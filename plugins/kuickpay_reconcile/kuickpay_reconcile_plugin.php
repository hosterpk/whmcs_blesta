<?php
/**
 * KuickPay Reconcile plugin handler
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickpayReconcilePlugin extends Plugin
{
    /**
     * Init
     */
    public function __construct()
    {
        Language::loadLang('kuickpay_reconcile_plugin', null, dirname(__FILE__) . DS . 'language' . DS);

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    /**
     * Performs install actions.
     *
     * Creates the durable voucher evidence tables used by KuickPay customer
     * payment references and reconciliation.
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Input', 'Record']);
        }

        try {
            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('gateway_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('client_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('currency', ['type'=>'varchar', 'size'=>3])
                ->setField('amount', ['type'=>'varchar', 'size'=>20])
                ->setField('status', [
                    'type'=>'enum',
                    'size'=>"'pending','retry','confirmed_unposted','posted','failed','expired','manual_review','cancelled'",
                    'default'=>'pending'
                ])
                ->setField('registration_number', ['type'=>'varchar', 'size'=>64])
                ->setField('consumer_number', ['type'=>'varchar', 'size'=>64])
                ->setField('date_due', ['type'=>'date', 'is_null'=>true, 'default'=>null])
                ->setField('date_expires', ['type'=>'date', 'is_null'=>true, 'default'=>null])
                ->setField('date_created', ['type'=>'datetime'])
                ->setField('date_updated', ['type'=>'datetime'])
                ->setField('date_posted', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
                ->setField('date_paid', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
                ->setField('date_last_checked', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
                ->setField('retry_count', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
                ->setField('error_class', ['type'=>'varchar', 'size'=>32, 'is_null'=>true, 'default'=>null])
                ->setField('raw_status', ['type'=>'varchar', 'size'=>8, 'is_null'=>true, 'default'=>null])
                ->setField('evidence_hash', ['type'=>'varchar', 'size'=>24, 'is_null'=>true, 'default'=>null])
                ->setField('kuickpay_reference', ['type'=>'varchar', 'size'=>128, 'is_null'=>true, 'default'=>null])
                ->setField('blesta_transaction_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
                ->setField('diagnostic_summary', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setField('admin_notes', ['type'=>'text', 'is_null'=>true, 'default'=>null])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id', 'consumer_number'], 'unique')
                ->setKey(['company_id', 'registration_number'], 'unique')
                ->setKey(['status'], 'index')
                ->setKey(['client_id'], 'index')
                ->setKey(['blesta_transaction_id'], 'index')
                ->create('kuickpay_vouchers', true);

            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('voucher_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('invoice_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('amount', ['type'=>'varchar', 'size'=>20])
                ->setField('date_created', ['type'=>'datetime'])
                ->setKey(['id'], 'primary')
                ->setKey(['voucher_id', 'invoice_id'], 'unique')
                ->setKey(['invoice_id'], 'index')
                ->create('kuickpay_voucher_invoices', true);

            $this->createReconcileTables();
            $this->addCronTasks();
        } catch (Exception $e) {
            $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
            return;
        }
    }

    /**
     * Performs upgrade actions.
     *
     * @param string $current_version The current installed version of this plugin
     * @param int $plugin_id The ID of plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Input', 'Record']);
        }

        try {
            if (version_compare($current_version, '1.1.0', '<')) {
                $this->addVoucherEvidenceColumns();
                $this->createReconcileTables();
                $this->addCronTasks();
            }

            if (version_compare($current_version, '1.2.0', '<')) {
                $this->addCronTasks();
            }

            if (version_compare($current_version, '1.3.0', '<')) {
                $this->addCronTasks();
            }

            if (version_compare($current_version, '1.4.0', '<')) {
                $this->addBulkReconciliationColumns();
            }
        } catch (Exception $e) {
            $this->Input->setErrors(['db'=> ['upgrade'=>$e->getMessage()]]);
            return;
        }
    }

    /**
     * Performs cleanup actions.
     *
     * Voucher evidence tables are preserved on uninstall per the architecture
     * rollback policy, even when this is the last plugin instance.
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance
     *  across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
        if (!isset($this->Input)) {
            Loader::loadComponents($this, ['Input']);
        }

        $this->addCronTasks(true, $last_instance);
    }

    /**
     * Runs plugin cron tasks.
     *
     * @param string $key The cron task key
     */
    public function cron($key)
    {
        if (!in_array($key, ['reconcile_pending', 'post_confirmed', 'expire_vouchers'], true)) {
            return;
        }

        if ($key === 'reconcile_pending') {
            Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayReconcileService.php');

            $service = new KuickPayReconcileService();
            $service->runCron((int) Configure::get('Blesta.company_id'));
            return;
        }

        if ($key === 'expire_vouchers') {
            Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayReconcileService.php');

            $service = new KuickPayReconcileService();
            $service->expirePending((int) Configure::get('Blesta.company_id'));
            return;
        }

        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayPostingService.php');

        $service = new KuickPayPostingService();
        $service->postConfirmed((int) Configure::get('Blesta.company_id'));
    }

    /**
     * Adds reconciliation-only columns to the existing voucher table.
     */
    private function addVoucherEvidenceColumns()
    {
        if (!$this->columnExists('kuickpay_vouchers', 'retry_count')) {
            $this->Record->query(
                'ALTER TABLE `kuickpay_vouchers` ADD `retry_count` INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$this->columnExists('kuickpay_vouchers', 'date_paid')) {
            $this->Record->query(
                'ALTER TABLE `kuickpay_vouchers` ADD `date_paid` DATETIME NULL DEFAULT NULL AFTER `date_posted`'
            );
        }
    }

    /**
     * Adds bulk reconciliation summary fields to the run table.
     */
    private function addBulkReconciliationColumns()
    {
        if (!$this->enumContains('kuickpay_reconciliation_runs', 'trigger_type', 'bulk')) {
            $this->Record->query(
                "ALTER TABLE `kuickpay_reconciliation_runs` "
                . "MODIFY `trigger_type` ENUM('cron','manual','bulk') NOT NULL"
            );
        }

        if (!$this->columnExists('kuickpay_reconciliation_runs', 'run_date')) {
            $this->Record->query(
                'ALTER TABLE `kuickpay_reconciliation_runs` '
                . 'ADD `run_date` DATE NULL DEFAULT NULL AFTER `date_completed`'
            );
        }

        if (!$this->columnExists('kuickpay_reconciliation_runs', 'total_unmatched')) {
            $this->Record->query(
                'ALTER TABLE `kuickpay_reconciliation_runs` '
                . 'ADD `total_unmatched` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_errors`'
            );
        }
    }

    /**
     * Creates reconciliation run, item, lock, and audit tables.
     */
    private function createReconcileTables()
    {
        $this->Record
            ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
            ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
            ->setField('trigger_type', ['type'=>'enum', 'size'=>"'cron','manual','bulk'"])
            ->setField('status', ['type'=>'enum', 'size'=>"'running','completed','aborted','failed'"])
            ->setField('date_started', ['type'=>'datetime'])
            ->setField('date_completed', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
            ->setField('run_date', ['type'=>'date', 'is_null'=>true, 'default'=>null])
            ->setField('cursor', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
            ->setField('total_eligible', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_checked', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_confirmed', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_retry', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_manual_review', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_expired', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_failed', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_errors', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('total_unmatched', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'default'=>0])
            ->setField('summary', ['type'=>'text', 'is_null'=>true, 'default'=>null])
            ->setKey(['id'], 'primary')
            ->setKey(['company_id'], 'index', 'idx_kuickpay_runs_company')
            ->setKey(['status'], 'index', 'idx_kuickpay_runs_status')
            ->create('kuickpay_reconciliation_runs', true);

        $this->Record
            ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
            ->setField('run_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
            ->setField('voucher_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
            ->setField('prior_status', ['type'=>'varchar', 'size'=>32])
            ->setField('new_status', ['type'=>'varchar', 'size'=>32])
            ->setField('error_class', ['type'=>'varchar', 'size'=>32, 'is_null'=>true, 'default'=>null])
            ->setField('evidence_hash', ['type'=>'varchar', 'size'=>24, 'is_null'=>true, 'default'=>null])
            ->setField('redacted_trace_id', ['type'=>'varchar', 'size'=>32, 'is_null'=>true, 'default'=>null])
            ->setField('date_created', ['type'=>'datetime'])
            ->setKey(['id'], 'primary')
            ->setKey(['run_id', 'voucher_id'], 'unique', 'uniq_kuickpay_items_run_voucher')
            ->setKey(['voucher_id'], 'index', 'idx_kuickpay_items_voucher')
            ->create('kuickpay_reconciliation_items', true);

        $this->Record
            ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
            ->setField('lock_name', ['type'=>'varchar', 'size'=>64])
            ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
            ->setField('owner_token', ['type'=>'varchar', 'size'=>64])
            ->setField('date_acquired', ['type'=>'datetime'])
            ->setField('date_expires', ['type'=>'datetime'])
            ->setField('date_heartbeat', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
            ->setKey(['id'], 'primary')
            ->setKey(['company_id', 'lock_name'], 'unique', 'uniq_kuickpay_locks_company_name')
            ->create('kuickpay_reconcile_locks', true);

        $this->Record
            ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
            ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
            ->setField('voucher_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
            ->setField('run_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
            ->setField('event_name', ['type'=>'varchar', 'size'=>64])
            ->setField('redacted_trace_id', ['type'=>'varchar', 'size'=>32, 'is_null'=>true, 'default'=>null])
            ->setField('evidence_hash', ['type'=>'varchar', 'size'=>24, 'is_null'=>true, 'default'=>null])
            ->setField('payload', ['type'=>'text', 'is_null'=>true, 'default'=>null])
            ->setField('date_created', ['type'=>'datetime'])
            ->setKey(['id'], 'primary')
            ->setKey(['company_id'], 'index', 'idx_kuickpay_audit_company')
            ->setKey(['voucher_id'], 'index', 'idx_kuickpay_audit_voucher')
            ->setKey(['event_name'], 'index', 'idx_kuickpay_audit_event')
            ->create('kuickpay_audit_events', true);
    }

    /**
     * Checks whether a column exists on a table.
     *
     * @param string $table The table name
     * @param string $column The column name
     * @return bool True when present
     */
    private function columnExists($table, $column)
    {
        $statement = $this->Record->query('SHOW COLUMNS FROM `' . $table . '` LIKE ?', $column);

        return (bool) $statement->fetch();
    }

    /**
     * Checks whether an enum column already includes a value.
     *
     * @param string $table The table name
     * @param string $column The column name
     * @param string $value The enum value
     * @return bool True when the enum contains the value
     */
    private function enumContains($table, $column, $value)
    {
        $statement = $this->Record->query('SHOW COLUMNS FROM `' . $table . '` LIKE ?', $column);
        $column = $statement->fetch();

        return $column && strpos((string) $column->Type, "'" . $value . "'") !== false;
    }

    /**
     * Adds or removes cron tasks.
     *
     * @param bool $undo True to remove the cron task
     * @param bool $last_instance True if the plugin is being completely uninstalled
     * @return bool True on success
     */
    private function addCronTasks($undo = false, $last_instance = false)
    {
        Loader::loadModels($this, ['CronTasks']);

        foreach ($this->getCronTasks() as $task) {
            if ($undo) {
                $this->deleteCronTask($task, $last_instance);
            } elseif (!$this->addCronTask($task)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates a cron task and task run idempotently.
     *
     * @param array $task Cron task fields
     * @return bool True on success
     */
    private function addCronTask(array $task)
    {
        if (($cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']))) {
            $task_id = $cron_task->id;
        } else {
            $task_id = $this->CronTasks->add($task);

            if (($errors = $this->CronTasks->errors())) {
                $this->Input->setErrors($errors);
                return false;
            }
        }

        if ($task_id && !$this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type'])) {
            $this->CronTasks->addTaskRun($task_id, ['enabled'=>$task['enabled'], $task['type']=>$task['type_value']]);

            if (($errors = $this->CronTasks->errors())) {
                $this->Input->setErrors($errors);
                return false;
            }
        }

        return true;
    }

    /**
     * Deletes a cron task run and optionally the shared task definition.
     *
     * @param array $task Cron task fields
     * @param bool $last_instance True if the plugin is being completely uninstalled
     */
    private function deleteCronTask(array $task, $last_instance = false)
    {
        if (($task_run = $this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']))) {
            $this->CronTasks->deleteTaskRun($task_run->task_run_id);
        }

        if ($last_instance &&
            ($cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']))
        ) {
            $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
        }
    }

    /**
     * Gets installable cron task definitions.
     *
     * @return array Cron task definitions
     */
    private function getCronTasks()
    {
        return [
            [
                'key'=>'reconcile_pending',
                'dir'=>'kuickpay_reconcile',
                'task_type'=>'plugin',
                'name'=>Language::_('KuickpayReconcilePlugin.cron.reconcile_pending_name', true),
                'description'=>Language::_('KuickpayReconcilePlugin.cron.reconcile_pending_desc', true),
                'type'=>'interval',
                'type_value'=>5,
                'enabled'=>1
            ],
            [
                'key'=>'post_confirmed',
                'dir'=>'kuickpay_reconcile',
                'task_type'=>'plugin',
                'name'=>Language::_('KuickpayReconcilePlugin.cron.post_confirmed_name', true),
                'description'=>Language::_('KuickpayReconcilePlugin.cron.post_confirmed_desc', true),
                'type'=>'interval',
                'type_value'=>5,
                'enabled'=>1
            ],
            [
                'key'=>'expire_vouchers',
                'dir'=>'kuickpay_reconcile',
                'task_type'=>'plugin',
                'name'=>Language::_('KuickpayReconcilePlugin.cron.expire_vouchers_name', true),
                'description'=>Language::_('KuickpayReconcilePlugin.cron.expire_vouchers_desc', true),
                'type'=>'interval',
                'type_value'=>60,
                'enabled'=>1
            ],
        ];
    }
}
