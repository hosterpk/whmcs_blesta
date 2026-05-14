<?php
/**
 * Plesk Server management
 *
 * Handles server-level operations including session token creation for SSO
 *
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @package plesk.commands
 */
class PleskServer extends PleskPacket
{
    /**
     * @var The earliest version this API may be used with
     */
    private $earliest_api_version = '1.6.0.0';
    /**
     * @var The PleskApi
     */
    private $api;
    /**
     * @var The base XML container for this API
     */
    private $base_container = '/server';

    /**
     * Sets the API to use for communication
     *
     * @param PleskApi $api The API to use for communication
     * @param string $api_version The API RPC version
     */
    public function __construct(PleskApi $api, $api_version)
    {
        parent::__construct($api_version);
        $this->api = $api;

        $this->buildContainer();
    }

    /**
     * Retrieves the earliest version this API command supports
     *
     * @return string The earliest API RPC version supported
     */
    public function getEarliestVersion()
    {
        return $this->earliest_api_version;
    }

    /**
     * Builds the packet container for the API command
     */
    protected function buildContainer()
    {
        $this->insert(['server' => null]);
        $this->setContainer($this->base_container);
    }

    /**
     * Creates a session token for SSO login
     *
     * @param array $params An array containing:
     *   - login: The login name of the user
     *   - user_ip: The user's IP address (will be base64 encoded)
     *   - source_server: Optional source server URL
     * @return PleskResponse The response object
     */
    public function createSession(array $params)
    {
        // Build the create_session element
        $session_data = [
            'login' => $params['login'] ?? '',
            'data' => [
                'user_ip' => base64_encode($params['user_ip'] ?? ''),
                'source_server' => base64_encode($params['source_server'] ?? '')
            ]
        ];

        // Set the container for this API request
        $this->insert(['create_session' => $session_data], $this->getContainer());
        $this->setContainer($this->base_container . '/create_session');

        return $this->api->submit($this->fetch(), $this->getContainer());
    }
}