<?php
use Blesta\Core\Util\Components\Component;

Loader::load(dirname(__FILE__) . DS . 'invoice_format.php');

/**
 * Invoice Format factory
 *
 * @package blesta
 * @subpackage blesta.components.invoice_formats
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class InvoiceFormats extends Component
{
    /**
     * Returns an instance of the requested invoice format
     *
     * @param string $format_name The name of the invoice format to instantiate
     * @return mixed An object of type $format_name
     * @throws Exception Thrown when the format_name does not exists or does not inherit from the appropriate parent
     */
    public static function create($format_name)
    {
        $format_name = Loader::toCamelCase($format_name);
        $format_file = Loader::fromCamelCase($format_name);

        if (!Loader::load(COMPONENTDIR . 'invoice_formats' . DS . $format_file . DS . $format_file . '.php')) {
            throw new Exception("Invoice Format '" . $format_name . "' does not exist.");
        }

        if (class_exists($format_name) && is_subclass_of($format_name, 'InvoiceFormat')) {
            return new $format_name();
        }

        throw new Exception("Invoice Format '" . $format_name . "' is not a recognized invoice format.");
    }

    /**
     * Lists all available invoice formats by scanning directories
     *
     * @return array An associative array where key is format_name and value is the display name
     */
    public static function listFormats()
    {
        $formats = [];
        $directories = glob(COMPONENTDIR . 'invoice_formats' . DS . '*', GLOB_ONLYDIR);
        foreach ($directories as $dir) {
            $format_name = basename($dir);
            $format_file = $dir . DS . $format_name . '.php';

            // Check if the format class file exists
            if (file_exists($format_file)) {
                try {
                    // Create an instance to get the display name
                    $instance = self::create($format_name);
                    $formats[$format_name] = $instance->getName();
                } catch (Exception $e) {
                    // If we can't create the instance, use the format name as fallback
                    $formats[$format_name] = $format_name;
                }
            }
        }

        return $formats;
    }
}
