<?php

namespace Sitebuilder\Classes;

class ServerData {
	/** @var string */
	public $host;
	/** @var string|null */
	public $ip = null;
	/** @var int|null */
	public $port = null;
	/** @var string */
	public $username;
	/** @var string */
	public $password;
	/** @var string */
	public $accesshash;
	/** @var bool */
	public $secure;

	public function __construct($host = null, $ip = null, $port = null, $secure = false, $user = null, $pass = null, $key = null) {
		$this->host = $host;
		$this->ip = $ip;
		$this->port = $port;
		$this->secure = !!$secure;
		$this->username = $user;
		$this->password = $pass;
		$this->accesshash = $key;
	}
	
	/** @return string */
	public function resolveHost() {
		return $this->ip ?: $this->host;
	}
}
