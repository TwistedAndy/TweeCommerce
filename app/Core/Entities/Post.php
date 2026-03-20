<?php

namespace App\Core\Entities;

use App\Core\Entity\Entity;

class Post extends Entity
{
    protected static function getEntityAlias(): string
    {
        return 'post';
    }

    protected static function getEntityFields(): array
    {
        return [
            'id'         => [
                'primary' => true,
                'type'    => 'int'
            ],
            'author_id'  => [
                'type'  => 'int',
                'rules' => 'required'
            ],
            'title'      => [
                'type'      => 'text',
                'rules'     => 'required',
                'translate' => true,
            ],
            'excerpt'    => [
                'type'      => 'text',
                'translate' => true,
            ],
            'content'    => [
                'type'      => 'html',
                'translate' => true,
            ],
            'status'     => [
                'type'    => 'text',
                'default' => 'draft'
            ],
            'created_at' => [
                'type'    => 'timestamp',
                'subtype' => 'created'
            ],
            'updated_at' => [
                'type'    => '?timestamp',
                'subtype' => 'updated'
            ],
            'deleted_at' => [
                'type'    => '?timestamp',
                'subtype' => 'deleted'
            ],
            'author'     => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'belongs-one',
                    'entity'      => 'user',
                    'foreign_key' => 'author_id'
                ]
            ],
            'comments'   => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'has-many',
                    'entity'      => 'comment',
                    'foreign_key' => 'post_id',
                    'cascade'     => true,
                ]
            ],
            'terms'      => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'belongs-many',
                    'entity'      => 'term',
                    'local_key'   => 'id', // By default, it's the local primary key
                    'foreign_key' => 'id' // By default, it's a foreign primary key
                ]
            ],
            'meta'       => [
                'type'     => 'relation',
                'relation' => [
                    'type'   => 'meta',
                    'entity' => 'post_meta',
                ]
            ],
        ];
    }

}
