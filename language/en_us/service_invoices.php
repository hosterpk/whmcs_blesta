<?php
/**
 * Language definitions for the Service Invoices model
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2017, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

$lang['ServiceInvoices.!error.service_id.exists'] = 'Invalid service ID.';
$lang['ServiceInvoices.!error.invoice_id.exists'] = 'Invalid invoice ID.';
$lang['ServiceInvoices.!error.failed_attempts.format'] = 'Failed attempts must be a number.';
$lang['ServiceInvoices.!error.maximum_attempts.format'] = 'Maximum attempts must be a number.';
$lang['ServiceInvoices.!error.type.valid'] = 'Invalid attempt type.';
$lang['ServiceInvoices.!error.date_next_attempt.format'] = 'Date next attempt must be a date.';

$lang['ServiceInvoices.getattempttypes.provisioning'] = 'Provisioning';
$lang['ServiceInvoices.getattempttypes.renewal'] = 'Renewal';
$lang['ServiceInvoices.getattempttypes.suspension'] = 'Suspension';
$lang['ServiceInvoices.getattempttypes.unsuspension'] = 'Unsuspension';
$lang['ServiceInvoices.getattempttypes.cancelation'] = 'Cancelation';
$lang['ServiceInvoices.getattempttypes.change'] = 'Change';

$lang['ServiceInvoices.getCancelOptions.both'] = 'Allow immediate or end of term cancellation';
$lang['ServiceInvoices.getCancelOptions.end_of_term'] = 'Allow end of term cancellation only';
$lang['ServiceInvoices.getCancelOptions.now'] = 'Allow immediate cancellation only';
