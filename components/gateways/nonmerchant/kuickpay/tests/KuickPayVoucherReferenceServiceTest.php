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
}

class TestableKuickPayVoucherReferenceService extends KuickPayVoucherReferenceService
{
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

    public function testCreateUsesDeterministicReferencesForInvoiceContext()
    {
        $repository = new KuickPayVoucherReferenceServiceFakeRepository();
        $repository->records[101] = [
            'voucher' => $this->voucherRow(101, '000055', 'KP000055'),
            'invoices' => [$this->invoiceRow(55, '1500.00')],
        ];

        $service = new KuickPayVoucherReferenceService($repository);
        $voucher = $service->getOrCreateForInvoiceContext($this->context());

        $this->assertSame(1, $repository->createCalls);
        $this->assertSame('000055', $repository->createdVoucherData['registration_number']);
        $this->assertSame('KP000055', $repository->createdVoucherData['consumer_number']);
        $this->assertSame('pending', $repository->createdVoucherData['status']);
        $this->assertSame('000055', $voucher['registration_number']);
        $this->assertSame('KP000055', $voucher['consumer_number']);
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

    private function context()
    {
        return [
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
        ];
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
