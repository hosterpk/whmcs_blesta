<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Transactions model
 *
 * This schema defines:
 * - Virtual/computed fields (gateway_name, gateway_type, applied_amount, credited_amount, type_name, transaction_type)
 * - Relationships to other models (client, gateway, transaction_type, applied)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, client_id, amount, currency, type, status, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Transactions',
    'table' => 'transactions',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'gateway_name' => [
            'lookup' => [
                'table' => 'gateways',
                'join_on' => ['gateways.id' => 'gateway_id'],
                'field' => 'name',
            ],
        ],
        'gateway_type' => [
            'lookup' => [
                'table' => 'gateways',
                'join_on' => ['gateways.id' => 'gateway_id'],
                'field' => 'type',
            ],
        ],
        'type_name' => [
            'lookup' => [
                'table' => 'transaction_types',
                'join_on' => ['transaction_types.id' => 'transaction_type_id'],
                'field' => 'name',
            ],
        ],
        'transaction_type' => [
            'lookup' => [
                'table' => 'transaction_types',
                'join_on' => ['transaction_types.id' => 'transaction_type_id'],
                'field' => 'name',
            ],
        ],
        'applied_amount' => function ($record) {
            // Sum of transaction_applied amounts
            // Requires subquery calculation
            return 0;
        },
        'credited_amount' => function ($record) {
            // Sum of transaction_applied amounts where type='credit'
            // Requires subquery calculation
            return 0;
        },
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
        'gateway' => [
            'model' => 'Gateways',
            'type' => 'belongs_to',
            'foreign_key' => 'gateway_id',
        ],
        'transaction_type' => [
            'model' => 'TransactionTypes',
            'type' => 'belongs_to',
            'foreign_key' => 'transaction_type_id',
        ],
        'applied' => [
            'type' => 'has_many',
            'model' => 'TransactionApplied',
            'foreign_key' => 'transaction_id',
            'default_included' => false,
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => [
                'gateway_name',
                'gateway_type',
                'type_name',
                'transaction_type',
                'applied_amount',
                'credited_amount',
            ],
            'relationships' => [],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['gateway_name', 'gateway_type', 'type_name', 'applied_amount'],
            'relationships' => [],
        ],
    ],
];
