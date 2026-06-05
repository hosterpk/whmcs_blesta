<?php

use Blesta\Core\Util\Events\Observer;
use Blesta\Core\Util\Events\Common\EventInterface;

/**
 * The Order plugin event observer
 *
 * @link http://www.blesta.com/ Blesta
 */
class OrderObserver extends Observer
{
    /**
     * Handle Order.cancelOrderBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.cancelOrderBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function cancelOrderBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.cancelOrderAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.cancelOrderAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function cancelOrderAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.addOrderBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addOrderBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addOrderBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.addOrderAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addOrderAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addOrderAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.setOrderStatusBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.setOrderStatusBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function setOrderStatusBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.setOrderStatusAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.setOrderStatusAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function setOrderStatusAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.addPayoutBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addPayoutBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addPayoutBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.addPayoutAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addPayoutAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addPayoutAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.editPayoutBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.editPayoutBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editPayoutBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.editPayoutAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.editPayoutAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editPayoutAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

        /**
     * Handle Order.addReferralBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addReferralBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addReferralBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.addReferralAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.addReferralAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function addReferralAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.editReferralBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.editReferralBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editReferralBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.editReferralAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.editReferralAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function editReferralAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.fraudCheckBefore events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.fraudCheckBefore events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function fraudCheckBefore(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }

    /**
     * Handle Order.fraudCheckAfter events
     *
     * @param Blesta\Core\Util\Events\Common\EventInterface $event An event object for Order.fraudCheckAfter events
     * @return Blesta\Core\Util\Events\Common\EventInterface The processed event object
     */
    public static function fraudCheckAfter(EventInterface $event)
    {
        return parent::triggerEvent($event);
    }
}
