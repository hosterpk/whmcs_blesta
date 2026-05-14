<?php

namespace Sitebuilder\Panels;

use ErrorException;
use Exception;
use Sitebuilder\Panels\CWP\src\storages\DBFtpStorage;
use WHMCS_CWP_SiteBuilder_Module\httpClients\Request;
use WHMCS_CWP_SiteBuilder_Module\InternalApiClient;
use WHMCS_CWP_SiteBuilder_Module\SiteproApiClient;

require_once __DIR__.'/Panel.php';

class CWP extends Panel {
	/** @var array */
	private static $translations;
	private $apiToken = null;
	/** @var array|null */
	private $translationOverwrites = null;

	public function setApiToken($apiToken) {
		$this->apiToken = $apiToken;
	}

	public function process() {
		require_once __DIR__.'/CWP/autoload.php';
		if (!$this->apiToken) {
			throw new ErrorException('CWP API token is required in module configuration');
		}
		$licenseHash = $this->builderLicenseHash ? null : SiteproApiClient::$defaultLicenseHash;

		$request = new Request();
		$request->baseUrl = 'https://' . $this->serverHost . ':2304';
		$request->body['key'] = $this->apiToken;
		$internalClient = new InternalApiClient(
			$request,
			$this->username,
			$this->domain,
			$this->panel
		);
		if ($this->panel == 'Blesta') {
			$storage = new DBFtpStorage($this->username, $this->domain);
			$internalClient->setStorage($storage);
		}
		$internalClient->setServerAddr($this->serverIp);

		// LOAD DOMAINS LIST
		try {
			$domains = $internalClient->getDomains();
		} catch (Exception $e) {
			$domains = array();
		}

		// SELECT DOMAIN
		$error = null;
		$domain = null;
		$qDomain = (isset($_GET['domain']) && $_GET['domain']) ? $_GET['domain'] : null;
		if (count($domains) > 0) {
			if (count($domains) === 1) {
				$domain = reset($domains);
			} else if ($qDomain) {
				foreach ($domains as $d) {
					if ($d['domain'] === $qDomain) {
						$domain = $d;
					}
				}
			}
			if ($domain) {
				try {
					$ftp = $internalClient->getFtp($domain['domain']);
					if (!$ftp) throw new ErrorException('Error: could not retrieve FTP account');
					
					$apiClient = new SiteproApiClient($this->builderApiUrl, $this->builderUsername, $this->builderPassword);
					$params = array(
						'type' => 'external',
						'domain' => $domain['domain'],
						'apiUrl' => $this->serverHost,
						'uploadDir' => $domain['path'],
						'username' => $ftp->buildFullUsername(),
						'password' => $ftp->passftp,
						'hostingPlan' => $internalClient->getPackageName(),
						'productName' => $this->productName,
						'addonNames' => $this->addonNames,
						'lang' => $this->langCode,
						'panel' => $this->panel,
						'licenseHash' => $licenseHash,
						'createFrom' => $this->createFromHash,
						'serverIp' => $this->serverIp,
						'clientId' => $internalClient->username,
						'clientEmail' => $internalClient->useremail,
						'pluginVersion' => $this->pluginVersion
					);
					$res = $apiClient->remoteCall('requestLogin', $params);
					if (is_object($res) && isset($res->url) && $res->url) {
						header('Location: '.$res->url, true);
						exit();
					} else if (is_object($res) && isset($res->error->message) && $res->error->message) {
						throw new ErrorException($res->error->message);
					} else {
						throw new ErrorException('Error: server error');
					}
				} catch (Exception $ex) {
					$error = $ex->getMessage();
				}
			}
		}
		
		if ($error) {
			throw new ErrorException($error);
		} else if (!empty($domains)) {
			return $this->genDomainListHtml($domains);
		}
	}

	private function langValue($key) {
		if (!self::$translations) {
			self::$translations = array();
			$lang = $this->langCode;
			if (!$lang) $lang = 'en';
			$locale_dir = dirname(__FILE__).'/CWP/locale';
			$locale_file = $locale_dir.'/'.$lang;
			if (!is_file($locale_file)) { $locale_file = $locale_dir.'/en'; }
			$locale_data = explode("\n", trim(file_get_contents($locale_file)));
			foreach ($locale_data as $li) {
				$tr = explode('=', $li, 2);
				if (!trim($tr[0]) || !isset($tr[1])) { continue; }
				self::$translations[trim($tr[0])] = trim($tr[1]);
			}
		}
		return isset(self::$translations[$key]) ? self::$translations[$key] : $key;
	}

	/** @param array $translations */
	public function overwriteTranslations($translations) {
		if (!is_array($translations)) return;
		foreach ($translations as $k => $v) {
			if (!is_string($v)) continue;
			$this->translationOverwrites[$k] = $v;
		}
	}

	private function __($msg) {
		return isset($this->translationOverwrites[$msg]) ? $this->translationOverwrites[$msg] : $this->langValue($msg);
	}
	
	private function genDomainListHtml($list) {
		$html = (($t = $this->__('SiteBuilder_SelectDomain')) ? "<h2>{$t}</h2>" : '')
				.(($t = $this->__('SiteBuilder_ChooseDomainDesc')) ? "<p>{$t}</p>" : '')
				.'<table class="table">'
					.'<thead>'
						.'<tr>'
							.'<th>'.$this->langValue('SiteproBuilder_Domain').'</th>'
							.'<th>'.$this->langValue('SiteproBuilder_DocRoot').'</th>'
						.'</tr>'
					.'</thead>'
					.'<tbody>';
		if (empty($list)) {
			$html .= '<tr><td colspan="2">No domains</td></tr>';
		} else {
			foreach ($list as $li) {
				$html .= '<tr>'
						.'<td><a href="?'.self::getQueryString(array('domain' => $li['domain'])).'">'.$li['domain'].'</a></td>'
						.'<td>'.$li['path'].'</td>'
					.'</tr>';
			}
		}
		$html .= '</tbody></table>';
		return $html;
	}
	
	private static function getQueryString($extraParams = array()) {
		$parts = explode('?', $_SERVER['REQUEST_URI'], 2);
		$qs = (isset($parts[1]) ? $parts[1] : '');
		$params = array(); parse_str(html_entity_decode($qs), $params);
		return http_build_query(array_merge($params, $extraParams));
	}
}