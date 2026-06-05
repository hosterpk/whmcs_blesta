<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the QuotationLineItems model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (quotation)
 * - Method presets (what get methods return)
 *
 * NOTE: Native table fields (id, quotation_id, description, qty, amount, tax, order)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'QuotationLineItems',
    'table' => 'quotation_lines',
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
        'quotation' => [
            'model' => 'Quotations',
            'type' => 'belongs_to',
            'foreign_key' => 'quotation_id',
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
