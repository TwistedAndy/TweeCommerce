<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityException;
use App\Core\Entity\EntityFields;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;

abstract class AbstractRelation implements RelationInterface
{
    protected bool           $isMultiple    = false;
    protected string         $relatedAlias  = '';
    protected string         $relatedClass  = '';
    protected string         $relatedTable  = '';
    protected string         $relatedKey    = '';
    protected array          $relatedConfig = [];
    protected EntityModel    $relatedModel;
    protected EntityFields   $relatedFields;
    protected EntityRegistry $registry;

    protected string $localKey     = '';
    protected string $foreignKey   = '';
    protected string $relationName = '';
    protected array  $constraint   = [];
    protected bool   $cascade      = false;

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        $this->registry     = $registry;
        $this->localKey     = $relation['local_key'] ?? '';
        $this->foreignKey   = $relation['foreign_key'] ?? '';
        $this->constraint   = $relation['constraint'] ?? [];
        $this->cascade      = (bool) ($relation['cascade'] ?? false);
        $this->relationName = $name;
    }

    /**
     * Resolve the related entity's metadata from the registry.
     * Call this in subclass constructors for all types except morph-to.
     */
    protected function initRelation(string $alias): void
    {
        $this->relatedAlias  = $alias;
        $this->relatedConfig = $this->registry->getConfig($alias);
        $this->relatedClass  = $this->registry->getEntityClass($alias);
        $this->relatedFields = $this->registry->getEntityFields($alias);
        $this->relatedModel  = $this->registry->getModel($alias);
        $this->relatedTable  = $this->registry->getEntityTable($alias);
        $this->relatedKey    = $this->relatedFields->getPrimaryKey();
    }

    public function getTable(): string
    {
        return $this->relatedTable;
    }

    public function getConfig(): array
    {
        return $this->relatedConfig;
    }

    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        return $this->isMultiple ? $this->resolveMany($value) : $this->resolveOne($value);
    }

    public function remove(int|string|EntityInterface|null $localEntity, string $localAlias): void
    {
    }

    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
    }

    public function cascadeRestore(array $localIds, string $localAlias = ''): void
    {
    }

    protected function getLocalId(EntityInterface $entity): int|string|null
    {
        return $entity->getAttribute($this->localKey);
    }

    protected function getForeignId(EntityInterface $entity): int|string|null
    {
        return $entity->getAttribute($this->foreignKey);
    }

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
                $this->relatedModel->{$callback}($builder);
            } elseif (is_callable($callback)) {
                call_user_func($callback, $builder, $this->relatedModel);
            } else {
                throw EntityException::invalidCallback($this->relationName);
            }
        }
    }

    protected function resolveOne(int|string|array|null|EntityInterface $value): ?EntityInterface
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) and array_is_list($value)) {
            throw EntityException::sequentialArray($this->relationName, $this->relatedClass);
        }

        if ($value instanceof EntityInterface) {
            if (!$value instanceof $this->relatedClass) {
                throw EntityException::typeMismatch($this->relatedClass, get_class($value));
            }
            return $value;
        }

        if (is_scalar($value)) {
            return $this->relatedModel->find($value) ?? throw EntityException::relatedNotFound($this->relatedClass, $value);
        }

        if (is_array($value) and !array_is_list($value)) {
            $relatedId = $value[$this->relatedKey] ?? null;

            if ($relatedId !== null and $existing = $this->relatedModel->find($relatedId)) {
                $existing->setAttributes($value);
                return $existing;
            }

            $entity = new $this->relatedClass([], $this->relatedAlias);
            $entity->setAttributes($value);

            return $entity;
        }

        throw EntityException::invalidValue($this->relationName, $this->relatedClass);
    }

    protected function resolveMany(int|string|array|null|EntityInterface $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value) or $value instanceof EntityInterface or (is_array($value) and !array_is_list($value))) {
            $value = [$value];
        }

        if (!is_array($value)) {
            throw EntityException::invalidManyValue($this->relationName, $this->relatedClass);
        }

        $entities   = [];
        $idsToFetch = [];

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
                    $entity = new $this->relatedClass([], $this->relatedAlias, $this->relatedFields);
                    $entity->setAttributes($item);
                    $entities[] = $entity;
                }
            } elseif (is_scalar($item)) {
                $idsToFetch[] = $item;
            } else {
                throw EntityException::invalidItem($this->relationName, $this->relatedClass);
            }
        }

        if ($idsToFetch) {
            $entities = array_merge($entities, $this->relatedModel->findMany($idsToFetch));
        }

        return $entities;
    }

    protected function detachOrphans(string $foreignKey, int|string $localId, array $excludeIds = []): void
    {
        $builder = $this->relatedModel->builder()->where($foreignKey, $localId);

        if (!empty($excludeIds)) {
            $builder->whereNotIn($this->relatedKey, array_unique($excludeIds));
        }

        $builder->update([$foreignKey => null]);
        $this->relatedModel->reset();

        $this->relatedModel->removeFromCacheWhere($foreignKey, $localId, $excludeIds);
    }
}
