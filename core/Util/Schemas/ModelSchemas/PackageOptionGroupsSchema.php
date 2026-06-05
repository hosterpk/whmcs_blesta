<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'PackageOptionGroups',
    'table' => 'package_option_groups',
    'primary_key' => 'id',
    'virtual' => [],
    'relationships' => [
        'company' => ['model' => 'Companies', 'type' => 'belongs_to', 'foreign_key' => 'company_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
