<?php
// Success messages
$lang['AdminTickets.!success.ticket_created'] = 'The ticket #%1$s has been successfully opened.'; // %1$s is the ticket number
$lang['AdminTickets.!success.ticket_updated'] = 'The ticket #%1$s has been successfully updated.'; // %1$s is the ticket number
$lang['AdminTickets.!success.ticket_split'] = 'The ticket #%1$s has been successfully split into ticket #%2$s.'; // %1$s is the ticket number of the ticket being split, %2$s is the ticket number of the split ticket
$lang['AdminTickets.!success.ticket_merge'] = 'The selected tickets have been successfully merged into ticket #%1$s.'; // %1$s is the ticket number
$lang['AdminTickets.!success.ticket_reassign'] = 'The selected tickets have been successfully reassigned.';
$lang['AdminTickets.!success.ticket_update_status'] = 'The selected tickets have been successfully updated.';
$lang['AdminTickets.!success.ticket_delete'] = 'The selected tickets have been successfully deleted.';


// Notice messages
$lang['AdminTickets.!notice.no_departments_staff'] = 'No staff and/or departments have yet been created. Click %1$s above to create a department, or %2$s to assign a staff member.'; // %1$s is the language definition for the Departments navigation item, %2$s is the language definition for the Staff navigation item


// Tooltips
$lang['AdminTickets.!tooltip.contacts'] = 'Contacts to be notified when a ticket is updated. Those not selected will be automatically added to the ticket if they respond to it.';
$lang['AdminTickets.!tooltip.recipients'] = 'Email address to be notified when a ticket is updated.';


// Page Titles
$lang['AdminTickets.index.page_title'] = 'Support Manager > Tickets';
$lang['AdminTickets.add.page_title'] = 'Support Manager > Open Ticket';
$lang['AdminTickets.reply.page_title'] = 'Support Manager > Ticket #%1$s'; // %1$s is the ticket number
$lang['AdminTickets.search.page_title'] = 'Search Results for "%1$s"'; // %1$s is the search keywords


$lang['AdminTickets.text.unassigned'] = 'Not Assigned';


// Index
$lang['AdminTickets.index.category_open'] = 'Awaiting Staff';
$lang['AdminTickets.index.category_awaiting_reply'] = 'Awaiting Client';
$lang['AdminTickets.index.category_in_progress'] = 'In Progress';
$lang['AdminTickets.index.category_on_hold'] = 'On Hold';
$lang['AdminTickets.index.category_closed'] = 'Closed';
$lang['AdminTickets.index.category_trash'] = 'Trash';

$lang['AdminTickets.index.categorylink_createticket'] = 'Open Ticket';

$lang['AdminTickets.index.boxtitle_tickets'] = 'Tickets';
$lang['AdminTickets.index.text_fullscreen'] = 'Toggle Fullscreen';
$lang['AdminTickets.index.text_exit_fullscreen'] = 'Exit Fullscreen';
$lang['AdminTickets.index.heading_code'] = 'Ticket Number';
$lang['AdminTickets.index.heading_client'] = 'Client';
$lang['AdminTickets.index.heading_priority'] = 'Priority';
$lang['AdminTickets.index.heading_rating'] = 'Rating';
$lang['AdminTickets.index.heading_department_name'] = 'Department';
$lang['AdminTickets.index.heading_summary'] = 'Summary';
$lang['AdminTickets.index.heading_assigned_staff'] = 'Assigned To';
$lang['AdminTickets.index.heading_last_reply_date'] = 'Last Reply';

$lang['AdminTickets.index.any'] = 'Any';
$lang['AdminTickets.index.minutes'] = '%1$s minutes'; // %1$s is the elapsed minutes
$lang['AdminTickets.index.hour'] = '1 hour';
$lang['AdminTickets.index.hours'] = '%1$s hours'; // %1$s is the elapsed hours
$lang['AdminTickets.index.field_ticket_number'] = 'Ticket Number';
$lang['AdminTickets.index.field_priority'] = 'Priority';
$lang['AdminTickets.index.field_department_id'] = 'Department';
$lang['AdminTickets.index.field_summary'] = 'Summary';
$lang['AdminTickets.index.field_assigned_staff'] = 'Assigned To';
$lang['AdminTickets.index.field_last_reply'] = 'Last Reply';
$lang['AdminTickets.index.placeholder_ticket_number'] = 'Enter ticket number';
$lang['AdminTickets.index.placeholder_summary'] = 'Search summary';

$lang['AdminTickets.index.unassigned'] = 'Unassigned';
$lang['AdminTickets.index.last_reply_by'] = 'by';

$lang['AdminTickets.index.no_results'] = 'There are currently no tickets with this status.';

$lang['AdminTickets.index.text_with_selected'] = 'With selected tickets, perform:';
$lang['AdminTickets.index.text_into_ticket'] = 'Into ticket:';
$lang['AdminTickets.index.text_to_client'] = 'To client:';
$lang['AdminTickets.index.text_to_status'] = 'Change to:';
$lang['AdminTickets.index.ticket_number_placeholder'] = 'Ticket Number';
$lang['AdminTickets.index.text_no_tickets'] = 'No open tickets found. Try searching again.';
$lang['AdminTickets.index.field_actionsubmit'] = 'Submit';

$lang['AdminTickets.index.heading_filters'] = 'Filters';
$lang['AdminTickets.index.field_apply_filters'] = 'Apply Filters';
$lang['AdminTickets.index.field_clear_filters'] = 'Clear Filters';

$lang['AdminTickets.index.ticket_name'] = '#%1$s %2$s %3$s'; // %1$s is the ticket number, %2$s is the client's first name, %3$s is the client's last name
$lang['AdminTickets.index.ticket_email'] = '#%1$s %2$s'; // %1$s is the ticket number, %2$s is the client's email address


// Add Ticket
$lang['AdminTickets.add.boxtitle_add'] = 'Open Ticket';

$lang['AdminTickets.add.heading_search_client'] = 'Search for the Client';
$lang['AdminTickets.add.text_no_clients'] = 'No clients found. Try searching again.';
$lang['AdminTickets.add.text_no_contacts'] = 'No additional contacts available for the selected client.';

$lang['AdminTickets.add.heading_summary'] = 'Summary';
$lang['AdminTickets.add.heading_recipients'] = 'Recipients';
$lang['AdminTickets.add.heading_contacts'] = 'Contacts';
$lang['AdminTickets.add.heading_contacts_recipients'] = 'Contacts & Recipients';
$lang['AdminTickets.add.heading_client'] = 'Client';
$lang['AdminTickets.add.heading_department'] = 'Department';
$lang['AdminTickets.add.heading_service'] = 'Service';
$lang['AdminTickets.add.heading_staff_id'] = 'Assigned To';
$lang['AdminTickets.add.heading_priority'] = 'Priority';
$lang['AdminTickets.add.heading_status'] = 'Status';
$lang['AdminTickets.add.field_attachments'] = 'Attachments';
$lang['AdminTickets.add.field_details'] = 'Details';
$lang['AdminTickets.add.text_add_recipient'] = 'Add Recipient';
$lang['AdminTickets.add.text_add_attachment'] = 'Add Attachment';
$lang['AdminTickets.add.field_addsubmit'] = 'Open Ticket';
$lang['Admintickets.add.client_placeholder'] = 'Client ID or Name';

$lang['AdminTickets.add.text_add_response'] = 'Insert Predefined Response';
$lang['AdminTickets.add.search_responses'] = 'Search responses...';
$lang['AdminTickets.add.no_results'] = 'No matching responses found';
$lang['AdminTickets.add.searching'] = 'Searching...';
$lang['AdminTickets.add.search_min_chars'] = 'Enter at least 2 characters to search';

$lang['AdminTickets.add.dropzone_drop_files_here'] = 'Drop files here to upload or click to select files';
$lang['AdminTickets.add.dropzone_remove_file'] = 'Remove File';

$lang['AdminTickets.add.text_contacts'] = 'Contacts not selected will be automatically added to the ticket if they respond to it.';
$lang['AdminTickets.add.heading_ticket_details'] = 'Ticket Details';
$lang['AdminTickets.add.login_as_client'] = 'Login as Client';
$lang['AdminTickets.add.markdown_supported'] = 'Markdown supported';
$lang['AdminTickets.add.dropzone_drop_files'] = 'Drop files here to upload or click to select files';
$lang['AdminTickets.add.browse_files'] = 'Browse Files';

// Custom Fields
$lang['AdminTickets.custom_fields.badge_custom'] = 'Custom';

// Reply
$lang['AdminTickets.reply.boxtitle_reply'] = 'Ticket #%1$s'; // %1$s is the ticket number

$lang['AdminTickets.reply.heading_summary'] = 'Summary';
$lang['AdminTickets.reply.heading_rating_comment'] = 'Rating Comment';
$lang['AdminTickets.reply.heading_recipients'] = 'Recipients';
$lang['AdminTickets.reply.heading_contacts'] = 'Contacts';
$lang['AdminTickets.reply.heading_contacts_recipients'] = 'Contacts & Recipients';
$lang['AdminTickets.reply.heading_client'] = 'Client';
$lang['AdminTickets.reply.heading_service'] = 'Service';
$lang['AdminTickets.reply.heading_department'] = 'Department';
$lang['AdminTickets.reply.heading_staff_id'] = 'Assigned To';
$lang['AdminTickets.reply.heading_priority'] = 'Priority';
$lang['AdminTickets.reply.heading_rating'] = 'Customer Rating';
$lang['AdminTickets.reply.text_date_rated'] = 'Rated: %1$s'; // %1$s is the date the rating was given
$lang['AdminTickets.reply.heading_status'] = 'Status';
$lang['AdminTickets.reply.heading_date_added'] = 'Date Opened';

$lang['AdminTickets.reply.text_add_response'] = 'Insert a Predefined Response';
$lang['AdminTickets.reply.text_with_selected'] = 'With selected replies, perform:';

$lang['AdminTickets.reply.heading_reply'] = 'Add Reply';
$lang['AdminTickets.reply.field_reply'] = 'Reply';
$lang['AdminTickets.reply.field_note'] = 'Note';
$lang['AdminTickets.reply.field_attachments'] = 'Attachments';
$lang['AdminTickets.reply.text_service_none'] = 'None';
$lang['AdminTickets.reply.text_domain'] = 'Domain';
$lang['AdminTickets.reply.text_additional_recipients'] = 'Additional Recipients';
$lang['AdminTickets.reply.text_add_recipient'] = 'Add Recipient';
$lang['AdminTickets.reply.text_add_attachment'] = 'Add Attachment';
$lang['AdminTickets.reply.field_replysubmit'] = 'Update Ticket';
$lang['AdminTickets.reply.field_actionsubmit'] = 'Go';

$lang['AdminTickets.reply.refresh'] = 'There are new replies or status changes.';
$lang['AdminTickets.reply.refresh_link'] = 'Click to display.';

$lang['AdminTickets.reply.reply_date'] = 'On %1$s %2$s %3$s replied'; // %1$s is the ticket reply date, %2$s is the first name of the reply author, %3$s is the last name of the reply author
$lang['AdminTickets.reply.note_date'] = 'On %1$s %2$s %3$s added a note'; // %1$s is the ticket reply date, %2$s is the first name of the reply author, %3$s is the last name of the reply author
$lang['AdminTickets.reply.log_date'] = '%1$s by %2$s %3$s'; // %1$s is the ticket reply date, %2$s is the first name of the reply author, %3$s is the last name of the reply author
$lang['AdminTickets.reply.system'] = 'System';
$lang['AdminTickets.reply.staff_title'] = 'Support Staff';

$lang['AdminTickets.reply.dropzone_drop_files_here'] = 'Drop files here to upload or Click to select files';
$lang['AdminTickets.reply.dropzone_remove_file'] = 'Remove File';

$lang['AdminTickets.reply.text_contacts'] = 'Contacts not selected will be automatically added to the ticket if they respond to it.';

$lang['AdminTickets.reply.heading_ticket_details'] = 'Ticket Details';
$lang['AdminTickets.reply.login_as_client'] = 'Login as Client';
$lang['AdminTickets.reply.search_responses'] = 'Search responses...';
$lang['AdminTickets.reply.no_results'] = 'No matching responses found';
$lang['AdminTickets.reply.searching'] = 'Searching...';
$lang['AdminTickets.reply.search_min_chars'] = 'Enter at least 2 characters to search';
$lang['AdminTickets.reply.markdown_supported'] = 'Markdown supported';
$lang['AdminTickets.reply.notes_visible_staff'] = 'Internal notes are only visible to staff members';
$lang['AdminTickets.reply.dropzone_drop_files'] = 'Drop files here to upload or Click to select files';
$lang['AdminTickets.reply.browse_files'] = 'Browse Files';
$lang['AdminTickets.reply.btn_cancel'] = 'Cancel';
$lang['AdminTickets.reply.client_title'] = 'Client';
$lang['AdminTickets.reply.note_label'] = 'Staff Note';


// Client widget
// Index
$lang['AdminTickets.client.category_open'] = 'Awaiting Staff Reply';
$lang['AdminTickets.client.category_awaiting_reply'] = 'Awaiting Client Reply';
$lang['AdminTickets.client.category_in_progress'] = 'In Progress';
$lang['AdminTickets.client.category_on_hold'] = 'On Hold';
$lang['AdminTickets.client.category_closed'] = 'Closed';
$lang['AdminTickets.client.category_trash'] = 'Trash';

$lang['AdminTickets.client.categorylink_createticket'] = 'Open Ticket';

$lang['AdminTickets.client.boxtitle_tickets'] = 'Tickets';
$lang['AdminTickets.client.heading_code'] = 'Ticket Number';
$lang['AdminTickets.client.heading_priority'] = 'Priority';
$lang['AdminTickets.client.heading_department_name'] = 'Department';
$lang['AdminTickets.client.heading_summary'] = 'Summary';
$lang['AdminTickets.client.heading_last_reply_date'] = 'Last Reply';

$lang['AdminTickets.client.no_results'] = 'There are currently no tickets with this status.';


// Search
$lang['AdminTickets.search.boxtitle_tickets'] = 'Search Tickets for "%1$s"'; // %1$s is the search criteria
$lang['AdminTickets.search.heading_code'] = 'Ticket Number';
$lang['AdminTickets.search.heading_priority'] = 'Priority';
$lang['AdminTickets.search.heading_status'] = 'Status';
$lang['AdminTickets.search.heading_department_name'] = 'Department';
$lang['AdminTickets.search.heading_summary'] = 'Summary';
$lang['AdminTickets.search.heading_last_reply_date'] = 'Last Reply';

$lang['AdminTickets.search.no_results'] = 'There are no tickets that match the search criteria.';


// AI Response Generation
$lang['AdminTickets.reply.button_generate_ai_response'] = 'Generate AI Response';
$lang['AdminTickets.reply.button_ai_response_ready'] = 'AI Response Ready';
$lang['AdminTickets.reply.button_regenerate'] = 'Regenerate';
$lang['AdminTickets.reply.button_regenerate_ai'] = 'Regenerate AI Response';
$lang['AdminTickets.reply.text_generating'] = 'Generating...';
$lang['AdminTickets.reply.text_regenerating'] = 'Regenerating...';
$lang['AdminTickets.reply.text_just_now'] = 'Just now';
$lang['AdminTickets.reply.text_minutes_ago'] = '%1$sm ago'; // %1$s is the number of minutes
$lang['AdminTickets.reply.text_hours_ago'] = '%1$sh ago'; // %1$s is the number of hours
$lang['AdminTickets.reply.text_days_ago'] = '%1$sd ago'; // %1$s is the number of days
$lang['AdminTickets.reply.modal_title'] = 'AI-Generated Response';
$lang['AdminTickets.reply.label_confidence'] = 'Confidence';
$lang['AdminTickets.reply.label_generated'] = 'Generated';
$lang['AdminTickets.reply.label_model'] = 'Model';
$lang['AdminTickets.reply.alert_review_required'] = 'Review Required';
$lang['AdminTickets.reply.alert_review_text'] = 'This response was automatically generated by AI. Please review it carefully before sending to ensure accuracy and appropriate tone.';
$lang['AdminTickets.reply.label_internal_notes'] = 'Internal Notes';
$lang['AdminTickets.reply.label_suggested_response'] = 'Suggested Response';
$lang['AdminTickets.reply.label_concerns'] = 'Concerns';
$lang['AdminTickets.reply.text_no_response_suggested'] = 'No response suggested. See internal notes for reasoning.';
$lang['AdminTickets.reply.button_cancel'] = 'Cancel';
$lang['AdminTickets.reply.button_use_response'] = 'Use This Response';
$lang['AdminTickets.reply.button_reject'] = 'Reject';

// AI Summary
$lang['AdminTickets.reply.button_summarize'] = 'Summarize';
$lang['AdminTickets.reply.text_ai_summary'] = 'AI Summary';

// AI Errors
$lang['AdminTickets.!error.ticket_invalid'] = 'Invalid ticket ID';
$lang['AdminTickets.!error.ai_not_enabled'] = 'AI features are not enabled for Support Manager';
$lang['AdminTickets.!error.ai_generation_failed'] = 'Failed to generate AI response';
$lang['AdminTickets.!error.analysis_invalid'] = 'Invalid analysis ID';
$lang['AdminTickets.!error.reply_not_found'] = 'The specified reply could not be found.';
$lang['AdminTickets.!error.summary_failed'] = 'Failed to generate summary.';
