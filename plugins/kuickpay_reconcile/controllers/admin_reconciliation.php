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
     * @var int Bounded number of item rows shown on the run-detail page (NFR7).
     *  The detail view compares the true item count against this cap to render
     *  an honest "showing first N of M" truncation notice.
     */
    private const ITEM_DISPLAY_LIMIT = 500;

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
        if (!$this->requireGetOnly($this->base_uri . 'plugin/kuickpay_reconcile/admin_reconciliation/index/')) {
            return;
        }

        if (!$this->requirePagePermission('kuickpay_reconcile.admin_reconciliation', 'AdminReconciliation.!error.acl_denied')) {
            return;
        }

        $company_id = (int) $this->company_id;

        $page = $this->positiveRouteInt($this->get[0] ?? null, 1);

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

    /**
     * Read-only run detail with item + audit-only exception drill-down.
     *
     * Company scope is enforced by fetching the run via getForCompany() FIRST; a
     * missing or cross-company run id resolves to a safe not-found redirect and
     * never renders another company's data. Only after ownership is confirmed
     * are the item/audit read models loaded and queried (themselves
     * company-scoped). Writes nothing — the gated next actions and full
     * sanitized evidence live on the linked admin_vouchers detail page (4.2/4.3).
     */
    public function detail()
    {
        if (!$this->requireGetOnly($this->base_uri . 'plugin/kuickpay_reconcile/admin_reconciliation/index/')) {
            return;
        }

        if (!$this->requirePagePermission('kuickpay_reconcile.admin_reconciliation', 'AdminReconciliation.!error.acl_denied')) {
            return;
        }

        $company_id = (int) $this->company_id;

        // Rejects "1e3", floats, signs, zero, and oversized integers.
        $run_id = $this->positiveRouteInt($this->get[0] ?? null);

        $run = ($run_id > 0)
            ? $this->KuickpayReconciliationRuns->getForCompany($run_id, $company_id)
            : false;
        if ($run_id <= 0 || !$run) {
            $this->flashMessage('error', Language::_('AdminReconciliation.!error.not_found', true), null, false);
            $this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_reconciliation/index/');
            return;
        }

        // Ownership confirmed: only now load the run-scoped read models (these
        // are intentionally NOT loaded in preAction()).
        Loader::loadModels($this, [
            'KuickpayReconcile.KuickpayReconciliationItems',
            'KuickpayReconcile.KuickpayAuditEvents',
        ]);

        $items = $this->KuickpayReconciliationItems->getByRun($run_id, $company_id, self::ITEM_DISPLAY_LIMIT);
        $item_total = $this->KuickpayReconciliationItems->getCountByRun($run_id, $company_id);
        // Audit-only bulk exceptions (unmatched/duplicate write no item row): the
        // only per-row evidence behind the run's total_unmatched count (AC3).
        $audit_exceptions = $this->KuickpayAuditEvents->getByRun($run_id, $company_id, self::ITEM_DISPLAY_LIMIT);
        $audit_total = $this->KuickpayAuditEvents->getCountByRun($run_id, $company_id);

        $this->set('run', $run);
        $this->set('items', $items);
        $this->set('item_total', $item_total);
        $this->set('item_limit', self::ITEM_DISPLAY_LIMIT);
        $this->set('audit_exceptions', $audit_exceptions);
        $this->set('audit_total', $audit_total);
        $this->set('audit_limit', self::ITEM_DISPLAY_LIMIT);
        $this->set('presenter', $this->presenter);
    }
}
