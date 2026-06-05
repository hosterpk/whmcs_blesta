<?php

use Blesta\Core\Util\Filters\InvoiceFilters;

/**
 * Client portal invoices controller
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ClientInvoices extends ClientController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Clients', 'Invoices']);
    }

    /**
     * List invoices
     */
    public function index()
    {
        // Get current page of results
        $status = ((isset($this->get[0]) && ($this->get[0] == 'closed')) ? $this->get[0] : 'open');
        $page = (isset($this->get[1]) ? (int) $this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_due');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        // Set filters from post input
        $post_filters = [];
        if (isset($this->post['filters'])) {
            $post_filters = $this->post['filters'];
            unset($this->post['filters']);

            foreach($post_filters as $filter => $value) {
                if (empty($value)) {
                    unset($post_filters[$filter]);
                }
            }
        }

        // Get the invoices
        $invoices = $this->Invoices->getList($this->client->id, $status, $page, [$sort => $order], $post_filters);
        $total_results = $this->Invoices->getListCount($this->client->id, $status, $post_filters);

        // Set the number of invoices of each type
        $status_count = [
            'open' => $this->Invoices->getStatusCount($this->client->id, 'open', $post_filters),
            'closed' => $this->Invoices->getStatusCount($this->client->id, 'closed', $post_filters)
        ];

        // Set the input field filters for the widget
        $invoice_filters = new InvoiceFilters();
        $this->set(
            'filters',
            $invoice_filters->getFilters(
                ['language' => Configure::get('Blesta.language'), 'company_id' => Configure::get('Blesta.company_id')],
                $post_filters
            )
        );

        // Get enabled electronic invoice formats
        $enabled_formats = $this->getEnabledFormats();
        $electronic_formats = [];
        if (!empty($enabled_formats)) {
            $this->components(['InvoiceFormats']);
            foreach ($enabled_formats as $format_key) {
                try {
                    $format = $this->InvoiceFormats->create($format_key);
                    $electronic_formats[$format_key] = $format->getName();
                } catch (Exception $e) {
                    // Skip formats that can't be instantiated
                }
            }
        }

        $this->set('filter_vars', $post_filters);
        $this->set('status', $status);
        $this->set('client', $this->client);
        $this->set('invoices', $invoices);
        $this->set('status_count', $status_count);
        $this->set('electronic_formats', $electronic_formats);
        $this->set('widget_state', isset($this->widgets_state['invoices']) ? $this->widgets_state['invoices'] : null);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->structure->set(
            'page_title',
            Language::_('ClientInvoices.index.page_title', true, $this->client->id_code)
        );

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination_client'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'invoices/index/' . $status . '/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        if ($this->isAjax()) {
            return $this->renderAjaxWidgetIfAsync(
                isset($this->get['whole_widget']) ? null : (isset($this->get[1]) || isset($this->get['sort']))
            );
        }
    }

    /**
     * AJAX request for all transactions an invoice has applied
     */
    public function applied()
    {
        $this->uses(['Transactions']);

        $invoice = $this->Invoices->get((int) $this->get[0]);

        // Ensure the invoice belongs to the client and this is an ajax request
        if (!$this->isAjax() || !$invoice || $invoice->client_id != $this->client->id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $vars = [
            'client' => $this->client,
            'applied' => $this->Transactions->getApplied(null, $this->get[0]),
            // Holds the name of all of the transaction types
            'transaction_types' => $this->Transactions->transactionTypeNames()
        ];

        // Send the template
        echo $this->partial('client_invoices_applied', $vars);

        // Render without layout
        return false;
    }

    /**
     * Renders the given invoice
     */
    public function view()
    {
        $this->uses(['Currencies', 'Clients', 'Companies', 'Transactions']);

        // Ensure we have a invoice to load, and that it belongs to this client
        if (!isset($this->get[0])
            || !($invoice = $this->Invoices->get((int) $this->get[0], true))
            || ($invoice->client_id != $this->client->id)
        ) {
            $this->redirect($this->base_uri);
        }

        // Get invoice client
        $client = $this->Clients->get($invoice->client_id);

        // Get client company
        $company = $this->Companies->get($client->company_id);

        // Get invoice currency
        $currency = $this->Currencies->get($invoice->currency, $company->id);

        // Fetch company settings for display_payments option
        Loader::loadComponents($this, ['SettingsCollection']);
        $company_settings = $this->SettingsCollection->fetchSettings($this->Companies, $company->id);

        // Get applied transactions for payments display
        $applied_transactions = [];
        $display_payments = false;
        if (isset($company_settings['inv_display_payments'])
            && $company_settings['inv_display_payments'] == 'true'
        ) {
            $display_payments = true;
            $applied_transactions = $this->Transactions->getApplied(null, $invoice->id);

            // Set real name to applied transactions
            $transaction_types = $this->Transactions->transactionTypeNames();
            foreach ($applied_transactions as &$transaction) {
                $transaction->type_real_name = $transaction_types[
                    ($transaction->type_name != '' ? $transaction->type_name : $transaction->type)
                ];
            }
        }

        // Get enabled electronic invoice formats
        $enabled_formats = $this->getEnabledFormats();
        $electronic_formats = [];
        if (!empty($enabled_formats)) {
            $this->components(['InvoiceFormats']);
            foreach ($enabled_formats as $format_key) {
                try {
                    $format = $this->InvoiceFormats->create($format_key);
                    $electronic_formats[$format_key] = $format->getName();
                } catch (Exception $e) {
                    // Skip formats that can't be instantiated
                }
            }
        }

        $this->set('currency', $currency);
        $this->set('invoice', $invoice);
        $this->set('client', $client);
        $this->set('company', $company);
        $this->set('electronic_formats', $electronic_formats);
        $this->set('language', Configure::get('Blesta.language'));
        $this->set('display_payments', $display_payments);
        $this->set('applied_transactions', $applied_transactions);
        $this->structure->set(
            'page_title',
            Language::_('ClientInvoices.view.page_title', true, $invoice->id_value)
        );
    }

    /**
     * Streams the given invoice to the browser
     */
    public function download()
    {
        // Ensure we have a invoice to load, and that it belongs to this client
        if (!isset($this->get[0])
            || !($invoice = $this->Invoices->get((int) $this->get[0]))
            || ($invoice->client_id != $this->client->id)
        ) {
            $this->redirect($this->base_uri);
        }

        $this->components(['InvoiceDelivery']);
        $this->InvoiceDelivery->downloadInvoices([$invoice->id]);
        exit;
    }

    /**
     * Downloads an electronic invoice in the specified format
     */
    public function downloadElectronic()
    {
        $invoice_id = (int) ($this->get[0] ?? null);
        $format = $this->get[1] ?? null;

        // Ensure we have a invoice to load, and that it belongs to this client
        if (!$invoice_id
            || !($invoice = $this->Invoices->get($invoice_id))
            || ($invoice->client_id != $this->client->id)
        ) {
            $this->redirect($this->base_uri);
        }

        // Validate format is provided and enabled
        $enabled_formats = $this->getEnabledFormats();
        if (!$format || !in_array($format, $enabled_formats)) {
            $this->flashMessage('error', Language::_('ClientInvoices.!error.format.invalid', true));
            $this->redirect($this->base_uri . 'invoices/view/' . $invoice_id . '/');
        }

        // Download using ElectronicInvoices model
        $this->uses(['ElectronicInvoices']);
        $this->ElectronicInvoices->download($invoice_id, $format);
        exit;
    }

    /**
     * Gets the list of enabled electronic invoice formats for the current company
     *
     * @return array List of enabled format keys
     */
    private function getEnabledFormats()
    {
        $this->uses(['Companies']);
        $setting = $this->Companies->getSetting($this->client->company_id, 'electronic_invoice_formats');

        if (!$setting || empty($setting->value)) {
            return [];
        }

        return safe_unserialize($setting->value);
    }
}
