<?php

use PHPUnit\Framework\TestCase;

class KuickPaySoapClientFake
{
    public $calls = [];
    public $lastRequest = '';
    public $lastResponse = '';
    public $throw;
    public $return;

    public function __soapCall($operation, $arguments)
    {
        $this->calls[] = [$operation, $arguments];

        if ($this->throw) {
            throw $this->throw;
        }

        return $this->return;
    }

    public function __getLastRequest()
    {
        return $this->lastRequest;
    }

    public function __getLastResponse()
    {
        return $this->lastResponse;
    }
}

class KuickPaySoapClientTest extends TestCase
{
    public function testFactoryReceivesTlsTimeoutTraceAndCacheOptions()
    {
        $capturedWsdl = null;
        $capturedOptions = null;
        $fake = new KuickPaySoapClientFake();
        $fake->return = (object) ['InsertVoucherResult' => '00 VOUCHERID00001'];
        $fake->lastResponse = $this->fixture('insert-voucher/success.xml');

        $client = new KuickPaySoapClient($this->config(['soap_timeout' => '7']), function ($wsdl, $options) use (
            &$capturedWsdl,
            &$capturedOptions,
            $fake
        ) {
            $capturedWsdl = $wsdl;
            $capturedOptions = $options;
            return $fake;
        });

        $this->callPrivate($client, 'InsertVoucher', ['Name' => 'Customer Name']);

        $this->assertSame('https://example.com/api.asmx?WSDL', $capturedWsdl);
        $this->assertSame(7, $capturedOptions['connection_timeout']);
        $this->assertTrue($capturedOptions['exceptions']);
        $this->assertTrue($capturedOptions['trace']);
        $this->assertSame(WSDL_CACHE_MEMORY, $capturedOptions['cache_wsdl']);
        $contextOptions = stream_context_get_options($capturedOptions['stream_context']);
        $this->assertSame(7, $contextOptions['http']['timeout']);
        $this->assertTrue($contextOptions['ssl']['verify_peer']);
        $this->assertTrue($contextOptions['ssl']['verify_peer_name']);
        $this->assertFalse($contextOptions['ssl']['allow_self_signed']);
    }

    public function testSuccessfulCallReturnsRawResultAndNoBusinessDecision()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->return = (object) ['InsertVoucherResult' => '00 VOUCHERID00001'];
        $fake->lastResponse = $this->fixture('insert-voucher/success.xml');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', [
            'userName' => 'voucher-user',
            'password' => 'secret',
            'Name' => 'Customer Name',
        ]);

        $this->assertTrue($outcome['ok']);
        $this->assertSame('InsertVoucher', $outcome['operation']);
        $this->assertSame('00 VOUCHERID00001', $outcome['raw_result']);
        $this->assertNull($outcome['error_class']);
        $this->assertArrayNotHasKey('status', $outcome);
        $this->assertArrayNotHasKey('paid', $outcome);
        $this->assertArrayNotHasKey('confirmed', $outcome);
        $this->assertSame('xxxxxxxxxxxx', $outcome['redacted_request']['userName']);
        $this->assertSame('xxxxxx', $outcome['redacted_request']['password']);
        $this->assertSame('xxxxxxxxxxxxx', $outcome['redacted_request']['Name']);
    }

    public function testSoapFaultWithResponseBodyIsTransportSuccess()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault('Server', 'Provider returned a body');
        $fake->lastResponse = $this->fixture('insert-voucher/invalid-credentials.xml');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertTrue($outcome['ok']);
        $this->assertNull($outcome['error_class']);
        $this->assertSame('05 INVALID_CREDENTIALS', $outcome['raw_result']);
        $this->assertStringContainsString('Provider returned a body', $outcome['fault']);
    }

    public function testTimeoutWithoutResponseBodyMapsToTimeout()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault('HTTP', 'connection timed out');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('timeout', $outcome['error_class']);
        $this->assertNull($outcome['raw_result']);
    }

    public function testConstructionFailureReturnsTransportOutcome()
    {
        $client = new KuickPaySoapClient($this->config(), function () {
            throw new RuntimeException('TLS failure');
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('transport_error', $outcome['error_class']);
        $this->assertStringContainsString('TLS failure', $outcome['fault']);
    }

    public function testEmptyOrUserinfoWsdlUrlIsBlocked()
    {
        $factoryCalled = false;
        $client = new KuickPaySoapClient($this->config(['wsdl_url' => 'https://user:pass@example.com/api.asmx?WSDL']), function () use (
            &$factoryCalled
        ) {
            $factoryCalled = true;
            return new KuickPaySoapClientFake();
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('transport_error', $outcome['error_class']);
        $this->assertFalse($factoryCalled);
    }

    private function callPrivate(KuickPaySoapClient $client, $operation, array $params)
    {
        $method = new ReflectionMethod($client, 'call');
        $method->setAccessible(true);

        return $method->invoke($client, $operation, $params);
    }

    private function config(array $overrides = [])
    {
        return array_merge([
            'wsdl_url' => 'https://example.com/api.asmx?WSDL',
            'soap_timeout' => '30',
            'institution_id' => 'KP01',
            'voucher_username' => 'voucher-user',
            'voucher_password' => 'voucher-secret',
            'inquiry_username' => 'inquiry-user',
            'inquiry_password' => 'inquiry-secret',
            'inquiry_same_as_voucher' => 'false',
            'logging_enabled' => 'false',
        ], $overrides);
    }

    private function fixture($path)
    {
        return file_get_contents(__DIR__ . '/../../../../../docs/kuickpay/fixtures/' . $path);
    }
}
