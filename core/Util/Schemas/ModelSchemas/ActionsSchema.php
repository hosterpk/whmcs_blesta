<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Actions model
 *
 * This schema defines:
 * - Relationships to other models (company, plugin)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, location, icon, url, name, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 *
 * Actions represent navigation items and widgets that can be added by plugins
 * or the system to various locations in the interface.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Actions',
    'table' => 'actions',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models
    // ============================================================
    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'plugin' => [
            'model' => 'Plugins',
            'type' => 'belongs_to',
            'foreign_key' => 'plugin_id',
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
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
