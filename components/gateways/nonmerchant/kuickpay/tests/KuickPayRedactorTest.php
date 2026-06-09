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
}
