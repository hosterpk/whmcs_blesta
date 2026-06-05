<?php

namespace Blesta\App\Models;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Blesta\App\AppModel;
use AppController;
use Configure;
use Language;
use Loader;
use stdClass;

/**
 * Notifications management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Notifications extends AppModel
{
    /**
     * Initialize Notifications
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['notifications']);
    }

    /**
     * Adds a new notification
     *
     * @param array $vars An array of notification data including:
     *  - `action_id` (int) The notification action ID
     *  - `user_id` (int) The user ID to receive the notification
     *  - `icon` (string|null) The notification icon (optional)
     *  - `color` (string|null) The notification color (optional)
     *  - `type` (string) The notification type: `info`, `success`, `warning`, or `danger` (optional, default: info)
     *  - `title` (string) The notification title
     *  - `message` (string) The notification message
     *  - `url` (string|null) The notification URL (optional)
     *  - `read` (int) Read status: 0 (unread) or 1 (read) (optional, default: 0)
     *  - `date_added` (string) Date notification was created in Y-m-d H:i:s format (optional, defaults to current time)
     * @return int|void The notification ID, void on error
     */
    public function add(array $vars): ?int
    {
        // Set default date_added if not provided
        if (!isset($vars['date_added'])) {
            $vars['date_added'] = $this->Date->format('Y-m-d H:i:s');
        }

        // Set default read status if not provided
        if (!isset($vars['read'])) {
            $vars['read'] = 0;
        }

        // Set default type if not provided
        if (!isset($vars['type'])) {
            $vars['type'] = 'info';
        }

        // Format title
        if (isset($vars['title'])) {
            $vars['title'] = Str::squish($vars['title']);
        }

        $rules = $this->getRules($vars);
        $this->Input->setRules($rules);

        if ($this->Input->validates($vars)) {
            $fields = ['action_id', 'user_id', 'icon', 'color', 'type', 'title', 'message', 'url', 'read', 'date_added'];
            $this->Record->insert('notifications', $vars, $fields);

            return $this->Record->lastInsertId();
        }

        return null;
    }

    /**
     * Fetches a notification by ID
     *
     * @param int $notification_id The notification ID
     * @return stdClass|false A stdClass object representing the notification, false if not found
     */
    public function get(int $notification_id): mixed
    {
        return $this->Record->select()
            ->from('notifications')
            ->where('id', '=', $notification_id)
            ->fetch();
    }

    /**
     * Updates a notification
     *
     * @param int $notification_id The notification ID
     * @param array $vars An array of notification data to update:
     *  - `title` (string) The notification title (optional)
     *  - `message` (string) The notification message (optional)
     *  - `read` (int) Read status: 0 or 1 (optional)
     *  - `icon` (string) The notification icon (optional)
     *  - `color` (string) The notification color (optional)
     *  - `type` (string) The notification type (optional)
     *  - `url` (string) The notification URL (optional)
     * @return int|void The notification ID, void on error
     */
    public function edit(int $notification_id, array $vars): ?int
    {
        $rules = $this->getRules($vars, true);
        $this->Input->setRules($rules);

        if ($this->Input->validates($vars)) {
            $fields = ['title', 'message', 'icon', 'color', 'type', 'url', 'read'];
            $this->Record->where('id', '=', $notification_id)
                ->update('notifications', $vars, $fields);

            return $notification_id;
        }

        return null;
    }

    /**
     * Permanently deletes a notification
     *
     * @param int $notification_id The notification ID to delete
     */
    public function delete(int $notification_id): void
    {
        $this->Record->from('notifications')
            ->where('id', '=', $notification_id)
            ->delete();
    }

    /**
     * Fetches a paginated list of notifications
     *
     * @param array $filters Filters to apply:
     *  - `user_id` (int) Filter by user ID (optional)
     *  - `read` (int) Filter by read status: 0 or 1 (optional)
     *  - `type` (string) Filter by type: `system` or `plugin` (optional)
     * @param int $page The page number of results to fetch (optional, default: 1)
     * @param array $order_by Sorting order as key/value pairs (optional, default: ['date_added' => 'DESC'])
     * @return array Array of stdClass objects representing notifications
     */
    public function getList(array $filters = [], int $page = 1, array $order_by = ['date_added' => 'DESC']): array
    {
        $this->Record->select()
            ->from('notifications');

        // Apply filters
        if (isset($filters['user_id'])) {
            $this->Record->where('user_id', '=', $filters['user_id']);
        }

        if (isset($filters['read'])) {
            $this->Record->where('read', '=', $filters['read']);
        }

        if (isset($filters['type'])) {
            $this->Record->where('type', '=', $filters['type']);
        }

        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Returns the total number of notifications for the given filters
     *
     * @param array $filters Filters to apply (see getList for available filters)
     * @return int The total number of notifications
     */
    public function getListCount(array $filters = []): int
    {
        $this->Record->select()
            ->from('notifications');

        // Apply filters (same as getList)
        if (isset($filters['user_id'])) {
            $this->Record->where('user_id', '=', $filters['user_id']);
        }

        if (isset($filters['read'])) {
            $this->Record->where('read', '=', $filters['read']);
        }

        if (isset($filters['type'])) {
            $this->Record->where('type', '=', $filters['type']);
        }

        return $this->Record->numResults();
    }

    /**
     * Fetches all notifications matching the given filters
     *
     * @param array $filters Filters to apply (see getList for available filters)
     * @param array $order_by Sorting order (optional, default: ['date_added' => 'DESC'])
     * @return array Array of stdClass objects representing notifications
     */
    public function getAll(array $filters = [], array $order_by = ['date_added' => 'DESC']): array
    {
        $this->Record->select()
            ->from('notifications');

        // Apply filters
        if (isset($filters['user_id'])) {
            $this->Record->where('user_id', '=', $filters['user_id']);
        }

        if (isset($filters['read'])) {
            $this->Record->where('read', '=', $filters['read']);
        }

        if (isset($filters['type'])) {
            $this->Record->where('type', '=', $filters['type']);
        }

        return $this->Record->order($order_by)->fetchAll();
    }

    /**
     * Marks a notification as read
     *
     * @param int $notification_id The notification ID
     */
    public function markAsRead(int $notification_id): void
    {
        $this->edit($notification_id, ['read' => 1]);
    }

    /**
     * Marks a notification as unread
     *
     * @param int $notification_id The notification ID
     */
    public function markAsUnread(int $notification_id): void
    {
        $this->edit($notification_id, ['read' => 0]);
    }

    /**
     * Returns the count of unread notifications for a user
     *
     * @param int $user_id The user ID
     * @param string|null $type Filter by notification type (optional)
     * @return int The number of unread notifications
     */
    public function getUnreadCount(int $user_id, ?string $type = null): int
    {
        $filters = [
            'user_id' => $user_id,
            'read' => 0
        ];

        if ($type !== null) {
            $filters['type'] = $type;
        }

        return $this->getListCount($filters);
    }

    /**
     * Sends a new notification
     *
     * @param array $vars An array of notification data including:
     *  - `action_id` (int) The notification action ID
     *  - `user_id` (int) The user ID to receive the notification
     *  - `icon` (string|null) The notification icon (optional)
     *  - `color` (string|null) The notification color (optional)
     *  - `type` (string) The notification type: `info`, `success`, `warning`, or `danger` (optional, default: info)
     *  - `title` (string) The notification title
     *  - `message` (string) The notification message
     *  - `url` (string|null) The notification URL (optional)
     *  - `read` (int) Read status: 0 (unread) or 1 (read) (optional, default: 0)
     *  - `date_added` (string) Date notification was created in Y-m-d H:i:s format (optional, defaults to current time)
     * @return bool True if the notification was sent successfully, false otherwise
     */
    public function send(string $action, ?string $target, array $vars): bool
    {
        // Load necessary models
        Loader::loadModels($this, ['Clients', 'Staff']);

        // Fetch notification action
        $action = $this->getAction($action, $target);

        $vars['action_id'] = $action->id;
        if (empty($vars['title'])) {
            $vars['title'] = Str::of($vars['message'] ?? '')->limit(45)->toString();
        }

        if (empty($vars['url'])) {
            $vars['url'] = AppController::baseUrl()
                . ($action->target == 'staff' ? AppController::adminUri() : AppController::clientUri());
        }

        // Send to all users if no user_id is provided
        $send = false;
        if (!isset($vars['user_id']) || empty($vars['user_id'])) {
            if ($target == 'staff') {
                $staff = $this->Staff->getAllByNotificationAction(
                    $action->action,
                    Configure::get('Blesta.company_id'),
                    'active'
                );

                foreach ($staff as $staff_member) {
                    $vars['user_id'] = $staff_member->user_id;
                    $notification = $this->add($vars);

                    if (!$send && !is_null($notification)) {
                        $send = true;
                    }
                }
            }

            if ($target == 'client') {
                $clients = $this->Clients->getAllByNotificationAction(
                    $action->action,
                    Configure::get('Blesta.company_id'),
                    'active'
                );

                foreach ($clients as $client) {
                    $vars['user_id'] = $client->user_id;
                    $notification = $this->add($vars);

                    if (!$send && !is_null($notification)) {
                        $send = true;
                    }
                }
            }
        } else {
            $notification = $this->add($vars);
            if (is_null($notification)) {
                return false;
            }
        }

        return $send;
    }

    /**
     * Fetches a notification action by action name
     *
     * @param string $action The action name to filter by
     * @param string|null $target The target type to filter by (optional)
     * @param string|null $type The action type to filter by (optional)
     * @param string|null $dir The plugin or module directory to filter by (optional)
     * @param int|null $company_id The company ID to filter by, if null uses current company (optional)
     * @return stdClass|false A stdClass object representing the notification action, false if not found
     */
    public function getAction(string $action, ?string $target, ?string $type = null, ?string $dir = null, ?int $company_id = null): mixed
    {
        if (is_null($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $this->Record->select()
            ->from('notification_actions')
            ->where('action', '=', $action)
            ->where('company_id', '=', $company_id)
            ->where('target', '=', $target);

        if ($type !== null) {
            $this->Record->where('type', '=', $type);
        }

        if ($dir !== null) {
            $this->Record->where('dir', '=', $dir);
        }

        return $this->Record->fetch();
    }

    /**
     * Fetches all notification actions
     *
     * @param string|null $type The action type: 'system', 'plugin', 'module', or null for all (optional)
     * @param string|null $dir The plugin or module directory to filter by (optional)
     * @param int|null $company_id The company ID to filter by, if null uses current company (optional)
     * @param array $order_by Sorting order as key/value pairs (optional, default: ['action' => 'ASC'])
     * @return array Array of stdClass objects representing notification actions
     */
    public function getActions(
        ?string $target,
        ?string $type = null,
        ?string $dir = null,
        ?int $company_id = null,
        array $order_by = ['action' => 'ASC']
    ): array {
        if ($company_id === null) {
            $company_id = Configure::get('Blesta.company_id');
        }

        $this->Record->select()
            ->from('notification_actions')
            ->where('company_id', '=', $company_id)
            ->where('target', '=', $target);

        if ($type !== null) {
            $this->Record->where('type', '=', $type);
        }

        if ($dir !== null) {
            $this->Record->where('dir', '=', $dir);
        }

        return $this->Record->order($order_by)->fetchAll();
    }

    /**
     * Checks if a notification is enabled for a specific user
     *
     * @param string $action The notification action name
     * @param int $user_id The user ID
     * @param ?int $company_id The company ID to check the notification action for (optional)
     * @return bool True if the notification is enabled, false otherwise
     */
    public function enabled(string $action, int $user_id, ?int $company_id = null): bool
    {
        // Load necessary models
        Loader::loadModels($this, ['Clients', 'Staff']);

        // Load format helper for settings
        $this->ArrayHelper = $this->DataStructure->create('Array');

        // Set company id
        if (is_null($company_id)) {
            $company_id = Configure::get('Blesta.company_id');
        }

        // Check user type
        $user_type = null;
        $staff = $this->Staff->getByUserId($user_id, $company_id);
        if (!empty($staff)) {
            $user_type = 'staff';
        }

        $client = $this->Clients->getByUserId($user_id, $company_id);
        if (!empty($client)) {
            $user_type = 'client';
        }

        // Check if the action is enabled for the user's group
        if ($user_type === 'staff') {
            // Fetch staff member by user id
            $staff = $this->Staff->getByUserId($user_id, $company_id);
            if (!$staff) {
                return false;
            }

            // Check if the action is enabled for the staff group
            $group_notification = false;
            foreach ($staff->groups as $group) {
                $group_enabled = $this->Staff->validateNotificationActionExists($action, $group->id);
                if (!$group_notification && $group_enabled) {
                    $group_notification = true;
                }
            }

            if (!$group_notification) {
                return false;
            }

            // Check if the notification is enabled by the staff member
            $notifications = Arr::keyBy($staff->notifications, 'action');
            if (!array_key_exists($action, $notifications)) {
                return false;
            }

            return true;
        } elseif ($user_type === 'client') {
            // Fetch client by user id
            $client = $this->Clients->getByUserId($user_id, $company_id);
            if (!$client) {
                return false;
            }

            // Check if the action is enabled for the client group
            $group_notification = $this->Clients->validateNotificationActionExists($action, $client->client_group_id);
            if (!$group_notification) {
                return false;
            }

            // Check if the notification is enabled by the client
            $notifications = Arr::keyBy($client->notifications, 'action');
            if (!array_key_exists($action, $notifications)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Returns all available notification types
     *
     * @return array Array of notification types as key/value pairs
     */
    public function getTypes(): array
    {
        return [
            'info' => $this->_('Notifications.getTypes.info'),
            'success' => $this->_('Notifications.getTypes.success'),
            'warning' => $this->_('Notifications.getTypes.warning'),
            'danger' => $this->_('Notifications.getTypes.danger')
        ];
    }

    /**
     * Returns validation rules for adding/editing notifications
     *
     * @param array $vars Input data to validate
     * @param bool $edit True if this is an edit operation, false otherwise
     * @return array Validation rules
     */
    private function getRules(array $vars = [], bool $edit = false): array
    {
        $rules = [
            'action_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'notification_actions'],
                    'message' => $this->_('Notifications.!error.action_id.exists')
                ]
            ],
            'user_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'users'],
                    'message' => $this->_('Notifications.!error.user_id.exists')
                ],
                'enabled' => [
                    'rule' => function ($user_id) use ($vars) {
                        if (!isset($vars['action_id'])) {
                            return false;
                        }

                        // Get the action name from action_id
                        $action = $this->Record->select('action')
                            ->from('notification_actions')
                            ->where('id', '=', $vars['action_id'])
                            ->fetch();

                        if (!$action) {
                            return false;
                        }

                        return $this->enabled($action->action, $user_id);
                    },
                    'message' => $this->_('Notifications.!error.user_id.enabled')
                ]
            ],
            'title' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('Notifications.!error.title.empty')
                ]
            ],
            'message' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('Notifications.!error.message.empty')
                ]
            ],
            'read' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => $this->_('Notifications.!error.read.format')
                ]
            ],
            'type' => [
                'format' => [
                    'if_set' => true,
                    'rule' => ['in_array', array_keys($this->getTypes())],
                    'message' => $this->_('Notifications.!error.type.format')
                ]
            ],
            'color' => [
                'format' => [
                    'if_set' => true,
                    'rule' => function ($color) {
                        Loader::loadHelpers($this, ['Color']);
                        return $this->Color->isValidColor($color);
                    },
                    'message' => $this->_('Notifications.!error.color.format')
                ]
            ]
        ];

        // For edit operations, make required fields optional
        if ($edit) {
            $rules['action_id']['exists']['if_set'] = true;
            $rules['user_id']['exists']['if_set'] = true;
            $rules['user_id']['enabled']['if_set'] = true;
            $rules['title']['empty']['if_set'] = true;
            $rules['message']['empty']['if_set'] = true;
        }

        return $rules;
    }
}
