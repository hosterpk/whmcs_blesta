<?php

/**
 * Admin My Info
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminMyinfo extends AppController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        parent::preAction();

        // Require login
        $this->requireLogin();

        $this->uses(['Staff']);

        Language::loadLang(['admin_myinfo']);
    }

    /**
     * Update this staff members information
     */
    public function index()
    {
        $this->uses(['Users', 'Languages', 'Companies', 'PluginManager']);
        $this->components(['SettingsCollection', 'Upload']);

        // Load the Base2n class from vendors
        $base32 = new Base2n(5, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', false, true, true);

        // Get staff and user IDs
        $user_id = $this->Session->read('blesta_id');
        $staff_id = $this->Session->read('blesta_staff_id');

        // Get user
        $user = $this->Users->get($user_id);

        $vars = [];

        // Update the users' info
        if (!empty($this->post)) {
            $errors = [];

            // Remove new password if not given
            if (empty($this->post['new_password'])) {
                unset($this->post['new_password'], $this->post['confirm_password']);
            }

            // Handle avatar removal
            if (isset($this->post['remove_avatar']) && $this->post['remove_avatar'] == '1') {
                // Set avatar to null to remove from database
                $this->post['avatar'] = null;
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

            // Begin transaction
            $this->Users->begin();

            // Whitelist user fields to prevent mass assignment
            $user_vars = array_intersect_key(
                $this->post,
                array_flip(
                    [
                        'username',
                        'new_password',
                        'confirm_password',
                        'current_password',
                        'recovery_email',
                        'two_factor_mode',
                        'two_factor_key',
                        'two_factor_pin',
                        'otp',
                        'avatar'
                    ]
                )
            );
            $this->Users->edit($user_id, $user_vars, true);
            $user_errors = $this->Users->errors();

            // Whitelist staff fields to prevent mass assignment
            $staff_vars = array_intersect_key(
                $this->post,
                array_flip(
                    [
                        'first_name',
                        'last_name',
                        'email',
                        'email_mobile',
                        'number_mobile',
                        'status'
                    ]
                )
            );
            $this->Staff->edit($staff_id, $staff_vars);
            $staff_errors = $this->Staff->errors();

            if (isset($this->post['settings']['language'])) {
                $this->Staff->setSetting($staff_id, 'language', $this->post['settings']['language']);
            }

            $errors = array_merge(
                (!empty($upload_errors) ? $upload_errors : []),
                (!empty($user_errors) ? $user_errors : []),
                (!empty($staff_errors) ? $staff_errors : [])
            );

            if (!empty($errors)) {
                // Error, rollback
                $this->Users->rollBack();

                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Handle avatar removal
                if (isset($this->post['remove_avatar']) && $this->post['remove_avatar'] == '1') {
                    // Get current avatar path and delete the file
                    if (!empty($user->avatar) && file_exists($user->avatar)) {
                        @unlink($user->avatar);
                    }
                }
                // Success, commit
                $this->Users->commit();

                $this->flashMessage('message', Language::_('AdminMyinfo.!success.updated', true));
                $this->redirect($this->base_uri);
            }
        }

        // Set my info
        if (empty($vars)) {
            $staff = $this->Staff->get($staff_id, $this->company_id);
            $staff->settings = $this->Form->collapseObjectArray($staff->settings, 'value', 'key');

            $vars = (object) array_merge((array) $user, (array) $staff);
        }

        // Generate random two-factor key
        if (!isset($vars->two_factor_key) || $vars->two_factor_key == '') {
            $vars->two_factor_key = $this->Users->systemHash(mt_rand() . md5(mt_rand()), null, 'sha1');
        }

        $vars->two_factor_key_base32 = $base32->encode(pack('H*', $vars->two_factor_key));

        $this->set('two_factor_modes', $this->Users->getOtpModes());
        $this->set('vars', $vars);
        $this->set('user', $user);
        $this->set('link_tabs', $this->getTabNames());
        $this->set(
            'languages',
            $this->Form->collapseObjectArray(
                $this->Languages->getAll(Configure::get('Blesta.company_id')),
                'name',
                'code'
            )
        );

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Updates assigned BCC notices
     */
    public function notices()
    {
        $staff_id = $this->Session->read('blesta_staff_id');
        $staff = $this->Staff->get($staff_id, $this->company_id);

        if (!empty($this->post)) {
            $notices = (!empty($this->post['notices']) ? $this->post['notices'] : []);
            $this->Staff->addNotices($staff_id, $staff->group->id, $notices);

            if (($errors = $this->Staff->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminMyinfo.!success.notices_updated', true));
                $this->redirect($this->base_uri . 'myinfo/');
            }
        }

        // Set initial value of notices
        if (empty($vars)) {
            // Set notices
            $staff_notices = $this->Staff->getNotices($staff_id, $staff->group->id);
            $notices = [];
            foreach ($staff_notices as $notice) {
                $notices[] = $notice->action;
            }

            $vars = (object) ['notices' => $notices];
        }

        $this->set('vars', $vars);
        $this->set('link_tabs', $this->getTabNames());
        $this->set('bcc_notices', $this->getGroupNotices($staff->group->id, 'bcc'));
        $this->set('subscription_notices', $this->getGroupNotices($staff->group->id, 'to'));

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Updates assigned notifications
     */
    public function notifications()
    {
        $staff_id = $this->Session->read('blesta_staff_id');
        $staff = $this->Staff->get($staff_id, $this->company_id);

        if (!empty($this->post)) {
            $notices = (!empty($this->post['notifications']) ? $this->post['notifications'] : []);
            $this->Staff->addNotifications($staff_id, $staff->group->id, $notices);

            if (($errors = $this->Staff->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminMyinfo.!success.notifications_updated', true));
                $this->redirect($this->base_uri . 'myinfo/');
            }
        }

        // Set initial value of notices
        if (empty($vars)) {
            // Set notifications
            $staff_notifications = $this->Staff->getNotifications($staff_id, $staff->group->id);
            $notifications = [];
            foreach ($staff_notifications as $notification) {
                $notifications[] = $notification->action;
            }

            $vars = (object) ['notifications' => $notifications];
        }

        $this->set('vars', $vars);
        $this->set('link_tabs', $this->getTabNames());
        $this->set('notifications', $this->getGroupNotifications($staff->group->id));

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Retrieves a list of staff group notices
     * @see AdminMyinfo::notices()
     *
     * @param int $staff_group_id The ID of the staff group this staff member belongs to
     * @param string $type The notice type of the email groups to fetch
     * @return array A list of available staff group notices
     */
    private function getGroupNotices($staff_group_id, $type)
    {
        $this->uses(['StaffGroups']);
        // Get staff group notices
        $group_notices = $this->StaffGroups->getNotices($staff_group_id);

        if (!empty($group_notices)) {
            // Get all client email groups
            $this->uses(['EmailGroups']);
            Language::loadLang('admin_company_emails');

            $email_groups = array_merge(
                $this->EmailGroups->getAllByNoticeType($type),
                $this->EmailGroups->getAllByNoticeType($type, null, false)
            );

            // Create a list of email groups by action
            $groups = [];
            foreach ($email_groups as &$email_group) {
                // Load plugin language
                if ($email_group->plugin_dir !== null) {
                    Language::loadLang(
                        'admin_company_emails',
                        null,
                        PLUGINDIR . $email_group->plugin_dir . DS . 'language' . DS
                    );
                }

                $email_group->lang = Language::_(
                    'AdminCompanyEmails.templates.' . $email_group->action . '_name',
                    true
                );
                $email_group->lang_description = Language::_(
                    'AdminCompanyEmails.templates.' . $email_group->action . '_desc',
                    true
                );

                // Set only those notices available to this staff group
                foreach ($group_notices as $notice) {
                    if ($notice->action == $email_group->action) {
                        $groups[] = $email_group;
                        break;
                    }
                }
            }

            return $groups;
        }

        return [];
    }

    /**
     * Retrieves and processes staff group notifications
     * Fetches notifications for a given staff group, loads the notification language
     * definitions, and translates notification names and descriptions.
     *
     * @param int $staff_group_id The ID of the staff group to fetch notifications for
     * @param string|null $type The action type: 'system', 'plugin', 'module', or null for all (optional)
     * @return array An array of processed notification objects with translated labels
     */
    private function getGroupNotifications($staff_group_id, ?string $type = null)
    {
        $this->uses(['StaffGroups', 'Notifications']);

        // Get staff group notifications
        $group_notifications = $this->StaffGroups->getNotifications($staff_group_id);
        if (!empty($group_notifications)) {
            Language::loadLang('notifications');

            $groups = [];
            foreach ($group_notifications as &$notification) {
                $action = $this->Notifications->getAction($notification->action, 'staff');
                if (!is_null($type) && $action->type !== $type) {
                    continue;
                }

                // Load language file from plugin or module
                if (!empty($notification->dir)) {
                    $dir = match ($notification->type) {
                        'plugin' => PLUGINDIR . $notification->dir . DS . 'language' . DS,
                        'module' => COMPONENTDIR . 'modules' . DS . $notification->dir . DS . 'language' . DS,
                        default => ROOTWEBDIR . 'language' . DS
                    };

                    Language::loadLang('notifications', null, $dir);
                }

                // Set translated labels
                $notification->lang = Language::_(
                    'Notifications.notification.' . $notification->action . '_name',
                    true
                );
                $notification->lang_description = Language::_(
                    'Notifications.notification.' . $notification->action . '_desc',
                    true
                );

                $groups[] = $notification;
            }

            return $groups;
        }

        return [];
    }

    /**
     * Icon bar settings page
     */
    public function iconbar()
    {
        $this->uses(['Navigation']);

        $staff_id = $this->Session->read('blesta_staff_id');

        if (!empty($this->post)) {
            if (isset($this->post['reset'])) {
                // Reset to default
                $this->Staff->unsetSetting($staff_id, 'iconBar_' . $this->company_id);
                $this->Staff->unsetSetting($staff_id, 'showAiChatbot_' . $this->company_id);
                $this->clearIconBarCache($staff_id);
                $this->flashMessage('message', Language::_('AdminMyinfo.!success.iconbar_reset', true));
                $this->redirect($this->base_uri . 'myinfo/iconbar/');
            }

            // Save icon bar settings
            if (!empty($this->post['icon_bar_items'])) {
                $items = json_decode($this->post['icon_bar_items'], true);

                if (is_array($items)) {
                    // Sanitize items
                    $clean_items = [];
                    foreach ($items as $item) {
                        $clean = [
                            'id' => $item['id'] ?? '',
                            'icon' => preg_replace('/[^a-zA-Z0-9\- ]/', '', $item['icon'] ?? 'bi-gear'),
                            'enabled' => !empty($item['enabled'])
                        ];

                        if (!empty($item['custom'])) {
                            $clean['custom'] = true;
                            $clean['name'] = $item['name'] ?? '';

                            // Enforce safe URL schemes — only allow http(s) and relative paths
                            $url = trim($item['url'] ?? '#');
                            if (
                                $url === '#'
                                || str_starts_with($url, '/')
                                || str_starts_with($url, 'http://')
                                || str_starts_with($url, 'https://')
                            ) {
                                $clean['url'] = $url;
                            } else {
                                $clean['url'] = '#';
                            }
                        }

                        $clean_items[] = $clean;
                    }

                    $this->Staff->setSetting(
                        $staff_id,
                        'iconBar_' . $this->company_id,
                        base64_encode(json_encode($clean_items))
                    );
                    // Save AI chatbot icon visibility preference
                    $this->Staff->setSetting(
                        $staff_id,
                        'showAiChatbot_' . $this->company_id,
                        !empty($this->post['show_ai_chatbot']) ? 'true' : 'false'
                    );

                    $this->clearIconBarCache($staff_id);
                    $this->flashMessage('message', Language::_('AdminMyinfo.!success.iconbar_updated', true));
                    $this->redirect($this->base_uri . 'myinfo/iconbar/');
                }
            }
        }

        // Build the list of available nav items
        $this->Navigation->baseUri('public', $this->public_uri)
            ->baseUri('client', $this->client_uri)
            ->baseUri('admin', $this->admin_uri);
        $nav = $this->Navigation->getPrimary($this->admin_uri);

        // Check permissions - filter nav items the same way setNav does
        if (!isset($this->StaffGroups)) {
            $this->uses(['StaffGroups']);
        }
        $group = $this->StaffGroups->getStaffGroupByStaff($staff_id, $this->company_id);
        if ($group) {
            foreach ($nav as $uri => $data) {
                if (isset($data['route'])) {
                    $route = $data['route'];
                    if (preg_match('/' . $route['controller'] . '/i', $this->controller)) {
                        $route['controller'] = $this->controller;
                    }
                } else {
                    $route = Router::routesTo($uri);
                }
                if (isset($route['plugin'])) {
                    $route['controller'] = $route['plugin'] . '.' . $route['controller'];
                }
                if (!$this->authorized($route['controller'], '*', $group)) {
                    unset($nav[$uri]);
                }
            }
        }

        // Load saved settings
        $setting = $this->Staff->getSetting($staff_id, 'iconBar_' . $this->company_id);
        $saved_items = null;
        if ($setting && !empty($setting->value)) {
            $saved_items = json_decode(base64_decode($setting->value), true);
        }

        if ($saved_items === null) {
            // Default: only Dashboard enabled (its nav key is the admin base URI)
            $dashboard_url = $this->admin_uri;
            $items = [];
            foreach ($nav as $url => $data) {
                $items[] = [
                    'id' => $url,
                    'icon' => $data['icon'] ?? 'bi bi-gear',
                    'name' => $data['name'] ?? '',
                    'enabled' => ($url === $dashboard_url)
                ];
            }
        } else {
            // Merge saved settings with current nav
            $items = [];

            // First, add saved items that still exist
            foreach ($saved_items as $saved) {
                if (!empty($saved['custom'])) {
                    $items[] = $saved;
                } elseif (isset($nav[$saved['id']])) {
                    $saved['name'] = $nav[$saved['id']]['name'] ?? $saved['name'] ?? '';
                    $items[] = $saved;
                }
            }

            // Add new nav items not in saved settings
            $saved_ids = array_column($saved_items, 'id');
            foreach ($nav as $url => $data) {
                if (!in_array($url, $saved_ids)) {
                    $items[] = [
                        'id' => $url,
                        'icon' => $data['icon'] ?? 'bi bi-gear',
                        'name' => $data['name'] ?? '',
                        'enabled' => false
                    ];
                }
            }
        }

        $this->set('items', $items);

        // AI chatbot icon visibility preference (default: shown)
        $pref = $this->Staff->getSetting($staff_id, 'showAiChatbot_' . $this->company_id);
        $this->set('show_ai_chatbot', (!$pref || $pref->value !== 'false'));

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * Retrieves a list of link tabs for use in templates
     *
     * @return array A list of tab names
     */
    private function getTabNames()
    {
        return [
            ['name' => Language::_('AdminMyinfo.gettabnames.text_index', true), 'uri' => 'index'],
            ['name' => Language::_('AdminMyinfo.gettabnames.text_notices', true), 'uri' => 'notices'],
            ['name' => Language::_('AdminMyinfo.gettabnames.text_notifications', true), 'uri' => 'notifications']
        ];
    }
}
