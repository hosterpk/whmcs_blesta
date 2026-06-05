<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the MessageGroups model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'MessageGroups',
    'table' => 'message_groups',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'messages' => [
            'type' => 'has_many',
            'model' => 'Messages',
            'foreign_key' => 'message_group_id',
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
