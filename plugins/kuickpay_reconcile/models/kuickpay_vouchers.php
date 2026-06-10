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
     * Fetches vouchers matching optional filters.
     *
     * @param array $filters Supported filters: status, client_id, company_id
     * @param int $page The page number
     * @param array $order_by The order fields
     * @return array Voucher rows
     */
    public function getList(array $filters, int $page = 1, array $order_by = ['date_created' => 'DESC'])
    {
        $this->Record->select()->from('kuickpay_vouchers');

        foreach (['status', 'client_id', 'company_id'] as $filter) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $this->Record->where($filter, '=', $filters[$filter]);
            }
        }

        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
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
