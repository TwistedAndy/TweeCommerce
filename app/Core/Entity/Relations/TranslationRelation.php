<?php

namespace App\Core\Entity\Relations;

use App\Core\Entity\Entity;
use App\Core\Entity\EntityException;
use App\Core\Entity\EntityInterface;
use App\Core\Entity\EntityModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * Handles lazy/eager loading, saving, and cascade-deleting translations for entities with
 * translatable fields. Queries are issued through the local entity's model builder — the same
 * pattern used by BelongsManyRelation for pivot tables. Registered as the 'translation'
 * relation type in EntityFields::RELATION_TYPES.
 */
class TranslationRelation extends AbstractRelation
{
    protected string $translationTable = '';
    protected string $entityColumn     = '';
    protected string $localeColumn     = '';
    protected string $keyColumn        = '';

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
        $alias = $entity->getAlias();

        if ($this->translationTable === '' and !$this->initConfig($alias)) {
            return [];
        }

        $localId = $this->getLocalId($entity);

        if (empty($localId)) {
            return [];
        }

        $locale  = $entity->getLocale();
        $model   = $this->registry->getModel($alias);
        $builder = $model->builder($this->translationTable)->where($this->entityColumn, $localId);

        if ($locale !== '') {
            $builder->where($this->localeColumn, $locale);
        }

        $rows   = $builder->get()->getResultArray();
        $result = [];

        foreach ($rows as $row) {
            $rowLocale = $row[$this->localeColumn] ?? '';

            if ($rowLocale === '') {
                continue;
            }

            $result[$rowLocale] = [Entity::TRANSLATION_ID => $row[$this->keyColumn] ?? null] + $row;
        }

        // For a single-locale query always include the locale even on a miss,
        // so the caller knows it was queried and can avoid a re-query.
        if ($locale !== '' and !isset($result[$locale])) {
            $result[$locale] = [Entity::TRANSLATION_ID => null];
        }

        return $result;
    }

    /**
     * Eager-load translations for a batch of entities in a single query.
     *
     * @param EntityInterface[] $entities
     * @param \Closure|null $dynamicConstraint
     */
    public function preload(array $entities, ?\Closure $dynamicConstraint = null): void
    {
        if (empty($entities)) {
            return;
        }

        $alias = $entities[0]->getAlias();

        if ($this->translationTable === '' and !$this->initConfig($alias)) {
            return;
        }

        $entityMap = [];

        foreach ($entities as $entity) {
            $localId = $entity->getAttribute($this->localKey);

            if ($localId !== null and $localId !== '') {
                $entityMap[$localId] = $entity;
            }
        }

        if (empty($entityMap)) {
            return;
        }

        $model   = $this->registry->getModel($alias);
        $builder = $model->builder($this->translationTable)->whereIn($this->entityColumn, array_keys($entityMap));

        if ($dynamicConstraint) {
            $dynamicConstraint($builder);
        }

        $rows = $builder->get()->getResultArray();

        foreach ($rows as $row) {
            $localId   = $row[$this->entityColumn] ?? null;
            $rowLocale = $row[$this->localeColumn] ?? '';

            if ($localId === null or $rowLocale === '' or !isset($entityMap[$localId])) {
                continue;
            }

            $entityMap[$localId]->setTranslation($rowLocale, $row, $row[$this->keyColumn] ?? null);
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

        $alias = $localEntity->getAlias();

        if ($this->translationTable === '' and !$this->initConfig($alias)) {
            return;
        }

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
                $model->builder($this->translationTable)
                    ->where($this->entityColumn, $localId)
                    ->where($this->localeColumn, $locale)
                    ->delete();
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            $row = [
                    $this->entityColumn => $localId,
                    $this->localeColumn => $locale,
                ] + array_intersect_key($values, $translatable);

            $translationId = $localEntity->getTranslationId($locale);

            if ($translationId) {
                $model->builder($this->translationTable)
                    ->where($this->keyColumn, $translationId)
                    ->update($row);
            } else {
                $model->builder($this->translationTable)->insert($row);

                $newIds[$locale] = $model->getInsertID();
            }
        }

        // Inject newly generated row IDs back so subsequent saves use UPDATE, not INSERT.
        // Merge the delta onto the full existing translation to avoid losing unchanged fields.
        if ($newIds) {
            foreach ($newIds as $locale => $newId) {
                $merged = array_merge($localEntity->getTranslation($locale) ?? [], array_intersect_key($relatedData[$locale], $translatable));

                $merged[Entity::TRANSLATION_ID] = $newId;
                $localEntity->setTranslation($locale, $merged);
            }
        }
    }

    public function resolve(int|string|array|EntityInterface|null $value): EntityInterface|array|null
    {
        return is_array($value) ? $value : [];
    }

    public function query(EntityInterface $localEntity): EntityModel
    {
        $alias = $localEntity->getAlias();

        if ($this->translationTable === '' and !$this->initConfig($alias)) {
            throw EntityException::unsupportedType('translation', 'query');
        }

        $model   = $this->registry->getModel($alias);
        $builder = $model->builder($this->translationTable);
        $builder->where($this->entityColumn, $this->getLocalId($localEntity));

        return $model;
    }

    public function join(BaseBuilder $builder, string $localAlias, string $column = ''): void
    {
        if ($this->translationTable === '' and !$this->initConfig($localAlias)) {
            return;
        }

        $localTable = $this->registry->getEntityTable($localAlias);

        $alias = "t_{$this->relationName}_{$column}";

        $builder->join(
            "{$this->translationTable} {$alias}",
            "{$alias}.{$this->entityColumn} = {$localTable}.{$this->localKey} AND {$alias}.{$this->localeColumn} = '{$column}'",
            'left'
        );
    }

    public function aggregate(array $lookupIds, string $expression, string $resultAlias, string $localAlias, ?\Closure $constraint): array
    {
        throw EntityException::unsupportedType('translation', 'aggregate');
    }

    /**
     * Delete all translation rows for the given entity IDs.
     */
    public function cascadeDelete(array $localIds, string $localAlias, bool $purge): void
    {
        if (empty($localIds) or !$purge) {
            return;
        }

        if ($this->translationTable === '' and !$this->initConfig($localAlias)) {
            return;
        }

        $this->registry->getModel($localAlias)
            ->builder($this->translationTable)
            ->whereIn($this->entityColumn, $localIds)
            ->delete();
    }

    /**
     * Initialize the entity config
     */
    protected function initConfig(string $alias): bool
    {
        $config = $this->registry->getConfig($alias)['translation'] ?? [];

        if (empty($config['table'])) {
            return false;
        }

        $this->translationTable = $config['table'];
        $this->entityColumn     = $config['entity_column'] ?? 'entity_id';
        $this->localeColumn     = $config['locale_column'] ?? 'locale';
        $this->keyColumn        = $config['key_column'] ?? 'id';

        return true;
    }
}
