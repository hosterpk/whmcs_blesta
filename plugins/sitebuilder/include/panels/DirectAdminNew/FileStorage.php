<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

require_once __DIR__.'/IFtpStorage.php';

class FileStorage implements IFtpStorage {
	private $username;
	private $domain;
	private $path;
	private $passPattern;

	public function __construct($username, $domain) {
		$this->username = $username;
		$this->domain = $domain;
		$this->path = __DIR__ .'/.spbldr_localStorage';
	}

	public function get() {
		$data = $this->readDataByKey($this->buildKey());
		$parts = $data ? explode(':', $data, 2) : null;
		if (is_array($parts) && count($parts) == 2
				&& ($pass = SecureUtil::decryptData($parts[1]))
				&& $this->isPasswordCorrect($pass)) {
			return FtpData::from($parts[0], $pass);
		}
		return null;
	}

	public function setPasswordPattern($pattern) {
		$this->passPattern = $pattern;
	}

	private function isPasswordCorrect($pass) {
		return $this->passPattern ? !!preg_match($this->passPattern, $pass) : true;
	}

	public function set(FtpData $saveData) {
		$data = $this->readData();
		$data[$this->buildKey()] = $saveData->username.':'.SecureUtil::encryptData($saveData->password);
		$this->writeData($data);
	}

	/** @return array|null */
	private function readData() {
		$file = $this->getFilePath();
		if (!is_file($file)) return null;
		$data = file_get_contents($this->getFilePath());
		$data = $data ? json_decode($data, true) : null;
		return $data;
	}

	private function writeData($data) {
		file_put_contents($this->getFilePath(), json_encode($data));
	}

	/** @return string|null */
	private function readDataByKey($key) {
		$data = $this->readData();
		return ($data && isset($data[$key])) ? $data[$key] : null;
	}

	private function getFilePath() {
		return $this->path;
	}

	public function setFilePath($path) {
		$this->path = $path;
	}

	private function buildKey() {
		return "{$this->username}_{$this->domain}";
	}
}

if (!class_exists('\WHMCS_DirectAdmin_SiteBuilder_Module\SecureUtil')) {
	class SecureUtil {
		const DEFAULT_PASS = '%kVoseGG3rpIaWiDg%yyIe*xLA95ZKRFhF9[IQ4i';
		const CYPHER_METHOD = 'aes-256-cbc';
		const IV = 'wnFtpnKarHwrNjvv';

		/**
		 * @param string $data
		 * @return string|null
		 */
		public static function encryptData($data) {
			return base64_encode(openssl_encrypt($data, self::CYPHER_METHOD, self::DEFAULT_PASS, OPENSSL_RAW_DATA, self::IV));
		}

		/**
		 * @param string $data
		 * @return string|null
		 */
		public static function decryptData($data) {
			return openssl_decrypt(base64_decode($data), self::CYPHER_METHOD, self::DEFAULT_PASS, OPENSSL_RAW_DATA, self::IV);
		}
	}
}
