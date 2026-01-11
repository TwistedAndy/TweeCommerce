<?php

namespace App\Core\Entity;

use App\Core\Libraries\Sanitizer;
use DateTimeInterface;

class EntityCaster
{
    protected Sanitizer $sanitizer;
    protected array     $casts        = [];
    protected array     $castParams   = [];
    protected array     $castHandlers = [];
    protected array     $dateFormats  = [];
    protected array     $nullable     = [];

    public function __construct(array $casts, ?array $castHandlers, Sanitizer $sanitizer)
    {
        if ($castHandlers) {
            $this->castHandlers = $castHandlers;
        }

        foreach ($casts as $field => $cast) {
            if (str_starts_with($cast, '?')) {
                $cast = ltrim($cast, '?');

                $this->nullable[$field] = true;
            }

            if (str_contains($cast, '[') and preg_match('/\A(.+)\[(.+)]\z/', $cast, $matches)) {
                $cast = $matches[1];

                $params = array_map('trim', explode(',', $matches[2]));
            } else {
                $params = [];
            }

            if (isset($this->castHandlers[$cast])) {
                $handler = $this->castHandlers[$cast];

                if (!method_exists($handler, 'get')) {
                    throw new EntityException("Cast method 'get' does not exist in $handler");
                }

                if (!method_exists($handler, 'set')) {
                    throw new EntityException("Cast method 'set' does not exist in $handler");
                }

                if (isset($this->nullable[$field])) {
                    $params[] = 'nullable';
                }

                $this->castParams[$field] = $params;
            }

            if (str_starts_with($cast, 'datetime') or $cast === 'timestamp') {
                $this->dateFormats[$field] = match ($cast) {
                    'datetime-ms' => 'Y-m-d H:i:s.v',
                    'datetime-us' => 'Y-m-d H:i:s.u',
                    'date' => 'Y-m-d',
                    'time' => 'H:i:s',
                    default => 'Y-m-d H:i:s',
                };
            }

            $this->casts[$field] = $cast;
        }

        $this->sanitizer = $sanitizer;
    }

    /**
     * Prepare data for serialization
     */
    public function __serialize(): array
    {
        return [
            'casts'        => $this->casts,
            'nullable'     => $this->nullable,
            'sanitizer'    => $this->sanitizer,
            'castParams'   => $this->castParams,
            'castHandlers' => $this->castHandlers,
            'dateFormats'  => $this->dateFormats,
        ];
    }

    /**
     * Restore state after serialization.
     */
    public function __unserialize(array $data): void
    {
        $this->casts        = $data['casts'];
        $this->nullable     = $data['nullable'];
        $this->sanitizer    = $data['sanitizer'];
        $this->castParams   = $data['castParams'];
        $this->castHandlers = $data['castHandlers'];
        $this->dateFormats  = $data['dateFormats'];
    }

    /**
     * Covnert a field value in database storage format
     */
    public function toStorage(string $field, $value)
    {
        if (!isset($this->casts[$field]) or ($value === null and isset($this->nullable[$field]))) {
            return $value;
        }

        $cast = $this->casts[$field];

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
                        return isset($this->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->getTimestamp();
                }

                return isset($this->nullable[$field]) ? null : 0;
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
            case 'int-bool':
                return $value ? 1 : 0;
            case 'float':
                return (float) $value;
            case 'json':
            case 'json-array':
                if (is_array($value) or is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);

                    if ($value === false) {
                        return isset($this->nullable[$field]) ? null : '{}';
                    }
                }

                return (string) $value;
            case 'array':
                if (!is_string($value) or !$this->isSerialized($value)) {
                    return serialize($value);
                }
                return $value;
            case 'datetime':
            case 'datetime-ms':
            case 'datetime-us':
                if ($value instanceof DateTimeInterface) {
                    return $value->format($this->dateFormats[$field]);
                }

                if (is_numeric($value)) {
                    $timestamp = (int) $value;
                } else {
                    $timestamp = strtotime($value);
                }

                if ($timestamp > 0) {
                    return date($this->dateFormats[$field], $timestamp);
                }

                return isset($this->nullable[$field]) ? null : '';
            case 'uri':
                return $this->sanitizer->sanitizeUri((string) $value);
            case 'csv':
                if (is_object($value)) {
                    $value = (array) $value;
                } elseif (!is_array($value)) {
                    $value = [(string) $value];
                }
                return implode(',', $value);
            default:
                if (isset($this->castHandlers[$cast])) {
                    $handler = $this->castHandlers[$cast];
                    $value   = $handler::set($value, $this->castParams[$field]);
                }
        }

        return $value;
    }

    /**
     * Covnert a field value from database storage format
     */
    public function fromStorage(string $field, $value)
    {
        if (!isset($this->casts[$field]) or ($value === null and isset($this->nullable[$field]))) {
            return $value;
        }

        $cast = $this->casts[$field];

        switch ($cast) {
            case 'int':
            case 'timestamp':
                return (int) $value;
            case 'key';
            case 'text';
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
                        return isset($this->nullable[$field]) ? null : 0;
                    }

                    return $value;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->format($this->dateFormats[$field]);
                }

                return isset($this->nullable[$field]) ? null : 0;
            case 'bool':
            case 'int-bool':
                return (bool) $value;
            case 'float':
                return (float) $value;
            case 'array':
                if (is_string($value) and $this->isSerialized($value)) {
                    return unserialize($value, ['allowed_classes' => false]);
                }

                return (array) $value;
            case 'json-array':
                if (is_string($value)) {
                    return json_decode($value, true);
                }

                return (array) $value;
            case 'json':
                if (is_string($value)) {
                    return json_decode($value, false);
                }

                if (!is_object($value)) {
                    return (object) $value;
                }

                return isset($this->nullable[$field]) ? null : new \stdClass();
            case 'csv':
                if (is_string($value)) {
                    return explode(',', $value);
                }

                if (is_array($value)) {
                    return $value;
                }

                if (is_object($value)) {
                    return (array) $value;
                }

                return [];
            default:
                if (isset($this->castHandlers[$cast])) {
                    $handler = $this->castHandlers[$cast];
                    $value   = $handler::get($value, $this->castParams[$field]);
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