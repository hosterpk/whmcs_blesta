<?php
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'paysera_response.php';

/**
 * Paysera API
 *
 * @link http://blesta.com Phillips Data, Inc.
 */
class PayseraApi
{
    ##
    # EDIT REQUIRED Update the below API url or replace it with an appropriate gateway field
    ##
    /**
     * @var string The API URL
     */
    private $apiUrl = '';
    ##
    # EDIT REQUIRED Update the above variable descriptions
    ##

    // The data sent with the last request served by this API
    private $lastRequest = [];

    /**
     * Initializes the request parameter
     *
     */
    ##
    # EDIT REQUIRED Update the above variable descriptions and parameter list below
    ##
    public function __construct()
    {
    }

    /**
     * Send an API request to Paysera
     *
     * @param string $route The path to the API method
     * @param array $body The data to be sent
     * @param string $method Data transfer method (POST, GET, PUT, DELETE)
     * @return PayseraResponse
     */
    public function apiRequest($route, array $body, $method)
    {
        $url = $this->apiUrl . '/' . $route;
        $curl = curl_init();

        switch (strtoupper($method)) {
            case 'DELETE':
                // Set data using get parameters
            case 'GET':
                $url .= empty($body) ? '' : '?' . http_build_query($body);
                break;
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, 1);
                // Use the default behavior to set data fields
            default:
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
                break;
        }

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSLVERSION, 1);

        $headers = [];
        ##
        #  Set any neccessary headers here
        ##
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $this->lastRequest = ['content' => $body, 'headers' => $headers];
        $result = curl_exec($curl);
        if (curl_errno($curl)) {
            $error = [
                'error' => 'Curl Error',
                'message' => 'An internal error occurred, or the server did not respond to the request.',
                'status' => 500
            ];

            return new PayseraResponse(['content' => json_encode($error), 'headers' => []]);
        }
        curl_close($curl);

        $data = explode("\n", $result);

        // Return request response
        return new PayseraResponse([
            'content' => $data[count($data) - 1],
            'headers' => array_splice($data, 0, count($data) - 1)]
        );
    }
}
