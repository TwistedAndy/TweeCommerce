<?php

namespace App\Core\Entity;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

class EntityRelation
{
    protected bool           $isMultiple;
    protected string         $type;
    protected array          $relatedConfig;
    protected string         $relatedAlias;
    protected string         $relatedClass;
    protected string         $relatedTable;
    protected string         $relatedKey;
    protected EntityModel    $relatedModel;
    protected EntityFields   $relatedFields;
    protected EntityRegistry $registry;

    protected string $localKey     = '';
    protected string $foreignKey   = '';
    protected string $relationName = '';
    protected array  $constraint   = [];
    protected bool   $cascade      = false;
    protected array  $pivot        = [];

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        if (empty($relation['entity'])) {
            throw EntityException::missingRelationAlias($name);
        }

        $this->registry = $registry;

        $this->type         = $relation['type'] ?? '';
        $this->localKey     = $relation['local_key'] ?? '';
        $this->foreignKey   = $relation['foreign_key'] ?? '';
        $this->constraint   = $relation['constraint'] ?? [];
        $this->cascade      = (bool) ($relation['cascade'] ?? false);
        $this->relationName = $name;

        $this->relatedAlias  = $relation['entity'];
        $this->relatedConfig = $this->registry->getConfig($this->relatedAlias);
        $this->relatedClass  = $registry->getEntityClass($this->relatedAlias);
        $this->relatedFields = $registry->getEntityFields($this->relatedAlias);
        $this->relatedModel  = $registry->getModel($this->relatedAlias);
        $this->relatedTable  = $this->registry->getEntityTable($this->relatedAlias);
        $this->relatedKey    = $this->relatedFields->getPrimaryKey();

        $this->isMultiple = in_array($this->type, ['has-many', 'belongs-many'], true);

        // Enforce pivot keys for belongs-many relations
        if ($this->type === 'belongs-many' and empty($this->foreignKey)) {
            $this->foreignKey = $this->relatedKey;
        }
    }

    /**
     * Get the relation type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get related table name
     */
    public function getTable(): string
    {
        return $this->relatedTable;
    }

    /**
     * Get related config array
     */
    public function getConfig(): array
    {
        return $this->relatedConfig;
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        if ($this->type === 'meta') {
            $localId = $this->getLocalId($entity);
            return $localId ? $this->relatedModel->find($localId) : null;
        }

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

        // Safely defer relations that require a parent ID until the parent is saved
        if (empty($localId) and $this->type !== 'belongs-one') {
            return;
        }

        match ($this->type) {
            'meta' => $this->updateMeta($localEntity, $relatedData),
            'has-one' => $this->updateHasOne($localId, $relatedData),
            'has-many' => $this->updateHasMany($localId, $relatedData),
            'belongs-one' => $this->updateBelongsOne($localEntity, $relatedData),
            'belongs-many' => $this->updateBelongsMany($localEntity, $relatedData),
            default => throw EntityException::unsupportedType($this->type, 'update')
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
     * Apply the appropriate LEFT JOIN(s) for this relation to the given builder.
     * This is the JOIN-time counterpart of query(), which builds WHERE clauses for lazy loading.
     *
     *   belongs-one  → JOIN related ON related.pk = local.fk
     *   has-one/many → JOIN related ON related.fk = local.pk
     *   belongs-many → JOIN pivot ON pivot.local = local.pk, JOIN related ON related.fk = pivot.foreign
     *   meta         → aliased JOIN per key: JOIN related AS m_rel_key ON ... AND key_col = 'key'
     */
    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
        switch ($this->type) {
            case 'belongs-one':
                $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->relatedKey} = {$localTable}.{$this->foreignKey}", 'left');
                break;

            case 'has-one':
            case 'has-many':
                $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->foreignKey} = {$localTable}.{$this->localKey}", 'left');
                break;

            case 'belongs-many':
                $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
                $fk    = $this->foreignKey ? : $this->relatedKey;

                $builder
                    ->join($pivot['table'], "{$pivot['table']}.{$pivot['local_column']} = {$localTable}.{$this->localKey}", 'left')
                    ->join($this->relatedTable, "{$this->relatedTable}.{$fk} = {$pivot['table']}.{$pivot['foreign_column']}", 'left');
                break;

            case 'meta':
                $entityColumn = $this->relatedConfig['entity_column'] ?? 'entity_id';
                $keyColumn    = $this->relatedConfig['key_column'] ?? 'meta_key';
                $alias        = "m_{$this->relationName}_{$column}";

                $builder->join(
                    "{$this->relatedTable} {$alias}",
                    "{$alias}.{$entityColumn} = {$localTable}.{$this->localKey} AND {$alias}.{$keyColumn} = " . $db->escape($column),
                    'left'
                );
                break;

            default:
                throw EntityException::unsupportedType($this->type, 'join');
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
            'meta' => $builder->where(
                $this->relatedConfig['entity_column'] ?? 'entity_id',
                $localId
            ),
            'has-one', 'has-many' => $builder->where($this->foreignKey, $localId),
            'belongs-one' => $builder->where(
                $this->relatedKey,
                $this->getForeignId($localEntity)
            ),
            'belongs-many' => $this->buildBelongsManyQuery($localEntity, $localId),
            default => throw EntityException::unsupportedType($this->type, 'query')
        };

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        return $this->relatedModel;
    }

    /**
     * Cascade a delete operation to one or more parent entity IDs.
     *
     * For meta: remove all the metadata only if the purge option is set to true
     *
     * For has-one / has-many: one query fetches all related IDs, then delete() runs
     * the bulk operation (and their own cascades) recursively.
     *
     * For belongs-many: remove pivot records in one DELETE … WHERE IN on hard-delete only,
     * so soft-deleted parents can be fully restored including their many-to-many relationships.
     *
     * For belongs-one: no-op (parent holds the FK; the referenced entity is not owned).
     *
     * @param bool $purge true = hard-delete, false = soft-delete (if the related model supports it)
     */
    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or empty($localIds) or $this->type === 'belongs-one') {
            return;
        }

        if ($this->type === 'meta') {
            if ($purge) {
                $this->relatedModel->delete($localIds, true);
            }

            return;
        }

        if ($this->type === 'belongs-many') {
            if ($purge) {
                $pivotConfig = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

                if (!empty($pivotConfig)) {
                    $this->syncPivot($pivotConfig['table'], $pivotConfig['local_column'], $pivotConfig['foreign_column'], $localIds, []);
                }
            }

            return;
        }

        // has-one / has-many: one query to get all related IDs across every parent,
        // then a single bulk delete (which recursively handles their own cascades).
        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if (!empty($ids)) {
            $this->relatedModel->delete($ids, $purge);
        }
    }

    /**
     * Cascade a restore operation to one or more parent entity IDs.
     *
     * For has-one / has-many: one query fetches all related IDs (including soft-deleted),
     *   then restore() runs the bulk operation recursively.
     * For belongs-many / belongs-one: no-op — pivot records were preserved on soft-delete,
     *   and the referenced entity is not owned by the parent.
     */
    public function cascadeRestore(array $localIds): void
    {
        if (!$this->cascade or empty($localIds) or in_array($this->type, ['belongs-one', 'belongs-many', 'meta'], true)) {
            return;
        }

        // has-one / has-many: include soft-deleted rows (raw builder bypasses maybeExcludeDeleted)
        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if (!empty($ids)) {
            $this->relatedModel->restore($ids);
        }
    }

    /**
     * Fill entities with the relation data
     */
    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $localIds  = [];
        $entityKey = $this->type === 'belongs-one' ? $this->foreignKey : $this->localKey;

        foreach ($entities as $entity) {
            $localIds[] = $entity->getAttribute($entityKey);
        }

        // Strip out nulls and duplicates to prevent invalid WHERE IN queries
        $localIds = array_filter(array_unique($localIds));

        if (empty($localIds)) {
            return;
        }

        if ($this->type === 'meta') {
            $metas     = $this->relatedModel->findMany($localIds);
            $metaArray = [];

            foreach ($metas as $meta) {
                $metaArray[$meta->getAttribute($this->relatedKey)] = $meta;
            }

            foreach ($entities as $entity) {
                $parentId = $this->getLocalId($entity);

                $metaObject = $metaArray[$parentId] ?? new $this->relatedClass([
                    $this->relatedKey => $parentId
                ], $this->relatedAlias, $this->relatedFields);

                $entity->setAttribute($this->relationName, $metaObject);
                $entity->flushChanges();
            }

            return;
        }

        if ($this->type === 'belongs-many') {
            $pivotConfig = $this->registry->getPivotConfig($entities[0]->getAlias(), $this->relatedAlias);

            if (empty($pivotConfig)) {
                return;
            }

            $builder = $this->relatedModel->builder();

            $this->relatedModel->handleDeleted();

            $builder
                ->select("{$this->relatedTable}.*, {$pivotConfig['table']}.{$pivotConfig['local_column']} AS __pivot_local_key")
                ->join($pivotConfig['table'], "{$pivotConfig['table']}.{$pivotConfig['foreign_column']} = {$this->relatedTable}.{$this->foreignKey}")
                ->whereIn("{$pivotConfig['table']}.{$pivotConfig['local_column']}", $localIds);

            if ($dynamicConstraint) {
                $dynamicConstraint($builder);
            }

            if ($this->constraint) {
                $this->applyConstraints($builder);
            }

            $rows = $builder->get()->getResultArray();

            $this->relatedModel->reset();

            $relatedByParentId = [];

            foreach ($rows as $row) {
                $parentId = (string) $row['__pivot_local_key'];
                unset($row['__pivot_local_key']);
                $relatedByParentId[$parentId][] = $this->relatedModel->hydrateRow($row);
            }

            foreach ($entities as $parentEntity) {
                $parentId = (string) $this->getLocalId($parentEntity);
                $parentEntity->setAttribute($this->relationName, $relatedByParentId[$parentId] ?? []);
                $parentEntity->flushChanges();
            }

            return;
        }

        $keyToMatch = $this->type === 'belongs-one' ? $this->relatedKey : $this->foreignKey;

        $builder = $this->relatedModel->builder();

        $this->relatedModel->handleDeleted();

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        if ($dynamicConstraint) {
            $dynamicConstraint($builder);
        }

        $relatedRecords = $builder->whereIn($keyToMatch, $localIds)->get()->getResultArray();

        $this->relatedModel->reset();

        // Build a hash map keyed by the matching field so each parent gets O(1) lookup
        $relatedByKey = [];
        foreach ($relatedRecords as $row) {
            $key = (string) $row[$keyToMatch];

            if (!isset($relatedByKey[$key])) {
                $relatedByKey[$key] = [];
            }

            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        foreach ($entities as $parentEntity) {
            $parentId = (string) ($this->type === 'belongs-one' ? $this->getForeignId($parentEntity) : $this->getLocalId($parentEntity));

            if (empty($parentId)) {
                continue;
            }

            $matched = $relatedByKey[$parentId] ?? [];
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
                throw EntityException::invalidCallback($this->relationName);
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
        $pivotConfig = $this->registry->getPivotConfig($localEntity->getAlias(), $this->relatedAlias);

        if (empty($pivotConfig)) {
            throw EntityException::pivotNotDefined($this->relationName);
        }

        $this->relatedModel->builder()
            ->select("{$this->relatedTable}.*")
            ->join($pivotConfig['table'], "{$pivotConfig['table']}.{$pivotConfig['foreign_column']} = {$this->relatedTable}.{$this->foreignKey}")
            ->where("{$pivotConfig['table']}.{$pivotConfig['local_column']}", $localId);
    }

    /**
     * Update the metadata for a relation
     */
    protected function updateMeta(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $localEntity->getAttribute($this->localKey);

        if (empty($localId)) {
            throw EntityException::parentNotSaved($this->relationName);
        }

        // Use the already-loaded meta object, or fetch it from cache/DB so that
        // MetaModel::save() has the correct $original state for INSERT vs UPDATE decisions.
        $meta = $localEntity->getAttribute($this->relationName);

        if (!$meta instanceof EntityInterface) {
            $meta = $this->relatedModel->find($localId)
                ?? new $this->relatedClass([$this->relatedKey => $localId], $this->relatedAlias, $this->relatedFields);
            $localEntity->setAttribute($this->relationName, $meta);
        }

        // Fetch dynamic column names safely from the MetaModel
        $metaKeyColumn   = $this->relatedConfig['key_column'] ?? 'meta_key';
        $metaValueColumn = $this->relatedConfig['value_column'] ?? 'meta_value';

        // Explicitly bind the parent ID to the Meta object's virtual primary key ('id')
        $meta->setAttribute($this->relatedKey, $localId);

        // Clean up and apply incoming data (safeguard for direct manual calls)
        if (is_array($relatedData)) {
            foreach ($relatedData as $key => $value) {
                // Handle dynamic legacy format (e.g. [['custom_key_col' => 'views', 'custom_value_col' => 5]])
                if (is_array($value) and isset($value[$metaKeyColumn])) {
                    $meta->setAttribute($value[$metaKeyColumn], $value[$metaValueColumn] ?? null);
                } else {
                    // Handle standard associative format: ['views' => 5]
                    $meta->setAttribute($key, $value);
                }
            }
        } elseif ($relatedData instanceof EntityInterface and $relatedData !== $meta) {
            $meta->setAttributes($relatedData->getChanges());
        }

        // Save directly via the MetaModel
        $this->relatedModel->save($meta);
    }

    /**
     * Update entity list the for the has-one relation
     */
    protected function updateHasOne(int|string|null $localId, array|null|EntityInterface $relatedData): void
    {
        if (empty($localId)) {
            return;
        }

        $entity = $this->resolveOne($relatedData);

        if ($entity instanceof EntityInterface) {
            // Assign and save the new child first
            $entity->setAttribute($this->foreignKey, $localId);
            $this->relatedModel->save($entity);

            $entityId   = $entity->getAttribute($this->relatedKey);
            $excludeIds = empty($entityId) ? [] : [$entityId];

            // Detach any OLD orphans, strictly excluding the one we just saved
            $this->detachOrphans($this->foreignKey, $localId, $excludeIds);
        } else {
            // If null/empty was passed, intentionally detach everything
            $this->detachOrphans($this->foreignKey, $localId);
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
            $this->detachOrphans($this->foreignKey, $localId);
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

        $this->detachOrphans($this->foreignKey, $localId, $entityIds);
    }

    /**
     * Update an entity for the belongs-one relation
     */
    protected function updateBelongsOne(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $relatedEntity = $this->resolveOne($relatedData);

        if ($relatedEntity instanceof EntityInterface) {
            $relatedId = $relatedEntity->getAttribute($this->relatedKey);

            if (empty($relatedId) or $relatedEntity->hasChanged()) {
                $this->relatedModel->save($relatedEntity);
                $relatedId = $relatedEntity->getAttribute($this->relatedKey);
            }
        } else {
            $relatedId = null;
        }

        $localEntity->setAttribute($this->foreignKey, $relatedId);
    }

    /**
     * Update entity list for the belongs-many relation.
     * Passing an empty array for $relatedData will intentionally detach all current relations.
     */
    protected function updateBelongsMany(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId     = $this->getLocalId($localEntity);
        $pivotConfig = $this->registry->getPivotConfig($localEntity->getAlias(), $this->relatedAlias);

        if (empty($pivotConfig)) {
            throw EntityException::pivotNotDefined($this->relationName);
        }

        // Trust resolver to format arrays, scalars, and entities consistently
        $entities = empty($relatedData) ? [] : $this->resolveMany($relatedData);
        $newIds   = [];

        // Clean, readable loop without arrow functions
        foreach ($entities as $entity) {
            // Because resolveMany strictly returns EntityInterface objects, we can simplify this too
            $id = $entity->getAttribute($this->foreignKey);

            // Safely filter out nulls and empty strings, but keep valid integers
            if (!empty($id)) {
                $newIds[] = $id;
            }
        }

        $this->syncPivot($pivotConfig['table'], $pivotConfig['local_column'], $pivotConfig['foreign_column'], $localId, array_unique($newIds));
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

        $pivotConfig = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

        if (!empty($pivotConfig)) {
            $this->syncPivot($pivotConfig['table'], $pivotConfig['local_column'], $pivotConfig['foreign_column'], $localId, []);
        }
    }

    /**
     * Resolve a *-one relation value into a single entity.
     */
    protected function resolveOne(int|string|array|null|EntityInterface $value): ?EntityInterface
    {
        if ($value === null) {
            return null;
        }

        // Catch sequential arrays passed to a singular relation
        if (is_array($value) and array_is_list($value)) {
            throw EntityException::sequentialArray($this->relationName, $this->relatedClass);
        }

        if ($value instanceof EntityInterface) {
            if (!$value instanceof $this->relatedClass) {
                throw EntityException::typeMismatch($this->relatedClass, get_class($value));
            }
            return $value;
        }

        // If it's a scalar ID, fetch it from the database/cache
        if (is_scalar($value)) {
            return $this->relatedModel->find($value) ?? throw EntityException::relatedNotFound($this->relatedClass, $value);
        }

        // If it's an associative array, either update an existing record or create a new one
        if (is_array($value) and !array_is_list($value)) {
            $relatedId = $value[$this->relatedKey] ?? null;

            // Tap into Identity Map / database if updating an existing record
            if ($relatedId !== null and $existing = $this->relatedModel->find($relatedId)) {
                $existing->setAttributes($value);
                return $existing;
            }

            // Instantiate an empty entity and use setAttributes() so the data is
            // registered internally as $changes. Otherwise, the model will skip saving it
            $entity = new $this->relatedClass([], $this->relatedAlias);
            $entity->setAttributes($value);

            return $entity;
        }

        throw EntityException::invalidValue($this->relationName, $this->relatedClass);
    }

    /**
     * Resolve a *-many relation value into an array of entities.
     */
    protected function resolveMany(int|string|array|null|EntityInterface $value): array
    {
        if ($value === null) {
            return [];
        }

        // Wrap single items (scalar ID, associative array, or Entity) into an array seamlessly
        if (is_scalar($value) or $value instanceof EntityInterface or (is_array($value) and !array_is_list($value))) {
            $value = [$value];
        }

        if (!is_array($value)) {
            throw EntityException::invalidManyValue($this->relationName, $this->relatedClass);
        }

        $entities   = [];
        $idsToFetch = [];

        // Sort out existing entities, new data arrays, and scalar IDs
        foreach ($value as $item) {
            if ($item instanceof EntityInterface) {
                if (!$item instanceof $this->relatedClass) {
                    throw EntityException::typeMismatch($this->relatedClass, get_class($item));
                }
                $entities[] = $item;
            } elseif (is_array($item) and !array_is_list($item)) {
                $relatedKey = $item[$this->relatedKey] ?? null;

                if ($relatedKey !== null and $existing = $this->relatedModel->find($relatedKey)) {
                    $existing->setAttributes($item);
                    $entities[] = $existing;
                } else {
                    // Use setAttributes() to register the array data as $changes
                    $entity = new $this->relatedClass([], $this->relatedAlias, $this->relatedFields);
                    $entity->setAttributes($item);
                    $entities[] = $entity;
                }
            } elseif (is_scalar($item)) {
                // Batch all simple IDs to fetch them in a single optimized query later
                $idsToFetch[] = $item;
            } else {
                throw EntityException::invalidItem($this->relationName, $this->relatedClass);
            }
        }

        // Fetch all scalar IDs in a single highly-optimized query
        if ($idsToFetch) {
            $entities = array_merge($entities, $this->relatedModel->findMany($idsToFetch));
        }

        return $entities;
    }

    /**
     * Finds orphaned records matching a foreign key and detaches them natively,
     * then commands the related model to clear them from its cache.
     */
    protected function detachOrphans(string $foreignKey, int|string $localId, array $excludeIds = []): void
    {
        $builder = $this->relatedModel->builder()->where($foreignKey, $localId);

        if (!empty($excludeIds)) {
            $builder->whereNotIn($this->relatedKey, array_unique($excludeIds));
        }

        $builder->update([$foreignKey => null]);
        $this->relatedModel->reset();

        // Purge matching orphans from the identity map without an extra SELECT
        $this->relatedModel->removeFromCacheWhere($foreignKey, $localId, $excludeIds);
    }

    /**
     * Synchronize or detach pivot records.
     * Passing an empty $foreignIds array will detach all records for the given $localId(s).
     */
    protected function syncPivot(string $pivotTable, string $localColumn, string $foreignColumn, int|string|array $localId, array $foreignIds = []): void
    {
        if (empty($localId)) {
            return;
        }

        $builder = $this->relatedModel->builder($pivotTable);

        // If no foreign IDs are provided, this acts as a full detach
        if (empty($foreignIds)) {
            is_array($localId) ? $builder->whereIn($localColumn, $localId) : $builder->where($localColumn, $localId);
            $builder->delete();
            $this->relatedModel->reset();
            return;
        }

        // Fetch current pivot relationships
        $currentRecords = $builder->select($foreignColumn)
            ->where($localColumn, $localId)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $currentIds = array_column($currentRecords, $foreignColumn);

        // Diff arrays to find what needs to be added or removed
        $idsToDetach = array_diff($currentIds, $foreignIds);
        $idsToAttach = array_diff($foreignIds, $currentIds);

        // Detach old IDs
        if (!empty($idsToDetach)) {
            $this->relatedModel->builder($pivotTable)
                ->where($localColumn, $localId)
                ->whereIn($foreignColumn, $idsToDetach)
                ->delete();
            $this->relatedModel->reset();
        }

        // Attach new IDs
        if (!empty($idsToAttach)) {
            $insertData = [];
            foreach ($idsToAttach as $id) {
                $insertData[] = [
                    $localColumn   => $localId,
                    $foreignColumn => $id
                ];
            }
            $this->relatedModel->builder($pivotTable)->ignore(true)->insertBatch($insertData);
            $this->relatedModel->reset();
        }
    }
}