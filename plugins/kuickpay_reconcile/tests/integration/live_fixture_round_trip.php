<?php
/**
 * Opt-in DB-backed KuickPay reconcile->post verification harness.
 *
 * This script mutates the configured Blesta database. Run only with a
 * disposable unpaid invoice created for verification.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This harness is CLI-only.\n");
    exit(2);
}

$options = getopt('', [
    'company-id:',
    'invoice-id:',
    'gateway-id:',
    'amount::',
    'fixture::',
    'registration-number::',
    'consumer-number::',
    'reference::',
    'i-understand-this-posts-real-transaction',
    'help',
]);

if (isset($options['help'])) {
    kuickpay_live_fixture_usage();
    exit(0);
}

foreach (['company-id', 'invoice-id', 'gateway-id'] as $required) {
    if (!isset($options[$required])) {
        fwrite(STDERR, "Missing --{$required}.\n\n");
        kuickpay_live_fixture_usage();
        exit(2);
    }
}

if (!array_key_exists('i-understand-this-posts-real-transaction', $options)) {
    fwrite(STDERR, "Refusing to run without --i-understand-this-posts-real-transaction.\n\n");
    kuickpay_live_fixture_usage();
    exit(2);
}

$root = dirname(__DIR__, 4);
$fixture = $options['fixture'] ?? 'valid/bill-payment-inquiry-paid-exact.xml';
$fixturePath = __DIR__ . '/../fixtures/kuickpay/' . $fixture;

if (!is_file($fixturePath)) {
    fwrite(STDERR, "Fixture not found: {$fixturePath}\n");
    exit(2);
}

$container = include $root . '/lib/init.php';
error_reporting(E_ALL);

$pluginLib = $root . '/plugins/kuickpay_reconcile/lib';
$gatewayLib = $root . '/components/gateways/nonmerchant/kuickpay/lib';
Loader::load($pluginLib . '/KuickPayVoucherRepository.php');
Loader::load($pluginLib . '/KuickPayReconciliationRunRepository.php');
Loader::load($pluginLib . '/KuickPayReconciliationItemRepository.php');
Loader::load($pluginLib . '/KuickPayReconcileLockRepository.php');
Loader::load($pluginLib . '/KuickPayAuditRepository.php');
Loader::load($pluginLib . '/KuickPayAuditService.php');
Loader::load($pluginLib . '/KuickPayValidationResult.php');
Loader::load($pluginLib . '/KuickPayInvoiceReader.php');
Loader::load($pluginLib . '/KuickPayEvidenceValidator.php');
Loader::load($pluginLib . '/KuickPayReconcileService.php');
Loader::load($pluginLib . '/KuickPayPostingService.php');
Loader::load($gatewayLib . '/KuickPayRedactor.php');
Loader::load($gatewayLib . '/KuickPayEvidence.php');
Loader::load($gatewayLib . '/KuickPayResponseParser.php');

$companyId = (int) $options['company-id'];
$invoiceId = (int) $options['invoice-id'];
$gatewayId = (int) $options['gateway-id'];

$invoiceReader = new KuickPayInvoiceReader();
$invoice = $invoiceReader->get($invoiceId);
if (!$invoice) {
    fwrite(STDERR, "Invoice {$invoiceId} was not found.\n");
    exit(1);
}

if ((string) ($invoice->status ?? '') !== 'active' || (string) ($invoice->currency ?? '') !== 'PKR') {
    fwrite(STDERR, "Invoice must be active and PKR.\n");
    exit(1);
}

$amount = isset($options['amount'])
    ? kuickpay_live_fixture_money((string) $options['amount'])
    : kuickpay_live_fixture_invoice_due($invoice);

if ($amount === null || $amount === '0.00') {
    fwrite(STDERR, "Could not derive a positive invoice amount; pass --amount=NNN.NN.\n");
    exit(1);
}

$suffix = date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 6);
$registrationNumber = $options['registration-number'] ?? ('KPVREG' . $suffix);
$consumerNumber = $options['consumer-number'] ?? ('KPVCON' . $suffix);
$reference = $options['reference'] ?? ('KPVREF' . $suffix);

$repository = new KuickPayVoucherRepository();
if ($repository->getByRegistrationNumber($registrationNumber, $companyId)
    || $repository->getByConsumerNumber($consumerNumber, $companyId)
) {
    fwrite(STDERR, "Generated or supplied verification identity already exists.\n");
    exit(1);
}

$voucherId = $repository->create([
    'company_id' => $companyId,
    'gateway_id' => $gatewayId,
    'client_id' => (int) $invoice->client_id,
    'currency' => 'PKR',
    'amount' => $amount,
    'status' => 'pending',
    'registration_number' => $registrationNumber,
    'consumer_number' => $consumerNumber,
    // context_key is required since Story 5.2; single-invoice set →
    // sha1 of the lone id (matches the canonical sha1(implode(',', [id]))).
    'context_key' => sha1((string) $invoiceId),
    'date_due' => date('Y-m-d', strtotime('+7 days')),
    'date_expires' => date('Y-m-d', strtotime('+14 days')),
], [[
    'invoice_id' => $invoiceId,
    'amount' => $amount,
]]);

if (!$voucherId) {
    fwrite(STDERR, "Failed to create verification voucher.\n");
    exit(1);
}

$rawResult = kuickpay_live_fixture_result($fixturePath);
$rawResult = str_replace('REG-0000001', $consumerNumber, $rawResult);
$rawResult = str_replace(['1000.00', '1000.0'], $amount, $rawResult);
$rawResult = str_replace('KP-REF-PAID', $reference, $rawResult);

$scopedRepository = new KuickPayScopedVerificationRepository($repository, $voucherId);
$client = new KuickPayFixtureInquiryClient($rawResult);
$gatewayConfig = [
    'reconciliation_enabled' => 'true',
    'wsdl_url' => 'fixture://kuickpay-live-verification',
    'soap_timeout' => '1',
    'institution_id' => 'fixture',
    'voucher_username' => 'fixture',
    'voucher_password' => 'fixture',
    'inquiry_username' => 'fixture',
    'inquiry_password' => 'fixture',
    'inquiry_same_as_voucher' => 'false',
    'logging_enabled' => 'false',
    'underpayment_policy' => 'manual_review',
    'overpayment_policy' => 'manual_review',
    'late_payment_policy' => 'manual_review',
];

$validator = new KuickPayEvidenceValidator(['voucher_repository' => $scopedRepository]);
$reconcile = new KuickPayReconcileService([
    'voucher_repository' => $scopedRepository,
    'run_repository' => new KuickPayReconciliationRunRepository(),
    'item_repository' => new KuickPayReconciliationItemRepository(),
    'lock_repository' => new KuickPayReconcileLockRepository(),
    'audit_service' => new KuickPayAuditService(),
    'parser' => new KuickPayResponseParser(),
    'evidence_validator' => $validator,
    'client_factory' => function () use ($client) {
        return $client;
    },
    'gateway_config' => $gatewayConfig,
]);
$posting = new KuickPayPostingService([
    'voucher_repository' => $scopedRepository,
    'evidence_validator' => $validator,
    'audit_service' => new KuickPayAuditService(),
    'lock_repository' => new KuickPayReconcileLockRepository(),
]);

$beforeTransactions = kuickpay_live_fixture_transaction_count(
    $repository->record(),
    (int) $invoice->client_id,
    $gatewayId,
    $reference
);

$reconcileResult = $reconcile->runCron($companyId);
$confirmed = $repository->getForCompany($voucherId, $companyId);
if (!$confirmed || (string) $confirmed->status !== 'confirmed_unposted') {
    fwrite(STDERR, "Reconcile did not produce confirmed_unposted.\n");
    kuickpay_live_fixture_print_state($voucherId, $reconcileResult, null, null);
    exit(1);
}

$postResult = $posting->postVoucher($companyId, $confirmed);
$posted = $repository->getForCompany($voucherId, $companyId);
if (!$posted || (string) $posted->status !== 'posted' || empty($posted->blesta_transaction_id)) {
    fwrite(STDERR, "Posting did not produce posted with a Blesta transaction.\n");
    kuickpay_live_fixture_print_state($voucherId, $reconcileResult, $postResult, null);
    exit(1);
}

$secondReconcile = $reconcile->runCron($companyId);
$secondPost = $posting->postVoucher($companyId, $posted);
$afterTransactions = kuickpay_live_fixture_transaction_count(
    $repository->record(),
    (int) $invoice->client_id,
    $gatewayId,
    $reference
);
$appliedCount = kuickpay_live_fixture_applied_count($repository->record(), (int) $posted->blesta_transaction_id);

if ($afterTransactions !== $beforeTransactions + 1
    || ($secondPost['outcome'] ?? '') !== 'already_posted'
    || $appliedCount !== 1
) {
    fwrite(STDERR, "Idempotency invariant failed.\n");
    kuickpay_live_fixture_print_state($voucherId, $secondReconcile, $secondPost, [
        'before_transactions' => $beforeTransactions,
        'after_transactions' => $afterTransactions,
        'applied_count' => $appliedCount,
    ]);
    exit(1);
}

kuickpay_live_fixture_print_state($voucherId, $reconcileResult, $postResult, [
    'second_reconcile' => $secondReconcile,
    'second_post' => $secondPost,
    'transaction_id' => (int) $posted->blesta_transaction_id,
    'before_transactions' => $beforeTransactions,
    'after_transactions' => $afterTransactions,
    'applied_count' => $appliedCount,
]);
exit(0);

function kuickpay_live_fixture_usage(): void
{
    fwrite(STDERR, <<<'TEXT'
Usage:
  php plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php \
    --company-id=1 --invoice-id=<disposable-unpaid-invoice-id> --gateway-id=<kuickpay-gateway-id> \
    --i-understand-this-posts-real-transaction

Optional:
  --amount=500.00
  --fixture=valid/bill-payment-inquiry-paid-exact.xml
  --registration-number=...
  --consumer-number=...
  --reference=...

This creates a real voucher, replays a sanitized inquiry fixture, posts a real
Blesta transaction, and verifies the second run does not duplicate it.

TEXT);
}

function kuickpay_live_fixture_result(string $fixturePath): string
{
    $xml = file_get_contents($fixturePath);
    if (!preg_match('/<BillPaymentInquiryResult>(.*?)<\/BillPaymentInquiryResult>/s', $xml, $matches)) {
        throw new RuntimeException('Fixture does not contain BillPaymentInquiryResult.');
    }

    return trim(html_entity_decode($matches[1]));
}

function kuickpay_live_fixture_money(string $amount): ?string
{
    $amount = trim($amount);
    if (!preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
        return null;
    }

    return $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
}

function kuickpay_live_fixture_invoice_due(stdClass $invoice): ?string
{
    if (isset($invoice->due)) {
        return kuickpay_live_fixture_money(number_format((float) $invoice->due, 2, '.', ''));
    }

    if (isset($invoice->total)) {
        $paid = isset($invoice->paid) ? (float) $invoice->paid : 0.0;
        return kuickpay_live_fixture_money(number_format(max(0.0, (float) $invoice->total - $paid), 2, '.', ''));
    }

    return null;
}

function kuickpay_live_fixture_transaction_count($record, int $clientId, int $gatewayId, string $reference): int
{
    $row = $record
        ->select(['COUNT(*)' => 'count'])
        ->from('transactions')
        ->where('transaction_id', '=', $reference)
        ->where('client_id', '=', $clientId)
        ->where('gateway_id', '=', $gatewayId)
        ->fetch();

    return (int) ($row->count ?? 0);
}

function kuickpay_live_fixture_applied_count($record, int $transactionId): int
{
    $row = $record
        ->select(['COUNT(*)' => 'count'])
        ->from('transaction_applied')
        ->where('transaction_id', '=', $transactionId)
        ->fetch();

    return (int) ($row->count ?? 0);
}

function kuickpay_live_fixture_print_state(int $voucherId, $reconcile, $post, $extra): void
{
    echo json_encode([
        'voucher_id' => $voucherId,
        'reconcile' => $reconcile,
        'post' => $post,
        'extra' => $extra,
    ], JSON_PRETTY_PRINT) . "\n";
}

class KuickPayFixtureInquiryClient
{
    private string $rawResult;

    public function __construct(string $rawResult)
    {
        $this->rawResult = $rawResult;
    }

    public function billPaymentInquiry(array $request): array
    {
        return [
            'ok' => true,
            'operation' => 'BillPaymentInquiry',
            'raw_result' => $this->rawResult,
            'error_class' => null,
            'redacted_trace_id' => 'fixture_round_trip',
        ];
    }
}

class KuickPayScopedVerificationRepository
{
    private KuickPayVoucherRepository $inner;
    private int $voucherId;

    public function __construct(KuickPayVoucherRepository $inner, int $voucherId)
    {
        $this->inner = $inner;
        $this->voucherId = $voucherId;
    }

    public function getReconcilable(int $company_id, int $limit, int $afterId = 0, string $pendingMinRecheckBefore = null): array
    {
        $voucher = $this->inner->getForCompany($this->voucherId, $company_id);
        if (!$voucher
            || (int) $voucher->id <= $afterId
            || !in_array((string) $voucher->status, ['pending', 'retry'], true)
        ) {
            return [];
        }

        return [$voucher];
    }

    public function getPostable(int $company_id, int $limit, int $afterId = 0): array
    {
        $voucher = $this->inner->getForCompany($this->voucherId, $company_id);
        if (!$voucher
            || (int) $voucher->id <= $afterId
            || (string) $voucher->status !== 'confirmed_unposted'
        ) {
            return [];
        }

        return [$voucher];
    }

    public function __call(string $name, array $arguments)
    {
        return $this->inner->{$name}(...$arguments);
    }
}
