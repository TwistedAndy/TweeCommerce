<?php

namespace App\Core\Entity;

use CodeIgniter\Database\BaseConnection;

class EntityRelation
{
    protected string         $type;
    protected string         $relatedAlias;
    protected string         $relatedClass;
    protected EntityModel    $relatedModel;
    protected EntityRegistry $registry;
    protected BaseConnection $db;

    protected string $localKey        = '';
    protected string $foreignKey      = '';
    protected string $pivotLocalKey   = '';
    protected string $pivotForeignKey = '';

    public function __construct(array $relation, EntityRegistry $registry)
    {
        if (empty($relation['related']) or !class_exists($relation['related'])) {
            throw new EntityException('Related entity class is not specified');
        }

        if (empty($relation['type'])) {
            throw new EntityException('Relation type is not specified');
        }

        if (empty($relation['local_key'])) {
            throw new EntityException('Local key is not specified');
        }

        if (empty($relation['foreign_key'])) {
            throw new EntityException('Foreign key is not specified');
        }

        $this->type         = $relation['type'];
        $this->localKey     = $relation['local_key'];
        $this->foreignKey   = $relation['foreign_key'];
        $this->relatedAlias = $relation['related'];
        $this->relatedClass = $registry->getEntityClass($relation['related']);

        // Enforce pivot keys for belongs-many relations
        if ($this->type === 'belongs-many') {
            if (empty($relation['pivot_local_key'])) {
                throw new EntityException("Pivot local key is required for belongs-many relation to {$this->relatedAlias}");
            }
            if (empty($relation['pivot_foreign_key'])) {
                throw new EntityException("Pivot foreign key is required for belongs-many relation to {$this->relatedAlias}");
            }

            $this->pivotLocalKey   = $relation['pivot_local_key'];
            $this->pivotForeignKey = $relation['pivot_foreign_key'];
        } else {
            $this->pivotLocalKey   = '';
            $this->pivotForeignKey = '';
        }

        $this->registry     = $registry;
        $this->relatedModel = $registry->getModel($this->relatedAlias);

        $dbGroup = $this->registry->getDatabaseGroup($this->relatedAlias);

        $this->db = \Config\Database::connect($dbGroup);
    }

    /**
     * Get related data
     */
    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        return match ($this->type) {
            'has-one' => $this->getHasOne($entity),
            'has-many' => $this->getHasMany($entity),
            'belongs-one' => $this->getBelongsOne($entity),
            'belongs-many' => $this->getBelongsMany($entity),
            default => throw new EntityException("Unknown relation type: {$this->type}")
        };
    }

    /**
     * Update related data
     */
    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $localEntity->getAttribute($this->localKey);

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
    public function remove(int|string|EntityInterface $localEntity, string $localAlias): void
    {
        if ($this->type === 'belongs-one') {
            $this->removeBelongsOne($localEntity, $localAlias);
        } elseif ($this->type === 'belongs-many') {
            $this->removeBelongsMany($localEntity, $localAlias);
        }
    }

    /**
     * Resolve and normalize the provided relation data.
     * Converts IDs and raw data arrays into proper Entity objects.
     */
    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        $isMany = in_array($this->type, ['has-many', 'belongs-many'], true);

        if ($isMany) {
            return $this->resolveMany($value);
        }

        return $this->resolveOne($value);
    }

    /**
     * Get an entity for the has-one relation
     */
    protected function getHasOne(EntityInterface $localEntity): ?EntityInterface
    {
        $localId = $localEntity->getAttribute($this->localKey);

        if (empty($localId)) {
            return null;
        }

        $results = $this->relatedModel->findByField($this->foreignKey, $localId);

        return !empty($results) ? reset($results) : null;
    }

    /**
     * Get an array with entities for the has-many relation
     */
    protected function getHasMany(EntityInterface $localEntity): array
    {
        $localId = $localEntity->getAttribute($this->localKey);

        return $localId ? $this->relatedModel->findByField($this->foreignKey, $localId) : [];
    }

    /**
     * Get an entity for the belongs-one relation
     */
    protected function getBelongsOne(EntityInterface $localEntity): ?EntityInterface
    {
        $foreignId = $localEntity->getAttribute($this->foreignKey);

        return $foreignId ? $this->relatedModel->findOne($foreignId) : null;
    }

    /**
     * Get an array with entities for the belongs-many relation
     */
    protected function getBelongsMany(EntityInterface $localEntity): array
    {
        $localId = $localEntity->getAttribute($this->localKey);

        if (empty($localId)) {
            return [];
        }

        $pivotTable = $this->registry->getPivotTable($localEntity->getAlias(), $this->relatedAlias);

        if (empty($pivotTable)) {
            throw new EntityException("Pivot table not defined for the {$this->relatedAlias} relation.");
        }

        $pivotRecords = $this->db->table($pivotTable)->where($this->pivotLocalKey, $localId)->get()->getResultArray();

        if (empty($pivotRecords)) {
            return [];
        }

        $relatedIds = array_column($pivotRecords, $this->pivotForeignKey);

        return $this->relatedModel->findMany($relatedIds);
    }

    /**
     * Update entity list the for rhe has-one relation
     */
    protected function updateHasOne(int|string $localId, array|null|EntityInterface $relatedData): void
    {
        // Detach any existing entity that currently belongs to this parent
        $this->relatedModel->builder()
            ->where($this->foreignKey, $localId)
            ->update([$this->foreignKey => null]);

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
    protected function updateHasMany(int|string $localId, array|null|EntityInterface $relatedData): void
    {
        // If empty array, detach everything and exit
        if (empty($relatedData)) {
            $this->relatedModel->builder()->where($this->foreignKey, $localId)->update([$this->foreignKey => null]);
            return;
        }

        $entities = $this->resolveMany($relatedData);

        $entityIds   = [];
        $existingIds = [];

        // Sort entities into "Existing" and "New"
        foreach ($entities as $entity) {
            if ($entity instanceof EntityInterface) {
                $primaryKey = $entity->getFields()->getPrimaryKey();
                $entityId   = $entity->getAttribute($primaryKey);
                $entity->setAttribute($this->foreignKey, $localId);

                if ($entityId) {
                    $entityIds[] = $entityId;

                    // If the entity was modified, we MUST save it individually!
                    if ($entity->hasChanged()) {
                        $this->relatedModel->update($entityId, $entity);
                    } else {
                        // Otherwise, just queue it to ensure the foreign key is intact
                        $existingIds[] = $entityId;
                    }
                } else {
                    // Explicitly use insert() to capture the new DB ID
                    $newId = $this->relatedModel->insert($entity, true);
                    if ($newId) {
                        $entity->setAttribute($primaryKey, $newId);
                        $entityIds[] = $newId;
                    }
                }
            }
        }

        $relatedPrimaryKey = $this->registry->getEntityFields($this->relatedAlias)->getPrimaryKey();

        // Update the actual relation for all existing entities in 1 query
        if (!empty($existingIds)) {
            $existingIds = array_unique($existingIds);

            $this->relatedModel->builder()
                ->whereIn($relatedPrimaryKey, $existingIds)
                ->update([$this->foreignKey => $localId]);
        }

        // Remove the relation from anything no longer in the list
        $builder = $this->relatedModel->builder()->where($this->foreignKey, $localId);

        if (!empty($entityIds)) {
            $builder->whereNotIn($relatedPrimaryKey, array_unique($entityIds));
        }

        // If $entityIds was empty, this safely detaches everything attached to this parent.
        $builder->update([$this->foreignKey => null]);
    }

    /**
     * Update an entity for the belongs-one relation
     */
    protected function updateBelongsOne(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $primaryKey = $localEntity->getFields()->getPrimaryKey();
        $localId    = $localEntity->getAttribute($primaryKey);
        $localAlias = $localEntity->getAlias();

        $relatedEntity = $this->resolveOne($relatedData);

        if ($relatedEntity instanceof EntityInterface) {
            $relatedId = $relatedEntity->getAttribute($this->localKey);
        } else {
            $relatedId = null;
        }

        $localEntity->setAttribute($this->foreignKey, $relatedId);

        if ($localId) {
            $table = $this->registry->getEntityTable($localAlias);

            $this->db->table($table)
                ->where($primaryKey, $localId)
                ->update([$this->foreignKey => $relatedId]);
        }
    }

    /**
     * Update entity list for the belongs-many relation
     */
    protected function updateBelongsMany(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $localEntity->getAttribute($this->localKey);
        $localAlias = $localEntity->getAlias();
        $pivotTable = $this->registry->getPivotTable($localAlias, $this->relatedAlias);

        if (empty($pivotTable)) {
            throw new EntityException("Pivot table not defined for the {$this->relatedAlias} relation.");
        }

        // Gather all the NEW foreign IDs that should be attached
        $entities = is_array($relatedData) ? $relatedData : (empty($relatedData) ? [] : [$relatedData]);
        $newIds   = [];

        foreach ($entities as $entity) {
            $relatedId = $entity instanceof EntityInterface ? $entity->getAttribute($this->foreignKey) : $entity;
            if ($relatedId) {
                $newIds[] = $relatedId;
            }
        }

        $newIds = array_unique($newIds);

        // Fetch the CURRENT foreign IDs already in the database pivot table
        $currentRecords = $this->db->table($pivotTable)
            ->select($this->pivotForeignKey)
            ->where($this->pivotLocalKey, $localId)
            ->get()
            ->getResultArray();

        $currentIds = array_column($currentRecords, $this->pivotForeignKey);

        // Calculate the Exact Delta (Diff)
        $idsToAttach = array_diff($newIds, $currentIds);
        $idsToDetach = array_diff($currentIds, $newIds);

        // Detach only the removed records
        if (!empty($idsToDetach)) {
            $this->db->table($pivotTable)
                ->where($this->pivotLocalKey, $localId)
                ->whereIn($this->pivotForeignKey, $idsToDetach)
                ->delete();
        }

        // Attach only the brand new records
        if (!empty($idsToAttach)) {
            $insertData = [];
            foreach ($idsToAttach as $id) {
                $insertData[] = [
                    $this->pivotLocalKey   => $localId,
                    $this->pivotForeignKey => $id
                ];
            }

            $this->db->table($pivotTable)->insertBatch($insertData);
        }
    }

    /**
     * Remove an entity for the belongs-one relation
     */
    protected function removeBelongsOne(int|string|EntityInterface $localEntity, string $localAlias): void
    {
        // Resolve the parent's primary key field name (usually 'id')
        $primaryKey = $this->registry->getEntityFields($localAlias)->getPrimaryKey();

        if ($localEntity instanceof EntityInterface) {
            $localEntity->setAttribute($this->foreignKey, null);
            $localId = $localEntity->getAttribute($primaryKey);
        } else {
            $localId = $localEntity;
        }

        // Update the database immediately
        if ($localId) {
            $table = $this->registry->getEntityTable($localAlias);

            $this->db->table($table)
                ->where($primaryKey, $localId)
                ->update([$this->foreignKey => null]);
        }
    }

    /**
     * Remove all pivot records for the belongs-many relation
     */
    protected function removeBelongsMany(int|string|EntityInterface $localEntity, string $localAlias): void
    {
        if (empty($localEntity)) {
            return;
        }

        if ($localEntity instanceof EntityInterface) {
            $localId = $localEntity->getAttribute($this->localKey);
        } else {
            $localId = $localEntity;
        }

        $pivotTable = $this->registry->getPivotTable($localAlias, $this->relatedAlias);

        if (empty($pivotTable)) {
            throw new EntityException("Pivot table not defined for the {$this->relatedAlias} relation.");
        }

        // Wipe all pivot records for this parent ID in one shot
        $this->db->table($pivotTable)->where($this->pivotLocalKey, $localId)->delete();
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

        if ($value instanceof $this->relatedClass) {
            return $value;
        }

        if (is_scalar($value)) {
            $entity = $this->relatedModel->findOne($value);

            if (!$entity) {
                throw new EntityException(sprintf('Could not find related %s with ID "%s".', $this->relatedClass, $value));
            }

            return $entity;
        }

        if (is_array($value) and !array_is_list($value)) {
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
            if ($item instanceof $this->relatedClass) {
                $entities[] = $item;
            } elseif (is_array($item) and !array_is_list($item)) {
                $entities[] = new $this->relatedClass($item, $this->relatedAlias);
            } elseif (is_scalar($item)) {
                $idsToFetch[] = $item;
            } else {
                throw new EntityException(sprintf('Invalid item type in relation "%s". Expected ID, associative array, or %s instance.', $this->relatedClass, $this->relatedClass));
            }
        }

        // Fetch all scalar IDs in a single query
        if ($idsToFetch) {
            $fetchedEntities = $this->relatedModel->findMany($idsToFetch);

            if (count($fetchedEntities) !== count(array_unique($idsToFetch))) {
                throw new EntityException(sprintf('One or more related %s entities could not be found in the database.', $this->relatedClass));
            }

            $entities = array_merge($entities, $fetchedEntities);
        }

        return $entities;
    }
}