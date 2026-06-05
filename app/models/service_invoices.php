<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Language;
use Loader;

/**
 * Service Invoice
 * This is an association between services and the invoices created for adding or renewing them.
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2017, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ServiceInvoices extends AppModel
{
    /**
     * Initialize language
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(['service_invoices']);
    }

    /**
     * Adds the association between the given service and an invoice created for its addition or renewal
     *
     * @param array $vars An array of information including:
     *
     *  - service_id The service ID
     *  - invoice_id The ID of the invoice
     *  - type The type of action to queue, it could be 'provisioning', 'renewal', 'suspension',
     *      'unsuspension' or 'cancelation'. 'provisioning' by default.
     *  - maximum_attempts The maximum number of times the service will be reattempted to be renewed (optional)
     *  - date_next_attempt The date for the next attempt to provision the service, if failed (optional)
     */
    public function add(array $vars)
    {
        $this->Input->setRules($this->getRules($vars));

        Loader::loadModels($this, ['Services', 'Clients']);

        // Set default association type
        if (empty($vars['type'])) {
            $vars['type'] = 'provisioning';
        }

        // Serialize data
        if (!empty($vars['data']) && !is_scalar($vars['data'])) {
            $vars['data'] = json_encode($vars['data']);
        }

        // Add service invoice association
        if ($this->Input->validates($vars)) {
            $service = $this->Services->get($vars['service_id']);

            // Get maximum attempts for this client
            if (empty($vars['maximum_attempts'])) {
                $service_attempts = 'service_' . ($vars['type'] ?? 'provisioning') . '_attempts';
                $attempts = $this->Clients->getSetting(
                    $service->client_id,
                    $service_attempts
                ) ?? null;

                if (isset($attempts->value) && is_numeric($attempts->value)) {
                    $vars['maximum_attempts'] = $attempts->value;
                }
            }

            // Try to match the service to an invoice, if invoice_id is null
            if (empty($vars['invoice_id'])) {
                $invoice = $this->Record->select('invoices.*')
                    ->from('invoice_lines')
                    ->innerJoin('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id', false)
                    ->where('invoice_lines.service_id', '=', $vars['service_id'])
                    ->where('invoices.status', '=', 'active')
                    ->where('invoices.date_closed', '=', null)
                    ->fetch();
                $vars['invoice_id'] = $invoice->id ?? null;
            }

            // If another entry exists with a null invoice ID for the same type, delete it
            $this->Record->from('service_invoices')
                ->where('service_id', '=', $vars['service_id'])
                ->where('invoice_id', '=', null)
                ->where('type', '=', $vars['type'])
                ->delete();

            // Ignore duplicate inserts by simply updating the columns of the primary key
            $fields = ['service_id', 'invoice_id', 'type', 'data', 'failed_attempts', 'maximum_attempts', 'date_next_attempt'];
            $this->Record->duplicate('service_id', '=', $vars['service_id'])
                ->duplicate('invoice_id', '=', $vars['invoice_id'])
                ->duplicate('type', '=', $vars['type'])
                ->insert('service_invoices', $vars, $fields);

            return $this->Record->lastInsertId();
        }
    }

    /**
     * Fetches the association between the given service and invoices
     *
     * @param int $service_id The ID of the service
     * @param int $invoice_id The ID of a particular invoice to delete for
     * @param int $type The type of association to delete for
     */
    public function get($service_id, $invoice_id = null, $type = null)
    {
        $this->Record->select('service_invoices.*')
            ->from('service_invoices')
            ->where('service_id', '=', $service_id);

        if ($invoice_id) {
            $this->Record->where('invoice_id', '=', $invoice_id);
        }

        if ($type) {
            $this->Record->where('type', '=', $type);
        }

        $service_invoice = $this->Record->order(['id' => 'ASC'])->fetch();
        if (!empty($service_invoice->data)) {
            $service_invoice->data = json_decode($service_invoice->data);
        }

        return $service_invoice;
    }

    /**
     * Fetches the association between the given service and invoices
     *
     * @param int $service_id The ID of the service
     * @param int $invoice_id The ID of a particular invoice to delete for
     * @param int $type The type of association to delete for
     * @param int $filters A list of parameters to filter by, including:
     *
     *   - failed_attempts The number of times the service has been attempted to be renewed, but failed
     *   - maximum_attempts The maximum number of times the service will be reattempted to be renewed
     *   - date_next_attempt The date for the next attempt to provision the service, if failed
     */
    public function getAll($service_id = null, $invoice_id = null, $type = null, array $filters = [])
    {
        $this->Record->select('service_invoices.*')
            ->from('service_invoices');

        if ($service_id) {
            $this->Record->where('service_id', '=', $service_id);
        }

        if ($invoice_id) {
            $this->Record->where('invoice_id', '=', $invoice_id);
        }

        if ($type) {
            $this->Record->where('type', '=', $type);
        }

        $this->Record = $this->applyFilters($this->Record, $filters);

        $service_invoices = $this->Record->fetchAll();
        foreach ($service_invoices as &$service_invoice) {
            if (!empty($service_invoice->data)) {
                $service_invoice->data = json_decode($service_invoice->data);
            }
        }

        return $service_invoices;
    }

    /**
     * Returns the number of associations between the given service and invoice
     *
     * @param int $service_id The ID of the service
     * @param int $invoice_id The ID of a particular invoice to delete for
     * @param int $type The type of association to delete for
     * @param int $filters A list of parameters to filter by, including:
     *
     *   - failed_attempts The number of times the service has been attempted to be renewed, but failed
     *   - maximum_attempts The maximum number of times the service will be reattempted to be renewed
     *   - date_next_attempt The date for the next attempt to provision the service, if failed
     */
    public function getListCount($service_id = null, $invoice_id = null, $type = null, array $filters = [])
    {
        $this->Record->select('service_invoices.*')
            ->from('service_invoices');

        if ($service_id) {
            $this->Record->where('service_id', '=', $service_id);
        }

        if ($invoice_id) {
            $this->Record->where('invoice_id', '=', $invoice_id);
        }

        if ($type) {
            $this->Record->where('type', '=', $type);
        }

        $this->Record = $this->applyFilters($this->Record, $filters);

        return $this->Record->numResults();
    }

    /**
     * Returns the number of associations between the given service and invoice
     *
     * @param int $service_id The ID of the service
     * @param int $invoice_id The ID of a particular invoice to delete for
     * @param int $type The type of association to delete for
     * @param int $filters A list of parameters to filter by, including:
     *
     *   - failed_attempts The number of times the service has been attempted to be renewed, but failed
     *   - maximum_attempts The maximum number of times the service will be reattempted to be renewed
     *   - date_next_attempt The date for the next attempt to provision the service, if failed
     */
    public function getList($page = 1, $order_by = ['date_next_attempt' => 'DESC'], $service_id = null, $invoice_id = null, $type = null, array $filters = [])
    {
        $this->Record->select('service_invoices.*')
            ->from('service_invoices');

        if ($service_id) {
            $this->Record->where('service_id', '=', $service_id);
        }

        if ($invoice_id) {
            $this->Record->where('invoice_id', '=', $invoice_id);
        }

        if ($type) {
            $this->Record->where('type', '=', $type);
        }

        $this->Record = $this->applyFilters($this->Record, $filters);

        $service_invoices = $this->Record->order($order_by)->
            limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())->
            fetchAll();

        foreach ($service_invoices as &$service_invoice) {
            if (!empty($service_invoice->data)) {
                $service_invoice->data = json_decode($service_invoice->data);
            }
        }

        return $service_invoices;
    }

    /**
     * Updates the association between the given service and an invoice
     *
     * @param array $vars An array of information including:
     *
     *  - failed_attempts The number of times the service has been attempted to be renewed, but failed (optional)
     *  - maximum_attempts The maximum number of times the service will be reattempted to be renewed (optional)
     *  - date_next_attempt The date for the next attempt to provision the service, if failed (optional)
     */
    public function update($service_id, $invoice_id, $type, array $vars)
    {
        $fields = ['failed_attempts', 'maximum_attempts', 'date_next_attempt'];
        $this->Record->where('service_id', '=', $service_id)
            ->where('invoice_id', '=', $invoice_id)
            ->where('type', '=', $type)
            ->update('service_invoices', $vars, $fields);
    }

    /**
     * Gets a list of service attempt types handled by this model
     *
     * @return array A list of attempt types handled by the model
     */
    public function getAttemptTypes()
    {
        return [
            'provisioning' => $this->_('ServiceInvoices.getattempttypes.provisioning'),
            'renewal' => $this->_('ServiceInvoices.getattempttypes.renewal'),
            'suspension' => $this->_('ServiceInvoices.getattempttypes.suspension'),
            'unsuspension' => $this->_('ServiceInvoices.getattempttypes.unsuspension'),
            'cancelation' => $this->_('ServiceInvoices.getattempttypes.cancelation'),
            'change' => $this->_('ServiceInvoices.getattempttypes.change'),
        ];
    }

    /**
     * Deletes the association between the given service and invoices created for its addition or renewal
     *
     * @param int $service_id The ID of the service
     * @param int $invoice_id The ID of a particular invoice to delete for
     * @param int $type The type of association to delete for
     */
    public function delete($service_id, $invoice_id = null, $type = null)
    {
        $this->Record->from('service_invoices')->where('service_id', '=', $service_id);

        if ($invoice_id) {
            $this->Record->where('invoice_id', '=', $invoice_id);
        }

        if ($type) {
            $this->Record->where('type', '=', $type);
        }

        $this->Record->delete();
    }

    /**
     * Retrieves validation rules for ::add
     *
     * @param array $vars An array of input data for validation
     * @return array The input validation rules
     */
    private function getRules(array $vars)
    {
        return [
            'service_id' => [
                'exists' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'id', 'services'],
                    'message' => $this->_('ServiceInvoices.!error.service_id.exists')
                ]
            ],
            'invoice_id' => [
                'exists' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'id', 'invoices'],
                    'message' => $this->_('ServiceInvoices.!error.invoice_id.exists')
                ]
            ],
            'failed_attempts' => [
                'format' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('ServiceInvoices.!error.failed_attempts.format')
                ]
            ],
            'maximum_attempts' => [
                'format' => [
                    'if_set' => true,
                    'rule' => 'is_numeric',
                    'message' => $this->_('ServiceInvoices.!error.maximum_attempts.format')
                ]
            ],
            'type' => [
                'format' => [
                    'rule' => ['in_array', array_keys($this->getAttemptTypes())],
                    'message' => $this->_('ServiceInvoices.!error.type.valid')
                ]
            ],
            'date_next_attempt' => [
                'format' => [
                    'if_set' => true,
                    'rule' => 'isDate',
                    'message' => $this->_('ServiceInvoices.!error.date_next_attempt.format'),
                    'post_format' => [[$this, 'dateToUtc']]
                ]
            ]
        ];
    }

    /**
     * Returns a list with the client service cancel options
     *
     * @return array A list of service cancel options
     */
    public function getCancelOptions()
    {
        return [
            'both' => Language::_('ServiceInvoices.getCancelOptions.both', true),
            'end_of_term' => Language::_('ServiceInvoices.getCancelOptions.end_of_term', true),
            'now' => Language::_('ServiceInvoices.getCancelOptions.now', true)
        ];
    }
}
