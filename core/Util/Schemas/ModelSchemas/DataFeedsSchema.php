<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the DataFeeds model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'DataFeeds',
    'table' => 'data_feeds',
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
