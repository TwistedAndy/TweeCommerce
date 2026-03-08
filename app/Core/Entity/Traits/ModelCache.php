<?php

namespace App\Core\Entity\Traits;

use App\Core\Entity\EntityInterface;

trait ModelCache
{
    protected static array $identityMap = [];

    protected int    $cacheLimit = 10000;
    protected string $alias;

    public function initCache(string $alias): void
    {
        if (empty($this->alias)) {
            $this->alias = $alias;
        }

        if (!isset(static::$identityMap[$alias])) {
            static::$identityMap[$alias] = [];
        }
    }

    /**
     * Get an entity from the map, or all entities if no ID is provided.
     * Touches the entity to mark it as recently used (LRU).
     */
    public function getFromCache(int|string|null $id = null): EntityInterface|array|null
    {
        if ($id === null) {
            return static::$identityMap[$this->alias];
        }

        if (isset(static::$identityMap[$this->alias][$id])) {
            $entity = static::$identityMap[$this->alias][$id];

            // Move to the end of the array (LRU)
            unset(static::$identityMap[$this->alias][$id]);
            static::$identityMap[$this->alias][$id] = $entity;

            return $entity;
        }

        return null;
    }

    /**
     * Add an entity to the map and enforce the LRU limit per alias.
     */
    public function addToCache(int|string $id, EntityInterface $entity): void
    {
        if (isset(static::$identityMap[$this->alias][$id])) {
            unset(static::$identityMap[$this->alias][$id]);
        }

        static::$identityMap[$this->alias][$id] = $entity;

        if ($this->cacheLimit > 0 and count(static::$identityMap[$this->alias]) > $this->cacheLimit) {
            $oldestKey = array_key_first(static::$identityMap[$this->alias]);
            if ($oldestKey !== null) {
                unset(static::$identityMap[$this->alias][$oldestKey]);
            }
        }
    }

    /**
     * Safely remove one or more entity IDs from this model's cache,
     * or clear the entire cache for this entity type if no ID is provided.
     */
    public function removeFromCache(int|string|array|null $ids = null): void
    {
        if ($ids === null) {
            static::$identityMap[$this->alias] = [];
            return;
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        foreach ($ids as $id) {
            unset(static::$identityMap[$this->alias][$id]);
        }
    }

    /**
     * Remove all cached entities where a field matches a value, optionally
     * keeping entries whose primary key is in $excludeIds.
     * Used by detachOrphans() to avoid a SELECT query for cache invalidation.
     */
    public function removeFromCacheWhere(string $field, mixed $value, array $excludeIds = []): void
    {
        $excludeSet = array_flip($excludeIds);

        foreach (static::$identityMap[$this->alias] as $id => $entity) {
            if ($entity->getAttribute($field) == $value and !isset($excludeSet[$id])) {
                unset(static::$identityMap[$this->alias][$id]);
            }
        }
    }

}