<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Modules model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company, rows, groups)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, name, class, version)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Modules',
    'table' => 'modules',
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
        'rows' => [
            'type' => 'has_many',
            'model' => 'ModuleRows',
            'foreign_key' => 'module_id',
            'default_included' => false,
        ],
        'groups' => [
            'type' => 'has_many',
            'model' => 'ModuleGroups',
            'foreign_key' => 'module_id',
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
