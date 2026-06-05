<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'Permissions',
    'table' => 'permissions',
    'primary_key' => ['staff_group_id', 'plugin_id', 'name'],
    'virtual' => [],
    'relationships' => [
        'staff_group' => [
            'model' => 'StaffGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'staff_group_id',
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
