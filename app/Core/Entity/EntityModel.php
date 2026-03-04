<?php

namespace App\Core\Entity;

use App\Core\Entity\Traits\ModelCache;
use App\Core\Entity\Traits\ModelRelations;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Pager\PagerInterface;
use CodeIgniter\Validation\ValidationInterface;

use BadMethodCallException;

/**
 * Entity Model
 */
class EntityModel
{
    use ModelCache;
    use ModelRelations;

    public readonly PagerInterface $pager;

    protected ?string        $DBGroup;
    protected BaseBuilder    $builder;
    protected BaseConnection $db;

    protected string         $table;
    protected string         $primaryKey;
    protected string         $class;
    protected string         $alias;
    protected EntityRegistry $registry;

    protected string $dateFormat   = 'U';
    protected string $deletedField = 'deleted_at';
    protected string $createdField = 'created_at';
    protected string $updatedField = 'updated_at';

    protected bool $useSoftDeletes = false;
    protected bool $excludeDeleted = true;
    protected bool $useTimestamps  = false;

    protected ValidationInterface $validator;

    protected array $allowedFields    = [];
    protected array $validationRules  = [];
    protected array $validationErrors = [];
    protected bool  $skipValidation   = false;

    public function __construct(string $alias, EntityRegistry $registry, ValidationInterface $validator, PagerInterface $pager)
    {
        $fields = $registry->getEntityFields($alias);

        if ($fields === null) {
            throw new EntityException('There is no entity with specified alias: ' . $alias);
        }

        $this->alias    = $alias;
        $this->class    = $registry->getEntityClass($alias);
        $this->registry = $registry;
        $this->pager    = $pager;

        $this->DBGroup = $registry->getDatabaseGroup($alias);

        $this->db      = \Config\Database::connect($this->DBGroup);
        $this->table   = $registry->getEntityTable($alias);
        $this->builder = $this->db->table($this->table);

        $this->validator    = $validator;
        $this->primaryKey   = $fields->getPrimaryKey();
        $this->createdField = $fields->getCreatedKey();
        $this->updatedField = $fields->getUpdatedKey();
        $this->deletedField = $fields->getDeletedKey();

        $allowedFields   = [];
        $validationRules = [];

        $primaryKey = $fields->getPrimaryKey();
        $relations  = $fields->getRelations();

        foreach ($fields->getFields() as $key => $field) {

            if ($key != $primaryKey and !isset($relations[$key])) {
                $allowedFields[$key] = true;
            }

            if (empty($field['rules']) or !is_array($field['rules']) or empty($field['rules']['rules'])) {
                continue;
            }

            $rule = [];

            if (!empty($field['label'])) {
                $rule['label'] = $field['label'];
            }

            $rules = $field['rules']['rules'];

            if (is_string($rules)) {
                $rules = str_replace('{table}', $this->table, $rules);
            } elseif (is_array($rules)) {
                foreach ($rules as $index => $rule) {
                    $rules[$index] = str_replace('{table}', $this->table, $rule);
                }
            }

            $rule['rules'] = $rules;

            if (!empty($field['rules']['errors'])) {
                $rule['errors'] = $field['rules']['errors'];
            }

            $validationRules[$key] = $rule;
        }

        $this->useTimestamps  = ($this->createdField or $this->updatedField);
        $this->useSoftDeletes = (bool) $this->deletedField;

        $this->allowedFields   = $allowedFields;
        $this->validationRules = $validationRules;

        $this->setRelations($fields);

        $this->initCache($this->alias);

        if ($this->createdField) {
            $dateFormat = $fields->getDateFormat($this->createdField);

            if ($dateFormat) {
                $this->dateFormat = $dateFormat;
            }
        } elseif ($this->updatedField) {
            $dateFormat = $fields->getDateFormat($this->updatedField);

            if ($dateFormat) {
                $this->dateFormat = $dateFormat;
            }
        }

    }

    public function __invoke(): BaseBuilder
    {
        return $this->builder;
    }

    /**
     * Proxies missing methods directly to the native CI4 Query Builder.
     *
     * @return $this|array<int|string, mixed>|BaseBuilder|bool|float|int|object|string|null
     */
    public function __call(string $name, array $params)
    {
        if (method_exists($this->builder, $name)) {
            $result = $this->builder->{$name}(...$params);
        } else {
            throw new BadMethodCallException('Call to undefined method ' . static::class . '::' . $name);
        }

        if ($result instanceof BaseBuilder) {
            return $this;
        }

        return $result;
    }

    /**
     * Get the active Query Builder for this model's table.
     */
    public function builder(?string $table = null): BaseBuilder
    {
        if ($table === null or $table === $this->table) {
            return $this->builder;
        }

        return $this->db->table($table);
    }

    public function find(int|string $id): ?EntityInterface
    {
        $entity = $this->getFromCache($id);

        if ($entity !== null) {
            return $entity;
        }

        // Apply condition natively to the builder, then explicitly call the model's first() method
        $this->builder->where($this->primaryKey, $id);

        return $this->first();
    }

    /**
     * Get entities with selected IDs
     *
     * @param int[]|string[] $ids
     *
     * @return EntityInterface[]
     */
    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // Do not cache the relations
        if ($this->hasWith()) {
            // Apply condition natively to the builder, then explicitly call the model's findAll() method
            $this->builder->whereIn($this->primaryKey, $ids);
            return $this->findAll();
        }

        $missingIds = [];

        $existingEntities = [];

        foreach ($ids as $id) {
            if ($cached = $this->getFromCache($id)) {
                $existingEntities[$id] = $cached;
            } else {
                $missingIds[] = $id;
            }
        }

        $entities = [];

        if ($missingIds) {

            $this->builder->whereIn($this->primaryKey, $missingIds);

            $foundEntities = $this->findAll();

            if ($foundEntities) {
                foreach ($foundEntities as $entity) {
                    $existingEntities[$entity->getAttribute($this->primaryKey)] = $entity;
                }
            }

            // Reorder the entities
            foreach ($ids as $id) {
                if (isset($existingEntities[$id])) {
                    $entities[$id] = $existingEntities[$id];
                }
            }
        } else {
            $entities = $existingEntities;
        }

        return array_values($entities);
    }

    /**
     * Find all records matching conditions
     *
     * @return EntityInterface[]
     */
    public function findAll(): array
    {
        $this->handleDeleted();

        $rows = $this->builder->get()->getResultArray();

        if ($rows) {
            $entities = array_map([$this, 'hydrateRow'], $rows);

            $this->loadRelations($entities);
        } else {
            $entities = [];
        }

        $this->reset();

        return $entities;
    }

    public function first(): ?EntityInterface
    {
        $this->handleDeleted();

        $row = $this->builder->get()->getRowArray();

        if (empty($row)) {
            $this->reset();
            return null;
        }

        $entity = $this->hydrateRow($row);

        $this->reset();

        return $entity;
    }

    public function save(EntityInterface $entity): bool
    {
        $id = $entity->getAttribute($this->primaryKey);

        return empty($id) ? $this->insert($entity) : $this->update($id, $entity);
    }

    public function insert(EntityInterface $entity): bool
    {
        if (!$this->validate($entity, false)) {
            return false;
        }

        $useTransaction = (bool) $this->getRelations();

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            // Pre-Save Relations (Transforms relation objects into FKs like author_id)
            if ($useTransaction) {
                $this->saveRelations($entity);
            }

            if ($this->createdField) {
                $entity->setAttribute($this->createdField, time());
            }

            $data = array_intersect_key($entity->getAttributes(), $this->allowedFields);

            if (empty($data)) {
                if ($useTransaction) {
                    $this->db->transRollback();
                }
                return false;
            }

            $success = $this->builder->insert($data);

            $this->reset();

            if (!$success) {
                if ($useTransaction) {
                    $this->db->transRollback();
                }
                return false;
            }

            $insertId = $this->db->insertID();
            $entity->setAttribute($this->primaryKey, $insertId);

            // Post-Save Relations (has-many, meta, etc.)
            if ($useTransaction) {
                $this->saveRelations($entity);
            }

            $entity->flushChanges();

            $this->addToCache($insertId, $entity);

            if ($useTransaction) {
                $this->db->transCommit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->db->transRollback();
            }
            throw new EntityException('Failed to insert entity: ' . $e->getMessage(), 0, $e);
        }
    }

    public function update(int|string $id, EntityInterface $entity): bool
    {
        if (!$entity->hasChanged()) {
            return true;
        }

        $data = $entity->getChanges();

        if (!$this->validate($data, true)) {
            return false;
        }

        $useTransaction = (bool) $this->getRelations();

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            // Pre-Save Relations
            if ($useTransaction) {
                $this->saveRelations($entity);
            }

            if ($this->updatedField) {
                $entity->setAttribute($this->updatedField, time());
            }

            // Extract changes AFTER pre-save relations have run
            $changes = $entity->getChanges();
            $changes = array_intersect_key($changes, $this->allowedFields);

            if (!empty($changes)) {
                $this->withDeleted();

                $this->builder->where($this->primaryKey, $id)->update($changes);
            }

            $this->reset();

            // Post-Save Relations
            if ($useTransaction) {
                $this->saveRelations($entity);
            }

            $entity->flushChanges();

            $this->addToCache($id, $entity);

            if ($useTransaction) {
                $this->db->transCommit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->db->transRollback();
            }
            throw new EntityException('Failed to update entity: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete one or more records.
     *
     * Pass an array to delete in bulk (one query). Pass $purge = true to hard-delete
     * even when the model uses soft-deletes. Cascades to relations with cascade => true.
     */
    public function delete(int|string|array $ids, bool $purge = false): bool
    {
        if (is_string($ids) or is_int($ids)) {
            $ids = [$ids];
        }

        if (empty($ids)) {
            return true;
        }

        $useTransaction = $this->getRelations();

        if ($useTransaction) {
            $this->db->transBegin();
        }

        if (!$this->useSoftDeletes) {
            $purge = true;
        }

        try {
            $this->cascadeDelete($ids, $this->alias, $purge);

            $this->withDeleted();

            $this->builder->whereIn($this->primaryKey, $ids);

            if ($purge) {
                $result = $this->builder->delete();
            } else {
                $result = $this->builder->update([$this->deletedField => $this->formatTimestamp(time())]);
            }

            $this->reset();

            $this->removeFromCache($ids);

            if ($useTransaction) {
                $this->db->transCommit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->db->transRollback();
            }
            throw new EntityException('Failed to delete entity: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Restore one or more soft-deleted records.
     *
     * Cascades to relations with cascade => true, restoring their soft-deleted
     * children recursively before un-deleting the parent rows.
     */
    public function restore(int|string|array $ids): bool
    {
        if (is_string($ids) or is_int($ids)) {
            $ids = [$ids];
        }

        if (empty($ids) or !$this->useSoftDeletes) {
            return true;
        }

        $useTransaction = $this->getRelations();

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            $this->cascadeRestore($ids);

            $this->withDeleted();

            $this->builder->whereIn($this->primaryKey, $ids);
            $result = $this->builder->update([$this->deletedField => null]);

            $this->reset();

            $this->removeFromCache($ids);

            if ($useTransaction) {
                $this->db->transCommit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->db->transRollback();
            }

            throw new EntityException('Failed to restore entity: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Explicitly discard any pending builder conditions and start a clean query.
     */
    public function reset(): self
    {
        $this->builder->resetQuery();
        $this->excludeDeleted = true;
        $this->resetWith();

        return $this;
    }

    /**
     * Paginates results using CI4's Pager library.
     * After calling this method, access $model->pager to render pagination links.
     *
     * @return EntityInterface[]
     */
    public function paginate(int $perPage = 20, string $group = 'default', int $page = 0): array
    {
        $page = max(1, $page ? : (int) $this->pager->getCurrentPage($group));

        // countAllResults(false) runs COUNT(*) while preserving all WHERE/JOIN conditions
        // so the subsequent findAll() applies the same filters with limit/offset added.
        $total = $this->builder->countAllResults(false);

        $this->pager->store($group, $page, $perPage, $total);

        $this->builder->limit($perPage, ($page - 1) * $perPage);

        return $this->findAll();
    }

    public function handleDeleted(): self
    {
        if ($this->useSoftDeletes and $this->excludeDeleted) {
            $this->builder->where($this->table . '.' . $this->deletedField, null);
        }

        return $this;
    }

    public function onlyDeleted(): self
    {
        if ($this->useSoftDeletes) {
            $this->builder->where($this->table . '.' . $this->deletedField . ' IS NOT NULL');
            $this->excludeDeleted = false;
        }

        return $this;
    }

    public function withDeleted(): self
    {
        $this->excludeDeleted = false;

        return $this;
    }

    public function hydrateRow(array $row): EntityInterface
    {
        $entityId = $row[$this->primaryKey] ?? null;

        if ($entityId !== null and $cached = $this->getFromCache($entityId)) {
            return $cached;
        }

        $entity = new $this->class($row, $this->alias);

        if (!empty($entityId)) {
            $this->addToCache($entityId, $entity);
        }

        return $entity;
    }

    /**
     * Get the validation errors from the last failed save.
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Validate the Entity or raw data array against the parsed rules.
     */
    public function validate(EntityInterface|array $data, $skipMissing = false): bool
    {
        if ($this->skipValidation or empty($this->validationRules)) {
            return true;
        }

        $row = $data instanceof EntityInterface ? $data->getAttributes() : $data;

        $rules = $this->validationRules;

        if ($skipMissing) {
            foreach (array_keys($rules) as $field) {
                if (!array_key_exists($field, $row)) {
                    unset($rules[$field]);
                }
            }
        }

        // If no data existed that needs validation, we're good to go.
        if (empty($rules)) {
            return true;
        }

        $this->validator->reset()->setRules($rules);

        if (!$this->validator->run($row)) {
            $this->validationErrors = $this->validator->getErrors();
            return false;
        }

        $this->validationErrors = [];
        return true;
    }

    /**
     * Set the value of the $skipValidation flag.
     */
    public function skipValidation(bool $skip = true): self
    {
        $this->skipValidation = $skip;

        return $this;
    }

    /**
     * Format a Unix timestamp using the model's configured date format.
     * Mirrors the same conversion the entity cast applies in insert() and update().
     */
    protected function formatTimestamp(int $time): int|string
    {
        return match ($this->dateFormat) {
            'datetime' => date('Y-m-d H:i:s', $time),
            'date' => date('Y-m-d', $time),
            default => $time,
        };
    }
}