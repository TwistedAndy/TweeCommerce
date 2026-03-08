<?php

namespace Config;

use App\Core\Entities\Comment;
use App\Core\Entities\Post;
use App\Core\Entities\Term;
use App\Core\Entities\User;
use App\Core\Meta\Meta;
use App\Core\Meta\MetaModel;
use CodeIgniter\Config\BaseConfig;

class Entities extends BaseConfig
{
    public array $entities = [
        'user'         => [
            'entity' => User::class,
            'table'  => 'users',
        ],
        'post'         => [
            'entity' => Post::class,
            'table'  => 'posts',
            'pivots' => [
                // Many-to-Many Pivot Tables as 'entity_alias' => array
                'term' => [
                    'table'          => 'term_relationships',
                    'local_column'   => 'post_id',
                    'foreign_column' => 'term_id',
                ],
            ]
        ],
        'term'         => [
            'entity' => Term::class,
            'table'  => 'terms',
            'pivots' => [
                'post' => [
                    'table'          => 'term_relationships',
                    'local_column'   => 'term_id',
                    'foreign_column' => 'post_id',
                ],
            ]
        ],
        'comment'      => [
            'entity' => Comment::class,
            'table'  => 'comments',
        ],
        'post_meta'    => [
            'entity'        => Meta::class,
            'model'         => MetaModel::class,
            'table'         => 'post_meta',
            // Meta Column Mapping
            'key_column'    => 'meta_key',
            'value_column'  => 'meta_value',
            'entity_column' => 'entity_id',
        ],
        'term_meta'    => [
            'entity'        => Meta::class,
            'table'         => 'term_meta',
            'entity_column' => 'entity_id',
        ],
        'user_meta'    => [
            'entity'        => Meta::class,
            'table'         => 'user_meta',
            'entity_column' => 'entity_id',
        ],
        'comment_meta' => [
            'entity'        => Meta::class,
            'table'         => 'comment_meta',
            'entity_column' => 'entity_id',
        ],
    ];

}