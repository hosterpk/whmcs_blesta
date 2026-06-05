<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Blacklist model
 */

return [
    'model' => 'Blacklist',
    'table' => 'blacklist',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
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
