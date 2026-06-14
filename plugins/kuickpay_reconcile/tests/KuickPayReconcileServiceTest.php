<?php

use PHPUnit\Framework\TestCase;

class KuickPayReconcileServiceTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures/kuickpay';

    public function testPaidExactInquiryTransitionsToConfirmedUnpostedWithoutPosting()
    {
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $result = $service->runCron(1);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['consumerNumber' => 'REG-0000001'], $client->requests[0]);
        $context = $service->buildParserContext($voucher);
        $this->assertSame('REG-0000001', $context['expected_consumer_number']);
        $this->assertArrayNotHasKey('expected_registration_number', $context);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertSame('1000.00', $repo->edits[0]['amount']);
        $this->assertSame('2026-06-09 00:00:00', $repo->edits[0]['date_paid']);
        $this->assertSame('KP-REF-PAID', $repo->edits[0]['kuickpay_reference']);
        $this->assertNotSame('posted', $repo->edits[0]['status']);
        $this->assertStringNotContainsString(
            'BillPaymentInquiryResult',
            $repo->edits[0]['diagnostic_summary']
        );
    }

    public function testConfirmedEvidenceRejectedByValidatorMovesToManualReviewWithoutPaymentFields()
    {
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => new KuickPayReconcileFakeEvidenceValidator(false, ['invoice_mismatch']),
        ]);

        $service->runCron(1);

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertArrayNotHasKey('amount', $repo->edits[0]);
        $this->assertArrayNotHasKey('date_paid', $repo->edits[0]);
        $this->assertArrayNotHasKey('kuickpay_reference', $repo->edits[0]);
        $this->assertSame('manual_review', $items->items[0]['new_status']);
        $this->assertContains('evidence.rejected', array_column($audit->events, 0));

        $diagnostic = json_decode($repo->edits[0]['diagnostic_summary'], true);
        $this->assertSame('confirmed_unposted', $diagnostic['status']);
        $this->assertSame(['invoice_mismatch'], $diagnostic['validation_errors']);
    }

    /**
     * @dataProvider parserLevelExceptionProvider
     */
    public function testParserLevelExceptionEvidenceAppliesPolicyAndStaysManualReviewWithoutPaymentFields(
        string $fixture,
        string $expectedReason
    ) {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult($fixture)),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher()]);
        $audit = new KuickPayReconcileFakeAuditService();
        $validator = new KuickPayReconcileFakeEvidenceValidator(true);
        $service = $this->service([
            'voucher_repository' => $repo,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $service->runCron(1);

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertArrayNotHasKey('amount', $repo->edits[0]);
        $this->assertArrayNotHasKey('date_paid', $repo->edits[0]);
        $this->assertArrayNotHasKey('kuickpay_reference', $repo->edits[0]);
        $this->assertFalse($validator->called);
        $this->assertContains('evidence.rejected', array_column($audit->events, 0));

        $diagnostic = json_decode($repo->edits[0]['diagnostic_summary'], true);
        $this->assertSame([$expectedReason], $diagnostic['validation_errors']);
    }

    public function parserLevelExceptionProvider(): array
    {
        return [
            'underpayment' => ['ambiguous/bill-payment-inquiry-amount-mismatch.xml', 'underpayment'],
            'overpayment' => ['ambiguous/bill-payment-inquiry-overpayment.xml', 'overpayment'],
        ];
    }

    public function testLatePaymentEvidenceAppliesPolicyAndStaysManualReviewWithoutPaymentFields()
    {
        $voucher = $this->voucher(['date_expires' => '2026-06-08']);
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('ambiguous/bill-payment-inquiry-late-after-expiry.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $audit = new KuickPayReconcileFakeAuditService();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $service->runCron(1);

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertArrayNotHasKey('amount', $repo->edits[0]);
        $this->assertArrayNotHasKey('date_paid', $repo->edits[0]);
        $this->assertArrayNotHasKey('kuickpay_reference', $repo->edits[0]);
        $this->assertContains('evidence.rejected', array_column($audit->events, 0));

        $diagnostic = json_decode($repo->edits[0]['diagnostic_summary'], true);
        $this->assertSame(['late_payment'], $diagnostic['validation_errors']);
    }

    public function testMissingFreshVoucherFailsClosedWithoutCallingValidator()
    {
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $repo->freshDataOverride = null;
        $validator = new KuickPayReconcileFakeEvidenceValidator(true);
        $service = $this->service([
            'voucher_repository' => $repo,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $service->runCron(1);

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertFalse($validator->called);

        $diagnostic = json_decode($repo->edits[0]['diagnostic_summary'], true);
        $this->assertSame(['stale_voucher'], $diagnostic['validation_errors']);
    }

    /**
     * @dataProvider fixtureMappingProvider
     */
    public function testFixtureBackedStateMappings(string $fixture, string $expectedStatus, ?string $expectedError)
    {
        $client = new KuickPayReconcileFakeClient([$this->outcome($this->fixtureResult($fixture))]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher()]);
        $service = $this->service(['voucher_repository' => $repo, 'client' => $client]);

        $service->runCron(1);

        $this->assertSame($expectedStatus, $repo->edits[0]['status']);
        $this->assertSame($expectedError, $repo->edits[0]['error_class']);
    }

    public function fixtureMappingProvider(): array
    {
        return [
            ['valid/bill-payment-inquiry-pending.xml', 'pending', null],
            ['ambiguous/bill-payment-inquiry-amount-mismatch.xml', 'manual_review', 'amount_mismatch'],
            ['valid/bill-payment-inquiry-expired.xml', 'expired', null],
            ['ambiguous/bill-payment-inquiry-unknown.xml', 'manual_review', 'unknown_status'],
        ];
    }

    public function testTransportTimeoutMovesToRetryAndIncrementsRetryCount()
    {
        $client = new KuickPayReconcileFakeClient([[
            'ok' => false,
            'operation' => 'BillPaymentInquiry',
            'raw_result' => null,
            'error_class' => 'timeout',
            'redacted_trace_id' => 'kp_timeout',
        ]]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher(['retry_count' => 1])]);
        $service = $this->service(['voucher_repository' => $repo, 'client' => $client]);

        $service->runCron(1);

        $this->assertSame('retry', $repo->edits[0]['status']);
        $this->assertSame(2, $repo->edits[0]['retry_count']);
        $this->assertSame('timeout', $repo->edits[0]['error_class']);
    }

    public function testRetryLimitEscalatesTransportFailureToManualReview()
    {
        $client = new KuickPayReconcileFakeClient([[
            'ok' => false,
            'operation' => 'BillPaymentInquiry',
            'raw_result' => null,
            'error_class' => 'transport_error',
            'redacted_trace_id' => 'kp_timeout',
        ]]);
        $repo = new KuickPayReconcileFakeVoucherRepository([
            $this->voucher(['status' => 'retry', 'retry_count' => 4]),
        ]);
        $service = $this->service(['voucher_repository' => $repo, 'client' => $client]);

        $service->runCron(1);

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertSame(5, $repo->edits[0]['retry_count']);
    }

    public function testHeldLockSkipsWithoutOpeningRun()
    {
        $lock = new KuickPayReconcileFakeLockRepository(false);
        $run = new KuickPayReconcileFakeRunRepository();
        $service = $this->service(['lock_repository' => $lock, 'run_repository' => $run]);

        $result = $service->runCron(1);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('lock_held', $result['reason']);
        $this->assertSame(0, $run->opened);
    }

    public function testReconcileVoucherConfirmsOneVoucherInManualRunWithoutPosting()
    {
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $run = new KuickPayReconcileFakeRunRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $result = $service->reconcileVoucher(1, 1);

        $this->assertSame('confirmed_unposted', $result['status']);
        $this->assertSame(10, $result['run_id']);
        $this->assertSame(1, $result['voucher_id']);
        $this->assertSame('manual', $run->openedTriggerType);
        $this->assertSame(0, $run->openedCursor);
        $this->assertSame(0, $run->resumeCalls, 'manual single-voucher reconcile must not use the cron cursor');
        $this->assertSame(0, $run->closedCursor);
        $this->assertSame(1, $run->closedCounts['total_eligible']);
        $this->assertSame(1, $run->closedCounts['total_confirmed']);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertNotSame('posted', $repo->edits[0]['status']);
        $this->assertContains('reconciliation.run.started', array_column($audit->events, 0));
        $this->assertContains('reconciliation.run.completed', array_column($audit->events, 0));
    }

    public function testReconcileVoucherSkipsWhenGatewayConfigUnavailable()
    {
        $service = new KuickPayReconcileService([
            'voucher_repository' => new KuickPayReconcileFakeVoucherRepository([$this->voucher()]),
            'run_repository' => new KuickPayReconcileFakeRunRepository(),
            'item_repository' => new KuickPayReconcileFakeItemRepository(),
            'lock_repository' => new KuickPayReconcileFakeLockRepository(),
            'audit_service' => new KuickPayReconcileFakeAuditService(),
            'parser' => new KuickPayResponseParser(),
            'evidence_validator' => new KuickPayReconcileFakeEvidenceValidator(true),
            'gateway_config' => null,
        ]);

        $result = $service->reconcileVoucher(1, 1);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('kuickpay_unavailable', $result['reason']);
    }

    public function testReconcileVoucherShortCircuitsNonReconcilableFreshStatusWithoutProviderCall()
    {
        $voucher = $this->voucher(['status' => 'confirmed_unposted']);
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $run = new KuickPayReconcileFakeRunRepository();
        $service = $this->service([
            'voucher_repository' => new KuickPayReconcileFakeVoucherRepository([$voucher]),
            'run_repository' => $run,
            'client' => $client,
        ]);

        $result = $service->reconcileVoucher(1, 1);

        $this->assertSame('confirmed_unposted', $result['status']);
        $this->assertSame(1, $result['voucher_id']);
        $this->assertSame([], $client->requests);
        $this->assertSame(0, $run->opened);
    }

    public function testReconcileVoucherProviderExceptionReturnsUnavailableToken()
    {
        $client = new KuickPayReconcileFakeClient([
            new RuntimeException('provider timed out'),
        ]);
        $run = new KuickPayReconcileFakeRunRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => new KuickPayReconcileFakeVoucherRepository([$this->voucher()]),
            'run_repository' => $run,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $result = $service->reconcileVoucher(1, 1);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('provider_unreachable', $result['reason']);
        $this->assertSame(10, $result['run_id']);
        $this->assertSame(1, $run->closedCounts['total_errors']);
        $this->assertContains('evidence.error', array_column($audit->events, 0));
        $errorEvent = $audit->events[array_search('evidence.error', array_column($audit->events, 0), true)];
        $this->assertSame(1, $errorEvent[1]['company_id']);
        $this->assertSame(1, $errorEvent[1]['voucher_id']);
        $this->assertSame(10, $errorEvent[1]['run_id']);
        $this->assertSame('', $errorEvent[1]['redacted_trace_id']);
        $this->assertSame(['error_class' => 'reconcile_exception'], $errorEvent[1]['payload']);
    }

    public function testManualReconcileDoesNotDemoteAVoucherConfirmedByaConcurrentCron()
    {
        // AC1: Manual Check Now selects a still-pending voucher, but while its SOAP
        // inquiry is in flight the cron flips pending -> confirmed_unposted (writing a
        // date_paid). The manual call's confirmed-branch stale guard would compute
        // manual_review; the status-guarded terminal write must then match ZERO rows
        // (the row already left pending/retry) so the confirmed-paid voucher is never
        // demoted to a stuck manual_review with a dangling date_paid.
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        // The concurrent cron wins exactly when the manual path re-reads the row
        // inside the confirmed branch: flip it to confirmed_unposted + date_paid.
        $repo->raceFlipOnGetWithInvoices = ['status' => 'confirmed_unposted', 'date_paid' => '2026-06-09 00:00:00'];
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $result = $service->reconcileVoucher(1, 1);

        // The guarded write matched zero rows: no demotion, no edit, no date_paid clobber.
        $this->assertSame('confirmed_unposted', $result['status']);
        $this->assertSame('confirmed_unposted', $voucher->status);
        $this->assertSame('2026-06-09 00:00:00', $voucher->date_paid);
        $this->assertSame([], $repo->edits, 'a racing manual reconcile must not write a demotion');
        // The recorded item/audit reflect the benign actual state, never a false manual_review.
        $this->assertSame('confirmed_unposted', $items->items[0]['new_status']);
        $this->assertNotContains('evidence.rejected', array_column($audit->events, 0));
    }

    public function testProcessVoucherThreadsGatewayConfigExplicitlyIntoPersistence()
    {
        // AC4: gateway_config is threaded structurally through the public
        // processVoucher() signature rather than read back from a mutable member,
        // so persistEvidence()'s exception-policy resolution can never deref a null
        // member (the 3-6 footgun). The config passed here reaches the point of use.
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $outcome = $service->processVoucher(1, 10, $voucher, $client, ['underpayment_policy' => 'manual_review']);

        $this->assertFalse($outcome['error']);
        $this->assertSame('confirmed_unposted', $outcome['new_status']);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
    }

    public function testProcessVoucherWrapsPerVoucherWritesInACommittedTransaction()
    {
        // AC5.1: the three per-Voucher writes (edit + item + audit) execute inside
        // one DB transaction; the happy path commits and never rolls back.
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $items = new KuickPayReconcileFakeItemRepository();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $service->runCron(1);

        $this->assertTrue($repo->record->begun);
        $this->assertTrue($repo->record->committed);
        $this->assertFalse($repo->record->rolledBack);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertSame('confirmed_unposted', $items->items[0]['new_status']);
    }

    public function testProcessVoucherRollsBackThenWritesFailureItemAfterRollback()
    {
        // AC5.1/5.2: a write failure inside the wrapped block rolls the transaction
        // back, and the failure-path item + evidence.error audit are written on a
        // FRESH statement after the rollBack() (mirroring the posting service).
        $voucher = $this->voucher();
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-pending.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $repo->throwOnEditIfActive = true;
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $service->runCron(1);

        $this->assertTrue($repo->record->begun);
        $this->assertTrue($repo->record->rolledBack);
        $this->assertFalse($repo->record->committed);
        // No success-path edit landed; only the failure-path item + audit remain.
        $this->assertSame([], $repo->edits);
        $this->assertCount(1, $items->items);
        $this->assertSame('reconcile_exception', $items->items[0]['error_class']);
        $this->assertContains('evidence.error', array_column($audit->events, 0));
    }

    public function testRunBulkWrapsEachRowWriteInACommittedTransaction()
    {
        // AC5.1: the bulk loop's per-Voucher writes are wrapped in a transaction too.
        $voucher = $this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
        ]);
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('valid/bill-payment-bulk-matched-paid.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $result = $service->runBulk(1, '2026-06-09');

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($repo->record->begun);
        $this->assertTrue($repo->record->committed);
        $this->assertFalse($repo->record->rolledBack);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
    }

    public function testManualActionSafetyMapsMatchDisplayStateMatrix()
    {
        $this->assertSame(
            [
                'pending' => ['recheck', 'review', 'cancel'],
                'retry' => ['recheck', 'review', 'cancel'],
                'confirmed_unposted' => ['recheck', 'review'],
                'posted' => [],
                'failed' => ['review', 'cancel'],
                'expired' => ['review', 'cancel'],
                'manual_review' => ['cancel'],
                'cancelled' => [],
            ],
            KuickpayVouchers::ALLOWED_ACTIONS_BY_STATE
        );

        $this->assertSame(
            ['pending', 'retry', 'failed', 'expired', 'confirmed_unposted'],
            KuickpayVouchers::ALLOWED_FROM_BY_ACTION['review']
        );
        $this->assertSame(
            ['pending', 'retry', 'failed', 'expired', 'manual_review'],
            KuickpayVouchers::ALLOWED_FROM_BY_ACTION['cancel']
        );
        $this->assertNotContains('confirmed_unposted', KuickpayVouchers::ALLOWED_FROM_BY_ACTION['cancel']);
        $this->assertNotContains('posted', KuickpayVouchers::ALLOWED_FROM_BY_ACTION['cancel']);
        $this->assertNotContains('cancelled', KuickpayVouchers::ALLOWED_FROM_BY_ACTION['cancel']);
        $this->assertNotContains('posted', KuickpayVouchers::ALLOWED_FROM_BY_ACTION['review']);
        $this->assertNotContains('cancelled', KuickpayVouchers::ALLOWED_FROM_BY_ACTION['review']);
    }

    public function testExpirePendingSkipsWhenExpiryLockHeld()
    {
        $lock = new KuickPayReconcileFakeLockRepository(false);
        $run = new KuickPayReconcileFakeRunRepository();
        $service = $this->service(['lock_repository' => $lock, 'run_repository' => $run]);

        $result = $service->expirePending(1);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('lock_held', $result['reason']);
        $this->assertSame(['processed' => 0, 'expired' => 0, 'errors' => 0], $result['counts']);
        $this->assertSame(0, $run->opened);
        $this->assertSame('expire_vouchers', $lock->acquiredLockName);
    }

    public function testExpirePendingTransitionsOnlyExpirableVouchersAndAudits()
    {
        $repo = new KuickPayReconcileFakeVoucherRepository([
            $this->voucher(['id' => 1, 'status' => 'pending', 'date_expires' => '2026-06-08']),
            $this->voucher(['id' => 2, 'status' => 'retry', 'date_expires' => '2026-06-08']),
            $this->voucher(['id' => 3, 'status' => 'pending', 'date_expires' => '2026-06-11']),
            $this->voucher(['id' => 4, 'status' => 'pending', 'date_expires' => null]),
            $this->voucher(['id' => 5, 'status' => 'pending', 'currency' => 'USD', 'date_expires' => '2026-06-08']),
            $this->voucher(['id' => 6, 'status' => 'confirmed_unposted', 'date_expires' => '2026-06-08']),
        ]);
        $lock = new KuickPayReconcileFakeLockRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $client = new KuickPayReconcileFakeClient([]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'lock_repository' => $lock,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $result = $service->expirePending(1);
        $rerun = $service->expirePending(1);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['processed' => 2, 'expired' => 2, 'errors' => 0], $result['counts']);
        $this->assertSame(['processed' => 0, 'expired' => 0, 'errors' => 0], $rerun['counts']);
        $this->assertSame([1, 2], array_column($repo->edits, 'voucher_id'));
        $this->assertSame('expired', $repo->edits[0]['status']);
        $this->assertSame('expired', $repo->edits[1]['status']);
        $this->assertSame(['voucher.expired', 'voucher.expired'], array_column($audit->events, 0));
        $this->assertSame(['prior_status' => 'pending'], $audit->events[0][1]['payload']);
        $this->assertSame(['prior_status' => 'retry'], $audit->events[1][1]['payload']);
        $this->assertSame(1, $audit->events[0][1]['company_id']);
        $this->assertSame(1, $audit->events[0][1]['voucher_id']);
        $this->assertTrue($lock->released);
        $this->assertSame([], $client->requests);
    }

    public function testExpirePendingSkipsAuditAndCountWhenGuardedTransitionDoesNotApply()
    {
        $repo = new KuickPayReconcileFakeVoucherRepository([
            $this->voucher(['id' => 1, 'status' => 'pending', 'date_expires' => '2026-06-08']),
        ]);
        // Simulate a voucher that left pending/retry between getExpirable() and
        // the guarded UPDATE (e.g. reconcile confirmed it under a clock skew):
        // expire() reports no transition, so the sweep must neither audit nor count it.
        $repo->forcedExpireResult = false;
        $lock = new KuickPayReconcileFakeLockRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $client = new KuickPayReconcileFakeClient([]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'lock_repository' => $lock,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $result = $service->expirePending(1);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['processed' => 1, 'expired' => 0, 'errors' => 0], $result['counts']);
        $this->assertSame([], $audit->events);
        $this->assertSame([], $repo->edits);
        $this->assertTrue($lock->released);
    }

    public function testRunUsesResumeCursorAndClosesCompletedRunWithResetCursor()
    {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-pending.xml')),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher(['id' => 6])]);
        $run = new KuickPayReconcileFakeRunRepository(5);
        $service = $this->service([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'client' => $client,
        ]);

        $service->runCron(1);

        $this->assertSame(5, $repo->lastAfterId);
        $this->assertSame(0, $run->closedCursor);
        // AC4.4: the cron run resolves its resume cursor scoped to its own
        // trigger_type, never a hard-coded 'cron' regardless of the caller.
        $this->assertSame(1, $run->resumeCalls);
        $this->assertSame('cron', $run->resumeTriggerType);
    }

    public function testRunBulkHappyPathMatchesByConsumerNumberAndQueuesConfirmed()
    {
        $voucher = $this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
        ]);
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('valid/bill-payment-bulk-matched-paid.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $run = new KuickPayReconcileFakeRunRepository();
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $result = $service->runBulk(1, '2026-06-09');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(['TransactionDate' => '20260609'], $client->bulkRequests[0]);
        $this->assertSame('2026-06-09', $run->openedBulkDate);
        $this->assertSame(0, $run->resumeCalls);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertSame('KP-BULK-PAID-0001', $repo->edits[0]['kuickpay_reference']);
        $this->assertSame('confirmed_unposted', $items->items[0]['new_status']);
        $this->assertSame(1, $run->closedCounts['total_checked']);
        $this->assertSame(1, $run->closedCounts['total_confirmed']);
        $this->assertSame(0, $run->closedCounts['total_unmatched']);
        $this->assertContains('reconciliation.run.started', array_column($audit->events, 0));
        $this->assertContains('reconciliation.run.completed', array_column($audit->events, 0));
    }

    public function testRunBulkUnmatchedRowAuditsWithoutItemOrVoucherMutation()
    {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('ambiguous/bill-payment-bulk-unmatched.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher()]);
        $run = new KuickPayReconcileFakeRunRepository();
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $result = $service->runBulk(1, '2026-06-09');

        $this->assertSame('completed', $result['status']);
        $this->assertSame([], $repo->edits);
        $this->assertSame([], $items->items);
        $this->assertSame(1, $run->closedCounts['total_unmatched']);
        $this->assertSame(1, $run->closedCounts['total_manual_review']);
        $this->assertContains('evidence.unmatched', array_column($audit->events, 0));
    }

    public function testRunBulkMalformedBulkResponseFailsRunWithoutVoucherMutation()
    {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('malformed/bill-payment-bulk-malformed-xml.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$this->voucher()]);
        $run = new KuickPayReconcileFakeRunRepository();
        $service = $this->service([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'client' => $client,
        ]);

        $result = $service->runBulk(1, '2026-06-09');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $repo->edits);
        $this->assertSame(1, $run->closedCounts['total_errors']);
        $this->assertSame(1, $run->closedCounts['total_failed']);
    }

    public function testRunBulkHeldLockSkipsWithoutOpeningRun()
    {
        $lock = new KuickPayReconcileFakeLockRepository(false);
        $run = new KuickPayReconcileFakeRunRepository();
        $service = $this->service(['lock_repository' => $lock, 'run_repository' => $run]);

        $result = $service->runBulk(1, '2026-06-09');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('lock_held', $result['reason']);
        $this->assertSame(0, $run->opened);
        $this->assertSame('reconcile_pending', $lock->acquiredLockName);
    }

    public function testRunBulkLatePaymentOnPendingExpiredVoucherMovesToManualReview()
    {
        $row = '<NewDataSet><Table>'
            . '<Consumer_Number>INSTITUTION_ID1234INVOICE_ID</Consumer_Number>'
            . '<Registration_Number>1234INVOICE_ID</Registration_Number>'
            . '<Transaction_Date>20260615</Transaction_Date>'
            . '<Paid_Amount>1000.00</Paid_Amount>'
            . '<Transaction_Reference>KP-BULK-LATE-0001</Transaction_Reference>'
            . '<Currency>PKR</Currency>'
            . '</Table></NewDataSet>';
        $voucher = $this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
            'date_expires' => '2026-06-08',
        ]);
        $client = new KuickPayReconcileFakeClient([
            $this->outcome($row, 'BillPaymentBulkInquiry'),
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $validator = new KuickPayEvidenceValidator([
            'voucher_repository' => $repo,
            'invoice_reader' => new KuickPayReconcileFakeInvoiceReader($this->invoice()),
        ]);
        $service = $this->service([
            'voucher_repository' => $repo,
            'client' => $client,
            'evidence_validator' => $validator,
        ]);

        $service->runBulk(1, '2026-06-15');

        $this->assertSame('manual_review', $repo->edits[0]['status']);
        $this->assertArrayNotHasKey('kuickpay_reference', $repo->edits[0]);
        $diagnostic = json_decode($repo->edits[0]['diagnostic_summary'], true);
        $this->assertSame(['late_payment'], $diagnostic['validation_errors']);
    }

    public function testRunBulkDuplicateConsumerRowsDoNotDoubleConfirm()
    {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('valid/bill-payment-bulk-mixed-multi-row.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $voucher = $this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $service->runBulk(1, '2026-06-09');

        // The provider echoed the same Consumer Number twice. The first row confirms the
        // voucher; the duplicate must NOT write a second (run_id, voucher_id) item row
        // (unique key would abort the run) and must NOT demote the confirmed voucher.
        $this->assertSame(['confirmed_unposted'], array_column($items->items, 'new_status'));
        $this->assertCount(1, $repo->edits);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertSame('confirmed_unposted', $voucher->status);
        $this->assertContains('evidence.duplicate', array_column($audit->events, 0));
    }

    public function testRunBulkAlreadyConfirmedVoucherIsNotDemotedOnRerun()
    {
        $client = new KuickPayReconcileFakeClient([
            $this->outcome(
                $this->bulkFixtureResult('valid/bill-payment-bulk-matched-paid.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $voucher = $this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
            'status' => 'confirmed_unposted',
        ]);
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucher], [$this->invoiceLink()]);
        $items = new KuickPayReconcileFakeItemRepository();
        $audit = new KuickPayReconcileFakeAuditService();
        $service = $this->service([
            'voucher_repository' => $repo,
            'item_repository' => $items,
            'audit_service' => $audit,
            'client' => $client,
        ]);

        $result = $service->runBulk(1, '2026-06-09');

        // Re-running bulk for the same date over a voucher already past pending/retry must
        // be idempotent: no demotion to manual_review, no mutation, no second item row.
        $this->assertSame('completed', $result['status']);
        $this->assertSame('confirmed_unposted', $voucher->status);
        $this->assertSame([], $repo->edits);
        $this->assertSame([], $items->items);
        $this->assertContains('evidence.duplicate', array_column($audit->events, 0));
    }

    public function testBuildBulkRequestValidatesAndFormatsTransactionDate()
    {
        $service = $this->service();

        $this->assertSame(['TransactionDate' => '20260609'], $service->buildBulkRequest('2026-06-09'));

        $this->expectException(InvalidArgumentException::class);
        $service->buildBulkRequest('2026-99-09');
    }

    public function testSupplyingBothIdentityKeysFailsClosedRegressionGuard()
    {
        $evidence = (new KuickPayResponseParser())->parse(
            $this->outcome($this->fixtureResult('valid/bill-payment-inquiry-paid-exact.xml')),
            [
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
                'expected_registration_number' => 'REG-0000001',
                'expected_consumer_number' => 'INSTITUTION_IDREG-0000001',
            ]
        );

        $this->assertSame('manual_review', $evidence->status());
        $this->assertSame('unmatched_reference', $evidence->errorClass());
    }

    public function testRequiredInquiryConfigGuardFailsClosedOnBlankCredentials()
    {
        // AC4.3: gatewayConfigForCompany() must fail closed (-> kuickpay_unavailable,
        // no SOAP client built, no Vouchers burned toward retry/manual_review) when a
        // required inquiry key is missing/blank, instead of constructing a client with
        // empty credentials. The framework-dependent resolver delegates the decision to
        // this pure guard, which is exercised here directly.
        $method = new ReflectionMethod(KuickPayReconcileService::class, 'hasRequiredInquiryConfig');
        $method->setAccessible(true);
        $service = (new ReflectionClass(KuickPayReconcileService::class))->newInstanceWithoutConstructor();

        $base = [
            'wsdl_url' => 'https://example.com/api.asmx?WSDL',
            'institution_id' => 'KP01',
            'inquiry_username' => 'inq-user',
            'inquiry_password' => 'inq-secret',
            'voucher_username' => 'vou-user',
            'voucher_password' => 'vou-secret',
            'inquiry_same_as_voucher' => 'false',
        ];

        $this->assertTrue($method->invoke($service, $base));

        foreach (['wsdl_url', 'institution_id', 'inquiry_username', 'inquiry_password'] as $key) {
            $broken = $base;
            $broken[$key] = '';
            $this->assertFalse($method->invoke($service, $broken), $key . ' blank must fail closed');

            $whitespace = $base;
            $whitespace[$key] = '   ';
            $this->assertFalse($method->invoke($service, $whitespace), $key . ' whitespace must fail closed');
        }

        // inquiry_same_as_voucher reuses the voucher credential pair.
        $sameAsVoucher = array_merge($base, [
            'inquiry_same_as_voucher' => 'true',
            'inquiry_username' => '',
            'inquiry_password' => '',
        ]);
        $this->assertTrue($method->invoke($service, $sameAsVoucher));
        $this->assertFalse($method->invoke($service, array_merge($sameAsVoucher, ['voucher_username' => ''])));
        $this->assertFalse($method->invoke($service, array_merge($sameAsVoucher, ['voucher_password' => ''])));
    }

    public function testRealSoapClientReceivesOperationalLoggerOnlyWhenEnabled()
    {
        $service = new KuickPayReconcileService([
            'voucher_repository' => new KuickPayReconcileFakeVoucherRepository([]),
            'run_repository' => new KuickPayReconcileFakeRunRepository(),
            'item_repository' => new KuickPayReconcileFakeItemRepository(),
            'lock_repository' => new KuickPayReconcileFakeLockRepository(),
            'audit_service' => new KuickPayReconcileFakeAuditService(),
            'parser' => new KuickPayResponseParser(),
            'evidence_validator' => new KuickPayReconcileFakeEvidenceValidator(true),
            'gateway_config' => ['reconciliation_enabled' => 'true'],
            'logger' => new KuickPayReconcileFakeLogger(),
        ]);

        $enabledClient = $this->invokeClient($service, ['logging_enabled' => 'true']);
        $disabledClient = $this->invokeClient($service, ['logging_enabled' => 'false']);

        $this->assertInstanceOf(KuickPaySoapClient::class, $enabledClient);
        $this->assertNotNull($this->readPrivate($enabledClient, 'logger'));
        $this->assertNull($this->readPrivate($disabledClient, 'logger'));
    }

    private function service(array $overrides = []): KuickPayReconcileService
    {
        $client = $overrides['client'] ?? new KuickPayReconcileFakeClient([]);

        return new KuickPayReconcileService([
            'voucher_repository' => $overrides['voucher_repository'] ?? new KuickPayReconcileFakeVoucherRepository([]),
            'run_repository' => $overrides['run_repository'] ?? new KuickPayReconcileFakeRunRepository(),
            'item_repository' => $overrides['item_repository'] ?? new KuickPayReconcileFakeItemRepository(),
            'lock_repository' => $overrides['lock_repository'] ?? new KuickPayReconcileFakeLockRepository(),
            'audit_service' => $overrides['audit_service'] ?? new KuickPayReconcileFakeAuditService(),
            'parser' => new KuickPayResponseParser(),
            'evidence_validator' => $overrides['evidence_validator'] ?? new KuickPayReconcileFakeEvidenceValidator(true),
            'gateway_config' => ['reconciliation_enabled' => 'true'],
            'client_factory' => function () use ($client) {
                return $client;
            },
        ]);
    }

    private function invokeClient(KuickPayReconcileService $service, array $overrides)
    {
        $method = new ReflectionMethod($service, 'client');
        $method->setAccessible(true);

        return $method->invoke($service, array_merge([
            'wsdl_url' => 'https://example.com/api.asmx?WSDL',
            'soap_timeout' => '30',
            'institution_id' => 'KP01',
            'voucher_username' => 'voucher-user',
            'voucher_password' => 'voucher-secret',
            'inquiry_username' => 'inquiry-user',
            'inquiry_password' => 'inquiry-secret',
            'inquiry_same_as_voucher' => 'false',
        ], $overrides));
    }

    private function readPrivate($object, string $property)
    {
        $property = new ReflectionProperty($object, $property);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    private function voucher(array $overrides = [])
    {
        return (object) array_merge([
            'id' => 1,
            'company_id' => 1,
            'client_id' => 10,
            'status' => 'pending',
            'amount' => '1000.00',
            'currency' => 'PKR',
            'registration_number' => 'REG-0000001',
            // KuickPay echoes the Consumer Number in inquiry result field [1];
            // the fixtures put 'REG-0000001' there, so keep them aligned.
            'consumer_number' => 'REG-0000001',
            'retry_count' => 0,
        ], $overrides);
    }

    private function outcome(string $rawResult, string $operation = 'BillPaymentInquiry'): array
    {
        return [
            'ok' => true,
            'operation' => $operation,
            'raw_result' => $rawResult,
            'error_class' => null,
            'redacted_trace_id' => 'kp_trace',
        ];
    }

    private function fixtureResult(string $fixture): string
    {
        $xml = file_get_contents(self::FIXTURE_DIR . '/' . $fixture);
        preg_match('/<BillPaymentInquiryResult>(.*?)<\/BillPaymentInquiryResult>/s', $xml, $matches);

        return trim(html_entity_decode($matches[1]));
    }

    private function bulkFixtureResult(string $fixture): string
    {
        $xml = file_get_contents(self::FIXTURE_DIR . '/' . $fixture);
        preg_match('/<BillPaymentBulkInquiryResult><!\[CDATA\[(.*?)\]\]><\/BillPaymentBulkInquiryResult>/s', $xml, $matches);

        return trim($matches[1]);
    }

    private function invoiceLink(array $overrides = [])
    {
        return (object) array_merge([
            'voucher_id' => 1,
            'invoice_id' => 55,
            'amount' => '1000.00',
        ], $overrides);
    }

    private function invoice(array $overrides = [])
    {
        return (object) array_merge([
            'id' => 55,
            'client_id' => 10,
            'status' => 'active',
            'currency' => 'PKR',
            'total' => 1000.0,
            'paid' => 0.0,
            'due' => 1000.0,
        ], $overrides);
    }
}

class KuickPayReconcileFakeVoucherRepository
{
    public array $edits = [];
    public int $lastAfterId = 0;
    public ?array $freshDataOverride = [];
    public ?stdClass $duplicateReference = null;
    public ?stdClass $activeSibling = null;
    public ?bool $forcedExpireResult = null;
    public ?array $raceFlipOnGetWithInvoices = null;
    public bool $throwOnEditIfActive = false;
    public KuickPayReconcileFakeRecord $record;
    private array $vouchers;
    private array $invoiceLinks;

    public function __construct(array $vouchers, array $invoiceLinks = [])
    {
        $this->vouchers = $vouchers;
        $this->invoiceLinks = $invoiceLinks;
        $this->record = new KuickPayReconcileFakeRecord();
    }

    public function record(): KuickPayReconcileFakeRecord
    {
        return $this->record;
    }

    public function getReconcilable(int $company_id, int $limit, int $afterId = 0, string $minRecheckBefore = null): array
    {
        $this->lastAfterId = $afterId;

        return $this->vouchers;
    }

    public function getExpirable(int $company_id, int $limit, int $afterId = 0): array
    {
        $this->lastAfterId = $afterId;
        $today = '2026-06-10';
        $expirable = [];

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id <= $afterId
                || (int) $voucher->company_id !== $company_id
                || (string) $voucher->currency !== 'PKR'
                || !in_array((string) $voucher->status, ['pending', 'retry'], true)
                || empty($voucher->date_expires)
                || (string) $voucher->date_expires >= $today
            ) {
                continue;
            }

            $expirable[] = $voucher;
            if (count($expirable) >= $limit) {
                break;
            }
        }

        return $expirable;
    }

    public function expire(int $voucher_id, int $company_id): bool
    {
        if ($this->forcedExpireResult !== null) {
            return $this->forcedExpireResult;
        }

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id
                && (int) $voucher->company_id === $company_id
                && in_array((string) $voucher->status, ['pending', 'retry'], true)
            ) {
                $voucher->status = 'expired';
                $this->edits[] = [
                    'status' => 'expired',
                    'voucher_id' => $voucher_id,
                    'company_id_scope' => $company_id,
                ];

                return true;
            }
        }

        return false;
    }

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $vars['company_id_scope'] = $company_id;
        $vars['voucher_id'] = $voucher_id;
        $this->edits[] = $vars;

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id && (int) $voucher->company_id === $company_id) {
                foreach ($vars as $key => $value) {
                    if (in_array($key, ['company_id_scope', 'voucher_id'], true)) {
                        continue;
                    }

                    $voucher->{$key} = $value;
                }
            }
        }
    }

    public function editIfActive(int $voucher_id, int $company_id, array $vars): bool
    {
        if ($this->throwOnEditIfActive) {
            throw new RuntimeException('simulated voucher write failure');
        }

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id && (int) $voucher->company_id === $company_id) {
                // Faithfully model the status-guarded UPDATE: a row that already
                // left pending/retry matches zero rows, so nothing is written.
                if (!in_array((string) $voucher->status, ['pending', 'retry'], true)) {
                    return false;
                }

                $this->edit($voucher_id, $company_id, $vars);

                return true;
            }
        }

        return false;
    }

    public function getWithInvoices(int $voucher_id): ?array
    {
        // Simulate a concurrent writer winning the race between the manual entry
        // read and the confirmed-branch re-read: flip the row on this re-read.
        if ($this->raceFlipOnGetWithInvoices !== null) {
            foreach ($this->vouchers as $voucher) {
                if ((int) $voucher->id === $voucher_id) {
                    foreach ($this->raceFlipOnGetWithInvoices as $key => $value) {
                        $voucher->{$key} = $value;
                    }
                }
            }
            $this->raceFlipOnGetWithInvoices = null;
        }

        if ($this->freshDataOverride !== []) {
            return $this->freshDataOverride;
        }

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id) {
                return ['voucher' => $voucher, 'invoices' => $this->invoiceLinks];
            }
        }

        return null;
    }

    public function getByConsumerNumber(string $consumer_number, int $company_id): ?stdClass
    {
        foreach ($this->vouchers as $voucher) {
            if ((string) $voucher->consumer_number === $consumer_number && (int) $voucher->company_id === $company_id) {
                return $voucher;
            }
        }

        return null;
    }

    public function getForCompany(int $voucher_id, int $company_id)
    {
        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id && (int) $voucher->company_id === $company_id) {
                return $voucher;
            }
        }

        return false;
    }

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->duplicateReference;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->activeSibling;
    }
}

class KuickPayReconcileFakeRecord
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

class KuickPayReconcileFakeEvidenceValidator
{
    public ?stdClass $voucher = null;
    public array $invoiceLinks = [];
    public bool $called = false;
    private bool $valid;
    private array $reasons;

    public function __construct(bool $valid, array $reasons = [])
    {
        $this->valid = $valid;
        $this->reasons = $reasons;
    }

    public function validate(stdClass $voucher, array $invoiceLinks, KuickPayEvidence $evidence): KuickPayValidationResult
    {
        $this->called = true;
        $this->voucher = $voucher;
        $this->invoiceLinks = $invoiceLinks;

        return new KuickPayValidationResult($this->valid, $this->reasons);
    }
}

class KuickPayReconcileFakeInvoiceReader
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

class KuickPayReconcileFakeRunRepository
{
    public int $opened = 0;
    public int $closedCursor = -1;
    public int $resumeCalls = 0;
    public ?string $resumeTriggerType = null;
    public ?string $openedBulkDate = null;
    public ?string $openedTriggerType = null;
    public ?int $openedCursor = null;
    public array $closedCounts = [];
    private int $resumeCursor;

    public function __construct(int $resumeCursor = 0)
    {
        $this->resumeCursor = $resumeCursor;
    }

    public function getResumeCursor(int $company_id, string $trigger_type = 'cron'): int
    {
        $this->resumeCalls++;
        $this->resumeTriggerType = $trigger_type;

        return $this->resumeCursor;
    }

    public function open(int $company_id, string $trigger_type, int $cursor): int
    {
        $this->opened++;
        $this->openedTriggerType = $trigger_type;
        $this->openedCursor = $cursor;

        return 10;
    }

    public function openBulk(int $company_id, string $run_date): int
    {
        $this->opened++;
        $this->openedBulkDate = $run_date;

        return 10;
    }

    public function updateCursor(int $run_id, int $cursor): void
    {
    }

    public function close(int $run_id, string $status, array $counts, int $cursor, string $summary): void
    {
        $this->closedCursor = $status === 'completed' ? 0 : $cursor;
        $this->closedCounts = $counts;
    }
}

class KuickPayReconcileFakeItemRepository
{
    public array $items = [];

    public function record(array $vars): void
    {
        $this->items[] = $vars;
    }
}

class KuickPayReconcileFakeLockRepository
{
    private bool $available;
    public bool $released = false;
    public ?string $acquiredLockName = null;

    public function __construct(bool $available = true)
    {
        $this->available = $available;
    }

    public function acquire(int $company_id, string $lockName, int $ttlSeconds): ?string
    {
        $this->acquiredLockName = $lockName;

        return $this->available ? 'owner-token' : null;
    }

    public function release(int $company_id, string $lockName, string $ownerToken): void
    {
        $this->released = true;
    }
}

class KuickPayReconcileFakeAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = [$eventName, $context];
    }
}

class KuickPayReconcileFakeLogger
{
    public array $info = [];
    public array $errors = [];

    public function info(string $message, array $context = []): void
    {
        $this->info[] = compact('message', 'context');
    }

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = compact('message', 'context');
    }
}

class KuickPayReconcileFakeClient
{
    public array $requests = [];
    public array $bulkRequests = [];
    private array $outcomes;

    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function billPaymentInquiry(array $params): array
    {
        $this->requests[] = $params;

        return array_shift($this->outcomes);
    }

    public function billPaymentBulkInquiry(array $params): array
    {
        $this->bulkRequests[] = $params;

        return array_shift($this->outcomes);
    }
}
