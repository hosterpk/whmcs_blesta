<?php

use PHPUnit\Framework\TestCase;

class KuickPayRedactorTest extends TestCase
{
    public function testRedactsFlatAndNestedSensitiveArrayValues()
    {
        $redactor = new KuickPayRedactor();

        $redacted = $redactor->redactArray([
            'userName' => 'voucher-user',
            'Password' => 'secret',
            'Name' => 'Customer Name',
            'safe' => 'visible',
            'nested' => [
                'mobile' => '03001234567',
                'amount' => '1000.00',
                'InstitutionID' => 12345,
                'empty' => null,
            ],
        ]);

        $this->assertSame('xxxxxxxxxxxx', $redacted['userName']);
        $this->assertSame('xxxxxx', $redacted['Password']);
        $this->assertSame('xxxxxxxxxxxxx', $redacted['Name']);
        $this->assertSame('visible', $redacted['safe']);
        $this->assertSame('xxxxxxxxxxx', $redacted['nested']['mobile']);
        $this->assertSame('1000.00', $redacted['nested']['amount']);
        $this->assertSame('xxxxx', $redacted['nested']['InstitutionID']);
        $this->assertNull($redacted['nested']['empty']);
    }

    public function testRedactsArrayValuedAndObjectSensitiveValues()
    {
        $redactor = new KuickPayRedactor();

        $redacted = $redactor->redactArray([
            'password' => ['old' => 'oldsecret', 'new' => 'newsecret'],
            'Name' => ['First', 'Last'],
            'Mobile' => (object) ['unexpected' => 'shape'],
            'safe' => 'visible',
        ]);

        $this->assertSame('xxxxxxxxx', $redacted['password']['old']);
        $this->assertSame('xxxxxxxxx', $redacted['password']['new']);
        $this->assertSame('xxxxx', $redacted['Name'][0]);
        $this->assertSame('xxxx', $redacted['Name'][1]);
        $this->assertSame('xxxx', $redacted['Mobile']);
        $this->assertSame('visible', $redacted['safe']);
    }

    public function testRedactsDefaultNamespaceEnvelopeByLocalElementName()
    {
        $redactor = new KuickPayRedactor();
        $xml = file_get_contents(__DIR__ . '/../../../../../docs/kuickpay/fixtures/redaction/credentials.xml');

        $redacted = $redactor->redactEnvelope($xml);

        $this->assertStringContainsString('<userName>xxxx</userName>', $redacted);
        $this->assertStringContainsString('<password>xxxx</password>', $redacted);
        $this->assertStringContainsString('<Name>xxxx</Name>', $redacted);
        $this->assertStringContainsString('<Mobile>xxxx</Mobile>', $redacted);
        $this->assertStringContainsString('<InstitutionID>xxxx</InstitutionID>', $redacted);
        $this->assertStringNotContainsString('voucher-user', $redacted);
        $this->assertStringNotContainsString('03001234567', $redacted);
    }

    public function testRedactEnvelopeBlanksResultPayloadIncludingBulkCdata()
    {
        $redactor = new KuickPayRedactor();
        $xml = file_get_contents(
            __DIR__ . '/../../../../../docs/kuickpay/fixtures/bill-payment-bulk-inquiry/matched-paid.xml'
        );

        $redacted = $redactor->redactEnvelope($xml);

        $this->assertStringContainsString('<BillPaymentBulkInquiryResult>xxxx</BillPaymentBulkInquiryResult>', $redacted);
        $this->assertStringNotContainsString('INSTITUTION_ID', $redacted);
        $this->assertStringNotContainsString('Consumer_Number', $redacted);
    }

    public function testUnsafeOrUnparseableEnvelopeReturnsPlaceholder()
    {
        $redactor = new KuickPayRedactor();

        $this->assertSame(
            KuickPayRedactor::ENVELOPE_UNPARSEABLE,
            $redactor->redactEnvelope('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo />')
        );
        $this->assertSame(KuickPayRedactor::ENVELOPE_UNPARSEABLE, $redactor->redactEnvelope('<foo>'));
        $this->assertSame(KuickPayRedactor::ENVELOPE_UNPARSEABLE, $redactor->redactEnvelope(''));
        $this->assertSame(
            KuickPayRedactor::ENVELOPE_UNPARSEABLE,
            $redactor->redactEnvelope(str_repeat('a', KuickPayRedactor::MAX_ENVELOPE_BYTES + 1))
        );
    }

    public function testTraceIdContainsNoSuppliedData()
    {
        $redactor = new KuickPayRedactor();

        $traceId = $redactor->traceId();

        $this->assertRegExp('/^kp_[a-f0-9]{16}$/', $traceId);
        $this->assertStringNotContainsString('user', $traceId);
        $this->assertStringNotContainsString('password', $traceId);
    }

    public function testOperationLogFieldsUseCanonicalSafeShape()
    {
        $fields = KuickPayRedactor::operationLogFields(
            'InsertVoucher',
            'kp_1234567890abcdef',
            123,
            [
                'userName' => 'xxxxxxxxxxxx',
                'RegistrationNumber' => 'REG-1',
                'raw_result' => '00 SECRET',
            ],
            [
                'response_present' => true,
                'result_present' => true,
                'result_code' => '00',
                'fault' => KuickPayRedactor::logSafeFaultToken(
                    '<Envelope><Body><InsertVoucherResult>03001234567</InsertVoucherResult></Body></Envelope>',
                    'transport_error',
                    true
                ),
                'raw_envelope' => '<Envelope />',
            ],
            null,
            12,
            2
        );

        $this->assertSame([
            'operation',
            'redacted_trace_id',
            'voucher_id',
            'request_summary',
            'response_summary',
            'error_class',
            'duration_ms',
            'attempt',
        ], array_keys($fields));
        $this->assertSame('InsertVoucher', $fields['operation']);
        $this->assertSame('kp_1234567890abcdef', $fields['redacted_trace_id']);
        $this->assertSame(123, $fields['voucher_id']);
        $this->assertSame(12, $fields['duration_ms']);
        $this->assertSame(2, $fields['attempt']);
        $this->assertArrayNotHasKey('raw_result', $fields['request_summary']);
        $this->assertArrayNotHasKey('raw_envelope', $fields['response_summary']);
        $this->assertSame('xml_fault_redacted', $fields['response_summary']['fault']);
        $this->assertStringNotContainsString('Envelope', json_encode($fields));
        $this->assertStringNotContainsString('03001234567', json_encode($fields));
    }
}
