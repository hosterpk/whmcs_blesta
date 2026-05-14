<?php
/**
 * Upgrades to version 5.11.4
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_11_4 extends UpgradeUtil
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
            'revertHtmlEmailTemplatesTags',
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
     * Updates the email templates, escaping the tags that not expects HTML code
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function revertHtmlEmailTemplatesTags($undo = false)
    {
        $parser_options = Configure::get('Blesta.parser_options');
        if (empty($parser_options)) {
            $parser_options = [
                'VARIABLE_START' => '{',
                'VARIABLE_END' => '}'
            ];
        }

        $html_tags = [
            'package.email_html',
            'ticket.details_html'
        ];

        if ($undo) {
            // Nothing to do
        } else {
            // Revert the change for html tags
            foreach ($html_tags as $html_tag) {
                $html_tag = $parser_options['VARIABLE_START'] . $html_tag . ' | e' . $parser_options['VARIABLE_END'];
                $new_html_tag = $parser_options['VARIABLE_START'] . $html_tag . $parser_options['VARIABLE_END'];

                $this->Record->query(
                    'UPDATE emails SET html = REPLACE(html , \'' . $html_tag . '\', \'' . $new_html_tag . '\') WHERE html LIKE (\'%' . $html_tag . '%\');'
                );
            }
        }
    }
}
