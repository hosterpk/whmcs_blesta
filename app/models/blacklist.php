<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Language;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Blacklist management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Blacklist extends AppModel
{
    /**
     * Initialize Blacklist
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['blacklist']);
    }

    /**
     * Adds a rule to the blacklist
     *
     * @param array $vars An array of client info including:
     *
     *  - rule The IP address, CIDR block, email address or wildcard email to block
     *  - type The rule type, it could be "ip" or "email"
     *  - plugin_dir The directory of the plugin that created the rule (optional)
     *  - note A note about the rule (optional)
     * @return int The ID of the blacklist rule, void on error
     */
    public function add(array $vars)
    {
        // Set rules
        $rules = [
            'rule' => [
                'exists' => [
                    'negate' => true,
                    'rule' => [[$this, 'validateExists'], 'rule', 'blacklist'],
                    'message' => $this->_('Blacklist.!error.rule.exists')
                ]
            ],
            'plugin_dir' => [
                'exists' => [
                    'if_set' => true,
                    'rule' => [[$this, 'validateExists'], 'dir', 'plugins'],
                    'message' => $this->_('Blacklist.!error.plugin_dir.exists')
                ]
            ],
            'type' => [
                'format' => [
                    'rule' => ['in_array', array_keys($this->getTypes())],
                    'message' => $this->_('Blacklist.!error.type.format', true)
                ]
            ],
            'block_outgoing' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', [0, 1]],
                    'message' => $this->_('Blacklist.!error.block_outgoing.valid')
                ]
            ]
        ];

        // Validate rule
        if ($vars['type'] == 'ip') {
            $rules['rule']['format'] = [
                'rule' => [[$this, 'validateIp']],
                'message' => $this->_('Blacklist.!error.rule.format_ip')
            ];
        } elseif ($vars['type'] == 'email') {
            $rules['rule']['format'] = [
                'rule' => [[$this, 'validateEmail']],
                'message' => $this->_('Blacklist.!error.rule.format_email')
            ];
        }

        $this->Input->setRules($rules);

        // Add blacklist rule
        if ($this->Input->validates($vars)) {
            $fields = ['rule', 'type', 'block_outgoing', 'plugin_dir', 'note'];
            $this->Record->insert('blacklist', $vars, $fields);
        }

        return $this->Record->lastInsertId();
    }

    /**
     * Fetches a specific rule
     *
     * @param int $id The ID of the rule to remove
     * @return mixed An object representing the rule
     */
    public function get($id)
    {
        return $this->Record->select()
            ->from('blacklist')
            ->where('id', '=', $id)
            ->fetch();
    }

    /**
     * Returns a list of the rules
     *
     * @param int $page The page to return results for (optional, default 1)
     * @param array $order_by The sort and order conditions (e.g. array('sort_field'=>"ASC"), optional)
     * @return mixed An array of objects
     */
    public function getList($page = 1, $order_by = ['plugin_dir' => 'ASC'])
    {
        return $this->Record->select(['blacklist.*', 'plugins.name' => 'plugin_name'])
            ->from('blacklist')
            ->leftJoin('plugins', 'plugins.dir', '=', 'blacklist.plugin_dir', false)
            ->order($order_by)
            ->group('blacklist.rule')
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Returns the total number of rules
     *
     * @return int The total amount of rules in the system
     */
    public function getListCount()
    {
        return $this->Record->select()
            ->from('blacklist')
            ->numResults();
    }

    /**
     * Removes a rule
     *
     * @param int $id The ID of the rule to remove
     */
    public function remove(int $id)
    {
        $this->Record->from('blacklist')
            ->where('id', '=', $id)
            ->delete();
    }

    /**
     * Deletes all the rules created by a specific plugin
     *
     * @param string $plugin_dir The directory name of the plugin that created the rules
     */
    public function removeByPlugin(string $plugin_dir)
    {
        $this->Record->from('blacklist')
            ->where('plugin_dir', '=', $plugin_dir)
            ->delete();
    }

    /**
     * Retrieves a list of the rule types and their language
     *
     * @return array A key/value list of rule types and their language
     */
    public function getTypes()
    {
        return [
            'ip' => $this->_('Blacklist.type.ip'),
            'email' => $this->_('Blacklist.type.email')
        ];
    }

    /**
     * Validates a rule of "ip" type
     *
     * @param string $ip The IP rule to validate
     * @return bool True if the IP rule is valid, false otherwise
     */
    public function validateIp(string $ip)
    {
        if (str_contains($ip, '/')) {
            return $this->validateCidr($ip);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    /**
     * Validates a rule of "ip" type, in CIDR format
     *
     * @param string $cidr The IP rule to validate
     * @return bool True if the IP rule is valid, false otherwise
     */
    private function validateCidr(string $cidr)
    {
        $parts = explode('/', $cidr);

        if (count($parts) != 2) {
            return false;
        }

        $ip = $parts[0];
        $netmask = $parts[1];

        if (!preg_match('/^\d+$/', $netmask)) {
            return false;
        }

        $netmask = intval($parts[1]);
        if ($netmask < 0) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $netmask <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $netmask <= 128;
        }

        return false;
    }

    /**
     * Validates a rule of "email" type
     *
     * @param string $email The email rule to validate
     * @return bool True if the email rule is valid, false otherwise
     */
    public function validateEmail(string $email)
    {
        $parts = explode('@', $email);

        if (count($parts) != 2) {
            return false;
        }

        $username = $parts[0];
        $hostname = $parts[1];

        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN)) {
            return false;
        }

        return $username == '*' ? true : $this->Input->isEmail($email);
    }

    /**
     * Verify an incoming request against the blacklist
     *
     * @param string $input The input data to verify against the blacklist
     * @param string $type The type of rule to use for verification
     * @param string $direction The direction where to validate the rule
     * @return bool True if the current request is approved by the blacklist, false otherwise
     */
    public function verify(?string $input, string $type, string $direction = 'incoming')
    {
        if (empty($input)) {
            return true;
        }

        // Set rules
        $rules = [
            'type' => [
                'format' => [
                    'rule' => ['in_array', array_keys($this->getTypes())],
                    'message' => $this->_('Blacklist.!error.type.format', true)
                ]
            ]
        ];

        $this->Input->setRules($rules);

        // Verify rule
        $data = ['type' => $type];
        if ($this->Input->validates($data)) {
            // Check if a rule exists for this specific input
            $this->Record->select()
                ->from('blacklist')
                ->where('rule', '=', $input)
                ->where('type', '=', $type);

            if ($direction == 'outgoing') {
                $this->Record->where('block_outgoing', '=', '1');
            }
            $rule = $this->Record->fetch();

            // If a rule doesn't exist for the given input, try to match the input against
            // a CIDR or a wildcard rule
            if (empty($rule)) {
                if ($type == 'ip') {
                    $cidr_rules = $this->Record->select()
                        ->from('blacklist')
                        ->where('rule', 'like', '%/%')
                        ->where('type', '=', 'ip')
                        ->where('block_outgoing', '=', ($direction == 'incoming' ? '0' : '1'))
                        ->fetchAll();

                    foreach ($cidr_rules as $cidr_rule) {
                        if (IpUtils::checkIp($input, $cidr_rule->rule)) {
                            $rule = $cidr_rule;
                            break;
                        }
                    }
                } elseif ($type == 'email') {
                    $parts = explode('@', $input, 2);
                    $wildcard = '*@' . ($parts[1] ?? '');
                    $this->Record->select()
                        ->from('blacklist')
                        ->where('rule', '=', $wildcard)
                        ->where('type', '=', 'email');

                    if ($direction == 'outgoing') {
                        $this->Record->where('block_outgoing', '=', '1');
                    }

                    $this->Record->fetch();
                }
            }

            return empty($rule);
        }

        return true;
    }
}
