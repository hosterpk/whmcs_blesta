<?php
// Success messages
$lang['AdminMain.!success.options_updated'] = 'The Mass Mailer settings were successfully updated!';


// Tooltips
$lang['AdminMain.!tooltip.rate_limit'] = 'Limits the amount of emails sent per each cron task execution. At the next run, it will pick up where it left off and send the next batch of emails, and so on until all the emails are sent. 0 for no limit.';


// Errors
$lang['AdminMain.!error.export_found'] = 'The export could not be found on the file system.';


// Page Titles
$lang['AdminMain.index.page_title'] = 'Mass Mailer';


// Index
$lang['AdminMain.index.boxtitle'] = 'Mass Mailer Jobs';
$lang['AdminMain.index.categorylink_compose'] = 'Create New Mailing';

$lang['AdminMain.index.job_task_total'] = '%1$s / %2$s'; // %1$s is the number of mailing tasks completed, %2$s is the total number of mailing tasks
$lang['AdminMain.index.heading_date'] = 'Date Added';
$lang['AdminMain.index.heading_type'] = 'Type';
$lang['AdminMain.index.heading_status'] = 'Status';
$lang['AdminMain.index.heading_complete'] = 'Completed';
$lang['AdminMain.index.heading_options'] = 'Actions';
$lang['AdminMain.index.option_export'] = 'Export';

$lang['AdminMain.index.type.email'] = 'Email';
$lang['AdminMain.index.type.export'] = 'Export';

$lang['AdminMain.index.email_to'] = 'To';
$lang['AdminMain.index.email_from'] = 'From';
$lang['AdminMain.index.email_subject'] = 'Subject';
$lang['AdminMain.index.email_to_recipients'] = '%1$s Recipient(s)'; // %1$s is the number of email recipients

$lang['AdminMain.index.no_export_details'] = 'There are no details available on the export.';
$lang['AdminMain.index.no_results'] = 'There are currently no mailing jobs.';


// Settings
$lang['AdminMain.settings.boxtitle'] = 'Mass Mailer Settings';
$lang['AdminMain.settings.heading'] = 'Settings';
$lang['AdminMain.settings.form.rate_limit'] = 'Rate Limit';
$lang['AdminMain.settings.submit_settings'] = 'Save Settings';
$lang['AdminMain.settings.submit_cancel'] = 'Cancel';
