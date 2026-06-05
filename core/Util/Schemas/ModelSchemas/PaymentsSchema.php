<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Payments model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'Payments',
    'table' => 'payments',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
        'transaction' => [
            'model' => 'Transactions',
            'type' => 'belongs_to',
            'foreign_key' => 'transaction_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['client', 'transaction'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
