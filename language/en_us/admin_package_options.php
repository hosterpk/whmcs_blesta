<?php
/**
 * Language definitions for the Admin Package Options controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Error
$lang['AdminPackageOptions.!error.import_file.missing'] = 'Please upload a Package Option to import.';
$lang['AdminPackageOptions.!error.value_image.upload'] = 'The option value image could not be uploaded.';
$lang['AdminPackageOptions.!error.value_image.type'] = 'The option value image must be a JPEG, PNG, GIF, or WebP file.';

// Notice
$lang['AdminPackageOptions.!notice.package_option_actions'] = 'Are you sure you want to perform this bulk action?';

// Success
$lang['AdminPackageOptions.!success.group_added'] = 'The package option group has been successfully created.';
$lang['AdminPackageOptions.!success.group_updated'] = 'The package option group has been successfully updated.';
$lang['AdminPackageOptions.!success.group_deleted'] = 'The package option group has been successfully deleted.';

$lang['AdminPackageOptions.!success.option_added'] = 'The package option has been successfully created.';
$lang['AdminPackageOptions.!success.option_updated'] = 'The package option has been successfully updated.';
$lang['AdminPackageOptions.!success.option_deleted'] = 'The package option has been successfully deleted.';
$lang['AdminPackageOptions.!error.option_delete_in_use'] = 'The package option cannot be deleted because one or more of its values are in use by a service.';
$lang['AdminPackageOptions.!success.option_removed'] = 'The package option has been successfully removed from the package option group.';

$lang['AdminPackageOptions.!success.logic_updated'] = 'The package option logic has been successfully updated.';


// Index
$lang['AdminPackageOptions.index.page_title'] = 'Package Options';
$lang['AdminPackageOptions.index.boxtitle_options'] = 'Configurable Options';

$lang['AdminPackageOptions.index.category_groups'] = 'Groups';
$lang['AdminPackageOptions.index.category_options'] = 'Options';

$lang['AdminPackageOptions.index.categorylink_createoption'] = 'Create Option';
$lang['AdminPackageOptions.index.categorylink_creategroup'] = 'Create Group';

$lang['AdminPackageOptions.index.heading_name'] = 'Name';
$lang['AdminPackageOptions.index.heading_label'] = 'Label';
$lang['AdminPackageOptions.index.heading_options'] = 'Actions';
$lang['AdminPackageOptions.index.option_edit'] = 'Edit';
$lang['AdminPackageOptions.index.option_logic'] = 'Option Logic';
$lang['AdminPackageOptions.index.option_logic_disabled'] = 'Option logic requires at least 2 options in this group';
$lang['AdminPackageOptions.index.option_export'] = 'Export';
$lang['AdminPackageOptions.index.option_delete'] = 'Delete';

$lang['AdminPackageOptions.index.field_actionsubmit'] = 'Apply';
$lang['AdminPackageOptions.index.field_cancel'] = 'Cancel';
$lang['AdminPackageOptions.index.field_continue'] = 'Continue';

$lang['AdminPackageOptions.index.action.delete_package_options'] = 'Delete Package Options';
$lang['AdminPackageOptions.index.action.delete_package_groups'] = 'Delete Option Groups';

$lang['AdminPackageOptions.index.confirm_delete_group'] = 'Are you sure you want to delete this package option group?';
$lang['AdminPackageOptions.index.confirm_delete_option'] = 'Are you sure you want to delete this package option?';

$lang['AdminPackageOptions.index.no_results_groups'] = 'There are no configurable option groups.';
$lang['AdminPackageOptions.index.no_results_options'] = 'There are no configurable options.';


// Package option group info
$lang['AdminPackageOptions.groupinfo.heading_options'] = 'Actions';
$lang['AdminPackageOptions.groupinfo.heading_label'] = 'Option Label';
$lang['AdminPackageOptions.groupinfo.heading_options'] = 'Actions';
$lang['AdminPackageOptions.groupinfo.option_edit'] = 'Edit';
$lang['AdminPackageOptions.groupinfo.option_remove'] = 'Remove from Group';
$lang['AdminPackageOptions.groupinfo.confirm_remove_option'] = 'Are you sure you want to remove this package option from this package option group?';
$lang['AdminPackageOptions.groupinfo.no_results'] = 'There are no package options assigned to this group.';


// Option info
$lang['AdminPackageOptions.optioninfo.heading_options'] = 'Option Values';
$lang['AdminPackageOptions.optioninfo.heading_name'] = 'Name';
$lang['AdminPackageOptions.optioninfo.heading_value'] = 'Value';
$lang['AdminPackageOptions.optioninfo.heading_min'] = 'Min';
$lang['AdminPackageOptions.optioninfo.heading_max'] = 'Max';
$lang['AdminPackageOptions.optioninfo.heading_step'] = 'Step';
$lang['AdminPackageOptions.optioninfo.heading_status'] = 'Status';
$lang['AdminPackageOptions.optioninfo.no_results'] = 'There are no values assigned to this option.';


// Tooltips
$lang['AdminPackageOptions.!tooltip.price_renews'] = 'A price can be set here for renewals, or will otherwise default to the set Price. Any prorated changes to services will be based off of this value.';
$lang['AdminPackageOptions.!tooltip.label'] = 'This is the label that will appear above this form field.';
$lang['AdminPackageOptions.!tooltip.name'] = 'This is the form field name, and is not displayed anywhere. Modules may expect this name to be something specific, if used for provisioning.';
$lang['AdminPackageOptions.!tooltip.type'] = 'The type of form field that should be displayed.';
$lang['AdminPackageOptions.!tooltip.option_name'] = 'This is the display name for this option.';
$lang['AdminPackageOptions.!tooltip.option_value'] = 'This is the value for this option, and is not displayed anywhere. Modules may expect this value to be something specific, if used for provisioning.';
$lang['AdminPackageOptions.!tooltip.option_min'] = 'The minimum value allowed.';
$lang['AdminPackageOptions.!tooltip.option_max'] = 'The maximum value allowed.';
$lang['AdminPackageOptions.!tooltip.option_step'] = 'The increment value. That is, the difference between one valid value and another.';
$lang['AdminPackageOptions.!tooltip.option_status'] = 'Inactive option values can no longer be selected for services, but can be kept for existing services until changed to a different value.';
$lang['AdminPackageOptions.!tooltip.option_default'] = 'The checked option value will be the default value selected when this option is added for a service.';
$lang['AdminPackageOptions.!tooltip.option_pricing_term'] = 'Only pricing options that match the term and currency for the Package chosen will be displayed on any order forms.';

$lang['AdminPackageOptions.!tooltip.package_membership'] = 'Packages may either be assigned to the package option group here, or package option groups may be assigned to packages individually.';


// Add Option
$lang['AdminPackageOptions.add.page_title'] = 'New Package Option';
$lang['AdminPackageOptions.add.boxtitle_addoption'] = 'New Package Option';

$lang['AdminPackageOptions.add.heading_basic'] = 'Basic';
$lang['AdminPackageOptions.add.field_label'] = 'Label';
$lang['AdminPackageOptions.add.field_name'] = 'Name';
$lang['AdminPackageOptions.add.field_description'] = 'Description';
$lang['AdminPackageOptions.add.field_type'] = 'Type';
$lang['AdminPackageOptions.add.field_addable'] = 'Client can Add';
$lang['AdminPackageOptions.add.field_editable'] = 'Client can Edit';
$lang['AdminPackageOptions.add.field_disable_pricing'] = 'Disable pricing';
$lang['AdminPackageOptions.add.field_hide_on_invoice'] = 'Do not show on invoices';
$lang['AdminPackageOptions.add.tooltip_disable_pricing'] = 'When enabled, this will hide the option price throughout the interface and set all prices to $0.00.';
$lang['AdminPackageOptions.add.tooltip_hide_on_invoice'] = 'When enabled, this option will not appear as a line item on invoices. Only shown when "Disable pricing" is enabled.';

$lang['AdminPackageOptions.add.heading_options'] = 'Values & Pricing';
$lang['AdminPackageOptions.add.text_select_type_first'] = 'Please select an option type on the Basic tab to configure values and pricing.';
$lang['AdminPackageOptions.add.categorylink_addoption'] = 'Add Additional Option';
$lang['AdminPackageOptions.add.text_name'] = 'Name';
$lang['AdminPackageOptions.add.text_value'] = 'Value';
$lang['AdminPackageOptions.add.text_min'] = 'Min';
$lang['AdminPackageOptions.add.text_max'] = 'Max';
$lang['AdminPackageOptions.add.text_step'] = 'Step';
$lang['AdminPackageOptions.add.text_default'] = 'Default';
$lang['AdminPackageOptions.add.text_status'] = 'Status';
$lang['AdminPackageOptions.add.text_image'] = 'Image';
$lang['AdminPackageOptions.add.text_options'] = 'Actions';
$lang['AdminPackageOptions.add.text_delete'] = 'Delete';

$lang['AdminPackageOptions.add.heading_prices'] = 'Pricing';
$lang['AdminPackageOptions.add.price_term'] = 'Term';
$lang['AdminPackageOptions.add.price_period'] = 'Period';
$lang['AdminPackageOptions.add.price_currency'] = 'Currency';
$lang['AdminPackageOptions.add.price_price'] = 'Price';
$lang['AdminPackageOptions.add.price_price_renews'] = 'Renewal Price';
$lang['AdminPackageOptions.add.price_setup'] = 'Setup Fee';
$lang['AdminPackageOptions.add.price_options'] = 'Actions';
$lang['AdminPackageOptions.add.price_add'] = 'Add';
$lang['AdminPackageOptions.add.price_delete'] = 'Delete';

$lang['AdminPackageOptions.add.heading_groups'] = 'Group Membership';
$lang['AdminPackageOptions.add.text_membergroups'] = 'Member Groups';
$lang['AdminPackageOptions.add.text_availablegroups'] = 'Available Groups';

$lang['AdminPackageOptions.add.field_submit'] = 'Create Package Option';


// Add Option
$lang['AdminPackageOptions.edit.page_title'] = 'Update Package Option';
$lang['AdminPackageOptions.edit.boxtitle_editoption'] = 'Update Package Option';

$lang['AdminPackageOptions.edit.heading_basic'] = 'Basic';
$lang['AdminPackageOptions.edit.field_label'] = 'Label';
$lang['AdminPackageOptions.edit.field_name'] = 'Name';
$lang['AdminPackageOptions.edit.field_description'] = 'Description';
$lang['AdminPackageOptions.edit.field_type'] = 'Type';
$lang['AdminPackageOptions.edit.type_in_use'] = 'The type cannot be changed because one or more option values are in use by a service.';
$lang['AdminPackageOptions.edit.field_addable'] = 'Client can Add';
$lang['AdminPackageOptions.edit.field_editable'] = 'Client can Edit';
$lang['AdminPackageOptions.edit.field_disable_pricing'] = 'Disable pricing';
$lang['AdminPackageOptions.edit.field_hide_on_invoice'] = 'Do not show on invoices';
$lang['AdminPackageOptions.edit.tooltip_disable_pricing'] = 'When enabled, this will hide the option price throughout the interface and set all prices to $0.00.';
$lang['AdminPackageOptions.edit.tooltip_hide_on_invoice'] = 'When enabled, this option will not appear as a line item on invoices. Only shown when "Disable pricing" is enabled.';

$lang['AdminPackageOptions.edit.heading_options'] = 'Values & Pricing';
$lang['AdminPackageOptions.edit.text_select_type_first'] = 'Please select an option type on the Basic tab to configure values and pricing.';
$lang['AdminPackageOptions.edit.categorylink_editoption'] = 'Add Additional Option';
$lang['AdminPackageOptions.edit.text_name'] = 'Name';
$lang['AdminPackageOptions.edit.text_value'] = 'Value';
$lang['AdminPackageOptions.edit.text_default'] = 'Default';
$lang['AdminPackageOptions.edit.text_status'] = 'Status';
$lang['AdminPackageOptions.edit.text_min'] = 'Min';
$lang['AdminPackageOptions.edit.text_max'] = 'Max';
$lang['AdminPackageOptions.edit.text_step'] = 'Step';
$lang['AdminPackageOptions.edit.text_image'] = 'Image';
$lang['AdminPackageOptions.edit.text_options'] = 'Actions';
$lang['AdminPackageOptions.edit.text_delete'] = 'Delete';

$lang['AdminPackageOptions.edit.heading_prices'] = 'Pricing';
$lang['AdminPackageOptions.edit.price_term'] = 'Term';
$lang['AdminPackageOptions.edit.price_period'] = 'Period';
$lang['AdminPackageOptions.edit.price_currency'] = 'Currency';
$lang['AdminPackageOptions.edit.price_price'] = 'Price';
$lang['AdminPackageOptions.edit.price_price_renews'] = 'Renewal Price';
$lang['AdminPackageOptions.edit.price_setup'] = 'Setup Fee';
$lang['AdminPackageOptions.edit.price_options'] = 'Actions';
$lang['AdminPackageOptions.edit.price_add'] = 'Add';
$lang['AdminPackageOptions.edit.price_delete'] = 'Delete';

$lang['AdminPackageOptions.edit.heading_groups'] = 'Group Membership';
$lang['AdminPackageOptions.edit.text_membergroups'] = 'Member Groups';
$lang['AdminPackageOptions.edit.text_availablegroups'] = 'Available Groups';

$lang['AdminPackageOptions.edit.field_submit'] = 'Update Package Option';


// Add Group
$lang['AdminPackageOptions.addgroup.tab_add'] = 'Add Group';
$lang['AdminPackageOptions.addgroup.tab_import'] = 'Import Group';

$lang['AdminPackageOptions.addgroup.page_title'] = 'New Package Option Group';
$lang['AdminPackageOptions.addgroup.boxtitle_addgroup'] = 'New Package Option Group';

$lang['AdminPackageOptions.addgroup.heading_basic'] = 'Basic';
$lang['AdminPackageOptions.addgroup.field_name'] = 'Name';
$lang['AdminPackageOptions.addgroup.field_description'] = 'Description';

$lang['AdminPackageOptions.addgroup.heading_packages'] = 'Package Membership';
$lang['AdminPackageOptions.addgroup.text_memberpackages'] = 'Member Packages';
$lang['AdminPackageOptions.addgroup.text_availablepackages'] = 'Available Packages';

$lang['AdminPackageOptions.addgroup.heading_import'] = 'Import Group';

$lang['AdminPackageOptions.addgroup.field_import_file'] = 'Import File';
$lang['AdminPackageOptions.addgroup.field_addgroupsubmit'] = 'Create Group';
$lang['AdminPackageOptions.addgroup.field_importgroupsubmit'] = 'Import Group';


// Edit Group
$lang['AdminPackageOptions.editgroup.page_title'] = 'Update Package Option Group';
$lang['AdminPackageOptions.editgroup.boxtitle_editgroup'] = 'Update Package Option Group';

$lang['AdminPackageOptions.editgroup.heading_basic'] = 'Basic';
$lang['AdminPackageOptions.editgroup.field_name'] = 'Name';
$lang['AdminPackageOptions.editgroup.field_description'] = 'Description';

$lang['AdminPackageOptions.editgroup.heading_packages'] = 'Package Membership';
$lang['AdminPackageOptions.editgroup.text_memberpackages'] = 'Member Packages';
$lang['AdminPackageOptions.editgroup.text_availablepackages'] = 'Available Packages';

$lang['AdminPackageOptions.editgroup.field_editgroupsubmit'] = 'Update Group';


$lang['AdminPackageOptions.logic.boxtitle'] = 'Configurable Option Logic';
$lang['AdminPackageOptions.logic.title_condition_sets'] = 'Condition Sets';

$lang['AdminPackageOptions.logic.heading_trigger_option_id'] = 'Triggering Option';
$lang['AdminPackageOptions.logic.heading_operator'] = 'Comparison Operator';
$lang['AdminPackageOptions.logic.heading_value'] = 'Value';
$lang['AdminPackageOptions.logic.heading_options'] = 'Actions';

$lang['AdminPackageOptions.logic.tooltip_trigger_option_id'] = 'The option to compare against the given value.';
$lang['AdminPackageOptions.logic.tooltip_value'] = 'The value to which the triggering option should be compared. For the "in" operator you may enter a comma separated list value1,value2,value3';

$lang['AdminPackageOptions.logic.field_option_id'] = 'Enable Option:';
$lang['AdminPackageOptions.logic.field_option_value_id'] = 'Enable Option Value:';
$lang['AdminPackageOptions.logic.field_submit'] = 'Save Logic';

$lang['AdminPackageOptions.logic.text_if'] = 'IF';
$lang['AdminPackageOptions.logic.text_or'] = '-- OR --';
$lang['AdminPackageOptions.logic.text_and'] = 'AND';
$lang['AdminPackageOptions.logic.text_description'] = 'If ANY condition sets associated with a Option or Option Value evaluate as true, then it will be enabled. Otherwise it will be disabled. ALL conditions in a set must evaluate as true for a set to do the same. An Option with no condition sets will always be enabled.';

$lang['AdminPackageOptions.logic.option_add_set'] = 'Add Condition Set';
$lang['AdminPackageOptions.logic.option_add'] = 'Add';
$lang['AdminPackageOptions.logic.option_remove'] = 'Remove';
$lang['AdminPackageOptions.logic.option_remove_set'] = 'Remove Condition Set';


$lang['AdminPackageOptions.logicsettings.boxtitle'] = 'Configurable Option Logic - Settings';
$lang['AdminPackageOptions.logicsettings.field_hide_options'] = 'Hide options disabled by configurable option logic';
$lang['AdminPackageOptions.logicsettings.field_submit'] = 'Save';


// Paradigm Editor - Filter bar
$lang['AdminPackageOptions.index.text_options_count'] = '%1$s Options';
$lang['AdminPackageOptions.index.text_groups_count'] = '%1$s Groups';
$lang['AdminPackageOptions.index.text_unassigned_count'] = 'Unassigned';
$lang['AdminPackageOptions.index.text_in_x_groups'] = 'in %1$s groups'; // %1$s is the count
$lang['AdminPackageOptions.index.text_in_clipboard'] = 'in clipboard';

// Paradigm Editor - Column headers
$lang['AdminPackageOptions.index.heading_available_options'] = 'Available Options';
$lang['AdminPackageOptions.index.heading_option_groups'] = 'Option Groups';
$lang['AdminPackageOptions.index.text_filter_options'] = 'Filter options...';
$lang['AdminPackageOptions.index.text_filter_groups'] = 'Filter groups...';

// Paradigm Editor - Buttons
$lang['AdminPackageOptions.index.categorylink_importgroup'] = 'Import Group';
$lang['AdminPackageOptions.index.btn_collapse_all'] = 'Collapse All';
$lang['AdminPackageOptions.index.btn_expand_all'] = 'Expand All';
$lang['AdminPackageOptions.index.btn_clear_clipboard'] = 'Clear';

// Paradigm Editor - Option card meta
$lang['AdminPackageOptions.index.text_free'] = 'Free';
$lang['AdminPackageOptions.index.text_from_price'] = 'From %1$s/mo';
$lang['AdminPackageOptions.index.text_values_count'] = '%1$s values';
$lang['AdminPackageOptions.index.text_text_input'] = 'Text input';
$lang['AdminPackageOptions.index.text_no_values'] = 'No predefined values';

// Paradigm Editor - Empty states
$lang['AdminPackageOptions.index.text_drop_options'] = 'Drop options here or click Paste';
$lang['AdminPackageOptions.index.text_no_options'] = 'No configurable options yet.';
$lang['AdminPackageOptions.index.text_no_groups'] = 'No option groups yet.';

// Paradigm Editor - Option type labels
$lang['AdminPackageOptions.index.type_select'] = 'Dropdown';
$lang['AdminPackageOptions.index.type_radio'] = 'Radio Buttons';
$lang['AdminPackageOptions.index.type_checkbox'] = 'Checkboxes';
$lang['AdminPackageOptions.index.type_quantity'] = 'Quantity';
$lang['AdminPackageOptions.index.type_text'] = 'Text Input';
$lang['AdminPackageOptions.index.type_textarea'] = 'Text Area';
$lang['AdminPackageOptions.index.type_password'] = 'Password';

// Paradigm Editor - Group actions
$lang['AdminPackageOptions.index.option_copy'] = 'Copy to clipboard';
$lang['AdminPackageOptions.index.option_paste'] = 'Paste options from clipboard';
$lang['AdminPackageOptions.index.option_remove_from_group'] = 'Remove from group';
$lang['AdminPackageOptions.index.text_options_count_group'] = '(%1$s options)';

// Paradigm Editor - Modals: Create/Edit Option
$lang['AdminPackageOptions.modal.title_create_option'] = 'Create Configurable Option';
$lang['AdminPackageOptions.modal.title_edit_option'] = 'Edit Configurable Option';
$lang['AdminPackageOptions.modal.tab_basic'] = 'Basic';
$lang['AdminPackageOptions.modal.tab_values_pricing'] = 'Values & Pricing';
$lang['AdminPackageOptions.modal.tab_groups'] = 'Groups';
$lang['AdminPackageOptions.modal.btn_create_option'] = 'Create Option';
$lang['AdminPackageOptions.modal.btn_save_changes'] = 'Save Changes';
$lang['AdminPackageOptions.modal.btn_cancel'] = 'Cancel';

// Paradigm Editor - Modals: Create/Edit Group
$lang['AdminPackageOptions.modal.title_create_group'] = 'Create Option Group';
$lang['AdminPackageOptions.modal.title_edit_group'] = 'Edit Option Group';
$lang['AdminPackageOptions.modal.tab_basic_group'] = 'Basic';
$lang['AdminPackageOptions.modal.tab_packages'] = 'Package Membership';
$lang['AdminPackageOptions.modal.btn_create_group'] = 'Create Group';
$lang['AdminPackageOptions.modal.btn_update_group'] = 'Save Changes';

// Paradigm Editor - Modals: Import
$lang['AdminPackageOptions.modal.title_import_group'] = 'Import Config Option Group';
$lang['AdminPackageOptions.modal.text_import_info'] = 'Upload a JSON file previously exported from Blesta to import a configurable option group with all its options and pricing.';
$lang['AdminPackageOptions.modal.text_drag_drop'] = 'Drag & Drop JSON file here';
$lang['AdminPackageOptions.modal.text_or_browse'] = 'or';
$lang['AdminPackageOptions.modal.btn_browse'] = 'Browse Files';
$lang['AdminPackageOptions.modal.text_options_to_import'] = 'Options to Import';
$lang['AdminPackageOptions.modal.btn_import_group'] = 'Import Group';
$lang['AdminPackageOptions.modal.text_validation_errors'] = 'Validation Errors:';


// Paradigm Editor - Modals: Logic
$lang['AdminPackageOptions.modal.title_option_logic'] = 'Option Logic: %1$s';
$lang['AdminPackageOptions.modal.btn_save_logic'] = 'Save Logic';
$lang['AdminPackageOptions.modal.btn_close'] = 'Close';
$lang['AdminPackageOptions.modal.text_logic_description'] = 'If ANY condition set evaluates as true, the selected option values will be enabled. Otherwise they will be disabled. ALL conditions within a set must be true for that set to pass. Options with no condition sets are always enabled.';
$lang['AdminPackageOptions.modal.text_condition_set'] = 'Condition Set';
$lang['AdminPackageOptions.modal.text_or'] = 'OR';
$lang['AdminPackageOptions.modal.text_and'] = 'AND';
$lang['AdminPackageOptions.modal.text_if'] = 'IF';
$lang['AdminPackageOptions.modal.heading_trigger_option'] = 'Triggering Option';
$lang['AdminPackageOptions.modal.heading_operator'] = 'Operator';
$lang['AdminPackageOptions.modal.heading_value'] = 'Value';
$lang['AdminPackageOptions.modal.heading_actions'] = 'Actions';
$lang['AdminPackageOptions.modal.field_enable_value'] = 'Enable Option Value:';
$lang['AdminPackageOptions.modal.field_hide_options'] = 'Hide options disabled by configurable option logic';
$lang['AdminPackageOptions.modal.btn_add_condition_set'] = 'Add Condition Set';
$lang['AdminPackageOptions.modal.btn_add_condition'] = 'Add';
$lang['AdminPackageOptions.modal.btn_remove_condition'] = 'Remove';
$lang['AdminPackageOptions.modal.btn_remove_set'] = 'Remove Condition Set';
$lang['AdminPackageOptions.modal.text_no_options'] = 'This group has no options to configure logic for.';
$lang['AdminPackageOptions.modal.text_no_condition_sets'] = 'No condition sets configured. Click "Add Condition Set" to create one.';
$lang['AdminPackageOptions.modal.text_select_option'] = '-- Please Select --';

// Paradigm Editor - Toast notifications
$lang['AdminPackageOptions.!success.option_copied'] = 'Copied "%1$s" to clipboard';
$lang['AdminPackageOptions.!success.option_pasted'] = 'Added %1$s option(s) to "%2$s"';
$lang['AdminPackageOptions.!success.clipboard_cleared'] = 'Clipboard cleared';
$lang['AdminPackageOptions.!success.option_added_to_group'] = 'Added "%1$s" to "%2$s"';
$lang['AdminPackageOptions.!success.option_removed_from_group'] = 'Removed "%1$s" from "%2$s"';
$lang['AdminPackageOptions.!warning.option_already_in_group'] = 'Option already exists in this group';
