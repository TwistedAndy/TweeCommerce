<?php

namespace App\Core\Entity;

use App\Libraries\Escaper;
use CodeIgniter\Exceptions\BadMethodCallException;
use JsonSerializable;

/**
 * A lightweight Entity class to handle simple objects
 */
class Entity implements EntityInterface, JsonSerializable
{
    const STATUS_DRAFT = 0;
    const STATUS_PENDING = 1;
    const STATUS_PUBLISHED = 2;
    const STATUS_ARCHIVED = 3;
    const STATUS_DELETED = 4;

    const TYPE_NONE = 0;
    const TYPE_PAGE = 1;
    const TYPE_POST = 2;

    /**
     * Partitioned storage for class-specific data
     * Keys are the FQCN via static::class
     */
    protected static array $entityCasters = [];
    protected static array $resolvedTypes = [];
    protected static array $resolvedMethods = [];
    protected static array $resolvedStatuses = [];

    /**
     * Get the primary key attribute
     *
     * @see https://codeigniter.com/user_guide/models/model.html#primarykey
     */
    public static function getEntityKey(): string
    {
        return 'id';
    }

    /**
     * Get the entity alias to be used as a base for database table names
     */
    public static function getEntityAlias(): string
    {
        return 'post';
    }

    /**
     * Get default values for all entity attribures
     */
    public static function getEntityDefaults(): array
    {
        return [
            'id'         => 0,
            'type'       => static::TYPE_NONE,
            'status'     => static::STATUS_DRAFT,
            'title'      => '',
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * Get validation rules for the entity
     *
     * @see https://codeigniter.com/user_guide/libraries/validation.html#validation-available-rules
     */
    public static function getEntityRules(): array
    {
        return [
            'id'    => 'required|is_natural_no_zero',
            'title' => 'required',
        ];
    }

    /**
     * Get validation messages for the entity
     *
     * @see https://codeigniter.com/user_guide/libraries/validation.html#setting-custom-error-messages
     */
    public static function getEntityMessages(): array
    {
        return [];
    }

    /**
     * Get the default entity casts
     *
     * @see https://codeigniter.com/user_guide/models/model.html#model-field-casting
     */
    public static function getEntityCasts(): array
    {
        return [
            'id'         => 'int',
            'type'       => 'int',
            'status'     => 'int',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    /**
     * Get custom entity cast handlers
     *
     * @see https://codeigniter.com/user_guide/models/model.html#custom-casting
     */
    public static function getEntityCastHandlers(): array
    {
        return [];
    }

    /**
     * Get dynamic types supported by this entity
     */
    public static function getEntityTypes(): array
    {
        $class = static::class;

        if (!isset(self::$resolvedTypes[$class])) {
            self::$resolvedTypes[$class] = [
                static::TYPE_NONE => __('None', 'system'),
                static::TYPE_PAGE => __('Page', 'system'),
                static::TYPE_POST => __('Post', 'system'),
            ];
        }

        return self::$resolvedTypes[$class];
    }

    /**
     * Get dynamic statuses supported by this entity
     */
    public static function getEntityStatuses(): array
    {
        $class = static::class;

        if (!isset(self::$resolvedStatuses[$class])) {
            self::$resolvedStatuses[$class] = [
                static::STATUS_DRAFT     => __('Draft', 'system'),
                static::STATUS_PENDING   => __('Pending', 'system'),
                static::STATUS_PUBLISHED => __('Published', 'system'),
                static::STATUS_ARCHIVED  => __('Archived', 'system'),
                static::STATUS_DELETED   => __('Deleted', 'system'),
            ];
        }

        return self::$resolvedStatuses[$class];
    }

    /**
     * Current entity attributes
     */
    protected array $attributes = [];

    /**
     * Original values of changed entity attributes
     */
    protected array $original = [];

    /**
     * Cached escaped values to prevent redundant escaping operations.
     */
    protected array $escaped = [];

    /**
     * One-time initialization of static class data
     * Prevents redundant processing during bulk instantiation
     */
    public function __construct(?array $data = null)
    {
        $class = static::class;
        $defaults = static::getEntityDefaults();

        if (!isset(static::$resolvedMethods[$class])) {

            self::$entityCasters[$class] = app(\App\Core\Entity\EntityCaster::class, [
                static::getEntityCasts(),
                static::getEntityCastHandlers()
            ]);

            static::$resolvedMethods[$class] = [];

            foreach ($defaults as $key => $value) {
                if (str_contains($key, '_')) {
                    $method = str_replace(['-', '_', ' '], '', ucwords($key, '-_ '));
                } else {
                    $method = ucfirst($key);
                }
                static::$resolvedMethods[$class][$key] = $method;
                static::$resolvedMethods[$class]['get' . $method] = $key;
                static::$resolvedMethods[$class]['set' . $method] = $key;
            }

        }

        $this->attributes = is_array($data) ? ($data + $defaults) : $defaults;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) or isset(static::$resolvedMethods[static::class][$key]);
    }

    public function __get(string $key)
    {
        if (isset(static::$resolvedMethods[static::class][$key])) {
            $suffix = static::$resolvedMethods[static::class][$key];
            $method = 'get' . $suffix;

            if (method_exists($this, $method)) {
                return $this->$method();
            }

            $value = $this->attributes[$key] ?? null;

            if (is_string($value) and $value) {
                $casts = static::getEntityCasts();
                if (!isset($casts[$key]) or $casts[$key] === 'text') {
                    if (!isset($this->escaped[$key])) {
                        $this->escaped[$key] = Escaper::escapeHtml($value);
                    }
                    $value = $this->escaped[$key];
                }
            }

            return $value;
        }

        return null;
    }

    public function __set(string $key, $value): void
    {
        if (!isset(static::$resolvedMethods[static::class][$key])) {
            return;
        }

        $suffix = static::$resolvedMethods[static::class][$key];
        $method = 'set' . $suffix;

        if (method_exists($this, $method)) {
            $this->$method($value);
            return;
        }

        $this->setAttribute($key, $value);
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (!isset(static::$resolvedMethods[static::class][$method])) {
            throw new BadMethodCallException(sprintf('Method %s does not exist.', $method));
        }

        $attribute = static::$resolvedMethods[static::class][$method];

        if (str_starts_with($method, 'get')) {
            return $this->__get($attribute);
        }

        if (str_starts_with($method, 'set')) {
            return $this->setAttribute($attribute, $arguments[0] ?? null);
        }

        throw new BadMethodCallException(sprintf('Method %s does not exist.', $method));
    }

    public function __unserialize(array $attributes): void
    {
        $this->setAttributes($attributes);
    }

    public function __serialize(): array
    {
        return $this->attributes;
    }

    /**
     * Get current entry attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set some or all entry attributes
     */
    public function setAttributes(array $attributes): void
    {
        $attributes = self::$entityCasters[static::class]->fromDataSource($attributes);
        $this->attributes = $attributes + $this->attributes;
    }

    /**
     * Internal method to set a single attribute with change tracking and casting.
     */
    public function setAttribute(string $attribute, mixed $newValue): bool
    {
        $currentValue = $this->attributes[$attribute] ?? null;

        $casts = static::getEntityCasts();

        if (isset($casts[$attribute]) and isset(self::$entityCasters[static::class])) {
            $data = self::$entityCasters[static::class]->fromDataSource([$attribute => $newValue]);
            $newValue = $data[$attribute];
        }

        if ($currentValue !== $newValue) {
            if (!array_key_exists($attribute, $this->original)) {
                $this->original[$attribute] = $currentValue;
            }

            $this->attributes[$attribute] = $newValue;

            if (isset($this->escaped[$attribute])) {
                unset($this->escaped[$attribute]);
            }

            if ($this->attributes[$attribute] === ($this->original[$attribute] ?? null)) {
                unset($this->original[$attribute]);
            }
            return true;
        }

        return false;
    }

    /**
     * Check if an attribute or a whole entity has changed
     */
    public function hasChanged(?string $key = null): bool
    {
        if ($key === null) {
            return count($this->original) > 0;
        } else {
            return array_key_exists($key, $this->original);
        }
    }

    /**
     * Get changed attributes with new values
     */
    public function getChanges(): array
    {
        return array_intersect_key($this->attributes, $this->original);
    }

    /**
     * Mark all attributes as unchanged after saving
     */
    public function flushChanges(): void
    {
        $this->original = [];
    }

    /**
     * Get all attributes with original values
     */
    public function getOriginal(): array
    {
        return $this->original + $this->attributes;
    }

    /**
     * Sync directly assigned object properties with attributes
     */
    public function syncOriginal(): void
    {
        $props = get_object_vars($this);

        foreach (['attributes', 'original'] as $key) {
            unset($props[$key]);
        }

        $props = self::$entityCasters[static::class]->fromDataSource($props);

        foreach ($props as $key => $value) {
            $this->attributes[$key] = $value;
            unset($this->$key);
        }
    }

    /**
     * Restore original attributes
     */
    public function restoreOriginal(): void
    {
        $this->attributes = $this->original + $this->attributes;
        $this->original = [];
    }

    /**
     * Return current attributes with entities converted to arrays
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array
    {
        if ($onlyChanged) {
            $attributes = $this->getChanges();
        } else {
            $attributes = $this->attributes;
        }

        if ($recursive) {
            return array_map(function ($value) use ($onlyChanged, $recursive) {
                if ($value instanceof EntityInterface) {
                    $value = $value->toRawArray($onlyChanged, $recursive);
                } elseif (is_object($value) and is_callable([$value, 'toRawArray'])) {
                    $value = $value->toRawArray();
                }
                return $value;
            }, $attributes);
        } else {
            return $attributes;
        }
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