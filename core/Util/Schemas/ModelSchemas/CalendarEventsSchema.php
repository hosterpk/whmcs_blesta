<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the CalendarEvents model
 *
 * This schema defines:
 * - Virtual/computed fields (staff_first_name, staff_last_name from lookup)
 * - Relationships to other models
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, staff_id, title, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'CalendarEvents',
    'table' => 'calendar_events',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'staff_first_name' => [
            'lookup' => [
                'table' => 'staff',
                'join_on' => ['staff.id' => 'staff_id'],
                'field' => 'first_name',
            ],
        ],
        'staff_last_name' => [
            'lookup' => [
                'table' => 'staff',
                'join_on' => ['staff.id' => 'staff_id'],
                'field' => 'last_name',
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
        'staff' => [
            'model' => 'Staff',
            'type' => 'belongs_to',
            'foreign_key' => 'staff_id',
            'merge' => true,
            'merge_fields' => [
                'staff_first_name' => 'first_name',
                'staff_last_name' => 'last_name',
            ],
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['staff_first_name', 'staff_last_name'],
            'relationships' => ['staff', 'company'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['staff_first_name', 'staff_last_name'],
            'relationships' => ['staff'],
        ],
    ],
];
