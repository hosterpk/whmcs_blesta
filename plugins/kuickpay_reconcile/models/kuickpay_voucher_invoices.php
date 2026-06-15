<?php
/**
 * KuickPay voucher invoices model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickpayVoucherInvoices extends KuickpayReconcileModel
{
    /**
     * Adds an invoice link for a voucher.
     *
     * @param array $vars Invoice link fields
     * @return mixed The invoice link ID, or void on validation error
     */
    public function add(array $vars)
    {
        $vars['date_created'] = $vars['date_created'] ?? date('Y-m-d H:i:s');

        $this->Input->setRules($this->getRules());
        if (!$this->Input->validates($vars)) {
            return;
        }

        $fields = ['voucher_id', 'invoice_id', 'amount', 'date_created'];
        $this->Record->insert('kuickpay_voucher_invoices', $vars, $fields);

        if ($this->Input->errors()) {
            return;
        }

        return $this->Record->lastInsertId();
    }

    /**
     * Fetches invoice links by voucher ID, scoped to the owning company.
     *
     * This is a PARENT-SCOPED child table (no company_id column); the tenant
     * scope is enforced by joining to the owning kuickpay_vouchers row, never
     * by a direct company_id filter (which would be a fatal SQL error).
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope (of the owning voucher)
     * @return array Invoice link rows
     */
    public function getByVoucherId(int $voucher_id, int $company_id)
    {
        return $this->scopedChildSelect('kuickpay_voucher_invoices', 'kuickpay_vouchers', 'voucher_id', $company_id)
            ->where('kuickpay_voucher_invoices.voucher_id', '=', $voucher_id)
            ->fetchAll();
    }

    /**
     * Locks and fetches invoice links by voucher ID, scoped to the owning company.
     *
     * Parent-scoped via a join to kuickpay_vouchers. FOR UPDATE needs raw SQL
     * (the Record builder has no lock-clause primitive); the join also pins the
     * parent voucher, which the posting flow already holds locked, so no new
     * lock-ordering hazard is introduced.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope (of the owning voucher)
     * @return array Invoice link rows
     */
    public function getByVoucherIdForUpdate(int $voucher_id, int $company_id)
    {
        return $this->Record
            ->query(
                'SELECT kuickpay_voucher_invoices.* FROM kuickpay_voucher_invoices'
                . ' INNER JOIN kuickpay_vouchers'
                . ' ON kuickpay_vouchers.id = kuickpay_voucher_invoices.voucher_id'
                . ' WHERE kuickpay_voucher_invoices.voucher_id = ? AND kuickpay_vouchers.company_id = ?'
                . ' FOR UPDATE',
                $voucher_id,
                $company_id
            )
            ->fetchAll();
    }

    /**
     * Batch-fetches invoice links for a set of voucher IDs, company-scoped.
     *
     * One query joined to kuickpay_vouchers for the company scope (the links
     * table has no company_id of its own), grouped in PHP. Avoids the per-row
     * N+1 the admin voucher list would otherwise incur.
     *
     * @param array $voucher_ids The voucher IDs to resolve
     * @param int $company_id The authenticated staff company (mandatory scope)
     * @return array Map of [voucher_id => [{invoice_id, amount}, ...]]
     */
    public function getByVoucherIds(array $voucher_ids, int $company_id): array
    {
        $voucher_ids = array_values(array_unique(array_map('intval', $voucher_ids)));
        if (empty($voucher_ids)) {
            return [];
        }

        $rows = $this->Record
            ->select([
                'kuickpay_voucher_invoices.voucher_id',
                'kuickpay_voucher_invoices.invoice_id',
                'kuickpay_voucher_invoices.amount',
            ])
            ->from('kuickpay_voucher_invoices')
            ->innerJoin(
                'kuickpay_vouchers',
                'kuickpay_vouchers.id',
                '=',
                'kuickpay_voucher_invoices.voucher_id',
                false
            )
            ->where('kuickpay_vouchers.company_id', '=', $company_id)
            ->where('kuickpay_voucher_invoices.voucher_id', 'in', $voucher_ids)
            ->order(['kuickpay_voucher_invoices.invoice_id' => 'ASC'])
            ->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->voucher_id][] = [
                'invoice_id' => $row->invoice_id,
                'amount' => $row->amount,
            ];
        }

        return $grouped;
    }

    /**
     * Fetches invoice links by invoice ID, scoped to the owning company.
     *
     * Parent-scoped via a join to kuickpay_vouchers (the links table has no
     * company_id of its own).
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID scope (of the owning voucher)
     * @return array Invoice link rows
     */
    public function getByInvoiceId(int $invoice_id, int $company_id)
    {
        return $this->scopedChildSelect('kuickpay_voucher_invoices', 'kuickpay_vouchers', 'voucher_id', $company_id)
            ->where('kuickpay_voucher_invoices.invoice_id', '=', $invoice_id)
            ->fetchAll();
    }

    /**
     * Returns invoice link validation rules.
     *
     * @return array Validation rules
     */
    private function getRules()
    {
        return [
            'voucher_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVoucherInvoices.!error.voucher_id.empty')
                ],
                'numeric' => [
                    'rule' => ['matches', '/^\d+$/'],
                    'message' => $this->_('KuickpayVoucherInvoices.!error.voucher_id.format')
                ]
            ],
            'invoice_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('KuickpayVoucherInvoices.!error.invoice_id.empty')
                ],
                'numeric' => [
                    'rule' => ['matches', '/^\d+$/'],
                    'message' => $this->_('KuickpayVoucherInvoices.!error.invoice_id.format')
                ]
            ],
            'amount' => [
                'format' => [
                    'rule' => ['matches', '/^\d+(?:\.\d{1,2})?$/'],
                    'message' => $this->_('KuickpayVoucherInvoices.!error.amount.format')
                ]
            ],
        ];
    }
}
