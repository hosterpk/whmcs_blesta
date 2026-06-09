<?php
/**
 * KuickPay non-merchant gateway
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Kuickpay extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new non-merchant gateway
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this gateway
        Language::loadLang('kuickpay', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        $companion_installed = $this->companionInstalled();
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $currencyPolicyOptions = [
            'pkr_only' => Language::_('Kuickpay.currency_policy.pkr_only', true),
        ];

        $feePolicyOptions = [
            'none' => Language::_('Kuickpay.fee_policy.none', true),
        ];

        $this->view->set('meta', $meta);
        $this->view->set('companion_installed', $companion_installed);
        $this->view->set('currency_policy', $currencyPolicyOptions);
        $this->view->set('fee_policy', $feePolicyOptions);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        foreach ([
            'inquiry_same_as_voucher',
            'instruction_online_banking',
            'instruction_bank_deposit',
            'instruction_agent_franchise',
            'instruction_mobile_app',
            'logging_enabled',
            'reconciliation_enabled',
        ] as $checkbox) {
            if (!isset($meta[$checkbox])) {
                $meta[$checkbox] = 'false';
            }
        }

        if (!isset($meta['currency_policy']) || $meta['currency_policy'] === '') {
            $meta['currency_policy'] = 'pkr_only';
        }
        if (!isset($meta['fee_policy']) || $meta['fee_policy'] === '') {
            $meta['fee_policy'] = 'none';
        }

        $same = (($meta['inquiry_same_as_voucher'] ?? 'false') === 'true');
        $optionalNumericRule = ['matches', '/^([0-9]+)?$/'];
        $referencePatternRule = ['matches', '/^[A-Za-z0-9_{}+\-]+$/'];

        $rules = [
            'wsdl_url' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.wsdl_url.empty', true),
                ],
                'format' => [
                    'rule' => function ($url) {
                        return is_string($url)
                            && filter_var($url, FILTER_VALIDATE_URL) !== false
                            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
                    },
                    'message' => Language::_('Kuickpay.!error.wsdl_url.format', true),
                ],
            ],
            'voucher_username' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.voucher_username.empty', true),
                ],
            ],
            'voucher_password' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.voucher_password.empty', true),
                ],
            ],
            'institution_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.institution_id.empty', true),
                ],
            ],
            'registration_number_pattern' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.registration_number_pattern.empty', true),
                ],
                'format' => [
                    'rule' => $referencePatternRule,
                    'message' => Language::_('Kuickpay.!error.registration_number_pattern.format', true),
                ],
            ],
            'consumer_number_pattern' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.consumer_number_pattern.empty', true),
                ],
                'format' => [
                    'rule' => $referencePatternRule,
                    'message' => Language::_('Kuickpay.!error.consumer_number_pattern.format', true),
                ],
            ],
            'soap_timeout' => [
                'numeric' => [
                    'if_set' => true,
                    'rule' => $optionalNumericRule,
                    'message' => Language::_('Kuickpay.!error.soap_timeout.numeric', true),
                ],
            ],
            'due_date_offset_days' => [
                'numeric' => [
                    'if_set' => true,
                    'rule' => $optionalNumericRule,
                    'message' => Language::_('Kuickpay.!error.due_date_offset_days.numeric', true),
                ],
            ],
            'expiry_date_offset_days' => [
                'numeric' => [
                    'if_set' => true,
                    'rule' => $optionalNumericRule,
                    'message' => Language::_('Kuickpay.!error.expiry_date_offset_days.numeric', true),
                ],
            ],
            'currency_policy' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['pkr_only']],
                    'message' => Language::_('Kuickpay.!error.currency_policy.valid', true),
                ],
            ],
            'fee_policy' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['none']],
                    'message' => Language::_('Kuickpay.!error.fee_policy.valid', true),
                ],
            ],
        ];

        if (!$same) {
            $rules['inquiry_username'] = [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.inquiry_username.empty', true),
                ],
            ];
            $rules['inquiry_password'] = [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Kuickpay.!error.inquiry_password.empty', true),
                ],
            ];
        }

        foreach ([
            'inquiry_same_as_voucher',
            'instruction_online_banking',
            'instruction_bank_deposit',
            'instruction_agent_franchise',
            'instruction_mobile_app',
            'logging_enabled',
            'reconciliation_enabled',
        ] as $checkbox) {
            $rules[$checkbox] = [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Kuickpay.!error.' . $checkbox . '.valid', true),
                ],
            ];
        }

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['voucher_password', 'inquiry_password'];
    }

    /**
     * Sets the meta data for this particular gateway
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form
     *
     * @param array $contact_info An array of contact info including:
     *  - id The contact ID
     *  - client_id The ID of the client this contact belongs to
     *  - user_id The user ID this contact belongs to (if any)
     *  - contact_type The type of contact
     *  - contact_type_id The ID of the contact type
     *  - first_name The first name on the contact
     *  - last_name The last name on the contact
     *  - title The title of the contact
     *  - company The company name of the contact
     *  - address1 The address 1 line of the contact
     *  - address2 The address 2 line of the contact
     *  - city The city of the contact
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-cahracter country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *  - description The Description of the charge
     *  - return_url The URL to redirect users to after a successful payment
     *  - recur An array of recurring info including:
     *      - start_date The date/time in UTC that the recurring payment begins
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used
     *          in conjunction with term in order to determine the next recurring payment
     * @return mixed A string of HTML markup required to render an authorization and
     *  capture payment form, or an array of HTML markup
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Html']);

        if (!$this->companionInstalled()) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's
     *      original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
        return null;
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
        return null;
    }

    /**
     * Determines whether the companion plugin is installed for the current company
     *
     * @return bool True when the companion plugin is installed, false otherwise
     */
    private function companionInstalled()
    {
        Loader::loadModels($this, ['PluginManager']);

        return $this->PluginManager->isInstalled('kuickpay_reconcile', Configure::get('Blesta.company_id'));
    }
}
