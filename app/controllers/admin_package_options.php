<?php

/**
 * Admin Package Options Management
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminPackageOptions extends AppController
{
    /**
     * Packages pre-action  
     */
    public function preAction()
    {
        parent::preAction();

        // Require login
        $this->requireLogin();

        $this->uses(['PackageOptionGroups', 'PackageOptions']);
        $this->helpers(['DataStructure']);

        // Create Array Helper
        $this->ArrayHelper = $this->DataStructure->create('Array');

        Language::loadLang(['admin_package_options']);
    }

    /**
     * List package option groups/options
     */
    public function index()
    {
        // Handle AJAX JSON actions routed through index
        if ($this->isAjax() && !empty($this->post['ajax_action'])) {
            switch ($this->post['ajax_action']) {
                case 'assign_option':
                    return $this->doAssignOption();
                case 'sort_options':
                    return $this->doSortOptions();
            }
        }

        // Set filters from post input
        $post_filters = [];
        if (isset($this->post['filters'])) {
            $post_filters = $this->post['filters'];
            unset($this->post['filters']);

            foreach($post_filters as $filter => $value) {
                if (empty($value)) {
                    unset($post_filters[$filter]);
                }
            }
        }

        $option_filters = array_merge(['hidden' => 0], $post_filters);

        // Load editor data for the view
        $this->setEditorViewVars($option_filters);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Deletes the given package option groups
     *
     * @param array $data An array of POST data including:
     *
     *  - package_group_ids An array of each package option group ID
     *  - action The action to perform, e.g. "delete_package_groups"
     * @return mixed An array of errors, or false otherwise
     */
    private function updatePackageGroups(array $data)
    {
        // Require authorization to update a client's package
        if (!$this->authorized('admin_package_options', '*')) {
            $this->flashMessage('error', Language::_('AppController.!error.unauthorized_access', true));
            $this->redirect($this->base_uri . 'packages/');
        }

        // Only include package group IDs in the list
        $package_group_ids = [];
        if (isset($data['package_group_ids'])) {
            foreach ((array) $data['package_group_ids'] as $package_group_id) {
                if (is_numeric($package_group_id)) {
                    $package_group_ids[] = $package_group_id;
                }
            }
        }

        $data['package_group_ids'] = $package_group_ids;
        $data['action'] = ($data['action'] ?? null);
        $errors = false;

        switch ($data['action']) {
            case 'delete_package_groups':
                $this->PackageOptionGroups->begin();
                foreach ($data['package_group_ids'] as $package_group_id) {
                    // Redirect if invalid package ID given
                    if (!($package_group = $this->PackageOptionGroups->get((int) $package_group_id))
                        || ($package_group->company_id != $this->company_id)
                    ) {
                        $this->redirect($this->base_uri . 'package_options/');
                    }

                    // Attempt to delete the package option group
                    $this->PackageOptionGroups->delete($package_group->id);

                    if (($errors = $this->PackageOptionGroups->errors())) {
                        $this->PackageOptionGroups->rollback();

                        return $errors;
                    }
                }
                $this->PackageOptionGroups->commit();
                break;
        }

        return $errors;
    }

    /**
     * Deletes the given package options
     *
     * @param array $data An array of POST data including:
     *
     *  - package_group_ids An array of each package options ID
     *  - action The action to perform, e.g. "delete_package_options"
     * @return mixed An array of errors, or false otherwise
     */
    private function updatePackageOptions(array $data)
    {
        // Require authorization to update a client's package
        if (!$this->authorized('admin_package_options', '*')) {
            $this->flashMessage('error', Language::_('AppController.!error.unauthorized_access', true));
            $this->redirect($this->base_uri . 'packages/');
        }

        // Only include package option IDs in the list
        $package_option_ids = [];
        if (isset($data['package_option_ids'])) {
            foreach ((array) $data['package_option_ids'] as $package_option_id) {
                if (is_numeric($package_option_id)) {
                    $package_option_ids[] = $package_option_id;
                }
            }
        }

        $data['package_option_ids'] = $package_option_ids;
        $data['action'] = ($data['action'] ?? null);
        $errors = false;

        switch ($data['action']) {
            case 'delete_package_options':
                $this->uses(['Services']);
                $this->PackageOptions->begin();
                foreach ($data['package_option_ids'] as $package_option_id) {
                    // Redirect if invalid package ID given
                    if (!($package_option = $this->PackageOptions->get((int) $package_option_id))
                        || ($package_option->company_id != $this->company_id)
                    ) {
                        $this->redirect($this->base_uri . 'package_options/');
                    }

                    // Check if any option values are in use by a service
                    if (!empty($package_option->values)) {
                        foreach ($package_option->values as $value) {
                            if ($this->Services->isServiceOptionValueInUse($value->id)) {
                                $this->PackageOptions->rollback();

                                return [
                                    'in_use' => ['option' => Language::_('AdminPackageOptions.!error.option_delete_in_use', true)]
                                ];
                            }
                        }
                    }

                    // Attempt to delete the package option
                    $this->PackageOptions->delete($package_option->id);

                    if (($errors = $this->PackageOptions->errors())) {
                        $this->PackageOptions->rollback();

                        return $errors;
                    }
                }
                $this->PackageOptions->commit();
                break;
        }

        return $errors;
    }

    /**
     * AJAX Fetch the expandable row section for package options
     */
    public function optionInfo()
    {
        // Ensure we have a valid package option
        if (!$this->isAjax()
            || !isset($this->get[0])
            || !($package_option = $this->PackageOptions->get($this->get[0]))
            || $package_option->company_id != $this->company_id
        ) {
            header($this->server_protocol . ' 403 Forbidden');
            exit();
        }

        // Set the package option
        $vars = [
            'package_option' => $package_option,
            'value_statuses' => $this->PackageOptions->getValueStatuses()
        ];

        echo $this->outputAsJson($this->partial('admin_package_options_optioninfo', $vars));
        return false;
    }

    /**
     * AJAX Fetch the expandable row section for package option groups
     */
    public function groupInfo()
    {
        // Ensure we have a valid package option group
        if (!$this->isAjax()
            || !isset($this->get[0])
            || !($package_group = $this->PackageOptionGroups->get($this->get[0]))
            || $package_group->company_id != $this->company_id
        ) {
            header($this->server_protocol . ' 403 Forbidden');
            exit();
        }

        // Fetch all the package options in this group
        $vars = [
            'package_options' => $this->PackageOptionGroups->getAllOptions($package_group->id, ['hidden' => (int) $package_group->hidden]),
            'package_group' => $package_group
        ];

        echo $this->outputAsJson($this->partial('admin_package_options_groupinfo', $vars));
        return false;
    }

    /**
     * Adds a package option
     */
    public function add()
    {
        $this->uses(['Currencies', 'Packages']);

        $vars = new stdClass();

        if (!empty($this->post)) {
            $data = $this->post;
            $data['company_id'] = $this->company_id;

            // Format the option values and pricing arrays
            $data = $this->formatOptionValueData($data);

            // Handle image uploads
            $upload_warnings = [];
            $data = $this->handleValueImageUploads($data, $upload_warnings);

            // Sync uploaded filenames back to post data so they survive form re-render on error
            $this->syncImageDataToPost($data);

            $option_id = $this->PackageOptions->add($data);

            if (($errors = $this->PackageOptions->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                if ($this->isAjax()) {
                    $this->outputAsJson(['success' => false, 'errors' => $errors]);
                    return false;
                }
                $this->setMessage('error', $errors);
            } else {
                // Success
                $message = Language::_('AdminPackageOptions.!success.option_added', true);
                if ($this->isAjax()) {
                    $response = [
                        'success' => true,
                        'option_id' => $option_id,
                        'message' => $message
                    ];
                    if (!empty($upload_warnings)) {
                        $response['warnings'] = array_unique($upload_warnings);
                    }
                    $this->outputAsJson($response);
                    return false;
                }
                $this->flashMessage('message', $message);
                $this->redirect($this->base_uri . 'package_options/index/options/');
            }
        }

        // Get default currency
        $default_currency = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'default_currency');
        $default_currency = $default_currency['value'];

        // Set default currency as the selected currency
        if (empty($this->post)) {
            $vars->values = ['pricing' => [['currency' => [$default_currency]]]];
        }

        // Fetch all available package option groups
        $package_option_groups = $this->Form->collapseObjectArray(
            $this->PackageOptionGroups->getAll($this->company_id, ['hidden' => 0]),
            'name',
            'id'
        );

        // Set all selected package option groups in assigned and unset all selected groups from available
        if (isset($vars->groups) && is_array($vars->groups)) {
            $selected = [];

            foreach ($package_option_groups as $id => $name) {
                if (in_array($id, $vars->groups)) {
                    $selected[$id] = $name;
                    unset($package_option_groups[$id]);
                }
            }

            $vars->groups = $selected;
        }

        $please_select = ['' => Language::_('AppController.select.please', true)];
        $this->set('types', $please_select + $this->PackageOptions->getTypes());
        $this->set('value_statuses', $this->PackageOptions->getValueStatuses());
        $this->set(
            'currencies',
            $this->Form->collapseObjectArray($this->Currencies->getAll($this->company_id), 'code', 'code')
        );
        $this->set('default_currency', $default_currency);
        $this->set('periods', $this->Packages->getPricingPeriods());
        $this->set('package_option_groups', $package_option_groups);
        $this->set('vars', $vars);
        $this->set('in_modal', $this->isAjax());
        $this->set('option_image_url', WEBDIR . 'uploads/options/');

        // Return just the view HTML for modal loading (no page layout)
        if ($this->isAjax()) {
            echo $this->view->fetch('admin_package_options_add');
            return false;
        }
    }

    /**
     * Updates a package option
     */
    public function edit()
    {
        // Ensure a valid package option was given
        if (!isset($this->get[0])
            || !($option = $this->PackageOptions->get($this->get[0]))
            || $option->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'package_options/index/options/');
        }

        $this->uses(['Currencies', 'Packages']);

        if (!empty($this->post)) {
            // Format the option values and pricing arrays
            $data = $this->formatOptionValueData($this->post);

            // Handle image uploads
            $upload_warnings = [];
            $data = $this->handleValueImageUploads($data, $upload_warnings);

            // Sync uploaded filenames back to post data so they survive form re-render on error
            $this->syncImageDataToPost($data);

            $option_id = $this->PackageOptions->edit($option->id, $data);

            if (($errors = $this->PackageOptions->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                if ($this->isAjax()) {
                    $this->outputAsJson(['success' => false, 'errors' => $errors]);
                    return false;
                }
                $this->setMessage('error', $errors);
            } else {
                // Success
                $message = Language::_('AdminPackageOptions.!success.option_updated', true);
                if ($this->isAjax()) {
                    $response = [
                        'success' => true,
                        'option_id' => $option->id,
                        'message' => $message
                    ];
                    if (!empty($upload_warnings)) {
                        $response['warnings'] = array_unique($upload_warnings);
                    }
                    $this->outputAsJson($response);
                    return false;
                }
                $this->flashMessage('message', $message);
                $this->redirect($this->base_uri . 'package_options/edit/' . $option->id . '/');
            }
        }

        // Check if any option values are in use by a service (must run before
        // values are reformatted from objects to arrays below)
        $this->uses(['Services']);
        $option_values_in_use = false;
        if (!empty($option->values)) {
            foreach ($option->values as $value) {
                if ($this->Services->isServiceOptionValueInUse($value->id)) {
                    $option_values_in_use = true;
                    break;
                }
            }
        }

        // Set initial option data
        if (!isset($vars)) {
            $vars = $option;

            // Format the values, pricing, and groups to HTML-style arrays
            if (property_exists($vars, 'values')) {
                foreach ($vars->values as $index => &$value) {
                    if (property_exists($value, 'pricing')) {
                        foreach ($value->pricing as $pricing_index => &$price) {
                            $vars->values[$index]->pricing[$pricing_index] = (array) $price;
                        }
                        $vars->values[$index]->pricing = $this->ArrayHelper->numericToKey(
                            $vars->values[$index]->pricing
                        );
                    }
                    $vars->values[$index] = (array) $value;
                }
                $vars->values = $this->ArrayHelper->numericToKey($vars->values);
            }

            if (property_exists($vars, 'groups')) {
                foreach ($vars->groups as $key => $group) {
                    $vars->groups[$key] = $group->id;
                }
            }
        }

        // Get default currency
        $default_currency = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'default_currency');
        $default_currency = $default_currency['value'];

        // Fetch all available package option groups
        $package_option_groups = $this->Form->collapseObjectArray(
            $this->PackageOptionGroups->getAll($this->company_id, ['hidden' => (int) $option->hidden]),
            'name',
            'id'
        );

        // Ensure already-assigned hidden groups are included so they aren't silently dropped on save
        if (!$option->hidden && isset($vars->groups) && is_array($vars->groups)) {
            foreach ($vars->groups as $group_id) {
                if (!isset($package_option_groups[$group_id])
                    && ($group = $this->PackageOptionGroups->get($group_id))
                    && $group->company_id == $this->company_id
                ) {
                    $package_option_groups[$group_id] = $group->name;
                }
            }
        }

        // Set all selected package option groups in assigned and unset all selected groups from available
        if (isset($vars->groups) && is_array($vars->groups)) {
            $selected = [];

            foreach ($package_option_groups as $id => $name) {
                if (in_array($id, $vars->groups)) {
                    $selected[$id] = $name;
                    unset($package_option_groups[$id]);
                }
            }

            $vars->groups = $selected;
        }

        $please_select = ['' => Language::_('AppController.select.please', true)];
        $this->set('types', $please_select + $this->PackageOptions->getTypes());
        $this->set('value_statuses', $this->PackageOptions->getValueStatuses());
        $this->set(
            'currencies',
            $this->Form->collapseObjectArray($this->Currencies->getAll($this->company_id), 'code', 'code')
        );
        $this->set('default_currency', $default_currency);
        $this->set('periods', $this->Packages->getPricingPeriods());
        $this->set('package_option_groups', $package_option_groups);
        $this->set('vars', $vars);
        $this->set('option_values_in_use', $option_values_in_use);
        $this->set('in_modal', $this->isAjax());
        $this->set('option_image_url', WEBDIR . 'uploads/options/');

        // Return just the view HTML for modal loading (no page layout)
        if ($this->isAjax()) {
            echo $this->view->fetch('admin_package_options_edit');
            return false;
        }
    }

    /**
     * Deletes a package option
     */
    public function delete()
    {
        // Ensure a valid package option was given
        if (!isset($this->post['id'])
            || !($option = $this->PackageOptions->get($this->post['id']))
            || $option->company_id != $this->company_id
        ) {
            if ($this->isAjax()) {
                $this->outputAsJson(['success' => false, 'errors' => ['invalid' => ['option' => 'Invalid option']]]);
                return false;
            }
            $this->redirect($this->base_uri . 'package_options/index/options/');
        }

        // Check if any option values are in use by a service
        $this->uses(['Services']);
        $in_use = false;
        if (!empty($option->values)) {
            foreach ($option->values as $value) {
                if ($this->Services->isServiceOptionValueInUse($value->id)) {
                    $in_use = true;
                    break;
                }
            }
        }

        if ($in_use) {
            if ($this->isAjax()) {
                $this->outputAsJson([
                    'success' => false,
                    'errors' => ['in_use' => ['option' => Language::_('AdminPackageOptions.!error.option_delete_in_use', true)]]
                ]);
                return false;
            }
            $this->flashMessage('error', Language::_('AdminPackageOptions.!error.option_delete_in_use', true));
            $this->redirect($this->base_uri . 'package_options/index/options/');
        }

        $this->PackageOptions->delete($option->id);

        if ($this->isAjax()) {
            $this->outputAsJson([
                'success' => true,
                'message' => Language::_('AdminPackageOptions.!success.option_deleted', true)
            ]);
            return false;
        }

        $this->flashMessage('message', Language::_('AdminPackageOptions.!success.option_deleted', true));
        $this->redirect($this->base_uri . 'package_options/index/options/');
    }

    /**
     * Removes a package option from a package option group
     */
    public function remove()
    {
        // Ensure a valid package option and group were given
        if (!isset($this->post['option_id'])
            || !isset($this->post['group_id'])
            || !($option = $this->PackageOptions->get($this->post['option_id']))
            || !($group = $this->PackageOptionGroups->get($this->post['group_id']))
        ) {
            if ($this->isAjax()) {
                $this->outputAsJson(['success' => false, 'errors' => ['invalid' => ['input' => 'Invalid option or group']]]);
                return false;
            }
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->PackageOptions->removeFromGroup($option->id, [$group->id]);

        if ($this->isAjax()) {
            $this->outputAsJson([
                'success' => true,
                'message' => Language::_(
                    'AdminPackageOptions.!success.option_removed_from_group',
                    true,
                    $option->label,
                    $group->name
                )
            ]);
            return false;
        }

        $this->flashMessage('message', Language::_('AdminPackageOptions.!success.option_removed', true));
        $this->redirect($this->base_uri . 'package_options/');
    }

    /**
     * Add package option group
     */
    public function addGroup()
    {
        $this->uses(['Packages']);

        $vars = new stdClass();

        // Add the package option group
        if (!empty($this->post)) {
            // Import package option group
            if (isset($this->post['import'])) {
                // Check whether a file has been uploaded
                $data = [];
                if (empty($this->files['import_file'])
                    || !isset($this->files['import_file']['error'])
                    || $this->files['import_file']['error'] != 0
                    || !($file_contents = file_get_contents($this->files['import_file']['tmp_name']))
                ) {
                    $import_errors = [
                        'import_file' => [
                            'missing' => Language::_('AdminPackageOptions.!error.import_file.missing', true)
                        ]
                    ];
                    if ($this->isAjax()) {
                        $this->outputAsJson(['success' => false, 'errors' => $import_errors]);
                        return false;
                    }
                    $this->setMessage('error', $import_errors);
                } else {
                    // Set the imported data
                    $data = (array) json_decode($file_contents, true);
                }

                // Import group
                if (!empty($data)) {
                    $this->PackageOptionGroups->import($data, $this->company_id);

                    if (($errors = $this->PackageOptionGroups->errors())) {
                        // Error, reset vars
                        if ($this->isAjax()) {
                            $this->outputAsJson(['success' => false, 'errors' => $errors]);
                            return false;
                        }
                        $this->setMessage('error', $errors);
                        $vars = (object) $this->post;
                    } else {
                        // Success
                        if ($this->isAjax()) {
                            $this->outputAsJson([
                                'success' => true,
                                'message' => Language::_('AdminPackageOptions.!success.group_added', true)
                            ]);
                            return false;
                        }
                        $this->flashMessage('message', Language::_('AdminPackageOptions.!success.group_added', true));
                        $this->redirect($this->base_uri . 'package_options/');
                    }
                }
            }

            // Add package option group
            if (isset($this->post['save'])) {
                $data = $this->post;
                $data['company_id'] = $this->company_id;
                $group_id = $this->PackageOptionGroups->add($data);

                if (($errors = $this->PackageOptionGroups->errors())) {
                    // Error, reset vars
                    $vars = (object) $this->post;
                    if ($this->isAjax()) {
                        $this->outputAsJson(['success' => false, 'errors' => $errors]);
                        return false;
                    }
                    $this->setMessage('error', $errors);
                } else {
                    // Success
                    if ($this->isAjax()) {
                        $this->outputAsJson([
                            'success' => true,
                            'group_id' => $group_id,
                            'message' => Language::_('AdminPackageOptions.!success.group_added', true)
                        ]);
                        return false;
                    }
                    $this->flashMessage('message', Language::_('AdminPackageOptions.!success.group_added', true));
                    $this->redirect($this->base_uri . 'package_options/');
                }
            }
        }

        // Fetch all available packages
        $packages = $this->Form->collapseObjectArray($this->Packages->getAll($this->company_id, ['name' => 'ASC'], null, null, ['hidden' => 1]), 'name', 'id');

        $vars->packages = (isset($vars->packages) && is_array($vars->packages) ? $vars->packages : []);
        $vars->packages = $this->getSelectedSwappableOptions($packages, $vars->packages);

        $this->set('vars', $vars);
        $this->set('packages', $packages);
        $this->set('in_modal', $this->isAjax());

        // Return just the view HTML for modal loading (no page layout)
        if ($this->isAjax()) {
            echo $this->view->fetch('admin_package_options_addgroup');
            return false;
        }
    }

    /**
     * Updates a package option group
     */
    public function editGroup()
    {
        // Ensure a valid package option group was given
        if (!isset($this->get[0])
            || !($group = $this->PackageOptionGroups->get($this->get[0]))
            || $group->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->uses(['Packages']);
        $vars = $group;

        // Update the package option group
        if (!empty($this->post)) {
            $data = $this->post;
            $this->PackageOptionGroups->edit($group->id, $data);

            if (($errors = $this->PackageOptionGroups->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                if ($this->isAjax()) {
                    $this->outputAsJson(['success' => false, 'errors' => $errors]);
                    return false;
                }
                $this->setMessage('error', $errors);
            } else {
                // Success
                if ($this->isAjax()) {
                    $this->outputAsJson([
                        'success' => true,
                        'group_id' => $group->id,
                        'message' => Language::_('AdminPackageOptions.!success.group_updated', true)
                    ]);
                    return false;
                }
                $this->flashMessage('message', Language::_('AdminPackageOptions.!success.group_updated', true));
                $this->redirect($this->base_uri . 'package_options/editgroup/' . $group->id . '/');
            }
        }

        // Fetch all available packages
        $packages = $this->Form->collapseObjectArray($this->Packages->getAll($this->company_id, ['name' => 'ASC'], null, null, ['hidden' => 1]), 'name', 'id');

        $vars->packages = (isset($vars->packages) && is_array($vars->packages) ? $vars->packages : []);
        $vars->packages = $this->getSelectedSwappableOptions($packages, $vars->packages);

        $this->set('vars', $vars);
        $this->set('packages', $packages);
        $this->set('in_modal', $this->isAjax());

        // Return just the view HTML for modal loading (no page layout)
        if ($this->isAjax()) {
            echo $this->view->fetch('admin_package_options_editgroup');
            return false;
        }
    }

    /**
     * Exports a package option group
     */
    public function exportGroup()
    {
        // Ensure a valid package option group was given
        if (!isset($this->get[0])
            || !($group = $this->PackageOptionGroups->get($this->get[0]))
            || $group->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->components(['Download']);

        $export = $this->PackageOptionGroups->export($group->id);

        $export_name = strtolower(str_replace(' ', '_', $export->name));
        $export_name = substr(preg_replace('/[^a-z0-9_-]/i', '', $export_name), 0, 249);
        $this->Download->downloadData('package-option-group-' . $export_name . '.json', json_encode($export, JSON_PRETTY_PRINT));
        exit;
    }

    /**
     * Deletes a package option group
     */
    public function deleteGroup()
    {
        // Ensure a valid package option group was given
        if (!isset($this->post['id'])
            || !($group = $this->PackageOptionGroups->get($this->post['id']))
            || $group->company_id != $this->company_id
        ) {
            if ($this->isAjax()) {
                $this->outputAsJson(['success' => false, 'errors' => ['invalid' => ['group' => 'Invalid group']]]);
                return false;
            }
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->PackageOptionGroups->delete($group->id);

        if ($this->isAjax()) {
            $this->outputAsJson([
                'success' => true,
                'message' => Language::_('AdminPackageOptions.!success.group_deleted', true)
            ]);
            return false;
        }

        $this->flashMessage('message', Language::_('AdminPackageOptions.!success.group_deleted', true));
        $this->redirect($this->base_uri . 'package_options/');
    }

    /**
     * Manages configurable options logic
     */
    public function logic()
    {
        $this->uses(['PackageOptionConditions', 'PackageOptionConditionSets']);

        if (!isset($this->get[0])
            || !($group = $this->PackageOptionGroups->get($this->get[0]))
            || $group->company_id != $this->company_id
        ) {
            if ($this->isAjax()) {
                header($this->server_protocol . ' 403 Forbidden');
                exit();
            }
            $this->redirect($this->base_uri . 'package_options/');
        }

        // Get the option for the current option
        $group_options = $this->PackageOptionGroups->getAllOptions($group->id);
        $package_options = $group_options;
        $option = null;
        foreach ($group_options as $key => $group_option) {
            if (!isset($this->get[1]) || $group_option->id == $this->get[1]) {
                $option = $this->PackageOptions->get($group_option->id);
                unset($package_options[$key]);
                break;
            }
        }

        // No options for which to set logic
        if (!$option) {
            if ($this->isAjax()) {
                header($this->server_protocol . ' 403 Forbidden');
                exit();
            }
            $this->redirect($this->base_uri . 'package_options/');
        }

        // Get the current condition sets for this option
        $condition_sets = $this->PackageOptionConditionSets->getAll(
            ['option_id' => $option->id, 'option_group_id' => $group->id]
        );
        $set_ids = array_map(function ($value) { return $value->id;}, $condition_sets);
        $sets_by_id = array_combine($set_ids, $condition_sets);

        $vars = (object) [];
        if (!empty($this->post)) {
            $post = (object) $this->post;
            $errors = null;

            // Start transaction for adding conditions and sets
            $this->PackageOptionConditionSets->begin();
            foreach (($post->condition_sets ?? []) as $condition_set) {
                $conditions_by_id = [];

                // If no option_value_ids are submitted, set an empty list
                if (!isset($condition_set['option_value_ids'])) {
                    $condition_set['option_value_ids'] = [];
                }

                // Add or edit condition set
                if (!empty($condition_set['id'])) {
                    $set_id = $this->PackageOptionConditionSets->edit($condition_set['id'], $condition_set);

                    if (isset($sets_by_id[$condition_set['id']])) {
                        // Get current condtions in this set
                        $condition_ids = array_map(
                            function ($value) { return $value->id; },
                            $sets_by_id[$condition_set['id']]->conditions
                        );
                        $conditions_by_id = array_combine(
                            $condition_ids,
                            $sets_by_id[$condition_set['id']]->conditions
                        );

                        unset($sets_by_id[$set_id]);
                    }
                } else {
                    $set_id = $this->PackageOptionConditionSets->add($condition_set);
                    $condition_set['id'] = $set_id;
                }

                // Abort the whole process if we encounter an error
                if (($errors = $this->PackageOptionConditionSets->errors())) {
                    break;
                }

                $set_object = (object) $condition_set;
                foreach (($set_object->conditions ?? []) as $condition) {
                    $condition['condition_set_id'] = $set_object->id;
                    // Add or edit condition set
                    if (!empty($condition['id'])) {
                        $this->PackageOptionConditions->edit($condition['id'], $condition);

                        unset($conditions_by_id[$condition['id']]);
                    } else {
                        $this->PackageOptionConditions->add($condition);
                    }

                    // Abort the whole process if we encounter an error
                    if (($errors = $this->PackageOptionConditions->errors())) {
                        break 2;
                    }
                }

                // Delete any conditions that were not included
                foreach ($conditions_by_id as $delete_condition) {
                    $this->PackageOptionConditions->delete($delete_condition->id);
                }
            }

            // Delete any condition sets that were not included
            foreach ($sets_by_id as $delete_set) {
                $this->PackageOptionConditionSets->delete($delete_set->id);
            }

            if ($errors) {
                // Rollback all database changes and reset vars to the view
                $this->PackageOptionConditionSets->rollback();
                $vars = $this->formatLogicVars($this->post);

                // Set error message
                $this->setMessage('error', $errors);
            } else {
                // Commit all database changes
                $this->PackageOptionConditionSets->commit();

                if ($this->isAjax()) {
                    $this->outputAsJson([
                        'success' => true,
                        'message' => Language::_('AdminPackageOptions.!success.logic_updated', true)
                    ]);
                    return false;
                }

                // Success message and redirect
                $this->flashMessage('message', Language::_('AdminPackageOptions.!success.logic_updated', true));
                $this->redirect($this->base_uri . 'package_options/');
            }
        } else {
            $vars->condition_sets = $condition_sets;
        }

        // Count condition sets per option for tab badges
        $option_set_counts = [];
        foreach ($group_options as $go) {
            $sets = $this->PackageOptionConditionSets->getAll(
                ['option_id' => $go->id, 'option_group_id' => $group->id]
            );
            $option_set_counts[$go->id] = count($sets);
        }

        $this->set('vars', $vars);
        $this->set('group', $group);
        $this->set('option', $option);
        $this->set(
            'operators',
            ['' => Language::_('AppController.select.please', true)] + $this->PackageOptionConditions->getOperators()
        );
        $this->set('group_options', $this->Form->collapseObjectArray($group_options, 'label', 'id'));
        $this->set(
            'package_options',
            ['' => Language::_('AppController.select.please', true)] + $this->Form->collapseObjectArray($package_options, 'label', 'id')
        );
        $this->set('option_set_counts', $option_set_counts);
        $this->set('in_modal', $this->isAjax());
        $this->set('hide_options', $group->hide_options ?? '0');

        // Return just the view HTML for modal loading (no page layout)
        if ($this->isAjax()) {
            echo $this->view->fetch('admin_package_options_logic');
            return false;
        }

        return $this->renderAjaxWidgetIfAsync(false);
    }

    /**
     * Updates a package option group
     */
    public function logicSettings()
    {
        // Ensure a valid package option group was given
        if (!isset($this->get[0])
            || !($group = $this->PackageOptionGroups->get($this->get[0]))
            || $group->company_id != $this->company_id
        ) {
            if ($this->isAjax()) {
                header($this->server_protocol . ' 403 Forbidden');
                exit();
            }
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->uses(['Packages']);
        $vars = $group;

        // Update the package option group
        if (!empty($this->post)) {
            $data = $this->post;
            if (!isset($data['hide_options'])) {
                $data['hide_options'] = '0';
            }

            $this->PackageOptionGroups->edit($group->id, $data);

            if (($errors = $this->PackageOptionGroups->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                if ($this->isAjax()) {
                    $this->outputAsJson(['success' => false, 'errors' => $errors]);
                    return false;
                }
                $this->setMessage('error', $errors);
            } else {
                // Success
                if ($this->isAjax()) {
                    $this->outputAsJson([
                        'success' => true,
                        'message' => Language::_('AdminPackageOptions.!success.group_updated', true)
                    ]);
                    return false;
                }
                $this->flashMessage('message', Language::_('AdminPackageOptions.!success.group_updated', true));
                $this->redirect($this->base_uri . 'package_options/logic/' . $group->id);
            }
        }

        $this->set('vars', $vars);
        $this->set('group', $group);
    }

    /**
     * Formats logic post data to be returned to the view
     *
     * @param array $post The post data to be formatted
     * @return stdClass Formatted post data
     */
    private function formatLogicVars(array $post) {
        $vars = (object) $post;
        foreach (($vars->condition_sets ?? []) as $index => $condition_set) {
            $object_condition_set = (object) $condition_set;
            foreach (($object_condition_set->conditions ?? []) as $condition_index => $condition) {
                $condition['triggering_option'] = $this->PackageOptions->get(($condition['trigger_option_id']?? null));
                $object_condition_set->conditions[$condition_index] = (object) $condition;
            }

            if (!empty($object_condition_set->conditions)) {
                $object_condition_set->conditions = array_values($object_condition_set->conditions);
            }
            $vars->condition_sets[$index] = $object_condition_set;
        }

        if (!empty($vars->condition_sets)) {
            $vars->condition_sets = array_values($vars->condition_sets);
        }

        return $vars;
    }

    /**
     * Outputs the given package option as a json object
     */
    public function getOption()
    {
        if (!$this->isAjax()
            || !isset($this->get[0])
            || !($option = $this->PackageOptions->get($this->get[0]))
        ) {
            $this->redirect($this->base_uri . 'package_options/');
        }

        $this->outputAsJson($option);
        return false;
    }

    /**
     * Order package options within a group
     */
    private function doSortOptions()
    {
        $this->PackageOptionGroups->orderOptions($this->post['group_id'] ?? null, $this->post['options'] ?? []);
        $this->outputAsJson(['success' => true]);
        return false;
    }

    /**
     * Order package option values within an option
     */
    public function orderValues()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'package_options/');
        }

        if (!empty($this->post)) {
            $this->PackageOptions->orderValues($this->post['option_id'], $this->post['values']);
        }
        return false;
    }

    /**
     * AJAX: Add an option to a group (drag-and-drop / paste)
     */
    private function doAssignOption()
    {
        $option_id = $this->post['option_id'] ?? null;
        $group_id = $this->post['group_id'] ?? null;

        // Validate the option and group exist and belong to this company
        if (!$option_id
            || !$group_id
            || !($option = $this->PackageOptions->get($option_id))
            || $option->company_id != $this->company_id
            || !($group = $this->PackageOptionGroups->get($group_id))
            || $group->company_id != $this->company_id
        ) {
            $this->outputAsJson(['success' => false, 'errors' => ['invalid' => ['input' => 'Invalid option or group']]]);
            return false;
        }

        $result = $this->PackageOptionGroups->addOptionToGroup($option->id, $group->id);

        if (!$result) {
            $this->outputAsJson([
                'success' => false,
                'message' => Language::_('AdminPackageOptions.!warning.option_already_in_group', true)
            ]);
            return false;
        }

        $this->outputAsJson([
            'success' => true,
            'message' => Language::_(
                'AdminPackageOptions.!success.option_added_to_group',
                true,
                $option->label,
                $group->name
            )
        ]);
        return false;
    }

    /**
     * AJAX endpoint returning lightweight editor state as JSON
     */
    public function editorData()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'package_options/');
        }

        $filters = array_merge(['hidden' => 0], $this->post['filters'] ?? []);
        $this->outputAsJson($this->buildEditorData($filters));
        return false;
    }

    /**
     * Sets additional view variables needed by the Paradigm theme editor
     */
    private function setEditorViewVars(array $filters = [])
    {
        $editor_data = $this->buildEditorData($filters);

        // Type icons mapping
        $type_icons = [
            'select' => 'bi-menu-button-wide',
            'radio' => 'bi-ui-radios',
            'checkbox' => 'bi-ui-checks',
            'quantity' => 'bi-123',
            'text' => 'bi-input-cursor-text',
            'textarea' => 'bi-textarea-t',
            'password' => 'bi-lock'
        ];

        // Get default currency (used by editor JS for new pricing rows)
        $default_currency = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'default_currency');

        $this->set('all_options', $editor_data['options']);
        $this->set('all_groups', $editor_data['groups']);
        $this->set('editor_stats', $editor_data['stats']);
        $this->set('type_icons', $type_icons);
        $this->set('editor_default_currency', $default_currency['value']);
    }

    /**
     * Builds the editor data array with options, groups, and stats
     *
     * @return array The editor data
     */
    private function buildEditorData(array $filters = [])
    {
        // Load all options and groups with 2 queries (no N+1)
        $all_options_raw = $this->PackageOptions->getAll($this->company_id, $filters);
        $all_groups_raw = $this->PackageOptionGroups->getAll($this->company_id, $filters);

        // Index options by ID for fast lookup
        $options_by_id = [];
        foreach ($all_options_raw as $opt) {
            $options_by_id[$opt->id] = $opt;
        }

        // Single query to fetch all group-option assignments at once
        $group_ids = array_map(function ($g) {
            return $g->id;
        }, $all_groups_raw);

        // Single query to fetch all group-option assignments at once via model's Record
        $assignments = [];
        if (!empty($group_ids)) {
            $record = $this->PackageOptionGroups->Record->select(
                ['package_option_group.*', 'package_options.hidden']
            )
                ->from('package_option_group')
                ->innerJoin(
                    'package_options',
                    'package_options.id',
                    '=',
                    'package_option_group.option_id',
                    false
                )
                ->where('package_option_group.option_group_id', 'in', $group_ids)
                ->order(['package_option_group.option_group_id' => 'ASC', 'package_option_group.order' => 'ASC']);

            if (isset($filters['hidden']) && !(bool) $filters['hidden']) {
                $record->where('package_options.hidden', '=', 0);
            }

            $assignments = $record->fetchAll();
        }

        // Build option-to-groups map and group-to-options map from assignments
        $option_group_map = [];
        $group_options_map = [];
        foreach ($assignments as $row) {
            $option_group_map[$row->option_id][] = $row->option_group_id;
            $group_options_map[$row->option_group_id][] = $row->option_id;
        }

        // Build group data using the maps
        $groups_with_options = [];
        foreach ($all_groups_raw as $group) {
            $group_data = [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description ?? '',
                'options' => []
            ];
            foreach ($group_options_map[$group->id] ?? [] as $option_id) {
                if (isset($options_by_id[$option_id])) {
                    $opt = $options_by_id[$option_id];
                    $group_data['options'][] = [
                        'id' => $opt->id,
                        'label' => $opt->label,
                        'name' => $opt->name,
                        'type' => $opt->type,
                        'group_count' => count($option_group_map[$opt->id] ?? [])
                    ];
                }
            }
            $groups_with_options[] = $group_data;
        }

        // Build option summaries
        $options_summary = [];
        $unassigned = 0;
        foreach ($all_options_raw as $opt) {
            $groups = $option_group_map[$opt->id] ?? [];
            $options_summary[] = [
                'id' => $opt->id,
                'label' => $opt->label,
                'name' => $opt->name,
                'type' => $opt->type,
                'groups' => $groups
            ];
            if (empty($groups)) {
                $unassigned++;
            }
        }

        return [
            'stats' => [
                'options_count' => count($options_summary),
                'groups_count' => count($groups_with_options),
                'unassigned_count' => $unassigned
            ],
            'options' => $options_summary,
            'groups' => $groups_with_options
        ];
    }

    /**
     * Formats the option values and pricing data submitted when adding or editing an option
     *
     * @param array $vars A list of input vars
     * @return array A list of formatted input vars
     */
    private function formatOptionValueData(array $vars)
    {
        $checkboxes = ['addable', 'editable', 'disable_pricing', 'hide_on_invoice'];
        foreach ($checkboxes as $checkbox) {
            if (!isset($vars[$checkbox])) {
                $vars[$checkbox] = 0;
            }
        }

        // Format the array data
        if (isset($vars['values']) && is_array($vars['values'])) {
            $vars['values'] = $this->ArrayHelper->keyToNumeric($this->post['values']);

            foreach ($vars['values'] as &$value) {
                if (empty($value['id'])) {
                    $value['id'] = null;
                }

                // Preserve existing image when no new file uploaded
                if (isset($value['existing_image'])) {
                    $value['image'] = $value['existing_image'] ?: null;
                    unset($value['existing_image']);
                }

                // Format the pricing data
                if (isset($value['pricing']) && is_array($value['pricing'])) {
                    $value['pricing'] = $this->ArrayHelper->keyToNumeric($value['pricing']);
                }

                // Quantity types should always have a value of NULL. Non-quantity types must have
                // a non-null value, and null min/max/step
                if (isset($vars['type']) && $vars['type'] == 'quantity') {
                    $value['value'] = null;

                    // The max value may be null (unlimited) if blank
                    $value['max'] = (isset($value['max']) && $value['max'] != '' ? $value['max'] : null);
                } else {
                    $value['value'] = (isset($value['value']) ? $value['value'] : '');
                    $value['min'] = null;
                    $value['max'] = null;
                    $value['step'] = null;
                }

                if (isset($value['pricing'])) {
                    foreach ($value['pricing'] as &$pricing) {
                        // Set a null ID so that new pricings can be properly added
                        if (empty($pricing['id'])) {
                            $pricing['id'] = null;
                        }

                        // Make sure there is no renewal price set for onetime pricings
                        if (array_key_exists('period', (array)$pricing) && $pricing['period'] == 'onetime') {
                            $pricing['price_renews'] = null;
                            $pricing['price_enable_renews'] = '0';
                        }
                    }
                }

                // Set the default checkbox value for 'default' if unchecked
                if (isset($vars['type']) && $vars['type'] == 'checkbox') {
                    $value['default'] = (isset($value['default']) ? $value['default'] : '0');
                }
            }
        }
        return $vars;
    }

    /**
     * Syncs uploaded image filenames from the processed $data back into $this->post
     * so that form re-rendering on validation error preserves the uploaded images
     *
     * @param array $data The processed option data (after handleValueImageUploads)
     */
    private function syncImageDataToPost(array $data)
    {
        if (empty($data['values'])) {
            return;
        }

        foreach ($data['values'] as $i => $value) {
            $image = $value['image'] ?? '';
            $this->post['values']['image'][$i] = $image;
            $this->post['values']['existing_image'][$i] = $image;
        }
    }

    /**
     * Retrieves a list of selected options for the swappable multi-select, and removes them from the available groups
     *
     * @param array &$available_groups A reference to a key/value array of all the available groups to choose from
     * @param array $selected_groups A key/value array of all the selected groups
     * @return array A key/value indexed array subset of the $available_groups that have been selected
     */
    private function getSelectedSwappableOptions(array &$available_groups, array $selected_groups)
    {
        $selected = [];

        foreach ($available_groups as $id => $name) {
            if (in_array($id, $selected_groups)) {
                $selected[$id] = $name;
                unset($available_groups[$id]);
            }
        }

        return $selected;
    }

    /**
     * Handles image file uploads for option values
     *
     * @param array $data The formatted option data
     * @param array &$upload_warnings Collects non-fatal warning messages for failed uploads
     * @return array The option data with image filenames set
     */
    private function handleValueImageUploads(array $data, array &$upload_warnings = [])
    {
        if (!isset($data['values']) || !is_array($data['values'])) {
            return $data;
        }

        // Build the upload path
        $temp = $this->SettingsCollection->fetchSetting(
            null,
            $this->company_id,
            'uploads_dir'
        );
        $upload_path = $temp['value'] . $this->company_id . DS . 'package_options' . DS;

        // Allowed MIME types and signatures for content-based validation
        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89PNG\r\n\x1A\n"],
            'image/gif' => ["GIF87a", "GIF89a"],
            'image/webp' => ["RIFF"]
        ];

        foreach ($data['values'] as $i => &$value) {
            // Handle explicit image removal
            if (!empty($value['remove_image'])) {
                // Delete old file
                if (!empty($value['image']) && file_exists($upload_path . $value['image'])) {
                    @unlink($upload_path . $value['image']);
                }
                $value['image'] = null;
                unset($value['remove_image']);
                continue;
            }
            unset($value['remove_image']);

            // Check if a file was uploaded for this value index
            $file_key = 'values_image_' . $i;
            if (
                !isset($_FILES[$file_key])
                || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            $file = $_FILES[$file_key];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $upload_warnings[] = Language::_('AdminPackageOptions.!error.value_image.upload', true);
                continue;
            }

            // Validate MIME from file contents
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected_mime = $finfo->file($file['tmp_name']);

            if (!isset($allowed_mimes[$detected_mime])) {
                $upload_warnings[] = Language::_('AdminPackageOptions.!error.value_image.type', true);
                continue;
            }

            // Validate magic bytes
            if (!$this->validateImageSignature($file['tmp_name'], $detected_mime, $signatures)) {
                $upload_warnings[] = Language::_('AdminPackageOptions.!error.value_image.type', true);
                continue;
            }

            // Generate unique filename
            $ext = $allowed_mimes[$detected_mime];
            $filename = uniqid() . '_' . preg_replace(
                '/[^a-zA-Z0-9._-]/',
                '',
                pathinfo($file['name'], PATHINFO_FILENAME)
            ) . '.' . $ext;

            // Create upload directory if it doesn't exist
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }

            // Move uploaded file, then delete old image only on success
            $old_image = $value['image'] ?? null;
            if (move_uploaded_file($file['tmp_name'], $upload_path . $filename)) {
                $value['image'] = $filename;

                // Delete the replaced file after the new one is safely written
                if (!empty($old_image) && $old_image !== $filename && file_exists($upload_path . $old_image)) {
                    @unlink($upload_path . $old_image);
                }
            } else {
                $upload_warnings[] = Language::_('AdminPackageOptions.!error.value_image.upload', true);
            }
        }

        return $data;
    }

    /**
     * Validates that a file's magic bytes match the expected MIME type
     *
     * @param string $file_path Path to the file
     * @param string $mime The detected MIME type
     * @param array $signatures Map of MIME types to expected magic byte sequences
     * @return bool True if the signature is valid
     */
    private function validateImageSignature($file_path, $mime, array $signatures)
    {
        if (!isset($signatures[$mime])) {
            return false;
        }

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false) {
            return false;
        }

        foreach ($signatures[$mime] as $signature) {
            if (strncmp($header, $signature, strlen($signature)) === 0) {
                // Additional check for WebP: bytes 8-11 must be "WEBP"
                if ($mime === 'image/webp' && substr($header, 8, 4) !== 'WEBP') {
                    return false;
                }
                return true;
            }
        }

        return false;
    }
}
