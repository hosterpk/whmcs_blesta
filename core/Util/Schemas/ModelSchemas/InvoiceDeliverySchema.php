<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'InvoiceDelivery',
    'table' => 'invoice_delivery',
    'primary_key' => ['invoice_id', 'method'],
    'virtual' => [],
    'relationships' => [
        'invoice' => ['model' => 'Invoices', 'type' => 'belongs_to', 'foreign_key' => 'invoice_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
