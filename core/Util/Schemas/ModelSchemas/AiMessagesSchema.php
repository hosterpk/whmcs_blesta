<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the AiMessages model
 */

return [
    'model' => 'AiMessages',
    'table' => 'ai_messages',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'conversation' => [
            'model' => 'AiConversations',
            'type' => 'belongs_to',
            'foreign_key' => 'conversation_id',
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
