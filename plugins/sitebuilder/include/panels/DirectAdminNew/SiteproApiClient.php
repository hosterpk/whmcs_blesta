<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

use ErrorException;

class SiteproApiClient {
	public function getUser($username, $hash, $domain = null) {
		return $this->remoteCall('getUser', array(
			'username' => $username,
			'hash' => $hash,
			'domain' => $domain
		));
	}

	public function requestLogin($domain, $user, $loginKey, $apiUrl, $hostingPlan = null, $panel = 'DirectAdmin', $createFromHash = null, $productName = null, $addonNames = null, $langCode = null, $clientId = '', $clientEmail = '', $pluginVersion = '') {
		return $this->remoteCall('requestLogin', array(
			'type' => 'internal',
			'domain' => $domain,
			'apiUrl' => $apiUrl,
			'username' => $user,
			'password' => $loginKey,
			'sessionId' => (isset($_SERVER['SESSION_ID']) ? $_SERVER['SESSION_ID'] : null),
			'sessionKey' => (isset($_SERVER['SESSION_KEY']) ? $_SERVER['SESSION_KEY'] : null),
			'serverId' => 0,
			'resellerId' => 0,
			'resellerId' => 0,
			'resellerClientId' => 0,
			'resellerClientAccountId' => 1,
			'hostingPlan' => $hostingPlan,
			'panel' => $panel,
			'lang' => $langCode,
			'userId' => SiteproModule::$userId,
			'licenseHash' => SiteproModule::$licenseHash,
			'createFrom' => $createFromHash,
			'productName' => $productName,
			'addonNames' => $addonNames,
			'serverIp' => SiteproModule::$serverIp,
			'clientId' => $clientId,
			'clientEmail' => $clientEmail,
			'pluginVersion' => $pluginVersion
		));
	}

	public function requestLoginExternal($domain, $user, $password, $uploadDir, $hostingPlan = null, $panel = 'DirectAdmin', $createFromHash = null, $productName = null, $addonNames = null, $langCode = null, $clientId = '', $clientEmail = '', $pluginVersion = '') {
		$params = array(
			'type' => 'external',
			'domain' => $domain,
			'apiUrl' => SiteproModule::$serverIp,
			'serverIp' => SiteproModule::$serverIp,
			'username' => $user,
			'uploadDir' => $uploadDir,
			'password' => $password,
			'hostingPlan' => $hostingPlan,
			'panel' => $panel,
			'lang' => $langCode,
			'userId' => SiteproModule::$userId,
			'licenseHash' => SiteproModule::$licenseHash,
			'createFrom' => $createFromHash,
			'productName' => $productName,
			'addonNames' => $addonNames,
			'clientId' => $clientId,
			'clientEmail' => $clientEmail,
			'pluginVersion' => $pluginVersion
		);

		return $this->remoteCall('requestLogin', $params);
	}
	
	private function remoteCall($method, $params) {
		$url = SiteproModule::$apiUrl.$method;
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Site.pro API Client/1.0.1 (PHP '.phpversion().')');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Connection: Close',
			'Content-Type: application/json',
		));
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, SiteproModule::$apiUser.':'.SiteproModule::$apiPass);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		//curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		
		$r = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$message = curl_error($ch);
		$code = curl_errno($ch);
		curl_close($ch);
		
		if ($status != 200) {
			$res = json_decode($r);
			if (!$res) {
				$res = null;
				throw new ErrorException('Response Code ('.$status.'): '.$message);
			}
		} else {
			$res = json_decode($r);
		}
		
		return $res;
	}
}
