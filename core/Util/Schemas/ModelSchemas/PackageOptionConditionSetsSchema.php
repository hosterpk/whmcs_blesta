<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackageOptionConditionSets model
 */

return [
    'model' => 'PackageOptionConditionSets',
    'table' => 'package_option_condition_sets',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'option_group' => [
            'model' => 'PackageOptionGroups',
            'type' => 'belongs_to',
            'foreign_key' => 'option_group_id',
        ],
        'conditions' => [
            'type' => 'has_many',
            'model' => 'PackageOptionConditions',
            'foreign_key' => 'condition_set_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['conditions'],
        ],
    ],
];
