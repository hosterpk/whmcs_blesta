<?php

namespace Blesta\Helpers\DataStructure;

use Blesta\Core\Util\Helpers\Helper;
use Exception;
use Loader;

/**
 * Factory class for creating Data Structure Helper objects
 *
 * @package blesta
 * @subpackage helpers.dataStructure
 */
class DataStructure extends Helper
{
    /**
     * Returns an instance of the requested helper
     *
     * @param string $structure The name of the data structure helper to instantiate
     * @return mixed A helper whose purpose is to manipulate data structures of the type $structure
     * @throws Exception Thrown when the helper does not exist
     */
    public static function create($structure)
    {
        $structure_name = "\\Blesta\\Helpers\\DataStructure\\{$structure}\\DataStructure{$structure}";

        if (class_exists($structure_name)) {
            return new $structure_name();
        }

        throw new Exception("The helper '" . $structure_name . "' is not a recognized data structure helper.");
    }
}
