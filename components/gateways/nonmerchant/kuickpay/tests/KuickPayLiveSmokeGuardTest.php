<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/live/KuickPayLiveSmokePlan.php';

class KuickPayLiveSmokeGuardTest extends TestCase
{
    public function testPlanSkipsWhenOptInIsAbsent()
    {
        $plan = KuickPayLiveSmokePlan::plan([]);

        $this->assertFalse($plan['run']);
        $this->assertSame('opt-in-not-set', $plan['reason']);
        $this->assertSame('BillPaymentInquiry', $plan['operation']);
        $this->assertSame([], $plan['config']);
        $this->assertSame([], $plan['missing']);
    }

    public function testPlanSkipsAndNamesMissingInputsWhenOptedInIncomplete()
    {
        $plan = KuickPayLiveSmokePlan::plan([
            'KUICKPAY_LIVE_SMOKE' => '1',
            'KUICKPAY_SMOKE_WSDL_URL' => 'https://example.invalid/service?wsdl',
        ]);

        $this->assertFalse($plan['run']);
        $this->assertSame('missing-required-inputs', $plan['reason']);
        $this->assertSame([
            'KUICKPAY_SMOKE_INQUIRY_USERNAME',
            'KUICKPAY_SMOKE_INQUIRY_PASSWORD',
            'KUICKPAY_SMOKE_INSTITUTION_ID',
            'KUICKPAY_SMOKE_CONSUMER_NUMBER',
        ], $plan['missing']);
        $this->assertSame([], $plan['config']);
    }

    public function testPlanRunsWithFullOptInAndMapsGatewayConfig()
    {
        $plan = KuickPayLiveSmokePlan::plan($this->fullEnv([
            'KUICKPAY_SMOKE_OPERATION' => 'Echo',
            'KUICKPAY_SMOKE_TIMEOUT' => '45',
        ]));

        $this->assertTrue($plan['run']);
        $this->assertSame('ready', $plan['reason']);
        $this->assertSame('Echo', $plan['operation']);
        $this->assertSame([], $plan['missing']);
        $this->assertSame('https://example.invalid/service?wsdl', $plan['config']['wsdl_url']);
        $this->assertSame('inquiry-user', $plan['config']['inquiry_username']);
        $this->assertSame('inquiry-pass', $plan['config']['inquiry_password']);
        $this->assertSame('12345', $plan['config']['institution_id']);
        $this->assertSame('45', $plan['config']['soap_timeout']);
        $this->assertSame('false', $plan['config']['inquiry_same_as_voucher']);
        $this->assertSame('900000000000', $plan['consumer_number']);
    }

    public function testPlanDefaultsUnsupportedOperationAndOutOfRangeTimeoutSafely()
    {
        $plan = KuickPayLiveSmokePlan::plan($this->fullEnv([
            'KUICKPAY_SMOKE_OPERATION' => 'InsertVoucher',
            'KUICKPAY_SMOKE_TIMEOUT' => '999',
        ]));

        $this->assertTrue($plan['run']);
        $this->assertSame('BillPaymentInquiry', $plan['operation']);
        $this->assertSame('30', $plan['config']['soap_timeout']);
    }

    public function testSanitizedCaptureFixturePassesForbiddenPatternScan()
    {
        $fixture = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><BillPaymentInquiryResponse>'
            . '<BillPaymentInquiryResult>xxxx</BillPaymentInquiryResult>'
            . '<userName>REDACTED_USERNAME</userName>'
            . '<password>REDACTED_PASSWORD</password>'
            . '<InstitutionID>INSTITUTION_ID</InstitutionID>'
            . '<Email>customer@example.invalid</Email>'
            . '<Mobile>0300XXXXXXX</Mobile>'
            . '<CNIC>XXXXX-XXXXXXX-X</CNIC>'
            . '</BillPaymentInquiryResponse></soap:Body></soap:Envelope>';

        $this->assertNoForbiddenFixtureLeak($fixture);
    }

    public function testForbiddenPatternScanCatchesCredentialAndPiiValues()
    {
        $this->assertForbiddenFixtureLeak('<password>real-secret</password>');
        $this->assertForbiddenFixtureLeak('<InstitutionID>12345</InstitutionID>');
        $this->assertForbiddenFixtureLeak('03001234567');
        $this->assertForbiddenFixtureLeak('12345-1234567-1');
        $this->assertForbiddenFixtureLeak('customer@example.com');
    }

    private function fullEnv(array $overrides = []): array
    {
        return array_merge([
            'KUICKPAY_LIVE_SMOKE' => '1',
            'KUICKPAY_SMOKE_WSDL_URL' => 'https://example.invalid/service?wsdl',
            'KUICKPAY_SMOKE_INQUIRY_USERNAME' => 'inquiry-user',
            'KUICKPAY_SMOKE_INQUIRY_PASSWORD' => 'inquiry-pass',
            'KUICKPAY_SMOKE_INSTITUTION_ID' => '12345',
            'KUICKPAY_SMOKE_CONSUMER_NUMBER' => '900000000000',
        ], $overrides);
    }

    private function assertNoForbiddenFixtureLeak(string $content): void
    {
        foreach ($this->fixtureForbiddenPatterns() as $name => $pattern) {
            $this->assertSame(0, preg_match($pattern, $content), 'Forbidden fixture pattern [' . $name . ']');
        }
    }

    private function assertForbiddenFixtureLeak(string $content): void
    {
        $matched = false;
        foreach ($this->fixtureForbiddenPatterns() as $pattern) {
            $matched = $matched || preg_match($pattern, $content) === 1;
        }

        $this->assertTrue($matched, 'Expected forbidden fixture pattern to match: ' . $content);
    }

    private function fixtureForbiddenPatterns(): array
    {
        return [
            'real username value' => '/<userName>(?!REDACTED_USERNAME<)[^<]+<\/userName>/i',
            'real password value' => '/<password>(?!REDACTED_PASSWORD<)[^<]+<\/password>/i',
            'real institution element' => '/<InstitutionID>(?!INSTITUTION_ID<)[^<]+<\/InstitutionID>/i',
            'raw username element' => '/<userName>\s*(?!REDACTED_USERNAME<|xxxx<)[^<]+<\/userName>/i',
            'raw password element' => '/<password>\s*(?!REDACTED_PASSWORD<|xxxx<)[^<]+<\/password>/i',
            'cnic dashed' => '/\b\d{5}-\d{7}-\d\b/',
            'cnic undashed' => '/\b\d{13}\b/',
            'real mobile' => '/\b03\d{2}(?:[\s-]?\d){7}\b/',
            'real mobile international' => '/(?:\+92|0092)[\s-]?3\d{2}(?:[\s-]?\d){7}\b/',
            'real email' => '/[A-Z0-9._%+-]+@(?!example\.invalid\b)[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ];
    }
}
