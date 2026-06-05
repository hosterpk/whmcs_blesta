<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'Emails',
    'table' => 'emails',
    'primary_key' => 'id',
    'virtual' => [],
    'relationships' => [
        'email_group' => [
            'model' => 'EmailGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'email_group_id',
        ],
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
    ],
    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
