<?php
/**
 * KuickPay Reconcile manual-review queue controller
 *
 * Read-only / idempotent admin surface listing only `manual_review` vouchers
 * so unsafe records are visible and actionable without mixing in posted/normal
 * records. Performs no writes, no SOAP, no posting, and no state transition;
 * every mutation stays on the existing admin_vouchers action endpoints (4.3),
 * to which each row links.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class AdminManualReview extends KuickpayReconcileController
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

        // Models and the presenter are NOT auto-loaded for this controller.
        Loader::loadModels($this, [
            'KuickpayReconcile.KuickpayVouchers',
            'KuickpayReconcile.KuickpayVoucherInvoices',
        ]);
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherListPresenter.php');

        $this->presenter = new KuickPayVoucherListPresenter();

        // The shared presenter returns AdminVouchers.* keys; Blesta only auto-
        // loads this controller's own language file, so load admin_vouchers too.
        Language::loadLang('admin_vouchers', null, dirname(__FILE__) . DS . '..' . DS . 'language' . DS);

        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    /**
     * Paginated, company-scoped list of vouchers awaiting manual review.
     *
     * Read-only: reuses the already-allowlisted `manual_review` status filter on
     * KuickpayVouchers::getList(); writes nothing. Each row's gated "next
     * allowed action" links to the admin_vouchers detail page where the 4.3
     * action forms live.
     */
    public function index()
    {
        if (!$this->requireGetOnly($this->base_uri . 'plugin/kuickpay_reconcile/admin_manual_review/index/')) {
            return;
        }

        $company_id = (int) $this->company_id;

        // Default sort: most-recently-checked first. date_last_checked is
        // nullable and MySQL sorts NULLs first under DESC, so never-checked
        // manual_review rows float to the top — acceptable here (they still need
        // attention). Sort/order are still allowlist-validated for forward-compat.
        $sort_in = (isset($this->get['sort']) && is_string($this->get['sort'])) ? $this->get['sort'] : null;
        $order_in = (isset($this->get['order']) && is_string($this->get['order'])) ? $this->get['order'] : null;
        $sort = $this->presenter->allowedSort($sort_in, 'date_last_checked');
        $order = $this->presenter->allowedOrder($order_in, 'desc');

        $page = $this->positiveRouteInt($this->get[0] ?? null, 1);

        // The queue is pre-scoped to manual_review; the status filter is already
        // allowlisted in applyListFilters(), so this is not a new query path.
        $filters = ['status' => 'manual_review'];
        $vouchers = $this->KuickpayVouchers->getList($company_id, $filters, $page, [$sort => $order]);
        $total_results = $this->KuickpayVouchers->getListCount($company_id, $filters);

        // Batch-resolve invoice mappings and client codes for the page (no N+1).
        $voucher_ids = [];
        $client_ids = [];
        foreach ($vouchers as $voucher) {
            $voucher_ids[] = (int) $voucher->id;
            $client_ids[] = (int) $voucher->client_id;
        }
        $invoices_by_voucher = $this->KuickpayVoucherInvoices->getByVoucherIds($voucher_ids, $company_id);
        $clients_by_id = $this->KuickpayVouchers->getClientCodes(
            array_values(array_unique($client_ids)),
            $company_id
        );

        $this->set('vouchers', $vouchers);
        $this->set('invoices_by_voucher', $invoices_by_voucher);
        $this->set('clients_by_id', $clients_by_id);
        $this->set('presenter', $this->presenter);
        // Per-action gating booleans (single source of truth: admin_vouchers
        // permissions, since the queue links to admin_vouchers action forms).
        $this->set('can_recheck', $this->staffGroupAllows('recheck'));
        $this->set('can_review', $this->staffGroupAllows('review'));
        $this->set('can_cancel', $this->staffGroupAllows('cancel'));

        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/kuickpay_reconcile/admin_manual_review/index/[p]/',
                'params' => ['sort' => $sort, 'order' => $order],
                'merge_get' => false,
            ]
        );
        $this->setPagination($this->get, $settings);
    }
}
