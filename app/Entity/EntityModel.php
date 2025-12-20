<?php

namespace App\Entity;

use App\Exceptions\ContainerException;
use App\Exceptions\ValidationException;
use CodeIgniter\Exceptions\InvalidArgumentException;
use CodeIgniter\Validation\ValidationInterface;
use CodeIgniter\Database\Exceptions\DataException;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use ReflectionException;

class EntityModel extends Model
{
    protected static array $entityCache = [];

    protected EntityCaster $dataCaster;

    protected $primaryKey = 'id';
    protected $dateFormat = 'int';
    protected $returnType = Entity::class;

    protected $useSoftDeletes = false;
    protected $protectFields = false;

    // Dates
    protected $useTimestamps = true;

    // Callbacks
    protected $beforeFind = ['beforeFindHandler'];
    protected $afterFind = ['afterFindHandler'];
    protected $afterInsert = ['updateCacheHandler'];
    protected $afterUpdate = ['updateCacheHandler'];
    protected $afterUpdateBatch = ['updateCacheHandler'];
    protected $afterDelete = ['deleteCacheHandler'];


    public function __construct(ConnectionInterface $db = null, ValidationInterface $validation = null)
    {
        $entity = $this->returnType;

        if (!is_subclass_of($entity, EntityInterface::class)) {
            throw new \RuntimeException("{$entity} must implement EntityInterface.");
        }

        $this->table = $entity::getEntityKey() . 's';
        $this->casts = $entity::getEntityCasts();
        $this->primaryKey = $entity::getEntityKey();

        $this->allowedFields = array_keys($entity::getEntityDefaults());
        $this->validationRules = $entity::getEntityRules();
        $this->validationMessages = $entity::getEntityMessages();

        $this->dataCaster = new EntityCaster(
            $this->casts,
            $entity::getEntityCastHandlers(),
            $db ?? \Config\Database::connect(),
        );

        parent::__construct($db, $validation);
    }

    /**
     * Returns the id value for the data array or object
     *
     * @param array|object $row
     *
     * @return int|string|null
     */
    public function getIdValue($row): int|string|null
    {
        if ($row instanceof EntityInterface) {
            $attributes = $row->getAttributes();
            $id = $attributes[$this->primaryKey] ?? null;
        } elseif (is_array($row)) {
            $id = $row[$this->primaryKey] ?? null;
        } elseif (is_object($row) and isset($row->{$this->primaryKey})) {
            $id = $row->{$this->primaryKey};
        } else {
            $id = parent::getIdValue($row);
        }

        return $id;
    }

    /**
     * @param $row
     *
     * @return bool
     * @throws ValidationException
     */
    public function save($row): bool
    {
        $this->validateData($row);

        try {
            return parent::save($row);
        } catch (ReflectionException $exception) {
            throw new ContainerException("Failed to reflect on callback: " . $exception->getMessage());
        }
    }

    /**
     * Throw an exception on validation fail
     */
    public function validateData($data): bool
    {
        if (!$this->validate($data)) {
            throw new ValidationException($this->errors());
        }
        return true;
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
            if (is_numeric($id) or is_string($id)) {
                if (isset(static::$entityCache[$id])) {
                    $records[$id] = static::$entityCache[$id];
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
                static::$entityCache[$id] = $row;
            }
        } elseif ($first instanceof EntityInterface) {
            foreach ($rows as $id => $row) {
                static::$entityCache[$id] = $row->getAttributes();
            }
        } elseif ($first instanceof \CodeIgniter\Entity\Entity) {
            foreach ($rows as $id => $row) {
                static::$entityCache[$id] = $row->toRawArray(false, false);
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

            $row = $this->dataCaster->fromDataSource($data['data']);

            /**
             * Process the regular inserts
             */
            if (is_string($data['id']) or is_numeric($data['id'])) {
                $row[$this->primaryKey] = $data['id'];
                static::$entityCache[$data['id']] = $row;
                return $data;
            }

            if (is_array($data['id'])) {
                foreach ($data['id'] as $id) {
                    if (isset(static::$entityCache[$id])) {
                        static::$entityCache[$id] = array_merge(static::$entityCache[$id], $row);
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

            $id = $row[$this->primaryKey];

            if (isset(static::$entityCache[$id])) {
                static::$entityCache[$id] = array_merge(
                    static::$entityCache[$id],
                    $this->dataCaster->fromDataSource($row)
                );
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
                unset(static::$entityCache[$id]);
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
            return new $returnType($this->dataCaster->fromDataSource($row));
        } elseif ($returnType === 'array') {
            return $this->dataCaster->fromDataSource($row);
        } elseif ($returnType === 'object') {
            return (object) $this->dataCaster->fromDataSource($row);
        } else {
            return parent::convertToReturnType($row, $returnType);
        }
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
            $row = $this->objectToArray($row, $onlyChanged, false);
        }

        $row = $this->dataCaster->toDataSource((array) $row);

        if (!$this->allowEmptyInserts and empty($row)) {
            throw DataException::forEmptyDataset($type);
        }

        return $row;
    }

}
