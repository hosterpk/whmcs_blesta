<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/KuickPayIssuanceService.php';

class KuickPaySecretLeakageTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures/kuickpay';

    public function testFixtureFilesContainNoForbiddenSecretOrPiiValues()
    {
        $files = $this->fixtureFiles();

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertNoForbiddenFixtureLeak(
                (string) file_get_contents($file),
                str_replace(self::FIXTURE_DIR . '/', '', $file)
            );
        }
    }

    public function testPersistedEvidenceAndAuditPayloadsContainNoSecretsOrRawEnvelopes()
    {
        $captured = [];

        $captured = array_merge($captured, $this->captureSingleReconcilePersistence());
        $captured = array_merge($captured, $this->captureConfirmedReconcilePersistence());
        $captured = array_merge($captured, $this->captureBulkReconcilePersistence());
        $captured = array_merge($captured, $this->capturePostingPersistence());
        $captured = array_merge($captured, $this->captureIssuancePersistence());

        $this->assertNotEmpty($captured);

        foreach ($captured as $label => $value) {
            $this->assertNoForbiddenPersistedLeak($this->stringify($value), $label);
        }
    }

    private function captureSingleReconcilePersistence(): array
    {
        $voucher = $this->voucher();
        $repo = new KuickPaySecretLeakageVoucherRepository([$voucher]);
        $run = new KuickPaySecretLeakageRunRepository();
        $items = new KuickPaySecretLeakageItemRepository();
        $audit = new KuickPaySecretLeakageAuditService();
        $client = new KuickPaySecretLeakageClient([
            $this->outcome($this->inquiryResult('malformed/bill-payment-inquiry-short.xml')),
        ]);
        $service = $this->reconcileService($repo, $run, $items, $audit, $client);

        $service->runCron(1);

        // Guard against a vacuous scan: a path that persisted nothing would
        // stringify to "[]" and pass every forbidden-pattern check trivially.
        $this->assertNotEmpty($repo->edits);

        return [
            'single voucher edit' => $repo->edits,
            'single item rows' => $items->items,
            'single run summary' => $run->summaries,
            'single audit events' => $audit->events,
        ];
    }

    private function captureConfirmedReconcilePersistence(): array
    {
        $voucher = $this->voucher();
        $repo = new KuickPaySecretLeakageVoucherRepository([$voucher], [$this->invoiceLink()]);
        $run = new KuickPaySecretLeakageRunRepository();
        $items = new KuickPaySecretLeakageItemRepository();
        $audit = new KuickPaySecretLeakageAuditService();
        $client = new KuickPaySecretLeakageClient([
            $this->outcome($this->inquiryResult('valid/bill-payment-inquiry-paid-exact.xml')),
        ]);
        $service = $this->reconcileService($repo, $run, $items, $audit, $client);

        $service->runCron(1);

        // Prove the confirmed branch actually ran so this capture is not vacuous: the
        // provider-echoed reference and raw status must reach the persisted columns
        // (KuickPayReconcileService.php:378,410) — the exact sinks where a smuggled
        // secret would land but a diagnostic_summary-only scan would miss it.
        $this->assertNotEmpty($repo->edits);
        $this->assertSame('confirmed_unposted', $repo->edits[0]['status']);
        $this->assertSame('KP-REF-PAID', $repo->edits[0]['kuickpay_reference']);
        $this->assertNotEmpty($repo->edits[0]['raw_status']);

        return [
            'confirmed voucher edit' => $repo->edits,
            'confirmed item rows' => $items->items,
            'confirmed run summary' => $run->summaries,
            'confirmed audit events' => $audit->events,
        ];
    }

    private function captureBulkReconcilePersistence(): array
    {
        $repo = new KuickPaySecretLeakageVoucherRepository([$this->voucher([
            'registration_number' => '1234INVOICE_ID',
            'consumer_number' => 'INSTITUTION_ID1234INVOICE_ID',
        ])]);
        $run = new KuickPaySecretLeakageRunRepository();
        $items = new KuickPaySecretLeakageItemRepository();
        $audit = new KuickPaySecretLeakageAuditService();
        $client = new KuickPaySecretLeakageClient([
            $this->outcome(
                $this->bulkResult('ambiguous/bill-payment-bulk-overpayment.xml'),
                'BillPaymentBulkInquiry'
            ),
        ]);
        $service = $this->reconcileService($repo, $run, $items, $audit, $client);

        $service->runBulk(1, '2026-06-09');

        $this->assertNotEmpty($repo->edits);

        return [
            'bulk voucher edits' => $repo->edits,
            'bulk item rows' => $items->items,
            'bulk run summary' => $run->summaries,
            'bulk audit events' => $audit->events,
        ];
    }

    private function capturePostingPersistence(): array
    {
        $voucher = $this->postingVoucher([
            'date_paid' => null,
            'diagnostic_summary' => json_encode([
                'status' => 'confirmed_unposted',
                'evidence_hash' => 'hash-safe',
                'redacted_trace_id' => 'kp_trace_safe',
                'validation_errors' => [],
            ]),
        ]);
        $repo = new KuickPaySecretLeakagePostingRepository([$voucher], [$this->invoiceLink()]);
        $audit = new KuickPaySecretLeakageAuditService();
        $service = new KuickPayPostingService([
            'voucher_repository' => $repo,
            'evidence_validator' => new KuickPaySecretLeakageEvidenceValidator(true),
            'audit_service' => $audit,
            'lock_repository' => new KuickPaySecretLeakageLockRepository(),
            'transactions' => new KuickPaySecretLeakageTransactions(),
        ]);

        $service->postVoucher(1, $voucher);

        $this->assertNotEmpty($repo->edits);

        return [
            'posting voucher edits' => $repo->edits,
            'posting audit events' => $audit->events,
        ];
    }

    private function captureIssuancePersistence(): array
    {
        $repo = new KuickPaySecretLeakageIssuanceRepository();
        $audit = new KuickPaySecretLeakageAuditService();
        $service = new KuickPayIssuanceService($repo, $audit);
        $evidence = new KuickPayEvidence(
            'pending',
            null,
            'KP-ISSUED-SAFE-123',
            null,
            'REG-0000001',
            null,
            null,
            null,
            '00',
            'kp_trace_safe',
            'hash-safe',
            []
        );

        $service->recordIssueOutcome(25, 1, $evidence);

        $this->assertNotEmpty($repo->edits);

        return [
            'issuance voucher edits' => $repo->edits,
            'issuance audit events' => $audit->events,
        ];
    }

    private function reconcileService(
        KuickPaySecretLeakageVoucherRepository $repo,
        KuickPaySecretLeakageRunRepository $run,
        KuickPaySecretLeakageItemRepository $items,
        KuickPaySecretLeakageAuditService $audit,
        KuickPaySecretLeakageClient $client
    ): KuickPayReconcileService {
        return new KuickPayReconcileService([
            'voucher_repository' => $repo,
            'run_repository' => $run,
            'item_repository' => $items,
            'lock_repository' => new KuickPaySecretLeakageLockRepository(),
            'audit_service' => $audit,
            'parser' => new KuickPayResponseParser(),
            'evidence_validator' => new KuickPaySecretLeakageEvidenceValidator(true),
            'gateway_config' => ['reconciliation_enabled' => 'true'],
            'client_factory' => function () use ($client) {
                return $client;
            },
        ]);
    }

    private function assertNoForbiddenFixtureLeak(string $content, string $label): void
    {
        foreach ($this->fixtureForbiddenPatterns() as $name => $pattern) {
            $this->assertSame(0, preg_match($pattern, $content), 'Forbidden fixture pattern [' . $name . '] in ' . $label);
        }
    }

    private function assertNoForbiddenPersistedLeak(string $content, string $label): void
    {
        foreach ($this->persistedForbiddenPatterns() as $name => $pattern) {
            $this->assertSame(0, preg_match($pattern, $content), 'Forbidden persisted pattern [' . $name . '] in ' . $label);
        }
    }

    private function fixtureForbiddenPatterns(): array
    {
        return [
            'real username value' => '/<userName>(?!REDACTED_USERNAME<)[^<]+<\/userName>/i',
            'real password value' => '/<password>(?!REDACTED_PASSWORD<)[^<]+<\/password>/i',
            'real institution element' => '/<InstitutionID>(?!INSTITUTION_ID<)[^<]+<\/InstitutionID>/i',
            'cnic' => '/\b\d{5}-\d{7}-\d\b/',
            'real mobile' => '/\b03\d{9}\b/',
            'real email' => '/[A-Z0-9._%+-]+@(?!example\.invalid\b)[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ];
    }

    private function persistedForbiddenPatterns(): array
    {
        return array_merge($this->fixtureForbiddenPatterns(), [
            'raw soap envelope' => '/<[^>]*(?:Envelope|Header|Body)\b/i',
            'raw kuickpay result element' => '/<(?:InsertVoucher|BillPaymentInquiry|BillPaymentBulkInquiry)Result\b/i',
            'credential key' => '/\b(?:userName|password|InstitutionID)\b/i',
        ]);
    }

    private function fixtureFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::FIXTURE_DIR));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function stringify($value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }

    private function inquiryResult(string $fixture): string
    {
        $xml = (string) file_get_contents(self::FIXTURE_DIR . '/' . $fixture);
        preg_match('/<BillPaymentInquiryResult>(.*?)<\/BillPaymentInquiryResult>/s', $xml, $matches);

        return trim(html_entity_decode($matches[1] ?? ''));
    }

    private function bulkResult(string $fixture): string
    {
        $xml = (string) file_get_contents(self::FIXTURE_DIR . '/' . $fixture);
        preg_match('/<BillPaymentBulkInquiryResult><!\[CDATA\[(.*?)\]\]><\/BillPaymentBulkInquiryResult>/s', $xml, $matches);

        return trim($matches[1] ?? '');
    }

    private function outcome(string $rawResult, string $operation = 'BillPaymentInquiry'): array
    {
        return [
            'ok' => true,
            'operation' => $operation,
            'raw_result' => $rawResult,
            'error_class' => null,
            'redacted_trace_id' => 'kp_trace_safe',
        ];
    }

    private function voucher(array $overrides = [])
    {
        return (object) array_merge([
            'id' => 1,
            'company_id' => 1,
            'client_id' => 10,
            'gateway_id' => 20,
            'status' => 'pending',
            'amount' => '1000.00',
            'currency' => 'PKR',
            'registration_number' => 'REG-0000001',
            'consumer_number' => 'INSTITUTION_IDREG-0000001',
            'retry_count' => 0,
            'date_expires' => '2026-06-15',
        ], $overrides);
    }

    private function postingVoucher(array $overrides = [])
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
            'kuickpay_reference' => 'KP-REF-SAFE',
            'evidence_hash' => 'hash-safe',
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
}

class KuickPaySecretLeakageVoucherRepository
{
    public array $edits = [];
    private array $vouchers;
    private array $invoiceLinks;

    public function __construct(array $vouchers, array $invoiceLinks = [])
    {
        $this->vouchers = $vouchers;
        $this->invoiceLinks = $invoiceLinks;
    }

    public function getReconcilable(int $company_id, int $limit, int $afterId = 0, string $minRecheckBefore = null): array
    {
        return $this->vouchers;
    }

    public function getExpirable(int $company_id, int $limit, int $afterId = 0): array
    {
        return [];
    }

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $vars['voucher_id'] = $voucher_id;
        $vars['company_id_scope'] = $company_id;
        $this->edits[] = $vars;

        foreach ($this->vouchers as $voucher) {
            if ((int) $voucher->id === $voucher_id && (int) $voucher->company_id === $company_id) {
                foreach ($vars as $key => $value) {
                    if (!in_array($key, ['voucher_id', 'company_id_scope'], true)) {
                        $voucher->{$key} = $value;
                    }
                }
            }
        }
    }

    public function getWithInvoices(int $voucher_id): ?array
    {
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

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return null;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return null;
    }
}

class KuickPaySecretLeakageRunRepository
{
    public array $summaries = [];

    public function getResumeCursor(int $company_id): int
    {
        return 0;
    }

    public function open(int $company_id, string $trigger_type, int $cursor): int
    {
        return 10;
    }

    public function openBulk(int $company_id, string $run_date): int
    {
        return 10;
    }

    public function updateCursor(int $run_id, int $cursor): void
    {
    }

    public function close(int $run_id, string $status, array $counts, int $cursor, string $summary): void
    {
        $this->summaries[] = compact('run_id', 'status', 'counts', 'cursor', 'summary');
    }
}

class KuickPaySecretLeakageItemRepository
{
    public array $items = [];

    public function record(array $vars): void
    {
        $this->items[] = $vars;
    }
}

class KuickPaySecretLeakageAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = [$eventName, $context];
    }
}

class KuickPaySecretLeakageClient
{
    private array $outcomes;

    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function billPaymentInquiry(array $request): array
    {
        return array_shift($this->outcomes);
    }

    public function billPaymentBulkInquiry(array $request): array
    {
        return array_shift($this->outcomes);
    }
}

class KuickPaySecretLeakageLockRepository
{
    public function acquire(int $company_id, string $lockName, int $ttlSeconds): ?string
    {
        return 'owner-token';
    }

    public function release(int $company_id, string $lockName, string $ownerToken): void
    {
    }
}

class KuickPaySecretLeakageEvidenceValidator
{
    private bool $valid;
    private array $reasons;

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
        return new KuickPayValidationResult($this->valid, $this->reasons);
    }
}

class KuickPaySecretLeakagePostingRepository
{
    public KuickPaySecretLeakageRecord $record;
    public array $edits = [];
    private array $vouchers;
    private array $links;

    public function __construct(array $vouchers, array $links)
    {
        $this->vouchers = $vouchers;
        $this->links = $links;
        $this->record = new KuickPaySecretLeakageRecord();
    }

    public function getPostable(int $company_id, int $limit, int $afterId = 0): array
    {
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
}

class KuickPaySecretLeakageRecord
{
    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }
}

class KuickPaySecretLeakageTransactions
{
    public function getByTransactionId($transaction_id, $client_id = null, $gateway_id = null)
    {
        return false;
    }

    public function add(array $vars)
    {
        return null;
    }

    public function apply($transaction_id, array $vars): void
    {
    }

    public function getApplied($transaction_id = null, $invoice_id = null): array
    {
        return [];
    }

    public function errors(): array
    {
        return [];
    }
}

class KuickPaySecretLeakageIssuanceRepository
{
    public array $edits = [];

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $this->edits[] = compact('voucher_id', 'company_id', 'vars');
    }
}
