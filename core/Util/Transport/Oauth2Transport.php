<?php

namespace Blesta\Core\Util\Transport;

use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Configure;

/**
 * Sends Emails over SMTP with ESMTP support using OAuth2 authentication.
 *
 * @package blesta
 * @subpackage core.Util.Transport
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Oauth2Transport extends EsmtpTransport
{
    private $providers = [
        'google' => [
            'smtp' => 'smtp.gmail.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'scope' => ['https://mail.google.com/']
        ],
        'microsoft' => [
            'smtp' => 'smtp-mail.outlook.com',
            'authorization_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => [
                'offline_access',
                'https://outlook.office.com/IMAP.AccessAsUser.All',
                'https://outlook.office.com/POP.AccessAsUser.All',
                'https://outlook.office.com/SMTP.Send'
            ]
        ]
    ];

    private $authenticators = [];
    private $provider;
    private $refresh_token = null;
    private $auth_code = null;
    private $client_id = null;
    private $client_secret = null;
    private $redirect_uri = null;

    /**
     * Initializes OAuth2 transport
     */
    public function __construct(
        string $host = 'localhost',
        int $port = 0,
        ?bool $tls = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    )
    {
        parent::__construct($host, $port, $tls, $dispatcher, $logger);

        if (!isset($this->Companies)) {
            \Loader::loadModels($this, ['Companies']);
        }

        // Force OAuth2 authentication
        $this->authenticators = [
            new XOAuth2Authenticator()
        ];

        // Set access token, if previously saved
        $access_token = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'oauth2_access_token');
        if (!empty($access_token->value)) {
            $this->setAccessToken($access_token->value);
        }

        // Set refresh token, if previously saved
        $refresh_token = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'oauth2_refresh_token');
        if (!empty($refresh_token->value)) {
            $this->setRefreshToken($refresh_token->value);
        }
    }

    /**
     * Sets the OAuth2.0 provider to use
     *
     * @param string $provider The provider to use for authentication, it could be 'google' or 'microsoft'
     */
    public function setProvider(string $provider)
    {
        if (array_key_exists($provider, $this->providers)) {
            $this->provider = $provider;
        } else {
            throw new \Exception('The provider "' . $provider . '" does not exist.');
        }

        return $this;
    }

    /**
     * Returns the current OAuth2.0 provider
     *
     * @return array An array representing the current provider, null if provider doesn't exist or hasn't been defined
     */
    public function getProvider()
    {
        return $this->providers[$this->provider] ?? null;
    }

    /**
     * Returns the authorization URL for the current provider
     *
     * @param array $params An array of URI parameters to pass to authorization endpoint (optional)
     * @return string The authorization URL of the provider
     */
    public function getAuthorizationUrl(array $params = [])
    {
        $uri = '';
        if (!empty($params)) {
            $uri = '?' . http_build_query($params);
        }

        return !empty($this->providers[$this->provider]['authorization_endpoint'])
            ? $this->providers[$this->provider]['authorization_endpoint'] . $uri
            : null;
    }

    /**
     * Returns the authorization scope for the current provider
     *
     * @return string The authorization scope of the provider
     */
    public function getScope()
    {
        return is_array($this->providers[$this->provider]['scope'])
            ? implode(' ', $this->providers[$this->provider]['scope'])
            : null;
    }

    /**
     * Set the OAuth2 Access Token
     *
     * @return $this An instance of this object
     */
    public function setAccessToken(string $password)
    {
        $this->setPassword($password);

        return $this;
    }

    /**
     * Returns the OAuth2 Access Token
     *
     * @return $this An instance of this object
     */
    public function getAccessToken()
    {
        return $this->getPassword();
    }

    /**
     * Set the OAuth2 Refresh Token
     *
     * @return $this An instance of this object
     */
    public function setRefreshToken(string $token)
    {
        $this->refresh_token = $token;

        return $this;
    }

    /**
     * Returns the OAuth2 Refresh Token
     *
     * @return $this An instance of this object
     */
    public function getRefreshToken()
    {
        return $this->refresh_token;
    }

    /**
     * Set the OAuth2 Authorization Code
     *
     * @return $this An instance of this object
     */
    public function setAuthorizationCode(string $code)
    {
        $this->auth_code = $code;

        return $this;
    }

    /**
     * Returns the OAuth2 Authorization Code
     *
     * @return $this An instance of this object
     */
    public function getAuthorizationCode()
    {
        return $this->auth_code;
    }

    /**
     * Set the OAuth2 Client ID
     *
     * @return $this An instance of this object
     */
    public function setClientId(string $client_id)
    {
        $this->client_id = $client_id;

        return $this;
    }

    /**
     * Returns the OAuth2 Client ID
     *
     * @return $this An instance of this object
     */
    public function getClientId()
    {
        return $this->client_id;
    }

    /**
     * Set the OAuth2 Client Secret
     *
     * @return $this An instance of this object
     */
    public function setClientSecret(string $client_secret)
    {
        $this->client_secret = $client_secret;

        return $this;
    }

    /**
     * Returns the OAuth2 Client Secret
     *
     * @return $this An instance of this object
     */
    public function getClientSecret()
    {
        return $this->client_secret;
    }

    /**
     * Set the OAuth2 Redirect URI
     *
     * @return $this An instance of this object
     */
    public function setRedirectUri(string $redirect_uri)
    {
        $this->redirect_uri = $redirect_uri;

        return $this;
    }

    /**
     * Returns the OAuth2 Redirect URI
     *
     * @return $this An instance of this object
     */
    public function getRedirectUri()
    {
        \Loader::loadModels($this, ['Companies']);

        // Set default redirect URI
        $base_url = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '')
            . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null);
        $redirect_uri = rtrim($base_url, '/') . '/' . ltrim(WEBDIR, '/') . Configure::get('Route.admin') . '/settings/company/emails/mail/';

        return empty($this->redirect_uri) ? $redirect_uri : $this->redirect_uri;
    }

    /**
     * Updates the OAuth 2.0 tokens
     *
     * @return object An object containing the new access and refresh token
     */
    public function refreshAccessToken(bool $initial = false, bool $save = true)
    {
        $post = [
            'code' => $this->getAuthorizationCode(),
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code'
        ];

        if (!$initial) {
            $post['refresh_token'] = $this->getRefreshToken();
            $post['grant_type'] = 'refresh_token';

            unset($post['code']);
            unset($post['redirect_uri']);
        }

        $ch = curl_init($this->providers[$this->provider]['token_endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        $data = (array) json_decode($response, true);

        $token = (object) [
            'access_token' => $data['access_token'] ?? $data['AccessToken'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? $data['RefreshToken'] ?? null
        ];

        // Update tokens for the current company, if possible
        \Loader::loadModels($this, ['Companies']);
        $settings = [];
        if (!empty($token->access_token)) {
            $settings['oauth2_access_token'] = $token->access_token;
        }
        if (!empty($token->refresh_token)) {
            $settings['oauth2_refresh_token'] = $token->refresh_token;
        }

        if (!empty($settings) && $save) {
            $this->Companies->setSettings(Configure::get('Blesta.company_id'), $settings);
        }

        return $token;
    }
}
