<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the CronTasks model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'CronTasks',
    'table' => 'cron_tasks',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'plugin' => [
            'model' => 'Plugins',
            'type' => 'belongs_to',
            'foreign_key' => 'plugin_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['plugin'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
