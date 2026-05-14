<?php

namespace Sitebuilder\Panels;

require_once __DIR__.'/Panel.php';

use ErrorException;
use Sitebuilder\Panels\DirectAdminNew\DBFtpStorage;
use WHMCS_DirectAdmin_SiteBuilder_Module\FileStorage;
use WHMCS_DirectAdmin_SiteBuilder_Module\SiteproModule;

class DirectAdminNew extends Panel {
	/** @var SiteproModule|null */
	private $module = null;

	/** @var array|null */
	private $translationOverwrites = null;

	public function resolvePort() {
		return $this->serverPort ? $this->serverPort : 2222;
	}

	/**
	 * @return SiteproModule
	 */
	private function &getModule() {
		if (!$this->module) {
			require_once __DIR__.'/DirectAdminNew/SiteproModule.php';
			$this->module = new SiteproModule(
				$this->serverHost,
				$this->username,
				$this->domain,
				$this->serverPassword,
				$this->resolvePort(),
				$this->serverSecure
			);
			if ($this->panel == 'Blesta') {
				require_once __DIR__.'/DirectAdminNew/DBFtpStorage.php';
				$this->module->setFtpStorage(new DBFtpStorage($this->username, $this->domain));
			} else {
				require_once __DIR__.'/DirectAdminNew/FileStorage.php';
				$this->module->setFtpStorage(new FileStorage($this->username, $this->domain));
			}
			$this->module->setApiUser("{$this->serverUsername}|{$this->username}");
			$this->module->setLoginKey($this->getLoginKey());
			$this->module->setBaseUri('/CMD_PLUGINS/siteprobuilder/index2.raw');
			$this->module->setup(
				$this->builderApiUrl,
				$this->builderUsername,
				$this->builderPassword,
				$this->builderUserId,
				$this->builderLicenseHash,
				$this->panel,
				$this->createFromHash,
				$this->serverIp,
				false,
				$this->pluginVersion
			);
		}
		return $this->module;
	}

	/**
	 * @return string
	 * @throws ErrorException
	 */
	private function getLoginKey() {
		$module = $this->getModule();
		$loginKeyFile = __DIR__.'/DirectAdminNew/login_key';
		$loginKey = is_file($loginKeyFile) ? file_get_contents($loginKeyFile) : null;
		if (!is_string($loginKey) || strlen($loginKey) != 32) {
			try {
				$prevApiUser = $module->getApiUser();
				$module->setApiUser($this->serverUsername);
				$loginKey = $module->createLoginKey($this->serverPassword, 'siteprobuilder'.strtolower($this->panel ?? '2'));
				$module->setApiUser($prevApiUser);
			} catch (ErrorException $ex) {
				throw new ErrorException('Failed to create login key: '.$ex->getMessage());
			}
			file_put_contents($loginKeyFile, $loginKey);
		}
		return $loginKey;
	}

	public function process() {
		$module = $this->getModule();
		if (!$module->isEnabled())
			throw new ErrorException('This feature is not available for your account');

		if (isset($_GET['run'])) {
			$subdomain = (isset($_GET['sd']) && $_GET['sd']) ? $_GET['sd'] : null;
			$this->openBuilder($module, $subdomain);
		}
		if (!empty(($subdomains = $module->getSubdomains()))) {
			$list = array_merge(array((object) array('id' => null, 'name' => $this->domain)), $subdomains);
			return $this->genDomainListHtml($list);
		} else {
			$this->openBuilder($module);
		}
	}
	
	/**
	 * @param string $subdomain 
	 * @throws ErrorException 
	 */
	private function openBuilder($subdomain = null) {
		$url = $this->getModule()->getBuilderUrl($subdomain, $this->productName, $this->addonNames, $this->langCode, [$this, 'isInstalled']);
		header('Location: '.$url);
		exit();
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
		return isset($this->translationOverwrites[$msg]) ? $this->translationOverwrites[$msg] : $msg;
	}

	private function genDomainListHtml($list) {
		$html = '<div class="sp-content">'
				.(($t = $this->__('Choose domain/subdomain:')) ? "<p>{$t}</p>" : '')
				.'<table class="table" cellpadding="0" cellspacing="0">'
					.'<tbody>';
		foreach ($list as $li) {
			$html .= '<tr><td>'
					.'<a href="'.htmlspecialchars('?'.self::getQueryString(array('sd' => $li->id, 'run' => 1))).'" target="_self">'.$li->name.'</a>'
				.'</td></tr>';
		}
		$html .= '</tbody>'
				.'</table>'
			.'</div>';
		return $html;
	}

	private static function getQueryString($extraParams = array()) {
		$parts = explode('?', $_SERVER['REQUEST_URI'], 2);
		$qs = (isset($parts[1]) ? $parts[1] : '');
		$params = array(); parse_str(html_entity_decode($qs), $params);
		return http_build_query(array_merge($params, $extraParams));
	}

	public function getForceInternalPublication() {
		return $this->getModule()->getForceInternalPublication();
	}

	public function isInstalled() {
		$baseUrl = ($this->serverSecure ? 'https' : 'http') . '://' . $this->serverHost . ':' . $this->resolvePort();
		$url = $baseUrl . '/CMD_PLUGINS/siteprobuilder/index2.raw';
		$loginKey = $this->getLoginKey();
		$authCode = base64_encode("admin|{$this->username}" . ':' . $loginKey);
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 0,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_ENCODING => 'UTF-8',
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'User-Agent: SiteProBuilder/PHP/1.3.6',
				'Referer: ' . $baseUrl,
				'Authorization: Basic ' . $authCode)
		));
		curl_exec($curl);
		$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return $statusCode != 404;
	}
}
