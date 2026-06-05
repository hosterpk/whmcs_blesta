<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Navigation model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'Navigation',
    'table' => 'navigation',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'parent' => [
            'model' => 'Navigation',
            'type' => 'belongs_to',
            'foreign_key' => 'parent_id',
        ],
        'children' => [
            'type' => 'has_many',
            'model' => 'Navigation',
            'foreign_key' => 'parent_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['parent', 'children'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
