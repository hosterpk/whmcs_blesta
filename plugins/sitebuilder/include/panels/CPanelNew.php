<?php

namespace Sitebuilder\Panels;

require_once __DIR__.'/Panel.php';
require_once __DIR__.'/../classes/Punycode.php';

use ErrorException;
use Sitebuilder\Panels\CPanelNew\CpanelApi;
use Sitebuilder\Panels\CPanelNew\i18n;
use WHMCS_cPanel_SiteBuilder_Module\SiteBuilder;
use Sitebuilder\Classes\Punycode;

class CPanelNew extends Panel {
	/** @var SiteBuilder */
	protected $module;
	/** @var SiteBuilderCore\i18n */
	protected $i18n;
	private $mainDomainMode = false;
	/** @var array|null */
	private $translationOverwrites = null;
	
	private $clientId = '';
	private $clientEmail = '';

	private function handleBuilderOpen($domain) {
		$this->module->openBuilder($domain, $this->username, $this->password, false, $this->hostingPlan, $this->clientId, $this->clientEmail);
	}
	
	public function process() {
		require_once dirname(__FILE__).'/CPanelNew/CpanelApi.php';
		require_once dirname(__FILE__).'/CPanelNew/SiteBuilder.class.php';
		try {
			$api = new CpanelApi();
			$api->configure(
				'https://'.$this->serverHost.':2087/',
				$this->serverUsername,
				$this->serverPassword,
				$this->serverAccesshash,
				$this->builderPublicKey
			);
			$api->setCpanelUser($this->username);
			$this->module = new SiteBuilder($this->builderApiUrl, $this->builderUsername, $this->builderPassword, true);
			$this->module->setup(
				$this->builderApiUrl,
				$this->builderUsername,
				$this->builderPassword,
				$this->builderUserId,
				$this->builderLicenseHash,
				$this->panel,
				$this->createFromHash,
				null,
				$this->pluginVersion
			);
			$api->setLogging($this->module);
			$this->module->setServerAddr($this->serverIp);
			$this->module->setCustomApiHandler($api);
			$this->module->setProductName($this->productName);
			$this->module->setAddonNames($this->addonNames);
			$this->module->setLang($this->langCode);

			require_once dirname(__FILE__).'/CPanelNew/i18n.php';
			$this->i18n = i18n::getInstance();
			$this->i18n->setLang($this->langCode);

			$list = $api->call('Variables', 'get_user_information', array('format' => 'list'));

			$this->clientId = isset($list['uid']) ? $list['uid'] : '';
			$this->clientEmail = isset($list['contact_email']) ? $list['contact_email'] : '';

			if (isset($_GET['domain']) && $_GET['domain']) {
				$this->handleBuilderOpen($_GET['domain']);
			}
			$list = $api->call('DomainInfo', 'domains_data', array('format' => 'list'));
			
			$puny = new Punycode();
			$mainDomainExists = false;
			foreach ($list as $li) {

				try { $liDomainEncoded = $puny->encode($li['domain']); }
				catch (\Exception $ex) { $liDomainEncoded = $li['domain']; }

				try { $thisDomainEncoded = $puny->encode($this->domain); }
				catch (\Exception $ex) { $thisDomainEncoded = $this->domain; }

				if (isset($li['domain']) && ($li['domain'] == $this->domain || $liDomainEncoded == $this->domain || $li['domain'] == $thisDomainEncoded)) {
					$mainDomainExists = true;
					break;
				}
			}
			if (!$mainDomainExists) {
				throw new ErrorException('Domain "'.$this->domain.'" not found in the list of available domains on this cPanel account.');
			}
			if ($this->mainDomainMode) {
				foreach ($list as $li) {
					if (isset($li['type']) && $li['type'] == 'main_domain') {
						$this->handleBuilderOpen($li['domain']);
					}
				}
			}
			if (count($list) == 1) {
				$this->handleBuilderOpen($list[0]['domain']);
			}
		} catch (ErrorException $ex) {
			if ($this->module->isLogEnabled()) {
				$apiLogging->log($ex->getMessage(), 'ERROR');
			} else {
				throw $ex;
			}
		}
		if ($this->module->isLogEnabled()) {
			exit();
		}
		return $this->genDomainListHtml($list);
	}

	public function setOnlyMainDomainMode($mainDomainMode) {
		$this->mainDomainMode = !!$mainDomainMode;
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
		return isset($this->translationOverwrites[$msg]) ? $this->translationOverwrites[$msg] : $this->i18n->__($msg);
	}
	
	private function genDomainListHtml($list) {
		$html = (($t = $this->__('SiteBuilder_SelectDomain')) ? "<h2>{$t}</h2>" : '')
				.(($t = $this->__('SiteBuilder_ChooseDomainDesc')) ? "<p>{$t}</p>" : '')
				.'<table class="table">'
					.'<thead>'
						.'<tr>'
							.'<th>'.$this->__('SiteBuilder_Domain').'</th>'
							.'<th>'.$this->__('SiteBuilder_DocRoot').'</th>'
						.'</tr>'
					.'</thead>'
					.'<tbody>';
		if (empty($list)) {
			$html .= '<tr><td colspan="2">No domains</td></tr>';
		} else {
			foreach ($list as $li) {
				$html .= '<tr>'
						.'<td><a href="?'.self::getQueryString(array('domain' => $li['domain'])).'">'.$li['domain'].'</a></td>'
						.'<td>'.$li['documentroot'].'</td>'
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
