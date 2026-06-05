<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ModuleClientMeta model
 */

return [
    'model' => 'ModuleClientMeta',
    'table' => 'module_client_meta',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
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
            'relationships' => [],
        ],
    ],
];
