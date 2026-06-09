<?php
/**
 * KuickPay voucher repository
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayVoucherRepository
{
    /**
     * Constructs the repository and loads plugin models.
     */
    public function __construct()
    {
        Loader::loadModels($this, [
            'KuickpayReconcile.KuickpayVouchers',
            'KuickpayReconcile.KuickpayVoucherInvoices',
        ]);
    }

    /**
     * Atomically creates a voucher and invoice links.
     *
     * @param array $voucherData Voucher fields
     * @param array $invoiceLinks Invoice link fields
     * @return int|null The voucher ID, or null on failure
     */
    public function create(array $voucherData, array $invoiceLinks): ?int
    {
        $this->KuickpayVouchers->Record->begin();

        try {
            $voucher_id = $this->KuickpayVouchers->add($voucherData);
            if (!$voucher_id || $this->KuickpayVouchers->errors()) {
                $this->KuickpayVouchers->Record->rollBack();
                return null;
            }

            foreach ($invoiceLinks as $invoiceLink) {
                $invoiceLink['voucher_id'] = $voucher_id;
                $invoice_link_id = $this->KuickpayVoucherInvoices->add($invoiceLink);
                if (!$invoice_link_id || $this->KuickpayVoucherInvoices->errors()) {
                    $this->KuickpayVouchers->Record->rollBack();
                    return null;
                }
            }

            $this->KuickpayVouchers->Record->commit();

            return (int) $voucher_id;
        } catch (Throwable $e) {
            $this->KuickpayVouchers->Record->rollBack();
            return null;
        }
    }

    /**
     * Fetches a pending voucher by linked invoice.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getPendingByInvoiceId(int $invoice_id, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getPendingByInvoiceId($invoice_id, $company_id);

        return $voucher ?: null;
    }

    /**
     * Fetches a voucher by registration number.
     *
     * @param string $registration_number The KuickPay registration number
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getByRegistrationNumber(string $registration_number, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getByRegistrationNumber($registration_number, $company_id);

        return $voucher ?: null;
    }

    /**
     * Fetches a voucher by consumer number.
     *
     * @param string $consumer_number The KuickPay consumer number
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getByConsumerNumber(string $consumer_number, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getByConsumerNumber($consumer_number, $company_id);

        return $voucher ?: null;
    }

    /**
     * Fetches a voucher with its invoice links.
     *
     * @param int $voucher_id The voucher ID
     * @return array|null Nested voucher/invoices data, or null when absent
     */
    public function getWithInvoices(int $voucher_id): ?array
    {
        $voucher = $this->KuickpayVouchers->get($voucher_id);
        if (!$voucher) {
            return null;
        }

        return [
            'voucher' => $voucher,
            'invoices' => $this->KuickpayVoucherInvoices->getByVoucherId($voucher_id),
        ];
    }
}
