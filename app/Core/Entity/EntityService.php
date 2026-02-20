<?php

namespace App\Core\Entity;

use App\Core\Container\Container;

class EntityService
{
    protected string $alias;

    protected string $entityTable;
    protected string $entityLabel;
    protected string $entityClass;
    protected array  $entityFields;
    protected array  $validationRules;
    protected string $dateFormat;
    protected bool   $useTimestamps;
    protected bool   $useSoftDeletes;
    protected bool   $primaryKeyType;
    protected string $primaryKeyField;

    public readonly EntityModel  $entityModel;
    public readonly EntityCaster $entityCaster;

    public function __construct(array $config, Container $container)
    {
        if (empty($this->alias)) {
            throw new EntityException('The entity service alias cannot be empty.');
        }

        if (!empty($config['entity_class'])) {
            if (!is_string($config['entity_class']) or !is_subclass_of($config['entity_class'], EntityInterface::class)) {
                throw new EntityException('The entity class should implement the EntityInterface');
            }
            $this->entityClass = $config['entity_class'];
        } else {
            $this->entityClass = Entity::class;
        }

        if (!empty($config['model_class'])) {
            if (!is_string($config['model_class']) or !is_subclass_of($config['model_class'], EntityModel::class)) {
                throw new EntityException('The model class should extend the EntityModel');
            }
            $modelClass = $config['model_class'];
        } else {
            $modelClass = EntityModel::class;
        }

        if (empty($config['entity_label']) or !is_string($config['entity_label'])) {
            $this->entityLabel = ucfirst($this->alias);
        } else {
            $this->entityLabel = $config['entity_label'];
        }

        if (empty($config['entity_table']) or !is_string($config['entity_table'])) {
            $this->entityTable = $this->alias . 's';
        } else {
            $this->entityTable = $config['entity_table'];
        }

        if (empty($config['entityGroup'])) {
            $DBGroup = null;
        } else {
            $DBGroup = $config['entityGroup'];
        }

        $schema = $this->entityClass::resolveSchema($container);

        $fields = $schema->fields;

        $validationRules = [];

        $primaryKey  = '';
        $primaryType = '';

        foreach ($fields as $key => $field) {
            if (!empty($field['primary'])) {
                $primaryKey  = $key;
                $primaryType = $field['type'];
            }

            if (!array_key_exists('rules', $field)) {
                continue;
            }

            if (!is_array($field['rules']) or empty($field['rules']['rules'])) {
                throw new EntityException("Failed to initialize field rules for '{$key}'");
            }

            $rules = $field['rules']['rules'];

            if (is_string($rules)) {
                $validationRules[$key] = str_replace('{table}', $this->entityTable, $rules);
            } elseif (is_array($rules)) {
                $validationRules[$key] = array_map(function ($rule) {
                    return str_replace('{table}', $this->entityTable, $rule);
                }, $rules);
            }
        }

        $this->primaryKeyType  = $primaryType;
        $this->primaryKeyField = $primaryKey;

        $this->entityFields    = $fields;
        $this->validationRules = $validationRules;
        $this->useSoftDeletes  = array_key_exists('deleted_at', $fields);
        $this->useTimestamps   = (!empty($fields['created_at']) or !empty($fields['updated_at']));

        $config = [
            'DBGroup'         => $DBGroup,
            'table'           => $this->entityTable,
            'primaryKey'      => $this->primaryKeyField,
            'returnType'      => $this->entityClass,
            'useSoftDeletes'  => $this->useSoftDeletes,
            'dataCaster'      => $this->entityCaster,
            'validationRules' => $this->validationRules,
            'allowedFields'   => array_diff(array_keys($fields), [$this->primaryKeyField]),
        ];

        $dateFields = [
            'created_at' => 'createdField',
            'updated_at' => 'updatedField',
            'deleted_at' => 'deletedField'
        ];

        foreach ($dateFields as $key => $field) {
            if (empty($fields[$key])) {
                $config[$field] = '';
            } else {
                $config[$field] = $key;
            }
        }

        if (!empty($fields['created_at']) and str_contains($fields['created_at']['type'], 'datetime')) {
            $this->dateFormat = 'datetime';
        } elseif (!empty($fields['updated_at']) and str_contains($fields['updated_at']['type'], 'datetime')) {
            $this->dateFormat = 'datetime';
        } else {
            $this->dateFormat = 'int';
        }

        $config['dateFormat']    = $this->dateFormat;
        $config['useTimestamps'] = $this->useTimestamps;

        $this->entityModel = $container->make($modelClass, [], static::class);

        $this->entityModel->configure($config);
    }

}