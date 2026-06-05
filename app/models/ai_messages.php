<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Language;

/**
 * AiMessages
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AiMessages extends AppModel
{
    /**
     * Initialize the AiMessages
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['ai_messages']);
    }

    /**
     * Adds a message
     *
     * @param array $vars An array of message data including:
     *  - conversation_id The conversation ID
     *  - role The role (system/user/assistant)
     *  - content The message content
     *  - prompt_tokens The prompt tokens (for assistant messages)
     *  - completion_tokens The completion tokens (for assistant messages)
     *  - cost The cost (for assistant messages)
     * @return int The message ID, void on error
     */
    public function add(array $vars)
    {
        // Set default date
        if (empty($vars['date_created'])) {
            $vars['date_created'] = date('Y-m-d H:i:s');
        }

        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = ['conversation_id', 'role', 'content', 'prompt_tokens', 'completion_tokens', 'cost', 'date_created'];
            $this->Record->insert('ai_messages', $vars, $fields);

            return $this->Record->lastInsertId();
        }
    }

    /**
     * Gets all messages for a conversation
     *
     * @param int $conversation_id The conversation ID
     * @return array An array of stdClass objects representing messages
     */
    public function getAll($conversation_id)
    {
        return $this->Record->select()
            ->from('ai_messages')
            ->where('conversation_id', '=', $conversation_id)
            ->order(['date_created' => 'ASC'])
            ->fetchAll();
    }

    /**
     * Gets usage statistics for a conversation
     *
     * @param int $conversation_id The conversation ID
     * @return mixed A stdClass object with totals, false if no messages
     */
    public function getUsageStats($conversation_id)
    {
        return $this->Record->select([
                'SUM(prompt_tokens)' => 'total_prompt_tokens',
                'SUM(completion_tokens)' => 'total_completion_tokens',
                'SUM(cost)' => 'total_cost'
            ])
            ->from('ai_messages')
            ->where('conversation_id', '=', $conversation_id)
            ->fetch();
    }

    /**
     * Gets usage statistics for a company
     *
     * @param int $company_id The company ID
     * @param int $staff_id The staff ID (optional)
     * @return mixed A stdClass object with totals, false if no messages
     */
    public function getCompanyUsageStats($company_id, $staff_id = null)
    {
        $this->Record->select([
                'SUM(ai_messages.prompt_tokens)' => 'total_prompt_tokens',
                'SUM(ai_messages.completion_tokens)' => 'total_completion_tokens',
                'SUM(ai_messages.cost)' => 'total_cost'
            ])
            ->from('ai_messages')
            ->innerJoin('ai_conversations', 'ai_conversations.id', '=', 'ai_messages.conversation_id', false)
            ->where('ai_conversations.company_id', '=', $company_id);

        if ($staff_id !== null) {
            $this->Record->where('ai_conversations.staff_id', '=', $staff_id);
        }

        return $this->Record->fetch();
    }

    /**
     * Gets validation rules for adding messages
     *
     * @param array $vars An array of input data
     * @return array An array of validation rules
     */
    private function getRules(array $vars)
    {
        $rules = [
            'conversation_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'ai_conversations'],
                    'message' => $this->_('AiMessages.!error.conversation_id.exists')
                ]
            ],
            'role' => [
                'valid' => [
                    'rule' => ['in_array', ['system', 'user', 'assistant']],
                    'message' => $this->_('AiMessages.!error.role.valid')
                ]
            ],
            'content' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('AiMessages.!error.content.empty')
                ]
            ]
        ];

        return $rules;
    }
}
