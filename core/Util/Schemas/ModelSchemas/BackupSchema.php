<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Backup model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'Backup',
    'table' => 'backups',
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
