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
        $this->assertSame(['RegistrationNumber' => 'REG-0000001'], $client->requests[0]);
        $this->assertArrayNotHasKey('expected_consumer_number', $service->buildParserContext($voucher));
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
            'consumer_number' => 'INSTITUTION_IDREG-0000001',
            'retry_count' => 0,
        ], $overrides);
    }

    private function outcome(string $rawResult): array
    {
        return [
            'ok' => true,
            'operation' => 'BillPaymentInquiry',
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
    private array $vouchers;
    private array $invoiceLinks;

    public function __construct(array $vouchers, array $invoiceLinks = [])
    {
        $this->vouchers = $vouchers;
        $this->invoiceLinks = $invoiceLinks;
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

    public function getWithInvoices(int $voucher_id): ?array
    {
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

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->duplicateReference;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->activeSibling;
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
    private int $resumeCursor;

    public function __construct(int $resumeCursor = 0)
    {
        $this->resumeCursor = $resumeCursor;
    }

    public function getResumeCursor(int $company_id): int
    {
        return $this->resumeCursor;
    }

    public function open(int $company_id, string $trigger_type, int $cursor): int
    {
        $this->opened++;

        return 10;
    }

    public function updateCursor(int $run_id, int $cursor): void
    {
    }

    public function close(int $run_id, string $status, array $counts, int $cursor, string $summary): void
    {
        $this->closedCursor = $status === 'completed' ? 0 : $cursor;
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

class KuickPayReconcileFakeClient
{
    public array $requests = [];
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
}
