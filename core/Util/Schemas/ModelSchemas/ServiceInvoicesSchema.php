<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ServiceInvoices model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'ServiceInvoices',
    'table' => 'service_invoices',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'service' => [
            'model' => 'Services',
            'type' => 'belongs_to',
            'foreign_key' => 'service_id',
        ],
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
            'relationships' => ['service', 'invoice'],
        ],
    ],
];
