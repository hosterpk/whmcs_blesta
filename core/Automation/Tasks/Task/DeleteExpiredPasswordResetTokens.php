<?php

namespace Blesta\Core\Automation\Tasks\Task;

use Blesta\Core\Automation\Tasks\Common\AbstractTask;
use Blesta\Core\Automation\Type\Common\AutomationTypeInterface;
use Language;
use Loader;

/**
 * The delete_expired_password_reset_tokens automation task
 *
 * @package blesta
 * @subpackage core.Automation.Tasks.Task
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class DeleteExpiredPasswordResetTokens extends AbstractTask
{
    /**
     * @var int The ID of the company this task is being processed for
     */
    private $companyId;

    /**
     * Initialize a new task
     *
     * @param AutomationTypeInterface $task The raw automation task
     * @param array $options An additional options necessary for the task:
     *
     *  - print_log True to print logged content to stdout, or false otherwise (default false)
     *  - cli True if this is being run via the Command-Line Interface, or false otherwise (default true)
     */
    public function __construct(AutomationTypeInterface $task, array $options = [])
    {
        parent::__construct($task, $options);

        Loader::loadModels($this, ['Companies', 'Clients', 'Invoices']);
        Loader::loadComponents($this, ['Record', 'SettingsCollection']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // This task cannot be run right now
        if (!$this->isTimeToRun()) {
            return;
        }

        $data = $this->task->raw();
        $this->companyId = $data->company_id;

        // Log the task has begun
        $this->log(Language::_('Automation.task.delete_expired_password_reset_tokens.attempt', true));

        // Execute the delete_expired_password_reset_tokens cron task
        $this->process();

        // Log the task has completed
        $this->log(Language::_('Automation.task.delete_expired_password_reset_tokens.completed', true));
        $this->logComplete();
    }

    /**
     * Processes the task
     */
    private function process()
    {
        // Delete expired tokens
        $this->deleteExpiredTokens();
    }

    /**
     * Delete all expired reset password tokens
     */
    private function deleteExpiredTokens()
    {
        Loader::loadModels($this, ['PasswordResets']);

        $tokens = $this->PasswordResets->getExpired();
        foreach ($tokens ?? [] as $token) {
            $this->PasswordResets->deleteByHash($token->token);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function isTimeToRun()
    {
        return $this->task->canRun(date('c'));
    }
}
