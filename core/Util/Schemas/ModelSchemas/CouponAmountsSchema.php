<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'CouponAmounts',
    'table' => 'coupon_amounts',
    'primary_key' => ['coupon_id', 'currency'],
    'virtual' => [],
    'relationships' => [
        'coupon' => ['model' => 'Coupons', 'type' => 'belongs_to', 'foreign_key' => 'coupon_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
