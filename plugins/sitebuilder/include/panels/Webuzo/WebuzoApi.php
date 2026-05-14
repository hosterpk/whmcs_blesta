<?php

namespace Whmcs_Webuzo_Module;

abstract class WebuzoApi
{
    protected $domain;
    protected $webuzoUser;
    protected $url;
    protected $username;
    protected $password;
    protected $serverIp;
    protected $lang;

    protected $panel = 'Webuzo';
    protected $hostingPlan = null;
    protected $productName = null;
    protected $addonNames = null;
    protected $licenseHash = null;
    protected $createFrom = null;


    public const FTP_USERNAME = 'sitepro_ftp';

    public function __construct(string $domain, string $webuzoUser, string $url, string $username, string $password, string $serverIp, string $lang = 'en')
    {
        $this->domain = $domain;
        $this->webuzoUser = $webuzoUser;
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->serverIp = $serverIp;
        $this->lang = $lang;
    }

    abstract public function checkFtp($username);
    abstract public function addFtp(string $username, string $password);
    abstract public function getFtpPath();
    abstract public function getUserInfo(string $username);

    /*protected function getFtpPassword()
    {
        return md5($this->webuzoUser . $this->domain);
    }*/

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

    public function openBuilder()
    {
        try {
            $userInfo = $this->getUserInfo($this->webuzoUser);

            $clientId = isset($userInfo['id']) ? $userInfo['id'] : '';
            $clientEmail = isset($userInfo['email']) ? $userInfo['email'] : '';

            $ftpUsername = $ftpPassword = $ftpDir = '';
            $isAdded = false;

            $ftp = $this->getFtpData();
            if (!empty($ftp)) {
                $auth = explode(':', $ftp);
                if (count($auth) == 3) {
                    $ftpUsername = $auth[0];
                    $ftpPassword = $auth[1];
                    $ftpDir = $auth[2] . 'public_html/';
                }

                if (!$ftpUsername || ($ftpUsername && ($ftp = $this->checkFtp($ftpUsername . '_' . $this->domain)) === false)) {
                    list($ftpUsername, $ftpPassword) = $this->createNewFtp();
                    $isAdded = true;
                }

            } else {
                list($ftpUsername, $ftpPassword) = $this->createNewFtp();
                $isAdded = true;
            }

            if ($isAdded) {
                $ftp = $this->checkFtp($ftpUsername . '_' . $this->domain);
                if ($ftp !== false) {
                    $ftpDir = $ftp['path'] . 'public_html/';
                    $this->setFtpData($ftpUsername . ':' . $ftpPassword . ':' . $ftpDir);
                } else {
                    $ftpUsername = '';
                }
            }

            if (!$ftpUsername) {
                echo 'Error with adding ftp account.';
                exit();
            }

            $params = array(
                "type" => "external",
                "domain" => $this->domain,
                "lang" => $this->lang,
                "username" => $ftpUsername . '_' . $this->domain,
                "password" => $ftpPassword,
                "apiUrl" => $this->serverIp,
                "serverIp" => $this->serverIp,
                "uploadDir" => $ftpDir,
                "panel" => $this->panel,
                'productName' => $this->productName,
                'addonNames' => $this->addonNames,
                'licenseHash' => $this->licenseHash,
                'createFrom' => $this->createFrom,
                'clientId' => $clientId,
                'clientEmail' => $clientEmail
            );

            $usr = $this->siteproLogin($params);

            if (is_object($usr) && !empty($usr->url)) {
                return $usr->url;
            } elseif (is_object($usr) && !empty($usr->error)) {
                echo $usr->error->message;
                exit();
            } else {
                echo 'Error: server error';
                exit();
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            exit();
        }
    }

    public function createNewFtp()
    {
        $ftpUsername = substr(uniqid(rand(), true), 0, 10);
        $ftpPassword = md5(uniqid(rand(), true));
        $this->addFtp($ftpUsername, $ftpPassword);
        sleep(1);
        return [$ftpUsername, $ftpPassword];
    }

    private function siteproLogin(array $params, int $_redirected = 0)
    {
        $url = $this->url . 'requestLogin';

        $header = array(
            'Connection: Close',
            'Content-Type: application/json'
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Site API Client/1.0.1 (PHP '.phpversion().')');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username.':'.$this->password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $r = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $respHeaders = array();
        curl_close($ch);

        do {
            $continue = false;
            $resp = explode("\r\n\r\n", $r, 2);
            if (count($resp) > 1) {
                $headersRaw = explode("\r\n", $resp[0]);
                foreach ($headersRaw as $h) {
                    if($h == "HTTP/1.1 100 Continue") {
                        $continue = true;
                    }
                    $keyVal = explode(':', $h, 2);
                    if (count($keyVal) > 1) {
                        $respHeaders[trim($keyVal[0])] = trim($keyVal[1]);
                    }
                }
                $r = $resp[1];
            }
        } while($continue);

        if ($_redirected < 3 && isset($respHeaders['Location'])) {
            return $this->siteproLogin($params, $_redirected + 1);
        }

        $res = json_decode($r);
        if ($errNo != CURLE_OK) {
            throw new \Exception('cURL request failed with error ('.$errNo.')'.($errMsg ? ': '.$errMsg : ''));
        } elseif ($status != 200 && $res === null) {
            throw new \Exception('Request failed with status ('.$status.')');
        } elseif (!$res) {
            throw new \Exception('Request returned bad response:<br><code style="word-wrap: break-word;">'.htmlspecialchars($r).'</code>');
        }

        return $res;
    }

    private function getFtpData()
    {
        $filepath = $this->getFtpPath();
        $data = file_get_contents($filepath);
        return $data;
    }

    private function setFtpData(string $data)
    {
        $filepath = $this->getFtpPath();
        file_put_contents($filepath, $data);
    }
}
