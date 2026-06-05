<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackageGroups model
 *
 * This schema defines:
 * - Virtual/computed fields (name, description from language-specific lookups)
 * - Relationships to other models (company, names, descriptions, packages, parents)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, type, hidden, company_id, allow_upgrades) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'PackageGroups',
    'table' => 'package_groups',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'name' => [
            'lookup' => [
                'table' => 'package_group_names',
                'join_on' => ['package_group_names.package_group_id' => 'id'],
                'field' => 'name',
                'condition' => 'lang_match', // Matches current language or falls back to en_us
            ],
        ],
        'description' => [
            'lookup' => [
                'table' => 'package_group_descriptions',
                'join_on' => ['package_group_descriptions.package_group_id' => 'id'],
                'field' => 'description',
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
        'names' => [
            'type' => 'has_many',
            'model' => 'PackageGroupNames',
            'foreign_key' => 'package_group_id',
            'default_included' => true, // Always included in get()
        ],
        'descriptions' => [
            'type' => 'has_many',
            'model' => 'PackageGroupDescriptions',
            'foreign_key' => 'package_group_id',
            'default_included' => true, // Always included in get()
        ],
        'packages' => [
            'type' => 'has_many',
            'model' => 'Packages',
            'foreign_key' => null, // Many-to-many via package_group junction
            'default_included' => false,
        ],
        'parents' => [
            'type' => 'has_many',
            'model' => 'PackageGroups',
            'foreign_key' => null, // Many-to-many via package_group_parents
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
            'virtual' => ['name', 'description'],
            'relationships' => ['names', 'descriptions'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['name', 'description'],
            'relationships' => ['names', 'descriptions'],
        ],
    ],
];
