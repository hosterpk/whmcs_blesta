<?php
/**
 * Language definitions for the Client Groups model
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

$lang['ClientGroups.!error.name.empty'] = 'Please specify a group name.';
$lang['ClientGroups.!error.company_id.exists'] = 'Invalid company ID.';
$lang['ClientGroups.!error.color.length'] = 'Color length may not exceed 16 characters.';
$lang['ClientGroups.!error.group_id.exists'] = 'Invalid client group ID.';
$lang['ClientGroups.!error.clients_format.format'] = 'The Client ID Format must contain {num}.';

// Credit limit errors
$lang['ClientGroups.!error.payment_credit_limits.min_amount'] = 'The minimum credit amount for %1$s must be greater than 0.';
$lang['ClientGroups.!error.payment_credit_limits.max_amount'] = 'The maximum credit amount for %1$s must be greater than 0.';
$lang['ClientGroups.!error.payment_credit_limits.max_less_than_min'] = 'The maximum credit amount for %1$s must be greater than the minimum amount.';
