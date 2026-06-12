<?php
/**
 * KuickPay protocol-library redaction boundary.
 *
 * This is the protocol-library redaction slice. The gateway's `maskCredentials()`
 * (kuickpay.php:291-294) is the gateway-owned credential slice -- two intentional
 * layers. Keep credential keys in sync.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayRedactor
{
    public const ENVELOPE_UNPARSEABLE = '[UNPARSEABLE_ENVELOPE]';
    public const MAX_ENVELOPE_BYTES = 1048576;

    /**
     * @var array Sensitive SOAP credential and PII element/key names
     */
    private $sensitive_fields = [
        'voucher_password',
        'inquiry_password',
        'voucher_username',
        'inquiry_username',
        'password',
        'userName',
        'Password',
        'UserName',
        'username',
        'USERNAME',
        'PASSWORD',
        'Name',
        'name',
        'NAME',
        'Mobile',
        'mobile',
        'MOBILE',
        'Email',
        'email',
        'EMAIL',
        'Branch',
        'branch',
        'BRANCH',
        'InstitutionID',
        'institutionID',
        'institutionId',
        'institution_id',
        'INSTITUTIONID',
        'INSTITUTION_ID',
    ];

    /**
     * @var array Lowercase sensitive field lookup
     */
    private $sensitive_lookup;

    /**
     * Redact sensitive keyed values in request/response arrays.
     *
     * @param array $data Request or response data
     * @return array Redacted data
     */
    public function redactArray(array $data): array
    {
        return $this->maskDataRecursive($data, $this->sensitiveFields());
    }

    /**
     * Collect the raw values stored under sensitive keys (recursively).
     *
     * Free-text diagnostics (a provider fault message) never pass through the keyed
     * or element redactors, yet a provider may echo submitted credentials/PII back in
     * one. This exposes those request-supplied values so a caller can strip them out.
     *
     * @param array $data Request or response data
     * @return array Distinct non-empty sensitive string values
     */
    public function sensitiveValues(array $data): array
    {
        $values = [];
        $this->collectSensitiveValues($data, $this->sensitiveFields(), $values);

        return array_values(array_unique($values));
    }

    /**
     * Redact sensitive SOAP envelope element text by local element name.
     *
     * @param string $xml Raw SOAP envelope
     * @return string Redacted envelope or a fixed placeholder when unsafe/unparseable
     */
    public function redactEnvelope(string $xml): string
    {
        // Empty input must short-circuit: on PHP 8 DOMDocument::loadXML('') throws a
        // ValueError rather than returning false, which would escape this method uncaught.
        if ($xml === '' || strlen($xml) > self::MAX_ENVELOPE_BYTES || stripos($xml, '<!DOCTYPE') !== false) {
            return self::ENVELOPE_UNPARSEABLE;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);

        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return self::ENVELOPE_UNPARSEABLE;
        }

        $xpath = new DOMXPath($document);
        foreach (array_keys($this->sensitiveFields()) as $field) {
            $nodes = $xpath->query('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "'
                . $field . '"]');

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $node->nodeValue = 'xxxx';
            }
        }

        // Blank every *Result element. The functional result payload lives, unredacted,
        // in the outcome's raw_result for the parser; a diagnostic envelope must not also
        // carry it -- especially the bulk dataset, whose Consumer_Number/InstitutionID sit
        // inside a CDATA block that element-name matching above cannot reach.
        $results = $xpath->query('//*[substring(local-name(), string-length(local-name()) - 5) = "Result"]');
        if ($results !== false) {
            foreach ($results as $node) {
                $node->nodeValue = 'xxxx';
            }
        }

        $redacted = $document->saveXML();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return is_string($redacted) ? $redacted : self::ENVELOPE_UNPARSEABLE;
    }

    /**
     * Create a non-PII correlation id for diagnostic stitching.
     *
     * @return string Short trace id
     */
    public function traceId(): string
    {
        static $counter = 0;
        $counter++;

        return 'kp_' . substr(hash('sha256', microtime(true) . ':' . getmypid() . ':' . $counter), 0, 16);
    }

    /**
     * Build the canonical operational log field set from already-safe inputs.
     *
     * @param string $operation SOAP operation name
     * @param string $redacted_trace_id Redacted correlation id
     * @param int|null $voucher_id Voucher id when the caller has one
     * @param array $request_summary Redacted request fields
     * @param array $response_summary Tokenized response facts
     * @param string|null $error_class Error class or null on success
     * @param int|null $duration_ms Transport attempt duration
     * @param int $attempt One-based transport attempt index
     * @return array Canonical log fields
     */
    public static function operationLogFields(
        string $operation,
        string $redacted_trace_id,
        ?int $voucher_id,
        array $request_summary,
        array $response_summary,
        ?string $error_class,
        ?int $duration_ms,
        int $attempt = 1
    ): array {
        unset($request_summary['raw_result'], $request_summary['raw_envelope']);
        unset($response_summary['raw_result'], $response_summary['raw_envelope']);
        $request_summary = self::dropOperationalLogCredentialKeys($request_summary);

        return [
            'operation' => $operation,
            'redacted_trace_id' => $redacted_trace_id,
            'voucher_id' => $voucher_id,
            'request_summary' => $request_summary,
            'response_summary' => [
                'response_present' => (bool) ($response_summary['response_present'] ?? false),
                'result_present' => (bool) ($response_summary['result_present'] ?? false),
                'result_code' => self::safeResultCode($response_summary['result_code'] ?? null),
                'fault' => self::logSafeFaultToken($response_summary['fault'] ?? null, $error_class),
            ],
            'error_class' => $error_class,
            'duration_ms' => $duration_ms,
            'attempt' => max(1, $attempt),
        ];
    }

    /**
     * Collapse provider/transport faults into bounded tokens safe for logs.
     *
     * @param string|null $fault Fault text or a pre-tokenized value
     * @param string|null $error_class Transport error class
     * @param bool $response_present True when a response envelope/body arrived
     * @return string|null Log-safe fault token
     */
    public static function logSafeFaultToken(?string $fault, ?string $error_class, bool $response_present = false): ?string
    {
        if ($fault === null || $fault === '') {
            return $error_class === 'timeout' ? 'timeout' : null;
        }

        $fault = (string) $fault;
        if (in_array($fault, [
            'timeout',
            'transport_error',
            'provider_fault',
            'provider_fault_with_response',
            'invalid_wsdl',
            'xml_fault_redacted',
        ], true)) {
            return $fault;
        }

        if (stripos($fault, 'wsdl') !== false && stripos($fault, 'invalid') !== false) {
            return 'invalid_wsdl';
        }

        if ($error_class === 'timeout') {
            return 'timeout';
        }

        if (preg_match('/[<>]|Envelope|Header|Body|[A-Za-z]+Result|userName|password|InstitutionID|CNIC|Mobile|Email/i', $fault) === 1
            || preg_match('/\b\d{5}-?\d{7}-?\d\b|\b03\d{9}\b|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $fault) === 1
        ) {
            return strpos($fault, '<') !== false || strpos($fault, '>') !== false
                ? 'xml_fault_redacted'
                : 'provider_fault';
        }

        if ($error_class === 'transport_error') {
            return 'transport_error';
        }

        return $response_present ? 'provider_fault_with_response' : 'provider_fault';
    }

    /**
     * Keep only a non-secret two-character operation result code.
     *
     * @param mixed $code Candidate result code
     * @return string|null Safe result code
     */
    private static function safeResultCode($code): ?string
    {
        if (!is_scalar($code)) {
            return null;
        }

        $code = (string) $code;

        return preg_match('/^[A-Za-z0-9]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Remove credential key names that should not appear in persisted logs.
     *
     * @param array $data Redacted request data
     * @return array Request data without credential-key fields
     */
    private static function dropOperationalLogCredentialKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/^(?:userName|username|password|InstitutionID|institution_id)$/i', (string) $key) === 1) {
                unset($data[$key]);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::dropOperationalLogCredentialKeys($value);
            }
        }

        return $data;
    }

    /**
     * Masks sensitive values recursively.
     *
     * @param array $data Data to redact
     * @param array $mask_fields Lowercase lookup of sensitive keys
     * @param string $mask_char Mask character
     * @param int $unmask_length Number of leading/trailing characters to leave unmasked
     * @return array Redacted data
     */
    private function maskDataRecursive(
        array $data,
        array $mask_fields,
        string $mask_char = 'x',
        int $unmask_length = 0
    ): array {
        foreach ($data as $key => $value) {
            // Check the key first: a sensitive key holding an array (e.g. a credential
            // list) must mask every leaf beneath it, not recurse and leak non-sensitive
            // child keys.
            if (array_key_exists(strtolower((string) $key), $mask_fields)) {
                $rule = $mask_fields[strtolower((string) $key)];
                $data[$key] = is_array($value)
                    ? $this->maskEverything($value, $rule, $mask_char)
                    : $this->maskValue($value, $rule, $mask_char, $unmask_length);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->maskDataRecursive($value, $mask_fields, $mask_char, $unmask_length);
            }
        }

        return $data;
    }

    /**
     * Mask every leaf value beneath a sensitive subtree.
     *
     * @param array $data Subtree stored under a sensitive key
     * @param mixed $rule Rule settings or a field marker
     * @param string $mask_char Mask character
     * @return array Fully masked subtree
     */
    private function maskEverything(array $data, $rule, string $mask_char): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value)
                ? $this->maskEverything($value, $rule, $mask_char)
                : $this->maskValue($value, $rule, $mask_char, 0);
        }

        return $data;
    }

    /**
     * Masks the given value using a set of rules.
     *
     * @param mixed $value Value to mask
     * @param mixed $rule Rule settings or a field marker
     * @param string $mask_char Default mask character
     * @param int $unmask_length Default unmask length
     * @return mixed Masked value, with null preserved
     */
    private function maskValue($value, $rule, string $mask_char, int $unmask_length)
    {
        if ($value === null) {
            return null;
        }

        // A value that cannot be cast to string (a non-stringable object) still must not
        // surface raw; collapse it to a fixed token rather than throwing on the cast.
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return 'xxxx';
        }

        $value = (string) $value;
        $mask = $mask_char;
        if (is_array($rule) && isset($rule['char'])) {
            $mask = $rule['char'];
        }

        $unmask = $unmask_length;
        if (is_array($rule) && isset($rule['length'])) {
            $unmask = $rule['length'];
        }

        if ($unmask < 0) {
            $unmask_value = substr($value, $unmask);
            $mask_value = substr($value, 0, $unmask);
            $value = str_repeat($mask, strlen($mask_value)) . $unmask_value;
        } elseif ($unmask > 0) {
            $unmask_value = substr($value, 0, $unmask);
            $mask_value = substr($value, $unmask);
            $value = $unmask_value . str_repeat($mask, strlen($mask_value));
        } else {
            $value = str_repeat($mask, strlen($value));
        }

        return $value;
    }

    /**
     * Recursively gather raw values stored under sensitive keys.
     *
     * @param array $data Data to scan
     * @param array $mask_fields Lowercase lookup of sensitive keys
     * @param array $values Accumulator of sensitive string values (by reference)
     * @return void
     */
    private function collectSensitiveValues(array $data, array $mask_fields, array &$values): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->collectSensitiveValues($value, $mask_fields, $values);
                continue;
            }

            if ($value === null || is_bool($value)
                || (is_object($value) && !method_exists($value, '__toString'))) {
                continue;
            }

            if (array_key_exists(strtolower((string) $key), $mask_fields)) {
                $string = (string) $value;
                if ($string !== '') {
                    $values[] = $string;
                }
            }
        }
    }

    /**
     * Return the case-insensitive sensitive field lookup.
     *
     * @return array Sensitive field lookup
     */
    private function sensitiveFields(): array
    {
        if ($this->sensitive_lookup === null) {
            $this->sensitive_lookup = [];
            foreach ($this->sensitive_fields as $field) {
                $this->sensitive_lookup[strtolower($field)] = $field;
            }
        }

        return $this->sensitive_lookup;
    }
}
