<?php
/**
 * KuickPay reconciliation runs model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 */
class KuickpayReconciliationRuns extends KuickpayReconcileModel
{
    /**
     * @var array The canonical run trigger types. Single source of truth that
     *  mirrors the trigger_type ENUM in
     *  KuickpayReconcilePlugin::createReconcileTables()/addBulkReconciliationColumns().
     *  Public so the pure presenter's allowlist can be cross-checked against it.
     */
    public const TRIGGER_TYPES = ['cron', 'manual', 'bulk'];

    /**
     * @var array The canonical run statuses. Single source of truth that mirrors
     *  the status ENUM in KuickpayReconcilePlugin::createReconcileTables().
     *  Public so the pure presenter's allowlist can be cross-checked against it.
     */
    public const STATUSES = ['running', 'completed', 'aborted', 'failed'];

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

    /**
     * Fetches a page of company-scoped reconciliation runs.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted optional filters (trigger_type, status)
     * @param int $page The page number
     * @param array $order_by The order fields
     * @return array Run rows
     */
    public function getListForCompany(
        int $company_id,
        array $filters = [],
        int $page = 1,
        array $order_by = ['date_started' => 'DESC']
    ): array {
        $this->Record->select()->from('kuickpay_reconciliation_runs');
        $this->applyRunFilters($company_id, $filters);

        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Counts company-scoped reconciliation runs matching the filters.
     *
     * Shares applyRunFilters() with getListForCompany() so the count can never
     * drift from the page it paginates.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted optional filters (trigger_type, status)
     * @return int The total matching rows
     */
    public function getListCountForCompany(int $company_id, array $filters = []): int
    {
        $this->Record->select()->from('kuickpay_reconciliation_runs');
        $this->applyRunFilters($company_id, $filters);

        return $this->Record->numResults();
    }

    /**
     * Fetches a single run scoped to a company.
     *
     * This is the company-scope gate reused by the run-detail item/audit queries
     * (the items table has no company_id of its own): a run id outside the staff
     * company resolves to false, never another company's run.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @return mixed The run row, or false when absent or out of company scope
     */
    public function getForCompany(int $run_id, int $company_id)
    {
        return $this->Record->select()
            ->from('kuickpay_reconciliation_runs')
            ->where('id', '=', $run_id)
            ->where('company_id', '=', $company_id)
            ->fetch();
    }

    /**
     * Applies the mandatory company scope and the allowlisted run filters.
     *
     * Company scope is applied UNCONDITIONALLY and never sourced from $filters.
     * Each optional filter is matched exactly against its source-of-truth const,
     * so a request value can never reach the query unvalidated.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted optional filters: trigger_type, status
     */
    private function applyRunFilters(int $company_id, array $filters): void
    {
        // Mandatory tenant scope — never from request input.
        $this->Record->where('company_id', '=', $company_id);

        if (isset($filters['trigger_type']) && in_array($filters['trigger_type'], self::TRIGGER_TYPES, true)) {
            $this->Record->where('trigger_type', '=', $filters['trigger_type']);
        }

        if (isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $this->Record->where('status', '=', $filters['status']);
        }
    }
}
