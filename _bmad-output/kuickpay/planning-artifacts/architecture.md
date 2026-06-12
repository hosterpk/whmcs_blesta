---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
inputDocuments:
  - _bmad-output/kuickpay/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/prd.md
  - _bmad-output/kuickpay/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/addendum.md
  - _bmad-output/kuickpay/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/review-rubric.md
  - _bmad-output/kuickpay/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/reconcile-source-intake.md
  - _bmad-output/kuickpay/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/DESIGN.md
  - _bmad-output/kuickpay/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/EXPERIENCE.md
  - _bmad-output/kuickpay/planning-artifacts/research/technical-kuickpay-blesta-payment-gateway-research-2026-06-09.md
  - _bmad-output/project-context.md
  - docs/index.md
  - docs/architecture.md
  - docs/api-contracts.md
  - docs/data-models.md
  - docs/component-inventory.md
  - docs/development-guide.md
  - docs/deployment-guide.md
  - docs/project-overview.md
  - docs/source-tree-analysis.md
workflowType: 'architecture'
project_name: 'whmcs_blesta'
user_name: 'Israr'
date: '2026-06-09'
lastStep: 8
status: 'complete'
completedAt: '2026-06-09'
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**
The PRD defines 30 functional requirements across seven product areas: gateway setup, Voucher generation, customer payment experience, KuickPay SOAP client/parser, reconciliation/payment posting, admin operations, and delivery/testing/docs.

Architecturally, this is a payment-state integration. The system must separate evidence collection from payment posting. Customer checkout can create or show a Voucher, the parser can normalize KuickPay evidence, and reconciliation can evaluate evidence, but only the safe posting path may mark a Blesta invoice paid.

**Non-Functional Requirements:**
The architecture is shaped by payment safety, security, reliability, idempotency, auditability, maintainability, localization, privacy, and bounded reconciliation.

Core invariants:

- No paid state from customer action, browser return, copied reference, or raw unvalidated KuickPay response.
- Unknown, malformed, duplicate, mismatched, late, partial, or replayed evidence must fail closed.
- Payment posting requires confirmed parser status, amount match, invoice match, duplicate check, and successful Blesta transaction posting.
- Credentials and diagnostics must be encrypted/redacted and never customer-visible.
- Customer/admin text must use Blesta language-file patterns.

**Scale & Complexity:**
This is a high-complexity Blesta/PHP payment gateway integration with admin and client surfaces.

- Primary domain: Blesta non-merchant payment integration
- Complexity level: high
- Estimated architectural components: 10-12

Likely components include a non-merchant gateway, KuickPay SOAP client, parser/validator, Voucher repository, reconciliation service, payment posting service, redaction/logging utility, customer payment views, admin diagnostics views, settings/credential handling, and tests/docs. A companion plugin remains a likely but still explicit architecture decision for admin/cron workflows.

### Technical Constraints & Dependencies

The repository is a Blesta `6.0.0-b1` PHP monolith using Blesta/minPHP routing, Composer, MySQL/PDO, `.pdt` templates, language files, extension directories, and Composer `vendors/`.

Key constraints:

- Source-compatibility floor: PHP 8.2 (no 8.3-only syntax/APIs). Production and verification **runtime: PHP 8.3 (ea-php83, ionCube 15)** — the ionCube-encoded Blesta core does not load on the 8.2 build. (Clarified 2026-06-13; "8.2" was a Composer floor, not the runtime.)
- Do not modify Blesta core files.
- Keep gateway behavior inside `components/gateways/nonmerchant/...`.
- Keep plugin/admin/cron behavior inside plugin boundaries if a companion plugin is selected.
- Use durable MySQL-backed state for Voucher/payment evidence.
- Preserve Blesta loader, model, Input validation, Record/query, transaction, language, and view conventions.
- Root Composer tests depend on sibling `../tests`; fallback verification must be explicit when unavailable.

Phase 0 is a release gate, not routine testing. Payment posting must remain disabled until sanitized KuickPay fixtures prove the parser contract for success, pending, failed, unknown, malformed, duplicate, amount mismatch, late, partial/overpayment if supported, and bulk reconciliation cases.

### Decided Invariants

- PKR-first MVP.
- No Blesta core edits.
- No hard-coded production credentials, institution IDs, URLs, fees, fallback phones, or conversion rates.
- Unknown KuickPay responses do not post payment.
- Bulk reconciliation matches stored Consumer Numbers only.
- Manual Review is a valid safe state, not a failure of the product.
- Customer UX must never imply "paid" until posting succeeds.

### Open Product and Architecture Decisions

- Gateway-only versus gateway plus companion plugin.
- Schema ownership, table names, unique keys, install/upgrade path, and retention policy.
- Idempotency key strategy for Voucher creation and payment posting.
- Posting transaction boundary and concurrency control.
- Cron cadence, retry/backoff behavior, and rate-limit policy.
- Fee, partial payment, overpayment, late payment, and multi-invoice policies.
- Whether old WHMCS payment references are imported, ignored, or treated as unverifiable.
- Whether admins can ever force-post payment; if yes, authorization and audit rules.
- Diagnostic retention and redacted evidence fields.
- Exact customer support path wording.

### Cross-Cutting Concerns Identified

- Payment truth and fail-closed state transitions
- Idempotent Voucher creation and duplicate transaction prevention
- Atomic posting under cron/manual concurrency
- Parser normalization and fixture-backed status mapping
- Payment-state UX contract across customer/admin surfaces
- Credential encryption and diagnostic redaction
- Admin-only observability, Manual Review, and support evidence
- Scheduled/manual/bulk reconciliation using one validation path
- Localization, accessibility, and responsive Consumer Number display
- PHP 8.2 compatibility and Blesta extension boundaries
- Safe verification when the sibling test suite is unavailable

## Starter Template Evaluation

### Primary Technology Domain

Existing Blesta/PHP extension development inside a brownfield PHP MVC monolith.

This project is not a greenfield web, mobile, API, or full-stack app. The scaffold decision is about the correct Blesta extension foundation rather than a generic framework starter.

### Selected Scaffold: Blesta-Native Extension Scaffold

Selected scaffold: Blesta-native extension scaffold, implemented in place under Blesta extension paths. This is not an external application starter. The Extension Generator may be used only to produce Blesta-compatible boilerplate; it must not decide payment-state ownership, schema boundaries, reconciliation behavior, callback behavior, cron/plugin duties, failure handling, or posting behavior.

Current-source checks support this direction:

- Blesta non-merchant gateway docs define the gateway shape and required methods.
- Blesta plugin docs define plugin lifecycle, admin actions, ACL, database access, events, and cron task surfaces.
- Blesta Extension Generator docs describe it as a skeleton generator for Blesta extensions, not a payment architecture tool.
- Blesta gateway configuration docs define `config.json` and language/config usage.
- Current Blesta release evidence shows Blesta 6.0.0 Beta 1 is non-production/unsupported, while Blesta 5.13 is a current stable release note. Production version must be confirmed before compatibility is finalized.

### Existing Technical Preferences Found

- Language/runtime: PHP 8.2-compatible code.
- Application pattern: Blesta/minPHP MVC monolith with extension directories.
- Gateway path: `components/gateways/nonmerchant/kuickpay/`
- Optional companion plugin path: `plugins/kuickpay_reconcile/`
- Database: MySQL through PDO and Blesta model/Record patterns.
- Views/UI: Blesta `.pdt` templates, inherited Bootstrap/Paradigm UI classes, existing helper patterns, and language files.
- Dependency layout: Composer installs to `vendors/`, not `vendor/`.
- Verification: PHPUnit `~8.5` where available; root test scripts expect sibling `../tests`; fallback checks must be explicit.
- Development guardrails: no Blesta core edits, no hard-coded production values, no secret leakage.

### Scaffold Options Considered

**Option 1: Generic PHP or web-app starter**

Rejected. A Laravel/Symfony/Next/Vite-style starter would introduce a second application architecture and violate the requirement to implement inside Blesta extension boundaries.

**Option 2: Blesta Extension Generator**

Accepted only as optional boilerplate support.

It may create class/file/language/template boilerplate. Generated methods, permissions, cron hooks, event hooks, and view templates must be audited before any payment-state write. Generated code must conform to the architecture; it must not define the architecture.

**Option 3: Existing Blesta extension patterns in this repository**

Selected as the real foundation.

Use local patterns from:

- `components/gateways/nonmerchant/offline`
- `components/gateways/nonmerchant/paysera`
- `plugins/auto_cancel`
- `plugins/webhooks`
- `plugins/extension_generator`

### Architecture Readiness Gates

This scaffold is architecture-ready, but only conditionally implementation-ready. Before the first implementation story writes the scaffold, architecture must decide:

- Gateway-only versus gateway-plus-plugin ownership.
- Whether reconciliation crosses the gateway lifecycle boundary and therefore requires `plugins/kuickpay_reconcile/`.
- Schema ownership, durable tables, unique keys, install/upgrade path, uninstall behavior, and retention policy.
- Payment-state owner and posting boundary.
- The stable contract between gateway and plugin if both exist.
- The exact fail-closed rule before invoice crediting.
- The accepted KuickPay events and which states require Manual Review.
- The first non-negotiable Phase 0 fixture set.

Default architectural posture: gateway-only unless reconciliation, admin operations, scheduled jobs, durable retry state, or operational audit workflows cross the gateway lifecycle boundary. If they do, use gateway-plus-plugin with a narrow documented contract.

### Expected Initial File Targets

Gateway:

```text
components/gateways/nonmerchant/kuickpay/
  kuickpay.php
  config.json
  language/en_us/kuickpay.php
  views/default/settings.pdt
  views/default/process.pdt
```

Optional companion plugin:

```text
plugins/kuickpay_reconcile/
  kuickpay_reconcile_plugin.php
  config.json
  controllers/
  models/
  language/en_us/
  views/default/
```

Use `kuickpay` consistently for folder names, class prefixes, language namespaces, template paths, table prefixes, and config keys unless Blesta conventions require another form.

### Architectural Decisions Provided by Scaffold

**Language & Runtime:**
PHP 8.2-compatible source as the floor (no PHP 8.3+ syntax or APIs), executed on the production runtime **PHP 8.3 (ea-php83, ionCube 15)**.

**Styling Solution:**
No new styling stack. Use Blesta admin/client `.pdt` templates, inherited Bootstrap/Paradigm UI classes, existing helper patterns, and language files.

**Build Tooling:**
No new Node or asset build pipeline. Use existing Blesta/PHP runtime and Composer conventions.

**Testing Framework:**
Use parser fixture tests first, Blesta gateway/plugin tests where the target suite exists, and explicit fallback verification such as `php -l` when sibling tests are unavailable.

Minimum fixture gate:

- success
- failure
- malformed response
- replay/duplicate
- amount mismatch
- invoice mismatch
- unknown transaction
- pending/unpaid
- late payment
- bulk reconciliation matched/unmatched rows

**Code Organization:**
Gateway responsibilities:

- gateway config and encrypted meta
- PKR eligibility
- customer payment reference rendering
- Voucher create/reuse handoff
- safe rejection of unsupported/unsafe payment states

Plugin/service responsibilities, if selected:

- Voucher repository
- reconciliation runs
- parser fixtures
- SOAP client wrapper
- payment posting service
- admin list/detail/actions
- cron/manual reconciliation
- schema lifecycle

**Development Experience:**
Use local Blesta examples and official docs as the scaffold source. Keep generated boilerplate small and replace placeholder logic with explicit payment-state contracts before implementation proceeds.

### Verification Baseline

Exact commands may be refined later, but implementation stories should at minimum include syntax checks over scaffold PHP files, such as:

```sh
php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
php -l plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
find components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile -name '*.php' -print -exec php -l {} \;
```

Run parser/unit/integration tests only where the repository or extension provides a valid target suite; do not claim root test coverage unless sibling `../tests` exists.

### Runbook Implications

The scaffold choice implies future documentation for:

- installation and enablement
- Admin Settings and credential rotation
- Phase 0 fixture capture and approval
- safe connection testing
- reconciliation job behavior
- Manual Review and delayed payment support handling
- rollback/uninstall behavior
- how operators confirm payment truth without reading raw KuickPay responses

### First Implementation Story Constraint

The first implementation story should be:

> Create KuickPay Blesta extension scaffold after architecture confirms gateway-only vs gateway-plus-plugin ownership.

It must not implement live payment mutation. The first story should establish the in-place scaffold, fixture harness direction, language/template conventions, no-core-edit boundary, and safe placeholder behavior.

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**
- Use existing Blesta MySQL/PDO database; no new persistence platform.
- Use gateway-plus-plugin ownership for MVP.
- Only `posted` implies paid.
- Companion plugin owns durable Voucher state, reconciliation, schema lifecycle, admin review, and posting services.
- Payment posting is centralized behind validated KuickPay evidence.
- No "force paid" action in MVP.
- Raw SOAP/XML never drives product logic directly.
- Use Blesta auth, plugin ACL, POST/CSRF for admin mutations, and encrypted settings.
- Deploy as Blesta extension files; no new hosting, queue, worker, container, or CI/CD system.

**Important Decisions (Shape Architecture):**
- Admin reconciliation workbench is required.
- Normalized parser result is the shared gateway/plugin contract.
- All diagnostics pass through one redaction boundary.
- Reconciliation cron must be bounded, locked, idempotent, and resumable.
- Rollback preserves Voucher/audit/payment evidence.
- All customer/admin text uses language files and conservative payment-state wording.

**Deferred Decisions (Must Be Resolved Before Production Where Applicable):**
- Production Blesta target version, especially 5.13 stable versus 6.0 beta compatibility.
- WHMCS cutover behavior for old payment references.
- Voucher expiry, retry/backoff, partial/overpayment, late payment, mismatch policy details.
- Callback/IPN support, only if KuickPay provides reliable verification rules.
- Exact audit retention duration and destructive purge policy.

### Data Architecture

Use the existing Blesta MySQL/PDO database with gateway-plus-plugin data ownership.

The KuickPay gateway owns checkout/reference display and calls the plugin service for Voucher create/reuse. `plugins/kuickpay_reconcile` owns durable Voucher state, reconciliation runs, admin review data, schema lifecycle, and payment posting services.

Tables:
- `kuickpay_vouchers`
- `kuickpay_voucher_invoices`
- `kuickpay_reconciliation_runs`
- `kuickpay_reconciliation_items`

States:
- `pending`
- `retry`
- `confirmed_unposted`
- `posted`
- `failed`
- `expired`
- `manual_review`
- `cancelled`

Only `posted` may imply that Blesta invoice payment succeeded. `confirmed_unposted` is evidence, not payment completion.

Use schema-level idempotency for company-scoped Registration Number, Consumer Number, active payment context, KuickPay references when present, and Blesta transaction ID once posted. Avoid nullable-unique MySQL traps for optional references.

Voucher creation must atomically create Voucher and invoice links. Posting must lock Voucher and invoice mapping rows, validate idempotency, create/apply the Blesta transaction, and only then transition to `posted`.

No cache participates in payment truth.

### Authentication & Security

Use existing Blesta admin/client authentication. No new login system.

Admin actions use plugin ACL with separate permissions for:
- view records
- run recheck
- add review note
- cancel/close Voucher
- view diagnostics
- future posting-capable actions, if ever introduced

Customer views are server-scoped to authenticated client invoices. Route parameters are never trusted.

Gateway credentials use `encryptableFields()`. The plugin must not duplicate gateway passwords. Plugin-owned secrets, if any, must use Blesta-supported encrypted settings.

All SOAP diagnostics, exceptions, retry records, logs, and audit fields pass through a single redaction boundary.

No "force paid" action exists in MVP. Manual Review supports recheck, cancel/close, notes, and evidence-needed status only.

Admin mutations require POST, staff auth, ACL, and CSRF protection. GET is read-only.

Callbacks/IPN remain future scope and untrusted evidence unless KuickPay provides reliable verification rules.

### API & Communication Patterns

Use a dedicated KuickPay SOAP client wrapper around PHP `SoapClient`.

Required operations:
- `InsertVoucher`
- `BillPaymentInquiry`
- `BillPaymentBulkInquiry`

Optional safe setup operations:
- `Echo`
- `GetInstitutionsList`

Communication flow:

```text
SOAP client -> redactor -> parser -> normalized result -> state machine
```

Controllers, `.pdt` views, posting logic, and reconciliation services must not consume raw SOAP strings/XML.

Expected wrapper target:

```text
components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php
```

The normalized parser result is the shared contract between gateway and plugin. Product code consumes parser output only.

`buildProcess()` may create/reuse/show a Voucher but cannot mark paid. `validate()`, `success()`, and callback/IPN paths cannot mark paid from browser/customer-side data.

Bulk XML parsing must use bounded payloads and safe XML parsing. Malformed, unknown, duplicate, unmatched, and mismatched cases map to explicit error classes.

Retry only inquiry/bulk operations on timeout/transport errors. Do not blindly retry `InsertVoucher` unless local idempotency proves it safe.

### Frontend Architecture

No new frontend framework or build pipeline.

Use Blesta `.pdt` templates, helpers, inherited admin/client UI patterns, and language files.

Customer surface stays inside Blesta client invoice/payment flow. It shows Consumer Number, amount, due date, expiry date, copy action, and instruction groups before secondary content.

No success/paid styling appears until `posted`.

Admin plugin provides a reconciliation workbench:
- Voucher list and filters
- Voucher detail
- redacted diagnostics
- run summaries
- Manual Review queue
- Check Now
- notes
- cancel/close
- normalized evidence

No raw SOAP or credentials appear in customer views. Admin diagnostics are redacted and permissioned.

Every normalized status/error class maps to one customer label, one admin label, and allowed actions.

### Infrastructure & Deployment

No new hosting platform, container, queue, worker, CI/CD, or deployment system for MVP.

Deploy as Blesta extension files:
- `components/gateways/nonmerchant/kuickpay/`
- `plugins/kuickpay_reconcile/`

Plugin install/upgrade owns schema creation, idempotent migrations, ACL/action registration, and cron task registration.

Use Blesta plugin cron tasks for reconciliation. Cron jobs must have:
- DB-backed lock
- bounded batch size
- max runtime
- retry limit
- resume cursor/status
- stale-lock handling
- no double-posting on rerun

Configuration lives in Blesta gateway/plugin settings. No `.env`, hard-coded config, committed credentials, or copied `config/blesta.php` values.

Rollout phases:
1. Phase 0 fixture/API validation
2. scaffold/settings
3. Voucher creation/customer display
4. reconciliation/posting
5. admin workbench/manual review
6. controlled production rollout

Rollback:
- disable KuickPay gateway
- disable plugin cron
- preserve Voucher/audit/payment evidence tables
- keep admin evidence readable
- do not delete data unless a separate archival/purge policy exists

Verification:
- `php -l` changed PHP files
- parser fixtures
- install/upgrade smoke checks with runtime/database
- cron dry-run/manual Check Now in staging
- rollback smoke check
- no root PHPUnit claim unless sibling `../tests` exists

### Decision Impact Analysis

**Implementation Sequence:**
1. Confirm production Blesta version and KuickPay Phase 0 fixtures.
2. Create gateway-plus-plugin scaffold.
3. Implement settings, credential encryption, and redaction.
4. Implement schema, state machine, and idempotency guards.
5. Implement SOAP client and parser fixtures.
6. Implement Voucher create/reuse and customer reference view.
7. Implement reconciliation and posting service.
8. Implement admin workbench and Manual Review actions.
9. Add deployment, rollback, and support runbooks.
10. Run staging fixture, cron, rollback, and controlled payment checks.

**Cross-Component Dependencies:**
- Gateway depends on plugin service for Voucher create/reuse.
- Plugin service depends on parser contract for KuickPay evidence.
- Posting depends on state machine, idempotency, row locking, and Blesta transaction creation.
- Admin workbench depends on durable Voucher/reconciliation records and redacted diagnostics.
- Customer UI depends on normalized safe payment states, not raw KuickPay responses.
- Rollback depends on separating gateway enablement from plugin cron execution.

## Implementation Patterns & Consistency Rules

### Pattern Authority

These implementation patterns are normative for the KuickPay MVP. Examples illustrate the required shape; they do not create alternate approaches. If an implementation choice is not listed here, prefer existing Blesta PHP 8.2, PDO, minPHP, `.pdt`, loader, `Input`, `Record`, and language-file conventions.

### Pattern Categories Defined

**Critical Conflict Points Identified:**
36 areas where AI agents could make different choices across extension naming, database shape, state names, SOAP parsing, admin routes, cron behavior, diagnostics, language keys, payment posting, and UI wording.

### Ownership Boundaries

The gateway owns checkout initiation and customer-facing KuickPay reference display only. It may call approved plugin services for Voucher create/reuse.

The gateway must not own durable Voucher lifecycle state, reconciliation, admin review, retry handling, schema lifecycle, or invoice payment posting.

The `kuickpay_reconcile` plugin owns durable Voucher state, reconciliation, admin review, schema lifecycle, retry handling, audit records, and posting orchestration.

In this architecture, an invoice is considered paid only after centralized posting succeeds from validated KuickPay evidence. Remote KuickPay status, raw SOAP/XML content, admin review labels, or local Voucher state must not independently mark an invoice paid.

### Naming Patterns

**Database Naming Conventions:**
- Use lower snake_case table names with the `kuickpay_` prefix.
- Use `id` as the primary key on entity tables.
- Use foreign keys named `<entity>_id`.
- Use Blesta-style date columns such as `date_created`, `date_updated`, `date_expires`, `date_posted`, and `date_last_checked`.
- Use Step 4 canonical Voucher states only: `pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`.
- `confirmed_unposted` means validated evidence only. It does not mean paid.
- Use explicit index names: `idx_<table>_<purpose>` and `uniq_<table>_<purpose>`.
- Avoid nullable unique columns for optional KuickPay references.

**Code Naming Conventions:**
- Gateway root: `components/gateways/nonmerchant/kuickpay/`
- Gateway class file: `components/gateways/nonmerchant/kuickpay/kuickpay.php`
- Plugin root: `plugins/kuickpay_reconcile/`
- Plugin class file: `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`
- SOAP wrapper target: `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`
- Plugin services should live under plugin-owned `models/` or `lib/`, not controllers.
- Suggested service names: `KuickPayResponseParser`, `KuickPayVoucherRepository`, `KuickPayPostingService`, `KuickPayAuditService`, `KuickPayReconcileService`.

### Parser & Evidence Contract

All KuickPay SOAP/XML responses must be converted into a normalized parser result before any product decision is made. Product logic may read only the normalized result and validation outcome, never raw SOAP/XML.

Required normalized fields:
- `status`
- `error_class`
- `reference`
- `consumer_number`
- `registration_number`
- `amount`
- `currency`
- `paid_at`
- `raw_status`
- `redacted_trace_id`
- `evidence_hash`
- `validation_errors`

Missing required fields produce `malformed_response`, not partial success.

Allowed error classes:
- `timeout`
- `transport_error`
- `credential_error`
- `malformed_response`
- `unknown_status`
- `amount_mismatch`
- `duplicate_reference`
- `unmatched_reference`

`duplicate_reference` and `unmatched_reference` are reconciliation exceptions requiring review. They must never fall through to posting.

### Posting Contract

Only `KuickPayPostingService` may create/apply the Blesta transaction.

The posting service must:
- re-read Voucher and evidence inside the posting transaction
- acquire the relevant row locks
- verify status, amount, currency, reference, invoice mapping, and idempotency again
- create/apply the Blesta transaction
- transition Voucher state to `posted` only after Blesta transaction success
- write an audit event

Amounts must be compared using normalized decimal strings or integer minor units, never PHP floats. Currency must be part of every validation check.

### UI Display-State Matrix

| Voucher State | Customer Label | Admin Label | Allowed Customer Actions | Allowed Admin Actions | Forbidden |
|---|---|---|---|---|---|
| `pending` | Payment reference created | Voucher active, not posted | copy Consumer Number, view instructions | recheck, open invoice | success styling |
| `retry` | Confirmation delayed | Provider unavailable | view reference/instructions | retry reconciliation | mark paid |
| `confirmed_unposted` | Waiting for payment confirmation | Validated evidence, ready to post | view status | post through service | direct transaction |
| `posted` | Payment received | Posted to Blesta | view receipt/status | view audit | duplicate posting |
| `failed` | Confirmation delayed | Evidence mismatch, review required | view safe message | review, retry where valid | raw evidence display |
| `expired` | Payment reference expired | Expired, not posted | generate/pay again if flow allows | close/archive | treating as failed |
| `manual_review` | Payment under review | Duplicate or ambiguous evidence | view safe message | acknowledge, retry reconcile, void/archive, open invoice | force paid |
| `cancelled` | Payment reference cancelled | Cancelled, not posted | generate/pay again if flow allows | view audit | reuse as active |

No customer view may show raw provider status, SOAP names, credentials, stack traces, or admin-review internals.

### Audit and Logging Patterns

Logs are operational diagnostics. Audit records are durable business history.

Audit records are required for:
- parser validation outcomes
- reconciliation runs
- state transitions
- admin decisions
- retry decisions
- posting attempts
- posting success/failure

Audit event names use lower dot notation, such as:
- `voucher.created`
- `voucher.issued`
- `evidence.received`
- `evidence.matched`
- `evidence.rejected`
- `posting.started`
- `posting.succeeded`
- `posting.failed`
- `admin.reviewed`

Audit payloads must use redacted fields only.

### Enforcement Guidelines

All AI agents must:
- keep gateway and plugin responsibilities separated
- use the normalized parser contract before payment-state decisions
- use language files for every customer/admin string
- pass diagnostics through the redaction boundary
- preserve schema-level idempotency and row-locking assumptions
- run `php -l` on changed PHP files
- avoid root PHPUnit claims unless sibling `../tests` exists
- update this architecture before changing a pattern

### Anti-Patterns

- Creating a Blesta transaction inside `buildProcess()`.
- Marking an invoice paid from browser return data.
- Calling `markPaid`, `recordPayment`, invoice status update, or transaction creation outside `KuickPayPostingService`.
- Parsing raw SOAP XML in a controller or `.pdt` view.
- Branching on raw SOAP status strings outside the parser.
- Showing "Payment received", green checks, success styling, or paid receipt language before `posted`.
- Storing raw SOAP or credentials in logs.
- Adding hard-coded production credentials or institution IDs.
- Using PHP floats for amount matching.
- Adding a GET admin route that mutates Voucher state.
- Adding a "force paid" admin action in MVP.
- Cron posting without row locks.

## Project Structure & Ownership

### Ownership Rule

Gateway files own checkout handoff and customer-facing KuickPay reference display only.

The `kuickpay_reconcile` plugin owns Voucher persistence, reconciliation, admin review, retry handling, audit records, cron, posting, and schema lifecycle.

Only `posted` plugin state may mark an invoice paid. Raw SOAP/XML responses are integration input only and must not directly drive business state.

When adding a new KuickPay behavior, first decide whether it changes checkout display, durable Voucher state, operator workflow, or external SOAP communication. Place it according to that ownership boundary before writing code.

### Complete Project Directory Structure

```text
components/gateways/nonmerchant/kuickpay/
├── README.md
├── composer.json
├── config.json
├── kuickpay.php
├── lib/
│   ├── KuickPaySoapClient.php
│   ├── KuickPayResponseParser.php
│   ├── KuickPayEvidence.php
│   └── KuickPayRedactor.php
├── language/
│   └── en_us/
│       └── kuickpay.php
└── views/
    └── default/
        ├── settings.pdt
        └── process.pdt

plugins/kuickpay_reconcile/
├── README.md
├── composer.json
├── config.json
├── kuickpay_reconcile_plugin.php
├── kuickpay_reconcile_controller.php
├── kuickpay_reconcile_model.php
├── controllers/
│   ├── admin_vouchers.php
│   ├── admin_reconciliation.php
│   └── admin_manual_review.php
├── models/
│   ├── kuickpay_vouchers.php
│   ├── kuickpay_voucher_invoices.php
│   ├── kuickpay_reconciliation_runs.php
│   ├── kuickpay_reconciliation_items.php
│   ├── kuickpay_reconcile_locks.php
│   └── kuickpay_audit_events.php
├── lib/
│   ├── README.md
│   ├── KuickPaySchema.php
│   ├── KuickPayVoucherStates.php
│   ├── KuickPayVoucherNormalizer.php
│   ├── KuickPayVoucherReferenceService.php
│   ├── KuickPayVoucherRepository.php
│   ├── KuickPayReconcileService.php
│   ├── KuickPayReconcileLockRepository.php
│   ├── KuickPayPostingService.php
│   ├── KuickPayAuditService.php
│   └── KuickPayAuditRepository.php
├── language/
│   └── en_us/
│       ├── kuickpay_reconcile_plugin.php
│       ├── admin_vouchers.php
│       ├── admin_reconciliation.php
│       └── admin_manual_review.php
├── views/
│   └── default/
│       ├── admin_vouchers.pdt
│       ├── admin_voucher_detail.pdt
│       ├── admin_reconciliation_runs.pdt
│       ├── admin_reconciliation_run_detail.pdt
│       └── admin_manual_review.pdt
└── tests/
    └── fixtures/
        └── kuickpay/
            ├── valid/
            │   ├── success.xml
            │   └── pending.xml
            ├── malformed/
            │   └── malformed.xml
            ├── ambiguous/
            │   ├── duplicate-reference.xml
            │   ├── unmatched-reference.xml
            │   └── amount-mismatch.xml
            └── redaction/
                └── credentials.xml

docs/kuickpay/
├── implementation-boundaries.md
├── deployment-checklist.md
├── operator-runbook.md
├── reconciliation-runbook.md
├── admin-review-runbook.md
├── rollback-runbook.md
├── support-troubleshooting.md
└── testing-fixtures.md
```

Do not place Voucher persistence, reconciliation, paid-state decisions, retry logic, audit logging, schema lifecycle, cron work, or admin review screens in the gateway.

### Architectural Boundaries

**API Boundaries:**
- External KuickPay SOAP calls live only in `KuickPaySoapClient.php`.
- SOAP responses flow through parser/redactor before business logic.
- No controller, view, cron task, or posting service branches on raw SOAP/XML.
- No new public REST API is introduced for MVP.

**Component Boundaries:**
- Gateway: installable payment method, settings UI, encrypted gateway meta, PKR eligibility, checkout reference display.
- Plugin: durable Voucher state, schema lifecycle, reconciliation runs, admin workbench, audit records, cron, posting service.
- Protocol library: SOAP client, parser, evidence object, redactor.
- Admin UI: plugin controllers and `.pdt` views only.

**Service Boundaries:**
- `KuickPayVoucherReferenceService` owns create/reuse/idempotency decisions for Voucher references.
- `KuickPayVoucherRepository` owns Voucher persistence reads/writes.
- `KuickPayVoucherNormalizer` turns validated SOAP-derived evidence into candidate Voucher updates before posting.
- `KuickPayReconcileService` owns inquiry/bulk reconciliation orchestration.
- `KuickPayPostingService` is the only service that creates/applies Blesta transactions.
- `KuickPayAuditService` owns durable business audit records.
- `KuickPaySchema` owns install/upgrade/uninstall schema lifecycle.
- Controllers call services; views render assigned data only.

**Controller Boundaries:**
- `admin_vouchers.php`: search, list, and detail only.
- `admin_reconciliation.php`: reconciliation run visibility and operational controls.
- `admin_manual_review.php`: human review workflow only.
- Controllers must not perform SOAP parsing, row locking, retry computation, schema changes, or payment posting.

**Data Boundaries:**
- Plugin owns all `kuickpay_` tables.
- Gateway does not create or mutate reconciliation/posting state directly.
- Payment truth is database-backed, not cache/session/browser-backed.
- Amount and currency validation happen before posting and again inside posting transaction.
- Audit persistence lives in `kuickpay_audit_events`.

### Requirements To Structure Mapping

**FR-1 to FR-5: Gateway Availability and Setup**
- `components/gateways/nonmerchant/kuickpay/kuickpay.php`
- `components/gateways/nonmerchant/kuickpay/config.json`
- `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt`
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`

**FR-6 to FR-11: Voucher Generation**
- `components/gateways/nonmerchant/kuickpay/kuickpay.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`
- `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php`
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`

**FR-12 to FR-14: Customer Payment Experience**
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt`
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`

**FR-15 to FR-17: KuickPay API Client and Parser**
- `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php`
- `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`

**FR-18 to FR-23: Reconciliation and Payment Posting**
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconcileLockRepository.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_items.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconcile_locks.php`

**FR-24 to FR-27: Admin Operations and Supportability**
- `plugins/kuickpay_reconcile/controllers/admin_vouchers.php`
- `plugins/kuickpay_reconcile/controllers/admin_reconciliation.php`
- `plugins/kuickpay_reconcile/controllers/admin_manual_review.php`
- `plugins/kuickpay_reconcile/views/default/*.pdt`
- `plugins/kuickpay_reconcile/lib/KuickPayAuditService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayAuditRepository.php`
- `plugins/kuickpay_reconcile/models/kuickpay_audit_events.php`

**FR-28 to FR-30: Testing, Deployment, and Documentation**
- `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`
- `docs/kuickpay/implementation-boundaries.md`
- `docs/kuickpay/deployment-checklist.md`
- `docs/kuickpay/operator-runbook.md`
- `docs/kuickpay/reconciliation-runbook.md`
- `docs/kuickpay/admin-review-runbook.md`
- `docs/kuickpay/rollback-runbook.md`
- `docs/kuickpay/support-troubleshooting.md`
- `docs/kuickpay/testing-fixtures.md`

### Integration Points

**Internal Communication:**
- Gateway calls plugin service for Voucher create/reuse.
- Plugin service uses SOAP/parser/redactor protocol classes for KuickPay evidence.
- Admin controllers call plugin services and models.
- Cron invokes reconciliation service without view/controller dependency.
- Posting service calls Blesta transaction APIs only after validation and locks.

**External Integrations:**
- KuickPay SOAP: `InsertVoucher`, `BillPaymentInquiry`, `BillPaymentBulkInquiry`, optional `Echo`, optional `GetInstitutionsList`.
- Blesta extension runtime: non-merchant gateway methods, plugin install/upgrade/uninstall, plugin cron tasks, Blesta auth/ACL/language/view helpers.
- MySQL/PDO through Blesta `Record`.

**Data Flow:**

```text
customer checkout
-> kuickpay gateway
-> plugin Voucher reference service
-> plugin Voucher repository
-> KuickPay InsertVoucher
-> normalized evidence
-> customer reference display
-> cron/manual reconciliation
-> normalized evidence
-> state machine
-> posting service
-> Blesta transaction
-> Voucher state posted
```

### File Organization Patterns

**Configuration Files:**
- Gateway config remains in gateway `config.json` and encrypted gateway meta.
- Plugin config remains in plugin `config.json`.
- No `.env`, Docker, CI, or root build config is added.

**Source Organization:**
- Payment display code stays in gateway.
- Durable state and admin operations stay in plugin.
- SOAP/parser/redactor classes stay protocol-focused and do not post payments.
- Shared payment decisions are service methods, not controller branches.
- `plugins/kuickpay_reconcile/lib/README.md` documents that `KuickPayPostingService.php` is the only class allowed to apply Blesta payments.

**Test Organization:**
- No new root `tests/` directory.
- Parser fixtures live inside the KuickPay extension/plugin test area.
- Root PHPUnit coverage is not claimed unless sibling `../tests` exists.
- Fallback verification is `php -l` over changed PHP files.

**Asset Organization:**
- No new frontend asset pipeline.
- `.pdt` views use Blesta helpers and inherited admin/client styling.
- Any icons/copy/actions are language-file driven.

### Development Workflow Integration

**Development Server Structure:**
- Uses the existing Blesta PHP web stack.
- No new local dev server is introduced.

**Build Process Structure:**
- No build process for MVP.
- Composer metadata may exist per extension, but root dependency layout remains `vendors/`.

**Deployment Structure:**
- Deploy gateway files under `components/gateways/nonmerchant/kuickpay/`.
- Deploy plugin files under `plugins/kuickpay_reconcile/`.
- Enable gateway and plugin through Blesta extension flows.
- Plugin install/upgrade owns schema, ACL/action registration, and cron registration.

## Architecture Validation Results

### Coherence Validation ✅

**Decision Compatibility:**
The architecture is internally consistent: Blesta-native gateway-plus-plugin ownership, PHP 8.2, MySQL/PDO, `.pdt` views, language files, plugin cron, and no-core-edit constraints all align.

**Pattern Consistency:**
Implementation patterns reinforce the core payment invariant: evidence may be collected and validated, but only `KuickPayPostingService` can create/apply a Blesta transaction and transition Voucher state to `posted`.

**Structure Alignment:**
The project structure supports the decisions. Gateway files are limited to setup, checkout, SOAP protocol, and customer reference display. Plugin files own durable state, reconciliation, admin review, locking, audit, schema, and posting.

### Requirements Coverage Validation ✅

**Functional Requirements Coverage:**
All 30 PRD functional requirements are mapped to concrete gateway, plugin, fixture, or documentation locations.

**Non-Functional Requirements Coverage:**
Payment safety, idempotency, security, redaction, localization, auditability, rollback, bounded cron, and fallback verification are covered architecturally.

### Implementation Readiness Validation ✅

**Decision Completeness:**
Critical implementation decisions are documented. Production Blesta target remains a production-gate decision, not a scaffold blocker.

**Structure Completeness:**
The directory structure identifies concrete files, ownership boundaries, integration points, and requirement mappings.

**Pattern Completeness:**
Naming, parser/evidence, posting, UI state, audit/logging, anti-patterns, and verification rules are specific enough for AI agents to implement consistently.

### Gap Analysis Results

**Critical Gaps:**
None for initial implementation.

**Important Production-Gate Gaps:**
- Confirm production Blesta target: 5.13 stable versus 6.0 beta compatibility.
- Complete Phase 0 sanitized KuickPay fixtures before enabling payment posting.
- Finalize policies for partial, over, late, mismatch, duplicate, and unmatched evidence.
- Define exact audit retention and destructive purge policy.

**Minor Gaps:**
- Exact default cron batch size, retry limits, and runtime ceilings remain to be selected during implementation.
- Runbooks are structurally defined but still need content.

### Architecture Completeness Checklist

**Requirements Analysis**
- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed
- [x] Technical constraints identified
- [x] Cross-cutting concerns mapped

**Architectural Decisions**
- [x] Critical decisions documented with versions
- [x] Technology stack fully specified
- [x] Integration patterns defined
- [x] Performance considerations addressed

**Implementation Patterns**
- [x] Naming conventions established
- [x] Structure patterns defined
- [x] Communication patterns specified
- [x] Process patterns documented

**Project Structure**
- [x] Complete directory structure defined
- [x] Component boundaries established
- [x] Integration points mapped
- [x] Requirements to structure mapping complete

### Architecture Readiness Assessment

**Overall Status:** READY FOR IMPLEMENTATION

**Confidence Level:** high

**Key Strengths:**
- Payment truth boundary is explicit and repeated.
- Gateway/plugin ownership is clear.
- Parser/evidence contract prevents raw SOAP drift.
- Admin, cron, audit, rollback, and support concerns have named homes.
- PRD requirements map to concrete files and services.

**Areas for Future Enhancement:**
- Production target version decision.
- Live/sandbox KuickPay validation process.
- Detailed exception policies and retention policy.
- Full runbook content.

### Implementation Handoff

**AI Agent Guidelines:**
- Follow all architectural decisions exactly as documented.
- Respect gateway/plugin ownership boundaries.
- Do not bypass normalized evidence, redaction, idempotency, or posting service rules.
- Use this document as the controlling implementation reference.

**First Implementation Priority:**
Create the KuickPay Blesta gateway-plus-plugin scaffold with safe placeholder behavior, no live payment mutation, language files, fixture harness direction, and `php -l` verification.
