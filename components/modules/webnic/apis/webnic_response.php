<?php
/**
 * WebNIC API response normalizer.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicResponse
{
    /**
     * @var array|null Decoded WebNIC response body
     */
    private $body;

    /**
     * @var int HTTP status code returned by the transport
     */
    private $http_status;

    /**
     * @var string|null Transport outcome descriptor
     */
    private $transport_outcome;

    /**
     * Initializes the WebNIC response wrapper.
     *
     * @param mixed $decoded_body Decoded JSON body from WebNIC, or null
     * @param int $http_status HTTP status code
     * @param string|null $transport_outcome null, retryable, or indeterminate
     */
    public function __construct($decoded_body, int $http_status = 200, $transport_outcome = null)
    {
        $this->body = $this->normalizeBody($decoded_body);
        $this->http_status = $http_status;
        $this->transport_outcome = $transport_outcome;
    }

    /**
     * Returns whether the response is a successful WebNIC envelope.
     *
     * @return bool True when the envelope is successful
     */
    public function success(): bool
    {
        return $this->transport_outcome === null
            && isset($this->body['code'])
            && $this->body['code'] === '1000';
    }

    /**
     * Returns the response data envelope, if present.
     *
     * @return mixed|null WebNIC data payload
     */
    public function data()
    {
        return $this->body['data'] ?? null;
    }

    /**
     * Returns the safe display message for this response.
     *
     * @return string Safe provider success message or localized error key
     */
    public function message(): string
    {
        if ($this->success()) {
            return (string)($this->body['message'] ?? '');
        }

        $sub_code = $this->errorSubCode();
        $map = Configure::get('Webnic.error_class_map') ?: [];
        if ($sub_code !== null && isset($map[$sub_code])) {
            return Language::_('Webnic.!error.' . $sub_code, true);
        }

        return Language::_('Webnic.!error.unknown', true);
    }

    /**
     * Classifies the response error.
     *
     * @return string|null retryable, terminal, indeterminate, or null on success
     */
    public function errorClass()
    {
        if ($this->success()) {
            return null;
        }

        if ($this->transport_outcome !== null) {
            return $this->transport_outcome;
        }

        if ($this->http_status >= 500 && $this->http_status <= 599) {
            return 'retryable';
        }

        if ($this->http_status === 401) {
            return 'retryable';
        }

        $map = Configure::get('Webnic.error_class_map') ?: [];
        $sub_code = $this->errorSubCode();
        if ($sub_code !== null && isset($map[$sub_code])) {
            return $map[$sub_code];
        }

        $code = $this->body['code'] ?? null;
        if ($code !== null && isset($map[$code])) {
            return $map[$code];
        }

        return $map['unknown'] ?? 'indeterminate';
    }

    /**
     * Classifies a Register response into the addService return contract (pure, FR17).
     *
     * No I/O — mirrors the decideAvailability/errorClass convention so the INV-4
     * return contract is pinned by a truth table. The CALLER (Webnic::addService)
     * owns the transition() calls and the Input error; this stays pure.
     *
     *  - !success()                      -> 'failed' (+ error_class/error_key/message);
     *  - success + pendingOrder:true     -> 'pending' (order_id from data.pendingOrderId);
     *  - success + pendingOrder:false    -> 'active' (dtexpire from data.dtexpire);
     *  - success missing pendingOrder    -> 'failed' terminal (malformed-success trap):
     *    a code=1000 envelope without the required data is NOT a usable success — do
     *    NOT proceed; surface terminal (deferred from WN-3-0).
     *
     * pendingOrderId is parsed defensively and passed through as-is; the multi-TLD pending
     * shape is OTE-captured (WN-3-6: `.nl` returns an INTEGER pendingOrderId — register_pending.json).
     * A pending outcome is NEVER downgraded to failed, because returning an Input error for an
     * async-pending order is forbidden (INV-4).
     *
     * @param WebnicResponse $resp The final classified Register response
     * @return array Decision: [
     *  'outcome'     => 'active'|'pending'|'failed',
     *  // active/pending:
     *  'order_id'    => string|null, // data.pendingOrderId (pending only)
     *  'dtexpire'    => string|null, // data.dtexpire ISO string
     *  // failed:
     *  'error_class' => string|null, // retryable|terminal|indeterminate
     *  'error_key'   => string,      // language-key suffix under Webnic.!error.*
     *  'message'     => string       // localized provider/error message
     * ]
     */
    public static function parseRegister(WebnicResponse $resp): array
    {
        if (!$resp->success()) {
            $class = $resp->errorClass();
            $body = $resp->body();
            $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
            $code = is_array($body) ? ($body['code'] ?? null) : null;
            $error_key = $class === 'terminal'
                ? ($sub_code ?: ($code ?: 'register_failed'))
                : 'register_failed';

            return [
                'outcome' => 'failed',
                'error_class' => $class,
                'error_key' => $error_key,
                'message' => $resp->message(),
            ];
        }

        $data = $resp->data();

        // Malformed-success trap: a success envelope MUST carry pendingOrder (the
        // synchronous .xyz capture has exactly {pendingOrder,dtexpire}). Missing it
        // is a terminal malformed-success — never treat it as a registration.
        if (!is_array($data) || !array_key_exists('pendingOrder', $data) || !is_bool($data['pendingOrder'])) {
            return [
                'outcome' => 'failed',
                'error_class' => 'terminal',
                'error_key' => 'register_malformed',
                'message' => Language::_('Webnic.!error.register_malformed', true),
            ];
        }

        if ($data['pendingOrder'] === true) {
            return [
                'outcome' => 'pending',
                'order_id' => $data['pendingOrderId'] ?? null,
                'dtexpire' => $data['dtexpire'] ?? null,
            ];
        }

        if (!isset($data['dtexpire']) || !is_string($data['dtexpire']) || trim($data['dtexpire']) === '') {
            return [
                'outcome' => 'failed',
                'error_class' => 'terminal',
                'error_key' => 'register_malformed',
                'message' => Language::_('Webnic.!error.register_malformed', true),
            ];
        }

        return [
            'outcome' => 'active',
            'order_id' => null,
            'dtexpire' => $data['dtexpire'] ?? null,
        ];
    }

    /**
     * Classifies a whois-privacy/toggle response into "privacy is now in the desired state" (WN-3-5).
     *
     * Pure, I/O-free (mirrors parseRegister). A `code:'1000'` is success. WebNIC reports an
     * already-in-state toggle as `code:2400 / subCode:DOM2400` with a message naming the current
     * state ("...already active" / "...already inactive"); that is an IDEMPOTENT success ONLY when
     * the current state matches the intended direction. Any other non-success is a real failure.
     *
     * @param WebnicResponse $resp The toggle response
     * @param bool $active The intended direction (true = enable, false = disable)
     * @return bool True when WHOIS privacy ends up in the intended state
     */
    public static function privacyToggleSucceeded(WebnicResponse $resp, bool $active): bool
    {
        if ($resp->success()) {
            return true;
        }

        $body = $resp->body();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $message = is_array($body) ? strtolower((string)($body['error']['message'] ?? '')) : '';

        if ($sub_code === 'DOM2400') {
            if ($active && strpos($message, 'already active') !== false) {
                return true;
            }
            if (!$active && strpos($message, 'already inactive') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the HTTP status code.
     *
     * @return int HTTP status code
     */
    public function status(): int
    {
        return $this->http_status;
    }

    /**
     * Returns the decoded body.
     *
     * @return array|null Decoded body
     */
    public function body()
    {
        return $this->body;
    }

    /**
     * Returns the provider business error subcode.
     *
     * @return string|null Error subcode, if any
     */
    private function errorSubCode()
    {
        if (!is_array($this->body) || !is_array($this->body['error'] ?? null)) {
            return null;
        }

        $sub_code = $this->body['error']['subCode'] ?? null;
        if ($sub_code === null) {
            return null;
        }

        // Normalize: a padded subcode (" DOM2400 ") must still match the error_class_map by code;
        // treat empty/whitespace-only as no subcode (round-1 P5 — subCode-by-code, trimmed).
        $sub_code = trim((string) $sub_code);

        return $sub_code === '' ? null : $sub_code;
    }

    /**
     * Normalizes decoded arrays/objects to arrays.
     *
     * @param mixed $body Decoded WebNIC response body
     * @return array|null Normalized array body
     */
    private function normalizeBody($body)
    {
        if ($body === null || is_array($body)) {
            return $body;
        }

        if (is_object($body)) {
            return $this->normalizeArray((array)$body);
        }

        return null;
    }

    /**
     * Recursively normalizes objects nested inside arrays.
     *
     * @param array $data Decoded response data
     * @return array Normalized data
     */
    private function normalizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $data[$key] = $this->normalizeArray((array)$value);
            } elseif (is_array($value)) {
                $data[$key] = $this->normalizeArray($value);
            }
        }

        return $data;
    }
}
