<?php

interface Modules_Siteprobuilder_ICustomApiHandler {
	/**
	 * Perform API-RPC call.
	 * @param string $request 
	 * @param string $login Panel username on behalf of which the operation will be performed.
	 * @return \SimpleXMLElement
	 */
	public function call($request, $login = null);
}
