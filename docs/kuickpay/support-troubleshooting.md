# KuickPay Support Troubleshooting Guide

Date: 2026-06-16
Audience: **support and finance staff handling a customer payment claim** ("I paid — why does my
invoice still say unpaid?").
Scope: Story 5.9 support troubleshooting — finding a voucher, reading Voucher Detail, interpreting
the safe status labels, and collecting sanitized escalation evidence. The reconciliation engine and
the manual-review queue are documented in `reconciliation-runbook.md`. Install/config is **Story
5.8** (`deployment-guide.md`). Rollback/upgrade/launch guidance is **Story 5.10**
(`rollback-runbook.md`, `upgrade-runbook.md`, `production-launch-checklist.md`).

This document is sanitized. It contains **NO** `config/blesta.php` values, credentials, Institution
ID, real WSDL host, raw SOAP/XML, real Consumer/Registration/KuickPay reference values, or customer
PII (NFR8/NFR10). Every concrete value is a **placeholder** (e.g. `<consumer-number>`, `<invoice-id>`,
`<redacted-trace-id>`, `<evidence-hash>`). It states plainly what is **verified against the shipped
code** versus what you confirm in your own environment (NFR12).

All facts below were verified against source at baseline commit `e6e49190`. **The code is truth.**

---

## 0. The single safety rule

**Only `posted` means paid.** The customer-facing surface styles and labels **only** a `posted`
voucher as "Payment received"; everything else — including `confirmed_unposted` — is shown as *not yet
received*. **Never tell a customer their payment is received/done until the voucher is `posted` and
the Blesta invoice itself shows paid.** The standing notice the customer already sees on the payment
page is:

> *"Blesta marks this invoice paid only after KuickPay confirms your payment."*

There is **no "force paid" button** in the product, and support must never claim a payment is posted
when it is not.

---

## 1. Start from the customer's claim — search and lookup

**Where:** the **KuickPay Vouchers** admin list (Billing → KuickPay Vouchers). **Permission:** the
voucher **view** permission (`kuickpay_reconcile.admin_vouchers`).

The list accepts this closed set of filters (anything else is dropped before it can reach the query):

| Filter (label) | Match | Notes |
|---|---|---|
| **Invoice ID** | exact, numeric | The fastest lookup from a ticket that quotes an invoice. |
| **Consumer Number** | partial (LIKE) | The number the customer entered at their bank. |
| **Registration Number** | partial (LIKE) | The internal reference number. |
| **KuickPay Reference** | partial (LIKE) | The provider's reference, if the customer has it. |
| **Client ID** | exact, numeric | All vouchers for one client. |
| **Amount** | value match | e.g. `100.00`. |
| **Status** | dropdown | One of the 8 statuses (see §3). |
| **Created From / Created To** | `YYYY-MM-DD` | Date-created range. |
| **Has Blesta transaction** | toggle | Narrow to vouchers that did / did not post. |

You can sort by Created, Client, Consumer Number, Status, or Last Inquiry.

**Recipe — "I paid but my invoice is unpaid":**
1. Search by **Invoice ID** (exact) or **Consumer Number** (partial) → open the matching voucher.
2. Read its **Status** (§3) — that single value tells you whether it is paid (`posted`) or still in
   flight.
3. If it is not `posted`, read **Voucher Detail** (§2) and the validation reasons before you reply.

> If a bulk run reported an **unmatched** payment (a provider row with no local voucher — see
> `reconciliation-runbook.md` §4/§5.3), there is **no voucher to open**. Investigate by Consumer
> Number and check the reconciliation **run detail** for the `evidence.unmatched` entry.

---

## 2. Inspect Voucher Detail

The detail page has six boxes:

| Box | Shows |
|---|---|
| **Voucher Summary** | Status, client, Registration Number, Consumer Number, KuickPay reference, amount/currency, and the date fields: **Created, Updated, Due, Expires, Last Inquiry, Paid, Posted**. |
| **Invoice Mapping & Related Records** | The linked invoice(s) and the **Posting State** label. **The Blesta transaction link appears only when the voucher is `posted`** (UX-DR20) — its absence is the at-a-glance "not posted yet" signal. |
| **Admin Notes** | Operator notes prepended on each Review/Cancel action. |
| **Actions** | The state-gated **Check Now / Mark Manual Review / Cancel** buttons (see `reconciliation-runbook.md` §6.2 for the per-state matrix). |
| **Parsed Response Summary** | The normalized, **safe** summary of the last provider response. |
| **Diagnostics** | **Separately permissioned** (`diagnostics` action). The **redacted** audit timeline plus the allowlisted diagnostic fields. |

### 2.1 The Diagnostics box — what it can and cannot show

The Diagnostics box is gated by its own `diagnostics` permission (a staffer can view a voucher without
seeing diagnostics). When shown, it renders:

- **The redacted audit timeline** — each event's label, date, **redacted trace id**, **evidence
  hash**, and an **already-redacted** payload.
- **An allowlisted set of diagnostic fields**, and only these: `status`, `raw_status`, `error_class`,
  `evidence_hash`, `redacted_trace_id`, `validation_errors`, `reference`, `consumer_number`,
  `registration_number`, `amount`, `currency`, `paid_at`.

It **never** shows raw SOAP/XML, the WSDL endpoint, or credentials — those are stripped before
storage and before display. The `validation_errors` and `error_class` values are rendered as
**human labels** (e.g. "Underpayment", "Amount mismatch"), never raw tokens.

---

## 3. Interpret safe statuses — the label table (the safety core)

This is the mapping every support reply depends on. Admin labels, customer-facing labels, and the
"safe to call paid?" column are all verified against the shipped language files.

| Status | Admin label | Customer-facing label | Operational meaning | Safe to call "paid"? |
|---|---|---|---|:--:|
| `pending` | Pending | "Payment reference created — awaiting payment" | Issued, awaiting payment/confirmation | **No** |
| `retry` | Retry | "Confirmation delayed" | Provider was unavailable; will recheck on backoff | **No** |
| `confirmed_unposted` | Confirmed (Unposted) | **"Waiting for payment confirmation"** | Evidence validated, **not yet posted** | **No** |
| `posted` | Posted | **"Payment received"** | Posted to a Blesta transaction | **YES — the only paid state** |
| `failed` | Failed | "Confirmation delayed" | Unrecoverable evidence result; cannot auto-post | **No** |
| `expired` | Expired | "Payment reference expired" | Past expiry; the window closed | **No** |
| `manual_review` | Manual Review | "Payment under review" | Ambiguous/policy hold; needs an operator | **No** |
| `cancelled` | Cancelled | "Payment reference cancelled" | Admin-terminated; customer may reissue | **No** |

Notes:
- The customer surface gives **only `posted`** the green "Payment received" styling; every other state
  is shown as not-yet-received. Notably, **`confirmed_unposted` shows the customer "Waiting for
  payment confirmation," not "paid."**
- `Unknown` ("Reference status unavailable" to the customer) is a **display fallback** for an
  unrecognized value — it is **not** a stored lifecycle status, so a real voucher never *sits* in it.
- Note the customer labels are intentionally reassuring-but-honest: `retry` and `failed` both read
  "Confirmation delayed" to the customer (the difference is operational, visible to staff only).

---

## 4. Do / don't — avoid unsafe paid-state claims

**Do:**
- Read the **Status** first and answer from it.
- Tell the customer to **wait for the invoice to show paid**; explain that confirmation can take a few
  minutes after they pay at the bank (the cron checks every ~5 minutes — see
  `reconciliation-runbook.md` §2).
- For `confirmed_unposted`, say "we've **received confirmation of your payment and it's being
  applied**" — posting runs on a **separate** task — but do **not** say the invoice is paid until it
  is `posted`.

**Don't:**
- ❌ Never tell a customer a `pending`, `retry`, `confirmed_unposted`, `manual_review`, `expired`, or
  `failed` voucher is "paid" / "done" / "received."
- ❌ Never promise to "force" or "manually mark" the payment — **there is no force-paid action**, by
  design. (A genuinely stuck-but-legitimate payment is resolved via investigation and, if needed,
  Blesta's own native transaction tools — never by overriding KuickPay's verdict.)
- ❌ Never read a green checkmark on `confirmed_unposted` — it is **not** posted.

---

## 5. Collect sanitized escalation evidence

When a case must go to KuickPay or to engineering, copy only the **safe** fields. These are exactly
the values the admin UI already exposes (the `KuickPayEvidence` safe getters and the redacted
diagnostics) — they are non-secret and non-PII and are safe to put in a ticket:

**Safe to include in an escalation:**

| Field | Example placeholder |
|---|---|
| Voucher status | `confirmed_unposted` |
| Error class (label) | "Amount Mismatch" |
| Validation reason(s) (labels) | "Underpayment", "Late payment" |
| KuickPay reference | `<kuickpay-reference>` |
| Consumer Number | `<consumer-number>` |
| Registration Number | `<registration-number>` |
| Amount / currency | `<amount>` / `PKR` |
| Paid-at | `<paid-at>` |
| Raw provider status code | `<raw-status>` |
| **Redacted trace id** | `<redacted-trace-id>` |
| **Evidence hash** | `<evidence-hash>` |
| Reconciliation run id | `<run-id>` |

**Never share (these are masked by the redactor and must never leave the system):**

- ❌ Raw SOAP request/response (`raw_result` / `raw_envelope`) or any XML envelope.
- ❌ The WSDL endpoint / host.
- ❌ Credentials — voucher and inquiry **username + password**, and the **Institution ID**.
- ❌ Customer PII — **name, mobile, CNIC, email**.

> The codebase does contain unredacted raw payload getters, but **they are not support evidence** and
> are not what the admin screens render. Always escalate from the **redacted Diagnostics / safe
> getters** the UI shows — never reach for a raw envelope to "give KuickPay more detail." The
> redacted trace id + evidence hash + Consumer Number are enough for KuickPay to correlate the
> transaction on their side without exposing anything sensitive.

### 5.1 Placeholder-only escalation template

Copy this and fill it from the **Voucher Detail page**, the **Diagnostics box**, and the customer
ticket. Diagnostic-only values are the redacted trace id, evidence hash, error class, validation
reasons, raw provider status, and run id; invoice/voucher identifiers come from the safe summary
fields on Voucher Detail or from the customer claim. Do not copy anything from raw logs, raw SOAP/XML,
gateway settings, or database/config files.

```
KuickPay payment escalation
---------------------------
Invoice ID:           <invoice-id>
Voucher status:       <status>            (NOTE: only "posted" = paid)
Consumer Number:      <consumer-number>
Registration Number:  <registration-number>
KuickPay reference:   <kuickpay-reference>
Amount / currency:    <amount> / PKR
Paid-at (provider):   <paid-at>
Error class:          <error-class-label>
Validation reason(s): <validation-reason-labels>
Redacted trace id:    <redacted-trace-id>
Evidence hash:        <evidence-hash>
Reconciliation run:   <run-id>
Symptom:              <one line: what the customer reports vs what the voucher shows>

Do NOT attach: raw SOAP/XML, WSDL host, credentials, Institution ID, or customer PII.
```

---

## 6. Quick reference — error classes and validation reasons

These appear (as **labels**, never raw tokens) in Diagnostics and help explain *why* a voucher is
where it is.

**Error classes:** Timeout, Transport Error, Credential Error, Malformed Response, Unknown Status,
Amount Mismatch, Duplicate Reference, Unmatched Reference, Posting Failed, Reconcile Exception.

**Validation reasons:** Currency mismatch, Amount mismatch, Unmatched reference, Invoice mismatch,
Stale voucher, Duplicate reference, Late payment, Missing paid date, Existing transaction mismatch,
Existing transaction partially applied, Existing transaction apply failed, Existing transaction
unverified, Missing expected context, Underpayment, Overpayment, Unknown status.

(For what these mean operationally and how the voucher is routed, see `reconciliation-runbook.md` §6.)

---

## 7. Honest-reporting notes (NFR12)

- Every filter key, status label, customer label, detail box, diagnostic field, error-class label,
  validation-reason label, and the redaction boundary above was cross-checked against the shipped
  source at baseline `e6e49190` — specifically `KuickPayVoucherListPresenter.php` (filter/diagnostic
  allowlists), `controllers/admin_vouchers.php` and `views/default/admin_vouchers_detail.pdt` (the
  detail boxes and the posted-only transaction link), `language/en_us/admin_vouchers.php`
  (admin/status/error/validation labels), the gateway `language/en_us/kuickpay.php` (customer status
  labels and the standing notice), and `KuickPayEvidence.php` / `KuickPayRedactor.php` (safe getters
  vs redaction boundary).
- This guide describes shipped behavior only; it does not assert any live lookup or payment was made
  from this document.

## See also

- `docs/kuickpay/reconciliation-runbook.md` — the reconciliation engine, run summaries, and the
  manual-review queue / action matrix referenced above.
- `docs/kuickpay/deployment-guide.md` — install, enable, and gateway settings.
- `docs/kuickpay/rollback-runbook.md` — disabling KuickPay while keeping evidence readable.
- `docs/kuickpay/production-launch-checklist.md` — first-week monitoring and Manual Review launch
  gates.
- `docs/kuickpay/blesta-footguns.md` — developer-facing framework traps behind the operator-visible
  behavior.
- `docs/kuickpay/live-smoke-runbook.md` — the opt-in credentialed real-provider smoke (Story 5.7),
  the model for never surfacing raw faults/endpoints.
- `docs/kuickpay/testing-fixtures.md` — fixture provenance and fail-closed parser evidence for
  inquiry and bulk cases.
