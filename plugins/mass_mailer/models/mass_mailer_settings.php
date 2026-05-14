<?php
/**
 * Mass Mailer settings
 *
 * @package blesta
 * @subpackage plugins.massmailer.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class MassMailerSettings extends MassMailerModel
{
    /**
     * Initialize
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang('mass_mailer_settings', null, PLUGINDIR . 'mass_mailer' . DS . 'language' . DS);
    }

    /**
     * Adds new settings
     *
     * @param int $company_id The ID of the company
     * @param array $vars A numerically-indexed list of overview setting key/value pairs
     */
    public function add($company_id, array $vars)
    {
        $rules = [
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('MassMailerSettings.!error.company_id.exists')
                ]
            ],
            'settings[][key]' => [
                'empty' => [
                    'rule'=>'isEmpty',
                    'negate' => true,
                    'message' => $this->_('MassMailerSettings.!error.settings[][key].empty')
                ]
            ],
            'settings[][value]' => [
                'length' => [
                    'rule' => ['maxLength', 255],
                    'message' => $this->_('MassMailerSettings.!error.settings[][value].length')
                ]
            ]
        ];

        $input = [
            'company_id' => $company_id,
            'settings' => $vars
        ];

        $this->Input->setRules($rules);

        if ($this->Input->validates($input)) {
            // Save each setting
            foreach ($input['settings'] as $setting) {
                $value = $setting['value'] ?? '';

                // Set input settings
                $settings = ['company_id' => $company_id, 'key' => $setting['key'], 'value' => $value];

                $this->Record->duplicate('value', '=', $value)->
                    insert('mass_mailer_settings', $settings);
            }
        }
    }

    /**
     * Retrieves a list of all mass mailer settings for a given company
     *
     * @param int $company_id The company ID
     * @return array A list of mass mailer settings for the given company
     */
    public function getSettings($company_id)
    {
        return $this->Record->select()->from('mass_mailer_settings')->
            where('company_id', '=', $company_id)->
            fetchAll();
    }
}
