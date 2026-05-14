<?php
/**
 * Upgrades to version 5.9.1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_9_1 extends UpgradeUtil
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
            'updateContactTagsEmailTemplates',
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
     * Updates the contact tags in the "reset password" email template
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateContactTagsEmailTemplates($undo = false)
    {
        $email_templates = [
            'reset_password', 'staff_reset_password', 'forgot_username'
        ];
        $tags = [
            'contact.first_name', 'contact.last_name', 'staff.first_name', 'staff.last_name'
        ];

        if ($undo) {
            foreach ($email_templates as $template) {
                foreach ($tags as $tag) {
                    $this->Record->query(
                        'UPDATE emails
                        INNER JOIN `email_groups` ON `email_groups`.`id` = `emails`.`email_group_id`
                        SET text = REPLACE(text , \'{' . $tag . ' | escape}\', \'{' . $tag . '}\') WHERE `email_groups`.`action` = \'' . $template . '\';'
                    );
                    $this->Record->query(
                        'UPDATE emails
                        INNER JOIN `email_groups` ON `email_groups`.`id` = `emails`.`email_group_id`
                        SET html = REPLACE(html , \'{' . $tag . ' | escape}\', \'{' . $tag . '}\') WHERE `email_groups`.`action` = \'' . $template . '\';'
                    );
                }
            }
        } else {
            foreach ($email_templates as $template) {
                foreach ($tags as $tag) {
                    $this->Record->query(
                        'UPDATE emails
                        INNER JOIN `email_groups` ON `email_groups`.`id` = `emails`.`email_group_id`
                        SET text = REPLACE(text , \'{' . $tag . '}\', \'{' . $tag . ' | escape}\') WHERE `email_groups`.`action` = \'' . $template . '\';'
                    );
                    $this->Record->query(
                        'UPDATE emails
                        INNER JOIN `email_groups` ON `email_groups`.`id` = `emails`.`email_group_id`
                        SET html = REPLACE(html , \'{' . $tag . '}\', \'{' . $tag . ' | escape}\') WHERE `email_groups`.`action` = \'' . $template . '\';'
                    );
                }
            }
        }
    }
}
