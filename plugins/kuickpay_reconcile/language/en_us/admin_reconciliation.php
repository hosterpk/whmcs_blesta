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

// ===========================================================================
// Run detail (Task 3)
// ===========================================================================
$lang['AdminReconciliation.detail.boxtitle'] = 'Reconciliation Run';
$lang['AdminReconciliation.link.back_to_runs'] = 'Back to Reconciliation Runs';
$lang['AdminReconciliation.!error.not_found'] = 'The requested reconciliation run could not be found.';

// Detail section headings
$lang['AdminReconciliation.detail.heading.counts'] = 'Count Breakdown';
$lang['AdminReconciliation.detail.heading.items'] = 'Processed Item Transitions';
$lang['AdminReconciliation.detail.heading.audit_exceptions'] = 'Audit-Only Exceptions';

// Item table headings
$lang['AdminReconciliation.detail.heading.voucher'] = 'Voucher';
$lang['AdminReconciliation.detail.heading.transition'] = 'Transition';
$lang['AdminReconciliation.detail.heading.failure_class'] = 'Failure Class';
$lang['AdminReconciliation.detail.heading.trace'] = 'Trace';
$lang['AdminReconciliation.detail.heading.when'] = 'When';

// Audit-only section headings
$lang['AdminReconciliation.detail.heading.event'] = 'Event';
$lang['AdminReconciliation.detail.heading.evidence'] = 'Evidence';

// Detail notes / empty states
$lang['AdminReconciliation.detail.running_note'] = 'This run is still in progress — counts are partial and may change.';
$lang['AdminReconciliation.detail.no_items'] = 'No processed item transitions were recorded for this run.';
$lang['AdminReconciliation.detail.items_truncated'] = 'Showing first %1$s of %2$s items.';
$lang['AdminReconciliation.detail.no_audit_exceptions'] = 'No audit-only exceptions (unmatched or duplicate) were recorded for this run.';
