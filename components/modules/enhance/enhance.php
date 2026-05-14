<?php
use Blesta\Core\Util\Validate\Server;
/**
 * Enhance Module
 *
 * @link https://www.blesta.com Phillips Data, Inc.
 */
class Enhance extends Module
{

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load the language required by this module
        Language::loadLang('enhance', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Configure::load('enhance', dirname(__FILE__) . DS . 'config' . DS);
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
////        if (version_compare($current_version, '1.1.0', '<')) {
////            // Preform actions here such as re-adding cron tasks, setting new meta fields, and more
////        }
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);

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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('vars', (object) $vars);

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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = $module_row->meta;
        }

        $this->view->set('vars', (object) $vars);

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
        $meta_fields = ['server_label', 'hostname', 'org_id', 'api_token'];
        $encrypted_fields = [];

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
        $meta_fields = ['server_label','hostname','org_id','api_token'];
        $encrypted_fields = [];

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
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
            'server_label' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Enhance.!error.server_label.empty', true)
                ]
            ],
            'hostname' => [
                'format' => [
                    'rule' => [[$this, 'validateHostName']],
                    'message' => Language::_('Enhance.!error.hostname.format', true)
                ]
            ],
            'org_id' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Enhance.!error.org_id.empty', true)
                ]
            ],
            'api_token' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Enhance.!error.api_token.empty', true)
                ],
                'valid' => [
                    'rule' => [[
                        $this, 'validateConnection'],
                        ['_linked' => 'hostname'],
                        ['_linked' => 'org_id']
                    ],
                    'message' => Language::_('Enhance.!error.api_token.valid', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Validates that the given hostname is valid.
     *
     * @param string $host_name The host name to validate
     * @return bool True if the hostname is valid, false otherwise
     */
    public function validateHostName($host_name)
    {
        $validator = new Server();
        return $validator->isDomain($host_name) || $validator->isIp($host_name);
    }

    /**
     * Validates the API connection
     *
     * @param string $api_token The API token to validate
     * @param string $hostname The hostname
     * @param string $org_id The organization ID
     * @return bool True if the connection is valid, false otherwise
     */
    public function validateConnection($api_token, $hostname = null, $org_id = null)
    {
        if ($hostname && $org_id && $api_token) {
            try {
                $api = $this->getApi('test', $hostname, $org_id, $api_token);

                // Log the API connection attempt
                $this->log($hostname . '|validateConnection', serialize(['hostname' => $hostname, 'org_id' => $org_id]), 'input', true);

                $response = $api->testConnection();
                $success = !($response->errors());

                // Log the response
                $this->log($hostname . '|validateConnection', serialize($response->raw()), 'output', $success);

                return $success;
            } catch (Exception $e) {
                // Log the exception
                $this->log($hostname . '|validateConnection', serialize(['error' => $e->getMessage()]), 'output', false);
                return false;
            }
        }

        return false;
    }


    /**
     * Returns an array of available service deligation order methods. The module
     * will determine how each method is defined. For example, the method "first"
     * may be implemented such that it returns the module row with the least number
     * of services assigned to it.
     *
     * @return array An array of order methods in key/value paris where the key is the
     *  type to be stored for the group and value is the name for that option
     * @see Module::selectModuleRow()
     */
    public function getGroupOrderOptions()
    {
        return [
            'roundrobin' => Language::_('Enhance.order_options.roundrobin', true),
            'first' => Language::_('Enhance.order_options.first', true)
        ];
    }

    /**
     * Returns the name to display for the given module row
     *
     * @param stdClass $module_row The module row
     * @return string The name to display for this module row
     */
    public function getModuleRowName($module_row)
    {
        return $module_row->meta->server_label ?? $module_row->meta->hostname ?? 'Enhance Server';
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

        // Fetch available plans from the API
        $plans = [];
        $row = $this->getModuleRow($vars->module_row ?? null);

        if ($row) {
            try {
                $api = $this->getApi($row->meta->server_label, $row->meta->hostname, $row->meta->org_id, $row->meta->api_token);
                $response = $api->getPlans();

                if (!$response->errors()) {
                    $plansData = $response->response();

                    // Handle different response structures
                    if (isset($plansData->items) && is_array($plansData->items)) {
                        foreach ($plansData->items as $plan) {
                            $plans[$plan->id] = $plan->name ?? 'Plan ' . $plan->id;
                        }
                    } elseif (isset($plansData->data) && is_array($plansData->data)) {
                        foreach ($plansData->data as $plan) {
                            $plans[$plan->id] = $plan->name ?? 'Plan ' . $plan->id;
                        }
                    } elseif (is_array($plansData)) {
                        foreach ($plansData as $plan) {
                            if (is_object($plan) && isset($plan->id)) {
                                $plans[$plan->id] = $plan->name ?? 'Plan ' . $plan->id;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Log the error but continue with empty plans array
                $this->log($row->meta->hostname . '|getPackageFields', 'Failed to fetch plans: ' . $e->getMessage(), 'output', false);
            }
        }

        // Set the Package field
        $package = $fields->label(Language::_('Enhance.package_fields.package', true), 'enhance_package');

        if (!empty($plans)) {
            // Create a select field with available plans
            $package->attach(
                $fields->fieldSelect(
                    'meta[package]',
                    $plans,
                    (isset($vars->meta['package']) ? $vars->meta['package'] : null),
                    ['id' => 'enhance_package']
                )
            );
        } else {
            // Fallback to text field if no plans are available
            $package->attach(
                $fields->fieldText(
                    'meta[package]',
                    (isset($vars->meta['package']) ? $vars->meta['package'] : null),
                    ['id' => 'enhance_package']
                )
            );

            // Add a tooltip to inform the user
            $tooltip = $fields->tooltip(Language::_('Enhance.package_fields.package_tooltip', true));
            $package->attach($tooltip);
        }

        $fields->setField($package);

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
    ) {
        $row = $this->getModuleRow();

        if (!$row) {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('Enhance.!error.module_row.missing', true)]]
            );
            return;
        }

        $api = $this->getApi($row->meta->server_label, $row->meta->hostname, $row->meta->org_id, $row->meta->api_token);

        // Set unset checkboxes
        $checkbox_fields = [];

        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $params = $this->getFieldsFromInput((array) $vars, $package);

        $this->validateService($package, $vars);

        if ($this->Input->errors()) {
            return;
        }

        $website_id = null;
        $subscription_id = null;
        $customer_org_id = null;
        // Only provision the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            $masked_params = $params;
            $masked_params['password'] = '***';

            $this->log($row->meta->hostname . '|addService', serialize($masked_params), 'input', true);

            // Get client information for customer creation
            if (!isset($this->Clients)) {
                Loader::loadModels($this, ['Clients']);
            }
            $client = $this->Clients->get($vars['client_id']);
            $customer_name = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
            if (empty(trim($customer_name))) {
                $customer_name = $client->company ?? 'Customer';
            }
            $customer_email = $client->email ?? '';

            // Load ModuleClientMeta for customer data storage/retrieval
            if (!isset($this->ModuleClientMeta)) {
                Loader::loadModels($this, ['ModuleClientMeta']);
            }

            // Check if we already have customer data stored for this client
            $existing_org_id_obj = $this->ModuleClientMeta->get(
                $vars['client_id'],
                'enhance_org_id',
                $row->id
            );
            $existing_login_id_obj = $this->ModuleClientMeta->get(
                $vars['client_id'],
                'enhance_login_id',
                $row->id
            );

            // Extract values from objects (ModuleClientMeta returns objects with ->value property)
            $existing_org_id = $existing_org_id_obj ? $existing_org_id_obj->value : null;
            $existing_login_id = $existing_login_id_obj ? $existing_login_id_obj->value : null;

            // Debug logging
            //$this->log($row->meta->hostname . '|client_debug', 'Client data: ' . serialize($client), 'output', true);
            $this->log($row->meta->hostname . '|customer_info', 'Name: ' . $customer_name . ', Email: ' . $customer_email, 'output', true);

            if ($existing_org_id && $existing_login_id) {
                $this->log($row->meta->hostname . '|existing_customer', 'Found stored customer data - org_id: ' . $existing_org_id . ', login_id: ' . $existing_login_id, 'output', true);
            } else {
                $this->log($row->meta->hostname . '|new_customer', 'No stored customer data found, will create new customer', 'output', true);
            }

            // Create website with stored customer data (if available) or create new customer
            if ($existing_org_id && $existing_login_id) {
                // Use existing customer data
                $this->log($row->meta->hostname . '|reusing_customer', 'Reusing existing customer for website creation', 'output', true);
                $response = $api->createWebsiteForExistingCustomer(
                    $params['domain'],
                    $params['package'],
                    $existing_org_id,
                    $existing_login_id,
                    $params['password']
                );
            } else {
                // Create new customer and website
                $this->log($row->meta->hostname . '|creating_customer', 'Creating new customer and website', 'output', true);
                $response = $api->createWebsite(
                    $params['domain'],
                    $params['package'],
                    $customer_email,
                    $customer_name,
                    $params['password']
                );
            }

            // Debug logging to see what's happening
            //$this->log($row->meta->hostname . '|addService_debug', 'Create website result: ' . serialize($response), 'output', true);

            // Log whether we used existing or new customer
            if (isset($response['existing_customer'])) {
                $customer_type = $response['existing_customer'] ? 'existing' : 'new';
                $this->log($row->meta->hostname . '|customer_type', 'Customer type: ' . $customer_type, 'output', true);
            }

            // Log which endpoint and data was used for the successful request
            $lastRequest = $api->getLastRequest();
            //$this->log($row->meta->hostname . '|api_request_debug', 'Last request info: ' . serialize($lastRequest), 'output', true);

            // Log detailed customer search results
            $detailKeys = ['total_customers', 'getCustomers_error', 'no_customers_data', 'search_complete', 'match_found'];
            foreach ($detailKeys as $key) {
                if (isset($lastRequest[$key])) {
                    $this->log($row->meta->hostname . '|search_detail', $key . ': ' . $lastRequest[$key], 'output', true);
                }
            }

            // Log each customer found
            $customerKeys = array_filter(array_keys($lastRequest), function($key) {
                return strpos($key, 'customer_') === 0 && strpos($key, '_') === strrpos($key, '_');
            });
            foreach ($customerKeys as $key) {
                $this->log($row->meta->hostname . '|customer_list', $key . ': ' . $lastRequest[$key], 'output', true);
            }

            // Log member details
            $memberKeys = array_filter(array_keys($lastRequest), function($key) {
                return strpos($key, 'member_') === 0 || strpos($key, 'members_count_') === 0 || strpos($key, 'no_members_') === 0;
            });
            foreach ($memberKeys as $key) {
                $this->log($row->meta->hostname . '|member_detail', $key . ': ' . $lastRequest[$key], 'output', true);
            }

            $success = false;
            $actual_username = $params['username']; // Default fallback
            $actual_password = $params['password'];

            // Handle new array-based response structure
            if (isset($response['error'])) {
                $this->Input->setErrors(['api' => [$response['error']]]);
            } elseif (isset($response['success']) && $response['success']) {
                $website_id = $response['website_id'];
                $subscription_id = $response['subscription_id'];
                $customer_org_id = $response['customer_org_id'];
                $actual_password = $response['password']; // Use the actual password that was used
                $success = true;

                // Use actual username from API if available, otherwise fall back to generated username
                if (isset($response['actual_username']) && !empty($response['actual_username'])) {
                    $actual_username = $response['actual_username'];
                    $this->log($row->meta->hostname . '|actual_username', 'Using actual username from API: ' . $actual_username, 'output', true);
                } else {
                    $actual_username = $params['username']; // Fallback to generated username
                    $this->log($row->meta->hostname . '|fallback_username', 'Using fallback username: ' . $actual_username, 'output', true);
                }

                // Log SSH password setting result
                if (isset($response['ssh_password_set'])) {
                    $ssh_status = $response['ssh_password_set'] ? 'SUCCESS' : 'FAILED';
                    $this->log($row->meta->hostname . '|ssh_password', 'SSH password setting: ' . $ssh_status, 'output', true);
                }

                // Store customer data for future service creation (only for new customers)
                if (!$existing_org_id && $customer_org_id && isset($response['login_id'])) {
                    $this->ModuleClientMeta->set(
                        $vars['client_id'],
                        $row->id,
                        0,
                        [
                            ['key' => 'enhance_org_id', 'value' => $customer_org_id, 'encrypted' => 0],
                            ['key' => 'enhance_login_id', 'value' => $response['login_id'], 'encrypted' => 0]
                        ]
                    );
                    $this->log($row->meta->hostname . '|stored_customer', 'Stored customer data - org_id: ' . $customer_org_id . ', login_id: ' . $response['login_id'], 'output', true);
                }

                // Update params with actual values from API
                $params['username'] = $actual_username;
                $params['password'] = $actual_password;
            }

            $this->log($row->meta->hostname . '|addService', 'Website creation completed. Success: ' . ($success ? 'true' : 'false'), 'output', $success);

            if (!$success) {
                return;
            }
        }

        // Return service fields
        $service_fields = [
            [
                'key' => 'domain',
                'value' => $params['domain'],
                'encrypted' => 0
            ],
            [
                'key' => 'username',
                'value' => $params['username'],
                'encrypted' => 0
            ],
            [
                'key' => 'password',
                'value' => $params['password'],
                'encrypted' => 1
            ],
            [
                'key' => 'customer_email',
                'value' => $customer_email,
                'encrypted' => 0
            ]
        ];

        if (isset($website_id)) {
            $service_fields[] = [
                'key' => 'website_id',
                'value' => $website_id,
                'encrypted' => 0
            ];
        }

        if (isset($subscription_id)) {
            $service_fields[] = [
                'key' => 'subscription_id',
                'value' => $subscription_id,
                'encrypted' => 0
            ];
        }

        if (isset($customer_org_id)) {
            $service_fields[] = [
                'key' => 'customer_org_id',
                'value' => $customer_org_id,
                'encrypted' => 0
            ];
        }

        return $service_fields;
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

        $this->validateServiceEdit($package, $vars);

        if ($this->Input->errors()) {
            return;
        }

        // Only update the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            // Do nothing, management is handle through service tabs
        }

        // Return all the service fields
        $encrypted_fields = ['password'];
        $return = [];
        $fields = ['domain', 'username', 'password', 'website_id', 'customer_email'];
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
            $api = $this->getApi(
                $row->meta->server_label,
                $row->meta->hostname,
                $row->meta->org_id,
                $row->meta->api_token
            );

            $service_fields = $this->serviceFieldsToObject($service->fields);

            if (isset($service_fields->customer_org_id) && isset($service_fields->subscription_id)) {
                $this->log($row->meta->hostname . '|suspendService', 'Suspending subscription: ' . $service_fields->subscription_id, 'input', true);

                $response = $api->suspendWebsite($service_fields->customer_org_id, $service_fields->subscription_id);

                $success = false;

                if (($errors = $response->errors())) {
                    $this->Input->setErrors(['api' => $errors]);
                } else {
                    $success = true;
                }

                $this->log($row->meta->hostname . '|suspendService', 'Suspend result: ' . ($success ? 'success' : 'failed'), 'output', $success);
            } else {
                $this->log($row->meta->hostname . '|suspendService', 'Missing customer_org_id or subscription_id', 'output', false);
                $this->Input->setErrors(['api' => ['Missing required service fields for suspension']]);
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
            $api = $this->getApi(
                $row->meta->server_label,
                $row->meta->hostname,
                $row->meta->org_id,
                $row->meta->api_token
            );

            $service_fields = $this->serviceFieldsToObject($service->fields);

            if (isset($service_fields->customer_org_id) && isset($service_fields->subscription_id)) {
                $this->log($row->meta->hostname . '|unsuspendService', 'Unsuspending subscription: ' . $service_fields->subscription_id, 'input', true);

                $response = $api->unsuspendWebsite($service_fields->customer_org_id, $service_fields->subscription_id);

                $success = false;

                if (($errors = $response->errors())) {
                    $this->Input->setErrors(['api' => $errors]);
                } else {
                    $success = true;
                }

                $this->log($row->meta->hostname . '|unsuspendService', 'Unsuspend result: ' . ($success ? 'success' : 'failed'), 'output', $success);
            } else {
                $this->log($row->meta->hostname . '|unsuspendService', 'Missing customer_org_id or subscription_id', 'output', false);
                $this->Input->setErrors(['api' => ['Missing required service fields for unsuspension']]);
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
            $api = $this->getApi(
                $row->meta->server_label,
                $row->meta->hostname,
                $row->meta->org_id,
                $row->meta->api_token
            );

            $service_fields = $this->serviceFieldsToObject($service->fields);

            if (isset($service_fields->customer_org_id) && isset($service_fields->subscription_id)) {
                $this->log($row->meta->hostname . '|cancelService', 'Deleting subscription: ' . $service_fields->subscription_id, 'input', true);

                $response = $api->deleteWebsite($service_fields->customer_org_id, $service_fields->subscription_id);

                $success = false;

                if (($errors = $response->errors())) {
                    $this->Input->setErrors(['api' => $errors]);
                } else {
                    $success = true;
                }

                $this->log($row->meta->hostname . '|cancelService', 'Delete result: ' . ($success ? 'success' : 'failed'), 'output', $success);
            } else {
                $this->log($row->meta->hostname . '|cancelService', 'Missing customer_org_id or subscription_id', 'output', false);
                $this->Input->setErrors(['api' => ['Missing required service fields for cancellation']]);
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
        $rules = [
            'domain' => [
                'format' => [
                    'if_set' => $edit,
                    'rule' => [[$this, 'validateDomain']],
                    'message' => Language::_('Enhance.!error.domain.format', true)
                ]
            ],
            'username' => [
                'format' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateUsername']],
                    'message' => Language::_('Enhance.!error.username.format', true)
                ]
            ],
            'password' => [
                'length' => [
                    'if_set' => true,
                    'rule' => ['minLength', 8],
                    'message' => Language::_('Enhance.!error.password.length', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Validates that the given domain name is valid.
     *
     * @param string $domain The domain name to validate
     * @return bool True if the domain is valid, false otherwise
     */
    public function validateDomain($domain)
    {
        $validator = new Server();
        return $validator->isDomain($domain) || $validator->isIp($domain);
    }

    /**
     * Validates that the given username is valid.
     *
     * @param string $username The username to validate
     * @return bool True if the username is valid, false otherwise
     */
    public function validateUsername($username)
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{2,15}$/', $username);
    }

    /**
     * Initializes the EnhanceApi and returns an instance of that object.
     *
     * @param string $server_label The server label for identification
     * @param string $hostname The Enhance server hostname
     * @param string $org_id The organization ID in Enhance
     * @param string $api_token The API token for authentication
     * @return EnhanceApi The EnhanceApi instance
     */
    private function getApi($server_label, $hostname, $org_id, $api_token)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'enhance_api.php');

        $api = new EnhanceApi($server_label, $hostname, $org_id, $api_token);

        return $api;
    }

    /**
     * Returns an array of service field to set for the service using the given input
     *
     * @param array $vars An array of key/value input pairs
     * @param stdClass $package A stdClass object representing the package for the service
     * @return array An array of key/value pairs representing service fields
     */
    private function getFieldsFromInput(array $vars, $package)
    {
        $domain = isset($vars['domain']) ? strtolower($vars['domain']) : null;
        $username = !empty($vars['username'])
            ? $vars['username']
            : $this->generateUsername($domain);
        $password = !empty($vars['password']) ? $vars['password'] : $this->generatePassword();

        // Get package name from the package meta
        $package_name = isset($package->meta->package) ? $package->meta->package : 'default';

        $fields = [
            'domain' => $domain,
            'username' => $username,
            'password' => $password,
            'package' => $package_name
        ];

        return $fields;
    }

    /**
     * Generates a username from the given host name.
     *
     * @param string $host_name The host name to use to generate the username
     * @return string The username generated from the given hostname
     */
    private function generateUsername($host_name)
    {
        // Remove everything except letters and numbers from the domain
        // ensure no number appears in the beginning
        $username = ltrim(preg_replace('/[^a-z0-9]/i', '', $host_name), '0123456789');

        $length = strlen($username);
        $pool = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $pool_size = strlen($pool);

        if ($length < 5) {
            for ($i = $length; $i < 8; $i++) {
                $username .= substr($pool, mt_rand(0, $pool_size - 1), 1);
            }
            $length = strlen($username);
        }

        $username = substr($username, 0, min($length, 16));

        return $username;
    }

    /**
     * Generates a password that meets Enhance requirements.
     * Requirements: At least 1 upper and lowercase letter, a number, a special character, and minimum length of 10
     *
     * @param int $min_length The minimum character length for the password (10 or larger)
     * @param int $max_length The maximum character length for the password (16 or fewer)
     * @return string The generated password
     */
    private function generatePassword($min_length = 12, $max_length = 16)
    {
        $length = mt_rand(max($min_length, 10), min($max_length, 16));

        // Character sets
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $specials = '!@#$%^&*()';

        // Ensure we have at least one of each required type
        $password = '';
        $password .= $lowercase[mt_rand(0, strlen($lowercase) - 1)]; // At least 1 lowercase
        $password .= $uppercase[mt_rand(0, strlen($uppercase) - 1)]; // At least 1 uppercase
        $password .= $numbers[mt_rand(0, strlen($numbers) - 1)];     // At least 1 number
        $password .= $specials[mt_rand(0, strlen($specials) - 1)];   // At least 1 special char

        // Fill remaining length with random characters from all sets
        $all_chars = $lowercase . $uppercase . $numbers . $specials;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all_chars[mt_rand(0, strlen($all_chars) - 1)];
        }

        // Shuffle the password to randomize the order
        $password = str_shuffle($password);

        return $password;
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);
        $login_url = $this->generateSsoLoginUrl($service_fields, $row);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $service_fields);
        $this->view->set('login_url', $login_url);

        return $this->view->fetch();
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);
        $login_url = $this->generateSsoLoginUrl($service_fields, $row);

        $this->view->set('module_row', $row);
        $this->view->set('package', $package);
        $this->view->set('service', $service);
        $this->view->set('service_fields', $service_fields);
        $this->view->set('login_url', $login_url);

        return $this->view->fetch();
    }

    /**
     * Generate SSO login URL for a service
     *
     * @param stdClass $service_fields The service fields object
     * @param stdClass $row The module row
     * @return string|null The SSO login URL or null if generation fails
     */
    private function generateSsoLoginUrl($service_fields, $row)
    {
        $login_url = null;

        // Generate SSO login URL if we have the necessary information
        if (isset($service_fields->customer_org_id) && $row) {
            $api = $this->getApi($row->meta->server_label, $row->meta->hostname, $row->meta->org_id, $row->meta->api_token);

            $this->log($row->meta->hostname . '|sso_debug', 'Starting SSO generation for customer_org_id: ' . $service_fields->customer_org_id, 'input', true);

            // Get customer org members and find owner
            try {
                $membersResponse = $api->getCustomerOrgMembers($service_fields->customer_org_id);
                if (!$membersResponse->errors()) {
                    $members = $membersResponse->response();
                    $owner = null;
                    if (isset($members->items) && is_array($members->items)) {
                        foreach ($members->items as $member) {
                            $roles = $member->roles ?? [];
                            if (in_array('Owner', $roles, true)) {
                                $owner = $member;
                                break;
                            }
                        }
                    }
                    if ($owner && isset($owner->id)) {
                        $otp_response = $api->generateSsoLink($service_fields->customer_org_id, $owner->id);
                    } else {
                        $this->log($row->meta->hostname . '|sso_error', 'No owner member found', 'output', false);
                        $otp_response = null;
                    }
                } else {
                    $this->log($row->meta->hostname . '|sso_error', 'Failed to get members: ' . serialize($membersResponse->errors()), 'output', false);
                    $otp_response = null;
                }
            } catch (Exception $e) {
                $this->log($row->meta->hostname . '|sso_error', 'SSO exception: ' . $e->getMessage(), 'output', false);
                $otp_response = null;
            }

            if ($otp_response && !$otp_response->errors()) {
                $otp_result = $otp_response->response();
                $this->log($row->meta->hostname . '|sso_debug', 'SSO response: ' . json_encode($otp_result), 'output', true);

                // Response should be a string URL
                if (is_string($otp_result)) {
                    $login_url = trim($otp_result, '"');
                    $this->log($row->meta->hostname . '|sso_success', 'Generated SSO URL: ' . $login_url, 'output', true);
                } elseif (isset($otp_result->url)) {
                    $login_url = $otp_result->url;
                    $this->log($row->meta->hostname . '|sso_success', 'Generated SSO URL from object: ' . $login_url, 'output', true);
                } else {
                    $this->log($row->meta->hostname . '|sso_error', 'SSO response received but no URL found: ' . json_encode($otp_result), 'output', false);
                }
            } elseif ($otp_response) {
                $this->log($row->meta->hostname . '|sso_error', 'SSO failed: ' . serialize($otp_response->errors()) . ' Status: ' . $otp_response->status(), 'output', false);
            }

        } else {
            $missing = [];
            if (!isset($service_fields->customer_org_id)) $missing[] = 'customer_org_id';
            if (!$row) $missing[] = 'module_row';
            $this->log($row ? $row->meta->hostname : 'unknown' . '|sso_error', 'Missing required fields for SSO: ' . implode(', ', $missing), 'output', false);
        }

        return $login_url;
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
            'tabClientChangePassword' => Language::_('Enhance.tabClientChangePassword', true)
        ];
    }

    /**
     * Returns all tabs to display to an admin when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title.
     *  Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getAdminTabs($package)
    {
        return [
            'tabChangePassword' => Language::_('Enhance.tabChangePassword', true)
        ];
    }

    /**
     * tabChangePassword
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabChangePassword(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        // Load ModuleClientMeta for customer data storage/retrieval
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $this->view = new View('tabChangePassword', 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);

        if (!empty($post)) {
            Loader::loadModels($this, ['Services']);

            // Validate password
            if (isset($post['password']) && !empty($post['password'])) {
                $row = $this->getModuleRow();

                if ($row) {
                    $api = $this->getApi(
                        $row->meta->server_label,
                        $row->meta->hostname,
                        $row->meta->org_id,
                        $row->meta->api_token
                    );

                    $existing_login_id_obj = $this->ModuleClientMeta->get(
                        $service->client_id,
                        'enhance_login_id',
                        $row->id
                    );

                    if (isset($existing_login_id_obj->value)) {
                        $this->log($row->meta->hostname . '|resetPassword', serialize($existing_login_id_obj->value), 'input', true);

                        $response = $api->updateLoginPassword($existing_login_id_obj->value, $post['password']);

                        $success = false;

                        if (($errors = $response->errors())) {
                            $this->Input->setErrors(['password' => ['change' => Language::_('Enhance.!error.password.change', true)]]);
                        } else {
                            $success = true;

                            // Update the service password field
                            $this->Services->edit($service->id, ['password' => $post['password']]);

                            $this->setMessage('success', Language::_('Enhance.success.password.changed', true));
                        }

                        $this->log($row->meta->hostname . '|resetPassword', serialize($response->raw()), 'output', $success);
                    }
                }
            }

            if ($this->Services->errors()) {
                $this->Input->setErrors($this->Services->errors());
            }

            $vars = (object)$post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', (isset($vars) ? $vars : new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);
        return $this->view->fetch();
    }

    /**
     * tabClientChangePassword
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientChangePassword(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        // Load ModuleClientMeta for customer data storage/retrieval
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        $this->view = new View('tabClientChangePassword', 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $service_fields = $this->serviceFieldsToObject($service->fields);

        if (!empty($post)) {
            Loader::loadModels($this, ['Services']);

            // Validate password
            if (isset($post['password']) && !empty($post['password'])) {
                $row = $this->getModuleRow();

                if ($row) {
                    $api = $this->getApi(
                        $row->meta->server_label,
                        $row->meta->hostname,
                        $row->meta->org_id,
                        $row->meta->api_token
                    );

                    $existing_login_id_obj = $this->ModuleClientMeta->get(
                        $service->client_id,
                        'enhance_login_id',
                        $row->id
                    );

                    if (isset($existing_login_id_obj->value)) {
                        $this->log($row->meta->hostname . '|resetPassword', serialize($existing_login_id_obj->value), 'input', true);

                        $response = $api->updateLoginPassword($existing_login_id_obj->value, $post['password']);

                        $success = false;

                        if (($errors = $response->errors())) {
                            $this->Input->setErrors(['password' => ['change' => Language::_('Enhance.!error.password.change', true)]]);
                        } else {
                            $success = true;

                            // Update the service password field
                            $this->Services->edit($service->id, ['password' => $post['password']]);

                            $this->setMessage('success', Language::_('Enhance.success.password.changed', true));
                        }

                        $this->log($row->meta->hostname . '|resetPassword', serialize($response->raw()), 'output', $success);
                    }
                }
            }

            if ($this->Services->errors()) {
                $this->Input->setErrors($this->Services->errors());
            }

            $vars = (object)$post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('vars', (isset($vars) ? $vars : new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'enhance' . DS);
        return $this->view->fetch();
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
        return $this->getServiceFields($vars);
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
        return $this->getServiceFields($vars);
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getClientAddFields($package, $vars = null)
    {
        return $this->getServiceFields($vars);
    }

    /**
     * Returns all fields to display to a client attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    public function getClientEditFields($package, $vars = null)
    {
        return $this->getServiceFields($vars);
    }

    /**
     * A centralized method for fetching service fields since they are the same for admin/client add/edit
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render
     *  as well as any additional HTML markup to include
     */
    private function getServiceFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        // Set the Domain field
        $domain = $fields->label(Language::_('Enhance.service_fields.domain', true), 'enhance_domain');
        $domain->attach(
            $fields->fieldText(
                'domain',
                (isset($vars->domain) ? $vars->domain : null),
                ['id' => 'enhance_domain']
            )
        );
        $fields->setField($domain);

        return $fields;
    }
}
