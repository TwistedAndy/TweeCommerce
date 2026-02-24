<?php

namespace App\Core\Entity;

use App\Core\Container\Container;
use CodeIgniter\Exceptions\BadMethodCallException;
use JsonSerializable;
use IteratorAggregate;
use ArrayAccess;
use Traversable;

/**
 * A lightweight Entity class to handle simple objects
 */
class Entity implements EntityInterface, JsonSerializable, ArrayAccess, IteratorAggregate
{
    /**
     * Entity Field Caches
     */
    protected static array $entityFields  = [];
    protected static array $entityAliases = [];
    protected static array $entityMethods = [];
    protected static array $entityGetters = [];
    protected static array $entitySetters = [];

    /**
     * Initialize entity fields and fill entity caches
     */
    public static function initEntity(?Container $container = null): EntityFields
    {
        $class = static::class;

        if (isset(static::$entityFields[$class])) {
            return static::$entityFields[$class];
        }

        if ($container === null) {
            $container = Container::getInstance();
        }

        $fields = $container->make(EntityFields::class, [
            'fields'    => $class::getEntityFields(),
            'container' => $container,
        ], $class);

        static::$entityFields[$class]  = $fields;
        static::$entityAliases[$class] = $class::getEntityAlias();
        static::$entityGetters[$class] = [];
        static::$entitySetters[$class] = [];

        $reflection = new \ReflectionClass($class);
        $methods    = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $excluded = array_fill_keys([
            'getAttributes',
            'getAttribute',
            'setAttributes',
            'setAttribute',
            'getIterator',
            'getOriginal',
            'getChanges',
            'getFields',
            'getAlias',
        ], true);

        foreach ($methods as $method) {
            $name = $method->getName();

            if (isset($excluded[$name])) {
                continue;
            }

            if (str_starts_with($name, 'get')) {
                $key = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', substr($name, 3)));

                static::$entityGetters[$class][$key] = $name;
            } elseif (str_starts_with($name, 'set')) {
                $key = strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', substr($name, 3)));

                static::$entitySetters[$class][$key] = $name;
            }
        }

        return $fields;
    }

    /**
     * Reset the static entity caches
     */
    public static function resetEntity(): void
    {
        $class = static::class;

        static::$entityFields[$class]  = [];
        static::$entityAliases[$class] = [];
        static::$entityMethods[$class] = [];
        static::$entityGetters[$class] = [];
        static::$entitySetters[$class] = [];
    }

    /**
     * Get entity fields in a raw format
     */
    protected static function getEntityFields(): array
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
     * Get the default entity alias
     */
    protected static function getEntityAlias(): string
    {
        return 'entity';
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

    protected string $alias;

    protected bool $customFields = false;

    /**
     * Current Entity Fields object
     */
    protected EntityFields $fields;

    public function __construct(array $data = [], ?string $alias = null, ?EntityFields $fields = null)
    {
        $class = static::class;

        if (!isset(static::$entityFields[$class])) {
            static::initEntity();
        }

        if ($fields === null) {
            $this->fields = static::$entityFields[$class];
        } else {
            $this->fields = $fields;

            $this->customFields = true;
        }

        if ($alias === null) {
            $this->alias = static::$entityAliases[$class];
        } else {
            $this->alias = $alias;
        }

        $this->attributes = $data;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes) or array_key_exists($name, $this->fields->getFields()) or isset(static::$entityGetters[static::class][$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->changes[$name], $this->escaped[$name]);
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->escaped)) {
            return $this->escaped[$name];
        }

        if ($this->fields->hasRelation($name)) {
            return $this->getAttribute($name);
        }

        $class = static::class;

        if (isset(static::$entityGetters[$class][$name])) {
            $method = static::$entityGetters[$class][$name];
            $value  = $this->$method();
        } else {
            $value = $this->fields->castFromStorage($name, $this->getAttribute($name));
        }

        $this->escaped[$name] = $value;

        return $value;
    }

    public function __set(string $name, mixed $value): void
    {
        $class = static::class;

        if (isset(static::$entitySetters[$class][$name])) {
            $method = static::$entitySetters[$class][$name];
            $this->$method($value);
        } else {
            $this->setAttribute($name, $value);
        }
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (isset(static::$entityMethods[$method])) {
            $field = static::$entityMethods[$method];
        } else {
            $field = strtolower(preg_replace(['/^(?:get|set|has)/', '/(?<!^)([A-Z])/'], ['', '_$1'], $method));

            static::$entityMethods[$method] = $field;
        }

        if (str_starts_with($method, 'get')) {
            return $this->__get($field);
        }

        if (str_starts_with($method, 'set')) {
            $this->__set($field, $arguments[0]);
            return array_key_exists($field, $this->changes);
        }

        if (str_starts_with($method, 'has')) {
            return $this->__isset($field);
        }

        throw new BadMethodCallException(sprintf('Method %s does not exist.', $method));
    }

    public function __serialize(): array
    {
        $data = [
            'attributes' => $this->attributes,
            'changes'    => $this->changes,
            'alias'      => $this->alias,
        ];

        if ($this->customFields) {
            $data['fields'] = $this->fields;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->attributes = $data['attributes'];
        $this->changes    = $data['changes'];
        $this->alias      = $data['alias'];

        if (isset($data['fields'])) {
            $this->customFields = true;
            $this->fields       = $data['fields'];
        } else {
            $this->customFields = false;
            $this->fields       = static::initEntity();
        }
    }

    /**
     * Get entity attributes in the storage format
     */
    public function getAttributes(): array
    {
        return $this->changes ? ($this->changes + $this->attributes) : $this->attributes;
    }

    /**
     * Get an entity attribute in the storage format
     */
    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->changes)) {
            return $this->changes[$key];
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        if ($this->fields->hasRelation($key)) {
            $value = $this->fields->getRelation($key)->get($this);

            $this->attributes[$key] = $value;
            return $value;
        }

        return $this->fields->getDefaultValue($key);
    }

    /**
     * Set entity attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $field => $value) {
            $this->setAttribute($field, $value);
        }
    }

    /**
     * Set an entity attribute
     */
    public function setAttribute(string $field, mixed $value): bool
    {
        if ($this->fields->hasRelation($field)) {
            $value = $this->fields->getRelation($field)->resolve($value);

            $this->attributes[$field] = $value;
            $this->changes[$field]    = $value;

            unset($this->escaped[$field]);

            return true;
        }

        $oldValue = $this->getAttribute($field);
        $newValue = $this->fields->castToStorage($field, $value);

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
        if ($this->changes) {
            $this->attributes = $this->changes + $this->attributes;
            $this->changes    = [];
        }
    }

    /**
     * Get all attributes with original values
     */
    public function getOriginal(): array
    {
        return $this->attributes;
    }

    /**
     * Get EntityFields Object
     */
    public function getFields(): EntityFields
    {
        return $this->fields;
    }

    /**
     * Get Entity Alias
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Return current attributes with entities converted to arrays
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array
    {
        if ($onlyChanged) {
            $attributes = $this->getChanges();
        } else {
            $attributes = $this->getAttributes() + $this->fields->getDefaultValues();
        }

        if ($recursive) {
            // Remove 'static' here
            $map = function ($value) use (&$map, $onlyChanged, $recursive) {
                if (is_object($value) && is_callable([$value, 'toRawArray'])) {
                    return $value->toRawArray($onlyChanged, $recursive);
                }
                if (is_array($value)) {
                    return array_map($map, $value);
                }
                return $value;
            };

            return array_map($map, $attributes);
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
        return iterator_to_array($this);
    }

    /**
     * IteratorAggregate: Allow iterating over the entity properties
     */
    public function getIterator(): Traversable
    {
        $keys = array_keys($this->getAttributes() + $this->fields->getDefaultValues());

        foreach ($keys as $key) {
            yield $key => $this->__get($key);
        }
    }

    /**
     * ArrayAccess: Allow accessing entity propertines like an array
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset($offset);
    }
}