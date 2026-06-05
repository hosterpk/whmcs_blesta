<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the PackagePricing model (junction view)
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (package, pricing/currency info)
 * - Method presets (what get methods return)
 *
 * NOTE: PackagePricing combines data from package_pricing table (id, package_id, pricing_id)
 * with data from pricings table (term, period, price, currency, fees, etc.)
 *
 * Native table fields are pulled from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'PackagePricing',
    'table' => 'package_pricing',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // Pricing fields are merged from the pricings table via relationship
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'package' => [
            'model' => 'Packages',
            'type' => 'belongs_to',
            'foreign_key' => 'package_id',
        ],
        'pricing' => [
            'model' => 'Pricings',
            'type' => 'belongs_to',
            'foreign_key' => 'pricing_id',
            'merge' => true,
            'merge_fields' => [
                'term' => 'term',
                'period' => 'period',
                'price' => 'price',
                'price_renews' => 'price_renews',
                'price_transfer' => 'price_transfer',
                'setup_fee' => 'setup_fee',
                'cancel_fee' => 'cancel_fee',
                'currency' => 'currency',
            ],
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields from package_pricing
            'virtual' => [],
            'relationships' => ['pricing'], // Merged pricing fields
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['pricing'],
        ],
    ],
];
