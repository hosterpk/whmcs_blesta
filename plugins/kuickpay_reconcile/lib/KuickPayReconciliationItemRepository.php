<?php
/**
 * KuickPay reconciliation item repository
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayReconciliationItemRepository
{
    public function __construct()
    {
        Loader::loadModels($this, ['KuickpayReconcile.KuickpayReconciliationItems']);
    }

    public function record(array $vars): void
    {
        $this->KuickpayReconciliationItems->add($vars);
    }
}
