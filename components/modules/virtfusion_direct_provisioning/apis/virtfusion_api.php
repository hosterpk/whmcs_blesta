<?php

class VirtfusionApi
{

    private $api_token;
    private $hostname;

    private $port;

    private $last_request = ['url' => null, 'args' => null];

    public function __construct($api_token, $hostname, $port = 443)
    {
        $this->api_token = $api_token;
        $this->hostname = $hostname;
        $this->port = $port;
    }

    public function get_query($query = '')
    {
        return $this->submit(ltrim($query, '/'));
    }
    
    public function submit($command, $type = 'GET', array $args = [])
    {
        $url = 'https://' . $this->hostname . ':' . $this->port . '/api/v1/' . $command;

        $this->last_request = [
            'url' => $url,
            'args' => $args
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));

        switch ($type) {
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-type: application/json; charset=utf-8',
            'authorization: Bearer ' . $this->api_token
        ]);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return ['info' => $info, 'response' => $response];
    }

    public function lastRequest()
    {
        return $this->last_request;
    }

    public function loadCommand($command)
    {
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'commands' . DIRECTORY_SEPARATOR . $command . '.php';
    }
}
