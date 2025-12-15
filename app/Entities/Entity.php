<?php

namespace App\Entities;

use JsonSerializable;
use CodeIgniter\Exceptions\BadMethodCallException;

/**
 * A lightweight Entity class to handle simple objects
 */
class Entity implements EntityInterface, JsonSerializable
{
    /**
     * Current entity attributes
     */
    protected array $attributes = [];

    /**
     * Original entity attributes
     */
    protected array $original = [];

    /**
     * @inheritDoc
     */
    public function __construct(?array $data = null)
    {
        if (is_array($data)) {
            $this->attributes = $data;
        }
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $method = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        $this->$method($value);
    }

    public function __call(string $name, array $arguments): mixed
    {
        $attribute = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($name, 3)));

        if (str_starts_with($name, 'get')) {

            if (array_key_exists($attribute, $this->attributes)) {
                return $this->attributes[$attribute];
            }

            return null;

        } elseif (str_starts_with($name, 'set')) {

            $updated = false;
            $newValue = $arguments[0] ?? null;
            $currentValue = $this->attributes[$attribute] ?? null;

            if ($currentValue !== $newValue) {
                $updated = true;

                /**
                 * Record the original value on first change
                 */
                if (!array_key_exists($attribute, $this->original)) {
                    $this->original[$attribute] = $currentValue;
                }

                $this->attributes[$attribute] = $newValue;

                /**
                 * Process the case, when a value was reverted back
                 */
                if ($this->attributes[$attribute] === $this->original[$attribute]) {
                    unset($this->original[$attribute]);
                }

            }

            return $updated;

        } else {
            throw new BadMethodCallException(sprintf('Method %s does not exist.', $name));
        }
    }

    public function __unserialize(array $attributes): void
    {
        $this->setAttributes($attributes);
    }

    public function __serialize(): array
    {
        return $this->attributes;
    }

    /**
     * @inheritDoc
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key)) {
                $this->attributes[$key] = $value;
            }
        }

        return $this->attributes;
    }

    /**
     * @inheritDoc
     */
    public function hasChanged(?string $key = null): bool
    {
        if ($key) {
            return array_key_exists($key, $this->original);
        } else {
            return count($this->original) > 0;
        }
    }

    /**
     * @inheritDoc
     */
    public function getChanges(): array
    {
        $attributes = [];

        foreach (array_keys($this->original) as $key) {
            if (array_key_exists($key, $this->attributes)) {
                $attributes[$key] = $this->attributes[$key];
            }
        }

        return $attributes;
    }

    /**
     * @inheritDoc
     */
    public function flushChanges(): void
    {
        $this->original = [];
    }

    /**
     * @inheritDoc
     */
    public function getOriginal(): array
    {
        $changes = $this->getChanges();

        if ($changes) {
            return array_merge($this->attributes, $changes);
        } else {
            return $this->attributes;
        }
    }

    /**
     * @inheritDoc
     */
    public function syncOriginal(): void
    {
        $props = get_object_vars($this);

        foreach (['attributes', 'original'] as $key) {
            unset($props[$key]);
        }

        foreach ($props as $key => $value) {
            $this->attributes[$key] = $value;
            unset($this->$key);
        }

    }

    /**
     * @inheritDoc
     */
    public function restoreOriginal(): void
    {
        foreach ($this->original as $key => $value) {
            $this->attributes[$key] = $value;
        }
        $this->original = [];
    }

    /**
     * @inheritDoc
     */
    public function toRawArray(bool $onlyChanged = false, bool $recursive = false): array
    {
        if ($onlyChanged) {
            $attributes = $this->getChanges();
        } else {
            $attributes = $this->attributes;
        }

        if ($recursive) {
            return array_map(static function ($value) use ($onlyChanged, $recursive) {
                if ($value instanceof EntityInterface) {
                    $value = $value->toRawArray($onlyChanged, $recursive);
                } elseif (is_object($value) and is_callable([$value, 'toRawArray'])) {
                    $value = $value->toRawArray();
                }
                return $value;
            }, $attributes);
        } else {
            return $attributes;
        }
    }

    /**
     * Allow an object to be serialized with json_encode()
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->getAttributes();
    }

}