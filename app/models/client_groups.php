<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Blesta\Core\Cache\CacheFactory;
use Language;
use Loader;
use stdClass;

/**
 * Client group management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ClientGroups extends AppModel
{
    /**
     * @var array In-memory cache of client group settings keyed by "client_group_id.key"
     */
    private static $settingsCache = [];

    /**
     * @var array Tracks which client group IDs have had all settings bulk-loaded into cache
     */
    private static $settingsCacheLoaded = [];

    /**
     * Clears in-memory and Redis caches for client group settings
     */
    public static function clearSettingsCache()
    {
        self::$settingsCache = [];
        self::$settingsCacheLoaded = [];

        CacheFactory::get()->deleteGroup('settings:client_group');
    }


    public $setting_keys = [
        'inv_days_before_renewal', 'autodebit_days_before_due',
        'suspend_services_days_after_due', 'autodebit_attempts',
        'service_renewal_attempts', 'first_renewal_attempt_threshold', 'first_renewal_attempt_spacing',
        'second_renewal_attempt_threshold', 'second_renewal_attempt_spacing',
        'service_provisioning_attempts', 'first_provisioning_attempt_threshold', 'first_provisioning_attempt_spacing',
        'second_provisioning_attempt_threshold', 'second_provisioning_attempt_spacing',
        'service_suspension_attempts', 'first_suspension_attempt_threshold', 'first_suspension_attempt_spacing',
        'second_suspension_attempt_threshold', 'second_suspension_attempt_spacing',
        'service_unsuspension_attempts', 'first_unsuspension_attempt_threshold', 'first_unsuspension_attempt_spacing',
        'second_unsuspension_attempt_threshold', 'second_unsuspension_attempt_spacing',
        'service_cancelation_attempts', 'first_cancelation_attempt_threshold', 'first_cancelation_attempt_spacing',
        'second_cancelation_attempt_threshold', 'second_cancelation_attempt_spacing',
        'client_set_invoice', 'inv_suspended_services',
        'clients_cancel_services', 'clients_cancel_options', 'clients_renew_services', 'synchronize_addons',
        'client_create_addons', 'auto_apply_credits',
        'auto_paid_pending_services', 'client_change_service_term',
        'client_change_service_package', 'delivery_methods', 'notice1',
        'notice2', 'notice3', 'notice_pending_autodebit',
        'send_payment_notices', 'send_cancellation_notice', 'autodebit', 'show_client_tax_id',
        'client_prorate_credits', 'process_paid_service_changes', 'late_fee_total_amount', 'late_fees',
        'cancel_service_changes_days', 'apply_inv_late_fees',
        'inv_group_services', 'inv_append_descriptions', 'inv_lines_verbose_option_dates', 'void_invoice_canceled_service',
        'void_inv_canceled_service_days', 'quotation_valid_days', 'quotation_dead_days', 'quotation_deposit_percentage',
        'prevent_unverified_payments', 'unique_contact_emails', 'force_email_usernames', 'email_verification',
        'required_contact_fields', 'shown_contact_fields', 'read_only_contact_fields', 'clients_increment',
        'clients_start', 'clients_format', 'enable_gateway_restrictions', 'allowed_gateways',
        'requeue_invoice_delivery_on_closed', 'payment_credit_enabled', 'payment_credit_limits'
    ];

    /**
     * Initialize ClientGroups
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['client_groups']);
    }


    /**
     * Add a client group using the supplied data
     *
     * @param array $vars A single dimensional array of keys including:
     *
     *  - name The name of this group
     *  - description A description of this group (optional)
     *  - company_id The company ID this group belongs to
     *   color The HTML color that represents this group (optional)
     * @return int The client group ID created, or void on error
     */
    public function add(array $vars)
    {
        // Trigger the ClientGroups.addBefore event
        $event = $this->executeAndParseEvent('ClientGroups.addBefore', ['vars' => $vars]);
        if ($event instanceof \Blesta\Core\Util\Events\Common\EventInterface && ($errors = $event->getErrors())) {
            $this->Input->setErrors($errors);
            return;
        }
        extract($event);

        $this->Input->setRules($this->getRules());

        if ($this->Input->validates($vars)) {
            // Add a client group
            $fields = ['name', 'description', 'company_id', 'color'];
            $this->Record->insert('client_groups', $vars, $fields);

            $client_group_id = $this->Record->lastInsertId();

            // Set Client Group Settings
            if (isset($vars['use_company_settings'])) {
                if ($vars['use_company_settings'] === 'true') {
                    // Remove Client Group Settings
                    $this->unsetSettings($client_group_id);
                } else {
                    $this->setSettings($client_group_id, $vars, $this->setting_keys);
                }
            }

            // Trigger the ClientGroups.addAfter event
            $this->executeAndParseEvent(
                'ClientGroups.addAfter',
                ['client_group_id' => $client_group_id, 'vars' => $vars]
            );

            return $client_group_id;
        }
    }

    /**
     * Edit a client group using the supplied data
     *
     * @param int $client_group_id The ID of the group to be updated
     * @param array $vars A single dimensional array of keys including:
     *
     *  - name The name of this group
     *  - description A description of this group (optional)
     *  - company_id The company ID this group belongs to
     *  - color The HTML color that represents this group (optional)
     */
    public function edit($client_group_id, array $vars)
    {
        // Trigger the ClientGroups.editBefore event
        $event = $this->executeAndParseEvent(
            'ClientGroups.editBefore',
            ['client_group_id' => $client_group_id, 'vars' => $vars]
        );
        if ($event instanceof \Blesta\Core\Util\Events\Common\EventInterface && ($errors = $event->getErrors())) {
            $this->Input->setErrors($errors);
            return;
        }
        extract($event);

        $rules = $this->getRules();
        $rules['group_id'] = [
            'exists' => [
                'rule' => [[$this, 'validateExists'], 'id', 'client_groups'],
                'message' => $this->_('ClientGroups.!error.group_id.exists')
            ]
        ];

        $this->Input->setRules($rules);

        $vars['group_id'] = $client_group_id;

        if ($this->Input->validates($vars)) {
            // Get the client group state prior to update
            $client_group = $this->get($client_group_id);

            // Update a client group
            $fields = ['name', 'description', 'company_id', 'color'];
            $this->Record->where('id', '=', $client_group_id)
                ->update('client_groups', $vars, $fields);


            // Set Client Group Settings
            if (isset($vars['use_company_settings'])) {
                if ($vars['use_company_settings'] === 'true') {
                    // Remove Client Group Settings
                    $this->unsetSettings($client_group_id);
                } else {
                    $this->setSettings($client_group_id, $vars, $this->setting_keys);
                }
            }

            // Trigger the ClientGroups.editAfter event
            $this->executeAndParseEvent(
                'ClientGroups.editAfter',
                ['client_group_id' => $client_group_id, 'vars' => $vars, 'old_client_group' => $client_group]
            );
        }
    }

    /**
     * Delete a client group and all associated client group settings
     *
     * @param int $client_group_id The ID for this client group
     */
    public function delete($client_group_id)
    {
        $client_group_id = (int) $client_group_id;

        // Trigger the ClientGroups.deleteBefore event
        $event = $this->executeAndParseEvent('ClientGroups.deleteBefore', ['client_group_id' => $client_group_id]);
        if ($event instanceof \Blesta\Core\Util\Events\Common\EventInterface && ($errors = $event->getErrors())) {
            $this->Input->setErrors($errors);
            return;
        }
        extract($event);

        $client_group = $this->get($client_group_id);
        $default_group = $this->getDefault($client_group->company_id);

        // If default client group, we cannot delete it
        if (!$default_group || $client_group_id == $default_group->id) {
            return false;
        }

        // Update all clients with this client group, set to the default group
        $this->Record->where('client_group_id', '=', $client_group_id)
            ->update('clients', ['client_group_id' => $default_group->id]);

        // Finally, delete the client group, and settings specific to this group
        $this->Record->from('client_group_settings')
            ->where('client_group_id', '=', $client_group_id)
            ->delete();

        $this->Record->from('client_groups')
            ->where('id', '=', $client_group_id)
            ->delete();

        // Invalidate cached settings for the deleted group and descendant caches
        self::clearSettingsCache();
        Clients::clearSettingsCache();

        // Trigger the ClientGroups.deleteAfter event
        $this->executeAndParseEvent(
            'ClientGroups.deleteAfter',
            ['client_group_id' => $client_group_id, 'old_client_group' => $client_group]
        );
    }

    /**
     * Finds the default client group for the given company.
     *
     * @param int $company_id
     * @return mixed stdClass object representing the default client group, false if no such group exists
     */
    public function getDefault($company_id)
    {
        return $this->getClientGroups($company_id)
            ->order(['client_groups.id' => 'ASC'])
            ->limit(1)
            ->fetch();
    }

    /**
     * Returns the given client group
     *
     * @param int $client_group_id The ID of the client group to fetch
     * @return mixed A stdClass object representing the client group, false if it does not exist
     */
    public function get($client_group_id)
    {
        $client_group = $this->Record->select(['id', 'company_id', 'name', 'description', 'color'])
            ->from('client_groups')
            ->where('id', '=', $client_group_id)
            ->fetch();

        // Trigger the ClientGroups.get event
        $event = $this->executeAndParseEvent('ClientGroups.get', [
            'client_group' => $client_group
        ]);
        if ($event instanceof \Blesta\Core\Util\Events\Common\EventInterface && ($errors = $event->getErrors())) {
            $this->Input->setErrors($errors);
            return false;
        }
        extract($event);

        return $client_group;
    }

    /**
     * Fetches a list of all client groups
     *
     * @param int $company_id The company ID
     * @param int $page The page to return results for (optional, default 1)
     * @param string $order_by The sort and order conditions (e.g. array('sort_field'=>"ASC"), optional)
     * @return mixed An array of objects or false if no results.
     */
    public function getList($company_id, $page = 1, array $order_by = ['name' => 'ASC'])
    {
        $this->Record = $this->getClientGroups($company_id);

        // Return the results
        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Return the total number of client groups returned from ClientGroups::getList(),
     * useful in constructing pagination for the getList() method.
     *
     * @param int $company_id The ID of the company whose client group count to fetch
     * @return int The total number of clients
     * @see ClientGroups::getList()
     */
    public function getListCount($company_id)
    {
        $this->Record = $this->getClientGroups($company_id);

        // Return the number of results
        return $this->Record->numResults();
    }

    /**
     * Fetches all custom client groups by company
     *
     * @param int $company_id The company ID to fetch client groups for
     * @return mixed An array of stdClass objects representing all client groups for the given company
     */
    public function getAll($company_id)
    {
        $this->Record = $this->getClientGroups($company_id);

        return $this->Record->fetchAll();
    }

    /**
     * Partially constructs the query required by ClientGroups::getList(),
     * ClientGroups::getListCount(), and ClientGroups::getAll()
     *
     * @param int $company_id The ID of the company whose client groups to fetch
     * @return Record The partially constructed query Record object
     */
    private function getClientGroups($company_id)
    {
        $fields = ['client_groups.id', 'client_groups.company_id', 'client_groups.name',
            'client_groups.description', 'client_groups.color', 'COUNT(clients.id)' => 'num_clients'
        ];

        // Find all client groups and the number of clients that belong to them
        $this->Record->select($fields)
            ->from('client_groups')
            ->leftJoin('clients', 'clients.client_group_id', '=', 'client_groups.id', false)
            ->where('client_groups.company_id', '=', $company_id)
            ->group('client_groups.id');

        return $this->Record;
    }

    /**
     * Fetch all settings that may apply to this client group. Settings are inherited
     * in the order of client_group_settings -> company_settings -> settings
     * where "->" represents the left item inheriting (and overwriting in the
     * case of duplicates) values found in the right item.
     *
     * @param int $client_group_id The client group ID to retrieve settings for
     * @param bool $ignore_inheritence True to fetch only client group settings without inheriting from
     *  company or system settings (default false)
     * @return mixed An array of objects containg key/values for the settings, false if no records found
     */
    public function getSettings($client_group_id, $ignore_inheritence = false)
    {

        // Client Group Settings
        $sql1 = $this->Record->select(['key', 'value', 'encrypted'])
            ->select(['?' => 'level'], false)
            ->appendValues(['client_group'])
            ->from('client_group_settings')
            ->where('client_group_id', '=', $client_group_id)
            ->get();
        $values = $this->Record->values;
        $this->Record->reset();
        $this->Record->values = $values;

        // Return only client group settings when ignoring company and system setting inheritence
        if ($ignore_inheritence) {
            $settings = $this->Record->select()
                ->from([$sql1 => 'temp'])
                ->group('temp.key')
                ->fetchAll();

            // Decrypt values where necessary
            for ($i = 0, $total = count($settings); $i < $total; $i++) {
                if ($settings[$i]->encrypted) {
                    $settings[$i]->value = $this->systemDecrypt($settings[$i]->value);
                }
            }
            return $settings;
        }

        // Company Settings
        $sql2 = $this->Record->select(['key', 'value', 'encrypted'])
            ->select(['?' => 'level'], false)
            ->appendValues(['company'])
            ->from('client_groups')
            ->innerJoin(
                'company_settings',
                'company_settings.company_id',
                '=',
                'client_groups.company_id',
                false
            )
            ->where('client_groups.id', '=', $client_group_id)
            ->where('company_settings.inherit', '=', '1')
            ->get();
        $values = $this->Record->values;
        $this->Record->reset();
        $this->Record->values = $values;

        // System settings
        $sql3 = $this->Record->select(['key', 'value', 'encrypted'])
            ->select(['?' => 'level'], false)
            ->appendValues(['system'])
            ->from('settings')
            ->where('settings.inherit', '=', '1')
            ->get();
        $values = $this->Record->values;
        $this->Record->reset();
        $this->Record->values = $values;

        $settings = $this->Record->select()
            ->from(
                ['((' . $sql1 . ') UNION (' . $sql2 . ') UNION (' . $sql3 . '))' => 'temp']
            )
            ->group('temp.key')
            ->fetchAll();

        // Decrypt values where necessary
        for ($i = 0, $total = count($settings); $i < $total; $i++) {
            if ($settings[$i]->encrypted) {
                $settings[$i]->value = $this->systemDecrypt($settings[$i]->value);
            }
        }
        return $settings;
    }

    /**
     * Fetch a specific setting that may apply to this client group. Settings are inherited
     * in the order of client_group_settings -> company_settings -> settings
     * where "->" represents the left item inheriting (and overwriting in the
     * case of duplicates) values found in the right item.
     *
     * @param int $client_group_id The client group ID to retrieve settings for
     * @param string $key The key name of the setting to fetch
     * @return mixed A stdClass object containg key/values for the settings, false if no records found
     */
    public function getSetting($client_group_id, $key)
    {
        // Tier 1: in-memory cache
        if (isset(self::$settingsCacheLoaded[$client_group_id])) {
            return self::$settingsCache[$client_group_id . '.' . $key] ?? false;
        }

        // Tier 2: Redis cache
        $cache = CacheFactory::get();
        $cached = $cache->read((string) $client_group_id, 'settings:client_group');
        if ($cached !== false) {
            foreach ($cached as $setting) {
                self::$settingsCache[$client_group_id . '.' . $setting->key] = $setting;
            }
            self::$settingsCacheLoaded[$client_group_id] = true;

            return self::$settingsCache[$client_group_id . '.' . $key] ?? false;
        }

        // Tier 3: database
        $allSettings = $this->getSettings($client_group_id);
        if (is_array($allSettings)) {
            foreach ($allSettings as $setting) {
                self::$settingsCache[$client_group_id . '.' . $setting->key] = $setting;
            }
            $cache->write((string) $client_group_id, $allSettings, 0, 'settings:client_group');
        }
        self::$settingsCacheLoaded[$client_group_id] = true;

        return self::$settingsCache[$client_group_id . '.' . $key] ?? false;
    }

    /**
     * Add a client group setting, if duplicate then update the value
     *
     * @param int $client_group_id The ID for the specified client group
     * @param string $key The key for this client group setting
     * @param string $value The value for this client group setting
     * @param mixed $encrypted True to encrypt $value, false to store
     *  unencrypted, null to encrypt if currently set to encrypt
     */
    public function setSetting($client_group_id, $key, $value, $encrypted = null)
    {
        $fields = ['key' => $key, 'client_group_id' => $client_group_id, 'value' => $value];

        // If encryption is mentioned set the appropriate value and encrypt if necessary
        if ($encrypted !== null) {
            $fields['encrypted'] = (int) $encrypted;
            if ($encrypted) {
                $fields['value'] = $this->systemEncrypt($fields['value']);
            }
        } else {
            // Check if the value is currently encrypted and encrypt if necessary
            $setting = $this->getSetting($client_group_id, $key);
            if ($setting && $setting->encrypted) {
                $fields['encrypted'] = 1;
                $fields['value'] = $this->systemEncrypt($fields['value']);
            }
        }

        $this->Record->duplicate('value', '=', $fields['value'])
            ->insert('client_group_settings', $fields);

        // Invalidate cached setting and descendant caches (client group settings are inherited by clients)
        self::clearSettingsCache();
        Clients::clearSettingsCache();
    }

    /**
     * Delete a client group setting
     *
     * @param int $client_group_id The ID for the specified client group
     * @param string $key The key for this client group setting
     */
    public function unsetSetting($client_group_id, $key)
    {
        $this->Record->from('client_group_settings')
            ->where('key', '=', $key)
            ->where('client_group_id', '=', $client_group_id)
            ->delete();

        // Invalidate cached setting and descendant caches (client group settings are inherited by clients)
        self::clearSettingsCache();
        Clients::clearSettingsCache();
    }

    /**
     * Deletes all client group settings
     *
     * @param int $client_group_id The ID for the specified client group
     */
    public function unsetSettings($client_group_id)
    {
        $this->Record->from('client_group_settings')
            ->where('client_group_id', '=', $client_group_id)
            ->delete();

        // Invalidate cached settings and descendant caches
        self::clearSettingsCache();
        Clients::clearSettingsCache();
    }

    /**
     * Add multiple client group settings, if duplicate then update the value
     *
     * @param int $client_group_id The ID for the specified client group
     * @param array $vars A single dimensional array of key/value pairs of settings
     * @param array $value_keys An array of key values to accept as valid fields
     */
    public function setSettings($client_group_id, array $vars, ?array $value_keys = null)
    {
        if (!empty($value_keys)) {
            $vars = array_intersect_key($vars, array_flip($value_keys));
        }
        foreach ($vars as $key => $value) {
            $this->setSetting($client_group_id, $key, $value);
        }
    }

    /**
     * Returns the rule set for adding/editing groups
     *
     * @return array A list of client group rules
     */
    private function getRules()
    {
        Loader::loadHelpers($this, ['SettingsProcessor']);
        $that = $this;
        $rules = [
            'name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('ClientGroups.!error.name.empty')
                ]
            ],
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('ClientGroups.!error.company_id.exists')
                ]
            ],
            'color' => [
                'length' => [
                    'if_set' => true,
                    'rule' => ['maxLength', 16],
                    'message' => $this->_('ClientGroups.!error.color.length')
                ]
            ],
            'clients_format' => [
                'format' => [
                    'if_set' => true,
                    'rule' => function ($format) {
                        return str_contains($format, '{num}');
                    },
                    'message' => $this->_('ClientGroups.!error.clients_format.format')
                ]
            ],
            'payment_credit_limits' => [
                'format' => [
                    'if_set' => true,
                    'rule' => function ($credit_limits) use ($that) {
                        $errors = $this->SettingsProcessor->validateCurrencyBasedSettings($credit_limits);
                        if (!empty($errors)) {
                            $error_messages = [];
                            foreach ($errors as $currency => $error_keys) {
                                foreach ($error_keys as $error_key) {
                                    $error_messages['payment_credit_limits'][$error_key . '_' . $currency] =
                                        Language::_('ClientGroups.!error.payment_credit_limits.' . $error_key, true, $currency);
                                }
                            }

                            $that->Input->setErrors($error_messages);
                        }

                        return empty($errors);
                    },
                    'message' => '', // The message is set from within the validation method
                    'post_format' => [[$this->SettingsProcessor, 'processCurrencyBasedSettings']]
                ]
            ]
        ];
        return $rules;
    }

    /**
     * Adds a client group notification
     *
     * @param array $vars An array of client group notification information including:
     *
     *  - client_group_id The ID of the client group this notification will be added to
     *  - action The notification action
     */
    public function addNotification(array $vars)
    {
        $this->Input->setRules($this->getNotificationRules($vars));

        if ($this->Input->validates($vars)) {
            // Add a new notification, but allow duplicates to be added without error
            $this->Record->duplicate('action', '=', $vars['action'])->
                insert('client_group_notifications', $vars, ['client_group_id', 'action']);
        }
    }

    /**
     * Deletes the given client group notification
     *
     * @param int $client_group_id The ID of the client group the notification belongs to
     * @param string $action The notification action to remove (optional, default null to delete all notifications)
     */
    public function deleteNotification($client_group_id, $action = null)
    {
        $this->deleteGroupNotifications($client_group_id, $action);
    }

    /**
     * Deletes the client group notifications
     *
     * @param int $client_group_id The ID of the client group
     * @param string $action The notification action (optional)
     */
    private function deleteGroupNotifications($client_group_id, $action = null)
    {
        // Delete the notification from all clients
        $this->Record->from('client_notifications')->
            where('client_group_id', '=', $client_group_id);

        if ($action) {
            $this->Record->where('action', '=', $action);
        }

        $this->Record->delete();

        // Delete the client group notification
        $this->Record->from('client_group_notifications')->
            where('client_group_id', '=', $client_group_id);

        if ($action) {
            $this->Record->where('action', '=', $action);
        }

        $this->Record->delete();
    }

    /**
     * Fetches all client group notifications
     *
     * @param int $client_group_id The ID of the client group
     * @return array A list of all client group notifications
     */
    public function getNotifications($client_group_id)
    {
        try {
            return $this->Record->select([
                'notification_actions.id', 'client_group_notifications.*'
            ])->
                from('client_group_notifications')->
                innerJoin('client_groups', 'client_groups.id', '=', 'client_group_notifications.client_group_id', false)->
                innerJoin('notification_actions', 'notification_actions.action', '=', 'client_group_notifications.action', false)->
                where('client_group_notifications.client_group_id', '=', $client_group_id)->
                where('notification_actions.target', '=', 'client')->
                where('notification_actions.company_id', '=', 'client_groups.company_id', false)->
                fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Fetches the rules for adding/editing a client group notification
     *
     * @param array $vars A list of input vars
     * @return array The client group notification rules
     */
    private function getNotificationRules(array $vars)
    {
        $rules = [
            'client_group_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'client_groups'],
                    'message' => $this->_('ClientGroups.!error.client_group_id.exists')
                ]
            ],
            'action' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'action', 'notification_actions'],
                    'message' => $this->_('ClientGroups.!error.action.exists', ($vars['action'] ?? null))
                ]
            ]
        ];

        return $rules;
    }
}
