<?php
/**
 * SupportManagerAiToolUses model
 *
 * Manages audit log of AI tool executions on support tickets
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerAiToolUses extends SupportManagerModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(
            'support_manager_ai_tool_uses',
            null,
            PLUGINDIR . 'support_manager' . DS . 'language' . DS
        );
    }

    /**
     * Logs a new AI tool use
     *
     * @param array $vars A list of input vars including:
     *  - ticket_id The ticket ID
     *  - tool_analysis_id The tool analysis ID that triggered this tool use (optional)
     *  - tool_name The tool name (e.g., 'close_ticket', 'change_priority')
     *  - arguments JSON string of arguments passed to tool
     *  - result JSON string of result from tool execution
     *  - confidence The confidence score (0-100)
     *  - executed_at The execution timestamp (optional, defaults to now)
     *  - executed_by Who executed the tool (default 'ai_system')
     * @return int|bool The tool use log ID, false on error
     */
    public function add(array $vars)
    {
        // Set default executed_at if not provided
        if (empty($vars['executed_at'])) {
            $vars['executed_at'] = date('Y-m-d H:i:s');
        }

        // Set default executed_by
        if (empty($vars['executed_by'])) {
            $vars['executed_by'] = 'ai_system';
        }

        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = [
                'ticket_id',
                'tool_analysis_id',
                'tool_name',
                'arguments',
                'result',
                'confidence',
                'executed_at',
                'executed_by'
            ];

            $this->Record->insert('support_ai_tool_uses', $vars, $fields);

            return $this->Record->lastInsertId();
        }

        return false;
    }

    /**
     * Gets an AI tool use by ID
     *
     * @param int $tool_use_id The tool use ID
     * @return mixed The tool use object, false if not found
     */
    public function get($tool_use_id)
    {
        return $this->Record->select()
            ->from('support_ai_tool_uses')
            ->where('id', '=', $tool_use_id)
            ->fetch();
    }

    /**
     * Gets all AI tool uses for a ticket
     *
     * @param int $ticket_id The ticket ID
     * @param string $tool_name Filter by specific tool name (optional)
     * @return array An array of tool use objects
     */
    public function getByTicketId($ticket_id, $tool_name = null)
    {
        $this->Record->select()
            ->from('support_ai_tool_uses')
            ->where('ticket_id', '=', $ticket_id);

        if ($tool_name !== null) {
            $this->Record->where('tool_name', '=', $tool_name);
        }

        return $this->Record->order(['executed_at' => 'DESC'])
            ->fetchAll();
    }

    /**
     * Gets all AI tool uses for a tool analysis
     *
     * @param int $tool_analysis_id The tool analysis ID
     * @return array An array of tool use objects
     */
    public function getByToolAnalysisId($tool_analysis_id)
    {
        return $this->Record->select()
            ->from('support_ai_tool_uses')
            ->where('tool_analysis_id', '=', $tool_analysis_id)
            ->order(['executed_at' => 'DESC'])
            ->fetchAll();
    }

    /**
     * Gets all AI tool uses with optional filters
     *
     * @param array $filters Filters including:
     *  - ticket_id Filter by ticket ID
     *  - tool_name Filter by tool name
     *  - date_from Filter by date range (from)
     *  - date_to Filter by date range (to)
     *  - limit Number of results to return
     *  - page Page number for pagination
     * @return array An array of tool use objects
     */
    public function getAll(array $filters = [])
    {
        $this->Record->select()->from('support_ai_tool_uses');

        // Apply filters
        if (!empty($filters['ticket_id'])) {
            $this->Record->where('ticket_id', '=', $filters['ticket_id']);
        }

        if (!empty($filters['tool_name'])) {
            $this->Record->where('tool_name', '=', $filters['tool_name']);
        }

        if (!empty($filters['date_from'])) {
            $this->Record->where('executed_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $this->Record->where('executed_at', '<=', $filters['date_to']);
        }

        // Order by most recent first
        $this->Record->order(['executed_at' => 'DESC']);

        // Apply pagination
        if (!empty($filters['limit'])) {
            $page = !empty($filters['page']) ? $filters['page'] : 1;
            $this->Record->limit($filters['limit'], ($page - 1) * $filters['limit']);
        }

        return $this->Record->fetchAll();
    }

    /**
     * Gets count of AI tool uses with optional filters
     *
     * @param array $filters Filters (same as getAll)
     * @return int The count of tool uses
     */
    public function getCount(array $filters = [])
    {
        $this->Record->select(['COUNT(*) as total'])->from('support_ai_tool_uses');

        // Apply same filters as getAll
        if (!empty($filters['ticket_id'])) {
            $this->Record->where('ticket_id', '=', $filters['ticket_id']);
        }

        if (!empty($filters['tool_name'])) {
            $this->Record->where('tool_name', '=', $filters['tool_name']);
        }

        if (!empty($filters['date_from'])) {
            $this->Record->where('executed_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $this->Record->where('executed_at', '<=', $filters['date_to']);
        }

        $result = $this->Record->fetch();
        return $result ? $result->total : 0;
    }

    /**
     * Gets statistics about AI tool uses
     *
     * @param array $filters Filters including:
     *  - date_from Filter by date range (from)
     *  - date_to Filter by date range (to)
     * @return array Statistics including total uses, uses by tool, average confidence
     */
    public function getStats(array $filters = [])
    {
        // Get total count
        $total = $this->getCount($filters);

        // Get tool uses by type
        $this->Record->select(['tool_name', 'COUNT(*) as count'])
            ->from('support_ai_tool_uses');

        if (!empty($filters['date_from'])) {
            $this->Record->where('executed_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $this->Record->where('executed_at', '<=', $filters['date_to']);
        }

        $by_type = $this->Record->group('tool_name')->fetchAll();

        // Get average confidence
        $this->Record->select(['AVG(confidence) as avg_confidence'])
            ->from('support_ai_tool_uses')
            ->where('confidence', 'IS NOT', null);

        if (!empty($filters['date_from'])) {
            $this->Record->where('executed_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $this->Record->where('executed_at', '<=', $filters['date_to']);
        }

        $confidence_result = $this->Record->fetch();
        $avg_confidence = $confidence_result ? $confidence_result->avg_confidence : 0;

        return [
            'total' => $total,
            'by_type' => $by_type,
            'avg_confidence' => round($avg_confidence, 2)
        ];
    }

    /**
     * Deletes an AI tool use log entry
     *
     * @param int $tool_use_id The tool use ID
     * @return bool True if deleted successfully
     */
    public function delete($tool_use_id)
    {
        $this->Record->from('support_ai_tool_uses')
            ->where('id', '=', $tool_use_id)
            ->delete();

        return true;
    }

    /**
     * Gets validation rules for adding AI tool uses
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
                    'message' => $this->_('SupportManagerAiToolUses.!error.ticket_id.exists')
                ]
            ],
            'tool_name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('SupportManagerAiToolUses.!error.tool_name.empty')
                ],
                'length' => [
                    'rule' => ['maxLength', 50],
                    'message' => $this->_('SupportManagerAiToolUses.!error.tool_name.length')
                ]
            ],
            'confidence' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('SupportManagerAiToolUses.!error.confidence.valid')
                ]
            ]
        ];

        return $rules;
    }
}
