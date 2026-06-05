<?php

use Blesta\Core\Util\Filters\ExtensionFilters;

/**
 * Admin Company Gateway Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminCompanyGateways extends AdminController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['GatewayManager']);
    }

    /**
     * Gateway Settings index page
     */
    public function index()
    {
        $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
    }

    /**
     * Gateways Installed page
     */
    public function installed()
    {
        $this->uses(['Companies', 'Accounts']);
        
        // Update default gateway settings
        if (!empty($this->post)) {
            if (isset($this->post['default_gateways'])) {
                // Get the old default gateway settings
                $old_default_setting = $this->Companies->getSetting(
                    $this->company_id,
                    'default_merchant_gateway'
                );
                $old_default_value = ($old_default_setting && isset($old_default_setting->value))
                    ? $old_default_setting->value
                    : null;
                $old_default_gateways = $old_default_value ? json_decode($old_default_value, true) : [];

                // Update NULL gateway_id accounts to the old default gateway before switching
                if (is_array($old_default_gateways) && !empty($old_default_gateways)) {
                    foreach ($old_default_gateways as $currency => $old_gateway_id) {
                        // Check if this currency's default gateway is changing
                        $new_gateway_id = $this->post['default_gateways'][$currency] ?? null;

                        if ($new_gateway_id && $old_gateway_id != $new_gateway_id) {
                            // Migrate NULL gateway_id accounts to the old default gateway
                            $this->Accounts->migrateNullGatewayAccounts($currency, $old_gateway_id);
                        }
                    }
                }

                $this->Companies->setSetting(
                    $this->company_id,
                    'default_merchant_gateway',
                    json_encode($this->post['default_gateways'])
                );

                if (($errors = $this->Companies->errors())) {
                    $this->setMessage('error', $errors);
                } else {
                    $this->flashMessage('message', Language::_('AdminCompanyGateways.!success.default_gateways_updated', true));
                    $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
                }
            }
        }

        $this->setTabs();
        $this->set('show_left_nav', !$this->isAjax());

        $gateways = $this->GatewayManager->getAll($this->company_id);

        // Fetch company currencies, with a merchant gateway
        $gateway_currencies = [];
        $merchant_gateways = $this->Form->collapseObjectArray($gateways['merchant'] ?? [], 'name', 'id');
        foreach ($gateways['merchant'] ?? [] as $gateway) {
            $currencies = $this->GatewayManager->getCurrencies($gateway->id);
            foreach ($currencies as $currency) {
                $gateway_currencies[$currency->currency][$currency->gateway_id] = $merchant_gateways[$currency->gateway_id];
            }
        }

        // Fetch default merchant gateway
        $default_gateways = $this->Companies->getSetting(
            $this->company_id,
            'default_merchant_gateway'
        );
        $default_gateways = isset($default_gateways->value) ? json_decode($default_gateways->value, true) : [];

        $filters = new ExtensionFilters();
        $this->set(
            'filters',
            $filters->getFilters([
                'placeholder' => Language::_('AdminCompanyGateways.text_filter_placeholder', true)
            ])
        );

        $this->set('gateway_types', ['merchant', 'nonmerchant']);
        $this->set('gateways', $gateways);
        $this->set('gateway_currencies', $gateway_currencies);
        $this->set('default_gateways', $default_gateways);

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Gateways Available page
     */
    public function available()
    {
        $filters = new ExtensionFilters();
        $this->set(
            'filters',
            $filters->getFilters([
                'placeholder' => Language::_('AdminCompanyGateways.text_filter_placeholder', true)
            ])
        );

        $this->setTabs();
        $this->set('show_left_nav', !$this->isAjax());
        $this->set('gateways', $this->GatewayManager->getAvailable(null, $this->company_id));
        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Sets the installed/available tabs to the view
     */
    private function setTabs()
    {
        $this->set(
            'link_tabs',
            [
                [
                    'name' => Language::_('AdminCompanyGateways.!tab.installed', true),
                    'uri' => 'installed'
                ],
                [
                    'name' => Language::_('AdminCompanyGateways.!tab.available', true),
                    'uri' => 'available'
                ]
            ]
        );
    }

    /**
     * Manage a gateway
     */
    public function manage()
    {
        // Redirect if the gateway ID is invalid
        if (!isset($this->get[0])
            || !($gateway_info = $this->GatewayManager->get((int) $this->get[0]))
            || ($gateway_info->company_id != $this->company_id)
        ) {
            $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
        }

        $this->uses(['Currencies', 'Companies']);
        $this->components(['Gateways']);
        $this->helpers(['DataStructure']);

        $gateway = $this->Gateways->create($gateway_info->class, $gateway_info->type);

        // Ready the array helper to convert meta object data to a key/value array
        $this->ArrayHelper = $this->DataStructure->create('Array');
        $vars = $this->ArrayHelper->numericToKey($gateway_info->meta, 'key', 'value');

        // Get all currencies for this company
        $company_currencies = $this->Currencies->getAll($this->company_id);

        if (!empty($this->post)) {
            // Update the gateway's meta data and currencies
            $currencies = isset($this->post['currencies']) ? $this->post['currencies'] : [];

            // Remove currencies from meta data
            unset($this->post['currencies']);

            // Validate that all selected currencies are installed for this company
            $valid_currencies = [];
            $invalid_currencies = [];
            foreach ($currencies as $currency) {
                $is_valid = false;
                foreach ($company_currencies as $company_currency) {
                    if ($company_currency->code == $currency) {
                        $valid_currencies[] = $currency;
                        $is_valid = true;
                        break;
                    }
                }
                if (!$is_valid) {
                    $invalid_currencies[] = $currency;
                }
            }

            $this->GatewayManager->edit($this->get[0], ['meta' => $this->post, 'currencies' => $valid_currencies]);

            if (($errors = $this->GatewayManager->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors);
                $vars = $this->post;
                $vars['currencies'] = $currencies;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyGateways.!success.manage_updated', true));
                $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
            }
        } else {
            // Set currencies in use by this gateway
            foreach ($gateway_info->currencies as $currency) {
                $vars['currencies'][] = $currency->currency;
            }
        }

        // Get default merchant gateway settings
        $default_gateways = [];
        $default_merchant_gateway = $this->Companies->getSetting($this->company_id, 'default_merchant_gateway');
        if (!empty($default_merchant_gateway->value)) {
            $default_gateways = json_decode($default_merchant_gateway->value, true);
        }

        // Create a list of gateway currencies and their statuses
        $gateways_in_use = [];
        $gateway_currencies = [];
        $i = 0;
        foreach ($gateway->getCurrencies() as $currency) {
            $gateway_currencies[$i] = new stdClass();
            $gateway_currencies[$i]->code = $currency;
            $gateway_currencies[$i]->disabled = true;
            $gateway_currencies[$i]->available = false;
            $gateway_currencies[$i]->in_use_by = null;

            // Check if this currency is in use by another gateway
            if ($gateway_info->type == 'merchant'
                && $this->GatewayManager->verifyCurrency($currency, $this->company_id, $gateway_info->id)
            ) {
                // Fetch the gateway that is using this currency
                if (!isset($gateways_in_use[$currency])) {
                    // Set all currencies this gateway is using so we don't need to fetch it again
                    if (($alt_gateway = $this->GatewayManager->getInstalledMerchant($this->company_id, $currency))) {
                        foreach ($alt_gateway->currencies as $alt_gateway_currency) {
                            if ($alt_gateway->id == $gateway_info->id) {
                                continue;
                            }
                            $gateways_in_use[$alt_gateway_currency->currency] = $alt_gateway;
                        }
                    }

                    unset($alt_gateway);
                }

                $gateway_currencies[$i]->in_use_by = (isset($gateways_in_use[$currency])
                    ? $gateways_in_use[$currency]
                    : null
                );
            }

            // Set whether this gateway currency is available for use by this company
            foreach ($company_currencies as $company_currency) {
                // This currency is available for use by this company
                if ($company_currency->code == $currency) {
                    $gateway_currencies[$i]->available = true;
                    $gateway_currencies[$i]->disabled = false;
                    break;
                }
            }

            $i++;
        }

        $this->set('gateway_currencies', $gateway_currencies);
        $this->set('gateway_info', $gateway_info);
        $this->set('gateway', $gateway);
        $this->set('content', $gateway->getSettings($vars));
        $this->set('vars', $vars);
        $this->structure->set(
            'page_title',
            Language::_('AdminCompanyGateways.manage.page_title', true, $gateway_info->name)
        );
    }

    /**
     * Install a gateway for this company
     */
    public function install()
    {
        if (!isset($this->post['id'])) {
            $this->redirect($this->base_uri . 'settings/company/gateways/available/');
        }

        $gateway_id = $this->GatewayManager->add([
            'class' => $this->post['id'],
            'company_id' => $this->company_id,
            'type' => isset($this->post['type']) ? $this->post['type'] : 'merchant'
        ]);

        if (($errors = $this->GatewayManager->errors())) {
            $this->flashMessage('error', $errors);
            $this->redirect($this->base_uri . 'settings/company/gateways/available/');
        } else {
            $this->flashMessage('message', Language::_('AdminCompanyGateways.!success.installed', true));
            $this->redirect($this->base_uri . 'settings/company/gateways/manage/' . $gateway_id);
        }
    }

    /**
     * Uninstall a gateway for this company
     */
    public function uninstall()
    {
        if (!isset($this->post['id']) || !($gateway = $this->GatewayManager->get($this->post['id']))) {
            $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
        }

        $this->GatewayManager->delete($this->post['id']);

        if (($errors = $this->GatewayManager->errors())) {
            $this->setMessage('error', $errors);
        } else {
            $this->flashMessage('message', Language::_('AdminCompanyGateways.!success.uninstalled', true));
            $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
        }
    }

    /**
     * Upgrade a gateway
     */
    public function upgrade()
    {
        // Fetch the module to upgrade
        if (!isset($this->post['id']) || !($gateway = $this->GatewayManager->get($this->post['id']))) {
            $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
        }

        $this->GatewayManager->upgrade($this->post['id']);

        if (($errors = $this->GatewayManager->errors())) {
            $this->flashMessage('error', $errors);
        } else {
            $this->flashMessage('message', Language::_('AdminCompanyGateways.!success.upgraded', true));
        }
        $this->redirect($this->base_uri . 'settings/company/gateways/installed/');
    }
}
