<?php
/**
 * SupportManagerAiToolAnalyses model
 *
 * Manages AI tool analyses for support ticket replies
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerAiToolAnalyses extends SupportManagerModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'support_manager_ai_tool_analyses',
            null,
            PLUGINDIR . 'support_manager' . DS . 'language' . DS
        );
    }

    /**
     * Creates a new AI tool analysis
     *
     * @param array $vars A list of input vars including:
     *  - ticket_id The ticket ID
     *  - conversation_id The AI conversation ID (optional)
     *  - suggested_tools JSON array of suggested tools
     *  - analysis_notes Notes about the tool analysis
     *  - concerns JSON array of concerns identified
     *  - execution_status The execution status ('pending', 'completed', 'failed', 'no_tools_needed')
     *  - model The AI model used
     *  - prompt_tokens Token count for prompt
     *  - completion_tokens Token count for completion
     *  - cost Cost in USD
     * @return int|bool The tool analysis ID, false on error
     */
    public function add(array $vars)
    {
        // Set default created_at if not provided
        if (empty($vars['created_at'])) {
            $vars['created_at'] = date('Y-m-d H:i:s');
        }

        // Set default execution_status if not provided
        if (empty($vars['execution_status'])) {
            $vars['execution_status'] = 'pending';
        }

        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = [
                'ticket_id',
                'conversation_id',
                'suggested_tools',
                'analysis_notes',
                'concerns',
                'execution_status',
                'model',
                'prompt_tokens',
                'completion_tokens',
                'cost',
                'created_at'
            ];

            $this->Record->insert('support_ai_tool_analyses', $vars, $fields);

            return $this->Record->lastInsertId();
        }

        return false;
    }

    /**
     * Gets an AI tool analysis by ID
     *
     * @param int $id The tool analysis ID
     * @return mixed The tool analysis object, false if not found
     */
    public function get($id)
    {
        return $this->Record->select()
            ->from('support_ai_tool_analyses')
            ->where('id', '=', $id)
            ->fetch();
    }

    /**
     * Gets the latest AI tool analysis for a ticket
     *
     * @param int $ticket_id The ticket ID
     * @param string $execution_status Filter by specific status (optional)
     * @return mixed The tool analysis object, false if not found
     */
    public function getByTicketId($ticket_id, $execution_status = null)
    {
        $this->Record->select()
            ->from('support_ai_tool_analyses')
            ->where('ticket_id', '=', $ticket_id);

        if ($execution_status !== null) {
            $this->Record->where('execution_status', '=', $execution_status);
        }

        return $this->Record->order(['created_at' => 'DESC'])
            ->fetch();
    }

    /**
     * Gets all AI tool analyses for a ticket
     *
     * @param int $ticket_id The ticket ID
     * @param string $execution_status Filter by specific status (optional)
     * @return array An array of tool analysis objects
     */
    public function getAllByTicketId($ticket_id, $execution_status = null)
    {
        $this->Record->select()
            ->from('support_ai_tool_analyses')
            ->where('ticket_id', '=', $ticket_id);

        if ($execution_status !== null) {
            $this->Record->where('execution_status', '=', $execution_status);
        }

        return $this->Record->order(['created_at' => 'DESC'])
            ->fetchAll();
    }

    /**
     * Gets AI tool analysis by reply ID
     *
     * @param int $reply_id The reply ID
     * @return mixed The tool analysis object, false if not found
     */
    public function getByReplyId($reply_id)
    {
        return $this->Record->select(['support_ai_tool_analyses.*'])
            ->from('support_ai_tool_analyses')
            ->innerJoin('support_replies', 'support_replies.ai_tool_analysis_id', '=', 'support_ai_tool_analyses.id', false)
            ->where('support_replies.id', '=', $reply_id)
            ->fetch();
    }

    /**
     * Marks a tool analysis as executed with execution statistics
     *
     * @param int $id The tool analysis ID
     * @param array $stats Execution statistics including:
     *  - execution_status The final execution status
     *  - tools_executed_count Number of tools successfully executed
     *  - tools_skipped_count Number of tools skipped
     *  - tools_failed_count Number of tools that failed
     * @return bool True on success
     */
    public function markExecuted($id, array $stats)
    {
        $update_data = [
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if (isset($stats['execution_status'])) {
            $update_data['execution_status'] = $stats['execution_status'];
            $update_data['executed_at'] = date('Y-m-d H:i:s');
        }

        if (isset($stats['tools_executed_count'])) {
            $update_data['tools_executed_count'] = $stats['tools_executed_count'];
        }

        if (isset($stats['tools_skipped_count'])) {
            $update_data['tools_skipped_count'] = $stats['tools_skipped_count'];
        }

        if (isset($stats['tools_failed_count'])) {
            $update_data['tools_failed_count'] = $stats['tools_failed_count'];
        }

        $this->Record->where('id', '=', $id)
            ->update('support_ai_tool_analyses', $update_data);

        return true;
    }

    /**
     * Deletes a tool analysis (for regeneration)
     *
     * @param int $id The tool analysis ID
     * @return bool True if deleted successfully
     */
    public function delete($id)
    {
        $this->Record->from('support_ai_tool_analyses')
            ->where('id', '=', $id)
            ->delete();

        return true;
    }

    /**
     * Gets cost summary for tool analyses
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
            'SUM(tools_executed_count) as total_tools_executed',
            'SUM(tools_skipped_count) as total_tools_skipped',
            'SUM(tools_failed_count) as total_tools_failed'
        ])->from('support_ai_tool_analyses');

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
            'total_tools_executed' => $result ? $result->total_tools_executed : 0,
            'total_tools_skipped' => $result ? $result->total_tools_skipped : 0,
            'total_tools_failed' => $result ? $result->total_tools_failed : 0
        ];
    }

    /**
     * Gets validation rules for adding AI tool analyses
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
                    'message' => $this->_('SupportManagerAiToolAnalyses.!error.ticket_id.exists')
                ]
            ],
            'execution_status' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['pending', 'completed', 'failed', 'no_tools_needed']],
                    'message' => $this->_('SupportManagerAiToolAnalyses.!error.execution_status.valid')
                ]
            ]
        ];

        return $rules;
    }
}
