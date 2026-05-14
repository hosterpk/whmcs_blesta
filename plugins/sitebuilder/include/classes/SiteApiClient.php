<?php

namespace Sitebuilder\Classes;

use ErrorException;

class SiteApiClient {
	/** @var string */
	protected $apiUrl;
	protected $apiUser;
	protected $apiPass;
	private $curlDetails;
	private $redirects;
	
	public function __construct($apiUrl, $apiUsername, $apiPassword) {
		$this->apiUrl = $apiUrl;
		$this->apiUser = $apiUsername;
		$this->apiPass = $apiPassword;
		$this->redirects = array();
	}
	
	public function remoteCall($method, $params, $timeout = 300, $_redirected = 0, $_url = null, $connTimeout = null) {
		if ($_url) {
			$url = $_url;
		} else {
			$_apiUrl = $this->apiUrl;
			if (!is_array($_apiUrl)) $_apiUrl = array($_apiUrl);
			$apiUrl = $_apiUrl[0];
			if (isset($_apiUrl[1])) {
				$host = $_apiUrl[1];
			} else {
				$host = null;
			}
			$url = $apiUrl.$method;
		}
		$header = array(
			'Connection: Close',
			'Content-Type: application/json'
		);
		if (isset($host) && $host) {
			$header[] = 'Host: '.$host;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Site API Client/1.0.1 (PHP '.phpversion().')');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		if ($connTimeout) curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this->apiUser.':'.$this->apiPass);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, true);
//		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		
		$r = curl_exec($ch);
		$errNo = curl_errno($ch);
		$errMsg = curl_error($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$respHeaders = array();
		curl_close($ch);
		
		$this->curlDetails = (object) array(
			'url' => $url,
			'redirects' => null,
			'responseCode' => $status,
			'responseHeaders' => array(),
			'responseBody' => "",
			'errno' => $errNo,
			'error' => $errMsg,
			'params' => $params
		);
		
		do {
			$continue = false;
			$resp = explode("\r\n\r\n", $r, 2);
			if (count($resp) > 1) {
				$headersRaw = explode("\r\n", $resp[0]);
				foreach ($headersRaw as $h) {
					if( $h == "HTTP/1.1 100 Continue" )
						$continue = true;
					$keyVal = explode(':', $h, 2);
					if (count($keyVal) > 1) {
						$respHeaders[trim($keyVal[0])] = trim($keyVal[1]);
					}
				}
				$r = $resp[1];
			}
		} while($continue);
		$this->curlDetails->responseHeaders = $respHeaders;
		$this->curlDetails->responseBody = $r;
		
		if (!$_redirected) $this->redirects = array();
		if ($_redirected < 3 && isset($respHeaders['Location'])) {
			$this->redirects[] = $respHeaders['Location'];
			return $this->remoteCall($method, $params, $timeout, ($_redirected + 1), $respHeaders['Location'], $connTimeout);
		}
		$this->curlDetails->redirects = $this->redirects;
		
		if ($errNo != CURLE_OK) {
			throw new ErrorException('cURL request failed with error ('.$errNo.')'.($errMsg ? ': '.$errMsg : ''));
		} else if ($status != 200) {
			$res = json_decode($r);
			if (!$res) {
				$res = null;
				throw new ErrorException('Request failed with status ('.$status.')');
			}
		} else {
			$res = json_decode($r);
		}
		
		return $res;
	}
	
	/** @return SiteApiClientCurlDetails */
	public function getCurlDetails() {
		return $this->curlDetails;
	}
	
}

/**
 * @property string $url
 * @property int $redirects
 * @property int $responseCode
 * @property string[] $responseHeaders
 * @property string $responseBody
 * @property int $errno
 * @property string $error
 * @property string|array $params
 */
class SiteApiClientCurlDetails {}
