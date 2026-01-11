<?php

namespace App\Core\Entity;

use App\Core\Container\Container;
use CodeIgniter\DataCaster\Cast\CastInterface;

/**
 * Read-only configuration object for an Entity class.
 * Holds cached reflection data, services, and field definitions.
 */
class EntitySchema
{
    public readonly EntityCaster $caster;
    public readonly array        $defaults;
    public readonly array        $fields;

    /**
     * Create a new Entity Schema object
     *
     * @see https://codeigniter.com/user_guide/models/model.html#custom-casting,
     *      https://codeigniter.com/user_guide/libraries/validation.html#setting-validation-rules
     *
     * @param array<string, array{
     *   primary?: (bool),         // Primary key flag
     *   label?:   (string),       // Field label
     *   default?: (mixed),        // Default value
     *   type?:    (string),       // Casting type
     *   rules?:   (string|array), // Validation rules
     *   caster?:  (string)        // Caster class name
     * }> $schema
     * @param Container|null $container
     */
    public function __construct(array $schema, ?Container $container = null)
    {
        if ($container === null) {
            $container = Container::getInstance();
        }

        $entityFields   = $schema;
        $entityDefaults = [];
        $entityCasts    = [];
        $fieldDefaults  = [];
        $castHandlers   = [];
        $hasPrimaryKey  = false;

        foreach ($entityFields as $key => $field) {

            if (empty($field['type'])) {
                $field['type'] = 'text';
            } elseif (!is_string($field['type'])) {
                throw new EntityException('A provided type should be a valid cast string');
            }

            $entityCasts[$key] = $field['type'];

            if (array_key_exists('default', $field)) {
                $entityDefaults[$key] = $field['default'];
            } else {
                $fieldDefaults[$key] = '';
            }

            if (empty($field['label'])) {
                $field['label'] = ucwords(str_replace('_', ' ', $key));
            }

            if (!empty($field['caster'])) {
                if (is_string($field['caster']) and is_subclass_of($field['caster'], CastInterface::class)) {
                    $castHandlers[$field['type']] = $field['caster'];
                } else {
                    throw new EntityException('A provided caster should be a implement the CastInterface interface');
                }
            }

            if (!empty($field['primary'])) {
                if ($hasPrimaryKey) {
                    throw new EntityException('There should be only one field marked as a primary key');
                }

                $hasPrimaryKey = true;
            }

            if (array_key_exists('rules', $field)) {
                if (is_string($field['rules'])) {
                    $field['rules'] = [
                        'rules' => $field['rules'],
                    ];
                } elseif (is_array($field['rules']) and empty($field['rules']['rules'])) {
                    $field['rules'] = [
                        'rules' => $field['rules'],
                    ];
                }

                if (!is_array($field['rules']) or empty($field['rules']['rules'])) {
                    throw new EntityException("Failed to initialize field rules for '{$key}'");
                }

                if (array_key_exists('errors', $field)) {
                    $field['rules']['errors'] = $field['errors'];
                    unset($field['errors']);
                }
            }

            $entityFields[$key] = $field;
        }

        if (!$hasPrimaryKey) {
            throw new EntityException('No primary key is specified for an entity');
        }

        $entityCaster = $container->make(EntityCaster::class, [
            'casts'        => $entityCasts,
            'castHandlers' => $castHandlers,
        ], static::class);

        if ($fieldDefaults) {
            foreach ($fieldDefaults as $key => $value) {
                $value = $entityCaster->toStorage($key, $value);

                $entityFields[$key]['default'] = $value;
                $entityDefaults[$key]          = $value;
            }
        }

        $this->defaults = $entityDefaults;
        $this->caster   = $entityCaster;
        $this->fields   = $entityFields;
    }

    /**
     * Prepare data for serialization
     */
    public function __serialize(): array
    {
        return [
            'caster'   => $this->caster,
            'defaults' => $this->defaults,
            'fields'   => $this->fields,
        ];
    }

    /**
     * Restore state after serialization
     */
    public function __unserialize(array $data): void
    {
        $this->caster   = $data['caster'];
        $this->defaults = $data['defaults'];
        $this->fields   = $data['fields'];
    }
}