<?php

namespace App\Core\Entity\Traits;

use App\Core\Entity\Entity;
use App\Core\Entity\EntityException;
use App\Core\Entity\EntityFields;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\Relations\RelationInterface;
use CodeIgniter\Database\BaseBuilder;

/**
 * Entity Relations Trait
 */
trait ModelRelations
{
    protected EntityFields $fields;

    /**
     * A list of relation fields for the current model
     *
     * @var array<string, array<string, mixed>|RelationInterface>
     */
    protected array $relations = [];

    /**
     * A queue of eagerly loaded relations: [ 'relationName' => Closure|null ]
     */
    protected array $withRelations = [];

    /**
     * A queue of relation aggregate operations to run after each find.
     *
     * Each entry: [
     *   'relation'   => string,   // relation key
     *   'function'   => string,   // COUNT | SUM | AVG | MAX | MIN | EXISTS | '' (raw)
     *   'column'     => string,   // column name or '*'
     *   'attribute'  => string,   // entity attribute to stamp the result into
     *   'constraint' => ?Closure, // optional query constraint
     *   'expression' => string,   // raw SQL expression (only when function === '')
     * ]
     */
    protected array $withAggregates = [];

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
     * Check if there are eager relations or aggregate operations queued.
     * Used by findMany() to decide whether to bypass the identity-map cache.
     */
    public function hasWith(): bool
    {
        return (bool) ($this->withRelations or $this->withAggregates);
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
     * Eager-load translations for entities with translatable fields.
     *
     * withTranslations()            — load all locales
     * withTranslations('pl')        — load only Polish
     * withTranslations(['pl','fr']) — load Polish and French
     */
    public function withTranslations(string|array|null $locales = null): static
    {
        if (empty($this->fields->getTranslatable())) {
            return $this;
        }

        $key = Entity::TRANSLATION_KEY;

        if ($locales === null) {
            return $this->with([$key]);
        }

        $locales      = (array) $locales;
        $config       = $this->registry->getConfig($this->alias)['translation'] ?? [];
        $localeColumn = $config['locale_column'] ?? 'locale';

        return $this->with([
            $key => function (BaseBuilder $builder) use ($locales, $localeColumn) {
                if (count($locales) === 1) {
                    $builder->where($localeColumn, $locales[0]);
                } else {
                    $builder->whereIn($localeColumn, $locales);
                }
            },
        ]);
    }

    /**
     * Queue a COUNT aggregate for a relation.
     * Stamps `{relation}_count` (or $as) onto each entity as a virtual attribute.
     *
     *   ->withCount('posts')                  // → posts_count
     *   ->withCount('posts', 'total_posts')   // → total_posts
     */
    public function withCount(string $relation, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'COUNT', '*', $as ?? "{$relation}_count", $constraint);
    }

    /**
     * Queue a SUM aggregate for a relation column.
     * Stamps `{relation}_sum_{column}` (or $as) onto each entity.
     *
     *   ->withSum('orders', 'total')
     *   ->withSum('orders', 'total', 'revenue')
     */
    public function withSum(string $relation, string $column, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'SUM', $column, $as ?? "{$relation}_sum_{$column}", $constraint);
    }

    /**
     * Queue an AVG aggregate for a relation column.
     * Stamps `{relation}_avg_{column}` (or $as) onto each entity.
     */
    public function withAvg(string $relation, string $column, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'AVG', $column, $as ?? "{$relation}_avg_{$column}", $constraint);
    }

    /**
     * Queue a MAX aggregate for a relation column.
     * Stamps `{relation}_max_{column}` (or $as) onto each entity.
     */
    public function withMax(string $relation, string $column, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'MAX', $column, $as ?? "{$relation}_max_{$column}", $constraint);
    }

    /**
     * Queue a MIN aggregate for a relation column.
     * Stamps `{relation}_min_{column}` (or $as) onto each entity.
     */
    public function withMin(string $relation, string $column, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'MIN', $column, $as ?? "{$relation}_min_{$column}", $constraint);
    }

    /**
     * Queue an EXISTS check for a relation.
     * Stamps `{relation}_exists` (or $as) as a bool onto each entity.
     *
     *   ->withExists('posts')                  // → posts_exists (bool)
     *   ->withExists('posts', 'has_posts')     // → has_posts (bool)
     */
    public function withExists(string $relation, ?string $as = null, ?\Closure $constraint = null): self
    {
        return $this->queueAggregate($relation, 'EXISTS', '*', $as ?? "{$relation}_exists", $constraint);
    }

    /**
     * Queue a raw aggregate expression for a relation.
     * The $expression is injected verbatim into the SELECT (e.g. 'SUM(price * quantity)').
     * The result is stamped as $as on each entity.
     *
     *   ->withAggregate('lines', 'SUM(price * quantity)', 'order_total')
     */
    public function withAggregate(string $relation, string $expression, string $as, ?\Closure $constraint = null): self
    {
        if (!isset($this->relations[$relation])) {
            throw EntityException::undefinedRelation($relation);
        }

        $this->withAggregates[] = [
            'relation'   => $relation,
            'function'   => '',
            'column'     => '',
            'attribute'  => $as,
            'constraint' => $constraint,
            'expression' => $expression,
        ];

        return $this;
    }

    /**
     * Get the relation fields
     *
     * @return array<string, RelationInterface>
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
     * Run all queued aggregate operations and stamp results onto each entity.
     * Called automatically by findAll() and first() after entity hydration.
     */
    protected function loadAggregates(array $entities): void
    {
        if (empty($this->withAggregates)) {
            return;
        }

        $aggregates = $this->withAggregates;

        $this->withAggregates = [];

        if (empty($entities)) {
            return;
        }

        foreach ($aggregates as $aggregate) {
            $this->resolveAggregate($entities, $aggregate);
        }
    }

    /**
     * Execute one aggregate and stamp results onto entities as virtual attributes.
     * Delegates all SQL logic to the relation class via RelationInterface::aggregate().
     */
    protected function resolveAggregate(array $entities, array $aggregate): void
    {
        $attribute  = $aggregate['attribute'];
        $function   = $aggregate['function'];
        $column     = $aggregate['column'];
        $constraint = $aggregate['constraint'];
        $rawExpr    = $aggregate['expression'];

        $relation     = $this->fields->getRelation($aggregate['relation']);
        $entityAttrKey = $relation->getAggregateKey();

        // Build the aggregate SQL expression
        if ($rawExpr !== '') {
            $expression = $rawExpr;
        } elseif ($function === 'COUNT' and $column === '*') {
            $expression = 'COUNT(*)';
        } elseif ($function === 'EXISTS') {
            $expression = 'COUNT(*)';
        } else {
            $expression = "{$function}({$relation->getTable()}.{$column})";
        }

        $default   = ($function === 'EXISTS') ? false : 0;
        $lookupIds = [];

        foreach ($entities as $entity) {
            $id = $entity->getAttribute($entityAttrKey);

            if ($id !== null and $id !== '') {
                $lookupIds[] = $id;
            }
        }

        $lookupIds = array_values(array_unique($lookupIds));

        if (empty($lookupIds)) {
            foreach ($entities as $entity) {
                $entity->setAttribute($attribute, $default);
                $entity->flushChanges();
            }

            return;
        }

        $map = $relation->aggregate($lookupIds, $expression, $attribute, $this->alias, $constraint);

        foreach ($entities as $entity) {
            $id    = (string) $entity->getAttribute($entityAttrKey);
            $value = $map[$id] ?? $default;

            if ($function === 'EXISTS') {
                $value = (bool) $value;
            } elseif ($function === 'COUNT') {
                $value = (int) $value;
            } elseif ($function !== '' and is_numeric($value)) {
                // Named functions (SUM, AVG, MAX, MIN) → float.
                // Raw expressions (withAggregate) are left as-is to preserve type and precision.
                $value = (float) $value;
            }

            $entity->setAttribute($attribute, $value);
            $entity->flushChanges();
        }
    }

    /**
     * Validate and push an aggregate entry onto the queue.
     */
    protected function queueAggregate(string $relation, string $function, string $column, string $as, ?\Closure $constraint): self
    {
        if (!isset($this->relations[$relation])) {
            throw EntityException::undefinedRelation($relation);
        }

        $this->withAggregates[] = [
            'relation'   => $relation,
            'function'   => $function,
            'column'     => $column,
            'attribute'  => $as,
            'constraint' => $constraint,
            'expression' => '',
        ];

        return $this;
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