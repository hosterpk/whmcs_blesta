<?php

/**
 * Actions
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2020, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Actions extends AppModel
{
    /**
     * @var array Mapping of deprecated pre-v5 `action` values to their equivalent `location` values
     */
    private $action_to_location_map = [
        'nav_secondary_staff' => 'nav_staff','nav_primary_staff' => 'nav_staff',
        'nav_secondary_client' => 'nav_client', 'nav_primary_client' => 'nav_client',
    ];

    /**
     * @var array Mapping of `location` values to their deprecated pre-v5 `action` equivalents for backward compatibility
     */
    private $location_to_action_map = [
        'nav_staff' => 'nav_primary_staff',
        'nav_client' => 'nav_primary_client',
        'nav_public' => 'nav_primary_client'
    ];

    /**
     * @var array Valid location identifiers for action placement in the interface
     */
    private $locations = [
        'nav_staff', 'nav_client', 'nav_public',
        'widget_client_home', 'widget_staff_home',
        'widget_staff_client', 'widget_staff_billing',
        'action_staff_client'
    ];

    /**
     * Initialize the Actions model
     *
     * Loads required language files for action management.
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['actions']);
    }

    /**
     * Creates a new action for navigation, widgets, or staff client areas
     *
     * Actions define menu items, widget placements, and other UI elements
     * that can be added by plugins throughout the interface.
     *
     * @param array $vars Action data:
     *  - `location` (string) Location identifier (optional, default: `nav_staff`):
     *      `nav_client`, `nav_staff`, `nav_public`, `widget_client_home`,
     *      `widget_staff_home`, `widget_staff_client`, `widget_staff_billing`, `action_staff_client`
     *  - `url` (string) Full or partial URL of the action
     *  - `name` (string) Language identifier or display text for the action label
     *  - `options` (array) Additional options for the action (optional)
     *  - `plugin_id` (int) Plugin ID associated with this action (optional, default: null)
     *  - `company_id` (int) Company ID to which this action belongs
     *  - `editable` (int) Whether action can be updated via interface: `0` or `1` (optional, default: 1)
     *  - `enabled` (int) Whether action is active in the interface: `0` or `1` (optional, default: 1)
     * @return int The new action ID on success, void on validation error
     */
    public function add(array $vars)
    {
        Loader::loadModels($this, ['Navigation', 'PluginManager']);
        $rules = $this->getRules($vars);

        $this->Input->setRules($rules);

        // Insert the action
        $vars = $this->mapOldFields($vars);
        if ($this->Input->validates($vars)) {
            $fields = ['location', 'url', 'name', 'options', 'plugin_id', 'company_id', 'editable', 'enabled'];
            $this->Record->insert('actions', $vars, $fields);

            return $this->Record->lastInsertId();
        }
    }

    /**
     * Updates an existing action
     *
     * @param int $action_id The action ID to update
     * @param array $vars Action data to update:
     *  - `name` (string) Language identifier or display text for the action label
     *  - `url` (string) Full or partial URL of the action
     *  - `options` (array) Additional options for the action (optional)
     *  - `enabled` (int) Whether action is active: `0` or `1`
     * @return int The action ID on success, void on validation error
     */
    public function edit($action_id, array $vars)
    {
        $rules = $this->getRules($vars, true);

        $this->Input->setRules($rules);

        // Update an action
        $vars = $this->mapOldFields($vars);
        if ($this->Input->validates($vars)) {
            $fields = ['name', 'url', 'options', 'enabled'];
            $this->Record->where('id', '=', $action_id)->update('actions', $vars, $fields);

            return $action_id;
        }
    }

    /**
     * Maps deprecated pre-Blesta v5 field names to their current equivalents
     *
     * Converts `uri` to `url` and `action` to `location` for backward compatibility.
     *
     * @param array $vars Input variables that may contain deprecated field names
     * @return array The input variables with deprecated fields mapped to current names
     */
    public function mapOldFields(array $vars)
    {
        if (isset($vars['uri']) && !isset($vars['url'])) {
            $vars['url'] = $vars['uri'];
        }

        if (isset($vars['action']) && !isset($vars['location'])) {
            $vars['location'] = array_key_exists($vars['action'], $this->action_to_location_map)
                ? $this->action_to_location_map[$vars['action']]
                : $vars['action'];
        }

        return $vars;
    }

    /**
     * Deletes actions and associated navigation items for a plugin
     *
     * **Warning**: This also deletes related navigation_items records.
     *
     * @param int $plugin_id The plugin ID whose actions should be removed
     * @param string|null $url Specific action URL to delete (optional). If null, deletes all actions for the plugin
     * @return void
     */
    public function delete($plugin_id, $url = null)
    {
        // Delete the action
        $this->Record->from('actions')->
            leftJoin('navigation_items', 'navigation_items.action_id', '=', 'actions.id', false)->
            where('actions.plugin_id', '=', $plugin_id);

        if ($url) {
            $this->Record->where('actions.url', '=', $url);
        }

        $this->Record->delete(['actions.*', 'navigation_items.*']);
    }

    /**
     * Retrieves an action by ID
     *
     * @param int $action_id The action ID to fetch
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return stdClass|false Action object with properties if found, false otherwise:
     *  - `id` (int) Action ID
     *  - `location` (string) Location identifier
     *  - `url` (string) Action URL
     *  - `name` (string) Action name (translated if $translate is true)
     *  - `options` (array|null) Unserialized options
     *  - `plugin_id` (int|null) Associated plugin ID
     *  - `company_id` (int) Company ID
     *  - `editable` (int) Whether editable via interface
     *  - `enabled` (int) Whether enabled
     *  - `plugin_dir` (string|null) Plugin directory name
     *  - `uri` (string) Deprecated alias for `url`
     *  - `action` (string) Deprecated alias for `location`
     *  - `nav_items` (array) Associated navigation items
     */
    public function get($action_id, $translate = true)
    {
        $action = $this->getActionRecord(['id' => $action_id])->fetch();
        return $action ? $this->formatAction($action, $translate) : $action;
    }


    /**
     * Retrieves an action by URL and optional location/company filters
     *
     * @param string $url The action URL to search for
     * @param string|null $location Location identifier to filter by (optional):
     *      `nav_client`, `nav_staff`, `nav_public`, `widget_client_home`,
     *      `widget_staff_home`, `widget_staff_client`, `widget_staff_billing`, `action_staff_client`
     * @param int|null $company_id Company ID to filter by (optional, defaults to current company)
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return stdClass|false Action object if found, false otherwise
     * @see Actions::get() For return object structure
     */
    public function getByUrl($url, $location = null, $company_id = null, $translate = true)
    {
        if ($company_id === null) {
            $company_id = Configure::get('Blesta.company_id');
        }
        $action = $this->getActionRecord(['url' => $url, 'location' => $location, 'company_id' => $company_id])->
            fetch();
        return $action ? $this->formatAction($action, $translate) : $action;
    }

    /**
     * Constructs a partial database query for searching actions
     *
     * @param array $filters Filter criteria:
     *  - `id` (int) Specific action ID (optional)
     *  - `url` (string) Action URL (optional)
     *  - `location` (string) Location identifier (optional)
     *  - `plugin_id` (int) Plugin ID (optional)
     *  - `company_id` (int) Company ID (optional)
     *  - `editable` (int) Whether editable: `0` or `1` (optional)
     *  - `enabled` (int) Whether enabled: `0` or `1` (optional)
     * @return Record Partially constructed Record query object with actions and plugins joined
     */
    private function getActionRecord(array $filters)
    {
        $this->Record->select(['actions.*', 'plugins.dir' => 'plugin_dir'])->
            from('actions')->
            leftJoin('plugins', 'plugins.id', '=', 'actions.plugin_id', false);

        $value_filters = ['id', 'url', 'location', 'plugin_id', 'company_id', 'editable', 'enabled'];
        foreach ($value_filters as $filter) {
            if (array_key_exists($filter, $filters)) {
                $this->Record->where('actions.' . $filter, '=', $filters[$filter]);
            }
        }

        return $this->Record;
    }

    /**
     * Retrieves all actions matching the specified filters
     *
     * @param array $filters Filter criteria:
     *  - `location` (string) Location identifier (optional)
     *  - `plugin_id` (int) Plugin ID (optional)
     *  - `company_id` (int) Company ID (optional)
     *  - `enabled` (int) Whether enabled: `0` or `1` (optional)
     *  - `editable` (int) Whether editable: `0` or `1` (optional)
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return array Array of stdClass action objects
     * @see Actions::get() For action object structure
     */
    public function getAll(array $filters = [], $translate = true)
    {
        return $this->formatActions(
            $this->getActionRecord(
                $this->mapOldFields($filters)
            )->fetchAll(),
            $translate
        );
    }



    /**
     * Retrieves a paginated list of actions matching the specified filters
     *
     * @param array $filters Filter criteria:
     *  - `location` (string) Location identifier (optional)
     *  - `plugin_id` (int) Plugin ID (optional)
     *  - `company_id` (int) Company ID (optional)
     *  - `enabled` (int) Whether enabled: `0` or `1` (optional)
     *  - `editable` (int) Whether editable: `0` or `1` (optional)
     * @param int $page Page number to return (optional, default: 1)
     * @param array $order_by Sort conditions as array (e.g., `['id' => 'DESC']`) (optional, default: `['id' => 'DESC']`)
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return array Array of stdClass action objects for the specified page
     * @see Actions::get() For action object structure
     * @see Actions::getListCount() For total count
     */
    public function getList(array $filters = [], $page = 1, array $order_by = ['id' => 'DESC'], $translate = true)
    {
        return $this->formatActions(
            $this->getActionRecord($this->mapOldFields($filters))->
                order($order_by)->
                limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())->
                fetchAll(),
            $translate
        );
    }


    /**
     * Returns the total number of actions matching the filters
     *
     * Used for constructing pagination with getList().
     *
     * @param array $filters Filter criteria (same as getList()):
     *  - `location` (string) Location identifier (optional)
     *  - `plugin_id` (int) Plugin ID (optional)
     *  - `company_id` (int) Company ID (optional)
     *  - `editable` (int) Whether editable: `0` or `1` (optional)
     *  - `enabled` (int) Whether enabled: `0` or `1` (optional)
     * @return int Total count of matching actions
     * @see Actions::getList()
     */
    public function getListCount($filters)
    {
        // Return the number of results
        return $this->getActionRecord($this->mapOldFields($filters))->numResults();
    }

    /**
     * Formats multiple action objects
     *
     * Unserializes options, translates names, and adds backward-compatible fields.
     *
     * @param array $actions Array of stdClass action objects to format
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return array The formatted action objects
     */
    private function formatActions(array $actions, $translate = true)
    {
        foreach ($actions as $index => $action) {
            $actions[$index] = $this->formatAction($action, $translate);
        }

        return $actions;
    }


    /**
     * Formats a single action object
     *
     * Performs the following transformations:
     * - Unserializes the `options` field
     * - Translates the action name if requested
     * - Adds deprecated `uri` field (alias for `url`)
     * - Adds deprecated `action` field (alias for `location`)
     * - Loads associated navigation items
     *
     * @param stdClass $action The action object to format
     * @param bool $translate Whether to translate language definitions (optional, default: true)
     * @return stdClass The formatted action object
     */
    private function formatAction(stdClass $action, $translate = true)
    {
        Loader::loadModels($this, ['Navigation']);

        // Unserialize the options
        if (property_exists($action, 'options')) {
            $action->options = ($action->options === null ? null : \Blesta\Core\Util\Common\Classes\Model::safeUnserialize($action->options));
        }

        // Translate the action's names
        if ($translate) {
            $action = $this->translateAction($action);
        }

        $action->uri = $action->url;
        $action->action = isset($this->location_to_action_map[$action->location])
            ? $this->location_to_action_map[$action->location]
            : $action->location;

        $action->nav_items = $this->Navigation->getAll(['action_id' => $action->id]);

        return $action;
    }

    /**
     * Translates language identifiers in an action object
     *
     * Loads the plugin's language file if needed and translates the action name
     * from a language identifier (e.g., `PluginName.action_name`) to the translated text.
     *
     * @param stdClass $action The action object whose name should be translated
     * @return stdClass The action object with translated name
     */
    private function translateAction(stdClass $action)
    {
        if (!isset($this->PluginManager)) {
            Loader::loadModels($this, ['PluginManager']);
        }

        if (!isset($this->loaded_plugins)) {
            $this->loaded_plugins = [];
        }

        // Load the language file for the plugin associated with this navigation item
        if (isset($action->plugin_id)
            && !in_array($action->plugin_id, $this->loaded_plugins)
            && ($plugin = $this->PluginManager->get($action->plugin_id))
        ) {
            Language::loadLang([$plugin->dir . '_plugin'], null, PLUGINDIR . $plugin->dir . DS . 'language' . DS);

            $this->loaded_plugins[] = $action->plugin_id;
        }

        // Translate the action name
        if (property_exists($action, 'name')) {
            $language = Language::_($action->name, true);
            $action->name = empty($language) ? $action->name : $language;
        }

        return $action;
    }

    /**
     * Retrieves all valid action location identifiers with their display names
     *
     * @return array Associative array of location identifiers => translated display names
     */
    public function getLocations()
    {
        $descriptions = [];
        foreach ($this->locations as $location) {
            $descriptions[$location]  = $this->_('Actions.getLocations.' . $location);
        }
        return $descriptions;
    }

    /**
     * Retrieves all action locations with detailed descriptions
     *
     * Provides more detailed explanatory text than getLocations().
     *
     * @return array Associative array of location identifiers => translated descriptions
     */
    public function getLocationDescriptions()
    {
        $descriptions = [];
        foreach ($this->locations as $location) {
            $descriptions[$location]  = $this->_('Actions.getLocationDescriptions.' . $location);
        }
        return $descriptions;
    }

    /**
     * Returns validation rules for adding or editing an action
     *
     * @param array $vars Input data to validate (used for context in validation rules)
     * @param bool $edit Whether validating for edit operation (optional, default: false).
     *                   When true, removes validation for immutable fields (location, plugin_id, company_id, editable)
     * @return array Validation rules array for Input::setRules()
     */
    private function getRules(array $vars = [], $edit = false)
    {
        $rules = [
            'location' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', array_keys($this->getLocations())],
                    'message' => $this->_('Actions.!error.location.valid')
                ],
                'unique' => [
                    'rule' => [
                        function ($location, $company_id, $url) {
                            $total = $this->Record->select()
                                ->from('actions')
                                ->where('company_id', '=', $company_id)
                                ->where('location', '=', $location)
                                ->where('url', '=', $url)
                                ->numResults();

                            return ($total === 0);
                        },
                        ['_linked' => 'company_id'],
                        ['_linked' => 'url']
                    ],
                    'message' => $this->_('Actions.!error.location.unique')
                ]
            ],
            'url' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('Actions.!error.url.empty')
                ]
            ],
            'name' => [
                'action_empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('Actions.!error.name.action_empty')
                ]
            ],
            'options' => [
                'empty' => [
                    'if_set' => true,
                    'rule' => true,
                    'post_format' => 'serialize'
                ]
            ],
            'plugin_id' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'id', 'plugins'],
                    'message' => $this->_('Actions.!error.plugin_id.valid')
                ]
            ],
            'company_id' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('Actions.!error.company_id.valid')
                ]
            ],
            'editable' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => $this->_('Actions.!error.editable.valid')
                ]
            ],
            'enabled' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => $this->_('Actions.!error.enabled.valid')
                ]
            ],
        ];

        if ($edit) {
            unset($rules['location']);
            unset($rules['plugin_id']);
            unset($rules['company_id']);
            unset($rules['editable']);
        }

        return $rules;
    }
}
