<?php

/**
 * Admin Company Look And Feel Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminCompanyLookandfeel extends AdminController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Companies', 'PluginManager', 'Plugins', 'Themes', 'Actions']);
        $this->components(['SettingsCollection', 'Upload']);
        $this->helpers(['Color']);
    }

    /**
     * Index
     */
    public function index()
    {
        $this->redirect($this->base_uri . 'settings/company/lookandfeel/template/');
    }

    /**
     * Set template
     */
    public function template()
    {
        $client_view_dirs = $this->Companies->getViewDirs('client');
        foreach ($client_view_dirs as $dir => $info) {
            $client_view_dirs[$dir] = $info->name;
        }

        $admin_view_dirs = $this->Companies->getViewDirs('admin');
        foreach ($admin_view_dirs as $dir => $info) {
            $admin_view_dirs[$dir] = $info->name;
        }

        if (!isset($this->post['client_view_override']) && !empty($this->post)) {
            $this->post['client_view_override'] = 'false';
        }

        if (!empty($this->post)) {
            $this->Companies->setSettings($this->company_id, $this->post, [
                'client_view_dir',
                'admin_view_dir',
                'client_view_override'
            ]);

            $this->setMessage('message', Language::_('AdminCompanyLookandfeel.!success.template_updated', true));
        }

        $this->set('client_view_dirs', $client_view_dirs);
        $this->set('admin_view_dirs', $admin_view_dirs);
        $this->set('vars', $this->SettingsCollection->fetchSettings($this->Companies, $this->company_id));
    }

    /**
     * Customize layout
     */
    public function layout()
    {
        // Fetch theme colors
        $admin_colors = [];
        $theme_id = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'theme_admin');
        if (!empty($theme_id['value'])) {
            $theme = $this->Themes->get($theme_id['value']);
            $admin_colors = $theme->colors;
        }

        $client_colors = [];
        $theme_id = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'theme_client');
        if (!empty($theme_id['value'])) {
            $theme = $this->Themes->get($theme_id['value']);
            $client_colors = $theme->colors;
        }

        // Fetch cards
        $plugin_cards = $this->PluginManager->getCards($this->company_id);
        $cards = [];
        foreach ($plugin_cards as $key => $plugin_card) {
            $plugin_card->card_id = $plugin_card->plugin_dir . '_' . Loader::fromCamelCase($plugin_card->callback[1]);
            $cards[$plugin_card->card_id] = $plugin_card;
        }

        // Fetch widgets
        $home_widgets = $this->Actions->getAll([
            'location' => 'widget_client_home',
            'company_id' => $this->company_id
        ]);

        $widgets = [];
        foreach ($home_widgets as $key => $home_widget) {
            $home_widget->widget_id = $home_widget->location . '_' . str_replace(
                ['/', '?', '=', '&', '#'],
                '_',
                trim(preg_replace('/\?.*/', '', $home_widget->url), '/')
            );
            $home_widget->uri = ($home_widget->location == 'widget_client_home' ? $this->client_uri : $this->admin_uri)
                . $home_widget->url;

            // Get widget preview
            $home_widget->html_preview = $this->getWidgetPreview($home_widget);

            $widgets[$home_widget->widget_id] = $home_widget;
        }

        if (!empty($this->post)) {
            // Update cards
            if (isset($this->post['cards'])) {
                $this->PluginManager->begin();

                foreach ($cards as $card) {
                    if (array_key_exists($card->card_id, (array)$this->post['cards']))
                    {
                        $background_type =
                            $this->post['cards'][$card->card_id]['background_type'] ?? $card->background_type;
                        $vars = [
                            'enabled' => $this->post['cards'][$card->card_id]['enabled'] ?? '0',
                            'text_color' => $this->post['cards'][$card->card_id]['text_color'] ?? $card->text_color,
                            'background' => $this->post['cards'][$card->card_id]['background_' . $background_type] ??
                                $card->background,
                            'background_type' => $this->post['cards'][$card->card_id]['background_type'] ??
                                $card->background_type,
                        ];
                        $card->enabled = $vars['enabled'] ?? 0;
                        $card->background = $vars['background'];
                        $card->background_type = $vars['background_type'];

                        $this->PluginManager->editCard(
                            $card->plugin_id,
                            $card->callback,
                            $card->level,
                            $vars
                        );
                    }
                }
            }

            // Save cards order
            $cards_order = [];
            if (isset($this->post['cards'])) {
                foreach ($this->post['cards'] as $card_id => $card) {
                    $cards_order[] = $card_id;
                }
            }

            $cards_order = base64_encode(serialize($cards_order));
            $this->Companies->setSetting($this->company_id, 'layout_cards_order', $cards_order);

            // Update widgets
            if (isset($this->post['widgets'])) {
                foreach ($this->post['widgets'] as $widget_id => $widget) {
                    Loader::loadComponents($this, ['Record']);
                    $this->Record->where('id', '=', $widget['id'])->update('actions', ['enabled' => ($widget['enabled'] ?? 0)]);
                }
            }

            // Save widgets order
            $widgets_order = [];
            if (isset($this->post['widgets'])) {
                foreach ($this->post['widgets'] as $widget_id => $widget) {
                    $widgets_order[] = $widget_id;
                }
            }

            $widgets_order = base64_encode(serialize($widgets_order));
            $this->Companies->setSetting($this->company_id, 'layout_widgets_order', $widgets_order);

            if (($errors = $this->PluginManager->errors())) {
                $this->PluginManager->rollback();
                $this->setMessage('error', $errors);
            } else {
                $this->PluginManager->commit();
                $this->flashMessage(
                    'message',
                    Language::_('AdminCompanyLookandfeel.!success.layout_updated', true)
                );
                $this->redirect($this->base_uri . 'settings/company/lookandfeel/layout/');
            }
        }

        // Order cards
        $cards_order = $this->Companies->getSetting($this->company_id, 'layout_cards_order');
        $cards_order = isset($cards_order->value) ? safe_unserialize(base64_decode($cards_order->value)) : [];

        $admin_cards = array_merge(array_flip($cards_order), $cards);
        foreach ($admin_cards as $key => $value) {
            if (!is_object($value) || (isset($value->level) && $value->level !== 'staff')) {
                unset($admin_cards[$key]);
            }
        }

        $client_cards = array_merge(array_flip($cards_order), $cards);
        foreach ($client_cards as $key => $value) {
            if (!is_object($value) || (isset($value->level) && $value->level !== 'client')) {
                unset($client_cards[$key]);
            }
        }

        // Order widgets
        $widgets_order = $this->Companies->getSetting($this->company_id, 'layout_widgets_order');
        $widgets_order = isset($widgets_order->value) ? safe_unserialize(base64_decode($widgets_order->value)) : [];

        $admin_widgets = array_merge(array_flip($widgets_order), $widgets);
        foreach ($admin_widgets as $key => $value) {
            if (!is_object($value) || (isset($value->location) && $value->location !== 'widget_staff_client')) {
                unset($admin_widgets[$key]);
            }
        }

        $client_widgets = array_merge(array_flip($widgets_order), $widgets);
        foreach ($client_widgets as $key => $value) {
            if (!is_object($value) || (isset($value->location) && $value->location !== 'widget_client_home')) {
                unset($client_widgets[$key]);
            }
        }

        // Get theme types
        $theme_types = $this->Themes->getTypes();
        if (is_array($theme_types)) {
            $theme_types = array_reverse($theme_types);
        }

        $this->set(
            'layout_admin',
            $this->partial(
                'admin_company_lookandfeel_layout_tab',
                [
                    'cards' => $admin_cards,
                    'widgets' => $admin_widgets,
                    'colors' => $admin_colors,
                    'theme_type' => 'admin'
                ]
            )
        );
        $this->set(
            'layout_client',
            $this->partial(
                'admin_company_lookandfeel_layout_tab',
                [
                    'cards' => $client_cards,
                    'widgets' => $client_widgets,
                    'colors' => $client_colors,
                    'theme_type' => 'client'
                ]
            )
        );

        $this->set('theme_types', $theme_types);
    }

    /**
     * Renders a HTML preview of the widget
     *
     * @param stdClass $widget An object representing the widget
     * @return string A string containing the HTML code of the preview, null on error
     */
    private function getWidgetPreview($widget)
    {
        // Determine the view type
        $view_type = (
            $widget->location == 'widget_client_home'
                ? 'client'
                : ($widget->location == 'widget_staff_client' ? 'admin' : null)
        );

        if (is_null($view_type)) {
            return null;
        }

        // If the widget belongs to a plugin, fetch it
        $plugin = null;
        if (!is_null($widget->plugin_id)) {
            Loader::loadModels($this, ['PluginManager']);
            $plugin = $this->PluginManager->get($widget->plugin_id, false, false);
        }

        // Load the main language file required by this widget
        $lang_file = Loader::fromCamelCase(explode('.', $widget->name, 2)[0] ?? null);
        $lang_dir = PLUGINDIR . ($plugin->dir ?? '') . DS . 'language' . DS;
        if (is_null($plugin)) {
            $lang_dir = null;
        }
        Language::loadLang($lang_file, null, $lang_dir);

        // Generate a fake preview of the widget
        if (is_null($plugin)) {
            $client_template = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'client_view_dir');
            $admin_template = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'admin_view_dir');
            $view_file = $view_type . '_'. trim(preg_replace('/\?.*/', '', $widget->url), '/');
            $view_dir = $view_type . DS . ($view_type == 'client' ? $client_template['value'] : $admin_template['value']);
        } else {
            $plugin_parts = explode('/', trim(
                str_replace(
                    ['index', 'plugin'],
                    '',
                    preg_replace('/\?.*/', '', $widget->url)
                ),
                '/'
            ), 2);
            $view_file = str_replace(['/', '?', '=', '&', '#'], '_', $plugin_parts[1]);
            $view_dir = Loader::toCamelCase($plugin->dir) . '.default';
        }
        Language::loadLang($view_file, null, $lang_dir);

        try {
            Loader::loadModels($this, ['Clients', 'Services']);
            $view_params = [
                'filters' => new Blesta\Core\Util\Input\Fields\InputFields(),
                'client' => $this->Clients->getAll()[0] ?? null,
                'status_count' => [
                    'open' => 0,
                    'closed' => 0,
                    'active' => 0,
                    'pending' => 0,
                    'suspended' => 0,
                    'canceled' => 0,
                    'approved' => 0,
                    'declined' => 0,
                    'voided' => 0
                ],
                'is_ajax' => true,
                'render_section' => true
            ];

            $view = new View($view_file, $view_dir);
            $view->base_uri = $this->base_uri;
            $view->Pagination = $this->getFromContainer('pagination');

            Loader::loadHelpers($view, ['WidgetClient', 'Widget']);
            Loader::loadComponents($view, ['Html']);

            foreach ($view_params as $key => $view_param) {
                $view->set($key, $view_param);
            }

            $widget->html_preview = $view->fetch();

            // Remove forms
            $widget->html_preview = preg_replace(
                '/<form\b[^>]*>(.*?)<\/form>/is',
                '',
                $widget->html_preview
            );

            // Remove JS code
            $widget->html_preview = preg_replace(
                '/<script\b[^>]*>(.*?)<\/script>/is',
                '',
                $widget->html_preview
            );

            // Format HTML
            $this->DOMDocument = new DOMDocument();
            $this->DOMDocument->substituteEntities = false;
            $this->DOMDocument->loadHTML(
                mb_convert_encoding($widget->html_preview, 'html-entities', 'utf-8')
            );
            $widget->html_preview = $this->DOMDocument->saveHTML();
        } catch (Throwable $e) {
            $widget->html_preview = null;
        }

        return $widget->html_preview;
    }

    /**
     * Navigation customization interface
     */
    public function navigation()
    {
        $this->uses(['Staff', 'StaffGroups']);
        $this->ArrayHelper = $this->DataStructure->create('Array');
        $location = isset($this->get[0]) ? $this->get[0] : 'nav_staff';

        if (!empty($this->post)) {
            // Delete existing navigation items for this company and location
            $this->Navigation->delete(['company_id' => Configure::get('Blesta.company_id'), 'location' => $location]);

            // Format the post data
            $this->post['navigation'] = $this->ArrayHelper->keyToNumeric($this->post['navigation']);

            $parent_nav_id = null;
            foreach ($this->post['navigation'] as $index => $navigation_item) {
                $vars = ['action_id' => $navigation_item['action_id'], 'order' => $index];

                // If this is a subitem use the last primary navigation item as its parent
                if ($navigation_item['subitem'] == '1') {
                    $vars['parent_id'] = $parent_nav_id;
                }

                // Add the navigation item
                $nav_id = $this->Navigation->add($vars);

                // Keep track of the last inserted primary navigation item
                if ($navigation_item['subitem'] === '0') {
                    $parent_nav_id = $nav_id;
                }
            }

            $staff_groups = $this->StaffGroups->getAll($this->company_id);
            foreach ($staff_groups as $staff_group) {
                // Clear nav cache for this group
                $staff_members = $this->Staff->getAll($this->company_id, null, $staff_group->id);
                foreach ($staff_members as $staff_member) {
                    Cache::clearCache(
                        'nav_staff_group_' . $staff_group->id,
                        $this->company_id . DS . 'nav' . DS . $staff_member->id . DS
                    );
                }
            }

            $this->flashMessage('message', Language::_('AdminCompanyLookandfeel.!success.navigation_updated', true));
            $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
        }

        // Set URIs for the Navigation model
        $this->uses(['Navigation']);
        $this->Navigation->baseUri('public', $this->public_uri)
            ->baseUri('client', $this->client_uri)
            ->baseUri('admin', $this->admin_uri);

        // Get the appropriate list of navigation items for the given location
        switch ($location) {
            case 'nav_client':
                // Get client navigation items
                $navigation_items = $this->Navigation->getPrimaryClient($this->client_uri);
                break;
            case 'nav_public':
                // Get public navigation items
                $navigation_items = $this->Navigation->getPrimaryPublic($this->public_uri, $this->client_uri);
                break;
            case 'nav_staff':
                // This is the default case
            default:
                // Get staff navigation items
                $navigation_items = $this->Navigation->getPrimary($this->admin_uri);
        }

        // Create a list of only nav type action locations for the Create Item modal
        $nav_locations = $this->Actions->getLocations();
        foreach ($nav_locations as $loc => $name) {
            if (!str_contains($loc, 'nav_')) {
                unset($nav_locations[$loc]);
            }
        }

        $this->set('location', $location);
        $this->set(
            'actions',
            $this->Actions->getAll(['location' => $location, 'company_id' => $this->company_id, 'enabled' => 1])
        );
        $this->set('navigation_items', $navigation_items);
        $this->set('nav_locations', $nav_locations);

        return $this->renderAjaxWidgetIfAsync();
    }

    /**
     * List custom actions
     */
    /**
     * Custom actions are now managed from the Navigation page.
     * Redirect any direct access to the navigation page.
     */
    public function actions()
    {
        $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
    }

    /**
     * Add an action
     */
    public function addaction()
    {
        // Non-AJAX requests redirect to navigation page
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
        }

        // Create an action
        if (isset($this->post['url'])) {
            $this->post['company_id'] = $this->company_id;
            $this->post['url'] = trim($this->post['url'], '/');

            $action_id = $this->Actions->add($this->post);

            if (($errors = $this->Actions->errors())) {
                $this->outputAsJson(['error' => true, 'messages' => $errors]);
                return false;
            }

            $action = $this->Actions->get($action_id);
            $this->outputAsJson(['success' => true, 'action' => $action]);
            return false;
        }
    }

    /**
     * Edits an action
     */
    public function editaction()
    {
        // Non-AJAX requests redirect to navigation page
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
        }

        // Determine if this is an icon-only edit (allowed for any action in this company)
        $is_icon_only = isset($this->post['icon']) && !isset($this->post['url']);

        // Get action or return error if not given
        if (!isset($this->get[0])
            || !($action = $this->Actions->get((int)$this->get[0], false))
            || $action->company_id != $this->company_id
            || (!$is_icon_only && ($action->plugin_id != null || $action->editable != '1'))
        ) {
            $this->outputAsJson(['error' => true, 'messages' => ['action' => ['invalid' => 'Invalid action.']]]);
            return false;
        }

        // Edit an action
        if (isset($this->post['url']) || isset($this->post['icon'])) {
            $this->post['company_id'] = $this->company_id;
            if (isset($this->post['url'])) {
                $this->post['url'] = trim($this->post['url'], '/');
            }

            // For icon-only edits, only pass the icon field to avoid overwriting other fields
            $edit_vars = $is_icon_only ? ['icon' => $this->post['icon']] : $this->post;
            $this->Actions->edit($action->id, $edit_vars);

            if (($errors = $this->Actions->errors())) {
                $this->outputAsJson(['error' => true, 'messages' => $errors]);
                return false;
            }

            // Clear nav cache for all staff members
            $this->uses(['Staff', 'StaffGroups']);
            $staff_groups = $this->StaffGroups->getAll($this->company_id);
            foreach ($staff_groups as $staff_group) {
                $staff_members = $this->Staff->getAll($this->company_id, null, $staff_group->id);
                foreach ($staff_members as $staff_member) {
                    Cache::clearCache(
                        'nav_staff_group_' . $staff_group->id,
                        $this->company_id . DS . 'nav' . DS . $staff_member->id . DS
                    );
                }
            }

            $updated_action = $this->Actions->get($action->id);
            $this->outputAsJson(['success' => true, 'action' => $updated_action]);
            return false;
        }
    }

    /**
     * Delete custom action
     */
    public function deleteaction()
    {
        // Redirect if invalid action given
        if (!isset($this->post['id'])
            || !($action = $this->Actions->get((int) $this->post['id']))
            || $action->company_id != $this->company_id
            || $action->plugin_id != null
            || $action->editable != '1'
        ) {
            $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
        }

        // Delete the action and any associated navigation items by ID
        Loader::loadComponents($this, ['Record']);
        $this->Record->from('actions')
            ->leftJoin('navigation_items', 'navigation_items.action_id', '=', 'actions.id', false)
            ->where('actions.id', '=', $action->id)
            ->delete(['actions.*', 'navigation_items.*']);

        // Clear nav cache for all staff members
        $this->uses(['Staff', 'StaffGroups']);
        $staff_groups = $this->StaffGroups->getAll($this->company_id);
        foreach ($staff_groups as $staff_group) {
            $staff_members = $this->Staff->getAll($this->company_id, null, $staff_group->id);
            foreach ($staff_members as $staff_member) {
                Cache::clearCache(
                    'nav_staff_group_' . $staff_group->id,
                    $this->company_id . DS . 'nav' . DS . $staff_member->id . DS
                );
            }
        }

        // Success
        $this->flashMessage('message', Language::_('AdminCompanyLookandfeel.!success.action_deleted', true));

        $this->redirect($this->base_uri . 'settings/company/lookandfeel/navigation/');
    }

    /**
     * Customize look and feel
     */
    public function customize()
    {
        #
        # TODO: Set custom content CORE-828
        #

        // Get company settings
        $company_settings = $this->SettingsCollection->fetchSettings(
            null,
            $this->company_id
        );

        // Update logos and icons
        if (!empty($this->post)) {
            if (isset($this->files) && !empty($this->files)) {
                $temp = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
                $upload_path = $temp['value'] . $this->company_id . DS . 'themes' . DS;

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
                    $expected_files = [
                        'admin_logo', 'admin_icon_image', 'admin_logo_dark',
                        'admin_icon_image_dark', 'admin_favicon_image',
                        'client_logo', 'client_icon_image', 'client_favicon_image'
                    ];

                    // Will overwrite existing file, which is exactly what we want
                    $this->Upload->writeFiles($expected_files, true, $expected_files);
                    $data = $this->Upload->getUploadData();

                    foreach ($expected_files as $file) {
                        if (isset($data[$file])) {
                            $this->post[$file] = $data[$file]['full_path'];
                        }
                    }

                    $errors = $this->Upload->errors();
                }
            }

            // Set new logo url
            if (empty($errors)) {
                // Handle image removals
                $removable = [
                    'logo_admin', 'logo_admin_dark', 'icon_admin', 'icon_admin_dark',
                    'favicon_admin', 'logo_client', 'icon_client', 'favicon_client'
                ];
                $removed = [];
                foreach ($removable as $setting_key) {
                    if (($this->post['remove_' . $setting_key] ?? '0') === '1') {
                        $this->Companies->setSetting($this->company_id, $setting_key, '');
                        $removed[$setting_key] = true;
                    }
                }

                // Set admin logo and icon
                if (isset($this->post['admin_type'])) {
                    $logo_admin = null;
                    if ($this->post['admin_type'] == 'logo' && isset($this->post['admin_logo'])) {
                        $file_name = explode(DS, $this->post['admin_logo']);
                        $file_name = end($file_name);

                        $logo_admin = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif ($this->post['admin_type'] == 'url' && isset($this->post['admin_url'])) {
                        $logo_admin = trim($this->post['admin_url']);
                    }

                    if ($logo_admin && empty($removed['logo_admin'])) {
                        $this->Companies->setSetting($this->company_id, 'logo_admin', $logo_admin);
                    }

                    $icon_admin = null;
                    if ($this->post['admin_icon_type'] == 'image' && isset($this->post['admin_icon_image'])) {
                        $file_name = explode(DS, $this->post['admin_icon_image']);
                        $file_name = end($file_name);

                        $icon_admin = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif ($this->post['admin_icon_type'] == 'url' && isset($this->post['admin_icon_url'])) {
                        $icon_admin = trim($this->post['admin_icon_url']);
                    }

                    if ($icon_admin && empty($removed['icon_admin'])) {
                        $this->Companies->setSetting($this->company_id, 'icon_admin', $icon_admin);
                    }

                    // Set admin dark logo
                    $logo_admin_dark = null;
                    if (($this->post['admin_dark_type'] ?? '') == 'logo' && isset($this->post['admin_logo_dark'])) {
                        $file_name = explode(DS, $this->post['admin_logo_dark']);
                        $file_name = end($file_name);

                        $logo_admin_dark = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif (($this->post['admin_dark_type'] ?? '') == 'url' && isset($this->post['admin_url_dark'])) {
                        $logo_admin_dark = trim($this->post['admin_url_dark']);
                    }

                    if ($logo_admin_dark && empty($removed['logo_admin_dark'])) {
                        $this->Companies->setSetting($this->company_id, 'logo_admin_dark', $logo_admin_dark);
                    }

                    // Set admin dark icon
                    $icon_admin_dark = null;
                    if (($this->post['admin_icon_dark_type'] ?? '') == 'image' && isset($this->post['admin_icon_image_dark'])) {
                        $file_name = explode(DS, $this->post['admin_icon_image_dark']);
                        $file_name = end($file_name);

                        $icon_admin_dark = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif (($this->post['admin_icon_dark_type'] ?? '') == 'url' && isset($this->post['admin_icon_url_dark'])) {
                        $icon_admin_dark = trim($this->post['admin_icon_url_dark']);
                    }

                    if ($icon_admin_dark && empty($removed['icon_admin_dark'])) {
                        $this->Companies->setSetting($this->company_id, 'icon_admin_dark', $icon_admin_dark);
                    }

                    // Set admin favicon
                    $favicon_admin = null;
                    if (($this->post['admin_favicon_type'] ?? '') == 'image' && isset($this->post['admin_favicon_image'])) {
                        $file_name = explode(DS, $this->post['admin_favicon_image']);
                        $file_name = end($file_name);

                        $favicon_admin = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif (($this->post['admin_favicon_type'] ?? '') == 'url' && isset($this->post['admin_favicon_url'])) {
                        $favicon_admin = trim($this->post['admin_favicon_url']);
                    }

                    if ($favicon_admin && empty($removed['favicon_admin'])) {
                        $this->Companies->setSetting($this->company_id, 'favicon_admin', $favicon_admin);
                    }
                }

                // Set client logo and icon
                if (isset($this->post['client_type'])) {
                    $logo_client = null;
                    if ($this->post['client_type'] == 'logo' && isset($this->post['client_logo'])) {
                        $file_name = explode(DS, $this->post['client_logo']);
                        $file_name = end($file_name);

                        $logo_client = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif ($this->post['client_type'] == 'url' && isset($this->post['client_url'])) {
                        $logo_client = trim($this->post['client_url']);
                    }

                    if ($logo_client && empty($removed['logo_client'])) {
                        $this->Companies->setSetting($this->company_id, 'logo_client', $logo_client);
                    }

                    $icon_client = null;
                    if ($this->post['client_icon_type'] == 'image' && isset($this->post['client_icon_image'])) {
                        $file_name = explode(DS, $this->post['client_icon_image']);
                        $file_name = end($file_name);

                        $icon_client = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif ($this->post['client_icon_type'] == 'url' && isset($this->post['client_icon_url'])) {
                        $icon_client = trim($this->post['client_icon_url']);
                    }

                    if ($icon_client && empty($removed['icon_client'])) {
                        $this->Companies->setSetting($this->company_id, 'icon_client', $icon_client);
                    }

                    // Set client favicon
                    $favicon_client = null;
                    if (($this->post['client_favicon_type'] ?? '') == 'image' && isset($this->post['client_favicon_image'])) {
                        $file_name = explode(DS, $this->post['client_favicon_image']);
                        $file_name = end($file_name);

                        $favicon_client = WEBDIR . 'uploads/themes/asset/' . $file_name;
                    } elseif (($this->post['client_favicon_type'] ?? '') == 'url' && isset($this->post['client_favicon_url'])) {
                        $favicon_client = trim($this->post['client_favicon_url']);
                    }

                    if ($favicon_client && empty($removed['favicon_client'])) {
                        $this->Companies->setSetting($this->company_id, 'favicon_client', $favicon_client);
                    }
                }

                $errors = $this->Companies->errors();
            }

            // Set logo size
            if (isset($this->post['admin_logo_height']) && is_numeric($this->post['admin_logo_height'])) {
                $this->Companies->setSetting($this->company_id, 'admin_logo_height', $this->post['admin_logo_height']);
            }

            if (isset($this->post['client_logo_height']) && is_numeric($this->post['client_logo_height'])) {
                $this->Companies->setSetting($this->company_id, 'client_logo_height', $this->post['client_logo_height']);
            }

            // Set message
            if (!empty($errors)) {
                $this->flashMessage('error', $errors);
            } else {
                $this->flashMessage('message', Language::_('AdminCompanyLookandfeel.!success.logo_updated', true));
            }

            $this->redirect($this->base_uri . 'settings/company/lookandfeel/customize/');
        }

        // Get admin logo and icon
        $default_logo = $this->structure->view_dir . 'images/logo.svg';
        $default_icon = $this->structure->view_dir . 'images/favicon.ico';
        $admin_logo = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'logo_admin');
        $admin_icon = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'icon_admin');
        $admin_logo_dark = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'logo_admin_dark');
        $admin_icon_dark = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'icon_admin_dark');
        $admin_favicon = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'favicon_admin');
        $theme_id = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'theme_admin');

        if (!empty($theme_id['value'])) {
            $theme = $this->Themes->get($theme_id['value']);

            // Determine whether to set the default logo to the color version
            if (!empty($theme->colors['theme_header_bg_color_top'])) {
                $color = $this->Color->hex($theme->colors['theme_header_bg_color_top'])->contrast50()->asHex();

                if ($color == '000000') {
                    $default_logo = $this->structure->view_dir . 'images/logo-color.svg';
                }
            }
        }

        // Set default logo, if a custom one is not set
        $admin_default_logo = false;
        if (!empty($admin_logo['value'])) {
            $admin_logo = $admin_logo['value'];
        } else {
            $admin_default_logo = true;
            $admin_logo = $default_logo;
        }

        // Set default icon, if a custom one is not set
        $admin_default_icon = true;
        if (!empty($admin_icon['value'])) {
            $admin_default_icon = false;
            $admin_icon = $admin_icon['value'];
        } else {
            $admin_icon = $default_icon;
        }

        // Set dark variants (fallback to light)
        $admin_logo_dark = !empty($admin_logo_dark['value']) ? $admin_logo_dark['value'] : null;
        $admin_icon_dark = !empty($admin_icon_dark['value']) ? $admin_icon_dark['value'] : null;
        $admin_favicon = !empty($admin_favicon['value']) ? $admin_favicon['value'] : null;

        // Get client logo
        $client_view_dir = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'client_view_dir');
        $structure_view_dir = WEBDIR . 'app/views/client/' . $client_view_dir['value'] . '/';
        $default_logo = $structure_view_dir . 'images/logo.svg';
        $default_icon = $structure_view_dir . 'images/favicon.ico';
        $client_logo = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'logo_client');
        $client_icon = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'icon_client');
        $client_favicon = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'favicon_client');
        $client_colors = [];
        $theme_id = $this->SettingsCollection->fetchSetting(null, $this->company_id, 'theme_client');

        if (!empty($theme_id['value'])) {
            $theme = $this->Themes->get($theme_id['value']);
            // Determine whether to set the default logo to the color version
            if (!empty($theme->colors['theme_header_bg_color_top'])) {
                $color = $this->Color->hex($theme->colors['theme_header_bg_color_top'])->contrast50()->asHex();

                if ($color == '000000') {
                    $default_logo = $structure_view_dir . 'images/logo-color.svg';
                }

                $client_colors = $theme->colors;
            }
        }

        // Set default logo, if a custom one is not set
        $client_default_logo = false;
        if (!empty($client_logo['value'])) {
            $client_logo = $client_logo['value'];
        } else {
            $client_default_logo = true;
            $client_logo = $default_logo;
        }

        // Set default icon, if a custom one is not set
        $client_default_icon = true;
        if (!empty($client_icon['value'])) {
            $client_default_icon = false;
            $client_icon = $client_icon['value'];
        } else {
            $client_icon = $default_icon;
        }

        $client_favicon = !empty($client_favicon['value']) ? $client_favicon['value'] : null;

        // Get theme types
        $theme_types = $this->Themes->getTypes();

        $this->set('vars', $company_settings);
        $this->set('admin_logo', $admin_logo);
        $this->set('admin_icon', $admin_icon);
        $this->set('admin_logo_dark', $admin_logo_dark);
        $this->set('admin_icon_dark', $admin_icon_dark);
        $this->set('admin_favicon', $admin_favicon);
        $this->set('client_logo', $client_logo);
        $this->set('client_icon', $client_icon);
        $this->set('client_favicon', $client_favicon);
        $this->set('admin_default_logo', $admin_default_logo);
        $this->set('admin_default_icon', $admin_default_icon);
        $this->set('client_default_logo', $client_default_logo);
        $this->set('client_default_icon', $client_default_icon);
        $this->set('client_colors', $client_colors);
        $this->set('theme_types', $theme_types);
    }
}
