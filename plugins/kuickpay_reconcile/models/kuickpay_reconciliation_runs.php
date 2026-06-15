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

    /**
     * @var array DB columns that public list reads may sort by.
     */
    private const ORDER_FIELDS = [
        'id',
        'trigger_type',
        'status',
        'date_started',
        'date_completed',
        'run_date',
    ];

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

        // scopedInsert enforces the tenant column on every run INSERT.
        $this->scopedInsert(
            'kuickpay_reconciliation_runs',
            (int) ($vars['company_id'] ?? 0),
            $vars,
            self::FIELDS
        );

        return $this->Record->lastInsertId();
    }

    /**
     * Updates a reconciliation run, scoped to its owning company.
     *
     * Previously an UNSCOPED cross-tenant UPDATE (Story 5.5 gap): the
     * company_id predicate is now mandatory via scopedUpdate, so a run id can
     * never be edited outside its company.
     *
     * @param int $run_id The run ID
     * @param int $company_id The company ID scope
     * @param array $vars Run fields to update
     */
    public function edit(int $run_id, int $company_id, array $vars): void
    {
        $this->scopedUpdate(
            'kuickpay_reconciliation_runs',
            $company_id,
            $vars,
            self::FIELDS,
            [['id', '=', $run_id]]
        );
    }

    /**
     * Returns the resume cursor for the most recent aborted run of a given
     * trigger type.
     *
     * Scoped by $trigger_type (not hard-coded 'cron') so a non-cron caller of the
     * shared run() entry can never inherit a prior aborted cron run's cursor and
     * silently skip low-id eligible vouchers.
     *
     * @param int $company_id The company ID
     * @param string $trigger_type The run trigger type to resume within
     * @return int The resume cursor, or 0 when none applies
     */
    public function getResumeCursor(int $company_id, string $trigger_type = 'cron'): int
    {
        $run = $this->scopedSelect('kuickpay_reconciliation_runs', $company_id)
            ->where('trigger_type', '=', $trigger_type)
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
        $this->scopedSelect('kuickpay_reconciliation_runs', $company_id);
        $this->applyRunFilters($filters);

        return $this->Record->order($this->sanitizeOrderBy($order_by))
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
        $this->scopedSelect('kuickpay_reconciliation_runs', $company_id);
        $this->applyRunFilters($filters);

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
        return $this->scopedSelect('kuickpay_reconciliation_runs', $company_id)
            ->where('id', '=', $run_id)
            ->fetch();
    }

    /**
     * Applies the mandatory company scope and the allowlisted run filters.
     *
     * Company scope is applied UNCONDITIONALLY and never sourced from $filters.
     * Each optional filter is matched exactly against its source-of-truth const,
     * so a request value can never reach the query unvalidated.
     *
     * @param array $filters Allowlisted optional filters: trigger_type, status
     */
    private function applyRunFilters(array $filters): void
    {
        if (isset($filters['trigger_type']) && in_array($filters['trigger_type'], self::TRIGGER_TYPES, true)) {
            $this->Record->where('trigger_type', '=', $filters['trigger_type']);
        }

        if (isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $this->Record->where('status', '=', $filters['status']);
        }
    }

    /**
     * Reduces caller-provided ordering to known columns and directions.
     *
     * @param array $order_by Requested order fields
     * @return array Safe order fields
     */
    private function sanitizeOrderBy(array $order_by): array
    {
        $safe = [];
        foreach ($order_by as $field => $direction) {
            if (!is_string($field) || !in_array($field, self::ORDER_FIELDS, true)) {
                continue;
            }

            $direction = strtoupper((string) $direction);
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                continue;
            }

            $safe[$field] = $direction;
        }

        return $safe ?: ['date_started' => 'DESC'];
    }
}
