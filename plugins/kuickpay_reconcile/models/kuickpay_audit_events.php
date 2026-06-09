<?php
/**
 * KuickPay audit events model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 */
class KuickpayAuditEvents extends KuickpayReconcileModel
{
    private const FIELDS = [
        'company_id',
        'voucher_id',
        'run_id',
        'event_name',
        'redacted_trace_id',
        'evidence_hash',
        'payload',
        'date_created',
    ];

    public function add(array $vars)
    {
        $vars['date_created'] = $vars['date_created'] ?? date('Y-m-d H:i:s');

        $this->Record->insert('kuickpay_audit_events', $vars, self::FIELDS);

        return $this->Record->lastInsertId();
    }
}
