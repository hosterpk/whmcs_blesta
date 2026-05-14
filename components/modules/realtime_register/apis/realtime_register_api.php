<?php
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'realtime_register_response.php';

/**
 * Realtime Register API
 *
 * @package blesta
 * @subpackage blesta.components.modules.centovacast
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RealtimeRegisterApi
{
    /**
     * @var string The Realtime Register API URL
     */
    private $api_url = [
        'live' => 'https://api.yoursrs.com/',
        'sandbox' => 'https://api.yoursrs-ote.com/',
    ];

    /**
     * @var string The customer handle to authenticate
     */
    private $customer;

    /**
     * @var string The API key to authenticate
     */
    private $api_key;

    /**
     * @var bool Whether or not to use the sandbox environment for the API requests
     */
    private $sandbox;

    /**
     * @var array The data sent with the last request served by this API
     */
    private $last_request = [];

    /**
     * Initializes the request parameter
     *
     * @param string $api_key The API key to authenticate
     */
    public function __construct(string $customer, string $api_key, bool $sandbox = false)
    {
        $this->customer = $customer;
        $this->api_key = $api_key;
        $this->sandbox = $sandbox;
    }

    /**
     * Send an API request to Realtime Register
     *
     * @param string $route The path to the API method
     * @param array $body The data to be sent
     * @param string $method Data transfer method (POST, GET, PUT, DELETE)
     * @return RealtimeRegisterResponse The API response
     */
    public function apiRequest(string $route, array $body = [], string $method = 'GET')
    {
        if ($this->sandbox) {
            $url = rtrim($this->api_url['sandbox'], '/') . '/' . ltrim($route, '/');
        } else {
            $url = rtrim($this->api_url['live'], '/') . '/' . ltrim($route, '/');
        }

        $ch = curl_init();

        switch (strtoupper($method)) {
            case 'DELETE':
            case 'GET':
                $url .= empty($body) ? '' : '?' . http_build_query($body);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
            default:
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                break;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        if (Configure::get('Blesta.curl_verify_ssl')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        curl_setopt($ch, CURLOPT_SSLVERSION, 1);

        // Set authentication header
        $headers = [
            'Authorization: ApiKey ' . trim($this->api_key),
            'Content-Type: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->last_request = ['content' => $body];

        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = [
                'error' => 'Curl Error',
                'message' => 'An internal error occurred, or the server did not respond to the request.',
                'status' => 500
            ];

            return new RealtimeRegisterResponse(['content' => json_encode($error)]);
        }
        curl_close($ch);

        // Return request response
        return new RealtimeRegisterResponse(['content' => $data, 'code' => $code]);
    }

    /**
     * Fetch customer price list
     *
     * @return RealtimeRegisterResponse The API response
     */
    public function priceList()
    {
        return $this->apiRequest('/v2/customers/' . $this->customer . '/pricelist');
    }

    /**
     * Check if a domain is available for registration
     *
     * @param string $domain The domain to be fetched
     * @return RealtimeRegisterResponse The API response
     */
    public function checkDomain(string $domain)
    {
        return $this->apiRequest('/v2/domains/' . $domain . '/check');
    }

    /**
     * Fetches an existing domain
     *
     * @param string $domain The domain to be fetched
     * @return RealtimeRegisterResponse The API response
     */
    public function getDomain(string $domain)
    {
        return $this->apiRequest('/v2/domains/' . $domain);
    }

    /**
     * Creates a new domain
     *
     * @param string $domain The domain to be registered
     * @param array $params An array containing:
     *  - registrant The contact handle of the registrant
     *  - privacyProtect Whether or not to include privacy protect (optional)
     *  - autoRenew Whether or not to renew the domain automatically, true by default (optional)
     *  - period The period to add to the domain during the transfer in months,
     *       defaults to the minimum period allowed by the registry (optional)
     *  - ns A list of nameservers for the domain (optional)
     * @return RealtimeRegisterResponse The API response
     */
    public function createDomain(string $domain, array $params)
    {
        // Set customer for the current request
        $params['customer'] = $this->customer;

        // If the period is less than 12, probably was given in years
        if (isset($params['period']) && is_numeric($params['period']) && $params['period'] < 12) {
            $params['period'] = $params['period'] * 12;
        }

        return $this->apiRequest('/v2/domains/' . $domain, $params, 'POST');
    }

    /**
     * Updates an existing domain
     *
     * @param string $domain The domain to be registered
     * @param array $params An array containing:
     *  - registrant The contact handle of the registrant
     *  - privacyProtect Whether or not to include privacy protect (optional)
     *  - autoRenew Whether or not to renew the domain automatically, true by default (optional)
     *  - ns A list of nameservers for the domain (optional)
     * @return RealtimeRegisterResponse The API response
     */
    public function updateDomain(string $domain, array $params)
    {
        return $this->apiRequest('/v2/domains/' . $domain . '/update', $params, 'POST');
    }

    /**
     * Transfers an existing domain
     *
     * @param string $domain The domain to be transferred
     * @param array $params An array containing:
     *  - registrant The contact handle of the registrant
     *  - privacyProtect Whether or not to include privacy protect (optional)
     *  - autoRenew Whether or not to renew the domain automatically, true by default (optional)
     *  - period The period to add to the domain during the transfer in months,
     *      defaults to the minimum period allowed by the registry (optional)
     *  - ns A list of nameservers for the domain (optional)
     *  - authcode The current auth code for the domain name
     * @return RealtimeRegisterResponse The API response
     */
    public function transferDomain(string $domain, array $params)
    {
        // Set customer for the current request
        $params['customer'] = $this->customer;

        // If the period is less than 12, probably was given in years
        if (isset($params['period']) && is_numeric($params['period']) && $params['period'] < 12) {
            $params['period'] = $params['period'] * 12;
        }

        return $this->apiRequest('/v2/domains/' . $domain . '/transfer', $params, 'POST');
    }

    /**
     * Renews an existing domain
     *
     * @param string $domain The domain to be renewed
     * @param array $params An array containing:
     *  - period The period to add to the domain during the transfer in months,
     *      defaults to the minimum period allowed by the registry
     * @return RealtimeRegisterResponse The API response
     */
    public function renewDomain(string $domain, array $params)
    {
        // Set customer for the current request
        $params['customer'] = $this->customer;

        // If the period is less than 12, probably was given in years
        if (isset($params['period']) && is_numeric($params['period']) && $params['period'] < 12) {
            $params['period'] = $params['period'] * 12;
        }

        return $this->apiRequest('/v2/domains/' . $domain . '/renew', $params, 'POST');
    }

    /**
     * Deletes an existing domain
     *
     * @param string $domain The domain to be renewed
     * @return RealtimeRegisterResponse The API response
     */
    public function deleteDomain(string $domain)
    {
        return $this->apiRequest('/v2/domains/' . $domain, [], 'DELETE');
    }

    /**
     * Create a contact
     *
     * @param string $handle The contact handle
     * @param array $params An array containing:
     *  - name The name of the person
     *  - organization The name of the organization
     *  - addressLine The address lines
     *  - postalCode Postal code
     *  - city The city
     *  - country The ISO 3166-1 alpha2 country code
     *  - email The email address
     *  - voice The voice telephone number. E164a format
     * @return RealtimeRegisterResponse The API response
     */
    public function createContact(string $handle, array $params)
    {
        return $this->apiRequest('/v2/customers/' . $this->customer . '/contacts/' . $handle, $params, 'POST');
    }

    /**
     * Append properties to a contact
     *
     * @param string $handle The contact handle
     * @param array $params The properties to append to the contact
     * @return RealtimeRegisterResponse The API response
     */
    public function appendPropertiesContact(string $handle, string $registry, array $params)
    {
        return $this->apiRequest(
            '/v2/customers/' . $this->customer . '/contacts/' . $handle . '/' . $registry,
            ['properties' => $params],
            'POST'
        );
    }

    /**
     * Fetches an existing contact
     *
     * @param string $handle The contact handle
     * @return RealtimeRegisterResponse The API response
     */
    public function getContact(string $handle)
    {
        return $this->apiRequest('/v2/customers/' . $this->customer . '/contacts/' . $handle);
    }

    /**
     * Deletes an existing contact
     *
     * @param string $handle The contact handle
     * @return RealtimeRegisterResponse The API response
     */
    public function deleteContact(string $handle)
    {
        return $this->apiRequest('/v2/customers/' . $this->customer . '/contacts/' . $handle, [], 'DELETE');
    }

    /**
     * Fetch an existing host
     *
     * @param string $host The host name
     * @return RealtimeRegisterResponse The API response
     */
    public function getHost(string $host)
    {
        return $this->apiRequest('/v2/hosts/' . $host);
    }

    /**
     * Creates a new host
     *
     * @param string $host The host name
     * @param string $ip_address The IP address of the new host
     * @return RealtimeRegisterResponse The API response
     */
    public function createHost(string $host, array $ip_address)
    {
        $params = [
            'addresses' => []
        ];

        foreach ($ip_address as $ip) {
            if (empty($ip)) {
                continue;
            }

            $params['addresses'][] = [
                'ipVersion' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'V4' : 'V6',
                'address' => $ip
            ];
        }

        return $this->apiRequest('/v2/hosts/' . $host, $params, 'POST');
    }

    /**
     * Deletes an existing host
     *
     * @param string $host The host name
     * @return RealtimeRegisterResponse The API response
     */
    public function deleteHost(string $host)
    {
        return $this->apiRequest('/v2/hosts/' . $host, [], 'DELETE');
    }

    /**
     * Fetch the existing hosts from a domain
     *
     * @param string $domain The domain to fetch the hosts
     * @return RealtimeRegisterResponse The API response
     */
    public function getDomainHosts(string $domain)
    {
        return $this->apiRequest('/v2/hosts?domain:eq=' . $domain);
    }

    /**
     * Fetch an existing DNS zone
     *
     * @param string $domain The domain to fetch the DNS zona
     * @return RealtimeRegisterResponse The API response
     */
    public function getZone(string $domain)
    {
        return $this->apiRequest('/v2/domains/' . $domain . '/zone/');
    }

    /**
     * Updates an existing DNS zone
     *
     * @param string $domain The domain to fetch the DNS zona
     * @return RealtimeRegisterResponse The API response
     */
    public function updateZone(string $domain, array $params)
    {
        return $this->apiRequest('/v2/domains/' . $domain . '/zone/update/', $params, 'POST');
    }

    /**
     * Fetch a TLD metadata
     *
     * @param string $tld The TLD to fetch the related metadata
     * @return RealtimeRegisterResponse The API response
     */
    public function getTld(string $tld)
    {
        return $this->apiRequest('/v2/tlds/' . $tld . '/info/');
    }
}
