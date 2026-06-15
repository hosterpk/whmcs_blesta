<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/KuickPayIssuanceService.php';

class KuickPayIssuanceServiceTest extends TestCase
{
    public function testRecordIssueOutcomePersistsSuccessAndIssuedAudit()
    {
        $repo = new KuickPayIssuanceFakeVoucherRepository();
        $audit = new KuickPayIssuanceFakeAuditService();
        $service = new KuickPayIssuanceService($repo, $audit);
        $evidence = new KuickPayEvidence(
            'pending',
            null,
            'KP-ISSUED-123',
            null,
            'REG-0000001',
            null,
            null,
            null,
            '00',
            'trace123',
            'hash123'
        );

        $service->recordIssueOutcome(25, 1, $evidence);

        $this->assertSame(25, $repo->edits[0]['voucher_id']);
        $this->assertSame(1, $repo->edits[0]['company_id']);
        $this->assertSame('pending', $repo->edits[0]['vars']['status']);
        $this->assertSame('KP-ISSUED-123', $repo->edits[0]['vars']['kuickpay_reference']);
        $this->assertSame('00', $repo->edits[0]['vars']['raw_status']);
        $this->assertStringNotContainsString('raw_result', $repo->edits[0]['vars']['diagnostic_summary']);
        $this->assertSame('voucher.issued', $audit->events[0]['event']);
        $this->assertSame(1, $audit->events[0]['context']['company_id']);
        $this->assertSame(25, $audit->events[0]['context']['voucher_id']);
        $this->assertSame('hash123', $audit->events[0]['context']['evidence_hash']);
    }

    public function testTransportAmbiguousIssueOutcomePersistsRetryForReconciliation()
    {
        $repo = new KuickPayIssuanceFakeVoucherRepository();
        $audit = new KuickPayIssuanceFakeAuditService();
        $service = new KuickPayIssuanceService($repo, $audit);
        $evidence = new KuickPayEvidence(
            'manual_review',
            'timeout',
            null,
            null,
            'REG-0000001',
            null,
            null,
            null,
            null,
            'trace-timeout',
            'hash-timeout',
            ['transport_failure']
        );

        $service->recordIssueOutcome(25, 1, $evidence);

        $this->assertSame('retry', $repo->edits[0]['vars']['status']);
        $this->assertSame('timeout', $repo->edits[0]['vars']['error_class']);
        $this->assertSame('evidence.rejected', $audit->events[0]['event']);
        $this->assertSame(['transport_failure'], $audit->events[0]['context']['payload']['validation_errors']);
    }
}

class KuickPayIssuanceFakeVoucherRepository
{
    public array $edits = [];

    public function edit(int $voucher_id, int $company_id, array $vars): int
    {
        $this->edits[] = compact('voucher_id', 'company_id', 'vars');

        // Models the scoped UPDATE's affected-row count contract.
        return 1;
    }
}

class KuickPayIssuanceFakeAuditService
{
    public array $events = [];

    public function record(string $eventName, array $context): void
    {
        $this->events[] = ['event' => $eventName, 'context' => $context];
    }
}
