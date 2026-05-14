<?php
/**
 * Language definitions for the Blacklist model
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Errors
$lang['Blacklist.!error.rule.exists'] = 'This rule already exists in the database.';
$lang['Blacklist.!error.plugin_dir.exists'] = 'The plugin given does not exist.';
$lang['Blacklist.!error.type.format'] = 'The type must be "ip" or "email".';
$lang['Blacklist.!error.rule.format_ip'] = 'The provided rule is not a valid IP address or a CIDR notation.';
$lang['Blacklist.!error.rule.format_email'] = 'The provided rule is not a email address.';
$lang['Blacklist.!error.block_outgoing.valid'] = 'The block outgoing rule must be "1" or "0".';

$lang['Blacklist.type.ip'] = 'IP Address / CIDR';
$lang['Blacklist.type.email'] = 'Email Address';
