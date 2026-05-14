<?php

namespace WHMCS_CWP_SiteBuilder_Module\storages;

use WHMCS_CWP_SiteBuilder_Module\FtpData;

require_once __DIR__.'/IFtpStorage.php';

class FileStorage implements IFtpStorage
{
	private $basePath;
	private $baseKey = 'data';

	public function __construct($basePath) {
		$this->basePath = $basePath;
	}

	public function get($key)
	{
		$filename = $this->getFileName($key);
		if (@file_exists($filename)) {
			$value = @file_get_contents(rtrim($this->basePath, '/') . '/.' . $this->baseKey . '_' . $key);
			if ($value) {
				return json_decode($value, true);
			}
		}
		return null;
	}

	public function set($key, $value)
	{
		$filename = $this->getFileName($key);
		if (!is_dir(dirname($filename))) {
			@mkdir(dirname($filename));
		}
		file_put_contents($filename, json_encode($value));
	}

	public function delete($key)
	{
		$file = $this->getFileName($key);
		if (is_file($file)) {
			unlink($file);
		}
	}

	public function setBasePath($path) {
		$this->basePath = $path;
	}

	private function getFileName($key)
	{
		return rtrim($this->basePath, '/') . '/.' . $this->baseKey . '_' . $key;
	}

	public function getFtp() {
		$ftp = $this->get('ftp');
		if ($ftp) {
			$data = new FtpData();
			$data->user = $ftp['user'];
			$data->domainftp = $ftp['domainftp'];
			$data->userftp = $ftp['userftp'];
			$data->passftp = $ftp['passftp'];
			$data->pathftp = $ftp['pathftp'];
			return $data;
		}
		return null;
	}

	public function setFtp(FtpData $values) {
		$this->set('ftp', $values);
	}

	public function deleteFtp() {
		$this->delete('ftp');
	}
}
