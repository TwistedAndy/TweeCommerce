<?php

namespace App\Core\Entity;

use App\Core\Container\Container;
use CodeIgniter\Model;
use CodeIgniter\Database\Exceptions\DataException;
use CodeIgniter\Exceptions\InvalidArgumentException;

use ReflectionException;

class EntityModel extends Model
{
    protected static array $entityCache = [];

    protected $primaryKey = 'id';
    protected $dateFormat = 'int';
    protected $returnType = Entity::class;

    protected string        $entityAlias = '';
    protected ?EntityFields $entityFields;

    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $protectFields  = false;

    protected bool $isLocked = false;

    // Callbacks
    protected $beforeFind       = ['beforeFindHandler'];
    protected $afterFind        = ['afterFindHandler'];
    protected $afterInsert      = ['updateCacheHandler', 'saveRelationsHandler'];
    protected $afterUpdate      = ['updateCacheHandler', 'saveRelationsHandler'];
    protected $afterUpdateBatch = ['updateCacheHandler', 'saveRelationsHandler'];
    protected $afterDelete      = ['deleteCacheHandler', 'deleteRelationsHandler'];


    // In EntityModel.php
    public function insert($row = null, bool $returnID = true): int|string|bool
    {
        $result = parent::insert($row, $returnID);

        if ($result !== false and $row instanceof EntityInterface) {
            // If this is an insert, inject the new DB ID back into the Entity
            if ($returnID and !is_bool($result)) {
                $row->setAttribute($this->primaryKey, $result);
            }

            // Save relations manually using the original object
            $this->saveRelations($row);
            $row->flushChanges();
        }

        return $result;
    }

    public function update($id = null, $row = null): bool
    {
        $result = parent::update($id, $row);

        if ($result !== false and $row instanceof EntityInterface) {
            $this->saveRelations($row);
            $row->flushChanges();
        }

        return $result;
    }

    protected function saveRelations(EntityInterface $entity): void
    {
        $relations = $entity->getFields()->getRelationKeys();
        $changes   = $entity->getChanges();

        $changes = array_intersect_key($changes, array_flip($relations));

        foreach ($changes as $field => $value) {
            $relation = $entity->getFields()->getRelation($field);
            if ($relation instanceof EntityRelation) {
                $relation->update($entity, $value);
            }
        }
    }

    /**
     * Get an entity by the primary key
     */
    public function findOne(int|string $id): EntityInterface|false
    {
        $row = $this->find($id);

        if (empty($row)) {
            return false;
        }

        if ($row instanceof EntityInterface) {
            return $row;
        }

        return new $this->returnType($row, $this->entityAlias);
    }

    /**
     * Get an array of entities by the primary key
     *
     * @param int[]|string[] $ids
     *
     * @return EntityInterface[]
     */
    public function findMany(array $ids): array
    {
        $rows = $this->find($ids);

        if (empty($rows)) {
            return [];
        }

        $entities = [];

        foreach ($rows as $row) {
            $entities[] = new $this->returnType($row, $this->entityAlias);
        }

        return $entities;
    }

    /**
     * Find entities by field value
     *
     * @param string $field
     * @param int|string|array $value
     *
     * @return Entity[]
     */
    public function findByField(string $field, int|string|array $value): array
    {
        $builder = $this->builder();

        if (is_array($value)) {
            $query = $builder->whereIn($field, $value);
        } else {
            $query = $builder->where($field, $value);
        }

        if ($this->useSoftDeletes) {
            $builder->where($this->table . '.' . $this->deletedField, null);
        }

        $rows = $query->get()->getResultArray();

        if (empty($rows)) {
            return [];
        }

        $entities = [];

        foreach ($rows as $row) {
            $entities[] = new $this->returnType($row, $this->entityAlias);
        }

        return $entities;
    }

    /**
     * Returns the id value for the data array or object
     */
    public function getIdValue(mixed $row): int|string|null
    {
        if ($row instanceof EntityInterface) {
            return $row->getAttribute($this->primaryKey);
        }

        if (is_array($row)) {
            return $row[$this->primaryKey] ?? null;
        }

        if (is_object($row) and isset($row->{$this->primaryKey})) {
            return $row->{$this->primaryKey};
        }

        return null;
    }

    /**
     * Configure a model once
     */
    public function configure(array $config): void
    {
        if ($this->isLocked) {
            throw new EntityException('It is not possible to re-configure the locked model.');
        }

        foreach ($config as $field => $value) {
            if (property_exists($this, $field)) {
                $this->{$field} = $value;
            }
        }

        $this->isLocked = true;
    }

    /**
     * Handle the relations saving
     */
    protected function saveRelationsHandler(array $data): array
    {
        if (empty($data['data']) or !($data['data'] instanceof EntityInterface)) {
            return $data;
        }

        $entity  = $data['data'];
        $changes = $entity->getChanges();

        foreach ($changes as $field => $value) {
            if ($entity->getFields()->hasRelation($field)) {
                $relation = $entity->getFields()->getRelation($field);
                $relation->update($entity, $value);
            }
        }

        return $data;
    }

    /*
     * Handle the relations removal
     */
    protected function deleteRelationsHandler(array $data): array
    {
        if (empty($data['id']) or empty($data['purge']) or empty($this->entityAlias) or empty($this->entityFields)) {
            return $data;
        }

        $entityClass = $this->returnType;

        if (!is_subclass_of($entityClass, EntityInterface::class)) {
            return $data;
        }

        $pivotRelations = [];

        $relationKeys = $this->entityFields->getRelationKeys();

        foreach ($relationKeys as $key => $field) {
            if ($this->entityFields->getRelationType($key) === 'belongs-many') {
                $pivotRelations[] = $this->entityFields->getRelation($key);
            }
        }

        if (empty($pivotRelations)) {
            return $data;
        }

        // Wipe the pivot records for each deleted ID
        foreach ($data['id'] as $id) {
            foreach ($pivotRelations as $relation) {
                try {
                    $relation->remove($id, $this->entityAlias);
                } catch (EntityException $e) {
                    continue;
                }
            }
        }

        return $data;
    }

    /**
     * Prefill the data from entity cache on beforeFind event
     *
     * @param array $data
     *
     * @return array
     */
    protected function beforeFindHandler(array $data): array
    {
        if (empty($data['id'])) {
            return $data;
        }

        if (is_array($data['id'])) {
            $ids = $data['id'];
        } else {
            $ids = [$data['id']];
        }

        $records = [];

        foreach ($ids as $id) {
            if (is_int($id) or is_string($id)) {
                $key = $this->table . '_' . $id;
                if (isset(static::$entityCache[$key])) {
                    $records[$id] = static::$entityCache[$key];
                } else {
                    return $data;
                }
            }
        }

        if (empty($records)) {
            return $data;
        }

        $data['returnData'] = true;

        if ($this->tempReturnType === 'array') {
            $data['data'] = $records;
        } else {
            foreach ($records as $key => $row) {
                $data['data'][$key] = $this->convertToReturnType($row, $this->tempReturnType);
            }
        }

        return $data;
    }

    /**
     * Fill the entry cache with fresh data
     *
     * @param array $data
     *
     * @return array
     */
    protected function afterFindHandler(array $data): array
    {
        if (empty($data['data']) or !is_array($data['data'])) {
            return $data;
        }

        if (empty($data['singleton'])) {
            $rows = $data['data'];
        } elseif (isset($data['data'][$this->primaryKey])) {
            $rows = [
                $data['data'][$this->primaryKey] => $data['data']
            ];
        } else {
            return $data;
        }

        $first = reset($rows);

        if (is_array($first) and !empty($first[$this->primaryKey])) {
            foreach ($rows as $id => $row) {
                static::$entityCache[$this->table . '_' . $id] = $row;
            }
        } elseif ($first instanceof EntityInterface) {
            foreach ($rows as $id => $row) {
                static::$entityCache[$this->table . '_' . $id] = $row->getAttributes();
            }
        } elseif ($first instanceof \CodeIgniter\Entity\Entity) {
            foreach ($rows as $id => $row) {
                static::$entityCache[$this->table . '_' . $id] = $row->toRawArray(false, false);
            }
        }

        return $data;
    }

    /**
     * Update the entry cache on adding or updating an entry
     *
     * @param array $data
     *
     * @return array
     */
    protected function updateCacheHandler(array $data): array
    {
        if (empty($data['result']) or empty($data['data']) or !is_array($data['data'])) {
            return $data;
        }

        /**
         * Singular inserts include the ID property as a string or as a number,
         * singular updates include the array of IDs
         */
        if (!empty($data['id'])) {

            $row = $data['data'];

            /**
             * Process the regular inserts
             */
            if (is_int($data['id']) or is_string($data['id'])) {
                $row[$this->primaryKey] = $data['id'];

                static::$entityCache[$this->table . '_' . $data['id']] = $row;
                return $data;
            }

            if (is_array($data['id'])) {
                foreach ($data['id'] as $id) {
                    $key = $this->table . '_' . $id;
                    if (isset(static::$entityCache[$key])) {
                        static::$entityCache[$key] = array_merge(static::$entityCache[$key], $row);
                    }
                }
            }

            return $data;
        }

        /**
         * Process batch updates. The Entry ID is a key of the array
         */
        foreach ($data['data'] as $row) {

            if (empty($row[$this->primaryKey])) {
                continue;
            }

            $id  = $row[$this->primaryKey];
            $key = $this->table . '_' . $id;

            if (isset(static::$entityCache[$key])) {
                static::$entityCache[$key] = array_merge(static::$entityCache[$key], $row);
            }
        }

        return $data;
    }

    /**
     * Delete cached entries from cache
     *
     * @param array $data
     *
     * @return array
     */
    protected function deleteCacheHandler(array $data): array
    {
        if (!empty($data['id']) and is_array($data['id'])) {
            foreach ($data['id'] as $id) {
                unset(static::$entityCache[$this->table . '_' . $id]);
            }
        }

        return $data;
    }

    /**
     * Convert a database row to an array or object
     *
     * @param array<string, mixed> $row Raw data from database
     * @param 'array'|'object'|class-string $returnType
     *
     * @return array|object
     */
    protected function convertToReturnType(array $row, string $returnType): array|object
    {
        if ($returnType === $this->returnType) {
            return new $returnType($row, $this->entityAlias);
        }

        if ($returnType === 'array') {
            return $row;
        }

        if ($returnType === 'object') {
            return (object) $row;
        }

        return parent::convertToReturnType($row, $returnType);
    }

    /**
     * Convert an array or an object to a database row
     *
     * @param array|object $row
     * @param 'insert'|'update' $type
     *
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     * @throws DataException
     */
    protected function transformDataToArray($row, string $type): array
    {
        if (!in_array($type, ['insert', 'update'], true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid type "%s" used upon transforming data to array.', $type)
            );
        }

        if (!$this->allowEmptyInserts and empty($row)) {
            throw DataException::forEmptyDataset($type);
        }

        if ($this->skipValidation === false and $this->cleanValidationRules === false) {
            $onlyChanged = false;
        } else {
            $onlyChanged = ($type === 'update' and $this->updateOnlyChanged);
        }

        if (is_object($row) and method_exists($row, 'toRawArray')) {
            $row = $row->toRawArray($onlyChanged, false);
        } elseif (is_object($row)) {
            $row = $this->objectToArray($row, $onlyChanged);
        }

        if (!empty($this->allowedFields)) {
            $safeFields   = $this->allowedFields;
            $safeFields[] = $this->primaryKey;
            $row          = array_intersect_key($row, array_flip($safeFields));
        }

        if (!$this->allowEmptyInserts and empty($row)) {
            throw DataException::forEmptyDataset($type);
        }

        return $row;
    }
}