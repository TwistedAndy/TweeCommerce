<?php

namespace App\Entities;

interface EntityInterface
{
    /**
     * Allow filling entry attributes on creation
     *
     * @param array|null $data
     */
    public function __construct(?array $data = null);

    /**
     * Get current entry attributes
     *
     * @return array
     */
    public function getAttributes(): array;

    /**
     * Set some or all entry attributes
     *
     * @param array $attributes
     *
     * @return array
     */
    public function setAttributes(array $attributes): array;

    /**
     * Check if an attribute or a whole entity has changed
     *
     * @param string|null $key
     *
     * @return bool
     */
    public function hasChanged(?string $key = null): bool;

    /**
     * Get changed attributes with new values
     *
     * @return array
     */
    public function getChanges(): array;

    /**
     * Mark all attributes as unchanged after saving
     *
     * @return void
     */
    public function flushChanges(): void;

    /**
     * Get all attributes with original values
     *
     * @return array
     */
    public function getOriginal(): array;

    /**
     * Sync directly assigned object properties with attributes
     * It's required for object restoration after fetch call
     *
     * @return void
     */
    public function syncOriginal(): void;

    /**
     * Restore original attributes
     *
     * @return void
     */
    public function restoreOriginal(): void;

    /**
     * Return current attributes with entities converted to arrays
     *
     * @param bool $onlyChanged
     * @param bool $recursive
     *
     * @return array
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array;

}