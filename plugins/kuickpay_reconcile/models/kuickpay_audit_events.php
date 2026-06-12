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

    /**
     * Fetches the redacted audit history for a voucher, newest first.
     *
     * Company-scoped read for the admin diagnostics section. Selects only the
     * display columns (never company_id/run_id/id) so internal scoping/ordering
     * keys never reach the view. The payload is already-redacted JSON written by
     * KuickPayAuditService::record(); it is rendered as one escaped blob, not a
     * per-key echo. Indexed on voucher_id and company_id.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @param int $limit Maximum rows to return (most recent kept)
     * @return array Audit event rows (stdClass), newest first
     */
    public function getByVoucher(int $voucher_id, int $company_id, int $limit = 100): array
    {
        return $this->Record
            ->select(['event_name', 'redacted_trace_id', 'evidence_hash', 'payload', 'date_created'])
            ->from('kuickpay_audit_events')
            ->where('voucher_id', '=', $voucher_id)
            ->where('company_id', '=', $company_id)
            ->order(['date_created' => 'DESC', 'id' => 'DESC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }
}
