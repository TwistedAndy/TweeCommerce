<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;

class HasRelation extends AbstractRelation
{
    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->isMultiple = ($relation['type'] === 'has-many');
    }

    public function getType(): string
    {
        return $this->isMultiple ? 'has-many' : 'has-one';
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        if (empty($localId)) {
            return;
        }

        if ($this->isMultiple) {
            $this->updateMany($localId, $relatedData);
        } else {
            $this->updateOne($localId, $relatedData);
        }
    }

    public function join(BaseBuilder $builder, string $localAlias, string $column = ''): void
    {
        $localTable = $this->registry->getEntityTable($localAlias);
        $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->foreignKey} = {$localTable}.{$this->localKey}", 'left');
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $builder = $this->relatedModel->builder();
        $builder->where($this->foreignKey, $this->getLocalId($localEntity));

        if ($this->constraint) {
            $this->applyConstraints($builder);
        }

        return $this->relatedModel;
    }

    public function preload(array $entities, ?\Closure $dynamicConstraint = null): void
    {
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

        $rows = $builder->whereIn($this->foreignKey, $localIds)->get()->getResultArray();
        $this->relatedModel->reset();

        $relatedByKey = [];

        foreach ($rows as $row) {
            $key                  = (string) $row[$this->foreignKey];
            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        $this->assignFromMap($entities, $relatedByKey, $this->localKey);
    }

    /**
     * Groups by foreign_key on the related table.
     *
     * SQL: SELECT foreign_key AS __group_key, {expression} AS {resultAlias}
     *      FROM related_table
     *      WHERE foreign_key IN (...)  [AND soft-delete filter]
     *      GROUP BY foreign_key
     */
    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array
    {
        $builder = $this->relatedModel->builder();
        $this->relatedModel->handleDeleted();

        $builder
            ->select("{$this->foreignKey} AS __group_key, {$expression} AS {$resultAlias}")
            ->whereIn($this->foreignKey, $lookupIds)
            ->groupBy($this->foreignKey);

        if ($constraint) {
            $constraint($builder);
        }

        return $this->runAggregateQuery($builder, $resultAlias);
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or !$localIds) {
            return;
        }

        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if ($ids) {
            $this->relatedModel->delete($ids, $purge);
        }
    }

    public function cascadeRestore(array $localIds, string $localAlias = ''): void
    {
        if (!$this->cascade or !$localIds) {
            return;
        }

        $rows = $this->relatedModel->builder()
            ->select($this->relatedKey)
            ->whereIn($this->foreignKey, $localIds)
            ->get()->getResultArray();

        $this->relatedModel->reset();

        $ids = array_column($rows, $this->relatedKey);

        if ($ids) {
            $this->relatedModel->restore($ids);
        }
    }

    protected function updateOne(int|string $localId, array|null|EntityInterface $relatedData): void
    {
        $entity = $this->resolveOne($relatedData);

        if ($entity instanceof EntityInterface) {
            $entity->setAttribute($this->foreignKey, $localId);

            if (!$this->relatedModel->save($entity)) {
                return;
            }

            $entityId   = $entity->getAttribute($this->relatedKey);
            $excludeIds = empty($entityId) ? [] : [$entityId];

            $this->detachOrphans($this->foreignKey, $localId, $excludeIds);
        } else {
            $this->detachOrphans($this->foreignKey, $localId);
        }
    }

    protected function updateMany(int|string $localId, array|null|EntityInterface $relatedData): void
    {
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
}
