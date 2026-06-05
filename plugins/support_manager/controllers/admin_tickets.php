<?php

use Blesta\Core\Util\Input\Fields\InputFields;
use Blesta\Core\Util\Input\Fields\Html as FieldsHtml;

/**
 * Support Manager Admin Tickets controller
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminTickets extends SupportManagerController
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

        // Load the Text Parser
        $this->helpers(['TextParser']);
        $this->uses(['SupportManager.SupportManagerStaff', 'SupportManager.SupportManagerTickets', 'Clients']);

        $this->staff_id = $this->Session->read('blesta_staff_id');

        $this->set('string', $this->DataStructure->create('string'));
    }

    /**
     * Retrieves a key/value list of actions that can be performed on tickets
     *
     * @return array A list of key/value pairs representing valid actions
     */
    private function getTicketActions()
    {
        return [
            'update_status' => Language::_('Global.action.update_status', true),
            'delete' => Language::_('Global.action.delete', true),
            'merge' => Language::_('Global.action.merge', true),
            'reassign' => Language::_('Global.action.reassign', true)
        ];
    }

    /**
     * Retrieves a key/value list of actions that can be performed on replies
     *
     * @return array A list of key/value pairs representing valid actions
     */
    private function getReplyActions()
    {
        return [
            'quote' => Language::_('Global.action.quote', true),
            'split' => Language::_('Global.action.split', true)
        ];
    }

    /**
     * Sets a message to the view if no staff or departments are set
     */
    private function setDepartmentStaffNotice()
    {
        $this->uses(['SupportManager.SupportManagerDepartments']);

        if ($this->SupportManagerDepartments->getListCount($this->company_id) == 0 ||
            $this->SupportManagerStaff->getListCount($this->company_id) == 0) {
            // Set language for the department/staff nav items
            $department = Language::_('SupportManagerPlugin.nav_primary_staff.departments', true);
            $staff = Language::_('SupportManagerPlugin.nav_primary_staff.staff', true);

            $this->setMessage(
                'notice',
                Language::_('AdminTickets.!notice.no_departments_staff', true, $department, $staff),
                false,
                null,
                false
            );
        }
    }

    /**
     * View tickets
     */
    public function index()
    {
        $status = (isset($this->get[0]) ? $this->get[0] : 'open');
        $page = (isset($this->get[1]) ? (int)$this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'last_reply_date');
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

        $this->set('status', $status);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        // Set the number of tickets of each type
        $status_count = [
            'open' => $this->SupportManagerTickets->getStatusCount('open', $this->staff_id, null, $post_filters),
            'awaiting_reply' => $this->SupportManagerTickets->getStatusCount('awaiting_reply', $this->staff_id, null, $post_filters),
            'in_progress' => $this->SupportManagerTickets->getStatusCount('in_progress', $this->staff_id, null, $post_filters),
            'on_hold' => $this->SupportManagerTickets->getStatusCount('on_hold', $this->staff_id, null, $post_filters),
            'closed' => $this->SupportManagerTickets->getStatusCount('closed', $this->staff_id, null, $post_filters),
            'trash' => $this->SupportManagerTickets->getStatusCount('trash', $this->staff_id, null, $post_filters)
        ];

        $tickets = $this->SupportManagerTickets->getList(
            $status,
            $this->staff_id,
            null,
            $page,
            [$sort => $order, 'support_tickets.id' => $order],
            false,
            null,
            $post_filters
        );
        $total_results = $this->SupportManagerTickets->getListCount($status, $this->staff_id, null, $post_filters);

        // Set pagination parameters, set group if available
        $params = ['sort' => $sort, 'order' => $order];

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/support_manager/admin_tickets/index/' . $status . '/[p]/',
                'params' => $params
            ]
        );
        $this->setPagination($this->get, $settings);

        // Set the time that the ticket was last replied to
        foreach ($tickets as &$ticket) {
            $ticket->last_reply_time = $this->timeSince($ticket->last_reply_date);
        }

        $ticket_actions = $this->getTicketActions();
        if ($status !== 'trash') {
            unset($ticket_actions['delete']);
        }

        // Get AI assistant name for ticket listing display
        $this->uses(['SupportManager.SupportManagerSettings']);
        $ai_name_setting = $this->SupportManagerSettings->getSetting('sm_ai_assistant_name', $this->company_id);
        $this->set('ai_assistant_name', $ai_name_setting ? $ai_name_setting->value : null);

        $this->set('staff_id', $this->staff_id);
        $this->set('tickets', $tickets);
        $this->set('page', $page);
        $this->set('status_count', $status_count);
        $this->set('priorities', $this->SupportManagerTickets->getPriorities());
        $this->set('ticket_actions', $ticket_actions);
        $this->set('ticket_statuses', $this->SupportManagerTickets->getStatuses());

        // Set a message if staff/departments are not setup
        if (!$this->isAjax()) {
            $this->setDepartmentStaffNotice();
            $this->set('set_ticket_time', true);

            // Build sidebar filter data
            $this->uses(['SupportManager.SupportManagerDepartments']);

            $departments = $this->Form->collapseObjectArray(
                $this->SupportManagerDepartments->getAll($this->company_id),
                'name',
                'id'
            );

            $staff = [];
            foreach ($this->SupportManagerStaff->getAll($this->company_id) as $staff_member) {
                $staff[$staff_member->id] = $staff_member->first_name . ' ' . $staff_member->last_name;
            }

            $time_options = [];
            $minutes = [15, 30];
            $hours = [1, 6, 12, 24, 72];
            foreach ($minutes as $minute) {
                $time_options[$minute] = Language::_('AdminTickets.index.minutes', true, $minute);
            }
            foreach ($hours as $hour) {
                $time_options[$hour * 60] = Language::_(
                    'AdminTickets.index.' . ($hour == 1 ? 'hour' : 'hours'),
                    true,
                    $hour
                );
            }

            $sidebar_html = $this->partial('admin_tickets_sidebar', [
                'filter_vars' => $post_filters,
                'priorities' => $this->SupportManagerTickets->getPriorities(),
                'departments' => $departments,
                'staff' => $staff,
                'time_options' => $time_options
            ]);
            $this->structure->set('side_bar_content', $sidebar_html);
            $this->structure->set('side_bar', ['partials/plugin_sidebar', null, 'side-content-mobile-visible']);
        }

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * View client profile ticket widget
     */
    public function client()
    {
        // Ensure a valid client was given
        $this->uses(['Clients']);
        $client_id = (isset($this->get['client_id'])
            ? $this->get['client_id']
            : (isset($this->get[0]) ? $this->get[0] : null)
        );
        if (empty($client_id) || !($client = $this->Clients->get($client_id))) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

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

        // Set the number of tickets of each type
        $status_count = [
            'open' => $this->SupportManagerTickets->getStatusCount('open', $this->staff_id, $client->id, $post_filters),
            'awaiting_reply' => $this->SupportManagerTickets->getStatusCount(
                'awaiting_reply',
                $this->staff_id,
                $client->id,
                $post_filters
            ),
            'in_progress' => $this->SupportManagerTickets->getStatusCount('in_progress', $this->staff_id, $client->id, $post_filters),
            'on_hold' => $this->SupportManagerTickets->getStatusCount('on_hold', $this->staff_id, $client->id, $post_filters),
            'closed' => $this->SupportManagerTickets->getStatusCount('closed', $this->staff_id, $client->id, $post_filters),
            'trash' => $this->SupportManagerTickets->getStatusCount('trash', $this->staff_id, $client->id, $post_filters)
        ];

        $status = (isset($this->get[1]) ? $this->get[1] : 'open');
        $page = (isset($this->get[2]) ? (int)$this->get[2] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'last_reply_date');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        // Fetch tickets
        $tickets = $this->SupportManagerTickets->getList(
            $status,
            $this->staff_id,
            $client->id,
            $page,
            [$sort => $order],
            true,
            null,
            $post_filters
        );

        // Set the time that the ticket was last replied to
        foreach ($tickets as &$ticket) {
            $ticket->last_reply_time = $this->timeSince($ticket->last_reply_date);
        }

        $this->set(
            'widget_state',
            isset($this->widgets_state['tickets_client']) ? $this->widgets_state['tickets_client'] : null
        );
        $this->set('tickets', $tickets);
        $this->set('client', $client);
        $this->set('status', $status);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('staff_id', $this->staff_id);
        $this->set('status_count', $status_count);
        $this->set('priorities', $this->SupportManagerTickets->getPriorities());

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->SupportManagerTickets->getListCount($status, $this->staff_id, $client->id),
                'uri' => $this->base_uri . 'plugin/support_manager/admin_tickets/client/'
                    . $client->id . '/' . $status . '/[p]/',
                'params' => ['sort' => $sort, 'order' => $order],
            ]
        );
        $this->setPagination($this->get, $settings);

        // Set the input field filters for the widget
        $filters = $this->getFilters($post_filters);
        $this->set('filters', $filters);
        $this->set('filter_vars', $post_filters);

        if ($this->isAjax()) {
            return $this->renderAjaxWidgetIfAsync(
                isset($this->get['client_id']) ? null : (isset($this->get[2]) || isset($this->get['sort']))
            );
        }
    }

    /**
     * Client Ticket count
     */
    public function clientTicketCount()
    {
        $client_id = isset($this->get[0]) ? $this->get[0] : null;
        $status = isset($this->get[1]) ? $this->get[1] : 'open';

        echo $this->SupportManagerTickets->getStatusCount($status, $this->staff_id, $client_id);
        return false;
    }

    /**
     * Add a ticket
     */
    public function add()
    {
        $this->uses([
            'Clients', 'Contacts', 'Staff', 'Services', 'ModuleManager', 'SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerStaff', 'SupportManager.SupportManagerResponses'
        ]);

        // Set the client if given
        $client_id = null;
        $client = null;
        if (isset($this->get[0])) {
            $client = $this->Clients->get($this->get[0]);
            $client_id = ($client ? $client->id : $this->get[0]);
        }

        $please_select = ['' => Language::_('AppController.select.please', true)];
        $department_staff = ['' => Language::_('AdminTickets.text.unassigned', true)];

        // Fetch client related services
        $service_ids = [];
        if ($client_id) {
            $service_ids = $this->fetchClientServicesIds($client_id);
        }

        if (!empty($this->post)) {
            // Set recipients
            if (!empty($this->post['recipients'])) {
                foreach ($this->post['recipients'] as $key => $recipient) {
                    if (empty($recipient)) {
                        unset($this->post['recipients'][$key]);
                    }
                }
            }

            $data = $this->post;

            if (!empty($data['department_id'])) {
                $department = $this->SupportManagerDepartments->get($data['department_id']);
            }

            // Set custom field checkboxes default value
            foreach ($department->fields ?? [] as $field) {
                if ($field->type == 'checkbox') {
                    if (!isset($this->post['custom_fields'][$field->id])) {
                        $this->post['custom_fields'][$field->id] = '0';
                    }
                }
            }

            // Set staff ticket is assigned to
            $data['staff_id'] = ($data['ticket_staff_id'] ?? $this->staff_id);
            $data['type'] = 'reply';

            // Set the client ID if not passed in by POST
            if (!isset($data['client_id'])) {
                $data['client_id'] = $client_id;
            } elseif ($client_id == null) {
                $client_id = $data['client_id'];
            }

            // Reject base64 inline images
            $has_base64 = $this->SupportManagerTickets->containsBase64Images($data['details'] ?? '');
            if ($has_base64) {
                $this->setMessage('error', [
                    'details' => ['base64' => Language::_(
                        'SupportManagerTickets.!error.inline_image.base64',
                        true
                    )]
                ], false, null, false);
                $vars = (object)$this->post;

                // Set the priorities and staff to show
                if (!empty($data['department_id'])) {
                    $priorities = $please_select + $this->SupportManagerTickets->getPriorities();
                    $department_staff += $this->Form->collapseObjectArray(
                        $this->SupportManagerStaff->getAll($this->company_id, $data['department_id'], false),
                        ['first_name', 'last_name'],
                        'id',
                        ' '
                    );
                }
            }

            // Finalize inline images and create the ticket
            $inline_attachment_ids = [];
            if (!$has_base64) {
                $temp_ids = $this->post['inline_image_temp_ids'] ?? [];
                if (!empty($temp_ids)) {
                    if (is_string($temp_ids)) {
                        $temp_ids = json_decode($temp_ids, true) ?: [];
                    }
                    $finalized = $this->SupportManagerTickets->finalizeInlineImages(
                        $data['details'],
                        $temp_ids
                    );
                    $data['details'] = $finalized['details'];
                    $inline_attachment_ids = $finalized['attachment_ids'];
                }

            // Create a transaction
            $this->SupportManagerTickets->begin();

            // Open the ticket
            $ticket_id = $this->SupportManagerTickets->add($data);
            $ticket_errors = $this->SupportManagerTickets->errors();
            $reply_errors = [];

            // Create the initial reply
            if (!$ticket_errors) {
                // Set the staff that replied to this ticket
                $data['staff_id'] = $this->staff_id;
                $reply_id = $this->SupportManagerTickets->addReply($ticket_id, $data, $this->files, true);
                $reply_errors = $this->SupportManagerTickets->errors();
            }

            // Get staff
            $staff = $this->Staff->get($data['staff_id']);

            $errors = array_merge(($ticket_errors ? $ticket_errors : []), ($reply_errors ? $reply_errors : []));

            if ($errors) {
                // Error, reset vars
                $this->SupportManagerTickets->rollBack();

                // Rollback inline attachments since the reply failed
                $this->SupportManagerTickets->rollbackInlineAttachments($inline_attachment_ids);

                $vars = (object)$this->post;
                $this->setMessage('error', $errors, false, null, false);

                // Set the priorities and staff to show
                if (!empty($data['department_id'])) {
                    $priorities = $please_select + $this->SupportManagerTickets->getPriorities();
                    $department_staff += $this->Form->collapseObjectArray(
                        $this->SupportManagerStaff->getAll($this->company_id, $data['department_id'], false),
                        ['first_name', 'last_name'],
                        'id',
                        ' '
                    );
                }
            } else {
                // Success
                $this->SupportManagerTickets->commit();

                // Link inline attachments to the reply
                if (!empty($inline_attachment_ids) && !empty($reply_id)) {
                    $this->SupportManagerTickets->linkInlineAttachmentsToReply(
                        $inline_attachment_ids,
                        $reply_id
                    );
                }

                // Fetch the ticket
                $ticket = $this->SupportManagerTickets->get($ticket_id);

                // Get the company hostname
                $hostname = isset(Configure::get('Blesta.company')->hostname)
                    ? Configure::get('Blesta.company')->hostname
                    : '';

                // Send the email associated with this ticket
                $key = mt_rand();
                $hash = $this->SupportManagerTickets->generateReplyHash($ticket->id, $key);
                $additional_tags = [
                    'SupportManager.ticket_updated' => [
                        'subject' => $ticket->summary ?? '',
                        'staff_name' => $this->Html->concat(' ', $staff->first_name ?? '', $staff->last_name ?? ''),
                        'update_ticket_url' => $this->Html->safe(
                            $hostname . $this->client_uri . 'plugin/support_manager/client_tickets/reply/'
                            . $ticket->id . '/?sid='
                            . rawurlencode(
                                $this->SupportManagerTickets->systemEncrypt(
                                    'h=' . substr($hash, -16) . '|k=' . $key
                                )
                            )
                        )
                    ]
                ];
                $this->SupportManagerTickets->sendEmail($reply_id, $additional_tags);

                // Notify staff that a ticket has been assigned to them
                $assign_to_staff_id = (isset($data['ticket_staff_id']) ? $data['ticket_staff_id'] : $this->staff_id);
                if (!empty($assign_to_staff_id) && $this->staff_id != $ticket->staff_id) {
                    $this->SupportManagerTickets->sendTicketAssignedEmail($ticket->id);
                }

                $this->flashMessage(
                    'message',
                    Language::_('AdminTickets.!success.ticket_created', true, $ticket->code),
                    null,
                    false
                );
                $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
            }
            } // end if (!$has_base64)
        }

        // Set departments, statuses
        $departments = $please_select
            + $this->Form->collapseObjectArray(
                $this->SupportManagerDepartments->getAll($this->company_id),
                'name',
                'id'
            );
        $statuses = $please_select + $this->SupportManagerTickets->getStatuses();
        unset($statuses['closed'], $statuses['trash']);

        // Set default vars
        if (!isset($vars)) {
            $vars = (object)['status' => 'open'];
        }

        // Set department custom fields
        if (!empty($data['department_id'])) {
            $department = $this->SupportManagerDepartments->get($data['department_id']);
            $department_fields = $this->renderDepartmentCustomFields($department->fields, $vars->custom_fields ?? []);
        }

        // Fetch client-related contacts
        $contacts = $this->Form->collapseObjectArray(
            $this->Contacts->getAll($client_id, 'billing') + $this->Contacts->getAll($client_id, 'other'),
            ['first_name', 'last_name', 'email'],
            'id',
            ' '
        );
        $this->set('contacts', $contacts);

        $this->set('vars', $vars);
        $this->set('service_ids', $service_ids);
        $this->set('departments', $departments);
        $this->set('priorities', ($priorities ?? $please_select));
        $this->set('department_fields', $department_fields ?? '');
        $this->set('statuses', $statuses);
        $this->set('department_staff', $department_staff);
        $this->set('client', $client);

        // Get staff settings and set default markdown_editor_mode if not set
        $staff_settings = $this->SupportManagerStaff->getSettings($this->staff_id, $this->company_id);
        if (!isset($staff_settings['markdown_editor_mode'])) {
            $staff_settings['markdown_editor_mode'] = 'markdown_no_preview';
        }

        $this->set('staff_id', $this->staff_id);
        $this->set('staff_settings', $staff_settings);

        // Load predefined responses
        Language::loadLang('admin_responses', null, PLUGINDIR . 'support_manager' . DS . 'language' . DS);
        $predefined_responses = $this->partial('admin_responses_response_list', [
            'categories' => $this->SupportManagerResponses->getAllCategories($this->company_id, null),
            'show_links' => false
        ]);
        $this->set('predefined_responses', $predefined_responses);

        // Pre-render the sidebar partial and set it up for the side-content area
        $sidebar_html = $this->partial(
            'admin_tickets_add_sidebar',
            [
                'vars' => $vars,
                'client' => $client,
                'departments' => $departments,
                'department_staff' => $department_staff,
                'priorities' => ($priorities ?? $please_select),
                'statuses' => $statuses,
                'service_ids' => $service_ids,
                'contacts' => $contacts
            ]
        );
        $this->structure->set('side_bar_content', $sidebar_html);
        $this->structure->set('side_bar', ['partials/plugin_sidebar', null, 'side-content-mobile-visible']);
    }

    /**
     * Reply to a ticket
     */
    public function reply()
    {
        // Ensure a valid ticket is given
        if (!isset($this->get[0])
            || !($ticket = $this->SupportManagerTickets->get($this->get[0], true, null, $this->staff_id))) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
        }

        $this->uses([
            'Clients', 'Contacts', 'Services', 'Staff', 'ModuleManager', 'SupportManager.SupportManagerDepartments', 'SupportManager.SupportManagerResponses'
        ]);

        $department = $this->SupportManagerDepartments->get($ticket->department_id);

        if (!empty($this->post)) {
            // Set custom field checkboxes default value
            foreach ($department->fields ?? [] as $field) {
                if ($field->type == 'checkbox') {
                    if (!isset($this->post['custom_fields'][$field->id])) {
                        $this->post['custom_fields'][$field->id] = '0';
                    }
                }
            }

            // Set recipients
            if (!empty($this->post['recipients'])) {
                foreach ($this->post['recipients'] as $key => $recipient) {
                    if (empty($recipient)) {
                        unset($this->post['recipients'][$key]);
                    }
                }
            }

            // Set empty contact list
            if (!isset($this->post['contacts'])) {
                $this->post['contacts'] = [];
            }

            $data = $this->post;
            $data['type'] = (isset($this->post['reply_type'])
                && in_array($this->post['reply_type'], ['reply', 'note']) ? $this->post['reply_type'] : null);
            $data['staff_id'] = $this->staff_id;

            // Set the details field
            if ($data['type'] == 'note') {
                $data['details'] = $data['notes'];
            }

            // Transition status unless already changed
            if (isset($data['status'])
                && $data['status'] == $ticket->status
                && $ticket->status == 'open'
                && $data['type'] != 'note'
                && ($department = $this->SupportManagerDepartments->get($ticket->department_id))
                && $department->automatic_transition == '1'
                && !empty($data['details'])
            ) {
                $data['status'] = 'awaiting_reply';
            }

            // Get staff
            $staff = $this->Staff->get($data['staff_id']);

            // Reject base64 inline images
            $has_base64 = $this->SupportManagerTickets->containsBase64Images($data['details'] ?? '');
            if ($has_base64) {
                $this->setMessage('error', [
                    'details' => ['base64' => Language::_(
                        'SupportManagerTickets.!error.inline_image.base64',
                        true
                    )]
                ], false, null, false);
                $vars = (object)$this->post;
            }

            // Finalize inline images and create the reply
            $inline_attachment_ids = [];
            if (!$has_base64) {
                $temp_ids = $this->post['inline_image_temp_ids'] ?? [];
                if (!empty($temp_ids)) {
                    if (is_string($temp_ids)) {
                        $temp_ids = json_decode($temp_ids, true) ?: [];
                    }
                    $finalized = $this->SupportManagerTickets->finalizeInlineImages(
                        $data['details'],
                        $temp_ids
                    );
                    $data['details'] = $finalized['details'];
                    $inline_attachment_ids = $finalized['attachment_ids'];
                }

            // Create a transaction
            $this->SupportManagerTickets->begin();

            // Add the reply
            $reply_id = $this->SupportManagerTickets->addReply($ticket->id, $data, $this->files);

            if (($errors = $this->SupportManagerTickets->errors())) {
                // Error, reset vars
                $this->SupportManagerTickets->rollBack();

                // Rollback inline attachments since the reply failed
                $this->SupportManagerTickets->rollbackInlineAttachments($inline_attachment_ids);

                $vars = (object)$this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Success, commit
                $this->SupportManagerTickets->commit();

                // Link inline attachments to the reply
                if (!empty($inline_attachment_ids) && !empty($reply_id)) {
                    $this->SupportManagerTickets->linkInlineAttachmentsToReply(
                        $inline_attachment_ids,
                        $reply_id
                    );
                }

                // Mark AI response as used if this reply used an AI-generated response
                if (!empty($this->post['ai_analysis_id'])) {
                    $this->uses(['SupportManager.SupportManagerAiResponseAnalyses']);
                    $this->SupportManagerAiResponseAnalyses->markUsed(
                        $this->post['ai_analysis_id'],
                        $this->staff_id
                    );
                }

                // Get the company hostname
                $hostname = isset(Configure::get('Blesta.company')->hostname)
                    ? Configure::get('Blesta.company')->hostname
                    : '';

                // Send the email associated with this ticket
                $key = mt_rand();
                $hash = $this->SupportManagerTickets->generateReplyHash($ticket->id, $key);
                $additional_tags = [
                    'SupportManager.ticket_updated' => [
                        'subject' => $ticket->summary ?? '',
                        'staff_name' => $this->Html->concat(' ', $staff->first_name ?? '', $staff->last_name ?? ''),
                        'update_ticket_url' => $this->Html->safe(
                            $hostname . $this->client_uri . 'plugin/support_manager/client_tickets/reply/'
                            . $ticket->id . '/?sid='
                            . rawurlencode(
                                $this->SupportManagerTickets->systemEncrypt('h=' . substr($hash, -16) . '|k=' . $key)
                            )
                        )
                    ]
                ];
                $this->SupportManagerTickets->sendEmail($reply_id, $additional_tags);

                // Notify staff that a ticket has been assigned to them
                if (!empty($this->post['ticket_staff_id']) && $this->post['ticket_staff_id'] != $ticket->staff_id
                    && $this->staff_id != $this->post['ticket_staff_id']) {
                    $this->SupportManagerTickets->sendTicketAssignedEmail($ticket->id);
                }

                $this->flashMessage(
                    'message',
                    Language::_('AdminTickets.!success.ticket_updated', true, $ticket->code),
                    null,
                    false
                );
                $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
            }
            } // end if (!$has_base64)
        }

        // Set initial ticket
        if (!isset($vars)) {
            $vars = $ticket;
            $vars->ticket_staff_id = $ticket->staff_id;
        }

        $valid_extensions = Configure::get('SupportManager.image_mime_types');
        foreach ($ticket->replies as $reply) {
            $reply->images = [];
            foreach ($reply->attachments as $attachment) {
                $image_name_parts = explode('.', $attachment->name);
                $image_extension = end($image_name_parts);
                if (in_array(strtolower($image_extension), $valid_extensions)) {
                    $reply->images[$attachment->id] = $attachment->name;
                }
            }
        }

        // Set department custom fields
        $department_fields = $this->renderDepartmentCustomFields($department->fields, $vars->custom_fields ?? []);

        $this->set('ticket', $ticket);
        $this->set('vars', $vars);
        $this->set(
            'refresh_message',
            $this->setMessage(
                'notice',
                Language::_('AdminTickets.reply.refresh', true)
                    . ' <a href="#ticket_replies">'
                        . Language::_('AdminTickets.reply.refresh_link', true)
                    . '</a>',
                true,
                ['preserve_tags' => true],
                false
            )
        );

        // Fetch client related services
        $service_ids = [];
        if (isset($ticket->client_id)) {
            $service_ids = $this->fetchClientServicesIds($ticket->client_id);
        }

        $this->set('service_ids', $service_ids);
        $this->set('statuses', $this->SupportManagerTickets->getStatuses());
        $this->set('priorities', $this->SupportManagerTickets->getPriorities());
        $this->set('department_fields', $department_fields);
        $this->set('staff_id', $this->staff_id);
        $this->set('service', $this->Services->get($ticket->service_id));

        // Set the client this ticket belongs to
        $client = null;
        if (!empty($ticket->client_id)) {
            $client = $this->Clients->get($ticket->client_id, false);
            $this->set('client', $client);
        }

        $please_select = ['' => Language::_('AppController.select.please', true)];
        $departments = $please_select
            + $this->Form->collapseObjectArray(
                $this->SupportManagerDepartments->getAll($this->company_id),
                'name',
                'id'
            );

        $department_staff = ['' => Language::_('AdminTickets.text.unassigned', true)] +
            $this->Form->collapseObjectArray(
                $this->SupportManagerStaff->getAll($this->company_id, $ticket->department_id, false),
                ['first_name', 'last_name'],
                'id',
                ' '
            );

        $this->set('departments', $departments);
        $this->set('department_staff', $department_staff);

        // Get AI settings
        $this->uses(['SupportManager.SupportManagerSettings']);
        $ai_settings_raw = $this->SupportManagerSettings->getSettings($this->company_id);
        $ai_settings = [];
        foreach ($ai_settings_raw as $setting) {
            $ai_settings[$setting->key] = $setting->value;
        }
        $this->set('ai_settings', $ai_settings);

        // Make staff settings available for those staff that have replied to this ticket
        $staff_settings = [
            $this->staff_id => $this->SupportManagerStaff->getSettings($this->staff_id, $this->company_id)
        ];
        if (!empty($ticket->replies)) {
            foreach ($ticket->replies as $reply) {
                if ($reply->staff_id) {
                    if (!array_key_exists($reply->staff_id, $staff_settings)) {
                        $staff_settings[$reply->staff_id] = $this->SupportManagerStaff->getSettings(
                            $reply->staff_id,
                            $this->company_id
                        );
                    }
                }
            }
        }

        // Set default markdown_editor_mode if not set for each staff member
        foreach ($staff_settings as $staff_id => &$settings) {
            if (!isset($settings['markdown_editor_mode'])) {
                $settings['markdown_editor_mode'] = 'markdown_no_preview';
            }
        }

        $this->set('staff_settings', $staff_settings);

        $this->set(
            'ticket_replies',
            $this->partial(
                'admin_tickets_replies',
                [
                    'ticket' => $ticket,
                    'ticket_actions' => $this->getReplyActions(),
                    'thumbnails_per_row' => Configure::get('SupportManager.thumbnails_per_row'),
                    'staff_settings' => $staff_settings,
                    'ai_settings' => $ai_settings
                ]
            )
        );

        // Fetch client-related contacts
        $contacts = $this->Form->collapseObjectArray(
            $this->Contacts->getAll($ticket->client_id),
            ['first_name', 'last_name', 'email'],
            'id',
            ' '
        );
        $this->set('contacts', $contacts);

        // Set the page title
        $this->structure->set('page_title', Language::_('AdminTickets.reply.page_title', true, $ticket->code));

        // Load predefined responses
        Language::loadLang('admin_responses', null, PLUGINDIR . 'support_manager' . DS . 'language' . DS);
        $predefined_responses = $this->partial('admin_responses_response_list', [
            'categories' => $this->SupportManagerResponses->getAllCategories($this->company_id, null),
            'show_links' => false
        ]);
        $this->set('predefined_responses', $predefined_responses);

        // Pre-render the sidebar partial and set it up for the side-content area
        $sidebar_html = $this->partial(
            'admin_tickets_reply_sidebar',
            [
                'ticket' => $ticket,
                'vars' => $vars,
                'client' => $client ?? null,
                'service' => $this->Services->get($ticket->service_id),
                'departments' => $departments,
                'department_staff' => $department_staff,
                'priorities' => $priorities ?? $this->SupportManagerTickets->getPriorities(),
                'statuses' => $statuses ?? $this->SupportManagerTickets->getStatuses(),
                'service_ids' => $service_ids,
                'contacts' => $contacts,
                'ai_settings' => $ai_settings
            ]
        );
        $this->structure->set('side_bar_content', $sidebar_html);
        $this->structure->set('side_bar', ['partials/plugin_sidebar', null, 'side-content-mobile-visible']);
    }

    /**
     * AJAX Fetch contacts when searching
     * @see AdminTickets::add()
     */
    public function getContacts()
    {
        // Ensure there is post data
        if (!$this->isAjax() || empty($this->post['client_id'])) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['Contacts']);
        $client_id = $this->post['client_id'];
        $contacts = $this->Form->collapseObjectArray(
            $this->Contacts->getAll($client_id, 'billing') + $this->Contacts->getAll($client_id, 'other'),
            ['first_name', 'last_name', 'email'],
            'id',
            ' '
        );

        echo json_encode(['contacts' => $contacts]);
        return false;
    }

    /**
     * AJAX Fetch clients when searching
     * @see AdminTickets::add()
     */
    public function getClients()
    {
        // Ensure there is post data
        if (!$this->isAjax() || empty($this->post['search'])) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['Clients']);
        $search = $this->post['search'];
        $clients = $this->Form->collapseObjectArray(
            $this->Clients->search($search),
            ['id_code', 'first_name', 'last_name'],
            'id',
            ' '
        );

        echo json_encode(['clients' => $clients]);
        return false;
    }

    /**
     * AJAX Fetch non-closed tickets
     * @see AdminTickets::add()
     */
    public function searchByCode()
    {
        // Ensure there is post data
        if (!$this->isAjax() || empty($this->post['search'])) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $this->uses(['Clients']);

        // Find matching tickets
        $search = $this->post['search'];
        $page = 1;
        $tickets = $this->SupportManagerTickets->searchByCode(
            $search,
            $this->staff_id,
            'not_closed',
            $page,
            ['code' => 'desc']
        );

        // Fetch the client name for each ticket
        $clients = [];
        foreach ($tickets as &$ticket) {
            if (!empty($ticket->client_id) && !isset($clients[$ticket->client_id])) {
                $clients[$ticket->client_id] = $this->Clients->get($ticket->client_id, false);
            }

            $ticket->display_name = Language::_('AdminTickets.index.ticket_email', true, $ticket->code, $ticket->email);
            if (!empty($clients[$ticket->client_id])) {
                $ticket->display_name = Language::_(
                    'AdminTickets.index.ticket_name',
                    true,
                    $ticket->code,
                    $clients[$ticket->client_id]->first_name,
                    $clients[$ticket->client_id]->last_name
                );
            }
        }

        $tickets = $this->Form->collapseObjectArray($tickets, ['display_name'], 'id', ' ');

        echo json_encode(['tickets' => $tickets]);
        return false;
    }

    /**
     * Search tickets
     */
    public function search()
    {
        // Get search criteria
        $search = (isset($this->get['search']) ? $this->get['search'] : '');
        if (isset($this->post['search'])) {
            $search = $this->post['search'];
        }

        // Set page title
        $this->structure->set('page_title', Language::_('AdminTickets.search.page_title', true, $search));

        $page = (isset($this->get['p']) ? (int)$this->get['p'] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'last_reply_date');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));
        $this->set('search', $search);

        // Search
        $tickets = $this->SupportManagerTickets->search(
            $search,
            $this->staff_id,
            $page,
            [$sort => $order, 'support_tickets.id' => $order]
        );
        foreach ($tickets as &$ticket) {
            $ticket->last_reply_time = $this->timeSince($ticket->last_reply_date);
        }

        $this->set('statuses', $this->SupportManagerTickets->getStatuses());
        $this->set('priorities', $this->SupportManagerTickets->getPriorities());
        $this->set('tickets', $tickets);

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->SupportManagerTickets->getSearchCount($search, $this->staff_id),
                'uri' => $this->base_uri . 'plugin/support_manager/admin_tickets/search/',
                'params' => ['p' => '[p]', 'search' => $search]
            ]
        );
        $this->setPagination($this->get, $settings);

        if ($this->isAjax()) {
            return $this->renderAjaxWidgetIfAsync(
                isset($this->post['search']) ? null : (isset($this->get['search']) || isset($this->get['sort']))
            );
        }
    }

    /**
     * Performs a given ticket action
     */
    public function action()
    {
        // Ensure a valid action was given
        if (empty($this->post['action']) || !in_array($this->post['action'], array_keys($this->getTicketActions()))) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
        }

        $ticket_ids = $this->getPostTicketIDs($this->post);

        switch ($this->post['action']) {
            case 'merge':
                if (!empty($this->post['ticket_id']) && !empty($ticket_ids)) {
                    $this->SupportManagerTickets->merge((int)$this->post['ticket_id'], (array)$ticket_ids);

                    if (($errors = $this->SupportManagerTickets->errors())) {
                        // Error
                        $this->flashMessage('error', $errors, null, false);
                    } else {
                        // Success
                        $ticket = $this->SupportManagerTickets->get((int)$this->post['ticket_id'], false);
                        $this->flashMessage(
                            'message',
                            Language::_('AdminTickets.!success.ticket_merge', true, ($ticket ? $ticket->code : '')),
                            null,
                            false
                        );
                    }
                }
                break;
            case 'update_status':
                $ticket_statuses = $this->SupportManagerTickets->getStatuses();

                if (!empty($ticket_ids) && !empty($this->post['status'])
                    && array_key_exists($this->post['status'], $ticket_statuses)) {
                    // Update the select tickets to the new status
                    $this->SupportManagerTickets->editMultiple(
                        (array)$ticket_ids,
                        ['by_staff_id' => $this->staff_id, 'status' => $this->post['status']]
                    );

                    if (($errors = $this->SupportManagerTickets->errors())) {
                        // Error
                        $this->flashMessage('error', $errors, null, false);
                    } else {
                        // Success
                        $this->flashMessage(
                            'message',
                            Language::_('AdminTickets.!success.ticket_update_status', true),
                            null,
                            false
                        );
                    }
                }
                break;
            case 'reassign':
                if (!empty($ticket_ids) && !empty($this->post['client_id'])) {
                    // Update the select tickets to the new client
                    $this->SupportManagerTickets->reassignTickets(
                        [
                            'ticket_ids' => (array)$ticket_ids,
                            'client_id' => $this->post['client_id'],
                            'staff_id' => $this->Session->read('blesta_staff_id')
                        ]
                    );

                    if (($errors = $this->SupportManagerTickets->errors())) {
                        // Error
                        $this->flashMessage('error', $errors, null, false);
                    } else {
                        // Success
                        $this->flashMessage(
                            'message',
                            Language::_('AdminTickets.!success.ticket_reassign', true),
                            null,
                            false
                        );
                    }
                }
        }

        // Maintain previous status view
        $ticket_status = isset($this->post['current_status'])
            ? $this->post['current_status']
            : '';
        $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/index/' . $ticket_status);
    }

    /**
     * Permanently deletes the given tickets
     */
    public function delete()
    {
        $ticket_ids = $this->getPostTicketIDs($this->post);
        $this->SupportManagerTickets->delete($ticket_ids);

        $this->flashMessage(
            'message',
            Language::_('AdminTickets.!success.ticket_delete', true),
            null,
            false
        );

        $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
    }

    /**
     * Gets a list of ticket IDs from post data
     *
     * @param array $post The post data
     * @return array The ticket IDs
     */
    private function getPostTicketIDs(array $post)
    {
        // Remove the 'all' ticket option if selected
        $ticket_ids = (isset($post['tickets']) ? (array)$post['tickets'] : []);
        if (isset($ticket_ids[0]) && $ticket_ids[0] == 'all') {
            unset($ticket_ids[0]);
            $ticket_ids = array_values($ticket_ids);
        }

        return $ticket_ids;
    }

    /**
     * AJAX Fetches the given replies for the given ticket as markdown-quoted text
     */
    public function getQuotedReplies()
    {
        if (!$this->isAjax() || !isset($this->get[0]) || empty($this->post['reply_ids']) ||
            !($ticket = $this->SupportManagerTickets->get($this->get[0], true, ['reply'], $this->staff_id))) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $reply_ids = explode(',', $this->post['reply_ids']);

        $replies = '';
        $i = 0;
        foreach ($ticket->replies as $reply) {
            if (in_array($reply->id, $reply_ids)) {
                $replies .= ($i++ > 0 ? "\n" : '') . "\n" . preg_replace("/\r\n|\r|\n/", "\n>", '>' . $reply->details);
            }
        }

        $this->outputAsJson($replies);
        return false;
    }

    /**
     * Performs a given action
     */
    public function replyAction()
    {
        // Ensure valid ticket was given
        if (!isset($this->get[0])
            || !($ticket = $this->SupportManagerTickets->get($this->get[0], true, null, $this->staff_id))
            || empty($this->post['action'])
            || !in_array($this->post['action'], array_keys($this->getReplyActions()))
        ) {
            $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
        }

        // Perform the requested action
        switch ($this->post['action']) {
            case 'split':
                // Split the ticket
                $replies = (isset($this->post['replies']) ? $this->post['replies'] : []);
                $new_ticket_id = $this->SupportManagerTickets->split($ticket->id, $replies);

                if (($errors = $this->SupportManagerTickets->errors())) {
                    // Error
                    $this->flashMessage('error', $errors, null, false);
                    $this->redirect(
                        $this->base_uri . 'plugin/support_manager/admin_tickets/reply/' . $ticket->id . '/'
                    );
                } else {
                    // Success
                    $new_ticket = $this->SupportManagerTickets->get($new_ticket_id, false);
                    $this->flashMessage(
                        'message',
                        Language::_(
                            'AdminTickets.!success.ticket_split',
                            true,
                            $ticket->code,
                            ($new_ticket ? $new_ticket->code : '')
                        ),
                        null,
                        false
                    );
                    $this->redirect(
                        $this->base_uri . 'plugin/support_manager/admin_tickets/reply/' . $new_ticket->id . '/'
                    );
                }
                break;
        }

        $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/reply/' . $ticket->id . '/');
    }

    /**
     * Streams an attachment to view
     */
    public function getAttachment()
    {
        // Ensure a valid attachment was given
        if (!isset($this->get[0]) || !($attachment = $this->SupportManagerTickets->getAttachment($this->get[0]))) {
            exit();
        }

        // Ensure the staff member can view the attachment
        $staff = $this->SupportManagerStaff->get($this->staff_id, $this->company_id);
        if (!in_array($attachment->department_id, $this->Form->collapseObjectArray($staff->departments, 'id', 'id'))) {
            exit();
        }

        $this->components(['Download']);

        $this->Download->downloadFile($attachment->file_name, $attachment->name);
        return false;
    }

    /**
     * AJAX Fetches a list of department priorities and the default priority
     */
    public function getPriorities()
    {
        $please_select = ['' => Language::_('AppController.select.please', true)];
        $vars = [
            'default_priority' => '',
            'priorities' => $please_select
        ];

        // Return nothing if the department not given
        if (!isset($this->get[0])) {
            $this->outputAsJson($vars);
            return false;
        }

        // Ensure a valid department was given
        $this->uses(['SupportManager.SupportManagerDepartments']);
        if (!$this->isAjax() || !($department = $this->SupportManagerDepartments->get($this->get[0])) ||
            $department->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        // Set priorities
        $vars['default_priority'] = $department->default_priority;
        $vars['priorities'] = $please_select + $this->SupportManagerDepartments->getPriorities($department->id);

        $this->outputAsJson($vars);
        return false;
    }

    /**
     * AJAX request to fetch all department that belong to a given support department
     */
    public function getDepartmentStaff()
    {
        $this->uses(['Services', 'ModuleManager']);
        $department_staff = ['' => Language::_('AdminTickets.text.unassigned', true)];

        if (!isset($this->get[0])) {
            $this->outputAsJson($department_staff);
            return false;
        }

        // Ensure a valid department was given
        $this->uses(['SupportManager.SupportManagerDepartments']);
        if (!$this->isAjax() || !($department = $this->SupportManagerDepartments->get($this->get[0])) ||
            $department->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        $department_staff += $this->Form->collapseObjectArray(
            $this->SupportManagerStaff->getAll($this->company_id, $department->id, false),
            ['first_name', 'last_name'],
            'id',
            ' '
        );

        // Get department fields
        $department_fields = $this->renderDepartmentCustomFields($department->fields, $this->post['custom_fields'] ?? []);

        // Fetch client related services
        $service_ids = [];
        if (isset($this->get['client_id'])) {
            $service_ids = $this->fetchClientServicesIds($this->get['client_id']);
        }

        $this->outputAsJson([
            'department_staff' => $department_staff,
            'department_fields' => $department_fields,
            'enable_related_services' => $department->enable_related_services,
            'service_ids' => $service_ids
        ]);
        return false;
    }

    /**
     * AJAX retrieves the partial that lists categories and responses
     */
    public function getResponseListing()
    {
        $this->uses(['SupportManager.SupportManagerResponses']);
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
     * AJAX retrieves the predefined response text for a specific response
     */
    public function getResponse()
    {
        $this->uses(['SupportManager.SupportManagerResponses']);
        // Ensure a valid response was given
        $response = (isset($this->get[0]) ? $this->SupportManagerResponses->get($this->get[0]) : null);
        if ($response && $response->company_id != $this->company_id) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        echo json_encode($response->details);
        return false;
    }

    /**
     * AJAX searches predefined responses across all categories
     */
    public function searchResponses()
    {
        $this->uses(['SupportManager.SupportManagerResponses']);

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
     * AJAX retrieves the predefined response text for a specific response
     */
    public function checkReplies()
    {
        $this->uses(['SupportManager.SupportManagerTickets']);
        // Ensure a valid ticket is given
        if (!$this->isAjax()
            || !isset($this->get[0])
            || !($ticket = $this->SupportManagerTickets->get($this->get[0], true, null, $this->staff_id))
        ) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }


        // Get AI settings for reply display
        $this->uses(['SupportManager.SupportManagerSettings']);
        $ai_settings_raw = $this->SupportManagerSettings->getSettings($this->company_id);
        $ai_settings = [];
        foreach ($ai_settings_raw as $setting) {
            $ai_settings[$setting->key] = $setting->value;
        }

        echo json_encode([
            'reply_count' => count($ticket->replies),
            'ticket_replies' => $this->partial(
                'admin_tickets_replies',
                [
                    'ticket' => $ticket,
                    'ticket_actions' => $this->getReplyActions(),
                    'thumbnails_per_row' => Configure::get('SupportManager.thumbnails_per_row'),
                    'ai_settings' => $ai_settings
                ]
            )
        ]);
        return false;
    }

    /**
     * AJAX endpoint for uploading inline images from the markdown editor.
     * Returns JSON with temp_id and alt text on success.
     */
    public function uploadImage()
    {
        // Require AJAX request
        if (!$this->isAjax()) {
            header($this->server_protocol . ' 401 Unauthorized');
            exit();
        }

        // Validate the uploaded file exists
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $this->outputAsJson([
                'error' => Language::_('SupportManagerTickets.!error.inline_image.upload', true)
            ]);
            return false;
        }

        $result = $this->SupportManagerTickets->uploadInlineImageTemp($_FILES['image']);

        if (($errors = $this->SupportManagerTickets->errors())) {
            // Return the first error message
            $error_message = '';
            foreach ($errors as $field_errors) {
                foreach ($field_errors as $message) {
                    $error_message = $message;
                    break 2;
                }
            }
            $this->outputAsJson(['error' => $error_message]);
            return false;
        }

        // Include preview URL so the editor can display the image
        $result['preview_url'] = $this->base_uri
            . 'plugin/support_manager/admin_tickets/getinlineimagetemp/'
            . $result['temp_id'] . '/';

        $this->outputAsJson($result);
        return false;
    }

    /**
     * AJAX Serves a temp inline image for editor preview
     */
    public function getInlineImageTemp()
    {
        $temp_id = $this->get[0] ?? null;
        if (!$temp_id) {
            header($this->server_protocol . ' 404 Not Found');
            exit();
        }

        $file = $this->SupportManagerTickets->getInlineImageTempFile($temp_id);
        if (!$file) {
            header($this->server_protocol . ' 404 Not Found');
            exit();
        }

        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . filesize($file['file_path']));
        header('Cache-Control: private, max-age=300');
        readfile($file['file_path']);
        exit();
    }

    /**
     * Gets a list of input fields for filtering tickets
     *
     * @param array $vars A list of submitted inputs that act as defaults for filter fields including:
     *
     *  - ticket_number The (partial) ticket number on which to filter tickets
     *  - priority The priority on which to filter tickets
     *  - department_id The department on which to filter tickets
     *  - summary The (partial) summary of the ticket line on which to filter tickets
     *  - staff_id The assigned staff member on which to filter tickets
     *  - last_reply The elapsed time from the last reply on which to filter tickets
     * @return InputFields An object representing the list of filter input field
     */
    private function getFilters(array $vars)
    {
        $this->uses(['SupportManager.SupportManagerDepartments']);

        $filters = new InputFields();

        // Set ticket number filter
        $ticket_number = $filters->label(
            Language::_('AdminTickets.index.field_ticket_number', true),
            'ticket_number'
        );
        $ticket_number->attach(
            $filters->fieldText(
                'filters[ticket_number]',
                isset($vars['ticket_number']) ? $vars['ticket_number'] : null,
                [
                    'id' => 'ticket_number',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('AdminTickets.index.field_ticket_number', true)
                ]
            )
        );
        $filters->setField($ticket_number);

        // Set priority filter
        $priorities = $this->SupportManagerTickets->getPriorities();
        $priority = $filters->label(
            Language::_('AdminTickets.index.field_priority', true),
            'priority'
        );
        $priority->attach(
            $filters->fieldSelect(
                'filters[priority]',
                ['' => Language::_('AdminTickets.index.any', true)] + $priorities,
                isset($vars['priority']) ? $vars['priority'] : null,
                ['id' => 'priority', 'class' => 'form-control']
            )
        );
        $filters->setField($priority);

        // Set department filter
        $departments = $this->Form->collapseObjectArray(
            $this->SupportManagerDepartments->getAll($this->company_id),
            'name',
            'id'
        );
        $department_id = $filters->label(
            Language::_('AdminTickets.index.field_department_id', true),
            'department_id'
        );
        $department_id->attach(
            $filters->fieldSelect(
                'filters[department_id]',
                ['' => Language::_('AdminTickets.index.any', true)] + $departments,
                isset($vars['department_id']) ? $vars['department_id'] : null,
                ['id' => 'department_id', 'class' => 'form-control']
            )
        );
        $filters->setField($department_id);

        // Set summary filter
        $summary = $filters->label(
            Language::_('AdminTickets.index.field_summary', true),
            'summary'
        );
        $summary->attach(
            $filters->fieldText(
                'filters[summary]',
                isset($vars['summary']) ? $vars['summary'] : null,
                [
                    'id' => 'summary',
                    'class' => 'form-control stretch',
                    'placeholder' => Language::_('AdminTickets.index.field_summary', true)
                ]
            )
        );
        $filters->setField($summary);

        // Set assigned staff filter
        $staff = [];
        foreach ($this->SupportManagerStaff->getAll($this->company_id) as $staff_member) {
            $staff[$staff_member->id] = $staff_member->first_name . ' ' . $staff_member->last_name;
        }

        $assigned_staff = $filters->label(
            Language::_('AdminTickets.index.field_assigned_staff', true),
            'assigned_staff'
        );
        $assigned_staff->attach(
            $filters->fieldSelect(
                'filters[assigned_staff]',
                ['' => Language::_('AdminTickets.index.any', true)] + $staff,
                isset($vars['assigned_staff']) ? $vars['assigned_staff'] : null,
                ['id' => 'assigned_staff', 'class' => 'form-control']
            )
        );
        $filters->setField($assigned_staff);

        // Set last reply filter
        $time_options = [];
        $minutes = [15, 30];
        $hours = [1, 6, 12, 24, 72];

        foreach ($minutes as $minute) {
            $time_options[$minute] = Language::_('AdminTickets.index.minutes', true, $minute);
        }

        foreach ($hours as $hour) {
            $time_options[$hour * 60] = Language::_('AdminTickets.index.' . ($hour == 1 ? 'hour' : 'hours'), true, $hour);
        }

        $last_reply = $filters->label(
            Language::_('AdminTickets.index.field_last_reply', true),
            'last_reply'
        );
        $last_reply->attach(
            $filters->fieldSelect(
                'filters[last_reply]',
                ['' => Language::_('AdminTickets.index.any', true)] + $time_options,
                isset($vars['last_reply']) ? $vars['last_reply'] : null,
                ['id' => 'last_reply', 'class' => 'form-control']
            )
        );
        $filters->setField($last_reply);

        return $filters;
    }

    /**
     * Formats the custom fields of a department in to a InputFields object
     *
     * @param array $fields An array containing the department custom fields
     * @param array $vars An array containing the default values for each of the custom fields
     * @return InputFields An InputFields object represeting the department custom fields
     */
    private function formatDepartmentCustomFields(array $fields, array $vars = [])
    {
        // Get custom fields
        $input_fields = new InputFields();

        foreach ($fields as $field) {
            // Skip field if visible only to clients
            if ($field->visibility == 'client_only') {
                continue;
            }

            // Set field label
            $label = Language::_($field->label, true);
            if (empty($label)) {
                $label = $field->label;
            }
            $field->label = $label;

            // Set field description
            $description = Language::_($field->description, true);
            if (empty($description)) {
                $description = $field->description;
            }
            $field->description = $description;

            // Set field as required
            $required = [];
            if ($field->required == 1) {
                $required = ['required' => 'required'];
            }

            // Build text and textarea field
            switch ($field->type) {
                case 'text':
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);
                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldText(
                                'custom_fields[' . $field->id . ']',
                                $vars[$field->id] ?? '',
                                array_merge(['id' => 'custom_fields_' . $field->id], $required)
                            )
                        )
                    );

                    // Add tooltip
                    if (!empty($field->description)) {
                        $tooltip = $input_fields->tooltip($field->description);
                        $custom_fields->attach($tooltip);
                    }
                    break;
                case 'quantity':
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);

                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldText(
                                'custom_fields[' . $field->id . ']',
                                $vars[$field->id] ?? $field->min ?? 0,
                                array_merge([
                                    'id' => 'custom_fields_' . $field->id,
                                    'data-type' => 'quantity',
                                    'data-min' => $field->min ?? 0,
                                    'data-max' => $field->max ?? 0,
                                    'data-step' => $field->step ?? 1
                                ], $required),
                                $input_fields->label($field->description)
                            )
                        )
                    );

                    break;
                case 'textarea':
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);
                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldTextarea(
                                'custom_fields[' . $field->id . ']',
                                $vars[$field->id] ?? '',
                                array_merge(['id' => 'custom_fields_' . $field->id], $required)
                            )
                        )
                    );

                    // Add tooltip
                    if (!empty($field->description)) {
                        $tooltip = $input_fields->tooltip($field->description);
                        $custom_fields->attach($tooltip);
                    }
                    break;
                case 'password':
                    // We use a text field instead of a password field as staff should be able to see the password
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);
                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldText(
                                'custom_fields[' . $field->id . ']',
                                $vars[$field->id] ?? '',
                                array_merge(['id' => 'custom_fields_' . $field->id], $required)
                            )
                        )
                    );

                    // Add tooltip
                    if (!empty($field->description)) {
                        $tooltip = $input_fields->tooltip($field->description);
                        $custom_fields->attach($tooltip);
                    }
                    break;
                case 'select':
                    $options = $this->formatCustomFieldOptions($field->options ?? []);
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);

                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldSelect(
                                'custom_fields[' . $field->id . ']',
                                $options['options'] ?? [],
                                $vars[$field->id] ?? $options['default'] ?? null,
                                array_merge(['id' => 'custom_fields_' . $field->id], $required),
                                []
                            )
                        )
                    );

                    // Add tooltip
                    if (!empty($field->description)) {
                        $tooltip = $input_fields->tooltip($field->description);
                        $custom_fields->attach($tooltip);
                    }
                    break;
                case 'radio':
                    $options = $this->formatCustomFieldOptions($field->options ?? []);
                    $custom_fields = $input_fields->label($field->label, 'custom_fields_' . $field->id);

                    // Add tooltip
                    if (!empty($field->description)) {
                        $tooltip = $input_fields->tooltip($field->description);
                        $custom_fields->attach($tooltip);
                    }

                    foreach ($options['options'] ?? [] as $value => $name) {
                        $custom_fields->attach(
                            $input_fields->fieldRadio(
                                'custom_fields[' . $field->id . ']',
                                $value,
                                ($vars[$field->id] ?? $options['default'] ?? null) == $value,
                                array_merge(['id' => 'custom_fields_' . $field->id . '_' . $value], $required),
                                $input_fields->label($name, 'custom_fields_' . $field->id . '_' . $value)
                            )
                        );
                    }

                    $input_fields->setField($custom_fields);
                    break;
                case 'checkbox':
                case 'emergency':
                    $custom_fields = $input_fields->label($field->description);
                    $input_fields->setField(
                        $custom_fields->attach(
                            $input_fields->fieldCheckbox(
                                'custom_fields[' . $field->id . ']',
                                '1',
                                ($vars[$field->id] ?? '0') == '1',
                                array_merge(['id' => 'custom_fields_' . $field->id], $required),
                                $input_fields->label($field->label, 'custom_fields_' . $field->id)
                            )
                        )
                    );
                    break;
            }
        }

        return $input_fields;
    }

    /**
     * Renders department custom fields using the styled partial template
     *
     * @param array $fields An array of field objects from the department
     * @param array $vars The field values
     * @return string The rendered HTML for the custom fields
     */
    private function renderDepartmentCustomFields(array $fields, array $vars = [])
    {
        return $this->partial('admin_tickets_custom_fields', [
            'fields' => $fields,
            'vars' => $vars
        ]);
    }

    /**
     * Formats the custom field options to be used in a "select" field
     *
     * @param array $options An array containing the custom field options
     * @return array An array containing the formatted custom field options
     */
    private function formatCustomFieldOptions(array $options = [])
    {
        $formatted_options = [];
        if (!empty($options['name'])) {
            foreach ($options['name'] as $i => $value) {
                $formatted_options['options'][$options['value'][$i]] = $options['name'][$i];

                if ($options['default'][$i] == '1') {
                    $formatted_options['default'] = $options['value'][$i];
                }
            }
        }

        return $formatted_options;
    }

    /**
     * AJAX endpoint to retrieve existing AI response for a ticket
     *
     * Fetches the most recent AI-generated response analysis for a ticket if one exists.
     * Returns response content, confidence score, concerns, and metadata.
     *
     * @return void Outputs JSON response with AI analysis data or error
     */
    public function ajaxGetAiResponse()
    {
        $this->uses(['SupportManager.SupportManagerSettings', 'SupportManager.SupportManagerAiResponseAnalyses']);

        // Only accept GET requests via AJAX
        if (!$this->isAjax()) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ajax_only', true)
            ]);
            return;
        }

        $ticket_id = $this->get['ticket_id'] ?? null;

        // Validate ticket exists and staff has access
        if (!$ticket_id || !($ticket = $this->SupportManagerTickets->get($ticket_id, true, null, $this->staff_id))) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ticket_invalid', true)
            ]);
            return false;
        }

        // Check if AI is enabled
        $ai_enabled = $this->SupportManagerSettings->getSetting('sm_ai_enabled', $this->company_id);
        if (empty($ai_enabled->value) || $ai_enabled->value !== 'true') {
            $this->outputAsJson([
                'success' => false,
                'has_response' => false
            ]);
            return false;
        }

        // Get latest AI response analysis for ticket (status: pending)
        $ai_analysis = $this->SupportManagerAiResponseAnalyses->getByTicketId($ticket_id, 'pending');

        if (!$ai_analysis) {
            $this->outputAsJson([
                'success' => true,
                'has_response' => false
            ]);
            return false;
        }

        // Calculate time ago
        $generated_time = strtotime($ai_analysis->created_at);
        $time_diff = time() - $generated_time;
        if ($time_diff < 60) {
            $time_ago = Language::_('AdminTickets.reply.text_just_now', true);
        } elseif ($time_diff < 3600) {
            $minutes = floor($time_diff / 60);
            $time_ago = Language::_('AdminTickets.reply.text_minutes_ago', true, $minutes);
        } elseif ($time_diff < 86400) {
            $hours = floor($time_diff / 3600);
            $time_ago = Language::_('AdminTickets.reply.text_hours_ago', true, $hours);
        } else {
            $days = floor($time_diff / 86400);
            $time_ago = Language::_('AdminTickets.reply.text_days_ago', true, $days);
        }

        // Get response data from new model structure
        $concerns = [];
        if ($ai_analysis->concerns) {
            $decoded_concerns = json_decode($ai_analysis->concerns, true);
            $concerns = is_array($decoded_concerns) ? $decoded_concerns : [];
        }

        // Sanitize concerns to prevent XSS
        $concerns = $concerns ?? [];
        $safe_concerns = array_map(function($concern) {
            return $this->Html->safe($concern);
        }, $concerns);

        // Sanitize notes and content to prevent XSS
        $safe_notes = $this->sanitizeAiContent($ai_analysis->internal_notes ?? '');
        $safe_content = $this->sanitizeAiContent($ai_analysis->response_text ?? '');

        // Return response data
        $this->outputAsJson([
            'success' => true,
            'has_response' => true,
            'response_id' => $ai_analysis->id,
            'analysis_id' => $ai_analysis->id,
            'notes' => $safe_notes,
            'content' => $safe_content,
            'response_confidence' => $ai_analysis->confidence ?? null,
            'suggested_tools' => [],  // No tools in manual workflow
            'confidence' => (int)($ai_analysis->confidence ?? 0),
            'reasoning' => $ai_analysis->confidence_reasoning ?? '',
            'concerns' => $safe_concerns,
            'model' => $ai_analysis->model ?? 'unknown',
            'generated_at' => date('c', $generated_time),
            'time_ago' => $time_ago
        ]);
        return false;
    }

    /**
     * AJAX endpoint to generate a new AI response for a ticket
     *
     * Triggers AI analysis and response generation for the specified ticket.
     * Returns the generated response content, confidence score, and metadata.
     * If regenerating, rejects the previous response first.
     *
     * @return void Outputs JSON response with generated AI analysis or error
     */
    public function ajaxGenerateAiResponse()
    {
        $this->uses(['SupportManager.SupportManagerSettings']);

        // Only accept POST requests
        if (!$this->isAjax() || empty($this->post)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ajax_only', true)
            ]);
            return;
        }

        $ticket_id = $this->post['ticket_id'] ?? null;
        $regenerate = $this->post['regenerate'] ?? false;

        // Validate ticket exists and staff has access
        if (!$ticket_id || !($ticket = $this->SupportManagerTickets->get($ticket_id, true, null, $this->staff_id))) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ticket_invalid', true)
            ]);
            return false;
        }

        // Check if AI is enabled
        $ai_enabled = $this->SupportManagerSettings->getSetting('sm_ai_enabled', $this->company_id);
        if (empty($ai_enabled->value) || $ai_enabled->value !== 'true') {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ai_not_enabled', true)
            ]);
            return false;
        }

        try {
            // If regenerating, delete old pending responses
            if ($regenerate) {
                $this->uses(['SupportManager.SupportManagerAiResponseAnalyses']);
                $old_analysis = $this->SupportManagerAiResponseAnalyses->getByTicketId($ticket_id, 'pending');
                if ($old_analysis) {
                    $this->SupportManagerAiResponseAnalyses->delete($old_analysis->id);
                }
            }

            // Get all unprocessed client replies for this ticket
            $unprocessed_replies = $this->SupportManagerTickets->getUnprocessedClientReplies($ticket_id);

            // For manual generation, allow generation even without unprocessed replies
            // In this case, use full conversation context without marking specific replies as "new"
            $reply_ids = [];
            if (!empty($unprocessed_replies)) {
                $reply_ids = array_map(function($r) { return $r->id; }, $unprocessed_replies);
            }

            // Load AI helper
            Loader::load(PLUGINDIR . 'support_manager' . DS . 'lib' . DS . 'support_manager_ai_helper.php');
            $ai_helper = new SupportManagerAiHelper($this->company_id);

            // Generate response (manual workflow)
            // If reply_ids is empty, generates based on full conversation context
            // If reply_ids provided, focuses on those specific new replies
            $analysis_id = $ai_helper->generateResponseForReplies(
                $reply_ids,
                $ticket,
                $unprocessed_replies,
                ['save_to_db' => true]
            );

            if (!$analysis_id) {
                throw new Exception('Failed to generate AI analysis');
            }

            // Fetch the analysis from database
            $analysis = $ai_helper->SupportManagerAiResponseAnalyses->get($analysis_id);

            if (!$analysis) {
                throw new Exception('Failed to retrieve AI analysis');
            }

            // Parse concerns JSON
            $concerns = [];
            if ($analysis->concerns) {
                $decoded_concerns = json_decode($analysis->concerns, true);
                $concerns = is_array($decoded_concerns) ? $decoded_concerns : [];
            }

            // Calculate time ago
            $time_ago = Language::_('AdminTickets.reply.text_just_now', true);

            // Sanitize concerns to prevent XSS
            $safe_concerns = array_map(function($concern) {
                return $this->Html->safe($concern);
            }, $concerns);

            // Sanitize notes and content to prevent XSS
            $safe_notes = $this->sanitizeAiContent($analysis->internal_notes ?? '');
            $safe_content = $this->sanitizeAiContent($analysis->response_text ?? '');

            // Format response for JSON
            $response = [
                'success' => true,
                'response_id' => $analysis_id,
                'analysis_id' => $analysis_id,
                'notes' => $safe_notes,
                'content' => $safe_content,
                'response_confidence' => $analysis->confidence ?? null,
                'requires_review' => true,
                'suggested_tools' => [],  // No tools for manual workflow
                'confidence' => $analysis->confidence ?? 50,
                'reasoning' => $analysis->confidence_reasoning ?? '',
                'concerns' => $safe_concerns,
                'model' => $analysis->model ?? 'unknown',
                'prompt_tokens' => $analysis->prompt_tokens ?? 0,
                'completion_tokens' => $analysis->completion_tokens ?? 0,
                'cost' => number_format($analysis->cost ?? 0, 4),
                'generated_at' => date('c', strtotime($analysis->created_at)),
                'time_ago' => $time_ago
            ];

            $this->outputAsJson($response);
        } catch (Exception $e) {
            // Generate unique error ID for log correlation
            $error_id = uniqid('ai_err_');

            // Log full error details with error ID
            $this->logger->error("AI generation failed [{$error_id}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());

            // Return generic error with reference code
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ai_generation_failed', true),
                'error_code' => $error_id
            ]);
        }
        return false;
    }

    /**
     * AJAX endpoint to generate or retrieve an AI summary for a specific reply
     *
     * @return void Outputs JSON response with summary or error
     */
    public function ajaxSummarizeReply()
    {
        $this->uses(['SupportManager.SupportManagerSettings']);

        // Only accept POST requests via AJAX
        if (!$this->isAjax() || empty($this->post)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ajax_only', true)
            ]);
            return;
        }

        $reply_id = $this->post['reply_id'] ?? null;

        // Load Record component for direct DB queries
        Loader::loadComponents($this, ['Record']);

        // Fetch the reply including type and staff_id for authorization checks
        $reply = $this->Record->select(['ticket_id', 'staff_id', 'type', 'details', 'ai_summary'])
            ->from('support_replies')
            ->where('id', '=', $reply_id)
            ->fetch();

        if (!$reply) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.reply_not_found', true)
            ]);
            return;
        }

        // Validate ticket access before returning any data
        if (!($ticket = $this->SupportManagerTickets->get($reply->ticket_id, true, null, $this->staff_id))) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ticket_invalid', true)
            ]);
            return;
        }

        // Only allow summarizing client replies (not staff replies or internal notes)
        if ($reply->type !== 'reply' || ($reply->staff_id !== null && $reply->staff_id !== '')) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.reply_not_found', true)
            ]);
            return;
        }

        // Return cached summary if it exists
        if (!empty($reply->ai_summary)) {
            $this->outputAsJson([
                'success' => true,
                'summary_html' => $this->TextParser->encode('markdown', $reply->ai_summary)
            ]);
            return;
        }

        // Check if AI is enabled
        $ai_enabled = $this->SupportManagerSettings->getSetting('sm_ai_enabled', $this->company_id);
        if (empty($ai_enabled->value) || $ai_enabled->value !== 'true') {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ai_not_enabled', true)
            ]);
            return;
        }

        try {
            // Load AI helper and generate summary
            Loader::load(PLUGINDIR . 'support_manager' . DS . 'lib' . DS . 'support_manager_ai_helper.php');
            $ai_helper = new SupportManagerAiHelper($this->company_id);

            $summary = $ai_helper->generateSummary($reply->details);

            if ($summary === false) {
                throw new Exception('Summary generation returned false');
            }

            // Persist the summary
            $this->Record->where('id', '=', $reply_id)
                ->update('support_replies', ['ai_summary' => $summary]);

            $this->outputAsJson([
                'success' => true,
                'summary_html' => $this->TextParser->encode('markdown', $summary)
            ]);
        } catch (Exception $e) {
            $error_id = uniqid('ai_sum_');
            $this->logger->error("AI summary failed [{$error_id}]: " . $e->getMessage());

            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.summary_failed', true),
                'error_code' => $error_id
            ]);
        }
        return false;
    }

    /**
     * AJAX endpoint to validate an AI response
     *
     * Verifies that a staff member has access to use an AI-generated response.
     * The response will only be marked as "used" when the actual reply is submitted.
     * This prevents premature usage tracking for responses that are inserted but never sent.
     * Requires staff to have access to the ticket associated with the analysis.
     *
     * @return void Outputs JSON response with success status or error
     */
    public function ajaxUseAiResponse()
    {
        $this->uses(['SupportManager.SupportManagerAiResponseAnalyses']);

        if (!$this->isAjax() || empty($this->post)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ajax_only', true)
            ]);
            return;
        }

        $analysis_id = $this->post['response_id'] ?? $this->post['analysis_id'] ?? null;

        if (!$analysis_id) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.analysis_invalid', true)
            ]);
            return;
        }

        // Get the response analysis to find its associated ticket
        $analysis = $this->SupportManagerAiResponseAnalyses->get($analysis_id);
        if (!$analysis) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.analysis_invalid', true)
            ]);
            return;
        }

        // Verify staff has access to the ticket associated with this analysis
        if (!($ticket = $this->SupportManagerTickets->get($analysis->ticket_id, true, null, $this->staff_id))) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.no_access', true)
            ]);
            return;
        }

        // Don't mark as used yet - will be marked when the reply is actually submitted
        // Return the analysis_id so the frontend can include it in the reply form
        $this->outputAsJson([
            'success' => true,
            'analysis_id' => $analysis_id
        ]);
    }

    /**
     * AJAX endpoint to reject an AI response
     *
     * Records that a staff member has rejected an AI-generated response.
     * This is used for tracking AI effectiveness and improving future responses.
     * Requires staff to have access to the ticket associated with the analysis.
     *
     * @return void Outputs JSON response with success status or error
     */
    public function ajaxRejectAiResponse()
    {
        $this->uses(['SupportManager.SupportManagerAiResponseAnalyses']);

        if (!$this->isAjax() || empty($this->post)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.ajax_only', true)
            ]);
            return;
        }

        $analysis_id = $this->post['response_id'] ?? $this->post['analysis_id'] ?? null;

        if (!$analysis_id) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.analysis_invalid', true)
            ]);
            return;
        }

        // Get the response analysis to find its associated ticket
        $analysis = $this->SupportManagerAiResponseAnalyses->get($analysis_id);
        if (!$analysis) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.analysis_invalid', true)
            ]);
            return;
        }

        // Verify staff has access to the ticket associated with this analysis
        if (!($ticket = $this->SupportManagerTickets->get($analysis->ticket_id, true, null, $this->staff_id))) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('AdminTickets.!error.no_access', true)
            ]);
            return;
        }

        // Delete the rejected response (in new architecture, rejection = deletion for regeneration)
        $this->SupportManagerAiResponseAnalyses->delete($analysis_id);

        $this->outputAsJson([
            'success' => true
        ]);
        return false; // Prevent auto-render
    }

    /**
     * Sanitizes AI-generated HTML content to prevent XSS attacks
     *
     * Uses HTMLPurifier to allow safe HTML formatting (paragraphs, lists, emphasis)
     * while removing dangerous elements and attributes (scripts, iframes, event handlers).
     *
     * @param string $content The AI-generated content to sanitize
     * @return string The sanitized HTML content
     */
    private function sanitizeAiContent($content)
    {
        if (empty($content)) {
            return '';
        }

        // Load HTMLPurifier (composer autoloads it, but explicit include for safety)
        if (!class_exists('HTMLPurifier_Config')) {
            require_once VENDORDIR . 'ezyang' . DS . 'htmlpurifier' . DS . 'library' . DS . 'HTMLPurifier.auto.php';
        }

        // Configure HTMLPurifier for AI-generated content
        $config = HTMLPurifier_Config::createDefault();

        // Set cache directory
        $cacheDir = CACHEDIR . 'htmlpurifier';
        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        // Allow safe HTML elements that AI might generate
        $config->set('HTML.Allowed', 'p,br,strong,em,b,i,u,ul,ol,li,a[href],blockquote,code,pre');

        // Convert newlines to <br> for plain text content
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.Linkify', false);

        // Security settings
        $config->set('HTML.Nofollow', true);  // Add rel="nofollow" to links
        $config->set('URI.DisableExternalResources', true);  // Block external images, etc.
        $config->set('Attr.EnableID', false);  // Disable ID attributes
        $config->set('Attr.AllowedClasses', []);  // No CSS classes allowed

        // Create purifier and sanitize
        $purifier = new HTMLPurifier($config);
        $sanitized = $purifier->purify($content);

        return trim($sanitized);
    }
}
