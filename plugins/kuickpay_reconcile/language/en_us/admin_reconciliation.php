<?php
// Box titles
$lang['AdminReconciliation.index.boxtitle'] = 'KuickPay Reconciliation Runs';

// Run-list column headings
$lang['AdminReconciliation.heading.run_number'] = 'Run #';
$lang['AdminReconciliation.heading.type'] = 'Type';
$lang['AdminReconciliation.heading.status'] = 'Status';
$lang['AdminReconciliation.heading.started'] = 'Started';
$lang['AdminReconciliation.heading.completed'] = 'Completed';
$lang['AdminReconciliation.heading.run_date'] = 'Run Date';

// Count labels (used identically in the run list and the run detail)
$lang['AdminReconciliation.count.checked'] = 'Checked';
$lang['AdminReconciliation.count.confirmed'] = 'Confirmed (ready to post)';
$lang['AdminReconciliation.count.manual_review'] = 'Manual Review (incl. unmatched)';
$lang['AdminReconciliation.count.unmatched'] = '— of which Unmatched';
$lang['AdminReconciliation.count.failed'] = 'Failed';
$lang['AdminReconciliation.count.errors'] = 'Errors';

// Count footnotes (honest reconciliation of the durable counters)
$lang['AdminReconciliation.footnote.posting'] = 'Confirmed rows are validated evidence awaiting posting; posting runs on a separate task.';
$lang['AdminReconciliation.footnote.unmatched_subset'] = 'On bulk runs, Unmatched is a subset of Manual Review (the same provider row increments both); it is shown as a subset, not added separately.';
$lang['AdminReconciliation.footnote.eligible'] = 'On bulk runs, Checked/Eligible counts returned provider rows, not local eligible vouchers.';
$lang['AdminReconciliation.footnote.failed_errors'] = 'A wholesale bulk transport/parse failure increments both Failed and Errors for the same run-level incident; they are shown separately, never summed.';

// Empty state
$lang['AdminReconciliation.no_results'] = 'No reconciliation runs have been recorded yet.';
