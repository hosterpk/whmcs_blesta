<?php

/**
 * Password resets management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class PasswordResets extends AppModel
{
    /**
     * Initialize PasswordResets
     */
    public function __construct()
    {
        parent::__construct();

        Language::loadLang(['password_resets']);
    }

    /**
     * Generates a new password reset token
     *
     * @param int $user_id The ID of the user to reset the password
     * @param string $email The email address of the user
     * @return string The password reset token, void on error
     */
    public function add(int $user_id, string $email)
    {
        $this->Input->setRules($this->getRules());

        $vars = compact('user_id', 'email');
        if (!$this->Input->validates($vars)) {
            return;
        }

        // Generate random token
        $token = bin2hex(random_bytes(16));
        $vars['token'] = $this->systemHash($token);

        // Set expiration date
        $vars['date_expires'] = $this->dateToUtc(
            $this->Date->modify(
                date('c'),
                '+' . Configure::get('Blesta.reset_password_ttl'),
                'c',
                Configure::get('Blesta.company_timezone')
            )
        );

        // Delete all previous tokens
        $this->deleteByUser($user_id);

        // Save token
        $fields = ['user_id', 'email', 'token', 'date_expires'];
        $this->Record->insert('password_resets', $vars, $fields);

        return $token;
    }

    /**
     * Validates the password reset token
     *
     * @param string $token The password reset token to validate
     * @return bool True if the token is valid, false otherwise
     */
    public function validate(string $token) : bool
    {
        if (!isset($this->Users)) {
            Loader::loadModels($this, ['Users']);
        }
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }
        if (!isset($this->Staff)) {
            Loader::loadModels($this, ['Staff']);
        }

        // Fetch token
        $token = $this->get($token);
        if (!$token) {
            return false;
        }

        // Verify token has not expired
        $current_date = $this->Date->toTime($this->dateToUtc(date('c'), 'c'));
        if ($current_date > $this->Date->toTime($token->date_expires)) {
            return false;
        }

        // Fetch user associated to the token
        if (!($user = $this->Users->get($token->user_id))) {
            return false;
        }

        $valid_email = false;
        if ($token->email == $user->recovery_email) {
            // Validate if the email is a recovery email
            $valid_email = true;
        } elseif (($client = $this->Clients->getByUserId($user->id))) {
            // Validate if the email belongs to a contact associated to the given user
            $email = $client->email;
            if (($contact = $this->Contacts->getByUserId($user->id, $client->id))) {
                $email = $contact->email;
            }
            
            if ($email == $token->email) {
                $valid_email = true;
            }
        } else {
            // Validate if the email belongs to a staff member associated to the given user
            if (($staff = $this->Staff->getByUserId($user->id))
                && $staff->email == $token->email
            ) {
                $valid_email = true;
            }
        }

        return $valid_email;
    }

    /**
     * Fetches a password reset token
     *
     * @param string $token The password reset token to fetch
     * @return mixed An object representing the token
     */
    public function get(string $token)
    {
        return $this->Record->select()->from('password_resets')->
            where('token', '=', $this->systemHash($token))->
            fetch();
    }

    /**
     * Fetches all expired password reset tokens
     *
     * @return array An array of objects representing the expired token
     */
    public function getExpired()
    {
        $current_date = $this->dateToUtc(date('c'), 'c');

        return $this->Record->select()->from('password_resets')->
            where('date_expires', '<=', $current_date)->
            fetchAll();
    }

    /**
     * Deletes an existing token
     *
     * @param string $token The token to delete
     */
    public function delete(string $token)
    {
        $this->deleteByHash($this->systemHash($token));
    }
    
    /**
     * Deletes an existing token by the token hash
     *
     * @param string $token_hash The token to delete
     */
    public function deleteByHash(string $token_hash)
    {
        $this->Record->from('password_resets')
            ->where('token', '=', $token_hash)
            ->delete();
    }

    /**
     * Deletes all existing token from a specific user
     *
     * @param int $user_id The user ID to delete all tokens
     */
    public function deleteByUser(int $user_id)
    {
        $this->Record->from('password_resets')
            ->where('user_id', '=', $user_id)
            ->delete();
    }

    /**
     * Fetches the validation rules for a new password reset token
     *
     * @return array The validation rules
     */
    private function getRules() : array
    {
        $rules = [
            'user_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'users'],
                    'message' => $this->_('PasswordResets.!error.user_id.exists')
                ]
            ],
            'email' => [
                'format' => [
                    'rule' => 'isEmail',
                    'message' => $this->_('PasswordResets.!error.email.format')
                ]
            ]
        ];

        return $rules;
    }
}
