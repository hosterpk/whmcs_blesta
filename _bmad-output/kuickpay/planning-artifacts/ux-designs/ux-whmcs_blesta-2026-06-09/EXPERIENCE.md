---
name: KuickPay Blesta Gateway
status: partial
sources:
  - "{planning_artifacts}/prds/prd-whmcs_blesta-2026-06-09/prd.md"
  - "{planning_artifacts}/prds/prd-whmcs_blesta-2026-06-09/addendum.md"
design: DESIGN.md
updated: 2026-06-09
---

# KuickPay Blesta Gateway - Experience Spine

`DESIGN.md` is the visual identity reference. This spine owns information architecture, behavior, states, accessibility, and flows. Spines win on conflict with any mock, import, or future generated visual.

## Foundation

Multi-surface responsive web inside Blesta. The customer surface lives in the Blesta client area payment flow. Admin/support/finance surfaces live in Blesta admin and follow Paradigm/Bootstrap widget, form, table, modal, alert, badge, and button conventions. No standalone mobile app, native app, or separate checkout microsite is specified.

[ASSUMPTION] The UI system is the current Blesta admin/client UI: Bootstrap-derived templates, Blesta `Widget`/`WidgetClient`, `.pdt` views, language files, Bootstrap Icons or Font Awesome where already available on the surface, and existing form/table helpers.

## Information Architecture

| Surface | Reached from | Purpose |
|---|---|---|
| Payment Method Selection | Blesta client invoice payment flow | Customer selects KuickPay as a non-merchant payment option for an eligible PKR invoice. |
| KuickPay Payment Reference | After selecting KuickPay / returning to an active Pending Voucher | Shows Consumer Number, amount, due date, expiry date, status expectation, and instruction groups. |
| KuickPay Unavailable / Retry | Payment Reference when voucher creation or inquiry has a retry-safe failure | Keeps invoice unpaid, explains retry path, and avoids raw SOAP details. |
| Non-PKR Blocked | Payment Method Selection or Payment Reference | Explains that KuickPay is unavailable for non-PKR invoices unless conversion policy is approved. |
| Gateway Settings | Admin company gateway manage screen | Configures endpoints, credentials, Institution ID, reference/date/phone/fee/currency/logging/reconciliation/instruction behavior. |
| Safe Connection Test | Gateway Settings | Confirms endpoint/credential shape without creating a payable Voucher unless live voucher test is explicitly selected. |
| Voucher List | Blesta admin KuickPay operations surface | Search/filter Vouchers by status, client, invoice ID, Consumer Number, date range, amount, and transaction/auth fields. |
| Voucher Detail | Voucher List row | Shows full Voucher state, invoice mapping, parsed response, sanitized diagnostics, transaction link, admin notes, and manual actions. |
| Manual Review Queue | Voucher List filtered to Manual Review | Focuses staff on ambiguous, unmatched, underpaid, overpaid, late, malformed, or policy-dependent cases. |
| Reconciliation Run Summary | Scheduled or manual reconciliation completion | Shows checked, posted, unmatched, failed, skipped, and Manual Review counts with timestamps and errors. |
| Bulk Reconciliation | Admin manual action, if enabled | Lets authorized staff run date-based Bulk Reconciliation and review matched/unmatched results. |
| Gateway Logs / Diagnostics | Existing Blesta gateway log patterns plus Voucher Detail | Gives sanitized request/response summaries for support escalation. |

No new public navigation is specified for customers beyond the payment flow. Admin navigation placement is a later architecture decision, but every admin surface must remain within Blesta extension/plugin boundaries.

## Voice and Tone

Microcopy should be plain, payment-safe, and specific about what is known. Brand voice lives in `DESIGN.md`.

| Do | Don't |
|---|---|
| "Use this Consumer Number to pay through a supported KuickPay channel." | "Your payment is complete." |
| "Blesta will mark this invoice paid after KuickPay confirms the payment." | "You are almost paid!" |
| "We could not reach KuickPay. Try again; your invoice has not been marked paid." | "Something went wrong: SOAP fault detail" |
| "Manual Review: amount does not match the Voucher." | "Error" |
| "No Vouchers match these filters." | "No data found!!!" |
| "Check Now" for admin inquiry | "Force Paid" |

All customer/admin strings must live in Blesta language files. Customer-facing copy must not expose raw diagnostics, credentials, parser fields, SOAP operation names, or internal exception classes.

## Component Patterns

Behavioral rules. Visual specifications live in `DESIGN.md.Components`.

| Component | Use | Behavioral rules |
|---|---|---|
| Payment method option | Payment Method Selection | KuickPay appears only when enabled for the company and eligible currency. Selecting it creates or reuses a Voucher according to FR-6. |
| Customer reference panel | KuickPay Payment Reference | Always shows Consumer Number, amount, due date, expiry date, and KuickPay identity before instructions. Does not imply paid status. |
| Consumer Number block | KuickPay Payment Reference | Copy action copies only the Consumer Number. Copy feedback is temporary and does not change payment state. |
| Instruction group | KuickPay Payment Reference | Render only enabled groups. Groups use localized/admin-configured text and can be scanned independently. |
| Status expectation block | KuickPay Payment Reference | States that Blesta updates after KuickPay confirmation. Includes support path when supplied. |
| Check Payment Status action | Customer or admin, if enabled | Customer action appears only if current reconciliation capability supports it. Admin action always applies parser/validation rules and cannot bypass payment safety. |
| Gateway settings section | Gateway Settings | Required settings validate before save. Passwords are masked on display and encrypted at rest. |
| Safe connection test | Gateway Settings | Tests endpoint/credential shape without creating a payable Voucher. Live voucher test requires explicit separate intent. |
| Voucher filters | Voucher List | Search by invoice ID and Consumer Number must be direct and fast. Filters preserve selected state after action or detail return. |
| Voucher status badge | Voucher List, Voucher Detail | Status label is always text plus optional icon/color. Manual Review includes reason or nearest reason summary. |
| Voucher detail diagnostics | Voucher Detail | Shows sanitized request/response summary to admins only. Long content scrolls inside the diagnostic block. |
| Manual action controls | Voucher Detail | Check Now, Mark Manual Review, Cancel, and note edits require admin intent, show result messages, and preserve audit history. No Force Paid action in MVP. |
| Reconciliation run summary | Reconciliation Run Summary | Shows counts, timestamps, run type, status, skipped states, unmatched payments, and failure class without exposing secrets. |
| Admin note | Voucher Detail | Required when manually marking Manual Review or canceling. Notes are timestamped and attributed where Blesta patterns support it. |

## State Patterns

| State | Surface | Treatment |
|---|---|---|
| Eligible unpaid PKR invoice | Payment Method Selection | KuickPay shown as a non-merchant payment option. |
| Non-PKR invoice | Payment Method Selection / Non-PKR Blocked | KuickPay hidden or blocked with clear copy; no Voucher is created. |
| Existing Pending Voucher | KuickPay Payment Reference | Reuse and show stored Consumer Number, amount, dates, and instructions. |
| Voucher created | KuickPay Payment Reference | Show payable reference and expectation copy. Invoice remains unpaid. |
| Voucher creation failure | KuickPay Unavailable / Retry | Generic retry-safe customer message; admin detail gets sanitized diagnostic summary. |
| KuickPay API timeout | Payment Reference / Voucher Detail | Customer sees retry-safe message. Admin sees timeout class and last inquiry timestamp. Voucher remains pending unless expiry/policy applies. |
| Voucher expired unpaid | Payment Reference / Voucher Detail | Active payment attempt stops. Customer can create a new Voucher if invoice remains unpaid. Admin history remains visible. |
| Paid and posted | Voucher Detail / Voucher List | Paid status links to Blesta transaction and mapped invoice. |
| Paid response with amount mismatch | Voucher Detail / Manual Review Queue | Manual Review with mismatch reason. Invoice is not silently paid. |
| Duplicate transaction/auth/reference | Voucher Detail / Manual Review Queue | Manual Review or safe duplicate message; no second Blesta transaction. |
| Unmatched bulk result | Reconciliation Run Summary / Manual Review Queue | Listed as unmatched with Consumer Number and sanitized evidence. |
| Empty Voucher List | Voucher List | Plain empty state with current filter summary. |
| Filter no results | Voucher List | "No Vouchers match these filters." Keep filters visible. |
| Missing required setting | Gateway Settings | Inline validation near field and Blesta message summary. |
| Credential masked | Gateway Settings / Diagnostics | Show masked value only; never reveal stored password. |
| Live voucher test requested | Gateway Settings | Requires explicit confirmation and clear test labeling before any payable test record is created. |

## Interaction Primitives

- Use normal Blesta form submit flows with disable-on-submit behavior for save, test, manual check, cancel, and bulk reconciliation actions.
- Copy Consumer Number is an explicit button/action near the value. It must be keyboard reachable and screen-reader labeled.
- Admin destructive or irreversible actions require confirmation and an admin note where the PRD requires intent.
- Search and filters run through standard Blesta list patterns. Avoid hover-only actions; actions must be available by keyboard and touch.
- Manual Check Now and scheduled reconciliation share the same parser/validation/posting rules.
- Bulk Reconciliation uses transaction date input and shows a result summary before staff acts on unmatched records.
- No customer-side return, browser refresh, button click, or copied reference can mark an invoice paid.
- Loading states must not blank existing Consumer Number or admin evidence. Preserve the last known state while checking.

## Accessibility Floor

Behavioral floor. Visual contrast is owned by `DESIGN.md` and inherited Blesta theme tokens.

- WCAG 2.2 AA target for customer and admin web surfaces.
- Every action has a visible text label or accessible label: Copy Consumer Number, Check Now, Mark Manual Review, Cancel Voucher, Run Bulk Reconciliation.
- Statuses are communicated with text, not color alone.
- Tables use header cells and meaningful column names for Consumer Number, invoice, amount, status, last inquiry, and transaction.
- Alerts use semantic alert patterns already present in Blesta.
- Keyboard focus order follows visual reading order: reference first, copy action, expectation, instruction groups, support path.
- Diagnostic textareas/blocks are readable by keyboard and do not trap focus.
- Copy feedback and reconciliation result messages should be announced through existing alert/message regions where available.
- Customer instructions must remain usable on small screens without horizontal scrolling.

## Responsive & Platform

| Context | Behavior |
|---|---|
| Customer phone width | Payment Reference is single-column. Consumer Number and amount remain above instructions. Tables are avoided on the customer reference surface. |
| Customer desktop width | Payment Reference may group summary and instruction groups in a wider layout, but the Consumer Number remains first. |
| Admin desktop/laptop | Voucher List and Reconciliation Run Summary use dense responsive tables with filters above. |
| Admin tablet/small width | Tables use Blesta responsive wrappers; primary actions remain reachable without hover. |
| Print/copy support | Consumer Number and amount remain plain text, not image-only or icon-only content. |

## Payment Safety UX

The UX must represent payment truth conservatively:

- "Voucher generated" is not "paid."
- "Customer says paid" is not "paid."
- "KuickPay returned unknown data" is not "paid."
- "Bulk result matched by suffix" is not "paid."
- "Amount/reference duplicate/mismatch" is not "paid."

The only paid customer/admin state is one backed by normalized KuickPay confirmation, amount/reference validation, duplicate checks, and successful Blesta transaction posting.

## Localization and Content Operations

- Customer instructions, status labels, field labels, settings help, validation messages, and admin action text must live in Blesta language files.
- Instruction Groups are configurable and localizable; disabled groups are not shown.
- Customer support path copy is an open decision. The surface must reserve a place for it without naming an unconfirmed channel.
- Raw diagnostic summaries are not localization content and remain admin-only evidence.

## Key Flows

### UJ-1. Ayesha pays a hosting invoice through mobile banking

1. Ayesha opens an unpaid Blesta invoice in the client area.
2. She reaches Payment Method Selection and chooses KuickPay because the invoice is eligible and PKR.
3. The gateway creates or reuses a Pending Voucher for that invoice context.
4. KuickPay Payment Reference opens with Consumer Number, amount, due date, expiry date, and KuickPay identity above the instruction groups.
5. Ayesha copies or reads the Consumer Number and pays externally through her supported bank, wallet, ATM, branch, agent, or app.
6. **Climax:** Before leaving Blesta, Ayesha has the exact Consumer Number and exact payment amount in the same visible reference panel.
7. Blesta continues to show expectation copy that the invoice is marked paid only after KuickPay confirmation.
8. Later reconciliation confirms and posts the payment; the invoice becomes paid in Blesta.

Failure path: if KuickPay is temporarily unavailable during creation or inquiry, Ayesha sees a retry-safe message and no paid state is inferred.

### UJ-2. Ahmed investigates a delayed payment

1. Ahmed receives a customer report that a payment was made but the invoice still appears unpaid.
2. He opens the Blesta admin KuickPay Voucher List.
3. He searches by invoice ID or Consumer Number and opens the matching Voucher Detail.
4. He reviews normalized status, amount, dates, invoice mapping, last inquiry time, parsed response summary, and sanitized diagnostics.
5. He runs Check Now when appropriate.
6. **Climax:** Ahmed can state whether the Voucher is pending, paid, failed, expired, or in Manual Review, and why.
7. If confirmed and safe, normal posting rules create/link the Blesta transaction. If ambiguous, the Voucher moves or remains in Manual Review with evidence and notes.

Failure path: if the response is malformed, amount-mismatched, duplicate, or unsupported by fixtures, Ahmed sees Manual Review instead of a paid action path.

### UJ-3. Nadia closes daily reconciliation

1. Nadia opens the reconciliation area after scheduled reconciliation has run, or starts a manual daily bulk run if enabled.
2. She reviews Reconciliation Run Summary counts: checked, posted, unmatched, failed, skipped, and Manual Review.
3. She drills into unmatched or Manual Review rows from the run summary.
4. The system matches confirmed payments to stored Consumer Numbers and applies the same validation/posting rules as single-reference inquiry.
5. **Climax:** Paid invoices are updated without Nadia re-entering payment details, while unsafe records remain visibly separated for Manual Review.
6. Nadia reviews late, underpaid, overpaid, or unmatched cases with enough sanitized evidence for internal or KuickPay escalation.

Failure path: if Bulk Reconciliation fails or returns ambiguous rows, the run summary records failure state and no invoice is marked paid from uncertain data.

## Assumptions and Open Questions

### Assumptions

- [ASSUMPTION] The MVP is a responsive Blesta web extension only.
- [ASSUMPTION] Current Blesta theme conventions and company theme settings remain the visual source of truth.
- [ASSUMPTION] Admin Voucher operations may require a companion plugin if the architecture workflow chooses that extension shape.
- [ASSUMPTION] Customer support path content exists but is not specified in the PRD.

### Open Questions

1. Should customers see Check Payment Status in MVP, or should status checks remain scheduled/admin-only?
2. What support channel and escalation wording should appear when customers paid externally but Blesta is still unpaid?
3. Which Instruction Groups are enabled by default and what exact localized instruction copy should each contain?
4. What exact statuses and parser messages should map to pending, paid, failed, expired, and Manual Review labels?
5. What fee, partial payment, overpayment, late payment, and multi-invoice policies should admin settings expose?
6. Should Manual Review have a dedicated dashboard/count, or is a Voucher List filter sufficient?
7. Which sanitized diagnostic fields are safe for staff viewing and KuickPay escalation?
