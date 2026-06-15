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

    /**
     * Fetches a bounded page of items for a run, scoped to a company.
     *
     * The items table has no company_id, so scope is enforced through a JOIN to
     * the runs table (defense in depth — the method is safe even if a caller
     * forgets the controller-side ownership fetch). The result is bounded
     * (NFR7); the controller compares getCountByRun() to the cap to surface an
     * honest truncation notice rather than silently dropping rows.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @param int $limit Maximum rows to return
     * @return array Item rows (stdClass), oldest first
     */
    public function getByRun(int $run_id, int $company_id, int $limit = 500): array
    {
        return $this->scopedChildSelect(
                'kuickpay_reconciliation_items',
                'kuickpay_reconciliation_runs',
                'run_id',
                $company_id,
                ['kuickpay_reconciliation_items.*']
            )
            ->where('kuickpay_reconciliation_items.run_id', '=', $run_id)
            ->order(['kuickpay_reconciliation_items.id' => 'ASC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Counts items for a run, scoped to a company via the same JOIN.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @return int The total matching item rows
     */
    public function getCountByRun(int $run_id, int $company_id): int
    {
        return $this->scopedChildSelect(
                'kuickpay_reconciliation_items',
                'kuickpay_reconciliation_runs',
                'run_id',
                $company_id,
                ['kuickpay_reconciliation_items.id']
            )
            ->where('kuickpay_reconciliation_items.run_id', '=', $run_id)
            ->numResults();
    }
}
