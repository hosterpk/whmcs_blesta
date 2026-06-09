<?php

use PHPUnit\Framework\TestCase;

class KuickPayResponseParserTest extends TestCase
{
    public function testParseRejectsBulkOperation()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use parseBulk()');

        $this->parser()->parse($this->outcome('BillPaymentBulkInquiry', '<NewDataSet/>'));
    }

    public function testUnknownOperationFailsClosedWithoutThrowing()
    {
        $evidence = $this->parser()->parse($this->outcome('UnknownOperation', '00'));

        $this->assertEvidence('manual_review', 'unknown_status', $evidence);
        $this->assertSame(['unknown_operation'], $evidence->validationErrors());
        $this->assertSame('kp_trace', $evidence->redactedTraceId());
    }

    /**
     * @dataProvider insertVoucherMappingProvider
     */
    public function testInsertVoucherCreationMappings(
        string $rawResult,
        string $expectedStatus,
        ?string $expectedError,
        ?string $expectedReference,
        array $expectedErrors
    ) {
        $evidence = $this->parser()->parse(
            $this->outcome('InsertVoucher', $rawResult),
            ['expected_registration_number' => 'REG-0000001']
        );

        $this->assertEvidence($expectedStatus, $expectedError, $evidence);
        $this->assertSame($expectedReference, $evidence->reference());
        $this->assertSame('REG-0000001', $evidence->registrationNumber());
        $this->assertNull($evidence->consumerNumber());
        $this->assertNull($evidence->amount());
        $this->assertNull($evidence->paidAt());
        $this->assertFalse($evidence->isConfirmedUnposted());
        $this->assertSame($expectedErrors, $evidence->validationErrors());
    }

    public function insertVoucherMappingProvider(): array
    {
        return [
            'created' => ['00 VOUCHERID00001', 'pending', null, 'VOUCHERID00001', []],
            'missing voucher id' => ['00', 'manual_review', 'malformed_response', null, ['missing_voucher_id']],
            'duplicate' => ['94 DUPLICATE', 'manual_review', 'duplicate_reference', null, ['duplicate_reference']],
            'credential failure' => ['05 INVALID_CREDENTIALS', 'failed', 'credential_error', null, ['credential_error']],
            'unknown numeric status' => ['77 UNDOCUMENTED', 'manual_review', 'unknown_status', null, ['unknown_status']],
            'short status' => ['0', 'manual_review', 'malformed_response', null, ['malformed_status']],
            'non numeric status' => ['XX BAD', 'manual_review', 'malformed_response', null, ['malformed_status']],
        ];
    }

    public function testInsertVoucherTransportFailureIsManualReview()
    {
        $evidence = $this->parser()->parse([
            'ok' => false,
            'operation' => 'InsertVoucher',
            'raw_result' => null,
            'error_class' => 'timeout',
            'redacted_trace_id' => 'kp_transport',
        ]);

        $this->assertEvidence('manual_review', 'timeout', $evidence);
        $this->assertNull($evidence->rawStatus());
        $this->assertSame('kp_transport', $evidence->redactedTraceId());
    }

    public function testInquiryTransportFailureIsRetry()
    {
        $evidence = $this->parser()->parse([
            'ok' => false,
            'operation' => 'BillPaymentInquiry',
            'raw_result' => null,
            'error_class' => 'transport_error',
            'redacted_trace_id' => 'kp_transport',
        ]);

        $this->assertEvidence('retry', 'transport_error', $evidence);
    }

    public function testSuccessfulTransportWithEmptyResultIsMalformed()
    {
        $evidence = $this->parser()->parse($this->outcome('InsertVoucher', ''));

        $this->assertEvidence('manual_review', 'malformed_response', $evidence);
        $this->assertSame(['empty_result'], $evidence->validationErrors());
    }

    /**
     * @dataProvider inquiryMappingProvider
     */
    public function testBillPaymentInquiryMappings(
        string $rawResult,
        array $context,
        string $expectedStatus,
        ?string $expectedError,
        array $expectedErrors
    ) {
        $evidence = $this->parser()->parse($this->outcome('BillPaymentInquiry', $rawResult), $context);

        $this->assertEvidence($expectedStatus, $expectedError, $evidence);
        $this->assertSame(substr($rawResult, 0, 2), $evidence->rawStatus());
        $this->assertSame(explode(',', $rawResult)[1], $evidence->registrationNumber());
        $this->assertNull($evidence->consumerNumber());
        $this->assertSame('KP-REF-PAID', $evidence->reference());
        $this->assertSame($expectedErrors, $evidence->validationErrors());
    }

    public function inquiryMappingProvider(): array
    {
        $context = [
            'expected_amount' => '1000.00',
            'expected_currency' => 'PKR',
            'expected_registration_number' => 'REG-0000001',
        ];

        return [
            'pending' => [
                '01,REG-0000001,,1000.00,,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'pending',
                null,
                [],
            ],
            'expired' => [
                '02,REG-0000001,,1000.00,,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'expired',
                null,
                [],
            ],
            'paid exact' => [
                '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'confirmed_unposted',
                null,
                [],
            ],
            'paid trailing zero equality' => [
                '00,REG-0000001,20260609,1000.0,KP-TXN-0001,KP-REF-PAID,pkr,INSTITUTION_ID',
                $context,
                'confirmed_unposted',
                null,
                [],
            ],
            'paid without context fails closed' => [
                '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                [],
                'manual_review',
                null,
                ['missing_expected_context'],
            ],
            'amount mismatch' => [
                '00,REG-0000001,20260609,900.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'manual_review',
                'amount_mismatch',
                ['amount_mismatch'],
            ],
            'currency mismatch' => [
                '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,USD,INSTITUTION_ID',
                $context,
                'manual_review',
                null,
                ['currency_mismatch'],
            ],
            'registration mismatch' => [
                '00,REG-OTHER,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'manual_review',
                'unmatched_reference',
                ['unmatched_reference'],
            ],
            'expected consumer also matches field one' => [
                '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context + ['expected_consumer_number' => 'REG-0000001'],
                'confirmed_unposted',
                null,
                [],
            ],
            'unknown status' => [
                '99,REG-0000001,,1000.00,,KP-REF-PAID,PKR,INSTITUTION_ID',
                $context,
                'manual_review',
                'unknown_status',
                ['unknown_status'],
            ],
        ];
    }

    public function testBillPaymentInquiryTooFewFieldsIsMalformed()
    {
        $evidence = $this->parser()->parse($this->outcome('BillPaymentInquiry', '00,REG-0000001'));

        $this->assertEvidence('manual_review', 'malformed_response', $evidence);
        $this->assertSame(['malformed_result'], $evidence->validationErrors());
    }

    public function testBillPaymentInquiryNormalizesPaidFields()
    {
        $evidence = $this->parser()->parse(
            $this->outcome('BillPaymentInquiry', '00,REG-0000001,20260609,1,000.00,KP-TXN-0001,KP-REF-PAID,PKR'),
            [
                'expected_amount' => '1000',
                'expected_currency' => 'pkr',
                'expected_registration_number' => 'REG-0000001',
            ]
        );

        $this->assertEvidence('confirmed_unposted', null, $evidence);
        $this->assertSame('1000.00', $evidence->amount());
        $this->assertSame('PKR', $evidence->currency());
        $this->assertSame('2026-06-09', $evidence->paidAt());
    }

    public function testEvidenceHashIsDeterministicAndExcludesTrace()
    {
        $first = $this->parser()->parse($this->outcome('InsertVoucher', '00 VOUCHERID00001', 'kp_first'));
        $second = $this->parser()->parse($this->outcome('InsertVoucher', '00 VOUCHERID00001', 'kp_second'));
        $different = $this->parser()->parse($this->outcome('InsertVoucher', '00 VOUCHERID00002', 'kp_second'));

        $this->assertRegExp('/^[a-f0-9]{24}$/', $first->evidenceHash());
        $this->assertSame($first->evidenceHash(), $second->evidenceHash());
        $this->assertNotSame($first->evidenceHash(), $different->evidenceHash());
    }

    private function assertEvidence(string $status, ?string $errorClass, KuickPayEvidence $evidence): void
    {
        $this->assertSame($status, $evidence->status());
        $this->assertSame($errorClass, $evidence->errorClass());
        $this->assertNotContains($evidence->status(), ['posted', 'cancelled']);
    }

    private function parser(): KuickPayResponseParser
    {
        return new KuickPayResponseParser();
    }

    private function outcome(string $operation, ?string $rawResult, string $traceId = 'kp_trace'): array
    {
        return [
            'ok' => true,
            'operation' => $operation,
            'raw_result' => $rawResult,
            'error_class' => null,
            'redacted_trace_id' => $traceId,
        ];
    }
}
