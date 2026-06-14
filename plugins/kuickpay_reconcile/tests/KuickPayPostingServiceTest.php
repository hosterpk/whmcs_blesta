<?php

use PHPUnit\Framework\TestCase;

class KuickPayPostingServiceTest extends TestCase
{
    public function testHappyPathCreatesAppliesAndMarksVoucherPosted()
    {
        $voucher = $this->voucher([
            'diagnostic_summary' => json_encode([
                'status' => 'confirmed_unposted',
                'redacted_trace_id' => 'kp_1234567890abcdef',
                'validation_errors' => [],
            ]),
        ]);
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $audit = new KuickPayPostingFakeAuditService();
        $service = $this->service($repo, $transactions, $audit);

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(9001, $result['blesta_transaction_id']);
        $this->assertTrue($repo->record->committed);
        $this->assertFalse($repo->record->rolledBack);
        $this->assertSame('posted', $repo->edits[0]['status']);
        $this->assertSame(9001, $repo->edits[0]['blesta_transaction_id']);
        $this->assertSame('KP-REF-PAID', $transactions->adds[0]['transaction_id']);
        $this->assertSame('other', $transactions->adds[0]['type']);
        $this->assertSame('approved', $transactions->adds[0]['status']);
        $this->assertNull($transactions->adds[0]['reference_id']);
        $this->assertSame([['invoice_id' => 55, 'amount' => '1000.00']], $transactions->applies[0]['vars']['amounts']);
        $this->assertSame(['posting.started', 'posting.succeeded'], array_column($audit->events, 0));
        $this->assertSame('kp_1234567890abcdef', $audit->events[0][1]['redacted_trace_id']);
        $this->assertSame('kp_1234567890abcdef', $audit->events[1][1]['redacted_trace_id']);
        $this->assertStringNotContainsString('BillPaymentInquiry', json_encode($audit->events));
        $this->assertStringNotContainsString('password', json_encode($audit->events));
    }

    public function testMissingPaidDateMovesToManualReviewWithoutTransaction()
    {
        $voucher = $this->voucher(['date_paid' => null]);
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $audit = new KuickPayPostingFakeAuditService();
        $service = $this->service($repo, $transactions, $audit);

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame('posting_failed', $repo->edits[0]['error_class']);
        $this->assertSame(['missing_paid_date'], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame(['posting.failed'], array_column($audit->events, 0));
    }

    public function testEmptyPaidDateMovesToManualReviewWithoutTransaction()
    {
        $voucher = $this->voucher(['date_paid' => '']);
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(['missing_paid_date'], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
        $this->assertSame([], $transactions->adds);
    }

    public function testMalformedPaidDateMovesToManualReviewWithoutTransaction()
    {
        $voucher = $this->voucher(['date_paid' => '0000-00-00 00:00:00']);
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(['missing_paid_date'], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
        $this->assertSame([], $transactions->adds);
    }

    public function testAlreadyPostedVoucherIsNoOpAfterLock()
    {
        $locked = $this->voucher(['status' => 'posted', 'blesta_transaction_id' => 800]);
        $repo = new KuickPayPostingFakeVoucherRepository([$locked], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('already_posted', $result['outcome']);
        $this->assertSame(800, $result['blesta_transaction_id']);
        $this->assertTrue($repo->record->committed);
        $this->assertSame([], $repo->edits);
        $this->assertSame([], $transactions->adds);
    }

    public function testAlreadyPostedVoucherRemainsNoOpAcrossSuccessiveCalls()
    {
        $locked = $this->voucher(['status' => 'posted', 'blesta_transaction_id' => 800]);
        $repo = new KuickPayPostingFakeVoucherRepository([$locked], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $service = $this->service($repo, $transactions);

        $first = $service->postVoucher(1, $this->voucher());
        $second = $service->postVoucher(1, $this->voucher());

        $this->assertSame('already_posted', $first['outcome']);
        $this->assertSame('already_posted', $second['outcome']);
        $this->assertSame([], $repo->edits);
        $this->assertSame([], $transactions->adds);
        $this->assertSame([], $transactions->applies);
    }

    public function testValidationFailureMovesToManualReviewWithoutTransaction()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $audit = new KuickPayPostingFakeAuditService();
        $validator = new KuickPayPostingFakeEvidenceValidator(false, ['invoice_mismatch']);
        $service = $this->service($repo, $transactions, $audit, $validator);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame(['confirmed_unposted'], $validator->allowedStatuses);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(['invoice_mismatch'], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame(['posting.failed'], array_column($audit->events, 0));
    }

    public function testAddFailureRollsBackAndLeavesVoucherUnchanged()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->addErrors = ['transaction_id' => ['failed']];
        $audit = new KuickPayPostingFakeAuditService();
        $service = $this->service($repo, $transactions, $audit);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('failed', $result['outcome']);
        $this->assertTrue($repo->record->rolledBack);
        $this->assertSame([], $repo->edits);
        $this->assertSame(['posting.started', 'posting.failed'], array_column($audit->events, 0));
    }

    public function testApplyFailureRollsBackAndLeavesVoucherUnchanged()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->applyErrors = ['amounts' => ['failed']];
        $audit = new KuickPayPostingFakeAuditService();
        $service = $this->service($repo, $transactions, $audit);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('failed', $result['outcome']);
        $this->assertTrue($repo->record->rolledBack);
        $this->assertSame([], $repo->edits);
        $this->assertSame(['posting.started', 'posting.failed'], array_column($audit->events, 0));
    }

    public function testFailedPostingIncrementsAttemptsAndEscalatesToManualReviewAtCap()
    {
        // AC5.4/5c: a deterministically-failing post increments the durable
        // posting_attempts counter; at POSTING_RETRY_LIMIT the voucher escalates to
        // manual_review (fail-closed) so it stops re-occupying the head of every
        // ascending-by-id postConfirmed() batch. Exercised via direct postVoucher().
        $voucher = $this->voucher();
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->addErrors = ['transaction_id' => ['failed']];
        $service = $this->service($repo, $transactions);

        for ($attempt = 1; $attempt < KuickPayPostingService::POSTING_RETRY_LIMIT; $attempt++) {
            $result = $service->postVoucher(1, $voucher);

            $this->assertSame('failed', $result['outcome'], 'attempt ' . $attempt);
            $this->assertSame('confirmed_unposted', $voucher->status, 'attempt ' . $attempt);
            $this->assertSame($attempt, $voucher->posting_attempts);
        }

        $capResult = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $capResult['outcome']);
        $this->assertSame('manual_review', $voucher->status);
        $this->assertSame(KuickPayPostingService::POSTING_RETRY_LIMIT, $voucher->posting_attempts);
        $lastEdit = $repo->edits[count($repo->edits) - 1];
        $this->assertSame('manual_review', $lastEdit['status']);
        $this->assertSame(
            ['posting_retry_exhausted'],
            json_decode($lastEdit['diagnostic_summary'], true)['validation_errors']
        );

        // The escalated voucher leaves confirmed_unposted; the next pass skips it.
        $afterCap = $service->postVoucher(1, $voucher);
        $this->assertSame('skipped', $afterCap['outcome']);
        $this->assertSame(KuickPayPostingService::POSTING_RETRY_LIMIT, $voucher->posting_attempts);
    }

    public function testValidationDrivenManualReviewDoesNotIncrementPostingAttempts()
    {
        // Non-failed outcomes must NOT increment posting_attempts: a validation
        // rejection moves the voucher to manual_review immediately, never via the
        // retry-cap path.
        $voucher = $this->voucher();
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $validator = new KuickPayPostingFakeEvidenceValidator(false, ['invoice_mismatch']);
        $service = $this->service($repo, $transactions, null, $validator);

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertFalse(isset($voucher->posting_attempts));
        $this->assertSame([], $transactions->adds);
    }

    public function testMissingPaidDateDoesNotIncrementPostingAttempts()
    {
        // The pre-transaction missing_paid_date guard is also a non-failed outcome.
        $voucher = $this->voucher(['date_paid' => null]);
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $service = $this->service($repo, new KuickPayPostingFakeTransactions());

        $result = $service->postVoucher(1, $voucher);

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertFalse(isset($voucher->posting_attempts));
    }

    public function testPostThenRerunCreatesExactlyOneTransaction()
    {
        // deferred-work.md:8 — posting is idempotent across reruns: the first run
        // creates exactly one transaction; a rerun over the now-posted voucher is a
        // no-op that creates no second transaction.
        $voucher = $this->voucher();
        $repo = new KuickPayPostingFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $service = $this->service($repo, $transactions);

        $first = $service->postVoucher(1, $voucher);
        // Simulate the markPosted persistence the real DB would apply, so the rerun
        // sees a posted voucher.
        $voucher->status = 'posted';
        $voucher->blesta_transaction_id = $first['blesta_transaction_id'];
        $second = $service->postVoucher(1, $voucher);

        $this->assertSame('posted', $first['outcome']);
        $this->assertSame('already_posted', $second['outcome']);
        $this->assertCount(1, $transactions->adds);
    }

    public function testExistingApprovedAppliedTransactionIsAdoptedWithoutAdd()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [$this->transaction()];
        $transactions->applied = [(object) ['invoice_id' => 55, 'applied_amount' => '1000.00']];
        $audit = new KuickPayPostingFakeAuditService();
        $service = $this->service($repo, $transactions, $audit);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(777, $result['blesta_transaction_id']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame('posted', $repo->edits[0]['status']);
        $this->assertSame(777, $repo->edits[0]['blesta_transaction_id']);
        $this->assertSame(['posting.started', 'posting.succeeded'], array_column($audit->events, 0));
    }

    public function testAdoptsExistingTransactionWithBlestaFourDecimalAmounts()
    {
        // Blesta stores transactions.amount and transaction_applied.amount as
        // decimal(12,4), so the DB returns 4-decimal strings. Adoption must treat
        // "1000.0000" as equal to the voucher/link 2dp "1000.00" — not route to
        // manual_review. Regression guard for the minor-unit parser.
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [$this->transaction(['amount' => '1000.0000'])];
        $transactions->applied = [(object) ['invoice_id' => 55, 'applied_amount' => '1000.0000']];
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(777, $result['blesta_transaction_id']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame('posted', $repo->edits[0]['status']);
    }

    public function testExistingApprovedUnappliedTransactionFailsClosed()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [$this->transaction()];
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame([], $transactions->applies);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(
            ['existing_transaction_unverified'],
            json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']
        );
    }

    public function testAdoptionPicksMostRecentApprovedAppliedWhenMultipleShareReference()
    {
        // AC5.5: when more than one approved transaction shares the reference for
        // one client+gateway, adoption must select the already-applied candidate
        // (whose allocations match the voucher links) -- never an arbitrary single
        // fetch. Here the NEWER candidate is mis-applied (would fail adoption) and
        // the older one is correctly applied; the helper must pick the older one.
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [
            (object) [
                'id' => 900,
                'transaction_id' => 'KP-REF-PAID',
                'client_id' => 10,
                'gateway_id' => 20,
                'amount' => '1000.00',
                'currency' => 'PKR',
                'status' => 'approved',
                'date_added' => '2026-06-09 10:00:00',
            ],
            (object) [
                'id' => 800,
                'transaction_id' => 'KP-REF-PAID',
                'client_id' => 10,
                'gateway_id' => 20,
                'amount' => '1000.00',
                'currency' => 'PKR',
                'status' => 'approved',
                'date_added' => '2026-06-09 09:00:00',
            ],
        ];
        $transactions->appliedByTransaction = [
            900 => [(object) ['invoice_id' => 99, 'applied_amount' => '1000.00']],
            800 => [(object) ['invoice_id' => 55, 'applied_amount' => '1000.00']],
        ];
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(800, $result['blesta_transaction_id']);
        $this->assertSame([], $transactions->adds, 'an existing transaction is adopted, never re-created');
        $this->assertSame(800, $repo->edits[0]['blesta_transaction_id']);
    }

    public function testAdoptionDoesNotFallbackToCoreUnorderedLookup()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->existing = $this->transaction();
        $transactions->applied = [(object) ['invoice_id' => 55, 'applied_amount' => '1000.00']];
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('posted', $result['outcome']);
        $this->assertSame(9001, $result['blesta_transaction_id']);
        $this->assertSame(0, $transactions->getByTransactionIdCalls);
        $this->assertCount(1, $transactions->adds);
    }

    public function testExistingMismatchedTransactionMovesToManualReview()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [$this->transaction(['amount' => '999.99'])];
        $transactions->applied = [(object) ['invoice_id' => 55, 'applied_amount' => '1000.00']];
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(
            ['existing_transaction_mismatch'],
            json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']
        );
    }

    /**
     * @dataProvider existingUnsafeTransactionProvider
     */
    public function testExistingUnsafeTransactionMovesToManualReview(array $transactionOverrides, array $applied, string $reason)
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $transactions->listResults = [$this->transaction($transactionOverrides)];
        $transactions->applied = $applied;
        $service = $this->service($repo, $transactions);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame([], $transactions->adds);
        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame([$reason], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
    }

    public function existingUnsafeTransactionProvider(): array
    {
        return [
            'wrong currency' => [
                ['currency' => 'USD'],
                [(object) ['invoice_id' => 55, 'applied_amount' => '1000.00']],
                'existing_transaction_mismatch',
            ],
            'wrong invoice applied' => [
                [],
                [(object) ['invoice_id' => 99, 'applied_amount' => '1000.00']],
                'existing_transaction_unverified',
            ],
            'partially applied' => [
                [],
                [(object) ['invoice_id' => 55, 'applied_amount' => '500.00']],
                'existing_transaction_unverified',
            ],
        ];
    }

    public function testDoubleAllocationRevalidationFailureMovesToManualReview()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $validator = new KuickPayPostingFakeEvidenceValidator(false, ['stale_voucher']);
        $service = $this->service($repo, $transactions, null, $validator);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertSame(['stale_voucher'], json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']);
        $this->assertSame([], $transactions->adds);
    }

    public function testSymmetricDoubleAllocationFailsThroughRealValidator()
    {
        // AC9 symmetric: a sibling voucher is still active on the same invoice, so
        // the real validator's findActiveByInvoiceId trips voucherIsFresh ->
        // stale_voucher -> manual_review (no transaction). End-to-end, real validator.
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => new KuickPayPostingValidatorRepo((object) ['id' => 44]),
            'invoice_reader' => new KuickPayPostingValidatorInvoiceReader((object) [
                'id' => 55, 'client_id' => 10, 'status' => 'active', 'currency' => 'PKR',
                'total' => 1000.0, 'paid' => 0.0, 'due' => 1000.0,
            ]),
        ]);
        $service = new KuickPayPostingService([
            'voucher_repository' => $repo,
            'evidence_validator' => $validator,
            'audit_service' => new KuickPayPostingFakeAuditService(),
            'lock_repository' => new KuickPayPostingFakeLockRepository(),
            'transactions' => $transactions,
        ]);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertContains(
            'stale_voucher',
            json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']
        );
        $this->assertSame([], $transactions->adds);
    }

    public function testAsymmetricDoubleAllocationFailsLiveInvoiceCheckThroughRealValidator()
    {
        // AC9 asymmetric: a sibling already posted reduced the live invoice due
        // below the allocation, so the real validator's invoiceMatches fails ->
        // invoice_mismatch -> manual_review (no transaction). End-to-end, real validator.
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $transactions = new KuickPayPostingFakeTransactions();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => new KuickPayPostingValidatorRepo(null),
            'invoice_reader' => new KuickPayPostingValidatorInvoiceReader((object) [
                'id' => 55, 'client_id' => 10, 'status' => 'active', 'currency' => 'PKR',
                'total' => 1000.0, 'paid' => 600.0, 'due' => 400.0,
            ]),
        ]);
        $service = new KuickPayPostingService([
            'voucher_repository' => $repo,
            'evidence_validator' => $validator,
            'audit_service' => new KuickPayPostingFakeAuditService(),
            'lock_repository' => new KuickPayPostingFakeLockRepository(),
            'transactions' => $transactions,
        ]);

        $result = $service->postVoucher(1, $this->voucher());

        $this->assertSame('manual_review', $result['outcome']);
        $this->assertContains(
            'invoice_mismatch',
            json_decode($repo->edits[0]['diagnostic_summary'], true)['validation_errors']
        );
        $this->assertSame([], $transactions->adds);
    }

    public function testPostConfirmedUsesLockAndCountsOutcomes()
    {
        $repo = new KuickPayPostingFakeVoucherRepository([$this->voucher()], [$this->invoiceLink()]);
        $lock = new KuickPayPostingFakeLockRepository();
        $service = new KuickPayPostingService([
            'voucher_repository' => $repo,
            'evidence_validator' => new KuickPayPostingFakeEvidenceValidator(true),
            'audit_service' => new KuickPayPostingFakeAuditService(),
            'lock_repository' => $lock,
            'transactions' => new KuickPayPostingFakeTransactions(),
        ]);

        $result = $service->postConfirmed(1);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($lock->released);
        $this->assertSame(1, $result['counts']['processed']);
        $this->assertSame(1, $result['counts']['posted']);
        $this->assertSame([1, KuickPayPostingService::BATCH_SIZE, 0], $repo->postableCall);
    }

    public function testHeldPostLockSkipsBatch()
    {
        $lock = new KuickPayPostingFakeLockRepository(false);
        $service = new KuickPayPostingService([
            'voucher_repository' => new KuickPayPostingFakeVoucherRepository([], []),
            'evidence_validator' => new KuickPayPostingFakeEvidenceValidator(true),
            'audit_service' => new KuickPayPostingFakeAuditService(),
            'lock_repository' => $lock,
            'transactions' => new KuickPayPostingFakeTransactions(),
        ]);

        $result = $service->postConfirmed(1);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('lock_held', $result['reason']);
        $this->assertFalse($lock->released);
    }

    private function service(
        KuickPayPostingFakeVoucherRepository $repo,
        KuickPayPostingFakeTransactions $transactions,
        KuickPayPostingFakeAuditService $audit = null,
        KuickPayPostingFakeEvidenceValidator $validator = null
    ): KuickPayPostingService {
        return new KuickPayPostingService([
            'voucher_repository' => $repo,
            'evidence_validator' => $validator ?? new KuickPayPostingFakeEvidenceValidator(true),
            'audit_service' => $audit ?? new KuickPayPostingFakeAuditService(),
            'lock_repository' => new KuickPayPostingFakeLockRepository(),
            'transactions' => $transactions,
        ]);
    }

    private function voucher(array $overrides = [])
    {
        return (object) array_merge([
            'id' => 1,
            'company_id' => 1,
            'gateway_id' => 20,
            'client_id' => 10,
            'status' => 'confirmed_unposted',
            'amount' => '1000.00',
            'currency' => 'PKR',
            'registration_number' => 'REG-0000001',
            'consumer_number' => 'INSTITUTION_IDREG-0000001',
            'date_paid' => '2026-06-09 00:00:00',
            'kuickpay_reference' => 'KP-REF-PAID',
            'evidence_hash' => 'hash',
            'blesta_transaction_id' => null,
            'diagnostic_summary' => json_encode(['status' => 'confirmed_unposted', 'validation_errors' => []]),
        ], $overrides);
    }

    private function invoiceLink(array $overrides = [])
    {
        return (object) array_merge([
            'voucher_id' => 1,
            'invoice_id' => 55,
            'amount' => '1000.00',
        ], $overrides);
    }

    private function transaction(array $overrides = [])
    {
        return (object) array_merge([
            'id' => 777,
            'transaction_id' => 'KP-REF-PAID',
            'client_id' => 10,
            'gateway_id' => 20,
            'amount' => '1000.00',
            'currency' => 'PKR',
            'status' => 'approved',
        ], $overrides);
    }
}

class KuickPayPostingFakeVoucherRepository
{
    public KuickPayPostingFakeRecord $record;
    public array $edits = [];
    public ?array $postableCall = null;
    private array $vouchers;
    private array $links;

    public function __construct(array $vouchers, array $links)
    {
        $this->vouchers = $vouchers;
        $this->links = $links;
        $this->record = new KuickPayPostingFakeRecord();
    }

    public function getPostable(int $company_id, int $limit, int $afterId = 0): array
    {
        $this->postableCall = [$company_id, $limit, $afterId];

        return $this->vouchers;
    }

    public function record()
    {
        return $this->record;
    }

    public function getForUpdate(int $voucher_id, int $company_id): ?stdClass
    {
        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id && (int) $voucher->company_id === $company_id) {
                return $voucher;
            }
        }

        return null;
    }

    public function getInvoiceLinksForUpdate(int $voucher_id): array
    {
        return $this->links;
    }

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $this->edits[] = $vars;
    }

    public function incrementPostingAttempts(int $voucher_id, int $company_id): int
    {
        foreach ($this->vouchers as $voucher) {
            // Faithfully model the status-guarded increment: only a still
            // confirmed_unposted, company-scoped row is incremented.
            if ((int) $voucher->id === $voucher_id
                && (int) $voucher->company_id === $company_id
                && (string) $voucher->status === 'confirmed_unposted'
            ) {
                $voucher->posting_attempts = (int) ($voucher->posting_attempts ?? 0) + 1;

                return (int) $voucher->posting_attempts;
            }
        }

        return 0;
    }

    public function transitionFromConfirmedUnposted(
        int $voucher_id,
        int $company_id,
        string $new_status,
        array $extra = []
    ): bool {
        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id
                && (int) $voucher->company_id === $company_id
                && (string) $voucher->status === 'confirmed_unposted'
            ) {
                $this->edit($voucher_id, $company_id, array_merge($extra, ['status' => $new_status]));
                $voucher->status = $new_status;

                return true;
            }
        }

        return false;
    }
}

class KuickPayPostingFakeRecord
{
    public bool $begun = false;
    public bool $committed = false;
    public bool $rolledBack = false;

    public function begin(): void
    {
        $this->begun = true;
    }

    public function commit(): void
    {
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
    }
}

class KuickPayPostingFakeEvidenceValidator
{
    private bool $valid;
    private array $reasons;
    public array $allowedStatuses = [];

    public function __construct(bool $valid, array $reasons = [])
    {
        $this->valid = $valid;
        $this->reasons = $reasons;
    }

    public function validate(
        stdClass $voucher,
        array $invoiceLinks,
        KuickPayEvidence $evidence,
        array $allowedStatuses = ['pending', 'retry']
    ): KuickPayValidationResult {
        $this->allowedStatuses = $allowedStatuses;

        return new KuickPayValidationResult($this->valid, $this->reasons);
    }
}

class KuickPayPostingFakeTransactions
{
    public array $adds = [];
    public array $applies = [];
    public array $applied = [];
    public $existing = false;
    public array $listResults = [];
    public array $appliedByTransaction = [];
    public array $addErrors = [];
    public array $applyErrors = [];
    public bool $autoApplyToApplied = false;
    public int $getByTransactionIdCalls = 0;
    private array $lastErrors = [];

    public function getByTransactionId($transaction_id, $client_id = null, $gateway_id = null)
    {
        $this->getByTransactionIdCalls++;

        return $this->existing;
    }

    public function getList(
        $client_id = null,
        $status = 'approved',
        $page = 1,
        $order_by = ['date_added' => 'DESC'],
        array $filters = []
    ): array {
        // The service narrows by exact reference/gateway/status itself; return the
        // configured candidate set unfiltered (mirrors getList's broad partial match).
        return $this->listResults;
    }

    public function add(array $vars)
    {
        $this->adds[] = $vars;
        $this->lastErrors = $this->addErrors;

        return empty($this->addErrors) ? 9001 : null;
    }

    public function apply($transaction_id, array $vars): void
    {
        $this->applies[] = ['transaction_id' => $transaction_id, 'vars' => $vars];
        $this->lastErrors = $this->applyErrors;

        if ($this->autoApplyToApplied && empty($this->applyErrors)) {
            $this->applied = array_map(function ($amount) {
                return (object) [
                    'invoice_id' => $amount['invoice_id'],
                    'applied_amount' => $amount['amount'],
                ];
            }, $vars['amounts']);
        }
    }

    public function getApplied($transaction_id = null, $invoice_id = null): array
    {
        // Per-transaction applied sets let a multi-candidate adoption test model
        // different allocations per candidate; fall back to the shared set.
        return $this->appliedByTransaction[(int) $transaction_id] ?? $this->applied;
    }

    public function errors(): array
    {
        return $this->lastErrors;
    }
}

class KuickPayPostingFakeAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = [$eventName, $context];
    }
}

class KuickPayPostingFakeLockRepository
{
    private bool $available;
    public bool $released = false;

    public function __construct(bool $available = true)
    {
        $this->available = $available;
    }

    public function acquire(int $company_id, string $lockName, int $ttlSeconds): ?string
    {
        return $this->available ? 'owner-token' : null;
    }

    public function release(int $company_id, string $lockName, string $ownerToken): void
    {
        $this->released = true;
    }
}

class KuickPayPostingValidatorRepo
{
    private ?stdClass $sibling;

    public function __construct(?stdClass $sibling = null)
    {
        $this->sibling = $sibling;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->sibling;
    }

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return null;
    }
}

class KuickPayPostingValidatorInvoiceReader
{
    private $invoice;

    public function __construct($invoice)
    {
        $this->invoice = $invoice;
    }

    public function get(int $invoice_id): ?stdClass
    {
        return $this->invoice;
    }
}
