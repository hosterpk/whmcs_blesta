<?php

namespace Sitebuilder\Panels;

use ErrorException;

class InterWorx extends Panel {
	
	protected function getBaseUrl() {
		return 'https://'.$this->serverHost.':2443';
	}
	
	protected function getUrl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/siteworx/index?action=login');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->tmpFile);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->tmpFile);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "email={$this->username}&password={$this->password}&domain={$this->domain}");
		$r = curl_exec($ch);
		$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		if (strpos($finalUrl, '/overview') === false) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, "email={$this->userEmail}&password={$this->password}&domain={$this->domain}");
			curl_exec($ch);
		}
		
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/siteworx/siteprobuilder?return_url=1');
		$r = curl_exec($ch);
		
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($status == 404) {
			throw new ErrorException('Website Builder plugin is not installed on InterWorx panel');
		}
		curl_close($ch);
		return $r;
	}
	
}