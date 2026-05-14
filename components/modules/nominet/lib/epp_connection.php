<?php

use Metaregistrar\EPP\eppConnection;

/**
 * Nominet EPP Connection
 *
 * @package blesta
 * @subpackage blesta.components.modules.nominet
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class NominetEppConnection extends eppConnection
{
    public function __construct($logging = false, $settingsfile = null)
    {
        parent::__construct($logging, $settingsfile);

        $this->enableDnssec();
    }
}
