<?php
$lang['AdminVouchers.index.boxtitle'] = 'KuickPay Vouchers';

// Column headings
$lang['AdminVouchers.heading.created'] = 'Created';
$lang['AdminVouchers.heading.client'] = 'Client';
$lang['AdminVouchers.heading.invoice'] = 'Invoice';
$lang['AdminVouchers.heading.amount'] = 'Amount';
$lang['AdminVouchers.heading.consumer_number'] = 'Consumer Number';
$lang['AdminVouchers.heading.status'] = 'Status';
$lang['AdminVouchers.heading.last_inquiry'] = 'Last Inquiry';
$lang['AdminVouchers.heading.transaction'] = 'Transaction';

// Filter labels
$lang['AdminVouchers.filter.status'] = 'Status';
$lang['AdminVouchers.filter.any'] = 'Any';
$lang['AdminVouchers.filter.client_id'] = 'Client ID';
$lang['AdminVouchers.filter.client_id_placeholder'] = 'Numeric client ID';
$lang['AdminVouchers.filter.consumer_number'] = 'Consumer Number';
$lang['AdminVouchers.filter.registration_number'] = 'Registration Number';
$lang['AdminVouchers.filter.kuickpay_reference'] = 'KuickPay Reference';
$lang['AdminVouchers.filter.amount'] = 'Amount';
$lang['AdminVouchers.filter.amount_placeholder'] = 'e.g. 100.00';
$lang['AdminVouchers.filter.invoice_id'] = 'Invoice ID';
$lang['AdminVouchers.filter.date_from'] = 'Created From';
$lang['AdminVouchers.filter.date_to'] = 'Created To';
$lang['AdminVouchers.filter.date_placeholder'] = 'YYYY-MM-DD';
$lang['AdminVouchers.filter.has_blesta_transaction'] = 'Has Blesta transaction';

// Status labels (closed allowlist via presenter)
$lang['AdminVouchers.status.pending'] = 'Pending';
$lang['AdminVouchers.status.retry'] = 'Retry';
$lang['AdminVouchers.status.confirmed_unposted'] = 'Confirmed (Unposted)';
$lang['AdminVouchers.status.posted'] = 'Posted';
$lang['AdminVouchers.status.failed'] = 'Failed';
$lang['AdminVouchers.status.expired'] = 'Expired';
$lang['AdminVouchers.status.manual_review'] = 'Manual Review';
$lang['AdminVouchers.status.cancelled'] = 'Cancelled';
$lang['AdminVouchers.status.unknown'] = 'Unknown';

// Row helpers
$lang['AdminVouchers.text.empty'] = '—';
$lang['AdminVouchers.text.transaction_view'] = 'View transaction';
$lang['AdminVouchers.no_results'] = 'No Vouchers match these filters.';

// ===========================================================================
// Story 4.2 — Voucher detail page
// ===========================================================================

// Not found / navigation / generic placeholders
$lang['AdminVouchers.!error.not_found'] = 'The requested voucher could not be found.';
$lang['AdminVouchers.link.back_to_list'] = 'Back to Voucher List';
$lang['AdminVouchers.text.none'] = 'None';

// Detail box titles
$lang['AdminVouchers.detail.heading.summary'] = 'Voucher Summary';
$lang['AdminVouchers.detail.heading.invoices'] = 'Invoice Mapping & Related Records';
$lang['AdminVouchers.detail.heading.admin_notes'] = 'Admin Notes';
$lang['AdminVouchers.detail.heading.actions'] = 'Actions';
$lang['AdminVouchers.detail.heading.parsed_summary'] = 'Parsed Response Summary';
$lang['AdminVouchers.detail.heading.diagnostics'] = 'Diagnostics';
$lang['AdminVouchers.detail.heading.audit_timeline'] = 'Audit Timeline';

// Detail field labels
$lang['AdminVouchers.detail.field.status'] = 'Status';
$lang['AdminVouchers.detail.field.client'] = 'Client';
$lang['AdminVouchers.detail.field.registration_number'] = 'Registration Number';
$lang['AdminVouchers.detail.field.consumer_number'] = 'Consumer Number';
$lang['AdminVouchers.detail.field.kuickpay_reference'] = 'KuickPay Reference';
$lang['AdminVouchers.detail.field.amount'] = 'Amount';
$lang['AdminVouchers.detail.field.date_created'] = 'Created';
$lang['AdminVouchers.detail.field.date_updated'] = 'Updated';
$lang['AdminVouchers.detail.field.date_due'] = 'Due';
$lang['AdminVouchers.detail.field.date_expires'] = 'Expires';
$lang['AdminVouchers.detail.field.date_last_checked'] = 'Last Inquiry';
$lang['AdminVouchers.detail.field.date_paid'] = 'Paid';
$lang['AdminVouchers.detail.field.date_posted'] = 'Posted';
$lang['AdminVouchers.detail.field.invoices'] = 'Invoices';
$lang['AdminVouchers.detail.field.posting_state'] = 'Posting State';
$lang['AdminVouchers.detail.field.transaction'] = 'Blesta Transaction';
$lang['AdminVouchers.detail.field.error_class'] = 'Error Class';
$lang['AdminVouchers.detail.field.retry_count'] = 'Retry Count';
$lang['AdminVouchers.detail.field.raw_status'] = 'Raw Status';
$lang['AdminVouchers.detail.field.evidence_hash'] = 'Evidence Hash';
$lang['AdminVouchers.detail.field.redacted_trace_id'] = 'Redacted Trace ID';
$lang['AdminVouchers.detail.field.validation_errors'] = 'Validation Errors';
$lang['AdminVouchers.detail.field.reference'] = 'Reference';
$lang['AdminVouchers.detail.field.currency'] = 'Currency';
$lang['AdminVouchers.detail.field.paid_at'] = 'Paid At';

// Detail placeholders / diagnostics chrome
$lang['AdminVouchers.detail.admin_notes.empty'] = 'No admin notes.';
$lang['AdminVouchers.detail.diagnostics.none'] = 'No diagnostic evidence available.';
$lang['AdminVouchers.detail.diagnostics.aria_label'] = 'Voucher diagnostics and audit timeline';
$lang['AdminVouchers.detail.audit.none'] = 'No audit events recorded for this voucher.';

// Manual action labels, prompts, and outcomes
$lang['AdminVouchers.action.recheck'] = 'Check Now';
$lang['AdminVouchers.action.review'] = 'Mark Manual Review';
$lang['AdminVouchers.action.cancel'] = 'Cancel';
$lang['AdminVouchers.label.admin_note'] = 'Admin Note';
$lang['AdminVouchers.confirm.cancel'] = 'Cancel this voucher? Existing evidence and audit history will be preserved.';
$lang['AdminVouchers.!error.note_required'] = 'An admin note is required.';
$lang['AdminVouchers.!error.invalid_state'] = 'The voucher state changed. Refresh the page and try again.';
$lang['AdminVouchers.!error.acl_denied'] = 'You do not have permission to run this voucher action.';
$lang['AdminVouchers.!success.review'] = 'Voucher routed to manual review.';
$lang['AdminVouchers.!success.cancel'] = 'Voucher cancelled.';
$lang['AdminVouchers.!success.recheck_posted'] = 'Checked — payment posted.';
$lang['AdminVouchers.!success.recheck_manual_review'] = 'Checked — routed to manual review.';
$lang['AdminVouchers.!success.recheck_confirmed'] = 'Checked — payment confirmed.';
$lang['AdminVouchers.!success.recheck_pending'] = 'Checked — payment still pending.';
$lang['AdminVouchers.!success.recheck_expired'] = 'Checked — voucher has expired.';
$lang['AdminVouchers.!success.recheck_retry'] = 'Checked — provider still unavailable, will retry.';
$lang['AdminVouchers.!success.recheck_already_posted'] = 'Already posted.';
$lang['AdminVouchers.!error.recheck_unreachable'] = 'Couldn\'t reach KuickPay — please try again.';
$lang['AdminVouchers.!error.recheck_unavailable'] = 'KuickPay reconciliation is unavailable.';
$lang['AdminVouchers.!error.recheck_failed'] = 'Check failed — please try again later.';

// Posting-state matrix (admin labels; success/transaction link only for posted)
$lang['AdminVouchers.posting_state.pending'] = 'Voucher active, not posted';
$lang['AdminVouchers.posting_state.retry'] = 'Provider unavailable';
$lang['AdminVouchers.posting_state.confirmed_unposted'] = 'Validated evidence, ready to post';
$lang['AdminVouchers.posting_state.posted'] = 'Posted to Blesta';
$lang['AdminVouchers.posting_state.failed'] = 'Evidence mismatch, review required';
$lang['AdminVouchers.posting_state.expired'] = 'Expired, not posted';
$lang['AdminVouchers.posting_state.manual_review'] = 'Duplicate or ambiguous evidence';
$lang['AdminVouchers.posting_state.cancelled'] = 'Cancelled, not posted';
$lang['AdminVouchers.posting_state.unknown'] = 'Unknown';

// Error-class labels (closed allowlist via presenter; all 10 stored + unknown)
$lang['AdminVouchers.error_class.timeout'] = 'Timeout';
$lang['AdminVouchers.error_class.transport_error'] = 'Transport Error';
$lang['AdminVouchers.error_class.credential_error'] = 'Credential Error';
$lang['AdminVouchers.error_class.malformed_response'] = 'Malformed Response';
$lang['AdminVouchers.error_class.unknown_status'] = 'Unknown Status';
$lang['AdminVouchers.error_class.amount_mismatch'] = 'Amount Mismatch';
$lang['AdminVouchers.error_class.duplicate_reference'] = 'Duplicate Reference';
$lang['AdminVouchers.error_class.unmatched_reference'] = 'Unmatched Reference';
$lang['AdminVouchers.error_class.posting_failed'] = 'Posting Failed';
$lang['AdminVouchers.error_class.reconcile_exception'] = 'Reconcile Exception';
$lang['AdminVouchers.error_class.unknown'] = 'Unknown';

// Audit event labels (closed allowlist via presenter; 19 events + generic)
$lang['AdminVouchers.event.voucher.issued'] = 'Voucher Issued';
$lang['AdminVouchers.event.voucher.replaced'] = 'Voucher Replaced';
$lang['AdminVouchers.event.voucher.expired'] = 'Voucher Expired';
$lang['AdminVouchers.event.voucher.generation_failed'] = 'Reference Generation Failed';
$lang['AdminVouchers.event.evidence.received'] = 'Evidence Received';
$lang['AdminVouchers.event.evidence.matched'] = 'Evidence Matched';
$lang['AdminVouchers.event.evidence.retry_decision'] = 'Retry Decision';
$lang['AdminVouchers.event.evidence.rejected'] = 'Evidence Rejected';
$lang['AdminVouchers.event.evidence.duplicate'] = 'Duplicate Evidence';
$lang['AdminVouchers.event.evidence.unmatched'] = 'Unmatched Evidence';
$lang['AdminVouchers.event.evidence.error'] = 'Processing Error';
$lang['AdminVouchers.event.reconciliation.run.started'] = 'Reconciliation Run Started';
$lang['AdminVouchers.event.reconciliation.run.completed'] = 'Reconciliation Run Completed';
$lang['AdminVouchers.event.posting.started'] = 'Posting Started';
$lang['AdminVouchers.event.posting.succeeded'] = 'Posting Succeeded';
$lang['AdminVouchers.event.posting.failed'] = 'Posting Failed';
$lang['AdminVouchers.event.admin.rechecked'] = 'Admin Checked Voucher';
$lang['AdminVouchers.event.admin.reviewed'] = 'Admin Marked Manual Review';
$lang['AdminVouchers.event.admin.cancelled'] = 'Admin Cancelled Voucher';
$lang['AdminVouchers.event.unknown'] = 'Event';

// Validation-reason labels (closed allowlist via presenter; all mapped + unknown)
$lang['AdminVouchers.validation_reason.currency_mismatch'] = 'Currency mismatch';
$lang['AdminVouchers.validation_reason.amount_mismatch'] = 'Amount mismatch';
$lang['AdminVouchers.validation_reason.unmatched_reference'] = 'Unmatched reference';
$lang['AdminVouchers.validation_reason.invoice_mismatch'] = 'Invoice mismatch';
$lang['AdminVouchers.validation_reason.stale_voucher'] = 'Stale voucher';
$lang['AdminVouchers.validation_reason.duplicate_reference'] = 'Duplicate reference';
$lang['AdminVouchers.validation_reason.late_payment'] = 'Late payment';
$lang['AdminVouchers.validation_reason.missing_paid_date'] = 'Missing paid date';
$lang['AdminVouchers.validation_reason.existing_transaction_mismatch'] = 'Existing transaction mismatch';
$lang['AdminVouchers.validation_reason.existing_transaction_partial_application'] = 'Existing transaction partially applied';
$lang['AdminVouchers.validation_reason.existing_transaction_apply_failed'] = 'Existing transaction apply failed';
$lang['AdminVouchers.validation_reason.existing_transaction_unverified'] = 'Existing transaction unverified';
$lang['AdminVouchers.validation_reason.missing_expected_context'] = 'Missing expected context';
$lang['AdminVouchers.validation_reason.underpayment'] = 'Underpayment';
$lang['AdminVouchers.validation_reason.overpayment'] = 'Overpayment';
$lang['AdminVouchers.validation_reason.unknown_status'] = 'Unknown status';
$lang['AdminVouchers.validation_reason.unknown'] = 'Unknown reason';

// ===========================================================================
// Story 4.4 — reconciliation run trigger-type and status labels (presenter
// namespace; closed allowlist). Shared by the run list and run detail views.
// ===========================================================================
$lang['AdminVouchers.run_trigger.cron'] = 'Scheduled';
$lang['AdminVouchers.run_trigger.manual'] = 'Manual';
$lang['AdminVouchers.run_trigger.bulk'] = 'Bulk';
$lang['AdminVouchers.run_trigger.unknown'] = 'Unknown';
$lang['AdminVouchers.run_status.running'] = 'Running';
$lang['AdminVouchers.run_status.completed'] = 'Completed';
$lang['AdminVouchers.run_status.aborted'] = 'Aborted';
$lang['AdminVouchers.run_status.failed'] = 'Failed';
$lang['AdminVouchers.run_status.unknown'] = 'Unknown';
