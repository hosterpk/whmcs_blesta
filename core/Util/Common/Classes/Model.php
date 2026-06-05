<?php

namespace Blesta\Core\Util\Common\Classes;

use Model as MinphpModel;

/**
 * Base class for models
 *
 * @package blesta
 * @subpackage core.Util.Common.Classes
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
class Model extends MinphpModel
{
    /**
     * Returns an instance of the model
     *
     * @return static The model instance
     */
    public static function instance(...$args): static
    {
        return new static(...$args);
    }

    /**
     * Returns the hostname for the current company
     *
     * @return string The company hostname (e.g., "example.com")
     */
    public static function hostname(): string
    {
        return \Configure::get('Blesta.company')->hostname;
    }

    /**
     * Safely unserializes data, supporting both JSON and PHP serialized formats
     * while preventing PHP object injection attacks.
     *
     * This method handles both JSON (new format) and PHP serialized data (legacy format)
     * while preventing PHP object injection attacks by rejecting object deserialization.
     *
     * @param string|null $data The serialized or JSON-encoded data
     * @return mixed The unserialized data, or null on failure
     */
    public static function safeUnserialize(?string $data)
    {
        if ($data === null) {
            return null;
        }

        // Try JSON first (new format)
        $result = json_decode($data, true);
        if ($result !== null || json_last_error() === JSON_ERROR_NONE) {
            return $result;
        }

        // Fall back to unserialize for legacy data with allowed_classes => false
        // to prevent PHP object injection attacks
        return @unserialize($data, ['allowed_classes' => false]);
    }
}
