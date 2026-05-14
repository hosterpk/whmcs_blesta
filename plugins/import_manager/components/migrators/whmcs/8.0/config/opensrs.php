<?php
Configure::set('opensrs.map', [
    'module' => 'opensrs',
    'module_row_key' => 'user',
    'module_row_meta' => [
        (object)['key' => 'user', 'value' => (object)['module' => 'username'], 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'key', 'value' => (object)['module' => 'privatekey'], 'serialized' => 0, 'encrypted' => 1],
        (object)['key' => 'sandbox', 'value' => (object)['module' => 'testmode'], 'serialized' => 0, 'encrypted' => 0]
    ],
    'package_meta' => [
        (object)['key' => 'ns', 'value' => [], 'serialized' => 1, 'encrypted' => 0],
        (object)['key' => 'tlds', 'value' => (object)['package' => 'tlds'], 'serialized' => 1, 'encrypted' => 0]
    ],
    'service_fields' => [
        'domain' => (object)['key' => 'domain', 'serialized' => 0, 'encrypted' => 0]
    ]
]);
