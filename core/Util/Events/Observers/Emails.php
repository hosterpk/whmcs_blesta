<?php

namespace Blesta\Core\Util\Events\Observers;

use Blesta\Core\Util\Events\Observer;
use Blesta\Core\Util\Events\Common\EventInterface;

/**
 * The Emails event observer
 *
 * @package blesta
 * @subpackage core.Util.Events.Observers
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Emails extends Observer
{
    /**
     * Handle Emails.get events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Emails.get events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function get(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Emails.send events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Emails.send events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function send(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Emails.sendCustom events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Emails.sendCustom events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function sendCustom(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }
}
