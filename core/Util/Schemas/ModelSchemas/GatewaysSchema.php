<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Gateways model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, name, class, version, type)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Gateways',
    'table' => 'gateways',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // No virtual fields
    ],

    // ============================================================
    // RELATIONSHIPS
    // ============================================================
    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'meta' => [
            'type' => 'has_many',
            'model' => 'GatewayMeta',
            'foreign_key' => 'gateway_id',
            'default_included' => false,
        ],
        'currencies' => [
            'type' => 'has_many',
            'model' => 'GatewayCurrencies',
            'foreign_key' => 'gateway_id',
            'default_included' => false,
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // ============================================================
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
