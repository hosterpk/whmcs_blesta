<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the EmailGroups model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'EmailGroups',
    'table' => 'email_groups',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'emails' => [
            'type' => 'has_many',
            'model' => 'Emails',
            'foreign_key' => 'email_group_id',
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
