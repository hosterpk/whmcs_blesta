<?php
/**
 * Language definitions for the Admin Company Client Options controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Success messages
$lang['AdminCompanyClientoptions.!success.field_updated'] = 'The client custom field has been successfully updated.';
$lang['AdminCompanyClientoptions.!success.field_created'] = 'The client custom field has been successfully created.';
$lang['AdminCompanyClientoptions.!success.field_deleted'] = 'The client custom field has been successfully deleted.';
$lang['AdminCompanyClientoptions.!success.requiredfields_updated'] = 'The required fields have been successfully updated.';
$lang['AdminCompanyClientoptions.!success.general_updated'] = 'The general settings have been successfully updated.';

// Notices
$lang['AdminCompanyClientoptions.!notice.group_settings'] = 'NOTE: These settings only apply to Client Groups that inherit their settings from the Company.';

// Errors
$lang['AdminCompanyClientoptions.!error.clients_format'] = 'The Client ID Format must contain {num}.';

// Tooltips
$lang['AdminCompanyClientoptions.!tooltip.read_only'] = 'If checked, the field cannot be modified by the client if it contains any data.';
$lang['AdminCompanyClientoptions.!tooltip.state'] = 'Use caution when requiring a State/Province be selected. Some countries do not have any states. Clients in those countries would be unable to save their contact details. We recommend not requiring this field.';
$lang['AdminCompanyClientoptions.!tooltip.unique_contact_emails'] = 'Restricts email addresses for contacts. Primary Contacts means no two primary contacts (i.e. clients) can have the same email address. All Contacts means no contacts of any type can have the same email address as another contact.';
$lang['AdminCompanyClientoptions.!tooltip.force_email_usernames'] = 'Clients must use their email address as their username rather than defining their own. Existing clients can still login with their current username.';
$lang['AdminCompanyClientoptions.!tooltip.email_verification'] = 'Check to send an email verification email when a new login is created or a client changes their email address. A notice will appear on the clients profile until they are verified.';
$lang['AdminCompanyClientoptions.!tooltip.clients_format'] = 'Client ID Format is the format of the Client ID. A value of ABC-{num} will result in a Client ID of ABC-1500 where 1500 is the Client\'s ID value.';
$lang['AdminCompanyClientoptions.!tooltip.clients_start'] = 'Client ID Start is the starting value for Client ID\'s. New clients will have this value, unless it is less than the value of the most recently created Client.';
$lang['AdminCompanyClientoptions.!tooltip.clients_increment'] = 'Subsequent Client IDs numbers will increment by this value.';

$lang['AdminCompanyClientoptions.!tooltip.client_group_id'] = 'The custom field will only apply to member\'s of the selected client group.';
$lang['AdminCompanyClientoptions.!tooltip.name'] = 'This is the display name for this field. It may be a language definition.';
$lang['AdminCompanyClientoptions.!tooltip.is_lang'] = 'Only check this box if you have added a language definition for this custom field in the custom language file.';
$lang['AdminCompanyClientoptions.!tooltip.link'] = 'A custom link that can be inserted into the name of the field. Enclose the text you want to apply the link inside square brackets (i.e. [terms]).';
$lang['AdminCompanyClientoptions.!tooltip.type'] = 'The custom field will appear as the selected form type.';
$lang['AdminCompanyClientoptions.!tooltip.show_client'] = 'Check to allow clients to see and update this field.';
$lang['AdminCompanyClientoptions.!tooltip.read_only_field'] = 'Checking this box will make this custom field unchangeable by the client. Read-only fields will be set automatically to their assigned default value.';
$lang['AdminCompanyClientoptions.!tooltip.required'] = 'Select "Yes" to ensure that a value is given for this field, for Drop Down types the option must appear in the list of options. Select "No" to accept any value for this field. Select "Custom Regex" to use a custom regular expression to valid this field.';
$lang['AdminCompanyClientoptions.!tooltip.regex'] = 'This option will appear if "Required" is set to "Custom Regex". Enter the custom regular expression to validate for this field here.';
$lang['AdminCompanyClientoptions.!tooltip.encrypted'] = 'Check this box to store the value encrypted. This is highly recommended if storing any sensitive or personally identifying information.';

$lang['AdminCompanyClientoptions.!tooltip.checkbox_value'] = 'The value submitted when the checkbox is checked.';
$lang['AdminCompanyClientoptions.!tooltip.default_text'] = 'The text entered here will be the default value set for this option when this custom field is added for a client.';
$lang['AdminCompanyClientoptions.!tooltip.default_checkbox'] = 'If checked, this checkbox will be checked by default when this custom field is added for a client.';

$lang['AdminCompanyClientoptions.!tooltip.select_default'] = 'The checked option value will be the default value selected when this option is added for a client.';
$lang['AdminCompanyClientoptions.!tooltip.password_format'] = 'Select the type of characters allowed in passwords.';
$lang['AdminCompanyClientoptions.!tooltip.password_rule'] = 'Enter a custom regular expression to validate passwords when "Custom" format is selected. The regular expression may define a minimum password length.';
$lang['AdminCompanyClientoptions.!tooltip.password_length'] = 'Set the minimum password length.';

$lang['AdminCompanyClientoptions.!tooltip.enable_gateway_restrictions'] = 'Limit which gateways clients can use. By default Gateway Restrictions are disabled and all gateways are available.';


// General settings
$lang['AdminCompanyClientoptions.general.page_title'] = 'Settings > Company > Client Options > General';
$lang['AdminCompanyClientoptions.general.boxtitle'] = 'General Client Settings';

$lang['AdminCompanyClientoptions.general.field_unique_contact_emails'] = 'Enforce Unique Contact Email Addresses';
$lang['AdminCompanyClientoptions.general.field_unique_contact_emails_none'] = '-- None --';
$lang['AdminCompanyClientoptions.general.field_unique_contact_emails_primary'] = 'Primary Contacts';
$lang['AdminCompanyClientoptions.general.field_unique_contact_emails_all'] = 'All Contacts';
$lang['AdminCompanyClientoptions.general.field_force_email_usernames'] = 'Force Email Usernames';
$lang['AdminCompanyClientoptions.general.field_email_verification'] = 'Enable Email Verification';
$lang['AdminCompanyClientoptions.general.field_clients_format'] = 'Client ID Format';
$lang['AdminCompanyClientoptions.general.field_clients_start'] = 'Client ID Start Value';
$lang['AdminCompanyClientoptions.general.field_clients_increment'] = 'Client ID Increment Value';
$lang['AdminCompanyClientoptions.general.field_password_format'] = 'Password Format';
$lang['AdminCompanyClientoptions.general.field_password_format_any'] = 'Any Characters';
$lang['AdminCompanyClientoptions.general.field_password_format_any_no_space'] = 'No Spaces';
$lang['AdminCompanyClientoptions.general.field_password_format_alpha_num'] = 'Alphanumeric Only';
$lang['AdminCompanyClientoptions.general.field_password_format_alpha'] = 'Alpha Only';
$lang['AdminCompanyClientoptions.general.field_password_format_num'] = 'Numbers Only';
$lang['AdminCompanyClientoptions.general.field_password_format_custom'] = 'Custom';
$lang['AdminCompanyClientoptions.general.field_password_rule'] = 'Password Custom Rule';
$lang['AdminCompanyClientoptions.general.field_password_length'] = 'Password Length';
$lang['AdminCompanyClientoptions.general.prevent_unverified_payments'] = 'Prevent Payments from Unverified Clients';
$lang['AdminCompanyClientoptions.general.text_submit'] = 'Update Settings';

// Custom Client Fields
$lang['AdminCompanyClientoptions.customfields.page_title'] = 'Settings > Company > Client Options > Client Custom Fields > Browse';
$lang['AdminCompanyClientoptions.customfields.boxtitle_browse'] = 'Browse Client Custom Fields';
$lang['AdminCompanyClientoptions.customfields.categorylink_addfield'] = 'Create Field';

$lang['AdminCompanyClientoptions.customfields.text_name'] = 'Name';
$lang['AdminCompanyClientoptions.customfields.text_type'] = 'Type';
$lang['AdminCompanyClientoptions.customfields.text_required'] = 'Required';
$lang['AdminCompanyClientoptions.customfields.text_visible'] = 'Visible to Clients';
$lang['AdminCompanyClientoptions.customfields.text_read_only'] = 'Read Only for Clients';
$lang['AdminCompanyClientoptions.customfields.text_options'] = 'Options';

$lang['AdminCompanyClientoptions.customfields.option_edit'] = 'Edit';
$lang['AdminCompanyClientoptions.customfields.option_delete'] = 'Delete';
$lang['AdminCompanyClientoptions.customfields.confirm_delete'] = 'Deleting this custom field will delete any and all data stored for it for each client within this group. Are you sure you want to delete this custom field?';

$lang['AdminCompanyClientoptions.customfields.no_results'] = 'There are no custom fields.';


// Add Custom Field
$lang['AdminCompanyClientoptions.addcustomfield.page_title'] = 'Settings > Company > Client Options > Client Custom Fields > Add Custom Field';
$lang['AdminCompanyClientoptions.addcustomfield.boxtitle_add'] = 'Add Custom Field';

$lang['AdminCompanyClientoptions.addcustomfield.field.client_group_id'] = 'Client Group';
$lang['AdminCompanyClientoptions.addcustomfield.field.name'] = 'Name';
$lang['AdminCompanyClientoptions.addcustomfield.field.is_lang'] = 'Name is a language definition';
$lang['AdminCompanyClientoptions.addcustomfield.field.link'] = 'Link';
$lang['AdminCompanyClientoptions.addcustomfield.field.type'] = 'Type';
$lang['AdminCompanyClientoptions.addcustomfield.field.show_client'] = 'Visible to Clients';
$lang['AdminCompanyClientoptions.addcustomfield.field.read_only'] = 'Read Only for Clients';
$lang['AdminCompanyClientoptions.addcustomfield.field.required'] = 'Required';
$lang['AdminCompanyClientoptions.addcustomfield.field.regex'] = 'Custom Regex';
$lang['AdminCompanyClientoptions.addcustomfield.field.encrypted'] = 'Encrypt Values';
$lang['AdminCompanyClientoptions.addcustomfield.field.addsubmit'] = 'Create Custom Field';

$lang['AdminCompanyClientoptions.addcustomfield.field.checkbox_value'] = 'Value';
$lang['AdminCompanyClientoptions.addcustomfield.field.default_checkbox'] = 'Default Value Checked';
$lang['AdminCompanyClientoptions.addcustomfield.field.default_text'] = 'Default Text Value';

$lang['AdminCompanyClientoptions.addcustomfield.configuration_warning'] = 'Requiring this field while not making it visible to clients will result in clients being unable to register or update their account information.';
$lang['AdminCompanyClientoptions.addcustomfield.categorylink_select'] = 'Add Additional Option';
$lang['AdminCompanyClientoptions.addcustomfield.heading_select_value'] = 'Value';
$lang['AdminCompanyClientoptions.addcustomfield.heading_select_option'] = 'Option Name';
$lang['AdminCompanyClientoptions.addcustomfield.heading_select_default'] = 'Default';
$lang['AdminCompanyClientoptions.addcustomfield.text_remove'] = 'Remove';


// Edit Custom Field
$lang['AdminCompanyClientoptions.editcustomfield.page_title'] = 'Settings > Company > Client Options > Client Custom Fields > Edit Custom Field';
$lang['AdminCompanyClientoptions.editcustomfield.boxtitle_edit'] = 'Edit Custom Field';

$lang['AdminCompanyClientoptions.editcustomfield.field.client_group_id'] = 'Client Group';
$lang['AdminCompanyClientoptions.editcustomfield.field.name'] = 'Name';
$lang['AdminCompanyClientoptions.editcustomfield.field.is_lang'] = 'Name is a language definition';
$lang['AdminCompanyClientoptions.editcustomfield.field.link'] = 'Link';
$lang['AdminCompanyClientoptions.editcustomfield.field.type'] = 'Type';
$lang['AdminCompanyClientoptions.editcustomfield.field.show_client'] = 'Visible to Clients';
$lang['AdminCompanyClientoptions.editcustomfield.field.read_only'] = 'Read Only for Clients';
$lang['AdminCompanyClientoptions.editcustomfield.field.required'] = 'Required';
$lang['AdminCompanyClientoptions.editcustomfield.field.regex'] = 'Custom Regex';
$lang['AdminCompanyClientoptions.editcustomfield.field.encrypted'] = 'Encrypt Values';
$lang['AdminCompanyClientoptions.editcustomfield.field.editsubmit'] = 'Update Custom Field';

$lang['AdminCompanyClientoptions.editcustomfield.field.checkbox_value'] = 'Value';
$lang['AdminCompanyClientoptions.editcustomfield.field.default_checkbox'] = 'Default Value Checked';
$lang['AdminCompanyClientoptions.editcustomfield.field.default_text'] = 'Default Text Value';

$lang['AdminCompanyClientoptions.editcustomfield.categorylink_select'] = 'Add Additional Option';
$lang['AdminCompanyClientoptions.editcustomfield.heading_select_value'] = 'Value';
$lang['AdminCompanyClientoptions.editcustomfield.heading_select_option'] = 'Option Name';
$lang['AdminCompanyClientoptions.editcustomfield.heading_select_default'] = 'Default';
$lang['AdminCompanyClientoptions.editcustomfield.text_remove'] = 'Remove';


// Text
$lang['AdminCompanyClientoptions.getRequired.no'] = 'No';
$lang['AdminCompanyClientoptions.getRequired.yes'] = 'Yes';
$lang['AdminCompanyClientoptions.getRequired.regex'] = 'Custom Regex';


// Require Fields
$lang['AdminCompanyClientoptions.requiredfields.page_title'] = 'Settings > Company > Client Options > Client Custom Fields > Required Client Fields';
$lang['AdminCompanyClientoptions.requiredfields.boxtitle'] = 'Required Client Fields';
$lang['AdminCompanyClientoptions.requiredfields.description'] = 'Check the fields that should be required when creating or updating a client or contact.';

$lang['AdminCompanyClientoptions.requiredfields.heading_field'] = 'Field';
$lang['AdminCompanyClientoptions.requiredfields.heading_required'] = 'Required';
$lang['AdminCompanyClientoptions.requiredfields.heading_show'] = 'Show';
$lang['AdminCompanyClientoptions.requiredfields.heading_read_only'] = 'Read Only';

$lang['AdminCompanyClientoptions.requiredfields.field_first_name'] = 'First Name';
$lang['AdminCompanyClientoptions.requiredfields.field_last_name'] = 'Last Name';
$lang['AdminCompanyClientoptions.requiredfields.field_company'] = 'Company/Org.';
$lang['AdminCompanyClientoptions.requiredfields.field_title'] = 'Title';
$lang['AdminCompanyClientoptions.requiredfields.field_address1'] = 'Address 1';
$lang['AdminCompanyClientoptions.requiredfields.field_address2'] = 'Address 2';
$lang['AdminCompanyClientoptions.requiredfields.field_city'] = 'City';
$lang['AdminCompanyClientoptions.requiredfields.field_country'] = 'Country';
$lang['AdminCompanyClientoptions.requiredfields.field_state'] = 'State/Province';
$lang['AdminCompanyClientoptions.requiredfields.field_zip'] = 'Zip/Postal Code';
$lang['AdminCompanyClientoptions.requiredfields.field_email'] = 'Email';
$lang['AdminCompanyClientoptions.requiredfields.field_phone'] = 'Phone';
$lang['AdminCompanyClientoptions.requiredfields.field_fax'] = 'Fax';

$lang['AdminCompanyClientoptions.requiredfields.text_submit'] = 'Update Settings';


// Gateway Restrictions
$lang['AdminCompanyClientoptions.gatewayrestrictions.field_enable_gateway_restrictions'] = 'Enable Gateway Restrictions';

$lang['AdminCompanyClientoptions.gatewayrestrictions.heading_enable'] = 'Enable';
$lang['AdminCompanyClientoptions.gatewayrestrictions.heading_gateway'] = 'Gateway';
$lang['AdminCompanyClientoptions.gatewayrestrictions.heading_type'] = 'Type';

$lang['AdminCompanyClientoptions.gatewayrestrictions.text_gateway_type_nonmerchant'] = 'Non-Merchant';
$lang['AdminCompanyClientoptions.gatewayrestrictions.text_gateway_type_merchant'] = 'Merchant';
$lang['AdminCompanyClientoptions.gatewayrestrictions.text_gateway_type_hybrid'] = 'Hybrid';


$lang['AdminCompanyClientoptions.gatewayrestrictions.no_gateways_text'] = 'There are no installed gateways.';
