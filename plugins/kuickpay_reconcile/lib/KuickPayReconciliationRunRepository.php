<?php
/**
 * KuickPay reconciliation run repository
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayReconciliationRunRepository
{
    public function __construct()
    {
        Loader::loadModels($this, ['KuickpayReconcile.KuickpayReconciliationRuns']);
    }

    public function open(int $company_id, string $trigger_type, int $cursor): int
    {
        return (int) $this->KuickpayReconciliationRuns->add([
            'company_id' => $company_id,
            'trigger_type' => $trigger_type,
            'status' => 'running',
            'cursor' => $cursor,
            'date_started' => date('Y-m-d H:i:s'),
        ]);
    }

    public function openBulk(int $company_id, string $run_date): int
    {
        return (int) $this->KuickpayReconciliationRuns->add([
            'company_id' => $company_id,
            'trigger_type' => 'bulk',
            'status' => 'running',
            'run_date' => $run_date,
            'cursor' => 0,
            'date_started' => date('Y-m-d H:i:s'),
        ]);
    }

    public function close(int $run_id, int $company_id, string $status, array $counts, int $cursor, string $summary): void
    {
        $this->KuickpayReconciliationRuns->edit($run_id, $company_id, array_merge($counts, [
            'status' => $status,
            'cursor' => $status === 'completed' ? 0 : $cursor,
            'date_completed' => date('Y-m-d H:i:s'),
            'summary' => $summary,
        ]));
    }

    public function updateCursor(int $run_id, int $company_id, int $cursor): void
    {
        $this->KuickpayReconciliationRuns->edit($run_id, $company_id, ['cursor' => $cursor]);
    }

    public function getResumeCursor(int $company_id, string $trigger_type = 'cron'): int
    {
        return $this->KuickpayReconciliationRuns->getResumeCursor($company_id, $trigger_type);
    }
}
