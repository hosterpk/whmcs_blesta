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

    public function exposeBuildVoucherRequest(array $voucher, array $contactData, array $meta): array
    {
        return $this->buildVoucherRequest($voucher, $contactData, $meta);
    }

    public function exposeNormalizePkMobile(string $raw): ?string
    {
        return $this->normalizePkMobile($raw);
    }

    public function exposeFormatVoucherDate(string $ymdDate): string
    {
        return $this->formatVoucherDate($ymdDate);
    }

    public function exposeBuildVoucherContactData(array $contactInfo): array
    {
        return $this->buildVoucherContactData($contactInfo);
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

class KuickPayVoucherGatewayFakeContacts
{
    public function get(int $contact_id)
    {
        return (object) ['email' => 'ali@example.test'];
    }

    public function getNumbers(int $contact_id, string $type, string $location)
    {
        return [(object) ['number' => '+923001234567']];
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

    public function testBuildVoucherRequestMapsInvoiceContactAndConfiguredPolicies()
    {
        $gateway = $this->gateway();
        $request = $gateway->exposeBuildVoucherRequest(
            [
                'registration_number' => 'REG55',
                'amount' => '001,500.5',
                'date_due' => '2026-06-13',
                'date_expires' => '2026-06-17',
            ],
            [
                'first_name' => 'Ali',
                'last_name' => 'Khan',
                'company' => '',
                'mobile' => '+92 300-1234567',
                'email' => 'ali@example.test',
                'branch' => 'SD',
            ],
            [
                'payment_head_label' => 'Hosting invoice',
                'fallback_mobile' => '03123456789',
                'fallback_email' => 'fallback@example.test',
                'default_branch' => 'PB',
                'institution_id' => 'SHOULD_NOT_LEAK',
                'voucher_username' => 'SHOULD_NOT_LEAK',
                'voucher_password' => 'SHOULD_NOT_LEAK',
            ]
        );

        $this->assertSame('REG55', $request['RegistrationNumber']);
        $this->assertSame('Hosting invoice', $request['Head1']);
        $this->assertSame('1500.50', $request['Amount1']);
        $this->assertSame('1500.50', $request['TotalAmount']);
        $this->assertSame('13-Jun-26', $request['DueDate']);
        $this->assertSame('17-Jun-26', $request['ExpiryDate']);
        $this->assertSame(date('d-M-y'), $request['IssueDate']);
        $this->assertSame('06', $request['VoucherMonth']);
        $this->assertSame('2026', $request['VoucherYear']);
        $this->assertSame('Ali Khan', $request['Name']);
        $this->assertSame('03001234567', $request['Mobile']);
        $this->assertSame('ali@example.test', $request['Email']);
        $this->assertSame('SD', $request['Branch']);
        $this->assertSame('', $request['Head2']);
        $this->assertSame('0', $request['Amount2']);
        $this->assertArrayNotHasKey('userName', $request);
        $this->assertArrayNotHasKey('password', $request);
        $this->assertArrayNotHasKey('InstitutionID', $request);
    }

    public function testBuildVoucherRequestAppliesMobileEmailAndBranchFallbacks()
    {
        $gateway = $this->gateway();
        $request = $gateway->exposeBuildVoucherRequest(
            [
                'registration_number' => 'REG55',
                'amount' => '1500.00',
                'date_due' => '2026-06-13',
                'date_expires' => '2026-06-17',
            ],
            [
                'first_name' => 'Ali',
                'last_name' => 'Khan',
                'company' => 'Example Co',
                'mobile' => '555',
                'email' => '',
                'branch' => '',
            ],
            [
                'payment_head_label' => '',
                'fallback_mobile' => '00923001234567',
                'fallback_email' => 'fallback@example.test',
                'default_branch' => 'PB',
            ]
        );

        $this->assertSame('Invoice Payment', $request['Head1']);
        $this->assertSame('Example Co', $request['Name']);
        $this->assertSame('03001234567', $request['Mobile']);
        $this->assertSame('fallback@example.test', $request['Email']);
        $this->assertSame('PB', $request['Branch']);
    }

    /**
     * @dataProvider pkMobileProvider
     */
    public function testNormalizePkMobileAcceptsDocumentedShapes($input, $expected)
    {
        $gateway = $this->gateway();

        $this->assertSame($expected, $gateway->exposeNormalizePkMobile($input));
    }

    public function pkMobileProvider()
    {
        return [
            ['03001234567', '03001234567'],
            ['+923001234567', '03001234567'],
            ['00923001234567', '03001234567'],
            ['923001234567', '03001234567'],
            ['+92 300-123-4567', '03001234567'],
            ['02134567890', null],
            ['+12025550123', null],
            ['', null],
        ];
    }

    public function testFormatVoucherDateCentralizesKuickpayDateFormat()
    {
        $gateway = $this->gateway();

        $this->assertSame('13-Jun-26', $gateway->exposeFormatVoucherDate('2026-06-13'));
    }

    public function testBuildVoucherContactDataLoadsEmailMobileAndBranch()
    {
        $gateway = $this->gateway();
        $gateway->Contacts = new KuickPayVoucherGatewayFakeContacts();

        $contactData = $gateway->exposeBuildVoucherContactData([
            'id' => 9,
            'first_name' => 'Ali',
            'last_name' => 'Khan',
            'company' => 'Example Co',
            'state' => ['code' => 'SD', 'name' => 'Sindh'],
        ]);

        $this->assertSame('Ali', $contactData['first_name']);
        $this->assertSame('Khan', $contactData['last_name']);
        $this->assertSame('Example Co', $contactData['company']);
        $this->assertSame('ali@example.test', $contactData['email']);
        $this->assertSame('+923001234567', $contactData['mobile']);
        $this->assertSame('SD', $contactData['branch']);
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

    public function testVoucherCreationFallbackSettingsHaveLanguageKeys()
    {
        $lang = [];
        require __DIR__ . '/../language/en_us/kuickpay.php';

        $this->assertSame('Fallback email', $lang['Kuickpay.fallback_email']);
        $this->assertSame('Default branch', $lang['Kuickpay.default_branch']);
        $this->assertArrayHasKey('Kuickpay.fallback_email_note', $lang);
        $this->assertArrayHasKey('Kuickpay.default_branch_note', $lang);
    }

    private function gateway()
    {
        $reflection = new ReflectionClass(KuickPayVoucherGatewayHelpers::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
