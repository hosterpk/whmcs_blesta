<?php
/**
 * WebNIC transfer-in command group (WN-4-1).
 *
 * The transfer saga's write/read surface: Submit Registrar Transfer In
 * (POST /domain/v2/transfer-in), the by-domain in-flight lookup
 * (GET /domain/v2/transfer-in/status?domainName=), and the verification-email resend
 * (POST /domain/v2/resend-verification-email?domainName=, WN-4-2 — a stateless side-action,
 * NOT part of the saga). A thin wrapper translating each
 * WebNIC op into one WebnicApi::submit() call (INV-2, mirrors WebnicDomains/WebnicHosts);
 * the pure decide* helpers are the I/O-free decision points (AR23/NFR13), unit-testable
 * with no Blesta bootstrap. Reading raw JSON stays in WebnicResponse; these never touch it.
 *
 * CONTRACT PROVENANCE (OTE-captured 2026-06-17, config/webnic.php T0 block): transfer-in
 * REQUIRES the 4 contact handles + registrantUserId (like register's B7 body) PLUS `authInfo`
 * (the EPP/auth code); the saga supplies them. The SUCCESS/PENDING submit envelope is
 * [UNVERIFIED — needs first live transfer] (OTE rejects a synthetic non-registry domain before
 * it can pend), so decideTransferSubmit() handles BOTH a standard code:1000 success AND a
 * host/create-style code:3000 batch defensively (§G).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicTransfers
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
     * Submits a registrar transfer-in (FR20).
     *
     * WebNIC op: Submit Registrar Transfer In (POST /domain/v2/transfer-in). The body
     * is the OTE-captured field set (TransferSaga::buildTransferBody): domainName, term,
     * authInfo (EPP), the four *ContactId handles, and registrantUserId. The response is
     * classified by decideTransferSubmit(); the caller (TransferSaga) owns the transition()
     * calls and the INV-4 return contract — this stays thin.
     *
     * @param array $body The transfer-in request body
     * @return WebnicResponse Normalized WebNIC response
     */
    public function submitTransferIn(array $body)
    {
        return $this->api->submit('domain/v2/transfer-in', $body, 'POST');
    }

    /**
     * Reads a domain's in-flight transfer-in record by name (FR21 reconcile-by-domain).
     *
     * WebNIC op: Transfer In Status (GET /domain/v2/transfer-in/status?domainName=). This
     * is the ONLY domain-keyed in-flight lookup in the API (registrations have none — they
     * use order/info?id=), so transfer recovery is a DIRECT by-domain read. `data.status`
     * is one of pending|approve|reject|cancel|insert_fail|complete; the branch decision
     * lives in the pure decideReconcileTransfer() helper. A not-yet-existing record returns
     * DOM2400 "Transfer in record not found".
     *
     * @param string $domainName The fully-qualified domain to read
     * @return WebnicResponse Normalized WebNIC response
     */
    public function transferStatus($domainName)
    {
        return $this->api->submit('domain/v2/transfer-in/status', ['domainName' => $domainName], 'GET');
    }

    /**
     * Resends the domain verification email (FR22).
     *
     * WebNIC op: Resend Domain Verification Email (POST /domain/v2/resend-verification-email).
     * OTE-captured (WN-4-2 T0, config/webnic.php provenance): `domainName` is a QUERY-STRING
     * parameter — a JSON body returns DOM4000 "Required request parameter 'domainName' ... is not
     * present" — so it is embedded in the command path (WebnicApi::submit query-encodes only GET
     * args) and the POST carries an empty body (wire-confirmed green). rawurlencode keeps a
     * punycode/reserved label URL-safe. The op is operation-agnostic (it fired on a register
     * awaiting registrant verification, not only transfers) and idempotent (a repeat resend also
     * returns code 1000); the response is classified by decideResendVerification(). No state change
     * (NFR5): this opens no order and runs no transition() — the caller (Webnic::resendTransferEmail)
     * only surfaces the outcome.
     *
     * @param string $domainName The fully-qualified domain to resend verification for
     * @return WebnicResponse Normalized WebNIC response
     */
    public function resendVerificationEmail(string $domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/resend-verification-email?domainName=' . rawurlencode($domainName),
            [],
            'POST'
        );
    }

    /**
     * Classifies a Submit Registrar Transfer In response (pure, FR20).
     *
     * No I/O — mirrors WebnicResponse::parseRegister / WebnicHosts::decideHostCreateResult.
     * A transfer is INHERENTLY async: an accepted submit is always 'submitted' (the caller
     * moves it to registrar_pending and reconciles by-domain), never synchronously active.
     * The success envelope is [UNVERIFIED] (§G), so this handles BOTH shapes:
     *  - code 1000 success                              -> 'submitted' (+ transfer_id if any);
     *  - code 3000 batch, success[] non-empty/fail[] empty -> 'submitted' (+ transfer_id if any);
     *  - code 3000 batch with fail[]                    -> 'failed' (nested batch subCode);
     *  - any other non-success                          -> 'failed' (errorClass + mapped key).
     *
     * The transfer_id is a best-effort correlation id (data.transferId|pendingOrderId|id),
     * cast to string at the boundary (the transfer_id column is varchar(64); pendingOrderId
     * is an INTEGER on the wire — the §L type-drift trap) and may be null. It is NOT load-
     * bearing for reconcile (transfer polls by domainName, not by id), only an audit handle.
     *
     * @param WebnicResponse $resp The Submit Registrar Transfer In response
     * @return array Decision: [
     *  'outcome'     => 'submitted'|'failed',
     *  'transfer_id' => string|null, // submitted only (best-effort correlation id)
     *  'error_class' => string|null, // failed only: retryable|terminal|indeterminate
     *  'error_key'   => string,      // failed only: language-key suffix under Webnic.!error.*
     *  'message'     => string       // failed only: localized provider/error message
     * ]
     */
    public static function decideTransferSubmit(WebnicResponse $resp): array
    {
        // Standard success envelope (code 1000): accepted into the transfer-in pipeline.
        if ($resp->success()) {
            return [
                'outcome' => 'submitted',
                'transfer_id' => self::correlationId($resp->data()),
            ];
        }

        // Batch envelope (code 3000): a successful submit MAY arrive as a host/create-style
        // batch rather than code 1000 — handle both defensively (§G; real shape [UNVERIFIED]).
        $body = $resp->body();
        if (is_array($body) && ($body['code'] ?? null) === '3000') {
            $data = is_array($resp->data()) ? $resp->data() : [];
            $success = (isset($data['success']) && is_array($data['success'])) ? $data['success'] : [];
            $fail = (isset($data['fail']) && is_array($data['fail'])) ? $data['fail'] : [];

            if (empty($fail) && !empty($success)) {
                return [
                    'outcome' => 'submitted',
                    'transfer_id' => self::correlationId($success[0] ?? null),
                ];
            }

            return [
                'outcome' => 'failed',
                'error_class' => 'terminal',
                'error_key' => self::batchFailSubCode($fail) ?: 'transfer_failed',
                'message' => $resp->message(),
            ];
        }

        // Non-success: classify via the single errorClass() point (transport outcome, then
        // 5xx/401, then error.subCode/code via error_class_map). Preserve terminal mapped keys
        // (DOM2400/DOM4000) so config/auth/registry-reject failures are not mislabeled.
        $class = $resp->errorClass();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        $error_key = $class === 'terminal'
            ? ($sub_code ?: ($code ?: 'transfer_failed'))
            : 'transfer_failed';

        return [
            'outcome' => 'failed',
            'error_class' => $class,
            'error_key' => $error_key,
            'message' => $resp->message(),
        ];
    }

    /**
     * Decides the reconcile-by-domain outcome for an in-flight transfer (pure, FR21/NFR5).
     *
     * No I/O. Maps the transfer-in/status enum to a reconcile action, honoring the
     * never-flip-on-doubt rule (WN-3-3): a transient/unknown signal NEVER flips the service
     * to a wrong terminal state.
     *  - success + status complete                 -> 'active'  (finalise + write expiry);
     *  - success + status reject|cancel|insert_fail -> 'failed'  (terminal registry reject);
     *  - success + status pending|approve          -> 'pending' (keep polling under backoff);
     *  - success + unknown status                  -> 'indeterminate' (never flip on doubt);
     *  - DOM2400 "Transfer in record not found"     -> 'absent'  (no in-flight record);
     *  - any other non-success                      -> 'indeterminate' (never flip on doubt).
     *
     * Callers interpret by context (NFR5): the reconciler treats 'absent'/'indeterminate' as
     * keep-polling (the order rides to give_up_at, never a wrong terminal flip); the saga's
     * pre-submit idempotency check treats 'absent' as "safe to submit".
     *
     * @param WebnicResponse $resp The transfer-in/status response
     * @return array Decision: [
     *  'outcome' => 'active'|'failed'|'pending'|'absent'|'indeterminate',
     *  'status'  => string|null // the raw transfer-in/status enum on success
     * ]
     */
    public static function decideReconcileTransfer(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            // trim() before lower-casing: a status carrying stray whitespace (" complete") must
            // still match its case, not fall to default->indeterminate and ride to the 30-day
            // give-up (which would lose a successfully-transferred domain). Round-1 P9.
            $status = is_array($data) ? strtolower(trim((string) ($data['status'] ?? ''))) : '';

            switch ($status) {
                case 'complete':
                    return ['outcome' => 'active', 'status' => $status];
                case 'reject':
                case 'cancel':
                case 'insert_fail':
                    return ['outcome' => 'failed', 'status' => $status];
                case 'pending':
                case 'approve':
                    return ['outcome' => 'pending', 'status' => $status];
                default:
                    // A success envelope carrying an unknown status value: never flip on doubt.
                    return ['outcome' => 'indeterminate', 'status' => $status];
            }
        }

        $body = $resp->body();
        $sub_code = is_array($body) ? ($body['error']['subCode'] ?? null) : null;

        // On the transfer-in/status endpoint a DOM2400 is unambiguously "Transfer in record not
        // found" (T0 OTE capture, config/webnic.php provenance) — i.e. absent / no in-flight
        // record. Classify by subCode alone; the prior stripos('not found') message clause was
        // brittle (a reworded/localized message flipped it to indeterminate, stranding a legit
        // retry on the polling treadmill / blocking a resubmit). Round-1 P6.
        if ($sub_code === 'DOM2400') {
            return ['outcome' => 'absent', 'status' => null];
        }

        return ['outcome' => 'indeterminate', 'status' => null];
    }

    /**
     * Classifies a Resend Domain Verification Email response (pure, FR22/FR41).
     *
     * No I/O — mirrors decideReconcileTransfer / parseRegister. The verification email is a
     * side-action, never a state change (NFR5), so the outcomes are purely advisory copy:
     *  - success (code 1000)                      -> 'ok'      (email sent; the idempotent
     *                                                 already-sent case ALSO returns 1000, OTE-confirmed);
     *  - benign subcode (Webnic.resend_benign_subcodes) -> 'already' (idempotent-benign: "already
     *                                                 verified / not in a verifiable state" — the
     *                                                 customer's intent is satisfied, so it is a
     *                                                 notice/success, NEVER an error; mirrors the
     *                                                 privacy-toggle already-active precedent);
     *  - terminal (DOM2400 not-found / DOM4000 bad-input / registry-reject) -> 'failed' + terminal;
     *  - retryable (5xx) / indeterminate (no reply) -> 'failed' + that class ("try again later").
     *
     * The benign-subcode set is config-driven and EMPTY today ([UNVERIFIED — needs live]: OTE never
     * produced a distinct already-verified subcode — resend returns plain 1000 even while
     * verified:false), so the 'already' branch is dormant until a production resend reveals one. The
     * benign match is by error.subCode, NOT a message stripos (the WN-4-1 P6 lesson). error_key is a
     * language-key SUFFIX under Webnic.!error.* the caller localizes: 'resend_failed' (terminal) vs
     * 'resend_unavailable' (retryable/indeterminate). No raw provider message is ever returned
     * (message() already maps to a non-leaking Webnic.* key — INV-7/FR41).
     *
     * @param WebnicResponse $resp The Resend Domain Verification Email response
     * @return array Decision: [
     *  'outcome'     => 'ok'|'already'|'failed',
     *  'error_class' => string|null, // failed only: retryable|terminal|indeterminate
     *  'error_key'   => string|null, // failed only: language-key suffix under Webnic.!error.*
     *  'message'     => string       // safe localized message (Webnic.* key, never raw provider text)
     * ]
     */
    public static function decideResendVerification(WebnicResponse $resp): array
    {
        // Success (code 1000): the verification email was (re)sent. Idempotent — a repeat resend
        // also returns 1000 (OTE-confirmed), so 'ok' covers the already-sent case too. Return the
        // localized Webnic.* key, never $resp->message() — on success message() short-circuits to the
        // RAW provider string, which would violate this method's "never raw provider text" contract
        // (round-1 P4: dead today since the caller ignores it, but a footgun for any future consumer).
        if ($resp->success()) {
            return ['outcome' => 'ok', 'error_class' => null, 'error_key' => null, 'message' => Language::_('Webnic.resend.ok', true)];
        }

        // Normalize the subcode (T1: "trim() + subCode-by-code"): a padded subcode (" DOM2400 ")
        // would otherwise bypass both the benign allowlist and the error_class_map; treat
        // empty/whitespace-only as no subcode.
        $body = $resp->body();
        $raw_sub = is_array($body) ? ($body['error']['subCode'] ?? null) : null;
        $sub_code = $raw_sub === null ? null : (trim((string) $raw_sub) === '' ? null : trim((string) $raw_sub));

        // Idempotent-benign: a subcode meaning "already verified / not in a verifiable state" already
        // satisfies the customer's intent — surface benign, never an error (FR41). Config-driven and
        // EMPTY today; keyed by subCode (NOT a message stripos — WN-4-1 P6). Cast defensively so a
        // misconfigured non-array override cannot raise a TypeError in in_array() (round-1 P5/P7).
        $benign = (array) (Configure::get('Webnic.resend_benign_subcodes') ?: []);
        if ($sub_code !== null && in_array($sub_code, $benign, true)) {
            return ['outcome' => 'already', 'error_class' => null, 'error_key' => null, 'message' => $resp->message()];
        }

        // Everything else is a failure. A terminal class gets the hard "couldn't resend" copy; a
        // retryable/indeterminate class gets the soft "temporarily unavailable — try again" copy.
        $class = $resp->errorClass();
        $error_key = $class === 'terminal' ? 'resend_failed' : 'resend_unavailable';

        return [
            'outcome' => 'failed',
            'error_class' => $class,
            'error_key' => $error_key,
            'message' => $resp->message(),
        ];
    }

    /**
     * Extracts a best-effort transfer correlation id from a submit success payload (pure).
     *
     * The exact field is [UNVERIFIED] (no live success captured), so this reads the
     * documented candidates in priority order and casts INTEGER ids to string at the
     * boundary (the transfer_id column is varchar(64); §L pendingOrderId int type-drift).
     *
     * @param mixed $data The success data envelope (or a batch success[] entry)
     * @return string|null The correlation id, or null when absent
     */
    private static function correlationId($data)
    {
        if (!is_array($data)) {
            return null;
        }

        foreach (['transferId', 'pendingOrderId', 'id'] as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key]))) {
                $value = (string) $data[$key];
                // Reject empty AND the integer-0 / "0" sentinel: a 0 id is a garbage correlation
                // handle (reconcile is by-domain anyway) that would otherwise pollute the audit
                // trail / transfer_id column. Round-1 P14c.
                if ($value !== '' && $value !== '0') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Extracts the nested business subcode from a batch-failure entry (pure).
     *
     * Mirrors WebnicHosts::batchFailSubCode: the batch nests the real subcode under a
     * per-registry block (data.fail[0] = {"<registry>": {"errors": {"subCode": ...}}}).
     *
     * @param array $fail The data.fail[] batch-failure list
     * @return string|null The nested subcode, or null when absent
     */
    private static function batchFailSubCode(array $fail)
    {
        $first = $fail[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        foreach ($first as $registry_block) {
            if (is_array($registry_block) && isset($registry_block['errors']['subCode'])) {
                return (string) $registry_block['errors']['subCode'];
            }
        }

        return null;
    }
}
