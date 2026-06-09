# Story 3.3: Reconcile Pending Vouchers by Single Inquiry

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a finance operator,
I want Pending Vouchers checked on a schedule (and by an approved manual trigger) through single-reference KuickPay inquiry,
so that confirmed payments can move toward posting without staff re-entry, and temporary provider failures never corrupt invoice state.

## Acceptance Criteria

> Source BDD: epics.md:619-635 (Story 3.3). Architecture cron/lock requirements: architecture.md:441-483, 581-661. The two epic scenarios are decomposed below into implementable, testable ACs. Cite sources when implementing.

1. **Scheduled reconciliation cron exists and is registered.** A Blesta plugin cron task (key `reconcile_pending`, dir `kuickpay_reconcile`, `task_type` `plugin`, `type` `interval`) is registered during `install()` for fresh installs AND idempotently added during `upgrade()` for the already-shipped `1.0.0` install, and removed on `uninstall()` honoring `$last_instance`. The plugin's `cron($key)` entry dispatches `reconcile_pending` into the reconciliation service. [Source: architecture.md:449-458; epics.md:628; mass_mailer_plugin.php:34-47,324-339,407-431; domains_plugin.php:304-319 — idempotent upgrade add]

2. **Orchestration lives in a reusable service, not the cron glue.** `KuickPayReconcileService` owns inquiry orchestration (eligibility selection, per-voucher inquiry, evidence persistence, state transition, run/item recording). `cron()` and any future admin "Check Now" (Epic 4) and bulk run (3.7) call the service; the service does not depend on a controller/view/request. [Source: architecture.md:547,785,868; epics.md:622 "scheduled or by approved manual trigger"]

3. **Eligible-voucher selection is bounded and resumable.** Each run selects only vouchers that are reconciliation-eligible: `status IN ('pending','retry')`, not past expiry (`date_expires IS NULL OR date_expires >= today`), belonging to the run's `company_id`, and due for a recheck (e.g. `date_last_checked IS NULL OR date_last_checked older than the configured min-recheck window`, with `retry`-state backoff by `retry_count`), ordered by `date_last_checked ASC` (nulls first). The run honors a bounded batch size, a max-runtime ceiling, and a resume cursor (last processed voucher id) so a rerun continues rather than restarting. [Source: architecture.md:451-458 "bounded batch size, max runtime, retry limit, resume cursor/status"; phase-0-contract.md:39 "single-reference inquiry only on a bounded schedule with jitter/backoff; no unbounded polling loops"]

4. **Each eligible voucher is checked through single-reference `BillPaymentInquiry` and parsed with durable context.** For each voucher the service: builds the single-inquiry request field map from the durable voucher record; calls `KuickPaySoapClient::billPaymentInquiry()` using **inquiry** credentials; passes the transport outcome to `KuickPayResponseParser::parse($transportOutcome, $context)` with `$context` populated from the voucher (`expected_amount`, `expected_currency`, `expected_registration_number`, `expected_consumer_number`); and consumes the returned `KuickPayEvidence` only — never the raw `*Result` string or XML. [Source: epics.md:629; architecture.md:397,408,551,770-772; 3-2 Dev Notes line 154 "the plugin reconcile/voucher services (3.3/3.4/3.7) supply these from durable Voucher records"; 3-1 AC4 inquiry credential selection]

5. **Evidence is persisted and the normalized status is updated.** The service updates the voucher with: `date_last_checked` (now), `raw_status`, `error_class`, `evidence_hash`, redacted `diagnostic_summary`, and — when the evidence carries them — paid `amount`/`paid_at`/`kuickpay_reference`. It transitions the voucher lifecycle `status` per the **safe state-mapping table** in Dev Notes. Each per-voucher outcome is recorded as a `kuickpay_reconciliation_items` row tied to the run. [Source: epics.md:630 "updates last inquiry timestamp, normalized status, parsed evidence, and redacted diagnostics"; architecture.md:534,610-634]

6. **Temporary provider failure keeps vouchers safe — no invoice corruption.** When the inquiry returns a transport failure (`ok === false` with `error_class` `timeout`/`transport_error`, surfaced by the parser as evidence status `retry`), the voucher remains `pending` or transitions to `retry` per the retry policy (increment `retry_count`, bounded by a retry limit; record `date_last_checked` and redacted diagnostics). No invoice, transaction, or paid state is touched anywhere in this story. [Source: epics.md:632-635; NFR2 (epics.md:89) "KuickPay API failure must not corrupt invoice state"; architecture.md:414]

7. **`confirmed_unposted` is evidence, NOT payment — this story never posts.** When the parser returns `confirmed_unposted` (paid + amount/currency/reference matched), the voucher transitions to `confirmed_unposted` and the evidence is captured for the downstream pipeline. This story MUST NOT create or apply a Blesta transaction, set status `posted`, call `KuickPayPostingService`, or mutate any invoice/transaction. The pre-posting validation gate (invoice mapping, duplicate transaction reference, voucher staleness) is Story 3.4; posting under row locks is Story 3.5. [Source: architecture.md:349,526,583-593,650-661; epics.md:637-669; whmcs-live-implementation-evidence "Blesta must preserve the safer confirmed_unposted boundary before posting"]

8. **Cron concurrency and rerun safety.** A DB-backed lock (`kuickpay_reconcile_locks`) prevents concurrent reconciliation for the same company; stale locks (past `date_expires`) are reclaimable; the lock is always released (including on error). Reruns never double-process the same voucher within a run and never double-post (guaranteed because this story does not post). [Source: architecture.md:451-458,661 "Cron posting without row locks" anti-pattern; cron must have "DB-backed lock … stale-lock handling … no double-posting on rerun"]

9. **Durable run summary + audit.** Each run is recorded in `kuickpay_reconciliation_runs` (trigger type, start/end, status, cursor, per-outcome counts, redacted summary). Audit events are written through `KuickPayAuditService` for the reconciliation run and for voucher state transitions / retry decisions, using lower-dot event names with redacted payloads only. [Source: architecture.md:610-634 "Audit records are required for … reconciliation runs, state transitions, … retry decisions"; NFR4 (epics.md:93)]

10. **Redaction and parser-contract boundary are absolute.** No raw SOAP/XML, credential, PII, stack trace, or unredacted provider string is persisted to any voucher column, run/item/audit row, or log. All diagnostics pass the redaction boundary (`KuickPayRedactor` / the client's already-redacted outcome fields). Product code reads `KuickPayEvidence` only; no code in this story branches on a raw `*Result` string. [Source: architecture.md:373,397,551,608,634,648-661; FR27; NFR8]

11. **Schema lifecycle: fresh install AND upgrade are both correct and idempotent.** New tables (`kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_reconcile_locks`, `kuickpay_audit_events`) and the new `kuickpay_vouchers.retry_count` column are created in `install()` (fresh) and added idempotently in `upgrade()` (the live install is at `1.0.0` from Story 2.1, which created only `kuickpay_vouchers` + `kuickpay_voucher_invoices`). Plugin `config.json` version is bumped (e.g. `1.1.0`). [Source: architecture.md:449,63 (FR9 durable state); project-context.md:63,110 "Schema/runtime upgrade work … install/upgrade … idempotent"; 2-1 install baseline]

12. **`KuickpayVouchers::edit()` is hardened to be company-scoped (first mutating caller).** This story is the first to mutate vouchers, so it resolves the deferred 2-1 finding: `edit()` currently filters the UPDATE on `id` only. Scope the update by `company_id` so cross-tenant mutation is impossible, and only mutate vouchers within the run's company. [Source: deferred-work.md:52; plugins/kuickpay_reconcile/models/kuickpay_vouchers.php:80]

13. **Reconciliation respects enablement toggles.** A run is a no-op when the KuickPay gateway is not installed/enabled for the company, or when the `reconciliation_enabled` gateway setting is not truthy. Ineligible-currency vouchers are never created (Story 1.5), so no extra currency gate is required here, but only PKR vouchers should ever be inquired. [Source: kuickpay.php:122,274 (`reconciliation_enabled` meta); architecture.md FR2 "reconciliation toggles"; epics.md:27]

14. **Verification is honest and fixture-backed.** `php -l` on every changed PHP file; component-local reconcile/parsing tests driven by the existing inquiry fixtures (pending, paid-exact, amount-mismatch, expired, unknown) — never a live KuickPay call (no sandbox exists); state exactly what ran; do not claim root PHPUnit unless sibling `../tests` exists. [Source: architecture.md:477-483; project-context.md:69-81; 3-1/3-2 verification notes; NFR12]

## Tasks / Subtasks

- [ ] **Task 1 — Schema additions (install + idempotent upgrade) (AC: 11, 12)**
  - [ ] In `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`, extend `install($plugin_id)` to create the four new tables, mirroring the **exact table-creation style already used** there for `kuickpay_vouchers` (same `Record` schema-builder idiom — do not switch to raw `CREATE TABLE` unless install() already uses it). Tables and columns are specified in Dev Notes → "Schema additions". Use `kuickpay_` prefix, `id` PK, Blesta-style date columns, explicit `idx_`/`uniq_` index names, and avoid nullable-unique columns. [Source: architecture.md:528-538]
  - [ ] Add `retry_count INT UNSIGNED NOT NULL DEFAULT 0` to `kuickpay_vouchers` (in the fresh-install schema AND the upgrade migration).
  - [ ] Implement `upgrade($current_version, $plugin_id)` with a `version_compare($current_version, '1.1.0', '<')` guard that idempotently: creates the four new tables if absent, adds `retry_count` if absent (check `information_schema.COLUMNS` or guard the `ALTER`/`try-catch` so re-running is safe), and registers the new cron task (Task 2). Do not drop or rewrite existing tables. [Source: project-context.md:63,110; domains_plugin.php:304-319 idempotent upgrade pattern]
  - [ ] Bump `plugins/kuickpay_reconcile/config.json` `version` to `1.1.0`.
  - [ ] Keep `uninstall()` non-destructive for data tables (rollback policy: preserve voucher/audit/evidence tables); only the cron task run is removed there (Task 2). [Source: architecture.md:470-475]
  - [ ] Add language keys for any new admin/operator-facing strings (cron task name/description) via the plugin `language/en_us/` files — never hard-code. [Source: project-context.md:44]

- [ ] **Task 2 — Register and run the cron task (AC: 1, 2, 13)**
  - [ ] Add a private `getCronTasks()` returning the `reconcile_pending` task definition (keys: `key`, `dir`='kuickpay_reconcile', `task_type`='plugin', `name`, `description`, `type`='interval', `type_value`=<minutes default>, `enabled`=1), and a private idempotent `addCronTask()`/`addCronTasks()` helper that `getByKey()`-checks before `add()` + `addTaskRun()`. Wire it into `install()` and the `upgrade()` migration. Remove via `deleteTaskRun()` (+ `deleteTask()` only when `$last_instance`) in `uninstall()`. Follow `mass_mailer_plugin.php:324-339,407-431` and the `CronTasks` model API (`app/models/cron_tasks.php` `add`, `addTaskRun`, `getByKey`, `getTaskRunByKey`, `deleteTaskRun`, `deleteTask`). [Source: mass_mailer_plugin.php; domains_plugin.php:304-319]
  - [ ] Implement `cron($key)` on the plugin: when `$key === 'reconcile_pending'`, load `KuickPayReconcileService` and call its run entry for `Configure::get('Blesta.company_id')`. Keep `cron()` thin — orchestration is in the service (AC2). [Source: mass_mailer_plugin.php:34-47; auto_cancel_plugin.php:77-82]
  - [ ] In the service entry, short-circuit to a no-op (recording nothing or an empty run) when the gateway is not installed/enabled for the company or `reconciliation_enabled` is not truthy (AC13). Read the gateway via `GatewayManager::getInstalledNonmerchant($company_id, 'kuickpay')`; treat `false`/disabled as "skip". [Source: gateway_manager.php:234-298; kuickpay.php:122,274]

- [ ] **Task 3 — DB-backed reconcile lock (AC: 8)**
  - [ ] Add `KuickPayReconcileLockRepository` (plugin `lib/`) + a `kuickpay_reconcile_locks`-backed model, with `acquire($company_id, $lockName, $ttlSeconds): ?string` (returns an owner token or null if held by a live lock), `release($company_id, $lockName, $ownerToken): void`, and stale-lock reclaim (a lock past `date_expires` may be taken over). Use an atomic insert/update keyed on `uniq_(company_id, lock_name)` so two concurrent crons cannot both acquire. Optionally refresh `date_heartbeat` mid-run. [Source: architecture.md:451-458]
  - [ ] The service acquires the lock at run start and **always** releases it in a `finally`-equivalent path (including exceptions), so a crash cannot wedge reconciliation. [Source: architecture.md:457 stale-lock handling]

- [ ] **Task 4 — `KuickPayReconcileService` orchestration (AC: 2, 3, 4, 5, 6, 7, 9, 10)**
  - [ ] Constructor loads the durable models/repos (`KuickPayVoucherRepository`, the new run/item/lock repos, `KuickPayAuditService`) and is able to construct the gateway protocol classes (Task 6). Follow the plugin `Loader` conventions (`Loader::loadModels`, `Loader::loadComponents`, `Loader::load(...)`). [Source: project-context.md:43]
  - [ ] Run lifecycle: open a `kuickpay_reconciliation_runs` row (`trigger_type`, `status='running'`, `date_started`, `cursor`), acquire the lock, select the eligible batch (Task 5 query), iterate, accumulate counts, update the cursor as it progresses, then close the run (`status='completed'`/`'aborted'`, `date_completed`, counts, redacted `summary`) and release the lock. Enforce the max-runtime ceiling and batch size; if the ceiling is hit, close as a resumable partial run (cursor preserved) rather than running unbounded. [Source: architecture.md:451-458; AC3]
  - [ ] Per-voucher loop: build inquiry request (Task 6), call the client, parse with context, persist evidence + transition state (Task 7 mapping), write a `kuickpay_reconciliation_items` row, and emit audit events (Task 8). Wrap each voucher's processing so one voucher's error does not abort the whole batch — record the error against that item and continue. [Source: AC5, AC9; NFR2]
  - [ ] **Never** call `KuickPayPostingService`, create/apply a Blesta transaction, set `posted`, or touch invoices/`Transactions` (AC6/AC7). Add a code comment stating this boundary and citing 3.4/3.5 ownership.

- [ ] **Task 5 — Eligible-voucher query + voucher mutation hardening (AC: 3, 12)**
  - [ ] Add a method to `KuickPayVoucherRepository` (and/or `KuickpayVouchers` model) such as `getReconcilable(int $company_id, int $limit, int $afterId = 0, string $minRecheckBefore = null): array` implementing the AC3 eligibility predicate with the cursor (`id > $afterId`) and bounded `$limit`, ordered deterministically (`date_last_checked ASC, id ASC`). Use `Record` query-builder; allowlist any request/config-derived sort/field names (none should be request-controlled here). [Source: project-context.md:47; architecture.md:783]
  - [ ] Harden `KuickpayVouchers::edit(int $voucher_id, array $vars)` to scope the UPDATE by `company_id` (currently `id`-only — deferred-work.md:52). The service passes `company_id` so a voucher is only ever mutated within its own tenant. Remove the dead `if (empty($fields))` guard noted in the same finding if trivially safe, otherwise leave it. [Source: deferred-work.md:52; kuickpay_vouchers.php:80]
  - [ ] Add a focused state-transition helper (either a small `KuickPayVoucherStates` lib of state constants + an `allowedTransition(from,to): bool` map, or a private method on the service). Canonical states only: `pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`. This story only ever transitions FROM `pending`/`retry` and only TO `pending`/`retry`/`confirmed_unposted`/`expired`/`failed`/`manual_review` (never `posted`, never `cancelled`). [Source: architecture.md:339-347,535]

- [ ] **Task 6 — Cross-extension wiring: gateway meta + protocol classes (AC: 4, 10, 13)**
  - [ ] From the plugin, read the KuickPay gateway's decrypted meta via `GatewayManager::getInstalledNonmerchant($company_id, 'kuickpay')` and collapse `$gateway->meta` (array of `{key,value,encrypted}`, already decrypted by the model) into a `key => value` map (e.g. `Form->collapseObjectArray($gateway->meta, 'value', 'key')`). Required keys for inquiry: `wsdl_url`, `soap_timeout`, `institution_id`, `inquiry_username`, `inquiry_password`, `inquiry_same_as_voucher`, plus `voucher_username`/`voucher_password` for the same-as-voucher fallback, and `reconciliation_enabled`. [Source: gateway_manager.php:234-298,789-802; kuickpay.php:492-510 config shape; 3-1 Dev Notes line 127 settings keys]
  - [ ] `Loader::load()` the gateway protocol classes from the gateway lib path (the plugin already loads `KuickPayVoucherReferenceService` from a cross-extension `PLUGINDIR`-style path; do the symmetric load from the gateway dir, e.g. `Loader::load(COMPONENTDIR . 'gateways' . DS . 'nonmerchant' . DS . 'kuickpay' . DS . 'lib' . DS . 'KuickPayRedactor.php')` then `KuickPaySoapClient.php`, `KuickPayEvidence.php`, `KuickPayResponseParser.php`). Construct `new KuickPaySoapClient($config)` and `new KuickPayResponseParser(new KuickPayRedactor())`. **Note:** the gateway's `getSoapClient()` is `protected`, so the plugin builds the client from meta itself — it does not call the gateway method. Keep the construction in one place in the service so it is reused. [Source: kuickpay.php:83-86,492-510 (protected factory); kuickpay.php:586 (existing cross-extension `Loader::load`); architecture.md:778,866]
  - [ ] Build the single-inquiry request field map from the durable voucher. **Confirm the exact request key against the live WHMCS source** (`z-kuickpaycheck.php` builds the Consumer Number at line 104, then calls `BillPaymentInquiry` at line 241 — so the single inquiry may be keyed on the **Consumer Number**, not the Registration Number) and the 3-1 pass-through field-map contract. The voucher stores BOTH `registration_number` and `consumer_number`; supply whichever the provider expects as the lookup key. Regardless of the request key, always pass `expected_registration_number` AND `expected_consumer_number` (plus `expected_amount`, `expected_currency`) in the parser `$context`, because the parser matches the response field `[1]` against `expected_registration_number` and fails closed without context. Do NOT compute references/dates here — those are durable voucher fields from 2.1/2.2. [Source: whmcs-live-implementation-evidence.md:27-28; phase-0-contract.md:121,124; 3-2 AC6; 3-1 Dev Notes line 80,132]

- [ ] **Task 7 — Evidence persistence + safe state mapping (AC: 5, 6, 7, 10)**
  - [ ] Implement the **evidence → voucher** mapping exactly as the "Safe state-transition mapping" table in Dev Notes. Persist `date_last_checked`, `raw_status`, `error_class`, `evidence_hash`, redacted `diagnostic_summary`, and (only when present in evidence) `amount` (paid), `paid_at` → store in the appropriate column, and `kuickpay_reference` (`evidence->reference()`). Use `KuickpayVouchers::edit()` (now company-scoped) for the write. [Source: architecture.md:534,610-634; AC5]
  - [ ] Build `diagnostic_summary` ONLY from redacted/safe evidence fields (`redacted_trace_id`, `raw_status`, `error_class`, `evidence_hash`, validation error keys) — never `raw_result`, raw envelope, credentials, or PII. The `KuickPayEvidence::toArray()` shape is redaction-safe by contract (it excludes raw payloads); still do not serialize anything outside it. [Source: architecture.md:608,634; 3-2 AC1; 3-1 AC5]
  - [ ] Compare amounts using normalized decimal strings / minor units — never PHP floats — anywhere this story compares the evidence amount to the voucher amount (the parser already did the canonical match; if you re-check, reuse the same normalization). [Source: architecture.md:593,658]

- [ ] **Task 8 — Run/item records + audit (AC: 8, 9, 10)**
  - [ ] Add models/repos for `kuickpay_reconciliation_runs` and `kuickpay_reconciliation_items` (CRUD used by the service). Record one item per voucher processed: `run_id`, `voucher_id`, `prior_status`, `new_status`, `error_class`, `evidence_hash`, `redacted_trace_id`, `date_created`.
  - [ ] Add `KuickPayAuditService` + `KuickPayAuditRepository` writing to `kuickpay_audit_events`. Minimal write-path API e.g. `record(string $eventName, array $context): void` storing `company_id`, `voucher_id`/`run_id` (nullable), `event_name`, `redacted_trace_id`, `evidence_hash`, redacted `payload` (JSON), `date_created`. Event names use lower-dot notation: at least `reconciliation.run.started`, `reconciliation.run.completed`, `evidence.received`, `evidence.matched` (for `confirmed_unposted`), `evidence.rejected` (manual_review/failed), and a retry-decision event. Payloads contain redacted fields only. **Scope note:** this is the durable audit *write* path that 3.4/3.5 reuse; the admin-facing audit/log *viewing* surface is Story 4.5 — do not build admin views here. [Source: architecture.md:610-634,547; NFR4; see Risks / Open Items]

- [ ] **Task 9 — Tests + verification (AC: 14)**
  - [ ] Add component-local tests in the plugin test area (mirroring the gateway-side suite conventions) that drive `KuickPayReconcileService` (or its per-voucher unit) against the existing inquiry fixtures in `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`: `valid/bill-payment-inquiry-pending.xml`, `valid/bill-payment-inquiry-paid-exact.xml`, `ambiguous/bill-payment-inquiry-amount-mismatch.xml`, `valid/bill-payment-inquiry-expired.xml`, and an unknown/malformed case. Inject a fake SOAP client (the 3-1 client takes a `$soapClientFactory` callable; or stub the inquiry to return a canned transport outcome) — **never** a live call (no sandbox). [Source: 3-1 constructor `$soapClientFactory`; 3-2 fixtures; project-context.md:79; NFR11]
  - [ ] Assert: pending→stays `pending` + `date_last_checked` set; paid-exact→`confirmed_unposted` with paid evidence stored AND **no** transaction created / no `posted`; amount-mismatch→`manual_review` with `error_class='amount_mismatch'`, no posting; expired→`expired`; transport timeout→`retry` with `retry_count` incremented and invoice untouched; lock prevents a second concurrent run; rerun resumes via cursor and does not reprocess; no raw/credential/PII string appears in any persisted column or item/audit row. [Source: AC5-AC10]
  - [ ] Run `php -l` on every changed PHP file. Run the component suite with `--bootstrap tests/bootstrap.php tests` (NOT `-c build/phpunit.xml` — that runner is broken per project-context.md:74). If `php`/`ext-soap` is unavailable in this checkout, say so and run under PHP 8.2 + PHPUnit ~8.5 before merge; never claim a lint/suite that did not run. [Source: project-context.md:73-74,81; deferred-work.md:44]

## Dev Notes

### Scope boundary (READ FIRST — this prevents the biggest possible disaster)

**This story records evidence and updates voucher state. It does NOT pay invoices.**

- ✅ IN scope: cron registration + handler; `KuickPayReconcileService`; eligible-voucher selection; calling `BillPaymentInquiry` (inquiry creds) via the 3-1 client; parsing via the 3-2 parser with durable context; persisting normalized evidence + `date_last_checked` + redacted diagnostics onto the voucher; the safe state transitions `pending`/`retry` → `pending`/`retry`/`confirmed_unposted`/`expired`/`failed`/`manual_review`; DB-backed lock; bounded/resumable run with run+item records; audit write-path; new schema (install + idempotent upgrade); company-scoping `edit()`.
- ❌ OUT of scope (do NOT implement here): creating/applying any Blesta transaction; setting `posted`; calling `KuickPayPostingService`; the full pre-posting validation gate — invoice-mapping check, duplicate transaction-reference de-dup, voucher-staleness check (that is **Story 3.4**); posting under row locks (**Story 3.5**); expiry/late/partial/overpayment policy beyond "evidence says expired → `expired`" (**Story 3.6**); bulk/date reconciliation (**Story 3.7**); admin "Check Now" button, voucher list, audit/diagnostics VIEWS (**Epic 4 / 4.5**).
- **`confirmed_unposted` ≠ paid.** Architecture: "Only `posted` may imply that Blesta invoice payment succeeded. `confirmed_unposted` is evidence, not payment completion." (architecture.md:349). WHMCS posts immediately on status `00`; Blesta deliberately stops at `confirmed_unposted` and posts only later through the isolated posting service.

### Why the parser already does "validation" (and what 3.4 still adds)

The 3-2 parser, **given context**, already enforces amount-equality (minor units, never floats), `currency == PKR`, and exact reference identity — emitting `confirmed_unposted` only when all pass, else `amount_mismatch`/`unmatched_reference`/`manual_review` (3-2 AC6, line 73). So when you supply the voucher's expected values in `$context`, the `KuickPayEvidence.status()` you get back is already the safe, fail-closed classification. **3.3's job is to persist that result and transition state — not to re-derive it.** Story 3.4 adds the checks the parser *cannot* do because it has no DB: invoice-mapping correctness, cross-voucher/Blesta duplicate-transaction-reference de-dup, and voucher-staleness — then 3.5 posts under locks with a final re-validation. This layering is why setting `confirmed_unposted` here is safe: nothing pays an invoice until 3.4 validates and 3.5 posts.

### Existing code you MUST integrate with (read; signatures are real — do not guess)

**Gateway protocol library** — `components/gateways/nonmerchant/kuickpay/lib/` (legacy global classes, load via `Loader::load`):
- `KuickPaySoapClient`:
  - `__construct(array $config, callable $soapClientFactory = null)` — config keys: `wsdl_url`, `soap_timeout`, `institution_id`, `voucher_username`, `voucher_password`, `inquiry_username`, `inquiry_password`, `inquiry_same_as_voucher`, `logging_enabled`.
  - `billPaymentInquiry(array $inquiryParams): array` — selects inquiry creds (falls back to voucher creds when `inquiry_same_as_voucher === 'true'`); retries up to 3× on transport-only failures.
  - **Transport outcome array** (the same shape for every op): `['ok'=>bool, 'operation'=>string, 'raw_result'=>?string, 'raw_envelope'=>?string, 'error_class'=>null|'timeout'|'transport_error', 'fault'=>?string, 'redacted_request'=>array, 'redacted_trace_id'=>string, 'attempts'=>int]`. Transport failure ⇔ `ok===false && error_class∈{timeout,transport_error}`. A body that arrived (even a SOAP fault/app error) ⇒ `ok===true, error_class===null` and the parser decides meaning from `raw_result`.
- `KuickPayResponseParser`:
  - `__construct(KuickPayRedactor $redactor = null)`
  - `parse(array $transportOutcome, array $context = []): KuickPayEvidence` — for `InsertVoucher`/`BillPaymentInquiry` (single). Throws `InvalidArgumentException` if handed a `BillPaymentBulkInquiry` outcome (use `parseBulk()` — bulk is Story 3.7, not this story).
  - `$context` keys you supply from the voucher: `expected_amount` (decimal string), `expected_currency` (default `PKR`), `expected_registration_number`, `expected_consumer_number`.
- `KuickPayEvidence` (immutable): getters `status()`, `errorClass()`, `reference()`, `consumerNumber()`, `registrationNumber()`, `amount()`, `currency()`, `paidAt()`, `rawStatus()`, `redactedTraceId()`, `evidenceHash()`, `validationErrors()`; helpers `isConfirmedUnposted()`, `toArray()` (12 contract keys, redaction-safe, excludes `raw_result`). `status()` ∈ {`pending`,`retry`,`confirmed_unposted`,`failed`,`expired`,`manual_review`}. `errorClass()` ∈ {null,`timeout`,`transport_error`,`credential_error`,`malformed_response`,`unknown_status`,`amount_mismatch`,`duplicate_reference`,`unmatched_reference`}.
- `KuickPayRedactor`: `traceId(): string`, `redactArray(array): array`, `redactEnvelope(string): string`. You mostly rely on the client's already-redacted outcome fields; use this only if you build extra diagnostics.

**Plugin durable layer** — `plugins/kuickpay_reconcile/`:
- Plugin class `KuickpayReconcilePlugin` (`kuickpay_reconcile_plugin.php`): `install($plugin_id)` currently creates ONLY `kuickpay_vouchers` + `kuickpay_voucher_invoices`; `upgrade()`/`uninstall()` are empty. **No `getCronTasks()`/`cron()` yet.** `config.json` version `1.0.0`, no cron tasks declared.
- `kuickpay_vouchers` table ALREADY HAS the evidence columns you need: `status` (enum, all 8 states), `currency`, `amount`, `registration_number`, `consumer_number`, `date_due`, `date_expires`, `date_created`, `date_updated`, `date_posted`, **`date_last_checked`**, **`error_class`**, **`raw_status`**, **`evidence_hash`**, **`kuickpay_reference`**, **`blesta_transaction_id`**, **`diagnostic_summary`**, `admin_notes`. **Missing:** a `retry_count`/attempt column (add it — Task 1) and a dedicated `paid_at` column (decide: store paid date into `diagnostic_summary`/a new `date_paid` column — prefer adding `date_paid DATETIME NULL` in the same migration for clean 3.4/3.5 consumption). Unique keys: `uniq (company_id, consumer_number)`, `uniq (company_id, registration_number)`.
- `KuickpayVouchers` model methods: `add(array)`, `edit(int $voucher_id, array $vars)` (⚠️ `id`-only scoped — harden in Task 5), `get(int)`, `getByConsumerNumber(string,int)`, `getByRegistrationNumber(string,int)`, `getPendingByInvoiceId(int,int)`, `getList(array $filters,int $page,array $order_by)`.
- `KuickPayVoucherRepository`: `create(array,array): ?int`, `getPendingByInvoiceId(int,int): ?stdClass`, `getWithInvoices(int): ?array`. Add your `getReconcilable(...)` here.
- NOT yet present (you create them): `KuickPayReconcileService`, `KuickPayReconcileLockRepository`, `KuickPayAuditService`, `KuickPayAuditRepository`, `KuickPayVoucherStates`, and the run/item/lock/audit models + tables.

### Blesta plugin cron pattern (concrete, from this codebase)

`CronTasks` model (`app/models/cron_tasks.php`): `add(array $vars)` (`key`,`task_type`,`dir`,`name`,`description`,`is_lang`,`type`); `addTaskRun($task_id, array $vars)` (`enabled` + either `interval` minutes OR `time` HH:MM:SS; sets `company_id` from `Blesta.company_id`); `getByKey($key,$dir,$task_type)`; `getTaskRunByKey($key,$dir,$system,$task_type)`; `deleteTaskRun($task_run_id)`; `deleteTask($task_id,$task_type,$dir)`.

Idempotent add (reuse for install AND upgrade):
```php
private function addCronTask($task) {
    Loader::loadModels($this, ['CronTasks']);
    $task_id = ($t = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']))
        ? $t->id : $this->CronTasks->add($task);
    if (($errors = $this->CronTasks->errors())) { $this->Input->setErrors($errors); return false; }
    if ($task_id) {
        $this->CronTasks->addTaskRun($task_id, ['enabled' => $task['enabled'], $task['type'] => $task['type_value']]);
    }
    return !$this->CronTasks->errors();
}
```
Execution: `public function cron($key) { if ($key === 'reconcile_pending') { /* load + run service */ } }`.
Uninstall: `getTaskRunByKey()` → `deleteTaskRun()`; and only when `$last_instance`, `getByKey()` → `deleteTask()`.
[Source: mass_mailer_plugin.php:34-47,324-339,407-431; domains_plugin.php:304-319; auto_cancel_plugin.php:77-82]

### Reading decrypted gateway meta from the plugin

`GatewayManager::getInstalledNonmerchant($company_id, 'kuickpay')` returns a gateway stdClass whose `->meta` is `[{key,value,encrypted}, ...]` with encrypted values **already decrypted** (model calls `systemDecrypt()` on read — gateway_manager.php:789-802). Collapse to a map and feed the `KuickPaySoapClient` config. Returns `false` if not installed → treat as "skip run" (AC13). [Source: gateway_manager.php:234-298,789-802; kuickpay.php:492-510 for the exact config keys the client expects]

### Safe state-transition mapping (evidence.status → voucher.status)

Apply ONLY when current voucher status is `pending` or `retry`:

| `KuickPayEvidence::status()` | `errorClass()` | Voucher transition | Also persist |
|---|---|---|---|
| `pending` | null | stay `pending` | `date_last_checked`, `raw_status`, diagnostics |
| `retry` | `timeout`/`transport_error` | → `retry` (increment `retry_count`; if `retry_count` exceeds limit, policy may hold at `retry` or escalate to `manual_review` — see Risks / Open Items) | `date_last_checked`, `error_class`, diagnostics; **no invoice touch** |
| `confirmed_unposted` | null | → `confirmed_unposted` | paid `amount`, `date_paid`, `kuickpay_reference`, `evidence_hash`, `raw_status`, diagnostics |
| `expired` | null | → `expired` | `date_last_checked`, `raw_status`, diagnostics |
| `manual_review` | `amount_mismatch`/`unmatched_reference`/`duplicate_reference`/`unknown_status`/`malformed_response`/`credential_error`/null | → `manual_review` | `error_class`, `evidence_hash`, `raw_status`, diagnostics |
| `failed` | `credential_error`/etc. | → `failed` (or `manual_review` per policy) | `error_class`, diagnostics |

Never transition to `posted` or `cancelled` here. Never transition a voucher already in `confirmed_unposted`/`posted`/`failed`/`expired`/`manual_review`/`cancelled` (those are not eligible — the query excludes them).

### Schema additions (mirror the existing install() Record style; `kuickpay_` prefix; explicit index names)

- `kuickpay_vouchers`: ADD `retry_count INT UNSIGNED NOT NULL DEFAULT 0`; ADD `date_paid DATETIME NULL` (clean home for evidence `paid_at`).
- `kuickpay_reconciliation_runs`: `id` PK; `company_id`; `trigger_type` ENUM('cron','manual'); `status` ENUM('running','completed','aborted','failed'); `date_started` DATETIME; `date_completed` DATETIME NULL; `cursor` INT UNSIGNED NULL; count columns `total_eligible/total_checked/total_confirmed/total_retry/total_manual_review/total_expired/total_failed/total_errors` (INT UNSIGNED DEFAULT 0); `summary` TEXT NULL; `idx_kuickpay_runs_company` (company_id), `idx_kuickpay_runs_status` (status).
- `kuickpay_reconciliation_items`: `id` PK; `run_id`; `voucher_id`; `prior_status` VARCHAR(32); `new_status` VARCHAR(32); `error_class` VARCHAR(32) NULL; `evidence_hash` VARCHAR(24) NULL; `redacted_trace_id` VARCHAR(32) NULL; `date_created` DATETIME; `uniq_kuickpay_items_run_voucher` (run_id, voucher_id), `idx_kuickpay_items_voucher` (voucher_id).
- `kuickpay_reconcile_locks`: `id` PK; `lock_name` VARCHAR(64); `company_id`; `owner_token` VARCHAR(64); `date_acquired` DATETIME; `date_expires` DATETIME; `date_heartbeat` DATETIME NULL; `uniq_kuickpay_locks_company_name` (company_id, lock_name).
- `kuickpay_audit_events`: `id` PK; `company_id`; `voucher_id` INT UNSIGNED NULL; `run_id` INT UNSIGNED NULL; `event_name` VARCHAR(64); `redacted_trace_id` VARCHAR(32) NULL; `evidence_hash` VARCHAR(24) NULL; `payload` TEXT NULL; `date_created` DATETIME; `idx_kuickpay_audit_company` (company_id), `idx_kuickpay_audit_voucher` (voucher_id), `idx_kuickpay_audit_event` (event_name).

All created in `install()` (fresh) and added idempotently in `upgrade()` under the `< 1.1.0` guard. [Source: architecture.md:333-353,528-538; FR9; project-context.md:63,110]

### Architecture compliance (guardrails)

- One SOAP home / one parse home: external calls only via `KuickPaySoapClient`; raw `*Result`/XML interpreted only in `KuickPayResponseParser`. The reconcile service branches on `KuickPayEvidence`, never on raw strings. [architecture.md:397,400,408,551,648-661]
- Ownership: the plugin owns durable Voucher state, reconciliation, retry, audit, schema, cron. The gateway owns checkout/display only. Do not move reconcile logic into the gateway. [architecture.md:518-526,665-673]
- Posting boundary: only `KuickPayPostingService` (3.5) may create/apply a Blesta transaction. Cron posting without row locks is a named anti-pattern — but this story does not post at all. [architecture.md:583-593,650-661]
- Validation/persistence in models/services; use `Loader`, `Input`, `Record`, transactions; language files for strings; preserve PHP 8.2 + legacy-vs-namespaced style of each file you touch. [project-context.md:39-49,51-66]
- Untrusted cron payload: validate response shape and company context; idempotency before mutating. [project-context.md:124]

### Previous Story Intelligence

- **3.2 (parser, done):** `parse()` ALWAYS returns one `KuickPayEvidence`; `toArray()` is exactly the 12 contract keys (no `operation`) and downstream 2.3/3.3/3.5 rely on that shape. Without expected context a `00` row fails closed to `manual_review` — so you MUST pass `$context` from the voucher. Inquiry fixtures already live at `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,ambiguous,malformed}/`. [3-2 ACs 1,6; Dev Notes 154]
- **3.1 (SOAP client, done):** client is pass-through (injects creds + institution id; caller supplies the field map); never decides paid/not-paid; inquiry retried ≤3× on transport errors only; `InsertVoucher` never auto-retried. Transport-level credential rejection surfaces as `transport_error` (in-body `credential_error` is the parser's). A `$soapClientFactory` callable enables test injection — use it instead of live calls. [3-1 ACs 4,8; Dev Notes 127,176]
- **2.1 (vouchers, done):** voucher schema + repository established; `edit()` is `id`-only scoped (harden now — you are the first mutator, deferred-work.md:52); the deterministic-reference/reuse edge cases are Epic 2.4's, not yours. Class casing is load-bearing: `KuickpayReconcilePlugin`, model `KuickpayVouchers`. Lib classes load via `Loader::load(dirname(__FILE__).DS.'lib'.DS.'<File>.php')`.
- **2.2 (reg/consumer numbers, ready-for-dev/in flight):** owns how `registration_number`/`consumer_number` are generated; you only READ them from the durable voucher. If 2.2 is not yet merged when you start, the values still come from the voucher columns — do not recompute them.

### Git Intelligence

Recent KuickPay commits set the conventions you extend: `feat(kuickpay): wrap soap operations behind safe client` (3.1), `feat(kuickpay): normalize soap responses into evidence` (3.2), `feat(kuickpay): create vouchers in build process` / `display voucher reference` (2.1), `fix(kuickpay_reconcile): use format error keys for voucher link ids`. Commit style: `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars; allowed types `feat|fix|docs|test|refactor|chore`. Suggested: `feat(kuickpay_reconcile): reconcile pending vouchers by single inquiry`. Keep BMad/docs artifact edits out of the implementation commit. [project-context.md:99-104]

### Testing Standards Summary

- Component-local PHPUnit (~8.5) in the plugin/gateway test tree; run via `--bootstrap tests/bootstrap.php tests` (NOT the broken `-c build/phpunit.xml`); external runner at `/root/tools/phpunit-8.5/vendor/bin/phpunit` if installed. No new root `tests/`. No live KuickPay calls — inject a fake client and use the existing inquiry fixtures. `php -l` every changed PHP file. State exactly what ran; if `php`/`ext-soap` is absent here, run under PHP 8.2 before merge and say so. [project-context.md:69-81; deferred-work.md:44; 3-1/3-2 records]

### Project Structure Notes

- New plugin files: `lib/KuickPayReconcileService.php`, `lib/KuickPayReconcileLockRepository.php`, `lib/KuickPayAuditService.php`, `lib/KuickPayAuditRepository.php`, optional `lib/KuickPayVoucherStates.php`; `models/kuickpay_reconciliation_runs.php`, `models/kuickpay_reconciliation_items.php`, `models/kuickpay_reconcile_locks.php`, `models/kuickpay_audit_events.php`; edits to `kuickpay_reconcile_plugin.php`, `models/kuickpay_vouchers.php`, `lib/KuickPayVoucherRepository.php`, `config.json`, `language/en_us/*`. These match the architecture's prescribed plugin tree (architecture.md:696-738) — names like `KuickPayReconcileService`, `KuickPayReconcileLockRepository`, `KuickPayAuditService`, `KuickPayAuditRepository` are the architecture's own suggested service names (architecture.md:547). Gateway files are NOT modified by this story.
- Detected variance: the architecture tree also lists `KuickPayVoucherNormalizer` and a separate `KuickPayPostingService`; those belong to 3.4/3.5 — do not create them here.

### Risks / Open Items (surface in Dev Agent Record)

- Single-inquiry REQUEST key (Consumer Number vs Registration Number) is inferred from WHMCS source positions, not quoted — confirm before wiring (Task 6). Mitigation: pass both expected values to the parser context regardless.
- Audit table/service scope vs Story 4.5 — see Open Questions; if descoped, fall back to recording reconciliation history in `kuickpay_reconciliation_runs/items` only.
- `confirmed_unposted` set here vs in 3.4 — see Open Questions; the chosen design (set here, validate-before-post in 3.4) is documented above and is safe because nothing posts until 3.5.
- `ext-soap`/PHP-8.2 toolchain availability in this checkout was contradictory for 3.1 — verify locally and reconcile the record.

### References

- [Source: epics.md:619-635] — Story 3.3 BDD; [epics.md:637-705] — 3.4/3.5/3.6/3.7 boundaries
- [Source: architecture.md:327-355,381-414,441-483,518-547,581-661,663-738,862-892] — data/API/infra/ownership/parser/posting/audit/structure/integration
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php; models/kuickpay_vouchers.php; lib/KuickPayVoucherRepository.php] — existing durable layer
- [Source: components/gateways/nonmerchant/kuickpay/lib/{KuickPaySoapClient,KuickPayResponseParser,KuickPayEvidence,KuickPayRedactor}.php] — protocol contract
- [Source: app/models/cron_tasks.php; app/models/gateway_manager.php:234-298,789-802] — cron + gateway-meta APIs
- [Source: plugins/mass_mailer/mass_mailer_plugin.php; plugins/domains/domains_plugin.php:304-319] — cron register/upgrade precedent
- [Source: docs/kuickpay/phase-0-contract.md; docs/kuickpay/whmcs-live-implementation-evidence.md] — confirmed provider contract
- [Source: _bmad-output/implementation-artifacts/deferred-work.md:52] — `edit()` company-scoping (resolve here)
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, testing/workflow/secret-safety rules

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
