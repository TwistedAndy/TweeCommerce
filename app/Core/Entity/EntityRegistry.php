<?php

namespace App\Core\Entity;

use \App\Core\Container\Container;
use \App\Core\Meta\MetaModel;
use \App\Core\Meta\Meta;
use \Config\Entities;

class EntityRegistry
{
    protected array     $config;
    protected array     $models = [];
    protected array     $fields = [];
    protected Container $container;

    public function __construct(Entities $config, Container $container)
    {
        $this->container = $container;

        foreach ($config->entities as $alias => $config) {
            $this->registerEntity($alias, $config);
        }
    }

    /**
     * Register a new entity type
     *
     * @param string $alias
     * @param array<string, array{
     *   entity:    class-string<EntityInterface>,
     *   table:     string,
     *   db_group?: string,
     *   pivots?:   array<string, string>,
     * }> $config
     *
     * @return void
     */
    public function registerEntity(string $alias, array $config): void
    {
        if (empty($config['entity']) or !is_string($config['entity']) or !is_a($config['entity'], EntityInterface::class, true)) {
            throw EntityException::invalidEntityClass(is_string($config['entity']) ? $config['entity'] : '');
        }

        $this->fields[$alias] = $config['entity']::initEntity($this->container);

        if (empty($config['model'])) {
            if (is_a($config['entity'], Meta::class, true)) {
                $config['model'] = MetaModel::class;
            } else {
                $config['model'] = EntityModel::class;
            }
        } elseif (!is_string($config['model']) or !is_a($config['model'], EntityModel::class, true)) {
            throw EntityException::invalidModelClass(is_string($config['model']) ? $config['model'] : '');
        }

        if (empty($config['table'])) {
            throw EntityException::missingTable($alias);
        }

        $this->config[$alias] = $config;
    }

    /**
     * Get the database group
     *
     * @param string $alias
     *
     * @return string|null
     */
    public function getDatabaseGroup(string $alias): ?string
    {
        return $this->config[$alias]['db_group'] ?? null;
    }

    /**
     * Get the Entity Model object
     */
    public function getModel(string $alias, bool $getShared = true): EntityModel
    {
        if ($getShared and !empty($this->models[$alias])) {
            return $this->models[$alias];
        }

        $config = $this->getConfig($alias);

        $model = $this->container->make($config['model'], [
            'alias'    => $alias,
            'registry' => $this
        ], static::class);

        if ($getShared) {
            $this->models[$alias] = $model;
        }

        return $model;
    }

    /**
     * Get the Entity table
     */
    public function getEntityFields(string $alias): ?EntityFields
    {
        return $this->fields[$alias] ?? null;
    }

    /**
     * Get the Entity table
     */
    public function getEntityTable(string $alias): string
    {
        $config = $this->getConfig($alias);

        return $config['table'];
    }

    /**
     * Get the Entity class by alias
     *
     * @param string $alias
     *
     * @return class-string<EntityInterface> $entityClass
     */
    public function getEntityClass(string $alias): string
    {
        $config = $this->getConfig($alias);

        return $config['entity'];
    }

    /**
     * Get the Pivot Table name
     *
     * @return array{
     *   table:          string,
     *   local_column:   string,
     *   foreign_column: string
     * }
     */
    public function getPivotConfig(string $localAlias, string $relatedAlias): array
    {
        $config = $this->getConfig($localAlias);

        if (empty($config['pivots']) or empty($config['pivots'][$relatedAlias])) {
            throw EntityException::pivotNotDefined($relatedAlias);
        }

        $pivotConfig = $config['pivots'][$relatedAlias];

        if (empty($pivotConfig['table'])) {
            throw EntityException::missingPivotTable($localAlias, $relatedAlias);
        }

        if (empty($pivotConfig['local_column']) or !is_string($pivotConfig['local_column'])) {
            throw EntityException::missingPivotColumn($localAlias, $relatedAlias, 'local');
        }

        if (empty($pivotConfig['foreign_column']) or !is_string($pivotConfig['foreign_column'])) {
            throw EntityException::missingPivotColumn($localAlias, $relatedAlias, 'foreign');
        }

        return $config['pivots'][$relatedAlias];
    }

    /**
     * Get the entity config
     *
     * @param string $alias
     *
     * @return array{
     *    entity:    class-string<EntityInterface>,
     *    table:     string,
     *    db_group?: string,
     *    pivots?:   array<string, string>,
     *  }
     */
    public function getConfig(string $alias): array
    {
        if (empty($this->config[$alias])) {
            throw EntityException::unknownAlias($alias);
        }

        return $this->config[$alias];
    }

}