<?php
// Basics
$lang['Webnic.name'] = 'WebNIC';
$lang['Webnic.description'] = 'Resell domains through WebNIC.';
$lang['Webnic.module_row'] = 'Account';
$lang['Webnic.module_row_plural'] = 'Accounts';

// Cron tasks
$lang['Webnic.getCronTasks.reconcile_orders_name'] = 'Reconcile WebNIC Orders';
$lang['Webnic.getCronTasks.reconcile_orders_desc'] = 'Reconciles pending WebNIC domain orders against the registry and finalizes or fails them.';

// Module row management
$lang['Webnic.add_module_row'] = 'Add Account';
$lang['Webnic.add_row.box_title'] = 'Add WebNIC Account';
$lang['Webnic.add_row.add_btn'] = 'Add Account';
$lang['Webnic.edit_row.box_title'] = 'Edit WebNIC Account';
$lang['Webnic.edit_row.edit_btn'] = 'Update Account';
$lang['Webnic.row_meta.username'] = 'API Username';
$lang['Webnic.row_meta.secret'] = 'API Secret';
$lang['Webnic.row_meta.secret.help'] = 'Leave blank to keep the current secret.';
$lang['Webnic.row_meta.secret.verify_help'] = 'Credentials are verified with WebNIC when you save. This runs a live check that may take a few seconds and can fail if this server\'s IP is not yet allowlisted.';
$lang['Webnic.row_meta.environment'] = 'Environment';
$lang['Webnic.row_meta.environment.ote'] = 'OTE / Sandbox';
$lang['Webnic.row_meta.environment.production'] = 'Production';
$lang['Webnic.row_meta.environment.help'] = 'Use credentials for the selected environment. OTE and Production credentials are separate, and WebNIC may require source IP allowlisting.';
$lang['Webnic.manage.module_rows_heading.username'] = 'Username';
$lang['Webnic.manage.module_rows_heading.environment'] = 'Environment';
$lang['Webnic.manage.module_rows_heading.options'] = 'Options';
$lang['Webnic.manage.module_rows.edit'] = 'Edit';
$lang['Webnic.manage.module_rows.delete'] = 'Delete';
$lang['Webnic.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this WebNIC account?';
$lang['Webnic.manage.no_results'] = 'There are no WebNIC accounts.';
$lang['Webnic.env_badge.ote'] = 'OTE / Sandbox';
$lang['Webnic.env_badge.production'] = 'Production';

// Errors
$lang['Webnic.!error.username.empty'] = 'Please enter the API username.';
$lang['Webnic.!error.secret.empty'] = 'Please enter the API secret.';
$lang['Webnic.!error.environment.valid'] = 'Please select a valid WebNIC environment.';
$lang['Webnic.!error.DOM2400'] = 'The domain name failed WebNIC validation.';
$lang['Webnic.!error.unknown'] = 'WebNIC returned an unknown response.';
$lang['Webnic.!error.auth_failed'] = 'WebNIC authentication failed. Check the account credentials and source IP allowlist.';
$lang['Webnic.!error.tlds_unavailable'] = 'WebNIC could not return the domain extension catalogue. The last known catalogue is used if available; otherwise no extensions are listed until the connection recovers.';
$lang['Webnic.!error.pricing_unavailable'] = 'WebNIC could not return domain pricing. Existing TLD prices are left unchanged; pricing is retried automatically once the connection recovers.';
$lang['Webnic.!error.temporarily_unavailable'] = 'This domain\'s availability could not be checked right now. Please try again.';
$lang['Webnic.!error.transfer_temporarily_unavailable'] = 'This domain\'s transfer eligibility could not be checked right now. Please try again.';

// Registration saga errors (Story 3.2). Surfaced via Input only on a failed
// addService — NEVER for an async-pending order (INV-4). The terminal error_class_map
// subcodes (DOM2400/DOM4000) reuse the keyed messages above/below.
$lang['Webnic.!error.DOM4000'] = 'WebNIC rejected one or more contact details. Please review the registrant information and try again.';
$lang['Webnic.!error.register_failed'] = 'The domain registration could not be completed right now. Please try again shortly.';
$lang['Webnic.!error.register_malformed'] = 'WebNIC returned an unexpected registration response. The domain was not registered; please try again.';
$lang['Webnic.!error.register_host_unavailable'] = 'The nameservers for this domain could not be prepared with WebNIC. Check the nameservers and try again.';
$lang['Webnic.!error.register_context_invalid'] = 'The registration could not be prepared because required account details are missing.';
$lang['Webnic.!error.register_already_registered'] = 'This domain appears to be already registered. Please contact support to finish activating it.';
$lang['Webnic.!error.register_reconcile_indeterminate'] = 'The registration status for this domain could not be confirmed. Please try again shortly.';
$lang['Webnic.!error.register_in_flight_unresolved'] = 'A previous registration attempt for this domain is still being resolved. Please try again shortly.';
$lang['Webnic.!error.whois_privacy_toggle_failed'] = 'The domain was registered, but enabling WHOIS privacy did not complete. The stored ID-protection choice remains available for retry or reconciliation replay.';
// addService NOT_REVIVED guard (WN-4-1 T7/AC8): a recovery retry whose order was resolved/
// cancelled concurrently must not be re-seeded.
$lang['Webnic.!error.register_already_resolved'] = 'This order was already resolved or cancelled and can no longer be retried. Reload the service to see its current status.';

// Transfer-in (WN-4-1, FR20/FR21). Working strings; final microcopy is Story 6.1. These are
// surfaced via Input BEFORE/at submit; a transfer that is accepted is a SUCCESS, never an Input
// error (INV-4) — these keys cover only the definitive-failure and gate paths.
$lang['Webnic.!error.transfer_failed'] = 'The domain transfer could not be started right now. Check the authorization (EPP) code and try again shortly.';
$lang['Webnic.!error.transfer_context_invalid'] = 'The transfer could not be prepared because required account details are missing.';
$lang['Webnic.!error.transfer_already_in_progress'] = 'A transfer for this domain is already in progress. No new transfer was started; watch the order status for the result.';
$lang['Webnic.!error.transfer_already_resolved'] = 'This transfer order was already resolved or cancelled and can no longer be retried. Reload the service to see its current status.';
$lang['Webnic.!error.transfer_reconcile_indeterminate'] = 'The transfer status for this domain could not be confirmed. Please try again shortly.';
// WN-4-1 round-1 P1: a non-claimable interior transfer row (a prior attempt crashed before submit)
// that the registry can no longer resolve — surfaced for recovery, never silently stranded pending.
$lang['Webnic.!error.transfer_in_flight_unresolved'] = 'A previous transfer attempt for this domain is still being resolved. Please try again shortly.';
// WN-4-1 round-1 P3: a retried terminal order whose transfer already COMPLETED out-of-band — never
// re-submitted; surfaced to support to finish activating (the reconciler owns activation, not retry).
$lang['Webnic.!error.transfer_already_active'] = 'This domain has already transferred in. Please contact support to finish activating it.';
// WN-4-1 round-1 P8: the EPP/auth code must be PRESENT before any order/API write. This validates
// presence only — a wrong-but-present code is still a registry reject at submit (AC7), not a form rule.
$lang['Webnic.!error.transfer_auth_code_required'] = 'Enter the authorization (EPP) code for this domain to start the transfer.';

// Resend verification email (WN-4-2, FR22/FR41). A stateless side-action — never a state change
// (NFR5). resend_failed = terminal (domain not found / registry reject); resend_unavailable =
// retryable/indeterminate ("try again"); resend_row_unavailable = the service's module row could
// not be resolved. Working strings; final microcopy is Story 6.1.
$lang['Webnic.!error.resend_failed'] = 'The verification email could not be resent for this domain. Please reload the service and try again, or contact support if it persists.';
$lang['Webnic.!error.resend_unavailable'] = 'The verification email could not be resent right now — WebNIC was temporarily unavailable. Please try again shortly.';
$lang['Webnic.!error.resend_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the verification email was not resent. Please reload and try again.';

// Renew a domain (WN-4-3, FR23/FR39d/FR41). A synchronous side-action — never a state change (NFR5).
// renew_blocked_pending = the order is not yet active (FR39d: block, never queue — core auto-retries
// once it activates); renew_blocked_terminal = a failed/cancelled order can't be renewed as-is;
// renew_unavailable = retryable/indeterminate ("try again"); renew_failed = a terminal business
// error (not-found / invalid / registry reject); renew_row_unavailable = the service's module row
// could not be resolved. Working strings; final microcopy is Story 6.1 (en_us mandatory, FR42/NFR10).
$lang['Webnic.!error.renew_blocked_pending'] = 'This domain can be renewed once it is active.';
$lang['Webnic.!error.renew_blocked_terminal'] = 'This domain can\'t be renewed in its current state. Please contact support.';
$lang['Webnic.!error.renew_unavailable'] = 'The domain could not be renewed right now — WebNIC was temporarily unavailable. Please try again shortly.';
$lang['Webnic.!error.renew_failed'] = 'The domain renewal could not be completed. Please reload the service and try again, or contact support if it persists.';
$lang['Webnic.!error.renew_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the domain was not renewed. Please reload and try again.';
$lang['Webnic.!error.renew_domain_missing'] = 'No domain is on file for this service, so it could not be renewed. Please contact support.';
$lang['Webnic.renew.ok'] = 'Domain renewed.';
$lang['Webnic.renew.already'] = 'This domain is already renewed for the requested term.';

// Restore a domain (WN-4-4, FR24). Restore is billable, so the client surface is
// guidance-only until a paid restore caller exists; admin mutation requires a fee acknowledgement.
$lang['Webnic.!error.restore_blocked_pending'] = 'This domain can be restored once its current WebNIC order is finished.';
$lang['Webnic.!error.restore_blocked_terminal'] = 'This domain can\'t be restored in its current state. Please contact support.';
$lang['Webnic.!error.restore_unavailable'] = 'The domain restore could not be checked right now. Please try again shortly.';
$lang['Webnic.!error.restore_failed'] = 'The domain restore could not be completed. Please reload the service and try again, or contact support if it persists.';
$lang['Webnic.!error.restore_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the domain was not restored. Please reload and try again.';
$lang['Webnic.!error.restore_domain_missing'] = 'No domain is on file for this service, so it could not be restored. Please contact support.';
$lang['Webnic.!error.restore_not_in_grace'] = 'This domain is not in a registry grace or redemption status that can be restored.';
$lang['Webnic.!error.restore_grace_rule_unavailable'] = 'The registry restore rule could not be confirmed. Please try again shortly.';
$lang['Webnic.!error.restore_grace_rule_denied'] = 'The registry restore rule does not allow restoration for this domain.';
$lang['Webnic.!error.restore_grace_window_expired'] = 'The registry restore window appears to have expired.';
$lang['Webnic.!error.restore_price_unavailable'] = 'The restore price could not be confirmed, so restoration is temporarily unavailable.';
$lang['Webnic.restore.ok'] = 'Domain restore accepted.';
$lang['Webnic.restore.pending'] = 'Domain restore accepted and pending at the registry.';
$lang['Webnic.restore_prompt.heading'] = 'Restore available';
$lang['Webnic.restore_prompt.client_guidance'] = 'This domain is in grace/redemption. Restoration has a registry fee; contact support to approve and process the restore.';

// Suspend / unsuspend a domain (WN-4-5, FR25/FR39d). T0 OTE capture chose Path B:
// local-only Blesta status changes, no registry write. The error keys still enforce
// active-only lifecycle gating and row/domain safety before Blesta flips local status.
// The *_failed keys are reserved for a future Path A wire classifier; Path B currently
// emits the more specific row/domain/unavailable keys.
$lang['Webnic.!error.suspend_blocked_pending'] = 'This domain can be suspended once it is active.';
$lang['Webnic.!error.suspend_blocked_terminal'] = 'This domain can\'t be suspended in its current state. Please contact support.';
$lang['Webnic.!error.suspend_unavailable'] = 'The domain suspension could not be checked right now. Please try again shortly.';
$lang['Webnic.!error.suspend_failed'] = 'The domain suspension could not be completed. Please contact support if it persists.';
$lang['Webnic.!error.suspend_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the domain was not suspended. Please reload and try again.';
$lang['Webnic.!error.suspend_domain_missing'] = 'No domain is on file for this service, so it could not be suspended. Please contact support.';
$lang['Webnic.!error.unsuspend_blocked_pending'] = 'This domain can be unsuspended once it is active.';
$lang['Webnic.!error.unsuspend_blocked_terminal'] = 'This domain can\'t be unsuspended in its current state. Please contact support.';
$lang['Webnic.!error.unsuspend_unavailable'] = 'The domain unsuspension could not be checked right now. Please try again shortly.';
$lang['Webnic.!error.unsuspend_failed'] = 'The domain unsuspension could not be completed. Please contact support if it persists.';
$lang['Webnic.!error.unsuspend_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the domain was not unsuspended. Please reload and try again.';
$lang['Webnic.!error.unsuspend_domain_missing'] = 'No domain is on file for this service, so it could not be unsuspended. Please contact support.';
$lang['Webnic.suspend.ok'] = 'Domain marked suspended locally.';
$lang['Webnic.unsuspend.ok'] = 'Domain marked active locally.';

// WHOIS/contact management (WN-5-2, FR28/FR29). Contact data is PII, so errors
// are normalized and never include raw provider payloads.
$lang['Webnic.!error.contact_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so contacts could not be managed. Please reload and try again.';
$lang['Webnic.!error.contact_domain_missing'] = 'No domain is on file for this service, so contacts could not be managed. Please contact support.';
$lang['Webnic.!error.contact_unavailable'] = 'Contact details are temporarily unavailable. Please reload and try again.';
$lang['Webnic.!error.contact_not_manageable'] = 'Contacts can be edited once this domain is active and manageable at WebNIC.';
$lang['Webnic.!error.contact_id_mismatch'] = 'The submitted contact no longer matches the current domain contacts. Reload and try again.';
$lang['Webnic.!error.contact_role_invalid'] = 'The submitted contact role is not valid.';
$lang['Webnic.!error.contact_required'] = 'Complete the required contact fields.';
$lang['Webnic.!error.contact_email_invalid'] = 'Enter a valid contact email address.';
$lang['Webnic.!error.contact_phone_invalid'] = 'Enter the phone number in +CC.number format.';
$lang['Webnic.!error.contact_country_invalid'] = 'Enter a two-letter country code.';
$lang['Webnic.!error.contact_shared_conflict'] = 'This contact is shared by multiple roles. Submit the same values for shared roles, or reload and edit one shared contact at a time.';
$lang['Webnic.!error.contact_update_failed'] = 'The contact update could not be completed. Please reload and try again, or contact support if it persists.';
$lang['Webnic.!error.contact_update_unavailable'] = 'The contact update could not be completed right now because WebNIC was temporarily unavailable. Please try again shortly.';
$lang['Webnic.contact_update.ok'] = 'Contact details updated.';
$lang['Webnic.contact_update.pending'] = 'Contact details accepted by WebNIC and pending registry processing.';

// Nameserver management (WN-5-3, FR30). Nameservers are public hostnames, but
// provider failures are still normalized so raw WebNIC payloads never reach users.
$lang['Webnic.!error.nameserver_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so nameservers could not be managed. Please reload and try again.';
$lang['Webnic.!error.nameserver_domain_missing'] = 'No domain is on file for this service, so nameservers could not be managed. Please contact support.';
$lang['Webnic.!error.nameserver_unavailable'] = 'Nameservers are temporarily unavailable. Please reload and try again.';
$lang['Webnic.!error.nameserver_not_manageable'] = 'Nameservers can be edited once this domain is active and manageable at WebNIC.';
$lang['Webnic.!error.nameserver_update_failed'] = 'The nameserver update could not be completed. Check the nameservers and try again, or contact support if it persists.';
$lang['Webnic.!error.nameserver_update_unavailable'] = 'The nameserver update could not be completed right now because WebNIC was temporarily unavailable. Please try again shortly.';
$lang['Webnic.!error.nameserver_invalid'] = 'Enter each nameserver as text.';
$lang['Webnic.nameserver_update.ok'] = 'Nameservers updated.';

// Cancel / guarded registry delete (WN-4-6, FR26/AC3). The core billing cancel is a
// no-delete no-op; these keys cover only deliberate admin registry deletion.
$lang['Webnic.!error.cancel_blocked_pending'] = 'This domain can be deleted from the registry once its current WebNIC order is finished.';
$lang['Webnic.!error.cancel_blocked_grace'] = 'This domain is in a registry deletion or grace window, so registry deletion is blocked.';
$lang['Webnic.!error.cancel_unavailable'] = 'The registry deletion could not be checked right now. Please try again shortly.';
$lang['Webnic.!error.cancel_failed'] = 'The registry deletion could not be completed. Please reload the service and try again, or contact support if it persists.';
$lang['Webnic.!error.cancel_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so the domain was not deleted. Please reload and try again.';
$lang['Webnic.!error.cancel_domain_missing'] = 'No domain is on file for this service, so it could not be deleted. Please contact support.';
$lang['Webnic.!error.cancel_non_refundable'] = 'This registry deletion is non-refundable.';
$lang['Webnic.cancel.ok'] = 'Domain deletion accepted.';
$lang['Webnic.delete.already'] = 'This domain is already deleted at the registry.';

// Connectivity test (Story 1.5). %1$s placeholders are filled from safe scalars
// only (environment label, server IP, integer HTTP status) — never provider text,
// the API secret, or the JWT.
$lang['Webnic.!success.connectivity'] = 'WebNIC connection verified (%1$s).';
$lang['Webnic.!error.connectivity_ip_allowlist'] = 'Could not reach WebNIC — the connection was refused or timed out. If the API username and secret are correct, this server\'s outbound IP (%1$s) is most likely not allowlisted in your WebNIC account. Add it to the WebNIC API IP allowlist and try again.';
$lang['Webnic.!error.connectivity_ip_allowlist_no_ip'] = 'Could not reach WebNIC — the connection was refused or timed out. If the API username and secret are correct, this server\'s outbound IP is most likely not allowlisted in your WebNIC account. Add it to the WebNIC API IP allowlist and try again.';
$lang['Webnic.!error.connectivity_invalid_credential'] = 'WebNIC rejected the connection. Check the API username and secret, confirm they match the selected environment (OTE vs Production), and that this server\'s IP is allowlisted.';
$lang['Webnic.!error.connectivity_failure'] = 'The WebNIC connectivity test failed (status %1$s). Verify the account settings and try again.';
$lang['Webnic.!error.connectivity_failure_generic'] = 'The WebNIC connectivity test failed. Verify the account settings and try again.';

// Status lexicon (Story 1.6). The five lifecycle alias labels rendered by the
// shared status-badge use icon + text + color, never color alone. `pending`
// reads as in-progress, never as active/done (FR39c).
$lang['Webnic.status.active'] = 'Active';
$lang['Webnic.status.pending'] = 'In progress';
$lang['Webnic.status.failed'] = 'Failed';
$lang['Webnic.status.cancelled'] = 'Cancelled';
$lang['Webnic.status.suspended'] = 'Suspended';

// Service-info lifecycle UI (Story 3.4a). Pending banner copy is bound by
// DESIGN/EXPERIENCE: a reassurance window, never a countdown or ETA. The softened
// copy appears only after Webnic.pending_banner_soften_after (default 1h).
$lang['Webnic.service_info.pending_banner'] = 'Registration in progress. This usually completes within a few minutes.';
$lang['Webnic.service_info.pending_banner_softened'] = 'Taking longer than usual — still queued, no action needed.';
// Transfer-pending banner (WN-4-1 AC4 / decision #8 / EXPERIENCE.md Flow 2). A days-horizon
// narrative — NEVER reuse the register "few minutes" copy. The "we'll email you" clause is the
// AC5/decision #15 promise fulfilled when the transfer reaches active.
$lang['Webnic.service_info.transfer_pending_banner'] = 'Transfer in progress — awaiting release from your current registrar; we\'ll email you when it completes.';
// Inline reason shown when a lifecycle control is blocked while pending (UX-DR11.2).
// Epic 4/5 tabs render tab_unavailable.pdt carrying this reason instead of controls.
$lang['Webnic.service_info.lifecycle_blocked'] = 'Available once your domain is active.';
$lang['Webnic.service_info.suspended_reason'] = 'This domain is locally suspended in Blesta. An administrator must unsuspend it before management actions resume.';

// Active-domain summary (WN-5-1, FR36/AC1/AC5). The single-live-read summary shown on the
// service page once a domain is active (registration/expiry dates, lock + WHOIS-privacy pills,
// nameservers). summary_unavailable is the AC5 loading/error note shown when the live read fails
// (never a blank, never a fatal). Working strings; final microcopy is Story 6.1.
$lang['Webnic.service_info.summary_heading'] = 'Domain summary';
$lang['Webnic.service_info.summary_registered_on'] = 'Registered';
$lang['Webnic.service_info.summary_expires_on'] = 'Expires';
$lang['Webnic.service_info.summary_locked'] = 'Registrar lock on';
$lang['Webnic.service_info.summary_unlocked'] = 'Registrar lock off';
$lang['Webnic.service_info.summary_privacy_on'] = 'WHOIS privacy on';
$lang['Webnic.service_info.summary_privacy_off'] = 'WHOIS privacy off';
$lang['Webnic.service_info.summary_nameservers'] = 'Nameservers';
$lang['Webnic.service_info.summary_empty'] = '—';
$lang['Webnic.service_info.summary_unavailable'] = 'Domain details are temporarily unavailable. Please reload in a moment.';

// Failed-order callout (Story 3.4a, AC4). Client variant is a dead-end support
// callout with NO retry control; admin variant adds the masked tracking record and
// a recovery action that links to the admin Recovery tab (the POST surface).
$lang['Webnic.failed_order.heading'] = 'Couldn\'t complete registration';
$lang['Webnic.failed_order.client_body'] = 'Our team has been notified — please contact support to get this resolved.';
$lang['Webnic.failed_order.tracking_title'] = 'Order tracking';
$lang['Webnic.failed_order.field.id'] = 'Order';
$lang['Webnic.failed_order.field.state'] = 'State';
$lang['Webnic.failed_order.field.attempts'] = 'Attempts';
$lang['Webnic.failed_order.field.created'] = 'Created';
$lang['Webnic.failed_order.field.last_polled'] = 'Last checked';
$lang['Webnic.failed_order.field.give_up_at'] = 'Give-up deadline';
$lang['Webnic.failed_order.recover_btn'] = 'Recover this order';
$lang['Webnic.failed_order.recover_hint'] = 'Use the Recovery tab to retry, finalise, or mark this order resolved.';
$lang['Webnic.failed_order.empty'] = '—';

// Admin Recovery tab (Story 3.4a, AC4/AC5/AC6).
$lang['Webnic.tab_recovery.title'] = 'Recovery';
$lang['Webnic.tab_recovery.heading'] = 'Failed-order recovery';
$lang['Webnic.tab_recovery.no_order'] = 'No WebNIC order is on file for this domain.';
$lang['Webnic.tab_recovery.actions_title'] = 'Recovery actions';
$lang['Webnic.tab_recovery.actions_help'] = 'Retry re-runs registration (reconciling first so a domain already registered is never charged twice). Finalise activates an order whose domain is confirmed registered at the registry. Resolve marks a dead order acknowledged.';
$lang['Webnic.tab_recovery.btn_retry'] = 'Retry registration';
$lang['Webnic.tab_recovery.btn_finalise'] = 'Finalise (already registered)';
$lang['Webnic.tab_recovery.btn_resolve'] = 'Mark resolved';
$lang['Webnic.tab_recovery.trace_title'] = 'Saga & reconcile trace';
$lang['Webnic.tab_recovery.trace_empty'] = 'No correlated log records found for this domain.';
$lang['Webnic.tab_recovery.load_error'] = 'The recovery view could not be loaded. The error has been logged — please reload, and contact an administrator if it persists.';
$lang['Webnic.tab_recovery.trace.when'] = 'When';
$lang['Webnic.tab_recovery.trace.command'] = 'Step';
$lang['Webnic.tab_recovery.trace.transition'] = 'Transition';
$lang['Webnic.tab_recovery.trace.class'] = 'Class';
$lang['Webnic.tab_recovery.trace.message'] = 'Detail';

// Recovery action result messages (Story 3.4a, AC5). Surfaced via setMessage.
$lang['Webnic.recover.resolve_ok'] = 'The failed order was marked resolved and cancelled.';
$lang['Webnic.recover.resolve_failed'] = 'The order could not be marked resolved. It may have already changed state — reload and try again.';
$lang['Webnic.recover.finalise_ok'] = 'The domain is confirmed registered; the order was finalised and is now active.';
$lang['Webnic.recover.finalise_not_registered'] = 'WebNIC does not yet show this domain as registered. Use Retry to attempt registration.';
$lang['Webnic.recover.finalise_not_active'] = 'WebNIC shows this domain registered but not active. Recheck the registry status before finalising.';
$lang['Webnic.recover.finalise_unavailable'] = 'Could not reach WebNIC to confirm the registry status. Please try again shortly.';
$lang['Webnic.recover.finalise_failed'] = 'The order could not be finalised. It may have changed state — reload and try again.';
$lang['Webnic.recover.retry_ok'] = 'Registration was re-submitted. Watch the order status for the result.';
$lang['Webnic.recover.retry_failed'] = 'The registration retry could not be completed. If WebNIC already shows this domain registered, use Finalise instead; otherwise review the trace below and try again shortly.';
$lang['Webnic.recover.not_failed'] = 'There is no failed order to recover for this domain.';
$lang['Webnic.recover.unknown_action'] = 'Unknown recovery action.';

// Resend verification email tab + action results (WN-4-2, FR22 / EXPERIENCE.md Flow 2). The ONE
// allowed lifecycle action while a transfer/registration is awaiting verification (decision #8) —
// inline single-click, no confirm modal. Offered on BOTH the client and admin service surfaces.
// resend.ok is the AC1 literal copy. Working strings; final microcopy is Story 6.1.
$lang['Webnic.tab_resend.title'] = 'Resend transfer email';
$lang['Webnic.tab_resend.heading'] = 'Resend transfer email';
$lang['Webnic.tab_resend.help'] = 'Your domain is awaiting verification. If the verification email never arrived, you can resend it below.';
$lang['Webnic.tab_resend.btn'] = 'Resend transfer email';
$lang['Webnic.tab_resend.load_error'] = 'The resend view could not be loaded. The error has been logged — please reload, and contact an administrator if it persists.';

// Admin Delete Domain tab (WN-4-6, FR26/UX-DR9). Admin-only, confirm-modal guarded.
$lang['Webnic.tab_delete.title'] = 'Delete Domain';
$lang['Webnic.tab_delete.heading'] = 'Delete Domain';
$lang['Webnic.tab_delete.warning'] = 'This permanently deletes the domain at the registry. The fee is not refunded.';
$lang['Webnic.tab_delete.confirm'] = 'Delete this domain at the registry? This action is irreversible and non-refundable.';
$lang['Webnic.tab_delete.btn'] = 'Delete Domain';
$lang['Webnic.tab_delete.unavailable'] = 'Registry deletion is not available for this service state.';
$lang['Webnic.tab_delete.load_error'] = 'The delete view could not be loaded. The error has been logged — please reload, and contact an administrator if it persists.';
$lang['Webnic.tab_restore.title'] = 'Restore Domain';
$lang['Webnic.tab_restore.heading'] = 'Restore Domain';
$lang['Webnic.tab_restore.warning'] = 'This restores the domain at the registry and may incur the displayed restoration fee.';
$lang['Webnic.tab_restore.help'] = 'Confirm the customer has approved the restore fee before submitting.';
$lang['Webnic.tab_restore.ack'] = 'I confirm the restore fee has been approved.';
$lang['Webnic.tab_restore.ack_required'] = 'Confirm the restore fee approval before submitting.';
$lang['Webnic.tab_restore.btn'] = 'Restore Domain';
$lang['Webnic.tab_restore.unavailable'] = 'Domain restore is not available for this service state.';
$lang['Webnic.tab_restore.load_error'] = 'The restore view could not be loaded. The error has been logged — please reload, and contact an administrator if it persists.';
$lang['Webnic.resend.ok'] = 'Transfer email resent.';
$lang['Webnic.resend.already'] = 'This domain\'s verification email was already confirmed — no action needed.';

// The 7 LogicBoxes-parity management-tab titles (WN-5-1, AC3/UX-DR15). Declared here NOW
// so the array-driven tab registry resolves a label even before Stories 5.2-5.8 ship the
// tab methods (the slots stay method-gated, so no dead link renders until then). Same
// canonical order on both the admin and client strips. Working strings; final copy 6.1.
$lang['Webnic.tab_whois.title'] = 'Contacts';
$lang['Webnic.tab_whois.heading'] = 'WHOIS contacts';
$lang['Webnic.tab_whois.help'] = 'Edit the contact details currently attached to this active domain. If the same WebNIC contact is shared by multiple roles, changes can affect every role using that contact.';
$lang['Webnic.tab_whois.unavailable'] = 'WHOIS contacts are available once this domain is active and manageable.';
$lang['Webnic.tab_whois.read_only'] = 'Read-only';
$lang['Webnic.tab_whois.shared_contact'] = 'Shared WebNIC contact %1$s is used by %2$s roles. Saving changes can affect every role and any domain using this handle.';
$lang['Webnic.tab_whois.save'] = 'Save contacts';
$lang['Webnic.tab_whois.role.registrant'] = 'Registrant';
$lang['Webnic.tab_whois.role.admin'] = 'Admin';
$lang['Webnic.tab_whois.role.tech'] = 'Technical';
$lang['Webnic.tab_whois.role.billing'] = 'Billing';
$lang['Webnic.tab_whois.field.first_name'] = 'First name';
$lang['Webnic.tab_whois.field.last_name'] = 'Last name';
$lang['Webnic.tab_whois.field.organization'] = 'Organization';
$lang['Webnic.tab_whois.field.address1'] = 'Address 1';
$lang['Webnic.tab_whois.field.address2'] = 'Address 2';
$lang['Webnic.tab_whois.field.city'] = 'City';
$lang['Webnic.tab_whois.field.state'] = 'State';
$lang['Webnic.tab_whois.field.zip'] = 'Postal code';
$lang['Webnic.tab_whois.field.country'] = 'Country';
$lang['Webnic.tab_whois.field.phone'] = 'Phone';
$lang['Webnic.tab_whois.field.email'] = 'Email';
$lang['Webnic.tab_nameservers.title'] = 'Nameservers';
$lang['Webnic.tab_nameservers.heading'] = 'Nameservers';
$lang['Webnic.tab_nameservers.help'] = 'Set the authoritative nameservers for this domain. Leave all fields blank to use WebNIC defaults, or enter at least two custom nameservers.';
$lang['Webnic.tab_nameservers.unavailable'] = 'Nameservers are available once this domain is active and manageable.';
$lang['Webnic.tab_nameservers.load_error'] = 'Nameservers could not be loaded from WebNIC. Reload before making changes.';
$lang['Webnic.tab_nameservers.too_many'] = 'WebNIC returned more nameservers than this form can edit. Contact support before making changes.';
$lang['Webnic.tab_nameservers.empty'] = 'No nameservers were returned by WebNIC, so the WebNIC default nameservers are pre-filled.';
$lang['Webnic.tab_nameservers.save'] = 'Save nameservers';
$lang['Webnic.tab_nameservers.update_ok'] = 'Nameservers updated.';
$lang['Webnic.tab_nameservers.field.ns'] = 'Nameserver %1$s';
$lang['Webnic.tab_nameservers.placeholder.ns'] = 'ns%1$s.example.com';
$lang['Webnic.tab_childnameservers.title'] = 'Child Nameservers';
$lang['Webnic.tab_dnsrecords.title'] = 'DNS Records';
$lang['Webnic.tab_dnsrecords.heading'] = 'DNS Records';
$lang['Webnic.tab_dnsrecords.help'] = 'Manage the basic WebNIC DNS records for this domain.';
$lang['Webnic.tab_dnsrecords.unavailable'] = 'DNS records are available once this domain is active and manageable.';
$lang['Webnic.tab_dnsrecords.load_error'] = 'DNS records could not be loaded from WebNIC. Reload before making changes.';
$lang['Webnic.tab_dnsrecords.refresh_error'] = 'DNS record saved. Reload to refresh the record list.';
$lang['Webnic.tab_dnsrecords.not_webnic_dns'] = 'DNS records require this domain to use WebNIC basic DNS.';
$lang['Webnic.tab_dnsrecords.no_supported_types'] = 'No editable basic DNS record types are currently supported by WebNIC.';
$lang['Webnic.tab_dnsrecords.empty'] = 'No DNS records yet. Add your first record.';
$lang['Webnic.tab_dnsrecords.unsupported'] = 'Some provider records are read-only in this tab.';
$lang['Webnic.tab_dnsrecords.read_only_type'] = 'This record type is outside the basic DNS records scope.';
$lang['Webnic.tab_dnsrecords.read_only_multi_value'] = 'This multi-value provider record is read-only in this tab.';
$lang['Webnic.tab_dnsrecords.read_only_invalid_value'] = 'This provider record has an empty or unsupported value shape and is read-only in this tab.';
$lang['Webnic.tab_dnsrecords.add'] = 'Add record';
$lang['Webnic.tab_dnsrecords.edit'] = 'Save record';
$lang['Webnic.tab_dnsrecords.delete'] = 'Delete';
$lang['Webnic.tab_dnsrecords.save'] = 'Save DNS record';
$lang['Webnic.tab_dnsrecords.update_ok'] = 'DNS record saved.';
$lang['Webnic.tab_dnsrecords.record_deleted'] = 'DNS record deleted.';
$lang['Webnic.tab_dnsrecords.column.type'] = 'Type';
$lang['Webnic.tab_dnsrecords.column.name'] = 'Name';
$lang['Webnic.tab_dnsrecords.column.value'] = 'Value';
$lang['Webnic.tab_dnsrecords.column.ttl'] = 'TTL';
$lang['Webnic.tab_dnsrecords.column.priority'] = 'Priority';
$lang['Webnic.tab_dnsrecords.column.weight'] = 'Weight';
$lang['Webnic.tab_dnsrecords.column.port'] = 'Port';
$lang['Webnic.tab_dnsrecords.column.actions'] = 'Actions';
$lang['Webnic.tab_dnsrecords.column.reason'] = 'Reason';
$lang['Webnic.tab_dnsrecords.field.type'] = 'Type';
$lang['Webnic.tab_dnsrecords.field.name'] = 'Name';
$lang['Webnic.tab_dnsrecords.field.value'] = 'Value';
$lang['Webnic.tab_dnsrecords.field.ttl'] = 'TTL';
$lang['Webnic.tab_dnsrecords.field.priority'] = 'Priority';
$lang['Webnic.tab_dnsrecords.field.weight'] = 'Weight';
$lang['Webnic.tab_dnsrecords.field.port'] = 'Port';
$lang['Webnic.tab_dnsrecords.placeholder.name'] = '@ or www';
$lang['Webnic.tab_dnsrecords.placeholder.value'] = 'Record value';
$lang['Webnic.tab_dnsrecords.placeholder.ttl'] = '3600';
$lang['Webnic.tab_dnsrecords.placeholder.priority'] = '10';
$lang['Webnic.tab_dnsrecords.placeholder.weight'] = '5';
$lang['Webnic.tab_dnsrecords.placeholder.port'] = '5060';
$lang['Webnic.tab_dnsrecords.type.A'] = 'A';
$lang['Webnic.tab_dnsrecords.type.AAAA'] = 'AAAA';
$lang['Webnic.tab_dnsrecords.type.CNAME'] = 'CNAME';
$lang['Webnic.tab_dnsrecords.type.MX'] = 'MX';
$lang['Webnic.tab_dnsrecords.type.SRV'] = 'SRV';
$lang['Webnic.tab_dnsrecords.type.TXT'] = 'TXT';
$lang['Webnic.tab_forwarding.title'] = 'Forwarding';
$lang['Webnic.tab_forwarding.heading'] = 'Forwarding';
$lang['Webnic.tab_forwarding.help'] = 'Manage WebNIC URL and email forwarding for this domain.';
$lang['Webnic.tab_forwarding.unavailable'] = 'Forwarding is available once this domain is active and manageable.';
$lang['Webnic.tab_forwarding.load_error'] = 'Forwarding could not be loaded from WebNIC. Reload before making changes.';
$lang['Webnic.tab_forwarding.refresh_error'] = 'Forwarding updated. Reload to refresh the forwarding list.';
$lang['Webnic.tab_forwarding.not_webnic_dns'] = 'Forwarding requires this domain to use WebNIC basic DNS.';
$lang['Webnic.tab_forwarding.empty'] = 'No forwarding rules yet.';
$lang['Webnic.tab_forwarding.url_section'] = 'URL forwards';
$lang['Webnic.tab_forwarding.email_section'] = 'Email forwards';
$lang['Webnic.tab_forwarding.email_mx_side_effect'] = 'No automatic MX record was observed during OTE capture. Confirm this zone\'s MX routing before relying on email forwarding.';
$lang['Webnic.tab_forwarding.add_url'] = 'Add URL forward';
$lang['Webnic.tab_forwarding.add_email'] = 'Add email forward';
$lang['Webnic.tab_forwarding.delete'] = 'Delete';
$lang['Webnic.tab_forwarding.update_ok'] = 'Forwarding updated.';
$lang['Webnic.tab_forwarding.record_deleted'] = 'Forwarding rule deleted.';
$lang['Webnic.tab_forwarding.column.url_subdomain'] = 'Subdomain';
$lang['Webnic.tab_forwarding.column.url_target'] = 'Target URL';
$lang['Webnic.tab_forwarding.column.email_source'] = 'Source';
$lang['Webnic.tab_forwarding.column.email_destination'] = 'Destination';
$lang['Webnic.tab_forwarding.column.status'] = 'Status';
$lang['Webnic.tab_forwarding.column.added'] = 'Added';
$lang['Webnic.tab_forwarding.column.modified'] = 'Modified';
$lang['Webnic.tab_forwarding.column.actions'] = 'Actions';
$lang['Webnic.tab_forwarding.field.url_subdomain'] = 'Subdomain';
$lang['Webnic.tab_forwarding.field.url_target'] = 'Target URL';
$lang['Webnic.tab_forwarding.field.email_source'] = 'Source local-part';
$lang['Webnic.tab_forwarding.field.email_destination'] = 'Destination email';
$lang['Webnic.tab_forwarding.placeholder.url_subdomain'] = '@, www, or shop.eu';
$lang['Webnic.tab_forwarding.placeholder.url_target'] = 'https://example.com';
$lang['Webnic.tab_forwarding.placeholder.email_source'] = 'sales';
$lang['Webnic.tab_forwarding.placeholder.email_destination'] = 'destination@example.com';
$lang['Webnic.tab_dnssec.title'] = 'DNSSEC';
$lang['Webnic.tab_dnssec.heading'] = 'DNSSEC';
$lang['Webnic.tab_dnssec.help'] = 'Manage the registrar DS records that secure this domain with DNSSEC.';
$lang['Webnic.tab_dnssec.unavailable'] = 'DNSSEC is available once this domain is active and manageable.';
$lang['Webnic.tab_dnssec.load_error'] = 'DNSSEC could not be loaded from WebNIC. Reload before making changes.';
$lang['Webnic.tab_dnssec.refresh_error'] = 'DNSSEC change saved. Reload to refresh the DS record list.';
$lang['Webnic.tab_dnssec.unsupported'] = 'DNSSEC is not supported for this domain.';
$lang['Webnic.tab_dnssec.unsupported_reason'] = 'The registry for this TLD does not support registrar DNSSEC, so there is nothing to configure here.';
$lang['Webnic.tab_dnssec.empty'] = 'No DS records yet. Add your first record.';
$lang['Webnic.tab_dnssec.add'] = 'Add DS record';
$lang['Webnic.tab_dnssec.remove'] = 'Remove';
$lang['Webnic.tab_dnssec.save'] = 'Save DS record';
$lang['Webnic.tab_dnssec.update_ok'] = 'DS record added.';
$lang['Webnic.tab_dnssec.record_removed'] = 'DS record removed.';
$lang['Webnic.tab_dnssec.confirm_remove'] = 'Remove this DS record? Removing the wrong record can break DNSSEC validation for this domain.';
$lang['Webnic.tab_dnssec.column.keytag'] = 'Key Tag';
$lang['Webnic.tab_dnssec.column.algorithm'] = 'Algorithm';
$lang['Webnic.tab_dnssec.column.digesttype'] = 'Digest Type';
$lang['Webnic.tab_dnssec.column.digest'] = 'Digest';
$lang['Webnic.tab_dnssec.column.actions'] = 'Actions';
$lang['Webnic.tab_dnssec.field.keytag'] = 'Key Tag';
$lang['Webnic.tab_dnssec.field.algorithm'] = 'Algorithm';
$lang['Webnic.tab_dnssec.field.digesttype'] = 'Digest Type';
$lang['Webnic.tab_dnssec.field.digest'] = 'Digest';
$lang['Webnic.tab_dnssec.placeholder.keytag'] = '12345';
$lang['Webnic.tab_dnssec.placeholder.digest'] = 'Hex digest';
$lang['Webnic.tab_dnssec.algorithm.3'] = '3 - DSA/SHA-1';
$lang['Webnic.tab_dnssec.algorithm.5'] = '5 - RSA/SHA-1';
$lang['Webnic.tab_dnssec.algorithm.7'] = '7 - RSASHA1-NSEC3-SHA1';
$lang['Webnic.tab_dnssec.algorithm.8'] = '8 - RSA/SHA-256';
$lang['Webnic.tab_dnssec.algorithm.10'] = '10 - RSA/SHA-512';
$lang['Webnic.tab_dnssec.algorithm.12'] = '12 - GOST R 34.10-2001';
$lang['Webnic.tab_dnssec.algorithm.13'] = '13 - ECDSA Curve P-256 with SHA-256';
$lang['Webnic.tab_dnssec.algorithm.14'] = '14 - ECDSA Curve P-384 with SHA-384';
$lang['Webnic.tab_dnssec.algorithm.15'] = '15 - Ed25519';
$lang['Webnic.tab_dnssec.algorithm.16'] = '16 - Ed448';
$lang['Webnic.tab_dnssec.digesttype.1'] = '1 - SHA-1';
$lang['Webnic.tab_dnssec.digesttype.2'] = '2 - SHA-256';
$lang['Webnic.tab_dnssec.digesttype.3'] = '3 - GOST R 34.11-94';
$lang['Webnic.tab_dnssec.digesttype.4'] = '4 - SHA-384';
$lang['Webnic.tab_settings.title'] = 'Settings';
$lang['Webnic.tab_settings.heading'] = 'Settings';
$lang['Webnic.tab_settings.help'] = 'Manage registrar lock, authorization code email, WHOIS privacy, and renewal visibility for this domain.';
$lang['Webnic.tab_settings.unavailable'] = 'Settings are available once this domain is active and manageable.';
$lang['Webnic.tab_settings.load_error'] = 'Settings could not be loaded from WebNIC. Reload before making changes.';
$lang['Webnic.tab_settings.refresh_error'] = 'Settings updated. Reload to refresh the current state.';
$lang['Webnic.tab_settings.update_ok'] = 'Settings updated.';
$lang['Webnic.tab_settings.autorenew_label'] = 'Auto-renew';
$lang['Webnic.tab_settings.autorenew_on'] = 'Auto-renew on';
$lang['Webnic.tab_settings.autorenew_off'] = 'Auto-renew off';
$lang['Webnic.tab_settings.autorenew_sor_note'] = 'Renewals are managed by Blesta; WebNIC auto-renew is kept off to avoid duplicate renewals.';
$lang['Webnic.tab_settings.lock_heading'] = 'Registrar lock';
$lang['Webnic.tab_settings.lock_on'] = 'Registrar lock on';
$lang['Webnic.tab_settings.lock_off'] = 'Registrar lock off';
$lang['Webnic.tab_settings.lock_enable'] = 'Enable lock';
$lang['Webnic.tab_settings.lock_disable'] = 'Disable lock';
$lang['Webnic.tab_settings.unlock_confirm'] = 'Disable the registrar lock? This can allow the domain to be transferred away.';
$lang['Webnic.tab_settings.lock_on_ok'] = 'Registrar lock enabled.';
$lang['Webnic.tab_settings.lock_off_ok'] = 'Registrar lock disabled.';
$lang['Webnic.tab_settings.epp_heading'] = 'Authorization code';
$lang['Webnic.tab_settings.epp_send_label'] = 'The authorization code is emailed by WebNIC and is never displayed here.';
$lang['Webnic.tab_settings.epp_send'] = 'Send authorization code';
$lang['Webnic.tab_settings.epp_send_ok'] = 'Authorization code email sent.';
$lang['Webnic.tab_settings.epp_reset'] = 'Reset authorization code';
$lang['Webnic.tab_settings.reset_epp_confirm'] = 'Reset the authorization code? The previous code will stop working.';
$lang['Webnic.tab_settings.epp_reset_ok'] = 'Authorization code reset.';
$lang['Webnic.tab_settings.privacy_heading'] = 'WHOIS privacy';
$lang['Webnic.tab_settings.privacy_on'] = 'WHOIS privacy on';
$lang['Webnic.tab_settings.privacy_off'] = 'WHOIS privacy off';
$lang['Webnic.tab_settings.privacy_enable'] = 'Enable privacy';
$lang['Webnic.tab_settings.privacy_disable'] = 'Disable privacy';
$lang['Webnic.tab_settings.disable_privacy_confirm'] = 'Disable WHOIS privacy? Public WHOIS may show contact details where the registry permits it.';
$lang['Webnic.tab_settings.privacy_on_ok'] = 'WHOIS privacy enabled.';
$lang['Webnic.tab_settings.privacy_off_ok'] = 'WHOIS privacy disabled.';
$lang['Webnic.tab_settings.privacy_not_capable'] = 'WHOIS privacy is not available for this TLD.';
$lang['Webnic.tab_settings.privacy_capability_unavailable'] = 'WHOIS privacy capability could not be confirmed. Reload before changing privacy.';

// Package configuration fields (Story 3.4b, AC1/FR38). Working strings; final copy 6.1.
$lang['Webnic.package_fields.tld_options'] = 'Supported TLDs';
$lang['Webnic.package_fields.term'] = 'Default registration term (years)';
$lang['Webnic.package_fields.tooltip.term'] = 'The default term for this package. Customers select the actual term from the package pricing at checkout.';
$lang['Webnic.package_fields.id_protection'] = 'Enable ID protection by default';
$lang['Webnic.package_fields.tooltip.id_protection'] = 'Pre-selects WHOIS/ID protection on new orders for this package.';
$lang['Webnic.package_fields.ns'] = 'Default nameserver %1$s';
$lang['Webnic.package_fields.dns_management'] = 'Enable DNS management by default';
$lang['Webnic.package_fields.tooltip.dns_management'] = 'Pre-selects DNS management on new orders for this package.';

// Order (add/edit-service) fields (Story 3.4b, AC2/FR38). The %1$s NS labels are filled
// from the integer index only.
$lang['Webnic.service_field.domain'] = 'Domain';
$lang['Webnic.service_field.ns'] = 'Nameserver %1$s';
$lang['Webnic.service_field.id_protection'] = 'ID protection';
$lang['Webnic.service_field.tooltip.id_protection'] = 'Hides your personal contact details from the public WHOIS where the registry permits it.';

// Transfer-in order field (WN-4-1 AC7). The EPP/auth code the customer enters to authorize
// the transfer at their current registrar (mirror Logicboxes.transfer_fields). The STORED code
// is never echoed back on screen (UX-DR3) — this label is for the input only.
$lang['Webnic.order_field.auth_code'] = 'Authorization (EPP) code';
$lang['Webnic.order_field.tooltip.auth_code'] = 'The transfer authorization (EPP/auth) code from your current registrar. Required to start the transfer.';

// Per-TLD registry-requirements fieldset (Story 3.4b, AC3/AC4/FR19/UX-DR6). The legend is
// exact per FR19/UX-DR6 — "Registry requirements for .{tld}" — with {tld} substituted.
// field_help_generic is the inline help every surfaced (unmapped) requirement carries so
// no field is rendered without a tooltip/aria-describedby (UX-DR16).
$lang['Webnic.tld_fieldset.legend'] = 'Registry requirements for .%1$s';
$lang['Webnic.tld_fieldset.field_help_generic'] = 'This domain extension has a registry requirement that must be provided to complete registration.';

// Pre-submission validation errors (Story 3.4b, AC3/FR19). Actionable, non-leaking; set
// via Input BEFORE any order is opened (INV-4 pre-flight, never for an async-pending order).
$lang['Webnic.!error.term_invalid'] = 'The selected registration term is not available for this domain. Choose a term between 1 and 10 years.';
$lang['Webnic.!error.nameservers_min'] = 'Please provide at least two nameservers, or leave them blank to use the defaults.';
$lang['Webnic.!error.nameservers_max'] = 'WebNIC supports up to five nameservers from this form. Remove extra nameservers and try again.';
$lang['Webnic.!error.dnsrecord_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so DNS records could not be managed. Please reload and try again.';
$lang['Webnic.!error.dnsrecord_domain_missing'] = 'No domain is on file for this service, so DNS records could not be managed. Please contact support.';
$lang['Webnic.!error.dnsrecord_unavailable'] = 'DNS records could not be loaded from WebNIC. Reload and try again.';
$lang['Webnic.!error.dnsrecord_not_manageable'] = 'DNS records are available once this domain is active and manageable.';
$lang['Webnic.!error.dnsrecord_not_webnic_dns'] = 'DNS records require this domain to use WebNIC basic DNS.';
$lang['Webnic.!error.dnsrecord_no_supported_types'] = 'No editable basic DNS record types are currently supported by WebNIC.';
$lang['Webnic.!error.dnsrecord_target_invalid'] = 'The submitted DNS record could not be matched to an editable row.';
$lang['Webnic.!error.dnsrecord_update_failed'] = 'The DNS record update could not be completed. Check the record and try again.';
$lang['Webnic.!error.dnsrecord_update_unavailable'] = 'The DNS record update is temporarily unavailable. Try again shortly.';
$lang['Webnic.!error.dnsrecord_invalid'] = 'Enter DNS record fields as text.';
$lang['Webnic.!error.dnsrecord_required'] = 'This DNS record field is required.';
$lang['Webnic.!error.dnsrecord_name_invalid'] = 'Enter a valid DNS record name.';
$lang['Webnic.!error.dnsrecord_value_invalid'] = 'Enter a valid DNS record value.';
$lang['Webnic.!error.dnsrecord_type_invalid'] = 'Choose a supported DNS record type.';
$lang['Webnic.!error.dnsrecord_ttl_invalid'] = 'Enter TTL as a whole number. Use a value between 0 and 2147483647.';
$lang['Webnic.!error.dnsrecord_priority_invalid'] = 'Enter priority as a whole number.';
$lang['Webnic.!error.dnsrecord_weight_invalid'] = 'Enter weight as a whole number.';
$lang['Webnic.!error.dnsrecord_port_invalid'] = 'Enter port as a whole number between 1 and 65535.';
$lang['Webnic.!error.dnssec_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so DNSSEC could not be managed. Please reload and try again.';
$lang['Webnic.!error.dnssec_domain_missing'] = 'No domain is on file for this service, so DNSSEC could not be managed. Please contact support.';
$lang['Webnic.!error.dnssec_unavailable'] = 'DNSSEC is temporarily unavailable. Reload and try again shortly.';
$lang['Webnic.!error.dnssec_read_failed'] = 'DNSSEC could not be loaded for this domain. Reload and try again.';
$lang['Webnic.!error.dnssec_not_manageable'] = 'DNSSEC is available once this domain is active and manageable.';
$lang['Webnic.!error.dnssec_unsupported'] = 'DNSSEC is not supported for this domain.';
$lang['Webnic.!error.dnssec_invalid'] = 'Enter DNSSEC fields as text.';
$lang['Webnic.!error.dnssec_action_invalid'] = 'Choose a supported DNSSEC action.';
$lang['Webnic.!error.dnssec_row_token'] = 'The DS record to remove could not be identified. Reload and try again.';
$lang['Webnic.!error.dnssec_target_invalid'] = 'The submitted DS record could not be matched to a current row. Reload and try again.';
$lang['Webnic.!error.dnssec_keytag_invalid'] = 'Enter the key tag as a whole number between 0 and 65535.';
$lang['Webnic.!error.dnssec_algorithm_invalid'] = 'Choose a supported DNSSEC algorithm.';
$lang['Webnic.!error.dnssec_digesttype_invalid'] = 'Choose a supported DNSSEC digest type.';
$lang['Webnic.!error.dnssec_digest_invalid'] = 'Enter the digest as hexadecimal with an even number of characters.';
$lang['Webnic.!error.dnssec_update_failed'] = 'The DNSSEC update could not be completed. Check the DS record and try again.';
$lang['Webnic.!error.dnssec_update_unavailable'] = 'The DNSSEC update is temporarily unavailable. Try again shortly.';
$lang['Webnic.!error.settings_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so settings could not be managed. Please reload and try again.';
$lang['Webnic.!error.settings_domain_missing'] = 'No domain is on file for this service, so settings could not be managed. Please contact support.';
$lang['Webnic.!error.settings_unavailable'] = 'Settings are temporarily unavailable. Reload and try again shortly.';
$lang['Webnic.!error.settings_not_manageable'] = 'Settings are available once this domain is active and manageable.';
$lang['Webnic.!error.settings_update_failed'] = 'The settings update could not be completed. Check the domain state and try again.';
$lang['Webnic.!error.settings_update_unavailable'] = 'The settings update is temporarily unavailable. Try again shortly.';
$lang['Webnic.!error.settings_update_unconfirmed'] = 'The registrar accepted the change, but it could not be confirmed yet. Reload in a moment to check the current state.';
$lang['Webnic.!error.settings_action_invalid'] = 'Choose a supported settings action.';
$lang['Webnic.!error.settings_privacy_not_capable'] = 'WHOIS privacy is not available for this TLD.';
$lang['Webnic.!error.settings_privacy_capability_unavailable'] = 'WHOIS privacy capability could not be confirmed. Reload and try again.';
$lang['Webnic.!error.forwarding_row_unavailable'] = 'This service\'s WebNIC account could not be loaded, so forwarding could not be managed. Please reload and try again.';
$lang['Webnic.!error.forwarding_domain_missing'] = 'No domain is on file for this service, so forwarding could not be managed. Please contact support.';
$lang['Webnic.!error.forwarding_not_manageable'] = 'Forwarding is available once this domain is active and manageable.';
$lang['Webnic.!error.forwarding_unavailable'] = 'Forwarding could not be loaded from WebNIC. Reload and try again.';
$lang['Webnic.!error.forwarding_not_webnic_dns'] = 'Forwarding requires this domain to use WebNIC basic DNS.';
$lang['Webnic.!error.forwarding_update_failed'] = 'The forwarding update could not be completed. Check the forwarding rule and try again.';
$lang['Webnic.!error.forwarding_update_unavailable'] = 'The forwarding update is temporarily unavailable. Try again shortly.';
$lang['Webnic.!error.forwarding_invalid'] = 'Enter forwarding fields as text.';
$lang['Webnic.!error.forwarding_required'] = 'This forwarding field is required.';
$lang['Webnic.!error.forwarding_action_invalid'] = 'Choose a supported forwarding action.';
$lang['Webnic.!error.forwarding_url_target'] = 'Enter a target URL beginning with http:// or https://.';
$lang['Webnic.!error.forwarding_email_target'] = 'Enter a valid destination email address.';
$lang['Webnic.!error.forwarding_row_token'] = 'Reload forwarding before changing this row.';
$lang['Webnic.!error.forwarding_target_invalid'] = 'The submitted forwarding row could not be matched to an editable row.';
$lang['Webnic.!error.forwarding_subdomain'] = 'Enter @ for the zone apex, or an ASCII subdomain such as www or shop.eu.';
$lang['Webnic.!error.forwarding_user'] = 'Enter a valid email source local-part.';
$lang['Webnic.!error.tld_requirement_missing'] = 'A required registry detail for this domain extension is missing. Please complete the highlighted fields.';
