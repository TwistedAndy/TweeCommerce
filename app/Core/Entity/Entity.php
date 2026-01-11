<?php

namespace App\Core\Entity;

use App\Core\Container\Container;
use CodeIgniter\Exceptions\BadMethodCallException;
use JsonSerializable;

/**
 * A lightweight Entity class to handle simple objects
 */
class Entity implements EntityInterface, JsonSerializable
{
    /**
     * Method keys and names caches
     */
    protected static array $schemas = [];
    protected static array $getters = [];
    protected static array $setters = [];
    protected static array $keys    = [];

    /**
     * Build a schema object and fill the caches
     */
    public static function buildSchema(): EntitySchema
    {
        $class = static::class;

        if (isset(static::$schemas[$class])) {
            return static::$schemas[$class];
        }

        $container = Container::getInstance();

        static::$getters[$class] = [];
        static::$setters[$class] = [];
        static::$schemas[$class] = $container->make(EntitySchema::class, [
            'fields' => $class::getFields(),
        ], static::class);

        $reflection = new \ReflectionClass($class);
        $methods    = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get')) {
                $key                           = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $name));
                static::$getters[$class][$key] = $name;
            } elseif (str_starts_with($name, 'set')) {
                $key                           = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $name));
                static::$setters[$class][$key] = $name;
            }
        }

        return static::$schemas[$class];
    }

    /**
     * Get entity schema fields
     */
    protected static function getFields(): array
    {
        return [
            'id'         => [
                'primary' => true,
                'label'   => 'ID',
                'default' => 0,
                'type'    => 'int',
                'rules'   => 'required|is_natural_no_zero',
            ],
            'title'      => [
                'default' => '',
                'type'    => 'text',
                'rules'   => [
                    'rules'  => 'required|min_length[2]',
                    'errors' => [
                        'required' => 'All entities must have {field} provided',
                    ],
                ],
            ],
            'created_at' => [
                'default' => 0,
                'type'    => 'timestamp',
                'rules'   => 'required',
            ],
            'updated_at' => [
                'default' => null,
                'type'    => '?timestamp',
            ],
            'deleted_at' => [
                'default' => null,
                'type'    => '?timestamp',
            ]
        ];
    }

    /**
     * Current entity attributes in the storage format
     */
    protected array $attributes = [];

    /**
     * Changed attribute values
     */
    protected array $changes = [];

    /**
     * Escaped attribute values in the entity format
     */
    protected array $escaped = [];

    protected bool $customSchema = false;

    protected EntitySchema $schema;

    public function __construct(array $data = [], ?EntitySchema $schema = null)
    {
        $class = static::class;

        if (!isset(static::$schemas[$class])) {
            static::buildSchema();
        }

        if ($schema === null) {
            $this->schema = static::$schemas[$class];
        } else {
            $this->schema = $schema;

            $this->customSchema = true;
        }

        $this->attributes = $data;
    }

    public function __isset(string $field): bool
    {
        return array_key_exists($field, $this->attributes) or array_key_exists($field, $this->schema->defaults) or isset(static::$getters[static::class][$field]);
    }

    public function __get(string $field): mixed
    {
        if (array_key_exists($field, $this->escaped)) {
            return $this->escaped[$field];
        }

        $class = static::class;

        if (isset(static::$getters[$class][$field])) {
            $method = static::$getters[$class][$field];
            $value  = $this->$method();
        } else {
            $value = $this->schema->caster->fromStorage($this->schema, $field, $this->getAttribute($field));
        }

        $this->escaped[$field] = $value;

        return $value;
    }

    public function __set(string $field, $value): void
    {
        $class = static::class;

        if (isset(static::$setters[$class][$field])) {
            $method = static::$setters[$class][$field];
            $this->$method($value);
        } else {
            $this->setAttribute($field, $value);
        }
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (isset(static::$keys[$method])) {
            $key = static::$keys[$method];
        } else {
            $key = strtolower(preg_replace(['/^(?:get|set|has)/', '/(?<!^)([A-Z])/'], ['', '_$1'], $method));

            static::$keys[$method] = $key;
        }

        if (str_starts_with($method, 'get')) {
            return $this->__get($key);
        }

        if (str_starts_with($method, 'set')) {
            $this->__set($key, $arguments[0]);
            return array_key_exists($key, $this->changes);
        }

        if (str_starts_with($method, 'has')) {
            return $this->__isset($key);
        }

        throw new BadMethodCallException(sprintf('Method %s does not exist.', $method));
    }

    public function __serialize(): array
    {
        $data = [
            'attributes' => $this->attributes,
            'changes'    => $this->changes
        ];

        if ($this->customSchema) {
            $data['schema'] = $this->schema;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->attributes = $data['attributes'];
        $this->changes    = $data['changes'];

        if (isset($data['schema'])) {
            $this->customSchema = true;
            $this->schema       = $data['schema'];
        } else {
            $this->customSchema = false;
            $this->schema       = static::buildSchema();
        }
    }

    /**
     * Get entry attributes in the storage format
     */
    public function getAttributes(): array
    {
        return $this->changes ? ($this->changes + $this->attributes) : $this->attributes;
    }

    /**
     * Get an entry attribute in the storage format
     */
    public function getAttribute(string $field): mixed
    {
        if (array_key_exists($field, $this->changes)) {
            return $this->changes[$field];
        }

        if (array_key_exists($field, $this->attributes)) {
            return $this->attributes[$field];
        }

        return $this->schema->defaults[$field] ?? null;
    }

    /**
     * Set entry attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $field => $value) {
            $this->setAttribute($field, $value);
        }
    }

    /**
     * Set an entry attribute
     */
    public function setAttribute(string $field, mixed $value): bool
    {
        $oldValue = $this->getAttribute($field);
        $newValue = $this->schema->caster->toStorage($this->schema, $field, $value);

        if ($oldValue === $newValue) {
            return false;
        }

        unset($this->escaped[$field]);

        if (array_key_exists($field, $this->attributes) and $this->attributes[$field] === $newValue) {
            unset($this->changes[$field]);
        } else {
            $this->changes[$field] = $newValue;
        }

        return true;
    }

    /**
     * Check if an attribute or a whole entity has changed
     */
    public function hasChanged(?string $field = null): bool
    {
        if ($field === null) {
            return count($this->changes) > 0;
        }

        return array_key_exists($field, $this->changes);
    }

    /**
     * Get changed attributes with new values
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Mark all attributes as unchanged after saving
     */
    public function flushChanges(): void
    {
        $this->changes = [];
    }

    /**
     * Get all attributes with original values
     */
    public function getOriginal(): array
    {
        return $this->attributes;
    }

    /**
     * Get Entity Schema Object
     */
    public function getSchema(): EntitySchema
    {
        return $this->schema;
    }

    /**
     * Return current attributes with entities converted to arrays
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array
    {
        if ($onlyChanged) {
            $attributes = $this->getChanges();
        } else {
            $attributes = $this->getAttributes();
        }

        if ($recursive) {
            return array_map(static function ($value) use ($onlyChanged, $recursive) {
                if (is_object($value) and is_callable([$value, 'toRawArray'])) {
                    $value = $value->toRawArray($onlyChanged, $recursive);
                }
                return $value;
            }, $attributes);
        }

        return $attributes;
    }

    /**
     * Allow an object to be serialized with json_encode()
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->getAttributes();
    }

}