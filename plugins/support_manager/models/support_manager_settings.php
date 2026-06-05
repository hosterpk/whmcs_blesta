<?php
/**
 * SupportManagerSettings model
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SupportManagerSettings extends SupportManagerModel
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang('support_manager_settings', null, PLUGINDIR . 'support_manager' . DS . 'language' . DS);
    }

    /**
     * Adds a new setting
     *
     * @param array $vars A list of input vars including:
     *  - key The setting key
     *  - company_id The company ID
     *  - value The setting value
     * @return bool True if the setting was added successfully, false otherwise
     */
    public function add(array $vars)
    {
        $this->Input->setRules($this->getRules($vars));

        if ($this->Input->validates($vars)) {
            $fields = ['key', 'company_id', 'value'];
            $this->Record->duplicate('value', '=', $vars['value'])
                ->insert('support_settings', $vars, $fields);

            return true;
        }

        return false;
    }

    /**
     * Updates an existing setting
     *
     * @param string $key The setting key
     * @param array $vars A list of input vars including:
     *  - value The new setting value
     * @param int $company_id The company ID
     * @return bool True if the setting was updated successfully, false otherwise
     */
    public function edit($key, array $vars, $company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $vars['key'] = $key;
        $vars['company_id'] = $company_id;
        
        $this->Input->setRules($this->getRules($vars, true));

        if ($this->Input->validates($vars)) {
            $this->Record->where('key', '=', $key)
                ->where('company_id', '=', $company_id)
                ->update('support_settings', ['value' => $vars['value']], ['value']);

            return true;
        }

        return false;
    }

    /**
     * Sets a setting value, creating it if it doesn't exist or updating if it does
     *
     * @param string $key The setting key
     * @param mixed $value The setting value
     * @param int $company_id The company ID
     * @return bool True if the setting was set successfully, false otherwise
     */
    public function setSetting($key, $value, $company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $vars = [
            'key' => $key,
            'company_id' => $company_id,
            'value' => $value
        ];

        return $this->add($vars);
    }

    /**
     * Retrieves a single setting
     *
     * @param string $key The setting key
     * @param int $company_id The company ID
     * @return mixed The setting object if it exists, false otherwise
     */
    public function getSetting($key, $company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        return $this->Record->select()
            ->from('support_settings')
            ->where('key', '=', $key)
            ->where('company_id', '=', $company_id)
            ->fetch();
    }

    /**
     * Retrieves all settings for a company
     *
     * @param int $company_id The company ID
     * @return array A list of all settings for the company
     */
    public function getSettings($company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        return $this->Record->select()
            ->from('support_settings')
            ->where('company_id', '=', $company_id)
            ->fetchAll();
    }

    /**
     * Sets multiple settings at once
     *
     * @param array $settings An array of key => value pairs
     * @param int $company_id The company ID
     * @return bool True if all settings were set successfully, false otherwise
     */
    public function setSettings(array $settings, $company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        // Accumulate errors across iterations — each setSetting call resets
        // Input rules, which would otherwise wipe earlier validation errors.
        // Re-key 'value' errors with the setting key to keep messages
        // attributable while preserving the 2-level structure the renderer expects.
        $accumulated_errors = [];
        $success = true;
        foreach ($settings as $key => $value) {
            if (!$this->setSetting($key, $value, $company_id)) {
                $success = false;
                $errors = $this->Input->errors();
                if (is_array($errors)) {
                    foreach ($errors as $field => $messages) {
                        $error_key = ($field === 'value') ? $key : $field;
                        $accumulated_errors[$error_key] = $messages;
                    }
                }
            }
        }

        if (!empty($accumulated_errors)) {
            $this->Input->setErrors($accumulated_errors);
        }

        return $success;
    }

    /**
     * Deletes a setting
     *
     * @param string $key The setting key
     * @param int $company_id The company ID
     * @return bool True if the setting was deleted successfully, false otherwise
     */
    public function delete($key, $company_id = null)
    {
        if (empty($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $this->Record->from('support_settings')
            ->where('key', '=', $key)
            ->where('company_id', '=', $company_id)
            ->delete();

        return true;
    }

    /**
     * Fetches a list of rules for adding/editing settings
     *
     * @param array $vars A list of input vars
     * @param bool $edit True to get the edit rules, false for the add rules (optional, default false)
     * @return array A list of rules
     */
    private function getRules(array $vars, $edit = false)
    {
        $rules = [
            'key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('SupportManagerSettings.!error.key.empty')
                ],
                'length' => [
                    'rule' => ['maxLength', 32],
                    'message' => $this->_('SupportManagerSettings.!error.key.length')
                ]
            ],
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('SupportManagerSettings.!error.company_id.exists')
                ]
            ]
        ];

        // Add AI-specific validation rules based on the setting key
        if (isset($vars['key'])) {
            $key = $vars['key'];

            // Temperature validation (0.0 - 2.0)
            if ($key === 'sm_ai_temperature') {
                $rules['value'] = [
                    'numeric' => [
                        'rule' => 'is_numeric',
                        'message' => $this->_('SupportManagerSettings.!error.ai_temperature.numeric'),
                        'post_format' => [[$this, 'formatTemperature']]
                    ],
                    'range' => [
                        'rule' => [[$this, 'validateRange'], 0.0, 2.0],
                        'message' => $this->_('SupportManagerSettings.!error.ai_temperature.range')
                    ]
                ];
            }

            // Max tokens validation (100 - 128000)
            if ($key === 'sm_ai_max_tokens') {
                $rules['value'] = [
                    'integer' => [
                        'rule' => [[$this, 'validateInteger']],
                        'message' => $this->_('SupportManagerSettings.!error.ai_max_tokens.integer'),
                        'post_format' => [[$this, 'formatInteger']]
                    ],
                    'range' => [
                        'rule' => [[$this, 'validateRange'], 100, 128000],
                        'message' => $this->_('SupportManagerSettings.!error.ai_max_tokens.range')
                    ]
                ];
            }

            // AI model validation (whitelist)
            if ($key === 'sm_ai_model') {
                $rules['value'] = [
                    'valid' => [
                        'rule' => [[$this, 'validateAiModel']],
                        'message' => $this->_('SupportManagerSettings.!error.ai_model.valid')
                    ]
                ];
            }

            // Max queue age in hours (1 - 8760, i.e. up to 1 year)
            if ($key === 'sm_ai_max_queue_age_hours') {
                $rules['value'] = [
                    'integer' => [
                        'rule' => [[$this, 'validateInteger']],
                        'message' => $this->_('SupportManagerSettings.!error.ai_max_queue_age_hours.integer'),
                        'post_format' => [[$this, 'formatInteger']]
                    ],
                    'range' => [
                        'rule' => [[$this, 'validateRange'], 1, 8760],
                        'message' => $this->_('SupportManagerSettings.!error.ai_max_queue_age_hours.range')
                    ]
                ];
            }

            // Confidence threshold validation (60 - 100)
            if ($key === 'sm_ai_confidence_threshold') {
                $rules['value'] = [
                    'integer' => [
                        'rule' => [[$this, 'validateInteger']],
                        'message' => $this->_('SupportManagerSettings.!error.ai_confidence_threshold.integer'),
                        'post_format' => [[$this, 'formatInteger']]
                    ],
                    'range' => [
                        'rule' => [[$this, 'validateRange'], 60, 100],
                        'message' => $this->_('SupportManagerSettings.!error.ai_confidence_threshold.range')
                    ]
                ];
            }

            // System prompt validation (max 10000 chars)
            if ($key === 'sm_ai_system_prompt') {
                $rules['value'] = [
                    'length' => [
                        'rule' => ['maxLength', 10000],
                        'message' => $this->_('SupportManagerSettings.!error.ai_system_prompt.length')
                    ]
                ];
            }

            // Auto-reply departments validation
            if ($key === 'sm_ai_auto_reply_departments') {
                $rules['value'] = [
                    'valid' => [
                        'rule' => [[$this, 'validateAiDepartments'], $vars['company_id'] ?? null],
                        'message' => $this->_('SupportManagerSettings.!error.ai_departments.valid')
                    ]
                ];
            }
        }

        if ($edit) {
            // Make certain fields optional for editing
            $rules = $this->setRulesIfSet($rules);

            // Key and company_id are required for edits to identify the record
            unset($rules['key']['if_set'], $rules['company_id']['if_set']);
        }

        return $rules;
    }

    /**
     * Validates a value is within a numeric range
     *
     * @param mixed $value The value to validate
     * @param float $min The minimum value
     * @param float $max The maximum value
     * @return bool True if valid, false otherwise
     */
    public function validateRange($value, $min, $max)
    {
        if (!is_numeric($value)) {
            return false;
        }

        return ($value >= $min && $value <= $max);
    }

    /**
     * Validates a value is an integer
     *
     * @param mixed $value The value to validate
     * @return bool True if valid, false otherwise
     */
    public function validateInteger($value)
    {
        return is_numeric($value) && $value == (int)$value;
    }

    /**
     * Validates AI model name against available models from BlestaAi
     *
     * @param string $value The model name to validate
     * @return bool True if valid, false otherwise
     */
    public function validateAiModel($value)
    {
        // Load BlestaAi to get available models dynamically
        Loader::loadComponents($this, ['BlestaAi']);
        $ai = new BlestaAi();
        $models = $ai->getModels();

        // Extract model IDs from the response
        $allowed_models = [];
        if (is_array($models)) {
            foreach ($models as $model) {
                // Handle both object and array responses
                if (is_object($model) && isset($model->id)) {
                    $allowed_models[] = $model->id;
                } elseif (is_array($model) && isset($model['id'])) {
                    $allowed_models[] = $model['id'];
                } elseif (is_string($model)) {
                    $allowed_models[] = $model;
                }
            }
        }

        return in_array(trim($value), $allowed_models);
    }

    /**
     * Validates auto-reply department IDs
     *
     * @param mixed $value JSON-encoded department IDs or array
     * @param int $company_id The company ID
     * @return bool True if valid, false otherwise
     */
    public function validateAiDepartments($value, $company_id)
    {
        // Decode if JSON string
        $dept_ids = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($dept_ids)) {
            return false;
        }

        // Load departments model if needed
        if (!isset($this->SupportManagerDepartments)) {
            Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
        }

        foreach ($dept_ids as $dept_id) {
            // Validate integer
            if (!is_numeric($dept_id) || $dept_id != (int)$dept_id) {
                return false;
            }

            // Check department exists and belongs to company
            $department = $this->SupportManagerDepartments->get((int)$dept_id);
            if (!$department || $department->company_id != $company_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Formats temperature value by rounding to 1 decimal place
     *
     * @param float $value The temperature value
     * @return float The formatted value
     */
    public function formatTemperature($value)
    {
        return round((float)$value, 1);
    }

    /**
     * Formats integer value by casting to int
     *
     * @param mixed $value The value to format
     * @return int The formatted value
     */
    public function formatInteger($value)
    {
        return (int)$value;
    }
}