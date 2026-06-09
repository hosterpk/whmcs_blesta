---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/prd.md
  - _bmad-output/planning-artifacts/architecture.md
  - _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/EXPERIENCE.md
  - _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/DESIGN.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/addendum.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/reconcile-source-intake.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/review-rubric.md
  - _bmad-output/planning-artifacts/research/technical-kuickpay-blesta-payment-gateway-research-2026-06-09.md
  - _bmad-output/planning-artifacts/implementation-readiness-report-2026-06-09.md
---

# whmcs_blesta - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for whmcs_blesta, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Admin users can install, enable, disable, upgrade, and uninstall the KuickPay Gateway through Blesta non-merchant gateway extension flows without editing Blesta core or removing unrelated data.

FR2: Admin users can configure all KuickPay behavior needed for voucher creation, inquiry, display, and reconciliation, including endpoints, credentials, Institution ID, reference patterns, dates, fallback mobile policy, currency policy, fee policy, instruction groups, logging, reconciliation toggles, and timeouts.

FR3: The gateway stores KuickPay credential passwords encrypted and masks them in all settings screens, logs, diagnostics, customer views, fixtures, docs, and exception paths.

FR4: Admin users can test KuickPay connectivity and credential shape without creating a payable Voucher unless they explicitly run a controlled live voucher test.

FR5: The MVP supports PKR payments only unless an approved currency conversion policy is configured; non-PKR attempts are blocked or routed away with clear messaging.

FR6: When a customer selects KuickPay for an eligible Payment Attempt, the system creates a new Voucher only when no valid Pending Voucher exists for the same invoice context.

FR7: The system generates and stores both Registration Number and Consumer Number for every Voucher using configurable, validated patterns with company-scoped uniqueness.

FR8: The system maps invoice, client, contact, amount, date, mobile, email, branch, and configured payment-head data into the KuickPay voucher request.

FR9: The system persists Voucher state before and after KuickPay interaction so creation, retry, reconciliation, support, and audit paths have durable records and duplicate guards.

FR10: If voucher creation fails or returns an unknown response, the customer sees a safe retry message and the Voucher is marked failed or Manual Review according to parser rules.

FR11: The gateway supports multi-invoice Payment Attempts only when Blesta provides deterministic invoice amount mapping; otherwise it blocks the attempt and asks the customer to pay invoices separately.

FR12: The customer payment page displays Consumer Number, payable amount, due date, expiry date, and KuickPay identity prominently, with a copyable Consumer Number.

FR13: The customer payment page displays only enabled, localized Instruction Groups for supported payment channels such as online banking, bank deposit, agent/franchise, and mobile app.

FR14: The customer payment page explains that Blesta marks the invoice paid only after KuickPay confirmation and shows a Check Payment Status action only when supported.

FR15: The system provides a reusable KuickPay SOAP client for voucher creation, single-reference inquiry, and Bulk Reconciliation, with configured timeouts, TLS validation, credential selection, and sanitized logging.

FR16: The system normalizes KuickPay responses into a stable internal parser result before any product logic consumes them.

FR17: Payment Posting logic cannot rely on unvalidated KuickPay status codes; unknown response codes map to Manual Review or retry, never paid, and successful-code behavior must be covered by fixtures before release.

FR18: The system periodically checks Pending Vouchers for payment status and updates inquiry timestamps, normalized status, parsed data, and Raw Diagnostic Summary without corrupting invoice state on temporary failures.

FR19: Before Payment Posting, the system validates amount, reference identity, invoice mapping, Voucher state, and duplicate transaction status.

FR20: When a KuickPay payment is safely confirmed, the system creates a Blesta transaction, applies it to mapped invoices, records the transaction reference, stores the Blesta transaction ID, and uses a safe transaction boundary where supported.

FR21: The system applies configured business rules for underpayment, overpayment, and payments received after Voucher expiry without silently marking invoices paid.

FR22: The system supports date-based Bulk Reconciliation as a safety net, matching results to stored Consumer Numbers and recording run summaries and unmatched payments.

FR23: The system expires unpaid Vouchers after their configured expiry date while preserving audit history and allowing a new Voucher when the invoice remains unpaid.

FR24: Admin users can search and filter Vouchers by status, client, invoice ID, Consumer Number, date range, amount, KuickPay transaction/auth fields, and related Blesta transaction.

FR25: Admin users can inspect Voucher details including client, invoice mapping, Registration Number, Consumer Number, amount, dates, status, parsed response summary, sanitized diagnostics, posting state, admin notes, and related Blesta records.

FR26: Admin users can safely Check Now, cancel, or mark a Voucher for Manual Review, with admin intent, notes where required, and preserved audit history.

FR27: The system logs KuickPay operations in a structured, sanitized way with operation name, Voucher or correlation ID, sanitized request/response summaries, error class, and timestamp.

FR28: Delivery includes tests for parser behavior, client mapping, idempotency, duplicate prevention, status transitions, amount handling, secret masking, and reference pattern generation.

FR29: Delivery includes opt-in live or sandbox tests for KuickPay credential and response validation, disabled by default and redacting credentials/production data.

FR30: Delivery includes install, configure, reconcile, troubleshoot, rollback, upgrade, and support documentation.

### NonFunctional Requirements

NFR1: Gateway Credential passwords must be encrypted, redacted, and rotatable through Admin Settings with no hard-coded production secrets.

NFR2: KuickPay API failure must not corrupt invoice state; temporary failures keep Vouchers pending unless expiry or admin policy applies.

NFR3: Voucher creation and Payment Posting must be idempotent through lookup checks, durable uniqueness constraints, or equivalent guards.

NFR4: Voucher lifecycle events, inquiry attempts, posting decisions, admin actions, and reconciliation run summaries must be auditable.

NFR5: Product code must follow Blesta extension boundaries, language-file patterns, loader APIs, PHP 8.2 compatibility, and existing project conventions.

NFR6: Customer and admin text must live in Blesta language files and be localizable through the owning gateway or plugin language files.

NFR7: Reconciliation must respect configured timeouts, bounded batches, and avoid unbounded polling loops while rate limits remain an open Phase 0 question.

NFR8: Raw Diagnostic Summary must be admin-only and must not expose secrets or unnecessary customer data.

NFR9: Unknown, malformed, duplicate, mismatched, late, partial, or replayed evidence must fail closed to retry or Manual Review, not paid.

NFR10: Production-specific URLs, credentials, Institution ID, fallback phone, fees, conversion rates, and notification values must be configurable, not hard-coded.

NFR11: Automated tests must not call live KuickPay endpoints by default; live or sandbox checks require explicit protected configuration.

NFR12: Root PHPUnit coverage must not be claimed unless the intended sibling `../tests` suite is present; fallback verification must state exactly what was run.

NFR13: Amount comparisons must use normalized decimal strings or integer minor units, never PHP floats.

NFR14: Admin mutations must require Blesta staff authentication, plugin ACL, POST/CSRF flow, and audit records; GET routes must remain read-only.

### Additional Requirements

- Use a Blesta-native gateway-plus-plugin scaffold, not a generic PHP, Laravel, Symfony, Node, Docker, queue, or standalone app starter.
- Place checkout, gateway metadata, encrypted gateway settings, PKR eligibility, safe connection test, customer reference display, and protocol classes under `components/gateways/nonmerchant/kuickpay/`.
- Place durable Voucher state, reconciliation runs, schema lifecycle, admin workbench, cron, locks, audit records, and posting services under `plugins/kuickpay_reconcile/`.
- The companion plugin is a hard MVP dependency for durable state, reconciliation, admin operations, Manual Review, run summaries, and safe posting.
- Gateway code must not own Voucher persistence, reconciliation, paid-state decisions, retry logic, audit logging, schema lifecycle, cron work, or admin review screens.
- The first implementation story must create the gateway-plus-plugin scaffold with safe placeholder behavior and no live payment mutation.
- Critical initial file targets include `kuickpay.php`, gateway `config.json`, gateway language/settings/process templates, gateway `lib/` protocol classes, plugin `config.json`, plugin lifecycle file, plugin controllers, plugin models, plugin services, plugin language files, plugin views, and plugin fixture directories.
- Use plugin-owned tables including `kuickpay_vouchers`, `kuickpay_voucher_invoices`, `kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_reconcile_locks`, and `kuickpay_audit_events`.
- Canonical Voucher states are `pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, and `cancelled`.
- Only `posted` may imply that a Blesta invoice payment succeeded; `confirmed_unposted` is validated evidence only.
- Voucher creation must atomically create Voucher and invoice link records and enforce company-scoped Registration Number, Consumer Number, active payment context, KuickPay reference, and Blesta transaction uniqueness where applicable.
- Payment Posting must lock or compare-and-update the Voucher and invoice mapping rows, revalidate status, amount, currency, reference, invoice mapping, and duplicate state, create/apply the Blesta transaction, then transition to `posted`.
- Only `KuickPayPostingService` may create or apply a Blesta transaction.
- SOAP calls must go through a dedicated KuickPay client wrapper around PHP `SoapClient`; controllers, views, cron, and posting services must not call raw SOAP directly.
- Required SOAP operations are `InsertVoucher`, `BillPaymentInquiry`, and `BillPaymentBulkInquiry`; optional safe setup operations are `Echo` and `GetInstitutionsList`.
- Raw SOAP/XML must flow through redactor and parser before business logic; product code consumes normalized parser output only.
- Normalized parser fields must include status, error class, reference, Consumer Number, Registration Number, amount, currency, payment date, raw status, redacted trace ID, evidence hash, and validation errors.
- Allowed error classes include timeout, transport error, credential error, malformed response, unknown status, amount mismatch, duplicate reference, and unmatched reference.
- Bulk XML parsing must use bounded payloads and safe XML handling; malformed, unknown, duplicate, unmatched, and mismatched cases map to explicit error classes.
- Retry inquiry and bulk operations conservatively; do not blindly retry `InsertVoucher` unless local idempotency proves it safe.
- Admin ACL must separate permissions for viewing records, running recheck, adding review notes, canceling or closing Vouchers, viewing diagnostics, and any future posting-capable action.
- No "force paid" admin action exists in MVP.
- Customer views must be scoped to authenticated client invoices and must never trust route parameters or browser/customer-side data for paid state.
- Reconciliation cron must be database-locked, bounded by batch size and max runtime, idempotent, resumable, retry-limited, stale-lock aware, and observable through run summaries.
- Audit records are durable business history and are required for parser outcomes, reconciliation runs, state transitions, admin decisions, retry decisions, posting attempts, posting success, and posting failure.
- Audit event names use lower dot notation such as `voucher.created`, `voucher.issued`, `evidence.received`, `evidence.matched`, `evidence.rejected`, `posting.started`, `posting.succeeded`, `posting.failed`, and `admin.reviewed`.
- Rollback must disable the KuickPay gateway and plugin cron while preserving Voucher, audit, and payment evidence tables until a separate archival or purge policy exists.
- Implementation verification must include `php -l` for changed PHP files, parser fixture checks, install/upgrade smoke checks when runtime/database are available, cron/manual reconciliation smoke checks in staging, rollback smoke checks, and no root PHPUnit claim unless `../tests` exists.
- Phase 0 must confirm production Blesta version, KuickPay endpoint/WSDL, date formats, Consumer Number formula, credential separation, rate limits, polling guidance, and sanitized fixtures before Payment Posting is enabled.
- Phase 0 fixture coverage must include InsertVoucher success, duplicate, invalid credentials, malformed result, timeout; BillPaymentInquiry pending, paid exact amount, amount mismatch, expired, unknown; and Bulk Reconciliation matched paid, unmatched, and malformed XML.
- Open production-gate decisions remain for partial payments, overpayments, late payments, fee policy, multi-invoice support, callback/IPN support, refunds, reversals, voucher cancellation, audit retention, and destructive purge policy.
- Deployment and support documentation should live under `docs/kuickpay/` for implementation boundaries, deployment checklist, operator runbook, reconciliation runbook, admin-review runbook, rollback runbook, support troubleshooting, and testing fixtures.

### UX Design Requirements

UX-DR1: The customer, support, finance, and admin experience must remain inside Blesta responsive web surfaces, not a standalone checkout microsite, mobile app, or custom visual system.

UX-DR2: KuickPay appears in Payment Method Selection only when enabled for the company and eligible currency; non-PKR invoices are hidden or blocked with clear copy and no Voucher creation.

UX-DR3: The Customer Reference Panel must always show KuickPay identity, Consumer Number, payable amount, due date, expiry date, and payment-status expectation before instruction groups.

UX-DR4: The Consumer Number block must include a keyboard-reachable Copy action that copies only the Consumer Number and gives temporary feedback without changing payment state.

UX-DR5: Instruction Groups must render only when enabled, use localized/admin-configured text, and be independently scannable by channel.

UX-DR6: The Status Expectation Block must state that Blesta marks the invoice paid after KuickPay confirmation and include a support path when supplied.

UX-DR7: Voucher creation failure or KuickPay timeout must show retry-safe customer copy, keep the invoice unpaid, and avoid raw SOAP details.

UX-DR8: Gateway Settings must group endpoints, credentials, Institution ID, reference patterns, date policies, phone policy, instruction groups, logging, reconciliation, currency, and fee behavior.

UX-DR9: Gateway Settings must validate required fields inline and through Blesta message patterns, mask credentials on display, and support credential rotation.

UX-DR10: Safe Connection Test must test endpoint or credential shape without creating a payable Voucher; any live voucher test must require explicit confirmation and clear test labeling.

UX-DR11: Voucher List must support direct, fast search/filter by status, client, invoice ID, Consumer Number, date range, amount, transaction/auth fields, and Blesta transaction link.

UX-DR12: Voucher List filters must remain visible and preserve selected state after an action or return from Voucher Detail.

UX-DR13: Voucher Detail must show full Voucher state, invoice mapping, Registration Number, Consumer Number, amount, dates, parsed response summary, sanitized diagnostics, posting state, admin notes, and related invoice/transaction links.

UX-DR14: Voucher Detail diagnostics must be admin-only, sanitized, readable by keyboard, and scroll within the diagnostic block when long.

UX-DR15: Manual actions such as Check Now, Mark Manual Review, Cancel Voucher, note edits, and Bulk Reconciliation must require admin intent, show result messages, preserve audit history, and use normal Blesta form submit flows.

UX-DR16: Manual Review must have a queue or filterable workbench and a callout on detail/run surfaces that includes the reason and next action, not only a generic label.

UX-DR17: Reconciliation Run Summary must show run type, status, checked, posted, unmatched, failed, skipped, Manual Review counts, timestamps, and failure class without exposing secrets.

UX-DR18: Bulk Reconciliation must use a transaction-date input, show a run summary, and route unmatched rows into staff review without requiring staff to infer invoice identity.

UX-DR19: Status badges must communicate state with text plus optional icon/color; color alone is not the status contract.

UX-DR20: Success styling, "Payment received" wording, paid receipts, or green-check treatment must appear only after the `posted` state.

UX-DR21: Loading states for customer checks or admin actions must not blank an existing Consumer Number, last known status, or admin evidence.

UX-DR22: Customer instructions must remain usable on small screens without horizontal scrolling; customer phone layout is single-column with reference and amount above instructions.

UX-DR23: Admin tables must use Blesta responsive table wrappers and keep primary actions reachable without hover.

UX-DR24: Accessibility target is WCAG 2.2 AA for customer and admin surfaces, including accessible labels, semantic alerts, meaningful table headers, keyboard focus order, and non-color status communication.

UX-DR25: Visual design must inherit Blesta theme, widgets, forms, alerts, badges, tables, Bootstrap-derived spacing, and semantic colors; no marketing shadows, gradients, floating panels, or standalone KuickPay visual system.

UX-DR26: Use monospace only for Consumer Number, Registration Number, KuickPay transaction/auth/reference fields, and sanitized diagnostic evidence; general instructions use normal inherited typography.

UX-DR27: Empty and no-result states must use plain language, including "No Vouchers match these filters," while keeping filters visible.

UX-DR28: Customer/admin strings must be language-file driven and must not expose raw diagnostics, credentials, parser fields, SOAP operation names, stack traces, or internal exception classes.

### FR Coverage Map

FR1: Epic 1 - Safe KuickPay Gateway Enablement
FR2: Epic 1 - Safe KuickPay Gateway Enablement
FR3: Epic 1 - Safe KuickPay Gateway Enablement
FR4: Epic 1 - Safe KuickPay Gateway Enablement
FR5: Epic 1 - Safe KuickPay Gateway Enablement
FR6: Epic 2 - Customer Voucher Payment Reference
FR7: Epic 2 - Customer Voucher Payment Reference
FR8: Epic 2 - Customer Voucher Payment Reference
FR9: Epic 2 - Customer Voucher Payment Reference
FR10: Epic 2 - Customer Voucher Payment Reference
FR11: Epic 2 - Customer Voucher Payment Reference
FR12: Epic 2 - Customer Voucher Payment Reference
FR13: Epic 2 - Customer Voucher Payment Reference
FR14: Epic 2 - Customer Voucher Payment Reference
FR15: Epic 3 - Trusted Reconciliation and Safe Posting
FR16: Epic 3 - Trusted Reconciliation and Safe Posting
FR17: Epic 3 - Trusted Reconciliation and Safe Posting
FR18: Epic 3 - Trusted Reconciliation and Safe Posting
FR19: Epic 3 - Trusted Reconciliation and Safe Posting
FR20: Epic 3 - Trusted Reconciliation and Safe Posting
FR21: Epic 3 - Trusted Reconciliation and Safe Posting
FR22: Epic 3 - Trusted Reconciliation and Safe Posting
FR23: Epic 3 - Trusted Reconciliation and Safe Posting
FR24: Epic 4 - Admin Support and Manual Review Operations
FR25: Epic 4 - Admin Support and Manual Review Operations
FR26: Epic 4 - Admin Support and Manual Review Operations
FR27: Epic 4 - Admin Support and Manual Review Operations
FR28: Epic 3 - Trusted Reconciliation and Safe Posting
FR29: Epic 5 - Launch Validation and Operational Handoff
FR30: Epic 5 - Launch Validation and Operational Handoff

## Epic List

### Epic 0: Phase 0 - KuickPay Contract Validation and Fixture Gate
Operators and developers confirm the KuickPay integration contract and capture sanitized fixtures so that downstream voucher issuance and payment posting build on verified evidence, not assumptions. This epic is a prerequisite gate: its single story must be approved before Story 2.3 success-path handling and before Epic 3. Epic numbering reflects user-value grouping, not strict build order.
**FRs covered:** Prerequisite gate for FR8, FR10, FR16, FR17 (introduces no new FR); supports FR29.

### Epic 1: Safe KuickPay Gateway Enablement
Admins can install, configure, secure, and safely test KuickPay as a PKR-only Blesta non-merchant gateway before customers use it.
**FRs covered:** FR1, FR2, FR3, FR4, FR5.

### Epic 2: Customer Voucher Payment Reference
Eligible customers can create or reuse a KuickPay Voucher, see a clear Consumer Number, amount, dates, and instructions, and understand that payment is confirmed later.
**FRs covered:** FR6, FR7, FR8, FR9, FR10, FR11, FR12, FR13, FR14.

### Epic 3: Trusted Reconciliation and Safe Posting
Finance can trust KuickPay evidence, reconcile pending Vouchers, validate amount/reference/invoice state, and post only confirmed payments through Blesta.
**FRs covered:** FR15, FR16, FR17, FR18, FR19, FR20, FR21, FR22, FR23, FR28.

### Epic 4: Admin Support and Manual Review Operations
Support and finance staff can find Vouchers, inspect safe diagnostics, run approved actions, and resolve ambiguous or delayed payments without unsafe paid-state shortcuts.
**FRs covered:** FR24, FR25, FR26, FR27.

### Epic 5: Launch Validation and Operational Handoff
Operators can run opt-in live/sandbox checks and use deployment, reconciliation, troubleshooting, rollback, upgrade, and support documentation for production rollout.
**FRs covered:** FR29, FR30.

## Epic 0: Phase 0 - KuickPay Contract Validation and Fixture Gate

Operators and developers confirm the KuickPay integration contract and capture sanitized fixtures before any payment-truth logic is built, so that voucher issuance and payment posting rely on verified evidence rather than assumed response formats. This is a release gate, not a feature: it owns the external KuickPay dependency that PRD Open Question #2 (exact response formats) and the architecture Phase 0 gate describe. Payment posting stays disabled until this gate is approved.

> **Sequencing:** Story 0.1 must be approved before Story 2.3 success-path handling (Epic 2) and before Epic 3 (parser, reconciliation, posting). Epic 1 (scaffold, settings, credentials, connection test, PKR eligibility) may proceed in parallel with Phase 0.

### Story 0.1: Confirm KuickPay Contract and Capture Sanitized Fixtures

As an operator and developer,
I want the KuickPay integration contract confirmed and sanitized fixtures captured before payment-truth logic is built,
So that voucher issuance and payment posting rely on verified evidence rather than assumed response formats.

**Acceptance Criteria:**

**Given** the production target and integration contract are being confirmed
**When** Phase 0 validation runs
**Then** the production Blesta version (5.13 stable versus 6.0 beta compatibility), KuickPay endpoint/WSDL, accepted date formats (due, expiry, issue, transaction), the Consumer Number formula for this merchant, and credential separation (voucher versus inquiry) are documented and approved
**And** no production credential, Institution ID, endpoint, or fallback value is hard-coded into business logic as a result.

**Given** KuickPay responses are obtained from a live or sandbox source
**When** fixtures are captured
**Then** sanitized fixtures exist for `InsertVoucher` (success, duplicate, invalid credentials, malformed result, timeout), `BillPaymentInquiry` (pending, paid exact amount, amount mismatch, expired, unknown), and Bulk Reconciliation (matched paid, unmatched, malformed XML)
**And** passwords, unredacted SOAP envelopes, customer secrets, and environment-specific values are excluded.

**Given** rate limits and polling guidance are initially unknown
**When** Phase 0 completes
**Then** documented KuickPay rate limits and recommended polling/backoff guidance are recorded
**Or** they are explicitly flagged as unavailable with a conservative default to use until confirmed.

**Given** Phase 0 has not been approved
**When** implementation reaches voucher success-path handling (Story 2.3) or parser/posting work (Epic 3)
**Then** payment posting remains disabled
**And** those slices must not consume unverified KuickPay status codes; unknown responses continue to map to retry or Manual Review, never paid.

## Epic 1: Safe KuickPay Gateway Enablement

Admins can install, configure, secure, and safely test KuickPay as a PKR-only Blesta non-merchant gateway before customers use it.

### Story 1.1: Install KuickPay Gateway and Companion Plugin Scaffold

As an admin operator,
I want KuickPay delivered as a Blesta-native gateway plus companion plugin scaffold,
So that the integration can be installed safely without modifying Blesta core.

**Acceptance Criteria:**

**Given** the KuickPay extension files are deployed
**When** Blesta scans extensions
**Then** KuickPay is detectable as a non-merchant gateway
**And** `kuickpay_reconcile` is detectable as the companion plugin.

**Given** the scaffold is installed, upgraded, disabled, or uninstalled
**When** the extension lifecycle runs
**Then** it does not modify Blesta core files
**And** it does not remove unrelated Blesta or extension data.

**Given** the companion plugin is missing or not installed
**When** an admin attempts to configure or use the gateway
**Then** the gateway shows a clear admin setup error
**And** no customer Voucher or payment mutation path is enabled.

### Story 1.2: Configure KuickPay Gateway Settings

As an admin operator,
I want to configure KuickPay endpoints, credentials, Institution ID, reference patterns, date policies, instruction groups, logging, reconciliation, currency, fee, and timeout behavior,
So that production-specific values are controlled without code changes.

**Acceptance Criteria:**

**Given** the admin opens KuickPay settings
**When** the settings form renders
**Then** settings are grouped using Blesta-native form patterns
**And** all customer/admin labels come from language files.

**Given** required settings are missing or invalid
**When** the admin saves
**Then** validation errors appear through Blesta `Input`/message patterns
**And** empty required values, invalid HTTPS URLs, invalid numeric fields, and invalid reference patterns are rejected.

**Given** settings are saved successfully
**When** the gateway later builds voucher or inquiry requests
**Then** it uses configured values
**And** no production URL, Institution ID, fallback phone, fee, conversion rate, or credential is hard-coded in business logic.

### Story 1.3: Encrypt and Mask KuickPay Credentials

As an admin operator,
I want KuickPay credential passwords encrypted and masked,
So that sensitive gateway access is protected during setup, diagnostics, and support.

**Acceptance Criteria:**

**Given** voucher and inquiry passwords are configured
**When** Blesta stores gateway meta
**Then** password fields are included in `encryptableFields()` or equivalent Blesta-supported encryption.

**Given** settings, logs, diagnostics, fixtures, docs, or exception messages are displayed
**When** credential fields are present
**Then** password values are masked or redacted
**And** raw credential values never appear.

**Given** the same-as-voucher credential toggle is enabled
**When** inquiry settings are saved
**Then** duplicate password storage is avoided where the Blesta setting pattern allows
**And** display remains masked.

### Story 1.4: Run Safe KuickPay Connection Tests

As an admin operator,
I want to test KuickPay connectivity without accidentally creating a payable Voucher,
So that setup can be verified safely before customer use.

**Acceptance Criteria:**

**Given** an admin runs the normal connection test
**When** the gateway contacts KuickPay
**Then** the test reports success, credential failure, endpoint unavailable, or timeout
**And** it does not create a payable Voucher or mark any invoice paid.

**Given** KuickPay supports a safe metadata operation such as `Echo` or `GetInstitutionsList`
**When** connection testing is configured
**Then** the gateway prefers that operation over `InsertVoucher`.

**Given** an admin requests a live voucher test
**When** the action is submitted
**Then** explicit admin confirmation is required
**And** any resulting test record is clearly labeled as a test.

### Story 1.5: Enforce PKR-Only Gateway Eligibility

As an admin and customer,
I want KuickPay available only for eligible PKR invoices,
So that unsupported currencies cannot create unsafe payment references.

**Acceptance Criteria:**

**Given** KuickPay is enabled for the company
**When** a customer views payment options for a PKR invoice
**Then** KuickPay can appear as a non-merchant payment option.

**Given** a customer views payment options for a non-PKR invoice
**When** KuickPay eligibility is evaluated
**Then** KuickPay is hidden or blocked with clear localized copy
**And** no Voucher is created.

**Given** currency behavior is configured
**When** an admin reviews KuickPay settings
**Then** the PKR-first policy is visible
**And** no USD-to-PKR or other conversion value is hard-coded.

## Epic 2: Customer Voucher Payment Reference

Eligible customers can create or reuse a KuickPay Voucher, see a clear Consumer Number, amount, dates, and instructions, and understand that payment is confirmed later.

### Story 2.1: Create Durable Customer Voucher Records

As a customer paying an eligible invoice,
I want my KuickPay payment attempt stored durably,
So that page refreshes, retries, support checks, and reconciliation use the same payment reference.

**Acceptance Criteria:**

**Given** an eligible invoice payment attempt starts
**When** the system prepares a KuickPay Voucher record
**Then** it stores company, gateway, client, invoice mapping, currency, amount, dates, status, Registration Number, Consumer Number, and diagnostic placeholders
**And** it creates only the Voucher and invoice-link persistence needed for customer payment attempts.

**Given** a Voucher record already exists for the same active invoice context
**When** the customer reloads or returns to the payment page
**Then** the system reuses the existing Pending Voucher
**And** it does not create a duplicate active Voucher.

### Story 2.2: Generate Registration Number and Consumer Number

As a customer paying through KuickPay,
I want one stable Consumer Number for my invoice,
So that I can pay through my bank, wallet, branch, ATM, agent, or payment app without guessing.

**Acceptance Criteria:**

**Given** an eligible Voucher is created
**When** reference generation runs
**Then** the default Registration Number uses random prefix plus invoice ID
**And** the default Consumer Number uses Institution ID plus Registration Number.

**Given** reference patterns are configured
**When** invalid pattern values would produce empty, duplicate, or malformed references
**Then** the Voucher is not issued
**And** an admin-safe validation or diagnostic error is recorded.

**Given** company-scoped references exist
**When** a new Voucher is created
**Then** Registration Number and Consumer Number uniqueness are enforced
**And** duplicates fail closed rather than producing a second active reference.

### Story 2.3: Map Invoice Data and Issue KuickPay Voucher

As a customer paying an eligible invoice,
I want Blesta to send the correct invoice and contact details to KuickPay,
So that the Consumer Number is payable for the exact amount I owe.

**Dependencies (sequencing):** This story must not begin until (a) **Story 0.1** Phase 0 contract validation and fixtures are approved, (b) the `InsertVoucher` path of **Story 3.1** (KuickPay SOAP client wrapper) exists, and (c) the voucher-creation-response cases of **Story 3.2** (normalized parser + fixtures) exist. Epic numbering reflects user-value grouping, not build order: the success-path and unknown-response acceptance criteria below depend on the parser rules delivered in Story 3.2 (see FR10, which posts "according to parser rules") and the SOAP client delivered in Story 3.1 (see FR15).

**Acceptance Criteria:**

**Given** an eligible PKR invoice and configured KuickPay settings
**When** the Voucher request is built
**Then** the request maps amount, payment head, due date, expiry date, issue date, client name, mobile, email, branch, Institution ID, and Registration Number according to configured policies.

**Given** a client mobile number is invalid or non-Pakistani
**When** the request is built
**Then** the configured fallback mobile policy is applied
**And** no hard-coded fallback phone is used.

**Given** KuickPay rejects customer contact data such as email during `InsertVoucher`
**When** the voucher creation response is processed
**Then** no payable Voucher is shown unless KuickPay successfully issued it
**And** the customer sees localized safe copy
**And** admins can inspect a sanitized provider validation reason
**And** any fallback email policy is configurable, not hard-coded.

**Given** KuickPay returns a voucher creation success response covered by accepted fixture behavior
**When** the response is processed
**Then** the Voucher remains unpaid and Pending
**And** stored creation evidence is sanitized.

**Given** KuickPay times out, fails, or returns an unknown voucher creation response
**When** the response is processed
**Then** the customer sees retry-safe copy
**And** the Voucher is marked failed, retry, or Manual Review according to safe parser rules.

### Story 2.4: Gate Changed Amounts and Multi-Invoice Attempts

As a customer paying through KuickPay,
I want the gateway to block ambiguous payment attempts,
So that the reference I pay cannot be misapplied to the wrong invoice amount.

**Acceptance Criteria:**

**Given** a Pending Voucher exists
**When** the invoice amount or invoice mapping has changed
**Then** the system follows the configured replacement or blocking policy
**And** it does not silently reuse a stale amount.

**Given** a customer has a Pending Voucher and staff applies a discount to the invoice
**When** the customer returns to the KuickPay payment page
**Then** the gateway detects that the stored Voucher amount no longer matches the invoice payable amount
**And** the stale Consumer Number is not shown as payable for the discounted invoice.

**Given** the configured policy allows replacement after an invoice discount
**When** the customer requests KuickPay payment for the updated invoice
**Then** the old local Voucher is retired from active reuse while preserving audit history
**And** a new Voucher is created or issued for the updated payable amount.

**Given** a customer attempts to pay multiple invoices
**When** deterministic invoice amount allocation is not implemented or not enabled
**Then** the gateway blocks the attempt with localized customer copy
**And** no Voucher is created.

**Given** multi-invoice support is enabled in a later policy
**When** the attempt is accepted
**Then** each invoice ID and amount allocation is stored deterministically.

### Story 2.5: Display Customer Payment Reference Panel

As a customer,
I want the Consumer Number, amount, due date, expiry date, and KuickPay identity shown clearly,
So that I can complete payment through a supported external channel.

**Acceptance Criteria:**

**Given** a Pending Voucher exists
**When** the customer views the KuickPay payment page
**Then** Consumer Number, payable amount, due date, expiry date, and KuickPay identity appear before instructions
**And** the layout is usable on phone and desktop widths without horizontal scrolling.

**Given** the customer uses the Copy Consumer Number action
**When** the action completes
**Then** only the Consumer Number is copied
**And** temporary feedback is shown without changing payment state.

**Given** the Voucher is Pending, retrying, failed, expired, Manual Review, or posted
**When** the customer views the page
**Then** customer status copy is conservative and localized
**And** success styling appears only when the Voucher is posted.

### Story 2.6: Display Configurable Payment Instructions and Status Expectations

As a customer,
I want clear channel-specific KuickPay instructions and payment-status expectations,
So that I know how to pay and when Blesta will update the invoice.

**Acceptance Criteria:**

**Given** instruction groups are configured
**When** the customer views the KuickPay payment page
**Then** only enabled instruction groups are displayed
**And** disabled groups are not rendered.

**Given** customer-facing copy is displayed
**When** the page renders
**Then** instruction text, labels, support path copy, validation messages, and status expectations come from language/config patterns
**And** no raw SOAP, parser fields, credentials, or internal exception classes are shown.

**Given** customer-side refresh, return, copy action, or Check Payment Status is used
**When** the page updates
**Then** the invoice is not marked paid from customer-side behavior
**And** the page explains that Blesta updates only after KuickPay confirmation.

## Epic 3: Trusted Reconciliation and Safe Posting

Finance can trust KuickPay evidence, reconcile pending Vouchers, validate amount/reference/invoice state, and post only confirmed payments through Blesta.

### Story 3.1: Wrap KuickPay SOAP Operations

As a finance operator,
I want KuickPay SOAP calls isolated behind a safe client,
So that reconciliation uses configured, redacted, timeout-bound provider communication.

**Sequencing note:** Although this story lives in Epic 3, its `InsertVoucher` operation is a prerequisite of **Story 2.3** (voucher issuance in Epic 2). Deliver the `InsertVoucher` path early enough to unblock Story 2.3; the `BillPaymentInquiry` and `BillPaymentBulkInquiry` operations may follow with the rest of Epic 3.

**Acceptance Criteria:**

**Given** configured KuickPay credentials and endpoints
**When** the system calls `InsertVoucher`, `BillPaymentInquiry`, or `BillPaymentBulkInquiry`
**Then** calls go through the KuickPay SOAP client wrapper
**And** configured timeouts, TLS validation, credential selection, and sanitized logging are applied.

**Given** SOAP faults, transport failures, or timeouts occur
**When** the client returns a result
**Then** it returns a structured transport outcome
**And** it never decides paid/not-paid status.

### Story 3.2: Normalize KuickPay Evidence with Fixtures

As a developer and finance operator,
I want raw KuickPay responses normalized into a stable evidence contract,
So that payment decisions never depend on opaque SOAP strings.

**Sequencing note:** The voucher-creation-response cases of this parser (success, unknown, malformed, timeout for `InsertVoucher`) are a prerequisite of **Story 2.3** and depend on **Story 0.1** Phase 0 fixtures. Deliver the creation-response parsing and its fixtures early enough to unblock Story 2.3; inquiry and bulk parsing cases may follow with the rest of Epic 3.

**Acceptance Criteria:**

**Given** raw voucher, inquiry, or bulk responses
**When** the parser processes them
**Then** output includes normalized status, error class, reference fields, amount, currency, paid date, redacted trace ID, evidence hash, and validation errors.

**Given** unknown, malformed, duplicate, unmatched, mismatched, pending, failed, expired, and successful fixture cases
**When** parser tests run
**Then** only fixture-backed confirmed-payment behavior can produce confirmed evidence
**And** unknown cases map to retry or Manual Review, never paid.

### Story 3.3: Reconcile Pending Vouchers by Single Inquiry

As a finance operator,
I want Pending Vouchers checked on a schedule or by approved manual trigger,
So that confirmed payments can move toward posting without staff re-entry.

**Acceptance Criteria:**

**Given** Pending Vouchers exist
**When** scheduled reconciliation runs
**Then** it checks eligible Vouchers through single-reference inquiry
**And** updates last inquiry timestamp, normalized status, parsed evidence, and redacted diagnostics.

**Given** the provider is temporarily unavailable
**When** reconciliation fails due to timeout or transport error
**Then** Vouchers remain pending or retry according to policy
**And** no invoice state is corrupted.

### Story 3.4: Validate Confirmed Payment Evidence

As a finance operator,
I want confirmed KuickPay evidence validated before posting,
So that wrong amounts, references, invoice mappings, or duplicates cannot pay an invoice.

**Acceptance Criteria:**

**Given** parser evidence indicates payment confirmation
**When** validation runs
**Then** amount, currency, Consumer Number, Registration Number, invoice mapping, Voucher state, and duplicate transaction references are checked.

**Given** validation finds amount mismatch, duplicate reference, unmatched reference, stale Voucher, or invoice mismatch
**When** the result is recorded
**Then** the Voucher moves to retry or Manual Review as appropriate
**And** no Blesta transaction is created.

### Story 3.5: Post Safe Blesta Transactions

As a finance operator,
I want safely confirmed KuickPay payments posted through Blesta's normal transaction path,
So that invoices are paid only after validated evidence and duplicate checks.

**Acceptance Criteria:**

**Given** a Voucher has validated confirmed evidence
**When** `KuickPayPostingService` posts payment
**Then** it re-reads and locks or compare-updates the Voucher, revalidates amount/reference/invoice state, creates/applies the Blesta transaction, stores the transaction ID, and transitions the Voucher to `posted`.

**Given** posting fails after confirmation evidence exists
**When** the transaction rolls back
**Then** the Voucher does not become `posted`
**And** confirmation evidence remains available for retry or Manual Review.

### Story 3.6: Handle Expiry, Late, Partial, and Overpayment Cases

As a finance operator,
I want policy-sensitive payment exceptions handled explicitly,
So that unsafe payment states do not silently mark invoices paid.

**Acceptance Criteria:**

**Given** a Voucher passes its configured expiry date unpaid
**When** expiry processing runs
**Then** it transitions out of active Pending state
**And** the customer can generate a new Voucher if the invoice remains unpaid.

**Given** payment evidence is underpaid, overpaid, late after expiry, or policy-dependent
**When** validation runs
**Then** configured policy is applied
**And** unsupported or unsafe cases go to Manual Review without full invoice payment.

### Story 3.7: Run Bulk Reconciliation Safety Net

As a finance operator,
I want date-based Bulk Reconciliation,
So that missed single-reference payments can be found and reviewed safely.

**Acceptance Criteria:**

**Given** an authorized bulk run date
**When** Bulk Reconciliation runs
**Then** returned rows are matched only by stored Consumer Numbers
**And** suffix inference is never used.

**Given** bulk rows are matched, unmatched, malformed, duplicate, or mismatched
**When** the run completes
**Then** matched confirmed rows use the same validation/posting rules as single inquiry
**And** unmatched or unsafe rows are recorded for Manual Review with a run summary.

### Story 3.8: Verify Payment-Safety Contracts

As a developer and finance operator,
I want automated and fallback verification for reconciliation and posting behavior,
So that unsafe payment mutations are caught before release.

**Acceptance Criteria:**

**Given** parser, reconciliation, idempotency, duplicate-prevention, status-transition, amount-handling, masking, and pattern-generation checks exist
**When** the relevant test suite runs
**Then** unknown responses, duplicate posting, mismatched amounts, and secret leakage are covered.

**Given** the sibling Blesta test suite or database runtime is unavailable
**When** verification is reported
**Then** the fallback checks actually run are listed
**And** lint-only or fixture-only checks are not claimed as full root PHPUnit coverage.

## Epic 4: Admin Support and Manual Review Operations

Support and finance staff can find Vouchers, inspect safe diagnostics, run approved actions, and resolve ambiguous or delayed payments without unsafe paid-state shortcuts.

### Story 4.1: Search and Filter KuickPay Vouchers

As a support agent,
I want to search and filter KuickPay Vouchers,
So that I can quickly find a customer's payment attempt.

**Acceptance Criteria:**

**Given** the admin opens the KuickPay Voucher List
**When** filters are available
**Then** staff can filter by status, client, invoice ID, Consumer Number, date range, amount, KuickPay transaction/auth fields, and Blesta transaction link.

**Given** filters are applied
**When** results render
**Then** list rows show created date, client, invoice mapping, amount, Consumer Number, status, last inquiry time, and transaction link when paid
**And** filters remain visible and selected after returning from detail or actions.

**Given** no records match
**When** the list renders
**Then** it shows a localized no-results message
**And** keeps filters visible.

### Story 4.2: Inspect Voucher Details and Safe Diagnostics

As a support agent,
I want a complete Voucher detail page with safe evidence,
So that I can explain pending, paid, failed, expired, or Manual Review states.

**Acceptance Criteria:**

**Given** an admin opens Voucher Detail
**When** the page renders
**Then** it shows client, invoice mapping, Registration Number, Consumer Number, amount, dates, current status, parsed response summary, posting state, admin notes, and related Blesta invoice/transaction links.

**Given** diagnostics are available
**When** the admin has permission to view diagnostics
**Then** sanitized request/response summaries are visible
**And** raw passwords, unredacted SOAP, customer-facing secrets, and internal stack traces are not shown.

**Given** diagnostic content is long
**When** the detail page renders
**Then** it remains keyboard-readable in a contained block
**And** it does not break the admin layout.

### Story 4.3: Run Safe Manual Voucher Actions

As a support or finance admin,
I want approved manual actions on a Voucher,
So that delayed or ambiguous payments can be checked or routed without unsafe shortcuts.

**Acceptance Criteria:**

**Given** an authorized admin clicks Check Now
**When** the action is submitted by POST with Blesta auth/ACL/CSRF protection
**Then** inquiry runs through the same parser, validation, and posting path as scheduled reconciliation
**And** the result message is shown.

**Given** an admin marks a Voucher for Manual Review
**When** the action is submitted
**Then** an admin note is required
**And** the Voucher state, reason, note, timestamp, and staff attribution are preserved where Blesta patterns support it.

**Given** an admin cancels or closes a Voucher
**When** the action is submitted
**Then** audit history and confirmed payment evidence are preserved
**And** confirmed paid evidence cannot be deleted through the cancel action.

**Given** an admin views available actions
**When** the Voucher is in any state
**Then** no Force Paid action is present in MVP.

### Story 4.4: Manage Manual Review Queue and Run Results

As a finance operator,
I want a focused Manual Review queue and reconciliation run summaries,
So that unsafe records are visible and actionable without mixing them with normal paid records.

**Acceptance Criteria:**

**Given** Manual Review Vouchers exist
**When** staff open the Manual Review queue or filter
**Then** rows show reason summary, client, invoice, amount, Consumer Number, last inquiry time, and next allowed action.

**Given** a reconciliation run completes
**When** staff review the run summary
**Then** it shows run type, status, checked, posted, unmatched, failed, skipped, Manual Review counts, timestamps, and failure class.

**Given** an unmatched, duplicate, mismatched, malformed, underpaid, overpaid, or late case exists
**When** staff drill into it
**Then** the detail view provides sanitized evidence and allowed next actions
**And** it never presents direct paid-state shortcuts.

### Story 4.5: Record Structured Logs and Audit Events

As an operator,
I want structured logs and durable audit events for KuickPay operations,
So that support and finance can investigate safely without leaking secrets.

**Acceptance Criteria:**

**Given** KuickPay operations run
**When** logs are written
**Then** logs include operation name, Voucher ID or correlation ID, sanitized request summary, sanitized response summary, error class, duration or timestamp where available
**And** passwords are always masked.

**Given** Voucher lifecycle, inquiry, posting, admin decision, retry, or reconciliation events occur
**When** audit records are created
**Then** event names use lower dot notation such as `voucher.created`, `evidence.received`, `posting.succeeded`, and `admin.reviewed`
**And** payloads contain redacted fields only.

**Given** customer-facing pages render
**When** logs or audit records exist
**Then** raw diagnostics remain admin-only
**And** customer messages stay generic and safe.

## Epic 5: Launch Validation and Operational Handoff

Operators can run opt-in live/sandbox checks and use deployment, reconciliation, troubleshooting, rollback, upgrade, and support documentation for production rollout.

### Story 5.1: Add Opt-In Live and Sandbox KuickPay Tests

As an operator and developer,
I want live or sandbox KuickPay checks to be explicit and redacted,
So that production credentials and data are never exercised accidentally.

**Acceptance Criteria:**

**Given** live or sandbox test code exists
**When** the default automated test suite runs
**Then** live KuickPay endpoints are not called
**And** tests require explicit protected configuration or environment variables.

**Given** live or sandbox tests are enabled intentionally
**When** the tests run
**Then** output redacts credentials, customer contact details, raw sensitive response values, and production data
**And** failures do not leave invoices marked paid.

**Given** sanitized live or sandbox fixtures are captured
**When** they are committed or documented
**Then** they exclude passwords, unredacted SOAP envelopes, customer secrets, and environment-specific values.

### Story 5.2: Document Deployment and Configuration

As an operator,
I want install and configuration documentation,
So that KuickPay can be deployed without guessing extension paths or settings.

**Acceptance Criteria:**

**Given** the deployment guide is opened
**When** an operator follows it
**Then** it explains gateway and plugin file locations, install order, dependency checks, PKR enablement, credential entry, Institution ID, endpoint configuration, timeouts, instruction groups, and safe connection testing.

**Given** credentials or environment-specific values are discussed
**When** documentation provides examples
**Then** it uses placeholders only
**And** it does not copy values from `config/blesta.php`, logs, cache, `.env`, or production settings.

### Story 5.3: Document Reconciliation and Support Operations

As a support or finance operator,
I want reconciliation and troubleshooting runbooks,
So that delayed, ambiguous, or failed payments can be handled consistently.

**Acceptance Criteria:**

**Given** the reconciliation runbook is opened
**When** staff follow it
**Then** it explains scheduled reconciliation, Check Now, Bulk Reconciliation, run summaries, Manual Review, unmatched payments, late payments, underpayments, overpayments, and duplicate references.

**Given** the support troubleshooting guide is opened
**When** staff investigate a customer claim
**Then** it explains how to search by invoice ID or Consumer Number, inspect Voucher Detail, interpret safe statuses, collect sanitized escalation evidence, and avoid unsafe paid-state claims.

### Story 5.4: Document Rollback, Upgrade, and Production Launch

As an operator,
I want rollback, upgrade, and launch guidance,
So that the integration can be introduced or disabled safely.

**Acceptance Criteria:**

**Given** rollback documentation is followed
**When** KuickPay must be disabled
**Then** it explains disabling the gateway, disabling plugin cron, preserving Voucher/audit/payment evidence, and keeping admin evidence readable.

**Given** upgrade documentation is followed
**When** a future KuickPay extension version is deployed
**Then** it explains plugin/gateway upgrade order, schema migration expectations, and verification checks.

**Given** production launch is prepared
**When** operators use the launch checklist
**Then** it includes production Blesta version confirmation, Phase 0 fixture approval, controlled payment test, credential rotation, reconciliation monitoring, first-week Manual Review checks, and rollback readiness.
