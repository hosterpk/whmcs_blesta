<?php

namespace Sitebuilder\Panels;

use ErrorException;

require_once __DIR__.'/Panel.php';

class CPanel extends Panel {
	
	protected function getBaseUrl() {
		return 'https://'.$this->serverHost.':2083';
	}
	
	protected function getUrl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/login?login_only=1');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->tmpFile);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->tmpFile);
		curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "user={$this->username}&pass={$this->password}&login=");
		$r = curl_exec($ch);
		
		$res = json_decode($r, true);
		$cpsess = null; $theme = 'paper_lantern';
		if ($res && is_array($res) && !empty($res)) {
			if (isset($res['redirect']) && preg_match('#(cpsess[^\/]+)\/frontend\/([^\/]+)\/#i', (string) $res['redirect'], $m)) {
				$cpsess = $m[1];
				$theme = $m[2];
			} else if (isset($res['security_token'])) {
				$cpsess = trim($res['security_token'], '/');
			}
		}
		if (!$cpsess) {
			throw new ErrorException('Unable to log in to cPanel');
		}
		
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/'.$cpsess.'/frontend/'.$theme.'/siteprobuilder/index.live.php?return_url=1&domain='.$this->domain);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		$r = curl_exec($ch);
		
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($status == 404) {
			throw new ErrorException('Website Builder plugin is not installed on cPanel');
		}
		curl_close($ch);
		return $r;
	}
	
}
