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

if (!class_exists('Configure')) {
    class Configure
    {
        public static function get($key)
        {
            return $key === 'Blesta.company_id' ? 1 : null;
        }
    }
}

if (!class_exists('Kuickpay')) {
    require_once __DIR__ . '/../kuickpay.php';
}

class KuickPayVoucherGatewayHelpers extends Kuickpay
{
    public $logs = [];

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

    public function exposeBuildVoucherReferenceContext(
        array $contact_info,
        $amount,
        array $invoice_amounts,
        array $meta
    ): array {
        return $this->buildVoucherReferenceContext($contact_info, $amount, $invoice_amounts, $meta);
    }

    public function exposeRecordReferenceGenerationFailure($service, array $invoice_amounts, array $meta): void
    {
        $this->recordReferenceGenerationFailure($service, $invoice_amounts, $meta);
    }

    protected function log($url, $data = null, $direction = 'input', $success = false)
    {
        $this->logs[] = compact('url', 'data', 'direction', 'success');

        return 'testlog1';
    }
}

class KuickPayVoucherGatewayFailureService
{
    private $lastError;

    public function __construct($lastError)
    {
        $this->lastError = $lastError;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
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

    public function testVoucherReferenceContextIncludesConfiguredPatterns()
    {
        $gateway = $this->gateway();
        $gateway->setCurrency('PKR');
        $context = $gateway->exposeBuildVoucherReferenceContext(
            ['client_id' => 3],
            '1,500',
            [['id' => 55, 'amount' => '1,500']],
            [
                'institution_id' => 'KP',
                'registration_number_pattern' => '{random_prefix}{invoice_id}',
                'consumer_number_pattern' => '{institution_id}{registration_number}',
                'due_date_offset_days' => '3',
                'expiry_date_offset_days' => '7',
            ]
        );

        $this->assertSame('{random_prefix}{invoice_id}', $context['registration_number_pattern']);
        $this->assertSame('{institution_id}{registration_number}', $context['consumer_number_pattern']);
        $this->assertSame([['id' => 55, 'amount' => '1500.00']], $context['invoice_amounts']);
        $this->assertSame('1500.00', $context['amount']);
    }

    public function testReferenceGenerationFailureLogsSanitizedPayload()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFailureService('invalid_registration_pattern');

        $gateway->exposeRecordReferenceGenerationFailure(
            $service,
            [['id' => 55, 'amount' => '1500.00']],
            ['logging_enabled' => 'true']
        );

        $this->assertCount(1, $gateway->logs);
        $this->assertSame('kuickpay:reference_generation', $gateway->logs[0]['url']);
        $this->assertSame('output', $gateway->logs[0]['direction']);
        $this->assertFalse($gateway->logs[0]['success']);

        $payload = json_decode($gateway->logs[0]['data'], true);
        $this->assertSame('reference_generation_failed', $payload['event']);
        $this->assertSame('invalid_registration_pattern', $payload['reason']);
        $this->assertSame(55, $payload['invoice']);
        $this->assertArrayNotHasKey('pattern', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('registration_number', $payload);
        $this->assertArrayNotHasKey('consumer_number', $payload);
    }

    public function testReferenceGenerationFailureDoesNotLogBenignOrDisabledCases()
    {
        $gateway = $this->gateway();

        $gateway->exposeRecordReferenceGenerationFailure(
            new KuickPayVoucherGatewayFailureService(null),
            [['id' => 55, 'amount' => '1500.00']],
            ['logging_enabled' => 'true']
        );
        $gateway->exposeRecordReferenceGenerationFailure(
            new KuickPayVoucherGatewayFailureService('invalid_registration_pattern'),
            [['id' => 55, 'amount' => '1500.00']],
            ['logging_enabled' => 'false']
        );

        $this->assertSame([], $gateway->logs);
    }

    public function testReferencePatternLanguageNotesDocumentLiveTokens()
    {
        $lang = [];
        require __DIR__ . '/../language/en_us/kuickpay.php';

        $this->assertStringContainsString('{random_prefix}', $lang['Kuickpay.registration_number_pattern_note']);
        $this->assertStringContainsString('{invoice_id}', $lang['Kuickpay.registration_number_pattern_note']);
        $this->assertStringContainsString('{institution_id}', $lang['Kuickpay.consumer_number_pattern_note']);
        $this->assertStringContainsString('{registration_number}', $lang['Kuickpay.consumer_number_pattern_note']);
        $this->assertStringNotContainsString('later workflow', $lang['Kuickpay.registration_number_pattern_note']);
        $this->assertStringNotContainsString('later workflow', $lang['Kuickpay.consumer_number_pattern_note']);
    }

    private function gateway()
    {
        $reflection = new ReflectionClass(KuickPayVoucherGatewayHelpers::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
