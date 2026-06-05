<?php

namespace Blesta\Core\Util\AI;

use Blesta\Core\Util\Schemas\SchemaLoader;

/**
 * Chatbot Context Builder
 *
 * Builds compact schema context strings for injection into AI chatbot messages.
 * Each builder method produces a focused context block with schema data and
 * instructions for a specific use case (reports, API queries, etc.).
 *
 * @package blesta
 * @subpackage core.Util.AI
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ChatbotContextBuilder
{
    /**
     * @var SchemaLoader Schema loader instance
     */
    private $schemaLoader;

    /**
     * @var array Runtime options (e.g. base_url)
     */
    private $options = [];

    /**
     * Initialize the ChatbotContextBuilder
     *
     * @param array $options Runtime options:
     *   - base_url (string): The installation's base URL (e.g. 'https://example.com/blesta/')
     * @param SchemaLoader|null $schemaLoader Schema loader instance (auto-created if null)
     */
    public function __construct(array $options = [], SchemaLoader $schemaLoader = null)
    {
        $this->options = $options;
        $this->schemaLoader = $schemaLoader ?? new SchemaLoader();
    }

    /**
     * Build context for a given context key
     *
     * @param string $contextKey The context key (e.g. 'custom_report')
     * @return string|null The context string, or null if key is unknown
     */
    public function build($contextKey)
    {
        $method = 'build' . str_replace('_', '', ucwords($contextKey, '_')) . 'Context';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return null;
    }

    /**
     * Build context for the Custom Report builder
     *
     * Provides schema for core reporting tables and instructions for writing
     * SQL queries compatible with Blesta's custom report system.
     *
     * @return string The formatted context string
     */
    public function buildCustomReportContext()
    {
        $instructions = <<<'INSTRUCTIONS'
You are helping the user write a SQL SELECT query for Blesta's Custom Report system.

IMPORTANT RULES:
- Write ONLY SELECT queries. No INSERT, UPDATE, DELETE, or DDL statements.
- Use exact table names as shown below. Blesta does not use table prefixes.
- The query will be entered at: Billing > Reports: Customize, by clicking the [+] button to add a new report.
- Use named placeholders like :parameter_name for values the user should supply at runtime. Each placeholder requires a corresponding field to be created on the report with a matching name. For example, :currency in the query requires a field named "currency". Blesta will prompt the user for each field's value when the report is run.
- Column `order` is a reserved word in MySQL — always backtick it: `order`.
- All datetime columns store UTC values in 'YYYY-MM-DD HH:MM:SS' format.
- Money values use decimal(19,4). The currency column (char(3)) indicates the currency code (e.g. 'USD').
- Multi-company installations scope data by company_id. Include company_id filtering where relevant.
- Explain each column and any WHERE conditions in your response.
- If the query is complex, break it down step by step.

INSTRUCTIONS;

        $tables = [
            'clients' => 'Clients — one row per client account',
            'contacts' => 'Contacts — name, email, address for each client (contact_type: primary, billing, other)',
            'client_groups' => 'Client groups — organizational grouping of clients',
            'invoices' => 'Invoices — billing documents (status: active, proforma, draft, void)',
            'invoice_lines' => 'Invoice line items — individual charges on an invoice',
            'invoice_line_taxes' => 'Tax entries applied to invoice lines',
            'services' => 'Services — provisioned products/services for clients (status: active, canceled, pending, suspended, in_review)',
            'packages' => 'Packages — product/service definitions',
            'package_pricing' => 'Package pricing — links packages to pricings table',
            'pricings' => 'Pricing terms — billing cycle, price, fees (period: day, week, month, year, onetime)',
            'transactions' => 'Payments received (status: approved, declined, void, error, pending, refunded, returned)',
            'transaction_applied' => 'Links transactions to invoices — which payments applied to which invoices',
            'coupons' => 'Discount coupons',
            'currencies' => 'Currency definitions and exchange rates',
            'taxes' => 'Tax rules (type: inclusive_calculated, inclusive, exclusive)',
        ];

        $schemas = [];
        foreach ($tables as $tableName => $description) {
            $schema = $this->loadDatabaseSchema($tableName);
            if ($schema) {
                $schemas[] = $this->formatTableSchema($tableName, $description, $schema);
            }
        }

        $relationships = <<<'RELS'

KEY RELATIONSHIPS:
- invoices.client_id -> clients.id
- invoice_lines.invoice_id -> invoices.id
- invoice_lines.service_id -> services.id (nullable)
- invoice_line_taxes.line_id -> invoice_lines.id
- services.client_id -> clients.id
- services.pricing_id -> package_pricing.id, then package_pricing.pricing_id -> pricings.id
- services.coupon_id -> coupons.id (nullable)
- package_pricing.package_id -> packages.id
- package_pricing.pricing_id -> pricings.id
- transactions.client_id -> clients.id
- transaction_applied.transaction_id -> transactions.id
- transaction_applied.invoice_id -> invoices.id
- contacts.client_id -> clients.id
- clients.client_group_id -> client_groups.id
- taxes.company_id -> companies.id

RELS;

        return $instructions . "\nDATABASE SCHEMA:\n\n" . implode("\n", $schemas) . $relationships;
    }

    /**
     * Build context for the API Query helper
     *
     * Provides model method presets, relationships, and API conventions
     * to help developers write correct Blesta API calls.
     *
     * @return string The formatted context string
     */
    public function buildApiQueryContext()
    {
        $apiBase = rtrim($this->options['base_url'] ?? 'https://yourdomain.com/', '/') . '/api/';

        $instructions = <<<'INSTRUCTIONS'
You are helping the user write Blesta API requests.

BLESTA API CONVENTIONS:
- Base URL: {{API_BASE}}
- Endpoint format: /api/{ModelName}/{methodName}.{format}
  - format: json or xml
  - ModelName: PascalCase (e.g. Clients, Invoices, Services)
  - methodName: camelCase — standard CRUD methods are: get, getAll, getList, add, edit, delete
  - IMPORTANT: The method to create a record is usually "add". The method to update is "edit", NOT "update".
    Exception: Clients uses "create" instead of "add" (create builds the full user/client/contact record set).
    Always check the MODEL-SPECIFIC METHODS section for the correct method name.
- Authentication: Use Authorization Headers (recommended):
    BLESTA-API-USER: your_api_user
    BLESTA-API-KEY: your_api_key
  API users are created at Settings > System > API Access.
- HTTP methods:
  - GET for read operations (get, getAll, getList)
  - POST for write operations (add, edit, delete)
- Pagination: getList methods accept ?page=N (1-indexed), return paginated results.
  getAll methods return ALL records (no pagination).
- Parameters: passed as query string (GET) or form body (POST).
- Response format (JSON):
  {"response": <data>} on success
  {"errors": {"field": {"type": "Error message"}}} on error

EXAMPLE REQUESTS:

# Get a single client
curl -X GET "{{API_BASE}}clients/get.json?client_id=1" \
  -H "BLESTA-API-USER: YOUR_API_USER_HERE" \
  -H "BLESTA-API-KEY: YOUR_API_KEY_HERE"

# Get all active invoices for a client
curl -X GET "{{API_BASE}}invoices/getall.json?client_id=1&status=active" \
  -H "BLESTA-API-USER: YOUR_API_USER_HERE" \
  -H "BLESTA-API-KEY: YOUR_API_KEY_HERE"

# Create a new client (POST with form data — note: Clients uses "create", not "add")
curl -X POST "{{API_BASE}}clients/create.json" \
  -H "BLESTA-API-USER: YOUR_API_USER_HERE" \
  -H "BLESTA-API-KEY: YOUR_API_KEY_HERE" \
  -d "username=user@example.com&new_password=securePass123&confirm_password=securePass123&first_name=John&last_name=Doe&email=user@example.com&address1=123+Main+St&city=Anytown&state=FL&zip=33000&country=US&client_group_id=1"

PHP SDK (BlestaApi):
Blesta also provides a PHP SDK. Only show PHP SDK examples if the user asks for PHP code.
Usage:
  require_once "blesta_api.php";
  $api = new BlestaApi("{{API_BASE}}", $user, $key);
  $response = $api->get("model_name", "method_name", array('param' => 'value'));
  // For write operations: $api->post("model_name", "method_name", array(...));
  $response->response(); // The response data
  $response->errors();   // Any errors
Note: model_name and method_name are lowercase in the SDK (e.g. "users", "get").

IMPORTANT NOTES:
- By default, provide a complete curl example in your response. Only include a PHP SDK example if the user specifically asks for PHP.
- Explain what fields are returned (use the model schema below).
- methodName in URLs is always lowercase (e.g. /invoices/getall.json not /invoices/getAll.json).
- If the user's request involves multiple API calls, show each call separately in order.
- IMPORTANT: Method signatures vary by model. Not all getAll methods accept the same parameters.
  Use the MODEL-SPECIFIC METHODS section below to find the correct method for each use case.
  The API matches request parameters to method arguments by name (via reflection), so parameter names must match the method signature exactly.

INSTRUCTIONS;

        $instructions = str_replace('{{API_BASE}}', $apiBase, $instructions);

        // Models most commonly used via the API
        $apiModels = [
            'Clients', 'Contacts', 'Invoices', 'Services', 'Packages',
            'Transactions', 'Users', 'Coupons', 'ClientGroups',
        ];

        $modelSummaries = [];
        foreach ($apiModels as $modelName) {
            $summary = $this->formatModelSummary($modelName);
            if ($summary) {
                $modelSummaries[] = $summary;
            }
        }

        $methods = <<<'METHODS'

MODEL-SPECIFIC METHODS:
Use these exact method names and parameter names. The API matches parameters by name.

## Clients
  get($client_id, $get_settings=true)
  getAll($status=null, $client_group_id=null, $get_settings=false)
  getList($status=null, $page=1, $order_by=['id_code'=>'ASC'], $filters=[])
  create($vars) — NOTE: use "create" for Clients, not "add". This is an exception.
  edit($client_id, $vars)
  delete($client_id)

## Contacts
  get($contact_id)
  getAll($client_id, $contact_type=null)
  getList($client_id, $page=1)
  add($vars)
  edit($contact_id, $vars)
  delete($contact_id)

## Invoices
  get($invoice_id)
  getAll($client_id=null, $status='open', $order_by=['date_due'=>'ASC'], $currency=null)
  getList($client_id=null, $status='open', $page=1)
  add($vars) — vars: client_id, status, currency, date_billed, date_due, lines[][description|qty|amount], delivery[]
  edit($invoice_id, $vars)

## Services
  get($service_id)
  getAll() — WARNING: does NOT accept client_id. Use getAllByClient instead.
  getAllByClient($client_id, $status='active') — use this to get services for a specific client
  getList($client_id=null, $status='active', $page=1)
  add($vars)
  edit($service_id, $vars)
  cancel($service_id, $vars) — vars: date_canceled, cancellation_reason
  suspend($service_id, $vars=[]) — vars: suspension_reason
  unsuspend($service_id, $vars=[])

## Packages
  get($package_id)
  getAll($company_id, $order=['name'=>'ASC'], $status=null, $type=null)
  getList($page=1, $order_by=['id_code'=>'asc'], $status=null)
  add($vars)
  edit($package_id, $vars)
  delete($package_id)

## Transactions
  get($transaction_id)
  getList($client_id=null, $status='approved', $page=1)
  add($vars) — vars: client_id, amount, currency, type, transaction_id, gateway_id
  edit($transaction_id, $vars)
  apply($transaction_id, $vars) — vars: amounts[invoice_id]=amount (applies payment to invoices)

METHODS;

        return $instructions . "\nMODEL REFERENCE:\n\n" . implode("\n", $modelSummaries) . $methods;
    }

    /**
     * Build context for the Plugin/Module Development helper
     *
     * Provides framework patterns, model loading, event system, cron tasks,
     * and controller routing for developers building Blesta plugins or modules.
     *
     * @return string The formatted context string
     */
    public function buildPluginDevContext()
    {
        $instructions = <<<'INSTRUCTIONS'
You are helping a developer build a Blesta plugin or module. Provide working PHP code using Blesta's framework patterns.

PLUGIN STRUCTURE:
- Directory: plugins/{plugin_name}/
- Main class: {plugin_name}_plugin.php -> class {PascalName}Plugin extends Plugin
- Controllers: controllers/admin_manage_plugin.php, controllers/client_main.php, etc.
- Models: models/{model_name}.php
- Views: views/default/
- Language: language/en_us/
- Config: config.json (metadata: name, version, authors)

PLUGIN LIFECYCLE METHODS:
- install($plugin_id) — create tables, register cron tasks, set initial settings
- uninstall($plugin_id, $last_instance) — cleanup; $last_instance=true if last company instance
- upgrade($current_version, $plugin_id) — version migrations
- cron($key) — execute a registered cron task by its key

LOADING MODELS:
  // Core models (from app/models/)
  Loader::loadModels($this, ['Services', 'Clients', 'Invoices']);
  $services = $this->Services->getAllByClient($client_id);

  // Plugin models (from plugin's models/ directory)
  Loader::loadModels($this, ['MyPlugin.MyModel']);
  $data = $this->MyModel->get($id);

  // In controllers, use $this->uses() shorthand:
  $this->uses(['Services', 'MyPlugin.MyModel']);

LOADING COMPONENTS & HELPERS:
  Loader::loadComponents($this, ['Record', 'Input', 'SettingsCollection']);
  // Common components: Record (DB queries), Input (validation), Upload, Acl, Session

  Loader::loadHelpers($this, ['Form', 'Html', 'Date']);

CONTROLLER ROUTING:
- URL pattern: {base}/plugin/{plugin_dir}/{controller_name}/{action}/{params}/
- Controllers extend AppController
- Public methods are routable as actions
- Example: plugin/my_plugin/admin_manage_plugin/settings/ -> AdminManagePlugin::settings()

  class AdminManagePlugin extends AppController {
      public function index() {
          $this->parent->requireLogin();
          $this->uses(['MyPlugin.MyModel']);
          Language::loadLang('admin_manage_plugin', null, PLUGINDIR . 'my_plugin' . DS . 'language' . DS);

          // Handle POST
          if (!empty($this->post)) {
              $this->MyModel->update($this->post);
              if (($errors = $this->MyModel->errors())) {
                  $this->parent->setMessage('error', $errors);
              } else {
                  $this->parent->setMessage('message', Language::_('AdminManagePlugin.success', true));
              }
          }

          $this->set('data', $this->MyModel->getAll());
          // Renders views/default/admin_manage_plugin.pdt
      }
  }

EVENT SYSTEM:
- Implement getEvents() in your plugin class to listen for core events
- Event names follow pattern: {Model}.{action}{Before|After}
  Examples: Services.addAfter, Clients.deleteAfter, Invoices.editBefore, Packages.addBefore

  public function getEvents() {
      return [
          ['event' => 'Services.addAfter', 'callback' => ['this', 'onServiceAdded']],
          ['event' => 'Invoices.addAfter', 'callback' => ['this', 'onInvoiceCreated']],
      ];
  }

  public function onServiceAdded($event) {
      $params = $event->getParams();   // ['service_id' => 123, ...]
      $return = $event->getReturnValue();
      // Do something with the event data
  }

Available event observers: Services, Clients, Contacts, Invoices, Packages, Emails, Companies, Transactions, Users

CRON TASKS:
  private function getCronTasks() {
      return [
          [
              'key' => 'my_task_key',
              'dir' => 'my_plugin',
              'task_type' => 'plugin',
              'name' => Language::_('MyPlugin.cron.my_task_name', true),
              'description' => Language::_('MyPlugin.cron.my_task_desc', true),
              'type' => 'interval',        // 'time' (HH:MM:SS daily) or 'interval' (seconds)
              'type_value' => 300,          // Every 5 minutes
              'enabled' => 1
          ]
      ];
  }

  // Register in install():
  public function install($plugin_id) {
      Loader::loadModels($this, ['CronTasks']);
      $this->addCronTasks($this->getCronTasks());
  }

  // Execute in cron():
  public function cron($key) {
      if ($key === 'my_task_key') {
          // Task logic here
      }
  }

NAVIGATION & WIDGETS:
- Implement getActions() to add nav items, widgets, or action links

  public function getActions() {
      return [
          [
              'action' => 'widget_staff_home',    // Widget on staff dashboard
              'uri' => 'plugin/my_plugin/admin_main/widget/',
              'name' => 'MyPlugin.widget_name',
          ],
          [
              'action' => 'nav_primary_client',   // Client nav item
              'uri' => 'plugin/my_plugin/client_main/index/',
              'name' => 'MyPlugin.nav_name',
              'options' => ['class' => 'my_plugin', 'icon' => 'bi bi-gear']
          ]
      ];
  }

Action types: widget_staff_home, widget_staff_billing, widget_staff_client, nav_primary_client, nav_primary_staff, action_staff_client

DATABASE QUERIES (Record component):
  Loader::loadComponents($this, ['Record']);

  // Select
  $results = $this->Record->select(['id', 'name'])
      ->from('my_table')
      ->where('company_id', '=', Configure::get('Blesta.company_id'))
      ->where('status', '=', 'active')
      ->fetchAll();

  // Insert
  $this->Record->insert('my_table', ['field' => 'value', 'company_id' => Configure::get('Blesta.company_id')]);

  // Update
  $this->Record->where('id', '=', $id)->update('my_table', ['field' => 'new_value']);

  // Delete
  $this->Record->from('my_table')->where('id', '=', $id)->delete();

MODULE DEVELOPMENT:
Modules are different from plugins. They provision and manage services on remote servers (e.g. cPanel, Plesk).
- Directory: components/modules/{module_name}/
- Main class: {module_name}.php -> class {PascalName} extends Module
- Config: config.json (metadata), config/{module_name}.php (settings)
- Views: views/default/
- Language: language/en_us/

Module lifecycle (different from plugins — no $plugin_id parameter):
  install() — create tables, initial setup
  upgrade($current_version) — version migrations
  uninstall($module_id, $last_instance) — cleanup

Service lifecycle hooks (called when services are provisioned/managed):
  addService($package, array $vars=null, $parent_package=null, $parent_service=null, $status='pending')
    — Provision on remote server. Return array of meta fields: [['key'=>..., 'value'=>..., 'encrypted'=>0]]
  editService($package, $service, array $vars=[], $parent_package=null, $parent_service=null)
    — Update on remote server. Return meta fields array or null.
  cancelService($package, $service, $parent_package=null, $parent_service=null)
  suspendService($package, $service, $parent_package=null, $parent_service=null)
  unsuspendService($package, $service, $parent_package=null, $parent_service=null)
  renewService($package, $service, $parent_package=null, $parent_service=null)
  changeServicePackage($package_from, $package_to, $service, $parent_package=null, $parent_service=null)

Validation hooks (called before add/edit to validate input, set Input errors on failure):
  validateService($package, array $vars=null) — validate before addService
  validateServiceEdit($service, array $vars=null) — validate before editService

Module row management (servers/connections):
  addModuleRow(array &$vars) — return meta fields array
  editModuleRow($module_row, array &$vars) — return meta fields array
  deleteModuleRow($module_row)
  manageModule($module, array &$vars) — return HTML for module settings page
  manageAddRow(array &$vars) — return HTML for add server form
  manageEditRow($module_row, array &$vars) — return HTML for edit server form

Dynamic form fields (return InputFields objects):
  getPackageFields($vars=null) — fields shown when creating/editing a package
  getAdminAddFields($package, $vars=null) — fields for admin adding a service
  getClientAddFields($package, $vars=null) — fields for client ordering a service
  getAdminEditFields($package, $vars=null) — fields for admin editing a service

Service info & tabs:
  getAdminServiceInfo($service, $package) — HTML shown on admin service page
  getClientServiceInfo($service, $package) — HTML shown on client service page
  getAdminServiceTabs($service) — return array of method => title pairs (e.g. ['tabName' => 'Tab Label'])
  getClientServiceTabs($service) — return array of method => title pairs

Accessing module data within methods:
  $this->getModule() — get the module object
  $this->getModuleRow() — get the current module row (server)
  $module_row->meta — stdClass of module row meta fields

IMPORTANT NOTES:
- Always scope queries by company_id using Configure::get('Blesta.company_id')
- Use Language::_() for all user-facing strings, never hardcode text
- Plugin and module views use .pdt extension (not .php)
- Access $this->post for POST data, $this->get for URL segments (array, 0-indexed)
- Use $this->parent->setMessage('error', $errors) or ('message', $success) for flash messages
- For AJAX responses: echo json_encode($data); return false;

INSTRUCTIONS;

        return $instructions;
    }

    /**
     * Format a model schema into a compact summary for API context
     *
     * Includes table name, presets (methods), relationships, and virtual fields.
     *
     * @param string $modelName The model name (e.g. 'Clients')
     * @return string|null Formatted model summary, or null if schema not found
     */
    private function formatModelSummary($modelName)
    {
        $schema = $this->schemaLoader->load($modelName);
        if (!$schema) {
            return null;
        }

        $lines = ['## ' . $modelName . ' (table: ' . ($schema['table'] ?? '?') . ')'];

        // Virtual fields
        $virtual = $schema['virtual'] ?? [];
        if (!empty($virtual)) {
            $vNames = array_keys($virtual);
            $lines[] = '  Virtual fields: ' . implode(', ', $vNames);
        }

        // Relationships
        $relationships = $schema['relationships'] ?? [];
        if (!empty($relationships)) {
            $relParts = [];
            foreach ($relationships as $key => $rel) {
                $type = $rel['type'] ?? '?';
                $target = $rel['model'] ?? '?';
                $included = !empty($rel['default_included']) ? ' [included]' : '';
                $merged = !empty($rel['merge']) ? ' [merged]' : '';
                $relParts[] = '    ' . $key . ': ' . $type . ' -> ' . $target . $included . $merged;
            }
            $lines[] = '  Relationships:';
            $lines = array_merge($lines, $relParts);
        }

        // Presets (methods)
        $presets = $schema['presets'] ?? [];
        if (!empty($presets)) {
            foreach ($presets as $method => $preset) {
                $parts = [$method . '()'];

                $virt = $preset['virtual'] ?? [];
                if (!empty($virt)) {
                    $parts[] = 'virtual: ' . implode(', ', $virt);
                }

                $rels = $preset['relationships'] ?? [];
                if (!empty($rels)) {
                    $parts[] = 'includes: ' . implode(', ', $rels);
                }

                $lines[] = '  ' . implode(' | ', $parts);
            }
        }

        // Key stored fields (compact: name + type only)
        $fields = $schema['fields'] ?? [];
        if (!empty($fields)) {
            $fieldParts = [];
            foreach ($fields as $name => $info) {
                $type = $info['type'] ?? '?';
                $fieldParts[] = $name . ' (' . $type . ')';
            }
            $lines[] = '  Fields: ' . implode(', ', $fieldParts);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Load a database schema JSON file directly
     *
     * @param string $tableName The table name
     * @return array|null The parsed schema, or null if not found
     */
    private function loadDatabaseSchema($tableName)
    {
        $path = dirname(__DIR__) . '/Schemas/DatabaseSchemas/' . $tableName . '.json';

        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        return json_decode($json, true);
    }

    /**
     * Format a table schema into a compact text representation
     *
     * @param string $tableName The table name
     * @param string $description Brief description of the table
     * @param array $schema The parsed JSON schema
     * @return string Compact schema text
     */
    private function formatTableSchema($tableName, $description, array $schema)
    {
        $lines = ['## ' . $tableName . ' — ' . $description];

        foreach ($schema['columns'] ?? [] as $col) {
            $name = $col['name'] ?? '';
            $type = $col['type'] ?? '';
            $parts = [$name, $type];

            if (!empty($col['key']) && $col['key'] === 'PRI') {
                $parts[] = 'PK';
            }
            if (!empty($col['extra']) && $col['extra'] === 'auto_increment') {
                $parts[] = 'AUTO';
            }
            if (isset($col['nullable']) && $col['nullable']) {
                $parts[] = 'NULL';
            }
            if (isset($col['default']) && $col['default'] !== null) {
                $parts[] = 'default=' . $col['default'];
            }

            $lines[] = '  ' . implode(' | ', $parts);
        }

        return implode("\n", $lines) . "\n";
    }
}
