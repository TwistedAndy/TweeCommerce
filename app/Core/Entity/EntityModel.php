<?php

namespace App\Core\Entity;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Pager\PagerInterface;
use BadMethodCallException;
use CodeIgniter\Validation\ValidationInterface;

/**
 * CI4-Native Data Mapper / Repository model.
 * Translates pure Entity objects to and from the database using CI4's native Builder proxying.
 */
class EntityModel
{
    protected static array $identityMap = [];

    protected int $cacheLimit = 10000;

    public readonly PagerInterface $pager;

    protected ?string        $DBGroup;
    protected ?BaseBuilder   $builder = null;
    protected BaseConnection $db;

    protected string         $table;
    protected string         $primaryKey;
    protected string         $entityClass;
    protected string         $entityAlias;
    protected EntityFields   $entityFields;
    protected EntityRegistry $registry;

    protected string $dateFormat   = 'U';
    protected string $deletedField = 'deleted_at';
    protected string $createdField = 'created_at';
    protected string $updatedField = 'updated_at';

    protected bool $useSoftDeletes = false;
    protected bool $excludeDeleted = true;
    protected bool $useTimestamps  = false;

    protected array $withRelations = [];

    protected ValidationInterface $validator;

    protected array $allowedFields        = [];
    protected array $relationFields       = [];
    protected array $validationRules      = [];
    protected array $validationErrors     = [];
    protected bool  $cleanValidationRules = true;
    protected bool  $skipValidation       = false;

    public function __construct(string $alias, EntityRegistry $registry, ValidationInterface $validator, PagerInterface $pager)
    {
        $entityFields = $registry->getEntityFields($alias);

        if ($entityFields === null) {
            throw new EntityException('There is no entity with specified alias: ' . $alias);
        }

        $this->entityAlias  = $alias;
        $this->entityClass  = $registry->getEntityClass($alias);
        $this->entityFields = $entityFields;
        $this->registry     = $registry;
        $this->pager        = $pager;

        if (!isset(static::$identityMap[$this->entityAlias])) {
            static::$identityMap[$this->entityAlias] = [];
        }

        $this->DBGroup = $registry->getDatabaseGroup($alias);

        $this->db    = \Config\Database::connect($this->DBGroup);
        $this->table = $registry->getEntityTable($alias);

        $this->validator    = $validator;
        $this->primaryKey   = $entityFields->getPrimaryKey();
        $this->createdField = $entityFields->getCreatedKey();
        $this->updatedField = $entityFields->getUpdatedKey();
        $this->deletedField = $entityFields->getDeletedKey();

        $allowedFields   = [];
        $relationFields  = [];
        $validationRules = [];

        $fieldList  = $entityFields->getFields();
        $primaryKey = $entityFields->getPrimaryKey();
        $relations  = $entityFields->getRelations();

        foreach ($fieldList as $key => $field) {

            if ($key != $primaryKey) {
                if (isset($relations[$key])) {
                    $relationFields[$key] = true;
                } else {
                    $allowedFields[$key] = true;
                }
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
        $this->relationFields  = $relationFields;
        $this->validationRules = $validationRules;

        if ($this->createdField) {
            $dateFormat = $entityFields->getDateFormat($this->createdField);

            if ($dateFormat) {
                $this->dateFormat = $dateFormat;
            }
        } elseif ($this->updatedField) {
            $dateFormat = $entityFields->getDateFormat($this->updatedField);

            if ($dateFormat) {
                $this->dateFormat = $dateFormat;
            }
        }

    }

    public function __invoke(): BaseBuilder
    {
        return $this->builder();
    }

    /**
     * Proxies missing methods directly to the native CI4 Query Builder.
     */
    public function __call(string $name, array $params)
    {
        $builder = $this->builder();

        if (method_exists($builder, $name)) {
            $result = $builder->{$name}(...$params);

            if ($result instanceof BaseBuilder) {
                return $this;
            }

            $this->builder = null;
            return $result;
        }

        throw new BadMethodCallException("Method {$name} does not exist on EntityModel or BaseBuilder.");
    }

    /**
     * Explicitly discard any pending builder conditions and start a clean query.
     * Use this when reusing a model instance across multiple independent queries.
     */
    public function newQuery(): self
    {
        $this->builder        = null;
        $this->withRelations  = [];
        $this->excludeDeleted = true;
        return $this;
    }

    /**
     * Get the active Query Builder for this model's table.
     */
    public function builder(?string $table = null): BaseBuilder
    {
        $targetTable = $table ?? $this->table;

        if ($this->builder === null or $this->builder->getTable() !== $targetTable) {
            $this->builder = $this->db->table($targetTable);
        }

        return $this->builder;
    }

    /**
     * Queue relations to be loaded to prevent N+1 queries.
     */
    public function with(array|string $relations): self
    {
        $this->withRelations = is_string($relations) ? func_get_args() : $relations;
        return $this;
    }

    public function find(int|string $id): ?EntityInterface
    {
        $entity = $this->getFromCache($id);

        if ($entity !== null) {
            return $entity;
        }

        // Apply condition natively to the builder, then explicitly call the model's first() method
        $this->builder()->where($this->primaryKey, $id);

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

        $builder = $this->builder();

        // Do not cache the relations
        if (!empty($this->withRelations)) {
            // Apply condition natively to the builder, then explicitly call the model's findAll() method
            $builder->whereIn($this->primaryKey, $ids);
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

            $this->builder()->whereIn($this->primaryKey, $missingIds);

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
        $builder = $this->builder();

        $this->handleDeleted($builder);

        $rows = $builder->get()->getResultArray();

        $entities = array_map([$this, 'hydrateRow'], $rows);

        if ($this->withRelations) {
            $this->loadRelations($entities);
        }

        $this->newQuery();

        return $entities;
    }

    public function first(): ?EntityInterface
    {
        $builder = $this->builder();

        $this->handleDeleted($builder);

        $row = $builder->get()->getRowArray();

        $this->builder = null;

        if (!$row) {
            $this->withRelations = []; // Clear eager load queue if no result
            return null;
        }

        $entity = $this->hydrateRow($row);

        if ($this->withRelations) {
            $this->loadRelations([$entity]);
        }

        $this->newQuery();

        return $entity;
    }

    public function save(EntityInterface $entity): bool
    {
        $id = $entity->getAttribute($this->primaryKey);
        return (empty($id)) ? $this->insert($entity) : $this->update($id, $entity);
    }

    public function insert(EntityInterface $entity): bool
    {
        // Inserts must validate all rules, so we temporarily enforce strict validation
        $cleanRules = $this->cleanValidationRules;

        $this->cleanValidationRules = false;

        if (!$this->validate($entity)) {
            $this->cleanValidationRules = $cleanRules;
            return false;
        }

        $this->cleanValidationRules = $cleanRules;

        $useTransaction = count($this->entityFields->getRelations()) > 0;

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            // Pre-Save Relations (Transforms relation objects into FKs like author_id)
            if ($this->relationFields) {
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

            $success = $this->builder()->insert($data);
            $this->newQuery();

            if (!$success) {
                if ($useTransaction) {
                    $this->db->transRollback();
                }
                return false;
            }

            $insertId = $this->db->insertID();
            $entity->setAttribute($this->primaryKey, $insertId);

            // Post-Save Relations (has-many, meta, etc.)
            if ($this->relationFields) {
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

        // For updates, we validate only the changes if cleanRules is active,
        // allowing partial updates to pass validation seamlessly.
        $dataToValidate = $this->cleanValidationRules ? $entity->getChanges() : $entity;

        if (!$this->validate($dataToValidate)) {
            return false;
        }

        $useTransaction = count($this->entityFields->getRelations()) > 1;

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            // Pre-Save Relations
            if ($this->relationFields) {
                $this->saveRelations($entity);
            }

            if ($this->updatedField) {
                $entity->setAttribute($this->updatedField, time());
            }

            // Extract changes AFTER pre-save relations have run
            $changes = $entity->getChanges();
            $changes = array_intersect_key($changes, $this->allowedFields);

            if (!empty($changes)) {
                $builder = $this->builder();

                $this->withDeleted();

                $builder->where($this->primaryKey, $id);
                $builder->update($changes);
            }

            $this->newQuery();

            // Post-Save Relations
            if ($this->relationFields) {
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

        $useTransaction = count($this->relationFields) > 0;

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            $this->runCascadeDelete($ids, $purge or !$this->useSoftDeletes);

            $builder = $this->builder();

            $this->withDeleted();

            $builder->whereIn($this->primaryKey, $ids);

            $result = (!$purge and $this->useSoftDeletes)
                ? $builder->update([$this->deletedField => $this->formatTimestamp(time())])
                : $builder->delete();

            $this->newQuery();

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

        $useTransaction = count($this->relationFields) > 0;

        if ($useTransaction) {
            $this->db->transBegin();
        }

        try {
            $this->runCascadeRestore($ids);

            $builder = $this->builder();

            $this->withDeleted();

            $builder->whereIn($this->primaryKey, $ids);
            $result = $builder->update([$this->deletedField => null]);

            $this->newQuery();

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
        $total = $this->builder()->countAllResults(false);

        $this->pager->store($group, $page, $perPage, $total);

        $this->builder()->limit($perPage, ($page - 1) * $perPage);

        return $this->findAll();
    }

    public function onlyDeleted(): void
    {
        if ($this->useSoftDeletes) {
            $this->builder()->where($this->table . '.' . $this->deletedField . ' IS NOT NULL');
            $this->excludeDeleted = false;
        }
    }

    public function withDeleted(): void
    {
        $this->excludeDeleted = false;
    }

    public function handleDeleted(?BaseBuilder $builder = null): void
    {
        if ($this->useSoftDeletes and $this->excludeDeleted) {
            if ($builder === null) {
                $builder = $this->builder();
            }

            $builder->where($this->table . '.' . $this->deletedField, null);
        }
    }

    public function hydrateRow(array $row): EntityInterface
    {
        $entityId = $row[$this->primaryKey] ?? null;

        if ($entityId !== null and $cached = $this->getFromCache($entityId)) {
            return $cached;
        }

        $entity = new $this->entityClass($row, $this->entityAlias, $this->entityFields);

        if (!empty($entityId)) {
            $this->addToCache($entityId, $entity);
        }

        return $entity;
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
     * Should validation rules be removed for fields that aren't present?
     */
    public function cleanRules(bool $choice = true): self
    {
        $this->cleanValidationRules = $choice;
        return $this;
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
    public function validate(EntityInterface|array $data): bool
    {
        if ($this->skipValidation or empty($this->validationRules)) {
            return true;
        }

        $row = $data instanceof EntityInterface ? $data->getAttributes() : $data;

        $rules = $this->cleanValidationRules
            ? $this->cleanValidationRules($this->validationRules, $row)
            : $this->validationRules;

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
     * Get an entity from the map, or all entities if no ID is provided.
     * Touches the entity to mark it as recently used (LRU).
     */
    public function getFromCache(int|string|null $id = null): EntityInterface|array|null
    {
        if ($id === null) {
            return static::$identityMap[$this->entityAlias];
        }

        if (isset(static::$identityMap[$this->entityAlias][$id])) {
            $entity = static::$identityMap[$this->entityAlias][$id];

            // Move to the end of the array (LRU)
            unset(static::$identityMap[$this->entityAlias][$id]);
            static::$identityMap[$this->entityAlias][$id] = $entity;

            return $entity;
        }

        return null;
    }

    /**
     * Add an entity to the map and enforce the LRU limit per alias.
     */
    public function addToCache(int|string $id, EntityInterface $entity): void
    {
        if (isset(static::$identityMap[$this->entityAlias][$id])) {
            unset(static::$identityMap[$this->entityAlias][$id]);
        }

        static::$identityMap[$this->entityAlias][$id] = $entity;

        if ($this->cacheLimit > 0 and count(static::$identityMap[$this->entityAlias]) > $this->cacheLimit) {
            $oldestKey = array_key_first(static::$identityMap[$this->entityAlias]);
            if ($oldestKey !== null) {
                unset(static::$identityMap[$this->entityAlias][$oldestKey]);
            }
        }
    }

    /**
     * Safely remove one or more entity IDs from this model's cache,
     * or clear the entire cache for this entity type if no ID is provided.
     */
    public function removeFromCache(int|string|array|null $ids = null): void
    {
        if ($ids === null) {
            static::$identityMap[$this->entityAlias] = [];
            return;
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        foreach ($ids as $id) {
            unset(static::$identityMap[$this->entityAlias][$id]);
        }
    }

    /**
     * Removes any rules that apply to fields that have not been set
     * so that rules don't block updating when doing a partial update.
     */
    protected function cleanValidationRules(array $rules, array $row): array
    {
        if (empty($row)) {
            return [];
        }

        foreach (array_keys($rules) as $field) {
            if (!array_key_exists($field, $row)) {
                unset($rules[$field]);
            }
        }

        return $rules;
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

    protected function loadRelations(array $entities): void
    {
        $relations = $this->withRelations;

        $this->withRelations = [];

        if (empty($relations) or empty($entities)) {
            return;
        }

        foreach ($relations as $relationName) {
            if (!empty($this->relationFields[$relationName])) {
                $this->entityFields->getRelation($relationName)->eagerLoad($entities);
            }
        }
    }

    /**
     * Run cascade delete/soft-delete for all relations that have cascade => true.
     */
    protected function runCascadeDelete(array $ids, bool $purge): void
    {
        foreach ($this->relationFields as $key => $flag) {
            $relation = $this->entityFields->getRelation($key);

            if ($relation->isCascade()) {
                $relation->cascadeDelete($ids, $this->entityAlias, $purge);
            }
        }
    }

    /**
     * Run cascade restore for all relations that have cascade => true.
     */
    protected function runCascadeRestore(array $ids): void
    {
        foreach ($this->relationFields as $key => $flag) {
            $relation = $this->entityFields->getRelation($key);

            if ($relation->isCascade()) {
                $relation->cascadeRestore($ids);
            }
        }
    }

    /**
     * Save the entity relations
     */
    protected function saveRelations(EntityInterface $entity): void
    {
        $changes    = $entity->getChanges();
        $attributes = $entity->getAttributes();

        foreach ($this->relationFields as $field => $flag) {

            // Process immediately explicit assignments
            if (array_key_exists($field, $changes)) {
                $this->entityFields->getRelation($field)->update($entity, $changes[$field]);
                continue;
            }

            // Skip deep scan if a relation was never loaded
            if (empty($attributes[$field])) {
                continue;
            }

            // Deep Scanning: Check the currently loaded attribute
            $value = $attributes[$field];

            $hasDeepChanges = false;

            if ($value instanceof EntityInterface) {
                $hasDeepChanges = $value->hasChanged();
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof EntityInterface && $item->hasChanged()) {
                        $hasDeepChanges = true;
                        break;
                    }
                }
            }

            // If any loaded entity has changed, push it into the update cycle
            if ($hasDeepChanges) {
                $this->entityFields->getRelation($field)->update($entity, $value);
            }
        }
    }
}