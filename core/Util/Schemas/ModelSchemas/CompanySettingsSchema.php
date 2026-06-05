<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the CompanySettings model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company)
 * - Method presets (what get methods return)
 *
 * NOTE: This is a settings table without a traditional model class.
 * Native table fields (key, company_id, value, encrypted, inherit) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'CompanySettings',
    'table' => 'company_settings',
    'primary_key' => ['key', 'company_id'],

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
    ],
];
