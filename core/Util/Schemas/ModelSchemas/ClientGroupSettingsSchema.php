<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ClientGroupSettings model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (client_group)
 * - Method presets (what get methods return)
 *
 * NOTE: This is a settings table without a traditional model class.
 * Native table fields (key, client_group_id, value, encrypted) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'ClientGroupSettings',
    'table' => 'client_group_settings',
    'primary_key' => ['key', 'client_group_id'],

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
        'client_group' => [
            'model' => 'ClientGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'client_group_id',
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
