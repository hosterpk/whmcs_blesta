<?php

namespace Blesta\Core\Util\Helpers;

use Blesta\Core\Util\Helpers\Common\AbstractHelper;

/**
 * A helper
 *
 * @package blesta
 * @subpackage core.Util.Helpers
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Helper extends AbstractHelper
{
    public function __construct()
    {
        $this->models = new \stdClass();
        $this->components = new \stdClass();
        $this->helpers = new \stdClass();
    }
}