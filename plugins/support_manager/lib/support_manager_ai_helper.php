<?php
use Blesta\Core\Util\Common\Traits\Container;

/**
 * SupportManagerAiHelper
 *
 * Helper class for AI-powered features in Support Manager
 * Handles message building, sensitive data masking, response generation,
 * confidence evaluation, and tool calling
 *
 * @package blesta
 * @subpackage plugins.supportmanager.lib
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerAiHelper
{
    use Container;

    /**
     * @var SupportManagerTickets
     */
    public $SupportManagerTickets;

    /**
     * @var SupportManagerSettings
     */
    public $SupportManagerSettings;

    /**
     * @var SupportManagerAiResponseAnalyses
     */
    public $SupportManagerAiResponseAnalyses;

    /**
     * @var SupportManagerAiToolAnalyses
     */
    public $SupportManagerAiToolAnalyses;

    /**
     * @var SupportManagerAiToolUses
     */
    public $SupportManagerAiToolUses;

    /**
     * @var Staff
     */
    public $Staff;

    /**
     * @var Monolog\Logger An instance of the logger
     */
    protected $logger;

    /**
     * @var int Company ID
     */
    public $company_id;

    /**
     * Constructor
     *
     * @param int $company_id The company ID
     */
    public function __construct($company_id)
    {
        $this->company_id = $company_id;

        // Load models
        Loader::loadModels($this, [
            'SupportManager.SupportManagerTickets',
            'SupportManager.SupportManagerSettings',
            'SupportManager.SupportManagerAiResponseAnalyses',
            'SupportManager.SupportManagerAiToolAnalyses',
            'SupportManager.SupportManagerAiToolUses',
            'Staff'
        ]);

        // Load BlestaAi component
        Loader::loadComponents($this, ['BlestaAi']);

        // Initialize logger
        $logger = $this->getFromContainer('logger');
        $this->logger = $logger;
    }

    /**
     * Checks if debug logging is enabled for AI features
     *
     * @return bool True if debug logging is enabled
     */
    private function isDebugEnabled()
    {
        return Configure::get('SupportManager.ai_debug_logging') === true;
    }

    /**
     * Builds message history for a ticket in the format expected by the AI
     *
     * Format: [Client - Name - Date] message or [Staff - Name - Date] message
     *
     * @param object $ticket The ticket object (from SupportManagerTickets->get())
     * @return array Array of message objects with 'role' and 'content'
     */
    private function buildTicketMessages($ticket)
    {
        $replies = $ticket->replies;
        $messages = [];

        foreach ($replies as $reply) {
            // Determine sender type and name
            if ($reply->staff_id) {
                $sender = 'Staff - ' . $reply->first_name . ' ' . $reply->last_name;
            } else {
                $sender = 'Client - ' . ($reply->first_name ?? 'Unknown') . ' ' . ($reply->last_name ?? '');
            }

            // Format timestamp
            $timestamp = date('Y-m-d H:i:s', strtotime($reply->date_added));

            // Build message content with label
            $content = "[{$sender} - {$timestamp}] {$reply->details}";

            // All messages use 'user' role as per TOOL_CALLING.md spec
            $messages[] = [
                'role' => 'user',
                'content' => $content
            ];
        }

        return $messages;
    }

    /**
     * Builds metadata message for a ticket
     *
     * Includes: Ticket #, Department, Priority, Status, Assigned To, Client, Subject, Available Staff
     *
     * @param object $ticket The ticket object (from SupportManagerTickets->get())
     * @return string Formatted metadata string
     */
    private function buildTicketMetadata($ticket)
    {
        // Get available staff for the department
        $staff = $this->Staff->getAll($this->company_id, 'active');

        // Format assigned staff
        $assigned = $ticket->staff_id
            ? "{$ticket->assigned_staff_first_name} {$ticket->assigned_staff_last_name} (ID: {$ticket->staff_id})"
            : 'Unassigned';

        // Format available staff list with IDs
        $staff_list = [];
        foreach ($staff as $s) {
            $role = !empty($s->title) ? $s->title : 'Staff';
            $staff_list[] = "{$s->first_name} {$s->last_name} (ID: {$s->id}, {$role})";
        }

        // Format client
        $client = $ticket->client_id
            ? "{$ticket->contact_first_name} {$ticket->contact_last_name} (ID: {$ticket->client_id})"
            : ($ticket->email ?? 'No client');

        // Build metadata string
        $metadata = "Ticket #{$ticket->code} | "
            . "Department: {$ticket->department_name} | "
            . "Priority: " . ucfirst($ticket->priority) . " | "
            . "Status: " . ucfirst(str_replace('_', ' ', $ticket->status)) . " | "
            . "Assigned To: {$assigned} | "
            . "Client: {$client} | "
            . "Subject: {$ticket->summary} | "
            . "Available Staff: " . implode(', ', $staff_list);

        return $metadata;
    }

    /**
     * Masks sensitive data in content before sending to AI
     *
     * Masks: credit cards, API keys, passwords, license keys, SSNs, phone numbers
     *
     * @param string $content The content to mask
     * @return string The masked content
     */
    private function maskSensitiveData($content)
    {
        // Credit card numbers (Visa, MC, Amex, Discover)
        $content = preg_replace(
            '/\b(?:\d{4}[\s-]?){3}\d{4}\b/',
            '[REDACTED_CREDIT_CARD]',
            $content
        );

        // API keys with common prefixes (sk_, api_, key_, token_, pk_, etc.)
        // More specific than matching any 32+ char string to avoid false positives
        $content = preg_replace(
            '/\b(sk|pk|api|key|token|secret|auth)_[a-zA-Z0-9_-]{20,}\b/i',
            '[REDACTED_API_KEY]',
            $content
        );

        // Passwords - improved pattern to catch more variations
        // Matches: "password is abc", "pass = 'abc'", "my password: abc123", etc.
        $content = preg_replace(
            '/\b(password|passwd|pass|pwd|pw)\s*(is|=|:)?\s*["\']?([^\s"\']+)["\']?/i',
            '$1$2 [REDACTED_PASSWORD]',
            $content
        );

        // License keys - case insensitive, supports 4-5 segments
        // Matches: XXXX-XXXX-XXXX-XXXX or XXXX-XXXX-XXXX-XXXX-XXXX
        $content = preg_replace(
            '/\b[A-Z0-9]{3,5}(?:-[A-Z0-9]{3,5}){3,4}\b/i',
            '[REDACTED_LICENSE_KEY]',
            $content
        );

        // Social Security Numbers (XXX-XX-XXXX)
        $content = preg_replace(
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '[REDACTED_SSN]',
            $content
        );

        // Phone numbers (various formats)
        // Matches: (555) 123-4567, 555-123-4567, 555.123.4567, +1-555-123-4567
        $content = preg_replace(
            '/\b(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/',
            '[REDACTED_PHONE]',
            $content
        );

        return $content;
    }


    /**
     * Evaluates the quality and confidence of an AI-generated response
     * Creates a conversation record for audit trail
     *
     * @param object $ticket The ticket object (from SupportManagerTickets->get())
     * @param string $proposed_response The AI-generated response to evaluate
     * @param array $settings The AI settings (from getSettings())
     * @return array Evaluation with 'confidence', 'reasoning', 'concerns', 'recommendation', 'conversation_id'
     * @throws Exception On API errors
     */
    private function evaluateResponse($ticket, $proposed_response, $settings)
    {
        // Get ticket replies to build context
        $replies = $ticket->replies;

        // Build original ticket content string
        $original_content = "Subject: {$ticket->summary}\n\n";

        // Add all replies to show full ticket history
        if (!empty($replies)) {
            foreach ($replies as $reply) {
                $original_content .= "---\n{$reply->details}\n";
            }
        }

        // Build evaluation message
        $evaluation_message = "Original Ticket:\n{$original_content}\n\n";
        $evaluation_message .= "Proposed Response:\n{$proposed_response}\n\n";
        $evaluation_message .= "Evaluate this response and provide confidence assessment.";

        // Initialize BlestaAi
        Loader::loadComponents($this, ['BlestaAi']);
        $ai = new BlestaAi();

        // Use the configured model for evaluation
        // NOTE: Using a cheaper/faster model like claude-3-5-haiku-20241022 would improve efficiency
        $eval_model = $this->getSettingWithFallback($settings, 'sm_ai_model', 'ai_default_model', 'claude-3-5-sonnet-20241022', 'sm_ai_override_model');

        // Create conversation for evaluation (creates audit record)
        $conversation_id = $ai->createConversation(
            $this->company_id,
            0, // System/AI staff ID
            $eval_model,
            "Evaluation: Ticket #{$ticket->code}"
        );

        // System prompt for evaluation
        $system_prompt = 'You are a quality assurance evaluator for customer support responses. Analyze the proposed response and rate its confidence level (0-100) based on:

1. **Completeness**: Does it fully address all aspects of the question?
2. **Accuracy**: Is the information correct and specific to the ticket?
3. **Clarity**: Is the response clear and unambiguous?
4. **Actionability**: Can the customer act on this information?
5. **Risk**: Could this response cause issues if incorrect?

Respond in JSON format:
{
    "confidence": 0-100,
    "reasoning": "explanation",
    "concerns": ["list of any concerns"],
    "recommendation": "auto_send" or "human_review"
}';

        // Make evaluation API call
        $response = $ai->chat($conversation_id, $evaluation_message, [
            'system_prompt' => $system_prompt,
            'temperature' => 0.3, // Lower temperature for more consistent evaluation
            'max_tokens' => 600, // Increased to prevent truncation of detailed evaluations
            'response_format' => ['type' => 'json_object'] // Force JSON mode
        ]);

        // Parse JSON response using robust error handling
        $evaluation = $this->parseJsonResponse($response['content']);

        // Validate response structure
        if (!is_array($evaluation) || !isset($evaluation['confidence'])) {
            // Log the parsing failure with the raw response for debugging
            $this->logger->error('Support Manager AI: Confidence evaluation JSON parsing failed', [
                'ticket_id' => $ticket->id,
                'conversation_id' => $conversation_id,
                'raw_response' => $response['content'],
                'parsed_result' => $evaluation
            ]);

            // Fallback if JSON parsing fails
            return [
                'confidence' => 50,
                'reasoning' => 'Failed to parse evaluation response: ' . json_last_error_msg(),
                'concerns' => ['Evaluation parsing error'],
                'recommendation' => 'human_review',
                'conversation_id' => $conversation_id
            ];
        }

        return [
            'confidence' => (int)$evaluation['confidence'],
            'reasoning' => $evaluation['reasoning'] ?? 'No reasoning provided',
            'concerns' => $evaluation['concerns'] ?? [],
            'recommendation' => $evaluation['recommendation'] ?? 'human_review',
            'conversation_id' => $conversation_id
        ];
    }

    /**
     * Generate AI tool analysis for batched ticket replies
     *
     * Generates tool suggestions for one or more unprocessed replies.
     * This is the first step in the automated workflow.
     *
     * @param array $reply_ids Array of reply IDs being analyzed
     * @param object $ticket The ticket object (from SupportManagerTickets->get())
     * @param array $unprocessed_replies Array of unprocessed reply objects
     * @param array $options Configuration:
     *  - save_to_db: bool - Save analysis to database (default: true)
     * @return array Result with 'analysis_id', 'tool_calls', 'notes'
     * @throws Exception On API errors
     */
    public function generateToolAnalysisForReplies(array $reply_ids, $ticket, array $unprocessed_replies, array $options = [])
    {
        $save_to_db = $options['save_to_db'] ?? true;

        // Get settings
        $settings = $this->getSettings();

        // Get full ticket conversation history for context
        $all_replies = $ticket->replies ?? [];

        // Build full conversation history with markers for new messages
        $conversation_history = [];
        $new_messages = [];

        foreach ($all_replies as $reply) {
            $is_new = in_array($reply->id, $reply_ids);
            $is_staff = ($reply->staff_id !== null && $reply->staff_id !== '');
            $sender_type = $is_staff ? ($reply->staff_id == 0 ? 'AI Assistant' : 'Staff') : 'Client';
            $sender_name = $is_staff
                ? ($reply->staff_id == 0 ? 'AI' : ($reply->first_name . ' ' . $reply->last_name))
                : ($reply->first_name ?? 'Client');
            $timestamp = date('Y-m-d H:i', strtotime($reply->date_added));

            $message_line = "[{$sender_type}: {$sender_name} at {$timestamp}] {$this->maskSensitiveData($reply->details)}";

            if ($is_new) {
                $new_messages[] = $message_line;
                $conversation_history[] = ">>> NEW MESSAGE >>> " . $message_line;
            } else {
                $conversation_history[] = $message_line;
            }
        }

        // Build metadata
        $metadata = $this->buildTicketMetadata($ticket);
        $metadata = $this->maskSensitiveData($metadata);

        // Format ticket context with clear instructions
        $ticket_context = "=== TICKET METADATA ===\n{$metadata}\n\n";
        $ticket_context .= "=== FULL CONVERSATION HISTORY ===\n";
        $ticket_context .= "Below is the complete ticket conversation. Messages marked with '>>> NEW MESSAGE >>>' are unprocessed client messages that require your analysis.\n";
        $ticket_context .= "Old messages are provided for context only. Focus your tool suggestions on addressing the NEW messages.\n\n";
        $ticket_context .= implode("\n", $conversation_history);
        $ticket_context .= "\n\n=== YOUR TASK ===\n";
        $ticket_context .= "Analyze the " . count($new_messages) . " NEW message(s) marked above and determine if any management tools should be used.\n";
        $ticket_context .= "Base your analysis on the new messages while considering the full conversation context.\n";
        $ticket_context .= "\n---\nProvide your JSON analysis now.";

        // Initialize BlestaAi and create conversation
        Loader::loadComponents($this, ['BlestaAi']);
        $ai = new BlestaAi();
        $model = $this->getSettingWithFallback($settings, 'sm_ai_model', 'ai_default_model', 'claude-3-5-sonnet-20241022', 'sm_ai_override_model');

        $conversation_id = $ai->createConversation(
            $this->company_id,
            0,
            $model,
            "Tool Analysis: Ticket #{$ticket->code}"
        );

        // Build system prompt for tool analysis only
        $system_prompt = $this->buildToolUseOnlyPrompt($settings);

        // Prepare chat options
        $temperature = $this->getSettingWithFallback($settings, 'sm_ai_temperature', 'ai_temperature', 1.0, 'sm_ai_override_temperature');
        $temperature = min($temperature, 0.7);

        $chat_options = [
            'system_prompt' => $system_prompt,
            'temperature' => $temperature,
            'max_tokens' => $this->getSettingWithFallback($settings, 'sm_ai_max_tokens', 'ai_max_tokens', 4000, 'sm_ai_override_max_tokens'),
        ];

        // Add tool definitions (response_format json_object is incompatible with
        // tool use - it forces the model to output JSON text instead of calling tools)
        $tools = $this->getToolDefinitions($settings);
        if (!empty($tools)) {
            $chat_options['tools'] = $tools;
            $chat_options['tool_choice'] = 'auto';
        } else {
            $chat_options['response_format'] = ['type' => 'json_object'];
        }

        // Make API call
        $response = $ai->chat($conversation_id, $ticket_context, $chat_options);

        // Extract tool calls first - when the AI uses tools, content may be empty
        $tool_calls = $response['tool_calls'] ?? [];

        // Parse JSON content (may be empty/non-JSON when AI responds with tool calls only)
        $parsed_response = !empty($response['content'])
            ? $this->parseJsonResponse($response['content'])
            : ['notes' => null, 'confidence' => 0, 'concerns' => []];

        // Determine execution status
        $execution_status = 'pending';
        if (empty($tool_calls)) {
            $execution_status = 'no_tools_needed';
        }

        // Save to database if requested
        $analysis_id = null;
        if ($save_to_db) {
            $analysis_id = $this->SupportManagerAiToolAnalyses->add([
                'ticket_id' => $ticket->id,
                'conversation_id' => $conversation_id,
                'suggested_tools' => !empty($tool_calls) ? json_encode($tool_calls) : null,
                'analysis_notes' => $parsed_response['notes'] ?? null,
                'concerns' => !empty($parsed_response['concerns']) ? json_encode($parsed_response['concerns']) : null,
                'execution_status' => $execution_status,
                'model' => $model,
                'prompt_tokens' => $response['prompt_tokens'],
                'completion_tokens' => $response['completion_tokens'],
                'cost' => $response['cost']
            ]);
        }

        return [
            'analysis_id' => $analysis_id,
            'tool_calls' => $tool_calls,
            'notes' => $parsed_response['notes'] ?? ''
        ];
    }

    /**
     * Generate AI response for batched ticket replies
     *
     * Generates a customer-facing reply for one or more unprocessed replies.
     * This is the second step in the automated workflow (after tool execution).
     *
     * @param array $reply_ids Array of reply IDs being analyzed
     * @param object $ticket The ticket object (from SupportManagerTickets->get())
     * @param array $unprocessed_replies Array of unprocessed reply objects
     * @param array $options Configuration:
     *  - save_to_db: bool - Save analysis to database (default: true)
     * @return int|false Analysis ID from support_ai_response_analyses table, or false on error
     * @throws Exception On API errors
     */
    public function generateResponseForReplies(array $reply_ids, $ticket, array $unprocessed_replies, array $options = [])
    {
        $save_to_db = $options['save_to_db'] ?? true;

        // Check if ticket is still open (skip response if tools closed ticket)
        if ($ticket->status === 'closed') {
            if ($this->isDebugEnabled()) {
                $this->logger->info('[AI Helper] Skipping response generation - ticket was closed by tools', [
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code
                ]);
            }
            return false;
        }

        // Get settings
        $settings = $this->getSettings();

        // Get full ticket conversation history for context
        $all_replies = $ticket->replies ?? [];

        // Determine if this is manual generation with full context (no specific new replies)
        $is_full_context_generation = empty($reply_ids);

        // Build full conversation history with markers for new messages
        $conversation_history = [];
        $new_messages = [];

        foreach ($all_replies as $reply) {
            $is_new = !$is_full_context_generation && in_array($reply->id, $reply_ids);
            $is_staff = ($reply->staff_id !== null && $reply->staff_id !== '');
            $sender_type = $is_staff ? ($reply->staff_id == 0 ? 'AI Assistant' : 'Staff') : 'Client';
            $sender_name = $is_staff
                ? ($reply->staff_id == 0 ? 'AI' : ($reply->first_name . ' ' . $reply->last_name))
                : ($reply->first_name ?? 'Client');
            $timestamp = date('Y-m-d H:i', strtotime($reply->date_added));

            $message_line = "[{$sender_type}: {$sender_name} at {$timestamp}] {$this->maskSensitiveData($reply->details)}";

            if ($is_new) {
                $new_messages[] = $message_line;
                $conversation_history[] = ">>> NEW MESSAGE >>> " . $message_line;
            } else {
                $conversation_history[] = $message_line;
            }
        }

        // Build metadata
        $metadata = $this->buildTicketMetadata($ticket);
        $metadata = $this->maskSensitiveData($metadata);

        // Format ticket context with clear instructions
        $ticket_context = "=== TICKET METADATA ===\n{$metadata}\n\n";
        $ticket_context .= "=== FULL CONVERSATION HISTORY ===\n";

        if ($is_full_context_generation) {
            // Manual generation with full context - no specific new messages
            $ticket_context .= "Below is the complete ticket conversation.\n\n";
            $ticket_context .= implode("\n", $conversation_history);
            $ticket_context .= "\n\n=== YOUR TASK ===\n";
            $ticket_context .= "Generate a response based on the full conversation history above.\n";
            $ticket_context .= "Consider the entire context and provide an appropriate response to move the ticket forward.\n";
            $ticket_context .= "If no response is needed, return 'no response needed'.\n";
        } else {
            // Automated generation with specific new messages
            $ticket_context .= "Below is the complete ticket conversation. Messages marked with '>>> NEW MESSAGE >>>' are unprocessed client messages that need a response.\n";
            $ticket_context .= "Old messages are provided for context only. Your response should address the NEW messages.\n\n";
            $ticket_context .= implode("\n", $conversation_history);
            $ticket_context .= "\n\n=== YOUR TASK ===\n";
            $ticket_context .= "Generate a response to the " . count($new_messages) . " NEW client message(s) marked above.\n";
            $ticket_context .= "Use the full conversation history for context, but focus your response on addressing the new messages.\n";
            $ticket_context .= "If the new messages don't require a response (e.g., just 'thank you'), return 'no response needed'.\n";
        }

        $ticket_context .= "\n---\nProvide your JSON response now.";

        // Initialize BlestaAi and create conversation
        Loader::loadComponents($this, ['BlestaAi']);
        $ai = new BlestaAi();
        $model = $this->getSettingWithFallback($settings, 'sm_ai_model', 'ai_default_model', 'claude-3-5-sonnet-20241022', 'sm_ai_override_model');

        $conversation_id = $ai->createConversation(
            $this->company_id,
            0,
            $model,
            "Response: Ticket #{$ticket->code}"
        );

        // Build system prompt for response only
        $system_prompt = $this->buildResponseOnlyPrompt($settings);

        // Prepare chat options
        $temperature = $this->getSettingWithFallback($settings, 'sm_ai_temperature', 'ai_temperature', 1.0, 'sm_ai_override_temperature');
        $temperature = min($temperature, 0.7);

        $chat_options = [
            'system_prompt' => $system_prompt,
            'temperature' => $temperature,
            'max_tokens' => $this->getSettingWithFallback($settings, 'sm_ai_max_tokens', 'ai_max_tokens', 4000, 'sm_ai_override_max_tokens'),
            'response_format' => ['type' => 'json_object']
        ];

        // IMPORTANT: Never include tools for response generation

        // Make API call
        $response = $ai->chat($conversation_id, $ticket_context, $chat_options);

        // Parse response
        $parsed_response = $this->parseJsonResponse($response['content']);

        // Two-step confidence evaluation (existing logic)
        $evaluation = null;
        if (!empty($parsed_response['response'])) {
            try {
                $evaluation = $this->evaluateResponse($ticket, $parsed_response['response'], $settings);
            } catch (Exception $e) {
                // Log error but continue with initial confidence
                $this->logger->error('Support Manager AI: Confidence evaluation failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage()
                ]);
                $evaluation = null; // Will fall back to initial response confidence
            }
        }

        // Determine status
        $status = 'pending';
        if (empty($parsed_response['response']) || strtolower($parsed_response['response']) === 'no response needed') {
            $status = 'no_response_needed';
        }

        // Save to database if requested
        $analysis_id = null;
        if ($save_to_db) {
            $analysis_data = [
                'ticket_id' => $ticket->id,
                'conversation_id' => $conversation_id,
                'response_text' => $parsed_response['response'] ?? null,
                'internal_notes' => $parsed_response['notes'] ?? null,
                'concerns' => !empty($parsed_response['concerns']) ? json_encode($parsed_response['concerns']) : null,
                'status' => $status,
                'model' => $model,
                'prompt_tokens' => $response['prompt_tokens'],
                'completion_tokens' => $response['completion_tokens'],
                'cost' => $response['cost'],
                'reply_ids' => $reply_ids  // Include reply IDs for automatic linking
            ];

            // Use evaluated confidence if available and valid
            if ($evaluation && isset($evaluation['confidence']) && $evaluation['confidence'] > 0) {
                $analysis_data['confidence'] = $evaluation['confidence'];
                $analysis_data['confidence_reasoning'] = $evaluation['reasoning'] ?? null;
            } else {
                // Fall back to initial response confidence or default
                $analysis_data['confidence'] = $parsed_response['confidence'] ?? 50;
            }

            $analysis_id = $this->SupportManagerAiResponseAnalyses->add($analysis_data);
        }

        return $analysis_id;
    }

    /**
     * Parses JSON response from AI with multiple fallback strategies
     *
     * @param string $content The AI response content
     * @return array Parsed JSON data or fallback structure
     */
    private function parseJsonResponse($content)
    {
        // Try as raw JSON first
        $parsed = json_decode($content, true);

        // Try extracting from markdown code blocks
        if (!is_array($parsed)) {
            if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
                $parsed = json_decode($matches[1], true);
            } elseif (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
                $parsed = json_decode($matches[1], true);
            }
        }

        // Try extracting JSON object from anywhere in response
        if (!is_array($parsed)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json_str = substr($content, $start, $end - $start + 1);
                $parsed = json_decode($json_str, true);
            }
        }

        // Fallback if all parsing fails
        if (!is_array($parsed)) {
            $this->logger->error('Support Manager AI: JSON parsing failed - ' . json_last_error_msg());
            return [
                'notes' => 'JSON parsing failed: ' . json_last_error_msg(),
                'response' => null,
                'confidence' => 0,
                'concerns' => ['Parsing error - check conversation audit trail']
            ];
        }

        return $parsed;
    }

    /**
     * Gets tool definitions for AI function calling
     *
     * @param array $settings The AI settings
     * @return array Array of tool definition objects
     */
    public function getToolDefinitions(array $settings)
    {
        $tools = [];

        // Debug: Log tool settings
        if ($this->isDebugEnabled()) {
            $this->logger->debug("Support Manager AI: Tool settings - " .
                "tools_enabled=" . ($settings['sm_ai_tools_enabled'] ?? 'not set') . ", " .
                "close=" . ($settings['sm_ai_tool_close_ticket'] ?? 'not set') . ", " .
                "priority=" . ($settings['sm_ai_tool_change_priority'] ?? 'not set') . ", " .
                "assign=" . ($settings['sm_ai_tool_assign_staff'] ?? 'not set')
            );
        }

        // Tool 1: Change Priority
        if (!empty($settings['sm_ai_tool_change_priority']) && $settings['sm_ai_tool_change_priority'] === 'true') {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'change_priority',
                    'description' => 'Update ticket priority based on urgency. Use higher priorities for critical issues requiring immediate attention.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['emergency', 'critical', 'high', 'medium', 'low'],
                                'description' => 'New priority level'
                            ],
                            'reasoning' => [
                                'type' => 'string',
                                'description' => 'Why this priority is appropriate'
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 100,
                                'description' => 'Confidence level 0-100'
                            ]
                        ],
                        'required' => ['priority', 'reasoning', 'confidence']
                    ]
                ]
            ];
        }

        // Tool 2: Close Ticket
        if (!empty($settings['sm_ai_tool_close_ticket']) && $settings['sm_ai_tool_close_ticket'] === 'true') {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'close_ticket',
                    'description' => 'Close a support ticket. Only use for spam, bounced messages, or when the client explicitly confirms the ticket is resolved in their latest reply.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'enum' => ['spam', 'bounced', 'resolved'],
                                'description' => 'Closure reason: spam (unsolicited content), bounced (delivery failure), resolved (client confirmed issue is fixed)'
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 100,
                                'description' => 'Confidence level 0-100'
                            ]
                        ],
                        'required' => ['reason', 'confidence']
                    ]
                ]
            ];
        }

        // Tool 3: Assign to Staff
        if (!empty($settings['sm_ai_tool_assign_staff']) && $settings['sm_ai_tool_assign_staff'] === 'true') {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'assign_to_staff',
                    'description' => 'Assign the ticket to a specific staff member who is best suited to handle this type of issue.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'staff_id' => [
                                'type' => 'string',
                                'description' => 'The staff member ID to assign the ticket to'
                            ],
                            'staff_name' => [
                                'type' => 'string',
                                'description' => 'The staff member name (for logging purposes)'
                            ],
                            'reasoning' => [
                                'type' => 'string',
                                'description' => 'Why this staff member is the best choice for this ticket'
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 100,
                                'description' => 'Confidence level 0-100'
                            ]
                        ],
                        'required' => ['staff_id', 'reasoning', 'confidence']
                    ]
                ]
            ];
        }

        return $tools;
    }

    /**
     * Executes a single ticket tool call
     *
     * Correct signature with ticket_id first, includes confidence checking
     *
     * @param int $ticket_id The ticket ID
     * @param string $tool_name The tool name (change_priority, close_ticket, assign_to_staff)
     * @param array $arguments The tool arguments
     * @param float $confidence The confidence score for this tool (0-100)
     * @param int $analysis_id Optional analysis ID to link tool execution to
     * @return array Result with 'success' and 'message' or 'error'
     */
    public function executeTicketTool($ticket_id, $tool_name, array $arguments, $confidence, $tool_analysis_id = null)
    {
        // Validate tool is allowed
        $allowed_tools = ['change_priority', 'close_ticket', 'assign_to_staff'];
        if (!in_array($tool_name, $allowed_tools)) {
            return ['success' => false, 'error' => "Unknown tool: {$tool_name}"];
        }

        // Check confidence threshold for tool type (hardcoded thresholds)
        $thresholds = [
            'close_ticket' => 90,      // High threshold - destructive action
            'assign_to_staff' => 75,   // Medium-high threshold
            'change_priority' => 70    // Medium threshold
        ];

        $required_confidence = $thresholds[$tool_name] ?? 70;

        if ($confidence < $required_confidence) {
            $message = "Tool '{$tool_name}' requires {$required_confidence}% confidence, got {$confidence}%";
            $this->logger->info("Support Manager AI: {$message} - skipping tool for ticket #{$ticket_id}");

            return [
                'success' => false,
                'error' => $message,
                'skipped' => true
            ];
        }

        // Route to appropriate handler
        $result = null;
        switch ($tool_name) {
            case 'change_priority':
                $result = $this->executeChangePriority($ticket_id, $arguments);
                break;
            case 'close_ticket':
                $result = $this->executeCloseTicket($ticket_id, $arguments);
                break;
            case 'assign_to_staff':
                $result = $this->executeAssignToStaff($ticket_id, $arguments);
                break;
            default:
                return ['success' => false, 'error' => 'Tool handler not implemented'];
        }

        // Log to support_ai_tool_uses table
        if ($result['success']) {
            $this->SupportManagerAiToolUses->add([
                'ticket_id' => $ticket_id,
                'tool_analysis_id' => $tool_analysis_id,
                'tool_name' => $tool_name,
                'arguments' => json_encode($arguments),
                'result' => json_encode($result),
                'confidence' => $confidence,
                'executed_by' => 'ai_system'
            ]);
        }

        return $result;
    }

    /**
     * Executes multiple tool uses from an AI analysis
     *
     * @param int $ticket_id The ticket ID
     * @param array $tool_calls Array of tool call objects from the AI
     * @param float $overall_confidence Overall confidence score (0-100)
     * @param int|null $tool_analysis_id Optional tool analysis ID to link executions to
     * @return array Summary with executed, skipped, and failed counts
     */
    public function executeToolUses($ticket_id, array $tool_calls, $overall_confidence, $tool_analysis_id = null)
    {
        $summary = [
            'executed' => [],
            'skipped' => [],
            'failed' => [],
            'total' => count($tool_calls)
        ];

        foreach ($tool_calls as $tool_call) {
            $tool_call_id = $tool_call['id'] ?? null;  // Needed for multi-turn conversations
            $tool_name = $tool_call['function']['name'] ?? null;
            $arguments_json = $tool_call['function']['arguments'] ?? '{}';

            if (!$tool_name) {
                $summary['failed'][] = [
                    'tool' => 'unknown',
                    'error' => 'Missing function name in tool call'
                ];
                continue;
            }

            // Parse JSON arguments (OpenRouter/OpenAI returns arguments as JSON string)
            $arguments = json_decode($arguments_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $summary['failed'][] = [
                    'tool' => $tool_name,
                    'error' => 'Failed to parse tool arguments: ' . json_last_error_msg()
                ];
                $this->logger->error("Support Manager AI: Failed to parse arguments for {$tool_name}: " . json_last_error_msg());
                continue;
            }

            // Use tool-specific confidence if provided, otherwise use overall
            $confidence = $arguments['confidence'] ?? $overall_confidence;

            // Execute the tool
            $result = $this->executeTicketTool($ticket_id, $tool_name, $arguments, $confidence, $tool_analysis_id);

            if ($result['success']) {
                $summary['executed'][] = [
                    'tool' => $tool_name,
                    'confidence' => $confidence,
                    'result' => $result
                ];
            } elseif (!empty($result['skipped'])) {
                $summary['skipped'][] = [
                    'tool' => $tool_name,
                    'confidence' => $confidence,
                    'reason' => $result['error']
                ];
            } else {
                $summary['failed'][] = [
                    'tool' => $tool_name,
                    'confidence' => $confidence,
                    'error' => $result['error']
                ];
            }
        }

        return $summary;
    }

    /**
     * Executes change priority action
     *
     * @param int $ticket_id The ticket ID
     * @param array $arguments Action arguments (priority, reasoning, confidence)
     * @return array Result with success status
     */
    private function executeChangePriority($ticket_id, array $arguments)
    {
        $new_priority = $arguments['priority'] ?? null;
        $reasoning = $arguments['reasoning'] ?? 'AI suggested priority change';
        $valid_priorities = ['emergency', 'critical', 'high', 'medium', 'low'];

        if (!$new_priority || !in_array($new_priority, $valid_priorities)) {
            return ['success' => false, 'error' => "Invalid priority: {$new_priority}"];
        }

        try {
            // Get the ticket
            $ticket = $this->SupportManagerTickets->get($ticket_id);
            if (!$ticket) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }

            // Check if priority is already set to this value
            if ($ticket->priority === $new_priority) {
                return ['success' => false, 'error' => "Priority already set to {$new_priority}", 'skipped' => true];
            }

            // Update the priority (by_staff_id 0 = AI assistant)
            $this->SupportManagerTickets->edit($ticket_id, [
                'priority' => $new_priority,
                'by_staff_id' => 0
            ]);

            // Add a staff note explaining the change
            $this->SupportManagerTickets->addReply($ticket_id, [
                'staff_id' => Configure::get('SupportManager.system_staff_id'),
                'type' => 'note',
                'details' => "Priority changed from {$ticket->priority} to {$new_priority} by AI. Reason: {$reasoning}",
                'date_added' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info("Support Manager AI: Changed priority for ticket #{$ticket->code} from {$ticket->priority} to {$new_priority}");

            return [
                'success' => true,
                'message' => "Priority updated successfully",
                'old_priority' => $ticket->priority,
                'new_priority' => $new_priority
            ];
        } catch (Exception $e) {
            $this->logger->error("Support Manager AI: Failed to change priority for ticket #{$ticket_id}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Executes close ticket action
     *
     * @param int $ticket_id The ticket ID
     * @param array $arguments Action arguments (reason, confidence)
     * @return array Result with success status
     */
    private function executeCloseTicket($ticket_id, array $arguments)
    {
        $reason = $arguments['reason'] ?? 'resolved';
        $valid_reasons = ['spam', 'bounced', 'resolved'];

        if (!in_array($reason, $valid_reasons)) {
            return ['success' => false, 'error' => "Invalid reason: {$reason}"];
        }

        try {
            // Get the ticket to verify it exists
            $ticket = $this->SupportManagerTickets->get($ticket_id);
            if (!$ticket) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }

            // Only close if ticket is currently open or awaiting_reply
            if (!in_array($ticket->status, ['open', 'awaiting_reply', 'in_progress'])) {
                return ['success' => false, 'error' => "Cannot close ticket with status: {$ticket->status}"];
            }

            // Close the ticket (staff_id 0 = AI assistant)
            $this->SupportManagerTickets->close($ticket_id, 0);

            // Add a staff reply explaining the closure
            $reply_details = "This ticket has been automatically closed by AI";
            if ($reason === 'spam') {
                $reply_details .= " (detected as spam)";
            } elseif ($reason === 'bounced') {
                $reply_details .= " (bounced email)";
            } elseif ($reason === 'resolved') {
                $reply_details .= " (marked as resolved by customer)";
            }
            $reply_details .= ".";

            // Add the closure note as a staff reply
            $this->SupportManagerTickets->addReply($ticket_id, [
                'staff_id' => Configure::get('SupportManager.system_staff_id'),
                'type' => 'note',
                'details' => $reply_details,
                'date_added' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info("Support Manager AI: Closed ticket #{$ticket->code} (reason: {$reason})");

            return [
                'success' => true,
                'message' => "Ticket closed successfully",
                'reason' => $reason
            ];
        } catch (Exception $e) {
            $this->logger->error("Support Manager AI: Failed to close ticket #{$ticket_id}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Executes assign to staff action
     *
     * @param int $ticket_id The ticket ID
     * @param array $arguments Action arguments (staff_id, staff_name, reasoning, confidence)
     * @return array Result with success status
     */
    private function executeAssignToStaff($ticket_id, array $arguments)
    {
        $staff_id = $arguments['staff_id'] ?? null;
        $reasoning = $arguments['reasoning'] ?? 'AI suggested assignment';

        if (!$staff_id) {
            return ['success' => false, 'error' => 'Missing staff_id'];
        }

        try {
            // Get the ticket
            $ticket = $this->SupportManagerTickets->get($ticket_id);
            if (!$ticket) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }

            // Check if already assigned to this staff member
            if ($ticket->staff_id == $staff_id) {
                return ['success' => false, 'error' => 'Ticket already assigned to this staff member', 'skipped' => true];
            }

            // Verify staff member exists and is active
            Loader::loadModels($this, ['Staff']);
            $staff = $this->Staff->get($staff_id, $this->company_id);
            if (!$staff || $staff->status !== 'active') {
                return ['success' => false, 'error' => 'Staff member not found or inactive'];
            }

            // Assign the ticket (by_staff_id 0 = AI assistant)
            // Note: Use 'ticket_staff_id' (not 'staff_id') when logging is enabled
            $this->SupportManagerTickets->edit($ticket_id, [
                'ticket_staff_id' => $staff_id,
                'status' => $ticket->status,
                'by_staff_id' => 0
            ], true);

            // Add a staff note explaining the assignment
            $staff_name = "{$staff->first_name} {$staff->last_name}";
            $this->SupportManagerTickets->addReply($ticket_id, [
                'staff_id' => Configure::get('SupportManager.system_staff_id'),
                'type' => 'note',
                'details' => "Ticket assigned to {$staff_name} by AI. Reason: {$reasoning}",
                'date_added' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info("Support Manager AI: Assigned ticket #{$ticket->code} to {$staff_name}");

            return [
                'success' => true,
                'message' => "Ticket assigned successfully",
                'staff_id' => $staff_id,
                'staff_name' => $staff_name
            ];
        } catch (Exception $e) {
            $this->logger->error("Support Manager AI: Failed to assign ticket #{$ticket_id}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gets AI settings with system defaults and overrides
     *
     * @return array Array of settings
     */
    private function getSettings()
    {
        // Get system settings
        Loader::loadModels($this, ['Settings']);
        $system_settings = [];

        $keys = [
            'ai_enabled',
            'ai_api_key',
            'ai_default_model',
            'ai_temperature',
            'ai_max_tokens',
            'ai_global_prompt'
        ];

        foreach ($keys as $key) {
            $setting = $this->Settings->getSetting($key);
            if ($setting) {
                $system_settings[$key] = $setting->value;
            }
        }

        // Get Support Manager settings
        $sm_settings_objects = $this->SupportManagerSettings->getSettings($this->company_id);
        $sm_settings = [];
        foreach ($sm_settings_objects as $setting) {
            $sm_settings[$setting->key] = $setting->value;
        }

        // Merge with system defaults
        return array_merge($system_settings, $sm_settings);
    }

    /**
     * Gets a setting value with fallback to company setting
     *
     * @param array $settings The merged settings array
     * @param string $primary_key The primary setting key to check first (e.g., 'sm_ai_temperature')
     * @param string $fallback_key The fallback setting key (e.g., 'ai_temperature')
     * @param mixed $default The default value if neither setting exists
     * @param string|null $override_flag The override flag key (e.g., 'sm_ai_override_temperature')
     * @return mixed The setting value with proper type casting
     */
    private function getSettingWithFallback($settings, $primary_key, $fallback_key, $default, $override_flag = null)
    {
        // Check if override is enabled (if override_flag is provided)
        $override_enabled = true; // Default to true if no override flag specified
        if ($override_flag !== null) {
            $override_enabled = isset($settings[$override_flag]) && $settings[$override_flag] === 'true';
        }

        // Check primary setting first (only if override is enabled or no override flag exists)
        if ($override_enabled && isset($settings[$primary_key]) && $settings[$primary_key] !== '' && $settings[$primary_key] !== null) {
            return $this->castSettingValue($settings[$primary_key], $primary_key);
        }

        // Fall back to company setting
        if (isset($settings[$fallback_key]) && $settings[$fallback_key] !== '' && $settings[$fallback_key] !== null) {
            return $this->castSettingValue($settings[$fallback_key], $fallback_key);
        }

        // Use default
        return $default;
    }

    /**
     * Casts a setting value to the appropriate type
     *
     * @param mixed $value The raw setting value
     * @param string $key The setting key (used to determine type)
     * @return mixed The properly typed value
     */
    private function castSettingValue($value, $key)
    {
        // Temperature should be float
        if (strpos($key, 'temperature') !== false) {
            return (float)$value;
        }

        // Max tokens should be int
        if (strpos($key, 'max_tokens') !== false) {
            return (int)$value;
        }

        // Model should be string (no casting needed)
        return $value;
    }

    /**
     * Generates an AI summary for a single ticket reply
     *
     * @param string $reply_text The reply text to summarize
     * @return string|false The generated summary text, or false on failure
     */
    public function generateSummary($reply_text)
    {
        $settings = $this->getSettings();

        // Initialize BlestaAi
        Loader::loadComponents($this, ['BlestaAi']);
        $ai = new BlestaAi();

        $model = $this->getSettingWithFallback(
            $settings,
            'sm_ai_model',
            'ai_default_model',
            'claude-3-5-sonnet-20241022',
            'sm_ai_override_model'
        );

        $conversation_id = $ai->createConversation(
            $this->company_id,
            0,
            $model,
            'Reply Summary'
        );

        $system_prompt = 'You are a support agent assistant.  Create a concise summary of the text provided. '
            . 'If there is one clear topic, write 2-3 sentences. '
            . 'If there are multiple distinct topics, use a short bullet list (lines starting with "- "). '
            . 'Focus on the key points, requests, or details. '
            . 'Return only the summary text. '
            . 'Do not comment on the nature or relevance of the content. '
            . 'Do not refuse, explain limitations, or offer alternatives. '
            . 'Do not wrap in JSON.';

        $masked_text = $this->maskSensitiveData($reply_text);

        $temperature = $this->getSettingWithFallback(
            $settings,
            'sm_ai_temperature',
            'ai_temperature',
            1.0,
            'sm_ai_override_temperature'
        );

        $chat_options = [
            'system_prompt' => $system_prompt,
            'temperature' => min($temperature, 0.3),
            'max_tokens' => 500
        ];

        try {
            $response = $ai->chat($conversation_id, $masked_text, $chat_options);

            if (!empty($response['content'])) {
                return trim($response['content']);
            }

            return false;
        } catch (Exception $e) {
            if ($this->isDebugEnabled()) {
                $this->logger->error('Support Manager AI: Summary generation failed', [
                    'error' => $e->getMessage()
                ]);
            }

            return false;
        }
    }

    /**
     * Builds system prompt for tool use only (no customer response)
     *
     * @param array $settings The AI settings
     * @return string The tool use prompt
     */
    private function buildToolUseOnlyPrompt($settings)
    {
        // Get custom base prompt or use default
        $prompt = !empty($settings['sm_ai_system_prompt'])
            ? $settings['sm_ai_system_prompt']
            : (!empty($settings['ai_global_prompt'])
                ? $settings['ai_global_prompt']
                : 'You are a professional AI assistant for a support ticket system.');

        $prompt .= "\n\nYOUR TASK: Analyze this support ticket for management actions.";
        $prompt .= "\n\nAnalyze the ticket and call the appropriate tools when you identify actions that should be taken.";
        $prompt .= "\nYou can call multiple tools in a single response if needed.";

        $prompt .= "\n\nAVAILABLE TOOLS:";

        // Add tool descriptions
        if (!empty($settings['sm_ai_tool_change_priority']) && $settings['sm_ai_tool_change_priority'] === 'true') {
            $prompt .= "\n\n1. change_priority";
            $prompt .= "\n   - Update ticket priority based on urgency";
            $prompt .= "\n   - Options: emergency, critical, high, medium, low";
            $prompt .= "\n   - Confidence threshold: 70%";
        }

        if (!empty($settings['sm_ai_tool_close_ticket']) && $settings['sm_ai_tool_close_ticket'] === 'true') {
            $prompt .= "\n\n2. close_ticket";
            $prompt .= "\n   - Close a ticket if it's resolved, spam, bounced, or customer says to close it";
            $prompt .= "\n   - Reasons: resolved, spam, bounced";
            $prompt .= "\n   - Confidence threshold: 90% (HIGH - destructive action)";
        }

        if (!empty($settings['sm_ai_tool_assign_staff']) && $settings['sm_ai_tool_assign_staff'] === 'true') {
            $prompt .= "\n\n3. assign_to_staff";
            $prompt .= "\n   - Assign ticket to appropriate team member";
            $prompt .= "\n   - Consider staff specializations";
            $prompt .= "\n   - Confidence threshold: 75%";
        }

        // Add custom tool instructions if configured
        if (!empty($settings['sm_ai_tool_instructions'])) {
            $prompt .= "\n\n" . $settings['sm_ai_tool_instructions'];
        }

        $prompt .= "\n\nJSON OUTPUT FORMAT:";
        $prompt .= "\nYour text response must be ONLY a valid JSON object with these fields:";
        $prompt .= "\n{";
        $prompt .= "\n  \"notes\": \"Analysis notes about the ticket and recommended actions\",";
        $prompt .= "\n  \"confidence\": 85,";
        $prompt .= "\n  \"concerns\": [\"List any concerns about the recommended actions\"]";
        $prompt .= "\n}";
        $prompt .= "\n\nIMPORTANT:";
        $prompt .= "\n- Start your response with { and end with }";
        $prompt .= "\n- Do not add any text before or after the JSON";
        $prompt .= "\n- Tool calls are made via function calling, not described in your JSON";
        $prompt .= "\n- When you identify an action to take, call the corresponding tool";

        return $prompt;
    }

    /**
     * Builds system prompt for response generation only (no tools)
     *
     * @param array $settings The AI settings
     * @return string The response generation prompt
     */
    private function buildResponseOnlyPrompt($settings)
    {
        // Get custom base prompt or use default
        $prompt = !empty($settings['sm_ai_system_prompt'])
            ? $settings['sm_ai_system_prompt']
            : (!empty($settings['ai_global_prompt'])
                ? $settings['ai_global_prompt']
                : 'You are a professional AI assistant for a support ticket system.');

        $prompt .= "\n\nYOUR TASK: Draft a helpful customer support response.";
        $prompt .= "\n\nGuidelines:";
        $prompt .= "\n- Be clear, empathetic, and actionable";
        $prompt .= "\n- Address the customer's specific question or concern";
        $prompt .= "\n- Provide next steps if applicable";
        $prompt .= "\n- Maintain a professional but friendly tone";

        $prompt .= "\n\nJSON OUTPUT FORMAT:";
        $prompt .= "\nYour response must be ONLY a valid JSON object with these fields:";
        $prompt .= "\n{";
        $prompt .= "\n  \"notes\": \"Brief internal notes for staff about this response\",";
        $prompt .= "\n  \"response\": \"Your customer-facing reply here\",";
        $prompt .= "\n  \"confidence\": 85,";
        $prompt .= "\n  \"concerns\": [\"Any concerns or things staff should verify\"]";
        $prompt .= "\n}";

        $prompt .= "\n\nIMPORTANT:";
        $prompt .= "\n- Start your response with { and end with }";
        $prompt .= "\n- Do not add any text before or after the JSON";
        $prompt .= "\n- Focus ONLY on customer communication, not ticket management";

        return $prompt;
    }
}
