<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PasswordResets model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'PasswordResets',
    'table' => 'password_resets',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'user' => [
            'model' => 'Users',
            'type' => 'belongs_to',
            'foreign_key' => 'user_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['user'],
        ],
    ],
];
