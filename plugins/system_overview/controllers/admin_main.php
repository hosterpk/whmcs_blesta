<?php

/**
 * System Overview main controller
 *
 * @package blesta
 * @subpackage plugins.systemoverview
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminMain extends SystemOverviewController
{
    /**
     * A list of time-frames and class names for recently active users.
     */
    private $activity_time_frames = [
        'user' => ['class' => 'user', 'seconds' => null],
        'latest' => ['class' => 'latest', 'seconds' => 1800],
        'recent' => ['class' => 'recent', 'seconds' => 14400],
        'old' => ['class' => 'old', 'seconds' => null]
    ];

    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->requireLogin();

        Language::loadLang('admin_main', null, PLUGINDIR . 'system_overview' . DS . 'language' . DS);
        $this->uses(['SystemOverview.SystemOverviewSettings']);
    }

    /**
     * Renders the system overview widget
     */
    public function index()
    {
        // Only available via AJAX
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri);
        }

        // Get overview data
        $data = $this->overview();
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }

        return $this->renderAjaxWidgetIfAsync(null);
    }

    /**
     * Renders the feed reader widget for the billing page
     */
    public function billing()
    {
        // Fetch content from the index action
        $this->action = 'index';
        $this->set('billing', 'true');
        return $this->index();
    }

    /**
     * System overview
     */
    private function overview()
    {
        $this->uses(['SystemOverview.SystemOverviewStatistics', 'SystemOverview.SystemOverviewUsers']);

        // Set staff ID
        $staff_id = $this->Session->read('blesta_staff_id');

        // Set dates
        $datetime = $this->Date->format('c');
        $dates = [
            'today_start' => $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($datetime, 'Y-m-d 00:00:00')),
            'today_end' => $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($datetime, 'Y-m-d 23:59:59'))
        ];

        // Get the statistics to show for this user
        $overview_settings = $this->SystemOverviewSettings->getSettings($staff_id, $this->company_id);

        // Set default settings for this staff member if none yet exist
        if (empty($overview_settings)) {
            $this->SystemOverviewSettings->addDefault($staff_id, $this->company_id);
            $overview_settings = $this->SystemOverviewSettings->getSettings($staff_id, $this->company_id);
        }

        // Set which statistics to show
        $active_statistics = [];
        foreach ($overview_settings as $setting) {
            if ($setting->value == 1) {
                $active_statistics[] = $setting->key;
            }
        }

        $statistics = [];
        foreach ($active_statistics as $statistic) {
            $url = null;
            $value = null;
            $icon = '';

            switch ($statistic) {
                case 'clients_active':
                    $value = $this->SystemOverviewStatistics->getClientCount($this->company_id);
                    $url = $this->base_uri . 'clients/';
                    $icon = 'fa-users';
                    break;
                case 'services_active':
                    $value = $this->SystemOverviewStatistics->getServiceCount($this->company_id);
                    $url = $this->base_uri . 'billing/services/';
                    $icon = 'fa-cogs';
                    break;
                case 'services_scheduled_cancellation':
                    $value = $this->SystemOverviewStatistics->getServiceCount(
                        $this->company_id,
                        'scheduled_cancellation'
                    );
                    $url = $this->base_uri . 'billing/services/scheduled_cancellation/';
                    $icon = 'fa-calendar-times';
                    break;
                case 'active_users_today':
                    $value = $this->SystemOverviewStatistics->getActiveUsersCount(
                        $this->company_id,
                        $dates['today_start'],
                        $dates['today_end']
                    );
                    $icon = 'fa-clock';
                    break;
                case 'recurring_invoices':
                    $value = $this->SystemOverviewStatistics->getRecurringInvoiceCount();
                    $url = $this->base_uri . 'billing/invoices/recurring/';
                    $icon = 'fa-file-alt';
                    break;
                case 'pending_orders':
                    if (!isset($this->PluginManager)) {
                        $this->uses(['PluginManager']);
                    }

                    if (!$this->PluginManager->isInstalled('order', $this->company_id)) {
                        continue 2;
                    }

                    $this->uses(['Order.OrderOrders']);
                    $value = $this->OrderOrders->getListCount('pending');
                    $url = $this->base_uri . 'billing/';
                    $icon = 'fa-cart-arrow-down';
                    break;
                case 'open_tickets':
                    if (!isset($this->PluginManager)) {
                        $this->uses(['PluginManager']);
                    }

                    if (!$this->PluginManager->isInstalled('support_manager', $this->company_id)) {
                        continue 2;
                    }

                    $this->uses(['SupportManager.SupportManagerTickets']);
                    $value = $this->SupportManagerTickets->getListCount('open');
                    $url = $this->base_uri . 'plugin/support_manager/admin_tickets/';
                    $icon = 'fa-ticket-alt';
                    break;
                default:
                    // This setting is not a valid statistic
                    continue 2;
            }

            // Map FontAwesome icons to Bootstrap Icons
            $icon_map = [
                'fa-users' => 'bi-people',
                'fa-cogs' => 'bi-box-seam',
                'fa-calendar-times' => 'bi-calendar-x',
                'fa-clock' => 'bi-clock-history',
                'fa-file-alt' => 'bi-file-earmark-text',
                'fa-cart-arrow-down' => 'bi-cart',
                'fa-ticket-alt' => 'bi-ticket-detailed'
            ];
            $bootstrap_icon = $icon_map[$icon] ?? 'bi-circle';

            $statistics[] = [
                'class' => $statistic,
                'name' => Language::_('AdminMain.overview.statistic.' . $statistic, true),
                'value' => $value,
                'url' => $url,
                'icon' => $bootstrap_icon
            ];
        }

        // Get the current tab
        $tabs = $this->getTabs($overview_settings);
        $current_tab = null;
        foreach ($tabs as $tab) {
            if ($tab['current']) {
                $current_tab = $tab['tab'];
                break;
            }
        }

        // Get all graphs data for all tabs (not just current tab)
        $all_graphs = ['graphs' => [], 'settings' => []];
        $tab_keys = ['all', 'clients', 'services'];
        foreach ($tab_keys as $tab_key) {
            $tab_graphs = $this->getGraphs($overview_settings, $tab_key);
            if (!empty($tab_graphs['graphs'])) {
                $all_graphs['graphs'] = array_merge($all_graphs['graphs'], $tab_graphs['graphs']);
            }
            if (!empty($tab_graphs['settings'])) {
                $all_graphs['settings'] = array_merge($all_graphs['settings'], $tab_graphs['settings']);
            }
        }

        return [
            'statistics' => $statistics,
            'recent_users' => $this->getRecentUsers(),
            'tabs' => $tabs,
            'graphs' => $all_graphs,
        ];
    }

    /**
     * AJAX Fetch graph data for a specific time period
     */
    public function graphData()
    {
        if (!$this->isAjax()) {
            exit();
        }

        // Get period from request (24h, 7d, 30d)
        $period = isset($this->get[0]) ? strtolower($this->get[0]) : '7d';

        // Convert period to days
        $days = 7;
        switch ($period) {
            case '24h':
                $days = 1;
                break;
            case '7d':
                $days = 7;
                break;
            case '30d':
                $days = 30;
                break;
        }

        // Get settings
        $settings = $this->SystemOverviewSettings->getSettings(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );

        // Get graphs with the specified date range
        $graphs = $this->getGraphsForPeriod($settings, $days);

        $this->outputAsJson(['graphs' => $graphs]);
        return false;
    }

    /**
     * Gets graph data for a specific number of days
     *
     * @param array $settings The plugin settings
     * @param int $days Number of days to fetch data for
     * @return array Graph data
     */
    private function getGraphsForPeriod(array $settings, $days)
    {
        $this->uses(['SystemOverview.SystemOverviewStatistics']);

        $graphs = [];

        foreach ($settings as $setting) {
            if ($setting->value != 1) {
                continue;
            }

            switch ($setting->key) {
                case 'graph_clients':
                case 'graph_services':
                    $graph = $this->getGraphForDays($setting->key, $days);

                    $data = [];
                    foreach ($graph as $key => $value) {
                        $data[] = [
                            'name' => Language::_('AdminMain.graph_line_name.' . $key, true),
                            'points' => $graph[$key]
                        ];
                    }

                    $graphs[$setting->key] = [
                        'name' => Language::_('AdminMain.graph_name.' . $setting->key, true),
                        'data' => $data
                    ];
                    break;
            }
        }

        return $graphs;
    }

    /**
     * Retrieves graph data for a specific number of days
     *
     * @param string $key The graph setting key
     * @param int $days The number of days to retrieve data for
     * @return array An array of line data representing the graph
     */
    private function getGraphForDays($key, $days)
    {
        $this->uses(['SystemOverview.SystemOverviewStatistics']);

        $lines = [];
        $datetime = $this->SystemOverviewStatistics->dateToUtc(date('c'));

        for ($i = 0; $i < max(0, (int) $days); $i++) {
            $date = strtotime($datetime . ' -' . $i . ' days');
            $day_start = $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($date, 'Y-m-d 00:00:00'));
            $day_end = $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($date, 'Y-m-d 23:59:59'));
            $day_start_time = $date * 1000;

            switch ($key) {
                case 'graph_services':
                    $lines['active'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices($this->company_id, $day_start, $day_end)
                    ];
                    $lines['canceled'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices(
                            $this->company_id,
                            $day_start,
                            $day_end,
                            'canceled'
                        )
                    ];
                    $lines['suspended'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices(
                            $this->company_id,
                            $day_start,
                            $day_end,
                            'suspended'
                        )
                    ];
                    break;
                case 'graph_clients':
                    $lines['new'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getClients($this->company_id, $day_start, $day_end)
                    ];
                    break;
            }
        }

        foreach ($lines as $type => $line) {
            $lines[$type] = array_reverse($line);
        }

        return $lines;
    }

    /**
     * Settings
     */
    public function settings()
    {
        // Only available via AJAX
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri);
        }

        $this->uses(['PluginManager']);

        // Get all overview settings
        $settings = [];
        $overview_settings = $this->SystemOverviewSettings->getSettings(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );

        foreach ($overview_settings as $setting) {
            $settings[$setting->key] = $setting->value;
        }

        $plugins = [];
        // Check whether Support Manager plugin is installed
        $plugins['support_manager'] = $this->PluginManager->isInstalled('support_manager', $this->company_id);
        // Check whether Order plugin is installed
        $plugins['order'] = $this->PluginManager->isInstalled('order', $this->company_id);

        $this->set('plugins', $plugins);
        $this->set('vars', $settings);
        $this->set('date_ranges', $this->getDateRanges());

        return $this->renderAjaxWidgetIfAsync(false);
    }

    /**
     * Update settings
     */
    public function update()
    {
        // Set default value for each unset checkbox
        $checkboxes = ['clients_active', 'active_users_today', 'pending_orders', 'open_tickets',
            'services_active', 'services_scheduled_cancellation', 'graph_clients', 'graph_services',
            'show_one_tab', 'show_legend', 'recurring_invoices'];
        foreach ($checkboxes as $checkbox) {
            if (!isset($this->post[$checkbox])) {
                $this->post[$checkbox] = 0;
            }
        }

        // Set each setting into indexed array for adding
        $settings = [];
        foreach ($this->post as $key => $value) {
            $settings[] = ['key' => $key, 'value' => $value];
        }

        // Add the settings
        $this->SystemOverviewSettings->add($this->Session->read('blesta_staff_id'), $this->company_id, $settings);

        if (($errors = $this->SystemOverviewSettings->errors())) {
            // Error
            $this->flashMessage('error', $errors);
        } else {
            // Success
            $this->flashMessage('message', Language::_('AdminMain.!success.options_updated', true));
        }

        $this->redirect($this->base_uri);
    }

    /**
     * Get graph date ranges
     */
    private function getDateRanges()
    {
        // Set graph date ranges
        $ranges = [
            'ytd' => Language::_('AdminMain.date_range.ytd', true),
            'month' => Language::_('AdminMain.date_range.month', true),
            1 => Language::_('AdminMain.date_range.day', true)
        ];

        for ($i = 2; $i <= 365; $i++) {
            $ranges[$i] = Language::_('AdminMain.date_range.days', true, $i);
        }

        return $ranges;
    }

    /**
     * Retrieves a formatted list of users that were recently active
     *
     * @return array A sorted list of stdClass objects representing each user
     */
    private function getRecentUsers()
    {
        if (!isset($this->SettingsCollection)) {
            $this->components(['SettingsCollection']);
        }

        // Set whether GeoIp is enabled
        $system_settings = $this->SettingsCollection->fetchSystemSettings();
        $use_geo_ip = (isset($system_settings['geoip_enabled']) && $system_settings['geoip_enabled'] == 'true');
        if ($use_geo_ip) {
            // Load GeoIP database
            $this->components(['Net']);
            if (!isset($this->NetGeoIp)) {
                $this->NetGeoIp = $this->Net->create('NetGeoIp');
            }
        }

        $recent_users = $this->SystemOverviewUsers->getRecentUsers($this->company_id);

        // Set class names to represent colors to separate time sections
        $current_user_id = $this->Session->read('blesta_id');
        $current_timestamp = $this->Date->toTime($this->SystemOverviewUsers->dateToUtc(date('c')));
        foreach ($recent_users as &$user) {
            // Set GeoIP info
            $user->geo_ip = [];
            if ($use_geo_ip) {
                try {
                    $user->geo_ip = ['location' => $this->NetGeoIp->getLocation($user->ip_address)];
                } catch (\Throwable $e) {
                    // Nothing to do
                }
            }

            // Set last activity time language (in minutes)
            $user_activity_timestamp = $this->Date->toTime($user->date_updated);
            $last_activity = ($current_timestamp - $user_activity_timestamp) / 60;
            if ($last_activity < 1) {
                $user->last_activity = Language::_('AdminMain.overview.tooltip_last_activity_now', true);
            } elseif ($last_activity == 1) {
                $user->last_activity = Language::_('AdminMain.overview.tooltip_last_activity_minute', true);
            } else {
                $user->last_activity = Language::_(
                    'AdminMain.overview.tooltip_last_activity_minutes',
                    true,
                    ceil($last_activity)
                );
            }

            // Set a class for the user
            $user->class = null;
            if ($user->user_id == $current_user_id) {
                $user->class = $this->activity_time_frames['user']['class'];
                continue;
            }

            // Set a class for this user in terms of last activity
            if ($current_timestamp < ($user_activity_timestamp + $this->activity_time_frames['latest']['seconds'])) {
                $user->class = $this->activity_time_frames['latest']['class'];
            } elseif (
                $current_timestamp
                < ($user_activity_timestamp + $this->activity_time_frames['recent']['seconds'])
            ) {
                $user->class = $this->activity_time_frames['recent']['class'];
            } else {
                $user->class = $this->activity_time_frames['old']['class'];
            }
        }

        return $recent_users;
    }

    /**
     * Retrieves a list of tabs to be shown on the overview
     * @see AdminMain::overview()
     *
     * @param array $settings A list of system overview settings (optional)
     * @param string $current_tab The currently selected tab (optional, default "")
     * @return array A list of tabs
     */
    private function getTabs(array $settings = [], $current_tab = '')
    {
        // Get settings if not given
        if (empty($settings)) {
            $settings = $this->SystemOverviewSettings->getSettings(
                $this->Session->read('blesta_staff_id'),
                $this->company_id
            );
        }

        // Set the tabs to show
        $tabs = [];
        $base_url = $this->base_uri . 'widget/system_overview/admin_main/tab/';
        foreach ($settings as $setting) {
            if ($setting->value == 1) {
                switch ($setting->key) {
                    case 'graph_clients':
                        $tabs[] = [
                            'name' => Language::_('AdminMain.overview.tab_clients', true),
                            'tab' => 'clients',
                            'url' => $base_url . 'clients/',
                            'current' => ($current_tab == 'clients')
                        ];
                        break;
                    case 'graph_services':
                        $tabs[] = [
                            'name' => Language::_('AdminMain.overview.tab_services', true),
                            'tab' => 'services',
                            'url' => $base_url . 'services/',
                            'current' => ($current_tab == 'services')
                        ];
                        break;
                    case 'show_one_tab':
                        $tabs = [
                            [
                                'name' => Language::_('AdminMain.overview.tab_all', true),
                                'tab' => 'all',
                                'url' => $base_url . 'all/',
                                'current' => ($current_tab == 'all')
                            ]
                        ];
                        break 2;
                }
            }
        }

        // Set current tab if none given
        if (empty($current_tab) && !empty($tabs)) {
            $tabs[0]['current'] = true;
        }

        return $tabs;
    }


    /**
     * Sets up the data for each graph
     *
     * @param array $settings The plugin settings (optional)
     * @param string $current_tab The current tab to get graphs for
     * @return array A list of graph data and settings
     */
    private function getGraphs(array $settings = [], $current_tab = null)
    {
        // Get settings if not given
        if (empty($settings)) {
            $settings = $this->SystemOverviewSettings->getSettings(
                $this->Session->read('blesta_staff_id'),
                $this->company_id
            );
        }

        // Get date range of graphs
        $graph_settings = [];
        foreach ($settings as $setting) {
            if ($setting->key == 'date_range' || $setting->key == 'show_legend') {
                $graph_settings[$setting->key] = $setting->value;
            }
        }

        // Get each graph in use
        $graphs = ['graphs' => [], 'settings' => $graph_settings];

        // Set tabs and their graph content keys
        $tabs = [
            'all' => [],
            'clients' => ['graph_clients'],
            'services' => ['graph_services'],
        ];
        foreach ($tabs as $tab) {
            $tabs['all'] = array_merge($tabs['all'], $tab);
        }

        if (isset($tabs[$current_tab])) {
            foreach ($settings as $setting) {
                switch ($setting->key) {
                    case 'graph_clients':
                    case 'graph_services':
                        // Check this graph belongs in this tab or skip it
                        if (!in_array($setting->key, $tabs[$current_tab])) {
                            break;
                        }

                        if ($setting->value == 1) {
                            // Get the graph data over the set interval
                            $graph = $this->getGraph(
                                $setting->key,
                                ($graph_settings['date_range'] ?? 0)
                            );

                            // Set each graph line name
                            $data = [];
                            foreach ($graph as $key => $value) {
                                $data[] = [
                                    'name' => Language::_('AdminMain.graph_line_name.' . $key, true),
                                    'points' => json_encode($graph[$key])
                                ];
                            }

                            $graphs['graphs'][$setting->key] = [
                                'name' => Language::_('AdminMain.graph_name.' . $setting->key, true),
                                'data' => $data
                            ];
                        }
                        break;
                }
            }
        }

        return $graphs;
    }

    /**
     * Retrieves graph data over a given interval
     *
     * @param string $key The graph setting key
     * @param int $days The time interval in days to retrieve data from
     * @return array An array of line data representing the graph
     */
    private function getGraph($key, $days)
    {
        $this->uses(['SystemOverview.SystemOverviewStatistics']);

        // Set days value for Year-to-Day
        if ($days == 'ytd') {
            $time = time() - strtotime('first day of January ' . date('Y'));
        }

        // Set days value for current month
        if ($days == 'month') {
            $time = time() - strtotime('first day of this month');
        }

        if (!is_numeric($days) && isset($time)) {
            $time = $time + (60 * 60 * 24); // We add +1 day due to a bug in nvd3
            $days = round($time / (60 * 60 * 24));
        } else {
            $days = $days + 1; // We add +1 day due to a bug in nvd3
        }

        // Set values for each graph line
        $lines = [];
        $datetime = $this->SystemOverviewStatistics->dateToUtc(date('c'));

        for ($i = 0; $i < max(0, (int) $days); $i++) {
            // Set start/end dates
            $date = strtotime($datetime . ' -' . $i . ' days');
            $day_start = $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($date, 'Y-m-d 00:00:00'));
            $day_end = $this->SystemOverviewStatistics->dateToUtc($this->Date->cast($date, 'Y-m-d 23:59:59'));

            // Set start time to milliseconds UTC for use in JS Date()
            $day_start_time = $date * 1000;

            // Set each graph data point
            switch ($key) {
                case 'graph_services':
                    $lines['active'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices($this->company_id, $day_start, $day_end)
                    ];
                    $lines['canceled'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices(
                            $this->company_id,
                            $day_start,
                            $day_end,
                            'canceled'
                        )
                    ];
                    $lines['suspended'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getServices(
                            $this->company_id,
                            $day_start,
                            $day_end,
                            'suspended'
                        )
                    ];
                    break;
                case 'graph_clients':
                    $lines['new'][] = [
                        $day_start_time,
                        $this->SystemOverviewStatistics->getClients($this->company_id, $day_start, $day_end)
                    ];
                    break;
            }
        }

        // Order line values by oldest date first so the graph can display them correctly
        foreach ($lines as $type => $line) {
            $lines[$type] = array_reverse($line);
        }

        return $lines;
    }
}
