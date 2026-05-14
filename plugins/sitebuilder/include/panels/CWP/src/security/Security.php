<?php

namespace WHMCS_CWP_SiteBuilder_Module\security;

class Security
{
	/**
	 * Generate strong password
	 * @param int $length password length
	 * @return string
	 */
	public static function generatePassword($length = 9) {
		$sets = array('abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '0123456789', './');
		$password = '';
		foreach ($sets as $set) {
			$password .= $set[array_rand(str_split($set))];
		}
		$all = str_split(implode('', $sets));
		for ($i = 0, $c = ($length - count($sets)); $i < $c; $i++) { $password .= $all[array_rand($all)]; }

		return str_shuffle($password);
	}
}