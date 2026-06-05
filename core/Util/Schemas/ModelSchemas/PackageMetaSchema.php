<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'PackageMeta',
    'table' => 'package_meta',
    'primary_key' => ['package_id', 'key'],
    'virtual' => [],
    'relationships' => [
        'package' => ['model' => 'Packages', 'type' => 'belongs_to', 'foreign_key' => 'package_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
