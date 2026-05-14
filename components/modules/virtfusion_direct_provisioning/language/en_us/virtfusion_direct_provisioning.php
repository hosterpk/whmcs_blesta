<?php
/**
 * en_us language for the VirtFusion Direct Provisioning module.
 */
// Basics
$lang['VirtfusionDirectProvisioning.name'] = 'VirtFusion Direct Provisioning';
$lang['VirtfusionDirectProvisioning.description'] = 'The VirtFusion Blesta Direct Provisioning module is a simple module that can create, terminate, suspend and unsuspend servers with a direct login bridge between Blesta and VirtFusion.';
$lang['VirtfusionDirectProvisioning.module_row'] = 'Server';
$lang['VirtfusionDirectProvisioning.module_row_plural'] = 'Servers';
$lang['VirtfusionDirectProvisioning.module_group'] = 'Server Group';


// Module management
$lang['VirtfusionDirectProvisioning.add_module_row'] = 'Add Server';
$lang['VirtfusionDirectProvisioning.manage.module_rows_title'] = 'Servers';

$lang['VirtfusionDirectProvisioning.manage.module_rows_heading.name'] = 'Name';
$lang['VirtfusionDirectProvisioning.manage.module_rows_heading.hostname'] = 'Hostname';
$lang['VirtfusionDirectProvisioning.manage.module_rows_heading.api_token'] = 'API Token';
$lang['VirtfusionDirectProvisioning.manage.module_rows_heading.options'] = 'Options';
$lang['VirtfusionDirectProvisioning.manage.module_rows.edit'] = 'Edit';
$lang['VirtfusionDirectProvisioning.manage.module_rows.delete'] = 'Delete';
$lang['VirtfusionDirectProvisioning.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this Server';

$lang['VirtfusionDirectProvisioning.manage.module_rows_no_results'] = 'There are no Servers.';

$lang['VirtfusionDirectProvisioning.order_options.first'] = 'First';

// Add row
$lang['VirtfusionDirectProvisioning.add_row.box_title'] = 'VirtFusion Direct Provisioning - Add Server';
$lang['VirtfusionDirectProvisioning.add_row.add_btn'] = 'Add Server';


// Edit row
$lang['VirtfusionDirectProvisioning.edit_row.box_title'] = 'VirtFusion Direct Provisioning - Edit Server';
$lang['VirtfusionDirectProvisioning.edit_row.edit_btn'] = 'Update Server';


// Row meta
$lang['VirtfusionDirectProvisioning.row_meta.name'] = 'Name';
$lang['VirtfusionDirectProvisioning.row_meta.hostname'] = 'Hostname';
$lang['VirtfusionDirectProvisioning.row_meta.api_token'] = 'API Token';




// Errors
$lang['VirtfusionDirectProvisioning.!error.name.empty'] = 'Please enter a valid name';
$lang['VirtfusionDirectProvisioning.!error.hostname.empty'] = 'Please enter a valid Hostname';
$lang['VirtfusionDirectProvisioning.!error.api_token.empty'] = 'Please enter API Token';
$lang['VirtfusionDirectProvisioning.!error.hostname.valid'] = 'Invalid Hostname';
$lang['VirtfusionDirectProvisioning.!error.api_token.valid'] = 'Invalid API Token';
$lang['VirtfusionDirectProvisioning.!error.module_row.missing'] = 'An internal error occurred. The module row is unavailable.';
$lang['VirtfusionDirectProvisioning.!error.meta[hypervisor_group_id].valid'] = 'Invalid Hypervisor Group ID.';
$lang['VirtfusionDirectProvisioning.!error.meta[default_ipv4].valid'] = 'Invalid number of IPv4 addresses.';
$lang['VirtfusionDirectProvisioning.!error.meta[package_id].valid'] = 'Invalid package id.';

// Client Errors
$lang['VirtfusionDirectProvisioning.client.!error.host.valid'] = 'The hostname appears to be invalid.';

// Service info
$lang['VirtfusionDirectProvisioning.service_info.server_id'] = 'Server ID';
$lang['VirtfusionDirectProvisioning.service_info.main_ip'] = 'Main IP Address';
$lang['VirtfusionDirectProvisioning.service_info.base_ips'] = 'Base IP Addresses';
$lang['VirtfusionDirectProvisioning.service_info.extra_ips'] = 'Extra IP Addresses';
$lang['VirtfusionDirectProvisioning.service_info.label'] = 'Label';

// Service Fields
$lang['VirtfusionDirectProvisioning.service_fields.server_id'] = 'Server ID';
$lang['VirtfusionDirectProvisioning.service_fields.label'] = 'Label';


// Manage
$lang['VirtfusionDirectProvisioning.tabManage'] = 'Manage';
$lang['VirtfusionDirectProvisioning.tabManage.header'] = 'Manage';
$lang['VirtfusionDirectProvisioning.tabManage.submit'] = 'Submit';


// Manage IP Address
$lang['VirtfusionDirectProvisioning.ipAddresses'] = 'IP Addresses';
$lang['VirtfusionDirectProvisioning.ipAddresses.header'] = 'IP Addresses';
$lang['VirtfusionDirectProvisioning.ipAddresses.main'] = 'Main IP Address';
$lang['VirtfusionDirectProvisioning.ipAddresses.base'] = 'Base IP Addresses';
$lang['VirtfusionDirectProvisioning.ipAddresses.extra'] = 'Additional IP Addresses';
$lang['VirtfusionDirectProvisioning.ipAddresses.ipv6'] = 'IPv6 Address';
$lang['VirtfusionDirectProvisioning.ipAddresses.add'] = 'Add IP';
$lang['VirtfusionDirectProvisioning.ipAddresses.remove'] = 'Remove IP';
$lang['VirtfusionDirectProvisioning.ipAddresses.submit'] = 'Submit';

$lang['VirtfusionDirectProvisioning.ipAddresses.ipv6_refresh'] = 'Refresh IPv6';

// Package Fields
$lang['VirtfusionDirectProvisioning.package_fields.hypervisor_group_id'] = 'Hypervisor Group ID';
$lang['VirtfusionDirectProvisioning.package_fields.default_ipv4'] = 'Default IPv4';
$lang['VirtfusionDirectProvisioning.package_fields.package_id'] = 'Package ID';
$lang['VirtfusionDirectProvisioning.package_fields.os_id'] = 'Default Operating System ID';
$lang['VirtfusionDirectProvisioning.package_fields.os_id.help_text'] = 'The OS ID is located in `media/templates`. Once you select a template, the ID is the last number in the url.';

// Option Fields
$lang['VirtfusionDirectProvisioning.option.extra_ip'] = 'additional_num_ips';

// Client Fields
$lang['VirtfusionDirectProvisioning.option_fields.hostname.label'] = 'Hostname';
$lang['VirtfusionDirectProvisioning.option_fields.hostname.tooltip'] = 'Please enter the name of your server using a fully qualified domain name. For example server.mydomain.com or web.mydomain.com';

$lang['VirtfusionDirectProvisioning.option_fields.extra_ip_addresses'] = 'Extra IP Addresses';
$lang['VirtfusionDirectProvisioning.option_fields.extra_ip_addresses.tooltip'] = 'This field must be selecting if downgrading the number of extra IPs.';


// Cron Tasks

