<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use App\Core\Container\Container;

class Entities extends BaseConfig
{
    /**
     * Map of Alias => Configuration
     */
    public array $types = [
        'posts' => [
            'table'   => 'posts',
            'service' => \App\Core\Entity\EntityService::class,
            'model'   => \App\Core\Entity\EntityModel::class
        ],
        'users' => [
            'table'   => 'users',
            'service' => \App\Core\Entity\EntityService::class,
            'model'   => \App\Core\Entity\EntityModel::class
        ],
    ];

    public function registerEntities(Container $container): void
    {
        foreach ($this->types as $alias => $config) {
            $container->singleton('entity.' . $alias, function () use ($container, $config) {
                return $container->make($config['service'], [
                    'config'    => $config,
                    'container' => $container,
                ]);
            });
        }
    }

}