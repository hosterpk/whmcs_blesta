<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the InvoiceLineItems model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (invoice, service, taxes)
 * - Method presets (what get methods return)
 *
 * NOTE: Native table fields (id, invoice_id, service_id, description, qty, amount, order)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'InvoiceLineItems',
    'table' => 'invoice_lines',
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
        'invoice' => [
            'model' => 'Invoices',
            'type' => 'belongs_to',
            'foreign_key' => 'invoice_id',
        ],
        'service' => [
            'model' => 'Services',
            'type' => 'belongs_to',
            'foreign_key' => 'service_id',
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
