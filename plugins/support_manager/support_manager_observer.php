<?php
use Blesta\Core\Util\Events\Observer;
use Blesta\Core\Util\Events\Common\EventInterface;

/**
 * The Support Manager plugin event observer
 *
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerObserver extends Observer
{
    /**
     * Handle SupportManager.addTicketBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addTicketBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addTicketBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.addTicketAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addTicketAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addTicketAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.editTicketBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.editTicketBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editTicketBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.editTicketAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.editTicketAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editTicketAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.addDepartmentBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addDepartmentBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addDepartmentBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.addDepartmentAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addDepartmentAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addDepartmentAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.editDepartmentBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.editDepartmentBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editDepartmentBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.editDepartmentAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.editDepartmentAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editDepartmentAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.addReplyBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addReplyBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addReplyBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle SupportManager.addReplyAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for SupportManager.addReplyAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addReplyAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }
}