<?php
/**
 * Upgrades to version 5.13.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_13_0B1 extends UpgradeUtil
{
    /**
     * @var array An array of all tasks completed
     */
    private $tasks = [];

    /**
     * Setup
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Record']);
    }

    /**
     * Returns a numerically indexed array of tasks to execute for the upgrade process
     *
     * @return array A numerically indexed array of tasks to execute for the upgrade process
     */
    public function tasks()
    {
        return [
            'updateBlacklistTable',
            'addStepUpAuthenticationConfig',
            'addStatesFormatColumn',
            'updateCzechRepublicStates',
            'setStatesFormats',
            'addUserAvatarsTable',
            'addRequeueInvoiceDeliverySetting',
            'addDefaultMerchantGatewaySettings',
            'addCouponPackageOptions',
            'addPackageOptionsPricingColumns',
            'addCreditHandlingSettings',
            'addCreditHandlingPermissions',
            'addLowBalanceNotificationsCron',
            'addLowBalanceNotificationEmail',
            'addNewControllerPermissions',
        ];
    }

    /**
     * Processes the given task
     *
     * @param string $task The task to process
     */
    public function process($task)
    {
        $tasks = $this->tasks();

        // Ensure task exists
        if (!in_array($task, $tasks)) {
            return;
        }

        $this->tasks[] = $task;
        $this->{$task}();
    }

    /**
     * Rolls back all tasks completed for the upgrade process
     */
    public function rollback()
    {
        // Undo all tasks
        while (($task = array_pop($this->tasks))) {
            $this->{$task}(true);
        }
    }

    /**
     * Adds a new "block_outgoing" column to the "blacklist" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateBlacklistTable($undo = false)
    {
        if ($undo) {
            $this->Record->query(
                "ALTER TABLE `blacklist` DROP COLUMN block_outgoing;"
            );
        } else {
            $this->Record->query(
                "ALTER TABLE `blacklist` ADD COLUMN block_outgoing TINYINT(1) NOT NULL DEFAULT 0 AFTER type;"
            );
        }
    }

    /**
     * Adds the step-up authentication configuration setting
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addStepUpAuthenticationConfig($undo = false)
    {
        if ($undo) {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->removeConfig(CONFIGDIR . 'blesta.php', 'Blesta.step_up_authentication');
            }
        } else {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->addConfig(CONFIGDIR . 'blesta.php', 'Blesta.step_up_authentication', true);
            }
        }
    }

    /**
     * Adds a new "format" column to the states table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addStatesFormatColumn($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `countries` DROP `format`;');
        } else {
            $this->Record->query("ALTER TABLE `countries`
                ADD `format` ENUM( 'code', 'name' ) NOT NULL DEFAULT 'code' AFTER `alpha3`;");
        }
    }

    /**
     * Updates the Czech Republic States to ISO_3166-2
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateCzechRepublicStates($undo = false)
    {
        $states_map = [
            // Vysočina Region
            '611' => '631',
            '612' => '632',
            '613' => '633',
            '614' => '634',
            '615' => '635',

            // South Moravian Region
            '621' => '641',
            '622' => '642',
            '623' => '643',
            '624' => '644',
            '625' => '645',
            '626' => '646',
            '627' => '647',

            // Primary regions
            'JC' => '31',
            'JM' => '64',
            'KA' => '41',
            'KR' => '52',
            'LI' => '51',
            'MO' => '80',
            'OL' => '71',
            'PA' => '53',
            'PL' => '32',
            'PR' => '10',
            'ST' => '20',
            'US' => '42',
            'VY' => '63',
            'ZL' => '72',
        ];

        foreach ($states_map as $old_code => $new_code) {
            $this->Record->where('country_alpha2', '=', 'CZ')
                ->where('code', '=', $old_code)
                ->update('states', ['code' => $new_code]);
        }
    }

    /**
     * Sets the preferred state format for the existing states
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function setStatesFormats($undo = false)
    {
        // Countries preferring state codes in addresses
        $code_countries = [
            'US', // United States
            'CA', // Canada
            'AU', // Australia
            'MX', // Mexico
            'BR', // Brazil
            'IT', // Italy
        ];

        if ($undo) {
            // Nothing to do
        } else {
            $this->Record->where('alpha2', 'notin', $code_countries)
                ->update('countries', ['format' => 'name']);
        }
    }

    /**
     * Creates a new user_avatars table
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addUserAvatarsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('user_avatars');
        } else {
            $this->Record->
            setField('user_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
            setField('file_name', ['type' => 'varchar', 'size' => 255])->
            setKey(['user_id'], 'primary')->
            create('user_avatars', true);
        }
    }

    /**
     * Adds the new company setting for requeuing invoice delivery on closure
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addRequeueInvoiceDeliverySetting($undo = false)
    {
        $setting = 'requeue_invoice_delivery_on_closed';

        if ($undo) {
            // Remove the new setting
            $this->Record->from('company_settings')->where('key', '=', $setting)->delete();
        } else {
            $value = 'false';

            // Add to company settings for all existing companies
            $companies = $this->Record->select()->from('companies')->getStatement();
            foreach ($companies as $company) {
                $this->Record->insert(
                    'company_settings',
                    ['key' => $setting, 'value' => $value, 'company_id' => $company->id]
                );
            }
        }
    }

    /**
     * Adds default merchant gateway settings for all companies
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addDefaultMerchantGatewaySettings($undo = false)
    {
        Loader::loadModels($this, ['Companies', 'GatewayManager']);

        if ($undo) {
            // Remove the default_merchant_gateway setting from all companies
            $this->Record->where('key', '=', 'default_merchant_gateway')
                ->delete('company_settings');
        } else {
            // Get all companies
            $companies = $this->Companies->getAll();

            // Get all merchant gateways for all companies
            foreach ($companies as $company) {
                $merchant_gateways = $this->GatewayManager->getAll($company->id, 'merchant');

                // Build currency to gateway mapping (first match prevails)
                $currency_gateway_map = [];
                foreach ($merchant_gateways as $gateway) {
                    // Get currencies for this gateway
                    $gateway_currencies = $this->Record->select(['currency'])
                        ->from('gateway_currencies')
                        ->where('gateway_id', '=', $gateway->id)
                        ->fetchAll();

                    foreach ($gateway_currencies as $gateway_currency) {
                        // Only set if currency doesn't already have a gateway (first match prevails)
                        if (!isset($currency_gateway_map[$gateway_currency->currency])) {
                            $currency_gateway_map[$gateway_currency->currency] = $gateway->id;
                        }
                    }
                }

                // Serialize and base64 encode the mapping
                $serialized_map = json_encode($currency_gateway_map);

                // Save as company setting
                $this->Companies->setSetting($company->id, 'default_merchant_gateway', $serialized_map);
            }
        }
    }

    /**
     * Adds the coupon_package_options table for limiting coupons based on package options
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addCouponPackageOptions($undo = false)
    {
        if ($undo) {
            $this->Record->drop('coupon_package_options');
        } else {
            // Create coupon_package_options table
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('coupon_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('option_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('option_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('option_value_ids', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('min_quantity', ['type' => 'int', 'size' => 10, 'is_null' => true, 'default' => null])
                ->setField('regex_pattern', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
                ->setKey(['coupon_id'], 'index')
                ->setKey(['option_id'], 'index')
                ->setKey(['id'], 'primary')
                ->create('coupon_package_options', true);
        }
    }

    /**
     * Adds "disable_pricing" and "hide_on_invoice" columns to the package_options table
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addPackageOptionsPricingColumns($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `package_options` DROP `disable_pricing`, DROP `hide_on_invoice`');
        } else {
            $this->Record->query(
                'ALTER TABLE `package_options`
                ADD `disable_pricing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hidden`,
                ADD `hide_on_invoice` TINYINT(1) NOT NULL DEFAULT 0 AFTER `disable_pricing`'
            );
        }
    }

    /**
     * Adds credit handling settings to company settings
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addCreditHandlingSettings($undo = false)
    {
        if ($undo) {
            $this->Record->from('company_settings')
                ->where('key', '=', 'payment_credit_enabled')
                ->delete();
            $this->Record->from('company_settings')
                ->where('key', '=', 'payment_credit_limits')
                ->delete();
        } else {
            $companies = $this->Record->select()->from('companies')->getStatement();
            foreach ($companies as $company) {
                $this->Record->insert(
                    'company_settings',
                    ['key' => 'payment_credit_enabled', 'value' => '1', 'company_id' => $company->id]
                );
                $this->Record->insert(
                    'company_settings',
                    ['key' => 'payment_credit_limits', 'value' => '{}', 'company_id' => $company->id]
                );
            }
        }
    }

    /**
     * Adds the low balance notifications cron task and its runs for all companies
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addLowBalanceNotificationsCron($undo = false)
    {
        Loader::loadModels($this, ['Companies', 'CronTasks']);

        if ($undo) {
            $cron = $this->CronTasks->getByKey('low_balance_notifications', null, 'system');

            $this->Record->from('cron_task_runs')
                ->innerJoin('cron_tasks', 'cron_tasks.id', '=', 'cron_task_runs.task_id', false)
                ->where('cron_tasks.task_type', '=', 'system')
                ->where('cron_task_runs.task_id', '=', $cron->id)
                ->delete(['cron_task_runs.*']);

            $this->Record->from('cron_tasks')
                ->where('id', '=', $cron->id)
                ->delete();
        } else {
            $task_id = $this->CronTasks->add([
                'key' => 'low_balance_notifications',
                'task_type' => 'system',
                'name' => 'CronTasks.crontask.name.low_balance_notifications',
                'description' => 'CronTasks.crontask.description.low_balance_notifications',
                'is_lang' => 1,
                'type' => 'time'
            ]);

            if ($task_id) {
                $companies = $this->Companies->getAll();
                foreach ($companies as $company) {
                    // Add cron task run for the company
                    $vars = [
                        'task_id' => $task_id,
                        'company_id' => $company->id,
                        'time' => '10:00',
                        'interval' => null,
                        'enabled' => 1,
                        'date_enabled' => $this->Companies->dateToUtc(date('c'))
                    ];

                    $this->Record->insert('cron_task_runs', $vars);
                }
            }
        }
    }

    /**
     * Adds permissions for credit handling view
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addCreditHandlingPermissions($undo = false)
    {
        Loader::loadModels($this, ['Permissions', 'StaffGroups']);
        Loader::loadComponents($this, ['Acl']);

        if ($undo) {
            // Nothing to undo
        } else {
            $staff_groups = $this->StaffGroups->getAll();
            // Determine comparable permission access
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = $this->Permissions->authorized(
                    'staff_group_' . $staff_group->id,
                    'admin_company_billing',
                    'invoices',
                    'staff',
                    $staff_group->company_id
                );
            }

            $group = $this->Permissions->getGroupByAlias('admin_settings');

            if ($group) {
                $permission = [
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_company_billing_credithandling',
                    'alias' => 'admin_company_billing',
                    'action' => 'credithandling'
                ];

                $this->Permissions->add($permission);

                foreach ($staff_groups as $staff_group) {
                    // If staff group has access to invoices, grant access to credit handling
                    if ($staff_group_access[$staff_group->id]) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_company_billing', $permission['action']);
                    }
                }
            }
        }
    }

    /**
     * Adds the low balance notification email group
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addLowBalanceNotificationEmail($undo = false)
    {
        Loader::loadModels($this, ['EmailGroups', 'Languages', 'Companies', 'Emails']);

        if ($undo) {
            // Get the email group
            $group = $this->Record->select()
                ->from('email_groups')
                ->where('action', '=', 'low_balance_notification')
                ->fetch();

            // Delete the email group and all associated emails
            if ($group) {
                $this->Record->from('emails')
                    ->where('email_group_id', '=', $group->id)
                    ->delete();
                $this->Record->from('email_groups')
                    ->where('id', '=', $group->id)
                    ->delete();
            }
        } else {
            // Check if email group already exists
            $group = $this->Record->select()
                ->from('email_groups')
                ->where('action', '=', 'low_balance_notification')
                ->fetch();
            $email_group_id = $group->id ?? null;

            // Add the email group if it doesn't exist
            if (!$group) {
                $this->Record->insert(
                    'email_groups',
                    [
                        'action' => 'low_balance_notification',
                        'type' => 'client',
                        'notice_type' => 'to',
                        'tags' => '{contact.first_name},{contact.last_name},{contact.email},{client.id_code},{balance},{threshold},{currency},{client_url}'
                    ]
                );
                $email_group_id = $this->Record->lastInsertId();
            }

            // Fetch all companies
            $companies = $this->Companies->getAll();

            foreach ($companies as $company) {
                // Fetch all languages installed for this company
                $languages = $this->Languages->getAll($company->id);

                // Add the low balance email template for each installed language
                foreach ($languages as $language) {
                    // Fetch the invoice delivery unpaid email to copy fields from
                    $invoice_delivery_email = $this->Emails->getByType($company->id, 'invoice_delivery_unpaid', $language->code);

                    if ($invoice_delivery_email && $email_group_id) {
                        $vars = [
                            'email_group_id' => $email_group_id,
                            'company_id' => $company->id,
                            'lang' => $language->code,
                            'from' => $invoice_delivery_email->from,
                            'from_name' => $invoice_delivery_email->from_name,
                            'subject' => 'Low Balance Notification',
                            'text' => 'Hi {contact.first_name} {contact.last_name},

Your account credit balance has fallen below your configured threshold.

Current Balance: {balance}
Notification Threshold: {threshold}
Currency: {currency}

To add funds to your account, please visit: {client_url}

If you have any questions, please contact us.',
                            'html' => '<p>Hi {contact.first_name} {contact.last_name},</p>

<p>Your account credit balance has fallen below your configured threshold.</p>

<p><strong>Current Balance:</strong> {balance}<br>
<strong>Notification Threshold:</strong> {threshold}<br>
<strong>Currency:</strong> {currency}</p>

<p>To add funds to your account, please visit: <a href="{client_url}">{client_url}</a></p>

<p>If you have any questions, please contact us.</p>',
                            'email_signature_id' => $invoice_delivery_email->email_signature_id
                        ];

                        $this->Record->insert('emails', $vars);
                    }
                }
            }
        }
    }

    /**
     * Adds permissions for new controller actions
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addNewControllerPermissions($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            Loader::loadModels($this, ['Permissions', 'StaffGroups']);
            Loader::loadComponents($this, ['Acl']);

            // Fetch all staff groups
            $staff_groups = $this->StaffGroups->getAll();

            // Determine comparable permission access
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = [
                    'admin_company_billing' => $this->Permissions->authorized(
                        'staff_group_' . $staff_group->id,
                        'admin_company_billing',
                        '*',
                        'staff',
                        $staff_group->company_id
                    ),
                    'admin_company_clientoptions_customfields' => $this->Permissions->authorized(
                        'staff_group_' . $staff_group->id,
                        'admin_company_clientoptions',
                        'customfields',
                        'staff',
                        $staff_group->company_id
                    )
                ];
            }

            $group = $this->Permissions->getGroupByAlias('admin_settings');

            // Add permissions for electronic invoices controller
            if ($group) {
                $permissions = [
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_company_electronic_invoices_index',
                    'alias' => 'admin_company_electronic_invoices',
                    'action' => 'index'
                ];
                $this->Permissions->add($permissions);

                foreach ($staff_groups as $staff_group) {
                    // If staff group has access to billing, grant access to electronic invoices
                    if ($staff_group_access[$staff_group->id]['admin_company_billing']) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                    } else {
                        $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                    }
                }

                // Add permissions for new billing AJAX endpoints
                $billing_actions = ['getpackageoptions', 'getpackageoptiondetails'];
                foreach ($billing_actions as $action) {
                    $permissions = [
                        'group_id' => $group->id,
                        'name' => 'StaffGroups.permissions.admin_company_billing_' . $action,
                        'alias' => 'admin_company_billing',
                        'action' => $action
                    ];
                    $this->Permissions->add($permissions);

                    foreach ($staff_groups as $staff_group) {
                        // If staff group has access to billing, grant access to these endpoints
                        if ($staff_group_access[$staff_group->id]['admin_company_billing']) {
                            $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        } else {
                            $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        }
                    }
                }

                // Add permissions for client options custom field CRUD actions
                $clientoptions_actions = ['addcustomfield', 'editcustomfield', 'deletecustomfield'];
                foreach ($clientoptions_actions as $action) {
                    $permissions = [
                        'group_id' => $group->id,
                        'name' => 'StaffGroups.permissions.admin_company_clientoptions_' . $action,
                        'alias' => 'admin_company_clientoptions',
                        'action' => $action
                    ];
                    $this->Permissions->add($permissions);

                    foreach ($staff_groups as $staff_group) {
                        // If staff group has access to customfields, grant access to CRUD actions
                        if ($staff_group_access[$staff_group->id]['admin_company_clientoptions_customfields']) {
                            $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        } else {
                            $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        }
                    }
                }
            }
        }
    }
}
