<?php
/**
 * KuickPay audit repository
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayAuditRepository
{
    public function __construct()
    {
        Loader::loadModels($this, ['KuickpayReconcile.KuickpayAuditEvents']);
    }

    public function add(array $vars): void
    {
        $this->KuickpayAuditEvents->add($vars);
    }
}
