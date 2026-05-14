<?php

namespace Sitebuilder\Panels\DirectAdminNew;

use Sitebuilder\Classes\DB;
use WHMCS_DirectAdmin_SiteBuilder_Module\FtpData;
use WHMCS_DirectAdmin_SiteBuilder_Module\IFtpStorage;

require_once __DIR__.'/IFtpStorage.php';

class DBFtpStorage implements IFtpStorage {
	private $username;
	private $domain;

	public function __construct($username, $domain) {
		$this->username = $username;
		$this->domain = $domain;
	}

	public function get() {
		$row = DB::getInstance()->credsStorage->get($this->buildKey());
		if ($row) {
			return FtpData::from($row['username'], $row['password']);
		}
		return null;
	}
	
	public function set(FtpData $data) {
		DB::getInstance()->credsStorage->store($this->buildKey(), '', $data->username, $data->password);
	}

	private function buildKey() {
		return "{$this->username}_{$this->domain}";
	}
}
