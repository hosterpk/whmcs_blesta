<?php
/**
 * Upgrades to version 5.12.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_12_0B1 extends UpgradeUtil
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
            'updateSettingsValueToMediumText',
            'updateInvoiceDeliveryTemplateTags',
            'addServicesDatePaidThrough',
            'addOAuthCompanySettings',
            'addStoredTagPaymentEmail',
            'updatePaymentEmails',
            'addCancellationCompanySettings',
            'addServiceUncancellationEmailTemplates',
            'removeServiceChanges',
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
     * Updates the "value" column from TEXT to MEDIUMTEXT on the settings table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateSettingsValueToMediumText($undo = false)
    {
        if ($undo) {
            $this->Record->query(
                "ALTER TABLE `settings` CHANGE `value` `value` TEXT NOT NULL;"
            );
        } else {
            $this->Record->query(
                "ALTER TABLE `settings` CHANGE `value` `value` MEDIUMTEXT NOT NULL;"
            );
        }
    }

    /**
     * Adds a new "email_attachments" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateInvoiceDeliveryTemplateTags($undo = false)
    {
        $templates = ['invoice_delivery_unpaid', 'invoice_delivery_paid'];

        foreach ($templates as $template) {
            $email_group = $this->Record->select()->
                from('email_groups')->
                where('action', '=', $template)->
                fetch();

            if ($email_group) {
                $tags = ($undo
                    ? str_replace('{contact.custom_fields},', '', $email_group->tags)
                    : '{contact.custom_fields},' . $email_group->tags);
                $this->Record->where('id', '=', $email_group->id)->
                    update('email_groups', ['tags' => $tags], ['tags']);
            }
        }
    }

    /**
     * Adds the new "date_paid_through" column to the services table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addServicesDatePaidThrough($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `services` DROP `date_paid_through`;');
        } else {
            $this->Record->query("ALTER TABLE `services` ADD `date_paid_through` DATETIME NULL DEFAULT NULL after `date_last_renewed`;");
        }
    }

    /**
     * Adds the new company settings for OAuth 2.0
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addOAuthCompanySettings($undo = false)
    {
        Loader::loadModels($this, ['Companies']);

        $company_settings = [
            'oauth2_host' => '',
            'oauth2_port' => 465,
            'oauth2_client_id' => '',
            'oauth2_client_secret' => '',
            'oauth2_code' => '',
            'oauth2_access_token' => '',
            'oauth2_refresh_token' => '',
            'oauth2_provider' => ''
        ];

        // Fetch all companies
        $companies = $this->Companies->getAll();

        // Add or remove the settings
        foreach ($companies as $company) {
            foreach ($company_settings as $setting => $default_value) {
                if ($undo) {
                    $this->Record->from('company_settings')
                        ->where('key', '=', $setting)
                        ->delete(['company_settings.*']);
                } else {
                    $this->Record->insert(
                        'company_settings',
                        ['key' => $setting, 'company_id' => $company->id, 'value' => $default_value]
                    );
                }
            }
        }
    }

    /**
     * Adds the new "stored" tag to payment emails
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addStoredTagPaymentEmail($undo = false)
    {
        $actions = [
            'payment_cc_approved', 'payment_cc_declined', 'payment_cc_error',
            'payment_ach_approved', 'payment_ach_declined', 'payment_ach_error'
        ];

        if ($undo) {
            foreach ($actions as $action) {
                $email_group = $this->Record->select()
                    ->from('email_groups')
                    ->where('action', '=', $action)
                    ->fetch();

                if ($email_group) {
                    $tags = str_replace(',{stored}', '', $email_group->tags);
                    $this->Record->where('id', '=', $email_group->id)->update(
                        'email_groups',
                        ['tags' => $tags],
                        ['tags']
                    );
                }
            }
        } else {
            foreach ($actions as $action) {
                $this->Record->query(
                    "UPDATE `email_groups` SET tags = CONCAT(`tags`, ',{stored}') WHERE action = ?",
                    [$action]
                );
            }
        }
    }

    /**
     * Update payment emails
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function updatePaymentEmails($undo = false)
    {
        $actions = [
            'payment_cc_approved'
        ];

        if ($undo) {
            // No need to undo
        } else {
            foreach ($actions as $action) {
                $email_group = $this->Record->select()
                    ->from('email_groups')
                    ->where('action', '=', $action)
                    ->fetch();

                $fields = [
                    'text' => "Hi {contact.first_name | e},

We have successfully processed payment with your {% if card_type %}{card_type | e}{% else %}Credit Card{% endif %}{% if last_four %}, ending in {last_four | e}{% endif %}. Please keep this email as a receipt for your records.

Amount: {amount | e}
Transaction Number: {response.transaction_id | e}

The charge will be listed as being from {company.name | e} on your credit card statement.

Thank you for your business!",
                'html' => "<p>
	Hi {contact.first_name | e},<br />
	<br />
	We have successfully processed payment with your {% if card_type %}{card_type | e}{% else %}Credit Card{% endif %}{% if last_four %}, ending in {last_four | e}{% endif %}. Please keep this email as a receipt for your records.<br />
	<br />
	Amount: {amount | e}<br />
	Transaction Number: {response.transaction_id | e}<br />
	<br />
	The charge will be listed as being from {company.name | e} on your credit card statement.<br />
	<br />
	Thank you for your business!</p>
"
                ];

                $this->Record->where('emails.email_group_id', '=', $email_group->id)
                    ->where('emails.lang', '=', 'en_us')
                    ->update('emails', $fields);
            }
        }
    }

    /**
     * Adds the new company settings for cancellation
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addCancellationCompanySettings($undo = false)
    {
        Loader::loadModels($this, ['Companies']);

        $company_settings = [
            'clients_cancel_options' => 'both'
        ];

        // Fetch all companies
        $companies = $this->Companies->getAll();

        // Add or remove the settings
        foreach ($companies as $company) {
            foreach ($company_settings as $setting => $default_value) {
                if ($undo) {
                    $this->Record->from('company_settings')
                        ->where('key', '=', $setting)
                        ->delete(['company_settings.*']);
                } else {
                    $this->Record->insert(
                        'company_settings',
                        ['key' => $setting, 'company_id' => $company->id, 'value' => $default_value]
                    );
                }
            }
        }
    }

    /**
     * Adds email templates, i.e. the tax liability report
     *
     * @param bool $undo True to undo the change, or false to perform the change
     */
    private function addServiceUncancellationEmailTemplates($undo = false)
    {
        if ($undo) {
            return;
        }

        Loader::loadModels($this, ['Companies', 'Emails', 'Languages']);

        // Add the service un-cancellation email template group
        $this->Record->query("INSERT INTO `email_groups` (`id`, `action`, `type`, `notice_type`, `plugin_dir`, `tags`)
            VALUES (
                NULL,
                'service_uncancellation',
                'client',
                'bcc',
                NULL,
                '{contact.first_name},{contact.last_name},{package.email_html},{package.email_text}'
            );");
        $email_group_id = $this->Record->lastInsertId();

        // Fetch all companies
        $companies = $this->Companies->getAll();

        foreach ($companies as $company) {
            // Fetch all languages installed for this company
            $languages = $this->Languages->getAll($company->id);

            // Add the email template for each installed language
            foreach ($languages as $language) {
                // Fetch the service creation email to copy fields from
                $service_creation_email = $this->Emails->getByType($company->id, 'service_creation', $language->code);

                if ($service_creation_email) {
                    $vars = [
                        'email_group_id' => $email_group_id,
                        'company_id' => $company->id,
                        'lang' => $language->code,
                        'from' => $service_creation_email->from,
                        'from_name' => $service_creation_email->from_name,
                        'subject' => 'Service Re-Activated',
                        'text' => 'Hi {contact.first_name | e},

Your service has been approved and re-activated. Please keep this email for your records.

{package.email_text | e}',
                        'html' => '<p>
    Hi {contact.first_name | e},<br />
    <br />
    Your service has been approved and re-activated. Please keep this email for your records.<br />
    <br />
    {package.email_html}</p>',
                        'email_signature_id' => $service_creation_email->email_signature_id
                    ];

                    $this->Record->insert('emails', $vars);
                }
            }
        }
    }

    /**
     * Removes the "Service Changes" item from the navigation bar
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function removeServiceChanges($undo = false)
    {
        Loader::loadModels($this, ['Companies', 'Navigation', 'Actions']);

        // Fetch all companies
        $companies = $this->Companies->getAll();

        // Add or remove the settings
        foreach ($companies as $company) {
            $nav = [
                'company_id' => $company->id,
                'url' => 'tools/servicechanges/',
                'location' => 'nav_staff',
                'name' => 'Navigation.getprimary.nav_tools_servicechanges',
                'editable' => 0
            ];

            if ($undo) {
                $action_id = $this->Actions->getByUrl($nav['url'], $nav['location'], $nav['company_id']);
                $this->Navigation->add(compact('action_id'));
            } else {
                $this->Navigation->delete($nav);
            }
        }
    }
}
