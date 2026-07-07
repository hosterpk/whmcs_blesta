<?php
/**
 * WebNIC DNS command group.
 *
 * Thin wrappers for the WebNIC `/dns/v2/zone/*` APIs that back the Epic 5 basic
 * DNS records and forwarding tabs. The live OTE contracts for WN-5.5/WN-5.6 use
 * simple envelopes, so no code:3000 batch classifier is needed here.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicDns
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
     * Reads zone records for a domain.
     *
     * WebNIC op: Get Zone Records (GET /dns/v2/zone/{zone}/records).
     *
     * @param string $domainName The domain zone name
     * @return WebnicResponse Normalized WebNIC response
     */
    public function getZoneRecords($domainName): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/records',
            [],
            'GET'
        );
    }

    /**
     * Reads the globally supported record-type list.
     *
     * WebNIC op: Get Supported Record Types (GET /dns/v2/zone/record-types).
     *
     * @return WebnicResponse Normalized WebNIC response
     */
    public function getSupportedRecordTypes(): WebnicResponse
    {
        return $this->api->submit('dns/v2/zone/record-types', [], 'GET');
    }

    /**
     * Saves/replaces a zone record.
     *
     * WebNIC op: Save Zone Record (POST /dns/v2/zone/{zone}/record). The record
     * body is the official `{name,type,ttl,rdatas:[{value}]}` shape captured at T0.
     *
     * @param string $domainName The domain zone name
     * @param array $record The record body
     * @return WebnicResponse Normalized WebNIC response
     */
    public function saveZoneRecord($domainName, array $record): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/record',
            $record,
            'POST'
        );
    }

    /**
     * Deletes a zone record by type and name.
     *
     * WebNIC op: Delete Zone Record (DELETE /dns/v2/zone/{zone}/record?type=&name=).
     * Both selectors are query parameters on the DELETE, so they are embedded in the
     * command path; passing them as args would serialize them into a JSON body.
     *
     * @param string $domainName The domain zone name
     * @param string $type Record type
     * @param string $name Record name; blank targets the zone apex per WebNIC docs
     * @return WebnicResponse Normalized WebNIC response
     */
    public function deleteZoneRecord($domainName, $type, $name): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName)
                . '/record?type=' . rawurlencode($type)
                . '&name=' . rawurlencode($name),
            [],
            'DELETE'
        );
    }

    /**
     * Reads URL forwarding rows for a domain zone.
     *
     * WebNIC op: Get Zone URL Forwarding (GET /dns/v2/zone/{zone}/url-forwardings).
     *
     * @param string $domainName The domain zone name
     * @return WebnicResponse Normalized WebNIC response
     */
    public function getUrlForwardings($domainName): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/url-forwardings',
            [],
            'GET'
        );
    }

    /**
     * Adds a URL forwarding row.
     *
     * WebNIC op: Add Zone URL Forwarding (POST /dns/v2/zone/{zone}/url-forwarding).
     *
     * @param string $domainName The domain zone name
     * @param array $body Forwarding body `{subdomain,targetUrl,overrideConflictingRecord}`
     * @return WebnicResponse Normalized WebNIC response
     */
    public function addUrlForwarding($domainName, array $body): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/url-forwarding',
            $body,
            'POST'
        );
    }

    /**
     * Removes a URL forwarding row by subdomain selector.
     *
     * WebNIC op: Remove Zone URL Forwarding
     * (DELETE /dns/v2/zone/{zone}/url-forwarding?subdomain=).
     *
     * @param string $domainName The domain zone name
     * @param string $subdomain The subdomain selector; `@` targets the zone apex display value
     * @return WebnicResponse Normalized WebNIC response
     */
    public function removeUrlForwarding($domainName, $subdomain): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName)
                . '/url-forwarding?subdomain=' . rawurlencode($subdomain),
            [],
            'DELETE'
        );
    }

    /**
     * Reads email forwarding rows for a domain zone.
     *
     * WebNIC op: Get Zone Email Forwarding (GET /dns/v2/zone/{zone}/email-forwardings).
     *
     * @param string $domainName The domain zone name
     * @return WebnicResponse Normalized WebNIC response
     */
    public function getEmailForwardings($domainName): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/email-forwardings',
            [],
            'GET'
        );
    }

    /**
     * Adds an email forwarding row.
     *
     * WebNIC op: Add Zone Email Forwarding (POST /dns/v2/zone/{zone}/email-forwarding).
     *
     * @param string $domainName The domain zone name
     * @param array $body Forwarding body `{user,targetEmail,overrideConflictingRecord}`
     * @return WebnicResponse Normalized WebNIC response
     */
    public function addEmailForwarding($domainName, array $body): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName) . '/email-forwarding',
            $body,
            'POST'
        );
    }

    /**
     * Removes an email forwarding row by source local-part selector.
     *
     * WebNIC op: Remove Zone Email Forwarding
     * (DELETE /dns/v2/zone/{zone}/email-forwarding?user=).
     *
     * @param string $domainName The domain zone name
     * @param string $user Source local-part selector
     * @return WebnicResponse Normalized WebNIC response
     */
    public function removeEmailForwarding($domainName, $user): WebnicResponse
    {
        return $this->api->submit(
            'dns/v2/zone/' . rawurlencode($domainName)
                . '/email-forwarding?user=' . rawurlencode($user),
            [],
            'DELETE'
        );
    }
}
