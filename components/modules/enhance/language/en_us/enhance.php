<?php
/**
 * en_us language for the Enhance module.
 */
// Basics
$lang['Enhance.name'] = 'Enhance';
$lang['Enhance.description'] = 'Enhance is a next-gen shared hosting control panel.';
$lang['Enhance.module_row'] = 'Server';
$lang['Enhance.module_row_plural'] = 'Servers';
$lang['Enhance.module_group'] = 'Server Group';


// Module management
$lang['Enhance.add_module_row'] = 'Add Server';
$lang['Enhance.add_module_group'] = 'Add Server Group';
$lang['Enhance.manage.module_rows_title'] = 'Servers';

$lang['Enhance.manage.module_rows_heading.server_label'] = 'Server Label';
$lang['Enhance.manage.module_rows_heading.hostname'] = 'Hostname';
$lang['Enhance.manage.module_rows_heading.org_id'] = 'Organization ID';
$lang['Enhance.manage.module_rows_heading.api_token'] = 'API Token';
$lang['Enhance.manage.module_rows_heading.options'] = 'Options';
$lang['Enhance.manage.module_rows.edit'] = 'Edit';
$lang['Enhance.manage.module_rows.delete'] = 'Delete';
$lang['Enhance.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this Server';

$lang['Enhance.manage.module_rows_no_results'] = 'There are no Servers.';

$lang['Enhance.manage.module_groups_title'] = 'Groups';
$lang['Enhance.manage.module_groups_heading.name'] = 'Name';
$lang['Enhance.manage.module_groups_heading.module_rows'] = 'Servers';
$lang['Enhance.manage.module_groups_heading.options'] = 'Options';

$lang['Enhance.manage.module_groups.edit'] = 'Edit';
$lang['Enhance.manage.module_groups.delete'] = 'Delete';
$lang['Enhance.manage.module_groups.confirm_delete'] = 'Are you sure you want to delete this Server';

$lang['Enhance.manage.module_groups.no_results'] = 'There is no Server Group';

$lang['Enhance.order_options.roundrobin'] = 'Evenly Distribute Among Servers';
$lang['Enhance.order_options.first'] = 'First Non-full Server';


// Add row
$lang['Enhance.add_row.box_title'] = 'Enhance - Add Server';
$lang['Enhance.add_row.add_btn'] = 'Add Server';


// Edit row
$lang['Enhance.edit_row.box_title'] = 'Enhance - Edit Server';
$lang['Enhance.edit_row.edit_btn'] = 'Update Server';


// Row meta
$lang['Enhance.row_meta.server_label'] = 'Server Label';
$lang['Enhance.row_meta.hostname'] = 'Hostname';
$lang['Enhance.row_meta.org_id'] = 'Organization ID';
$lang['Enhance.row_meta.api_token'] = 'API Token';


// Errors
$lang['Enhance.!error.server_label.empty'] = 'Please enter a server label.';
$lang['Enhance.!error.hostname.format'] = 'Please enter a valid hostname or IP address.';
$lang['Enhance.!error.org_id.empty'] = 'Please enter an organization ID.';
$lang['Enhance.!error.api_token.empty'] = 'Please enter an API token.';
$lang['Enhance.!error.api_token.valid'] = 'Unable to connect to the Enhance server. Please verify your API credentials.';
$lang['Enhance.!error.module_row.missing'] = 'An internal error occurred. The module row is unavailable.';
$lang['Enhance.!error.domain.format'] = 'Please enter a valid domain name.';
$lang['Enhance.!error.username.format'] = 'The username may contain only letters, numbers, dashes, and underscores and must be between 3 and 16 characters in length.';
$lang['Enhance.!error.password.length'] = 'The password must be at least 8 characters in length.';
$lang['Enhance.!error.password.change'] = 'Unable to change the password. Please try again.';


// Change Password
$lang['Enhance.tabChangePassword'] = 'Change Password';
$lang['Enhance.tabChangePassword.header'] = 'Change Password';
$lang['Enhance.tabChangePassword.submit'] = 'Submit';


// Client Change Password
$lang['Enhance.tabClientChangePassword'] = 'Change Password';
$lang['Enhance.tabClientChangePassword.header'] = 'Change Password';
$lang['Enhance.tabClientChangePassword.submit'] = 'Submit';


// Success messages
$lang['Enhance.success.password.changed'] = 'Password successfully changed.';


// Service info
$lang['Enhance.service_info.domain'] = 'Domain';
$lang['Enhance.service_info.username'] = 'System Username';
$lang['Enhance.service_info.email'] = 'Login Email';
$lang['Enhance.service_info.password'] = 'Password';
$lang['Enhance.service_info.options'] = 'Options';
$lang['Enhance.service_info.option_login'] = 'Login to Control Panel';


// Service Fields
$lang['Enhance.service_fields.domain'] = 'Domain';
$lang['Enhance.service_fields.username'] = 'Username';
$lang['Enhance.service_fields.password'] = 'Password';


// Package Fields
$lang['Enhance.package_fields.package'] = 'Package';
$lang['Enhance.package_fields.package_tooltip'] = 'Enter the Plan ID from your Enhance control panel. Plans could not be loaded automatically.';
