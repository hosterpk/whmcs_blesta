<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;
use Loader;
use stdClass;

/**
 * Service upgrades/downgrades (Kept for backwards compatibility, Use ServiceInvoices instead)
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2015, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ServiceChanges extends AppModel
{
    /**
     * Initialize language
     */
    public function __construct()
    {
        parent::__construct();

        Loader::loadModels($this, ['Services', 'Invoices', 'Clients', 'ServiceInvoices']);

        Language::loadLang(['service_changes']);
    }

    /**
     * Queues a pending service change entry
     *
     * @param int $service_id The service ID
     * @param int $invoice_id The ID of the invoice
     * @param array $vars An array of information including:
     *
     *  - data An array of input data used later to process the service change
     * @return int The service change ID on success, or void on failure
     */
    public function add($service_id, $invoice_id, array $vars)
    {
        $data = [
            'service_id' => $service_id,
            'invoice_id' => $invoice_id,
            'type' => 'change',
            'data' => (isset($vars['data']) ? (array) $vars['data'] : []),
            'date_next_attempt' => date('c')
        ];

        $service_invoice_id = $this->ServiceInvoices->add($data);

        // Log that the service change was created
        $this->logger->info('Created Service Change', array_merge($data, ['id' => $service_invoice_id]));

        return $service_invoice_id;
    }

    /**
     * Updates a service change entry
     *
     * @param int $service_change_id The ID of the service change to update
     * @param array $vars An array of fields to update:
     *
     *  - status The service change status (one of: 'pending', 'canceled', 'error', 'completed')
     */
    public function edit($service_change_id, array $vars)
    {
        $service_change = $this->Record->select()
            ->from('service_invoices')
            ->where('id', '=', $service_change_id)
            ->fetch();

        if (!$service_change) {
            return;
        }

        // Remove service change if has been completed or canceled
        if (in_array($vars['status'] ?? null, ['completed', 'canceled'])) {
            $this->ServiceInvoices->delete(
                $service_change->service_id,
                $service_change->invoice_id,
                'change'
            );
        }

        // Reset attempts if status is set to pending
        if (($vars['status'] ?? null) == 'pending') {
            $this->ServiceInvoices->update(
                $service_change->service_id,
                $service_change->invoice_id,
                'change',
                ['failed_attempts' => 0]
            );
        }

        // Increment attempts if status is set to error
        if (($vars['status'] ?? null) == 'error') {
            $this->ServiceInvoices->update(
                $service_change->service_id,
                $service_change->invoice_id,
                'change',
                ['failed_attempts' => $service_change->failed_attempts + 1]
            );
        }

        // Log that the service change was updated
        $fields = ['status'];
        $log_vars = array_intersect_key($vars, array_flip($fields));
        $this->logger->info('Updated Service Change', array_merge($log_vars, ['id' => $service_change_id]));
    }

    /**
     * Cancels all service changes for the given ID
     *
     * @param int $service_change_id The ID of the service changes to permanently delete
     * @param bool $void_invoice True to void the invoice associated to this service change
     */
    public function cancel($service_change_id, $void_invoice = false)
    {
        // Same as delete
        $this->delete($service_change_id, $void_invoice);
    }

    /**
     * Deletes all service changes for the given ID
     *
     * @param int $service_change_id The ID of the service changes to permanently delete
     * @param bool $void_invoice True to void the invoice associated to this service change
     */
    public function delete($service_change_id, $void_invoice = false)
    {
        $service_change = $this->Record->select()
            ->from('service_invoices')
            ->where('id', '=', $service_change_id)
            ->fetch();

        if (!$service_change) {
            return;
        }

        if ($void_invoice) {
            $invoice = $this->Invoices->get($service_change->invoice_id);

            if ($invoice && $invoice->date_closed !== null) {
                // Paid invoices cannot be voided; surface the reason instead of silently skipping
                $this->Input->setErrors([
                    'void_invoice' => ['paid' => $this->_('ServiceChanges.!error.void_invoice.paid', true)]
                ]);
                return;
            } elseif ($invoice) {
                $this->Invoices->edit($service_change->invoice_id, ['status' => 'void']);

                if (($invoice_errors = $this->Invoices->errors())) {
                    $this->Input->setErrors($invoice_errors);
                }
            }
        }

        $this->ServiceInvoices->delete(
            $service_change->service_id,
            $service_change->invoice_id,
            'change'
        );
    }

    /**
     * Deletes all service changes associated with the given service
     *
     * @param int $service_id The ID of the service whose changes to permanently delete
     */
    public function deleteByService($service_id)
    {
        $this->ServiceInvoices->delete($service_id, null, 'change');
    }

    /**
     * Retrieves a service change
     *
     * @param int $service_change_id The ID of the service change to update
     * @return mixed An stdClass object representing the service change, or false otherwise
     */
    public function get($service_change_id)
    {
        $service_change = $this->Record->select()
            ->from('service_invoices')
            ->where('id', '=', $service_change_id)
            ->where('type', '=', 'change')
            ->fetch();

        if (!$service_change) {
            return false;
        }

        if (!empty($service_change->data)) {
            $service_change->data = json_decode($service_change->data);
        }

        $service_change->status = ($service_change->failed_attempts >= $service_change->maximum_attempts)
            ? 'error'
            : 'pending';
        $service_change->date_added = $service_change->date_next_attempt;
        $service_change->date_status = $service_change->date_next_attempt;

        return $service_change;
    }

    /**
     * Retrieves a list of all service change entries of the given status
     *
     * @param mixed $status The status of the service changes to fetch, or null for all
     * @param int $service_id The ID of the service the service change belongs to (optional)
     * @return array A list of service change entries
     */
    public function getAll($status = null, $service_id = null)
    {
        // Set filters
        $filters = [];
        if (!empty($status)) {
            $status_filter = $this->buildStatusFilter($status);
            if ($status_filter === false) {
                return [];
            }
            if ($status_filter !== null) {
                $filters[] = $status_filter;
            }
        }

        // Format data
        $service_changes = $this->ServiceInvoices->getAll($service_id, null, 'change', $filters);
        foreach ($service_changes as &$entry) {
            $entry->service = $this->Services->get($entry->service_id ?? null);
            $entry->invoice = $this->Invoices->get($entry->invoice_id ?? null);
            $entry->client = $this->Clients->get($entry->service->client_id ?? null);

            $entry->status = ($entry->failed_attempts >= $entry->maximum_attempts)
                ? 'error'
                : 'pending';
            $entry->date_added = $entry->date_next_attempt;
            $entry->date_status = $entry->date_next_attempt;
        }

        return $service_changes;
    }

    /**
     * Returns the number of all service change entries of the given status
     *
     * @param mixed $status The status of the service changes to fetch, or null for all
     * @param int $service_id The ID of the service the service change belongs to (optional)
     * @param array $filters A list of parameters to filter by, including:
     *
     *  - service_id The ID of the service (optional)
     *  - invoice_id The ID of the invoice (optional)
     *  - invoice The invoice number associated to the service change (optional)
     *  - date_added The date the service change was created (optional)
     *  - date_status The date the service change was updated (optional)
     * @return int The number of service changes
     */
    public function getListCount($status = null, $service_id = null, array $filters = [])
    {
        // Filter on date added
        if (isset($filters['date_added']) || isset($filters['date_status'])) {
            $date = $filters['date_status'] ?? $filters['date_added'] ?? null;
            $filters[] = [
                'column' => 'date_next_attempt',
                'operator' => '>=',
                'value' => $this->dateToUtc(
                    $this->Date->cast($date . ' 00:00:00', 'Y-m-d')
                )
            ];
            $filters[] = [
                'column' => 'date_next_attempt',
                'operator' => '<=',
                'value' => $this->dateToUtc(
                    $this->Date->cast($date . ' 23:59:59', 'Y-m-d')
                )
            ];

            unset($filters['date_added']);
            unset($filters['date_status']);
        }

        // Set status filter
        if (!empty($status)) {
            $status_filter = $this->buildStatusFilter($status);
            if ($status_filter === false) {
                return 0;
            }
            if ($status_filter !== null) {
                $filters[] = $status_filter;
            }
        }

        return $this->ServiceInvoices->getListCount($service_id, null, 'change', $filters);
    }

    /**
     * Retrieves a list of all service change entries of the given status
     *
     * @param int $page The page to return results for (optional, default 1)
     * @param array $order_by The sort and order conditions (e.g. array('sort_field'=>"ASC"), optional)
     * @param mixed $status The status of the service changes to fetch, or null for all
     * @param int $service_id The ID of the service change belongs to (optional)
     * @param array $filters A list of parameters to filter by, including:
     *
     * - service_id The ID of the service (optional)
     * - invoice_id The ID of the invoice (optional)
     * - invoice The invoice number associated to the service change (optional)
     * - date_added The date the service change was created (optional)
     * - date_status The date the service change was updated (optional)
     * @return array A list of service change entries
     */
    public function getList($page = 1, $order_by = ['date_next_attempt' => 'DESC'], $status = null, $service_id = null, array $filters = [])
    {
        // Translate legacy order columns to the real service_invoices columns
        $column_aliases = ['date_added' => 'date_next_attempt', 'date_status' => 'date_next_attempt'];
        $translated_order = [];
        foreach ($order_by as $column => $direction) {
            $translated_order[$column_aliases[$column] ?? $column] = $direction;
        }
        $order_by = $translated_order;

        // Translate legacy filter keys to the real service_invoices columns
        if (isset($filters['invoice'])) {
            $filters['invoice_id'] = $filters['invoice'];
            unset($filters['invoice']);
        }

        // Filter on date added
        if (isset($filters['date_added']) || isset($filters['date_status'])) {
            $date = $filters['date_status'] ?? $filters['date_added'] ?? null;
            $filters[] = [
                'column' => 'date_next_attempt',
                'operator' => '>=',
                'value' => $this->dateToUtc(
                    $this->Date->cast($date . ' 00:00:00', 'Y-m-d')
                )
            ];
            $filters[] = [
                'column' => 'date_next_attempt',
                'operator' => '<=',
                'value' => $this->dateToUtc(
                    $this->Date->cast($date . ' 23:59:59', 'Y-m-d')
                )
            ];

            unset($filters['date_added']);
            unset($filters['date_status']);
        }

        // Set status filter
        if (!empty($status)) {
            $status_filter = $this->buildStatusFilter($status);
            if ($status_filter === false) {
                return [];
            }
            if ($status_filter !== null) {
                $filters[] = $status_filter;
            }
        }

        // Format data
        $service_changes = $this->ServiceInvoices->getList($page, $order_by, $service_id, null, 'change', $filters);
        foreach ($service_changes as &$entry) {
            $entry->service = $this->Services->get($entry->service_id ?? null);
            $entry->invoice = $this->Invoices->get($entry->invoice_id ?? null);
            $entry->client = $this->Clients->get($entry->service->client_id ?? null);

            $entry->status = ($entry->failed_attempts >= $entry->maximum_attempts)
                ? 'error'
                : 'pending';
            $entry->date_added = $entry->date_next_attempt;
            $entry->date_status = $entry->date_next_attempt;
        }

        return $service_changes;
    }

    /**
     * Processes the pending service change by updating the service
     *
     * @param int $service_change_id The ID of the pending service change to update
     */
    public function process($service_change_id)
    {
        if (!isset($this->Services)) {
            Loader::loadModels($this, ['Services']);
        }

        if (!isset($this->Form)) {
            Loader::loadHelpers($this, ['Form']);
        }

        if (!isset($this->Logs)) {
            Loader::loadModels($this, ['Logs']);
        }

        if (!isset($this->Transactions)) {
            Loader::loadModels($this, ['Transactions']);
        }

        $service_change = $this->get($service_change_id);

        // Process the service change
        if ($service_change && $service_change->status == 'pending') {
            // Get current service
            $service = $this->Services->get($service_change->service_id);
            $service_fields = $this->Form->collapseObjectArray($service->fields, 'value', 'key');

            // Format the object to array
            $data = $this->objectToArray($service_change->data);
            $data = array_merge($service_fields, $data);

            // Update the service
            $this->Services->edit($service_change->service_id, $data);

            // Update the queued service change entry
            $errors = $this->Services->errors();
            $status = (empty($errors) ? 'completed' : 'error');
            $this->edit($service_change_id, ['status' => $status]);

            // Fetch the transaction related to this service change
            $transactions = $this->Transactions->getApplied(null, $service_change->invoice_id);

            // Log service change
            if ($status == 'completed') {
                $log = [
                    'service_id' => $service->id,
                    'transactions' => $this->Form->collapseObjectArray($transactions ?? [], 'id'),
                    'old_service' => (array) $service,
                    'new_service' => (array) $this->Services->get($service_change->service_id)
                ];
                $this->Logs->addServiceChange($log);
            }
        }
    }

    /**
     * Retrieves a presenter representing a set of items, discounts, and taxes
     * for a service change (i.e. upgrade/downgrade)
     *
     * @param int $service_id The ID of the service to change
     * @param array $vars An array of input representing the new service changes:
     *  - configoptions An array of key/value pairs where each key is an
     *      option ID and each value is the selected value
     *  - pricing_id The ID of the new pricing selected
     *  - qty The service quantity
     *  - coupon_code A new coupon code to use
     *  - date_renews (optional) The new renew date, if it is changing from the service's current renew date
     * @return bool|Blesta\Core\Pricing\Presenter\Type\ServiceChangePresenter The presenter, otherwise false
     */
    public function getPresenter($service_id, array $vars)
    {
        Loader::loadModels(
            $this,
            ['Companies', 'Coupons', 'Invoices', 'ModuleManager', 'Packages', 'PackageOptions', 'Services']
        );
        Loader::loadComponents($this, ['SettingsCollection', 'Form']);

        // Service must exist
        if (!($service = $this->Services->get($service_id))) {
            return false;
        }

        // Determine the package and pricing selected to change to
        $pricing_id = (isset($vars['pricing_id']) ? (int) $vars['pricing_id'] : null);
        $pricing = null;
        if ($pricing_id && ($package = $this->Packages->getByPricingId($pricing_id))) {
            foreach ($package->pricing as $price) {
                if ($price->id == $pricing_id) {
                    $pricing = $price;
                    break;
                }
            }
        }

        // Default to the service package and pricing if new pricing is not given
        if (empty($pricing)) {
            $pricing = $service->package_pricing;
            $package = $this->Packages->get($service->package->id);
        }

        // Fetch all new package options
        $formatted_service_options = $this->PackageOptions->formatServiceOptions($service->options);
        $package_options = $this->PackageOptions->getAllByPackageId(
            $package->id,
            $pricing->term,
            $pricing->period,
            $pricing->currency,
            null,
            $formatted_service_options
        );

        // Fetch any coupon that may exist or be set
        $coupons = [];
        if (
            !empty($vars['coupon_code'])
            && ($coupon = $this->Coupons->getByCode($vars['coupon_code']))
            && $coupon->company_id == Configure::get('Blesta.company_id')
        ) {
            $coupons['new'] = [$coupon];
        }

        // If no coupon was given, fallback to the service coupon, if any
        if (
            !empty($service->coupon_id)
            && ($coupon = $this->Coupons->get($service->coupon_id))
            && $coupon->company_id == Configure::get('Blesta.company_id')
        ) {
            $coupon->from_service = '1';
            $coupons['old'] = [$coupon];
            if (empty($coupons['new'])) {
                $coupons['new'] = $coupons['old'];
            }
        }

        // When the submitted coupon is the one already bound to the service,
        // share the old-branch object so both sides reference the same instance.
        // This lets the change builder pair the old and new items together.
        if (!empty($coupons['new']) && !empty($coupons['old'])
            && $coupons['new'][0]->id == $coupons['old'][0]->id
        ) {
            $coupons['new'] = $coupons['old'];
        }

        // Set options for the builder to use to construct the presenter
        $change_renew_date = false;
        $renew_day_changed = false;
        if (!empty($vars['date_renews']) && !empty($service->date_renews)) {
            $change_renew_date = true;
            $renew_day_changed = $this->hasDayChanged($vars['date_renews'], $service->date_renews . 'Z');
        }
        $now = date('c');
        $options = [
            // Include setup fees since we're adding this package term anew
            'includeSetupFees' => $package->id != $service->package->id,
            // Line items show they are billed from this date
            'startDate' => $now,
            'prorateStartDate' => ($change_renew_date && $renew_day_changed ? $service->date_renews . 'Z' : $now),
            'prorateEndDate' => (!empty($service->date_renews) ? $service->date_renews . 'Z' : null),
            // If the renew date has changed, we are prorating to the new renew date
            'prorateEndDateData' => ($change_renew_date ? $vars['date_renews'] : null),
            // NEW-side recur reflects whether the service is currently in its
            // recurring-tier pricing (i.e. has already renewed at least once).
            // First-term changes use initial-tier pricing on NEW side. OLD-side
            // is forced to recur=true in ServiceChangeBuilder::serviceBuilder().
            // The asymmetry is intentional — see CORE-5058 for the rationale.
            'recur' => ($pricing->period != 'onetime' && $service->date_last_renewed !== null),
            'applyDate' => (!empty($service->date_renews) ? $service->date_renews . 'Z' : $now),
            'config_options' => $formatted_service_options['configoptions'] ?? [],
            'upgrade' => $package->id != $service->package->id,
            // Convert current service and option prices to the new currency for comparison
            'option_currency' => $pricing->currency,
            'service_currency' => $vars['override_currency'] ?? $pricing->currency
        ];

        // Retrieve the pricing builder from the container and update the date format options
        $container = Configure::get('container');
        $container['pricing.options'] = [
            'dateFormat' => $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'date_format')
                ->value,
            'dateTimeFormat' => $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'datetime_format')
                ->value
        ];

        // Determine if this is a domain service
        if (
            (
            $registrar = $this->ModuleManager->getInstalled([
                'type' => 'registrar',
                'company_id' => Configure::get('Blesta.company_id'),
                'module_id' => $package->module_id
            ])
            )
        ) {
            $options['item_type'] = 'domain';
        }

        $factory = $this->getFromContainer('pricingBuilder');
        $serviceChange = $factory->serviceChange();

        // Build the service change presenter
        $serviceChange->settings($this->SettingsCollection->fetchClientSettings($service->client_id));
        $serviceChange->taxes($this->Invoices->getTaxRules($service->client_id));
        $serviceChange->discounts($coupons);
        $serviceChange->options($options);

        return $serviceChange->build($service, $vars, $package, $pricing, $package_options);
    }

    /**
     * Determines whether the two given dates represent different days
     *
     * @param string|null $date1 The date
     * @param string|null $date2 The date
     * @return bool True if the dates given represent different days, otherwise false
     */
    private function hasDayChanged($date1, $date2)
    {
        // Convert the dates to local time
        if (!empty($date1) && !empty($date2)) {
            $date = clone $this->Date;
            $date->setTimezone('UTC', Configure::get('Blesta.company_timezone'));
            $date1_format = $date->modify($date1, 'midnight', 'Y-m-d H:i:s', Configure::get('Blesta.company_timezone'));
            $date2_format = $date->modify($date2, 'midnight', 'Y-m-d H:i:s', Configure::get('Blesta.company_timezone'));

            return $date1_format !== $date2_format;
        }

        return false;
    }

    /**
     * Builds the filter comparing failed_attempts to maximum_attempts for the
     * requested status(es). Returns null if both pending and error are
     * requested (no filter needed, the union covers every row of
     * type=change). Returns false if the status list resolves to neither
     * pending nor error, in which case the caller should short-circuit.
     *
     * @param string|array $status A single status or array of statuses
     * @return array|null|false The filter, null for "no filter needed", or false for "no match"
     */
    private function buildStatusFilter($status)
    {
        $statuses = (array) $status;
        $pending = in_array('pending', $statuses, true);
        $error = in_array('error', $statuses, true);

        if ($pending && $error) {
            return null;
        }

        if ($pending) {
            return [
                'column' => 'failed_attempts',
                'operator' => '<',
                'value' => 'maximum_attempts',
                'bind' => false
            ];
        }

        if ($error) {
            return [
                'column' => 'failed_attempts',
                'operator' => '>=',
                'value' => 'maximum_attempts',
                'bind' => false
            ];
        }

        return false;
    }

    /**
     * Retrieves a list of available service change statuses and their language
     *
     * @return array A list of service change statuses and their language
     */
    public function getStatuses()
    {
        return [
            'pending' => $this->_('ServiceChanges.status.pending', true),
            'error' => $this->_('ServiceChanges.status.error', true),
        ];
    }

    /**
     * Converts an object and any nested objects into arrays
     *
     * @param stdClass $object An stdClass object
     * @return array An array representing the object and its fields
     */
    private function objectToArray($object)
    {
        $result = [];
        foreach ($object as $key => $value) {
            if (is_object($value)) {
                $value = $this->objectToArray($value);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
