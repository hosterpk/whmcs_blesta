<?php

namespace Sitebuilder\Classes;

class ProductData {
	/** @var int */
	public $id;
	/** @var string */
	public $name;
	/** @var string|null */
	public $plan = null;

	public function __construct($id, $name = null, $plan = null) {
		$this->id = $id;
		$this->name = $name;
		$this->plan = $plan;
	}
}
