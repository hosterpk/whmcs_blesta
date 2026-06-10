---
title: KuickPay Blesta Payment Gateway
status: final
created: 2026-06-09
updated: 2026-06-09
---

# PRD: KuickPay Blesta Payment Gateway

## 0. Document Purpose

This PRD defines the requirements for adding KuickPay as a Blesta payment option for HosterPK's WHMCS-to-Blesta migration. It is intended for product, operations, architecture, engineering, QA, and support handoff. The document uses a Glossary-anchored vocabulary, groups product capabilities into features, and assigns globally stable Functional Requirement IDs. Implementation details that would crowd the PRD are preserved in `addendum.md`.

## 1. Vision

HosterPK customers need a local Pakistan payment path inside Blesta that works with the way they already pay bills: through bank channels, mobile wallets, ATMs, branches, agents, and KuickPay-supported payment apps. The KuickPay Gateway lets a customer open a Blesta invoice, generate or reuse a payable KuickPay Consumer Number, pay externally, and have Blesta reflect the payment after confirmation.

The product must reduce staff effort and payment ambiguity during the Blesta migration. Without it, staff would manually create references, answer avoidable support tickets, verify payments outside Blesta, and post transactions by hand. That creates slow invoice clearance, duplicate payment risk, and inconsistent finance records.

The gateway should be conservative where payment truth is uncertain. KuickPay responses are externally controlled and may be opaque, so the product must fail closed: no invoice may be marked paid unless the payment is confirmed, amount-checked, duplicate-checked, and posted through Blesta's normal transaction path.

## 2. Target User

### 2.1 Jobs To Be Done

- As a customer, I want to pay a Blesta invoice through a familiar local payment channel without contacting support.
- As a customer, I want one clear Consumer Number, amount, due date, expiry date, and payment instruction set so I can complete payment without guessing.
- As a support agent, I want to find a customer's KuickPay payment attempt quickly and explain whether it is pending, paid, failed, expired, or under review.
- As a finance/admin user, I want KuickPay confirmations reconciled into Blesta transactions without duplicate posting.
- As an operator, I want credentials, URLs, institution data, instruction groups, fees, timeouts, and reconciliation behavior configured without editing Blesta core or hard-coding production values.
- As a developer, I want a parser and fixture-backed contract so KuickPay's raw SOAP responses cannot leak ambiguity into invoice posting logic.

### 2.2 Non-Users (v1)

- Customers who need card tokenization, saved payment accounts, or Blesta-initiated recurring auto-charge.
- Staff who need API refunds, voids, or reversals through KuickPay before KuickPay confirms official support.
- Non-PKR invoice flows unless a separate currency conversion policy is approved.

### 2.3 Key User Journeys

- **UJ-1. Ayesha pays a hosting invoice through mobile banking.**
  - **Persona + context:** Ayesha is a HosterPK customer with an unpaid Blesta invoice and prefers local bank or wallet bill payment.
  - **Entry state:** She is viewing an unpaid invoice in the Blesta client area and KuickPay is enabled for PKR.
  - **Path:** She selects KuickPay, the gateway creates or reuses a Voucher, Blesta shows the Consumer Number, amount, due date, expiry date, and payment instructions, and she pays through a supported channel.
  - **Climax:** She sees the exact Consumer Number and payment amount before leaving Blesta.
  - **Resolution:** Reconciliation later confirms payment and the invoice becomes paid in Blesta.
  - **Edge case:** If KuickPay is temporarily unavailable, she sees a retry-safe message and no paid status is inferred.

- **UJ-2. Ahmed investigates a delayed payment.**
  - **Persona + context:** Ahmed is a support agent responding to a customer who claims they paid but still sees an unpaid invoice.
  - **Entry state:** He is authenticated in Blesta admin.
  - **Path:** He opens the KuickPay Voucher list, searches by invoice ID or Consumer Number, opens the Voucher detail, reviews the normalized status and diagnostic summary, and runs Check Now.
  - **Climax:** He can tell whether the payment is pending, paid, failed, expired, or needs Manual Review.
  - **Resolution:** He either confirms the invoice is already posted, triggers safe posting when confirmed, or escalates with enough sanitized evidence.
  - **Edge case:** If the response is ambiguous, the Voucher moves to Manual Review instead of being posted.

- **UJ-3. Nadia closes daily reconciliation.**
  - **Persona + context:** Nadia is responsible for finance reconciliation and wants Blesta to reflect KuickPay payments without duplicate transactions.
  - **Entry state:** Scheduled reconciliation has run, or she starts a daily bulk run manually.
  - **Path:** The system polls Pending Vouchers and optionally runs Bulk Reconciliation by date, matches confirmed payments to stored Consumer Numbers, validates amount/reference uniqueness, and posts Blesta transactions.
  - **Climax:** Paid invoices are updated without staff re-entering payment details.
  - **Resolution:** Nadia reviews the run summary, unmatched payments, and Manual Review queue.
  - **Edge case:** Overpayments, underpayments, late payments after expiry, or unmatched references are surfaced for Manual Review.

## 3. Glossary

- **Admin Setting** - A configurable value maintained in Blesta admin, such as credential, URL, institution ID, fee policy, timeout, instruction group, or reconciliation behavior.
- **Bulk Reconciliation** - A KuickPay inquiry mode that returns payment data for an institution and transaction date, used as a safety net for missed single-reference checks.
- **Consumer Number** - The payable KuickPay reference shown to customers. The default pattern is Institution ID plus Registration Number.
- **Gateway Credential** - A KuickPay username or password used for voucher creation or inquiry. Password values are encrypted at rest and redacted from logs.
- **Instruction Group** - A configurable customer-facing group of payment instructions, such as online banking, bank deposit, agent/franchise shop, or mobile app.
- **KuickPay Gateway** - The Blesta non-merchant payment capability that creates Vouchers, displays payment instructions, and supports reconciliation.
- **Manual Review** - A Voucher state requiring staff action because the system cannot safely interpret the response or match the payment.
- **Payment Attempt** - A customer's attempt to pay one invoice or supported invoice set through the KuickPay Gateway.
- **Payment Posting** - Creating and applying a Blesta transaction after KuickPay payment confirmation.
- **Pending Voucher** - A Voucher that exists but has not yet been confirmed as paid, failed, expired, cancelled, or moved to Manual Review.
- **Raw Diagnostic Summary** - Sanitized request/response information retained for admin troubleshooting without exposing secrets.
- **Reconciliation** - The process of checking KuickPay for payment confirmation and applying confirmed payments to Blesta.
- **Registration Number** - The merchant-side KuickPay bill/reference suffix sent to `InsertVoucher`; by default it is generated from a random prefix plus invoice ID.
- **Voucher** - The stored KuickPay payment record for a Payment Attempt, including Registration Number, Consumer Number, amount, status, response data, and invoice mapping.

## 4. Features

### 4.1 Gateway Availability and Setup

**Description:** Admin users can install and configure the KuickPay Gateway as a Blesta non-merchant gateway for PKR payments without modifying Blesta core. The setup flow must support secure credential entry, configurable defaults, and a connection test that cannot accidentally create a payable Voucher.

**Functional Requirements:**

#### FR-1: Installable KuickPay Gateway

Admin users can install, enable, disable, upgrade, and uninstall the KuickPay Gateway through Blesta extension flows.

**Consequences (testable):**
- Blesta detects the gateway as a non-merchant payment gateway.
- The gateway can be enabled for PKR without editing Blesta core files.
- Install, upgrade, and uninstall paths do not remove unrelated Blesta or extension data.

#### FR-2: Configurable Admin Settings

Admin users can configure all KuickPay Gateway behavior required for voucher creation, inquiry, display, and reconciliation.

**Consequences (testable):**
- Settings include WSDL URL, voucher credentials, inquiry credentials, same-as-voucher toggle, Institution ID, Registration Number pattern, Consumer Number pattern, payment head label, due/expiry behavior, fallback mobile policy, currency policy, fee policy, instruction group toggles, logging toggle, reconciliation toggles, and timeout values.
- Required settings cannot be saved empty.
- HTTPS URL and numeric fields are validated before save.

#### FR-3: Encrypted Credential Storage

The KuickPay Gateway stores Gateway Credential passwords encrypted and masks them in every display, log, and diagnostic path.

**Consequences (testable):**
- Voucher API password and inquiry API password are returned by `encryptableFields()` or an equivalent Blesta-supported encryption mechanism.
- Password values never appear in Raw Diagnostic Summary, customer pages, admin logs, exception messages, fixtures, or deployment docs.
- Credential rotation can be completed through Admin Settings without code deployment.

#### FR-4: Safe Connection Testing

Admin users can test KuickPay connectivity and credential shape without creating a payable Voucher unless explicitly running a controlled live voucher test.

**Consequences (testable):**
- Test connection reports success, credential failure, API timeout, or unavailable endpoint.
- Test connection does not mark any invoice paid.
- Any live voucher test requires explicit admin intent and creates a clearly identifiable test record.

#### FR-5: PKR-First Currency Policy

The MVP supports PKR payments only unless an approved currency conversion policy is configured.

**Consequences (testable):**
- Non-PKR invoice payment attempts are blocked or routed away from KuickPay with a clear message.
- No USD-to-PKR conversion value is hard-coded in business logic.
- Currency behavior is visible in Admin Settings.

### 4.2 Voucher Generation

**Description:** Customers can generate or view a Voucher for an eligible invoice Payment Attempt. Voucher creation is idempotent, records all required reference data, and never duplicates active references for the same payment context.

**Functional Requirements:**

#### FR-6: Create or Reuse Voucher

When a customer selects KuickPay for an eligible Payment Attempt, the system creates a new Voucher only if no valid Pending Voucher already exists for the same invoice context.

**Consequences (testable):**
- A repeated payment-page refresh reuses the existing Pending Voucher.
- If the amount or invoice mapping changes, replacement behavior follows the configured policy.
- No duplicate active Voucher exists for the same invoice/payment context.

#### FR-7: Generate Registration Number and Consumer Number

The system generates and stores both Registration Number and Consumer Number for every Voucher.

**Consequences (testable):**
- Default Registration Number pattern is random prefix plus invoice ID.
- Default Consumer Number pattern is Institution ID plus Registration Number.
- Generated values are unique within the configured company scope.
- Generation patterns are configurable and validated.

#### FR-8: Map Invoice and Contact Data to Voucher Request

The system maps invoice, client, contact, amount, date, mobile, email, branch, and configured payment-head data into the voucher request.

**Consequences (testable):**
- The payable amount sent to KuickPay matches the Blesta payment amount in PKR.
- Invalid or non-PK mobile numbers follow the configured fallback policy.
- Date values use the configured due, expiry, and issue date policies.
- Empty optional payment heads are sent according to the parser/client contract.

#### FR-9: Persist Voucher State

The system persists Voucher state before and after KuickPay interaction so creation, retry, reconciliation, support, and audit paths have a durable record.

**Consequences (testable):**
- Voucher records include company, gateway, client, invoice mapping, currency, amount, Registration Number, Consumer Number, Institution ID, status, dates, sanitized raw responses, parsed codes, error summaries, and Blesta transaction linkage.
- Unique constraints or equivalent guards prevent duplicate Registration Number, Consumer Number, and transaction reference posting.
- Raw Diagnostic Summary is available to admins only.

#### FR-10: Handle Voucher Creation Failure

If voucher creation fails or returns an unknown response, the customer sees a safe retry message and the Voucher is marked failed or Manual Review according to parser rules.

**Consequences (testable):**
- Customer-facing text does not expose raw SOAP details.
- Unknown voucher-creation responses are not treated as payable success.
- Admin users can inspect sanitized failure details.

#### FR-11: Handle Multi-Invoice Payment Attempts

The KuickPay Gateway supports multi-invoice Payment Attempts only when Blesta provides deterministic invoice amount mapping; otherwise it blocks the attempt and asks the customer to pay invoices separately.

**Consequences (testable):**
- Supported multi-invoice attempts store each invoice ID and amount allocation.
- Unsupported multi-invoice attempts do not create a Voucher.
- Payment Posting uses deterministic allocation order when multiple invoices are supported.

### 4.3 Customer Payment Experience

**Description:** The payment page gives customers a clear, copyable Consumer Number and channel-specific payment instructions while setting expectations that the invoice updates after KuickPay confirmation.

**Functional Requirements:**

#### FR-12: Display Payment Reference and Amount

The customer payment page displays the Consumer Number, payable amount, due date, expiry date, and KuickPay identity prominently.

**Consequences (testable):**
- Consumer Number is visible without opening an Instruction Group.
- Consumer Number can be copied by the customer.
- Amount, due date, and expiry date match the stored Voucher.

#### FR-13: Display Configurable Instruction Groups

The customer payment page displays enabled Instruction Groups for supported payment channels.

**Consequences (testable):**
- Admin users can enable or disable online banking, bank deposit, agent/franchise, and mobile app Instruction Groups.
- Disabled Instruction Groups are not shown to customers.
- Instruction text is localizable through Blesta language file patterns.

#### FR-14: Set Payment Status Expectations

The customer payment page explains that Blesta marks the invoice paid after KuickPay confirmation.

**Consequences (testable):**
- The page does not imply that generating a Voucher pays the invoice.
- The page includes a support path if the customer paid but the invoice remains unpaid.
- A Check Payment Status action is shown only if supported by the current reconciliation capability.

### 4.4 KuickPay API Client and Parser

**Description:** The integration must isolate SOAP calls and raw response parsing behind testable components so product behavior is not coupled to opaque strings.

**Functional Requirements:**

#### FR-15: KuickPay SOAP Client

The system provides a reusable client for required KuickPay operations.

**Consequences (testable):**
- Required client operations include voucher creation, single-reference inquiry, and Bulk Reconciliation inquiry.
- Optional setup operations can be supported for connectivity and institution discovery.
- Client calls apply configured timeouts, TLS validation, credential selection, and sanitized logging.

#### FR-16: Normalized Parser Contract

The system normalizes KuickPay responses into a stable internal result shape before any product logic consumes them.

**Consequences (testable):**
- Parser output includes success flag, normalized status, Consumer Number, Registration Number, voucher ID, transaction/auth fields, amount, payment date, message, and sanitized raw response.
- Parser tests cover successful, pending, failed, expired, invalid, malformed, and unknown responses.
- Parser behavior is documented in the addendum and fixtures.

#### FR-17: Fixture-First Payment Truth

Payment Posting logic cannot rely on unvalidated KuickPay status codes beyond fixture-backed behavior.

**Consequences (testable):**
- Sanitized live or sandbox fixtures exist before final Payment Posting approval.
- Unknown response codes map to Manual Review or retry, never paid.
- The known successful-code behavior is covered by tests before release.

### 4.5 Reconciliation and Payment Posting

**Description:** Reconciliation checks Pending Vouchers, validates confirmed payment data, posts Blesta transactions, and protects against duplicate or unsafe payment states.

**Functional Requirements:**

#### FR-18: Scheduled Pending Voucher Reconciliation

The system periodically checks Pending Vouchers for payment status.

**Consequences (testable):**
- Paid, cancelled, expired, and Manual Review Vouchers are skipped unless explicitly rechecked by admin.
- Inquiry results update last inquiry timestamp, normalized status, parsed payment data, and Raw Diagnostic Summary.
- Temporary API failures leave the Voucher pending unless expiry or admin policy says otherwise.

#### FR-19: Validate Confirmed Payments

Before Payment Posting, the system validates amount, reference identity, invoice mapping, Voucher state, and duplicate transaction status.

**Consequences (testable):**
- A paid response with mismatched amount does not silently mark an invoice fully paid.
- A duplicate KuickPay transaction/auth/reference cannot post twice.
- The system verifies that the Voucher is not already paid before posting.

#### FR-20: Post Blesta Transaction

When a KuickPay payment is safely confirmed, the system creates a Blesta transaction and applies it to mapped invoices.

**Consequences (testable):**
- Transaction method identifies KuickPay.
- Transaction reference stores configured KuickPay reference/auth data.
- Voucher stores Blesta transaction ID after posting.
- Payment Posting runs inside a safe transaction boundary where supported by Blesta patterns.

#### FR-21: Handle Partial, Over, and Late Payments

The system applies configured business rules for underpayment, overpayment, and payments received after Voucher expiry.

**Consequences (testable):**
- Underpayment does not fully pay the invoice unless policy explicitly allows partial posting.
- Overpayment is posted or flagged according to configured policy.
- Late payment after expiry moves to Manual Review unless a safe policy is explicitly configured.

#### FR-22: Daily Bulk Reconciliation

The system supports Bulk Reconciliation by transaction date as a safety net for missed single-reference inquiries.

**Consequences (testable):**
- Bulk results match against stored Consumer Numbers rather than inferred invoice suffixes.
- Matched confirmed payments follow the same validation and posting rules as single-reference inquiry.
- Unmatched payments are listed for Manual Review.
- Run summary records checked count, posted count, unmatched count, failure state, and timestamps.

#### FR-23: Expire Stale Vouchers

The system expires unpaid Vouchers after their configured expiry date.

**Consequences (testable):**
- Expired unpaid Vouchers stop appearing as active payment attempts.
- Customers can create a new Voucher for an unpaid invoice after expiry.
- Expiry does not erase Raw Diagnostic Summary or admin audit data.

### 4.6 Admin Operations and Supportability

**Description:** Staff need searchable operational visibility, safe manual actions, and structured logs to resolve customer payment issues.

**Functional Requirements:**

#### FR-24: Searchable Voucher List

Admin users can search and filter Vouchers.

**Consequences (testable):**
- Filters include status, client, invoice ID, Consumer Number, date range, amount, and KuickPay transaction/auth fields.
- List rows show created date, client, invoice mapping, amount, Consumer Number, status, last inquiry time, and Blesta transaction link when paid.

#### FR-25: Voucher Detail Page

Admin users can inspect full Voucher details and diagnostics.

**Consequences (testable):**
- Detail page shows client, invoice mapping, Registration Number, Consumer Number, amount, dates, current status, parsed response summary, Raw Diagnostic Summary, posting state, and admin notes.
- Detail page links to related Blesta invoice and transaction records when available.

#### FR-26: Manual Admin Actions

Admin users can safely check, cancel, or mark a Voucher for Manual Review.

**Consequences (testable):**
- Check Now runs inquiry and applies normal parser/validation rules.
- Mark Manual Review requires admin intent and stores an admin note.
- Cancel does not delete audit history or confirmed payment data.

#### FR-27: Structured Logging

The system logs KuickPay operations in a structured, sanitized way.

**Consequences (testable):**
- Logs include operation name, Voucher ID or correlation ID, sanitized request summary, sanitized response summary, error class, and timestamp.
- Passwords are always masked.
- Customer-facing messages remain generic and safe.

### 4.7 Delivery, Testing, and Documentation

**Description:** The gateway is release-ready only when parser behavior, idempotency, payment posting, deployment, and support workflows are covered.

**Functional Requirements:**

#### FR-28: Unit and Contract Tests

The delivery includes tests for parser, client mapping, idempotency, duplicate prevention, status transitions, amount handling, secret masking, and pattern generation.

**Consequences (testable):**
- Unknown responses map to Manual Review.
- Duplicate Voucher and duplicate Payment Posting cases are covered.
- Tests do not call live KuickPay endpoints by default.

#### FR-29: Optional Live API Tests

The delivery includes opt-in live or sandbox tests for KuickPay credential and response validation.

**Consequences (testable):**
- Live tests require environment variables or protected runtime config.
- Live tests are disabled by default.
- Test output redacts credentials and avoids committing production data.

#### FR-30: Deployment and Support Documentation

The delivery includes install, configure, reconcile, troubleshoot, rollback, upgrade, and support documentation.

**Consequences (testable):**
- Documentation explains where extension files live, how to configure credentials, how to enable PKR, how to run reconciliation, how to inspect logs, and how to handle delayed payments.
- Documentation states known limitations and KuickPay escalation data required for support.

## 5. Non-Goals (Explicit)

- Do not implement card tokenization or saved payment accounts.
- Do not implement Blesta-initiated recurring auto-charge through KuickPay.
- Do not implement refunds, voids, reversals, or voucher cancellation through KuickPay unless KuickPay confirms supported APIs.
- Do not modify Blesta core files.
- Do not hard-code production credentials, institution ID, fallback phone, debug recipients, payment URLs, conversion rates, or fee values in business logic.
- Do not mark invoices paid from customer-side data, callback data, or unvalidated KuickPay responses.
- Do not make live external API calls in default automated tests.
- Do not expose raw KuickPay responses to customers.

## 6. MVP Scope

### 6.1 In Scope

- Installable KuickPay non-merchant gateway for PKR.
- Admin settings for credentials, Institution ID, reference patterns, dates, phone policy, instruction groups, logging, fee policy, and reconciliation.
- Secure credential storage and redacted diagnostics.
- Voucher generation for eligible Blesta invoice Payment Attempts.
- Customer payment page with Consumer Number and configurable instructions.
- KuickPay SOAP client for voucher creation, single-reference inquiry, and Bulk Reconciliation.
- Normalized parser with sanitized fixtures.
- Scheduled Reconciliation and safe Payment Posting.
- Admin Voucher list, detail, manual check, cancellation, and Manual Review.
- Unit/contract tests, optional live API tests, deployment guide, and support documentation.

### 6.2 Out of Scope for MVP

- Non-PKR payments unless a dedicated conversion policy is approved.
- Customer refunds, voids, reversals, or gateway-side cancellation.
- KuickPay SMS/email sending.
- Automatic recurring payment collection.
- Public customer display of raw payment processor diagnostics.
- Multiple separate KuickPay gateways split by payment channel.

## 7. Success Metrics

**Primary**

- **SM-1:** Voucher generation success rate - at least 95 percent of eligible PKR attempts produce or reuse a Voucher without staff intervention after launch tuning. Validates FR-6, FR-7, FR-8, FR-9.
- **SM-2:** Safe automated posting - 100 percent of Blesta paid states created by the KuickPay Gateway have confirmed KuickPay evidence, amount/reference validation, and duplicate checks. Validates FR-17, FR-19, FR-20.
- **SM-3:** Reconciliation effectiveness - at least 95 percent of paid KuickPay Vouchers are posted to Blesta within the configured reconciliation window. Validates FR-18, FR-20, FR-22.

**Secondary**

- **SM-4:** Support visibility - staff can locate a Voucher by invoice ID or Consumer Number and identify next action in under two minutes. Validates FR-24, FR-25, FR-26.
- **SM-5:** Secret safety - zero credentials or raw sensitive values appear in logs, docs, fixtures, or customer-visible output. Validates FR-3, FR-27, FR-29.
- **SM-6:** Manual Review quality - every ambiguous, unmatched, underpaid, overpaid, or late payment has a stored reason and admin action path. Validates FR-10, FR-21, FR-22, FR-26.

**Counter-metrics (do not optimize)**

- **SM-C1:** Do not reduce Manual Review count by auto-approving uncertain responses. Counterbalances SM-3.
- **SM-C2:** Do not improve voucher generation rate by creating duplicate active Vouchers. Counterbalances SM-1.
- **SM-C3:** Do not shorten support handling time by hiding diagnostic uncertainty from staff. Counterbalances SM-4.

## 8. Cross-Cutting NFRs

- **Security:** Gateway Credential passwords must be encrypted, redacted, and rotatable through Admin Settings. No hard-coded production secrets are allowed.
- **Reliability:** KuickPay API failure must not corrupt invoice state. Temporary failures keep Vouchers pending unless expiry or admin policy applies.
- **Idempotency:** Voucher creation and Payment Posting must be protected by lookup checks and durable uniqueness constraints or equivalent guards.
- **Auditability:** Voucher lifecycle, inquiry attempts, posting decisions, admin manual actions, and run summaries must be traceable.
- **Maintainability:** Product code must follow Blesta extension boundaries, language files, loader patterns, PHP 8.2 compatibility, and existing project conventions.
- **Localization:** Customer/admin text must live in Blesta language files.
- **Performance:** Reconciliation must respect configured timeouts and avoid unbounded polling loops. API rate limits remain an Open Question.
- **Privacy:** Raw Diagnostic Summary must be admin-only and must not expose secrets or unnecessary customer data.

## 9. Constraints and Guardrails

### 9.1 Integration Constraints

- KuickPay is a non-merchant bill/reference flow for v1; customers pay outside Blesta using a Consumer Number.
- The gateway must support KuickPay SOAP/ASMX operations for voucher creation and payment inquiry.
- Blesta extension code must remain inside gateway/plugin extension boundaries and must not modify Blesta core.
- Current project runtime targets PHP 8.2 and Blesta 6.0.0-b1 conventions. [ASSUMPTION: production deployment will use the same extension API surface as this repository unless operations confirms otherwise.]

### 9.2 Payment Safety Guardrails

- Unknown or malformed KuickPay responses must fail closed to Manual Review or retry.
- No payment may be posted from browser return data alone.
- Amount mismatches, duplicate transaction references, unmatched bulk records, and late payments after expiry must not silently mark invoices paid.
- Bulk Reconciliation must match stored Consumer Numbers and must not infer invoice identity by truncating Consumer Number suffixes.

### 9.3 Operational Guardrails

- Every production-specific value must be configurable.
- Live API tests and live voucher tests must be opt-in and redacted.
- Scheduled reconciliation must be observable through run summaries and admin diagnostics.
- The system must preserve evidence for staff escalation to KuickPay support.

## 10. Rollout and Change Management

- **Phase 0: API contract validation.** Validate KuickPay credentials, response shapes, status codes, date formats, and sanitized fixtures before final Payment Posting logic is approved.
- **Phase 1: Gateway setup and voucher generation.** Release installable gateway, Admin Settings, safe test connection, voucher creation, and customer payment page in staging.
- **Phase 2: Reconciliation and Payment Posting.** Enable polling, Bulk Reconciliation, duplicate prevention, amount validation, and Blesta transaction posting in staging.
- **Phase 3: Admin operations and support.** Enable Voucher list/detail, manual check, Manual Review queue, structured logs, deployment guide, and support guide.
- **Phase 4: Production rollout.** Rotate any reused credentials, enable PKR KuickPay in production, run controlled payment tests, monitor reconciliation, and review Manual Review cases daily during initial launch.

## 11. Risks and Mitigations

- **Opaque response formats:** KuickPay public response definitions are generic strings. Mitigation: fixture-first parser contract and Phase 0 gate.
- **Duplicate payment posting:** Cron or manual checks may repeat. Mitigation: Voucher state checks, transaction uniqueness, and safe transaction boundaries.
- **Wrong invoice matching:** Consumer Number parsing could be misused. Mitigation: store Consumer Number and invoice mapping at creation; never infer from suffix alone.
- **Credential leakage:** Gateway credentials are high-risk. Mitigation: encrypted fields, masked logs, no hard-coded values, and redacted tests/docs.
- **Delayed reconciliation:** KuickPay or network issues may delay paid invoice updates. Mitigation: pending retry, Bulk Reconciliation, admin Check Now, and support documentation.
- **Production version mismatch:** Extension contracts may differ across Blesta versions. Mitigation: confirm target production Blesta version before implementation and run staging verification.

## 12. Open Questions

1. What exact production Blesta version will host the gateway, and does it match the current repository runtime?
2. What are the full exact `InsertVoucherResult`, `BillPaymentInquiryResult`, and `BillPaymentBulkInquiryResult` formats for successful, pending, failed, duplicate, expired, invalid credential, and API-down cases?
3. Is Consumer Number always Institution ID plus Registration Number for this merchant?
4. What date formats does KuickPay officially accept for due date, expiry date, issue date, and transaction date?
5. Should voucher and inquiry credentials remain separate in production, or can one credential pair serve both operations?
6. Does KuickPay support webhook/callback/IPN for merchants, and should v2 use it if available?
7. Does KuickPay support partial payments, overpayments, refunds, reversals, or voucher cancellation through API?
8. What are KuickPay API rate limits and recommended polling intervals?
9. What is the official fee policy, and should gateway fees be charged to customers, absorbed, or only recorded for finance?
10. Will MVP support multi-invoice Vouchers in production, or should multi-invoice attempts be blocked until staging confirms deterministic allocation?
11. What sanitized live or sandbox fixtures are acceptable to commit for parser tests?

## 13. Assumptions Index

- Inline assumption from Section 9.1 - Production deployment will use the same extension API surface as this repository unless operations confirms otherwise.
