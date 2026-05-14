<?php

namespace Sitebuilder\Panels;

use ErrorException;

require_once __DIR__.'/Panel.php';

class DirectAdmin extends Panel {
	protected function getBaseUrl() {
		return ($this->serverSecure ? 'https' : 'http').'://'.$this->serverHost.':2222';
	}
	
	protected function getUrl() {
		$baseUrl = $this->getBaseUrl();
		$url = $baseUrl.'/CMD_PLUGINS/siteprobuilder/index.raw?return_url=1&iframe=yes&d='.$this->domain;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, "{$this->serverUsername}|{$this->username}:{$this->serverPassword}");
		$r = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		curl_close($ch);
		header('content-type: text/plain');
		if ($errno) {
			throw new ErrorException("Failed to connect to DirectAdmin ($errno)".($err ? ' :'.$err : ''));
		} else if ($status < 200 || $status > 200) {
			throw new ErrorException("Failed to connect to DirectAdmin with status $status");
		} else if (preg_match('#^<!doctype#i', trim($r)) || preg_match('#^<html#i', trim($r))) {
			throw new ErrorException("Failed to log in to DirectAdmin");
		}
		if (strstr($r, 'login_hash=')) {
			return $r;
		} else {
			throw new ErrorException($r);
		}
		/*if (strstr($r, 'plugin does not exist')) {
			throw new ErrorException('Website Builder plugin is not installed on DirectAdmin panel or is not active for current user');
		} else if (strstr($r, 'feature is not available')) {
			throw new ErrorException('Website Builder plugin is not enabled for current user in hosting package of DirectAdmin panel.');
		}*/
		return $r;
	}
}
