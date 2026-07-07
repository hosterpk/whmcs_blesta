<?php
/**
 * WebNIC domain command group.
 *
 * Home for the WebNIC `/domain/v2/*` read family that backs the Domain Manager's
 * availability surface: single-domain availability (this story), with bulk
 * availability (WN-2-5) and transfer eligibility / term validation (WN-2-6) added
 * to this same class later. Mirrors the logicboxes command-group structure (INV-2)
 * and WebnicPricing: a thin wrapper that translates a WebNIC op into a single
 * WebnicApi::submit() call, never reading raw JSON itself (that is WebnicResponse's
 * job). The pure decision helper below is I/O-free so it is unit-testable with no
 * Blesta bootstrap (AR23/NFR13).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicDomains
{
    /**
     * @var WebnicApi
     */
    private $api;

    /**
     * Sets the API to use for communication.
     *
     * @param WebnicApi $api The API to use for communication
     */
    public function __construct(WebnicApi $api)
    {
        $this->api = $api;
    }

    /**
     * Queries single-domain availability.
     *
     * WebNIC op: Query Domain (GET /domain/v2/query). The domain is passed through
     * to the `domainName` query param exactly as received (the response echoes the
     * `idn`/`punyCodeDomainName` form); bearer auth is handled by WebnicApi/
     * TokenStore. The path is given without a leading slash to match the existing
     * submit() convention (WebnicApi::buildUrl prepends the env base URL). This is
     * the same read testConnection already exercises live as its connectivity
     * sentinel; an availability read never charges.
     *
     * @param string $domain The fully-qualified domain to query
     * @return WebnicResponse Normalized WebNIC response whose data() carries the
     *  `available` boolean (plus orthogonal premium/online/landrush/idn flags)
     */
    public function queryDomain($domain)
    {
        return $this->api->submit('domain/v2/query', ['domainName' => $domain], 'GET');
    }

    /**
     * Submits a domain registration (FR16).
     *
     * WebNIC op: Register Domain (POST /domain/v2/register). The body is the observed
     * register field set (config/webnic.php field_map): domainName, term, nameservers,
     * the four *ContactId handles, and registrantUserId. The response is parsed by
     * WebnicResponse::parseRegister(); the caller (Webnic::addService) owns the
     * transition() calls and the INV-4 return contract — this stays thin.
     *
     * @param array $body The register request body
     * @return WebnicResponse Normalized WebNIC response
     */
    public function register(array $body)
    {
        return $this->api->submit('domain/v2/register', $body, 'POST');
    }

    /**
     * Reads a domain's registry record by name (FR18a reconcile-by-domain).
     *
     * WebNIC op: Get Domain Info (GET /domain/v2/info?domainName=). The idempotency
     * reconcile signal: a success envelope means the domain is already registered (do
     * not resubmit), a DOM2400 "Domain record not found" means it is definitively not
     * registered (safe to resubmit). The branch decision lives in the pure
     * decideReconcileRegister() helper.
     *
     * @param string $domainName The fully-qualified domain to read
     * @return WebnicResponse Normalized WebNIC response
     */
    public function info($domainName)
    {
        return $this->api->submit('domain/v2/info', ['domainName' => $domainName], 'GET');
    }

    /**
     * Updates the assigned nameservers for a registered domain.
     *
     * WebNIC op: Update Domain Nameserver (PUT /domain/v2/dns?domainName=). T0 OTE
     * capture proved `domainName` is a query parameter while `nameservers` is the JSON
     * body; sending domainName only in the body returns DOM4000. The success and
     * already-set envelopes are the standard code:1000, not a 3000 batch response.
     *
     * @param string $domainName The registered domain to update
     * @param array $nameservers The nameserver hostnames to assign
     * @return WebnicResponse Normalized WebNIC response
     */
    public function updateNameservers(string $domainName, array $nameservers): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/dns?domainName=' . rawurlencode($domainName),
            ['nameservers' => array_values($nameservers)],
            'PUT'
        );
    }

    /**
     * Toggles WHOIS privacy on a registered domain (WN-3-5, the §K ID-protection wire-up).
     *
     * WebNIC op: Toggle WHOIS Privacy (PUT /domain/v2/whois-privacy/toggle). Contract cracked +
     * verified on live OTE: body is exactly {active, domainName}; active:true enables, false
     * disables; success is code:1000. Privacy is a SEPARATE post-register operation — NOT a
     * register-body field and NOT /proxy. The already-in-state response (DOM2400) is classified
     * idempotently by WebnicResponse::privacyToggleSucceeded(); the caller (Webnic::addService)
     * runs this only on the post-success path so a failure never reverses a completed
     * registration (INV-4).
     *
     * @param string $domainName The registered domain
     * @param bool $active True to enable WHOIS privacy, false to disable
     * @return WebnicResponse Normalized WebNIC response
     */
    public function toggleWhoisPrivacy($domainName, $active)
    {
        return $this->api->submit(
            'domain/v2/whois-privacy/toggle',
            ['domainName' => $domainName, 'active' => (bool) $active],
            'PUT'
        );
    }

    /**
     * Updates a domain's live registrar status.
     *
     * WebNIC op: Update Domain Status (PUT /domain/v2/status?domainName=&status=).
     * WN-5.8a T0 proved both domainName and status are request parameters, not
     * JSON body fields; body-only form returns DOM4000. Lock uses
     * transfer_protected and unlock uses active.
     *
     * @param string $domainName The registered domain to update
     * @param string $status The target status (active|transfer_protected|name_protected)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function updateDomainStatus(string $domainName, string $status): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/status?domainName=' . rawurlencode($domainName) . '&status=' . rawurlencode($status),
            [],
            'PUT'
        );
    }

    /**
     * Sends authorization information to the registrant email address.
     *
     * WebNIC op: Send Authorization Information
     * (POST /domain/v2/auth-info/send?domainName=). WN-5.8a T0 proved the success
     * envelope carries data.recipient only, never the auth/EPP code.
     *
     * @param string $domainName The registered domain
     * @return WebnicResponse Normalized WebNIC response
     */
    public function sendAuthorizationInfo(string $domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/auth-info/send?domainName=' . rawurlencode($domainName),
            [],
            'POST'
        );
    }

    /**
     * Resets the domain authorization information.
     *
     * WebNIC op: Reset Authorization Information
     * (POST /domain/v2/auth-info/reset?domainName=). WN-5.8a T0 proved the success
     * envelope is simple code:1000 with no returned auth/EPP code.
     *
     * @param string $domainName The registered domain
     * @return WebnicResponse Normalized WebNIC response
     */
    public function resetAuthorizationInfo(string $domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/auth-info/reset?domainName=' . rawurlencode($domainName),
            [],
            'POST'
        );
    }

    /**
     * Submits a domain renewal for a term in years (FR23, WN-4-3).
     *
     * WebNIC op: Renew Domain (POST /domain/v2/renew). Contract cracked + verified on live OTE
     * (WN-4-3 T0, config/webnic.php provenance): the body is exactly {domainName, term} — BOTH are
     * JSON BODY fields (domainName is NOT a query-string param, unlike the resend op), and `term` is
     * the only accepted term key (renewYear/years/duration are all rejected with "'term' is
     * required"). The body carries NO auto-renew opt-in of any spelling (AC3/AR20/B6): WebNIC
     * auto-renew stays off so Blesta remains the renewal system-of-record. The success envelope is a
     * standard code:1000 carrying the new registry expiry (data.dtexpire, synchronous — NOT a
     * 3000-batch, no order id); the response is classified by decideRenew(). No state change (NFR5):
     * renew opens no order and runs no transition() — the caller (Webnic::performRenew) only submits
     * the term extension and refreshes the cached expiry.
     *
     * @param string $domainName The registered domain to renew
     * @param int $term The renewal term in years
     * @return WebnicResponse Normalized WebNIC response
     */
    public function renew(string $domainName, int $term): WebnicResponse
    {
        return $this->api->submit('domain/v2/renew', ['domainName' => $domainName, 'term' => $term], 'POST');
    }

    /**
     * Restores a domain from redemption grace.
     *
     * WebNIC op: Restore Domain (POST /domain/v2/restore). T0 OTE capture proved
     * both `domainName` and `agreeRestorePolicy` are JSON body fields; query-only
     * form returns DOM4002. The default policy flag is true because callers gate the
     * billable action before reaching this low-level command.
     *
     * @param string $domainName The domain to restore
     * @param array $vars Optional restore vars (`agreeRestorePolicy`)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function restore(string $domainName, array $vars = []): WebnicResponse
    {
        $agree = array_key_exists('agreeRestorePolicy', $vars)
            ? (bool) $vars['agreeRestorePolicy']
            : true;

        return $this->api->submit(
            'domain/v2/restore',
            ['domainName' => $domainName, 'agreeRestorePolicy' => $agree],
            'POST'
        );
    }

    /**
     * Deletes a registered domain at the registry.
     *
     * WebNIC op: Delete Domain (DELETE /domain/v2/delete?domainName=). T0 OTE capture proved
     * `domainName` is a query-string/request parameter, not a JSON body field; a DELETE with
     * {"domainName":...} in the body returns DOM4000. Keep the args empty and embed the
     * rawurlencoded domain in the command path so WebnicApi::submit() sends no domain body.
     *
     * @param string $domainName The registered domain to delete
     * @return WebnicResponse Normalized WebNIC response
     */
    public function delete(string $domainName): WebnicResponse
    {
        return $this->api->submit('domain/v2/delete?domainName=' . rawurlencode($domainName), [], 'DELETE');
    }

    /**
     * Queries transfer eligibility type for a single domain.
     *
     * WebNIC op: Query Transfer Type (GET /domain/v2/query-transfer-type). The
     * domain is passed through to the `domainName` query param exactly as received.
     * A successful response carries `data.transferType` with one of
     * `registrar_transfer`, `reseller_transfer`, or `domain_owner`.
     *
     * @param string $domain The fully-qualified domain to query
     * @return WebnicResponse Normalized WebNIC response whose data() carries the
     *  `transferType` enum
     */
    public function queryTransferType($domain)
    {
        return $this->api->submit('domain/v2/query-transfer-type', ['domainName' => $domain], 'GET');
    }

    /**
     * Encodes the availability branch decision from a final WebnicResponse (AR23).
     *
     * This is the testable home for the AC1/AC3 logic, so Webnic::checkAvailability()
     * stays thin glue around row resolution and the bool contract. It performs NO
     * I/O — no Language, no Cache, no log, no time() — only inspects the
     * already-classified response, and is the single guarantee behind FR41 ("never a
     * false taken"): the ONLY "taken" is a success envelope carrying
     * `data.available === false`. A malformed success envelope without a real
     * boolean availability flag is indeterminate and surfaced through the retry-safe
     * error side-channel rather than being coerced to "taken" or "available". A
     * non-success response (transport failure, 5xx, 401, business error) can never
     * be a success() — WebnicResponse stamps a non-null transport_outcome on every
     * no-reply — so it is always classified as a retryable / terminal /
     * indeterminate error, never coerced to "taken".
     *
     *  - success + available:true  -> class 'available' (registerable);
     *  - success + available:false -> class 'taken' (the only "taken", no error);
     *  - terminal (e.g. DOM2400 invalid domain, auth_failed) -> class 'terminal',
     *    surfaced through the mapped provider error key (a legitimate "can't
     *    register this" / configuration failure, distinct from "taken" and from
     *    "temporarily unavailable");
     *  - retryable (transport/5xx/401) / indeterminate (unknown business code) ->
     *    surfaced as "temporarily unavailable; try again", never a silent "taken".
     *
     * Classification itself is delegated to the single point WebnicResponse::
     * errorClass() — no bespoke classification is re-implemented here.
     *
     * @param WebnicResponse $resp The final classified WebNIC response
     * @return array Decision: [
     *  'available' => bool|null,  // true/false on success; null on any error
     *  'class' => string,         // available|taken|retryable|terminal|indeterminate
     *  'error_key' => string|null,// language-key suffix under Webnic.!error.* (null
     *                             //   for available/taken)
     *  'log_failure' => bool      // log the exchange (every non-success != silent)
     * ]
     */
    public static function decideAvailability(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            if (!is_array($data) || !array_key_exists('available', $data) || !is_bool($data['available'])) {
                return [
                    'available' => null,
                    'class' => 'indeterminate',
                    'error_key' => 'temporarily_unavailable',
                    'log_failure' => true,
                ];
            }

            // The ONLY path that yields a "taken" false: a success envelope whose
            // data.available is explicitly boolean false. Do not cast provider values
            // here: the string "false" is truthy in PHP and would become a false
            // "available".
            $available = $data['available'];

            return [
                'available' => $available,
                'class' => $available ? 'available' : 'taken',
                'error_key' => null,
                'log_failure' => false,
            ];
        }

        // Non-success: a retryable / terminal / indeterminate error, never "taken".
        // errorClass() is the single classification point (transport outcome, then
        // 5xx/401, then error.subCode/code via error_class_map, default
        // indeterminate). Preserve terminal mapped keys (DOM2400, auth_failed) so
        // auth/config failures are not misreported as invalid-domain input.
        $class = $resp->errorClass();
        $body = $resp->body();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        $error_key = $class === 'terminal'
            ? ($sub_code ?: ($code ?: 'temporarily_unavailable'))
            : 'temporarily_unavailable';

        return [
            'available' => null,
            'class' => $class,
            'error_key' => $error_key,
            'log_failure' => true,
        ];
    }

    /**
     * Encodes transfer eligibility from a final WebnicResponse (AR23).
     *
     * This is the testable decision point behind Webnic::checkTransferAvailability().
     * It performs no I/O and never guesses an eligible result: only the documented
     * `registrar_transfer` and `reseller_transfer` values return true. The
     * `domain_owner` response is a clean false with no error; malformed or unknown
     * success envelopes are indeterminate and should be retried by the user later.
     *
     * @param WebnicResponse $resp The final classified WebNIC response
     * @return array Decision: [
     *  'eligible' => bool|null,  // true/false on success; null on any error
     *  'class' => string,        // eligible|owned|retryable|terminal|indeterminate
     *  'error_key' => string|null,
     *  'log_failure' => bool
     * ]
     */
    public static function decideTransferEligibility(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            if (!is_array($data)
                || !array_key_exists('transferType', $data)
                || !is_string($data['transferType'])
            ) {
                return [
                    'eligible' => null,
                    'class' => 'indeterminate',
                    'error_key' => 'transfer_temporarily_unavailable',
                    'log_failure' => true,
                ];
            }

            if ($data['transferType'] === 'registrar_transfer' || $data['transferType'] === 'reseller_transfer') {
                return [
                    'eligible' => true,
                    'class' => 'eligible',
                    'error_key' => null,
                    'log_failure' => false,
                ];
            }

            if ($data['transferType'] === 'domain_owner') {
                return [
                    'eligible' => false,
                    'class' => 'owned',
                    'error_key' => null,
                    'log_failure' => false,
                ];
            }

            return [
                'eligible' => null,
                'class' => 'indeterminate',
                'error_key' => 'transfer_temporarily_unavailable',
                'log_failure' => true,
            ];
        }

        $class = $resp->errorClass();
        $body = $resp->body();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        $error_key = $class === 'terminal'
            ? ($sub_code ?: ($code ?: 'transfer_temporarily_unavailable'))
            : 'transfer_temporarily_unavailable';

        return [
            'eligible' => null,
            'class' => $class,
            'error_key' => $error_key,
            'log_failure' => true,
        ];
    }

    /**
     * Decides the reconcile-by-domain outcome for a register intent (pure, INV-5).
     *
     * No I/O. The single guard behind "never blind-resubmit Register" (AC5/FR18a):
     * given a Get Domain Info response, decide whether a live/retried intent may
     * resubmit. DOM2400 is multi-meaning, so the not-registered verdict requires BOTH
     * the DOM2400 subcode AND a "not found" provider message (info-endpoint context);
     * a DOM2400 carrying "invalid"/"not available" (a register/query meaning) stays
     * indeterminate. Anything else conservative is indeterminate — never resubmit on
     * doubt.
     *
     *  - success                       -> 'registered'     (already exists; do NOT resubmit)
     *  - DOM2400 + "not found" message -> 'not_registered' (safe to resubmit)
     *  - any other non-success         -> 'indeterminate'  (do NOT resubmit)
     *
     * @param WebnicResponse $resp The Get Domain Info response
     * @return array Decision: [
     *  'outcome'  => 'registered'|'not_registered'|'indeterminate',
     *  'status'   => string|null, // info data.status on the registered branch
     *  'dtexpire' => string|null  // info data.dtexpire on the registered branch
     * ]
     */
    public static function decideReconcileRegister(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            $status = is_array($data) ? ($data['status'] ?? null) : null;
            $dtexpire = is_array($data) ? ($data['dtexpire'] ?? null) : null;

            return ['outcome' => 'registered', 'status' => $status, 'dtexpire' => $dtexpire];
        }

        $body = $resp->body();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $provider_message = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';

        if ($sub_code === 'DOM2400' && stripos($provider_message, 'not found') !== false) {
            return ['outcome' => 'not_registered', 'status' => null, 'dtexpire' => null];
        }

        return ['outcome' => 'indeterminate', 'status' => null, 'dtexpire' => null];
    }

    /**
     * Folds per-domain WebNIC responses into the bulk availability contract (AR23).
     *
     * The method is intentionally pure: it performs no logging, localization, DB, time,
     * or network work. Each row delegates to decideAvailability() so bulk and single
     * availability share the same FR41 decision point.
     *
     * @param array $responsesByDomain Map of domain => WebnicResponse
     * @return array Decision: [
     *  'availability' => array, // domain => bool; true only for class 'available'
     *  'errors' => array,       // domain => error key for genuine errors only
     *  'log' => array           // domain => bool log_failure
     * ]
     */
    public static function decideBulkAvailability(array $responsesByDomain): array
    {
        $availability = [];
        $errors = [];
        $log = [];

        foreach ($responsesByDomain as $domain => $response) {
            $decision = self::decideAvailability($response);
            $class = $decision['class'];

            $availability[$domain] = $class === 'available';
            if ($class !== 'available' && $class !== 'taken') {
                $errors[$domain] = $decision['error_key'];
            }
            $log[$domain] = (bool) $decision['log_failure'];
        }

        return [
            'availability' => $availability,
            'errors' => $errors,
            'log' => $log,
        ];
    }

    /**
     * Classifies a Renew Domain response (pure, FR23/FR41, WN-4-3 AC6).
     *
     * No I/O — mirrors decideResendVerification / parseRegister. Renew is a SYNCHRONOUS side-action,
     * never a state change (NFR5): the outcomes drive Input errors and observability copy, never a
     * transition().
     *  - success (code 1000)                       -> 'ok' (term extended; data.dtexpire carries the new
     *                                                 expiry, refreshed separately by performRenew). A
     *                                                 defensive ccTLD success carrying pendingOrder:true
     *                                                 (§C — never captured on the synchronous .com path)
     *                                                 is ALSO 'ok' with pending:true: a non-error
     *                                                 "submitted, the new expiry will appear once the
     *                                                 registry confirms" that opens NO order and sets NO
     *                                                 Input error;
     *  - benign subcode (Webnic.renew_benign_subcodes) -> 'already' (idempotent-benign; EMPTY today —
     *                                                 OTE's double-renew reuses the overloaded DOM2400, so
     *                                                 no DISTINCT benign subcode exists. Keyed by
     *                                                 error.subCode, NOT a message stripos — WN-4-1 P6);
     *  - terminal (DOM2400 not-found/invalid/just-renewed, DOM4000 field, registry-reject) -> 'failed' + terminal;
     *  - retryable (5xx) / indeterminate (no reply / unknown code) -> 'failed' + that class ("try again later").
     *
     * error_key is a language-key SUFFIX under Webnic.!error.* the caller localizes: 'renew_failed'
     * (terminal) vs 'renew_unavailable' (retryable/indeterminate). No raw provider message is ever
     * returned: the success branch returns the localized Webnic.renew.ok key (NOT $resp->message(),
     * which short-circuits to the RAW provider string on success — the WN-4-2 P4 footgun); the error
     * branches return $resp->message(), which already maps to a non-leaking Webnic.* key (INV-7/FR41).
     *
     * @param WebnicResponse $resp The Renew Domain response
     * @return array Decision: [
     *  'outcome'     => 'ok'|'already'|'failed',
     *  'pending'     => bool,        // ok only: the §C defensive async-renew flag (false on the .com sync path)
     *  'error_class' => string|null, // failed only: retryable|terminal|indeterminate
     *  'error_key'   => string|null, // failed only: language-key suffix under Webnic.!error.*
     *  'message'     => string       // safe localized message (Webnic.* key, never raw provider text)
     * ]
     */
    public static function decideRenew(WebnicResponse $resp): array
    {
        // Success (code 1000): the registry accepted the term extension. Read pendingOrder defensively
        // for the §C async flag; .com renew is synchronous (pendingOrder:false). A success missing
        // dtexpire is STILL 'ok' — the expiry refresh is a non-fatal immediacy nicety (AC2), and the
        // live read-through getExpirationDate stays authoritative. Return the localized key, never
        // $resp->message() (which leaks the raw provider string on success).
        if ($resp->success()) {
            $data = $resp->data();
            $pending = is_array($data) && (($data['pendingOrder'] ?? null) === true);

            return [
                'outcome' => 'ok',
                'pending' => $pending,
                'error_class' => null,
                'error_key' => null,
                'message' => Language::_('Webnic.renew.ok', true),
            ];
        }

        // Normalize the subcode (WN-4-2 P5): a padded subcode (" DOM2400 ") must still match the benign
        // allowlist / error_class_map by code; treat empty/whitespace-only as no subcode. Guard that
        // `error` is an array before indexing it (a scalar/string `error` envelope must not raise — round-2 P8).
        $body = $resp->body();
        $raw_sub = (is_array($body) && is_array($body['error'] ?? null)) ? ($body['error']['subCode'] ?? null) : null;
        $sub_code = $raw_sub === null ? null : (trim((string) $raw_sub) === '' ? null : trim((string) $raw_sub));

        // Idempotent-benign: a subcode meaning "already renewed for this term / not in a renewable
        // state" satisfies the customer's intent — surface benign, never an error (FR41). Config-driven
        // and EMPTY today; keyed by subCode (NOT a message stripos — WN-4-1 P6). Cast defensively so a
        // misconfigured non-array override cannot raise a TypeError in in_array() (WN-4-2 P5/P7).
        $benign = (array) (Configure::get('Webnic.renew_benign_subcodes') ?: []);
        if ($sub_code !== null && in_array($sub_code, $benign, true)) {
            return [
                'outcome' => 'already',
                'pending' => false,
                'error_class' => null,
                'error_key' => null,
                // Benign = the customer's intent is already satisfied — surface a SUCCESS-toned notice,
                // never $resp->message() (which resolves to an error-toned Webnic.!error.* key — round-2 P5).
                'message' => Language::_('Webnic.renew.already', true),
            ];
        }

        // Everything else is a failure. A terminal class gets the hard "couldn't renew" copy; a
        // retryable/indeterminate class gets the soft "temporarily unavailable — try again" copy.
        $class = $resp->errorClass();

        // AC6 (renew-specific TERMINAL default): a STRUCTURED business rejection — the registry answered
        // with a parseable envelope carrying an error code/subCode — whose subcode is not yet mapped is
        // reclassified TERMINAL for renew (operator-action / fix-the-input default; e.g. the
        // [UNVERIFIED — needs prod] insufficient-balance / registry-reject subcodes harvested from the
        // first prod renewal). The GLOBAL AR13 'unknown' => 'indeterminate' map is left intact (it still
        // governs register/transfer/etc.); only renew, here, hardens an unmapped *business* envelope so a
        // real registry reject is not retry-looped with the wrong signal. Transport-level no-answer stays
        // 'indeterminate' (its body is null) and 5xx stays 'retryable' (errorClass handles both first).
        if ($class === 'indeterminate' && self::isStructuredBusinessReject($resp)) {
            $class = 'terminal';
        }

        $error_key = $class === 'terminal' ? 'renew_failed' : 'renew_unavailable';

        return [
            'outcome' => 'failed',
            'pending' => false,
            'error_class' => $class,
            'error_key' => $error_key,
            'message' => $resp->message(),
        ];
    }

    /**
     * Classifies a Restore Domain response into the deliberate restore contract (WN-4-4).
     *
     * Pure / I/O-free. A success envelope means the registry accepted the restore
     * request; data.pendingOrder=true is accepted-pending for user copy and audit
     * only, with no order/reconciler state. Non-success flows through
     * WebnicResponse::errorClass(); structured but unmapped business rejects are
     * terminal for restore, while transport/no-answer remains retryable or
     * indeterminate. No provider message is returned on success.
     *
     * @param WebnicResponse $resp The Restore Domain response
     * @return array Decision: [
     *  'outcome'     => 'ok'|'failed',
     *  'pending'     => bool,
     *  'error_class' => string|null,
     *  'error_key'   => string|null,
     *  'message'     => string
     * ]
     */
    public static function decideRestore(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            $pending = is_array($data) && (($data['pendingOrder'] ?? null) === true);

            return [
                'outcome' => 'ok',
                'pending' => $pending,
                'error_class' => null,
                'error_key' => null,
                'message' => Language::_($pending ? 'Webnic.restore.pending' : 'Webnic.restore.ok', true),
            ];
        }

        $class = $resp->errorClass();

        if ($class === 'indeterminate' && self::isStructuredBusinessReject($resp)) {
            $class = 'terminal';
        }

        $error_key = $class === 'terminal' ? 'restore_failed' : 'restore_unavailable';

        return [
            'outcome' => 'failed',
            'pending' => false,
            'error_class' => $class,
            'error_key' => $error_key,
            'message' => $resp->message(),
        ];
    }

    /**
     * Classifies a Delete Domain response into the deliberate admin action contract (WN-4-6).
     *
     * Pure / I/O-free. Success means the registry accepted the delete request; pendingOrder is carried
     * defensively for future ccTLDs but no reconciler branch is opened here (NFR5). Non-success flows
     * through WebnicResponse::errorClass(); a structured but unmapped business reject is terminal for
     * delete, while transport/no-answer remains retryable/indeterminate. A distinct already-deleted
     * benign subcode can be configured later, but OTE reused overloaded DOM2400 for repeat-delete and
     * not-found, so the default seam is empty.
     *
     * @param WebnicResponse $resp The Delete Domain response
     * @return array Decision: [
     *  'outcome'     => 'ok'|'already'|'failed',
     *  'pending'     => bool,
     *  'error_class' => string|null,
     *  'error_key'   => string|null,
     *  'message'     => string
     * ]
     */
    public static function decideDelete(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            $pending = is_array($data) && (($data['pendingOrder'] ?? null) === true);

            return [
                'outcome' => 'ok',
                'pending' => $pending,
                'error_class' => null,
                'error_key' => null,
                'message' => Language::_('Webnic.cancel.ok', true),
            ];
        }

        $body = $resp->body();
        $raw_sub = (is_array($body) && is_array($body['error'] ?? null)) ? ($body['error']['subCode'] ?? null) : null;
        $sub_code = $raw_sub === null ? null : (trim((string) $raw_sub) === '' ? null : trim((string) $raw_sub));
        $class = $resp->errorClass();

        $benign = (array) (Configure::get('Webnic.delete_benign_subcodes') ?: []);
        if ($sub_code !== null && in_array($sub_code, $benign, true)) {
            return [
                'outcome' => 'already',
                'pending' => false,
                'error_class' => null,
                'error_key' => null,
                'message' => Language::_('Webnic.delete.already', true),
            ];
        }

        if ($class === 'indeterminate' && self::isStructuredBusinessReject($resp)) {
            $class = 'terminal';
        }

        $error_key = $class === 'terminal' ? 'delete_failed' : 'delete_unavailable';

        return [
            'outcome' => 'failed',
            'pending' => false,
            'error_class' => $class,
            'error_key' => $error_key,
            'message' => $resp->message(),
        ];
    }

    /**
     * Detects a STRUCTURED business rejection: a parseable WebNIC envelope carrying a non-empty error
     * code or error.subCode (the registry actually answered), as opposed to a transport-level no-answer
     * (whose decoded body is null). Used to give renew a TERMINAL default for an UNMAPPED business
     * subcode (AC6) without touching the global AR13 'unknown' => 'indeterminate' convention.
     *
     * @param WebnicResponse $resp The renew response
     * @return bool True when the body carries a non-empty error code or subCode
     */
    private static function isStructuredBusinessReject(WebnicResponse $resp): bool
    {
        $body = $resp->body();
        if (!is_array($body)) {
            return false;
        }

        $code = $body['code'] ?? null;
        $sub = is_array($body['error'] ?? null) ? ($body['error']['subCode'] ?? null) : null;

        $has_code = $code !== null && trim((string) $code) !== '';
        $has_sub = $sub !== null && trim((string) $sub) !== '';

        return $has_code || $has_sub;
    }

    /**
     * Calendar-safe, leap-year-correct count of whole years between two instants, rounded to NEAREST
     * (half UP), for the AC5 over-renew term bump (round-2: replaces the round(days/365) approximation
     * that drifted on leap years and down-rounded an exact N.5 into an under-renewal). Pure / I/O-free so
     * the rounding boundaries are unit-pinned. Both args MUST be in the same (UTC) zone; ref <= now => 0.
     *
     * @param \DateTimeImmutable $now The reference "now" (UTC)
     * @param \DateTimeImmutable $ref The target date (UTC)
     * @return int Whole years (>= 0), rounded to nearest with .5 rounding up
     */
    public static function roundYearsBetween(\DateTimeImmutable $now, \DateTimeImmutable $ref): int
    {
        if ($ref <= $now) {
            return 0;
        }

        // Largest whole-year count N with now + N years <= ref (P{n}Y is leap-safe, unlike days/365).
        $years = 0;
        while ($now->add(new \DateInterval('P' . ($years + 1) . 'Y')) <= $ref) {
            $years++;
        }

        // Round to nearest: if ref reaches at least the halfway point of the ACTUAL next-year span
        // (leap-safe — not a fixed 365/2), round up. Exact .5 rounds UP (never silently under-renew).
        $lower = $now->add(new \DateInterval('P' . $years . 'Y'));
        $upper = $now->add(new \DateInterval('P' . ($years + 1) . 'Y'));
        $span = $upper->getTimestamp() - $lower->getTimestamp();
        $into = $ref->getTimestamp() - $lower->getTimestamp();

        return ($span > 0 && $into * 2 >= $span) ? $years + 1 : $years;
    }
}
