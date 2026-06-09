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
     * Fetches invoice links by voucher ID.
     *
     * @param int $voucher_id The voucher ID
     * @return array Invoice link rows
     */
    public function getByVoucherId(int $voucher_id)
    {
        return $this->Record->select()
            ->from('kuickpay_voucher_invoices')
            ->where('voucher_id', '=', $voucher_id)
            ->fetchAll();
    }

    /**
     * Fetches invoice links by invoice ID.
     *
     * @param int $invoice_id The invoice ID
     * @return array Invoice link rows
     */
    public function getByInvoiceId(int $invoice_id)
    {
        return $this->Record->select()
            ->from('kuickpay_voucher_invoices')
            ->where('invoice_id', '=', $invoice_id)
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
                    'message' => $this->_('KuickpayVoucherInvoices.!error.voucher_id.empty')
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
                    'message' => $this->_('KuickpayVoucherInvoices.!error.invoice_id.empty')
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
