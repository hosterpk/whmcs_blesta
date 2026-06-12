<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('AppModel', false)) {
    class AppModel
    {
    }
}

require_once __DIR__ . '/../kuickpay_reconcile_model.php';
require_once __DIR__ . '/../models/kuickpay_vouchers.php';

class KuickPayVouchersAmountFilterTest extends TestCase
{
    /**
     * @dataProvider amountNormalizationProvider
     */
    public function testNormalizeAmountFilterToleratesOnlyTrailingZeroExtraDecimals(string $input, string $expected)
    {
        $method = new ReflectionMethod(KuickpayVouchers::class, 'normalizeAmountFilter');
        $method->setAccessible(true);
        $model = (new ReflectionClass(KuickpayVouchers::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $method->invoke($model, $input));
    }

    public function amountNormalizationProvider(): array
    {
        return [
            ['100', '100.00'],
            ['001,000.5', '1000.50'],
            ['100.0000', '100.00'],
            ['100.0100', '100.01'],
            ['100.009', '100.009'],
            ['100.0010', '100.0010'],
        ];
    }
}
