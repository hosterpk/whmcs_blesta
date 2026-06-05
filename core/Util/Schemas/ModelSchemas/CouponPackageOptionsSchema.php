<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'CouponPackageOptions',
    'table' => 'coupon_package_options',
    'primary_key' => ['coupon_id', 'option_id'],
    'virtual' => [],
    'relationships' => [
        'coupon' => ['model' => 'Coupons', 'type' => 'belongs_to', 'foreign_key' => 'coupon_id'],
        'option' => ['model' => 'PackageOptions', 'type' => 'belongs_to', 'foreign_key' => 'option_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
