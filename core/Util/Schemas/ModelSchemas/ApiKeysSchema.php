<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ApiKeys model
 *
 * This schema defines:
 * - Virtual/computed fields (company_name from lookup)
 * - Relationships to other models
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, user, key, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'ApiKeys',
    'table' => 'api_keys',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'company_name' => [
            'lookup' => [
                'table' => 'companies',
                'join_on' => ['companies.id' => 'company_id'],
                'field' => 'name',
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
            'merge' => true,
            'merge_fields' => [
                'company_name' => 'name',
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
            'virtual' => ['company_name'],
            'relationships' => ['company'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['company_name'],
            'relationships' => ['company'],
        ],
    ],
];
