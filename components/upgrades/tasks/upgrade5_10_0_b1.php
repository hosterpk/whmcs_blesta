<?php
/**
 * Upgrades to version 5.10.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_10_0B1 extends UpgradeUtil
{
    /**
     * @var array An array of all tasks completed
     */
    private $tasks = [];

    /**
     * Setup
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Returns a numerically indexed array of tasks to execute for the upgrade process
     *
     * @return array A numerically indexed array of tasks to execute for the upgrade process
     */
    public function tasks()
    {
        return [
            'updateEmailTemplatesHttps',
            'addServicesDataFeed',
            'addPackagePricingDefault',
            'addEmailAttachmentsTable',
            'createTemporaryUploadPath',
            'updateEmailTemplatesTags',
            'addPasswordResetsTable',
            'addPasswordResetsCron',
            'addEmailAttachmentDeletionPermission',
        ];
    }

    /**
     * Processes the given task
     *
     * @param string $task The task to process
     */
    public function process($task)
    {
        $tasks = $this->tasks();

        // Ensure task exists
        if (!in_array($task, $tasks)) {
            return;
        }

        $this->tasks[] = $task;
        $this->{$task}();
    }

    /**
     * Rolls back all tasks completed for the upgrade process
     */
    public function rollback()
    {
        // Undo all tasks
        while (($task = array_pop($this->tasks))) {
            $this->{$task}(true);
        }
    }

    /**
     * Updates the links on all email templates from http to https
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateEmailTemplatesHttps($undo = false)
    {
        // Run task only during installation
        if ($this->getEnvironment() !== 'install') {
            return;
        }

        if ($undo) {
            $this->Record->query(
                'UPDATE emails SET text = REPLACE(text , \'https://\', \'http://\') WHERE text LIKE (\'%https://%\');'
            );
            $this->Record->query(
                'UPDATE emails SET html = REPLACE(html , \'https://\', \'http://\') WHERE html LIKE (\'%https://%\');'
            );
        } else {
            $this->Record->query(
                'UPDATE emails SET text = REPLACE(text , \'http://\', \'https://\') WHERE text LIKE (\'%http://%\');'
            );
            $this->Record->query(
                'UPDATE emails SET html = REPLACE(html , \'http://\', \'https://\') WHERE html LIKE (\'%http://%\');'
            );
        }
    }

    /**
     * Adds the data feed for services
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addServicesDataFeed($undo = false)
    {
        Loader::loadModels($this, ['Companies']);

        $feed = [
            'feed' => 'service',
            'class' => '\\Blesta\\Core\\Util\\DataFeed\\Feeds\\ServiceFeed',
            'endpoints' => [
                ['feed' => 'service', 'endpoint' => 'count']
            ]
        ];

        if ($undo) {
            // Delete service data feed
            $this->Record->from('data_feeds')->where('feed', '=', $feed['feed'])->delete();
            $this->Record->from('data_feed_endpoints')->where('feed', '=', $feed['feed'])->delete();
        } else {
            // Add service data feed
            $endpoints = $feed['endpoints'];
            unset($feed['endpoints']);

            $this->Record->insert('data_feeds', $feed);
            // Fetch all companies
            $companies = $this->Companies->getAll();
            foreach ($endpoints as $endpoint) {
                foreach ($companies as $company) {
                    $endpoint['company_id'] = $company->id;
                    $this->Record->insert('data_feed_endpoints', $endpoint);
                }
            }
        }
    }

    /**
     * Adds the new "default" column to the package_pricing table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPackagePricingDefault($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `package_pricing` DROP `default`;');
        } else {
            $this->Record->query("ALTER TABLE `package_pricing` ADD `default` TINYINT(1) NOT NULL DEFAULT '0' ;");
        }
    }

    /**
     * Adds a new "email_attachments" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addEmailAttachmentsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('email_attachments');
        } else {
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('email_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('name', ['type'=>'varchar', 'size'=>255])->
                setField('file_name', ['type'=>'varchar', 'size'=>255])->
                setKey(['id'], 'primary')->
                setKey(['email_id'], 'index')->
                create('email_attachments', true);
        }
    }

    /**
     * Creates a new "tmp" path under the uploads folder
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function createTemporaryUploadPath($undo = false)
    {
        Loader::loadComponents($this, ['Upload', 'SettingsCollection']);

        // Set the uploads directory
        $uploads_dir = $this->SettingsCollection->fetchSetting(
            null,
            Configure::get('Blesta.company_id'),
            'uploads_dir'
        );
        $uploads_dir = $uploads_dir['value'] ?? '';

        if ($undo) {
            // Nothing to do
        } else {
            @$this->Upload->createUploadPath(
                $uploads_dir . Configure::get('Blesta.company_id') . DS . 'tmp' . DS
            );
        }
    }

    /**
     * Updates the email templates, escaping the tags that not expects HTML code
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateEmailTemplatesTags($undo = false)
    {
        // Run task only during installation
        if ($this->getEnvironment() !== 'install') {
            return;
        }

        $parser_options = Configure::get('Blesta.parser_options');
        if (empty($parser_options)) {
            $parser_options = [
                'VARIABLE_START' => '{',
                'VARIABLE_END' => '}'
            ];
        }

        $fields = ['subject', 'text', 'html'];
        $html_tags = [
            'package.email_html',
            'ticket.details_html'
        ];

        if ($undo) {
            foreach ($fields as $field) {
                $this->Record->query(
                    'UPDATE emails SET ' . $field . ' = REPLACE(' . $field . ' , \' | e' . $parser_options['VARIABLE_END'] . '\', \'' . $parser_options['VARIABLE_END'] . '\') WHERE ' . $field . ' LIKE (\'%' . $parser_options['VARIABLE_START'] . '%' . $parser_options['VARIABLE_END'] . '%\');'
                );
            }
        } else {
            foreach ($fields as $field) {
                // Add the escape filter to all tags
                $this->Record->query(
                    'UPDATE emails SET ' . $field . ' = REPLACE(' . $field . ' , \'' . $parser_options['VARIABLE_END'] . '\', \' | e' . $parser_options['VARIABLE_END'] . '\') WHERE ' . $field . ' LIKE (\'%' . $parser_options['VARIABLE_START'] . '%' . $parser_options['VARIABLE_END'] . '%\');'
                );

                // Revert the change for logic tags
                $this->Record->query(
                    'UPDATE emails SET ' . $field . ' = REPLACE(' . $field . ' , \'% | e\', \'%\') WHERE ' . $field . ' LIKE (\'%' . $parser_options['VARIABLE_START'] . '%' . $parser_options['VARIABLE_END'] . '%\');'
                );

                // Revert the change for html tags
                foreach ($html_tags as $html_tag) {
                    $this->Record->query(
                        'UPDATE emails SET ' . $field . ' = REPLACE(' . $field . ' , \'' . $parser_options['VARIABLE_START'] . $html_tag . ' | e' . $parser_options['VARIABLE_END'] . '\', \'' . $parser_options['VARIABLE_START'] . $html_tag . $parser_options['VARIABLE_END'] . '\') WHERE ' . $field . ' LIKE (\'%' . $html_tag . '%\');'
                    );
                }
            }
        }
    }

    /**
     * Adds a new "password_resets" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPasswordResetsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('password_resets');
        } else {
            $this->Record->
                setField('user_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('email', ['type'=>'varchar', 'size'=>255])->
                setField('token', ['type'=>'varchar', 'size'=>255])->
                setField('date_expires', ['type' => 'datetime'])->
                setKey(['token'], 'primary')->
                setKey(['email'], 'index')->
                create('password_resets', true);
        }
    }

    /**
     * Adds a new "delete_expired_password_reset_tokens" cron task
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPasswordResetsCron($undo = false)
    {
        Loader::loadModels($this, ['Companies', 'CronTasks']);

        if ($undo) {
            // Remove cron task
            $cron = $this->CronTasks->getByKey('delete_expired_password_reset_tokens', null, 'system');

            $this->Record->from('cron_task_runs')
                ->innerJoin('cron_tasks', 'cron_tasks.id', '=', 'cron_task_runs.task_id', false)
                ->where('cron_tasks.task_type', '=', 'system')
                ->where('cron_task_runs.task_id', '=', $cron->id)
                ->delete(['cron_task_runs.*']);

            $this->Record->from('cron_tasks')
                ->where('id', '=', $cron->id)
                ->delete();
        } else {
            // Add cron task
            $task_id = $this->CronTasks->add([
                'key' => 'delete_expired_password_reset_tokens',
                'task_type' => 'system',
                'name' => 'CronTasks.crontask.name.delete_expired_password_reset_tokens',
                'description' => 'CronTasks.crontask.description.delete_expired_password_reset_tokens',
                'is_lang' => 1,
                'type' => 'interval'
            ]);

            if ($task_id) {
                $companies = $this->Companies->getAll();
                foreach ($companies as $company) {
                    // Add cron task run for the company
                    $vars = [
                        'task_id' => $task_id,
                        'company_id' => $company->id,
                        'time' => null,
                        'interval' => '15',
                        'enabled' => 1,
                        'date_enabled' => $this->Companies->dateToUtc(date('c'))
                    ];

                    $this->Record->insert('cron_task_runs', $vars);
                }
            }
        }
    }

    /**
     * Adds a permission for package deletion
     *
     * @param bool $undo Whether to add or undo the change
     */
    private function addEmailAttachmentDeletionPermission($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            // Get the package permission group
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);
            $group = $this->Permissions->getGroupByAlias('admin_settings');

            // Add the new package deletion permission
            if ($group) {
                $this->Permissions->add([
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_company_emails_deleteattachment',
                    'alias' => 'admin_company_emails',
                    'action' => 'deleteattachment'
                ]);

                $staff_groups = $this->Record->select('id')->from('staff_groups')->fetchAll();
                foreach ($staff_groups as $staff_group) {
                    $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_company_emails', 'deleteattachment');
                }
            }
        }
    }
}
