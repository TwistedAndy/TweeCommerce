<?php

namespace App\Core\Entity;

use App\Core\Container\Container;
use App\Core\Libraries\Sanitizer;
use CodeIgniter\DataCaster\Cast\CastInterface;
use DateTimeInterface;

/**
 * Read-only configuration object for an Entity class.
 * Holds cached reflection data, services, and field definitions.
 */
class EntityFields {

    protected Container $container;

    protected Sanitizer $sanitizer;

    protected string $primaryKey = '';

    protected string $createdKey = '';

    protected string $updatedKey = '';

    protected string $deletedKey = '';

    protected array $relationData = [];

    protected array $relations = [];

    protected array $fields = [];

    protected array $defaults = [];

    protected array $nullable = [];

    protected array $casts = [];

    protected array $castParams = [];

    protected array $castHandlers = [];

    protected array $dateFormats = [];

    protected const DATE_FORMATS = [
        'time'        => 'H:i:s',
        'date'        => 'Y-m-d',
        'datetime'    => 'Y-m-d H:i:s',
        'datetime-ms' => 'Y-m-d H:i:s.v',
        'datetime-us' => 'Y-m-d H:i:s.u',
    ];

    /**
     * Create a new EntityFields object
     *
     * @see    https://codeigniter.com/user_guide/models/model.html#custom-casting,
     *      https://codeigniter.com/user_guide/libraries/validation.html#setting-validation-rules
     *
     *  Field Options:
     *  - primary:  Primary Key Flag
     *  - label:    Field Label
     *  - default:  Default Value in the storage format
     *  - type:     Casting Type or Casting Class Name
     *  - rules:    Validation rules and custom error messages
     *  - relation: Relation Configuration
     *
     * @param array<string, array{
     *   primary?:  bool,
     *   label?:    string,
     *   default?:  mixed,
     *   type?:     string,
     *   rules?:    string|array{
     *     rules: string|array<string, string>,
     *     errors?: array<string, string>
     *   },
     *   relation?: array{
     *     type:         string,
     *     related:      string,
     *     local_key?:   string,
     *     foreign_key?: string,
     *   },
     * }>               $fields
     * @param Container $container
     * @param Sanitizer $sanitizer
     */
    public function __construct(array $fields, Container $container, Sanitizer $sanitizer)
    {
        $this->container = $container;
        $this->sanitizer = $sanitizer;

        foreach ($fields as $key => $field) {
            $this->addField($key, $field);
        }

        if (empty($this->primaryKey)) {
            throw new EntityException('No primary key is specified for an entity');
        }

        foreach ($this->fields as $key => $field) {
            if ($field['type'] === 'relation') {
                if (empty($field['relation']) or !is_array($field['relation'])) {
                    throw new EntityException('An entity relation field requires relation data specified');
                }

                $this->addRelation($key, $field['relation']);
            }
        }
    }

    /**
     * Exclude the $relationData property from serialization
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);

        unset($data['relations']);
        unset($data['container']);

        return $data;
    }

    /**
     * Restore the object after serialization.
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }

        $this->relations = [];
        $this->container = Container::getInstance();
    }

    /**
     * Add an entity field
     */
    public function addField(string $key, array $field): void
    {
        if (isset($this->fields[$key])) {
            throw new EntityException("A field '{$key}' is already defined");
        }

        if (empty($field['type'])) {
            $field['type'] = 'text';
        } elseif (!is_string($field['type'])) {
            throw new EntityException('A provided type should be a valid cast string');
        }

        if (!empty($field['primary'])) {
            if ($this->primaryKey) {
                throw new EntityException('There should be only one entity field marked as a primary key');
            }

            $this->primaryKey = $key;
        }

        $cast = $field['type'];

        $isNullable = str_starts_with($cast, '?');

        if ($isNullable) {
            $this->nullable[$key] = true;

            $cast = ltrim($cast, '?');
        }

        if (str_starts_with($cast, '\\')) {
            if (str_contains($cast, '[') and preg_match('/\A(.+)\[(.+)]\z/', $cast, $matches)) {
                $cast = $matches[1];
                $params = array_map('trim', explode(',', $matches[2]));
            } else {
                $params = [];
            }

            if (!class_exists($cast) or !is_subclass_of($cast, CastInterface::class)) {
                throw new EntityException("A provided cast handler '$cast' should implement the CastInterface interface");
            }

            if ($isNullable) {
                $params[] = 'nullable';
            }

            $this->castParams[$key] = $params;
            $this->castHandlers[$key] = $cast;
        }

        if (!empty(self::DATE_FORMATS[$cast])) {
            $this->dateFormats[$key] = self::DATE_FORMATS[$cast];
        }

        $this->casts[$key] = $cast;

        $field['type'] = $cast;

        if (empty($field['label'])) {
            $field['label'] = ucwords(str_replace('_', ' ', $key));
        } else {
            $field['label'] = (string) $field['label'];
        }

        if (!empty($field['subtype'])) {
            $subtype = $field['subtype'];

            if ($subtype === 'created') {
                $this->createdKey = $key;
            } elseif ($subtype === 'updated') {
                $this->updatedKey = $key;
            } elseif ($subtype === 'deleted') {
                $this->deletedKey = $key;
            }
        }

        if (!empty($field['rules'])) {
            if (is_string($field['rules'])) {
                $field['rules'] = [
                    'rules' => $field['rules'],
                ];
            } elseif (is_array($field['rules']) and empty($field['rules']['rules'])) {
                $field['rules'] = [
                    'rules' => $field['rules'],
                ];
            }

            if (!is_array($field['rules']) or empty($field['rules']['rules'])) {
                throw new EntityException("Failed to initialize field rules for '{$key}'");
            }

            if (!empty($field['errors']) and is_array($field['errors'])) {
                $field['rules']['errors'] = $field['errors'];
                unset($field['errors']);
            }
        }

        if (array_key_exists('default', $field)) {
            $this->defaults[$key] = $this->castToStorage($key, $field['default']);
        } else {
            $this->defaults[$key] = $isNullable ? null : $this->getCastDefault($cast);
        }

        $field['default'] = $this->defaults[$key];

        $this->fields[$key] = $field;
    }

    /**
     * Add a new relation
     *
     * @param string $key
     * @param array{
     *   type:         string,
     *   related:      string,
     *   local_key?:   string,
     *   foreign_key?: string,
     *   constraint?:  array{
     *     limit?:    int,
     *     offset?:   int,
     *     where?:    array,
     *     orderby?:  array,
     *     callback?: callable|string|null
     *   },
     * }             $relation
     */
    public function addRelation(string $key, array $relation): void
    {
        if (isset($this->relationData[$key])) {
            throw new EntityException("A relation key '{$key}' is already defined");
        }

        if (empty($relation['related']) or !is_string($relation['related'])) {
            throw new EntityException('A related alias is not specified for the "' . $key . '" relation.');
        }

        if (empty($relation['type'])) {
            throw new EntityException('A relation type is not specified for the "' . $key . '" relation.');
        }

        if (!is_string($relation['type']) or !in_array($relation['type'], ['has-one', 'has-many', 'belongs-one', 'belongs-many'])) {
            throw new EntityException('Unsupported type for the "' . $key . '" relation. Supported values: has-one, has-many, belongs-one, or belongs-many');
        }

        if (empty($relation['local_key']) or !is_string($relation['local_key'])) {
            $relation['local_key'] = $this->primaryKey;
        }

        if (empty($relation['foreign_key']) or !is_string($relation['foreign_key'])) {
            $relation['foreign_key'] = '';
        }

        if (isset($relation['constraint']['callback']) and $relation['constraint']['callback'] instanceof \Closure) {
            throw new EntityException("The condition callback for relation '{$key}' cannot be a Closure. Please use a serializable callable (e.g., string or array).");
        }

        $this->relationData[$key] = [
            'type'              => $relation['type'],
            'related'           => $relation['related'],
            'local_key'         => $relation['local_key'],
            'foreign_key'       => $relation['foreign_key'],
            'pivot_local_key'   => $relation['pivot_local_key'] ?? null,
            'pivot_foreign_key' => $relation['pivot_foreign_key'] ?? null,
            'constraint'        => $relation['constraint'] ?? [],
        ];
    }

    /**
     * Check if a field contains a relation
     */
    public function hasRelation(string $key): bool
    {
        return isset($this->relationData[$key]);
    }

    public function getCreatedKey(): string
    {
        return $this->createdKey;
    }

    public function getUpdatedKey(): string
    {
        return $this->updatedKey;
    }

    public function getDeletedKey(): string
    {
        return $this->deletedKey;
    }

    /**
     * Get a date format for a field
     */
    public function getDateFormat(string $key): string | null
    {
        return $this->dateFormats[$key] ?? null;
    }

    /**
     * Get a relation object
     */
    public function getRelation(string $key): EntityRelation | null
    {
        if (isset($this->relations[$key])) {
            return $this->relations[$key];
        }

        if (!isset($this->relationData[$key])) {
            return null;
        }

        $relation = $this->container->make(EntityRelation::class, [
            'name'     => $key,
            'relation' => $this->relationData[$key]
        ]);

        $this->relations[$key] = $relation;

        return $relation;
    }

    /**
     * Get relation keys
     */
    public function getRelationKeys(): array
    {
        return array_keys($this->relationData);
    }

    /**
     * Get a relation type
     */
    public function getRelationType(string $key): string
    {
        return isset($this->relationData[$key]) ? $this->relationData[$key]['type'] : '';
    }

    /**
     * Get all fields in the normalized format
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get a field array
     */
    public function getField(string $key): array | null
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * Get all field casts
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    /**
     * Get a field cast
     */
    public function getCast(string $key): string
    {
        return $this->casts[$key] ?? '';
    }

    /**
     * Get a primary key name
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get default values for all fields in the storage format
     */
    public function getDefaultValues(): array
    {
        return $this->defaults;
    }

    /**
     * Get a field default value in the storage format
     */
    public function getDefaultValue(string $field): mixed
    {
        if (array_key_exists($field, $this->defaults)) {
            return $this->defaults[$field];
        }

        return null;
    }

    /**
     * Convert a field value in the storage format
     */
    public function castToStorage(string $field, $value)
    {
        if (!isset($this->casts[$field]) or ($value === null and isset($this->nullable[$field]))) {
            return $value;
        }

        $cast = $this->casts[$field];

        switch ($cast) {
            case 'int':
                return (int) $value;
            case 'text':
                return $this->sanitizer->sanitizeText((string) $value);
            case 'text-raw':
                return (string) $value;
            case 'timestamp':
                if (is_int($value) or is_numeric($value)) {
                    return (int) $value;
                }

                if (is_string($value)) {
                    $value = strtotime($value);

                    if ($value === false) {
                        return isset($this->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->getTimestamp();
                }

                return isset($this->nullable[$field]) ? null : 0;
            case 'html':
            case 'html-full':
            case 'html-basic':
                if ($cast === 'html') {
                    return $this->sanitizer->sanitizeHtml((string) $value);
                }

                return $this->sanitizer->sanitizeHtml((string) $value, str_replace('html-', '', $cast));
            case 'key':
                return $this->sanitizer->sanitizeKey((string) $value);
            case 'bool':
                return $value ? 1 : 0;
            case 'float':
                return (float) $value;
            case 'json':
            case 'json-array':
                if (!is_string($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $trimmed = trim($value);

                if ($trimmed === '') {
                    return ($cast === 'json-array') ? '[]' : '{}';
                }

                $first = $trimmed[0];
                $last = substr($trimmed, -1);

                if (($first === '{' and $last === '}') or ($first === '[' and $last === ']') or ($first === '"' and $last === '"')) {
                    json_decode($trimmed);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $trimmed;
                    }
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE);
            case 'array':
                if (!is_string($value) or !$this->isSerialized($value)) {
                    return serialize($value);
                }

                return $value;
            case 'datetime':
            case 'datetime-ms':
            case 'datetime-us':
                if ($value instanceof DateTimeInterface) {
                    return $value->format($this->dateFormats[$field]);
                }

                if (is_numeric($value)) {
                    $timestamp = (int) $value;
                } else {
                    $timestamp = strtotime($value);
                }

                if ($timestamp > 0) {
                    return date($this->dateFormats[$field], $timestamp);
                }

                return isset($this->nullable[$field]) ? null : '';
            case 'uri':
                return $this->sanitizer->sanitizeUri((string) $value);
            default:
                if (isset($this->castHandlers[$field])) {
                    $handler = $this->castHandlers[$field];
                    $value = $handler::set($value, $this->castParams[$field]);
                }
        }

        return $value;
    }

    /**
     * Convert a field value from the storage format
     */
    public function castFromStorage(string $field, $value)
    {
        if (!isset($this->casts[$field]) or ($value === null and isset($this->nullable[$field]))) {
            return $value;
        }

        $cast = $this->casts[$field];

        switch ($cast) {
            case 'int':
            case 'timestamp':
                return (int) $value;
            case 'key':
            case 'text':
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            case 'html':
            case 'html-full':
            case 'html-basic':
            case 'text-raw':
                return (string) $value;
            case 'float':
                return (float) $value;
            case 'array':
                if (is_string($value) and $this->isSerialized($value)) {
                    return unserialize($value, ['allowed_classes' => false]);
                }

                return (array) $value;
            case 'json':
            case 'json-array':
                if (is_string($value)) {
                    $decoded = json_decode($value, $cast === 'json-array');

                    if ($decoded === null and json_last_error() !== JSON_ERROR_NONE) {
                        return $value;
                    }

                    return $decoded;
                }

                if (is_object($value) or is_array($value)) {
                    return $cast === 'json-array' ? (array) $value : (object) $value;
                }

                return $value;
            case 'datetime':
            case 'datetime-ms':
            case 'datetime-us':
                if (is_int($value) or is_numeric($value)) {
                    return (int) $value;
                }

                if (is_string($value)) {
                    $value = strtotime($value);

                    if ($value === false) {
                        return isset($this->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->format($this->dateFormats[$field]);
                }

                return isset($this->nullable[$field]) ? null : 0;
            case 'bool':
                return (bool) $value;
            default:
                if (isset($this->castHandlers[$field])) {
                    $handler = $this->castHandlers[$field];
                    $value = $handler::get($value, $this->castParams[$field]);
                }
        }

        return $value;
    }

    /**
     * Get a default field value in the storage format
     */
    public function getCastDefault(string $cast): string | int
    {
        return match ($cast) {
            'int', 'bool', 'float', 'timestamp' => 0,
            'json', 'json-array' => ($cast === 'json-array') ? '[]' : '{}',
            'array' => 'a:0:{}',
            default => '',
        };
    }

    /**
     * Check if a string is serialized
     */
    protected function isSerialized(mixed $string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        $string = trim($string);

        if ($string === 'N;') {
            return true;
        }

        if (strlen($string) < 4 or $string[1] !== ':') {
            return false;
        }

        $last_letter = substr($string, -1);

        if (';' !== $last_letter and '}' !== $last_letter) {
            return false;
        }

        return str_contains('adObisCE', $string[0]);
    }

}