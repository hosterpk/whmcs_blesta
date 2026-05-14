<?php

namespace Whmcs_Webuzo_Module;

class WebuzoSelf extends WebuzoApi {

    public function __construct(string $domain, string $webuzoUser, string $url, string $username, string $password, string $serverIp, string  $lang = 'en')
    {
        parent::__construct($domain, $webuzoUser, $url, $username, $password, $serverIp, $lang);
    }

    public function checkFtp($username)
    {
        exec('webuzo --enduser-api --act=ftp --loginAs=' . $this->webuzoUser, $data);
        if (empty($data)) {
            return false;
        }

        $response = json_decode(implode("\r\n", $data), true);
        if(empty($response['ftp_list']) || empty($response['ftp_list'][$username])) {
            return false;
        }
 
        return $response['ftp_list'][$username];
    }

    public function addFtp(string $username, string $password)
    {
        $command = 'webuzo --enduser-api --act=ftp_account --loginAs=' . $this->webuzoUser
            . ' --create_acc=1'
            . ' --login=' . $username
            . ' --newpass=' . $password
            . ' --conf=' . $password
            . ' --ftpdomain=' . $this->domain
            . ' --quota=' . 'unlimited';

        exec($command, $data);
        if (empty($data)) {
            return false;
        }

        $response = json_decode(implode("\r\n", $data), true);
        if(empty($response['done']) || empty($response['done']['msg'])) {
            return false;
        }

        return true;
    }

    public function getUserInfo(string $username)
    {
        exec('webuzo --api --act=users', $data);
        if (empty($data)) {
            return false;
        }

        $response = json_decode(implode("\r\n", $data), true);
        if(empty($response['users']) || empty($response['users'][$username])) {
            return false;
        }
 
        return $response['users'][$username];
    }

    public function getFtpPath() 
    {
        global $W;

        $filepath = __DIR__ .'/ftp.dat';
        if (!file_exists($filepath)) {
            $W->wexec('touch ' . $filepath);
            $W->wexec('chmod 777 ' . $filepath);
        }

        return $filepath;
    }
}