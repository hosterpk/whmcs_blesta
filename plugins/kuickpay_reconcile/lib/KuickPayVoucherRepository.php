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
     * Fetches the latest voucher by linked invoice.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getLatestByInvoiceId(int $invoice_id, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getLatestByInvoiceId($invoice_id, $company_id);

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

    /**
     * Fetches another active voucher holding the same KuickPay reference.
     *
     * @param string $reference The confirmed KuickPay transaction reference
     * @param int $company_id The company ID
     * @param int $excludeVoucherId Voucher ID to exclude from duplicate checks
     * @return stdClass|null The voucher row, or null when absent
     */
    public function findActiveByKuickpayReference(
        string $reference,
        int $company_id,
        int $excludeVoucherId = 0
    ): ?stdClass {
        $voucher = $this->KuickpayVouchers->findActiveByKuickpayReference(
            $reference,
            $company_id,
            $excludeVoucherId
        );

        return $voucher ?: null;
    }

    /**
     * Fetches another active voucher linked to an invoice.
     *
     * @param int $invoice_id The invoice ID
     * @param int $company_id The company ID
     * @param int $excludeVoucherId Voucher ID to exclude from sibling checks
     * @return stdClass|null The voucher row, or null when absent
     */
    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->findActiveByInvoiceId($invoice_id, $company_id, $excludeVoucherId);

        return $voucher ?: null;
    }

    /**
     * Fetches vouchers eligible for bounded single-inquiry reconciliation.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $afterId Resume cursor; only IDs greater than this are returned
     * @param string|null $pendingMinRecheckBefore Pending vouchers checked after this timestamp are skipped
     * @return array Voucher rows
     */
    public function getReconcilable(
        int $company_id,
        int $limit,
        int $afterId = 0,
        string $pendingMinRecheckBefore = null
    ): array {
        return $this->KuickpayVouchers->getReconcilable(
            $company_id,
            $limit,
            $afterId,
            $pendingMinRecheckBefore
        );
    }

    /**
     * Updates a voucher through the company-scoped model mutator.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @param array $vars Voucher fields
     */
    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $this->KuickpayVouchers->edit($voucher_id, $company_id, $vars);
    }
}
