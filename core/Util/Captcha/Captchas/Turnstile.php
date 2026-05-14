<?php
namespace Blesta\Core\Util\Captcha\Captchas;

use Blesta\Core\Util\Captcha\Common\AbstractCaptcha;
use Blesta\Core\Util\Input\Fields\InputFields;
use Language;
use Configure;

/**
 * CloudFlare Turnstile integration
 *
 * @package blesta
 * @subpackage core.Util.Captcha.Captchas
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Turnstile extends AbstractCaptcha
{
    /**
     * @var array An array of options
     */
    private $options = [];

    /**
     * @var string The CloudFlare Turnstile JavaScript API URL
     */
    private $apiUrl = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /**
     * @var string The CloudFlare Turnstile Verification API URL
     */
    private $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Initialize Turnstile
     */
    public function __construct()
    {
        parent::__construct();

        // Autoload the language file
        Language::loadLang(
            'turnstile',
            $this->Html->safe(
                ($this->options['lang'] ?? ''),
                Configure::get('Blesta.language')
            ),
            COREDIR . 'Util' . DS . 'Captcha' . DS . 'Captchas' . DS . 'language' . DS
        );
    }

    /**
     * Returns the name of the captcha provider
     *
     * @return string The name of the captcha provider
     */
    public function getName()
    {
        return Language::_('Turnstile.name', true);
    }

    /**
     * Builds the HTML content to render the Turnstile
     *
     * @return string The HTML
     */
    public function buildHtml()
    {
        $key = $this->Html->safe(($this->options['turnstile_site_key'] ?? null));
        $lang = $this->Html->safe(($this->options['lang'] ?? null));
        $apiUrl = $this->Html->safe(
            $this->apiUrl . (!empty($lang) ? '?language=' . $lang : '')
        );

        $html = <<< HTML
<div class="cf-turnstile" data-sitekey="$key" data-theme="light"></div>
<script type="text/javascript" src="$apiUrl"></script>
HTML;

        return $html;
    }

    /**
     * Sets Turnstile options
     *
     * @param array $options An array of options including:
     *
     *  - turnstile_secret_key The Turnstile site key
     *  - turnstile_site_key The Turnstile shared key
     *  - lang The user's language (e.g. "en" for English)
     *  - ip_address The user's IP address (optional)
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    /**
     * Verifies whether or not the captcha is valid
     *
     * @param array $data An array of data to validate against, including:
     *
     *  - response The value of 'g-turnstile-response' in the submitted form
     * @return bool Whether or not the captcha is valid
     */
    public function verify(array $data)
    {
        $success = false;

        // Attempt to verify the captcha was accepted
        try {
            $data = [
                'secret' => $this->options['turnstile_secret_key'] ?? null,
                'response' => $data['cf-turnstile-response'] ?? null
            ];

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $this->verifyUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);

            if ($response === false) {
                $this->setErrors(['curl' => ['error' => curl_error($ch)]]);
            } else {
                $response = json_decode($response);

                return $response->success ?? false;
            }

            curl_close($ch);
        } catch (\Throwable $e) {
            // Turnstile could not process the request due to missing data
            $this->setErrors(['turnstile' => ['error' => $e->getMessage()]]);
        }

        return $success;
    }

    /**
     * Gets a list of the options input fields
     *
     * @param array $vars An array containing the posted fields
     * @return InputFields An object representing the list of input fields
     */
    public function getOptionFields(array $vars = [])
    {
        // Set captcha option fields
        $fields = new InputFields();

        // Set site key
        $site_key = $fields->label(
            Language::_('Turnstile.options.field_turnstile_site_key', true),
            'turnstile_site_key'
        );
        $site_key->attach(
            $fields->fieldText(
                'turnstile_site_key',
                $vars['turnstile_site_key'] ?? null,
                [
                    'id' => 'turnstile_site_key',
                    'class' => 'form-control'
                ]
            )
        );
        $fields->setField($site_key);

        // Set secret key
        $secret_key = $fields->label(
            Language::_('Turnstile.options.field_turnstile_secret_key', true),
            'turnstile_secret_key'
        );
        $secret_key->attach(
            $fields->fieldText(
                'turnstile_secret_key',
                $vars['turnstile_secret_key'] ?? null,
                [
                    'id' => 'turnstile_secret_key',
                    'class' => 'form-control'
                ]
            )
        );
        $fields->setField($secret_key);

        return $fields;
    }
}
