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

        // Set the left nav for all settings pages to settings_leftnav
        $this->set(
            'left_nav',
            $this->partial('settings_leftnav', ['nav' => $this->Navigation->getCompany($this->base_uri)])
        );
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
        $this->uses(['EmailGroups', 'Emails', 'Languages', 'EmailHtmlTemplates']);
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

            $this->Emails->edit($template->id, $this->post);
            $errors = $this->Emails->errors();

            // Handle file uploads
            if (isset($this->files) && !empty($this->files)) {
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

                    $errors = $this->Upload->errors();

                    if (!empty($data) && !$errors) {
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

        // Include WYSIWYG
        $this->Javascript->setFile('blesta/ckeditor/build/ckeditor.js', 'head', VENDORWEBDIR);

        $this->set('template_name', $template_name);
        $this->set('status', $this->Emails->getStatusTypes());
        $this->set('templates', $templates);
        $this->set('signatures', $signatures);
        $this->set('email_template_groups', $email_template_groups);
        $this->set('vars', $vars);
        $this->set('template', $template);
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
        $this->uses(['EmailHtmlTemplates', 'Languages']);

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

        $this->set('tags', $tags);
        $this->set('languages', $languages);
        $this->set('vars', $vars);

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
}
