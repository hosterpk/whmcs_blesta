<?php

use PHPUnit\Framework\TestCase;

// Load the REAL framework base Gateway (not the empty NonmerchantGateway stub
// the other suites use), so the inherited config-backed currency wiring is
// exercised end to end. loadConfig()/getCurrencies() are pure (file read +
// property access) and do not touch Loader/Configure, so the base loads
// standalone in this component-local harness now that Story 5.1 stood up the
// live stack. The Container trait is required first because Gateway `use`s it.
require_once __DIR__ . '/../../../../../core/Util/Common/Traits/Container.php';
require_once __DIR__ . '/../../../lib/gateway.php';

/**
 * Minimal concrete subclass of the REAL framework Gateway base, implementing
 * only its abstract methods so the inherited
 * loadConfig() -> $this->config->currencies -> getCurrencies() path can be
 * exercised against the real config.json. Kuickpay inherits getCurrencies()
 * unchanged (asserted below), so this proves the production currency wiring
 * Kuickpay actually relies on — not the overridden stub the eligibility-guard
 * suite uses.
 */
class KuickPayRealGatewayConfigProbe extends Gateway
{
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function getSettings(array $meta = null)
    {
        return '';
    }

    public function editSettings(array $meta = null)
    {
        return '';
    }

    public function encryptableFields()
    {
        return [];
    }

    public function setMeta(array $meta = null)
    {
    }

    public function loadKuickpayConfig(): void
    {
        $this->loadConfig(__DIR__ . '/../config.json');
    }
}

class KuickPayCurrencyWiringTest extends TestCase
{
    public function testRealGatewayLoadConfigExposesPkrFromConfigJson()
    {
        // AC3c (Story 5.5): the inherited, config-backed getCurrencies() — NOT a
        // test override — returns exactly the config.json declaration after the
        // real Gateway::loadConfig() reads it.
        $gateway = new KuickPayRealGatewayConfigProbe();
        $gateway->loadKuickpayConfig();

        $this->assertSame(['PKR'], $gateway->getCurrencies());
    }

    public function testKuickpayDoesNotOverrideInheritedGetCurrencies()
    {
        // Kuickpay must rely on the framework's config-backed getCurrencies()
        // (above), not a hand-rolled override, so its supported currencies
        // always track config.json.
        $source = (string) file_get_contents(__DIR__ . '/../kuickpay.php');

        $this->assertStringNotContainsString('function getCurrencies', $source);
    }
}
