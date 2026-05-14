<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

interface IFtpStorage {
	/** @return FtpData|null */
	public function get();

	public function set(FtpData $data);
}
