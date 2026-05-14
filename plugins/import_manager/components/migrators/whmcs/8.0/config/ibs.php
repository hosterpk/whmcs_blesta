<?php
Configure::set('ibs.map', [
    'module' => 'internetbs',
    'module_row_key' => 'username',
    'module_row_meta' => [
        (object)['key' => 'api_key', 'value' => (object)['module' => 'username'], 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'password', 'value' => (object)['module' => 'password'], 'serialized' => 0, 'encrypted' => 1],
        (object)[
            'key' => 'sandbox',
            'value' => (object)['module' => 'testmode'],
            'serialized' => 0,
            'encrypted' => 0,
            'callback' => function ($value) { return ($value == 'on' ? 'true' : 'false'); }
        ]
    ],
    'package_meta' => [
        (object)['key' => 'type', 'value' => 'domain', 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'ns', 'value' => null, 'serialized' => 1, 'encrypted' => 0],
        (object)['key' => 'tlds', 'value' => (object)['package' => 'tlds'], 'serialized' => 1, 'encrypted' => 0],
        (object)['key' => 'epp_code', 'value' => '1', 'serialized' => 0, 'encrypted' => 0]
    ],
    'service_fields' => [
        'domain' => (object)['key' => 'domain', 'serialized' => 0, 'encrypted' => 0]
    ]
]);
