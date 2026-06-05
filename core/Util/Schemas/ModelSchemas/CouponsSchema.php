<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Coupons model
 *
 * This schema defines:
 * - Virtual/computed fields (none)
 * - Relationships to other models (company, amounts, packages, terms, package_options)
 * - Method presets (what get(), getAll() return)
 *
 * NOTE: Native table fields (id, code, company_id, used_qty, max_qty, start_date, end_date,
 * status, recurring, limit_recurring, apply_package_options, internal_use_only) are pulled
 * directly from the database schema and do not need to be defined here.
 */

return [
    // ============================================================
    // BASIC METADATA
    // ============================================================
    'model' => 'Coupons',
    'table' => 'coupons',
    'primary_key' => 'id',

    // ============================================================
    // VIRTUAL FIELDS
    // ============================================================
    'virtual' => [
        // No virtual fields for Coupons model
    ],

    // ============================================================
    // RELATIONSHIPS
    // Relationships to other models - can be merged into parent
    // object or returned as nested objects/collections
    // ============================================================
    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
        'amounts' => [
            'type' => 'has_many',
            'model' => 'CouponAmounts',
            'foreign_key' => 'coupon_id',
            'default_included' => true, // Always included in get()
        ],
        'packages' => [
            'type' => 'has_many',
            'model' => 'CouponPackages',
            'foreign_key' => 'coupon_id',
            'default_included' => true, // Always included in get()
        ],
        'terms' => [
            'type' => 'has_many',
            'model' => 'CouponTerms',
            'foreign_key' => 'coupon_id',
            'default_included' => true, // Always included in get()
        ],
        'package_options' => [
            'type' => 'has_many',
            'model' => 'CouponPackageOptions',
            'foreign_key' => 'coupon_id',
            'default_included' => true, // Always included in get()
        ],
    ],

    // ============================================================
    // METHOD PRESETS
    // Define what specific model methods return
    // ============================================================
    'presets' => [
        'get' => [
            'fields' => '*', // All database fields
            'virtual' => [],
            'relationships' => ['amounts', 'packages', 'terms', 'package_options'],
        ],
        'getAll' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['amounts', 'packages'],
        ],
    ],
];
