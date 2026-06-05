<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Quotations model
 *
 * This schema defines:
 * - Virtual/computed fields (id_code)
 * - Relationships to other models (client, staff, line_items, taxes, invoices)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, id_format, id_value, client_id, staff_id, title,
 * status, subtotal, total, currency, notes, private_notes, date_created, date_expires)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Quotations',
    'table' => 'quotations',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        'id_code' => function ($record) {
            return str_replace('{num}', $record->id_value ?? 1, $record->id_format ?? 'QUOTE-{num}');
        },
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
        'staff' => [
            'model' => 'Staff',
            'type' => 'belongs_to',
            'foreign_key' => 'staff_id',
        ],
        'line_items' => [
            'type' => 'has_many',
            'model' => 'QuotationLineItems',
            'foreign_key' => 'quotation_id',
            'default_included' => true, // Always included in get()
        ],
        'taxes' => [
            'type' => 'has_many',
            'model' => 'Taxes',
            'foreign_key' => null, // Loaded via join on quotation_lines
            'default_included' => true, // Always included in get()
        ],
        'invoices' => [
            'type' => 'has_many',
            'model' => 'Invoices',
            'foreign_key' => null, // Via quotation_invoices junction
            'default_included' => true, // Always included in get()
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => ['line_items', 'taxes', 'invoices'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['id_code'],
            'relationships' => [],
        ],
    ],
];
