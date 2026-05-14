<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

use ErrorException;
use Exception;

require_once __DIR__.'/IFtpStorage.php';
require_once __DIR__.'/DirectadminApi.php';
require_once __DIR__.'/SiteproApiClient.php';
require_once __DIR__.'/Punycode.php';

class SiteproModule {
	public static $apiUrl = '';
	public static $apiUser = '';
	public static $apiPass = '';
	public static $userId = null;
	public static $licenseHash = null;
	public static $serverIp = null;
	private static $panel = 'DirectAdmin';
	private static $createFromHash = null;
	private static $forceInternalPublication = false;
	private static $pluginVersion = null;

	public static function setup($apiUrl, $apiUser, $apiPass, $userId = null, $licenseHash = null, $panel = 'DirectAdmin', $createFromHash = null, $serverIp = null, $forceInternalPublication = false, $pluginVersion = '') {
		self::$apiUrl = $apiUrl;
		self::$apiUser = $apiUser;
		self::$apiPass = $apiPass;
		self::$userId = $userId;
		self::$licenseHash = $licenseHash;
		self::$panel = $panel;
		self::$createFromHash = $createFromHash;
		self::$serverIp = $serverIp;
		self::$forceInternalPublication = !!$forceInternalPublication;
		self::$pluginVersion = $pluginVersion;
	}

	/** @var array|null */
	private $subdomains = null;
	
	/** @var string|null */
	private $domain = null;
	
	/** @var string|null */
	private $user = null;

	/** @var string|null */
	private $hostingPlan = null;

	/** @var array|null */
	private $userConf = null;
	
	/** @var string|null */
	private $baseUrl = null;

	/** @var string|null */
	private $host = null;

	/** @var bool */
	private $secure = true;

	/** @var int */
	private $port = 2222;

	/** @var string */
	private $baseUri = '/CMD_PLUGINS/siteprobuilder/index2.raw';

	/** @var string|null */
	private $loginKey = null;

	/** @var DirectadminApi */
	private $selfApi;

	/** @var IFtpStorage|null */
	private $ftpStorage = null;

	public function __construct($host, $user, $domain, $pass = null, $port = 2222, $secure = true, $sessionId = null, $sessionKey = null, $loginKey = null, $baseUri = null) {
		$this->host = $host;
		$this->user = $user;
		$this->domain = $domain;
		$this->port = $port;
		$this->secure = !!$secure;
		$this->loginKey = $loginKey;
		$this->baseUri = $baseUri;
		$this->selfApi = new DirectadminApi(
			$this->host,
			$this->user,
			$pass,
			$this->port,
			$this->secure,
			$sessionId,
			$sessionKey
		);
	}

	public function getEncDomain() {
		$puny = new Punycode();
		try {
			return $puny->encode($this->domain);
		} catch (Exception $ex) {
			return $this->domain;
		}
	}

	public function getForceInternalPublication() {
		return self::$forceInternalPublication;
	}

	public function &getLoginKey() {
		return $this->loginKey;
	}

	public function setLoginKey($loginKey) {
		$this->loginKey = $loginKey;
	}

	/**
	 * @param string|null $subdomain
	 * @param string|null $productName
	 * @param string[]|null $addonNames
	 * @param string|null $langCode
	 * @param bool|callable $isPanelInstalled
	 * @throws ErrorException
	 * @return string
	 */
	public function getBuilderUrl($subdomain = null, $productName = null, $addonNames = null, $langCode = null, $isPanelInstalled = true) {
		$domain = $this->domain;
		$qParams = array('d' => $domain);
		$sub = '';
		if ($subdomain) {
			$subdomains = $this->getSubdomains();
			foreach ($subdomains as $li) {
				if ($li->id == $subdomain || $li->name == $subdomain) {
					$qParams['sd'] = $sub = $li->id;
					$domain = $li->name;
					break;
				}
			}
		}

		$api = new SiteproApiClient(self::$apiUrl, self::$apiUser, self::$apiPass);

		$apiUrl = $this->getBaseUrl($qParams).'api/';

		$ftpUsername = $ftpPassword = '';
		if (!self::$forceInternalPublication) {
			list($ftpUsername, $ftpPassword) = $this->getFtp();
		}

		if (self::$forceInternalPublication || !$ftpUsername) {
			$isInstalled = is_callable($isPanelInstalled) ? $isPanelInstalled() : $isPanelInstalled;
			if (!$isInstalled) {
				throw new ErrorException('DirectAdmin plugin not installed.', 2000);
			}
			$usr = $api->requestLogin($domain, "admin|{$this->user}", $this->getLoginKey(), $apiUrl, $this->getHostingPlan(), self::$panel, self::$createFromHash, $productName, $addonNames, $langCode, '', '', self::$pluginVersion);
		}
		else {
			$ftpDir = 'directadmin_' . $this->domain . '_' . $sub;
			$usr = $api->requestLoginExternal($domain, $ftpUsername, $ftpPassword, $ftpDir, $this->getHostingPlan(), self::$panel, self::$createFromHash, $productName, $addonNames, $langCode, '', '', self::$pluginVersion);
		}

		if (is_object($usr) && isset($usr->url) && $usr->url) {
			return $usr->url;
		} else if (is_object($usr) && isset($usr->error) && $usr->error) {
			throw new ErrorException('Error: '.$usr->error->message);
		} else {
			throw new ErrorException('Error: server error');
		}
	}

	private function getFtp() {
		$ftpUsername = $ftpPassword = '';
		$isAdded = false;

		$ftpData = $this->getFtpData();
		if ($ftpData) {
			$ftpUsername = $ftpData->username;
			$ftpPassword = $ftpData->password;
			$ftp = $ftpUsername ? $this->checkFtp($ftpUsername) : false;
			if (!$ftpUsername || $ftp === false) {
				list($ftpUsername, $ftpPassword) = $this->createNewFtp();
				$isAdded = true;
			}
		} else {
			list($ftpUsername, $ftpPassword) = $this->createNewFtp();
			$isAdded = true;
		}

		if ($isAdded) {
			$ftp = $this->checkFtp($ftpUsername);
		}

		if ($ftp !== false) {
			$this->setFtpData(FtpData::from($ftpUsername, $ftpPassword));
			$ftpUsername = $ftp['fulluser'];
		} else {
			$ftpUsername = '';
		}

		return [$ftpUsername, $ftpPassword];
	}
	
	public function isEnabled() {
		$uc = $this->getUserConf();
		return (!isset($uc['siteprobuilder']) || $uc['siteprobuilder'] == 'ON' || $uc['siteprobuilder'] == 1 || $uc['siteprobuilder'] == 'yes');
	}

	public function deleteLoginKey($name) {
		try {
			$this->callApi("api/login-keys/keys/{$name}", [], 'DELETE');
		} catch (ErrorException $ex) {
			// do nothing.
		}
	}

	/**
	 * @param string $name
	 * @return string
	 * @throws ErrorException
	 */
	public function createLoginKey($currentPassword, $name = 'siteprobuilder') {
		$key = $this->getLoginKey(); // try creating key with the previous value (if there was one)
		if (!$key) $key = md5(microtime());
		$this->deleteLoginKey($name);
		$this->callApi('api/login-keys/keys', [
			'allowCommands' => ['CMD_LOGIN', 'CMD_PLUGINS'],
			'allowLogin' => true,
			'allowNetworks' => [],
			'autoRemove' => true,
			'currentPassword' => $currentPassword,
			'denyCommands' => [],
			'expires' => date('Y-m-d\TH:i:s\Z', 0),
			'hasExpiry' => false,
			'id' => $name,
			'password' => $key,
		], 'POST');
		$this->callApi("api/login-keys/keys/{$name}", array(
			'allowCommands' => ['CMD_LOGIN', 'CMD_PLUGINS'],
			'allowLogin' => true,
			'allowNetworks' => [],
			'autoRemove' => true,
			'currentPassword' => $currentPassword,
			'denyCommands' => [],
			'expires' => date('Y-m-d\TH:i:s\Z', 0),
			'hasExpiry' => false,
			'id' => $name,
			'password' => $key,
		), 'PATCH');
		return $key;
	}

	public function setFtpStorage(IFtpStorage $ftpStorage) {
		$this->ftpStorage = $ftpStorage;
		if ($this->ftpStorage instanceof FileStorage) {
			$this->ftpStorage->setPasswordPattern('#^[a-f0-9]{32}$#');
		}
	}

	/** @return FtpData */
	private function getFtpData()
    {
		return $this->ftpStorage ? $this->ftpStorage->get() : null;
    }

	private function setFtpData(FtpData $data)
    {
		if ($this->ftpStorage) {
			$this->ftpStorage->set($data);
		}
    }

	public function checkFtp(string $username)
    {
		$data = $this->callApi('CMD_API_FTP_SHOW', array(
			'domain' => $this->getEncDomain(),
			'user' => $username,
		));

        if (empty($data['fulluser'])) {
            return false;
        }
 
        return $data;
    }

	public function createNewFtp()
    {
        $ftpUsername = substr(uniqid(rand(), true), 0, 10);
        $ftpPassword = md5(uniqid(rand(), true));
        $this->addFtp($ftpUsername, $ftpPassword);
        return [$ftpUsername, $ftpPassword];
    }

	public function addFtp(string $username, string $password) {
        return $this->callApi('CMD_API_FTP', array(
			'action' => 'create',
			'domain' => $this->getEncDomain(),
			'user' => $username,
			'type' => 'custom',
			'passwd' => $password,
			'passwd2' => $password,
			'custom_val' => '/home/' . $this->user
		), 'POST');
    }


	/**
	 * Call DA API
	 * @param string $command API command to call
	 * @param array $args associative array with command arguments
	 * @param string $method
	 * @return array command result
	 * @throws ErrorException
	 */
	public function callApi($command, $args = array(), $method = 'GET') {
		try {
			list($res, $err) = $this->selfApi->call($command, $args, $method);
			if ($err) throw new ErrorException($err);
			return $res;
		} catch (ErrorException $ex) {
			throw new ErrorException("API call error [command: {$command}, user: {$this->selfApi->getUser()}]: {$ex->getMessage()}. ".$this->selfApi->getLastResponse());
		}
	}

	public function getApiUser() {
		return $this->selfApi->getUser();
	}

	public function setApiUser($apiUser) {
		$this->selfApi->setUser($apiUser);
	}

	/**
	 * Get subdomain list for a domain
	 * @param string $domain
	 * @return array
	 */
	public function getSubdomains() {
		if (is_null($this->subdomains)) {
			$this->subdomains = [];
			$result = $this->callApi('CMD_API_SUBDOMAINS', array('domain' => $this->getEncDomain()));
			if ($result && isset($result['list']) && is_array($result['list'])) {
				foreach ($result['list'] as $li) { $this->subdomains[] = (object) array('id' => $li, 'name' => $li.'.'.$this->domain); }
			}
		}
		return $this->subdomains;
	}

	/** @param string $uri */
	public function setBaseUri($uri) {
		$this->baseUri = $uri;
	}
	
	public function getBaseUrl($params = null) {
		if (is_null($this->baseUrl)) {
			$this->baseUrl = ($this->secure ? 'https' : 'http').'://'
				.$this->host . ($this->port == 80 ? "" : ":{$this->port}")
				.$this->baseUri . '?route=/boot.php/';
		}
		$baseUrl = $this->baseUrl;
		if ($params && is_array($params)) {
			$prm = array();
			foreach ($params as $k => $v) { $prm[] = urlencode($k).'='.urldecode($v);  }
			if (!empty($prm)) {
				$url = explode('?', $baseUrl, 2);
				if (isset($url[1])) $prm[] = $url[1];
				$baseUrl = $url[0].'?'.implode('&', $prm);
			}
		}	
		return $baseUrl;
	}

	private function getUserConf() {
		if (is_null($this->userConf)) {
			$this->userConf = $this->callApi('CMD_API_SHOW_USER_CONFIG', array('user' => $this->user));
		}
		return $this->userConf;
	}
	
	public function getHostingPlan() {
		if (is_null($this->hostingPlan)) {
			$uc = $this->getUserConf();
			$this->hostingPlan = (isset($uc['package']) && $uc['package']) ? $uc['package'] : null;
			if ((!$this->hostingPlan || $this->hostingPlan == 'custom') && isset($uc['original_package']) && $uc['original_package']) {
				$this->hostingPlan = $uc['original_package'];
			}
		}
		return $this->hostingPlan;
	}

	/** @param string $hostingPlan */
	public function setHostingPlan($hostingPlan) {
		$this->hostingPlan = $hostingPlan;
	}
}

class FtpData {
	public $username = null;
	public $password = null;

	public static function from($username, $password) {
		$self = new self();
		$self->username = $username;
		$self->password = $password;
		return $self;
	}
}
