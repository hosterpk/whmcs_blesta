<?php

namespace WHMCS_cPanel_SiteBuilder_Module;

use CPANEL;
use ErrorException;
use stdClass;

require_once dirname(__FILE__).'/SecureUtil.php';
require_once dirname(__FILE__).'/ILoggable.php';
require_once dirname(__FILE__).'/ICPanelApiHandler.php';
require_once dirname(__FILE__).'/SiteApiClient.php';

class SiteBuilder extends SiteApiClient implements ILoggable {
	/** @var CPANEL|null */
	private $cpanel = null;
	/** @var ICPanelApiHandler */
	private $customApiHandler;
	/** @var boolean */
	private $debug = false;
	/** @var string */
	private $lang;
	/** @var string|null */
	private $serverAddr;
	/** @var string|null */
	private $productName = null;
	/** @var string[]|null */
	private $addonNames = null;

	public static $apiUrl = '';
	public static $apiUser = '';
	public static $apiPass = '';
	public static $isBillingPanel = false;
	public static $userId = false;
	public static $licenseHash = null;
	public static $panel = 'WHM_cPanel';
	public static $createFromHash = null;
	public static $publicKey = null;
	public static $pluginVersion = null;

	const SSH_KEY_NAME = 'sitepro_builder';

	public function __construct() {
		parent::__construct();
		$this->debug = (isset($_GET['debug']) && $_GET['debug'] == 'true');
		if ($this->isLogEnabled()) {
			error_reporting(E_ALL);
			ini_set('display_errors', true);
		}
		$this->log($_SERVER, 'SERVER');
	}

	public static function setup($apiUrl, $apiUser, $apiPass, $userId = null, $licenseHash = null, $panel = 'WHM_cPanel', $createFromHash = null, $publicKey = null, $pluginVersion = null) {
		self::$apiUrl = $apiUrl;
		self::$apiUser = $apiUser;
		self::$apiPass = $apiPass;
		self::$isBillingPanel = in_array($panel, ['WHMCS', 'HostBill', 'Blesta']);
		self::$userId = $userId;
		self::$licenseHash = $licenseHash;
		self::$panel = $panel;
		self::$createFromHash = $createFromHash;
		self::$publicKey = $publicKey;
		self::$pluginVersion = $pluginVersion;
	}
	
	public function log($msg, $title = null) {
		if ($this->isLogEnabled()) {
			if ($title) echo '<strong>'.$title.':</strong><br />';
			echo '<pre>'.htmlspecialchars(is_string($msg) ? $msg : print_r($msg, true)).'</pre><br />';
		}
	}
	
	public function isLogEnabled() {
		return $this->debug;
	}

	public function setCustomApiHandler(ICPanelApiHandler $handler) {
		$this->customApiHandler = $handler;
	}

	/** @return CPANEL */
	public function cpanelApi() {
		if ($this->cpanel === null) {
			require_once "/usr/local/cpanel/php/cpanel.php";
			$this->cpanel = new CPANEL();
		}
		return $this->cpanel;
	}
	
	public function setServerAddr($serverAddr) {
		$this->serverAddr = $serverAddr;
	}

	private function getServerAddr() {
		if (!$this->serverAddr) {
			if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR']) $this->serverAddr = $_SERVER['SERVER_ADDR'];
			if (!$this->serverAddr && isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) $this->serverAddr = $_SERVER['HTTP_HOST'];
		}
		return $this->serverAddr;
	}
	
	private function apiCall($type, $module, $function, $params = array()) {
		if ($this->customApiHandler) {
			return $this->customApiHandler->call($module, $function, $params);
		} else {
			try {
				if ($type == 'uapi') {
				$resp = $this->cpanelApi()->uapi($module, $function, $params);
				$res = $resp['cpanelresult']['result'];
					$status = isset($res['status']) ? $res['status'] : null;
					$errors = isset($res['errors']) ? $res['errors'] : null;
				}
				elseif ($type == 'api2') {
					$resp = $this->cpanelApi()->api2($module, $function, $params);
					$res = $resp['cpanelresult'];
					$status = isset($res['event']['result']) ? $res['event']['result'] : null;
					$errors = isset($res['reason']) ? $res['reason'] : null;
				}
				else {
					return null;
				}

				if ($status != 1) {
					$error = "API Error (2) [$module :: $function]:";
					if ($errors) {
						if (is_array($errors)) {
							$error .= '<br />'.implode('<br />', $res['errors']);
						} else {
							$error = ' '. $errors;
						}
					} else {
						$error .= ' unsuccessful call<br />Response from cPanel API:<br />'
								.'<pre>'.print_r($resp, true).'</pre>';
					}
					throw new ErrorException($error);
				}
			} catch (ErrorException $ex) {
				if (!$this->isLogEnabled()) {
					throw $ex;
				} else {
					$this->log($ex->getMessage(), 'API Error');
					exit();
				}
			}
			return isset($res['data']) ? $res['data'] : null;
		}
	}
	
	public function uapiCall($module, $function, $params = array()) {
		return $this->apiCall('uapi', $module, $function, $params);
	}

	public function api2Call($module, $function, $params = array()) {
		return $this->apiCall('api2', $module, $function, $params);
	}

	public function checkSSHKey() {
		if (!self::$publicKey) {
			return false;
		}
		$isKey = $this->isSSHKey();
		if (!$isKey) {
			$this->saveSSHKey();
			$isKey = $this->isSSHKey();
		}
		return $isKey;
	}

	private function isSSHKey()
	{
		if (self::$isBillingPanel) {
			if (!$this->customApiHandler) throw new ErrorException('API handler not set.');
			$publicKeys = $this->customApiHandler->call('SSH', 'listkeys', array('pub' => 1));

			if (empty($publicKeys)) {
				return false;
			}

			foreach($publicKeys as $key) {
				if ($key['authstatus'] == 'authorized' && $key['name'] == self::SSH_KEY_NAME) {
					return true;
				}
			}
		}
		else {
			$publicKeys = $this->api2Call('SSH', 'listkeys', array('pub' => 1));
			if (empty($publicKeys)) {
				return false;
			}

			foreach($publicKeys as $key) {
				if ($key['authstatus'] == 'authorized' && $key['name'] == self::SSH_KEY_NAME) {
					return true;
				}
			}
		}

		return false;
	}

	private function saveSSHKey()
	{
		if (self::$isBillingPanel) {
			if (!$this->customApiHandler) throw new ErrorException('API handler not set.');
			$this->customApiHandler->call('SSH', 'delkey', array('name' => self::SSH_KEY_NAME, 'pub' => 1));
			$res = $this->customApiHandler->call('SSH', 'importkey', array('key' => self::$publicKey, 'name'=> self::SSH_KEY_NAME));
			if (!$res['name']) {
				return;
			}
			$res = $this->customApiHandler->call('SSH', 'fetchkey', array('name'=> self::SSH_KEY_NAME, 'pub' => 1));
			if (!$res['name']) {
				return;
			}
			$res = $this->customApiHandler->call('SSH', 'authkey', array('key'=> self::SSH_KEY_NAME, 'action' => 'authorize'));
		}
		else {
			$res = $this->api2Call('SSH', 'delkey', array('name' => self::SSH_KEY_NAME, 'pub' => 1));
			$res = $this->api2Call('SSH', 'importkey', array('key' => self::$publicKey, 'name'=> self::SSH_KEY_NAME));
			if (empty($res[0]) || empty($res[0]['name'])) {
				return;
			}
			$res = $this->api2Call('SSH', 'fetchkey', array('name'=> self::SSH_KEY_NAME, 'pub' => 1));
			if (empty($res[0]) || empty($res[0]['name'])) {
				return;
			}
			$res = $this->api2Call('SSH', 'authkey', array('key'=> self::SSH_KEY_NAME, 'action' => 'authorize'));
		}
	}

	public function isSSHEnabled()
	{
		$uploadFile = dirname(__FILE__).'/upload_type.txt';
		if (!file_exists($uploadFile)) {
			return false;
		}
		$content = file_get_contents($uploadFile);
		if (!$content) {
			return false;
		}
		$content = str_replace(["\r","\n","\s"], '', $content);
		if ($content == 'ssh') {
			return true;
		}
	}

	public function isShellEnabled($user)
	{
		$res = $this->uapiCall('Variables', 'get_user_information', array('user' => $user));
		if (isset($res['shell']) && strpos($res['shell'], 'noshell') === false) {
			return true;
		}

		return false;
	}
	
	/**
	 * Generate strong password
	 * @param int $length password length
	 * @return string
	 */
	private function generatePassword($length = 9) {
		$sets = array('abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '0123456789', './');
		$password = '';
		foreach ($sets as $set) {
			$password .= $set[array_rand(str_split($set))];
		}
		$all = str_split(implode('', $sets));
		for ($i = 0, $c = ($length - count($sets)); $i < $c; $i++) { $password .= $all[array_rand($all)]; }

		return str_shuffle($password);
	}
	
	/**
	 * Load data from local storage
	 * @param string $path absolute path to local storage file
	 * @return stdClass data object
	 */
	private function localStorageLoad($path) {
		$data = new stdClass();
		if (self::$isBillingPanel) {
			if (!$this->customApiHandler) throw new ErrorException('API handler not set.');
			try {
				$res = $this->customApiHandler->call('Fileman', 'get_file_content',
						array('dir' => dirname($path), 'file' => basename($path)));
				$dataStr = isset($res['content']) ? $res['content'] : null;
			} catch (ErrorException $ex) {}
		} else {
			if (is_file($path)) {
				$dataStr = file_get_contents($path);
			}
		}
		if (isset($dataStr)) {
			$dataObj = $dataStr ? json_decode($dataStr) : null;
			if (is_object($dataObj)) {
				$data = $dataObj;
			}
		}
		if (isset($data->ftp->password) && $data->ftp->password) {
			$dec = SecureUtil::decryptData($data->ftp->password);
			if ($dec === '') { // if password in file is plain
				$this->localStorageStore($path, $data); // encrypt passowrd in file
			} else {
				$data->ftp->password = $dec;
			}
		}
		return $data;
	}
	
	/**
	 * Store data to local storage
	 * @param string $path absolute path to local storage file
	 * @param stdClass $data data object
	 * @return boolean true if success, false otherwise
	 */
	private function localStorageStore($path, $data) {
		$mdata = is_object($data) ? json_decode(json_encode($data)) : (object)array();
		if (isset($mdata->ftp->password) && $mdata->ftp->password) {
			$mdata->ftp->password = SecureUtil::encryptData($mdata->ftp->password);
		}
		$dataStr = json_encode($mdata);
		if (self::$isBillingPanel) {
			if (!$this->customApiHandler) throw new ErrorException('API handler not set.');
			try {
				$this->customApiHandler->call('Fileman', 'save_file_content',
						array('dir' => dirname($path), 'file' => basename($path), 'content' => $dataStr));
			} catch (ErrorException $ex) {}
		} else {
			if (file_put_contents($path, $dataStr) !== false) {
				chmod($path, 0600);
				return true;
			}
		}
		return false;
	}

	private $ftpList = null;
	private function uapiFtpList() {
		if (!$this->ftpList) {
			$this->ftpList = $this->uapiCall('Ftp', 'list_ftp');
		}
		return $this->ftpList;
	}

	/**
	 * Check if FTP account exists
	 * @param string $username
	 * @return boolean
	 * @throws ErrorException
	 */
	private function ftpExists($username) {
		if (!$username) return false;
		$list = $this->uapiFtpList();
		if ($list) {
			list($username2) = explode('@', $username);
			if (!strlen($username2)) $username2 = $username;
			foreach ($list as $li) {
				if ($li['type'] == 'logaccess') continue;
				if ($li['user'] == $username || $li['user'] == $username2) {
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * Check if FTP account is working
	 * @param string $username
	 * @param string $password
	 * @param bool $trySSL
	 * @param bool $tryGlobalHost
	 */
	private function ftpActive($username, $password, $trySSL = false, $tryGlobalHost = false) {
		if (!$username || !$password) return false;
		if (self::$isBillingPanel) $tryGlobalHost = true;
		$host = $tryGlobalHost ? $this->getServerAddr() : '127.0.0.1';
		if (!$host) return false;

		$active = false;
		ini_set('display_errors', true);
		// if (ob_get_level() > 0) ob_end_clean();
		ob_start();
		try {
			$linkId = ($trySSL && function_exists('ftp_ssl_connect')) ? ftp_ssl_connect($host) : ftp_connect($host);
			if (!$linkId) {
				if (!$tryGlobalHost) {
					return $this->ftpActive($username, $password, $trySSL, true);
				} else {
					throw new ErrorException('');
				}
			}
			if (!ftp_login($linkId, $username, $password)) {
				if (!$trySSL) {
					if (preg_match('#cleartext\ sessions|tls|ssl#i', ob_get_contents())) {
						return $this->ftpActive($username, $password, true, $tryGlobalHost);
					}
				} else {
					throw new ErrorException('');
				}
			} else {
				$active = true;
			}
		} catch (ErrorException $ex) {}

		ob_end_clean();
		ini_set('display_errors', false);
		return $active;
	}
	
	/**
	 * Create new FTP account
	 * @param string $username username for ftp account
	 * @param string $domain domain to create FTP account for
	 * @return stdClass ftp data
	 * @throws ErrorException
	 */
	private function ftpCreate($username, $domain = null) {
		$user = $username.($domain ? '@'.$domain : '');
		$pass = $this->generatePassword(16);
		$this->uapiCall('Ftp', 'add_ftp', array('user' => $user, 'pass' => $pass, 'homedir' => '/'));
		return array($user, $pass);
	}
	
	public function getLang() {
		if (!$this->lang) {
			try {
				$res = $this->uapiCall('Locale', 'get_attributes');
				$this->lang = $res['locale'];
			} catch (ErrorException $ex) {}
		}
		return $this->lang;
	}

	public function setLang($lang) {
		$this->lang = $lang;
	}

	public function setProductName($productName) {
		$this->productName = $productName;
	}

	public function setAddonNames(array $addonNames) {
		$this->addonNames = $addonNames;
	}
	
	/**
	 * Open builder.
	 * @param string $domain
	 * @param string $user
	 * @param string $pass
	 * @param boolean $outputUrl if TRUE then instead of redirect to builder URL it will be output
	 * @throws ErrorException
	 */
	public function openBuilder($domain, $user, $pass, $outputUrl = false, $customHostingPlan = null, $clientId = '', $clientEmail = '', $openerButtonId = '') {
		$hostingPlan = $customHostingPlan;
		$uploadDir = null;
		$domAddr = null;
		
		$res = $this->uapiCall('DomainInfo', 'single_domain_data', array('domain' => $domain));
		$homeDir = $res['homedir'];
		$uploadDir = ltrim(str_replace($homeDir, '', $res['documentroot']), '/');
		$lang = $this->getLang();
		if (!$lang) $lang = null;
		
		$apiUrl = (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null;
		$res = $this->uapiCall('StatsBar', 'get_stats', array('display' => 'dedicatedip|sharedip|hostname|hostingpackage'));
		foreach ($res as $inf) {
			if (!$domAddr && ($inf['id'] == 'dedicatedip' || $inf['id'] == 'sharedip' || $inf['id'] == 'hostname')) {
				$domAddr = $inf['value'] ? $inf['value'] : null;
			} else if (!$hostingPlan && $inf['id'] == 'hostingpackage') {
				$hostingPlan = $inf['value'] ? $inf['value'] : null;
			}
		}
		if (!$apiUrl) $apiUrl = $domAddr;

		$sshMode = !self::$isBillingPanel && $this->isSSHEnabled() && $this->isShellEnabled($user) && $this->checkSSHKey($user);
		if ($sshMode) {
			if (strpos($uploadDir, $homeDir) === false) {
				$uploadDir = '/' . $homeDir . '/' . trim($uploadDir, '/');
			}
		} else {
			$localStorage = ((!self::$isBillingPanel && isset($_SERVER['HOME']) && $_SERVER['HOME']) ? $_SERVER['HOME'] : $homeDir).'/.spbldr_localStorage';
			$this->log($localStorage, "Local storage path");
			$ls = $this->localStorageLoad($localStorage);
			if (!$pass || $pass == '__HIDDEN__' || (self::$isBillingPanel && $pass && (!$this->ftpExists($user) || !$this->ftpActive($user, $pass)))) {
				$isset = isset($ls->ftp) && isset($ls->ftp->username, $ls->ftp->password) && $ls->ftp->username && $ls->ftp->password;
				$this->log(array('isset' => $isset), "Retrieved FTP account information");
				$exists = $isset && $this->ftpExists($ls->ftp->username);
				$this->log(array('exists' => $exists), "Retrieved FTP account information");
				$active = $exists && $this->ftpActive($ls->ftp->username, $ls->ftp->password);
				$this->log(array('active' => $active), "Retrieved FTP account information");
				try {
					if (!$active) {
						$ls->ftp = (object) array();
						list($ls->ftp->username, $ls->ftp->password) = $this->ftpCreate(sprintf('%08x', crc32('spbldr...'.microtime())), $domain);
						$this->log($ls->ftp, "Created FTP account");
						$this->localStorageStore($localStorage, $ls);
					}
				} catch (ErrorException $ex) {
					if (!self::$isBillingPanel) throw $ex;
				}
				try {
					if (isset($ls->ftp) && is_object($ls->ftp)) {
						$user = $ls->ftp->username;
						$pass = $ls->ftp->password;
						if (isset($ls->ftp->docRoot) && $ls->ftp->docRoot) $uploadDir = $ls->ftp->docRoot;
					}
				} catch (ErrorException $ex) {
					if (!self::$isBillingPanel) throw $ex;
				}
			} else if (!self::$isBillingPanel) {
				try {
					if (!isset($ls->ftp) || $ls->ftp->username != $user || $ls->ftp->password != $pass) {
						$ls->ftp = (object) array('username' => $user, 'password' => $pass, 'native' => true);
						$this->localStorageStore($localStorage, $ls);
					}
				} catch (ErrorException $ex) {
					if (!self::$isBillingPanel) throw $ex;
				}
			}
		}

		$params = array(
			"type" => $sshMode ? 'ssh' : 'external',
			"domain" => $domain,
			"lang" => ($outputUrl ? null : $lang),
			"username" => $user,
			"password" => $sshMode ? '-' : $pass,
			"apiUrl" => $domAddr,
			"hostingPlan" => $hostingPlan,
			"productName" => $this->productName,
			"addonNames" => $this->addonNames,
			"uploadDir" => $uploadDir,
			"panel" => self::$panel,
			"userId" => self::$userId,
			"licenseHash" => self::$licenseHash,
			"createFrom" => self::$createFromHash,
			"clientId" => $clientId,
			"clientEmail" => $clientEmail,
			"openerButtonId" => $openerButtonId,
			'pluginVersion' => self::$pluginVersion
		);
		if ($this->serverAddr) {
			$params['serverIp'] = $this->serverAddr;
		}
		
		$usr = $this->remoteCall('requestLogin', $params);
		$this->log($usr, "Builder API response");
		if ($this->isLogEnabled()) {
			if (self::$isBillingPanel) exit(); else return;
		}
		
		if (is_object($usr) && isset($usr->url) && $usr->url) {
			if ($outputUrl) {
				echo $usr->url;
			} else {
				header('Location: '.$usr->url, true);
			}
			exit();
		} else if (is_object($usr) && isset($usr->error) && $usr->error) {
			throw new ErrorException($usr->error->message);
		} else {
			throw new ErrorException('Error: server error');
		}
	}
	
	/** WHM has no curl installed by default */
	public function remoteCall($method, $params, $timeout = 300, $_redirected = 0, $_url = null, $connTimeout = null) {
		$useCurl = function_exists('curl_init');
		$this->log(($useCurl ? 'true' : 'false'), 'Use cURL');
		if ($useCurl) return parent::remoteCall($method, $params, $timeout, $_redirected, $_url, $connTimeout);
		
		if ($this->debug) {
			$url_parts = parse_url($this->apiUrl); $ip = null;
			if (isset($url_parts['host']) && $url_parts['host']) {
				$ip = gethostbyname($url_parts['host']);
			}
			$this->log($ip, "Builder API host IP");
		}
		$this->log($this->apiUrl.$method, "Builder API URL");
		
		$uinf = parse_url($this->apiUrl.$method);
		$request_host = $uinf['host'];
		$request_uri = $uinf['path'];
		$request_uri .= (isset($uinf['query']) && $uinf['query']) ? ('?'.$uinf['query']) : '';
		
		$errno = $errstr = null;
		$fp = fsockopen($request_host, (isset($uinf['port']) ? $uinf['port'] : 80), $errno, $errstr, 30);
		if (!$fp) {
			if ($errno === 0 && (gethostbyname($request_host) == $request_host)) {
				throw new ErrorException("Error: domain '$request_host' is not resolved.");
			}
			throw new ErrorException("$errstr ($errno)");
		} else {
			$post = json_encode($params);
			$content_length = mb_strlen($post, '8bit');
			
			$headers = "POST $request_uri HTTP/1.1\r\n";
			$headers .= "Host: $request_host\r\n";
			$headers .= "Connection: Close\r\n";
			$headers .= "Accept: text/html,application/json\r\n";
			$headers .= "User-Agent: Website Builder plugin\r\n";
			$headers .= "Authorization: Basic ".base64_encode($this->apiUser.':'.$this->apiPass)."\r\n";
			$headers .= "Content-Type: application/json\r\n";
			$headers .= "Content-Length: $content_length\r\n";
			$headers .= "\r\n";
			fwrite($fp, $headers);
			if ($post) fwrite($fp, $post);
			$response_ = '';
			while (!feof($fp)) {
				$response_ .= fgets($fp);
			}
			fclose($fp);
			
			$response = explode("\r\n\r\n", $response_, 2);
			$result = array();
			$result['header']	= $response[0];
			$result['body']		= isset($response[1]) ? $response[1] : '';
			
			if (preg_match('#(?:\r\nTransfer-Encoding: chunked\r\n)#ism', $result['header'])) {
				$body = $result['body'];
				$result['body'] = '';
				$m = null;
				while (preg_match('#(?:\r\n|^)[0-9a-f]+\r\n#ism', $body, $m, PREG_OFFSET_CAPTURE)) {
					$size = intval(trim($m[0][0]), 16);
					if ($size) {
						$result['body'] .= substr($body, $m[0][1] + strlen($m[0][0]), $size);
					}
					$body = substr($body, $m[0][1] + strlen($m[0][0]) + $size);
				}
			}
			
			$http_code = preg_match('#^HTTP/1\.[0-9]+\ ([0-9]+)\ [^\ ]+.*#i', $result['header'], $m) ? $m[1] : 404;
			if ($http_code != 200) {
				$res = json_decode($result['body']);
				if (!$res) {
					$res = null;
					throw new ErrorException('Response Code ('.$http_code.')');
				}
			} else {
				$res = json_decode($result['body']);
			}
			return $res;
		}
		return null;
	}
	
}
