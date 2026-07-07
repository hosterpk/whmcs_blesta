<?php
/**
 * WebNIC API client.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'webnic_response.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'redactor.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'webnic_token_store.php';

use Webnic\Support\Redactor;
use Webnic\TokenStore;

class WebnicApi
{
    private const PROD_URL = 'https://api.webnic.cc';
    private const OTE_URL = 'https://oteapi.webnic.cc';
    private const TOKEN_PATH = '/reseller/v2/api-user/token';
    private const REFRESH_MARGIN = 600;
    private const MAX_RETRIES = 2;

    /**
     * @var int
     */
    private $module_row_id;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var string
     */
    private $environment;

    /**
     * @var mixed Duck-typed token store
     */
    private $store;

    /**
     * @var callable
     */
    private $transport;

    /**
     * @var int|callable|null
     */
    private $now;

    /**
     * @var callable
     */
    private $log_sink;

    /**
     * @var bool
     */
    private $transport_injected;

    /**
     * @var array Last request context
     */
    private $last_request = ['url' => null, 'args' => null];

    /**
     * Sets the connection details.
     *
     * @param int $module_row_id Module row id used to scope the token cache
     * @param string $username WebNIC API username
     * @param string $secret WebNIC API secret
     * @param string $environment production or ote
     * @param mixed $store Duck-typed TokenStore: get/save/delete
     * @param callable|null $transport Injectable HTTP transport
     * @param int|callable|null $now Fixed unix timestamp or callable clock
     * @param callable|null $log_sink Structured log sink
     */
    public function __construct(
        $module_row_id,
        $username,
        $secret,
        $environment,
        $store,
        callable $transport = null,
        $now = null,
        callable $log_sink = null
    ) {
        $this->module_row_id = (int)$module_row_id;
        $this->username = $username;
        $this->secret = $secret;
        $this->environment = $environment;
        $this->store = $store;
        $this->transport = $transport ?: [$this, 'curlTransport'];
        $this->transport_injected = $transport !== null;
        $this->now = $now;
        $this->log_sink = $log_sink ?: function ($level, array $context): void {
        };
    }

    /**
     * Submits an authenticated request to WebNIC.
     *
     * @param string $command API command path
     * @param array $args JSON request payload
     * @param string $method HTTP method
     * @return WebnicResponse Normalized WebNIC response
     */
    public function submit($command, array $args = [], $method = 'POST')
    {
        $method = strtoupper($method);
        $response = $this->submitWithTokenRefresh($command, $args, $method);

        return $this->retryResponse(
            $response,
            function () use ($command, $args, $method) {
                return $this->submitWithTokenRefresh($command, $args, $method);
            }
        );
    }

    /**
     * Returns the details of the last request made, scrubbed of secrets.
     *
     * The raw last request can carry the mint password or sensitive command
     * args (epp/auth codes); callers commonly feed this straight into a Blesta
     * module log, so it must cross the redaction boundary before leaving apis/.
     *
     * @return array Scrubbed last request data
     */
    public function lastRequest()
    {
        return Redactor::scrub($this->last_request);
    }

    /**
     * Sends an authenticated request, refreshing once on a 401 response.
     *
     * @param string $command API command path
     * @param array $args JSON request payload
     * @param string $method HTTP method
     * @return WebnicResponse Normalized response
     */
    private function submitWithTokenRefresh($command, array $args, $method)
    {
        $token = $this->getToken();
        if (isset($token['response'])) {
            return $token['response'];
        }

        $response = $this->sendAuthenticatedRequest($command, $args, $method, $token['token']);
        if ($response->status() !== 401) {
            return $response;
        }

        $this->store->delete($this->module_row_id);

        $token = $this->mintToken();
        if (isset($token['response'])) {
            return $token['response'];
        }

        $response = $this->sendAuthenticatedRequest($command, $args, $method, $token['token']);
        if ($response->status() === 401) {
            // A 401 after a clean re-auth is a terminal authorization failure
            // (bad scope / IP not allowlisted) — do not re-auth twice.
            return $this->authFailureResponse();
        }

        return $response;
    }

    /**
     * Returns a cached token, or mints and stores a fresh one.
     *
     * @return array token or response
     */
    private function getToken(): array
    {
        $cached = $this->store->get($this->module_row_id);
        $token = $this->readCachedValue($cached, 'token');
        $expires_at = $this->readCachedValue($cached, 'expires_at');

        if ($token !== null
            && $expires_at !== null
            && !TokenStore::needsRefresh((int)$expires_at, $this->currentTime(), self::REFRESH_MARGIN)
        ) {
            return ['token' => $token];
        }

        return $this->mintToken();
    }

    /**
     * Mints and stores a fresh bearer token.
     *
     * @return array token or response
     */
    private function mintToken(): array
    {
        // Minting is retried only by submit()'s single outer budget; wrapping a
        // second retryResponse here would multiply the budgets (up to 3x3 token
        // POSTs) into a retry storm against the auth endpoint.
        $response = $this->sendRequest(
            'POST',
            $this->tokenUrl(),
            ['Content-Type' => 'application/json'],
            [
                'username' => $this->username,
                'password' => $this->secret,
            ]
        );

        if (!$response->success()) {
            if ($response->status() === 401) {
                return ['response' => $this->authFailureResponse()];
            }

            return ['response' => $response];
        }

        $data = $response->data();
        $token = $data['access_token'] ?? null;
        $expires_in = $data['expires_in'] ?? null;

        if ($token === null || $expires_in === null || (int)$expires_in <= 0) {
            // A non-positive TTL would mint a token already inside the refresh
            // window, re-minting on every call; treat it as unusable.
            return ['response' => $this->authFailureResponse()];
        }

        $expires_at = $this->currentTime() + (int)$expires_in;
        $this->store->save($this->module_row_id, $token, $expires_at);

        return ['token' => $token];
    }

    /**
     * Sends a bearer-authenticated request.
     *
     * @param string $command API command path
     * @param array $args JSON request payload
     * @param string $method HTTP method
     * @param string $token Bearer token
     * @return WebnicResponse Normalized response
     */
    private function sendAuthenticatedRequest($command, array $args, $method, $token)
    {
        return $this->sendRequest(
            $method,
            $this->buildUrl($command),
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            $args
        );
    }

    /**
     * Sends a JSON request through the configured transport.
     *
     * @param string $method HTTP method
     * @param string $url Absolute request URL
     * @param array $headers Request headers
     * @param array $payload Request payload
     * @return WebnicResponse Normalized response
     */
    private function sendRequest($method, $url, array $headers, array $payload)
    {
        $method = strtoupper($method);
        $transport_url = $url;
        $body = $method === 'GET' ? '' : $this->encodeJson($payload);

        if ($method === 'GET' && !empty($payload)) {
            $transport_url .= '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        }

        $this->last_request = [
            'url' => $transport_url,
            'args' => $payload,
        ];

        try {
            $result = call_user_func($this->transport, $method, $transport_url, $headers, $body);
        } catch (\Throwable $e) {
            $result = [
                'body' => null,
                'status' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $raw_body = $result['body'] ?? null;
        $status = (int)($result['status'] ?? 0);
        $error = $result['error'] ?? null;
        $errno = $result['errno'] ?? null;
        $decoded_body = $this->decodeBody($raw_body);

        $this->log('debug', [
            'request' => [
                'method' => $method,
                'url' => $transport_url,
                'headers' => $headers,
                'body' => $payload,
            ],
            'response' => [
                'status' => $status,
                'body' => $decoded_body,
            ],
            'error' => $error,
        ]);

        return new WebnicResponse(
            $decoded_body,
            $status,
            $this->transportOutcome($error, $status, $raw_body, $errno)
        );
    }

    /**
     * Retries retryable transport/5xx responses.
     *
     * @param WebnicResponse $response Initial response
     * @param callable $callback Retry callback
     * @return WebnicResponse Final response
     */
    private function retryResponse(WebnicResponse $response, callable $callback)
    {
        $attempt = 0;
        while ($attempt < self::MAX_RETRIES && $this->shouldRetry($response)) {
            $attempt++;
            $this->backoff($attempt);
            $response = $callback();
        }

        return $response;
    }

    /**
     * Determines whether the general retry budget applies.
     *
     * @param WebnicResponse $response Response to inspect
     * @return bool True when the request should be retried
     */
    private function shouldRetry(WebnicResponse $response): bool
    {
        return $response->status() !== 401 && $response->errorClass() === 'retryable';
    }

    /**
     * Applies the configured retry backoff.
     *
     * @param int $attempt Retry attempt number
     */
    private function backoff($attempt)
    {
        if (!$this->transport_injected) {
            sleep($attempt);
        }
    }

    /**
     * Default cURL transport.
     *
     * @param string $method HTTP method
     * @param string $url Absolute URL
     * @param array $headers Request headers
     * @param string $body JSON body
     * @return array Transport result
     */
    private function curlTransport($method, $url, array $headers, $body): array
    {
        $header_lines = [];
        foreach ($headers as $key => $value) {
            $header_lines[] = $key . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Blesta)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // WebNIC IP allowlisting is per source address and its dashboard accepts
        // IPv4 only, but api/oteapi.webnic.cc are Cloudflare dual-stack (A + AAAA).
        // Left to happy-eyeballs, curl prefers IPv6, so operations arrive from the
        // host's (unallowlistable) IPv6 egress and WebNIC rejects them (403 DOM0004,
        // surfaced as the "IP not allowlisted" connectivity failure). Pin every call
        // to the allowlisted IPv4 path. Verified on beta 2026-06-21: default egress
        // -> reject, forced IPv4 -> code:"1000" pass.
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // WN-4-1 (C9/T1): capture the libcurl errno (before curl_close) so retryability is
        // classified by the robust, locale-independent error code, not English message needles.
        $errno = curl_errno($ch);
        $error = null;
        if ($response === false) {
            $error = curl_error($ch);
            $response = null;
        }

        curl_close($ch);

        return [
            'body' => $response,
            'status' => $status,
            'error' => $error,
            'errno' => $errno,
        ];
    }

    /**
     * Builds an absolute API URL from a command path.
     *
     * @param string $command API command path
     * @return string Absolute URL
     */
    private function buildUrl($command): string
    {
        return rtrim($this->baseUrl(), '/') . '/' . ltrim($command, '/');
    }

    /**
     * Returns the token endpoint URL.
     *
     * @return string Absolute token endpoint URL
     */
    private function tokenUrl(): string
    {
        return rtrim($this->baseUrl(), '/') . self::TOKEN_PATH;
    }

    /**
     * Returns the selected WebNIC base URL.
     *
     * @return string Base URL
     */
    private function baseUrl(): string
    {
        return $this->environment === 'ote' ? self::OTE_URL : self::PROD_URL;
    }

    /**
     * Returns the current unix timestamp.
     *
     * @return int Unix timestamp
     */
    private function currentTime(): int
    {
        if (is_callable($this->now)) {
            return (int)call_user_func($this->now);
        }

        if ($this->now !== null) {
            return (int)$this->now;
        }

        return time();
    }

    /**
     * Reads a cached token-store value from arrays or objects.
     *
     * @param mixed $cached Cached token row
     * @param string $key Row key
     * @return mixed|null Row value
     */
    private function readCachedValue($cached, $key)
    {
        if (is_array($cached) && array_key_exists($key, $cached)) {
            return $cached[$key];
        }

        if (is_object($cached) && isset($cached->{$key})) {
            return $cached->{$key};
        }

        return null;
    }

    /**
     * Encodes request payload as JSON.
     *
     * @param array $payload Request payload
     * @return string JSON payload
     */
    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload);

        return $json === false ? '{}' : $json;
    }

    /**
     * Decodes a raw response body.
     *
     * @param string|null $raw_body Raw response body
     * @return array|null Decoded body
     */
    private function decodeBody($raw_body)
    {
        if ($raw_body === null || $raw_body === '') {
            return null;
        }

        $decoded = json_decode($raw_body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Maps transport errors to response outcome markers.
     *
     * @param string|null $error Transport error
     * @param int $status HTTP status
     * @param string|null $body Raw body
     * @param int|null $errno The libcurl errno (WN-4-1 C9/T1), or null for an injected transport
     * @return string|null retryable, indeterminate, or null
     */
    private function transportOutcome($error, $status, $body, $errno = null)
    {
        // WN-4-1 (C9/T1, round-1 P9/X4): classify retryability by the robust libcurl errno FIRST,
        // independent of the error STRING. The production cURL path always sets a non-zero errno on a
        // transport fault even when curl_error() is momentarily empty, so the errno must not be gated
        // behind a non-empty message. The reconciler's backoff DEPENDS on this, and English message
        // needles drift across libcurl/locale.
        if ($errno !== null && (int) $errno !== 0) {
            return self::isRetryableCurlErrno((int) $errno) ? 'retryable' : 'indeterminate';
        }

        if ($error !== null && $error !== '') {
            // Fallback ONLY for an injected test transport that carries no errno: a coarse English
            // substring heuristic. The production path never reaches here (it always has an errno).
            $lower = strtolower($error);
            foreach (['timeout', 'timed out', 'could not connect', 'failed to connect', 'connection refused', 'resolve host'] as $needle) {
                if (strpos($lower, $needle) !== false) {
                    return 'retryable';
                }
            }

            return 'indeterminate';
        }

        if ($status === 0 && $body === null) {
            return 'indeterminate';
        }

        return null;
    }

    /**
     * Classifies a libcurl errno as a transient, retry-worthy connection error (WN-4-1 C9/T1).
     *
     * Only connection/transport-layer faults (resolve/connect/timeout/TLS-handshake/incomplete
     * read) are retryable — an HTTP response that arrived is classified by status/envelope, not here.
     * Codes are the stable libcurl CURLE_* numbers (locale-independent), used directly because the
     * CURLE_* PHP constants are not guaranteed defined without the cURL extension at parse time.
     *
     * @param int $errno The libcurl errno
     * @return bool True when the error is a transient connection fault
     */
    private static function isRetryableCurlErrno(int $errno): bool
    {
        return in_array($errno, [
            6,  // CURLE_COULDNT_RESOLVE_HOST
            7,  // CURLE_COULDNT_CONNECT
            28, // CURLE_OPERATION_TIMEDOUT
            35, // CURLE_SSL_CONNECT_ERROR
            52, // CURLE_GOT_NOTHING (server closed the connection without a reply)
            55, // CURLE_SEND_ERROR
            56, // CURLE_RECV_ERROR
        ], true);
    }

    /**
     * Returns a terminal internal authentication failure response.
     *
     * @return WebnicResponse Auth failure response
     */
    private function authFailureResponse()
    {
        return new WebnicResponse([
            'code' => 'auth_failed',
            'error' => [
                'subCode' => 'auth_failed',
            ],
        ], 400);
    }

    /**
     * Sends scrubbed payloads to the configured log sink.
     *
     * @param string $level Log level
     * @param array $context Structured context
     */
    private function log($level, array $context)
    {
        try {
            call_user_func($this->log_sink, $level, Redactor::scrub($context));
        } catch (\Throwable $e) {
            // Logging must not interrupt API control flow.
        }
    }
}
