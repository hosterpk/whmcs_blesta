<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the AiConversations model
 */

return [
    'model' => 'AiConversations',
    'table' => 'ai_conversations',
    'primary_key' => 'id',

    'virtual' => [],

    'relationships' => [
        'user' => [
            'model' => 'Users',
            'type' => 'belongs_to',
            'foreign_key' => 'user_id',
        ],
        'messages' => [
            'type' => 'has_many',
            'model' => 'AiMessages',
            'foreign_key' => 'conversation_id',
        ],
    ],

    'presets' => [
        'get' => [
            'fields' => '*',
            'virtual' => [],
            'relationships' => ['messages'],
        ],
    ],
];
