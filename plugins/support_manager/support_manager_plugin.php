<?php
use Blesta\Core\Util\Events\Common\EventInterface;
use Blesta\Core\Util\Common\Traits\Container;

/**
 * Support Manager plugin handler
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerPlugin extends Plugin
{
    use Container;

    /**
     * @var Monolog\Logger An instance of the logger
     */
    protected $logger;

    public function __construct()
    {
        Language::loadLang('support_manager_plugin', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this plugin
        Loader::loadComponents($this, ['Input', 'Record']);

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Initialize logger
        $logger = $this->getFromContainer('logger');
        $this->logger = $logger;
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        Loader::loadModels($this, ['CronTasks', 'Emails', 'EmailGroups', 'Languages', 'Companies']);

        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);

        // Add all support tables, *IFF* not already added
        try {
            // Tickets
            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('code', ['type'=>'varchar', 'size'=>128])
                ->setField('department_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('staff_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField(
                    'service_id',
                    ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null]
                )
                ->setField('client_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('email', ['type'=>'varchar', 'size'=>255, 'is_null'=>true, 'default'=>null])
                ->setField('summary', ['type'=>'varchar', 'size'=>255])
                ->setField(
                    'priority',
                    ['type'=>'enum', 'size'=>"'emergency','critical','high','medium','low'", 'default'=>'low']
                )
                ->setField('request_feedback', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setField('rating', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('rating_comment', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setField('date_rated', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
                ->setField(
                    'status',
                    [
                        'type'=>'enum',
                        'size'=>"'open','awaiting_reply','in_progress','on_hold','closed','trash'",
                        'default'=>'open'
                    ]
                )
                ->setField('date_added', ['type'=>'datetime'])
                ->setField('date_updated', ['type'=>'datetime'])
                ->setField('date_closed', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
                ->setKey(['id'], 'primary')
                ->setKey(['code'], 'index')
                ->setKey(['date_added', 'status'], 'index')
                ->setKey(['department_id', 'status'], 'index')
                ->create('support_tickets', true);

            $this->Record
                ->setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('field_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('value', ['type'=>'text'])
                ->setField('encrypted', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setKey(['ticket_id', 'field_id'], 'primary')
                ->create('support_ticket_fields', true);

            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('contact_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('email', ['type'=>'varchar', 'size'=>255, 'is_null'=>true, 'default'=>null])
                ->setKey(['ticket_id', 'email', 'contact_id'], 'unique')
                ->setKey(['id'], 'primary')
                ->create('support_ticket_recipients', true);

            // Replies
            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('staff_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField(
                    'contact_id',
                    ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null]
                )
                ->setField('type', ['type'=>'enum', 'size'=>"'reply','note','log'", 'default'=>'reply'])
                ->setField('details', ['type'=>'mediumtext'])
                ->setField('ai_response_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('ai_tool_analysis_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('ai_queued', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setField('date_added', ['type'=>'datetime'])
                ->setField('is_ai_generated', ['type'=>'tinyint', 'size'=>1, 'is_null'=>true, 'default'=>null])
                ->setField('ai_summary', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setKey(['id'], 'primary')
                ->setKey(['ticket_id', 'type'], 'index')
                ->setKey(['ai_response_id'], 'index', 'idx_ai_response')
                ->setKey(['ai_tool_analysis_id'], 'index', 'idx_ai_tool_analysis')
                ->setKey(['ai_queued', 'ticket_id'], 'index', 'idx_ai_queued')
                ->setKey(['details'], 'fulltext')
                ->create('support_replies', true);

            // Attachments
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('reply_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('name', ['type'=>'varchar', 'size'=>255])->
                setField('file_name', ['type'=>'varchar', 'size'=>255])->
                setKey(['id'], 'primary')->
                setKey(['reply_id'], 'index')->
                create('support_attachments', true);

            // Departments
            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('name', ['type'=>'varchar', 'size'=>255])
                ->setField('description', ['type'=>'text'])
                ->setField('email', ['type'=>'varchar', 'size'=>255])
                ->setField('method', ['type'=>'enum', 'size'=>"'pipe','pop3','imap','oauth2','none'", 'default'=>'pipe'])
                ->setField(
                    'default_priority',
                    ['type'=>'enum', 'size'=>"'emergency','critical','high','medium','low'", 'default'=>'low']
                )
                ->setField('host', ['type'=>'varchar', 'size'=>128, 'is_null' => true, 'default' => null])
                ->setField('user', ['type'=>'varchar', 'size'=>64, 'is_null' => true, 'default' => null])
                ->setField('pass', ['type'=>'text', 'is_null' => true, 'default' => null])
                ->setField('port', ['type'=>'smallint', 'size'=>6, 'is_null' => true, 'default' => null])
                ->setField(
                    'security',
                    ['type'=>'enum', 'size'=>"'none','ssl','tls'", 'is_null' => true, 'default' => null]
                )
                ->setField('box_name', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField(
                    'mark_messages',
                    ['type'=>'enum', 'size'=>"'read','deleted'", 'is_null' => true, 'default' => null]
                )
                ->setField('oauth2_provider', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_user', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_client_id', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_client_secret', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_access_token', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_code', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('oauth2_refresh_token', ['type'=>'varchar', 'size'=>255, 'is_null' => true, 'default' => null])
                ->setField('clients_only', ['type'=>'tinyint', 'size'=>1, 'default'=>1])
                ->setField('require_captcha', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setField('override_from_email', ['type'=>'tinyint', 'size'=>1, 'default'=>1])
                ->setField('send_ticket_received', ['type'=>'tinyint', 'size'=>1, 'default'=>1])
                ->setField('automatic_transition', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setField('close_ticket_interval', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('delete_ticket_interval', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('feedback_ticket_interval', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('reminder_ticket_interval', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('reminder_ticket_status', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setField('reminder_ticket_priority', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setField('include_attachments', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setField('attachment_types', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setField('max_attachment_size', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('enable_related_services', ['type'=>'int', 'size'=>1, 'default'=>0])
                ->setField('response_id', ['type'=>'int', 'size'=>10, 'is_null'=>true, 'default'=>null])
                ->setField('status', ['type'=>'enum', 'size'=>"'hidden','visible'", 'default'=>'visible'])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id'], 'index')
                ->setKey(['status', 'company_id'], 'index')
                ->create('support_departments', true);

            $this->Record->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('department_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('order', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('label', ['type' => 'varchar', 'size' => 255])
                ->setField('description', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('visibility',
                    ['type' => 'enum', 'size' => "'client','client_only','staff'", 'is_null' => true, 'default' => 'client'])
                ->setField('type', [
                    'type' => 'enum',
                    'size' => "'checkbox','radio','select','quantity','text','textarea','password','emergency'",
                    'is_null' => true,
                    'default' => 'text'
                ])
                ->setField('min', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('max', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('step', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('client_add', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('required', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('encrypted', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('auto_delete', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('options', ['type' => 'mediumtext', 'is_null' => true, 'default' => null])
                ->setKey(['id'], 'primary')
                ->create('support_department_fields', true);

            $this->Record->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('department_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField(
                    'priority',
                    ['type'=>'enum', 'size'=>"'emergency','critical','high','medium','low'", 'default'=>'low']
                )
                ->setKey(['id'], 'primary')
                ->create('support_department_priorities', true);

            // Staff Departments
            $this->Record->
                setField('department_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('staff_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setKey(['department_id', 'staff_id'], 'primary')->
                create('support_staff_departments', true);

            // Staff Schedules
            $this->Record->
                setField('staff_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('day', ['type'=>'enum', 'size'=>"'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'"])->
                setField('start_time', ['type'=>'time'])->
                setField('end_time', ['type'=>'time'])->
                setKey(['staff_id', 'company_id', 'day'], 'primary')->
                create('support_staff_schedules', true);

            // Response Categories
            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField(
                    'parent_id',
                    ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null' => true, 'default' => null]
                )
                ->setField('name', ['type'=>'varchar', 'size'=>64])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id'], 'index')
                ->setKey(['parent_id', 'company_id'], 'index')
                ->create('support_response_categories', true);

            // Responses
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('category_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('name', ['type'=>'varchar', 'size'=>64])->
                setField('details', ['type'=>'mediumtext'])->
                setKey(['id'], 'primary')->
                setKey(['category_id'], 'index')->
                create('support_responses', true);

            // Settings
            $this->Record->
                setField('key', ['type'=>'varchar', 'size'=>32])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('value', ['type'=>'text'])->
                setKey(['key', 'company_id'], 'primary')->
                create('support_settings', true);

            // Staff Settings
            $this->Record->
                setField('key', ['type'=>'varchar', 'size'=>32])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('staff_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('value', ['type'=>'text'])->
                setKey(['key', 'company_id', 'staff_id'], 'primary')->
                create('support_staff_settings', true);

            // Knowledge base articles
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('access', ['type'=>'enum', 'size'=>"'public','private','hidden','staff'", 'default'=>'public'])->
                setField('up_votes', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])->
                setField('down_votes', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])->
                setField('date_created', ['type'=>'datetime'])->
                setField('date_updated', ['type'=>'datetime'])->
                setKey(['id'], 'primary')->
                setKey(['company_id', 'access'], 'index')->
                create('support_kb_articles', true);

            // Knowledge base article categories
            $this->Record->
                setField('category_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('article_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setKey(['category_id', 'article_id'], 'primary')->
                create('support_kb_article_categories', true);

            // Knowledgebase article content
            $this->Record->
                setField('article_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('lang', ['type'=>'char', 'size'=>5])->
                setField('title', ['type'=>'varchar', 'size'=>255])->
                setField('body', ['type'=>'mediumtext'])->
                setField('content_type', ['type'=>'enum', 'size'=>"'text','html'", 'default'=>'text'])->
                setKey(['article_id', 'lang'], 'primary')->
                create('support_kb_article_content', true);

            // Knowledgebase categories
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('parent_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('name', ['type'=>'varchar', 'size'=>255])->
                setField('description', ['type'=>'text', 'is_null'=>true, 'default'=>null])->
                setField('access', ['type'=>'enum', 'size'=>"'public','private','hidden','staff'", 'default'=>'public'])->
                setField('date_created', ['type'=>'datetime'])->
                setField('date_updated', ['type'=>'datetime'])->
                setKey(['id'], 'primary')->
                setKey(['company_id', 'parent_id', 'access'], 'index')->
                create('support_kb_categories', true);

            // Reminders notifications
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('status', ['type'=>'varchar', 'size'=>255, 'is_null'=>true, 'default'=>null])->
                setField('date_sent', ['type'=>'datetime'])->
                setKey(['id'], 'primary')->
                create('support_reminders', true);

            // Set the uploads directory
            Loader::loadComponents($this, ['SettingsCollection', 'Upload']);
            $temp = $this->SettingsCollection->fetchSetting(null, Configure::get('Blesta.company_id'), 'uploads_dir');
            $upload_path = $temp['value'] . Configure::get('Blesta.company_id') . DS . 'support_manager_files' . DS;
            // Create the upload path if it doesn't already exist
            $this->Upload->createUploadPath($upload_path, 0777);

            // Add AI schema
            $this->addAiSchema();
        } catch (Exception $e) {
            // Error adding... no permission?
            $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
            return;
        }

        // Add cron tasks
        $this->addCronTasks($this->getCronTasks());

        // Fetch all currently-installed languages for this company, for which email templates should be created for
        $languages = $this->Languages->getAll(Configure::get('Blesta.company_id'));

        // Add all email templates
        $emails = Configure::get('SupportManager.install.emails');
        foreach ($emails as $email) {
            $group = $this->EmailGroups->getByAction($email['action']);
            if ($group) {
                $group_id = $group->id;
            } else {
                $group_id = $this->EmailGroups->add([
                    'action' => $email['action'],
                    'type' => $email['type'],
                    'plugin_dir' => $email['plugin_dir'],
                    'tags' => $email['tags']
                ]);
            }

            // Set from hostname to use that which is configured for the company
            if (isset(Configure::get('Blesta.company')->hostname)) {
                $email['from'] = str_replace(
                    '@mydomain.com',
                    '@' . Configure::get('Blesta.company')->hostname,
                    $email['from']
                );
            }

            // Add the email template for each language
            foreach ($languages as $language) {
                $this->Emails->add([
                    'email_group_id' => $group_id,
                    'company_id' => Configure::get('Blesta.company_id'),
                    'lang' => $language->code,
                    'from' => $email['from'],
                    'from_name' => $email['from_name'],
                    'subject' => $email['subject'],
                    'text' => $email['text'],
                    'html' => $email['html']
                ]);
            }
        }

        // Add default avatar setting for all companies
        $companies = $this->Companies->getAll();
        foreach ($companies as $company) {
            $this->Record->insert('support_settings', [
                'key' => 'avatar',
                'company_id' => $company->id,
                'value' => 'gravatar'
            ]);

            // Add AI analyze trigger setting (when to trigger AI analysis)
            $this->Record->insert('support_settings', [
                'key' => 'sm_ai_analyze_trigger',
                'company_id' => $company->id,
                'value' => 'every_reply'
            ]);
        }

        // Register bell notification actions
        $this->addNotificationActions();
    }

    /**
     * Creates AI-related database schema (tables for AI response/tool analysis and tool usage)
     */
    private function addAiSchema()
    {
        // Create support_ai_response_analyses table (reply-centric response tracking)
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('ticket_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('conversation_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('response_text', ['type' => 'mediumtext', 'is_null' => true, 'default' => null])
            ->setField('internal_notes', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('concerns', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('status', ['type' => 'enum', 'size' => "'pending','used','expired','no_response_needed'", 'default' => 'pending'])
            ->setField('used_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('used_by_staff_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('expired_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('confidence', ['type' => 'tinyint', 'size' => 3, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('confidence_reasoning', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('model', ['type' => 'varchar', 'size' => 100, 'is_null' => true, 'default' => null])
            ->setField('prompt_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('completion_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('cost', ['type' => 'decimal', 'size' => '10,6', 'is_null' => true, 'default' => null])
            ->setField('created_at', ['type' => 'datetime'])
            ->setField('updated_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            ->setKey(['ticket_id', 'status'], 'index', 'idx_ticket_status')
            ->setKey(['conversation_id'], 'index', 'idx_conversation')
            ->create('support_ai_response_analyses', true);

        // Create support_ai_tool_analyses table (reply-centric tool analysis tracking)
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('ticket_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('conversation_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('suggested_tools', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('analysis_notes', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('concerns', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('execution_status', ['type' => 'enum', 'size' => "'pending','completed','failed','no_tools_needed'", 'default' => 'pending'])
            ->setField('executed_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('tools_executed_count', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
            ->setField('tools_skipped_count', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
            ->setField('tools_failed_count', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
            ->setField('model', ['type' => 'varchar', 'size' => 100, 'is_null' => true, 'default' => null])
            ->setField('prompt_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('completion_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('cost', ['type' => 'decimal', 'size' => '10,6', 'is_null' => true, 'default' => null])
            ->setField('created_at', ['type' => 'datetime'])
            ->setField('updated_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            ->setKey(['ticket_id', 'execution_status'], 'index', 'idx_ticket_status')
            ->setKey(['conversation_id'], 'index', 'idx_conversation')
            ->create('support_ai_tool_analyses', true);

        // Create support_ai_tool_uses table for audit logging of AI tool executions
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('ticket_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('tool_analysis_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('tool_name', ['type' => 'varchar', 'size' => 50])
            ->setField('arguments', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('result', ['type' => 'text', 'is_null' => true, 'default' => null])
            ->setField('confidence', ['type' => 'tinyint', 'size' => 3, 'unsigned' => true, 'is_null' => true, 'default' => null])
            ->setField('executed_at', ['type' => 'datetime'])
            ->setField('executed_by', ['type' => 'varchar', 'size' => 50, 'default' => 'ai_system'])
            ->setKey(['id'], 'primary')
            ->setKey(['ticket_id'], 'index')
            ->setKey(['tool_analysis_id'], 'index', 'idx_tool_analysis')
            ->setKey(['tool_name'], 'index')
            ->setKey(['executed_at'], 'index')
            ->create('support_ai_tool_uses', true);
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version
     *
     * @param string $current_version The current installed version of this plugin
     * @param int $plugin_id The ID of the plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);

        // Upgrade if possible
        if (version_compare($this->getVersion(), $current_version, '>')) {
            // Handle the upgrade, set errors using $this->Input->setErrors() if any errors encountered

            // Upgrade to 1.2.0
            if (version_compare($current_version, '1.2.0', '<')) {
                // Update format of existing staff settings
                $settings_stmt = $this->Record->select()->from('support_staff_settings')->
                    open()->
                        where('key', '=', 'mobile_ticket_emails')->
                        orWhere('key', '=', 'ticket_emails')->
                    close()->
                    getStatement();

                // Fetch the department priorities
                Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
                $priorities = $this->SupportManagerDepartments->getPriorities();

                // Set default setting values to true (i.e. to receive ticket emails)
                $values = [];
                foreach ($priorities as $key => $language) {
                    $values[$key] = 'true';
                }

                // Begin a transaction
                $this->Record->begin();

                // Update each setting
                while (($setting = $settings_stmt->fetch())) {
                    // Build the new setting
                    $new_setting = (array)$setting;
                    $new_setting['value'] = $values;

                    // Set values to false
                    if ($setting->value == 'false') {
                        foreach ($new_setting['value'] as &$value) {
                            $value = 'false';
                        }
                    }

                    // Update the setting
                    $new_setting['value'] = serialize($new_setting['value']);
                    $this->Record->duplicate('value', '=', $new_setting['value'])->
                        insert('support_staff_settings', $new_setting);
                }

                // Commit the transaction
                $this->Record->commit();
            }

            // Upgrade to 1.5.0
            if (version_compare($current_version, '1.5.0', '<')) {
                // Update email template tags to include {ticket.summary} and {ticket_hash_code}
                $this->Record->begin();

                $vars = ['tags' => '{ticket},{ticket.summary},{ticket_hash_code}'];
                $this->Record->where('action', '=', 'SupportManager.ticket_received')
                    ->update('email_groups', $vars);
                $this->Record->where('action', '=', 'SupportManager.staff_ticket_updated')
                    ->update('email_groups', $vars);
                $this->Record->where('action', '=', 'SupportManager.staff_ticket_updated_mobile')
                    ->update('email_groups', $vars);

                $vars = ['tags' => '{ticket},{ticket.summary},{update_ticket_url},{ticket_hash_code}'];
                $this->Record->where('action', '=', 'SupportManager.ticket_updated')
                    ->update('email_groups', $vars);

                $this->Record->commit();
            }

            // Upgrade to 1.5.2
            if (version_compare($current_version, '1.5.2', '<')) {
                $this->addEmailTemplates();
            }

            // Upgrade to 1.5.3
            if (version_compare($current_version, '1.5.3', '<')) {
                // Fetch all client/staff ticket updated emails to remove the http protocol from mailto links
                $email_group_action = ['SupportManager.ticket_updated', 'SupportManager.staff_ticket_updated'];

                // Remove http from mailto links
                foreach ($email_group_action as $action) {
                    // Fetch all ticket emails
                    $emails = $this->Record->select(['emails.id', 'emails.text', 'emails.html'])->from('emails')->
                        on('email_groups.id', '=', 'emails.email_group_id', false)->
                        innerJoin('email_groups', 'email_groups.action', '=', $action)->
                        getStatement();

                    // Update each ticket updated email to remove the HTTP protocol from mailto links
                    foreach ($emails as $email) {
                        // Update HTML to fix mailto for Ticket Updated and Staff Ticket Updated templates
                        $html_replace = ['http://mailto:'];
                        $html_replace_with = ['mailto:'];

                        // Set HTTP protocol on Ticket Updated email only
                        if ($action == 'SupportManager.ticket_updated') {
                            $html_replace[] = '<a href="http://{update_ticket_url}">{update_ticket_url}</a>';
                            $html_replace_with[]
                                = '<a href="http://{update_ticket_url}">http://{update_ticket_url}</a>';
                        }

                        $vars = [
                            // Set HTTP protocol for text on Ticket Updated email only
                            'text' => ($action == 'SupportManager.ticket_updated'
                                ? str_replace('{update_ticket_url}', 'http://{update_ticket_url}', $email->text)
                                : $email->text
                            ),
                            'html' => str_replace($html_replace, $html_replace_with, $email->html)
                        ];

                        if ($vars['html'] != $email->html || $vars['text'] != $email->text) {
                            $this->Record->where('id', '=', $email->id)->update('emails', $vars);
                        }
                    }
                }
            }

            // Upgrade to 1.6.0
            if (version_compare($current_version, '1.6.0', '<')) {
                // Update support departments to include the new override_from_email field
                $this->Record->query(
                    "ALTER TABLE `support_departments`
                    ADD `override_from_email` TINYINT( 1 ) NOT NULL DEFAULT '1' AFTER `clients_only` ;"
                );
            }

            // Upgrade to 1.6.4
            if (version_compare($current_version, '1.6.4', '<')) {
                Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);

                // Set date_closed for tickets that are closed
                $tickets = $this->Record->select(['id'])->from('support_tickets')->
                    where('status', '=', 'closed')->where('date_closed', '=', null)->
                    getStatement();

                // Set closed date to now
                foreach ($tickets as $ticket) {
                    $this->Record
                        ->where('id', '=', $ticket->id)
                        ->update(
                            'support_tickets',
                            ['date_closed' => $this->SupportManagerDepartments->dateToUtc(date('c'))]
                        );
                }
            }

            // Upgrade to 2.0.0
            if (version_compare($current_version, '2.0.0', '<')) {
                Loader::loadModels($this, ['CronTasks']);

                // Fetch all client ticket updated emails to update default text content
                $emails = $this->Record->select(['emails.id', 'emails.text', 'emails.html'])->from('emails')->
                    on('email_groups.id', '=', 'emails.email_group_id', false)->
                    innerJoin('email_groups', 'email_groups.action', '=', 'SupportManager.ticket_updated')->
                    getStatement();

                // Update email templates
                foreach ($emails as $email) {
                    // Update text
                    $replace = 'If you are a client, you may also update the ticket in our support area at';
                    $replace_with = 'You may also update the ticket in our support area at';

                    $vars = [
                        'text' => str_replace($replace, $replace_with, $email->text),
                        'html' => str_replace($replace, $replace_with, $email->html)
                    ];

                    if ($vars['html'] != $email->html || $vars['text'] != $email->text) {
                        $this->Record->where('id', '=', $email->id)->update('emails', $vars);
                    }
                }

                // Add new cron task to auto-close open tickets
                $cron_tasks = $this->getCronTasks();
                $task = null;
                foreach ($cron_tasks as $task) {
                    if ($task['key'] == 'close_tickets') {
                        break;
                    }
                }

                if ($task) {
                    $this->addCronTasks([$task]);
                }

                // Update support departments to include the new close_ticket_interval field
                $this->Record->query(
                    'ALTER TABLE `support_departments`
                    ADD `close_ticket_interval` INT( 10 ) NULL DEFAULT NULL AFTER `override_from_email` ;'
                );
                $this->Record->query(
                    'ALTER TABLE `support_departments`
                    ADD `response_id` INT( 10 ) NULL DEFAULT NULL AFTER `close_ticket_interval` ;'
                );

                // Add new index
                $this->Record->query('ALTER TABLE `support_tickets` ADD INDEX ( `department_id` , `status` ) ;');
            }

            // Upgrade to 2.4.0
            if (version_compare($current_version, '2.4.0', '<')) {
                // Add new database tables for the knowledge base
                $this->Record->
                    setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                    setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                    setField('access', ['type'=>'enum', 'size'=>"'public','private','hidden'", 'default'=>'public'])->
                    setField('up_votes', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])->
                    setField('down_votes', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])->
                    setField('date_created', ['type'=>'datetime'])->
                    setField('date_updated', ['type'=>'datetime'])->
                    setKey(['id'], 'primary')->
                    setKey(['company_id', 'access'], 'index')->
                    create('support_kb_articles', true);
                $this->Record->
                    setField('category_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                    setField('article_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                    setKey(['category_id', 'article_id'], 'primary')->
                    create('support_kb_article_categories', true);
                $this->Record->
                    setField('article_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                    setField('lang', ['type'=>'char', 'size'=>5])->
                    setField('title', ['type'=>'varchar', 'size'=>255])->
                    setField('body', ['type'=>'mediumtext'])->
                    setField('content_type', ['type'=>'enum', 'size'=>"'text','html'", 'default'=>'text'])->
                    setKey(['article_id', 'lang'], 'primary')->
                    create('support_kb_article_content', true);
                $this->Record
                    ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                    ->setField(
                        'parent_id',
                        ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null]
                    )
                    ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                    ->setField('name', ['type'=>'varchar', 'size'=>255])
                    ->setField('description', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                    ->setField('access', ['type'=>'enum', 'size'=>"'public','private','hidden'", 'default'=>'public'])
                    ->setField('date_created', ['type'=>'datetime'])
                    ->setField('date_updated', ['type'=>'datetime'])
                    ->setKey(['id'], 'primary')
                    ->setKey(['company_id', 'parent_id', 'access'], 'index')
                    ->create('support_kb_categories', true);
            }

            // Upgrade to v2.5.0
            if (version_compare($current_version, '2.5.0', '<')) {
                // Add a field for contacts that reply to tickets
                $this->Record->query(
                    'ALTER TABLE `support_replies`
                    ADD `contact_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `staff_id`;'
                );
            }

            // Upgrade to v2.6.0
            if (version_compare($current_version, '2.6.0', '<')) {
                // Add a new email template for when staff are assigned to a ticket
                $templates = [
                    'SupportManager.staff_ticket_assigned'
                ];
                $this->addEmailTemplates($templates);
            }

            // Upgrade to v2.12.0
            if (version_compare($current_version, '2.12.0', '<')) {
                if (!isset($this->SupportManagerDepartments)) {
                    Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
                }

                // Add a field for contacts that reply to tickets
                $this->Record->query('ALTER TABLE `support_departments` CHANGE `pass` `pass` TEXT NULL DEFAULT NULL;');

                $departments = $this->Record->select()->from('support_departments')->fetchAll();

                // Encrypt all department passwords
                foreach ($departments as $department) {
                    $this->Record->where('id', '=', $department->id)
                        ->update(
                            'support_departments',
                            ['pass' => $this->SupportManagerDepartments->systemEncrypt($department->pass)],
                            ['pass']
                        );
                }

                $this->Record->query(
                    "ALTER TABLE `support_departments`
                    ADD `send_ticket_received` TINYINT(1) NOT NULL DEFAULT '1' AFTER `override_from_email`;"
                );
            }

            // Upgrade to v2.14.0
            if (version_compare($current_version, '2.14.0', '<')) {
                Loader::loadModels($this, ['CronTasks']);

                // Add the 'trash' status
                $this->Record->query("ALTER TABLE `support_tickets`
                    CHANGE `status` `status` ENUM ('open','awaiting_reply','in_progress','closed','trash')
                    NOT NULL DEFAULT 'open';
                ");

                // Add a 'date_updated' column to the 'support_tickets' table
                $this->Record->query('ALTER TABLE `support_tickets` ADD `date_updated` DATETIME NULL DEFAULT NULL
                    AFTER `date_added`;
                ');

                // Add a 'delete_ticket_interval' column to the 'support_departments' table
                $this->Record->query('ALTER TABLE `support_departments`
                    ADD `delete_ticket_interval` INT(10) NULL DEFAULT NULL AFTER `close_ticket_interval`;
                ');

                // Set date_updated to date_added for all tickets
                $this->Record->set('support_tickets.date_updated', 'support_tickets.date_added', false)
                    ->update('support_tickets');

                // Make support_tickets.date_updated not null by default
                $this->Record->query('ALTER TABLE `support_tickets`
                    CHANGE `date_updated` `date_updated` DATETIME NOT NULL;
                ');

                // Add new cron task to auto-delete trash tickets
                $cron_tasks = $this->getCronTasks();
                $task = null;
                foreach ($cron_tasks as $task) {
                    if ($task['key'] == 'delete_tickets') {
                        break;
                    }
                }

                if ($task) {
                    $this->addCronTasks([$task]);
                }
            }

            // Upgrade to v2.14.1
            if (version_compare($current_version, '2.14.1', '<')) {
                Loader::loadModels($this, ['StaffGroups', 'Permissions']);
                Loader::loadComponents($this, ['Acl']);

                $plugin_permission = $this->Permissions->getByAlias('support_manager.admin_tickets', $plugin_id);

                if ($plugin_permission) {
                    $staff_groups = $this->StaffGroups->getAll();

                    // Deny permission for ticket deletion to all staff groups by default
                    foreach ($staff_groups as $staff_group) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'support_manager.admin_tickets', 'delete');
                    }
                }
            }

            // Upgrade to v2.15.0
            if (version_compare($current_version, '2.15.0', '<')) {
                // Add the 'on_hold' status
                $this->Record->query("ALTER TABLE `support_tickets`
                    CHANGE `status`
                    `status` ENUM ('open','awaiting_reply','in_progress','on_hold','closed','trash')
                    NOT NULL DEFAULT 'open';
                ");

                // Add index on client_id to the support_tickets table
                $this->Record->query('ALTER TABLE `support_tickets` ADD INDEX (`client_id`);');
            }

            // Upgrade to v2.16.0
            if (version_compare($current_version, '2.16.0', '<')) {
                Loader::loadModels($this, ['StaffGroups', 'Permissions']);
                Loader::loadComponents($this, ['Acl']);

                $misspelled_permission = $this->Permissions->getByAlias('suport_manager.admin_tickets', $plugin_id);

                if ($misspelled_permission) {
                    $this->Permissions->edit(
                        $misspelled_permission->id,
                        [
                            'group_id' => $misspelled_permission->group_id,
                            'name' => $misspelled_permission->name,
                            'alias' => 'support_manager.admin_tickets',
                            'action' => $misspelled_permission->action
                        ]
                    );
                }
            }

            // Upgrade to v2.16.2
            if (version_compare($current_version, '2.16.2', '<')) {
                // Add the {ticket_hash_code} to the ticket_received email templates that don't already have it
                $emails = $this->Record->select(['emails.id', 'emails.subject'])
                    ->from('email_groups')
                    ->innerJoin('emails', 'emails.email_group_id', '=', 'email_groups.id', false)
                    ->where('email_groups.action', '=', 'SupportManager.ticket_received')
                    ->notLike('emails.subject', '%{ticket_hash_code}%')
                    ->fetchAll();

                foreach ($emails as $email) {
                    $this->Record->where('emails.id', '=', $email->id)
                        ->update('emails', ['subject' => $email->subject . ' {ticket_hash_code}']);
                }
            }

            // Upgrade to v2.17.0
            if (version_compare($current_version, '2.17.0', '<')) {
                // Add a 'automatic_transition' column to the 'support_departments' table
                $this->Record->query('ALTER TABLE `support_departments` ADD `automatic_transition` TINYINT(1) NOT NULL
                    DEFAULT 0 AFTER `send_ticket_received`;
                ');
            }

            // Upgrade to 2.20.0
            if (version_compare($current_version, '2.20.0', '<')) {
                // Update email template tags to include {contact}
                $this->Record->query(
                    "UPDATE `email_groups` SET tags = CONCAT(`tags`, ',{client},{reply_contact}')
                    WHERE action IN (?, ?, ?)",
                    [
                        'SupportManager.ticket_received',
                        'SupportManager.staff_ticket_updated',
                        'SupportManager.staff_ticket_updated_mobile'
                    ]
                );
                $this->Record->query(
                    "UPDATE `email_groups` SET tags = CONCAT(`tags`, ',{client}') WHERE action = ?",
                    ['SupportManager.ticket_updated']
                );
            }

            // Upgrade to 2.21.0
            if (version_compare($current_version, '2.21.0', '<')) {
                // Update ticket_received email template tags to include {update_ticket_url}
                $this->Record->query(
                    "UPDATE `email_groups` SET tags = CONCAT(`tags`, ',{update_ticket_url}') WHERE action = ?",
                    ['SupportManager.ticket_received']
                );
            }

            $this->upgrade2_24_0($current_version);
            $this->upgrade2_25_0($current_version);
            $this->upgrade2_27_0($current_version);
            $this->upgrade2_27_2($current_version);
            $this->upgrade2_28_0($current_version);
            $this->upgrade2_29_0($current_version);
            $this->upgrade2_33_0($current_version);
            $this->upgrade2_34_0($current_version);
            $this->upgrade2_35_0($current_version);
            $this->upgrade2_38_0($current_version);
            $this->upgrade2_39_0($current_version);
            $this->upgrade2_40_0($current_version);
            $this->upgrade3_0_0($current_version);
            $this->upgrade3_1_0($current_version);
            $this->upgrade3_2_0($current_version);
            $this->upgrade3_2_1($current_version);
            $this->upgrade3_3_0($current_version);
        }
    }

    /**
     * Run the upgrade for v2.24.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_24_0($current_version)
    {
        // Upgrade to 2.24.0
        if (version_compare($current_version, '2.24.0', '<')) {
            // Update support departments to include the new require_captcha field
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `require_captcha` TINYINT( 1 ) NOT NULL DEFAULT '0' AFTER `clients_only`;"
            );
        }
    }

    /**
     * Run the upgrade for v2.25.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_25_0($current_version)
    {
        // Upgrade to 2.25.0
        if (version_compare($current_version, '2.25.0', '<')) {
            // Add a FULLTEXT index on the support replies
            $this->Record->query("ALTER TABLE `support_replies` ADD FULLTEXT `details` (`details`)");
        }
    }

    /**
     * Run the upgrade for v2.27.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_27_0($current_version)
    {
        // Upgrade to v2.27.0
        if (version_compare($current_version, '2.27.0', '<')) {
            // Add new database tables for reminders
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('status', ['type'=>'varchar', 'size'=>255, 'is_null'=>true, 'default'=>null])->
                setField('date_sent', ['type'=>'datetime'])->
                setKey(['id'], 'primary')->
                create('support_reminders', true);

            // Update support departments to include new reminder fields
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `reminder_ticket_interval` TINYINT( 10 ) NULL DEFAULT NULL AFTER `delete_ticket_interval`;"
            );
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `reminder_ticket_status` TEXT NULL DEFAULT NULL AFTER `reminder_ticket_interval`;"
            );
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `reminder_ticket_priority` TEXT NULL DEFAULT NULL AFTER `reminder_ticket_status`;"
            );

            // Add a new email template for client and staff reminders
            $templates = [
                'SupportManager.ticket_reminder',
                'SupportManager.staff_ticket_reminder'
            ];
            $this->addEmailTemplates($templates);

            // Add new cron task to send ticket reminders
            Loader::loadModels($this, ['CronTasks']);

            $cron_tasks = $this->getCronTasks();
            $task = null;
            foreach ($cron_tasks as $task) {
                if ($task['key'] == 'send_reminders') {
                    break;
                }
            }

            if ($task) {
                $this->addCronTasks([$task]);
            }

            // Update support departments to include new attachment fields
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `include_attachments` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reminder_ticket_priority`;"
            );
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `attachment_types` VARCHAR(255) NULL DEFAULT NULL AFTER `include_attachments`;"
            );
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `max_attachment_size` INT(10) NULL DEFAULT NULL AFTER `attachment_types`;"
            );
        }
    }

    /**
     * Run the upgrade for v2.27.2 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_27_2($current_version)
    {
        // Upgrade to v2.27.2
        if (version_compare($current_version, '2.27.2', '<')) {

            // Update support departments to include new reminder fields
            $this->Record->query(
                "ALTER TABLE `support_departments`
                CHANGE `reminder_ticket_interval` `reminder_ticket_interval` INT( 10 ) NULL DEFAULT NULL;"
            );
        }
    }

    /**
     * Run the upgrade for v2.28.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_28_0($current_version)
    {
        // Upgrade to v2.28.0
        if (version_compare($current_version, '2.28.0', '<')) {
            $this->Record
                ->setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('field_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('value', ['type'=>'text'])
                ->setField('encrypted', ['type'=>'tinyint', 'size'=>1, 'default'=>0])
                ->setKey(['ticket_id', 'field_id'], 'primary')
                ->create('support_ticket_fields', true);

            $this->Record->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('department_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('order', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('label', ['type' => 'varchar', 'size' => 255])
                ->setField('description', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('visibility',
                    ['type' => 'enum', 'size' => "'client','staff'", 'is_null' => true, 'default' => null])
                ->setField('type', [
                        'type' => 'enum',
                        'size' => "'checkbox','radio','select','quantity','text','textarea','password'",
                        'is_null' => true,
                        'default' => 'text'
                    ])
                ->setField('min', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('max', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('step', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('client_add', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('encrypted', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('auto_delete', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('options', ['type' => 'mediumtext', 'is_null' => true, 'default' => null])
                ->setKey(['id'], 'primary')
                ->create('support_department_fields', true);
        }
    }

    /**
     * Run the upgrade for v2.29.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_29_0($current_version)
    {
        // Upgrade to v2.29.0
        if (version_compare($current_version, '2.29.0', '<')) {
            // Update support knowledge base articles access to add 'staff' option
            $this->Record->query(
                "ALTER TABLE `support_kb_articles`
                CHANGE `access` `access` ENUM('public','private','hidden','staff') NOT NULL DEFAULT 'public';"
            );

            // Update support knowledge base categories access to add 'staff' option
            $this->Record->query(
                "ALTER TABLE `support_kb_categories`
                CHANGE `access` `access` ENUM('public','private','hidden','staff') NOT NULL DEFAULT 'public';"
            );
        }
    }

    /**
     * Run the upgrade for v2.33.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_33_0($current_version)
    {
        // Upgrade to v2.33.0
        if (version_compare($current_version, '2.33.0', '<')) {
            $this->Record->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('department_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField(
                    'priority',
                    ['type'=>'enum', 'size'=>"'emergency','critical','high','medium','low'", 'default'=>'low']
                )
                ->setKey(['id'], 'primary')
                ->create('support_department_priorities', true);

            Loader::loadModels($this, ['Companies', 'SupportManager.SupportManagerDepartments']);

            $companies = $this->Companies->getAll();
            foreach ($companies as $company) {
                $departments = $this->SupportManagerDepartments->getAll($company->id);
                foreach ($departments as $department) {
                    $priorities = ['emergency', 'critical', 'high', 'medium', 'low'];
                    foreach ($priorities as $priority) {
                        $this->Record->insert("support_department_priorities", [
                            'department_id' => $department->id,
                            'priority' => $priority
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Run the upgrade for v2.34.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_34_0($current_version)
    {
        // Upgrade to v2.34.0
        if (version_compare($current_version, '2.34.0', '<')) {
            // Update support ticket code to varchar with a max length of 128
            $this->Record->query(
                "ALTER TABLE `support_tickets` CHANGE `code` `code` VARCHAR(128) NOT NULL;"
            );
        }
    }

    /**
     * Run the upgrade for v2.35.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_35_0($current_version)
    {
        // Upgrade to 2.35.0
        if (version_compare($current_version, '2.35.0', '<')) {
            // Update email template tags to include {subject} and {staff_name}
            $this->Record->begin();

            $vars = ['tags' => '{ticket},{update_ticket_url},{ticket_hash_code},{client},{subject},{staff_name}'];
            $this->Record->where('action', '=', 'SupportManager.ticket_updated')
                ->update('email_groups', $vars);

            $this->Record->commit();

            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('ticket_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('contact_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('email', ['type'=>'varchar', 'size'=>255, 'is_null'=>true, 'default'=>null])
                ->setKey(['ticket_id', 'email', 'contact_id'], 'unique')
                ->setKey(['id'], 'primary')
                ->create('support_ticket_recipients', true);
        }
    }

    /**
     * Run the upgrade for v2.38.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_38_0($current_version)
    {
        // Upgrade to 2.38.0
        if (version_compare($current_version, '2.38.0', '<')) {
            $this->Record->query(
                "ALTER TABLE `support_departments`
                ADD `enable_related_services` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_attachment_size`;"
            );
        }
    }

    /**
     * Run the upgrade for v2.39.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_39_0($current_version)
    {
        // Upgrade to v2.39.0
        if (version_compare($current_version, '2.39.0', '<')) {
            // Add the 'client_only' visibility status
            $this->Record->query("ALTER TABLE `support_department_fields`
                CHANGE `visibility` `visibility` ENUM ('client','client_only','staff')
                NOT NULL DEFAULT 'client';
            ");

            // Add the 'emergency' type
            $this->Record->query("ALTER TABLE `support_department_fields`
                CHANGE `type` `type` ENUM ('checkbox','radio','select','quantity','text','textarea','password','emergency')
                NOT NULL DEFAULT 'text';
            ");

            // Add the 'required' column
            $this->Record->query("ALTER TABLE `support_department_fields`
                ADD `required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `client_add`;
            ");
        }
    }

    /**
     * Run the upgrade for v2.40.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade2_40_0($current_version)
    {
        // Upgrade to v2.40.0
        if (version_compare($current_version, '2.40.0', '<')) {
            // Add default avatar setting for all companies
            Loader::loadModels($this, ['Companies']);

            // Add new OAuth 2.0 columns
            $columns = [
                'oauth2_provider', 'oauth2_user', 'oauth2_client_id', 'oauth2_client_secret',
                'oauth2_access_token', 'oauth2_code', 'oauth2_refresh_token'
            ];
            foreach ($columns as $column) {
                $this->Record->query("ALTER TABLE `support_departments`
                    ADD `" . $column . "` VARCHAR( 255 ) NULL DEFAULT NULL AFTER `mark_messages`;"
                );
            }

            $this->Record->query("ALTER TABLE `support_departments` CHANGE `method` `method`
                ENUM('pipe','pop3','imap','oauth2','none')
                CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL;
            ");

            $companies = $this->Companies->getAll();
            foreach ($companies as $company) {
                $this->Record->insert('support_settings', [
                    'key' => 'avatar',
                    'company_id' => $company->id,
                    'value' => 'gravatar'
                ]);
            }

            // Add the 'request_feedback' column to 'support_tickets' table
            $this->Record->query("ALTER TABLE `support_tickets`
                ADD `request_feedback` TINYINT(1) NOT NULL DEFAULT 0 AFTER `priority`;
            ");

            // Add the 'rating' column to 'support_tickets' table
            $this->Record->query("ALTER TABLE `support_tickets`
                ADD `rating` INT(10) NULL DEFAULT NULL AFTER `request_feedback`;
            ");

            // Add the 'rating' column to 'support_tickets' table
            $this->Record->query("ALTER TABLE `support_tickets`
                ADD `rating_comment` TEXT NULL DEFAULT NULL AFTER `rating`;
            ");

            // Add the 'feedback_ticket_interval' column to 'support_departments' table
            $this->Record->query("ALTER TABLE `support_departments`
                ADD `feedback_ticket_interval` INT(10) NULL DEFAULT NULL AFTER `delete_ticket_interval`;
            ");

            $this->addEmailTemplates();

            // Add new cron task to send ticket reminders
            Loader::loadModels($this, ['CronTasks']);

            $cron_tasks = $this->getCronTasks();
            $task = null;
            foreach ($cron_tasks as $task) {
                if ($task['key'] == 'send_feedback_reminders') {
                    break;
                }
            }

            if ($task) {
                $this->addCronTasks([$task]);
            }
        }
    }

    /**
     * Run the upgrade for v3.0.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade3_0_0($current_version)
    {
        if (version_compare($current_version, '3.0.0', '<')) {
            // Add the 'date_rated' column to 'support_tickets' table
            $this->Record->query("ALTER TABLE `support_tickets`
                ADD `date_rated` DATETIME NULL DEFAULT NULL AFTER `rating_comment`;
            ");
        }
    }

    /**
     * Run the upgrade for v3.1.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade3_1_0($current_version)
    {
        if (version_compare($current_version, '3.1.0', '<')) {
            // Register bell notification actions for existing installations
            $this->addNotificationActions();
        }
    }

    /**
     * Run the upgrade for v3.2.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade3_2_0($current_version)
    {
        if (version_compare($current_version, '3.2.0', '<')) {
            // Create AI schema (tables for AI response/tool analysis and tool usage)
            $this->addAiSchema();

            // Add new cron task to generate AI responses
            Loader::loadModels($this, ['CronTasks']);

            $cron_tasks = $this->getCronTasks();
            $task = null;
            foreach ($cron_tasks as $cron_task) {
                if ($cron_task['key'] == 'process_tickets_with_ai') {
                    $task = $cron_task;
                    break;
                }
            }

            if ($task) {
                $this->addCronTasks([$task]);
            }

            // Add AI tracking columns to support_replies table
            $this->Record->query("
                ALTER TABLE `support_replies`
                ADD COLUMN `ai_response_id` INT(10) UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Links to AI response analysis for this reply' AFTER `details`,
                ADD COLUMN `ai_tool_analysis_id` INT(10) UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Links to AI tool analysis for this reply' AFTER `ai_response_id`,
                ADD COLUMN `ai_queued` TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Flag: reply is queued for AI processing' AFTER `ai_tool_analysis_id`,
                ADD INDEX `idx_ai_response` (`ai_response_id`),
                ADD INDEX `idx_ai_tool_analysis` (`ai_tool_analysis_id`),
                ADD INDEX `idx_ai_queued` (`ai_queued`, `ticket_id`)
            ");

            // Add new setting for AI analyze trigger (when to trigger AI analysis)
            Loader::loadModels($this, ['Companies']);
            $companies = $this->Companies->getAll();

            foreach ($companies as $company) {
                $this->Record->duplicate('value', '=', 'every_reply')
                    ->insert('support_settings', [
                        'key' => 'sm_ai_analyze_trigger',
                        'company_id' => $company->id,
                        'value' => 'every_reply'
                    ]);
            }
        }
    }

    /**
     * Run the upgrade for v3.2.1 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade3_2_1($current_version)
    {
        if (version_compare($current_version, '3.2.1', '<')) {
            // Add is_ai_generated column to support_replies table for existing installations
            // This column was added in install() but missed in upgrade3_2_0
            // Check if column exists first to avoid error on fresh 3.2.0 installations
            $columns = $this->Record->query("SHOW COLUMNS FROM `support_replies` LIKE 'is_ai_generated'")->fetch();
            if (empty($columns)) {
                $this->Record->query("
                    ALTER TABLE `support_replies`
                    ADD COLUMN `is_ai_generated` TINYINT(1) NULL DEFAULT NULL
                        COMMENT 'Flag: reply was generated by AI' AFTER `date_added`
                ");
            }

            // Add AI assistant name setting for all companies
            Loader::loadModels($this, ['Companies']);
            $companies = $this->Companies->getAll();

            foreach ($companies as $company) {
                $this->Record->duplicate('value', '=', '')
                    ->insert('support_settings', [
                        'key' => 'sm_ai_assistant_name',
                        'company_id' => $company->id,
                        'value' => ''
                    ]);
            }
        }
    }

    /**
     * Run the upgrade for v3.3.0 if being upgraded from a previous version
     *
     * @param string $current_version The current version of the plugin being upgraded
     */
    private function upgrade3_3_0($current_version)
    {
        if (version_compare($current_version, '3.3.0', '<')) {
            // Add ai_summary column to support_replies table
            $columns = $this->Record->query("SHOW COLUMNS FROM `support_replies` LIKE 'ai_summary'")->fetch();
            if (empty($columns)) {
                $this->Record->query("
                    ALTER TABLE `support_replies`
                    ADD COLUMN `ai_summary` TEXT NULL DEFAULT NULL AFTER `is_ai_generated`
                ");
            }
        }
    }

    /**
     * Adds a new email template to the system
     *
     * @param array $templates An array with the email templates to add (optional)
     */
    private function addEmailTemplates(array $templates = [])
    {
        Loader::loadModels($this, ['Emails', 'EmailGroups', 'Languages']);
        $languages = $this->Languages->getAll(Configure::get('Blesta.company_id'));

        $emails = Configure::get('SupportManager.install.emails');
        foreach ($emails as $email) {
            // Only add this email template if it doesn't already exist
            if (
                (!in_array($email['action'], $templates) || ($group = $this->EmailGroups->getByAction($email['action'])))
                && !empty($templates)
            ) {
                continue;
            }

            $group = $this->EmailGroups->getByAction($email['action']);
            if ($group && empty($templates)) {
                $group_id = $group->id;
            } else {
                $group_id = $this->EmailGroups->add([
                    'action' => $email['action'],
                    'type' => $email['type'],
                    'plugin_dir' => $email['plugin_dir'],
                    'tags' => $email['tags']
                ]);
            }

            // Set from hostname to use that which is configured for the company
            if (isset(Configure::get('Blesta.company')->hostname)) {
                $email['from'] = str_replace(
                    '@mydomain.com',
                    '@' . Configure::get('Blesta.company')->hostname,
                    $email['from']
                );
            }

            // Add the email template for each language
            foreach ($languages as $language) {
                if (empty($templates)) {
                    // Check if this email already exists for this language
                    $template = $this->Emails->getByType(
                        Configure::get('Blesta.company_id'),
                        $email['action'],
                        $language->code
                    );

                    // Template already exists for this language
                    if ($template !== false) {
                        continue;
                    }

                }

                $this->Emails->add([
                    'email_group_id' => $group_id,
                    'company_id' => Configure::get('Blesta.company_id'),
                    'lang' => $language->code,
                    'from' => $email['from'],
                    'from_name' => $email['from_name'],
                    'subject' => $email['subject'],
                    'text' => $email['text'],
                    'html' => $email['html']
                ]);
            }
        }
    }

    /**
     * Registers bell notification actions for the support manager plugin
     * and enables them for all staff groups in the current company
     */
    private function addNotificationActions()
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $actions = [
            [
                'action' => 'SupportManager.staff_ticket_updated',
                'target' => 'staff'
            ],
            [
                'action' => 'SupportManager.staff_ticket_assigned',
                'target' => 'staff'
            ]
        ];

        $company_id = Configure::get('Blesta.company_id');

        // Get all staff groups for this company
        $staff_groups = $this->Record->select(['id'])
            ->from('staff_groups')
            ->where('company_id', '=', $company_id)
            ->fetchAll();

        foreach ($actions as $action) {
            // Only add to notification_actions if it doesn't already exist
            $exists = $this->Record->select(['id'])
                ->from('notification_actions')
                ->where('company_id', '=', $company_id)
                ->where('action', '=', $action['action'])
                ->where('target', '=', $action['target'])
                ->fetch();

            if (!$exists) {
                $this->Record->insert('notification_actions', [
                    'company_id' => $company_id,
                    'dir' => 'support_manager',
                    'type' => 'plugin',
                    'target' => $action['target'],
                    'action' => $action['action']
                ]);
            }

            // Enable for all staff groups and their staff members (allow duplicates without error)
            foreach ($staff_groups as $staff_group) {
                $this->Record->duplicate('action', '=', $action['action'])
                    ->insert('staff_group_notifications', [
                        'staff_group_id' => $staff_group->id,
                        'action' => $action['action']
                    ]);

                // Opt in all staff members in this group by default
                $staff_members = $this->Record->select(['staff_group.staff_id'])
                    ->from('staff_group')
                    ->where('staff_group.staff_group_id', '=', $staff_group->id)
                    ->fetchAll();

                foreach ($staff_members as $staff_member) {
                    $this->Record->duplicate('action', '=', $action['action'])
                        ->insert('staff_notifications', [
                            'staff_group_id' => $staff_group->id,
                            'staff_id' => $staff_member->staff_id,
                            'action' => $action['action']
                        ]);
                }
            }
        }
    }

    /**
     * Removes bell notification actions for the support manager plugin
     * and cleans up staff group notification entries
     */
    private function removeNotificationActions()
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $actions = [
            'SupportManager.staff_ticket_updated',
            'SupportManager.staff_ticket_assigned'
        ];

        foreach ($actions as $action) {
            // Remove from staff_group_notifications
            $this->Record->from('staff_group_notifications')
                ->where('action', '=', $action)
                ->delete();

            // Remove from staff_notifications
            $this->Record->from('staff_notifications')
                ->where('action', '=', $action)
                ->delete();
        }

        // Remove from notification_actions
        $this->Record->from('notification_actions')
            ->where('dir', '=', 'support_manager')
            ->where('type', '=', 'plugin')
            ->where('company_id', '=', Configure::get('Blesta.company_id'))
            ->delete();
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance
     *  across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
        Loader::loadModels($this, ['CronTasks', 'Emails', 'EmailGroups']);
        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);

        $emails = Configure::get('SupportManager.install.emails');

        // Fetch the cron tasks for this plugin
        $cron_tasks = $this->getCronTasks();

        // Remove the tables created by this plugin
        if ($last_instance) {
            try {
                // Uninstall tables
                $this->Record->drop('support_tickets');
                $this->Record->drop('support_ticket_fields');
                $this->Record->drop('support_ticket_recipients');
                $this->Record->drop('support_replies');
                $this->Record->drop('support_attachments');
                $this->Record->drop('support_departments');
                $this->Record->drop('support_department_fields');
                $this->Record->drop('support_staff_departments');
                $this->Record->drop('support_staff_schedules');
                $this->Record->drop('support_response_categories');
                $this->Record->drop('support_responses');
                $this->Record->drop('support_settings');
                $this->Record->drop('support_staff_settings');
                $this->Record->drop('support_kb_articles');
                $this->Record->drop('support_kb_article_categories');
                $this->Record->drop('support_kb_article_content');
                $this->Record->drop('support_kb_categories');
                $this->Record->drop('support_reminders');
                // Drop AI tables
                $this->Record->drop('support_ai_response_analyses');
                $this->Record->drop('support_ai_tool_analyses');
                $this->Record->drop('support_ai_tool_uses');
            } catch (Exception $e) {
                // Error dropping... no permission?
                $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
                return;
            }

            // Remove the cron tasks
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }

        // Remove individual cron task runs
        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }

        // Remove emails and email groups as necessary
        foreach ($emails as $email) {
            // Fetch the email template created by this plugin
            $group = $this->EmailGroups->getByAction($email['action']);

            // Delete all emails templates belonging to this plugin's email group and company
            if ($group) {
                $this->Emails->deleteAll($group->id, Configure::get('Blesta.company_id'));

                if ($last_instance) {
                    $this->EmailGroups->delete($group->id);
                }
            }
        }

        // Remove bell notification actions
        $this->removeNotificationActions();
    }

    /**
     * Retrieves the total number of open tickets
     *
     * @param int $client_id The ID of the client assigned to the tickets
     * @return int The total number of open tickets
     */
    public function getOpenTicketsCount($client_id)
    {
        Loader::loadModels($this, ['SupportManager.SupportManagerTickets']);

        return $this->SupportManagerTickets->getListCount('not_closed', null, $client_id);
    }

    /**
     * Returns all actions to be configured for this widget
     * (invoked after install() or upgrade(), overwrites all existing actions)
     *
     * @return array A numerically indexed array containing:
     *  - action The action to register for
     *  - uri The URI to be invoked for the given action
     *  - name The name to represent the action (can be language definition)
     *  - options An array of key/value pair options for the given action
     */
    public function getActions()
    {
        return [
            // Client Nav
            [
                'action' => 'nav_primary_client',
                'uri' => 'plugin/support_manager/client_main/',
                'name' => 'SupportManagerPlugin.nav_primary_client.main',
                'options' => [
                    'sub' => [
                        [
                            'uri' => 'plugin/support_manager/client_tickets/',
                            'name' => 'SupportManagerPlugin.nav_primary_client.tickets'
                        ],
                        [
                            'uri' => 'plugin/support_manager/knowledgebase/',
                            'name' => 'SupportManagerPlugin.nav_primary_client.knowledgebase'
                        ]
                    ]
                ]
            ],
            // Client Widget
            [
                'action' => 'widget_client_home',
                'uri' => 'plugin/support_manager/client_main/index/',
                'name' => 'SupportManagerPlugin.widget_client_home.main'
            ],
            // Staff Nav
            [
                'action' => 'nav_primary_staff',
                'uri' => 'plugin/support_manager/admin_main/',
                'name' => 'SupportManagerPlugin.nav_primary_staff.main',
                'icon' => 'bi-headset',
                'options' => [
                    'sub' => [
                        [
                            'uri' => 'plugin/support_manager/admin_tickets/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.tickets'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_departments/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.departments'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_responses/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.responses'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_staff/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.staff'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_knowledgebase/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.knowledgebase'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_main/settings/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.settings'
                        ],
                        [
                            'uri' => 'plugin/support_manager/admin_main/ai/',
                            'name' => 'SupportManagerPlugin.nav_primary_staff.ai'
                        ]
                    ]
                ]
            ],
            // Widget
            [
                'action' => 'widget_staff_client',
                'uri' => 'plugin/support_manager/admin_tickets/client/',
                'name' => 'SupportManagerPlugin.widget_staff_client.tickets'
            ],
            // Client Profile Action Link
            [
                'action' => 'action_staff_client',
                'uri' => 'plugin/support_manager/admin_tickets/add/',
                'name' => 'SupportManagerPlugin.action_staff_client.add',
                'options' => [
                    'class' => 'ticket',
                    'icon' => 'fa-ticket-alt'
                ]
            ]
        ];
    }

    /**
     * Returns all cards to be configured for this plugin (invoked after install() or upgrade(),
     * overwrites all existing cards)
     *
     * @return array A numerically indexed array containing:
     *
     *  - level The level this card should be displayed on (client or staff) (optional, default client)
     *  - callback A method defined by the plugin class for calculating the value of the card or fetching a custom html
     *  - callback_type The callback type, 'value' to fetch the card value or
     *      'html' to fetch the custom html code (optional, default value)
     *  - background The background color in hexadecimal or path to the background image for this card (optional)
     *  - background_type The background type, 'color' to set a hexadecimal background or
     *      'image' to set an image background (optional, default color)
     *  - label A string or language key appearing under the value as a label
     *  - link The link to which the card will be pointed (optional)
     *  - enabled Whether this card appears on client profiles by default
     *      (1 to enable, 0 to disable) (optional, default 1)
     */
    public function getCards()
    {
        return [
            [
                'level' => 'client',
                'callback' => ['this', 'getOpenTicketsCount'],
                'callback_type' => 'value',
                'text_color' => '#edf6ff',
                'background' => '#007bff',
                'background_type' => 'color',
                'label' => 'SupportManagerPlugin.card_client.tickets',
                'link' => '/plugin/support_manager/client_tickets/',
                'enabled' => 1
            ]
        ];
    }

    /**
     * Returns all permissions to be configured for this plugin (invoked after install(), upgrade(),
     *  and uninstall(), overwrites all existing permissions)
     *
     * @return array A numerically indexed array containing:
     *
     *  - group_alias The alias of the permission group this permission belongs to
     *  - name The name of this permission
     *  - alias The ACO alias for this permission (i.e. the Class name to apply to)
     *  - action The action this ACO may control (i.e. the Method name of the alias to control access for)
     */
    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_tickets', true),
                'alias' => 'support_manager.admin_tickets',
                'action' => '*'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_tickets_client', true),
                'alias' => 'support_manager.admin_tickets',
                'action' => 'client'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_tickets_delete', true),
                'alias' => 'support_manager.admin_tickets',
                'action' => 'delete'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_departments', true),
                'alias' => 'support_manager.admin_departments',
                'action' => '*'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_responses', true),
                'alias' => 'support_manager.admin_responses',
                'action' => '*'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_staff', true),
                'alias' => 'support_manager.admin_staff',
                'action' => '*'
            ],
            [
                'group_alias' => 'support_manager.admin_main',
                'name' => Language::_('SupportManagerPlugin.permission.admin_knowledgebase', true),
                'alias' => 'support_manager.admin_knowledgebase',
                'action' => '*'
            ]
        ];
    }

    /**
     * Returns all permission groups to be configured for this plugin (invoked after install(), upgrade(),
     *  and uninstall(), overwrites all existing permission groups)
     *
     * @return array A numerically indexed array containing:
     *
     *  - name The name of this permission group
     *  - level The level this permission group resides on (staff or client)
     *  - alias The ACO alias for this permission group (i.e. the Class name to apply to)
     */
    public function getPermissionGroups()
    {
        return [
            [
                'name' => Language::_('SupportManagerPlugin.permission.admin_main', true),
                'level' => 'staff',
                'alias' => 'support_manager.admin_main'
            ]
        ];
    }

    /**
     * Returns all events to be registered for this plugin
     * (invoked after install() or upgrade(), overwrites all existing events)
     *
     * @return array A numerically indexed array containing:
     *  - event The event to register for
     *  - callback A string or array representing a callback function or class/method.
     *      If a user (e.g. non-native PHP) function or class/method, the plugin must
     *      automatically define it when the plugin is loaded. To invoke an instance
     *      methods pass "this" instead of the class name as the 1st callback element.
     */
    public function getEvents()
    {
        return [
            [
                'event' => 'Report.clientData',
                'callback' => ['this', 'getClientData']
            ],
            [
                'event' => 'Navigation.getSearchOptions',
                'callback' => ['this', 'getSearchOptions']
            ],
            [
                'event' => 'Clients.delete',
                'callback' => ['this', 'deleteClientTickets']
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageTemplates()
    {
        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);

        return Configure::get('SupportManager.install.messages');
    }

    /**
     * Appends ticket information to the existing client data
     *
     * @param EventInterface $event The event to process
     */
    public function getClientData(EventInterface $event)
    {
        Loader::loadModels($this, ['SupportManager.SupportManagerTickets']);
        $params = $event->getParams();
        $return = $event->getReturnValue();

        if (isset($params['client_id'])) {
            // Get all tickets for this client
            $i = 1;
            $data = ['tickets' => []];
            while (($tickets = $this->SupportManagerTickets->getList(
                null,
                null,
                $params['client_id'],
                $i++,
                ['last_reply_date' => 'desc'],
                true,
                ['reply']
            ))) {
                $data['tickets'] = array_merge($data['tickets'], $tickets);
            }

            // Filter out sensitive or internal data from the ticket
            foreach ($data['tickets'] as $index => $ticket) {
                $data['tickets'][$index] = $this->filterTicketExportData($ticket);
            }

            if (!isset($return['extra'])) {
                $return['extra'] = [];
            }
            $return['extra'][] = $data;
        }

        $event->setReturnValue($return);
    }

    /**
     * Strips out any sensitive or internal data from the given ticket
     *
     * @param stdClass $ticket The ticket to remove data from
     */
    private function filterTicketExportData(stdClass $ticket)
    {
        $ticket_whitelist = ['code', 'email', 'summary', 'priority', 'status', 'date_added', 'date_closed', 'replies'];
        $reply_whitelist = ['type', 'details', 'date_added', 'attachments'];
        $attachment_whitelist = ['name'];

        // Filter out unnecessary ticket data
        foreach ($ticket as $property => $value) {
            if (!in_array($property, $ticket_whitelist)) {
                unset($ticket->{$property});
            }
        }

        // Filter out unnecessary reply data
        foreach ($ticket->replies as $reply) {
            foreach ($reply as $property => $value) {
                if (!in_array($property, $reply_whitelist)) {
                    unset($reply->{$property});
                }
            }

            // Filter out unnecessary attachment data
            foreach ($reply->attachments as $attachment) {
                foreach ($attachment as $property => $value) {
                    if (!in_array($property, $attachment_whitelist)) {
                        unset($attachment->{$property});
                    }
                }
            }
        }

        return $ticket;
    }

    /**
     * Returns the search options to append to the list of staff search options
     *
     * @param EventInterface $event The event to process
     */
    public function getSearchOptions(EventInterface $event)
    {
        $params = $event->getParams();

        if (isset($params['options'])) {
            $params['options'] += [
                $params['base_uri'] . 'plugin/support_manager/admin_tickets/search/'
                => Language::_('SupportManagerPlugin.event_getsearchoptions.tickets', true)
            ];
        }

        $event->setParams($params);
    }

    /**
     * Deletes all tickets, attachments, and replies associated with this client
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event The event to process
     */
    public function deleteClientTickets($event)
    {
        Loader::loadModels($this, ['SupportManager.SupportManagerTickets']);
        $params = $event->getParams();

        if (isset($params['client_id'])) {
            while (($tickets = $this->SupportManagerTickets->getList(null, null, $params['client_id']))) {
                $ticket_ids = [];
                foreach ($tickets as $ticket) {
                    // Keep track of the tickets to delete
                    $ticket_ids[] = $ticket->id;

                    // Look at each reply for this ticket
                    foreach ($ticket->replies as $reply) {
                        // Unlink attachment files
                        foreach ($reply->attachments as $attachment) {
                            unlink($attachment->file_name);
                        }
                    }
                }

                // Delete tickets, replies, and attachment records
                $this->SupportManagerTickets->delete($ticket_ids);
            }
        }
    }

    /**
     * Execute the cron task
     *
     * @param string $key The cron task to execute
     */
    public function cron($key)
    {
        switch ($key) {
            case 'poll_tickets':
                Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);

                // Set options when processing emails
                $webdir = $this->SupportManagerDepartments->getWebDirectory();
                $is_cli = (empty($_SERVER['REQUEST_URI']));

                // Set the URIs to the admin/client portals
                $options = [
                    'is_cli' => $is_cli,
                    'client_uri' => $webdir . Configure::get('Route.client') . '/',
                    'admin_uri' => $webdir . Configure::get('Route.admin') . '/'
                ];

                Loader::loadComponents($this, ['SupportManager.TicketManager']);
                $this->TicketManager->setOptions($options);
                $this->TicketManager->processDepartmentEmails();
                break;
            case 'close_tickets':
                $this->cronCloseTickets();
                break;
            case 'delete_tickets':
                $this->cronDeleteTickets();
                break;
            case 'send_feedback_reminders':
                $this->sendFeedbackReminders();
                break;
            case 'send_reminders':
                $this->sendReminders();
                break;
            case 'process_tickets_with_ai':
                $this->cronProcessTicketsWithAi();
                break;
            default:
                break;
        }
    }

    /**
     * Performs the close tickets action
     */
    private function cronCloseTickets()
    {
        // Fetch all departments
        Loader::loadModels($this, ['SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerTickets']);
        $departments = $this->SupportManagerDepartments->getAll(Configure::get('Blesta.company_id'));

        foreach ($departments as $department) {
            $this->SupportManagerTickets->closeAllByDepartment($department->id);
        }
    }

    /**
     * Performs the delete tickets action
     */
    private function cronDeleteTickets()
    {
        // Fetch all departments
        Loader::loadModels($this, ['SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerTickets']);
        $departments = $this->SupportManagerDepartments->getAll(Configure::get('Blesta.company_id'));

        foreach ($departments as $department) {
            $this->SupportManagerTickets->deleteAllByDepartment($department->id);
        }

        // Clean up orphaned inline image temp files
        $this->SupportManagerTickets->cleanupOrphanedInlineImageTemps(24);
    }

    /**
     * Sends the ticket feedback reminders
     */
    private function sendFeedbackReminders()
    {
        // Fetch all departments
        Loader::loadModels($this, ['SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerTickets']);
        $departments = $this->SupportManagerDepartments->getAll(Configure::get('Blesta.company_id'));

        foreach ($departments as $department) {
            $this->SupportManagerTickets->notifyFeedbackAllByDepartment($department->id);
        }
    }

    /**
     * Sends the ticket reminders
     */
    private function sendReminders()
    {
        // Fetch all departments
        Loader::loadModels($this, ['SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerTickets']);
        $departments = $this->SupportManagerDepartments->getAll(Configure::get('Blesta.company_id'));

        foreach ($departments as $department) {
            $this->SupportManagerTickets->notifyAllByDepartment($department->id);
        }
    }

    /**
     * Processes queued ticket replies with AI - generates responses and executes tool uses
     *
     * Processes client replies that have been flagged for AI analysis (ai_queued=1).
     * Batches multiple rapid replies together for efficient processing.
     */
    private function cronProcessTicketsWithAi()
    {
        // Load required models
        Loader::loadModels($this, [
            'SupportManager.SupportManagerTickets',
            'SupportManager.SupportManagerDepartments',
            'SupportManager.SupportManagerSettings',
            'SupportManager.SupportManagerAiResponseAnalyses',
            'SupportManager.SupportManagerAiToolAnalyses',
            'SupportManager.SupportManagerAiToolUses'
        ]);

        // Load AI helper
        Loader::load(PLUGINDIR . 'support_manager' . DS . 'lib' . DS . 'support_manager_ai_helper.php');

        // Get current company ID
        $company_id = Configure::get('Blesta.company_id');

        // Check if AI is enabled for this company
        $ai_enabled = $this->SupportManagerSettings->getSetting('sm_ai_enabled', $company_id);
        if (!$ai_enabled || $ai_enabled->value !== 'true') {
            return; // AI not enabled, skip
        }

        // Get rate limiting settings
        $batch_size = $this->SupportManagerSettings->getSetting('sm_ai_cron_batch_size', $company_id);
        $max_batch_size = $batch_size ? (int)$batch_size->value : 50;

        $max_calls = $this->SupportManagerSettings->getSetting('sm_ai_cron_max_calls', $company_id);
        $max_api_calls = $max_calls ? (int)$max_calls->value : 100;

        // Get max queue age in hours (default 24) — replies older than this are
        // considered stale and will be discarded rather than processed. Prevents
        // processing a large backlog if the cron has been disabled for some time.
        $max_age = $this->SupportManagerSettings->getSetting('sm_ai_max_queue_age_hours', $company_id);
        $max_age_hours = ($max_age && $max_age->value !== '') ? (int)$max_age->value : 24;
        // Defensive fallback: invalid values would cause destructive cleanup
        if ($max_age_hours < 1 || $max_age_hours > 8760) {
            $max_age_hours = 24;
        }
        $stale_cutoff = date('Y-m-d H:i:s', strtotime('-' . $max_age_hours . ' hours'));

        // Discard any stale queued replies before processing
        $stale_tickets = $this->Record->select(['ticket_id'])
            ->from('support_replies')
            ->where('ai_queued', '=', 1)
            ->where('staff_id', '=', null)
            ->where('date_added', '<', $stale_cutoff)
            ->group(['ticket_id'])
            ->fetchAll();

        if (!empty($stale_tickets)) {
            $this->Record->where('ai_queued', '=', 1)
                ->where('staff_id', '=', null)
                ->where('date_added', '<', $stale_cutoff)
                ->update('support_replies', ['ai_queued' => 0]);

            foreach ($stale_tickets as $stale) {
                $this->SupportManagerAiResponseAnalyses->expirePendingForTicket($stale->ticket_id);
            }
            echo 'Discarded stale AI queue entries from ' . count($stale_tickets) . " ticket(s)\n";
        }

        // Initialize counters
        $tickets_processed = 0;
        $replies_processed = 0;
        $api_calls_made = 0;
        $responses_generated = 0;
        $tools_executed = 0;

        // Get department restrictions (fetch once before loop to avoid N+1 query)
        $ai_departments = $this->SupportManagerSettings->getSetting('sm_ai_auto_reply_departments', $company_id);
        $allowed_departments = [];
        if ($ai_departments && $ai_departments->value) {
            $allowed_departments = json_decode($ai_departments->value, true);
            if (!is_array($allowed_departments)) {
                $allowed_departments = [];
            }
        }

        // Query for queued replies grouped by ticket
        $queued_reply_groups = $this->Record->select(['ticket_id', 'MIN(id)' => 'oldest_reply_id', 'MIN(date_added)' => 'oldest_date', 'COUNT(*)' => 'reply_count'])
            ->from('support_replies')
            ->where('ai_queued', '=', 1)
            ->where('staff_id', '=', null) // Client replies only
            ->group(['ticket_id'])
            ->order(['oldest_date' => 'asc'])
            ->limit($max_batch_size)
            ->fetchAll();

        foreach ($queued_reply_groups as $queue_group) {
            // Check rate limits
            if ($tickets_processed >= $max_batch_size || $api_calls_made >= $max_api_calls) {
                break;
            }

            // Get full ticket to check department restrictions and status
            $ticket = $this->SupportManagerTickets->get($queue_group->ticket_id);
            if (!$ticket) {
                continue; // Invalid ticket
            }

            // Skip closed tickets — de-queue their pending replies
            if ($ticket->status === 'closed') {
                $this->Record->where('ticket_id', '=', $queue_group->ticket_id)
                    ->where('ai_queued', '=', 1)
                    ->update('support_replies', ['ai_queued' => 0]);
                if (!isset($this->SupportManagerAiResponseAnalyses)) {
                    Loader::loadModels($this, ['SupportManager.SupportManagerAiResponseAnalyses']);
                }
                $this->SupportManagerAiResponseAnalyses->expirePendingForTicket($queue_group->ticket_id);
                echo "Ticket #{$ticket->code} is closed, skipping AI processing\n";
                continue;
            }

            // Check if AI is enabled for this department
            if (!empty($allowed_departments) && !in_array($ticket->department_id, $allowed_departments)) {
                continue; // Skip this ticket's department
            }

            // Get ALL queued replies for this ticket
            $batched_replies = $this->Record->select()
                ->from('support_replies')
                ->where('ticket_id', '=', $queue_group->ticket_id)
                ->where('ai_queued', '=', 1)
                ->where('staff_id', '=', null)
                ->order(['date_added' => 'asc'])
                ->fetchAll();

            if (empty($batched_replies)) {
                continue; // No replies to process
            }

            $reply_ids = array_map(function ($r) {
                return $r->id;
            }, $batched_replies);

            // Atomically claim replies (race condition prevention)
            $this->Record->where('id', 'in', $reply_ids)
                ->where('ai_queued', '=', 1)
                ->update('support_replies', ['ai_queued' => 0]);

            if ($this->Record->affectedRows() === 0) {
                // Another process claimed them
                echo "Ticket #{$ticket->code} replies already being processed, skipping\n";
                continue;
            }

            // Check if a staff member has already replied after the queued client messages
            // Compare by reply ID (auto-increment) rather than date_added to avoid
            // same-second timestamp ties incorrectly suppressing AI processing
            $oldest_queued_reply_id = $batched_replies[0]->id;
            $staff_reply_after = $this->Record->select(['id'])
                ->from('support_replies')
                ->where('ticket_id', '=', $queue_group->ticket_id)
                ->where('staff_id', '!=', null)
                ->where('type', '=', 'reply')
                ->where('id', '>', $oldest_queued_reply_id)
                ->fetch();

            if ($staff_reply_after) {
                echo "Ticket #{$ticket->code} already has a staff reply, skipping AI processing\n";
                // Expire any pending AI response analyses for this ticket
                if (!isset($this->SupportManagerAiResponseAnalyses)) {
                    Loader::loadModels($this, ['SupportManager.SupportManagerAiResponseAnalyses']);
                }
                $this->SupportManagerAiResponseAnalyses->expirePendingForTicket($queue_group->ticket_id);
                continue;
            }

            echo "Processing ticket #{$ticket->code} (" . count($batched_replies) . " batched replies)...";
            $replies_processed += count($batched_replies);

            // Initialize AI helper
            try {
                $ai_helper = new SupportManagerAiHelper($company_id);

                // Step 1: Analyze for tool use (FIRST API CALL)
                $tool_result = $ai_helper->generateToolAnalysisForReplies(
                    $reply_ids,
                    $ticket,
                    $batched_replies,
                    ['save_to_db' => true]
                );
                $api_calls_made++; // Count the tool analysis call

                $tool_analysis_id = $tool_result['analysis_id'];
                $tool_calls = $tool_result['tool_calls'];

                // Step 2: Generate customer response (SECOND API CALL)
                $response_analysis_id = $ai_helper->generateResponseForReplies(
                    $reply_ids,
                    $ticket,
                    $batched_replies,
                    ['save_to_db' => true]
                );

                if ($response_analysis_id) {
                    $api_calls_made++; // Count the response generation call
                }

                // Step 3: Link analyses to ALL batched replies
                $this->Record->where('id', 'in', $reply_ids)
                    ->update('support_replies', [
                        'ai_response_id' => $response_analysis_id,
                        'ai_tool_analysis_id' => $tool_analysis_id
                    ]);

                $tickets_processed++;

                // Step 4: Handle auto-reply logic (before tool execution so replies
                // are posted before destructive actions like close_ticket)
                $analysis = null;
                if ($response_analysis_id) {
                    $analysis = $ai_helper->SupportManagerAiResponseAnalyses->get($response_analysis_id);
                }

                // Re-check for staff replies and ticket status before auto-posting
                // or tool execution (covers changes that occurred while API calls
                // were in progress)
                $staff_replied_since = $this->Record->select(['id'])
                    ->from('support_replies')
                    ->where('ticket_id', '=', $ticket->id)
                    ->where('staff_id', '!=', null)
                    ->where('type', '=', 'reply')
                    ->where('id', '>', $oldest_queued_reply_id)
                    ->fetch();

                $fresh_ticket = $this->SupportManagerTickets->get($ticket->id);
                $ticket_closed_since = $fresh_ticket && $fresh_ticket->status === 'closed';

                $skip_actions = $staff_replied_since || $ticket_closed_since;

                // Expire the just-generated analysis if we're skipping actions
                if ($skip_actions && $response_analysis_id) {
                    $ai_helper->SupportManagerAiResponseAnalyses->markExpired($response_analysis_id);
                }

                if ($analysis && $analysis->response_text && $analysis->status !== 'no_response_needed') {
                    $responses_generated++;
                    echo " success\n";

                    // Check if auto-reply is enabled
                    $auto_reply_enabled = $this->SupportManagerSettings->getSetting(
                        'sm_ai_auto_reply_enabled',
                        $company_id
                    );

                    $require_review = $this->SupportManagerSettings->getSetting(
                        'sm_ai_require_human_review',
                        $company_id
                    );

                    $confidence_threshold = $this->SupportManagerSettings->getSetting(
                        'sm_ai_confidence_threshold',
                        $company_id
                    );
                    $threshold = $confidence_threshold ? (int)$confidence_threshold->value : 90;

                    // Auto-reply if conditions are met
                    if (!$skip_actions
                        && $auto_reply_enabled
                        && $auto_reply_enabled->value === 'true'
                        && (!$require_review || $require_review->value === 'false')
                        && isset($analysis->confidence)
                        && ((int) $analysis->confidence >= (int) $threshold)
                    ) {
                        // Post the reply automatically
                        $reply_data = [
                            'staff_id' => 0, // AI assistant (staff_id 0)
                            'type' => 'reply',
                            'details' => $analysis->response_text,
                            'date_added' => date('Y-m-d H:i:s'),
                            'is_ai_generated' => 1  // Mark as AI-generated
                        ];

                        // Add AI disclaimer if enabled
                        $show_disclaimer = $this->SupportManagerSettings->getSetting(
                            'sm_ai_show_disclaimer',
                            $company_id
                        );

                        if ($show_disclaimer && $show_disclaimer->value === 'true') {
                            $custom_disclaimer = $this->SupportManagerSettings->getSetting(
                                'sm_ai_custom_disclaimer',
                                $company_id
                            );

                            if ($custom_disclaimer && $custom_disclaimer->value) {
                                $disclaimer = $custom_disclaimer->value;
                            } else {
                                $disclaimer = Language::_('SupportManagerPlugin.ai.default_disclaimer', true);
                            }

                            $reply_data['details'] = $reply_data['details'] . "\n\n" . $disclaimer;
                        }

                        // Add the reply
                        $reply_id = $this->SupportManagerTickets->addReply(
                            $ticket->id,
                            $reply_data
                        );

                        if ($reply_id) {
                            // Transition ticket status if department has automatic_transition enabled
                            if ($ticket->status === 'open') {
                                $department = $this->SupportManagerDepartments->get($ticket->department_id);
                                if ($department && $department->automatic_transition == '1') {
                                    $this->SupportManagerTickets->edit($ticket->id, [
                                        'status' => 'awaiting_reply'
                                    ], false);
                                }
                            }

                            // Mark AI response analysis as used (staff_id 0 = AI assistant)
                            $ai_helper->SupportManagerAiResponseAnalyses->markUsed(
                                $response_analysis_id,
                                0
                            );

                            // Log the auto-reply tool use
                            $this->SupportManagerAiToolUses->add([
                                'ticket_id' => $ticket->id,
                                'tool_analysis_id' => $response_analysis_id,
                                'tool_name' => 'auto_reply',
                                'arguments' => json_encode([
                                    'confidence' => $analysis->confidence,
                                    'threshold' => $threshold
                                ]),
                                'result' => json_encode([
                                    'success' => true,
                                    'reply_id' => $reply_id
                                ]),
                                'confidence' => $analysis->confidence,
                                'executed_at' => date('Y-m-d H:i:s'),
                                'executed_by' => 'ai_cron'
                            ]);
                        }
                    }
                } else {
                    echo " (no response needed)\n";
                }

                // Step 5: Execute tools if any were suggested (after reply is posted
                // so close_ticket doesn't prevent the reply from being sent)
                // Skip tool execution if a staff member has replied in the meantime
                $execution_summary = null;
                if (!empty($tool_calls) && !$skip_actions) {
                    echo " - Executing " . count($tool_calls) . " tool(s)...";

                    $execution_summary = $ai_helper->executeToolUses(
                        $ticket->id,
                        $tool_calls,
                        50, // overall confidence (no longer stored in tool analysis)
                        $tool_analysis_id
                    );

                    // Update tool analysis with execution stats
                    if ($execution_summary) {
                        $this->SupportManagerAiToolAnalyses->markExecuted($tool_analysis_id, [
                            'execution_status' => 'completed',
                            'tools_executed_count' => count($execution_summary['executed']),
                            'tools_skipped_count' => count($execution_summary['skipped']),
                            'tools_failed_count' => count($execution_summary['failed'])
                        ]);
                    }

                    // Log execution results
                    if (!empty($execution_summary['executed'])) {
                        $tools_executed += count($execution_summary['executed']);
                        echo " executed: " . count($execution_summary['executed']);
                    }
                    if (!empty($execution_summary['skipped'])) {
                        echo " skipped: " . count($execution_summary['skipped']);
                    }
                    if (!empty($execution_summary['failed'])) {
                        echo " failed: " . count($execution_summary['failed']);
                        $this->logger->warning('Support Manager AI: Some tool executions failed for ticket #' . $ticket->code);
                    }
                }

            } catch (Exception $e) {
                // Re-queue the replies on error
                $this->Record->where('id', 'in', $reply_ids)
                    ->update('support_replies', ['ai_queued' => 1]);

                // Log error and continue to next ticket
                echo " error\n";
                $this->logger->error('Support Manager AI: Error processing ticket #' . $ticket->code . ': ' . $e->getMessage());
                continue;
            }
        }

        // Output summary
        echo "\nAI cron task completed: {$tickets_processed} tickets processed ({$replies_processed} replies), {$responses_generated} responses generated, {$tools_executed} tools executed\n";
    }

    /**
     * Retrieves cron tasks available to this plugin along with their default values
     *
     * @return array A list of cron tasks
     */
    private function getCronTasks()
    {
        return [
            // Cron task to check for incoming email tickets
            [
                'key' => 'poll_tickets',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.poll_tickets_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.poll_tickets_desc', true),
                'type' => 'interval',
                'type_value' => 5,
                'enabled' => 1
            ],
            // Cron task to auto-close tickets
            [
                'key' => 'close_tickets',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.close_tickets_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.close_tickets_desc', true),
                'type' => 'interval',
                'type_value' => 360, // 6 hours
                'enabled' => 1
            ],
            // Cron task to auto-deletes tickets
            [
                'key' => 'delete_tickets',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.delete_tickets_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.delete_tickets_desc', true),
                'type' => 'interval',
                'type_value' => 360, // 6 hours
                'enabled' => 1
            ],
            // Cron task to send feedback reminders
            [
                'key' => 'send_feedback_reminders',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.send_feedback_reminders_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.send_feedback_reminders_desc', true),
                'type' => 'interval',
                'type_value' => 5,
                'enabled' => 1
            ],
            // Cron task to send ticket reminders
            [
                'key' => 'send_reminders',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.send_reminders_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.send_reminders_desc', true),
                'type' => 'interval',
                'type_value' => 5,
                'enabled' => 1
            ],
            // Cron task to process tickets with AI (responses and tool execution)
            [
                'key' => 'process_tickets_with_ai',
                'task_type' => 'plugin',
                'dir' => 'support_manager',
                'name' => Language::_('SupportManagerPlugin.cron.process_tickets_with_ai_name', true),
                'description' => Language::_('SupportManagerPlugin.cron.process_tickets_with_ai_desc', true),
                'type' => 'interval',
                'type_value' => 15,
                'enabled' => 1
            ]
        ];
    }

    /**
     * Attempts to add new cron tasks for this plugin
     *
     * @param array $tasks A list of cron tasks to add
     * @see SupportManagerPlugin::install(), SupportManagerPlugin::upgrade(), SupportManagerPlugin::getCronTasks()
     */
    private function addCronTasks(array $tasks)
    {
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = ['enabled' => $task['enabled']];
                if ($task['type'] == 'interval') {
                    $task_vars['interval'] = $task['type_value'];
                } else {
                    $task_vars['time'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }
}
