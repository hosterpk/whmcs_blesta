<?php
/**
 * KuickPay reconciliation items model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 */
class KuickpayReconciliationItems extends KuickpayReconcileModel
{
    private const FIELDS = [
        'run_id',
        'voucher_id',
        'prior_status',
        'new_status',
        'error_class',
        'evidence_hash',
        'redacted_trace_id',
        'date_created',
    ];

    public function add(array $vars)
    {
        $vars['date_created'] = $vars['date_created'] ?? date('Y-m-d H:i:s');

        $this->Record->insert('kuickpay_reconciliation_items', $vars, self::FIELDS);

        return $this->Record->lastInsertId();
    }
}
