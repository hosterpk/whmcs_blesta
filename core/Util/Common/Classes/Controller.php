<?php

namespace Blesta\Core\Util\Common\Classes;

use \Controller as BaseController;

/**
 * Base class for controllers
 *
 * @package blesta
 * @subpackage core.Util.Common.Classes
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
class Controller extends BaseController
{
    protected $models;
    protected $components;
    protected $helpers;

    public function __construct()
    {
        parent::__construct();

        $this->models = new \stdClass();
        $this->components = new \stdClass();
        $this->helpers = new \stdClass();
    }
}
