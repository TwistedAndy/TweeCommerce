<?php

namespace App\Core\Entity;

use App\Core\Container\Container;

/**
 * Interface EntityInterface
 *
 * Defines the contract for high-performance entities within the CMS.
 */
interface EntityInterface
{
    /**
     * Get an entity caster
     */
    public static function resolveSchema(?Container $container = null): EntitySchema;

    /**
     * Initializes the entity and triggers one-time static data caching
     */
    public function __construct(array $data = []);

    /**
     * Get raw entry attributes
     */
    public function getAttributes(): array;

    /**
     * Get a raw entry attribute
     */
    public function getAttribute(string $field): mixed;

    /**
     * Set some or all entry attributes
     */
    public function setAttributes(array $attributes): void;

    /**
     * Internal method to set a single attribute with change tracking and casting.
     */
    public function setAttribute(string $field, mixed $value): bool;

    /**
     * Check if an attribute or a whole entity has changed
     */
    public function hasChanged(?string $field = null): bool;

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
     * Get all attributes with original values
     */
    public function getSchema(): EntitySchema;

    /**
     * Return current attributes with entities converted to arrays
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array;
}