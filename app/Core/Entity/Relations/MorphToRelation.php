<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityException;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

class MorphToRelation extends AbstractRelation
{
    protected string $morphTypeKey;

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $morphKey           = $relation['morph_key'];
        $this->foreignKey   = $morphKey . '_id';
        $this->morphTypeKey = $morphKey . '_type';
    }

    public function getType(): string
    {
        return 'morph-to';
    }

    public function getTable(): string
    {
        return '';
    }

    public function getConfig(): array
    {
        return [];
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        $morphId   = $entity->getAttribute($this->foreignKey);
        $morphType = $entity->getAttribute($this->morphTypeKey);

        if (empty($morphId) or empty($morphType)) {
            return null;
        }

        return $this->registry->getModel($morphType)->find($morphId);
    }

    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof EntityInterface) {
            return $value;
        }

        throw EntityException::morphToRequiresInstance($this->relationName);
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
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

    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
        throw EntityException::unsupportedType('morph-to', 'join');
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        throw EntityException::unsupportedType('morph-to', 'query');
    }

    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
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
    }
}
