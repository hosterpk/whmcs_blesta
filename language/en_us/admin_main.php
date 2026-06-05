<?php
/**
 * Language definitions for the Admin Main controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Errors
$lang['AdminMain.!error.delete_event.staff_id'] = "Only the event's creator may delete this event.";
$lang['AdminMain.!error.event_editable'] = "Only the event's creator may delete that event.";


// Success
$lang['AdminMain.!success.event_added'] = 'The event has been successfully added!';
$lang['AdminMain.!success.event_edited'] = 'The event has been successfully updated!';
$lang['AdminMain.!success.event_deleted'] = 'The event has been successfully deleted!';


// Index
$lang['AdminMain.index.page_title'] = 'Dashboard';
$lang['AdminMain.index.heading_quicklinks'] = 'Quick Links';
$lang['AdminMain.index.no_quicklinks'] = 'No Quick Links yet. Click the star icon on any page to add it here.';
$lang['AdminMain.index.manage_widgets'] = 'Manage Widgets';
$lang['AdminMain.index.customize_dashboard'] = 'Customize Dashboard';
$lang['AdminMain.index.modal_manage_widgets_title'] = 'Manage Dashboard Widgets';
$lang['AdminMain.index.modal_manage_widgets_close'] = 'Close';
$lang['AdminMain.index.modal_delete_quicklink_title'] = 'Remove Quick Link';
$lang['AdminMain.index.modal_delete_quicklink_message'] = 'Are you sure you want to remove "%1$s" from Quick Links?';
$lang['AdminMain.index.modal_delete_quicklink_confirm'] = 'Remove';
$lang['AdminMain.index.modal_delete_quicklink_cancel'] = 'Cancel';


// Manage widgets
$lang['AdminMain.managewidgets.text_widgets'] = 'Drag widgets from the right to the left to add them to your dashboard, or from left to right to remove them.';
$lang['AdminMain.managewidgets.text_toggle_widgets'] = 'Click on widgets to show or hide them from your dashboard.';
$lang['AdminMain.managewidgets.text_version'] = 'ver %1$s'; // %1$s is the version number of the plugin
$lang['AdminMain.managewidgets.text_author'] = 'Author: ';


// Update dashboard
$lang['AdminMain.updatedashboard.text_layout'] = 'Select a layout below to customize your dashboard.';


// Calendar
$lang['AdminMain.calendar.page_title'] = 'Calendar';
$lang['AdminMain.calendar.boxtitle_calendar'] = 'Calendar';
$lang['AdminMain.calendar.categorylink_addevent'] = 'Create Event';
$lang['AdminMain.calendar.category_month'] = 'Month';
$lang['AdminMain.calendar.category_week'] = 'Week';
$lang['AdminMain.calendar.category_day'] = 'Day';
$lang['AdminMain.calendar.category_list'] = 'List';
$lang['AdminMain.calendar.category_today'] = 'Today';

$lang['AdminMain.getEvents.shared_event_title'] = '%2$s %3$s: %1$s'; // %1$s is the event title, %2$s is the staff's first name, %3$s is the staff's last name


// Add Calendar Event
$lang['AdminMain.addevent.boxtitle_addevent'] = 'Create Event';
$lang['AdminMain.addevent.field_title'] = 'Title';
$lang['AdminMain.addevent.field_start_date'] = 'Start Date';
$lang['AdminMain.addevent.field_end_date'] = 'End Date';
$lang['AdminMain.addevent.field_shared'] = 'Make this event viewable by other staff';
$lang['AdminMain.addevent.field_all_day'] = 'All day';
$lang['AdminMain.addevent.field_addeventsubmit'] = 'Create Event';


// Edit Calendar Event
$lang['AdminMain.editevent.boxtitle_editevent'] = 'Edit Event';
$lang['AdminMain.editevent.field_title'] = 'Title';
$lang['AdminMain.editevent.field_start_date'] = 'Start Date';
$lang['AdminMain.editevent.field_end_date'] = 'End Date';
$lang['AdminMain.editevent.field_shared'] = 'Make this event viewable by other staff';
$lang['AdminMain.editevent.field_all_day'] = 'All day';
$lang['AdminMain.editevent.field_editeventsubmit'] = 'Update Event';
$lang['AdminMain.editevent.field_deleteeventsubmit'] = 'Delete Event';
$lang['AdminMain.editevent.confirm_delete'] = 'Really delete this event?';
