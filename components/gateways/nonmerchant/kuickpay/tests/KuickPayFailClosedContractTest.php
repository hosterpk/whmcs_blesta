<?php

use PHPUnit\Framework\TestCase;

class KuickPayFailClosedContractTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../../../../plugins/kuickpay_reconcile/tests/fixtures/kuickpay';

    private const FAIL_CLOSED_STATUSES = ['pending', 'expired', 'manual_review', 'failed', 'retry'];

    private const PARSER_ERROR_CLASSES = [
        'timeout',
        'transport_error',
        'credential_error',
        'malformed_response',
        'unknown_status',
        'amount_mismatch',
        'duplicate_reference',
        'unmatched_reference',
    ];

    /**
     * @dataProvider unsafeFixtureProvider
     */
    public function testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence(string $fixture, string $operation)
    {
        $evidenceRows = $this->parseFixture($fixture, $operation);

        $this->assertNotEmpty($evidenceRows);

        foreach ($evidenceRows as $evidence) {
            $this->assertContains($evidence->status(), self::FAIL_CLOSED_STATUSES, $fixture);
            $this->assertNotContains($evidence->status(), ['confirmed_unposted', 'posted'], $fixture);

            if ($evidence->errorClass() !== null) {
                $this->assertContains($evidence->errorClass(), self::PARSER_ERROR_CLASSES, $fixture);
            }

            if (in_array($fixture, [
                'ambiguous/bill-payment-inquiry-empty-currency.xml',
                'ambiguous/bill-payment-inquiry-non-pkr.xml',
            ], true)) {
                $this->assertNull($evidence->errorClass(), $fixture);
                $this->assertContains('currency_mismatch', $evidence->validationErrors(), $fixture);
                $this->assertSame('manual_review', $evidence->status(), $fixture);
            }
        }
    }

    public function unsafeFixtureProvider(): array
    {
        $cases = [];

        foreach (glob(self::FIXTURE_DIR . '/{ambiguous,malformed}/*.xml', GLOB_BRACE) as $path) {
            $fixture = str_replace(self::FIXTURE_DIR . '/', '', $path);
            if ($fixture === 'ambiguous/bill-payment-inquiry-late-after-expiry.xml') {
                continue;
            }

            $cases[$fixture] = [$fixture, $this->operationForFixture($fixture)];
        }

        ksort($cases);

        return $cases;
    }

    public function testInsertVoucherTimeoutDescriptorFailsClosedWithoutXmlParsing()
    {
        $descriptor = (string) file_get_contents(self::FIXTURE_DIR . '/ambiguous/insert-voucher-timeout.md');

        $this->assertStringContainsString('Operation: `InsertVoucher`', $descriptor);
        $this->assertStringContainsString('Error class: `timeout`', $descriptor);

        $evidence = (new KuickPayResponseParser())->parse([
            'ok' => false,
            'operation' => 'InsertVoucher',
            'raw_result' => null,
            'error_class' => 'timeout',
            'redacted_trace_id' => 'kp_timeout',
        ]);

        $this->assertSame('manual_review', $evidence->status());
        $this->assertSame('timeout', $evidence->errorClass());
        $this->assertNotContains($evidence->status(), ['confirmed_unposted', 'posted']);
    }

    private function parseFixture(string $fixture, string $operation): array
    {
        $parser = new KuickPayResponseParser();
        $rawResult = $this->fixtureResult($fixture);

        if ($operation === 'BillPaymentBulkInquiry') {
            return $parser->parseBulk(
                $this->outcome($operation, $rawResult),
                [
                    'expected' => [
                        'INSTITUTION_ID1234INVOICE_ID' => [
                            'amount' => '1000.00',
                            'currency' => 'PKR',
                        ],
                    ],
                ]
            );
        }

        return [
            $parser->parse($this->outcome($operation, $rawResult), [
                'expected_amount' => '1000.00',
                'expected_currency' => 'PKR',
                'expected_registration_number' => 'REG-0000001',
            ]),
        ];
    }

    private function operationForFixture(string $fixture): string
    {
        if (strpos($fixture, 'insert-voucher-') !== false) {
            return 'InsertVoucher';
        }

        if (strpos($fixture, 'bill-payment-bulk-') !== false) {
            return 'BillPaymentBulkInquiry';
        }

        return 'BillPaymentInquiry';
    }

    private function outcome(string $operation, ?string $rawResult): array
    {
        return [
            'ok' => true,
            'operation' => $operation,
            'raw_result' => $rawResult,
            'error_class' => null,
            'redacted_trace_id' => 'kp_trace',
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
}
