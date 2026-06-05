<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'PackageGroupNames',
    'table' => 'package_group_names',
    'primary_key' => ['package_group_id', 'lang'],
    'virtual' => [],
    'relationships' => [
        'package_group' => ['model' => 'PackageGroups', 'type' => 'belongs_to', 'foreign_key' => 'package_group_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
