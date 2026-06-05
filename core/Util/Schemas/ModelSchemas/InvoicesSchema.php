<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Invoices model
 *
 * This schema defines:
 * - Virtual/computed fields (id_code, tax_total, etc.)
 * - Relationships to other models (client, line_items, delivery, taxes, etc.)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, client_id, date_billed, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Invoices',
    'table' => 'invoices',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'id_code' => function ($record) {
            return str_replace('{num}', $record->id_value ?? 1, $record->id_format ?? 'INV-{num}');
        },
        'tax_subtotal' => function ($record) {
            // Deprecated since 4.6.0 - use presenter->totals()->tax_amount instead
            // Requires presenter calculation, returned as 0 placeholder
            return '0.00';
        },
        'tax_total' => function ($record) {
            // Deprecated since 4.6.0 - use presenter->totals()->tax_amount instead
            // Requires presenter calculation, returned as 0 placeholder
            return '0.00';
        },
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
        'line_items' => [
            'type' => 'has_many',
            'model' => 'InvoiceLineItems',
            'foreign_key' => 'invoice_id',
            'default_included' => true, // Always included in get()
        ],
        'delivery' => [
            'type' => 'has_many',
            'model' => 'InvoiceDelivery',
            'foreign_key' => 'invoice_id',
            'default_included' => true, // Always included in get()
        ],
        'meta' => [
            'type' => 'has_many',
            'model' => 'InvoiceMeta',
            'foreign_key' => 'invoice_id',
            'default_included' => true, // Always included in get()
        ],
        'taxes' => [
            'type' => 'has_many',
            'model' => 'Taxes',
            'foreign_key' => null, // Special: loaded via join on invoice_lines
            'default_included' => true, // Always included in get()
        ],
        'applied_transactions' => [
            'type' => 'has_many',
            'model' => 'TransactionApplied',
            'foreign_key' => 'invoice_id',
            'default_included' => true, // Always included in get()
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['id_code', 'tax_subtotal', 'tax_total'],
            'relationships' => ['line_items', 'delivery', 'meta', 'taxes', 'applied_transactions'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => [], // getAll() doesn't load relationships by default
        ],
        'getList' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => [],
        ],
    ],
];
