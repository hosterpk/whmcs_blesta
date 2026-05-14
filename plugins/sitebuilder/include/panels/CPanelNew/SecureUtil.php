<?php

namespace WHMCS_cPanel_SiteBuilder_Module;

if (!function_exists('openssl_encrypt')) {
	function openssl_encrypt($data, $cipher_algo, $passphrase, $options = 0, $iv = '') {
		return $data;
	}
	function openssl_decrypt($data, $cipher_algo, $passphrase, $options = 0, $iv = '') {
		return $data;
	}
}

class SecureUtil {
	const DEFAULT_PASS = '%kVoseGG3rpIaWiDg%yyIe*xLA95ZKRFhF9[IQ4i';
	const CYPHER_METHOD = 'aes-256-cbc';
	const IV = 'wnFtpnKarHwrNjvv';

	public static function encryptData(string $data, string $pass = ''): string {
		return base64_encode(openssl_encrypt($data, self::CYPHER_METHOD, ($pass ?? self::DEFAULT_PASS), OPENSSL_RAW_DATA, self::IV));
	}

	public static function decryptData(string $data, string $pass = ''): string {
		return openssl_decrypt(base64_decode($data), self::CYPHER_METHOD, ($pass ?? self::DEFAULT_PASS), OPENSSL_RAW_DATA, self::IV);
	}

	public static function validateSVGImageSource($source) {
		return !preg_match('#<[A-Za-z0-9:\-]*script|onload|onerror|xlink:href\s*=#i', $source);
	}
}
