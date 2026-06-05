<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'Notifications',
    'table' => 'notifications',
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
            'relationships' => [],
        ],
    ],
];
