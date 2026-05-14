<?php
/**
 * en_us language for the Realtime Register module.
 */
// Basics
$lang['RealtimeRegister.name'] = 'Realtime Register';
$lang['RealtimeRegister.description'] = 'We offer 2,000+ TLDs from over 150 registries, and are constantly adding new ones. We take care of registry updates and all policies and procedures.';
$lang['RealtimeRegister.module_row'] = 'Account';
$lang['RealtimeRegister.module_row_plural'] = 'Accounts';
$lang['RealtimeRegister.module_group'] = 'Account Group';


// Module management
$lang['RealtimeRegister.add_module_row'] = 'Add Account';
$lang['RealtimeRegister.add_module_group'] = 'Add Account Group';
$lang['RealtimeRegister.manage.module_rows_title'] = 'Accounts';

$lang['RealtimeRegister.manage.module_rows_heading.customer'] = 'Customer';
$lang['RealtimeRegister.manage.module_rows_heading.api_key'] = 'API Key';
$lang['RealtimeRegister.manage.module_rows_heading.options'] = 'Options';
$lang['RealtimeRegister.manage.module_rows.edit'] = 'Edit';
$lang['RealtimeRegister.manage.module_rows.delete'] = 'Delete';
$lang['RealtimeRegister.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this Account';

$lang['RealtimeRegister.manage.module_rows_no_results'] = 'There are no Accounts.';

$lang['RealtimeRegister.manage.module_groups_title'] = 'Groups';
$lang['RealtimeRegister.manage.module_groups_heading.name'] = 'Name';
$lang['RealtimeRegister.manage.module_groups_heading.module_rows'] = 'Accounts';
$lang['RealtimeRegister.manage.module_groups_heading.options'] = 'Options';

$lang['RealtimeRegister.manage.module_groups.edit'] = 'Edit';
$lang['RealtimeRegister.manage.module_groups.delete'] = 'Delete';
$lang['RealtimeRegister.manage.module_groups.confirm_delete'] = 'Are you sure you want to delete this Account';

$lang['RealtimeRegister.manage.module_groups.no_results'] = 'There is no Account Group';


// Add row
$lang['RealtimeRegister.add_row.box_title'] = ' Realtime Register - Add Account';
$lang['RealtimeRegister.add_row.add_btn'] = 'Add Account';


// Edit row
$lang['RealtimeRegister.edit_row.box_title'] = ' Realtime Register - Edit Account';
$lang['RealtimeRegister.edit_row.edit_btn'] = 'Update Account';


// Row meta
$lang['RealtimeRegister.row_meta.customer'] = 'Customer';
$lang['RealtimeRegister.row_meta.api_key'] = 'API Key';
$lang['RealtimeRegister.row_meta.sandbox'] = 'Sandbox';


// Errors
$lang['RealtimeRegister.!error.customer.empty'] = 'Please enter a customer handle';
$lang['RealtimeRegister.!error.api_key.valid'] = 'Invalid API Key';
$lang['RealtimeRegister.!error.api_key.valid_connection'] = 'Unable to make a connection using the given credentials.';
$lang['RealtimeRegister.!error.sandbox.format'] = 'Sandbox must be either "true" or "false".';
$lang['RealtimeRegister.!error.module_row.missing'] = 'An internal error occurred. The module row is unavailable.';


// Warnings
$lang['RealtimeRegister.!notice.client_update_prohibited'] = 'You are not allowed to manage this domain.';


// Service info
$lang['RealtimeRegister.service_info.domain'] = 'Domain';


// Service Fields
$lang['RealtimeRegister.service_fields.domain'] = 'Domain';
$lang['RealtimeRegister.service_fields.authcode'] = 'Authorization Code';
$lang['RealtimeRegister.service_fields.ns1'] = 'Name Server 1';
$lang['RealtimeRegister.service_fields.ns2'] = 'Name Server 2';
$lang['RealtimeRegister.service_fields.ns3'] = 'Name Server 3';
$lang['RealtimeRegister.service_fields.ns4'] = 'Name Server 4';
$lang['RealtimeRegister.service_fields.ns5'] = 'Name Server 5';


// Package Fields
$lang['RealtimeRegister.package_fields.epp_code'] = 'EPP Code';

$lang['RealtimeRegister.package_field.tooltip.epp_code'] = 'Whether to allow users to request an EPP Code through the Blesta service interface.';
$lang['RealtimeRegister.package_fields.tld_options'] = 'TLDs';


// Tab contacts
$lang['RealtimeRegister.tab_contacts.title'] = 'Contacts';
$lang['RealtimeRegister.tab_contacts.section_ADMIN'] = 'Administrative';
$lang['RealtimeRegister.tab_contacts.section_BILLING'] = 'Billing';
$lang['RealtimeRegister.tab_contacts.section_TECH'] = 'Technical';

$lang['RealtimeRegister.tab_contacts.field_email'] = 'Email Address';
$lang['RealtimeRegister.tab_contacts.field_phone'] = 'Phone Number';
$lang['RealtimeRegister.tab_contacts.field_first_name'] = 'First Name';
$lang['RealtimeRegister.tab_contacts.field_last_name'] = 'Last Name';
$lang['RealtimeRegister.tab_contacts.field_address1'] = 'Address Line 1';
$lang['RealtimeRegister.tab_contacts.field_address2'] = 'Address Line 2';
$lang['RealtimeRegister.tab_contacts.field_city'] = 'City';
$lang['RealtimeRegister.tab_contacts.field_state'] = 'State';
$lang['RealtimeRegister.tab_contacts.field_zip'] = 'Zip';
$lang['RealtimeRegister.tab_contacts.field_country'] = 'Country';

$lang['RealtimeRegister.tab_contacts.field_update'] = 'Update Contacts';


// Tab client contacts
$lang['RealtimeRegister.tab_client_contacts.title'] = 'Contacts';
$lang['RealtimeRegister.tab_client_contacts.section_ADMIN'] = 'Administrative';
$lang['RealtimeRegister.tab_client_contacts.section_BILLING'] = 'Billing';
$lang['RealtimeRegister.tab_client_contacts.section_TECH'] = 'Technical';

$lang['RealtimeRegister.tab_client_contacts.field_email'] = 'Email Address';
$lang['RealtimeRegister.tab_client_contacts.field_phone'] = 'Phone Number';
$lang['RealtimeRegister.tab_client_contacts.field_first_name'] = 'First Name';
$lang['RealtimeRegister.tab_client_contacts.field_last_name'] = 'Last Name';
$lang['RealtimeRegister.tab_client_contacts.field_address1'] = 'Address Line 1';
$lang['RealtimeRegister.tab_client_contacts.field_address2'] = 'Address Line 2';
$lang['RealtimeRegister.tab_client_contacts.field_city'] = 'City';
$lang['RealtimeRegister.tab_client_contacts.field_state'] = 'State';
$lang['RealtimeRegister.tab_client_contacts.field_zip'] = 'Zip';
$lang['RealtimeRegister.tab_client_contacts.field_country'] = 'Country';

$lang['RealtimeRegister.tab_client_contacts.field_update'] = 'Update Contacts';


// Tab nameservers
$lang['RealtimeRegister.tab_nameservers.title'] = 'Nameservers';
$lang['RealtimeRegister.tab_nameservers.heading'] = 'Nameservers';
$lang['RealtimeRegister.tab_nameservers.field_update'] = 'Update';


// Tab client nameservers
$lang['RealtimeRegister.tab_client_nameservers.title'] = 'Nameservers';
$lang['RealtimeRegister.tab_client_nameservers.heading'] = 'Nameservers';
$lang['RealtimeRegister.tab_client_nameservers.field_update'] = 'Update';


// Tab hosts
$lang['RealtimeRegister.tab_hosts.title'] = 'Hosts';
$lang['RealtimeRegister.tab_hosts.heading'] = 'Hosts';
$lang['RealtimeRegister.tab_hosts.heading_host'] = 'Host';
$lang['RealtimeRegister.tab_hosts.heading_ip_address'] = 'IP Address';
$lang['RealtimeRegister.tab_hosts.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_hosts.heading_add'] = 'Add Host';
$lang['RealtimeRegister.tab_hosts.text_no_hosts'] = 'There are currently no hosts for this domain.';
$lang['RealtimeRegister.tab_hosts.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_hosts.field_host'] = 'Host';
$lang['RealtimeRegister.tab_hosts.field_ip_address'] = 'IP Address';
$lang['RealtimeRegister.tab_hosts.field_add'] = 'Add Host';


// Tab client hosts
$lang['RealtimeRegister.tab_client_hosts.title'] = 'Hosts';
$lang['RealtimeRegister.tab_client_hosts.heading'] = 'Hosts';
$lang['RealtimeRegister.tab_client_hosts.heading_host'] = 'Host';
$lang['RealtimeRegister.tab_client_hosts.heading_ip_address'] = 'IP Address';
$lang['RealtimeRegister.tab_client_hosts.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_client_hosts.heading_add'] = 'Add Host';
$lang['RealtimeRegister.tab_client_hosts.text_no_hosts'] = 'There are currently no hosts for this domain.';
$lang['RealtimeRegister.tab_client_hosts.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_client_hosts.field_host'] = 'Host';
$lang['RealtimeRegister.tab_client_hosts.field_ip_address'] = 'IP Address';
$lang['RealtimeRegister.tab_client_hosts.field_add'] = 'Add Host';


// Tab DNSSEC
$lang['RealtimeRegister.tab_dnssec.title'] = 'DNSSEC';
$lang['RealtimeRegister.tab_dnssec.heading'] = 'DNSSEC Keys';
$lang['RealtimeRegister.tab_dnssec.heading_add_dnssec'] = 'Add DNSSEC Key';
$lang['RealtimeRegister.tab_dnssec.heading_dnssec_digest'] = 'DNSSEC Digest';
$lang['RealtimeRegister.tab_dnssec.heading_protocol'] = 'Protocol';
$lang['RealtimeRegister.tab_dnssec.heading_flags'] = 'Flags';
$lang['RealtimeRegister.tab_dnssec.heading_algorithm'] = 'Algorithm';
$lang['RealtimeRegister.tab_dnssec.heading_public_key'] = 'Public Key';
$lang['RealtimeRegister.tab_dnssec.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_dnssec.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_dnssec.field_protocol'] = 'Protocol';
$lang['RealtimeRegister.tab_dnssec.field_flags'] = 'Flags';
$lang['RealtimeRegister.tab_dnssec.field_algorithm'] = 'Algorithm';
$lang['RealtimeRegister.tab_dnssec.field_publicKey'] = 'Public Key';
$lang['RealtimeRegister.tab_dnssec.field_add'] = 'Add Key';
$lang['RealtimeRegister.tab_dnssec.text_no_dnssec_keys'] = 'There are currently no DNSSEC Keys for this domain.';


// Tab client DNSSEC
$lang['RealtimeRegister.tab_client_dnssec.title'] = 'DNSSEC';
$lang['RealtimeRegister.tab_client_dnssec.heading'] = 'DNSSEC Keys';
$lang['RealtimeRegister.tab_client_dnssec.heading_add_dnssec'] = 'Add DNSSEC Key';
$lang['RealtimeRegister.tab_client_dnssec.heading_dnssec_digest'] = 'DNSSEC Digest';
$lang['RealtimeRegister.tab_client_dnssec.heading_protocol'] = 'Protocol';
$lang['RealtimeRegister.tab_client_dnssec.heading_flags'] = 'Flags';
$lang['RealtimeRegister.tab_client_dnssec.heading_algorithm'] = 'Algorithm';
$lang['RealtimeRegister.tab_client_dnssec.heading_public_key'] = 'Public Key';
$lang['RealtimeRegister.tab_client_dnssec.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_client_dnssec.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_client_dnssec.field_protocol'] = 'Protocol';
$lang['RealtimeRegister.tab_client_dnssec.field_flags'] = 'Flags';
$lang['RealtimeRegister.tab_client_dnssec.field_algorithm'] = 'Algorithm';
$lang['RealtimeRegister.tab_client_dnssec.field_publicKey'] = 'Public Key';
$lang['RealtimeRegister.tab_client_dnssec.field_add'] = 'Add Key';
$lang['RealtimeRegister.tab_client_dnssec.text_no_dnssec_keys'] = 'There are currently no DNSSEC Keys for this domain.';


// Tab DNS
$lang['RealtimeRegister.tab_dns.title'] = 'DNS Zone';
$lang['RealtimeRegister.tab_dns.heading'] = 'DNS Records';
$lang['RealtimeRegister.tab_dns.heading_add_record'] = 'Add DNS Record';
$lang['RealtimeRegister.tab_dns.heading_name'] = 'Name';
$lang['RealtimeRegister.tab_dns.heading_type'] = 'Type';
$lang['RealtimeRegister.tab_dns.heading_content'] = 'Content';
$lang['RealtimeRegister.tab_dns.heading_ttl'] = 'TTL';
$lang['RealtimeRegister.tab_dns.heading_priority'] = 'Priority';
$lang['RealtimeRegister.tab_dns.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_dns.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_dns.field_name'] = 'Name';
$lang['RealtimeRegister.tab_dns.field_type'] = 'Type';
$lang['RealtimeRegister.tab_dns.field_content'] = 'Content';
$lang['RealtimeRegister.tab_dns.field_ttl'] = 'TTL';
$lang['RealtimeRegister.tab_dns.field_prio'] = 'Priority';
$lang['RealtimeRegister.tab_dns.field_add'] = 'Add Record';
$lang['RealtimeRegister.tab_dns.text_no_dns_records'] = 'There are currently no DNS Records for this domain.';
$lang['RealtimeRegister.tab_dns.text_dns_disabled'] = 'Internal DNS Zone has been disabled for this domain.';


// Tab client DNS
$lang['RealtimeRegister.tab_client_dns.title'] = 'DNS Zone';
$lang['RealtimeRegister.tab_client_dns.heading'] = 'DNS Records';
$lang['RealtimeRegister.tab_client_dns.heading_add_record'] = 'Add DNS Record';
$lang['RealtimeRegister.tab_client_dns.heading_name'] = 'Name';
$lang['RealtimeRegister.tab_client_dns.heading_type'] = 'Type';
$lang['RealtimeRegister.tab_client_dns.heading_content'] = 'Content';
$lang['RealtimeRegister.tab_client_dns.heading_ttl'] = 'TTL';
$lang['RealtimeRegister.tab_client_dns.heading_priority'] = 'Priority';
$lang['RealtimeRegister.tab_client_dns.heading_options'] = 'Options';
$lang['RealtimeRegister.tab_client_dns.field_delete'] = 'Delete';
$lang['RealtimeRegister.tab_client_dns.field_name'] = 'Name';
$lang['RealtimeRegister.tab_client_dns.field_type'] = 'Type';
$lang['RealtimeRegister.tab_client_dns.field_content'] = 'Content';
$lang['RealtimeRegister.tab_client_dns.field_ttl'] = 'TTL';
$lang['RealtimeRegister.tab_client_dns.field_prio'] = 'Priority';
$lang['RealtimeRegister.tab_client_dns.field_add'] = 'Add Record';
$lang['RealtimeRegister.tab_client_dns.text_no_dns_records'] = 'There are currently no DNS Records for this domain.';
$lang['RealtimeRegister.tab_client_dns.text_dns_disabled'] = 'Internal DNS Zone has been disabled for this domain.';


// Tab settings
$lang['RealtimeRegister.tab_settings.title'] = 'Settings';
$lang['RealtimeRegister.tab_settings.heading'] = 'Settings';
$lang['RealtimeRegister.tab_settings.heading_dns'] = 'DNS Zone';
$lang['RealtimeRegister.tab_settings.heading_auth_code'] = 'Authorization Code';
$lang['RealtimeRegister.tab_settings.field_registrar_lock'] = 'Registrar Lock';
$lang['RealtimeRegister.tab_settings.field_registrar_lock_yes'] = 'Set the registrar lock. Recommended to prevent unauthorized transfer.';
$lang['RealtimeRegister.tab_settings.field_registrar_lock_no'] = 'Release the registrar lock so the domain can be transferred.';

$lang['RealtimeRegister.tab_settings.field_enable_dns'] = 'Enable DNS Zone';
$lang['RealtimeRegister.tab_settings.field_enable_dns_yes'] = 'Use the internal DNS zone.';
$lang['RealtimeRegister.tab_settings.field_enable_dns_no'] = 'Use external Nameservers.';

$lang['RealtimeRegister.tab_settings.field_request_epp'] = 'Request EPP Code/Transfer Key';
$lang['RealtimeRegister.tab_settings.field_submit'] = 'Update Settings';
$lang['RealtimeRegister.tab_settings.field_authcode'] = 'Authentication Code';
$lang['RealtimeRegister.tab_settings.field_update_auth_code'] = 'Update Authentication Code';


// Tab client settings
$lang['RealtimeRegister.tab_client_settings.title'] = 'Settings';
$lang['RealtimeRegister.tab_client_settings.heading'] = 'Settings';
$lang['RealtimeRegister.tab_client_settings.heading_dns'] = 'DNS Zone';
$lang['RealtimeRegister.tab_client_settings.heading_auth_code'] = 'Authorization Code';
$lang['RealtimeRegister.tab_client_settings.field_registrar_lock'] = 'Registrar Lock';
$lang['RealtimeRegister.tab_client_settings.field_registrar_lock_yes'] = 'Set the registrar lock. Recommended to prevent unauthorized transfer.';
$lang['RealtimeRegister.tab_client_settings.field_registrar_lock_no'] = 'Release the registrar lock so the domain can be transferred.';
$lang['RealtimeRegister.tab_client_settings.field_status'] = 'Domain Status';

$lang['RealtimeRegister.tab_client_settings.field_enable_dns'] = 'Enable DNS Zone';
$lang['RealtimeRegister.tab_client_settings.field_enable_dns_yes'] = 'Use the internal DNS zone.';
$lang['RealtimeRegister.tab_client_settings.field_enable_dns_no'] = 'Use external Nameservers.';

$lang['RealtimeRegister.tab_client_settings.field_request_epp'] = 'Request EPP Code/Transfer Key';
$lang['RealtimeRegister.tab_client_settings.field_submit'] = 'Update Settings';
$lang['RealtimeRegister.tab_client_settings.field_authcode'] = 'Authentication Code';
$lang['RealtimeRegister.tab_client_settings.field_update_auth_code'] = 'Update Authentication Code';

// .US domain fields
$lang['RealtimeRegister.domain.RegistrantNexus'] = 'Registrant Type';
$lang['RealtimeRegister.domain.RegistrantNexus.c11'] = 'US citizen';
$lang['RealtimeRegister.domain.RegistrantNexus.c12'] = 'Permanent resident of the US';
$lang['RealtimeRegister.domain.RegistrantNexus.c21'] = 'US entity or organization';
$lang['RealtimeRegister.domain.RegistrantNexus.c31'] = 'Foreign organization';
$lang['RealtimeRegister.domain.RegistrantNexus.c32'] = 'Foreign organization with an office in the US';
$lang['RealtimeRegister.domain.RegistrantPurpose'] = 'Purpose';
$lang['RealtimeRegister.domain.RegistrantPurpose.p1'] = 'Business';
$lang['RealtimeRegister.domain.RegistrantPurpose.p2'] = 'Non-profit';
$lang['RealtimeRegister.domain.RegistrantPurpose.p3'] = 'Personal';
$lang['RealtimeRegister.domain.RegistrantPurpose.p4'] = 'Educational';
$lang['RealtimeRegister.domain.RegistrantPurpose.p5'] = 'Governmental';

// .EU domain fields
$lang['RealtimeRegister.domain.EUAgreeWhoisPolicy'] = 'Whois Policy';
$lang['RealtimeRegister.domain.EUAgreeWhoisPolicy.yes'] = 'I hereby agree that the Registry is entitled to transfer the data contained in this application to third parties(i) if ordered to do so by a public authority, carrying out its legitimate tasks; and (ii) upon demand of an ADR Provider as mentioned in section 16 of the Terms and Conditions which are published at www.eurid.eu; and (iii) as provided in Section 2 (WHOIS look-up facility) of the .eu Domain Name WHOIS Policy which is published at www.eurid.eu.';
$lang['RealtimeRegister.domain.EUAgreeDeletePolicy'] = 'Deleteion Rules';
$lang['RealtimeRegister.domain.EUAgreeDeletePolicy.yes'] = 'I agree and acknowledge to the special renewal and expiration terms set forth below for this domain name, including those terms set forth in the Registration Agreement. I understand that unless I have set this domain for autorenewal, this domain name must be explicitly renewed by the expiration date or the 20th of the month of expiration, whichever is sooner. (e.g. If the name expires on Sept 4th, 2008, then a manual renewal must be received by Sept 4th, 2008. If name expires on Sep 27th, 2008, the renewal request must be received prior to Sep 20th, 2008). If the name is not manually renewed or previously set to autorenew, a delete request will be issued by RealtimeRegister. When a delete request is issued, the name will remain fully functional in my account until expiration, but will no longer be renewable nor will I be able to make any modifications to the name. These terms are subject to change.';

// .CA domain fields
$lang['RealtimeRegister.domain.CIRALegalType'] = 'Legal Type';
$lang['RealtimeRegister.domain.RegistrantPurpose.cco'] = 'Corporation';
$lang['RealtimeRegister.domain.RegistrantPurpose.cct'] = 'Canadian citizen';
$lang['RealtimeRegister.domain.RegistrantPurpose.res'] = 'Canadian resident';
$lang['RealtimeRegister.domain.RegistrantPurpose.gov'] = 'Government entity';
$lang['RealtimeRegister.domain.RegistrantPurpose.edu'] = 'Educational';
$lang['RealtimeRegister.domain.RegistrantPurpose.ass'] = 'Unincorporated Association';
$lang['RealtimeRegister.domain.RegistrantPurpose.hop'] = 'Hospital';
$lang['RealtimeRegister.domain.RegistrantPurpose.prt'] = 'Partnership';
$lang['RealtimeRegister.domain.RegistrantPurpose.tdm'] = 'Trade-mark';
$lang['RealtimeRegister.domain.RegistrantPurpose.trd'] = 'Trade Union';
$lang['RealtimeRegister.domain.RegistrantPurpose.plt'] = 'Political Party';
$lang['RealtimeRegister.domain.RegistrantPurpose.lam'] = 'Libraries, Archives and Museums';
$lang['RealtimeRegister.domain.RegistrantPurpose.trs'] = 'Trust';
$lang['RealtimeRegister.domain.RegistrantPurpose.abo'] = 'Aboriginal Peoples';
$lang['RealtimeRegister.domain.RegistrantPurpose.inb'] = 'Indian Band';
$lang['RealtimeRegister.domain.RegistrantPurpose.lgr'] = 'Legal Representative';
$lang['RealtimeRegister.domain.RegistrantPurpose.omk'] = 'Official Mark';
$lang['RealtimeRegister.domain.RegistrantPurpose.maj'] = 'The Queen';
$lang['RealtimeRegister.domain.CIRAWhoisDisplay'] = 'Whois';
$lang['RealtimeRegister.domain.CIRAWhoisDisplay.full'] = 'Make Public';
$lang['RealtimeRegister.domain.CIRAWhoisDisplay.private'] = 'Keep Private';

// .CO.UK domain fields
$lang['RealtimeRegister.domain.COUKLegalType'] = 'Legal Type';
$lang['RealtimeRegister.domain.COUKLegalType.ind'] = 'UK individual';
$lang['RealtimeRegister.domain.COUKLegalType.find'] = 'Non-UK individual';
$lang['RealtimeRegister.domain.COUKLegalType.ltd'] = 'UK Limited Company';
$lang['RealtimeRegister.domain.COUKLegalType.plc'] = 'UK Public Limited Company';
$lang['RealtimeRegister.domain.COUKLegalType.ptnr'] = 'UK Partnership';
$lang['RealtimeRegister.domain.COUKLegalType.llp'] = 'UK Limited Liability Partnership';
$lang['RealtimeRegister.domain.COUKLegalType.ip'] = 'UK Industrial/Provident Registered Company';
$lang['RealtimeRegister.domain.COUKLegalType.stra'] = 'UK Sole Trader';
$lang['RealtimeRegister.domain.COUKLegalType.sch'] = 'UK School';
$lang['RealtimeRegister.domain.COUKLegalType.rchar'] = 'UK Registered Charity';
$lang['RealtimeRegister.domain.COUKLegalType.gov'] = 'UK Government Body';
$lang['RealtimeRegister.domain.COUKLegalType.other'] = 'UK Entity (other)';
$lang['RealtimeRegister.domain.COUKLegalType.crc'] = 'UK Corporation by Royal Charter';
$lang['RealtimeRegister.domain.COUKLegalType.fcorp'] = 'Foreign Organization';
$lang['RealtimeRegister.domain.COUKLegalType.stat'] = 'UK Statutory Body FIND';
$lang['RealtimeRegister.domain.COUKLegalType.fother'] = 'Other Foreign Organizations';
$lang['RealtimeRegister.domain.COUKCompanyID'] = 'Company ID Number';
$lang['RealtimeRegister.domain.COUKRegisteredfor'] = 'Registrant Name';

// .ME.UK domain fields
$lang['RealtimeRegister.domain.MEUKLegalType'] = 'Legal Type';
$lang['RealtimeRegister.domain.MEUKLegalType.ind'] = 'UK individual';
$lang['RealtimeRegister.domain.MEUKLegalType.find'] = 'Non-UK individual';
$lang['RealtimeRegister.domain.MEUKLegalType.ltd'] = 'UK Limited Company';
$lang['RealtimeRegister.domain.MEUKLegalType.plc'] = 'UK Public Limited Company';
$lang['RealtimeRegister.domain.MEUKLegalType.ptnr'] = 'UK Partnership';
$lang['RealtimeRegister.domain.MEUKLegalType.llp'] = 'UK Limited Liability Partnership';
$lang['RealtimeRegister.domain.MEUKLegalType.ip'] = 'UK Industrial/Provident Registered Company';
$lang['RealtimeRegister.domain.MEUKLegalType.stra'] = 'UK Sole Trader';
$lang['RealtimeRegister.domain.MEUKLegalType.sch'] = 'UK School';
$lang['RealtimeRegister.domain.MEUKLegalType.rchar'] = 'UK Registered Charity';
$lang['RealtimeRegister.domain.MEUKLegalType.gov'] = 'UK Government Body';
$lang['RealtimeRegister.domain.MEUKLegalType.other'] = 'UK Entity (other)';
$lang['RealtimeRegister.domain.MEUKLegalType.crc'] = 'UK Corporation by Royal Charter';
$lang['RealtimeRegister.domain.MEUKLegalType.fcorp'] = 'Foreign Organization';
$lang['RealtimeRegister.domain.MEUKLegalType.stat'] = 'UK Statutory Body FIND';
$lang['RealtimeRegister.domain.MEUKLegalType.fother'] = 'Other Foreign Organizations';
$lang['RealtimeRegister.domain.MEUKCompanyID'] = 'Company ID Number';
$lang['RealtimeRegister.domain.MEUKRegisteredfor'] = 'Registrant Name';

// .ORG.UK domain fields
$lang['RealtimeRegister.domain.ORGUKLegalType'] = 'Legal Type';
$lang['RealtimeRegister.domain.ORGUKLegalType.ind'] = 'UK individual';
$lang['RealtimeRegister.domain.ORGUKLegalType.find'] = 'Non-UK individual';
$lang['RealtimeRegister.domain.ORGUKLegalType.ltd'] = 'UK Limited Company';
$lang['RealtimeRegister.domain.ORGUKLegalType.plc'] = 'UK Public Limited Company';
$lang['RealtimeRegister.domain.ORGUKLegalType.ptnr'] = 'UK Partnership';
$lang['RealtimeRegister.domain.ORGUKLegalType.llp'] = 'UK Limited Liability Partnership';
$lang['RealtimeRegister.domain.ORGUKLegalType.ip'] = 'UK Industrial/Provident Registered Company';
$lang['RealtimeRegister.domain.ORGUKLegalType.stra'] = 'UK Sole Trader';
$lang['RealtimeRegister.domain.ORGUKLegalType.sch'] = 'UK School';
$lang['RealtimeRegister.domain.ORGUKLegalType.rchar'] = 'UK Registered Charity';
$lang['RealtimeRegister.domain.ORGUKLegalType.gov'] = 'UK Government Body';
$lang['RealtimeRegister.domain.ORGUKLegalType.other'] = 'UK Entity (other)';
$lang['RealtimeRegister.domain.ORGUKLegalType.crc'] = 'UK Corporation by Royal Charter';
$lang['RealtimeRegister.domain.ORGUKLegalType.fcorp'] = 'Foreign Organization';
$lang['RealtimeRegister.domain.ORGUKLegalType.stat'] = 'UK Statutory Body FIND';
$lang['RealtimeRegister.domain.ORGUKLegalType.fother'] = 'Other Foreign Organizations';
$lang['RealtimeRegister.domain.ORGUKCompanyID'] = 'Company ID Number';
$lang['RealtimeRegister.domain.ORGUKRegisteredfor'] = 'Registrant Name';

// .ASIA domain fields
$lang['RealtimeRegister.domain.ASIALegalEntityType'] = 'Legal Type';
$lang['RealtimeRegister.domain.ASIALegalEntityType.corporation'] = 'Corporations or Companies';
$lang['RealtimeRegister.domain.ASIALegalEntityType.cooperative'] = 'Cooperatives';
$lang['RealtimeRegister.domain.ASIALegalEntityType.partnership'] = 'Partnerships or Collectives';
$lang['RealtimeRegister.domain.ASIALegalEntityType.government'] = 'Government Bodies';
$lang['RealtimeRegister.domain.ASIALegalEntityType.politicalParty'] = 'Political parties or Trade Unions';
$lang['RealtimeRegister.domain.ASIALegalEntityType.society'] = 'Trusts, Estates, Associations or Societies';
$lang['RealtimeRegister.domain.ASIALegalEntityType.institution'] = 'Institutions';
$lang['RealtimeRegister.domain.ASIALegalEntityType.naturalPerson'] = 'Natural Persons';
$lang['RealtimeRegister.domain.ASIAIdentForm'] = 'Form of Identity';
$lang['RealtimeRegister.domain.ASIAIdentForm.certificate'] = 'Certificate of Incorporation';
$lang['RealtimeRegister.domain.ASIAIdentForm.legislation'] = 'Charter';
$lang['RealtimeRegister.domain.ASIAIdentForm.societyRegistry'] = 'Societies Registry';
$lang['RealtimeRegister.domain.ASIAIdentForm.politicalPartyRegistry'] = 'Political Party Registry';
$lang['RealtimeRegister.domain.ASIAIdentForm.passport'] = 'Passport/ Citizenship ID';
$lang['RealtimeRegister.domain.ASIAIdentNumber'] = 'Identity Number';

// .FR domain fields
$lang['RealtimeRegister.!tooltip.FRRegistrantBirthDate'] = 'Set your birth date in the format: YYYY-MM-DD';
$lang['RealtimeRegister.!tooltip.FRRegistrantLegalId'] = 'The SIREN number is the first part of the SIRET NUMBER and consists of 9 digits. The SIRET number is a unique identification number with 14 digits.';
$lang['RealtimeRegister.!tooltip.FRRegistrantDunsNumber'] = 'The DUNS number consists of 9 digits, issued by Dun & Bradstreet.';
$lang['RealtimeRegister.!tooltip.FRRegistrantJoDateDec'] = 'French associations listed with the Journal Officiel de la République Francaise should set a declaration date in the format: YYYY-MM-DD';
$lang['RealtimeRegister.!tooltip.FRRegistrantJoDatePub'] = 'Enter the publication date in the Journal Officiel in the format: YYYY-MM-DD';

$lang['RealtimeRegister.domain.FRLegalType'] = 'Legal Type';
$lang['RealtimeRegister.domain.FRLegalType.individual'] = 'Individual';
$lang['RealtimeRegister.domain.FRLegalType.company'] = 'Company';
$lang['RealtimeRegister.domain.FRRegistrantBirthDate'] = 'Birth Date';
$lang['RealtimeRegister.domain.FRRegistrantBirthplace'] = 'Birth Place';
$lang['RealtimeRegister.domain.FRRegistrantLegalId'] = 'SIREN/SIRET Number';
$lang['RealtimeRegister.domain.FRRegistrantTradeNumber'] = 'Trademark Number';
$lang['RealtimeRegister.domain.FRRegistrantDunsNumber'] = 'DUNS Number';
$lang['RealtimeRegister.domain.FRRegistrantLocalId'] = 'European Economic Area Local ID';
$lang['RealtimeRegister.domain.FRRegistrantJoDateDec'] = 'The Journal Official Declaration Date';
$lang['RealtimeRegister.domain.FRRegistrantJoDatePub'] = 'The Journal Official Publication Date';
$lang['RealtimeRegister.domain.FRRegistrantJoNumber'] = 'The Journal Official Number';
$lang['RealtimeRegister.domain.FRRegistrantJoPage'] = 'The Journal Official Announcement Page Number';
