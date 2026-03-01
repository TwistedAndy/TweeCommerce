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
    protected static array $identityMap      = [];
    protected static int   $identityMapLimit = 10000;

    protected ?string        $DBGroup;
    protected ?BaseBuilder   $builder = null;
    protected BaseConnection $db;

    protected string         $table;
    protected string         $primaryKey;
    protected string         $entityClass;
    protected string         $entityAlias;
    protected EntityFields   $entityFields;
    protected EntityRegistry $registry;
    protected PagerInterface $pager;

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
    protected array $validationRules      = [];
    protected array $validationErrors     = [];
    protected bool  $cleanValidationRules = true;
    protected bool  $skipValidation       = false;

    /**
     * Configure the maximum number of records held in the Identity Map.
     */
    public static function setIdentityMapLimit(int $limit): void
    {
        static::$identityMapLimit = $limit;
    }

    /**
     * Get an entity from the map and mark it as recently used (LRU).
     */
    protected static function getFromIdentityMap(string $key): ?EntityInterface
    {
        if (isset(static::$identityMap[$key])) {
            $entity = static::$identityMap[$key];

            // Move to the end of the array
            unset(static::$identityMap[$key]);
            static::$identityMap[$key] = $entity;

            return $entity;
        }

        return null;
    }

    /**
     * Add an entity to the map and enforce the LRU limit.
     */
    protected static function addToIdentityMap(string $key, EntityInterface $entity): void
    {
        // If it exists, unset it first so it moves to the end of the array
        if (isset(static::$identityMap[$key])) {
            unset(static::$identityMap[$key]);
        }

        static::$identityMap[$key] = $entity;

        // Enforce the size limit (if limit is greater than 0)
        if (static::$identityMapLimit > 0 && count(static::$identityMap) > static::$identityMapLimit) {
            // array_key_first() is O(1) performance. Drops the oldest, least recently used record.
            $oldestKey = array_key_first(static::$identityMap);
            if ($oldestKey !== null) {
                unset(static::$identityMap[$oldestKey]);
            }
        }
    }

    /**
     * Remove an entity from the map.
     */
    protected static function deleteFromIdentityMap(string $key): void
    {
        unset(static::$identityMap[$key]);
    }

    /**
     * Clean the identity cache
     */
    public static function clearIdentityMap(): void
    {
        static::$identityMap = [];
    }

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

        $this->DBGroup = $registry->getDatabaseGroup($alias);

        $this->db    = \Config\Database::connect($this->DBGroup);
        $this->table = $registry->getEntityTable($alias);

        $this->validator    = $validator;
        $this->primaryKey   = $entityFields->getPrimaryKey();
        $this->createdField = $entityFields->getCreatedKey();
        $this->updatedField = $entityFields->getUpdatedKey();
        $this->deletedField = $entityFields->getDeletedKey();

        $allowedFields   = [];
        $validationRules = [];

        $fieldList  = $entityFields->getFields();
        $primaryKey = $entityFields->getPrimaryKey();

        foreach ($fieldList as $key => $field) {

            if ($key != $primaryKey and !$entityFields->hasRelation($key)) {
                $allowedFields[] = $key;
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
        $cacheKey = $this->entityAlias . '_' . $id;

        if (isset(static::$identityMap[$cacheKey])) {
            return static::getFromIdentityMap($cacheKey);
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

        $exitingEntities = [];

        foreach ($ids as $id) {
            $cacheKey = $this->entityAlias . '_' . $id;

            if (isset(static::$identityMap[$cacheKey])) {
                $exitingEntities[$id] = static::getFromIdentityMap($cacheKey);
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
                    $exitingEntities[$entity->getAttribute($this->primaryKey)] = $entity;
                }
            }

            // Reorder the entities
            foreach ($ids as $id) {
                if (isset($exitingEntities[$id])) {
                    $entities[$id] = $exitingEntities[$id];
                }
            }
        } else {
            $entities = $exitingEntities;
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

        $this->maybeExcludeDeleted($builder);

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

        $this->maybeExcludeDeleted($builder);

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

        if ($this->createdField) {
            $entity->setAttribute($this->createdField, time());
        }

        $data = $entity->getAttributes();

        $data = array_intersect_key($data, $this->entityFields->getFields());

        if (empty($data)) {
            return false;
        }

        $success = $this->builder()->insert($data);

        $this->newQuery();

        if ($success) {
            $insertId = $this->db->insertID();
            $entity->setAttribute($this->primaryKey, $insertId);

            $this->saveRelations($entity);
            $entity->flushChanges();

            static::addToIdentityMap($this->entityAlias . '_' . $insertId, $entity);
            return true;
        }

        return false;
    }

    public function update(int|string $id, EntityInterface $entity): bool
    {
        if (!$entity->hasChanged()) {
            $this->saveRelations($entity);
            return true;
        }

        // For updates, we validate only the changes if cleanRules is active,
        // allowing partial updates to pass validation seamlessly.
        $dataToValidate = $this->cleanValidationRules ? $entity->getChanges() : $entity;

        if (!$this->validate($dataToValidate)) {
            return false;
        }

        if ($this->updatedField) {
            $entity->setAttribute($this->updatedField, time());
        }

        $changes = $entity->getChanges();

        $changes = array_intersect_key($changes, $this->entityFields->getFields());

        if (!empty($changes)) {
            $builder = $this->builder();

            $this->withDeleted();

            $builder->where($this->primaryKey, $id);
            $builder->update($changes);
        }

        $this->newQuery();

        $this->saveRelations($entity);
        $entity->flushChanges();

        static::addToIdentityMap($this->entityAlias . '_' . $id, $entity);
        return true;
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

        $this->runCascadeDelete($ids, $purge or !$this->useSoftDeletes);

        $builder = $this->builder();

        $this->withDeleted();

        $builder->whereIn($this->primaryKey, $ids);

        $result = (!$purge and $this->useSoftDeletes)
            ? $builder->update([$this->deletedField => $this->formatTimestamp(time())])
            : $builder->delete();

        $this->newQuery();

        foreach ($ids as $singleId) {
            static::deleteFromIdentityMap($this->entityAlias . '_' . $singleId);
        }

        return $result;
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

        $this->runCascadeRestore($ids);

        $builder = $this->builder();

        $this->withDeleted();

        $builder->whereIn($this->primaryKey, $ids);
        $result = $builder->update([$this->deletedField => null]);

        $this->newQuery();

        foreach ($ids as $singleId) {
            static::deleteFromIdentityMap($this->entityAlias . '_' . $singleId);
        }

        return $result;
    }

    public function attach(string $pivotTable, string $localKey, string $foreignKey, int|string $localId, array $relatedIds): void
    {
        if (empty($relatedIds)) {
            return;
        }

        $insertData = [];

        // Clean, readable loop instead of array_map with fn()
        foreach ($relatedIds as $id) {
            $insertData[] = [
                $localKey   => $localId,
                $foreignKey => $id
            ];
        }

        // Uses a dedicated builder directly from the DB so we don't pollute the model's primary table builder
        $this->db->table($pivotTable)->ignore(true)->insertBatch($insertData);
    }

    public function detach(string $pivotTable, string $localKey, string $foreignKey, int|string|array $localId, ?array $relatedIds = null): void
    {
        if (empty($localId)) {
            return;
        }

        $query = is_array($localId)
            ? $this->db->table($pivotTable)->whereIn($localKey, $localId)
            : $this->db->table($pivotTable)->where($localKey, $localId);

        if (!is_array($localId) && $relatedIds !== null) {
            if (empty($relatedIds)) {
                return;
            }

            $query->whereIn($foreignKey, $relatedIds);
        }

        $query->delete();
    }

    public function sync(string $pivotTable, string $localKey, string $foreignKey, int|string $localId, array $relatedIds): void
    {
        $currentRecords = $this->db->table($pivotTable)
            ->select($foreignKey)
            ->where($localKey, $localId)
            ->get()->getResultArray();

        $currentIds = array_column($currentRecords, $foreignKey);

        $idsToAttach = array_diff($relatedIds, $currentIds);
        $idsToDetach = array_diff($currentIds, $relatedIds);

        $this->detach($pivotTable, $localKey, $foreignKey, $localId, $idsToDetach);
        $this->attach($pivotTable, $localKey, $foreignKey, $localId, $idsToAttach);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getPager(): PagerInterface
    {
        return $this->pager;
    }

    /**
     * Get records without trashed ones
     */
    public function maybeExcludeDeleted(?BaseBuilder $builder = null): void
    {
        if ($this->useSoftDeletes and $this->excludeDeleted) {
            if ($builder === null) {
                $builder = $this->builder();
            }

            $builder->where($this->table . '.' . $this->deletedField, null);
        }
    }

    public function withDeleted(): void
    {
        $this->excludeDeleted = false;
    }

    public function onlyDeleted(): void
    {
        if ($this->useSoftDeletes) {
            $this->builder()->where($this->table . '.' . $this->deletedField . ' IS NOT NULL');
            $this->excludeDeleted = false;
        }
    }

    public function hydrateRow(array $row): EntityInterface
    {
        $entityId = $row[$this->primaryKey] ?? null;
        $cacheKey = $this->entityAlias . '_' . $entityId;

        if (isset(static::$identityMap[$cacheKey])) {
            return static::getFromIdentityMap($cacheKey);
        }

        $entity = new $this->entityClass($row, $this->entityAlias, $this->entityFields);

        if (!empty($entityId)) {
            static::addToIdentityMap($cacheKey, $entity);
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
        if ($this->skipValidation || empty($this->validationRules)) {
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
            $relation = $this->entityFields->getRelation($relationName);

            if ($relation) {
                $relation->eagerLoad($entities);
            }
        }
    }

    /**
     * Run cascade delete/soft-delete for all relations that have cascade => true.
     */
    protected function runCascadeDelete(array $ids, bool $purge): void
    {
        foreach ($this->entityFields->getRelationKeys() as $key) {
            $relation = $this->entityFields->getRelation($key);

            if ($relation !== null and $relation->isCascade()) {
                $relation->cascadeDelete($ids, $this->entityAlias, $purge);
            }
        }
    }

    /**
     * Run cascade restore for all relations that have cascade => true.
     */
    protected function runCascadeRestore(array $ids): void
    {
        foreach ($this->entityFields->getRelationKeys() as $key) {
            $relation = $this->entityFields->getRelation($key);

            if ($relation !== null and $relation->isCascade()) {
                $relation->cascadeRestore($ids);
            }
        }
    }

    protected function saveRelations(EntityInterface $entity): void
    {
        $relations = $this->entityFields->getRelationKeys();
        $changes   = $entity->getChanges();

        $changedRelations = array_intersect_key($changes, array_flip($relations));

        foreach ($changedRelations as $field => $value) {
            $this->entityFields->getRelation($field)->update($entity, $value);
        }
    }
}