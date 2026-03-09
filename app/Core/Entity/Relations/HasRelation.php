<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

class HasRelation extends AbstractRelation
{
    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->isMultiple = ($relation['type'] === 'has-many');
        $this->initRelation($relation['entity']);
    }

    public function getType(): string
    {
        return $this->isMultiple ? 'has-many' : 'has-one';
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        if (!$this->getLocalId($entity)) {
            return $this->isMultiple ? [] : null;
        }

        return $this->isMultiple ? $this->query($entity)->findAll() : $this->query($entity)->first();
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        if (empty($localId)) {
            return;
        }

        if ($this->isMultiple) {
            $this->updateHasMany($localId, $relatedData);
        } else {
            $this->updateHasOne($localId, $relatedData);
        }
    }

    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
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

    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $localIds = [];

        foreach ($entities as $entity) {
            $localIds[] = $entity->getAttribute($this->localKey);
        }

        $localIds = array_filter(array_unique($localIds));

        if (empty($localIds)) {
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

        $relatedRecords = $builder->whereIn($this->foreignKey, $localIds)->get()->getResultArray();
        $this->relatedModel->reset();

        $relatedByKey = [];

        foreach ($relatedRecords as $row) {
            $key                  = (string) $row[$this->foreignKey];
            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        $empty = $this->isMultiple ? [] : null;

        foreach ($entities as $parentEntity) {
            $parentId = (string) $this->getLocalId($parentEntity);

            if (empty($parentId)) {
                $parentEntity->setAttribute($this->relationName, $empty);
                $parentEntity->flushChanges();
                continue;
            }

            $matched = $relatedByKey[$parentId] ?? [];
            $parentEntity->setAttribute($this->relationName, $this->isMultiple ? $matched : ($matched[0] ?? null));
            $parentEntity->flushChanges();
        }
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or empty($localIds)) {
            return;
        }

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

        try {
            $rows = $builder->get()->getResultArray();
        } finally {
            $this->relatedModel->reset();
        }

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['__group_key']] = $row[$resultAlias];
        }

        return $map;
    }

    public function cascadeRestore(array $localIds, string $localAlias = ''): void
    {
        if (!$this->cascade or empty($localIds)) {
            return;
        }

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

    private function updateHasOne(int|string $localId, array|null|EntityInterface $relatedData): void
    {
        $entity = $this->resolveOne($relatedData);

        if ($entity instanceof EntityInterface) {
            $entity->setAttribute($this->foreignKey, $localId);
            $this->relatedModel->save($entity);

            $entityId   = $entity->getAttribute($this->relatedKey);
            $excludeIds = empty($entityId) ? [] : [$entityId];

            $this->detachOrphans($this->foreignKey, $localId, $excludeIds);
        } else {
            $this->detachOrphans($this->foreignKey, $localId);
        }
    }

    private function updateHasMany(int|string $localId, array|null|EntityInterface $relatedData): void
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
