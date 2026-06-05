<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Language;

/**
 * AiConversations
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AiConversations extends AppModel
{
    /**
     * Initialize the AiConversations
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['ai_conversations']);
    }

    /**
     * Adds a conversation
     *
     * @param array $vars An array of conversation data including:
     *  - company_id The company ID
     *  - staff_id The staff ID (0 for system)
     *  - title The optional conversation title
     *  - type The optional conversation type (defaults to 'chatbot' at the database level)
     *  - model The AI model ID
     *  - status The status (active/archived)
     * @return int The conversation ID, void on error
     */
    public function add(array $vars)
    {
        // Set default status if not provided
        if (empty($vars['status'])) {
            $vars['status'] = 'active';
        }

        // Set default date
        if (empty($vars['date_created'])) {
            $vars['date_created'] = date('Y-m-d H:i:s');
        }

        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = ['company_id', 'staff_id', 'title', 'type', 'model', 'status', 'date_created'];
            $this->Record->insert('ai_conversations', $vars, $fields);

            return $this->Record->lastInsertId();
        }
    }

    /**
     * Gets a conversation by ID
     *
     * @param int $conversation_id The conversation ID
     * @return mixed A stdClass object representing the conversation, false if it doesn't exist
     */
    public function get($conversation_id)
    {
        return $this->Record->select()
            ->from('ai_conversations')
            ->where('id', '=', $conversation_id)
            ->fetch();
    }

    /**
     * Gets all conversations for a company
     *
     * @param int $company_id The company ID
     * @param int $staff_id The staff ID (optional, null to get all staff)
     * @param string $status The status filter (active/archived, null for all)
     * @param string $type The type filter (null for all)
     * @return array An array of stdClass objects representing conversations
     */
    public function getAll($company_id, $staff_id = null, $status = 'active', $type = null)
    {
        $this->Record->select()
            ->from('ai_conversations')
            ->where('company_id', '=', $company_id);

        if ($staff_id !== null) {
            $this->Record->where('staff_id', '=', $staff_id);
        }

        if ($status !== null) {
            $this->Record->where('status', '=', $status);
        }

        if ($type !== null) {
            $this->Record->where('type', '=', $type);
        }

        return $this->Record->order(['date_created' => 'DESC'])->fetchAll();
    }

    /**
     * Gets all conversations for a company with the last message preview included via a single query.
     *
     * @param int $company_id The company ID
     * @param int $staff_id The staff ID (optional, null to get all staff)
     * @param string $status The status filter (active/archived, null for all)
     * @param int $page The page number (1-indexed)
     * @param int $per_page The number of results per page
     * @param string $type The type filter (null for all)
     * @return array An array of stdClass objects with an additional last_message_content field
     */
    public function getAllWithPreview($company_id, $staff_id = null, $status = 'active', $page = 1, $per_page = 25, $type = null)
    {
        // Subquery: latest message ID per conversation
        $subquery_record = clone $this->Record;
        $subquery_record->reset();

        $latest_sql = $subquery_record->select([
            'ai_messages.conversation_id',
            'MAX(ai_messages.id)' => 'max_id'
        ])
            ->from('ai_messages')
            ->group(['ai_messages.conversation_id'])
            ->get();
        $latest_values = $subquery_record->values;

        $this->Record->select([
            'ai_conversations.*',
            'ai_messages.content' => 'last_message_content',
            'ai_messages.date_created' => 'last_message_date'
        ])
            ->from('ai_conversations')
            ->appendValues($latest_values)
            ->leftJoin(
                [$latest_sql => 'latest'],
                'latest.conversation_id',
                '=',
                'ai_conversations.id',
                false
            )
            ->leftJoin(
                'ai_messages',
                'ai_messages.id',
                '=',
                'latest.max_id',
                false
            )
            ->where('ai_conversations.company_id', '=', $company_id);

        if ($staff_id !== null) {
            $this->Record->where('ai_conversations.staff_id', '=', $staff_id);
        }
        if ($status !== null) {
            $this->Record->where('ai_conversations.status', '=', $status);
        }
        if ($type !== null) {
            $this->Record->where('ai_conversations.type', '=', $type);
        }

        return $this->Record->order(['IFNULL(ai_messages.date_created,ai_conversations.date_created)' => 'DESC'], false)
            ->limit($per_page, (max(1, $page) - 1) * $per_page)
            ->fetchAll();
    }

    /**
     * Gets the total count of conversations for a company (used for pagination).
     *
     * @param int $company_id The company ID
     * @param int $staff_id The staff ID (optional, null to get all staff)
     * @param string $status The status filter (active/archived, null for all)
     * @param string $type The type filter (null for all)
     * @return int The total number of conversations
     */
    public function getAllWithPreviewCount($company_id, $staff_id = null, $status = 'active', $type = null)
    {
        $this->Record->select()
            ->from('ai_conversations')
            ->where('ai_conversations.company_id', '=', $company_id);

        if ($staff_id !== null) {
            $this->Record->where('ai_conversations.staff_id', '=', $staff_id);
        }
        if ($status !== null) {
            $this->Record->where('ai_conversations.status', '=', $status);
        }
        if ($type !== null) {
            $this->Record->where('ai_conversations.type', '=', $type);
        }

        return $this->Record->numResults();
    }

    /**
     * Archives a conversation
     *
     * @param int $conversation_id The conversation ID
     */
    public function archive($conversation_id)
    {
        $this->Record->where('id', '=', $conversation_id)
            ->update('ai_conversations', ['status' => 'archived']);
    }

    /**
     * Searches conversations by title
     *
     * @param int $company_id The company ID
     * @param int $staff_id The staff ID
     * @param string $query The search query
     * @return array An array of matching conversations
     */
    public function search($company_id, $staff_id, $query)
    {
        return $this->Record->select()
            ->from('ai_conversations')
            ->where('company_id', '=', $company_id)
            ->where('staff_id', '=', $staff_id)
            ->where('status', '=', 'active')
            ->like('title', '%' . $query . '%')
            ->order(['date_created' => 'DESC'])
            ->fetchAll();
    }

    /**
     * Updates a conversation title
     *
     * @param int $conversation_id The conversation ID
     * @param string $title The new title
     */
    public function updateTitle($conversation_id, $title)
    {
        $this->Record->where('id', '=', $conversation_id)
            ->update('ai_conversations', ['title' => $title]);
    }

    /**
     * Updates a conversation model
     *
     * @param int $conversation_id The conversation ID
     * @param string $model The new model ID
     */
    public function updateModel($conversation_id, $model)
    {
        $this->Record->where('id', '=', $conversation_id)
            ->update('ai_conversations', ['model' => $model]);
    }

    /**
     * Gets the most recent message for a conversation
     *
     * @param int $conversation_id The conversation ID
     * @return mixed A stdClass object representing the message, false if none
     */
    public function getLastMessage($conversation_id)
    {
        return $this->Record->select()
            ->from('ai_messages')
            ->where('conversation_id', '=', $conversation_id)
            ->order(['date_created' => 'DESC'])
            ->limit(1)
            ->fetch();
    }

    /**
     * Gets validation rules for adding/editing conversations
     *
     * @param array $vars An array of input data
     * @return array An array of validation rules
     */
    private function getRules(array $vars)
    {
        $rules = [
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('AiConversations.!error.company_id.exists')
                ]
            ],
            'staff_id' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('AiConversations.!error.staff_id.valid')
                ]
            ],
            'model' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('AiConversations.!error.model.empty')
                ]
            ],
            'status' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['active', 'archived']],
                    'message' => $this->_('AiConversations.!error.status.valid')
                ]
            ],
            'type' => [
                'length' => [
                    'if_set' => true,
                    'rule' => ['maxLength', 64],
                    'message' => $this->_('AiConversations.!error.type.length')
                ]
            ]
        ];

        return $rules;
    }
}
