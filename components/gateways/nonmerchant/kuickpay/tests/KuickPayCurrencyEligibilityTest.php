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

class KuickPayCurrencyEligibilityGateway extends Kuickpay
{
    public $configuredCurrencies = ['PKR'];

    // Load-bearing: this self-contained harness stubs NonmerchantGateway as an
    // empty class, so the framework's config-backed Gateway::getCurrencies()
    // (which reads config.json via loadConfig()) is unavailable here. This
    // override supplies the configured currencies the guard reads. Do not
    // remove it, or getCurrencies() becomes undefined and every case fails
    // closed. testConfigDeclaresOnlyPkr() separately locks config.json's value.
    public function getCurrencies()
    {
        return $this->configuredCurrencies;
    }

    public function isCurrencyEligible()
    {
        return $this->currencyEligible();
    }
}

class KuickPayCurrencyEligibilityTest extends TestCase
{
    public function testConfiguredCurrencyIsEligible()
    {
        $gateway = $this->gateway();
        $gateway->setCurrency('PKR');

        $this->assertTrue($gateway->isCurrencyEligible());
    }

    public function testNonConfiguredCurrencyIsIneligible()
    {
        $gateway = $this->gateway();
        $gateway->setCurrency('USD');

        $this->assertFalse($gateway->isCurrencyEligible());
    }

    public function testMissingCurrencyIsIneligible()
    {
        $gateway = $this->gateway();

        $this->assertFalse($gateway->isCurrencyEligible());

        $gateway->setCurrency(null);

        $this->assertFalse($gateway->isCurrencyEligible());
    }

    public function testEmptyConfiguredCurrenciesFailClosed()
    {
        $gateway = $this->gateway();
        $gateway->configuredCurrencies = [];
        $gateway->setCurrency('PKR');

        $this->assertFalse($gateway->isCurrencyEligible());
    }

    public function testConfigDeclaresOnlyPkr()
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);

        $this->assertSame(['PKR'], $config['currencies']);
    }

    private function gateway()
    {
        $reflection = new ReflectionClass(KuickPayCurrencyEligibilityGateway::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
