<?php
/**
 * WebNIC host command group (WN-3-2).
 *
 * The registration saga's third step: the >=2 host (nameserver) objects that must
 * be registry-known before Register (FR15). A thin wrapper translating each WebNIC
 * op into one WebnicApi::submit() call; the pure decideHostCreate() is the I/O-free
 * decision point (AR23/NFR13).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicHosts
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
     * Checks whether a nameserver is already registry-known for an extension (FR15).
     *
     * WebNIC op: Check Host (POST /domain/v2/host/check?nameserver=&ext=). Both
     * arguments are QUERY PARAMS on a POST (no body), so they ride in the command
     * path. The response carries data.available: `false` means the host is ALREADY
     * registry-known and usable directly (the observed WebNIC shared NS case); `true`
     * means it must be created with createHost() before Register.
     *
     * @param string $nameserver The fully-qualified nameserver host
     * @param string $ext The extension (TLD without the leading dot)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function checkHost($nameserver, $ext)
    {
        return $this->api->submit(
            'domain/v2/host/check?nameserver=' . rawurlencode($nameserver) . '&ext=' . rawurlencode($ext),
            [],
            'POST'
        );
    }

    /**
     * Registers a host object for an extension (FR15).
     *
     * WebNIC op: Create Host (POST /domain/v2/host/create/extension). Only called
     * when checkHost() reports available=true. OTE-captured (WN-3-6 §H, host_create.json):
     * a SUCCESSFUL create is a `code:"3000"` BATCH envelope with data.success[]/fail[],
     * NOT the standard `code:"1000"` — so WebnicResponse::success() (code===1000) returns
     * FALSE on success. Callers MUST read the result via decideHostCreateResult(), never
     * success() (see that helper).
     *
     * @param string $host The fully-qualified nameserver host
     * @param array $ipList The host IP addresses
     * @param string $ext The extension (TLD without the leading dot)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function createHost($host, array $ipList, $ext)
    {
        return $this->api->submit('domain/v2/host/create/extension', [
            'host' => $host,
            'ipList' => $ipList,
            'ext' => $ext,
        ], 'POST');
    }

    /**
     * Decides whether a nameserver needs a host/create before Register (pure).
     *
     * No I/O. The single decision point behind the saga's host step:
     *  - success + available:false -> 'ready' (already registry-known, use as-is);
     *  - success + available:true  -> 'create' (must host/create before Register);
     *  - malformed success / any error -> 'indeterminate' (never fabricate usability).
     *
     * @param WebnicResponse $resp The host/check response
     * @return array Decision: ['outcome' => 'ready'|'create'|'indeterminate']
     */
    public static function decideHostCreate(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            $data = $resp->data();
            if (is_array($data) && array_key_exists('available', $data) && is_bool($data['available'])) {
                return ['outcome' => $data['available'] ? 'create' : 'ready'];
            }
        }

        return ['outcome' => 'indeterminate'];
    }

    /**
     * Decides the outcome of a host/create/extension response (pure, WN-3-6 §H / AC3).
     *
     * The host-create-specific batch reader the §H finding called for: host/create does NOT
     * return the standard code:1000 success — the live OTE capture is a `code:"3000"` BATCH
     * envelope with data.success[] / data.fail[] (host_create.json / host_create_fail.json),
     * so WebnicResponse::success() (code===1000) returns FALSE even on a SUCCESSFUL create.
     * Callers MUST use this helper (not success()) to read a create result; the broad success()
     * semantics are deliberately left unchanged (it correctly rejects the non-1000 envelope for
     * every other op). This is the tested primitive the saga's create branch will consume when
     * self-hosted glue / child NS is wired (Epic 5 / WN-4-1) — the saga is untouched here (§H).
     *
     *  - code 3000 + data.success[] non-empty + data.fail[] empty -> 'created';
     *  - code 3000 + data.fail[] non-empty -> 'failed' (sub_code from the NESTED
     *    data.fail[0].<registry>.errors.subCode, e.g. DOM2400 — where errorClass()/errorSubCode()
     *    cannot see it because they read body.error.subCode);
     *  - transport fault / non-batch / malformed -> 'indeterminate' (never fabricate created).
     *
     * @param WebnicResponse $resp The host/create/extension response
     * @return array ['outcome' => 'created'|'failed'|'indeterminate', 'sub_code' => string|null]
     */
    public static function decideHostCreateResult(WebnicResponse $resp): array
    {
        $body = $resp->body();
        if (!is_array($body) || ($body['code'] ?? null) !== '3000') {
            return ['outcome' => 'indeterminate', 'sub_code' => null];
        }

        $data = is_array($resp->data()) ? $resp->data() : [];
        $success = (isset($data['success']) && is_array($data['success'])) ? $data['success'] : [];
        $fail = (isset($data['fail']) && is_array($data['fail'])) ? $data['fail'] : [];

        if (!empty($fail)) {
            return ['outcome' => 'failed', 'sub_code' => self::batchFailSubCode($fail)];
        }

        if (!empty($success)) {
            return ['outcome' => 'created', 'sub_code' => null];
        }

        return ['outcome' => 'indeterminate', 'sub_code' => null];
    }

    /**
     * Extracts the nested business subcode from a host/create batch-failure entry (pure).
     *
     * The batch nests the real subcode under a per-registry block:
     * data.fail[0] = {"<registry>": {"errors": {"subCode": "DOM2400", ...}}}. The registry key
     * varies, so this reads the first entry's first block that carries errors.subCode defensively.
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
