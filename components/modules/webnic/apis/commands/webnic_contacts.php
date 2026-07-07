<?php
/**
 * WebNIC contact + registrant command group (WN-3-2).
 *
 * The write-flow siblings of WebnicDomains for the registration saga's first two
 * steps: the reusable contact handles (POST /domain/v2/contact/create, GET
 * /domain/v2/contact/query) and the shared registrant account (POST
 * /domain/v2/registrant/create). Each method is a thin wrapper that translates one
 * WebNIC op into a single WebnicApi::submit() call; the pure parse* helpers are the
 * I/O-free decision points (AR23/NFR13), so they are unit-testable with no Blesta
 * bootstrap. Reading raw JSON stays in WebnicResponse; these never touch it.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicContacts
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
     * Creates a domain contact under a single role (FR14).
     *
     * WebNIC op: Create Domain Contact (POST /domain/v2/contact/create). The request
     * is nested by role — {registrant|administrator|technical|billing} => contact —
     * exactly as observed in the capture spike (one contact was created and its id
     * reused for all four roles). The response `data` is an ARRAY; the reusable
     * handle is read with parseContactId().
     *
     * @param string $role One of registrant|administrator|technical|billing
     * @param array $details The WebNIC contact_map field set (ContactsMap::mapClientToContact)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function createContact($role, array $details)
    {
        return $this->api->submit('domain/v2/contact/create', [$role => $details], 'POST');
    }

    /**
     * Reads back a contact handle (FR14 reuse read-path).
     *
     * WebNIC op: Query Domain Contact (GET /domain/v2/contact/query?contactId=). The
     * response `data` is a single object with the full contact details.
     *
     * @param string $contactId The WebNIC contact handle (WNC...)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function queryContact($contactId)
    {
        return $this->api->submit('domain/v2/contact/query', ['contactId' => $contactId], 'GET');
    }

    /**
     * Modifies an existing WebNIC contact handle (FR29).
     *
     * WebNIC op: Modify Contact (POST /domain/v2/contact/modify). T0 OTE capture
     * confirmed selected-field updates are accepted and that `customFields` must stay
     * object-shaped (`{}`), not an empty array, when present. WebNIC documents that
     * domains sharing the same contact ID are all affected by this operation.
     *
     * @param string $contactId The currently attached WebNIC contact handle
     * @param array $details Selected WebNIC contact detail fields to update
     * @return WebnicResponse Normalized WebNIC response
     */
    public function modifyContact($contactId, array $details)
    {
        return $this->api->submit(
            'domain/v2/contact/modify',
            ['contactId' => $contactId, 'details' => $details],
            'POST'
        );
    }

    /**
     * Creates (or re-mints) the shared registrant account (FR14).
     *
     * WebNIC op: Create Registrant (POST /domain/v2/registrant/create?username=). The
     * username is a QUERY PARAM with no request body, so it rides in the command path
     * (WebnicApi sends POST bodies as JSON, never query strings). The response carries
     * data.registrantUserId (WNU...) and data.password (a secret — redacted in logs).
     * Callers derive `username` deterministically so a re-mint is idempotent.
     *
     * @param string $username The registrant username (>=10 alphanumerics, unique)
     * @return WebnicResponse Normalized WebNIC response
     */
    public function createRegistrant($username)
    {
        return $this->api->submit('domain/v2/registrant/create?username=' . rawurlencode($username), [], 'POST');
    }

    /**
     * Extracts the reusable contact handle from a contact/create response (pure).
     *
     * The malformed-success trap (deferred from WN-3-0): a code=1000 envelope without
     * a usable data[0].contactId is NOT a usable handle — return null so the saga
     * surfaces a terminal malformed-success instead of proceeding to Register with a
     * missing handle. No I/O.
     *
     * @param WebnicResponse $resp The contact/create response
     * @return string|null The contact handle, or null when absent/malformed
     */
    public static function parseContactId(WebnicResponse $resp)
    {
        if (!$resp->success()) {
            return null;
        }

        $data = $resp->data();
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return null;
        }

        $id = $data[0]['contactId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Extracts the shared registrant account id from a registrant/create response (pure).
     *
     * Same malformed-success trap: a success envelope without a usable
     * data.registrantUserId returns null. No I/O.
     *
     * @param WebnicResponse $resp The registrant/create response
     * @return string|null The registrant account id, or null when absent/malformed
     */
    public static function parseRegistrantUserId(WebnicResponse $resp)
    {
        if (!$resp->success()) {
            return null;
        }

        $data = $resp->data();
        if (!is_array($data)) {
            return null;
        }

        $id = $data['registrantUserId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Classifies a Modify Contact response (pure, WN-5-2).
     *
     * T0 OTE observed immediate `code:1000`; the official docs also document
     * `code:1001` for accepted/in-progress registry updates, so callers must not
     * collapse 1001 into a false failure or a fully-updated success claim. Success
     * branches return localized keys, never raw provider strings.
     *
     * @param WebnicResponse $resp The Modify Contact response
     * @return array Decision: [
     *  'outcome'     => 'ok'|'accepted'|'failed',
     *  'pending'     => bool,
     *  'error_class' => string|null,
     *  'error_key'   => string|null,
     *  'message'     => string
     * ]
     */
    public static function decideModifyContact(WebnicResponse $resp): array
    {
        if ($resp->success()) {
            return [
                'outcome' => 'ok',
                'pending' => false,
                'message' => Language::_('Webnic.contact_update.ok', true),
            ];
        }

        $body = $resp->body();
        if (is_array($body) && ($body['code'] ?? null) === '1001') {
            return [
                'outcome' => 'accepted',
                'pending' => true,
                'message' => Language::_('Webnic.contact_update.pending', true),
            ];
        }

        $class = $resp->errorClass();
        $sub_code = is_array($body) && is_array($body['error'] ?? null)
            ? trim((string)($body['error']['subCode'] ?? ''))
            : '';

        return [
            'outcome' => 'failed',
            'pending' => false,
            'error_class' => $class,
            'error_key' => $sub_code !== '' ? $sub_code : 'contact_update_failed',
            'message' => $resp->message(),
        ];
    }
}
