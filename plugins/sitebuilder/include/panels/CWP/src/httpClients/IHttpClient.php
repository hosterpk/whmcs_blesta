<?php

namespace WHMCS_CWP_SiteBuilder_Module\httpClients;

interface IHttpClient {
	
	/**
	 * @param Request $request
	 */
	public function post($request);
	
}
