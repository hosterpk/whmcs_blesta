<?php

/**
 * SitePro builder module.
 */
class Modules_Siteprobuilder_SitePro {
	private $siteId;
	private $domainName;
	/** @var Modules_Siteprobuilder_Api */
	private $_siteproApi;
	private $error;
	/** @var pm_ApiRpc */
	private $api;
	/** @var Modules_Siteprobuilder_ICustomApiHandler */
	private $customApiHandler = null;
	/** @var string|null */
	private $productName = null;
	/** @var string[]|null */
	private $addonNames = null;
	/** @var string|null */
	private $forceHostingPlan = null;
	
	public static $apiUrl = '';
	public static $apiUser = '';
	public static $apiPass = '';
	public static $userId = null;
	public static $licenseHash = null;
	private static $publicKey = null;
	private static $defaultLicenseHash = 'TP4a7TBgGyDyqTzHkSHQze45wHGXnmgJnwzkshAmBN';
	private static $panel = 'Plesk';
	private static $createFromHash = null;
	private static $disallowWhenSuspended = false;
	private static $pluginVersion = '';
	
	public function __construct($siteId = null, $domainName = null) {
		$this->siteId = $siteId;
		$this->domainName = $domainName;
		$this->_siteproApi = new Modules_Siteprobuilder_Api();
	}

	public static function setup($apiUrl, $apiUser, $apiPass, $userId = null, $licenseHash = null, $publicKey = null,
			$panel = 'Plesk', $createFromHash = null, $disallowWhenSuspended = false, $pluginVersion = '') {
		self::$apiUrl = $apiUrl;
		self::$apiUser = $apiUser;
		self::$apiPass = $apiPass;
		self::$userId = $userId;
		self::$licenseHash = $licenseHash;
		self::$publicKey = $publicKey;
		self::$panel = $panel;
		self::$createFromHash = $createFromHash;
		self::$disallowWhenSuspended = $disallowWhenSuspended;
		self::$pluginVersion = $pluginVersion;
	}

	private static function isPlesk() {
		return self::$panel == 'Plesk';
	}

	public function setCustomApiHandler(Modules_Siteprobuilder_ICustomApiHandler $handler) {
		$this->customApiHandler = $handler;
	}

	/** @return Modules_Siteprobuilder_Api */
	public function &siteproApi() {
		return $this->_siteproApi;
	}

	/** @return Modules_Siteprobuilder_ICustomApiHandler */
	private function &api() {
		if (self::isPlesk()) {
			if (!$this->api) $this->api = pm_ApiRpc::getService();
			return $this->api;
		} else {
			if (!$this->customApiHandler) throw new ErrorException('API handler not set.');
			return $this->customApiHandler;
		}
	}
	
	public function getError() {
		return $this->error;
	}

	/**
	 * Get session client login when logged in as additional user or null otherwise.
	 * @param string $default
	 * @return string
	 */
	private static function getSessionClientLogin($default = null) {
		if (isset($_SESSION['auth']['smbUserId']) && $_SESSION['auth']['smbUserId']
			&& isset($_SESSION['auth']['sessionClientId']) && $_SESSION['auth']['sessionClientId']) {
			$custClient = pm_Client::getByClientId($_SESSION['auth']['sessionClientId']);
			if ($custClient && $custClient->getLogin()) {
				return $custClient->getLogin();
			}
		}
		return $default;
	}

	public static function isPluginEnabled() {
		return (class_exists('Modules_Siteprobuilder_Permissions'))
				? Modules_Siteprobuilder_Permissions::isPluginEnabled() : true;
	}
	
	public static function getDomainsData() {
		$data = array();
		$domains = pm_Session::getCurrentDomains();
		foreach ($domains as $domain) {
			if ($domain->hasHosting()) {
				$data[] = (object) array(
					'id' => $domain->getId(),
					'domain' => $domain->getName(),
					'path' => $domain->getDocumentRoot()
				);
			}
		}
		return $data;
	}

	private function log($siteId, $type, $data, $domain = null) {
		if (is_string($data)) {
			$msg = $data;
		} else if (class_exists('SimpleXMLElement') && ($data instanceof SimpleXMLElement)) {
			$msg = $data->asXML();
		} else if (is_null($data)) {
			$msg = 'NULL';
		} else {
			$msg = print_r($data, true);
		}
		$addCont = "[Date: ".date('Y-m-d H:i:s') . ($domain ? ", Domain: $domain" : "").", Type: $type]\n$msg\n";
		$placeholder = '[EARLIER CONTENT CLEARED] ';

		if (self::isPlesk()) {
			$fileManager = new pm_FileManager($siteId);
			$filePath = $fileManager->getFilePath('.htsitebuilder_log');
			$fileCont = ($fileManager->fileExists($filePath)) ? $fileManager->fileGetContents($filePath) : '';
		} else {
			$logDir = __DIR__.'/log';
			if (!is_dir($logDir)) mkdir($logDir, 0755);
			$filePath = $logDir.'/'.$this->domainName.'.log';
			$fileCont = (is_file($filePath)) ? file_get_contents($filePath) : '';
		}
		if (strlen($fileCont) >= 1024*1024) {
			$fileCont = $placeholder . substr($fileCont, strlen($addCont) + strlen($placeholder));
		}
		$fileCont .= $addCont;
		if (self::isPlesk()) {
			$fileManager->filePutContents($filePath, $fileCont);
		} else {
			file_put_contents($filePath, $fileCont);
		}
	}

	/**
	 * Get site (domain) info
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-sites-domains/getting-information-about-sites.66583/
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-sites-domains.66541/
	 * @param string $mainDomain domain name to info about.
	 * @return stdClass
	 */
	private function getSiteData() {
		$request = <<<APIRPC
		<site>
			<get>
				<filter>
					<name>{$this->domainName}</name>
				</filter>
				<dataset>
					<gen_info/>
					<hosting/>
				</dataset>
			</get>
		</site>
APIRPC;
		$response = $this->api()->call($request, self::getSessionClientLogin());
		$result = (isset($response->site->get->result) && $response->site->get->result) ? $response->site->get->result : null;
		Modules_Siteprobuilder_Functions::check_result_status($result, 'get site data');
		
		if (!$this->siteId && isset($result->id) && intval($result->id)) {
			$this->siteId = intval($result->id);
		}
		
		$data = (object) array(
			'domain' => null,
			'ftpUser' => null,
			'ftpPass' => null,
			'ipAddr' => null,
			'webspaceId' => null,
			'path' => null,
		);
		
		if ($result) {
			if (!$this->siteId && isset($data->id) && $data->id) {
				$this->siteId = $data->id;
			}
			$data->domain = (isset($result->data->gen_info->name) && $result->data->gen_info->name) ? (string) $result->data->gen_info->name : null;
			$data->webspaceId = (isset($result->data->gen_info->{'webspace-id'}) && $result->data->gen_info->{'webspace-id'}) ? (string) $result->data->gen_info->{'webspace-id'} : null;
			$data->ipAddr = (isset($result->data->hosting->vrt_hst->ip_address) && $result->data->hosting->vrt_hst->ip_address) ? (string) $result->data->hosting->vrt_hst->ip_address : null;
			if (isset($result->data->hosting->vrt_hst->property)) {
				foreach ($result->data->hosting->vrt_hst->property as $prop) {
					switch ((string) $prop->name) {
						case 'ftp_login':
							$data->ftpUser = (string) $prop->value;
						break;
						case 'ftp_password':
							$data->ftpPass = (string) $prop->value;
						break;
						case 'www_root':
							$data->path = (string) $prop->value;
						break;
					}
				}
			}
		}
		$dataLog = clone $data;
		$dataLog->ftpPass = self::maskPassword($dataLog->ftpPass);
		$this->log($this->siteId, 'getSiteData', $dataLog, $this->domainName);

		return $data;
	}

	private static function maskPassword($password, $openChars = 1) {
		return substr($password, 0, $openChars).'***'.substr($password, -$openChars);
	}

	/**
	 * Get subscription (Webspace) data.
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-subscriptions-webspaces/getting-information-about-subscriptions.33899/
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-subscriptions-webspaces.33852/
	 * @return stdClass
	 */
	private function getWebspaceData($id) {
		$request = <<<APIRPC
		<webspace>
			<get>
				<filter>
					<id>{$id}</id>
				</filter>
				<dataset>
					<gen_info/>
					<hosting/>
					<subscriptions/>
				</dataset>
			</get>
		</webspace>
APIRPC;
		$response = $this->api()->call($request, self::getSessionClientLogin());
		$result = (isset($response->webspace->get->result) && $response->webspace->get->result) ? $response->webspace->get->result : null;
		Modules_Siteprobuilder_Functions::check_result_status($result, 'get webspace data');
		
		$data = (object) array(
			'path' => null,
			'planIds' => array(),
			'hasShell' => false,
			'status' => true,
			'userId' => 0
		);
		
		if ($result) {
			$subscription = isset($result->data->subscriptions->subscription) ? $result->data->subscriptions->subscription : null;
			if ($subscription && isset($subscription->plan)) {
				foreach ($subscription->plan as $li) {
					$data->planIds[] = (string) $li->{'plan-guid'};
				}
			}
			if (isset($result->data->hosting->vrt_hst->property)) {
				foreach ($result->data->hosting->vrt_hst->property as $prop) {
					$name = isset($prop->name) ? (string)$prop->name : '';
					$val = isset($prop->value) ? (string)$prop->value : '';
					if (!$name || !$val) continue;
					if ($name == 'www_root') {
						$ds = preg_quote(DIRECTORY_SEPARATOR);
						if (strpos($val, DIRECTORY_SEPARATOR.'httpdocs') !== false) {
							list($data->path) = explode('httpdocs', $val, 2);
							$data->path = preg_match('#^(\/var\/www\/vhosts\/[^\/]+)\/.+$#i', $data->path, $m) ? $m[1] : $data->path;
						}
						else if (strpos($val, DIRECTORY_SEPARATOR.'public_html') !== false) {
							list($data->path) = explode('public_html', $val, 2);
						}
						else if (preg_match('#^(\/home\/[^\/]+)\/[^\/]+$#ui', $val, $m)) {
							$data->path = $m[1];
						}
						else if (preg_match("#^(.+{$ds}vhosts{$ds}[^$ds]+)#i", $val, $m)) {
							$data->path = $m[1];
						}
						else {
							$data->path = $val;
						}
						$data->path = rtrim($data->path, DIRECTORY_SEPARATOR);
						break;
					}
					else if ($name == 'shell') {
						$data->hasShell = $val && $val != '/bin/false';
					}
				}
			}
			if (isset($result->data->gen_info->status)) {
				$data->status = $result->data->gen_info->status == 0;
			}
			if (isset($result->data->gen_info->{'owner-id'})) {
				$data->userId = $result->data->gen_info->{'owner-id'};
			}
		}
		$this->log($this->siteId, 'getWebspaceData', $data, $this->domainName);

		return $data;
	}

	/**
	 * Get info of service and addon plans.
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-service-plans/getting-information-on-service-plans.32915/
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-service-plans.32891/
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-addon-plans/getting-information-on-addon-plans.66434/
	 * @link http://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-addon-plans.66420/
	 * @param string[] $guids list of service plan unique identifiers.
	 * @return [string, string[]]
	 */
	private function getPlans($guids) {
		$servPlan = null;
		$servGuid = null;
		$addonPlans = array();
		foreach ($guids as $guid) {
			$request = '<service-plan><get><filter><guid>'.$guid.'</guid></filter><owner-all/></get></service-plan>';
			$response = $this->api()->call($request, 'admin');
			$result = isset($response->{'service-plan'}->get->result) ? $response->{'service-plan'}->get->result : null;
			if ($result && isset($result->name) && $result->name) {
				$servGuid = $guid;
				$servPlan = (string)$result->name;
				break;
			}
		}
		$this->log($this->siteId, 'servPlan', $servPlan, $this->domainName);
		foreach ($guids as $guid) {
			if ($servGuid == $guid) continue;
			$request = '<service-plan-addon><get><filter><guid>'.$guid.'</guid></filter></get></service-plan-addon>';
			$response = $this->api()->call($request, 'admin');
			$result = isset($response->{'service-plan-addon'}->get->result) ? $response->{'service-plan-addon'}->get->result : null;
			if ($result && isset($result->name) && $result->name) {
				$addonPlans[] = (string)$result->name;
			}
		}
		$this->log($this->siteId, 'addonPlans', $addonPlans, $this->domainName);
		return array($servPlan, $addonPlans);
	}

	/**
	 * Get Administrator Information.
	 * @link https://docs.plesk.com/en-US/12.5/api-rpc/reference/managing-plesk-administrator-information.74559/
	 * @return stdClass
	 */
	public function getAdminInfo() {
		$request = '<server><get><admin/></get></server>';
		$response = $this->api()->call($request);
		if (isset($response->server->get->result->status) && $response->server->get->result->status == 'ok') {
			return (object) array(
				'email' => (string) $response->server->get->result->admin->admin_email,
				'name' => (string) $response->server->get->result->admin->admin_pname,
				'country' => (string) $response->server->get->result->admin->admin_country
			);
		}
		return null;
	}

	/**
	 * Get associative array with license data.
	 * @link https://docs.plesk.com/en-US/onyx/extensions-guide/plesk-features-available-for-extensions/retrieve-data-from-plesk/license.76098
	 * @link http://ch.origin.download.plesk.com/Plesk/PP12/12.0/Doc/en-US/online/plesk-api-rpc/36914.htm
	 * @param string $productId product identifier to get license data for.
	 * @return stdClass
	 */
	public function getLicense($productId = 'ext-siteprobuilder') {
		$licKeys = array();
		if (method_exists('pm_License','getAdditionalKeysList')) {
			$licKeys = pm_License::getAdditionalKeysList($productId);
		} else {
			$request = '<server><get_additional_key/></server>';
			$response = $this->api()->call($request);
			if (isset($response->server->get_additional_key->result)) {
				foreach ($response->server->get_additional_key->result as $result) {
					if (isset($result->status) && $result->status == 'ok' && isset($result->key_info)) {
						$item = array();
						foreach ($result->key_info->children() as $property) {
							if ($property->getName() == 'property') continue;
							$item[$property->getName()] = (string) $property;
						}
						foreach ($result->key_info->property as $property) {
							$item[(string) $property->name] = (string) $property->value;
						}
						$item['lim_date'] = isset($item['lim_date']) ? intval($item['lim_date']) : 0;
						if ($productId && (!isset($item['name']) || (!preg_match('#^.+/'.preg_quote($productId).'$#i', $item['name']) && $productId != $item['name']))) {
							continue;
						}
						$licKeys[] = $item;
					}
				}
			}
		}
		foreach ($licKeys as $lic) {
			if (isset($lic['lim_date']) && $lic['lim_date'] >= intval(date('Ymd')) && isset($lic['key-body']) && $lic['key-body']) {
				$data = json_decode($lic['key-body']);
				if ((!isset($data) || $data === false) && is_string($lic['key-body'])) {
					$data = (object) array(
						'name' => isset($lic['app']) ? $lic['app'] : null,
						'builderLicenseHash' => trim($lic['key-body'])
					);
				}
				if (!is_object($data)) continue;
				return $data;
			}
		}
		return null;
	}

	/**
	 * Try to add builder SSH key to client's authorized_keys file.
	 * @return bool indicating if SSH key was successfully stored.
	 */
	private function storeSSHKey() {
		$key = self::$publicKey;
		if (!$key) return false;
		$fileManager = new pm_FileManager($this->siteId);
		$sshDir = $fileManager->getFilePath('.ssh');
		$sshFile = $fileManager->getFilePath('.ssh/authorized_keys');
		if (!$fileManager->fileExists($sshDir)) $fileManager->mkdir($sshDir, '0755', true);
		$content = ($fileManager->fileExists($sshFile)) ? $fileManager->fileGetContents($sshFile) : '';
		if (strpos($content, $key) === false) {
			$content = trim($content, "\n")."\n".$key."\n";
			$fileManager->filePutContents($sshFile, $content);
		}
		return true;
	}

	public function setProductName($productName) {
		$this->productName = $productName;
	}

	public function setAddonNames(array $addonNames) {
		$this->addonNames = $addonNames;
	}

	public function forceHostingPlan($plan) {
		$this->forceHostingPlan = $plan;
	}

	/**
	 * Build parameter list required to open builder.
	 * @return array key value pair array with parameters.
	 */
	private function getParams() {
		$site = $this->getSiteData();
		$webspace = $this->getWebspaceData($site->webspaceId);
		if (!$webspace->status && self::$disallowWhenSuspended) {
			throw new ErrorException('Permission denied: subscription suspended.');
		}
		if ($this->forceHostingPlan) {
			$hostingPlan = $this->forceHostingPlan;
			$addonNames = $this->addonNames;
		} else {
			list($servPlan, $addonNames) = $this->getPlans($webspace->planIds, false);
			$hostingPlan = $servPlan;
		}
		
		$lic = $this->getLicense();
		
		$uploadDir = str_replace($webspace->path, '', $site->path);

		$licHash = ($lic && isset($lic->builderLicenseHash) && $lic->builderLicenseHash) ? $lic->builderLicenseHash : null;
		$defHash = self::$defaultLicenseHash;

		$this->log($this->siteId, 'Licenses', array(
			is_null($licHash) ? 'NULL' : self::maskPassword($licHash, 2),
			is_null($defHash) ? 'NULL' : self::maskPassword($defHash, 2)
		), $this->domainName);

		$sshMode = self::isPlesk() && $webspace->hasShell && pm_Settings::get('pluginSSHAllowed', false) && $this->storeSSHKey();
		
		return array(
			'type' => $sshMode ? 'ssh' : 'external',
			'licenseHash' => self::$licenseHash ? self::$licenseHash : ($licHash ? $licHash : (self::$userId ? null : $defHash)),
			'domain' => $site->domain,
			'ftpUser' => $site->ftpUser, // the same as system user for SSH mode.
			'ftpPass' => $sshMode ? '-' : $site->ftpPass, // private/public keys are used for SSH mode.
			'ipAddr' => $site->ipAddr,
			'uploadDir' => $sshMode ? $site->path : $uploadDir, // need full path for SSH mode.
			'hostingPlan' => $hostingPlan,
			'productName' => $this->productName,
			'addonNames' => $addonNames,
			'panel' => self::$panel,
			'createFrom' => self::$createFromHash,
			'clientId' => isset($webspace->userId) ? (string)$webspace->userId : '',
			'pluginVersion' => self::$pluginVersion
		);
	}
	
	/**
	 * @throws ErrorException
	 */
	public function start() {
		$outputUrl = (isset($_GET['return_url']) && $_GET['return_url']);
		$this->siteproApi()->openBuilder($this->getParams(), $outputUrl);
	}
}
