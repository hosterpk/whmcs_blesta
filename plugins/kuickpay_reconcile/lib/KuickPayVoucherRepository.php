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
                $this->KuickpayVouchers->Record->reset();
                return null;
            }

            foreach ($invoiceLinks as $invoiceLink) {
                $invoiceLink['voucher_id'] = $voucher_id;
                $invoice_link_id = $this->KuickpayVoucherInvoices->add($invoiceLink);
                if (!$invoice_link_id || $this->KuickpayVoucherInvoices->errors()) {
                    $this->KuickpayVouchers->Record->rollBack();
                    $this->KuickpayVouchers->Record->reset();
                    return null;
                }
            }

            $this->KuickpayVouchers->Record->commit();

            return (int) $voucher_id;
        } catch (Throwable $e) {
            $this->KuickpayVouchers->Record->rollBack();
            // A duplicate-key INSERT (e.g. the active-context unique key, Story
            // 5.2) throws mid-query and leaves stale bound values on the shared
            // Record builder; without this reset the caller's create-null
            // fall-through re-lookup fails with "bound variables does not match
            // tokens" and the loser would get null instead of the winner.
            $this->KuickpayVouchers->Record->reset();
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
     * Fetches a pending voucher whose linked invoice set exactly matches the requested IDs.
     *
     * @param array $invoiceIds Invoice IDs
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getPendingByInvoiceSet(array $invoiceIds, int $company_id): ?stdClass
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        sort($invoiceIds, SORT_NUMERIC);
        if (empty($invoiceIds)) {
            return null;
        }

        $voucher = $this->KuickpayVouchers->getPendingByInvoiceSet($invoiceIds, $company_id);

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
     * Fetches vouchers eligible for bounded posting.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $afterId Resume cursor; only IDs greater than this are returned
     * @return array Voucher rows
     */
    public function getPostable(int $company_id, int $limit, int $afterId = 0): array
    {
        return $this->KuickpayVouchers->getPostable($company_id, $limit, $afterId);
    }

    /**
     * Fetches vouchers eligible for bounded local expiry.
     *
     * @param int $company_id The company ID
     * @param int $limit Maximum records to return
     * @param int $afterId Resume cursor; only IDs greater than this are returned
     * @return array Voucher rows
     */
    public function getExpirable(int $company_id, int $limit, int $afterId = 0): array
    {
        return $this->KuickpayVouchers->getExpirable($company_id, $limit, $afterId);
    }

    /**
     * Atomically transitions a still-active voucher to 'expired'.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @return bool True only when this call transitioned the row
     */
    public function expire(int $voucher_id, int $company_id): bool
    {
        return $this->KuickpayVouchers->expire($voucher_id, $company_id);
    }

    /**
     * Locks and fetches a company-scoped voucher row.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getForUpdate(int $voucher_id, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getForUpdate($voucher_id, $company_id);

        return $voucher ?: null;
    }

    /**
     * Fetches a company-scoped voucher by id.
     *
     * Required by KuickPayReconcileService::reconcileVoucher() (the admin
     * "Check Now" path); delegates to the model, mirroring getForUpdate().
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID
     * @return stdClass|null The voucher row, or null when absent
     */
    public function getForCompany(int $voucher_id, int $company_id): ?stdClass
    {
        $voucher = $this->KuickpayVouchers->getForCompany($voucher_id, $company_id);

        return $voucher ?: null;
    }

    /**
     * Locks and fetches invoice links for a voucher.
     *
     * @param int $voucher_id The voucher ID
     * @return array Invoice link rows
     */
    public function getInvoiceLinksForUpdate(int $voucher_id): array
    {
        return $this->KuickpayVoucherInvoices->getByVoucherIdForUpdate($voucher_id);
    }

    /**
     * Returns the shared Blesta Record instance used by the voucher model.
     *
     * @return mixed
     */
    public function record()
    {
        return $this->KuickpayVouchers->Record;
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

    /**
     * Status-guarded voucher update: writes only when the row is still active
     * (status IN 'pending','retry').
     *
     * Delegates to the model's transition() (WHERE status IN (...) + rowCount
     * gate) so a racing reconcile that overlaps a concurrent confirm/transition
     * matches zero rows instead of demoting it. Mirrors the existing expire()
     * delegation; reuses the one status-guarded UPDATE mechanism rather than
     * hand-rolling a second.
     *
     * @param int $voucher_id The voucher ID
     * @param int $company_id The company ID scope
     * @param array $vars Voucher fields; the 'status' key is the target state
     * @return bool True only when this call transitioned the row
     */
    public function editIfActive(int $voucher_id, int $company_id, array $vars): bool
    {
        $new_status = (string) ($vars['status'] ?? '');
        unset($vars['status']);

        return $this->KuickpayVouchers->transition(
            $voucher_id,
            $company_id,
            $new_status,
            ['pending', 'retry'],
            $vars
        );
    }
}
