<?php
/**
 * WebNIC connectivity-result classifier.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'webnic_response.php';

/**
 * Pure, table-driven mapper from a normalized WebNIC response to one of the four
 * connectivity-test outcomes. No Language, no Configure, no $_SERVER, no I/O and
 * no live call — the IP lookup and localization happen later in
 * Webnic::testConnection, never here, so this class stays unit-testable in the
 * pure (no-Blesta) test container (AR23/NFR13).
 *
 * CAPTURED CONTRACT (refined 2026-06-16 from a live OTE run). The real "IP not
 * allowlisted" and "bad credential" shapes — UNOBSERVED at Gate-1 — were captured
 * against oteapi.webnic.cc and are now keyed off explicitly:
 *  - `ip_allowlist` has TWO real shapes: (a) a transport-level non-reply
 *    (connection refused/timeout -> status 0); AND (b) an HTTP 403 envelope WebNIC
 *    returns to an unallowlisted source — `DOM0004` "Forbidden access with current
 *    ip address" (domain API) / `RES0004` "Forbidden access" (reseller API). WebNIC
 *    does NOT refuse the connection; it answers 403, so arm (a) alone missed it.
 *  - `invalid_credential` has the synthetic `auth_failed` / HTTP 401 shapes PLUS
 *    the live token-mint signature `RES5001` "Invalid username" (returned HTTP 500).
 * NOTE: the token-mint endpoint is NOT IP-gated — minting succeeds even from an
 * unallowlisted IP, and the IP block surfaces on the subsequent operation read
 * (which is why Webnic::testConnection issues a real `domain/v2/query`, not a
 * mint-only probe). The Task-3 copy must still stay helpful under any residual
 * misclassification (it names creds + environment + IP allowlist).
 *
 * INVARIANT (why arm (a) is safe before arm (b)): a genuine success never has
 * status() === 0 — the transport layer stamps a non-null transport_outcome on
 * every no-reply (WebnicApi::transportOutcome), so success() is always false
 * when status() === 0. The only response that would slip through as a false pass
 * (code:'1000' WITH status:0 AND a null outcome) is unreachable from the real
 * client; do not construct it as a "valid pass" fixture.
 */
class WebnicConnectivity
{
    /**
     * @var string Token mint + read both succeeded.
     */
    public const PASS = 'pass';

    /**
     * @var string No HTTP reply — likely this server's IP is not allowlisted.
     */
    public const IP_ALLOWLIST = 'ip_allowlist';

    /**
     * @var string Server answered and rejected the connection.
     */
    public const INVALID_CREDENTIAL = 'invalid_credential';

    /**
     * @var string Any other failure (5xx, unexpected 4xx) — never a silent pass.
     */
    public const FAILURE = 'failure';

    /**
     * Classifies a connectivity-test response into a single outcome constant.
     *
     * Decision order (return on first match):
     *  (a) success envelope                                       -> pass
     *  (b) no HTTP reply (status 0, transport refused/timeout)    -> ip_allowlist
     *  (c) IP-block envelope (DOM0004/RES0004 / "ip address" msg)  -> ip_allowlist
     *  (d) credential rejection (auth_failed / 401 / RES5001)     -> invalid_credential
     *  (e) anything else                                          -> failure
     *
     * @param WebnicResponse $response Normalized connectivity-test response
     * @return string One of self::PASS|IP_ALLOWLIST|INVALID_CREDENTIAL|FAILURE
     */
    public static function classify(WebnicResponse $response): string
    {
        if ($response->success()) {
            return self::PASS;
        }

        // (b) Transport-level non-reply (connection refused/timeout, DNS/SSL) — a
        // firewall IP block can surface this way (no HTTP response at all).
        if ($response->status() === 0) {
            return self::IP_ALLOWLIST;
        }

        $body = $response->body();
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $message = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';

        // (c) IP-allowlist rejection (captured from live OTE): WebNIC answers an
        // unallowlisted source with an HTTP 403 envelope, not a connection refusal —
        // subCode DOM0004 "Forbidden access with current ip address" (domain API) or
        // RES0004 "Forbidden access" (reseller API). The message guard catches any
        // future IP-worded subcode.
        if ($sub_code === 'DOM0004'
            || $sub_code === 'RES0004'
            || stripos($message, 'ip address') !== false
        ) {
            return self::IP_ALLOWLIST;
        }

        // (d) Credential rejection: a synthetic auth_failed, an HTTP 401, or the live
        // token-mint signature subCode RES5001 "Invalid username".
        if ($code === 'auth_failed'
            || $sub_code === 'auth_failed'
            || $sub_code === 'RES5001'
            || $response->status() === 401
            || stripos($message, 'invalid username') !== false
            || stripos($message, 'invalid password') !== false
        ) {
            return self::INVALID_CREDENTIAL;
        }

        return self::FAILURE;
    }
}
