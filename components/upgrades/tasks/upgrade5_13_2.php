<?php
/**
 * Upgrades to version 5.13.2
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_13_2 extends UpgradeUtil
{
    /**
     * @var array An array of all tasks completed
     */
    private $tasks = [];

    /**
     * Setup
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Returns a numerically indexed array of tasks to execute for the upgrade process
     *
     * @return array A numerically indexed array of tasks to execute for the upgrade process
     */
    public function tasks()
    {
        return [
            'addPostalMethodsReturnAddressDefaults',
        ];
    }

    /**
     * Processes the given task
     *
     * @param string $task The task to process
     */
    public function process($task)
    {
        $tasks = $this->tasks();

        // Ensure task exists
        if (!in_array($task, $tasks)) {
            return;
        }

        $this->tasks[] = $task;
        $this->{$task}();
    }

    /**
     * Rolls back all tasks completed for the upgrade process
     */
    public function rollback()
    {
        // Undo all tasks
        while (($task = array_pop($this->tasks))) {
            $this->{$task}(true);
        }
    }

    /**
     * Adds default PostalMethods return address settings for all companies
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPostalMethodsReturnAddressDefaults($undo = false)
    {
        $settings = [
            'postalmethods_return_name',
            'postalmethods_return_company',
            'postalmethods_return_address1',
            'postalmethods_return_address2'
        ];

        if ($undo) {
            foreach ($settings as $setting) {
                $this->Record->from('company_settings')
                    ->where('key', '=', $setting)
                    ->delete();
            }
        } else {
            // Get all companies with their names and addresses
            $companies = $this->Record->select(['id', 'name', 'address'])->from('companies')->fetchAll();

            foreach ($companies as $company) {
                // Split address by newlines, take first 2 lines
                $address_lines = preg_split('/\r\n|\r|\n/', $company->address ?? '');
                $address1 = $address_lines[0] ?? '';
                $address2 = $address_lines[1] ?? '';

                $this->Record->insert(
                    'company_settings',
                    [
                        'key' => 'postalmethods_return_name',
                        'value' => 'Billing Department',
                        'company_id' => $company->id
                    ]
                );

                $this->Record->insert(
                    'company_settings',
                    [
                        'key' => 'postalmethods_return_company',
                        'value' => $company->name,
                        'company_id' => $company->id
                    ]
                );

                $this->Record->insert(
                    'company_settings',
                    [
                        'key' => 'postalmethods_return_address1',
                        'value' => $address1,
                        'company_id' => $company->id
                    ]
                );

                $this->Record->insert(
                    'company_settings',
                    [
                        'key' => 'postalmethods_return_address2',
                        'value' => $address2,
                        'company_id' => $company->id
                    ]
                );
            }
        }
    }
}
