<?php

use PHPUnit\Framework\TestCase;

class KuickPayVoucherReferenceServiceTest extends TestCase
{
    /**
     * @dataProvider normalizeAmountProvider
     */
    public function testNormalizeAmountRoundsHalfUpAndFailsClosed($input, $expected)
    {
        // AC3d (5.5): the service copy must half-up round to 2 dp using
        // decimal-string math (no PHP floats) and fail closed on invalid input,
        // staying byte-for-byte identical to the gateway copy so the cross-side
        // amount compare is self-consistent.
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceFakeRepository());
        $method = new ReflectionMethod(KuickPayVoucherReferenceService::class, 'normalizeAmount');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($service, $input));
    }

    public function normalizeAmountProvider()
    {
        return [
            ['1500', '1500.00'],
            ['1,000.50', '1000.50'],
            // Half-up rounding past 2 dp (the decimal(12,4) trap, Story 3-5).
            ['0.009', '0.01'],
            ['100.0050', '100.01'],
            ['999.9999', '1000.00'],
            ['1000.0000', '1000.00'],
            // Invalid / negative -> fail-closed empty sentinel.
            ['-5', ''],
            ['-10.00', ''],
            ['abc', ''],
            ['', ''],
        ];
    }

    public function testRetireVoucherSurfacesAffectedRowCount()
    {
        // AC3e (5.5): retireVoucher branches on the affected-row count surfaced
        // by edit(). A wrong-company or missing voucher is a 0-row no-op (false,
        // no audit); an in-scope match retires (true) and records the
        // voucher.replaced audit exactly once.
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $voucherId = $repository->seedActiveVoucher([
            'company_id' => 1,
            'status' => 'pending',
            'registration_number' => 'REG-1',
            'consumer_number' => 'CON-1',
            'context_key' => 'ctx-1',
            'invoices' => [['invoice_id' => 55, 'amount' => '1500.00']],
        ]);
        $audit = new KuickPayVoucherReferenceFakeAuditService();
        $service = new KuickPayVoucherReferenceService($repository, $audit);

        $this->assertFalse($service->retireVoucher($voucherId, 2, 'amount_changed'));
        $this->assertFalse($service->retireVoucher(99999, 1, 'amount_changed'));
        $this->assertSame([], $audit->events);

        $this->assertTrue($service->retireVoucher($voucherId, 1, 'amount_changed'));
        $this->assertCount(1, $audit->events);
        $this->assertSame('voucher.replaced', $audit->events[0][0]);
        $this->assertSame(1, $audit->events[0][1]['company_id']);
        $this->assertSame($voucherId, $audit->events[0][1]['voucher_id']);
    }

    public function testFakeModelsEmptyStringIdentityCollisionButNullDistinctness()
    {
        // Story 5.5 AC2 identity diversification: the company-scoped unique keys
        // treat a NULL consumer_number/registration_number as DISTINCT (many
        // NULLs allowed) but an empty string '' as a value (two '' in one
        // company collide), exactly as MySQL does. NULL is already covered
        // elsewhere; this pins the empty-string variant.
        $repo = new KuickPayVoucherReferenceFakeRepository();
        $base = [
            'company_id' => 1,
            'status' => 'pending',
            'gateway_id' => 2,
            'client_id' => 3,
            'currency' => 'PKR',
            'amount' => '10.00',
            'date_due' => '2026-06-13',
            'date_expires' => '2026-06-20',
        ];

        // Empty-string consumer is a value: a second '' in the same company
        // collides on uniq_kuickpay_vouchers_consumer (create returns null).
        $this->assertNotNull($repo->create(
            $base + ['consumer_number' => '', 'registration_number' => 'REG-1', 'context_key' => 'ctx-1'],
            []
        ));
        $this->assertNull($repo->create(
            $base + ['consumer_number' => '', 'registration_number' => 'REG-2', 'context_key' => 'ctx-2'],
            []
        ));

        // The same empty-string consumer in a DIFFERENT company is fine.
        $this->assertNotNull($repo->create(
            ['company_id' => 2] + $base + ['consumer_number' => '', 'registration_number' => 'REG-3', 'context_key' => 'ctx-3'],
            []
        ));

        // NULL consumer numbers are distinct: many are allowed in one company.
        $this->assertNotNull($repo->create(
            $base + ['consumer_number' => null, 'registration_number' => 'REG-4', 'context_key' => 'ctx-4'],
            []
        ));
        $this->assertNotNull($repo->create(
            $base + ['consumer_number' => null, 'registration_number' => 'REG-5', 'context_key' => 'ctx-5'],
            []
        ));
    }

    public function testDuplicateInvoiceIdRecordsGenerationFailedAudit()
    {
        $audit = new KuickPayVoucherReferenceFakeAuditService();
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceFakeRepository(), $audit);

        $result = $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => [
                ['id' => 55, 'amount' => '1000.00'],
                ['id' => 55, 'amount' => '999.00'],
            ],
        ]));

        $this->assertNull($result);
        $this->assertSame('duplicate_invoice_id', $service->getLastError());
        $this->assertSame('voucher.generation_failed', $audit->events[0][0]);
        $this->assertSame(1, $audit->events[0][1]['company_id']);
        $this->assertNull($audit->events[0][1]['voucher_id']);
        $this->assertSame([
            'reason' => 'duplicate_invoice_id',
            'invoice_id' => 55,
        ], $audit->events[0][1]['payload']);
    }

    public function testDuplicateInvoiceIdAuditNamesTheActuallyConflictingInvoice()
    {
        // AC2 (5.4): when the conflicting duplicate pair is NOT the first context row,
        // the voucher.generation_failed payload must name the invoice that actually
        // triggered the conflict (56), not firstContextInvoiceId() (55).
        $audit = new KuickPayVoucherReferenceFakeAuditService();
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceFakeRepository(), $audit);

        $result = $service->getOrCreateForInvoiceContext($this->context([
            'multi_invoice_policy' => 'allow',
            'invoice_amounts' => [
                ['id' => 55, 'amount' => '600.00'],
                ['id' => 56, 'amount' => '400.00'],
                ['id' => 56, 'amount' => '399.00'],
            ],
        ]));

        $this->assertNull($result);
        $this->assertSame('duplicate_invoice_id', $service->getLastError());
        $this->assertSame('voucher.generation_failed', $audit->events[0][0]);
        $this->assertSame([
            'reason' => 'duplicate_invoice_id',
            'invoice_id' => 56,
        ], $audit->events[0][1]['payload']);
    }

    public function testInvalidPatternRecordsGenerationFailedAudit()
    {
        $audit = new KuickPayVoucherReferenceFakeAuditService();
        $service = new KuickPayVoucherReferenceService(new KuickPayVoucherReferenceFakeRepository(), $audit);

        $result = $service->getOrCreateForInvoiceContext($this->context([
            'registration_number_pattern' => '{missing_token}',
        ]));

        $this->assertNull($result);
        $this->assertSame('invalid_registration_pattern', $service->getLastError());
        $this->assertSame('voucher.generation_failed', $audit->events[0][0]);
        $this->assertSame([
            'reason' => 'invalid_registration_pattern',
            'invoice_id' => 55,
        ], $audit->events[0][1]['payload']);
    }

    public function testContextKeyIsDeterministicDistinctSortedAndOrderIndependent()
    {
        // Single-invoice set → sha1 of the lone id (matches the SQL backfill).
        $single = $this->capturedContextKey([['id' => 55, 'amount' => '1000.00']]);
        $this->assertNotSame('', $single);
        $this->assertSame(sha1('55'), $single);

        // Same multi-invoice set in either order yields the SAME key.
        $ab = $this->capturedContextKey(
            [['id' => 55, 'amount' => '600.00'], ['id' => 56, 'amount' => '400.00']],
            'allow'
        );
        $ba = $this->capturedContextKey(
            [['id' => 56, 'amount' => '400.00'], ['id' => 55, 'amount' => '600.00']],
            'allow'
        );
        $this->assertSame($ab, $ba);
        $this->assertSame(sha1('55,56'), $ab);

        // Duplicate ids (same amount) collapse to the distinct set.
        $dup = $this->capturedContextKey(
            [
                ['id' => 55, 'amount' => '600.00'],
                ['id' => 56, 'amount' => '400.00'],
                ['id' => 55, 'amount' => '600.00'],
            ],
            'allow'
        );
        $this->assertSame($ab, $dup);

        // A different set yields a different key.
        $different = $this->capturedContextKey(
            [['id' => 55, 'amount' => '600.00'], ['id' => 57, 'amount' => '400.00']],
            'allow'
        );
        $this->assertNotSame($ab, $different);
    }

    public function testSecondSameSetCreateFailsOnUniqueKeyAndFallsThroughToWinner()
    {
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $winnerId = $repository->seedActiveVoucher([
            'company_id' => 1,
            'status' => 'pending',
            'context_key' => sha1('55'),
            'registration_number' => 'WIN55',
            'consumer_number' => 'KPWIN55',
            'invoices' => [['invoice_id' => 55, 'amount' => '1000.00']],
        ]);

        // Simulate the race: the application pre-lookup misses, so the service
        // attempts a second create; the unique (company_id, active_context_key)
        // constraint rejects it (create returns null), and the create-null
        // fall-through re-reads and returns the committed winner.
        $repository->hidePendingUntilCreateAttempted = true;

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => [['id' => 55, 'amount' => '1000.00']],
        ]));

        $this->assertNotNull($voucher);
        $this->assertSame($winnerId, $voucher['id']);
        $this->assertSame(1, $repository->createCalls);
        $this->assertSame(sha1('55'), $repository->createdVoucherData['context_key']);
        $this->assertSame([$winnerId], $repository->activeVoucherIds());
    }

    public function testCreateFallThroughSetsCreateFailedDiagnosticAndDurableAudit()
    {
        // AC2 (5.4): the genuine create fall-through — create() returns falsy AND the
        // race-recovery re-lookup finds no winner — must set a 'create_failed'
        // diagnostic (so the gateway emits a non-null reason) and leave a durable
        // audit breadcrumb. A posted voucher holds the active-context slot forever, so
        // the create collides (returns null) and no PENDING winner exists on re-lookup.
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $winnerId = $repository->seedActiveVoucher([
            'company_id' => 1,
            'status' => 'posted',
            'context_key' => sha1('55'),
            'registration_number' => 'PAID55',
            'consumer_number' => 'KPPAID55',
            'invoices' => [['invoice_id' => 55, 'amount' => '1000.00']],
        ]);
        $repository->hidePendingUntilCreateAttempted = true;
        $audit = new KuickPayVoucherReferenceFakeAuditService();

        $service = new KuickPayVoucherReferenceService($repository, $audit);
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => [['id' => 55, 'amount' => '1000.00']],
        ]));

        $this->assertNull($voucher);
        $this->assertSame(1, $repository->createCalls);
        $this->assertSame([$winnerId], $repository->activeVoucherIds());
        $this->assertSame('create_failed', $service->getLastError());
        $this->assertSame('voucher.generation_failed', $audit->events[0][0]);
        $this->assertNull($audit->events[0][1]['voucher_id']);
        $this->assertSame([
            'reason' => 'create_failed',
            'invoice_id' => 55,
        ], $audit->events[0][1]['payload']);
    }

    public function testReleasedContextSlotAllowsAFreshActiveVoucher()
    {
        // A cancelled winner releases the slot (active_context_key → NULL), so a
        // new pending voucher for the same invoice set can be created.
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $repository->seedActiveVoucher([
            'company_id' => 1,
            'status' => 'cancelled',
            'context_key' => sha1('55'),
            'registration_number' => 'OLD55',
            'consumer_number' => 'KPOLD55',
            'invoices' => [['invoice_id' => 55, 'amount' => '1000.00']],
        ]);

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => [['id' => 55, 'amount' => '1000.00']],
        ]));

        $this->assertNotNull($voucher);
        $this->assertSame('pending', $voucher['status']);
        $this->assertSame(sha1('55'), $repository->createdVoucherData['context_key']);
        $this->assertSame(1, $repository->createCalls);
    }

    public function testPostedContextSlotBlocksAFreshActiveVoucher()
    {
        // A posted voucher holds the slot forever, so a new pending voucher for
        // the same invoice set is rejected by the fake unique active-context key.
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $winnerId = $repository->seedActiveVoucher([
            'company_id' => 1,
            'status' => 'posted',
            'context_key' => sha1('55'),
            'registration_number' => 'PAID55',
            'consumer_number' => 'KPPAID55',
            'invoices' => [['invoice_id' => 55, 'amount' => '1000.00']],
        ]);
        $repository->hidePendingUntilCreateAttempted = true;

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => [['id' => 55, 'amount' => '1000.00']],
        ]));

        $this->assertNull($voucher);
        $this->assertSame(1, $repository->createCalls);
        $this->assertSame([$winnerId], $repository->activeVoucherIds());
    }

    public function testEmptyContextKeyIsRejectedLikeTheNotNullModelRule()
    {
        // Fake fidelity to the NOT-NULL model rule: a missing context_key makes
        // create() fail (the real model validates it as required).
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $this->assertNull($repository->create([
            'company_id' => 1,
            'status' => 'pending',
            'registration_number' => 'X',
            'consumer_number' => 'KPX',
        ], [['invoice_id' => 55, 'amount' => '1000.00']]));
        $this->assertSame([], $repository->activeVoucherIds());
    }

    private function capturedContextKey(array $invoiceAmounts, string $policy = 'block'): string
    {
        $repository = new KuickPayVoucherReferenceFakeRepository();
        $service = new KuickPayVoucherReferenceService($repository);
        $service->getOrCreateForInvoiceContext($this->context([
            'invoice_amounts' => $invoiceAmounts,
            'multi_invoice_policy' => $policy,
        ]));

        return (string) ($repository->createdVoucherData['context_key'] ?? '');
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'company_id' => 1,
            'gateway_id' => 2,
            'client_id' => 3,
            'currency' => 'PKR',
            'amount' => '1000.00',
            'institution_id' => 'KP01',
            'invoice_amounts' => [
                ['id' => 55, 'amount' => '1000.00'],
            ],
        ], $overrides);
    }
}

class KuickPayVoucherReferenceFakeAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = [$eventName, $context];
    }
}

/**
 * Stateful voucher-repository fake held to real NOT-NULL/UNIQUE fidelity.
 *
 * It enforces the two database guarantees a fake could otherwise mask
 * (Story 5.2; Epic 3 retro AI-2): create() rejects an empty context_key (the
 * model's NOT-NULL-equivalent required rule) and rejects a duplicate active
 * (company_id, active_context_key) (the uniq_kuickpay_vouchers_active_context
 * unique key). active_context_key is derived from status exactly as the STORED
 * generated column is: NULL for the terminal expired/cancelled states, else
 * context_key. The authoritative proof remains the real-DB harness (Task 6);
 * this is a regression net only.
 */
class KuickPayVoucherReferenceFakeRepository
{
    use KuickPayFakeVoucherConstraints;

    /** @var array<int, array> id => ['voucher' => stdClass, 'invoices' => stdClass[]] */
    public array $rows = [];

    /** @var int Count of create() attempts (seed excluded) */
    public int $createCalls = 0;

    /** @var array|null The most recent voucher data passed to create() */
    public $createdVoucherData = null;

    /** @var array|null The most recent invoice links passed to create() */
    public $createdInvoiceLinks = null;

    /** @var bool When true, pending lookups miss until a create is attempted */
    public bool $hidePendingUntilCreateAttempted = false;

    /** @var int Next auto-increment id */
    private int $nextId = 1;

    public function getPendingByInvoiceSet(array $invoiceIds, int $companyId)
    {
        if ($this->hidePendingUntilCreateAttempted && $this->createCalls === 0) {
            return null;
        }

        $want = $this->normalizeIds($invoiceIds);

        return $this->findPending($companyId, function (array $invoices) use ($want): bool {
            $have = $this->normalizeIds(array_map(static function ($i) {
                return (int) $i->invoice_id;
            }, $invoices));

            return $have === $want;
        });
    }

    public function getPendingByInvoiceId(int $invoiceId, int $companyId)
    {
        if ($this->hidePendingUntilCreateAttempted && $this->createCalls === 0) {
            return null;
        }

        return $this->findPending($companyId, function (array $invoices) use ($invoiceId): bool {
            foreach ($invoices as $invoice) {
                if ((int) $invoice->invoice_id === $invoiceId) {
                    return true;
                }
            }

            return false;
        });
    }

    public function getByRegistrationNumber(string $registrationNumber, int $companyId)
    {
        return null;
    }

    public function getByConsumerNumber(string $consumerNumber, int $companyId)
    {
        return null;
    }

    public function create(array $voucherData, array $invoiceLinks)
    {
        $this->createCalls++;
        $this->createdVoucherData = $voucherData;
        $this->createdInvoiceLinks = $invoiceLinks;

        // Model the real repository create(): the DB enforces the NOT-NULL +
        // company-scoped UNIQUE keys (shared constraint trait throws like MySQL),
        // and the repository swallows that violation and returns null.
        $id = $this->nextId;
        try {
            $this->enforceVoucherInsert($id, $voucherData);
        } catch (RuntimeException $e) {
            return null;
        }
        $this->nextId++;

        $voucher = (object) array_merge($voucherData, ['id' => $id]);
        $invoices = array_map(static function (array $link) {
            return (object) $link;
        }, $invoiceLinks);
        $this->rows[$id] = ['voucher' => $voucher, 'invoices' => $invoices];

        return $id;
    }

    public function getWithInvoices(int $voucherId, int $companyId = 0)
    {
        $row = $this->rows[$voucherId] ?? null;
        if ($row === null) {
            return null;
        }

        // Company-scoped read fidelity: a voucher id outside the company resolves
        // to null, never another tenant's row.
        if ($companyId !== 0 && (int) $row['voucher']->company_id !== $companyId) {
            return null;
        }

        return $row;
    }

    /**
     * Models the scoped, count-returning UPDATE (Story 5.5 AC1/AC3e): returns the
     * affected-row count — 1 for an in-scope match, 0 for a no-op (wrong/zero
     * company_id, or no such row). A terminal status (cancelled/expired) releases
     * the active-context slot, exactly as the STORED active_context_key goes NULL.
     */
    public function edit(int $voucherId, int $companyId, array $vars): int
    {
        if (!isset($this->rows[$voucherId])) {
            return 0;
        }

        $voucher = $this->rows[$voucherId]['voucher'];
        if ((int) $voucher->company_id !== $companyId) {
            return 0;
        }

        $oldStatus = (string) $voucher->status;
        foreach ($vars as $key => $value) {
            $voucher->{$key} = $value;
        }
        $this->releaseActiveContextOnTerminal(
            $companyId,
            (string) ($voucher->context_key ?? ''),
            $oldStatus,
            (string) $voucher->status
        );

        return 1;
    }

    /**
     * Seeds a committed voucher through the same constraint-enforcing create()
     * path, then resets the create counter so the seed is not attributed to the
     * service under test.
     *
     * @param array $row Voucher fields plus an 'invoices' link list
     * @return int The seeded voucher id
     */
    public function seedActiveVoucher(array $row): int
    {
        $invoices = $row['invoices'] ?? [];
        unset($row['invoices']);
        $row += [
            'gateway_id' => 2,
            'client_id' => 3,
            'currency' => 'PKR',
            'amount' => '1000.00',
            'date_due' => '2026-06-13',
            'date_expires' => '2026-06-20',
        ];

        $id = (int) $this->create($row, $invoices);
        $this->createCalls = 0;

        return $id;
    }

    /**
     * @return int[] Ids of rows still holding an active context slot
     */
    public function activeVoucherIds(): array
    {
        $ids = [];
        foreach ($this->rows as $id => $row) {
            if ($this->fakeActiveContextKey((string) $row['voucher']->status, 'x') !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function findPending(int $companyId, callable $invoiceMatch)
    {
        foreach ($this->rows as $row) {
            if ((int) $row['voucher']->company_id === $companyId
                && (string) $row['voucher']->status === 'pending'
                && $invoiceMatch($row['invoices'])
            ) {
                return $row['voucher'];
            }
        }

        return null;
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
