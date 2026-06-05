<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'PackageDescriptions',
    'table' => 'package_descriptions',
    'primary_key' => ['package_id', 'lang'],
    'virtual' => [],
    'relationships' => [
        'package' => ['model' => 'Packages', 'type' => 'belongs_to', 'foreign_key' => 'package_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
