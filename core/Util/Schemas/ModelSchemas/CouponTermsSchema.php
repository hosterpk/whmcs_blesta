<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

return [
    'model' => 'CouponTerms',
    'table' => 'coupon_terms',
    'primary_key' => 'id',
    'virtual' => [],
    'relationships' => [
        'coupon' => ['model' => 'Coupons', 'type' => 'belongs_to', 'foreign_key' => 'coupon_id'],
    ],
    'presets' => ['get' => ['fields' => '*', 'virtual' => [], 'relationships' => []]],
];
