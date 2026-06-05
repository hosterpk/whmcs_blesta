<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the EmailVerifications model
 *
 * NOTE: Native table fields are pulled from the database schema.
 */

return [
    'model' => 'EmailVerifications',
    'table' => 'email_verifications',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'contact' => [
            'model' => 'Contacts',
            'type' => 'belongs_to',
            'foreign_key' => 'contact_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['contact'],
        ],
    ],
];
