<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Companies model
 *
 * This schema defines:
 * - Relationships to other models (settings, gateways, modules)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, name, hostname, address, phone, fax) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Companies',
    'table' => 'companies',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // No virtual fields for Companies model
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'settings' => [
            'type' => 'has_many',
            'model' => 'CompanySettings',
            'foreign_key' => 'company_id',
            'default_included' => false,
        ],
        'gateways' => [
            'type' => 'has_many',
            'model' => 'Gateways',
            'foreign_key' => 'company_id',
            'default_included' => false,
        ],
        'modules' => [
            'type' => 'has_many',
            'model' => 'Modules',
            'foreign_key' => 'company_id',
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
            'relationships' => [],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
