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

        // scopedInsert enforces the tenant column on every audit INSERT.
        $this->scopedInsert('kuickpay_audit_events', (int) ($vars['company_id'] ?? 0), $vars, self::FIELDS);

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
        return $this->scopedSelect(
                'kuickpay_audit_events',
                $company_id,
                ['event_name', 'redacted_trace_id', 'evidence_hash', 'payload', 'date_created']
            )
            ->where('voucher_id', '=', $voucher_id)
            ->order(['date_created' => 'DESC', 'id' => 'DESC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Fetches the run-scoped audit-only bulk exceptions for the run-detail view.
     *
     * The bulk loop writes NO reconciliation_items row for unmatched provider
     * rows (no local voucher) or duplicate echoes — it bumps the counters and
     * records an audit event instead. This read surfaces those two event types
     * so the run's total_unmatched always has a corresponding per-row evidence
     * entry (AC3). Company scope is a direct column here (the audit table has
     * company_id + run_id), so no JOIN is needed. Selects only display columns
     * (mirrors getByVoucher, but keeps voucher_id so the view can link a
     * duplicate to its voucher while an unmatched row — voucher_id null —
     * renders evidence only). The payload is already-redacted JSON.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @param int $limit Maximum rows to return
     * @return array Audit event rows (stdClass), oldest first
     */
    public function getByRun(int $run_id, int $company_id, int $limit = 500): array
    {
        return $this->scopedSelect(
                'kuickpay_audit_events',
                $company_id,
                ['voucher_id', 'event_name', 'redacted_trace_id', 'evidence_hash', 'payload', 'date_created']
            )
            ->where('run_id', '=', $run_id)
            ->where('event_name', 'in', ['evidence.unmatched', 'evidence.duplicate'])
            ->order(['id' => 'ASC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Counts run-scoped audit-only bulk exceptions for truncation honesty.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @return int The total matching audit-only exception rows
     */
    public function getCountByRun(int $run_id, int $company_id): int
    {
        return $this->scopedSelect('kuickpay_audit_events', $company_id, ['id'])
            ->where('run_id', '=', $run_id)
            ->where('event_name', 'in', ['evidence.unmatched', 'evidence.duplicate'])
            ->numResults();
    }
}
