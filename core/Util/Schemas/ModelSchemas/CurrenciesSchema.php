<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Currencies model
 *
 * This schema defines:
 * - Virtual/computed fields
 * - Relationships to other models
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (code, format, precision, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Currencies',
    'table' => 'currencies',
    'primary_key' => ['code', 'company_id'], // Composite key

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        // Example: formatted display name with symbol
        'display_name' => function ($record) {
            $prefix = $record->prefix ?? '';
            $suffix = $record->suffix ?? '';
            $code = $record->code ?? 'XXX';

            if ($prefix) {
                return $prefix . ' ' . $code;
            }
            if ($suffix) {
                return $code . ' ' . $suffix;
            }
            return $code;
        },
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
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['display_name'],
            'relationships' => [], // get() doesn't load relationships by default
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['display_name'],
            'relationships' => [],
        ],
    ],
];
