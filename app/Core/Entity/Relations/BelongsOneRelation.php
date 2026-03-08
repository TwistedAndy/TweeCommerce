<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

class BelongsOneRelation extends AbstractRelation
{
    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->isMultiple = false;
        $this->initRelation($relation['entity']);
    }

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
                $this->relatedModel->save($relatedEntity);
                $relatedId = $relatedEntity->getAttribute($this->relatedKey);
            }
        } else {
            $relatedId = null;
        }

        $localEntity->setAttribute($this->foreignKey, $relatedId);
    }

    public function remove(int|string|EntityInterface|null $localEntity, string $localAlias): void
    {
        $localId = $localEntity instanceof EntityInterface ? $this->getLocalId($localEntity) : $localEntity;

        if (empty($localId)) {
            return;
        }

        $localModel = $this->registry->getModel($localAlias);
        $entity     = $localModel->find($localId);

        if ($entity === null) {
            return;
        }

        $entity->setAttribute($this->foreignKey, null);
        $localModel->update($localId, $entity);
    }

    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
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

    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $localIds = [];

        foreach ($entities as $entity) {
            $localIds[] = $entity->getAttribute($this->foreignKey);
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

        $relatedRecords = $builder->whereIn($this->relatedKey, $localIds)->get()->getResultArray();
        $this->relatedModel->reset();

        $relatedByKey = [];

        foreach ($relatedRecords as $row) {
            $key                  = (string) $row[$this->relatedKey];
            $relatedByKey[$key][] = $this->relatedModel->hydrateRow($row);
        }

        foreach ($entities as $parentEntity) {
            $parentId = (string) $this->getForeignId($parentEntity);

            if (empty($parentId)) {
                $parentEntity->setAttribute($this->relationName, null);
                $parentEntity->flushChanges();
                continue;
            }

            $matched = $relatedByKey[$parentId] ?? [];
            $parentEntity->setAttribute($this->relationName, $matched[0] ?? null);
            $parentEntity->flushChanges();
        }
    }
}
