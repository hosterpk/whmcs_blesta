<?php

namespace Blesta\Core\Util\Common\Classes;

use \Model as BaseModel;

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
class Model extends BaseModel
{
    protected $models;
    protected $components;
    protected $helpers;

    public function __construct($db_info = null)
    {
        parent::__construct($db_info);

        $this->models = new \stdClass();
        $this->components = new \stdClass();
        $this->helpers = new \stdClass();
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
    public static function safeUnserialize($data)
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
