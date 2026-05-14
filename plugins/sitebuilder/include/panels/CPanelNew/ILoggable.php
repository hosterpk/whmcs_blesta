<?php

namespace WHMCS_cPanel_SiteBuilder_Module;

interface ILoggable {
	
	public function isLogEnabled();
	
	public function log($msg, $title = null);
	
}
