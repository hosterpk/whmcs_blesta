<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Packages model
 *
 * This schema defines:
 * - Virtual/computed fields (id_code, name)
 * - Relationships to other models (pricing, groups, meta, etc.)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, module_id, qty, status, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Packages',
    'table' => 'packages',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'id_code' => function ($record) {
            return str_replace('{num}', $record->id_value ?? 1, $record->id_format ?? 'PKG-{num}');
        },
        'name' => [
            'lookup' => [
                'table' => 'package_names',
                'join_on' => ['package_names.package_id' => 'id'],
                'field' => 'name',
                'condition' => 'lang_match', // Matches current language or falls back to en_us
            ],
        ],
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'module' => [
            'model' => 'Modules',
            'type' => 'belongs_to',
            'foreign_key' => 'module_id',
        ],
        'names' => [
            'type' => 'has_many',
            'model' => 'PackageNames',
            'foreign_key' => 'package_id',
            'default_included' => true, // Always included in get()
        ],
        'descriptions' => [
            'type' => 'has_many',
            'model' => 'PackageDescriptions',
            'foreign_key' => 'package_id',
            'default_included' => true, // Always included in get()
        ],
        'email_content' => [
            'type' => 'has_many',
            'model' => 'PackageEmailContent',
            'foreign_key' => 'package_id',
            'default_included' => true, // Always included in get()
        ],
        'pricing' => [
            'type' => 'has_many',
            'model' => 'PackagePricing',
            'foreign_key' => 'package_id',
            'default_included' => true, // Always included in get()
        ],
        'meta' => [
            'type' => 'has_many',
            'model' => 'PackageMeta',
            'foreign_key' => 'package_id',
            'default_included' => true, // Always included in get()
        ],
        'groups' => [
            'type' => 'has_many',
            'model' => 'PackageGroups',
            'foreign_key' => null, // Many-to-many via package_group junction
            'default_included' => true, // Always included in get()
        ],
        'module_groups' => [
            'type' => 'has_many',
            'model' => 'ModuleGroups',
            'foreign_key' => null, // Loaded via package->module_group or junction
            'default_included' => true, // Always included in get()
        ],
        'option_groups' => [
            'type' => 'has_many',
            'model' => 'PackageOptionGroups',
            'foreign_key' => null, // Many-to-many via package_package_option_group junction
            'default_included' => true, // Always included in get()
        ],
        'plugins' => [
            'type' => 'has_many',
            'model' => 'Plugins',
            'foreign_key' => null, // Many-to-many via package_plugins junction
            'default_included' => true, // Always included in get()
        ],
        'default_pricing' => [
            'type' => 'belongs_to',
            'model' => 'PackagePricing',
            'foreign_key' => null, // Calculated based on default pricing logic
            'default_included' => true, // Always included in get()
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['id_code', 'name'],
            'relationships' => [
                'names',
                'descriptions',
                'email_content',
                'pricing',
                'meta',
                'groups',
                'module_groups',
                'option_groups',
                'plugins',
                'default_pricing',
            ],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['id_code', 'name'],
            'relationships' => ['pricing', 'groups'],
        ],
        'getList' => [
            'fields' => '*',
            'virtual' => ['id_code', 'name'],
            'relationships' => [],
        ],
    ],
];
