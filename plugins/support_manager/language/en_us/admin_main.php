<?php
// Success messages
$lang['AdminMain.!success.settings_updated'] = 'The settings have been successfully updated.';
$lang['AdminMain.!success.ai_settings_updated'] = 'The AI settings have been successfully updated.';

// Page titles
$lang['AdminMain.settings.page_title'] = 'Support Manager > Settings';
$lang['AdminMain.ai.page_title'] = 'Support Manager > AI Settings';

// Settings
$lang['AdminMain.settings.boxtitle_settings'] = 'Settings';
$lang['AdminMain.settings.heading_avatar_settings'] = 'Avatar Settings';
$lang['AdminMain.settings.field_avatar'] = 'Avatar';
$lang['AdminMain.settings.option_gravatar'] = 'Use Gravatar';
$lang['AdminMain.settings.option_fallback'] = 'Use Gravatar but override if a custom avatar is set';
$lang['AdminMain.settings.option_default'] = 'Use custom avatar only';
$lang['AdminMain.settings.field_default_avatar'] = 'Default Avatar Image';
$lang['AdminMain.settings.text_remove_avatar'] = 'Remove Image';
$lang['AdminMain.settings.text_avatar_recommendation'] = 'Recommended: 150x150px, JPG or PNG, max 2MB';
$lang['AdminMain.settings.field_submit'] = 'Update Settings';

// AI Settings
$lang['AdminMain.ai.boxtitle_settings'] = 'AI Settings';

// Warning messages
$lang['AdminMain.ai.warning_not_configured_title'] = 'Blesta AI API Key Required';
$lang['AdminMain.ai.warning_not_configured_text'] = 'AI features for the Support Manager require a Blesta AI API key. Please configure your API key in System Settings > Artificial Intelligence before enabling AI features.';
$lang['AdminMain.ai.button_configure_ai'] = 'Go to System AI Settings';

// Section headings
$lang['AdminMain.ai.heading_features'] = 'AI Features';
$lang['AdminMain.ai.heading_model'] = 'Model Configuration';
$lang['AdminMain.ai.heading_parameters'] = 'Model Parameters';
$lang['AdminMain.ai.heading_system_prompt'] = 'System Prompt';
$lang['AdminMain.ai.heading_experimental'] = 'Experimental Features';
$lang['AdminMain.ai.heading_replies'] = 'Automatic Replies';
$lang['AdminMain.ai.heading_tools'] = 'AI Tools';

// AI Features
$lang['AdminMain.ai.field_enabled'] = 'Enable AI Features for Support Manager';
$lang['AdminMain.ai.field_enabled_desc'] = 'Allow AI-powered features within the ticket system including automated responses, summaries, and tools.';

// Model Configuration
$lang['AdminMain.ai.field_override_model'] = 'Override Default AI Model';
$lang['AdminMain.ai.field_override_model_desc'] = 'System default: %1$s'; // %1$s = default model name
$lang['AdminMain.ai.field_model'] = 'AI Model';
$lang['AdminMain.ai.field_model_tooltip'] = 'Select the AI model to use specifically for Support Manager features. Different models have different capabilities and pricing.';
$lang['AdminMain.ai.field_model_desc'] = 'This model will be used for all AI features in the Support Manager.';

// Model Parameters
$lang['AdminMain.ai.field_override_max_tokens'] = 'Override Max Tokens';
$lang['AdminMain.ai.field_override_max_tokens_desc'] = 'System default: %1$s'; // %1$s = default max tokens
$lang['AdminMain.ai.field_max_tokens'] = 'Max Tokens';
$lang['AdminMain.ai.field_max_tokens_tooltip'] = 'Maximum number of tokens (words/word pieces) the AI can generate in a single response. Higher values allow longer responses but consume more resources. Typical range: 100-4000 for most tasks.';
$lang['AdminMain.ai.field_max_tokens_desc'] = 'Controls the maximum length of AI-generated responses. Default: 4000';

$lang['AdminMain.ai.field_override_temperature'] = 'Override Temperature';
$lang['AdminMain.ai.field_override_temperature_desc'] = 'System default: %1$s'; // %1$s = default temperature
$lang['AdminMain.ai.field_temperature'] = 'Temperature';
$lang['AdminMain.ai.field_temperature_tooltip'] = 'Controls randomness in responses. Lower values (0.0-0.7) produce more focused and deterministic outputs. Higher values (1.3-2.0) produce more creative and varied outputs. Range: 0.0 to 2.0';
$lang['AdminMain.ai.field_temperature_desc'] = 'Lower temperature = more focused, higher temperature = more creative. Default: 1.0';

// System Prompt
$lang['AdminMain.ai.field_system_prompt'] = 'Support Manager System Prompt';
$lang['AdminMain.ai.field_system_prompt_tooltip'] = 'Define specific instructions for the AI when handling support tickets. This prompt overrides the global system prompt and defines the AI\'s behavior specifically within the ticket system.';
$lang['AdminMain.ai.field_system_prompt_desc'] = 'This prompt is used specifically for Support Manager AI features and overrides the global system prompt.';

// Experimental Features
$lang['AdminMain.ai.badge_experimental'] = 'EXPERIMENTAL';
$lang['AdminMain.ai.field_auto_reply_enabled'] = 'Enable Automatic AI Ticket Replies';
$lang['AdminMain.ai.field_auto_reply_enabled_desc'] = 'Allow AI to automatically reply to tickets when it has a high degree of certainty about the answer.';

// Confidence Threshold
$lang['AdminMain.ai.field_confidence_threshold'] = 'Confidence Threshold';
$lang['AdminMain.ai.field_confidence_threshold_tooltip'] = 'AI will only automatically reply to tickets when its confidence level meets or exceeds this threshold. Higher values (90-100%) are more conservative and safer. Lower values (60-89%) will result in more automatic replies but with higher risk of errors.';
$lang['AdminMain.ai.field_confidence_threshold_desc'] = 'Higher threshold = more conservative (fewer automatic replies, higher accuracy). Recommended: 70% or higher.';

// Human Review
$lang['AdminMain.ai.field_require_human_review'] = 'Require Human Review Before Sending';
$lang['AdminMain.ai.field_require_human_review_desc'] = 'Auto-generated replies are displayed within the ticket for staff use (Recommended)';

// AI Disclaimer
$lang['AdminMain.ai.field_add_ai_disclaimer'] = 'Add AI-Generated Disclaimer';
$lang['AdminMain.ai.field_add_ai_disclaimer_desc'] = 'Append a notice to auto-generated replies indicating they were created by AI (Recommended for transparency)';

// Custom Disclaimer
$lang['AdminMain.ai.field_custom_disclaimer'] = 'Custom Disclaimer Text';
$lang['AdminMain.ai.field_custom_disclaimer_tooltip'] = 'Customize the disclaimer message appended to AI-generated replies. Leave blank to use the default message.';
$lang['AdminMain.ai.field_custom_disclaimer_desc'] = 'This text will be appended to all AI-generated ticket replies.';
$lang['AdminMain.ai.field_custom_disclaimer_placeholder'] = 'This response was generated with AI assistance.';

// Restricted Departments
$lang['AdminMain.ai.field_restricted_departments'] = 'Restrict Auto-Reply to Departments';
$lang['AdminMain.ai.field_restricted_departments_tooltip'] = 'Only allow automatic replies for specific ticket departments. Leave all unchecked to allow all departments.';
$lang['AdminMain.ai.field_restricted_departments_desc'] = 'Select which ticket departments can receive automatic AI replies. Uncheck all to allow all departments.';

// AI Assistant Name
$lang['AdminMain.ai.field_assistant_name'] = 'AI Assistant Display Name';
$lang['AdminMain.ai.field_assistant_name_tooltip'] = 'The name shown for AI-generated replies in ticket threads. This personalizes the AI assistant for your support team.';
$lang['AdminMain.ai.field_assistant_name_desc'] = 'Leave blank to use the default name: "Support"';
$lang['AdminMain.ai.field_assistant_name_placeholder'] = 'Support';

// Analyze Trigger
$lang['AdminMain.ai.field_analyze_trigger'] = 'AI Analysis Trigger';
$lang['AdminMain.ai.field_analyze_trigger_tooltip'] = 'Choose when the AI should analyze tickets for potential responses and tool uses. "Every Reply" analyzes each new message. "Ticket Opened" only analyzes the initial ticket opening.';
$lang['AdminMain.ai.field_analyze_trigger_desc'] = 'Controls when AI analysis is triggered for generating responses and executing tools.';
$lang['AdminMain.ai.option_every_reply'] = 'Every Reply';
$lang['AdminMain.ai.option_ticket_opened'] = 'Ticket Opened Only';

// Max Queue Age
$lang['AdminMain.ai.field_max_queue_age_hours'] = 'Max Queue Age (Hours)';
$lang['AdminMain.ai.field_max_queue_age_hours_tooltip'] = 'Queued client replies older than this value will be discarded by the cron rather than processed. Prevents the AI from replying to stale tickets if the cron has been disabled and a backlog has built up.';
$lang['AdminMain.ai.field_max_queue_age_hours_desc'] = 'Discard queued AI replies older than this many hours. Must be between 1 and 8760 (1 year). Defaults to 24.';

// AI Tools
$lang['AdminMain.ai.field_tools_enabled'] = 'Enable Tools';
$lang['AdminMain.ai.field_tools_enabled_desc'] = 'Allow AI to use tools for ticket management such as changing priority, closing tickets, or assigning to staff members.';
$lang['AdminMain.ai.field_tools_available'] = 'Available Tools';
$lang['AdminMain.ai.field_tools_available_tooltip'] = 'Select which tools the AI is allowed to use. Each tool enables specific actions that the AI can perform when processing tickets.';

$lang['AdminMain.ai.field_tool_change_priority'] = 'Change Ticket Priority';
$lang['AdminMain.ai.field_tool_change_priority_desc'] = 'Allow AI to adjust ticket priority (up or down) when an inappropriate priority was selected by the client or detected by analysis.';

$lang['AdminMain.ai.field_tool_close_ticket'] = 'Close Ticket';
$lang['AdminMain.ai.field_tool_close_ticket_desc'] = 'Allow AI to close tickets in cases of spam, bounced messages, or clearly resolved issues.';

$lang['AdminMain.ai.field_tool_assign_staff'] = 'Assign to Staff Member';
$lang['AdminMain.ai.field_tool_assign_staff_desc'] = 'Allow AI to assign tickets to specific staff members based on system prompt instructions.';

$lang['AdminMain.ai.field_tool_instructions'] = 'Tool Usage Instructions';
$lang['AdminMain.ai.field_tool_instructions_tooltip'] = 'Provide specific guidance to the AI on when and how to use the enabled tools. For example, specify staff member names and their areas of expertise for ticket assignment.';
$lang['AdminMain.ai.field_tool_instructions_desc'] = 'Provide instructions and specific scenarios where tools should be used. This text will be included in the system prompt when tools are enabled.';
$lang['AdminMain.ai.field_tool_instructions_placeholder'] = "Example:&#10;- Assign technical issues related to Linux servers to John, Windows servers to Dave&#10;- Only close tickets that are clearly spam, auto-responses, or the customer indicates that the ticket is resolved in the latest reply&#10;- Increase priority for urgent issues mentioning 'down' or 'offline' to Emergency status&#10;- Decrease priority of Emergency tickets if they are not actual emergencies";

// Submit button
$lang['AdminMain.ai.field_submit'] = 'Save AI Settings';