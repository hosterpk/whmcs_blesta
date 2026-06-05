<?php
/**
 * Language definitions for the Admin Myinfo controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Success messages
$lang['AdminMyinfo.!success.updated'] = 'Your account settings were successfully updated.';
$lang['AdminMyinfo.!success.notices_updated'] = 'Your notice settings were successfully updated.';
$lang['AdminMyinfo.!success.notifications_updated'] = 'Your notification settings were successfully updated.';


// Tabs
$lang['AdminMyinfo.gettabnames.text_index'] = 'Account';
$lang['AdminMyinfo.gettabnames.text_notices'] = 'Notices';
$lang['AdminMyinfo.gettabnames.text_notifications'] = 'Notifications';


// Index
$lang['AdminMyinfo.index.page_title'] = 'My Information';
$lang['AdminMyinfo.index.boxtitle_myinfo'] = 'My Information';

// Section headings
$lang['AdminMyinfo.index.heading_profile_picture'] = 'Profile Picture';
$lang['AdminMyinfo.index.heading_account_information'] = 'Account Information';
$lang['AdminMyinfo.index.heading_two_factor'] = 'Two Factor Authentication';
$lang['AdminMyinfo.index.heading_additional_settings'] = 'Additional Settings';

// Avatar
$lang['AdminMyinfo.index.field_avatar'] = 'Profile Picture';
$lang['AdminMyinfo.index.link_remove_avatar'] = 'Remove Image';
$lang['AdminMyinfo.index.text_avatar_recommendation'] = 'Recommended: 150x150px, JPG or PNG, max 2MB';

// Two factor
$lang['AdminMyinfo.index.text_scan_qr'] = 'Scan with Authenticator App';
$lang['AdminMyinfo.index.text_authenticator_apps'] = 'Use Google Authenticator, Authy, or any TOTP-compatible app';
$lang['AdminMyinfo.index.field_username'] = 'Username';
$lang['AdminMyinfo.index.field_firstname'] = 'First Name';
$lang['AdminMyinfo.index.field_lastname'] = 'Last Name';
$lang['AdminMyinfo.index.field_email'] = 'E-mail';
$lang['AdminMyinfo.index.field_mobileemail'] = 'Mobile E-mail';
$lang['AdminMyinfo.index.field_mobilenumber'] = 'Mobile Number';
$lang['AdminMyinfo.index.field_newpass'] = 'New Password';
$lang['AdminMyinfo.index.field_confirmpass'] = 'Confirm Password';
$lang['AdminMyinfo.index.field_recovery_email'] = 'Recovery Email (Optional)';
$lang['AdminMyinfo.index.field_twofactormode'] = 'Two Factor Authentication';
$lang['AdminMyinfo.index.field_twofactorkey'] = 'Two Factor Key';
$lang['AdminMyinfo.index.field_twofactorpin'] = 'Two Factor Pin';
$lang['AdminMyinfo.index.field_language'] = 'Language';
$lang['AdminMyinfo.index.field_otp'] = 'One-time Password';
$lang['AdminMyinfo.index.note_otp'] = 'To confirm your two factor authentication is configured correctly, enter your generated one-time password.';
$lang['AdminMyinfo.index.field_groups'] = 'Staff Groups';
$lang['AdminMyinfo.index.field_currentpass'] = 'Current Password';

$lang['AdminMyinfo.index.field_infosubmit'] = 'Update Account';


// Email Notices
$lang['AdminMyinfo.notices.page_title'] = 'My Information > Notices';
$lang['AdminMyinfo.notices.heading_notices'] = 'Email BCC Notices';
$lang['AdminMyinfo.notices.heading_subscription_notices'] = 'Email Subscription Notices';
$lang['AdminMyinfo.notices.field_noticesubmit'] = 'Update Notices';
$lang['AdminMyinfo.notices.no_bcc_results'] = 'There are no email BCC notices available to your staff group.';
$lang['AdminMyinfo.notices.no_subscription_results'] = 'There are no subscription notices available to your staff group.';

// Notifications
$lang['AdminMyinfo.notifications.page_title'] = 'My Information > Notifications';
$lang['AdminMyinfo.notifications.heading_notifications'] = 'Notifications';
$lang['AdminMyinfo.notifications.field_notificationsubmit'] = 'Update Notifications';
$lang['AdminMyinfo.notifications.no_results'] = 'There are no notifications available to your staff group.';

// Icon Bar
$lang['AdminMyinfo.!success.iconbar_updated'] = 'Your icon bar settings were successfully updated.';
$lang['AdminMyinfo.!success.iconbar_reset'] = 'Your icon bar settings were reset to default.';
$lang['AdminMyinfo.iconbar.page_title'] = 'My Info > Icon Bar';
$lang['AdminMyinfo.iconbar.heading_iconbar'] = 'Icon Bar';
$lang['AdminMyinfo.iconbar.text_info'] = 'Configure which icons appear in the icon bar and their order. Drag icons to rearrange them, click the edit button to change the icon, and use checkboxes to enable or disable icons. The icon bar provides quick access to frequently used features.';
$lang['AdminMyinfo.iconbar.btn_save'] = 'Save Changes';
$lang['AdminMyinfo.iconbar.btn_reset'] = 'Reset to Default';
$lang['AdminMyinfo.iconbar.btn_create_custom'] = 'Create Custom Icon';
$lang['AdminMyinfo.iconbar.modal_create_title'] = 'Create Custom Icon';
$lang['AdminMyinfo.iconbar.modal_edit_title'] = 'Edit Icon';
$lang['AdminMyinfo.iconbar.field_name'] = 'Name';
$lang['AdminMyinfo.iconbar.field_url'] = 'URL';
$lang['AdminMyinfo.iconbar.field_icon_class'] = 'Icon Class (Bootstrap Icons)';
$lang['AdminMyinfo.iconbar.field_item_name'] = 'Item Name';
$lang['AdminMyinfo.iconbar.text_name_description'] = 'Enter a name for this icon bar item';
$lang['AdminMyinfo.iconbar.text_url_description'] = 'Enter the URL this icon should link to';
$lang['AdminMyinfo.iconbar.text_icon_description'] = 'Enter a Bootstrap Icon class name (e.g., bi-grid, bi-people, bi-calendar-event).';
$lang['AdminMyinfo.iconbar.text_browse_icons'] = 'Browse icons';
$lang['AdminMyinfo.iconbar.btn_modal_apply'] = 'Apply';
$lang['AdminMyinfo.iconbar.btn_modal_create'] = 'Create Icon';
$lang['AdminMyinfo.iconbar.btn_modal_cancel'] = 'Cancel';
$lang['AdminMyinfo.iconbar.confirm_reset'] = 'Are you sure you want to reset the icon bar to default settings?';
$lang['AdminMyinfo.iconbar.field_show_ai_chatbot'] = 'Show Chatbot Icon';
$lang['AdminMyinfo.iconbar.text_show_ai_chatbot'] = 'Display the AI chatbot icon in the icon bar. Uncheck to remove.';
