<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ClientGroups model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company, settings)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, company_id, name, description, color)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'ClientGroups',
    'table' => 'client_groups',
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
        'settings' => [
            'type' => 'has_many',
            'model' => 'ClientGroupSettings',
            'foreign_key' => 'client_group_id',
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
