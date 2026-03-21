<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityException;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;

class MetaRelation extends AbstractRelation
{
    protected string $keyColumn    = 'meta_key';
    protected string $valueColumn  = 'meta_value';
    protected string $entityColumn = 'entity_id';

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
        $this->keyColumn    = $this->relatedConfig['key_column'] ?? 'meta_key';
        $this->valueColumn  = $this->relatedConfig['value_column'] ?? 'meta_value';
        $this->entityColumn = $this->relatedConfig['entity_column'] ?? 'entity_id';
    }

    public function getType(): string
    {
        return 'meta';
    }

    public function get(EntityInterface $entity): EntityInterface|array|null
    {
        $localId = $this->getLocalId($entity);
        return $localId ? $this->relatedModel->find($localId) : null;
    }

    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $localEntity->getAttribute($this->localKey);

        if (empty($localId)) {
            throw EntityException::parentNotSaved($this->relationName);
        }

        $meta = $localEntity->getAttribute($this->relationName);

        if (!$meta instanceof EntityInterface) {
            $meta = $this->relatedModel->find($localId)
                ?? new $this->relatedClass([$this->relatedKey => $localId], $this->relatedAlias, $this->relatedFields);
            $localEntity->setAttribute($this->relationName, $meta);
        }

        $meta->setAttribute($this->relatedKey, $localId);

        if (is_array($relatedData)) {
            foreach ($relatedData as $key => $value) {
                $meta->setAttribute($key, $value);
            }
        } elseif ($relatedData instanceof EntityInterface and $relatedData !== $meta) {
            $meta->setAttributes($relatedData->getChanges());
        }

        $this->relatedModel->save($meta);
    }

    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array
    {
        throw EntityException::unsupportedType('meta', 'aggregate');
    }

    public function join(BaseBuilder $builder, string $localAlias, string $column = ''): void
    {
        $localTable = $this->registry->getEntityTable($localAlias);
        $alias      = "m_{$this->relationName}_{$column}";

        $builder->join(
            "{$this->relatedTable} {$alias}",
            "{$alias}.{$this->entityColumn} = {$localTable}.{$this->localKey} AND {$alias}.{$this->keyColumn} = '{$column}'",
            'left'
        );
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $builder = $this->relatedModel->builder();
        $builder->where($this->entityColumn, $this->getLocalId($localEntity));

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

        $metas     = $this->relatedModel->findMany($localIds);
        $metaArray = [];

        foreach ($metas as $meta) {
            $metaArray[$meta->getAttribute($this->relatedKey)] = $meta;
        }

        foreach ($entities as $entity) {
            $parentId = $this->getLocalId($entity);

            $metaObject = $metaArray[$parentId] ?? new $this->relatedClass([
                $this->relatedKey => $parentId,
            ], $this->relatedAlias, $this->relatedFields);

            $entity->setAttribute($this->relationName, $metaObject);
            $entity->flushChanges();
        }
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (!$this->cascade or empty($localIds) or !$purge) {
            return;
        }

        $this->relatedModel->delete($localIds, true);
    }
}
