---
baseline_commit: b20b2a9f14cfa806588363654e8fe4364430d4a8
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.3: Reconcile and Posting Safety Hardening

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer,
I want the known money-path concurrency and lifecycle residuals closed,
so that manual and scheduled reconciliation cannot demote or strand a confirmed payment.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.3 (lines 899–927); `deferred-work.md` 4-3 `:435` guard (line 128),
> single-inquiry paid-date / AI-3 (line 12), `getResumeCursor` trigger scope (line 80), per-Voucher txn (line 81),
> `insertLock` (line 84), `gatewayConfigForCompany` empty-keys (line 85), posting head-of-line blocking (line 109),
> `getByTransactionId` (line 110); Epic 3 retro AI-6 + AI-7 (`epic-3-retro-2026-06-11.md:131–132`) and Patterns #3/#4
> (lines 67–68); Epic 4 retro Pattern #5 + action items 4/6/7 (`epic-4-retro-2026-06-13.md:62–63,121,123,124`);
> architecture **Reconciliation/Posting Flow** (570–592), **Voucher States / active context** (339–351), **Audit** (610–634);
> NFR9 fail-closed (epics.md:103), NFR3 idempotency (epics.md:91).

1. **(AC1 — Status-guard the `persistEvidence()` terminal write so a racing manual reconcile cannot demote a confirmed payment)**
   **Given** manual Check Now (`reconcileVoucher()`) deliberately skips the `reconcile_pending` batch lock,
   **When** it races a cron confirmation on the same Voucher (cron flips `pending → confirmed_unposted` with a `date_paid` while the manual SOAP inquiry is in flight),
   **Then** the terminal write in `persistEvidence()` (`KuickPayReconcileService.php:535`, the historical `:435` reference) is **status-guarded** (`WHERE status IN ('pending','retry')`) so the racing manual reconcile matches **zero rows** instead of overwriting a `confirmed_unposted` Voucher to `manual_review` with a dangling `date_paid`,
   **And** when the guarded write matches zero rows the function does **not** record a demotion — it returns the Voucher's *actual current* status so the `kuickpay_reconciliation_items` row and audit event reflect a benign no-op, not a false `manual_review`.

2. **(AC2 — Single-inquiry confirmed evidence with an empty/unparseable paid date routes to `manual_review` at parse time)**
   **Given** a single-inquiry confirmed (`00`) row whose `Transaction_Date` (`fields[2]`) is empty or unparseable,
   **When** `KuickPayResponseParser::parseBillPaymentInquiry()` parses it,
   **Then** it routes to `manual_review` **at parse time** with a `missing_paid_date` validation error, mirroring the existing bulk `parseBulk` guard (`KuickPayResponseParser.php:~261`) — closing the gap where the single-inquiry branch could emit `confirmed_unposted` with `paidAt() === null` and rely only on the downstream posting `validPaidDate` gate.

3. **(AC3 — `getReconcilable()` and `getExpirable()` derive "today" from the same clock; the limbo window is eliminated, not merely guarded)**
   **Given** `getReconcilable()` selects the eligible set using the **PHP clock** (`date('Y-m-d')` for the `date_expires` gate, `kuickpay_vouchers.php:549`) and `getExpirable()` selects using the **DB clock** (`CURDATE()`, `kuickpay_vouchers.php:618`),
   **When** both selectors compute "today",
   **Then** they derive it from the **same clock** so the expiry/confirm limbo window (a row simultaneously reconcilable on the PHP clock and expirable on the DB clock under app/DB clock skew) is **eliminated at the boundary**, not only mitigated by the AC1/`expire()` status guards.

4. **(AC4 — Thread `gatewayConfig` structurally, fail closed on missing config keys, and scope `getResumeCursor` by `trigger_type`)**
   **Given** the resolved gateway config and the resume cursor,
   **When** a run (`run()`), manual recheck (`reconcileVoucher()`), or bulk run (`runBulk()`) starts,
   **Then** `gatewayConfig` is threaded **structurally** — passed explicitly down through `processVoucher()` into `persistEvidence()` rather than read back from a mutable `$this->gatewayConfig` member — so the null-in-production footgun that bit 3-6 cannot recur,
   **And** `gatewayConfigForCompany()` fails closed (`kuickpay_unavailable`) when required SOAP/config keys are missing or blank instead of constructing a client with empty credentials,
   **And** `getResumeCursor()` is **scoped by the current `run()` trigger_type** (`runRepository->getResumeCursor($company_id, $trigger_type)`) so any future non-cron caller of the shared `run()` entry can never inherit a prior aborted **cron** run's cursor and silently skip low-id eligible Vouchers. `reconcileVoucher()` and `runBulk()` still open their own run records with cursor `0` and do not call `getResumeCursor()`.

5. **(AC5 — Per-Voucher transactional writes, infra-aware lock failure, durable bounded posting retries, and correct transaction adoption)**
   **Given** per-Voucher reconcile writes and the bounded posting batch,
   **When** they execute,
   **Then** (a) the per-Voucher reconcile writes (Voucher edit + `kuickpay_reconciliation_items` row + audit event) in **both** `processVoucher()` and the `runBulk()` loop are wrapped in a single DB transaction (`begin()/commit()/rollBack()`), with the failure-path item/audit written on a **fresh statement after `rollBack()`** (mirroring the posting service);
   **And** (b) `insertLock()` **distinguishes a duplicate-key collision** (SQLSTATE `23000` / MySQL `1062`, the expected "lock already held") from an **infrastructure failure** (connection loss, etc.), returning `false` only for the former and **surfacing** the latter instead of masquerading it as `lock_held`;
   **And** (c) a **durable posting retry cap** prevents a deterministically-failing low-id Voucher from re-occupying the head of every `postConfirmed()` batch (head-of-line blocking), escalating to `manual_review` after a bounded number of failed posting attempts (fail-closed, NFR9);
   **And** (d) the transaction-adoption lookup selects the **most-recent approved + already-applied** match (implemented in `KuickPayPostingService`, **not** by editing core `app/models/transactions.php`) so an external/manual duplicate reference cannot cause adoption of the wrong row.

_Closes: `deferred-work.md` 4-3 `:435` guard, single-inquiry paid-date (AI-3), resume-cursor trigger scope, per-Voucher txn, `insertLock`, config-keys guard, posting retry cap, `getByTransactionId`; Epic 3 retro AI-6 + AI-7; NFR9._

## Tasks / Subtasks

- [x] **Task 1 — AC1: Status-guard the `persistEvidence()` terminal write** (closes `deferred-work.md:128`)
  - [x] 1.1 In `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php::persistEvidence()` (`:475–538`), replace the unguarded terminal write `$this->voucherRepository->edit((int) $voucher->id, $company_id, $vars);` (`:535`) with a **status-guarded** write that only matches rows still `IN ('pending','retry')` and returns whether it transitioned the row. **Reuse the model's existing status-guarded UPDATE pattern** — `KuickpayVouchers::transition($id, $company_id, $new_status, ['pending','retry'], $extraVars)` (`kuickpay_vouchers.php:664–693`) already does `WHERE status IN (...)` + `rowCount()===1`; do **not** hand-roll a second mechanism. Add a thin repository method (e.g. `editIfActive(int $id, int $company_id, array $vars): bool` delegating to `transition()`) so the service stays at the repository boundary, consistent with the existing `expire()` delegation (`KuickPayVoucherRepository.php:256–259`).
  - [x] 1.2 When the guarded write matches **zero rows** (the row already left `pending/retry` — a concurrent cron confirmed/transitioned it), `persistEvidence()` must **not** return the would-be demoted status. Re-read the current row (company-scoped) and return its **actual** status, so the caller's `itemRepository->record()` and `recordEvidenceAudit()` reflect a benign no-op (`prior_status === new_status`), never a false `manual_review` with a dangling `date_paid`. Do not write `date_paid`/`amount`/`kuickpay_reference` on the no-op path.
  - [x] 1.3 Confirm this single fix covers **all** callers of `persistEvidence()` — `processVoucher()` (cron single + manual Check Now, `:396`) **and** the `runBulk()` loop (`:336`). The bulk loop's pre-read status check (`:316–318`) stays as a cheap early-out but is no longer the safety boundary; the guarded write is.
  - [x] 1.4 Add/extend tests in `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php`: a manual reconcile whose evidence would set `manual_review`/other, against a Voucher that a concurrent writer has already moved to `confirmed_unposted`, results in **no demotion** (guarded write matches 0 rows; returned status === `confirmed_unposted`; no `date_paid` clobber). The fake voucher repo must model the status-guarded write faithfully (return `false`/0-rows when the simulated current status is not in `pending/retry`) so the fake cannot mask the bug (fake-fidelity lesson, Epic 3 retro AI-2). The **authoritative** proof is the Task 6 DB harness.

- [x] **Task 2 — AC2: Mirror the bulk `missing_paid_date` guard into the single-inquiry confirmed branch** (closes `deferred-work.md:12`, Epic 3 AI-3)
  - [x] 2.1 In `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php::parseBillPaymentInquiry()` (`:437–560`), before returning `STATUS_CONFIRMED_UNPOSTED` (`:552–559`) for a `00` row that has passed amount/currency/identity validation, check `if ($this->normalizeDate($fields[2]) === null)` (the same `normalizeDate()` used at `:579`, defined `:692–705`) and instead return `STATUS_MANUAL_REVIEW` with validation errors `['missing_paid_date']` — byte-for-byte the bulk idiom at `parseBulk()` `:256–270`. Use the existing `inquiryEvidence(...)` helper so the evidence shape (reference, identity, amount, currency, raw status, trace id) stays consistent.
  - [x] 2.2 Preserve all existing single-inquiry routing (malformed status, `01` pending, `02` expired, amount/currency/registration mismatch → `manual_review`). The new guard fires **only** on an otherwise-confirmable `00` row with a null/empty/unparseable date — it must not change any currently-passing case.
  - [x] 2.3 Add a test in `components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php` modelled on `testBulkConfirmWithMissingPaidDateFailsClosed()` (`:417–440`): a single-inquiry `00` response with an empty/blank `Transaction_Date` (and amount/currency/identity all matching) → `manual_review`, `validationErrors() === ['missing_paid_date']`, `paidAt() === null`. The `KuickPayFailClosedContractTest` (`:25–48`) will then naturally cover any such ambiguous fixtures.

- [x] **Task 3 — AC3: Align `getReconcilable()` and `getExpirable()` to the same clock** (closes Epic 3 retro AI-6 `:131`; `[[kuickpay-expiry-reconcile-clock-skew]]`)
  - [x] 3.1 In `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php::getReconcilable()` (`:534–577`), change the `date_expires` gate from the PHP clock `->where('date_expires', '>=', date('Y-m-d'))` (`:549`) to the **DB clock** raw expression `->where('date_expires', '>=', 'CURDATE()', false, false)` — matching the `getExpirable()` idiom `->where('date_expires', '<', 'CURDATE()', false, false)` (`:618`) and the existing raw-expression style already used for the retry backoff at `:567` (`'DATE_SUB(NOW(), …)', false, false`). After this change `date_expires = CURDATE()` ⇒ reconcilable (>=) **and not** expirable (<), and `date_expires < CURDATE()` ⇒ expirable **and not** reconcilable — exact complements, **no overlap, no gap**.
  - [x] 3.2 Secondary consistency (recommended, not the load-bearing fix): the pending-recheck cadence default is also PHP-clock (`$pending_min_recheck_before = … date('Y-m-d H:i:s', strtotime('-30 minutes'))`, `:540`) and the service passes a PHP-clock value (`KuickPayReconcileService.php:137`, `date('Y-m-d H:i:s', time() - PENDING_RECHECK_MINUTES*60)`). **Deliberately left PHP-clock (documented scope, not silent):** this is a *cadence*, not a correctness boundary — skew only rechecks slightly early/late and never strands a payment, whereas the `date_expires` gate (3.1) is the limbo-window boundary that was aligned. The load-bearing complement is `date_expires` on one clock; the recheck cadence is intentionally out of scope. See Dev Agent Record.
  - [x] 3.3 The clock alignment is a **DB-behavior** change — fakes cannot prove it. Prove it in the Task 6 DB harness (a row with `date_expires = CURDATE()` is returned by `getReconcilable()` and **not** by `getExpirable()`; a row with `date_expires = CURDATE() - 1 day` is the reverse). Note in the harness that this removes the limbo window the AC1 guard previously only *guarded*.

- [x] **Task 4 — AC4: Thread `gatewayConfig` structurally + required-key guard + scope `getResumeCursor` by `trigger_type`** (closes Epic 3 retro AI-7 `:132`, `deferred-work.md:80,85`)
  - [x] 4.1 **Config threading.** Today `run()`/`reconcileVoucher()`/`runBulk()` each resolve `$gateway_config` at entry and assign it to the mutable member `$this->gatewayConfig` (`:110,185,263`), and `persistEvidence()` reads it back via `resolveExceptionStatus($new_status, $reasons, $this->gatewayConfig)` (`:528`). Make this **structural**: pass the resolved `$gateway_config` explicitly down the call chain — `processVoucher($company_id, $run_id, $voucher, $client, $gateway_config)` → `persistEvidence($company_id, $voucher, $evidence, $gateway_config)` → `resolveExceptionStatus(..., $gateway_config)` — so correctness no longer depends on the member being set first (the "per-story don't-forget" gap that bit 3-6). Keep the entry-point resolution and `kuickpay_unavailable` skip (`:107–109,182–184,260–262`) unchanged. Do not keep `$this->gatewayConfig` as the source of truth for runtime decisions; after this change it may remain only as a constructor/test override input.
  - [x] 4.2 Update **all** `processVoucher()` callers to pass `$gateway_config` (`run()` `:150`; `reconcileVoucher()` `:211`; the `runBulk()` loop persists via `persistEvidence()` directly at `:336` — pass `$gateway_config` there too). `processVoucher()` is `public` (`:386`) and is part of the tested surface — update its signature and every test double/call site (`KuickPayReconcileServiceTest.php`) accordingly. Prefer an explicit required parameter over an optional one so a missing config is a hard error at call time, not a silent `null`.
  - [x] 4.3 **Empty-keys guard (`deferred-work.md:85`).** `gatewayConfigForCompany()` (`:719–750`) defaults every credential/config field to `?? ''`; with missing required keys the service builds a SOAP client with empty credentials and burns retries toward `manual_review`. Add a required-keys-present check (`wsdl_url`, `inquiry_username`/`voucher_username` per `inquiry_same_as_voucher`, `inquiry_password`/`voucher_password`, and `institution_id` if the SOAP client requires it for inquiry requests) that returns `null` (→ `kuickpay_unavailable` skip) instead of running a doomed batch. Keep it conservative — these keys are validated at save time (Epic 1); this is defence-in-depth, not a behavior change for valid configs. Add a service test for blank required inquiry credentials returning `kuickpay_unavailable` and making no SOAP client.
  - [x] 4.4 **Resume-cursor trigger scope.** In `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php::getResumeCursor()` (`:71–83`), add a `string $trigger_type` parameter and filter `->where('trigger_type', '=', $trigger_type)` instead of the hard-coded `'cron'` (`:76`). Update the call in `KuickPayReconcileService::run()` (`:125`) to pass the run's `$trigger_type`. Update the repository delegate (`KuickPayReconciliationRunRepository`) and any `getResumeCursor` test. Confirm `reconcileVoucher()` (opens `'manual'`, cursor `0`, `:202`) and `runBulk()` (`openBulk`, cursor `0`, `:277`) do **not** call `getResumeCursor` and so are unaffected — but the scoping makes the shared `run()` entry safe for any future non-cron caller of `run($company_id, $trigger_type)`.

- [x] **Task 5 — AC5: Per-Voucher transaction, infra-aware `insertLock`, posting retry cap, correct adoption** (closes `deferred-work.md:81,84,109,110`)
  - [x] 5.1 **Per-Voucher reconcile transaction.** Wrap the three per-Voucher writes — `persistEvidence()` (Voucher edit), `itemRepository->record()`, `recordEvidenceAudit()` — in a single transaction in **both** `processVoucher()` (`:392–409`) and the `runBulk()` loop (`:335–349`). Use the same idiom the posting service already uses: `$record = $this->voucherRepository->record(); $record->begin(); … $record->commit();` with `$record->rollBack()` on failure (`KuickPayPostingService::postVoucher` `:87–197` is the reference). **CRITICAL Blesta footgun (Epic 3 retro `:70`): there are NO nested transactions and a self-transacting method called inside an outer `begin()` commits early and drops the lock.** Verify every call inside the wrapped block is non-transacting: `persistEvidence`→`edit()/transition()` (no `begin`), `getWithInvoices()` (read), `itemRepository->record()`, `auditService->record()` are all fine; do **not** call `voucherRepository->create()` (it self-transacts, `:33–53`) inside the block.
  - [x] 5.2 On failure inside the wrapped block, `rollBack()` and then write the failure-path `kuickpay_reconciliation_items` row + `evidence.error` audit on a **fresh statement after the rollback** (the existing `processVoucher()` catch at `:412–441` already does item+audit on failure — move it after the `rollBack()` and ensure the `Record` is clean, per the 5.2 `Record->reset()` lesson `[[kuickpay-bulk-idempotency-unique-item-key]]`). One Voucher's failure must never abort the batch (the existing inner try/catch discipline holds).
  - [x] 5.3 **Infra-aware `insertLock`.** In `plugins/kuickpay_reconcile/models/kuickpay_reconcile_locks.php::insertLock()` (`:10–26`), replace `catch (Exception $e) { return false; }` with exception inspection: return `false` **only** for a duplicate-key collision (PDO `SQLSTATE 23000`, MySQL driver code `1062` — read `$e->getCode()` / `$e->errorInfo`), and **re-throw** (or return a distinguishable infra-error signal) for any other exception. Update `KuickPayReconcileLockRepository::acquire()` (`:15–29`) so a genuine infra failure is **surfaced** (propagated/audited) rather than silently falling through to `reclaimStale()` and returning `null` (`lock_held`). Preserve the duplicate-key → `reclaimStale()` → stale-reclaim path exactly. Add a unit test that a non-duplicate PDOException from `insertLock()` is surfaced, while a `1062` returns `false`.
  - [x] 5.4 **Posting retry cap (durable bounded attempts → escalate to `manual_review`; touches schema).** In `KuickPayPostingService::postConfirmed()` (`:41–74`), a `failed` `postVoucher()` outcome (`:166,177,196`) leaves the Voucher `confirmed_unposted`, so a deterministically-failing low-id row re-occupies the head of every batch (`getPostable()` is ascending-by-id with no skip, `:587–600`). Add a durable bounded posting-attempt counter so the head clears across runs:
    - Add a `posting_attempts INT UNSIGNED NOT NULL DEFAULT 0` column to `kuickpay_vouchers` (new idempotent migration, **bump `config.json` `1.9.0` → `1.10.0`**, follow the 5.2 `addActiveContextGuard()`/`columnExists()` pattern + both `install()` and `upgrade()` paths).
    - Add `posting_attempts` to the model `FIELDS` allowlist + a validation rule consistent with `retry_count`; otherwise writes will be silently dropped.
    - Add repository/model helpers to increment attempts atomically for a still-`confirmed_unposted` company-scoped Voucher and to transition to `manual_review` when attempts reach `KuickPayPostingService::POSTING_RETRY_LIMIT = 5` (mirrors `KuickPayReconcileService::RETRY_LIMIT`).
    - Increment only on true failed posting outcomes (`transaction_add_failed`, `transaction_apply_failed`, `posting_exception`). Do **not** increment for `posted`, `already_posted`, `skipped`, or validation-driven immediate `manual_review` outcomes such as `missing_paid_date` / evidence mismatch.
    - The increment/escalation must run for failures returned by `postVoucher()` whether `postVoucher()` is called from `postConfirmed()` or directly by tests/admin tooling. Implement the failed-outcome bookkeeping inside `postVoucher()`'s failure paths or in a private helper called by those paths, not only in the `postConfirmed()` loop.
    - When the cap is reached, transition the Voucher to `manual_review` (fail-closed, NFR9) with a `posting_retry_exhausted` diagnostic + audit, so it leaves `confirmed_unposted` and stops blocking the head.
  - [x] 5.5 **Correct transaction adoption — do NOT edit core.** `KuickPayPostingService::postVoucher()` calls core `$this->transactions->getByTransactionId(reference, client_id, gateway_id)` (`:136–140`), which does a single un-ordered `fetch()` in core `app/models/transactions.php:236` (plaintext core, `Blesta\App\Models\Transactions` — **framework code, out of scope to edit**). If two `approved` transactions ever share the reference for one client+gateway, adoption may verify the wrong row. **Fix inside `KuickPayPostingService` only:** add a private helper that selects the **most-recent `approved` + already-applied** candidate (e.g. order by `date_added DESC, id DESC`, prefer one whose applied allocations already match via `appliedMatches()`), instead of trusting the arbitrary single `fetch()`. The existing `adoptExistingTransaction()` mismatch gate (`:200–236`, requires `status==='approved'` + amount/currency/client/gateway match + applied-allocation verify) stays; this only ensures the **right** candidate is handed to it. Note that `kuickpay_reference` is per-Voucher unique so this is a low-likelihood hardening (`deferred-work.md:110`); keep it minimal and do not change core behavior.
  - [x] 5.6 Extend `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php`: a `failed` Voucher increments `posting_attempts` and, at the cap, transitions to `manual_review` (head no longer blocked); direct `postVoucher()` failed paths also increment/escalate; non-failed outcomes do **not** increment; the adoption helper picks the most-recent approved+applied transaction when multiple share a reference. Also add the deferred post-then-rerun call-count assertion (`deferred-work.md:8`): post a `confirmed_unposted` Voucher once (exactly one transaction created), re-run, assert no second transaction. Hold fakes to NOT-NULL/UNIQUE fidelity (Epic 3 retro AI-2).

- [x] **Task 6 — DB-backed proof against the real stack + sanitized evidence** (AC1, AC3, AC5; NFR9, NFR12)
  - [x] 6.1 Add (or extend) an opt-in, CLI-only, clearly-guarded integration harness under `plugins/kuickpay_reconcile/tests/integration/` (sibling to `active_context_guard_check.php` and `live_fixture_round_trip.php` — reuse their `$container = include $root.'/lib/init.php';` bootstrap, CLI-only guard, and `--i-understand…` confirmation flag). Seed **disposable** rows only, tear down or roll back, never touch real customer invoices, and do not collide with the existing live `pending`/`manual_review` rows.
  - [x] 6.2 **AC1 proof:** seed a disposable `pending` Voucher, simulate the race by transitioning it to `confirmed_unposted` (with `date_paid`) and then invoking the manual reconcile write path with evidence that *would* set `manual_review`; assert the status-guarded write matched **0 rows**, the Voucher remains `confirmed_unposted` with its `date_paid` intact, and the recorded item/audit show a no-op (not a demotion).
  - [x] 6.3 **AC3 proof:** seed disposable Vouchers with `date_expires = CURDATE()` and `date_expires = CURDATE() - INTERVAL 1 DAY`; assert `getReconcilable()` returns the former and not the latter, and `getExpirable()` returns the latter and not the former — proving the boundary is an exact complement on one clock.
  - [x] 6.4 **AC5 proof:** (a) per-Voucher transaction — force an item/audit write to fail mid-block and assert the Voucher edit rolled back (no partial state) and the failure item+audit were written after rollback; (b) `insertLock` — assert a duplicate `(company_id, lock_name)` insert returns `false` (lock held) while a simulated infra error surfaces; (c) posting retry cap — drive a deterministically-failing post N times and assert the Voucher escalates to `manual_review` and the next batch advances past it.
  - [x] 6.5 Prove the `posting_attempts` migration AC end-to-end like 5.2 did: before/after `SHOW CREATE TABLE kuickpay_vouchers` across the real `1.9.0 → 1.10.0` `PluginManager::upgrade()`, existing rows default `posting_attempts = 0`, idempotent re-run clean, and fresh-`install()` ≡ `upgrade()` final schema.
  - [x] 6.6 Author/extend the sanitized evidence record (a new `docs/kuickpay/` note **or** a clearly-headed section appended to `docs/kuickpay/live-verification-evidence.md`) stating exactly what ran against the real stack vs fakes, PHP version (8.3 production / 8.2 floor), exact commands, and the AC1/AC3/AC5 proofs. **Redaction gate (NFR8):** no `config/blesta.php` values, DB creds, host/user, KuickPay credentials, raw SOAP, or customer PII — placeholders and structural/state evidence only.

- [x] **Task 7 — Verification-honesty close-out** (NFR12)
  - [x] 7.1 Run `php -l` (PHP 8.3 production **and** the 8.2 source-floor) on every changed PHP file: `KuickPayReconcileService.php`, `KuickPayResponseParser.php`, `kuickpay_vouchers.php`, `kuickpay_reconciliation_runs.php`, `kuickpay_reconcile_locks.php`, `KuickPayPostingService.php`, `KuickPayVoucherRepository.php`, `KuickPayReconcileLockRepository.php`, `KuickPayReconciliationRunRepository.php`, the plugin file + language file if a column is added, and any new harness.
  - [x] 7.2 Run both component suites with the documented runner (Dev Notes) under PHP 8.3 and the 8.2 floor; confirm green **modulo the one disclosed pre-existing gateway baseline red** (`ambiguous/bill-payment-inquiry-empty-currency.xml`, `[[kuickpay-failclosed-empty-currency-red]]`) — disclose it as baseline, not a regression.
  - [x] 7.3 In the Dev Agent Record, mark these `deferred-work.md` items **closed** with the commit/line evidence: 4-3 `:435` guard (line 128), single-inquiry paid-date / AI-3 (line 12), `getResumeCursor` trigger scope (line 80), per-Voucher txn (line 81), `insertLock` (line 84), posting head-of-line blocking (line 109), `getByTransactionId` adoption (line 110), and (if done) the empty-keys guard (line 85) + post-then-rerun assertion (line 8). State precisely what ran on the real DB vs fakes (NFR12); a single-process deterministic proof is legitimate — say so, do not claim multi-process concurrency you did not run.

## Dev Notes

### What this story is

This is an **application-layer hardening** story with one required small schema column for durable posting retries (Task 5.4). It converts five named-and-deferred money-path residuals into structural guarantees. Every item here has been carried across Epics 3→4 and routed to terminal Epic 5 (`sprint-status.yaml:64–66`). It is the sibling of 5.2: where 5.2 closed the *issuance-time* concurrency residual at the schema layer, **5.3 closes the *reconcile-time* and *posting-time* residuals** so a confirmed payment can be neither demoted (AC1), stranded with a null paid date (AC2), lost to a clock-skew limbo (AC3), processed against a null/stale config or wrong resume cursor (AC4), nor left in a non-atomic / head-of-line-blocked / mis-adopted state (AC5). All fixes are **fail-closed** (NFR9): the safe direction is always "leave it for review / make no change," never "mark paid."

### The five mechanisms (read before writing code)

**AC1 — the manual-vs-cron demotion race (`deferred-work.md:128`; Epic 4 retro Pattern #5 `:62–63`).** Manual Check Now (`reconcileVoucher()`) deliberately skips the `reconcile_pending` batch lock so it won't block on the 5-minute cron. That exposes `persistEvidence()`'s **un-status-guarded terminal `edit()`** (`:535`, the historical `:435`): if the cron flips `pending → confirmed_unposted` (writing `date_paid`) *while* the manual SOAP inquiry is in flight, the manual call's own stale-guard inside the confirmed branch (`:498–508`) sets `manual_review`, and the terminal `edit()` (`WHERE id=? AND company_id=?` only, `kuickpay_vouchers.php:101–116`) **overwrites the cron's confirmed-paid row**, demoting it to a stuck `manual_review` with a dangling `date_paid`. The fix is to route the terminal write through the **already-existing** status-guarded UPDATE (`transition()`/`expire()` use `WHERE status IN (...)` + `rowCount()===1`, `:639–693`): a racing manual reconcile then matches **0 rows** and the function returns the real current status — a no-op, not a demotion.

**AC2 — single-inquiry null paid date (`deferred-work.md:12`; Epic 3 AI-3).** `parseBulk()` fails closed when a matched paid row has an empty/unparseable `Transaction_Date` (`missing_paid_date` → `manual_review`, `:256–270`), but `parseBillPaymentInquiry()` can still emit `confirmed_unposted` with `paidAt() === null` because it passes `normalizeDate($fields[2])` straight through (`:579`) with no guard. Today only the posting `validPaidDate` gate catches it (fails closed, so not a payment-safety escalation) — but the Voucher sits `confirmed_unposted` forever, never posting and never surfaced. Mirror the bulk guard at parse time. **Parser is evidence-internal, no DB** — this is a pure gateway-component change.

**AC3 — the clock-skew limbo (`[[kuickpay-expiry-reconcile-clock-skew]]`; Epic 3 retro Pattern #3 `:67`, AI-6 `:131`).** `getReconcilable()` gates `date_expires >= date('Y-m-d')` on the **PHP clock** (`:549`) while `getExpirable()` gates `date_expires < CURDATE()` on the **DB clock** (`:618`). Under app/DB clock skew a row can be *simultaneously* reconcilable (PHP says not-yet-expired) and expirable (DB says expired) — the exact concurrent-overlap that 3-6's adversarial review proved could overwrite a confirmed paid result to `expired`. The 3-6 patch and this story's AC1 *guard* the writes; AC3 *eliminates the window* by deriving "today" from one clock (switch `getReconcilable`'s gate to `CURDATE()`). Then the two selectors are exact complements at the boundary.

**AC4 — config null-footgun + required-key fail-closed + resume-cursor scope (Epic 3 retro Pattern #4 `:68`, AI-7 `:132`; `deferred-work.md:80,85`).** Reading the resolved gateway config back from the mutable `$this->gatewayConfig` member inside `persistEvidence()` returned `null` in production in 3-6 unless the run path set the member first — a footgun that depends on per-story memory. Make it structural by passing the config explicitly down the call chain. Also stop treating blank SOAP credentials/config as usable runtime config: `gatewayConfigForCompany()` must return `null` when required inquiry credentials are missing, so the run fails closed as `kuickpay_unavailable` instead of burning real Vouchers toward retry/manual review with an impossible SOAP client. Separately, `getResumeCursor()` hard-filters `trigger_type='cron'` (`:76`) but `run()` accepts a trigger argument; scope the cursor by the current `run()` trigger type. `reconcileVoucher()` and `runBulk()` currently open their own cursor-0 run records, so they are unaffected except for the shared config threading.

**AC5 — atomicity, lock honesty, posting liveness, correct adoption.**
- *Per-Voucher transaction (`deferred-work.md:81`):* `processVoucher()` and the bulk loop do three independent writes (Voucher edit + item + audit); a crash between them leaves a mutated Voucher with a missing item/audit. Wrap them (the posting service already models this). **No nested transactions in Blesta** (Epic 3 retro `:70`) — keep self-transacting `create()` out of the block.
- *`insertLock` honesty (`deferred-work.md:84`):* `catch (Exception) { return false; }` conflates a duplicate-key (lock genuinely held) with a real infra failure, hiding the latter as `lock_held`. Distinguish SQLSTATE `23000`/`1062` from everything else and surface infra errors.
- *Posting head-of-line blocking (`deferred-work.md:109`):* a `failed` post leaves the Voucher `confirmed_unposted`; `getPostable()` is ascending-by-id with no skip, so a deterministically-failing low-id Voucher re-occupies the head of every batch and newer rows are never reached. A durable bounded retry cap → `manual_review` clears the head across runs (fail-closed). A within-run skip cursor alone is insufficient and is not an accepted implementation for this story.
- *`getByTransactionId` adoption (`deferred-work.md:110`):* core's single un-ordered `fetch()` can adopt the wrong row if a reference is duplicated. **Core is out of scope** — select the most-recent approved+applied candidate inside the posting service.

### Runtime, toolchain, and how to drive the real DB (inherited from 5.1/5.2)

- **Production runtime is PHP 8.3 (ea-php83, ionCube 15)** — the framework only boots on 8.3. Verify live legs on `/usr/local/bin/php` (8.3). "PHP 8.2" is a Composer **source-compatibility floor** only; keep code 8.2-syntax-clean (no 8.3-only syntax/APIs). See `[[kuickpay-php82-toolchain-now-available]]`.
- A real Blesta+MySQL (MariaDB `10.6.27`, confirmed in 5.2) stack runs locally; `config/blesta.php` holds DB creds; `mysql` client is `/usr/bin/mysql`; `beta.hosterpk.com` is pre-dev (data-rich, **not** live) → DB-backed tests are safe (`sprint-status.yaml:68–75`).
- Component test runner (project-context.md:74): `cd plugins/kuickpay_reconcile && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (and the gateway component for the parser test). **Do NOT** use `-c build/phpunit.xml`. Pre-existing baseline red: the gateway suite's `ambiguous/bill-payment-inquiry-empty-currency.xml` fail-closed contract test — disclose as baseline (`[[kuickpay-failclosed-empty-currency-red]]`).
- Task 5.4 adds a column; drive the upgrade through `PluginManager::upgrade()` (not a direct call) so the real migration path runs. Installed version after 5.2 is **`1.9.0`**, so the real upgrade under test is `1.9.0 → 1.10.0`.

### Files to touch (UPDATE) — current state and what changes

- `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php` — **AC1/AC4/AC5.** `persistEvidence()` (`:475–538`) terminal `edit()` at `:535` → status-guarded write + no-op return (AC1); `processVoucher()` (`:386–444`) + `runBulk()` loop (`:300–356`) → wrap the three writes in a transaction (AC5.1/5.2) and thread `$gateway_config` explicitly (AC4.1/4.2); `getResumeCursor` call at `:125` → pass `$trigger_type` (AC4.4); required-key guard in `gatewayConfigForCompany()` (`:719–750`, AC4.3). **Preserve:** lock acquire/release, run open/close, `resolveExceptionStatus` policy gating (`:587–605`, still hard-`manual_review`), the `kuickpay_unavailable` skip, all audit-event names.
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php` — **AC2.** Add the `missing_paid_date` guard to `parseBillPaymentInquiry()` (`:437–560`) before the `confirmed_unposted` return (`:552`), reusing `normalizeDate()` (`:692–705`) and `inquiryEvidence()`. **Preserve:** every existing inquiry/bulk routing branch and the `STATUS_*` contract.
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` — **AC3** (clock gate at `:549` → `CURDATE()`), **AC1** (reuse `transition()` `:664–693` via a thin repo method), **AC5.4** (`posting_attempts` in `FIELDS` `:24–60` + a `getRules()` rule `:718+`, and `getPostable()` `:587–600` unchanged but the cap drives escalation). **Preserve:** `expire()` (`:639–651`), `getForUpdate()` (`:702–711`), all selectors' existing semantics, the `FIELDS` allowlist discipline (un-listed columns are silently dropped — Epic 3 retro `:70`).
- `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php` — **AC4.4.** `getResumeCursor()` (`:71–83`) → add `$trigger_type` param, filter on it instead of hard-coded `'cron'` (`:76`). **Preserve:** the `status='aborted'` + `cursor != null` + `ORDER BY id DESC` resume semantics.
- `plugins/kuickpay_reconcile/models/kuickpay_reconcile_locks.php` — **AC5.3.** `insertLock()` (`:10–26`) → inspect the exception; `false` only for dup-key (`23000`/`1062`), surface others. **Preserve:** `reclaimStale()` and `release()`.
- `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php` — **AC5.4/5.5.** durable retry cap for failed `postVoucher()` outcomes (including direct `postVoucher()` calls, not only `postConfirmed()` loop bookkeeping); adoption-lookup helper replacing the arbitrary `getByTransactionId` call (`:136–140`). **Preserve:** the `FOR UPDATE` two-layer idempotency (`:94–134`), `adoptExistingTransaction()` mismatch gate (`:200–236`), `validPaidDate` gate (`:80–85`), audit-after-rollback discipline.
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` — add a thin `editIfActive()`/status-guarded delegate (AC1) and (if needed) `record()` is already exposed (`:308–311`) for the per-Voucher transaction. `plugins/kuickpay_reconcile/lib/KuickPayReconcileLockRepository.php` — surface infra errors from `acquire()` (`:15–29`). `plugins/kuickpay_reconcile/lib/KuickPayReconciliationRunRepository.php` — pass `$trigger_type` through to `getResumeCursor`.
- `plugins/kuickpay_reconcile/config.json` `1.9.0` → `1.10.0`; `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` — new idempotent `addPostingAttemptsColumn()` (modelled on 5.2's `addActiveContextGuard()`/`columnExists()`), wired into BOTH `install()` and a new `version_compare(<'1.10.0')` `upgrade()` branch; `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php` — a `posting_attempts` validation key if a rule is added.

### New / extended test files

- `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php` (AC1, AC4), `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php` (AC5), `components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php` (AC2), model/repo tests for `getResumeCursor` scope and `insertLock` classification.
- `plugins/kuickpay_reconcile/tests/integration/<name>.php` — opt-in CLI DB harness (sibling to `active_context_guard_check.php`) proving AC1/AC3/AC5 against the real DB — the **authoritative** proof for the DB-behavior items.
- Sanitized evidence under `docs/kuickpay/` (new note or appended section). Keep `_bmad-output/` + `docs/kuickpay/` doc commits separate from runtime/migration commits (project-context.md:104). Commit style `<type>(<scope>): <summary>`, e.g. `fix(kuickpay): status-guard reconcile terminal write`, `test(kuickpay): prove manual-vs-cron no-demote on real DB`.

### Cross-cutting guardrails (project-context.md + memories)

- **Fail-closed (NFR9):** every new branch resolves to retry/manual_review/no-op, never "paid." AC1 no-op, AC2 manual_review, AC5 posting-cap → manual_review all honor this. (`[[kuickpay-reconcile-state-set]]`: reconciliation auto-checks only `pending`+`retry`; `manual_review` is a dead-end until Epic 4 surfaces it — escalations are deliberate.)
- **Single-identity contract** (`[[kuickpay-parser-single-identity-contract]]`): the inquiry path validates ONE identity (Consumer Number in `fields[1]`); the AC2 guard must not introduce any second-identity comparison.
- **Blesta decimal(12,4) amount trap** (`[[kuickpay-blesta-decimal4-amount-trap]]`): any new fake amount source must return 4-decimal strings; minor-unit comparisons must tolerate them. The posting adoption helper compares via `toMinorUnitsOrNull()` — keep it.
- **No nested transactions / no `forUpdate()` builder; self-transacting `create()` commits early inside an outer `begin()`** (Epic 3 retro `:70`) — load-bearing for AC5.1.
- **`FIELDS` allowlist silently drops un-listed columns** — if `posting_attempts` is added it MUST be in `FIELDS` or every write ignores it.
- **Schema-affecting work needs BOTH fresh-install and upgrade artifacts** (project-context.md:63,110) — applies to the required `posting_attempts` column in Task 5.4.
- **No new ORM / no raw SQL beyond the established pattern** (project-context.md:26,47): reuse `transition()`, the `CURDATE()` raw-expression idiom already in the file, and `record()->begin()` — do not introduce new query mechanisms. **Do NOT edit core** `app/models/transactions.php` (AC5.5) or any ionCube-protected file.
- **Never expose** `config/blesta.php` / DB creds / host / PII / raw SOAP in the harness, evidence, or commits (NFR8; project-context.md:33,112,125).
- **Honest reporting (NFR12):** name precisely what ran on the real DB vs fakes; a single-process deterministic proof is legitimate — say so plainly.

### Prior-story intelligence

- **5.1** proved the live stack on PHP 8.3, advanced the installed plugin to `1.8.0`, and established the `tests/integration/` harness + sanitized-evidence-doc pattern. **5.2** advanced it to `1.9.0`, added the active-context unique key, and taught two hard lessons this story inherits: (1) a duplicate-key `PDOException` leaves stale bound values on the shared `Record` — call `Record->reset()` after `rollBack()` on every failure path (`KuickPayVoucherRepository::create()` `:56–65`); (2) generated-column / lock / clock behavior is **DB-only** — fakes can only approximate it, so the real-DB harness is the authoritative proof.
- **Epic 3 retro Pattern #1/#3 and Epic 4 retro Pattern #5**: a spec can be followed exactly and still ship a defect when the *spec itself* punted a structural guard (clock alignment; concurrency). This story exists to convert those punts into structural guarantees — do not re-defer any AC.
- **Next:** **5.4** owns audit/redaction completeness (bulk `evidence.error` symmetry, `invoice_id` mislabel, `create_failed`, redactor attributes/aliases) — keep those OUT of 5.3 except where AC5.2's failure-path audit naturally overlaps. **5.5** owns structural company-scoping + `retireVoucher()` affected-row hardening (the remaining dependency before `replace`/`allow` can be un-gated) + `normalizeAmount` rounding — keep those OUT of 5.3.

## Project Structure Notes

- All runtime changes stay inside the owning extensions: the plugin (`plugins/kuickpay_reconcile/…`) owns reconcile/posting/lock/schema logic; the gateway (`components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`) owns evidence parsing. No core-app changes — AC5.5 is explicitly implemented in the posting service, **not** in core `app/models/transactions.php`.
- Tests stay inside the extension test areas (`plugins/kuickpay_reconcile/tests/…`, `components/gateways/nonmerchant/kuickpay/tests/…`); **no** new root `tests/` (project-context.md:70).
- **Detected variance / risk to flag:** AC5.4 adds a schema column, which is the *only* schema-touching part of an otherwise application-layer story and forces a `1.9.0 → 1.10.0` migration with both install+upgrade artifacts and DB-backed verification. This is intentional because the no-schema skip-cursor alternative does not fix cross-run head-of-line blocking and is not sufficient for AC5.
- **No-nested-transaction constraint** (AC5.1) means the per-Voucher `begin()` block must contain only non-transacting calls — verify by inspection, do not assume.

### Posting Retry Cap Decision (Task 5.4)

Use the durable `posting_attempts` counter. The lighter no-schema skip-cursor does not survive across runs, so it would leave the head-of-line blocker open and cannot satisfy AC5. Implement the schema column + `1.10.0` migration and prove both fresh-install and `1.9.0 → 1.10.0` upgrade paths.

## References

- [Source: epics.md#Story 5.3 (lines 899–927); Epic 5 intro (843–846)]
- [Source: deferred-work.md#4-3 `:435` guard (line 128); single-inquiry paid-date/AI-3 (12); getResumeCursor scope (80); per-voucher txn (81); insertLock (84); empty-keys guard (85); posting head-of-line (109); getByTransactionId (110); post-then-rerun assertion (8)]
- [Source: epic-3-retro-2026-06-11.md#Pattern #3 clock race (67); Pattern #4 config null-footgun (68); framework footguns incl. no-nested-txn (70); AI-6 clock align (131); AI-7 config threading (132); forward watch-items (115)]
- [Source: epic-4-retro-2026-06-13.md#Pattern #5 manual-vs-cron race (62–63); action items 4/6/7 (121,123,124)]
- [Source: architecture.md#Reconciliation/Posting Flow (570–592); Voucher States / active payment context (339–351); Audit & Logging (610–634); NFR9 (epics.md:103); NFR3 (epics.md:91)]
- [Source: KuickPayReconcileService.php persistEvidence (`:475–538`, terminal write `:535`), processVoucher (`:386–444`), runBulk loop (`:300–356`), run/getResumeCursor call (`:102–175`,`:125`), reconcileVoucher (`:177–252`), gatewayConfigForCompany (`:719–750`), resolveExceptionStatus (`:587–605`)]
- [Source: models/kuickpay_vouchers.php edit (`:101–116`), transition (`:664–693`), expire (`:639–651`), getReconcilable (`:534–577`, clock `:549`), getExpirable (`:610–623`, `:618`), getPostable (`:587–600`), getForUpdate (`:702–711`)]
- [Source: models/kuickpay_reconciliation_runs.php getResumeCursor (`:71–83`)]
- [Source: models/kuickpay_reconcile_locks.php insertLock (`:10–26`); KuickPayReconcileLockRepository.php acquire (`:15–29`)]
- [Source: lib/KuickPayPostingService.php postConfirmed (`:41–74`), postVoucher (`:76–198`), getByTransactionId call (`:136–140`), adoptExistingTransaction (`:200–236`)]
- [Source: lib/KuickPayResponseParser.php parseBillPaymentInquiry (`:437–560`, confirmed return `:552`), parseBulk missing_paid_date (`:256–270`), normalizeDate (`:692–705`), inquiryEvidence (`:579`)]
- [Source: lib/KuickPayVoucherRepository.php edit/record/expire/create (`:256–323`,`:31–66`); app/models/transactions.php getByTransactionId (CORE, `:236` — read-only reference, do NOT edit)]
- [Source: 5.2 story (integration-harness + evidence-doc precedent, Record->reset lesson, MariaDB 10.6.27); tests/integration/active_context_guard_check.php]
- [Source: sprint-status.yaml#Epic 5 note + build order (lines 37–76)]
- Memories: `[[kuickpay-expiry-reconcile-clock-skew]]`, `[[kuickpay-reconcile-state-set]]`, `[[kuickpay-parser-single-identity-contract]]`, `[[kuickpay-blesta-decimal4-amount-trap]]`, `[[kuickpay-bulk-idempotency-unique-item-key]]`, `[[kuickpay-recheck-outcome-token-set]]`, `[[kuickpay-php82-toolchain-now-available]]`, `[[kuickpay-failclosed-empty-currency-red]]`, `[[kuickpay-real-inquiry-response-shape-validator-fix]]`

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `claude-opus-4-8[1m]` (bmad-dev-story workflow).

### Debug Log References

- Baseline before dev: plugin suite 166 green; gateway suite 233 with the one disclosed pre-existing red
  (`ambiguous/bill-payment-inquiry-empty-currency.xml`).
- DB harness run (`posting_safety_hardening_check.php --i-understand-this-mutates-kuickpay-vouchers
  --company-id=1`) → `result: PASS`, all proofs green; real upgrade `1.9.0 → 1.10.0` ran once; live rows
  untouched; scratch dropped; second invocation `migration_upgrade_ran_this_invocation: false` + PASS.
- Test-infra fix during dev: removed a duplicate `KuickPaySecretLeakageRecord` class collision; declared
  `public $Record` on the shared `AppModel` test stub so the locks model unit test can inject a fake Record.

### Completion Notes List

All five ACs implemented application-layer (plus the one required `posting_attempts` schema column), each
fail-closed (NFR9). Per-logical-unit commits (one AC / sub-AC each):

- **AC1** status-guarded `persistEvidence()` terminal write via new `editIfActive()` → `transition()`
  delegate; zero-row no-op re-reads actual status (no demotion, no `date_paid` clobber). — commit `ffea5a28`.
- **AC2** single-inquiry `missing_paid_date` guard mirrored into `parseBillPaymentInquiry()`. — `3edb3be6`.
- **AC3** `getReconcilable()` `date_expires` gate switched PHP `date('Y-m-d')` → DB `CURDATE()`
  (exact complement with `getExpirable()`). Task 3.2: the pending-recheck cadence is **deliberately left
  PHP-clock** (documented, not silent) — it is a cadence, not the limbo boundary. — `6b1271eb`.
- **AC4** structural `gatewayConfig` threading (`processVoucher`/`persistEvidence`/`resolveExceptionStatus`,
  member no longer source-of-truth) `4e55e5d3`; empty-keys fail-closed guard in `gatewayConfigForCompany()`
  `1d62e803`; `getResumeCursor()` scoped by `trigger_type` `de8177aa`.
- **AC5** per-Voucher transaction in `processVoucher()` + bulk loop (shared `persistVoucherOutcome()`,
  failure item/audit on a fresh statement after rollBack) `03cbf035`; infra-aware `insertLock()`
  (dup-key `23000`/`1062` → false, infra surfaces) `b8553aeb`; durable posting retry cap + `posting_attempts`
  `1.9.0 → 1.10.0` migration `a2a84088`; deterministic adoption candidate selection (no core edit)
  `77683abe`.
- **DB harness + sanitized evidence**: `310dc453`, `5076bf43`.

**deferred-work.md items closed** (commit / line evidence):

- `:128` 4-3 `:435` guard → AC1 `editIfActive`/`transition` status guard — `ffea5a28`.
- `:12` single-inquiry paid-date (Epic 3 AI-3) → AC2 parser guard — `3edb3be6`.
- `:80` `getResumeCursor` trigger scope → AC4.4 — `de8177aa`.
- `:81` per-Voucher txn → AC5.1 `persistVoucherOutcome` — `03cbf035`.
- `:84` `insertLock` honesty → AC5.3 — `b8553aeb`.
- `:85` empty-keys guard → AC4.3 — `1d62e803`.
- `:109` posting head-of-line blocking → AC5.4 durable retry cap — `a2a84088`.
- `:110` `getByTransactionId` adoption → AC5.5 `findAdoptableTransaction` (core NOT edited) — `77683abe`.
- `:8` post-then-rerun assertion → AC5.6 (`testPostThenRerunCreatesExactlyOneTransaction`) — `a2a84088`.
- Epic 3 retro AI-6 (clock align) → AC3 — `6b1271eb`; AI-7 (config threading) → AC4.1 — `4e55e5d3`.

**Real DB vs fakes (NFR12, honest, single-process):** proven against the real Blesta + MariaDB 10.6.27 stack
via the harness — the real `1.9.0 → 1.10.0` migration (column added, default-0, idempotent, fresh ≡ upgrade);
AC1 zero-row no-op (real `transition()`); AC3 reconcilable/expirable exact complement (real `CURDATE()` rows);
AC5a per-Voucher transaction rollback (real `Record` begin/rollBack); AC5b duplicate-lock → false (real unique
key); AC5c posting retry cap escalation (real `postVoucher()` ×5). Proven by **unit tests with status-faithful
fakes**: AC2 (parser, no DB); AC4 (config threading, empty-keys guard, cursor scope); AC5b infra-surface
(synthetic PDOException — a real infra failure cannot be safely induced); AC5.5 multi-candidate adoption. No
multi-process concurrency is claimed; every real-DB proof is a deterministic single-process exercise of the
guard. Plugin suite 180/180 green on PHP 8.3 and the 8.2 floor; gateway suite 234 green **modulo the one
disclosed pre-existing baseline red** (`empty-currency`, unrelated; the AC2 guard does not change it).

### File List

Runtime:
- `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`
- `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconcileLockRepository.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconciliationRunRepository.php`
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconcile_locks.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php`
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`
- `plugins/kuickpay_reconcile/config.json`
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php`
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`

Tests / harness:
- `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayReconcileLocksTest.php` (new)
- `plugins/kuickpay_reconcile/tests/bootstrap.php`
- `plugins/kuickpay_reconcile/tests/integration/posting_safety_hardening_check.php` (new)
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php`

Docs:
- `docs/kuickpay/posting-safety-hardening-verification.md` (new)

## Change Log

| Date | Version | Description | Author |
|---|---|---|---|
| 2026-06-14 | dev-1 | Implemented AC1–AC5 + DB harness. Plugin `1.9.0 → 1.10.0` (`posting_attempts`). Closed deferred-work `:8,:12,:80,:81,:84,:85,:109,:110,:128` and Epic 3 retro AI-6/AI-7. Plugin suite 180 green (8.3 + 8.2 floor); gateway suite 234 green modulo the disclosed `empty-currency` baseline red. DB-backed AC1/AC3/AC5 + migration proofs PASS on the real MariaDB 10.6.27 stack (single-process). | Amelia (dev-story) |
| 2026-06-14 | validation-1 | Story validation fixes applied: required durable posting_attempts migration, required empty-config guard, trigger-scoped cursor wording, direct postVoucher retry-cap coverage. | Codex (validate-create-story) |
| 2026-06-14 | — | Story drafted (ready-for-dev): reconcile/posting safety hardening — AC1 status-guarded persistEvidence write, AC2 single-inquiry missing_paid_date guard, AC3 clock alignment, AC4 structural gatewayConfig threading + trigger-scoped resume cursor, AC5 per-voucher transaction + infra-aware insertLock + posting retry cap + correct adoption. | Israr (create-story) |
