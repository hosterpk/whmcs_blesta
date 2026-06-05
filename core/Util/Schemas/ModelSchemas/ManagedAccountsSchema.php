<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ManagedAccounts model
 */

return [
    'model' => 'ManagedAccounts',
    'table' => 'managed_accounts',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'staff' => [
            'model' => 'Staff',
            'type' => 'belongs_to',
            'foreign_key' => 'staff_id',
        ],
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['staff', 'client'],
        ],
    ],
];
