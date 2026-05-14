<?php

namespace Sitebuilder\Classes;

use stdClass;

class CredsUtil {
	const PLACEHOLDER_START = '/***START***';
	const PLACEHOLDER_END = '***END***/';
	
	private static $self = null;
	private static function getSelf() {
		if (!self::$self) {
			self::$self = new self();
		}
		return self::$self;
	}

	private static $basePath;

	/** @param string $path */
	public static function setBasePath($path) {
		self::$basePath = $path;
	}

	public static function get($domain) {
		return self::getSelf()->selfGet($domain);
	}

	public static function store($domain, $data, $append = false) {
		return self::getSelf()->selfStore($domain, $data, $append);
	}

	private function buildPath($domain) {
		$hash = sprintf("%08x", crc32($domain));
		return self::$basePath."/{$hash}_{$domain}.php";
	}

	/**
	 * @param string $domain
	 * @return stdClass|null
	 */
	private function selfGet($domain) {
		$file = $this->buildPath($domain);
		if (is_file($file)) {
			return $this->buildDataFromContent(file_get_contents($file));
		}
		return null;
	}

	/**
	 * @param string $domain
	 * @param stdClass|null $data
	 */
	private function selfStore($domain, $data, $append = false) {
		$file = $this->buildPath($domain);
		if ($data && is_object($data)) {
			if ($append) {
				$old = $this->selfGet($domain);
				if (is_object($old)) {
					foreach ($data as $k => $v) {
						$old->{$k} = $v;
					}
				}
				$data = $old;
			}
			if (!is_dir(($dir = dirname($file)))) mkdir($dir);
			file_put_contents($file, $this->buildContentFromData($data));
		} else if ($data === null && is_file($file)) {
			unlink($file);
		}
	}

	/**
	 * @param stdClass|null $data
	 * @return string
	 */
	private function buildContentFromData($data) {
		return "<?"."php "
			.self::PLACEHOLDER_START
			.base64_encode(json_encode($data))
			.self::PLACEHOLDER_END;
	}

	/**
	 * @param string $data
	 * @return stdClass|null
	 */
	private function buildDataFromContent($content) {
		$startIdx = (($start = strpos($content, self::PLACEHOLDER_START)) !== false) ? ($start + strlen(self::PLACEHOLDER_START)) : false;
		$endIdx = strpos($content, self::PLACEHOLDER_END);
		if ($startIdx !== false && $endIdx !== false) {
			$length = $endIdx - $startIdx;
			if ($length > 0 && ($dataRaw = substr($content, $startIdx, $length))
					&& ($json = base64_decode($dataRaw))
					&& (($data = json_decode($json)) !== null)) {
				return $data;
			}
		}
		return null;
	}
}
