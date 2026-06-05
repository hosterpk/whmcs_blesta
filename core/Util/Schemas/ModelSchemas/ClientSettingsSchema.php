<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for ClientSettings (table-only, no model class)
 *
 * This schema exists for a table that has no corresponding model class.
 * It's used when other models reference client_settings data.
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'ClientSettings',
    'table' => 'client_settings',
    'primary_key' => ['client_id', 'key'], // Composite key

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // No virtual fields for this simple table
    ],

    // ============================================================
    // RELATIONSHIPS
    // ============================================================
    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // ============================================================
    'presets' => [
        // Settings are typically accessed through parent model (Clients::getSettings())
        // No standalone get/getAll methods
    ],
];
