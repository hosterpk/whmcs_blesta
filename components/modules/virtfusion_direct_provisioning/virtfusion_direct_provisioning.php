<?php

/**
 * VirtFusion Direct Provisioning Module
 *
 * @link https://docs.virtfusion.com/integrations/blesta VirtFusion
 */
class VirtfusionDirectProvisioning extends Module
{

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load the language required by this module
        Language::loadLang('virtfusion_direct_provisioning', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this module
        Loader::loadComponents($this, ['Input', 'Record']);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

    }

    private function getApi($api_token, $hostname)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'virtfusion_api.php');

        return new VirtfusionApi($api_token, $hostname);
    }

    /**
     * Performs any necessary bootstraping actions
     */
    public function install()
    {
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version. Sets Input errors on failure, preventing
     * the module from being upgraded.
     *
     * @param string $current_version The current installed version of this module
     */
    public function upgrade($current_version)
    {
        if (version_compare($current_version, '1.0.1', '<')) {
            if (!isset($this->ModuleManager)) {
                Loader::loadModels($this, ['ModuleManager', 'Services']);
            }
            $modules = $this->ModuleManager->getByClass('virtfusion_direct_provisioning');

            // get mod info
            foreach ($modules as $module) {
                $rows = $this->ModuleManager->getRows($module->id);
                foreach ($rows as $row) {
                    $this->upgrade1_0_1($row);
                }
            }
        }
    }

    private function upgrade1_0_1($row)
    {
        $api_token;
        $hostname;
        $module_row_id;

        $meta = (array)$row->meta;

        if (isset($meta['api_token']) && isset($meta['hostname'])) {
            $api_token = $meta['api_token'];
            $hostname = $meta['hostname'];
            $module_row_id = $row->id;
        }

        if ($api_token && $hostname && $module_row_id) {
            $services = $this->Services->getAll(
                ['date_added' => 'DESC'],
                true,
                [],
                [
                    'services' => [
                        'module_row_id' => $module_row_id
                    ]
                ]
            );

            $api = $this->getApi($api_token, $hostname);

            foreach ($services as $service) {
                $service_fields = $this->serviceFieldsToObject($service->fields);

                $server_id = $service_fields->virtfusion_server_id;
                $virtfusion_ipv6 = null;

                $server_info = $api->get_query("servers/$server_id");
                $server_data = json_decode($server_info['response']);
                if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
                    $ipv6_data = $server_data->data->network->interfaces[0]->ipv6[0];
                    $virtfusion_ipv6 = $ipv6_data->subnet."/".$ipv6_data->cidr;
                }

                $insert = array(
                    'key' => 'virtfusion_ipv6_cidr',
                    'value' => $virtfusion_ipv6
                );

                $this->Services->editField($service->id, $insert);
                unset($virtfusion_ipv6);
            }
        }
    }

    /**
     * Performs any necessary cleanup actions. Sets Input errors on failure
     * after the module has been uninstalled.
     *
     * @param int $module_id The ID of the module being uninstalled
     * @param bool $last_instance True if $module_id is the last instance
     *  across all companies for this module, false otherwise
     */
    public function uninstall($module_id, $last_instance)
    {
    }

    /**
     * Returns the value used to identify a particular service
     *
     * @param stdClass $service A stdClass object representing the service
     * @return string A value used to identify this service amongst other similar services
     */
    public function getServiceName($service)
    {
        foreach ($service->fields as $field) {
            if ($field->key == 'virtfusion_hostname') {
                return $field->value;
            }
        }
        return null;
    }

    /**
     * Returns the rendered view of the manage module page.
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manager module
     *  page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
    public function manageModule($module, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page.
     *
     * @param array $vars An array of post data submitted to or on the add module
     *  row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow(array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (!empty($vars)) {
            // Set unset checkboxes
            $checkbox_fields = [];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
        }

        $this->view->set('vars', (object)$vars);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit
     *  module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow($module_row, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = $module_row->meta;
        } else {
            // Set unset checkboxes
            $checkbox_fields = [];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
        }

        $this->view->set('vars', (object)$vars);

        return $this->view->fetch();
    }

    /**
     * Adds the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being added. Returns a set of data, which may be
     * a subset of $vars, that is stored for this module row.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = ['name', 'hostname', 'api_token'];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            $vars['hostname'] = strtolower($vars['hostname']);
            // Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Edits the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being updated. Returns a set of data, which may be
     * a subset of $vars, that is stored for this module row.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        $meta_fields = ['name', 'hostname', 'api_token'];
        $encrypted_fields = ['api_token'];

        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            $vars['hostname'] = strtolower($vars['hostname']);
            // Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Builds and returns the rules required to add/edit a module row (e.g. server).
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules(&$vars)
    {
        $rules = [
            'name' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.name.empty', true)
                ]
            ],
            'hostname' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.hostname.empty', true)
                ],
            ],
            'api_token' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.api_token.empty', true)
                ],
                'valid' => [
                    'rule' => array(array($this, "validateApiCredentials"), $vars),
                    'message' => Language::_("VirtfusionDirectProvisioning.!error.api_token.valid", true)
                ]
            ]
        ];

        return $rules;
    }

    // ping server to make sure we have valid host and api token
    public function validateApiCredentials($api_token, $vars)
    {
        try {
            $api = $this->getApi($vars['api_token'], $vars['hostname']);
            $request = $api->get_query('packages');

            if ($request['info']['http_code'] != 200) {
                $msg =  ($request['response']) ? json_decode($request['response']) : 'Invalid API Token';
                $this->log($vars['hostname'], serialize($msg), "output", false);
                return false;
            }

            return true;
        }
        catch (Exception $e) {
            return false;
            // Trap any errors encountered, could not validate connection
        }
        return false;
    }


    /**
     * Returns an array of available service delegation order methods. The module
     * will determine how each method is defined. For example, the method "first"
     * may be implemented such that it returns the module row with the least number
     * of services assigned to it.
     *
     * @return array An array of order methods in key/value pairs where the key
     *  is the type to be stored for the group and value is the name for that option
     * @see Module::selectModuleRow()
     */
    public function getGroupOrderOptions() {
        return array(
            'first' => Language::_("VirtfusionDirectProvisioning.order_options.first", true)
        );
    }

    /**
	 * Determines which module row should be attempted when a service is provisioned
	 * for the given group based upon the order method set for that group.
	 *
	 * @return int The module row ID to attempt to add the service with
	 * @see Module::getGroupOrderOptions()
	 */
	public function selectModuleRow($module_group_id) {
		if (!isset($this->ModuleManager))
			Loader::loadModels($this, array("ModuleManager"));

		$group = $this->ModuleManager->getGroup($module_group_id);

		if ($group) {
			switch ($group->add_order) {
				default:
				case "first":

					foreach ($group->rows as $row) {
						return $row->id;
					}

					break;
			}
		}
		return 0;
	}

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     *
     * @param array An array of key/value pairs used to add the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addPackage(array $vars = null)
    {
        // Set rules to validate input data
        $this->Input->setRules($this->getPackageRules($vars));

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            if (!isset($vars['meta'])) {
                return [];
            }

            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package, returns the meta
     * data to save when editing a package. Performs any action required to edit
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being edited.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array An array of key/value pairs used to edit the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage($package, array $vars=null) {
        // Set rules to validate input data
        $this->Input->setRules($this->getPackageRules($vars));

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            if (!isset($vars['meta'])) {
                return [];
            }

            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Deletes the package on the remote server. Sets Input errors on failure,
     * preventing the package from being deleted.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function deletePackage($package)
    {
    }

    /**
     * Builds and returns rules required to be validated when adding/editing a package.
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getPackageRules(array $vars)
    {
        // Validate the package fields
        $rules = [
            'meta[hypervisor_group_id]' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.meta[hypervisor_group_id].valid', true)
                ]
            ],
            'meta[default_ipv4]' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.meta[default_ipv4].valid', true)
                ]
            ],
            'meta[package_id]' => [
                'empty' => [
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.meta[package_id].valid', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to
     *  render as well as any additional HTML markup to include
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        // Set the Hypervisor Group ID field
        $hypervisor_group_id = $fields->label(Language::_('VirtfusionDirectProvisioning.package_fields.hypervisor_group_id', true), 'virtfusion_direct_provisioning_hypervisor_group_id');
        $hypervisor_group_id->attach(
            $fields->fieldText(
                'meta[hypervisor_group_id]',
                (isset($vars->meta['hypervisor_group_id']) ? $vars->meta['hypervisor_group_id'] : null),
                ['id' => 'virtfusion_direct_provisioning_hypervisor_group_id']
            )
        );
        $fields->setField($hypervisor_group_id);

        // Set the Default IPv4 field
        $default_ipv4 = $fields->label(Language::_('VirtfusionDirectProvisioning.package_fields.default_ipv4', true), 'virtfusion_direct_provisioning_default_ipv4');
        $default_ipv4->attach(
            $fields->fieldText(
                'meta[default_ipv4]',
                (isset($vars->meta['default_ipv4']) ? $vars->meta['default_ipv4'] : null),
                ['id' => 'virtfusion_direct_provisioning_default_ipv4']
            )
        );
        $fields->setField($default_ipv4);

        // Set the Package ID field
        $package_id = $fields->label(Language::_('VirtfusionDirectProvisioning.package_fields.package_id', true), 'virtfusion_direct_provisioning_package_id');
        $package_id->attach(
            $fields->fieldText(
                'meta[package_id]',
                (isset($vars->meta['package_id']) ? $vars->meta['package_id'] : null),
                ['id' => 'virtfusion_direct_provisioning_package_id']
            )
        );
        $fields->setField($package_id);

        // Set the Package ID field
        $os_id = $fields->label(Language::_('VirtfusionDirectProvisioning.package_fields.os_id', true), 'virtfusion_direct_provisioning_os_id');
        $os_id->attach(
            $fields->fieldText(
                'meta[virtfusion-default_os_template]',
                (isset($vars->meta['virtfusion-default_os_template']) ? $vars->meta['virtfusion-default_os_template'] : null),
                [
                    'id' => 'virtfusion_direct_provisioning_os_id',
                    'requred' => 'required'
                ]
            )
        );
        $os_id->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioning.package_fields.os_id.help_text', true), 'virtfusion_direct_provisioning_os_id'));

        $fields->setField($os_id);

        return $fields;
    }

    /**
     * Adds the service to the remote server. Sets Input errors on failure,
     * preventing the service from being added.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being added (if the current service is an addon service
     *  service and parent service has already been provisioned)
     * @param string $status The status of the service being added. These include:
     *  - active
     *  - canceled
     *  - pending
     *  - suspended
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addService(
        $package,
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    )
    {
        // $this->Input->setErrors(['api' => ['response' => print_r($client, true)]]);
        // return;
        // Set unset checkboxes
        $checkbox_fields = [];

        // default OS version
        $virtfusion_os_id = $package->meta->{'virtfusion-default_os_template'};
        $domain = isset($vars['virtfusion_hostname']) ? trim($vars['virtfusion_hostname']) : '';
        $server_id = 0;
        $virtfusion_password = '';
        $virtfusion_ip = '';
        $virtfusion_base_ips = '';
        $virtfusion_additional_ips = '';
        $virtfusion_ipv6_cidr = '';

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        // Load the API
        $row = $this->getModuleRow();

        $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

        // Get the fields for the service
        //$params = $this->getFieldsFromInput($vars, $package);

        // Validate the service-specific fields
        $this->validateService($package, $vars);

        if ($this->Input->errors()) {
            return;
        }

        // Only provision the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            /**
             *
             * We need to check if a user exists in VirtFusion based on the extrelid
             *
             */
            // $service_fields = $this->serviceFieldsToObject($service->fields);

            $api->loadCommand('virtfusion_client');

            try {

                $server_api = new VirtfusionClient($api);

                $this->log($row->meta->hostname . '| client check', $vars['client_id'], "input", true);
                $request = $server_api->check($vars['client_id'], []);


                if (isset($request['info'])) {

                    $this->log($row->meta->hostname . '| client check result', serialize($request), "output", $request['info']['http_code'] == 200);
                    switch ($request['info']['http_code']) {
                        case 200:

                            $data = json_decode($request['response']);
                            /**
                             *
                             * A user already exists
                             *
                             */
                            break;

                        case 404:

                            Loader::loadModels($this, ['Clients']);

                            $this->log($row->meta->hostname . '| get client', $vars['client_id'], "input", true);
                            $client = $this->Clients->get($vars['client_id'], false);

                            $request = $server_api->create([
                                "name" => $client->first_name . ' ' . $client->last_name,
                                "email" => $client->email,
                                'extRelationId' => $vars['client_id']
                            ]);

                            $this->log($row->meta->hostname . '| client info', serialize($request), "output", $request['info']['http_code'] == 201);

                            if (isset($request['info'])) {
                                if ($request['info']['http_code'] !== 201) {
                                    $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                                    return;
                                }
                            } else {
                                $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                                return;
                            }

                            $data = json_decode($request['response']);

                            break;

                        default:
                            $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                            return;
                    };


                    /**
                     *
                     * Create server
                     *
                     */

                    $api->loadCommand('virtfusion_server');

                    $server_api = new VirtfusionServer($api);

                    // override default hypervisor group ID if we have config option
                    $hypervisor_group_id = $package->meta->hypervisor_group_id;
                    if (isset($vars['configoptions']['dynamic_hypervisor_group_id'])) {
                        $hypervisor_group_id = $vars['configoptions']['dynamic_hypervisor_group_id'];
                    }

                    $virtfusion_extra_ips = 0;
                    if (isset($vars['configoptions']['additional_num_ips'])) {
                        $virtfusion_extra_ips = $vars['configoptions']['additional_num_ips'];
                        $this->log($row->meta->hostname . '| number of extra IPs', $virtfusion_extra_ips, "input", true);
                    }

                    $ipv4_count = (int)$package->meta->default_ipv4 + (int)$virtfusion_extra_ips;

                    $request = $server_api->create([
                        "packageId" => $package->meta->package_id,
                        "userId" => $data->data->id,
                        "hypervisorId" => $hypervisor_group_id,
                        "ipv4" => $ipv4_count,
                    ]);

                    $this->log($row->meta->hostname . '| create server', serialize($request), "input", $request['info']['http_code'] !== 201);

                    if ($request['info']['http_code'] !== 201) {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                        return;
                    }

                    $data = json_decode($request['response']);

                    $server_id = $data->data->id;

                    /**
                     *
                     * Build server
                     *
                     */

                    if (isset($vars['configoptions']['virtfusion-os_template'])) {
                        $virtfusion_os_id = $vars['configoptions']['virtfusion-os_template'];
                    }
                    $this->log($row->meta->hostname . '| build os id', $virtfusion_os_id, "input", true);

                    $hasError = true;

                    // check that is int no hiccups in extraction
                    if (is_numeric($virtfusion_os_id) && !empty($domain)) {
                        $server_name = substr($domain, 0, strrpos($domain, "."));

                        $build_request = $server_api->build(
                            $server_id,
                            [
                                'operatingSystemId' => $virtfusion_os_id,
                                'name' => $server_name,
                                'hostname' => $domain,
                                'ipv6' => true
                                // 'sshKeys' => [ 1 ], // not sure if needed
                                // 'email' => true // not sure if needed (default false)
                                ]
                            );

                        $build_data = json_decode($build_request['response']);

                        if ($build_request['info']['http_code'] == 200) {

                            $hasError = false;

                            $virtfusion_password = $build_data->data->settings->decryptedPassword;

                            // if 200 we should have this
                            $ip_addresses = [];
                            foreach ($build_data->data->network->interfaces[0]->ipv4 as $ip) {
                                $ip_addresses[] = $ip->address;
                            }
                            $base_num = $package->meta->default_ipv4 - 1;

                            if (isset($ip_addresses[0])) {
                                $virtfusion_ip = $ip_addresses[0];
                            }

                            // get #2 - base number of ips
                            $virtfusion_base_ips_arr = array_slice($ip_addresses, 1, $base_num);
                            $virtfusion_base_ips = implode(',', $virtfusion_base_ips_arr);

                            if ($virtfusion_extra_ips > 0) {
                                // get #3 - end
                                $virtfusion_additional_ips_arr = array_slice($ip_addresses, $base_num+1, count($ip_addresses)-1);
                                $virtfusion_additional_ips = implode(',', $virtfusion_additional_ips_arr);
                            }

                            for($i = 0; $i <= 10; $i++) {
                                sleep(5);
                                $server_info = $api->get_query("servers/$server_id");
                                $server_data = json_decode($server_info['response']);
                                if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
                                    $ipv6_data = $server_data->data->network->interfaces[0]->ipv6[0];
                                    $virtfusion_ipv6_cidr = $ipv6_data->subnet."/".$ipv6_data->cidr;
                                    $this->log($row->meta->hostname . '| get ipv6', serialize($ipv6_data), "output", $server_info['info']['http_code'] == 200);
                                    break;
                                }
                            }
                        }

                        $this->log($row->meta->hostname . '| build server', serialize($build_request), "output", $build_request['info']['http_code'] == 200);
                    }

                    if ($hasError) {

                        $cleanup_request = $server_api->cancel($server_id,[]);

                        // log clean up
                        // should we email admin?
                        $this->log($row->meta->hostname . '| error services cleanup', serialize($cleanup_request), "output", $cleanup_request['info']['http_code'] == 200);

                        // generic error, will be improved
                        $this->Input->setErrors(['api' => ['response' => 'Could not build the server.']]);
                        // do server cleanup
                        return;
                    }

                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                    return;
                }

            } catch (Exception $e) {
                $this->Input->setErrors(['api' => ['response' => print_r($e->getMessage(), true)]]);
                return;
            }
        }

        return array(
            [
                'key' => 'virtfusion-os_template',
                'value' => $virtfusion_os_id,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_hostname',
                'value' => $domain,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_server_id',
                'value' => $server_id,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_password',
                'value' => $virtfusion_password,
                'encrypted' => 1
            ],
            [
                'key' => 'virtfusion_ip',
                'value' => $virtfusion_ip,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion_ipv6_cidr',
                'value' => $virtfusion_ipv6_cidr,
                'encrypted' => 0
            ],
            [
                'key' => 'virtfusion-base_ips',
                'value' => $virtfusion_base_ips,
                'encrypted' => 0
            ],
            [
                'key' => 'additional_num_ips',
                'value' => $virtfusion_additional_ips,
                'encrypted' => 0
            ]
        );

        // Return service fields
    }

    /**
     * Edits the service on the remote server. Sets Input errors on failure,
     * preventing the service from being edited.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being edited (if the current service is an addon service)
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editService($package, $service, array $vars = null, $parent_package = null, $parent_service = null)
    {
        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $service_fields = $this->serviceFieldsToObject($service->fields);

        $this->validateService($package, $vars, true);

        if ($this->Input->errors()) {
            return;
        }

        // Only update the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            // we need the api
            if ($module_row = $this->getModuleRow()) {
                $data = $this->adjustIpAddresses($module_row, $service_fields, $vars);

                if (!empty($data['errors']['err_msg'])) {
                    // if not staff override error
                    // since removing is not possible from this page
                    // give user some guidance
                    if ( !isset($vars['staff_id']) ) {
                        $this->Input->setErrors(['Internal' => [ 'Error' => 'You cannot remove IPs from this tab, please try again from IP Addresses tab' ] ]);
                    } else {
                        $this->Input->setErrors(['api' => ['response' => $data['errors']['err_msg']]]);
                    }

                    return;
                }

                // reset vars with new information
                $vars['additional_num_ips'] = $data['service_fields']->{'additional_num_ips'};
            }
        }

        // Return all the service fields
        $fields = ['virtfusion_server_id', 'virtfusion_hostname', 'virtfusion-os_template', 'virtfusion_password', 'virtfusion-base_ips', 'additional_num_ips', 'virtfusion_ip'];
        $encrypted_fields = ['virtfusion_password'];
        $return = [];
        foreach ($fields as $field) {
            if (isset($vars[$field]) || isset($service_fields->{$field})) {
                $return[] = [
                    'key' => $field,
                    'value' => $vars[$field] ?? $service_fields->{$field},
                    'encrypted' => (in_array($field, $encrypted_fields) ? 1 : 0)
                ];
            }
        }

        return $return;
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being suspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {

            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
            $service_fields = $this->serviceFieldsToObject($service->fields);

            $api->loadCommand('virtfusion_server');

            try {

                $server_api = new VirtfusionServer($api);
                $request = $server_api->suspend($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }

                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| suspend', serialize($request), "output", $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (Exception $e) {
                // Nothing to do
                return;
            }
        }

        return null;
    }

    /**
     * Unsuspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being unsuspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being unsuspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {

            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
            $service_fields = $this->serviceFieldsToObject($service->fields);

            $api->loadCommand('virtfusion_server');

            try {

                $server_api = new VirtfusionServer($api);
                $request = $server_api->unsuspend($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }

                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| unsuspend', serialize($request), "output", $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (Exception $e) {
                // Nothing to do
                return;
            }

        }

        return null;
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being canceled (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
            $service_fields = $this->serviceFieldsToObject($service->fields);

            $api->loadCommand('virtfusion_server');

            try {

                $server_api = new VirtfusionServer($api);
                $request = $server_api->cancel($service_fields->virtfusion_server_id, []);

                $success = false;

                if (isset($request['info'])) {
                    if ($request['info']['http_code'] === 204) {
                        $success = true;
                    } else {
                        $this->Input->setErrors(['api' => ['response' => 'Received  a ' . $request['info']['http_code'] . ' http code from the API. The action was unsuccessful.']]);
                    }

                } else {
                    $this->Input->setErrors(['api' => ['response' => 'Failed to get a response from the API. The action was unsuccessful.']]);
                }

                $this->log($row->meta->hostname . '| cancel', serialize($request), "output", $success);

                if (!$success) {
                    return;
                }
                return true;
            } catch (Exception $e) {
                // Nothing to do
                return;
            }
        }
        return null;
    }

    /**
     * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @return bool True if the service validates, false otherwise. Sets Input errors when false.
     */
    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars));
        return $this->Input->validates($vars);
    }

    /**
     * Attempts to validate an existing service against a set of service info updates. Sets Input errors on failure.
     *
     * @param stdClass $service A stdClass object representing the service to validate for editing
     * @param array $vars An array of user-supplied info to satisfy the request
     * @return bool True if the service update validates or false otherwise. Sets Input errors when false.
     */
    public function validateServiceEdit($service, array $vars = null)
    {
        $this->Input->setRules($this->getServiceRules($vars, true));
        return $this->Input->validates($vars);
    }

    /**
     * Returns the rule set for adding/editing a service
     *
     * @param array $vars A list of input vars
     * @param bool $edit True to get the edit rules, false for the add rules
     * @return array Service rules
     */
    private function getServiceRules(array $vars = null, $edit = false)
    {
        // Validate the service fields
        $rules = [
            'virtfusion_hostname' => [
                'valid' => [
                    'rule' => [[$this, 'validateHostname']],
                    'message' => Language::_('VirtfusionDirectProvisioning.client.!error.host.valid', true)
                ]
            ],
            'virtfusion_server_id' => [
                'valid' => [
                    'if_set' => $edit,
                    'rule' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.server_id.valid', true)
                ]
            ],
            'label' => [
                'valid' => [
                    'if_set' => $edit,
                    'rule' => true,
                    'message' => Language::_('VirtfusionDirectProvisioning.!error.label.valid', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Updates the package for the service on the remote server. Sets Input
     * errors on failure, preventing the service's package from being changed.
     *
     * @param stdClass $package_from A stdClass object representing the current package
     * @param stdClass $package_to A stdClass object representing the new package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being changed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function changeServicePackage(
        $package_from,
        $package_to,
        $service,
        $parent_package = null,
        $parent_service = null
    )
    {
        $service_fields = $this->serviceFieldsToObject($service->fields);

        if (($row = $this->getModuleRow()) && isset($service_fields->virtfusion_server_id)) {
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);
            $server_id = $service_fields->virtfusion_server_id;

            try {
                $api->loadCommand('virtfusion_server');
                $server_api = new VirtfusionServer($api);

                $server_info = $api->get_query("servers/$server_id");
                $this->log($row->meta->hostname . '| client get server', serialize($server_info), "output", $server_info['info']['http_code'] == 200);

                if (isset($server_info['info']) && $server_info['info']['http_code'] == '200') {
                    $server_data = json_decode($server_info['response']);

                    // get old primary storage
                    $primary_storage = null;
                    $new_primary_storage = null;

                    foreach($server_data->data->storage as $storage) {
                        if ($storage->primary) {
                            $primary_storage = $storage->capacity;
                        }
                    }

                    $new_pkg_id = $package_to->meta->package_id;
                    $pkg_response = $api->get_query("packages/$new_pkg_id");
                    $this->log($row->meta->hostname . '| client get pkg', serialize($pkg_response), "output", $pkg_response['info']['http_code'] == 200);

                    // issue geting pkg data, log and exit
                    if (isset($pkg_response['info']) && $pkg_response['info']['http_code'] != 200) {
                        $this->Input->setErrors(['api' => ['response' => 'Error:'. $pkg_response['info']['http_code'] . ' Could not upgrade server.']]);
                        return null;
                    }

                    $pkg_data = json_decode($pkg_response['response']);
                    $new_primary_storage = $pkg_data->data->primaryStorage;

                    // cannot downgrade
                    if (is_null($primary_storage) || $primary_storage > $new_primary_storage) {
                        $this->log($row->meta->hostname . '| client upgrade storage', "possible downgrade attempt", "output", false);
                        $this->Input->setErrors(['api' => ['response' => 'Error:Storage Could not upgrade server.']]);
                        return null;
                    }

                    $api->loadCommand('virtfusion_server');
                    $server_api = new VirtfusionServer($api);

                    $server_pkg_data = $server_api->changePkg($server_id, $new_pkg_id);
                    $server_response = json_decode($server_pkg_data['response']);
                    $this->log($row->meta->hostname . '| client upgrade server', serialize($server_pkg_data), "output", $server_pkg_data['info']['http_code'] == 200);

                    if ($server_pkg_data['info']['http_code'] != '200') {
                        $msg = isset($server_response->errors) ? implode('<br />', $server_response->errors) : 'Error:'. $server_pkg_data['info']['http_code'] . ' Could not upgrade server.';

                        $this->Input->setErrors(['api' => ['response' => $msg]]);
                        return null;
                    }

                    // auto reboot
                    $restart_data = $server_api->powerAction($server_id, 'restart');
                    $this->log($row->meta->hostname . '| client restart server', serialize($restart_data), "output", $restart_data['info']['http_code'] == 200);

                } else {
                    $this->log($row->meta->hostname . '| client get server', serialize($server_info), "output", $server_info['info']['http_code'] == 200);
                    $this->Input->setErrors(['api' => ['response' => $server_info['info']['http_code']]]);
                    return null;
                }

            } catch (Exception $e) {
                $this->Input->setErrors(['api' => ['response' => print_r($e->getMessage(), true)]]);
                return null;
            }
        }

        return null;
    }


    /**
     * Fetches the HTML content to display when viewing the service info in the
     * admin interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getAdminServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('admin_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->serviceFieldsToObject($service->fields));

        return $this->view->fetch();
    }

    /**
     * Returns all tabs to display to a client when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title.
     *  Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getClientTabs($package)
    {
        return [
            'tabManage' => Language::_('VirtfusionDirectProvisioning.tabManage', true),
            'tabClientIPAddresses' => Language::_('VirtfusionDirectProvisioning.ipAddresses', true)
        ];
    }

    public function getAdminTabs($package)
    {
        return [
            'tabAdminManage' => Language::_('VirtfusionDirectProvisioning.tabManage', true),
            'tabAdminIPAddresses' => Language::_('VirtfusionDirectProvisioning.ipAddresses', true)
        ];
    }

    /**
     * tabManage
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabManage(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $this->view = new View('tabManage', 'default');
        $this->view->base_uri = $this->base_uri;

        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);

        if ($_POST) {
            if (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    if (($row = $this->getModuleRow())) {
                        $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

                        $api->loadCommand('virtfusion_server');

                        $server_api = new VirtfusionServer($api);
                        $request = $server_api->fetchToken($service_fields->virtfusion_server_id, $service->client_id, []);

                        if (isset($request['info'])) {

                            $this->log($row->meta->hostname . '| client api token', serialize($request), "output", $request['info']['http_code'] == 200);
                            if ($request['info']['http_code'] === 200) {
                                $data = json_decode($request['response']);

                                header("Location: https://" . $row->meta->hostname . $data->data->authentication->endpoint_complete);
                                die();

                            }
                        }
                    }
                }
            }
            $this->Input->setErrors([['We couldn\'t log you in. Something went wrong.']]);
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', (isset($vars) ? $vars : new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);
        return $this->view->fetch();
    }

    public function tabAdminManage(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $this->view = new View('tabAdminManage', 'default');

        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);

        if ($_POST) {
            if (property_exists($service_fields, 'virtfusion_server_id')) {
                if (is_numeric($service_fields->virtfusion_server_id)) {
                    if (($row = $this->getModuleRow())) {
                        $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

                        $api->loadCommand('virtfusion_server');

                        $server_api = new VirtfusionServer($api);
                        $request = $server_api->fetchToken($service_fields->virtfusion_server_id, $service->client_id, []);

                        if (isset($request['info'])) {

                            $this->log($row->meta->hostname . '| admin api token', serialize($request), "output", $request['info']['http_code'] == 200);
                            if ($request['info']['http_code'] === 200) {
                                $data = json_decode($request['response']);

                                header("Location: https://" . $row->meta->hostname . $data->data->authentication->endpoint_complete);
                                die();
                            }
                        }
                    }
                }
            }
            $this->Input->setErrors([['We couldn\'t log you in. Something went wrong.']]);
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', (isset($vars) ? $vars : new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);
        return $this->view->fetch();
    }

    /**
     * tabClientIPAddresses
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientIPAddresses(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $this->view = new View('tab_ips', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!empty($post)) {
            $error_msg = $this->removeIPAddress($package, $service, $post);

            if (!empty($error_msg)) {
                // this does not redirect to the right place
                $this->Input->setErrors(['api' => ['response' => $error_msg]]);
            }

            // redirect to clear post
            // if not refresh could cause issues
            $host = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
            header("Location: $host" . $_SERVER['HTTP_HOST'] . $post['submit_uri']);
            die();
        }

        $ip_address_data = $this->getClientIpAddresses($package, $service, $get, $post, $client = true);
        $formated_ips = $this->formatIPToView($ip_address_data);

        $submit_uri = $this->base_uri . "services/manage/".$service->id."/tabClientIPAddresses/";
        $this->view->set('submit_uri', $submit_uri);
        $this->view->set('ip_addresses', $formated_ips);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->set('ip_addable', $ip_address_data->addable);
        $this->view->set('view_type', 'tabClientIPAddresses');

        return $this->view->fetch();
    }

    /**
     * tabAdminIPAddresses
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabAdminIPAddresses(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $this->view = new View('tab_ips', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $ip_address_data = $this->getClientIpAddresses($package, $service, $get, $post, $client = false);

        $formated_ips = $this->formatIPToView($ip_address_data);

        if (isset($post['refresh_ipv6'])) {
            unset($post['refresh_ipv6']);

            $row = $this->getModuleRow();
            $api = $this->getApi($row->meta->api_token, $row->meta->hostname);

            Loader::loadModels($this, ['Services']);

            // Get the service fields
            $service_fields = $this->serviceFieldsToObject($service->fields);
            $server_id = $service_fields->virtfusion_server_id;
            $virtfusion_ipv6 = null;

            $server_info = $api->get_query("servers/$server_id");
            $server_data = json_decode($server_info['response']);
            if (isset($server_data->data->network->interfaces[0]->ipv6[0])) {
                $ipv6_data = $server_data->data->network->interfaces[0]->ipv6[0];
                $virtfusion_ipv6 = $ipv6_data->subnet."/".$ipv6_data->cidr;
            }

            $insert = array(
                'key' => 'virtfusion_ipv6_cidr',
                'value' => $virtfusion_ipv6
            );

            $this->Services->editField($service->id, $insert);
            unset($virtfusion_ipv6);

            // redirect to clear post
            // if not refresh could cause issues
            $host = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
            header("Location: $host" . $_SERVER['HTTP_HOST'] . "/admin/clients/servicetab/$service->client_id/$service->id/tabAdminIPAddresses/");
            die();
        }


        $this->view->set('ip_addresses', $formated_ips);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->set('ip_addable', $ip_address_data->addable);
        $this->view->set('is_admin', true);
        $this->view->set('view_type', 'tabAdminIPAddresses');

        return $this->view->fetch();
    }

    private function removeIPAddress($package, $service, $post) {
        Loader::loadHelpers($this, [
            'Invoices',
            'Services',
            'ServiceChanges',
            'ModuleManager'
        ]);

        $service_fields = $this->serviceFieldsToObject($service->fields);
        $module_row = $this->getModuleRow();

        // Get current extra IP stuff
        $extra_ips_arr = array();
        $extra_ips = $service_fields->{'virtfusion-extra_ips'};
        if (!empty($extra_ips) && $module_row) {
            $extra_ips_arr = explode(',', $extra_ips);
        }

        $ip_to_remove = $post['ip_address'];

        if (in_array($ip_to_remove, $extra_ips_arr)) {

            // Get and load api
            $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
            $api->loadCommand('virtfusion_server');
            $server_api = new VirtfusionServer($api);

            $request = $server_api->removeIpv4($service_fields->virtfusion_server_id, [ $ip_to_remove ]);
            $this->log($module_row->meta->hostname, serialize($request), "output", $request['info']['http_code'] == '204');
            $new_extra_ips = implode(',', array_diff($extra_ips_arr, [ $ip_to_remove ]));

            if ($request['info']['http_code'] == '204') {
                // prorate here

                $new_extra_ips = array_diff($extra_ips_arr, [ $ip_to_remove ]);

                $this->Services->editField(
                    $service->id,
                    [
                        "key" => "virtfusion-extra_ips",
                        "value" => implode(',', $new_extra_ips),
                        "encrypted" => false
                    ]
                );

                // Fetch and re-set all current service config options
                $options = [];
                foreach ($service->options as $option) {
                    // Quantity options use the qty field as the value
                    if ($option->option_type == 'quantity') {
                        $option->option_value = $option->qty;
                    }

                    // Set the extra IPs to the count of them
                    if ($option->option_name == 'virtfusion-extra_ips') {
                        $option->option_value = max(0, count($new_extra_ips));
                    }

                    // Set the value of each option
                    $options[$option->option_id] = $option->option_value;
                }

                // Get the invoice lines for an ip change
                $invoice_vars = $this->getIpChangeInvoiceVars($service, $options);
                if (!empty($invoice_vars)) {
                    // Create the invoice
                    $this->Invoices->add($invoice_vars);
                }

                // Update the config options
                $this->Services->edit(
                    $service->id,
                    [
                        'virtfusion_server_id' => $service_fields->{'virtfusion_server_id'},
                        'virtfusion_hostname' => $service_fields->virtfusion_hostname,
                        'configoptions' => $options,
                        'use_module' => 'false'
                    ]
                );

                if ($this->Services->errors()) {
                    return $this->Services->errors();
                }
            } else {
                return 'Could not remove IP address.';
            }
        }

        return '';
    }

    /**
     * Gets the invoice lines for an ip change
     *
     * @param stdClass $service An object representing a service
     * @param array $options A list of configurable options including extra IPs
     * @return array A list of invoice vars
     */
    private function getIpChangeInvoiceVars($service, array $options)
    {

        $serviceChange = $this->ServiceChanges->getPresenter(
            $service->id,
            ['configoptions' => $options, 'pricing_id' => $service->pricing_id, 'qty' => $service->qty]
        );

        // Setup line items from each of the presenter's items
        foreach ($serviceChange->items() as $item) {
            // Tax has to be deconstructed since the presenter's tax amounts
            // cannot be passed along
            $items[] = [
                'qty' => $item->qty,
                'amount' => $item->price,
                'description' => $item->description,
                'tax' => !empty($item->taxes)
            ];
        }

        // Add a line item for each discount amount
        foreach ($serviceChange->discounts() as $discount) {
            // The total discount is the negated total
            $items[] = [
                'qty' => 1,
                'amount' => (-1 * $discount->total),
                'description' => $discount->description,
                'tax' => false
            ];
        }

        $invoice_vars = [];
        $total = $serviceChange->totals()->total;
        if ($total > 0) {
            // Invoice the service change
            $invoice_vars = [
                'client_id' => $service->client_id,
                'date_billed' => date('c'),
                'date_due' => date('c'),
                'currency' => $service->package_pricing->currency,
                'lines' => $items
            ];
        }

        return $invoice_vars;
    }

    /**
     * Format ip array in to array used in views to display tabled ip data
     *
     * @param object $ip_address_data An object representing all ips
     * @return array An array of ip for the template
     */
    private function formatIPToView($ip_address_data) {
        $view_ready = array();

        if (isset($ip_address_data->ip_addresses) && isset($ip_address_data->editable_options)) {
            $ip_addresses = $ip_address_data->ip_addresses;
            $editable_options = $ip_address_data->editable_options;

            foreach($ip_addresses as $title => $address) {
                $view_ready[] = (object) array(
                    'header' => Language::_('VirtfusionDirectProvisioning.ipAddresses.'.$title, true),
                    'editable' => $editable_options[$title], // 1/0 for yes no
                    'ip_addresses' => $address // needs to be array
                );
            }
        }

        return $view_ready;
    }

    /**
     *
     *
     * @param stdClass $service_fields A stdClass object representing the current service_fields
     * @param array $vars An array of user supplied info to satisfy the request
     * @return null
     */
    private function adjustIpAddresses($module_row, $service_fields, $vars) {
        $edit_qty = 0;
        $current_qty = 0;
        $extra_ips = array();
        $ips_to_remove = array();
        $new_extra_ips = array();
        $err_msg = '';


        // Get and load api
        $api = $this->getApi($module_row->meta->api_token, $module_row->meta->hostname);
        $api->loadCommand('virtfusion_server');
        $server_api = new VirtfusionServer($api);

        // Explode will add empty element if empty
        // lets make sure its not
        if (isset($service_fields->{'additional_num_ips'}) && !empty($service_fields->{'additional_num_ips'})) {
            $extra_ips = explode(',', $service_fields->{'additional_num_ips'});
            $current_qty = count($extra_ips);
        }

        // Get the new updated count
        if (isset($vars['configoptions']['additional_num_ips'])) {
            $edit_qty = (int) $vars['configoptions']['additional_num_ips'];
        }

        if (isset($vars['virtfusion_extra_ip_to_remove'])) {
            $ips_to_remove = $vars['virtfusion_extra_ip_to_remove'];
        }

        if ($current_qty < $edit_qty) {
            $diff_qty = $edit_qty - $current_qty;

            $request = $server_api->addIpv4Qty($service_fields->virtfusion_server_id, $diff_qty);
            $this->log($module_row->meta->hostname, serialize($request), "output", $request['info']['http_code'] == '200');
            $response = json_decode($request['response'], true);

            if ($request['info']['http_code'] != '200') {
                $err_msg = 'There was an error while adding IP Addresses';
            } else {
                $new_extra_ips = array_merge($extra_ips, $response['data']);
            }

        } else if ($current_qty > $edit_qty) {
            // REMOVE
            $diff_qty = $current_qty - $edit_qty;

            // check to make sure we removing same ammount
            // as ips we have to remove
            if ($diff_qty == count($ips_to_remove)) {
                $new_extra_ips = array_diff($extra_ips, $ips_to_remove);

                $request = $server_api->removeIpv4($service_fields->virtfusion_server_id, $ips_to_remove);
                $this->log($module_row->meta->hostname, serialize($request), "output", $request['info']['http_code'] == '204');
                if ($request['info']['http_code'] != '204') {
                    $err_msg = 'There was an error while removing IP Addresses';
                }

            } else {
                $err_msg = "Extra IP addresses to be removed did not match number of IPs being removed!";
            }
        }

        if (empty($err_msg) && $current_qty != $edit_qty) {
            // should we do this here?
            $service_fields->{'additional_num_ips'} = implode(',', $new_extra_ips);
        }

        return array(
            'service_fields' => $service_fields,
            'errors' => [
                'err_msg' => $err_msg
            ]
        );
    }

    /**
     * Handles data for the IPs tab in the client and admin interfaces
     * @see VirtfusionDirectProvisioning::tabIPs() and VirtfusionDirectProvisioning::tabClientIPs()
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param bool $client True if the action is being performed by the client, false otherwise
     * @return array An array of vars for the template
     */
    private function getClientIpAddresses($package, $service, array $get = null, array $post = null, $client = false) {
        Loader::loadModels($this, ['Services']);

        // Get the service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $module_row = $this->getModuleRow($package->module_row);

        // define items we will return
        $main_ip = array();
        $base_ips = array();
        $additional_ips = array();
        $option_addable = false;

        // determine if we can add more IPs
        foreach($service->options as $option) {
            if ($option->option_name == 'additional_num_ips') {
                $option_addable = $option->option_addable;
            }
        }

        // set main ip
        if (isset($service_fields->virtfusion_ip)) {
            $main_ip = explode(',', $service_fields->virtfusion_ip);
        }

        if (isset($service_fields->{'virtfusion-base_ips'}) && !empty($service_fields->{'virtfusion-base_ips'})) {
            $base_ips = explode(',', $service_fields->{'virtfusion-base_ips'});
        }

        if (isset($service_fields->{'additional_num_ips'}) && !empty($service_fields->{'additional_num_ips'})) {
            $additional_ips = explode(',', $service_fields->{'additional_num_ips'});
        }

        if (isset($service_fields->virtfusion_ipv6_cidr)) {
            $ipv6 = explode(',', $service_fields->virtfusion_ipv6_cidr);
        }

        // Determine whether the service option for custom IPs is editable by the client
        $option_editable = !$client;
        if ($client) {
            foreach ($service->options as $option) {
                if ($option->option_name == 'additional_num_ips') {
                    $option_editable = ($option->option_editable == 1);
                    break;
                }
            }
        }

        // for consistency make it an opject
        return (object) array(
            'ip_addresses' => array(
                'main' => $main_ip,
                'base' => $base_ips,
                'extra' => array_values($additional_ips ?? []),
                'ipv6' => array_values($ipv6 ?? []),
            ),
            'editable_options' => array(
                'main' => false,
                'base' => false,
                'extra' => $option_editable,
                'ipv6' => false
            ),
            'addable' => $option_addable
        );
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * client interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getClientServiceInfo($service, $package)
    {
        $row = $this->getModuleRow();

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('client_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'virtfusion_direct_provisioning' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $this->serviceFieldsToObject($service->fields));

        return $this->view->fetch();
    }


    /** Simple function to get user add/edit avail actions */
    private function getServiceOption($package_id, $service_name) {
        $options = array();

        Loader::loadModels($this, ['PackageOptions']);
    	$package_options = $this->PackageOptions->getByPackageId($package_id);

        foreach($package_options as $option) {
            if ($option->name == $service_name) {
                $options['addable'] = $option->addable;
                $options['editable'] = $option->editable;
            }
        }

        return $options;
    }

    /**
     * Returns all fields to display to an admin attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getAdminAddFields($package, $vars = null)
    {

        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();


        $hostname_field = $fields->label(Language::_("VirtfusionDirectProvisioning.option_fields.hostname.label", true), "hostname");
        $hostname_field->attach(
            $fields->fieldText(
                "virtfusion_hostname",
                $this->Html->ifSet($vars->virtfusion_hostname),
                array('id'=>'virtfusion_hostname', 'required'=>'required')
            )
        );
        // Set the field
        $fields->setField($hostname_field);
        unset($hostname_field);

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>".$this->getHostnameValidationJS()."</script>
        ");

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $hostname_field = $fields->label(Language::_("VirtfusionDirectProvisioning.option_fields.hostname.label", true), "hostname");
        $hostname_field->attach(
            $fields->fieldText(
                "virtfusion_hostname",
                $this->Html->ifSet($vars->virtfusion_hostname),
                array('id'=>'virtfusion_hostname', 'required'=>'required')
            )
        );
        // Set the field
        $fields->setField($hostname_field);
        unset($hostname_field);

        // Set the Server ID field
        $server_id = $fields->label(Language::_('VirtfusionDirectProvisioning.service_fields.server_id', true), 'virtfusion_direct_provisioning_server_id');
        $server_id->attach(
            $fields->fieldText(
                'virtfusion_server_id',
                (isset($vars->virtfusion_server_id) ? $vars->virtfusion_server_id : null),
                ['id' => 'virtfusion_direct_provisioning_server_id']
            )
        );
        $fields->setField($server_id);

        $extra_ips = array();
        // explode will add blank item to array if its empty
        if (isset($vars->{'additional_num_ips'}) && !empty($vars->{'additional_num_ips'})) {
            $ip_options = explode(',', $vars->{'additional_num_ips'});
            // set ips as keys and values;
            $extra_ips = array_combine($ip_options, $ip_options);
        }

        $service_options = $this->getServiceOption($package->id, 'additional_num_ips');
        if (!empty($service_options)) {
            $extra_ip_addresses = $fields->label(Language::_('VirtfusionDirectProvisioning.option_fields.extra_ip_addresses', true), 'virtfusion_direct_provisioning_extra_ip_addresses');
            $extra_ip_addresses->attach($fields->tooltip(Language::_('VirtfusionDirectProvisioning.option_fields.extra_ip_addresses.tooltip', true)));
            $extra_ip_addresses->attach(
                $fields->fieldMultiSelect(
                    'virtfusion_extra_ip_to_remove[]',
                    $extra_ips,
                    ['id' => 'virtfusion_extra_ip_to_remove']
                )
            );
            $fields->setField($extra_ip_addresses);
        }

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>".$this->getHostnameValidationJS()."</script>
        ");

        return $fields;
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getClientAddFields($package, $vars = null) {
        Loader::loadHelpers($this, array("Html"));

        $fields = new ModuleFields();

        // Create field label
        $hostname_field = $fields->label(Language::_("VirtfusionDirectProvisioning.option_fields.hostname.label", true), "hostname");
        // Create field and attach to label
        // Add a tooltip next to this field
        $tooltip = $fields->tooltip(Language::_("VirtfusionDirectProvisioning.option_fields.hostname.tooltip", true));
        $hostname_field->attach($tooltip);

        $hostname_field->attach(
            $fields->fieldText(
                "virtfusion_hostname",
                $this->Html->ifSet($vars->virtfusion_hostname),
                array('id'=>'virtfusion_hostname', 'required'=>'required')
            )
        );
        // Set the field
        $fields->setField($hostname_field);

        $service_options = $this->getServiceOption($package->id, 'additional_num_ips');
        if (!empty($service_options) && $service_options['addable'] == '1') {

        }

        $fields->setHtml("
            <style>.cst_error {border:2px solid red}</style>
            <script type='text/javascript'>".$this->getHostnameValidationJS()."</script>
        ");

        return $fields;
    }

    public function getClientEditFields($package, $vars = null) {
        die('why you no work');
    }

    /**
     * Returns an array of key values for fields stored for a module, package,
     * and service under this module, used to substitute those keys with their
     * actual module, package, or service meta values in related emails.
     *
     * @return array A multi-dimensional array of key/value pairs where each key
     *  is one of 'module', 'package', or 'service' and each value is a numerically
     *  indexed array of key values that match meta fields under that category.
     * @see Modules::addModuleRow()
     * @see Modules::editModuleRow()
     * @see Modules::addPackage()
     * @see Modules::editPackage()
     * @see Modules::addService()
     * @see Modules::editService()
     */
    public function getEmailTags()
    {
        return [
            'module' => [],
            'package' => [],
            'service' => [
                'virtfusion_hostname',
                'virtfusion_password',
                'virtfusion_ip',
                'virtfusion-base_ips',
                'virtfusion_ipv6_cidr'
            ]
        ];
    }

    /**
     * Validates that the given hostname is valid
     *
     * @param string $host_name The host name to validate
     * @return bool True if the hostname is valid, false otherwise
     */
    public function validateHostname($host_name) {
        if (strlen($host_name) > 255) {
            return false;
        }

        $octet = "([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])";
        $nested_octet = "(\." . $octet . ')';
        $hostname_regex = '/^' . $octet . $nested_octet . $nested_octet . '+$/i';

        $valid = $this->Input->matches($host_name, $hostname_regex);

        return $valid;
    }

    /**
     * Similar to @VirtfusionDirectProvisioning:validateHostname
     * but for validating on the front end
     */
    private function getHostnameValidationJS() {
        $str = "
            $(document).ready(function() {
                $('#virtfusion_hostname').focusout(function() {
                    const hostname = $(this).val()
                    const regex_str = /^([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])(\.([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]))(\.([a-z0-9]|[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]))+$/i
                    if (!regex_str.test(hostname)) {
                        alert('" . Language::_('VirtfusionDirectProvisioning.client.!error.host.valid', true) . "')
                        $(this).addClass('cst_error')
                    }
                }).focusin(function() {
                    $(this).removeClass('cst_error');
                });
            })";

        return $str;
    }
}
