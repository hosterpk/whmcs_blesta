<?php
/**
 * KuickPay Reconcile admin controller
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class AdminMain extends KuickpayReconcileController
{
    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();

        $this->requireLogin();

        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    /**
     * Shows the minimal bulk reconciliation trigger.
     */
    public function index()
    {
        $this->set('vars', (object) []);
    }

    /**
     * Runs a date-based bulk reconciliation.
     */
    public function run()
    {
        if (empty($this->post)) {
            $this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_main/index/');
        }

        $run_date = trim((string) ($this->post['run_date'] ?? ''));
        if (!$this->validRunDate($run_date)) {
            $this->setMessage(
                'error',
                Language::_('AdminMain.!error.run_date_format', true),
                false,
                null,
                false
            );
            $this->set('vars', (object) $this->post);
            $this->action = 'index';
            return;
        }

        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayReconcileService.php');

        $service = new KuickPayReconcileService();
        $result = $service->runBulk((int) $this->company_id, $run_date);

        if (($result['status'] ?? null) === 'completed') {
            $counts = $result['counts'] ?? [];
            $this->flashMessage(
                'message',
                Language::_(
                    'AdminMain.!success.bulk_completed',
                    true,
                    (int) ($result['run_id'] ?? 0),
                    (int) ($counts['total_checked'] ?? 0),
                    (int) ($counts['total_unmatched'] ?? 0),
                    (int) ($counts['total_manual_review'] ?? 0)
                ),
                null,
                false
            );
        } elseif (($result['status'] ?? null) === 'skipped') {
            $this->flashMessage(
                'error',
                Language::_('AdminMain.!error.bulk_skipped', true, (string) ($result['reason'] ?? 'unknown')),
                null,
                false
            );
        } else {
            $this->flashMessage(
                'error',
                Language::_('AdminMain.!error.bulk_failed', true, (string) ($result['status'] ?? 'failed')),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_main/index/');
    }

    /**
     * Validates a YYYY-MM-DD run date.
     *
     * @param string $run_date Requested run date
     * @return bool True when valid
     */
    private function validRunDate(string $run_date): bool
    {
        $date = DateTime::createFromFormat('!Y-m-d', $run_date);

        return $date && $date->format('Y-m-d') === $run_date;
    }
}
