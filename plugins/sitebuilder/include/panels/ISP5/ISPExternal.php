<?php

namespace Sitebuilder\Panels\ISP5;

require_once __DIR__.'/ISPBase.php';

use ErrorException;

class ISPExternal extends ISPBase
{
    protected static $ispApiUrl = null;
    protected static $ispApiUser = null;
    protected static $ispApiPass = null;
    protected static $ispUsername = null;
    protected static $customFtpClass = null;

    public function __construct($ispApiUrl, $ispApiUser, $ispApiPass, $apiUrl, $apiUser, $apiPass, $username = null, $licenseHash = null, $panel = '_external_', $serverIp = null, $domain = null, $affiliateId = null, $userId = null, $hostingPlan = null, $lang = null, $customFtpClass = null, $pluginVersion = '')
    {
		self::$ispApiUser = $ispApiUser;
        self::$ispApiPass = $ispApiPass;
        self::$ispApiUrl = $ispApiUrl;
        self::$ispUsername = $username;
		self::$customFtpClass = $customFtpClass;
        return parent::__construct($apiUrl, $apiUser, $apiPass, $username, $licenseHash, $panel, $serverIp, $domain, $affiliateId, $userId, $hostingPlan, $lang, $pluginVersion);
    }

    public static function getIspApiUrl()
    {
        return self::$ispApiUrl;
    }
    public static function getIspApiUser()
    {
        return self::$ispApiUser;
    }
    public static function getIspApiPass()
    {
        return self::$ispApiPass;
    }
    public static function getIspUsername()
    {
        return self::$ispUsername;
    }

	/** @return FTP */
	private static function getFtpClass() {
		return self::$customFtpClass ? self::$customFtpClass : FTP::class;
	}

    public function getFtpData()
    {
		$ftpClass = self::getFtpClass();
        $domain = $this->domain;
        $owner = $ftpClass::getOwner();
        $homeDir = Data::getDomainDocroot($owner, $domain);
        return $ftpClass::getAccount($domain, $homeDir, $owner, 'w');
    }

    public function getProductName()
    {
       return $this->productName;
    }

    public function getAddonNames()
    {
        return $this->addonNames;
    }

    public function setDomain($domain)
    {
        $this->domain = $domain;
    }

    public function setPanel($panel)
    {
        $this->panel = $panel;
    }

    public function setProductName($productName)
    {
        $this->productName = $productName;
    }

    public function setAddonNames($addonNames)
    {
        $this->addonNames = $addonNames;
    }

    public function setLicenseHash($licenseHash)
    {
        $this->licenseHash = $licenseHash;
    }

    public function setCreateFrom($createFrom)
    {
        $this->createFrom = $createFrom;
    }
}

class API
{
    public static function call($function, array $params = array(), $envParams = array())
    {
        $curl = curl_init();

        $command = ISPExternal::getIspApiUrl() . "?authinfo=" . urlencode(ISPExternal::getIspApiUser() . ":" . ISPExternal::getIspApiPass()) . "&out=xml&func=$function";

        foreach ($params as $key => $val) {
            $command .= '&' . $key . '=' . $val;
        }
        
        foreach ($envParams as $key => $val) {
            $command .= '&' . $key . '=' . $val;
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $command,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $result = curl_exec($curl);

        if($result === false) {
            throw new ErrorException(curl_error($curl));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            throw new ErrorException('Error http code: ' . $$httpCode);
        }

        curl_close($curl);

        return simplexml_load_string($result);
    }
}

class Data {
    private static $domainData = null;

    public static function getDataFile()
    {
        return __DIR__ .'/ftp.dat';
    }

    public static function getDomainDocroot($owner, $domain)
    {
        $name = 'docroot';

        if (!self::$domainData || !is_array(self::$domainData)) {
            $result = API::call('webdomain', array(
                'su' => $owner,
                'elid' => $domain
            ));

            $data = array();
            foreach ($result->elem as $elem) {
                if ((string) $elem->name == $domain || (($attrs = $elem->name->attributes()) && isset($attrs['orig']) && $attrs['orig'] == $domain)) {
                    $ipaddr = (string) $elem->ipaddr;
                    $ipaddrs = explode(',', $ipaddr);
                    foreach ($ipaddrs as $ip) {
                        $ip = trim($ip);
                        if (preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $ip)) {
                            $data['ipaddr'] = $ip;
                            break;
                        }
                    }
                    $data['owner'] = (string) $elem->owner;
                    $data['docroot'] = (string) $elem->docroot;
                    break;
                }
            }
            self::$domainData = $data;
        }

        return $name ? (isset(self::$domainData[$name]) ? self::$domainData[$name] : null) : self::$domainData;
    }
}

class FTP extends FTPBase
{
    static function getOwner()
    {
        return ISPExternal::getIspUsername();
    }

    static function apiCall($function, $params = array(), $envParams = array())
    {
        return API::call($function, $params, $envParams);
    }

    public static function getDataFile()
    {
        return Data::getDataFile();
    }

}