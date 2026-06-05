<?php
use Blesta\Core\Util\Filters\PackageFilters;
use Blesta\Core\Util\Filters\PackageGroupFilters;
use Blesta\Core\Util\Input\Fields\Html as FieldsHtml;

/**
 * Admin Packages Management
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminPackages extends AppController
{
    /**
     * Packages pre-action
     */
    public function preAction()
    {
        parent::preAction();

        // Require login
        $this->requireLogin();

        $this->uses(['Packages', 'PackageGroups']);
        $this->helpers(['DataStructure']);

        // Create Array Helper
        $this->ArrayHelper = $this->DataStructure->create('Array');

        Language::loadLang(['admin_packages']);
    }

    /**
     * List packages
     */
    public function index()
    {
        if (!empty($this->post) && isset($this->post['package_ids'])) {
            if (($errors = $this->updatePackages($this->post))) {
                $this->set('vars', (object) $this->post);
                $this->setMessage('error', $errors);
            } else {
                $term = 'AdminPackages.!success.packages_deleted';
                $this->setMessage('message', Language::_($term, true));
            }
        }

        // Set current page of results
        $status = (isset($this->get[0]) ? $this->get[0] : 'active');
        $page = (isset($this->get[1]) ? (int) $this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'id_code');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

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

        if (isset($post_filters['package_group_id'])
            && ($package_group = $this->PackageGroups->get($post_filters['package_group_id']))
        ) {
            $post_filters['hidden'] = $package_group->hidden;
        }

        // Set the number of packages of each type
        $status_count = [
            'active' => $this->Packages->getListCount('active', $post_filters),
            'inactive' => $this->Packages->getListCount('inactive', $post_filters),
            'restricted' => $this->Packages->getListCount('restricted', $post_filters)
        ];

        // Set the input field filters for the widget
        $package_filters = new PackageFilters();
        $this->set(
            'filters',
            $package_filters->getFilters(
                ['language' => Configure::get('Blesta.language'), 'company_id' => Configure::get('Blesta.company_id')],
                $post_filters
            )
        );

        // Build service actions
        $actions = [
            'delete_packages' => Language::_('AdminPackages.index.action.delete', true)
        ];

        $this->set('filter_vars', $post_filters);
        $this->set('status', $status);
        $this->set('status_count', $status_count);
        $this->set('packages', $this->Packages->getList($page, [$sort => $order], $status, $post_filters));
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('actions', $actions);
        $total_results = $this->Packages->getListCount($status, $post_filters);

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'packages/index/' . $status . '/[p]/',
                'params' => ['sort' => $sort, 'order' => $order],
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Deletes the given packages
     *
     * @param array $data An array of POST data including:
     *
     *  - package_ids An array of each package ID
     *  - action The action to perform, e.g. "delete_packages"
     * @return mixed An array of errors, or false otherwise
     */
    private function updatePackages(array $data)
    {
        // Require authorization to update a client's package
        if (!$this->authorized('admin_packages', 'delete')) {
            $this->flashMessage('error', Language::_('AppController.!error.unauthorized_access', true));
            $this->redirect($this->base_uri . 'packages/');
        }

        // Only include package IDs in the list
        $package_ids = [];
        if (isset($data['package_ids'])) {
            foreach ((array) $data['package_ids'] as $package_id) {
                if (is_numeric($package_id)) {
                    $package_ids[] = $package_id;
                }
            }
        }

        $data['package_ids'] = $package_ids;
        $data['action'] = (isset($data['action']) ? $data['action'] : null);
        $errors = false;

        switch ($data['action']) {
            case 'delete_packages':
                $this->Packages->begin();
                foreach ($data['package_ids'] as $package_id) {
                    // Redirect if invalid package ID given
                    if (!($package = $this->Packages->get((int) $package_id))
                        || ($package->company_id != $this->company_id)
                    ) {
                        $this->redirect($this->base_uri . 'packages/');
                    }

                    // Attempt to delete the package
                    $this->Packages->delete($package->id, true);

                    if (($errors = $this->Packages->errors())) {
                        $this->Packages->rollback();
                        return $errors;
                    }
                }
                $this->Packages->commit();
                break;
        }

        return $errors;
    }

    /**
     * AJAX request for all pricing details for a package
     */
    public function packagePricing()
    {
        if (!isset($this->get[0])) {
            exit();
        }

        $package = $this->Packages->get((int) $this->get[0]);

        // Ensure the package exists and this is an ajax request
        if (!$this->isAjax() || !$package) {
            header($this->server_protocol . ' 403 Forbidden');
            exit();
        }

        // Set language for periods
        $periods = $this->Packages->getPricingPeriods();
        foreach ($this->Packages->getPricingPeriods(true) as $period => $lang) {
            $periods[$period . '_plural'] = $lang;
        }

        $vars = [
            'pricing' => $package->pricing,
            'periods' => $periods
        ];

        // Send the template
        echo $this->partial('admin_packages_packagepricing', $vars);

        // Render without layout
        return false;
    }

    /**
     * List module options for ajax requests
     */
    public function moduleOptions()
    {
        if (!$this->isAjax() || !isset($this->post['module_id'])) {
            return false;
        }

        $this->uses(['ModuleManager']);
        $this->components(['Modules']);

        if (!isset($this->post['module_row'])) {
            $this->post['module_row'] = 0;
        }
        if (!isset($this->post['module_group'])) {
            $this->post['module_group'] = 'select';
        }
        if (!isset($this->post['module_group_client'])) {
            $this->post['module_group_client'] = 0;
        }

        $module = $this->ModuleManager->initModule($this->post['module_id'], $this->company_id);

        if (!$module) {
            return false;
        }

        // Fetch all package fields this module requires
        $module->base_uri = $this->base_uri;
        $package_fields = $module->getPackageFields((object) $this->post);
        $fields = $package_fields->getFields();
        $html = $package_fields->getHtml();
        $input_html = new FieldsHtml($package_fields);
        $tags = $module->getEmailTags();
        $template = $module->getEmailTemplate();

        // Fetch the parser options to determine the start and end characters for template variables
        $parser_options = Configure::get('Blesta.parser_options');

        $module_email_tags = '';
        if (!empty($tags)) {
            $i = 0;
            foreach ($tags as $group => $group_tags) {
                foreach ($group_tags as $tag) {
                    $module_email_tags .= ($i++ > 0 ? ' ' : '') .
                        $parser_options['VARIABLE_START'] . $group . '.' . $tag . $parser_options['VARIABLE_END'];
                }
            }
        }

        $groups = $this->ArrayHelper->numericToKey(
            (array) $this->ModuleManager->getGroups($this->post['module_id']),
            'id',
            'name'
        );
        $rows = $this->ArrayHelper->numericToKey(
            (array) $this->ModuleManager->getRows($this->post['module_id']),
            'id',
            'meta'
        );

        $row_key = $module->moduleRowMetaKey();
        foreach ($rows as $key => &$value) {
            $value = $value->$row_key;
        }

        $group_name = $module->moduleGroupName();
        $data = [
            'module_options' => $this->partial(
                'admin_packages_moduleoptions',
                [
                    'input_html' => $input_html,
                    'fields' => $fields,
                    'group_name' => (empty($group_name)
                        ? Language::_('AdminPackages.moduleoptions.not_applicable', true)
                        : $group_name
                    ),
                    'groups' => $groups,
                    'row_name' => $module->moduleRowName(),
                    'rows' => $rows,
                    'vars' => (object) $this->post
                ]
            ),
            'module_email_tags' => $module_email_tags,
            'module_template' => $template
        ];

        $this->outputAsJson($data);
        return false;
    }

    /**
     * AJAX Fetch all modules (json encoded ajax request)
     */
    public function getModules()
    {
        $this->uses(['ModuleManager']);
        $modules = ['' => Language::_('AppController.select.please', true)] + (array)$this->Form->collapseObjectArray(
            $this->ModuleManager->getAll($this->company_id),
            'name',
            'id'
        );

        echo json_encode($modules);
        return false;
    }

    /**
     * Add package
     */
    public function add()
    {
        $this->uses(['Companies', 'Currencies', 'Languages', 'ModuleManager', 'PackageOptionGroups']);
        $this->components(['SettingsCollection']);

        $vars = new stdClass();

        // Copy a package
        if (isset($this->get[0])
            && ($package = $this->Packages->get((int) $this->get[0]))
            && $package->company_id == $this->company_id
        ) {
            $vars = $package;
            // Set pricing to the correct format
            $vars->pricing = $this->ArrayHelper->numericToKey($vars->pricing);

            // Set package groups and option groups to the correct format
            $vars->groups = $this->Form->collapseObjectArray($vars->groups, 'id');
            $vars->module_groups = $this->Form->collapseObjectArray($vars->module_groups, 'id');
            $vars->option_groups = $this->Form->collapseObjectArray($vars->option_groups, 'id');
            $vars->plugins = $this->Form->collapseObjectArray($vars->plugins, 'plugin_id');
        }

        // Fetch all available package groups
        $package_groups = $this->Form->collapseObjectArray(
            $this->Packages->getAllGroups($this->company_id),
            'name',
            'id'
        );

        if (!empty($this->post)) {
            // Set company ID for this package
            $this->post['company_id'] = $this->company_id;
            // Set empty checkboxes
            if (!isset($this->post['taxable'])) {
                $this->post['taxable'] = 0;
            }
            if (!isset($this->post['single_term'])) {
                $this->post['single_term'] = 0;
            }
            if (!isset($this->post['upgrades_use_renewal'])) {
                $this->post['upgrades_use_renewal'] = 0;
            }
            if (!isset($this->post['override_price'])) {
                $this->post['override_price'] = 0;
            }
            if (!isset($this->post['module_group_client'])) {
                $this->post['module_group_client'] = 0;
            }
            if (!isset($this->post['manual_activation'])) {
                $this->post['manual_activation'] = 0;
            }

            // Begin transaction
            $this->Packages->begin();

            $data = $this->post;

            // Remove pro rata options
            if (!isset($data['prorata']) || $data['prorata'] != '1') {
                unset($data['prorata_day'], $data['prorata_cutoff']);
            }
            if (isset($data['prorata_cutoff']) && $data['prorata_cutoff'] == '') {
                unset($data['prorata_cutoff']);
            }

            // Attempt to add a package group if none are available
            $group_errors = [];
            if ((isset($data['select_group_type']) && $data['select_group_type'] == 'new')) {
                $this->uses(['PackageGroups']);
                $package_group_data = [
                    'names' => (isset($data['group_names']) ? $data['group_names'] : []),
                    'type' => 'standard',
                    'company_id' => $data['company_id']
                ];
                $new_package_group_id = $this->PackageGroups->add($package_group_data);

                $group_errors = $this->PackageGroups->errors();
            }

            // Attempt to add a package
            $package_errors = [];
            if (empty($group_errors)) {
                // Make package qty unlimited
                if (isset($data['qty_unlimited']) && $data['qty_unlimited'] == 'true') {
                    $data['qty'] = null;
                }

                // Make client package limit unlimited
                if (isset($data['client_qty_unlimited']) && $data['client_qty_unlimited'] == 'true') {
                    $data['client_qty'] = null;
                }

                // Add the new package group if created
                if (!empty($new_package_group_id)) {
                    $data['groups'] = [$new_package_group_id];
                }

                // A blank value, or the special value 'select' are not actual module groups
                if (isset($data['module_group']) && in_array($data['module_group'], ['', 'select'])) {
                    unset($data['module_group']);
                }

                $data['pricing'] = $this->ArrayHelper->keyToNumeric($this->post['pricing']);
                // Make sure there is no renewal price set for onetime pricings
                foreach ($data['pricing'] as &$prices) {
                    if (array_key_exists('period', (array)$prices) && $prices['period'] == 'onetime') {
                        $prices['price_renews'] = null;
                        $prices['price_enable_renews'] = '0';
                    }
                }

                $this->decodeEmailContentTemplateTags($data);
                $this->Packages->add($data);
                $package_errors = $this->Packages->errors();
            }

            $errors = array_merge(
                (!empty($group_errors) ? $group_errors : []),
                (!empty($package_errors) ? $package_errors : [])
            );

            if ($errors) {
                // Error
                $this->Packages->rollBack();
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;

                $array_fields = ['email_content', 'descriptions', 'names', 'group_names'];
                foreach ($array_fields as $array_field) {
                    // Set each datum to an object
                    if (isset($vars->{$array_field})) {
                        foreach ($vars->{$array_field} as &$data) {
                            $data = (object) $data;
                        }
                        unset($data);
                    }
                }
            } else {
                // Success
                $this->Packages->commit();
                $this->flashMessage('message', Language::_('AdminPackages.!success.package_added', true));
                $this->redirect($this->base_uri . 'packages/');
            }
        }

        // Get all settings
        $default_currency = $this->SettingsCollection->fetchSetting(
            $this->Companies,
            $this->company_id,
            'default_currency'
        );
        $default_currency = $default_currency['value'];

        // Get all currencies
        $currencies = $this->Form->collapseObjectArray($this->Currencies->getAll($this->company_id), 'code', 'code');

        // Set default currency as the selected currency
        if (empty($this->post) && empty($vars->pricing['currency'])) {
            $vars->pricing['currency'] = [$default_currency];
        }

        // Set all selected package groups
        $vars->groups = (isset($vars->groups) && is_array($vars->groups) ? $vars->groups : []);
        $vars->groups = $this->getSelectedSwappableOptions($package_groups, $vars->groups);

        // Fetch all available package option groups
        $package_option_groups = $this->Form->collapseObjectArray(
            $this->PackageOptionGroups->getAll($this->company_id, ['hidden' => 0]),
            'name',
            'id'
        );
        $vars->option_groups = (isset($vars->option_groups)
            && is_array($vars->option_groups) ? $vars->option_groups : []
        );
        $vars->option_groups = $this->getSelectedSwappableOptions($package_option_groups, $vars->option_groups);

        // Fetch all plugin groups
        $plugins = $this->Form->collapseObjectArray(
            $this->getPlugins($this->company_id),
            'name',
            'id'
        );
        $vars->plugins = (isset($vars->plugins) && is_array($vars->plugins) ? $vars->plugins : []);
        $vars->plugins = $this->getSelectedSwappableOptions($plugins, $vars->plugins);

        $module_ids = $this->Form->collapseObjectArray($this->ModuleManager->getAll($this->company_id), 'id', 'class');
        $this->set('prorata_days', $this->Packages->getProrataDays());
        $this->set('currencies', $currencies);
        $this->set('default_currency', $default_currency);
        $this->set(
            'modules',
            $this->Form->collapseObjectArray($this->ModuleManager->getAll($this->company_id), 'name', 'id')
        );
        $this->set('none_module_id', (isset($module_ids['none']) ? $module_ids['none'] : ''));
        $this->set('status', $this->Packages->getStatusTypes());
        $this->set('periods', $this->Packages->getPricingPeriods());
        $this->set('languages', $this->Languages->getAll($this->company_id));
        $this->set('package_groups', $package_groups);
        $this->set('package_option_groups', $package_option_groups);
        $this->set('plugins', $plugins);
        $this->set('vars', $vars);

        // Check if AI package description feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        $ai_feature_enabled = !empty($settings['ai_enabled'])
            && $settings['ai_enabled'] === 'true'
            && !empty($settings['ai_feature_package_descriptions'])
            && $settings['ai_feature_package_descriptions'] === 'true';
        $this->set('ai_feature_enabled', $ai_feature_enabled);

        $this->set('module_email_tags', $this->getWelcomeTags());

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);
    }

    /**
     * Edit package
     */
    public function edit()
    {
        $this->uses(['Companies', 'Currencies', 'Languages', 'ModuleManager', 'PackageOptionGroups']);
        $this->components(['SettingsCollection']);

        // Redirect if invalid package ID given
        if (empty($this->get[0])
            || !($package = $this->Packages->get((int) $this->get[0]))
            || $package->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'packages/');
        }

        $vars = $package;
        // Set pricing to the correct format
        $vars->pricing = $this->ArrayHelper->numericToKey($vars->pricing);

        // Set package groups and option groups to the correct format
        $vars->groups = $this->Form->collapseObjectArray($vars->groups, 'id');
        $vars->module_groups = $this->Form->collapseObjectArray($vars->module_groups, 'id');
        $vars->option_groups = $this->Form->collapseObjectArray($vars->option_groups, 'id');
        $vars->plugins = $this->Form->collapseObjectArray($vars->plugins, 'plugin_id');

        // Update the package
        if (!empty($this->post)) {
            // Set empty checkboxes
            if (!isset($this->post['taxable'])) {
                $this->post['taxable'] = 0;
            }
            if (!isset($this->post['single_term'])) {
                $this->post['single_term'] = 0;
            }
            if (!isset($this->post['upgrades_use_renewal'])) {
                $this->post['upgrades_use_renewal'] = 0;
            }
            if (!isset($this->post['override_price'])) {
                $this->post['override_price'] = 0;
            }
            if (!isset($this->post['module_group_client'])) {
                $this->post['module_group_client'] = 0;
            }
            if (!isset($this->post['manual_activation'])) {
                $this->post['manual_activation'] = 0;
            }

            // Remove pro rata options
            if (!isset($this->post['prorata']) || $this->post['prorata'] != '1') {
                $this->post['prorata_day'] = null;
                $this->post['prorata_cutoff'] = null;
            }

            // Remove blank pricing IDs
            $this->post['pricing'] = (isset($this->post['pricing']) ? (array) $this->post['pricing'] : []);
            if (isset($this->post['pricing']['id'])) {
                foreach ($this->post['pricing']['id'] as $key => $id) {
                    if (empty($id)) {
                        unset($this->post['pricing']['id'][$key]);
                    }
                }
            }

            $data = $this->post;

            // Remove pro rata options
            if (isset($data['prorata_cutoff']) && $data['prorata_cutoff'] == '') {
                $data['prorata_cutoff'] = null;
            }

            // Make package qty unlimited
            if (isset($data['qty_unlimited']) && $data['qty_unlimited'] == 'true') {
                $data['qty'] = null;
            }

            // Make client package limit unlimited
            if (isset($data['client_qty_unlimited']) && $data['client_qty_unlimited'] == 'true') {
                $data['client_qty'] = null;
            }

            // A blank value, or the special value 'select' are not actual module groups
            if (isset($data['module_group']) && in_array($data['module_group'], ['', 'select'])) {
                $data['module_group'] = null;
            }

            // Set to remove all configurable option groups if none given
            if (empty($data['option_groups'])) {
                $data['option_groups'] = [];
            }

            // Set to remove all package groups if none given
            if (empty($data['groups'])) {
                $data['groups'] = [];
            }

            // Convert pricing back to the desired format
            $data['pricing'] = $this->ArrayHelper->keyToNumeric($this->post['pricing']);

            foreach ($data['pricing'] as &$prices) {
                // Remove blank IDs. These will be added as new prices
                if (array_key_exists('id', (array)$prices) && empty($prices['id'])) {
                    unset($prices['id']);
                }

                // Make sure there is no renewal price set for onetime pricings
                if (array_key_exists('period', $prices) && $prices['period'] == 'onetime') {
                    $prices['price_renews'] = null;
                    $prices['price_enable_renews'] = '0';
                }
            }

            $this->decodeEmailContentTemplateTags($data);
            $this->Packages->edit($package->id, $data);
            unset($data);

            if (($errors = $this->Packages->errors())) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;

                $array_fields = ['email_content', 'descriptions', 'names'];
                foreach ($array_fields as $array_field) {
                    // Set each email to an object
                    if (isset($vars->{$array_field})) {
                        foreach ($vars->{$array_field} as &$data) {
                            $data = (object) $data;
                        }
                        unset($data);
                    }
                }

                // Restore removed terms
                Loader::loadComponents($this, ['Record']);
                foreach ($vars->pricing['id'] as $index => $pricing_id) {
                    if (!empty($vars->pricing['id'][$index]) && empty($vars->pricing['term'][$index])) {
                        $pricing = $this->Record->select(['pricings.*'])
                            ->from('pricings')
                            ->innerJoin('package_pricing', 'package_pricing.pricing_id', '=', 'pricings.id', false)
                            ->where('package_pricing.id', '=', $vars->pricing['id'][$index])
                            ->fetch();

                        $vars->pricing['term'][$index] = $pricing->term;
                        $vars->pricing['period'][$index] = $pricing->period;
                        $vars->pricing['price'][$index] = $pricing->price;
                        $vars->pricing['price_renews'][$index] = $pricing->price_renews;
                        $vars->pricing['price_transfer'][$index] = $pricing->price_transfer;
                        $vars->pricing['setup_fee'][$index] = $pricing->setup_fee;
                        $vars->pricing['cancel_fee'][$index] = $pricing->cancel_fee;
                        $vars->pricing['currency'][$index] = $pricing->currency;
                    }
                }
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminPackages.!success.package_updated', true));
                $this->redirect($this->base_uri . 'packages/edit/' . $package->id . '/');
            }
        }

        // Get all settings
        $default_currency = $this->SettingsCollection->fetchSetting(
            $this->Companies,
            $this->company_id,
            'default_currency'
        );
        $default_currency = $default_currency['value'];

        // Get all currencies
        $currencies = $this->Form->collapseObjectArray($this->Currencies->getAll($this->company_id), 'code', 'code');

        // Fetch all available package groups
        $package_groups = $this->Form->collapseObjectArray(
            $this->Packages->getAllGroups($this->company_id, null, null, ['hidden' => $package->hidden]),
            'name',
            'id'
        );

        $vars->groups = (isset($vars->groups) && is_array($vars->groups) ? $vars->groups : []);
        $vars->groups = $this->getSelectedSwappableOptions($package_groups, $vars->groups);

        // Fetch all available package option groups
        $package_option_groups = $this->Form->collapseObjectArray(
            $this->PackageOptionGroups->getAll($this->company_id, ['hidden' => (int) $package->hidden]),
            'name',
            'id'
        );
        $vars->option_groups = (isset($vars->option_groups)
            && is_array($vars->option_groups) ? $vars->option_groups : []
        );

        // Ensure already-assigned hidden groups are included so they aren't silently dropped on save
        if (!$package->hidden) {
            foreach ($vars->option_groups as $group_id) {
                if (!isset($package_option_groups[$group_id])
                    && ($group = $this->PackageOptionGroups->get($group_id))
                    && $group->company_id == $this->company_id
                ) {
                    $package_option_groups[$group_id] = $group->name;
                }
            }
        }

        $vars->option_groups = $this->getSelectedSwappableOptions($package_option_groups, $vars->option_groups);

        // Fetch all plugin groups
        $plugins = $this->Form->collapseObjectArray(
            $this->getPlugins($this->company_id),
            'name',
            'id'
        );
        $vars->plugins = (isset($vars->plugins) && is_array($vars->plugins) ? $vars->plugins : []);
        $vars->plugins = $this->getSelectedSwappableOptions($plugins, $vars->plugins);

        $this->set('has_services', $this->Packages->validateServiceExists($package->id));
        $this->set('prorata_days', $this->Packages->getProrataDays());
        $this->set('currencies', $currencies);
        $this->set('default_currency', $default_currency);
        $this->set(
            'modules',
            $this->Form->collapseObjectArray($this->ModuleManager->getAll($this->company_id), 'name', 'id')
        );
        $this->set('status', $this->Packages->getStatusTypes());
        $this->set('periods', $this->Packages->getPricingPeriods());
        $this->set('languages', $this->Languages->getAll($this->company_id));
        $this->set('package_groups', $package_groups);
        $this->set('package_option_groups', $package_option_groups);
        $this->set('plugins', $plugins);
        $this->set('vars', $vars);

        // Check if AI package description feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        $ai_feature_enabled = !empty($settings['ai_enabled'])
            && $settings['ai_enabled'] === 'true'
            && !empty($settings['ai_feature_package_descriptions'])
            && $settings['ai_feature_package_descriptions'] === 'true';
        $this->set('ai_feature_enabled', $ai_feature_enabled);

        $this->set('module_email_tags', $this->getWelcomeTags());

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);
    }

    /**
     * Delete package
     */
    public function delete()
    {
        // Redirect if invalid package ID given
        if (!isset($this->post['id'])
            || !($package = $this->Packages->get((int) $this->post['id']))
            || ($package->company_id != $this->company_id)
        ) {
            $this->redirect($this->base_uri . 'packages/');
        }

        // Attempt to delete the package
        $this->Packages->delete($package->id, true);

        if (($errors = $this->Packages->errors())) {
            // Error
            $this->flashMessage('error', $errors);
        } else {
            // Success
            $this->flashMessage('message', Language::_('AdminPackages.!success.package_deleted', true));
        }

        $this->redirect($this->base_uri . 'packages/');
    }

    /**
     * Package groups
     */
    public function groups()
    {
        // Set current page of results
        $type = (isset($this->get[0]) ? $this->get[0] : 'standard');
        $page = (isset($this->get[1]) ? (int) $this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'name');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

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

        // Get the count of each package group type
        $type_count = [
            'standard' => $this->PackageGroups->getTypeCount($this->company_id, 'standard', $post_filters),
            'addon' => $this->PackageGroups->getTypeCount($this->company_id, 'addon', $post_filters)
        ];

        // Fetch package groups
        $package_groups = $this->PackageGroups->getList($this->company_id, $page, $type, [$sort => $order], $post_filters);

        // Fetch packages belonging to each group
        foreach ($package_groups as &$group) {
            $group->packages = $this->Packages->getAllPackagesByGroup($group->id, null, ['hidden' => $group->hidden]);
        }

        // Set the input field filters for the widget
        $package_filters = new PackageGroupFilters();
        $this->set(
            'filters',
            $package_filters->getFilters(
                ['language' => Configure::get('Blesta.language'), 'company_id' => Configure::get('Blesta.company_id')],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('type', $type);
        $this->set('types', $this->PackageGroups->getTypes());
        $this->set('package_groups', $package_groups);
        $this->set('type_count', $type_count);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->PackageGroups->getListCount($this->company_id, $type),
                'uri' => $this->base_uri . 'packages/groups/' . $type . '/[p]/',
                'params' => ['sort' => $sort, 'order' => $order],
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Add a package group
     */
    public function addGroup()
    {
        $vars = new stdClass();

        // Get all standard groups (available groups)
        $standard_groups = $this->PackageGroups->getAll($this->company_id, 'standard');

        if (!empty($this->post)) {
            // Set the currenty company ID
            $this->post['company_id'] = $this->company_id;

            // Set checkboxes if not given
            if (!isset($this->post['allow_upgrades'])) {
                $this->post['allow_upgrades'] = '0';
            }

            // Add the package group
            $package_group_id = $this->PackageGroups->add($this->post);

            if (($errors = $this->PackageGroups->errors())) {
                // Error, reset vars
                unset($this->post['company_id']);
                $vars = (object) $this->post;
                $this->setMessage('error', $errors);

                // Format the parent groups
                if (!empty($vars->parents)) {
                    $parents = [];

                    foreach ($vars->parents as $parent_group_id) {
                        foreach ($standard_groups as $standard_group) {
                            if ($parent_group_id == $standard_group->id) {
                                $parents[] = (object) ['id' => $parent_group_id, 'name' => $standard_group->name];
                            }
                        }
                    }
                    $vars->parents = $parents;
                }
            } else {
                // Success
                $package_group = $this->PackageGroups->get($package_group_id);
                $this->flashMessage(
                    'message',
                    Language::_('AdminPackages.!success.group_added', true, $package_group->name)
                );
                $this->redirect($this->base_uri . 'packages/groups/');
            }
        }

        // Format the list of selected parent groups and available groups
        if (isset($vars->parents)) {
            foreach ($vars->parents as $parent_group) {
                // Remove standard groups that are currently selected as parent groups
                foreach ($standard_groups as $key => $standard_group) {
                    if ($parent_group->id == $standard_group->id) {
                        unset($standard_groups[$key]);
                    }
                }
            }

            $vars->parents = $this->Form->collapseObjectArray($vars->parents, 'name', 'id');
        }

        // Format the names and descriptions
        $vars->names = $this->arrayValuesToObject((array)(isset($vars->names) ? $vars->names : []));
        $vars->descriptions = $this->arrayValuesToObject((array)(isset($vars->descriptions) ? $vars->descriptions : []));

        $this->set('vars', $vars);
        $this->set('languages', $this->Languages->getAll($this->company_id));
        $this->set('group_types', $this->PackageGroups->getTypes());
        $this->set('standard_groups', $this->Form->collapseObjectArray($standard_groups, 'name', 'id'));
    }

    /**
     * Edit a package group
     */
    public function editGroup()
    {
        // Ensure a group has been given
        if (!isset($this->get[0])
            || !($group = $this->PackageGroups->get((int) $this->get[0]))
            || ($this->company_id != $group->company_id)
        ) {
            $this->redirect($this->base_uri . 'packages/groups/');
        }

        // Get all standard groups (available groups)
        $standard_groups = $this->PackageGroups->getAll($this->company_id, 'standard');

        // Remove this group itself from the list of available standard groups
        foreach ($standard_groups as $key => $standard_group) {
            if ($standard_group->id == $group->id) {
                unset($standard_groups[$key]);
                break;
            }
        }

        if (!empty($this->post)) {
            $this->post['company_id'] = $this->company_id;

            // Set checkboxes if not given
            if (!isset($this->post['allow_upgrades'])) {
                $this->post['allow_upgrades'] = '0';
            }

            $this->PackageGroups->edit($group->id, $this->post);

            if (($errors = $this->PackageGroups->errors())) {
                // Error
                $vars = (object) $this->post;
                $this->setMessage('error', $errors);

                // Format the parent groups
                if (!empty($vars->parents)) {
                    $parents = [];

                    foreach ($vars->parents as $parent_group_id) {
                        foreach ($standard_groups as $standard_group) {
                            if ($parent_group_id == $standard_group->id) {
                                $parents[] = (object) ['id' => $parent_group_id, 'name' => $standard_group->name];
                            }
                        }
                    }
                    $vars->parents = $parents;
                }
            } else {
                // Success
                $package_group = $this->PackageGroups->get($group->id);
                $this->flashMessage(
                    'message',
                    Language::_('AdminPackages.!success.group_updated', true, $package_group->name)
                );
                $this->redirect($this->base_uri . 'packages/editgroup/' . $group->id . '/');
            }
        } else {
            // Set the initial group
            $vars = $group;
        }

        // Format the list of selected parent groups and available groups
        if (isset($vars->parents)) {
            foreach ($vars->parents as $parent_group) {
                // Remove standard groups that are currently selected as parent groups
                foreach ($standard_groups as $key => $standard_group) {
                    if ($parent_group->id == $standard_group->id) {
                        unset($standard_groups[$key]);
                    }
                }
            }

            $vars->parents = $this->Form->collapseObjectArray($vars->parents, 'name', 'id');
        }

        // Format the names and descriptions
        $vars->names = $this->arrayValuesToObject((array)(isset($vars->names) ? $vars->names : []));
        $vars->descriptions = $this->arrayValuesToObject((array)(isset($vars->descriptions) ? $vars->descriptions : []));

        $this->set('vars', $vars);
        $this->set('languages', $this->Languages->getAll($this->company_id));
        $this->set('standard_groups', $this->Form->collapseObjectArray($standard_groups, 'name', 'id'));
        $this->set('group_types', $this->PackageGroups->getTypes());
    }

    /**
     * Converts the given array values to objects iff they are arrays
     *
     * @param array $arr The array whose array values to convert into objects
     * @return The original array with stdCLass object values for each array
     */
    private function arrayValuesToObject(array $arr)
    {
        foreach ($arr as $key => &$value) {
            if (is_array($value)) {
                $value = (object)$value;
            }
        }

        return $arr;
    }

    /**
     * Deletes a package group
     */
    public function deleteGroup()
    {
        // Ensure a group has been given
        if (!isset($this->post['id'])
            || !($group = $this->PackageGroups->get((int) $this->post['id']))
            || ($this->company_id != $group->company_id)
        ) {
            $this->redirect($this->base_uri . 'packages/groups/');
        }

        $this->PackageGroups->delete($group->id);

        $this->flashMessage('message', Language::_('AdminPackages.!success.group_deleted', true, $group->name));
        $this->redirect($this->base_uri . 'packages/groups/');
    }

    /**
     * Order packages within a package group
     */
    public function orderPackages()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'packages/groups/');
        }

        if (!empty($this->post)) {
            $this->Packages->orderPackages($this->post['group_id'], $this->post['packages']);
        }
        return false;
    }

    /**
     * Returns a string containing all welcome tags available by default to package welcome emails
     *
     * @return string A string containing all welcome tags available by default to package welcome emails
     */
    private function getWelcomeTags()
    {
        $this->uses(['Services']);

        // Fetch the parser options to determine the start and end characters for template variables
        $parser_options = Configure::get('Blesta.parser_options');

        // Build all tags available by default in the welcome email
        $module_email_tags = '';
        $tags = $this->Services->getWelcomeEmailTags();
        if (!empty($tags)) {
            $i = 0;
            foreach ($tags as $group => $group_tags) {
                foreach ($group_tags as $tag) {
                    $module_email_tags .= ($i++ > 0 ? ' ' : '') .
                        $parser_options['VARIABLE_START'] . $group . '.' . $tag . $parser_options['VARIABLE_END'];
                }
            }
        }
        return $module_email_tags;
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

        foreach ($selected_groups as $id) {
            if (array_key_exists($id, $available_groups)) {
                $selected[$id] = $available_groups[$id];
                unset($available_groups[$id]);
            }
        }

        return $selected;
    }

    /**
     * Retrieves a set of available plugins that support service tabs
     *
     * @param int $company_id The ID of the company to filter plugins for
     * @return array An array of plugins
     */
    private function getPlugins($company_id)
    {
        $this->uses(['PluginManager']);
        $this->components(['Plugins']);

        $plugins = [];
        $available_plugins = $this->PluginManager->getAll($company_id);

        foreach ($available_plugins as $available_plugin) {
            // The plugin must be enabled to be considered
            if (!$available_plugin->enabled) {
                continue;
            }

            try {
                $plugin = $this->Plugins->create($available_plugin->dir);
            } catch (Throwable $e) {
                continue;
            }

            // The plugin may be included if it supports service tabs
            if ($plugin->allowsServiceTabs()) {
                $plugins[] = $available_plugin;
            }
        }

        return $plugins;
    }

    /**
     * AJAX endpoint to generate package description using AI
     */
    public function generateDescription()
    {
        // Only accept AJAX POST requests
        if (!$this->isAjax()) {
            header('HTTP/1.0 403 Forbidden');
            return false;
        }

        $this->uses(['Settings']);
        $this->components(['SettingsCollection', 'BlestaAi']);

        $response = [
            'success' => false,
            'error' => 'Invalid request'
        ];

        // Check if AI is enabled and package descriptions feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (empty($settings['ai_enabled']) || $settings['ai_enabled'] !== 'true') {
            $response['error'] = Language::_('AdminPackages.ai.error_disabled', true);
            echo json_encode($response);
            return false;
        }

        if (empty($settings['ai_feature_package_descriptions'])
            || $settings['ai_feature_package_descriptions'] !== 'true') {
            $response['error'] = Language::_('AdminPackages.ai.error_feature_disabled', true);
            echo json_encode($response);
            return false;
        }

        try {
            $ai = new BlestaAi($settings['ai_api_key']);

            // Get parameters
            $prompt = $this->post['prompt'] ?? '';
            $package_name = $this->post['package_name'] ?? '';
            $module_name = $this->post['module_name'] ?? null;
            $pricing = $this->post['pricing'] ?? null;
            $language = $this->post['language'] ?? 'en_us';
            $generate_html = ($this->post['generate_html'] ?? 'true') === 'true';
            $generate_text = ($this->post['generate_text'] ?? 'true') === 'true';
            $tone = $this->post['tone'] ?? 'professional';

            // Determine output format
            $output_format = 'html'; // default
            if ($generate_text && !$generate_html) {
                $output_format = 'text';
            } elseif ($generate_html && !$generate_text) {
                $output_format = 'html';
            }

            if (empty($prompt)) {
                $response['error'] = Language::_('AdminPackages.ai.error_prompt_required', true);
                echo json_encode($response);
                return false;
            }

            // Parse pricing if provided
            $pricing_info = null;
            if ($pricing && is_string($pricing)) {
                $pricing_info = json_decode($pricing, true);
            }

            // Build context-aware system prompt
            $system_prompt = $this->buildPackageDescriptionSystemPrompt(
                $settings['ai_global_prompt'] ?? '',
                $package_name,
                $module_name,
                $pricing_info,
                $language,
                $tone,
                $output_format
            );

            // Create conversation
            $conversation_id = $ai->createConversation(
                Configure::get('Blesta.company_id'),
                $this->Session->read('blesta_staff_id'),
                $settings['ai_default_model'] ?? 'openai/gpt-5.2',
                'Package Description Generation - ' . date('Y-m-d H:i:s'),
                'package_description'
            );

            // Single API call to generate description in the requested format
            $user_prompt = "Generate a package description based on the following request:\n\n" . $prompt;

            $ai_response = $ai->chat($conversation_id, $user_prompt, [
                'system_prompt' => $system_prompt,
                'temperature' => 0.7
            ]);

            // Parse the JSON response with shared parser
            $parser = new \Blesta\Core\Util\AI\AiResponseParser();
            $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();

            $parsed = $parser->parse($ai_response['content'], ['feedback', 'description']);

            // Build response with the generated content in the appropriate field
            $response = [
                'success' => true,
                'feedback' => $parsed['feedback'] ?? null,
                'html' => null,
                'text' => null,
                'conversation_id' => $conversation_id,
                'raw' => $parsed['raw'] ?? null,
                'full_prompt' => "=== SYSTEM PROMPT ===\n" . $system_prompt . "\n\n=== USER PROMPT ===\n" . $user_prompt
            ];

            // Set the content in the appropriate field based on format
            if ($output_format === 'html') {
                $response['html'] = isset($parsed['description'])
                    ? $this->sanitizeHtml($parsed['description'])
                    : null;
            } else {
                $response['text'] = isset($parsed['description'])
                    ? $sanitizer->sanitizeText($parsed['description'])
                    : null;
            }

        } catch (Exception $e) {
            $response['error'] = $e->getMessage();
        }

        echo json_encode($response);
        return false;
    }

    /**
     * AJAX endpoint to preview the AI prompt without generating content
     */
    public function previewPrompt()
    {
        // Only accept AJAX POST requests
        if (!$this->isAjax()) {
            header('HTTP/1.0 403 Forbidden');
            return false;
        }

        $this->uses(['Settings']);
        $this->components(['SettingsCollection']);

        $response = [
            'success' => false,
            'error' => 'Invalid request'
        ];

        // Check if AI is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (empty($settings['ai_enabled']) || $settings['ai_enabled'] !== 'true') {
            $response['error'] = Language::_('AdminPackages.ai.error_disabled', true);
            echo json_encode($response);
            return false;
        }

        // Get parameters
        $prompt = $this->post['prompt'] ?? '';
        $package_name = $this->post['package_name'] ?? '';
        $module_name = $this->post['module_name'] ?? null;
        $pricing = $this->post['pricing'] ?? null;
        $language = $this->post['language'] ?? 'en_us';
        $generate_html = ($this->post['generate_html'] ?? 'true') === 'true';
        $generate_text = ($this->post['generate_text'] ?? 'true') === 'true';
        $tone = $this->post['tone'] ?? 'professional';
        $content_type = $this->post['content_type'] ?? 'description'; // 'description' or 'email'

        // Determine output format
        $output_format = 'html';
        if ($generate_text && !$generate_html) {
            $output_format = 'text';
        } elseif ($generate_html && !$generate_text) {
            $output_format = 'html';
        }

        // Parse pricing if provided
        $pricing_info = null;
        if ($pricing && is_string($pricing)) {
            $pricing_info = json_decode($pricing, true);
        }

        // Build the system prompt based on content type
        if ($content_type === 'email') {
            $email_tags = $this->post['email_tags'] ?? '';
            $system_prompt = $this->buildEmailSystemPrompt(
                $settings['ai_global_prompt'] ?? '',
                $package_name,
                $module_name,
                $email_tags,
                $language,
                $tone
            );
            $user_prompt = "Generate a welcome email based on the following request:\n\n" . $prompt;
        } else {
            $system_prompt = $this->buildPackageDescriptionSystemPrompt(
                $settings['ai_global_prompt'] ?? '',
                $package_name,
                $module_name,
                $pricing_info,
                $language,
                $tone,
                $output_format
            );
            $user_prompt = "Generate a package description based on the following request:\n\n" . $prompt;
        }

        $response = [
            'success' => true,
            'full_prompt' => "=== SYSTEM PROMPT ===\n" . $system_prompt . "\n\n=== USER PROMPT ===\n" . $user_prompt
        ];

        echo json_encode($response);
        return false;
    }

    /**
     * Builds a context-aware system prompt for package description generation
     *
     * @param string $global_prompt The global AI system prompt
     * @param string $package_name The package name
     * @param string $module_name The module name
     * @param array $pricing_info Array of pricing information
     * @param string $language The language code
     * @param string $tone The tone (professional, casual, technical)
     * @param string $output_format The output format ('html' or 'text')
     * @return string The formatted system prompt
     */
    private function buildPackageDescriptionSystemPrompt(
        $global_prompt,
        $package_name,
        $module_name,
        $pricing_info,
        $language,
        $tone,
        $output_format = 'html'
    ) {
        $prompt = $global_prompt . "\n\n";
        $prompt .= "You are generating package descriptions for Blesta, a billing and client management platform.\n";
        $prompt .= "Your task is to create compelling, professional package descriptions that highlight features and benefits.\n\n";

        $prompt .= "Package Context:\n";
        if ($package_name) {
            $prompt .= "- Name: " . $package_name . "\n";
        }
        if ($module_name) {
            $prompt .= "- Service Type/Module: " . $module_name . "\n";
        }
        if ($pricing_info && is_array($pricing_info) && count($pricing_info) > 0) {
            $prompt .= "- Pricing Tiers: " . implode(', ', array_filter($pricing_info)) . "\n";
        }

        $prompt .= "\nOutput Requirements:\n";
        $prompt .= "- Language: " . $language . "\n";
        $prompt .= "- Tone: " . $tone . "\n";
        $prompt .= "- Output format: " . $output_format . "\n";
        $prompt .= "- You MUST respond with valid JSON only, no other text\n\n";

        $prompt .= "Response Format (JSON):\n";
        $prompt .= "{\n";
        $prompt .= "  \"feedback\": \"Any clarifying questions, assumptions made, or suggestions for improvement\",\n";

        if ($output_format === 'html') {
            $prompt .= "  \"description\": \"HTML formatted description with semantic markup, bullet points for features\"\n";
        } else {
            $prompt .= "  \"description\": \"Markdown formatted description with headings, lists, and emphasis\"\n";
        }

        $prompt .= "}\n\n";

        $prompt .= "CRITICAL: The 'description' field must contain ONLY the package description content - no preamble, ";
        $prompt .= "explanations, comments, or context. Any commentary, notes, assumptions, or suggestions MUST go in the 'feedback' field.\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- Focus on benefits, not just features\n";
        $prompt .= "- Use clear, concise language\n";
        $prompt .= "- Highlight what makes this package valuable to customers\n";

        if ($output_format === 'html') {
            $prompt .= "- Use semantic HTML tags (p, ul, li, strong, em, h3, h4)\n";
        } else {
            $prompt .= "- Use proper Markdown syntax: headings (#, ##), lists (-, *), and emphasis (**, *)\n";
        }

        $prompt .= "- Be accurate and professional\n";
        $prompt .= "- Avoid making promises about specific technical details unless explicitly mentioned\n";
        $prompt .= "- Put ALL assumptions, clarifying questions, or suggestions in the 'feedback' field, NOT in 'description'\n";

        return $prompt;
    }

    /**
     * Decode CKEditor's URL-encoded template tags in href attributes within
     * each language's email_content[*][html] entry before saving.
     *
     * CKEditor percent-encodes { and } only inside href values, which corrupts
     * H2O template tags placed inside link URLs (e.g. {client_url}). Restore them.
     *
     * @param array $data POST data, modified by reference
     */
    private function decodeEmailContentTemplateTags(array &$data)
    {
        if (empty($data['email_content']) || !is_array($data['email_content'])) {
            return;
        }

        $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();
        foreach ($data['email_content'] as &$content) {
            if (is_array($content) && !empty($content['html'])) {
                $content['html'] = $sanitizer->decodeHrefTemplateTags($content['html']);
            }
        }
    }

    /**
     * Sanitizes HTML output from AI for package content
     *
     * Uses HTMLPurifier for security-focused sanitization. Allows tags
     * appropriate for package descriptions and welcome emails.
     *
     * @param string $html The HTML content to sanitize
     * @return string The sanitized HTML
     */
    private function sanitizeHtml($html)
    {
        if (empty($html)) {
            return '';
        }

        $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();
        $html = $sanitizer->extractFromCodeFences($html);
        $html = $sanitizer->stripCodeFences($html);

        return $sanitizer->purifyHtml(
            $html,
            [
                'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'span', 'div',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'a[href|title|target]',
                'ul', 'ol', 'li',
                'table[width|cellpadding|cellspacing|border]',
                'thead', 'tbody', 'tfoot', 'tr', 'td[colspan|rowspan]', 'th[colspan|rowspan]',
                'img[src|alt|width|height]',
                'blockquote', 'hr'
            ],
            [
                'color', 'background-color', 'background',
                'font-size', 'font-family', 'font-weight', 'font-style',
                'text-align', 'text-decoration',
                'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
                'border', 'border-color', 'border-width', 'border-style',
                'width', 'height', 'max-width',
                'line-height', 'vertical-align'
            ]
        );
    }

    /**
     * AJAX endpoint to generate package welcome email using AI
     */
    public function generateEmail()
    {
        // Only accept AJAX POST requests
        if (!$this->isAjax()) {
            header('HTTP/1.0 403 Forbidden');
            return false;
        }

        $this->components(['SettingsCollection', 'BlestaAi']);

        $response = [
            'success' => false,
            'error' => 'Invalid request'
        ];

        // Check if AI is enabled and package descriptions feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (empty($settings['ai_enabled']) || $settings['ai_enabled'] !== 'true') {
            $response['error'] = Language::_('AdminPackages.ai.error_disabled', true);
            echo json_encode($response);
            return false;
        }

        if (empty($settings['ai_feature_package_descriptions'])
            || $settings['ai_feature_package_descriptions'] !== 'true') {
            $response['error'] = Language::_('AdminPackages.ai.error_feature_disabled', true);
            echo json_encode($response);
            return false;
        }

        try {
            $ai = new BlestaAi($settings['ai_api_key']);

            // Get parameters
            $prompt = $this->post['prompt'] ?? '';
            $package_name = $this->post['package_name'] ?? '';
            $module_name = $this->post['module_name'] ?? null;
            $email_tags = $this->post['email_tags'] ?? null;
            $language = $this->post['language'] ?? 'en_us';
            $generate_html = ($this->post['generate_html'] ?? 'true') === 'true';
            $generate_text = ($this->post['generate_text'] ?? 'true') === 'true';
            $tone = $this->post['tone'] ?? 'professional';

            if (empty($prompt)) {
                $response['error'] = Language::_('AdminPackages.ai.error_prompt_required', true);
                echo json_encode($response);
                return false;
            }

            // Build context-aware system prompt for email (now with JSON mandate)
            $system_prompt = $this->buildEmailSystemPrompt(
                $settings['ai_global_prompt'] ?? '',
                $package_name,
                $module_name,
                $email_tags,
                $language,
                $tone
            );

            // Create conversation
            $conversation_id = $ai->createConversation(
                Configure::get('Blesta.company_id'),
                $this->Session->read('blesta_staff_id'),
                $settings['ai_default_model'] ?? 'openai/gpt-5.2',
                'Package Email Generation - ' . date('Y-m-d H:i:s'),
                'package_email'
            );

            // Build user prompt with format guidance
            $user_prompt = $prompt;
            if ($generate_html && $generate_text) {
                $user_prompt .= "\n\nGenerate both an HTML and a plain text version of this welcome email.";
            } elseif ($generate_html) {
                $user_prompt .= "\n\nGenerate the HTML version only. Set the 'text' field to null in your response.";
            } else {
                $user_prompt .= "\n\nGenerate the plain text version only. Set the 'html' field to null in your response.";
            }

            // Single API call with structured JSON response
            $ai_response = $ai->chat($conversation_id, $user_prompt, [
                'system_prompt' => $system_prompt,
                'temperature' => 0.7
            ]);

            // Parse structured response
            $parser = new \Blesta\Core\Util\AI\AiResponseParser();
            $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();

            $parsed = $parser->parse($ai_response['content'], ['html', 'text', 'feedback']);

            $generated = [];

            if ($generate_html && !empty($parsed['html'])) {
                $html = $this->sanitizeHtml($parsed['html']);
                $html = $sanitizer->unescapeTemplateTags($html);
                $generated['html'] = $sanitizer->decodeHrefTemplateTags($html);
            }

            if ($generate_text && !empty($parsed['text'])) {
                $generated['text'] = $sanitizer->sanitizeText($parsed['text']);
            }

            $response = [
                'success' => true,
                'html' => $generated['html'] ?? null,
                'text' => $generated['text'] ?? null,
                'feedback' => $parsed['feedback'] ?? null,
                'conversation_id' => $conversation_id,
                'full_prompt' => "=== SYSTEM PROMPT ===\n" . $system_prompt . "\n\n=== USER PROMPT ===\n" . $user_prompt
            ];

        } catch (Exception $e) {
            $response['error'] = $e->getMessage();
        }

        echo json_encode($response);
        return false;
    }

    /**
     * Builds a context-aware system prompt for email generation
     *
     * @param string $global_prompt The global AI system prompt
     * @param string $package_name The package name
     * @param string $module_name The module name
     * @param string $email_tags Available email tags/variables
     * @param string $language The language code
     * @param string $tone The tone (professional, casual, technical)
     * @return string The formatted system prompt
     */
    private function buildEmailSystemPrompt(
        $global_prompt,
        $package_name,
        $module_name,
        $email_tags,
        $language,
        $tone
    ) {
        $prompt = $global_prompt . "\n\n";
        $prompt .= "You are generating welcome email templates for Blesta packages.\n";
        $prompt .= "Your task is to create professional, welcoming emails that help new customers get started.\n\n";

        $prompt .= "Package Context:\n";
        if ($package_name) {
            $prompt .= "- Package Name: " . $package_name . "\n";
        }
        if ($module_name) {
            $prompt .= "- Service Type/Module: " . $module_name . "\n";
        }
        if ($email_tags) {
            $prompt .= "- Available Email Tags (use these for dynamic content): " . $email_tags . "\n";
        }

        $prompt .= "\nOutput Requirements:\n";
        $prompt .= "- Language: " . $language . "\n";
        $prompt .= "- Tone: " . $tone . "\n";
        $prompt .= "- You MUST respond with valid JSON only, no other text\n\n";

        $prompt .= "Response Format (JSON):\n";
        $prompt .= "{\n";
        $prompt .= "  \"html\": \"Complete HTML email body with inline styles, or null if not requested\",\n";
        $prompt .= "  \"text\": \"Complete plain text email body with no HTML tags, or null if not requested\",\n";
        $prompt .= "  \"feedback\": \"Any clarifying questions, assumptions made, or suggestions for improvement\"\n";
        $prompt .= "}\n\n";

        $prompt .= "CRITICAL: The 'html' and 'text' fields must contain ONLY the email body content. ";
        $prompt .= "Any commentary, notes, or suggestions MUST go in the 'feedback' field.\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- Start with a warm welcome message\n";
        $prompt .= "- Include key information about the service\n";
        $prompt .= "- Provide clear next steps for the customer\n";
        $prompt .= "- Use email tags where appropriate for personalization\n";
        $prompt .= "- Keep the email concise but informative\n";
        $prompt .= "- Be professional and customer-focused\n";
        $prompt .= "- Avoid making promises about technical details unless explicitly mentioned\n";
        $prompt .= "- For the HTML version: Use ONLY inline styles (style attribute). Do NOT use <style> tags or CSS blocks\n";
        $prompt .= "- For the text version: Do NOT include any HTML tags\n";
        $prompt .= "- Both versions should have identical content, just different formatting\n";
        $prompt .= "- IMPORTANT: Tags that represent collections (e.g., name_servers, ip_addresses) MUST use H2O loop syntax to iterate over all items\n";

        // Append H2O template syntax reference for loops, conditionals, and filters
        $contextBuilder = new \Blesta\Core\Util\AI\EmailTagContextBuilder();
        $prompt .= $contextBuilder->getH2oSyntaxReference();

        return $prompt;
    }
}
