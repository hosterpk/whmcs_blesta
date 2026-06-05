<?php
/**
 * Language definitions for the Admin Company Emails settings controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Success messages
$lang['AdminCompanyEmails.!success.edittemplate_updated'] = 'The email template settings were successfully updated!';
$lang['AdminCompanyEmails.!success.editsignature_updated'] = 'The email signature has been successfully updated!';
$lang['AdminCompanyEmails.!success.addsignature_created'] = 'The email signature has been successfully created!';
$lang['AdminCompanyEmails.!success.deletesignature_deleted'] = 'The email signature has been successfully deleted!';
$lang['AdminCompanyEmails.!success.addhtmltemplate_created'] = 'The email HTML template has been successfully created!';
$lang['AdminCompanyEmails.!success.edithtmltemplate_updated'] = 'The email HTML template has been successfully updated!';
$lang['AdminCompanyEmails.!success.deletehtmltemplate_deleted'] = 'The email HTML template has been successfully deleted!';
$lang['AdminCompanyEmails.!success.mail_updated'] = 'The Mail settings have been successfully updated!';
$lang['AdminCompanyEmails.!success.smtp_test'] = 'SMTP connection was successful!';
$lang['AdminCompanyEmails.!success.oauth2_test'] = 'OAuth 2.0 connection was successful!';
$lang['AdminCompanyEmails.!success.sendmail_test'] = 'Sendmail connection was successful!';
$lang['AdminCompanyEmails.!success.snapshot_restored'] = 'The email template has been successfully restored from the snapshot!';


// Tooltips
$lang['AdminCompanyEmails.!tooltip.from_name'] = 'This is the friendly name of the email address displayed by the recipient\'s mail client.';
$lang['AdminCompanyEmails.!tooltip.from'] = 'This is the email address that this message should appear from.';
$lang['AdminCompanyEmails.!tooltip.subject'] = 'This is the subject of the message. Email subjects may use tags.';
$lang['AdminCompanyEmails.!tooltip.email_template_group_id'] = 'This is the HTML template that will be used for this email.';
$lang['AdminCompanyEmails.!tooltip.email_signature_id'] = 'The message will be appended with the selected signature.';
$lang['AdminCompanyEmails.!tooltip.include_attachments'] = 'If any file attachments are sent with this email template, unchecking this option will no longer attach them to emails.';
$lang['AdminCompanyEmails.!tooltip.status'] = 'No emails will be sent using this template unless this option is enabled.';

$lang['AdminCompanyEmails.!tooltip.html_email'] = 'Check to allow email with HTML content to be delivered. A plain-text version of emails will always be sent.';
$lang['AdminCompanyEmails.!tooltip.mail_delivery'] = 'SMTP uses a configured SMTP server for email delivery while Sendmail will attempt to send email through the Sendmail binary on the system. SMTP is generally faster, more secure, and more reliable, so that is the recommended option.';
$lang['AdminCompanyEmails.!tooltip.sendmail_path'] = 'The sendmail command to run including path and flags.';
$lang['AdminCompanyEmails.!tooltip.sendmail_from'] = 'This is only for testing the send mail command and will be used to send a test email to a random disposable email address.';
$lang['AdminCompanyEmails.!tooltip.smtp_host'] = 'Set the host name used to communicate with the SMTP server.';
$lang['AdminCompanyEmails.!tooltip.smtp_port'] = 'Set the port used to communicate with the SMTP server.';
$lang['AdminCompanyEmails.!tooltip.smtp_user'] = 'Set the SMTP user account to send mail through.';
$lang['AdminCompanyEmails.!tooltip.smtp_password'] = 'Set the password for the SMTP user account.';
$lang['AdminCompanyEmails.!tooltip.smtp_from'] = 'The from address to use when testing the settings.';
$lang['AdminCompanyEmails.!tooltip.smtp_to'] = 'This is only for testing the send mail command and will be used to send a test email to the specified email address (or a random disposable one).';

$lang['AdminCompanyEmails.!tooltip.oauth2_host'] = 'Set the host name used to communicate with the SMTP server.';
$lang['AdminCompanyEmails.!tooltip.oauth2_port'] = 'Set the port used to communicate with the SMTP server.';
$lang['AdminCompanyEmails.!tooltip.oauth2_user'] = 'Set the OAuth 2.0 user account to send mail through the SMTP server.';
$lang['AdminCompanyEmails.!tooltip.oauth2_client_id'] = 'Set the OAuth 2.0 Client ID (sometimes referred as Application ID) for the SMTP service.';
$lang['AdminCompanyEmails.!tooltip.oauth2_client_secret'] = 'Set the OAuth 2.0 Client Secret (sometimes referred as Application Secret) for the SMTP service.';
$lang['AdminCompanyEmails.!tooltip.oauth2_redirect_uri'] = 'Copy this URL and add it as an authorized redirect URI in your OAuth provider\'s application settings (e.g., Google Cloud Console, Microsoft Azure Portal). This is required for OAuth authentication to work.';
$lang['AdminCompanyEmails.!tooltip.oauth2_from'] = 'The from address to use when testing the settings.';
$lang['AdminCompanyEmails.!tooltip.oauth2_to'] = 'This is only for testing the send mail command and will be used to send a test email to the specified email address (or a random disposable one).';

$lang['AdminCompanyEmails.!tooltip.submitmail'] = 'Update Settings';


// Common language
$lang['AdminCompanyEmails.!cancel.field.cancel'] = 'Cancel';


// Email templates
$lang['AdminCompanyEmails.templates.page_title'] = 'Settings > Company > Emails > Email Templates';
$lang['AdminCompanyEmails.templates.boxtitle_templates'] = 'Email Templates';

$lang['AdminCompanyEmails.templates.heading_client'] = 'Client Emails';
$lang['AdminCompanyEmails.templates.heading_staff'] = 'Staff Emails';
$lang['AdminCompanyEmails.templates.heading_shared'] = 'Shared Emails';
$lang['AdminCompanyEmails.templates.heading_plugins'] = 'Plugin Emails';

$lang['AdminCompanyEmails.templates.text_name'] = 'Name';
$lang['AdminCompanyEmails.templates.text_plugin'] = 'Plugin';
$lang['AdminCompanyEmails.templates.text_description'] = 'Description';
$lang['AdminCompanyEmails.templates.text_options'] = 'Options';

$lang['AdminCompanyEmails.templates.option_edit'] = 'Edit';

$lang['AdminCompanyEmails.templates.no_results'] = 'There are no templates of this type.';

$lang['AdminCompanyEmails.templates.field_templatesubmit'] = 'Apply';
$lang['AdminCompanyEmails.templates.text_items_selected'] = 'items selected';

$lang['AdminCompanyEmails.templates.payment_cc_approved_name'] = 'Payment Approved (Credit Card)';
$lang['AdminCompanyEmails.templates.payment_cc_approved_desc'] = 'Notice sent after a successful credit card payment is approved.';
$lang['AdminCompanyEmails.templates.payment_cc_declined_name'] = 'Payment Declined (Credit Card)';
$lang['AdminCompanyEmails.templates.payment_cc_declined_desc'] = 'Notice sent after a credit card payment attempt is declined.';
$lang['AdminCompanyEmails.templates.payment_cc_error_name'] = 'Payment Error (Credit Card)';
$lang['AdminCompanyEmails.templates.payment_cc_error_desc'] = 'Notice sent after a credit card payment attempt results in error.';
$lang['AdminCompanyEmails.templates.payment_ach_approved_name'] = 'Payment Approved (ACH)';
$lang['AdminCompanyEmails.templates.payment_ach_approved_desc'] = 'Notice sent after a successful ACH payment is approved.';
$lang['AdminCompanyEmails.templates.payment_ach_declined_name'] = 'Payment Declined (ACH)';
$lang['AdminCompanyEmails.templates.payment_ach_declined_desc'] = 'Notice sent after a ACH payment attempt is declined.';
$lang['AdminCompanyEmails.templates.payment_ach_error_name'] = 'Payment Error (ACH)';
$lang['AdminCompanyEmails.templates.payment_ach_error_desc'] = 'Notice sent after an ACH payment attempt results in error.';
$lang['AdminCompanyEmails.templates.payment_manual_approved_name'] = 'Payment Received (Manual Entry)';
$lang['AdminCompanyEmails.templates.payment_manual_approved_desc'] = 'Notice sent after a payment is manually recorded.';
$lang['AdminCompanyEmails.templates.payment_nonmerchant_approved_name'] = 'Payment Received (Non-Merchant)';
$lang['AdminCompanyEmails.templates.payment_nonmerchant_approved_desc'] = 'Notice sent after a payment is received from a non-merchant gateway.';
$lang['AdminCompanyEmails.templates.credit_card_expiration_name'] = 'Credit Card Expiration';
$lang['AdminCompanyEmails.templates.credit_card_expiration_desc'] = 'Notice sent when an active credit card is about to expire.';
$lang['AdminCompanyEmails.templates.low_balance_notification_name'] = 'Low Balance Notification';
$lang['AdminCompanyEmails.templates.low_balance_notification_desc'] = 'Notice sent when client credit balance falls below configured threshold.';
$lang['AdminCompanyEmails.templates.invoice_delivery_unpaid_name'] = 'Invoice Delivery (Unpaid)';
$lang['AdminCompanyEmails.templates.invoice_delivery_unpaid_desc'] = 'Notice containing a PDF copy of an unpaid invoice.';
$lang['AdminCompanyEmails.templates.invoice_delivery_paid_name'] = 'Invoice Delivery (Paid)';
$lang['AdminCompanyEmails.templates.invoice_delivery_paid_desc'] = 'Notice containing a PDF copy of a paid invoice.';
$lang['AdminCompanyEmails.templates.invoice_notice_first_name'] = 'Invoice Notice (1st)';
$lang['AdminCompanyEmails.templates.invoice_notice_first_desc'] = 'First invoice notice, either a reminder to pay or late notice.';
$lang['AdminCompanyEmails.templates.invoice_notice_second_name'] = 'Invoice Notice (2nd)';
$lang['AdminCompanyEmails.templates.invoice_notice_second_desc'] = 'Second invoice notice, either a reminder to pay or late notice.';
$lang['AdminCompanyEmails.templates.invoice_notice_third_name'] = 'Invoice Notice (3rd)';
$lang['AdminCompanyEmails.templates.invoice_notice_third_desc'] = 'Third invoice notice, either a reminder to pay or late notice.';
$lang['AdminCompanyEmails.templates.reset_password_name'] = 'Password Reset';
$lang['AdminCompanyEmails.templates.reset_password_desc'] = 'Password reset email containing a link to change the account password.';
$lang['AdminCompanyEmails.templates.forgot_username_name'] = 'Forgot Username';
$lang['AdminCompanyEmails.templates.forgot_username_desc'] = 'Username recovery email containing the username on record for the account.';
$lang['AdminCompanyEmails.templates.service_cancellation_name'] = 'Service Cancellation';
$lang['AdminCompanyEmails.templates.service_cancellation_desc'] = 'Service cancellation notice, sent when a service is canceled.';
$lang['AdminCompanyEmails.templates.service_scheduled_cancellation_name'] = 'Service Scheduled Cancellation';
$lang['AdminCompanyEmails.templates.service_scheduled_cancellation_desc'] = 'Service scheduled cancellation notice, sent when a service is scheduled for cancellation.';
$lang['AdminCompanyEmails.templates.service_suspension_name'] = 'Service Suspension';
$lang['AdminCompanyEmails.templates.service_suspension_desc'] = 'Service suspended notice, sent when a service is automatically suspended.';
$lang['AdminCompanyEmails.templates.service_unsuspension_name'] = 'Service Unsuspension';
$lang['AdminCompanyEmails.templates.service_unsuspension_desc'] = 'Service unsuspended notice, sent when a service is automatically unsuspended.';
$lang['AdminCompanyEmails.templates.account_management_invite_name'] = 'Account Management Invitation';
$lang['AdminCompanyEmails.templates.account_management_invite_desc'] = 'Notice sent after a user has invited you to manage their account.';
$lang['AdminCompanyEmails.templates.account_welcome_name'] = 'Account Registration';
$lang['AdminCompanyEmails.templates.account_welcome_desc'] = 'Welcome notice sent for new account registrations.';
$lang['AdminCompanyEmails.templates.report_ar_name'] = 'Aging Invoices Report';
$lang['AdminCompanyEmails.templates.report_ar_desc'] = 'Thirty, Sixety, Ninety day Aging Invoice Reports, delivered once per month.';
$lang['AdminCompanyEmails.templates.report_tax_liability_name'] = 'Tax Liability Report';
$lang['AdminCompanyEmails.templates.report_tax_liability_desc'] = 'A monthly Tax Liability Report, generated for the previous month.';
$lang['AdminCompanyEmails.templates.report_invoice_creation_name'] = 'Invoice Creation Report';
$lang['AdminCompanyEmails.templates.report_invoice_creation_desc'] = 'A daily report of invoices generated for the previous day.';
$lang['AdminCompanyEmails.templates.service_suspension_error_name'] = 'Suspension Error';
$lang['AdminCompanyEmails.templates.service_suspension_error_desc'] = 'Notice sent after a failed attempt to suspend a service.';
$lang['AdminCompanyEmails.templates.service_unsuspension_error_name'] = 'Unsuspension Error';
$lang['AdminCompanyEmails.templates.service_unsuspension_error_desc'] = 'Notice sent after a failed attempt to unsuspend a service.';
$lang['AdminCompanyEmails.templates.service_cancel_error_name'] = 'Cancellation Error';
$lang['AdminCompanyEmails.templates.service_cancel_error_desc'] = 'Notice sent after a failed attempt to cancel a service.';
$lang['AdminCompanyEmails.templates.service_creation_error_name'] = 'Creation Error';
$lang['AdminCompanyEmails.templates.service_creation_error_desc'] = 'Notice sent after a failed attempt to provision a service.';
$lang['AdminCompanyEmails.templates.service_renewal_error_name'] = 'Renewal Error';
$lang['AdminCompanyEmails.templates.service_renewal_error_desc'] = 'Notice sent after a failed attempt to renew a service.';
$lang['AdminCompanyEmails.templates.auto_debit_pending_name'] = 'Auto-Debit Pending';
$lang['AdminCompanyEmails.templates.auto_debit_pending_desc'] = 'Notice sent that indicates an automatic payment will be attempted soon.';
$lang['AdminCompanyEmails.templates.staff_reset_password_name'] = 'Password Reset';
$lang['AdminCompanyEmails.templates.staff_reset_password_desc'] = 'Password reset email containing a link to change the account password.';
$lang['AdminCompanyEmails.templates.service_creation_name'] = 'Service Creation';
$lang['AdminCompanyEmails.templates.service_creation_desc'] = 'Service creation notice, sent when a service has been created.';
$lang['AdminCompanyEmails.templates.service_uncancellation_name'] = 'Service Uncancellation';
$lang['AdminCompanyEmails.templates.service_uncancellation_desc'] = 'Service uncancellation notice, sent when a service has been uncancelled.';
$lang['AdminCompanyEmails.templates.verify_email_name'] = 'Email Verification';
$lang['AdminCompanyEmails.templates.verify_email_desc'] = 'Email verification link, sent when new login is created or a client changes their email address.';
$lang['AdminCompanyEmails.templates.quotation_delivery_name'] = 'Quote Delivery';
$lang['AdminCompanyEmails.templates.quotation_delivery_desc'] = 'Notice containing a PDF copy of a quote.';
$lang['AdminCompanyEmails.templates.quotation_approved_name'] = 'Quote Approval';
$lang['AdminCompanyEmails.templates.quotation_approved_desc'] = 'Notice sent manually by staff of a quote that has been approved. Contains a PDF copy of a quote';
$lang['AdminCompanyEmails.templates.staff_quotation_approved_name'] = 'Staff Quote Approval';
$lang['AdminCompanyEmails.templates.staff_quotation_approved_desc'] = 'Notice sent to staff after a quote has been approved by the client.';


// Edit email template
$lang['AdminCompanyEmails.edittemplate.page_title'] = 'Settings > Company > Emails > Edit Email Template';
$lang['AdminCompanyEmails.edittemplate.heading_email_template'] = 'Email Template';
$lang['AdminCompanyEmails.edittemplate.heading_additional_attachments'] = 'Additional Attachments';
$lang['AdminCompanyEmails.edittemplate.heading_file_name'] = 'File Name';
$lang['AdminCompanyEmails.edittemplate.heading_options'] = 'Actions';
$lang['AdminCompanyEmails.edittemplate.boxtitle_edittemplate'] = 'Edit Email Template %1$s'; // %1$s is the email template group name

$lang['AdminCompanyEmails.edittemplate.text_none'] = 'None';
$lang['AdminCompanyEmails.edittemplate.text_from_name'] = 'Enter from name';
$lang['AdminCompanyEmails.edittemplate.text_from_email'] = 'Enter from email address';
$lang['AdminCompanyEmails.edittemplate.text_subject'] = 'Enter email subject';
$lang['AdminCompanyEmails.edittemplate.text_plain_text'] = 'Enter plain text version of email';
$lang['AdminCompanyEmails.edittemplate.text_available_tags'] = 'Available Tags';
$lang['AdminCompanyEmails.edittemplate.text_tags_description'] = 'Use these tags in your email template to include dynamic content.';
$lang['AdminCompanyEmails.edittemplate.text_drop_files'] = 'Drop files here or click browse to upload attachments';
$lang['AdminCompanyEmails.edittemplate.text_browse_files'] = 'Browse Files';
$lang['AdminCompanyEmails.edittemplate.confirm_delete_attachment'] = 'Are you sure you want to delete this attachment?';
$lang['AdminCompanyEmails.edittemplate.option_delete'] = 'Delete';

$lang['AdminCompanyEmails.edittemplate.field.status'] = 'Enabled';
$lang['AdminCompanyEmails.edittemplate.field.from_name'] = 'From Name';
$lang['AdminCompanyEmails.edittemplate.field.from'] = 'From Email';
$lang['AdminCompanyEmails.edittemplate.field.subject'] = 'Subject';
$lang['AdminCompanyEmails.edittemplate.field.email_template_group_id'] = 'HTML Template';
$lang['AdminCompanyEmails.edittemplate.field.tags'] = 'Available Tags';
$lang['AdminCompanyEmails.edittemplate.field.text'] = 'Text';
$lang['AdminCompanyEmails.edittemplate.field.html'] = 'HTML';
$lang['AdminCompanyEmails.edittemplate.field.email_signature_id'] = 'Signature';
$lang['AdminCompanyEmails.edittemplate.field.include_attachments'] = 'Include Any Attachments';
$lang['AdminCompanyEmails.edittemplate.field_attachment'] = 'Attachment';
$lang['AdminCompanyEmails.edittemplate.field.edittemplatesubmit'] = 'Update Template';
$lang['AdminCompanyEmails.edittemplate.field_cancel'] = 'Cancel';
$lang['AdminCompanyEmails.edittemplate.field_continue'] = 'Continue';
$lang['AdminCompanyEmails.edittemplate.field_restore'] = 'Restore';
$lang['AdminCompanyEmails.edittemplate.field_restore_snapshot'] = 'Restore Snapshot';
$lang['AdminCompanyEmails.edittemplate.heading_snapshots'] = 'Template History';
$lang['AdminCompanyEmails.edittemplate.text_no_snapshots'] = 'There are no snapshots available for this email template.';
$lang['AdminCompanyEmails.edittemplate.confirm_restore_snapshot'] = 'Are you sure you want to restore this snapshot? Any unsaved changes will be lost.';


// Email HTML templates
$lang['AdminCompanyEmails.htmltemplates.boxtitle_templates'] = 'HTML Templates';
$lang['AdminCompanyEmails.htmltemplates.categorylink_addhtmltemplate'] = 'Add HTML Template';
$lang['AdminCompanyEmails.htmltemplates.text_name'] = 'Template Name';
$lang['AdminCompanyEmails.htmltemplates.text_options'] = 'Options';
$lang['AdminCompanyEmails.htmltemplates.option_edit'] = 'Edit';
$lang['AdminCompanyEmails.htmltemplates.option_delete'] = 'Delete';

$lang['AdminCompanyEmails.htmltemplates.confirm_delete'] = 'Are you sure you want to delete this HTML template?';
$lang['AdminCompanyEmails.htmltemplates.no_results'] = 'There are no HTML templates.';


// Add Email HTML template
$lang['AdminCompanyEmails.addhtmltemplate.boxtitle_addhtmltemplate'] = 'Add HTML Template';
$lang['AdminCompanyEmails.addhtmltemplate.field.name'] = 'Name';
$lang['AdminCompanyEmails.addhtmltemplate.field.tags'] = 'Tags';
$lang['AdminCompanyEmails.addhtmltemplate.field.addtemplatesubmit'] = 'Create Template';


// Add Email HTML template
$lang['AdminCompanyEmails.edithtmltemplate.boxtitle_addhtmltemplate'] = 'Edit HTML Template';
$lang['AdminCompanyEmails.edithtmltemplate.field.name'] = 'Name';
$lang['AdminCompanyEmails.edithtmltemplate.field.tags'] = 'Tags';
$lang['AdminCompanyEmails.edithtmltemplate.field.addtemplatesubmit'] = 'Update Template';


// Email signatures
$lang['AdminCompanyEmails.signatures.page_title'] = 'Settings > Company > Emails > Signatures';
$lang['AdminCompanyEmails.signatures.boxtitle_signatures'] = 'Signatures';

$lang['AdminCompanyEmails.signatures.categorylink_newsignature'] = 'New Signature';
$lang['AdminCompanyEmails.signatures.no_results'] = 'There are no email signatures.';

$lang['AdminCompanyEmails.signatures.text_name'] = 'Name';
$lang['AdminCompanyEmails.signatures.text_description'] = 'Description';
$lang['AdminCompanyEmails.signatures.text_options'] = 'Options';

$lang['AdminCompanyEmails.signatures.option_edit'] = 'Edit';
$lang['AdminCompanyEmails.signatures.option_delete'] = 'Delete';

$lang['AdminCompanyEmails.signatures.confirm_delete'] = 'Are you sure you want to delete this email signature?';


// Add email signature
$lang['AdminCompanyEmails.addsignature.page_title'] = 'Settings > Company > Emails > Add Signature';
$lang['AdminCompanyEmails.addsignature.boxtitle_addsignature'] = 'Add Signature';

$lang['AdminCompanyEmails.addsignature.field_name'] = 'Name';
$lang['AdminCompanyEmails.addsignature.field_text'] = 'Text';
$lang['AdminCompanyEmails.addsignature.field_html'] = 'HTML';
$lang['AdminCompanyEmails.addsignature.field_addsignaturesubmit'] = 'Create Signature';

$lang['AdminCompanyEmails.addsignature.text_signatures'] = 'Signatures are used for email templates, making it easier to modify email signatures in bulk';


// Edit email signature
$lang['AdminCompanyEmails.editsignature.page_title'] = 'Settings > Company > Emails > Edit Signature';
$lang['AdminCompanyEmails.editsignature.boxtitle_editsignature'] = 'Edit Signature';

$lang['AdminCompanyEmails.editsignature.field_name'] = 'Name';
$lang['AdminCompanyEmails.editsignature.field_text'] = 'Text';
$lang['AdminCompanyEmails.editsignature.field_html'] = 'HTML';
$lang['AdminCompanyEmails.editsignature.field_editsignaturesubmit'] = 'Update Signature';


// Mail
$lang['AdminCompanyEmails.mail.page_title'] = 'Settings > Company > Emails > Mail Settings';
$lang['AdminCompanyEmails.mail.boxtitle_mail'] = 'Mail Settings';

$lang['AdminCompanyEmails.mail.text_section'] = 'This section controls how email is delivered from Blesta. Sendmail is the simplest delivery method, but SMTP is generally faster and more reliable.';
$lang['AdminCompanyEmails.mail.text_mail_from_test'] = 'This email address is dynamically pulled from one of your email templates and is not saved here. If the address is using the wrong domain, it is an indicator that you need to update the from address for your email templates.';

$lang['AdminCompanyEmails.mail.field.sendmail_path'] = 'Sendmail Path';
$lang['AdminCompanyEmails.mail.field.sendmail_from'] = 'Sendmail Test From Address';
$lang['AdminCompanyEmails.mail.field.html_email'] = 'Enable HTML';
$lang['AdminCompanyEmails.mail.field.mail_delivery'] = 'Delivery Method';
$lang['AdminCompanyEmails.mail.field.test'] = 'Test These Settings';
$lang['AdminCompanyEmails.mail.field.smtp_host'] = 'SMTP Host';
$lang['AdminCompanyEmails.mail.field.smtp_port'] = 'SMTP Port';
$lang['AdminCompanyEmails.mail.field.smtp_user'] = 'SMTP User';
$lang['AdminCompanyEmails.mail.field.smtp_password'] = 'SMTP Password';
$lang['AdminCompanyEmails.mail.field.smtp_from'] = 'Test From Address';
$lang['AdminCompanyEmails.mail.field.smtp_to'] = 'Test To Address';

$lang['AdminCompanyEmails.mail.field.oauth2_host'] = 'SMTP Host';
$lang['AdminCompanyEmails.mail.field.oauth2_port'] = 'SMTP Port';
$lang['AdminCompanyEmails.mail.field.oauth2_provider'] = 'OAuth 2.0 Provider';
$lang['AdminCompanyEmails.mail.field.oauth2_user'] = 'OAuth 2.0 User';
$lang['AdminCompanyEmails.mail.field.oauth2_client_id'] = 'OAuth 2.0 Client / Application ID';
$lang['AdminCompanyEmails.mail.field.oauth2_client_secret'] = 'OAuth 2.0 Client / Application Secret';
$lang['AdminCompanyEmails.mail.field.oauth2_redirect_uri'] = 'OAuth 2.0 Redirect URI';

$lang['AdminCompanyEmails.mail.field.oauth2_from'] = 'Test From Address';
$lang['AdminCompanyEmails.mail.field.oauth2_to'] = 'Test To Address';

$lang['AdminCompanyEmails.mail.field.submitmail'] = 'Update Settings';

$lang['AdminCompanyEmails.mail.text_optional'] = 'Optional';
$lang['AdminCompanyEmails.mail.text_redirect'] = 'After saving OAuth 2.0 credentials you will be redirected to the provider to complete the process.';
$lang['AdminCompanyEmails.mail.text_copy'] = 'Copy';
$lang['AdminCompanyEmails.mail.text_copied'] = 'Copied!';


// Text
$lang['AdminCompanyEmails.getRequiredMethods.sendmail'] = 'Sendmail';
$lang['AdminCompanyEmails.getRequiredMethods.smtp'] = 'SMTP';
$lang['AdminCompanyEmails.getRequiredMethods.oauth2'] = 'OAuth 2.0';
$lang['AdminCompanyEmails.getsmtpsecurityoptions.none'] = 'None';
$lang['AdminCompanyEmails.getsmtpsecurityoptions.ssl'] = 'SSL';
$lang['AdminCompanyEmails.getsmtpsecurityoptions.tls'] = 'TLS';
$lang['AdminCompanyEmails.getoauth2providers.google'] = 'Google';
$lang['AdminCompanyEmails.getoauth2providers.microsoft'] = 'Microsoft';
$lang['AdminCompanyEmails.gettemplateactions.update_from_email'] = 'Update "From Email"';
$lang['AdminCompanyEmails.gettemplateactions.update_from_name'] = 'Update "From Name"';
$lang['AdminCompanyEmails.gettemplateactions.update_html_template'] = 'Update HTML Template';
$lang['AdminCompanyEmails.gettemplateactions.text_none'] = 'None';


// AI Content Generation
$lang['AdminCompanyEmails.ai.modal_title'] = 'AI Content Assistant';
$lang['AdminCompanyEmails.ai.modal_title_generate'] = 'Generate Email Content';
$lang['AdminCompanyEmails.ai.modal_title_rewrite'] = 'Rewrite Email Content';
$lang['AdminCompanyEmails.ai.generate_button'] = 'Generate';
$lang['AdminCompanyEmails.ai.rewrite_button'] = 'Rewrite';
$lang['AdminCompanyEmails.ai.regenerate_button'] = 'Regenerate';
$lang['AdminCompanyEmails.ai.use_content_button'] = 'Use This Content';
$lang['AdminCompanyEmails.ai.btn_cancel'] = 'Cancel';
$lang['AdminCompanyEmails.ai.generating'] = 'Generating...';
$lang['AdminCompanyEmails.ai.prompt_context_label'] = 'Prompt Context';
$lang['AdminCompanyEmails.ai.prompt_loading'] = 'Loading prompt...';
$lang['AdminCompanyEmails.ai.additional_instructions_label'] = 'Additional Instructions';
$lang['AdminCompanyEmails.ai.additional_instructions_placeholder'] = 'Add specific requirements or tone preferences...';
$lang['AdminCompanyEmails.ai.additional_instructions_help'] = 'Optional guidance for the AI to customize the generated content.';
$lang['AdminCompanyEmails.ai.generated_content_label'] = 'Generated Content';
$lang['AdminCompanyEmails.ai.initial_instructions'] = 'Click Generate to create email content based on the template type and available tags.';
$lang['AdminCompanyEmails.ai.preview_html'] = 'HTML Preview';
$lang['AdminCompanyEmails.ai.preview_text'] = 'Text Preview';
$lang['AdminCompanyEmails.ai.error_disabled'] = 'AI features are currently disabled.';
$lang['AdminCompanyEmails.ai.error_feature_disabled'] = 'AI email template generation is not enabled.';
$lang['AdminCompanyEmails.ai.error_prompt_required'] = 'A prompt is required to generate content.';
$lang['AdminCompanyEmails.ai.error_prompt_too_long'] = 'Prompt exceeds maximum length.';
$lang['AdminCompanyEmails.ai.error_generation_failed'] = 'Content generation failed. Please try again.';
$lang['AdminCompanyEmails.ai.error_rate_limit'] = 'Too many requests. Please wait a moment before trying again.';
$lang['AdminCompanyEmails.ai.error_prefix'] = 'Error:';
$lang['AdminCompanyEmails.ai.apply_content_label'] = 'Apply content to:';
$lang['AdminCompanyEmails.ai.apply_subject'] = 'Subject line';
$lang['AdminCompanyEmails.ai.apply_html'] = 'HTML version';
$lang['AdminCompanyEmails.ai.apply_text'] = 'Text version';
$lang['AdminCompanyEmails.ai.preview_subject'] = 'Suggested Subject';
