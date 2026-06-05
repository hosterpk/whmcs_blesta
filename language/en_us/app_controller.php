<?php
/**
 * Language definitions for the AppController (essentially global UI definitions)
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Language direction (only ltr or rtl)
$lang['AppController.lang.dir'] = 'ltr';

// Errors
$lang['AppController.!error.unauthorized_access'] = 'You are not authorized to access that resource';
$lang['AppController.!error.client_unauthorized_access'] = 'You do not have permission to access that resource, please contact the primary account holder to request access';
$lang['AppController.!error.invalid_csrf'] = 'The form token is invalid.';

// Success
$lang['AppController.!success.license_updated'] = 'Your license successfully revalidated. Please log in.';
$lang['AppController.!success.license_key_updated'] = 'Your license key was successfully updated. Please log in.';

// Options
$lang['AppController.select.please'] = '-- Please Select --';

// Modal boxes
$lang['AppController.modal.text_close'] = 'Close';
$lang['AppController.modal.text_confirm'] = 'Please Confirm';
$lang['AppController.modal.confirm_delete'] = 'Confirm Delete';
$lang['AppController.modal.btn_cancel'] = 'Cancel';
$lang['AppController.modal.btn_delete'] = 'Delete';

// Tooltip text
$lang['AppController.tooltip.text'] = '?';

// Dropzone text
$lang['AppController.dropzone.text'] = 'Drop files here to upload or Click to select files';

// Loading state
$lang['AppController.text_loading'] = 'Loading...';

// Banners
$lang['AppController.banners.client_manager'] = 'You are currently managing an account that has invited you to manage it.';
$lang['AppController.banners.text_switch_back'] = 'Switch back to my account';

// Message box close text
$lang['AppController.message.close'] = '×';

// Structure
$lang['AppController.structure.text_myinfo'] = 'My Info';
$lang['AppController.structure.text_notices'] = 'Notices';
$lang['AppController.structure.text_settings'] = 'Settings';
$lang['AppController.structure.text_iconbar'] = 'Icon Bar';
$lang['AppController.structure.text_logout'] = 'Log Out';
$lang['AppController.structure.text_maintenance'] = 'Maintenance Mode is currently enabled and clients may not login.';
$lang['AppController.structure.text_maintenance_header'] = 'Maintenance Mode Enabled';
$lang['AppController.structure.text_maintenance_button'] = 'Edit Maintenance Mode';
$lang['AppController.structure.text_step_up_access'] = 'You currently have a step up session open with access to admin settings.';
$lang['AppController.structure.text_step_up_access_header'] = 'Step Up Authentication Active';
$lang['AppController.structure.text_step_up_access_button'] = 'End Session Now';
$lang['AppController.structure.text_step_up_time_remaining'] = 'Time Remaining:';
$lang['AppController.structure.text_step_up_extend'] = 'Extend Session';
$lang['AppController.structure.text_search_placeholder'] = '%1$s...'; // %1$s is the search type
$lang['AppController.structure.text_version'] = 'v%1$s'; // %1$s is the version number
$lang['AppController.structure.text_licensed_to'] = 'Licensed to %1$s'; // %1$s is the company name
$lang['AppController.structure.text_notifications'] = 'Notifications';
$lang['AppController.structure.no_notifications'] = 'No new notifications';
$lang['AppController.structure.text_open'] = 'Open';
$lang['AppController.structure.text_mark_all_read'] = 'Mark all as read';
$lang['AppController.client_structure.staff_as_client_note'] = 'Return to Staff Portal';
$lang['AppController.client_structure.default_title'] = 'My Account';
$lang['AppController.client_structure.text_return_to_portal'] = 'Return to Portal';
$lang['AppController.client_structure.text_logout'] = 'Log Out';
$lang['AppController.client_structure.text_login'] = 'Log In';
$lang['AppController.client_structure.text_update_account'] = 'Manage Account';
$lang['AppController.client_structure.text_emails'] = 'Email History';
$lang['AppController.client_structure.text_accounts'] = 'Payment Accounts';
$lang['AppController.client_structure.text_contacts'] = 'Contacts';
$lang['AppController.client_structure.text_managers'] = 'Managers';


// Dates
$lang['AppController.dates.day_sun'] = 'Sunday';
$lang['AppController.dates.day_mon'] = 'Monday';
$lang['AppController.dates.day_tue'] = 'Tuesday';
$lang['AppController.dates.day_wed'] = 'Wednesday';
$lang['AppController.dates.day_thu'] = 'Thursday';
$lang['AppController.dates.day_fri'] = 'Friday';
$lang['AppController.dates.day_sat'] = 'Saturday';

$lang['AppController.dates.dayabbr_sun'] = 'Sun';
$lang['AppController.dates.dayabbr_mon'] = 'Mon';
$lang['AppController.dates.dayabbr_tue'] = 'Tue';
$lang['AppController.dates.dayabbr_wed'] = 'Wed';
$lang['AppController.dates.dayabbr_thur'] = 'Thu';
$lang['AppController.dates.dayabbr_fri'] = 'Fri';
$lang['AppController.dates.dayabbr_sat'] = 'Sat';

$lang['AppController.dates.month_jan'] = 'January';
$lang['AppController.dates.month_feb'] = 'February';
$lang['AppController.dates.month_mar'] = 'March';
$lang['AppController.dates.month_apr'] = 'April';
$lang['AppController.dates.month_may'] = 'May';
$lang['AppController.dates.month_jun'] = 'June';
$lang['AppController.dates.month_jul'] = 'July';
$lang['AppController.dates.month_aug'] = 'August';
$lang['AppController.dates.month_sep'] = 'September';
$lang['AppController.dates.month_oct'] = 'October';
$lang['AppController.dates.month_nov'] = 'November';
$lang['AppController.dates.month_dec'] = 'December';

$lang['AppController.dates.monthabbr_jan'] = 'Jan';
$lang['AppController.dates.monthabbr_feb'] = 'Feb';
$lang['AppController.dates.monthabbr_mar'] = 'Mar';
$lang['AppController.dates.monthabbr_apr'] = 'Apr';
$lang['AppController.dates.monthabbr_may'] = 'May';
$lang['AppController.dates.monthabbr_jun'] = 'Jun';
$lang['AppController.dates.monthabbr_jul'] = 'Jul';
$lang['AppController.dates.monthabbr_aug'] = 'Aug';
$lang['AppController.dates.monthabbr_sep'] = 'Sep';
$lang['AppController.dates.monthabbr_oct'] = 'Oct';
$lang['AppController.dates.monthabbr_nov'] = 'Nov';
$lang['AppController.dates.monthabbr_dec'] = 'Dec';


// Graphs
$lang['AppController.graphs.control_label.stacked'] = 'Stacked';
$lang['AppController.graphs.control_label.stream'] = 'Stream';
$lang['AppController.graphs.control_label.expanded'] = 'Expanded';
$lang['AppController.graphs.control_label.grouped'] = 'Grouped';

$lang['AppController.graphs.label.total'] = 'Total';


// Screen reader
$lang['AppController.sreader.dropdown'] = 'Toggle Dropdown';
$lang['AppController.sreader.navigation'] = 'Toggle Navigation';
