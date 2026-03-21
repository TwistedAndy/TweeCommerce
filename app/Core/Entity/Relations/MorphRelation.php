<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;

class MorphRelation extends AbstractRelation
{
    protected string $morphTypeKey;

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->isMultiple   = ($relation['type'] === 'morph-many');
        $this->morphTypeKey = $relation['morph_key'] . '_type';
        $this->foreignKey   = $relation['morph_key'] . '_id';
    }

    public function getType(): string
    {
        return $this->isMultiple ? 'morph-many' : 'morph-one';
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId    = $this->getLocalId($localEntity);
        $localAlias = $localEntity->getAlias();

        if (empty($localId)) {
            return;
        }

        if ($this->isMultiple) {
            $this->updateMorphMany($localId, $localAlias, $relatedData);
        } else {
            $this->updateMorphOne($localId, $localAlias, $relatedData);
        }
    }

    public function join(BaseBuilder $builder, string $localAlias, string $column = ''): void
    {
        $localTable = $this->registry->getEntityTable($localAlias);
        $builder->join(
            $this->relatedTable,
            "{$this->relatedTable}.{$this->foreignKey} = {$localTable}.{$this->localKey} AND {$this->relatedTable}.{$this->morphTypeKey} = '{$localAlias}'",
            'left'
        );
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $builder = $this->relatedModel->builder();
        $builder->where($this->foreignKey, $this->getLocalId($localEntity))
                ->where($this->morphTypeKey, $localEntity->getAlias());

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        return $this->relatedModel;
    }

    public function preload(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $localIds = $this->collectIds($entities, $this->localKey);

        if (!$localIds) {
            return;
        }

        $builder = $this->relatedModel->builder();
        $this->relatedModel->handleDeleted();

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        if ($dynamicConstraint) {
            $dynamicConstraint($builder);
        }

        $builder->where($this->morphTypeKey, $entities[0]->getAlias());

        $relatedRecords = $builder->whereIn($this->foreignKey, $localIds)->get()->getResultArray();
        $this->relatedModel->reset();

        $relatedByKey = [];

        foreach ($relatedRecords as $row) {
            $key = (string) $row[$this->foreignKey];
            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        $this->assignFromMap($entities, $relatedByKey, $this->localKey);
    }

    /**
     * Like HasRelation::aggregate() but adds the morph type filter.
     *
     * SQL: SELECT foreign_key AS __group_key, {expression} AS {resultAlias}
     *      FROM related_table
     *      WHERE foreign_key IN (...)
     *        AND morph_type = localAlias  [AND soft-delete filter]
     *      GROUP BY foreign_key
     */
    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array
    {
        $builder = $this->relatedModel->builder();
        $this->relatedModel->handleDeleted();

        $builder
            ->select("{$this->foreignKey} AS __group_key, {$expression} AS {$resultAlias}")
            ->whereIn($this->foreignKey, $lookupIds)
            ->where($this->morphTypeKey, $localAlias)
            ->groupBy($this->foreignKey);

        if ($constraint) {
            $constraint($builder);
        }

        return $this->runAggregateQuery($builder, $resultAlias);
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or empty($localIds)) {
            return;
        }

        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->where($this->morphTypeKey, $localAlias)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if (!empty($ids)) {
            $this->relatedModel->delete($ids, $purge);
        }
    }

    public function cascadeRestore(array $localIds, string $localAlias = ''): void
    {
        if (!$this->cascade or empty($localIds)) {
            return;
        }

        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->where($this->morphTypeKey, $localAlias)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if (!empty($ids)) {
            $this->relatedModel->restore($ids);
        }
    }

    protected function updateMorphOne(int|string $localId, string $localAlias, array|null|EntityInterface $relatedData): void
    {
        $entity = $this->resolveOne($relatedData);

        if ($entity instanceof EntityInterface) {
            $entity->setAttribute($this->foreignKey, $localId);
            $entity->setAttribute($this->morphTypeKey, $localAlias);

            if (!$this->relatedModel->save($entity)) {
                return;
            }

            $entityId   = $entity->getAttribute($this->relatedKey);
            $excludeIds = empty($entityId) ? [] : [$entityId];

            $this->detachMorphOrphans($localId, $localAlias, $excludeIds);
        } else {
            $this->detachMorphOrphans($localId, $localAlias);
        }
    }

    protected function updateMorphMany(int|string $localId, string $localAlias, array|null|EntityInterface $relatedData): void
    {
        if (empty($relatedData)) {
            $this->detachMorphOrphans($localId, $localAlias);
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

        $this->detachMorphOrphans($localId, $localAlias, $entityIds);
    }

    protected function detachMorphOrphans(int|string $localId, string $localAlias, array $excludeIds = []): void
    {
        $builder = $this->relatedModel->builder()
            ->where($this->foreignKey, $localId)
            ->where($this->morphTypeKey, $localAlias);

        if (!empty($excludeIds)) {
            $builder->whereNotIn($this->relatedKey, array_unique($excludeIds));
        }

        $builder->delete();
        $this->relatedModel->reset();

        $this->relatedModel->removeFromCacheWhere($this->foreignKey, $localId, $excludeIds);
    }
}
