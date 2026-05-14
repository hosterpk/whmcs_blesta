<?php

class Modules_Siteprobuilder_Api {
	private static $apiUrlDef = 'https://site.pro/api/';
	
	private $curlDetails;
	private $redirects;
	
	/**
	 * @param array $params
	 * @param bool $outputUrl
	 * @throws ErrorException
	 */
	public function openBuilder($params, $outputUrl = false) {
		$usr = $this->requestLogin($params['type'], $params['domain'], $params['ftpUser'], $params['ftpPass'], $params['uploadDir'],
				$params['ipAddr'], $params['hostingPlan'], $params['licenseHash'], $params['panel'], $params['productName'], $params['addonNames'], $params['clientId'], $params['pluginVersion']);
		if (is_object($usr) && isset($usr->url) && $usr->url) {
			if ($outputUrl) {
				echo $usr->url;
				exit();
			} else {
				header('Location: '.$usr->url, true);
				exit();
			}
		} else if (is_object($usr) && isset($usr->error) && $usr->error) {
			throw new ErrorException('Error: '.$usr->error->message);
		} else {
			throw new ErrorException('Error: server error');
		}
	}
	
	public function requestLogin($type, $domain, $ftpUser, $ftpPass, $uploadDir, $ipAddr, $hostingPlan = null, $licenseHash = null, $panel = 'Plesk', $productName = null, $addonNames = null, $clientId = '', $pluginVersion = '') {
		return $this->remoteCall('requestLogin', array(
			'type' => $type,
			'domain' => $domain,
			'licenseHash' => ($licenseHash ? $licenseHash : null),
			'username' => $ftpUser,
			'password' => $ftpPass,
			'uploadDir' => $uploadDir,
			'apiUrl' => $ipAddr,
			'hostingPlan' => $hostingPlan,
			'productName' => $productName,
			'addonNames' => $addonNames,
			'panel' => $panel,
			'userId' => Modules_Siteprobuilder_SitePro::$userId,
			'clientId' => $clientId,
			'pluginVersion' => $pluginVersion
		));
	}

	/**
	 * Activate builder license purchased from plesk systems.
	 * @param string $licenseHash hash associated with license.
	 * @param stdClass $info object containing license owner info.
	 * @return stdClass response data object.
	 */
	public function activateLicense($licenseHash, $info) {
		return $this->remoteCall('pleskisv/activate', array(
			'licenseHash' => $licenseHash,
			'email' => $info->email,
			'name' => $info->name
		), self::$apiUrlDef);
	}

	/**
	 * Get license configuration URL.
	 * @param string $licenseHash hash associated with license.
	 * @param string $countryCode country code of administrator user for guessing language.
	 * @return stdClass response data object.
	 */
	public function configureLicense($licenseHash, $countryCode) {
		return $this->remoteCall('pleskisv/login', array(
			'licenseHash' => $licenseHash,
			'countryCode' => $countryCode
		), self::$apiUrlDef);
	}

	private function remoteCall($method, $params, $customApiUrl = null, $timeout = 300, $_redirected = 0, $_url = null, $connTimeout = null) {
		if ($_url) {
			$url = $_url;
		} else {
			$_apiUrl = $customApiUrl ? $customApiUrl : Modules_Siteprobuilder_SitePro::$apiUrl;
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
		curl_setopt($ch, CURLOPT_USERAGENT, 'Site.pro API Client/1.0.1 (PHP '.phpversion().')');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		if ($connTimeout) curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, Modules_Siteprobuilder_SitePro::$apiUser.':'.Modules_Siteprobuilder_SitePro::$apiPass);
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
			return $this->remoteCall($method, $params, null, $timeout, ($_redirected + 1), $respHeaders['Location'], $connTimeout);
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
	
}
