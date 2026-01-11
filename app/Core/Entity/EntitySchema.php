<?php

namespace App\Core\Entity;

use CodeIgniter\DataCaster\Cast\CastInterface;

/**
 * Read-only configuration object for an Entity class.
 * Holds cached reflection data, services, and field definitions.
 */
class EntitySchema
{
    public readonly EntityCaster $caster;

    public readonly string $primaryKey;
    public readonly array  $fields;
    public readonly array  $defaults;
    public readonly array  $nullable;
    public readonly array  $casts;
    public readonly array  $castParams;
    public readonly array  $castHandlers;
    public readonly array  $dateFormats;

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
     *   type?:    (string),       // Casting type or a casting class FQN
     *   rules?:   (string|array), // Validation rules
     * }> $fields
     */
    public function __construct(array $fields, EntityCaster $caster)
    {
        $primaryKey   = false;
        $defaults     = [];
        $nullable     = [];
        $casts        = [];
        $castParams   = [];
        $castHandlers = [];
        $dateFormats  = [];

        $dateFormatMap = [
            'time'        => 'H:i:s',
            'date'        => 'Y-m-d',
            'datetime'    => 'Y-m-d H:i:s',
            'datetime-ms' => 'Y-m-d H:i:s.v',
            'datetime-us' => 'Y-m-d H:i:s.u',
        ];

        foreach ($fields as $key => $field) {

            if (empty($field['type'])) {
                $field['type'] = 'text';
            } elseif (!is_string($field['type'])) {
                throw new EntityException('A provided type should be a valid cast string');
            }

            $cast = $field['type'];

            $isNullable = str_starts_with($cast, '?');

            if ($isNullable) {
                $nullable[$key] = true;

                $cast = ltrim($cast, '?');
            }

            if (str_contains($cast, '[') and preg_match('/\A(.+)\[(.+)]\z/', $cast, $matches)) {
                $cast   = $matches[1];
                $params = array_map('trim', explode(',', $matches[2]));
            } else {
                $params = [];
            }

            if (array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            } else {
                $defaults[$key] = $isNullable ? null : '';
            }

            if (str_starts_with($cast, '\\')) {
                if (!is_string($cast) or !class_exists($cast) or !is_subclass_of($cast, CastInterface::class)) {
                    throw new EntityException("A provided cast handler '$cast' should implement the CastInterface interface");
                }

                if ($isNullable) {
                    $params[] = 'nullable';
                }

                $castParams[$key]   = $params;
                $castHandlers[$key] = $cast;
            }

            if (!empty($dateFormatMap[$cast])) {
                $dateFormats[$key] = $dateFormatMap[$cast];
            }

            $casts[$key] = $cast;

            unset($field['type'], $field['default']);

            if (empty($field['label'])) {
                $field['label'] = ucwords(str_replace('_', ' ', $key));
            } else {
                $field['label'] = (string) $field['label'];
            }

            if (!empty($field['primary'])) {
                if ($primaryKey) {
                    throw new EntityException('There should be only one entity field marked as a primary key');
                }

                $primaryKey = $key;
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

            $fields[$key] = $field;
        }

        if (!$primaryKey) {
            throw new EntityException('No primary key is specified for an entity');
        }

        $this->primaryKey   = $primaryKey;
        $this->fields       = $fields;
        $this->nullable     = $nullable;
        $this->caster       = $caster;
        $this->casts        = $casts;
        $this->castParams   = $castParams;
        $this->castHandlers = $castHandlers;
        $this->dateFormats  = $dateFormats;

        foreach ($defaults as $field => $value) {
            $defaults[$field] = $caster->toStorage($this, $field, $value);
        }

        $this->defaults = $defaults;
    }
}