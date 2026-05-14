<?php
/**
 * Upgrades to version 5.12.1
 *
 * @package blesta
 * @subpackage components.upgrades.tasks
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Upgrade5_12_1 extends UpgradeUtil
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
            'addQuotationApprovedEmail',
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
     * Adds the quotation delivery email template
     *
     * @param bool $undo True to undo the change, false to perform the change
     */
    private function addQuotationApprovedEmail($undo = false)
    {
        if ($undo) {
            return;
        }

        Loader::loadModels($this, ['Companies', 'Emails', 'Languages']);

        // Add the quotation approved email template group
        $this->Record->query(
            "INSERT INTO `email_groups` (`id` , `action` , `type` , `plugin_dir` , `tags`)
            VALUES (
                NULL ,
                'quotation_approved',
                'client',
                NULL ,
                '{staff.first_name} {staff.last_name} {contact.first_name} {contact.last_name} {client_url} {quotations}'
            );"
        );
        $email_group_id = $this->Record->lastInsertId();

        // Fetch all companies
        $companies = $this->Companies->getAll();

        foreach ($companies as $company) {
            // Fetch all languages installed for this company
            $languages = $this->Languages->getAll($company->id);

            // Add the quotation approved email template for each installed language
            foreach ($languages as $language) {
                // Fetch the service suspension email to copy fields from
                $invoice_delivery_email = $this->Emails->getByType($company->id, 'service_suspension', $language->code);

                if ($invoice_delivery_email) {
                    $vars = [
                        'email_group_id' => $email_group_id,
                        'company_id' => $company->id,
                        'lang' => $language->code,
                        'from' => $invoice_delivery_email->from,
                        'from_name' => $invoice_delivery_email->from_name,
                        'subject' => 'Quotation Approved',
                        'text' => 'Hi {contact.first_name} {contact.last_name},
{% for quotation in quotations %}
The quote #{quotation.id_code} was approved!.
{% endfor %}
View the quote from the client interface at http://{client_url}/client',
                        'html' => '<p>Hi {contact.first_name} {contact.last_name},</p>
{% for quotation in quotations %}
<p>The quote #{quotation.id_code} was approved!.</p>
{% endfor %}
<p>View the quote from the client interface at <a href="http://{client_url}/client">http://{client_url}/client</a></p>',
                        'email_signature_id' => $invoice_delivery_email->email_signature_id
                    ];

                    $this->Record->insert('emails', $vars);
                }
            }
        }
    }
}
