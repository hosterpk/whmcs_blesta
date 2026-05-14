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
