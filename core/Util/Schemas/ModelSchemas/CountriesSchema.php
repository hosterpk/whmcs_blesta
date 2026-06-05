<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Countries model
 *
 * Simple reference data model with no complex relationships.
 */

return [
    'model' => 'Countries',
    'table' => 'countries',
    'primary_key' => 'alpha2',

    'virtual' => [
        // No virtual fields for this simple reference table
    ],

    'relationships' => [
        // No relationships - this is a reference table
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
