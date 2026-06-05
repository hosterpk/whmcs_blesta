<?php
/**
 * Procedural Function Mapping for Classes
 *
 * This configuration defines prefix mappings for automatically generating procedural function wrappers
 * for all public static methods in the specified classes.
 *
 * Two mapping formats are supported:
 *
 * 1. Class-to-Prefix Mapping (Auto-generates all methods):
 *    'prefix' => ClassName::class
 *    - Automatically wraps ALL public static methods from the class
 *    - Function names follow the pattern: {prefix}_{method_name}
 *
 * 2. Individual Method Mapping (Selective wrapping):
 *    'function_name' => [ClassName::class, 'methodName']
 *    - Wraps only the specified static method
 *    - Allows custom function names and selective method exposure
 *
 * Examples:
 * - Class mapping: 'array' => Blesta\Utils\Arrays::class
 *   - Arrays::get() becomes array_get()
 *   - Arrays::flatten() becomes array_flatten()
 *
 * - Individual mapping: 'str_truncate' => [Blesta\Utils\Strings::class, 'truncate']
 *   - Only creates str_truncate() function
 *   - Useful for custom naming or limiting exposed methods
 *
 * This provides a Laravel-style helper function API for developers who prefer procedural programming
 * or want quick access to utility methods without explicit class references.
 *
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

return [
    'safe_unserialize' => [Blesta\Core\Util\Common\Classes\Model::class, 'safeUnserialize'],
    'view' => [Blesta\Core\Util\Common\Classes\Controller::class, 'renderPartialView'],
    'app' => Blesta\Core\Util\Common\Classes\Controller::class,
];
