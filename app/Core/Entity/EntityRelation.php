<?php

namespace App\Core\Entity;

use CodeIgniter\Database\BaseBuilder;

class EntityRelation
{
    protected bool           $isMultiple;
    protected string         $type;
    protected string         $relatedAlias;
    protected string         $relatedClass;
    protected string         $relatedTable;
    protected string         $relatedKey;
    protected EntityModel    $relatedModel;
    protected EntityRegistry $registry;

    protected string $localKey        = '';
    protected string $foreignKey      = '';
    protected string $pivotLocalKey   = '';
    protected string $pivotForeignKey = '';
    protected string $relationName    = '';
    protected array  $constraint      = [];

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        if (empty($relation['related'])) {
            throw new EntityException('Related entity name is not specified');
        }

        $this->registry = $registry;

        $this->type         = $relation['type'] ?? throw new EntityException('Relation type is not specified');
        $this->localKey     = $relation['local_key'] ?? throw new EntityException('Local key is not specified');
        $this->foreignKey   = $relation['foreign_key'] ?? throw new EntityException('Foreign key is not specified');
        $this->relationName = $name;
        $this->constraint   = $relation['constraint'] ?? [];

        $this->relatedAlias = $relation['related'];
        $this->relatedClass = $registry->getEntityClass($relation['related']);
        $this->relatedModel = $registry->getModel($this->relatedAlias);
        $this->relatedTable = $this->relatedModel->getTable();
        $this->relatedKey   = $this->relatedModel->getPrimaryKey();

        $this->isMultiple = in_array($this->type, ['has-many', 'belongs-many'], true);

        // Enforce foreign_key for owning-side relations
        if (in_array($this->type, ['has-one', 'has-many'], true) && empty($this->foreignKey)) {
            throw new EntityException("foreign_key is required for '{$this->type}' relation '{$name}'");
        }

        // Enforce pivot keys for belongs-many relations
        if ($this->type === 'belongs-many') {
            if (empty($relation['pivot_local_key']) or empty($relation['pivot_foreign_key'])) {
                throw new EntityException("Both pivot_local_key and pivot_foreign_key are required for belongs-many relation");
            }

            $this->pivotLocalKey   = $relation['pivot_local_key'];
            $this->pivotForeignKey = $relation['pivot_foreign_key'];
        }
    }

    /**
     * Get a configured Model Instance proxying the Builder for this relation.
     *
     * This method modifies the internal Query Builder state of the related model.
     * It must be immediately followed by a terminal operation (findAll(), first()),
     * which will null the builder after executing.
     */
    public function query(EntityInterface $localEntity): EntityModel
    {
        $builder = $this->relatedModel->builder();
        $localId = $this->getLocalId($localEntity);

        match ($this->type) {
            'has-one', 'has-many' => $builder->where($this->foreignKey, $localId),
            'belongs-one' => $builder->where(
                $this->relatedKey,
                $this->getForeignId($localEntity)
            ),
            'belongs-many' => $this->buildBelongsManyQuery($localEntity, $localId),
            default => throw new EntityException("Unknown relation type: {$this->type}")
        };

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        return $this->relatedModel;
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        if ($this->type === 'belongs-one' and !$this->getForeignId($entity)) {
            return null;
        }

        if ($this->type !== 'belongs-one' and !$this->getLocalId($entity)) {
            return $this->isMultiple ? [] : null;
        }

        return $this->isMultiple ? $this->query($entity)->findAll() : $this->query($entity)->first();
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        // A parent needs a local ID for all relationships EXCEPT 'belongs-one',
        // where the parent itself holds the foreign key and will be saved afterwards.
        if (empty($localId) and $this->type !== 'belongs-one') {
            throw new EntityException('Parent entity must be saved before updating relations.');
        }

        match ($this->type) {
            'has-one' => $this->updateHasOne($localId, $relatedData),
            'has-many' => $this->updateHasMany($localId, $relatedData),
            'belongs-one' => $this->updateBelongsOne($localEntity, $relatedData),
            'belongs-many' => $this->updateBelongsMany($localEntity, $relatedData),
            default => throw new EntityException("Update not supported for: {$this->type}")
        };
    }

    /**
     * Remove related data
     */
    public function remove(int|string|EntityInterface|null $localEntity, string $localAlias): void
    {
        if (empty($localEntity)) {
            return;
        }

        if ($this->type === 'belongs-one') {
            $this->removeBelongsOne($localEntity, $localAlias);
        } elseif ($this->type === 'belongs-many') {
            $this->removeBelongsMany($localEntity, $localAlias);
        }
    }

    /**
     * Resolve a value into a singular entity or an array with entities
     */
    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        return $this->isMultiple ? $this->resolveMany($value) : $this->resolveOne($value);
    }

    /**
     * Fill entities with the relation data
     */
    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $localIds = [];

        // Clean extraction loop using array keys for instant uniqueness mapping
        foreach ($entities as $entity) {
            $id = $this->type === 'belongs-one' ? $this->getForeignId($entity) : $this->getLocalId($entity);

            if (!empty($id)) {
                $localIds[(string) $id] = $id;
            }
        }

        $localIds = array_values($localIds);

        if (empty($localIds)) {
            return;
        }

        if ($this->type === 'belongs-many') {
            $pivotTable = $this->registry->getPivotTable($entities[0]->getAlias(), $this->relatedAlias);

            $builder = $this->relatedModel->builder($pivotTable);

            // Fetch relations via pivot table to build a map.
            $builder->select("{$this->pivotLocalKey}, {$this->pivotForeignKey}")->whereIn($this->pivotLocalKey, $localIds);

            if ($dynamicConstraint) {
                $dynamicConstraint($builder);
            }

            if ($this->constraint) {
                $this->applyConstraints($builder);
            }

            $pivotRecords = $builder->get()->getResultArray();

            $this->relatedModel->newQuery();

            $pivotMap          = [];
            $relatedIdsToFetch = [];

            foreach ($pivotRecords as $row) {
                // Cast to string to guarantee strict type consistency on dictionary lookups
                $pivotLocalId   = (string) $row[$this->pivotLocalKey];
                $pivotForeignId = (string) $row[$this->pivotForeignKey];

                $pivotMap[$pivotLocalId][] = $pivotForeignId;
                $relatedIdsToFetch[]       = $pivotForeignId;
            }

            // Let the target model pull the entities properly, natively applying soft deletes
            $relatedEntitiesList = $relatedIdsToFetch ? $this->relatedModel->findMany(array_unique($relatedIdsToFetch)) : [];

            $relatedEntitiesById = [];
            foreach ($relatedEntitiesList as $relEntity) {
                $relatedId = $relEntity->getAttribute($this->relatedKey);
                if (!empty($relatedId)) {
                    $relatedEntitiesById[(string) $relatedId] = $relEntity;
                }
            }

            foreach ($entities as $parentEntity) {
                $parentId = $this->getLocalId($parentEntity);
                $matched  = [];

                if (!empty($parentId)) {
                    $strParentId = (string) $parentId;

                    if (isset($pivotMap[$strParentId])) {
                        foreach ($pivotMap[$strParentId] as $relatedId) {
                            if (isset($relatedEntitiesById[$relatedId])) {
                                $matched[] = $relatedEntitiesById[$relatedId];
                            }
                        }
                    }
                }

                $parentEntity->setAttribute($this->relationName, $matched);
                $parentEntity->flushChanges();
            }

            return;
        }

        $keyToMatch = $this->type === 'belongs-one' ? $this->relatedKey : $this->foreignKey;

        $builder = $this->relatedModel->builder();

        $this->relatedModel->maybeExcludeDeleted($builder);

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        if ($dynamicConstraint) {
            $dynamicConstraint($builder);
        }

        $relatedRecords = $builder->whereIn($keyToMatch, $localIds)->get()->getResultArray();

        $this->relatedModel->newQuery();

        $relatedEntities = [];
        foreach ($relatedRecords as $row) {
            // Bypass find() entirely to prevent N+1 database queries.
            $entity = $this->relatedModel->hydrateRow($row);
            $relatedEntities[] = $entity;
        }

        foreach ($entities as $parentEntity) {
            $parentId = $this->type === 'belongs-one' ? $this->getForeignId($parentEntity) : $this->getLocalId($parentEntity);

            if (empty($parentId)) {
                continue;
            }

            $matched  = [];

            if ($this->type === 'belongs-one') {
                foreach ($relatedEntities as $relatedEntity) {
                    if ($relatedEntity->getAttribute($this->relatedKey) === $parentId) {
                        $matched[] = $relatedEntity;
                    }
                }
            } else {
                foreach ($relatedEntities as $relatedEntity) {
                    if ($relatedEntity->getAttribute($this->foreignKey) === $parentId) {
                        $matched[] = $relatedEntity;
                    }
                }
            }

            $parentEntity->setAttribute($this->relationName, $this->isMultiple ? $matched : ($matched[0] ?? null));
            $parentEntity->flushChanges();
        }
    }

    /**
     * Apply conditions to relations
     */
    protected function applyConstraints(BaseBuilder $builder): void
    {
        if (!empty($this->constraint['where']) and is_array($this->constraint['where'])) {
            $builder->where($this->constraint['where']);
        }

        if (!empty($this->constraint['orderBy'])) {
            $order = $this->constraint['orderBy'];
            if (is_array($order)) {
                $builder->orderBy($order[0], $order[1] ?? 'ASC');
            } else {
                $builder->orderBy($order);
            }
        }

        if (!empty($this->constraint['limit'])) {
            $builder->limit((int) $this->constraint['limit']);
        }

        if (isset($this->constraint['offset']) and is_numeric($this->constraint['offset'])) {
            $builder->offset((int) $this->constraint['offset']);
        }

        if (!empty($this->constraint['callback'])) {
            $callback = $this->constraint['callback'];

            if (is_string($callback) and method_exists($this->relatedModel, $callback)) {
                // Local Model Method (e.g., 'scopeApproved')
                $this->relatedModel->{$callback}($builder);
            } elseif (is_callable($callback)) {
                // Standard PHP Callable (Global function, Static 'Class::method', or ['Class', 'method'])
                call_user_func($callback, $builder, $this->relatedModel);
            } else {
                throw new EntityException("The relation callback is invalid. It must be an existing model method or a valid PHP callable.");
            }
        }
    }

    /**
     * Get the local key value from the parent entity.
     */
    protected function getLocalId(EntityInterface $entity): int|string|null
    {
        return $entity->getAttribute($this->localKey);
    }

    /**
     * Get the foreign key value.
     *
     * For belongs-* relations, the foreign key column sits on the parent entity.
     */
    protected function getForeignId(EntityInterface $entity): int|string|null
    {
        return $entity->getAttribute($this->foreignKey);
    }

    protected function buildBelongsManyQuery(EntityInterface $localEntity, int|string|null $localId): void
    {
        $pivotTable = $this->registry->getPivotTable($localEntity->getAlias(), $this->relatedAlias);

        if (empty($pivotTable)) {
            throw new EntityException("Pivot table not defined for the {$this->relatedAlias} relation.");
        }

        $this->relatedModel->builder()
            ->select("{$this->relatedTable}.*")
            ->join($pivotTable, "{$pivotTable}.{$this->pivotForeignKey} = {$this->relatedTable}.{$this->relatedKey}")
            ->where("{$pivotTable}.{$this->pivotLocalKey}", $localId);
    }

    /**
     * Update entity list the for the has-one relation
     */
    protected function updateHasOne(int|string|null $localId, array|null|EntityInterface $relatedData): void
    {
        if (empty($localId)) {
            return;
        }

        // Detach any existing entity that currently belongs to this parent
        $this->relatedModel->builder()
            ->where($this->foreignKey, $localId)
            ->update([$this->foreignKey => null]);

        $this->relatedModel->newQuery();

        $entity = $this->resolveOne($relatedData);

        // Attach and save the new entity (if one was provided)
        if ($entity instanceof EntityInterface) {
            $entity->setAttribute($this->foreignKey, $localId);
            $this->relatedModel->save($entity);
        }
    }

    /**
     * Update entity list for the has-many relation
     */
    protected function updateHasMany(int|string|null $localId, array|null|EntityInterface $relatedData): void
    {
        if (empty($localId)) {
            return;
        }

        // If empty array, detach everything and exit
        if (empty($relatedData)) {
            $this->relatedModel->builder()->where($this->foreignKey, $localId)->update([$this->foreignKey => null]);
            $this->relatedModel->newQuery();
            return;
        }

        $entities  = $this->resolveMany($relatedData);
        $entityIds = [];

        foreach ($entities as $entity) {
            $entity->setAttribute($this->foreignKey, $localId);
            $this->relatedModel->save($entity);

            $entityId = $entity->getAttribute($this->relatedKey);

            if (!empty($entityId)) {
                $entityIds[] = $entityId;
            }
        }

        $builder = $this->relatedModel->builder()->where($this->foreignKey, $localId);

        if (!empty($entityIds)) {
            $builder->whereNotIn($this->relatedKey, array_unique($entityIds));
        }

        $builder->update([$this->foreignKey => null]);

        $this->relatedModel->newQuery();
    }

    /**
     * Update an entity for the belongs-one relation
     */
    protected function updateBelongsOne(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        $relatedEntity = $this->resolveOne($relatedData);
        $relatedId     = $relatedEntity ? $this->getLocalId($relatedEntity) : null;

        $localEntity->setAttribute($this->foreignKey, $relatedId);

        if (!empty($localId)) {
            $localModel = $this->registry->getModel($localEntity->getAlias());
            $localModel->update($localId, $localEntity);
        }
    }

    /**
     * Update entity list for the belongs-many relation.
     * Passing an empty array for $relatedData will intentionally detach all current relations.
     */
    protected function updateBelongsMany(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $this->getLocalId($localEntity);
        $pivotTable = $this->registry->getPivotTable($localEntity->getAlias(), $this->relatedAlias);

        if (empty($pivotTable)) {
            throw new EntityException("Pivot table not defined for the {$this->relatedAlias} relation.");
        }

        $entities = is_array($relatedData) ? $relatedData : (empty($relatedData) ? [] : [$relatedData]);
        $newIds   = [];

        // Clean, readable loop without arrow functions
        foreach ($entities as $entity) {
            $id = $entity instanceof EntityInterface ? $entity->getAttribute($this->relatedKey) : $entity;

            // Safely filter out nulls and empty strings, but keep valid integers
            if (!empty($id)) {
                $newIds[] = $id;
            }
        }

        $this->relatedModel->sync($pivotTable, $this->pivotLocalKey, $this->pivotForeignKey, $localId, array_unique($newIds));
    }

    /**
     * Remove an entity for the belongs-one relation
     */
    protected function removeBelongsOne(int|string|EntityInterface $localEntity, string $localAlias): void
    {
        $localId = $localEntity instanceof EntityInterface ? $this->getLocalId($localEntity) : $localEntity;

        if (empty($localId)) {
            return;
        }

        $localModel = $this->registry->getModel($localAlias);

        // Safe to pull from cache, update property, and re-save
        $entity = $localModel->find($localId);

        if ($entity === null) {
            return;
        }

        $entity->setAttribute($this->foreignKey, null);
        $localModel->update($localId, $entity);
    }

    /**
     * Remove all pivot records for the belongs-many relation
     */
    protected function removeBelongsMany(int|string|EntityInterface $localEntity, string $localAlias): void
    {
        $localId = $localEntity instanceof EntityInterface ? $this->getLocalId($localEntity) : $localEntity;

        if (empty($localId)) {
            return;
        }

        $pivotTable = $this->registry->getPivotTable($localAlias, $this->relatedAlias);

        $this->relatedModel->detach($pivotTable, $this->pivotLocalKey, $this->pivotForeignKey, $localId);
    }

    /**
     * Resolve a *-one relation value into a single entity.
     */
    protected function resolveOne(int|string|array|null|EntityInterface $value): ?EntityInterface
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) and array_is_list($value)) {
            throw new EntityException(sprintf('Relation "%s" expects a single ID, associative array, or %s instance. Sequential array provided.', $this->relatedClass, $this->relatedClass));
        }

        if ($value instanceof EntityInterface) {
            if (!$value instanceof $this->relatedClass) {
                throw new EntityException(sprintf('Relation expects instance of %s, but got %s.', $this->relatedClass, get_class($value)));
            }
            return $value;
        }

        if (is_scalar($value)) {
            return $this->relatedModel->find($value) ?? throw new EntityException("Could not find related {$this->relatedClass} with ID {$value}");
        }

        if (is_array($value) and !array_is_list($value)) {
            $relatedId = $value[$this->relatedKey] ?? null;

            // Tap into Identity Map / database if updating an existing record
            if ($relatedId !== null and $existing = $this->relatedModel->find($relatedId)) {
                $existing->setAttributes($value);
                return $existing;
            }

            return new $this->relatedClass($value, $this->relatedAlias);
        }

        throw new EntityException(sprintf('Invalid data type for relation "%s". Expected ID, associative array, or %s instance.', $this->relatedClass, $this->relatedClass));
    }

    /**
     * Resolve a *-many relation value into an array of entities.
     */
    protected function resolveMany(int|string|array|null|EntityInterface $value): array
    {
        if ($value === null) {
            return [];
        }

        // Wrap single items (scalar ID, associative array, or Entity) into an array
        if (is_scalar($value) or $value instanceof EntityInterface or (is_array($value) and !array_is_list($value))) {
            $value = [$value];
        }

        if (!is_array($value)) {
            throw new EntityException(sprintf('Relation "%s" expects an array of IDs, data arrays, or %s instances.', $this->relatedClass, $this->relatedClass));
        }

        $entities   = [];
        $idsToFetch = [];

        // Sort out existing entities, new data arrays, and scalar IDs
        foreach ($value as $item) {
            if ($item instanceof EntityInterface) {
                if (!$item instanceof $this->relatedClass) {
                    throw new EntityException(sprintf('Relation expects instance of %s, but got %s.', $this->relatedClass, get_class($item)));
                }
                $entities[] = $item;
            } elseif (is_array($item) and !array_is_list($item)) {
                $relatedKey = $item[$this->relatedKey] ?? null;

                if ($relatedKey !== null and $existing = $this->relatedModel->find($relatedKey)) {
                    $existing->setAttributes($item);
                    $entities[] = $existing;
                } else {
                    $entities[] = new $this->relatedClass($item, $this->relatedAlias);
                }
            } elseif (is_scalar($item)) {
                $idsToFetch[] = $item;
            } else {
                throw new EntityException(sprintf('Invalid item type in relation "%s". Expected ID, associative array, or %s instance.', $this->relatedClass, $this->relatedClass));
            }
        }

        // Fetch all scalar IDs in a single query
        if ($idsToFetch) {
            $entities = array_merge($entities, $this->relatedModel->findMany($idsToFetch));
        }

        return $entities;
    }
}