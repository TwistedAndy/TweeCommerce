<?php

namespace App\Core\Entity;

/**
 * Interface EntityInterface
 *
 * Defines the contract for high-performance entities within the CMS.
 */
interface EntityInterface
{
    /**
     * Get the primary key attribute
     *
     * @see https://codeigniter.com/user_guide/models/model.html#primarykey
     */
    public static function getEntityKey(): string;

    /**
     * Get the entity alias to be used as a base for database table names
     */
    public static function getEntityAlias(): string;

    /**
     * Get default values for all entity attributes
     */
    public static function getEntityDefaults(): array;

    /**
     * Get validation rules for the entity
     *
     * @see https://codeigniter.com/user_guide/libraries/validation.html#validation-available-rules
     */
    public static function getEntityRules(): array;

    /**
     * Get validation messages for the entity
     *
     * @see https://codeigniter.com/user_guide/libraries/validation.html#setting-custom-error-messages
     */
    public static function getEntityMessages(): array;

    /**
     * Get the default entity casts
     *
     * @see https://codeigniter.com/user_guide/models/model.html#model-field-casting
     */
    public static function getEntityCasts(): array;

    /**
     * Get custom entity cast handlers
     *
     * @see https://codeigniter.com/user_guide/models/model.html#custom-casting
     */
    public static function getEntityCastHandlers(): array;

    /**
     * Get dynamic types supported by this entity
     */
    public static function getEntityTypes(): array;

    /**
     * Get dynamic statuses supported by this entity
     */
    public static function getEntityStatuses(): array;

    /**
     * Initializes the entity and triggers one-time static data caching
     */
    public function __construct(?array $data = null);

    /**
     * Get current entry attributes
     */
    public function getAttributes(): array;

    /**
     * Set some or all entry attributes
     */
    public function setAttributes(array $attributes): void;

    /**
     * Internal method to set a single attribute with change tracking and casting.
     */
    public function setAttribute(string $attribute, mixed $newValue): bool;

    /**
     * Check if an attribute or a whole entity has changed
     */
    public function hasChanged(?string $key = null): bool;

    /**
     * Get changed attributes with new values
     */
    public function getChanges(): array;

    /**
     * Mark all attributes as unchanged after saving
     */
    public function flushChanges(): void;

    /**
     * Get all attributes with original values
     */
    public function getOriginal(): array;

    /**
     * Sync directly assigned object properties with attributes
     */
    public function syncOriginal(): void;

    /**
     * Restore original attributes
     */
    public function restoreOriginal(): void;

    /**
     * Return current attributes with entities converted to arrays
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array;
}