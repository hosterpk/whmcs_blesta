<?php

namespace Sitebuilder\Panels;

use ErrorException;
use Exception;
use Whmcs_Webuzo_Module\WebuzoWhmcs;

require_once __DIR__.'/Panel.php';

class Webuzo extends Panel
{
    /** @var array */
    private static $translations;
    private $apiUrl = null;
    private $apiUser = null;
    private $apiPass = null;
    private $apiKey = null;

    public function setApiUser($apiUser)
    {
        $this->apiUser = $apiUser;
    }

	public function setApiPassword($apiPass)
    {
        $this->apiPass = $apiPass;
    }

    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function process()
    {
        require_once __DIR__.'/Webuzo/WebuzoApi.php';
        require_once __DIR__.'/Webuzo/WebuzoWhmcs.php';

		$this->apiUrl = 'https://' . $this->serverHost . ':2005/index.php?api=json';

        if (!$this->apiUser) {
            throw new ErrorException('Webuzo apiUser is required in module configuration');
        }
        if (!$this->apiPass) {
            throw new ErrorException('Webuzo apiPass is required in module configuration');
        }
        if (!$this->apiKey) {
            throw new ErrorException('Webuzo apiKey is required in module configuration');
        }

        $licenseHash = $this->builderLicenseHash ? null : WebuzoWhmcs::DEFAULT_LICENSE_HASH;

        $apiModule = new WebuzoWhmcs($this->apiUrl, $this->apiUser, $this->apiPass, $this->apiKey, $this->username, $this->builderApiUrl, $this->builderUsername, $this->builderPassword, $this->serverHost, $this->getLang());
        $apiModule->setPanel('WHMCS');
        $apiModule->setProductName($this->productName);
        $apiModule->setAddonNames($this->addonNames);
        $apiModule->setLicenseHash($licenseHash);
        $apiModule->setCreateFrom($this->createFromHash);

        // LOAD DOMAINS LIST
        try {
            $domains = $apiModule->getDomains();
        } catch (Exception $e) {
            $domains = array();
        }

        // SELECT DOMAIN
        $error = null;
        $domain = null;
        $qDomain = (isset($_GET['domain']) && $_GET['domain']) ? $_GET['domain'] : null;
        if (count($domains) > 0) {
            if (count($domains) === 1) {
                $domain = reset($domains);
            } elseif ($qDomain) {
                foreach ($domains as $d) {
                    if ($d['domain'] === $qDomain) {
                        $domain = $d;
                    }
                }
            }
            if ($domain) {
                try {
                    $apiModule->setDomain($domain['domain']);

                    $buildeUrl = $apiModule->openBuilder();
                    if ($buildeUrl) {
                        header('Location: '.$buildeUrl, true);
                        exit();
                    }
                } catch (Exception $ex) {
                    $error = $ex->getMessage();
                }
            }
        }

        if ($error) {
            throw new ErrorException($error);
        } elseif (!empty($domains)) {
            return $this->genDomainListHtml($domains);
        }
    }

    private function getLang()
    {
        $locale = (isset($GLOBALS['_LANG']['locale']) ? $GLOBALS['_LANG']['locale'] : null);
        list($lang) = ($locale ? explode('_', $locale, 2) : array(null));
        return $lang;
    }

    private function langValue($key)
    {
        if (!self::$translations) {
            self::$translations = array();
            $lang = $this->getLang();
            if (!$lang) {
                $lang = 'en';
            }
            $locale_dir = dirname(__FILE__).'/CWP/locale';
            $locale_file = $locale_dir.'/'.$lang;
            if (!is_file($locale_file)) {
                $locale_file = $locale_dir.'/en';
            }
            $locale_data = explode("\n", trim(file_get_contents($locale_file)));
            foreach ($locale_data as $li) {
                $tr = explode('=', $li, 2);
                if (!trim($tr[0]) || !isset($tr[1])) {
                    continue;
                }
                self::$translations[trim($tr[0])] = trim($tr[1]);
            }
        }
        return isset(self::$translations[$key]) ? self::$translations[$key] : $key;
    }

    private function genDomainListHtml($list)
    {
        $html = '<p>'.$this->langValue('SiteproBuilder_ChooseDomainDesc').'</p>'
                .'<h2>'.$this->langValue('SiteproBuilder_SelectDomain').'</h2>'
                .'<table class="table">'
                    .'<thead>'
                        .'<tr>'
                            .'<th>'.$this->langValue('SiteproBuilder_Domain').'</th>'
                            .'<th>'.$this->langValue('SiteproBuilder_DocRoot').'</th>'
                        .'</tr>'
                    .'</thead>'
                    .'<tbody>';
        if (empty($list)) {
            $html .= '<tr><td colspan="2">No domains</td></tr>';
        } else {
            foreach ($list as $li) {
                $html .= '<tr>'
                        .'<td><a href="?'.self::getQueryString(array('domain' => $li['domain'])).'">'.$li['domain'].'</a></td>'
                        .'<td>'.$li['path'].'</td>'
                    .'</tr>';
            }
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private static function getQueryString($extraParams = array())
    {
        $parts = explode('?', $_SERVER['REQUEST_URI'], 2);
        $qs = (isset($parts[1]) ? $parts[1] : '');
        $params = array();
        parse_str(html_entity_decode($qs), $params);
        return http_build_query(array_merge($params, $extraParams));
    }
}
