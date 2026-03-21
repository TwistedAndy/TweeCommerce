<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use CodeIgniter\Database\BaseBuilder;
use App\Core\Entity\EntityRegistry;

class BelongsOneRelation extends AbstractRelation
{
    public function getType(): string
    {
        return 'belongs-one';
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        if (!$this->getForeignId($entity)) {
            return null;
        }

        return $this->query($entity)->first();
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $relatedEntity = $this->resolveOne($relatedData);

        if ($relatedEntity instanceof EntityInterface) {
            $relatedId = $relatedEntity->getAttribute($this->relatedKey);

            if (empty($relatedId) or $relatedEntity->hasChanged()) {
                if (!$this->relatedModel->save($relatedEntity)) {
                    return;
                }

                $relatedId = $relatedEntity->getAttribute($this->relatedKey);
            }
        } else {
            $relatedId = null;
        }

        $localEntity->setAttribute($this->foreignKey, $relatedId);
    }

    /**
     * The FK lives on the local entity, so we match against the related entity's PK.
     */
    public function getAggregateKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Groups by the related entity's PK (each local entity references it via its FK).
     *
     * SQL: SELECT pk AS __group_key, {expression} AS {resultAlias}
     *      FROM related_table
     *      WHERE pk IN (fk_values)  [AND soft-delete filter]
     *      GROUP BY pk
     */
    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array
    {
        $builder = $this->relatedModel->builder();
        $this->relatedModel->handleDeleted();

        $builder
            ->select("{$this->relatedKey} AS __group_key, {$expression} AS {$resultAlias}")
            ->whereIn($this->relatedKey, $lookupIds)
            ->groupBy($this->relatedKey);

        if ($constraint) {
            $constraint($builder);
        }

        return $this->runAggregateQuery($builder, $resultAlias);
    }

    public function join(BaseBuilder $builder, string $localAlias, string $column = ''): void
    {
        $localTable = $this->registry->getEntityTable($localAlias);
        $builder->join($this->relatedTable, "{$this->relatedTable}.{$this->relatedKey} = {$localTable}.{$this->foreignKey}", 'left');
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $builder = $this->relatedModel->builder();
        $builder->where($this->relatedKey, $this->getForeignId($localEntity));

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

        $localIds = $this->collectIds($entities, $this->foreignKey);

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

        $relatedRecords = $builder->whereIn($this->relatedKey, $localIds)->get()->getResultArray();
        $this->relatedModel->reset();

        $relatedByKey = [];

        foreach ($relatedRecords as $row) {
            $key                  = (string) $row[$this->relatedKey];
            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        $this->assignFromMap($entities, $relatedByKey, $this->foreignKey);
    }
}
