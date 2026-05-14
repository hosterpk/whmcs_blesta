<?php

namespace Sitebuilder\Panels;

use ErrorException;
use Sitebuilder\Panels\ISP5\ISPBlesta;
use Sitebuilder\Panels\ISP5\ISPExternal;

class ISP5 extends Panel {

	private $apiUrl = null;

	private function getLang()
    {
        $locale = (isset($GLOBALS['_LANG']['locale']) ? $GLOBALS['_LANG']['locale'] : null);
        list($lang) = ($locale ? explode('_', $locale, 2) : array(null));
        return $lang;
    }

	public function process() {
		$this->apiUrl = 'https://'.$this->serverHost.':1500/ispmgr';
		if ($this->panel == 'Blesta') {
			require_once __DIR__.'/ISP5/ISPBlesta.php';
			$module = new ISPBlesta(
				$this->apiUrl,
				$this->serverUsername,
				$this->serverPassword,
				$this->builderApiUrl,
				$this->builderUsername,
				$this->builderPassword,
				$this->username,
				$this->builderLicenseHash,
				$this->panel,
				$this->serverHost,
				$this->domain,
				null,
				$this->builderUserId,
				$this->hostingPlan,
				$this->getLang(),
				$this->pluginVersion
			);
		} else {
			require_once __DIR__.'/ISP5/ISPExternal.php';
			$module = new ISPExternal(
				$this->apiUrl,
				$this->serverUsername,
				$this->serverPassword,
				$this->builderApiUrl,
				$this->builderUsername,
				$this->builderPassword,
				$this->username,
				$this->builderLicenseHash,
				$this->panel,
				$this->serverHost,
				$this->domain,
				null,
				$this->builderUserId,
				$this->hostingPlan,
				$this->getLang(),
				null,
				$this->pluginVersion
			);
		}
		$module->setProductName($this->productName);
		$module->setAddonNames($this->addonNames);
		$module->setCreateFrom($this->createFromHash);

		$url = $module->openBuilder();

		if (is_array($url) && isset($url['url'])) {
			header('Location: ' . $url['url']);
		}
		elseif (is_array($url) && isset($url['error'])) {
			throw new ErrorException($url['error']);
		}

		exit();
	}
}
