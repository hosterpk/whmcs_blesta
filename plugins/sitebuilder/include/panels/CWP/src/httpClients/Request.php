<?php

namespace WHMCS_CWP_SiteBuilder_Module\httpClients;

use Exception;

class Request
{
	public $baseUrl = null;
	public $url;
	public $headers = array();
	public $auth = null;
	public $body = null;
	public $format = 'urlencoded';

	public function getFullUrl()
	{
		$url = $this->url;
		if (is_array($url)) {
			$host = $url[0];
			unset($url[0]);
			$params = $url;

			$url = $host . '?' . http_build_query($params);
		}
		return ((string)$this->baseUrl) . $url;
	}

	public function getBody()
	{
		$formatters = $this->listFormatter();
		if (isset($formatters[$this->format])) {
			return $formatters[$this->format]($this->body);
		}
		throw new Exception('Unknown format');
	}

	protected function listFormatter()
	{
		return array(
			'json' => function ($val) {
				$this->headers[] = 'Content-Type: application/json';
				return json_encode($val);
			},
			'urlencoded' => function ($val) {
				$this->headers[] = 'Content-Type: application/x-www-form-urlencoded';
				return http_build_query($val);
			},
		);
	}
}
