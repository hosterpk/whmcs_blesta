<?php

namespace Blesta\Core\Automation\Tasks\Task;

use Blesta\Core\Automation\Tasks\Common\AbstractTask;
use Blesta\Core\Automation\Type\Common\AutomationTypeInterface;
use Configure;
use Language;
use Loader;
use stdClass;

/**
 * The low balance notification automation task
 *
 * @package blesta
 * @subpackage core.Automation.Tasks.Task
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class LowBalanceNotifications extends AbstractTask
{
    /**
     * Initialize a new task
     *
     * @param AutomationTypeInterface $task The raw automation task
     * @param array $options An additional options necessary for the task:
     *
     *  - print_log True to print logged content to stdout, or false otherwise (default false)
     *  - cli True if this is being run via the Command-Line Interface, or false otherwise (default true)
     *  - client_uri The URI of the client interface
     */
    public function __construct(AutomationTypeInterface $task, array $options = [])
    {
        parent::__construct($task, $options);

        Loader::loadModels($this, ['Clients', 'Contacts', 'Emails', 'Transactions']);
        Loader::loadHelpers($this, ['Html', 'CurrencyFormat']);
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

        // Log the task has begun
        $this->log(Language::_('Automation.task.low_balance_notifications.attempt', true));

        // Execute the low balance notifications cron task
        $this->process($this->task->raw());

        // Log the task has completed
        $this->log(Language::_('Automation.task.low_balance_notifications.completed', true));
        $this->logComplete();
    }

    /**
     * Processes the task
     *
     * @param stdClass $data The low balance notifications task data
     */
    private function process(stdClass $data)
    {
        // Get all clients for this company with credit enabled
        $clients = $this->Clients->getAll($data->company_id, null, true);

        // Get the company hostname
        $hostname = Configure::get('Blesta.company')->hostname ?? '';

        foreach ($clients as $client) {
            // Skip if credit is not enabled for this client
            if (($client->settings['payment_credit_enabled'] ?? 'false') == 'false') {
                continue;
            }

            // Get credit balance thresholds
            $thresholds = [];
            if (!empty($client->settings['credit_balance_thresholds'])) {
                $thresholds = json_decode($client->settings['credit_balance_thresholds'], true);
            }

            // Skip if no thresholds configured
            if (empty($thresholds)) {
                continue;
            }

            // Get notification sent status
            $notifications_sent = [];
            if (!empty($client->settings['credit_balance_notifications_sent'])) {
                $notifications_sent = json_decode($client->settings['credit_balance_notifications_sent'], true);
            }

            $notifications_updated = false;

            // Check balance for each currency with a threshold
            foreach ($thresholds as $currency => $threshold) {
                $balance = $this->Transactions->getTotalCredit($client->id, $currency);
                $already_sent = !empty($notifications_sent[$currency]);

                // Send notification if balance is below threshold and not already sent
                if ($balance < $threshold && !$already_sent) {
                    $contact = $this->Contacts->get($client->contact_id);

                    $tags = [
                        'contact' => $contact,
                        'client' => $client,
                        'balance' => $this->CurrencyFormat->format($balance, $currency),
                        'threshold' => $this->CurrencyFormat->format($threshold, $currency),
                        'currency' => $currency,
                        'client_url' => $this->Html->safe($hostname . $this->options['client_uri'])
                    ];

                    $this->Emails->send(
                        'low_balance_notification',
                        $data->company_id,
                        $client->settings['language'],
                        $contact->email,
                        $tags,
                        null,
                        null,
                        null,
                        ['to_client_id' => $client->id]
                    );

                    // Log success/error
                    if (($errors = $this->Emails->errors())) {
                        $this->log(
                            Language::_(
                                'Automation.task.low_balance_notifications.failed',
                                true,
                                $contact->first_name,
                                $contact->last_name,
                                $client->id_code
                            )
                        );

                        // Reset errors
                        $this->resetErrors($this->Emails);
                    } else {
                        $this->log(
                            Language::_(
                                'Automation.task.low_balance_notifications.success',
                                true,
                                $contact->first_name,
                                $contact->last_name,
                                $client->id_code,
                                $currency
                            )
                        );

                        // Mark notification as sent
                        $notifications_sent[$currency] = true;
                        $notifications_updated = true;
                    }
                } elseif ($balance >= $threshold && $already_sent) {
                    // Reset notification flag when balance is restored
                    $notifications_sent[$currency] = false;
                    $notifications_updated = true;
                }
            }

            // Update notification status if changed
            if ($notifications_updated) {
                $this->Clients->setSetting(
                    $client->id,
                    'credit_balance_notifications_sent',
                    json_encode($notifications_sent)
                );
            }
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
