<?php
use Blesta\Core\Util\Events\EventFactory;

/**
 * Support Manager parent model
 *
 * @package blesta
 * @subpackage plugins.support
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerModel extends AppModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        Configure::load('support_manager', dirname(__FILE__) . DS . 'config' . DS);
    }

    /**
     * Retrieves the web directory
     *
     * @return string The web directory
     */
    public function getWebDirectory()
    {
        $webdir = WEBDIR;
        $is_cli = (empty($_SERVER['REQUEST_URI']));

        // Set default webdir if running via CLI
        if ($is_cli) {
            Loader::loadModels($this, ['Settings']);
            $root_web = $this->Settings->getSetting('root_web_dir');
            if ($root_web) {
                $webdir = str_replace(DS, '/', str_replace(rtrim($root_web->value, DS), '', ROOTWEBDIR));

                if (!HTACCESS) {
                    $webdir .= 'index.php/';
                }
            }
        }

        return $webdir;
    }

    /**
     * Triggers a plugin event
     *
     * @param string $name The name of the event to trigger
     * @param array $params An array of parameters to be held by this event (optional)
     * @return array The list of parameters that were submitted along with any modifications made to them
     *  by the event handlers. In addition a __return__ item is included with the return array from the event.
     */
    public function triggerEvent($name, array $params = [])
    {
        Loader::load(dirname(__FILE__) . DS . 'support_manager_observer.php');

        $eventFactory = new EventFactory();
        $eventListener = $eventFactory->listener();
        $eventListener->register('SupportManager.' . $name, ['SupportManagerObserver', $name]);

        $event = $eventListener->trigger($eventFactory->event('SupportManager.' . $name, $params));

        // Get the event return value
        $returnValue = $event->getReturnValue();

        // Put return in a special index
        $return = ['__return__' => $returnValue];

        // Any return values that match the submitted params should be put in their own index to support extract() calls
        if (is_array($returnValue)) {
            foreach ($returnValue as $key => $data) {
                if (array_key_exists($key, $params)) {
                    $return[$key] = $data;
                }
            }
        }

        return $return;
    }
}
