---
baseline_commit: 91819026ea0658080eb433c9de38d347978895ff
---

# Story 3.5: Post Safe Blesta Transactions

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a finance operator,
I want safely confirmed KuickPay payments posted through Blesta's normal transaction path,
so that invoices are paid only after validated evidence and duplicate checks.

## Acceptance Criteria

> Epic source (BDD, [Source: _bmad-output/planning-artifacts/epics.md#Story-3.5]):
> - **Given** a Voucher has validated confirmed evidence **When** `KuickPayPostingService` posts payment **Then** it re-reads and locks or compare-updates the Voucher, revalidates amount/reference/invoice state, creates/applies the Blesta transaction, stores the transaction ID, and transitions the Voucher to `posted`.
> - **Given** posting fails after confirmation evidence exists **When** the transaction rolls back **Then** the Voucher does not become `posted` **And** confirmation evidence remains available for retry or Manual Review.

The following testable criteria expand the two BDD scenarios above. Each is mapped to tasks below.

1. **AC1 — Posting boundary & service home.** A new class `KuickPayPostingService` is created at `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php`. Within **KuickPay-owned runtime code** (the `kuickpay` gateway and the `kuickpay_reconcile` plugin — their controllers, services, models, views, and tests) it is the **only** class that calls Blesta `Transactions->add()` / `Transactions->apply()` or otherwise marks an invoice paid. (Core Blesta flows that legitimately record/apply transactions — e.g. `admin_clients`, `services`, `client_services` — are out of scope and untouched; do not add a grep gate that trips on them.) Posting MUST NOT occur in the gateway `buildProcess()`, `validate()`, `success()`, any KuickPay controller, `.pdt` view, or the reconcile/validation path. [Source: architecture.md#Posting-Contract; architecture.md#Anti-Patterns "Calling markPaid, recordPayment, … or transaction creation outside KuickPayPostingService"]

2. **AC2 — Eligible input only.** The service posts only a Voucher whose row currently has `status = 'confirmed_unposted'`, `blesta_transaction_id IS NULL`, a non-empty `kuickpay_reference`, `currency = 'PKR'`, a valid decimal-string `amount`, and a non-empty/valid `date_paid`. Any voucher not meeting all of these is skipped (no transaction) and, where its state is internally inconsistent, transitioned to `manual_review` with a redacted reason. [Source: architecture.md#Data-Architecture; deferred-work.md (3-4 paid-date item)]

3. **AC3 — Locked re-read + re-validation inside the posting transaction.** Posting opens a DB transaction (`begin()`), acquires a row lock on **both** the target voucher **and its `kuickpay_voucher_invoices` mapping rows** (`SELECT ... FOR UPDATE`) — architecture mandates locking the Voucher *and* invoice-mapping rows (architecture.md:353) — then re-verifies **status, amount, currency, reference identity, invoice mapping, and idempotency again** against live state before any money moves, using the locked rows. If any check fails: create **no** Blesta transaction, transition the voucher to `manual_review` (failure reason codes merged into `diagnostic_summary`, mirroring `KuickPayReconcileService::persistEvidence()`'s `mergeValidationErrors`), roll back/commit so no partial transaction rows survive, and write a `posting.failed` audit event. [Source: architecture.md#Posting-Contract — "verify ... again"; architecture.md:353]

4. **AC4 — Create & apply the Blesta transaction.** On successful re-validation the service creates a Blesta transaction via `Transactions->add()` with: `client_id`, `amount`, `currency` (all from the voucher), `type='other'`, `gateway_id` (from the voucher), `transaction_id` = the voucher's `kuickpay_reference`, `status='approved'`, `message` = a language-file key (short, redacted, e.g. "KuickPay payment posted" — never raw SOAP/credentials/PII), `reference_id` left null (the KuickPay reference lives in `transaction_id`). For `date_added`, record the **posting time** — omit it (Blesta defaults to now) or pass `date('Y-m-d H:i:s')`; do **not** back-date to `date_paid` or attempt a UTC conversion of the timezone-less stored value. It then applies the transaction to the mapped invoice(s) via `Transactions->apply($id, ['amounts' => [...], 'date' => $voucher->date_paid])` using the locked `kuickpay_voucher_invoices` allocations (`invoice_id` + per-row `amount`); `date_paid` is already stored as `'Y-m-d H:i:s'`, which is the format `apply()` expects. [Source: app/models/transactions.php; plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php]

5. **AC5 — Persist `posted` state atomically.** After the Blesta transaction + apply succeed, and **within the same DB transaction**, the service stores `blesta_transaction_id` (the new transaction id), sets `status = 'posted'`, sets `date_posted = NOW()`, writes a `posting.succeeded` audit event, then `commit()`s. The voucher reaches `posted` only after the Blesta transaction succeeds. [Source: architecture.md#Posting-Contract; architecture.md#Data-Architecture lines 339-353]

6. **AC6 — Failure rolls back (epic scenario 2).** If `Transactions->add()`/`apply()` errors, or persisting the posted state fails, the DB transaction is rolled back: the voucher does **not** become `posted`, `blesta_transaction_id` stays `NULL`, the voucher remains `confirmed_unposted` (confirmation evidence preserved for retry/Manual Review), and a `posting.failed` audit event is recorded. No invoice is left partially paid by an orphaned transaction. [Source: epics.md#Story-3.5 scenario 2; deferred-work.md line 68]

7. **AC7 — Two-layer idempotency / no double-post.** (a) The row lock + `status='confirmed_unposted'` + `blesta_transaction_id IS NULL` guard makes concurrent or repeated posting of the same voucher a no-op. (b) Before `add()`, the service calls `Transactions->getByTransactionId($kuickpay_reference, $client_id, $gateway_id)`; if a matching transaction already exists, it does **not** blindly mark the voucher `posted`. It first verifies the existing transaction is safe to adopt — `status='approved'`, matching client/gateway/amount/currency, **and already applied to exactly the mapped invoice allocation(s)** (check the applied rows, e.g. `Transactions->getApplied($id)`; if approved-but-unapplied, apply it to the mapped invoices within this same transaction and check `Transactions->errors()`). Only then does it link the id and transition to `posted`. A mismatched / non-approved / wrong-invoice / partially-applied existing transaction routes the voucher to `manual_review` with `posting.failed` — never `posted`. Re-running the posting pass never creates a second transaction for the same reference. [Source: epics.md:91 (NFR3 idempotency); architecture.md:349,351,353,526; app/models/transactions.php#getByTransactionId/#getApplied]

8. **AC8 — Missing/invalid paid date fails closed.** A `confirmed_unposted` voucher with `date_paid` null/empty/malformed is **not** posted; it is routed to `manual_review` (reason recorded, `posting.failed` audit). This resolves the 3-4 deferral that a confirmed voucher can persist with `date_paid = NULL`. [Source: deferred-work.md line 81 — "Must be resolved before Story 3.5 posting"]

9. **AC9 — Double-allocation blocked under the lock.** Re-validation under the row lock re-checks that each mapped invoice is still `active`, belongs to the voucher's client, is PKR, and its live remaining balance (`due`) still covers the allocation, and that no sibling voucher is already `confirmed_unposted`/`posted` for the same invoice. Two outcomes, both fail-closed: (a) **asymmetric** — one voucher already `posted` reduced the invoice `due`, so the second fails `invoiceMatches` → `manual_review`; (b) **symmetric** — two vouchers *both* `confirmed_unposted` on one invoice each see the other as an active sibling via `findActiveByInvoiceId`, so **both** route to `manual_review` (no double-pay). Neither path posts twice. [Source: deferred-work.md line 82 — deferred to 3.5; KuickPayEvidenceValidator.php voucherIsFresh/findActiveByInvoiceId]

10. **AC10 — Exact-amount, fully-covered cases only.** Only payments whose evidence amount equals the voucher amount and whose voucher amount equals the sum of invoice-link amounts are posted. Underpayment, overpayment, late-after-expiry, and partial cases are **out of scope here** (Story 3.6) and must never be force-posted by this service. [Source: epics.md#Story-3.6; architecture.md#Anti-Patterns "force paid"]

11. **AC11 — Amount/currency safety.** The posting service's own amount comparisons use normalized decimal strings or integer minor units, never PHP floats; currency (`PKR`) is part of every check. (Note: the reused `KuickPayEvidenceValidator::invoiceDueMinorUnits()` and Blesta core `Transactions::apply()` cast to float internally for the live-`due` read and the applied-amount increment — pre-existing, shipped behavior, safe within double precision for realistic PKR invoice totals; do not refactor them in this story.) [Source: epics.md:111 (NFR13); architecture.md:593; architecture.md:658 (anti-pattern: "Using PHP floats for amount matching")]

12. **AC12 — Bounded, locked trigger.** A dedicated plugin cron task `post_confirmed` drives the posting pass for the company with a DB-backed lock (separate from `reconcile_pending`), bounded batch size, max runtime, and no double-posting on rerun. It is registered idempotently through the plugin install/upgrade path (plugin version bump + `getCronTasks()` entry + `cron($key)` dispatch). [Source: architecture.md#Infrastructure-Deployment; architecture.md#Anti-Patterns "Cron posting without row locks"]

13. **AC13 — Audit & redaction.** `posting.started`, `posting.succeeded`, and `posting.failed` audit events are written via `KuickPayAuditService` with redacted payloads only (ids/hashes/status, no secrets, no raw SOAP/XML, no PII). [Source: architecture.md#Audit-and-Logging-Patterns]

14. **AC14 — No regressions.** Reconcile cron (3-3) and evidence validation (3-4) behavior is preserved. Any extension to `KuickPayEvidenceValidator` (see Dev Notes) MUST default to existing behavior so all current 3-4 callers and tests pass unchanged. [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php; KuickPayEvidenceValidator.php]

15. **AC15 — Verification.** Tests cover: happy-path posting; idempotent re-post (already `posted`); duplicate-transaction short-circuit via `getByTransactionId`; each re-validation failure → `manual_review` with no transaction; rollback when the transaction/apply fails; and the missing-paid-date guard. Tests use the existing fake-injection pattern (no live SOAP, no live DB writes mocked through fakes). Run with the external PHPUnit 8.5 runner; `php -l` all changed files. Do not claim root PHPUnit coverage unless sibling `../tests` exists. [Source: project-context.md Testing Rules; deferred-work.md]

## Tasks / Subtasks

- [x] **Task 1 — Resolve the paid-date prerequisite (AC8)** — fail closed when confirmed evidence lacks a usable paid date.
  - [x] Decide the enforcement point: simplest is a guard at the start of posting (`date_paid` empty/`'0000-00-00...'`/unparseable → route to `manual_review`, audit `posting.failed`, no transaction). Optionally also tighten the 3-4 confirmed path, but do NOT change 3-4 test expectations without updating them.
  - [x] Add a language key for the safe customer/admin label if any string surfaces; reason codes stay machine-readable (e.g. `missing_paid_date`).

- [x] **Task 2 — Extend the validator for the `confirmed_unposted` posting state (AC3, AC9, AC14)** — without breaking 3-4.
  - [x] In `KuickPayEvidenceValidator::validate()`, add an optional trailing parameter `array $allowedStatuses = ['pending', 'retry']` and thread it into `voucherIsFresh()` (replace the hard-coded `['pending','retry']` membership test with `$allowedStatuses`). Default value preserves all existing reconcile-time behavior.
  - [x] Posting calls `validate($freshVoucher, $invoiceLinks, $evidence, ['confirmed_unposted'])`. Confirm the other sub-checks already behave correctly at posting time: `referenceIsUnique` and `findActiveByInvoiceId` exclude the current voucher id (sibling/duplicate detection still correct); `amountMatches`/`currencyMatches`/`invoiceMatches` re-verify live invoice state.
  - [x] Update/extend `KuickPayEvidenceValidatorTest.php` to cover the new `confirmed_unposted` allowed-status path AND assert the default still rejects `confirmed_unposted` as `stale_voucher` (regression guard).

- [x] **Task 3 — Build `KuickPayPostingService` (AC1–AC7, AC10, AC11, AC13)** at `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php`.

  Posting unit at a glance (the normative sequence is the numbered steps below):
  ```
  postVoucher(company_id, voucher):
    1. paid-date guard (AC8)                      → manual_review if missing/invalid
    2. begin()                                    (shared PDO covers Transactions + voucher)
    3. SELECT ... FOR UPDATE voucher row + its voucher_invoices rows (AC3)
    4. idempotency/state guard                    → commit + no-op if not postable (AC7a)
    5. reconstruct evidence + validate(['confirmed_unposted']) (AC3/AC9) → manual_review on fail
    6. getByTransactionId → verify approved+applied before adopting (AC7b)
    7. posting.started; Transactions->add() + errors() check (AC4)
    8. Transactions->apply() + errors() check (AC4)
    9. edit voucher → posted, blesta_transaction_id, date_posted (AC5)
    10. posting.succeeded; commit()   |  any failure → rollBack + posting.failed (AC6)
  ```
  - [x] Define class constants mirroring `KuickPayReconcileService`: `BATCH_SIZE = 100`, `MAX_RUNTIME_SECONDS = 240`, and a distinct lock name `post_confirmed`.
  - [x] Constructor mirrors existing services: `__construct(array $dependencies = [])` with `loadRuntimeDependencies()` when empty; inject `voucher_repository`, `evidence_validator`, `audit_service`, `lock_repository`, plus the Blesta `Transactions` model (load via `Loader::loadModels($this, ['Transactions'])` in `loadRuntimeDependencies()`, matching `KuickPayReconcileService`) via a seam so tests can fake them. Follow the DI shape in `KuickPayReconcileService` / `KuickPayEvidenceValidator`.
  - [x] `postConfirmed(int $company_id): array` — acquire the `post_confirmed` DB lock (skip with `lock_held` if not acquired), bounded batch: fetch up to `BATCH_SIZE` postable vouchers via `getPostable($company_id, $limit, $afterId)` (Task 4), iterate with a `MAX_RUNTIME_SECONDS` guard, call `postVoucher()` per voucher, accumulate a counts summary, release the lock in `finally`. Return shape: `['status' => 'completed'|'skipped'|'aborted', 'counts' => ['processed','posted','already_posted','skipped','manual_review','failed','errors']]`.
  - [x] `postVoucher(int $company_id, $voucher): array` — the safe posting unit. Return shape: `['voucher_id' => int, 'outcome' => 'posted'|'already_posted'|'skipped'|'manual_review'|'failed', 'blesta_transaction_id' => ?int]`.
    1. Run the Task 1 paid-date guard.
    2. `Record->begin()` (use the voucher model's Record — all models share one PDO connection, so this boundary also covers `Transactions->add/apply`). **Inside this transaction, use only plain UPDATE/SELECT (the model `edit()` and raw `query()`); never call a method that self-manages `begin()/commit()` — see the Dev Notes footgun warning.**
    3. Row-lock + re-read **the voucher AND its invoice-mapping rows** inside the open transaction: `Record->query('SELECT * FROM kuickpay_vouchers WHERE id = ? AND company_id = ? FOR UPDATE', $voucher->id, $company_id)->fetch()`, and lock the links via a `kuickpay_voucher_invoices ... WHERE voucher_id = ? FOR UPDATE` read (add a repo/model locked-read, e.g. `getInvoiceLinksForUpdate($voucher_id)`). All subsequent re-validation/allocation uses these locked rows.
    4. Idempotency/state guard: if missing, `company_id` mismatch, `status !== 'confirmed_unposted'`, or `blesta_transaction_id` already set → `commit()` and return `already_posted`/`skipped`. Do not create a transaction.
    5. Reconstruct a `KuickPayEvidence` from the stored confirmed fields and call `validator->validate($lockedVoucher, $lockedLinks, $evidence, ['confirmed_unposted'])` (Task 2). **Constructor field map** (`KuickPayEvidence` is positional — map every arg): `status='confirmed_unposted'`, `error_class=null`, `reference=$voucher->kuickpay_reference`, `consumer_number=$voucher->consumer_number`, `registration_number=$voucher->registration_number`, `amount=$voucher->amount`, `currency=$voucher->currency`, `paid_at=substr($voucher->date_paid,0,10)`, `raw_status=null`, `redacted_trace_id=''` (validator does not read it; a placeholder is fine), `evidence_hash=$voucher->evidence_hash`, `validation_errors=[]`. Do **not** use `KuickPayValidationResult::outcomeStatus()` for the success transition — on a pass it returns `'confirmed_unposted'` (the current state, not `'posted'`); branch on `isValid()` and set `posted`/`manual_review` explicitly. On failure → set `status='manual_review'`, merge `$result->reasons()` into `diagnostic_summary` (reuse the `mergeValidationErrors` pattern from `KuickPayReconcileService`), optionally set `error_class='posting_failed'`, `commit()`, audit `posting.failed`, return `manual_review`.
    6. Duplicate-transaction check: `Transactions->getByTransactionId($kuickpay_reference, $client_id, $gateway_id)`. If found, **verify before adopting** (AC7b): it must be `approved`, client/gateway/amount/currency-matched, and already applied to exactly the mapped allocation(s) (`Transactions->getApplied($id)`); if approved-but-unapplied, apply it now within this transaction and check `Transactions->errors()`. On a clean verify/apply → set `blesta_transaction_id`, `status='posted'`, `date_posted`, audit `posting.succeeded`, `commit()`, return `posted`. On any mismatch/partial/non-approved → `status='manual_review'` (reason → `diagnostic_summary`), `commit()`, audit `posting.failed`, return `manual_review`. **Never** mark `posted` from a found-but-unverified transaction.
    7. **Mandatory** `posting.started` audit (every attempt that reaches money movement — both the new-transaction and adopt-existing branches). `Transactions->add([...])` (fields per AC4); check `Transactions->errors()` (note: a `Transactions.addBefore` event listener can veto the add, yielding null id + errors) → on error `rollBack()`, audit `posting.failed`, return `failed` (voucher stays `confirmed_unposted`).
    8. `Transactions->apply($transaction_id, ['amounts' => $allocations, 'date' => $voucher->date_paid])`; check errors → `rollBack()`, audit `posting.failed`, return `failed`.
    9. Update voucher: `status='posted'`, `blesta_transaction_id=$transaction_id`, `date_posted=NOW()` via `voucher_repository->edit($id, $company_id, $vars)` (a plain `Record->update()` — safe inside the open transaction).
    10. `posting.succeeded` audit. `commit()`. Return `posted`.
  - [x] Wrap the whole unit in `try/catch (Throwable)`: on throw, `rollBack()` first, then a best-effort `posting.failed` audit on a fresh write **after** the rollback (`try { … } catch (Throwable) { /* swallow */ }`, mirroring `KuickPayReconcileService`'s `finally`/best-effort audit) so it survives the rollback and one voucher's failure cannot abort the rest of the batch.

- [x] **Task 4 — Repository/model support (AC2, AC9)**.
  - [x] Add `KuickpayVouchers::getPostable(int $company_id, int $limit, int $afterId = 0)` selecting `status='confirmed_unposted' AND currency='PKR' AND blesta_transaction_id IS NULL AND date_paid IS NOT NULL` ordered by id, plus a `KuickPayVoucherRepository::getPostable(...)` passthrough (mirror `getReconcilable`). The `date_paid IS NOT NULL` filter MUST live in the query (it is the primary gate); Task 1's paid-date guard is defense-in-depth for a row that slips through (e.g. a reconcile/posting race) — keep **both** layers.
  - [x] Add a locked invoice-link read for the posting transaction (e.g. `KuickPayVoucherRepository::getInvoiceLinksForUpdate(int $voucher_id)` → model method issuing `SELECT ... FROM kuickpay_voucher_invoices WHERE voucher_id = ? FOR UPDATE`). The existing `getByVoucherId()` is an unlocked read and is fine for batch selection, but the re-validation/apply inside the posting transaction must use the locked rows (AC3, architecture.md:353).
  - [x] Confirm `KuickPayVoucherRepository::edit()` is sufficient for the locked write flow (it is a plain `where()->update()` — safe inside `begin()/commit()`); do not introduce a non-canonical interim status — the 8 enum states are fixed.

- [x] **Task 5 — Register the `post_confirmed` cron trigger (AC12)** in `kuickpay_reconcile_plugin.php`.
  - [x] Add a `post_confirmed` entry to `getCronTasks()` (interval; choose a sensible cadence ≥ the 5-min reconcile, e.g. 5–15 min) with its own `key`/`name`/`description` language entries.
  - [x] Handle `post_confirmed` in `cron($key)`: load + run `KuickPayPostingService::postConfirmed((int) Configure::get('Blesta.company_id'))`.
  - [x] Bump `config.json` version to `1.2.0` and **restructure `upgrade()`** into sequential, idempotent version-gated blocks. The current method early-returns when `version_compare($current_version, '1.1.0', '>=')` — that guard would make the `1.2.0` work **unreachable** for any install already at `1.1.0` (the live upgrade case), silently never registering `post_confirmed` and failing AC12. Remove the top-level early return and replace with cumulative gates, e.g.:
    ```php
    if (!isset($this->Record)) { Loader::loadComponents($this, ['Input', 'Record']); }
    if (version_compare($current_version, '1.1.0', '<')) {
        $this->addVoucherEvidenceColumns(); $this->createReconcileTables(); $this->addCronTasks();
    }
    if (version_compare($current_version, '1.2.0', '<')) {
        $this->addCronTasks(); // idempotent — registers post_confirmed (getByKey guard skips existing)
    }
    ```
    `addCronTasks()` is already idempotent (`getByKey`/`getTaskRunByKey` guards), so re-running it is safe. Add a verification note: smoke-test a real `1.1.0 → 1.2.0` upgrade and confirm `post_confirmed` is registered and dispatchable.
  - [x] Use a distinct DB lock name (`post_confirmed`) via the existing `KuickPayReconcileLockRepository` — both locks coexist in the single `kuickpay_reconcile_locks` table keyed by `(company_id, lock_name)`, so the distinct names mean posting and reconciliation never contend. Honor TTL / stale-lock handling like the reconcile service.

- [x] **Task 6 — Language & docs (AC13)**.
  - [x] Add new strings to `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php`: the `post_confirmed` cron name/description, the `Transactions->add()` `message` text (e.g. `KuickpayReconcile.posting.transaction_message`), and any admin manual-review reason labels. Keep machine reason codes out of user copy.
  - [x] **Create** `plugins/kuickpay_reconcile/lib/README.md` (it does not exist yet) stating that `KuickPayPostingService` is the only class permitted to create/apply Blesta payments — this file is mandated by the architecture and this story is the natural owner since it introduces the service. [Source: architecture.md:906]

- [x] **Task 7 — Tests (AC15)** at `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php` (+ the validator test updates from Task 2).
  - [x] Reuse the fake-injection style from `KuickPayReconcileServiceTest`/`KuickPayEvidenceValidatorTest`: fake voucher repository (in-memory rows + invoice links), fake/stub `Transactions` (records `add`/`apply` calls, returns a fake id, can be forced to error, and lets the test seed `getByTransactionId`/`getApplied` return values), fake invoice reader, fake audit service (captures event names + payloads).
  - [x] Cases:
    - happy-path post (transaction created, applied, voucher→`posted`, `blesta_transaction_id` set, `date_posted` set, `posting.started` **and** `posting.succeeded` audited);
    - idempotent skip when already `posted` / `blesta_transaction_id` set → no `add`;
    - **existing-transaction verify-applied (AC7b):** (i) found + approved + matching + already applied to the mapped invoices → link, set `posted`, **no** duplicate `add`; (ii) found + approved but **unapplied** → applies within the same transaction, then `posted`; (iii) found but **non-approved / wrong amount / wrong currency / wrong invoice / partially applied** → `manual_review`, **never** `posted`;
    - each re-validation failure → `manual_review`, zero `add` calls;
    - `add()` error (incl. an `addBefore`-style veto returning null id + errors) → rollback, voucher stays `confirmed_unposted`, `posting.failed`;
    - `apply()` error → rollback, voucher stays `confirmed_unposted`;
    - missing/invalid `date_paid` → `manual_review`, no `add`;
    - double-allocation **asymmetric** (sibling already `posted`, invoice `due` reduced) → `manual_review`; double-allocation **symmetric** (two `confirmed_unposted` on one invoice) → **both** `manual_review`.
  - [x] Assert no audit payload or transaction `message` contains secrets/raw SOAP/PII; assert the posting service's own amount handling uses string/minor-unit comparisons; assert failure reasons land in `diagnostic_summary`.
  - [x] Run: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (NOT `-c build/phpunit.xml`). `php -l` every changed PHP file. Report exactly what ran; if `../tests`/DB unavailable, say so.

## Dev Notes

### What this story is (and is NOT)

This is the **posting boundary** — the single, centralized point where validated KuickPay evidence becomes a real Blesta payment. Story 3-3 reconciles pending vouchers and 3-4 validates confirmed evidence; **both deliberately stop short of posting**. The seam is explicit in code:

> `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:233` — `// Story 3.4 validates confirmed evidence only. Posting and invoice mutation belong to Story 3.5.`

`KuickPayPostingService.php` does **not exist yet** — you are creating it. After 3-4, a successfully validated voucher lands in `status = 'confirmed_unposted'` with `amount`, `date_paid`, and `kuickpay_reference` persisted (see `persistEvidence()` lines 199-231). That row is exactly your input.

NOT in scope (do not implement here): SOAP calls (3-3 owns them), bulk reconciliation (3-7), under/over/partial/late/expiry policy (3-6), admin "force paid" (forbidden in MVP), any customer-facing posting trigger.

### The voucher state machine (canonical — do not invent states)

`pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`. Defined in `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php:13-22` (enum) and `kuickpay_reconcile_plugin.php:45-49` (schema). Your transitions: `confirmed_unposted → posted` (success) and `confirmed_unposted → manual_review` (re-validation failure / missing paid date). **Only `posted` may imply a Blesta invoice payment succeeded.** [Source: architecture.md:349] Do NOT add a transient `posting` state — the enum is fixed.

### CRITICAL TRAP — do not blindly reuse the validator

`KuickPayEvidenceValidator::voucherIsFresh()` (`KuickPayEvidenceValidator.php:140-160`) returns `false` (→ `stale_voucher`) unless `voucher->status ∈ ['pending','retry']`. At posting time the voucher is `confirmed_unposted`, so calling `validate()` unchanged would **falsely reject every postable voucher**. Fix it the surgical way (Task 2): add an `array $allowedStatuses = ['pending','retry']` parameter defaulting to current behavior, and pass `['confirmed_unposted']` from posting. Do **not** widen the default or hard-code `confirmed_unposted` into the validator — that would let the reconcile path treat already-confirmed vouchers as fresh and re-process them. Add a regression test asserting the default still rejects `confirmed_unposted`.

The rest of the validator works correctly at posting time:
- `referenceIsUnique($evidence, $company_id, $voucher_id)` excludes the current voucher and flags any OTHER `confirmed_unposted`/`posted` voucher holding the same reference → real duplicate. ✔
- `voucherIsFresh` also checks `empty($voucher->blesta_transaction_id)` (good — fails if already linked) and sibling `findActiveByInvoiceId(...,$excludeVoucherId=$voucher_id)` → blocks double-allocation. ✔
- `amountMatches` re-checks evidence==voucher AND voucher==Σ(link amounts); `invoiceMatches` re-checks live invoice `active`/client/currency/`due ≥ link`. ✔ (all minor-unit integer math)

**Where the re-validation teeth actually are.** Because the posting-time `KuickPayEvidence` is *reconstructed from the stored voucher fields*, the evidence-vs-voucher checks (`amountMatches` first clause; the reference check) are reconstructed-vs-stored and **pass by construction** — they are not a fresh provider re-check (3.5 deliberately performs no SOAP re-fetch). The load-bearing re-checks at posting time are the **live** lookups the validator re-reads each call: `invoiceMatches` (live invoice `active`/client/currency/`due ≥ link`) and `referenceIsUnique`/`findActiveByInvoiceId` (live duplicate/sibling state). Point the Task 7 assertions at those live surfaces, not at the tautological amount/reference equality.

### Blesta transaction API (verified against `app/models/transactions.php`)

- `Transactions->add(array $vars): int|void` — fields: `client_id` (req), `amount` (req, decimal string ok), `currency` (req, 3-char), `type` ∈ `{cc,ach,other}` → use **`other`**, `gateway_id` (from voucher), `transaction_id` (gateway ref → use the voucher's `kuickpay_reference`; max 128), `reference_id` left **null** (the reference lives in `transaction_id`), `message` (≤255) → a **language-file key** (short, redacted; never raw SOAP/credentials/PII), `status` ∈ `{approved,declined,void,error,pending,refunded,returned}` → **`approved`**, `date_added` → the **posting time** (omit to let `add()` default to now, or pass `date('Y-m-d H:i:s')`; do **not** back-date to `date_paid` — it is timezone-less midnight and "converting it to UTC" is meaningless). Returns the new id, or `void` on validation error — always check `Transactions->errors()`. Note `add()` fires `Transactions.addBefore`/`addAfter` events; an `addBefore` listener can veto the insert (null id + errors), which the `errors()` check already handles. Use the voucher's `date_paid` only for `apply()`'s `date` (payment-received date; already `'Y-m-d H:i:s'`).
- `Transactions->apply($transaction_id, ['amounts' => [['invoice_id'=>X,'amount'=>Y], ...], 'date'=>'Y-m-d H:i:s'])` — `$transaction_id` is the internal `transactions.id` returned by `add()`. Only `approved` transactions count toward invoice paid totals; `apply()` auto-closes fully-paid invoices via `Invoices->setClosed()`. Build `amounts` from `kuickpay_voucher_invoices` rows (`invoice_id`, `amount`).
- `Transactions->getByTransactionId($transaction_id, $client_id = null, $gateway_id = null): stdClass|false` — duplicate-detection key; call before `add()` with `kuickpay_reference`, `client_id`, `gateway_id`. Returns the existing transaction or `false`.

The voucher carries every field you need: `client_id`, `gateway_id`, `currency` (`PKR`), `amount` (varchar(20) decimal string), `kuickpay_reference` (varchar(128)), `date_paid` (datetime, stored as `'Y-m-d 00:00:00'` by 3-3's `paidDate()`), plus the `kuickpay_voucher_invoices` links. `transactions.amount` is `decimal(12,4)`; pass the decimal string as-is.

### Transaction boundary + row locking (verified against core `Record`)

- All Blesta models share **one** PDO connection (singleton in `core/ServiceProviders/MinphpBridge.php` → `core/Database/Record.php`). So `$this->KuickpayVouchers->Record->begin()` opens a transaction that **also covers** `Transactions->add()`/`apply()` and the voucher `edit()`. Commit/rollback once around the whole unit.
- **FOOTGUN — nested transactions do not exist in Blesta `Record`/PDO.** `KuickPayVoucherRepository::create()` self-manages its own `begin()/commit()/rollBack()`. It is fine as a standalone caller, but it is **not** a precedent to mirror *inside* `postVoucher()`: if you call a self-transacting method (including `create()`) inside the open posting transaction, the inner `commit()` commits the **entire** outer transaction and releases the `FOR UPDATE` lock early, silently breaking the atomic boundary. Inside the posting transaction call only plain UPDATE/SELECT — the model `edit()` (a bare `where()->update()`, verified) and raw `Record->query()`.
- There is **no** `forUpdate()` builder method. Use raw SQL via `Record->query('SELECT ... FOR UPDATE', $id, $company_id)` (variadic positional binds) → `->fetch()`. Lock both the voucher row and its `kuickpay_voucher_invoices` rows. The locks are held until `commit()/rollBack()` on the shared connection.
- For a compare-and-update alternative, `Record->affectedRows()` after a `where(...)->update(...)` returns the affected count — but since the canonical state set has no interim "claim" status, prefer the `FOR UPDATE` re-read + guard. The architecture explicitly lists **"Cron posting without row locks"** as an anti-pattern (architecture.md:661), so a lock is mandatory.

### Idempotency — two independent layers

1. **Voucher row**: `FOR UPDATE` lock + `status='confirmed_unposted'` + `blesta_transaction_id IS NULL`. Repeated/concurrent posting of the same voucher becomes a no-op. The schema indexes `blesta_transaction_id` (`kuickpay_reconcile_plugin.php:72`) but does **not** make it unique — the runtime guard is the enforcement.
2. **Blesta transaction**: `getByTransactionId(kuickpay_reference, client_id, gateway_id)` before `add()` — net-new defense-in-depth against a **previously or externally committed** transaction for the same reference. (Within a single failed run this layer is rarely the thing that fires: the one outer `begin()/commit()` means a failed run's `add()` rolls back *with* the unit, so a "transaction committed but voucher update lost" state is essentially unreachable intra-run — Blesta `Record` has no nested transactions.) On a hit, **verify the found transaction is `approved` and already applied to the mapped invoices before adopting it** (see AC7b / Task 3 step 6); a found-but-unapplied/mismatched transaction must route to `manual_review`, never `posted`. (There is no posting-duplicate item in deferred-work.md — this guard is additive, not a deferred-item closure.)

### Audit (verified against `KuickPayAuditService`)

`KuickPayAuditService::record(string $eventName, array $context): void`. Context keys: `company_id` (req int), `voucher_id`, `run_id` (nullable), `redacted_trace_id`, `evidence_hash`, `payload` (array → json). Emit `posting.started`, `posting.succeeded`, `posting.failed` (lower-dot names per architecture.md:629-631). Payloads redacted only — ids/hashes/status, never secrets/raw SOAP/PII. Note: audit writes inside the posting transaction roll back with it; for `posting.failed` on rollback, write the audit on a fresh statement after `rollBack()` (best-effort, wrapped so it can't abort the batch — mirror the reconcile service's `finally`/best-effort audit handling).

### Deferred-work items this story MUST close

- **`date_paid` can be NULL on a confirmed voucher** — `deferred-work.md:81` ("Must be resolved before Story 3.5 posting"). → AC8 / Task 1.
- **Two pending vouchers can allocate the same invoice** — `deferred-work.md:82` (deferred to 3.5; "row-locked posting + final re-validation + idempotency"). → AC9 / Task 3 step 5 (live `due` re-check) + sibling check.
- **Per-voucher writes not transactional** — `deferred-work.md:68` (wrap multi-write ops). → AC5/AC6 / Task 3 (one `begin()/commit()/rollBack()` around add+apply+voucher-edit+audit).

### Source tree — files to create / touch

```
plugins/kuickpay_reconcile/
  lib/KuickPayPostingService.php            (NEW — the deliverable)
  lib/KuickPayEvidenceValidator.php         (UPDATE — add $allowedStatuses param; default-preserving)
  lib/KuickPayVoucherRepository.php         (UPDATE — add getPostable passthrough)
  models/kuickpay_vouchers.php              (UPDATE — add getPostable selector)
  kuickpay_reconcile_plugin.php             (UPDATE — post_confirmed cron + cron($key) + upgrade 1.2.0)
  config.json                               (UPDATE — version 1.1.0 -> 1.2.0)
  language/en_us/kuickpay_reconcile_plugin.php (UPDATE — cron + reason strings)
  tests/KuickPayPostingServiceTest.php      (NEW)
  tests/KuickPayEvidenceValidatorTest.php   (UPDATE — confirmed_unposted path + default regression)
  lib/README.md                             (NEW — posting-only-class note; mandated by architecture.md:906)
```
Reused as-is: `lib/KuickPayInvoiceReader.php`, `lib/KuickPayAuditService.php`/`KuickPayAuditRepository.php`, `lib/KuickPayReconcileLockRepository.php`, `lib/KuickPayValidationResult.php`, `lib/KuickPayEvidence.php` (gateway), `models/kuickpay_voucher_invoices.php`, `models/kuickpay_audit_events.php`.

### Project Structure Notes

- Posting lives in the **plugin** (`plugins/kuickpay_reconcile`), never the gateway — the gateway owns only checkout/reference display. [Source: architecture.md#Ownership-Boundaries lines 518-526]
- Plugin services live under `lib/`; persistence in `models/`. Follow the `__construct(array $dependencies = [])` + `loadRuntimeDependencies()` DI seam used by `KuickPayReconcileService` and `KuickPayEvidenceValidator` so tests inject fakes.
- Cron registration uses the existing idempotent helpers (`addCronTasks`/`addCronTask`/`deleteCronTask`/`getCronTasks`). Schema/data-shape changes go through plugin `install()`/`upgrade()` only — but note this story needs **no new columns** (every field already exists: `blesta_transaction_id`, `date_posted`, `date_paid`).

### Constraints (from project-context.md — non-negotiable)

- PHP **8.2** target (no 8.3+ syntax/APIs). Match each file's existing style; legacy plugin classes use global namespace (no `declare(strict_types=1)` sweep). The new lib classes here use typed signatures like the existing `KuickPay*` libs — match those siblings.
- Use Blesta `Loader`, `Record`, `Input`, model/`errors()` patterns; no raw SQL beyond the `FOR UPDATE` read (the surrounding model already uses query-builder + one raw subquery, so a bound raw `query()` is in-house style). Allowlist any request-controlled fields (none expected here — company_id comes from `Configure::get('Blesta.company_id')`).
- Keep all user-facing text in language files via `Language::_`. No secrets/credentials/raw SOAP in logs, audits, or fixtures.
- GET routes stay read-only; this story has no admin mutation routes (cron-driven). Do not add a "force paid" action.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.5] — user story + BDD ACs; #Story-3.6 (out-of-scope exceptions); FR17/FR19/FR20 and NFR3 (epics.md:91, idempotency) / NFR13 (epics.md:111, no-float amounts) in #Requirements-Inventory. The no-float rule is echoed at architecture.md:593 and anti-pattern architecture.md:658.
- [Source: _bmad-output/planning-artifacts/architecture.md#Posting-Contract (lines 581-593)] — re-read/lock/re-verify/create/apply/transition/audit sequence.
- [Source: architecture.md#Data-Architecture (lines 339-353)] — states; `posted` semantics; lock + idempotency requirement.
- [Source: architecture.md#Anti-Patterns (lines 648-661)] — no transaction in `buildProcess()`, no posting outside `KuickPayPostingService`, no float amounts, cron posting needs row locks, no force-paid.
- [Source: architecture.md#UI-Display-State-Matrix (lines 597-606)] — `confirmed_unposted` "post through service"; `posted` forbids duplicate posting; no success styling pre-`posted`.
- [Source: plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php:181-237] — `persistEvidence()` integration seam (line 233 comment).
- [Source: plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php:25-160] — `validate()` + `voucherIsFresh()` trap.
- [Source: plugins/kuickpay_reconcile/lib/KuickPayValidationResult.php] — `isValid()/reasons()/outcomeStatus()`.
- [Source: plugins/kuickpay_reconcile/models/kuickpay_vouchers.php] — fields, statuses, `edit()`, `findActive*`.
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php] — schema, cron registration, install/upgrade.
- [Source: app/models/transactions.php] — `add()/apply()/getByTransactionId()`.
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php] — constructor for reconstructing evidence at posting time.
- [Source: _bmad-output/implementation-artifacts/deferred-work.md:68,76,81,82] — items to close.
- [Source: _bmad-output/project-context.md] — PHP 8.2, Blesta conventions, testing rules.

## Developer Context

### Previous Story Intelligence

- **3-4 (Validate Confirmed Payment Evidence, done):** built `KuickPayEvidenceValidator`, `KuickPayInvoiceReader`, `KuickPayValidationResult`; wired into `KuickPayReconcileService::persistEvidence()`. A passing validation sets the voucher to `confirmed_unposted` with `amount`/`date_paid`/`kuickpay_reference`; a failing one sets `manual_review` and strips paying fields. The validator returns `KuickPayValidationResult` (immutable) with `isValid()`, `reasons()` (machine codes, no PII), `outcomeStatus()`. Reason codes: `currency_mismatch`, `amount_mismatch`, `unmatched_reference`, `invoice_mismatch`, `stale_voucher`, `duplicate_reference`.
- **3-3 (Reconcile, done):** cron `reconcile_pending` (5-min), DB lock via `KuickPayReconcileLockRepository`, bounded batch + resume cursor + max-runtime + retry policy. `persistEvidence()` is where confirmation happens and stops. Mirror its lock/bounded-batch shape for the posting pass.
- **3-2/3-1 (done):** `KuickPayEvidence` (immutable, constructor takes the normalized fields), `KuickPayResponseParser`, `KuickPaySoapClient`, `KuickPayRedactor`. You only need `KuickPayEvidence` here (to reconstruct evidence from stored fields); no SOAP at posting time.
- **2-1 (done):** voucher schema + models; `blesta_transaction_id`, `date_posted`, `date_paid` already exist — no migration needed.
- **Testing pattern across 3-x:** fake-injection via `__construct(array $dependencies = [])`, external PHPUnit 8.5 runner, fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`. Several ACs across this epic were verified on PHP 8.3.31 (the only interpreter available in those checkouts) with a fallback note — do the same if 8.2 is unavailable and state it explicitly; do not claim 8.2 verification you didn't run.

### Git Intelligence Summary

Recent commits (Epic 2/3) show the established cadence and conventions to follow: small, scoped commits with `feat(kuickpay):` / `test(kuickpay):` / `chore(story):` prefixes; tests committed alongside the lib/model change they cover (e.g. `feat(kuickpay): store multi-invoice allocations` paired repository + model + test changes; `test(kuickpay): cover service amount-changed notice mapping`). Keep posting changes in similarly small commits (validator param, service, cron wiring, tests) and update this story's checkboxes/Dev Agent Record as you go. Commit summaries imperative, lowercase, ≤72 chars; types limited to `feat|fix|docs|test|refactor|chore`.

### Latest Tech Information

No external libraries or version research apply — posting uses only in-repo Blesta 6.0 APIs (`Transactions`, `Invoices`, `Record`) and existing KuickPay plugin classes, all on PHP 8.2. The one framework nuance worth restating: there is no query-builder `FOR UPDATE`; use a bound raw `Record->query(... FOR UPDATE ...)` inside an open `begin()` transaction on the shared PDO connection.

### Project Context Reference

Full agent rules: `_bmad-output/project-context.md`. Most load-bearing for this story: preserve Blesta extension boundaries and loader/model/`Record`/`Input` patterns; PHP 8.2 only; never float-compare money (NFR13); language-file all strings; keep secrets/raw SOAP out of audits/logs/fixtures; schema changes need install/upgrade artifacts (none needed here); run `php -l` on changed files and don't overstate verification.

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References
- 2026-06-10: Extended `KuickPayEvidenceValidator::validate()` with an allowed-status parameter. Verified with `/root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayEvidenceValidatorTest.php` and `php -l` on changed validator files.
- 2026-06-10: Added postable voucher selection plus locked voucher/invoice-link reads. Verified with `/root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayVoucherRepositoryTest.php` and `php -l` on touched repository/model files.
- 2026-06-10: Added `KuickPayPostingService` with paid-date guard, locked re-read, validation, duplicate transaction adoption, Blesta transaction add/apply, rollback handling, and posting audits. Verified with `/root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayPostingServiceTest.php` and `php -l` on new posting files.
- 2026-06-10: Registered `post_confirmed` cron, bumped plugin config to 1.2.0, added posting language strings, and made upgrade version gates cumulative. Verified with `php -l` on plugin/language files and the posting service PHPUnit slice.
- 2026-06-10: Added `plugins/kuickpay_reconcile/lib/README.md` documenting `KuickPayPostingService` as the only KuickPay payment posting boundary.
- 2026-06-10: Expanded posting tests for malformed paid dates, unsafe existing transactions, partial applications, and double-allocation re-validation failures. Verified with the posting service PHPUnit slice and `php -l` on the test file.
- 2026-06-10: Final verification passed: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (56 tests, 205 assertions), and `php -l` passed for every changed PHP file. Boundary grep confirmed KuickPay-owned `Transactions->add()` / `Transactions->apply()` calls are limited to `KuickPayPostingService`.

### Completion Notes List
- Task 2 complete: posting can explicitly validate `confirmed_unposted` vouchers while reconcile-time defaults still reject that state as stale.
- Task 4 complete: repository/model support now exposes bounded postable fetches and `FOR UPDATE` reads for the posting transaction.
- Task 1 paid-date guard complete in `KuickPayPostingService::postVoucher()`; missing or malformed paid dates route to `manual_review` with `missing_paid_date` and no transaction.
- Task 3 complete: posting is centralized in `KuickPayPostingService` with DB lock batching, row-locked posting, idempotency, rollback-safe failures, and redacted audit events.
- Task 5 complete: `post_confirmed` is registered idempotently, dispatches `KuickPayPostingService`, and uses a distinct DB lock.
- Task 6 language strings complete: cron labels and the redacted transaction message are in the plugin language file.
- Task 6 docs complete: library README documents the single posting boundary and forbids payment creation/application outside `KuickPayPostingService`.
- Task 7 coverage expanded: posting tests now cover happy path, idempotency, duplicate/adopted transactions, invalid paid dates, re-validation failure, rollback failure paths, and unsafe existing transaction handling.
- Task 7 complete: full plugin-local PHPUnit suite and syntax checks passed; story is ready for review.

### File List
- plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php
- plugins/kuickpay_reconcile/lib/KuickPayPostingService.php
- plugins/kuickpay_reconcile/lib/README.md
- plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
- plugins/kuickpay_reconcile/config.json
- plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
- plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php
- plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php
- plugins/kuickpay_reconcile/models/kuickpay_vouchers.php
- plugins/kuickpay_reconcile/tests/bootstrap.php
- plugins/kuickpay_reconcile/tests/KuickPayEvidenceValidatorTest.php
- plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php
- plugins/kuickpay_reconcile/tests/KuickPayVoucherRepositoryTest.php

### Review Findings

Adversarial code review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), 2026-06-10. Baseline `91819026`. 3 layers ran; 2 patch, 2 defer, 15 dismissed.

- [x] [Review][Patch] `toMinorUnitsOrNull` rejects Blesta `decimal(12,4)` amounts → adopt-existing-transaction (AC7b) routes every real match to `manual_review` in production [plugins/kuickpay_reconcile/lib/KuickPayPostingService.php:418] — DB returns `transactions.amount`/`transaction_applied.amount` as 4-decimal strings (`"1000.0000"`); the regex `^\d+(?:\.\d{1,2})?$` only accepts 0–2 decimals → `null`. Tests passed only because the fakes used 2-decimal strings. Fix also hardens `adoptExistingTransaction`/`appliedMatches` so an unparseable (`null`) amount is a definitive mismatch (no `null===null` match). No floats introduced. **FIXED** in `fix(kuickpay): accept blesta 4-decimal amounts when adopting transactions` + 4-decimal regression test.
- [x] [Review][Patch] AC9 double-allocation not verified end-to-end through the real validator [plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php:229] — `testDoubleAllocationRevalidationFailureMovesToManualReview` injects a stub validator; AC15 enumerates symmetric (active sibling → `stale_voucher`) and asymmetric (reduced live `due` → `invoice_mismatch`) cases. Logic is covered in `KuickPayEvidenceValidatorTest`, but add posting-service integration tests driving the real `KuickPayEvidenceValidator`. **FIXED** in `test(kuickpay): cover AC9 double-allocation via real validator`.
- [x] [Review][Defer] Head-of-line blocking: a deterministically-failing voucher stays `confirmed_unposted` and re-occupies the front of every bounded batch [plugins/kuickpay_reconcile/lib/KuickPayPostingService.php:54] — deferred, secondary; per AC6 the `failed` outcome intentionally preserves `confirmed_unposted` for retry, and a retry/backoff cap is Epic-4 scope.
- [x] [Review][Defer] `getByTransactionId` returns a single arbitrary row when duplicate references exist [plugins/kuickpay_reconcile/lib/KuickPayPostingService.php:136] — deferred, pre-existing core behavior; `transactions.transaction_id` has no unique index. Low likelihood since `kuickpay_reference` is per-voucher unique.
