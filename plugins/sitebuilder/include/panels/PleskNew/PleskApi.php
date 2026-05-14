<?php

namespace Sitebuilder\Panels\PleskNew;

use ErrorException;
use Exception;
use Modules_Siteprobuilder_ICustomApiHandler;

require_once __DIR__.'/ICustomApiHandler.php';

class PleskApi implements Modules_Siteprobuilder_ICustomApiHandler {

	private $host;
	private $port = 8443;
	private $uri = '/enterprise/control/agent.php';
	private $username;
	private $password;
	
	private static $instance;
	
	public function __construct($host, $username, $password) {
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
	}
	
	public function call($request, $login = null) {
		if (strpos('<?xml', $request) === false) {
			if (strpos('<packet', $request) === false) {
				$request = "<packet>$request</packet>";
			}
			$request = '<?xml version="1.0" encoding="utf-8"?>'.$request;
		}
		$url = 'https://'.$this->host.':'.$this->port . $this->uri;
		$headers = array(
			'HTTP_AUTH_LOGIN: '.$this->username,
			'HTTP_AUTH_PASSWD: '.$this->password,
			'Content-Type: text/xml'
		);
	
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
		
		$ret = curl_exec($ch);
		$errno = curl_errno($ch);
		if ($errno) {
			$error = curl_error($ch);
			throw new ErrorException("cURL Error: $error ($errno)");
		}
		curl_close($ch);
		
		try {
			$result = simplexml_load_string($ret);
		} catch (Exception $ex) {
			throw new ErrorException("XML Error: {$ex->getMessage()}");
		}
		return $result;
	}
	
	public static function getInstance($serverIp, $username, $password) {
		if (!self::$instance) {
			self::$instance = new self($serverIp, $username, $password);
		}
		return self::$instance;
	}
}