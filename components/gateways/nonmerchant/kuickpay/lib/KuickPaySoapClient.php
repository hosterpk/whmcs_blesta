<?php
/**
 * KuickPay SOAP transport wrapper.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPaySoapClient
{
    private const DEFAULT_TIMEOUT = 30;
    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 120;

    /**
     * @var array Gateway SOAP configuration
     */
    private $config;

    /**
     * @var callable Factory with signature function(string $wsdl, array $options): object
     */
    private $soap_client_factory;

    /**
     * @var object|null Lazily constructed SOAP client or test double
     */
    private $soap_client;

    /**
     * @var array|null Lazily built SoapClient options
     */
    private $soap_options;

    /**
     * @var KuickPayRedactor
     */
    private $redactor;

    /**
     * @var callable|null Receives canonical operational log fields and success flag
     */
    private $logger;

    /**
     * @param array $config Gateway SOAP configuration
     * @param callable|null $soapClientFactory Optional factory for testable SOAP construction
     * @param callable|null $logger Optional operational logger
     */
    public function __construct(array $config, callable $soapClientFactory = null, callable $logger = null)
    {
        $this->config = $config;
        $this->soap_client_factory = $soapClientFactory ?: function (string $wsdl, array $options): object {
            return new SoapClient($wsdl, $options);
        };
        $this->redactor = new KuickPayRedactor();
        $this->logger = $logger;
    }

    /**
     * Create a KuickPay voucher through one transport attempt.
     *
     * @param array $voucherParams Caller-supplied voucher field map
     * @return array Structured transport outcome
     */
    public function insertVoucher(array $voucherParams): array
    {
        $params = $this->withCredentials($voucherParams, false);

        // InsertVoucher is never auto-retried: idempotency is unproven and a retry can double-issue a payable voucher.
        $outcome = $this->call('InsertVoucher', $params);
        $outcome['attempts'] = 1;

        return $outcome;
    }

    /**
     * Run a single bill payment inquiry with bounded transport retries.
     *
     * @param array $inquiryParams Caller-supplied inquiry field map
     * @return array Structured transport outcome
     */
    public function billPaymentInquiry(array $inquiryParams): array
    {
        return $this->callWithRetry('BillPaymentInquiry', $this->withCredentials($inquiryParams, true));
    }

    /**
     * Run a bulk bill payment inquiry with bounded transport retries.
     *
     * @param array $bulkParams Caller-supplied bulk inquiry field map
     * @return array Structured transport outcome
     */
    public function billPaymentBulkInquiry(array $bulkParams): array
    {
        return $this->callWithRetry('BillPaymentBulkInquiry', $this->withCredentials($bulkParams, true));
    }

    /**
     * Run a safe SOAP Echo operation for future setup checks.
     *
     * @param array $params Caller-supplied echo params
     * @return array Structured transport outcome
     */
    public function echoTest(array $params = []): array
    {
        return $this->call('Echo', $params);
    }

    /**
     * Run a safe SOAP GetInstitutionsList operation for future setup checks.
     *
     * @param array $params Caller-supplied params
     * @return array Structured transport outcome
     */
    public function getInstitutionsList(array $params = []): array
    {
        return $this->call('GetInstitutionsList', $params);
    }

    /**
     * Perform one SOAP transport attempt and return transport facts only.
     *
     * Outcome shape:
     * - ok: bool transport reachability only; true when a response body arrived
     * - operation: string SOAP operation name
     * - raw_result: ?string unredacted parser-only operation result payload
     * - raw_envelope: ?string redacted response envelope diagnostic
     * - error_class: ?string null|timeout|transport_error
     * - fault: ?string redacted fault/transport summary
     * - redacted_request: array redacted request params
     * - redacted_trace_id: string non-PII trace id
     * - duration_ms: int transport attempt duration
     * - attempt: int one-based transport attempt index
     * - attempts: int attempt count supplied by public wrappers
     *
     * @param string $operation SOAP operation name
     * @param array $params SOAP operation params
     * @param int $attempt One-based transport attempt index
     * @return array Structured transport outcome
     */
    private function call(string $operation, array $params, int $attempt = 1): array
    {
        $start = microtime(true);
        $trace_id = $this->redactor->traceId();
        $redacted_request = $this->redactor->redactArray($params);

        if (!$this->hasUsableWsdlUrl()) {
            return $this->outcome(
                false,
                $operation,
                null,
                null,
                'transport_error',
                'Invalid or unsafe WSDL URL',
                $redacted_request,
                $trace_id,
                $this->durationMs($start),
                $attempt
            );
        }

        $previous_timeout = ini_set('default_socket_timeout', (string) $this->timeout());

        try {
            $client = $this->soapClient();
            $result = $client->__soapCall($operation, [$params]);
            $response = $this->lastEnvelope($client, 'response');

            return $this->outcome(
                true,
                $operation,
                $this->extractRawResult($operation, $result, $response),
                $this->redactEnvelope($response),
                null,
                null,
                $redacted_request,
                $trace_id,
                $this->durationMs($start),
                $attempt
            );
        } catch (SoapFault $e) {
            $response = isset($client) ? $this->lastEnvelope($client, 'response') : '';
            if ($response !== '') {
                return $this->outcome(
                    true,
                    $operation,
                    $this->extractRawResult($operation, null, $response),
                    $this->redactEnvelope($response),
                    null,
                    $this->redactedDiagnosticText($e->getMessage(), $params),
                    $redacted_request,
                    $trace_id,
                    $this->durationMs($start),
                    $attempt
                );
            }

            return $this->outcome(
                false,
                $operation,
                null,
                null,
                $this->isTimeout($e) ? 'timeout' : 'transport_error',
                $this->redactedDiagnosticText($e->getMessage(), $params),
                $redacted_request,
                $trace_id,
                $this->durationMs($start),
                $attempt
            );
        } catch (Throwable $e) {
            $response = isset($client) ? $this->lastEnvelope($client, 'response') : '';
            if ($response !== '') {
                return $this->outcome(
                    true,
                    $operation,
                    $this->extractRawResult($operation, null, $response),
                    $this->redactEnvelope($response),
                    null,
                    $this->redactedDiagnosticText($e->getMessage(), $params),
                    $redacted_request,
                    $trace_id,
                    $this->durationMs($start),
                    $attempt
                );
            }

            return $this->outcome(
                false,
                $operation,
                null,
                null,
                $this->isTimeout($e) ? 'timeout' : 'transport_error',
                $this->redactedDiagnosticText($e->getMessage(), $params),
                $redacted_request,
                $trace_id,
                $this->durationMs($start),
                $attempt
            );
        } finally {
            if ($previous_timeout !== false) {
                ini_set('default_socket_timeout', $previous_timeout);
            }
        }
    }

    /**
     * Merge operation credentials and institution id into caller params.
     *
     * @param array $params Caller-supplied operation params
     * @param bool $inquiry True for inquiry credentials, false for voucher credentials
     * @return array Params with SOAP credentials
     */
    private function withCredentials(array $params, bool $inquiry): array
    {
        $same_as_voucher = (string) $this->configValue('inquiry_same_as_voucher', 'false') === 'true';
        $prefix = ($inquiry && !$same_as_voucher) ? 'inquiry' : 'voucher';

        return array_merge($params, [
            'userName' => (string) $this->configValue($prefix . '_username', ''),
            'password' => (string) $this->configValue($prefix . '_password', ''),
            'InstitutionID' => (string) $this->configValue('institution_id', ''),
        ]);
    }

    /**
     * Retry inquiry operations on transport-only failures.
     *
     * @param string $operation SOAP operation name
     * @param array $params SOAP params
     * @return array Structured transport outcome
     */
    private function callWithRetry(string $operation, array $params): array
    {
        $max_attempts = 3;
        $outcome = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $outcome = $this->call($operation, $params, $attempt);
            $outcome['attempts'] = $attempt;

            if ($outcome['ok'] || !in_array($outcome['error_class'], ['timeout', 'transport_error'], true)) {
                return $outcome;
            }
        }

        return $outcome;
    }

    /**
     * Lazily construct the configured SoapClient or test double.
     *
     * @return object SOAP client/test double
     */
    private function soapClient(): object
    {
        if ($this->soap_client === null) {
            $factory = $this->soap_client_factory;
            $this->soap_client = $factory((string) $this->configValue('wsdl_url'), $this->soapOptions());
        }

        return $this->soap_client;
    }

    /**
     * Build SoapClient options once.
     *
     * @return array SoapClient options
     */
    private function soapOptions(): array
    {
        if ($this->soap_options === null) {
            $timeout = $this->timeout();
            $this->soap_options = [
                'connection_timeout' => $timeout,
                'exceptions' => true,
                'trace' => true,
                'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? WSDL_CACHE_MEMORY : 2,
                'stream_context' => stream_context_create([
                    'http' => [
                        'timeout' => $timeout,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ]),
            ];
        }

        return $this->soap_options;
    }

    /**
     * Derive bounded connection/read timeout seconds.
     *
     * @return int Timeout seconds
     */
    private function timeout(): int
    {
        $timeout = (int) $this->configValue('soap_timeout', self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        // Bound both ends: a sane floor, and a ceiling so a misconfigured huge value
        // cannot pin a connection open far longer than any reasonable transport.
        return min(self::MAX_TIMEOUT, max(self::MIN_TIMEOUT, $timeout));
    }

    /**
     * Validate WSDL URL at call time, including userinfo rejection.
     *
     * @return bool True when the URL is safe enough to attempt
     */
    private function hasUsableWsdlUrl(): bool
    {
        $wsdl_url = (string) $this->configValue('wsdl_url', '');
        if ($wsdl_url === '' || filter_var($wsdl_url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Enforce TLS at call time too (mirrors the editSettings guard); filter_var alone
        // accepts http://, file://, and other non-HTTPS schemes that would bypass TLS.
        if (strtolower((string) parse_url($wsdl_url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        return parse_url($wsdl_url, PHP_URL_USER) === null
            && parse_url($wsdl_url, PHP_URL_PASS) === null;
    }

    /**
     * Extract operation result payload without business parsing.
     *
     * @param string $operation SOAP operation name
     * @param mixed $result SoapClient return value
     * @param string $response Raw response envelope
     * @return string|null Raw result payload
     */
    private function extractRawResult(string $operation, $result, string $response): ?string
    {
        $property = $operation . 'Result';
        if (is_object($result) && isset($result->{$property})) {
            return (string) $result->{$property};
        }

        if (is_array($result) && isset($result[$property])) {
            return (string) $result[$property];
        }

        if ($response === '') {
            return null;
        }

        // Same fail-closed guard the redactor applies: never feed an oversize or
        // DOCTYPE-bearing (XML-entity-expansion) envelope to the parser.
        if (strlen($response) > KuickPayRedactor::MAX_ENVELOPE_BYTES
            || stripos($response, '<!DOCTYPE') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        if (!$document->loadXML($response, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return null;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name() = "' . $property . '"]');
        $value = ($nodes !== false && $nodes->length > 0) ? $nodes->item(0)->textContent : null;

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $value;
    }

    /**
     * Read the last request/response envelope from a SOAP client if exposed.
     *
     * @param object $client SOAP client/test double
     * @param string $type response or request
     * @return string Raw envelope or an empty string
     */
    private function lastEnvelope(object $client, string $type): string
    {
        $method = $type === 'request' ? '__getLastRequest' : '__getLastResponse';
        if (!method_exists($client, $method)) {
            return '';
        }

        $value = $client->{$method}();

        return is_string($value) ? $value : '';
    }

    /**
     * Redact an envelope only when present.
     *
     * @param string $envelope Raw SOAP envelope
     * @return string|null Redacted SOAP envelope
     */
    private function redactEnvelope(string $envelope): ?string
    {
        return $envelope === '' ? null : $this->redactor->redactEnvelope($envelope);
    }

    /**
     * Classify timeout-like transport exceptions.
     *
     * @param Throwable $e Transport exception
     * @return bool True when exception text indicates timeout
     */
    private function isTimeout(Throwable $e): bool
    {
        return preg_match('/timeout|timed out|temporarily unavailable/i', $e->getMessage()) === 1;
    }

    /**
     * Return a redacted diagnostic string without raw credentials or PII.
     *
     * A provider fault may echo back submitted credentials or PII (Name/Mobile/Email/
     * Branch) as free text that never passed through the keyed/element redactors, so the
     * request-supplied sensitive values are stripped here in addition to configured
     * credentials. Fail closed: over-masking a diagnostic is preferable to leaking (AC5).
     *
     * @param string $text Raw diagnostic text
     * @param array $params Request params whose sensitive values must not surface
     * @return string Redacted diagnostic text
     */
    private function redactedDiagnosticText(string $text, array $params = []): string
    {
        if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
            $redacted = $this->redactor->redactEnvelope($text);
            if ($redacted !== KuickPayRedactor::ENVELOPE_UNPARSEABLE) {
                $text = $redacted;
            }
        }

        $secret_values = $this->redactor->sensitiveValues($params);
        foreach ([
            'voucher_username',
            'voucher_password',
            'inquiry_username',
            'inquiry_password',
            'institution_id',
        ] as $key) {
            $secret_values[] = (string) $this->configValue($key, '');
        }

        // Strip longest values first so a short value cannot pre-empt a longer overlap.
        usort($secret_values, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($secret_values as $value) {
            if ($value !== '') {
                $text = str_replace($value, 'xxxx', $text);
            }
        }

        $text = preg_replace(
            '/(userName|username|UserName|password|Password|InstitutionID|Name|Mobile|Email|Branch)\s*[:=]\s*(\'[^\']*\'|"[^"]*"|[^,\s<]+)/i',
            '$1=xxxx',
            $text
        );

        return is_string($text) ? $text : '';
    }

    /**
     * Fetch a config value with default.
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @return mixed Config value
     */
    private function configValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Calculate elapsed transport attempt duration.
     *
     * @param float $start microtime(true) start
     * @return int Duration in milliseconds
     */
    private function durationMs(float $start): int
    {
        return max(0, (int) round((microtime(true) - $start) * 1000));
    }

    /**
     * Build the canonical transport outcome.
     *
     * @param bool $ok True when response body arrived
     * @param string $operation SOAP operation name
     * @param string|null $raw_result Parser-only raw result payload
     * @param string|null $raw_envelope Redacted response envelope
     * @param string|null $error_class Transport error class
     * @param string|null $fault Redacted fault summary
     * @param array $redacted_request Redacted request params
     * @param string $trace_id Redacted trace id
     * @param int $duration_ms Transport attempt duration
     * @param int $attempt One-based transport attempt index
     * @return array Structured outcome
     */
    private function outcome(
        bool $ok,
        string $operation,
        ?string $raw_result,
        ?string $raw_envelope,
        ?string $error_class,
        ?string $fault,
        array $redacted_request,
        string $trace_id,
        int $duration_ms,
        int $attempt = 1
    ): array {
        $response_present = $raw_envelope !== null && $raw_envelope !== '';
        $result_present = $raw_result !== null && $raw_result !== '';
        $result_code = $result_present && preg_match('/^[A-Za-z0-9]{2}/', (string) $raw_result) === 1
            ? substr((string) $raw_result, 0, 2)
            : null;
        $fault_token = KuickPayRedactor::logSafeFaultToken($fault, $error_class, $response_present);

        $outcome = [
            'ok' => $ok,
            'operation' => $operation,
            'raw_result' => $raw_result,
            'raw_envelope' => $raw_envelope,
            'error_class' => $error_class,
            'fault' => $fault,
            'redacted_request' => $redacted_request,
            'redacted_trace_id' => $trace_id,
            'duration_ms' => $duration_ms,
            'attempt' => max(1, $attempt),
            'attempts' => 1,
        ];

        if ($this->logger !== null) {
            $logger = $this->logger;
            try {
                $logger(KuickPayRedactor::operationLogFields(
                    $operation,
                    $trace_id,
                    null,
                    $redacted_request,
                    [
                        'response_present' => $response_present,
                        'result_present' => $result_present,
                        'result_code' => $result_code,
                        'fault' => $fault_token,
                    ],
                    $error_class,
                    $duration_ms,
                    max(1, $attempt)
                ), $ok);
            } catch (Throwable $e) {
                // Operational logging must never affect the SOAP operation outcome.
            }
        }

        return $outcome;
    }
}
