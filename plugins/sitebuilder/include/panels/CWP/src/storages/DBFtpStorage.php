<?php

namespace Sitebuilder\Panels\CWP\src\storages;

use Sitebuilder\Classes\DB;
use WHMCS_CWP_SiteBuilder_Module\FtpData;
use WHMCS_CWP_SiteBuilder_Module\storages\IFtpStorage;

require_once __DIR__.'/IFtpStorage.php';

class DBFtpStorage implements IFtpStorage {
	private $username;
	private $domain;
	
	public function __construct($username, $domain) {
		$this->username = $username;
		$this->domain = $domain;
	}
	
	public function getFtp() {
		$row = DB::getInstance()->credsStorage->get($this->buildKey());
		if ($row) {
			$data = new FtpData();
			$data->domainftp = $row['host'];
			$data->userftp = $row['username'];
			$data->passftp = $row['password'];
			return $data;
		}
		return null;
	}

	public function setFtp(FtpData $value) {
		DB::getInstance()->credsStorage->store($this->buildKey(), $value->domainftp, $value->userftp, $value->passftp);
	}

	public function deleteFtp() {
		DB::getInstance()->credsStorage->delete($this->buildKey());
	}

	private function buildKey() {
		return "{$this->username}_{$this->domain}";
	}
}
