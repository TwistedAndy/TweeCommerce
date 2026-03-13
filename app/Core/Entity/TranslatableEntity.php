<?php

namespace App\Core\Entity;

use App\Core\Container\Container;

abstract class TranslatableEntity extends Entity
{
    /**
     * Currently selected locale
     */
    protected string $locale = '';

    /**
     * A list of translatable keys
     *
     * @var array<string, true>
     */
    protected array $translationKeys = [];

    /**
     * Maps Locale => Translation ID. Key presence means the locale has been loaded.
     * A null value means the locale was queried but has no row in the database.
     *
     * @var array<string, int|string|null>
     */
    protected array $translationIds = [];

    /**
     * Per-locale escaped (cast) value cache for translatable fields.
     * Keyed as [locale][field] so switching locales does not wipe other locales' caches.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $escapedTranslations = [];

    protected const TRANSLATION_KEY = '_translations';

    public static function initEntity(?Container $container = null): EntityFields
    {
        $class = static::class;

        if (isset(static::$entityFields[$class])) {
            return static::$entityFields[$class];
        }

        if ($container === null) {
            $container = Container::getInstance();
        }

        $rawFields = $class::getEntityFields();

        $rawFields[static::TRANSLATION_KEY] = [
            'type'     => 'relation',
            'relation' => [
                'type'    => 'translation',
                'entity'  => '',
                'cascade' => true,
            ]
        ];

        $fields = $container->make(EntityFields::class, [
            'fields'    => $rawFields,
            'container' => $container,
        ], $class);

        static::$entityFields[$class] = $fields;

        static::initCaches();

        return $fields;
    }

    public static function getTranslationKey(): string
    {
        return static::TRANSLATION_KEY;
    }

    public function __construct(array $data = [], ?string $alias = null, ?EntityFields $fields = null)
    {
        parent::__construct($data, $alias, $fields);

        $this->translationKeys = $this->fields->getTranslatable();
    }

    public function __get(string $name): mixed
    {
        if (!isset($this->translationKeys[$name]) or $this->locale === '') {
            return parent::__get($name);
        }

        $locale = $this->locale;

        if (!isset($this->escapedTranslations[$locale])) {
            $this->escapedTranslations[$locale] = [];
        }

        if (array_key_exists($name, $this->escapedTranslations[$locale])) {
            return $this->escapedTranslations[$locale][$name];
        }

        $value = $this->getAttribute($name);

        if (isset($this->fieldKeys[$name])) {
            $value = $this->fields->castFromStorage($name, $value);
        } elseif ($this->fields->isSerialized($value)) {
            $value = unserialize($value);
        }

        $this->escapedTranslations[$locale][$name] = $value;

        return $value;
    }

    public function __serialize(): array
    {
        $data = parent::__serialize();

        $data['locale']         = $this->locale;
        $data['translationIds'] = $this->translationIds;

        return $data;
    }

    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);

        $this->locale          = $data['locale'] ?? '';
        $this->translationIds  = $data['translationIds'] ?? [];
        $this->translationKeys = $this->fields->getTranslatable();

        $this->setLocale($this->locale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        if (empty($this->translationKeys) or empty($locale)) {
            return $this;
        }

        $translationKey = self::TRANSLATION_KEY;

        if (!isset($this->attributes[$translationKey])) {
            $this->attributes[$translationKey] = [];
        }

        if (!isset($this->attributes[$translationKey][$locale])) {
            $this->attributes[$translationKey][$locale] = [];
        }

        if (!isset($this->changes[$translationKey])) {
            $this->changes[$translationKey] = [];
        }

        if (!isset($this->changes[$translationKey][$locale])) {
            $this->changes[$translationKey][$locale] = [];
        }

        if (!isset($this->escapedTranslations[$locale])) {
            $this->escapedTranslations[$locale] = [];
        }

        return $this;
    }

    public function getAttributes(): array
    {
        if (!$this->changes) {
            return $this->attributes;
        }

        $attributes = $this->changes + $this->attributes;

        $translationKey = self::TRANSLATION_KEY;

        if (!isset($this->attributes[$translationKey])) {
            $this->attributes[$translationKey] = [];
        }

        $translations = $this->attributes[$translationKey];

        if (!empty($this->changes[$translationKey])) {
            foreach ($this->changes[$translationKey] as $locale => $fields) {
                foreach ($fields as $field => $value) {
                    $translations[$locale][$field] = $value;
                }
            }
        }

        $attributes[$translationKey] = $translations;

        return $attributes;
    }

    public function getAttribute(string $key): mixed
    {
        if (!isset($this->translationKeys[$key]) or $this->locale === '') {
            return parent::getAttribute($key);
        }

        $translationKey = self::TRANSLATION_KEY;

        // Lazy load a locale if it has been never been fetched
        if (!array_key_exists($this->locale, $this->translationIds)) {
            $this->fields->getRelation($translationKey)->get($this);
        }

        return $this->changes[$translationKey][$this->locale][$key] ?? $this->attributes[$translationKey][$this->locale][$key] ?? parent::getAttribute($key);
    }

    public function setAttribute(string $key, mixed $value): bool
    {
        if (!isset($this->translationKeys[$key]) or $this->locale === '') {
            return parent::setAttribute($key, $value);
        }

        $newValue = $this->fields->castToStorage($key, $value);

        $oldValue = $this->getAttribute($key);

        if ($oldValue === $newValue) {
            return false;
        }

        $this->changes[self::TRANSLATION_KEY][$this->locale][$key] = $newValue;

        unset($this->escapedTranslations[$this->locale][$key]);

        return true;
    }

    /**
     * Store translation fields for a locale directly in attributes without marking the entity dirty.
     * Also records the Translation ID so update() can issue UPDATE vs INSERT correctly.
     *
     * A null $id means the locale was loaded but has not been stored in the database yet.
     * An empty $data array is valid: it records that the locale was queried and returned nothing.
     *
     * @param array<string, mixed> $data     Translated field values in the storage format
     * @param int|string|null $translationId Translation primary key, or null if no row exists
     */
    public function setTranslation(string $locale, array $data, int|string|null $translationId = null): void
    {
        $translationKey = self::TRANSLATION_KEY;

        if (!isset($this->attributes[$translationKey])) {
            $this->attributes[$translationKey] = [];
        }

        $this->attributes[$translationKey][$locale] = $data;

        $this->translationIds[$locale] = $translationId;

        unset($this->escapedTranslations[$locale]);
    }

    /**
     * Return the translation row ID for a locale or null if there is no row or it is not yet loaded
     */
    public function getTranslationId(string $locale): int|string|null
    {
        return $this->translationIds[$locale] ?? null;
    }

}
