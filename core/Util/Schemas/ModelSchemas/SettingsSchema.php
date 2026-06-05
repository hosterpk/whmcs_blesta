<?php

namespace Blesta\Core\Util\Schemas\ModelSchemas;

/**
 * Schema definition for the Settings model
 *
 * NOTE: The Settings model is a wrapper that handles system settings
 * and may not map directly to a single table.
 */

return [
    'model' => 'Settings',
    'table' => null, // Uses various settings tables
    'primary_key' => null,

    'virtual' => [],

    'relationships' => [],

    'presets' => [],
];
