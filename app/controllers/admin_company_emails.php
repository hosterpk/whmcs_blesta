<?php

use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;

/**
 * Admin Company Emails Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminCompanyEmails extends AdminController
{
    /**
     * Pre-action
     */
    public function preAction()
    {
        parent::preAction();

        $this->uses(['Navigation']);
        $this->components(['SettingsCollection', 'Email']);
    }

    /**
     * Taxes page
     */
    public function index()
    {
        $this->redirect($this->base_uri . 'settings/company/emails/templates/');
    }

    /**
     * Email Mail Settings page
     */
    public function mail()
    {
        $this->uses(['Companies', 'Emails']);

        if (!empty($this->post)) {
            // Set checkboxes if not given
            if (empty($this->post['html_email'])) {
                $this->post['html_email'] = 'false';
            }

            $fields = [
                'html_email', 'mail_delivery', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password',
                'sendmail_path', 'sendmail_from', 'oauth2_host', 'oauth2_port', 'oauth2_provider',
                'oauth2_user', 'oauth2_client_id', 'oauth2_client_secret'
            ];
            $this->Companies->setSettings($this->company_id, $this->post, $fields);

            if (
                !empty($this->post['oauth2_provider'])
                && !empty($this->post['oauth2_client_id'])
                && !empty($this->post['oauth2_client_secret'])
            ) {
                // Fetch authorization url
                try {
                    $transport = $this->Email->buildTransport('oauth2')
                        ->setProvider($this->post['oauth2_provider']);
                } catch (Throwable $e) {
                    $this->flashMessage('error', $e->getMessage());
                    $this->redirect($this->base_uri . 'settings/company/emails/mail/');

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
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.mail_updated', true));
                $this->redirect($this->base_uri . 'settings/company/emails/mail/');
            }
        }

        $vars = $this->SettingsCollection->fetchSettings($this->Companies, $this->company_id);
        if (!isset($vars['sendmail_path'])) {
            $sendmail_path = ini_get('sendmail_path');
            $vars['sendmail_path'] = !empty($sendmail_path) ? $sendmail_path : '/usr/sbin/sendmail -bs';
        }

        if (!isset($vars['sendmail_from'])) {
            $email = $this->Emails->getByType(Configure::get('Blesta.company_id'), 'forgot_username');
            $vars['sendmail_from'] = $email->from;
        }

        if (!isset($vars['smtp_from'])) {
            $email = $this->Emails->getByType(Configure::get('Blesta.company_id'), 'forgot_username');
            $vars['smtp_from'] = $email->from;
        }

        // Process OAuth 2.0 authorization redirect
        if (!empty($this->get['code'])) {
            $this->Companies->setSettings($this->company_id, ['oauth2_code' => $this->get['code']]);

            $transport = $this->Email->buildTransport('oauth2');
            $transport->setProvider($vars['oauth2_provider'])
                ->setAuthorizationCode($this->get['code'])
                ->setClientId($vars['oauth2_client_id'])
                ->setClientSecret($vars['oauth2_client_secret'])
                ->refreshAccessToken(true);
        }

        // Get SMTP servers
        $smtp_servers = [];
        foreach ($this->getOauth2Providers() as $provider => $name) {
            $transport = $this->Email->buildTransport('oauth2');
            $provider_transport = $transport->setProvider($provider)
                ->getProvider();

            if (empty($provider_transport['smtp'])) {
                continue;
            }

            $smtp_servers[$provider] = $provider_transport['smtp'] ?? '';
        }
        $this->set('smtp_servers', $smtp_servers);

        // Generate OAuth2 redirect URI for display
        $oauth2_redirect_uri = '';
        try {
            $transport = $this->Email->buildTransport('oauth2');
            $oauth2_redirect_uri = $transport->getRedirectUri();
        } catch (Throwable $e) {
            // If transport cannot be built, redirect URI won't be available
        }
        $this->set('oauth2_redirect_uri', $oauth2_redirect_uri);

        $this->set('vars', $vars);
        $this->set('delivery_methods', $this->getDeliveryMethods());
        $this->set('security_options', $this->getSmtpSecurityOptions());
        $this->set('oauth2_providers', $this->getOauth2Providers());
        $this->set(
            'message_unauthorized',
            $this->setMessage(
                'error',
                Language::_('AppController.!error.unauthorized_access', true),
                true,
                null,
                false
            )
        );
    }

    /**
     * Test SMTP connection
     */
    public function mailTest()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/company/emails/mail/');
        }

        try {
            $transport = null;
            if (isset($this->post['mail_delivery'])) {
                switch ($this->post['mail_delivery']) {
                    case 'smtp':
                        // Test SMTP settings
                        $transport = $this->Email->buildTransport(
                            $this->post['mail_delivery'],
                            [
                                'host' => $this->post['smtp_host'],
                                'port' => (int) (!empty($this->post['smtp_port']) ? $this->post['smtp_port'] : 465)
                            ]
                        );
                        $transport->setUsername($this->post['smtp_user'])
                            ->setPassword($this->post['smtp_password']);

                        $this->Email->setTransport($transport);

                        $this->Email->setSubject(Language::_('AdminCompanyEmails.!success.' . $this->post['mail_delivery']  . '_test', true));
                        $this->Email->setBody(md5(time()));
                        $this->Email->setFrom($this->post['smtp_from'] ?: (time() . '@mailinator.com'));
                        $this->Email->addAddress($this->post['smtp_to'] ?: md5(time()) . '@mailinator.com');
                        $this->Email->send(true);
                        break;
                    case 'oauth2':
                        // Test OAuth2.0 settings
                        $transport = $this->Email->buildTransport(
                            $this->post['mail_delivery'],
                            [
                                'host' => $this->post['oauth2_host'],
                                'port' => (int) (!empty($this->post['oauth2_port']) ? $this->post['oauth2_port'] : 587)
                            ]
                        );

                        $transport->setUsername($this->post['oauth2_user'])
                            ->setClientId($this->post['oauth2_client_id'])
                            ->setClientSecret($this->post['oauth2_client_secret'])
                            ->setProvider($this->post['oauth2_provider'])
                            ->refreshAccessToken();

                        $this->Email->setTransport($transport);

                        $this->Email->setSubject(Language::_('AdminCompanyEmails.!success.' . $this->post['mail_delivery']  . '_test', true));
                        $this->Email->setBody(md5(time()));
                        $this->Email->setFrom($this->post['oauth2_from'] ?: (time() . '@mailinator.com'));
                        $this->Email->addAddress($this->post['oauth2_to'] ?: md5(time()) . '@mailinator.com');
                        $this->Email->send(true);
                        break;
                    default:
                        // Get the sendmail path
                        $sendmail_path = ini_get('sendmail_path');
                        if (isset($this->post['sendmail_path'])) {
                            $sendmail_path = $this->post['sendmail_path'];
                        }

                        $transport = $this->Email->buildTransport(
                            'sendmail',
                            ['command' => !empty($sendmail_path) ? $sendmail_path : null]
                        );

                        $this->Email->setTransport($transport);

                        $this->Email->setSubject(Language::_('AdminCompanyEmails.!success.' . $this->post['mail_delivery']  . '_test', true));
                        $this->Email->setBody(md5(time()));
                        $this->Email->setFrom($this->post['sendmail_from'] ?: (time() . '@mailinator.com'));
                        $this->Email->addAddress(md5(time()) . '@mailinator.com');
                        $this->Email->send(true);
                }
            }

            echo $this->setMessage(
                'message',
                Language::_('AdminCompanyEmails.!success.' . $this->post['mail_delivery']  . '_test', true),
                true
            );
        } catch (Throwable $e) {
            echo $this->setMessage('error', $e->getMessage(), true);
        }

        return false;
    }

    /**
     * Email Templates
     */
    public function templates()
    {
        $this->uses(['EmailGroups', 'Languages', 'Emails']);

        $groups = [];
        // Load core groups
        $groups['client'] = $this->EmailGroups->getAllEmails($this->company_id);
        $groups['staff'] = $this->EmailGroups->getAllEmails($this->company_id, 'staff');

        // Load plugin groups
        $plugin_groups = [];
        $plugin_groups = $this->EmailGroups->getAllEmails($this->company_id, 'client', false);
        $plugin_groups = array_merge(
            $plugin_groups,
            $this->EmailGroups->getAllEmails($this->company_id, 'staff', false)
        );
        $plugin_groups = array_merge(
            $plugin_groups,
            $this->EmailGroups->getAllEmails($this->company_id, 'shared', false)
        );

        $groups['plugins'] = $plugin_groups;

        // Set language for each group
        foreach ($groups as $type => &$group_list) {
            foreach ($group_list as &$group) {
                // Set plugin-specific language
                if ($type == 'plugins') {
                    Language::loadLang(
                        'admin_company_emails',
                        null,
                        PLUGINDIR . $group->plugin_dir . DS . 'language' . DS
                    );
                }

                $group->group_name = Language::_(
                    'AdminCompanyEmails.templates.' . $group->email_group_action . '_name',
                    true
                );
                $group->group_desc = Language::_(
                    'AdminCompanyEmails.templates.' . $group->email_group_action . '_desc',
                    true
                );
            }
        }

        // Process bulk actions
        if (!empty($this->post['action'])) {
            $languages = $this->Languages->getAll(Configure::get('Blesta.company_id'));

            switch ($this->post['action']) {
                case 'from_email':
                    $success = false;
                    foreach ($this->post['template_id'] as $template_id) {
                        foreach ($languages as $language) {
                            $params = ['from' => $this->post['from'] ?? null];
                            $template = (array) $this->Emails->getByGroupId($template_id, $language->code);

                            $this->Emails->edit($template['id'], array_merge($template, $params));

                            if (($errors = $this->Emails->errors())) {
                                // Error
                                $this->setMessage('error', $errors);
                                $success = false;
                                $vars = (object) $this->post;

                                break;
                            } else {
                                $success = true;
                            }
                        }
                    }

                    if ($success) {
                        // Success
                        $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.edittemplate_updated', true));
                        $this->redirect($this->base_uri . 'settings/company/emails/templates/');
                    }
                    break;
                case 'from_name':
                    $success = false;
                    foreach ($this->post['template_id'] as $template_id) {
                        foreach ($languages as $language) {
                            $params = ['from_name' => $this->post['from'] ?? null];
                            $template = (array) $this->Emails->getByGroupId($template_id, $language->code);

                            $this->Emails->edit($template['id'], array_merge($template, $params));

                            if (($errors = $this->Emails->errors())) {
                                // Error
                                $this->setMessage('error', $errors);
                                $success = false;
                                $vars = (object) $this->post;

                                break;
                            } else {
                                $success = true;
                            }
                        }
                    }

                    if ($success) {
                        // Success
                        $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.edittemplate_updated', true));
                        $this->redirect($this->base_uri . 'settings/company/emails/templates/');
                    }
                    break;
                case 'html_template':
                    $success = false;
                    foreach ($this->post['template_id'] as $template_id) {
                        foreach ($languages as $language) {
                            $params = ['email_template_group_id' => $this->post['email_template_group_id'] ?? null];
                            $template = (array) $this->Emails->getByGroupId($template_id, $language->code);

                            $this->Emails->edit($template['id'], array_merge($template, $params));

                            if (($errors = $this->Emails->errors())) {
                                // Error
                                $this->setMessage('error', $errors);
                                $success = false;
                                $vars = (object) $this->post;

                                break;
                            } else {
                                $success = true;
                            }
                        }
                    }

                    if ($success) {
                        // Success
                        $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.edittemplate_updated', true));
                        $this->redirect($this->base_uri . 'settings/company/emails/templates/');
                    }
                    break;
            }
        }

        // Get all HTML templates
        if (!isset($this->EmailHtmlTemplates)) {
            Loader::loadModels($this, ['EmailHtmlTemplates']);
        }

        $html_templates = $this->Form->collapseObjectArray(
            $this->EmailHtmlTemplates->getGroups(Configure::get('Blesta.company_id')),
            'name',
            'id'
        );

        $no_temp = ['' => Language::_('AdminCompanyEmails.gettemplateactions.text_none', true)];
        $html_templates = $no_temp + $html_templates;

        $this->set('template_actions', $this->getTemplateActions());
        $this->set('html_templates', $html_templates);
        $this->set('vars', $vars ?? new stdClass());
        $this->set('groups', $groups);
    }

    /**
     * Edit Email Template
     */
    public function editTemplate()
    {
        $this->uses(['EmailGroups', 'Emails', 'Languages', 'EmailHtmlTemplates', 'EmailSnapshots']);
        $this->components(['Upload']);

        // Set the language of the template to fetch
        $selected_language = Configure::get('Blesta.language');

        // Fetch a specific template, if one is given (for another language)
        if (isset($this->get[1]) && ($selected_template = $this->Emails->get($this->get[1]))) {
            $selected_language = $selected_template->lang;
        }
        unset($selected_template);

        // Ensure a valid email group was given
        if (!isset($this->get[0]) || !($template = $this->Emails->getByGroupId($this->get[0], $selected_language))) {
            $this->redirect($this->base_uri . 'settings/company/emails/templates/');
        }

        // Set the selected template as the vars
        $vars = $template;
        $templates = new stdClass();
        $company_id = $this->company_id;

        if (!empty($this->post)) {
            // Set empty checkboxes for this email template
            if (empty($this->post['status'])) {
                $this->post['status'] = 'inactive';
            }
            if (empty($this->post['include_attachments'])) {
                $this->post['include_attachments'] = 0;
            }

            // Update Email template
            $this->post['email_group_id'] = (int) $this->get[0];
            $this->post['company_id'] = $company_id;

            // Remove email signature if set to none
            if (empty($this->post['email_signature_id'])) {
                $this->post['email_signature_id'] = null;
            }

            // Remove email HTML template if set to none
            if (empty($this->post['email_template_group_id'])) {
                $this->post['email_template_group_id'] = null;
            }

            // Decode CKEditor's URL-encoded template tags in href attributes.
            if (!empty($this->post['html'])) {
                $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();
                $this->post['html'] = $sanitizer->decodeHrefTemplateTags($this->post['html']);
            }

            $this->Emails->edit($template->id, $this->post);
            $errors = $this->Emails->errors();

            // Handle file uploads (only if email validation passed)
            if (!$errors && isset($this->files) && !empty($this->files)) {
                $temp = $this->SettingsCollection->fetchSetting($this->Companies, $this->company_id, 'uploads_dir');
                $upload_path = $temp['value'] . $this->company_id . DS . 'email_attachments' . DS;

                $this->Upload->setFiles($this->files, false);

                // Create the upload path if it doesn't already exists
                $this->Upload->createUploadPath($upload_path);
                $this->Upload->setUploadPath($upload_path);

                // Set maximum size to 20MB
                $this->Upload->setMaxFileSize(20 * 1024 * 1024); // in bytes

                if (!($errors = $this->Upload->errors())) {
                    // Will overwrite existing file, which is exactly what we want
                    $this->Upload->writeFile('attachment', true, null, function ($file_name) {
                        $ext = strrchr($file_name, '.');
                        $file_name = md5($file_name . uniqid()) . $ext;

                        return $this->Emails->dateToUtc(date('c'), "Ymd\THisO") . '_' . $file_name;
                    });
                    $data = $this->Upload->getUploadData();

                    if (!($errors = $this->Upload->errors()) && !empty($data)) {
                        $this->Emails->addAttachments($template->id, $data['attachment']);
                        $errors = $this->Emails->errors();
                    }
                }
            }

            if ($errors) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.edittemplate_updated', true));
                $this->redirect($this->base_uri . 'settings/company/emails/edittemplate/' . $template->email_group_id . '/');
            }
        }

        // Set the template name
        if (!empty($template->plugin_dir)) {
            Language::loadLang('admin_company_emails', null, PLUGINDIR . $template->plugin_dir . DS . 'language' . DS);
        }
        $template_name = Language::_('AdminCompanyEmails.templates.' . $template->email_group_action . '_name', true);

        // All email group templates
        $templates = $this->Emails->getList($company_id, (int) $this->get[0]);
        $languages = $this->Languages->getAll($company_id);

        // Set template language names
        if (is_array($templates) && is_array($languages)) {
            $num_temp = count($templates);
            $num_lang = count($languages);

            for ($i = 0; $i < $num_temp; $i++) {
                for ($j = 0; $j < $num_lang; $j++) {
                    if ($templates[$i]->lang == $languages[$j]->code) {
                        $templates[$i]->lang_name = $languages[$j]->name;
                    }
                }
            }
        }

        // All email signatures
        $signatures = $this->Form->collapseObjectArray($this->Emails->getSignatureList($company_id), 'name', 'id');

        $no_sig = ['' => Language::_('AdminCompanyEmails.edittemplate.text_none', true)];
        $signatures = $no_sig + $signatures;

        // Get all HTML templates
        $email_template_groups = $this->Form->collapseObjectArray(
            $this->EmailHtmlTemplates->getGroups($company_id),
            'name',
            'id'
        );

        $no_temp = ['' => Language::_('AdminCompanyEmails.edittemplate.text_none', true)];
        $email_template_groups = $no_temp + $email_template_groups;

        // Fetch email snapshots for the current template
        $snapshots = $this->EmailSnapshots->getAll($template->id, true);

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);

        // Check if AI features are enabled for email templates
        $system_settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        $ai_feature_enabled = !empty($system_settings['ai_enabled'])
            && $system_settings['ai_enabled'] === 'true'
            && !empty($system_settings['ai_feature_email_templates'])
            && $system_settings['ai_feature_email_templates'] === 'true';

        $this->set('template_name', $template_name);
        $this->set('status', $this->Emails->getStatusTypes());
        $this->set('templates', $templates);
        $this->set('signatures', $signatures);
        $this->set('email_template_groups', $email_template_groups);
        $this->set('vars', $vars);
        $this->set('template', $template);
        $this->set('snapshots', $snapshots);
        $this->set('ai_feature_enabled', $ai_feature_enabled);
    }

    /**
     * Delete Attachment
     */
    public function deleteAttachment()
    {
        $this->uses(['Emails']);

        if (!$this->isAjax()) {
            header($this->server_protocol . ' 403 Forbidden');
            exit();
        }

        if (!isset($this->post['id'])
            || !($attachment = $this->Emails->getAttachment((int) $this->post['id']))
            || $attachment->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'settings/company/emails/templates/');
        }

        $this->Emails->deleteAttachment($attachment->id);

        if (($errors = $this->Emails->errors())) {
            return false;
        }

        // JSON encode the AJAX response
        unset($attachment->file_name);
        $this->outputAsJson($attachment);
        return false;
    }

    /**
     * Restore Email Template from Snapshot
     */
    public function restoreSnapshot()
    {
        $this->uses(['Emails', 'EmailSnapshots']);

        if (
            !isset($this->post['email_id'])
            || !isset($this->post['snapshot_id'])
            || !($email = $this->Emails->get((int) $this->post['email_id']))
        ) {
            $this->redirect($this->base_uri . 'settings/company/emails/templates/');
        }

        $email_id = $email->id;
        $email_group_id = $email->email_group_id;
        $snapshot_id = (int) $this->post['snapshot_id'];

        $this->EmailSnapshots->restore($email_id, $snapshot_id);

        if (($errors = $this->EmailSnapshots->errors())) {
            $this->flashMessage('error', $errors);
        } else {
            $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.snapshot_restored', true));
        }

        $this->redirect($this->base_uri . 'settings/company/emails/edittemplate/' . $email_group_id . '/' . $email_id . '/');
    }

    /**
     * Email Signatures
     */
    public function signatures()
    {
        $this->uses(['Emails']);

        // Set current page of results
        $page = (isset($this->get[0]) ? (int) $this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'name');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'asc');

        $signatures = $this->Emails->getSignatureList($this->company_id, $page, [$sort => $order]);

        $this->set('signatures', $signatures);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $this->Emails->getSignatureListCount($this->company_id),
                'uri' => $this->base_uri . 'settings/company/emails/signatures/[p]/',
                'params' => ['sort' => $sort, 'order' => $order]
            ]
        );
        $this->setPagination($this->get, $settings);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Add Email Signature
     */
    public function addSignature()
    {
        $this->uses(['Emails']);

        $vars = new stdClass();

        if (!empty($this->post)) {
            $this->post['company_id'] = $this->company_id;
            $this->Emails->addSignature($this->post);

            if (($errors = $this->Emails->errors())) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.addsignature_created', true));
                $this->redirect($this->base_uri . 'settings/company/emails/signatures/');
            }
        }

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);

        $this->set('vars', $vars);
    }

    /**
     * Edit Email Signature
     */
    public function editSignature()
    {
        $this->uses(['Emails']);

        // Redirect if signature invalid or if it does not belong to this company
        if (!isset($this->get[0]) || !($signature = $this->Emails->getSignature((int) $this->get[0])) ||
            ($signature->company_id != $this->company_id)) {
            $this->redirect($this->base_uri . 'settings/company/emails/signatures/');
        }

        $vars = [];

        if (!empty($this->post)) {
            $this->Emails->editSignature($signature->id, $this->post);

            if (($errors = $this->Emails->errors())) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.editsignature_updated', true));
                $this->redirect($this->base_uri . 'settings/company/emails/editsignature/' . $signature->id . '/');
            }
        }

        // Set default signature
        if (empty($vars)) {
            $vars = $signature;
        }

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);

        $this->set('vars', $signature);
    }

    /**
     * Delete Email Signature
     */
    public function deleteSignature()
    {
        $this->uses(['Emails']);

        if (!isset($this->post['id'])
            || !($signature = $this->Emails->getSignature((int) $this->post['id']))
            || $signature->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'settings/company/emails/signatures/');
        }

        $this->Emails->deleteSignature($signature->id);

        if (($errors = $this->Emails->errors())) {
            // Error
            $this->flashMessage('error', $errors);
        } else {
            // Success
            $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.deletesignature_deleted', true));
        }

        $this->redirect($this->base_uri . 'settings/company/emails/signatures/');
    }

    /**
     * Email HTML Templates
     */
    public function htmlTemplates()
    {
        $this->uses(['EmailHtmlTemplates']);

        // Load groups
        $groups = $this->EmailHtmlTemplates->getGroups($this->company_id);

        $this->set('vars', $vars ?? new stdClass());
        $this->set('groups', $groups);
    }

    /**
     * Add HTML Template group
     */
    public function addHtmlTemplate()
    {
        $this->uses(['EmailHtmlTemplates', 'Languages', 'Settings']);

        // Set the language of the template to fetch
        $selected_language = Configure::get('Blesta.language');

        // Set available tags
        $tags = ['{{email_body}}', '{{signature}}'];

        // Fetch all languages
        $languages = $this->Languages->getAll($this->company_id);

        $vars = new stdClass();

        if (!empty($this->post)) {
            $this->post['company_id'] = $this->company_id;
            $this->EmailHtmlTemplates->addGroup($this->post);

            if (($errors = $this->EmailHtmlTemplates->errors())) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.addhtmltemplate_created', true));
                $this->redirect($this->base_uri . 'settings/company/emails/htmltemplates/');
            }
        }

        // Check if AI features are enabled for email templates
        $system_settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);
        $ai_feature_enabled = !empty($system_settings['ai_enabled'])
            && $system_settings['ai_enabled'] === 'true'
            && !empty($system_settings['ai_feature_email_templates'])
            && $system_settings['ai_feature_email_templates'] === 'true';

        $this->set('tags', $tags);
        $this->set('languages', $languages);
        $this->set('vars', $vars);
        $this->set('ai_feature_enabled', $ai_feature_enabled);

        // Load Ace editor
        $this->Javascript->setFile('ace/src-min/ace.js', 'head', VENDORWEBDIR);
    }

    /**
     * Edit HTML Template group
     */
    public function editHtmlTemplate()
    {
        $this->uses(['EmailHtmlTemplates', 'Languages']);

        // Redirect if signature invalid or if it does not belong to this company
        if (!isset($this->get[0]) || !($group = $this->EmailHtmlTemplates->getGroup((int) $this->get[0])) ||
            ($group->company_id != $this->company_id)) {
            $this->redirect($this->base_uri . 'settings/company/emails/htmltemplates/');
        }

        // Set the language of the template to fetch
        $selected_language = Configure::get('Blesta.language');

        // Set available tags
        $tags = ['{{email_body}}', '{{signature}}'];

        // Fetch all languages
        $languages = $this->Languages->getAll($this->company_id);

        $vars = $group;

        if (!empty($this->post)) {
            $this->EmailHtmlTemplates->editGroup($group->id, $this->post);

            if (($errors = $this->EmailHtmlTemplates->errors())) {
                // Error
                $this->setMessage('error', $errors);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.edithtmltemplate_updated', true));
                $this->redirect($this->base_uri . 'settings/company/emails/edithtmltemplate/' . $group->id . '/');
            }
        }

        $this->set('tags', $tags);
        $this->set('languages', $languages);
        $this->set('vars', $vars);

        // Load Ace editor
        $this->Javascript->setFile('ace/src-min/ace.js', 'head', VENDORWEBDIR);
    }

    /**
     * Delete HTML Template group
     */
    public function deleteHtmlTemplate()
    {
        $this->uses(['EmailHtmlTemplates']);

        if (!isset($this->post['id'])
            || !($group = $this->EmailHtmlTemplates->getGroup((int) $this->post['id']))
            || $group->company_id != $this->company_id
        ) {
            $this->redirect($this->base_uri . 'settings/company/emails/htmltemplates/');
        }

        $this->EmailHtmlTemplates->deleteGroup($group->id);

        if (($errors = $this->EmailHtmlTemplates->errors())) {
            // Error
            $this->flashMessage('error', $errors);
        } else {
            // Success
            $this->flashMessage('message', Language::_('AdminCompanyEmails.!success.deletehtmltemplate_deleted', true));
        }

        $this->redirect($this->base_uri . 'settings/company/emails/htmltemplates/');
    }

    /**
     * Retrieves a list of mail delivery methods
     *
     * @return array A list of key=>value pairs of delivery methods
     */
    private function getDeliveryMethods()
    {
        return [
            'sendmail' => Language::_('AdminCompanyEmails.getRequiredMethods.sendmail', true),
            'smtp' => Language::_('AdminCompanyEmails.getRequiredMethods.smtp', true),
            'oauth2' => Language::_('AdminCompanyEmails.getRequiredMethods.oauth2', true)
        ];
    }

    /**
     * Retrieves a list of SMTP security options
     *
     * @return array A list of key=>value pairs of smtp security options
     */
    private function getSmtpSecurityOptions()
    {
        return [
            '' => Language::_('AdminCompanyEmails.getsmtpsecurityoptions.none', true),
            'ssl' => Language::_('AdminCompanyEmails.getsmtpsecurityoptions.ssl', true),
            'tls' => Language::_('AdminCompanyEmails.getsmtpsecurityoptions.tls', true)
        ];
    }

    /**
     * Retrieves a list of OAuth 2.0 providers
     *
     * @return array A list of key=>value pairs of OAuth 2.0 providers
     */
    private function getOauth2Providers()
    {
        return [
            'google' => Language::_('AdminCompanyEmails.getoauth2providers.google', true),
            'microsoft' => Language::_('AdminCompanyEmails.getoauth2providers.microsoft', true)
        ];
    }

    /**
     * Retrieves a list of Email Template bulk actions
     *
     * @return array A list of key=>value pairs of bulk actions
     */
    private function getTemplateActions()
    {
        return [
            'from_email' => Language::_('AdminCompanyEmails.gettemplateactions.update_from_email', true),
            'from_name' => Language::_('AdminCompanyEmails.gettemplateactions.update_from_name', true),
            'html_template' => Language::_('AdminCompanyEmails.gettemplateactions.update_html_template', true)
        ];
    }

    /**
     * AJAX endpoint to generate email template content using AI
     */
    public function generateEmailContent()
    {
        // Only accept AJAX POST requests
        if (!$this->isAjax()) {
            header('HTTP/1.0 403 Forbidden');
            return false;
        }

        $this->uses(['Settings']);
        $this->components(['BlestaAi']);

        $response = [
            'success' => false,
            'error' => 'Invalid request'
        ];

        // Check if AI is enabled and email templates feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (empty($settings['ai_enabled']) || $settings['ai_enabled'] !== 'true') {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_disabled', true);
            echo json_encode($response);
            return false;
        }

        if (empty($settings['ai_feature_email_templates'])
            || $settings['ai_feature_email_templates'] !== 'true') {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_feature_disabled', true);
            echo json_encode($response);
            return false;
        }

        // Check rate limiting
        if (!$this->checkAiRateLimit()) {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_rate_limit', true);
            echo json_encode($response);
            return false;
        }

        try {
            $ai = new BlestaAi($settings['ai_api_key']);

            // Get and sanitize parameters
            $prompt = isset($this->post['prompt']) ? trim($this->post['prompt']) : '';
            $email_type = isset($this->post['email_type']) ? strip_tags(trim($this->post['email_type'])) : '';
            $email_group_id = isset($this->post['email_group_id']) ? (int)$this->post['email_group_id'] : 0;
            $language = isset($this->post['language']) ? preg_replace('/[^a-z_]/', '', $this->post['language']) : 'en_us';
            $generate_html = in_array($this->post['generate_html'] ?? 'true', ['true', '1'], true);
            $generate_text = in_array($this->post['generate_text'] ?? 'true', ['true', '1'], true);
            $tone = isset($this->post['tone']) ? preg_replace('/[^a-z]/', '', $this->post['tone']) : 'professional';
            $is_html_template = in_array($this->post['is_html_template'] ?? 'false', ['true', '1'], true);

            if ($is_html_template) {
                $generate_html = true;
                $generate_text = false;
            }

            // Validate prompt
            if (empty($prompt)) {
                $response['error'] = Language::_('AdminCompanyEmails.ai.error_prompt_required', true);
                echo json_encode($response);
                return false;
            }

            // Limit prompt length to prevent abuse
            if (strlen($prompt) > 10000) {
                $response['error'] = Language::_('AdminCompanyEmails.ai.error_prompt_too_long', true);
                echo json_encode($response);
                return false;
            }

            if ($is_html_template) {
                $tagArray = ['{email_body}', '{signature}'];
            } else {
                // Get tags from database using email_group_id
                $this->uses(['Emails']);
                $template = $this->Emails->getByGroupId($email_group_id, $language);
                $tagArray = $template ? $template->tags : [];
            }

            // Build enhanced tag context
            $tagContext = '';

            try {
                $contextBuilder = new \Blesta\Core\Util\AI\EmailTagContextBuilder();

                // Get email context settings from system configuration
                $contextSettings = $this->getEmailContextSettings();

                // Build context data with configured settings
                $contextData = $contextBuilder->buildContextData($tagArray, [
                    'include_schemas' => $contextSettings['include_schemas'],
                    'include_examples' => $contextSettings['include_examples'],
                    'max_depth' => $contextSettings['depth']
                ]);

                // Format for LLM (use 'detailed' format - content controlled by checkboxes)
                $tagContext = $contextBuilder->formatForLLM($contextData, 'detailed');

                // Append any tags that weren't parsable to ensure they're still available
                $tagContext = $this->ensureAllTagsIncluded($tagContext, $tagArray);
            } catch (Exception $e) {
                // If context building fails, fall back to simple tag list
                $this->logger->warning('EmailTagContextBuilder error: ' . $e->getMessage());
                if (!empty($tagArray)) {
                    $tagContext = "Available Template Tags:\n" . implode(', ', array_map(function($t) {
                        $t = trim(str_replace(['{', '}'], '', $t));
                        return '{' . $t . '}';
                    }, $tagArray));
                }
            }

            // Build context-aware system prompt with enhanced tag context
            $system_prompt = $this->buildEmailTemplateSystemPrompt(
                $settings['ai_global_prompt'] ?? '',
                $email_type,
                $tagContext,
                $language,
                $tone,
                $is_html_template ? 'html' : 'email'
            );

            // Create conversation
            $conversation_id = $ai->createConversation(
                Configure::get('Blesta.company_id'),
                $this->Session->read('blesta_staff_id'),
                $settings['ai_default_model'] ?? 'openai/gpt-5.2',
                'Email Template Generation - ' . date('Y-m-d H:i:s'),
                'email_template'
            );

            // Build user prompt with format guidance
            $user_prompt = $prompt;
            if ($generate_html && $generate_text) {
                $user_prompt .= "\n\nGenerate both an HTML and a plain text version of this email.";
            } elseif ($generate_html) {
                $user_prompt .= "\n\nGenerate the HTML version only. Set the 'text' field to null in your response.";
            } else {
                $user_prompt .= "\n\nGenerate the plain text version only. Set the 'html' field to null in your response.";
            }

            // Single API call with structured JSON response
            $ai_response = $ai->chat($conversation_id, $user_prompt, [
                'system_prompt' => $system_prompt,
                'temperature' => 0.7
            ]);

            // Parse structured response
            $parser = new \Blesta\Core\Util\AI\AiResponseParser();
            $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();

            $parsed = $parser->parse($ai_response['content'], ['subject', 'html', 'text', 'feedback']);

            $generated = [];

            if ($generate_html && !empty($parsed['html'])) {
                $html = $this->sanitizeGeneratedHtml($parsed['html'], $is_html_template);
                $html = $sanitizer->unescapeTemplateTags($html);
                $generated['html'] = $sanitizer->decodeHrefTemplateTags($html);
            }

            if ($generate_text && !empty($parsed['text'])) {
                $generated['text'] = $sanitizer->sanitizeText($parsed['text']);
            }

            $response = [
                'success' => true,
                'subject' => $parsed['subject'] ?? null,
                'html' => $generated['html'] ?? null,
                'text' => $generated['text'] ?? null,
                'feedback' => $parsed['feedback'] ?? null,
                'conversation_id' => $conversation_id
            ];

        } catch (Exception $e) {
            // Log the actual error for debugging
            $this->logger->error('AI email generation error: ' . $e->getMessage());
            // Return generic error to user
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_generation_failed', true);
        }

        echo json_encode($response);
        return false;
    }

    /**
     * AJAX endpoint to preview the AI prompt for email template generation
     */
    public function previewEmailPrompt()
    {
        // Only accept AJAX POST requests
        if (!$this->isAjax()) {
            header('HTTP/1.0 403 Forbidden');
            return false;
        }

        $this->uses(['Settings']);

        $response = [
            'success' => false,
            'error' => 'Invalid request'
        ];

        // Check if AI is enabled and email templates feature is enabled
        $settings = $this->SettingsCollection->fetchSystemSettings($this->Settings);

        if (empty($settings['ai_enabled']) || $settings['ai_enabled'] !== 'true') {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_disabled', true);
            echo json_encode($response);
            return false;
        }

        if (empty($settings['ai_feature_email_templates'])
            || $settings['ai_feature_email_templates'] !== 'true') {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_feature_disabled', true);
            echo json_encode($response);
            return false;
        }

        // Check rate limiting
        if (!$this->checkAiRateLimit()) {
            $response['error'] = Language::_('AdminCompanyEmails.ai.error_rate_limit', true);
            echo json_encode($response);
            return false;
        }

        // Sanitize inputs
        $prompt = isset($this->post['prompt']) ? trim($this->post['prompt']) : '';
        $email_type = isset($this->post['email_type']) ? strip_tags(trim($this->post['email_type'])) : '';
        $email_group_id = isset($this->post['email_group_id']) ? (int)$this->post['email_group_id'] : 0;
        $language = isset($this->post['language']) ? preg_replace('/[^a-z_]/', '', $this->post['language']) : 'en_us';
        $tone = isset($this->post['tone']) ? preg_replace('/[^a-z]/', '', $this->post['tone']) : 'professional';
        $is_html_template = in_array($this->post['is_html_template'] ?? 'false', ['true', '1'], true);

        if ($is_html_template) {
            $tagArray = ['{email_body}', '{signature}'];
        } else {
            // Get tags from database using email_group_id
            $this->uses(['Emails']);
            $template = $this->Emails->getByGroupId($email_group_id, $language);
            $tagArray = $template ? $template->tags : [];
        }

        // Build enhanced tag context
        $tagContext = '';

        try {
            $contextBuilder = new \Blesta\Core\Util\AI\EmailTagContextBuilder();

            // Get email context settings from system configuration
            $contextSettings = $this->getEmailContextSettings();

            // Build context data with configured settings
            $contextData = $contextBuilder->buildContextData($tagArray, [
                'include_schemas' => $contextSettings['include_schemas'],
                'include_examples' => $contextSettings['include_examples'],
                'max_depth' => $contextSettings['depth']
            ]);

            // Format for LLM (use 'detailed' format - content controlled by checkboxes)
            $tagContext = $contextBuilder->formatForLLM($contextData, 'detailed');

            // Append any tags that weren't parsable to ensure they're still available
            $tagContext = $this->ensureAllTagsIncluded($tagContext, $tagArray);
        } catch (Exception $e) {
            // If context building fails, fall back to simple tag list
            if (!empty($tagArray)) {
                $tagContext = "Available Template Tags:\n" . implode(', ', array_map(function($t) {
                    $t = trim(str_replace(['{', '}'], '', $t));
                    return '{' . $t . '}';
                }, $tagArray));
            }
        }

        // Build the system prompt with enhanced tag context
        $system_prompt = $this->buildEmailTemplateSystemPrompt(
            $settings['ai_global_prompt'] ?? '',
            $email_type,
            $tagContext,
            $language,
            $tone,
            $is_html_template ? 'html' : 'email'
        );

        $response = [
            'success' => true,
            'full_prompt' => "=== SYSTEM PROMPT ===\n" . $system_prompt .
                "\n\n=== USER PROMPT ===\n" . $prompt
        ];

        echo json_encode($response);
        return false;
    }

    /**
     * Builds a context-aware system prompt for email template generation
     *
     * @param string $global_prompt The global AI system prompt
     * @param string $email_type The email template type/name
     * @param string $formatted_tag_context Formatted tag context with schema and examples
     * @param string $language The language code
     * @param string $tone The tone (professional, casual, technical)
     * @return string The formatted system prompt
     */
    private function buildEmailTemplateSystemPrompt(
        $global_prompt,
        $email_type,
        $formatted_tag_context,
        $language,
        $tone,
        $template_type = 'email'
    ) {
        $prompt = '';
        if (!empty($global_prompt)) {
            $prompt = $global_prompt . "\n\n";
        }

        switch ($template_type) {
            case 'html':
                $prompt .= "You are creating an HTML email wrapper template for a billing/client management system.\n";
                $prompt .= "This wrapper is a visual shell/frame that surrounds every outgoing email.\n\n";

                $prompt .= "Available Placeholder Tags (DOUBLE braces, use exactly as shown):\n";
                $prompt .= "- {{email_body}} — replaced at send time with the actual email content (subject, message, details)\n";
                $prompt .= "- {{signature}} — replaced at send time with the sender's signature block\n\n";

                $prompt .= "Output Requirements:\n";
                $prompt .= "- Language: " . $language . "\n";
                $prompt .= "- Tone: " . $tone . "\n";
                $prompt .= "- You MUST respond with valid JSON only, no other text\n\n";

                $prompt .= "Response Format (JSON):\n";
                $prompt .= "{\n";
                $prompt .= "  \"subject\": null,\n";
                $prompt .= "  \"html\": \"Complete standalone HTML wrapper document\",\n";
                $prompt .= "  \"text\": null,\n";
                $prompt .= "  \"feedback\": \"Any clarifying questions, assumptions made, or suggestions for improvement\"\n";
                $prompt .= "}\n\n";

                $prompt .= "CRITICAL: The 'html' field must contain ONLY the HTML wrapper document. ";
                $prompt .= "Any commentary, notes, or suggestions MUST go in the 'feedback' field.\n\n";

                $prompt .= "Guidelines:\n";
                $prompt .= "- MUST place {{email_body}} where the email content should appear\n";
                $prompt .= "- MUST place {{signature}} below the body content\n";
                $prompt .= "- Use ONLY the two tags listed above — do NOT invent or add any others\n";
                $prompt .= "- Use ONLY inline styles (style attribute) — do NOT use <style> tags, many email clients strip them\n";
                $prompt .= "- Structure: DOCTYPE, <html>, <head> (charset/viewport meta only), <body> with a centered container\n";
                $prompt .= "- Include a branded header area (logo placeholder or company name), content area for {{email_body}}, footer with {{signature}}\n";
                $prompt .= "- Max width 600px, centered, works on desktop and mobile\n";
                $prompt .= "- Use a clean, minimal design with sufficient white space\n";
                $prompt .= "- Do not hard-code any email content — the wrapper must work for any email type\n";
                break;
            case 'email':
            default:
                $prompt .= "You are generating email template content for a billing/client management system.\n";
                $prompt .= "Your task is to create professional, clear email content.\n\n";

                $prompt .= "Email Context:\n";
                if ($email_type) {
                    $prompt .= "- Email Type: " . $email_type . "\n";
                }

                $prompt .= "\n" . $formatted_tag_context . "\n";

                $prompt .= "\nOutput Requirements:\n";
                $prompt .= "- Language: " . $language . "\n";
                $prompt .= "- Tone: " . $tone . "\n";
                $prompt .= "- You MUST respond with valid JSON only, no other text\n\n";

                $prompt .= "Response Format (JSON):\n";
                $prompt .= "{\n";
                $prompt .= "  \"subject\": \"A suggested subject line for this email, or null to keep the existing subject\",\n";
                $prompt .= "  \"html\": \"Complete HTML email body with inline styles, or null if not requested\",\n";
                $prompt .= "  \"text\": \"Complete plain text email body with no HTML tags, or null if not requested\",\n";
                $prompt .= "  \"feedback\": \"Any clarifying questions, assumptions made, or suggestions for improvement\"\n";
                $prompt .= "}\n\n";

                $prompt .= "CRITICAL: The 'html' and 'text' fields must contain ONLY the email body content. ";
                $prompt .= "Any commentary, notes, or suggestions MUST go in the 'feedback' field.\n\n";

                $prompt .= "Guidelines:\n";
                $prompt .= "- Write clear, concise email content appropriate for the email type\n";
                $prompt .= "- Use the available template tags where appropriate for personalization\n";
                $prompt .= "- IMPORTANT: ONLY use the tags listed above. Do NOT create, invent, or use any other tags\n";
                $prompt .= "- IMPORTANT: All tags must use SINGLE braces like {tag}, NOT double braces like {{tag}}\n";
                $prompt .= "- IMPORTANT: All tags must use the EXACT format shown above (including braces and casing)\n";
                $prompt .= "- Keep the email professional and customer-focused\n";
                $prompt .= "- For the HTML version: Use ONLY inline styles (style attribute). Do NOT use <style> tags\n";
                $prompt .= "- For the text version: Do NOT include any HTML tags\n";
                $prompt .= "- Both versions should have identical content, just different formatting\n";
                $prompt .= "- Do not include email headers (From, To, Subject) in the body - just the body content\n";
                $prompt .= "- Avoid making specific promises about technical details unless explicitly mentioned\n";
                break;
        }

        return $prompt;
    }

    /**
     * Sanitizes generated HTML content for safe use in emails
     *
     * Uses HTMLPurifier for industry-standard HTML sanitization to prevent XSS attacks.
     * This is more secure and thorough than regex or basic DOM-based sanitization.
     *
     * @param string $html The HTML content to sanitize
     * @return string The sanitized HTML
     */
    private function sanitizeGeneratedHtml($html, bool $is_wrapper_template = false)
    {
        if (empty($html)) {
            return '';
        }

        $sanitizer = new \Blesta\Core\Util\AI\AiContentSanitizer();
        $html = $sanitizer->extractFromCodeFences($html);
        $html = $sanitizer->stripCodeFences($html);

        if ($is_wrapper_template) {
            return $this->sanitizeGeneratedWrapperHtml($html);
        }

        return $sanitizer->purifyHtml(
            $html,
            [
                'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'span', 'div',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'a[href|title|target]',
                'ul', 'ol', 'li',
                'table[width|cellpadding|cellspacing|border]',
                'thead', 'tbody', 'tfoot', 'tr', 'td[colspan|rowspan]', 'th[colspan|rowspan]',
                'img[src|alt|width|height]',
                'blockquote', 'pre', 'code', 'hr'
            ],
            [
                'color', 'background-color', 'background',
                'font-size', 'font-family', 'font-weight', 'font-style',
                'text-align', 'text-decoration',
                'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
                'border', 'border-color', 'border-width', 'border-style',
                'width', 'height', 'max-width', 'max-height',
                'line-height', 'vertical-align'
            ],
            // Don't force nofollow on links; allow target attribute as set by AI/user
            ['HTML.Nofollow' => false, 'HTML.TargetBlank' => false]
        );
    }

    /**
     * Sanitizes generated HTML wrapper template content.
     *
     * Wrapper templates are full HTML documents that surround every outgoing email.
     * HTMLPurifier does not process doc-level tags (html/head/body/doctype), so we
     * extract the body contents, purify them with an email-safe allowlist, then
     * re-wrap in a fixed, trusted boilerplate. The {{email_body}} and {{signature}}
     * placeholders are token-swapped across purification to guarantee they survive,
     * and {{email_body}} is re-injected if still absent afterward.
     *
     * @param string $html The HTML wrapper content to sanitize
     * @return string A safe, well-formed HTML email document
     */
    private function sanitizeGeneratedWrapperHtml($html)
    {
        if (!class_exists('HTMLPurifier_Config')) {
            require_once VENDORDIR . 'ezyang' . DS . 'htmlpurifier' . DS . 'library' . DS . 'HTMLPurifier.auto.php';
        }

        // Extract body contents via DOMDocument. Fragments get auto-wrapped by
        // loadHTML, so the same extraction works for both full-doc and fragment input.
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $bodyInnerHtml = '';
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            foreach ($body->childNodes as $child) {
                $bodyInnerHtml .= $doc->saveHTML($child);
            }
        } else {
            $bodyInnerHtml = $html;
        }

        // Token-swap the placeholder tags so HTMLPurifier cannot strip them
        $placeholders = [
            '{{email_body}}' => '__BLESTA_PLACEHOLDER_EMAIL_BODY__',
            '{{signature}}'  => '__BLESTA_PLACEHOLDER_SIGNATURE__',
        ];
        $bodyInnerHtml = str_replace(array_keys($placeholders), array_values($placeholders), $bodyInnerHtml);

        $config = HTMLPurifier_Config::createDefault();

        $cacheDir = CACHEDIR . 'htmlpurifier';
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        // Allowlist suited to email wrapper layouts (tables, inline styles)
        $config->set('HTML.Allowed', implode(',', [
            'p[style]', 'br', 'b', 'strong', 'i', 'em', 'u',
            'span[style|class]', 'div[style|class]', 'center',
            'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]', 'h5[style]', 'h6[style]',
            'a[href|title|target|style]',
            'ul[style]', 'ol[style]', 'li[style]',
            'table[width|cellpadding|cellspacing|border|align|bgcolor|style]',
            'thead', 'tbody', 'tfoot',
            'tr[style|bgcolor|align|valign]',
            'td[colspan|rowspan|width|height|align|valign|bgcolor|style]',
            'th[colspan|rowspan|width|height|align|valign|bgcolor|style]',
            'img[src|alt|width|height|style|border]',
            'hr[style]', 'blockquote[style]',
        ]));

        // Enable definitions for border-radius / per-corner variants (Proprietary)
        // and display / visibility / overflow / opacity (Tricky). The AllowedProperties
        // list below still gates what actually passes through.
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowTricky', true);

        $config->set('CSS.AllowedProperties', implode(',', [
            'color', 'background', 'background-color', 'background-image',
            'font', 'font-size', 'font-family', 'font-weight', 'font-style',
            'text-align', 'text-decoration', 'text-transform',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'border', 'border-color', 'border-width', 'border-style',
            'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-radius',
            'border-top-left-radius', 'border-top-right-radius',
            'border-bottom-left-radius', 'border-bottom-right-radius',
            'border-collapse', 'border-spacing',
            'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height',
            'line-height', 'vertical-align', 'display',
        ]));

        // Block javascript: / data: URI schemes; allow only safe web + mail schemes
        $config->set('URI.AllowedSchemes', [
            'http' => true, 'https' => true, 'mailto' => true,
        ]);

        $config->set('HTML.Nofollow', false);
        $config->set('HTML.TargetBlank', false);
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('Output.TidyFormat', false);

        $purifier = new HTMLPurifier($config);
        $purifiedBody = $purifier->purify($bodyInnerHtml);

        // Restore placeholder tags
        $purifiedBody = str_replace(array_values($placeholders), array_keys($placeholders), $purifiedBody);

        // Guarantee the required {{email_body}} placeholder is present
        if (strpos($purifiedBody, '{{email_body}}') === false) {
            $purifiedBody .= "\n{{email_body}}";
        }

        // Re-wrap in a trusted, email-safe document boilerplate
        return "<!DOCTYPE html>\n"
            . "<html>\n"
            . "<head>\n"
            . '<meta charset="UTF-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
            . "</head>\n"
            . '<body style="margin:0;padding:0;">' . "\n"
            . $purifiedBody . "\n"
            . "</body>\n"
            . '</html>';
    }

    /**
     * Parse comma-separated email tags into array
     *
     * @param string $tagsString Comma-separated tag string
     * @return array Array of cleaned tag strings
     */
    private function parseEmailTags($tagsString)
    {
        if (empty($tagsString)) {
            return [];
        }

        // Extract all {tag} patterns using regex to handle any separator (commas, newlines, spaces)
        preg_match_all('/\{([^}]+)\}/', $tagsString, $matches);
        $tags = $matches[1] ?? [];

        // Clean up each tag (trim whitespace)
        return array_map('trim', array_filter($tags));
    }

    /**
     * Ensure all tags from the original list are included in the context
     * Appends any missing tags to the context string
     *
     * @param string $context The formatted context string
     * @param array $allTags All original tags
     * @return string Updated context with all tags included
     */
    private function ensureAllTagsIncluded($context, array $allTags)
    {
        if (empty($allTags)) {
            return $context;
        }

        // Find tags that aren't mentioned in the context
        $missingTags = [];
        foreach ($allTags as $tag) {
            // Normalize: strip braces and H2O filters (e.g., |currency:invoice.currency:2)
            $cleanTag = trim(str_replace(['{', '}'], '', $tag));
            if (($pipePos = strpos($cleanTag, '|')) !== false) {
                $cleanTag = substr($cleanTag, 0, $pipePos);
            }
            $cleanTag = trim($cleanTag);
            if (empty($cleanTag)) {
                continue;
            }
            if (stripos($context, '{' . $cleanTag . '}') === false
                && stripos($context, '{' . $cleanTag . '.') === false
                && stripos($context, '{' . $cleanTag . '|') === false
            ) {
                $missingTags[] = $cleanTag;
            }
        }

        // If there are missing tags, append them
        if (!empty($missingTags)) {
            $context .= "\nADDITIONAL TAGS (schema not available):\n";
            foreach ($missingTags as $tag) {
                $context .= "{" . $tag . "}\n";
            }
        }

        return $context;
    }

    /**
     * Get email context settings from system configuration
     *
     * @return array Array with keys: depth, include_schemas, include_examples
     */
    private function getEmailContextSettings()
    {
        return [
            'depth' => (int)(Configure::get('Blesta.ai_email_context_depth') ?? 2),
            'include_schemas' => (Configure::get('Blesta.ai_email_context_schemas') ?? 'true') === 'true',
            'include_examples' => (Configure::get('Blesta.ai_email_context_examples') ?? 'true') === 'true'
        ];
    }

    /**
     * Check if the current staff member has exceeded the AI request rate limit
     *
     * @return bool True if request is allowed, false if rate limit exceeded
     */
    private function checkAiRateLimit()
    {
        $minute_key = floor(time() / 60);
        $session_key = 'ai_rate_limit_' . $minute_key;

        // Get current request count for this minute from session
        $request_count = (int)($this->Session->read($session_key) ?? 0);

        // Check if limit exceeded (15 requests per minute)
        $rate_limit = (int)(Configure::get('Blesta.ai_rate_limit_per_minute') ?? 15);
        if ($request_count >= $rate_limit) {
            return false;
        }

        // Increment counter in session
        $this->Session->write($session_key, $request_count + 1);

        return true;
    }
}
