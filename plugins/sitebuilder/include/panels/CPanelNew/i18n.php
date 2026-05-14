<?php

namespace Sitebuilder\Panels\CPanelNew;

class i18n {
	private static $instance = null;
	/** @return self */
	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private $translations = null;

	private $lang;

	public function setLang($lang) {
		$this->lang = $lang;
	}

	public function __($key) {
		if (is_null($this->translations)) {
			$this->translations = array();
			$lang = $this->lang;
			if (!$lang) $lang = 'en';
			$locale_dir = dirname(__FILE__).'/locale';
			$locale_file = $locale_dir.'/'.$lang;
			if (!is_file($locale_file)) { $locale_file = $locale_dir.'/en'; }
			$locale_data = explode("\n", trim(file_get_contents($locale_file)));
			foreach ($locale_data as $li) {
				$tr = explode('=', $li, 2);
				if (!trim($tr[0]) || !isset($tr[1])) { continue; }
				$this->translations[trim($tr[0])] = trim($tr[1]);
			}
		}
		return isset($this->translations[$key]) ? $this->translations[$key] : $key;
	}
}