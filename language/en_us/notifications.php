<?php
/**
 * Language definitions for the Notifications model
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Error messages
$lang['Notifications.!error.action_id.exists'] = 'Invalid notification action ID.';
$lang['Notifications.!error.user_id.exists'] = 'Invalid user ID.';
$lang['Notifications.!error.user_id.enabled'] = 'Notification action is not enabled for this user.';
$lang['Notifications.!error.title.empty'] = 'Please enter a notification title.';
$lang['Notifications.!error.message.empty'] = 'Please enter a notification message.';
$lang['Notifications.!error.content.empty'] = 'Please enter notification content.';
$lang['Notifications.!error.read.format'] = 'Read status must be 0 or 1.';
$lang['Notifications.!error.type.format'] = 'Invalid notification type.';
$lang['Notifications.!error.plugin_dir.required'] = 'Plugin directory is required for plugin notifications.';


// Notifications
$lang['Notifications.notification.service_suspension_error_name'] = 'Suspension Error';
$lang['Notifications.notification.service_suspension_error_desc'] = 'Notification sent after a failed attempt to suspend a service.';
$lang['Notifications.notification.service_unsuspension_error_name'] = 'Unsuspension Error';
$lang['Notifications.notification.service_unsuspension_error_desc'] = 'Notification sent after a failed attempt to unsuspend a service.';
$lang['Notifications.notification.service_cancel_error_name'] = 'Cancellation Error';
$lang['Notifications.notification.service_cancel_error_desc'] = 'Notification sent after a failed attempt to cancel a service.';
$lang['Notifications.notification.service_creation_error_name'] = 'Creation Error';
$lang['Notifications.notification.service_creation_error_desc'] = 'Notification sent after a failed attempt to provision a service.';
$lang['Notifications.notification.service_renewal_error_name'] = 'Renewal Error';
$lang['Notifications.notification.service_renewal_error_desc'] = 'Notification sent after a failed attempt to renew a service.';
$lang['Notifications.notification.service_creation_name'] = 'Service Creation';
$lang['Notifications.notification.service_creation_desc'] = 'Notification sent when a service has been created.';
$lang['Notifications.notification.service_uncancellation_name'] = 'Service Uncancellation';
$lang['Notifications.notification.service_uncancellation_desc'] = 'Notification sent when a service has been uncancelled.';


// Notification types
$lang['Notifications.getTypes.system'] = 'System';
$lang['Notifications.getTypes.plugin'] = 'Plugin';

// Notification message types
$lang['Notifications.getTypes.info'] = 'Info';
$lang['Notifications.getTypes.success'] = 'Success';
$lang['Notifications.getTypes.warning'] = 'Warning';
$lang['Notifications.getTypes.danger'] = 'Danger';
