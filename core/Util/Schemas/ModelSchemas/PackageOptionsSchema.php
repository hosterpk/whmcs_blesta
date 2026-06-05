<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackageOptions model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company, values, groups, conditions)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, label, name, description, type, addable,
 * editable, hidden, disable_pricing, hide_on_invoice) are pulled directly from the
 * database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'PackageOptions',
    'table' => 'package_options',
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
        'values' => [
            'type' => 'has_many',
            'model' => 'PackageOptionValues',
            'foreign_key' => 'option_id',
            'default_included' => true,
        ],
        'groups' => [
            'type' => 'has_many',
            'model' => 'PackageOptionGroups',
            'foreign_key' => null, // Many-to-many
            'default_included' => false,
        ],
        'conditions' => [
            'type' => 'has_many',
            'model' => 'PackageOptionConditions',
            'foreign_key' => 'option_id',
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
            'relationships' => ['values'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['values'],
        ],
    ],
];
