<?php

namespace Blesta\Core\Util\Events;

use Blesta\Core\Util\Events\Common\EventInterface;
use Blesta\Core\Util\Events\Common\Observable;

/**
 * Handler for invoking observable events
 *
 * @package blesta
 * @subpackage core.Util.Events
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
class Handler implements Observable
{
    /**
     * {@inheritdoc}
     */
    public function update(EventInterface $event)
    {
    }
}
