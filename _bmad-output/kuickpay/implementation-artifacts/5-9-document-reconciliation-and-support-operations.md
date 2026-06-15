---
baseline_commit: e6e4919087c75e03799d97e82ebcfbc1485eef82
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.9: Document Reconciliation and Support Operations

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a support or finance operator,
I want reconciliation and troubleshooting runbooks,
so that delayed, ambiguous, or failed payments can be handled consistently.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.9 (lines 1052–1066) and the doc-location rule `epics.md:148`
> (the `docs/kuickpay/` set names a **reconciliation-runbook**, an **admin-review-runbook**, and a
> **support-troubleshooting** guide; `architecture.md:754-762`). **Carried discipline** (the
> sanitized-doc contract every Epic-5 doc honors): NFR8 (`epics.md:101` — no secret exposure),
> NFR10 (`epics.md:105` — no hard-coded endpoints/credentials/Institution IDs/PII), NFR12
> (`epics.md:109` — honest reporting / code-is-truth). Architecture: admin reconciliation
> workbench + redacted-diagnostics boundary (`architecture.md:426-439`), gateway-vs-plugin
> ownership (`architecture.md:518-526,765-789`), "confirm payment truth without reading raw KuickPay
> responses" (`architecture.md:287`), "only `posted` implies paid" (`architecture.md:304,424`).

**This is a DOCUMENTATION-ONLY story.** No production code, no test code, no schema, no version
bump, no settings/behavior change. The deliverables are Markdown files under `docs/kuickpay/`. The
"implementation" is writing accurate **operator/support** runbooks that match the **already-shipped**
reconciliation engine (Epic 3) and admin support surface (Epic 4). Do **not** "fix" any code you
document; if you find a real defect, record it as a finding in the Dev Agent Record — do not change
it in this story. **The code is truth — if a doc and the code disagree, fix the doc** (NFR12).

1. **(AC1 — the reconciliation runbook)** — *epic-sourced (`epics.md:1060-1062`)*
   **Given** the reconciliation runbook is opened,
   **When** staff follow it,
   **Then** it explains **scheduled reconciliation** (the three cron tasks: `reconcile_pending`,
   `post_confirmed`, `expire_vouchers`), **Check Now** (single manual recheck), **Bulk
   Reconciliation** (date-keyed bulk inquiry), **run summaries** (the run/item counts and how to
   read them), **Manual Review** (how a voucher gets there, the queue, and the recheck/review/cancel
   actions), **unmatched payments** (bulk evidence with no local voucher), **late payments**,
   **underpayments**, **overpayments**, and **duplicate references** — all matching the real status
   names, cron labels, counts, and admin actions in the shipped plugin (see Dev Notes "Reconciliation
   engine reference" + "Run-summary semantics" + "Manual-Review routing").

2. **(AC2 — the support troubleshooting guide)** — *epic-sourced (`epics.md:1064-1066`)*
   **Given** the support troubleshooting guide is opened,
   **When** staff investigate a customer claim,
   **Then** it explains how to **search by Invoice ID or Consumer Number** (the admin voucher list
   filters), **inspect Voucher Detail** (the detail screen and what each box shows), **interpret safe
   statuses** (the admin↔customer label mapping; **only `posted` means paid**), **collect sanitized
   escalation evidence** (the safe evidence getters + redacted trace/hash; never raw SOAP/XML, WSDL,
   credentials, or PII), and **avoid unsafe paid-state claims** (never tell a customer a
   `confirmed_unposted`/`manual_review`/`retry` voucher is "paid"; the customer surface itself only
   styles/labels `posted` as received) — all matching the real filter keys, view boxes, status
   labels, and redaction boundary in the shipped plugin/gateway (see Dev Notes "Admin support-surface
   reference" + "Status vocabulary" + "Redaction / escalation-evidence boundary").

3. **(AC3 — placeholders only; zero secret/environment/PII leakage — NFR8/NFR10)** — *carried discipline*
   **Given** any example, status snippet, evidence sample, or escalation template in either doc,
   **When** documentation shows a value,
   **Then** it uses **placeholders only** (e.g. `<consumer-number>`, `<invoice-id>`,
   `<redacted-trace-id>`, `<operator-provided-wsdl-url>`)
   **And** it does **not** copy any value from `config/blesta.php`, logs, cache, `.env`, production
   settings, the live DB, or real KuickPay traffic — no real Consumer/Registration/KuickPay
   reference, no Institution ID, no WSDL host, no customer name/mobile/CNIC/email, no raw SOAP
   envelope, no credential. Apply the same secret-leak self-scan discipline Story 5.7/5.8 used.

4. **(AC4 — honest, code-verified reporting — NFR12)** — *carried discipline*
   **Given** any behavior asserted in either runbook,
   **When** it is written,
   **Then** it is **verified against the shipped source** at baseline `e6e49190` (status names, cron
   keys/labels, filter keys, audit-event names, count fields, admin actions, redaction boundary)
   **And** anything that could **not** be confirmed against code is stated as such (or omitted),
   rather than asserted — and any doc/code discrepancy found is recorded as a finding, not silently
   "fixed" in code.

## Tasks / Subtasks

- [ ] **Task 1 — AC1/AC3/AC4: write the reconciliation runbook** (`docs/kuickpay/reconciliation-runbook.md`)
  - [ ] 1.1 **Sanitized header + audience.** Open with the standard sanitized-doc header (mirror
    `deployment-guide.md:1-17` / `gateway-settings-and-endpoint-hardening-verification.md:1-8`):
    audience = **support/finance operators**; scope = Story 5.9 reconciliation runbook (rollback/
    upgrade/launch is Story 5.10 — out of scope); the file contains **no** secrets/WSDL host/
    Institution ID/PII; facts verified against source at baseline `e6e49190`; state what is verified
    vs what an operator confirms in their own environment (NFR12).
  - [ ] 1.2 **Scheduled reconciliation — the three cron tasks.** Document the Blesta plugin cron
    tasks exactly as registered (see Dev Notes "Reconciliation engine reference"):
    - `reconcile_pending` — **"Reconcile Pending KuickPay Vouchers"**, every **5 min**; single-inquiry
      checks **Pending + Retry** vouchers (PKR only, not yet expired), with Pending rechecked no more
      than every ~30 min and Retry on exponential backoff (capped ~6 h). DB-locked / bounded batch
      (100) / resumable; if the lock is held the run is **skipped** (no error). Gated by the gateway
      `reconciliation_enabled` setting.
    - `post_confirmed` — **"Post Confirmed KuickPay Payments"**, every **5 min**; posts
      **Confirmed (Unposted)** vouchers to Blesta via the single posting service (row-locked,
      idempotent — this is the **only** path that marks an invoice paid).
    - `expire_vouchers` — **"Expire KuickPay Vouchers"**, every **60 min**; status-guarded transition
      of past-expiry **Pending/Retry** vouchers to **Expired**.
    Explain operator-visible facts: where these appear in Blesta's automation/cron logs, the 5-min
    cadence, and that nothing posts outside `post_confirmed`. (Do **not** re-document install/enable
    — that's 5.8; link `deployment-guide.md`.)
  - [ ] 1.3 **Check Now (single manual recheck).** Document the per-voucher **Check Now** action on
    Voucher Detail: available on **Pending, Retry, Confirmed (Unposted)** (per
    `ALLOWED_ACTIONS_BY_STATE`); on Pending/Retry it runs one inquiry, on Confirmed (Unposted) it
    attempts posting. List the operator-visible **outcomes** (posted / already posted / confirmed /
    retry / manual review / unreachable / failed) and the success/error message each shows. Note it
    runs **outside** the cron batch lock (a deliberate manual override) and is **safe** (fails
    closed, evidence preserved) — reference the documented during-inquiry race residual only as a
    "rare, self-healing" footnote, not a scare. Requires the **recheck** permission.
  - [ ] 1.4 **Bulk Reconciliation.** Document the **Bulk Reconciliation** screen
    (`admin_main`): operator supplies a **run date** (`YYYY-MM-DD`, bounded to a 365-day lookback),
    which triggers one `BillPaymentBulkInquiry` for that date. Matching is by **Consumer Number**
    against local Pending/Retry vouchers. Explain the three outcome buckets — **matched** (normal
    reconcile + item row), **unmatched** (provider row with no local voucher → audit-only, counted),
    **duplicate** (same Consumer Number echoed twice in one run → audit-only, counted) — and the
    completion banner ("Bulk reconciliation run #N completed. Checked: …, unmatched: …, manual
    review: …"). Requires the **bulk_reconcile** permission.
  - [ ] 1.5 **Run summaries (how to read a run).** Document the Reconciliation Runs screen
    (`admin_reconciliation`) and run-detail drill-down: the per-run count fields (`total_eligible`,
    `total_checked`, `total_confirmed`, `total_retry`, `total_manual_review`, `total_expired`,
    `total_failed`, `total_errors`, `total_unmatched`) and the two displayed labels —
    **"Confirmed (ready to post)"** and **"Manual Review (incl. unmatched)"**. **Critically capture
    the two count footnotes verbatim-ish:** "Confirmed rows are validated evidence awaiting posting;
    posting runs on a separate task." and "On bulk runs, Unmatched is a **subset** of Manual Review
    (the same provider row increments both); shown as a subset, not added separately." Explain
    **item rows vs audit events**: each processed voucher gets one `kuickpay_reconciliation_items`
    row `(run_id, voucher_id)`; bulk **unmatched/duplicate** evidence produces **no item row** — only
    `evidence.unmatched` / `evidence.duplicate` audit events, which are the only audit events the
    run-detail view surfaces (the run-detail audit allowlist — `[[kuickpay-run-detail-audit-allowlist]]`).
  - [ ] 1.6 **Manual Review queue + the under/over/late/duplicate/unmatched cases.** Document the
    **KuickPay Manual Review** queue (`admin_manual_review`, read-only list of `manual_review`
    vouchers, most-recently-checked first; every row links to Voucher Detail to act). Explain
    **how a voucher lands in Manual Review** and map each AC1-named case to its real cause:
    - **Underpayment / Overpayment / Late payment** → routed to `manual_review` by the fixed
      `underpayment_policy` / `overpayment_policy` / `late_payment_policy = manual_review` settings
      (these never auto-post).
    - **Duplicate reference** → duplicate KuickPay reference / duplicate Consumer Number echo →
      `manual_review` (or audit-only on bulk).
    - **Unmatched payment** → bulk provider evidence with no local voucher → counted as unmatched,
      surfaced on run detail (no voucher to act on directly; investigate via Consumer Number).
    - Plus: stale-evidence guard, evidence-validation failure, retry-limit exhaustion, and posting
      failures (missing paid date, blocked adoption, transaction create/apply failure, posting cap).
    Document the **actions** an operator takes from Voucher Detail: **Check Now** (re-run), **Review**
    (keep in review + add a required note; idempotent), **Cancel** (terminal → `cancelled` + required
    note). State plainly that **Manual Review is a valid safe state, recoverable by admin action — it
    is not a failure of the product** (`architecture.md:86`) and there is **no "force paid" action**
    (`architecture.md:307,375`).
  - [ ] 1.7 **Admin-review-runbook coverage.** The architecture's planned `admin-review-runbook.md`
    (`architecture.md:759`) is satisfied by the Manual Review section above. You **may** split that
    section into a separate `docs/kuickpay/admin-review-runbook.md` if cleaner, but cross-link it; a
    single reconciliation-runbook covering AC1's named "Manual Review" topic satisfies the AC
    literally and is less ambiguous. Do **not** start the 5.10 rollback/upgrade/launch docs here.

- [ ] **Task 2 — AC2/AC3/AC4: write the support troubleshooting guide** (`docs/kuickpay/support-troubleshooting.md`)
  - [ ] 2.1 **Sanitized header + audience.** Same header pattern as Task 1.1; audience =
    **support/finance staff handling a customer claim**.
  - [ ] 2.2 **Search / lookup.** Document the **KuickPay Vouchers** admin list (`admin_vouchers`
    index) and its filters: **Invoice ID** (exact), **Consumer Number** (partial/LIKE),
    **Registration Number**, **KuickPay Reference**, **Client ID**, **Amount**, **Status** (dropdown
    over the status allowlist), **Date Created from/to**, and **Has Blesta Transaction**. Give the
    "start from a customer claim" recipe: search by Invoice ID or Consumer Number → open Voucher
    Detail. Note this needs the voucher **view** permission.
  - [ ] 2.3 **Inspect Voucher Detail.** Document the detail screen's boxes: Voucher Summary
    (status, client, Registration/Consumer Number, KuickPay reference, amount/currency, the date
    fields incl. date paid/posted), Invoice Mapping & Posting State (linked invoices; **transaction
    link appears only for `posted`** — UX-DR20), Admin Notes, Manual Actions (state-gated Recheck/
    Review/Cancel), Parsed Response Summary, and the **Diagnostics** box (**separately permissioned**
    — `diagnostics` action). Make clear the diagnostics box shows the **redacted** audit timeline
    (event label, date, redacted trace id, evidence hash, already-redacted payload) and the
    allowlisted diagnostic fields — **never** raw SOAP/XML, WSDL, or credentials.
  - [ ] 2.4 **Interpret safe statuses (the label table) — the safety core of this guide.** Provide a
    single table mapping each voucher status → **admin label** → **customer-facing label** →
    operational meaning → **safe to call "paid"?** Use the verified labels (Dev Notes "Status
    vocabulary"). Hammer the rule: the customer surface shows `confirmed_unposted` as **"Waiting for
    payment confirmation"** (NOT paid) and only `posted` as **"Payment received"**; the standing
    customer notice is *"Blesta marks this invoice paid only after KuickPay confirms your payment."*
    (`Kuickpay.process.status_expectation`). **Only `posted` = paid/posted-to-Blesta.**
  - [ ] 2.5 **Avoid unsafe paid-state claims.** A short, explicit "do / don't" for staff: never tell
    a customer a `pending`/`retry`/`confirmed_unposted`/`manual_review`/`expired` voucher is paid;
    "Confirmed (Unposted)" means **evidence accepted, not yet posted** — posting is a separate task;
    direct the customer/ticket to wait for `posted` (the Blesta invoice itself flips to paid only on
    posting). Tie back to "no force-paid action exists."
  - [ ] 2.6 **Collect sanitized escalation evidence.** Document what is **safe to copy into an
    escalation** vs what must **never** leave the system. Safe: status, error class, **KuickPay
    reference**, **Consumer/Registration Number**, amount, currency, paid-at, **redacted trace id**,
    **evidence hash**, validation-reason tokens, run id (the `KuickPayEvidence` safe getters + the
    redacted diagnostics). Never share: raw SOAP request/response (`raw_result`/`raw_envelope`),
    WSDL endpoint, credentials (voucher/inquiry username+password, Institution ID), or customer PII
    (name/mobile/CNIC/email) — all masked by `KuickPayRedactor`. Give a placeholder-only escalation
    template using `<redacted-trace-id>` / `<evidence-hash>` / `<consumer-number>` so a ticket can be
    raised without leaking anything (NFR8). Reference the unredacted-getter warning
    (`[[kuickpay-soapclient-rawresult-unredacted]]`): the raw payload getters exist but are **not**
    for support evidence — use only the safe allowlist / redacted views the admin UI already renders.

- [ ] **Task 3 — AC3/AC4: verification (secret-leak scan + code cross-check)**
  - [ ] 3.1 **Cross-check every operator-facing term against source** at baseline `e6e49190`: the 8
    voucher statuses + labels (`models/kuickpay_vouchers.php` STATUSES; `language/.../admin_vouchers.php`
    status/posting_state keys), customer labels (`gateway .../kuickpay.php` `process.status.*`), the
    three cron task keys/labels (`kuickpay_reconcile_plugin.php` cron defs + `language/.../kuickpay_reconcile_plugin.php`),
    the filter keys (`KuickPayVoucherListPresenter` FILTER_KEYS + `admin_vouchers` buildFilters), the
    run count fields + the two footnotes (`admin_reconciliation.php` lang), the audit-event names
    (presenter allowlist), and the redaction boundary (`KuickPayRedactor` / `KuickPayEvidence`). Do
    not invent a status/action/count/event the code does not have; do not omit one an AC names.
  - [ ] 3.2 **Secret-leak self-scan** of every new doc (mirror Story 5.7/5.8 discipline): grep for
    credential / WSDL-host / Institution-ID / Consumer-Number / CNIC / mobile / email / raw-envelope
    shapes and confirm only placeholders appear — no `config/blesta.php` value, no live DB value, no
    real KuickPay traffic.
  - [ ] 3.3 **Honest reporting (NFR12):** if any documented behavior could not be confirmed against
    the code (e.g. a count semantics nuance, or the exact Check-Now outcome token set), say so
    explicitly rather than asserting it; record any doc/code discrepancy as a finding (do not change
    code).

- [ ] **Task 4 — Doc hygiene & commit**
  - [ ] 4.1 Keep this a **docs-only** change set under `docs/kuickpay/` + the `_bmad-output/` story
    file. No runtime/test/schema/`config.json` changes, no version bump (`project-context.md:104` —
    don't mix generated docs with runtime changes). Commit style `docs(kuickpay): <summary>`,
    imperative, ≤72 chars (e.g. `docs(kuickpay): add reconciliation and support runbooks`).
  - [ ] 4.2 Cross-link the new docs into the existing `docs/kuickpay/` set (deployment guide,
    blesta-footguns, live-smoke runbook, testing-fixtures) via "See also" sections so they are
    discoverable. No `index.md` exists today — do not invent one unless one appears.

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **Docs only.** Touch nothing under `components/` or `plugins/`. You are describing behavior that
  already shipped and was code-reviewed across Epics 3–5. **If a doc and the code disagree, the code
  is truth** — fix the doc and flag the discrepancy in the Dev Agent Record; do **not** change code.
- **Placeholders only (AC3/NFR8/NFR10).** No real Consumer/Registration/KuickPay reference, Invoice
  ID from the live DB, Institution ID, WSDL host, customer PII, raw SOAP envelope, or credential in
  any doc. Prefer `<consumer-number>`, `<invoice-id>`, `<redacted-trace-id>`, `<evidence-hash>`.
- **Only `posted` is paid.** Every status/label/escalation statement must respect this. Never write
  anything that implies `confirmed_unposted`, `retry`, `pending`, or `manual_review` is "paid". This
  is the single most important safety property of the support guide (`architecture.md:304,424`;
  `[[kuickpay-parser-single-identity-contract]]`).
- **No `KuickPay` vs `Kuickpay` slip.** The **gateway** class is `Kuickpay` (camelCase round-trip);
  the **plugin** class is `KuickpayReconcilePlugin`. The brand rendered to humans is "KuickPay".
- **Manual Review is safe, not a bug.** Document it as a valid recoverable state with no force-paid
  shortcut — never frame it as an error to "clear" by forcing payment (`architecture.md:86,307,375`).

### Reconciliation engine reference (verified at baseline `e6e49190`)

| Operation | Where | Operator-facing facts |
|---|---|---|
| Cron `reconcile_pending` | `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php:788-798` (def), `:217-250` (dispatch); `lib/KuickPayReconcileService.php:runCron()` | "Reconcile Pending KuickPay Vouchers"; **5 min**; selects **pending+retry**, PKR only, not expired; pending recheck ≥~30 min, retry exp-backoff cap ~360 min; batch 100; DB lock `reconcile_pending` TTL 600s; lock-held ⇒ `skipped`; gated by `reconciliation_enabled`. |
| Cron `post_confirmed` | `kuickpay_reconcile_plugin.php` cron def; `lib/KuickPayPostingService.php` | "Post Confirmed KuickPay Payments"; **5 min**; posts **confirmed_unposted** → Blesta transaction; row-locked + idempotent; **only path that marks paid**. |
| Cron `expire_vouchers` | `kuickpay_reconcile_plugin.php:809-818`; `KuickPayReconcileService::expirePending()`; `models/kuickpay_vouchers.php:616-628` | "Expire KuickPay Vouchers"; **60 min**; status-guarded `pending/retry → expired` (1-row UPDATE); audits `voucher.expired`. |
| Check Now (single) | `controllers/admin_vouchers.php:214-281` `recheck()`; `KuickPayReconcileService::reconcileVoucher()` (`:176-248`) / `KuickPayPostingService::postVoucher()` | POST-only; perm `recheck`; allowed on **pending/retry/confirmed_unposted** (`models/kuickpay_vouchers.php:63-67`); outcome tokens via `admin_vouchers.php:420-452` `safeRecheckOutcome()`; audits `admin.rechecked`. Runs **outside** the cron batch lock (documented during-inquiry race residual — `deferred-work.md` 4-3 item — is rare + fails closed). |
| Bulk reconciliation | `controllers/admin_main.php:43-115` `run()`; `KuickPayReconcileService::runBulk()` (`:250-377`) | POST-only; perm `bulk_reconcile` (`*`); input `run_date` `YYYY-MM-DD` bounded ≤365-day lookback; one `BillPaymentBulkInquiry`; match by **Consumer Number**; buckets matched/unmatched/duplicate; banner `AdminMain.!success.bulk_completed`. |
| Run summary (runs) | `models/kuickpay_reconciliation_runs.php`; schema `kuickpay_reconcile_plugin.php:594-616` | counts: `total_eligible/checked/confirmed/retry/manual_review/expired/failed/errors/unmatched`; `trigger_type` cron/manual/bulk; `status` running/completed/aborted/failed; `cursor` resume. |
| Run detail (items + audit) | `controllers/admin_reconciliation.php:136-149`; `models/kuickpay_audit_events.php:76-88` | items `(run_id,voucher_id)` unique, ≤500 shown; **bulk unmatched/duplicate have NO item row** — only `evidence.unmatched`/`evidence.duplicate` audit events, the **only** audit events the run-detail view surfaces (`[[kuickpay-run-detail-audit-allowlist]]`). |
| Manual Review queue | `controllers/admin_manual_review.php:55-117` `index()` | read-only list of `manual_review`, sorted `date_last_checked DESC`; perm `manual_review` (`*`); every row links to Voucher Detail to act. |

### Admin support-surface reference (verified at baseline `e6e49190`)

| Surface | Where | Notes |
|---|---|---|
| Voucher list + filters | `controllers/admin_vouchers.php:49-135` `index()`; `views/default/admin_vouchers.pdt`; `lib/KuickPayVoucherListPresenter.php:67-78` FILTER_KEYS | filters: status, consumer_number (LIKE), registration_number (LIKE), client_id, invoice_id (exact), kuickpay_reference (LIKE), amount, date_from/to, has_blesta_transaction; sort: date_created/client_id/consumer_number/status/date_last_checked. |
| Voucher detail | `controllers/admin_vouchers.php:147-212` `detail()`; `views/default/admin_vouchers_detail.pdt` | 6 boxes (summary / invoice-mapping+posting-state / notes / manual actions / parsed-response / **diagnostics**). Transaction link only for `posted` (`detail.pdt:146-147`, UX-DR20). |
| Diagnostics (gated) | `admin_vouchers.php:195-200`; `KuickPayVoucherListPresenter.php:264-277` DIAGNOSTIC_FIELD_KEYS | **separate `diagnostics` permission**; shows redacted audit timeline + allowlisted fields (status, raw_status, error_class, evidence_hash, redacted_trace_id, validation_errors, reference, consumer/registration number, amount, currency, paid_at). |
| Nav + ACL | `kuickpay_reconcile_plugin.php:257-352` (getActions/getPermissions); `kuickpay_reconcile_controller.php:36-109` | 4 staff nav items under `billing/`: Vouchers, Bulk Reconcile, Manual Review, Reconciliation. 8 permissions incl. the two-group split (view vs `diagnostics` vs `recheck`/`review`/`cancel`). `requirePagePermission()` enforces `*`. |

### Status vocabulary (the AC2 safety table — verified)

Statuses: `models/kuickpay_vouchers.php` STATUSES (`:13-22`). Admin labels: `language/en_us/admin_vouchers.php:30-39`. Customer labels: gateway `language/en_us/kuickpay.php:21-29` (`process.status.*`). Standing customer notice `process.status_expectation` (`:20`): *"Blesta marks this invoice paid only after KuickPay confirms your payment."*

| Status | Admin label | Customer-facing label | Meaning | Safe to call "paid"? |
|---|---|---|---|---|
| `pending` | Pending | "Payment reference created — awaiting payment" | Issued, awaiting confirmation | **No** |
| `retry` | Retry | "Confirmation delayed" | Provider unavailable; will recheck | **No** |
| `confirmed_unposted` | Confirmed (Unposted) | "Waiting for payment confirmation" | Evidence validated, **not yet posted** | **No** |
| `posted` | Posted | "Payment received" | Posted to a Blesta transaction | **YES — the only paid state** |
| `failed` | Failed | "Confirmation delayed" | Unrecoverable evidence result; cannot auto-post | **No** |
| `expired` | Expired | "Payment reference expired" | Past expiry; window closed | **No** |
| `manual_review` | Manual Review | "Payment under review" | Ambiguous/policy hold; needs admin | **No** |
| `cancelled` | Cancelled | "Payment reference cancelled" | Admin-terminated; customer may reissue | **No** |

> The gateway list also defines a `status.unknown` ("Reference status unavailable") presentation
> label; `unknown` is a display fallback, not a stored voucher status. Verify before listing it as a
> lifecycle state.

### Audit-event vocabulary (presenter allowlist — `KuickPayVoucherListPresenter.php:145-165`)

`voucher.issued`, `voucher.replaced`, `voucher.expired`, `voucher.generation_failed`,
`evidence.received`, `evidence.matched`, `evidence.retry_decision`, `evidence.rejected`,
`evidence.duplicate`, `evidence.unmatched`, `evidence.error`, `reconciliation.run.started`,
`reconciliation.run.completed`, `posting.started`, `posting.succeeded`, `posting.failed`,
`admin.rechecked`, `admin.reviewed`, `admin.cancelled`. (Run-detail view surfaces only
`evidence.unmatched`/`evidence.duplicate` — the run-detail allowlist.)

### Error-class / validation-reason vocabulary (for the troubleshooting guide)

Error classes (`KuickPayVoucherListPresenter.php:122-133`; labels `admin_vouchers.php:131-141`):
`timeout`, `transport_error`, `credential_error`, `malformed_response`, `unknown_status`,
`amount_mismatch`, `duplicate_reference`, `unmatched_reference`, `posting_failed`,
`reconcile_exception`. Validation-reason tokens (presenter `:182-202`; labels `admin_vouchers.php:166-182`)
cover currency/amount/date mismatch, unmatched/duplicate reference, stale voucher, late payment,
under/overpayment, transaction-state conflict, unknown status — render the **labels**, never raw
tokens, in operator-facing copy.

### Run-summary semantics (capture these exactly — they are easy to get wrong)

- `confirmed_unposted` count is shown as **"Confirmed (ready to post)"** with the footnote *"Confirmed
  rows are validated evidence awaiting posting; posting runs on a separate task."* — i.e. **not yet
  posted/paid**. (`admin_reconciliation.php` lang `:15,:22`.)
- On **bulk** runs, **Unmatched is a subset of Manual Review** — the same provider row increments
  both `total_manual_review` and `total_unmatched`; it is displayed as a subset, not added twice
  (`admin_reconciliation.php` lang `:16,:23`; closes the 3.7 count-overlap deferred item). Say this
  plainly so an operator doesn't double-count.
- **Item rows vs audit events:** matched vouchers → one `kuickpay_reconciliation_items` row each;
  bulk unmatched/duplicate evidence → **audit event only, no item row**. The run-detail view lists
  items plus the unmatched/duplicate audit events (`[[kuickpay-run-detail-audit-allowlist]]`).

### Redaction / escalation-evidence boundary (NFR8 — `KuickPayRedactor` / `KuickPayEvidence`)

- **Safe to escalate** (the `KuickPayEvidence` getters the admin UI already exposes,
  `components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php:72-159`): status, error class,
  KuickPay reference, Consumer/Registration Number, amount, currency, paid-at, raw provider status
  code, **redacted trace id**, **evidence hash**, validation-reason tokens.
- **Never share** (masked by `KuickPayRedactor`, `.../lib/KuickPayRedactor.php:15-58,185-214`): raw
  SOAP request/response (`raw_result`/`raw_envelope`), WSDL endpoint, credentials (voucher/inquiry
  username+password, Institution ID), customer PII (name/mobile/CNIC/email). The unredacted raw
  getters exist but are **not** support evidence (`[[kuickpay-soapclient-rawresult-unredacted]]`) —
  always use the redacted diagnostics/safe getters the admin screens render.
- Give a **placeholder-only escalation template** so a ticket can carry the redacted trace id +
  evidence hash + Consumer Number (all non-PII) for KuickPay correlation without leaking anything.

### Doc conventions to mirror (existing `docs/kuickpay/` set)

- **Sanitized header** at the top of each doc, mirroring `deployment-guide.md:1-17` and
  `gateway-settings-and-endpoint-hardening-verification.md:1-8`: no `config/blesta.php`/DB/KuickPay
  credentials, no Institution ID, no WSDL host, no PII; what was verified vs assumed (NFR12).
- **Placeholder style:** `<consumer-number>`, `<invoice-id>`, `<redacted-trace-id>`,
  `<evidence-hash>`, `<operator-provided-wsdl-url>` — the style already used in
  `live-smoke-runbook.md:47-51` and `deployment-guide.md`.
- **Audience:** both docs are for **operators/support**; keep the developer-facing `blesta-footguns.md`
  separate. **Markdown** only, in `docs/kuickpay/`. Tables (status map, count fields, escalation
  template) are the most scannable form.

### Where this story sits in the doc plan (don't overreach)

`epics.md:148` + `architecture.md:754-762` plan the `docs/kuickpay/` set across 5.8–5.10:
- **5.8 (done):** `deployment-guide.md` (install/config) + `blesta-footguns.md`.
- **5.9 (this story):** reconciliation runbook (+ admin-review section) + support troubleshooting →
  `reconciliation-runbook.md` (and optionally `admin-review-runbook.md`) + `support-troubleshooting.md`.
- **5.10:** rollback + upgrade + production-launch checklist. **Out of scope here** — reference the
  gateway-disable vs plugin-cron-disable separation only if needed, don't deep-document it.

### Previous Story Intelligence (5.8 — done; 5.7, 5.6)

- **5.8** established the exact sanitized-doc + placeholder discipline this story reuses and shipped
  `deployment-guide.md` + `blesta-footguns.md`. Its review caught an **endpoint-host-literal leak**
  and a **wrong `reconciliation_enabled` scope** claim — apply the same paranoia: scan for leaked
  values and verify every behavioral claim against code. Link 5.8 from these runbooks (don't
  re-document install/config/credentials — that's 5.8).
- **5.7** is the credentialed live-smoke runbook (`live-smoke-runbook.md`); its review hammered
  AC2-style leakage (a fault leaked the WSDL host). The escalation-evidence section here must be just
  as strict about never surfacing raw faults/endpoints.
- **5.6** is the endpoint-hardening source; only reference it for "where the WSDL lives", never a host
  literal.
- **Runtime reality** (`[[kuickpay-php82-toolchain-now-available]]`): production is **PHP 8.3
  (ea-php83)**; "8.2" is a source-floor. If a runbook mentions runtime, say 8.3 production / 8.2
  source-floor.

### Git Intelligence

- Baseline HEAD `e6e49190` `docs(kuickpay): document deployment and blesta footguns` (5.8 landing).
  Epic-5 docs land via `docs(kuickpay): …` commits kept **separate** from runtime/test commits —
  follow that for this docs-only story. Pre-existing working-tree noise (`.htaccess`, `dashboard`,
  etc.) is **outside** this task — leave it untouched (`project-context.md:114`).

### Project Structure Notes

- New files (docs only): `docs/kuickpay/reconciliation-runbook.md`,
  `docs/kuickpay/support-troubleshooting.md` (optionally `docs/kuickpay/admin-review-runbook.md`),
  plus the `_bmad-output/` story file. **No** `components/`, `plugins/`, `tests/`, schema, or
  `config.json` changes; **no** version bump.
- Aligns with `epics.md:148` and the architecture's planned doc set (`architecture.md:754-762`).
  Additive beside the existing `docs/kuickpay/` verification + 5.8 docs; no churn to closed records.

### References

- [Source: epics.md#Story-5.9 (1052–1066)] — the two ACs (reconciliation runbook; support
  troubleshooting guide).
- [Source: epics.md:148] — reconciliation-runbook / admin-review-runbook / support-troubleshooting
  live under `docs/kuickpay/`.
- [Source: epics.md:101,105,109] — NFR8 (no secret exposure), NFR10 (no hard-coded
  endpoints/credentials/Institution IDs), NFR12 (honest reporting).
- [Source: architecture.md:86,287,304,307,375,424,426-439,518-526,754-789] — Manual Review is a
  valid safe state; confirm payment truth without raw responses; only `posted` implies paid; no
  force-paid; admin reconciliation workbench + redacted diagnostics; ownership boundaries; planned
  doc set.
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php:217-250,257-352,594-659,788-820] —
  cron dispatch + task defs, getActions/getPermissions, run/item/audit schema.
- [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:176-377] — reconcileVoucher
  (Check Now) + runBulk + run lifecycle.
- [Source: plugins/kuickpay_reconcile/controllers/admin_vouchers.php:49-281,420-452] — list/detail +
  recheck/review/cancel + safeRecheckOutcome.
- [Source: plugins/kuickpay_reconcile/controllers/admin_main.php:43-147,
  controllers/admin_reconciliation.php:136-149, controllers/admin_manual_review.php:55-117] — bulk
  trigger, run summary/drill-down, manual-review queue.
- [Source: plugins/kuickpay_reconcile/models/kuickpay_vouchers.php:13-22,52-67,512-628] — STATUSES,
  ALLOWED_ACTIONS_BY_STATE, getReconcilable/getExpirable/expire.
- [Source: plugins/kuickpay_reconcile/lib/KuickPayVoucherListPresenter.php:67-78,122-202,264-277] —
  filter keys, error-class/validation/audit-event allowlists, diagnostic-field allowlist.
- [Source: plugins/kuickpay_reconcile/language/en_us/admin_vouchers.php:30-39,119-141,166-182;
  admin_reconciliation.php:15-23; admin_main.php:5; kuickpay_reconcile_plugin.php:4-22] — admin
  labels, count footnotes, bulk banner, cron task names.
- [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php:20-29] — customer
  status labels + the "marks paid only after KuickPay confirms" notice.
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php:72-159;
  lib/KuickPayRedactor.php:15-58,185-214] — safe escalation getters vs the redaction boundary.
- [Source: docs/kuickpay/deployment-guide.md:1-17] — sanitized-header + placeholder pattern to
  mirror; install/config to link (not re-document).
- [Source: _bmad-output/kuickpay/implementation-artifacts/deferred-work.md] — 3.7 bulk count-overlap
  (closed by the subset footnote), 4-3 manual-Check-Now during-inquiry race (rare/fail-closed).
- [Source: project-context.md:104,114,125] — docs-commit separation; leave unrelated working-tree
  changes; no secrets in docs.
- Memory: `[[kuickpay-parser-single-identity-contract]]`, `[[kuickpay-reconcile-state-set]]`,
  `[[kuickpay-recheck-outcome-token-set]]`, `[[kuickpay-run-detail-audit-allowlist]]`,
  `[[kuickpay-expiry-reconcile-clock-skew]]`, `[[kuickpay-soapclient-rawresult-unredacted]]`,
  `[[kuickpay-admin-list-blesta-footguns]]`, `[[kuickpay-php82-toolchain-now-available]]`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|---|---|
| 2026-06-16 | Story 5.9 drafted (ready-for-dev): reconciliation + support runbooks scoped against shipped Epic 3/4 behavior, verified to source at baseline `e6e49190`. |
