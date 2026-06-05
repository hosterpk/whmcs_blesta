<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ElectronicInvoices model
 */

return [
    'model' => 'ElectronicInvoices',
    'table' => 'electronic_invoices',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'invoice' => [
            'model' => 'Invoices',
            'type' => 'belongs_to',
            'foreign_key' => 'invoice_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['invoice'],
        ],
    ],
];
