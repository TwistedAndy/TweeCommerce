<?php

namespace App\Core\Entity\Traits;

use App\Core\Entity\EntityException;
use App\Core\Entity\EntityFields;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityRelation;

/**
 * Entity Relations Trait
 */
trait ModelRelations
{
    protected EntityFields $fields;

    /**
     * A list of relation fields for the current model
     *
     * @var array<string, array<string, mixed>|EntityRelation>
     */
    protected array $relations = [];

    /**
     * A queue of eagerly loaded relations
     */
    protected array $withRelations = [];

    /**
     * Queue relations to be eagerly loaded
     */
    public function with(array|string $relations): self
    {
        $this->withRelations = is_string($relations) ? func_get_args() : $relations;

        foreach ($this->withRelations as $key) {
            if (!isset($this->relations[$key])) {
                throw EntityException::undefinedRelation($key);
            }
        }

        return $this;
    }

    /**
     * Check if there are eager relations queued
     */
    public function hasWith(): bool
    {
        return (bool) $this->withRelations;
    }

    /**
     * Reset the relation queue
     */
    public function resetWith(): self
    {
        $this->withRelations = [];

        return $this;
    }

    /**
     * Get the relation fields
     *
     * @return array<string, EntityRelation>
     */
    protected function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Save the entity relations
     */
    protected function saveRelations(EntityInterface $entity): void
    {
        $changes    = $entity->getChanges();
        $attributes = $entity->getAttributes();

        foreach ($this->relations as $key => $data) {
            $relation = $this->fields->getRelation($key);

            // Process immediately explicit assignments
            if (array_key_exists($key, $changes)) {
                $relation->update($entity, $changes[$key]);
                continue;
            }

            // Skip deep scan if a relation was never loaded
            if (empty($attributes[$key])) {
                continue;
            }

            // Deep Scanning: Check the currently loaded attribute
            $value = $attributes[$key];

            $hasDeepChanges = false;

            if ($value instanceof EntityInterface) {
                $hasDeepChanges = $value->hasChanged();
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof EntityInterface && $item->hasChanged()) {
                        $hasDeepChanges = true;
                        break;
                    }
                }
            }

            // If any loaded entity has changed, push it into the update cycle
            if ($hasDeepChanges) {
                $relation->update($entity, $value);
            }
        }
    }

    /**
     * Eagerly load the relation data for entities
     */
    protected function loadRelations(array $entities): void
    {
        if (empty($this->withRelations)) {
            return;
        }

        $relations = $this->withRelations;

        $this->withRelations = [];

        if (empty($entities)) {
            return;
        }

        foreach ($relations as $key) {
            $this->fields->getRelation($key)->eagerLoad($entities);
        }
    }

    /**
     * Run cascade delete for all relations that have cascade => true.
     */
    protected function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (empty($this->relations) or empty($localIds)) {
            return;
        }

        foreach ($this->relations as $key => $data) {
            if (!empty($data['cascade'])) {
                $relation = $this->fields->getRelation($key);
                $relation->cascadeDelete($localIds, $localAlias, $purge);
            }
        }
    }

    /**
     * Run cascade restore for all relations that have cascade => true.
     */
    protected function cascadeRestore(array $localIds): void
    {
        if (empty($this->relations) or empty($localIds)) {
            return;
        }

        foreach ($this->relations as $key => $data) {
            if (!empty($data['cascade'])) {
                $relation = $this->fields->getRelation($key);
                $relation->cascadeRestore($localIds);
            }
        }
    }

}