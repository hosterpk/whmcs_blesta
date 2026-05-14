<?php

namespace WHMCS_CWP_SiteBuilder_Module\storages;

use WHMCS_CWP_SiteBuilder_Module\FtpData;

interface IFtpStorage {
	/** @return FtpData|null */
	public function getFtp();
	public function setFtp(FtpData $value);
	public function deleteFtp();
}
