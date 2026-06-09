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
     * Performs scaffold install actions.
     *
     * Voucher schema is owned by Story 2.1, and reconciliation cron is owned by Epic 3.
     * This scaffold intentionally creates no tables, events, actions, or cron entries.
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
    }

    /**
     * Performs scaffold upgrade actions.
     *
     * Future stories should add versioned migrations here when this plugin owns data.
     *
     * @param string $current_version The current installed version of this plugin
     * @param int $plugin_id The ID of plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
    }

    /**
     * Performs scaffold cleanup actions.
     *
     * This plugin owns no data yet. When schema is added, cleanup must remove only
     * plugin-owned data and must honor $last_instance for multi-company safety.
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance
     *  across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
    }
}
