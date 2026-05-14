<?php

namespace WHMCS_DirectAdmin_SiteBuilder_Module;

use ErrorException;

interface DirectadminApiInterface {
	/**
	 * @param string $command
	 * @param array $args
	 * @return mixed
	 * @throws ErrorException
	 */
	public function call($command, $args = array());

	/** @return string */
	public function getPassword();
}
