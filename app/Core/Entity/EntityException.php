<?php

namespace App\Core\Entity;

class EntityException extends \RuntimeException
{
    // -------------------------------------------------------------------------
    // Field definition  (100–199)
    // -------------------------------------------------------------------------

    public static function noPrimaryKey(string $entity = ''): self
    {
        $msg = $entity
            ? "Entity '{$entity}' requires exactly one field marked as primary key."
            : 'Entity definition requires exactly one field marked as primary key.';

        return new self($msg, 101);
    }

    public static function multiplePrimaryKeys(string $existing, string $duplicate): self
    {
        return new self("Only one primary key is allowed per entity; '{$existing}' is already the primary key, cannot mark '{$duplicate}' as primary too.", 102);
    }

    public static function duplicateField(string $key): self
    {
        return new self("Field '{$key}' is already defined.", 103);
    }

    public static function invalidCastType(string $key): self
    {
        return new self("Field '{$key}': cast type must be a string.", 104);
    }

    public static function invalidCastHandler(string $cast): self
    {
        return new self("Cast handler '{$cast}' must implement CastInterface.", 105);
    }

    public static function invalidFieldRules(string $key): self
    {
        return new self("Field '{$key}': rules must be a string or an array with a 'rules' key.", 106);
    }

    public static function missingRelationConfig(string $key): self
    {
        return new self("Field '{$key}': relation-type fields require a 'relation' configuration array.", 107);
    }

    // -------------------------------------------------------------------------
    // Relation definition  (200–299)
    // -------------------------------------------------------------------------

    public static function duplicateRelation(string $key): self
    {
        return new self("Relation '{$key}' is already defined.", 201);
    }

    public static function undefinedRelation(string $key): self
    {
        return new self("Relation '{$key}' is not defined.", 202);
    }

    public static function missingRelationAlias(string $key): self
    {
        return new self("Relation '{$key}': a related entity alias is required.", 203);
    }

    public static function missingRelationType(string $key): self
    {
        return new self("Relation '{$key}': a type is required.", 204);
    }

    public static function unsupportedRelationType(string $key, string $type): self
    {
        return new self("Relation '{$key}': unsupported type '{$type}'. Supported: has-one, has-many, belongs-one, belongs-many, meta.", 205);
    }

    public static function closureCallback(string $key): self
    {
        return new self("Relation '{$key}': constraint callbacks cannot be Closures; use a named function, static method, or [class, method] array.", 206);
    }

    public static function invalidLocalKey(string $key, string $localKey): self
    {
        return new self("Relation '{$key}': local key '{$localKey}' does not exist in the entity fields.", 207);
    }

    public static function invalidForeignKey(string $key, string $foreignKey): self
    {
        return new self("Relation '{$key}': foreign key '{$foreignKey}' does not exist in the entity fields.", 208);
    }

    // -------------------------------------------------------------------------
    // Registry  (300–399)
    // -------------------------------------------------------------------------

    public static function unknownAlias(string $alias): self
    {
        return new self("No entity is registered with alias '{$alias}'.", 301);
    }

    public static function invalidEntityClass(string $class = ''): self
    {
        $msg = $class
            ? "Entity class '{$class}' does not exist or does not implement EntityInterface."
            : 'Entity class does not exist or does not implement EntityInterface.';

        return new self($msg, 302);
    }

    public static function invalidModelClass(string $class = ''): self
    {
        $msg = $class
            ? "Model class '{$class}' does not extend EntityModel."
            : 'Entity model must extend EntityModel.';

        return new self($msg, 303);
    }

    public static function missingTable(string $alias): self
    {
        return new self("Entity '{$alias}' must specify a table name.", 304);
    }

    public static function missingPivotTable(string $local, string $related): self
    {
        return new self("Pivot table is not configured for the '{$local}' → '{$related}' relation.", 305);
    }

    public static function missingPivotColumn(string $local, string $related, string $which): self
    {
        return new self("Pivot {$which} column is not configured for the '{$local}' → '{$related}' relation.", 306);
    }

    // -------------------------------------------------------------------------
    // CRUD operations  (400–499)
    // -------------------------------------------------------------------------

    public static function operationFailed(string $op, \Throwable $previous): self
    {
        return new self("Failed to {$op} entity: {$previous->getMessage()}", 401, $previous);
    }

    // -------------------------------------------------------------------------
    // Relation runtime  (500–599)
    // -------------------------------------------------------------------------

    public static function unsupportedType(string $type, string $context): self
    {
        return new self("Relation type '{$type}' is not supported for {$context}.", 501);
    }

    public static function pivotNotDefined(string $relation): self
    {
        return new self("No pivot configuration is defined for the '{$relation}' relation.", 502);
    }

    public static function relatedNotFound(string $class, int|string $id): self
    {
        return new self("Could not find {$class} with ID {$id}.", 503);
    }

    public static function parentNotSaved(string $relation = ''): self
    {
        $message = $relation
            ? "The parent entity must be saved before the '{$relation}' meta relation can be updated."
            : 'The parent entity must be saved before its meta relation can be updated.';

        return new self($message, 504);
    }

    public static function invalidCallback(string $relation = ''): self
    {
        $msg = $relation
            ? "Relation '{$relation}': constraint callback must be a model method name or a valid PHP callable."
            : 'Relation callback must be a model method name or a valid PHP callable.';

        return new self($msg, 505);
    }

    // -------------------------------------------------------------------------
    // Relation resolution  (600–699)
    // -------------------------------------------------------------------------

    public static function typeMismatch(string $expected, string $actual): self
    {
        return new self("Expected {$expected}, got {$actual}.", 601);
    }

    public static function sequentialArray(string $relation, string $class): self
    {
        return new self("Relation '{$relation}': expected a single ID, associative array, or {$class} instance; a sequential array was given.", 602);
    }

    public static function invalidValue(string $relation, string $class): self
    {
        return new self("Relation '{$relation}': expected an ID, associative array, or {$class} instance.", 603);
    }

    public static function invalidManyValue(string $relation, string $class): self
    {
        return new self("Relation '{$relation}': expected an array of IDs, associative arrays, or {$class} instances.", 604);
    }

    public static function invalidItem(string $relation, string $class): self
    {
        return new self("Relation '{$relation}': each item must be an ID, associative array, or {$class} instance.", 605);
    }
}
