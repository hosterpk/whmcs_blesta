<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Services model
 *
 * This schema defines:
 * - Virtual/computed fields (id_code, name)
 * - Relationships to other models (client, package, pricing, fields, options)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, client_id, pricing_id, status, etc.) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Services',
    'table' => 'services',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // Computed/calculated fields that don't exist in the database
    // but are generated at runtime
    // ============================================================
    'virtual' => [
        'id_code' => function ($record) {
            return str_replace('{num}', $record->id_value ?? 1, $record->id_format ?? 'SRV-{num}');
        },
        'name' => function ($record) {
            // Service name from package or module->getServiceName()
            // Requires module lookup, returned as placeholder
            return 'Service';
        },
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'client' => [
            'model' => 'Clients',
            'type' => 'belongs_to',
            'foreign_key' => 'client_id',
        ],
        'package_pricing' => [
            'model' => 'PackagePricing',
            'type' => 'belongs_to',
            'foreign_key' => 'pricing_id',
            'merge' => true,
            'merge_fields' => [
                'package_pricing' => '*', // Entire pricing object merged as sub-object
            ],
        ],
        'package' => [
            'model' => 'Packages',
            'type' => 'belongs_to',
            'foreign_key' => null, // Loaded via package_pricing->package_id
            'default_included' => true,
        ],
        'fields' => [
            'type' => 'has_many',
            'model' => 'ServiceFields',
            'foreign_key' => 'service_id',
            'default_included' => true, // Always included in get()
        ],
        'options' => [
            'type' => 'has_many',
            'model' => 'ServiceOptions',
            'foreign_key' => 'service_id',
            'default_included' => true, // Always included in get()
        ],
        'parent_service' => [
            'model' => 'Services',
            'type' => 'belongs_to',
            'foreign_key' => 'parent_service_id',
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => ['id_code', 'name'],
            'relationships' => ['package_pricing', 'package', 'fields', 'options'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => ['id_code', 'name'],
            'relationships' => ['package_pricing', 'package', 'fields', 'options'],
        ],
        'getList' => [
            'fields' => '*',
            'virtual' => ['id_code', 'name'],
            'relationships' => [],
        ],
    ],
];
