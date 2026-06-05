<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Staff model
 *
 * This schema defines:
 * - Virtual/computed fields (none - user fields are merged from relationship)
 * - Relationships to other models (user, groups, notices, notifications, settings)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, user_id, first_name, last_name, email, status, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Staff',
    'table' => 'staff',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // No virtual fields - user fields are merged from relationship
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'user' => [
            'model' => 'Users',
            'type' => 'belongs_to',
            'foreign_key' => 'user_id',
            'merge' => true,
            'merge_fields' => [
                'username' => 'username',
                'two_factor_mode' => 'two_factor_mode',
                'two_factor_key' => 'two_factor_key',
                'two_factor_pin' => 'two_factor_pin',
            ],
        ],
        'groups' => [
            'type' => 'has_many',
            'model' => 'StaffGroups',
            'foreign_key' => null, // Many-to-many via staff_group junction
            'default_included' => true, // Always included in get()
        ],
        'notices' => [
            'type' => 'has_many',
            'model' => 'StaffNotices',
            'foreign_key' => 'staff_id',
            'default_included' => true, // Always included in get()
        ],
        'notifications' => [
            'type' => 'has_many',
            'model' => 'StaffNotifications',
            'foreign_key' => 'staff_id',
            'default_included' => true, // Always included in get()
        ],
        'settings' => [
            'type' => 'has_many',
            'model' => 'StaffSettings',
            'foreign_key' => 'staff_id',
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
            'virtual' => [],
            'relationships' => ['user', 'groups', 'notices', 'notifications'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['user', 'groups'],
        ],
    ],
];
