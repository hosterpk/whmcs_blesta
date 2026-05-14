<?php

namespace Sitebuilder\Classes;

class DB {
	const PREFIX = 'sitebuilder_';
	
	private static $recordInstance;
	public static function setup($recordInstance) {
		self::$recordInstance = $recordInstance;
	}

	private static $instance = null;
	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** DBCredsStorage */
	public $credsStorage;

	private function __construct() {
		$this->credsStorage = new DBCredsStorage($this);
	}

	public function Record() {
		return self::$recordInstance;
	}

	public function table($tblName) {
		return self::PREFIX . $tblName;
	}
	
	public function onInstall() {
		$this->credsStorage->onInstall();
	}

	public function onUninstall() {
		$this->credsStorage->onUninstall();
	}
}
