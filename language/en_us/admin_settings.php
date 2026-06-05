<?php
/**
 * Language definitions for the Admin Settings controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Company settings
$lang['AdminSettings.company.page_title'] = 'Settings';
$lang['AdminSettings.company.category_company'] = 'Company';
$lang['AdminSettings.company.category_system'] = 'System';

$lang['AdminSettings.company.boxtitle_settings'] = 'Settings';

$lang['AdminSettings.company.heading_general'] = 'General';
$lang['AdminSettings.company.text_general'] = 'Configure localization, internationalization options, and client contact types.';

$lang['AdminSettings.company.heading_billing'] = 'Billing/Payment';
$lang['AdminSettings.company.text_billing'] = 'Configure invoice options and customization, automatic payments, payment notices, and coupons.';

$lang['AdminSettings.company.heading_emails'] = 'Emails';
$lang['AdminSettings.company.text_emails'] = 'Configure mail settings, edit email templates, and create and manage email signatures.';

$lang['AdminSettings.company.heading_messengers'] = 'Messengers';
$lang['AdminSettings.company.text_messengers'] = 'Install and manage messengers, which are used for notifications and communication.';

$lang['AdminSettings.company.heading_currencies'] = 'Currencies';
$lang['AdminSettings.company.text_currencies'] = 'Update and add currencies, set the default currency, configure multi-currency, and configure exchange rates.';

$lang['AdminSettings.company.heading_clientgroups'] = 'Client Groups';
$lang['AdminSettings.company.text_clientgroups'] = 'All clients belong to a group, which determines when that client is invoiced, charged, and more. Create and manage client groups and settings.';

$lang['AdminSettings.company.heading_automation'] = 'Automation';
$lang['AdminSettings.company.text_automation'] = 'Access and control automated tasks in Blesta.';

$lang['AdminSettings.company.heading_modules'] = 'Modules';
$lang['AdminSettings.company.text_modules'] = 'Install and manage modules, which are used for the provisioning and management of services.';

$lang['AdminSettings.company.heading_gateways'] = 'Payment Gateways';
$lang['AdminSettings.company.text_gateways'] = 'Enable and disable merchant and non-merchant payment processors for the acceptance of payment.';

$lang['AdminSettings.company.heading_taxes'] = 'Taxes';
$lang['AdminSettings.company.text_taxes'] = 'Enable or disable tax, set a Tax ID/VATIN, and create single or multi-level tax rules.';

$lang['AdminSettings.company.heading_clientoptions'] = 'Client Options';
$lang['AdminSettings.company.text_clientoptions'] = 'Create and manage custom client fields and select required client fields.';

$lang['AdminSettings.company.heading_plugins'] = 'Plugins';
$lang['AdminSettings.company.text_plugins'] = 'Install, uninstall, and manage plugins, which extend and customize the core functionality of Blesta.';

$lang['AdminSettings.company.heading_lookandfeel'] = 'Look and Feel';
$lang['AdminSettings.company.text_lookandfeel'] = 'Customize the look and feel of Blesta by managing color themes, or updating the client interface template.';

$lang['AdminSettings.company.heading_feeds'] = 'Data Feeds';
$lang['AdminSettings.company.text_feeds'] = 'Data feeds allow package pricing, names and descriptions, to be easily embedded in an external website.';


// System settings
$lang['AdminSettings.system.page_title'] = 'Settings';
$lang['AdminSettings.system.category_company'] = 'Company';
$lang['AdminSettings.system.category_system'] = 'System';

$lang['AdminSettings.system.boxtitle_settings'] = 'Settings';

$lang['AdminSettings.system.heading_general'] = 'General';
$lang['AdminSettings.system.text_general'] = 'Configure system-wide settings, including enabling Maintenance Mode, the GeoIP database, or updating your License Key.';

$lang['AdminSettings.system.heading_companies'] = 'Companies';
$lang['AdminSettings.system.text_companies'] = 'Add or update Companies.';

$lang['AdminSettings.system.heading_backup'] = 'Backup';
$lang['AdminSettings.system.text_backup'] = 'Configure automatic backups using SFTP, or Amazon S3, or download a backup to your local computer.';

$lang['AdminSettings.system.heading_api'] = 'API Access';
$lang['AdminSettings.system.text_api'] = "Control access to this installation's API.";

$lang['AdminSettings.system.heading_marketplace'] = 'Marketplace';
$lang['AdminSettings.system.text_marketplace'] = 'Browse and download plugins, modules, gateways, and more from the Blesta Marketplace.';

$lang['AdminSettings.system.heading_automation'] = 'Automation';
$lang['AdminSettings.system.text_automation'] = 'Determine your Cron or Scheduled Task information.';

$lang['AdminSettings.system.heading_staff'] = 'Staff';
$lang['AdminSettings.system.text_staff'] = 'Create or modify Staff accounts and permissions. Staff have access to the Blesta Admin area.';

$lang['AdminSettings.system.heading_upgrade'] = 'Upgrade Options';
$lang['AdminSettings.system.text_upgrade'] = 'View upgrade information and downloads.';

$lang['AdminSettings.system.heading_help'] = 'Help';
$lang['AdminSettings.system.text_help'] = 'View help resources for your installation of Blesta.';


// Search metadata - keywords and descriptions for settings sidebar search
// Company > General
$lang['AdminSettings.search.keywords_localization'] = 'language locale timezone date time format currency region country calendar';
$lang['AdminSettings.search.description_localization'] = 'Configure regional settings including language, timezone, and date formats';
$lang['AdminSettings.search.keywords_internationalization'] = 'i18n language translation install locale international';
$lang['AdminSettings.search.description_internationalization'] = 'Install and manage language packs for multiple languages';
$lang['AdminSettings.search.keywords_encryption'] = 'security encryption passphrase aes keys private data protection';
$lang['AdminSettings.search.description_encryption'] = 'Configure encryption settings for sensitive data storage';
$lang['AdminSettings.search.keywords_contacttypes'] = 'contact types billing technical administrative primary';
$lang['AdminSettings.search.description_contacttypes'] = 'Manage contact type categories for client accounts';
$lang['AdminSettings.search.keywords_marketing'] = 'marketing campaigns email newsletter promotions advertising';
$lang['AdminSettings.search.description_marketing'] = 'Configure marketing and promotional settings';
$lang['AdminSettings.search.keywords_smartsearch'] = 'search quick find client invoice service smart intelligent';
$lang['AdminSettings.search.description_smartsearch'] = 'Configure smart search functionality and indexing';
$lang['AdminSettings.search.keywords_humanverification'] = 'captcha recaptcha verification spam bot protection security';
$lang['AdminSettings.search.description_humanverification'] = 'Configure CAPTCHA and anti-spam verification settings';

// Company > Look and Feel
$lang['AdminSettings.search.keywords_themes'] = 'theme appearance skin design colors branding custom dark mode light';
$lang['AdminSettings.search.description_themes'] = 'Select and customize visual themes';
$lang['AdminSettings.search.keywords_template'] = 'template view directory admin client portal theme select';
$lang['AdminSettings.search.description_template'] = 'Select admin and client portal view templates';
$lang['AdminSettings.search.keywords_layout'] = 'layout dashboard widgets cards grid arrangement';
$lang['AdminSettings.search.description_layout'] = 'Configure dashboard and page layouts';
$lang['AdminSettings.search.keywords_customize'] = 'customize branding logo favicon colors css custom icon header gradient';
$lang['AdminSettings.search.description_customize'] = 'Customize branding, logos, and colors';
$lang['AdminSettings.search.keywords_navigation'] = 'navigation menu sidebar links items order custom';
$lang['AdminSettings.search.description_navigation'] = 'Manage navigation menu items and order';
$lang['AdminSettings.search.keywords_actions'] = 'actions permissions staff client access control acl';
$lang['AdminSettings.search.description_actions'] = 'Manage action permissions and access control';

// Company > Billing/Payment
$lang['AdminSettings.search.keywords_invoiceandchargeoptions'] = 'invoice charge options autodebit auto debit suspension renewal quotation proration service changes billing schedule';
$lang['AdminSettings.search.description_invoiceandchargeoptions'] = 'Configure invoice generation timing, auto-debit, service suspension, and billing options';
$lang['AdminSettings.search.keywords_invoicecustomization'] = 'invoice customization numbering format appearance paper size logo display';
$lang['AdminSettings.search.description_invoicecustomization'] = 'Customize invoice appearance, numbering, and display options';
$lang['AdminSettings.search.keywords_invoicedelivery'] = 'invoice delivery email print download send method distribution';
$lang['AdminSettings.search.description_invoicedelivery'] = 'Configure how invoices are delivered to clients';
$lang['AdminSettings.search.keywords_latefees'] = 'late fees charges overdue past due penalty percentage fixed amount minimum';
$lang['AdminSettings.search.description_latefees'] = 'Configure late fee amounts and rules for overdue invoices';
$lang['AdminSettings.search.keywords_notices'] = 'notices reminders payment due overdue notification cancellation autodebit pending email alert';
$lang['AdminSettings.search.description_notices'] = 'Configure invoice payment reminder notices and notifications';
$lang['AdminSettings.search.keywords_coupons'] = 'coupons discount promo code promotion percentage savings voucher';
$lang['AdminSettings.search.description_coupons'] = 'Create and manage promotional coupon codes and discounts';
$lang['AdminSettings.search.keywords_acceptedpaymenttypes'] = 'accepted payment types methods credit card ach check money order allowed';
$lang['AdminSettings.search.description_acceptedpaymenttypes'] = 'Manage which payment methods clients can use';
$lang['AdminSettings.search.keywords_credithandling'] = 'credit handling limits payment credits apply balance prepay minimum maximum';
$lang['AdminSettings.search.description_credithandling'] = 'Configure payment credit settings and balance limits';

// Company > Messengers
$lang['AdminSettings.search.keywords_messengers'] = 'messengers sms text message notification integration install';
$lang['AdminSettings.search.description_messengers'] = 'Install and manage messenger integrations';
$lang['AdminSettings.search.keywords_messengerconfiguration'] = 'messenger configuration setup api credentials provider settings';
$lang['AdminSettings.search.description_messengerconfiguration'] = 'Configure messenger service settings and credentials';
$lang['AdminSettings.search.keywords_messagetemplates'] = 'message templates sms text notification content customize';
$lang['AdminSettings.search.description_messagetemplates'] = 'Customize messenger notification templates';

// Company > Taxes
$lang['AdminSettings.search.keywords_basictaxsettings'] = 'tax settings sales vat gst inclusive exclusive basic calculation enable';
$lang['AdminSettings.search.description_basictaxsettings'] = 'Configure basic tax calculation and display settings';
$lang['AdminSettings.search.keywords_taxrules'] = 'tax rules rates jurisdiction state country province region percentage';
$lang['AdminSettings.search.description_taxrules'] = 'Manage tax rules and rates by region and jurisdiction';

// Company > Emails
$lang['AdminSettings.search.keywords_mailsettings'] = 'mail settings smtp sendmail oauth2 delivery server host port email configuration test';
$lang['AdminSettings.search.description_mailsettings'] = 'Configure email delivery method and mail server settings';
$lang['AdminSettings.search.keywords_emailtemplates'] = 'email templates notification message content subject body customize';
$lang['AdminSettings.search.description_emailtemplates'] = 'Customize email notification templates and content';
$lang['AdminSettings.search.keywords_htmltemplates'] = 'html templates email design wrapper layout header footer style';
$lang['AdminSettings.search.description_htmltemplates'] = 'Design HTML email wrapper templates for styling';
$lang['AdminSettings.search.keywords_signatures'] = 'signatures email footer signoff sign text append';
$lang['AdminSettings.search.description_signatures'] = 'Manage email signatures appended to outgoing emails';

// Company > Client Options
$lang['AdminSettings.search.keywords_generalclientsettings'] = 'client settings options preferences registration portal general default';
$lang['AdminSettings.search.description_generalclientsettings'] = 'Configure general client portal settings and preferences';
$lang['AdminSettings.search.keywords_clientcustomfields'] = 'custom fields client registration form data additional profile';
$lang['AdminSettings.search.description_clientcustomfields'] = 'Create custom data fields for client profiles and registration';
$lang['AdminSettings.search.keywords_requiredclientfields'] = 'required fields mandatory client registration validation enforce';
$lang['AdminSettings.search.description_requiredclientfields'] = 'Configure which client profile fields are mandatory';

// Company > Currencies
$lang['AdminSettings.search.keywords_currencysetup'] = 'currency setup exchange rate conversion default base pricing';
$lang['AdminSettings.search.description_currencysetup'] = 'Configure currency settings and exchange rates';
$lang['AdminSettings.search.keywords_activecurrencies'] = 'active currencies enabled available multi-currency add remove';
$lang['AdminSettings.search.description_activecurrencies'] = 'Manage which currencies are available for use';

// Company > Data Feeds
$lang['AdminSettings.search.keywords_general'] = 'data feeds api endpoint public pricing enable disable';
$lang['AdminSettings.search.description_general'] = 'Enable and configure general data feed settings';
$lang['AdminSettings.search.keywords_feedsettings'] = 'feed settings configuration endpoint data individual';
$lang['AdminSettings.search.description_feedsettings'] = 'Manage individual data feed configurations';

// Company > Single-link groups
$lang['AdminSettings.search.keywords_automation'] = 'automation cron tasks scheduling jobs recurring timed run';
$lang['AdminSettings.search.description_automation'] = 'Configure automated task scheduling and cron jobs';
$lang['AdminSettings.search.keywords_electronicinvoices'] = 'electronic invoices einvoicing edi digital xml peppol';
$lang['AdminSettings.search.description_electronicinvoices'] = 'Configure electronic invoicing format and settings';
$lang['AdminSettings.search.keywords_modules'] = 'modules provisioning hosting domain server cpanel plesk whm install';
$lang['AdminSettings.search.description_modules'] = 'Install and manage service provisioning modules';
$lang['AdminSettings.search.keywords_paymentgateways'] = 'payment gateways processor merchant credit card paypal stripe checkout install';
$lang['AdminSettings.search.description_paymentgateways'] = 'Install and configure payment gateway integrations';
$lang['AdminSettings.search.keywords_plugins'] = 'plugins extensions addons features functionality install manage';
$lang['AdminSettings.search.description_plugins'] = 'Install and manage system plugins and extensions';
$lang['AdminSettings.search.keywords_clientgroups'] = 'client groups categories segment pricing tiers organization';
$lang['AdminSettings.search.description_clientgroups'] = 'Manage client group categories and settings';

// System > General
$lang['AdminSettings.search.keywords_basicsetup'] = 'basic setup directories paths root web temp uploads logs cache system health';
$lang['AdminSettings.search.description_basicsetup'] = 'Configure system directories, paths, and health checks';
$lang['AdminSettings.search.keywords_geoipsettings'] = 'geoip geolocation ip address location maxmind country detection database';
$lang['AdminSettings.search.description_geoipsettings'] = 'Configure GeoIP database for location detection';
$lang['AdminSettings.search.keywords_maintenance'] = 'maintenance mode downtime offline system status message reason';
$lang['AdminSettings.search.description_maintenance'] = 'Enable maintenance mode and set downtime messages';
$lang['AdminSettings.search.keywords_licensekey'] = 'license key activation product registration serial support';
$lang['AdminSettings.search.description_licensekey'] = 'Manage your Blesta license key and activation';
$lang['AdminSettings.search.keywords_paymenttypes'] = 'payment types methods check cash wire transfer money order define';
$lang['AdminSettings.search.description_paymenttypes'] = 'Define system-wide payment type options';

// System > Backup
$lang['AdminSettings.search.keywords_ondemand'] = 'backup on demand download database manual restore export';
$lang['AdminSettings.search.description_ondemand'] = 'Create and download database backups on demand';
$lang['AdminSettings.search.keywords_secureftp'] = 'backup sftp ftp secure file transfer remote automatic scheduled';
$lang['AdminSettings.search.description_secureftp'] = 'Configure automatic backups via secure FTP';
$lang['AdminSettings.search.keywords_amazons3'] = 'backup amazon s3 aws cloud storage remote automatic bucket';
$lang['AdminSettings.search.description_amazons3'] = 'Configure automatic backups to Amazon S3';

// System > Staff
$lang['AdminSettings.search.keywords_managestaff'] = 'staff users employees administrators manage add remove deactivate';
$lang['AdminSettings.search.description_managestaff'] = 'Add, edit, and manage staff member accounts';
$lang['AdminSettings.search.keywords_staffgroups'] = 'staff groups roles permissions access levels teams acl';
$lang['AdminSettings.search.description_staffgroups'] = 'Manage staff permission groups and access levels';

// System > Help
$lang['AdminSettings.search.keywords_resources'] = 'resources help documentation support knowledge base guide';
$lang['AdminSettings.search.description_resources'] = 'Access help documentation and support resources';
$lang['AdminSettings.search.keywords_releasenotes'] = 'release notes changelog updates version history whats new';
$lang['AdminSettings.search.description_releasenotes'] = 'View release notes and version change history';
$lang['AdminSettings.search.keywords_aboutblesta'] = 'about blesta version info credits system information license';
$lang['AdminSettings.search.description_aboutblesta'] = 'View system version information and credits';

// System > Single-link groups
$lang['AdminSettings.search.keywords_companies'] = 'companies multi-company organizations business units hostname manage';
$lang['AdminSettings.search.description_companies'] = 'Manage companies and multi-company configuration';
$lang['AdminSettings.search.keywords_apiaccess'] = 'api access keys authentication developer integration rest token';
$lang['AdminSettings.search.description_apiaccess'] = 'Manage API keys and developer access';
$lang['AdminSettings.search.keywords_ai'] = 'ai artificial intelligence openai chatbot assistant llm';
$lang['AdminSettings.search.description_ai'] = 'Configure AI integration settings';
$lang['AdminSettings.search.keywords_upgradeoptions'] = 'upgrade update version migration system patch';
$lang['AdminSettings.search.description_upgradeoptions'] = 'Manage system upgrades and version updates';
$lang['AdminSettings.search.keywords_marketplace'] = 'marketplace extensions plugins modules themes store download browse';
$lang['AdminSettings.search.description_marketplace'] = 'Browse and install extensions from the marketplace';
