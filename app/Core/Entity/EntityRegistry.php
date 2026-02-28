<?php

namespace App\Core\Entity;

use \App\Core\Container\Container;
use \Config\Database;
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
            throw new EntityException('Specified entity class does not exist or does not implement EntityInterface.');
        }

        $this->fields[$alias] = $config['entity']::initEntity($this->container);

        if (empty($config['model'])) {
            $config['model'] = EntityModel::class;
        } elseif (!is_string($config['model']) or !is_a($config['model'], EntityModel::class, true)) {
            throw new EntityException('Entity model should extend the ' . EntityModel::class . ' class.');
        }

        if (empty($config['table'])) {
            throw new EntityException('Entity table is required for an ' . $alias . ' entity.');
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
		return $this->config[$alias]['databaseGroup'] ?? null;
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
     * @param string $localAlias
     * @param string $relatedAlias
     *
     * @return string
     */
    public function getPivotTable(string $localAlias, string $relatedAlias): string
    {
        $config = $this->getConfig($localAlias);

        if (empty($config['pivots']) or empty($config['pivots'][$relatedAlias])) {
            return '';
        }

        return $config['pivots'][$relatedAlias];
    }

    /**
     * Get the entity config
     *
     * @param string $alias
     *
     * @return array
     */
    protected function getConfig(string $alias): array
    {
        if (empty($this->config[$alias])) {
            throw new EntityException('Entity type not defined for the ' . $alias . ' entity.');
        }

        return $this->config[$alias];
    }

}