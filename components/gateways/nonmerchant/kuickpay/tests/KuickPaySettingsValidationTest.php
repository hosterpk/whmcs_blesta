<?php

use PHPUnit\Framework\TestCase;

// The component bootstrap loads only lib/* classes; the gateway base and Language
// are stubbed inline (shared with the other suites via the class_exists guards).
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

// Load the REAL Blesta Input (Minphp\Input\Input, pure validation, no DB/Loader)
// so editSettings() field rules are exercised end to end -- the connection-probe
// suite's no-op Input fake cannot evaluate setRules()/validates(). Mirrors how
// KuickPayCurrencyWiringTest loads the real Gateway base.
if (!class_exists('Minphp\\Input\\Input')) {
    require_once __DIR__ . '/../../../../../vendors/minphp/input/src/Input.php';
}
if (!class_exists('Input')) {
    require_once __DIR__ . '/../../../../../vendors/minphp/bridge/src/Components/Input.php';
}

/**
 * Gateway subclass that overrides the DNS seam so the save-time host rule can be
 * driven without real resolution (matching KuickPayConnectionProbeGateway).
 */
class KuickPaySettingsValidationGateway extends Kuickpay
{
    /** @var array Addresses resolveProbeAddresses() returns for a named host. */
    public $resolved = ['93.184.216.34'];

    public $probeCalls = [];

    protected function executeConnectionProbe($url, array $options)
    {
        $this->probeCalls[] = ['url' => $url, 'options' => $options];
        return ['errno' => 0, 'response_code' => 200];
    }

    protected function resolveProbeAddresses($host)
    {
        return $this->resolved;
    }
}

class KuickPaySettingsValidationTest extends TestCase
{
    // --- Pure validator unit tests (no framework, no DNS) -------------------

    public function testWsdlUrlSafetyRejectsUserinfo()
    {
        $result = Kuickpay::wsdlUrlSafety('https://user:pass@kuickpay.example/wsdl', []);

        $this->assertFalse($result['safe']);
        $this->assertSame('userinfo', $result['reason']);
    }

    public function testWsdlUrlSafetyRejectsNonHttpsAndMalformed()
    {
        $this->assertSame('format', Kuickpay::wsdlUrlSafety('http://kuickpay.example/wsdl', [])['reason']);
        $this->assertSame('format', Kuickpay::wsdlUrlSafety('not-a-url', [])['reason']);
        $this->assertSame('format', Kuickpay::wsdlUrlSafety('', [])['reason']);
    }

    /**
     * @dataProvider privateLiteralHostProvider
     */
    public function testWsdlUrlSafetyRejectsPrivateLiteralHosts($url)
    {
        $result = Kuickpay::wsdlUrlSafety($url, []);

        $this->assertFalse($result['safe']);
        $this->assertSame('private', $result['reason']);
    }

    public function privateLiteralHostProvider()
    {
        return [
            'loopback v4' => ['https://127.0.0.1/wsdl'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'private v4' => ['https://10.0.0.5/wsdl'],
            'loopback v6' => ['https://[::1]/wsdl'],
            'ula v6' => ['https://[fc00::1]/wsdl'],
            'ipv4-mapped v6' => ['https://[::ffff:10.0.0.1]/wsdl'],
        ];
    }

    public function testWsdlUrlSafetyAllowsPublicHostWhenAllowlistEmpty()
    {
        $result = Kuickpay::wsdlUrlSafety('https://kuickpay.example/api.asmx?WSDL', []);

        $this->assertTrue($result['safe']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame('kuickpay.example', $result['host']);
    }

    public function testWsdlUrlSafetyEnforcesPopulatedAllowlistCaseInsensitively()
    {
        $allowed = ['kuickpay.example'];

        $match = Kuickpay::wsdlUrlSafety('https://KUICKPAY.EXAMPLE/wsdl', $allowed);
        $this->assertTrue($match['safe']);

        $miss = Kuickpay::wsdlUrlSafety('https://other.example/wsdl', $allowed);
        $this->assertFalse($miss['safe']);
        $this->assertSame('host', $miss['reason']);
    }

    public function testParseAllowedHostsNormalizesSplitsAndDedupes()
    {
        $this->assertSame(
            ['a.example', 'b.example', 'c.example'],
            Kuickpay::parseAllowedHosts("A.example, b.example\nc.example , a.example")
        );
        $this->assertSame([], Kuickpay::parseAllowedHosts(''));
        $this->assertSame([], Kuickpay::parseAllowedHosts('   '));
    }

    public function testIsPlausibleHostAcceptsNamesAndIpsRejectsGarbage()
    {
        $this->assertTrue(Kuickpay::isPlausibleHost('kuickpay.example'));
        $this->assertTrue(Kuickpay::isPlausibleHost('10.0.0.1'));
        $this->assertTrue(Kuickpay::isPlausibleHost('2001:db8::1'));
        $this->assertFalse(Kuickpay::isPlausibleHost('http://kuickpay.example/x'));
        $this->assertFalse(Kuickpay::isPlausibleHost('bad host'));
        $this->assertFalse(Kuickpay::isPlausibleHost(''));
    }

    /**
     * @dataProvider soapTimeoutProvider
     */
    public function testSoapTimeoutInRange($value, $expected)
    {
        $this->assertSame($expected, Kuickpay::soapTimeoutInRange($value));
    }

    public function soapTimeoutProvider()
    {
        return [
            'zero rejected' => ['0', false],
            'leading zero rejected' => ['007', false],
            'over max rejected' => ['301', false],
            'non-numeric rejected' => ['abc', false],
            'min ok' => ['1', true],
            'typical ok' => ['30', true],
            'max ok' => ['300', true],
        ];
    }

    /**
     * @dataProvider offsetProvider
     */
    public function testOffsetDaysInRange($value, $expected)
    {
        $this->assertSame($expected, Kuickpay::offsetDaysInRange($value));
    }

    public function offsetProvider()
    {
        return [
            'zero allowed' => ['0', true],
            'leading zero rejected' => ['007', false],
            'double zero rejected' => ['00', false],
            'over max rejected' => ['366', false],
            'negative rejected' => ['-1', false],
            'typical ok' => ['5', true],
            'max ok' => ['365', true],
        ];
    }

    /**
     * @dataProvider expiryDueProvider
     */
    public function testExpiryNotBeforeDue($expiry, $due, $expected)
    {
        $this->assertSame($expected, Kuickpay::expiryNotBeforeDue($expiry, $due));
    }

    public function expiryDueProvider()
    {
        return [
            'equal ok' => ['5', '5', true],
            'after ok' => ['6', '5', true],
            'before rejected' => ['4', '5', false],
            'expiry unset ok' => ['', '5', true],
            'due unset ok' => ['5', '', true],
            'malformed ok (range rule handles it)' => ['abc', '5', true],
        ];
    }

    // --- End-to-end editSettings() against the real Input -------------------

    public function testValidSettingsValidateClean()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta());

        $this->assertFalse($gateway->Input->errors());
    }

    public function testUserinfoUrlRejectedAtSave()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta(['wsdl_url' => 'https://user:pass@kuickpay.example/wsdl']));

        $this->assertArrayHasKey('userinfo', $gateway->Input->errors()['wsdl_url']);
    }

    /**
     * @dataProvider privateSaveHostProvider
     */
    public function testPrivateLiteralHostRejectedAtSave($url)
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta(['wsdl_url' => $url]));

        $this->assertArrayHasKey('host', $gateway->Input->errors()['wsdl_url']);
    }

    public function privateSaveHostProvider()
    {
        return [
            'loopback' => ['https://127.0.0.1/wsdl'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback v6' => ['https://[::1]/wsdl'],
        ];
    }

    public function testNamedHostResolvingToPrivateRejectedAtSave()
    {
        $gateway = $this->gateway(['10.0.0.5']);

        $gateway->editSettings($this->meta(['wsdl_url' => 'https://internal.example/wsdl']));

        $this->assertArrayHasKey('host', $gateway->Input->errors()['wsdl_url']);
    }

    public function testUnresolvableNamedHostRejectedAtSave()
    {
        $gateway = $this->gateway([]);

        $gateway->editSettings($this->meta(['wsdl_url' => 'https://nx.example/wsdl']));

        $this->assertArrayHasKey('host', $gateway->Input->errors()['wsdl_url']);
    }

    public function testPopulatedAllowlistMatchingHostPasses()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta([
            'wsdl_url' => 'https://kuickpay.example/api.asmx?WSDL',
            'wsdl_allowed_hosts' => "kuickpay.example\nbackup.kuickpay.example",
        ]));

        $this->assertFalse($gateway->Input->errors());
    }

    public function testPopulatedAllowlistNonMatchingHostRejected()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta([
            'wsdl_url' => 'https://kuickpay.example/api.asmx?WSDL',
            'wsdl_allowed_hosts' => 'allowed.example',
        ]));

        $this->assertArrayHasKey('host', $gateway->Input->errors()['wsdl_url']);
    }

    public function testEmptyAllowlistFallsBackToPublicHostChecks()
    {
        $gateway = $this->gateway();

        // No wsdl_allowed_hosts set; a public, resolvable host still saves.
        $gateway->editSettings($this->meta(['wsdl_url' => 'https://kuickpay.example/api.asmx?WSDL']));

        $this->assertFalse($gateway->Input->errors());
    }

    public function testMalformedAllowlistEntryRejected()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta(['wsdl_allowed_hosts' => 'http://evil.example/x']));

        $this->assertArrayHasKey('valid', $gateway->Input->errors()['wsdl_allowed_hosts']);
    }

    /**
     * @dataProvider soapTimeoutSaveProvider
     */
    public function testSoapTimeoutBoundsAtSave($value, $expectError)
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta(['soap_timeout' => $value]));

        $errors = $gateway->Input->errors();
        if ($expectError) {
            $this->assertArrayHasKey('range', $errors['soap_timeout']);
        } else {
            $this->assertFalse($errors);
        }
    }

    public function soapTimeoutSaveProvider()
    {
        return [
            'zero rejected' => ['0', true],
            'leading zero rejected' => ['007', true],
            'over max rejected' => ['301', true],
            'valid passes' => ['30', false],
        ];
    }

    public function testOffsetBoundsAndLeadingZeroAtSave()
    {
        $gateway = $this->gateway();
        $gateway->editSettings($this->meta(['due_date_offset_days' => '007']));
        $this->assertArrayHasKey('range', $gateway->Input->errors()['due_date_offset_days']);

        $overMax = $this->gateway();
        $overMax->editSettings($this->meta(['expiry_date_offset_days' => '400']));
        $this->assertArrayHasKey('range', $overMax->Input->errors()['expiry_date_offset_days']);

        $zeroOk = $this->gateway();
        $zeroOk->editSettings($this->meta(['due_date_offset_days' => '0', 'expiry_date_offset_days' => '0']));
        $this->assertFalse($zeroOk->Input->errors());
    }

    public function testExpiryBeforeDueRejectedAtSave()
    {
        $gateway = $this->gateway();

        $gateway->editSettings($this->meta([
            'due_date_offset_days' => '10',
            'expiry_date_offset_days' => '5',
        ]));

        $this->assertArrayHasKey('before_due', $gateway->Input->errors()['expiry_date_offset_days']);
    }

    public function testExpiryEqualOrAfterDuePasses()
    {
        $equal = $this->gateway();
        $equal->editSettings($this->meta(['due_date_offset_days' => '5', 'expiry_date_offset_days' => '5']));
        $this->assertFalse($equal->Input->errors());

        $after = $this->gateway();
        $after->editSettings($this->meta(['due_date_offset_days' => '5', 'expiry_date_offset_days' => '6']));
        $this->assertFalse($after->Input->errors());
    }

    public function testUnsetNumericFieldsPass()
    {
        $gateway = $this->gateway();

        // Baseline meta omits soap_timeout and both offsets entirely.
        $gateway->editSettings($this->meta());

        $this->assertFalse($gateway->Input->errors());
    }

    public function testInvalidSettingsDoNotRunConnectionProbe()
    {
        $input = new Input();
        $reflection = new ReflectionClass(KuickPaySettingsValidationGateway::class);
        $gateway = $reflection->newInstanceWithoutConstructor();
        $gateway->Input = $input;

        $gateway->editSettings($this->meta([
            'soap_timeout' => '301',
            'run_connection_test' => 'true',
        ]));

        $this->assertSame([], $gateway->probeCalls);
        $this->assertArrayHasKey('range', $gateway->Input->errors()['soap_timeout']);
    }

    // --- helpers ------------------------------------------------------------

    private function gateway(array $resolved = null)
    {
        $reflection = new ReflectionClass(KuickPaySettingsValidationGateway::class);
        $gateway = $reflection->newInstanceWithoutConstructor();
        $gateway->Input = new Input();
        if ($resolved !== null) {
            $gateway->resolved = $resolved;
        }

        return $gateway;
    }

    private function meta(array $overrides = [])
    {
        return array_merge([
            'wsdl_url' => 'https://kuickpay.example/api.asmx?WSDL',
            'voucher_username' => 'voucher-user',
            'voucher_password' => 'voucher-secret',
            'inquiry_same_as_voucher' => 'true',
            'institution_id' => 'KP01',
            'registration_number_pattern' => 'REG{invoice}',
            'consumer_number_pattern' => 'CON{invoice}',
            'currency_policy' => 'pkr_only',
            'fee_policy' => 'none',
        ], $overrides);
    }
}
