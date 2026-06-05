<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the SystemEvents model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'SystemEvents',
    'table' => 'system_events',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [],

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
