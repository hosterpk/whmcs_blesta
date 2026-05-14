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

        $success = true;
        foreach ($settings as $key => $value) {
            if (!$this->setSetting($key, $value, $company_id)) {
                $success = false;
            }
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

        if ($edit) {
            // Make certain fields optional for editing
            $rules = $this->setRulesIfSet($rules);
            
            // Key and company_id are required for edits to identify the record
            unset($rules['key']['if_set'], $rules['company_id']['if_set']);
        }

        return $rules;
    }
}