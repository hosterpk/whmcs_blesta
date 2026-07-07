<?php
/**
 * HosterPK client template language - en_us : the CANONICAL copy table (Story 2.4).
 *
 * Keying convention - `Hosterpk.<surface>.<state>`, kept filterable so
 * gen-copy-coverage.sh can grep it:
 *   - theme_toggle.* - the icon-toggle accessible names (seeded Story 2.3,
 *     consumed by the hpkThemeToggle Alpine reactive aria-label).
 *   - state.*        - the base-state CANON: the warm-voice empty / error /
 *     success exemplars (loading is the skeleton = no text; warning copy is
 *     page-specific, so neither carries a generic key).
 *   - action.*       - verb-first button labels.
 *   - login.*        - the login surface trust + reassurance lines.
 *
 * Voice (AR-ENF3 / UX-DR23): second person, present tense, one sentence, names
 * the next action, no exclamation marks. Values are taken VERBATIM from the UX
 * EXPERIENCE.md Voice & Tone table - these deliberate brand/voice strings are
 * the documented `Language::_()` exception; everything else stays translatable.
 * Loaded at runtime via `Language::loadLang('hosterpk', ...)` (structure.pdt).
 *
 * One-sentence exception (review 2.4-R1 D3, RATIFIED by Israr): the sealed
 * EXPERIENCE.md error exemplar `state.error_generic` is verbatim TWO sentences
 * ("That card was declined. Try another method, or we can help."). VERBATIM
 * source fidelity wins over the one-sentence rule here - the UX artifact is
 * sealed; do not rewrite it. This is the single sanctioned multi-sentence value.
 *
 * Scope (OQ3 / AR-ENF3 "no entry = hard stop"): this file ships the base-state
 * canon + the documented voice strings ONLY. A page-specific key arrives WITH
 * its page (Epics 3-10); do NOT pre-author keys for pages that do not exist yet
 * (inventing one now is guessing at scope). The hard-stop is meaningful only
 * once a page actually pulls a key.
 *
 * Legacy Blesta language file: plain `$lang[...]`, no namespace, no
 * declare(strict_types); PHP 8.2 source floor.
 */

// Theme toggle - the accessible name reflects the ACTION the icon-only control
// performs (it flips light<->dark), never colour alone. The name announced is
// what activating the control will DO from the current theme.
$lang['Hosterpk.theme_toggle.to_dark'] = 'Switch to dark theme';
$lang['Hosterpk.theme_toggle.to_light'] = 'Switch to light theme';

// Password show/hide toggle (Story 2.6) - the accessible name reflects the
// ACTION the control performs (reveal vs mask), never a state alone; the name
// announced is what activating it will DO. Text toggle, no eye glyph (OQ3).
// Consumed by the hpkPasswordToggle Alpine reactive aria-label (passed in via
// x-data); the static aria-label is the clean fallback.
$lang['Hosterpk.password_toggle.show'] = 'Show password';
$lang['Hosterpk.password_toggle.hide'] = 'Hide password';

// Base-state canon - the warm-voice exemplars (EXPERIENCE.md:80-82). The empty
// string is a template for the empty state; per-list copy arrives with each list
// page. The error/success strings are the canonical declined-card / payment
// exemplars.
$lang['Hosterpk.state.empty_generic'] = 'You\'re all caught up — no open tickets.';
// Sanctioned two-sentence exception (review 2.4 D3) - verbatim sealed UX source.
$lang['Hosterpk.state.error_generic'] = 'That card was declined. Try another method, or we can help.';
$lang['Hosterpk.state.success_generic'] = 'Payment received — you\'re all set.';

// Verb-first actions (EXPERIENCE.md:79) - the real, fixed brand button labels
// the empty/error/action surfaces reuse.
$lang['Hosterpk.action.pay_now'] = 'Pay now';
$lang['Hosterpk.action.open_ticket'] = 'Open a ticket';
$lang['Hosterpk.action.renew_domain'] = 'Renew domain';
$lang['Hosterpk.action.manage_service'] = 'Manage service';
$lang['Hosterpk.action.pay_past_due'] = 'Pay past due';
$lang['Hosterpk.action.verify_email'] = 'Verify email';
// Modal close (Story 2.7) - the accessible name on the .hpk-modal close
// affordance; a shared, surface-agnostic action label (the consumer .pdt renders
// it as the close button's aria-label). Page-specific modal copy (titles, body)
// arrives WITH each page, E5+ (AR-ENF3).
$lang['Hosterpk.action.close'] = 'Close';

// Dashboard home (Stories 4.1 / 4.4) - action-needed cards lead the client home
// page, with status as translated text plus the semantic colour pill. Story 4.4
// collapses the duplicate stock home into a single triage surface and makes the
// greeting the page H1.
$lang['Hosterpk.dashboard.heading'] = 'Dashboard';
$lang['Hosterpk.dashboard.greeting'] = 'Welcome back, %1$s';
$lang['Hosterpk.dashboard.greeting_noname'] = 'Welcome back';
$lang['Hosterpk.dashboard.flash_label'] = 'Notifications';
$lang['Hosterpk.dashboard.summary_title'] = 'Dashboard summary';
$lang['Hosterpk.dashboard.summaries_title'] = 'Billing & domains';
$lang['Hosterpk.dashboard.action_needed_title'] = 'Needs your attention';
$lang['Hosterpk.dashboard.in_progress_title'] = 'In progress';
$lang['Hosterpk.dashboard.settled_title'] = 'Settled';
$lang['Hosterpk.dashboard.hero.attention'] = 'You have items that need your attention.';
$lang['Hosterpk.dashboard.hero.healthy'] = 'Everything looks good. No action needed.';
$lang['Hosterpk.dashboard.hero.empty.title'] = 'Welcome to your account';
$lang['Hosterpk.dashboard.hero.empty.body'] = 'You do not have any active services yet. Use the menu when you are ready to explore.';
$lang['Hosterpk.dashboard.hero.empty.view_services'] = 'View services';
$lang['Hosterpk.dashboard.invoice_due_title'] = 'An invoice is due';
$lang['Hosterpk.dashboard.invoice_due_detail'] = 'You have %1$s due on your account.';
$lang['Hosterpk.dashboard.invoice_overdue_title'] = 'An invoice is overdue';
$lang['Hosterpk.dashboard.invoice_overdue_detail'] = 'You have %1$s due, including %2$s past due.';
$lang['Hosterpk.dashboard.service_in_review_title'] = 'A service is in review';
$lang['Hosterpk.dashboard.email_verify_title'] = 'Your email needs verification';
$lang['Hosterpk.dashboard.email_verify_detail'] = 'Verify %1$s to keep account notices reaching you.';
$lang['Hosterpk.dashboard.all_clear_title'] = 'You are all caught up';
$lang['Hosterpk.dashboard.all_clear_body'] = 'Nothing needs your action right now.';
$lang['Hosterpk.dashboard.more_link'] = '+%1$s more';
$lang['Hosterpk.status.overdue'] = 'Overdue';
$lang['Hosterpk.status.due'] = 'Due';
$lang['Hosterpk.status.in_review'] = 'In review';
$lang['Hosterpk.status.verify_pending'] = 'Verify pending';
$lang['Hosterpk.status.settled'] = 'Settled';
$lang['Hosterpk.status.paid'] = 'Paid';
$lang['Hosterpk.status.unpaid'] = 'Unpaid';

// Invoices list (Story 5.1) - first owned billing list/table page.
$lang['Hosterpk.invoice.page_title'] = 'Invoices';
$lang['Hosterpk.invoice.tabs_label'] = 'Invoice status';
$lang['Hosterpk.invoice.tab_open'] = 'Open';
$lang['Hosterpk.invoice.tab_closed'] = 'Closed';
$lang['Hosterpk.invoice.filter_invoice_number'] = 'Invoice number';
$lang['Hosterpk.invoice.filter_currency'] = 'Currency';
$lang['Hosterpk.invoice.filter_invoice_line'] = 'Line item';
$lang['Hosterpk.invoice.filter_generic'] = 'Filter';
$lang['Hosterpk.invoice.filter_submit'] = 'Filter invoices';
$lang['Hosterpk.invoice.heading_invoice'] = 'Invoice #';
$lang['Hosterpk.invoice.heading_amount'] = 'Amount';
$lang['Hosterpk.invoice.heading_paid'] = 'Paid';
$lang['Hosterpk.invoice.heading_due'] = 'Due';
$lang['Hosterpk.invoice.heading_date_closed'] = 'Date closed';
$lang['Hosterpk.invoice.heading_date_billed'] = 'Date billed';
$lang['Hosterpk.invoice.heading_date_due'] = 'Date due';
$lang['Hosterpk.invoice.heading_status'] = 'Status';
$lang['Hosterpk.invoice.heading_actions'] = 'Actions';
$lang['Hosterpk.invoice.action_pay'] = 'Pay';
$lang['Hosterpk.invoice.action_view'] = 'View';
$lang['Hosterpk.invoice.action_download'] = 'Download PDF';
$lang['Hosterpk.invoice.action_download_electronic'] = 'Download %1$s';
$lang['Hosterpk.invoice.empty_open_title'] = 'You\'re all caught up';
$lang['Hosterpk.invoice.empty_open_body'] = 'Check closed invoices or come back when a new bill arrives.';
$lang['Hosterpk.invoice.empty_closed_title'] = 'No closed invoices yet';
$lang['Hosterpk.invoice.empty_closed_body'] = 'Paid invoices will appear here when they are settled.';
$lang['Hosterpk.invoice.pagination_label'] = 'Invoice pages';

// Invoice detail (Story 5.2) - the UJ-2 read surface the list links into. The
// single status badge is the canonical paid/unpaid/overdue indicator (DEC-9), so
// there is no separate "paid" text marker key. Invoice terms come from the
// per-client inv_terms_<language> setting, not a static copy key.
$lang['Hosterpk.invoice_detail.heading_invoice'] = 'Invoice #%1$s';
$lang['Hosterpk.invoice_detail.heading_proforma'] = 'Proforma #%1$s';
$lang['Hosterpk.invoice_detail.invoice_from'] = 'Invoice from';
$lang['Hosterpk.invoice_detail.bill_to'] = 'Bill to';
$lang['Hosterpk.invoice_detail.client_id'] = 'Client ID';
$lang['Hosterpk.invoice_detail.date_billed'] = 'Date billed';
$lang['Hosterpk.invoice_detail.date_due'] = 'Date due';
$lang['Hosterpk.invoice_detail.description'] = 'Description';
$lang['Hosterpk.invoice_detail.quantity'] = 'Quantity';
$lang['Hosterpk.invoice_detail.unit_price'] = 'Unit price';
$lang['Hosterpk.invoice_detail.cost'] = 'Cost';
$lang['Hosterpk.invoice_detail.subtotal'] = 'Subtotal';
$lang['Hosterpk.invoice_detail.tax'] = '%1$s (%2$s%%)';
$lang['Hosterpk.invoice_detail.total'] = 'Total';
$lang['Hosterpk.invoice_detail.notes'] = 'Notes';
$lang['Hosterpk.invoice_detail.overdue_banner'] = 'This invoice is past due — pay now to avoid service interruption.';
$lang['Hosterpk.invoice_detail.payments_heading'] = 'Payments applied';
$lang['Hosterpk.invoice_detail.payments_applied_date'] = 'Applied date';
$lang['Hosterpk.invoice_detail.payments_type'] = 'Type';
$lang['Hosterpk.invoice_detail.payments_transaction_id'] = 'Transaction ID';
$lang['Hosterpk.invoice_detail.payments_amount'] = 'Amount';
$lang['Hosterpk.invoice_detail.balance_due'] = 'Balance due';

// Applied-transactions fragment (Story 5.2, DEC-6) - the 5.1 list expand-row sub-view.
$lang['Hosterpk.invoice_applied.heading_paymenttype'] = 'Payment type';
$lang['Hosterpk.invoice_applied.heading_amount'] = 'Amount';
$lang['Hosterpk.invoice_applied.heading_applied'] = 'Applied';
$lang['Hosterpk.invoice_applied.heading_appliedon'] = 'Applied on';
$lang['Hosterpk.invoice_applied.no_results'] = 'No payments have been applied to this invoice yet.';

// Transactions / billing history list (Story 5.5) - read-only payment record.
// The Status column is net-new (stock conveys status only via the active tab);
// its badge text lives here, never the stock Transactions.status.* strings (AC13).
$lang['Hosterpk.transaction.page_title'] = 'Billing history';
$lang['Hosterpk.transaction.tabs_label'] = 'Transaction status';
$lang['Hosterpk.transaction.tab_approved'] = 'Approved';
$lang['Hosterpk.transaction.tab_pending'] = 'Pending';
$lang['Hosterpk.transaction.tab_declined'] = 'Declined';
$lang['Hosterpk.transaction.filter_payment_type'] = 'Payment type';
$lang['Hosterpk.transaction.filter_transaction_id'] = 'Transaction number';
$lang['Hosterpk.transaction.filter_applied_status'] = 'Applied status';
$lang['Hosterpk.transaction.filter_start_date'] = 'From date';
$lang['Hosterpk.transaction.filter_end_date'] = 'To date';
$lang['Hosterpk.transaction.filter_minimum_amount'] = 'Minimum amount';
$lang['Hosterpk.transaction.filter_maximum_amount'] = 'Maximum amount';
$lang['Hosterpk.transaction.filter_generic'] = 'Filter';
$lang['Hosterpk.transaction.filter_submit'] = 'Filter transactions';
$lang['Hosterpk.transaction.filter_option_any'] = 'Any';
$lang['Hosterpk.transaction.filter_option_fully_applied'] = 'Fully applied';
$lang['Hosterpk.transaction.filter_option_partially_applied'] = 'Partially applied';
$lang['Hosterpk.transaction.filter_option_not_applied'] = 'Not applied';
$lang['Hosterpk.transaction.filter_placeholder_transaction_id'] = 'Transaction number';
$lang['Hosterpk.transaction.filter_placeholder_start_date'] = 'From date';
$lang['Hosterpk.transaction.filter_placeholder_end_date'] = 'To date';
$lang['Hosterpk.transaction.filter_placeholder_minimum_amount'] = 'Minimum amount';
$lang['Hosterpk.transaction.filter_placeholder_maximum_amount'] = 'Maximum amount';
$lang['Hosterpk.transaction.heading_type'] = 'Type';
$lang['Hosterpk.transaction.heading_amount'] = 'Amount';
$lang['Hosterpk.transaction.heading_credited'] = 'Credited';
$lang['Hosterpk.transaction.heading_applied'] = 'Applied';
$lang['Hosterpk.transaction.heading_number'] = 'Number';
$lang['Hosterpk.transaction.heading_date'] = 'Date';
$lang['Hosterpk.transaction.heading_status'] = 'Status';
$lang['Hosterpk.transaction.status_approved'] = 'Approved';
$lang['Hosterpk.transaction.status_pending'] = 'Pending';
$lang['Hosterpk.transaction.status_declined'] = 'Declined';
$lang['Hosterpk.transaction.status_void'] = 'Void';
$lang['Hosterpk.transaction.status_error'] = 'Error';
$lang['Hosterpk.transaction.status_refunded'] = 'Refunded';
$lang['Hosterpk.transaction.status_returned'] = 'Returned';
$lang['Hosterpk.transaction.status_unknown'] = 'Unknown';
$lang['Hosterpk.transaction.empty_approved_title'] = 'No approved payments yet';
$lang['Hosterpk.transaction.empty_approved_body'] = 'Make a payment to see approved receipts listed here once payments clear.';
$lang['Hosterpk.transaction.empty_pending_title'] = 'No pending payments';
$lang['Hosterpk.transaction.empty_pending_body'] = 'Make a payment and return here to track it while it clears.';
$lang['Hosterpk.transaction.empty_declined_title'] = 'No declined payments';
$lang['Hosterpk.transaction.empty_declined_body'] = 'Retry the payment from your invoice or contact support for help.';
$lang['Hosterpk.transaction.empty_void_title'] = 'No void payments';
$lang['Hosterpk.transaction.empty_void_body'] = 'Contact support if you expected a void payment to appear here.';
$lang['Hosterpk.transaction.empty_error_title'] = 'No error transactions';
$lang['Hosterpk.transaction.empty_error_body'] = 'Contact support if a failed payment should appear here.';
$lang['Hosterpk.transaction.empty_refunded_title'] = 'No refunds';
$lang['Hosterpk.transaction.empty_refunded_body'] = 'Contact support if you expected a refund to appear here.';
$lang['Hosterpk.transaction.empty_returned_title'] = 'No returned payments';
$lang['Hosterpk.transaction.empty_returned_body'] = 'Contact support if a returned payment is missing here.';
$lang['Hosterpk.transaction.pagination_label'] = 'Transaction pages';

// Applied-invoices fragment (Story 5.5, DEC-7) - the invoices a transaction was
// applied to, echoed without layout by client_transactions::applied().
$lang['Hosterpk.transaction_applied.heading_invoice'] = 'Invoice';
$lang['Hosterpk.transaction_applied.heading_amount'] = 'Amount';
$lang['Hosterpk.transaction_applied.heading_appliedon'] = 'Applied on';
$lang['Hosterpk.transaction_applied.no_results'] = 'This payment has not been applied to any invoices yet.';

// Payment flow (Story 5.3) - method selection, confirm, received surfaces.
// The invoice-summary and confirm-action labels reuse the existing Blesta
// ClientPay.* / ClientAccounts.* keys where a stock partial contract is held.
$lang['Hosterpk.payment_method.select_method'] = 'Choose how to pay';
$lang['Hosterpk.payment_method.saved_account'] = 'Saved payment method';
$lang['Hosterpk.payment_method.new_details'] = 'New payment details';
$lang['Hosterpk.payment_method.gateway_option'] = 'Payment gateway';
$lang['Hosterpk.payment_method.secure_note'] = 'Your payment details are entered securely and handled directly by the payment gateway.';
$lang['Hosterpk.payment.received_empty_title'] = 'Payment status unavailable';
$lang['Hosterpk.payment.received_empty_body'] = 'We could not confirm this payment. You can try again or contact support.';
$lang['Hosterpk.payment.select_invoice'] = 'Select invoice %1$s for payment';
$lang['Hosterpk.payment.select_all_invoices'] = 'Select all invoices for payment';

// Dashboard at-a-glance summary (Stories 4.2 / 4.4) — stat tiles, recent activity
// feed, and quick-action links. Voice: second person, present tense, one sentence,
// no exclamation marks. Empty states name the next implicit action.
$lang['Hosterpk.dashboard.overview_title'] = 'Account overview';
$lang['Hosterpk.dashboard.stats_title'] = 'At a glance';
$lang['Hosterpk.dashboard.stat_services'] = 'Active services';
$lang['Hosterpk.dashboard.stat_services_singular'] = 'Active service';
$lang['Hosterpk.dashboard.stat_services_empty'] = 'No active services yet';
$lang['Hosterpk.dashboard.stat_domains'] = 'Active domains';
$lang['Hosterpk.dashboard.stat_domains_singular'] = 'Active domain';
$lang['Hosterpk.dashboard.stat_domains_empty'] = 'No active domains';
$lang['Hosterpk.dashboard.stat_invoices_unpaid'] = 'Unpaid invoices';
$lang['Hosterpk.dashboard.stat_invoices_unpaid_singular'] = 'Unpaid invoice';
$lang['Hosterpk.dashboard.stat_invoices_unpaid_empty'] = 'No unpaid invoices';
$lang['Hosterpk.dashboard.stat_credit'] = 'Account credit';
$lang['Hosterpk.dashboard.stat_credit_empty'] = 'No account credit';
$lang['Hosterpk.dashboard.activity_title'] = 'Recent activity';
$lang['Hosterpk.dashboard.activity_date_suffix'] = ' · %1$s';
$lang['Hosterpk.dashboard.activity_payment'] = 'Payment received — %1$s%2$s';
$lang['Hosterpk.dashboard.activity_invoice'] = 'Invoice #%1$s created — %2$s%3$s';
$lang['Hosterpk.dashboard.activity_invoice_no_number'] = 'Invoice created — %1$s%2$s';
$lang['Hosterpk.dashboard.activity_empty_title'] = 'No recent activity';
$lang['Hosterpk.dashboard.activity_empty_body'] = 'When you make a payment or receive an invoice, it will appear here.';
$lang['Hosterpk.dashboard.quick_actions_title'] = 'Quick actions';
$lang['Hosterpk.action.pay_invoice'] = 'Pay invoice';
$lang['Hosterpk.action.manage_domains'] = 'Manage domains';
$lang['Hosterpk.action.update_account'] = 'Update account';

// Dashboard lower-page summary cards and compact account summary (Story 4.4)
$lang['Hosterpk.dashboard.latest_invoice.title'] = 'Latest invoice';
$lang['Hosterpk.dashboard.latest_invoice.empty'] = 'No invoices yet.';
$lang['Hosterpk.dashboard.latest_invoice.due'] = 'Due %1$s';
$lang['Hosterpk.dashboard.latest_invoice.past_due'] = 'Past due';
$lang['Hosterpk.dashboard.domain.title'] = 'Next domain renewal';
$lang['Hosterpk.dashboard.domain.renews'] = 'Renews %1$s';
$lang['Hosterpk.dashboard.account.title'] = 'Account';
$lang['Hosterpk.dashboard.account.status'] = '%1$s · %2$s';
$lang['Hosterpk.dashboard.account.autopay_on'] = 'Auto-pay on';
$lang['Hosterpk.dashboard.account.autopay_off'] = 'Auto-pay off';
$lang['Hosterpk.dashboard.account.credit'] = 'Credit: %1$s';
$lang['Hosterpk.dashboard.account.update'] = 'Update account';
$lang['Hosterpk.action.pay'] = 'Pay';
$lang['Hosterpk.action.view'] = 'View';
$lang['Hosterpk.action.manage'] = 'Manage';

// Auth shell - shared, neutral fallback copy for $show_header=false account-entry
// pages until each owned auth view provides page-specific shell text.
$lang['Hosterpk.auth_shell.headline'] = 'Access your HosterPK account securely.';
$lang['Hosterpk.auth_shell.trust_line'] = 'Continue to manage your services in one place.';

// Login surface (EXPERIENCE.md:83-84) - the migration-reassuring trust line and
// the stored-payment-details reassurance.
$lang['Hosterpk.login.headline'] = 'Your hosting, domains & billing — all in one place.';
$lang['Hosterpk.login.trust_line'] = 'Same account, refreshed and easier to use.';
$lang['Hosterpk.login.reassurance'] = 'Your payment details are never stored by HosterPK.';
$lang['Hosterpk.login.secure_line'] = 'Protected by HosterPK secure login.';
// Login unhappy paths (Story 3.2, FR14 / UX-DR22-23 / EXPERIENCE.md:190). Warm,
// migration-aware failure copy that replaces Blesta's raw auth errors. error_invalid
// is VERBATIM from the sealed EXPERIENCE.md:190; error_locked covers BOTH the rate-limit
// and the wrong-company/location blocks, so it is duration-agnostic by design (OQ1
// ratified 2026-06-19 - "wait a few minutes" was dropped because the company/location
// block is not time-based). error_captcha covers a failed human-verification check
// (captcha enabled + wrong answer) without disclosing whether the credentials matched.
// Voice: second person, present tense, names the next action, no exclamation. The
// two-sentence error_invalid follows the sealed source verbatim (the same one-sentence
// exception ratified for state.error_generic above).
$lang['Hosterpk.login.error_invalid'] = 'Those details didn\'t match. Try again, or reset your password.';
$lang['Hosterpk.login.error_locked'] = 'Sign-in isn\'t available right now — try again in a bit, or reset your password.';
$lang['Hosterpk.login.error_captcha'] = 'That security check didn\'t match — please try the verification again.';

// Brand chrome (Story 2.10) - the shell's accessible names + the authored sidebar
// group labels. nav.* control names follow the names-the-action voice (open/close/
// skip); the nav.group.* labels are the URI-prefix-bucketed sidebar sections (the IA
// is authored in the .pdt, NOT derived from Blesta's localized $nav labels - see the
// story "nav group map"). nav.primary_label is the single <nav> landmark's aria-label
// (the WAI-ARIA "Primary" convention - no trailing "navigation", which AT appends).
// notifications.label is the icon-only link to the dashboard action-needed region
// (link-only in 2.10 - no feed/count/dot). brand.logo_alt is the branded logo's
// alternative text. account.menu_label names the account disclosure. footer.* is the
// compact brand footer (NOT the marketing footer).
$lang['Hosterpk.brand.logo_alt'] = 'HosterPK';
$lang['Hosterpk.nav.skip_to_content'] = 'Skip to main content';
$lang['Hosterpk.nav.primary_label'] = 'Primary';
$lang['Hosterpk.nav.open_menu'] = 'Open the navigation menu';
$lang['Hosterpk.nav.close_menu'] = 'Close the navigation menu';
$lang['Hosterpk.nav.more'] = 'More';
$lang['Hosterpk.nav.group.dashboard'] = 'Dashboard';
$lang['Hosterpk.nav.group.services'] = 'My Services';
$lang['Hosterpk.nav.group.domains'] = 'Domains';
$lang['Hosterpk.nav.group.billing'] = 'Billing';
$lang['Hosterpk.nav.group.support'] = 'Support';
$lang['Hosterpk.nav.group.resources'] = 'Resources';
$lang['Hosterpk.nav.group.account'] = 'Account';
$lang['Hosterpk.notifications.label'] = 'View notifications';
$lang['Hosterpk.account.menu_label'] = 'Open your account menu';
$lang['Hosterpk.footer.home'] = 'Home';
$lang['Hosterpk.footer.rights'] = 'All rights reserved.';

// Account management — stored payment methods, credit thresholds, add/edit flows
// (Story 5.6, FR24 / UX-DR21). Voice: second person, present tense, one sentence,
// names the next action, no exclamation marks.
//
// Stored payment methods list.
$lang['Hosterpk.account.page_title'] = 'Payment accounts';
$lang['Hosterpk.account.intro'] = 'Manage the cards and accounts saved to your profile.';
$lang['Hosterpk.account.legend'] = 'Choose your automatic payment method';
$lang['Hosterpk.account.no_autodebit'] = 'No automatic payments';
$lang['Hosterpk.account.no_autodebit_detail'] = 'Pay invoices manually when they are due.';
$lang['Hosterpk.account.last4'] = 'ending in';
$lang['Hosterpk.account.action_edit'] = 'Edit';
$lang['Hosterpk.account.action_verify'] = 'Verify';
$lang['Hosterpk.account.action_remove'] = 'Remove';
$lang['Hosterpk.account.action_add'] = 'Add payment method';
$lang['Hosterpk.account.set_autodebit'] = 'Use for automatic payments';
$lang['Hosterpk.account.remove_autodebit'] = 'Remove automatic payments';
$lang['Hosterpk.account.status_unverified'] = 'Unverified';
$lang['Hosterpk.account.status_inactive'] = 'Inactive';
$lang['Hosterpk.account.empty_title'] = 'No payment methods saved yet';
$lang['Hosterpk.account.empty_body'] = 'Add a card or bank account so you can pay invoices quickly.';
$lang['Hosterpk.account.confirm_remove'] = 'Remove this saved payment method? You can add it again later.';

// Accounts sub-navigation.
$lang['Hosterpk.account_nav.menu_label'] = 'Payment accounts menu';
$lang['Hosterpk.account_nav.payment_accounts'] = 'Payment accounts';
$lang['Hosterpk.account_nav.add'] = 'Add payment method';
$lang['Hosterpk.account_nav.credit_handling'] = 'Account credit';
$lang['Hosterpk.account_nav.return'] = 'Return to dashboard';

// Account credit (threshold settings).
$lang['Hosterpk.account_credit.page_title'] = 'Account credit';
$lang['Hosterpk.account_credit.explainer'] = 'Credit on your account can be applied automatically when an open invoice reaches the threshold you set for each currency.';
$lang['Hosterpk.account_credit.pay_flow_pointer'] = 'To apply credit while paying a specific invoice, use the account-credit option on the pay screen.';
$lang['Hosterpk.account_credit.heading_currency'] = 'Currency';
$lang['Hosterpk.account_credit.heading_threshold'] = 'Auto-apply threshold';
$lang['Hosterpk.account_credit.save'] = 'Save settings';

// Add payment account (2-step).
$lang['Hosterpk.account_add.page_title'] = 'Add payment method';
$lang['Hosterpk.account_add.intro'] = 'Choose the type of account you want to save.';
$lang['Hosterpk.account_add.type_legend'] = 'Choose account type';
$lang['Hosterpk.account_add.type_cc'] = 'Credit or debit card';
$lang['Hosterpk.account_add.type_cc_detail'] = 'Pay with Visa, Mastercard, or other major cards.';
$lang['Hosterpk.account_add.type_ach'] = 'Bank account';
$lang['Hosterpk.account_add.type_ach_detail'] = 'Pay directly from a checking or savings account.';
$lang['Hosterpk.account_add.action_continue'] = 'Continue';
$lang['Hosterpk.account_add.action_create'] = 'Create account';

// Edit payment account.
$lang['Hosterpk.account_edit.cc_page_title'] = 'Edit card';
$lang['Hosterpk.account_edit.cc_intro'] = 'Update the billing contact and card details for this saved payment method.';
$lang['Hosterpk.account_edit.ach_page_title'] = 'Edit bank account';
$lang['Hosterpk.account_edit.ach_intro'] = 'Update the billing contact and bank account details for this saved payment method.';
$lang['Hosterpk.account_edit.action_update_cc'] = 'Update card';
$lang['Hosterpk.account_edit.action_update_ach'] = 'Update bank account';

// Billing contact partial.
$lang['Hosterpk.account_contact.heading'] = 'Billing contact';
$lang['Hosterpk.account_contact.field_contact_id'] = 'Copy from an existing contact';
$lang['Hosterpk.account_contact.help_select_contact'] = 'Select a contact to pre-fill the fields below.';
$lang['Hosterpk.account_contact.field_first_name'] = 'First name';
$lang['Hosterpk.account_contact.field_last_name'] = 'Last name';
$lang['Hosterpk.account_contact.field_address1'] = 'Address line 1';
$lang['Hosterpk.account_contact.field_address2'] = 'Address line 2';
$lang['Hosterpk.account_contact.field_city'] = 'City';
$lang['Hosterpk.account_contact.field_country'] = 'Country';
$lang['Hosterpk.account_contact.field_state'] = 'State';
$lang['Hosterpk.account_contact.field_zip'] = 'ZIP / postal code';
$lang['Hosterpk.account_contact.field_email'] = 'Email address';

// Services list (Story 6.1) — first owned services list/table override.
$lang['Hosterpk.service.page_title'] = 'My services';
$lang['Hosterpk.service.tabs_label'] = 'Service status';
$lang['Hosterpk.service.tab_active'] = 'Active';
$lang['Hosterpk.service.tab_pending'] = 'Pending';
$lang['Hosterpk.service.tab_suspended'] = 'Suspended';
$lang['Hosterpk.service.tab_canceled'] = 'Canceled';
$lang['Hosterpk.service.filter_package_name'] = 'Package name';
$lang['Hosterpk.service.filter_service_meta'] = 'Service details';
$lang['Hosterpk.service.filter_generic'] = 'Filter';
$lang['Hosterpk.service.filter_submit'] = 'Filter services';
$lang['Hosterpk.service.heading_package'] = 'Package';
$lang['Hosterpk.service.heading_label'] = 'Label';
$lang['Hosterpk.service.heading_term'] = 'Term';
$lang['Hosterpk.service.heading_date_created'] = 'Date created';
$lang['Hosterpk.service.heading_date_renews'] = 'Date renews';
$lang['Hosterpk.service.heading_date_suspended'] = 'Date suspended';
$lang['Hosterpk.service.heading_date_canceled'] = 'Date canceled';
$lang['Hosterpk.service.heading_status'] = 'Status';
$lang['Hosterpk.service.heading_actions'] = 'Actions';
$lang['Hosterpk.service.status_active'] = 'Active';
$lang['Hosterpk.service.status_pending'] = 'Pending';
$lang['Hosterpk.service.status_suspended'] = 'Suspended';
$lang['Hosterpk.service.status_canceled'] = 'Canceled';
$lang['Hosterpk.service.status_unknown'] = 'Unknown';
$lang['Hosterpk.service.status_in_review'] = 'In review';
$lang['Hosterpk.service.action_manage'] = 'Manage service';
$lang['Hosterpk.service.recurring_term'] = '%1$s %2$s at %3$s';
$lang['Hosterpk.service.text_never'] = 'Never';
$lang['Hosterpk.service.cancel_pending_tooltip'] = 'This service cancels on %1$s';
$lang['Hosterpk.service.empty_active_title'] = 'No active services yet';
$lang['Hosterpk.service.empty_active_body'] = 'Check back after provisioning; your active hosting services appear here.';
$lang['Hosterpk.service.empty_pending_title'] = 'No pending services';
$lang['Hosterpk.service.empty_pending_body'] = 'Check this page while setup continues; pending services appear here.';
$lang['Hosterpk.service.empty_suspended_title'] = 'No suspended services';
$lang['Hosterpk.service.empty_suspended_body'] = 'Review billing or verification notices; suspended services appear here when action is needed.';
$lang['Hosterpk.service.empty_canceled_title'] = 'No canceled services';
$lang['Hosterpk.service.empty_canceled_body'] = 'Use this page for your records; canceled services remain listed here.';
$lang['Hosterpk.service.pagination_label'] = 'Service pages';

// Service detail (Story 6.2) — status-led service-detail surface.
$lang['Hosterpk.service_detail.page_title'] = '%1$s — %2$s'; // %1$s is the package name, %2$s is the service label
$lang['Hosterpk.service_detail.rail_label'] = 'Service navigation';
$lang['Hosterpk.service_detail.module_frame_label'] = 'Service management';
$lang['Hosterpk.service_detail.heading_status'] = 'Status';
$lang['Hosterpk.service_detail.heading_package'] = 'Package';
$lang['Hosterpk.service_detail.heading_service_name'] = 'Service label';
$lang['Hosterpk.service_detail.heading_date_added'] = 'Date added';
$lang['Hosterpk.service_detail.heading_package_term'] = 'Term';
$lang['Hosterpk.service_detail.heading_date_renews'] = 'Renews on';
$lang['Hosterpk.service_detail.heading_date_next_invoice'] = 'Next invoice';
$lang['Hosterpk.service_detail.heading_price'] = 'Renewal price';
$lang['Hosterpk.service_detail.heading_price_onetime'] = 'Price';
$lang['Hosterpk.service_detail.heading_recurring_coupon'] = 'Recurring coupon';
$lang['Hosterpk.service_detail.text_never'] = 'Never';
$lang['Hosterpk.service_detail.text_coupon_percent'] = '%1$s (%2$s%%)'; // %1$s is the coupon code, %2$s is the discount percentage (use %% for a literal percent sign)
$lang['Hosterpk.service_detail.text_coupon_amount'] = '%1$s (%2$s)'; // %1$s is the coupon code, %2$s is the formatted discount amount
$lang['Hosterpk.service_detail.text_price'] = '%1$sx %2$s'; // %1$s is the quantity, %2$s is the formatted option price
$lang['Hosterpk.service_detail.heading_config_options'] = 'Configurable options';
$lang['Hosterpk.service_detail.heading_options'] = 'Manage this service';
$lang['Hosterpk.service_detail.button_change_service_package'] = 'Change package';
$lang['Hosterpk.service_detail.button_change_service_term'] = 'Change term';
$lang['Hosterpk.service_detail.button_renew'] = 'Renew';
$lang['Hosterpk.service_detail.button_cancel'] = 'Cancel service';
$lang['Hosterpk.service_detail.button_config_options'] = 'Change options';
$lang['Hosterpk.service_detail.button_manage_parent'] = 'Manage parent service';
// Service detail — HTMX tab island loading affordances (Story 7.2). loading_label is
// the visually-hidden label inside the skeleton placeholder; loading_announce is the
// concise message set on the #hpk-service-tabpanel-status aria-live region during an
// in-flight tab swap (NOT announced on the whole panel — DEC-7). Voice: present-tense,
// names what is happening, no exclamation.
$lang['Hosterpk.service_detail.loading_label'] = 'Loading…';
$lang['Hosterpk.service_detail.loading_announce'] = 'Loading service section…';

// Service cancel (Story 6.2) — blestaModal confirm-before-commit body.
$lang['Hosterpk.service_cancel.heading_cancel'] = 'Cancel service';
$lang['Hosterpk.service_cancel.close'] = 'Close';
$lang['Hosterpk.service_cancel.field_term_date'] = 'Cancel at the end of the current term on %1$s.'; // %1$s is the term end date
$lang['Hosterpk.service_cancel.field_term'] = 'Cancel at the end of the current term.';
$lang['Hosterpk.service_cancel.field_now'] = 'Cancel this service now.';
$lang['Hosterpk.service_cancel.field_do_not'] = 'Do not cancel this service.';
$lang['Hosterpk.service_cancel.field_cancellation_reason'] = 'Cancellation reason';
$lang['Hosterpk.service_cancel.field_password'] = 'Confirm your password';
$lang['Hosterpk.service_cancel.field_cancel_cancel'] = 'Keep service';
$lang['Hosterpk.service_cancel.field_cancel_submit'] = 'Cancel service';

// Service renew (Story 6.2) — blestaModal confirm-before-commit body.
$lang['Hosterpk.service_renew.heading_renew'] = 'Renew service';
$lang['Hosterpk.service_renew.close'] = 'Close';
$lang['Hosterpk.service_renew.field_pricing_id'] = 'Renewal term';
$lang['Hosterpk.service_renew.field_password'] = 'Confirm your password';
$lang['Hosterpk.service_renew.field_renew_cancel'] = 'Keep current term';
$lang['Hosterpk.service_renew.field_renew_submit'] = 'Renew service';

// Domain mutation confirm-before-commit (Story 7.3) — the centralized confirm modal
// (system B chrome) the marker-scoped executor (bootstrap.js) builds when a module
// emits a modal-confirm-{delete|warning|success} trigger inside our owned
// .hpk-service-detail__module frame. These are the CHROME labels only; the
// consequence sentence is module-owned copy carried in data-confirm-message, and the
// confirm button's verb label is the trigger button's own (module-localized) text —
// so the executor never authors English. Voice: present tense, second person, names
// the next action, no exclamation. confirm_fallback is a defensive label used only if
// a future trigger ships with no visible text (real triggers are verb-first).
$lang['Hosterpk.service_confirm.title'] = 'Confirm this change';
$lang['Hosterpk.service_confirm.cancel'] = 'Cancel';
$lang['Hosterpk.service_confirm.close'] = 'Close';
$lang['Hosterpk.service_confirm.confirm_fallback'] = 'Confirm';
// success_announce is the canonical aria-live string set ONCE on the
// #hpk-service-tabpanel-status region after a mutation save re-renders the owned
// panel inline (UX-DR26); the redirect-on-save path shows a post-redirect banner
// instead and needs no announce.
$lang['Hosterpk.service_mutation.success_announce'] = 'Your changes are saved.';

// Domains list (Story 7.1) — plugin-owned domains list (Domains.ClientMain::index).
$lang['Hosterpk.domain.page_title'] = 'My domains';
$lang['Hosterpk.domain.tabs_label'] = 'Domain status';
$lang['Hosterpk.domain.tab_active'] = 'Active';
$lang['Hosterpk.domain.tab_pending'] = 'Pending';
$lang['Hosterpk.domain.tab_suspended'] = 'Suspended';
$lang['Hosterpk.domain.tab_canceled'] = 'Canceled';
$lang['Hosterpk.domain.filter_tld'] = 'TLD';
$lang['Hosterpk.domain.filter_domain_name'] = 'Domain name';
$lang['Hosterpk.domain.filter_generic'] = 'Filter';
$lang['Hosterpk.domain.filter_submit'] = 'Filter domains';
$lang['Hosterpk.domain.heading_domain'] = 'Domain';
$lang['Hosterpk.domain.heading_term'] = 'Term';
$lang['Hosterpk.domain.heading_registration_date'] = 'Registration date';
$lang['Hosterpk.domain.heading_renewal_date'] = 'Renewal date';
$lang['Hosterpk.domain.heading_expiration_date'] = 'Expiration date';
$lang['Hosterpk.domain.heading_suspension_date'] = 'Suspension date';
$lang['Hosterpk.domain.heading_deletion_date'] = 'Deletion date';
$lang['Hosterpk.domain.heading_status'] = 'Status';
$lang['Hosterpk.domain.heading_actions'] = 'Actions';
$lang['Hosterpk.domain.status_active'] = 'Active';
$lang['Hosterpk.domain.status_pending'] = 'Pending';
$lang['Hosterpk.domain.status_suspended'] = 'Suspended';
$lang['Hosterpk.domain.status_canceled'] = 'Canceled';
$lang['Hosterpk.domain.expiring_soon'] = 'Expiring soon';
$lang['Hosterpk.domain.expiring_soon_detail'] = 'Expires %1$s — renew to keep this domain.'; // %1$s is the expiration date
$lang['Hosterpk.domain.auto_renew_off'] = 'Scheduled to cancel';
$lang['Hosterpk.domain.cancel_pending_tooltip'] = 'This domain is scheduled to cancel on %1$s.'; // %1$s is the cancellation date
$lang['Hosterpk.domain.action_manage'] = 'Manage domain';
$lang['Hosterpk.domain.recurring_term'] = '%1$s %2$s at %3$s'; // %1$s term count, %2$s period, %3$s renewal price
$lang['Hosterpk.domain.text_never'] = 'Never';
$lang['Hosterpk.domain.empty_active_title'] = 'No active domains yet';
$lang['Hosterpk.domain.empty_active_body'] = 'Register or transfer a domain and it appears here with its renewal status.';
$lang['Hosterpk.domain.empty_pending_title'] = 'No pending domains';
$lang['Hosterpk.domain.empty_pending_body'] = 'Check this page while registration completes; pending domains appear here.';
$lang['Hosterpk.domain.empty_suspended_title'] = 'No suspended domains';
$lang['Hosterpk.domain.empty_suspended_body'] = 'Review billing or verification notices; suspended domains appear here when action is needed.';
$lang['Hosterpk.domain.empty_canceled_title'] = 'No canceled domains';
$lang['Hosterpk.domain.empty_canceled_body'] = 'Use this page for your records; canceled domains remain listed here.';
$lang['Hosterpk.domain.pagination_label'] = 'Domain pages';

// Account recovery (Story 3.3, FR15 / UX-DR13/22/23/24/27). Warm, migration-aware copy
// for the slim-shell recovery screens that the login card links to (reset, confirmreset,
// forgot). VIEW-ONLY (AR-D8): the .pdt classifies Blesta's rendered $message and swaps in
// these warm strings (3.2 pattern, extended). Headings are the per-page <h2> inside the
// card (the brand panel owns the single <h1>); descriptions are the short lead under it.
// *_sent = the sent-or-neutral success outcome Blesta emits under THIS checkout's privacy-
// preserving defaults (default_password_reset_value / default_forgot_username_value = true),
// where an unknown username/email returns the same success message as a real send. *_unknown
// is forward-compatible: only surfaced if a future config sets those defaults to false and
// Blesta emits its explicit unknown_user / unknown_email error. confirm_error is the summary
// lead on a confirmreset validation failure (the specific rule text shows inline on the
// new_password field). Captcha reuses the shared Hosterpk.login.error_captcha (no recovery-
// specific wording wanted). Voice: second person, present tense, names the next action, no
// exclamation. Page keys validated at point-of-use (NOT state.* keys — see RULE 7 gate).
$lang['Hosterpk.recover.reset_heading'] = 'Reset your password';
$lang['Hosterpk.recover.reset_description'] = 'Enter your username and we\'ll email you a secure reset link.';
$lang['Hosterpk.recover.reset_sent'] = 'If that account exists, we\'ve emailed a reset link — check your inbox.';
$lang['Hosterpk.recover.reset_unknown'] = 'We don\'t recognize that username — check it and try again, or contact support.';
$lang['Hosterpk.recover.confirm_heading'] = 'Set a new password';
$lang['Hosterpk.recover.confirm_description'] = 'Choose a new password for your account.';
$lang['Hosterpk.recover.confirm_error'] = 'We couldn\'t set your new password — please fix the highlighted field.';
$lang['Hosterpk.recover.forgot_heading'] = 'Recover your username';
$lang['Hosterpk.recover.forgot_description'] = 'Enter your email and we\'ll send your username.';
$lang['Hosterpk.recover.forgot_sent'] = 'If that email is on record, we\'ve sent your username — check your inbox.';
$lang['Hosterpk.recover.forgot_unknown'] = 'We don\'t recognize that email — check it and try again, or contact support.';

// Profile & contacts (Story 9.1, FR32 / UX-DR13/24 / UJ-6). Warm, sealed-voice copy for the
// Account group's profile-edit page and the additional/sub-contacts CRUD. PAGE KEYS validated
// at point-of-use (NOT state.* — RULE 7 hard-checks only the 3 canonical state.* keys, reused
// here). The *_summary keys are the danger-alert lead the view-only $message classifier swaps
// in on a validation failure (3.2/3.3 pattern); per-field inline errors come from
// the threaded model $errors array passed to the view/partial by the controller,
// NOT from copy here (the Form helper has no error()/setErrors() method in this Blesta version).
// success keys warm the POST-REDIRECT landing banner (UX-DR24 module-row constraint — no
// inline green panel): contacts add/edit land on the OWNED client_contacts.pdt list and are
// warmed from here; profile lands on the unforked dashboard and rides Blesta's own flash by
// default (OQ5). contacts.empty surfaces as the ADD-PAGE intro, since stock index() redirects
// to contacts/add/ when there are no sub-contacts (OQ4) — it is not a list empty-state.
// delete_confirm is the consequence sentence shown in the stock blestaModalConfirm overlay
// (styled by Story 7.3's general .hpk-client .modal.show chrome). Blesta's own field labels,
// tab headings, and submit/cancel CTAs continue to come from ClientMain.edit.* / ClientContacts.*
// — do not duplicate them here. Voice: second person, present tense, one sentence, names the
// next action, no exclamation. Final wording is OQ3 (lead to ratify at review).
$lang['Hosterpk.profile.success'] = 'Your profile is saved.';
$lang['Hosterpk.profile.error_summary'] = 'We couldn\'t save your profile — fix the highlighted fields below.';
$lang['Hosterpk.contacts.empty'] = 'You haven\'t added any contacts yet — add one to share access.';
$lang['Hosterpk.contacts.add_success'] = 'Contact saved.';
$lang['Hosterpk.contacts.edit_success'] = 'Contact saved.';
$lang['Hosterpk.contacts.error_summary'] = 'We couldn\'t save this contact — fix the highlighted fields below.';
$lang['Hosterpk.contacts.delete_confirm'] = 'Remove this contact? They lose access to your account.';

// Password & security (Story 9.2, FR32 / UX-DR13/24 / UJ-6). The Authentication tab's
// deep affordances: the show/hide toggles reuse Hosterpk.password_toggle.* (above);
// these keys are the password-policy HINT + the live client-side strength hints + the
// security-scoped danger summary. password_requirements_* mirror Blesta's own
// Users.!error.new_password.format_* shape (%1$s = the server-enforced minimum length)
// so the visible hint never disagrees with the format the server actually enforces — the
// .pdt selects the branch from the threaded $password_format (Task 2), defaulting to
// _any. They are VISIBLE help-text shown before submit (fully no-JS). password_too_short
// / password_mismatch are the live, text-based inline hints the hpkPasswordStrength
// Alpine factory toggles on input (length + mismatch only — format/custom-regex stay
// server-authoritative); they never block submit. error_summary is the warm danger lead
// the view-only $message classifier swaps in for an auth/password/2FA/recovery-email
// validation failure (per-field inline errors still come from the threaded $errors
// array, not from copy). Voice: second person, present tense, one sentence, names the
// next action, no exclamation. Final wording is OQ-D (lead to ratify at review).
$lang['Hosterpk.security.password_requirements_any'] = 'Use at least %1$s character(s) for your new password.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_requirements_any_no_space'] = 'Use at least %1$s character(s), with no spaces.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_requirements_alpha_num'] = 'Use at least %1$s character(s), with letters and numbers only.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_requirements_alpha'] = 'Use at least %1$s character(s), with letters only.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_requirements_num'] = 'Use at least %1$s character(s), with numbers only.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_requirements_custom'] = 'Use at least %1$s character(s) that meet your account password rules.'; // %1$s is the minimum length
$lang['Hosterpk.security.password_too_short'] = 'Add more characters to meet the requirement above.';
$lang['Hosterpk.security.password_mismatch'] = 'Enter the same password in both fields.';
$lang['Hosterpk.security.error_summary'] = 'We couldn\'t update your security settings — fix the highlighted fields below.';

// Email & notification preferences (Story 9.3). The checkbox label remains Blesta-owned
// copy; these HosterPK keys add only the group heading and scope help.
$lang['Hosterpk.email_prefs.section_title'] = 'Email & notification preferences';
$lang['Hosterpk.email_prefs.marketing_help'] = 'Use this setting for emails about new products, services, and offers only — account, billing, and service notices are always sent.';

// Support tickets list (Story 8.1, FR29 — plugin-owned ticket list, support_manager
// ClientTickets::index, reached via the support_manager_controller HOSTERPK-SEAM).
// PAGE KEYS validated at point-of-use (NOT state.* — RULE 7 hard-checks only the 3
// canonical state.* keys, reused only as the empty-state fallback). The priority_* /
// status_* labels are the badge text mapped from support_manager's ticket priority /
// status VALUES (Dev Notes §C); the .pdt falls back to Blesta's own localized
// $priorities / $statuses label for any value without an Hosterpk key (e.g. trash).
// The filter_minutes / filter_hour / filter_hours keys rewrite the controller-built
// last-reply dropdown option labels in the owned view.
// Empty copy is per-tab (empty_open_* on the Open tab, empty_closed_* on Closed).
// Voice: second person, present tense, one sentence, names the next action, no exclamation.
$lang['Hosterpk.ticket.page_title'] = 'Support tickets';
$lang['Hosterpk.ticket.tabs_label'] = 'Ticket status';
$lang['Hosterpk.ticket.tab_open'] = 'Open';
$lang['Hosterpk.ticket.tab_closed'] = 'Closed';
$lang['Hosterpk.ticket.heading_code'] = 'Ticket';
$lang['Hosterpk.ticket.heading_priority'] = 'Priority';
$lang['Hosterpk.ticket.heading_department'] = 'Department';
$lang['Hosterpk.ticket.heading_summary'] = 'Summary';
$lang['Hosterpk.ticket.heading_last_reply'] = 'Last reply';
$lang['Hosterpk.ticket.heading_status'] = 'Status';
$lang['Hosterpk.ticket.heading_options'] = 'Options';
$lang['Hosterpk.ticket.priority_low'] = 'Low';
$lang['Hosterpk.ticket.priority_medium'] = 'Medium';
$lang['Hosterpk.ticket.priority_high'] = 'High';
$lang['Hosterpk.ticket.priority_critical'] = 'Critical';
$lang['Hosterpk.ticket.priority_emergency'] = 'Emergency';
$lang['Hosterpk.ticket.status_open'] = 'Awaiting staff';
$lang['Hosterpk.ticket.status_awaiting_reply'] = 'Awaiting your reply';
$lang['Hosterpk.ticket.status_in_progress'] = 'In progress';
$lang['Hosterpk.ticket.status_on_hold'] = 'On hold';
$lang['Hosterpk.ticket.status_closed'] = 'Closed';
$lang['Hosterpk.ticket.empty_open_title'] = 'No open tickets';
$lang['Hosterpk.ticket.empty_open_body'] = 'Open a ticket and our team will reply here as soon as we can.';
$lang['Hosterpk.ticket.empty_closed_title'] = 'No closed tickets';
$lang['Hosterpk.ticket.empty_closed_body'] = 'Closed tickets appear here for your records once a conversation wraps up.';
$lang['Hosterpk.ticket.filter_ticket_number'] = 'Ticket number';
$lang['Hosterpk.ticket.filter_priority'] = 'Priority';
$lang['Hosterpk.ticket.filter_summary'] = 'Summary';
$lang['Hosterpk.ticket.filter_last_reply'] = 'Last reply';
$lang['Hosterpk.ticket.filter_any'] = 'Any';
$lang['Hosterpk.ticket.filter_generic'] = 'Filter';
$lang['Hosterpk.ticket.filter_submit'] = 'Filter tickets';
$lang['Hosterpk.ticket.filter_minutes'] = '%1$s minutes'; // %1$s is the number of minutes
$lang['Hosterpk.ticket.filter_hour'] = '1 hour';
$lang['Hosterpk.ticket.filter_hours'] = '%1$s hours'; // %1$s is the number of hours
$lang['Hosterpk.ticket.action_reply'] = 'Reply';
$lang['Hosterpk.ticket.action_close'] = 'Close';
$lang['Hosterpk.ticket.action_feedback'] = 'Leave feedback';
$lang['Hosterpk.ticket.action_create'] = 'Open a ticket';
$lang['Hosterpk.ticket.pagination_label'] = 'Ticket pages';

// Story 8.2: ticket detail / reply (second HTMX island). Net-new keys only — the
// summary card reuses heading_summary/heading_department/heading_priority/heading_status
// and the priority_*/status_* badge labels from the 8.1 block above (do not redeclare).
$lang['Hosterpk.ticket.detail_title'] = 'Ticket #%1$s'; // %1$s is the ticket code
$lang['Hosterpk.ticket.heading_opened'] = 'Opened';
$lang['Hosterpk.ticket.heading_service'] = 'Related service';
$lang['Hosterpk.ticket.thread_heading'] = 'Conversation';
$lang['Hosterpk.ticket.reply_meta'] = '%1$s · %2$s'; // %1$s is the author, %2$s is the date
$lang['Hosterpk.ticket.reply_you'] = 'You';
$lang['Hosterpk.ticket.reply_staff'] = 'Support';
$lang['Hosterpk.ticket.reply_system'] = 'Support team';
$lang['Hosterpk.ticket.log_entry'] = 'Ticket update';
$lang['Hosterpk.ticket.reply_label'] = 'Add a reply';
$lang['Hosterpk.ticket.reply_placeholder'] = 'Type your reply here';
$lang['Hosterpk.ticket.reply_submit'] = 'Send reply';
$lang['Hosterpk.ticket.close_submit'] = 'Close ticket';
$lang['Hosterpk.ticket.attach_label'] = 'Attachments';
$lang['Hosterpk.ticket.attach_hint'] = 'You can attach %1$s files up to %2$s.'; // %1$s is the accepted types, %2$s is the max size
$lang['Hosterpk.ticket.attach_hint_types'] = 'You can attach %1$s files.'; // %1$s is the accepted types
$lang['Hosterpk.ticket.attach_hint_size'] = 'You can attach files up to %1$s.'; // %1$s is the max size
$lang['Hosterpk.ticket.attach_hint_generic'] = 'You can attach files to this ticket.';
$lang['Hosterpk.ticket.attach_selected'] = 'Selected files';
$lang['Hosterpk.ticket.attach_remove'] = 'Remove %1$s'; // %1$s is the file name (aria-label)
$lang['Hosterpk.ticket.attach_none'] = 'No files are selected yet.';
$lang['Hosterpk.ticket.recipients_toggle'] = 'Add more recipients';
$lang['Hosterpk.ticket.recipients_label'] = 'Email these replies to';
$lang['Hosterpk.ticket.add_recipient'] = 'Add another recipient';
$lang['Hosterpk.ticket.recipient_remove'] = 'Remove this recipient';
$lang['Hosterpk.ticket.recipient_placeholder'] = 'name@example.com';
$lang['Hosterpk.ticket.reply_sent_announce'] = 'Your reply was added to the conversation.';
$lang['Hosterpk.ticket.reply_error_announce'] = 'Your reply could not be sent; review the form and try again.';
$lang['Hosterpk.ticket.thread_loading_announce'] = 'Sending your reply.';
$lang['Hosterpk.ticket.confirm_title'] = 'Close this ticket';
$lang['Hosterpk.ticket.confirm_cancel'] = 'Keep ticket open';
$lang['Hosterpk.ticket.confirm_close_label'] = 'Dismiss this dialog';
$lang['Hosterpk.ticket.confirm_fallback'] = 'Close ticket';
$lang['Hosterpk.ticket.confirm_close_message'] = 'Closing this ticket stops further replies until you reopen it.';

// ----- Ticket create form (Story 8.3) -------------------------------------
// Net-new keys for the owned client_tickets::add view. Reuses attach_*/recipients_*/
// add_recipient/recipient_*/priority_* from the 8.1/8.2 blocks above (do not redeclare).
$lang['Hosterpk.ticket.create_title'] = 'Open a ticket — %1$s'; // %1$s is the department name
$lang['Hosterpk.ticket.create_error_summary'] = 'Fix the highlighted fields and submit again.';
$lang['Hosterpk.ticket.email_label'] = 'Your email address';
$lang['Hosterpk.ticket.subject_label'] = 'Subject';
$lang['Hosterpk.ticket.subject_placeholder'] = 'Summarize your request in a few words';
$lang['Hosterpk.ticket.priority_label'] = 'Priority';
$lang['Hosterpk.ticket.service_label'] = 'Related service';
$lang['Hosterpk.ticket.message_label'] = 'Message';
$lang['Hosterpk.ticket.message_placeholder'] = 'Describe your issue and include any details that help us assist you';
$lang['Hosterpk.ticket.create_submit'] = 'Open ticket';
