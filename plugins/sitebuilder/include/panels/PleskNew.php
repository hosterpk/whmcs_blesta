<?php

namespace Sitebuilder\Panels;

use Modules_Siteprobuilder_SitePro;
use Sitebuilder\Panels\PleskNew\PleskApi;

require_once __DIR__.'/Panel.php';

class PleskNew extends Panel {
	public function process() {
        require_once __DIR__.'/PleskNew/Api.php';
        require_once __DIR__.'/PleskNew/Functions.php';
        require_once __DIR__.'/PleskNew/ICustomApiHandler.php';
        require_once __DIR__.'/PleskNew/PleskApi.php';
        require_once __DIR__.'/PleskNew/SitePro.php';

		$module = new Modules_Siteprobuilder_SitePro(null, $this->domain);
		$module->setup(
			$this->builderApiUrl,
			$this->builderUsername,
			$this->builderPassword,
			$this->builderUserId,
			$this->builderLicenseHash,
			$this->builderPublicKey,
			$this->panel,
			$this->createFromHash,
			false,
			$this->pluginVersion
		);
		$module->setCustomApiHandler(new PleskApi($this->getApiHost(), $this->serverUsername, $this->serverPassword));
		$module->setProductName($this->productName);
		$module->setAddonNames($this->addonNames);
		$module->forceHostingPlan($this->hostingPlan);
		$module->start();
	}

	private function getApiHost() {
		return $this->serverHost ? $this->serverHost : $this->serverIp;
	}
}
