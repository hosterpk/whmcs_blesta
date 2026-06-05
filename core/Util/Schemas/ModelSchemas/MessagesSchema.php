<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Messages model
 *
 * This schema defines:
 * - Relationships to other models (company, message_group)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, message_group_id, company_id, type, status)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Messages',
    'table' => 'messages',
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
        'message_group' => [
            'model' => 'MessageGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'message_group_id',
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
            'relationships' => ['message_group'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
