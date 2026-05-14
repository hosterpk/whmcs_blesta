<?php
use Blesta\Core\Util\Components\Component;

Loader::load(COMPONENTDIR . 'delivery' . DS . 'delivery_method.php');

/**
 * Delivery factory component
 *
 * @package blesta
 * @subpackage components.delivery
 * @copyright Copyright (c) 2011, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Delivery extends Component
{
    /**
     * Creates a new instance of the given Delivery library
     *
     * @param string $lib The library to load
     * @param array $params An array of parameters to pass to the library's constructor
     */
    public static function create($lib, array $params = [])
    {
        $lib = Loader::fromCamelCase($lib);
        $class = Loader::toCamelCase($lib);

        // Load the library requested
        Loader::load(COMPONENTDIR . 'delivery' . DS . $lib . DS . $lib . '.php');

        $reflect = new ReflectionClass($class);
        return $reflect->newInstanceArgs($params);
    }
}
