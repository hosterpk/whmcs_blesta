<?php

/**
 * Client portal main controller
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ClientMain extends ClientController
{
    /**
     * @var string The custom field prefix used in form names to keep them unique and easily referenced
     */
    private $custom_field_prefix = 'c_f';

    /**
     * @var array A list of client editable settings
     */
    private $editable_settings = [];

    /**
     * Main pre-action
     */
    public function preAction()
    {
        parent::preAction();

        // Allow states to be fetched and set the language without login
        if (in_array(strtolower($this->action), ['getstates', 'setlanguage'])) {
            return;
        }

        // Load models, language
        $this->uses(['Clients', 'Contacts']);

        $this->contact = $this->Contacts->getByUserId($this->Session->read('blesta_id'), $this->client->id);
        if (!$this->contact) {
            $this->contact = $this->Contacts->get($this->client->contact_id);
        }

        // Include client settings
        if ($this->contact) {
            $this->contact->settings = $this->client->settings;
        }

        // Set left client info section
        $this->setMyInfo();

        // Set editable client settings
        $client_settings = $this->client->settings;
        $this->editable_settings = [
            'autodebit' => true,
            'tax_id' => (isset($client_settings['show_client_tax_id'])
                ? ($client_settings['show_client_tax_id'] == 'true')
                : false
            ),
            'inv_address_to' => true,
            'default_currency' => (isset($client_settings['client_set_currency'])
                ? ($client_settings['client_set_currency'] == 'true')
                : false
            ),
            'inv_method' => (isset($client_settings['client_set_invoice'])
                ? ($client_settings['client_set_invoice'] == 'true')
                : false
            ),
            'language' => (isset($client_settings['client_set_lang'])
                ? ($client_settings['client_set_lang'] == 'true')
                : false
            ),
            'receive_email_marketing' => (isset($client_settings['show_receive_email_marketing'])
                ? ($client_settings['show_receive_email_marketing'] == 'true')
                : true
            )
        ];
    }

    /**
     * Client Profile
     */
    public function index()
    {
        if ($this->loadHosterpkDashboardLanguage()) {
            $greeting_name = !empty($this->client->first_name)
                ? $this->client->first_name
                : (isset($this->contact->first_name) ? $this->contact->first_name : '');
            $dashboard_greeting = $greeting_name !== ''
                ? Language::_('Hosterpk.dashboard.greeting', true, $greeting_name)
                : Language::_('Hosterpk.dashboard.greeting_noname', true);

            $this->structure->set('title', Language::_('Hosterpk.dashboard.heading', true));
            $this->structure->set('dashboard_greeting', $dashboard_greeting);
            $this->set('title', Language::_('Hosterpk.dashboard.heading', true));
            $this->set('dashboard_greeting', $dashboard_greeting);
        }
        $action_items = [];
        $action_item_rank = 0;

        if ($this->hasPermission('client_invoices')) {
            // Get all client currencies that there may be amounts due in
            $currencies = $this->Invoices->invoicedCurrencies($this->client->id);

            // Set a message for all currencies that have an amount due
            $amount_due_message = null;
            $max_due = 0;
            $past_due = 0;
            $max_due_currency = null;
            $currencies_owed = 0;
            foreach ($currencies as $currency) {
                $total_due = $this->Invoices->amountDue($this->client->id, $currency->currency);

                if ($total_due > $max_due) {
                    $max_due_currency = $currency->currency;
                    $max_due = $total_due;
                    $amount_due_message = Language::_(
                        'ClientMain.!info.invoice_due_text',
                        true,
                        $this->CurrencyFormat->format($total_due, $currency->currency)
                    );

                    // Get any past due amounts
                    $past_due = $this->Invoices->amountDue($this->client->id, $max_due_currency, 'past_due');
                    if ($past_due > 0) {
                        $amount_due_message = Language::_(
                            'ClientMain.!info.invoice_due_past_due_text',
                            true,
                            $this->CurrencyFormat->format($total_due, $currency->currency),
                            $this->CurrencyFormat->format($past_due, $currency->currency)
                        );
                    }

                    $currencies_owed++;
                }
            }

            if ($amount_due_message) {
                $invoice_action_url = $this->base_uri . 'pay/index/' . $max_due_currency . '/';
                $invoice_action_label = Language::_('Hosterpk.action.pay_now', true);
                $invoice_severity = 'warning';
                $invoice_status_label = 'Hosterpk.status.due';
                $invoice_icon = 'receipt';
                $invoice_title = Language::_('Hosterpk.dashboard.invoice_due_title', true);
                $invoice_detail = Language::_(
                    'Hosterpk.dashboard.invoice_due_detail',
                    true,
                    $this->CurrencyFormat->format($max_due, $max_due_currency, ['suffix' => false, 'code' => false])
                );

                // Set a past due button
                $past_due_btn = [];
                $message_type = 'info';
                if ($past_due > 0) {
                    $message_type = 'notice';
                    $invoice_action_url = $this->base_uri . 'pay/index/' . $max_due_currency . '/pastdue/';
                    $invoice_action_label = Language::_('Hosterpk.action.pay_past_due', true);
                    $invoice_severity = 'danger';
                    $invoice_status_label = 'Hosterpk.status.overdue';
                    $invoice_icon = 'exclamation-triangle-fill';
                    $invoice_title = Language::_('Hosterpk.dashboard.invoice_overdue_title', true);
                    $invoice_detail = Language::_(
                        'Hosterpk.dashboard.invoice_overdue_detail',
                        true,
                        $this->CurrencyFormat->format($max_due, $max_due_currency, ['suffix' => false, 'code' => false]),
                        $this->CurrencyFormat->format($past_due, $max_due_currency, ['suffix' => false, 'code' => false])
                    );

                    $past_due_btn = [
                        'class' => 'btn',
                        'url' => $this->Html->safe($this->base_uri . 'pay/index/' . $max_due_currency . '/pastdue/'),
                        'label' => Language::_('ClientMain.!info.invoice_past_due_button', true)
                    ];
                }

                $message = ['amount_due' => [$amount_due_message]];
                if ($currencies_owed > 1) {
                    $message['amount_due'][] = Language::_('ClientMain.!info.invoice_due_other_currencies', true);
                }

                $params = [
                    $message_type . '_title' => Language::_(
                        'ClientMain.!info.invoice_due_title',
                        true,
                        $this->client->first_name
                    ),
                    $message_type . '_buttons' => []
                ];

                // Show the payment button when there is no past due button for the full amount
                if ($past_due < $max_due) {
                    $params[$message_type . '_buttons'][] = [
                        'class' => 'btn',
                        'url' => $this->Html->safe($this->base_uri . 'pay/index/' . $max_due_currency . '/'),
                        'label' => Language::_('ClientMain.!info.invoice_due_button', true),
                        'icon_class' => 'fa-plus-circle'
                    ];
                }

                // Add the past due button if any amounts are past due
                if (!empty($past_due_btn)) {
                    $params[$message_type . '_buttons'][] = $past_due_btn;
                }

                // Story 4.4: dashboard summary is rendered as action cards, so the
                // duplicate stock setMessage banner is suppressed. Genuine
                // post-redirect/session flashes are rendered by the view flash region.

                $action_items[] = [
                    'band' => 'action_needed',
                    'severity' => $invoice_severity,
                    'status_label' => $invoice_status_label,
                    'icon' => $invoice_icon,
                    'title' => $invoice_title,
                    'detail' => $invoice_detail,
                    'action' => [
                        'label' => $invoice_action_label,
                        'url' => $invoice_action_url
                    ],
                    'key' => 'invoice-due',
                    '_rank' => $action_item_rank++
                ];
            }

            // Add a note regarding in-review messages
            $in_review = $this->buildInReviewList();
            if (!empty($in_review['messages'])) {
                // Story 4.4: dashboard summary is rendered as action cards, so the
                // duplicate stock setMessage banner is suppressed.

                foreach ($in_review['items'] as $index => $service) {
                    $action_item = [
                        'band' => 'in_progress',
                        'severity' => 'info',
                        'status_label' => 'Hosterpk.status.in_review',
                        'icon' => 'info-circle-fill',
                        'title' => Language::_('Hosterpk.dashboard.service_in_review_title', true),
                        'detail' => $service['detail'],
                        'action' => [
                            'label' => Language::_('Hosterpk.action.manage_service', true),
                            'url' => $this->base_uri . 'services/'
                        ],
                        'key' => $service['key'],
                        '_rank' => $action_item_rank++
                    ];

                    if ($index === count($in_review['items']) - 1 && $in_review['overflow'] > 0) {
                        $action_item['overflow'] = $in_review['overflow'];
                        $action_item['overflow_url'] = $this->base_uri . 'services/';
                    }

                    $action_items[] = $action_item;
                }
            }
        }

        // Set a message if the email hasn't been verified
        Loader::loadModels($this, ['ClientGroups', 'EmailVerifications', 'Contacts']);
        Loader::loadHelpers($this, ['Form']);

        $settings = $this->ClientGroups->getSettings($this->client->client_group_id);
        $settings = $this->Form->collapseObjectArray($settings, 'value', 'key');

        if (
            isset($settings['email_verification'])
            && $settings['email_verification'] == 'true'
        ) {
            $contacts = $this->Contacts->getAll($this->client->id);
            $email_verification = $this->EmailVerifications->getByContactId($this->contact->id);

            if (
                empty($email_verification)
                || (isset($email_verification->verified) && $email_verification->verified == 1)
            ) {
                foreach ($contacts as $contact) {
                    if (empty($contact->user_id)) {
                        $contact_email_verification = $this->EmailVerifications->getByContactId($contact->id);

                        if (
                            isset($contact_email_verification->verified) && $contact_email_verification->verified == 0
                        ) {
                            $email_verification = $contact_email_verification;
                            break;
                        }
                    }
                }
            }

            if (isset($email_verification->verified) && $email_verification->verified == 0) {
                if (isset($message_type) && $message_type == 'info' && !empty($message)) {
                    $message['amount_due'][] = Language::_(
                        'ClientMain.!info.email_pending_verification',
                        true,
                        $email_verification->email
                    );
                } else {
                    $message = Language::_(
                        'ClientMain.!info.email_pending_verification',
                        true,
                        $email_verification->email
                    );
                }

                $time = time();
                $hash = $this->Clients->systemHash('c=' . $email_verification->contact_id . '|t=' . $time);
                $verify_url = $this->base_uri . 'verify/send/?sid=' . rawurlencode(
                    $this->Clients->systemEncrypt(
                        'c=' . $email_verification->contact_id . '|t=' . $time . '|h=' . substr($hash, -16)
                    )
                );
                // Story 4.4: dashboard summary is rendered as action cards, so the
                // duplicate stock setMessage banner is suppressed.

                $action_items[] = [
                    'band' => 'action_needed',
                    'severity' => 'warning',
                    'status_label' => 'Hosterpk.status.verify_pending',
                    'icon' => 'person-circle',
                    'title' => Language::_('Hosterpk.dashboard.email_verify_title', true),
                    'detail' => Language::_(
                        'Hosterpk.dashboard.email_verify_detail',
                        true,
                        $email_verification->email
                    ),
                    'action' => [
                        'label' => Language::_('Hosterpk.action.verify_email', true),
                        'url' => $verify_url
                    ],
                    'key' => 'email-verify',
                    '_rank' => $action_item_rank++
                ];
            }
        }

        $this->sortDashboardActionItems($action_items);
        foreach ($action_items as &$action_item) {
            unset($action_item['_rank']);
        }
        unset($action_item);

        $this->set('action_items', $action_items);
        $this->set('client', $this->client);

        // Story 4.2: at-a-glance stats, recent activity, and quick actions.
        // All data is read-only, client+company-scoped, and pulled from core
        // Blesta models only. The domains tile is omitted when the client has
        // zero domains in any status, so the grid reflows to 3-up.
        $dashboard_stats = [];
        $recent_activity = [];
        $quick_actions = [];
        $domain_any_count = 0;
        $active_services_count = null;
        $latest_invoice = null;
        $next_domain_renewal = null;
        $dashboard_hero_state = 'healthy';

        if ($this->loadHosterpkDashboardLanguage()) {
            Loader::loadModels($this, ['Services', 'Invoices', 'Transactions']);

            $client_id = $this->client->id;
            $currency_code = isset($this->client->settings['default_currency'])
                ? $this->client->settings['default_currency']
                : null;
            $can_view_invoices = $this->hasPermission('client_invoices');

            // Active services (non-domain)
            if ($this->hasPermission('client_services')) {
                $active_services_count = $this->Services->getStatusCount(
                    $client_id,
                    'active',
                    true,
                    ['type' => 'services']
                );
                $dashboard_stats[] = [
                    'key' => 'services',
                    'icon' => 'hdd-stack',
                    'severity' => 'info',
                    'value' => $active_services_count,
                    'label' => ((int)$active_services_count === 1)
                        ? 'Hosterpk.dashboard.stat_services_singular'
                        : 'Hosterpk.dashboard.stat_services',
                    'empty_copy' => 'Hosterpk.dashboard.stat_services_empty',
                    'url' => $this->base_uri . 'services/'
                ];
            }

            // Domains tile is shown only when the client has at least one domain
            // in any status. Count domains via the core Services type filter.
            if ($this->hasPermission('client_services')) {
                $domain_any_count = $this->Services->getStatusCount(
                    $client_id,
                    'all',
                    true,
                    ['type' => 'domains']
                );

                if ($domain_any_count > 0) {
                    $active_domains = $this->Services->getStatusCount(
                        $client_id,
                        'active',
                        true,
                        ['type' => 'domains']
                    );
                    $dashboard_stats[] = [
                        'key' => 'domains',
                        'icon' => 'globe2',
                        'severity' => 'primary',
                        'value' => $active_domains,
                        'label' => ((int)$active_domains === 1)
                            ? 'Hosterpk.dashboard.stat_domains_singular'
                            : 'Hosterpk.dashboard.stat_domains',
                        'empty_copy' => 'Hosterpk.dashboard.stat_domains_empty',
                        'url' => $this->base_uri . 'plugin/domains/client_main/'
                    ];
                }
            }

            // Unpaid invoices
            if ($can_view_invoices) {
                $unpaid_invoices = $this->Invoices->getStatusCount($client_id, 'open');
                $dashboard_stats[] = [
                    'key' => 'invoices_unpaid',
                    'icon' => 'receipt',
                    'severity' => 'warning',
                    'value' => $unpaid_invoices,
                    'label' => ((int)$unpaid_invoices === 1)
                        ? 'Hosterpk.dashboard.stat_invoices_unpaid_singular'
                        : 'Hosterpk.dashboard.stat_invoices_unpaid',
                    'empty_copy' => 'Hosterpk.dashboard.stat_invoices_unpaid_empty',
                    'url' => $this->base_uri . 'invoices/'
                ];
            }

            // Account credit (mirrors getCurrencyAmounts() credit handling)
            if ($this->hasPermission('_credits') && $currency_code !== null) {
                $payment_credit_enabled = isset($this->client->settings['payment_credit_enabled'])
                    ? $this->client->settings['payment_credit_enabled']
                    : '1';
                $show_credit = true;

                if ($payment_credit_enabled == '0') {
                    $show_credit = false;
                    $used_currencies = array_unique(
                        array_merge(
                            $this->Clients->usedCurrencies($client_id),
                            [$currency_code]
                        )
                    );
                    foreach ($used_currencies as $check_currency) {
                        if ($this->Transactions->getTotalCredit($client_id, $check_currency) > 0) {
                            $show_credit = true;
                            break;
                        }
                    }
                }

                if ($show_credit) {
                    $credit_amount = $this->Transactions->getTotalCredit($client_id, $currency_code);
                    $credit_stat = [
                        'key' => 'credit',
                        'icon' => 'credit-card',
                        'severity' => 'success',
                        'value' => $this->CurrencyFormat->format(
                            $credit_amount,
                            $currency_code,
                            ['suffix' => false, 'code' => false]
                        ),
                        'label' => 'Hosterpk.dashboard.stat_credit',
                        'empty_copy' => 'Hosterpk.dashboard.stat_credit_empty',
                        'is_empty' => $this->isDashboardAmountZeroOrLess($credit_amount)
                    ];
                    if ($can_view_invoices) {
                        $credit_stat['url'] = $this->base_uri . 'invoices/';
                    }
                    $dashboard_stats[] = $credit_stat;
                }
            }

            // Recent activity: transactions first, then single most-recent invoice fallback
            $has_transactions_permission = $this->hasPermission('client_transactions');
            if ($has_transactions_permission) {
                $transactions = $this->Transactions->getList(
                    $client_id,
                    'approved',
                    1,
                    ['date_added' => 'DESC']
                );

                if (!empty($transactions)) {
                    $transactions = array_slice($transactions, 0, 5);
                    foreach ($transactions as $transaction) {
                        $amount = $this->CurrencyFormat->format(
                            (isset($transaction->amount) ? $transaction->amount : 0),
                            (isset($transaction->currency) ? $transaction->currency : $currency_code),
                            ['suffix' => false, 'code' => false]
                        );
                        $date = $this->formatDashboardActivityDate($transaction->date_added ?? null);
                        $date_suffix = $this->formatDashboardActivityDateSuffix($date);

                        $recent_activity[] = [
                            'text' => Language::_(
                                'Hosterpk.dashboard.activity_payment',
                                true,
                                $amount,
                                $date_suffix
                            ),
                            'date' => $date
                        ];
                    }
                }
            }

            if (empty($recent_activity) && $can_view_invoices) {
                $latest_invoices = $this->Invoices->getList(
                    $client_id,
                    'all',
                    1,
                    ['date_billed' => 'DESC']
                );

                if (!empty($latest_invoices)) {
                    $invoice = $latest_invoices[0];
                    $amount = $this->CurrencyFormat->format(
                        (isset($invoice->total) ? $invoice->total : 0),
                        (isset($invoice->currency) ? $invoice->currency : $currency_code),
                        ['suffix' => false, 'code' => false]
                    );
                    $date = $this->formatDashboardActivityDate($invoice->date_billed ?? null);
                    $date_suffix = $this->formatDashboardActivityDateSuffix($date);
                    $invoice_number = $this->getDashboardInvoiceNumber($invoice);
                    $activity_key = $invoice_number === ''
                        ? 'Hosterpk.dashboard.activity_invoice_no_number'
                        : 'Hosterpk.dashboard.activity_invoice';
                    $activity_args = $invoice_number === ''
                        ? [$amount, $date_suffix]
                        : [$invoice_number, $amount, $date_suffix];

                    $recent_activity[] = [
                        'text' => Language::_($activity_key, true, ...$activity_args),
                        'date' => $date
                    ];
                }
            }

            // Quick actions: static, permission-aware navigation links
            if ($can_view_invoices) {
                $quick_actions[] = [
                    'label' => 'Hosterpk.action.pay_invoice',
                    'url' => $this->base_uri . 'invoices/',
                    'icon' => 'receipt'
                ];
            }

            if ($this->hasPermission('client_services')) {
                $quick_actions[] = [
                    'label' => 'Hosterpk.action.manage_service',
                    'url' => $this->base_uri . 'services/',
                    'icon' => 'hdd-stack'
                ];
            }

            if ($domain_any_count > 0 && $this->hasPermission('client_services')) {
                $quick_actions[] = [
                    'label' => 'Hosterpk.action.manage_domains',
                    'url' => $this->base_uri . 'plugin/domains/client_main/',
                    'icon' => 'globe2'
                ];
            }

            if ($this->hasPermission('support_manager.*')) {
                $quick_actions[] = [
                    'label' => 'Hosterpk.action.open_ticket',
                    'url' => $this->base_uri . 'plugin/support_manager/client_tickets/',
                    'icon' => 'life-preserver'
                ];
            }

            if ($this->hasPermission('client_contacts')) {
                $quick_actions[] = [
                    'label' => 'Hosterpk.action.update_account',
                    'url' => $this->base_uri . 'main/edit/',
                    'icon' => 'person-circle'
                ];
            }

            // Story 4.4: latest-invoice summary card (read-only, core model)
            $latest_invoice = null;
            if ($can_view_invoices) {
                $latest_invoices = $this->Invoices->getList(
                    $client_id,
                    'all',
                    1,
                    ['date_billed' => 'DESC']
                );
                if (!empty($latest_invoices[0])) {
                    $invoice = $latest_invoices[0];
                    $invoice_currency = isset($invoice->currency) ? $invoice->currency : $currency_code;
                    $invoice_due = isset($invoice->due) ? $invoice->due : 0;
                    $is_past_due = (
                        !empty($invoice->date_due)
                        && empty($invoice->date_closed)
                        && strtotime($invoice->date_due) < strtotime(date('Y-m-d'))
                        && $this->isDashboardAmountZeroOrLess($invoice_due) === false
                    );
                    $invoice_pay_url = $this->base_uri . 'pay/index/'
                        . rawurlencode($invoice_currency) . '/'
                        . ($is_past_due ? 'pastdue/' : '');

                    $latest_invoice = [
                        'id' => isset($invoice->id) ? (int)$invoice->id : 0,
                        'id_code' => isset($invoice->id_code) ? (string)$invoice->id_code : '',
                        'id_value' => isset($invoice->id_value) ? (string)$invoice->id_value : '',
                        'total' => isset($invoice->total) ? (string)$invoice->total : '0.0000',
                        'currency' => $invoice_currency,
                        'status' => isset($invoice->status) ? (string)$invoice->status : '',
                        'due' => (string)$invoice_due,
                        'date_due' => isset($invoice->date_due) ? (string)$invoice->date_due : null,
                        'date_due_formatted' => isset($invoice->date_due)
                            ? $this->formatDashboardActivityDate($invoice->date_due)
                            : null,
                        'date_closed' => isset($invoice->date_closed) ? (string)$invoice->date_closed : null,
                        'pay_url' => $invoice_pay_url,
                        'view_url' => $this->base_uri . 'invoices/view/'
                            . (isset($invoice->id) ? (int)$invoice->id : 0) . '/',
                        'is_past_due' => $is_past_due
                    ];
                }
            }

            // Story 4.4: next domain-renewal summary card (read-only, core model)
            $next_domain_renewal = null;
            if ($domain_any_count > 0 && $this->hasPermission('client_services')) {
                $domain_list = $this->Services->getList(
                    $client_id,
                    'active',
                    1,
                    ['date_renews' => 'ASC'],
                    true,
                    ['type' => 'domains']
                );
                if (!empty($domain_list[0])) {
                    $domain = $domain_list[0];
                    $renewal_date = !empty($domain->date_renews) ? (string)$domain->date_renews : null;
                    $days_until_renewal = null;
                    if ($renewal_date !== null) {
                        $days_until_renewal = floor((strtotime($renewal_date) - time()) / 86400);
                    }

                    $next_domain_renewal = [
                        'id' => isset($domain->id) ? (int)$domain->id : 0,
                        'name' => isset($domain->name) ? (string)$domain->name : '',
                        'date_renews' => $renewal_date,
                        'date_renews_formatted' => $renewal_date !== null
                            ? $this->formatDashboardActivityDate($renewal_date)
                            : null,
                        'manage_url' => $this->base_uri . 'services/manage/'
                            . (isset($domain->id) ? (int)$domain->id : 0) . '/',
                        'days_until_renewal' => $days_until_renewal
                    ];
                }
            }

            // Story 4.4: state-aware hero (attention / healthy / empty)
            $dashboard_hero_state = 'healthy';
            if (!empty($action_items)) {
                $dashboard_hero_state = 'attention';
            } elseif ($active_services_count !== null && (int)$active_services_count === 0) {
                $dashboard_hero_state = 'empty';
            }
        }

        $this->set('dashboard_stats', $dashboard_stats);
        $this->set('recent_activity', $recent_activity);
        $this->set('quick_actions', $quick_actions);
        $this->set('latest_invoice', $latest_invoice);
        $this->set('next_domain_renewal', $next_domain_renewal);
        $this->set('dashboard_hero_state', $dashboard_hero_state);
    }

    /**
     * Loads HosterPK dashboard copy when the client theme is active.
     */
    private function loadHosterpkDashboardLanguage()
    {
        if (!defined('ROOTWEBDIR') || !defined('WEBDIR')) {
            return false;
        }

        $active_view_dir = null;
        if (isset($this->structure) && isset($this->structure->view_dir)) {
            $active_view_dir = $this->structure->view_dir;
        } elseif (isset($this->view_dir)) {
            $active_view_dir = $this->view_dir;
        }
        if ($active_view_dir === null) {
            return false;
        }

        $active_view_dir = rtrim(str_replace('\\', '/', $active_view_dir), '/') . '/';
        $hosterpk_view_dir = rtrim(str_replace('\\', '/', WEBDIR . 'app/views/client/hosterpk/'), '/') . '/';
        if ($active_view_dir !== $hosterpk_view_dir) {
            return false;
        }

        $view_root = realpath(ROOTWEBDIR . 'app' . DS . 'views' . DS . 'client' . DS . 'hosterpk');
        $language_path = realpath(ROOTWEBDIR . 'app' . DS . 'views' . DS . 'client' . DS . 'hosterpk' . DS . 'language');
        if (
            $view_root === false
            || $language_path === false
            || strpos($language_path . DS, $view_root . DS) !== 0
        ) {
            return false;
        }

        Language::loadLang('hosterpk', null, $language_path . DS);
        return true;
    }

    /**
     * Formats a dashboard activity date with Blesta's timezone-aware date helper.
     *
     * @param string|null $date The source date
     * @return string The formatted date, or an empty string when unavailable
     */
    private function formatDashboardActivityDate($date)
    {
        if (!is_scalar($date)) {
            return '';
        }

        $date = trim((string)$date);
        if ($date === '' || strtotime($date) === false) {
            return '';
        }

        return isset($this->Date) ? $this->Date->cast($date, 'j M') : date('j M', strtotime($date));
    }

    /**
     * Returns the optional translated date suffix for an activity line.
     *
     * @param string $date The pre-formatted activity date
     * @return string The suffix including separator, or empty string
     */
    private function formatDashboardActivityDateSuffix($date)
    {
        return $date === '' ? '' : Language::_('Hosterpk.dashboard.activity_date_suffix', true, $date);
    }

    /**
     * Gets the best available display invoice number.
     *
     * @param stdClass $invoice The invoice row
     * @return string The invoice number, or empty string
     */
    private function getDashboardInvoiceNumber($invoice)
    {
        foreach (['id_code', 'id_value', 'id'] as $field) {
            if (isset($invoice->{$field}) && trim((string)$invoice->{$field}) !== '') {
                return (string)$invoice->{$field};
            }
        }

        return '';
    }

    /**
     * Determines whether a decimal amount is zero or negative without floats.
     *
     * @param mixed $amount The raw amount value from the model
     * @return bool True when the amount is parseable as zero or less
     */
    private function isDashboardAmountZeroOrLess($amount)
    {
        if ($amount === null || $amount === '') {
            return true;
        }

        $amount = preg_replace('/[,\s]/', '', (string)$amount);
        if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/', $amount)) {
            return false;
        }
        if (strpos($amount, '-') === 0) {
            return true;
        }

        $amount = ltrim($amount, '+');
        return trim(str_replace('.', '', $amount), '0') === '';
    }

    /**
     * Sorts dashboard action items by the Story 4.1 contract.
     *
     * @param array $items The action items to sort in place
     */
    private function sortDashboardActionItems(&$items)
    {
        $band_order = [
            'action_needed' => 0,
            'in_progress' => 1,
            'settled' => 2
        ];
        $severity_order = [
            'danger' => 0,
            'warning' => 1,
            'info' => 2,
            'success' => 3
        ];

        usort($items, function ($left, $right) use ($band_order, $severity_order) {
            $left_band = $band_order[$left['band'] ?? 'settled'] ?? 99;
            $right_band = $band_order[$right['band'] ?? 'settled'] ?? 99;
            if ($left_band !== $right_band) {
                return $left_band - $right_band;
            }

            $left_severity = $severity_order[$left['severity'] ?? 'success'] ?? 99;
            $right_severity = $severity_order[$right['severity'] ?? 'success'] ?? 99;
            if ($left_severity !== $right_severity) {
                return $left_severity - $right_severity;
            }

            return ($left['_rank'] ?? 0) - ($right['_rank'] ?? 0);
        });
    }

    /**
     * Builds the in-review service list used by messages and dashboard cards.
     *
     * @return array A messages/items/overflow tuple
     */
    private function buildInReviewList()
    {
        if (!isset($this->Services)) {
            $this->uses(['Services']);
        }

        // Fetch all in-review services
        $services = $this->Services->getAllByClient(
            $this->client->id,
            'in_review',
            ['date_added' => 'DESC'],
            false
        );

        // Construct a message to notify the client of their in-review services
        $service_list = [];
        $action_services = [];
        $num_services = count($services);
        $max_services = 5;
        for ($i = 0; $i < min($max_services, $num_services); $i++) {
            $service_name = Language::_(
                'ClientMain.!info.service_name',
                true,
                $services[$i]->package->name,
                $services[$i]->name
            );
            $service_list[] = $service_name;
            $action_services[] = [
                'detail' => $service_name,
                'key' => 'service-in-review-' . (isset($services[$i]->id) ? $services[$i]->id : ($i + 1))
            ];
        }
        unset($services);

        $overflow = 0;
        // Add a note about additional services
        if ($num_services > $max_services) {
            $overflow = ($num_services - $max_services);
            $service_list[] = Language::_(
                'ClientMain.!info.additional_service' . ($overflow > 1 ? 's' : ''),
                true,
                $overflow
            );
        }

        return [
            'messages' => $service_list,
            'items' => $action_services,
            'overflow' => $overflow
        ];
    }

    /**
     * Edit the client
     */
    public function edit()
    {
        $this->uses(['Currencies', 'Languages', 'Users', 'Companies']);
        $this->components(['SettingsCollection', 'Upload']);

        $this->ArrayHelper = $this->DataStructure->create('Array');
        $is_primary = $this->client->contact_id == $this->contact->id;

        // Set user as the current user, or the client's primary user if logged in as staff
        $current_user_id = $this->Session->read('blesta_id');
        if ($this->isStaffAsClient()) {
            $current_user_id = $this->client->user_id;
        }

        $user = $this->Users->get($current_user_id);
        $company = Configure::get('Blesta.company');

        // Load the Base2n class from vendors
        $base32 = new Base2n(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', false, true, true);

        $vars = [];

        // Update the client
        if (!empty($this->post)) {
            // Set the client settings to update
            $new_client_settings = [];

            if ($this->editable_settings['receive_email_marketing']) {
                $new_client_settings['receive_email_marketing'] = empty($this->post['receive_email_marketing'])
                    ? 'false'
                    : $this->post['receive_email_marketing'];
            }

            foreach ($this->editable_settings as $setting => $enabled) {
                if (isset($this->post[$setting]) && $enabled) {
                    $new_client_settings[$setting] = $this->post[$setting];
                }
            }

            // Handle file uploads
            if (isset($this->files) && !empty($this->files)) {
                $temp = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
                $upload_path = $temp['value'] . $this->company_id . DS . 'avatars' . DS;

                $this->Upload->setFiles($this->files);

                // Create the upload path if it doesn't already exists
                $this->Upload->createUploadPath($upload_path);
                $this->Upload->setUploadPath($upload_path);

                // Set the allowed mime types
                Configure::load('mime');
                $mime_types = Configure::get('Blesta.allowed_mime_types');
                $file_extensions = Configure::get('Blesta.allowed_file_extensions');
                $this->Upload->setAllowedMimeTypes($mime_types['image']);
                $this->Upload->setAllowedFileExtensions($file_extensions['image']);

                if (!($errors = $this->Upload->errors())) {
                    $this->Upload->writeFile('avatar', true, null, function ($file_name) {
                        return uniqid() . $this->Upload->md5($file_name);
                    });
                    $data = $this->Upload->getUploadData();

                    if (isset($data['avatar'])) {
                        $this->post['avatar'] = $data['avatar']['full_path'];
                    }

                    $upload_errors = $this->Upload->errors();
                }
            }

            // Handle avatar removal
            if (isset($this->post['remove_avatar']) && $this->post['remove_avatar'] == '1') {
                // Set avatar to null to remove from database
                $this->post['avatar'] = null;
            }

            // Begin a new transaction
            $this->Clients->begin();

            // Update the email/password, or two-factor auth if given
            $email_user_type = (isset($this->client->settings['username_type'])
                && $this->client->settings['username_type'] == 'email');

            if (empty($this->post['new_password'])) {
                unset($this->post['new_password'], $this->post['confirm_password']);
            }

            // Set user fields to update
            $user_vars = array_intersect_key(
                $this->post,
                array_flip(
                    [
                        'recovery_email',
                        'current_password',
                        'new_password',
                        'confirm_password',
                        'two_factor_mode',
                        'two_factor_key',
                        'otp',
                        'avatar'
                    ]
                )
            );

            if (!array_key_exists('two_factor_mode', (array)$user_vars)) {
                $user_vars['two_factor_mode'] = 'none';
                $user_vars['two_factor_key'] = null;
            }

            $validate_password = (
                ($user_vars['two_factor_mode'] != 'none' && $user->two_factor_mode != $user_vars['two_factor_mode'])
                || !empty($user_vars['new_password'])
            );

            if ($is_primary && $email_user_type && isset($this->post['email'])) {
                $user_vars['username'] = $this->post['email'];
            }

            $this->Users->edit($user->id, $user_vars, $validate_password);
            $user_errors = $this->Users->errors();

            $custom_field_errors = false;
            $client_settings_errors = false;
            if ($is_primary) {
                // Update the client custom fields
                $custom_field_errors = $this->addCustomFields($this->post);

                // Update client settings
                $this->Clients->setClientSettings($this->client->id, $new_client_settings);
                $client_settings_errors = $this->Clients->errors();
            }

            // Update the phone numbers
            $vars = $this->post;
            // Format the phone numbers
            $vars['numbers'] = $this->ArrayHelper->keyToNumeric($this->post['numbers'] ?? []);

            // Update the contact
            unset($vars['user_id']);
            if (($vars['email'] ?? '') == $this->contact->email) {
                $vars['verify'] = false;
            }
            $this->Contacts->edit($this->contact->id, $vars);
            $contact_errors = $this->Contacts->errors();

            // Combine any errors
            $errors = array_merge(
                (!empty($upload_errors) ? $upload_errors : []),
                (!empty($contact_errors) ? $contact_errors : []),
                (!empty($client_settings_errors) ? $client_settings_errors : []),
                (!empty($custom_field_errors) ? $custom_field_errors : []),
                (!empty($user_errors) ? $user_errors : [])
            );

            if (!empty($errors)) {
                // Error, rollback
                $this->Clients->rollBack();

                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
                $vars->username = $user->username;
            } else {
                // Success, commit
                $this->Clients->commit();
                if (isset($new_client_settings['tax_id'])) {
                    $this->Clients->setSettings(
                        $this->client->id,
                        ['tax_id' => $new_client_settings['tax_id']],
                        ['tax_id', 'tax_exempt']
                    );
                }

                // Handle avatar removal
                if (isset($this->post['remove_avatar']) && $this->post['remove_avatar'] == '1') {
                    // Get current avatar path and delete the file
                    if (!empty($user->avatar) && file_exists($user->avatar)) {
                        @unlink($user->avatar);
                    }
                }

                $this->flashMessage('message', Language::_('ClientMain.!success.client_updated', true));
                $this->redirect($this->base_uri);
            }
        }

        // Set the initial client data
        if (empty($vars)) {
            $vars = (object) array_merge((array) $user, (array) $this->contact);

            // Set contact phone numbers formatted for HTML
            $vars->numbers = $this->ArrayHelper->numericToKey($vars->numbers);

            // Set client custom field values
            if ($is_primary) {
                $field_values = $this->Clients->getCustomFieldValues($this->client->id);
                foreach ($field_values as $field) {
                    $vars->{$this->custom_field_prefix . $field->id} = $field->value;
                }
            }
        }

        // Set whether to show additional settings section
        $show_additional_settings = false;
        if ($is_primary
            && ($this->editable_settings['language']
                || $this->editable_settings['receive_email_marketing']
                || 0 < count(
                    $custom_fields = $this->Clients->getCustomFields(
                        $this->client->company_id,
                        $this->client->client_group_id,
                        ['show_client' => 1]
                    )
                )
            )
        ) {
            $show_additional_settings = true;
        }

        // Get all client contacts for which to make invoices addressable to (primary and billing contacts)
        $contacts = array_merge(
            $this->Contacts->getAll($this->client->id, 'primary'),
            $this->Contacts->getAll($this->client->id, 'billing')
        );

        $this->set('username', $user->username);

        if ($is_primary) {
            $this->set('contacts', $this->Form->collapseObjectArray($contacts, ['first_name', 'last_name'], 'id', ' '));
            $this->set(
                'currencies',
                $this->Form->collapseObjectArray($this->Currencies->getAll($this->client->company_id), 'code', 'code')
            );
            $this->set(
                'languages',
                $this->Form->collapseObjectArray($this->Languages->getAll($this->client->company_id), 'name', 'code')
            );
        }

        // Generate random two-factor key
        if (!isset($vars->two_factor_key) || $vars->two_factor_key == '') {
            $vars->two_factor_key = $this->Users->systemHash(mt_rand() . md5(mt_rand()), null, 'sha1');
        }

        $vars->two_factor_mode = (property_exists($vars, 'two_factor_mode')
            ? $vars->two_factor_mode
            : $user->two_factor_mode
        );
        $vars->two_factor_key_base32 = $base32->encode(pack('H*', $vars->two_factor_key));

        // Aggregate model errors (if any) so the owned HosterPK view + partials can render
        // per-field inline errors (.hpk-invalid/.hpk-field-error). The Form helper has no
        // error()/setErrors() in this Blesta version, so per-field inline is sourced from
        // this array, keyed by Blesta field name. Empty on the initial (non-submit) render.
        $errors = (isset($errors) && is_array($errors)) ? $errors : [];

        $this->set('enabled_fields', $this->editable_settings);
        $this->set('show_additional_settings', $show_additional_settings);
        $this->set('vars', $vars);
        $this->set('errors', $errors);
        // Pass the RAW company name; the owned view encodes the otpauth issuer exactly once
        // (Story 9.2, AC C). Encoding here too produced %2520 double-encoding for a
        // space-containing name.
        $this->set('two_factor_issuer', $company->name);
        $this->set('is_primary', $is_primary);

        // Story 9.2 (OQ-A): thread the ACTIVE password policy to the owned view so the
        // weak-password hint states exactly what the server enforces (additive, read-only —
        // no validation/save/auth/CSRF change). Mirror the same company settings (and the
        // same 6/any//.*/i fallbacks) Users::getRules() reads (users.php:933-942); guard
        // against getSetting() returning false. Companies is already loaded in edit().
        $hpk_pw_len = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_length');
        $hpk_pw_len_val = (is_object($hpk_pw_len) && isset($hpk_pw_len->value) ? $hpk_pw_len->value : '6');
        // Guard against a non-numeric DB value so the view hint can never disagree with
        // the server-enforced length (and so the client-side check has a sane threshold).
        $this->set('password_min_length', (is_numeric($hpk_pw_len_val) ? $hpk_pw_len_val : '6'));
        $hpk_pw_fmt = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_format');
        $this->set('password_format', (is_object($hpk_pw_fmt) && isset($hpk_pw_fmt->value) ? $hpk_pw_fmt->value : 'any'));
        $hpk_pw_rule = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_rule');
        $this->set('password_rule', (is_object($hpk_pw_rule) && isset($hpk_pw_rule->value) ? $hpk_pw_rule->value : '/.*/i'));

        // Set partials to view
        $this->setContactView($vars, $this->contact, $errors);
        $this->setPhoneView($vars, $errors);
        $this->setCustomFieldView($vars, $errors);
    }

    /**
     * Edit client's invoice method
     */
    public function invoiceMethod()
    {
        $this->requirePermission('_invoice_delivery');

        // Get available delivery methods
        $delivery_methods = $this->Invoices->getDeliveryMethods($this->client->id);

        $vars = [];

        if (!empty($this->post)) {
            // Only update the invoice method setting from this page
            $vars = ['inv_method' => (isset($this->post['inv_method']) ? $this->post['inv_method'] : '')];
            $this->Clients->setClientSettings($this->client->id, $vars);

            if (($errors = $this->Clients->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                $this->setMessage('error', $errors);
            } else {
                // Success
                $new_invoice_method = isset($delivery_methods[$vars['inv_method']])
                    ? $delivery_methods[$vars['inv_method']]
                    : '';
                $this->flashMessage(
                    'message',
                    Language::_('ClientMain.!success.invoice_method_updated', true, $new_invoice_method)
                );
                $this->redirect($this->base_uri);
            }
        }

        // Set the invoice method, or reset when setting is disabled
        if (empty($vars) || !$this->editable_settings['inv_method']) {
            $vars = (object) ['inv_method' => $this->client->settings['inv_method']];
        }

        $this->set('vars', $vars);
        $this->set('enabled', $this->editable_settings['inv_method']);
        $this->set('delivery_methods', $delivery_methods);
    }

    /**
     * Attempts to add custom fields to a client
     *
     * @param array $vars The post data, containing custom fields
     * @return mixed An array of errors, or false if none exist
     * @see Clients::add(), Clients::edit()
     */
    private function addCustomFields(array $vars = [])
    {
        $client_id = $this->client->id;

        // Get the client's current custom fields
        $client_custom_fields = $this->Clients->getCustomFieldValues($client_id);

        // Create a list of custom field IDs to update
        $custom_fields = $this->Clients->getCustomFields($this->client->company_id, $this->client->client_group_id);
        $custom_field_ids = [];
        foreach ($custom_fields as $field) {
            if ($field->read_only) {
                continue;
            }
            $custom_field_ids[] = $field->id;
        }
        unset($field);

        // Build a list of given custom fields to update
        $custom_fields_set = [];
        foreach ($vars as $field => $value) {
            // Get the custom field ID from the name
            $field_id = preg_replace('/' . $this->custom_field_prefix . '/', '', $field, 1);

            // Set the custom field
            if ($field_id != $field && in_array($field_id, $custom_field_ids)) {
                $custom_fields_set[$field_id] = $value;
            }
        }
        unset($field, $value);

        // Set every custom field available, even if it's not given, for validation
        $deletable_fields = [];
        foreach ($custom_field_ids as $field) {
            $custom_field = $this->Clients->getCustomField($field, $this->client->company_id);
            if (!isset($custom_fields_set[$custom_field->id])) {
                // Only set custom field to validate if it is not read only
                if ($custom_field->read_only != '1' && $custom_field->show_client == '1') {
                    // Set a temp value for validation purposes
                    $custom_fields_set[$custom_field->id] = '';
                    // Set this field to be deleted
                    $deletable_fields[] = $custom_field->id;
                }
            }
        }
        unset($field_id);

        // Attempt to add/update each custom field
        $temp_field_errors = [];
        foreach ($custom_fields_set as $field_id => $value) {
            $this->Clients->setCustomField($field_id, $client_id, $value);
            $temp_field_errors[$field_id] = $this->Clients->errors();
        }
        unset($field_id, $value);

        // Delete the fields that were not given
        foreach ($deletable_fields as $field_id) {
            $this->Clients->deleteCustomFieldValue($field_id, $client_id);
        }

        // Combine multiple custom field errors together
        $custom_field_errors = [];
        $i = 0;
        foreach ($temp_field_errors as $field_id => $field_errors) {
            // Skip any "error" that is not an array already
            if (!is_array($field_errors)) {
                $i++;
                continue;
            }

            // Change the keys of each custom field error so we can display all of them at once
            $error_keys = array_keys($field_errors);
            $temp_error = [];

            foreach ($error_keys as $key) {
                $temp_error[$key . $i] = $field_errors[$key];
                // Emit the HosterPK view key only once per custom field so multiple
                // distinct errors are not overwritten under the same control key.
                if (!isset($temp_error[$this->custom_field_prefix . $field_id])) {
                    $temp_error[$this->custom_field_prefix . $field_id] = $field_errors[$key];
                }
            }

            $custom_field_errors = array_merge($custom_field_errors, $temp_error);
            $i++;
        }

        return (empty($custom_field_errors) ? false : $custom_field_errors);
    }

    /**
     * Sets the contact partial view
     * @see ClientMain::edit()
     *
     * @param stdClass $vars The input vars object for use in the view
     * @param stdClass $contact An object representing the current contact being updated
     */
    private function setContactView(stdClass $vars, $contact = null, array $errors = [])
    {
        $this->uses(['Countries', 'States', 'ClientGroups', 'Users', 'Clients', 'Companies', 'PluginManager']);

        $contacts = [];
        $contact_fields_groups = ['required_contact_fields', 'shown_contact_fields', 'read_only_contact_fields'];

        // Set partial for contact info
        $contact_info = [
            'js_contacts' => json_encode($contacts),
            'contacts' => $this->Form->collapseObjectArray($contacts, ['first_name', 'last_name'], 'id', ' '),
            'countries' => $this->Form->collapseObjectArray(
                $this->Countries->getList(),
                ['name', 'alt_name'],
                'alpha2',
                ' - '
            ),
            'states' => $this->Form->collapseObjectArray($this->States->getList($vars->country), 'name', 'code'),
            'vars' => $vars,
            'errors' => $errors,
            'edit' => true,
            'show_email' => true,
            'show_avatar' => true
        ];

        if (is_object($contact)) {
            $contact_info['contact'] = $contact;

            if (!empty($contact->user_id)) {
                $contact_info['user'] = $this->Users->get($contact->user_id);
            } else if (!empty($contact->client_id)) {
                $client = $this->Clients->get($contact->client_id);
                $contact_info['user'] = $this->Users->get($client->user_id);
            }
        }

        // Get contact field groups
        foreach ($contact_fields_groups as $group_name) {
            ${$group_name} = [];
            if ($this->client) {
                $setting = $this->ClientGroups->getSetting($this->client->client_group_id, $group_name);

                if ($setting) {
                    $unserialized = safe_unserialize(
                        base64_decode($setting->value)
                    );
                    if (is_array($unserialized)) {
                        ${$group_name} = $unserialized;
                    }
                }
            }

            $contact_info[$group_name] = ${$group_name};
        }

        // Load language for partial
        Language::loadLang('client_contacts');
        $this->set('contact_info', $this->partial('client_contacts_contact_info', $contact_info));
    }

    /**
     * Sets the contact phone number partial view
     * @see ClientMain::edit()
     *
     * @param stdClass $vars The input vars object for use in the view
     */
    private function setPhoneView(stdClass $vars, array $errors = [])
    {
        $contact_fields_groups = ['required_contact_fields', 'shown_contact_fields', 'read_only_contact_fields'];

        // Set partial for phone numbers
        $partial_vars = [
            'numbers' => (isset($vars->numbers) ? $vars->numbers : []),
            'number_types' => $this->Contacts->getNumberTypes(),
            'number_locations' => $this->Contacts->getNumberLocations(),
            'errors' => $errors
        ];

        // Get contact field groups
        foreach ($contact_fields_groups as $group_name) {
            ${$group_name} = [];
            if ($this->client) {
                $setting = $this->ClientGroups->getSetting($this->client->client_group_id, $group_name);

                if ($setting) {
                    $unserialized = safe_unserialize(
                        base64_decode($setting->value)
                    );
                    if (is_array($unserialized)) {
                        ${$group_name} = $unserialized;
                    }
                }
            }

            $partial_vars[$group_name] = ${$group_name};
        }

        if (!in_array('phone', $shown_contact_fields) && !in_array('phone', $required_contact_fields)) {
            unset($partial_vars['number_types']['phone']);
        }
        if (!in_array('fax', $shown_contact_fields) && !in_array('fax', $required_contact_fields)) {
            unset($partial_vars['number_types']['fax']);
        }

        $this->set('phone_numbers', $this->partial('client_contacts_phone_numbers', $partial_vars));
    }

    /**
     * Sets the custom fields partial view
     * @see ClientMain::edit()
     *
     * @param stdClass $vars An stdClass object representing the client vars
     */
    private function setCustomFieldView(stdClass $vars, array $errors = [])
    {
        // Set partial for custom fields
        $custom_fields = $this->Clients->getCustomFields($this->client->company_id, $this->client->client_group_id);
        $custom_field_values = null;

        // Swap key/value pairs for "Select" option custom fields (to display)
        foreach ($custom_fields as &$field) {
            // Swap select values
            if ($field->type == 'select' && is_array($field->values)) {
                $field->values = array_flip($field->values);
            }

            // Re-set any missing custom field values (e.g. in the case of errors) for read-only vars
            if ($field->read_only == '1' && !isset($vars->{$this->custom_field_prefix . $field->id})) {
                // Fetch the custom field values for this client
                if ($custom_field_values === null) {
                    $custom_field_values = $this->Clients->getCustomFieldValues($this->client->id);
                }

                // Set this custom field value to the client's value
                foreach ($custom_field_values as $custom_field) {
                    if ($custom_field->id == $field->id) {
                        $vars->{$this->custom_field_prefix . $field->id} = $custom_field->value;
                        break;
                    }
                }
            }
        }

        $partial_vars = [
            'vars' => $vars,
            'custom_fields' => $custom_fields,
            'custom_field_prefix' => $this->custom_field_prefix,
            'errors' => $errors
        ];
        $this->set('custom_fields', $this->partial('client_main_custom_fields', $partial_vars));
    }

    /**
     * Sets a partial view that contains all left-column client info
     */
    private function setMyInfo()
    {
        $this->uses(['Accounts', 'Invoices', 'ManagedAccounts']);

        $client = $this->client;
        $contact = $this->contact;
        // Get client contact numbers
        $contact->numbers = $this->Contacts->getNumbers($contact->id);

        // Get available invoice delivery methods and set language for the one set for this client
        $invoice_delivery_methods = $this->Invoices->getDeliveryMethods($client->id, $client->client_group_id, true);
        $invoice_method_language = (isset($invoice_delivery_methods[$client->settings['inv_method']])
            ? $invoice_delivery_methods[$client->settings['inv_method']]
            : ''
        );

        // Check whether payment types used for payment accounts are enabled
        $show_autodebit = true;
        if ((!isset($this->client->settings['payments_allowed_ach'])
                || $this->client->settings['payments_allowed_ach'] != 'true')
            && (!isset($this->client->settings['payments_allowed_cc'])
                || $this->client->settings['payments_allowed_cc'] != 'true')
        ) {
            // If the client has no payment accounts, don't show the autodebit section
            if (count($this->Accounts->getAllCcByClient($this->client->id)) === 0
                && count($this->Accounts->getAllCcByClient($this->client->id)) === 0
            ) {
                $show_autodebit = false;
            }
        }

        $myinfo_settings = [
            'invoice' => [
                'enabled' => ('true' == $client->settings['client_set_invoice']),
                'description' => Language::_('ClientMain.myinfo.setting_invoices', true, $invoice_method_language)
            ],
            'autodebit' => [
                'enabled' => $show_autodebit,
                'description' => $this->getAutodebitDescription()
            ]
        ];

        if (!$this->hasPermission('_invoice_delivery')) {
            unset($myinfo_settings['invoice']);
        }
        if (!$this->hasPermission('client_accounts')) {
            unset($myinfo_settings['autodebit']);
        }

        $number_types = $this->Contacts->getNumberTypes();
        $number_locations = $this->Contacts->getNumberLocations();

        // Get client contacts
        $contacts = array_merge(
            $this->Contacts->getAll($this->client->id, 'billing'),
            $this->Contacts->getAll($this->client->id, 'other')
        );

        // Check if the current session is a manager or a primary contact
        $is_manager = !empty($this->Session->read('blesta_contact_id'));

        // Get accounts managed by the client
        $managed_accounts = false;
        if ($this->hasPermission('_managed') && !$is_manager) {
            $managed_accounts = array_slice($this->ManagedAccounts->getAll($this->client->id), 0, 4);
        }

        $this->set(
            'myinfo',
            $this->partial(
                'client_main_myinfo',
                compact(
                    'client',
                    'contact',
                    'myinfo_settings',
                    'invoice_delivery_methods',
                    'number_types',
                    'number_locations',
                    'contacts',
                    'managed_accounts'
                )
            )
        );
    }

    /**
     * AJAX Searches managed accounts
     */
    public function searchManagedAccounts()
    {
        // Ensure a valid client was given
        if (!$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['ManagedAccounts']);

        $search = null;
        if (isset($this->get[0])) {
            $search = $this->get[0];
        }

        if (empty($search)) {
            return false;
        }

        // Search accounts
        $results = $this->ManagedAccounts->search($this->client->id, $search, 0);

        // Build the vars
        $vars = [
            'accounts' => $results
        ];

        // Set the partial for currency amounts
        $response = $this->partial('client_main_searchmanagedaccounts', $vars);

        // JSON encode the AJAX response
        $this->outputAsJson($response);
        return false;
    }

    /**
     * AJAX Fetches the currency amounts for the my info sidebar
     */
    public function getCurrencyAmounts()
    {
        // Ensure a valid client was given
        if (!$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->requirePermission('_credits');

        $this->uses(['Currencies', 'Transactions']);

        $currency_code = $this->client->settings['default_currency'];
        if (isset($this->get[0]) && ($currency = $this->Currencies->get($this->get[0], $this->company_id))) {
            $currency_code = $currency->code;
        }

        // Check if credit payments are disabled and if client has no credits in any currency
        $payment_credit_enabled = $this->client->settings['payment_credit_enabled'] ?? '1';
        if ($payment_credit_enabled == '0') {
            $has_credits = false;
            $used_currencies = array_unique(
                array_merge(
                    $this->Clients->usedCurrencies($this->client->id),
                    [$this->client->settings['default_currency']]
                )
            );
            foreach ($used_currencies as $check_currency) {
                if ($this->Transactions->getTotalCredit($this->client->id, $check_currency) > 0) {
                    $has_credits = true;
                    break;
                }
            }

            // Hide the credit card if disabled and no credits
            if (!$has_credits) {
                $this->outputAsJson('');
                return false;
            }
        }

        // Fetch the amounts
        $amounts = [
            'total_credit' => [
                'lang' => Language::_('ClientMain.getcurrencyamounts.text_total_credits', true),
                'amount' => $this->CurrencyFormat->format(
                    $this->Transactions->getTotalCredit($this->client->id, $currency_code),
                    $currency_code
                )
            ]
        ];

        // Build the vars
        $vars = [
            'payment_credit_enabled' => $payment_credit_enabled,
            'selected_currency' => $currency_code,
            'currencies' => array_unique(
                array_merge(
                    $this->Clients->usedCurrencies($this->client->id),
                    [$this->client->settings['default_currency']]
                )
            ),
            'amounts' => $amounts
        ];

        // Set the partial for currency amounts
        $response = $this->partial('client_main_getcurrencyamounts', $vars);

        // JSON encode the AJAX response
        $this->outputAsJson($response);
        return false;
    }

    /**
     * AJAX Fetch all states belonging to a given country (json encoded ajax request)
     */
    public function getStates()
    {
        $this->uses(['States']);
        // Prepend "all" option to state listing
        $states = [];
        if (isset($this->get[0])) {
            $states = (array) $this->Form->collapseObjectArray($this->States->getList($this->get[0]), 'name', 'code');
        }

        echo json_encode($states);
        return false;
    }

    /**
     * Retrieves the autodebit language description based on the payment account settings
     *
     * @return string The autodebit language description
     */
    private function getAutodebitDescription()
    {
        $client = $this->client;
        #
        # TODO: Clean this up... -- BEGIN
        #
        #
        // Set autodebit/invoice language based on settings
        $autodebit_description = Language::_('ClientMain.myinfo.setting_autodebit_disabled', true);
        if (('true' == $client->settings['autodebit'])
            && ($debit_account = $this->Clients->getDebitAccount($client->id))
        ) {
            $autodebit_days_before_due = $client->settings['autodebit_days_before_due'];
            $autodebit_description = Language::_('ClientMain.myinfo.setting_autodebit_enabled', true);
            $autodebit_account_description = '';

            // Set autodebit language based on account
            switch ($debit_account->type) {
                case 'cc':
                    if (($autodebit_account = $this->Accounts->getCc($debit_account->account_id))) {
                        $card_types = $this->Accounts->getCcTypes();
                        $card_type = (isset($card_types[$autodebit_account->type])
                            ? $card_types[$autodebit_account->type]
                            : ''
                        );

                        // Set the language based on how many days before due. Zero, one, or more
                        if ($autodebit_days_before_due == 0) {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_cc_zero_days',
                                true,
                                $card_type,
                                $autodebit_account->last4
                            );
                        } elseif ($autodebit_days_before_due == 1) {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_cc_one_day',
                                true,
                                $card_type,
                                $autodebit_account->last4
                            );
                        } else {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_cc_days',
                                true,
                                $card_type,
                                $autodebit_account->last4,
                                $autodebit_days_before_due
                            );
                        }
                    }
                    break;
                case 'ach':
                    if (($autodebit_account = $this->Accounts->getAch($debit_account->account_id))) {
                        $account_types = $this->Accounts->getAchTypes();
                        $account_type = (isset($account_types[$autodebit_account->type])
                            ? $account_types[$autodebit_account->type]
                            : ''
                        );

                        if ($autodebit_days_before_due == 0) {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_ach_zero_days',
                                true,
                                $account_type,
                                $autodebit_account->last4
                            );
                        } elseif ($autodebit_days_before_due == 1) {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_ach_one_day',
                                true,
                                $account_type,
                                $autodebit_account->last4
                            );
                        } else {
                            $autodebit_account_description = Language::_(
                                'ClientMain.myinfo.setting_autodebit_ach_days',
                                true,
                                $account_type,
                                $autodebit_account->last4,
                                $autodebit_days_before_due
                            );
                        }
                    }
                    break;
            }

            // Combine the autodebit descriptions
            $autodebit_description = $this->Html->concat(' ', $autodebit_description, $autodebit_account_description);
        }
        #
        # TODO: Clean this up... -- END
        #
        #
        return $autodebit_description;
    }

    /**
     * Sets the default language for this session
     */
    public function setLanguage()
    {
        $this->uses(['Languages']);

        $this->setClientLanguage($this->post['language_code']);

        $this->redirect(isset($this->post['redirect_uri']) ? $this->post['redirect_uri'] : $this->base_uri);
    }
}
