<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'CouponPackages',
    'table' => 'coupon_packages',
    'primary_key' => ['coupon_id', 'package_id'],
    'virtual' => [],
    'relationships' => [
        'coupon' => ['model' => 'Coupons', 'type' => 'belongs_to', 'foreign_key' => 'coupon_id'],
        'package' => ['model' => 'Packages', 'type' => 'belongs_to', 'foreign_key' => 'package_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
