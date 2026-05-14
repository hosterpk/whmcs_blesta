<?php

namespace Sitebuilder\Classes;

class HostingData {
	/** @var string */
	public $domain;
	/** @var string */
	public $username;
	/** @var string */
	public $password;

	public function __construct($domain = null, $user = null, $pass = null) {
		$this->domain = $domain;
		$this->username = $user;
		$this->password = $pass;
	}
}
