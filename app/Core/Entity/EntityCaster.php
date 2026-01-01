<?php

namespace App\Core\Entity;

use App\Core\Libraries\Sanitizer;
use CodeIgniter\DataCaster\Cast\ArrayCast;
use CodeIgniter\DataCaster\Cast\BooleanCast;
use CodeIgniter\DataCaster\Cast\CSVCast;
use CodeIgniter\DataCaster\Cast\DatetimeCast;
use CodeIgniter\DataCaster\Cast\FloatCast;
use CodeIgniter\DataCaster\Cast\IntBoolCast;
use CodeIgniter\DataCaster\Cast\IntegerCast;
use CodeIgniter\DataCaster\Cast\JsonCast;
use CodeIgniter\DataCaster\Cast\TimestampCast;
use CodeIgniter\DataCaster\Cast\URICast;
use CodeIgniter\DataCaster\Exceptions\CastException;
use CodeIgniter\I18n\Time;
use JsonException;

class EntityCaster
{
    protected array     $casts    = [];
    protected array     $nullable = [];
    protected Sanitizer $sanitizer;

    protected array $castHandlers = [
        'array'     => ArrayCast::class,
        'bool'      => BooleanCast::class,
        'boolean'   => BooleanCast::class,
        'csv'       => CSVCast::class,
        'datetime'  => DatetimeCast::class,
        'double'    => FloatCast::class,
        'float'     => FloatCast::class,
        'int'       => IntegerCast::class,
        'integer'   => IntegerCast::class,
        'int-bool'  => IntBoolCast::class,
        'json'      => JsonCast::class,
        'timestamp' => TimestampCast::class,
        'uri'       => URICast::class,
    ];

    public function __construct(array $casts, ?array $castHandlers, Sanitizer $sanitizer)
    {
        foreach ($casts as $field => $cast) {
            if (str_starts_with($cast, '?')) {
                $cast = ltrim($cast, '?');

                $this->nullable[$field] = true;
            }

            $this->casts[$field] = $cast;
        }

        if ($castHandlers) {
            $this->castHandlers = $castHandlers + $this->castHandlers;
        }

        $this->sanitizer = $sanitizer;
    }

    public function getDateFormat($type = 'datetime'): string
    {
        $map = [
            'time'        => 'H:i:s',
            'date'        => 'Y-m-d',
            'datetime'    => 'Y-m-d H:i:s',
            'datetime-ms' => 'Y-m-d H:i:s.v',
            'datetime-us' => 'Y-m-d H:i:s.u',
        ];

        return $map[$type] ?? 'Y-m-d H:i:s';
    }

    /**
     * Convert data to the database storage format
     *
     * @param array $data
     *
     * @return array
     */
    public function toDataSource(array $data): array
    {
        foreach ($data as $field => $value) {

            $cast = $this->casts[$field] ?? 'text';

            switch ($cast) {
                case 'int':
                    if (!is_int($value)) {
                        $data[$field] = (int) $value;
                    }
                    break;
                case 'text':
                    $data[$field] = $this->sanitizer->sanitizeText((string) $value);
                    break;
                case 'timestamp':
                    if (!is_int($value)) {
                        if (is_numeric($value)) {
                            $data[$field] = (int) $value;
                        } elseif (is_string($value)) {
                            $data[$field] = strtotime($value);
                        } elseif ($value instanceof \DateTimeInterface) {
                            $data[$field] = $value->getTimestamp();
                        } else {
                            throw new CastException("The provided value '{$value}' for the field '{$field}' is not a correct timestamp");
                        }
                    }
                    break;
                case 'html':
                case 'html-full':
                case 'html-basic':
                    if ($cast === 'html') {
                        $data[$field] = $this->sanitizer->sanitizeHtml((string) $value);
                    } else {
                        $data[$field] = $this->sanitizer->sanitizeHtml((string) $value, str_replace('html-', '', $cast));
                    }
                    break;
                case 'key':
                    $data[$field] = $this->sanitizer->sanitizeKey((string) $value);
                    break;
                case 'bool':
                case 'int-bool':
                    $data[$field] = $value ? 1 : 0;
                    break;
                case 'float':
                    if (!is_float($value)) {
                        $data[$field] = (float) $value;
                    }
                    break;
                case 'json':
                case 'json-array':
                    if (is_array($value) or is_object($value)) {
                        try {
                            $data[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception) {
                            throw CastException::forInvalidJsonFormat($exception->getCode());
                        }
                    }
                    break;
                case 'array':
                    if (!is_string($value) or !str_starts_with($value, 's:')) {
                        $data[$field] = serialize($value);
                    }
                    break;
                case 'datetime':
                case 'datetime-ms':
                case 'datetime-us':
                    if ($value instanceof \DateTimeInterface) {
                        $data[$field] = $value->format($this->getDateFormat($cast));
                    } else {
                        if (is_numeric($value)) {
                            $timestamp = (int) $value;
                        } else {
                            $timestamp = strtotime($value);
                        }
                        if ($timestamp > 0) {
                            $data[$field] = date($this->getDateFormat($cast), $timestamp);
                        } else {
                            throw new CastException("The provided value '{$value}' for the field '{$field}' is not a correct date");
                        }
                    }
                    break;
                case 'uri':
                    if (!is_string($value)) {
                        $data[$field] = (string) $value;
                    }
                    break;
                case 'csv':
                    if (is_object($value)) {
                        $value = (array) $value;
                    } elseif (!is_array($value)) {
                        $value = [(string) $value];
                    }
                    $data[$field] = implode(',', $value);
                    break;
                default:
                    $data[$field] = $this->castAs($value, $cast, 'set');
            }
        }

        return $data;
    }

    /**
     * Converts data from DataSource to PHP array with specified type values.
     *
     * @param array<string, mixed> $row DataSource data
     *
     */
    public function fromDataSource(array $row): array
    {
        $casts = array_intersect_key($this->casts, $row);

        foreach ($casts as $field => $cast) {

            $value = $row[$field];

            switch ($cast) {
                case 'int':
                    if (!is_int($value)) {
                        $row[$field] = (int) $value;
                    }
                    break;
                case 'key';
                case 'text';
                case 'html':
                case 'html-full':
                case 'html-basic':
                    if (!is_string($value)) {
                        $row[$field] = (string) $value;
                    }
                    break;
                case 'datetime':
                case 'datetime-ms':
                case 'datetime-us':
                case 'timestamp':
                    if (is_string($value) or is_numeric($value)) {
                        try {
                            if (is_numeric($value) and $cast === 'timestamp') {
                                $row[$field] = Time::createFromTimestamp(
                                    (int) $value,
                                    date_default_timezone_get()
                                );
                            } else {
                                $row[$field] = Time::createFromFormat(
                                    self::getDateFormat($cast),
                                    $value
                                );
                            }
                        } catch (\Exception $exception) {
                            $row[$field] = null;
                        }
                    } elseif (!($value instanceof Time)) {
                        $row[$field] = null;
                    }
                    break;
                case 'bool':
                case 'int-bool':
                    if (!is_bool($value)) {
                        $row[$field] = (bool) $value;
                    }
                    break;
                case 'float':
                    if (!is_float($value)) {
                        $row[$field] = (float) $value;
                    }
                    break;
                case 'json':
                    if (is_string($value)) {
                        $row[$field] = json_decode($value, false);
                    } elseif (!is_object($value)) {
                        $row[$field] = (object) $value;
                    }
                    break;
                case 'json-array':
                    if (is_string($value)) {
                        $row[$field] = json_decode($value, true);
                    } elseif (!is_array($value)) {
                        $row[$field] = (array) $value;
                    }
                    break;
                case 'array':
                    if (is_string($value) and $this->isSerialized($value)) {
                        $row[$field] = unserialize($value, ['allowed_classes' => false]);
                    } elseif (!is_array($value)) {
                        $row[$field] = (array) $value;
                    }
                    break;
                case 'csv':
                    if (!is_array($value)) {
                        $row[$field] = explode(',', (string) $value);
                    }
                    break;
                default:
                    $row[$field] = $this->castAs($value, $field, 'get');
            }

        }

        return $row;
    }

    public function castAs(mixed $value, string $field, string $method = 'get'): mixed
    {
        if ($method !== 'get' and $method !== 'set') {
            throw CastException::forInvalidMethod($method);
        }

        if (!isset($this->casts[$field])) {
            return $value;
        }

        $cast = $this->casts[$field];

        $cast = ($cast === 'json-array') ? 'json[array]' : $cast;

        $params = [];

        if (preg_match('/\A(.+)\[(.+)]\z/', $cast, $matches)) {
            $cast   = $matches[1];
            $params = array_map('trim', explode(',', $matches[2]));
        }

        if (!empty($this->nullable[$field])) {
            $params[] = 'nullable';
        }

        $cast = trim($cast, '[]');

        $handlers = $this->castHandlers;

        if (!isset($handlers[$cast])) {
            throw new CastException("No such handler for '{$field}'. Invalid type: '{$cast}'");
        }

        $handler = $handlers[$cast];

        if (!method_exists($handler, $method)) {
            throw CastException::forInvalidInterface($handler);
        }

        return $handler::$method($value, $params);
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

        return (bool) preg_match('/^[adObisCE]:/', $string);
    }

}