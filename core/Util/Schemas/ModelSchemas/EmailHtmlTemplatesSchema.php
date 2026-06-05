<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the EmailHtmlTemplates model
 */

return [
    'model' => 'EmailHtmlTemplates',
    'table' => 'email_html_templates',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'company' => [
            'model' => 'Companies',
            'type' => 'belongs_to',
            'foreign_key' => 'company_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => [],
        ],
    ],
];
