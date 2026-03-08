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
    protected string $morphTypeKey = '';
    protected string $morphKey     = '';
    protected array  $constraint   = [];
    protected bool   $cascade      = false;

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        // morph-to resolves its entity dynamically — no fixed alias at construction
        if (($relation['type'] ?? '') !== 'morph-to' and empty($relation['entity'])) {
            throw EntityException::missingRelationAlias($name);
        }

        $this->registry = $registry;

        $this->type         = $relation['type'] ?? '';
        $this->localKey     = $relation['local_key'] ?? '';
        $this->foreignKey   = $relation['foreign_key'] ?? '';
        $this->constraint   = $relation['constraint'] ?? [];
        $this->cascade      = (bool) ($relation['cascade'] ?? false);
        $this->relationName = $name;
        $this->isMultiple   = in_array($this->type, ['has-many', 'belongs-many', 'morph-many', 'morph-belongs-many'], true);

        // Resolve related entity for all types except morph-to (dynamic at runtime)
        if ($this->type !== 'morph-to') {
            $this->relatedAlias  = $relation['entity'];
            $this->relatedConfig = $this->registry->getConfig($this->relatedAlias);
            $this->relatedClass  = $registry->getEntityClass($this->relatedAlias);
            $this->relatedFields = $registry->getEntityFields($this->relatedAlias);
            $this->relatedModel  = $registry->getModel($this->relatedAlias);
            $this->relatedTable  = $this->registry->getEntityTable($this->relatedAlias);
            $this->relatedKey    = $this->relatedFields->getPrimaryKey();
        }

        // Enforce FK default for pivot-based relations
        if (in_array($this->type, ['belongs-many', 'morph-belongs-many'], true) and empty($this->foreignKey)) {
            $this->foreignKey = $this->relatedKey;
        }

        // Derive morph column names from the morph_key prefix
        if (str_starts_with($this->type, 'morph-')) {
            $morphKey           = $relation['morph_key'] ?? '';
            $this->morphTypeKey = $morphKey . '_type';

            if ($this->type === 'morph-belongs-many') {
                $this->morphKey = $morphKey . '_id';
            } else {
                // morph-one, morph-many, morph-to: foreignKey is the morph_id column
                $this->foreignKey = $morphKey . '_id';
            }
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

        if ($this->type === 'morph-to') {
            $morphId   = $entity->getAttribute($this->foreignKey);
            $morphType = $entity->getAttribute($this->morphTypeKey);
            if (empty($morphId) or empty($morphType)) {
                return null;
            }
            return $this->registry->getModel($morphType)->find($morphId);
        }

        if ($this->type === 'belongs-one' and !$this->getForeignId($entity)) {
            return null;
        }

        // Exclude both belongs-one AND morph-to from the local ID check
        if (!in_array($this->type, ['belongs-one', 'morph-to'], true) and !$this->getLocalId($entity)) {
            return $this->isMultiple ? [] : null;
        }

        return $this->isMultiple ? $this->query($entity)->findAll() : $this->query($entity)->first();
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        // morph-to and belongs-one set data on the local entity - no parent ID required
        if (empty($localId) and !in_array($this->type, ['belongs-one', 'morph-to'], true)) {
            return;
        }

        match ($this->type) {
            'meta' => $this->updateMeta($localEntity, $relatedData),
            'has-one' => $this->updateHasOne($localId, $relatedData),
            'has-many' => $this->updateHasMany($localId, $relatedData),
            'belongs-one' => $this->updateBelongsOne($localEntity, $relatedData),
            'belongs-many' => $this->updateBelongsMany($localEntity, $relatedData),
            'morph-one' => $this->updateMorphOne($localEntity, $relatedData),
            'morph-many' => $this->updateMorphMany($localEntity, $relatedData),
            'morph-to' => $this->updateMorphTo($localEntity, $relatedData),
            'morph-belongs-many' => $this->updateMorphBelongsMany($localEntity, $relatedData),
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
        if ($this->type === 'morph-to') {
            if ($value === null) {
                return null;
            }
            if ($value instanceof EntityInterface) {
                return $value;
            }
            throw EntityException::morphToRequiresInstance($this->relationName);
        }

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
            case 'has-one':
            case 'has-many':
                $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->foreignKey} = {$localTable}.{$this->localKey}", 'left');
                break;

            case 'belongs-one':
                $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->relatedKey} = {$localTable}.{$this->foreignKey}", 'left');
                break;

            case 'belongs-many':
                $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
                $foreignKey = $this->foreignKey ? : $this->relatedKey;

                $builder
                    ->join($pivot['table'], "{$pivot['table']}.{$pivot['local_column']} = {$localTable}.{$this->localKey}", 'left')
                    ->join($this->relatedTable, "{$this->relatedTable}.{$foreignKey} = {$pivot['table']}.{$pivot['foreign_column']}", 'left');
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

            case 'morph-one':
            case 'morph-many':
                $builder->join(
                    $this->relatedTable,
                    "{$this->relatedTable}.{$this->foreignKey} = {$localTable}.{$this->localKey} AND {$this->relatedTable}.{$this->morphTypeKey} = " . $db->escape($localAlias),
                    'left'
                );
                break;

            case 'morph-belongs-many':
                $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

                $builder
                    ->join($pivot['table'], "{$pivot['table']}.{$this->morphKey} = {$localTable}.{$this->localKey} AND {$pivot['table']}.{$this->morphTypeKey} = " . $db->escape($localAlias), 'left')
                    ->join($this->relatedTable, "{$this->relatedTable}.{$this->relatedKey} = {$pivot['table']}.{$this->foreignKey}", 'left');
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
            'morph-one', 'morph-many' => $builder->where($this->foreignKey, $localId)->where($this->morphTypeKey, $localEntity->getAlias()),
            'belongs-one' => $builder->where($this->relatedKey, $this->getForeignId($localEntity)),
            'belongs-many' => $this->buildBelongsManyQuery($localEntity, $localId),
            'morph-belongs-many' => $this->buildMorphBelongsManyQuery($localEntity, $localId),
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
        // morph-to: inverse side — the related entity is not owned by the parent
        if (!$this->cascade or empty($localIds) or in_array($this->type, ['belongs-one', 'morph-to'], true)) {
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
                $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
                $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localIds, []);
            }

            return;
        }

        if ($this->type === 'morph-belongs-many') {
            if ($purge) {
                $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
                $this->syncPivot($pivot['table'], $this->morphKey, $this->foreignKey, $localIds, [], $this->morphTypeKey, $localAlias);
            }

            return;
        }

        // has-one / has-many / morph-one / morph-many: batch fetch related IDs then delete recursively
        $builder = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds);

        if ($this->morphTypeKey !== '') {
            $builder->where($this->morphTypeKey, $localAlias);
        }

        $rows = $builder->get()->getResultArray();

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
    public function cascadeRestore(array $localIds, string $localAlias = ''): void
    {
        // morph-to and morph-belongs-many: not owned by parent — no-op
        if (!$this->cascade or empty($localIds) or in_array($this->type, ['belongs-one', 'belongs-many', 'meta', 'morph-to', 'morph-belongs-many'], true)) {
            return;
        }

        // has-one / has-many / morph-one / morph-many: include soft-deleted rows
        $builder = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds);

        if ($this->morphTypeKey !== '') {
            $builder->where($this->morphTypeKey, $localAlias);
        }

        $rows = $builder->get()->getResultArray();

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

        if ($this->type === 'morph-to') {
            // Group local entities by their runtime morph_type so each alias gets one batch query
            $grouped = [];

            foreach ($entities as $entity) {
                $morphId   = $entity->getAttribute($this->foreignKey);
                $morphType = $entity->getAttribute($this->morphTypeKey);

                if (empty($morphId) or empty($morphType)) {
                    $entity->setAttribute($this->relationName, null);
                    $entity->flushChanges();
                    continue;
                }

                $grouped[$morphType][$morphId][] = $entity;
            }

            foreach ($grouped as $alias => $entitiesByMorphId) {
                $relatedModel = $this->registry->getModel($alias);
                $relatedByKey = [];

                foreach ($relatedModel->findMany(array_keys($entitiesByMorphId)) as $related) {
                    $relatedByKey[$related->getAttribute($related->getFields()->getPrimaryKey())] = $related;
                }

                foreach ($entitiesByMorphId as $morphId => $parentEntities) {
                    $related = $relatedByKey[$morphId] ?? null;

                    foreach ($parentEntities as $parentEntity) {
                        $parentEntity->setAttribute($this->relationName, $related);
                        $parentEntity->flushChanges();
                    }
                }
            }

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
            $pivot = $this->registry->getPivotConfig($entities[0]->getAlias(), $this->relatedAlias);
            $this->eagerLoadPivot($entities, $localIds, $pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $dynamicConstraint);

            return;
        }

        if ($this->type === 'morph-belongs-many') {
            $alias = $entities[0]->getAlias();
            $pivot = $this->registry->getPivotConfig($alias, $this->relatedAlias);
            $this->eagerLoadPivot($entities, $localIds, $pivot['table'], $this->morphKey, $this->foreignKey, $dynamicConstraint, $alias);

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

        // morph-one / morph-many: scope to the parent entity type
        if (in_array($this->type, ['morph-one', 'morph-many'], true)) {
            $builder->where($this->morphTypeKey, $entities[0]->getAlias());
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
     * Shared pivot eager-load logic for belongs-many and morph-belongs-many.
     *
     * @param string $pivotLocalCol   Pivot column holding the parent ID (used for WHERE IN and grouping)
     * @param string $pivotForeignCol Pivot column holding the related entity ID (used for the JOIN)
     * @param string $morphTypeAlias  Non-empty for morph-belongs-many — adds AND pivot.morph_type = alias
     */
    protected function eagerLoadPivot(array $entities, array $localIds, string $pivotTable, string $pivotLocalCol, string $pivotForeignCol, ?\Closure $dynamicConstraint, string $morphTypeAlias = ''): void
    {
        $builder = $this->relatedModel->builder();

        $this->relatedModel->handleDeleted();

        $builder
            ->select("{$this->relatedTable}.*, {$pivotTable}.{$pivotLocalCol} AS __pivot_local_key")
            ->join($pivotTable, "{$pivotTable}.{$pivotForeignCol} = {$this->relatedTable}.{$this->relatedKey}")
            ->whereIn("{$pivotTable}.{$pivotLocalCol}", $localIds);

        if ($morphTypeAlias !== '') {
            $builder->where("{$pivotTable}.{$this->morphTypeKey}", $morphTypeAlias);
        }

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
        $pivot = $this->registry->getPivotConfig($localEntity->getAlias(), $this->relatedAlias);

        $this->relatedModel->builder()
            ->select("{$this->relatedTable}.*")
            ->join($pivot['table'], "{$pivot['table']}.{$pivot['foreign_column']} = {$this->relatedTable}.{$this->foreignKey}")
            ->where("{$pivot['table']}.{$pivot['local_column']}", $localId);
    }

    /**
     * Build query for morph-belongs-many: JOIN pivot on morph_id + morph_type, then JOIN related
     */
    protected function buildMorphBelongsManyQuery(EntityInterface $localEntity, int|string|null $localId): void
    {
        $pivot = $this->registry->getPivotConfig($localEntity->getAlias(), $this->relatedAlias);

        $this->relatedModel->builder()
            ->select("{$this->relatedTable}.*")
            ->join($pivot['table'], "{$pivot['table']}.{$this->foreignKey} = {$this->relatedTable}.{$this->relatedKey}")
            ->where("{$pivot['table']}.{$this->morphKey}", $localId)
            ->where("{$pivot['table']}.{$this->morphTypeKey}", $localEntity->getAlias());
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
        $localId = $this->getLocalId($localEntity);
        $pivot   = $this->registry->getPivotConfig($localEntity->getAlias(), $this->relatedAlias);

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
        $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localId, array_unique($newIds));
    }

    /**
     * Update entity for the morph-one relation (like has-one but stamps morph_type on the child)
     */
    protected function updateMorphOne(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $this->getLocalId($localEntity);
        $localAlias = $localEntity->getAlias();

        if (empty($localId)) {
            return;
        }

        $entity = $this->resolveOne($relatedData);

        if ($entity instanceof EntityInterface) {
            $entity->setAttribute($this->foreignKey, $localId);
            $entity->setAttribute($this->morphTypeKey, $localAlias);
            $this->relatedModel->save($entity);

            $entityId   = $entity->getAttribute($this->relatedKey);
            $excludeIds = empty($entityId) ? [] : [$entityId];

            $this->detachMorphOrphans($this->foreignKey, $this->morphTypeKey, $localId, $localAlias, $excludeIds);
        } else {
            $this->detachMorphOrphans($this->foreignKey, $this->morphTypeKey, $localId, $localAlias);
        }
    }

    /**
     * Update entity list for the morph-many relation (like has-many but stamps morph_type on each child)
     */
    protected function updateMorphMany(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $this->getLocalId($localEntity);
        $localAlias = $localEntity->getAlias();

        if (empty($localId)) {
            return;
        }

        if (empty($relatedData)) {
            $this->detachMorphOrphans($this->foreignKey, $this->morphTypeKey, $localId, $localAlias);
            return;
        }

        $entities  = $this->resolveMany($relatedData);
        $entityIds = [];

        foreach ($entities as $entity) {
            $entity->setAttribute($this->foreignKey, $localId);
            $entity->setAttribute($this->morphTypeKey, $localAlias);
            $this->relatedModel->save($entity);

            $entityId = $entity->getAttribute($this->relatedKey);

            if (!empty($entityId)) {
                $entityIds[] = $entityId;
            }
        }

        $this->detachMorphOrphans($this->foreignKey, $this->morphTypeKey, $localId, $localAlias, $entityIds);
    }

    /**
     * Update morph-to relation: stamp morph_id and morph_type onto the local entity (like belongs-one but two columns)
     */
    protected function updateMorphTo(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        if ($relatedData !== null and !$relatedData instanceof EntityInterface) {
            throw EntityException::invalidValue($this->relationName, 'EntityInterface');
        }

        if ($relatedData === null) {
            $localEntity->setAttribute($this->foreignKey, null);
            $localEntity->setAttribute($this->morphTypeKey, null);
            return;
        }

        $relatedAlias  = $relatedData->getAlias();
        $relatedFields = $this->registry->getEntityFields($relatedAlias);
        $relatedPk     = $relatedFields->getPrimaryKey();
        $relatedId     = $relatedData->getAttribute($relatedPk);

        if (empty($relatedId) or $relatedData->hasChanged()) {
            $this->registry->getModel($relatedAlias)->save($relatedData);
            $relatedId = $relatedData->getAttribute($relatedPk);
        }

        $localEntity->setAttribute($this->foreignKey, $relatedId);
        $localEntity->setAttribute($this->morphTypeKey, $relatedAlias);
    }

    /**
     * Sync pivot records for morph-belongs-many (like belongs-many but pivot has morph_id + morph_type)
     */
    protected function updateMorphBelongsMany(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $this->getLocalId($localEntity);
        $localAlias = $localEntity->getAlias();
        $pivot      = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

        $entities = empty($relatedData) ? [] : $this->resolveMany($relatedData);
        $newIds   = [];

        foreach ($entities as $entity) {
            $id = $entity->getAttribute($this->relatedKey);

            if (!empty($id)) {
                $newIds[] = $id;
            }
        }

        $this->syncPivot($pivot['table'], $this->morphKey, $this->foreignKey, $localId, array_unique($newIds), $this->morphTypeKey, $localAlias);
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

        $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
        $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localId, []);
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
     * Like detachOrphans() but clears both morph_id and morph_type columns,
     * and scopes the WHERE to the specific entity type.
     */
    protected function detachMorphOrphans(string $morphIdCol, string $morphTypeCol, int|string $localId, string $localAlias, array $excludeIds = []): void
    {
        $builder = $this->relatedModel->builder()
            ->where($morphIdCol, $localId)
            ->where($morphTypeCol, $localAlias);

        if (!empty($excludeIds)) {
            $builder->whereNotIn($this->relatedKey, array_unique($excludeIds));
        }

        $builder->delete();
        $this->relatedModel->reset();

        $this->relatedModel->removeFromCacheWhere($morphIdCol, $localId, $excludeIds);
    }

    /**
     * Synchronize or detach pivot records.
     * Passing an empty $foreignIds array will detach all records for the given $localId(s).
     * When $morphTypeColumn and $morphTypeAlias are provided, a morph-type condition is added
     * to all queries and the morph-type column is included in every INSERT row.
     */
    protected function syncPivot(string $pivotTable, string $localColumn, string $foreignColumn, int|string|array $localId, array $foreignIds = [], string $morphTypeColumn = '', string $morphTypeAlias = ''): void
    {
        if (empty($localId)) {
            return;
        }

        // Prevent invalid bulk-sync attempts
        if (is_array($localId) and !empty($foreignIds)) {
            throw new \InvalidArgumentException("Cannot sync multiple foreign IDs to multiple local IDs simultaneously. Array of local IDs is only permitted for bulk detaching.");
        }

        $builder = $this->relatedModel->builder($pivotTable);

        // If no foreign IDs are provided, this acts as a full detach
        if (empty($foreignIds)) {

            if (is_array($localId)) {
                $builder->whereIn($localColumn, $localId);
            } else {
                $builder->where($localColumn, $localId);
            }

            if ($morphTypeColumn !== '') {
                $builder->where($morphTypeColumn, $morphTypeAlias);
            }

            $builder->delete();
            $this->relatedModel->reset();
            return;
        }

        $currentBuilder = $builder->select($foreignColumn);

        if (is_array($localId)) {
            $currentBuilder->whereIn($localColumn, $localId);
        } else {
            $currentBuilder->where($localColumn, $localId);
        }

        if ($morphTypeColumn !== '') {
            $currentBuilder->where($morphTypeColumn, $morphTypeAlias);
        }

        $currentRecords = $currentBuilder->get()->getResultArray();

        $this->relatedModel->reset();

        $currentIds = array_column($currentRecords, $foreignColumn);

        // Diff arrays to find what needs to be added or removed
        $idsToDetach = array_diff($currentIds, $foreignIds);
        $idsToAttach = array_diff($foreignIds, $currentIds);

        // Detach old IDs
        if (!empty($idsToDetach)) {
            $detachBuilder = $this->relatedModel->builder($pivotTable)
                ->where($localColumn, $localId)
                ->whereIn($foreignColumn, $idsToDetach);

            if ($morphTypeColumn !== '') {
                $detachBuilder->where($morphTypeColumn, $morphTypeAlias);
            }

            $detachBuilder->delete();
            $this->relatedModel->reset();
        }

        // Attach new IDs
        if (!empty($idsToAttach)) {
            $insertData = [];
            foreach ($idsToAttach as $id) {
                $row = [$localColumn => $localId, $foreignColumn => $id];

                if ($morphTypeColumn !== '') {
                    $row[$morphTypeColumn] = $morphTypeAlias;
                }

                $insertData[] = $row;
            }
            $this->relatedModel->builder($pivotTable)->ignore(true)->insertBatch($insertData);
            $this->relatedModel->reset();
        }
    }
}