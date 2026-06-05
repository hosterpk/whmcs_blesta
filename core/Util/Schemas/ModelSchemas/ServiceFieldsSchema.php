<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'ServiceFields',
    'table' => 'service_fields',
    'primary_key' => ['service_id', 'key'],
    'virtual' => [],
    'relationships' => [
        'service' => ['model' => 'Services', 'type' => 'belongs_to', 'foreign_key' => 'service_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
