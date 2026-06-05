<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Contacts model
 *
 * This schema defines:
 * - Virtual/computed fields (country_name, state_name, contact_type_name)
 * - Relationships to other models (client, user, contact_type, numbers, permissions)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, client_id, first_name, email, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Contacts',
    'table' => 'contacts',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'contact_type_name' => [
            'lookup' => [
                'table' => 'contact_types',
                'join_on' => ['contact_types.id' => 'contact_type_id'],
                'field' => 'name',
            ],
        ],
        'contact_type_is_lang' => [
            'lookup' => [
                'table' => 'contact_types',
                'join_on' => ['contact_types.id' => 'contact_type_id'],
                'field' => 'is_lang',
            ],
        ],
        'country_name' => [
            'lookup' => [
                'table' => 'countries',
                'join_on' => ['countries.alpha2' => 'country'],
                'field' => 'name',
                'condition' => 'state_format_is_name',
            ],
        ],
        'state_name' => [
            'lookup' => [
                'table' => 'states',
                'join_on' => [
                    'states.country_alpha2' => 'country',
                    'states.code' => 'state',
                ],
                'field' => 'name',
                'condition' => 'state_format_is_name',
            ],
        ],
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
        'user' => [
            'model' => 'Users',
            'type' => 'belongs_to',
            'foreign_key' => 'user_id',
        ],
        'contact_type' => [
            'model' => 'ContactTypes',
            'type' => 'belongs_to',
            'foreign_key' => 'contact_type_id',
        ],
        'numbers' => [
            'type' => 'has_many',
            'model' => 'ContactNumbers',
            'foreign_key' => 'contact_id',
            'default_included' => false,
        ],
        'permissions' => [
            'type' => 'has_many',
            'model' => 'ContactPermissions',
            'foreign_key' => 'contact_id',
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
            'virtual' => ['contact_type_name', 'contact_type_is_lang', 'country_name', 'state_name'],
            'relationships' => [],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['contact_type_name'],
            'relationships' => [],
        ],
    ],
];
