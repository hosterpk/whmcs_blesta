<?php
/**
 * KuickPay vouchers model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickpayVouchers extends KuickpayReconcileModel
{
    private const STATUSES = [
        'pending',
        'retry',
        'confirmed_unposted',
        'posted',
        'failed',
        'expired',
        'manual_review',
        'cancelled',
    ];

    private const FIELDS = [
        'company_id',
        'gateway_id',
        'client_id',
        'currency',
        'amount',
        'status',
        'registration_number',
        'consumer_number',
        'date_due',
        'date_expires',
        'date_created',
        'date_updated',
        'date_posted',
        'date_paid',
        'date_last_checked',
        'retry_count',
        'error_class',
        'raw_status',
        'evidence_hash',
        'kuickpay_reference',
        'blesta_transaction_id',
        'diagnostic_summary',
        'admin_notes',
    ];

    public const ALLOWED_ACTIONS_BY_STATE = [
        'pending' => ['recheck', 'review', 'cancel'],
        'retry' => ['recheck', 'review', 'cancel'],
        'confirmed_unposted' => ['recheck', 'review'],
        'posted' => [],
        'failed' => ['review', 'cancel'],
        'expired' => ['review', 'cancel'],
        'manual_review' => ['cancel'],
        'cancelled' => [],
    ];

    public const ALLOWED_FROM_BY_ACTION = [
        'recheck' => ['pending', 'retry', 'confirmed_unposted'],
        'review' => ['pending', 'retry', 'failed', 'expired', 'confirmed_unposted'],
        'cancel' => ['pending', 'retry', 'failed', 'expired', 'manual_review'],
    ];

    /**
     * Adds a voucher.
     *
     * @param array $vars Voucher fields
     * @return mixed The voucher ID, or void on validation error
     */
    public function add(array $vars)
    {
        $now = date('Y-m-d H:i:s');
        $vars['date_created'] = $vars['date_created'] ?? $now;
        $vars['date_updated'] = $vars['date_updated'] ?? $now;

        $this->Input->setRules($this->getRules());
        if (!$this->Input->validates($vars)) {
            return;
        }

        $this->Record->insert('kuickpay_vouchers', $vars, self::FIELDS);

        if ($this->Input->errors()) {
            return;
        }

        return $this->Record->lastInsertId();
    }

    /**
     * Updates an existing voucher.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @param array $vars Voucher fields
     */
    public function edit(int $voucher_id, int $company_id, array $vars)
    {
        unset($vars['company_id']);

        $vars['date_updated'] = date('Y-m-d H:i:s');

        $fields = array_values(array_intersect(array_keys($vars), self::FIELDS));
        if (empty($fields)) {
            return;
        }

        $this->Record
            ->where('id', '=', $voucher_id)
            ->where('company_id', '=', $company_id)
            ->update('kuickpay_vouchers', $vars, $fields);
    }

    /**
     * Fetches a voucher by ID.
     *
     * @param int $voucher_id The voucher ID
     * @return mixed The voucher row, or false when absent
     */
    public function get(int $voucher_id)
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('id', '=', $voucher_id)
            ->fetch();
    }

    /**
     * Fetches a voucher by ID, scoped to a company.
     *
     * Company-scoped counterpart to get(): the detail page MUST use this so a
     * voucher ID outside the authenticated staff company resolves to a safe
     * "not found" (false), never another company's row. Mirrors the
     * getByConsumerNumber() shape; the unscoped get() is reserved for the
     * already-scoped reconcile/posting callers.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @return mixed The voucher row, or false when absent or out of company scope
     */
    public function getForCompany(int $voucher_id, int $company_id)
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('id', '=', $voucher_id)
            ->where('company_id', '=', $company_id)
            ->fetch();
    }

    /**
     * Fetches a voucher by consumer number.
     *
     * @param string $consumer_number The KuickPay consumer number
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getByConsumerNumber(string $consumer_number, int $company_id)
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('consumer_number', '=', $consumer_number)
            ->where('company_id', '=', $company_id)
            ->fetch();
    }

    /**
     * Fetches a voucher by registration number.
     *
     * @param string $registration_number The KuickPay registration number
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getByRegistrationNumber(string $registration_number, int $company_id)
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('registration_number', '=', $registration_number)
            ->where('company_id', '=', $company_id)
            ->fetch();
    }

    /**
     * Fetches the pending voucher linked to an invoice.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getPendingByInvoiceId(int $invoice_id, int $company_id)
    {
        return $this->Record->select(['kuickpay_vouchers.*'])
            ->from('kuickpay_vouchers')
            ->innerJoin(
                'kuickpay_voucher_invoices',
                'kuickpay_voucher_invoices.voucher_id',
                '=',
                'kuickpay_vouchers.id',
                false
            )
            ->where('kuickpay_voucher_invoices.invoice_id', '=', $invoice_id)
            ->where('kuickpay_vouchers.company_id', '=', $company_id)
            ->where('kuickpay_vouchers.status', '=', 'pending')
            ->fetch();
    }

    /**
     * Fetches the pending voucher whose linked invoice set exactly matches the IDs.
     *
     * @param array $invoice_ids Sorted distinct invoice IDs
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getPendingByInvoiceSet(array $invoice_ids, int $company_id)
    {
        $invoice_ids = array_values(array_unique(array_map('intval', $invoice_ids)));
        sort($invoice_ids, SORT_NUMERIC);
        if (empty($invoice_ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));
        $count = count($invoice_ids);
        $sub_query = 'SELECT voucher_id FROM kuickpay_voucher_invoices'
            . ' GROUP BY voucher_id'
            . ' HAVING COUNT(*) = ?'
            . ' AND SUM(CASE WHEN invoice_id IN (' . $placeholders . ') THEN 1 ELSE 0 END) = ?';
        $values = array_merge([$count], $invoice_ids, [$count]);

        return $this->Record->select(['kuickpay_vouchers.*'])
            ->from('kuickpay_vouchers')
            ->appendValues($values)
            ->innerJoin(
                [$sub_query => 'matched_invoice_set'],
                'matched_invoice_set.voucher_id',
                '=',
                'kuickpay_vouchers.id',
                false
            )
            ->where('kuickpay_vouchers.company_id', '=', $company_id)
            ->where('kuickpay_vouchers.status', '=', 'pending')
            ->fetch();
    }

    /**
     * Fetches the most recent voucher linked to an invoice, regardless of status.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getLatestByInvoiceId(int $invoice_id, int $company_id)
    {
        return $this->Record->select(['kuickpay_vouchers.*'])
            ->from('kuickpay_vouchers')
            ->innerJoin(
                'kuickpay_voucher_invoices',
                'kuickpay_voucher_invoices.voucher_id',
                '=',
                'kuickpay_vouchers.id',
                false
            )
            ->where('kuickpay_voucher_invoices.invoice_id', '=', $invoice_id)
            ->where('kuickpay_vouchers.company_id', '=', $company_id)
            ->order(['kuickpay_vouchers.id' => 'DESC'])
            ->limit(1)
            ->fetch();
    }

    /**
     * Fetches an active voucher with a confirmed KuickPay reference.
     *
     * @param string $reference The KuickPay transaction reference
     * @param int $company_id The company ID
     * @param int $exclude_voucher_id Voucher ID to exclude
     * @return mixed The voucher row, or false when absent
     */
    public function findActiveByKuickpayReference(string $reference, int $company_id, int $exclude_voucher_id = 0)
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('kuickpay_reference', '=', $reference)
            ->where('company_id', '=', $company_id)
            ->where('status', 'in', ['confirmed_unposted', 'posted'])
            ->where('id', '!=', $exclude_voucher_id)
            ->where('kuickpay_reference', '!=', null)
            ->where('kuickpay_reference', '!=', '')
            ->fetch();
    }

    /**
     * Fetches an active voucher linked to an invoice.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @param int $exclude_voucher_id Voucher ID to exclude
     * @return mixed The voucher row, or false when absent
     */
    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $exclude_voucher_id = 0)
    {
        return $this->Record->select(['kuickpay_vouchers.*'])
            ->from('kuickpay_vouchers')
            ->innerJoin(
                'kuickpay_voucher_invoices',
                'kuickpay_voucher_invoices.voucher_id',
                '=',
                'kuickpay_vouchers.id',
                false
            )
            ->where('kuickpay_voucher_invoices.invoice_id', '=', $invoice_id)
            ->where('kuickpay_vouchers.company_id', '=', $company_id)
            ->where('kuickpay_vouchers.status', 'in', ['confirmed_unposted', 'posted'])
            ->where('kuickpay_vouchers.id', '!=', $exclude_voucher_id)
            ->limit(1)
            ->fetch();
    }

    /**
     * Returns the canonical set of voucher statuses.
     *
     * Exposes the private STATUSES allowlist so the admin controller can build
     * status-select options without leaking the constant. The pure presenter
     * keeps its own copy (it cannot load this DB-backed model at unit time).
     *
     * @return array The canonical voucher statuses
     */
    public static function getStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * Fetches a page of company-scoped vouchers matching the allowlisted filters.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted filters (see applyListFilters)
     * @param int $page The page number
     * @param array $order_by The order fields
     * @return array Voucher rows
     */
    public function getList(
        int $company_id,
        array $filters = [],
        int $page = 1,
        array $order_by = ['date_created' => 'DESC']
    ): array {
        $this->Record->select()->from('kuickpay_vouchers');
        $this->applyListFilters($company_id, $filters);

        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Counts company-scoped vouchers matching the allowlisted filters.
     *
     * Shares applyListFilters() with getList() so the count can never drift
     * from the page it paginates.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted filters (see applyListFilters)
     * @return int The total matching rows
     */
    public function getListCount(int $company_id, array $filters = []): int
    {
        $this->Record->select()->from('kuickpay_vouchers');
        $this->applyListFilters($company_id, $filters);

        return $this->Record->numResults();
    }

    /**
     * Applies the mandatory company scope and the allowlisted list filters.
     *
     * Company scope is applied UNCONDITIONALLY and never sourced from $filters,
     * so a caller can never omit it and leak another company's vouchers. Each
     * remaining filter is matched per the FR24 filter map; request values reach
     * the query only as bound parameters or as integer-cast subqueries.
     *
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @param array $filters Allowlisted filters: status, client_id,
     *  consumer_number, registration_number, kuickpay_reference, amount,
     *  invoice_id, date_from, date_to, has_blesta_transaction
     */
    private function applyListFilters(int $company_id, array $filters): void
    {
        // Mandatory tenant scope — never from request input.
        $this->Record->where('company_id', '=', $company_id);

        // Status: exact, validated against the model's own allowlist.
        if (isset($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $this->Record->where('status', '=', $filters['status']);
        }

        // Client: exact integer id.
        if (isset($filters['client_id']) && ctype_digit((string) $filters['client_id']) && (int) $filters['client_id'] > 0) {
            $this->Record->where('client_id', '=', (int) $filters['client_id']);
        }

        // Partial (LIKE) identity matches.
        foreach (['consumer_number', 'registration_number', 'kuickpay_reference'] as $like_field) {
            if (isset($filters[$like_field]) && $filters[$like_field] !== '') {
                $this->Record->like($like_field, '%' . addcslashes($filters[$like_field], '%_\\') . '%');
            }
        }

        // Amount: exact match on a normalized decimal string (never float, never range).
        if (isset($filters['amount']) && $filters['amount'] !== '') {
            $this->Record->where('amount', '=', $this->normalizeAmountFilter((string) $filters['amount']));
        }

        // Created-date range (date_created BETWEEN date_from .. date_to inclusive).
        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $this->Record->where('date_created', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $date_to = $filters['date_to'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
                $date_to .= ' 23:59:59';
            }
            $this->Record->where('date_created', '<=', $date_to);
        }

        // "Has Blesta transaction" toggle → blesta_transaction_id IS NOT NULL.
        if (!empty($filters['has_blesta_transaction'])) {
            $this->Record->where('blesta_transaction_id', '!=', null);
        }

        // Invoice id: EXISTS via an id IN (subquery) on the links table to avoid
        // row multiplication. The id is integer-cast and inlined (injection-safe),
        // which also sidesteps the bound-value ordering hazard of a parametrized
        // subquery placed mid-WHERE.
        if (isset($filters['invoice_id']) && ctype_digit((string) $filters['invoice_id']) && (int) $filters['invoice_id'] > 0) {
            $invoice_id = (int) $filters['invoice_id'];
            $this->Record->where(
                'kuickpay_vouchers.id',
                'in',
                ['SELECT voucher_id FROM kuickpay_voucher_invoices WHERE invoice_id = ' . $invoice_id],
                false,
                false
            );
        }
    }

    /**
     * Normalizes a user-entered amount to the canonical stored decimal string.
     *
     * Mirrors KuickPayVoucherReferenceService::normalizeAmount so the filter
     * compares like-for-like against the stored varchar amount: strip commas,
     * drop leading zeros, and pad/truncate to exactly two decimal places. A
     * non-numeric value is returned trimmed (it simply matches nothing).
     *
     * @param string $amount The raw amount filter value
     * @return string The normalized decimal string
     */
    private function normalizeAmountFilter(string $amount): string
    {
        $amount = trim($amount);
        $normalized = str_replace(',', '', $amount);

        // Normalize a leading decimal point (e.g. ".50" -> "0.50").
        if (strpos($normalized, '.') === 0) {
            $normalized = '0' . $normalized;
        }

        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return $amount;
        }

        $parts = explode('.', $normalized, 2);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') {
            $integer = '0';
        }
        $raw_fraction = $parts[1] ?? '';
        $extra_fraction = substr($raw_fraction, 2);
        if ($extra_fraction !== '' && trim($extra_fraction, '0') !== '') {
            return $amount;
        }
        $fraction = substr(str_pad($raw_fraction, 2, '0'), 0, 2);

        return $integer . '.' . $fraction;
    }

    /**
     * Resolves human-readable client codes for a set of client IDs.
     *
     * One company-scoped query selecting only the computed id_code (no contact
     * PII). id_code is REPLACE(id_format, '{num}', id_value) — a computed
     * expression, never a selectable column — so it is built here rather than
     * joined into the voucher query.
     *
     * @param array $client_ids The client IDs to resolve
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @return array Map of [client_id => id_code]
     */
    public function getClientCodes(array $client_ids, int $company_id): array
    {
        $client_ids = array_values(array_unique(array_map('intval', $client_ids)));
        if (empty($client_ids)) {
            return [];
        }

        $rows = $this->Record
            ->select(['clients.id', 'REPLACE(clients.id_format, ?, clients.id_value)' => 'id_code'])
            ->appendValues(['{num}'])
            ->from('clients')
            ->innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)
            ->where('client_groups.company_id', '=', $company_id)
            ->where('clients.id', 'in', $client_ids)
            ->fetchAll();

        $codes = [];
        foreach ($rows as $row) {
            $codes[(int) $row->id] = $row->id_code;
        }

        return $codes;
    }

    /**
     * Fetches vouchers eligible for single-reference reconciliation.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $after_id Resume cursor; only IDs greater than this are returned
     * @param string|null $pending_min_recheck_before Pending min recheck timestamp
     * @return array Voucher rows
     */
    public function getReconcilable(
        int $company_id,
        int $limit,
        int $after_id = 0,
        string $pending_min_recheck_before = null
    ): array {
        $pending_min_recheck_before = $pending_min_recheck_before ?: date('Y-m-d H:i:s', strtotime('-30 minutes'));

        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('company_id', '=', $company_id)
            ->where('currency', '=', 'PKR')
            ->where('status', 'in', ['pending', 'retry'])
            ->where('id', '>', max(0, $after_id))
            ->open()
                ->where('date_expires', '>=', date('Y-m-d'))
                ->orWhere('date_expires', '=', null)
            ->close()
            ->open()
                ->open()
                    ->where('status', '=', 'pending')
                    ->open()
                        ->where('date_last_checked', '=', null)
                        ->orWhere('date_last_checked', '<=', $pending_min_recheck_before)
                    ->close()
                ->close()
                ->open()
                    ->orWhere('status', '=', 'retry')
                    ->open()
                        ->where('date_last_checked', '=', null)
                        ->orWhere(
                            'date_last_checked',
                            '<=',
                            'DATE_SUB(NOW(), INTERVAL LEAST(360, 30 * POW(2, retry_count)) MINUTE)',
                            false,
                            false
                        )
                    ->close()
                ->close()
            ->close()
            ->order(['date_last_checked' => 'ASC', 'id' => 'ASC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Fetches vouchers eligible for safe posting.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $after_id Resume cursor; only IDs greater than this are returned
     * @return array Voucher rows
     */
    public function getPostable(int $company_id, int $limit, int $after_id = 0): array
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('company_id', '=', $company_id)
            ->where('currency', '=', 'PKR')
            ->where('status', '=', 'confirmed_unposted')
            ->where('blesta_transaction_id', '=', null)
            ->where('date_paid', '!=', null)
            ->where('id', '>', max(0, $after_id))
            ->order(['id' => 'ASC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Fetches vouchers eligible for local expiry.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $after_id Resume cursor; only IDs greater than this are returned
     * @return array Voucher rows
     */
    public function getExpirable(int $company_id, int $limit, int $after_id = 0): array
    {
        return $this->Record->select()
            ->from('kuickpay_vouchers')
            ->where('company_id', '=', $company_id)
            ->where('currency', '=', 'PKR')
            ->where('status', 'in', ['pending', 'retry'])
            ->where('date_expires', '!=', null)
            ->where('date_expires', '<', 'CURDATE()', false, false)
            ->where('id', '>', max(0, $after_id))
            ->order(['id' => 'ASC'])
            ->limit(max(1, $limit))
            ->fetchAll();
    }

    /**
     * Atomically transitions a still-active voucher to 'expired'.
     *
     * The status precondition makes the sweep safe against a concurrent
     * reconcile/posting transition: getExpirable() reads without a row lock
     * and the expire/reconcile crons hold distinct locks, so a row selected
     * as expirable may already have become confirmed_unposted by the time
     * this UPDATE runs. Guarding on status IN ('pending','retry') means a row
     * that left the active set is never clobbered back to 'expired'.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @return bool True only when this call transitioned the row
     */
    public function expire(int $voucher_id, int $company_id): bool
    {
        $statement = $this->Record
            ->where('id', '=', $voucher_id)
            ->where('company_id', '=', $company_id)
            ->where('status', 'in', ['pending', 'retry'])
            ->update('kuickpay_vouchers', [
                'status' => 'expired',
                'date_updated' => date('Y-m-d H:i:s'),
            ], ['status', 'date_updated']);

        return $statement->rowCount() === 1;
    }

    /**
     * Atomically transitions a company-scoped voucher when it is still in an
     * allowed source state.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @param string $new_status The target status
     * @param array $allowed_from Source statuses that may transition
     * @param array $extra Additional allowlisted voucher fields to write
     * @return bool True only when this call transitioned the row
     */
    public function transition(
        int $voucher_id,
        int $company_id,
        string $new_status,
        array $allowed_from,
        array $extra = []
    ): bool {
        if (!in_array($new_status, self::STATUSES, true)) {
            return false;
        }

        $allowed_from = array_values(array_intersect($allowed_from, self::STATUSES));
        if (empty($allowed_from)) {
            return false;
        }

        unset($extra['company_id']);
        $vars = array_intersect_key($extra, array_flip(self::FIELDS));
        $vars['status'] = $new_status;
        $vars['date_updated'] = date('Y-m-d H:i:s');
        $fields = array_values(array_intersect(array_keys($vars), self::FIELDS));

        $statement = $this->Record
            ->where('id', '=', $voucher_id)
            ->where('company_id', '=', $company_id)
            ->where('status', 'in', $allowed_from)
            ->update('kuickpay_vouchers', $vars, $fields);

        return $statement->rowCount() === 1;
    }

    /**
     * Locks and fetches a company-scoped voucher row.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID
     * @return mixed The voucher row, or false when absent
     */
    public function getForUpdate(int $voucher_id, int $company_id)
    {
        return $this->Record
            ->query(
                'SELECT * FROM kuickpay_vouchers WHERE id = ? AND company_id = ? FOR UPDATE',
                $voucher_id,
                $company_id
            )
            ->fetch();
    }

    /**
     * Returns voucher validation rules.
     *
     * @return array Validation rules
     */
    private function getRules()
    {
        return [
            'company_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.company_id.empty')
                ]
            ],
            'client_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.client_id.empty')
                ]
            ],
            'gateway_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.gateway_id.empty')
                ]
            ],
            'currency' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.currency.empty')
                ],
                'length' => [
                    'rule' => ['maxLength', 3],
                    'message' => $this->_('KuickpayVouchers.!error.currency.length')
                ]
            ],
            'amount' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.amount.empty')
                ],
                'format' => [
                    'rule' => ['matches', '/^\d+(?:\.\d{1,2})?$/'],
                    'message' => $this->_('KuickpayVouchers.!error.amount.format')
                ]
            ],
            'status' => [
                'valid' => [
                    'rule' => ['in_array', self::STATUSES],
                    'message' => $this->_('KuickpayVouchers.!error.status.valid')
                ]
            ],
            'registration_number' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.registration_number.empty')
                ]
            ],
            'consumer_number' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVouchers.!error.consumer_number.empty')
                ]
            ],
        ];
    }
}
