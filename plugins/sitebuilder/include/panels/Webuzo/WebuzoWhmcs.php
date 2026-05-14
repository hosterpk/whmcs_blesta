<?php

namespace Whmcs_Webuzo_Module;

class WebuzoWhmcs extends WebuzoApi {

    protected $webuzoApiUrl; // https://hostname.com:2003/index.php?api=json
    protected $webuzoApiUser;
    protected $webuzoApiPassword;
    protected $webuzoApiKey;

    const DEFAULT_LICENSE_HASH = 'BLGZgymWF3XFv5nOh6D3kuoOgU1q95XgTJch1240G0';

    public function __construct(string $webuzoApiUrl, string $webuzoApiUser, string $webuzoApiPassword, string $webuzoApiKey, string $webuzoUser, string $url, string $username, string $password, string $serverIp, string  $lang = 'en')
    {
        parent::__construct('', $webuzoUser, $url, $username, $password, $serverIp, $lang);
        $this->webuzoApiUrl = $webuzoApiUrl;
        $this->webuzoApiUser = $webuzoApiUser;
        $this->webuzoApiPassword = $webuzoApiPassword;
        $this->webuzoApiKey = $webuzoApiKey;
    }

    public function checkFtp($username)
    {
        $post = [
            'apiuser' => $this->webuzoApiUser, 
            'apikey' => $this->webuzoApiKey
        ];

        $apiUrl = str_replace('2005', '2003', $this->webuzoApiUrl);

        $url = $apiUrl . '&act=ftp&loginAs=' . rawurlencode($this->webuzoUser);

        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

        if(!empty($post)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $resp = curl_exec($ch);

        curl_close($ch);

        $response = json_decode($resp, true);
        if (empty($response) || empty($response['ftp_list']) || empty($response['ftp_list'][$username])) {
            return false;
        }
   
        return $response['ftp_list'][$username];
    }

    public function addFtp(string $username, string $password)
    {
        $post = [
            'apiuser' => $this->webuzoApiUser, 
            'apikey' => $this->webuzoApiKey,
            'create_acc' => 1,
            'login' => $username,
            'newpass' => $password,
            'conf' => $password,
            'ftpdomain' => $this->domain,
            'quota' => 'unlimited'
        ];

        $apiUrl = str_replace('2005', '2003', $this->webuzoApiUrl);

        $url = $apiUrl . '&act=ftp_account&loginAs=' . rawurlencode($this->webuzoUser);

        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

        if(!empty($post)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $resp = curl_exec($ch);

        curl_close($ch);

        $response = json_decode($resp, true);
        if (empty($response) || empty($response['done']) || empty($response['done']['msg'])) {
            return false;
        }

        return true;
    }

    public function getUserInfo(string $username)
    {
        $apiUrl = str_replace('2005', '2003', $this->webuzoApiUrl);

        $url = $apiUrl . '&act=users&apiuser=' . rawurlencode($this->webuzoUser) . '&apikey=' . $this->webuzoApiKey;

        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

        $resp = curl_exec($ch);

        curl_close($ch);

        $response = json_decode($resp, true);
        if (empty($response) || empty($response['users']) || empty($response['users'][$username])) {
            return false;
        }
   
        return $response['users'][$username];
    }

    public function getDomains()
    {
        $apiUrl = parse_url($this->webuzoApiUrl);

        $url = "https://" . rawurlencode($this->webuzoApiUser) . ":" . rawurlencode($this->webuzoApiPassword) . "@" . $apiUrl['host'] . ":" . $apiUrl['port']  . "/index.php?api=json&act=domains&user_search=" . rawurlencode($this->webuzoUser);

        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 

        $resp = curl_exec($ch);

        curl_close($ch);

        $response = json_decode($resp, true);
        if (empty($response) || empty($response['domains'])) {
            return false;
        }

        return $response['domains'];
    }

    public function getFtpPath() 
    {
        return __DIR__ .'/ftp.dat';
    }
}