<?php
/**
 * en_us language for the Nominet module.
 */
// Basics
$lang['Nominet.name'] = 'Nominet';
$lang['Nominet.description'] = 'Nominet is the domain name registry that runs .UK top level domain.';
$lang['Nominet.module_row'] = 'Account';
$lang['Nominet.module_row_plural'] = 'Accounts';
$lang['Nominet.module_group'] = 'Accounts Group';


// Module management
$lang['Nominet.add_module_row'] = 'Add Account';
$lang['Nominet.add_module_group'] = 'Add Accounts Group';
$lang['Nominet.manage.module_rows_title'] = 'Accounts';

$lang['Nominet.manage.module_rows_heading.username'] = 'Username';
$lang['Nominet.manage.module_rows_heading.options'] = 'Options';
$lang['Nominet.manage.module_rows.edit'] = 'Edit';
$lang['Nominet.manage.module_rows.delete'] = 'Delete';
$lang['Nominet.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this Account';

$lang['Nominet.manage.module_rows_no_results'] = 'There are no Accounts.';

$lang['Nominet.manage.module_groups_title'] = 'Groups';
$lang['Nominet.manage.module_groups_heading.name'] = 'Name';
$lang['Nominet.manage.module_groups_heading.module_rows'] = 'Accounts';
$lang['Nominet.manage.module_groups_heading.options'] = 'Options';

$lang['Nominet.manage.module_groups.edit'] = 'Edit';
$lang['Nominet.manage.module_groups.delete'] = 'Delete';
$lang['Nominet.manage.module_groups.confirm_delete'] = 'Are you sure you want to delete this Account';

$lang['Nominet.manage.module_groups.no_results'] = 'There is no Accounts Group';


// Options
$lang['Nominet.order_options.roundrobin'] = 'Evenly Distribute Among Servers';
$lang['Nominet.order_options.first'] = 'First Non-full Server';


// Add row
$lang['Nominet.add_row.box_title'] = 'Nominet - Add Account';
$lang['Nominet.add_row.add_btn'] = 'Add Account';


// Edit row
$lang['Nominet.edit_row.box_title'] = 'Nominet - Edit Account';
$lang['Nominet.edit_row.edit_btn'] = 'Update Account';


// Row meta
$lang['Nominet.row_meta.username'] = 'Username';
$lang['Nominet.row_meta.password'] = 'Password';
$lang['Nominet.row_meta.secure'] = 'Use Secure Connection';
$lang['Nominet.row_meta.sandbox'] = 'Sandbox';


// Errors
$lang['Nominet.!error.module_row.missing'] = 'An internal error occurred. The module row is unavailable.';
$lang['Nominet.!error.domain.valid'] = 'The given domain is invalid.';
$lang['Nominet.!error.ns1.valid'] = 'Invalid Name Server 1';
$lang['Nominet.!error.ns2.valid'] = 'Invalid Name Server 2';
$lang['Nominet.!error.ns3.valid'] = 'Invalid Name Server 3';
$lang['Nominet.!error.ns4.valid'] = 'Invalid Name Server 4';
$lang['Nominet.!error.ns5.valid'] = 'Invalid Name Server 5';


// Service info
$lang['Nominet.service_info.domain'] = 'Domain';


// Service Fields
$lang['Nominet.service_fields.domain'] = 'Domain';
$lang['Nominet.service_fields.ns1'] = 'Name Server 1';
$lang['Nominet.service_fields.ns2'] = 'Name Server 2';
$lang['Nominet.service_fields.ns3'] = 'Name Server 3';
$lang['Nominet.service_fields.ns4'] = 'Name Server 4';
$lang['Nominet.service_fields.ns5'] = 'Name Server 5';


// Package Fields
$lang['Nominet.package_fields.epp_code'] = 'EPP Code';
$lang['Nominet.package_field.tooltip.epp_code'] = 'Whether to allow users to request an EPP Code through the Blesta service interface.';
$lang['Nominet.package_fields.tld_options'] = 'TLDs';
$lang['Nominet.package_fields.ns1'] = 'Name Server 1';
$lang['Nominet.package_fields.ns2'] = 'Name Server 2';
$lang['Nominet.package_fields.ns3'] = 'Name Server 3';
$lang['Nominet.package_fields.ns4'] = 'Name Server 4';
$lang['Nominet.package_fields.ns5'] = 'Name Server 5';


// IPS tag fields
$lang['Nominet.tag_fields.enable_tag'] = 'Enable IPS Tag';
$lang['Nominet.tag_fields.enable_tag_option'] = 'Enables the option for this domain to be pushed to another registrar by supplying a new IPS tag';


// Contact fields
$lang['Nominet.contact_fields.first_name'] = 'First Name';
$lang['Nominet.contact_fields.last_name'] = 'Last Name';
$lang['Nominet.contact_fields.email'] = 'E-mail Address';
$lang['Nominet.contact_fields.address1'] = 'Address 1';
$lang['Nominet.contact_fields.address2'] = 'Address 2';
$lang['Nominet.contact_fields.city'] = 'City';
$lang['Nominet.contact_fields.state'] = 'State';
$lang['Nominet.contact_fields.zip'] = 'Zip Code';
$lang['Nominet.contact_fields.country'] = 'Country';
$lang['Nominet.contact_fields.phone'] = 'Phone';


// Contacts tab
$lang['Nominet.tab_whois.title'] = 'Contacts';
$lang['Nominet.tab_whois.section_admin'] = 'Administrative';
$lang['Nominet.tab_whois.section_tech'] = 'Technical';
$lang['Nominet.tab_whois.section_billing'] = 'Billing';
$lang['Nominet.tab_whois.field_submit'] = 'Update Contacts';


// Nameservers tab
$lang['Nominet.tab_nameservers.title'] = 'Nameservers';
$lang['Nominet.tab_nameservers.heading'] = 'Nameservers';
$lang['Nominet.tab_nameservers.field_submit'] = 'Update Nameservers';


// DNSSEC tab
$lang['Nominet.tab_dnssec.title'] = 'DNSSEC';
$lang['Nominet.tab_dnssec.heading'] = 'DNSSEC Records';
$lang['Nominet.tab_dnssec.heading_add_record'] = 'Add Record';
$lang['Nominet.tab_dnssec.header_key_tag'] = 'Key Tag';
$lang['Nominet.tab_dnssec.header_digest_type'] = 'Digest Type';
$lang['Nominet.tab_dnssec.header_algorithm'] = 'Algorithm';
$lang['Nominet.tab_dnssec.header_digest'] = 'Digest';
$lang['Nominet.tab_dnssec.header_options'] = 'Options';
$lang['Nominet.tab_dnssec.field_key_tag'] = 'Key Tag';
$lang['Nominet.tab_dnssec.field_digest_type'] = 'Digest Type';
$lang['Nominet.tab_dnssec.field_algorithm'] = 'Algorithm';
$lang['Nominet.tab_dnssec.field_digest'] = 'Digest';
$lang['Nominet.tab_dnssec.field_delete'] = 'Delete';
$lang['Nominet.tab_dnssec.field_submit'] = 'Add Record';
$lang['Nominet.tab_dnssec.no_results'] = 'There are no DNSSEC records to show.';


// Settings tab
$lang['Nominet.tab_settings.title'] = 'Settings';
$lang['Nominet.tab_settings.heading_push_domain'] = 'Push Domain';
$lang['Nominet.tab_settings.heading_auth_code'] = 'Authorization Code (EPP)';
$lang['Nominet.tab_settings.text_push_domain'] = 'Transfer (push) the domain to another registrar. (This action cannot be undone)';
$lang['Nominet.tab_settings.text_auth_code'] = 'Use this authorization code to transfer your domain to another provider.';
$lang['Nominet.tab_settings.field_tag'] = 'IPS Tag';
$lang['Nominet.tab_settings.field_submit'] = 'Update Domain';


// Client contacts tab
$lang['Nominet.tab_client_whois.title'] = 'Contacts';
$lang['Nominet.tab_client_whois.heading'] = 'Contacts';
$lang['Nominet.tab_client_whois.section_admin'] = 'Administrative';
$lang['Nominet.tab_client_whois.section_tech'] = 'Technical';
$lang['Nominet.tab_client_whois.section_billing'] = 'Billing';
$lang['Nominet.tab_client_whois.field_submit'] = 'Update Contacts';


// Client nameservers tab
$lang['Nominet.tab_client_nameservers.title'] = 'Nameservers';
$lang['Nominet.tab_client_nameservers.heading'] = 'Nameservers';
$lang['Nominet.tab_client_nameservers.field_submit'] = 'Update Nameservers';


// Client DNSSEC tab
$lang['Nominet.tab_client_dnssec.title'] = 'DNSSEC';
$lang['Nominet.tab_client_dnssec.heading'] = 'DNSSEC Records';
$lang['Nominet.tab_client_dnssec.heading_add_record'] = 'Add Record';
$lang['Nominet.tab_client_dnssec.header_key_tag'] = 'Key Tag';
$lang['Nominet.tab_client_dnssec.header_digest_type'] = 'Digest Type';
$lang['Nominet.tab_client_dnssec.header_algorithm'] = 'Algorithm';
$lang['Nominet.tab_client_dnssec.header_digest'] = 'Digest';
$lang['Nominet.tab_client_dnssec.header_options'] = 'Options';
$lang['Nominet.tab_client_dnssec.field_key_tag'] = 'Key Tag';
$lang['Nominet.tab_client_dnssec.field_digest_type'] = 'Digest Type';
$lang['Nominet.tab_client_dnssec.field_algorithm'] = 'Algorithm';
$lang['Nominet.tab_client_dnssec.field_digest'] = 'Digest';
$lang['Nominet.tab_client_dnssec.field_delete'] = 'Delete';
$lang['Nominet.tab_client_dnssec.field_submit'] = 'Add Record';
$lang['Nominet.tab_client_dnssec.no_results'] = 'There are no DNSSEC records to show.';


// Client settings tab
$lang['Nominet.tab_client_settings.title'] = 'Settings';
$lang['Nominet.tab_client_settings.heading_push_domain'] = 'Push Domain';
$lang['Nominet.tab_client_settings.heading_auth_code'] = 'Authorization Code (EPP)';
$lang['Nominet.tab_client_settings.text_push_domain'] = 'Transfer (push) the domain to another registrar. (This action cannot be undone)';
$lang['Nominet.tab_client_settings.text_auth_code'] = 'Use this authorization code to transfer your domain to another provider.';
$lang['Nominet.tab_client_settings.field_tag'] = 'IPS Tag';
$lang['Nominet.tab_client_settings.field_submit'] = 'Update Domain';
