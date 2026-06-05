<?php

/**
 * Realtime Register Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.logicboxes
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class RealtimeRegister extends RegistrarModule
{
    /**
     * @var array DNSSEC Key flags
     */
    private $dnssec_flags = [
        256 => 'ZSK',
        257 => 'KSK'
    ];

    /**
     * @var array DNSSEC Key algorithms
     */
    private $dnssec_algorithms = [
        3 => 'DSA/SHA1',
        5 => 'RSA/SHA-1',
        6 => 'DSA-NSEC3-SHA1',
        7 => 'RSASHA1-NSEC3-SHA1',
        8 => 'RSA/SHA-256',
        10 => 'RSA/SHA-512',
        12 => 'GOST R 34.10-2001',
        13 => 'ECDSA Curve P-256 with SHA-256',
        14 => 'ECDSA Curve P-384 with SHA-384',
        15 => 'Ed25519',
        16 => 'Ed448',
        17 => 'SM2 signing algorithm with SM3 hashing algorithm',
        23 => 'GOST R 34.10.2012'
    ];

    /**
     * @var array DNS Record types
     */
    private $record_types = [
        'A' => 'A - Address record',
        'MX' => 'MX - Mail exchange record',
        'CNAME' => 'CNAME - Canonical name record',
        'AAAA' => 'AAAA - IPv6 address record',
        'URL' => 'URL - URL Record',
        'MBOXFW' => 'MBOXFW - Mailbox forward record',
        'HINFO' => 'HINFO - Host information record',
        'NAPTR' => 'NAPTR - Naming Authority Pointer',
        'NS' => 'NS - Name server record',
        'SRV' => 'SRV - Service locator',
        'CAA' => 'CAA - Certification Authority Authorization',
        'TLSA' => 'TLSA - Transport Layer Security Authentication',
        'ALIAS' => 'ALIAS - ALIAS record',
        'TXT' => 'TXT - Text record',
        'SOA' => 'SOA - Start of [a zone of] authority record',
        'DNSKEY' => 'DNSKEY - DNSKEY record',
        'CERT' => 'CERT - Certificate record',
        'DS' => 'DS - DS record',
        'LOC' => 'LOC - Location record',
        'SSHFP' => 'SSHFP - SSH key record',
        'URI' => 'URI - URI record'
    ];

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load the language required by this module
        Language::loadLang('realtime_register', null, dirname(__FILE__) . DS . 'language' . DS);

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load module config
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Configure::load('realtime_register', dirname(__FILE__) . DS . 'config' . DS);
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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

        // Fetch module
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass(
            \Illuminate\Support\Str::snake(get_class($this)),
            Configure::get('Blesta.company_id')
        );
        $module = ($module[0] ?? []);
        $this->view->set('module', (object) $module);
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
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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

        // Fetch module
        Loader::loadModels($this, ['ModuleManager']);
        $module = $this->ModuleManager->getByClass(
            \Illuminate\Support\Str::snake(get_class($this)),
            Configure::get('Blesta.company_id')
        );
        $module = ($module[0] ?? []);
        $this->view->set('module', (object) $module);
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
        $meta_fields = ['customer', 'api_key', 'sandbox'];
        $encrypted_fields = ['api_key'];

        // Set unset checkboxes
        $checkbox_fields = ['sandbox'];
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
        $meta_fields = ['customer', 'api_key', 'sandbox'];
        $encrypted_fields = ['api_key'];

        // Set unset checkboxes
        $checkbox_fields = ['sandbox'];
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
            'customer' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('RealtimeRegister.!error.customer.empty', true)
                ]
            ],
            'api_key' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('RealtimeRegister.!error.api_key.valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        $vars['customer'],
                        $vars['sandbox'] ?? 'false'
                    ],
                    'message' => Language::_('RealtimeRegister.!error.api_key.valid_connection', true)
                ]
            ],
            'sandbox' => [
                'format' => [
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('RealtimeRegister.!error.sandbox.format', true)
                ]
            ]
        ];

        return $rules;
    }

    /**
     * Validates whether or not the connection details are valid by attempting to fetch
     * the number of accounts that currently reside on the server.
     *
     * @param string $api_key The API key to authenticate
     * @param string $customer The customer handle to authenticate
     * @param bool $sandbox Whether or not to use the sandbox environment for the API requests
     * @return bool True if the connection is valid, false otherwise
     */
    public function validateConnection($api_key, $customer, $sandbox)
    {
        try {
            $api = $this->getApi($customer, $api_key, $sandbox);

            $params = compact('customer', 'sandbox');
            $this->log($customer . '|pricelist', serialize($params), 'input', true);

            $response = $api->priceList();
            $errors = $response->errors();

            $success = false;
            if (empty($errors)) {
                $success = true;
            }

            $this->log($customer . '|pricelist', serialize($response), 'output', $success);

            if ($success) {
                return true;
            }
        } catch (\Throwable $e) {
            // Trap any errors encountered, could not validate connection
        }

        return false;
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
     * Builds and returns rules required to be validated when adding/editing a package.
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getPackageRules(array $vars)
    {
        // Validate the package fields
        $rules = [
            'epp_code' => [
                'valid' => [
                    'ifset' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => Language::_('RealtimeRegister.!error.epp_code.valid', true)
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

        // Set the EPP Code field
        $epp_code = $fields->label(Language::_('RealtimeRegister.package_fields.epp_code', true), 'realtime_register_epp_code');
        $epp_code->attach(
            $fields->fieldCheckbox(
                'meta[epp_code]',
                'true',
                ($vars->meta['epp_code'] ?? null) == 'true',
                ['id' => 'realtime_register_epp_code']
            )
        );
        // Add tooltip
        $tooltip = $fields->tooltip(Language::_('RealtimeRegister.package_field.tooltip.epp_code', true));
        $epp_code->attach($tooltip);
        $fields->setField($epp_code);

        // Set all TLD checkboxes
        $tld_options = $fields->label(Language::_('RealtimeRegister.package_fields.tld_options', true));

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
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }

        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }

        $row = $this->getModuleRow($package->module_row);
        if (!$row) {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('RealtimeRegister.!error.module_row.missing', true)]]
            );

            return;
        }
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Set unset checkboxes
        $checkbox_fields = [];
        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        // Validate service
        $this->validateService($package, $vars);
        if ($this->Input->errors()) {
            return;
        }

        // Fetch client
        $client = $this->Clients->get($vars['client_id']);
        if ($client) {
            $contact_numbers = $this->Contacts->getNumbers($client->contact_id);
        }

        // Fetch TLD
        $tld = $this->getTld($vars['domain']);
        $tld_fields = $this->getTldFields($tld, $package->module_row);

        // Only provision the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            // Create registrant contact
            $params = [
                'name' => $client->first_name . ' ' . $client->last_name,
                'addressLine' => [$client->address1, $client->address2],
                'postalCode' => $client->zip,
                'city' => $client->city,
                'country' => $client->country,
                'email' => $client->email,
                'voice' => $this->formatPhone(
                    isset($contact_numbers[0]) ? $contact_numbers[0]->number : '1111111111',
                    $client->country
                )
            ];

            if (empty($params['addressLine'][1])) {
                unset($params['addressLine'][1]);
            }

            $roles = ['ADMIN', 'BILLING', 'TECH', 'REGISTRANT'];
            $contacts = [];
            foreach ($roles as $role) {
                $handle = uniqid($role);
                $contacts[$role] = $handle;

                $this->log($row->meta->customer . '|contacts', serialize($params), 'input', true);
                $contact = $api->createContact($handle, $params);
                $response = $contact->response();
                $this->log($row->meta->customer . '|contacts', serialize($response), 'output', empty($contact->errors()));

                if (!empty($contact->errors())) {
                    $this->Input->setErrors(
                        ['api' => $contact->errors()]
                    );

                    return;
                }

                // Append TLD specific fields
                if ($role == 'REGISTRANT') {
                    $fields = array_keys($tld_fields);
                    $additional_fields = array_intersect_key($vars, array_flip($fields));

                    $registry = '';
                    foreach ($tld_fields as $field) {
                        if (!empty($field['registry'])) {
                            $registry = $field['registry'];
                        }
                    }

                    $this->log($row->meta->customer . '|contacts', serialize($additional_fields), 'input', true);
                    $properties = $api->appendPropertiesContact($handle, $registry, $additional_fields);
                    $response = $properties->response();
                    $this->log($row->meta->customer . '|contacts', serialize($response), 'output', empty($properties->errors()));
                }
            }

            // Set registration period
            $years = 1;
            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $vars['pricing_id']) {
                    $years = $pricing->term;
                    break;
                }
            }

            // Register domain
            $params = [
                'registrant' => $contacts['REGISTRANT'],
                'privacyProtect' => false,
                'autoRenew' => false,
                'period' => $years,
                'ns' => array_values($vars['ns'] ?? []),
                'contacts' => []
            ];

            foreach ($roles as $role) {
                if ($role == 'REGISTRANT') {
                    continue;
                }

                $params['contacts'][] = ['role' => $role, 'handle' => $contacts[$role]];
            }

            if ($row->meta->sandbox == 'true') {
                $params['zone'] = [
                    'service' => 'BASIC',
                    'template' => 'standard-template'
                ];
                $params['ns'] = [
                    'ns1.realtimeregister-ote.com',
                    'ns2.realtimeregister-ote.com'
                ];
            }

            $this->log($row->meta->customer . '|create', serialize($params), 'input', true);

            if (isset($vars['transfer']) || isset($vars['authcode'])) {
                $params['authcode'] = $vars['authcode'];
                $domain = $api->transferDomain($vars['domain'], $params);
            } else {
                $domain = $api->createDomain($vars['domain'], $params);
            }

            $response = $domain->response();
            $this->log($row->meta->customer . '|create', serialize($response), 'output', empty($domain->errors()));

            if (!empty($domain->errors())) {
                $this->Input->setErrors(
                    ['api' => $domain->errors()]
                );

                return;
            }
        }

        // Return service fields
        return [
            [
                'key' => 'domain',
                'value' => $vars['domain'],
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
        $row = $this->getModuleRow($package->module_row);
        if (!$row) {
            $this->Input->setErrors(
                ['module_row' => ['missing' => Language::_('RealtimeRegister.!error.module_row.missing', true)]]
            );

            return;
        }
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Set unset checkboxes
        $checkbox_fields = [];
        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($vars[$checkbox_field])) {
                $vars[$checkbox_field] = 'false';
            }
        }

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Validate service
        $this->validateServiceEdit($package, $vars);
        if ($this->Input->errors()) {
            return;
        }

        // Only update the service if 'use_module' is true
        if ($vars['use_module'] == 'true') {
            // Set nameservers
            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                if (isset($vars['ns' . $i]) && $vars['ns' . $i] != '') {
                    $ns[] = $vars['ns' . $i];
                }
            }

            // Only update nameservers if at least one was provided
            if (!empty($ns)) {
                $params = [
                    'ns' => $ns
                ];

                $this->log($row->meta->customer . '|update', serialize($params), 'input', true);
                $domain = $api->updateDomain($vars['domain'], $params);
                $response = $domain->response();
                $this->log($row->meta->customer . '|update', serialize($response), 'output', empty($domain->errors()));

                if (!empty($domain->errors())) {
                    $this->Input->setErrors(
                        ['api' => $domain->errors()]
                    );

                    return;
                }
            }
        }

        // Return all the service fields
        $encrypted_fields = [];
        $return = [];
        $fields = ['domain'];
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
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Cancel domain
        $service_fields = $this->serviceFieldsToObject($service->fields);

        $this->log($row->meta->customer . '|delete', serialize($service_fields), 'input', true);
        $cancel = $api->deleteDomain($service_fields->domain);
        $response = $cancel->response();
        $this->log($row->meta->customer . '|delete', serialize($response), 'output', empty($cancel->errors()));

        return null;
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
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Renew domain
        $service_fields = $this->serviceFieldsToObject($service->fields);
        $vars = [
            'period' => 1
        ];
        foreach ($package->pricing as $pricing) {
            if ($pricing->id == $service->pricing_id) {
                $vars['period'] = $pricing->term;
                break;
            }
        }

        $this->log($row->meta->customer . '|renew', serialize($vars), 'input', true);
        $renew = $api->renewDomain($service_fields->domain, $vars);
        $response = $renew->response();
        $this->log($row->meta->customer . '|renew', serialize($response), 'output', empty($renew->errors()));

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
            'domain' => [
                'valid' => [
                    'if_set' => $edit,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('RealtimeRegister.!error.domain.valid', true)
                ]
            ]
        ];

        // Unset irrelevant rules when editing a service
        if ($edit) {
            $edit_fields = [];

            foreach ($rules as $field => $rule) {
                if (!in_array($field, $edit_fields)) {
                    unset($rules[$field]);
                }
            }
        }

        return $rules;
    }

    /**
     * Initializes the RealtimeRegisterApi and returns an instance of that object.
     *
     * @param string $customer The customer handle to authenticate
     * @param string $api_key The API key to authenticate
     * @param bool $sandbox Whether or not to use the sandbox environment for the API requests
     * @return RealtimeRegisterApi The RealtimeRegisterApi instance
     */
    private function getApi($customer, $api_key, $sandbox = false)
    {
        Loader::load(dirname(__FILE__) . DS . 'apis' . DS . 'realtime_register_api.php');

        $api = new RealtimeRegisterApi($customer, $api_key, ($sandbox === 'true'));

        return $api;
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

        // Fetch TLD specific fields
        $tld = $this->getTld($vars->domain ?? '');
        $tld_fields = [];
        if (!empty($tld)) {
            $tld_fields = $this->getTldFields($tld, $package->module_row);
        }

        $module_fields = $this->arrayToModuleFields(
            array_merge(
                Configure::get('RealtimeRegister.domain_fields'),
                $tld_fields,
                Configure::get('RealtimeRegister.nameserver_fields')
            ),
            null,
            $vars
        );

        // Set the Auth code field
        if (isset($vars->transfer) || isset($vars->authcode)) {
            $module_fields = $this->arrayToModuleFields(
                array_merge(
                    Configure::get('RealtimeRegister.domain_fields'),
                    $tld_fields,
                    Configure::get('RealtimeRegister.transfer_fields'),
                    Configure::get('RealtimeRegister.nameserver_fields')
                ),
                null,
                $vars
            );
        }

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

        $module_fields = $this->arrayToModuleFields(
            Configure::get('RealtimeRegister.domain_fields'),
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

        // Fetch TLD specific fields
        $tld = $this->getTld($vars->domain ?? '');
        $tld_fields = [];
        if (!empty($tld)) {
            $tld_fields = $this->getTldFields($tld, $package->module_row);
        }

        $module_fields = $this->arrayToModuleFields(
            array_merge(
                Configure::get('RealtimeRegister.domain_fields'),
                $tld_fields,
                Configure::get('RealtimeRegister.nameserver_fields')
            ),
            null,
            $vars
        );

        // Set the Auth code field
        if (isset($vars->transfer) || isset($vars->authcode)) {
            $module_fields = $this->arrayToModuleFields(
                array_merge(
                    Configure::get('RealtimeRegister.domain_fields'),
                    $tld_fields,
                    Configure::get('RealtimeRegister.transfer_fields'),
                    Configure::get('RealtimeRegister.nameserver_fields')
                ),
                null,
                $vars
            );
        }

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
        return new ModuleFields();
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $params = compact('domain');
        $this->log($row->meta->customer . '|check', serialize($params), 'input', true);

        // Check if the domain is available
        $domain = $api->checkDomain($domain);
        $response = $domain->response();

        $this->log($row->meta->customer . '|check', serialize($response), 'output', isset($response->available));

        return $response->available ?? false;
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
        // If the domain is available for registration, then it is not available for transfer
        return !$this->checkAvailability($domain, $module_row_id);
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
        $tabs = [
            'tabClientContacts' => [
                'name' => Language::_('RealtimeRegister.tab_client_contacts.title', true),
                'icon' => 'fas fa-users'
            ],
            'tabClientNameservers' => [
                'name' => Language::_('RealtimeRegister.tab_client_nameservers.title', true),
                'icon' => 'fas fa-server'
            ],
            'tabClientHosts' => [
                'name' => Language::_('RealtimeRegister.tab_client_hosts.title', true),
                'icon' => 'fas fa-hdd'
            ],
            'tabClientDnssec' => [
                'name' => Language::_('RealtimeRegister.tab_client_dnssec.title', true),
                'icon' => 'fas fa-globe-americas'
            ],
            'tabClientDns' => [
                'name' => Language::_('RealtimeRegister.tab_client_dns.title', true),
                'icon' => 'fas fa-sitemap'
            ],
            'tabClientSettings' => [
                'name' => Language::_('RealtimeRegister.tab_client_settings.title', true),
                'icon' => 'fas fa-cog'
            ]
        ];

        // Check if DNS Management is enabled
        if (!$this->featureServiceEnabled('dns_management', $service)) {
            unset($tabs['tabClientDnssec'], $tabs['tabClientDns']);
        }

        return $tabs;
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
        $tabs = [
            'tabContacts' => Language::_('RealtimeRegister.tab_contacts.title', true),
            'tabNameservers' => Language::_('RealtimeRegister.tab_nameservers.title', true),
            'tabHosts' => Language::_('RealtimeRegister.tab_hosts.title', true),
            'tabDnssec' => Language::_('RealtimeRegister.tab_dnssec.title', true),
            'tabDns' => Language::_('RealtimeRegister.tab_dns.title', true),
            'tabSettings' => Language::_('RealtimeRegister.tab_settings.title', true)
        ];

        // Check if DNS Management is enabled
        // if (!$this->featureServiceEnabled('dns_management', $service)) {
        //     unset($tabs['tabDnssec'], $tabs['tabDns']);
        // }

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
     * Contacts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_contacts', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Update domain contacts
        if (!empty($post)) {
            $this->setDomainContacts($service_fields->domain, $post['contacts'], $row->id);

            $vars = (object) $post;
        }

        // Fetch current contacts
        $contacts = $this->getDomainContacts($service_fields->domain, $row->id);

        $this->view->set('contacts', $contacts);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client Contacts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_contacts', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Update domain contacts
        if (!empty($post)) {
            $this->setDomainContacts($service_fields->domain, $post['contacts'], $row->id);

            $vars = (object) $post;
        }

        // Fetch current contacts
        $contacts = $this->getDomainContacts($service_fields->domain, $row->id);

        $this->view->set('contacts', $contacts);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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
    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_nameservers', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage nameservers
        if (!empty($post)) {
            $ns = [];
            for ($i = 0; $i <= 5; $i++) {
                if (!empty($post['ns' . ($i + 1)])) {
                    $ns[] = $post['ns' . ($i + 1)];
                }
            }

            $this->setDomainNameservers($service_fields->domain, $row->id, $ns);
        }

        // Fetch current nameservers
        $nameservers = $this->getDomainNameServers($service_fields->domain, $row->id);
        for ($i = 0; $i <= 5; $i++) {
            if (empty($vars->{'ns' . ($i + 1)}) && isset($nameservers[$i]['url'])) {
                $vars->{'ns' . ($i + 1)} = $nameservers[$i]['url'];
            }
        }

        $this->view->set('nameservers', $nameservers);
        $this->view->set('nameserver_fields', Configure::get('RealtimeRegister.nameserver_fields'));
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_nameservers', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage nameservers
        if (!empty($post)) {
            $ns = [];
            for ($i = 0; $i <= 5; $i++) {
                if (!empty($post['ns' . ($i + 1)])) {
                    $ns[] = $post['ns' . ($i + 1)];
                }
            }

            $this->setDomainNameservers($service_fields->domain, $row->id, $ns);
        }

        // Fetch current nameservers
        $nameservers = $this->getDomainNameServers($service_fields->domain, $row->id);
        for ($i = 0; $i <= 5; $i++) {
            if (empty($vars->{'ns' . ($i + 1)}) && isset($nameservers[$i]['url'])) {
                $vars->{'ns' . ($i + 1)} = $nameservers[$i]['url'];
            }
        }

        $this->view->set('nameservers', $nameservers);
        $this->view->set('nameserver_fields', Configure::get('RealtimeRegister.nameserver_fields'));
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Hosts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_hosts', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage hosts
        if (!empty($post)) {
            $this->log($row->meta->customer . '|hosts', serialize($post), 'input', true);

            if ($post['action'] == 'add') {
                $action = $api->createHost($post['host'], $post['ip_address']);
            }

            if ($post['action'] == 'delete') {
                $action = $api->deleteHost($post['host']);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|hosts', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current hosts
        $this->log($row->meta->customer . '|hosts', serialize($service_fields), 'input', true);
        $hosts = $api->getDomainHosts($service_fields->domain);
        $response = $hosts->response();
        $this->log($row->meta->customer . '|hosts', serialize($response), 'output', empty($hosts->errors()));

        $hosts = [];
        foreach ($response->entities ?? [] as $host) {
            $ips = [];
            foreach ($host->addresses as $ip_address) {
                $ips[] = ['ip' => $ip_address->address, 'version' => ($ip_address->ipVersion == 'V4') ? '4' : '6'];
            }

            $hosts[] = [
                'host' => $host->hostName,
                'ips' => $ips
            ];
        }

        $this->view->set('hosts', $hosts);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client Hosts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_hosts', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage hosts
        if (!empty($post)) {
            $this->log($row->meta->customer . '|hosts', serialize($post), 'input', true);

            if ($post['action'] == 'add') {
                $action = $api->createHost($post['host'], $post['ip_address']);
            }

            if ($post['action'] == 'delete') {
                $action = $api->deleteHost($post['host']);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|hosts', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current hosts
        $this->log($row->meta->customer . '|hosts', serialize($service_fields), 'input', true);
        $hosts = $api->getDomainHosts($service_fields->domain);
        $response = $hosts->response();
        $this->log($row->meta->customer . '|hosts', serialize($response), 'output', empty($hosts->errors()));

        $hosts = [];
        foreach ($response->entities ?? [] as $host) {
            $ips = [];
            foreach ($host->addresses as $ip_address) {
                $ips[] = ['ip' => $ip_address->address, 'version' => ($ip_address->ipVersion == 'V4') ? '4' : '6'];
            }

            $hosts[] = [
                'host' => $host->hostName,
                'ips' => $ips
            ];
        }

        $this->view->set('hosts', $hosts);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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
    public function tabDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_dnssec', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage DNSSEC
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            $domain = $this->getDomainInfo($service_fields->domain, $row->id);

            if ($post['action'] == 'add') {
                $key = $post;
                unset($key['_csrf_token']);
                unset($key['action']);

                $params = [
                    'keyData' => ($domain['keyData'] ?? []) + [$key]
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            if ($post['action'] == 'delete') {
                $key = [];
                foreach ($domain['keyData'] as $key_data) {
                    if ($key_data->publicKey !== $post['public_key']) {
                        $key[] = $key_data;
                    }
                }

                $params = [
                    'keyData' => $key
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current status
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);

        // Fetch DNSSEC Key
        $dnssec_key = [];
        if (!empty($domain['keyData'])) {
            $dnssec_key = $domain['keyData'];
        }

        $this->view->set('domain', $domain);
        $this->view->set('dnssec_key', $dnssec_key);
        $this->view->set('dnssec_flags', $this->dnssec_flags);
        $this->view->set('dnssec_algorithms', $this->dnssec_algorithms);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client DNSSEC tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_dnssec', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage DNSSEC
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            $domain = $this->getDomainInfo($service_fields->domain, $row->id);

            if ($post['action'] == 'add') {
                $key = $post;
                unset($key['_csrf_token']);
                unset($key['action']);

                $params = [
                    'keyData' => ($domain['keyData'] ?? []) + [$key]
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            if ($post['action'] == 'delete') {
                $key = [];
                foreach ($domain['keyData'] as $key_data) {
                    if ($key_data->publicKey !== $post['public_key']) {
                        $key[] = $key_data;
                    }
                }

                $params = [
                    'keyData' => $key
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current status
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);

        // Fetch DNSSEC Key
        $dnssec_key = [];
        if (!empty($domain['keyData'])) {
            $dnssec_key = $domain['keyData'];
        }

        $this->view->set('domain', $domain);
        $this->view->set('dnssec_key', $dnssec_key);
        $this->view->set('dnssec_flags', $this->dnssec_flags);
        $this->view->set('dnssec_algorithms', $this->dnssec_algorithms);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * DNS tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabDns($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_dns', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage DNS
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            $zone = $api->getZone($service_fields->domain);
            $response = $zone->response();
            $dns_records = $response->records ?? [];

            if ($post['action'] == 'add') {
                $record = $post;
                unset($record['_csrf_token']);
                unset($record['action']);

                foreach ($record as $key => $value) {
                    if (empty($value)) {
                        unset($record[$key]);
                    }
                }

                $params = [
                    'records' => $dns_records + [$record]
                ];
                $action = $api->updateZone($service_fields->domain, $params);
            }

            if ($post['action'] == 'delete') {
                $records = [];
                foreach ($dns_records as $record) {
                    if ($record->name !== $post['name'] && $record->type !== $post['type']) {
                        $records[] = $record;
                    }
                }

                $params = [
                    'records' => $records
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current DNS records
        $zone = $api->getZone($service_fields->domain);
        $response = $zone->response();

        $enabled = false;
        if (!empty($response)) {
            $enabled = true;
        }

        $dns_records = [];
        if (!empty($response->records)) {
            $dns_records = $response->records;
        }

        $this->view->set('enabled', $enabled);
        $this->view->set('dns_records', $dns_records);
        $this->view->set('record_types', $this->record_types);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client DNS tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientDns($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_dns', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage DNS
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            $zone = $api->getZone($service_fields->domain);
            $response = $zone->response();
            $dns_records = $response->records ?? [];

            if ($post['action'] == 'add') {
                $record = $post;
                unset($record['_csrf_token']);
                unset($record['action']);

                foreach ($record as $key => $value) {
                    if (empty($value)) {
                        unset($record[$key]);
                    }
                }

                $params = [
                    'records' => $dns_records + [$record]
                ];
                $action = $api->updateZone($service_fields->domain, $params);
            }

            if ($post['action'] == 'delete') {
                $records = [];
                foreach ($dns_records as $record) {
                    if ($record->name !== $post['name'] && $record->type !== $post['type']) {
                        $records[] = $record;
                    }
                }

                $params = [
                    'records' => $records
                ];
                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current DNS records
        $zone = $api->getZone($service_fields->domain);
        $response = $zone->response();

        $enabled = false;
        if (!empty($response)) {
            $enabled = true;
        }

        $dns_records = [];
        if (!empty($response->records)) {
            $dns_records = $response->records;
        }

        $this->view->set('enabled', $enabled);
        $this->view->set('dns_records', $dns_records);
        $this->view->set('record_types', $this->record_types);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

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
    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_settings', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage settings
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            if ($post['action'] == 'update') {
                $domain = $this->getDomainInfo($service_fields->domain, $row->id);

                if (($post['registrar_lock'] ?? 'false') == 'true') {
                    if (!in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
                        $domain['status'][] = 'CLIENT_TRANSFER_PROHIBITED';
                    }
                } else {
                    if (in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
                        foreach ($domain['status'] as $key => $status) {
                            if ($status == 'CLIENT_TRANSFER_PROHIBITED') {
                                unset($domain['status'][$key]);
                            }
                        }
                    }
                }

                $domain['status'] = array_values($domain['status']);

                $action = $api->updateDomain($service_fields->domain, ['status' => $domain['status'] ?? []]);
            }

            if ($post['action'] == 'update_auth_code') {
                $action = $api->updateDomain($service_fields->domain, ['authcode' => $post['authcode'] ?? null]);
            }

            if ($post['action'] == 'enable_dns') {
                $params = $post['enable_dns'] == 'true' ? [
                        'ns' => [],
                        'zone' => [
                            'service' => 'BASIC'
                        ]
                    ] : [
                        'ns' => []
                    ];

                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current status
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);

        // Fetch domain status
        $registrar_lock = 'false';
        if (in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
            $registrar_lock = 'true';
        }

        // Fetch zone status
        $enable_dns = 'true';
        if (empty($domain['zone'])) {
            $enable_dns = 'false';
        }

        $this->view->set('domain', $domain);
        $this->view->set('registrar_lock', $registrar_lock);
        $this->view->set('enable_dns', $enable_dns);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
    }

    /**
     * Client Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->view = new View('tab_client_settings', 'default');

        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Check if the user is allowed to manage this domain
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);
        if (in_array('CLIENT_UPDATE_PROHIBITED', ($domain['status'] ?? []))) {
            $this->setMessage('notice', Language::_('RealtimeRegister.!notice.client_update_prohibited', true));

            return;
        }

        // Manage settings
        if (!empty($post)) {
            $this->log($row->meta->customer . '|domains', serialize($post), 'input', true);

            if ($post['action'] == 'update') {
                $domain = $this->getDomainInfo($service_fields->domain, $row->id);

                if (($post['registrar_lock'] ?? 'false') == 'true') {
                    if (!in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
                        $domain['status'][] = 'CLIENT_TRANSFER_PROHIBITED';
                    }
                } else {
                    if (in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
                        foreach ($domain['status'] as $key => $status) {
                            if ($status == 'CLIENT_TRANSFER_PROHIBITED') {
                                unset($domain['status'][$key]);
                            }
                        }
                    }
                }

                $domain['status'] = array_values($domain['status']);

                $action = $api->updateDomain($service_fields->domain, ['status' => $domain['status'] ?? []]);
            }

            if ($post['action'] == 'update_auth_code') {
                $action = $api->updateDomain($service_fields->domain, ['authcode' => $post['authcode']]);
            }

            if ($post['action'] == 'enable_dns') {
                $params = $post['enable_dns'] == 'true' ? [
                        'ns' => [],
                        'zone' => [
                            'service' => 'BASIC'
                        ]
                    ] : [
                        'ns' => []
                    ];

                $action = $api->updateDomain($service_fields->domain, $params);
            }

            // Set error, if any
            $response = $action->response();
            $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($action->errors()));
            if (!empty($action->errors())) {
                $this->Input->setErrors(['errors' => $action->errors()]);

                $vars = (object) $post;
            }
        }

        // Fetch current status
        $domain = $this->getDomainInfo($service_fields->domain, $row->id);

        // Fetch domain status
        $registrar_lock = 'false';
        if (in_array('CLIENT_TRANSFER_PROHIBITED', $domain['status'] ?? [])) {
            $registrar_lock = 'true';
        }

        // Fetch zone status
        $enable_dns = 'true';
        if (empty($domain['zone'])) {
            $enable_dns = 'false';
        }

        $this->view->set('domain', $domain);
        $this->view->set('registrar_lock', $registrar_lock);
        $this->view->set('enable_dns', $enable_dns);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'realtime_register' . DS);

        return $this->view->fetch();
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Get the domain information
        $domain = $api->getDomain($domain);
        $result = $domain->response();

        return (array) $result;
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Get the domain information
        $domain = $api->getDomain($domain);
        $result = $domain->response();

        return date(strtotime($result->createdDate), $format);
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Get the domain information
        $domain = $api->getDomain($domain);
        $result = $domain->response();

        return date(strtotime($result->expiryDate), $format);
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
     * Get a list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Get a filtered list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $filters A list of criteria by which to filter fetched pricings including but not limited to:
     *
     *  - tlds A list of tlds for which to fetch pricings
     *  - currencies A list of currencies for which to fetch pricings
     *  - terms A list of terms for which to fetch pricings
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        if (!isset($this->Currencies)) {
            Loader::loadModels($this, ['Currencies']);
        }

        // Get all currencies
        $currencies = [];
        $company_currencies = $this->Currencies->getAll(Configure::get('Blesta.company_id'));
        foreach ($company_currencies as $currency) {
            $currencies[$currency->code] = $currency;
        }

        // Fetch the TLDs results from the cache, if they exist
        $cache = Cache::fetchCache(
            'tlds',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'realtime_register' . DS
        );

        $tlds = [];
        if ($cache) {
            $tlds = safe_unserialize(base64_decode($cache));
        }

        if (empty($tlds)) {
            try {
                $row = $this->getModuleRow($module_row_id);

                if (!$row) {
                    $rows = $this->getModuleRows();
                    $row = reset($rows);

                    if (!$row) {
                        $this->Input->setErrors(
                            ['module_row' => ['missing' => Language::_('RealtimeRegister.!error.module_row.missing', true)]]
                        );

                        return;
                    }
                }

                // Initialize API
                $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

                // Fetch price list
                $this->log($row->meta->customer . '|pricelist', serialize([]), 'input', true);
                $response = $api->priceList();
                $price_list = $response->response();
                $this->log($row->meta->customer . '|pricelist', serialize($price_list), 'output', empty($response->errors()));

                // Build pricing array
                foreach ($price_list->prices ?? [] as $product) {
                    // Skip product if it is not a domain
                    if (!str_contains($product->product, 'domain')) {
                        continue;
                    }

                    // Set product TLD
                    $tld = '.' . str_replace(
                        '_',
                        '.',
                        trim(str_replace(['domain_', 'centralnic_', '_legacy'], '_', strtolower($product->product)), '_')
                    );

                    // Set pricing
                    $pricing_map = ['CREATE' => 'register', 'TRANSFER' => 'transfer', 'RENEW' => 'renew'];
                    if (!array_key_exists($product->action, $pricing_map)) {
                        continue;
                    }

                    // Skip TLD if price is zero
                    if ($product->price <= 0) {
                        continue;
                    }

                    // Format TLD price
                    $price = (float) number_format($product->price / 100, 2, '.', '');

                    // Set TLD pricing
                    for ($i = 1; $i <= 10; $i++) {
                        $tlds[$tld][$product->currency][$i][$pricing_map[$product->action]] = $price * $i;

                        foreach ($currencies as $currency) {
                            $tlds[$tld][$currency->code][$i][$pricing_map[$product->action]] = $this->Currencies->convert(
                                $price * $i,
                                $product->currency,
                                $currency->code,
                                Configure::get('Blesta.company_id')
                            );
                        }
                    }
                }

                if (count($tlds) > 0 && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                    try {
                        Cache::writeCache(
                            'tlds',
                            base64_encode(serialize($tlds)),
                            strtotime(Configure::get('Blesta.cache_length')) - time(),
                            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'realtime_register' . DS
                        );
                    } catch (\Throwable $e) {
                        // Write to cache failed, so disable caching
                        Configure::set('Caching.on', false);
                    }
                }
            } catch (\Throwable $e) {
                // Do nothing
            }
        }

        // Apply filters
        foreach ($tlds as $tld => $currencies) {
            // Filter by 'tlds'
            if (isset($filters['tlds']) && !in_array($tld, $filters['tlds'])) {
                unset($tlds[$tld]);
            }

            foreach ($currencies as $currency => $terms) {
                // Filter by 'currencies'
                if (isset($filters['currencies']) && !in_array($currency, $filters['currencies'])) {
                    unset($tlds[$tld][$currency]);
                }

                foreach ($terms as $term => $pricing) {
                    // Filter by 'terms'
                    if (isset($filters['terms']) && !in_array($term, $filters['terms'])) {
                        unset($tlds[$tld][$currency][$term]);
                    }
                }
            }
        }

        return $tlds;
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
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Register the new domain
        $domain = $api->createDomain($domain, $vars);
        $response = $domain->response();

        return ($response->status == 'OK');
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Renew the domain
        $renew = $api->renewDomain($domain, $vars);
        $response = $renew->response();

        return !empty($response->expiryDate);
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
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Transfer the domain
        $transfer = $api->transferDomain($domain, $vars);
        $response = $transfer->response();

        // Send confirmation email
        $this->resendTransferEmail($domain, $module_row_id);

        return !in_array($response->status ?? 'failed', ['cancelled', 'rejected', 'failed']);
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Fetch current contacts
        $contacts = [];
        $domain = $this->getDomainInfo($domain, $module_row_id);

        foreach ($domain['contacts'] ?? [] as $contact) {
            $this->log($row->meta->customer . '|contacts', serialize($contact), 'input', true);
            $remote_contact = $api->getContact($contact->handle);
            $response = $remote_contact->response();
            $this->log($row->meta->customer . '|contacts', serialize($response), 'output', empty($remote_contact->errors()));

            if (!empty($remote_contact->errors())) {
                $this->Input->setErrors(['errors' => $remote_contact->errors()]);
                continue;
            }

            // Format contact
            $name = explode(' ', $response->name ?? '', 2);
            $contacts[] = (object) [
                'external_id' => $contact->role ?? '',
                'email' => $response->email ?? '',
                'phone' => $response->voice ?? '',
                'first_name' => $name[0] ?? '',
                'last_name' => $name[1] ?? '',
                'address1' => $response->addressLine[0] ?? '',
                'address2' => $response->addressLine[1] ?? '',
                'city' => $response->city ?? '',
                'state' => '',
                'zip' => $response->postalCode ?? '',
                'country' => $response->country ?? ''
            ];
        }

        return $contacts;
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
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Fetch the current contacts, to be deleted later
        $domain_info = $this->getDomainInfo($domain, $module_row_id);
        $remote_contacts = $domain_info['contacts'] ?? [];

        // Set new contacts
        $handles = [];
        $roles = ['ADMIN', 'BILLING', 'TECH'];
        foreach ($vars as $contact) {
            if (!in_array($contact['external_id'], $roles)) {
                continue;
            }

            $handle = uniqid($contact['external_id']);
            $params = [
                'name' => $contact['first_name'] . ' ' . $contact['last_name'],
                'addressLine' => [$contact['address1'] ?? '', $contact['address2'] ?? ''],
                'postalCode' => $contact['zip'] ?? '',
                'city' => $contact['city'] ?? '',
                'country' => $contact['country'] ?? '',
                'email' => $contact['email'] ?? '',
                'voice' => $this->formatPhone($contact['phone'] ?? '1111111111', $contact['country'] ?? '')
            ];

            if (empty($params['addressLine'][1])) {
                unset($params['addressLine'][1]);
            }

            $handles[] = ['role' => $contact['external_id'], 'handle' => $handle];

            $this->log($row->meta->customer . '|contacts', serialize($params), 'input', true);
            $contact = $api->createContact($handle, $params);
            $response = $contact->response();
            $this->log($row->meta->customer . '|contacts', serialize($response), 'output', empty($contact->errors()));

            if (!empty($contact->errors())) {
                $this->Input->setErrors(
                    ['api' => $contact->errors()]
                );

                return false;
            }
        }

        // Update domain
        $params = ['contacts' => $handles];

        $this->log($row->meta->customer . '|domains', serialize($params), 'input', true);
        $domain = $api->updateDomain($domain, $params);
        $response = $domain->response();
        $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($domain->errors()));

        // Delete old contacts
        foreach ($remote_contacts as $remote_contact) {
            $this->log($row->meta->customer . '|contacts', serialize($remote_contact), 'input', true);
            $contact = $api->deleteContact($remote_contact->handle);
            $response = $contact->response();
            $this->log($row->meta->customer . '|contacts', serialize($response), 'output', empty($contact->errors()));
        }
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Fetch domain
        $this->log($row->meta->customer . '|domains', serialize(compact('domain')), 'input', true);
        $domain = $api->getDomain($domain);
        $response = $domain->response();
        $this->log($row->meta->customer . '|domains', serialize($response), 'output', empty($domain->errors()));

        $ns = [];
        foreach ($response->ns ?? [] as $host) {
            $ns[] = [
                'url' => trim($host),
                'ips' => [gethostbyname(trim($host))]
            ];
        }

        if (!empty($domain->errors())) {
            $this->Input->setErrors(['errors' => $domain->errors()]);
        }

        return $ns;
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
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        // Format nameservers
        foreach ($vars as $i => &$ns) {
            if (empty($ns)) {
                unset($vars[$i]);
            } else {
                $ns = trim($ns);
            }
        }

        // Update the domain
        $params = ['ns' => array_values($vars)];
        $this->log($row->meta->customer . '|update', serialize($params), 'input', true);
        $domain = $api->updateDomain($domain, $params);
        $this->log($row->meta->customer . '|update', serialize($domain->response()), 'output', empty($domain->errors()));

        if (!empty($domain->errors())) {
            $this->Input->setErrors(['errors' => $domain->errors()]);
        }

        return empty($domain->errors());
    }

    /**
     * Get a list of the TLDs supported by the registrar module
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs supported by the registrar module
     */
    public function getTlds($module_row_id = null)
    {
        $pricing = $this->getTldPricing($module_row_id);

        return !empty($pricing) ? array_keys($pricing) : Configure::get('RealtimeRegister.tlds');
    }

    /**
     * Returns the TLD of the given domain
     *
     * @param string $domain The domain to return the TLD from
     * @return string The TLD of the domain
     */
    private function getTld($domain)
    {
        $tlds = $this->getTlds();

        $domain = strtolower($domain);

        foreach ($tlds as $tld) {
            if (substr($domain, -strlen($tld)) == $tld) {
                return $tld;
            }
        }

        return strstr($domain, '.');
    }

    /**
     * Returns the specific fields for a given TLD
     *
     * @param string $tld The TLD to fetch the fields
     * @param int|null $module_row_id The ID of the module row to fetch for the current module
     * @return array An array of module fields for the specific TLD
     */
    private function getTldFields($tld, $module_row_id = null)
    {
        $fields = [];

        // Format TLD
        $tld = ltrim($tld, '.');

        // Fetch TLD metadata
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->customer, $row->meta->api_key, $row->meta->sandbox);

        $this->log($row->meta->customer . '|tlds', serialize(compact('tld')), 'input', true);
        $tld_info = $api->getTld($tld);
        $response = $tld_info->response();
        $this->log($row->meta->customer . '|tlds', serialize($response), 'output', empty($tld_info->errors()));

        if (!empty($response->metadata->contactProperties)) {
            foreach ($response->metadata->contactProperties as $property) {
                $lang = Language::_('RealtimeRegister.domain.' . $property->name, true);
                $fields[$property->name] = [
                    'label' => !empty($lang) ? $lang : $property->label,
                    'type' => empty($property->values) ? 'text' : 'select',
                    'options' => (array) $property->values ?? [],
                    'registry' => $response->provider ?? ''
                ];
            }
        }

        // Fallback to built-in fields
        if (empty($fields)) {
            $fields = Configure::get('RealtimeRegister.domain_fields.' . $tld) ?? [];
        }

        return $fields;
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

        return $this->Contacts->intlNumber($number, $country, '.');
    }
}
