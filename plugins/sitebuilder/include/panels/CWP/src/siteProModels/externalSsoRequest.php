<?php

namespace WHMCS_CWP_SiteBuilder_Module\siteProModels;

class externalSsoRequest
{
	public $type = 'external';
	/**
	 * Client domain name. This field acts as a website identifier in builder.
	 * Make sure it is the same for one unique website.
	 * Do not pass different values for one website (for example "www" and "non-www" domain versions),
	 * otherwise the website will not be found in builder.
	 * You can also pass numeric ID for this parameter as website identifier in builder.
	 * Then we recommend to add extra parameter "baseDomain".
	 * Please see more information about it below.
	 * @var string
	 */
	public $domain;

	/**
	 * Client FTP connection username
	 * @var string
	 */
	public $username;

	/**
	 * Client FTP connection password
	 * @var string
	 */
	public $password;

	/**
	 * Client FTP public_html directory ex.: /public_html
	 * @var string
	 */
	public $uploadDir;

	/**
	 * IP address of client FTP server
	 * @var string
	 */
	public $apiUrl;

	/**
	 * Hosting plan identifier, will be used for builder feature limitations by plan
	 * @var string
	 */
	public $hostingPlan;

	/**
	 * Language 2 letter code (ex. "en", "ru", ...) to open builder in
	 * @var string
	 */
	public $lang;

	public function __construct($options = array())
	{
		foreach ($options as $key => $option) {
			$this->$key = $option;
		}
	}
}
