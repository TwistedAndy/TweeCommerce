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
     * A queue of eagerly loaded relations: [ 'relationName' => Closure|null ]
     */
    protected array $withRelations = [];

    /**
     * Queue relations to be eagerly loaded.
     *
     * Accepts plain names or an associative array with optional inline constraints:
     * ->with('author')
     * ->with('author', 'comments')
     * ->with(['author', 'comments' => fn($q) => $q->where('approved', 1)])
     * ->with(['comments' => [$this, 'scopeApproved']])
     */
    public function with(array|string ...$relations): self
    {
        foreach ($relations as $arg) {
            // Normalize the argument so we can iterate smoothly
            $list = is_array($arg) ? $arg : [$arg];

            foreach ($list as $key => $value) {
                // If the key is numeric, it's just a string relation name.
                // Otherwise, the key is the name and the value is the constraint.
                $name       = is_int($key) ? (string) $value : (string) $key;
                $constraint = is_callable($value) ? $value : null;

                if (!isset($this->relations[$name])) {
                    throw EntityException::undefinedRelation($name);
                }

                // Assign to the queue (this preserves existing queued relations for chaining)
                $this->withRelations[$name] = $constraint;
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
                    if ($item instanceof EntityInterface and $item->hasChanged()) {
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

        foreach ($relations as $key => $constraint) {
            $this->fields->getRelation($key)->eagerLoad($entities, $constraint);
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
                $relation->cascadeRestore($localIds, $this->alias);
            }
        }
    }

}