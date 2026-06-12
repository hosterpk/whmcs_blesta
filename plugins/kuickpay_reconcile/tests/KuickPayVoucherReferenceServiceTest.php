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

class KuickPayVoucherReferenceFakeRepository
{
    public function getPendingByInvoiceSet(array $invoiceIds, int $companyId)
    {
        return null;
    }

    public function getPendingByInvoiceId(int $invoiceId, int $companyId)
    {
        return null;
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
        return null;
    }
}
