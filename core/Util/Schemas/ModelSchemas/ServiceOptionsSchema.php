<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the ServiceOptions model
 *
 * This schema defines:
 * - Virtual/computed fields (option_name, option_label from package_option)
 * - Relationships to other models (service, package_option, package_option_value)
 * - Method presets (what get methods return)
 *
 * NOTE: Native table fields (service_id, option_pricing_id, qty, option_value)
 * are pulled directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'ServiceOptions',
    'table' => 'service_options',
    'primary_key' => ['service_id', 'option_pricing_id'],

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        'option_name' => [
            'lookup' => [
                'table' => 'package_options',
                'join_on' => null, // Complex join via pricing
                'field' => 'name',
            ],
        ],
        'option_label' => [
            'lookup' => [
                'table' => 'package_options',
                'join_on' => null, // Complex join via pricing
                'field' => 'label',
            ],
        ],
    ],

    // ============================================================
    // RELATIONSHIPS
    // ============================================================
    'relationships' => [
        'service' => [
            'model' => 'Services',
            'type' => 'belongs_to',
            'foreign_key' => 'service_id',
        ],
        'option_pricing' => [
            'model' => 'PackageOptionPricing',
            'type' => 'belongs_to',
            'foreign_key' => 'option_pricing_id',
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => ['option_name', 'option_label'],
            'relationships' => [],
        ],
    ],
];
