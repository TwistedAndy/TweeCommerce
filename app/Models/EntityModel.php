<?php

namespace App\Models;

use App\Entities\Entity;
use App\Entities\EntityInterface;
use App\Exceptions\ContainerException;
use App\Exceptions\ValidationException;

use CodeIgniter\Model;
use CodeIgniter\DataCaster\DataCaster;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DataException;
use CodeIgniter\Exceptions\InvalidArgumentException;
use CodeIgniter\Validation\ValidationInterface;
use CodeIgniter\I18n\Time;
use ReflectionException;

class EntityModel extends Model
{
    protected static array $entityCache = [];

    protected DataCaster $dataCaster;

    protected $primaryKey = 'id';
    protected $dateFormat = 'int';
    protected $returnType = Entity::class;

    protected $useSoftDeletes = true;
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
        $this->dataCaster = new DataCaster($this->castHandlers, $this->casts, $this->db, false);
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

            $row = $this->convertRowToEntry($data['data']);

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
                    $this->convertRowToEntry($row)
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
        if ($returnType === Entity::class) {
            return new Entity($this->convertRowToEntry($row));
        } elseif ($returnType === 'array') {
            return $this->convertRowToEntry($row);
        } elseif ($returnType === 'object') {
            return (object) $this->convertRowToEntry($row);
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

        $row = $this->convertEntryToRow((array) $row);

        if (!$this->allowEmptyInserts and empty($row)) {
            throw DataException::forEmptyDataset($type);
        }

        return $row;
    }

    /**
     * Convert a database row array to entry data array
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function convertRowToEntry(array $row): array
    {
        if (empty($this->casts)) {
            return $row;
        }

        foreach ($this->casts as $field => $type) {
            if (!isset($row[$field])) {
                continue;
            }

            $type = ltrim($type, '?');

            $value = $row[$field];

            switch ($type) {
                case 'int':
                case 'integer':
                    if (!is_int($value)) {
                        $row[$field] = (int) $value;
                    }
                    break;
                case 'bool':
                case 'boolean':
                case 'int-bool':
                    if (!is_bool($value)) {
                        $row[$field] = (bool) $value;
                    }
                    break;
                case 'float':
                case 'double':
                    if (!is_float($value)) {
                        $row[$field] = (float) $value;
                    }
                    break;
                case 'json':
                    if (is_string($value)) {
                        $row[$field] = json_decode($value, false);
                    } elseif (!is_object($value)) {
                        $row[$field] = (object) $value;
                    }
                    break;
                case 'json-array':
                    if (is_string($value)) {
                        $row[$field] = json_decode($value, true);
                    } elseif (!is_array($value)) {
                        $row[$field] = (array) $value;
                    }
                    break;
                case 'array':
                    if (is_string($value) and (str_starts_with($value, 'a:') or str_starts_with($value, 's:'))) {
                        $row[$field] = unserialize($value, ['allowed_classes' => false]);
                    } elseif (!is_array($value)) {
                        $row[$field] = (array) $value;
                    }
                    break;
                case 'datetime':
                case 'datetime-ms':
                case 'datetime-us':
                case 'timestamp':
                    if (is_string($value) or is_numeric($value)) {
                        try {
                            if (is_numeric($value) and $type === 'timestamp') {
                                $row[$field] = Time::createFromTimestamp(
                                    (int) $value,
                                    date_default_timezone_get()
                                );
                            } else {
                                $row[$field] = Time::createFromFormat(
                                    $this->db->dateFormat[$type],
                                    $value
                                );
                            }
                        } catch (\Exception $e) {
                            $row[$field] = null;
                        }
                    } elseif (!($value instanceof Time)) {
                        $row[$field] = null;
                    }
                    break;
                case 'csv':
                    if (!is_array($value)) {
                        $row[$field] = explode(',', (string) $value);
                    }
                    break;
                default:
                    $row[$field] = $this->dataCaster->castAs($value, $field, 'get');
            }
        }

        return $row;
    }

    /**
     * Convert entry data array to a database row array
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function convertEntryToRow(array $row): array
    {
        if (empty($this->casts)) {
            return $row;
        }

        foreach ($this->casts as $field => $type) {
            if (!isset($row[$field])) {
                continue;
            }

            $type = ltrim($type, '?');
            $value = $row[$field];

            switch ($type) {
                case 'int':
                case 'integer':
                    if (!is_int($value)) {
                        $row[$field] = (int) $value;
                    }
                    break;
                case 'bool':
                case 'boolean':
                case 'int-bool':
                    $row[$field] = $value ? 1 : 0;
                    break;
                case 'float':
                case 'double':
                    if (!is_float($value)) {
                        $row[$field] = (float) $value;
                    }
                    break;
                case 'json':
                case 'json-array':
                    if (is_array($value) or is_object($value)) {
                        $row[$field] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    break;
                case 'array':
                    if (!is_string($value)) {
                        $row[$field] = serialize($value);
                    }
                    break;
                case 'datetime':
                case 'datetime-ms':
                case 'datetime-us':
                    if ($value instanceof Time) {
                        $row[$field] = $value->format($this->db->dateFormat[$type]);
                    } else {
                        if (is_numeric($value)) {
                            $timestamp = (int) $value;
                        } else {
                            $timestamp = strtotime($value);
                        }
                        if ($timestamp > 0) {
                            $row[$field] = date($this->db->dateFormat[$type], $timestamp);
                        } else {
                            $row[$field] = null;
                        }
                    }
                    break;
                case 'timestamp':
                    if ($value instanceof Time) {
                        $row[$field] = $value->getTimestamp();
                    } elseif (is_numeric($value)) {
                        $row[$field] = (int) $value;
                    } elseif (is_string($value)) {
                        $row[$field] = strtotime($value);
                    } else {
                        $row[$field] = null;
                    }
                    break;
                case 'csv':
                    if (is_object($value)) {
                        $value = (array) $value;
                    } elseif (!is_array($value)) {
                        $value = [(string) $value];
                    }
                    $row[$field] = implode(',', $value);
                    break;
                default:
                    $row[$field] = $this->dataCaster->castAs($value, $field, 'set');
            }
        }

        return $row;
    }

}
