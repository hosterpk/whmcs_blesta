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
    public $fakeSoapClient;
    public $fakeIssuanceService;
    public $fakeVoucherRepository;
    public $fakeVoucherReferenceService;

    public function exposePaymentPolicyOptions(): array
    {
        return $this->paymentPolicyOptions();
    }

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

    public function exposeReloadVoucherDecision($voucher): string
    {
        return $this->reloadVoucherDecision($voucher);
    }

    public function exposeResolveDisplayMode(?array $voucher, string $decision): ?string
    {
        return $this->resolveDisplayMode($voucher, $decision);
    }

    public function exposeCustomerVoucherStatusDisplay(string $status): array
    {
        return $this->customerVoucherStatusDisplay($status);
    }

    public function exposeEnabledInstructionGroups(array $meta): array
    {
        return $this->enabledInstructionGroups($meta);
    }

    public function exposeCustomerStatusCheckSupported(): bool
    {
        return $this->customerStatusCheckSupported();
    }

    public function exposeIsBlockedMultiInvoice(array $invoiceAmounts, string $policy): bool
    {
        return $this->isBlockedMultiInvoice($invoiceAmounts, $policy);
    }

    public function exposeIssueVoucherIfNeeded(array $voucher, array $contactInfo, array $meta): ?array
    {
        return $this->issueVoucherIfNeeded($voucher, $contactInfo, $meta);
    }

    public function exposeDisplayVoucherForContext(
        $latest,
        array $context,
        array $contactInfo,
        array $meta,
        $service,
        $repository
    ): array {
        return $this->displayVoucherForContext($latest, $context, $contactInfo, $meta, $service, $repository);
    }

    public function exposeCreateVoucherForContext(
        array $context,
        array $contactInfo,
        array $meta,
        $service
    ): array {
        return $this->createVoucherForContext($context, $contactInfo, $meta, $service);
    }

    public function exposeMaskCredentials($data)
    {
        return $this->maskCredentials($data);
    }

    protected function log($url, $data = null, $direction = 'input', $success = false)
    {
        $this->logs[] = compact('url', 'data', 'direction', 'success');

        return 'testlog1';
    }

    protected function getSoapClient()
    {
        return $this->fakeSoapClient;
    }

    protected function getIssuanceService()
    {
        return $this->fakeIssuanceService;
    }

    protected function getVoucherRepository()
    {
        return $this->fakeVoucherRepository;
    }

    protected function getVoucherReferenceService()
    {
        return $this->fakeVoucherReferenceService;
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

class KuickPayVoucherGatewayFakeSoapClient
{
    public array $requests = [];
    private array $outcomes;

    public function __construct(array $outcomes = [])
    {
        $this->outcomes = $outcomes;
    }

    public function insertVoucher(array $request): array
    {
        $this->requests[] = $request;

        return array_shift($this->outcomes) ?: [
            'ok' => true,
            'operation' => 'InsertVoucher',
            'raw_result' => '00 KP-ISSUED-123',
            'error_class' => null,
            'redacted_trace_id' => 'trace123',
        ];
    }
}

class KuickPayVoucherGatewayFakeIssuanceService
{
    public array $records = [];

    public function recordIssueOutcome(int $voucherId, int $companyId, KuickPayEvidence $evidence): void
    {
        $this->records[] = compact('voucherId', 'companyId', 'evidence');
    }
}

class KuickPayVoucherGatewayFakeVoucherRepository
{
    public array $edits = [];
    public $latest;
    public $withInvoices;
    public bool $throwOnLatest = false;

    public function edit(int $voucher_id, int $company_id, array $vars): void
    {
        $this->edits[] = compact('voucher_id', 'company_id', 'vars');
    }

    public function getLatestByInvoiceId(int $invoice_id, int $company_id)
    {
        if ($this->throwOnLatest) {
            throw new RuntimeException('latest read failed');
        }

        return $this->latest;
    }

    public function getWithInvoices(int $voucher_id)
    {
        return $this->withInvoices;
    }
}

class KuickPayVoucherGatewayFakeReferenceService
{
    public bool $matches = true;
    public bool $retireResult = true;
    public ?array $createdVoucher = null;
    public array $matchCalls = [];
    public array $retireCalls = [];
    public int $createCalls = 0;
    private $lastError;

    public function __construct($lastError = null)
    {
        $this->lastError = $lastError;
    }

    public function requestMatchesVoucher(array $voucherFlat, string $contextAmount, array $contextInvoiceAmounts): bool
    {
        $this->matchCalls[] = compact('voucherFlat', 'contextAmount', 'contextInvoiceAmounts');

        return $this->matches;
    }

    public function retireVoucher(int $voucherId, int $companyId, string $reason, array $auditPayload = []): bool
    {
        $this->retireCalls[] = compact('voucherId', 'companyId', 'reason', 'auditPayload');

        return $this->retireResult;
    }

    public function getOrCreateForInvoiceContext(array $context): ?array
    {
        $this->createCalls++;

        return $this->createdVoucher;
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

    public function testVoucherReferenceContextIncludesPaymentPolicies()
    {
        $gateway = $this->gateway();
        $gateway->setCurrency('PKR');
        $context = $gateway->exposeBuildVoucherReferenceContext(
            ['client_id' => 3],
            '1,500',
            [['id' => 55, 'amount' => '1,500']],
            [
                'amount_change_policy' => 'replace',
                'multi_invoice_policy' => 'allow',
            ]
        );

        $this->assertSame('replace', $context['amount_change_policy']);
        $this->assertSame('allow', $context['multi_invoice_policy']);
    }

    public function testVoucherReferenceContextDefaultsPaymentPoliciesToBlock()
    {
        $gateway = $this->gateway();
        $gateway->setCurrency('PKR');
        $context = $gateway->exposeBuildVoucherReferenceContext(
            ['client_id' => 3],
            '1,500',
            [['id' => 55, 'amount' => '1,500']],
            []
        );

        $this->assertSame('block', $context['amount_change_policy']);
        $this->assertSame('block', $context['multi_invoice_policy']);
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
        $this->assertSame('1501', $request['Amount1']);
        $this->assertSame('1501', $request['TotalAmount']);
        $this->assertSame('13-Jun-26', $request['DueDate']);
        $this->assertSame('17-Jun-26', $request['ExpiryDate']);
        $this->assertSame(date('d-M-y'), $request['IssueDate']);
        $this->assertSame('06', $request['VoucherMonth']);
        $this->assertSame('2026', $request['VoucherYear']);
        $this->assertSame('Ali Khan', $request['Name']);
        $this->assertSame('3001234567', $request['Mobile']);
        $this->assertSame('ali@example.test', $request['Email']);
        $this->assertSame('SD', $request['Branch']);
        $this->assertSame('', $request['Head2']);
        $this->assertSame('', $request['Amount2']);
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
        $this->assertSame('3001234567', $request['Mobile']);
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
            ['03001234567', '3001234567'],
            ['+923001234567', '3001234567'],
            ['00923001234567', '3001234567'],
            ['923001234567', '3001234567'],
            ['+92 300-123-4567', '3001234567'],
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

    /**
     * @dataProvider unparseableDateProvider
     */
    public function testFormatVoucherDateFailsClosedOnUnparseableDate($input)
    {
        $gateway = $this->gateway();

        $this->assertSame('', $gateway->exposeFormatVoucherDate($input));
    }

    public function unparseableDateProvider()
    {
        return [
            'empty' => [''],
            'not a date' => ['not-a-date'],
        ];
    }

    public function testBuildVoucherRequestFailsClosedOnMissingDueDate()
    {
        $gateway = $this->gateway();
        $request = $gateway->exposeBuildVoucherRequest(
            [
                'registration_number' => 'REG55',
                'amount' => '1500.00',
                'date_due' => '',
                'date_expires' => '',
            ],
            ['first_name' => 'Ali', 'last_name' => 'Khan', 'company' => '', 'mobile' => '', 'email' => '', 'branch' => ''],
            []
        );

        $this->assertSame('', $request['DueDate']);
        $this->assertSame('', $request['ExpiryDate']);
        $this->assertSame('', $request['VoucherMonth']);
        $this->assertSame('', $request['VoucherYear']);
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

    /**
     * @dataProvider reloadDecisionProvider
     */
    public function testReloadVoucherDecisionFollowsSequentialSafetyMatrix($voucher, string $expected)
    {
        $gateway = $this->gateway();

        $this->assertSame($expected, $gateway->exposeReloadVoucherDecision($voucher));
    }

    public function reloadDecisionProvider()
    {
        return [
            'none' => [null, 'allow'],
            'pending without reference' => [(object) ['status' => 'pending', 'kuickpay_reference' => null], 'issue'],
            'pending with reference' => [(object) ['status' => 'pending', 'kuickpay_reference' => 'KP-1'], 'display'],
            'retry blocks' => [(object) ['status' => 'retry', 'error_class' => 'timeout'], 'block'],
            'manual review blocks' => [(object) ['status' => 'manual_review', 'error_class' => 'duplicate_reference'], 'block'],
            'failed credential allows' => [(object) ['status' => 'failed', 'error_class' => 'credential_error'], 'allow'],
            'expired allows' => [(object) ['status' => 'expired', 'error_class' => null], 'allow'],
            'cancelled allows' => [(object) ['status' => 'cancelled', 'error_class' => null], 'allow'],
            'posted blocks' => [(object) ['status' => 'posted', 'error_class' => null], 'block'],
            'unknown blocks' => [(object) ['status' => 'unexpected', 'error_class' => null], 'block'],
        ];
    }

    /**
     * @dataProvider displayModeProvider
     */
    public function testResolveDisplayModeFollowsResolvedVoucherAndDecision($voucher, string $decision, $expected)
    {
        $gateway = $this->gateway();

        $this->assertSame($expected, $gateway->exposeResolveDisplayMode($voucher, $decision));
    }

    public function displayModeProvider()
    {
        $voucher = ['status' => 'pending', 'kuickpay_reference' => 'KP-ISSUED-123'];

        return [
            'display with no resolved voucher is no panel' => [null, 'display', null],
            'block with resolved voucher is status only' => [$voucher, 'block', 'status_only'],
            'display with resolved voucher is payable' => [$voucher, 'display', 'payable'],
            'allow with resolved voucher is payable' => [$voucher, 'allow', 'payable'],
            'issue with resolved voucher is payable' => [$voucher, 'issue', 'payable'],
        ];
    }

    /**
     * @dataProvider customerStatusDisplayProvider
     */
    public function testCustomerVoucherStatusDisplayMapsCustomerStatusCopyAndBadges(
        string $status,
        string $expectedLabelKey,
        string $expectedBadge
    ) {
        $gateway = $this->gateway();

        $display = $gateway->exposeCustomerVoucherStatusDisplay($status);

        $this->assertSame($expectedLabelKey, $display['label_key']);
        $this->assertSame($expectedBadge, $display['badge']);
    }

    public function customerStatusDisplayProvider()
    {
        return [
            'pending' => ['pending', 'Kuickpay.process.status.pending', 'badge-info'],
            'retry' => ['retry', 'Kuickpay.process.status.retry', 'badge-info'],
            'confirmed unposted' => [
                'confirmed_unposted',
                'Kuickpay.process.status.confirmed_unposted',
                'badge-info',
            ],
            'posted' => ['posted', 'Kuickpay.process.status.posted', 'badge-success'],
            'failed' => ['failed', 'Kuickpay.process.status.failed', 'badge-info'],
            'expired' => ['expired', 'Kuickpay.process.status.expired', 'badge-secondary'],
            'manual review' => ['manual_review', 'Kuickpay.process.status.manual_review', 'badge-warning'],
            'cancelled' => ['cancelled', 'Kuickpay.process.status.cancelled', 'badge-secondary'],
            'unmapped' => ['unexpected', 'Kuickpay.process.status.unknown', 'badge-secondary'],
            'empty' => ['', 'Kuickpay.process.status.unknown', 'badge-secondary'],
        ];
    }

    /**
     * @dataProvider nonPostedCustomerStatusProvider
     */
    public function testCustomerVoucherStatusDisplayKeepsNonPostedStatesNonSuccess(string $status)
    {
        $gateway = $this->gateway();

        $display = $gateway->exposeCustomerVoucherStatusDisplay($status);

        $this->assertNotSame('badge-success', $display['badge']);
    }

    public function nonPostedCustomerStatusProvider()
    {
        return [
            ['pending'],
            ['retry'],
            ['confirmed_unposted'],
            ['failed'],
            ['expired'],
            ['manual_review'],
            ['cancelled'],
            ['unexpected'],
            [''],
        ];
    }

    /**
     * @dataProvider enabledInstructionGroupsProvider
     */
    public function testEnabledInstructionGroupsFollowConfiguredOrderAndDefaults(array $meta, array $expectedChannels)
    {
        $gateway = $this->gateway();

        $groups = $gateway->exposeEnabledInstructionGroups($meta);

        $this->assertSame($expectedChannels, array_column($groups, 'channel'));
        foreach ($groups as $group) {
            $channel = $group['channel'];
            $this->assertSame('Kuickpay.process.instruction.' . $channel . '.title', $group['title_key']);
            $this->assertSame('Kuickpay.process.instruction.' . $channel . '.body', $group['body_key']);
        }
    }

    public function enabledInstructionGroupsProvider()
    {
        return [
            'all enabled' => [
                [
                    'instruction_online_banking' => 'true',
                    'instruction_bank_deposit' => 'true',
                    'instruction_agent_franchise' => 'true',
                    'instruction_mobile_app' => 'true',
                ],
                ['online_banking', 'bank_deposit', 'agent_franchise', 'mobile_app'],
            ],
            'all disabled' => [
                [
                    'instruction_online_banking' => 'false',
                    'instruction_bank_deposit' => 'false',
                    'instruction_agent_franchise' => 'false',
                    'instruction_mobile_app' => 'false',
                ],
                [],
            ],
            'fresh install defaults' => [
                [],
                ['online_banking', 'bank_deposit'],
            ],
            'explicit mixed overrides' => [
                [
                    'instruction_online_banking' => 'false',
                    'instruction_bank_deposit' => 'true',
                    'instruction_agent_franchise' => 'true',
                    'instruction_mobile_app' => 'false',
                ],
                ['bank_deposit', 'agent_franchise'],
            ],
        ];
    }

    public function testCustomerStatusCheckSupportedIsDisabledForMvp()
    {
        $gateway = $this->gateway();

        $this->assertFalse($gateway->exposeCustomerStatusCheckSupported());
    }

    /**
     * @dataProvider multiInvoiceGateProvider
     */
    public function testMultiInvoiceGateBlocksAmbiguousAttempts(array $invoiceAmounts, string $policy, bool $expected)
    {
        $gateway = $this->gateway();

        $this->assertSame($expected, $gateway->exposeIsBlockedMultiInvoice($invoiceAmounts, $policy));
    }

    public function multiInvoiceGateProvider()
    {
        return [
            'empty block policy falls through' => [[], 'block', false],
            'single invoice block policy falls through' => [[['id' => 55, 'amount' => '1500.00']], 'block', false],
            'two distinct invoices block policy blocks' => [
                [['id' => 55, 'amount' => '1000.00'], ['id' => 56, 'amount' => '500.00']],
                'block',
                true,
            ],
            'duplicate invoice id block policy blocks' => [
                [['id' => 55, 'amount' => '1000.00'], ['id' => 55, 'amount' => '500.00']],
                'block',
                true,
            ],
            'two distinct invoices allow policy falls through' => [
                [['id' => 55, 'amount' => '1000.00'], ['id' => 56, 'amount' => '500.00']],
                'allow',
                false,
            ],
            'duplicate invoice id allow policy falls through to service dedupe' => [
                [['id' => 55, 'amount' => '1000.00'], ['id' => 55, 'amount' => '500.00']],
                'allow',
                false,
            ],
        ];
    }

    public function testIssueVoucherIfNeededDoesNotReissueAlreadyIssuedPendingVoucher()
    {
        $gateway = $this->gateway();
        $gateway->Contacts = new KuickPayVoucherGatewayFakeContacts();
        $gateway->fakeSoapClient = new KuickPayVoucherGatewayFakeSoapClient();
        $gateway->fakeIssuanceService = new KuickPayVoucherGatewayFakeIssuanceService();
        $gateway->fakeVoucherRepository = new KuickPayVoucherGatewayFakeVoucherRepository();

        $voucher = $this->voucher(['kuickpay_reference' => 'KP-ISSUED-123']);
        $result = $gateway->exposeIssueVoucherIfNeeded($voucher, $this->contactInfo(), []);

        $this->assertSame($voucher, $result);
        $this->assertSame([], $gateway->fakeSoapClient->requests);
        $this->assertSame([], $gateway->fakeIssuanceService->records);
    }

    public function testDisplayVoucherForContextShowsMatchingIssuedVoucher()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService();
        $repository = new KuickPayVoucherGatewayFakeVoucherRepository();
        $latest = (object) $this->voucher(['kuickpay_reference' => 'KP-ISSUED-123']);

        $result = $gateway->exposeDisplayVoucherForContext(
            $latest,
            $this->voucherContext(),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'block'],
            $service,
            $repository
        );

        $this->assertSame('KP-ISSUED-123', $result['voucher']['kuickpay_reference']);
        $this->assertNull($result['process_notice']);
        $this->assertCount(1, $service->matchCalls);
        $this->assertSame([], $service->retireCalls);
        $this->assertSame(0, $service->createCalls);
    }

    public function testDisplayVoucherForContextBlocksChangedAmount()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService();
        $service->matches = false;
        $repository = new KuickPayVoucherGatewayFakeVoucherRepository();

        $result = $gateway->exposeDisplayVoucherForContext(
            (object) $this->voucher(['kuickpay_reference' => 'KP-ISSUED-123']),
            $this->voucherContext(['amount' => '1200.00']),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'block'],
            $service,
            $repository
        );

        $this->assertNull($result['voucher']);
        $this->assertSame('amount_changed', $result['process_notice']);
        $this->assertSame([], $service->retireCalls);
        $this->assertSame(0, $service->createCalls);
    }

    public function testDisplayVoucherForContextRetiresAndReplacesChangedAmount()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService();
        $service->matches = false;
        $service->createdVoucher = $this->voucher([
            'id' => 26,
            'amount' => '1200.00',
            'kuickpay_reference' => 'KP-NEW-123',
        ]);
        $repository = new KuickPayVoucherGatewayFakeVoucherRepository();

        $result = $gateway->exposeDisplayVoucherForContext(
            (object) $this->voucher(['kuickpay_reference' => 'KP-OLD-123']),
            $this->voucherContext(['amount' => '1200.00']),
            $this->contactInfo(),
            ['amount_change_policy' => 'replace', 'multi_invoice_policy' => 'block'],
            $service,
            $repository
        );

        $this->assertSame('KP-NEW-123', $result['voucher']['kuickpay_reference']);
        $this->assertNull($result['process_notice']);
        $this->assertSame(1, $service->createCalls);
        $this->assertSame(25, $service->retireCalls[0]['voucherId']);
        $this->assertSame(1, $service->retireCalls[0]['companyId']);
        $this->assertSame('amount_changed', $service->retireCalls[0]['reason']);
        $this->assertSame('1500.00', $service->retireCalls[0]['auditPayload']['old_amount']);
        $this->assertSame('1200.00', $service->retireCalls[0]['auditPayload']['new_amount']);
    }

    public function testDisplayVoucherForContextLoadsLinksWhenMultiInvoiceAllowed()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService();
        $repository = new KuickPayVoucherGatewayFakeVoucherRepository();
        $latest = (object) $this->voucher(['kuickpay_reference' => 'KP-ISSUED-123']);
        $repository->withInvoices = [
            'voucher' => $latest,
            'invoices' => [
                (object) ['invoice_id' => 55, 'amount' => '1000.00'],
                (object) ['invoice_id' => 56, 'amount' => '500.00'],
            ],
        ];

        $gateway->exposeDisplayVoucherForContext(
            $latest,
            $this->voucherContext([
                'invoice_amounts' => [
                    ['id' => 55, 'amount' => '1000.00'],
                    ['id' => 56, 'amount' => '500.00'],
                ],
            ]),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'allow'],
            $service,
            $repository
        );

        $this->assertSame($repository->withInvoices['invoices'], $service->matchCalls[0]['voucherFlat']['invoices']);
    }

    public function testCreateVoucherForContextMapsServiceAmountChangedToNotice()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService('amount_changed');

        $result = $gateway->exposeCreateVoucherForContext(
            $this->voucherContext(['amount' => '1200.00']),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'block'],
            $service
        );

        $this->assertNull($result['voucher']);
        $this->assertSame('amount_changed', $result['process_notice']);
        $this->assertSame(1, $service->createCalls);
    }

    public function testCreateVoucherForContextLeavesNoticeUnsetForGenericFailure()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService('uniqueness_exhausted');

        $result = $gateway->exposeCreateVoucherForContext(
            $this->voucherContext(),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'block'],
            $service
        );

        $this->assertNull($result['voucher']);
        $this->assertNull($result['process_notice']);
        $this->assertSame(1, $service->createCalls);
    }

    public function testExpiredLatestVoucherAllowsFreshVoucherCreation()
    {
        $gateway = $this->gateway();
        $service = new KuickPayVoucherGatewayFakeReferenceService();
        $service->createdVoucher = $this->voucher([
            'id' => 26,
            'status' => 'pending',
            'registration_number' => 'REG-NEW',
            'consumer_number' => 'INSTREG-NEW',
            'kuickpay_reference' => 'KP-NEW',
        ]);
        $latest = (object) $this->voucher([
            'id' => 25,
            'status' => 'expired',
            'registration_number' => 'REG-OLD',
            'consumer_number' => 'INSTREG-OLD',
        ]);

        $decision = $gateway->exposeReloadVoucherDecision($latest);
        $result = $gateway->exposeCreateVoucherForContext(
            $this->voucherContext(),
            $this->contactInfo(),
            ['amount_change_policy' => 'block', 'multi_invoice_policy' => 'block'],
            $service
        );

        $this->assertSame('allow', $decision);
        $this->assertSame(1, $service->createCalls);
        $this->assertSame(26, $result['voucher']['id']);
        $this->assertSame('REG-NEW', $result['voucher']['registration_number']);
        $this->assertSame('INSTREG-NEW', $result['voucher']['consumer_number']);
        $this->assertSame('KP-NEW', $result['voucher']['kuickpay_reference']);
        $this->assertNull($result['process_notice']);
    }

    public function testIssueVoucherIfNeededCallsSoapParsesAndPersistsEvidence()
    {
        $gateway = $this->gateway();
        $gateway->Contacts = new KuickPayVoucherGatewayFakeContacts();
        $gateway->fakeSoapClient = new KuickPayVoucherGatewayFakeSoapClient();
        $gateway->fakeIssuanceService = new KuickPayVoucherGatewayFakeIssuanceService();
        $gateway->fakeVoucherRepository = new KuickPayVoucherGatewayFakeVoucherRepository();
        $gateway->fakeVoucherRepository->latest = (object) $this->voucher([
            'kuickpay_reference' => 'KP-ISSUED-123',
            'raw_status' => '00',
        ]);

        $result = $gateway->exposeIssueVoucherIfNeeded(
            $this->voucher(),
            $this->contactInfo(),
            ['fallback_mobile' => '03123456789', 'fallback_email' => 'fallback@example.test']
        );

        $this->assertCount(1, $gateway->fakeVoucherRepository->edits);
        $this->assertArrayHasKey('date_last_checked', $gateway->fakeVoucherRepository->edits[0]['vars']);
        $this->assertCount(1, $gateway->fakeSoapClient->requests);
        $this->assertSame('REG55', $gateway->fakeSoapClient->requests[0]['RegistrationNumber']);
        $this->assertCount(1, $gateway->fakeIssuanceService->records);
        $this->assertSame('pending', $gateway->fakeIssuanceService->records[0]['evidence']->status());
        $this->assertSame('KP-ISSUED-123', $gateway->fakeIssuanceService->records[0]['evidence']->reference());
        $this->assertSame('KP-ISSUED-123', $result['kuickpay_reference']);
    }

    public function testIssueVoucherIfNeededPreservesRecordedOutcomeWhenPostPersistStepThrows()
    {
        $gateway = $this->gateway();
        $gateway->Contacts = new KuickPayVoucherGatewayFakeContacts();
        $gateway->fakeSoapClient = new KuickPayVoucherGatewayFakeSoapClient();
        $gateway->fakeIssuanceService = new KuickPayVoucherGatewayFakeIssuanceService();
        $gateway->fakeVoucherRepository = new KuickPayVoucherGatewayFakeVoucherRepository();
        $gateway->fakeVoucherRepository->throwOnLatest = true;

        $result = $gateway->exposeIssueVoucherIfNeeded($this->voucher(), $this->contactInfo(), []);

        // A post-persist failure must not overwrite the recorded success:
        // the evidence is recorded exactly once and never re-fabricated.
        $this->assertNull($result);
        $this->assertCount(1, $gateway->fakeIssuanceService->records);
        $this->assertSame('pending', $gateway->fakeIssuanceService->records[0]['evidence']->status());
        $this->assertSame('KP-ISSUED-123', $gateway->fakeIssuanceService->records[0]['evidence']->reference());
    }

    public function testIssueVoucherIfNeededLogsSanitizedFailureDiagnostic()
    {
        $gateway = $this->gateway();
        $gateway->Contacts = new KuickPayVoucherGatewayFakeContacts();
        $gateway->fakeSoapClient = new KuickPayVoucherGatewayFakeSoapClient([[
            'ok' => false,
            'operation' => 'InsertVoucher',
            'raw_result' => null,
            'error_class' => 'timeout',
            'redacted_trace_id' => 'trace-timeout',
        ]]);
        $gateway->fakeIssuanceService = new KuickPayVoucherGatewayFakeIssuanceService();
        $gateway->fakeVoucherRepository = new KuickPayVoucherGatewayFakeVoucherRepository();
        $gateway->fakeVoucherRepository->latest = (object) $this->voucher([
            'status' => 'retry',
            'error_class' => 'timeout',
        ]);

        $result = $gateway->exposeIssueVoucherIfNeeded(
            $this->voucher(),
            $this->contactInfo(),
            ['logging_enabled' => 'true']
        );

        $this->assertNull($result);
        $payload = json_decode($gateway->logs[0]['data'], true);
        $this->assertSame('InsertVoucher', $payload['operation']);
        $this->assertSame('trace-timeout', $payload['redacted_trace_id']);
        $this->assertSame(25, $payload['voucher_id']);
        $this->assertSame(['invoice' => 55], $payload['request_summary']);
        $this->assertSame([
            'response_present' => false,
            'result_present' => false,
            'result_code' => null,
            'fault' => 'timeout',
        ], $payload['response_summary']);
        $this->assertSame('timeout', $payload['error_class']);
        $this->assertNull($payload['duration_ms']);
        $this->assertArrayNotHasKey('raw_result', $payload);
        $this->assertArrayNotHasKey('event', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('mobile', $payload);
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

    public function testPaymentPolicySettingsAreProductionGatedToBlock()
    {
        $gateway = $this->gateway();

        $this->assertSame(
            [
                'amount_change_policy' => ['block' => 'Kuickpay.amount_change_policy.block'],
                'multi_invoice_policy' => ['block' => 'Kuickpay.multi_invoice_policy.block'],
                'underpayment_policy' => ['manual_review' => 'Kuickpay.underpayment_policy.manual_review'],
                'overpayment_policy' => ['manual_review' => 'Kuickpay.overpayment_policy.manual_review'],
                'late_payment_policy' => ['manual_review' => 'Kuickpay.late_payment_policy.manual_review'],
            ],
            $gateway->exposePaymentPolicyOptions()
        );
    }

    public function testPaymentPolicyLanguageKeysExistForFutureUngating()
    {
        $lang = [];
        require __DIR__ . '/../language/en_us/kuickpay.php';

        foreach ([
            'Kuickpay.amount_change_policy',
            'Kuickpay.amount_change_policy_note',
            'Kuickpay.amount_change_policy.block',
            'Kuickpay.amount_change_policy.replace',
            'Kuickpay.!error.amount_change_policy.valid',
            'Kuickpay.multi_invoice_policy',
            'Kuickpay.multi_invoice_policy_note',
            'Kuickpay.multi_invoice_policy.block',
            'Kuickpay.multi_invoice_policy.allow',
            'Kuickpay.!error.multi_invoice_policy.valid',
            'Kuickpay.underpayment_policy',
            'Kuickpay.underpayment_policy_note',
            'Kuickpay.underpayment_policy.manual_review',
            'Kuickpay.!error.underpayment_policy.valid',
            'Kuickpay.overpayment_policy',
            'Kuickpay.overpayment_policy_note',
            'Kuickpay.overpayment_policy.manual_review',
            'Kuickpay.!error.overpayment_policy.valid',
            'Kuickpay.late_payment_policy',
            'Kuickpay.late_payment_policy_note',
            'Kuickpay.late_payment_policy.manual_review',
            'Kuickpay.!error.late_payment_policy.valid',
        ] as $key) {
            $this->assertArrayHasKey($key, $lang);
            $this->assertNotSame('', $lang[$key]);
        }
    }

    public function testProcessRetrySafeCopyHasLanguageKey()
    {
        $lang = [];
        require __DIR__ . '/../language/en_us/kuickpay.php';

        $this->assertArrayHasKey('Kuickpay.process.retry_safe', $lang);
        $this->assertArrayHasKey('Kuickpay.process.amount_changed', $lang);
        $this->assertArrayHasKey('Kuickpay.process.multi_invoice_unsupported', $lang);
        $this->assertStringNotContainsString('SOAP', $lang['Kuickpay.process.retry_safe']);
        $this->assertStringNotContainsString('error_class', $lang['Kuickpay.process.retry_safe']);
        $this->assertStringNotContainsString('SOAP', $lang['Kuickpay.process.amount_changed']);
        $this->assertStringNotContainsString('error_class', $lang['Kuickpay.process.amount_changed']);
        $this->assertStringNotContainsString('SOAP', $lang['Kuickpay.process.multi_invoice_unsupported']);
        $this->assertStringNotContainsString('error_class', $lang['Kuickpay.process.multi_invoice_unsupported']);
    }

    public function testCustomerReferencePanelLanguageKeysExist()
    {
        $lang = [];
        require __DIR__ . '/../language/en_us/kuickpay.php';

        foreach ([
            'Kuickpay.process.identity_label',
            'Kuickpay.process.copy_button',
            'Kuickpay.process.copy_feedback',
            'Kuickpay.process.status_expectation',
            'Kuickpay.process.status.pending',
            'Kuickpay.process.status.retry',
            'Kuickpay.process.status.confirmed_unposted',
            'Kuickpay.process.status.posted',
            'Kuickpay.process.status.failed',
            'Kuickpay.process.status.expired',
            'Kuickpay.process.status.manual_review',
            'Kuickpay.process.status.cancelled',
            'Kuickpay.process.status.unknown',
            'Kuickpay.process.instructions_heading',
            'Kuickpay.process.instruction.online_banking.title',
            'Kuickpay.process.instruction.online_banking.body',
            'Kuickpay.process.instruction.bank_deposit.title',
            'Kuickpay.process.instruction.bank_deposit.body',
            'Kuickpay.process.instruction.agent_franchise.title',
            'Kuickpay.process.instruction.agent_franchise.body',
            'Kuickpay.process.instruction.mobile_app.title',
            'Kuickpay.process.instruction.mobile_app.body',
        ] as $key) {
            $this->assertArrayHasKey($key, $lang);
            $this->assertNotSame('', $lang[$key]);
        }

        $forbiddenTerms = [
            'SOAP',
            'WSDL',
            'xmlns',
            'Envelope',
            'error_class',
            'Exception',
            'raw_status',
            'registration_number',
            'consumer_number',
            'password',
            'username',
            'secret',
            'credential',
            'confirmed_unposted',
            'manual_review',
            'Registration Number',
        ];
        foreach ([
            'Kuickpay.process.instructions_heading',
            'Kuickpay.process.instruction.online_banking.title',
            'Kuickpay.process.instruction.online_banking.body',
            'Kuickpay.process.instruction.bank_deposit.title',
            'Kuickpay.process.instruction.bank_deposit.body',
            'Kuickpay.process.instruction.agent_franchise.title',
            'Kuickpay.process.instruction.agent_franchise.body',
            'Kuickpay.process.instruction.mobile_app.title',
            'Kuickpay.process.instruction.mobile_app.body',
        ] as $key) {
            foreach ($forbiddenTerms as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $lang[$key]);
            }
        }
    }

    public function testMaskCredentialsHandlesNonStringInputsSafely()
    {
        // AC3 (5.4): the gateway-owned masker must tolerate any input type and match
        // credential keys case-insensitively, with no TypeError/deprecation. (The shared
        // base Gateway::maskDataRecursive str_repeat()s over strlen() and matches keys
        // case-sensitively; this gateway-local implementation replaces it.)
        $gateway = $this->gateway();
        $object = new stdClass();
        $object->secret = 'value';
        $nested = new stdClass();
        $nested->password = 'nested-secret';
        $nested->note = 'keep me';

        $masked = $gateway->exposeMaskCredentials([
            'password' => null,
            'voucher_password' => $object,
            'inquiry_password' => true,
            'USERNAME' => 'voucher-user',
            'userName' => ['old' => 'a', 'new' => 'bb'],
            'amount' => '1000.00',
            'object_graph' => $nested,
            'nested' => [
                'Password' => 'secret',
                'note' => 'keep me',
            ],
        ]);

        // null preserved; non-stringable object -> fixed token; bool stringified then masked.
        $this->assertNull($masked['password']);
        $this->assertSame('xxxx', $masked['voucher_password']);
        $this->assertSame('x', $masked['inquiry_password']);
        // Mixed-case credential key still matched (case-insensitive allowlist).
        $this->assertSame('xxxxxxxxxxxx', $masked['USERNAME']);
        // A credential key holding an array collapses to a fixed token.
        $this->assertSame('xxxx', $masked['userName']);
        // Non-credential keys preserved, while object graphs are scanned recursively.
        $this->assertSame('1000.00', $masked['amount']);
        $this->assertSame('xxxxxxxxxxxxx', $masked['object_graph']['password']);
        $this->assertSame('keep me', $masked['object_graph']['note']);
        $this->assertSame('xxxxxx', $masked['nested']['Password']);
        $this->assertSame('keep me', $masked['nested']['note']);
        $this->assertSame('xxxx', $gateway->exposeMaskCredentials($object));
    }

    private function gateway()
    {
        $reflection = new ReflectionClass(KuickPayVoucherGatewayHelpers::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function voucher(array $overrides = []): array
    {
        return array_merge([
            'id' => 25,
            'company_id' => 1,
            'client_id' => 3,
            'gateway_id' => 2,
            'currency' => 'PKR',
            'amount' => '1500.00',
            'status' => 'pending',
            'registration_number' => 'REG55',
            'consumer_number' => 'KPREG55',
            'kuickpay_reference' => null,
            'raw_status' => null,
            'date_due' => '2026-06-13',
            'date_expires' => '2026-06-17',
            'invoices' => [['invoice_id' => 55, 'amount' => '1500.00']],
        ], $overrides);
    }

    private function contactInfo(): array
    {
        return [
            'id' => 9,
            'first_name' => 'Ali',
            'last_name' => 'Khan',
            'company' => '',
            'state' => ['code' => 'SD'],
        ];
    }

    private function voucherContext(array $overrides = []): array
    {
        return array_merge([
            'company_id' => 1,
            'amount' => '1500.00',
            'invoice_amounts' => [['id' => 55, 'amount' => '1500.00']],
        ], $overrides);
    }
}
