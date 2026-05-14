<?php

class Modules_Siteprobuilder_Functions {

	public static function repair_ip_address(&$ipAddress) {
		if (preg_match('#^\D+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$#', $ipAddress, $m)) {
			$ipAddress = $m[1];
		}
	}
	
	public static function check_result_status($resultXML, $operation = null) {
		if (!$resultXML) {
			die("Empty result");
		} else if ($resultXML->status != 'ok') {
			die("Error {$resultXML->errcode} ".($operation ? $operation : '').": {$resultXML->errtext}");
		}
	}
	
	public static function get_server_addr() {
		return isset($_SERVER['SERVER_ADDR']) ?
			$_SERVER['SERVER_ADDR'] : (
				isset($_SERVER['LOCAL_ADDR']) ?
				$_SERVER['LOCAL_ADDR'] :
				gethostbyname(
					isset($_SERVER['HTTP_HOST']) ?
					$_SERVER['HTTP_HOST'] :
					$_SERVER['SERVER_NAME']
				)
			);
	}
	
}