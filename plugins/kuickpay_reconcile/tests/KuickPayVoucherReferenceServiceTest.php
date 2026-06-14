<?php

use PHPUnit\Framework\TestCase;

class KuickPayVoucherReferenceServiceTest extends TestCase
{
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

    /** @var array<string, int> "company|active_context_key" => id */
    private array $activeIndex = [];

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

        // NOT-NULL context_key fidelity: the model rejects an empty value.
        if (empty($voucherData['context_key'])) {
            return null;
        }

        // UNIQUE (company_id, active_context_key) fidelity.
        $activeContextKey = $this->activeContextKey(
            (string) ($voucherData['status'] ?? ''),
            (string) $voucherData['context_key']
        );
        $indexKey = $this->indexKey((int) $voucherData['company_id'], $activeContextKey);
        if ($activeContextKey !== null && isset($this->activeIndex[$indexKey])) {
            return null;
        }

        $id = $this->nextId++;
        $voucher = (object) array_merge($voucherData, ['id' => $id]);
        $invoices = array_map(static function (array $link) {
            return (object) $link;
        }, $invoiceLinks);
        $this->rows[$id] = ['voucher' => $voucher, 'invoices' => $invoices];
        if ($activeContextKey !== null) {
            $this->activeIndex[$indexKey] = $id;
        }

        return $id;
    }

    public function getWithInvoices(int $voucherId)
    {
        return $this->rows[$voucherId] ?? null;
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
            if ($this->activeContextKey((string) $row['voucher']->status, 'x') !== null) {
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

    private function activeContextKey(string $status, string $contextKey): ?string
    {
        return in_array($status, ['expired', 'cancelled'], true) ? null : $contextKey;
    }

    private function indexKey(int $companyId, ?string $activeContextKey): string
    {
        return $companyId . '|' . ($activeContextKey ?? "\0null");
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
