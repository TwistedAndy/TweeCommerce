<?php

namespace App\Core\Meta;

use App\Core\Entity\Entity;

class Meta extends Entity
{
    protected static function getEntityAlias(): string
    {
        return 'meta';
    }

    protected static function getEntityFields(): array
    {
        return [
            'id' => [
                'primary' => true,
                'type'    => 'text-raw'
            ]
        ];
    }

    public function __unset(string $name): void
    {
        $this->__set($name, null);
    }

    public function get(string $key): mixed
    {
        return $this->__get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->__set($key, $value);
    }

    public function delete(string $key): void
    {
        $this->__set($key, null);
    }
}