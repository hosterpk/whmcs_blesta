<?php

namespace Sitebuilder\Panels\CPanelNew;

use ErrorException;
use WHMCS_cPanel_SiteBuilder_Module\ICPanelApiHandler;
use WHMCS_cPanel_SiteBuilder_Module\ILoggable;

require_once dirname(__FILE__).'/ILoggable.php';
require_once dirname(__FILE__).'/ICPanelApiHandler.php';

class CpanelApi implements ICPanelApiHandler {
	
	private $whmBaseUrl;
	private $whmUsername;
	private $whmPassword;
	private $whmAccessToken;
	
	private $cookieDir;
	private $cookieFile;
	
	private $linkId;
	private $apiUrl;
	
	private $cpanelUser;
	private $logging;
	
	public function __construct() {
		$this->cookieDir = dirname(__FILE__).'/../../tmp';
		$this->cleanup();
	}
	
	public function setLogging(ILoggable $logging) {
		$this->logging = $logging;
	}
	
	public function getLogging() {
		return $this->logging;
	}
	
	public function log($msg, $title = null) {
		if ($this->logging) $this->logging->log($msg, $title);
	}
	
	public function configure($whmBaseUrl, $whmUsername, $whmPassword, $whmAccessToken = null) {
		$this->whmBaseUrl = $whmBaseUrl;
		$this->whmUsername = $whmUsername;
		$this->whmPassword = $whmPassword;
		$this->whmAccessToken = $whmAccessToken;
		$this->reset();
	}
	
	public function setCpanelUser($user) {
		if (!$user || $this->cpanelUser && $this->cpanelUser != $user) $this->reset();
		$this->cpanelUser = $user;
	}
	
	private function reset() {
		$this->apiUrl = null;
		if ($this->cookieFile && is_file($this->cookieFile)) {
			unlink($this->cookieFile);
		}
		if ($this->linkId) curl_close($this->linkId);
	}
	
	public function call($module, $function, $params = array(), $forceBasicAuth = false) {
		if (!$this->cpanelUser) return false;
		$this->log($this->cpanelUser, 'Cpanel API User');
		$useWhmApi = !!$this->whmAccessToken;
		$useBasicAuth = $forceBasicAuth || !$this->whmAccessToken;
		if ($useWhmApi) $useBasicAuth = false;
		$this->log(($useWhmApi ? 'YES' : 'NO'), 'Use WHM API');
		if (!$this->linkId) {
			$this->linkId = curl_init();
			curl_setopt($this->linkId, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($this->linkId, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($this->linkId, CURLOPT_HEADER, false);
			curl_setopt($this->linkId, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->linkId, CURLOPT_HTTPHEADER, array(
				'Authorization: '.($useBasicAuth
						? 'Basic '.base64_encode($this->whmUsername.':'.$this->whmPassword)."\r\n"
						: 'whm '.$this->whmUsername.':'.$this->whmAccessToken)
			));
		}
		if ($useWhmApi) {
			$qs = http_build_query($params);
			curl_setopt($this->linkId, CURLOPT_URL, $this->whmBaseUrl.'json-api/cpanel?api.version=1'
					."&cpanel_jsonapi_user={$this->cpanelUser}"
					."&cpanel_jsonapi_module={$module}"
					."&cpanel_jsonapi_func={$function}"
					."&cpanel_jsonapi_apiversion=3" // 1 = cPanel API 1; 2 = cPanel API 2; 3 = cPanel UAPI
					.($qs ? "&{$qs}" : ''));
		}
		else {
			if (!$this->apiUrl) {
				curl_setopt($this->linkId, CURLOPT_URL, $this->whmBaseUrl.'json-api/create_user_session?api.version=1&service=cpaneld&user='.$this->cpanelUser);
				$resp = curl_exec($this->linkId);
				if (!$useBasicAuth && curl_getinfo($this->linkId, CURLINFO_HTTP_CODE) == 403) {
					curl_close($this->linkId);
					$this->linkId = null;
					$this->apiUrl = null;
					return $this->call($module, $function, $params, true);
				}
				$this->log($resp, 'Cpanel API step 1');
				if (($error = $this->getCurlError($this->linkId))) throw new ErrorException('API Error (1): '.$error.
						($resp ? '<p><strong>Response from cPanel:</strong></p><pre>'.$resp.'</pre>' : ''));

				$decoded = json_decode($resp);
				if (is_null($decoded) || $decoded === false) throw new ErrorException('API Error (1): broken response'.
						($resp ? '<p><strong>Response from cPanel:</strong></p><pre>'.$resp.'</pre>' : ''));
				if (!isset($decoded->data->url) || !$decoded->data->url) throw new ErrorException('API Error (1): could not retrieve API URL'.
						($resp ? '<p><strong>Response from cPanel:</strong></p><pre>'.$resp.'</pre>' : ''));
				
				curl_setopt($this->linkId, CURLOPT_HTTPHEADER, array());
				curl_setopt($this->linkId, CURLOPT_COOKIESESSION, $this->getCookieFile());
				curl_setopt($this->linkId, CURLOPT_COOKIEJAR, $this->getCookieFile());
				curl_setopt($this->linkId, CURLOPT_COOKIEFILE, $this->getCookieFile());
				curl_setopt($this->linkId, CURLOPT_URL, $decoded->data->url);
				curl_exec($this->linkId);

				$this->apiUrl = preg_replace('#/login(?:/)??.*#', '', $decoded->data->url);
				$this->log($this->apiUrl, 'Cpanel API step 2');
			}
			$this->log(array($module, $function, $params), 'Cpanel API params');
			$qs = http_build_query($params);
			curl_setopt($this->linkId, CURLOPT_URL, $this->apiUrl.'/execute/'.$module.'/'.$function.($qs ? "?$qs" : ''));
		}
		$r = curl_exec($this->linkId);
		$resp = json_decode($r, true);
		$this->log($r, 'Cpanel API step 3');
		if ((is_null($resp) || $resp === false)	&& ($error = $this->getCurlError($this->linkId))) {
			throw new ErrorException('API Error (2): '.$error.'<p>Could not log in to cPanel or received broken response</p>');
		}
		if ($useWhmApi) {
			$resp = $resp['result'];
		}
		if (isset($resp['status']) && $resp['status'] != 1) {
			$error = 'API Error (2):';
			if (isset($resp['errors']) && $resp['errors']) {
				if (is_array($resp['errors'])) {
					$error .= '<br />'.implode('<br />', $resp['errors']);
				} else {
					$error = ' '.$resp['errors'];
				}
			} else {
				$error .= ' unsuccessful call';
			}
			throw new ErrorException($error);
		}
		return isset($resp['data']) ? $resp['data'] : null;
	}
	
	private function getCookieFile() {
		if ((!$this->cookieFile || !is_file($this->cookieFile)) && $this->cpanelUser) {
			$this->cookieFile = $this->cookieDir.'/'.md5($this->cpanelUser.microtime());
		}
		return $this->cookieFile;
	}
	
	private function getCurlError($linkId) {
		if (($err = curl_error($linkId))) {
			return $err;
		} else if (($no = curl_errno($linkId))) {
			return "cURL error $no";
		} else if (($s = curl_getinfo($linkId, CURLINFO_HTTP_CODE)) != 200) {
			return "HTTP response $s";
		}
		return null;
	}
	
	private function cleanup() {
		if ($this->cookieDir && is_dir($this->cookieDir)) {
			if (($dh = opendir($this->cookieDir)) !== false) {
				$delFiles = array();
				while (($file = readdir($dh)) !== false) {
					if ($file == '.' || $file == '..') continue;
					$path = $this->cookieDir.'/'.$file;
					if (filemtime($path) < time() - 3600) {
						$delFiles[] = $path;
					}
				}
				foreach ($delFiles as $file) { @unlink($file); }
				closedir($dh);
			}
		}
	}
	
}
