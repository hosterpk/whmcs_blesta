<?php
/**
 * KuickPay confirmed-voucher posting service.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayPostingService
{
    public const BATCH_SIZE = 100;
    public const MAX_RUNTIME_SECONDS = 240;
    public const LOCK_TTL_SECONDS = 600;
    private const LOCK_NAME = 'post_confirmed';
    private const TRANSACTION_MESSAGE_KEY = 'KuickpayReconcile.posting.transaction_message';

    private $voucherRepository;
    private $evidenceValidator;
    private $auditService;
    private $lockRepository;
    private $transactions;

    public function __construct(array $dependencies = [])
    {
        if (empty($dependencies)) {
            $this->loadRuntimeDependencies();
        }

        $this->voucherRepository = $dependencies['voucher_repository'] ?? new KuickPayVoucherRepository();
        $this->evidenceValidator = $dependencies['evidence_validator'] ?? new KuickPayEvidenceValidator();
        $this->auditService = $dependencies['audit_service'] ?? new KuickPayAuditService();
        $this->lockRepository = $dependencies['lock_repository'] ?? new KuickPayReconcileLockRepository();

        if (isset($dependencies['transactions'])) {
            $this->transactions = $dependencies['transactions'];
        } else {
            Loader::loadModels($this, ['Transactions']);
            $this->transactions = $this->Transactions;
        }
    }

    public function postConfirmed(int $company_id): array
    {
        $owner_token = $this->lockRepository->acquire($company_id, self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if ($owner_token === null) {
            return ['status' => 'skipped', 'reason' => 'lock_held', 'counts' => $this->initialCounts()];
        }

        $counts = $this->initialCounts();
        $status = 'completed';
        $cursor = 0;
        $start = time();

        try {
            $vouchers = $this->voucherRepository->getPostable($company_id, self::BATCH_SIZE, $cursor);

            foreach ($vouchers as $voucher) {
                if (time() - $start >= self::MAX_RUNTIME_SECONDS) {
                    $status = 'aborted';
                    break;
                }

                $cursor = (int) ($voucher->id ?? 0);
                $result = $this->postVoucher($company_id, $voucher);
                $counts = $this->countOutcome($counts, $result['outcome']);
            }
        } catch (Throwable $e) {
            $status = 'aborted';
            $counts['errors']++;
        } finally {
            $this->lockRepository->release($company_id, self::LOCK_NAME, $owner_token);
        }

        return ['status' => $status, 'counts' => $counts, 'cursor' => $cursor];
    }

    public function postVoucher(int $company_id, $voucher): array
    {
        $voucher_id = (int) ($voucher->id ?? 0);

        if (!$this->validPaidDate((string) ($voucher->date_paid ?? ''))) {
            $this->moveToManualReview($voucher_id, $company_id, $voucher, ['missing_paid_date']);
            $this->recordFailureAudit($company_id, $voucher, ['reason' => 'missing_paid_date']);

            return $this->result($voucher_id, 'manual_review');
        }

        $record = $this->voucherRepository->record();
        $begun = false;

        try {
            $record->begin();
            $begun = true;

            $lockedVoucher = $this->voucherRepository->getForUpdate($voucher_id, $company_id);
            $lockedLinks = $this->voucherRepository->getInvoiceLinksForUpdate($voucher_id);

            if (!$lockedVoucher || (int) ($lockedVoucher->company_id ?? 0) !== $company_id) {
                $record->commit();

                return $this->result($voucher_id, 'skipped');
            }

            if ((string) ($lockedVoucher->status ?? '') !== 'confirmed_unposted'
                || !empty($lockedVoucher->blesta_transaction_id)
            ) {
                $record->commit();

                $outcome = !empty($lockedVoucher->blesta_transaction_id)
                    || (string) ($lockedVoucher->status ?? '') === 'posted'
                    ? 'already_posted'
                    : 'skipped';

                return $this->result(
                    $voucher_id,
                    $outcome,
                    empty($lockedVoucher->blesta_transaction_id) ? null : (int) $lockedVoucher->blesta_transaction_id
                );
            }

            $evidence = $this->evidenceFromVoucher($lockedVoucher);
            $validation = $this->evidenceValidator->validate(
                $lockedVoucher,
                $lockedLinks,
                $evidence,
                ['confirmed_unposted']
            );

            if (!$validation->isValid()) {
                $this->moveToManualReview($voucher_id, $company_id, $lockedVoucher, $validation->reasons());
                $this->recordFailureAudit($company_id, $lockedVoucher, ['reasons' => $validation->reasons()]);
                $record->commit();

                return $this->result($voucher_id, 'manual_review');
            }

            $existing = $this->transactions->getByTransactionId(
                (string) $lockedVoucher->kuickpay_reference,
                (int) $lockedVoucher->client_id,
                (int) $lockedVoucher->gateway_id
            );

            if ($existing) {
                $this->recordStartedAudit($company_id, $lockedVoucher, 'adopt_existing');
                $adopted = $this->adoptExistingTransaction($existing, $lockedVoucher, $lockedLinks);
                if (!$adopted['ok']) {
                    $this->moveToManualReview($voucher_id, $company_id, $lockedVoucher, [$adopted['reason']]);
                    $this->recordFailureAudit($company_id, $lockedVoucher, ['reason' => $adopted['reason']]);
                    $record->commit();

                    return $this->result($voucher_id, 'manual_review');
                }

                $this->markPosted($voucher_id, $company_id, (int) $existing->id);
                $this->recordSucceededAudit($company_id, $lockedVoucher, (int) $existing->id, 'adopt_existing');
                $record->commit();

                return $this->result($voucher_id, 'posted', (int) $existing->id);
            }

            $this->recordStartedAudit($company_id, $lockedVoucher, 'create');
            $transaction_id = $this->transactions->add($this->transactionVars($lockedVoucher));
            if (!$transaction_id || $this->transactions->errors()) {
                $record->rollBack();
                $this->recordFailureAudit($company_id, $lockedVoucher, ['reason' => 'transaction_add_failed']);

                return $this->result($voucher_id, 'failed');
            }

            $this->transactions->apply((int) $transaction_id, [
                'amounts' => $this->allocations($lockedLinks),
                'date' => (string) $lockedVoucher->date_paid,
            ]);
            if ($this->transactions->errors()) {
                $record->rollBack();
                $this->recordFailureAudit($company_id, $lockedVoucher, ['reason' => 'transaction_apply_failed']);

                return $this->result($voucher_id, 'failed');
            }

            $this->markPosted($voucher_id, $company_id, (int) $transaction_id);
            $this->recordSucceededAudit($company_id, $lockedVoucher, (int) $transaction_id, 'create');
            $record->commit();

            return $this->result($voucher_id, 'posted', (int) $transaction_id);
        } catch (Throwable $e) {
            if ($begun) {
                try {
                    $record->rollBack();
                } catch (Throwable $rollbackError) {
                    // Best effort; the failure audit below must still be attempted.
                }
            }

            $this->recordFailureAudit($company_id, $voucher, ['reason' => 'posting_exception']);

            return $this->result($voucher_id, 'failed');
        }
    }

    private function adoptExistingTransaction($transaction, $voucher, array $links): array
    {
        $basicMismatch = (string) ($transaction->status ?? '') !== 'approved'
            || (int) ($transaction->client_id ?? 0) !== (int) $voucher->client_id
            || (int) ($transaction->gateway_id ?? 0) !== (int) $voucher->gateway_id
            || (string) ($transaction->currency ?? '') !== (string) $voucher->currency
            || $this->toMinorUnitsOrNull((string) ($transaction->amount ?? '')) !== $this->toMinorUnitsOrNull(
                (string) $voucher->amount
            );

        if ($basicMismatch) {
            return ['ok' => false, 'reason' => 'existing_transaction_mismatch'];
        }

        if ($this->appliedMatches((int) $transaction->id, $links)) {
            return ['ok' => true];
        }

        if (!empty($this->transactions->getApplied((int) $transaction->id))) {
            return ['ok' => false, 'reason' => 'existing_transaction_partial_application'];
        }

        $this->transactions->apply((int) $transaction->id, [
            'amounts' => $this->allocations($links),
            'date' => (string) $voucher->date_paid,
        ]);
        if ($this->transactions->errors()) {
            return ['ok' => false, 'reason' => 'existing_transaction_apply_failed'];
        }

        return $this->appliedMatches((int) $transaction->id, $links)
            ? ['ok' => true]
            : ['ok' => false, 'reason' => 'existing_transaction_unverified'];
    }

    private function appliedMatches(int $transaction_id, array $links): bool
    {
        $expected = [];
        foreach ($links as $link) {
            $expected[(int) $link->invoice_id] = $this->toMinorUnitsOrNull((string) $link->amount);
        }

        $actual = [];
        foreach ($this->transactions->getApplied($transaction_id) as $applied) {
            $actual[(int) $applied->invoice_id] = $this->toMinorUnitsOrNull((string) $applied->applied_amount);
        }

        ksort($expected);
        ksort($actual);

        return $expected === $actual && !empty($expected);
    }

    private function transactionVars($voucher): array
    {
        return [
            'client_id' => (int) $voucher->client_id,
            'amount' => (string) $voucher->amount,
            'currency' => (string) $voucher->currency,
            'type' => 'other',
            'gateway_id' => (int) $voucher->gateway_id,
            'transaction_id' => (string) $voucher->kuickpay_reference,
            'reference_id' => null,
            'message' => $this->transactionMessage(),
            'status' => 'approved',
            'date_added' => date('Y-m-d H:i:s'),
        ];
    }

    private function transactionMessage(): string
    {
        return class_exists('Language')
            ? Language::_(self::TRANSACTION_MESSAGE_KEY, true)
            : 'KuickPay payment posted';
    }

    private function evidenceFromVoucher($voucher): KuickPayEvidence
    {
        return new KuickPayEvidence(
            'confirmed_unposted',
            null,
            (string) $voucher->kuickpay_reference,
            (string) $voucher->consumer_number,
            (string) $voucher->registration_number,
            (string) $voucher->amount,
            (string) $voucher->currency,
            substr((string) $voucher->date_paid, 0, 10),
            null,
            '',
            (string) ($voucher->evidence_hash ?? ''),
            []
        );
    }

    private function validPaidDate(string $date_paid): bool
    {
        if ($date_paid === '' || strpos($date_paid, '0000-00-00') === 0) {
            return false;
        }

        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date_paid);

        return $date && $date->format('Y-m-d H:i:s') === $date_paid;
    }

    private function allocations(array $links): array
    {
        $amounts = [];
        foreach ($links as $link) {
            $amounts[] = [
                'invoice_id' => (int) $link->invoice_id,
                'amount' => (string) $link->amount,
            ];
        }

        return $amounts;
    }

    private function markPosted(int $voucher_id, int $company_id, int $transaction_id): void
    {
        $this->voucherRepository->edit($voucher_id, $company_id, [
            'status' => 'posted',
            'blesta_transaction_id' => $transaction_id,
            'date_posted' => date('Y-m-d H:i:s'),
            'error_class' => null,
        ]);
    }

    private function moveToManualReview(int $voucher_id, int $company_id, $voucher, array $reasons): void
    {
        $this->voucherRepository->edit($voucher_id, $company_id, [
            'status' => 'manual_review',
            'error_class' => 'posting_failed',
            'diagnostic_summary' => $this->mergeValidationErrors(
                (string) ($voucher->diagnostic_summary ?? ''),
                $reasons
            ),
        ]);
    }

    private function mergeValidationErrors(string $diagnosticSummary, array $reasons): string
    {
        $diag = json_decode($diagnosticSummary, true) ?: [];
        $diag['validation_errors'] = array_values(array_unique(array_merge(
            $diag['validation_errors'] ?? [],
            $reasons
        )));

        return json_encode($diag);
    }

    private function recordStartedAudit(int $company_id, $voucher, string $mode): void
    {
        $this->recordAudit('posting.started', $company_id, $voucher, ['mode' => $mode]);
    }

    private function recordSucceededAudit(int $company_id, $voucher, int $transaction_id, string $mode): void
    {
        $this->recordAudit('posting.succeeded', $company_id, $voucher, [
            'mode' => $mode,
            'blesta_transaction_id' => $transaction_id,
        ]);
    }

    private function recordFailureAudit(int $company_id, $voucher, array $payload): void
    {
        $this->recordAudit('posting.failed', $company_id, $voucher, $payload);
    }

    private function recordAudit(string $event, int $company_id, $voucher, array $payload): void
    {
        try {
            $this->auditService->record($event, [
                'company_id' => $company_id,
                'voucher_id' => (int) ($voucher->id ?? 0),
                'redacted_trace_id' => '',
                'evidence_hash' => (string) ($voucher->evidence_hash ?? ''),
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
            // Audit writes must not abort the posting batch.
        }
    }

    private function result(int $voucher_id, string $outcome, ?int $transaction_id = null): array
    {
        return [
            'voucher_id' => $voucher_id,
            'outcome' => $outcome,
            'blesta_transaction_id' => $transaction_id,
        ];
    }

    private function initialCounts(): array
    {
        return [
            'processed' => 0,
            'posted' => 0,
            'already_posted' => 0,
            'skipped' => 0,
            'manual_review' => 0,
            'failed' => 0,
            'errors' => 0,
        ];
    }

    private function countOutcome(array $counts, string $outcome): array
    {
        $counts['processed']++;
        if (isset($counts[$outcome])) {
            $counts[$outcome]++;
        } else {
            $counts['errors']++;
        }

        return $counts;
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
        $gateway_dir = dirname(dirname(dirname($plugin_dir))) . DS . 'components' . DS . 'gateways'
            . DS . 'nonmerchant' . DS . 'kuickpay' . DS . 'lib';

        Loader::load($plugin_dir . DS . 'KuickPayVoucherRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayReconcileLockRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayAuditRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayAuditService.php');
        Loader::load($plugin_dir . DS . 'KuickPayValidationResult.php');
        Loader::load($plugin_dir . DS . 'KuickPayInvoiceReader.php');
        Loader::load($plugin_dir . DS . 'KuickPayEvidenceValidator.php');
        Loader::load($gateway_dir . DS . 'KuickPayEvidence.php');
    }
}
