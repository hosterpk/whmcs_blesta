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
     * @var int The gateway ID assigned by Blesta
     */
    protected $kuickpay_gateway_id;

    /**
     * @var array Credential-bearing fields that must be redacted from gateway-owned diagnostics
     */
    private $credential_mask_fields = [
        'voucher_password',
        'inquiry_password',
        'voucher_username',
        'inquiry_username',
        'password',
        'userName',
        'Password',
        'UserName',
    ];

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
     * Sets this gateway's ID.
     *
     * @param int $id The gateway ID
     */
    public function setGatewayId($id)
    {
        if (is_callable('parent::setGatewayId')) {
            parent::setGatewayId($id);
        }

        $this->kuickpay_gateway_id = $id;
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
        $this->view->set('voucher_password_stored', !empty($meta['voucher_password']));
        $this->view->set('inquiry_password_stored', !empty($meta['inquiry_password']));

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

        // Trim required identifier fields so whitespace-only input is treated as empty.
        // Blesta's isEmpty() rule only checks string length, so " " would otherwise pass.
        foreach (['voucher_username', 'inquiry_username', 'institution_id'] as $textField) {
            if (isset($meta[$textField]) && is_string($meta[$textField])) {
                $meta[$textField] = trim($meta[$textField]);
            }
        }

        $same = (($meta['inquiry_same_as_voucher'] ?? 'false') === 'true');
        // When inquiry credentials reuse voucher credentials, store exactly one credential pair.
        // Inquiry operations must read voucher_* when inquiry_same_as_voucher === 'true'.
        if ($same) {
            unset($meta['inquiry_username'], $meta['inquiry_password']);
        }

        $optionalNumericRule = ['matches', '/^([0-9]+)?$/D'];
        $referencePatternRule = ['matches', '/^[A-Za-z0-9_{}+\-]+$/D'];

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

        if (!$this->Input->errors() && (($meta['run_connection_test'] ?? 'false') === 'true')) {
            $this->runConnectionTest($meta);
        }

        unset($meta['run_connection_test']);

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
     * Masks credential-bearing fields before gateway-owned logging, diagnostics, or error messages.
     *
     * All KuickPay credential-bearing data the gateway itself logs, embeds in an exception/error message,
     * or writes to a diagnostic must pass through this first.
     *
     * @param array $data Credential-bearing data to mask
     * @return array The masked data
     */
    protected function maskCredentials(array $data)
    {
        return $this->maskDataRecursive($data, $this->credential_mask_fields);
    }

    /**
     * Runs the safe settings-time connection test.
     *
     * This probe intentionally fetches only the configured WSDL document. It sends no credentials, logs nothing,
     * creates no voucher, and does not validate server-side credential failures. The authenticated safe-op test
     * and live labeled-voucher test are deferred to Story 5-1 / Epic 3 after the KuickPay contract is confirmed.
     *
     * @param array $meta The settings data being tested
     */
    private function runConnectionTest(array $meta)
    {
        if (!function_exists('curl_init')) {
            $this->Input->setErrors([
                'connection' => [
                    'unavailable' => Language::_('Kuickpay.!error.connection.unavailable', true),
                ],
            ]);
            return;
        }

        $url = (string) ($meta['wsdl_url'] ?? '');
        if (
            strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        ) {
            $this->Input->setErrors([
                'connection' => [
                    'url_userinfo' => Language::_('Kuickpay.!error.connection.url_userinfo', true),
                ],
            ]);
            return;
        }

        // SSRF guard: resolve the host and reject private, loopback, link-local, or
        // otherwise reserved addresses so the server-side fetch cannot be steered at
        // internal services or the cloud metadata endpoint (169.254.169.254). A literal
        // IP is checked directly; a hostname is resolved through the system resolver
        // (which honors /etc/hosts, so "localhost" is caught) and every resolved address
        // must be public. A host that resolves to nothing is treated as blocked rather
        // than handed to an unvalidated cURL re-resolution. Residual, deferred to the
        // Epic 5 confirmed-endpoint allowlist: IPv6-only hosts named via DNS are not
        // resolved here (gethostbynamel is IPv4-only).
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveProbeAddresses($host);

        $blocked = ($host === '' || $addresses === []);
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            $this->Input->setErrors([
                'connection' => [
                    'url_blocked' => Language::_('Kuickpay.!error.connection.url_blocked', true),
                ],
            ]);
            return;
        }

        // Pin the validated address(es) so cURL connects only to what was checked,
        // closing the DNS-rebinding window between this check and the fetch.
        $port = parse_url($url, PHP_URL_PORT);
        $resolve = [];
        foreach ($addresses as $address) {
            $resolve[] = $host . ':' . (is_int($port) ? $port : 443) . ':' . $address;
        }

        $timeout = (int) ($meta['soap_timeout'] ?? 0);
        if ($timeout < 1) {
            $timeout = 30;
        }
        $timeout = min(120, $timeout);

        $result = $this->executeConnectionProbe($url, [
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_RESOLVE => $resolve,
        ]);

        if ((int) ($result['errno'] ?? 0) === 0 && (int) ($result['response_code'] ?? 0) > 0) {
            return;
        }

        if ((int) ($result['errno'] ?? 0) === CURLE_OPERATION_TIMEDOUT) {
            $this->Input->setErrors([
                'connection' => [
                    'timeout' => Language::_('Kuickpay.!error.connection.timeout', true),
                ],
            ]);
            return;
        }

        $this->Input->setErrors([
            'connection' => [
                'unreachable' => Language::_('Kuickpay.!error.connection.unreachable', true),
            ],
        ]);
    }

    /**
     * Executes the cURL transport probe.
     *
     * Callers must validate and pin the host first (see runConnectionTest()'s SSRF guard).
     * A broader confirmed-endpoint host allowlist is deferred until the production KuickPay
     * endpoint set is confirmed (Epic 5).
     *
     * @param string $url The HTTPS WSDL URL to fetch
     * @param array $options cURL options for the bounded reachability request
     * @return array The cURL errno and HTTP response code
     */
    protected function executeConnectionProbe($url, array $options)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['errno' => CURLE_FAILED_INIT, 'response_code' => 0];
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);

        $result = [
            'errno' => curl_errno($ch),
            'response_code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        ];

        curl_close($ch);

        return $result;
    }

    /**
     * Resolves a probe hostname to its IP addresses through the system resolver.
     *
     * Split out as a seam so connection tests can exercise the SSRF guard without DNS.
     *
     * @param string $host The WSDL hostname to resolve
     * @return array The resolved IPv4 addresses, or an empty array when none resolve
     */
    protected function resolveProbeAddresses($host)
    {
        $addresses = gethostbynamel($host);

        return is_array($addresses) ? $addresses : [];
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
     * Builds the KuickPay SOAP transport client from gateway meta.
     *
     * @return KuickPaySoapClient The configured SOAP transport wrapper
     */
    protected function getSoapClient()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayRedactor.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPaySoapClient.php');

        $meta = is_array($this->meta) ? $this->meta : [];

        return new KuickPaySoapClient([
            'wsdl_url' => $meta['wsdl_url'] ?? '',
            'soap_timeout' => $meta['soap_timeout'] ?? '',
            'institution_id' => $meta['institution_id'] ?? '',
            'voucher_username' => $meta['voucher_username'] ?? '',
            'voucher_password' => $meta['voucher_password'] ?? '',
            'inquiry_username' => $meta['inquiry_username'] ?? '',
            'inquiry_password' => $meta['inquiry_password'] ?? '',
            'inquiry_same_as_voucher' => $meta['inquiry_same_as_voucher'] ?? 'false',
            'logging_enabled' => $meta['logging_enabled'] ?? 'false',
        ]);
    }

    /**
     * Determines whether the active payment currency is eligible for KuickPay.
     *
     * Eligibility is read from getCurrencies() (config.json) so no currency value
     * is hard-coded. This mirrors GatewayManager::currencyExists() and fails closed
     * for non-configured, unset, empty, or unloaded currencies.
     *
     * @return bool True when the active currency is configured for this gateway
     */
    protected function currencyEligible()
    {
        return in_array((string) ($this->currency ?? ''), (array) $this->getCurrencies(), true);
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

        // Blesta's gateway_currencies join is the primary currency listing gate.
        // This backstop protects buildProcess() before Story 2.3 adds voucher creation;
        // InsertVoucher/voucher persistence must stay behind it so ineligible currency can never create a Voucher.
        if (!$this->currencyEligible()) {
            $this->Input->setErrors(
                ['currency' => ['ineligible' => Language::_('Kuickpay.!error.currency_ineligible', true)]]
            );
        } elseif (!$this->companionInstalled()) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        if (!$this->Input->errors()) {
            $meta = is_array($this->meta) ? $this->meta : [];
            $service = $this->getVoucherReferenceService();
            $context = $this->buildVoucherReferenceContext(
                $contact_info,
                $amount,
                (array) $invoice_amounts,
                $meta
            );
            $voucher = $service->getOrCreateForInvoiceContext($context);

            if ($voucher !== null) {
                $this->view->set('voucher', $voucher);
            } else {
                $this->recordReferenceGenerationFailure($service, $context['invoice_amounts'], $meta);
            }
        }

        return $this->view->fetch();
    }

    /**
     * Gets the companion plugin voucher reference service.
     *
     * @return KuickPayVoucherReferenceService The reference service
     */
    protected function getVoucherReferenceService()
    {
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherReferenceService.php');

        return new KuickPayVoucherReferenceService();
    }

    /**
     * Builds the voucher reference context passed to the companion plugin.
     *
     * @param array $contact_info Contact data from Blesta
     * @param mixed $amount Payment amount
     * @param array $invoice_amounts Invoice amount rows
     * @param array $meta Gateway settings
     * @return array Voucher reference context
     */
    protected function buildVoucherReferenceContext(
        array $contact_info,
        $amount,
        array $invoice_amounts,
        array $meta
    ): array {
        return [
            'company_id' => Configure::get('Blesta.company_id'),
            'gateway_id' => $this->kuickpay_gateway_id,
            'client_id' => $contact_info['client_id'] ?? null,
            'currency' => $this->currency,
            'amount' => $this->normalizeAmount((string) $amount),
            'invoice_amounts' => $this->normalizeInvoiceAmounts($invoice_amounts),
            'institution_id' => $meta['institution_id'] ?? '',
            'registration_number_pattern' => $meta['registration_number_pattern'] ?? '',
            'consumer_number_pattern' => $meta['consumer_number_pattern'] ?? '',
            'due_date_offset_days' => (int) ($meta['due_date_offset_days'] ?? 0),
            'expiry_date_offset_days' => (int) ($meta['expiry_date_offset_days'] ?? 0),
        ];
    }

    /**
     * Records an admin-safe reference generation diagnostic.
     *
     * @param mixed $service Reference service exposing getLastError()
     * @param array $invoice_amounts Normalized invoice amount rows
     * @param array $meta Gateway settings
     */
    protected function recordReferenceGenerationFailure($service, array $invoice_amounts, array $meta): void
    {
        if (($meta['logging_enabled'] ?? 'true') !== 'true' || !method_exists($service, 'getLastError')) {
            return;
        }

        $reason = $service->getLastError();
        if ($reason === null) {
            return;
        }

        $this->log(
            'kuickpay:reference_generation',
            json_encode([
                'event' => 'reference_generation_failed',
                'reason' => $reason,
                'invoice' => $invoice_amounts[0]['id'] ?? null,
            ]),
            'output',
            false
        );
    }

    /**
     * Normalizes an amount as a decimal string without float math.
     *
     * @param string $amount The amount to normalize
     * @return string The normalized amount, or original trimmed input when invalid
     */
    protected function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);
        $normalized = str_replace(',', '', $amount);

        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return $amount;
        }

        $parts = explode('.', $normalized, 2);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') {
            $integer = '0';
        }
        $fraction = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);

        return $integer . '.' . $fraction;
    }

    /**
     * Normalizes invoice amount allocations as decimal strings.
     *
     * @param array $invoice_amounts Invoice amount allocations
     * @return array Normalized invoice amount allocations
     */
    protected function normalizeInvoiceAmounts(array $invoice_amounts): array
    {
        foreach ($invoice_amounts as &$invoice_amount) {
            if (isset($invoice_amount['amount'])) {
                $invoice_amount['amount'] = $this->normalizeAmount((string) $invoice_amount['amount']);
            }
        }
        unset($invoice_amount);

        return $invoice_amounts;
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
