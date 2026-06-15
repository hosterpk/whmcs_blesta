# KuickPay Reconciliation Runbook

Date: 2026-06-16
Audience: **support and finance operators** running KuickPay reconciliation and clearing the
manual-review queue.
Scope: Story 5.9 reconciliation runbook — scheduled reconciliation, Check Now, bulk
reconciliation, run summaries, and the manual-review queue (including the late / under / over /
duplicate / unmatched cases). Install/enable/configuration is **Story 5.8** (`deployment-guide.md`)
and rollback/upgrade/launch is **Story 5.10** (`rollback-runbook.md`, `upgrade-runbook.md`,
`production-launch-checklist.md`) — both out of scope here.

This document is sanitized. It contains **NO** `config/blesta.php` values, database credentials,
KuickPay credentials, Institution ID values, real WSDL host names, raw SOAP payloads, real
Consumer/Registration/KuickPay reference values, or customer PII (NFR8/NFR10). Every concrete value
below is a **placeholder** (e.g. `<consumer-number>`, `<invoice-id>`, `<run-id>`). It states plainly
what is **verified against the shipped code** versus what an operator must confirm in their own
environment (NFR12).

All facts below were verified against source at baseline commit `e6e49190`. **The code is truth:** if
this runbook and the running system ever disagree, trust the system and report the doc bug.

---

## 0. The one rule that governs everything

**Only `posted` means paid.** A KuickPay voucher marks a Blesta invoice paid **only** when it reaches
the `posted` state, and the **only** path to `posted` is the `post_confirmed` cron task (or the
equivalent manual post via Check Now). No other state — `pending`, `retry`, `confirmed_unposted`,
`manual_review`, `failed`, `expired`, `cancelled` — is a paid state, and there is **no "force paid"
action anywhere in the product**. Keep this in mind for every step below.

---

## 1. The two trees and where these screens live

KuickPay ships as two cooperating Blesta extensions (see `deployment-guide.md` §1):

- **Gateway** `components/gateways/nonmerchant/kuickpay/` (class `Kuickpay`) — checkout, the customer
  reference display, settings, and the SOAP/parser/redactor libraries.
- **Plugin** `plugins/kuickpay_reconcile/` (class `KuickpayReconcilePlugin`) — durable voucher state,
  the cron tasks, posting, the database schema, and **the four admin screens this runbook covers**.

The four staff screens live under **Billing** in the admin nav:

| Nav item | Screen | Permission (ACL alias / action) |
|---|---|---|
| KuickPay Vouchers | Voucher list + detail | `kuickpay_reconcile.admin_vouchers` / `*` |
| KuickPay Bulk Reconciliation | Bulk run trigger | `kuickpay_reconcile.admin_main` / `*` |
| KuickPay Manual Review | Manual-review queue | `kuickpay_reconcile.admin_manual_review` / `*` |
| KuickPay Reconciliation Runs | Run list + run detail | `kuickpay_reconcile.admin_reconciliation` / `*` |

Three further actions on the voucher detail page are separately permissioned: **Check Now**
(`recheck`), **Mark Manual Review** (`review`), **Cancel** (`cancel`), and the **Diagnostics** box
(`diagnostics`) — all on the `kuickpay_reconcile.admin_vouchers` alias. A staff member can be allowed
to *view* vouchers without being allowed to act on them.

---

## 2. Scheduled reconciliation — the three cron tasks

KuickPay registers **three Blesta plugin cron tasks**. They appear in Blesta's automation/cron logs
under the names below and run on Blesta's cron cadence (configure the cron itself per
`deployment-guide.md`; do not re-document install/enable here).

| Cron key | Task name (as logged) | Interval | What it does |
|---|---|---:|---|
| `reconcile_pending` | **Reconcile Pending KuickPay Vouchers** | every **5 min** | Checks **Pending + Retry** vouchers by single inquiry. |
| `post_confirmed` | **Post Confirmed KuickPay Payments** | every **5 min** | Posts **Confirmed (Unposted)** vouchers to Blesta. **The only path that marks an invoice paid.** |
| `expire_vouchers` | **Expire KuickPay Vouchers** | every **60 min** | Transitions past-expiry **Pending / Retry** vouchers to **Expired**. |

### 2.1 `reconcile_pending` — "Reconcile Pending KuickPay Vouchers"

- Selects only **Pending** and **Retry** vouchers, **PKR only**, **not yet expired**.
- A **Pending** voucher is rechecked no more often than every **~30 minutes**
  (`PENDING_RECHECK_MINUTES = 30`).
- A **Retry** voucher backs off exponentially: the next eligible recheck is
  `min(360, 30 × 2^retry_count)` minutes after the last check — i.e. 30 min, then 60, 120, 240, and
  then **capped at 360 minutes (6 hours)**. After `RETRY_LIMIT = 5` exhausted attempts the voucher
  is routed to manual review rather than retried forever.
- Runs as a **DB-locked, bounded, resumable** batch: up to **100 vouchers** per run
  (`BATCH_SIZE = 100`), soft runtime budget **240 s**, with a `cursor` so the next run continues.
- The run takes a DB lock named `reconcile_pending` (TTL **600 s**). **If the lock is already held,
  the run is `skipped` — this is normal, not an error** (you may see a benign "skipped: lock_held").
- Gated by the gateway **`reconciliation_enabled`** setting. If the PKR KuickPay gateway is absent or
  reconciliation is disabled, the run is `skipped` with reason `kuickpay_unavailable`.

### 2.2 `post_confirmed` — "Post Confirmed KuickPay Payments"

- Posts **Confirmed (Unposted)** vouchers to a Blesta transaction.
- **Row-locked and idempotent** — re-running it never double-posts.
- This is the **only** task (and the only code path) that creates/applies a Blesta transaction and
  flips an invoice to paid. Nothing posts outside `post_confirmed` (and its manual equivalent, Check
  Now on a confirmed voucher).

### 2.3 `expire_vouchers` — "Expire KuickPay Vouchers"

- Every **60 min**, performs a status-guarded transition of past-expiry **Pending / Retry** vouchers
  to **Expired** (a one-row `UPDATE … WHERE status IN (pending, retry)`), and writes a
  `voucher.expired` audit event.
- Expiry never touches a `posted`, `confirmed_unposted`, `manual_review`, `failed`, or `cancelled`
  voucher.

> **Operator note:** the 5-minute cadence means a customer who has just paid at the bank may wait a
> few minutes before the voucher moves to `confirmed_unposted` and then `posted`. That delay is
> expected; it is not a failure. Direct customers to wait for the invoice itself to show paid.

---

## 3. Check Now — single manual recheck

**Where:** the **Check Now** button on a voucher's detail page (`KuickPay Vouchers → open a voucher`).
**Permission:** `recheck`. **Method:** POST only.

Check Now is available only on **Pending, Retry, and Confirmed (Unposted)** vouchers (per the voucher
state machine `ALLOWED_ACTIONS_BY_STATE`). It is a deliberate **manual override that runs outside the
cron batch lock**, so an operator can re-check one voucher immediately without waiting for the next
scheduled run.

- On a **Pending / Retry** voucher: it runs **one** single inquiry. If that inquiry validates the
  payment and moves the voucher to `confirmed_unposted`, Check Now then **also attempts posting** in
  the same click.
- On a **Confirmed (Unposted)** voucher: it **attempts posting** directly.

It is **safe**: it fails closed and preserves evidence; it never force-marks a voucher paid. Each
click writes an `admin.rechecked` audit event recording the prior status and the outcome.

### 3.1 Check Now outcomes and the message you will see

The outcome is mapped through a closed allowlist (any unexpected value falls back to `failed`), so
the banner is always one of these:

| Outcome | Banner | Type |
|---|---|---|
| `posted` | "Checked — payment posted." | success |
| `already_posted` | "Already posted." | success |
| `confirmed_unposted` | "Checked — payment confirmed." | success |
| `pending` | "Checked — payment still pending." | success |
| `retry` | "Checked — provider still unavailable, will retry." | success |
| `manual_review` | "Checked — routed to manual review." | success |
| `expired` | "Checked — voucher has expired." | success |
| `unavailable` | "Couldn't reach KuickPay — please try again." | error |
| `skipped` | "KuickPay reconciliation is unavailable." | error |
| `failed` | "Check failed — please try again later." | error |

> **"Routed to manual review" is not an error.** It means the evidence was ambiguous or hit a policy
> hold and is now safely parked for an operator — see §6.

> **Footnote (rare, self-healing):** because Check Now runs outside the cron batch lock, a Check Now
> fired at the exact instant the cron is inquiring the same voucher has a documented, rare race. It
> **fails closed** (no double-post, evidence preserved) and self-heals on the next scheduled run; it
> is not something to chase.

---

## 4. Bulk Reconciliation

**Where:** the **KuickPay Bulk Reconciliation** screen (`KuickPay Bulk Reconciliation` nav item).
**Permission:** `bulk_reconcile` (`kuickpay_reconcile.admin_main`). **Method:** POST only.

The operator supplies a single **Transaction date** field (the input is `run_date`, format
`YYYY-MM-DD`). The date:

- must be a valid `YYYY-MM-DD` date (else "Enter a valid transaction date in YYYY-MM-DD format."),
- **cannot be in the future** (else "The transaction date cannot be in the future."),
- **cannot be older than 365 days** (`MAX_LOOKBACK_DAYS = 365`; else "The transaction date is too far
  in the past (maximum look-back is 365 days).").

Submitting triggers **one `BillPaymentBulkInquiry`** for that date. Each returned provider row is
matched **by Consumer Number** against local **Pending / Retry** vouchers, producing three buckets:

| Bucket | Meaning | Recorded as |
|---|---|---|
| **matched** | Provider row matched a local Pending/Retry voucher → it is reconciled normally. | A normal item-row transition (and counted in `total_checked`). |
| **unmatched** | Provider row whose Consumer Number matches **no local voucher**. | **Audit-only** `evidence.unmatched` event; counted in `total_unmatched` (and `total_manual_review` — see §5.2). **No item row.** |
| **duplicate** | The **same Consumer Number echoed twice** within the one run. | **Audit-only** `evidence.duplicate` event; counted. **No item row.** |

On success you get the completion banner:

> "Bulk reconciliation run #`<run-id>` completed. Checked: `<n>`, unmatched: `<n>`, manual review:
> `<n>`."

(The three numbers are `total_checked`, `total_unmatched`, and `total_manual_review` for that run.)
If the run could not start it instead shows "Bulk reconciliation was skipped: `<reason>`." or "Bulk
reconciliation did not complete. Status: `<status>`."

> Bulk reconciliation only ever **moves matched local vouchers forward and records evidence** for the
> rest. It cannot create a voucher, and an unmatched provider row is never auto-posted — it is logged
> for an operator to investigate by Consumer Number.

---

## 5. Run summaries — how to read a reconciliation run

**Where:** the **KuickPay Reconciliation Runs** screen lists every run (newest first), and each run
links to a **run-detail** drill-down. Both are read-only.

Every run (cron, manual, or bulk) records a row with a `trigger_type` (**Scheduled / Manual /
Bulk**), a `status` (**Running / Completed / Aborted / Failed**), start/complete timestamps, the
`run_date` (bulk only), a resume `cursor`, and nine durable counters.

### 5.1 The nine counters and the six displayed labels

Durable counters on the run row: `total_eligible`, `total_checked`, `total_confirmed`,
`total_retry`, `total_manual_review`, `total_expired`, `total_failed`, `total_errors`,
`total_unmatched`.

The run list and run detail display them under these labels:

| Displayed label | Counter | Read it as |
|---|---|---|
| **Checked** | `total_checked` | Vouchers (or provider rows, on bulk) processed this run. |
| **Confirmed (ready to post)** | `total_confirmed` | Evidence validated, **awaiting posting — NOT yet paid**. |
| **Manual Review (incl. unmatched)** | `total_manual_review` | Routed to the manual-review queue this run. |
| **— of which Unmatched** | `total_unmatched` | The subset of the above that were bulk unmatched rows. |
| **Failed** | `total_failed` | Unrecoverable evidence results this run. |
| **Errors** | `total_errors` | Run-level transport/parse errors. |

### 5.2 The count footnotes — read these or you will mis-count

The UI carries four honest-reporting footnotes. Capture all four when explaining a run:

1. **Posting is a separate task:** *"Confirmed rows are validated evidence awaiting posting; posting
   runs on a separate task."* — a "Confirmed (ready to post)" count is **not** money in the bank yet.
2. **Unmatched is a subset, not an addition:** *"On bulk runs, Unmatched is a subset of Manual Review
   (the same provider row increments both); it is shown as a subset, not added separately."* — do
   **not** add Unmatched on top of Manual Review; the same row bumped both counters.
3. **Bulk "Checked" counts provider rows:** *"On bulk runs, Checked/Eligible counts returned provider
   rows, not local eligible vouchers."* — on a bulk run, Checked is "rows KuickPay returned," not
   "local vouchers due."
4. **Failed and Errors can both fire for one incident:** *"A wholesale bulk transport/parse failure
   increments both Failed and Errors for the same run-level incident; they are shown separately,
   never summed."*

### 5.3 Item rows vs audit-only exceptions

The run-detail page has two sections under the count breakdown:

- **Processed Item Transitions** — one `kuickpay_reconciliation_items` row per processed voucher,
  keyed uniquely on `(run_id, voucher_id)`. Up to **500** are shown with an honest "showing first N
  of M" notice when truncated.
- **Audit-Only Exceptions** — bulk **unmatched / duplicate** evidence writes **no item row**; it
  produces only an `evidence.unmatched` / `evidence.duplicate` audit event. The run-detail view
  surfaces **only those two** audit event types (the run-detail audit allowlist) — every other audit
  event lives on the voucher's own diagnostics timeline, not here. This is why `total_unmatched`
  always has a matching per-row entry in this section even though there is no voucher to transition.

---

## 6. Manual Review queue — and the late / under / over / duplicate / unmatched cases

**Where:** the **KuickPay Manual Review** screen is a read-only queue of every `manual_review`
voucher, most-recently-checked first (never-checked rows float to the top). Each row links to its
**voucher detail** page, where the operator acts. **Permission:** `manual_review`.

**Manual Review is a valid, recoverable safe state — not a product failure and not an error to
"clear" by forcing payment.** It is where the system parks a voucher whose payment truth is ambiguous
or held by policy, so a human decides. There is **no force-paid / force-post action** anywhere.

### 6.1 How a voucher lands in Manual Review

| Case | Cause | Notes |
|---|---|---|
| **Underpayment** | Customer paid less than due. | Routed by the **fixed** `underpayment_policy = manual_review` gateway setting — never auto-posts. |
| **Overpayment** | Customer paid more than due. | Routed by the **fixed** `overpayment_policy = manual_review` setting — never auto-posts. |
| **Late payment** | Payment confirmed after the reference window. | Routed by the **fixed** `late_payment_policy = manual_review` setting — never auto-posts. |
| **Duplicate reference** | Duplicate KuickPay reference / duplicate Consumer Number echo. | Routed to `manual_review`; on a **bulk** run it is **audit-only** (`evidence.duplicate`), counted but with no local voucher transition. |
| **Unmatched payment** | Bulk provider evidence with **no local voucher**. | Counted in `total_unmatched`; surfaced on **run detail**, not as a queue row (there is no voucher to act on — investigate via Consumer Number). |
| **Stale evidence** | Evidence older/inconsistent with the live voucher (`stale_voucher`). | Held rather than posted. |
| **Evidence-validation failure** | Currency / amount / invoice mismatch, unknown status, etc. | Held rather than posted. |
| **Retry-limit exhaustion** | `RETRY_LIMIT = 5` attempts used without a clean result. | Routed to manual review instead of retrying forever. |
| **Posting failure** | Missing paid date, blocked invoice adoption, an existing-transaction conflict, a transaction create/apply failure, or the posting cap. | Held for an operator; the invoice is **not** marked paid. |

> The three payment-policy settings (`underpayment_policy`, `overpayment_policy`,
> `late_payment_policy`) are **fixed to `manual_review`** — the settings validator accepts no other
> value. Under/over/late payments therefore **always** go to a human and never auto-post.

### 6.2 What an operator can do — the real action matrix

From a voucher's detail page the available actions are **gated by the voucher's current state**
(`ALLOWED_ACTIONS_BY_STATE`). This is the verified matrix:

| Voucher state | Check Now | Mark Manual Review | Cancel |
|---|:--:|:--:|:--:|
| Pending | ✅ | ✅ | ✅ |
| Retry | ✅ | ✅ | ✅ |
| Confirmed (Unposted) | ✅ | ✅ | — |
| Posted | — | — | — |
| Failed | — | ✅ | ✅ |
| Expired | — | ✅ | ✅ |
| **Manual Review** | — | — | ✅ |
| Cancelled | — | — | — |

The three actions:

- **Check Now** (`recheck`) — re-run inquiry/posting (see §3). Available on Pending / Retry /
  Confirmed (Unposted).
- **Mark Manual Review** (`review`) — **routes a voucher *into* the manual-review queue** from
  Pending / Retry / Confirmed (Unposted) / Failed / Expired. Requires an admin note. Writes an
  `admin.reviewed` event; shows "Voucher routed to manual review."
- **Cancel** (`cancel`) — terminal transition to **Cancelled**, from Pending / Retry / Failed /
  Expired / **Manual Review**. Requires an admin note. Writes an `admin.cancelled` event; shows
  "Voucher cancelled." (The customer may then reissue a fresh reference.)

> **Important — what "acting on a Manual Review voucher" actually means.** Once a voucher is **already
> in `manual_review`, the only in-plugin action offered is `Cancel`.** Check Now and Mark Manual
> Review are **not** available from the `manual_review` state (Check Now needs Pending/Retry/Confirmed;
> Mark Manual Review is the *route-in* action). There is deliberately **no force-post / force-paid**
> path. So the operator's job on a manual-review voucher is to **investigate** (read the Diagnostics
> timeline and the validation-reason labels — see `support-troubleshooting.md`), and then either:
> - leave it parked while the underlying issue is resolved (e.g. the customer completes/repeats the
>   payment, after which a fresh voucher reconciles normally), or
> - **Cancel** it (customer reissues), or
> - resolve the payment through **Blesta's own native transaction tools**, entirely outside this
>   plugin — KuickPay never force-marks the voucher paid.
>
> (This corrects the story's task-1.6 paraphrase, which listed Check Now / Review / Cancel as
> manual-review actions; the shipped state machine offers only **Cancel** from `manual_review`. See
> the Dev Agent Record finding.)

---

## 7. Audit trail

Every reconciliation and admin action writes a `kuickpay_audit_events` row with a **redacted** trace
id, an evidence hash, and an already-redacted payload (never raw SOAP). Operators see these on the
voucher's **Diagnostics → Audit Timeline** (requires the `diagnostics` permission). The emitted event
vocabulary (rendered through a closed label allowlist) includes:

`voucher.issued`, `voucher.replaced`, `voucher.expired`, `voucher.generation_failed`,
`evidence.received`, `evidence.matched`, `evidence.retry_decision`, `evidence.rejected`,
`evidence.duplicate`, `evidence.unmatched`, `evidence.error`, `reconciliation.run.started`,
`reconciliation.run.completed`, `posting.started`, `posting.succeeded`, `posting.failed`,
`admin.rechecked`, `admin.reviewed`, `admin.cancelled`.

(As noted in §5.3, the **run-detail** view surfaces only `evidence.unmatched` / `evidence.duplicate`;
the rest are visible on the individual voucher's timeline.)

---

## 8. Honest-reporting notes (NFR12)

- Every cron key/name/interval, status name, count field, count footnote, banner string, permission
  alias, audit-event name, and the action matrix above was cross-checked against the shipped source
  at baseline `e6e49190` — specifically `kuickpay_reconcile_plugin.php` (cron defs + schema),
  `KuickPayReconcileService.php` (cadence/lock/batch constants), `models/kuickpay_vouchers.php`
  (`STATUSES`, `ALLOWED_ACTIONS_BY_STATE`, retry backoff SQL), the `admin_main` / `admin_reconciliation`
  / `admin_manual_review` / `admin_vouchers` controllers, `KuickPayVoucherListPresenter.php`
  (allowlists), and the `en_us` language files.
- The retry backoff is the literal `LEAST(360, 30 * POW(2, retry_count))` minutes; "≈6 h cap" is that
  360-minute ceiling.
- This runbook describes shipped behavior only. It does not assert that any live reconciliation,
  inquiry, or posting was performed from this document — those are operator actions in a real
  environment (the sanctioned real-provider check is the Story 5.7 live smoke).
- **Discrepancy recorded (not "fixed"):** the Manual-Review action set is `Cancel`-only from the
  `manual_review` state (§6.2), narrower than the story draft's paraphrase. The code is authoritative;
  this is logged in the story's Dev Agent Record rather than changed in code.

## See also

- `docs/kuickpay/support-troubleshooting.md` — searching by Invoice/Consumer Number, reading Voucher
  Detail, the safe status table, and collecting sanitized escalation evidence.
- `docs/kuickpay/deployment-guide.md` — install, enable, configuration, and the gateway settings
  (including `reconciliation_enabled` and the cron setup) referenced above.
- `docs/kuickpay/rollback-runbook.md` — disabling gateway/cron while preserving voucher and audit
  evidence.
- `docs/kuickpay/production-launch-checklist.md` — launch monitoring gates that reference this
  runbook's cron and Manual Review sections.
- `docs/kuickpay/blesta-footguns.md` — developer-facing Blesta framework footguns behind these
  behaviors.
- `docs/kuickpay/live-smoke-runbook.md` — the opt-in credentialed real-provider smoke (Story 5.7).
- `docs/kuickpay/testing-fixtures.md` — fixture provenance and fail-closed parser evidence behind
  inquiry and bulk-reconciliation behavior.
