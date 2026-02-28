<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use \App\Core\Entity\EntityInterface;
use CodeIgniter\Database\BaseBuilder;

class Entities extends BaseConfig
{
    /**
     * Map of Entity classes to their database properties and services.
     *
     * @var array<class-string<EntityInterface>, array>
     */
    public array $entities = [
        'posts' => [
            'entity'   => \App\Core\Entity\Entity::class,
            'table'    => 'posts',
            'db_group' => '',
            'pivots'   => [
                // 'tags' => 'post_tags'
            ]
        ],
    ];

}