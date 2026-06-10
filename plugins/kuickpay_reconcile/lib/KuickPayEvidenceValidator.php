<?php
/**
 * Validates confirmed KuickPay evidence against durable and live state.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayEvidenceValidator
{
    private $voucherRepository;
    private $voucherInvoices;
    private $invoiceReader;

    public function __construct(array $dependencies = [])
    {
        if (empty($dependencies)) {
            $this->loadRuntimeDependencies();
        }

        $this->voucherRepository = $dependencies['voucher_repository'] ?? new KuickPayVoucherRepository();
        $this->voucherInvoices = $dependencies['voucher_invoices'] ?? null;
        $this->invoiceReader = $dependencies['invoice_reader'] ?? new KuickPayInvoiceReader();
    }

    public function validate(
        stdClass $voucher,
        array $invoiceLinks,
        KuickPayEvidence $evidence,
        array $allowedStatuses = ['pending', 'retry']
    ): KuickPayValidationResult {
        $reasons = [];
        $voucher_id = (int) ($voucher->id ?? 0);
        $company_id = (int) ($voucher->company_id ?? 0);

        $invoiceMap = [];
        $invoiceMismatch = empty($invoiceLinks);
        $linkSum = 0;

        foreach ($invoiceLinks as $link) {
            $invoice_id = (int) ($link->invoice_id ?? 0);
            $invoice = $invoice_id > 0 ? $this->invoiceReader->get($invoice_id) : null;
            $invoiceMap[$invoice_id] = $invoice;

            $linkAmount = $this->toMinorUnitsOrNull((string) ($link->amount ?? ''));
            if ($linkAmount === null) {
                $invoiceMismatch = true;
                continue;
            }

            $linkSum += $linkAmount;

            if (!$invoice || !$this->invoiceMatches($invoice, $voucher, $linkAmount)) {
                $invoiceMismatch = true;
            }
        }

        if (!$this->currencyMatches($voucher, $invoiceMap, $evidence)) {
            $reasons[] = 'currency_mismatch';
        }

        if (!$this->amountMatches($voucher, $evidence, $linkSum, !empty($invoiceLinks))) {
            $reasons[] = 'amount_mismatch';
        }

        if (!$this->referenceMatches($voucher, $evidence)) {
            $reasons[] = 'unmatched_reference';
        }

        if ($invoiceMismatch) {
            $reasons[] = 'invoice_mismatch';
        }

        if (!$this->voucherIsFresh($voucher, $invoiceLinks, $company_id, $voucher_id, $allowedStatuses)) {
            $reasons[] = 'stale_voucher';
        }

        if (!$this->referenceIsUnique($evidence, $company_id, $voucher_id)) {
            $reasons[] = 'duplicate_reference';
        }

        return new KuickPayValidationResult(empty($reasons), $reasons);
    }

    private function currencyMatches(stdClass $voucher, array $invoiceMap, KuickPayEvidence $evidence): bool
    {
        if ($evidence->currency() !== 'PKR' || (string) ($voucher->currency ?? '') !== 'PKR') {
            return false;
        }

        foreach ($invoiceMap as $invoice) {
            if (!$invoice || (string) ($invoice->currency ?? '') !== 'PKR') {
                return false;
            }
        }

        return true;
    }

    private function amountMatches(
        stdClass $voucher,
        KuickPayEvidence $evidence,
        int $linkSum,
        bool $hasInvoiceLinks
    ): bool {
        $evidenceAmount = $this->toMinorUnitsOrNull((string) $evidence->amount());
        $voucherAmount = $this->toMinorUnitsOrNull((string) ($voucher->amount ?? ''));

        if ($evidenceAmount === null || $voucherAmount === null || $evidenceAmount !== $voucherAmount) {
            return false;
        }

        return !$hasInvoiceLinks || $voucherAmount === $linkSum;
    }

    private function referenceMatches(stdClass $voucher, KuickPayEvidence $evidence): bool
    {
        if ((string) $evidence->registrationNumber() !== (string) ($voucher->registration_number ?? '')) {
            return false;
        }

        if ($evidence->consumerNumber() !== null
            && (string) $evidence->consumerNumber() !== (string) ($voucher->consumer_number ?? '')
        ) {
            return false;
        }

        return true;
    }

    private function invoiceMatches(stdClass $invoice, stdClass $voucher, int $linkAmount): bool
    {
        if ((string) ($invoice->status ?? '') !== 'active'
            || (int) ($invoice->client_id ?? 0) !== (int) ($voucher->client_id ?? 0)
            || (string) ($invoice->currency ?? '') !== (string) ($voucher->currency ?? '')
        ) {
            return false;
        }

        $due = $this->invoiceDueMinorUnits($invoice);

        return $due !== null && $due >= $linkAmount;
    }

    private function voucherIsFresh(
        stdClass $voucher,
        array $invoiceLinks,
        int $company_id,
        int $voucher_id,
        array $allowedStatuses
    ): bool {
        if (!in_array((string) ($voucher->status ?? ''), $allowedStatuses, true)
            || !empty($voucher->blesta_transaction_id)
        ) {
            return false;
        }

        foreach ($invoiceLinks as $link) {
            $sibling = $this->voucherRepository->findActiveByInvoiceId(
                (int) ($link->invoice_id ?? 0),
                $company_id,
                $voucher_id
            );
            if ($sibling) {
                return false;
            }
        }

        return true;
    }

    private function referenceIsUnique(KuickPayEvidence $evidence, int $company_id, int $voucher_id): bool
    {
        $reference = trim((string) $evidence->reference());
        if ($reference === '') {
            return false;
        }

        return !$this->voucherRepository->findActiveByKuickpayReference($reference, $company_id, $voucher_id);
    }

    private function invoiceDueMinorUnits(stdClass $invoice): ?int
    {
        if (isset($invoice->due)) {
            return $this->toMinorUnitsOrNull(number_format((float) $invoice->due, 2, '.', ''));
        }

        if (isset($invoice->total)) {
            $paid = isset($invoice->paid) ? (float) $invoice->paid : 0.0;

            return $this->toMinorUnitsOrNull(number_format((float) $invoice->total - $paid, 2, '.', ''));
        }

        return null;
    }

    private function toMinorUnitsOrNull(string $amount): ?int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function loadRuntimeDependencies(): void
    {
        $plugin_dir = dirname(__FILE__);

        Loader::load($plugin_dir . DS . 'KuickPayVoucherRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayInvoiceReader.php');
        Loader::load($plugin_dir . DS . 'KuickPayValidationResult.php');
    }
}
