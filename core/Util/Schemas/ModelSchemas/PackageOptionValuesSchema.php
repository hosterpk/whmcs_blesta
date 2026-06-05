<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackageOptionValues model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (option, pricing)
 * - Method presets (what get methods return)
 *
 * NOTE: Native table fields (id, option_id, name, value, default, order, status)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'PackageOptionValues',
    'table' => 'package_option_values',
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
        'option' => [
            'model' => 'PackageOptions',
            'type' => 'belongs_to',
            'foreign_key' => 'option_id',
        ],
        'pricing' => [
            'type' => 'has_many',
            'model' => 'PackageOptionPricing',
            'foreign_key' => 'value_id',
            'default_included' => true,
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['pricing'],
        ],
    ],
];
