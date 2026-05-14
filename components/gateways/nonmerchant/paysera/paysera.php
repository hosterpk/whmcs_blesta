<?php
/**
 * Paysera Gateway
 *
 * @package blesta
 * @subpackage blesta.components.gateways.paysera
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Paysera extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new merchant gateway
     */
    public function __construct()
    {
        // Load configuration required by this gateway
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this gateway
        Language::loadLang('paysera', null, dirname(__FILE__) . DS . 'language' . DS);
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
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically add to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'paysera' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

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
        $rules = [
            'project_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Paysera.!error.project_id.empty', true)
                ]
            ],
            'project_password' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Paysera.!error.project_password.empty', true)
                ],
                'valid' => [
                    'rule' => [[$this, 'validateConnection'], $meta['project_id']],
                    'message' => Language::_('Paysera.!error.project_password.valid', true)
                ]
            ],
            'sandbox'=>[
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Paysera.!error.sandbox.valid', true)
                ]
            ]
        ];

        // Set checkbox if not set
        if (!isset($meta['sandbox'])) {
            $meta['sandbox'] = 'false';
        }

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['project_password'];
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
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used in conjunction
     *          with term in order to determine the next recurring payment
     * @return string HTML markup required to render an authorization and capture payment form
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // Force 2-decimal places only
        $amount = round($amount, 2);
        if (isset($options['recur']['amount'])) {
            $options['recur']['amount'] = round($options['recur']['amount'], 2);
        }

        // Build payment
        $payment = [
            'projectid' => $this->meta['project_id'],
            'sign_password' => $this->meta['project_password'],
            'orderid' => ($contact_info['client_id'] ?? null) . '-' . time(),
            'paytext' => ($options['description'] ?? null),
            'amount' => $amount * 100,
            'currency' => $this->currency,
            'country' => $contact_info['country']['alpha2'] ?? null,
            'accepturl' => $options['return_url'] . '&invoices=' . $this->serializeInvoices($invoice_amounts),
            'cancelurl' => $options['return_url'] . '&invoices=' . $this->serializeInvoices($invoice_amounts),
            'callbackurl' => Configure::get('Blesta.gw_callback_url')
                . Configure::get('Blesta.company_id') . '/paysera/?invoices=' . $this->serializeInvoices($invoice_amounts),
            'test' => ($this->meta['sandbox'] == 'false' ? 0 : 1)
        ];

        if (isset($_GET['proceed']) && $_GET['proceed'] == 'true') {
            WebToPay::redirectToPayment($payment, true);
        }

        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @param $return_status
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
     *  - parent_transaction_id The ID returned by the gateway to identify this
     *      transaction's original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        Loader::loadModels($this, ['Clients']);

        // Parse response
        try {
            $response = WebToPay::validateAndParseData(
                $get,
                $this->meta['project_id'],
                $this->meta['project_password']
            );

            $success = ($response['status'] ?? null) == 1;

            // Log the response
            $this->log(($_SERVER['REQUEST_URI'] ?? null), serialize($response), 'output', $success);
        } catch (Throwable $e) {
            $success = false;

            // Log the response
            $this->log(($_SERVER['REQUEST_URI'] ?? null), serialize($e), 'output', $success);
        }

        if (!$success) {
            return;
        }

        // Fetch client id
        $client_id = null;
        $order_parts = explode('-', $response['orderid'] ?? '', 2);
        if (isset($order_parts[0]) && $this->Clients->get($order_parts[0])) {
            $client_id = $order_parts[0];
        }

        // Print expected response by the Paysera api
        echo 'OK';

        return [
            'client_id' => $client_id,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : null,
            'currency' => $response['currency'] ?? null,
            'invoices' => $this->unserializeInvoices($get['invoices'] ?? null),
            'status' => 'approved',
            'reference_id' => $response['orderid'] ?? null,
            'transaction_id' => $response['requestid'] ?? null,
            'parent_transaction_id' => null
        ];
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
        try {
            $response = WebToPay::validateAndParseData(
                $get,
                $this->meta['project_id'],
                $this->meta['project_password']
            );
        } catch (Throwable $e) {
            // Nothing to do
        }

        $params = [
            'client_id' => $get['client_id'] ?? null,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : null,
            'currency' => $response['currency'] ?? null,
            'invoices' => $this->unserializeInvoices($get['invoices'] ?? null),
            'status' => isset($response) ? 'approved' : 'declined',
            'transaction_id' => $response['requestid'] ?? null,
            'parent_transaction_id' => null
        ];

        return $params;
    }

    /**
     * Refund a payment
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this transaction
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a payment or authorization.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return base64_encode($str);
    }

    /**
     * Unserializes a string of invoice info into an array
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $str = base64_decode($str);

        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }
        return $invoices;
    }

    /**
     * Validates if the provided project password is valid
     *
     * @param string $project_password The project password
     * @param string $project_id The project ID
     * @return bool True if the API Key is valid, false otherwise
     */
    public function validateConnection($project_password, $project_id)
    {
        try {
            $data = [
                'orderid' => 0,
                'amount' => 1,
                'currency' => 'USD',
                'country' => 'LT',
                'accepturl' => Configure::get('Blesta.gw_callback_url')
                    . Configure::get('Blesta.company_id') . '/paysera/',
                'cancelurl' => Configure::get('Blesta.gw_callback_url')
                    . Configure::get('Blesta.company_id') . '/paysera/',
                'callbackurl' => Configure::get('Blesta.gw_callback_url')
                    . Configure::get('Blesta.company_id') . '/paysera/',
                'test' => 1
            ];
            $factory = new WebToPay_Factory(array('projectId' => $project_id, 'password' => $project_password));
            $response = $factory->getRequestBuilder()
                ->buildRequestUrlFromData($data);

            return !empty($response);
        } catch (Throwable $e) {
            $this->Input->setErrors(['project_password' => ['response' => $e->getMessage()]]);

            return false;
        }
    }
}
