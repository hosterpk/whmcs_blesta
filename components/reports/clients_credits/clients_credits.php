<?php
use Blesta\Core\Util\Common\Traits\Container;

/**
 * ClientsCredits report
 *
 * @package blesta
 * @subpackage components.reports.clients_credits
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ClientsCredits extends Report
{
    // Load traits
    use Container;

    /**
     * Load language
     */
    public function __construct()
    {
        Loader::loadModels(
            $this,
            ['Clients', 'Currencies', 'Companies', 'Transactions']
        );
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this report
        Language::loadLang('clients_credits', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return Language::_('ClientsCredits.name', true);
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
        Loader::loadHelpers($this, ['Javascript']);

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('options', 'default');
        $this->view->setDefaultView('components' . DS . 'reports' . DS . 'clients_credits' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Fetch all client statuses
        $statuses = $this->Clients->getStatusTypes();

        // Fetch all company currencies
        $currencies = $this->Currencies->getAll($company_id);
        $currencies = $this->Form->collapseObjectArray($currencies, 'code', 'code');

        $default_currency = $this->Companies->getSetting($company_id, 'default_currency');

        $this->view->set('statuses', $statuses);
        $this->view->set('currencies', $currencies);
        $this->view->set('default_currency', $default_currency);
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function getKeyInfo()
    {
        return [
            'id_code' => ['name' => Language::_('ClientsCredits.heading.id_code', true)],
            'first_name' => ['name' => Language::_('ClientsCredits.heading.first_name', true)],
            'last_name' => ['name' => Language::_('ClientsCredits.heading.last_name', true)],
            'company' => ['name' => Language::_('ClientsCredits.heading.company', true)],
            'email' => ['name' => Language::_('ClientsCredits.heading.email', true)],
            'credits' => ['name' => Language::_('ClientsCredits.heading.credits', true)],
            'currency' => ['name' => Language::_('ClientsCredits.heading.currency', true)]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($company_id, array $vars)
    {
        Loader::loadHelpers($this, ['Form']);

        // Fetch all the clients matching the status
        if (!array_key_exists($vars['status'] ?? null, $this->Clients->getStatusTypes())) {
            $this->Input->setErrors(
                ['valid' => ['status' => Language::_('ClientsCredits.!error.status', true)]]
            );

            return;
        }

        $clients = $this->Clients->getAll($vars['status'], ['company_id' => $company_id]);

        // Validate currency
        $currency = $vars['currency'] ?? null;
        $currencies = $this->Currencies->getAll($company_id);
        $currencies = $this->Form->collapseObjectArray($currencies, 'code', 'code');

        if (!array_key_exists($currency, $currencies)) {
            $this->Input->setErrors(
                ['valid' => ['currency' => Language::_('ClientsCredits.!error.currency', true)]]
            );

            return;
        }

        // Fetch unapplied transactions for each client
        foreach ($clients as $key => &$client) {
            $client->credits = 0;
            $client->currency = $currency;

            $transactions = $this->Transactions->getCredits($client->id, $currency);
            foreach ($transactions[$currency] ?? [] as $transaction) {
                $client->credits += $transaction['credit'];
            }

            if (empty($client->credits)) {
                unset($clients[$key]);
            }
        }

        return $clients;
    }

    /**
     * Returns any errors
     *
     * @return mixed An array of errors, false if no errors set
     */
    public function errors()
    {
        return $this->Input->errors();
    }
}
