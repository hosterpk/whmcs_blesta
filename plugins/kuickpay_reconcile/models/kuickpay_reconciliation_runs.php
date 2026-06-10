<?php
/**
 * KuickPay reconciliation runs model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 */
class KuickpayReconciliationRuns extends KuickpayReconcileModel
{
    private const FIELDS = [
        'company_id',
        'trigger_type',
        'status',
        'date_started',
        'date_completed',
        'run_date',
        'cursor',
        'total_eligible',
        'total_checked',
        'total_confirmed',
        'total_retry',
        'total_manual_review',
        'total_expired',
        'total_failed',
        'total_errors',
        'total_unmatched',
        'summary',
    ];

    public function add(array $vars)
    {
        $vars['date_started'] = $vars['date_started'] ?? date('Y-m-d H:i:s');

        $this->Record->insert('kuickpay_reconciliation_runs', $vars, self::FIELDS);

        return $this->Record->lastInsertId();
    }

    public function edit(int $run_id, array $vars): void
    {
        $this->Record->where('id', '=', $run_id)->update('kuickpay_reconciliation_runs', $vars, self::FIELDS);
    }

    public function getResumeCursor(int $company_id): int
    {
        $run = $this->Record->select()
            ->from('kuickpay_reconciliation_runs')
            ->where('company_id', '=', $company_id)
            ->where('trigger_type', '=', 'cron')
            ->where('status', '=', 'aborted')
            ->where('cursor', '!=', null)
            ->order(['id' => 'DESC'])
            ->fetch();

        return $run ? (int) $run->cursor : 0;
    }
}
