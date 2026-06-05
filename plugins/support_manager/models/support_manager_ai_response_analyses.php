<?php
/**
 * SupportManagerAiResponseAnalyses model
 *
 * Manages AI-generated response analyses for support ticket replies
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerAiResponseAnalyses extends SupportManagerModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'support_manager_ai_response_analyses',
            null,
            PLUGINDIR . 'support_manager' . DS . 'language' . DS
        );
    }

    /**
     * Creates a new AI response analysis
     *
     * @param array $vars A list of input vars including:
     *  - ticket_id The ticket ID
     *  - conversation_id The AI conversation ID (optional)
     *  - response_text The generated response text
     *  - internal_notes Internal notes about the response
     *  - concerns JSON array of concerns identified
     *  - status The status ('pending', 'used', 'expired', 'no_response_needed')
     *  - confidence The confidence score (0-100)
     *  - confidence_reasoning Explanation for the confidence score
     *  - model The AI model used
     *  - prompt_tokens Token count for prompt
     *  - completion_tokens Token count for completion
     *  - cost Cost in USD
     *  - reply_ids Array of reply IDs to link to this analysis (optional)
     * @return int|bool The response analysis ID, false on error
     */
    public function add(array $vars)
    {
        // Set default created_at if not provided
        if (empty($vars['created_at'])) {
            $vars['created_at'] = date('Y-m-d H:i:s');
        }

        // Set default status if not provided
        if (empty($vars['status'])) {
            $vars['status'] = 'pending';
        }

        // Extract reply_ids before validation (not a database field)
        $reply_ids = $vars['reply_ids'] ?? null;
        unset($vars['reply_ids']);

        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = [
                'ticket_id',
                'conversation_id',
                'response_text',
                'internal_notes',
                'concerns',
                'status',
                'confidence',
                'confidence_reasoning',
                'model',
                'prompt_tokens',
                'completion_tokens',
                'cost',
                'created_at'
            ];

            $this->Record->insert('support_ai_response_analyses', $vars, $fields);
            $analysis_id = $this->Record->lastInsertId();

            // Link replies to this analysis if provided
            if ($analysis_id && !empty($reply_ids) && is_array($reply_ids)) {
                $this->Record->where('id', 'in', $reply_ids)
                    ->update('support_replies', ['ai_response_id' => $analysis_id]);
            }

            return $analysis_id;
        }

        return false;
    }

    /**
     * Gets an AI response analysis by ID
     *
     * @param int $id The response analysis ID
     * @return mixed The response analysis object, false if not found
     */
    public function get($id)
    {
        return $this->Record->select()
            ->from('support_ai_response_analyses')
            ->where('id', '=', $id)
            ->fetch();
    }

    /**
     * Gets the latest AI response analysis for a ticket
     *
     * @param int $ticket_id The ticket ID
     * @param string $status Filter by specific status (optional)
     * @return mixed The response analysis object, false if not found
     */
    public function getByTicketId($ticket_id, $status = null)
    {
        $this->Record->select()
            ->from('support_ai_response_analyses')
            ->where('ticket_id', '=', $ticket_id);

        if ($status !== null) {
            $this->Record->where('status', '=', $status);
        }

        return $this->Record->order(['created_at' => 'DESC'])
            ->fetch();
    }

    /**
     * Gets all AI response analyses for a ticket
     *
     * @param int $ticket_id The ticket ID
     * @param string $status Filter by specific status (optional)
     * @return array An array of response analysis objects
     */
    public function getAllByTicketId($ticket_id, $status = null)
    {
        $this->Record->select()
            ->from('support_ai_response_analyses')
            ->where('ticket_id', '=', $ticket_id);

        if ($status !== null) {
            $this->Record->where('status', '=', $status);
        }

        return $this->Record->order(['created_at' => 'DESC'])
            ->fetchAll();
    }

    /**
     * Gets AI response analysis by reply ID
     *
     * @param int $reply_id The reply ID
     * @return mixed The response analysis object, false if not found
     */
    public function getByReplyId($reply_id)
    {
        return $this->Record->select(['support_ai_response_analyses.*'])
            ->from('support_ai_response_analyses')
            ->innerJoin('support_replies', 'support_replies.ai_response_id', '=', 'support_ai_response_analyses.id', false)
            ->where('support_replies.id', '=', $reply_id)
            ->fetch();
    }

    /**
     * Marks a response analysis as used
     *
     * @param int $id The response analysis ID
     * @param int $staff_id The staff member who used the response
     * @return bool True on success
     */
    public function markUsed($id, $staff_id)
    {
        $this->Record->where('id', '=', $id)
            ->update('support_ai_response_analyses', [
                'status' => 'used',
                'used_at' => date('Y-m-d H:i:s'),
                'used_by_staff_id' => $staff_id,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        return true;
    }

    /**
     * Marks a response analysis as expired
     *
     * @param int $id The response analysis ID
     * @return bool True on success
     */
    public function markExpired($id)
    {
        $this->Record->where('id', '=', $id)
            ->update('support_ai_response_analyses', [
                'status' => 'expired',
                'expired_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        return true;
    }

    /**
     * Expires all pending response analyses for a ticket
     * Used when a new client reply arrives to invalidate previous responses
     *
     * This expires both reply-linked analyses and replyless analyses (manual generations).
     *
     * @param int $ticket_id The ticket ID
     * @return bool True on success
     */
    public function expirePendingForTicket($ticket_id)
    {
        // Expire all pending responses for this ticket (both linked and replyless)
        $this->Record->where('ticket_id', '=', $ticket_id)
            ->where('status', '=', 'pending')
            ->update('support_ai_response_analyses', [
                'status' => 'expired',
                'expired_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        return true;
    }

    /**
     * Deletes a response analysis (for regeneration)
     *
     * Automatically unlinks any replies that were associated with this analysis
     * before deleting it, allowing them to be reprocessed.
     *
     * @param int $id The response analysis ID
     * @return bool True if deleted successfully
     */
    public function delete($id)
    {
        // Unlink any replies associated with this analysis
        $this->Record->where('ai_response_id', '=', $id)
            ->update('support_replies', ['ai_response_id' => null]);

        // Delete the analysis
        $this->Record->from('support_ai_response_analyses')
            ->where('id', '=', $id)
            ->delete();

        return true;
    }

    /**
     * Gets cost summary for response analyses
     *
     * @param string $date_from Start date (optional)
     * @param string $date_to End date (optional)
     * @return array Summary with total cost, token counts, and analysis count
     */
    public function getCostSummary($date_from = null, $date_to = null)
    {
        $this->Record->select([
            'COUNT(*) as total_analyses',
            'SUM(cost) as total_cost',
            'SUM(prompt_tokens) as total_prompt_tokens',
            'SUM(completion_tokens) as total_completion_tokens',
            'AVG(confidence) as avg_confidence'
        ])->from('support_ai_response_analyses');

        if ($date_from) {
            $this->Record->where('created_at', '>=', $date_from);
        }

        if ($date_to) {
            $this->Record->where('created_at', '<=', $date_to);
        }

        $result = $this->Record->fetch();

        return [
            'total_analyses' => $result ? $result->total_analyses : 0,
            'total_cost' => $result ? $result->total_cost : 0,
            'total_prompt_tokens' => $result ? $result->total_prompt_tokens : 0,
            'total_completion_tokens' => $result ? $result->total_completion_tokens : 0,
            'avg_confidence' => $result ? round($result->avg_confidence, 2) : 0
        ];
    }

    /**
     * Gets validation rules for adding AI response analyses
     *
     * @param array $vars A list of input vars
     * @return array A list of rules
     */
    private function getRules(array $vars)
    {
        $rules = [
            'ticket_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'support_tickets'],
                    'message' => $this->_('SupportManagerAiResponseAnalyses.!error.ticket_id.exists')
                ]
            ],
            'status' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['pending', 'used', 'expired', 'no_response_needed']],
                    'message' => $this->_('SupportManagerAiResponseAnalyses.!error.status.valid')
                ]
            ],
            'confidence' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('SupportManagerAiResponseAnalyses.!error.confidence.valid')
                ]
            ]
        ];

        return $rules;
    }
}
