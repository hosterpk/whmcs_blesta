<?php
/**
 * Client Revenue report
 *
 * @package blesta
 * @subpackage components.reports.client_revenue
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ClientRevenue extends Report
{
    /**
     * Load language
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Record', 'SettingsCollection']);
        Loader::loadModels($this, ['Currencies']);
        Loader::loadHelpers($this, ['DataStructure', 'Date']);
        $this->ArrayHelper = $this->DataStructure->create('Array');

        $this->replacement_keys = Configure::get('Blesta.replacement_keys');

        // Load the language required by this report
        Language::loadLang('client_revenue', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return Language::_('ClientRevenue.name', true);
    }

    /**
     * {@inheritdoc}
     */
    public function getFormats()
    {
        return ['csv', 'json'];
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions($company_id, array $vars = [])
    {
        Loader::loadModels($this, ['Companies']);

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('options', 'default');
        $this->view->setDefaultView('components' . DS . 'reports' . DS . 'client_revenue' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!isset($vars['start_date'])) {
            $vars['start_date'] = $this->Date->format('Y-01-01', date('c'));
        }

        if (!isset($vars['end_date'])) {
            $vars['end_date'] = $this->Date->format('Y-12-31', date('c'));
        }

        $this->view->set('vars', (object)$vars);
        $this->view->set('currencies', $this->Form->collapseObjectArray(
            $this->Currencies->getAll($company_id),
            'code',
            'code'
        ));
        $default_currency = $this->Companies->getSetting($company_id, 'default_currency');
        $this->view->set('default_currency', $default_currency ? $default_currency->value : 'USD');

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function getKeyInfo()
    {
        return [
            'client_id_code' => ['name' => Language::_('ClientRevenue.heading.client_id_code', true)],
            'client_name' => ['name' => Language::_('ClientRevenue.heading.client_name', true)],
            'company' => ['name' => Language::_('ClientRevenue.heading.company', true)],
            'total' => ['name' => Language::_('ClientRevenue.heading.total', true)]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($company_id, array $vars)
    {
        // Format dates
        $timezone = $this->SettingsCollection->fetchSetting(null, $company_id, 'timezone');
        $timezone = (array_key_exists('value', $timezone) ? $timezone['value'] : 'UTC');
        $this->Date->setTimezone($timezone, 'UTC');

        // Set filter options
        $format = 'Y-m-d H:i:s';
        $start_date = !empty($vars['start_date'])
            ? $this->Date->format($format, $vars['start_date'] . ' 00:00:00')
            : null;
        $end_date = !empty($vars['end_date'])
            ? $this->Date->format($format, $vars['end_date'] . ' 23:59:59')
            : null;
        $currency = !empty($vars['currency']) ? $vars['currency'] : 'USD';

        // Get all approved transactions
        $this->Record->select([
            'transactions.client_id', 'transactions.currency',
            'SUM(transactions.amount)' => 'total'
        ])
            ->from('transactions')
            ->leftJoin('transaction_types', 'transaction_types.id', '=', 'transactions.transaction_type_id', false)
            ->open()
                ->where('transaction_types.type', '!=', 'credit')
                ->orWhere('transaction_types.type', '=', null)
            ->close()
            ->where('transactions.status', '=', 'approved')
            ->where('transactions.currency', '=', $currency);

        // Filter on the date the invoice was closed
        if ($start_date) {
            $this->Record->where('transactions.date_added', '>=', $start_date);
        }

        if ($end_date) {
            $this->Record->where('transactions.date_added', '<=', $end_date);
        }

        $this->Record->group(['transactions.client_id']);

        $transactions_sub_query = $this->Record->get();
        $transactions_values = $this->Record->values;
        $this->Record->reset();

        // Get all active clients
        $currency = $this->Currencies->get($currency, Configure::get('Blesta.company_id'));
        $precision = $currency->precision ?? 4;
        $this->Record->select([
            'REPLACE(clients.id_format, ?, clients.id_value)' => 'client_id_code',
            'CONCAT(contacts.first_name, ?, contacts.last_name)' => 'client_name',
            'contacts.company', 'ROUND(IFNULL(transactions.total, ?), ?)' => 'total'
        ])
            ->from('clients')
            ->appendValues([$this->replacement_keys['clients']['ID_VALUE_TAG'], ' ', 0, $precision])
            ->innerJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)
            ->on('contacts.contact_type', '=', 'primary')
            ->innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)
            ->leftJoin(
                [$transactions_sub_query => 'transactions'],
                'transactions.client_id',
                '=',
                'clients.id',
                false
            )
            ->appendValues($transactions_values)
            ->where('clients.status', '=', 'active')
            ->where('client_groups.company_id', '=', $company_id)
            ->group(['clients.id']);

        return new IteratorIterator($this->Record->getStatement());
    }
}
