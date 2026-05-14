<?php
Configure::set('namesilo.map', [
    'module' => 'namesilo',
    'module_row_key' => 'user',
    'module_row_meta' => [
        (object)['key' => 'user', 'value' => 'whmcs', 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'key', 'value' => (object)['module' => 'live_api_key'], 'serialized' => 0, 'encrypted' => 1],
        (object)['key' => 'payment_id', 'value' => (object)['module' => 'payment_id'], 'serialized' => 0, 'encrypted' => 0]
    ],
    'package_meta' => [
        (object)['key' => 'type', 'value' => 'domain', 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'ns', 'value' => [], 'serialized' => 1, 'encrypted' => 0],
        (object)['key' => 'tlds', 'value' => (object)['package' => 'tlds'], 'serialized' => 1, 'encrypted' => 0]
    ],
    'service_fields' => [
        'domain' => (object)['key' => 'domain', 'serialized' => 0, 'encrypted' => 0]
    ]
]);
