<?php

/**
 * Enhance API Response
 *
 * @link https://www.blesta.com Phillips Data, Inc.
 */
class EnhanceResponse
{
    private $status;
    private $raw;
    private $response;
    private $errors;
    private $headers;

    /**
     * EnhanceResponse constructor.
     *
     * @param array $apiResponse
     */
    public function __construct(array $apiResponse)
    {
        $this->raw = $apiResponse['content'];
        $this->response = json_decode($apiResponse['content']);
        $this->headers = $apiResponse['headers'];

        $this->status = '400';
        if (isset($this->headers[0])) {
            $status_parts = explode(' ', $this->headers[0]);
            if (isset($status_parts[1])) {
                $this->status = $status_parts[1];
            }
        }

        $this->errors = [];

        // Parse errors from the API response
        if ($this->status >= 400) {
            if (isset($this->response->errors)) {
                if (is_array($this->response->errors)) {
                    foreach ($this->response->errors as $error) {
                        if (is_object($error) && isset($error->message)) {
                            $this->errors[] = $error->message;
                        } elseif (is_string($error)) {
                            $this->errors[] = $error;
                        }
                    }
                } else {
                    $this->errors[] = $this->response->errors;
                }
            } elseif (isset($this->response->error)) {
                $this->errors[] = is_string($this->response->error) ? $this->response->error : 'API Error';
            } elseif (isset($this->response->message)) {
                $this->errors[] = $this->response->message;
            } else {
                $this->errors[] = 'HTTP ' . $this->status . ' Error';
            }
        }
    }

    /**
     * Get the status of this response
     *
     * @return string The status of this response
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * Get the raw data from this response
     *
     * @return string The raw data from this response
     */
    public function raw()
    {
        return $this->raw;
    }

    /**
     * Get the data response from this response
     *
     * @return string The data response from this response
     */
    public function response()
    {
        return $this->response;
    }

    /**
     * Get any errors from this response
     *
     * @return string The errors from this response
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Get the headers returned with this response
     *
     * @return string The headers returned with this response
     */
    public function headers()
    {
        return $this->headers;
    }
}
