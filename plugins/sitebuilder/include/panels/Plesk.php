<?php

namespace Sitebuilder\Panels;

require_once __DIR__.'/PleskNew/PleskApi.php';

use ErrorException;
use Sitebuilder\Panels\PleskNew\PleskApi;

class Plesk extends Panel {
		
	protected function getBaseUrl() {
		return 'https://'.$this->serverHost.':8443';
	}
	
	protected function callApiOld() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/login_up.php3');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->tmpFile);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->tmpFile);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "login_name={$this->username}&passwd={$this->password}&locale_id=default&send=");
		$r = curl_exec($ch);
		if (strpos($r, 'name="login_name"') !== false && strpos($r, 'name="passwd"') !== false) {
			throw new ErrorException('Username or password of Plesk panel user is incorrect');
		}
		
		curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl().'/modules/siteprobuilder/?dom_id='.$this->domain.'&return_url=1');
		$r = curl_exec($ch);
		
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($status == 404) {
			throw new ErrorException('Website Builder extension is not installed on Plesk panel');
		}
		curl_close($ch);
		return $r;
	}
	
	private function getApiHost() {
		return $this->serverHost ? $this->serverHost : $this->serverIp;
	}
	
	private function retrieveUsernameByDomain($domain, PleskApi $api) {
		$req = <<<REQ
<webspace>
	<get>
		<filter>
			<name>$domain</name>
		</filter>
		<dataset>
			<gen_info/>
		</dataset>
	</get>
</webspace>
REQ;
		$ownerId = null;
		$resp = $api->call($req);
		if (isset($resp->webspace->get->result) && ($res = $resp->webspace->get->result)) {
			if (isset($res->status) && (string) $res->status == 'ok') {
				if (isset($res->data->gen_info->{'owner-id'}) && $res->data->gen_info->{'owner-id'}) {
					$ownerId = $res->data->gen_info->{'owner-id'};
				}
			}
		}
		if ($ownerId) {
			$req = <<<REQ
<customer>
	<get>
		<filter>
			<id>$ownerId</id>
		</filter>
		<dataset>
			<gen_info/>
		</dataset>
	</get>
</customer>
REQ;
			$resp = $api->call($req);
			if (isset($resp->customer->get->result) && ($res = $resp->customer->get->result)) {
				if (isset($res->status) && (string) $res->status == 'ok') {
					if (isset($res->data->gen_info->login) && $res->data->gen_info->login) {
						return $res->data->gen_info->login;
					}
				}
			}
		}
		return null;
	}
	
	protected function getUrl() {
		require_once __DIR__.'/PleskNew/PleskApi.php';
		$api = new PleskApi($this->getApiHost(), $this->serverUsername, $this->serverPassword);
		$userIpBase64 = base64_encode((isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR']
				: ((isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
		$host = $this->serverHost ? $this->serverHost : null;
		if (!$host) $host = $this->serverIp;
		$serverHost = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) ? base64_encode($_SERVER['HTTP_HOST']) : null;
		$username = $this->retrieveUsernameByDomain($this->domain, $api);
		if (!$username) $username = $this->username;
		$req = <<<REQ
<server>
	<create_session>
		<login>{$username}</login>
		<data>
			<user_ip>{$userIpBase64}</user_ip>
			<source_server>{$serverHost}</source_server>
		</data>
	</create_session>
</server>
REQ;
		$resp = $api->call($req);
		if (isset($resp->server->create_session->result) && ($res = $resp->server->create_session->result)) {
			if (isset($res->status) && (string) $res->status == 'ok') {
				if (isset($res->id) && $res->id) {
					$sessId = (string) $res->id;
					$redirUrl = urlencode("/modules/siteprobuilder/?domain={$this->domain}");
					$url = "https://$host:8443/enterprise/rsession_init.php?PHPSESSID={$sessId}&PLESKSESSID={$sessId}&success_redirect_url={$redirUrl}";
					header("Location: $url");
					exit();
				} else {
					throw new ErrorException('Failed to retrieve Plesk session ID');
				}
			} else if (isset($res->status) && (string) $res->status == 'error') {
				$errcode = (isset($res->errcode) && intval($res->errcode)) ? intval($res->errcode) : null;
				$errtext = (isset($res->errtext) && (string) $res->errtext) ? (string) $res->errtext : null;
				if ($errcode == 1001) {
					throw new ErrorException("Error: The specified Plesk user \"$username\" was not found");
				}
				throw new ErrorException('Plesk API Error'.($errcode ? ' ('.$errcode.')' : '').($errtext ? ': '.$errtext : ''));
			}
		} else {
			$status = (isset($resp->system->status) && (string)$resp->system->status) ? (string)$resp->system->status
					: ((isset($resp->status) && (string)$resp->status) ? (string)$resp->status : null);
					
			$errcode = (isset($resp->system->errcode) && intval($resp->system->errcode)) ? intval($resp->system->errcode)
					: ((isset($resp->errcode) && intval($resp->errcode)) ? intval($resp->errcode) : null);
					
			$errtext = (isset($resp->system->errtext) && (string)$resp->system->errtext) ? (string)$resp->system->errtext
					: ((isset($resp->errtext) && (string)$resp->errtext) ? (string)$resp->errtext : null);
			
			if ($status == 'error' && $errcode) {
				throw new ErrorException('Plesk API System Error ('.$errcode.')'.($errtext ? ': '.$errtext : ''));
			}
			throw new ErrorException('Plesk API &lt;create_session&gt; call failed');
		}
	}
}
