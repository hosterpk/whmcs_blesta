<?php

namespace WHMCS_CWP_SiteBuilder_Module;

use ErrorException;
use Exception;
use WHMCS_CWP_SiteBuilder_Module\httpClients\CurlHttpClient;
use WHMCS_CWP_SiteBuilder_Module\httpClients\SocketHttpClient;
use WHMCS_CWP_SiteBuilder_Module\security\Security;
use WHMCS_CWP_SiteBuilder_Module\storages\FileStorage;
use WHMCS_CWP_SiteBuilder_Module\storages\IFtpStorage;

require_once __DIR__.'/storages/IFtpStorage.php';

class InternalApiClient
{
	/**
	 * @var Request
	 */
	protected $baseRequest;

	public $username;
	public $useremail;
	public $userDomain;

	protected $packageName;
	protected $domains;
	/** @var FtpData|null */
	protected $ftp = null;

	private $isBillingPanel;
	private $panel;

	protected $userPath;

	/** @var string|null */
	private $serverAddr;

	/**
	 * @var IFtpStorage|null
	 */
	private $storage = null;

	public function __construct($baseRequest, $username, $userDomain = null, $panel = 'CWP')
	{
		$this->baseRequest = $baseRequest;
		$this->username = $username;
		$this->userDomain = $userDomain;
		$this->isBillingPanel = in_array($panel, ['WHMCS', 'HostBill', 'Blesta']);
		$this->panel = $panel;
	}

	public function setStorage(IFtpStorage $storage) {
		$this->storage = $storage;
	}

	/** @return IFtpStorage */
	public function &getStorage() {
		if ($this->storage === null) {
			if ($this->panel == 'CWP') {
				$this->storage = new FileStorage('sitebuilder');
			} else {
				$this->storage = new FileStorage(__DIR__);
			}
		}
		return $this->storage;
	}

	public function getDomains()
	{
		if (!$this->domains) {
			$this->fetchInfo();
		}
		return $this->domains;
	}

	public function getPackageName()
	{
		if (!$this->packageName) {
			$this->fetchInfo();
		}
		return $this->packageName;
	}

	private function ftpExists(FtpData $ftpData) {
		if (!$ftpData->userftp) return false;
		$request = clone $this->baseRequest;
		$request->body = array_merge($request->body, array("action" => 'list', "user" => $this->username));
		$request->url = '/v1/ftp';
		$response = $this->getHttpClient()->post($request);

		$ftpUser = $ftpData->buildFullUsername();
		if ($response['status'] === 'OK' && is_array($response['msj'])) {
			foreach ($response['msj'] as $ftpAcc) {
				if ($ftpAcc['user'] === $ftpUser) {
					return true;
				}
			}
		}
		return false;
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

	private function ftpActive(FtpData $ftpData, $useSSL = false, $tryGlobalHost = false) {
		if (!$ftpData->userftp || !$ftpData->passftp) return false;
		if ($this->isBillingPanel) $tryGlobalHost = true;
		$host = $tryGlobalHost ? $this->getServerAddr() : '127.0.0.1';
		if (!$host) return false;
		
		$active = false;
		ini_set('display_errors', true);
		if (ob_get_level() > 0) ob_end_clean();
		ob_start();
		try {
			$linkId = ($useSSL && function_exists('ftp_ssl_connect')) ? ftp_ssl_connect($host) : ftp_connect($host);
			if (!$linkId) {
				if (!$tryGlobalHost) {
					return $this->ftpActive($ftpData, $useSSL, true);
				} else {
					throw new ErrorException('');
				}
			}
			if (!ftp_login($linkId, $ftpData->buildFullUsername(), $ftpData->passftp)) {
				if (!$useSSL) {
					if (preg_match('#cleartext\ sessions|tls|ssl#i', ob_get_contents())) {
						return $this->ftpActive($ftpData, true, $tryGlobalHost);
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

	/** @return FtpData|null */
	private function ftpCreate($domain = null) {
		$data = new FtpData();
		$data->user = $this->username;
		$data->userftp = ($this->isBillingPanel ? 'w' : '').sprintf('%08x', crc32('spbldr...'.microtime()));
		$data->passftp = Security::generatePassword();
		$data->domainftp = $this->userDomain ?? $domain;
		$data->pathftp = $this->userPath;

		$request = clone $this->baseRequest;
		$request->body = array_merge($request->body, array('action' => 'add'), $data->toJson());
		$request->url = '/v1/ftp';
		$response = $this->getHttpClient()->post($request);

		if ($response['status'] === 'OK') {
			return $data;
		} else {
			$error = 'Unable create ftp user';
			if (isset($response['status']) && $response['status'] == 'Error' && isset($response['msj']) && $response['msj']) {
				$error .= ': '.$response['msj'];
			}
			throw new Exception($error);
		}
		return null;
	}

	private function updateFtpPath(FtpData $ftpData, $domain = null) {
		if (is_array($this->domains)) {
			foreach ($this->domains as $dom) {
				if ($dom['domain'] == $domain) {
					return $dom['path'];
				}
			}
		}
		$path = $ftpData->pathftp ? $ftpData->pathftp : $this->userPath;
		if (!$domain || $domain == $this->userDomain) {
			$path = rtrim($path, '/') . '/public_html';
		} else {
			$path = rtrim($path, '/') . '/' . $domain;
		}
		$ftpData->pathftp = $path;
	}

	public function getFtp($domain = null)
	{
		if (!$this->domains) {
			$this->fetchInfo();
		}
		if (!$this->domains) {
			return null;
		}
		if ($this->ftp) {
			return $this->ftp;
		}

		$ftp = $this->getStorage()->getFtp();
		$exists =  $ftp && $this->ftpExists($ftp);
		$active = $exists && $this->ftpActive($ftp);
		if (!$active) $ftp = null;
		if (!$ftp) {
			$ftp = $this->ftpCreate($domain);
			if ($ftp) {
				$this->getStorage()->setFtp($ftp);
			} else {
				$this->getStorage()->deleteFtp();
			}
		}

		$this->updateFtpPath($ftp, $domain);

		$this->ftp = $ftp;

		return $this->ftp;
	}

	public function getLang()
	{
		$accepts = '';
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			$accepts = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
		}
		$language = null;
		$maxQ = 0;
		foreach ($accepts as $accept) {
			if (!empty($accept)) {
				$lang = explode(';', $accept);
				$l = $lang[0];
				$q = isset($l[1]) ? (float)str_replace('q=', '', $l[1]) : 1;
				if ($q > $maxQ) {
					$language = $l;
				}
			}
		}

		if ($language) {
			$language = substr($language, 0, 2);
		}
		return $language;
	}

	public function fetchInfo()
	{
		$request = clone $this->baseRequest;
		$request->body = array_merge($request->body, array("action" => 'list', "user" => $this->username));
		$request->url = '/v1/accountdetail';
		$response = $this->getHttpClient()->post($request);

		$domains = [];
		if ($response['status'] === 'OK') {
			if (is_array($response['result'])) {
				$this->packageName = $response['result']['account_info']['package_name'];
				$this->userPath = $response['result']['account_info']['directory'];
				$this->useremail = $response['result']['account_info']['email'];

				if (isset($response['result']['domains']) && is_array($response['result']['domains'])) {
					foreach ($response['result']['domains'] as $domain) {
						$domains[] = [
							'domain' => $domain['domain'],
							'path' => $domain['path'],
						];
					}
				}
				if (isset($response['result']['subdomains']) && is_array($response['result']['subdomains'])) {
					foreach ($response['result']['subdomains'] as $domain) {
						$domains[] = [
							'domain' => $domain['subdomain'] . '.' . $domain['domain'],
							'path' => $domain['path'],
						];
					}
				}
				if (isset($response['result']['subdomins']) && is_array($response['result']['subdomins'])) {
					foreach ($response['result']['subdomins'] as $domain) {
						$domains[] = [
							'domain' => $domain['subdomain'] . '.' . $domain['domain'],
							'path' => $domain['path'],
						];
					}
				}
			}
		} else {
			throw new Exception('Unable load domain list');
		}
		$this->domains = $domains;

		$storage = $this->getStorage();
		if ($storage instanceof FileStorage) {
			if ($this->isBillingPanel) {
				$dir = __DIR__.'/storages/data/'.$this->username;
				if (!is_dir($dir)) mkdir($dir, 0755, true);
				$storage->setBasePath($dir);
			} else {
				$storage->setBasePath($this->userPath);
			}
		}
	}

	/** @return IHttpClient */
	public function getHttpClient()
	{
		$useCurl = function_exists('curl_init');
		if ($useCurl) {
			return new CurlHttpClient();
		}
		return new SocketHttpClient();
	}
}

class FtpData {
	public $user = null;
	public $userftp = null;
	public $passftp = null;
	public $domainftp = null;
	public $pathftp = null;

	public function buildFullUsername() {
		return "{$this->userftp}@{$this->domainftp}";
	}

	/** @return array */
	public function toJson() {
		return [
			'user' => $this->user,
			'userftp' => $this->userftp,
			'passftp' => $this->passftp,
			'domainftp' => $this->domainftp,
			'pathftp' => $this->pathftp,
		];
	}
}
