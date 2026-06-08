---
stepsCompleted: [1, 2, 3, 4, 5, 6]
inputDocuments:
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/prd.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/addendum.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/review-rubric.md
  - _bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/reconcile-source-intake.md
  - _bmad-output/project-context.md
  - docs/architecture.md
  - docs/development-guide.md
  - docs/api-contracts.md
  - docs/data-models.md
workflowType: 'research'
lastStep: 6
research_type: 'technical'
research_topic: 'KuickPay Blesta Payment Gateway'
research_goals: 'Convert the PRD into source-verified architecture and implementation research for a safe KuickPay non-merchant Blesta gateway, with special attention to SOAP parsing, payment truth, reconciliation, admin operations, and Blesta extension boundaries.'
user_name: 'Israr'
date: '2026-06-09'
web_research_enabled: true
source_verification: true
status: 'complete'
---

# KuickPay Blesta Payment Gateway: Comprehensive Technical Research

## Research Overview

This research evaluates how to implement the PRD for a KuickPay payment option in Blesta without weakening payment safety during HosterPK's WHMCS-to-Blesta migration. The local project context establishes a PHP 8.2 Blesta 6.0.0-b1 monolith with Blesta/minPHP routing, `vendors/` dependency layout, MySQL/PDO persistence, legacy extension conventions, `.pdt` views, language files, and no repo-local test suite. The PRD establishes the product shape: a PKR-first KuickPay non-merchant bill/reference flow, fixture-first parser, fail-closed posting, reconciliation, admin support views, and no Blesta core edits.

External verification confirms that the required KuickPay operations are exposed through an ASMX/SOAP service and that Blesta's public developer docs support the non-merchant gateway methods, encrypted gateway fields, plugin pages, and plugin cron tasks needed by this product. The strongest architectural conclusion is that the MVP should be delivered as a coupled gateway plus companion plugin: the gateway owns Blesta payment selection, voucher creation/reuse, and the customer Consumer Number page; the plugin owns voucher persistence, reconciliation cron, admin voucher operations, manual review, and run summaries. The full executive summary below explains the technical reasoning, source confidence, and implementation gates.

## Executive Summary

The KuickPay Blesta Gateway should be treated as a payment-state integration, not a simple "display instructions" gateway. KuickPay's public ASMX pages verify the required operations and parameter surfaces for `InsertVoucher`, `BillPaymentInquiry`, and `BillPaymentBulkInquiry`, but they expose response values as generic strings or XML datasets rather than a fully documented domain contract. That means the PRD's Phase 0 fixture gate is not optional: no code path may post a Blesta transaction until sanitized successful, pending, failed, expired, duplicate, malformed, and unavailable response fixtures are captured and parser behavior is locked down.

Blesta's non-merchant gateway contract is the correct entry point for checkout because it provides `getSettings`, `editSettings`, `encryptableFields`, `setMeta`, `buildProcess`, `validate`, and `success`. The local checkout already contains many non-merchant gateways that follow this extension family, including `offline`, `paysera`, `razorpay`, and others. However, the PRD also requires scheduled reconciliation, admin voucher search/detail pages, manual review, run summaries, and operational retention. Blesta's plugin docs explicitly support custom pages, models, actions, widgets, API extension, event listeners, and cron tasks, so a companion plugin is the cleanest fit for the operational half of the product.

The main implementation risk is duplicate or unsafe payment posting. The design must store a voucher record before and after KuickPay calls, enforce uniqueness on Registration Number, Consumer Number, and KuickPay transaction/auth references, and make posting a database-transaction-protected state transition. Retries are necessary for transient KuickPay or network faults, but retry policy must be conservative because payment operations are not inherently idempotent unless the application supplies durable idempotency keys and unique constraints. Unknown response codes, amount mismatches, late payments, unmatched bulk rows, and malformed XML must go to retry or Manual Review, never paid.

**Key technical findings:**

- Blesta non-merchant gateways are the right checkout surface, but Blesta plugins are the right admin/cron surface. A single deliverable can include both extensions with an explicit dependency check.
- KuickPay's ASMX service verifies operation names and request fields, but not enough business semantics to approve payment posting without fixture evidence.
- PHP 8.2 is locally pinned and should remain the implementation target; PHP 8.2 is security-supported until 2026-12-31, so the gateway should not introduce PHP 8.3+ APIs.
- SOAP response parsing should be isolated behind a parser contract that normalizes strings/XML into a stable internal shape and treats every unrecognized result as unsafe.
- Reconciliation should match stored Consumer Numbers, not infer invoice identity from Consumer Number suffixes.
- Customer-facing payment generation is not payment truth. Only server-side inquiry or bulk reconciliation evidence may trigger a Blesta transaction.
- The MVP should avoid callback/IPN assumptions. If KuickPay later confirms callbacks, model them as an additional untrusted event source with signature/IP allowlist, replay protection, and idempotency.

**Top recommendations:**

1. Build `components/gateways/nonmerchant/kuickpay` plus `plugins/kuickpay_reconcile` as a coupled package, and block gateway enablement if the companion plugin or required tables are missing.
2. Make Phase 0 a hard gate: capture sanitized KuickPay fixtures and approve parser mappings before Payment Posting is enabled.
3. Use a voucher state machine and database uniqueness as the idempotency boundary, not controller/session state.
4. Keep all customer/admin text in language files, all credentials in Blesta encrypted gateway/plugin settings, and all diagnostics redacted.
5. Implement reconciliation as a bounded cron/manual job with timeouts, retry/backoff, run summaries, and Manual Review outputs.

## Table of Contents

1. Research Scope and Methodology
2. Local Project and PRD Source Findings
3. Technology Stack Analysis
4. Integration Patterns Analysis
5. Architectural Patterns and Design
6. Data and State Architecture
7. Security, Compliance, and Logging
8. Implementation Approaches and Testing
9. Technical Recommendations
10. Implementation Roadmap and Risk Assessment
11. Source Verification and Confidence
12. Appendix: Proposed Contracts and Checklists

## 1. Research Scope and Methodology

### Technical Research Scope Confirmation

**Research Topic:** KuickPay Blesta Payment Gateway

**Research Goals:** Convert the PRD into source-verified architecture and implementation research for a safe KuickPay non-merchant Blesta gateway, with special attention to SOAP parsing, payment truth, reconciliation, admin operations, and Blesta extension boundaries.

**Technical Research Scope:**

- Architecture analysis - Blesta extension placement, gateway/plugin split, data ownership, and payment-state transitions.
- Implementation approaches - PHP 8.2 coding patterns, Blesta loader/Input/language conventions, parser isolation, and fixture-first testing.
- Technology stack - Blesta 6.0.0-b1, PHP 8.2, MySQL/PDO, Composer `vendors/`, ASMX/SOAP, XML parsing, and PHPUnit 8.5 constraints.
- Integration patterns - KuickPay voucher creation, single-reference inquiry, bulk inquiry, retries, idempotency, and optional future callbacks.
- Performance and operations - bounded reconciliation, timeouts, retry policy, run summaries, Manual Review, and admin support workflows.

**Scope Confirmed:** 2026-06-09. The user requested YOLO mode, so the normal interactive `[C]` checkpoints were treated as confirmed continuation.

### Methodology

This report used three evidence layers:

- **Repo evidence:** PRD, addendum, review rubric, project context, architecture docs, development guide, API/data-model docs, `composer.json`, existing non-merchant gateways, and existing plugin cron patterns.
- **Primary external docs:** Blesta developer docs, KuickPay public ASMX operation pages, KuickPay customer payment pages, PHP official docs, OWASP guidance, PCI SSC FAQ, Microsoft architecture guidance, and Stripe webhook docs used only as an analogy for duplicate/replay-safe event handling.
- **Confidence grading:** High confidence for local repo constraints and documented operation surfaces; medium confidence for implementation inferences; low confidence for KuickPay status-code semantics until merchant-specific fixtures are captured.

## 2. Local Project and PRD Source Findings

### Local Architecture Facts

The checkout is a Blesta PHP monolith:

- Runtime target: PHP `>=8.2.0` with Composer platform pinned to PHP `8.2`.
- Dependency directory: `vendors/`, not default `vendor/`.
- Application style: Blesta/minPHP MVC with `index.php`, `lib/init.php`, `config/services.php`, `config/routes.php`, app controllers/models/views, components, plugins, and extension folders.
- Database: MySQL-compatible database via PDO.
- Payment gateways: `components/gateways/nonmerchant/<gateway>` and `components/gateways/merchant/<gateway>`.
- Plugins: `plugins/<plugin>` with controllers, models, views, language, config, plugin lifecycle, actions, and cron support.
- Tests: root Composer test scripts target sibling `../tests`; this checkout does not contain a repo-local root test suite.

These facts come from `_bmad-output/project-context.md`, `docs/architecture.md`, `docs/development-guide.md`, `docs/api-contracts.md`, `docs/data-models.md`, and `composer.json`.

### PRD Constraints That Drive Architecture

The PRD is already technically disciplined. Its core constraints are:

- KuickPay is a PKR-first non-merchant bill/reference flow.
- No Blesta invoice may be marked paid unless KuickPay payment is confirmed, amount-checked, duplicate-checked, and posted through Blesta's transaction path.
- Unknown KuickPay responses map to retry or Manual Review, not paid.
- Admin users need settings, searchable vouchers, detail pages, manual check, cancellation/manual review, logging, and reconciliation summaries.
- The parser must be fixture-backed before Payment Posting is approved.
- Multi-invoice support remains conditional on deterministic allocation.
- Production Blesta version is still an open question even though this repo is Blesta 6.0.0-b1.

### Local Code Pattern Findings

Existing non-merchant gateways such as `offline` and `paysera` confirm these local patterns:

- Gateway classes extend `NonmerchantGateway`.
- Constructors load `config.json`, `Input`, and language files.
- `getSettings()` builds a settings `.pdt` view.
- `editSettings()` uses `Input` rules and returns meta.
- `encryptableFields()` lists credential fields that Blesta should encrypt.
- `buildProcess()` renders the customer payment page.
- `validate()` and `success()` are callback/return surfaces that may return transaction data or set errors.

Existing plugins such as `auto_cancel` and `webhooks` confirm:

- Plugin cron tasks are registered in install/upgrade flows via `CronTasks`.
- Cron task runs are removed on uninstall.
- `cron($key)` dispatches by task key.
- Plugin pages and navigation actions are a normal admin-surface pattern.

## 3. Technology Stack Analysis

### Programming Languages

**PHP 8.2 is the implementation target.** The local Composer platform pins PHP to `8.2`, and project context explicitly forbids PHP 8.3+ syntax or APIs without approval. PHP's official supported-versions page says each branch gets two years of active support and two additional years of security support; PHP 8.2 is security-supported until 2026-12-31. Source: [PHP supported versions](https://www.php.net/supported-versions.php).

**Implication:** implement the gateway in conservative PHP 8.2 compatible style. Do not use PHP 8.3 typed class constants, PHP 8.4 property hooks, modern PHPUnit APIs beyond the repo's PHPUnit 8.5 dependency, or strict type sweeps across legacy extension files.

### Frameworks and Libraries

**Blesta non-merchant gateway API.** Blesta's docs state that non-merchant gateways extend `NonmerchantGateway` and implement required gateway methods. The docs identify `getSettings`, `editSettings`, `encryptableFields`, `setMeta`, `validate`, `success`, and optional `buildProcess` as the payment gateway contract. Source: [Blesta non-merchant gateways](https://docs.blesta.com/developers/gateways/non-merchant-gateways/).

**Blesta plugin API.** Blesta's plugin docs describe plugins as MVC-style extension packages that can create pages, widgets, custom cron tasks, event listeners, and API extensions. Source: [Blesta creating a plugin](https://docs.blesta.com/developers/plugins/getting-started/).

**SOAP client.** PHP's `SoapClient` supports WSDL and non-WSDL mode, SOAP 1.1/1.2, tracing, exceptions, connection timeout, WSDL caching, stream contexts, and TLS options. Source: [PHP SoapClient constructor](https://www.php.net/manual/en/soapclient.construct.php).

**Implication:** wrap `SoapClient` in a KuickPay-specific client rather than calling it directly from controllers/views. Use configured WSDL/endpoint, timeouts, `exceptions => true`, sanitized tracing only when enabled, and stream context settings for TLS verification. Avoid leaving `trace` on by default because raw requests contain credentials.

### Database and Storage Technologies

The local app uses MySQL via PDO and Blesta `Record`/model patterns. The KuickPay integration needs its own durable tables, most likely plugin-owned:

- `kuickpay_vouchers`
- `kuickpay_reconciliation_runs`
- optionally `kuickpay_reconciliation_items` if run-level row diagnostics are too large for a summary field

**Relational storage is the right fit** because the MVP depends on uniqueness constraints, status queries, invoice/client joins, transaction references, and admin filtering. Redis or cache storage is not appropriate for payment truth.

### Development Tools and Platforms

The repo has Composer, PHPCS/Slevomat dev dependencies, PHPUnit 8.5, and no root CI configuration. Root tests target `../tests`, so implementation planning must include a fallback verification path:

- `php -l` on changed PHP files.
- parser fixture tests in a local extension test layout if one exists or is introduced with explicit scope.
- optional live KuickPay tests disabled by default and guarded by environment/config.
- database-backed install/upgrade verification when a Blesta runtime and MySQL are available.

### Cloud Infrastructure and Deployment

No Docker, CI, or infrastructure manifests were found. Deployment is a traditional PHP web stack with rewrite support, Blesta runtime configuration, MySQL, writable runtime folders, and cron/controller automation. The gateway should not introduce a new runtime service, queue, Node build, or alternate storage system. A queue can be modeled later through Blesta cron and database-backed work rows if production volume requires it.

## 4. Integration Patterns Analysis

### KuickPay API Surface

The public ASMX operation list includes:

- `BillPaymentBulkInquiry`
- `BillPaymentInquiry`
- `Echo`
- `GetInstitutionsList`
- `InsertVoucher`
- `PaymentInquiry`
- `sendEmail`
- `sendSMS`

Source: [KuickPay ASMX operation list](https://app.kuickpay.com/kuickpaycoreapi/api.asmx).

The PRD's required MVP operations align with the public ASMX operation list.

### InsertVoucher Pattern

`InsertVoucher` is a SOAP operation with credentials, `InstitutionID`, `RegistrationNumber`, ten payment heads/amounts, `TotalAmount`, due/expiry/issue dates, voucher month/year, name, mobile, email, and branch fields. The response sample exposes `InsertVoucherResult` as a string. Source: [KuickPay InsertVoucher](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=InsertVoucher).

**Design consequences:**

- Store the voucher record before the SOAP call with a creating/pending-create status and a generated Registration Number.
- Store the raw sanitized response and parser output after the call.
- Treat `InsertVoucherResult` as a parser input, not as business truth.
- Keep all date formats configurable or centrally normalized until KuickPay confirms official expected formats.
- Use `Amount1` and `TotalAmount` as the same PKR amount for MVP unless fee policy changes.

### Single-Reference Inquiry Pattern

`BillPaymentInquiry` accepts credentials and `consumerNumber`, and returns `BillPaymentInquiryResult` as a string. Source: [KuickPay BillPaymentInquiry](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=BillPaymentInquiry).

**Design consequences:**

- Inquiry should only run for stored Pending Vouchers unless an admin explicitly rechecks another state.
- Match the response back to the stored Consumer Number and voucher ID.
- Validate amount, reference identity, current voucher state, and duplicate posting before posting.
- Unknown strings or fields move to Manual Review or retry.

### Bulk Reconciliation Pattern

`BillPaymentBulkInquiry` accepts credentials, `InstitutionID`, and `TransactionDate`, and returns an XML dataset-like result. Source: [KuickPay BillPaymentBulkInquiry](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=BillPaymentBulkInquiry).

**Design consequences:**

- Bulk reconciliation is a safety net, not the primary customer path.
- Match rows by stored Consumer Number only.
- Never infer invoice identity by slicing a Consumer Number suffix.
- Store run summaries and unmatched rows for Manual Review.
- Parse XML defensively. Do not enable entity expansion, external network entity loading, or unsafe XML flags.

### Customer Payment Channel Reality

KuickPay's own billing page says businesses can collect through digital banking, cards, and OTC channels, and the how-to-pay page shows customers entering a Consumer Number in banking, ATM, branch, and agent flows. Sources: [KuickPay billing](https://kuickpay.com/billing/) and [KuickPay how to pay](https://app.kuickpay.com/paymentsbillpayment?cn=01500).

**Design consequences:**

- The Blesta customer page should optimize for clear copy/display of Consumer Number, amount, due date, expiry date, and instructions.
- The customer page must state that Blesta updates after KuickPay confirmation.
- Generating or displaying a voucher is not a payment event.

### Optional Callback/IPN Pattern

The PRD asks whether KuickPay supports webhook/callback/IPN. Public ASMX evidence found here does not prove a callback contract. If KuickPay later confirms a callback, use payment-industry event-handling patterns:

- Verify source/signature if provided.
- Reject stale/replayed payloads.
- Persist event IDs or derived idempotency keys.
- Process asynchronously or through a database work queue.
- Treat callbacks as signals to run server-side inquiry, not direct payment truth unless KuickPay's signed payload contract is strong enough.

Stripe's webhook docs are not KuickPay docs, but they are useful current evidence for duplicate-event and replay-safe event handling: Stripe recommends signature verification, duplicate event tracking, asynchronous processing, HTTPS, secret rotation, and timestamp replay protection. Source: [Stripe webhooks](https://docs.stripe.com/webhooks?lang=node&locale=en-GB).

## 5. Architectural Patterns and Design

### Recommended Extension Shape

Use a coupled Blesta extension package:

```text
components/gateways/nonmerchant/kuickpay/
  config.json
  kuickpay.php
  lib/
    kuickpay_client.php
    kuickpay_parser.php
    kuickpay_redactor.php
    kuickpay_exceptions.php
  language/
    en_us/kuickpay.php
  views/default/
    process.pdt
    settings.pdt

plugins/kuickpay_reconcile/
  config.json
  kuickpay_reconcile_plugin.php
  controllers/
    admin_manage_plugin.php
  models/
    kuickpay_vouchers.php
    kuickpay_reconciliation_runs.php
  language/
    en_us/kuickpay_reconcile_plugin.php
  views/default/
    admin_vouchers.pdt
    admin_voucher_detail.pdt
    admin_reconciliation_runs.pdt
```

The gateway should fail settings validation or display a clear admin setup error if the companion plugin/tables are missing. This makes the cross-extension dependency explicit instead of hiding direct SQL inside the gateway.

### Gateway Responsibilities

The gateway should own:

- `config.json` metadata and PKR-supported currency configuration.
- admin gateway settings for WSDL URL, credentials, Institution ID, patterns, dates, timeouts, instruction toggles, logging, and same-as-voucher credential policy.
- `encryptableFields()` for voucher and inquiry passwords.
- `editSettings()` validation for required fields, HTTPS URL, numeric Institution ID if required by merchant contract, timeouts, and pattern syntax.
- `buildProcess()` for create-or-reuse voucher and customer display.
- sanitized logging for gateway calls.
- no direct Payment Posting from customer-side data.

`validate()` and `success()` should be conservative. If KuickPay does not provide a return/callback payment proof contract, these methods should not approve transactions. They can return `pending` or unsupported/null depending on Blesta behavior confirmed during implementation, while the reconciliation service remains the only approved posting path.

### Plugin Responsibilities

The companion plugin should own:

- database install/upgrade/uninstall for voucher and reconciliation tables.
- model APIs for create/reuse voucher, status transitions, inquiry update, bulk match, post transaction, and admin notes.
- admin navigation action under an appropriate admin tools/billing surface.
- voucher list filters by status, client, invoice, Consumer Number, date range, amount, KuickPay transaction/auth fields, and Blesta transaction ID.
- voucher detail page with sanitized raw diagnostic summary.
- Check Now, Cancel, and Mark Manual Review actions.
- cron task for scheduled reconciliation and optional bulk daily run.
- retention policy for reconciliation logs/diagnostics if approved.

Blesta plugin docs support plugin file structure, plugin pages, MVC patterns, install/upgrade/uninstall hooks, and cron tasks. Sources: [Blesta creating a plugin](https://docs.blesta.com/developers/plugins/getting-started/) and [Blesta plugin cron tasks](https://docs.blesta.com/developers/plugins/plugin-cron-tasks/).

### Why Not Gateway Only?

A gateway-only implementation would be simpler to package, but it is a poor fit for the PRD's operational requirements. Existing non-merchant gateway examples are good for settings, process rendering, and callback validation. They are not a natural home for searchable admin operations, scheduled reconciliation task registration, run summaries, and Manual Review workflow. A gateway-only design would likely drift into custom routes or hidden model behavior that Blesta already expects plugins to own.

### Why Not Core App Changes?

The PRD and project context both prohibit Blesta core edits. Core app changes would also increase upgrade risk for a migration project. Extension-owned code keeps the integration installable, rollbackable, and aligned with Blesta's extension discovery model.

### Resilience Pattern

KuickPay calls are remote service calls and should use bounded retry. Microsoft's retry pattern guidance distinguishes cancel, immediate retry, and retry-after-delay strategies; it also warns that retries can execute non-idempotent operations more than once if the first request succeeded but the response failed. Source: [Microsoft Retry pattern](https://learn.microsoft.com/ka-ge/azure/architecture/patterns/retry).

For this product:

- Retry inquiry more readily than voucher creation.
- Do not retry `InsertVoucher` blindly after a transport failure unless the Registration Number lookup/reuse semantics are proven. A duplicate create may produce multiple payable references or ambiguous responses.
- Use configured timeouts and small retry counts.
- Add jitter/backoff to scheduled reconciliation to avoid synchronized API pressure.
- Escalate repeated failures to run summary errors, not infinite loops.

Microsoft's transient-fault guidance defines idempotency, circuit breaker, exponential backoff, jitter, timeout, and dead-letter queue concepts that map directly to reconciliation design. Source: [Microsoft transient fault handling](https://learn.microsoft.com/en-us/azure/well-architected/design-guides/handle-transient-faults).

## 6. Data and State Architecture

### Voucher State Machine

Recommended statuses:

```text
creating
pending
failed
expired
paid_confirmed
posted
manual_review
canceled
```

An alternative is to keep `paid` as the posted state and store confirmation separately. The safer model is to distinguish confirmed-by-KuickPay from posted-to-Blesta:

- `paid_confirmed`: KuickPay evidence validated, but Blesta transaction not yet posted.
- `posted`: Blesta transaction created/applied and voucher linked to transaction ID.

This separation makes recovery clearer if posting fails after confirmation.

### Voucher Table

Recommended fields:

```text
id
company_id
gateway_id
client_id
invoice_ids_json
invoice_amounts_json
currency
amount
amount_after_due_date
voucher_fee
posted_gateway_fee
registration_number
registration_random
consumer_number
institution_id
voucher_id
auth_id
payment_channel
status
status_reason
issue_date
due_date
expiry_date
payment_date
kuickpay_transaction_id
kuickpay_transaction_ref
blesta_transaction_id
insert_request_hash
insert_response_raw
insert_status_code
last_inquiry_at
last_inquiry_response_raw
last_inquiry_status_code
last_bulk_seen_at
last_error
admin_note
created_at
updated_at
```

Recommended constraints:

- Unique `(company_id, registration_number)`.
- Unique `(company_id, consumer_number)`.
- Unique KuickPay transaction/auth/reference where present.
- Unique Blesta transaction ID where present.
- Index `(company_id, status)`.
- Index `(company_id, client_id)`.
- Index `(company_id, payment_date)`.
- If MySQL supports it in the target version, enforce one active voucher per invoice context through a generated active key or application-level transaction plus unique active context table. If not, enforce in model transaction with row locks.

### Reconciliation Run Table

Recommended fields:

```text
id
company_id
run_type
status
started_at
completed_at
vouchers_checked
payments_confirmed
payments_posted
manual_review_count
unmatched_count
error_count
raw_summary
error_message
created_by_staff_id
created_at
updated_at
```

Recommended run types:

- `single`
- `cron`
- `bulk`
- `manual`

### Idempotency Boundary

Use the database as the idempotency boundary:

- Voucher create/reuse checks must run in a transaction.
- Payment Posting must lock the voucher or enforce a compare-and-update transition from `paid_confirmed` to `posted`.
- Duplicate transaction references must be rejected by unique constraints.
- A failed posting after confirmation must remain recoverable without losing the KuickPay evidence.

OWASP's payment functionality testing guidance specifically calls out race condition mitigation with database transactions, row-level locking, optimistic locking, idempotency keys, and atomic invalidation. Source: [OWASP Test Payment Functionality](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/10-Test-Payment-Functionality).

### Amount Handling

KuickPay SOAP samples define amount fields as `double`, but PHP application logic should not treat floats as payment truth. Recommended approach:

- Store amounts as fixed-scale decimals in the database.
- Normalize Blesta invoice/payment amounts to PKR decimal strings.
- Format SOAP edge values only in the KuickPay client.
- Compare decimal strings or integer paisa values, not PHP floats.
- Apply configured rounding once at the boundary and store the formatted amount sent to KuickPay.

This avoids subtle float mismatch bugs where a confirmed amount appears unequal due to binary floating point representation.

## 7. Security, Compliance, and Logging

### Credential Storage

Blesta's gateway docs identify `encryptableFields()` as the method that returns meta fields to encrypt. Source: [Blesta non-merchant gateways](https://docs.blesta.com/developers/gateways/non-merchant-gateways/).

For KuickPay:

- `voucher_password` and `inquiry_password` must be encryptable fields.
- If same-as-voucher is enabled, do not duplicate password values in logs or diagnostics.
- Settings pages must show masked values and allow rotation.
- Do not store production credentials in docs, fixtures, or example config.

### Logging and Diagnostics

OWASP logging guidance emphasizes application-level logs for operational and security use cases, and recommends enough "when, where, who, what" context for investigation while avoiding overcollection. Source: [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html).

Recommended KuickPay log fields:

- operation name
- voucher ID
- reconciliation run ID
- company ID
- client ID if safe and admin-only
- Consumer Number masked if displayed outside admin-only views
- request/response hash
- normalized status
- error class
- duration
- timestamp
- staff ID for manual actions

Never log:

- KuickPay passwords
- raw unredacted SOAP envelopes
- full customer contact data unless specifically needed and admin-only
- `config/blesta.php` values
- future card details if card flows are ever added

### Payment Security

The MVP is a bill/reference flow, not card capture. Keep it that way. OWASP notes payment integrations can use redirects, iframes, cross-domain posts, or backend API calls, and that systems processing payment data must treat business logic as security-critical. Source: [OWASP Test Payment Functionality](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/10-Test-Payment-Functionality).

PCI SSC states sensitive authentication data must not be stored after authorization even if encrypted. Source: [PCI SSC FAQ 1533](https://www.pcisecuritystandards.org/faqs/1533/). The MVP should avoid card data entirely; if KuickPay card collection is later added through Checkout or portal flows, the compliance model must be reviewed separately.

### XML and SOAP Safety

For SOAP/XML:

- Enforce HTTPS WSDL/endpoint unless a staging exception is explicitly configured.
- Validate TLS certificates.
- Keep `SoapClient` trace disabled by default.
- Redact `userName`, `password`, mobile, email, and raw body fields before diagnostics.
- Parse bulk XML with safe libxml settings, no entity expansion, and bounded input length.
- Treat XML parse warnings as parser errors that go to retry or Manual Review.

### Admin Authorization

Admin list/detail/manual actions must use Blesta plugin controller patterns and ACL/action registration. Do not expose voucher admin actions through direct public plugin routes without admin authentication and permission checks. `Check Now`, `Cancel`, and `Mark Manual Review` are financial support actions and should require staff intent, CSRF protection through Blesta forms, and audit notes.

## 8. Implementation Approaches and Testing

### Parser-First Development

Start with `kuickpay_parser.php` and fixtures before building posting:

```php
[
    'success' => true,
    'status' => 'pending|paid_confirmed|failed|expired|manual_review|retry',
    'consumer_number' => '...',
    'registration_number' => '...',
    'voucher_id' => '...',
    'auth_id' => '...',
    'transaction_id' => '...',
    'transaction_ref' => '...',
    'amount' => '...',
    'payment_date' => '...',
    'message' => '...',
    'raw_summary' => '...',
    'reason_code' => '...',
]
```

Parser tests should cover:

- `InsertVoucherResult` success.
- `InsertVoucherResult` duplicate/failed/unknown.
- `BillPaymentInquiryResult` paid.
- pending/unpaid.
- failed/expired.
- malformed comma string.
- missing amount.
- amount with comma separator or unexpected decimal scale.
- unknown code.
- bulk XML with one match.
- bulk XML with multiple rows.
- malformed bulk XML.
- unmatched Consumer Number.
- redaction of secrets.

### Client Implementation

`kuickpay_client.php` should:

- construct `SoapClient` from configured WSDL/endpoint.
- support SOAP 1.1 by default unless merchant testing requires SOAP 1.2.
- set connection timeout and socket timeout through configuration/runtime wrapper.
- catch `SoapFault` and transport exceptions.
- return a typed response wrapper: operation, success/failure, raw response, duration, and error class.
- never decide paid/not paid. That belongs to the parser plus reconciliation service.

### Settings Implementation

Admin settings should include:

- WSDL URL.
- voucher username/password.
- inquiry username/password.
- same-as-voucher toggle.
- Institution ID.
- Registration Number pattern.
- Consumer Number pattern.
- payment head label.
- issue/due/expiry date policies.
- fallback mobile policy.
- PKR currency policy.
- fee policy.
- instruction group toggles.
- logging toggle.
- reconciliation toggles and schedule.
- single and bulk timeouts.
- optional controlled live voucher test flag.

Validate required settings through Blesta `Input` rules. The safe connection test should use `Echo` or `GetInstitutionsList` if credentials permit, or a non-payable metadata check. Do not run `InsertVoucher` from a generic connection test.

### Customer Page Implementation

`buildProcess()` should:

- block non-PKR attempts with language-file text.
- reject unsupported multi-invoice attempts unless deterministic allocation is implemented.
- call plugin model service to create or reuse an active voucher.
- show Consumer Number, amount, due date, expiry date, and enabled instruction groups.
- provide copy affordance through plain JS only if consistent with Blesta views.
- show "payment updates after confirmation" messaging.
- never show raw SOAP output.

### Posting Implementation

Posting should happen only through the reconciliation service:

1. Load voucher by ID with current state.
2. Verify state is eligible.
3. Verify parser status is `paid_confirmed`.
4. Verify amount equals stored payable amount or configured policy handles mismatch.
5. Verify Consumer Number and transaction/auth references match stored voucher.
6. Verify no Blesta transaction is linked.
7. Verify KuickPay transaction/auth/reference uniqueness.
8. Begin model transaction.
9. Create and apply Blesta transaction through the normal model/service path.
10. Store Blesta transaction ID and move voucher to `posted`.
11. Commit.
12. On failure, roll back and preserve confirmation evidence for retry/manual action.

### Verification Strategy

Minimum implementation verification:

- `php -l` on every changed PHP file.
- parser unit tests with sanitized fixtures.
- settings validation tests for required/invalid fields and encrypted field list.
- model tests for create/reuse idempotency and duplicate posting where DB test environment exists.
- manual admin/client smoke tests in a real Blesta web stack.
- optional live tests disabled by default and requiring explicit credentials/config.

Do not claim root PHPUnit coverage unless sibling `../tests` is available.

## 9. Technical Recommendations

### Architecture Recommendations

- Use gateway plus companion plugin.
- Make the companion plugin a hard setup dependency.
- Keep code inside extension folders. Do not edit Blesta core.
- Use plugin models as the API for voucher persistence and reconciliation.
- Keep SOAP client/parser separate from Blesta controller code.
- Make unknown response handling explicit and test-covered.

### Technology Stack Recommendations

- PHP 8.2 compatible code only.
- Blesta `Loader`, `Input`, `Record`, language files, `.pdt`, and plugin/gateway lifecycle patterns.
- MySQL/PDO durable state with database uniqueness.
- PHP `SoapClient` wrapped behind `KuickPayClient`.
- `SimpleXML`/libxml only with defensive parsing for bulk results.
- PHPUnit 8.5-compatible tests if a local/extension test suite is introduced.

### Operational Recommendations

- Start with reconciliation intervals conservative enough to respect unknown KuickPay rate limits.
- Add "Check Now" for support but route it through the same parser/posting service as cron.
- Add bulk reconciliation as a daily/audit safety net, not a replacement for single-reference polling.
- Add run summaries from day one so support can distinguish KuickPay outage from parser/manual-review issues.
- Add a launch runbook: first production payment, first delayed payment, first under/overpayment, first unmatched bulk row, credential rotation, rollback.

### Product Scope Recommendations

- Keep non-PKR blocked until currency conversion policy is approved.
- Block multi-invoice payment attempts until deterministic allocation is proven in staging.
- Keep refunds, voids, reversals, voucher cancellation through KuickPay, callbacks, partial payment policy, overpayment policy, and fee charging outside MVP unless KuickPay/operations confirm the contract.
- Make Manual Review a feature, not a defect. It is the safe output for unproven processor behavior.

## 10. Implementation Roadmap and Risk Assessment

### Phase 0: API Contract Validation

Goals:

- Confirm production Blesta version.
- Confirm KuickPay endpoint/WSDL for production and staging.
- Confirm official date formats.
- Confirm Consumer Number formula.
- Confirm credentials and whether voucher/inquiry credentials differ.
- Capture sanitized fixtures for every status class.
- Confirm rate limits and polling guidance.

Exit gate:

- Parser fixture matrix accepted.
- No Payment Posting code path enabled without fixture-backed status mapping.

### Phase 1: Gateway Setup and Voucher Generation

Deliver:

- gateway skeleton and `config.json`.
- settings page and validation.
- encrypted credential fields.
- safe connection test.
- registration/consumer number generation.
- create/reuse voucher model API.
- customer process page with instructions.
- no Payment Posting.

Main risks:

- duplicate active vouchers.
- unsafe live test voucher creation.
- hard-coded production values.

### Phase 2: Reconciliation and Posting

Deliver:

- single-reference inquiry service.
- bounded scheduled reconciliation.
- posting transaction service.
- duplicate transaction prevention.
- amount/reference validation.
- retry/manual-review states.

Main risks:

- duplicate posting from cron/manual concurrency.
- float amount mismatch.
- treating unknown response as paid.

### Phase 3: Admin Operations

Deliver:

- plugin admin nav/action.
- voucher list/filter.
- voucher detail.
- Check Now.
- Cancel.
- Mark Manual Review.
- run summaries.
- redacted diagnostics.

Main risks:

- exposing raw responses or secrets.
- actions bypassing admin permissions.
- support cannot explain delayed payments.

### Phase 4: Bulk Reconciliation and Launch Hardening

Deliver:

- bulk inquiry by date.
- unmatched row handling.
- launch runbook.
- controlled production test.
- first-week monitoring checklist.

Main risks:

- bulk matching by inferred suffix instead of stored Consumer Number.
- large bulk result causing slow admin/cron behavior.
- unknown rate limits.

## 11. Source Verification and Confidence

### Primary External Sources

- [Blesta non-merchant gateways](https://docs.blesta.com/developers/gateways/non-merchant-gateways/) - gateway methods, encrypted fields, settings, process, validate/success.
- [Blesta payment gateway overview](https://docs.blesta.com/category/payment-gateways/) - merchant vs non-merchant payment gateway distinction.
- [Blesta creating a plugin](https://docs.blesta.com/developers/plugins/getting-started/) - plugin MVC structure and capabilities.
- [Blesta plugin cron tasks](https://docs.blesta.com/developers/plugins/plugin-cron-tasks/) - install/upgrade cron task registration and uninstall cleanup.
- [Blesta deprecated functionality](https://docs.blesta.com/developers/deprecated/) - current deprecation context for events and APIs.
- [KuickPay ASMX operations](https://app.kuickpay.com/kuickpaycoreapi/api.asmx) - operation list.
- [KuickPay InsertVoucher](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=InsertVoucher) - voucher request fields and result wrapper.
- [KuickPay BillPaymentInquiry](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=BillPaymentInquiry) - single Consumer Number inquiry fields and result wrapper.
- [KuickPay BillPaymentBulkInquiry](https://app.kuickpay.com/kuickpaycoreapi/api.asmx?op=BillPaymentBulkInquiry) - Institution ID/date bulk inquiry fields and XML result wrapper.
- [KuickPay billing](https://kuickpay.com/billing/) - bill payment modes and aggregation positioning.
- [KuickPay how to pay](https://app.kuickpay.com/paymentsbillpayment?cn=01500) - Consumer Number payment behavior across banking/ATM/OTC channels.
- [PHP supported versions](https://www.php.net/supported-versions.php) - PHP branch support window.
- [PHP SoapClient constructor](https://www.php.net/manual/en/soapclient.construct.php) - SOAP client options, timeouts, tracing, exceptions, TLS notes.
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) - application logging event content and safety guidance.
- [OWASP Test Payment Functionality](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/10-Test-Payment-Functionality) - payment flow testing, server-side pricing, race/idempotency concerns.
- [PCI SSC FAQ 1533](https://www.pcisecuritystandards.org/faqs/1533/) - sensitive authentication data storage prohibition.
- [Microsoft Retry pattern](https://learn.microsoft.com/ka-ge/azure/architecture/patterns/retry) - retry strategies and idempotency risks.
- [Microsoft transient fault handling](https://learn.microsoft.com/en-us/azure/well-architected/design-guides/handle-transient-faults) - transient fault terminology and retry/circuit breaker concepts.
- [Stripe webhooks](https://docs.stripe.com/webhooks?lang=node&locale=en-GB) - duplicate/replay-safe webhook design analogy.

### Local Sources

- `_bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/prd.md`
- `_bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/addendum.md`
- `_bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/review-rubric.md`
- `_bmad-output/planning-artifacts/prds/prd-whmcs_blesta-2026-06-09/reconcile-source-intake.md`
- `_bmad-output/project-context.md`
- `docs/architecture.md`
- `docs/development-guide.md`
- `docs/api-contracts.md`
- `docs/data-models.md`
- `composer.json`
- `components/gateways/nonmerchant/offline/offline.php`
- `components/gateways/nonmerchant/paysera/paysera.php`
- `plugins/auto_cancel/auto_cancel_plugin.php`
- `plugins/webhooks/webhooks_plugin.php`

### Confidence Levels

**High confidence:**

- Blesta non-merchant gateway contract.
- Blesta plugin suitability for admin pages and cron.
- local PHP 8.2 and Blesta 6.0.0-b1 constraints.
- KuickPay operation names and visible SOAP request fields.
- need for encrypted credential fields and redacted logs.

**Medium confidence:**

- gateway plus companion plugin as final package shape. This is strongly indicated by PRD plus Blesta docs, but architecture workflow should still decide packaging and dependency details.
- SOAP 1.1 default. Public pages show SOAP 1.1 and SOAP 1.2; merchant testing should confirm preferred binding.
- database schema fields and status names. These are recommended contracts, not final architecture artifacts.

**Low confidence until Phase 0 evidence:**

- exact KuickPay status codes.
- meaning of `00` across every operation.
- voucher ID substring positions.
- exact paid amount/payment date field indexes.
- Consumer Number formula for this merchant.
- rate limits and polling intervals.
- partial/over/late/refund/cancel support.

## 12. Appendix: Proposed Contracts and Checklists

### Phase 0 Fixture Matrix

Required sanitized fixtures before Payment Posting:

| Operation | Case | Expected internal status |
| --- | --- | --- |
| InsertVoucher | success | pending |
| InsertVoucher | duplicate registration | manual_review or failed, pending merchant semantics |
| InsertVoucher | invalid credentials | failed |
| InsertVoucher | malformed result | manual_review |
| InsertVoucher | timeout after send | retry/manual_review until lookup rule proven |
| BillPaymentInquiry | unpaid/pending | pending |
| BillPaymentInquiry | paid exact amount | paid_confirmed |
| BillPaymentInquiry | paid mismatched amount | manual_review |
| BillPaymentInquiry | expired | expired or manual_review, pending merchant semantics |
| BillPaymentInquiry | unknown code | manual_review |
| BillPaymentBulkInquiry | matching paid Consumer Number | paid_confirmed |
| BillPaymentBulkInquiry | unmatched Consumer Number | manual_review run item |
| BillPaymentBulkInquiry | malformed XML | retry/manual_review |

### Minimal Acceptance Checklist for Architecture

- Extension split decided: gateway only vs gateway plus plugin. This report recommends gateway plus plugin.
- Production Blesta version confirmed.
- Schema ownership decided.
- Parser contract approved.
- Voucher state machine approved.
- Reconciliation schedule and timeout policy approved.
- Manual Review states and admin action permissions approved.
- Fee, partial, overpayment, late-payment, and multi-invoice policies approved or explicitly blocked.

### Implementation Checklist

- No Blesta core edits.
- PHP 8.2 compatible code.
- Credentials in `encryptableFields()`.
- Customer text in language files.
- Admin text in plugin language files.
- `.pdt` views stay thin.
- All KuickPay calls go through client wrapper.
- All raw responses go through redactor before storage/logging.
- Parser tests cover every fixture.
- Posting service uses transaction and duplicate guards.
- Cron is bounded and observable.
- Live tests are opt-in only.

## Technical Research Conclusion

The PRD is architecture-ready if Phase 0 is respected as a hard payment-truth gate. The safest implementation is not a generic offline gateway and not a core modification. It is a Blesta-native extension pair: a non-merchant gateway that creates/reuses and displays KuickPay payment references, and a companion plugin that owns durable voucher state, reconciliation, admin operations, and safe transaction posting.

The implementation should optimize for auditability and recoverability over speed. If KuickPay is ambiguous, unavailable, late, or mismatched, the correct product behavior is pending or Manual Review. Automated posting should be narrow, fixture-backed, amount-checked, duplicate-checked, and reversible only through normal Blesta/admin accounting processes rather than hidden retry side effects.

**Technical Research Completion Date:** 2026-06-09
**Research Period:** current comprehensive technical analysis
**Source Verification:** external sources verified during this run and linked above
**Technical Confidence Level:** high for Blesta/local architecture and KuickPay operation surfaces; deliberately low for KuickPay payment semantics until merchant-specific fixtures are captured
