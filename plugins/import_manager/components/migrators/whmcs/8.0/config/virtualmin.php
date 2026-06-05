<?php

Configure::set('virtualmin.map', [
    'module' => 'virtualmin',
    'module_row_key' => 'hostname',
    'module_row_meta' => [
        (object)['key' => 'server_name', 'value' => (object)['module' => 'name'], 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'host_name', 'value' => (object)['module' => 'hostname'], 'alternate_value' => (object)['module' => 'ipaddress'], 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'user_name', 'value' => (object)['module' => 'username'], 'serialized' => 0, 'encrypted' => 1],
        (object)['key' => 'port', 'value' => (object)['module' => 'port'], 'serialized' => 0, 'encrypted' => 1, 'callback' => function ($value) {
            return empty($value) ? '10000' : $value;
        }],
        (object)['key' => 'use_ssl', 'value' => (object)['module' => 'secure'], 'serialized' => 0, 'encrypted' => 0, 'callback' => function ($value) {
            return ($value == 'on' ? 'true' : 'false');
        }],
        (object)['key' => 'password', 'value' => (object)['module' => 'password'], 'serialized' => 0, 'encrypted' => 1],
        (object)['key' => 'account_limit', 'value' => (object)['module' => 'maxaccounts'], 'serialized' => 0, 'encrypted' => 0, 'callback' => function ($value) {
            return empty($value) ? '0' : $value;
        }]
    ],
    'package_meta' => [
        (object)['key' => 'plan', 'value' => (object)['package' => 'configoption2'], 'serialized' => 0, 'encrypted' => 0, 'callback' => function ($value) {
            return empty($value) ? '' : $value;
        }],
        (object)['key' => 'template', 'value' => (object)['package' => 'configoption1'], 'serialized' => 0, 'encrypted' => 0, 'callback' => function ($value) {
            return empty($value) ? '' : $value;
        }],
        (object)['key' => 'sub_domains', 'value' => 'disable', 'serialized' => 0, 'encrypted' => 0],
        (object)['key' => 'domains_list', 'value' => '', 'serialized' => 0, 'encrypted' => 0]
    ],
    'service_fields' => [
        'domain' => (object)['key' => 'virtualmin_domain', 'serialized' => 0, 'encrypted' => 0],
        'username' => (object)['key' => 'virtualmin_username', 'serialized' => 0, 'encrypted' => 0],
        'password' => (object)['key' => 'virtualmin_password', 'serialized' => 0, 'encrypted' => 1]
    ]
]);
