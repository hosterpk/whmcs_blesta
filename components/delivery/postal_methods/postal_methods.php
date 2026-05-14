<?php
/**
 * PostalMethods service for physically mailing letters
 *
 * @package blesta
 * @subpackage components.delivery.postal_methods
 * @copyright Copyright (c) 2011, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

class PostalMethods extends DeliveryMethod
{
    /**
     * @var string The URL to submit requests to
     */
    private static $url = 'https://api.secure.postalmethods.com/v1';
    /**
     * @var string The account API key
     */
    private $api_key;
    /**
     * @var array An array of a file's bitstream data and file type
     */
    private $file = [];
    /**
     * @var string An optional description to associate with this letter
     */
    private $description;
    /**
     * @var array A list of address fields to send to
     */
    private $to_address;
    /**
     * @var array A list of return address fields to send to
     */
    private $return_address;
    /**
     * @var array A list of reply envelope address fields
     */
    private $reply_envelope_address;
    /**
     * @var string 'true' to include a reply envelope, 'false' otherwise
     */
    private $include_reply_envelope = 'false';
    /**
     * @var string 'true' to print on both sides of a page, 'false' otherwise
     */
    private $double_sided = 'false';
    /**
     * @var string 'true' to print in color, 'false' for black-and-white
     */
    private $colored = 'false';
    /**
     * @var string 'true' if bottom-third of the letter should be perforated, 'false' otherwise
     */
    private $perforate_document = 'false';
    /**
     * @var array An array of available file types accepted by Postal Methods
     * NOTE: These file types are acceptable when the address is inside of the document
     */
    private $available_file_types = ['DOC', 'DOCX', 'PDF', 'HTML'];


    /**
     * Constructs a new PostalMethods component
     */
    public function __construct()
    {
        Loader::loadHelpers($this, ['Xml']);
        Loader::loadComponents($this, ['Input']);
        // Set all vars to default values
        $this->resetAll();
    }

    /**
     * Sets the Postal Methods API Key required for making requests
     *
     * @param string $api_key The Postal Methods API key
     */
    public function setApiKey($api_key)
    {
        $this->api_key = $api_key;
    }

    /**
     * Sets the file and file type of the file to be mailed
     *
     * @param array $file A key=>value array of the file and it's extension:
     *
     *  - file The bitstream of the file to send in binary
     *  - type The type of file this is (i.e. HTML, DOC, DOCX, or PDF), (optional, default PDF)
     */
    public function setFile(array $file)
    {
        if (!empty($file['file'])) {
            $this->file['file'] = $file['file'];
            // Set the file type
            if (!empty($file['type']) && in_array(strtoupper($file['type']), $this->getFileTypes())) {
                $this->file['type'] = strtoupper($file['type']);
            } else {
                $this->file['type'] = 'PDF';
            }
        }
    }

    /**
     * Sets the outside address
     *
     * @param array $address A list of attributes attributes including:
     *
     *  - name The name of the recipient
     *  - company The company name
     *  - address1 Address1
     *  - address2 Address2
     *  - city The city
     *  - state The ISO 3166-2 subdivision code
     *  - zip The postal code
     *  - country_code The ISO 3166-1 alpha3 country code
     */
    public function setToAddress(array $address)
    {
        $this->to_address = [
            'sendToAddress.Name' => (isset($address['name']) ? $address['name'] : ''),
            'sendToAddress.Company' => (isset($address['company']) ? $address['company'] : ''),
            'sendToAddress.AddressLine1' => (isset($address['address1']) ? $address['address1'] : ''),
            'sendToAddress.AddressLine2' => (isset($address['address2']) ? $address['address2'] : ''),
            'sendToAddress.City' => (isset($address['city']) ? $address['city'] : ''),
            'sendToAddress.State' => (isset($address['state']) ? $address['state'] : ''),
            'sendToAddress.Zipcode' => (isset($address['zip']) ? $address['zip'] : ''),
            'sendToAddress.Country' => (isset($address['country_code']) ? $address['country_code'] : '')
        ];
    }

    /**
     * Sets the return address
     *
     * @param array $address A list of attributes attributes including:
     *
     *  - name The name of the recipient
     *  - company The company name
     *  - address1 Address1
     *  - address2 Address2
     *  - city The city
     *  - state The ISO 3166-2 subdivision code
     *  - zip The postal code
     *  - country The ISO 3166-1 alpha3 country code
     */
    public function setReturnAddress(array $address)
    {
        $this->return_address = [
            'returnAddress.Name' => (isset($address['name']) ? $address['name'] : ''),
            'returnAddress.Company' => (isset($address['company']) ? $address['company'] : ''),
            'returnAddress.AddressLine1' => (isset($address['address1']) ? $address['address1'] : ''),
            'returnAddress.AddressLine2' => (isset($address['address2']) ? $address['address2'] : ''),
            'returnAddress.City' => (isset($address['city']) ? $address['city'] : ''),
            'returnAddress.State' => (isset($address['state']) ? $address['state'] : ''),
            'returnAddress.Zipcode' => (isset($address['zip']) ? $address['zip'] : ''),
            'returnAddress.Country' => (isset($address['country']) ? $address['country'] : '')
        ];

        // Also set reply envelope address to same as return address by default
        // This can be overridden by calling setReplyEnvelopeAddress()
        $this->reply_envelope_address = [
            'replyonEvelopeAddress.Name' => (isset($address['name']) ? $address['name'] : ''),
            'replyonEvelopeAddress.Company' => (isset($address['company']) ? $address['company'] : ''),
            'replyonEvelopeAddress.AddressLine1' => (isset($address['address1']) ? $address['address1'] : ''),
            'replyonEvelopeAddress.AddressLine2' => (isset($address['address2']) ? $address['address2'] : ''),
            'replyonEvelopeAddress.City' => (isset($address['city']) ? $address['city'] : ''),
            'replyonEvelopeAddress.State' => (isset($address['state']) ? $address['state'] : ''),
            'replyonEvelopeAddress.Zipcode' => (isset($address['zip']) ? $address['zip'] : ''),
            'replyonEvelopeAddress.Country' => (isset($address['country']) ? $address['country'] : '')
        ];
    }

    /**
     * Sets a reply envelope to be included in the mail
     *
     * @param bool $include_reply_envelope True to include a reply envelope in the mail,
     *  false to not include a reply envelope
     * @notes An address must be explicitly set in order to include a reply envelope
     * @see PostalMethods::setToAddress()
     */
    public function setReplyEnvelope($include_reply_envelope)
    {
        $this->include_reply_envelope = $include_reply_envelope;
    }

    /**
     * Sets whether to print in color
     *
     * @param bool $colored True to print in color, false to print in black-and-white
     */
    public function setColored($colored)
    {
        $this->colored = $colored;
    }

    /**
     * Sets whether to print on both sides of a page
     *
     * @param bool $double_sided True to print on both sides of a page, false to print on one
     */
    public function setDoubleSided($double_sided)
    {
        $this->double_sided = $double_sided;
    }

    /**
     * Sets whether the bottom-third of the letter sent to PostalMethods should
     * be perforated
     *
     * @param bool $perforated True to have the bottom-third of the letter perforated,
     *  false to not perforate the letter
     * @notes An address must be explicitly set in order to have this letter perforated
     * @see PostalMethods::setToAddress()
     */
    public function setPerforated($perforated)
    {
        $this->perforate_document = $perforated;
    }

    /**
     * Sets a description to associate with this letter in the PostalMethods account
     *
     * @param string $description A description to associate with this letter. Limit 100 characters
     */
    public function setDescription($description)
    {
        $this->description = substr($description, 0, 100);
    }

    /**
     * Retrieves a list of available file types accepted by Postal Methods
     *
     * @return array A numerically-indexed array of available file types
     */
    public function getFileTypes()
    {
        return $this->available_file_types;
    }

    /**
     * Resets all settings back to default except for the account username and password
     */
    public function resetAll()
    {
        $this->file = [];
        $this->description = null;
        $this->to_address = null;
        $this->return_address = null;
        $this->reply_envelope_address = null;
        $this->perforate_document = 'false';
        $this->double_sided = 'false';
        $this->colored = 'false';
        $this->include_reply_envelope = 'false';
    }

    /**
     * Sends the document to Postal Methods for mailing
     */
    public function send()
    {
        // Load the HTTP component, if not already loaded
        if (!isset($this->Http)) {
            Loader::loadComponents($this, ['Net']);
            $this->Http = $this->Net->create('Http');
        }

        // Set the action based on whether we're sending along an address and return envelope
        $action = '/Letter/send';
        if ($this->to_address) {
            $action = '/Letter/sendWithAddress';
        }

        // Create a temporary file with the contents
        $filename = tempnam(sys_get_temp_dir(), 'tmp_');
        $file = fopen($filename, 'w');
        fwrite($file, $this->file['file']);
        fclose($file);

        // Change the file extension to PDF since that is all that PostalMethods supports
        $filename_parts = explode('.', $filename);
        $new_filename = $filename_parts[0] . '.pdf';
        rename($filename, $new_filename);

        // Make a request to PostalMethods to send the letter
        $this->Http->setHeaders(['Secret-Key: ' . $this->api_key]);
        $response = $this->Http->post(
            self::$url . $action,
            $this->getSendParameters(),
            [['name' => 'file', 'file' => $new_filename, 'type' => $this->file['type']]]
        );

        // Remove the temporary file
        unlink($new_filename);

        // Parse the response and set any errors
        $this->parseResponse($response);
    }

    /**
     * Gathers all the parameters necessary to send the letter
     */
    private function getSendParameters()
    {
        $params = [
            'myDescription' => $this->description,
            'perforation' => $this->perforate_document,
            'replyOnEnvelope' => $this->include_reply_envelope,
            'isDoubleSided' => $this->double_sided,
            'isColored' => $this->colored,
            'isReturnAddressAppended' => 'true',
            'fileUrl' => '',
            'refId' => uniqid('BLESTA_', true)
        ];

        // Add return address if set
        if (!empty($this->return_address)) {
            $params = array_merge($params, $this->return_address);
        }

        // Add reply envelope address if reply envelope is enabled
        if ($this->include_reply_envelope === 'true'
            && !empty($this->reply_envelope_address['replyonEvelopeAddress.AddressLine1'])) {
            $params = array_merge($params, $this->reply_envelope_address);
            $params['appendReplyOnEnvelopeAddress'] = 'true';
        }

        // Add sendTo address if set (for sendWithAddress endpoint)
        if (!empty($this->to_address)) {
            $params = array_merge($params, $this->to_address);
        }

        return $params;
    }

    /**
     * Parses the API response from PostalMethods, sets any errors that may have been generated
     *
     * @param string $response The API response from PostalMethods
     * @return bool true on success, false on error
     */
    private function parseResponse($response)
    {
        $decoded_response = json_decode($response);

        if ($decoded_response) {
            // Check if the request was successful based on the 'success' field
            if (!empty($decoded_response->success) && empty($decoded_response->error)) {
                return true;
            }

            // Handle error response
            $error_message = 'Unknown error';

            // Check for unAuthorizedRequest flag
            if (!empty($decoded_response->unAuthorizedRequest)) {
                $error_message = 'Unauthorized User - Please check your API Secret Key';
            } elseif (!empty($decoded_response->error)) {
                // Extract error message from error object
                if (is_object($decoded_response->error) && isset($decoded_response->error->message)) {
                    $error_message = $decoded_response->error->message;
                } elseif (is_string($decoded_response->error)) {
                    $error_message = $decoded_response->error;
                } else {
                    $error_message = json_encode($decoded_response->error);
                }
            } elseif (isset($decoded_response->result->message)) {
                // Sometimes the message is in result
                $error_message = $decoded_response->result->message;
            }

            $this->Input->setErrors([
                'PostalMethods' => [
                    'response' => $error_message
                ]
            ]);
        } else {
            // Failed to decode JSON response
            $this->Input->setErrors([
                'PostalMethods' => [
                    'response' => 'Invalid API response: ' . substr($response, 0, 500)
                ]
            ]);
        }

        return false;
    }

    /**
     * Returns all errors set in this object
     *
     * @return array An array of error info
     */
    public function errors()
    {
        return $this->Input->errors();
    }
}
