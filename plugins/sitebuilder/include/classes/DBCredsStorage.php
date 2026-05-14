<?php

namespace Sitebuilder\Classes;

class DBCredsStorage {
	const TBL = 'creds_storage';

	/** @var DB */
	private $db;

	public function __construct($db) {
		$this->db = $db;
	}

	public function store($key, $host, $ftpUser, $ftpPass) {
		$this->delete($key);
		$this->db->Record()
			->insert(
				$this->db->table(self::TBL),
				[
					'key' => $key,
					'host' => $host,
					'username' => $ftpUser,
					'password' => SecureUtil::encryptData($ftpPass),
				]
			);
	}

	public function delete($key) {
		$this->db->Record()
			->from($this->db->table(self::TBL))
			->where('key', '=', $key)
			->delete();
	}

	public function get($key) {
		$res = $this->db->Record()
			->select()
			->from($this->db->table(self::TBL))
			->where('key', '=', $key);
		$row = $res->fetch(\PDO::FETCH_ASSOC);
		if ($row) $row['password'] = SecureUtil::decryptData($row['password']);
		return $row;
	}

	public function onInstall() {
		$this->db->Record()
			->setField("key", array('type' => "varchar", 'size' => 255))
			->setField("host", array('type' => "varchar", 'size' => 255))
			->setField("username", array('type' => "varchar", 'size' => 255))
			->setField("password", array('type' => "varchar", 'size' => 255))
			->setKey(array("key"), "primary")
			->create($this->db->table(DBCredsStorage::TBL));
	}

	public function onUninstall() {
		$this->db->Record()
			->drop($this->db->table(DBCredsStorage::TBL));
	}
}
