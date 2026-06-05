<?php

use Blesta\Core\Util\Captcha\Captcha;

/**
 * Administrative Login
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminLogin extends AppController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        // Set the default view before preAction so that
        // flash messages are shown in this message template
        $this->setDefaultView('default');

        parent::preAction();

        $this->uses(['Users', 'Companies']);
        Language::loadLang(['admin_login']);

        // If company specified as a parameter, set that as the desired log in company
        if (isset($this->get[0])) {
            $this->Session->write('blesta_company_id', $this->get[0]);

            // If already logged in, saved URI so we can load up the same URI under the other company
            if (isset($this->get['uri'])
                && $this->Session->read('blesta_id') > 0
                && $this->Session->read('blesta_staff_id') > 0
            ) {
                $this->redirect($this->get['uri']);
            }
        } else {
            $this->Session->write('blesta_company_id', $this->company_id);
        }

        // If logged in, redirect to admin main
        if (
            $this->Session->read('blesta_id') > 0
            && $this->Session->read('blesta_staff_id') > 0
            && ($this->action !== 'up' && $this->action !== 'drop' && $this->action !== 'extend')
        ) {
            $this->redirect($this->base_uri);
        }

        // Use the special admin login structure
        $this->structure_view = 'structure_admin_login';
    }

    /**
     * Configure the first account in the system, sets/fetches/verifies license key
     */
    public function setup()
    {
        // Ensure not fully installed
        if ($this->fullyInstalled()) {
            $this->redirect($this->base_uri . 'login/');
        }

        if (!empty($this->post)) {
            $this->uses(['Companies', 'Emails', 'License', 'PluginManager']);

            // Handle requesting free trial
            if ($this->post['enter_key'] == 'false') {
                $this->post['license_key'] = $this->License->requestTrial($this->post);
            }

            // Set license key
            $this->License->updateLicenseKey($this->post['license_key']);

            $trans_began = false;
            if (!($errors = $this->License->errors())) {
                // Create the user and staff account
                $trans_began = true;
                $this->Users->begin();
                $user = $this->post;
                $user['new_password'] = $user['password'];
                $user_id = $this->Users->add($user);

                if (!($errors = $this->Users->errors())) {
                    $staff = [
                        'user_id' => $user_id,
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'email' => $user['email'],
                        'groups' => [1] // assign to the intial Administrators group
                    ];

                    $staff_id = $this->Staff->add($staff);

                    if (!($errors = $this->Staff->errors())) {
                        $this->Users->commit();

                        // Set hostname
                        if (isset($_SERVER['SERVER_NAME'])) {
                            // Update all email from addresses using the current host name
                            $this->Emails->updateFromDomain(
                                $_SERVER['SERVER_NAME'],
                                Configure::get('Blesta.company_id')
                            );

                            $this->Companies->edit(
                                Configure::get('Blesta.company_id'),
                                ['hostname' => $_SERVER['SERVER_NAME']]
                            );
                        }

                        // Set default widget displays
                        $widgets = [
                            'widget_staff_home' => [
                                $this->PluginManager->systemHash('widget_system_overview_admin_main') => [
                                    'open' => true,
                                    'width' => 'full',
                                    'column' => null,
                                    'order' => 0
                                ],
                                $this->PluginManager->systemHash('widget_feed_reader_admin_main') => [
                                    'open' => true,
                                    'width' => 'half',
                                    'column' => 0,
                                    'order' => 1
                                ],
                                $this->PluginManager->systemHash('widget_system_status_admin_main') => [
                                    'open' => true,
                                    'width' => 'half',
                                    'column' => 0,
                                    'order' => 2
                                ],
                            ],
                            'widget_staff_billing' => [
                                $this->PluginManager->systemHash('widget_billing_overview_admin_main') => [
                                    'open' => true,
                                    'width' => 'full',
                                    'column' => null,
                                    'order' => 0
                                ],
                                $this->PluginManager->systemHash('widget_order_admin_main') => [
                                    'open' => true,
                                    'width' => 'full',
                                    'column' => null,
                                    'order' => 1
                                ]
                            ]
                        ];
                        foreach ($widgets as $widget_location => $widgets_state) {
                            match ($widget_location) {
                                'widget_staff_home' => $this->Staff->saveHomeWidgetsState(
                                    $staff_id,
                                    Configure::get('Blesta.company_id'),
                                    $widgets_state
                                ),
                                'widget_staff_billing' => $this->Staff->saveBillingWidgetsState(
                                    $staff_id,
                                    Configure::get('Blesta.company_id'),
                                    $widgets_state
                                )
                            };
                        }

                        $this->License->loadRemoteConfig($this->post['license_key']);

                        // Send the subscription for newsletters
                        if (substr($this->post['license_key'], 0, 6) == 'trial-' || !empty($this->post['newsletter'])) {
                            $this->sendNotification($this->post['license_key'], $staff);
                        }

                        // Auto-login (not really, but we already have the username and password in $this->post)
                        $this->index();
                    }
                }
            }

            if ($errors) {
                if ($trans_began) {
                    $this->Users->rollback();
                }

                $this->setMessage('error', $errors);
                $this->set('vars', $this->post);
            }
        }
    }

    /**
     * Handle login attempts
     */
    public function index()
    {
        // Check if fully installed, if not fully installed, complete installation
        if (!$this->fullyInstalled()) {
            $this->redirect($this->base_uri . 'login/setup');
        }

        if ($this->Session->read('blesta_auth') != '') {
            $this->forwardPostAuth();
        }

        // Get captcha instance
        $captcha = null;
        if (Captcha::enabled('admin_login')) {
            $captcha = Captcha::get();
        }

        if (!empty($this->post)) {
            // Ensure the IP address is determined automatically by disallowing it from being set
            unset($this->post['ip_address']);

            // Validate captcha
            if ($captcha !== null) {
                $success = Captcha::validate($captcha, $this->post);

                if (!$success) {
                    $errors = [
                        'captcha' => ['invalid' => Language::_('AdminLogin.!error.captcha.invalid', true)]
                    ];
                }
            }

            // Attempt to log user in
            if (empty($errors)) {
                $this->Users->login($this->Session, $this->post);

                if (($errors = $this->Users->errors())) {
                    $this->setMessage('error', $errors);
                    $this->set('vars', (object) $this->post);
                } else {
                    $this->forwardPostAuth();
                }
            } else {
                $this->setMessage('error', $errors);
                $this->set('vars', (object)$this->post);
            }
        }

        $this->set('captcha', ($captcha !== null ? $captcha->buildHtml() : ''));
    }

    /**
     * Handle otp requests
     */
    public function otp()
    {
        if ($this->Session->read('blesta_auth') == '') {
            $this->redirect($this->base_uri . 'login/');
        }

        if (!empty($this->post)) {
            // Ensure the IP address is determined automatically by disallowing it from being set
            unset($this->post['ip_address']);

            // Attempt to log user in
            $this->Users->login($this->Session, $this->post);

            if (($errors = $this->Users->errors())) {
                $this->setMessage('error', $errors);
                $this->set('vars', (object) $this->post);
            } else {
                $this->forwardPostAuth();
            }
        }

        $this->setMessage('info', Language::_('AdminLogin.!info.otp', true));
    }

    /**
     * Reset password
     */
    public function reset()
    {
        $this->uses(['Staff', 'Emails', 'PasswordResets']);

        // Get captcha instance
        $captcha = null;
        if (Captcha::enabled('admin_login_reset')) {
            $captcha = Captcha::get();
        }

        if (!empty($this->post)) {
            // Validate captcha
            if ($captcha !== null) {
                $success = Captcha::validate($captcha, $this->post);

                if (!$success) {
                    $errors = [
                        'captcha' => ['invalid' => Language::_('AdminLogin.!error.captcha.invalid', true)]
                    ];
                }
            }

            if (empty($errors)) {
                // Send reset password email
                $sent = Configure::get('Blesta.default_password_reset_value');
                if (isset($this->post['username']) && ($user = $this->Users->getByUsername($this->post['username']))) {
                    // Send reset password email
                    $staff = $this->Staff->getByUserId($user->id);
                    if ($staff && $staff->status == 'active') {
                        // Get the company hostname
                        $hostname = isset(Configure::get('Blesta.company')->hostname)
                            ? Configure::get('Blesta.company')->hostname
                            : '';
                        $requestor = $this->getFromContainer('requestor');

                        $token = $this->PasswordResets->add($user->id, $staff->email);
                        $tags = [
                            'staff' => $staff,
                            'ip_address' => $requestor->ip_address,
                            'password_reset_url' => $this->Html->safe(
                                $hostname . $this->base_uri . 'login/confirmreset/?sid=' . rawurlencode($token)
                            )
                        ];
                        $this->Emails->send(
                            'staff_reset_password',
                            $this->company_id,
                            Configure::get('Blesta.language'),
                            $staff->email,
                            $tags,
                            isset($user->recovery_email) ? [$user->recovery_email] : null
                        );
                        $sent = true;
                    }
                }

                if ($sent) {
                    $this->setMessage('message', Language::_('AdminLogin.!success.reset_sent', true));
                } else {
                    $this->setMessage('error', Language::_('AdminLogin.!error.unknown_user', true));
                }
            } else {
                $this->setMessage('error', $errors);
                $this->set('vars', (object)$this->post);
            }
        }

        $this->setMessage('info', Language::_('AdminLogin.!info.reset_password', true));
        $this->set('captcha', ($captcha !== null ? $captcha->buildHtml() : ''));
    }

    /**
     * Confirm password reset
     */
    public function confirmReset()
    {
        $this->uses(['Staff', 'PasswordResets']);

        // Verify parameters
        if (!isset($this->get['sid'])) {
            $this->redirect($this->base_uri . 'login/');
        }

        // Fetch token
        $token = $this->PasswordResets->get($this->get['sid']);
        if (!$token) {
            $this->redirect($this->base_uri . 'login/');
        }

        // Validate token
        if (!$this->PasswordResets->validate($this->get['sid'])) {
            $this->redirect($this->base_uri . 'login/');
        }

        // Attempt to update the user's password and log in
        if (!empty($this->post)) {
            $staff = $this->Staff->getByUserId($token->user_id);

            if ($staff && $staff->status == 'active' && $this->PasswordResets->validate($this->get['sid'])) {
                // Update the user's password (only allow password fields to prevent mass assignment)
                $user_vars = [
                    'new_password' => $this->post['new_password'],
                    'confirm_password' => $this->post['confirm_password'] ?? null,
                ];
                $this->Users->edit($token->user_id, $user_vars);

                if (!($errors = $this->Users->errors())) {
                    $this->post['username'] = $staff->username;
                    $this->post['password'] = $this->post['new_password'];

                    // Ensure the IP address is determined automatically by disallowing it from being set
                    unset($this->post['ip_address']);

                    // Attempt to log user in
                    $this->Users->login($this->Session, $this->post);

                    $this->PasswordResets->deleteByHash($token->token);
                    $this->forwardPostAuth();
                } else {
                    $this->setMessage('error', $errors);
                }
            }
        }
    }

    /**
     * Handle step up authentication
     */
    public function up()
    {
        $this->setDefaultView('default');

        $this->uses(['Users']);

        // Get current user
        $staff = $this->Staff->get($this->Session->read('blesta_staff_id'), $this->company_id);
        $user = $this->Users->get($staff->user_id ?? null);
        if (!$user) {
            $this->Session->clear();
            $this->flashMessage('error', Language::_('Users.!error.username.auth', true));
            $this->redirect($this->base_uri . 'login');
        }

        // Validate credentials
        if (!empty($this->post)) {
            $authenticated = false;
            if (($user->two_factor_mode ?? null) !== 'none') {
                $authenticated = $this->Users->validateOtp(($this->post['stepup_password'] ?? null), $user);
            } else {
                $authenticated = $this->Users->auth($user->username, $this->post, 'staff');
            }

            if ($authenticated) {
                $this->Session->write('blesta_step_up', time() + Configure::get('Blesta.session_ttl'));

                $forward_to = $this->get['uri'] ?? null;
                if (empty($forward_to)) {
                    $forward_to = $this->base_uri;
                }

                $this->redirect($forward_to);
            }
        }

        $this->set('two_factor_mode', $user->two_factor_mode ?? 'none');
        $this->setMessage(
            'info',
            Language::_('AdminLogin.!info.step_up' . (($user->two_factor_mode ?? 'none') !== 'none' ? '_otp' : ''), true)
        );
    }

    /**
     * Handle step up authentication
     */
    public function drop()
    {
        $this->Session->clear('blesta_step_up');

        if ($this->isAjax()) {
            $this->outputAsJson(['success' => true]);
            return false;
        }

        $this->redirect($this->base_uri . 'settings/company/');
    }

    /**
     * Extend step up authentication session
     */
    public function extend()
    {
        if (
            !$this->isAjax()
            || strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        ) {
            $this->outputAsJson([
                'success' => false,
                'message' => Language::_('AppController.!error.invalid_csrf', true)
            ]);
            return false;
        }

        $step_up = $this->Session->read('blesta_step_up');

        if (empty($step_up) || time() >= (int) $step_up) {
            $this->Session->clear('blesta_step_up');
            $this->outputAsJson([
                'success' => false,
                'message' => Language::_('AdminLogin.!error.step_up_expired', true)
            ]);
            return false;
        }

        $expires_at = time() + Configure::get('Blesta.session_ttl');
        $this->Session->write('blesta_step_up', $expires_at);

        $this->outputAsJson([
            'success' => true,
            'expires_at' => $expires_at,
            'message' => Language::_('AdminLogin.!success.step_up_extended', true)
        ]);
        return false;
    }

    /**
     * Finishes logging in the staff member and forwards the user off to the desired location
     */
    private function forwardPostAuth()
    {
        if ($this->Session->read('blesta_id')) {
            $this->uses(['Staff', 'StaffGroups']);

            $staff = $this->Staff->getByUserId($this->Session->read('blesta_id'));

            if (!$staff) {
                $this->Session->clear();
                $this->flashMessage('error', Language::_('Users.!error.username.auth', true));
                $this->redirect($this->base_uri . 'login');
            }

            // Set the appropriate Company ID and Staff ID values for this session
            $groups = $this->StaffGroups->getUsersGroups($this->Session->read('blesta_id'));
            $num_groups = count($groups);

            // Ensure that the desired company ID is available to this staff member
            $staff_id = null;
            for ($i = 0; $i < $num_groups; $i++) {
                if ($this->Session->read('blesta_company_id') == $groups[$i]->company_id) {
                    $staff_id = $staff->id;
                    break;
                }
            }

            // Company ID wasn't available so assign to the 1st available company if possible
            // else the user can not log in because they are not assigned to any companies
            if (!$staff_id) {
                if ($num_groups > 0) {
                    $this->Session->write('blesta_company_id', $groups[0]->company_id);
                    $staff_id = $staff->id;
                } else {
                    $this->Session->clear();
                    $this->flashMessage('error', Language::_('StaffGroups.!error.no_company_id.exists', true));
                    $this->redirect($this->base_uri . 'login');
                }
            }
            $this->Session->write('blesta_staff_id', $staff_id);

            // Detect if we should forward after logging in and do so
            if (isset($this->post['forward_to'])) {
                $forward_to = $this->post['forward_to'];
            } else {
                // Only forward to the URL if it is in the logged-in interface
                $forward_to = $this->Session->read('blesta_forward_to');
                $forward_to = (
                    strtolower($forward_to) !== strtolower(str_ireplace($this->base_uri, '', $forward_to))
                        ? $forward_to
                        : null
                );
            }

            $this->Session->clear('blesta_forward_to');
            if (!$forward_to) {
                $forward_to = $this->base_uri;
            }

            $this->redirect($forward_to);
        } else {
            // Requires OTP auth
            $this->redirect($this->base_uri . 'login/otp');
        }
    }

    /**
     * Checks whether the system is fully installed
     *
     * @return bool True if the system is fully installed, false otherwise
     */
    private function fullyInstalled()
    {
        $this->uses(['Staff', 'Settings']);

        $license_key = $this->Settings->getSetting('license_key');

        if ($this->Staff->getListCount() > 0 && ($license_key && $license_key->value != '')) {
            return true;
        }
        return false;
    }

    /**
     * Send a notification to Blesta
     *
     * @param string $license_key The license key
     * @param array $staff An array of key/value pairs representing the staff user
     */
    private function sendNotification($license_key, array $staff)
    {
        $this->components(['Net', 'Security']);
        $this->Http = $this->Net->create('Http');

        $rsa = $this->Security->create('Crypt', 'RSA');
        $rsa->loadKey($this->getAccountPublicKey());

        $data = [
            'license_key' => $license_key,
            'first_name' => $staff['first_name'],
            'last_name' => $staff['last_name'],
            'email' => $staff['email'],
            'version' => BLESTA_VERSION,
            'install_url' => rtrim($this->base_url, '/') . $this->public_uri
        ];

        $this->Http->post(
            'https://account.blesta.com/plugin/license_journey/callback/install/2/',
            ['data' => base64_encode($rsa->encrypt(json_encode($data)))]
        );
    }

    /**
     * The account public key
     *
     * @return string The public key
     */
    private function getAccountPublicKey()
    {
        return <<<ACCOUNTPUBLICKEY
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuzwHR+PqoW5jKCl2gTao
NvbLLwFRz0YxiRIqkGyxfTJx/xp1U1AFPgeen+aTXTcF9kbtNj0r+CVAnycNU+UM
P75Lpw+mHkRFrJ/qwLNqSfsukCVmxWqVE7bqPkR5p+hTHkptJt980RQr+540igLO
ZYwNO6iP5l5XX5MJdpGLNqzGNx7sUMphYCWZ2c+ZBolUfth9kEy35uUZ4wiE7U6r
ToEKg7S6FtnFsIpgHybpVMWCWh5/ZWUZvrIxVN9rnpJP+jlPFcfwCbRTg9ooguex
R3iAgw8y9KJqB5gA1wMsD+QpxdIZS0u4nQ9860zR8d+rKv5bZx7E2p5J1EVfpMBu
kQIDAQAB
-----END PUBLIC KEY-----
ACCOUNTPUBLICKEY;
    }
}
