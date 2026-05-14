<?php

namespace WHMCS_CWP_SiteBuilder_Module\httpClients;

use ErrorException;

require_once __DIR__.'/IHttpClient.php';

class SocketHttpClient implements IHttpClient
{
	/**
	 * @param $request Request
	 */
	public function post($request)
	{
		$fullUrl = $request->getFullUrl();
		$uinf = parse_url($fullUrl);
		$request_host = $uinf['host'];
		$request_uri = $uinf['path'];
		$request_uri .= (isset($uinf['query']) && $uinf['query']) ? ('?'.$uinf['query']) : '';

		$errno = $errstr = null;
		$fp = fsockopen($request_host, (isset($uinf['port']) ? $uinf['port'] : 80), $errno, $errstr, 30);
		if (!$fp) {
			if ($errno === 0 && (gethostbyname($request_host) == $request_host)) {
				throw new ErrorException("Error: domain '$request_host' is not resolved.");
			}
			throw new ErrorException("$errstr ($errno)");
		} else {
			$post = $request->getBody();
			$content_length = mb_strlen($post, '8bit');

			$headers = "POST $request_uri HTTP/1.1\r\n";
			$headers .= "Host: $request_host\r\n";
			$headers .= "Connection: Close\r\n";
			$headers .= "Accept: text/html,application/json\r\n";
			$headers .= "User-Agent: Site.pro Builder plugin\r\n";
			if ($request->auth) {
				$headers .= "Authorization: Basic ".base64_encode(implode(':', $request->auth))."\r\n";
			}
			$headers .= "Content-Type: application/json\r\n";
			$headers .= "Content-Length: $content_length\r\n";
			$headers .= "\r\n";
			fwrite($fp, $headers);
			if ($post) fwrite($fp, $post);
			$response_ = '';
			while (!feof($fp)) {
				$response_ .= fgets($fp);
			}
			fclose($fp);

			$response = explode("\r\n\r\n", $response_, 2);
			$result = array();
			$result['header']	= $response[0];
			$result['body']		= isset($response[1]) ? $response[1] : '';

			if (preg_match('#(?:\r\nTransfer-Encoding: chunked\r\n)#ism', $result['header'])) {
				$body = $result['body'];
				$result['body'] = '';
				$m = null;
				while (preg_match('#(?:\r\n|^)[0-9a-f]+\r\n#ism', $body, $m, PREG_OFFSET_CAPTURE)) {
					$size = intval(trim($m[0][0]), 16);
					if ($size) {
						$result['body'] .= substr($body, $m[0][1] + strlen($m[0][0]), $size);
					}
					$body = substr($body, $m[0][1] + strlen($m[0][0]) + $size);
				}
			}

			$http_code = preg_match('#^HTTP/1\.[0-9]+\ ([0-9]+)\ [^\ ]+.*#i', $result['header'], $m) ? $m[1] : 404;
			if ($http_code != 200) {
				$res = json_decode($result['body']);
				if (!$res) {
					$res = null;
					throw new ErrorException('Response Code ('.$http_code.')');
				}
			} else {
				$res = json_decode($result['body']);
			}
			return $res;
		}
		return null;
	}
}
