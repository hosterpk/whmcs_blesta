<?php
/**
 * Opt-in live KuickPay SOAP smoke.
 *
 * This script is manual verification scaffolding. It is intentionally not a
 * PHPUnit test and must never write Blesta voucher, invoice, or transaction data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/KuickPayLiveSmokePlan.php';

$plan = KuickPayLiveSmokePlan::plan(kuickpay_live_smoke_env());

if (!$plan['run']) {
    kuickpay_live_smoke_print([
        'result' => 'SKIPPED',
        'reason' => $plan['reason'],
        'missing' => $plan['missing'],
        'no_invoice_paid' => true,
    ]);
    exit(0);
}

$client = new KuickPaySoapClient($plan['config']);
$outcome = kuickpay_live_smoke_call($client, $plan['operation'], $plan['consumer_number']);
$evidence = (new KuickPayResponseParser())->parse($outcome, [
    'expected_consumer_number' => $plan['consumer_number'],
]);

$capture = kuickpay_live_smoke_capture($outcome, $plan['capture_path']);
$report = [
    'result' => $outcome['ok'] ? 'COMPLETED' : 'FAILED',
    'transport' => kuickpay_live_smoke_transport_report($outcome),
    'evidence' => kuickpay_live_smoke_evidence_report($evidence),
    'capture' => $capture,
    'no_invoice_paid' => true,
    'no_invoice_paid_reason' => 'DB-free read-only smoke; no voucher, invoice, transaction, posting, or database path is invoked.',
];

kuickpay_live_smoke_print($report);
exit(kuickpay_live_smoke_exit_code($outcome, $evidence));

function kuickpay_live_smoke_env(): array
{
    $keys = [
        'KUICKPAY_LIVE_SMOKE',
        'KUICKPAY_SMOKE_WSDL_URL',
        'KUICKPAY_SMOKE_INQUIRY_USERNAME',
        'KUICKPAY_SMOKE_INQUIRY_PASSWORD',
        'KUICKPAY_SMOKE_INSTITUTION_ID',
        'KUICKPAY_SMOKE_CONSUMER_NUMBER',
        'KUICKPAY_SMOKE_OPERATION',
        'KUICKPAY_SMOKE_TIMEOUT',
        'KUICKPAY_SMOKE_CAPTURE',
    ];
    $env = [];

    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }

    return $env;
}

function kuickpay_live_smoke_call(KuickPaySoapClient $client, string $operation, string $consumerNumber): array
{
    if ($operation === 'Echo') {
        return $client->echoTest();
    }

    if ($operation === 'GetInstitutionsList') {
        return $client->getInstitutionsList();
    }

    return $client->billPaymentInquiry(['Consumer_Number' => $consumerNumber]);
}

function kuickpay_live_smoke_transport_report(array $outcome): array
{
    return [
        'ok' => (bool) ($outcome['ok'] ?? false),
        'operation' => isset($outcome['operation']) ? (string) $outcome['operation'] : null,
        'error_class' => isset($outcome['error_class']) ? $outcome['error_class'] : null,
        'fault' => isset($outcome['fault']) ? $outcome['fault'] : null,
        'redacted_trace_id' => isset($outcome['redacted_trace_id']) ? (string) $outcome['redacted_trace_id'] : null,
        'duration_ms' => isset($outcome['duration_ms']) ? (int) $outcome['duration_ms'] : null,
        'attempt' => isset($outcome['attempt']) ? (int) $outcome['attempt'] : null,
        'attempts' => isset($outcome['attempts']) ? (int) $outcome['attempts'] : null,
    ];
}

function kuickpay_live_smoke_evidence_report(KuickPayEvidence $evidence): array
{
    return [
        'status' => $evidence->status(),
        'error_class' => $evidence->errorClass(),
        'evidence_hash' => $evidence->evidenceHash(),
        'redacted_trace_id' => $evidence->redactedTraceId(),
        'validation_errors' => $evidence->validationErrors(),
        'operation' => $evidence->operation(),
        'is_confirmed_unposted' => $evidence->isConfirmedUnposted(),
    ];
}

function kuickpay_live_smoke_capture(array $outcome, ?string $capturePath): array
{
    if ($capturePath === null || $capturePath === '') {
        return ['written' => false, 'reason' => 'not-requested'];
    }

    $envelope = isset($outcome['raw_envelope']) && is_string($outcome['raw_envelope']) && $outcome['raw_envelope'] !== ''
        ? $outcome['raw_envelope']
        : KuickPayRedactor::ENVELOPE_UNPARSEABLE;

    // Exclusive-create only: a mistyped or wrong capture path must never silently
    // overwrite an existing file (a prior capture, or unrelated operator data).
    $handle = @fopen($capturePath, 'x');
    if ($handle === false) {
        return ['written' => false, 'reason' => file_exists($capturePath) ? 'target-exists' : 'write-failed'];
    }

    $written = fwrite($handle, $envelope);
    fclose($handle);

    if ($written === false) {
        return ['written' => false, 'reason' => 'write-failed'];
    }

    return [
        'written' => true,
        'bytes' => strlen($envelope),
    ];
}

function kuickpay_live_smoke_exit_code(array $outcome, KuickPayEvidence $evidence): int
{
    if (($outcome['ok'] ?? false) === false) {
        return 1;
    }

    return $evidence->errorClass() === KuickPayResponseParser::ERROR_CREDENTIAL ? 1 : 0;
}

function kuickpay_live_smoke_print(array $report): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
