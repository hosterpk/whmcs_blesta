<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Taxes model
 */

return [
    'model' => 'Taxes',
    'table' => 'taxes',
    'primary_key' => 'id',

    'virtual' => [
        'display_name' => function ($record) {
            $name = $record->name ?? 'Tax';
            $amount = $record->amount ?? 0;
            $type = $record->type ?? 'inclusive';

            return sprintf('%s (%.2f%% %s)', $name, $amount, $type);
        },
    ],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'country' => [
            'model' => 'Countries',
            'type' => 'belongs_to',
            'foreign_key' => 'country',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => ['display_name'],
            'relationships' => [],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['display_name'],
            'relationships' => [],
        ],
    ],
];
