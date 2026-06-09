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
     * payment references. Reconciliation cron is owned by later stories.
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
                ->setField('date_last_checked', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
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
        } catch (Exception $e) {
            $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
            return;
        }
    }

    /**
     * Performs upgrade actions.
     *
     * This is the first schema version, so there are no versioned migrations yet.
     *
     * @param string $current_version The current installed version of this plugin
     * @param int $plugin_id The ID of plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
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
    }
}
