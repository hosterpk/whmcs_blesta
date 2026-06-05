<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Logs model
 *
 * NOTE: The Logs model is a special case that wraps multiple log tables
 * (log_users, log_transactions, log_services, etc.) rather than mapping
 * to a single table. This schema provides a generic structure.
 */

return [
    'model' => 'Logs',
    'table' => null, // No single table - uses multiple log_* tables
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
