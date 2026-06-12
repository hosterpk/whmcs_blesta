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
     * @var int Maximum bulk run_date look-back, in days.
     */
    private const MAX_LOOKBACK_DAYS = 365;

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
        $date_error = $this->runDateError($run_date);
        if ($date_error !== null) {
            $this->setMessage(
                'error',
                Language::_($date_error, true),
                false,
                null,
                false
            );
            $this->set('vars', (object) $this->post);
            $this->action = 'index';
            return;
        }

        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayReconcileService.php');

        $dependencies = [];
        try {
            $dependencies['logger'] = $this->getFromContainer('logger');
        } catch (Throwable $e) {
            // Missing logger falls back to no operational SOAP logs.
        }

        $service = new KuickPayReconcileService($dependencies);
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
     * Validates and bounds a YYYY-MM-DD bulk run date.
     *
     * Returns null when the date is valid, otherwise the specific localized
     * error key so run() can surface a distinct message per failure (malformed,
     * future, or beyond the look-back window) instead of one generic format
     * error. Comparison is date-only: createFromFormat('!Y-m-d') zeroes the time
     * and the bounds are computed at midnight, so a same-day run date is allowed.
     *
     * @param string $run_date Requested run date
     * @return string|null The localized error key, or null when valid
     */
    private function runDateError(string $run_date): ?string
    {
        $date = DateTime::createFromFormat('!Y-m-d', $run_date);
        if (!$date || $date->format('Y-m-d') !== $run_date) {
            return 'AdminMain.!error.run_date_format';
        }

        $today = new DateTime('today');
        if ($date > $today) {
            return 'AdminMain.!error.run_date_future';
        }

        $oldest = (clone $today)->modify('-' . self::MAX_LOOKBACK_DAYS . ' days');
        if ($date < $oldest) {
            return 'AdminMain.!error.run_date_too_old';
        }

        return null;
    }
}
