<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the StaffGroups model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company, staff, permissions)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, name, num_staff, session_lock)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'StaffGroups',
    'table' => 'staff_groups',
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
        'staff' => [
            'type' => 'has_many',
            'model' => 'Staff',
            'foreign_key' => null, // Many-to-many via staff_group junction
            'default_included' => false,
        ],
        'permissions' => [
            'type' => 'has_many',
            'model' => 'Permissions',
            'foreign_key' => null, // Via permissions table
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
