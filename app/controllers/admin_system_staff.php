<?php

/**
 * Admin System Staff Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminSystemStaff extends AdminController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Settings', 'Staff', 'StaffGroups']);
        $this->components(['SettingsCollection']);

        // Create an array helper
        $this->ArrayHelper = $this->DataStructure->create('Array');

        if (!$this->isAjax()) {
        }
    }

    /**
     * Index
     */
    public function index()
    {
        // Redirect to Manage Staff
        $this->redirect($this->base_uri . 'settings/system/staff/manage/');
    }

    /**
     * Manage Staff
     */
    public function manage()
    {
        // Set current page of results
        $status = (isset($this->get[0]) ? $this->get[0] : 'active');
        $page = (isset($this->get[1]) ? (int) $this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'first_name');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'asc');

        // Set the number of staff of each type, from every company
        $status_count = [
            'active' => $this->Staff->getListCount(null, 'active'),
            'inactive' => $this->Staff->getListCount(null, 'inactive')
        ];

        $this->set('status_count', $status_count);
        $this->set('status', $status);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('staff', $this->Staff->getList(null, $status, $page, [$sort => $order]));
        $this->set('staff_id', $this->Session->read('blesta_staff_id'));
        $total_results = $this->Staff->getListCount(null, $status);

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'settings/system/staff/manage/' . $status . '/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Staff Add Settings page
     */
    public function add()
    {
        $this->uses(['Users']);

        // Load the Base2n class from vendors
        $base32 = new Base2n(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', false, true, true);

        $vars = new stdClass();

        if (!empty($this->post)) {
            // Use the email as the username if selected
            if (isset($this->post['username_type']) && isset($this->post['email'])) {
                if ($this->post['username_type'] == 'email') {
                    $this->post['username'] = $this->post['email'];
                }
            }

            // Begin transaction
            $this->Users->begin();

            $user_id = $this->Users->add($this->post);
            $errors = $this->Users->errors();

            // Add staff iff there are no user errors
            if (empty($errors)) {
                $this->post['user_id'] = $user_id;

                $this->Staff->add($this->post);
                $errors = $this->Staff->errors();
            }

            if (!empty($errors)) {
                // Error, rollback
                $this->Users->rollBack();

                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success, commit
                $this->Users->commit();

                $this->flashMessage('message', Language::_('AdminSystemStaff.!success.staff_added', true));
                $this->redirect($this->base_uri . 'settings/system/staff/manage/');
            }
        }

        // Determine selected and available staff groups
        $staff_groups = $this->StaffGroups->getAll();
        $formatted_staff_groups = $this->ArrayHelper->numericToKey($staff_groups, 'id', 'name');

        // Rename the staff group to include the company name
        foreach ($staff_groups as $staff_group) {
            if (isset($formatted_staff_groups[$staff_group->id])) {
                $formatted_staff_groups[$staff_group->id] .= ' - ' . $staff_group->company_name;
            }
        }

        if (!empty($vars->groups)) {
            $this->assignGroups($formatted_staff_groups, $vars->groups);
        }

        // Generate random two-factor key
        if (!isset($vars->two_factor_key) || $vars->two_factor_key == '') {
            $vars->two_factor_key = $this->Users->systemHash(mt_rand() . md5(mt_rand()), null, 'sha1');
        }

        $vars->two_factor_key_base32 = $base32->encode(pack('H*', $vars->two_factor_key));

        $this->set('groups', $formatted_staff_groups);
        $this->set('two_factor_modes', $this->Users->getOtpModes());
        $this->set('vars', $vars);
    }

    /**
     * Staff Edit Settings page
     */
    public function edit()
    {
        $this->uses(['Users']);

        // Load the Base2n class from vendors
        $base32 = new Base2n(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', false, true, true);

        // Ensure a staff ID was provided and set the staff member's info
        if (!isset($this->get[0]) || !($staff = $this->Staff->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'settings/system/staff/');
        }

        $vars = null;

        // Edit the staff member
        if (!empty($this->post)) {
            // Remove new password if none given
            if (empty($this->post['new_password'])) {
                unset($this->post['new_password'], $this->post['confirm_password']);
            }

            // Begin transaction
            $this->Users->begin();

            // Whitelist user fields to prevent mass assignment
            $user_vars = array_intersect_key(
                $this->post,
                array_flip(
                    [
                        'username',
                        'new_password',
                        'confirm_password',
                        'recovery_email',
                        'two_factor_mode',
                        'two_factor_key',
                        'two_factor_pin'
                    ]
                )
            );
            $this->Users->edit($staff->user_id, $user_vars);
            $user_errors = $this->Users->errors();

            // Whitelist staff fields to prevent mass assignment
            $staff_vars = array_intersect_key(
                $this->post,
                array_flip(
                    [
                        'first_name',
                        'last_name',
                        'email',
                        'email_mobile',
                        'number_mobile',
                        'status',
                        'groups'
                    ]
                )
            );
            $this->Staff->edit($staff->id, $staff_vars);
            $staff_errors = $this->Staff->errors();

            $errors = array_merge(($user_errors ? $user_errors : []), ($staff_errors ? $staff_errors : []));

            if (!empty($errors)) {
                // Error, reset vars and rollback
                $this->Users->rollBack();

                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success, commit
                $this->Users->commit();

                $this->flashMessage('message', Language::_('AdminSystemStaff.!success.staff_updated', true));
                $this->redirect($this->base_uri . 'settings/system/staff/edit/' . $staff->id . '/');
            }
        }

        // Set current staff member vars
        if (!$vars) {
            $vars = $staff;

            // Set assigned groups to a list of group IDs
            if (!empty($vars->groups) && is_array($vars->groups)) {
                $temp_groups = $vars->groups;

                for ($i = 0, $num_groups = count($temp_groups); $i < $num_groups; $i++) {
                    $vars->groups[$i] = $temp_groups[$i]->id;
                }
            }
        }

        // Determine selected and available staff groups
        $staff_groups = $this->StaffGroups->getAll();
        $formatted_staff_groups = $this->ArrayHelper->numericToKey($staff_groups, 'id', 'name');

        // Rename the staff group to include the company name
        foreach ($staff_groups as $staff_group) {
            if (isset($formatted_staff_groups[$staff_group->id])) {
                $formatted_staff_groups[$staff_group->id] .= ' - ' . $staff_group->company_name;
            }
        }

        if (!empty($vars->groups)) {
            $this->assignGroups($formatted_staff_groups, $vars->groups);
        }

        // Generate random two-factor key
        if (!isset($vars->two_factor_key) || $vars->two_factor_key == '') {
            $vars->two_factor_key = $this->Users->systemHash(mt_rand() . md5(mt_rand()), null, 'sha1');
        }

        $vars->two_factor_key_base32 = $base32->encode(pack('H*', $vars->two_factor_key));

        $this->set('vars', $vars);
        $this->set('groups', $formatted_staff_groups);
        $this->set('two_factor_modes', $this->Users->getOtpModes());
    }

    /**
     * Updates the status of a staff member
     */
    public function status()
    {
        // Redirect if staff ID not given, staff is changing his own status,
        // or status is invalid
        if (empty($this->post['id'])
            || !($staff = $this->Staff->get((int) $this->post['id']))
            || empty($this->post['status'])
            || !$this->Staff->validateStatus($this->post['status'])
            || $staff->id == $this->Session->read('blesta_staff_id')
        ) {
            $this->redirect($this->base_uri . 'settings/system/staff/manage/');
        }

        // Update the status of this staff member
        $vars = ['status' => $this->post['status']];
        $this->Staff->edit($staff->id, $vars);

        $this->flashMessage('message', Language::_('AdminSystemStaff.!success.staff_updated', true));
        $this->redirect(
            $this->base_uri . 'settings/system/staff/manage/'
            . ($this->post['status'] == 'inactive' ? 'active' : 'inactive')
        );
    }

    /**
     * Staff Groups
     */
    public function groups()
    {
        // Set current page of results
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'name');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        // Get staff groups
        $staff_groups = $this->StaffGroups->getList(null, $page, [$sort => $order]);
        $total_results = $this->StaffGroups->getListCount(null);

        $this->set('groups', $staff_groups);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'settings/system/staff/groups/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Add Staff Group
     */
    public function addGroup()
    {
        $this->uses(['Permissions']);
        $vars = new stdClass();

        // Add Staff Group
        if (!empty($this->post)) {
            if (empty($this->post['session_lock'])) {
                $this->post['session_lock'] = '0';
            }

            $this->StaffGroups->add($this->post);

            if (($errors = $this->StaffGroups->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                $this->setMessage('error', $errors);
            } else {
                // Success
                $this->flashMessage(
                    'message',
                    Language::_('AdminSystemStaff.!success.group_added', true, $this->post['name'])
                );
                $this->redirect($this->base_uri . 'settings/system/staff/groups/');
            }
        }

        $this->set('vars', $vars);

        // Format companies list
        $this->set('companies', $this->ArrayHelper->numericToKey($this->Companies->getAll(), 'id', 'name'));
        // Fetch all permissions
        $this->set('permissions', $this->Permissions->getAll('staff', $this->company_id));
        $this->set('bcc_notices', $this->getEmailGroups('bcc'));
        $this->set('subscription_notices', $this->getEmailGroups('to'));
        $this->set('notifications', $this->getNotifications());
    }

    /**
     * Edit Staff Group
     */
    public function editGroup()
    {
        $this->uses(['Permissions']);

        // Ensure we have a valid staff group
        if (!isset($this->get[0]) || !($staff_group = $this->StaffGroups->get((int) $this->get[0]))) {
            $this->redirect($this->base_uri . 'settings/system/staff/groups/');
        }

        $vars = null;

        // Edit Staff Group
        if (!empty($this->post)) {
            if (empty($this->post['session_lock'])) {
                $this->post['session_lock'] = '0';
            }

            $this->StaffGroups->edit($staff_group->id, $this->post);

            if (($errors = $this->StaffGroups->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                $this->setMessage('error', $errors);
            } else {
                // Success
                $this->flashMessage(
                    'message',
                    Language::_('AdminSystemStaff.!success.group_updated', true, $this->post['name'])
                );
                $this->redirect($this->base_uri . 'settings/system/staff/editgroup/' . $staff_group->id);
            }
        }

        // Set initial staff group
        if (!$vars) {
            $vars = $staff_group;

            // Set the permissions for this group
            $permissions = $this->Permissions->fromAcl(
                'staff_group_' . $staff_group->id,
                'staff',
                $staff_group->company_id
            );

            if ($permissions) {
                $vars->permission_group = $permissions->permission_group;
                $vars->permission = $permissions->permission;
            }

            // Set email notices
            $notices = (!empty($vars->notices) ? $vars->notices : []);
            $vars->notices = [];
            foreach ($notices as $notice) {
                $vars->notices[] = $notice->action;
            }

            // Set notifications
            $notifications = (!empty($vars->notifications) ? $vars->notifications : []);
            $vars->notifications = [];
            foreach ($notifications as $notification) {
                $vars->notifications[] = $notification->action;
            }
        }

        // Determine if this staff group is assigned to the current staff member - need to
        // caution them about making changes
        $users_staff_group = $this->StaffGroups->getStaffGroupByStaff(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );

        if ($users_staff_group && $users_staff_group->id == $staff_group->id) {
            $this->set('is_assigned_group', true);
        }

        $this->set('vars', $vars);

        // Format companies list
        $this->set('companies', $this->ArrayHelper->numericToKey($this->Companies->getAll(), 'id', 'name'));
        // Fetch all permissions
        $this->set('permissions', $this->Permissions->getAll('staff', $staff_group->company_id));
        $this->set('bcc_notices', $this->getEmailGroups('bcc'));
        $this->set('subscription_notices', $this->getEmailGroups('to'));
        $this->set('notifications', $this->getNotifications());
    }

    /**
     * Deletes a staff group
     */
    public function deleteGroup()
    {
        if (empty($this->post['id']) || !($staff_group = $this->StaffGroups->get((int) $this->post['id']))) {
            $this->redirect($this->base_uri . 'settings/system/staff/groups/');
        }

        // Delete the staff group
        $this->StaffGroups->delete($staff_group->id);

        if (($errors = $this->StaffGroups->errors())) {
            // Error, could not delete the staff group
            $this->flashMessage('error', $errors);
        } else {
            // Success, staff group deleted
            $this->flashMessage(
                'message',
                Language::_('AdminSystemStaff.!success.group_deleted', true, (isset($staff_group->name) ? $this->Html->safe($staff_group->name) : null))
            );
        }
        $this->redirect($this->base_uri . 'settings/system/staff/groups/');
    }

    /**
     * Sets the assigned and available staff groups. Manipulates the given parameters by reference.
     * @see AdminSystemStaff::addGroup() and AdminSystemStaff::editGroup()
     *
     * @param array $staff_groups A key=>value array of staff groups containing group id => name
     * @param array $assigned_groups A numerically indexed array of staff group IDs
     */
    private function assignGroups(&$staff_groups, &$assigned_groups)
    {
        $temp_groups = array_flip($assigned_groups);
        $groups = [];

        // Find any assigned groups from the list of staff groups and set them
        foreach ($staff_groups as $id => $name) {
            if (isset($temp_groups[$id])) {
                // Set this group as an assigned group
                $groups[$id] = $name;
                // Remove this group from the list of available staff groups
                unset($staff_groups[$id]);
            }
        }
        $assigned_groups = $groups;
    }

    /**
     * Retrieves a list of email groups
     *
     * @param string $type The notice type of the email groups to fetch
     * @return array A list of email groups
     */
    private function getEmailGroups($type)
    {
        // Get all client email groups
        $this->uses(['EmailGroups']);
        Language::loadLang('admin_company_emails');

        // Fetch the core and plugin email groups
        $email_groups = array_merge(
            $this->EmailGroups->getAllByNoticeType($type),
            $this->EmailGroups->getAllByNoticeType($type, null, false)
        );

        // Update the list of email groups by action
        foreach ($email_groups as &$email_group) {
            // Load plugin language
            if ($email_group->plugin_dir !== null) {
                Language::loadLang(
                    'admin_company_emails',
                    null,
                    PLUGINDIR . $email_group->plugin_dir . DS . 'language' . DS
                );
            }

            $email_group->lang = Language::_('AdminCompanyEmails.templates.' . $email_group->action . '_name', true);
            $email_group->lang_description = Language::_(
                'AdminCompanyEmails.templates.' . $email_group->action . '_desc',
                true
            );
        }

        return $email_groups;
    }

    /**
     * Fetches all the notification actions, loads the notification language
     * definitions, and translates notification names and descriptions.
     *
     * @param string|null $type The action type: 'system', 'plugin', 'module', or null for all (optional)
     * @return array An array of processed notification objects with translated labels
     */
    private function getNotifications(?string $type = null)
    {
        $this->uses(['StaffGroups', 'Notifications']);

        // Get company notifications
        $notifications = $this->Notifications->getActions('staff');

        if (!empty($notifications)) {
            Language::loadLang('notifications');

            $actions = [];
            foreach ($notifications as &$notification) {
                if (!is_null($type) && $notification->type !== $type) {
                    continue;
                }

                // Load language file from plugin or module
                if (!empty($notification->dir)) {
                    $dir = match ($notification->type) {
                        'plugin' => PLUGINDIR . $notification->dir . DS . 'language' . DS,
                        'module' => COMPONENTDIR . 'modules' . DS . $notification->dir . DS . 'language' . DS,
                        default => ROOTWEBDIR . 'language' . DS
                    };

                    Language::loadLang('notifications', null, $dir);
                }

                // Set translated labels
                $notification->lang = Language::_(
                    'Notifications.notification.' . $notification->action . '_name',
                    true
                );
                $notification->lang_description = Language::_(
                    'Notifications.notification.' . $notification->action . '_desc',
                    true
                );

                $actions[] = $notification;
            }

            return $actions;
        }

        return [];
    }
}
