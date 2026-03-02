<?php

namespace App\Core\Entities;

use App\Core\Entity\Entity;

class Comment extends Entity
{
    protected static function getEntityAlias(): string
    {
        return 'comment';
    }

    protected static function getEntityFields(): array
    {
        return [
            'id'           => [
                'primary' => true,
                'type'    => 'int'
            ],
            'post_id'      => [
                'type'  => 'int',
                'rules' => 'required'
            ],
            'user_id'      => [
                'type'    => '?int',
                'default' => null
            ],
            'author_name'  => [
                'type'  => 'text',
                'rules' => 'required'
            ],
            'author_email' => [
                'type'  => 'text',
                'rules' => 'required|valid_email'
            ],
            'content'      => [
                'type'  => 'text-raw',
                'rules' => 'required'
            ],
            'status'       => [
                'type'    => 'text',
                'default' => 'pending'
            ],
            'created_at'   => [
                'type'    => 'timestamp',
                'subtype' => 'created'
            ],
            'post'         => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'belongs-one',
                    'entity'      => 'post',
                    'foreign_key' => 'post_id'
                ]
            ],
            'author'       => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'belongs-one',
                    'entity'      => 'user',
                    'foreign_key' => 'user_id'
                ]
            ],
            'meta'         => [
                'type'     => 'relation',
                'relation' => [
                    'type'   => 'meta',
                    'entity' => 'commnent_meta',
                ]
            ],
        ];
    }
}