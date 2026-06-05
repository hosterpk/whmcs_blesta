<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;
use Loader;
use Session;
use stdClass;

/**
 * User management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Users extends AppModel
{
    /**
     * Initialize Users
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['users']);
    }

    /**
     * Attempts to authenticate the given user and initialize a session with
     * that user's ID.
     *
     * @param Session The session to initialize
     * @param array $vars An array of login information including:
     *
     *  - username The username of the user attempting to log in
     *  - password The password of the user attempting to log in
     *  - remember_me If "true" will set a cookie to remember the user's session on a subsequent visit
     *  - otp The one-time password required to authenticate this user (required only if enabled for this user)
     *  - ip_address The IP address of the user attempting to log in (determined automatically if not given)
     * @return int The ID of the user authenticated, false on failure
     */
    public function login(Session $session, array $vars)
    {
        // Load the auth component, to handle various authentication requests
        Loader::loadComponents($this, ['Auth']);

        #
        # TODO: Add support for LDAP, OpenID and others
        #

        // Placeholder for user details during Users::auth request (saves us a query)
        $user = null;
        $vars['user'] = & $user;
        $otp = false;

        if (!isset($vars['ip_address'])) {
            $requestor = $this->getFromContainer('requestor');
            $vars['ip_address'] = $requestor->ip_address;
        }

        // Remove partial login if attempting a new login
        if (isset($vars['username'])) {
            $session->clear('blesta_auth');
        }

        if (($user_id = $session->read('blesta_auth')) != '') {
            $user = $this->get($user_id);

            $otp = true;
            // Validate OTP
            $rules = [
                'otp' => [
                    'auth' => [
                        'rule' => [[$this, 'validateOtp'], $user],
                        'message' => $this->_('Users.!error.otp.auth')
                    ]
                ]
            ];
        } else {
            $rules = [
                'username' => [
                    'attempts' => [
                        'rule' => [[$this, 'validateLoginAttempts'], ($vars['ip_address'] ?? null)],
                        'message' => $this->_('Users.!error.username.attempts'),
                        // prevent disclosing information about username/passwords
                        // also prevent DOS due to Users::auth() executing
                        'final' => true
                    ],
                    'auth' => [
                        'rule' => [[$this, 'auth'], $vars],
                        'message' => $this->_('Users.!error.username.auth'),
                        // Prevent disclosing information about username/passwords
                        // also prevent DOS due to further error checking the company rule
                        'final' => true
                    ],
                    'company' => [
                        'rule' => function ($username) {
                            // Fetch the user
                            $user = $this->getByUsername($username);

                            // This rule only considers actual users and is valid without one
                            if (!$user) {
                                return true;
                            }

                            // Ensure the user belongs to this company
                            $staff = $this->Record->select(['id'])
                                ->from('staff')
                                ->where('user_id', '=', $user->id)
                                ->fetch();

                            // Check whether the staff belongs to this company
                            if ($staff) {
                                $group = (bool) $this->Record->select(['staff_groups.id'])
                                    ->from('staff_groups')
                                    ->innerJoin(
                                        'staff_group',
                                        'staff_group.staff_group_id',
                                        '=',
                                        'staff_groups.id',
                                        false
                                    )
                                    ->where('staff_group.staff_id', '=', $staff->id)
                                    ->where('staff_groups.company_id', '=', Configure::get('Blesta.company_id'))
                                    ->fetch();

                                return $group;
                            }

                            // Check whether the client/contact belongs to this company
                            $group = (bool) $this->Record->select(['client_groups.id'])
                                ->from('client_groups')
                                ->innerJoin('clients', 'clients.client_group_id', '=', 'client_groups.id', false)
                                ->innerJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)
                                ->open()
                                    ->where('clients.user_id', '=', $user->id)
                                    ->orWhere('contacts.user_id', '=', $user->id)
                                ->close()
                                ->where('client_groups.company_id', '=', Configure::get('Blesta.company_id'))
                                ->fetch();

                            return $group;
                        },
                        'message' => $this->_('Users.!error.username.company')
                    ]
                ]
            ];
        }

        $this->Input->setRules($rules);

        if ($this->Input->validates($vars)) {
            // Clear any persistent session cookie already set and regenerate the session
            // We'll reinitiate it if necessary
            $session->clearSessionCookie('/', '', false, true);

            if ($otp || $user->two_factor_mode == 'none') {
                // Remove partial login
                $session->clear('blesta_auth');
                // Log user in, OTP validates
                $session->write('blesta_id', $user->id);

                $session->write('ip', $vars['ip_address']);

                // Set a cookie so the session will persist
                if (
                    (isset($vars['remember_me']) && $vars['remember_me'] == 'true')
                    || $session->read('blesta_session_cookie') === true
                ) {
                    // Set the persistent session cookie
                    $session->setSessionCookie('/', '', false, true);
                    // Clear the session reminder that told us the user wanted a persistent session cookie
                    $session->clear('blesta_session_cookie');
                }

                if (!isset($this->Logs)) {
                    Loader::loadModels($this, ['Logs']);
                }

                // Log this user
                $log = [
                    'user_id' => $user->id,
                    'ip_address' => ($vars['ip_address'] ?? null),
                    'company_id' => Configure::get('Blesta.company_id'),
                    'result' => 'success'
                ];

                $this->Logs->addUser($log);

                // Handle successful login event
                $eventFactory = $this->getFromContainer('util.events');
                $eventListener = $eventFactory->listener();
                $eventListener->register('Users.login');
                $eventListener->trigger(
                    $eventFactory->event('Users.login', ['user_id' => $session->read('blesta_id')])
                );
            } else {
                // user requires OTP, partial log in
                $session->write('blesta_auth', $user->id);

                // This user requested to make this session persist, pass that along
                if (isset($vars['remember_me']) && $vars['remember_me'] == 'true') {
                    $session->write('blesta_session_cookie', true);
                }
            }

            return $user->id;
        } elseif (!$otp) {
            if (!isset($this->Logs)) {
                Loader::loadModels($this, ['Logs']);
            }

            $user = $this->getByUsername($vars['username']);

            // Only log if user exists
            if ($user) {
                // Log this user
                $log = [
                    'user_id' => $user->id,
                    'ip_address' => ($vars['ip_address'] ?? null),
                    'company_id' => Configure::get('Blesta.company_id'),
                    'result' => 'failure'
                ];

                $this->Logs->addUser($log);
            }

            // Handle unsuccesful login event
            //Events::execute(array("UserEvents", "loginFail"), $vars);
        }

        return false;
    }

    /**
     * Logs the user out by terminating the session
     *
     * @param Session The session to terminate
     */
    public function logout(Session $session)
    {
        // Handle logout event
        $eventFactory = $this->getFromContainer('util.events');
        $eventListener = $eventFactory->listener();
        $eventListener->register('Users.logout');
        $eventListener->trigger($eventFactory->event('Users.logout', ['user_id' => $session->read('blesta_id')]));

        // Log the user out
        $session->clear();

        // Clear any persistent session cookie already set, we'll reinitiate it if necessary
        $session->clearSessionCookie('/', '', false, true);
    }

    /**
     * Checks to ensure that the user specified can be authenticated
     *
     * @param string $username The ID or username of the user to authenticate
     * @param array $vars an array of authentication info including:
     *
     *  - username The username of the user attempting to log in
     *  - password The password of the user attempting to log in
     * @param string $type The type of user to authenticate:
     *
     *  - any Will check any type
     *  - staff Check staff type only
     *  - client Check client type only
     *  - contact Check contact type only
     * @return bool True if the user can be authenticated, false otherwise
     */
    public function auth($username, array $vars, $type = 'any')
    {
        if (!isset($vars['username'])) {
            $vars['username'] = $username;
        }

        $this->Record->select(['users.*'])->from('users')->
            where('users.username', '=', $vars['username']);

        switch ($type) {
            case 'any':
                $this->Record->leftJoin('staff', 'staff.user_id', '=', 'users.id', false)->
                    leftJoin('clients', 'clients.user_id', '=', 'users.id', false)->
                    leftJoin('contacts', 'contacts.user_id', '=', 'users.id', false)->
                    leftJoin(['clients' => 'contact_client'], 'contact_client.id', '=', 'contacts.client_id', false)->
                    open()->
                        where('staff.status', '=', 'active')->
                        orWhere('clients.status', '=', 'active')->
                        open()->
                            // Enable when contact login added
                            orWhere('contacts.id', '!=', null)->
                            where('contact_client.status', '=', 'active')->
                        close()->
                    close();
                break;
            case 'staff':
                $this->Record->innerJoin('staff', 'staff.user_id', '=', 'users.id', false)->
                    where('staff.status', '=', 'active');
                break;
            case 'client':
                $this->Record->innerJoin('clients', 'clients.user_id', '=', 'users.id', false)->
                    where('clients.status', '=', 'active');
                break;
            case 'contact':
                // Enable when contact login added
                $this->Record->innerJoin('contacts', 'contacts.user_id', '=', 'users.id', false)->
                    innerJoin('clients', 'clients.id', '=', 'contacts.client_id', false)->
                    where('clients.status', '=', 'active');
                break;
        }

        $user = $this->Record->fetch();

        $authorized = false;
        if ($user) {
            if ($this->checkPassword($vars['password'], $user->password)) {
                $authorized = true;
            } elseif (
                Configure::get('Blesta.auth_legacy_passwords')
                && $this->checkPassword(
                    $vars['password'],
                    $user->password,
                    Configure::get('Blesta.auth_legacy_passwords_algo')
                )
            ) {
                $authorized = true;

                // Upgrade user password
                $this->edit(
                    $user->id,
                    ['new_password' => $vars['password'], 'confirm_password' => $vars['password']],
                    false
                );
            }
        }

        if (!$authorized) {
            $user = false;
        }

        $vars['user'] = $user;

        return $authorized;
    }

    /**
     * Validates the given OTP against the given user
     *
     * @param string $otp The one-time password required to authenticate this
     *  user (required only if enabled for this user)
     * @param stdClass $user The stdClass object representation of the user to validate the OTP against
     * @return bool True if the OTP validates, false otherwise
     */
    public function validateOtp($otp, $user)
    {
        if (!$user) {
            return false;
        }

        if ($otp == '' || (strlen($otp) > 16)) {
            return false;
        }

        if (!isset($this->Auth) || !($this->Auth instanceof Auth)) {
            Loader::loadComponents($this, ['Auth']);
        }

        // Check if OTP has already been used
        if ($this->getOtp($user->id, $otp)) {
            return false;
        }

        // Record the OTP as having been used
        $this->addOtp($user->id, $otp);

        // Validate submitted 2-factor data
        switch ($user->two_factor_mode) {
            case 'motp':
                $motp = $this->Auth->create('motp', [$user->two_factor_pin, $user->two_factor_key]);

                // If OTP fails, return false
                if (!$motp->checkOtp($otp, time())) {
                    return false;
                }
                break;
            case 'totp':
                $oath = $this->Auth->create('oath', [$user->two_factor_key]);

                // If OTP fails, return false
                if (!$oath->checkTotp($otp, time())) {
                    return false;
                }
                break;
            case 'none':
                return true;
            default:
                return false;
        }
        return true;
    }

    /**
     * Adds the user record to the system
     *
     * @param array $vars An array of user info including:
     *
     *  - username The username for this user. Must be unique across all companies for this installation.
     *  - new_password The password for this user
     *  - confirm_password The password for this user
     *  - recovery_email The email to which password reset emails should be sent
     *  - two_factor_mode The two factor authentication mode 'none', 'motp', 'totp' (optional, default 'none')
     *  - two_factor_key The two factor authentication key (optional, default null)
     *  - two_factor_pin The two factor authentication pin (optional, default null)
     * @return int The ID of the user created, void on error
     */
    public function add(array $vars)
    {
        $vars = $this->adjustInput($vars);

        if ($this->validateUser($vars)) {
            // Set password and date added
            $vars['password'] = $this->hashPassword($vars['new_password']);
            $vars['date_added'] = date('Y-m-d H:i:s');

            // Add a user
            $fields = ['username', 'password', 'recovery_email', 'two_factor_mode', 'two_factor_key', 'two_factor_pin', 'date_added'];
            $this->Record->insert('users', $vars, $fields);

            $user_id = $this->Record->lastInsertId();

            return $user_id;
        }
    }

    /**
     * Edits the user record in the system
     *
     * @param int $user_id The ID of the user to edit
     * @param array $vars An array of user info including:
     *
     *  - username The username for this user (optional)
     *  - current_password The current password for this user (optional, required if $validate_pass is true)
     *  - new_password The new password for this user (optional)
     *  - confirm_password The new password for this user (optional, required if 'new_password' is given)
     *  - recovery_email The email to which password reset emails should be sent
     *  - two_factor_mode The two factor authentication mode 'none', 'motp', 'totp' (optional)
     *  - two_factor_key The two factor authentication key (optional)
     *  - two_factor_pin The two factor authentication pin (optional)
     *  - otp The one-time-password to validate, required if two_factor_mode
     *      is something other than 'none' and $validate_pass is set to true
     *  - verify Whether or not the email should be verified, overrides the company and client group settings
     *     (Only applies to users who use their email address as a username)
     * @param bool $validate_pass Whether or not to validate the
     *      current_password before updating this user (optional, default
     *      false). If set will also attempt to validate the one-time-password.
     */
    public function edit($user_id, array $vars, $validate_pass = false)
    {
        // Set user ID
        $vars['user_id'] = $user_id;
        if ($this->validateUser($vars, true, $validate_pass)) {
            $vars = $this->adjustInput($vars, true);

            $fields = ['username', 'recovery_email', 'two_factor_mode', 'two_factor_key', 'two_factor_pin'];
            foreach ($fields as $i => $field) {
                if (!array_key_exists($field, $vars)) {
                    unset($fields[$i]);
                }
            }

            // Set email address for verification
            Loader::loadModels($this, ['Clients', 'Contacts', 'ClientGroups', 'EmailVerifications']);
            Loader::loadHelpers($this, ['Form']);

            if (($client = $this->Clients->getByUserId($user_id, true))) {
                $settings = $this->ClientGroups->getSettings($client->client_group_id);
                $settings = $this->Form->collapseObjectArray($settings, 'value', 'key');

                $vars['verify'] = isset($vars['verify'])
                    ? (bool)$vars['verify']
                    : ($settings['email_verification'] == 'true');

                if (
                    isset($vars['username'])
                    && $vars['verify']
                ) {
                    if (!($contact = $this->Contacts->getByUserId($user_id, $client->id))) {
                        $contact = $this->Contacts->get($client->contact_id);
                    }

                    $user = $this->get($user_id);
                    if (
                        $user->username == $contact->email
                        && $user->username !== $vars['username']
                    ) {
                        // Prevent saving the new email address
                        $vars['username'] = $contact->email;
                    }
                }
            }

            // Replace old password with new
            if (!empty($vars['new_password'])) {
                $vars['password'] = $this->hashPassword($vars['new_password']);
                $fields[] = 'password';
            }

            // Update avatar
            if (!empty($vars['avatar'])) {
                // Delete old avatar
                $user = $this->get($user_id);
                if ($user->avatar != $vars['avatar']) {
                    @unlink($user->avatar);
                }

                // Save new avatar
                $avatar = [
                    'user_id' => $user_id,
                    'file_name' => $vars['avatar']
                ];
                $this->Record->duplicate('file_name', '=', $avatar['file_name'])->
                    insert('user_avatars', $avatar);
            } elseif (array_key_exists('avatar', $vars) && $vars['avatar'] === null) {
                // Remove avatar
                $user = $this->get($user_id);
                if (!empty($user->avatar)) {
                    @unlink($user->avatar);
                    $this->Record->from('user_avatars')->where('user_id', '=', $user_id)->delete();
                }
            }

            if (empty($fields)) {
                return;
            }

            // Update a user
            $this->Record->where('id', '=', $user_id)->update('users', $vars, $fields);
        }
    }

    /**
     * Permanently deletes a user record from the system. USE WITH EXTREME CAUTION
     *
     * @param int $user_id The ID of the user to delete
     */
    public function delete($user_id)
    {
        // Nothing to delete
        if (!($user = $this->get($user_id))) {
            return;
        }

        $rules = [
            'clients' => [
                'exist' => [
                    'rule' => [[$this, 'validateClientsExist']],
                    'negate' => true,
                    'message' => $this->_('Users.!error.clients.exist')
                ]
            ]
        ];
        $vars = ['clients' => $user_id];

        $this->Input->setRules($rules);

        if ($this->Input->validates($vars)) {
            $this->Record->from('users')
                ->leftJoin('user_otps', 'user_otps.user_id', '=', 'users.id', false)
                ->leftJoin('user_avatars', 'user_avatars.user_id', '=', 'users.id', false)
                ->where('users.id', '=', $user_id)
                ->delete(['users.*', 'user_otps.*', 'user_avatars.*']);

            // Delete avatar
            if (file_exists($user->avatar)) {
                @unlink($user->avatar);
            }

            // Trigger the event
            $eventFactory = $this->getFromContainer('util.events');
            $eventListener = $eventFactory->listener();
            $eventListener->register('Users.delete');
            $eventListener->trigger(
                $eventFactory->event('Users.delete', ['user_id' => $user_id, 'old_user' => $user])
            );
        }
    }

    /**
     * Fetches a user
     *
     * @param int $user_id The user ID to fetch
     * @return mixed An stdClass object representing the user, or false if it does not exist
     * @see Users::getByUsername()
     */
    public function get($user_id)
    {
        $user = $this->Record->select(['users.*', 'user_avatars.file_name' => 'avatar'])
            ->from('users')
            ->leftJoin('user_avatars', 'user_avatars.user_id', '=', 'users.id', false)
            ->where('id', '=', $user_id)
            ->fetch();

        // Trigger the Users.get event
        $event = $this->executeAndParseEvent('Users.get', [
            'user' => $user
        ]);
        if ($event instanceof \Blesta\Core\Util\Events\Common\EventInterface && ($errors = $event->getErrors())) {
            $this->Input->setErrors($errors);
            return false;
        }
        extract($event);

        return $user;
    }

    /**
     * Fetches a user
     *
     * @param string $username The username to fetch
     * @return mixed An stdClass object representing the user, or false if it does not exist
     * @see Users::get()
     */
    public function getByUsername($username)
    {
        return $this->Record->select(['users.*', 'user_avatars.file_name' => 'avatar'])
            ->from('users')
            ->leftJoin('user_avatars', 'user_avatars.user_id', '=', 'users.id', false)
            ->where('username', '=', $username)
            ->fetch();
    }

    /**
     * Fetches a user
     *
     * @param string $email The username email address to fetch
     * @return mixed An stdClass object representing the user, or false if it does not exist
     * @see Users::get()
     */
    public function getByEmail($email)
    {
        return $this->queryByEmail($email)->fetch();
    }

    /**
     * Fetches a list of users
     *
     * @param string $email The username email address to fetch
     * @return array A list of stdClass objects representing users
     */
    public function getAllByEmail($email)
    {
        return $this->queryByEmail($email)->fetchAll();
    }

    /**
     * Partially constructs the query fetching users by email
     *
     * @param string $email The username email address to fetch
     * @return Record The partially constructed query Record object
     * @see Users::get()
     */
    public function queryByEmail($email)
    {
        return $this->Record->select(['users.*', 'user_avatars.file_name' => 'avatar'])->
            from('clients')->
            innerJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)->
            innerJoin('users', 'users.id', '=', 'IFNULL(contacts.user_id, clients.user_id)', false)->
            leftJoin('user_avatars', 'user_avatars.user_id', '=', 'users.id', false)->
            where('contacts.email', '=', $email);
    }

    /**
     * Returns the one-time password record if it exists
     *
     * @param int $user_id The ID of the user to fetch on
     * @param string $otp The one-time password to search for
     * @reutrn mixed An stdClass object representing this record, or false if it does not exist
     */
    public function getOtp($user_id, $otp)
    {
        return (bool) $this->Record->select(['user_id'])->from('user_otps')->
            where('user_id', '=', $user_id)->where('otp', '=', $otp)->fetch();
    }

    /**
     * Return all One-time-password modes supported by the system
     *
     * @return array An array of key/value pairs consisting of the OTP mode (the key) and it's friendly name (the value)
     */
    public function getOtpModes()
    {
        return [
            'none' => 'None',
            'motp' => 'Mobile One Time Password',
            'totp' => 'Time-based HMAC One Time Password'
        ];
    }

    /**
     * Adds the one-time password record to the system so we can ensure it is not
     * used again.
     *
     * @param int $user_id The ID of the user to record the one-time password for
     * @param string $otp The one-time password to record
     */
    public function addOtp($user_id, $otp)
    {
        $cutoff_date = $this->dateToUtc(
            $this->Date->modify(
                date('c'),
                '-7 days',
                'c',
                Configure::get('Blesta.company_timezone')
            )
        );

        // delete any records older than 7 days
        $this->Record->from('user_otps')->
            where('date_added', '<', $cutoff_date)->
            delete();

        // insert into user_otps
        $this->Record->insert('user_otps', ['user_id' => $user_id, 'otp' => $otp,
            'date_added' => date('Y-m-d H:i:s')]);
    }

    /**
     * Fetches the one-time password mode required to authenticate this user
     *
     * @param string The ID or username of the user to fetch the required OTP mode
     * @return mixed A string containing the type of OTP mode, false if the user does not exist or
     * no mode is set
     */
    public function requiredOtpMode($user)
    {
        return $this->Record->select(['two_factor_mode'])->from('users')->
            where('two_factor_mode', '!=', 'none')->open()->
            where('id', '=', $user)->orWhere('username', '=', $user)->close()->fetch();
    }

    /**
     * Validates the user's 'two_factor_mode' field
     *
     * @param string $mode The two factor mode to check
     * @return bool True if validated, false otherwise
     */
    public function validateTwoFactorMode($mode)
    {
        switch ($mode) {
            case 'none':
            case 'motp':
            case 'totp':
                return true;
        }
        return false;
    }

    /**
     * Validates the user's 'two_factor_key'
     *
     * @param string $key The two factor key
     * @param string $mode The two factor mode
     * @retrun boolean True if valid, false otherwise
     */
    public function validateTwoFactorKey($key, $mode)
    {
        if ($mode == 'motp' || $mode == 'totp') {
            return ($key != '');
        }
        return true;
    }

    /**
     * Validates the given password matches the one on record
     *
     * @param string $password The encrypted password to check
     * @param int $user_id The user ID of the user to check against
     * @return bool True if the passwords are equivalent, false otherwise
     */
    public function validatePasswordEquals($password, $user_id)
    {
        $user = $this->get($user_id);

        return $this->checkPassword($password, $user->password);
    }

    /**
     * Validates the given username is unique across all users, besides $user_id
     *
     * @param string $username The username to be validated against the user ID
     * @param int $user_id A user ID
     * @return bool True if the username is unique for all users (besides this $user_id), false otherwise
     */
    public function validateUniqueUser($username, $user_id)
    {
        // Check for usernames taken by any user that is NOT associated with the
        // given $user_id
        $users = $this->Record->select('id')->from('users')->where('username', '=', $username)->
            where('id', '!=', $user_id)->numResults();

        if ($users > 0) {
            return false;
        }
        return true;
    }

    /**
     * Computes an HMAC sha-256 hash of the given password, then hashes that
     * using the best hashing algorithm available on the system
     *
     * @param string $password The password to hash
     * @return string The hashed password
     */
    public function hashPassword($password)
    {
        return password_hash(
            $this->systemHash($password),
            PASSWORD_BCRYPT,
            ['cost' => Configure::get('Blesta.hash_work')]
        );
    }

    /**
     * Verifies whether or not the given plain-text password produces the
     * supplied hash.
     *
     * @param string $password The password to validate
     * @param string $stored_hash The hash to verify the password against
     * @param string $algorithm The algorithm to use for validating the password
     * @return bool True if the password is good, false otherwise
     */
    public function checkPassword($password, $stored_hash, $algorithm = null)
    {
        switch ($algorithm) {
            case 'clientexec-sha256':
                // The hash_pbkdf2 function must exist (i.e. php 5.5+) to verify the password
                if (!function_exists('hash_pbkdf2')) {
                    return false;
                }

                $temp = explode(':', $stored_hash);

                $algo = $temp[0] ?? 'sha256';
                $iterations = $temp[1] ?? 1000;
                $salt = $temp[2] ?? null;
                $hash = $temp[3] ?? null;

                $new_hash = hash_pbkdf2($algo, $password, $salt, $iterations, 0, true);
                return substr(base64_encode($new_hash), 0, 32) == $hash;
            case 'whmcs':
                // Since newer versions on WHMCS may still have passwords encrypted in the old method, we will test
                // both kinds of encryption in both cases
            case 'whmcs-md5':
                // Verify new passwords
                if (password_verify($password, $stored_hash)) {
                    return true;
                }

                $temp = explode(':', $stored_hash);
                $salt = $temp[1] ?? null;
                if ($salt) {
                    return md5($salt . html_entity_decode($password)) . ':' . $salt == $stored_hash;
                }
                return md5($password) == $stored_hash;
            case 'md5':
                return md5($password) == $stored_hash;
            default:
                return password_verify($this->systemHash($password), $stored_hash);
        }
    }

    /**
     * Validates that at least one client is assigned to this user
     *
     * @param int $user_id The ID of the user
     * @return bool True if at least one client is assigned to this user, false otherwise
     */
    public function validateClientsExist($user_id)
    {
        $count = $this->Record->select('clients.*')->from('users')->
            innerJoin('clients', 'clients.user_id', '=', 'users.id', false)->
            where('clients.user_id', '=', $user_id)->
            numResults();

        if ($count > 0) {
            return true;
        }
        return false;
    }

    /**
     * Validates whether the user has made too many failed attempts to login
     *
     * @param string $username The username
     * @param string $ip_address The IP address from which the login took place
     * @return bool False if there has been too many failed login attempts, true otherwise
     */
    public function validateLoginAttempts($username, $ip_address)
    {
        $an_hour_ago = date('Y-m-d H:i:s', time() - 3600);

        // Get number of failed login attempts
        $count = $this->Record->select(['log_users.*'])->from('users')->
            innerJoin('log_users', 'log_users.user_id', '=', 'users.id', false)->
            where('users.username', '=', $username)->
            where('log_users.ip_address', '=', $ip_address)->
            where('log_users.date_added', '>=', $an_hour_ago)->
            where('log_users.result', '=', 'failure')->
            numResults();

        if ($count >= Configure::get('Blesta.max_failed_login_attempts')) {
            return false;
        }
        return true;
    }

    /**
     * Returns the rule set for adding/editing users
     *
     * @param array $vars An array of fields
     * @param bool $edit True to get the rules pertaining to editing a user
     * @return array An array of user rules
     */
    private function getRules(array $vars, $edit = false)
    {
        Loader::loadModels($this, ['Companies']);

        // Fetch password length
        $password_length = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_length');
        $password_length = $password_length->value ?? '6';

        // Fetch password format
        $password_format = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_format');
        $password_format = $password_format->value ?? 'any';

        // Fetch password rule
        $password_rule = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'password_rule');
        $password_rule = $password_rule->value ?? '/.*/i';

        $rules = [
            'username' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('Users.!error.username.empty')
                ],
                'unique' => [
                    'rule' => [[$this, 'validateUniqueUser'], ($vars['user_id'] ?? null)],
                    'message' => $this->_('Users.!error.username.unique')
                ]
            ],
            'new_password' => [
                'format' => [
                    'rule' => ['isPassword', $password_length, $password_format, $password_rule],
                    'message' => $this->getPasswordErrorMessage($password_format, $password_length, $password_rule),
                ],
                'matches' => [
                    'rule' => ['compares', '==', ($vars['confirm_password'] ?? null)],
                    'message' => $this->_('Users.!error.new_password.matches')
                ]
            ],
            'recovery_email' => [
                'format' => [
                    'if_set' => true,
                    'rule' => 'isEmail',
                    'message' => $this->_('Users.!error.recovery_email.format')
                ],
            ],
            'two_factor_mode' => [
                'format' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateTwoFactorMode']],
                    'message' => $this->_('Users.!error.two_factor_mode.format')
                ]
            ],
            'two_factor_key' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateTwoFactorKey'], ($vars['two_factor_mode'] ?? null)],
                    'message' => $this->_('Users.!error.two_factor_key.format')
                ]
            ]
        ];

        // Only require 'new_password' if it is set during an edit
        if ($edit) {
            $rules['new_password']['format']['if_set'] = true;
            $rules['new_password']['matches']['if_set'] = true;
        }

        return $rules;
    }

    /**
     * Get the appropriate password error message based on the password format
     *
     * @param string $password_format The password format (any, any_no_space, alpha_num, alpha, num, custom)
     * @param string $password_length The minimum password length
     * @param string $password_rule The custom regex rule (used when format is 'custom')
     * @return string The error message
     */
    private function getPasswordErrorMessage($password_format, $password_length, $password_rule)
    {
        switch ($password_format) {
            case 'any':
                return $this->_('Users.!error.new_password.format_any', $password_length);
            case 'any_no_space':
                return $this->_('Users.!error.new_password.format_any_no_space', $password_length);
            case 'alpha_num':
                return $this->_('Users.!error.new_password.format_alpha_num', $password_length);
            case 'alpha':
                return $this->_('Users.!error.new_password.format_alpha', $password_length);
            case 'num':
                return $this->_('Users.!error.new_password.format_num', $password_length);
            case 'custom':
                $description = $this->parsePasswordRule($password_rule);
                return $this->_('Users.!error.new_password.format_custom', $password_length, $description);
            default:
                // Fallback to the legacy error message for unknown formats
                return $this->_('Users.!error.new_password.format', $password_length);
        }
    }

    /**
     * Parse a custom password regex rule and return a human-readable description
     *
     * @param string $regex The regex pattern to parse
     * @return string A human-readable description of the password requirements
     */
    private function parsePasswordRule($regex)
    {
        $requirements = [];

        // Remove delimiters and flags
        $pattern = preg_replace('/^\/(.*)\/[a-z]*$/i', '$1', $regex);

        // Check for common lookahead patterns
        if (preg_match('/\(\?=.*\[a-z\]/i', $pattern)) {
            $requirements[] = $this->_('Users.!error.password_requirement.lowercase');
        }
        if (preg_match('/\(\?=.*\[A-Z\]/', $pattern)) {
            $requirements[] = $this->_('Users.!error.password_requirement.uppercase');
        }
        if (preg_match('/\(\?=.*(?:\\\\d|\[0-9\])/', $pattern)) {
            $requirements[] = $this->_('Users.!error.password_requirement.digit');
        }
        if (preg_match('/\(\?=.*\[.*[!@#$%^&*()_+\-=\[\]{};\':"|,.<>?\/~`]/', $pattern)) {
            $requirements[] = $this->_('Users.!error.password_requirement.special_char');
        }

        // Extract length requirements
        $min_length = null;
        $max_length = null;

        // Look for .{min,max} or .{min,} patterns
        if (preg_match('/\.?\{(\d+),(\d*)\}/', $pattern, $matches)) {
            $min_length = $matches[1];
            $max_length = !empty($matches[2]) ? $matches[2] : null;
        }
        // Look for ^.{min}$ exact length
        elseif (preg_match('/\{(\d+)\}/', $pattern, $matches)) {
            $min_length = $matches[1];
            $max_length = $matches[1];
        }

        // Build the description
        $description_parts = [];

        if ($min_length !== null) {
            if ($max_length !== null && $min_length !== $max_length) {
                $description_parts[] = $this->_('Users.!error.password_requirement.length_between', $min_length, $max_length);
            } elseif ($max_length !== null && $min_length === $max_length) {
                $description_parts[] = $this->_('Users.!error.password_requirement.length_exact', $min_length);
            } else {
                $description_parts[] = $this->_('Users.!error.password_requirement.length_min', $min_length);
            }
        }

        if (!empty($requirements)) {
            $description_parts[] = $this->_('Users.!error.password_requirement.must_contain', $this->formatList($requirements));
        }

        // If we found meaningful requirements, return the description
        if (!empty($description_parts)) {
            return implode(', ', $description_parts);
        }

        // Fallback: show the regex pattern itself
        return $this->_('Users.!error.password_requirement.pattern_fallback', $regex);
    }

    /**
     * Format a list of items with proper grammar (e.g., "a, b, and c")
     *
     * @param array $items The items to format
     * @return string The formatted list
     */
    private function formatList(array $items)
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }

    /**
     * Validate user information for add or edit
     *
     * @param array $vars An array of user info including:
     *
     *  - user_id The ID of the user for validating edits (optional)
     *  - username The username for this user (optional)
     *  - current_password The current password for this user (optional, required if $validate_pass is true)
     *  - new_password The new password for this user (optional)
     *  - confirm_password The new password for this user (optional, required if 'new_password' is given)
     *  - two_factor_mode The two factor authentication mode 'none', 'motp', 'totp' (optional)
     *  - two_factor_key The two factor authentication key (optional)
     *  - two_factor_pin The two factor authentication pin (optional)
     *  - otp The one-time-password to validate, required if two_factor_mode
     *      is something other than 'none' and $validate_pass is set to true
     * @param bool $edit Whether this data is being validated for an edit (optional, default false)
     * @param bool $validate_pass Whether or not to validate the
     *      current_password before updating this user (optional, default
     *      false). If set will also attempt to validate the one-time-password.
     * @return bool True if the user info is valid, false otherwise
     */
    public function validateUser(array $vars, $edit = false, $validate_pass = false)
    {
        $vars = $this->adjustInput($vars, $edit);

        if ($edit) {
            // Add new rules for user ID
            $rules = [
                'user_id' => [
                    'exists' => [
                        'rule' => [[$this, 'validateExists'], 'id', 'users'],
                        'message' => $this->_('Users.!error.user_id.exists')
                    ]
                ],
                'recovery_email' => [
                    'blacklisted' => [
                        'if_set' => true,
                        'rule' => function ($email) use ($vars) {
                            Loader::loadComponents($this, ['Session']);

                            if (empty($this->Session->read('blesta_staff_id'))) {
                                Loader::loadModels($this, ['Blacklist']);

                                return $this->Blacklist->verify($email, 'email');
                            }

                            return true;
                        },
                        'message' => $this->_('AppController.!error.unauthorized_access')
                    ]
                ]
            ];

            // Validate the current password
            if ($validate_pass) {
                $rules['current_password'] = [
                    'matches' => [
                        'rule' => [[$this, 'validatePasswordEquals'], ($vars['user_id'] ?? null)],
                        'message' => $this->_('Users.!error.current_password.matches')
                    ]
                ];

                if (isset($vars['two_factor_mode']) && $vars['two_factor_mode'] != 'none') {
                    $user = new stdClass();
                    $user->id = ($vars['user_id'] ?? null);
                    $user->two_factor_mode = $vars['two_factor_mode'];
                    $user->two_factor_key = $vars['two_factor_key'] ?? null;
                    $user->two_factor_pin = $vars['two_factor_pin'] ?? null;

                    // Validate OTP
                    $rules['otp'] = [
                        'auth' => [
                            'rule' => [[$this, 'validateOtp'], $user],
                            'message' => $this->_('Users.!error.otp.auth')
                        ]
                    ];
                }
            }
        } else {
            $rules = [];
        }

        $rules = array_merge($rules, $this->getRules($vars, $edit));

        if ($edit) {
            // Remove username constraint (make optional)
            foreach ($rules['username'] as &$rule) {
                $rule['if_set'] = true;
            }
        }

        $this->Input->setRules($rules);

        return $this->Input->validates($vars);
    }

    /**
     * Adjusts input for user creation/editing/validation
     *
     * @param array $vars An array of user info including:
     *
     *  - username The username for this user (optional)
     *  - current_password The current password for this user (optional, required if $validate_pass is true)
     *  - new_password The new password for this user (optional)
     *  - confirm_password The new password for this user (optional, required if 'new_password' is given)
     *  - two_factor_mode The two factor authentication mode 'none', 'motp', 'totp' (optional)
     *  - two_factor_key The two factor authentication key (optional)
     *  - two_factor_pin The two factor authentication pin (optional)
     *  - otp The one-time-password to validate, required if two_factor_mode
     *      is something other than 'none' and $validate_pass is set to true
     * @param bool $edit Whether this data is being adjusted for an update (optional, default false)
     * @return array The adjust input data
     */
    private function adjustInput(array $vars, $edit = false)
    {
        if ($edit) {
            // Remove new password if it is empty
            if (empty($vars['new_password']) && !isset($vars['confirm_password'])) {
                unset($vars['new_password']);
            }
        }

        // Unset empty recovery email
        if (empty($vars['recovery_email'])) {
            $vars['recovery_email'] = null;
        }

        return $vars;
    }
}
