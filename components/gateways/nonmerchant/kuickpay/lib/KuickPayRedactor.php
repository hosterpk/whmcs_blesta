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
        if (strlen($xml) > self::MAX_ENVELOPE_BYTES || stripos($xml, '<!DOCTYPE') !== false) {
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
            if (is_array($value)) {
                $data[$key] = $this->maskDataRecursive($value, $mask_fields, $mask_char, $unmask_length);
                continue;
            }

            if (array_key_exists(strtolower((string) $key), $mask_fields)) {
                $data[$key] = $this->maskValue($value, $mask_fields[strtolower((string) $key)], $mask_char, $unmask_length);
            }
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
