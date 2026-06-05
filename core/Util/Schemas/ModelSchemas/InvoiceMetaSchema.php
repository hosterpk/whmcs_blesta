<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'InvoiceMeta',
    'table' => 'invoice_meta',
    'primary_key' => ['invoice_id', 'key'],
    'virtual' => [],
    'relationships' => [
        'invoice' => ['model' => 'Invoices', 'type' => 'belongs_to', 'foreign_key' => 'invoice_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
