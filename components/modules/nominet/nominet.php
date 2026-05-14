<?php

use Blesta\Core\Util\Validate\Server;

/**
 * Nominet Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.nominet
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Nominet extends RegistrarModule
{
    /**
     * @var array An array containing the EPP servers for live and sandbox requests
     */
    private $endpoint = [
        'live' => [
            'secure' => ['server' => 'epp.nominet.org.uk', 'port' => 700],
            'insecure' => ['server' => 'epp.nominet.org.uk', 'port' => 8700]
        ],
        'sandbox' => [
            'secure' => ['server' => 'testbed-epp.nominet.org.uk', 'port' => 700],
            'insecure' => ['server' => 'testbed-epp.nominet.org.uk', 'port' => 8700]
        ]
    ];

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load the language required by this module
        Language::loadLang('nominet', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Configure::load('nominet', dirname(__FILE__) . DS . 'config' . DS);
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (!empty($vars)) {
            // Set unset checkboxes
            $checkbox_fields = ['secure', 'sandbox'];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
        }

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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = $module_row->meta;
        } else {
            // Set unset checkboxes
            $checkbox_fields = ['secure', 'sandbox'];

            foreach ($checkbox_fields as $checkbox_field) {
                if (!isset($vars[$checkbox_field])) {
                    $vars[$checkbox_field] = 'false';
                }
            }
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
        $meta_fields = ['username', 'password', 'secure', 'sandbox'];
        $encrypted_fields = ['password'];

        // Set unset checkboxes
        $checkbox_fields = ['secure', 'sandbox'];

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
        $meta_fields = ['username', 'password', 'secure', 'sandbox'];
        $encrypted_fields = ['password'];

        // Set unset checkboxes
        $checkbox_fields = ['secure', 'sandbox'];

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
     * Builds and returns the rules required to add/edit a module row (e.g. server).
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules(&$vars)
    {
        $rules = [
            'username' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Nominet.!error.username.valid', true)
                ]
            ],
            'password' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Nominet.!error.password.valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        $vars['username'],
                        $vars['secure'],
                        $vars['sandbox']
                    ],
                    'message' => Language::_('Nominet.!error.password.valid_connection', true)
                ]
            ],
            'secure' => [
                'format' => [
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Nominet.!error.secure.format', true)
                ]
            ],
            'sandbox' => [
                'format' => [
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Nominet.!error.sandbox.format', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Validates whether or not the connection details are valid.
     *
     * @param string $password The Nominet password
     * @param string $username The Nominet userbane
     * @param string $secure 'true' to use a secure connection
     * @param string $sandbox 'true' to use the sandbox server
     * @return bool True if the connection is valid, false otherwise
     */
    public function validateConnection($password, $username, $secure = 'false', $sandbox = 'false')
    {
        try {
            $api = $this->getApi($username, $password, $secure, $sandbox);

            // Check with the credentials with the EPP server
            $availability = $this->request($api, new Metaregistrar\EPP\eppCheckDomainRequest(['nominet.org.uk']));
            if ($availability == false || empty($availability->getCheckedDomains())) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            // Trap any errors encountered, could not validate connection
        }

        return false;
    }

    /**
     * Validates that the given domain is valid.
     *
     * @param string $domain The domain to validate
     * @return bool True if the domain is valid, false otherwise
     */
    public function validateDomain($domain)
    {
        $validator = new Server();

        return $validator->isDomain($domain);
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
     * Validates that at least 2 name servers are set in the given array of name servers.
     *
     * @param array $name_servers An array of name servers
     * @return bool True if the array count is >= 2, false otherwise
     */
    public function validateNameServerCount($name_servers)
    {
        if (is_array($name_servers) && count($name_servers) >= 2) {
            return true;
        }

        return false;
    }

    /**
     * Validates that the nameservers given are formatted correctly.
     *
     * @param array $name_servers An array of name servers
     * @return bool True if every name server is formatted correctly, false otherwise
     */
    public function validateNameServers($name_servers)
    {
        if (is_array($name_servers)) {
            foreach ($name_servers as $name_server) {
                if (!$this->validateHostName($name_server)) {
                    return false;
                }
            }
        }

        return true;
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
            'roundrobin' => Language::_('Nominet.order_options.roundrobin', true),
            'first' => Language::_('Nominet.order_options.first', true)
        ];
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
            if (!isset($vars['meta'] )) {
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
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage($package, array $vars = null)
    {
        // Set rules to validate input data
        $this->Input->setRules($this->getPackageRules($vars));

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            if (!isset($vars['meta'] )) {
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
     * Builds and returns rules required to be validated when adding/editing a package.
     *
     * @param array &$vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getPackageRules(array &$vars)
    {
        // Validate the package fields
        $rules = [
            'epp_code' => [
                'valid' => [
                    'ifset' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => Language::_('Nominet.!error.epp_code.valid', true)
                ]
            ],
            'ns' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateNameServers'], true],
                    'message' => Language::_('Nominet.!error.ns.valid', true)
                ]
            ]
        ];

        // Remove empty nameservers
        foreach ($vars['ns'] ?? [] as $key => $ns) {
            if (empty($ns)) {
                unset($vars['ns'][$key]);
            }
        }

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

        // Set the EPP Code field
        $epp_code = $fields->label(Language::_('Nominet.package_fields.epp_code', true), 'nominet_epp_code');
        $epp_code->attach(
            $fields->fieldCheckbox(
                'meta[epp_code]',
                'true',
                ($vars->meta['epp_code'] ?? null) == 'true',
                ['id' => 'nominet_epp_code']
            )
        );
        // Add tooltip
        $tooltip = $fields->tooltip(Language::_('Nominet.package_field.tooltip.epp_code', true));
        $epp_code->attach($tooltip);
        $fields->setField($epp_code);

        // Set all TLD checkboxes
        $tld_options = $fields->label(Language::_('Nominet.package_fields.tld_options', true));

        $tlds = $this->getTlds();
        sort($tlds);

        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, 'tld_' . $tld);
            $tld_options->attach(
                $fields->fieldCheckbox(
                    'meta[tlds][]',
                    $tld,
                    (isset($vars->meta['tlds']) && in_array($tld, $vars->meta['tlds'])),
                    ['id' => 'tld_' . $tld],
                    $tld_label
                )
            );
        }
        $fields->setField($tld_options);

        // Set nameservers
        for ($i=1; $i<=5; $i++) {
            $type = $fields->label(Language::_('Nominet.package_fields.ns' . $i, true), 'nominet_ns' . $i);
            $type->attach(
                $fields->fieldText(
                    'meta[ns][]',
                    (isset($vars->meta['ns'][$i-1]) ? $vars->meta['ns'][$i-1] : null),
                    ['id' => 'nominet_ns' . $i]
                )
            );
            $fields->setField($type);
        }

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
        if (($row = $this->getModuleRow())) {

            // Validate service
            $this->validateService($package, $vars);
            if ($this->Input->errors()) {
                return;
            }

            // Format input
            $vars = $this->getFieldsFromInput($vars, $package);

            // Only provision the service if 'use_module' is true
            if ($vars['use_module'] == 'true') {
                // Get contact from client
                if (!isset($this->Clients)) {
                    Loader::loadModels($this, ['Clients']);
                }
                if (!isset($this->Contacts)) {
                    Loader::loadModels($this, ['Contacts']);
                }

                $client = $this->Clients->get($vars['client_id']);
                if ($client) {
                    $contact_numbers = $this->Contacts->getNumbers($client->contact_id);
                }

                // Register domain
                $params = [
                    'contact' => [
                        'first_name' => $client->first_name ?? '',
                        'last_name' => $client->last_name ?? '',
                        'address1' => $client->address1 ?? '',
                        'city' => $client->city ?? '',
                        'state' => $client->state ?? '',
                        'zip' => $client->zip ?? '',
                        'country' => $client->country ?? '',
                        'email' => $client->email ?? '',
                        'phone' => $this->formatPhone(
                            isset($contact_numbers[0]) ? $contact_numbers[0]->number : null,
                            $client->country
                        )
                    ],
                    'ns' => $vars['ns'] ?? (array) $package->meta->ns
                ];
                $this->registerDomain($vars['domain'], $row->id, $params);
            }
        } else {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('Nominet.!error.module_row.missing', true)]]
            );
        }

        // Return service fields
        return [
            [
                'key' => 'domain',
                'value' => $vars['domain'],
                'encrypted' => 0
            ],
            [
                'key' => 'enable_tag',
                'value' => '0',
                'encrypted' => 0
            ]
        ];
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
        if (($row = $this->getModuleRow())) {
            $service_fields = $this->serviceFieldsToObject($service->fields);

            $this->validateService($package, $vars, true);
            if ($this->Input->errors()) {
                return;
            }

            // Format input
            $vars = $this->getFieldsFromInput($vars, $package);

            // Only update the service if 'use_module' is true
            if ($vars['use_module'] == 'true') {
                // Update nameservers
                if (isset($vars['ns']) && is_array($vars['ns'])) {
                    $this->setDomainNameservers($this->getServiceDomain($service), $row->id, $vars['ns']);
                }
            }
        } else {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('Nominet.!error.module_row.missing', true)]]
            );
        }

        // Return all the service fields
        $encrypted_fields = [];
        $return = [];
        $fields = ['domain', 'enable_tag'];
        foreach ($fields as $field) {
            if (isset($vars[$field]) || isset($service_fields[$field])) {
                $return[] = [
                    'key' => $field,
                    'value' => $vars[$field] ?? $service_fields[$field],
                    'encrypted' => (in_array($field, $encrypted_fields) ? 1 : 0)
                ];
            }
        }

        return $return;
    }

    /**
     * Allows the module to perform an action when the service is ready to renew.
     * Sets Input errors on failure, preventing the service from renewing.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent
     *  service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent
     *  service of the service being renewed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically
     *  indexed array of meta fields to be stored for this service containing:
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        if (($row = $this->getModuleRow())) {
            // Get renew period
            $period = 1;
            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $service->pricing_id) {
                    $period = $pricing->term;
                    break;
                }
            }

            // Only process renewal if adding years today will add time to the expiry date
            if (strtotime('+' . $period . ' years') > strtotime($this->getExpirationDate($service))) {
                $this->renewDomain($this->getServiceDomain($service), $row->id, ['years' => $period]);
            }
        } else {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('Nominet.!error.module_row.missing', true)]]
            );
        }

        return null;
    }

    /**
     * Returns an array of service field to set for the service using the given input.
     *
     * @param array $vars An array of key/value input pairs
     * @param stdClass $package A stdClass object representing the package for the service
     * @return array An array of key/value pairs representing service fields
     */
    private function getFieldsFromInput(array $vars, $package)
    {
        $fields = $vars;

        // Set nameservers
        $ns = [];
        for ($i = 1; $i <= 5; $i++) {
            if (isset($vars['ns' . $i])) {
                $ns[$i] = $vars['ns' . $i];
                unset($fields['ns' . $i]);
            }
        }
        $fields['ns'] = $ns;

        return $fields;
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
     * @param array &$vars A list of input vars
     * @param bool $edit True to get the edit rules, false for the add rules
     * @return array Service rules
     */
    private function getServiceRules(array &$vars = null, $edit = false)
    {
        // Validate the service fields
        $rules = [
            'domain' => [
                'valid' => [
                    'rule' => [[$this, 'validateDomain'], true],
                    'message' => Language::_('Nominet.!error.domain.valid', true)
                ]
            ],
            'ns1' => [
                'valid' => [
                    'rule' => [[$this, 'validateHostName'], true],
                    'message' => Language::_('Nominet.!error.ns1.valid', true)
                ]
            ],
            'ns2' => [
                'valid' => [
                    'rule' => [[$this, 'validateHostName'], true],
                    'message' => Language::_('Nominet.!error.ns2.valid', true)
                ]
            ],
            'ns3' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateHostName'], true],
                    'message' => Language::_('Nominet.!error.ns3.valid', true)
                ]
            ],
            'ns4' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateHostName'], true],
                    'message' => Language::_('Nominet.!error.ns4.valid', true)
                ]
            ],
            'ns5' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateHostName'], true],
                    'message' => Language::_('Nominet.!error.ns5.valid', true)
                ]
            ]
        ];

        // Remove validation rules for optional fields
        for ($i = 1; $i <= 5; $i++) {
            if (empty($vars['ns' . $i])) {
                unset($vars['ns' . $i]);
            }
        }

        return $rules;
    }

    /**
     * Generates a password.
     *
     * @param int $min_length The minimum character length for the password (5 or larger)
     * @param int $max_length The maximum character length for the password (14 or fewer)
     * @return string The generated password
     */
    private function generatePassword($min_length = 10, $max_length = 14)
    {
        $pool = 'abcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
        $pool_size = strlen($pool);
        $length = mt_rand(max($min_length, 5), min($max_length, 14));
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= substr($pool, mt_rand(0, $pool_size - 1), 1);
        }

        return $password;
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

        // Set default name servers
        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            $i = 1;
            foreach ($package->meta->ns as $ns) {
                $vars->{'ns' . $i++} = $ns;
            }
        }

        $module_fields = $this->arrayToModuleFields(
            array_merge(
                Configure::get('Nominet.domain_fields'),
                Configure::get('Nominet.nameserver_fields')
            ),
            null,
            $vars
        );

        return $module_fields;
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

        // Get nameservers from EPP server, if available
        if (isset($vars->domain)) {
            $nameservers = $this->getDomainNameServers($vars->domain, $package->module_row);
            $i = 1;
            foreach ($nameservers as $ns) {
                $vars->{'ns' . $i++} = $ns['url'];
            }
        }

        // Set default name servers
        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            $i = 1;
            foreach ($package->meta->ns as $ns) {
                $vars->{'ns' . $i++} = $ns;
            }
        }

        $module_fields = $this->arrayToModuleFields(
            array_merge(
                Configure::get('Nominet.domain_fields'),
                Configure::get('Nominet.nameserver_fields'),
                Configure::get('Nominet.tag_fields')
            ),
            null,
            $vars
        );

        return $module_fields;
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
        Loader::loadHelpers($this, ['Html']);

        // Set default name servers
        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            $i = 1;
            foreach ($package->meta->ns as $ns) {
                $vars->{'ns' . $i++} = $ns;
            }
        }

        $module_fields = $this->arrayToModuleFields(
            array_merge(
                Configure::get('Nominet.domain_fields'),
                Configure::get('Nominet.nameserver_fields')
            ),
            null,
            $vars
        );

        return $module_fields;
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
        return $this->getClientAddFields($package, $vars);
    }

    /**
     * Returns all tabs to display to an admin when managing a service
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array An array of tabs in the format of method => title.
     *  Example: ['methodName' => "Title", 'methodName2' => "Title2"]
     */
    public function getAdminServiceTabs($service)
    {
        Loader::loadModels($this, ['Packages']);
        $package = $this->Packages->get($service->package_id ?? $service->package->id);

        $tabs = [
            'tabWhois' => Language::_('Nominet.tab_whois.title', true),
            'tabNameservers' => Language::_('Nominet.tab_nameservers.title', true),
            'tabDnssec' => Language::_('Nominet.tab_dnssec.title', true),
            'tabSettings' => Language::_('Nominet.tab_settings.title', true)
        ];

        // Check if DNS Management is enabled
        if (!$this->featureServiceEnabled('dns_management', $service)) {
            unset($tabs['tabDnssec']);
        }

        // Determine if this service has access to the settings tab
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $ips_tag = $service_fields->enable_tag ?? '0';
        $epp_code = $package->meta->epp_code ?? '0';

        if (!$ips_tag && !$epp_code) {
            unset($tabs['tabSettings']);
        }

        return $tabs;
    }

    /**
     * Returns all tabs to display to a client when managing a service.
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array An array of tabs in the format of method => title, or method => array where array contains:
     *
     *  - name (required) The name of the link
     *  - icon (optional) use to display a custom icon
     *  - href (optional) use to link to a different URL
     *      Example:
     *      ['methodName' => "Title", 'methodName2' => "Title2"]
     *      ['methodName' => ['name' => "Title", 'icon' => "icon"]]
     */
    public function getClientServiceTabs($service)
    {
        Loader::loadModels($this, ['Packages']);
        $package = $this->Packages->get($service->package_id ?? $service->package->id);

        $tabs = [
            'tabClientWhois' => Language::_('Nominet.tab_client_whois.title', true),
            'tabClientNameservers' => Language::_('Nominet.tab_client_nameservers.title', true),
            'tabClientDnssec' => Language::_('Nominet.tab_client_dnssec.title', true),
            'tabClientSettings' => Language::_('Nominet.tab_client_settings.title', true)
        ];

        // Check if DNS Management is enabled
        if (!$this->featureServiceEnabled('dns_management', $service)) {
            unset($tabs['tabClientDnssec']);
        }

        // Determine if this service has access to the settings tab
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $ips_tag = $service_fields->enable_tag ?? '0';
        $epp_code = $package->meta->epp_code ?? '0';

        if (!$ips_tag && !$epp_code) {
            unset($tabs['tabClientSettings']);
        }

        return $tabs;
    }

    /**
     * Checks if a feature is enabled for a given service
     *
     * @param string $feature The name of the feature to check if it's enabled (e.g. id_protection)
     * @param stdClass $service An object representing the service
     * @return bool True if the feature is enabled, false otherwise
     */
    private function featureServiceEnabled($feature, $service)
    {
        // Get service option groups
        foreach ($service->options as $option) {
            if ($option->option_name == $feature) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whois tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabWhois(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_whois', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain contacts
        try {
            $contacts = $this->getDomainContacts($service_fields->domain, $service->module_row_id);
            $vars = [];
            foreach ($contacts as $contact) {
                $vars[$contact->external_id] = (array) $contact;
            }
            $vars = (object) $vars;
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['contacts' => $e->getMessage()]]);
        }

        // Update whois contact
        if (!empty($post)) {
            $contacts = [];
            foreach ($post as $external_id => $contact) {
                $contact['external_id'] = $external_id;
                $contacts[] = $contact;
            }

            $this->setDomainContacts($service_fields->domain, $contacts, $service->module_row_id);
            $vars = (object) $post;
        }

        // Set countries list
        Loader::loadModels($this, ['Countries']);
        $this->view->set(
            'countries',
            $this->Form->collapseObjectArray($this->Countries->getList(), ['name', 'alt_name'], 'alpha2', ' - ')
        );

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('whois_fields', Configure::get('Nominet.contact_fields'));
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Whois client tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientWhois(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_client_whois', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain contacts
        try {
            $contacts = $this->getDomainContacts($service_fields->domain, $service->module_row_id);
            $vars = [];
            foreach ($contacts as $contact) {
                $vars[$contact->external_id] = (array) $contact;
            }
            $vars = (object) $vars;
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['contacts' => $e->getMessage()]]);
        }

        // Update whois contact
        if (!empty($post)) {
            $contacts = [];
            foreach ($post as $external_id => $contact) {
                $contact['external_id'] = $external_id;
                $contacts[] = $contact;
            }

            $this->setDomainContacts($service_fields->domain, $contacts, $service->module_row_id);
            $vars = (object) $post;
        }

        // Set countries list
        Loader::loadModels($this, ['Countries']);
        $this->view->set(
            'countries',
            $this->Form->collapseObjectArray($this->Countries->getList(), ['name', 'alt_name'], 'alpha2', ' - ')
        );

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('whois_fields', Configure::get('Nominet.contact_fields'));
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabNameservers(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_nameservers', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain nameservers
        $vars = (object) [];
        try {
            $nameservers = $this->getDomainNameServers($service_fields->domain, $service->module_row_id);

            if (empty($nameservers)) {
                $i = 1;
                foreach ($package->meta->ns ?? [] as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            } else {
                foreach ($nameservers as $ns => $nameserver) {
                    if (!is_array($nameserver)) {
                        continue;
                    }

                    $vars->{'ns' . ($ns + 1)} = $nameserver['url'];
                }
            }
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['nameservers' => $e->getMessage()]]);
        }

        // Update domain nameservers
        if (!empty($post)) {
            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                if (isset($post['ns' . $i]) && !empty($post['ns' . $i])) {
                    $ns[] = $post['ns' . $i];
                }
            }

            $this->setDomainNameservers($service_fields->domain, $service->module_row_id, $ns);
            $vars = (object) $post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('nameserver_fields', Configure::get('Nominet.nameserver_fields'));
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Nameservers client tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientNameservers(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_client_nameservers', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain nameservers
        $vars = (object) [];
        try {
            $nameservers = $this->getDomainNameServers($service_fields->domain, $service->module_row_id);

            if (empty($nameservers)) {
                $i = 1;
                foreach ($package->meta->ns ?? [] as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            } else {
                foreach ($nameservers as $ns => $nameserver) {
                    if (!is_array($nameserver)) {
                        continue;
                    }

                    $vars->{'ns' . ($ns + 1)} = $nameserver['url'];
                }
            }
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['nameservers' => $e->getMessage()]]);
        }

        // Update domain nameservers
        if (!empty($post)) {
            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                if (isset($post['ns' . $i]) && !empty($post['ns' . $i])) {
                    $ns[] = $post['ns' . $i];
                }
            }

            $this->setDomainNameservers($service_fields->domain, $service->module_row_id, $ns);
            $vars = (object) $post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('nameserver_fields', Configure::get('Nominet.nameserver_fields'));
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * DNSSEC tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabDnssec(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_dnssec', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain DNSSEC
        $dnssec = [];
        try {
            $dnssec = $this->getDnssec($service_fields->domain, $service->module_row_id);
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['dnssec' => $e->getMessage()]]);
        }

        // Delete exist record
        if (!empty($post) && (($post['action'] ?? 'add') == 'delete')) {
            $this->deleteDnssec($service_fields->domain, $service->module_row_id, $post);
        }

        // Add new record
        if (!empty($post) && (($post['action'] ?? 'add') !== 'delete')) {
            $this->addDnssec($service_fields->domain, $service->module_row_id, $post);
            $vars = (object) $post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('dnssec', $dnssec);
        $this->view->set('flags', Configure::get('Nominet.dnssec_options')['flags']);
        $this->view->set('digest', Configure::get('Nominet.dnssec_options')['digest']);
        $this->view->set('algorithms', Configure::get('Nominet.dnssec_options')['algorithms']);
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * DNSSEC client tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientDnssec(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_client_dnssec', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Fetch domain DNSSEC
        $dnssec = [];
        try {
            $dnssec = $this->getDnssec($service_fields->domain, $service->module_row_id);
        } catch (Throwable $e) {
            $this->Input->setErrors(['errors' => ['dnssec' => $e->getMessage()]]);
        }

        // Delete exist record
        if (!empty($post) && ($post['action'] == 'delete')) {
            $this->deleteDnssec($service_fields->domain, $service->module_row_id, $post);
        }

        // Add new record
        if (!empty($post) && ($post['action'] !== 'delete')) {
            $this->addDnssec($service_fields->domain, $service->module_row_id, $post);
            $vars = (object) $post;
        }

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('dnssec', $dnssec);
        $this->view->set('flags', Configure::get('Nominet.dnssec_options')['flags']);
        $this->view->set('digest', Configure::get('Nominet.dnssec_options')['digest']);
        $this->view->set('algorithms', Configure::get('Nominet.dnssec_options')['algorithms']);
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabSettings(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_settings', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Determine if this service has access to change the IPS tag
        $ips_tag = $service_fields->enable_tag ?? '0';

        // Push domain
        if (!empty($post) && $ips_tag == '1') {
            $this->pushDomain($service_fields->domain, $service->module_row_id, $post);
        }

        // Get domain information
        $domain = $this->getDomainInfo($service_fields->domain, $service->module_row_id);

        // Determine if this service has access to epp_code
        $epp_code = $package->meta->epp_code ?? '0';

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('domain', $domain);
        $this->view->set('epp_code', $epp_code);
        $this->view->set('ips_tag', $ips_tag);
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Settings client tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientSettings(
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    ) {
        $this->view = new View('tab_client_settings', 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Get service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Determine if this service has access to change the IPS tag
        $ips_tag = $service_fields->enable_tag ?? '0';

        // Push domain
        if (!empty($post) && $ips_tag == '1') {
            $this->pushDomain($service_fields->domain, $service->module_row_id, $post);
        }

        // Get domain information
        $domain = $this->getDomainInfo($service_fields->domain, $service->module_row_id);

        // Determine if this service has access to epp_code
        $epp_code = $package->meta->epp_code ?? '0';

        $this->view->set('service_fields', $service_fields);
        $this->view->set('service_id', $service->id);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('domain', $domain);
        $this->view->set('epp_code', $epp_code);
        $this->view->set('ips_tag', $ips_tag);
        $this->view->set('vars', ($vars ?? new stdClass()));

        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'nominet' . DS);

        return $this->view->fetch();
    }

    /**
     * Verifies that the provided domain name is available
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is available, false otherwise
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        // Check with the EPP server if the domain is available
        $availability = $this->request($api, new Metaregistrar\EPP\eppCheckDomainRequest([$domain]));

        if ($availability == false) {
            return false;
        }

        // Check result
        $checks = $availability->getCheckedDomains();
        foreach ($checks as $check) {
            return $check['available'] ?? false;
        }

        return false;
    }

    /**
     * Verifies that the provided domain name is available for transfer
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is available for transfer, false otherwise
     */
    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        // Nominet transfers operates as “push” transfers requested by the current registrar,
        // therefore we cannot request a transfer as is not included in Nominet's standard EPP implementation.
        return false;
    }

    /**
     * Gets a list of basic information for a domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of common domain information
     *
     *  - * The contents of the return vary depending on the registrar
     */
    public function getDomainInfo($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain information
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return [];
        }

        $domain_info = [
            'id' => $info->getDomainId(),
            'roid' => $info->getDomainRoid(),
            'domain_name' => $info->getDomainName(),
            'statuses' => $info->getDomainStatuses(),
            'contacts' => $info->getDomainContacts(),
            'hosts' => $info->getDomainHosts(),
            'creation_date' => $info->getDomainCreateDate(),
            'expiration_date' => $info->getDomainExpirationDate(),
            'epp_code' => $info->getDomainAuthInfo()
        ];

        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('domain_info')),
            'output',
            !empty($info->getDomainId())
        );

        return $domain_info;
    }

    /**
     * Gets the domain expiration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the expiration date in
     * @return string The domain expiration date in UTC time in the given format
     * @see Services::get()
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain information
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        $expiration_date = $info->getDomainExpirationDate();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('expiration_date')),
            'output',
            !empty($expiration_date)
        );

        return !empty($expiration_date)
            ? date($format, strtotime($expiration_date))
            : false;
    }

    /**
     * Gets the domain registration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the registration date in
     * @return string The domain registration date in UTC time in the given format
     * @see Services::get()
     */
    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain information
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        $creation_date = $info->getDomainCreateDate();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('creation_date')),
            'output',
            !empty($creation_date)
        );

        return !empty($creation_date)
            ? date($format, strtotime($creation_date))
            : false;
    }

    /**
     * Gets the domain name from the given service
     *
     * @param stdClass $service The service from which to extract the domain name
     * @return string The domain name associated with the service
     * @see Services::get()
     */
    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $service_field) {
                if ($service_field->key == 'domain') {
                    return $service_field->value;
                }
            }
        }

        return $this->getServiceName($service);
    }

    /**
     * Register a new domain through the registrar
     *
     * @param string $domain The domain to register
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the registration request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the domain was successfully registered, false otherwise
     */
    public function registerDomain($domain, $module_row_id = null, array $vars = [])
    {
        Loader::loadHelpers($this, ['Html']);
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        // Add contact
        $contact_id = null;
        if (isset($vars['contact'])) {
            if (empty($vars['contact']['company'])) {
                $vars['contact']['company'] = null;
            }

            $contact = new Metaregistrar\EPP\eppContact(
                new Metaregistrar\EPP\eppContactPostalInfo(
                    $this->Html->concat(' ', ($vars['contact']['first_name'] ?? ''), ($vars['contact']['last_name'] ?? '')),
                    $vars['contact']['city'] ?? '',
                    $vars['contact']['country'] ?? '',
                    $vars['contact']['company'] ?? '',
                    $vars['contact']['address1'] ?? '',
                    $vars['contact']['state'] ?? '',
                    $vars['contact']['zip'] ?? '',
                    ($vars['contact']['country'] ?? 'UK') == 'UK'
                        ? Metaregistrar\EPP\eppContact::TYPE_LOC
                        : Metaregistrar\EPP\eppContact::TYPE_INT
                ),
                $vars['contact']['email'] ?? '',
                $this->formatPhone($vars['contact']['phone'] ?? '', $vars['contact']['country'] ?? 'UK')
            );
            $contact->setPassword($this->generatePassword());

            $response = $this->request($api, new Metaregistrar\EPP\eppCreateContactRequest($contact));
            if ($response) {
                $contact_id = $response->getContactId();
            }
        }

        // Create domain
        $register = new Metaregistrar\EPP\eppDomain($domain, $contact_id);
        $register->setRegistrant(new Metaregistrar\EPP\eppContactHandle($contact_id));
        $register->addContact(
            new Metaregistrar\EPP\eppContactHandle(
                $contact_id,
                Metaregistrar\EPP\eppContactHandle::CONTACT_TYPE_REGISTRANT
            )
        );
        $register->setAuthorisationCode($this->generatePassword(6, 8));

        // Set contact
        $update = new NominetEppDomain($domain);
        if ($contact_id) {
            $update->setRegistrant($contact_id);
        }

        $this->request(
            $api,
            new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), null, null, $update)
        );

        // Set nameservers
        if (isset($vars['ns']) && is_array($vars['ns'])) {
            foreach ($vars['ns'] as $nameserver) {
                if (empty($nameserver)) {
                    continue;
                }
                $register->addHost(new Metaregistrar\EPP\eppHost($nameserver));
            }
        }

        // Send request
        $response = $this->request($api, new Metaregistrar\EPP\eppCreateDomainRequest($register));

        return $response && !empty($response->getDomainCreateDate());
    }

    /**
     * Renew a domain through the registrar
     *
     * @param string $domain The domain to renew
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the renew request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the domain was successfully renewed, false otherwise
     */
    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        // Renew the domain
        $renew = new Metaregistrar\EPP\eppDomain($domain);
        $renew->setPeriod(($vars['qty'] ?? $vars['years'] ?? 1));
        $renew->setPeriodUnit('y');

        if (($info = $this->request($api, new Metaregistrar\EPP\eppInfoDomainRequest($renew)))) {
            // Send request
            $expiration_date = date('Y-m-d',strtotime($info->getDomainExpirationDate()));
            $response = $this->request($api, new Metaregistrar\EPP\eppRenewRequest($renew, $expiration_date));

            return $response->getResultCode() == 1000;
        }

        return false;
    }

    /**
     * Transfer a domain through the registrar
     *
     * @param string $domain The domain to register
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the transfer request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the domain was successfully transferred, false otherwise
     */
    public function transferDomain($domain, $module_row_id = null, array $vars = [])
    {
        // Nominet transfers operates as “push” transfers requested by the current registrar,
        // therefore we cannot request a transfer as is not included in Nominet's standard EPP implementation.
        // See Nominet::pushDomain()
        if (isset($this->Input)) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        return false;
    }

    /**
     * Push a domain to a different registrar
     *
     * @param string $domain The domain to register
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the transfer request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the domain was successfully transferred, false otherwise
     */
    private function pushDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppUpdateDomainRequest', json_encode(compact('domain', 'vars')), 'input', true);

        // Updated the registrar tag to push the domain
        $update = new NominetEppDomain($domain);
        $update->setTag($vars['tag'] ?? '');

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new NominetEppDomainRequest(new NominetEppDomain($domain), null, null, $update)
        );

        return $response !== false;
    }

    /**
     * Gets a list of contacts associated with a domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of contact objects with the following information:
     *
     *  - external_id The ID of the contact in the registrar
     *  - email The primary email associated with the contact
     *  - phone The phone number associated with the contact
     *  - first_name The first name of the contact
     *  - last_name The last name of the contact
     *  - address1 The contact's address
     *  - address2 The contact's address line two
     *  - city The contact's city
     *  - state The 3-character ISO 3166-2 subdivision code
     *  - zip The zip/postal code for this contact
     *  - country The 2-character ISO 3166-1 country code
     */
    public function getDomainContacts($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain contacts
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );

        if (!$info) {
            return [];
        }

        // Get domain registrant
        $registrant = $info->getDomainRegistrant();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('registrant')),
            'output',
            !empty($contacts)
        );

        // Format contacts
        $types = ['registrant'];
        $formatted_contacts = [];
        foreach ($types as $type) {
            // Fetch contact
            $contact = $this->request(
                $api,
                new Metaregistrar\EPP\eppInfoContactRequest(new Metaregistrar\EPP\eppContactHandle($registrant))
            );

            // Format name
            $name = [$contact->getContactName()];
            if (str_contains($contact->getContactName(), ' ')) {
                $name = explode(' ', $contact->getContactName(), 2);
            }

            // Format contact
            $formatted_contacts[] = (object) [
                'external_id' => $type,
                'email' => $contact->getContactEmail(),
                'phone' => $contact->getContactVoice(),
                'first_name' => $name[0] ?? '',
                'last_name' => $name[1] ?? '',
                'address1' => $contact->getContactStreet(),
                'address2' => '',
                'city' => $contact->getContactCity(),
                'state' => $contact->getContactProvince(),
                'zip' => $contact->getContactZipcode(),
                'country' => $contact->getContactCountrycode(),
            ];
        }

        return $formatted_contacts;
    }

    /**
     * Returns whether the domain has a registrar lock
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain has a registrar lock, false otherwise
     */
    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain statuses
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        $statuses = $info->getDomainStatuses();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('statuses')),
            'output',
            !empty($statuses)
        );

        return in_array(Metaregistrar\EPP\eppDomain::STATUS_CLIENT_TRANSFER_PROHIBITED, $statuses);
    }

    /**
     * Gets a list of name server data associated with a domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of name servers, each with the following fields:
     *
     *  - url The URL of the name server
     *  - ips A list of IPs for the name server
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current domain nameservers
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return [];
        }

        $nameservers = $info->getDomainNameservers();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('nameservers')),
            'output',
            !empty($nameservers)
        );

        // Format nameservers
        $ns = [];
        foreach ($nameservers ?? [] as $nameserver) {
            if ($nameserver instanceof \Metaregistrar\EPP\eppHost) {
                $ns[] = [
                    'url' => trim($nameserver->getHostname(), '.'),
                    'ips' => empty($nameserver->getIpAddresses())
                        ? $nameserver->getIpAddresses()
                        : gethostbyname($nameserver->getIpAddresses())
                ];
            }
        }

        return $ns;
    }

    /**
     * Locks the given domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain was successfully locked, false otherwise
     */
    public function lockDomain($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppUpdateDomainRequest', json_encode(compact('domain')), 'input', true);

        // Add the "transfer prohibited" status to the domain
        $add = new Metaregistrar\EPP\eppDomain($domain);
        $add->addStatus(Metaregistrar\EPP\eppDomain::STATUS_CLIENT_TRANSFER_PROHIBITED);

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), $add)
        );

        return $response !== false;
    }

    /**
     * Resend domain transfer verification email
     *
     * @param string $domain The domain for which to resend the email
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the email was successfully sent, false otherwise
     */
    public function resendTransferEmail($domain, $module_row_id = null)
    {
        if (isset($this->Input)) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        return false;
    }

    /**
     * Restore a domain through the registrar
     *
     * @param string $domain The domain to restore
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the restore request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the domain was successfully restored, false otherwise
     */
    public function restoreDomain($domain, $module_row_id = null, array $vars = [])
    {
        if (isset($this->Input)) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        return false;
    }

    /**
     * Send domain transfer auth code to admin email
     *
     * @param string $domain The domain for which to send the email
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the email was successfully sent, false otherwise
     */
    public function sendEppEmail($domain, $module_row_id = null)
    {
        if (isset($this->Input)) {
            $this->Input->setErrors($this->getCommonError('unsupported'));
        }

        return false;
    }

    /**
     * Updates the list of contacts associated with a domain
     *
     * @param string $domain The domain for which to update contact info
     * @param array $vars A list of contact arrays with the following information:
     *
     *  - external_id The ID of the contact in the registrar (optional)
     *  - email The primary email associated with the contact
     *  - phone The phone number associated with the contact
     *  - first_name The first name of the contact
     *  - last_name The last name of the contact
     *  - address1 The contact's address
     *  - address2 The contact's address line two
     *  - city The contact's city
     *  - state The 3-character ISO 3166-2 subdivision code
     *  - zip The zip/postal code for this contact
     *  - country The 2-character ISO 3166-1 country code
     *  - * Other fields required by the registrar
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the contacts were updated, false otherwise
     */
    public function setDomainContacts($domain, array $vars = [], $module_row_id = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain', 'vars')), 'input', true);

        // Get current domain info
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        // Set contact type
        foreach ($vars as $key => $contact) {
            switch ($key) {
                case 'registrant':
                    $contact['external_id'] = NominetEppContactHandle::CONTACT_TYPE_REGISTRANT;
                    break;
            }
        }

        try {
            // Create contacts
            foreach ($vars as &$contact) {
                if (empty($contact['first_name']) && empty($contact['last_name'])) {
                    continue;
                }

                if (empty($contact['company'])) {
                    $contact['company'] = null;
                }

                $epp_contact = new Metaregistrar\EPP\eppContact(
                    new Metaregistrar\EPP\eppContactPostalInfo(
                        $this->Html->concat(' ', ($contact['first_name'] ?? ''), ($contact['last_name'] ?? '')),
                        $contact['city'] ?? '',
                        $contact['country'] ?? '',
                        $contact['company'] ?? '',
                        $contact['address1'] ?? '',
                        $contact['state'] ?? '',
                        $contact['zip'] ?? '',
                        ($vars['contact']['country'] ?? 'UK') == 'UK'
                            ? Metaregistrar\EPP\eppContact::TYPE_LOC
                            : Metaregistrar\EPP\eppContact::TYPE_INT
                    ),
                    $contact['email'] ?? '',
                    $this->formatPhone($contact['phone'] ?? '', $contact['country'])
                );
                $epp_contact->setPassword($this->generatePassword());
                $response = $api->request(new Metaregistrar\EPP\eppCreateContactRequest($epp_contact));

                $this->log(
                    $api->getUsername() . '|eppContact',
                    json_encode($response),
                    'output'
                );

                if ($response->getContactId()) {
                    $contact['id'] = $response->getContactId();
                }
            }

            // Set new contact ID
            $update = new NominetEppDomain($domain);
            if (!empty($vars)) {
                foreach ($vars as $key => $contact) {
                    if (empty($contact['id'])) {
                        continue;
                    }

                    $update->setRegistrant($contact['id']);
                }
            }

            $response = $this->request(
                $api,
                new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), null, null, $update)
            );
        } catch (Throwable $e) {
            if (isset($this->Input)) {
                $this->Input->setErrors(['exception' => ['message' => $e->getMessage()]]);
            }

            $this->log(
                $api->getUsername() . '|updateContacts',
                json_encode(['exception' => $e->getMessage()]),
                'output'
            );

            return false;
        }

        return $response !== false;
    }

    /**
     * Assign new name servers to a domain
     *
     * @param string $domain The domain for which to assign new name servers
     * @param int|null $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of name servers to assign (e.g. [ns1, ns2])
     * @return bool True if the name servers were successfully updated, false otherwise
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain', 'vars')), 'input', true);

        // Get current domain nameservers
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        $nameservers = $info->getDomainNameservers();
        $this->log(
            $row->meta->username . '|eppInfoDomainRequest',
            json_encode(compact('nameservers')),
            'output',
            !empty($nameservers)
        );

        // Delete current domain nameservers
        $delete = null;
        if (is_array($nameservers)) {
            $delete = new Metaregistrar\EPP\eppDomain($domain);
            foreach ($nameservers as $nameserver) {
                $delete->addHost(new Metaregistrar\EPP\eppHost($nameserver->getHostname()));
            }
        }

        // Add new domain nameservers
        $add = null;
        if (!empty($vars)) {
            $add = new Metaregistrar\EPP\eppDomain($domain);
            foreach ($vars as $nameserver) {
                if (empty($nameserver)) {
                    continue;
                }
                $add->addHost(new Metaregistrar\EPP\eppHost($nameserver));
            }
        }

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), $add, $delete)
        );

        return $response !== false;
    }

    /**
     * Assigns new ips to a name server
     *
     * @param array $vars A list of name servers and their new ips
     *
     *  - nsx => [ip1, ip2]
     * @param int|null $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the name servers were successfully updated, false otherwise
     */
    public function setNameserverIps(array $vars = [], $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppCreateHostRequest', json_encode(compact('vars')), 'input', true);

        // Add nameservers
        $ns = [];
        if (!empty($vars)) {
            foreach ($vars as $nameserver => $ips) {
                $ns[] = new Metaregistrar\EPP\eppHost($nameserver, $ips);
            }
        }

        // Send request to the EPP server
        foreach ($ns as $request) {
            $response = $this->request(
                $api,
                new Metaregistrar\EPP\eppCreateHostRequest($request)
            );

            if (!$response) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unlocks the given domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain was successfully unlocked, false otherwise
     */
    public function unlockDomain($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppUpdateDomainRequest', json_encode(compact('domain')), 'input', true);

        // Remove the "transfer prohibited" status to the domain
        $delete = new Metaregistrar\EPP\eppDomain($domain);
        $delete->addStatus(Metaregistrar\EPP\eppDomain::STATUS_CLIENT_TRANSFER_PROHIBITED);

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), null, $delete)
        );

        return $response !== false;
    }

    /**
     * Set a new domain transfer auth code
     *
     * @param string $domain The domain for which to update the code
     * @param string $epp_code The new epp auth code to use
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of vars to submit with the update request
     *
     *  - * The contents of $vars vary depending on the registrar
     * @return bool True if the code was successfully updated, false otherwise
     */
    public function updateEppCode($domain, $epp_code, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppUpdateDomainRequest', json_encode(compact('domain')), 'input', true);

        // Update the domain EPP code
        $update = new Metaregistrar\EPP\eppDomain($domain);
        $update->setAuthorisationCode($epp_code);

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), null, null, $update)
        );

        return $response !== false;
    }

    /**
     * Get a list of the TLDs supported by the registrar module
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs supported by the registrar module
     */
    public function getTlds($module_row_id = null)
    {
        return Configure::get('Nominet.tlds');
    }

    /**
     * Fetches the DNSSEC keys
     *
     * @param string $domain The domain to fetch the keys
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array An array with the keys for the given domain
     */
    private function getDnssec($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppInfoDomainRequest', json_encode(compact('domain')), 'input', true);

        // Get current DNSSEC keys
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return [];
        }

        $keys = $info->getKeydata();
        $dnssec = [];
        foreach ($keys as $key) {
            $dnssec[] = (object) [
                'flags' => $key->getFlags(),
                'algorithm' => $key->getAlgorithm(),
                'digest' => $key->getDigest(),
                'digest_type' => $key->getDigestType(),
                'key_tag' => $key->getKeytag(),
                'key' => $key->getPubkey()
            ];
        }

        return $dnssec;
    }

    /**
     * Adds a new DNSSEC record
     *
     * @param string $domain The domain to update
     * @param array $vars An array of DNSSEC keys
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain key was successfully updated, false otherwise
     * @link https://www.lacnic.net/innovaportal/file/3135/1/dnssec_intro-mvergara.pdf
     */
    private function addDnssec($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppDnssecUpdateDomainRequest', json_encode(compact('domain', 'vars')), 'input', true);

        // Add the new DNSSEC key to the domain
        $add = new Metaregistrar\EPP\eppDomain($domain);

        $secdns = new Metaregistrar\EPP\eppSecdns();
        if (isset($vars['flags'])) {
            $secdns->setFlags($vars['flags']);
        }
        if (isset($vars['algorithm'])) {
            $secdns->setAlgorithm($vars['algorithm']);
        }
        if (isset($vars['key'])) {
            $secdns->setPubkey($vars['key']);
        }
        if (isset($vars['key_tag'])) {
            $secdns->setKeytag($vars['key_tag']);
        }
        if (isset($vars['digest_type'])) {
            $secdns->setDigestType($vars['digest_type']);
        }
        if (isset($vars['digest'])) {
            $secdns->setDigest($vars['digest']);
        }
        $add->addSecdns($secdns);

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppDnssecUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), $add)
        );

        return $response !== false;
    }

    /**
     * Remvoes an existing DNSSEC record
     *
     * @param string $domain The domain to update
     * @param array $vars The DNSSEC record to delete
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the record was successfully removed, false otherwise
     */
    private function deleteDnssec($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->username, $row->meta->password, $row->meta->secure, $row->meta->sandbox);

        $this->log($row->meta->username . '|eppDnssecUpdateDomainRequest', json_encode(compact('domain', 'vars')), 'input', true);

        // Get current DNSSEC keys
        $info = $this->request(
            $api,
            new Metaregistrar\EPP\eppInfoDomainRequest(new Metaregistrar\EPP\eppDomain($domain))
        );
        if (!$info) {
            return false;
        }

        // Remove old DNSSEC record
        $keys = $info->getKeydata();
        $remove = new Metaregistrar\EPP\eppDomain($domain);
        foreach ($keys as $record) {
            if (
                $vars['key_tag'] == $record->getKeytag()
                && $vars['algorithm'] == $record->getAlgorithm()
                && $vars['digest_type'] == $record->getDigestType()
                && $vars['digest'] == $record->getDigest()
            ) {
                $remove->addSecdns($record);
            }
        }

        // Send request to the EPP server
        $response = $this->request(
            $api,
            new Metaregistrar\EPP\eppDnssecUpdateDomainRequest(new Metaregistrar\EPP\eppDomain($domain), null, $remove)
        );

        return $response !== false;
    }

    /**
     * Sends a request to the Nominet EPP server
     *
     * @param \NominetEppConnection $api An instance of the EPP server API
     * @param \Metaregistrar\EPP\eppRequest $request An EPP request
     * @return \Metaregistrar\EPP\eppResponse The response from the EPP server, false on error
     */
    private function request(NominetEppConnection $api, Metaregistrar\EPP\eppRequest $request)
    {
        $class = get_class($request);
        try {
            $response = $api->request($request);

            $this->log(
                $api->getUsername() . '|' . $class,
                json_encode($response),
                'output',
                ($response->getResultCode() < 2000)
            );

            if (($response->getResultCode() >= 2000) && isset($this->Input)) {
                $this->Input->setErrors(['error' => ['message' => $response->getResultMessage()]]);

                return false;
            }

            return $response;
        } catch (Throwable $e) {
            if (isset($this->Input)) {
                $this->Input->setErrors(['exception' => ['message' => $e->getMessage()]]);
            }

            $this->log(
                $api->getUsername() . '|' . $class,
                json_encode(['exception' => $e->getMessage()]),
                'output'
            );

            return false;
        }
    }

    /**
     * Formats a phone number into +NNN.NNNNNNNNNN
     *
     * @param string $number The phone number
     * @param string $country The ISO 3166-1 alpha2 country code
     * @return string The number in +NNN.NNNNNNNNNN
     */
    private function formatPhone($number, $country)
    {
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }

        return '+' . $this->Contacts->intlNumber($number, $country, '.');
    }

    /**
     * Initializes the Nominet EPP server and returns an instance of the connection.
     *
     * @param string $password The Nominet password
     * @param string $username The Nominet userbane
     * @param string $secure 'true' to use a secure connection
     * @param string $sandbox 'true' to use the sandbox server
     * @return NominetEppConnection The NominetApi connection to the EPP server
     */
    private function getApi($username, $password, $secure = 'false', $sandbox = 'false')
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'epp_connection.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'epp_domain.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'epp_domain_request.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'epp_contact_handler.php');

        $connection = new NominetEppConnection();

        // Set Hostname
        $hostname = $this->endpoint[($sandbox == 'true' ? 'sandbox' : 'live')][($secure == 'true' ? 'secure' : 'insecure')]['server'];
        $connection->setHostname(($secure == 'true' ? 'ssl://' : '') . $hostname);

        // Set port
        $port = $this->endpoint[($sandbox == 'true' ? 'sandbox' : 'live')][($secure == 'true' ? 'secure' : 'insecure')]['port'];
        $connection->setPort($port);

        // Set credentials
        $connection->setUsername($username);
        $connection->setPassword($password);

        // Login to server
        $this->log($username . '|login', json_encode(compact('hostname', 'username', 'port', 'secure')), 'input', true);

        try {
            $connection->login();
        } catch (Throwable $e) {
            if (isset($this->Input)) {
                $this->Input->setErrors(['exception' => ['message' => $e->getMessage()]]);
            }
            $this->log($username . '|login', json_encode(['exception' => $e->getMessage()]), 'output', false);

            return new NominetEppConnection();
        }

        $this->log($username . '|login', json_encode($connection), 'output', true);

        return $connection;
    }
}
