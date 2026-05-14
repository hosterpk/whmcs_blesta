<?php
/**
 * Language definitions for the Cron controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Errors
$lang['Cron.!error.cron.failed'] = 'Cron failed to log.';
$lang['Cron.!error.task_execution.failed'] = 'Error: %1$s %2$s'; // %1$s is the error message that occurred, %2$s is the stack trace
$lang['Cron.!error.task_filter.invalid_json'] = 'Invalid JSON in task filter parameter: %1$s'; // %1$s is the JSON error message
$lang['Cron.!error.task_filter.invalid_format'] = 'Task filter must be a JSON object.';
$lang['Cron.!error.task_filter.both_include_exclude'] = 'Task filter cannot have both "include" and "exclude" keys.';
$lang['Cron.!error.task_filter.include_not_array'] = 'Task filter "include" must be an array.';
$lang['Cron.!error.task_filter.exclude_not_array'] = 'Task filter "exclude" must be an array.';


// Index
$lang['Cron.index.attempt_all'] = 'Attempting to run all tasks for %1$s.'; // %1$s is the company name
$lang['Cron.index.completed_all'] = 'All tasks have been completed.';
$lang['Cron.index.attempt_all_system'] = 'Attempting to run all system tasks.';
$lang['Cron.index.completed_all_system'] = 'All system tasks have been completed.';

// Apply credits
$lang['Cron.applycredits.attempt'] = 'Attempting to apply credits to open invoices.';
$lang['Cron.applycredits.completed'] = 'The apply credits task has completed.';
$lang['Cron.applycredits.apply_failed'] = 'Unable to apply pending credits for client #%1$s.'; // %1$s is the client ID
$lang['Cron.applycredits.apply_success'] = 'Successfully applied pending credits from transaction %1$s for client #%2$s to invoice #%3$s in the amount of %4$s.'; // %1$s is the transaction number, %2$s is the client ID, %3$s is the invoice ID, and %4$s is the amount applied
$lang['Cron.applycredits.apply_none'] = 'There are no invoices to which credits may be applied.';


// Autodebit invoices
$lang['Cron.autodebitinvoices.attempt'] = 'Attempting to auto debit open invoices.';
$lang['Cron.autodebitinvoices.completed'] = 'The auto debit invoices task has completed.';
$lang['Cron.autodebitinvoices.charge_attempt'] = 'Attempting to auto debit client #%1$s for all open invoices in the amount of %2$s.'; // %1$s is the client ID, %2$s is the amount due
$lang['Cron.autodebitinvoices.charge_failed'] = 'Unable to process the payment.';
$lang['Cron.autodebitinvoices.charge_success'] = 'Successfully processed the payment.';


// Card Expiration Reminders
$lang['Cron.cardexpirationreminders.attempt'] = 'Attempting to send card expiration reminders.';
$lang['Cron.cardexpirationreminders.completed'] = 'The card expiration reminders task has completed.';
$lang['Cron.cardexpirationreminders.failed'] = 'The expiration reminder for contact %1$s %2$s from client #%3$s could not be sent.'; // %1$s is the contact's first name, %2$s is the contact's last name, %3$s is the client ID
$lang['Cron.cardexpirationreminders.success'] = 'Successfully delivered the expiration reminder for contact %1$s %2$s from client #%3$s.'; // %1$s is the contact's first name, %2$s is the contact's last name, %3$s is the client ID


// Suspend services
$lang['Cron.suspendservices.attempt'] = 'Attempting to suspend past due services.';
$lang['Cron.suspendservices.completed'] = 'The suspend services task has completed.';
$lang['Cron.suspendservices.suspension_reason'] = 'Non-Payment';

$lang['Cron.suspendservices.suspend_error'] = 'The service #%1$s from client %2$s could not be suspended.'; // %1$s is the service ID, %2$s is the client ID
$lang['Cron.suspendservices.suspend_success'] = 'The service #%1$s from client %2$s has been suspended.'; // %1$s is the service ID, %2$s is the client ID


// Suspend services
$lang['Cron.unsuspendservices.attempt'] = 'Attempting to unsuspend paid suspended services.';
$lang['Cron.unsuspendservices.completed'] = 'The unsuspend services task has completed.';

$lang['Cron.unsuspendservices.unsuspend_error'] = 'The service #%1$s from client %2$s could not be unsuspended.'; // %1$s is the service ID, %2$s is the client ID
$lang['Cron.unsuspendservices.unsuspend_success'] = 'The service #%1$s from client %2$s has been unsuspended.'; // %1$s is the service ID, %2$s is the client ID


// Cancel Scheduled Services
$lang['Cron.cancelscheduledservices.attempt'] = 'Attempting to cancel scheduled services.';
$lang['Cron.cancelscheduledservices.completed'] = 'The cancel scheduled services task has completed.';

$lang['Cron.cancelscheduledservices.cancel_error'] = 'The service #%1$s from client #%2$s could not be canceled.'; // %1$s is the service ID, %2$s is the client ID
$lang['Cron.cancelscheduledservices.cancel_success'] = 'The service #%1$s from client #%2$s has been canceled.'; // %1$s is the service ID, %2$s is the client ID


// Add paid pending services
$lang['Cron.addpaidpendingservices.attempt'] = 'Attempting to provision paid pending services.';
$lang['Cron.addpaidpendingservices.completed'] = 'The paid pending services task has completed.';
$lang['Cron.addpaidpendingservices.service_error'] = 'The pending service #%1$s from client #%2$s could not be made active.'; // %1$s is the service ID, %2$s is the client ID
$lang['Cron.addpaidpendingservices.service_success'] = 'The pending service #%1$s from client #%2$s is now active.'; // %1$s is the service ID, %2$s is the client ID
