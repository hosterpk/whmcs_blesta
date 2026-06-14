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
                ->setKey(['company_id', 'consumer_number'], 'unique', 'uniq_kuickpay_vouchers_consumer')
                ->setKey(['company_id', 'registration_number'], 'unique', 'uniq_kuickpay_vouchers_reg')
                ->setKey(['status'], 'index', 'idx_kuickpay_vouchers_status')
                ->setKey(['client_id'], 'index', 'idx_kuickpay_vouchers_client')
                ->setKey(['blesta_transaction_id'], 'index', 'idx_kuickpay_vouchers_txn')
                ->create('kuickpay_vouchers', true);

            $this->Record
                ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
                ->setField('voucher_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('invoice_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
                ->setField('amount', ['type'=>'varchar', 'size'=>20])
                ->setField('date_created', ['type'=>'datetime'])
                ->setKey(['id'], 'primary')
                ->setKey(['voucher_id', 'invoice_id'], 'unique', 'uniq_kuickpay_voucher_invoices_link')
                ->setKey(['invoice_id'], 'index', 'idx_kuickpay_voucher_invoices_inv')
                ->create('kuickpay_voucher_invoices', true);

            // Fresh install and versioned upgrade converge on the identical
            // schema by sharing this one method (Story 5.2). On an empty table
            // the backfill UPDATE is a harmless no-op; the generated column and
            // its unique key cannot be expressed through the setField/setKey
            // builder, so the raw-query ALTER path is the only mechanism.
            $this->addActiveContextGuard();

            // Durable posting-attempt counter (Story 5.3): shared by install() and
            // upgrade() so both routes converge on the identical schema. On a fresh
            // table the column simply defaults to 0.
            $this->addPostingAttemptsColumn();

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

            // 1.5.0 adds the admin voucher list (Story 4.1). No schema change:
            // the bump exists only so PluginManager::upgrade() re-syncs the nav
            // and ACL set from getActions()/getPermissions().
            if (version_compare($current_version, '1.5.0', '<')) {
                // Intentionally empty — nav/permission re-registration is driven
                // by the version change, not by SQL here.
            }

            // 1.6.0 adds the voucher detail page + the separate "view
            // diagnostics" permission (Story 4.2). No schema change: the bump
            // exists only so PluginManager::upgrade() re-syncs the permission
            // set from getPermissions() and registers the new diagnostics ACO.
            if (version_compare($current_version, '1.6.0', '<')) {
                // Intentionally empty — permission re-registration is driven by
                // the version change, not by SQL here.
            }

            // 1.7.0 adds the per-voucher manual action permissions (Story 4.3).
            // No schema change: the bump exists only so PluginManager::upgrade()
            // re-syncs the permission set from getPermissions().
            if (version_compare($current_version, '1.7.0', '<')) {
                // Intentionally empty — permission re-registration is driven by
                // the version change, not by SQL here.
            }

            // 1.8.0 adds the manual-review queue + reconciliation run views and
            // their two read-only page permissions (Story 4.4). No schema change:
            // the runs/items tables already exist; the bump exists only so
            // PluginManager::upgrade() re-syncs nav + the permission set.
            if (version_compare($current_version, '1.8.0', '<')) {
                // Intentionally empty — nav/permission re-registration is driven
                // by the version change, not by SQL here.
            }

            // 1.9.0 adds the schema-level active-context concurrency guard
            // (Story 5.2): a deterministic context_key, a status-derived STORED
            // generated active_context_key, and a company-scoped unique key so
            // two concurrent same-invoice-set submissions can never mint two
            // pending vouchers. FIRST Epic 5 branch that runs real SQL.
            if (version_compare($current_version, '1.9.0', '<')) {
                $this->addActiveContextGuard();
            }

            // 1.10.0 adds the durable posting-attempt counter (Story 5.3): a
            // confirmed_unposted Voucher whose post deterministically fails is
            // bounded by posting_attempts and escalated to manual_review at the
            // cap, so it stops re-occupying the head of every postConfirmed() batch.
            if (version_compare($current_version, '1.10.0', '<')) {
                $this->addPostingAttemptsColumn();
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

            $dependencies = [];
            try {
                $dependencies['logger'] = $this->getFromContainer('logger');
            } catch (Throwable $e) {
                // Missing logger falls back to no operational SOAP logs.
            }

            $service = new KuickPayReconcileService($dependencies);
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
     * Returns staff navigation actions for this plugin.
     *
     * @return array Plugin actions
     */
    public function getActions()
    {
        return [
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/kuickpay_reconcile/admin_vouchers/index/',
                'name' => 'KuickpayReconcilePlugin.nav_secondary_staff.vouchers',
                'options' => ['parent' => 'billing/']
            ],
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/kuickpay_reconcile/admin_main/index/',
                'name' => 'KuickpayReconcilePlugin.nav_secondary_staff.bulk_reconcile',
                'options' => ['parent' => 'billing/']
            ],
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/kuickpay_reconcile/admin_manual_review/index/',
                'name' => 'KuickpayReconcilePlugin.nav_secondary_staff.manual_review',
                'options' => ['parent' => 'billing/']
            ],
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/kuickpay_reconcile/admin_reconciliation/index/',
                'name' => 'KuickpayReconcilePlugin.nav_secondary_staff.reconciliation',
                'options' => ['parent' => 'billing/']
            ]
        ];
    }

    /**
     * Returns ACL permissions for this plugin.
     *
     * @return array Plugin permissions
     */
    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers', true),
                'alias' => 'kuickpay_reconcile.admin_vouchers',
                'action' => '*'
            ],
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.bulk_reconcile', true),
                'alias' => 'kuickpay_reconcile.admin_main',
                'action' => '*'
            ],
            // Separate "view diagnostics" permission: same alias as the
            // view-records row above, distinct action. Gates only the
            // diagnostics SECTION of admin_vouchers/detail (Story 4.2) — there
            // is no public diagnostics() method, so it never gates a route.
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers_diagnostics', true),
                'alias' => 'kuickpay_reconcile.admin_vouchers',
                'action' => 'diagnostics'
            ],
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers_recheck', true),
                'alias' => 'kuickpay_reconcile.admin_vouchers',
                'action' => 'recheck'
            ],
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers_review', true),
                'alias' => 'kuickpay_reconcile.admin_vouchers',
                'action' => 'review'
            ],
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers_cancel', true),
                'alias' => 'kuickpay_reconcile.admin_vouchers',
                'action' => 'cancel'
            ],
            // Read-only page permissions for the Story 4.4 visibility surfaces.
            // The controllers never mutate, so action => '*' is correct here.
            // Grant alongside admin_vouchers view: the queue/run views link to
            // admin_vouchers/detail, which enforces its own view permission.
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.manual_review', true),
                'alias' => 'kuickpay_reconcile.admin_manual_review',
                'action' => '*'
            ],
            [
                'group_alias' => 'admin_billing',
                'name' => Language::_('KuickpayReconcilePlugin.permission.reconciliation', true),
                'alias' => 'kuickpay_reconcile.admin_reconciliation',
                'action' => '*'
            ]
        ];
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
     * Adds the durable posting-attempt counter column (Story 5.3).
     *
     * Idempotent and shared by both install() and upgrade() so the two routes
     * converge on the identical schema. posting_attempts bounds how many times a
     * confirmed_unposted Voucher may fail posting before it is escalated to
     * manual_review (KuickPayPostingService::POSTING_RETRY_LIMIT), clearing the
     * head-of-line block in getPostable()'s ascending-by-id batch. Existing rows
     * default to 0; the ADD is guarded so a re-run is a no-op.
     */
    private function addPostingAttemptsColumn()
    {
        if (!$this->columnExists('kuickpay_vouchers', 'posting_attempts')) {
            $this->Record->query(
                'ALTER TABLE `kuickpay_vouchers`'
                . ' ADD `posting_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `retry_count`'
            );
        }
    }

    /**
     * Adds the schema-level active-context concurrency guard (Story 5.2).
     *
     * Idempotent and shared by both install() and upgrade() so the two routes
     * converge on the identical schema. Adds, each step guarded so a re-run is
     * a no-op:
     *  - context_key: a deterministic fingerprint of the company-scoped,
     *    ascending, de-duplicated integer invoice-id set, written on every
     *    voucher (sha1 of "1,2,3").
     *  - active_context_key: a STORED generated column equal to context_key for
     *    every status EXCEPT the two customer-re-payable terminal states
     *    (expired, cancelled), which release the slot by computing to NULL.
     *  - uniq_kuickpay_vouchers_active_context (company_id, active_context_key):
     *    a unique key. Because a unique index permits multiple NULLs, released
     *    or terminal vouchers never collide, but at most one active voucher per
     *    (company, invoice-set) can exist — so two concurrent same-set issuance
     *    attempts resolve to exactly one pending voucher (the loser's INSERT
     *    fails on the key and the create-null fall-through returns the winner).
     */
    private function addActiveContextGuard()
    {
        $sql = self::activeContextGuardSql('kuickpay_vouchers');

        // 2.2a — nullable context_key so the ADD succeeds on a populated table
        // (a NOT-NULL-without-default ADD would fail on existing rows).
        if (!$this->columnExists('kuickpay_vouchers', 'context_key')) {
            $this->Record->query($sql['add_context_key']);
        }

        // 2.2b — backfill with the SAME algorithm the application uses
        // (sha1 of the ascending, de-duplicated integer invoice-id set). Rows
        // with no links keep context_key = NULL (intentionally not an active
        // claim); repository->create() rolls back if any link insert fails, so
        // link-less vouchers should not exist.
        $this->Record->query($sql['set_group_concat_max_len']);
        $this->Record->query($sql['backfill_context_key']);

        // 2.2c — resolve any pre-existing active-context collisions BEFORE the
        // unique key is added (never silently drop the key).
        $this->resolveActiveContextDuplicates();

        // 2.2d — status-derived STORED generated column. MySQL/MariaDB recompute
        // it on every INSERT/UPDATE of status or context_key, so the application
        // never writes it and transition code needs no changes.
        if (!$this->columnExists('kuickpay_vouchers', 'active_context_key')) {
            $this->Record->query($sql['add_active_context_key']);
        }

        // 2.2e — company-scoped unique key over the generated column. Multiple
        // NULL active_context_key values are permitted, so terminal/released
        // rows never collide.
        if (!$this->indexExists('kuickpay_vouchers', 'uniq_kuickpay_vouchers_active_context')) {
            $this->Record->query($sql['add_unique_key']);
        }
    }

    /**
     * Returns the table-parameterized SQL for the Story 5.2 active-context guard.
     *
     * The integration harness uses the same SQL against a scratch table for
     * fresh-install/upgrade schema parity, so drift between test evidence and the
     * production guard is caught by construction.
     *
     * @param string $table Table name to alter
     * @return array SQL statements keyed by migration step
     */
    public static function activeContextGuardSql($table = 'kuickpay_vouchers'): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            throw new InvalidArgumentException('Invalid KuickPay voucher table name.');
        }

        $quotedTable = '`' . $table . '`';

        return [
            'add_context_key' => 'ALTER TABLE ' . $quotedTable
                . ' ADD `context_key` VARCHAR(64) NULL DEFAULT NULL AFTER `consumer_number`',
            'set_group_concat_max_len' => 'SET SESSION group_concat_max_len = @@max_allowed_packet',
            'backfill_context_key' => 'UPDATE ' . $quotedTable . ' v'
                . ' JOIN (SELECT voucher_id,'
                . " SHA1(GROUP_CONCAT(DISTINCT invoice_id ORDER BY invoice_id SEPARATOR ',')) AS ck"
                . ' FROM `kuickpay_voucher_invoices` GROUP BY voucher_id) m'
                . ' ON m.voucher_id = v.id'
                . ' SET v.context_key = m.ck'
                . ' WHERE v.context_key IS NULL',
            'add_active_context_key' => 'ALTER TABLE ' . $quotedTable . ' ADD `active_context_key` VARCHAR(64)'
                . " GENERATED ALWAYS AS (CASE WHEN status IN ('expired','cancelled')"
                . ' THEN NULL ELSE context_key END) STORED AFTER `context_key`',
            'add_unique_key' => 'ALTER TABLE ' . $quotedTable
                . ' ADD UNIQUE KEY `uniq_kuickpay_vouchers_active_context` (`company_id`, `active_context_key`)',
        ];
    }

    /**
     * Resolves pre-existing active-context collisions before the unique key.
     *
     * Detects rows that would violate uniq_kuickpay_vouchers_active_context once
     * it is live — same (company_id, context_key) among non-terminal statuses —
     * and resolves each group deterministically (fail-closed per NFR9): keep one
     * active row (a paid 'posted' row if present, otherwise the most recent id)
     * and cancel the older colliding non-posted rows so their generated
     * active_context_key releases to NULL. Never touches a 'posted' row. On the
     * current live data this finds nothing; the method exists so the migration
     * is correct if it ever fires. If a group cannot be reduced to a single
     * active row (e.g. two 'posted' rows share an invoice set), the later
     * ADD UNIQUE KEY fails closed and surfaces the error rather than guessing.
     */
    private function resolveActiveContextDuplicates()
    {
        $groups = $this->Record->query(
            'SELECT company_id, context_key, COUNT(*) AS c'
            . ' FROM `kuickpay_vouchers`'
            . " WHERE status NOT IN ('expired','cancelled') AND context_key IS NOT NULL"
            . ' GROUP BY company_id, context_key HAVING c > 1'
        )->fetchAll();

        foreach ($groups as $group) {
            $rows = $this->Record->query(
                'SELECT id, status FROM `kuickpay_vouchers`'
                . ' WHERE company_id = ? AND context_key = ?'
                . " AND status NOT IN ('expired','cancelled')"
                . ' ORDER BY id DESC',
                $group->company_id,
                $group->context_key
            )->fetchAll();

            // Survivor: the most recent id by default, but prefer a paid row —
            // a 'posted' invoice set must keep its slot forever.
            $survivor_id = (int) $rows[0]->id;
            foreach ($rows as $row) {
                if ((string) $row->status === 'posted') {
                    $survivor_id = (int) $row->id;
                    break;
                }
            }

            $this->Record->query(
                'UPDATE `kuickpay_vouchers`'
                . ' SET status = ?, date_updated = ?'
                . ' WHERE company_id = ? AND context_key = ?'
                . " AND status NOT IN ('expired','cancelled','posted')"
                . ' AND id <> ?',
                'cancelled',
                date('Y-m-d H:i:s'),
                $group->company_id,
                $group->context_key,
                $survivor_id
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
     * Checks whether a named index exists on a table.
     *
     * @param string $table The table name
     * @param string $index The index (key) name
     * @return bool True when present
     */
    private function indexExists($table, $index)
    {
        $statement = $this->Record->query(
            'SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?',
            $index
        );

        return (bool) $statement->fetch();
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
