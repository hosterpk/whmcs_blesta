<?php
/**
 * Admin Company Electronic Invoices Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminCompanyElectronicInvoices extends AdminController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Companies', 'Navigation']);
        $this->components(['InvoiceFormats']);

        // Set the left nav for all settings pages to settings_leftnav
        $this->set(
            'left_nav',
            $this->partial('settings_leftnav', ['nav' => $this->Navigation->getCompany($this->base_uri)])
        );
    }

    /**
     * Electronic Invoices settings page
     */
    public function index()
    {
        // Get all available invoice formats
        $available_formats = $this->InvoiceFormats->listFormats();

        // Handle form submission
        if (!empty($this->post)) {
            // Save to company settings
            $this->Companies->setSetting($this->company_id, 'electronic_invoice_formats', json_encode($this->post['formats'] ?? []));

            // Set success message and redirect
            if (($errors = $this->Companies->errors())) {
                $this->setMessage('error', $errors);
            } else {
                $this->flashMessage('message', Language::_('AdminCompanyElectronicInvoices.!success.formats_updated', true));
                $this->redirect($this->base_uri . 'settings/company/electronic_invoices/');
            }
        }

        // Fetch enabled formats
        $enabled_formats = $this->Companies->getSetting($this->company_id, 'electronic_invoice_formats');
        $enabled_formats = !empty($enabled_formats->value) ? \Blesta\Core\Util\Common\Classes\Model::safeUnserialize($enabled_formats->value) : [];

        // Prepare data for the view
        $formats_data = [];
        foreach ($available_formats as $format_key => $display_name) {
            $format = $this->InvoiceFormats->create($format_key);
            $formats_data[] = [
                'key' => $format_key,
                'name' => $display_name,
                'description' => $format->getDescription(),
                'enabled' => in_array($format_key, $enabled_formats)
            ];
        }

        $this->set('formats', $formats_data);
        $this->set('vars', (object)$this->post);
    }

    /**
     * Gets the list of enabled electronic invoice formats
     *
     * @return array List of enabled format keys
     */
    private function getEnabledFormats()
    {
        $setting = $this->Companies->getSetting($this->company_id, 'electronic_invoice_formats');

        if (!$setting || empty($setting->value)) {
            return [];
        }

        // Stored as comma-separated values
        return \Blesta\Core\Util\Common\Classes\Model::safeUnserialize($setting->value);
    }
}
