<?php

use PHPUnit\Framework\TestCase;

class KuickPaySoapClientFake
{
    public $calls = [];
    public $lastRequest = '';
    public $lastResponse = '';
    public $throw;
    public $return;
    public $queue = [];

    public function __soapCall($operation, $arguments)
    {
        $this->calls[] = [$operation, $arguments];

        if (!empty($this->queue)) {
            $next = array_shift($this->queue);
            $this->lastResponse = $next['lastResponse'] ?? $this->lastResponse;
            if (isset($next['throw'])) {
                throw $next['throw'];
            }

            return $next['return'] ?? null;
        }

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

    public function testTimeoutIsCappedAtUpperBound()
    {
        $capturedOptions = null;
        $fake = new KuickPaySoapClientFake();
        $fake->return = (object) ['InsertVoucherResult' => '00 X'];

        $client = new KuickPaySoapClient($this->config(['soap_timeout' => '99999']), function ($wsdl, $options) use (
            &$capturedOptions,
            $fake
        ) {
            $capturedOptions = $options;
            return $fake;
        });

        $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertSame(120, $capturedOptions['connection_timeout']);
        $contextOptions = stream_context_get_options($capturedOptions['stream_context']);
        $this->assertSame(120, $contextOptions['http']['timeout']);
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

    public function testFaultDiagnosticRedactsPiiAndCredentialsFromRequest()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault(
            'Server',
            'Rejected voucher for Name: Customer Name Mobile: 03001234567 Email: john@example.com '
            . 'userName=voucher-user password=voucher-secret'
        );

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', [
            'userName' => 'voucher-user',
            'password' => 'voucher-secret',
            'Name' => 'Customer Name',
            'Mobile' => '03001234567',
            'Email' => 'john@example.com',
            'Branch' => 'Main Branch',
        ]);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('transport_error', $outcome['error_class']);
        $this->assertStringNotContainsString('Customer Name', $outcome['fault']);
        $this->assertStringNotContainsString('03001234567', $outcome['fault']);
        $this->assertStringNotContainsString('john@example.com', $outcome['fault']);
        $this->assertStringNotContainsString('voucher-user', $outcome['fault']);
        $this->assertStringNotContainsString('voucher-secret', $outcome['fault']);
    }

    public function testResponseEnvelopeWithDoctypeIsNotParsedAndEnvelopeIsPlaceheld()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault('Server', 'fault');
        $fake->lastResponse = '<!DOCTYPE x [<!ENTITY a "x">]>'
            . '<Envelope><InsertVoucherResult>&a;</InsertVoucherResult></Envelope>';

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertTrue($outcome['ok']);
        $this->assertNull($outcome['raw_result']);
        $this->assertSame(KuickPayRedactor::ENVELOPE_UNPARSEABLE, $outcome['raw_envelope']);
    }

    public function testNonSoapFaultThrowableWithResponseBodyIsTransportSuccess()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new RuntimeException('decode blew up after the body arrived');
        $fake->lastResponse = $this->fixture('insert-voucher/success.xml');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertTrue($outcome['ok']);
        $this->assertNull($outcome['error_class']);
        $this->assertSame('00 VOUCHERID00001', $outcome['raw_result']);
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

    public function testNonHttpsWsdlUrlIsBlocked()
    {
        $factoryCalled = false;
        $client = new KuickPaySoapClient(
            $this->config(['wsdl_url' => 'http://example.com/api.asmx?WSDL']),
            function () use (&$factoryCalled) {
                $factoryCalled = true;
                return new KuickPaySoapClientFake();
            }
        );

        $outcome = $this->callPrivate($client, 'InsertVoucher', []);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('transport_error', $outcome['error_class']);
        $this->assertFalse($factoryCalled);
    }

    public function testInsertVoucherInjectsVoucherCredentialsAndNeverRetries()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault('HTTP', 'connection timed out');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $client->insertVoucher(['RegistrationNumber' => 'REG-1', 'Name' => 'Customer Name']);

        $this->assertSame(1, $outcome['attempts']);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('InsertVoucher', $fake->calls[0][0]);
        $params = $fake->calls[0][1][0];
        $this->assertSame('voucher-user', $params['userName']);
        $this->assertSame('voucher-secret', $params['password']);
        $this->assertSame('KP01', $params['InstitutionID']);
        $this->assertSame('REG-1', $params['RegistrationNumber']);
        $this->assertSame('timeout', $outcome['error_class']);
    }

    public function testInquiryUsesInquiryCredentialsAndRetriesTransportFailures()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->queue = [
            ['throw' => new SoapFault('HTTP', 'connection timed out')],
            [
                'return' => (object) [
                    'BillPaymentInquiryResult' => '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
                ],
                'lastResponse' => $this->fixture('bill-payment-inquiry/paid-exact.xml'),
            ],
        ];

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $client->billPaymentInquiry(['RegistrationNumber' => 'REG-1']);

        $this->assertSame(2, $outcome['attempts']);
        $this->assertCount(2, $fake->calls);
        $params = $fake->calls[0][1][0];
        $this->assertSame('inquiry-user', $params['userName']);
        $this->assertSame('inquiry-secret', $params['password']);
        $this->assertSame('KP01', $params['InstitutionID']);
        $this->assertTrue($outcome['ok']);
        $this->assertNull($outcome['error_class']);
        $this->assertSame(
            '00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID',
            $outcome['raw_result']
        );
    }

    public function testInquiryFallsBackToVoucherCredentialsWhenConfigured()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->return = (object) ['BillPaymentInquiryResult' => '00,REG-1'];
        $fake->lastResponse = $this->fixture('bill-payment-inquiry/paid-exact.xml');

        $client = new KuickPaySoapClient($this->config(['inquiry_same_as_voucher' => 'true']), function () use ($fake) {
            return $fake;
        });

        $client->billPaymentInquiry(['RegistrationNumber' => 'REG-1']);

        $params = $fake->calls[0][1][0];
        $this->assertSame('voucher-user', $params['userName']);
        $this->assertSame('voucher-secret', $params['password']);
    }

    public function testBulkInquiryRetriesOnlyToBoundedLimitAndPassesRawXml()
    {
        $fake = new KuickPaySoapClientFake();
        $rawBulk = '<NewDataSet><Table><Consumer_Number>KP0100011</Consumer_Number></Table></NewDataSet>';
        $fake->return = (object) ['BillPaymentBulkInquiryResult' => $rawBulk];
        $fake->lastResponse = $this->fixture('bill-payment-bulk-inquiry/matched-paid.xml');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $client->billPaymentBulkInquiry(['TransactionDate' => '20260609']);

        $this->assertSame(1, $outcome['attempts']);
        $this->assertSame($rawBulk, $outcome['raw_result']);
        $this->assertArrayNotHasKey('Consumer_Number', $outcome);
    }

    public function testInquiryGivesUpAfterBoundedTransportRetries()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->throw = new SoapFault('HTTP', 'connection timed out');

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $outcome = $client->billPaymentInquiry(['RegistrationNumber' => 'REG-1']);

        $this->assertSame(3, $outcome['attempts']);
        $this->assertCount(3, $fake->calls);
        $this->assertFalse($outcome['ok']);
        $this->assertSame('timeout', $outcome['error_class']);
    }

    public function testLoggerCapturesCanonicalShapeForEachRetryAttempt()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->queue = [
            ['throw' => new SoapFault('HTTP', 'connection timed out')],
            ['throw' => new SoapFault('HTTP', 'connection timed out')],
            [
                'return' => (object) ['BillPaymentInquiryResult' => '00,REG-0000001,20260609,1000.00'],
                'lastResponse' => $this->fixture('bill-payment-inquiry/paid-exact.xml'),
            ],
        ];
        $logs = [];

        $client = new KuickPaySoapClient(
            $this->config(),
            function () use ($fake) {
                return $fake;
            },
            function (array $fields, bool $ok) use (&$logs) {
                $logs[] = ['fields' => $fields, 'ok' => $ok];
            }
        );

        $outcome = $client->billPaymentInquiry(['RegistrationNumber' => 'REG-1']);

        $this->assertTrue($outcome['ok']);
        $this->assertCount(3, $logs);
        $this->assertSame([1, 2, 3], [
            $logs[0]['fields']['attempt'],
            $logs[1]['fields']['attempt'],
            $logs[2]['fields']['attempt'],
        ]);
        $this->assertFalse($logs[0]['ok']);
        $this->assertTrue($logs[2]['ok']);
        $this->assertSame('BillPaymentInquiry', $logs[2]['fields']['operation']);
        $this->assertSame('00', $logs[2]['fields']['response_summary']['result_code']);
        $this->assertArrayNotHasKey('raw_result', $logs[2]['fields']);
        $this->assertArrayNotHasKey('raw_envelope', $logs[2]['fields']);
        $this->assertArrayNotHasKey('raw_result', $logs[2]['fields']['response_summary']);
        $this->assertStringNotContainsString('Envelope', json_encode($logs));
        $this->assertStringNotContainsString('inquiry-secret', json_encode($logs));
    }

    public function testSafeSetupOperationsUseTransportOutcome()
    {
        $fake = new KuickPaySoapClientFake();
        $fake->return = (object) ['EchoResult' => 'pong'];

        $client = new KuickPaySoapClient($this->config(), function () use ($fake) {
            return $fake;
        });

        $echo = $client->echoTest(['message' => 'ping']);
        $institutions = $client->getInstitutionsList([]);

        $this->assertSame('Echo', $fake->calls[0][0]);
        $this->assertSame('GetInstitutionsList', $fake->calls[1][0]);
        $this->assertTrue($echo['ok']);
        $this->assertSame('pong', $echo['raw_result']);
        $this->assertSame('GetInstitutionsList', $institutions['operation']);
    }

    public function testIsTimeoutClassifiesLocaleIndependentlyByDuration()
    {
        // AC4 (5.4): classification must not depend solely on substring-matching
        // localized exception text. A transport that ran to ~the configured ceiling is
        // a timeout regardless of how the OS localized the message; a fast-failing
        // transport error is not; PHP-internal English markers remain a fallback.
        $client = new KuickPaySoapClient($this->config(['soap_timeout' => '30']), function () {
            return new KuickPaySoapClientFake();
        });

        // German "connection timed out" defeats the English substring match, but the
        // ~ceiling duration (>= 90% of 30s) still classifies it as a timeout.
        $localized = new SoapFault('HTTP', 'Verbindung wegen Zeitueberschreitung fehlgeschlagen');
        $this->assertTrue($this->invokeIsTimeout($client, $localized, 30000));

        // A genuine non-timeout transport error that failed fast stays transport_error.
        $refused = new SoapFault('HTTP', 'Verbindung abgelehnt');
        $this->assertFalse($this->invokeIsTimeout($client, $refused, 50));

        // Backward-compatible secondary signal: English markers still classify a
        // timeout when the duration is inconclusive (none threaded).
        $english = new SoapFault('HTTP', 'connection timed out');
        $this->assertTrue($this->invokeIsTimeout($client, $english, null));
    }

    private function invokeIsTimeout(KuickPaySoapClient $client, Throwable $e, ?int $elapsedMs): bool
    {
        $method = new ReflectionMethod($client, 'isTimeout');
        $method->setAccessible(true);

        return $method->invoke($client, $e, $elapsedMs);
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
