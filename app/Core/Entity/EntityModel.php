<?php

namespace App\Core\Entity;

use App\Core\Entity\Traits\ModelCache;
use App\Core\Entity\Traits\ModelRelations;
use App\Core\Entity\Traits\QueryBuilder;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Pager\PagerInterface;
use CodeIgniter\Validation\ValidationInterface;


/**
 * Entity Model
 */
class EntityModel
{
    use ModelCache;
    use ModelRelations;
    use QueryBuilder;

    public readonly PagerInterface $pager;

    protected ?string        $DBGroup;
    protected BaseBuilder    $builder;
    protected BaseConnection $db;

    protected string         $table;
    protected string         $primaryKey;
    protected string         $class;
    protected string         $alias;
    protected EntityFields   $fields;
    protected EntityRegistry $registry;

    protected string $dateFormat   = 'U';
    protected string $deletedField = 'deleted_at';
    protected string $createdField = 'created_at';
    protected string $updatedField = 'updated_at';

    protected bool $useSoftDeletes = false;
    protected bool $excludeDeleted = true;
    protected bool $useTimestamps  = false;

    protected ValidationInterface $validator;

    protected array $relations        = [];
    protected array $allowedFields    = [];
    protected array $validationRules  = [];
    protected array $validationErrors = [];
    protected bool  $skipValidation   = false;

    public function __construct(string $alias, EntityRegistry $registry, ValidationInterface $validator, PagerInterface $pager)
    {
        $fields = $registry->getEntityFields($alias);

        if ($fields === null) {
            throw EntityException::unknownAlias($alias);
        }

        $this->alias    = $alias;
        $this->class    = $registry->getEntityClass($alias);
        $this->registry = $registry;
        $this->fields   = $fields;
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

        $allowedFields = [];

        $primaryKey = $fields->getPrimaryKey();
        $relations  = $fields->getRelations();

        foreach ($fields->getFields() as $key => $field) {
            if ($key !== $primaryKey and !isset($relations[$key])) {
                $allowedFields[$key] = true;
            }
        }

        $this->useTimestamps  = ($this->createdField or $this->updatedField);
        $this->useSoftDeletes = (bool) $this->deletedField;

        $this->allowedFields   = $allowedFields;
        $this->validationRules = $fields->getRules($this->table);
        $this->relations       = $fields->getRelations();

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

        $this->initCache($this->alias);

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
        // Skip cache when relations or aggregates are queued — they must be applied to a fresh load
        if (!$this->hasWith()) {
            $entity = $this->getFromCache($id);

            if ($entity !== null) {
                return $entity;
            }
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
            $this->loadAggregates($entities);
        } else {
            $entities = [];
            $this->loadAggregates([]);
        }

        $this->reset();

        return $entities;
    }

    public function first(): ?EntityInterface
    {
        $this->handleDeleted();

        $row = $this->builder->get()->getRowArray();

        if (empty($row)) {
            $this->loadAggregates([]);
            $this->reset();
            return null;
        }

        $entity = $this->hydrateRow($row);

        $this->loadAggregates([$entity]);

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

        $entityAttrs    = $entity->getAttributes();
        $entityChanges  = $entity->getChanges();
        $useTransaction = false;

        foreach (array_keys($this->relations) as $key) {
            if (array_key_exists($key, $entityChanges) or !empty($entityAttrs[$key])) {
                $useTransaction = true;
                break;
            }
        }

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
            throw EntityException::operationFailed('insert', $e);
        }
    }

    public function update(int|string $id, EntityInterface $entity): bool
    {
        if (!$entity->hasChanged()) {
            return true;
        }

        if (!$this->validate($entity, true)) {
            return false;
        }

        $entityAttrs    = $entity->getAttributes();
        $entityChanges  = $entity->getChanges();
        $useTransaction = false;

        foreach (array_keys($this->relations) as $key) {
            if (array_key_exists($key, $entityChanges) or !empty($entityAttrs[$key])) {
                $useTransaction = true;
                break;
            }
        }

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
            throw EntityException::operationFailed('update', $e);
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

        $useTransaction = (bool) $this->getRelations();

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
            throw EntityException::operationFailed('delete', $e);
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

        $useTransaction = (bool) $this->getRelations();

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

            throw EntityException::operationFailed('restore', $e);
        }
    }

    /**
     * Explicitly discard any pending builder conditions and start a clean query.
     */
    public function reset(): self
    {
        $this->builder->resetQuery();
        $this->excludeDeleted  = true;
        $this->joinedRelations = [];
        $this->withRelations   = [];
        $this->withAggregates  = [];

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

        // Apply soft-delete filter first so the COUNT matches the subsequent findAll().
        // Set excludeDeleted = false afterwards so findAll() does not re-apply the clause.
        $this->handleDeleted();
        $this->excludeDeleted = false;

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
    public function validate(EntityInterface|array $data, bool $skipMissing = false): bool
    {
        if ($this->skipValidation or empty($this->validationRules)) {
            return true;
        }

        // Get the full attribute set for context (handles matches[] and is_unique[] placeholders)
        $row = $data instanceof EntityInterface ? $data->getAttributes() : $data;

        $rules = $this->validationRules;

        if ($skipMissing) {
            // For updates, we only care about rules for fields present in the input
            $input = $data instanceof EntityInterface ? $data->getChanges() : $data;

            foreach (array_keys($rules) as $key) {
                if (!array_key_exists($key, $input)) {
                    unset($rules[$key]);
                }
            }
        }

        // If no rules remain after filtering, validation is technically successful
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
     * Get the insert ID of the last query executed.
     */
    public function getInsertID(): int|string
    {
        return $this->db->insertID();
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