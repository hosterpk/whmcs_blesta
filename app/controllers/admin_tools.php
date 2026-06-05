<?php

use Blesta\Core\Util\Filters\LogFilters;
use Blesta\Core\Util\Filters\SystemLogFilters;
use Blesta\Core\Util\LogReader\SystemLogReader;

/**
 * Admin Tools
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminTools extends AppController
{
    /**
     * Tools pre-action
     */
    public function preAction()
    {
        parent::preAction();

        // Require login
        $orig_action = $this->action;
        if (substr($this->action, 0, 3) == 'log') {
            $this->action = 'logs';
        }
        if ($this->action == 'integritycheck') {
            $this->action = 'utilities';
        }

        $this->requireLogin();
        $this->action = $orig_action;

        $this->uses(['Logs']);
        Language::loadLang(['admin_tools']);

        Loader::loadModels($this, ['Companies', 'Transactions']);

    }

    /**
     * Index
     */
    public function index()
    {
        // Default to logs (module log)
        $this->redirect($this->base_uri . 'tools/logs/module/');
    }

    /**
     * All logs
     */
    public function logs()
    {
        // Default to module log
        $this->redirect($this->base_uri . 'tools/logs/module/');
    }

    /**
     * List module log data
     */
    public function logModule()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');
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

        // Fetch all the module log groups
        $module_logs = $this->Logs->getModuleList($page, [$sort => $order], true, $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('module_logs', $module_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getModuleListCount(true, $post_filters),
                'uri' => $this->base_uri . 'tools/logs/module/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List module log data
     */
    public function logMessenger()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');
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

        // Fetch all the messenger log groups
        $messenger_logs = $this->Logs->getMessengerList($page, [$sort => $order], true, $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('messenger_logs', $messenger_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getMessengerListCount(true, $post_filters),
                'uri' => $this->base_uri . 'tools/logs/messenger/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * AJAX request for all module log data under a specific module log group
     */
    public function moduleLogList()
    {
        if (!isset($this->get[0]) || !$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $vars = [
            'module_logs' => $this->Logs->getModuleGroupList($this->get[0])
        ];
        // Fetch module logs for a specific group and send the template
        echo $this->partial('admin_tools_moduleloglist', $vars);

        // Render without layout
        return false;
    }

    /**
     * AJAX request for all messenger log data under a specific messenger log group
     */
    public function messengerLogList()
    {
        if (!isset($this->get[0]) || !$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $vars = [
            'messenger_logs' => $this->Logs->getMessengerGroupList($this->get[0])
        ];
        // Fetch messenger logs for a specific group and send the template
        echo $this->partial('admin_tools_messengerloglist', $vars);

        // Render without layout
        return false;
    }

    /**
     * List gateway log data
     */
    public function logGateway()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');
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

        // Fetch all the gateway log groups
        $gateway_logs = $this->Logs->getGatewayList($page, [$sort => $order], true, $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('gateway_logs', $gateway_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getGatewayListCount(true, $post_filters),
                'uri' => $this->base_uri . 'tools/logs/gateway/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * AJAX request for all gateway log data under a specific gateway log group
     */
    public function gatewayLogList()
    {
        if (!isset($this->get[0]) || !$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $vars = [
            'gateway_logs' => $this->Logs->getGatewayGroupList($this->get[0])
        ];
        // Fetch module logs for a specific group and send the template
        echo $this->partial('admin_tools_gatewayloglist', $vars);

        // Render without layout
        return false;
    }

    /**
     * List all email log data
     */
    public function logEmail()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_sent');
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

        // Fetch all the module log groups
        $email_logs = $this->Logs->getEmailList($page, [$sort => $order], $post_filters);

        // Format CC addresses, if available
        if ($email_logs) {
            for ($i = 0, $num_logs = count($email_logs); $i < $num_logs; $i++) {
                // Format all CC addresses from CSV to array
                $cc_addresses = $email_logs[$i]->cc_address ?? '';
                $email_logs[$i]->cc_address = [];
                foreach (explode(',', $cc_addresses ?? '') as $address) {
                    if (!empty($address)) {
                        $email_logs[$i]->cc_address[] = $address;
                    }
                }
            }
        }

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('email_logs', $email_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getEmailListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/email/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all user log data
     */
    public function logUsers()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');
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

        $user_logs = $this->Logs->getUserList($page, [$sort => $order], $post_filters);

        if (!isset($this->SettingsCollection)) {
            $this->components(['SettingsCollection']);
        }

        // Check whether GeoIp is enabled
        $system_settings = $this->SettingsCollection->fetchSystemSettings();
        $use_geo_ip = ($system_settings['geoip_enabled'] == 'true');
        if ($use_geo_ip) {
            // Load GeoIP database
            $this->components(['Net']);
            if (!isset($this->NetGeoIp)) {
                $this->NetGeoIp = $this->Net->create('NetGeoIp');
            }
        }

        foreach ($user_logs as &$user) {
            $user->geo_ip = [];
            if ($use_geo_ip) {
                try {
                    $user->geo_ip = ['location' => $this->NetGeoIp->getLocation($user->ip_address)];
                } catch (Throwable $e) {
                    // Nothing to do
                }
            }
        }

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('user_logs', $user_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getUserListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/users/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all contact log data
     */
    public function logContacts()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_changed');
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

        // Fetch all contact logs
        $contact_logs = $this->Logs->getContactList($page, [$sort => $order], $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('contact_logs', $contact_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getContactListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/contacts/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all service changes log data
     */
    public function logServiceChanges()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_changed');
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

        $service_change_logs = $this->Logs->getServiceChangeList($page, [$sort => $order], $post_filters);

        // Get default currency
        $default_currency = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'default_currency');
        $default_currency = $default_currency['value'];

        // Get transaction types
        $transaction_types = $this->Transactions->getTypes();
        $transaction_types = $this->Form->collapseObjectArray($transaction_types, 'real_name', 'name');

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('service_change_logs', $service_change_logs);
        $this->set('default_currency', $default_currency);
        $this->set('transaction_types', $transaction_types);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getServiceChangeListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/servicechanges/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all client settings log data
     */
    public function logClientSettings()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_changed');
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

        $client_settings_logs = $this->Logs->getClientSettingsList($page, [$sort => $order], $post_filters);

        if (!isset($this->SettingsCollection)) {
            $this->components(['SettingsCollection']);
        }

        // Check whether GeoIp is enabled
        $system_settings = $this->SettingsCollection->fetchSystemSettings();
        $use_geo_ip = ($system_settings['geoip_enabled'] == 'true');
        if ($use_geo_ip) {
            // Load GeoIP database
            $this->components(['Net']);
            if (!isset($this->NetGeoIp)) {
                $this->NetGeoIp = $this->Net->create('NetGeoIp');
            }
        }

        foreach ($client_settings_logs as &$setting_log) {
            $setting_log->geo_ip = [];
            if ($use_geo_ip) {
                try {
                    $setting_log->geo_ip = ['location' => $this->NetGeoIp->getLocation($setting_log->ip_address)];
                } catch (Throwable $e) {
                    // Nothing to do
                }
            }
        }

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('client_settings_logs', $client_settings_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getClientSettingsListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/clientsettings/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all transaction log data
     */
    public function logTransactions()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_changed');
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

        // Fetch all transaction logs
        $transaction_logs = $this->Logs->getTransactionList($page, [$sort => $order], $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('transaction_logs', $transaction_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getTransactionListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/transactions/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all invoice delivery log data
     */
    public function logInvoiceDelivery()
    {
        $this->uses(['Invoices']);

        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_sent');
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

        // Fetches all invoice logs
        $invoice_logs = $this->Invoices->getDeliveryList(null, $page, [$sort => $order], $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('invoice_logs', $invoice_logs);
        $this->set('link_tabs', $this->getLogNames());
        $this->set('invoice_methods', $this->Invoices->getDeliveryMethods(null, null, false));

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Invoices->getDeliveryListCount(null, $post_filters),
                'uri' => $this->base_uri . 'tools/logs/invoicedelivery/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List all account access log data
     */
    public function logAccountAccess()
    {
        // When/who unencrypted credit cards

        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_accessed');
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

        // Fetch all access logs
        $access_logs = $this->Logs->getAccountAccessList($page, [$sort => $order], $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('access_logs', $access_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getAccountAccessListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/accountaccess/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * AJAX request for all account access log data
     */
    public function accountAccess()
    {
        if (!isset($this->get[0]) || !$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['Accounts']);

        $vars = [
            'access_logs' => $this->Logs->getAccountAccessLog($this->get[0]),
            'account_types' => $this->Accounts->getTypes(),
            'cc_types' => $this->Accounts->getCcTypes(),
            'ach_types' => $this->Accounts->getAchTypes()
        ];
        // Fetch module logs for a specific group and send the template
        echo $this->partial('admin_tools_accountaccess', $vars);

        // Render without layout
        return false;
    }

    /**
     * List all cron log data
     */
    public function logCron()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'start_date');
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

        // Fetch all cron logs
        $cron_logs = $this->Logs->getCronList($page, [$sort => $order], $post_filters);

        // Set the input field filters for the widget
        $log_filters = new LogFilters();
        $this->set(
            'filters',
            $log_filters->getFilters(
                [
                    'language' => Configure::get('Blesta.language'),
                    'company_id' => Configure::get('Blesta.company_id')
                ],
                $post_filters
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('cron_logs', $cron_logs);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Logs->getCronListCount($post_filters),
                'uri' => $this->base_uri . 'tools/logs/cron/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * List system file log data
     */
    public function logSystem()
    {
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);

        // Set filters from post input
        $post_filters = [];
        if (isset($this->post['filters'])) {
            $post_filters = $this->post['filters'];
            unset($this->post['filters']);

            foreach ($post_filters as $filter => $value) {
                // Skip array filters (like levels) from empty check
                if (!is_array($value) && empty($value)) {
                    unset($post_filters[$filter]);
                }
            }
        }

        // Apply default filters when no filters have been submitted
        if (empty($post_filters)) {
            $post_filters['levels'] = SystemLogFilters::DEFAULT_LEVELS;
            $post_filters['start_date'] = date('Y-m-d');
        }

        // Get the log directory from system settings
        $this->uses(['Settings']);
        $log_dir_setting = $this->Settings->getSetting('log_dir');
        $log_dir = $log_dir_setting ? $log_dir_setting->value : '';

        $system_logs = [];
        $total_results = 0;
        $log_dir_valid = !empty($log_dir) && is_dir($log_dir) && is_readable($log_dir);

        if ($log_dir_valid) {
            $per_page = Configure::get('Blesta.results_per_page') ?? 25;
            $reader = new SystemLogReader($log_dir, $per_page);
            $system_logs = $reader->getEntries($page, $post_filters);
            $total_results = $reader->getEntryCount($post_filters);
        }

        // Set the input field filters for the widget
        $log_filters = new SystemLogFilters();
        $filter_options = [
            'language' => Configure::get('Blesta.language'),
            'company_id' => Configure::get('Blesta.company_id')
        ];
        $this->set('filters', $log_filters->getFilters($filter_options, $post_filters));
        $this->set(
            'level_checkbox_html',
            $log_filters->getLevelCheckboxHtml(
                $filter_options,
                $post_filters['levels'] ?? SystemLogFilters::DEFAULT_LEVELS
            )
        );

        $this->set('filter_vars', $post_filters);
        $this->set('system_logs', $system_logs);
        $this->set('log_dir_valid', $log_dir_valid);
        $this->set('link_tabs', $this->getLogNames());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'tools/logs/system/[p]/',
                'params' => []
            ]
        );
        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]));
    }

    /**
     * Retrieves a list of link tabs for use in templates
     */
    private function getLogNames()
    {
        return [
            ['name' => Language::_('AdminTools.getlognames.text_module', true), 'uri' => 'module'],
            ['name' => Language::_('AdminTools.getlognames.text_messenger', true), 'uri' => 'messenger'],
            ['name' => Language::_('AdminTools.getlognames.text_gateway', true), 'uri' => 'gateway'],
            ['name' => Language::_('AdminTools.getlognames.text_email', true), 'uri' => 'email'],
            ['name' => Language::_('AdminTools.getlognames.text_users', true), 'uri' => 'users'],
            ['name' => Language::_('AdminTools.getlognames.text_contacts', true), 'uri' => 'contacts'],
            ['name' => Language::_('AdminTools.getlognames.text_service_changes', true), 'uri' => 'servicechanges'],
            ['name' => Language::_('AdminTools.getlognames.text_client_settings', true), 'uri' => 'clientsettings'],
            ['name' => Language::_('AdminTools.getlognames.text_accountaccess', true), 'uri' => 'accountaccess'],
            ['name' => Language::_('AdminTools.getlognames.text_transactions', true), 'uri' => 'transactions'],
            ['name' => Language::_('AdminTools.getlognames.text_cron', true), 'uri' => 'cron'],
            ['name' => Language::_('AdminTools.getlognames.text_invoice_delivery', true), 'uri' => 'invoicedelivery'],
            ['name' => Language::_('AdminTools.getlognames.text_system', true), 'uri' => 'system'],
        ];
    }

    /**
     * Currency conversion
     */
    public function convertCurrency()
    {
        $this->uses(['Currencies']);
        $this->components(['SettingsCollection']);

        $vars = new stdClass();

        // Set current default currency
        $default_currency = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'default_currency');
        $vars->to_currency = $default_currency['value'];

        // Do the conversion
        if (!empty($this->post)) {
            $vars = (object) $this->post;

            // Convert the currency
            $amount = (isset($this->post['amount']) ? $this->post['amount'] : 0);
            $to_currency = (isset($this->post['to_currency']) ? $this->post['to_currency'] : '');
            $from_currency = (isset($this->post['from_currency']) ? $this->post['from_currency'] : '');
            $converted_amount = $this->Currencies->convert($amount, $from_currency, $to_currency, $this->company_id);

            $this->setMessage(
                'message',
                Language::_(
                    'AdminTools.!success.currency_converted',
                    true,
                    $this->Currencies->toCurrency($amount, $from_currency, $this->company_id, true, true, true),
                    $this->Currencies->toCurrency($converted_amount, $to_currency, $this->company_id, true, true, true)
                )
            );
        }

        $this->set(
            'currencies',
            $this->Form->collapseObjectArray($this->Currencies->getAll($this->company_id), 'code', 'code')
        );
        $this->set('vars', $vars);
    }

    /**
     * Displays a list of utilities
     */
    public function utilities()
    {
        // Handle clear file cache request
        if (isset($this->post['clear_file_cache'])) {
            $company_id = Configure::get('Blesta.company_id');

            // Clear views cache (has subdirectories per client)
            Cache::emptyCache($company_id . DS . 'views' . DS);
            $view_dirs = glob(CACHEDIR . $company_id . DS . 'views' . DS . '*', GLOB_ONLYDIR) ?: [];
            foreach ($view_dirs as $view_dir) {
                Cache::emptyCache($company_id . DS . 'views' . DS . basename($view_dir) . DS);
            }

            // Clear nav cache (has subdirectories per staff ID)
            $nav_dirs = glob(CACHEDIR . $company_id . DS . 'nav' . DS . '*', GLOB_ONLYDIR) ?: [];
            foreach ($nav_dirs as $nav_dir) {
                Cache::emptyCache($company_id . DS . 'nav' . DS . basename($nav_dir) . DS);
            }

            // Clear plugins cache (has subdirectories per plugin)
            $plugin_dirs = glob(CACHEDIR . $company_id . DS . 'plugins' . DS . '*', GLOB_ONLYDIR) ?: [];
            foreach ($plugin_dirs as $plugin_dir) {
                Cache::emptyCache($company_id . DS . 'plugins' . DS . basename($plugin_dir) . DS);
            }

            $this->flashMessage('message', Language::_('AdminTools.!success.cache_cleared', true));
            $this->redirect($this->base_uri . 'tools/utilities/');
        }

        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        // Load database info from the config
        $database_info = Configure::get('Blesta.database_info');

        // Fetch non-utf8mb4 tables
        $non_utf8mb4_tables = $this->Record->select(
                ["concat('ALTER TABLE `', TABLE_SCHEMA, '`.`', table_name, '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')" => 'query'],
                false
            )->
            from('information_schema.tables')->
            where('TABLE_SCHEMA', '=', $database_info['database'])->
            where('TABLE_COLLATION', '!=', 'utf8mb4_unicode_ci')->
            fetchAll();

        // Fetch non-utf8mb4 columns
        $select_string = "concat(
            'ALTER TABLE `',
            columns.TABLE_SCHEMA,
            '`.`',
            columns.table_name,
            '` MODIFY `',
            columns.column_name,
            '` ',
            columns.data_type,
            IF(columns.data_type NOT LIKE '%text%', '(', ''),
            IF(columns.data_type NOT LIKE '%text%', columns.CHARACTER_MAXIMUM_LENGTH, ''),
            IF(columns.data_type NOT LIKE '%text%', ')', ''),
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
        )";
        $non_utf8mb4_columns = $this->Record->select([$select_string => 'query'], false)->
            from(['information_schema.columns' => 'columns'])->
            where('TABLE_SCHEMA', '=', $database_info['database'])->
            where('COLLATION_NAME', '!=', 'utf8mb4_unicode_ci')->
            where('DATA_TYPE', '!=', 'enum')->
            fetchAll();

        if (isset($this->post['update_to_utf8mb4'])) {
            // Update the collation for the database
            $this->Record->query(
                'ALTER DATABASE `' . $database_info['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
            );
            foreach ($non_utf8mb4_tables as $table) {
                $this->Record->query($table->query);
            }
            foreach ($non_utf8mb4_columns as $column) {
                $this->Record->query($column->query);
            }

            try {
                // Replace charset query in the config
                $file_config = file_get_contents(CONFIGDIR . 'blesta.php');
                $updated_file_config = str_replace('SET NAMES \'utf8\'', 'SET NAMES \'utf8mb4\'', $file_config);
                file_put_contents(CONFIGDIR . 'blesta.php', $updated_file_config);
            } catch (Throwable $e) {
                // Do nothing
            }

            // Set success message and redirect
            $this->flashMessage('message', Language::_('AdminTools.!success.collation_updated', true));
            $this->redirect($this->base_uri . 'tools/utilities/');
        }


        // Check if the MySQL/MariaDB version meets the minimum system requirements
        $pdo = $this->Record->connect();
        $server = (object) $pdo->query("SHOW VARIABLES like '%version%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        $utf8mb4_requirements_met = true;
        if (
            (str_contains($server->version_comment, 'MySQL')
                && version_compare($server->version, '5.7.7', '<')
            )
            || (str_contains($server->version_comment, 'MariaDB')
                && version_compare($server->version, '10.2.2', '<')
            )
        ) {
            $utf8mb4_requirements_met = false;
        }


        $config_dbinfo = Configure::get('Database.profile');
        $config_charset_mb4 = is_array($config_dbinfo)
            && isset($config_dbinfo['charset_query'])
            && strpos($config_dbinfo['charset_query'], 'utf8mb4');

        $this->set('all_tables_utf8mb4', empty($non_utf8mb4_tables) && empty($non_utf8mb4_columns));
        $this->set('utf8mb4_requirements_met', $utf8mb4_requirements_met);
        $this->set('config_charset_mb4', $config_charset_mb4);
    }

    /**
     * System Integrity Check — verifies installed files against a shipped manifest.json
     */
    public function integrityCheck()
    {
        $manifest_path = ROOTWEBDIR . 'manifest.json';

        // Handle AJAX batch check request
        if (isset($this->post['batch_check'])) {
            $this->outputAsJson($this->integrityCheckBatch($manifest_path));
            return false;
        }

        // Handle download report
        if (isset($this->post['download_report'])) {
            $this->integrityCheckDownload($manifest_path);
            return;
        }

        // Pass CSRF token and manifest info for the JS-driven check
        $this->set('csrf_token', $this->Form->getCsrfToken());
        $this->set('manifest_exists', file_exists($manifest_path));

        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path));
            if ($manifest && !empty($manifest->files)) {
                $this->set('manifest_total', count($manifest->files));
                $this->set('manifest_version', $manifest->version ?? null);
                $this->set('manifest_date', $manifest->generated_at ?? null);
            }
        }
    }

    /**
     * Processes a batch of manifest files and returns results as JSON
     *
     * @param string $manifest_path Path to the manifest.json file
     * @return array JSON response with batch results
     */
    private function integrityCheckBatch($manifest_path)
    {
        $binary_extensions = [
            'png', 'jpg', 'jpeg', 'gif', 'ico', 'bmp', 'svg', 'webp',
            'woff', 'woff2', 'ttf', 'eot', 'otf',
            'zip', 'gz', 'tar', 'phar',
            'pdf', 'swf', 'exe', 'dll', 'so',
            'mp3', 'mp4', 'wav', 'ogg',
            'p12', 'pem', 'crt', 'key'
        ];

        if (!file_exists($manifest_path)) {
            return ['error' => Language::_('AdminTools.integritycheck.text_manifest_not_found', true)];
        }

        $manifest = json_decode(file_get_contents($manifest_path));
        if (!$manifest || empty($manifest->files)) {
            return ['error' => Language::_('AdminTools.integritycheck.text_manifest_not_found', true)];
        }

        $offset = max(0, (int) ($this->post['offset'] ?? 0));
        $batch_size = 500;
        $total = count($manifest->files);
        $batch = array_slice($manifest->files, $offset, $batch_size);

        $modified = [];
        $missing = [];
        $ok_count = 0;

        foreach ($batch as $entry) {
            $full_path = ROOTWEBDIR . str_replace('/', DS, $entry->path);

            if (!file_exists($full_path)) {
                $missing[] = [
                    'path' => $entry->path,
                    'category' => strpos($entry->path, 'vendors/') === 0 ? 'vendor' : 'core',
                ];
                continue;
            }

            // Try hash_file first (fast path — works for most unmodified files)
            $hash = hash_file('sha256', $full_path);

            // If mismatch on a text file, retry with line ending normalization
            if ($hash !== $entry->sha256) {
                $ext = strtolower(pathinfo($entry->path, PATHINFO_EXTENSION));
                if (!in_array($ext, $binary_extensions)) {
                    $content = file_get_contents($full_path);
                    $content = str_replace(["\r\n", "\r"], "\n", $content);
                    $hash = hash('sha256', $content);
                }
            }

            if ($hash !== $entry->sha256) {
                $modified[] = [
                    'path' => $entry->path,
                    'category' => strpos($entry->path, 'vendors/') === 0 ? 'vendor' : 'core',
                ];
            } else {
                $ok_count++;
            }
        }

        $next_offset = $offset + $batch_size;

        return [
            'modified' => $modified,
            'missing' => $missing,
            'ok_count' => $ok_count,
            'processed' => min($next_offset, $total),
            'total' => $total,
            'done' => $next_offset >= $total,
            'manifest_version' => $manifest->version ?? null,
            'manifest_date' => $manifest->generated_at ?? null,
        ];
    }

    /**
     * Generates and downloads a plain text integrity check report
     *
     * @param string $manifest_path Path to the manifest.json file
     */
    private function integrityCheckDownload($manifest_path)
    {
        $this->components(['Download']);

        $modified = json_decode($this->post['modified_files'] ?? '[]');
        $missing = json_decode($this->post['missing_files'] ?? '[]');
        $ok_count = (int) ($this->post['ok_count'] ?? 0);
        $total = (int) ($this->post['total_count'] ?? 0);

        $manifest_version = 'Unknown';
        $manifest_date = 'Unknown';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path));
            if ($manifest) {
                $manifest_version = $manifest->version ?? 'Unknown';
                $manifest_date = $manifest->generated_at ?? 'Unknown';
            }
        }

        $report = "Blesta System Integrity Check Report\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= "Manifest Version: " . $manifest_version . "\n";
        $report .= "Manifest Generated: " . $manifest_date . "\n\n";
        $report .= "Summary: " . $total . " files checked, "
            . count($modified) . " modified, "
            . count($missing) . " missing, "
            . $ok_count . " OK\n";

        if (!empty($modified)) {
            $report .= "\nMODIFIED FILES:\n";
            foreach ($modified as $file) {
                $label = ($file->category ?? '') === 'vendor' ? ' [Vendor]' : '';
                $report .= "  " . ($file->path ?? '') . $label . "\n";
            }
        }

        if (!empty($missing)) {
            $report .= "\nMISSING FILES:\n";
            foreach ($missing as $file) {
                $label = ($file->category ?? '') === 'vendor' ? ' [Vendor]' : '';
                $report .= "  " . ($file->path ?? '') . $label . "\n";
            }
        }

        if (empty($modified) && empty($missing)) {
            $report .= "\nAll files match the manifest.\n";
        }

        $this->Download->downloadData(
            'integrity-check-' . date('Y-m-d-His') . '.txt',
            $report
        );
        exit;
    }

    /**
     * Displays a list of renewing services
     */
    public function renewals()
    {
        $this->redirect($this->base_uri . 'tools/provisioning/');
    }

    /**
     * Displays a list of provisioning services
     */
    public function provisioning()
    {
        $this->uses(['Services', 'Logs', 'CronTasks', 'Companies', 'ServiceChanges']);
        $category = (isset($this->get[0]) ? $this->get[0] : 'provision');
        $page = (isset($this->get[1]) ? (int) $this->get[1] : 1);
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        switch ($category) {
            case 'provision':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'provision_pending_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('provision_pending_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+' . (int) abs($cron_task->interval ?? 5) . ' minutes',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getPendingPaidList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getPendingPaidCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/provision/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'renewal':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_renews');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'process_renewing_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('process_renewing_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+' . (int) abs($cron_task->interval ?? 5) . ' minutes',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getRenewablePaidList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getRenewablePaidCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/renewal/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'unpaid_renewal':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_renews');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'process_renewing_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('process_renewing_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+' . (int) abs($cron_task->interval ?? 5) . ' minutes',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getRenewableUnpaidList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getRenewableUnpaidCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/unpaid_renewal/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'suspension':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_renews');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'suspend_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('suspend_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+1 day',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getPendingSuspensionList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getPendingSuspensionCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/suspension/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'unsuspension':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_renews');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'unsuspend_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('unsuspend_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+' . (int) abs($cron_task->interval ?? 5) . ' minutes',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getPendingUnsuspensionList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getPendingUnsuspensionCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/unsuspension/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'cancelation':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_canceled');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'cancel_scheduled_services');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('cancel_scheduled_services', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+1 day',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                $services = $this->Services->getPendingCancelationList(true, $page, [$sort => $order], true);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->Services->getPendingCancelationCount(true, true),
                        'uri' => $this->base_uri . 'tools/provisioning/cancelation/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
            case 'changes':
                $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'date_added');

                // Get next cron run date
                $last_execution = $this->Logs->getCronLastExecution(1, 'process_service_changes');
                $last_execution = !empty($last_execution[0]->end_date) ? $last_execution[0]->end_date : $this->Logs->dateToUtc(date('c'));
                $cron_task = $this->CronTasks->getTaskRunByKey('process_service_changes', null, false, 'system');
                $next_execution = $this->Date->modify(
                    $last_execution,
                    '+1 day',
                    'Y-m-d H:i:s',
                    Configure::get('Blesta.company_timezone')
                );

                // Fetch all service changes
                $services = $this->ServiceChanges->getList($page, [$sort => $order], ['pending', 'error']);

                $settings = array_merge(
                    Configure::get('Blesta.pagination'),
                    [
                        'total_results' => $this->ServiceChanges->getListCount(['pending', 'error']),
                        'uri' => $this->base_uri . 'tools/provisioning/changes/[p]/',
                        'params' => ['sort' => $sort, 'order' => $order]
                    ]
                );
                break;
        }

        $this->setMessage('notice', Language::_('AdminTools.!notice.conditions_met', true));

        $this->set('category', $category);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('services', $services);
        $this->set('next_execution', $next_execution);

        $this->setPagination($this->get, $settings);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Update service max attempts
     */
    public function changeMaxAttempts()
    {
        if (!$this->isAjax() && empty($this->post)) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['Services']);
        $this->components(['Record']);

        // Fetch the service
        if (!isset($this->get[0])
            || !($service = $this->Services->get($this->get[0]))
        ) {
            $this->redirect($this->base_uri . 'tools/provisioning/');
        }

        $category = (isset($this->get[1]) ? $this->get[1] : 'provision');

        if (!empty($this->post)) {
            $service_renewal = $this->Record->select('service_invoices.maximum_attempts')->
                where('service_invoices.service_id', '=', $service->id)->
                where('service_invoices.type', '=', $category)->
                update('service_invoices', ['maximum_attempts' => $this->post['max_attempts']]);
            $this->flashMessage('message', Language::_('AdminTools.!success.max_updated', true));
            $this->redirect($this->base_uri . 'tools/provisioning/' . $category . '/');
        }

        $service_renewal = $this->Record->select('service_invoices.maximum_attempts')->
            from('service_invoices')->
            where('service_invoices.service_id', '=', $service->id)->
            where('service_invoices.type', '=', $category)->
            fetch();
        $service->maximum_attempts = $service_renewal->maximum_attempts ?? 0;

        echo $this->partial('admin_tools_change_max_attempts', ['service' => $service]);
        return false;
    }

    /**
     * Remove service from renewal queue
     */
    public function dequeue()
    {
        $this->uses(['Services']);
        $this->components(['Record']);

        // Fetch the service
        if (!isset($this->get[0])
            || !($service = $this->Services->get($this->get[0]))
        ) {
            $this->redirect($this->base_uri . 'tools/provisioning/');
        }

        $this->Record->from('service_invoices')->
            where('service_invoices.service_id', '=', $service->id)->
            delete();

        // Success
        $this->flashMessage('message', Language::_('AdminTools.!success.dequeue', true));
        $this->redirect($this->base_uri . 'tools/provisioning/');
        return false;
    }

    /**
     * Shows all the blacklist rules
     */
    public function blacklist()
    {
        $this->uses(['Blacklist']);

        // Set current page of results
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'plugin_dir');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'asc');

        // Fetch all rules
        $rules = $this->Blacklist->getList($page, [$sort => $order]);

        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('rules', $rules);

        // Overwrite default pagination settings
        $total_results = $this->Blacklist->getListCount();
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'tools/blacklist/[p]/',
                'params' => ['sort' => $sort, 'order' => $order],
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Adds a new rule to the blacklist
     */
    public function blacklistAdd()
    {
        $this->uses(['Blacklist']);

        $vars = (object) [];
        if (!empty($this->post)) {
            $vars = (object) $this->post;
            $this->Blacklist->add($this->post);

            if (($errors = $this->Blacklist->errors())) {
                $this->setMessage('error', $errors);
            } else {
                $this->flashMessage(
                    'message',
                    Language::_('AdminTools.!success.rule_added', true)
                );
                $this->redirect($this->base_uri . 'tools/blacklist/');
            }
        }

        // Get rule types
        $types = $this->Blacklist->getTypes();

        $this->set('types', $types);
        $this->set('vars', $vars);
    }

    /**
     * Deletes a rule from the blacklist
     */
    public function blacklistDelete()
    {
        $this->uses(['Blacklist']);

        // Ensure a valid rule was given
        $rule_id = $this->get[0] ?? $this->post['id'] ?? null;
        if (empty($rule_id) || !($rule = $this->Blacklist->get($rule_id))) {
            $this->redirect($this->base_uri . 'tools/blacklist/');
        }

        // Attempt to remove blacklist rule
        $this->Blacklist->remove($rule->id);

        $this->flashMessage('message', Language::_('AdminTools.!success.rule_removed', true));
        $this->redirect($this->base_uri . 'tools/blacklist/');
    }

    /**
     * Shows all the service changes
     */
    public function serviceChanges()
    {
        $this->redirect($this->base_uri . 'tools/provisioning/changes/');
    }

    /**
     * Cancels the pending service changes
     */
    public function serviceChangesCancel()
    {
        $this->uses(['ServiceChanges']);

        // Ensure a valid service change was given
        $service_change_id = $this->get[0] ?? $this->post['id'] ?? null;
        if (empty($service_change_id) || !($service_change = $this->ServiceChanges->get($service_change_id))) {
            $this->redirect($this->base_uri . 'tools/provisioning/changes/');
        }

        // Ensure the service change is pending
        if (($service_change->status ?? '') !== 'pending') {
            $this->redirect($this->base_uri . 'tools/provisioning/changes/');
        }

        // Attempt to cancel the pending service changes
        $void_invoice = ($this->post['void_invoice'] ?? null) == 'true';
        $this->ServiceChanges->cancel($service_change->id, $void_invoice);

        if (($errors = $this->ServiceChanges->errors())) {
            $this->flashMessage('error', $errors);
        } else {
            $this->flashMessage('message', Language::_('AdminTools.!success.service_changes_canceled', true));
        }

        $this->redirect($this->base_uri . 'tools/provisioning/changes/');
    }

    /**
     * Retries the errored service changes
     */
    public function serviceChangesRetry()
    {
        $this->uses(['ServiceChanges']);

        // Ensure a valid service change was given
        $service_change_id = $this->get[0] ?? $this->post['id'] ?? null;
        if (empty($service_change_id) || !($service_change = $this->ServiceChanges->get($service_change_id))) {
            $this->redirect($this->base_uri . 'tools/provisioning/changes/');
        }

        // Ensure the service change is pending
        if (($service_change->status ?? '') !== 'error') {
            $this->redirect($this->base_uri . 'tools/provisioning/changes/');
        }

        // Attempt to retry the pending service changes
        $this->ServiceChanges->edit($service_change->id, ['status' => 'pending']);

        $this->flashMessage('message', Language::_('AdminTools.!success.service_changes_scheduled', true));
        $this->redirect($this->base_uri . 'tools/provisioning/changes/');
    }
}
