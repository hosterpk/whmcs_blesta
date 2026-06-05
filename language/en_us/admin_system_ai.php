<?php
// Success messages
$lang['AdminSystemAi.!success.settings_updated'] = 'The AI settings were successfully updated!';
$lang['AdminSystemAi.!success.api_key_fetched'] = 'API key successfully retrieved from your Blesta account.';

// Error messages
$lang['AdminSystemAi.!error.connection_failed'] = 'Could not connect to AI API: %1$s';
$lang['AdminSystemAi.!error.invalid_request'] = 'Invalid request.';
$lang['AdminSystemAi.!error.username_required'] = 'Please enter your account.blesta.com username.';
$lang['AdminSystemAi.!error.no_license_key'] = 'No Blesta license key found. Please configure your license first.';
$lang['AdminSystemAi.!error.auth_failed'] = 'Authentication failed. Please check your username and license key.';
$lang['AdminSystemAi.!error.request_failed'] = 'Request failed. Please try again.';
$lang['AdminSystemAi.!error.api_key_required'] = 'Please enter an API key.';
$lang['AdminSystemAi.!error.invalid_api_key'] = 'Invalid API key.';

// Index page
$lang['AdminSystemAi.index.page_title'] = 'Settings > System > AI';
$lang['AdminSystemAi.index.boxtitle_ai'] = 'AI Settings';

$lang['AdminSystemAi.index.field.ai_enabled'] = 'Enable AI Features';
$lang['AdminSystemAi.index.field.ai_api_key'] = 'API Key';
$lang['AdminSystemAi.index.field.ai_default_model'] = 'Default Model';
$lang['AdminSystemAi.index.field.ai_temperature'] = 'Temperature';
$lang['AdminSystemAi.index.field.ai_max_tokens'] = 'Max Tokens';
$lang['AdminSystemAi.index.field.submit'] = 'Update Settings';

$lang['AdminSystemAi.index.tooltip.ai_enabled'] = 'Enable or disable AI features throughout the system.';
$lang['AdminSystemAi.index.tooltip.ai_api_key'] = 'Your Blesta AI API key. Obtain this from account.blesta.com.';
$lang['AdminSystemAi.index.tooltip.ai_default_model'] = 'The default AI model to use for chat completions.';
$lang['AdminSystemAi.index.tooltip.ai_temperature'] = 'Controls randomness. Lower values are more deterministic, higher values are more creative. Range: 0.0 to 2.0';
$lang['AdminSystemAi.index.tooltip.ai_max_tokens'] = 'Maximum number of tokens to generate in responses.';

$lang['AdminSystemAi.index.text_connected'] = 'Successfully connected to Blesta AI.';
$lang['AdminSystemAi.index.text_connection_error'] = 'Failed to connect to Blesta AI. Check your API key.';
$lang['AdminSystemAi.index.text_balance'] = 'Balance: %1$s %2$s';
$lang['AdminSystemAi.index.text_temperature_range'] = '(0.0 - 2.0)';
$lang['AdminSystemAi.index.text_fetch_key'] = 'Fetch From My Blesta Account';
$lang['AdminSystemAi.index.text_manual_entry'] = 'Enter your API key manually or fetch it automatically from your account.';
$lang['AdminSystemAi.index.text_validating'] = 'Validating...';
$lang['AdminSystemAi.index.text_key_valid'] = 'API key validated and saved';
$lang['AdminSystemAi.index.text_select_model'] = 'Select a model';

// Usage statistics
$lang['AdminSystemAi.index.text_api_status'] = 'API Status';
$lang['AdminSystemAi.index.text_status_active'] = 'Active';
$lang['AdminSystemAi.index.text_remaining_credits'] = 'Remaining Credits';
$lang['AdminSystemAi.index.text_credits_used'] = 'Credits Used (This Month)';
$lang['AdminSystemAi.index.text_last_api_call'] = 'Last API Call';
$lang['AdminSystemAi.index.text_no_calls_yet'] = 'No calls yet';

// Modal
$lang['AdminSystemAi.modal.heading_fetch'] = 'Fetch From My Blesta Account';
$lang['AdminSystemAi.modal.text_subtitle'] = 'Your username is required to retrieve your API key';
$lang['AdminSystemAi.modal.text_info'] = 'Your account.blesta.com username and your Blesta license key will be used to validate your license and automatically fetch a new Blesta AI API key. If a key has already been generated, it will be revoked and a new key will be issued.';
$lang['AdminSystemAi.modal.field.username'] = 'Username';
$lang['AdminSystemAi.modal.field.username_placeholder'] = 'Enter your account username';
$lang['AdminSystemAi.modal.button.authenticate'] = 'Authenticate';
$lang['AdminSystemAi.modal.button.cancel'] = 'Cancel';
$lang['AdminSystemAi.modal.text_authenticating'] = 'Authenticating...';

// Section headers
$lang['AdminSystemAi.index.heading_api'] = 'API Configuration';
$lang['AdminSystemAi.index.heading_model'] = 'Default Model';
$lang['AdminSystemAi.index.heading_parameters'] = 'Model Parameters';
$lang['AdminSystemAi.index.heading_prompts'] = 'System Prompts';
$lang['AdminSystemAi.index.heading_features'] = 'Enabled Features';

// Global prompt
$lang['AdminSystemAi.index.field.ai_global_prompt'] = 'Global System Prompt (Default)';
$lang['AdminSystemAi.index.tooltip.ai_global_prompt'] = 'The default instructions sent to the AI model with every request. This defines the AI\'s behavior and context across all features.';
$lang['AdminSystemAi.index.text_global_prompt_help'] = 'This prompt applies to all AI features unless overridden within the feature.';

// Feature checkboxes
$lang['AdminSystemAi.index.field.ai_feature_package_descriptions'] = 'Package Descriptions';
$lang['AdminSystemAi.index.text_feature_package_descriptions'] = 'Generate compelling product and service descriptions';

$lang['AdminSystemAi.index.field.ai_feature_email_templates'] = 'Email Templates';
$lang['AdminSystemAi.index.text_feature_email_templates'] = 'AI-assisted email template edits and improvements';

$lang['AdminSystemAi.index.field.ai_feature_chatbot'] = 'Chatbot';
$lang['AdminSystemAi.index.text_feature_chatbot'] = 'AI-powered chatbot for staff assistance';

// Staff groups
$lang['AdminSystemAi.index.field.ai_chatbot_staff_groups'] = 'Staff Group Access';
$lang['AdminSystemAi.index.tooltip.ai_chatbot_staff_groups'] = 'Select which staff groups can access the AI chatbot. Multiple groups can be selected.';
$lang['AdminSystemAi.index.text_staff_groups_help'] = 'Hold Ctrl (Cmd on Mac) to select multiple groups.';
$lang['AdminSystemAi.index.text_features_intro'] = 'Select which core Blesta features should have AI assistance enabled. Plugins can access AI features and are configured independently.';

// Beta disclaimer
$lang['AdminSystemAi.index.heading_beta'] = 'Beta Feature Notice';
$lang['AdminSystemAi.index.text_beta_notice'] = 'This feature is currently in beta and may produce unexpected or inaccurate results; use with discretion. The "Fetch From My Blesta Account" button is not yet active during the beta — to request a Blesta AI API key, open a ticket from your Blesta client account. Keys issued for the beta are issued manually at our discretion and will be revoked once 6.0.0 is released.';
$lang['AdminSystemAi.index.heading_privacy'] = 'Privacy Notice';
$lang['AdminSystemAi.index.text_privacy_notice'] = 'Requests are sent to third-party AI providers (e.g., OpenAI, Anthropic) for processing. The Blesta AI service does not store AI conversation data; however, we do not control how these providers handle or retain data. Avoid submitting sensitive or confidential information.';
$lang['AdminSystemAi.index.heading_privacy_acknowledgment'] = 'Privacy Acknowledgment';
$lang['AdminSystemAi.index.field.ai_privacy_acknowledged'] = 'I have read and understand the privacy notice above.';
$lang['AdminSystemAi.index.text_privacy_last_acknowledged'] = 'Last acknowledged on %1$s.';
$lang['AdminSystemAi.!error.privacy_not_acknowledged'] = 'You must agree to the privacy notice before saving.';

// Email Context Settings
$lang['AdminSystemAi.index.heading_email_context'] = 'Email Template Context Settings';

$lang['AdminSystemAi.index.field.ai_email_context_depth'] = 'Relationship Depth';
$lang['AdminSystemAi.index.tooltip.ai_email_context_depth'] = 'Maximum depth for traversing model relationships. Higher values include more related data but increase token usage. Range: 1-5.';
$lang['AdminSystemAi.index.text_email_context_depth'] = 'Controls how deep to follow relationships (e.g., invoice → client → contacts). Default: 2';

$lang['AdminSystemAi.index.field.ai_email_context_schemas'] = 'Include Schema Definitions';
$lang['AdminSystemAi.index.text_email_context_schemas'] = 'Include field type information and database schemas in the context.';

$lang['AdminSystemAi.index.field.ai_email_context_examples'] = 'Include Example Data';
$lang['AdminSystemAi.index.text_email_context_examples'] = 'Include sample data values to help the AI understand data formats and structure.';
