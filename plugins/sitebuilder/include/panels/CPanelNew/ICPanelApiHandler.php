<?php

namespace WHMCS_cPanel_SiteBuilder_Module;

interface ICPanelApiHandler {
	public function call($module, $function, $params = array());
}
