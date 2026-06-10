<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php';

class KuickPayVoucherReferenceServiceFakeRepository
{
    public $pendingVoucher;
    public $createdVoucherId = 101;
    public $createCalls = 0;
    public $createdVoucherData;
    public $createdInvoiceLinks;
    public $records = [];
    public $createReturnsNull = false;
    public $pendingAfterCreateFailure;
    public $registrationLookups = [];
    public $consumerLookups = [];
    public $registrationLookupReturns = [];
    public $consumerLookupReturns = [];
    public array $edits = [];

    public function getPendingByInvoiceId(int $invoice_id, int $company_id)
    {
        if ($this->createCalls > 0 && $this->pendingAfterCreateFailure) {
            return $this->pendingAfterCreateFailure;
        }

        return $this->pendingVoucher;
    }

    public function create(array $voucherData, array $invoiceLinks)
    {
        $this->createCalls++;
        $this->createdVoucherData = $voucherData;
        $this->createdInvoiceLinks = $invoiceLinks;

        return $this->createReturnsNull ? null : $this->createdVoucherId;
    }

    public function getWithInvoices(int $voucher_id)
    {
        return $this->records[$voucher_id] ?? null;
    }

    public function getByRegistrationNumber(string $registration_number, int $company_id)
    {
        $this->registrationLookups[] = [$registration_number, $company_id];

        return $this->nextLookupReturn($this->registrationLookupReturns, $registration_number, $company_id);
    }

    public function getByConsumerNumber(string $consumer_number, int $company_id)
    {
        $this->consumerLookups[] = [$consumer_number, $company_id];

        return $this->nextLookupReturn($this->consumerLookupReturns, $consumer_number, $company_id);
    }

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $this->edits[] = compact('voucher_id', 'company_id', 'vars');
    }

    private function nextLookupReturn(array &$returns, string $reference, int $company_id)
    {
        if (empty($returns)) {
            return null;
        }

        $return = array_shift($returns);
        if ($return instanceof Closure) {
            return $return($reference, $company_id);
        }

        return $return;
    }
}

class KuickPayVoucherReferenceServiceFakeAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = ['event' => $eventName, 'context' => $context];
    }
}

class TestableKuickPayVoucherReferenceService extends KuickPayVoucherReferenceService
{
    public $randomQueue = [1234];

    protected function randomInt(): int
    {
        return (int) (array_shift($this->randomQueue) ?? 9999);
    }

    public function callGenerateRandomPrefix(): string
    {
        return $this->generateRandomPrefix();
    }

    public function callExpandPattern(string $pattern, array $values): ?string
    {
        return $this->expandPattern($pattern, $values);
    }
}

class KuickPayVoucherReferenceServiceTest extends TestCase
{
    public function testExpandPatternSubstitutesRecognizedTokensAndLiterals()
    {
        $service = new TestableKuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertSame(
            '1111-55_KP',
            $service->callExpandPattern(
                '{random_prefix}-{invoice_id}_{institution_id}',
                ['random_prefix' => '1111', 'invoice_id' => '55', 'institution_id' => 'KP']
            )
        );
    }

    public function testExpandPatternRejectsUnknownTokensAndResidualBraces()
    {
        $service = new TestableKuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertNull($service->callExpandPattern('{client_id}', ['client_id' => '3']));
        $this->assertNull($service->callExpandPattern('KP{invoice_id', ['invoice_id' => '55']));
        $this->assertNull($service->callExpandPattern('{registration_number}', ['invoice_id' => '55']));
    }

    public function testExpandPatternRejectsEmptyAndOverlongResults()
    {
        $service = new TestableKuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertNull($service->callExpandPattern('{institution_id}', ['institution_id' => '']));
        $this->assertNull($service->callExpandPattern(str_repeat('A', 65), []));
        $this->assertSame(str_repeat('A', 64), $service->callExpandPattern(str_repeat('A', 64), []));
    }

    public function testReuseReturnsExistingPendingVoucherWithoutCreating()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->pendingVoucher = $this->voucherRow(25);
        $repository->records[25] = [
            'voucher' => $repository->pendingVoucher,
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(0, $repository->createCalls);
        $this->assertSame(25, $voucher['id']);
        $this->assertSame('KP000055', $voucher['consumer_number']);
        $this->assertSame([['invoice_id' => 55, 'amount' => '1500.00']], $voucher['invoices']);
    }

    public function testRequestMatchesVoucherComparesAmountsAsCanonicalStrings()
    {
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertTrue($service->requestMatchesVoucher(
            ['amount' => '1,500', 'invoices' => []],
            '1500.00',
            [['id' => 55, 'amount' => '1500.00']]
        ));
        $this->assertFalse($service->requestMatchesVoucher(
            ['amount' => '1500.01', 'invoices' => []],
            '1500.00',
            [['id' => 55, 'amount' => '1500.00']]
        ));
    }

    public function testRequestMatchesVoucherTreatsUnloadedLinksAsAmountOnly()
    {
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertTrue($service->requestMatchesVoucher(
            ['amount' => '1500.00', 'invoices' => []],
            '1500.00',
            [
                ['id' => 55, 'amount' => '1000.00'],
                ['id' => 56, 'amount' => '500.00'],
            ]
        ));
    }

    public function testRequestMatchesVoucherComparesLoadedLinksOrderIndependently()
    {
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertTrue($service->requestMatchesVoucher(
            [
                'amount' => '1500.00',
                'invoices' => [
                    ['invoice_id' => 56, 'amount' => '500'],
                    ['invoice_id' => 55, 'amount' => '1,000.00'],
                ],
            ],
            '1500.00',
            [
                ['id' => 55, 'amount' => '1000.00'],
                ['id' => 56, 'amount' => '500.00'],
            ]
        ));
    }

    public function testRequestMatchesVoucherAcceptsStdClassLinkRows()
    {
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertTrue($service->requestMatchesVoucher(
            [
                'amount' => '1500.00',
                'invoices' => [
                    (object) ['invoice_id' => 55, 'amount' => '1000.00'],
                    (object) ['invoice_id' => 56, 'amount' => '500.00'],
                ],
            ],
            '1500.00',
            [
                ['id' => 56, 'amount' => '500.00'],
                ['id' => 55, 'amount' => '1000.00'],
            ]
        ));
    }

    public function testRequestMatchesVoucherRejectsLoadedMappingChanges()
    {
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());

        $this->assertFalse($service->requestMatchesVoucher(
            [
                'amount' => '1500.00',
                'invoices' => [
                    ['invoice_id' => 55, 'amount' => '1500.00'],
                ],
            ],
            '1500.00',
            [
                ['id' => 56, 'amount' => '1500.00'],
            ]
        ));
        $this->assertFalse($service->requestMatchesVoucher(
            [
                'amount' => '1500.00',
                'invoices' => [
                    ['invoice_id' => 55, 'amount' => '1200.00'],
                    ['invoice_id' => 56, 'amount' => '300.00'],
                ],
            ],
            '1500.00',
            [
                ['id' => 55, 'amount' => '1000.00'],
                ['id' => 56, 'amount' => '500.00'],
            ]
        ));
    }

    public function testRetireVoucherCancelsVoucherAndRecordsReplacementAudit()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $audit = new KuickPayVoucherReferenceServiceFakeAuditService();
        $service = new KuickPayVoucherReferenceService($repository, $audit);

        $this->assertTrue($service->retireVoucher(25, 1, 'amount_changed', [
            'old_amount' => '1500.00',
            'new_amount' => '1200.00',
            'redacted_trace_id' => 'trace-retire',
        ]));

        $this->assertSame([
            [
                'voucher_id' => 25,
                'company_id' => 1,
                'vars' => ['status' => 'cancelled'],
            ],
        ], $repository->edits);
        $this->assertSame('voucher.replaced', $audit->events[0]['event']);
        $this->assertSame(1, $audit->events[0]['context']['company_id']);
        $this->assertSame(25, $audit->events[0]['context']['voucher_id']);
        $this->assertSame('trace-retire', $audit->events[0]['context']['redacted_trace_id']);
        $this->assertSame([
            'reason' => 'amount_changed',
            'old_amount' => '1500.00',
            'new_amount' => '1200.00',
        ], $audit->events[0]['context']['payload']);
        $this->assertArrayNotHasKey('run_id', $audit->events[0]['context']);
    }

    public function testFlatVoucherExposesIssuanceStateForIdempotency()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->pendingVoucher = $this->voucherRow(25);
        $repository->pendingVoucher->kuickpay_reference = 'KP-ISSUED-123';
        $repository->pendingVoucher->raw_status = '00';
        $repository->records[25] = [
            'voucher' => $repository->pendingVoucher,
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame('KP-ISSUED-123', $voucher['kuickpay_reference']);
        $this->assertSame('00', $voucher['raw_status']);
    }

    public function testCreateUsesDefaultRandomPatternReferencesForInvoiceContext()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, '123455', 'KP123455'),
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1234];
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(1, $repository->createCalls);
        $this->assertSame('123455', $repository->createdVoucherData['registration_number']);
        $this->assertSame('KP123455', $repository->createdVoucherData['consumer_number']);
        $this->assertSame('pending', $repository->createdVoucherData['status']);
        $this->assertSame('123455', $voucher['registration_number']);
        $this->assertSame('KP123455', $voucher['consumer_number']);
        $this->assertNull($service->getLastError());
    }

    public function testGenerateRandomPrefixZeroPadsRawRandomInteger()
    {
        $service = new TestableKuickPayVoucherReferenceService(new KuickPayVoucherReferenceServiceFakeRepository());
        $service->randomQueue = [42];

        $this->assertSame('0042', $service->callGenerateRandomPrefix());
    }

    public function testDefaultPatternsPreserveLeadingZeroInstitutionId()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $instId = '01999';
        $reg = '1111666666';
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, $reg, $instId . $reg),
            'invoices' => [$this->invoiceRow(666666, '1500.00')],
        ];

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1111];
        $context = $this->context([
            'institution_id' => $instId,
            'invoice_amounts' => [
                ['id' => 666666, 'amount' => '1500.00'],
            ],
        ]);

        $voucher = $service->getOrCreateForInvoiceContext($context);

        $this->assertSame($reg, $voucher['registration_number']);
        $this->assertSame($instId . $reg, $voucher['consumer_number']);
    }

    public function testCustomPatternsCanUseLiterals()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, 'R-1234-55', 'C-KP-R-1234-55'),
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1234];
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'registration_number_pattern' => 'R-{random_prefix}-{invoice_id}',
            'consumer_number_pattern' => 'C-{institution_id}-{registration_number}',
        ]));

        $this->assertSame('R-1234-55', $voucher['registration_number']);
        $this->assertSame('C-KP-R-1234-55', $voucher['consumer_number']);
    }

    public function testInvalidRegistrationPatternFailsClosedWithReason()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $service = new TestableKuickPayVoucherReferenceService($repository);

        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'registration_number_pattern' => '{client_id}',
        ]));

        $this->assertNull($voucher);
        $this->assertSame('invalid_registration_pattern', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
    }

    public function testInvalidConsumerPatternFailsClosedWithReason()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $service = new TestableKuickPayVoucherReferenceService($repository);

        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'consumer_number_pattern' => '{client_id}',
        ]));

        $this->assertNull($voucher);
        $this->assertSame('invalid_consumer_pattern', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
    }

    public function testEmptyInstitutionIdInConsumerPatternFailsClosedWithReason()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $service = new TestableKuickPayVoucherReferenceService($repository);

        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'institution_id' => '',
            'consumer_number_pattern' => '{institution_id}{registration_number}',
        ]));

        $this->assertNull($voucher);
        $this->assertSame('invalid_consumer_pattern', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
    }

    public function testOverlongGeneratedReferenceFailsClosed()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $service = new TestableKuickPayVoucherReferenceService($repository);

        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'registration_number_pattern' => str_repeat('A', 65),
        ]));

        $this->assertNull($voucher);
        $this->assertSame('invalid_registration_pattern', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
    }

    public function testCollisionRegeneratesAndCreatesWithNextPrefix()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->registrationLookupReturns = [(object) ['id' => 1], null];
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, '222255', 'KP222255'),
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1111, 2222];
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(1, $repository->createCalls);
        $this->assertSame('222255', $repository->createdVoucherData['registration_number']);
        $this->assertSame('KP222255', $repository->createdVoucherData['consumer_number']);
        $this->assertSame('222255', $voucher['registration_number']);
        $this->assertSame([
            ['111155', 1],
            ['222255', 1],
        ], $repository->registrationLookups);
    }

    public function testConsumerNumberCollisionRegeneratesAndCreatesWithNextPrefix()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->consumerLookupReturns = [(object) ['id' => 1], null];
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, '222255', 'KP222255'),
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1111, 2222];
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(1, $repository->createCalls);
        $this->assertSame('222255', $repository->createdVoucherData['registration_number']);
        $this->assertSame('KP222255', $repository->createdVoucherData['consumer_number']);
        $this->assertSame('222255', $voucher['registration_number']);
        $this->assertSame([
            ['KP111155', 1],
            ['KP222255', 1],
        ], $repository->consumerLookups);
    }

    public function testReferenceCollisionExhaustionFailsClosed()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->registrationLookupReturns = array_fill(0, 5, (object) ['id' => 1]);

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1111, 2222, 3333, 4444, 5555];
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertNull($voucher);
        $this->assertSame('uniqueness_exhausted', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
        $this->assertCount(5, $repository->registrationLookups);
    }

    public function testDuplicateForcingPatternExhaustionFailsClosed()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->registrationLookupReturns = array_fill(0, 5, (object) ['id' => 1]);

        $service = new TestableKuickPayVoucherReferenceService($repository);
        $service->randomQueue = [1111, 2222, 3333, 4444, 5555];
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'registration_number_pattern' => '{institution_id}',
            'consumer_number_pattern' => '{institution_id}',
        ]));

        $this->assertNull($voucher);
        $this->assertSame('uniqueness_exhausted', $service->getLastError());
        $this->assertSame(0, $repository->createCalls);
        $this->assertSame([
            ['KP', 1],
            ['KP', 1],
            ['KP', 1],
            ['KP', 1],
            ['KP', 1],
        ], $repository->registrationLookups);
    }

    public function testCreateFailureRerunsReuseLookupOnceForRaceRecovery()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->createReturnsNull = true;
        $repository->pendingAfterCreateFailure = $this->voucherRow(77);
        $repository->records[77] = [
            'voucher' => $repository->pendingAfterCreateFailure,
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(1, $repository->createCalls);
        $this->assertSame(77, $voucher['id']);
        $this->assertSame('KP000055', $voucher['consumer_number']);
    }

    private function context(array $overrides = [])
    {
        return array_merge([
            'company_id' => 1,
            'gateway_id' => 2,
            'client_id' => 3,
            'currency' => 'PKR',
            'amount' => '1500.00',
            'invoice_amounts' => [
                ['id' => 55, 'amount' => '1500.00'],
            ],
            'institution_id' => 'KP',
            'due_date_offset_days' => 3,
            'expiry_date_offset_days' => 7,
        ], $overrides);
    }

    private function voucherRow($id, $registration_number = '000055', $consumer_number = 'KP000055')
    {
        return (object) [
            'id' => $id,
            'company_id' => 1,
            'client_id' => 3,
            'gateway_id' => 2,
            'currency' => 'PKR',
            'amount' => '1500.00',
            'status' => 'pending',
            'registration_number' => $registration_number,
            'consumer_number' => $consumer_number,
            'date_due' => '2026-06-13',
            'date_expires' => '2026-06-17',
        ];
    }

    private function invoiceRow($invoice_id, $amount)
    {
        return (object) [
            'invoice_id' => $invoice_id,
            'amount' => $amount,
        ];
    }
}
