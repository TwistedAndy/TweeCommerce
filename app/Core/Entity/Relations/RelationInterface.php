<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

interface RelationInterface
{
    public function getType(): string;

    public function getTable(): string;

    public function getConfig(): array;

    /**
     * Fetch the relation for a single entity natively
     */
    public function get(EntityInterface $entity): EntityInterface|array|null;

    /**
     * Update/Save the relationship data
     */
    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void;

    /**
     * Remove/Detach the relationship entirely
     */
    public function remove(int|string|EntityInterface|null $localEntity, string $localAlias): void;

    /**
     * Resolve a value into a singular entity or an array with entities
     */
    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null;


    /**
     * Apply the appropriate LEFT JOIN(s) for this relation to the given builder.
     * This is the JOIN-time counterpart of query(), which builds WHERE clauses for lazy loading.
     */
    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void;

    /**
     * Get a configured Model Instance proxying the Builder for this relation.
     *
     * This method modifies the internal Query Builder state of the related model.
     * It must be immediately followed by a terminal operation (findAll(), first()),
     * which will null the builder after executing.
     */
    public function query(EntityInterface $localEntity): EntityModel;

    /**
     * Fill entities with the relation data
     */
    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void;

    /**
     * Run an aggregate query for the given parent-side IDs.
     *
     * Returns a flat map of [ (string) parent_id => aggregate_value ] suitable for
     * stamping directly onto the parent entities as virtual attributes.
     *
     * @param array    $lookupIds  IDs collected from the parent entities (via getAggregateKey())
     * @param string   $expression  Ready-to-use SQL expression: COUNT(*), SUM(table.col), …
     * @param string   $resultAlias SQL column alias for the aggregate value (e.g. 'posts_count').
     *                              Using the destination attribute name keeps query logs readable and
     *                              guarantees a unique alias when multiple aggregates share one query.
     * @param string   $localAlias  Alias of the owning entity (required by pivot/morph relations)
     * @param ?\Closure $constraint Optional extra WHERE constraints applied to the inner query
     *
     * @return array<string, mixed>
     */
    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array;

    /**
     * Return the attribute name on the local entity whose value is used as the lookup key
     * when matching aggregate results back to their parent entities.
     *
     * Defaults to local_key (the parent PK) for most relation types.
     * BelongsOneRelation overrides this to return foreign_key instead.
     */
    public function getAggregateKey(): string;

    /**
     * Cascade a delete operation to one or more parent entity IDs.
     *
     * @param bool $purge true = hard-delete, false = soft-delete (if the related model supports it)
     */
    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void;

    /**
     * Cascade a restore operation to one or more parent entity IDs.
     */
    public function cascadeRestore(array $localIds, string $localAlias = ''): void;
}