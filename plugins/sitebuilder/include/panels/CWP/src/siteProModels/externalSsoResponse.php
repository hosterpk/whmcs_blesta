<?php

namespace WHMCS_CWP_SiteBuilder_Module\siteProModels;

class externalSsoResponse
{
	/**
	 * Url to redirect client to (only on success)
	 * @var string
	 */
	public $url;

	/**
	 * Hash that may be used with website controlling API calls (only on success when request property more is set to true)
	 * @var string
	 */
	public $loginHash;

	/**
	 * Base API url that should be used for website controlling API calls (only on success when request property more is set to true)
	 * @var string
	 */
	public $builderApiUrl;
	/**
	 * Error description object (only on error)
	 * @var object
	 */
	public $error;

	public function __construct($options = array())
	{
		foreach ($options as $key => $option) {
			$this->$key = $option;
		}
	}
}
