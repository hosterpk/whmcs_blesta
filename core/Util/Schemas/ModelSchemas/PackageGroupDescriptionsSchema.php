<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'PackageGroupDescriptions',
    'table' => 'package_group_descriptions',
    'primary_key' => ['package_group_id', 'lang'],
    'virtual' => [],
    'relationships' => [
        'package_group' => ['model' => 'PackageGroups', 'type' => 'belongs_to', 'foreign_key' => 'package_group_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
