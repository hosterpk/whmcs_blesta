<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('NonmerchantGateway')) {
    class NonmerchantGateway
    {
    }
}

if (!class_exists('Language')) {
    class Language
    {
        public static function _($key, $return = false)
        {
            return $key;
        }
    }
}

require_once __DIR__ . '/../kuickpay.php';

class KuickPayConnectionProbeInput
{
    public $errors = [];

    public function setRules(array $rules)
    {
    }

    public function validates(array $vars)
    {
        $this->errors = [];
        return true;
    }

    public function setErrors(array $errors)
    {
        $this->errors = $errors;
    }

    public function errors()
    {
        return $this->errors;
    }
}

class KuickPayConnectionProbeGateway extends Kuickpay
{
    public $probeCalls = [];
    public $probeResult = ['errno' => 7, 'response_code' => 0];

    protected function executeConnectionProbe($url, array $options)
    {
        $this->probeCalls[] = ['url' => $url, 'options' => $options];
        return $this->probeResult;
    }
}

class KuickPayConnectionProbeTest extends TestCase
{
    public function testSentinelRunsProbeAndIsNotPersisted()
    {
        $input = new KuickPayConnectionProbeInput();
        $gateway = $this->gateway($input);

        $meta = $this->meta(['run_connection_test' => 'true']);
        $result = $gateway->editSettings($meta);

        $this->assertArrayNotHasKey('run_connection_test', $result);
        $this->assertCount(1, $gateway->probeCalls);
        $this->assertSame(
            'Kuickpay.!error.connection.unreachable',
            $input->errors['connection']['unreachable']
        );
    }

    public function testNormalSaveDoesNotRunProbe()
    {
        $input = new KuickPayConnectionProbeInput();
        $gateway = $this->gateway($input);

        $result = $gateway->editSettings($this->meta());

        $this->assertArrayNotHasKey('run_connection_test', $result);
        $this->assertSame([], $gateway->probeCalls);
        $this->assertSame([], $input->errors);
    }

    public function testUserinfoUrlIsRejectedBeforeProbe()
    {
        $input = new KuickPayConnectionProbeInput();
        $gateway = $this->gateway($input);

        $gateway->editSettings($this->meta([
            'wsdl_url' => 'https://user:pass@example.test/wsdl',
            'run_connection_test' => 'true',
        ]));

        $this->assertSame([], $gateway->probeCalls);
        $this->assertSame(
            'Kuickpay.!error.connection.url_userinfo',
            $input->errors['connection']['url_userinfo']
        );
    }

    public function testProbeUsesBoundedTlsGetAndSendsNoCredentials()
    {
        $input = new KuickPayConnectionProbeInput();
        $gateway = $this->gateway($input);
        $gateway->probeResult = ['errno' => 0, 'response_code' => 404];

        $gateway->editSettings($this->meta([
            'soap_timeout' => '99999',
            'run_connection_test' => 'true',
        ]));

        $this->assertSame([], $input->errors);
        $this->assertCount(1, $gateway->probeCalls);
        $call = $gateway->probeCalls[0];

        $this->assertSame('https://example.test/api.asmx?WSDL', $call['url']);
        $this->assertSame(120, $call['options'][CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(120, $call['options'][CURLOPT_TIMEOUT]);
        $this->assertTrue($call['options'][CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $call['options'][CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURLPROTO_HTTPS, $call['options'][CURLOPT_PROTOCOLS]);
        $this->assertFalse($call['options'][CURLOPT_FOLLOWLOCATION]);
        $this->assertTrue($call['options'][CURLOPT_RETURNTRANSFER]);
        $this->assertFalse($call['options'][CURLOPT_NOBODY]);
        $this->assertStringNotContainsString('voucher-user', serialize($call));
        $this->assertStringNotContainsString('voucher-secret', serialize($call));
        $this->assertStringNotContainsString('inquiry-user', serialize($call));
        $this->assertStringNotContainsString('inquiry-secret', serialize($call));
    }

    public function testTimeoutMapsBeforeGenericUnreachable()
    {
        $input = new KuickPayConnectionProbeInput();
        $gateway = $this->gateway($input);
        $gateway->probeResult = ['errno' => CURLE_OPERATION_TIMEDOUT, 'response_code' => 0];

        $gateway->editSettings($this->meta([
            'soap_timeout' => '1',
            'run_connection_test' => 'true',
        ]));

        $this->assertSame(1, $gateway->probeCalls[0]['options'][CURLOPT_TIMEOUT]);
        $this->assertSame(
            'Kuickpay.!error.connection.timeout',
            $input->errors['connection']['timeout']
        );
    }

    private function gateway(KuickPayConnectionProbeInput $input)
    {
        $reflection = new ReflectionClass(KuickPayConnectionProbeGateway::class);
        $gateway = $reflection->newInstanceWithoutConstructor();
        $gateway->Input = $input;

        return $gateway;
    }

    private function meta(array $overrides = [])
    {
        return array_merge([
            'wsdl_url' => 'https://example.test/api.asmx?WSDL',
            'voucher_username' => 'voucher-user',
            'voucher_password' => 'voucher-secret',
            'inquiry_same_as_voucher' => 'false',
            'inquiry_username' => 'inquiry-user',
            'inquiry_password' => 'inquiry-secret',
            'institution_id' => 'KP01',
            'registration_number_pattern' => 'REG{invoice}',
            'consumer_number_pattern' => 'CON{invoice}',
            'currency_policy' => 'pkr_only',
            'fee_policy' => 'none',
            'soap_timeout' => '30',
        ], $overrides);
    }
}
