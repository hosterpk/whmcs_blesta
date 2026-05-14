<?php
use Blesta\Core\Util\Components\Component;

Loader::load(COMPONENTDIR . 'net' . DS . 'net_protocol.php');

/**
 * Networking factory component
 *
 * @package blesta
 * @subpackage components.net
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Net extends Component
{
    /**
     * Creates a new instance of the given Net library
     * @param string $lib The library to load
     * @param array $params An array of parameters to pass to the library's constructor
     */
    public static function create($lib, array $params = [])
    {
        $lib = Loader::fromCamelCase($lib);
        $class = Loader::toCamelCase($lib);

        // Load the library requested
        Loader::load(COMPONENTDIR . 'net' . DS . $lib . DS . $lib . '.php');

        $reflect = new ReflectionClass($class);
        return $reflect->newInstanceArgs($params);
    }
}
