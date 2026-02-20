<?php

namespace App\Core\Entity;

use App\Core\Libraries\Sanitizer;
use DateTimeInterface;

class EntityCaster
{
    protected Sanitizer $sanitizer;

    public function __construct(Sanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    /**
     * Convert a field value in database storage format
     */
    public function toStorage(EntitySchema $schema, string $field, $value)
    {
        if (!isset($schema->casts[$field]) or ($value === null and isset($schema->nullable[$field]))) {
            return $value;
        }

        $cast = $schema->casts[$field];

        switch ($cast) {
            case 'int':
                return (int) $value;
            case 'text':
                return $this->sanitizer->sanitizeText((string) $value);
            case 'text-raw':
                return (string) $value;
            case 'timestamp':
                if (is_int($value) or is_numeric($value)) {
                    return (int) $value;
                }

                if (is_string($value)) {
                    $value = strtotime($value);

                    if ($value === false) {
                        return isset($schema->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->getTimestamp();
                }

                return isset($schema->nullable[$field]) ? null : 0;
            case 'html':
            case 'html-full':
            case 'html-basic':
                if ($cast === 'html') {
                    return $this->sanitizer->sanitizeHtml((string) $value);
                }

                return $this->sanitizer->sanitizeHtml((string) $value, str_replace('html-', '', $cast));
            case 'key':
                return $this->sanitizer->sanitizeKey((string) $value);
            case 'bool':
                return $value ? 1 : 0;
            case 'float':
                return (float) $value;
            case 'json':
            case 'json-array':
                if (!is_string($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $trimmed = trim($value);

                if ($trimmed === '') {
                    return ($cast === 'json-array') ? '[]' : '{}';
                }

                $first = $trimmed[0];
                $last  = substr($trimmed, -1);

                if (($first === '{' and $last === '}') or ($first === '[' and $last === ']') or ($first === '"' and $last === '"')) {
                    json_decode($trimmed);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $trimmed;
                    }
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE);
            case 'array':
                if (!is_string($value) or !$this->isSerialized($value)) {
                    return serialize($value);
                }
                return $value;
            case 'datetime':
            case 'datetime-ms':
            case 'datetime-us':
                if ($value instanceof DateTimeInterface) {
                    return $value->format($schema->dateFormats[$field]);
                }

                if (is_numeric($value)) {
                    $timestamp = (int) $value;
                } else {
                    $timestamp = strtotime($value);
                }

                if ($timestamp > 0) {
                    return date($schema->dateFormats[$field], $timestamp);
                }

                return isset($schema->nullable[$field]) ? null : '';
            case 'uri':
                return $this->sanitizer->sanitizeUri((string) $value);
            default:
                if (isset($schema->castHandlers[$field])) {
                    $handler = $schema->castHandlers[$field];
                    $value   = $handler::set($value, $schema->castParams[$field]);
                }
        }

        return $value;
    }

    /**
     * Convert a field value from database storage format
     */
    public function fromStorage(EntitySchema $schema, string $field, $value)
    {
        if (!isset($schema->casts[$field]) or ($value === null and isset($schema->nullable[$field]))) {
            return $value;
        }

        $cast = $schema->casts[$field];

        switch ($cast) {
            case 'int':
            case 'timestamp':
                return (int) $value;
            case 'key':
            case 'text':
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            case 'html':
            case 'html-full':
            case 'html-basic':
            case 'text-raw':
                return (string) $value;
            case 'datetime':
            case 'datetime-ms':
            case 'datetime-us':
                if (is_int($value) or is_numeric($value)) {
                    return (int) $value;
                }

                if (is_string($value)) {
                    $value = strtotime($value);

                    if ($value === false) {
                        return isset($schema->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->format($schema->dateFormats[$field]);
                }

                return isset($schema->nullable[$field]) ? null : 0;
            case 'bool':
                return (bool) $value;
            case 'float':
                return (float) $value;
            case 'array':
                if (is_string($value) and $this->isSerialized($value)) {
                    return unserialize($value, ['allowed_classes' => false]);
                }

                return (array) $value;
            case 'json':
            case 'json-array':
                if (is_string($value)) {
                    $decoded = json_decode($value, $cast === 'json-array');

                    if ($decoded === null and json_last_error() !== JSON_ERROR_NONE) {
                        return $value;
                    }

                    return $decoded;
                }

                if (is_object($value) or is_array($value)) {
                    return $cast === 'json-array' ? (array) $value : (object) $value;
                }

                return $value;
            default:
                if (isset($schema->castHandlers[$field])) {
                    $handler = $schema->castHandlers[$field];
                    $value   = $handler::get($value, $schema->castParams[$field]);
                }
        }

        return $value;
    }

    /**
     * Check if a string is serialized
     *
     * @param mixed $string
     *
     * @return bool
     */
    protected function isSerialized(mixed $string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        $string = trim($string);

        if ($string === 'N;') {
            return true;
        }

        if (strlen($string) < 4 or $string[1] !== ':') {
            return false;
        }

        $last_letter = substr($string, -1);

        if (';' !== $last_letter and '}' !== $last_letter) {
            return false;
        }

        return str_contains('adObisCE', $string[0]);
    }

}