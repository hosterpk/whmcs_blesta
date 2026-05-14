<?php

namespace Blesta\Core\Util\Helpers\Common;

/**
 * Abstract class for helpers
 *
 * @package blesta
 * @subpackage core.Util.Helpers
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
abstract class AbstractHelper
{
    protected $models;
    protected $components;
    protected $helpers;
}