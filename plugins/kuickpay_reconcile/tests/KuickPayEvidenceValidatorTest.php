<?php

use PHPUnit\Framework\TestCase;

class KuickPayEvidenceValidatorTest extends TestCase
{
    private KuickPayEvidenceValidatorFakeVoucherRepository $voucherRepository;

    public function testValidConfirmedInquiryEvidencePassesWithNullConsumerNumber()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(),
            [$this->invoiceLink()],
            $this->evidence(['consumer_number' => null])
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->reasons());
        $this->assertSame('confirmed_unposted', $result->outcomeStatus());
    }

    public function testConfirmedUnpostedVoucherPassesWhenAllowedForPosting()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['status' => 'confirmed_unposted']),
            [$this->invoiceLink()],
            $this->evidence(['consumer_number' => null]),
            ['confirmed_unposted']
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->reasons());
    }

    public function testConfirmedUnpostedVoucherIsRejectedByDefault()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['status' => 'confirmed_unposted']),
            [$this->invoiceLink()],
            $this->evidence(['consumer_number' => null])
        );

        $this->assertFalse($result->isValid());
        $this->assertContains('stale_voucher', $result->reasons());
    }

    public function testMultipleInvoiceLinkAllocationsSummingToVoucherAmountPass()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['amount' => '1000.00']),
            [
                $this->invoiceLink(['invoice_id' => 55, 'amount' => '600.00']),
                $this->invoiceLink(['invoice_id' => 56, 'amount' => '400.00']),
            ],
            $this->evidence(['consumer_number' => null])
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->reasons());
    }

    public function testMultipleInvoiceLinkAllocationsNotSummingToVoucherAmountFailAmount()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['amount' => '1000.00']),
            [
                $this->invoiceLink(['invoice_id' => 55, 'amount' => '600.00']),
                $this->invoiceLink(['invoice_id' => 56, 'amount' => '300.00']),
            ],
            $this->evidence(['consumer_number' => null])
        );

        $this->assertFalse($result->isValid());
        $this->assertContains('amount_mismatch', $result->reasons());
    }

    public function testTrailingZeroVoucherAmountMatchesTwoDecimalEvidenceInMinorUnits()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['amount' => '1000.0']),
            [$this->invoiceLink(['amount' => '1000.0'])],
            $this->evidence(['consumer_number' => null, 'amount' => '1000.00'])
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->reasons());
    }

    public function testPaidAfterVoucherExpiryFailsWithLatePaymentReason()
    {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher(['date_expires' => '2026-06-08']),
            [$this->invoiceLink()],
            $this->evidence(['paid_at' => '2026-06-09'])
        );

        $this->assertFalse($result->isValid());
        $this->assertSame(['late_payment'], array_values(array_intersect(['late_payment'], $result->reasons())));
    }

    /**
     * @dataProvider notLatePaymentProvider
     */
    public function testLatePaymentReasonIsNoopWhenExpiryOrPaidDateAbsentOrNotAfter(
        array $voucherOverrides,
        array $evidenceOverrides
    ) {
        $validator = $this->validator();

        $result = $validator->validate(
            $this->voucher($voucherOverrides),
            [$this->invoiceLink()],
            $this->evidence($evidenceOverrides)
        );

        $this->assertTrue($result->isValid());
        $this->assertNotContains('late_payment', $result->reasons());
    }

    public function notLatePaymentProvider(): array
    {
        return [
            'null expiry' => [['date_expires' => null], ['paid_at' => '2026-06-09']],
            'empty expiry' => [['date_expires' => ''], ['paid_at' => '2026-06-09']],
            'null paid date' => [['date_expires' => '2026-06-08'], ['paid_at' => null]],
            'paid on expiry date' => [['date_expires' => '2026-06-09'], ['paid_at' => '2026-06-09']],
            'paid before expiry date' => [['date_expires' => '2026-06-10'], ['paid_at' => '2026-06-09']],
        ];
    }

    /**
     * @dataProvider failureProvider
     */
    public function testValidationFailuresReturnMachineReasonCodes(
        array $voucherOverrides,
        array $linkOverrides,
        array $invoiceOverrides,
        array $evidenceOverrides,
        ?object $duplicateReference,
        ?object $activeSibling,
        string $expectedReason
    ) {
        $validator = $this->validator($invoiceOverrides === ['missing' => true] ? null : $invoiceOverrides);
        $this->voucherRepository->duplicateReference = $duplicateReference;
        $this->voucherRepository->activeSibling = $activeSibling;

        $links = $linkOverrides === ['empty' => true] ? [] : [$this->invoiceLink($linkOverrides)];

        $result = $validator->validate(
            $this->voucher($voucherOverrides),
            $links,
            $this->evidence($evidenceOverrides)
        );

        $this->assertFalse($result->isValid());
        $this->assertContains($expectedReason, $result->reasons());
        $this->assertSame('manual_review', $result->outcomeStatus());
    }

    public function failureProvider(): array
    {
        return [
            'currency drift' => [
                [],
                [],
                ['currency' => 'USD'],
                [],
                null,
                null,
                'currency_mismatch',
            ],
            'amount mismatch' => [
                [],
                ['amount' => '999.99'],
                [],
                [],
                null,
                null,
                'amount_mismatch',
            ],
            'registration mismatch' => [
                [],
                [],
                [],
                ['registration_number' => 'REG-OTHER'],
                null,
                null,
                'unmatched_reference',
            ],
            'consumer mismatch when present' => [
                [],
                [],
                [],
                ['consumer_number' => 'CONSUMER-OTHER'],
                null,
                null,
                'unmatched_reference',
            ],
            'empty invoice links' => [
                [],
                ['empty' => true],
                [],
                [],
                null,
                null,
                'invoice_mismatch',
            ],
            'missing invoice' => [
                [],
                [],
                ['missing' => true],
                [],
                null,
                null,
                'invoice_mismatch',
            ],
            'void invoice' => [
                [],
                [],
                ['status' => 'void'],
                [],
                null,
                null,
                'invoice_mismatch',
            ],
            'paid invoice' => [
                [],
                [],
                ['due' => 0.0],
                [],
                null,
                null,
                'invoice_mismatch',
            ],
            'wrong client invoice' => [
                [],
                [],
                ['client_id' => 999],
                [],
                null,
                null,
                'invoice_mismatch',
            ],
            'stale voucher status' => [
                ['status' => 'manual_review'],
                [],
                [],
                [],
                null,
                null,
                'stale_voucher',
            ],
            'already posted voucher' => [
                ['blesta_transaction_id' => 123],
                [],
                [],
                [],
                null,
                null,
                'stale_voucher',
            ],
            'active sibling voucher' => [
                [],
                [],
                [],
                [],
                null,
                (object) ['id' => 44],
                'stale_voucher',
            ],
            'duplicate reference' => [
                [],
                [],
                [],
                [],
                (object) ['id' => 45],
                null,
                'duplicate_reference',
            ],
            'empty reference' => [
                [],
                [],
                [],
                ['reference' => ''],
                null,
                null,
                'duplicate_reference',
            ],
        ];
    }

    private function validator($invoiceOverrides = []): KuickPayEvidenceValidator
    {
        $this->voucherRepository = new KuickPayEvidenceValidatorFakeVoucherRepository();

        return new KuickPayEvidenceValidator([
            'voucher_repository' => $this->voucherRepository,
            'invoice_reader' => new KuickPayEvidenceValidatorFakeInvoiceReader(
                $invoiceOverrides === null ? null : $this->invoice((array) $invoiceOverrides)
            ),
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
            'blesta_transaction_id' => null,
        ], $overrides);
    }

    private function invoiceLink(array $overrides = [])
    {
        return (object) array_merge([
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

    private function evidence(array $overrides = []): KuickPayEvidence
    {
        $data = array_merge([
            'status' => 'confirmed_unposted',
            'error_class' => null,
            'reference' => 'KP-REF-PAID',
            'consumer_number' => null,
            'registration_number' => 'REG-0000001',
            'amount' => '1000.00',
            'currency' => 'PKR',
            'paid_at' => '2026-06-09',
            'raw_status' => 'P',
            'redacted_trace_id' => 'kp_trace',
            'evidence_hash' => 'hash',
            'validation_errors' => [],
            'operation' => 'BillPaymentInquiry',
        ], $overrides);

        return new KuickPayEvidence(
            $data['status'],
            $data['error_class'],
            $data['reference'],
            $data['consumer_number'],
            $data['registration_number'],
            $data['amount'],
            $data['currency'],
            $data['paid_at'],
            $data['raw_status'],
            $data['redacted_trace_id'],
            $data['evidence_hash'],
            $data['validation_errors'],
            $data['operation']
        );
    }
}

class KuickPayEvidenceValidatorFakeInvoiceReader
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

class KuickPayEvidenceValidatorFakeVoucherRepository
{
    public ?stdClass $duplicateReference = null;
    public ?stdClass $activeSibling = null;

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->duplicateReference;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass
    {
        return $this->activeSibling;
    }
}
