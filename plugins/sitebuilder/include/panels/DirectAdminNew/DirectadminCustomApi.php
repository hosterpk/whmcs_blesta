<?php

require_once __DIR__.'/DirectadminApiInterface.php';

use WHMCS_DirectAdmin_SiteBuilder_Module\DirectadminApiInterface;

class DirectadminCustomApi implements DirectadminApiInterface {
	private $conn = null;

	/** @var string */
	private $host;
	/** @var string */
	private $user;
	/** @var string */
	private $pass;
	/** @var int */
	private $port = 2222;
	/** @var string */
	private $proto = 'http';

	public function __construct($host, $user, $pass, $port = 2222, $proto = 'http') {
		$this->host = $host;
		$this->user = $user;
		$this->pass = $pass;
		if ($port && ($t = intval($port))) $this->port = $t;
		if ($proto && preg_match('#^(http|https)$#i', $proto)) $this->proto = strtolower($proto);
	}

	/**
	 * @param string $command
	 * @param array $args
	 * @return array
	 * @throws ErrorException
	 */
	public function call($command, $args = array()) {
		$conn = $this->getConn();
		$url = $this->buildUrl($command, $args);
		curl_setopt($conn, CURLOPT_URL, $url);
		$r = curl_exec($conn);
		$s = curl_getinfo($conn, CURLINFO_HTTP_CODE);
		$er = curl_error($conn);
		$ec = curl_errno($conn);
		$resp = null;
		if (is_string($r)) {
			if ($r) {
				parse_str($r, $resp);
			} else {
				$resp = array();
			}
		}
		if ($ec > 0) {
			throw new ErrorException("cURL request error ($ec)" . ($er ? ": $er" : ''));
		} else {
			if ($s < 200 || $s > 200) {
				if ($s == 400 && $r && preg_match('#use https#i', $r)) {
					$this->proto = 'https';
					return $this->call($command, $args);
				}
				$msg = "HTTP request error ($s)";
				if (isset($resp['error']) && $resp['error'] && isset($resp['text']) && $resp['text']) {
					$msg .= ': '.$resp['text'] . ((isset($resp['details']) && $resp['details']) ? ' ('.$resp['details'].')' : '');
					$msg .= " [command: $command]";
				}
				throw new ErrorException($msg);
			}
		}
		if (!is_array($resp)) {
			throw new ErrorException("Bad response (API URL: $url, RESPONSE: <code style=\"word-wrap: break-word;\">".htmlspecialchars($r)."</code>)");
		}
		return $resp;
	}

	public function getPassword() {
		return $this->pass;
	}

	private function buildUrl($command, $args = array()) {
		$qs = !empty($args) ? '?'.http_build_query($args) : '';
		return "{$this->proto}://{$this->host}:{$this->port}/{$command}{$qs}";
	}

	private function getConn() {
		if (!$this->conn) {
			$this->conn = curl_init();
			curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->conn, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($this->conn, CURLOPT_USERPWD, "{$this->user}:{$this->pass}");
			curl_setopt($this->conn, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($this->conn, CURLOPT_SSL_VERIFYPEER, false);
		}
		return $this->conn;
	}
}
