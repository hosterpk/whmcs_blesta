<?php
/**
 * Realtime Register API Response
 *
 * @package blesta
 * @subpackage blesta.components.modules.centovacast
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RealtimeRegisterResponse
{
    private $status;
    private $raw;
    private $response;
    private $errors;

    private $codes = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Unused',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required'
    ];

    /**
     * RealtimeRegisterResponse constructor.
     *
     * @param array $response
     */
    public function __construct(array $response)
    {
        $this->raw = $response['content'];
        $this->response = json_decode($response['content']);
        $this->status = $response['code'] ?? '400';

        $this->errors = [];

        if (isset($this->response->violations)) {
            foreach ($this->response->violations as $violation) {
                $this->errors[] = $violation->field . ': ' . $violation->message;
            }
        }

        $error_types = [
            'ValidationError', 'UnrecognizedPropertyException', 'ObjectDoesNotExist',
            'InvalidMessage', 'ProviderUnavailable'
        ];
        if (isset($this->response->message) && in_array($this->response->type, $error_types)) {
            $this->errors[] = $this->response->message;
        }

        if (($this->status < 200 || $this->status > 299) && empty($this->errors)) {
            $this->errors[] = $this->codes[$this->status] ?? 'Unknown error';
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
     * @return array The errors from this response
     */
    public function errors()
    {
        return $this->errors;
    }
}
