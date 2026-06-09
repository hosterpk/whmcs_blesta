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

if (!class_exists('Kuickpay')) {
    require_once __DIR__ . '/../kuickpay.php';
}

class KuickPayVoucherGatewayHelpers extends Kuickpay
{
    public function exposeNormalizeAmount(string $amount): string
    {
        return $this->normalizeAmount($amount);
    }

    public function exposeNormalizeInvoiceAmounts(array $invoice_amounts): array
    {
        return $this->normalizeInvoiceAmounts($invoice_amounts);
    }

    public function exposeGatewayId()
    {
        return $this->kuickpay_gateway_id;
    }
}

class KuickPayVoucherGatewayHelpersTest extends TestCase
{
    /**
     * @dataProvider amountProvider
     */
    public function testNormalizeAmountUsesStringRules($input, $expected)
    {
        $gateway = $this->gateway();

        $this->assertSame($expected, $gateway->exposeNormalizeAmount($input));
    }

    public function amountProvider()
    {
        return [
            ['1500', '1500.00'],
            ['001,500.5', '1500.50'],
            ['0.009', '0.00'],
            [' 10.2 ', '10.20'],
            ['-10.00', '-10.00'],
            ['+10.00', '+10.00'],
            ['1e3', '1e3'],
            ['abc', 'abc'],
        ];
    }

    public function testNormalizeInvoiceAmountsMapsEachAmount()
    {
        $gateway = $this->gateway();

        $this->assertSame(
            [
                ['id' => 1, 'amount' => '1500.00'],
                ['id' => 2, 'amount' => '-5.00'],
            ],
            $gateway->exposeNormalizeInvoiceAmounts([
                ['id' => 1, 'amount' => '1,500'],
                ['id' => 2, 'amount' => '-5.00'],
            ])
        );
    }

    public function testSetGatewayIdStoresShadowValue()
    {
        $gateway = $this->gateway();
        $gateway->setGatewayId(42);

        $this->assertSame(42, $gateway->exposeGatewayId());
    }

    private function gateway()
    {
        $reflection = new ReflectionClass(KuickPayVoucherGatewayHelpers::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
