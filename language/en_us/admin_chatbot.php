<?php
/**
 * Language definitions for the Admin Chatbot controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Page
$lang['AdminChatbot.index.page_title'] = 'AI Chatbot';

// Chat UI
$lang['AdminChatbot.index.btn_new_chat'] = 'New Chat';
$lang['AdminChatbot.index.search_placeholder'] = 'Search conversations...';
$lang['AdminChatbot.index.no_conversations'] = 'No conversations yet';
$lang['AdminChatbot.index.show_all_conversations'] = 'Show all conversations';
$lang['AdminChatbot.index.show_chatbot_only'] = 'Show chatbot conversations only';
$lang['AdminChatbot.index.input_placeholder'] = 'Type a message...';
$lang['AdminChatbot.index.btn_send'] = 'Send';
$lang['AdminChatbot.index.model_label'] = 'Model';
$lang['AdminChatbot.index.new_chat_title'] = 'New Chat';
$lang['AdminChatbot.index.default_title'] = 'New Conversation';

// Empty state
$lang['AdminChatbot.index.empty_title'] = 'How can I help you today?';
$lang['AdminChatbot.index.empty_subtitle'] = 'Start a conversation by typing a message or choosing a suggestion below. AI-generated content may contain inaccuracies, biases, or hallucinations and should not be considered professional advice. This tool must not be used for any illegal or harmful purposes.';

// Suggestion cards
$lang['AdminChatbot.index.suggestion_billing_title'] = 'Billing Help';
$lang['AdminChatbot.index.suggestion_billing_text'] = 'How do I create and send an invoice to a client?';
$lang['AdminChatbot.index.suggestion_billing_context'] = 'The user is asking about invoicing clients in Blesta. Provide step-by-step guidance on creating invoices. Cover navigation paths in Blesta (e.g. Clients > Select client > Create Invoice action or the [+] icon in the Invoices widget), invoice delivery methods, recurring invoice setup, and common billing settings.';
$lang['AdminChatbot.index.suggestion_services_title'] = 'Service Management';
$lang['AdminChatbot.index.suggestion_services_text'] = 'How do I provision a new service for a client?';
$lang['AdminChatbot.index.suggestion_services_context'] = 'The user is asking about service provisioning in Blesta. Guide them through adding a service for a client, selecting a package, configuring module settings, and activating the service. Cover the navigation path (Clients > Select client > New Service), package creation (Packages > New Package) and term selection, module selection (Module tab on new Package), and manual vs automatic provisioning.';
$lang['AdminChatbot.index.suggestion_modules_title'] = 'Module Setup';
$lang['AdminChatbot.index.suggestion_modules_text'] = 'How do I configure a server module like cPanel?';
$lang['AdminChatbot.index.suggestion_modules_context'] = 'The user is asking about module installation configuration in Blesta. Walk them through installing and configuring modules in Blesta. Cover navigation (Settings > Modules > Available), adding server or API credentials, creating packages that use the module (Packages > New Package). Use cPanel as a concrete example but mention the pattern applies to other modules.';
$lang['AdminChatbot.index.suggestion_support_title'] = 'Support Tickets';
$lang['AdminChatbot.index.suggestion_support_text'] = 'How do I manage and respond to support tickets?';
$lang['AdminChatbot.index.suggestion_support_context'] = 'The user is asking about the support ticket system in Blesta. Explain how to create a support department and respond to tickets using the Support Manager plugin, including navigation (Support > Departments, and Support > Tickets). Cover ticket statuses, departments, predefined responses, ticket email importing via piping or POP/IMAP, and staff user creation and department assignment.';
$lang['AdminChatbot.index.suggestion_automation_title'] = 'Automation';
$lang['AdminChatbot.index.suggestion_automation_text'] = 'How do I set up automated billing and service tasks?';
$lang['AdminChatbot.index.suggestion_automation_context'] = 'The user is asking about automation and cron tasks in Blesta. Explain how to set up the system cron job, the kinds of automated tasks Blesta runs (invoice creation, payment processing, service suspension/unsuspension, email reminders), and where to find the recommended cron command under Settings > System > Automation, and individual tasks and their run times and frequencies under Settings > Company > Automation.';
$lang['AdminChatbot.index.suggestion_plugins_title'] = 'Plugins';
$lang['AdminChatbot.index.suggestion_plugins_text'] = 'How do I install and configure plugins?';
$lang['AdminChatbot.index.suggestion_plugins_context'] = 'The user is asking about plugin management in Blesta. Guide them through installing plugins (Settings > Plugins > Available), enabling/disabling plugins, configuring plugin settings, and managing plugin permissions for staff groups (Settings > System > Staff > Staff Groups: Edit). Mention popular plugins like Support Manager, CMS, Domain Manager, and how to install third-party plugins via upload and where to activate.';
$lang['AdminChatbot.index.suggestion_clients_title'] = 'Client Management';
$lang['AdminChatbot.index.suggestion_clients_text'] = 'How do I manage client accounts and groups?';
$lang['AdminChatbot.index.suggestion_clients_context'] = 'The user is asking about client management in Blesta. Cover creating new clients, editing client profiles, managing client groups (Settings > Clients > Client Groups), setting group-level defaults for invoicing and payment, auto-debit, payment late notices and reminders, managing payment accounts, and navigating the client profile page as a one-stop destination for all client actions.';
$lang['AdminChatbot.index.suggestion_security_title'] = 'Security';
$lang['AdminChatbot.index.suggestion_security_text'] = 'What security best practices should I follow?';
$lang['AdminChatbot.index.suggestion_security_context'] = 'The user is asking about security best practices. Cover staff permissions and group-based access control, two-factor authentication setup, strong password policies, keeping Blesta updated, SSL/TLS configuration, IP-based login restrictions, and accessing logs (Tools > Logs). Mention changing the admin default route in /config/routes.php via Route.admin';

// Prompt-mode suggestion cards
$lang['AdminChatbot.index.suggestion_custom_report_title'] = 'Custom Report';
$lang['AdminChatbot.index.suggestion_custom_report_text'] = 'Generate a SQL query for a custom report';
$lang['AdminChatbot.index.suggestion_custom_report_placeholder'] = 'Describe the report you need...';
$lang['AdminChatbot.index.suggestion_api_query_title'] = 'API Query';
$lang['AdminChatbot.index.suggestion_api_query_text'] = 'Get help writing Blesta API requests';
$lang['AdminChatbot.index.suggestion_api_query_placeholder'] = 'What do you want to do via the API?';
$lang['AdminChatbot.index.suggestion_plugin_dev_title'] = 'Developer Help';
$lang['AdminChatbot.index.suggestion_plugin_dev_text'] = 'Get help building a plugin or module';
$lang['AdminChatbot.index.suggestion_plugin_dev_placeholder'] = 'What are you building?';

// Context pill
$lang['AdminChatbot.index.context_pill_dismiss'] = 'Cancel';

// Card badge
$lang['AdminChatbot.index.card_badge_prompt_mode'] = 'Prompt-Mode';

// Truncation notice
$lang['AdminChatbot.index.truncated_notice'] = 'This response was truncated due to token limits. You can increase Max Tokens under Settings > System > AI, or ask the AI to continue.';

// Not configured state
$lang['AdminChatbot.index.not_configured_title'] = 'AI Not Configured';
$lang['AdminChatbot.index.not_configured_text'] = 'The AI chatbot has not been configured yet. Please configure the AI settings to start using this feature.';
$lang['AdminChatbot.index.btn_configure'] = 'Configure AI';

// No permission state
$lang['AdminChatbot.index.no_permission_title'] = 'Access Restricted';
$lang['AdminChatbot.index.no_permission_text'] = 'You do not have permission to use the AI chatbot. Please contact your administrator to request access.';
$lang['AdminChatbot.index.btn_go_back'] = 'Go Back';

// Errors
$lang['AdminChatbot.!error.unauthorized'] = 'You are not authorized to perform this action.';
$lang['AdminChatbot.!error.conversation_not_found'] = 'Conversation not found.';
$lang['AdminChatbot.!error.message_empty'] = 'Please enter a message.';
$lang['AdminChatbot.!error.stream_failed'] = 'Failed to get a response from the AI. Please try again.';
$lang['AdminChatbot.!error.conversation_create_failed'] = 'Failed to create conversation. Please try again.';
$lang['AdminChatbot.!error.model_empty'] = 'Please select a model before sending a message.';

// Actions
$lang['AdminChatbot.index.btn_delete'] = 'Delete';

// Delete confirmation
$lang['AdminChatbot.index.confirm_delete'] = 'Are you sure you want to delete this conversation?';

// Timestamps
$lang['AdminChatbot.index.time_just_now'] = 'Just now';
$lang['AdminChatbot.index.time_minutes_ago'] = '%1$s min ago'; // %1$s is the number of minutes
$lang['AdminChatbot.index.time_hours_ago'] = '%1$s hr ago'; // %1$s is the number of hours
$lang['AdminChatbot.index.time_today'] = 'Today';
$lang['AdminChatbot.index.time_yesterday'] = 'Yesterday';
