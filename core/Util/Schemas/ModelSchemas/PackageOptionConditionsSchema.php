<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackageOptionConditions model
 */

return [
    'model' => 'PackageOptionConditions',
    'table' => 'package_option_conditions',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'condition_set' => [
            'model' => 'PackageOptionConditionSets',
            'type' => 'belongs_to',
            'foreign_key' => 'condition_set_id',
        ],
        'trigger_option_value' => [
            'model' => 'PackageOptionValues',
            'type' => 'belongs_to',
            'foreign_key' => 'trigger_option_value_id',
        ],
        'option_value' => [
            'model' => 'PackageOptionValues',
            'type' => 'belongs_to',
            'foreign_key' => 'option_value_id',
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
