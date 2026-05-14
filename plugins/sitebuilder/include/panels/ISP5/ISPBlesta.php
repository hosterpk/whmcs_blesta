<?php

namespace Sitebuilder\Panels\ISP5;

require_once __DIR__.'/ISPExternal.php';

use Sitebuilder\Classes\DB;
use Sitebuilder\Panels\ISP5\ISPExternal;

class ISPBlesta extends ISPExternal {
	public function __construct($ispApiUrl, $ispApiUser, $ispApiPass, $apiUrl, $apiUser, $apiPass, $username = null, $licenseHash = null, $panel = 'Blesta', $serverIp = null, $domain = null, $affiliateId = null, $userId = null, $hostingPlan = null, $lang = null, $pluginVersion = '')
    {
        return parent::__construct($ispApiUrl, $ispApiUser, $ispApiPass, $apiUrl, $apiUser, $apiPass, $username, $licenseHash, $panel, $serverIp, $domain, $affiliateId, $userId, $hostingPlan, $lang, FTPBlesta::class, $pluginVersion);
    }
}

class FTPBlesta extends FTP {
	public static function storage($action, $owner, $domain, $ftpUser = null, $ftpPass = null)
    {
        $key = "{$owner}_{$domain}";
        switch ($action) {
            case 'add':
                if (!$ftpUser || !$ftpPass) {
                    return;
                }
                DB::getInstance()->credsStorage->store($key, $domain, $ftpUser, $ftpPass);
                break;
            case 'get':
                $data = DB::getInstance()->credsStorage->get($key);
                return $data ? ($data['username'].':'.$data['password']) : null;
            case 'delete':
                DB::getInstance()->credsStorage->delete($key);
                break;
        }
    }
}
