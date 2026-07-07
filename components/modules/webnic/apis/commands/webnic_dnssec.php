<?php
/**
 * WebNIC DNSSEC command group.
 *
 * Thin wrappers for the WebNIC `/domain/v2/dnssec*` APIs that back the Epic 5
 * DNSSEC (DS-record) tab. The live OTE T0 capture (WN-5.7, fixture
 * tests/fixtures/webnic/dnssec_contract_wn57.json) proved:
 *  - reads pass `domainName` as a GET query param;
 *  - the update/delete writes need `domainName` baked into the query string of the
 *    command path (a JSON-body `domainName` is rejected with DOM4000), so it is
 *    rawurlencode()'d into the path here (the WebnicDns / WebnicDomains precedent);
 *  - the update body carries `dsDatas` only and REPLACES the whole set (full-list PUT);
 *  - the success envelope is simple `code:1000` (no `code:3000` batch), so
 *    WebnicResponse::success() applies directly and no batch classifier is needed.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicDnssec
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
     * Reads whether the domain's registry/TLD supports DNSSEC.
     *
     * WebNIC op: Check DNSSEC Supported (GET /domain/v2/dnssec/support?domainName=).
     * `domainName` rides the GET query string via $args.
     *
     * @param string $domainName The domain name
     * @return WebnicResponse Normalized WebNIC response carrying data.dnssecSupported(bool)
     */
    public function getDnssecSupport($domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/dnssec/support',
            ['domainName' => $domainName],
            'GET'
        );
    }

    /**
     * Reads the current DS records for a domain.
     *
     * WebNIC op: Get DNSSEC (GET /domain/v2/dnssec?domainName=). Returns
     * data.dsDatas[]{keyTag, algorithm, digestType, digest} (string values).
     *
     * @param string $domainName The domain name
     * @return WebnicResponse Normalized WebNIC response
     */
    public function getDnssec($domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/dnssec',
            ['domainName' => $domainName],
            'GET'
        );
    }

    /**
     * Replaces the full DS-record set for a domain (full-list PUT semantics).
     *
     * WebNIC op: Update DNSSEC (POST /domain/v2/dnssec?domainName=). The captured
     * contract REPLACES the whole `dsDatas[]` set, so callers must send the complete
     * intended list. `domainName` must be a query param (DOM4000 if placed in the body),
     * so it is baked into the command path; the body carries `dsDatas` only.
     *
     * @param string $domainName The domain name
     * @param array $dsDatas The complete DS-record list to set
     *                        ([{keyTag, algorithm, digestType, digest}, ...])
     * @return WebnicResponse Normalized WebNIC response
     */
    public function updateDnssec($domainName, array $dsDatas): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/dnssec?domainName=' . rawurlencode($domainName),
            ['dsDatas' => array_values($dsDatas)],
            'POST'
        );
    }

    /**
     * Removes ALL DS records for a domain.
     *
     * WebNIC op: Delete DNSSEC (DELETE /domain/v2/dnssec?domainName=). The captured
     * contract deletes the entire set and ignores any request body. To remove one of
     * several records, re-POST the remaining list via updateDnssec(); use this only to
     * clear the last record (an empty-list update is rejected with DOM2400).
     *
     * @param string $domainName The domain name
     * @return WebnicResponse Normalized WebNIC response
     */
    public function deleteDnssec($domainName): WebnicResponse
    {
        return $this->api->submit(
            'domain/v2/dnssec?domainName=' . rawurlencode($domainName),
            [],
            'DELETE'
        );
    }
}
