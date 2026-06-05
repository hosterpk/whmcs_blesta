<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'TransactionApplied',
    'table' => 'transaction_applied',
    'primary_key' => ['transaction_id', 'invoice_id'],
    'virtual' => [],
    'relationships' => [
        'transaction' => ['model' => 'Transactions', 'type' => 'belongs_to', 'foreign_key' => 'transaction_id'],
        'invoice' => ['model' => 'Invoices', 'type' => 'belongs_to', 'foreign_key' => 'invoice_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
