<?php
/**
 * Upgrades to version 5.11.0-b1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_11_0B1 extends UpgradeUtil
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
            'addEmailTemplatesTable',
            'addTemplateIdEmailGroupsTable',
            'addEmailTemplatesPermissions',
            'addDefaultEmailTemplate',
            'addLogServiceChangesTable',
            'addEditServiceAdvancedPermissions',
            'updateFiveTheme',
            'addPackagesDataFeed',
            'addAttemptsSetting',
            'addServiceAttemptSpacingSettings',
            'updateServicesInvoicesTable',
            'addServiceInvoicesForExistingServices',
            'updateClientGroupSettingKeyColumnLength',
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
     * Adds a new "email_attachments" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addEmailTemplatesTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('email_templates');
            $this->Record->drop('email_template_groups');
        } else {
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('email_template_group_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('lang', ['type'=>'char', 'size'=>5])->
                setField('html', ['type'=>'text'])->
                setKey(['id'], 'primary')->
                create('email_templates', true);

            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('name', ['type'=>'varchar', 'size'=>255])->
                setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setKey(['id'], 'primary')->
                create('email_template_groups', true);
        }
    }

    /**
     * Adds a new "template_id" column to the "email_groups" table
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addTemplateIdEmailGroupsTable($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `emails` DROP `email_template_group_id`;');
        } else {
            $this->Record->query('ALTER TABLE `emails` ADD `email_template_group_id` INT UNSIGNED NULL DEFAULT NULL AFTER `email_signature_id`;');
        }
    }

    /**
     * Adds a new permission for the Email Templates settings page
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addEmailTemplatesPermissions($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);

            // Fetch all staff groups
            $staff_groups = $this->Record->select(['staff_groups.*'])
                ->from('staff_groups')
                ->group(['staff_groups.id'])
                ->fetchAll();

            // Determine comparable permission access
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = [
                    'admin_company_emails' => $this->Permissions->authorized(
                        'staff_group_' . $staff_group->id,
                        'admin_company_emails',
                        '*',
                        'staff',
                        $staff_group->company_id
                    )
                ];
            }
            $group = $this->Permissions->getGroupByAlias('admin_settings');

            // Add the new email templates permission
            if ($group) {
                $actions = [
                    'htmltemplates', 'addhtmltemplate', 'edithtmltemplate', 'deletehtmltemplate'
                ];
                foreach ($actions as $action) {
                    $permissions = [
                        'group_id' => $group->id,
                        'name' => 'StaffGroups.permissions.admin_company_emails_' . $action,
                        'alias' => 'admin_company_emails',
                        'action' => $action
                    ];
                    $this->Permissions->add($permissions);

                    foreach ($staff_groups as $staff_group) {
                        // If staff group has access to similar item, grant access to this item
                        if ($staff_group_access[$staff_group->id][$permissions['alias']]) {
                            $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        } else {
                            $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Adds a new permission for the Email Templates settings page
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    public function addDefaultEmailTemplate($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            Loader::loadModels($this, ['Languages', 'EmailHtmlTemplates', 'Companies']);

            $html_template = <<<'EOD'
<!doctype html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
    <head>
        <title>
        </title>
        <!--[if !mso]><!-- -->
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <!--<![endif]-->
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!--[if !mso]><!-->
        <!--<![endif]-->
        <!--[if mso]>
        <xml>
            <o:OfficeDocumentSettings>
                <o:AllowPNG/>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
        <![endif]-->
        <!--[if lte mso 11]>
        <style type="text/css">
            .outlook-group-fix { width:100% !important; }
        </style>
        <![endif]-->
    </head>
    <body style="background-color: #f9f9f9;margin: 0;padding: 0;-webkit-text-size-adjust: 100%;-ms-text-size-adjust: 100%;">
        <div style="background-color:#f9f9f9;">
            <!--[if mso | IE]>
            <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:600px;" width="600">
                <tr>
                    <td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;">
            <![endif]-->
            <div style="background:#f9f9f9;background-color:#f9f9f9;Margin:0px auto;max-width:600px;">
                <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background: #f9f9f9;background-color: #f9f9f9;width: 100%;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                    <tbody>
                    <tr>
                        <td style="border-bottom: #333957 solid 5px;direction: ltr;font-size: 0px;padding: 20px 0;text-align: center;vertical-align: top;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                            <!--[if mso | IE]>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]>
            </td>
            </tr>
            </table>
            <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:600px;" width="600">
                <tr>
                    <td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;">
            <![endif]-->
            <div style="background:#fff;background-color:#fff;Margin:0px auto;max-width:600px;">
                <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background: #fff;background-color: #fff;width: 100%;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                    <tbody>
                    <tr>
                        <td style="border: #dddddd solid 1px;border-top: 0px;direction: ltr;font-size: 0px;padding: 20px 0;text-align: center;vertical-align: top;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                            <!--[if mso | IE]>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td
                                            style="vertical-align:bottom;width:600px;"
                                    >
                            <![endif]-->
                            <div class="mj-column-per-100 outlook-group-fix" style="font-size:13px;text-align:left;direction:ltr;display:inline-block;vertical-align:bottom;width:100%;">
                                <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="vertical-align: bottom;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;" width="100%">
                                    <tr>
                                        <td align="left" style="font-size: 0px;padding: 10px 25px;word-break: break-word;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                            <div style="font-family:'Helvetica Neue',Arial,sans-serif;font-size:16px;line-height:22px;text-align:left;color:#555;">
                                                {{email_body}}
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td align="left" style="font-size: 0px;padding: 10px 25px;word-break: break-word;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                            <div style="font-family:'Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:20px;text-align:left;color:#525252;">
                                                {{signature}}
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <!--[if mso | IE]>
                            </td>
                            </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]>
            </td>
            </tr>
            </table>
            <table align="center" border="0" cellpadding="0" cellspacing="0" style="width:600px;" width="600">
                <tr>
                    <td style="line-height:0px;font-size:0px;mso-line-height-rule:exactly;">
            <![endif]-->
            <div style="Margin:0px auto;max-width:600px;">
                <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                    <tbody>
                    <tr>
                        <td style="direction: ltr;font-size: 0px;padding: 20px 0;text-align: center;vertical-align: top;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                            <!--[if mso | IE]>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td
                                            style="vertical-align:bottom;width:600px;"
                                    >
                            <![endif]-->
                            <div class="mj-column-per-100 outlook-group-fix" style="font-size:13px;text-align:left;direction:ltr;display:inline-block;vertical-align:bottom;width:100%;">
                                <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%" style="border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                    <tbody>
                                    <tr>
                                        <td style="vertical-align: bottom;padding: 0;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                            <table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%" style="border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                                <tr>
                                                    <td align="center" style="font-size: 0px;padding: 0;word-break: break-word;border-collapse: collapse;mso-table-lspace: 0pt;mso-table-rspace: 0pt;">
                                                        <div style="font-family:'Helvetica Neue',Arial,sans-serif;font-size:12px;font-weight:300;line-height:1;text-align:center;color:#575757;">
                                                            Sent by Blesta.
                                                        </div>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!--[if mso | IE]>
                            </td>
                            </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <!--[if mso | IE]>
            </td>
            </tr>
            </table>
            <![endif]-->
        </div>
    </body>
</html>
EOD;

            $companies = $this->Companies->getAll();
            foreach ($companies as $company) {
                $templates = [];

                // Fetch company languages
                $languages = $this->Languages->getAll($company->id);
                foreach ($languages as $language) {
                    $templates[] = [
                        'lang' => $language->code,
                        'html' => $html_template
                    ];
                }

                // Add email html template group
                $params = [
                    'name' => 'EmailHtmlTemplates.template.name.default',
                    'company_id' => $company->id,
                    'templates' => $templates
                ];
                $group_id = $this->EmailHtmlTemplates->addGroup($params);
            }
        }
    }

    /**
     * Adds a new "log_service_changes" table
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addLogServiceChangesTable($undo = false)
    {
        if ($undo) {
            $this->Record->drop('log_service_changes');
        } else {
            $this->Record->
                setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])->
                setField('service_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])->
                setField('transactions', ['type'=>'text'])->
                setField('old_service', ['type'=>'text'])->
                setField('new_service', ['type'=>'text'])->
                setField('date_changed', ['type' => 'datetime'])->
                setKey(['id'], 'primary')->
                create('log_service_changes', true);

            // Add setting only during upgrade
            if ($this->getEnvironment() == 'upgrade' && file_exists(CONFIGDIR . 'blesta.php')) {
                $this->addConfig(CONFIGDIR . 'blesta.php', 'Blesta.auto_delete_service_changes_logs', false);
            }
        }
    }

    /**
     * Adds a new permission for the Advanced Options tab under Edit Service
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function addEditServiceAdvancedPermissions($undo = false)
    {
        if ($undo) {
            // Do Nothing
        } else {
            Loader::loadModels($this, ['Permissions']);
            Loader::loadComponents($this, ['Acl']);

            // Fetch all staff groups
            $staff_groups = $this->Record->select(['staff_groups.*'])
                ->from('staff_groups')
                ->group(['staff_groups.id'])
                ->fetchAll();

            // Determine comparable permission access
            $staff_group_access = [];
            foreach ($staff_groups as $staff_group) {
                $staff_group_access[$staff_group->id] = [
                    'admin_clients' => $this->Permissions->authorized(
                        'staff_group_' . $staff_group->id,
                        'admin_clients',
                        'editservice',
                        'staff',
                        $staff_group->company_id
                    )
                ];
            }

            $group = $this->Permissions->getGroupByAlias('admin_clients');

            // Add the new advanced edit service permission
            if ($group) {
                $actions = [
                    'editserviceadvanced'
                ];
                foreach ($actions as $action) {
                    $permissions = [
                        'group_id' => $group->id,
                        'name' => 'StaffGroups.permissions.admin_clients_' . $action,
                        'alias' => 'admin_clients',
                        'action' => $action
                    ];
                    $this->Permissions->add($permissions);

                    foreach ($staff_groups as $staff_group) {
                        // If staff group has access to similar item, grant access to this item
                        if ($staff_group_access[$staff_group->id][$permissions['alias']]) {
                            $this->Acl->allow('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        } else {
                            $this->Acl->deny('staff_group_' . $staff_group->id, $permissions['alias'], $permissions['action']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Updates the default "FIVE" theme
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function updateFiveTheme($undo = false)
    {
        if ($undo) {
            // Nothing to do
        } else {
            Loader::loadModels($this, ['Themes']);

            $themes = $this->Themes->getAll('client');
            foreach ($themes as $theme) {
                if ($theme->company_id == null) {
                    $custom_css = <<<'CSS'
body {
    background-color: #f0f0f0;
}
body[class^="plugin-order"],
body[class^="plugin-support_managerclient-knowledgebase"] {
    background-color: #ffffff;
}
body.plugin-orderclient-orders-index {
    background-color: #f0f0f0;
}
body.client-client_login-index {
    background-color: #ffffff;
}
.nav-content .navbar {
    font-size: 1rem;
}
.nav-content .container, .nav-content .container-md {
    padding: 10px 5px;
}
.top-nav .dropdown a.dropdown-toggle, .top-nav .dropdown a.dropdown-toggle:focus {
    color: #8c8c8c !important;
}
.top-nav .dropdown a.dropdown-toggle:hover {
    color: #2e3338 !important;
}
.title {
    height: 60px;
}
.title h3 {
    line-height: 60px;
}
.cards .card {
    border-bottom: .4em solid #2e33388a;
}
.card-blesta>.card-header, .panel .card-blesta>.panel-heading {
    padding: 14px 15px;
    font-size: 1rem;
}
.cards .card .card-content {
    width: 100%;
    padding: 10px;
}
.alert {
    padding: 1.25rem;
}
.footer {
    min-height: 90px;
}
CSS;

                    // Update theme CSS
                    $theme_options = [
                        'colors' => $theme->colors,
                        'logo_url' => $theme->logo_url,
                        'custom_css' => $custom_css
                    ];

                    $data = base64_encode(serialize($theme_options));
                    $this->Record->where('id', '=', $theme->id)->update('themes', ['data' => $data], ['data']);
                }
            }
        }
    }
    
    /**
     * Adds the data feed for packages
     *
     * @param bool $undo Whether to undo the upgrade
     */
    private function addPackagesDataFeed($undo = false)
    {
        Loader::loadModels($this, ['Companies']);

        $feed = [
            'feed' => 'package',
            'class' => '\\Blesta\\Core\\Util\\DataFeed\\Feeds\\PackageFeed',
            'endpoints' => [
                ['feed' => 'package', 'endpoint' => 'quantity'],
                ['feed' => 'package', 'endpoint' => 'clientlimit']
            ]
        ];

        if ($undo) {
            // Delete service data feed
            $this->Record->from('data_feed_endpoints')->where('feed', '=', $feed['feed'])->delete();
        } else {
            // Add service data feed
            $endpoints = $feed['endpoints'];
            unset($feed['endpoints']);

            // Fetch all companies
            $companies = $this->Companies->getAll();
            foreach ($endpoints as $endpoint) {
                foreach ($companies as $company) {
                    $endpoint['company_id'] = $company->id;
                    $this->Record->insert('data_feed_endpoints', $endpoint);
                }
            }
        }
    }
    
    /**
     * Adds the company settings for Service Provisioning Attempts
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function addAttemptsSetting($undo = false)
    {
        if ($undo) {
            $this->Record->query("DELETE FROM `company_settings` WHERE `key`='service_provisioning_attempts';");
            $this->Record->query("DELETE FROM `company_settings` WHERE `key`='service_suspension_attempts';");
            $this->Record->query("DELETE FROM `company_settings` WHERE `key`='service_unsuspension_attempts';");
            $this->Record->query("DELETE FROM `company_settings` WHERE `key`='service_cancelation_attempts';");
        } else {
            $companies = $this->Record->select()->from('companies')->fetchAll();
            foreach ($companies as $company) {
                $this->Record->query(
                    "INSERT INTO `company_settings` (`key`, `company_id`, `value`)
                        VALUES ('service_provisioning_attempts', ?, '24');",
                    $company->id
                );
                $this->Record->query(
                    "INSERT INTO `company_settings` (`key`, `company_id`, `value`)
                        VALUES ('service_suspension_attempts', ?, '24');",
                    $company->id
                );
                $this->Record->query(
                    "INSERT INTO `company_settings` (`key`, `company_id`, `value`)
                        VALUES ('service_unsuspension_attempts', ?, '24');",
                    $company->id
                );
                $this->Record->query(
                    "INSERT INTO `company_settings` (`key`, `company_id`, `value`)
                        VALUES ('service_cancelation_attempts', ?, '24');",
                    $company->id
                );
            }
        }
    }
    
    /**
     * Adds the company settings for Service attempt Spacing
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function addServiceAttemptSpacingSettings($undo = false)
    {
        Loader::loadModels($this, ['Companies']);
        $companies = $this->Companies->getAll();

        $service_attempt_types = ['provisioning', 'renewal', 'cancelation', 'suspension', 'unsuspension'];
        $service_attempt_fields = [];
        foreach ($service_attempt_types as $service_attempt_type) {
            $service_attempt_fields['first_' . $service_attempt_type . '_attempt_threshold'] = 6;
            $service_attempt_fields['first_' . $service_attempt_type . '_attempt_spacing'] = 1;
            $service_attempt_fields['second_' . $service_attempt_type . '_attempt_threshold'] = 12;
            $service_attempt_fields['second_' . $service_attempt_type . '_attempt_spacing'] = 24;
        }

        if ($undo) {
            // Remove company settings
            foreach ($companies as $company) {
                foreach ($service_attempt_types as $field => $value) {
                    $this->Companies->unsetSetting($company->id, $field);
                }
            }
        } else {
            // Update company settings
            foreach ($companies as $company) {
                
                foreach ($service_attempt_fields as $field => $value) {
                    $this->Companies->setSetting($company->id, $field, $value);
                }
            }
        }
    }

    /**
     * Updates the service_invoices.invoice_id field from NOT NULL to NULL
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function updateServicesInvoicesTable($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `service_invoices` DROP `type`');
            $this->Record->query('ALTER TABLE `service_invoices` CHANGE `invoice_id` `invoice_id` INT( 10 );');
        } else {
            $this->Record->query('ALTER TABLE `service_invoices` DROP PRIMARY KEY;');
            $this->Record->query('ALTER TABLE `service_invoices` ADD UNIQUE KEY (`service_id`, `invoice_id`);');
            $this->Record->query('ALTER TABLE `service_invoices` CHANGE `invoice_id` `invoice_id` INT( 10 ) NULL DEFAULT NULL;');
            $this->Record->query('ALTER TABLE `service_invoices` ADD `type` ENUM("provisioning","renewal","suspension","unsuspension","cancelation") NOT NULL DEFAULT "renewal" AFTER `service_id`;');
        }
    }

    /**
     * Adds a service invoice for all previous paid pending services
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function addServiceInvoicesForExistingServices($undo = false)
    {
        if ($undo) {
            // Nothing to do
        } else {
            Loader::loadModels($this, ['ClientGroups', 'SettingsCollection', 'Services', 'ServiceInvoices']);

            $companies = $this->Record->select()->from('companies')->fetchAll();
            $default_max_attempts = 24;
            foreach ($companies as $company) {
                // Get all client groups for this company
                $client_groups = $this->ClientGroups->getAll($company->id);

                foreach ($client_groups as $client_group) {
                    $type = [
                        'provisioning' => $this->Services->getPendingPaidQuery(false, false, $client_group->id)->fetchAll(),
                        'suspension' => $this->Services->getPendingSuspensionQuery(false, false, $client_group->id)->fetchAll(),
                        'unsuspension' => $this->Services->getPendingUnsuspensionQuery(false, false, $client_group->id)->fetchAll(),
                        'cancelation' => $this->Services->getPendingCancelationQuery(false, false)->fetchAll()
                    ];
                    foreach ($type as $type => $services) {
                        foreach ($services as $service) {
                            $vars = [
                                'invoice_id' => $service->invoice_id ?? null,
                                'service_id' => $service->id,
                                'type' => $type,
                                'maximum_attempts' => $default_max_attempts
                            ];
                            $this->Record->duplicate('service_id', '=', $service->id)
                                ->duplicate('invoice_id', '=', $service->invoice_id ?? null)
                                ->insert('service_invoices', $vars);
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Updates the client_group_settings.key column to allow longer strings
     *
     * @param bool $undo True to undo the change false to perform the change
     */
    private function updateClientGroupSettingKeyColumnLength($undo = false)
    {
        if ($undo) {
            $this->Record->query('ALTER TABLE `client_group_settings` CHANGE `key` `key` VARCHAR( 32 );');
        } else {
            $this->Record->query('ALTER TABLE `client_group_settings` CHANGE `key` `key` VARCHAR( 64 );');
        }
    }
}
