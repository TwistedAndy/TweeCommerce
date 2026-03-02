<?php

namespace App\Core\Entities;

use App\Core\Entity\Entity;

class Term extends Entity
{
    protected static function getEntityAlias(): string
    {
        return 'term';
    }

    protected static function getEntityFields(): array
    {
        return [
            'id'       => [
                'primary' => true,
                'type'    => 'int'
            ],
            'taxonomy' => [
                'type'  => 'text',
                'rules' => 'required'
            ],
            'name'     => [
                'type'  => 'text',
                'rules' => 'required'
            ],
            'slug'     => [
                'type'  => 'text',
                'rules' => 'required'
            ],
            'posts'    => [
                'type'     => 'relation',
                'relation' => [
                    'type'              => 'belongs-many',
                    'entity'            => 'post',
                    'pivot_local_key'   => 'term_id',
                    'pivot_foreign_key' => 'post_id'
                ]
            ],
            'meta'     => [
                'type'     => 'relation',
                'relation' => [
                    'type'   => 'meta',
                    'entity' => 'term_meta',
                ],
            ],
        ];
    }

}