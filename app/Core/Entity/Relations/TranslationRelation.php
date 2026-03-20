<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\Entity;
use App\Core\Entity\EntityException;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Handles lazy/eager loading, saving, and cascade-deleting translations for entities with
 * translatable fields. Queries are issued through the local entity's model builder — the same
 * pattern used by BelongsManyRelation for pivot tables. Registered as the 'translation'
 * relation type in EntityFields::RELATION_TYPES.
 */
class TranslationRelation extends AbstractRelation
{
    protected array $configCache = [];

    public function __construct(string $name, array $relation, EntityRegistry $registry)
    {
        parent::__construct($name, $relation, $registry);
    }

    private function resolveConfig(string $alias): array
    {
        return $this->configCache[$alias] ??= $this->registry->getConfig($alias)['translation'] ?? [];
    }

    public function getType(): string
    {
        return 'translation';
    }

    /**
     * Load translations for a single entity, injecting them via setTranslation().
     *
     * If the entity has an active locale set, only that locale is queried (per-locale lazy load).
     * If locale is empty, all locales are loaded.
     *
     * Always calls setTranslation even on a miss so subsequent access does not re-query.
     *
     * @return array<string, array<string, mixed>>  locale → field data (no row IDs)
     */
    public function get(EntityInterface $entity): array
    {
        $alias  = $entity->getAlias();
        $config = $this->resolveConfig($alias);
        $table  = $config['table'] ?? '';

        if (empty($table)) {
            return [];
        }

        $entityColumn = $config['entity_column'] ?? 'entity_id';
        $localeColumn = $config['locale_column'] ?? 'locale';
        $relatedKey   = $config['key_column'] ?? 'id';
        $localId      = $this->getLocalId($entity);

        if (empty($localId)) {
            return [];
        }

        $locale  = $entity->getLocale();
        $model   = $this->registry->getModel($alias);
        $builder = $model->builder($table)->where($entityColumn, $localId);

        if ($locale !== '') {
            $builder->where($localeColumn, $locale);
        }

        $rows = $builder->get()->getResultArray();

        [$dataMap, $idMap] = $this->hydrateRows($rows, $localeColumn, $relatedKey, $entity->getFields()->getTranslatable());

        // Embed the translation row ID into each locale's data array so the caller
        // can store it without needing a separate id map.
        $result = [];

        foreach ($dataMap as $loc => $data) {
            $result[$loc]                         = $data;
            $result[$loc][Entity::TRANSLATION_ID] = $idMap[$loc] ?? null;
        }

        // For a single-locale query always include the locale even on a miss,
        // so the caller knows it was queried and can avoid a re-query.
        if ($locale !== '' && !isset($result[$locale])) {
            $result[$locale] = [Entity::TRANSLATION_ID => null];
        }

        return $result;
    }

    /**
     * Eager-load translations for a batch of entities in a single query.
     */
    public function eagerLoad(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $alias  = $entities[0]->getAlias();
        $config = $this->resolveConfig($alias);
        $table  = $config['table'] ?? '';

        if (empty($table)) {
            return;
        }

        $entityColumn = $config['entity_column'] ?? 'entity_id';
        $localeColumn = $config['locale_column'] ?? 'locale';
        $relatedKey   = $config['key_column'] ?? 'id';
        $translatable = $entities[0]->getFields()->getTranslatable();

        // Single pass: collect IDs and group entities by ID simultaneously
        $entityMap = [];

        foreach ($entities as $entity) {
            $localId = $entity->getAttribute($this->localKey);

            if ($localId !== null and $localId !== '') {
                $entityMap[$localId][] = $entity;
            }
        }

        if (empty($entityMap)) {
            return;
        }

        $model   = $this->registry->getModel($alias);
        $builder = $model->builder($table)->whereIn($entityColumn, array_keys($entityMap));

        if ($dynamicConstraint) {
            $dynamicConstraint($builder);
        }

        $rows = $builder->get()->getResultArray();

        $grouped = [];

        foreach ($rows as $row) {
            $id = $row[$entityColumn] ?? null;

            if ($id !== null) {
                $grouped[$id][] = $row;
            }
        }

        foreach ($entityMap as $localId => $group) {
            [$dataMap, $idMap] = $this->hydrateRows($grouped[$localId] ?? [], $localeColumn, $relatedKey, $translatable);

            foreach ($group as $entity) {
                foreach ($dataMap as $locale => $data) {
                    $entity->setTranslation($locale, $data, $idMap[$locale] ?? null);
                }
            }
        }
    }

    /**
     * Save changed translations for a single entity.
     */
    public function update(EntityInterface $localEntity, array|null|EntityInterface $relatedData): void
    {
        $localId = $localEntity->getAttribute($this->localKey);

        if (empty($localId) or !is_array($relatedData)) {
            return;
        }

        $alias  = $localEntity->getAlias();
        $config = $this->resolveConfig($alias);
        $table  = $config['table'] ?? '';

        if (empty($table)) {
            return;
        }

        $entityColumn = $config['entity_column'] ?? 'entity_id';
        $localeColumn = $config['locale_column'] ?? 'locale';
        $primaryKey   = $config['key_column'] ?? 'id';

        $translatable = $localEntity->getFields()->getTranslatable();
        $model        = $this->registry->getModel($alias);

        // Collects locale => new row ID for any INSERTs performed in this pass.
        $newIds = [];

        // Process inserts, updates, and explicit deletions based strictly on the delta
        foreach ($relatedData as $locale => $values) {
            if (!is_string($locale)) {
                continue;
            }

            // Explicit locale deletion: setAttribute('title', null) with locale set to 'es'
            if ($values === null) {
                $model->builder($table)
                    ->where($entityColumn, $localId)
                    ->where($localeColumn, $locale)
                    ->delete();
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            $row = [
                $entityColumn => $localId,
                $localeColumn => $locale,
            ];

            foreach ($translatable as $field => $flag) {
                if (array_key_exists($field, $values)) {
                    $row[$field] = $values[$field];
                }
            }

            $translationId = $localEntity->getTranslationId($locale);

            if ($translationId) {
                $model->builder($table)
                    ->where($primaryKey, $translationId)
                    ->update($row);
            } else {
                $model->builder($table)->insert($row);

                $newIds[$locale] = $model->getInsertID();
            }
        }

        // Inject newly generated row IDs back so subsequent saves use UPDATE, not INSERT.
        if ($newIds) {
            foreach ($newIds as $locale => $newId) {
                $values = $relatedData[$locale];

                $stored = [];

                foreach ($translatable as $field => $flag) {
                    if (array_key_exists($field, $values)) {
                        $stored[$field] = $values[$field];
                    }
                }

                $localEntity->setTranslation($locale, $stored, $newId);
            }
        }
    }

    /**
     * Delete all translation rows for the given entity IDs.
     */
    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (empty($localIds) or !$purge) {
            return;
        }

        $config = $this->resolveConfig($localAlias);
        $table  = $config['table'] ?? '';

        if (empty($table)) {
            return;
        }

        $entityColumn = $config['entity_column'] ?? 'entity_id';

        $model = $this->registry->getModel($localAlias);

        $model->builder($table)
            ->whereIn($entityColumn, $localIds)
            ->delete();
    }

    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        return is_array($value) ? $value : [];
    }

    public function join(BaseBuilder $builder, string $localTable, string $localAlias, BaseConnection $db, string $column = ''): void
    {
        throw EntityException::unsupportedType('translation', 'join');
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        throw EntityException::unsupportedType('translation', 'query');
    }

    /**
     * Convert raw DB rows into a field data map and a separate ID map.
     *
     * @param array[] $rows
     * @param string $localeColumn Column holding the locale code
     * @param string $relatedKey   Primary key column of the translation table
     * @param array $translatable  Translatable field names from EntityFields::getTranslatable()
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, int|string|null>}
     *         [locale => fieldData, ...], [locale => rowId, ...]
     */
    protected function hydrateRows(array $rows, string $localeColumn, string $relatedKey, array $translatable): array
    {
        $dataMap = [];
        $idsMap  = [];

        foreach ($rows as $row) {
            $locale = $row[$localeColumn] ?? '';

            if ($locale === '') {
                continue;
            }

            $idsMap[$locale] = $row[$relatedKey] ?? null;

            $fields = [];

            foreach ($translatable as $field => $flag) {
                if (array_key_exists($field, $row)) {
                    $fields[$field] = $row[$field];
                }
            }

            $dataMap[$locale] = $fields;
        }

        return [$dataMap, $idsMap];
    }
}
