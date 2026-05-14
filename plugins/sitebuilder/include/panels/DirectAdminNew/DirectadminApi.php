<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

class DirectadminApi {
	/** @var string */
	private $host;
	/** @var string */
	private $user;
	/** @var string|null */
	private $pass = null;
	/** @var int */
	private $port = 2222;
	/** @var bool */
	private $secure = true;
	/** @var string|null */
	private $sessionId = null;
	/** @var string|null */
	private $sessionKey = null;
	
	/** @var DirectadminApiResponse */
	private $lastResponse = null;

	public function __construct($host, $user, $pass = null, $port = 2222, $secure = true, $sessionId = null, $sessionKey = null) {
		$this->host = $host;
		$this->user = $user;
		$this->pass = $pass;
		$this->port = $port;
		$this->secure = $secure;
		$this->sessionId = $sessionId;
		$this->sessionKey = $sessionKey;
	}

	/**
	 * @param string|null $command
	 * @param string|null $query
	 * @return string
	 */
	private function buildBaseUrl($command = null, $query = null) {
		$url = ($this->secure ? 'https' : 'http').'://'.$this->host.':'.$this->port;
		if ($command) {
			$url .= '/'.ltrim($command, '/');
		}
		if ($query) {
			$url .= '?'.$query;
		}
		return $url;
	}

	/** @return array[array|null,string] */
	public function call($command, $args = [], $method = 'GET') {
		$headers = [];
		$query = '';
		$payload = null;
		$legacyMode = !!preg_match('#^\/?CMD_#', $command);
		if ($legacyMode) {
			if (in_array($method, ['GET', 'DELETE'])) {
				$query = http_build_query($args);
			} else if (!empty($args)) {
				$payload = http_build_query($args);
			}
		} else {
			$headers[] = 'Content-type: application/json';
			if (!empty($args)) $payload = ($t = json_encode($args)) ? $t : '';
		}
		$ch = curl_init();
		if ($this->pass) {
			$headers[] = "Authorization: Basic ".base64_encode("{$this->user}:{$this->pass}");
		}
		if ($this->sessionId && $this->sessionKey) {
			$headers[] = "Cookie: session={$this->sessionId}; key={$this->sessionKey}";
		}
		$url = $this->buildBaseUrl($command, $query);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if ($payload) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		$r = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$ec = curl_errno($ch);
		$em = curl_error($ch);

		$this->lastResponse = new DirectadminApiResponse($url, $method, $r, $statusCode, $payload, $ec, $em);

		$resp = null;
		$err = '';

		if ($ec > 0) {
			$err .= ($em ? $em : 'Curl error') . " ({$ec})";
		} else if ($statusCode < 200 || $statusCode >= 300) {
			if ($statusCode == 400 && $r && preg_match('#use https#i', $r)) {
				$this->secure = true;
				return $this->call($command, $args, $method);
			}
			$err .= "Http code {$statusCode}";
		}
		if ($r) {
			if ($err) {
				$err .= ": {$r}";
			} else {
				if ($legacyMode) {
					parse_str($r, $resp);
				} else {
					$resp = $r ? json_decode($r, true) : null;
				}
				if (!is_array($resp)) {
					$err .= ($err ? ': ' : '') . 'empty response';
				}
			}
		}
		return [$resp, $err];
	}

	/** @return DirectadminApiResponse|null */
	public function getLastResponse() {
		return $this->lastResponse;
	}

	/** @param string|null $password */
	public function setPassword($password) {
		$this->pass = $password;
	}

	/** @return string */
	public function getUser() {
		return $this->user;
	}

	/** @param string */
	public function setUser($user) {
		$this->user = $user;
	}
}

class DirectadminApiResponse {
	/** @var string */
	public $url;
	/** @var string */
	public $method;
	/** @var string|null */
	public $response;
	/** @var int */
	public $statusCode;
	/** @var string|null */
	public $payload;
	/** @var int */
	public $errno;
	/** @var string|null */
	public $error;

	public function __construct($url, $method, $response, $statusCode, $payload = null, $errno = CURLE_OK, $error = null) {
		$this->url = $url;
		$this->method = $method;
		$this->response = $response;
		$this->statusCode = $statusCode;
		$this->payload = $payload;
		$this->errno = $errno;
		$this->error = $error;
	}

	public function __toString() {
		return "URL: {$this->url}"
			.", Method: {$this->method}"
			.", Status: {$this->statusCode}"
			.", Payload: ".(is_null($this->payload) ? 'NULL' : $this->payload)
			.", Response: ".(is_null($this->response) ? 'NULL' : ($this->response ? $this->response : '(empty string)'));
	}
}
