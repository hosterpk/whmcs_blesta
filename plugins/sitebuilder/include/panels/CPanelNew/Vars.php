<?php

interface CPANEL {
	/**
	 * @return array
	 */
	public function uapi($module, $function, $params);

	/**
	 * @return array
	 */
	public function api2($module, $function, $params);

	/**
	 * @param string $title
	 * @return string
	 */
	public function header($title = null);

	/**
	 * @return string
	 */
	public function footer();

	/**
	 * @return string
	 */
	public function end();
}
