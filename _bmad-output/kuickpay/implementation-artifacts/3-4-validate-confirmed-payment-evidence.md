---
baseline_commit: a55dac93f5d8486a1a1c088fd5a33644cca5bf4f
---

# Story 3.4: Validate Confirmed Payment Evidence

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a finance operator,
I want confirmed KuickPay evidence validated before posting,
so that wrong amounts, references, invoice mappings, or duplicates cannot pay an invoice.

## Acceptance Criteria

**Epic 3.4 BDD (verbatim) [Source: epics.md:643-652]:**

> **Given** parser evidence indicates payment confirmation
> **When** validation runs
> **Then** amount, currency, Consumer Number, Registration Number, invoice mapping, Voucher state, and duplicate transaction references are checked.
>
> **Given** validation finds amount mismatch, duplicate reference, unmatched reference, stale Voucher, or invoice mismatch
> **When** the result is recorded
> **Then** the Voucher moves to retry or Manual Review as appropriate
> **And** no Blesta transaction is created.

**Expanded, testable acceptance criteria:**

1. **AC1 — A reusable, DB-aware validation gate exists.** A single, reusable validation component (`KuickPayEvidenceValidator`, plugin `lib/`) takes a confirmed-payment evidence object (parser `status === 'confirmed_unposted'`), the durable Voucher row, its invoice-link rows, and live Blesta invoice state, and returns a deterministic pass/fail result with a machine-readable reason. The same component is callable by reconciliation (this story), posting (3.5), and bulk (3.7) so there is exactly **one validation path**. [Source: architecture.md:111 "one validation path", :588 posting "verify … again"]

2. **AC2 — Confirmed evidence only reaches `confirmed_unposted` after the gate passes.** In `KuickPayReconcileService::persistEvidence()`, when the parser yields `confirmed_unposted`, the Voucher transitions to `confirmed_unposted` **only if** the validation gate passes. If the gate fails, the Voucher transitions to `manual_review` instead and is **never** left in `confirmed_unposted`. All non-confirmed parser outcomes (pending / retry / expired / failed / manual_review) keep their existing 3.3 behavior unchanged. [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:197-207; epics.md:651-652]

3. **AC3 — Amount + currency are re-validated against durable + live state in minor units.** The gate confirms `evidence.amount()` equals the Voucher's stored `amount`, equals the sum of the Voucher's invoice-link allocations, and is fully covered by the live outstanding balance of the mapped invoice(s); and that `evidence.currency()` equals the Voucher `currency` equals each mapped invoice `currency` (PKR). All amount comparisons use integer minor units or normalized decimal strings — **never PHP floats**. A mismatch fails the gate. [Source: architecture.md:593, :658; epics.md:111 (NFR13); epics.md:647,649]

4. **AC4 — Reference identity is re-asserted under the single-identity contract.** The gate confirms `evidence.registrationNumber()` equals the Voucher `registration_number` (the field the single inquiry validated). It MUST NOT reject confirmed single-inquiry evidence merely because `evidence.consumerNumber()` is `null` (single-`BillPaymentInquiry` evidence always carries a null Consumer Number). A non-null Consumer Number that disagrees with the Voucher fails the gate. [Source: KuickPayResponseParser.php inquiryEvidence (consumer_number always null on inquiry); kuickpay-parser-single-identity-contract memory; 3-3 story lines 142-146]

5. **AC5 — Invoice mapping is validated against live Blesta state.** For every linked invoice the gate loads live Blesta invoice state and confirms: the invoice exists, belongs to the Voucher's `client_id`, is `status === 'active'` (not `void`/`draft`/`proforma`), still has outstanding balance ≥ the link allocation, and is in the Voucher currency. Any deviation (missing, void, already paid, wrong client, currency drift, allocation exceeds balance) fails the gate as an invoice mismatch. [Source: app/models/invoices.php:2498 get(), :3650 `due = total-IFNULL(paid,0)`, :1484/:1521 "active && paid==0"; epics.md:647,649]

6. **AC6 — Stale Voucher is detected.** The gate fails if the Voucher is no longer in a confirm-eligible state (anything other than `pending`/`retry` at decision time), already carries a `blesta_transaction_id`, or another Voucher for the same invoice(s) is already `confirmed_unposted` or `posted` (superseded). [Source: epics.md:649 "stale Voucher"; architecture.md:601-602 state matrix]

7. **AC7 — Duplicate transaction reference is detected.** The gate fails if the confirmed `kuickpay_reference` is already held by a **different** Voucher in the same company in state `confirmed_unposted` or `posted` (cross-Voucher replay/duplicate). The check is company-scoped and excludes the Voucher under validation. [Source: epics.md:647 "duplicate transaction references", :649 "duplicate reference"; architecture.md:579 "duplicate_reference … must never fall through to posting"]

8. **AC8 — Failure routing + recording.** A failed gate routes the Voucher to `manual_review` (domain/evidence failures are non-transient; the transient `retry` path remains owned by 3.3 transport handling). The outcome is recorded durably: the reconciliation **item** row's `new_status`, the Voucher `diagnostic_summary` (redacted reason code in `validation_errors`), and an audit event — `evidence.matched` on pass, `evidence.rejected` on fail (reusing the existing 3.3 audit wiring). Reason codes are carried in `validation_errors`/audit payload, not by inventing new top-level `error_class` enum values. [Source: KuickPayReconcileService.php:269-275; architecture.md:569-577 allowed error classes, :623-634 audit events]

9. **AC9 — No posting, no invoice/transaction mutation.** This story creates/applies **no** Blesta transaction, sets **no** `posted` state, calls **no** `KuickPayPostingService`, and mutates **no** invoice or `transactions` row. Live Blesta invoice/transaction reads are **read-only**. A passing gate leaves the Voucher at `confirmed_unposted` with captured evidence; posting under row locks is Story 3.5. [Source: architecture.md:583 "Only KuickPayPostingService may create/apply the Blesta transaction", :650-661 anti-patterns; epics.md:652,654-669; 3-3 story lines 33,72,109]

10. **AC10 — Verification is honest and fixture-backed.** New/updated component tests cover: pass → `confirmed_unposted`; each failure class (amount mismatch vs invoice, currency drift, unmatched reference, null-consumer-number does NOT fail, invoice void/paid/missing/wrong-client, stale Voucher, duplicate reference) → `manual_review` + `evidence.rejected` + no posting. Run with the component PHPUnit runner; if `php`/`ext-soap` is unavailable here, say so and run under PHP 8.2 before merge. [Source: project-context.md:73-74,81; epics.md FR28 line 79; 3-3 story Task on testing]

## Tasks / Subtasks

- [x] **Task 1 — Create `KuickPayEvidenceValidator` (the one validation path) (AC: 1,3,4,5,6,7)**
  - [x] Add `plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php`. No namespace; legacy global class style matching the other plugin `lib/` classes (e.g. `KuickPayReconcileService`, `KuickPayVoucherRepository`). **The loader path is context-sensitive:** the plugin-root file loads lib classes as `Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayEvidenceValidator.php')`, but the service's `loadRuntimeDependencies()` already sets `$plugin_dir = dirname(__FILE__)` to `…/lib` and loads siblings as `Loader::load($plugin_dir . DS . 'KuickPayVoucherRepository.php')` — so when you wire the validator in (Task 3) use the **service-context** form, never a second `'lib'` segment (which fatals as `lib/lib/…`).
  - [x] Constructor takes an injectable `array $dependencies = []` (mirror `KuickPayReconcileService::__construct`, including its `if (empty($dependencies)) { $this->loadRuntimeDependencies(); }` guard so defaults resolve) with: `voucher_repository` (for duplicate-reference + sibling-invoice-voucher lookups), `voucher_invoices` model (sibling lookup by invoice), and an `invoice_reader`. Create `plugins/kuickpay_reconcile/lib/KuickPayInvoiceReader.php` as the explicit thin wrapper (**not** an inline closure): it exposes `get(int $invoice_id): ?stdClass` by `Loader::loadModels($this, ['Invoices'])` then returning `$this->Invoices->get($invoice_id) ?: null`. Defaults instantiate the real collaborators; tests inject fakes returning canned invoice stdClass objects.
  - [x] Public method `validate(stdClass $voucher, array $invoiceLinks, KuickPayEvidence $evidence): KuickPayValidationResult`.
  - [x] Add a small immutable result value object `KuickPayValidationResult` (plugin `lib/`) with `isValid(): bool`, `reasons(): array`, and `outcomeStatus(): string` (`confirmed_unposted` on pass, `manual_review` on fail). `reasons()` returns **bare machine codes only** — fixed constants like `amount_mismatch`, **never** interpolated values (an actual amount, a Consumer/Registration Number, or a `kuickpay_reference`); these codes land in `diagnostic_summary.validation_errors` and may reach the audit surface, so any interpolation/PII would violate the redaction rule (architecture.md:634). Match the immutable-value-object style of `KuickPayEvidence`. Reuse-ready for 3.5/3.7.
  - [x] Implement checks in this order, **failing closed** (collect all reasons; any reason ⇒ invalid):
    - [x] **Currency** — `evidence.currency()` === `voucher->currency` === each mapped invoice currency, all `=== 'PKR'`. Reason `currency_mismatch`.
    - [x] **Amount** — minor-unit equality of `evidence.amount()` vs `voucher->amount` vs `sum(invoiceLinks[].amount)`. Reason `amount_mismatch`. Add a private `toMinorUnits(string): int` helper (parse `^\d+(?:\.\d{1,2})?$` → integer cents; pad fraction to 2). **Never** cast money to `float` (epics.md:111 NFR13 / architecture.md:593,658).
    - [x] **Reference identity (single-identity contract)** — `(string) evidence.registrationNumber()` === `(string) voucher->registration_number`. If `evidence.consumerNumber()` is non-null, it must === `voucher->consumer_number`; a `null` Consumer Number is valid for inquiry evidence and must NOT fail. Reason `unmatched_reference`. [Source: kuickpay-parser-single-identity-contract memory; 3-3 story:142]
    - [x] **Invoice mapping (live Blesta read, read-only)** — fail closed with `invoice_mismatch` if `invoiceLinks` is empty (no mapping to validate — don't let this fall through to the amount-sum check, which yields a less diagnostic reason). Otherwise for each link: load invoice via the injected reader; require non-false, `status === 'active'`, `client_id === voucher->client_id`, currency match, and outstanding `due` (`total - paid`) ≥ link allocation (minor units). Reason `invoice_mismatch`.
    - [x] **Stale Voucher** — `voucher->status ∈ {pending,retry}`, `blesta_transaction_id` empty, and for each mapped invoice **no other** Voucher (excluding this id, same `company_id`) is already `confirmed_unposted`/`posted`. Use the new company-scoped `KuickPayVoucherRepository::findActiveByInvoiceId($invoice_id, $company_id, $excludeVoucherId)` (Task 2) — **not** the raw `KuickpayVoucherInvoices::getByInvoiceId`, which returns link rows (not vouchers), is not company-scoped, and does not exclude self. Reason `stale_voucher`.
    - [x] **Duplicate transaction reference** — the needle is the **freshly parsed `(string) evidence->reference()`**, not `voucher->kuickpay_reference` (that column is still empty on a `pending`/`retry` voucher). Company-scoped lookup of another Voucher (≠ this id) holding the same reference in `{confirmed_unposted, posted}` via `findActiveByKuickpayReference()`; fail closed (`duplicate_reference`) if the confirmed reference is null/empty. Reason `duplicate_reference`.
  - [x] Keep the validator pure of side effects: it reads and decides; it does NOT write Voucher/invoice/transaction rows, does NOT create transactions, and does NOT emit audit events (the caller records the outcome).

- [x] **Task 2 — Add the duplicate-reference + sibling-voucher repository lookups (AC: 6,7)**
  - [x] In `KuickPayVoucherRepository` (+ `KuickpayVouchers` model) add `findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0): ?stdClass` selecting a Voucher with matching `kuickpay_reference`, `company_id`, `status IN ('confirmed_unposted','posted')`, `id != excludeVoucherId`, **and `kuickpay_reference IS NOT NULL AND kuickpay_reference != ''`** (guard against empty-reference false positives). Use the established `Record` builder + company scoping already in the model (mirror `getByConsumerNumber`'s `->select()->from()->where()…->fetch()` shape); do not write raw SQL where the model uses the builder.
  - [x] Add a company-scoped sibling-by-invoice finder `findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0): ?stdClass` (repository + model): join `kuickpay_voucher_invoices` to `kuickpay_vouchers` on `voucher_id`, filter `invoice_id`, `company_id`, `status IN ('confirmed_unposted','posted')`, `id != excludeVoucherId`, return the first match (one query — no per-sibling N+1). The repository has **no** generic `get($voucher_id)` today, so this dedicated finder is the clean path; do not reach into `KuickpayVouchers::get()` from the validator, and do not use the bare `KuickpayVoucherInvoices::getByInvoiceId()` (link rows only, not company-scoped, no self-exclusion).
  - [x] No new columns are required (the Voucher table already has `kuickpay_reference`, `blesta_transaction_id`, `client_id`, `status` enum, `currency`, `amount`). **Do not** add a schema migration unless a reason code genuinely needs a new persisted column — if so, bump `config.json` `version` 1.1.0 → 1.2.0 and follow the `upgrade($current_version)` + `columnExists()` guard pattern in `kuickpay_reconcile_plugin.php`. Default expectation: **no migration**.

- [x] **Task 3 — Wire the gate into `KuickPayReconcileService::persistEvidence()` (AC: 2,8,9)**
  - [x] Add `evidence_validator` to the service DI array (constructor `?? new KuickPayEvidenceValidator()`, mirroring the existing `?? new …` defaults at lines 33-40) and load the new classes in `loadRuntimeDependencies()` alongside the other sibling loads (lines 364-369) using the **in-`lib/` form**: `Loader::load($plugin_dir . DS . 'KuickPayEvidenceValidator.php');`, `Loader::load($plugin_dir . DS . 'KuickPayValidationResult.php');`, `Loader::load($plugin_dir . DS . 'KuickPayInvoiceReader.php');` — **not** the `dirname(__FILE__) . DS . 'lib' . DS . …` plugin-root form (inside the service that resolves to `lib/lib/…` and fatals).
  - [x] **Re-read the voucher at decision time (AC6).** `processVoucher` selected `$voucher` from the batch *before* the SOAP call (line 120), so validate against a fresh row, not the snapshot: `$freshData = KuickPayVoucherRepository::getWithInvoices((int) $voucher->id)` (returns `['voucher'=>…,'invoices'=>…]`, or `null` if the voucher vanished), then **destructure explicitly**: `$freshVoucher = $freshData['voucher']` and `$invoiceLinks = $freshData['invoices']`. If `$freshData` is `null`, or the fresh `voucher->status` is no longer `pending`/`retry`, **fail closed WITHOUT calling `validate()`**: set **both** `$new_status = 'manual_review'` and `$vars['status'] = 'manual_review'`, `unset($vars['amount'], $vars['date_paid'], $vars['kuickpay_reference'])`, and still record the reason on this `$result`-less path by merging the **literal** `['stale_voucher']` into `diagnostic_summary.validation_errors` (same decode→merge→encode shown below — `$result` does not exist on this early-bail path, so use the literal, not `$result->reasons()`; otherwise this failure mode transitions to `manual_review` with no diagnostic trail). Never pass `null`/the stale snapshot into `validate()`. Note `getWithInvoices()` → `KuickpayVouchers::get()` is **not** company-scoped, so assert the fresh `company_id` matches before trusting the row.
  - [x] In `persistEvidence()`, inside the `if ($evidence->isConfirmedUnposted())` block (lines 197-201), call `$result = $this->evidenceValidator->validate($freshVoucher, $invoiceLinks, $evidence)` and branch on **`$result->isValid()`**:
    - On **pass** (`$result->isValid()` true): keep `$new_status = 'confirmed_unposted'`; set `amount`/`date_paid`/`kuickpay_reference` exactly as today.
    - On **fail** (`$result->isValid()` false): reassign **both** `$new_status = 'manual_review'` **and** `$vars['status'] = 'manual_review'` — the persisted value is `$vars['status']`, frozen from the pass-path at line 185, so reassigning `$new_status` alone still writes `confirmed_unposted` to the row (see the seam note in Dev Notes). Then `unset($vars['amount'], $vars['date_paid'], $vars['kuickpay_reference'])` so a rejected voucher carries no confirmed-payment fields. **The validator never writes top-level `error_class`** — leave `error_class` exactly as the parser set it (which is `null` on the confirmed path); carry **all** validator reasons (including ones that happen to match an allowed enum like `amount_mismatch`, and the DB-only `invoice_mismatch`/`stale_voucher`/`currency_mismatch`) only in `validation_errors`. This avoids inventing new enum values and removes any ambiguity about when the validator "should" set `error_class`.
  - [x] **Merge the validator reason codes into the existing `diagnostic_summary` JSON** (it is already a string at line 190, built from evidence only — decode → append → re-encode):
    ```php
    $diag = json_decode($vars['diagnostic_summary'], true) ?: [];
    $diag['validation_errors'] = array_values(array_unique(array_merge(
        $diag['validation_errors'] ?? [],
        $result->reasons()
    )));
    $vars['diagnostic_summary'] = json_encode($diag);
    ```
    Do not string-concatenate (invalid JSON) and do not overwrite (drops the parser's errors). On the early-bail stale path (the fresh-read bullet above) `$result` does not exist — merge the literal `['stale_voucher']` here instead of `$result->reasons()`.
  - [x] Ensure the final `$new_status` flows through to the existing item-row write (`processVoucher` line 130-139) and `recordEvidenceAudit` (line 141), so a rejected confirmation is recorded as an item `new_status='manual_review'` and emits `evidence.rejected`; a passing confirmation still emits `evidence.matched`. Do not duplicate audit events.
  - [x] Re-affirm the boundary comment at line 203: this story validates but still **never** posts; posting/row-locks are 3.5. Keep the "never call `KuickPayPostingService` / never create a transaction / never touch invoices write-side" guarantee from 3.3 intact.

- [x] **Task 4 — Tests (AC: 10)**
  - [x] **Fix the pre-existing happy-path test first — it WILL regress the moment the gate is wired.** `testPaidExactInquiryTransitionsToConfirmedUnpostedWithoutPosting` (test lines 9-32) is the **only** test that asserts `confirmed_unposted`; it seeds a voucher with **no invoice links**. (`testFixtureBackedStateMappings`'s `fixtureMappingProvider` has **no** `confirmed_unposted` rows — only pending/expired/`manual_review` — so it does **not** regress; do not go hunting there.) Once the gate loads links + reads invoices, that paid-exact test routes to `manual_review` (and the fake repo, which lacks `getWithInvoices()`, errors first). Update it to seed a valid invoice link and inject an invoice-reader fake returning a matching `active` PKR invoice (`due ≥ allocation`, `client_id` = voucher) so the happy path still lands on `confirmed_unposted`. A red pre-existing test here is expected — not a regression to mis-diagnose.
  - [x] Extend `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php` fakes: add an invoice-reader fake, and extend `KuickPayReconcileFakeVoucherRepository` with `getWithInvoices()` / the new `findActiveByKuickpayReference()` / `findActiveByInvoiceId()` so the confirmed path can load links + resolve siblings. (The current fake repo only implements `getReconcilable`/`edit` at lines 198-221 and stores **bare voucher objects with no invoice links** — so it must also gain an **invoice-link store** (constructor arg or setter mapping `voucher_id → link rows`); without it, `getWithInvoices()`/`findActiveByInvoiceId()` cannot return links or find siblings.) Inject the assembled fake validator (or a real validator built from these fakes) into the service via the **`evidence_validator`** DI key — the service constructor takes `evidence_validator`, **not** `invoice_reader` (the invoice-reader fake belongs to the validator's own DI, not the service array).
  - [x] Add a focused `KuickPayEvidenceValidatorTest.php` (plugin `tests/`) unit-testing the validator in isolation with fakes for each reason class — this is the cleanest place to prove minor-unit amount math, the null-consumer-number acceptance, and each fail reason. Also assert `KuickPayValidationResult::reasons()` returns only bare codes (no interpolated amounts/identifiers), so nothing PII-bearing can reach `diagnostic_summary`/audit.
  - [x] Cover end-to-end through the service: paid-exact fixture + valid invoice ⇒ `confirmed_unposted` + `evidence.matched`; and each failure (amount vs invoice, currency drift, unmatched reference, invoice void/paid/missing/wrong-client, stale voucher, duplicate reference) ⇒ `manual_review` + `evidence.rejected` + no posting fields/`posted`.
  - [x] Reuse existing fixtures under `tests/fixtures/kuickpay/valid/` (e.g. `bill-payment-inquiry-paid-exact.xml`, `bill-payment-inquiry-paid-trailing-zero.xml` for the precision trap) — do NOT add live KuickPay calls.
  - [x] Run `php -l` on every changed PHP file. The 3.4 tests are **plugin** tests — run them with `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`; run the gateway suite (`cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`) only if you touch gateway parser files. **NOT** `-c build/phpunit.xml` (broken runner, project-context.md:74). This checkout has PHP 8.3.31 + `ext-soap` + the phpunit-8.5 runner present, so the plugin suite runs directly here; if `php`/`ext-soap` is ever unavailable, state that and run under PHP 8.2 + PHPUnit ~8.5 before merge; never claim a suite that did not run.

## Dev Notes

### TL;DR — what this story actually adds (read this first)

The parser (3.2) and reconcile service (3.3) **already** validate amount, currency, and Registration-Number identity **against the expected context** and fail closed to `manual_review` on mismatch. **That is evidence-internal validation — it has no database.** This story adds the checks the parser *cannot* do because it only sees one response: **invoice-mapping correctness against live Blesta state, cross-Voucher duplicate-reference de-dup, and Voucher staleness** — then 3.5 posts under row locks with a final re-validation. This layering is exactly why 3.3 setting `confirmed_unposted` is safe: nothing pays an invoice until 3.4 validates and 3.5 posts. [Source: 3-3 story lines 33,144-146; architecture.md:111,588]

**The single most important integration fact:** today `KuickPayReconcileService::persistEvidence()` transitions a Voucher to `confirmed_unposted` **with zero domain validation** (it blindly trusts `$evidence->isConfirmedUnposted()` and writes `amount`/`date_paid`/`kuickpay_reference`, lines 197-201). Your job is to insert the validation gate at that exact point so only fully-validated confirmations reach `confirmed_unposted`; rejected ones go to `manual_review`. [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:197-207]

### The exact integration seam (quote)

```php
// KuickPayReconcileService::persistEvidence()  (current)
$new_status = $this->mappedStatus(...);              // line 182 -> 'confirmed_unposted' for confirmed evidence
$vars = [
    'status' => $new_status,                          // line 185  ⚠️ $vars['status'] is FROZEN here, BEFORE the gate
    // ...
    'diagnostic_summary' => $this->diagnosticSummary($evidence),  // line 190  built from EVIDENCE ONLY, before the gate
];
if ($evidence->isConfirmedUnposted()) {               // line 197  <-- your gate runs inside this if
    $vars['amount'] = $evidence->amount();
    $vars['date_paid'] = $this->paidDate($evidence);
    $vars['kuickpay_reference'] = $evidence->reference();
}
$this->voucherRepository->edit((int) $voucher->id, $company_id, $vars);  // line 204  persists $vars (NOT $new_status)
return $new_status;                                   // line 206  drives the item row + audit only
```

`$new_status` is computed earlier by `mappedStatus()` (line 182, returns `'confirmed_unposted'` for confirmed evidence). Your gate runs inside the `if`. **Two load-bearing facts about this seam — both are easy to get wrong and either one silently defeats the story:**

1. **`$vars['status']` is the value actually persisted, and it is frozen at line 185 *before* your gate.** The DB write at line 204 uses `$vars`; the `return $new_status` at line 206 only drives the downstream item-row + audit. So on gate **fail** you MUST reassign **both** `$new_status = 'manual_review'` **and** `$vars['status'] = 'manual_review'`, then `unset($vars['amount'], $vars['date_paid'], $vars['kuickpay_reference'])`. Reassigning the local `$new_status` alone leaves `status = 'confirmed_unposted'` in the voucher row — a rejected payment that Story 3.5 would then post, with the item-row/audit disagreeing. This is the exact failure mode this story exists to prevent.

2. **`diagnostic_summary` is already a JSON string at line 190, built from `$evidence` only** (private `diagnosticSummary()`, lines 226-236, which encodes `$evidence->validationErrors()`). It has no access to the `KuickPayValidationResult`. To land the validator's reason codes you must `json_decode($vars['diagnostic_summary'], true)`, append `$result->reasons()` into `validation_errors` (merge + unique, **don't** replace), and `json_encode` back into `$vars['diagnostic_summary']`. Do not string-concatenate (invalid JSON) and do not overwrite (drops the parser's own errors).

The downstream item-row write (`processVoucher` lines 130-139) and `recordEvidenceAudit` (lines 247-276) already branch on `$new_status` to emit `evidence.matched` vs `evidence.rejected` — let the corrected `$new_status` flow through; **don't** add a second audit call. [Source: KuickPayReconcileService.php:120-163,179-276,226-236]

### Reusable validator design — "one validation path"

Architecture mandates exactly one validation path reused across scheduled/manual/bulk reconciliation and posting (architecture.md:111), and 3.5 posting must "verify status, amount, currency, reference, invoice mapping, and idempotency **again**" (architecture.md:588) — the word *again* presumes a first pass, which is this story. Build a standalone, side-effect-free `KuickPayEvidenceValidator` returning a `KuickPayValidationResult`, so 3.5 (`KuickPayPostingService`) and 3.7 (bulk) call the identical logic. Do **not** bury the rules inside `persistEvidence()` where 3.5 cannot reuse them. The architecture's suggested-service list (architecture.md:547) is explicitly non-exhaustive ("parser/validator" appears as a component at architecture.md:61), so a dedicated validator class is in-pattern.

### The evidence contract you consume (3.2 output — immutable value object)

`KuickPayEvidence` getters: `status()`, `errorClass()`, `reference()`, `consumerNumber()`, `registrationNumber()`, `amount()`, `currency()`, `paidAt()`, `rawStatus()`, `redactedTraceId()`, `evidenceHash()`, `validationErrors()`; helper `isConfirmedUnposted()`. [Source: KuickPayEvidence.php; 3-3 story:159]
- `status()` ∈ `{pending, retry, confirmed_unposted, failed, expired, manual_review}`. `'confirmed_unposted'` is the **only** confirmed-payment status; detect it via `isConfirmedUnposted()`.
- `amount()` is a **canonical decimal string** with exactly 2 fraction digits (e.g. `"1000.00"`) — never a float. `null` if unparsed. [Source: KuickPayResponseParser.php normalizeAmount]
- `currency()` is an **uppercase** string (e.g. `"PKR"`) or `null`.
- `paidAt()` is `"Y-m-d"` (date granularity, no time) or `null`. The service already converts it via `paidDate()` to `"Y-m-d 00:00:00"` (line 238-245).
- `consumerNumber()` is **always `null` on `BillPaymentInquiry` evidence** (the single-inquiry path 3.3 uses) — see the single-identity trap below. `registrationNumber()` carries field `[1]` of the inquiry result.
- `evidenceHash()` is a 24-char SHA-256 prefix over (operation|rawStatus|reference|consumer|registration|amount|currency|paidAt) — deterministic, redaction-safe; useful for replay detection but **per-response**, so it is NOT a durable cross-voucher key. Use the durable `kuickpay_reference` for cross-Voucher duplicate detection.

### ⚠️ Single-identity contract trap (do not regress)

A single `BillPaymentInquiry` validates exactly **one** identity field — `field[1]` (Registration Number). The parser already fails closed to `manual_review`/`unmatched_reference` if you pass *both* `expected_registration_number` and a differing `expected_consumer_number` (there is a regression guard test for this: `testSupplyingBothIdentityKeysFailsClosedRegressionGuard`, test file line 130; the service deliberately passes only `expected_registration_number`, KuickPayReconcileService.php:170-177). **In the validator:** assert `evidence.registrationNumber()` vs `voucher->registration_number`. Because inquiry evidence has a **null** `consumerNumber()`, you must treat null as "not provided / OK" — do NOT require the Consumer Number to match on the inquiry path, or you will reject every legitimately-paid voucher. [Source: kuickpay-parser-single-identity-contract memory; 3-3 story:142-146; test line 130-144]

### Amount/currency math — minor units, never floats (NFR13)

Project rule + architecture: "Amounts must be compared using normalized decimal strings or integer minor units, never PHP floats. Currency must be part of every validation check." (architecture.md:593; epics.md:111 NFR13; anti-pattern architecture.md:658). Persisted money is stored as `varchar(20)` decimal strings (`kuickpay_vouchers.amount`, `kuickpay_voucher_invoices.amount`), validated `^\d+(?:\.\d{1,2})?$`. **Trap:** the Voucher amount may have 1 fraction digit while evidence has 2 (`"1000.0"` vs `"1000.00"`), and Blesta's invoice `due`/`total`/`paid` are **floats** (app/models/invoices.php:2456 `round($total,$precision)`). Normalize everything to integer minor units (cents) before comparing: parse the decimal string to int cents; for Blesta floats format to 2dp string first (`number_format($due, 2, '.', '')`) then to cents. Use the `bill-payment-inquiry-paid-trailing-zero.xml` fixture to lock the precision behavior.

### Live Blesta invoice integration — this is the plugin's FIRST invoice read

The `kuickpay_reconcile` plugin does **not** currently load Blesta's `Invoices`/`Transactions` models anywhere (confirmed by grep). You are introducing the first live-invoice read. Read-only.
- Load via Blesta loader: `Loader::loadModels($this, ['Invoices'])` then `$this->Invoices->get($invoice_id)`. [Source: app/models/invoices.php:2498]
- `Invoices::get()` returns a stdClass with `id`, `client_id`, `status` (`'active'|'draft'|'proforma'|'void'`), `currency`, `total`, `paid`, computed `due` = `total - IFNULL(paid,0)` (invoices.php:3650), `date_due`, line items, applied transactions, etc.
- Blesta's own "open/payable" test is `status === 'active' && paid == 0` (invoices.php:1484,1521). For partial-safety use `due > 0` and require `due ≥ allocation` (minor units).
- Keep all of this **read-only**. Creating/applying a transaction or editing an invoice is forbidden here and is centralized in `KuickPayPostingService` (3.5) per architecture.md:583,650-652.
- To keep the validator unit-testable without Blesta's `Loader`, inject an `invoice_reader` collaborator (default wraps `Invoices`); tests pass a fake returning canned invoice stdClass objects.

### Outcome → state / audit / error_class mapping

| Gate result | Voucher `status` | Audit event | Persisted detail |
|---|---|---|---|
| pass (all checks ok) | `confirmed_unposted` | `evidence.matched` (already emitted, KuickPayReconcileService.php:269-270) | `amount`, `date_paid`, `kuickpay_reference`, `evidence_hash`, `diagnostic_summary` |
| fail (any reason) | `manual_review` | `evidence.rejected` (already emitted, line 273-274) | reason codes in `diagnostic_summary.validation_errors`; `error_class` unchanged from the parser (null on the confirmed path) — the validator never writes it |

- AC2's "retry or Manual Review as appropriate": **retry** is the transient-provider path already owned by 3.3 (timeout/transport → `retry`, KuickPayReconcileService.php:215-216). **Domain/evidence validation failures here are non-transient → `manual_review`.** Do not route invoice/amount/duplicate/stale failures to `retry`.
- **Do not invent new `error_class` enum values.** Architecture enumerates the allowed set (architecture.md:569-577): `timeout, transport_error, credential_error, malformed_response, unknown_status, amount_mismatch, duplicate_reference, unmatched_reference`. `amount_mismatch`/`duplicate_reference`/`unmatched_reference` already fit three of your reasons. For DB-only reasons (`invoice_mismatch`, `stale_voucher`, `currency_mismatch`) carry the code in `diagnostic_summary.validation_errors[]` and the audit `payload`, leaving top-level `error_class` either null or the closest allowed value — changing the enum is a documented architecture change (architecture.md:646) and out of scope here. If the parser already set an allowed `error_class` (e.g. `amount_mismatch`), preserve that top-level value and only append the validator's reason codes to `validation_errors`; never overwrite it.
- Audit payloads must use redacted fields only (architecture.md:634); the existing `recordEvidenceAudit` payload (prior_status/new_status/error_class) is already redaction-safe. AC8's reason-code requirement is satisfied **durably** by `diagnostic_summary.validation_errors` (the `/audit payload` in AC8 reads as either-surface); extending the audit `payload` with the reason codes is **optional** — if you do, add only redaction-safe machine codes (never raw evidence/PII) and do **not** add a second audit call.
- `diagnostic_summary.status` records the **evidence** classification, not the voucher state: on a gate-rejected confirmation it will legitimately read `confirmed_unposted` while the voucher `status` column and audit `new_status` read `manual_review` (the merge rewrites only `validation_errors`, never `$diag['status']`). Do **not** "helpfully" overwrite `diagnostic_summary.status` — it preserves the "provider said paid" signal for Epic-4 diagnostics; downstream consumers read the voucher `status` **column** for state, never `diagnostic_summary.status`.

### Boundary — what is explicitly OUT of scope

❌ Do NOT implement here (belongs to later stories): creating/applying any Blesta transaction; setting `posted`; calling/creating `KuickPayPostingService`; row-locked posting (Story **3.5**); expiry/late/partial/overpayment policy beyond what the parser already classifies (Story **3.6**); date-based bulk reconciliation / `BillPaymentBulkInquiry` consumption (Story **3.7**); admin "Check Now" buttons, Voucher list/detail, audit/diagnostics **views** (Epic **4** / Story **4.5**). The architecture file tree also lists `KuickPayVoucherNormalizer`, `KuickPaySchema`, `KuickPayVoucherStates` — those are not this story; do not create them. [Source: architecture.md:716-723; 3-3 story:109,248]

### Previous-story intelligence (3.3 + 3.2 + 2.x)

- **3.3 (reconcile, done):** Established the cron/manual reconcile loop, DB lock (`kuickpay_reconcile_locks`, always released in a finally-path), batch+cursor+runtime bounds, `RETRY_LIMIT=5`, `PENDING_RECHECK_MINUTES=30`, exponential backoff, and the `persistEvidence()`/`mappedStatus()` state mapping you are extending. It **hardened `KuickpayVouchers::edit()` to be company-scoped** (`edit(int $voucher_id, int $company_id, array $vars)`, drops `company_id` from `$vars`) — your repository additions must keep that company scoping. 3.3 deliberately stopped at `confirmed_unposted` and pointed at 3.4 for exactly this gate. [Source: 3-3 story:33,42-43,72,144-146; KuickPayReconcileService.php]
- **3.3 deferred items you should be aware of (don't reintroduce, optionally improve):** per-voucher writes (voucher edit + item + audit) are **not** wrapped in a single DB transaction (3-3 story:336) — a mid-voucher crash can leave a partial trail that self-heals next run; your added validation read happens *before* the write, so it does not worsen this, but be aware. Per-voucher exceptions record an item row but no audit event (deferred to 4.5). [Source: 3-3 story:333-340; deferred-work.md]
- **3.2 (parser, done):** Owns the evidence contract + the context-based amount/currency/reference validation. Reuse its output; do not re-parse SOAP/XML — product logic consumes normalized evidence only (architecture.md:551, anti-pattern :653-654).
- **2.1/2.2 (vouchers, done):** Voucher schema + reference service. Class casing is load-bearing: `KuickpayReconcilePlugin`, model `KuickpayVouchers`, `KuickpayVoucherInvoices`. Unique keys already exist: `uniq(company_id, consumer_number)`, `uniq(company_id, registration_number)`, `uniq(voucher_id, invoice_id)`. There is **no** unique key on `kuickpay_reference` — which is exactly why the cross-Voucher duplicate check (AC7) is a query, not a constraint. [Source: 3-3 story:164,234; kuickpay_reconcile_plugin.php schema]

### Git intelligence (recent, relevant)

Last commits are all 3.3 reconciliation work: `feat(kuickpay_reconcile): add pending voucher reconciliation`, `test(...): cover pending voucher reconciliation`, then three review fixes — `scope voucher reconciliation updates` (company-scoped `edit()`), `always release reconcile lock on failure`, `isolate voucher errors from the batch`. Takeaways for 3.4: (1) company-scoping of mutations is enforced and reviewed — keep it; (2) per-voucher error isolation is a hard expectation — your validator/invoice-read must not let one voucher's exception abort the batch (the existing `try/catch` in `processVoucher` already isolates, lines 144-160 — keep your new reads inside it); (3) commits are narrow and conventional (`<type>(<scope>): <summary>`, lowercase, <72 chars). [Source: `git log`; project-context.md:101-103]

### Project Structure Notes

- New files (plugin-owned, `plugins/kuickpay_reconcile/lib/`): `KuickPayEvidenceValidator.php`, `KuickPayValidationResult.php`, `KuickPayInvoiceReader.php` (thin read-only wrapper over Blesta's `Invoices` model). All align with the architecture component list ("parser/validator", architecture.md:61) and the plugin-owns-validation/posting ownership rule (architecture.md:305,331,669). Validation/posting belong to the plugin, never the gateway (architecture.md:521-526).
- Modified files: `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php` (wire the gate into `persistEvidence`, add DI), `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` + `models/kuickpay_vouchers.php` (add **both** `findActiveByKuickpayReference` **and** `findActiveByInvoiceId`, repository + model layer), tests under `plugins/kuickpay_reconcile/tests/`.
- No new schema expected (Voucher/invoice-link/audit/run/item tables already carry every field needed — see 3-3 story:164 and the agent-confirmed schema). Only bump `config.json` to 1.2.0 + add an `upgrade()` migration **if** you discover a genuinely-needed persisted column; default is no migration.
- Preserve PHP 8.2 + legacy global-class style of each file (no namespaces in plugin `lib/`/models; match surrounding type-hint style). Strings via language files; validation/persistence via models/services; `Loader`/`Input`/`Record`/transactions per project-context.md:39-66.
- Detected variance / decision: the epics file phrases 3.4 as "validation … checks amount, currency, Consumer Number, Registration Number, …" — but on the single-inquiry path Consumer Number is null in evidence and is validated indirectly via Registration Number identity (single-identity contract). The Consumer-Number column match becomes meaningful on the **bulk** path (3.7), where evidence carries `consumer_number`. Build the validator to check Consumer Number **only when present** so it is correct for both paths without regressing the inquiry path.

### Testing Standards

- Component-local PHPUnit (~8.5) in the plugin/gateway test tree. Run via `--bootstrap tests/bootstrap.php tests` (NOT the broken `-c build/phpunit.xml`, project-context.md:74). External runner at `/root/tools/phpunit-8.5/vendor/bin/phpunit` if installed. No new root `tests/`. No live KuickPay calls — inject the fake client + fake invoice reader and use existing inquiry fixtures. `php -l` every changed PHP file. State exactly what ran; if `php`/`ext-soap` is absent here, run under PHP 8.2 before merge and say so. [Source: project-context.md:69-81; 3-3 story:243]
- Test seam: `KuickPayReconcileServiceTest::service()` (test file:146-162) builds the service from a DI array of fakes — add the **`evidence_validator`** fake there. The service constructor takes `evidence_validator`, **not** `invoice_reader` (the invoice-reader fake belongs to the **validator's** own DI, so wire it into the validator you inject, not the service array — adding `invoice_reader` to the service array is silently ignored). The existing `KuickPayReconcileFakeVoucherRepository` (test file:198-221) only implements `getReconcilable`/`edit` and stores bare voucher objects; extend it with `getWithInvoices()`, `findActiveByKuickpayReference()`, and `findActiveByInvoiceId()` (plus an invoice-link store) for the confirmed path. Prefer a dedicated `KuickPayEvidenceValidatorTest` for the pure-logic reason matrix.
- Must-have negative tests (each ⇒ `manual_review` + `evidence.rejected` + assert no `posted`/no transaction write): amount-vs-invoice mismatch, currency drift, unmatched Registration Number, **null Consumer Number does NOT reject** (positive guard), invoice void / already-paid / missing / wrong-client, stale Voucher (sibling already posted; or `blesta_transaction_id` set), duplicate `kuickpay_reference`. Plus the happy path ⇒ `confirmed_unposted` + `evidence.matched` with `amount`/`date_paid`/`kuickpay_reference` set.

### References

- [Source: epics.md:637-652] — Story 3.4 user story + BDD acceptance criteria
- [Source: epics.md] — build order: 3-3 → 3-4 → 3-5; [epics.md:654-705] — 3.5/3.6/3.7 boundaries
- [Source: epics.md FR19 line 61, FR17 line 57, NFR9 line 103, NFR13 line 111, NFR2 line 89, NFR4 line 93] — pre-posting validation, fail-closed, no-float amounts, no-corruption, auditability
- [Source: architecture.md:41,48-50] — separate evidence from posting; fail closed; posting requires amount/invoice/duplicate checks
- [Source: architecture.md:111] — "one validation path" for scheduled/manual/bulk reconciliation
- [Source: architecture.md:549-579] — Parser & Evidence Contract; allowed error classes; "duplicate_reference/unmatched_reference … must never fall through to posting"
- [Source: architecture.md:581-593] — Posting Contract: re-validate amount/currency/reference/invoice mapping/idempotency **again**; minor-units not floats
- [Source: architecture.md:597-606] — UI display-state matrix (`confirmed_unposted` = "Validated evidence, ready to post"; forbidden: direct transaction)
- [Source: architecture.md:623-634,650-661] — audit event names; anti-patterns (no transaction outside `KuickPayPostingService`, no float amounts, no force-paid)
- [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:120-276] — `processVoucher`/`persistEvidence`/`mappedStatus`/`recordEvidenceAudit` — the seam to extend
- [Source: plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php; models/kuickpay_vouchers.php; models/kuickpay_voucher_invoices.php] — repository/model APIs, company-scoped `edit()`, unique keys, `getByInvoiceId`/`getWithInvoices`
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php + KuickPayResponseParser.php] — evidence getters, status/error-class constants, amount/date normalization, single-identity validation
- [Source: app/models/invoices.php:2498 (get), :3650 (due=total-paid), :1484/:1521 (active && paid==0), :2456 (float rounding)] — Blesta invoice read contract
- [Source: 3-3 story 3-3-reconcile-pending-vouchers-by-single-inquiry.md:33,72,109,142-146,164,234,333-340] — explicit 3.4 deferral of the validation gate; single-identity rationale; schema inventory; deferred items
- [Source: memory kuickpay-parser-single-identity-contract] — single inquiry validates ONE field; never pass both expected reg + consumer
- [Source: project-context.md:39-66,69-81,101-103,118-130] — PHP 8.2 + Blesta loader/Input/Record/transaction/language conventions; testing rules; commit style; untrusted-cron-payload validation

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` — passing, 31 tests / 103 assertions.
- `php -l` on every changed PHP file — passing.
- Runtime available here: PHP 8.3.31 CLI with `ext-soap`; PHP 8.2 was not the active CLI.

### Completion Notes List

- Added reusable side-effect-free `KuickPayEvidenceValidator` plus `KuickPayValidationResult` and read-only `KuickPayInvoiceReader`.
- Added company-scoped active Voucher lookups for duplicate KuickPay references and sibling invoice vouchers; no schema migration was needed.
- Wired confirmed evidence persistence through a fresh voucher/invoice-link read and validation gate; failures route to `manual_review`, merge redacted reason codes into `diagnostic_summary.validation_errors`, and avoid confirmed-payment fields.
- Added validator, repository, and service tests covering pass, failure reason classes, stale fresh-read handling, item/audit status flow, and no posting fields on rejected confirmation.

### File List

- plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php
- plugins/kuickpay_reconcile/lib/KuickPayInvoiceReader.php
- plugins/kuickpay_reconcile/lib/KuickPayValidationResult.php
- plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
- plugins/kuickpay_reconcile/models/kuickpay_vouchers.php
- plugins/kuickpay_reconcile/tests/KuickPayEvidenceValidatorTest.php
- plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php
- plugins/kuickpay_reconcile/tests/KuickPayVoucherRepositoryTest.php
- plugins/kuickpay_reconcile/tests/bootstrap.php
- _bmad-output/kuickpay/implementation-artifacts/3-4-validate-confirmed-payment-evidence.md
- _bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml

### Change Log

- 2026-06-10 — Implemented confirmed payment evidence validation gate and moved story to review.
- 2026-06-10 — Code review (3 adversarial layers): all 10 ACs met; 1 patch applied (validator amount-math coverage), 3 items deferred, ~14 dismissed as noise/false-positive. Status → done.

### Review Findings

_Code review 2026-06-10 — Blind Hunter + Edge Case Hunter + Acceptance Auditor (all layers completed). Verified independently: `php -l` clean on all 9 changed files; plugin suite green (34 tests / 109 assertions after the patch below). Acceptance Auditor verdict: AC1–AC10 all **Met**._

**Patch (applied this review):**

- [x] [Review][Patch] Validator amount-math coverage gap — multi-invoice-link summation (AC3 `sum(links)` equality) and the trailing-zero precision trap were untested at the validator level (`bill-payment-inquiry-paid-trailing-zero.xml` was only exercised by the gateway parser test). Added 3 unit tests (multi-link sum pass, multi-link sum mismatch → `amount_mismatch`, trailing-zero minor-unit equality). [plugins/kuickpay_reconcile/tests/KuickPayEvidenceValidatorTest.php] — fixed in commit `test(kuickpay_reconcile): cover multi-invoice sum and trailing-zero math`.

**Deferred (real, but not actionable in 3.4 scope):**

- [x] [Review][Defer] Confirmed evidence with a null/malformed paid date passes the gate → voucher persists `confirmed_unposted` with `date_paid = NULL`. Parser reaches `STATUS_CONFIRMED_UNPOSTED` without validating `fields[2]` (the date), and the validator never checks `paidAt()`. Paid date is not in the AC3–AC7 check-list, and the root allowance is in the 3.2 parser. **Must be resolved before 3.5 posting** (a Blesta transaction needs a paid date). [components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php:504; plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:220] — deferred to Story 3.5.
- [x] [Review][Defer] Same invoice can be allocated by two distinct **pending** vouchers — `findActiveByInvoiceId` only matches `confirmed_unposted`/`posted` siblings, and `invoiceMatches` compares the full invoice `due` (not reduced by in-flight unposted links). No money moves in 3.4; the real guard is 3.5 row-locked posting + final re-validation. [plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php voucherIsFresh/invoiceMatches] — deferred to Story 3.5.
- [x] [Review][Defer] Consumer-number format consistency on the **bulk** path (3.7) — voucher `consumer_number` stores an `INSTITUTION_ID`-prefixed value; when bulk evidence carries a non-null `consumer_number`, the strict equality in `referenceMatches()` could route a legitimately-paid voucher to `manual_review`. Correct/skipped for 3.4 (inquiry consumer is always null). [plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php referenceMatches] — verify when Story 3.7 consumes bulk evidence.
- [x] [Review][Defer] AC10 PHP 8.2 verification gate outstanding — only PHP 8.3.31 and 7.4.33 are present on this host (no 8.2 binary). Code is 8.2-syntax-compatible (no 8.3+ syntax; verified). Re-run the plugin suite under PHP 8.2 before merge per the story's own AC10 gate. — pre-merge action.

**Dismissed (false positives / handled / out-of-scope by design):** `!= null` SQL "never matches" — refuted, Record converts to `IS NOT NULL` (vendors/minphp/record/src/Record.php:1117); duplicate `invoice_id` double-counts `linkSum` — prevented by `uniq(voucher_id, invoice_id)` (kuickpay_reconcile_plugin.php:82); `mergeValidationErrors` silent JSON loss — input is always well-formed JSON from a single internal producer; pending-vs-pending reference race — `kuickpay_reference` is empty on pending vouchers + sequential batch + reconcile DB lock; currency-check redundancy — consistent, maintainability only; fail-closed path lacks defensive `unset` — payment keys only set in the pass branch (structural no-op); negative `due` → generic reason — fails closed; zero-amount / integer-overflow amounts — not reachable from DB-bounded invoice totals; `invoiceDueMinorUnits` total−paid fallback — dead branch for real Blesta reads; test sentinel `[]` collision — cosmetic, fail-closed behavior covered via `null`.
