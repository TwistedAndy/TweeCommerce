<?php

namespace App\Core\Entities;

use App\Core\Entity\Entity;

class User extends Entity
{
    protected static function getEntityAlias(): string
    {
        return 'user';
    }

    protected static function getEntityFields(): array
    {
        return [
            'id'       => [
                'primary' => true,
                'type'    => 'int'
            ],
            'username' => [
                'type'  => 'text',
                'rules' => 'required|alpha_numeric_space|min_length[3]'
            ],
            'email'    => [
                'type'  => 'text',
                'rules' => 'required|valid_email'
            ],
            'posts'    => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'has-many',
                    'entity'      => 'post',
                    'foreign_key' => 'author_id'
                ]
            ],
            'comments' => [
                'type'     => 'relation',
                'relation' => [
                    'type'        => 'has-many',
                    'entity'      => 'comment',
                    'foreign_key' => 'user_id'
                ]
            ],
            'meta'     => [
                'type'     => 'relation',
                'relation' => [
                    'type'   => 'meta',
                    'entity' => 'user_meta'
                ]
            ],
        ];
    }

}