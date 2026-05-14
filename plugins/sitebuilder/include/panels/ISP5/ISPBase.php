<?php

namespace Sitebuilder\Panels\ISP5;

use ErrorException;

abstract class ISPBase
{
    protected $apiUrl = '';
    protected $apiUser = '';
    protected $apiPass = '';
    protected $panel = '';
    protected $username = null;
    protected $licenseHash = null;
    protected $serverIp = null;
    protected $domain = null;
    protected $affiliateId = null;
    protected $userId = null;
    protected $hostingPlan = null;
    protected $lang = null;
    protected $productName = null;
    protected $addonNames = null;
    protected $createFrom = null;
    protected $pluginVersion = '';

    public $ftpUser = null;
    public $ftpPass = null;
    public $uploadDir = null;

    abstract public function getProductName();
    abstract public function getAddonNames();
    abstract public function getFtpData();

    public function __construct($apiUrl, $apiUser, $apiPass, $username = null, $licenseHash = null, $panel = '_base_', $serverIp = null, $domain = null, $affiliateId = null, $userId = null, $hostingPlan = null, $lang = null, $pluginVersion = '')
    {
        $this->apiUrl = $apiUrl;
        $this->apiUser = $apiUser;
        $this->apiPass = $apiPass;
        $this->username = $username;
        $this->licenseHash = $licenseHash;
        $this->panel = $panel;
        $this->serverIp = $serverIp;
        $this->domain = $domain;
        $this->affiliateId = $affiliateId;
        $this->userId = $userId;
        $this->hostingPlan = $hostingPlan;
        $this->lang = $lang;
        $this->pluginVersion = $pluginVersion;
    }

    public function openBuilder()
    {
        list($this->ftpUser, $this->ftpPass, $this->uploadDir) = $this->getFtpData();

        $params = array(
            'type' => 'external',
            'domain' => $this->domain,
            'username' => $this->ftpUser,
            'password' => $this->ftpPass,
            'uploadDir' => $this->uploadDir,
            'apiUrl' => $this->serverIp,
            'serverIp' => $this->serverIp,
            'affiliateId' => $this->affiliateId,
            'hostingPlan' => $this->hostingPlan,
            'panel' => $this->panel,
            'userId' => $this->userId,
            'licenseHash' => $this->licenseHash,
            'lang' => $this->lang,
            'productName' => $this->getProductName(),
            'addonNames' => $this->getAddonNames(),
            'clientId' => $this->username,
            'pluginVersion' => $this->pluginVersion
        );

        $usr = $this->remoteCall('requestLogin', $params);

        if (is_object($usr) && isset($usr->url) && $usr->url) {
            return array('url' => $usr->url);
        } elseif (is_object($usr) && isset($usr->error) && $usr->error) {
            return array('error' => $usr->error->message);
        } else {
            return array('error' => 'Error: server error');
        }
    }

    private function remoteCall($method, $params, $timeout = 300, $_redirected = 0, $_url = null, $connTimeout = null)
    {
        if ($_url) {
            $url = $_url;
        } else {
            $_apiUrl = $this->apiUrl;
            if (!is_array($_apiUrl)) {
                $_apiUrl = array($_apiUrl);
            }
            $apiUrl = $_apiUrl[0];
            if (isset($_apiUrl[1])) {
                $host = $_apiUrl[1];
            } else {
                $host = null;
            }
            $url = $apiUrl.$method;
        }
        $header = array(
            'Connection: Close',
            'Content-Type: application/json'
        );
        if (isset($host) && $host) {
            $header[] = 'Host: '.$host;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Site API Client/1.0.1 (PHP '.phpversion().')');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        if ($connTimeout) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connTimeout);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiUser.':'.$this->apiPass);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        //		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $r = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $respHeaders = array();
        curl_close($ch);

        $curlDetails = (object) array(
            'url' => $url,
            'redirects' => null,
            'responseCode' => $status,
            'responseHeaders' => array(),
            'responseBody' => "",
            'errno' => $errNo,
            'error' => $errMsg,
            'params' => $params
        );

        do {
            $continue = false;
            $resp = explode("\r\n\r\n", $r, 2);
            if (count($resp) > 1) {
                $headersRaw = explode("\r\n", $resp[0]);
                foreach ($headersRaw as $h) {
                    if ($h == "HTTP/1.1 100 Continue") {
                        $continue = true;
                    }
                    $keyVal = explode(':', $h, 2);
                    if (count($keyVal) > 1) {
                        $respHeaders[trim($keyVal[0])] = trim($keyVal[1]);
                    }
                }
                $r = $resp[1];
            }
        } while ($continue);
        $curlDetails->responseHeaders = $respHeaders;
        $curlDetails->responseBody = $r;

        if (!$_redirected) {
            $redirects = array();
        }
        if ($_redirected < 3 && isset($respHeaders['Location'])) {
            $redirects[] = $respHeaders['Location'];
            return $this->remoteCall($method, $params, $timeout, ($_redirected + 1), $respHeaders['Location'], $connTimeout);
        }
        $curlDetails->redirects = $redirects;

        if ($errNo != CURLE_OK) {
            throw new \ErrorException('cURL request failed with error ('.$errNo.')'.($errMsg ? ': '.$errMsg : ''));
        } elseif ($status != 200) {
            $res = json_decode($r);
            if (!$res) {
                $res = null;
                throw new \ErrorException('Request failed with status ('.$status.')');
            }
        } else {
            $res = json_decode($r);
        }

        return $res;
    }

}

abstract class FTPBase 
{
    /** @return string */
    public static function getOwner() { return ''; }
    /** @return \SimpleXMLElement|false */
    public static function apiCall($function, $params = array(), $envParams = array()) { return false; }
    /** @return string */
    public static function getDataFile() { return ''; }

    public static function createAccount($ftpUser, $ftpPass, $homeDir, $owner = null)
    {
        $user = static::getOwner();
        if (!$owner) {
            $owner = $user;
        }
        $result = static::apiCall('ftp.user.edit', array(
            'su' => static::getOwner(),
            'sok' => 'yes',
            'name' => $ftpUser,
            'home' => $homeDir,
            'owner' => $owner
        ), array(
            'passwd' => $ftpPass,
            'confirm' => $ftpPass,
        ));

        if (isset($result->ok)) {
            return true;
        } else {
            throw new ErrorException("Error: couldn't create FTP account.".(isset($result->error->msg) ? '<br /><br />'.((string) $result->error->msg) : ''));
        }
    }

    public static function deleteAccount($ftpUser)
    {
        $result = static::apiCall('ftp.user.delete', array('su' => static::getOwner(), 'elid' => $ftpUser));
        return (isset($result->ok) && $result->ok);
    }

    public static function hasAccount($ftpUser)
    {
        $owner = static::getOwner();
        $list = static::getAccountList();
        $matches = array(
            $ftpUser,
            $owner.'_'.$ftpUser,
            $ftpUser.'_'.$owner
        );

        foreach ($list as $li) {
            foreach ($matches as $m) {
                if ($m == $li) {
                    return $li;
                }
            }
        }
        return false;
    }

    public static function getAccountList()
    {
        $list = array();
        $result = static::apiCall('ftp.user', array('su' => static::getOwner()));
        foreach ($result->elem as $ftp) {
            $list[] = (string) $ftp->name;
        }
        return $list;
    }

    public static function getAccount($domain, $homeDir, $owner, $prefix = '')
    {
        $ftpUser = 's'.substr(sprintf("%08x", crc32($domain)), 1) . $prefix;
        if (($data = static::storage('get', $owner, $domain))) {
            list($storedFtpUser, $storedFtpPass) = explode(':', $data, 2);
        } else {
            $storedFtpUser = $storedFtpPass = null;
        }
        if (!$storedFtpUser || $storedFtpUser != $ftpUser) {
            if (($_ftpUser = static::hasAccount($storedFtpUser))) {
                static::deleteAccount($_ftpUser);
            }
            if (($_ftpUser = static::hasAccount($ftpUser))) {
                static::deleteAccount($_ftpUser);
            }
            static::storage('delete', $owner, $domain);
            $storedFtpUser = $storedFtpPass = null;
        }
        if (!($_ftpUser = static::hasAccount($ftpUser))) {
            $ftpPass = static::generatePassword(12);
            static::createAccount($ftpUser, $ftpPass, $homeDir, $owner);
            static::storage('add', $owner, $domain, $ftpUser, $ftpPass);
            $storedFtpUser = $ftpUser;
            $storedFtpPass = $ftpPass;
        }
        if (!($_ftpUser = static::hasAccount($ftpUser))) {
            return [null, null, null];
        } else {
            $storedFtpUser = $_ftpUser;
        }
        if ($storedFtpUser && $storedFtpPass) {
            return [$storedFtpUser, $storedFtpPass, '/'];
        } else {
            return [null, null, null];
        }
    }

    private static function generatePassword($length = 9)
    {
        $sets = array('abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '0123456789', '!@#%^*{}?<>[]');
        $password = '';
        foreach ($sets as $set) {
            $password .= $set[array_rand(str_split($set))];
        }
        $all = str_split(implode('', $sets));
        for ($i = 0, $c = ($length - count($sets)); $i < $c; $i++) {
            $password .= $all[array_rand($all)];
        }

        return str_shuffle($password);
    }

    static function storage($action, $owner, $domain, $ftpUser = null, $ftpPass = null)
    {
        $data_file = static::getDataFile();

        switch ($action) {
            case 'add':
                if (!$ftpUser || !$ftpPass) {
                    return;
                }
                $data = is_file($data_file) ? json_decode(file_get_contents($data_file), true) : array();
                if (!$data) {
                    $data = array();
                }
                if (!isset($data[$owner])) {
                    $data[$owner] = array();
                }
                $data[$owner][$domain] = $ftpUser.':'.$ftpPass;
                if (is_file($data_file)) {
                    unlink($data_file);
                }
                file_put_contents($data_file, json_encode($data));
                break;
            case 'get':
                if (!is_file($data_file)) {
                    return null;
                }
                $data = json_decode(file_get_contents($data_file), true);
                if ($data && is_array($data) && isset($data[$owner][$domain])) {
                    return $data[$owner][$domain];
                } else {
                    return null;
                }
                break;
            case 'delete':
                $data = json_decode(file_get_contents($data_file), true);
                if ($data && is_array($data)) {
                    unset($data[$owner][$domain]);
                    if (is_file($data_file)) {
                        unlink($data_file);
                    }
                    file_put_contents($data_file, json_encode($data));
                }
                break;
        }
    }
}
