<?php
/**
 * Support Manager Admin Departments controller
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminDepartments extends SupportManagerController
{
    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();

        // Restore structure view location of the admin portal
        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
        $this->requireLogin();

        $this->uses(['SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerResponses']);

        $this->staff_id = $this->Session->read('blesta_staff_id');
    }

    /**
     * List departments
     */
    public function index()
    {
        $page = (isset($this->get[0]) ? (int)$this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'name');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'asc');

        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        $departments = $this->SupportManagerDepartments->getList($this->company_id, $page, [$sort => $order]);
        $total_results = $this->SupportManagerDepartments->getListCount($this->company_id);

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/support_manager/admin_departments/index/[p]/'
            ]
        );
        $this->setPagination($this->get, $settings);

        $this->set('departments', $departments);
        $this->set('priorities', $this->SupportManagerDepartments->getPriorities());
        $this->set('string', $this->DataStructure->create('string'));

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Add a department
     */
    public function add()
    {
        $this->uses(['SupportManager.SupportManagerTickets', 'Email']);

        // Create a department
        if (!empty($this->post)) {
            // Set empty checkboxes
            $checkboxes = [
                'clients_only', 'enable_related_services', 'require_captcha', 'override_from_email',
                'send_ticket_received', 'automatic_transition',
                'include_attachments'
            ];
            foreach ($checkboxes as $checkbox) {
                if (!isset($this->post[$checkbox])) {
                    $this->post[$checkbox] = '0';
                }
            }

            $checkboxes_array = [
                'reminder_ticket_status', 'reminder_ticket_priority'
            ];
            foreach ($checkboxes_array as $checkbox) {
                if (empty($this->post[$checkbox])) {
                    $this->post[$checkbox] = [];
                }
            }

            $field_checkboxes = [
                'client_add', 'encrypted', 'auto_delete'
            ];
            if (!empty($this->post['fields']['label'])) {
                foreach ($this->post['fields']['label'] as $i => $label) {
                    foreach ($field_checkboxes as $field_checkbox) {
                        if (!isset($this->post['fields'][$field_checkbox][$i])) {
                            $this->post['fields'][$field_checkbox][$i] = '0';
                        }
                    }
                }
            }

            // Set the close ticket interval, delete ticket interval, reminder ticket interval, and response ID to null if not set
            if (empty($this->post['close_ticket_interval'])) {
                $this->post['close_ticket_interval'] = null;
            }
            if (empty($this->post['delete_ticket_interval'])) {
                $this->post['delete_ticket_interval'] = null;
            }
            if (empty($this->post['feedback_ticket_interval'])) {
                $this->post['feedback_ticket_interval'] = null;
            }
            if (empty($this->post['reminder_ticket_interval'])) {
                $this->post['reminder_ticket_interval'] = null;
            }
            if (empty($this->post['response_id'])) {
                $this->post['response_id'] = null;
            }
            if (empty($this->post['attachment_types'])) {
                $this->post['attachment_types'] = null;
            }
            if (empty($this->post['max_attachment_size'])) {
                $this->post['max_attachment_size'] = null;
            }

            // Set the company ID
            $data = $this->post;
            $data['company_id'] = $this->company_id;

            $department = $this->SupportManagerDepartments->add($data);

            if (($errors = $this->SupportManagerDepartments->errors())) {
                // Error, reset vars
                $vars = (object)$this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Add this staff member to this department
                $this->addStaff($department->id);

                // Handle OAuth 2.0 redirection
                if (
                    !empty($this->post['oauth2_provider'])
                    && !empty($this->post['oauth2_client_id'])
                    && !empty($this->post['oauth2_client_secret'])
                ) {
                    // Fetch authorization url
                    try {
                        $transport = $this->Email->buildTransport('oauth2')
                            ->setProvider($this->post['oauth2_provider'])
                            ->setRedirectUri(
                                'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://'
                                . Configure::get('Blesta.company')->hostname . $this->base_uri
                                . 'plugin/support_manager/admin_departments/edit/' . $department->id . '/'
                            );
                    } catch (Throwable $e) {
                        $this->flashMessage('error', $e->getMessage());
                        $this->redirect(
                            $this->base_uri . 'plugin/support_manager/admin_departments/edit/' . $department->id . '/'
                        );

                        return;
                    }

                    // Process OAuth 2.0 redirect
                    $params = [
                        'client_id' => $this->post['oauth2_client_id'],
                        'redirect_uri' => $transport->getRedirectUri(),
                        'response_type' => 'code',
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                        'scope' => $transport->getScope()
                    ];
                    $this->redirect($transport->getAuthorizationUrl($params));
                } else {
                    // Success
                    $this->flashMessage(
                        'message',
                        Language::_('AdminDepartments.!success.department_created', true, $department->name),
                        null,
                        false
                    );
                    $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
                }
            }
        }

        // Set default fields
        if (!isset($vars)) {
            $vars = (object)['port' => 110, 'box_name' => 'INBOX', 'clients_only' => '1'];
        }

        // Set the selected auto response, if any
        if (!empty($vars->response_id)) {
            $response = $this->SupportManagerResponses->get($vars->response_id);
            if ($response && $response->company_id == $this->company_id) {
                $this->set('response', $response);
            } else {
                unset($vars->response_id);
            }
        }

        $this->set('vars', $vars);
        $this->set('priorities', $this->SupportManagerDepartments->getPriorities());
        $this->set('methods', $this->SupportManagerDepartments->getMethods());
        $this->set('providers', $this->SupportManagerDepartments->getProviders());
        $this->set('statuses', $this->SupportManagerDepartments->getStatuses());
        $this->set('security_types', $this->SupportManagerDepartments->getSecurityTypes());
        $this->set('message_types', $this->SupportManagerDepartments->getMessageTypes());
        $this->set('field_types', $this->SupportManagerDepartments->getFieldTypes());
        $this->set('visibility_options', $this->SupportManagerDepartments->getVisibilityOptions());
        $this->set(
            'close_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getTicketIntervals(30)
        );
        $this->set(
            'delete_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getTicketIntervals(90)
        );
        $this->set(
            'feedback_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getReminderIntervals()
        );
        $this->set(
            'reminder_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getReminderIntervals()
        );
        $this->set('ticket_statuses', $this->SupportManagerTickets->getStatuses());
        $this->set('piping_config', '/usr/bin/php ' . realpath(dirname(__FILE__) . DS . '..' . DS) . DS . 'pipe.php');
    }

    /**
     * Edit a department
     */
    public function edit()
    {
        if (!isset($this->get[0]) || !($department = $this->SupportManagerDepartments->get($this->get[0])) ||
            $this->company_id != $department->company_id) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
        }

        $this->uses(['SupportManager.SupportManagerTickets', 'Email']);

        // Process OAuth 2.0 authorization redirect
        if (!empty($this->get['code'])) {
            $data = [
                'oauth2_code' => $this->get['code']
            ];

            $transport = $this->Email->buildTransport('oauth2');
            $token = $transport->setProvider($department->oauth2_provider)
                ->setRedirectUri(
                    'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://'
                        . Configure::get('Blesta.company')->hostname . $this->base_uri
                        . 'plugin/support_manager/admin_departments/edit/' . $department->id . '/'
                )
                ->setAuthorizationCode($this->get['code'])
                ->setClientId($department->oauth2_client_id)
                ->setClientSecret($department->oauth2_client_secret)
                ->refreshAccessToken(true, false);

            $data['oauth2_access_token'] = $token->access_token ?? null;
            $data['oauth2_refresh_token'] = $token->refresh_token ?? null;

            $department = $this->SupportManagerDepartments->edit($department->id, $data);
        }

        // Update a department
        if (!empty($this->post)) {
            // Set empty checkboxes
            $checkboxes = [
                'clients_only', 'enable_related_services', 'require_captcha', 'override_from_email',
                'send_ticket_received', 'automatic_transition', 'include_attachments'
            ];
            foreach ($checkboxes as $checkbox) {
                if (!isset($this->post[$checkbox])) {
                    $this->post[$checkbox] = '0';
                }
            }

            $checkboxes_array = [
                'reminder_ticket_status', 'reminder_ticket_priority'
            ];
            foreach ($checkboxes_array as $checkbox) {
                if (empty($this->post[$checkbox])) {
                    $this->post[$checkbox] = [];
                }
            }

            $field_checkboxes = [
                'client_add', 'encrypted', 'auto_delete'
            ];
            if (!empty($this->post['fields']['label'])) {
                foreach ($this->post['fields']['label'] as $i => $label) {
                    foreach ($field_checkboxes as $field_checkbox) {
                        if (!isset($this->post['fields'][$field_checkbox][$i])) {
                            $this->post['fields'][$field_checkbox][$i] = '0';
                        }
                    }
                }
            }

            // Set the close ticket interval, delete ticket interval, and response ID to null if not set
            if (empty($this->post['close_ticket_interval'])) {
                $this->post['close_ticket_interval'] = null;
            }
            if (empty($this->post['delete_ticket_interval'])) {
                $this->post['delete_ticket_interval'] = null;
            }
            if (empty($this->post['feedback_ticket_interval'])) {
                $this->post['feedback_ticket_interval'] = null;
            }
            if (empty($this->post['reminder_ticket_interval'])) {
                $this->post['reminder_ticket_interval'] = null;
            }
            if (empty($this->post['response_id'])) {
                $this->post['response_id'] = null;
            }
            if (empty($this->post['attachment_types'])) {
                $this->post['attachment_types'] = null;
            }
            if (empty($this->post['max_attachment_size'])) {
                $this->post['max_attachment_size'] = null;
            }

            $department = $this->SupportManagerDepartments->edit($department->id, $this->post);

            if (($errors = $this->SupportManagerDepartments->errors())) {
                // Error, reset vars
                $vars = (object)$this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Handle OAuth 2.0 redirection
                if (
                    !empty($this->post['oauth2_provider'])
                    && !empty($this->post['oauth2_client_id'])
                    && !empty($this->post['oauth2_client_secret'])
                ) {
                    // Fetch authorization url
                    try {
                        $transport = $this->Email->buildTransport('oauth2')
                            ->setProvider($this->post['oauth2_provider'])
                            ->setRedirectUri(
                                'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://'
                                    . Configure::get('Blesta.company')->hostname . $this->base_uri
                                    . 'plugin/support_manager/admin_departments/edit/' . $department->id . '/'
                            );
                    } catch (Throwable $e) {
                        $this->flashMessage('error', $e->getMessage());
                        $this->redirect(
                            $this->base_uri . 'plugin/support_manager/admin_departments/edit/' . $department->id . '/'
                        );

                        return;
                    }

                    // Process OAuth 2.0 redirect
                    $params = [
                        'client_id' => $this->post['oauth2_client_id'],
                        'redirect_uri' => $transport->getRedirectUri(),
                        'response_type' => 'code',
                        'access_type' => 'offline',
                        'prompt' => 'consent',
                        'scope' => $transport->getScope()
                    ];
                    $this->redirect($transport->getAuthorizationUrl($params));
                } else {
                    // Success
                    $department = $this->SupportManagerDepartments->get($department->id);
                    $this->flashMessage(
                        'message',
                        Language::_('AdminDepartments.!success.department_updated', true, $department->name),
                        null,
                        false
                    );
                    $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
                }
            }
        }

        // Set initial department
        if (!isset($vars)) {
            $vars = $department;
        }

        // Set the selected auto response, if any
        if (!empty($vars->response_id)) {
            $response = $this->SupportManagerResponses->get($vars->response_id);
            if ($response && $response->company_id == $this->company_id) {
                $this->set('response', $response);
            } else {
                unset($vars->response_id);
            }
        }

        $this->set('vars', $vars);
        $this->set('department', $department);
        $this->set('priorities', $this->SupportManagerDepartments->getPriorities());
        $this->set('methods', $this->SupportManagerDepartments->getMethods());
        $this->set('providers', $this->SupportManagerDepartments->getProviders());
        $this->set('statuses', $this->SupportManagerDepartments->getStatuses());
        $this->set('security_types', $this->SupportManagerDepartments->getSecurityTypes());
        $this->set('message_types', $this->SupportManagerDepartments->getMessageTypes());
        $this->set('field_types', $this->SupportManagerDepartments->getFieldTypes());
        $this->set('visibility_options', $this->SupportManagerDepartments->getVisibilityOptions());
        $this->set(
            'close_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getTicketIntervals(30)
        );
        $this->set(
            'delete_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getTicketIntervals(90)
        );
        $this->set(
            'feedback_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getReminderIntervals()
        );
        $this->set(
            'reminder_ticket_intervals',
            ['' => Language::_('Global.select.never', true)]
            + $this->SupportManagerDepartments->getReminderIntervals()
        );
        $this->set('ticket_statuses', $this->SupportManagerTickets->getStatuses());
        $this->set('piping_config', '/usr/bin/php ' . realpath(dirname(__FILE__) . DS . '..' . DS) . DS . 'pipe.php');
    }

    /**
     * Test the IMAP/POP3 details and forces an import
     */
    public function importTest()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
        }

        if (!isset($this->get[0]) || !($department = $this->SupportManagerDepartments->get($this->get[0])) ||
            $this->company_id != $department->company_id) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
        }

        try {
            Loader::loadModels($this, ['SupportManager.SupportManagerDepartments']);
            Loader::loadComponents($this, ['SupportManager.TicketManager']);

            // Set options when processing emails
            $webdir = $this->SupportManagerDepartments->getWebDirectory();
            $is_cli = (empty($_SERVER['REQUEST_URI']));

            // Set the URIs to the admin/client portals
            $options = [
                'is_cli' => $is_cli,
                'client_uri' => $webdir . Configure::get('Route.client') . '/',
                'admin_uri' => $webdir . Configure::get('Route.admin') . '/',
                'verbose' => true
            ];
            $this->TicketManager->setOptions($options);

            // Process emails
            $departments = [
                (object) [
                    'id' => $department->id,
                    'host' => $this->post['host'] ?? '',
                    'port' => $this->post['port'] ?? '',
                    'user' => $this->post['user'] ?? '',
                    'pass' => $this->post['pass'] ?? '',
                    'email' => $this->post['email'] ?? '',
                    'method' => $this->post['method'] ?? '',
                    'default_priority' => $this->post['default_priority'] ?? 'medium',
                    'clients_only' => $this->post['clients_only'] ?? false,
                    'security' => $this->post['security'] ?? 'none',
                    'box_name' => $this->post['box_name'] ?? 'INBOX',
                    'mark_messages' => $this->post['mark_messages'] ?? 'read'
                ]
            ];
            $output = $this->TicketManager->processDepartmentEmails($departments);

            if (!empty($output)) {
                $error = implode("\n", $output);
                echo $this->setMessage('error', $error, true, null, false);
            } else {
                echo $this->setMessage(
                    'message',
                    Language::_('AdminDepartments.!success.department_imported', true, ($this->post['name'] ?? '')),
                    true,
                    null,
                    false
                );
            }
        } catch (Throwable $e) {
            echo $this->setMessage('error', $e->getMessage(), true, null, false);
        }

        return false;
    }

    /**
     * Delete a department
     */
    public function delete()
    {
        if (!isset($this->post['id']) || !($department = $this->SupportManagerDepartments->get($this->post['id'])) ||
            $this->company_id != $department->company_id) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
        }

        // Attempt to delete the department
        $this->SupportManagerDepartments->delete($department->id);

        // Set message
        if (($errors = $this->SupportManagerDepartments->errors())) {
            $this->flashMessage('error', $errors, null, false);
        } else {
            $this->flashMessage(
                'message',
                Language::_('AdminDepartments.!success.department_deleted', true, $department->name),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/support_manager/admin_departments/');
    }

    /**
     * AJAX Retrieves staff associated with a department
     */
    public function assignedStaff()
    {
        // Ensure a department ID was given
        if (!$this->isAjax() || !isset($this->get[0]) ||
            !($department = $this->SupportManagerDepartments->get($this->get[0])) ||
            $department->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['SupportManager.SupportManagerStaff']);

        $vars = [
            'staff' => $this->SupportManagerStaff->getAll($this->company_id, $department->id)
        ];

        // Send the template
        echo $this->partial('admin_departments_assigned_staff', $vars);

        // Render without layout
        return false;
    }

    /**
     * AJAX retrieves the partial that lists categories and responses
     */
    public function getResponseListing()
    {
        // Ensure a valid category was given
        $category = (isset($this->get[0]) ? $this->SupportManagerResponses->getCategory($this->get[0]) : null);
        if ($category && $category->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        // Load language for responses
        Language::loadLang('admin_responses', null, PLUGINDIR . 'support_manager' . DS . 'language' . DS);

        // Build the partial for listing categories and responses
        $category_id = (isset($category->id) ? $category->id : null);
        $vars = [
            'categories' => $this->SupportManagerResponses->getAllCategories($this->company_id, $category_id),
            'category' => $category,
            'show_links' => false
        ];

        if ($category) {
            $vars['responses'] = $this->SupportManagerResponses->getAll($this->company_id, $category_id);
        }

        echo json_encode($this->partial('admin_responses_response_list', $vars));
        return false;
    }

    /**
     * AJAX searches predefined responses by name/details
     */
    public function searchResponses()
    {
        // Ensure this is an AJAX request
        if (!$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $query = isset($this->get['q']) ? trim($this->get['q']) : '';

        // Require minimum 2 characters
        if (strlen($query) < 2) {
            echo json_encode(['results' => [], 'error' => 'min_chars']);
            return false;
        }

        // Search for matching responses
        $results = $this->SupportManagerResponses->search($this->company_id, $query, 20);

        // Format results for JSON output
        $formatted_results = [];
        foreach ($results as $response) {
            $formatted_results[] = [
                'id' => $response->id,
                'name' => $response->name,
                'category_path' => $response->category_path
            ];
        }

        echo json_encode(['results' => $formatted_results]);
        return false;
    }

    /**
     * AJAX retrieves a specific predefined response
     */
    public function getResponse()
    {
        // Ensure a valid response was given
        $response = (isset($this->get[0]) ? $this->SupportManagerResponses->get($this->get[0]) : null);
        if ($response && $response->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        echo json_encode($response);
        return false;
    }

    /**
     * Assigns the staff member to this department
     * @see AdminDepartments::add()
     *
     * @param int $department_id The ID of the department to add the staff to
     */
    private function addStaff($department_id)
    {
        $this->uses(['SupportManager.SupportManagerStaff']);

        $support_staff = $this->SupportManagerStaff->get($this->staff_id, $this->company_id);

        // Create a new staff member
        if (!$support_staff) {
            // Build default staff schedules to all day, every day
            $schedules = [];
            $days = $this->SupportManagerStaff->getDays();
            foreach (array_keys($days) as $day) {
                $schedules[] = ['day' => $day, 'all_day' => 1];
            }

            // Default to receive ticket emails for all priorities
            $settings = ['ticket_emails' => []];
            $department_priorities = $this->SupportManagerDepartments->getPriorities();
            foreach ($department_priorities as $key => $language) {
                $settings['ticket_emails'][$key] = 'true';
            }

            // Create the staff member and assign them to this department
            $vars = [
                'staff_id' => $this->staff_id,
                'company_id' => $this->company_id,
                'departments' => [$department_id],
                'schedules' => $schedules,
                'settings' => $settings
            ];
            $this->SupportManagerStaff->add($vars);
        } else {
            // Re-save the support staff member while also assigning this department to them
            $schedules = [];
            $i = 0;
            foreach ($support_staff->schedules as $schedule) {
                // Format the schedule time
                $schedules[$i]['day'] = $schedule->day;
                $schedules[$i]['start_time'] = $this->Date->cast(
                    $schedule->start_time,
                    Configure::get('SupportManager.time_format')
                );
                $schedules[$i]['end_time'] = $this->Date->cast(
                    $schedule->end_time,
                    Configure::get('SupportManager.time_format')
                );
                $i++;
            }

            $departments = [$department_id];
            foreach ($support_staff->departments as $department) {
                $departments[] = $department->id;
            }

            $vars = [
                'company_id' => $support_staff->company_id,
                'departments' => $departments,
                'schedules' => $schedules
            ];

            $this->SupportManagerStaff->edit($support_staff->id, $vars);
        }
    }
}
