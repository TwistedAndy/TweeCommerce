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
     * A translation key with the translation primary key
     */
    public const TRANSLATION_ID = '_id';

    /**
     * An internal field key to store all entity translations
     */
    public const TRANSLATION_KEY = '_translations';

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

        $entityFields = $class::getEntityFields();

        // Add the translation relation if there's a field marked with 'translate' => true
        foreach ($entityFields as $field) {
            if (!empty($field['translate'])) {
                $entityFields[self::TRANSLATION_KEY] = [
                    'type'     => 'relation',
                    'relation' => [
                        'type'    => 'translation',
                        'entity'  => '',
                        'cascade' => true,
                    ],
                ];
                break;
            }
        }

        $fields = $container->make(EntityFields::class, [
            'fields'    => $entityFields,
            'container' => $container,
        ], $class);

        static::$entityFields[$class] = $fields;

        static::initCaches();

        return $fields;
    }

    /**
     * Reset the static entity caches
     */
    public static function resetEntity(): void
    {
        $class = static::class;

        unset(
            static::$entityFields[$class],
            static::$entityAliases[$class],
            static::$entityMethods[$class],
            static::$entityGetters[$class],
            static::$entitySetters[$class],
        );
    }

    /**
     * Fill the static entity caches
     */
    protected static function initCaches(): void
    {
        $class = static::class;

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
            'getLocale',
            'setLocale',
            'getTranslatable',
            'getTranslations',
            'getTranslation',
            'getTranslationId',
            'setTranslation',
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
            'tags'       => [
                'type'     => 'relation',
                'relation' => [
                    'type'       => 'has-many',
                    'entity'     => 'comment',
                    'constraint' => [
                        'where'    => ['status' => 'approved', 'deleted_at' => null],
                        'orderBy'  => ['created_at', 'DESC'],
                        'limit'    => 50,
                        'offset'   => 0,
                        /*
                         * Supported callback formats:
                         * - Local Model Method (String)
                         * - Static Class Callable (Array)
                         * - Static Class Callable (String)
                         * - Global Function (String)
                         */
                        'callback' => null
                    ]
                ],
            ],
            'created_at' => [
                'default' => 0,
                'type'    => 'timestamp',
                'rules'   => 'required',
                'subtype' => 'created',
            ],
            'updated_at' => [
                'default' => null,
                'type'    => '?timestamp',
                'subtype' => 'updated',
            ],
            'deleted_at' => [
                'default' => null,
                'type'    => '?timestamp',
                'subtype' => 'deleted',
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
    protected array  $escaped      = [];
    protected array  $fieldKeys    = [];
    protected array  $relationKeys = [];
    protected string $alias;

    /**
     * Current Entity Fields object
     */
    protected EntityFields $fields;

    protected bool $customFields = false;

    /**
     * Currently selected locale (empty string = no active locale / non-translatable)
     */
    protected string $locale = '';

    /**
     * Fast-lookup map of translatable field names: ['title' => true, ...]
     * Empty array means the entity has no translatable fields
     *
     * @var array<string, true>
     */
    protected array $translatable = [];

    public function __construct(array $data = [], ?string $alias = null, ?EntityFields $fields = null)
    {
        $class = static::class;

        if (!isset(static::$entityFields[$class])) {
            static::initEntity();
        }

        if ($fields === null or $fields === static::$entityFields[$class]) {
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

        $this->fieldKeys    = $this->fields->getFields();
        $this->relationKeys = $this->fields->getRelations();
        $this->translatable = $this->fields->getTranslatable();

        if ($this->translatable and !isset($this->attributes[self::TRANSLATION_KEY])) {
            $this->attributes[self::TRANSLATION_KEY] = [];
        }
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
        // Check for a translation when a locale is set AND a field is translatable
        if ($this->locale !== '' and isset($this->translatable[$name])) {
            $key = $this->locale . $name;

            if (array_key_exists($key, $this->escaped)) {
                return $this->escaped[$key];
            }

            $value = $this->getAttribute($name);

            if (isset($this->fieldKeys[$name])) {
                $value = $this->fields->castFromStorage($name, $value);
            } elseif ($this->fields->isSerialized($value)) {
                $value = unserialize($value);
            }

            return $this->escaped[$key] = $value;
        }

        if (array_key_exists($name, $this->escaped)) {
            return $this->escaped[$name];
        }

        if (isset($this->relationKeys[$name])) {
            return $this->getAttribute($name);
        }

        $class = static::class;

        if (isset(static::$entityGetters[$class][$name])) {
            $method = static::$entityGetters[$class][$name];
            $value  = $this->$method();
        } else {
            $value = $this->getAttribute($name);

            if (isset($this->fieldKeys[$name])) {
                $value = $this->fields->castFromStorage($name, $value);
            } elseif ($this->fields->isSerialized($value)) {
                $value = unserialize($value);
            }
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

        $prefix = substr($method, 0, 3);

        if ($prefix === 'get') {
            return $this->__get($field);
        }

        if ($prefix === 'set') {
            $this->__set($field, $arguments[0]);
            return array_key_exists($field, $this->changes);
        }

        if ($prefix === 'has') {
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

        if ($this->locale !== '') {
            $data['locale'] = $this->locale;
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

        $this->fieldKeys    = $this->fields->getFields();
        $this->relationKeys = $this->fields->getRelations();

        $this->translatable = $this->fields->getTranslatable();

        if ($this->translatable) {
            if (!isset($this->attributes[self::TRANSLATION_KEY])) {
                $this->attributes[self::TRANSLATION_KEY] = [];
            }

            $this->setLocale($data['locale'] ?? '');
        }
    }

    /**
     * Get entity attributes in the storage format
     */
    public function getAttributes(): array
    {
        if (!$this->changes) {
            return $this->attributes;
        }

        $attributes = $this->changes + $this->attributes;

        // When translation changes exist, merge them by locale
        if ($this->translatable and $this->changes[self::TRANSLATION_KEY] ?? null) {
            $attributes[self::TRANSLATION_KEY] = $this->getTranslations(true);
        }

        return $attributes;
    }

    /**
     * Get an entity attribute in the storage format
     */
    public function getAttribute(string $key): mixed
    {
        if ($this->locale !== '' and isset($this->translatable[$key])) {
            $changes = $this->changes[self::TRANSLATION_KEY][$this->locale] ?? null;

            if ($changes !== null and array_key_exists($key, $changes)) {
                return $changes[$key];
            }

            $translation = $this->getTranslation($this->locale);

            if (array_key_exists($key, $translation)) {
                return $translation[$key];
            }
        }

        if (array_key_exists($key, $this->changes)) {
            return $this->changes[$key];
        }

        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        if (isset($this->relationKeys[$key])) {
            $value = $this->fields->getRelation($key)->get($this);

            $this->attributes[$key] = $value;
            return $value;
        }

        $value = $this->fields->getDefaultValue($key);

        $this->attributes[$key] = $value;

        return $value;
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
    public function setAttribute(string $key, mixed $value): bool
    {
        if ($this->locale !== '' and isset($this->translatable[$key])) {
            $newValue = $this->fields->castToStorage($key, $value);
            $oldValue = $this->getAttribute($key);

            if ($oldValue === $newValue) {
                return false;
            }

            $changes       = $this->changes[self::TRANSLATION_KEY][$this->locale] ?? [];
            $changes[$key] = $newValue;

            $this->changes[self::TRANSLATION_KEY][$this->locale] = $changes;

            unset($this->escaped[$this->locale . $key]);

            return true;
        }

        if (isset($this->relationKeys[$key])) {
            $relation = $this->fields->getRelation($key);

            // Read directly from raw memory arrays to prevent N+1 queries
            $existing = $this->changes[$key] ?? $this->attributes[$key] ?? null;

            // If assigning an associative array to an existing entity, update it in place
            if ($existing instanceof EntityInterface and is_array($value) and !array_is_list($value)) {
                $existing->setAttributes($value);
                $value = $existing;
            } else {
                // Normalizes the input into EntityInterface or EntityInterface[]
                $value = $relation->resolve($value);
            }

            // Exact identical instances (e.g., same mutated memory object)
            if ($existing === $value) {
                return false;
            }

            // Singular Entity Comparison: Compare Primary Keys natively
            if ($existing instanceof EntityInterface and $value instanceof EntityInterface) {
                $primaryKey = $existing->getFields()->getPrimaryKey();
                $existingId = $existing->getAttribute($primaryKey);
                $valueId    = $value->getAttribute($primaryKey);

                if ($existingId !== null and $existingId === $valueId and $existing->getAlias() === $value->getAlias()) {
                    return false;
                }
            }

            // Arrays (has-many/belongs-many) bypass the above checks and assumed to be changed
            $this->attributes[$key] = $value;
            $this->changes[$key]    = $value;

            return true;
        }

        $oldValue = $this->getAttribute($key);

        if (isset($this->fieldKeys[$key])) {
            $newValue = $this->fields->castToStorage($key, $value);
        } elseif (is_object($value) or is_array($value)) {
            $newValue = serialize($value);
        } else {
            $newValue = $value;
        }

        if ($oldValue === $newValue) {
            return false;
        }

        unset($this->escaped[$key]);

        if (array_key_exists($key, $this->attributes) and $this->attributes[$key] === $newValue) {
            unset($this->changes[$key]);
        } else {
            $this->changes[$key] = $newValue;
        }

        return true;
    }

    /**
     * Check if an attribute or a whole entity has changed
     */
    public function hasChanged(?string $key = null): bool
    {
        if ($key === null) {
            return $this->changes !== [];
        }

        return array_key_exists($key, $this->changes);
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
            $this->attributes = $this->getAttributes();
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
     * ArrayAccess: Allow accessing entity properties like an array
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

    /**
     * Get the current entity locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Set the current entity locale
     */
    public function setLocale(string $locale): void
    {
        if ($this->translatable) {
            $this->locale = $locale;
        }
    }

    /**
     * Return all translations, optionally triggering a full DB load first.
     *
     * @return array<string, array<string, mixed>>  locale → field data
     */
    public function getTranslations(bool $onlyLoaded = false): array
    {
        if (empty($this->translatable)) {
            return [];
        }

        if (!$onlyLoaded) {
            $currentLocale = $this->locale;
            $this->locale  = '';

            $translations = $this->fields->getRelation(self::TRANSLATION_KEY)->get($this);

            foreach ($translations as $locale => $data) {
                $this->setTranslation($locale, $data);
            }

            $this->locale = $currentLocale;
        }

        $translations = $this->attributes[self::TRANSLATION_KEY];

        if ($this->changes[self::TRANSLATION_KEY] ?? null) {
            foreach ($this->changes[self::TRANSLATION_KEY] as $locale => $fields) {
                $translations[$locale] = $fields + ($translations[$locale] ?? []);
            }
        }

        return $translations;
    }

    /**
     * Return translated field data for a single locale, lazy-loading if needed.
     *
     * @return array<string, mixed>
     */
    public function getTranslation(string $locale): array
    {
        if ($locale === '') {
            return [];
        }

        $translation = $this->attributes[self::TRANSLATION_KEY][$locale] ?? null;

        if ($translation === null) {
            $currentLocale = $this->locale;
            $this->locale  = $locale;
            $translations  = $this->fields->getRelation(self::TRANSLATION_KEY)->get($this);
            $this->locale  = $currentLocale;

            foreach ($translations as $key => $data) {
                $this->setTranslation($key, $data);
            }

            if (isset($translations[$locale])) {
                $translation = $translations[$locale];
            } else {
                $translation = [];

                // Prevent loading the non-existing locale again
                $this->attributes[self::TRANSLATION_KEY][$locale] = $translation;
            }
        }

        if ($this->changes[self::TRANSLATION_KEY][$locale] ?? null) {
            $translation = $this->changes[self::TRANSLATION_KEY][$locale] + $translation;
        }

        return $translation;
    }

    public function getTranslationId(string $locale): int|string|null
    {
        $translation = $this->getTranslation($locale);

        return $translation[self::TRANSLATION_ID] ?? null;
    }

    /**
     * Store translation fields for a locale directly in attributes without marking the entity dirty.
     * Also records the Translation ID so update() can issue UPDATE vs INSERT correctly.
     * A null $id means the locale was loaded but has not been stored in the database yet.
     *
     * @param string $locale
     * @param array<string, mixed> $data     Translated field values in the storage format
     * @param int|string|null $translationId Translation primary key, or null if no row exists
     */
    public function setTranslation(string $locale, array $data, int|string|null $translationId = null): void
    {
        if ($locale === '') {
            return;
        }

        $existing = $this->attributes[self::TRANSLATION_KEY][$locale] ?? null;

        if ($existing) {
            if (empty($translationId) and !empty($existing[self::TRANSLATION_ID])) {
                $translationId = $existing[self::TRANSLATION_ID];
            }

            foreach ($this->translatable as $field => $value) {
                unset($this->escaped[$locale . $field]);
            }
        }

        if (!array_key_exists(self::TRANSLATION_ID, $data)) {
            $data[self::TRANSLATION_ID] = $translationId;
        }

        $this->attributes[self::TRANSLATION_KEY][$locale] = $data;
    }
}
