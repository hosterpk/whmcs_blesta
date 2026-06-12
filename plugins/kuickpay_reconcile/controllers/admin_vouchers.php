<?php

use Blesta\Core\Util\Input\Fields\InputFields;

/**
 * KuickPay Reconcile admin voucher list controller
 *
 * Read-only / idempotent admin surface for searching and filtering KuickPay
 * vouchers. Performs no writes, no SOAP, no posting, and no state transition.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class AdminVouchers extends KuickpayReconcileController
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

        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
    }

    /**
     * Searchable, sortable, paginated voucher list.
     *
     * Read-only: the Blesta filter widget submits via POST (Widget convention),
     * but nothing here mutates voucher state.
     */
    public function index()
    {
        $company_id = (int) $this->company_id;

        // Resolve sort/order from GET, validated against the presenter allowlist.
        // Guard against array injection before the typed presenter call.
        $sort_in = (isset($this->get['sort']) && is_string($this->get['sort'])) ? $this->get['sort'] : null;
        $order_in = (isset($this->get['order']) && is_string($this->get['order'])) ? $this->get['order'] : null;
        $sort = $this->presenter->allowedSort($sort_in, 'date_created');
        $order = $this->presenter->allowedOrder($order_in, 'desc');

        // Read filters from GET (pagination/Back) then POST (fresh apply wins).
        $raw_filters = [];
        $has_post_filters = false;
        if (isset($this->get['filters']) && is_array($this->get['filters'])) {
            $raw_filters = $this->get['filters'];
        }
        if (isset($this->post['filters']) && is_array($this->post['filters'])) {
            $has_post_filters = true;
            $raw_filters = $this->post['filters'];
        }
        $filters = $this->presenter->sanitizeFilters($raw_filters);
        $page = (
            !$has_post_filters
            && isset($this->get[0])
            && is_numeric($this->get[0])
        ) ? (int) $this->get[0] : 1;

        // Company scope is a dedicated, mandatory argument — never from request.
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
        $this->set('filters', $this->buildFilters($filters));
        $this->set('filter_vars', $filters);
        $this->set('presenter', $this->presenter);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order === 'asc' ? 'desc' : 'asc'));

        // Pagination params carry sort/order AND every active filter so the
        // selection survives page links and the browser Back button (URL-driven,
        // no session). merge_get is disabled because $this->get['filters'] is a
        // nested array that the naive pagination URL builder cannot encode.
        $params = ['sort' => $sort, 'order' => $order];
        foreach ($filters as $key => $value) {
            $params[rawurlencode('filters[' . $key . ']')] = rawurlencode((string) $value);
        }

        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/index/[p]/',
                'params' => $params,
                'merge_get' => false,
            ]
        );

        // Hand pagination a GET set without the nested filters array: filters are
        // already re-encoded as flat params above, and the pagination URL builder
        // concatenates values naively (it cannot stringify a nested array).
        $pagination_get = $this->get;
        unset($pagination_get['filters']);
        $this->setPagination($pagination_get, $settings);

        return $this->renderAjaxWidgetIfAsync($has_post_filters || isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Read-only voucher detail page with permission-gated diagnostics.
     *
     * GET only: performs no writes, no SOAP, no posting, and no state
     * transition. The fetch is company-scoped so a voucher ID outside the
     * authenticated staff company resolves to a safe not-found redirect and
     * never renders another company's row. The diagnostics section (sanitized
     * evidence + redacted audit history) is gated behind a separate plugin
     * permission; the audit query runs only when that permission is granted.
     */
    public function detail()
    {
        // Company scope is resolved explicitly and never read from the request.
        $company_id = (int) $this->company_id;

        // The id is the first route segment: detail/{id}/. ctype_digit is
        // deliberately stricter than the list's is_numeric — it rejects "1e3",
        // floats, and signs.
        $voucher_id = (isset($this->get[0]) && ctype_digit((string) $this->get[0]))
            ? (int) $this->get[0]
            : 0;

        // Company-scoped fetch. A missing id or a cross-company/absent voucher
        // both resolve to the same safe not-found outcome.
        $voucher = ($voucher_id > 0)
            ? $this->KuickpayVouchers->getForCompany($voucher_id, $company_id)
            : false;
        if ($voucher_id <= 0 || !$voucher) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.not_found', true), null, false);
            $this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/index/');
            return;
        }

        // Reuse 4.1's already company-scoped batched lookups (no new unscoped
        // queries): invoice mappings and the human-readable client code.
        $invoices = $this->KuickpayVoucherInvoices->getByVoucherIds([$voucher_id], $company_id)[$voucher_id] ?? [];
        $client_id = !empty($voucher->client_id) ? (int) $voucher->client_id : null;
        $client_code = $client_id !== null
            ? ($this->KuickpayVouchers->getClientCodes([$client_id], $company_id)[$client_id] ?? $voucher->client_id)
            : null;

        // Decode the already-sanitized normalized evidence, then reduce it to
        // the allowlisted known keys (AC7) before it reaches the view.
        $diagnostic = json_decode((string) $voucher->diagnostic_summary, true);
        if (!is_array($diagnostic)) {
            $diagnostic = [];
        }
        $diagnostic = $this->presenter->allowedDiagnosticFields($diagnostic);

        // Permission gate (AC8): only when the separate "view diagnostics"
        // permission is granted do we load the audit history. This must be an
        // exact-action check: Blesta's normal authorized() wildcard fallback
        // would let the existing `action => *` view permission satisfy
        // diagnostics on the same alias.
        $can_view_diagnostics = $this->canViewDiagnostics();
        $events = [];
        if ($can_view_diagnostics) {
            Loader::loadModels($this, ['KuickpayReconcile.KuickpayAuditEvents']);
            $events = $this->KuickpayAuditEvents->getByVoucher($voucher_id, $company_id);
        }

        $this->set('voucher', $voucher);
        $this->set('invoices', $invoices);
        $this->set('client_code', $client_code);
        $this->set('diagnostic', $diagnostic);
        $this->set('events', $events);
        $this->set('can_view_diagnostics', $can_view_diagnostics);
        $this->set('can_recheck', $this->staffGroupAllows('recheck'));
        $this->set('can_review', $this->staffGroupAllows('review'));
        $this->set('can_cancel', $this->staffGroupAllows('cancel'));
        $this->set('presenter', $this->presenter);
    }

    public function recheck()
    {
        $company_id = (int) $this->company_id;
        $voucher_id = $this->routeVoucherId();
        $detail_url = $this->detailUrl($voucher_id);

        if (empty($this->post)) {
            $this->redirect($detail_url);
            return;
        }

        $voucher = $this->voucherForAction($voucher_id, $company_id);
        if (!$voucher) {
            return;
        }

        if (!$this->staffGroupAllows('recheck')) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.acl_denied', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $prior_status = (string) $voucher->status;
        if (!in_array('recheck', KuickpayVouchers::ALLOWED_ACTIONS_BY_STATE[$prior_status] ?? [], true)) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.invalid_state', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $run_id = null;
        $outcome = 'failed';
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayReconcileService.php');
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayPostingService.php');

        if (in_array($prior_status, ['pending', 'retry'], true)) {
            $result = (new KuickPayReconcileService())->reconcileVoucher($company_id, $voucher_id);
            $run_id = $result['run_id'] ?? null;
            $outcome = $this->safeRecheckOutcome((string) ($result['status'] ?? 'failed'));

            $fresh = $this->KuickpayVouchers->getForCompany($voucher_id, $company_id);
            if ($fresh && (string) ($fresh->status ?? '') === 'confirmed_unposted') {
                $post_result = (new KuickPayPostingService())->postVoucher($company_id, $fresh);
                $post_outcome = (string) ($post_result['outcome'] ?? 'failed');
                $outcome = $this->safeRecheckOutcome($post_outcome === 'skipped' ? 'failed' : $post_outcome);
            }
        } elseif ($prior_status === 'confirmed_unposted') {
            $post_result = (new KuickPayPostingService())->postVoucher($company_id, $voucher);
            $post_outcome = (string) ($post_result['outcome'] ?? 'failed');
            $outcome = $this->safeRecheckOutcome($post_outcome === 'skipped' ? 'failed' : $post_outcome);
        }

        $this->recordAdminAudit('admin.rechecked', $company_id, $voucher_id, [
            'staff_id' => $this->staffId(),
            'prior_status' => $prior_status,
            'outcome' => $outcome,
        ], $run_id);

        $message = $this->recheckMessage($outcome);
        $this->flashMessage($message['type'], Language::_($message['key'], true), null, false);
        $this->redirect($detail_url);
    }

    public function review()
    {
        $this->noteTransitionAction('review', 'manual_review', 'admin.reviewed', 'AdminVouchers.!success.review');
    }

    public function cancel()
    {
        $this->noteTransitionAction('cancel', 'cancelled', 'admin.cancelled', 'AdminVouchers.!success.cancel');
    }

    /**
     * Checks the dedicated diagnostics permission without wildcard fallback.
     *
     * The plugin intentionally has both a general admin_vouchers `*` permission
     * for the page and a separate admin_vouchers `diagnostics` permission for
     * the sensitive section. MinPHP ACL treats `*` as a wildcard for arbitrary
     * actions, so this check inspects the current staff group's ACL entries and
     * requires an explicit diagnostics allow.
     *
     * @return bool True when the current staff group explicitly allows diagnostics
     */
    private function canViewDiagnostics(): bool
    {
        return $this->staffGroupAllows('diagnostics');
    }

    /**
     * Checks a dedicated admin_vouchers permission without wildcard fallback.
     *
     * @param string $action The exact ACL action token
     * @return bool True when the current staff group explicitly allows the action
     */
    private function staffGroupAllows(string $action): bool
    {
        Loader::loadComponents($this, ['Acl']);
        Loader::loadModels($this, ['StaffGroups']);

        $staff_group = $this->StaffGroups->getStaffGroupByStaff(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );
        if (!$staff_group) {
            return false;
        }

        $access_list = $this->Acl->getAccessList(
            'staff_group_' . $staff_group->id,
            'kuickpay_reconcile.admin_vouchers'
        );
        foreach ($access_list as $access) {
            if (($access->action ?? null) !== $action) {
                continue;
            }

            return ($access->permission ?? null) === 'allow';
        }

        return false;
    }

    private function noteTransitionAction(string $action, string $new_status, string $event, string $successKey): void
    {
        $company_id = (int) $this->company_id;
        $voucher_id = $this->routeVoucherId();
        $detail_url = $this->detailUrl($voucher_id);

        if (empty($this->post)) {
            $this->redirect($detail_url);
            return;
        }

        $voucher = $this->voucherForAction($voucher_id, $company_id);
        if (!$voucher) {
            return;
        }

        if (!$this->staffGroupAllows($action)) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.acl_denied', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $note = trim((string) ($this->post['admin_note'] ?? ''));
        if ($note === '') {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.note_required', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $prior_status = (string) $voucher->status;
        if (!in_array($action, KuickpayVouchers::ALLOWED_ACTIONS_BY_STATE[$prior_status] ?? [], true)) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.invalid_state', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $admin_notes = $this->prependAdminNote((string) ($voucher->admin_notes ?? ''), $note, $this->staffId());
        $transitioned = $this->KuickpayVouchers->transition(
            $voucher_id,
            $company_id,
            $new_status,
            KuickpayVouchers::ALLOWED_FROM_BY_ACTION[$action] ?? [],
            ['admin_notes' => $admin_notes]
        );

        if (!$transitioned) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.invalid_state', true), null, false);
            $this->redirect($detail_url);
            return;
        }

        $this->recordAdminAudit($event, $company_id, $voucher_id, [
            'staff_id' => $this->staffId(),
            'prior_status' => $prior_status,
        ]);

        $this->flashMessage('message', Language::_($successKey, true), null, false);
        $this->redirect($detail_url);
    }

    private function routeVoucherId(): int
    {
        return (isset($this->get[0]) && ctype_digit((string) $this->get[0]))
            ? (int) $this->get[0]
            : 0;
    }

    private function detailUrl(int $voucher_id): string
    {
        if ($voucher_id <= 0) {
            return $this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/index/';
        }

        return $this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/detail/' . $voucher_id . '/';
    }

    private function voucherForAction(int $voucher_id, int $company_id)
    {
        $voucher = ($voucher_id > 0)
            ? $this->KuickpayVouchers->getForCompany($voucher_id, $company_id)
            : false;
        if ($voucher_id <= 0 || !$voucher) {
            $this->flashMessage('error', Language::_('AdminVouchers.!error.not_found', true), null, false);
            $this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/index/');
            return false;
        }

        return $voucher;
    }

    private function prependAdminNote(string $existing, string $note, int $staff_id): string
    {
        $line = '[' . date('c') . '] (staff #' . $staff_id . ') ' . $note;

        return $existing === '' ? $line : $line . "\n" . $existing;
    }

    private function staffId(): int
    {
        return (int) $this->Session->read('blesta_staff_id');
    }

    private function recordAdminAudit(
        string $event,
        int $company_id,
        int $voucher_id,
        array $payload,
        ?int $run_id = null
    ): void {
        try {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayAuditService.php');
            $context = [
                'company_id' => $company_id,
                'voucher_id' => $voucher_id,
                'payload' => $payload,
            ];
            if ($run_id !== null) {
                $context['run_id'] = $run_id;
            }
            (new KuickPayAuditService())->record($event, $context);
        } catch (Throwable $e) {
            // Admin decisions should not fail because the audit write failed.
        }
    }

    private function safeRecheckOutcome(string $outcome): string
    {
        $allowed = [
            'posted',
            'already_posted',
            'confirmed_unposted',
            'pending',
            'retry',
            'expired',
            'manual_review',
            'failed',
            'unavailable',
            'skipped',
        ];

        return in_array($outcome, $allowed, true) ? $outcome : 'failed';
    }

    private function recheckMessage(string $outcome): array
    {
        $messages = [
            'posted' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_posted'],
            'already_posted' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_already_posted'],
            'confirmed_unposted' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_confirmed'],
            'pending' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_pending'],
            'manual_review' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_manual_review'],
            'retry' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_retry'],
            'expired' => ['type' => 'message', 'key' => 'AdminVouchers.!success.recheck_expired'],
            'unavailable' => ['type' => 'error', 'key' => 'AdminVouchers.!error.recheck_unreachable'],
            'skipped' => ['type' => 'error', 'key' => 'AdminVouchers.!error.recheck_unavailable'],
            'failed' => ['type' => 'error', 'key' => 'AdminVouchers.!error.recheck_failed'],
        ];

        return $messages[$outcome] ?? $messages['failed'];
    }

    /**
     * Builds the InputFields filter widget for the list.
     *
     * Returns an InputFields object (Widget::setFilters is type-hinted on it; a
     * plain array would fatal). Every field name is filters[<key>] so the
     * controller can read them back from POST/GET.
     *
     * @param array $filter_vars The active, sanitized filter values (for defaults)
     * @return InputFields The filter field set
     */
    private function buildFilters(array $filter_vars)
    {
        $fields = new InputFields();

        // Status select — options sourced from the model's single source of
        // truth, labelled through the presenter's closed allowlist.
        $status_options = ['' => Language::_('AdminVouchers.filter.any', true)];
        foreach (KuickpayVouchers::getStatuses() as $status) {
            $status_options[$status] = Language::_($this->presenter->labelKeyFor($status), true);
        }
        $status = $fields->label(Language::_('AdminVouchers.filter.status', true), 'status');
        $status->attach(
            $fields->fieldSelect(
                'filters[status]',
                $status_options,
                $filter_vars['status'] ?? null,
                ['id' => 'status', 'class' => 'form-control']
            )
        );
        $fields->setField($status);

        // Text filters
        $text_fields = [
            'client_id' => ['label' => 'AdminVouchers.filter.client_id', 'placeholder' => 'AdminVouchers.filter.client_id_placeholder'],
            'consumer_number' => ['label' => 'AdminVouchers.filter.consumer_number', 'placeholder' => null],
            'registration_number' => ['label' => 'AdminVouchers.filter.registration_number', 'placeholder' => null],
            'kuickpay_reference' => ['label' => 'AdminVouchers.filter.kuickpay_reference', 'placeholder' => null],
            'amount' => ['label' => 'AdminVouchers.filter.amount', 'placeholder' => 'AdminVouchers.filter.amount_placeholder'],
            'invoice_id' => ['label' => 'AdminVouchers.filter.invoice_id', 'placeholder' => null],
        ];
        foreach ($text_fields as $key => $config) {
            $attributes = ['id' => $key, 'class' => 'form-control'];
            if ($config['placeholder'] !== null) {
                $attributes['placeholder'] = Language::_($config['placeholder'], true);
            }
            $label = $fields->label(Language::_($config['label'], true), $key);
            $label->attach($fields->fieldText('filters[' . $key . ']', $filter_vars[$key] ?? null, $attributes));
            $fields->setField($label);
        }

        // Created-date range
        foreach (['date_from', 'date_to'] as $key) {
            $label = $fields->label(Language::_('AdminVouchers.filter.' . $key, true), $key);
            $label->attach(
                $fields->fieldText(
                    'filters[' . $key . ']',
                    $filter_vars[$key] ?? null,
                    [
                        'id' => $key,
                        'class' => 'date form-control',
                        'placeholder' => Language::_('AdminVouchers.filter.date_placeholder', true),
                    ]
                )
            );
            $fields->setField($label);
        }

        // "Has Blesta transaction" boolean toggle → blesta_transaction_id IS NOT NULL
        $has_transaction = $fields->label(
            Language::_('AdminVouchers.filter.has_blesta_transaction', true),
            'has_blesta_transaction'
        );
        $has_transaction->attach(
            $fields->fieldCheckbox(
                'filters[has_blesta_transaction]',
                '1',
                !empty($filter_vars['has_blesta_transaction']),
                ['id' => 'has_blesta_transaction', 'class' => 'form-check-input']
            )
        );
        $fields->setField($has_transaction);

        // Bind the date pickers for the created-date range inputs.
        $fields->setHtml('
            <script type="text/javascript">
                (function() {
                    function bindDates() {
                        if (typeof blestaBindDatePicker === "function") {
                            blestaBindDatePicker();
                        }
                    }
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", bindDates);
                    } else {
                        bindDates();
                    }
                })();
            </script>
        ');

        return $fields;
    }
}
