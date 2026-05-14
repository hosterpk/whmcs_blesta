<?php
/**
 * Upgrades to version 5.11.0
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_11_0 extends UpgradeUtil
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
            'updateServiceQueuePermissions',
            'updateServiceQueueAction',
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
     * Replaces the permissions for admin_tools.renewals by admin_tools.queue
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateServiceQueuePermissions($undo = false)
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

            // Add the new tools queue page permission
            if ($group) {
                $old_permission = $this->Permissions->getByAlias('admin_tools', null, 'renewals');
                if (!empty($old_permission->id)) {
                    $this->Permissions->delete($old_permission->id);
                }

                $this->Permissions->add([
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_tools_provisioning',
                    'alias' => 'admin_tools',
                    'action' => 'provisioning'
                ]);

                foreach ($staff_groups as $staff_group) {
                    if ($staff_group_access[$staff_group->id]) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_tools', 'provisioning');
                    }
                }
            }
        }
    }

    /**
     * Replaces the staff navigation entry for admin_tools.renewals by admin_tools.queue
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateServiceQueueAction($undo = false)
    {
        if ($undo) {
            $this->Record->where('location', '=', 'nav_staff')
                ->where('url', '=', 'tools/provisioning/')
                ->update('actions', [
                    'url' => 'tools/renewals/',
                    'name' => 'Navigation.getprimary.nav_tools_renewals'
                ]);
        } else {
            $this->Record->where('location', '=', 'nav_staff')
                ->where('url', '=', 'tools/renewals/')
                ->update('actions', [
                    'url' => 'tools/provisioning/',
                    'name' => 'Navigation.getprimary.nav_tools_provisioning'
                ]);
        }
    }
}
