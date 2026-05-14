<?php
/**
 * Upgrades to version 5.9.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_9_0B1 extends UpgradeUtil
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
            'addPackagesManualActivation',
            'createBlacklistTable',
            'addBlacklistAction',
            'addBlacklistPermissions',
            'addServiceRenewalSpacingSettings',
            'addServiceInvoicesDateNextAttemptColumn',
            'addUsersRecoveryEmailColumn',
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
     * Adds the new manual activation column to the packages table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPackagesManualActivation($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `packages` DROP `manual_activation`;');
        } else {
            $this->Record->query(
                'ALTER TABLE `packages` ADD `manual_activation` TINYINT( 1 ) UNSIGNED NOT NULL DEFAULT 0 AFTER `module_group`;'
            );
        }
    }

    /**
     * Adds the company settings for Service Renewal Spacing
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function addServiceRenewalSpacingSettings($undo = false)
    {
        Loader::loadModels($this, ['Companies']);
        $companies = $this->Companies->getAll();

        $fields = [
            'first_renewal_attempt_threshold' => '6',
            'first_renewal_attempt_spacing' => '1',
            'second_renewal_attempt_threshold' => '12',
            'second_renewal_attempt_spacing' => '24'
        ];

        if ($undo) {
            // Remove company settings
            foreach ($companies as $company) {
                foreach ($fields as $field => $value) {
                    $this->Companies->unsetSetting($company->id, $field);
                }
            }
        } else {
            // Update company settings
            foreach ($companies as $company) {
                foreach ($fields as $field => $value) {
                    $this->Companies->setSetting($company->id, $field, $value);
                }
            }
        }
    }

    /**
     * Adds a new "date_next_attempt" column to the service_invoices table
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addServiceInvoicesDateNextAttemptColumn($undo = false)
    {
        if ($undo) {
            $this->Record->query(
                "ALTER TABLE `service_invoices` DROP `date_next_attempt`;"
            );
        } else {
            $this->Record->query(
                "ALTER TABLE `service_invoices` ADD `date_next_attempt` DATETIME NULL DEFAULT NULL;"
            );
        }
    }

    /**
     * Creates the required tables by the Account Management system in the database
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function createBlacklistTable($undo = false)
    {
        Loader::loadModels($this, ['Companies']);

        if ($undo) {
            try {
                $this->Record->drop('blacklist');
            } catch (Exception $e) {
                // Nothing to do
            }
        } else {
            // blacklist
            $this->Record->
                setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
                setField('rule', ['type' => 'varchar', 'size' => 255])->
                setField(
                    'type',
                    ['type' => 'enum', 'size' => "'ip','email'", 'default' => 'ip']
                )->
                setField('plugin_dir', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])->
                setField('note', ['type' => 'text', 'is_null' => true, 'default' => null])->
                setKey(['id'], 'primary')->
                create('blacklist', true);
        }
    }

    /**
     * Adds "Blacklist" to the navigation bar
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addBlacklistAction($undo = false)
    {
        if ($undo) {
            $this->Record->from('action')->
                innerJoin('navigation_items', 'navigation_items.action_id', '=', 'actions.id', false)->
                where('actions.url', '=', 'tools/blacklist/')->
                delete(['actions.*', 'navigation_items.*']);
        } else {
            $companies = $this->Record->select()->
                from('companies')->
                fetchAll();

            foreach ($companies as $company) {
                $params = [
                    'location' => 'nav_staff',
                    'url' => 'tools/blacklist/',
                    'name' => 'Navigation.getprimary.nav_tools_blacklist',
                    'company_id' => $company->id,
                    'editable' => 0,
                    'enabled' => 1
                ];
                $this->Record->insert('actions', $params);
                $action_id = $this->Record->lastInsertId();

                $tools_nav_item = $this->Record->select('navigation_items.id')->
                    from('actions')->
                    innerJoin('navigation_items', 'navigation_items.action_id', '=', 'actions.id', false)->
                    where('actions.url', '=', 'tools/')->
                    where('actions.company_id', '=', $company->id)->
                    fetch();

                if ($tools_nav_item) {
                    $last_nav_item = $this->Record->select('navigation_items.order')->
                        from('navigation_items')->
                        order(['order' => 'desc'])->
                        fetch();
                    $this->Record->insert(
                        'navigation_items',
                        ['action_id' => $action_id, 'order' => $last_nav_item->order, 'parent_id' => $tools_nav_item->id]
                    );
                }
            }

        }
    }

    /**
     * Adds a permission for tools blacklist page
     *
     * @param bool $undo Whether to add or undo the change
     */
    private function addBlacklistPermissions($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            // Get the admin tools permission group
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);
            $group = $this->Permissions->getGroupByAlias('admin_tools');

            // Determine comparable permission access
            $staff_groups = $this->Record->select()->from('staff_groups')->fetchAll();
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = $this->Permissions->authorized(
                    'staff_group_' . $staff_group->id,
                    'admin_tools',
                    'logs',
                    'staff',
                    $staff_group->company_id
                );
            }

            // Add the new tools utilities page permission
            if ($group) {
                $this->Permissions->add([
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_tools_blacklist',
                    'alias' => 'admin_tools',
                    'action' => 'blacklist'
                ]);

                foreach ($staff_groups as $staff_group) {
                    if ($staff_group_access[$staff_group->id]) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_tools', 'blacklist');
                    }
                }
            }
        }
    }

    /**
     * Adds the new recovery email column to the users table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addUsersRecoveryEmailColumn($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `users` DROP `recovery_email`;');
        } else {
            $this->Record->query(
                'ALTER TABLE `users` ADD `recovery_email` VARCHAR( 255 ) NULL DEFAULT NULL AFTER `password`;'
            );
        }
    }
}
