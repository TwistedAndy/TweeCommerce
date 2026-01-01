<?php

namespace App\Core\Entity;

use App\Core\Libraries\Escaper;
use App\Core\Container\Container;
use CodeIgniter\DataCaster\Cast\CastInterface;
use CodeIgniter\Exceptions\BadMethodCallException;
use JsonSerializable;

/**
 * A lightweight Entity class to handle simple objects
 */
class Entity implements EntityInterface, JsonSerializable
{
    /**
     * A set of internal static caches
     */
    protected static array $entityDefaults = [];
    protected static array $entityEscapers = [];
    protected static array $entityCasters  = [];
    protected static array $entityMethods  = [];
    protected static array $entityFields   = [];
    protected static array $entityCasts    = [];

    /**
     * Get an entity caster
     */
    public static function getCaster(): EntityCaster
    {
        $class = static::class;

        if (!isset(static::$entityCasters[$class])) {
            static::getFields();
        }

        return static::$entityCasters[$class];
    }

    /**
     * Get a normalized array with entity fields
     */
    public static function getFields(): array
    {
        $class = static::class;

        if (isset(static::$entityFields[$class])) {
            return static::$entityFields[$class];
        }

        static::$entityFields[$class] = [];

        $entityFields = static::getSchema();

        $container = Container::getInstance();

        $fieldCasts      = [];
        $castHandlers    = [];
        $fieldDefaults   = [];
        $resolveDefaults = [];
        $entityMethods   = [];
        $hasPrimaryKey   = false;

        foreach ($entityFields as $key => $field) {

            if (empty($field['type'])) {
                $field['type'] = 'text';
            } elseif (!is_string($field['type'])) {
                throw new EntityException('A provided type should be a valid cast string');
            }

            $fieldCasts[$key] = $field['type'];

            if (array_key_exists('default', $field)) {
                $fieldDefaults[$key] = $field['default'];
            } else {
                $resolveDefaults[$key] = '';
            }

            if (empty($field['label'])) {
                $field['label'] = ucwords(str_replace('_', ' ', $key));
            }

            if (!empty($field['caster'])) {
                if (is_string($field['caster']) and is_subclass_of($field['caster'], CastInterface::class)) {
                    $castHandlers[$field['type']] = $field['caster'];
                } else {
                    throw new EntityException('A provided caster should be a implement the CastInterface interface');
                }
            }

            if (!empty($field['primary'])) {
                if ($hasPrimaryKey) {
                    throw new EntityException('There should be only one field marked as a primary key');
                }

                $hasPrimaryKey = true;
            }

            if (str_contains($key, '_')) {
                $method = str_replace(['-', '_', ' '], '', \ucwords($key, '-_ '));
            } else {
                $method = ucfirst($key);
            }

            $entityMethods[$key]            = $method;
            $entityMethods['get' . $method] = $key;
            $entityMethods['set' . $method] = $key;

            if (array_key_exists('rules', $field)) {
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

                if (array_key_exists('errors', $field)) {
                    $field['rules']['errors'] = $field['errors'];
                    unset($field['errors']);
                }
            }

            $entityFields[$key] = $field;
        }

        if (!$hasPrimaryKey) {
            throw new EntityException('No primary key is specified for an entity');
        }

        $entityEscaper = $container->make(Escaper::class);

        $entityCaster = $container->make(EntityCaster::class, [
            'casts'        => $fieldCasts,
            'castHandlers' => $castHandlers,
        ]);

        if ($resolveDefaults) {

            $defaults = $entityCaster->fromDataSource($resolveDefaults);

            foreach ($defaults as $key => $default) {
                $entityFields[$key]['default'] = $default;
                $fieldDefaults[$key]           = $default;
            }

        }

        self::$entityDefaults[$class] = $fieldDefaults;
        self::$entityEscapers[$class] = $entityEscaper;
        self::$entityMethods[$class]  = $entityMethods;
        self::$entityCasters[$class]  = $entityCaster;
        self::$entityFields[$class]   = $entityFields;
        self::$entityCasts[$class]    = $fieldCasts;

        return self::$entityFields[$class];
    }

    /**
     * Return an entry fields with a field name as a key
     *
     * @see https://codeigniter.com/user_guide/models/model.html#custom-casting,
     *      https://codeigniter.com/user_guide/libraries/validation.html#setting-validation-rules
     */
    protected static function getSchema(): array
    {
        /**
         * @var array<string, array{
         *   primary?: (bool),         // Primary key flag
         *   label?:   (string),       // Field label
         *   default?: (mixed),        // Default value
         *   type?:    (string),       // Casting type
         *   rules?:   (string|array), // Validation rules
         *   caster?:  (string)        // Caster class name
         * }>
         */
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

    public function __construct(?array $data = null)
    {
        $class = static::class;

        if (!isset(static::$entityDefaults[$class])) {
            static::getFields();
        }

        $this->attributes = is_array($data) ? ($data + static::$entityDefaults[$class]) : static::$entityDefaults[$class];
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) or isset(static::$entityMethods[static::class][$key]);
    }

    public function __get(string $key): mixed
    {
        if (!isset(static::$entityMethods[static::class][$key])) {
            return null;
        }

        $suffix = static::$entityMethods[static::class][$key];
        $method = 'get' . $suffix;

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        $value = $this->attributes[$key] ?? null;

        if (is_string($value) and $value) {
            $casts = self::$entityCasts[static::class];
            if (!isset($casts[$key]) or $casts[$key] === 'text') {
                if (!isset($this->escaped[$key])) {
                    $this->escaped[$key] = static::$entityEscapers[static::class]->escapeHtml($value);
                }
                $value = $this->escaped[$key];
            }
        }

        return $value;
    }

    public function __set(string $key, $value): void
    {
        if (!isset(static::$entityMethods[static::class][$key])) {
            return;
        }

        $method = 'set' . static::$entityMethods[static::class][$key];

        if (method_exists($this, $method)) {
            $this->$method($value);
            return;
        }

        $this->setAttribute($key, $value);
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (!isset(static::$entityMethods[static::class][$method])) {
            throw new BadMethodCallException(sprintf('Method %s does not exist.', $method));
        }

        $attribute = static::$entityMethods[static::class][$method];

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
     * Get raw entry attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set entry attributes
     */
    public function setAttributes(array $attributes): void
    {
        $attributes       = self::$entityCasters[static::class]->fromDataSource($attributes);
        $this->attributes = $attributes + $this->attributes;
    }

    /**
     * Get a raw entry attribute
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set a single entry attribute
     */
    public function setAttribute(string $key, mixed $value): bool
    {
        $currentValue = $this->attributes[$key] ?? null;

        if (isset(self::$entityCasts[static::class][$key])) {
            $data  = self::$entityCasters[static::class]->fromDataSource([$key => $value]);
            $value = $data[$key];
        }

        if ($currentValue !== $value) {
            if (!array_key_exists($key, $this->original)) {
                $this->original[$key] = $currentValue;
            }

            $this->attributes[$key] = $value;

            if (isset($this->escaped[$key])) {
                unset($this->escaped[$key]);
            }

            if ($this->attributes[$key] === ($this->original[$key] ?? null)) {
                unset($this->original[$key]);
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
     * Restore original attributes
     */
    public function restoreOriginal(): void
    {
        $this->attributes = $this->original + $this->attributes;
        $this->original   = [];
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
                if (is_object($value) and is_callable([$value, 'toRawArray'])) {
                    $value = $value->toRawArray($onlyChanged, $recursive);
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
        return $this->attributes;
    }

}