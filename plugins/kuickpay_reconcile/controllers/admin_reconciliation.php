<?php
/**
 * KuickPay Reconcile run-visibility controller
 *
 * Read-only / idempotent admin surface for reconciliation run summaries and
 * per-run drill-down. Performs no writes, no SOAP, no posting, and no state
 * transition; full sanitized evidence and the gated next actions live on the
 * linked admin_vouchers detail page (4.2/4.3).
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class AdminReconciliation extends KuickpayReconcileController
{
    /**
     * @var KuickPayVoucherListPresenter The pure presentation/allowlist seam
     */
    private $presenter;

    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();

        $this->requireLogin();

        // Only the runs model is loaded here; the item/audit read models are
        // loaded in detail() AFTER run ownership is confirmed.
        Loader::loadModels($this, ['KuickpayReconcile.KuickpayReconciliationRuns']);
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherListPresenter.php');

        $this->presenter = new KuickPayVoucherListPresenter();

        // The shared presenter returns AdminVouchers.* keys; Blesta only auto-
        // loads this controller's own language file, so load admin_vouchers too.
        Language::loadLang('admin_vouchers', null, dirname(__FILE__) . DS . '..' . DS . 'language' . DS);

        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    /**
     * Paginated, company-scoped list of reconciliation runs, newest first.
     *
     * Ships without request-driven filters this story (the model's $filters
     * param exists for forward-compat only), keeping the list lean — no filter
     * widget, no AJAX re-render.
     */
    public function index()
    {
        $company_id = (int) $this->company_id;

        $page = (isset($this->get[0]) && is_numeric($this->get[0])) ? (int) $this->get[0] : 1;

        $runs = $this->KuickpayReconciliationRuns->getListForCompany(
            $company_id,
            [],
            $page,
            ['date_started' => 'DESC']
        );
        $total_results = $this->KuickpayReconciliationRuns->getListCountForCompany($company_id, []);

        $this->set('runs', $runs);
        $this->set('presenter', $this->presenter);

        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/kuickpay_reconcile/admin_reconciliation/index/[p]/',
                'params' => [],
                'merge_get' => false,
            ]
        );
        $this->setPagination($this->get, $settings);
    }
}
