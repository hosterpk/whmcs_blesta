<?php
namespace Blesta\Core\Util\Events\Observers;

use Blesta\Core\Util\Events\Observer;
use Blesta\Core\Util\Events\Common\EventInterface;

/**
 * The Users event observer
 *
 * @package blesta
 * @subpackage core.Util.Events.Observers
 * @copyright Copyright (c) 2019, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Users extends Observer
{
    /**
     * Handle Users.delete events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Users.delete events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function delete(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Users.login events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Users.login events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function login(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Users.logout events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Users.logout events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function logout(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Users.get events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Users.get events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function get(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }
}
