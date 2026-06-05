<?php
/**
 * Support Manager Admin Main controller
 *
 * @package blesta
 * @subpackage plugins.supportmanager
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminMain extends SupportManagerController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        // Restore structure view location of the admin portal
        $this->structure->setDefaultView(APPDIR);
        $this->structure->setView(null, $this->orig_structure_view);
        $this->requireLogin();

        // Load the models
        $this->uses(['SupportManager.SupportManagerSettings', 'Companies']);
        
        Language::loadLang('admin_main', null, PLUGINDIR . 'support_manager' . DS . 'language' . DS);
    }

    /**
     * Redirect to the AdminTickets controller
     */
    public function index()
    {
        $this->redirect($this->base_uri . 'plugin/support_manager/admin_tickets/');
    }

    /**
     * Settings page
     */
    public function settings()
    {
        $this->helpers(['Form']);
        $this->components(['SettingsCollection', 'Upload']);

        // Get current settings
        $settings = $this->Form->collapseObjectArray(
            $this->SupportManagerSettings->getSettings($this->company_id),
            'value',
            'key'
        );

        $vars = (object) $settings;

        // Set settings
        if (!empty($this->post)) {
            $settings_to_save = $this->post;

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
                    $this->Upload->writeFile('default_avatar', true, null, function ($file_name) {
                        return uniqid() . $this->Upload->md5($file_name);
                    });
                    $data = $this->Upload->getUploadData();

                    if (isset($data['default_avatar'])) {
                        $this->post['default_avatar'] = $data['default_avatar']['full_path'];
                    }

                    $upload_errors = $this->Upload->errors();
                }
            }
            
            // Handle avatar removal
            if (($this->post['remove_default_avatar'] ?? '0') == '1') {
                $default_avatar = $this->SupportManagerSettings->getSetting('default_avatar', $this->company_id);
                if (!empty($default_avatar->value) && file_exists($default_avatar->value)) {
                    unlink($default_avatar->value);
                }
                $this->post['default_avatar'] = '';
            }
            
            $this->SupportManagerSettings->setSettings($this->post, $this->company_id);
            $settings_error = $this->SupportManagerSettings->errors();

            $errors = array_merge(
                (array) (!empty($upload_errors) ? $upload_errors : []),
                (array) (!empty($settings_error) ? $settings_error : [])
            );

            if (!empty($errors)) {
                // Error, reset vars
                $vars = (object) $this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Success
                $this->flashMessage(
                    'message',
                    Language::_('AdminMain.!success.settings_updated', true),
                    null,
                    false
                );
                $this->redirect(
                    $this->base_uri . 'plugin/support_manager/admin_main/settings/'
                );
            }
        }

        // Set avatar options
        $avatar_options = [
            'gravatar' => Language::_('AdminMain.settings.option_gravatar', true),
            'fallback' => Language::_('AdminMain.settings.option_fallback', true),
            'default' => Language::_('AdminMain.settings.option_default', true)
        ];

        $this->set('vars', $vars);
        $this->set('settings', $settings);
        $this->set('avatar_options', $avatar_options);
        
        return $this->renderAjaxWidgetIfAsync(
            isset($this->get[0]) || isset($this->post['settings'])
        );
    }

    /**
     * AI settings page
     */
    public function ai()
    {
        $this->helpers(['Form']);
        $this->uses(['Settings', 'SupportManager.SupportManagerDepartments']);
        $this->components(['SettingsCollection']);

        // Get all system settings
        $system_settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        // Check if system AI is configured
        $ai_configured = !empty($system_settings['ai_enabled'])
            && $system_settings['ai_enabled'] == 'true'
            && !empty($system_settings['ai_api_key']);

        // Get current settings
        $settings = $this->Form->collapseObjectArray(
            $this->SupportManagerSettings->getSettings($this->company_id),
            'value',
            'key'
        );

        $vars = (object) $settings;

        // Handle form submission
        if (!empty($this->post)) {
            // Only allow known AI setting keys
            $allowed_keys = [
                'sm_ai_enabled',
                'sm_ai_override_model',
                'sm_ai_model',
                'sm_ai_override_max_tokens',
                'sm_ai_max_tokens',
                'sm_ai_override_temperature',
                'sm_ai_temperature',
                'sm_ai_system_prompt',
                'sm_ai_auto_reply_enabled',
                'sm_ai_require_human_review',
                'sm_ai_show_disclaimer',
                'sm_ai_custom_disclaimer',
                'sm_ai_confidence_threshold',
                'sm_ai_auto_reply_departments',
                'sm_ai_tools_enabled',
                'sm_ai_tool_change_priority',
                'sm_ai_tool_close_ticket',
                'sm_ai_tool_assign_staff',
                'sm_ai_tool_instructions',
                'sm_ai_assistant_name',
                'sm_ai_analyze_trigger',
                'sm_ai_max_queue_age_hours'
            ];

            $settings_to_save = array_intersect_key(
                $this->post,
                array_flip($allowed_keys)
            );

            // Set default values for checkboxes (unchecked checkboxes aren't submitted)
            $checkbox_fields = [
                'sm_ai_enabled',
                'sm_ai_override_model',
                'sm_ai_override_max_tokens',
                'sm_ai_override_temperature',
                'sm_ai_auto_reply_enabled',
                'sm_ai_require_human_review',
                'sm_ai_show_disclaimer',
                'sm_ai_tools_enabled',
                'sm_ai_tool_change_priority',
                'sm_ai_tool_close_ticket',
                'sm_ai_tool_assign_staff'
            ];

            foreach ($checkbox_fields as $field) {
                if (!isset($settings_to_save[$field])) {
                    $settings_to_save[$field] = 'false';
                }
            }

            // Handle auto-reply departments array - convert to JSON
            if (isset($settings_to_save['sm_ai_auto_reply_departments'])) {
                if (is_array($settings_to_save['sm_ai_auto_reply_departments'])) {
                    $settings_to_save['sm_ai_auto_reply_departments'] = json_encode($settings_to_save['sm_ai_auto_reply_departments']);
                }
            } else {
                // If not set, save empty array
                $settings_to_save['sm_ai_auto_reply_departments'] = '[]';
            }

            $this->SupportManagerSettings->setSettings($settings_to_save, $this->company_id);

            if (($errors = $this->SupportManagerSettings->errors())) {
                // Error, reset vars
                $vars = (object) $this->post;
                $this->setMessage('error', $errors, false, null, false);
            } else {
                // Success
                $this->flashMessage(
                    'message',
                    Language::_('AdminMain.!success.ai_settings_updated', true),
                    null,
                    false
                );
                $this->redirect(
                    $this->base_uri . 'plugin/support_manager/admin_main/ai/'
                );
            }
        }

        // Fetch models from BlestaAI if configured
        $models = [];
        if ($ai_configured) {
            try {
                $this->components(['BlestaAi']);
                $ai = new BlestaAi($system_settings['ai_api_key']);
                $models = $ai->getModels();
            } catch (Exception $e) {
                // Failed to fetch models - will use empty array
            }
        }

        // Set system defaults from global AI settings
        $system_defaults = [
            'model' => $system_settings['ai_default_model'] ?? 'claude-3-5-sonnet-20241022',
            'max_tokens' => $system_settings['ai_max_tokens'] ?? '4000',
            'temperature' => $system_settings['ai_temperature'] ?? '1.0'
        ];

        // Set default values if not set
        if (!isset($vars->sm_ai_enabled)) {
            $vars->sm_ai_enabled = 'false';
        }
        if (!isset($vars->sm_ai_override_model)) {
            $vars->sm_ai_override_model = 'false';
        }
        if (!isset($vars->sm_ai_model)) {
            $vars->sm_ai_model = $system_defaults['model'];
        }
        if (!isset($vars->sm_ai_override_max_tokens)) {
            $vars->sm_ai_override_max_tokens = 'false';
        }
        if (!isset($vars->sm_ai_max_tokens)) {
            $vars->sm_ai_max_tokens = $system_defaults['max_tokens'];
        }
        if (!isset($vars->sm_ai_override_temperature)) {
            $vars->sm_ai_override_temperature = 'false';
        }
        if (!isset($vars->sm_ai_temperature)) {
            $vars->sm_ai_temperature = $system_defaults['temperature'];
        }
        if (!isset($vars->sm_ai_auto_reply_enabled)) {
            $vars->sm_ai_auto_reply_enabled = 'false';
        }
        if (!isset($vars->sm_ai_confidence_threshold)) {
            $vars->sm_ai_confidence_threshold = '70';
        }
        if (!isset($vars->sm_ai_require_human_review)) {
            $vars->sm_ai_require_human_review = 'true';
        }
        if (!isset($vars->sm_ai_show_disclaimer)) {
            $vars->sm_ai_show_disclaimer = 'true';
        }
        if (!isset($vars->sm_ai_tools_enabled)) {
            $vars->sm_ai_tools_enabled = 'false';
        }
        if (!isset($vars->sm_ai_tool_change_priority)) {
            $vars->sm_ai_tool_change_priority = 'false';
        }
        if (!isset($vars->sm_ai_tool_close_ticket)) {
            $vars->sm_ai_tool_close_ticket = 'false';
        }
        if (!isset($vars->sm_ai_tool_assign_staff)) {
            $vars->sm_ai_tool_assign_staff = 'false';
        }

        // Format model options for dropdown (same format as system AI settings)
        $model_options = [];
        foreach ($models as $model) {
            $model_id = $model->id;
            $model_name = $model->name;
            $label = ($model_name !== $model_id) ? $model_name : $model_id;

            if ($model->promptPrice !== null && $model->completionPrice !== null) {
                $label .= ' (' . number_format($model->promptPrice, 6) . ' / ' . number_format($model->completionPrice, 6) . ')';
            }

            $model_options[$model_id] = $label;
        }

        // If no models fetched but we have a current model set, add it to the list
        if (empty($model_options) && !empty($vars->sm_ai_model)) {
            $model_options[$vars->sm_ai_model] = $vars->sm_ai_model;
        }

        // Fetch departments for auto-reply restrictions
        $departments = $this->SupportManagerDepartments->getAll($this->company_id);

        $this->set('vars', $vars);
        $this->set('settings', $settings);
        $this->set('ai_configured', $ai_configured);
        $this->set('system_defaults', $system_defaults);
        $this->set('model_options', $model_options);
        $this->set('departments', $departments);

        return $this->renderAjaxWidgetIfAsync(
            isset($this->get[0]) || isset($this->post)
        );
    }

    /**
     * Creates a unique filename for uploaded avatar files
     *
     * @param string $name The original filename
     * @param string $path The upload path
     * @return string The new unique filename
     */
    public function makeAvatarFileName($name, $path)
    {
        $file_info = pathinfo($name);
        $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';

        return 'default_avatar_' . time() . $extension;
    }
}
