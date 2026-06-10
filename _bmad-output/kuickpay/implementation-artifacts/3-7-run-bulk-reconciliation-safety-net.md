# Story 3.7: Run Bulk Reconciliation Safety Net

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a finance operator,
I want date-based Bulk Reconciliation,
so that missed single-reference payments can be found and reviewed safely.

## Context & Why This Story Exists

Single-reference reconciliation (Story 3.3) only checks Vouchers that are still locally `pending`/`retry` and **not past expiry** (`getReconcilable()` excludes `date_expires < today`, `kuickpay_vouchers.php:328`). That leaves a gap: a customer who pays at the bank **after** the Voucher expired locally, or whose single inquiry kept failing, has a real KuickPay payment the system never matched. Bulk Reconciliation is the **safety net** — it asks KuickPay for *all* payments on a given transaction date (by Institution ID + date), then matches each returned row back to a stored Voucher **by Consumer Number only**, funnelling matched-confirmed rows through the **exact same** validation → posting path as single inquiry, and surfacing everything else (unmatched, late, mismatched, duplicate, malformed) for Manual Review with a run summary.

This is overwhelmingly a **reuse + orchestration** story. The SOAP client (`billPaymentBulkInquiry`), the bulk XML parser (`parseBulk`), the evidence validator, the posting service, the exception policy, the run/item/lock/audit persistence, and all seven bulk test fixtures **already exist** from Stories 3.1–3.6. Your job is to wire a new bulk run path into `KuickPayReconcileService`, add the minimal schema to record a bulk run, and **not break** the single-inquiry path or the payment-safety invariants.

## Acceptance Criteria

From epics.md Story 3.7 (lines 689–705), restated as testable criteria. **ACs are the floor, not the ceiling** — the system must remain working end-to-end and preserve all payment-safety invariants in §"Must-Not-Break Invariants" even where not spelled out in an AC.

1. **AC1 — Matched by Consumer Number only, no suffix inference.** Given an authorized bulk run for a transaction date, when Bulk Reconciliation runs, returned rows are matched to stored Vouchers **only** by exact Consumer Number (`kuickpay_vouchers.consumer_number`, company-scoped). Suffix inference / Consumer-Number truncation is **never** used to guess an invoice. A returned Consumer Number that does not exactly equal a stored Voucher's Consumer Number is treated as **unmatched** (not "close enough").

2. **AC2 — Matched confirmed rows reuse the single-inquiry validation/posting rules.** When a matched row is a confirmed payment, it flows through the **same** `KuickPayEvidenceValidator` checks (amount/currency/reference/invoice-mapping/duplicate/stale/late) and the **same** `KuickPayPostingService` posting path as single inquiry. The bulk path does **not** create or apply a Blesta transaction itself and does **not** re-implement validation or posting.

3. **AC3 — Unmatched & unsafe rows go to Manual Review with a run summary.** When the run completes, unmatched rows and matched-but-unsafe rows (amount mismatch, duplicate reference, late/partial/over per policy, malformed) are recorded for Manual Review (never marked paid), and a durable **run summary** records: run kind = bulk, the transaction date, checked count, matched/confirmed count, unmatched count, manual-review count, failed count, error count, status, and start/complete timestamps.

4. **AC4 — Bulk run is authorized, bounded, locked, and audited.** A bulk run requires explicit admin intent (it is **not** a default recurring cron). It is bounded (batch + max-runtime), serialized against the scheduled single-reconcile path via the DB lock, and emits audit events for run start, run completion, and per-row evidence outcomes (including unmatched). Temporary provider failure (timeout/transport) records a `failed`/`aborted` run and corrupts no invoice state.

5. **AC5 — Safe XML & explicit error classes.** Bulk XML parsing uses bounded payloads and safe XML handling (no external entities, no DOCTYPE, row + byte caps). Malformed, unknown, duplicate, unmatched, and mismatched rows map to the existing explicit error classes and never fall through to posting. (Already enforced by `parseBulk`; the story must not weaken it.)

## Scope Decision (read before estimating)

**This story delivers the bulk run ENGINE + persistence + an authorized trigger. It does NOT build the polished admin workbench.** The architecture and FR-coverage map put the reconciliation/posting **engine** in Epic 3 and the admin **workbench** (run-list/run-detail views, Manual Review queue UI, final nav placement) in **Epic 4 / Story 4.4** (FR24–FR27). EXPERIENCE.md explicitly defers admin nav placement.

In scope for 3.7:
- `KuickPayReconcileService::runBulk(...)` — the date-based bulk run engine, reusing parser/validator/posting/exception/lock/run/audit machinery.
- Bulk-run persistence: extend `kuickpay_reconciliation_runs` to record a bulk run kind, the transaction date, and an unmatched count (schema upgrade v1.4.0).
- Consumer-Number matching of returned rows to stored Vouchers (no suffix inference); routing of matched/unmatched/unsafe outcomes; run-summary + audit recording of unmatched rows.
- A **minimal authorized trigger** to invoke a bulk run for a date with admin intent (POST + staff auth + plugin ACL + CSRF) and a result message — the floor for UX-DR15/UX-DR18. **Decided (Israr, 2026-06-11):** build the minimal trigger now; the full date-input form, run-summary display, and Manual Review queue are Epic 4 / Story 4.4. This story builds only the minimum to invoke and confirm a run.
- Tests (engine + parser-context + matching + schema), run via the external PHPUnit 8.5 runner, using the existing bulk fixtures.

Out of scope (Epic 4 / Story 4.4): run-list/run-detail admin views, Manual Review queue UI, drill-into-unmatched evidence screens, search/filter, polished nav placement.

## Tasks / Subtasks

- [ ] **Task 1 — Schema upgrade for bulk runs (AC3, AC4).** Bump plugin to `1.4.0` and add an idempotent upgrade block. (`plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`, `config.json`, `models/kuickpay_reconciliation_runs.php`)
  - [ ] In `config.json`, set `version` to `1.4.0`.
  - [ ] In `upgrade()` (currently `:100–124`), add `if (version_compare($current_version, '1.4.0', '<')) { $this->addBulkReconciliationColumns(); }`.
  - [ ] Add `private function addBulkReconciliationColumns()` mirroring `addVoucherEvidenceColumns()` (`:181`): guard each change with `columnExists()`/an enum-check, then run `ALTER TABLE`:
    - Extend `trigger_type` enum from `'cron','manual'` to **`'cron','manual','bulk'`** (`ALTER TABLE kuickpay_reconciliation_runs MODIFY trigger_type ENUM('cron','manual','bulk') NOT NULL`). Do not drop the existing values.
    - Add `run_date DATE NULL DEFAULT NULL` (the authorized bulk transaction date; NULL for single/cron runs).
    - Add `total_unmatched INT UNSIGNED NOT NULL DEFAULT 0`.
  - [ ] Also add the same three field definitions to the **fresh-install** DDL in `createReconcileTables()` (`:201–221`) so new installs match upgraded installs (`trigger_type` enum gains `'bulk'`; add `run_date`, `total_unmatched`).
  - [ ] Add `run_date` and `total_unmatched` to the `FIELDS` allow-list in `models/kuickpay_reconciliation_runs.php` so the model persists them. Verify `trigger_type` validation (if any) accepts `'bulk'`.
  - [ ] **Verify fresh-install AND upgrade paths** (project-context: schema work needs both). Document the exact `php -l` + smoke result in the Dev Agent Record.

- [ ] **Task 2 — Run repository support for bulk runs (AC3, AC4).** (`plugins/kuickpay_reconcile/lib/KuickPayReconciliationRunRepository.php`)
  - [ ] Add a way to open a bulk run that records `run_date`. Either add `openBulk(int $company_id, string $run_date): int` or widen `open()` to accept an optional `run_date`. Keep the existing `open(company_id, trigger_type, cursor)` signature working for the single path (`KuickPayReconcileService.php:120` calls it).
  - [ ] Ensure `close()` persists `total_unmatched` — it already merges the `$counts` array via `array_merge` (`:28`), so adding `total_unmatched` to the counts array is sufficient; no signature change needed.
  - [ ] **Do NOT** make the bulk path call `getResumeCursor()` — that method filters to aborted **cron** runs and is single-path-specific. Bulk uses its own bounding (Task 3), not the cron resume cursor.

- [ ] **Task 3 — `runBulk()` engine in the reconcile service (AC1–AC5).** (`plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php`)
  - [ ] Add `public function runBulk(int $company_id, string $run_date, string $trigger_type = 'bulk'): array`.
  - [ ] **Config first, then thread it (critical):** resolve config exactly like `run()` (`:100–104`): `$gateway_config = $this->gatewayConfig ?? $this->gatewayConfigForCompany($company_id);` return `['status'=>'skipped','reason'=>'kuickpay_unavailable']` if null; then **`$this->gatewayConfig = $gateway_config;`** so `persistEvidence()` can read `*_policy` keys. (See §"Config threading" — this is a real footgun.)
  - [ ] **Lock:** acquire `self::LOCK_NAME` (`'reconcile_pending'`) with `self::LOCK_TTL_SECONDS`, release in `finally`. **Reuse the same lock name as the single path** so bulk and scheduled single reconciliation never touch the same Voucher concurrently. If the lock is held, return `['status'=>'skipped','reason'=>'lock_held']`. (See OQ-2.)
  - [ ] **Open run:** `$run_id = $runRepository->openBulk($company_id, $run_date);` audit `reconciliation.run.started` with payload `{trigger_type:'bulk', run_date}`.
  - [ ] **One bulk inquiry call:** build the bulk request (Task 4), `$transport = $client->billPaymentBulkInquiry($request);`. The SOAP client already retries timeout/transport up to 3× and returns a structured outcome — do not add retry here.
  - [ ] **Parse + match + classify:** turn `$transport` into per-row evidence and match each row to a Voucher by Consumer Number (Task 5). For each matched row, reuse `$this->persistEvidence($company_id, $voucher, $evidence)` — it runs the validator, applies exception policy, and writes the Voucher. **Posting is NOT done here**; the existing `post_confirmed` cron picks up any `confirmed_unposted` Voucher (`getPostable`, `kuickpay_vouchers.php:366`) and posts it.
  - [ ] **Record matched items:** for each matched Voucher, write a `kuickpay_reconciliation_items` row (reuse the `processVoucher` item shape, `:181–190`) and emit `recordEvidenceAudit(...)`.
  - [ ] **Record unmatched rows:** for each returned row whose Consumer Number matches no stored Voucher, increment `total_unmatched` and emit an audit event (e.g. `evidence.unmatched`) with a **redacted** payload (Consumer Number + sanitized evidence hash/trace only — no raw XML, no PII). **Do not** write a `kuickpay_reconciliation_items` row for unmatched rows — `items.voucher_id` is `NOT NULL` and `(run_id, voucher_id)` is `UNIQUE` (`:226,:234`), so unmatched rows have no Voucher to key on. `kuickpay_audit_events.voucher_id` is nullable (`:253`) and is the correct home; the run summary's `total_unmatched` is the count.
  - [ ] **Bound the run:** respect `self::MAX_RUNTIME_SECONDS` (set status `aborted` and stop if exceeded) and a sane row/Voucher cap (reuse `self::BATCH_SIZE` semantics or bound by `MAX_BULK_ROWS` already enforced in the parser). Per NFR7, avoid unbounded loops.
  - [ ] **Close run:** `$runRepository->close($run_id, $status, $counts, 0, $summary)` with bulk counts incl. `total_unmatched`; audit `reconciliation.run.completed`. Best-effort close inside the `finally`, then release the lock (mirror `run()`'s `:150–166` structure).
  - [ ] Return `['status'=>..., 'run_id'=>..., 'counts'=>..., 'run_date'=>...]`.

- [ ] **Task 4 — Bulk request builder (AC1, AC5).** (`KuickPayReconcileService.php`)
  - [ ] Add `buildBulkRequest(string $run_date): array` returning the date-keyed params the KuickPay `BillPaymentBulkInquiry` operation expects. The `KuickPaySoapClient` auto-merges `inquiry_*` credentials + `institution_id` via `withCredentials($params, true)`, so this builder supplies **only** the transaction-date field(s). **VERIFY the exact field name(s)/format against the KuickPay contract** captured in Story 0.1 (the bulk fixtures and `addendum.md` §A.1 describe "Institution ID and transaction date"). Do not invent a field name — if 0.1 fixtures don't pin it, flag it (see OQ-3). Validate `run_date` is a real `YYYY-MM-DD` date before calling out.

- [ ] **Task 5 — Bulk parse + Consumer-Number matching (AC1, AC2, AC3).** Decide and implement how rows are classified. **Recommended approach (per-consumer expectation map):** extend the parser; **fallback approach (per-voucher calls):** no parser change. See §"Key Implementation Decision" for the full trade-off and pick one.
  - [ ] Whichever approach: matching is **exact Consumer Number** against stored Vouchers via `KuickPayVoucherRepository::getByConsumerNumber($cn, $company_id)` (already exists, `:129`). No suffix logic anywhere.
  - [ ] A matched row's confirmed/amount-mismatch classification (amount fail-closed) **must be produced by the parser**, not re-derived in the service — see §"Must-Not-Break Invariants" #6.
  - [ ] Handle `parseBulk` returning a single transport-failure or malformed evidence element (`parseBulk` returns `[transportFailure]` or `[malformedBulkEvidence]`, `:118,:123,:127,:139`): record run `failed`/`aborted` with the error class; match/post nothing.
  - [ ] Duplicate handling: if two returned rows carry the same Consumer Number, the matched Voucher must not be double-processed into a second confirmed state. `persistEvidence`'s status guard (`mappedStatus` returns the current status unchanged once a Voucher leaves `pending`/`retry`, `:297–298`) and the validator's duplicate-reference check are the guards; ensure the second row routes to Manual Review, not a second confirm. Add a test.

- [ ] **Task 6 — Minimal authorized trigger (AC4; UX-DR15, UX-DR18).** **Decided (OQ-1): build the minimal trigger now.** Provide the minimum admin-intent entry point to invoke a bulk run for a date:
  - [ ] Add `getActions()` to the plugin (does not exist today) and a thin admin controller method that: requires staff auth + plugin ACL, accepts a **POST** with CSRF, validates the transaction-date input, calls `KuickPayReconcileService::runBulk($company_id, $run_date)`, and shows a Blesta `setMessage`/`flashMessage` result. GET stays read-only (NFR14). Use `Language::_()` keys for all labels/messages.
  - [ ] Keep it minimal — no run-list/detail views, no Manual Review queue, no search/filter (all Epic 4 / Story 4.4). Just enough UI to start a run for a date and confirm it ran.
  - [ ] **Do NOT register a recurring cron task for bulk.** Bulk is authorized/manual (FR-22, UX-DR18, EXPERIENCE.md IA line 35). Leave `getCronTasks()`'s three existing tasks unchanged.

- [ ] **Task 7 — Tests (AC1–AC5; Story 3.8 will extend).** Run with the external PHPUnit 8.5 runner (see §Testing). Reuse existing bulk fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,ambiguous,malformed}/bill-payment-bulk-*.xml`.
  - [ ] Service tests (`tests/KuickPayReconcileServiceTest.php`): `runBulk` happy path (matched-paid → `confirmed_unposted`, item + audit written, run summary counts correct); unmatched row → `total_unmatched`++ + audit, no item, no Voucher mutated; amount-mismatch/over/under matched row → `manual_review`; malformed/transport bulk response → `failed`/`aborted`, nothing posted; lock-held → skipped; **late payment on a still-`pending` past-expiry Voucher → `manual_review` with `late_payment` reason** (the materially-reachable late path — see §"Late payment").
  - [ ] Matching tests: suffix-pair fixture proves exact-match-only (no suffix inference); blank Consumer Number never matches; duplicate Consumer Number rows don't double-confirm.
  - [ ] Parser tests (only if you extend `parseBulk` — Task 5 recommended approach): per-consumer expectation map classifies each row against its own amount; backward-compatible with existing single-amount callers and all existing `KuickPayResponseParserTest` bulk tests still pass.
  - [ ] Schema test or smoke: fresh install and 1.3.0→1.4.0 upgrade both yield `trigger_type` accepting `'bulk'`, plus `run_date` and `total_unmatched` columns.
  - [ ] `php -l` on every changed PHP file. State exactly which suites ran and which prerequisites (sibling `../tests`, DB) were unavailable — do not overstate coverage.

## Key Implementation Decision: how `parseBulk` classifies multi-amount rows

`parseBulk(array $transportOutcome, array $context)` (`KuickPayResponseParser.php:115–257`) today takes:
- `$context['expected_consumer_numbers']` — an **array** of Consumer Numbers; rows whose Consumer Number is `in_array(..., true)` are "matched", the rest become `manual_review` + `unmatched_reference` (`:200–210`).
- `$context['expected_amount']` and `$context['expected_currency']` — a **single** expected amount/currency applied to **every** matched row (`:214–234`). Mismatch → `manual_review` + `amount_mismatch`.

The problem for bulk: each Voucher has its **own** expected amount, so one `expected_amount` cannot correctly validate many matched rows at once. You must resolve this. Two viable approaches:

**Approach A — extend `parseBulk` with a per-consumer expectation map (RECOMMENDED).**
- Add support for `$context['expected']` = map `consumer_number => ['amount' => string, 'currency' => string]` (keep the existing `expected_consumer_numbers`/`expected_amount`/`expected_currency` behavior when the map is absent, so existing single-amount callers and tests are untouched).
- For each row: if its Consumer Number is a key in the map → validate against *that* consumer's amount/currency → `confirmed_unposted` or `manual_review`+`amount_mismatch`; else → `manual_review`+`unmatched_reference`.
- Orchestrator builds the map: enumerate the returned Consumer Numbers, `getByConsumerNumber` each, and for found Vouchers add `{cn => {amount: voucher->amount, currency: voucher->currency}}`. One `parseBulk` call then classifies every row correctly.
- **Pros:** single bounded parse; the parser keeps ownership of the amount fail-closed (honors Invariant #6); orchestrator stays thin; cleanly testable. **Cons:** requires a parser change + parser tests (gateway-side component owned by Story 3.2 — extension is in-scope and consistent).
- To enumerate Consumer Numbers without re-implementing XML parsing, add a small parser helper (e.g. `extractBulkRows($transport): array` returning normalized row facts) **or** call `parseBulk($transport, [])` once (every row returns `unmatched`, but each evidence object still carries `consumerNumber()`), read the Consumer Numbers, build the map, then call `parseBulk($transport, ['expected' => $map])` for the authoritative classification.

**Approach B — per-Voucher scoped `parseBulk` calls (no parser change).**
- Enumerate returned Consumer Numbers (via `parseBulk($transport, [])` to read `consumerNumber()` off each evidence row), `getByConsumerNumber` each.
- For each matched Voucher, call `parseBulk($transport, ['expected_consumer_numbers' => [$cn], 'expected_amount' => $voucher->amount, 'expected_currency' => $voucher->currency])` and take the row whose `consumerNumber() === $cn`. That single row is the Voucher's evidence → `persistEvidence`.
- **Pros:** zero parser change; fully honors Invariant #6. **Cons:** re-parses the (bounded) XML once per matched Voucher — O(matched) parses; acceptable for a bounded safety net but wasteful at the row cap.

**Recommendation: Approach A.** It is the single-pass, parser-owns-amount design and the easiest to test deterministically. If the team prefers not to touch the gateway parser this cycle, Approach B is correct and ships without parser changes. **Either way, do not classify amount mismatch in the service** (Invariant #6).

## Must-Not-Break Invariants (payment safety — verify each in review)

1. **Posting boundary.** Only `KuickPayPostingService` may create/apply a Blesta transaction and set `blesta_transaction_id` / transition to `posted`. `runBulk` must **never** call `Transactions->add()/apply()`, `markPaid`, or set `blesta_transaction_id`. It only drives Vouchers to `confirmed_unposted`/`manual_review`; the existing `post_confirmed` cron posts confirmed ones. [architecture.md §Posting Contract :583; §Anti-Patterns :652; lib/README.md]
2. **One validation path.** Matched confirmed rows must go through the existing `KuickPayEvidenceValidator` (via `persistEvidence`), not a new bulk validator. [architecture.md :111]
3. **Never decide paid on unknown.** Unknown/malformed/unmatched/duplicate/mismatched/late/partial/over must fail closed to `retry` or `manual_review`, never paid. [NFR9; parser + validator already enforce]
4. **Match by stored Consumer Number only; no suffix inference.** [AC1; architecture.md Decided Invariant :85; prd.md :477, :498]
5. **Idempotency / no double-posting on rerun.** Re-running bulk for the same date must not create a second confirm or a second transaction. Guards: `persistEvidence` status guard (`mappedStatus` returns current status unchanged once a Voucher leaves `pending`/`retry`, `:297–298`); validator duplicate-reference check (uses freshly-parsed `evidence->reference()`); posting's own re-read/row-lock/`blesta_transaction_id IS NULL` guard; the `(run_id, voucher_id)` unique key on items. [NFR3; architecture.md :585–591]
6. **Amount fail-closed lives in the parser, not the service.** The check "paid amount ≠ expected amount → not `confirmed_unposted`" must be produced by `parseBulk` (so amount-mismatched evidence can never reach `confirmed_unposted` and be picked up by the posting cron `getPostable`). Do not relax `parseBulk` to let mismatched rows through, and do not re-derive the confirm decision in `runBulk`. Keep `error_class = 'amount_mismatch'` (a fixed enum value); refine only the reason in `validationErrors`. [memory: parser front-runs validator]
7. **Company scoping.** All lookups/mutations are company-scoped (`getByConsumerNumber`, `edit`, run/audit rows all take `company_id`). Bulk runs for one company must not read or mutate another's Vouchers.
8. **Single-inquiry path unchanged.** `run()`, `runCron()`, `expirePending()`, `getReconcilable()`, the three existing cron tasks, and `parseBulk`'s existing single-amount behavior must keep working exactly as before. Schema changes are additive (new columns + a widened enum), never destructive.
9. **No secret/PII leakage.** No raw SOAP/XML, credentials, or PII in `diagnostic_summary`, `summary`, audit payloads, items, logs, or test fixtures. Use redacted trace IDs / evidence hashes only. [NFR + project-context]

## Dev Notes

### Files that already exist and how they fit (reuse these — do not reinvent)

| Component | File | What it gives you | Story 3.7 action |
|---|---|---|---|
| Bulk SOAP op | `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` | `billPaymentBulkInquiry(array $bulkParams): array` (`:89`), retries timeout/transport ×3, merges `inquiry_*` creds + `institution_id`, returns structured transport outcome (`ok`,`operation`,`raw_result`,`error_class`,`fault`,`redacted_trace_id`,`attempts`). Never decides paid. | **Call as-is.** Supply only date params. |
| Bulk parser | `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php` | `parseBulk(...)` (`:115`): safe XML (LIBXML_NONET, DOCTYPE+byte cap `:126`, `MAX_BULK_ROWS` `:149`, `NewDataSet`/`Table` `:136–147`), per-row fields `Consumer_Number/Registration_Number/Transaction_Date/Paid_Amount/Transaction_Reference/Currency` (`:164–169`), exact Consumer-Number match (`:200`), amount/currency fail-closed (`:223–234`). Error classes: `unmatched_reference`, `amount_mismatch`, `malformed_response`, `timeout`, `transport_error`. | **Reuse**; optionally extend with an expectation map (Approach A). |
| Evidence DTO | `components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php` | `status()`,`errorClass()`,`reference()`,`consumerNumber()`,`registrationNumber()`,`amount()`,`currency()`,`paidAt()`,`evidenceHash()`,`redactedTraceId()`,`validationErrors()`,`isConfirmedUnposted()`. | Read-only consumer. |
| Validator | `plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php` | `validate($voucher,$invoiceLinks,$evidence,$allowedStatuses=['pending','retry'])` (`:25`). `lateReason` (`:183`): no-op when `date_expires` empty or `paidAt` null; else `late_payment` if `paid_at > date_expires` (date-only compare). | **Reuse via `persistEvidence`.** Do not change the default `$allowedStatuses`. |
| Posting | `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php` | `postConfirmed($company_id)` cron; re-read/row-lock/revalidate/create-txn/transition-to-`posted`; idempotent. | **Don't call directly.** The `post_confirmed` cron auto-posts bulk-confirmed Vouchers. |
| Orchestrator | `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php` | `run()`,`runCron()`,`processVoucher()` (`:171`),`persistEvidence()` (`:230`, **private, same class — call it from `runBulk`**),`buildParserContext()`,`mappedStatus()` (`:295`),`resolveExceptionStatus()` (`:342`),`gatewayConfigForCompany()` (`:402`),`client()` (`:435`),`recordEvidenceAudit()` (`:371`). Constants: `BATCH_SIZE=100`,`MAX_RUNTIME_SECONDS=240`,`LOCK_TTL_SECONDS=600`,`LOCK_NAME='reconcile_pending'`. | **Add `runBulk()`, `buildBulkRequest()` here.** |
| Voucher lookup | `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` + `models/kuickpay_vouchers.php` | `getByConsumerNumber($cn,$company_id)` (model `:121`) — exact, any status, company-scoped. `getWithInvoices()`, `getPostable()` (`:366`, picks any `confirmed_unposted`+null txn+non-null `date_paid`), `edit($id,$company_id,$vars)` (unsets `company_id`, company-scoped). | **Use `getByConsumerNumber` for matching.** No new selector needed. |
| Run/item/lock/audit | `lib/KuickPayReconciliationRunRepository.php`, `KuickPayReconciliationItemRepository.php`, `KuickPayReconcileLockRepository.php`, `KuickPayAuditService.php` | `open/close/updateCursor/getResumeCursor`; `record(vars)`; lock `acquire/release`; audit `record(eventName, context)`. | Extend run repo for `run_date` (Task 2); reuse the rest. |

### Current state of files you will modify (read before editing)

- **`KuickPayReconcileService.php`** — single-inquiry orchestrator (full read done). `run()` (`:98–169`): resolve config → **write to member (`:104`)** → acquire lock → open run → `getReconcilable` batch → loop `processVoucher` → close → release. `persistEvidence` (`:230–293`) is the reusable core: builds `$vars`, validates only when `isConfirmedUnposted()` (`:248`), fresh-reads via `getWithInvoices`, freshness-guards status `in ['pending','retry']` (`:256`), runs validator, merges reasons, applies `resolveExceptionStatus` off the final merged `validation_errors` (`:282–287`), then `edit`. No bulk method exists; no `BillPaymentBulkInquiry` reference. **What 3.7 changes:** adds `runBulk` + `buildBulkRequest`; calls existing `persistEvidence` per matched Voucher. **Preserve:** every behavior in §Must-Not-Break.
- **`kuickpay_reconcile_plugin.php`** — `install` (`:31`), `upgrade` (`:100`, version-compare blocks calling `addCronTasks`/column helpers), `cron($key)` (`:150`, 3 keys only), `createReconcileTables()` (`:199`: runs DDL `:201–221` with `trigger_type ENUM('cron','manual')` `:204`; items `:223` with `voucher_id` NOT NULL `:226` + unique `(run_id,voucher_id)` `:234`; audit_events `:250` with **nullable** `voucher_id` `:253`), `addVoucherEvidenceColumns()` (`:181`, the `columnExists`+`ALTER` template to copy), `getCronTasks()` (`:358`). **No `getActions()`.** **What 3.7 changes:** adds the v1.4.0 upgrade block + `addBulkReconciliationColumns()`, mirrors the columns into `createReconcileTables()`, and (Task 6) adds `getActions()` + a thin controller. **Preserve:** the three cron tasks, the idempotent task/run registration, the rollback policy (tables preserved on uninstall, `:128–130`).
- **`models/kuickpay_reconciliation_runs.php`** — add `run_date`, `total_unmatched` to `FIELDS`; confirm `trigger_type` accepts `'bulk'`.
- **`KuickPayReconciliationRunRepository.php`** — add `openBulk()` (or widen `open()`); `close()` already merges arbitrary counts.
- **`models/kuickpay_vouchers.php` / `KuickPayVoucherRepository.php`** — likely **no change** (use existing `getByConsumerNumber`). Only touch if you add a row helper.
- **`KuickPayResponseParser.php`** — change only if you pick Approach A (per-consumer expectation map), backward-compatibly.

### Config threading (do not skip — caused a bug in 3.6)

`persistEvidence` reads `$this->gatewayConfig` for `*_policy` resolution (`:283`). `gatewayConfig` is `null` in production unless `run()`/`runBulk()` resolves and **writes it to the member**. `run()` does this at `:104`. Story 3.6 fixed a bug where it didn't. **`runBulk` must replicate `$this->gatewayConfig = $gateway_config;` after the null-guard**, or every matched row's exception-policy read silently uses `manual_review` defaults regardless of configured policy. `gatewayConfigForCompany()` also gates on `reconciliation_enabled` (`:415`) and returns `null` if KuickPay isn't installed/eligible — `runBulk` should return `skipped` in that case.

### Late payment — the path this story makes reachable

Per the recorded control-flow note: on the single-inquiry path, `getReconcilable` excludes past-expiry Vouchers (`:328`) and the hourly expiry sweep transitions them to `expired`, so the validator's `late_payment` check is effectively dead there. **Bulk is date-based and matches by Consumer Number regardless of expiry**, so it can surface a confirmed payment for a Voucher whose `date_expires` is in the past:
- If that Voucher is still `pending`/`retry` (not yet swept): `persistEvidence` freshness guard passes → validator runs → `lateReason` fires (`paid_at > date_expires`) → `manual_review` + `late_payment`. **This is the materially-reachable late path — test it (Task 7).**
- If already swept to `expired`: `persistEvidence` freshness guard (`:256`, status must be `pending`/`retry`) fails → `manual_review` + `stale_voucher`.
- Either way the outcome is `manual_review` (safe) — never paid. Do not "fix" this into an auto-pay.

### Run summary fields (FR-22, UX-DR17)

UX-DR17 wants run type, status, checked, posted, unmatched, failed, skipped, manual-review counts, timestamps, and failure class. The current run table covers status, timestamps, and `total_checked/confirmed/retry/manual_review/expired/failed/errors`. This story adds `run_date`, `trigger_type='bulk'`, and `total_unmatched`. "Posted" is async (the posting cron sets it later), so the bulk run summary reports `total_confirmed` (queued-for-posting), not posted; Epic 4's run-detail view can join posting state. Keep counts in the existing `$counts` array shape (extend `initialCounts()`/`countOutcome()` analogue for the bulk path; add `total_unmatched`). Do not invent new top-level `error_class` enum values (the 8-value enum is fixed: `timeout`,`transport_error`,`credential_error`,`malformed_response`,`unknown_status`,`amount_mismatch`,`duplicate_reference`,`unmatched_reference`).

### Project Structure Notes

- Plugin-owned vs gateway-owned boundary holds: orchestration/persistence/posting/admin live under `plugins/kuickpay_reconcile/`; the SOAP client + parser + evidence DTO + redactor live under `components/gateways/nonmerchant/kuickpay/lib/`. The gateway never owns Voucher persistence, paid-state decisions, or posting. Approach A touches the gateway parser only to extend parsing (no policy/persistence leaks into the gateway).
- Naming: `kuickpay_` table prefix, `id` PK, `<entity>_id` FKs, Blesta `date_*` columns, explicit `idx_`/`uniq_` index names. New columns follow this.
- Schema lifecycle: additive via `columnExists()`+`ALTER` in a version-gated `upgrade()` block **and** mirrored into `createReconcileTables()` for fresh installs — both paths must agree (project-context: "verify both fresh schema/install and versioned upgrade").
- PHP 8.2 only; match the target file's existing (largely untyped-legacy mixed with typed) style; no `declare(strict_types=1)`; no PHP 8.3+ APIs. Use Blesta `Loader`, `Record`, `Input`, `Language::_()`, transactions, and event patterns already present.
- Amounts compared as normalized decimal strings / minor units, never PHP floats (NFR13) — already true in parser/validator; preserve it.

### References

- [Source: epics.md#Story-3.7] lines 689–705 (ACs); FR22 line 67; FR15 line 53; FR9 line 41; NFR4 line 93; NFR7 line 99; NFR9 line 103; NFR13 line 111; NFR14 line 113; Additional Requirements lines 119–144 (locks/bounding/retry/XML-safety); UX-DR18 line 186; UX-DR15 line 180; UX-DR17 line 184.
- [Source: architecture.md] Decided Invariant "Bulk reconciliation matches stored Consumer Numbers only" :85; one validation path :111; bulk XML safety + error classes :412, :569–579; posting contract :583, :585–591; service boundaries :785–786; cron lock/bounded/idempotent/resumable :451–459, :317; anti-patterns :652–661; run/item tables :336–337; Epic 4 admin controllers FR24–27 :842–846; implementation sequence :487–497.
- [Source: prd.md] FR-22 "Daily Bulk Reconciliation" :306–314; suffix prohibition + risk mitigation :477, :498–500.
- [Source: EXPERIENCE.md] IA "Bulk Reconciliation = admin manual action" line 35; run summary component line 73; bulk uses transaction-date input line 104; nav placement deferred line 38; UJ-3 line 178–187.
- [Source: prds/.../addendum.md] §A.1 "reconcile payments by Institution ID and transaction date as a daily/audit safety net" line 14; §B `BillPaymentBulkInquiryResult` XML dataset of `Consumer_Number` rows line 84.
- Live code anchors cited inline above (verified by direct read).

## Previous Story Intelligence (3.3–3.6)

- **3.3 (single inquiry):** established the lock → open run → bounded batch → `processVoucher` → persist → close → release pattern, the run/item/audit tables, the `KuickPayReconcileLockRepository`, the safe evidence→status mapping, and company-scoped `edit`. Bulk reuses all of it.
- **3.4 (validator):** `KuickPayEvidenceValidator` is immutable/side-effect-free, returns `KuickPayValidationResult` with bare machine reason codes; integrated into `persistEvidence` so `$vars['status']` and `diagnostic_summary` are reassigned/merged on failure. Reuse unchanged.
- **3.5 (posting):** `KuickPayPostingService` is the **only** transaction writer; re-reads + row-locks Voucher and invoice links, revalidates, posts inside one DB transaction, idempotent on `blesta_transaction_id`. Added `getPostable` (picks any `confirmed_unposted`) and the `$allowedStatuses` param to `validate()`. Bulk-confirmed Vouchers post via the existing `post_confirmed` cron — bulk must not post directly.
- **3.6 (exceptions):** `underpayment`/`overpayment` directional classification lives in the **parser**; `late_payment` in the **validator**; both fail closed to `manual_review`. Added `*_policy` gateway settings + `resolveExceptionStatus` (3-arg: `(string $currentStatus, array $reasons, ?array $gatewayConfig)`, `:342`) applied off the final merged `validation_errors`. **Fixed the config-threading bug** (`run()` now writes `$this->gatewayConfig` at `:104`) — `runBulk` must do the same. **Fixed the expiry-sweep race** with a status-guarded atomic `UPDATE` (`kuickpay_vouchers::expire`, `:418`) — relevant because bulk + expiry + reconcile crons can race the same Voucher; reusing the same `reconcile_pending` lock and `persistEvidence`'s status guard keeps bulk safe.

## Git Intelligence Summary

Recent Epic 3 commits (`feat/test/fix(kuickpay)`) show the established cadence: small typed additions, fixture-backed tests, conventional commits (`<type>(<scope>): <summary>`, ≤72 chars, lowercase imperative; types `feat|fix|docs|test|refactor|chore`). The latest, `c90d02ac fix(kuickpay): guard expiry sweep against concurrent status change`, is the concurrency fix referenced above. Branch from `main`; keep BMAD/_bmad-output doc changes out of runtime-code commits (project-context). Expect commits like `feat(kuickpay): add bulk reconciliation engine`, `feat(kuickpay): record bulk run summaries`, `test(kuickpay): cover bulk reconciliation matching`.

## Latest Tech Information

No external/web research required: this is an internal integration story. All dependencies already exist in-repo and versions are pinned by project-context (PHP 8.2, Blesta 6.0.0-b1, PHPUnit ~8.5, PHPCS ~4.0). XML safety (XXE/DOCTYPE/byte/row caps) is already implemented in `parseBulk` (`LIBXML_NONET`, DOCTYPE rejection, `MAX_ENVELOPE_BYTES`, `MAX_BULK_ROWS`) — preserve it; do not introduce a new XML library or relax `libxml` hardening.

## Testing

- **Runner (project-context-verified).** Use the external PHPUnit 8.5 runner, not `-c build/phpunit.xml`:
  - Gateway parser tests: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
  - Plugin service tests: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
- **Fixtures (already present).** `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-bulk-{matched-paid,mixed-multi-row,suffix-pair}.xml`, `.../ambiguous/bill-payment-bulk-{unmatched,late-partial,overpayment}.xml`, `.../malformed/bill-payment-bulk-malformed-xml.xml`. Add new fixtures only if a case isn't covered.
- **Existing bulk parser tests** in `KuickPayResponseParserTest.php` (e.g. `testBulkExactMatchDoesNotUseSuffix`, `testBulkUnmatchedRowFailsClosed`, `testBulkAmountMismatchFailsClosedForMatchedRow`, `testBulkMalformedDatasetReturnsSingleManualReviewEvidence`) **must keep passing** — Approach A changes must be backward compatible.
- **Coverage to add:** see Task 7. Use the DI constructor (`new KuickPayReconcileService(['client_factory'=>..., 'voucher_repository'=>..., 'gateway_config'=>...])`) to inject a fake SOAP client returning fixture transport outcomes and fake repositories — mirror `KuickPayReconcileServiceTest.php`'s existing setup.
- **Honesty rule (project-context + NFR).** `../tests` (sibling root suite) and a live DB may be unavailable; run the narrowest safe fallback (component PHPUnit + `php -l`) and **state exactly what ran**. Do not claim root PHPUnit coverage.

## Open Questions / Clarifications (resolve with Israr before or during dev)

- **OQ-1 (trigger scope) — RESOLVED (Israr, 2026-06-11): minimal trigger now.** Build `getActions()` + a thin admin controller (POST/auth/ACL/CSRF + result message) to invoke a bulk run for a date; defer run-list/detail views, the Manual Review queue, and search/filter to Epic 4 / Story 4.4. See Task 6.
- **OQ-2 (lock).** Reuse the single path's `reconcile_pending` lock (serializes bulk vs scheduled single — recommended, safest against same-Voucher contention) or a distinct `reconcile_bulk` lock (lets them run concurrently, relying on `persistEvidence` status guard + posting row-lock for safety)? Story assumes **reuse `reconcile_pending`**.
- **OQ-3 (bulk request field).** Does Story 0.1's captured KuickPay contract pin the exact `BillPaymentBulkInquiry` date field name(s)/format (single date vs range; `YYYYMMDD` vs `YYYY-MM-DD`)? `buildBulkRequest` needs this. If 0.1 didn't capture it, this is a contract gap to close (sandbox/echo) before live use — but unit tests can proceed against fixtures regardless.

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List
