<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the States model (reference data)
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (country)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (country_alpha2, code, name)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'States',
    'table' => 'states',
    'primary_key' => ['country_alpha2', 'code'],

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
        'country' => [
            'model' => 'Countries',
            'type' => 'belongs_to',
            'foreign_key' => 'country_alpha2',
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
