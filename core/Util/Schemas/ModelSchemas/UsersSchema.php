<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Users model
 */

return [
    'model' => 'Users',
    'table' => 'users',
    'primary_key' => 'id',

    'virtual' => [
        // No virtual fields - username is already in table
    ],

    'relationships' => [
        // Users is referenced by many other models but doesn't reference others
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
