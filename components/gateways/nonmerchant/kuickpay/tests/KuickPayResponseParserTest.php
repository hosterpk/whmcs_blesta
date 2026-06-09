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
