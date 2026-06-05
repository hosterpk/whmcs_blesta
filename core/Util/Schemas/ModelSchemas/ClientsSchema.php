<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Clients model
 *
 * This schema defines:
 * - Virtual/computed fields (id_code, country_name, etc.)
 * - Relationships to other models (merged and nested)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, status, user_id, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 *
 * NOTE: Model references (e.g., 'model' => 'ClientSettings') refer to
 * model schemas, not PHP model classes. A schema can exist for a table
 * even if no corresponding model class exists.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Clients',
    'table' => 'clients',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'id_code' => function ($record) {
            return str_replace('{num}', $record->id_value ?? 1000, $record->id_format ?? 'CLIENT-{num}');
        },
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
        'primary_contact' => [
            'model' => 'Contacts',
            'type' => 'has_one',  // Changed from belongs_to - Clients has one primary Contact
            'local_key' => 'id',  // Clients.id
            'foreign_key' => 'client_id',  // matches Contacts.client_id
            'conditions' => ['contact_type' => 'primary'],
            'merge' => true,
            'merge_fields' => [
                'contact_id' => 'id', // Alias: parent.contact_id = child.id
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'company' => 'company',
                'title' => 'title',
                'email' => 'email',
                'address1' => 'address1',
                'address2' => 'address2',
                'city' => 'city',
                'state' => 'state',
                'zip' => 'zip',
                'country' => 'country',
            ],
        ],
        'client_group' => [
            'model' => 'ClientGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'client_group_id',
            'merge' => true,
            'merge_fields' => [
                'group_name' => 'name',
                'company_id' => 'company_id',
            ],
        ],
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
                'date_added' => 'date_added', // User's date_added
            ],
        ],
        'settings' => [
            'type' => 'has_many_cascading',
            'sources' => [
                [
                    'model' => 'ClientSettings',
                    'foreign_key' => 'client_id',
                    'priority' => 1, // Highest priority
                ],
                [
                    'model' => 'ClientGroupSettings',
                    'foreign_key' => 'client_group_id',
                    'priority' => 2,
                ],
                [
                    'model' => 'CompanySettings',
                    'foreign_key' => 'company_id',
                    'priority' => 3, // Lowest priority
                ],
            ],
        ],
        'notifications' => [
            'type' => 'has_many',
            'model' => 'ClientNotifications',
            'foreign_key' => 'client_id',
        ],
        'contacts' => [
            'type' => 'has_many',
            'model' => 'Contacts',
            'foreign_key' => 'client_id',
            'default_included' => false, // Not included by default
        ],
        'services' => [
            'type' => 'has_many',
            'model' => 'Services',
            'foreign_key' => 'client_id',
            'default_included' => false,
        ],
        'invoices' => [
            'type' => 'has_many',
            'model' => 'Invoices',
            'foreign_key' => 'client_id',
            'default_included' => false,
        ],
        'transactions' => [
            'type' => 'has_many',
            'model' => 'Transactions',
            'foreign_key' => 'client_id',
            'default_included' => false,
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // These are shortcuts for common patterns
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['id_code', 'country_name', 'state_name'],
            'relationships' => ['primary_contact', 'client_group', 'user', 'settings'],
            'collections' => ['notifications'],
            'load_collections' => true,  // Enable collection loading
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => ['primary_contact', 'client_group', 'user'],
        ],
        'getList' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => ['primary_contact', 'client_group', 'user'],
        ],
    ],
];
