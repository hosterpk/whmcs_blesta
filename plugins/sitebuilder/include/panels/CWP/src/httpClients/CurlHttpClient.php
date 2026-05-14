<?php

namespace WHMCS_CWP_SiteBuilder_Module\httpClients;

use ErrorException;

require_once __DIR__.'/IHttpClient.php';

class CurlHttpClient implements IHttpClient
{
	/**
	 * @param $request Request
	 */
	public function post($request)
	{
		$fullUrl = $request->getFullUrl();
		$parseUrl = parse_url($fullUrl);
		$host = $parseUrl['host'];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $fullUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request->getBody());
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array_merge($request->headers, array(
			'Connection: Close',
		));
		if (isset($host) && $host) {
			$headers[] = 'Host: ' . $host;
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);

		if ($request->auth) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, implode(':', $request->auth));
		}

		$response = curl_exec($ch);

		$errNo = curl_errno($ch);
		$errMsg = curl_error($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($errNo != CURLE_OK) {
			throw new ErrorException('cURL request failed with error (' . $errNo . ')' . ($errMsg ? ': ' . $errMsg : ''));
		} else if ($status != 200) {
			$res = json_decode($response, true);
			if (!$res) {
				$res = null;
				throw new ErrorException('Request failed with status (' . $status . ')');
			}
		} else {
			$res = json_decode($response, true);
		}

		return $res;
	}
}
