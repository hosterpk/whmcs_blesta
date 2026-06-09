<?php

use PHPUnit\Framework\TestCase;

class KuickPayResponseParserTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../../../../plugins/kuickpay_reconcile/tests/fixtures/kuickpay';

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
        // Field 4 (txn ref) is purely numeric here: a regression guard against the old
        // comma-count reconstruction, which mistook a numeric txn ref for a split amount and
        // corrupted the reference/currency mapping. Amount, currency, and date still normalize.
        $evidence = $this->parser()->parse(
            $this->outcome('BillPaymentInquiry', '00,REG-0000001,20260609,1000.0,1234567890,KP-REF-PAID,pkr,INSTITUTION_ID'),
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
        $this->assertSame('KP-REF-PAID', $evidence->reference());
    }

    public function testBulkTransportFailureReturnsSingleRetryEvidence()
    {
        $evidence = $this->parser()->parseBulk([
            'ok' => false,
            'operation' => 'BillPaymentBulkInquiry',
            'raw_result' => null,
            'error_class' => 'timeout',
            'redacted_trace_id' => 'kp_bulk_transport',
        ]);

        $this->assertCount(1, $evidence);
        $this->assertEvidence('retry', 'timeout', $evidence[0]);
    }

    public function testBulkMatchedPaidRowConfirmsUnposted()
    {
        $evidence = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<NewDataSet>' . $this->bulkRow() . '</NewDataSet>'),
            [
                'expected_consumer_numbers' => ['INSTITUTION_ID1234INVOICE_ID'],
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
            ]
        );

        $this->assertCount(1, $evidence);
        $this->assertEvidence('confirmed_unposted', null, $evidence[0]);
        $this->assertSame('INSTITUTION_ID1234INVOICE_ID', $evidence[0]->consumerNumber());
        $this->assertSame('1234INVOICE_ID', $evidence[0]->registrationNumber());
        $this->assertSame('KP-BULK-PAID-0001', $evidence[0]->reference());
        $this->assertSame('1000.00', $evidence[0]->amount());
        $this->assertSame('PKR', $evidence[0]->currency());
        $this->assertSame('2026-06-09', $evidence[0]->paidAt());
    }

    public function testBulkUnmatchedRowFailsClosed()
    {
        $evidence = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<NewDataSet>' . $this->bulkRow() . '</NewDataSet>'),
            [
                'expected_consumer_numbers' => ['INSTITUTION_ID9999OTHER'],
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
            ]
        );

        $this->assertCount(1, $evidence);
        $this->assertEvidence('manual_review', 'unmatched_reference', $evidence[0]);
        $this->assertSame(['unmatched_reference'], $evidence[0]->validationErrors());
    }

    public function testBulkExactMatchDoesNotUseSuffix()
    {
        $dataset = '<NewDataSet>'
            . $this->bulkRow('INSTITUTION_ID1234INVOICE_ID', 'KP-LONG')
            . $this->bulkRow('1234INVOICE_ID', 'KP-SHORT')
            . '</NewDataSet>';

        $evidence = $this->parser()->parseBulk($this->outcome('BillPaymentBulkInquiry', $dataset), [
            'expected_consumer_numbers' => ['1234INVOICE_ID'],
            'expected_amount' => '1000.00',
            'expected_currency' => 'PKR',
        ]);

        $this->assertCount(2, $evidence);
        $this->assertEvidence('manual_review', 'unmatched_reference', $evidence[0]);
        $this->assertSame('INSTITUTION_ID1234INVOICE_ID', $evidence[0]->consumerNumber());
        $this->assertEvidence('confirmed_unposted', null, $evidence[1]);
        $this->assertSame('1234INVOICE_ID', $evidence[1]->consumerNumber());
    }

    public function testBulkBlankConsumerNumberNeverMatchesBlankExpected()
    {
        // A blank expected consumer (e.g. a null voucher value coerced to '') must not match a
        // blank Consumer_Number row and confirm a payment; it fails closed to unmatched.
        $row = '<Table>'
            . '<Consumer_Number></Consumer_Number>'
            . '<Registration_Number>1234INVOICE_ID</Registration_Number>'
            . '<Transaction_Date>20260609</Transaction_Date>'
            . '<Paid_Amount>1000.00</Paid_Amount>'
            . '<Transaction_Reference>KP-BULK-PAID-0001</Transaction_Reference>'
            . '<Currency>PKR</Currency>'
            . '</Table>';

        $evidence = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<NewDataSet>' . $row . '</NewDataSet>'),
            [
                'expected_consumer_numbers' => ['', 'INSTITUTION_ID1234INVOICE_ID'],
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
            ]
        );

        $this->assertCount(1, $evidence);
        $this->assertEvidence('manual_review', 'unmatched_reference', $evidence[0]);
        $this->assertSame(['unmatched_reference'], $evidence[0]->validationErrors());
    }

    public function testBulkMalformedDatasetReturnsSingleManualReviewEvidence()
    {
        $evidence = $this->parser()->parseBulk(
            $this->outcome(
                'BillPaymentBulkInquiry',
                '<NewDataSet><Table><Consumer_Number>INSTITUTION_ID1234INVOICE_ID</Consumer_Number><Paid_Amount>'
            ),
            ['expected_consumer_numbers' => ['INSTITUTION_ID1234INVOICE_ID']]
        );

        $this->assertCount(1, $evidence);
        $this->assertEvidence('manual_review', 'malformed_response', $evidence[0]);
        $this->assertNull($evidence[0]->consumerNumber());
        $this->assertSame(['malformed_dataset'], $evidence[0]->validationErrors());
    }

    public function testBulkEmptyDatasetReturnsNoEvidenceRows()
    {
        $this->assertSame([], $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<NewDataSet/>')
        ));
    }

    public function testBulkRejectsDoctypeAndOversizeDatasets()
    {
        $doctype = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<!DOCTYPE x [<!ENTITY a "x">]><NewDataSet/>')
        );
        $oversize = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', str_repeat('x', KuickPayRedactor::MAX_ENVELOPE_BYTES + 1))
        );

        $this->assertEvidence('manual_review', 'malformed_response', $doctype[0]);
        $this->assertSame(['malformed_dataset'], $doctype[0]->validationErrors());
        $this->assertEvidence('manual_review', 'malformed_response', $oversize[0]);
        $this->assertSame(['malformed_dataset'], $oversize[0]->validationErrors());
    }

    public function testBulkAmountMismatchFailsClosedForMatchedRow()
    {
        $evidence = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', '<NewDataSet>' . $this->bulkRow(
                'INSTITUTION_ID1234INVOICE_ID',
                'KP-BULK-PAID-0001',
                '900.00'
            ) . '</NewDataSet>'),
            [
                'expected_consumer_numbers' => ['INSTITUTION_ID1234INVOICE_ID'],
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
            ]
        );

        $this->assertCount(1, $evidence);
        $this->assertEvidence('manual_review', 'amount_mismatch', $evidence[0]);
        $this->assertSame(['amount_mismatch'], $evidence[0]->validationErrors());
    }

    /**
     * @dataProvider insertVoucherFixtureProvider
     */
    public function testInsertVoucherFixtureMappings(string $fixture, string $status, ?string $errorClass)
    {
        $evidence = $this->parser()->parse(
            $this->outcome('InsertVoucher', $this->fixtureResult($fixture)),
            ['expected_registration_number' => 'REG-0000001']
        );

        $this->assertEvidence($status, $errorClass, $evidence);
    }

    public function insertVoucherFixtureProvider(): array
    {
        return [
            ['valid/insert-voucher-success.xml', 'pending', null],
            ['malformed/insert-voucher-malformed.xml', 'manual_review', 'malformed_response'],
            ['ambiguous/insert-voucher-duplicate.xml', 'manual_review', 'duplicate_reference'],
            ['malformed/insert-voucher-invalid-credentials.xml', 'failed', 'credential_error'],
            ['malformed/insert-voucher-non-2-char-status.xml', 'manual_review', 'malformed_response'],
        ];
    }

    /**
     * @dataProvider inquiryFixtureProvider
     */
    public function testInquiryFixtureMappings(
        string $fixture,
        string $status,
        ?string $errorClass,
        array $validationErrors = []
    ) {
        $evidence = $this->parser()->parse(
            $this->outcome('BillPaymentInquiry', $this->fixtureResult($fixture)),
            [
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
                'expected_registration_number' => 'REG-0000001',
            ]
        );

        $this->assertEvidence($status, $errorClass, $evidence);
        $this->assertSame($validationErrors, $evidence->validationErrors());
    }

    public function inquiryFixtureProvider(): array
    {
        return [
            ['valid/bill-payment-inquiry-pending.xml', 'pending', null],
            ['valid/bill-payment-inquiry-paid-exact.xml', 'confirmed_unposted', null],
            ['valid/bill-payment-inquiry-paid-trailing-zero.xml', 'confirmed_unposted', null],
            ['valid/bill-payment-inquiry-expired.xml', 'expired', null],
            ['ambiguous/bill-payment-inquiry-amount-mismatch.xml', 'manual_review', 'amount_mismatch', ['amount_mismatch']],
            ['ambiguous/bill-payment-inquiry-unknown.xml', 'manual_review', 'unknown_status', ['unknown_status']],
            ['ambiguous/bill-payment-inquiry-non-pkr.xml', 'manual_review', null, ['currency_mismatch']],
            ['ambiguous/bill-payment-inquiry-empty-currency.xml', 'manual_review', null, ['currency_mismatch']],
            ['malformed/bill-payment-inquiry-short.xml', 'manual_review', 'malformed_response', ['malformed_result']],
        ];
    }

    public function testBulkFixtureMappings()
    {
        $context = [
            'expected_consumer_numbers' => ['INSTITUTION_ID1234INVOICE_ID'],
            'expected_amount' => '1000.00',
            'expected_currency' => 'PKR',
        ];

        $matched = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('valid/bill-payment-bulk-matched-paid.xml')),
            $context
        );
        $unmatched = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('ambiguous/bill-payment-bulk-unmatched.xml')),
            $context
        );
        $malformed = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('malformed/bill-payment-bulk-malformed-xml.xml')),
            $context
        );

        $this->assertEvidence('confirmed_unposted', null, $matched[0]);
        $this->assertEvidence('manual_review', 'unmatched_reference', $unmatched[0]);
        $this->assertEvidence('manual_review', 'malformed_response', $malformed[0]);
        $this->assertNull($malformed[0]->consumerNumber());
    }

    public function testBulkHardeningFixtures()
    {
        $context = [
            'expected_consumer_numbers' => ['INSTITUTION_ID1234INVOICE_ID'],
            'expected_amount' => '1000.00',
            'expected_currency' => 'PKR',
        ];

        $mixed = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('valid/bill-payment-bulk-mixed-multi-row.xml')),
            $context
        );
        $overpayment = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('ambiguous/bill-payment-bulk-overpayment.xml')),
            $context
        );
        $latePartial = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('ambiguous/bill-payment-bulk-late-partial.xml')),
            $context
        );
        $suffix = $this->parser()->parseBulk(
            $this->outcome('BillPaymentBulkInquiry', $this->fixtureResult('valid/bill-payment-bulk-suffix-pair.xml')),
            [
                'expected_consumer_numbers' => ['1234INVOICE_ID'],
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
            ]
        );

        $this->assertSame(['confirmed_unposted', 'manual_review', 'confirmed_unposted'], array_map(function ($evidence) {
            return $evidence->status();
        }, $mixed));
        $this->assertEvidence('manual_review', 'amount_mismatch', $overpayment[0]);
        $this->assertEvidence('manual_review', 'amount_mismatch', $latePartial[0]);
        $this->assertEvidence('manual_review', 'unmatched_reference', $suffix[0]);
        $this->assertEvidence('confirmed_unposted', null, $suffix[1]);
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

    private function fixtureResult(string $relativePath): string
    {
        $document = new DOMDocument();
        $this->assertTrue($document->load(self::FIXTURE_DIR . '/' . $relativePath));

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[substring(local-name(), string-length(local-name()) - 5) = "Result"]');
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length);

        return trim($nodes->item(0)->textContent);
    }

    private function bulkRow(
        string $consumerNumber = 'INSTITUTION_ID1234INVOICE_ID',
        string $transactionReference = 'KP-BULK-PAID-0001',
        string $amount = '1000.00',
        string $currency = 'PKR'
    ): string {
        return '<Table>'
            . '<Consumer_Number>' . $consumerNumber . '</Consumer_Number>'
            . '<Registration_Number>1234INVOICE_ID</Registration_Number>'
            . '<Transaction_Date>20260609</Transaction_Date>'
            . '<Paid_Amount>' . $amount . '</Paid_Amount>'
            . '<Transaction_Reference>' . $transactionReference . '</Transaction_Reference>'
            . '<Currency>' . $currency . '</Currency>'
            . '</Table>';
    }
}
