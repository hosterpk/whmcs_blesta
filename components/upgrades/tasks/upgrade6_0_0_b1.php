<?php
/**
 * Upgrades to version 6.0.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade6_0_0B1 extends UpgradeUtil
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
            'addRestoreSnapshotPermission',
            'addEmailTemplateHistoryTable',
            'addOriginalEmailTemplates',
            'saveCurrentEmailTemplates',
            'addSnapshotsLimitConfig',
            'addActionsIconColumn',
            'updateActionsIcons',
            'addNotificationsTable',
            'addNotificationActionsTable',
            'addStaffNotificationsTable',
            'addClientNotificationsTable',
            'addStaffGroupNotificationsTable',
            'addClientGroupNotificationsTable',
            'addDefaultNotificationActions',
            'initializeWidgetStates',
            'addAiConversationsTable',
            'addAiMessagesTable',
            'addAiSettings',
            'addAiPermissions',
            'updateDefaultClientGroupColor',
            'assignDefaultEmailHtmlTemplate',
            'removeStaffThemeColors',
            'migrateAdminViewDirToParadigm',
            'addPackageOptionValueImageColumn',
            'addDataColumnServiceInvoices',
            'updateTypeColumnServiceInvoices',
            'migrateServiceChanges',
            'addQueryLoggingConfig',
            'addDefaultStaffGroupNotices',
            'addIntegrityCheckPermissions',
            'createCacheDirectory',
            'convertDatabaseToUtf8mb4',
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
        // Undo all tasks in reverse order
        while (($task = array_pop($this->tasks))) {
            $this->{$task}(true);
        }
    }

    /**
     * Adds the restoresnapshot permission to staff groups that can edit email templates
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addRestoreSnapshotPermission($undo = false)
    {
        if ($undo) {
            // Do nothing
        } else {
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);

            $group = $this->Permissions->getGroupByAlias('admin_settings');

            if ($group) {
                $this->Permissions->add([
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_company_emails_restoresnapshot',
                    'alias' => 'admin_company_emails',
                    'action' => 'restoresnapshot'
                ]);

                $staff_groups = $this->Record->select(['id', 'company_id'])->from('staff_groups')->fetchAll();
                foreach ($staff_groups as $staff_group) {
                    if ($this->Permissions->authorized(
                        'staff_group_' . $staff_group->id,
                        'admin_company_emails',
                        'edittemplate',
                        'staff',
                        $staff_group->company_id
                    )) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_company_emails', 'restoresnapshot');
                    }
                }
            }
        }
    }

    /**
     * Creates a new email_snapshots table
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addEmailTemplateHistoryTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('email_snapshots');
        } else {
            $this->Record->
                setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
                setField('email_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('lang', ['type' => 'char', 'size' => 5])->
                setField('from', ['type' => 'varchar', 'size' => 255])->
                setField('from_name', ['type' => 'varchar', 'size' => 255])->
                setField('subject', ['type' => 'mediumtext'])->
                setField('text', ['type' => 'text'])->
                setField('html', ['type' => 'text'])->
                setField('date_saved', ['type' => 'datetime', 'is_null' => true, 'default' => null])->
                setKey(['email_id'], 'index')->
                setKey(['id'], 'primary')->
                create('email_snapshots', true);
        }
    }

    /**
     * Populates email_snapshots with original email templates from version 3.0.0
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addOriginalEmailTemplates($undo = false)
    {
        if ($undo) {
            // Delete original email templates from history
            $this->Record->from('email_snapshots')
                ->where('date_saved', '=', null)
                ->delete();
        } else {
            Loader::loadModels($this, ['Companies']);

            // Get all companies
            $companies = $this->Companies->getAll();

            // Fetch original email templates
            $templates = $this->getOriginalEmailTemplates();

            // Insert each original template into history for all companies and all installed languages
            foreach ($companies as $company) {
                foreach ($templates as $template) {
                    // Find all emails for this template across all languages for this company
                    $emails = $this->Record->select()
                        ->from('emails')
                        ->where('email_group_id', '=', $template['email_group_id'])
                        ->where('company_id', '=', $company->id)
                        ->fetchAll();

                    // Insert the original template for each language version
                    foreach ($emails as $email) {
                        $this->Record->insert('email_snapshots', [
                            'email_id' => $email->id,
                            'lang' => $email->lang,
                            'from' => $template['from'],
                            'from_name' => $template['from_name'],
                            'subject' => $template['subject'],
                            'text' => $template['text'],
                            'html' => $template['html'],
                            'date_saved' => null
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Returns the original email templates
     *
     * @return array Array of original email templates
     */
    private function getOriginalEmailTemplates()
    {
        return [
            [
                'email_group_id' => 1,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Received',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe have successfully processed payment with your {card_type | e}, ending in {last_four | e}. Please keep this email as a receipt for your records.\r\n\r\nAmount: {amount | e}\r\nTransaction Number: {response.transaction_id | e}\r\n\r\nThe charge will be listed as being from {company.name | e} on your credit card statement.\r\n\r\nThank you for your business!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe have successfully processed payment with your {card_type | e}, ending in {last_four | e}. Please keep this email as a receipt for your records.<br />\r\n\t<br />\r\n\tAmount: {amount | e}<br />\r\n\tTransaction Number: {response.transaction_id | e}<br />\r\n\t<br />\r\n\tThe charge will be listed as being from {company.name | e} on your credit card statement.<br />\r\n\t<br />\r\n\tThank you for your business!</p>\r\n"
            ],
            [
                'email_group_id' => 2,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Declined',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe tried to charge your {card_type | e}, ending in {last_four | e}, in the amount of {amount | e}, but it was declined.\r\n\r\nResponse Message: {response.message | e}\r\n\r\nPlease verify that the account details we have on file are correct.\r\n\r\nWe will attempt to charge your card again once a day until it is successful, up to three times.",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe tried to charge your {card_type | e}, ending in {last_four | e}, in the amount of {amount | e}, but it was declined.<br />\r\n\t<br />\r\n\t<strong>Response Message: {response.message | e}</strong></p>\r\n<p>\r\n\t<strong>Please verify that the account details we have on file are correct.</strong><br />\r\n\t<br />\r\n\tWe will attempt to charge your card again once a day until it is successful, up to three times.</p>\r\n"
            ],
            [
                'email_group_id' => 3,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Error',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe tried to charge your {card_type | e}, ending in {last_four | e}, in the amount of {amount | e}, but the request resulted in an error.\r\n\r\nError Response: {response.message | e}\r\n\r\nPlease verify that the account details we have on file are correct.\r\n\r\nWe will attempt to charge your card again once a day until it is successful, up to three times.",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe tried to charge your {card_type | e}, ending in {last_four | e}, in the amount of {amount | e}, but the request resulted in an error.</p>\r\n<p>\r\n\t<strong>Error Response: {response.message | e}</strong></p>\r\n<p>\r\n\t<strong>Please verify that the account details we have on file are correct.</strong><br />\r\n\t<br />\r\n\tWe will attempt to charge your card again once a day until it is successful, up to three times.</p>\r\n"
            ],
            [
                'email_group_id' => 4,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Submitted',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe have submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}. Funds should be withdrawn within the next 72 hours, but you may see a pending request in your online banking now. Please keep this email as a receipt for your records.\r\n\r\nAmount: {amount | e}\r\nTransaction Number: {response.transaction_id | e}\r\n\r\nThe charge will be listed as being from {company.name | e} on your bank statement.\r\n\r\nThank you for your business!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe have submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}. Funds should be withdrawn within the next 72 hours, but you may see a pending request in your online banking now. Please keep this email as a receipt for your records.<br />\r\n\t<br />\r\n\tAmount: {amount | e}<br />\r\n\tTransaction Number: {response.transaction_id | e}<br />\r\n\t<br />\r\n\tThe charge will be listed as being from {company.name | e} on your bank statement.<br />\r\n\t<br />\r\n\tThank you for your business!</p>\r\n"
            ],
            [
                'email_group_id' => 5,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Declined',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}, in the amount of {amount | e}, but the request was declined.\r\n\r\nResponse Message: {response.message | e}\r\n\r\nPlease verify that the account details we have on file are correct.\r\n\r\nWe will attempt this request again once a day until it is successful, up to three times.",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}, in the amount of {amount | e}, but the request was declined.</p>\r\n<p>\r\n\t<strong>Response Message: {response.message | e}</strong><br />\r\n\t<br />\r\n\t<strong>Please verify that the account details we have on file are correct.</strong><br />\r\n\t<br />\r\n\tWe will attempt this request again once a day until it is successful, up to three times.</p>\r\n"
            ],
            [
                'email_group_id' => 6,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Error',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}, in the amount of {amount | e}, but the request resulted in an error.\r\n\r\nResponse Message: {response.message | e}\r\n\r\nPlease verify that the account details we have on file are correct.\r\n\r\nWe will attempt this request again once a day until it is successful, up to three times.",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe submitted a debit request to your bank for your {account_type | e} account, ending in {last_four | e}, in the amount of {amount | e}, but the request resulted in an error.</p>\r\n<p>\r\n\t<strong>Response Message: {response.message | e}</strong></p>\r\n<p>\r\n\t<strong>Please verify that the account details we have on file are correct.</strong><br />\r\n\t<br />\r\n\tWe will attempt this request again once a day until it is successful, up to three times.</p>\r\n"
            ],
            [
                'email_group_id' => 7,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Received',
                'text' => "Hi {contact.first_name | e},\r\n\r\nWe have received your {payment_type | e} payment in the amount of {amount | e} and it has been applied to your account. Please keep this email as a receipt for your records.\r\n\r\nAmount: {amount | e}\r\nTransaction Number: {transaction_id | e}\r\n\r\nThank you for your business!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tWe have received your {payment_type | e} payment in the amount of {amount | e} and it has been applied to your account. Please keep this email as a receipt for your records.<br />\r\n\t<br />\r\n\tAmount: {amount | e}<br />\r\n\tTransaction Number: {transaction_id | e}<br />\r\n\t<br />\r\n\tThank you for your business!</p>\r\n"
            ],
            [
                'email_group_id' => 8,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Credit Card Expiring Soon',
                'text' => "Hi {contact.first_name | e},\r\n\r\nYour {card_type | e} ending in {last_four | e} is expiring this month.\r\n\r\nTo update your card, please log into our client area at https://{client_url | e}. If the card is not updated, we'll be unable to process payment for any current or future charges.\r\n\r\nThank you!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tYour {card_type | e} ending in {last_four | e} is expiring this month.<br />\r\n\t<br />\r\n\tTo update your card, please log into our client area at <a href=\"https://{client_url | e}\">https://{client_url | e}</a>. If the card is not updated, we'll be unable to process payment for any current or future charges.<br />\r\n\t<br />\r\n\tThank you!</p>\r\n"
            ],
            [
                'email_group_id' => 9,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Invoice Due',
                'text' => "Hi {contact.first_name | e},\n\nAn invoice has been created for your account and is attached to this email in PDF format.\n{% for invoice in invoices %}\nInvoice #: {invoice.id_code | e}\n\n{% if autodebit %}{% if payment_account %}{% if invoice.autodebit_date_formatted %}Auto debit is enabled for your account, so we'll automatically process the card you have on file on {invoice.autodebit_date_formatted | e} unless payment has been applied sooner.{% else %}If you would like us to automatically charge your card, login to your account at https://{client_url | e} to set up auto debit.{% endif %}{% else %}If you would like us to automatically charge your card, login to your account at https://{client_url | e} to set up auto debit.{% endif %}{% else %}If you would like us to automatically charge your card, login to your account at https://{client_url | e} to set up auto debit.{% endif %}\n\nPay Now, visit https://{invoice.payment_url | e} (No login required)\n{% endfor %}\nIf you have any questions about your invoice, please let us know!",
                'html' => "<p>\n\tHi {contact.first_name | e},<br />\n\t<br />\n\tAn invoice has been created for your account and is attached to this email in PDF format.<br />\n\t{% for invoice in invoices %}<br />\n\tInvoice #:<strong> {invoice.id_code | e}</strong></p>\n<p>\n\t{% if autodebit %}{% if payment_account %}{% if invoice.autodebit_date_formatted %}Auto debit is enabled for your account, so we'll automatically process the card you have on file on <strong>{invoice.autodebit_date_formatted | e}</strong> unless payment has been applied sooner.{% else %}If you would like us to automatically charge your card, login to your account at <a href=\"https://{client_url | e}\">https://{client_url | e}</a> to set up auto debit.{% endif %}{% else %}If you would like us to automatically charge your card, login to your account at <a href=\"https://{client_url | e}\">https://{client_url | e}</a> to set up auto debit.{% endif %}{% else %}If you would like us to automatically charge your card, login to your account at <a href=\"https://{client_url | e}\">https://{client_url | e}</a> to set up auto debit.{% endif %}<br />\n\t<br />\n\t<a href=\"https://{invoice.payment_url | e}\">Pay Now</a> (No login required)<br />\n\t{% endfor %}<br />\nIf you have any questions about your invoice, please let us know!</p>"
            ],
            [
                'email_group_id' => 10,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Invoice Due (Copy)',
                'text' => "Hi {contact.first_name | e},\r\n\r\nAn invoice has been created for your account and is attached to this email in PDF format.\r\n{% for invoice in invoices %}\r\nInvoice #: {invoice.id_code | e}{% endfor %}\r\nThis invoice has already been paid, so no payment is necessary for this one, but your account may have other balances. To login to your account, please visit https://{client_url | e}.\r\n\r\nIf you have any questions about your invoice, please let us know!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tAn invoice has been created for your account and is attached to this email in PDF format.<br />\r\n\t{% for invoice in invoices %}<br />\r\n\tInvoice #:<strong> {invoice.id_code | e}</strong>{% endfor %}</p>\r\n<p>\r\n\tThis invoice has already been paid, so no payment is necessary for this one, but your account may have other balances. To login to your account, please visit <a href=\"https://{client_url | e}\">https://{client_url | e}</a>.<br />\r\n\t<br />\r\n\tIf you have any questions about your invoice, please let us know!</p>\r\n"
            ],
            [
                'email_group_id' => 11,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Invoice Due: Reminder',
                'text' => "Hi {contact.first_name | e},\r\n\r\nThis is a reminder that invoice #{invoice.id_code | e} is due on {invoice.date_due_formatted | e}. If you have recently mailed in payment for this invoice, you can ignore this reminder.\r\n\r\nPay Now.. https://{payment_url | e} (No Login Required)\r\n\r\nThank you for your continued business!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tThis is a reminder that invoice <strong>#{invoice.id_code | e}</strong> is due on <strong>{invoice.date_due_formatted | e}</strong>. If you have recently mailed in payment for this invoice, you can ignore this reminder.</p>\r\n<p>\r\n\t<a href=\"https://{payment_url | e}\">Pay Now</a> (No login required)<br />\r\n\t<br />\r\n\tThank you for your continued business!</p>\r\n"
            ],
            [
                'email_group_id' => 12,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Invoice Due: 2nd Notice',
                'text' => "Hi {contact.first_name | e},\r\n\r\nThis is the 2nd notice we have sent regarding invoice #{invoice.id_code | e}. It was due on {invoice.date_due_formatted | e} and is now past due. If you have recently mailed in payment for this invoice, you can ignore this email.\r\n\r\nPay Now.. https://{payment_url | e} (No login required)",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tThis is the <strong>2nd notice</strong> we have sent regarding invoice <strong>#{invoice.id_code | e}</strong>. It was due on <strong>{invoice.date_due_formatted | e}</strong> and is now past due. If you have recently mailed in payment for this invoice, you can ignore this email.<br />\r\n\t<br />\r\n\t<a href=\"https://{payment_url | e}\">Pay Now</a> (No login required)</p>\r\n"
            ],
            [
                'email_group_id' => 13,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Invoice Due: Last Notice',
                'text' => "Hi {contact.first_name | e},\r\n\r\nThis is the 3rd notice we have sent regarding invoice #{invoice.id_code | e}. It was due on {invoice.date_due_formatted | e} and is now past due. This is the last notice we will send regarding this particular invoice. If payment is not received soon, the account may be suspended.\r\n\r\nPay Now.. https://{payment_url | e} (No login required)",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tThis is the <strong>3rd notice</strong> we have sent regarding invoice <strong>#{invoice.id_code | e}</strong>. It was due on <strong>{invoice.date_due_formatted | e}</strong> and is now past due. This is the last notice we will send regarding this particular invoice. If payment is not received soon, the account may be suspended.<br />\r\n\t<br />\r\n\t<a href=\"https://{payment_url | e}\">Pay Now</a> (No login required)</p>\r\n"
            ],
            [
                'email_group_id' => 14,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Password Reset',
                'text' => "Hi {contact.first_name | escape | e},\r\n\r\nSomeone at the IP address {ip_address | e} requested a password reset for your account. If this was you, please visit the following URL to reset your password...\r\n\r\nhttps://{password_reset_url | e}\r\n\r\nIf you did not make this request, you can safely ignore this email.\r\n",
                'html' => "<p>\r\n\tHi {contact.first_name | escape | e},<br />\r\n\t<br />\r\n\tSomeone at the IP address {ip_address | e} requested a password reset for your account. If this was you, please visit the following URL to reset your password...<br />\r\n\t<br />\r\n\t<a href=\"https://{password_reset_url | e}\">https://{password_reset_url | e}</a><br />\r\n\t<br />\r\n\tIf you did not make this request, you can safely ignore this email.</p>\r\n"
            ],
            [
                'email_group_id' => 15,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Service Suspended',
                'text' => "Hi {contact.first_name | e},\n\nYour service, {package.name | e} - {service.name | e} has been suspended. The service may have been suspended for the following reasons:\n\n1. Non-payment. If your service was suspended for non-payment, you may login at https://{client_uri | e} to post payment and re-activate the service.\n2. TOS or abuse violation.\n\nIf the service is suspended for an extended period of time, it may be cancelled. Please contact us if you have any questions.",
                'html' => "<p>Hi {contact.first_name | e},</p>\n<p>Your service, {package.name | e} - {service.name | e} has been suspended. The service may have been suspended for the following reasons:</p>\n<ol>\n\t<li>Non-payment. If your service was suspended for non-payment, you may login at&nbsp;<a href=\"https://{client_uri | e}\">https://{client_uri | e}</a>&nbsp;to post payment and re-activate the service.</li>\n\t<li>TOS or abuse violation.</li>\n</ol>\n<p>If the service is suspended for an extended period of time, it may be cancelled. Please contact us if you have any questions.</p>"
            ],
            [
                'email_group_id' => 16,
                'lang' => 'en_us',
                'from' => 'sales@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Welcome to {company.name | e}',
                'text' => "Hi {contact.first_name | e}!\r\n\r\nThank you for registering with us. Your new account has been set up and you may login to our client area with the details below. Please login and change your password at your earliest convenience.\r\n\r\nLogin at.. https://{client_url | e}login/ with..\r\n\r\nUsername: {username | e}\r\nPassword: (Use the password you signed up with)\r\n\r\nIf you placed an order for services, you'll receive a separate email once they are activated.\r\n\r\nThank you for choosing {company.name | e}!",
                'html' => "<p>\r\n\tHi {contact.first_name | e}!<br />\r\n\t<br />\r\n\tThank you for registering with us. Your new account has been set up and you may login to our client area with the details below. Please login and change your password at your earliest convenience.<br />\r\n\t<br />\r\n\tLogin at.. <a href=\"https://{client_url | e}login/\">https://{client_url | e}login/</a> with..<br />\r\n\t<br />\r\n\tUsername: {username | e}<br />\r\n\tPassword: (Use the password you signed up with)<br />\r\n\t<br />\r\n\tIf you placed an order for services, you&#39;ll receive a separate email once they are activated.<br />\r\n\t<br />\r\n\tThank you for choosing {company.name | e}!</p>\r\n"
            ],
            [
                'email_group_id' => 17,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing',
                'subject' => 'Monthly Aging Invoices Report',
                'text' => "Hi {staff.first_name | e},\n\nAn Aging Invoices Report has been generated for {company.name | e} and is attached to this email as a CSV file.",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n\tAn Aging Invoices Report has been generated for {company.name | e} and is attached to this email as a CSV file.</p>"
            ],
            [
                'email_group_id' => 18,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing',
                'subject' => 'Daily Invoice Creation Report',
                'text' => "Hi {staff.first_name | e},\n\nA Daily Invoice Creation Report has been generated for {company.name | e} and is attached to this email as a CSV file.",
                'html' => "<p>\n\tHi {staff.first_name | e},</p>\n<p>\n\tA Daily Invoice Creation Report has been generated for {company.name | e} and is attached to this email as a CSV file.</p>"
            ],
            [
                'email_group_id' => 19,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Suspension Error',
                'text' => "Hi {staff.first_name | e},\n\nThere was an error with the {package.name | e} package, or its module does not support automatic suspension.\n\n{package.name | e} {service.name | e}\n--\n{client.id_code | e}\n{client.first_name | e} {client.last_name | e}\n{client.email | e}\n\nManage Service (https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/)\n\nThe service may need to be suspended manually.\n{% if errors %}{% for type in errors %}{% for error in type %}\nError: {error | e}\n\n{% endfor %}{% endfor %}{% endif %}",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n\tThere was an error with the {package.name | e} package, or its module does not support automatic suspension.<br />\n\t<br />\n\t{package.name | e} {service.name | e}<br />\n\t--<br />\n\t{client.id_code | e}<br />\n\t{client.first_name | e} {client.last_name | e}<br />\n\t{client.email | e}</p>\n<p>\n\t<a href=\"https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/\">Manage Service</a><br />\n\t<br />\n\tThe service may need to be suspended manually.<br />\n\t{% if errors %}{% for type in errors %}{% for error in type %}<br />\n\tError: {error | e}</p>\n<p>\n\t{% endfor %}{% endfor %}{% endif %}</p>"
            ],
            [
                'email_group_id' => 20,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Unsuspension Error',
                'text' => "Hi {staff.first_name | e},\n\nThere was an error with the {package.name | e} package, or its module does not support automatic unsuspension.\n\n{package.name | e} {service.name | e}\n--\n{client.id_code | e}\n{client.first_name | e} {client.last_name | e}\n{client.email | e}\n\nManage Service (https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/)\n\nThe service may need to be unsuspended manually.\n{% if errors %}{% for type in errors %}{% for error in type %}\nError: {error | e}\n\n{% endfor %}{% endfor %}{% endif %}",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n\tThere was an error with the {package.name | e} package, or its module does not support automatic unsuspension.<br />\n\t<br />\n\t{package.name | e} {service.name | e}<br />\n\t--<br />\n\t{client.id_code | e}<br />\n\t{client.first_name | e} {client.last_name | e}<br />\n\t{client.email | e}</p>\n<p>\n\t<a href=\"https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/\">Manage Service</a><br />\n\t<br />\n\tThe service may need to be unsuspended manually.<br />\n\t{% if errors %}{% for type in errors %}{% for error in type %}<br />\n\tError: {error | e}</p>\n<p>\n\t{% endfor %}{% endfor %}{% endif %}</p>"
            ],
            [
                'email_group_id' => 21,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Cancellation Error',
                'text' => "Hi {staff.first_name | e},\n\nThere was an error with the {package.name | e} package, or its module returned an error on cancellation.\n\n{package.name | e} {service.name | e}\n--\n{client.id_code | e}\n{client.first_name | e} {client.last_name | e}\n{client.email | e}\n\nManage Service (https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/)\n\nThe service may need to be cancelled manually.\n{% if errors %}{% for type in errors %}{% for error in type %}\nError: {error | e}\n\n{% endfor %}{% endfor %}{% endif %}",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n\tThere was an error with the {package.name | e} package, or its module returned an error on cancellation.<br />\n\t<br />\n\t{package.name | e} {service.name | e}<br />\n\t--<br />\n\t{client.id_code | e}<br />\n\t{client.first_name | e} {client.last_name | e}<br />\n\t{client.email | e}</p>\n<p>\n\t<a href=\"https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/\">Manage Service</a><br />\n\t<br />\n\tThe service may need to be cancelled manually.<br />\n\t{% if errors %}{% for type in errors %}{% for error in type %}<br />\n\tError: {error | e}</p>\n<p>\n\t{% endfor %}{% endfor %}{% endif %}</p>"
            ],
            [
                'email_group_id' => 22,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Scheduled Payment Reminder',
                'text' => "Hi {contact.first_name | e},\r\n\r\nThis is a reminder that a payment is scheduled to be charged to your account on {autodebit_date | e} in the amount of {amount_formatted | e} for invoice #{invoice.id_code | e}.\r\n\r\n{% if payment_account.account_type == \"ach\" %}Your {payment_account.type_name | e} Account ending in {payment_account.last4 | e} will be processed on this date. If we should use a different payment method, or if you need to make a change to this account, please login to our client area at https://{client_uri | e} to make the correction at your earliest convenience.{% else %}Your {payment_account.type_name | e} ending in {payment_account.last4 | e} will be processed on this date. If we should use a different payment method, or if you need to make a change to this account, please login to our client area at https://{client_uri | e} to make the correction at your earliest convenience.{% endif %}\r\n\r\nIf you'd like to disable automatic payments, you may do so by logging into your account at https://{client_uri | e}.\r\n\r\nThank you for your continued business!",
                'html' => "<p>\r\n\tHi {contact.first_name | e},</p>\r\n<p>\r\n\tThis is a reminder that a payment is scheduled to be charged to your account on {autodebit_date | e} in the amount of {amount_formatted | e} for invoice #{invoice.id_code | e}.</p>\r\n<p>\r\n\t{% if payment_account.account_type == \"ach\" %}Your {payment_account.type_name | e} Account ending in {payment_account.last4 | e} will be processed on this date. If we should use a different payment method, or if you need to make a change to this account, please login to our client area at https://{client_uri | e} to make the correction at your earliest convenience.{% else %}Your {payment_account.type_name | e} ending in {payment_account.last4 | e} will be processed on this date. If we should use a different payment method, or if you need to make a change to this account, please login to our client area at <a href=\"https://{client_uri | e}\">https://{client_uri | e}</a> to make the correction at your earliest convenience.{% endif %}<br />\r\n\t<br />\r\n\tIf you'd like to disable automatic payments, you may do so by logging into your account at <a href=\"https://{client_uri | e}\">https://{client_uri | e}</a>.<br />\r\n\t<br />\r\n\tThank you for your continued business!</p>\r\n"
            ],
            [
                'email_group_id' => 23,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Password Reset',
                'text' => "Hi {staff.first_name | escape | e},\r\n\r\nSomeone at the IP address {ip_address | e} requested a password reset for your account. If this was you, please visit the following URL to reset your password..\r\n\r\nhttps://{password_reset_url | e}\r\n\r\nIf you did not submit this request, please contact your system administrator. No action is required to keep your password the same.",
                'html' => "<p>\r\n\tHi {staff.first_name | escape | e},<br />\r\n\t<br />\r\n\tSomeone at the IP address <strong>{ip_address | e}</strong> requested a password reset for your account. If this was you, please visit the following URL to reset your password..<br />\r\n\t<br />\r\n\t<a href=\"https://{password_reset_url | e}\">https://{password_reset_url | e}</a><br />\r\n\t<br />\r\n\tIf you did not submit this request, please contact your system administrator. No action is required to keep your password the same.</p>\r\n"
            ],
            [
                'email_group_id' => 24,
                'lang' => 'en_us',
                'from' => 'sales@domain.com',
                'from_name' => 'Sales',
                'subject' => 'New Service Activated',
                'text' => "Hi {contact.first_name | e},\r\n\r\nYour service has been approved and activated. Please keep this email for your records.\r\n\r\n{package.email_text | e}",
                'html' => "<p>\r\n\tHi {contact.first_name | e},<br />\r\n\t<br />\r\n\tYour service has been approved and activated. Please keep this email for your records.<br />\r\n\t<br />\r\n\t{package.email_html}</p>\r\n"
            ],
            [
                'email_group_id' => 25,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Service Unsuspended',
                'text' => "Hi {contact.first_name | e},\n\nYour service, {package.name | e} - {service.name | e} has been unsuspended.",
                'html' => "<p>Hi {contact.first_name | e},</p>\n<p>Your service, {package.name | e} - {service.name | e} has been unsuspended.</p>"
            ],
            [
                'email_group_id' => 26,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Creation Error',
                'text' => "Hi {staff.first_name | e},\n\nThere was an error provisioning the following service:\n\n{package.name | e} {service.name | e}\n--\n{client.id_code | e}\n{client.first_name | e} {client.last_name | e}\n{client.email | e}\n\nManage Service (https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/)\n\nThe pending service may need to be modified so that it can be provisioned automatically or it may need to be provisioned manually.\n{% if errors %}{% for type in errors %}{% for error in type %}\nError: {error | e}\n\n{% endfor %}{% endfor %}{% endif %}",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n\tThere was an error provisioning the following service:<br />\n\t<br />\n\t{package.name | e} {service.name | e}<br />\n\t--<br />\n\t{client.id_code | e}<br />\n\t{client.first_name | e} {client.last_name | e}<br />\n\t{client.email | e}</p>\n<p>\n\t<a href=\"https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/\">Manage Service</a></p>\n<p>\n\tThe pending service may need to be modified so that it can be provisioned automatically or it may need to be provisioned manually.<br />\n\t{% if errors %}{% for type in errors %}{% for error in type %}<br />\n\tError: {error | e}</p>\n<p>\n\t{% endfor %}{% endfor %}{% endif %}</p>"
            ],
            [
                'email_group_id' => 27,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing',
                'subject' => 'Monthly Tax Liability Report',
                'text' => "Hi {staff.first_name | e},\n\nA Tax Liability Report has been generated for {company.name | e} and is attached to this email as a CSV file.",
                'html' => "<p>Hi {staff.first_name | e},</p>\n<p>A Tax Liability Report has been generated for {company.name | e} and is attached to this email as a CSV file.</p>"
            ],
            [
                'email_group_id' => 28,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Payment Received',
                'text' => "Hi {contact.first_name | e},\n\nWe have received your {transaction.gateway_name | e} payment in the amount of {transaction.amount | currency_format transaction.currency | e} and it has been applied to your account. Please keep this email as a receipt for your records.\n\nAmount: {transaction.amount | currency_format transaction.currency | e}\nTransaction Number: {transaction.transaction_id | e}\n\nThank you for your business!",
                'html' => "<p>\n\tHi {contact.first_name | e},<br />\n\t<br />\n\tWe have received your {transaction.gateway_name | e} payment in the amount of {transaction.amount | currency_format transaction.currency | e} and it has been applied to your account. Please keep this email as a receipt for your records.<br />\n\t<br />\n\tAmount: {transaction.amount | currency_format transaction.currency | e}<br />\n\tTransaction Number: {transaction.transaction_id | e}<br />\n\t<br />\n\tThank you for your business!</p>\r\n"
            ],
            [
                'email_group_id' => 29,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Service Canceled',
                'text' => "Hi {contact.first_name | e},\n\nYour service, {package.name | e} - {service.name | e}, has been canceled.",
                'html' => "<p>Hi {contact.first_name | e},</p>\n                            <p>Your service, {package.name | e} - {service.name | e}, has been canceled.</p>"
            ],
            [
                'email_group_id' => 30,
                'lang' => 'en_us',
                'from' => 'admin@domain.com',
                'from_name' => 'Admin',
                'subject' => 'Service Renewal Error',
                'text' => "Hi {staff.first_name | e},\n\nThere was an error renewing the following service:\n\n{package.name | e} {service.name | e}\n--\n{client.id_code | e}\n{client.first_name | e} {client.last_name | e}\n{client.email | e}\n\nManage Service (https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/)\n\nThe service may need to be modified so that it can be renewed automatically.\n{% if errors %}{% for type in errors %}{% for error in type %}\nError: {error | e}\n\n{% endfor %}{% endfor %}{% endif %}",
                'html' => "<p>\n\tHi {staff.first_name | e},<br />\n\t<br />\n    There was an error renewing the following service:<br />\n\t<br />\n\t{package.name | e} {service.name | e}<br />\n\t--<br />\n\t{client.id_code | e}<br />\n\t{client.first_name | e} {client.last_name | e}<br />\n\t{client.email | e}</p>\n<p>\n\t<a href=\"https://{admin_uri | e}clients/editservice/{client.id | e}/{service.id | e}/\">Manage Service</a></p>\n<p>\n    The service may need to be modified so that it can be renewed automatically.<br />\n\t{% if errors %}{% for type in errors %}{% for error in type %}<br />\n\tError: {error | e}</p>\n<p>\n\t{% endfor %}{% endfor %}{% endif %}</p>"
            ],
            [
                'email_group_id' => 31,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Scheduled Cancellation',
                'text' => "Hi {contact.first_name | e},\n\nYour service, {package.name | e} - {service.name | e}, has been scheduled for cancellation. If no action is taken, it will be cancelled on {service.date_canceled_formatted | e}. If you believe this action is in error, please contact us as soon as possible.",
                'html' => "<p>Hi {contact.first_name | e},</p>\n                            <p>Your service, {package.name | e} - {service.name | e}, has been scheduled for cancellation. If no action is taken, it will be cancelled on {service.date_canceled_formatted | e}. If you believe this action is in error, please contact us as soon as possible.</p>"
            ],
            [
                'email_group_id' => 32,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Forgot Username',
                'text' => "Hi {contact.first_name | escape | e},\n\nSomeone at the IP address {ip_address | e} requested the username for your account.\nThe username for your account is: {username | e}\n\nIf you did not make this request, you can safely ignore this email.",
                'html' => "<p>Hi {contact.first_name | escape | e},</p>\n                            <p>Someone at the IP address {ip_address | e} requested the username for your account.<br/>The username for your account is: <b>{username | e}</b></p>\n                            <p>If you did not make this request, you can safely ignore this email.</p>"
            ],
            [
                'email_group_id' => 33,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Verify your Email Address',
                'text' => "Hi {contact.first_name | e},\n\nSome features of your account may be restricted, or orders may be held, until your email address is verified. To verify your email address, please click the link below or copy and paste it into your browser.\nhttps://{verification_url | e}",
                'html' => "<p>Hi {contact.first_name | e},</p>\n                            <p>Some features of your account may be restricted, or orders may be held, until your email address is verified. To verify your email address, please click the link below or copy and paste it into your browser.<br />https://{verification_url | e}</p>"
            ],
            [
                'email_group_id' => 34,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Quote Created',
                'text' => "Hi {contact.first_name | e},\n\n{% for quotation in quotations %}\nA quote #{quotation.id_code | e} was created.\n{% endfor %}\nTo view it or approve login to your account at https://{client_url | e}login/.",
                'html' => "<p>Hi {contact.first_name | e},</p>\n{% for quotation in quotations %}\n<p>A quote #{quotation.id_code | e} was created.</p>\n{% endfor %}\n<p>To view it or approve login to your account at <a href=\"https://{client_url | e}login/\">https://{client_url | e}login/</a></p>"
            ],
            [
                'email_group_id' => 35,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'Quotation Approved',
                'text' => "Hi {staff.first_name | e},\n\n{% for quotation in quotations %}\nThe quote #{quotation.id_code | e} was approved!.\n{% endfor %}\nView or invoice the quote from the Staff interface.",
                'html' => "<p>Hi {staff.first_name | e},</p>\n{% for quotation in quotations %}\n<p>The quote #{quotation.id_code | e} was approved by {contact.first_name | e} {contact.last_name | e}!.</p>\n{% endfor %}\n<p>View or invoice the quote from the Staff interface.</p>"
            ],
            [
                'email_group_id' => 36,
                'lang' => 'en_us',
                'from' => 'billing@domain.com',
                'from_name' => 'Billing Department',
                'subject' => 'A user has invited you to manage their account',
                'text' => "Hi there,\n\n{contact.first_name | e} {contact.last_name | e} has invited you to manage their account.\n\nTo accept or reject this invitation, click the following link: https://{verification_url | e}/",
                'html' => "<p>Hi there,</p>\n\n<p>{contact.first_name | e} {contact.last_name | e} has invited you to manage their account.</p>\n\n<p>To accept or reject this invitation, click the following link: <a href=\"https://{verification_url | e}/\">https://{verification_url | e}/</a></p>"
            ]
        ];
    }

    /**
     * Populates email_snapshots with current email templates
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function saveCurrentEmailTemplates($undo = false)
    {
        if ($undo) {
            // Clear the history table
            $this->Record->from('email_snapshots')->truncate();
        } else {
            // Get all existing email templates
            $emails = $this->Record->select()
                ->from('emails')
                ->fetchAll();

            // Insert each email into the history table
            foreach ($emails as $email) {
                $this->Record->insert('email_snapshots', [
                    'email_id' => $email->id,
                    'lang' => $email->lang,
                    'from' => $email->from,
                    'from_name' => $email->from_name,
                    'subject' => $email->subject,
                    'text' => $email->text,
                    'html' => $email->html,
                    'date_saved' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Adds the snapshots limit configuration setting
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addSnapshotsLimitConfig($undo = false)
    {
        if ($undo) {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->removeConfig(CONFIGDIR . 'blesta.php', 'Blesta.snapshots_limit');
            }
        } else {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->addConfig(CONFIGDIR . 'blesta.php', 'Blesta.snapshots_limit', 10);
            }
        }
    }

    /**
     * Creates the ai_conversations table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addAiConversationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('ai_conversations');
        } else {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('staff_id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
                ->setField('title', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
                ->setField('type', ['type' => 'varchar', 'size' => 64, 'default' => 'chatbot'])
                ->setField('model', ['type' => 'varchar', 'size' => 64])
                ->setField('status', ['type' => 'enum', 'size' => "'active','archived'", 'default' => 'active'])
                ->setField('date_created', ['type' => 'datetime'])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id'], 'index')
                ->setKey(['staff_id'], 'index')
                ->setKey(['type'], 'index')
                ->setKey(['status'], 'index')
                ->setKey(['date_created'], 'index')
                ->create('ai_conversations', true);
        }
    }

    /**
     * Creates the ai_messages table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addAiMessagesTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('ai_messages');
        } else {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('conversation_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('role', ['type' => 'enum', 'size' => "'system','user','assistant'"])
                ->setField('content', ['type' => 'mediumtext'])
                ->setField('prompt_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('completion_tokens', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'is_null' => true, 'default' => null])
                ->setField('cost', ['type' => 'decimal', 'size' => '10,4', 'is_null' => true, 'default' => null])
                ->setField('date_created', ['type' => 'datetime'])
                ->setKey(['id'], 'primary')
                ->setKey(['conversation_id'], 'index')
                ->setKey(['role'], 'index')
                ->setKey(['date_created'], 'index')
                ->create('ai_messages', true);
        }
    }

    /**
     * Adds AI system settings
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addAiSettings($undo = false)
    {
        if ($undo) {
            $this->Record->from('settings')
                ->where('key', 'in', ['ai_enabled', 'ai_api_key', 'ai_default_model', 'ai_temperature', 'ai_max_tokens', 'ai_service_uuid', 'ai_service_id', 'ai_account_balance'])
                ->delete();
        } else {
            Loader::loadModels($this, ['Settings']);

            // Default settings
            $settings = [
                'ai_enabled' => 'false',
                'ai_api_key' => '',  // Will be encrypted
                'ai_default_model' => 'openai/gpt-5.2',
                'ai_temperature' => '0.7',
                'ai_max_tokens' => '2000',
                'ai_service_uuid' => '',
                'ai_service_id' => '',
                'ai_account_balance' => '0'
            ];

            foreach ($settings as $key => $value) {
                // Mark as encrypted only if there is a value to encrypt;
                // an empty value will be encrypted when a real key is set
                $encrypted = ($key === 'ai_api_key' && $value !== '') ? 1 : 0;
                $this->Settings->setSetting($key, $value, $encrypted);
            }
        }
    }

    /**
     * Adds AI permissions/actions
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addAiPermissions($undo = false)
    {
        if ($undo) {
            // Do nothing
        } else {
            Loader::loadModels($this, ['Permissions', 'StaffGroups']);
            Loader::loadComponents($this, ['Acl']);

            // Fetch all staff groups
            $staff_groups = $this->StaffGroups->getAll();

            // Determine comparable permission access
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                // Grant AI access to staff who can access system general settings
                $staff_group_access[$staff_group->id] = $this->Permissions->authorized(
                    'staff_group_' . $staff_group->id,
                    'admin_system_general',
                    '*',
                    'staff',
                    $staff_group->company_id
                );
            }

            $group = $this->Permissions->getGroupByAlias('admin_settings');

            // Add AI settings permissions
            if ($group) {
                $permissions = [
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_system_ai',
                    'alias' => 'admin_system_ai',
                    'action' => '*'
                ];
                $this->Permissions->add($permissions);

                foreach ($staff_groups as $staff_group) {
                    // If staff group has access to system general settings, grant access to AI settings
                    if ($staff_group_access[$staff_group->id]) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                    } else {
                        $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                    }
                }
            }
        }
    }

    /**
     * Adds the "icon" column to the "actions" table before "url"
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addActionsIconColumn($undo = false)
    {
        if ($undo) {
            $this->Record->query("ALTER TABLE `actions` DROP `icon`;");
        } else {
            // Add icon column after location and before url
            $this->Record->query(
                "ALTER TABLE `actions` ADD `icon` VARCHAR(255) NOT NULL DEFAULT 'bi bi-gear' AFTER `location`"
            );
        }
    }

    /**
     * Updates default icons for existing staff navigation actions
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateActionsIcons($undo = false)
    {
        if ($undo) {
            // Reset all icons to default
            $this->Record->where('location', '=', 'nav_staff')
                ->update('actions', ['icon' => 'bi bi-gear']);
        } else {
            // Map of action URLs to their corresponding icons
            $icon_map = [
                '' => 'bi bi-grid',
                'clients/' => 'bi bi-people',
                'billing/' => 'bi bi-receipt',
                'packages/' => 'bi bi-grid-3x3-gap',
                'tools/' => 'bi bi-tools',
                'plugin/support_manager/admin_main/' => 'bi bi-headset'
            ];

            // Update icons for each mapped action
            foreach ($icon_map as $url => $icon) {
                $this->Record->where('location', '=', 'nav_staff')
                    ->where('url', '=', $url)
                    ->update('actions', ['icon' => $icon]);
            }
        }
    }

    /**
     * Creates the "notifications" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addNotificationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('notifications');
        } else {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('action_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('user_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('icon', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
                ->setField('color', ['type' => 'varchar', 'size' => 20, 'is_null' => true, 'default' => null])
                ->setField('type', ['type' => 'enum', 'size' => "'info','success','warning','danger'", 'default' => 'info'])
                ->setField('title', ['type' => 'varchar', 'size' => 255])
                ->setField('message', ['type' => 'text'])
                ->setField('url', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('read', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
                ->setField('date_added', ['type' => 'datetime'])
                ->setKey(['user_id', 'read'], 'index')
                ->setKey(['id'], 'primary')
                ->create('notifications', true);
        }
    }

    /**
     * Creates the "notification_actions" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addNotificationActionsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('notification_actions');
        } else {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('dir', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
                ->setField('type', ['type' => 'enum', 'size' => "'system','plugin','module'", 'default' => 'system'])
                ->setField('target', ['type' => 'enum', 'size' => "'staff','client'", 'default' => 'staff'])
                ->setField('action', ['type' => 'varchar', 'size' => 255])
                ->setKey(['id'], 'primary')
                ->setKey(['company_id', 'action', 'target'], 'unique')
                ->create('notification_actions', true);
        }
    }

    /**
     * Creates the "staff_notifications" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addStaffNotificationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('staff_notifications');
        } else {
            $this->Record
                ->setField('staff_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('staff_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('action', ['type' => 'varchar', 'size' => 255])
                ->setKey(['staff_group_id', 'staff_id', 'action'], 'primary')
                ->create('staff_notifications', true);
        }
    }

    /**
     * Creates the "client_notifications" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addClientNotificationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('client_notifications');
        } else {
            $this->Record
                ->setField('client_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('client_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('action', ['type' => 'varchar', 'size' => 255])
                ->setKey(['client_group_id', 'client_id', 'action'], 'primary')
                ->create('client_notifications', true);
        }
    }

    /**
     * Creates the "staff_group_notifications" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addStaffGroupNotificationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('staff_group_notifications');
        } else {
            $this->Record
                ->setField('staff_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('action', ['type' => 'varchar', 'size' => 255])
                ->setKey(['staff_group_id', 'action'], 'primary')
                ->create('staff_group_notifications', true);
        }
    }

    /**
     * Creates the "client_group_notifications" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addClientGroupNotificationsTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('client_group_notifications');
        } else {
            $this->Record
                ->setField('client_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('action', ['type' => 'varchar', 'size' => 255])
                ->setKey(['client_group_id', 'action'], 'primary')
                ->create('client_group_notifications', true);
        }
    }

    /**
     * Adds default system notification actions for all companies
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addDefaultNotificationActions($undo = false)
    {
        $actions = [
            'service_suspension_error',
            'service_unsuspension_error',
            'service_cancel_error',
            'service_renewal_error',
            'service_creation_error',
            'service_uncancellation'
        ];

        if ($undo) {
            // Get notification action IDs for these system actions
            foreach ($actions as $action) {
                // Remove staff_group_notifications actions
                $this->Record->from('staff_group_notifications')
                    ->where('action', '=', $action)
                    ->delete();

                // Remove the notification actions
                $this->Record->from('notification_actions')
                    ->where('type', '=', 'system')
                    ->where('target', '=', 'staff')
                    ->where('action', '=', $action)
                    ->delete();
            }
        } else {
            // Get all companies
            $companies = $this->Record->select(['id'])
                ->from('companies')
                ->fetchAll();

            // Add notification actions for each company and staff group
            foreach ($companies as $company) {
                // Get all staff groups
                $staff_groups = $this->Record->select(['id'])
                    ->from('staff_groups')
                    ->where('company_id', '=', $company->id)
                    ->fetchAll();

                foreach ($actions as $action) {
                    $this->Record->insert('notification_actions', [
                        'company_id' => $company->id,
                        'dir' => null,
                        'type' => 'system',
                        'target' => 'staff',
                        'action' => $action
                    ]);

                    foreach ($staff_groups as $staff_group) {
                        $this->Record->insert('staff_group_notifications', [
                            'staff_group_id' => $staff_group->id,
                            'action' => $action
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Updates the default client group color from #dcdcdc to #708090
     * for better dark mode compatibility
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateDefaultClientGroupColor($undo = false)
    {
        if ($undo) {
            $this->Record->where('color', '=', '708090')
                ->update('client_groups', ['color' => 'dcdcdc']);
        } else {
            $this->Record->where('color', '=', 'dcdcdc')
                ->update('client_groups', ['color' => '708090']);
        }
    }

    /**
     * Assigns the default HTML email template to all emails that don't have one,
     * for each company
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function assignDefaultEmailHtmlTemplate($undo = false)
    {
        if ($undo) {
            // Do nothing - we can't reliably determine which emails were previously NULL
        } else {
            // Get all companies
            $companies = $this->Record->select(['id'])
                ->from('companies')
                ->fetchAll();

            foreach ($companies as $company) {
                // Find the first HTML template group for this company (the default)
                $template_group = $this->Record->select(['id'])
                    ->from('email_template_groups')
                    ->where('company_id', '=', $company->id)
                    ->order(['id' => 'asc'])
                    ->fetch();

                if (!$template_group) {
                    continue;
                }

                // Update all emails for this company that have no HTML template assigned
                $this->Record->where('company_id', '=', $company->id)
                    ->where('email_template_group_id', '=', null)
                    ->update('emails', ['email_template_group_id' => $template_group->id]);
            }
        }
    }

    /**
     * Initializes widget states for all existing staff members
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function initializeWidgetStates($undo = false)
    {
        if ($undo) {
            // No rollback needed - widget states are user preferences
            return;
        }

        // Load Staff model
        Loader::loadModels($this, ['Staff', 'PluginManager']);

        // Get all companies
        $companies = $this->Record->select(['id'])
            ->from('companies')
            ->fetchAll();

        // Initialize widget states for each company's staff members
        foreach ($companies as $company) {
            // Get all staff for this company
            $staff_members = $this->Record->select(['staff.id'])
                ->from('staff')
                ->innerJoin('staff_group', 'staff_group.staff_id', '=', 'staff.id', false)
                ->innerJoin('staff_groups', 'staff_groups.id', '=', 'staff_group.staff_group_id', false)
                ->where('staff_groups.company_id', '=', $company->id)
                ->fetchAll();

            // On fresh install no staff exist yet and Blesta.system_key may not be set,
            // which would make systemHash() throw. Skip the company entirely.
            if (empty($staff_members)) {
                continue;
            }

            $widgets = [
                'widget_staff_home' => [
                    $this->PluginManager->systemHash('widget_system_overview_admin_main') => [
                        'open' => true,
                        'width' => 'full',
                        'column' => null,
                        'order' => 0
                    ],
                    $this->PluginManager->systemHash('widget_feed_reader_admin_main') => [
                        'open' => true,
                        'width' => 'half',
                        'column' => 0,
                        'order' => 1
                    ],
                    $this->PluginManager->systemHash('widget_system_status_admin_main') => [
                        'open' => true,
                        'width' => 'half',
                        'column' => 0,
                        'order' => 2
                    ],
                ],
                'widget_staff_billing' => [
                    $this->PluginManager->systemHash('widget_billing_overview_admin_main') => [
                        'open' => true,
                        'width' => 'full',
                        'column' => null,
                        'order' => 0
                    ],
                    $this->PluginManager->systemHash('widget_order_admin_main') => [
                        'open' => true,
                        'width' => 'full',
                        'column' => null,
                        'order' => 1
                    ]
                ]
            ];
            foreach ($staff_members as $staff) {
                foreach ($widgets as $widget_location => $widgets_state) {
                    match ($widget_location) {
                        'widget_staff_home' => $this->Staff->saveHomeWidgetsState(
                            $staff->id,
                            $company->id,
                            $widgets_state
                        ),
                        'widget_staff_billing' => $this->Staff->saveBillingWidgetsState(
                            $staff->id,
                            $company->id,
                            $widgets_state
                        )
                    };
                }
            }
        }
    }

    /**
     * Replaces all admin themes with a single "SIX" default theme.
     * Deletes all existing admin themes (default and custom), inserts SIX,
     * and points all system/company settings to it.
     *
     * This migration cannot be reversed — deleted themes are not recoverable.
     *
     * @param bool $undo Whether to undo the upgrade (no-op, see note above)
     */
    private function removeStaffThemeColors($undo = false)
    {
        if ($undo) {
            // Cannot be reversed: all previous admin themes were deleted on upgrade
        } else {
            // 1. Delete all admin themes (default and custom)
            $this->Record->from('themes')
                ->where('type', '=', 'admin')
                ->delete();

            // 2. Insert new SIX default admin theme
            $six_data = base64_encode(json_encode([
                'colors' => [],
                'logo_url' => '',
                'custom_css' => ''
            ]));

            $this->Record->insert('themes', [
                'company_id' => null,
                'name' => 'SIX',
                'type' => 'admin',
                'data' => $six_data
            ]);
            $six_theme_id = $this->Record->lastInsertId();

            // 3. Update the system default admin theme to point to SIX
            $this->Record->where('key', '=', 'theme_admin')
                ->update('settings', ['value' => $six_theme_id]);

            // 4. Point all companies to SIX
            $this->Record->where('key', '=', 'theme_admin')
                ->update('company_settings', ['value' => $six_theme_id]);
        }
    }

    /**
     * Migrates admin_view_dir from 'default' to 'paradigm' in both
     * company_settings and the blesta.php config file
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function migrateAdminViewDirToParadigm($undo = false)
    {
        if ($undo) {
            $this->Record->where('key', '=', 'admin_view_dir')
                ->where('value', '=', 'paradigm')
                ->update('company_settings', ['value' => 'default']);

            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->editConfig(CONFIGDIR . 'blesta.php', 'Blesta.default_admin_view_template', "'default'");
            }
        } else {
            $this->Record->where('key', '=', 'admin_view_dir')
                ->where('value', '=', 'default')
                ->update('company_settings', ['value' => 'paradigm']);

            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->editConfig(CONFIGDIR . 'blesta.php', 'Blesta.default_admin_view_template', "'paradigm'");
            }
        }
    }

    /**
     * Adds the `image` column to the `package_option_values` table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPackageOptionValueImageColumn($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `package_option_values` DROP COLUMN IF EXISTS `image`');
        } else {
            $this->Record->query(
                'ALTER TABLE `package_option_values` ADD `image` VARCHAR(255) NULL DEFAULT NULL AFTER `step`'
            );
        }
    }

    /**
     * Adds a new "data" column to the "service_invoices" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addDataColumnServiceInvoices($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `service_invoices` DROP `data`;');
        } else {
            $this->Record->query('ALTER TABLE `service_invoices` ADD `data` MEDIUMTEXT NULL DEFAULT NULL;');
        }
    }

    /**
     * Updates the "type" column to the "service_invoices" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateTypeColumnServiceInvoices($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `service_invoices` DROP INDEX `service_invoice_type`;');
            $this->Record->query('ALTER TABLE `service_invoices` DROP COLUMN `id`;');
            $this->Record->query("ALTER TABLE `service_invoices`
                CHANGE `type` `type` ENUM( 'provisioning','renewal','suspension','unsuspension','cancelation' )
                    CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'renewal';");
            // Restore the (service_id, invoice_id) unique key that existed before this migration
            $this->Record->query('ALTER TABLE `service_invoices` ADD UNIQUE KEY (`service_id`, `invoice_id`);');
        } else {
            $this->Record->query("ALTER TABLE `service_invoices`
                CHANGE `type` `type` ENUM( 'provisioning','renewal','suspension','unsuspension','cancelation','change' )
                    CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT 'renewal';");
            // Drop the old (service_id, invoice_id) unique key before adding the new (service_id, invoice_id, type) key
            $this->Record->query('ALTER TABLE `service_invoices` DROP INDEX `service_id`;');
            $this->Record->query('ALTER TABLE `service_invoices` ADD `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY;');
            $this->Record->query('ALTER TABLE `service_invoices`
                ADD UNIQUE KEY `service_invoice_type` (`service_id`, `invoice_id`, `type`);');
        }
    }

    /**
     * Migrate service changes to the "service_invoices" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function migrateServiceChanges($undo = false)
    {
        $maximum_attempts = 24;

        if ($undo) {
            $this->Record->query("DELETE FROM `company_settings` WHERE `key`='service_change_attempts';");

            // Create "service_changes" table
            $this->Record->
                setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
                setField('service_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('invoice_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField(
                    'status',
                    ['type' => 'enum', 'size' => "'pending','completed','canceled','error'", 'default' => 'pending']
                )->
                setField('data', ['type' => 'mediumtext'])->
                setField('date_added', ['type' => 'datetime'])->
                setField('date_status', ['type' => 'datetime'])->
                setKey(['service_id'], 'index')->
                setKey(['invoice_id'], 'unique')->
                setKey(['status', 'date_status'], 'index')->
                setKey(['id'], 'primary')->
                create('service_changes', true);

            // Restore service changes
            $service_changes = $this->Record->select()->from('service_invoices')
                ->where('service_invoices.type', '=', 'change')
                ->fetchAll();
            foreach ($service_changes as $service_change) {
                $vars = [
                    'invoice_id' => $service_change->invoice_id,
                    'service_id' => $service_change->service_id,
                    'status' => ($service_change->failed_attempts >= $maximum_attempts) ? 'error' : 'pending',
                    'data' => $service_change->data,
                    'date_added' => $service_change->date_next_attempt,
                    'date_status' => $service_change->date_next_attempt
                ];
                $this->Record->insert('service_changes', $vars);
            }

            // Remove migrated rows from "service_invoices" so the type enum can be shrunk
            $this->Record->from('service_invoices')
                ->where('type', '=', 'change')
                ->delete();
        } else {
            $companies = $this->Record->select()->from('companies')->fetchAll();
            foreach ($companies as $company) {
                $this->Record->query(
                    "INSERT INTO `company_settings` (`key`, `company_id`, `value`)
                        VALUES ('service_change_attempts', ?, '{$maximum_attempts}');",
                    $company->id
                );
            }

            // Migrate service changes
            $service_changes = $this->Record->select()->from('service_changes')->fetchAll();
            foreach ($service_changes as $service_change) {
                if ($service_change->status == 'completed' || $service_change->status == 'canceled') {
                    continue;
                }

                $vars = [
                    'invoice_id' => $service_change->invoice_id,
                    'service_id' => $service_change->service_id,
                    'type' => 'change',
                    'failed_attempts' => ($service_change->status == 'error') ? $maximum_attempts : 0,
                    'maximum_attempts' => $maximum_attempts,
                    'data' => $service_change->data,
                    'date_next_attempt' => $service_change->date_status
                ];
                $this->Record->duplicate('data', '=', $service_change->data)
                    ->insert('service_invoices', $vars);
            }

            // Drop "service_changes" table
            $this->Record->drop('service_changes');
        }
    }

    /**
     * Adds the query logging configuration setting
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addQueryLoggingConfig($undo = false)
    {
        if ($undo) {
            // No rollback possible for config file settings
        } else {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $this->addConfig(CONFIGDIR . 'blesta.php', 'System.query_logging', false);
            }
        }
    }

    /**
     * Adds all available notices to staff group 1 if it has no BCC or CC notices assigned
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addDefaultStaffGroupNotices($undo = false)
    {
        if ($undo) {
            return;
        }

        // Get all email groups with notice type 'bcc' or 'to'
        $email_groups = $this->Record->select(['action'])
            ->from('email_groups')
            ->where('notice_type', 'in', ['bcc', 'to'])
            ->fetchAll();

        if (empty($email_groups)) {
            return;
        }

        // Skip if staff group 1 already has any bcc/to notices assigned.
        // numResults() wraps the query as SELECT COUNT(*) FROM (<query>) AS t,
        // so SELECT * here would produce two `action` columns in the derived
        // table from the join. Pick a single explicit column instead.
        $existing = $this->Record->select(['staff_group_notices.staff_group_id'])
            ->from('staff_group_notices')
            ->innerJoin('email_groups', 'email_groups.action', '=', 'staff_group_notices.action', false)
            ->where('staff_group_notices.staff_group_id', '=', 1)
            ->where('email_groups.notice_type', 'in', ['bcc', 'to'])
            ->numResults();

        if ($existing > 0) {
            return;
        }

        // Add all notices to staff group 1
        foreach ($email_groups as $email_group) {
            $this->Record->duplicate('action', '=', $email_group->action)
                ->insert('staff_group_notices', [
                    'staff_group_id' => 1,
                    'action' => $email_group->action
                ]);
        }
    }

    /**
     * Adds a permission for the system integrity check page
     *
     * @param bool $undo Whether to add or undo the change
     */
    private function addIntegrityCheckPermissions($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            // Get the admin tools permission group
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);
            $group = $this->Permissions->getGroupByAlias('admin_tools');

            // Determine comparable permission access: grant to staff who can access utilities
            $staff_groups = $this->Record->select()->from('staff_groups')->fetchAll();
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = $this->Permissions->authorized(
                    'staff_group_' . $staff_group->id,
                    'admin_tools',
                    'utilities',
                    'staff',
                    $staff_group->company_id
                );
            }

            // Add the system integrity check permission
            if ($group) {
                $this->Permissions->add([
                    'group_id' => $group->id,
                    'name' => 'StaffGroups.permissions.admin_tools_integritycheck',
                    'alias' => 'admin_tools',
                    'action' => 'integritycheck'
                ]);

                foreach ($staff_groups as $staff_group) {
                    if ($staff_group_access[$staff_group->id]) {
                        $this->Acl->allow('staff_group_' . $staff_group->id, 'admin_tools', 'integritycheck');
                    }
                }
            }
        }
    }

    /**
     * Creates the cache_blesta directory above the web root and copies CACHEDIR contents into it
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function createCacheDirectory($undo = false)
    {
        $marker_file = ROOTWEBDIR . 'config' . DS . 'cache.dir.php';

        if ($undo) {
            $this->Record->from('settings')
                ->where('key', '=', 'cache_dir')
                ->delete(['settings.*']);

            if (is_file($marker_file)) {
                @unlink($marker_file);
            }

            return;
        }

        // Calculate path above docroot
        $webdir = str_replace('/', DS, str_replace('index.php/', '', WEBDIR));
        $public_root_web = rtrim(str_replace($webdir == DS ? '' : $webdir, '', ROOTWEBDIR), DS) . DS;
        $cache_dir = realpath(dirname($public_root_web)) . DS . 'cache_blesta' . DS;

        if (!file_exists($cache_dir)) {
            @mkdir($cache_dir, 0755);
        }

        // Copy existing cache contents to new location
        if (is_dir($cache_dir) && is_writable($cache_dir)) {
            $old_cache = ROOTWEBDIR . 'cache' . DS;
            $items = @scandir($old_cache);
            if ($items) {
                foreach ($items as $item) {
                    if (in_array($item, ['.', '..', '.htaccess', 'index.php'])) {
                        continue;
                    }

                    $src = $old_cache . $item;
                    $dst = $cache_dir . $item;
                    if (!file_exists($dst)) {
                        @rename($src, $dst);
                    }
                }
            }
        }

        // Always ensure the .htaccess defense-in-depth file is present when the dir exists,
        // even if cache_blesta/ was created on a prior partial run.
        if (is_dir($cache_dir) && is_writable($cache_dir)) {
            $htaccess = <<<HTACCESS
Order deny,allow
Deny from all
HTACCESS;
            @file_put_contents($cache_dir . '.htaccess', $htaccess);
        }

        // Save the new cache_dir setting (used by the admin UI)
        $this->Record->insert('settings', ['key' => 'cache_dir', 'value' => $cache_dir]);

        // Write the marker file that Bootstrap.php and MinphpBridge.php read at boot.
        // The DB row alone isn't readable from the constants closure due to a circular
        // bootstrap dependency (pdo -> Configure -> minphp.config -> minphp.constants).
        $marker_contents = "<?php\nreturn " . var_export($cache_dir, true) . ";\n";
        @file_put_contents($marker_file, $marker_contents);
    }

    /**
     * Converts the database, all tables, and all columns to utf8mb4_unicode_ci,
     * and updates the blesta.php config charset_query accordingly.
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function convertDatabaseToUtf8mb4($undo = false)
    {
        if ($undo) {
            // No practical rollback for charset conversion
            return;
        }

        // Only run during upgrades, not fresh installs (schema.sql already uses utf8mb4)
        if ($this->getEnvironment() !== 'upgrade') {
            return;
        }

        set_time_limit(0);

        $database_info = Configure::get('Blesta.database_info');

        // Check for non-utf8mb4 tables
        $non_utf8mb4_tables = $this->Record->select(['table_name' => 'name'])
            ->from('information_schema.tables')
            ->where('TABLE_SCHEMA', '=', $database_info['database'])
            ->where('TABLE_COLLATION', '!=', 'utf8mb4_unicode_ci')
            ->fetchAll();

        // Check for non-utf8mb4 columns (catches partial conversions where the
        // table default was changed but individual columns were not)
        $non_utf8mb4_columns = $this->Record->select(['table_name' => 'name'])
            ->from('information_schema.columns')
            ->where('TABLE_SCHEMA', '=', $database_info['database'])
            ->where('COLLATION_NAME', '!=', 'utf8mb4_unicode_ci')
            ->where('COLLATION_NAME', '!=', null)
            ->group(['table_name'])
            ->fetchAll();

        // Merge the two lists into a unique set of tables that need conversion
        $tables_to_convert = [];
        foreach ($non_utf8mb4_tables as $table) {
            $tables_to_convert[$table->name] = true;
        }
        foreach ($non_utf8mb4_columns as $table) {
            $tables_to_convert[$table->name] = true;
        }

        // Convert tables and columns if any need it
        if (!empty($tables_to_convert)) {
            // Convert the database default charset
            $this->Record->query(
                'ALTER DATABASE `' . $database_info['database']
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            // Convert each table (CONVERT TO also converts all columns)
            foreach ($tables_to_convert as $table_name => $unused) {
                $this->Record->query(
                    'ALTER TABLE `' . $database_info['database'] . '`.`' . $table_name
                    . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            }
        }

        // Always update blesta.php config charset_query, even if tables were
        // already converted (e.g. via Tools > Utilities which may not have
        // updated the config, or a partial prior conversion)
        try {
            if (file_exists(CONFIGDIR . 'blesta.php')) {
                $file_config = file_get_contents(CONFIGDIR . 'blesta.php');
                $updated_config = str_replace(
                    "SET NAMES 'utf8'",
                    "SET NAMES 'utf8mb4'",
                    $file_config
                );
                file_put_contents(CONFIGDIR . 'blesta.php', $updated_config);
            }
        } catch (Throwable $e) {
            // Config update is non-fatal; can be done manually or via Tools > Utilities
        }
    }
}
