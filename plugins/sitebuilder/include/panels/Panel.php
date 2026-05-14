<?php

namespace Sitebuilder\Panels;

use ErrorException;

abstract class Panel {
	/** @var string */
	public $username;
	/** @var string */
	public $password;
	/** @var string */
	public $domain;
	/** @var string|null */
	public $hostingPlan = null;
	/** @var string|null */
	public $userEmail = null;

	/** @var string */
	public $serverHost;
	/** @var string */
	public $serverUsername;
	/** @var string|null */
	public $serverPassword = null;
	/** @var string|null */
	public $serverAccesshash = null;
	/** @var string|null */
	public $serverIp = null;
	/** @var string|null */
	public $serverPort = null;
	/** @var bool */
	public $serverSecure = false;

	/** @var string */
	public $builderApiUrl;
	/** @var string */
	public $builderUsername;
	/** @var string */
	public $builderPassword;
	/** @var string|null */
	public $builderUserId = null;
	/** @var string|null */
	public $builderLicenseHash = null;
	/** @var string|null */
	public $builderPublicKey = null;
	/** @var string|null */
	public $panel = null;
	/** @var string|null */
	public $createFromHash = null;
	/** @var string|null */
	public $langCode = null;

	/** @var string|null */
	public $productName = null;
	/** @var string[] */
	public $addonNames = [];

	/** @var string|null */
	public $pluginVersion = null;
	
	protected $tmpDir;
	protected $tmpFile;
	
	private static $panels = array(
		'plesk' => array('class' => '\Sitebuilder\Panels\PleskNew', 'file' => __DIR__.'/PleskNew.php'),
		'cpanel' => array('class' => '\Sitebuilder\Panels\CPanelNew', 'file' => __DIR__.'/CPanelNew.php'),
		'interworx' => array('class' => '\Sitebuilder\Panels\InterWorx', 'file' => __DIR__.'/InterWorx.php'),
		'directadmin' => array('class' => '\Sitebuilder\Panels\DirectAdminNew', 'file' => __DIR__.'/DirectAdminNew.php'),
		'direct_admin' => array('class' => '\Sitebuilder\Panels\DirectAdminNew', 'file' => __DIR__.'/DirectAdminNew.php'),
		'ispmanager' => array('class' => '\Sitebuilder\Panels\ISP5', 'file' => __DIR__.'/ISP5.php'),
		'cwp' => array('class' => '\Sitebuilder\Panels\CWP', 'file' => __DIR__.'/CWP.php'),
		'centoswebpanel' => array('class' => '\Sitebuilder\Panels\CWP', 'file' => __DIR__.'/CWP.php'),
		'webuzo' => array('class' => '\Sitebuilder\Panels\Webuzo', 'file' => __DIR__.'/Webuzo.php'),
	);

	/** @return self|null */
	public static function build($key) {
		$inst = null;
		$obj = isset(self::$panels[$key]) ? self::$panels[$key] : null;
		if (!$obj) {
			foreach (self::$panels as $panelKey => $panel) {
				if (preg_match('#'.preg_quote($panelKey).'#i', $key)) {
					$obj = $panel;
					break;
				}
			}
		}
		if ($obj) {
			require_once $obj['file'];
			$inst = new $obj['class']();
		}
		return $inst;
	}
	
	public static function resolvePanel($serverType) {
		$panel = isset(self::$panels[$serverType]) ? self::$panels[$serverType] : null;
		if (!$panel) {
			foreach (array_keys(self::$panels) as $panelId) {
				if (strpos(strtolower($serverType), $panelId) !== false) {
					$panel = self::$panels[$panelId]; break;
				}
			}
		}
		return $panel;
	}
	
	public function __construct() {
		$this->tmpDir = dirname(__FILE__).'/../tmp';
		$this->tmpFile = $this->tmpDir.'/'.md5(microtime());
	}

	public function process() {
		$url = $this->getUrl();
		$this->removeTmpFile();
		if ($url && $this->checkIsUrl($url)) {
			header('Location: '.$url);
			exit();
		} else if ($url && $this->checkIsError($url)) {
			throw new ErrorException($url);
		} else {
			throw new ErrorException('Error: Could not get Builder URL');
		}
	}
	
	public function identifyUser($username, $password, $domain, $hostingPlan, $userEmail = null) {
		$this->username = $username;
		$this->password = $password;
		$this->domain = $domain;
		$this->hostingPlan = $hostingPlan;
		$this->userEmail = $userEmail;
	}

	public function identifyServer($host, $username, $password, $accesshash, $ip = null, $port = null, $secure = false) {
		$this->serverHost = $host;
		$this->serverUsername = $username;
		$this->serverPassword = $password;
		$this->serverAccesshash = $accesshash;
		$this->serverIp = $ip;
		$this->serverPort = $port;
		$this->serverSecure = !!$secure;
	}

	public function identifyBuilder($apiUrl, $username, $password, $userId = null, $licenseHash = null, $publicKey = null, $panel = null, $createFromHash = null, $langCode = null, $pluginVersion = null) {
		$this->builderApiUrl = $apiUrl;
		$this->builderUsername = $username;
		$this->builderPassword = $password;
		$this->builderUserId = $userId;
		$this->builderLicenseHash = $licenseHash;
		$this->builderPublicKey = $publicKey;
		$this->panel = $panel;
		$this->createFromHash = $createFromHash;
		$this->langCode = $langCode;
		$this->pluginVersion = $pluginVersion;
	}

	public function identifyProduct($productName = null, $addonNames = []) {
		$this->productName = $productName;
		$aNames = [];
		if (is_array($addonNames)) {
			foreach ($addonNames as $name) {
				if (!$name || !is_string($name)) continue;
				$aNames[] = $name;
			}
			$aNames = array_unique($aNames);
		}
		$this->addonNames = $aNames;
	}
	
	protected function getUrl() { return ''; }
	
	private function checkIsUrl($url) {
		return (strpos($url, 'login_hash=') !== false);
	}
	
	private function checkIsError($url) {
		return (strpos(strtolower($url), 'error') !== false);
	}
	
	private function removeTmpFile() {
		if ($this->tmpFile && is_file($this->tmpFile)) {
			@unlink($this->tmpFile);
		}
	}
}
