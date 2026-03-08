<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

class BelongsManyRelation extends AbstractRelation
{
    protected bool   $isMorph      = false;
    protected string $morphTypeKey = '';
    protected string $morphKey     = '';

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->isMultiple = true;
        $this->isMorph    = ($relation['type'] === 'morph-belongs-many');
        $this->initRelation($relation['entity']);

        if ($this->isMorph) {
            $morphKey           = $relation['morph_key'];
            $this->morphKey     = $morphKey . '_id';
            $this->morphTypeKey = $morphKey . '_type';
        }

        if (empty($this->foreignKey)) {
            $this->foreignKey = $this->relatedKey;
        }
    }

    public function getType(): string
    {
        return $this->isMorph ? 'morph-belongs-many' : 'belongs-many';
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        return $this->query($entity)->findAll();
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $this->getLocalId($localEntity);

        if (empty($localId)) {
            return;
        }

        $localAlias = $localEntity->getAlias();
        $pivot      = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
        $entities   = empty($relatedData) ? [] : $this->resolveMany($relatedData);
        $newIds     = [];

        if ($this->isMorph) {
            foreach ($entities as $entity) {
                $id = $entity->getAttribute($this->relatedKey);
                if (!empty($id)) {
                    $newIds[] = $id;
                }
            }
            $this->syncPivot($pivot['table'], $this->morphKey, $this->foreignKey, $localId, array_unique($newIds), $this->morphTypeKey, $localAlias);
        } else {
            foreach ($entities as $entity) {
                $id = $entity->getAttribute($this->foreignKey);
                if (!empty($id)) {
                    $newIds[] = $id;
                }
            }
            $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localId, array_unique($newIds));
        }
    }

    public function remove(int|string|EntityInterface|null $localEntity, string $localAlias): void
    {
        if (empty($localEntity) or $this->isMorph) {
            return;
        }

        $localId = $localEntity instanceof EntityInterface ? $this->getLocalId($localEntity) : $localEntity;

        if (empty($localId)) {
            return;
        }

        $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
        $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localId, []);
    }

    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
        $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

        if ($this->isMorph) {
            $builder
                ->join($pivot['table'], "{$pivot['table']}.{$this->morphKey} = {$localTable}.{$this->localKey} AND {$pivot['table']}.{$this->morphTypeKey} = " . $db->escape($localAlias), 'left')
                ->join($this->relatedTable, "{$this->relatedTable}.{$this->relatedKey} = {$pivot['table']}.{$this->foreignKey}", 'left');
        } else {
            $builder
                ->join($pivot['table'], "{$pivot['table']}.{$pivot['local_column']} = {$localTable}.{$this->localKey}", 'left')
                ->join($this->relatedTable, "{$this->relatedTable}.{$this->foreignKey} = {$pivot['table']}.{$pivot['foreign_column']}", 'left');
        }
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $localId    = $this->getLocalId($localEntity);
        $localAlias = $localEntity->getAlias();
        $pivot      = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);
        $builder    = $this->relatedModel->builder();

        if ($this->isMorph) {
            $builder
                ->select("{$this->relatedTable}.*")
                ->join($pivot['table'], "{$pivot['table']}.{$this->foreignKey} = {$this->relatedTable}.{$this->relatedKey}")
                ->where("{$pivot['table']}.{$this->morphKey}", $localId)
                ->where("{$pivot['table']}.{$this->morphTypeKey}", $localAlias);
        } else {
            $builder
                ->select("{$this->relatedTable}.*")
                ->join($pivot['table'], "{$pivot['table']}.{$pivot['foreign_column']} = {$this->relatedTable}.{$this->foreignKey}")
                ->where("{$pivot['table']}.{$pivot['local_column']}", $localId);
        }

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

        $alias = $entities[0]->getAlias();
        $pivot = $this->registry->getPivotConfig($alias, $this->relatedAlias);

        if ($this->isMorph) {
            $this->eagerLoadPivot($entities, $localIds, $pivot['table'], $this->morphKey, $this->foreignKey, $dynamicConstraint, $alias);
        } else {
            $this->eagerLoadPivot($entities, $localIds, $pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $dynamicConstraint);
        }
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or empty($localIds) or !$purge) {
            return;
        }

        $pivot = $this->registry->getPivotConfig($localAlias, $this->relatedAlias);

        if ($this->isMorph) {
            $this->syncPivot($pivot['table'], $this->morphKey, $this->foreignKey, $localIds, [], $this->morphTypeKey, $localAlias);
        } else {
            $this->syncPivot($pivot['table'], $pivot['local_column'], $pivot['foreign_column'], $localIds, []);
        }
    }

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

    protected function syncPivot(string $pivotTable, string $localColumn, string $foreignColumn, int|string|array $localId, array $foreignIds = [], string $morphTypeColumn = '', string $morphTypeAlias = ''): void
    {
        if (empty($localId)) {
            return;
        }

        if (is_array($localId) and !empty($foreignIds)) {
            throw new \InvalidArgumentException('Cannot sync multiple foreign IDs to multiple local IDs simultaneously. Array of local IDs is only permitted for bulk detaching.');
        }

        $builder = $this->relatedModel->builder($pivotTable);

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

        $currentIds  = array_column($currentRecords, $foreignColumn);
        $idsToDetach = array_diff($currentIds, $foreignIds);
        $idsToAttach = array_diff($foreignIds, $currentIds);

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
