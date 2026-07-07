<?php
/**
 * WebNIC secret redaction boundary.
 *
 * This is the only sanctioned pre-sink filter for WebNIC log, diagnostic, and
 * error payloads. Any request, response, header, or observability message bound
 * for a log must pass through scrub() first; logging a raw secret is a release
 * blocker.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic\Support;

class Redactor
{
    public const REDACTED = '[REDACTED]';

    // Story 1.4: append any further secret/token field live OTE surfaces.
    // WN-3-4a: `webnic_order_id` is the actual `webnic_orders` column name (INV-7); the
    // generic `order_id` key never matched it (normalizes to `webnicorderid`), so the
    // INV-9 records that carry it logged it raw. Listed explicitly so every sink — saga,
    // reconciler, and recovery — masks it at this single boundary (release blocker).
    // WN-3-6: `authInfo` (the domain EPP/auth-transfer code) surfaced live on OTE in the
    // order/info?id= pending-order body (`data.details.authInfo`); it is a secret and the
    // generic `authcode` key never matched it (normalizes to `authinfo` vs `authcode`).
    // Listed explicitly so any logged order/info — and every Epic-4 transfer flow that
    // carries an EPP code — masks it here (release blocker). normalizeKey() does an EXACT
    // normalized lookup, so this redacts `authInfo` but NOT the `needAuthInfo` rule flag
    // (normalizes to `needauthinfo`).
    private const SENSITIVE_KEYS = [
        'secret',
        'password',
        'jwt',
        'token',
        'authorization',
        'epp_code',
        'authcode',
        'authinfo',
        'order_id',
        'webnic_order_id',
        'transfer_id',
        'access_token',
    ];

    /**
     * Scrub sensitive values from arrays, strings, scalars, and objects.
     *
     * @param mixed $payload Payload headed to a log, diagnostic, or error sink
     * @return mixed Redacted payload with non-sensitive data preserved
     */
    public static function scrub($payload)
    {
        if (is_array($payload)) {
            return self::scrubArray($payload);
        }

        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return self::scrubString($payload);
        }

        if (is_object($payload)) {
            if (!method_exists($payload, '__toString')) {
                return self::REDACTED;
            }

            return self::scrubString((string) $payload);
        }

        return $payload;
    }

    /**
     * Redact sensitive keyed values recursively.
     *
     * @param array $data Structured payload data
     * @return array Redacted data
     */
    private static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $data[$key] = self::maskSensitiveValue($value);
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrubArray($value);
                continue;
            }

            if (is_string($value)) {
                $data[$key] = self::scrubString($value);
                continue;
            }

            if (is_object($value)) {
                $data[$key] = method_exists($value, '__toString')
                    ? self::scrubString((string) $value)
                    : self::REDACTED;
            }
        }

        return $data;
    }

    /**
     * Mask a value stored directly under a sensitive key.
     *
     * @param mixed $value Sensitive value or subtree
     * @return mixed Masked value with null preserved
     */
    private static function maskSensitiveValue($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return self::maskEverything($value);
        }

        return self::REDACTED;
    }

    /**
     * Mask every non-null leaf under a sensitive subtree.
     *
     * @param array $data Sensitive subtree
     * @return array Fully redacted subtree
     */
    private static function maskEverything(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                $data[$key] = null;
                continue;
            }

            $data[$key] = is_array($value)
                ? self::maskEverything($value)
                : self::REDACTED;
        }

        return $data;
    }

    /**
     * Redact embedded key=value, key: value, JSON key/value, and Authorization header fragments.
     *
     * @param string $value Raw string payload
     * @return string Redacted string payload
     */
    private static function scrubString(string $value): string
    {
        $value = preg_replace(
            '/(Authorization:[ \t]*).*/i',
            '$1' . self::REDACTED,
            $value
        );

        return preg_replace_callback(
            // WN-3-6: `auth[_-]?info` matches the EPP/auth-transfer code embedded in a STRING
            // value (error message, serialized transfer body, `authInfo=<epp>` query string) —
            // the array-KEY path is covered by SENSITIVE_KEYS, this completes the boundary. The
            // leading-boundary anchor keeps `needAuthInfo` (the rule flag) un-matched here too.
            '/(^|[?&{,\s"])((?:secret|password|jwt|token|epp[_-]?code|auth[_-]?code|auth[_-]?info|order[_-]?id|transfer[_-]?id|access[_-]?token)"?\s*[:=]\s*)("?)([^"&\s]+)\3/i',
            function ($matches) {
                return $matches[1] . $matches[2] . $matches[3] . self::REDACTED . $matches[3];
            },
            $value
        );
    }

    /**
     * Determine whether a key is sensitive after case/separator normalization.
     *
     * @param mixed $key Candidate array key
     * @return bool True when the key is sensitive
     */
    private static function isSensitiveKey($key): bool
    {
        $lookup = self::sensitiveLookup();

        return isset($lookup[self::normalizeKey($key)]);
    }

    /**
     * Normalize a key for exact case/separator-insensitive comparison.
     *
     * @param mixed $key Candidate key
     * @return string Normalized key
     */
    private static function normalizeKey($key): string
    {
        return str_replace(['_', '-'], '', strtolower((string) $key));
    }

    /**
     * Return the normalized sensitive-key lookup.
     *
     * @return array Sensitive key lookup
     */
    private static function sensitiveLookup(): array
    {
        static $lookup = null;

        if ($lookup === null) {
            $lookup = array_flip(array_map([self::class, 'normalizeKey'], self::SENSITIVE_KEYS));
        }

        return $lookup;
    }
}
