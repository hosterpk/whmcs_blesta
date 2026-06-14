---
baseline_commit: 039f5637b5388a3851ce3283d56a01b3165bf3a7
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.2: Schema-Level Active-Context Concurrency Guard

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an architect,
I want a schema-enforced unique active payment context per invoice set,
so that two concurrent submissions can never mint two pending Vouchers for the same invoice set — closing the `context_key`/`active_context_key` residual that has been named and deferred across four epics (2-2 → 2-3 → 2-4 → 3-4 → 3-5 → Epic 4).

## Acceptance Criteria

> Sourced from `epics.md` Story 5.2 (lines 876–897); architecture **Data Architecture** "active payment context" idempotency
> (lines 351–353), **Naming Patterns / DB conventions** (lines 528–538), **Infrastructure & Deployment** install/upgrade/rollback
> (lines 441–483); NFR9 (epics.md:103); FR9 (epics.md:41); `deferred-work.md` 2-4 schema residual (line 20), 2-3 concurrent
> double-submit (line 89), 3-4 double-pending (line 95); Epic 3 retro AI-5 (`epic-3-retro-2026-06-11.md` lines 69, 90, 130, 187),
> Epic 4 retro AI-5 / Pattern #6 (`epic-4-retro-2026-06-13.md` lines 64, 85, 122, 181).

1. **(AC1 — Schema guard exists and proves single-active-context, against the real DB)**
   **Given** the `kuickpay_vouchers` schema,
   **When** the migration adds a `context_key` column (deterministic from the company-scoped sorted invoice-id set) plus a
   status-derived `active_context_key` and a **company-scoped unique key** (`(company_id, active_context_key)`), designed so
   non-active vouchers carry a `NULL` `active_context_key` and therefore do not collide (the standard MySQL multiple-NULLs-in-a-unique-index
   idiom — **not** the nullable-unique trap that the architecture forbids for *optional references*),
   **Then** two concurrent same-invoice-set issuance attempts resolve to **exactly one** active pending Voucher — the loser's
   `INSERT` fails on the unique key and the existing create-null fall-through returns the winner's pending Voucher — **proven against
   the real DB**, not only against fakes.

2. **(AC2 — Closes the double-allocation/double-pending residual at the schema layer; un-gating becomes safe)**
   **Given** the new schema guarantee,
   **When** `replace` / concurrent issuance (gated off since Story 2.4) is reconsidered,
   **Then** any future un-gating is **only** permitted behind this unique key (this story builds and proves the key; it does **not**
   flip the production gate — see Dev Notes "Scope boundary: un-gating is enabled, not performed"),
   **And** the application-layer double-allocation residual at Stories 3.4/3.5 (two distinct *pending* vouchers on one invoice set) is
   closed at the **schema layer** for the exact-invoice-set case, not just the posting-time row lock.

3. **(AC3 — Upgrade and fresh install backfill safely and are verified end to end)**
   **Given** an upgrade from the current installed version,
   **When** the migration runs against the real DB,
   **Then** existing rows backfill `context_key` with the **same algorithm** the application uses (so a later concurrent insert for an
   existing invoice set correctly collides), pre-existing active-context duplicates (if any) are detected and resolved **before** the
   unique key is added, the unique key is then added without error, and **both** the fresh-`install()` path and the versioned `upgrade()`
   path produce the identical final schema — verified end to end (before/after `SHOW CREATE TABLE` evidence, idempotent re-run).

_Closes: Epic 3→4 retro AI-5 (carried 4×); `deferred-work.md` 2-4 schema residual, 2-3 concurrent double-submit, 3-4 double-pending;
architecture "active payment context" idempotency; NFR9._

## Tasks / Subtasks

- [x] **Task 1 — Confirm the live DB engine supports a STORED generated column + a unique index on it (AC1; pre-flight, no code)**
  - [x] 1.1 On the real stack, run `SELECT VERSION();` (via `/usr/bin/mysql` using `config/blesta.php` creds — **never copy the creds or the output's host/user into any artifact**, state only the engine + version). Confirm MySQL ≥ 5.7.8 **or** MariaDB ≥ 10.2 — both support `GENERATED ALWAYS AS (...) STORED` and a `UNIQUE` index over a (nullable) generated column with the multiple-NULLs-allowed semantics this story relies on.
  - [x] 1.2 If, and only if, the engine cannot index a generated column the way this design needs, fall back to the **application-maintained** variant (a plain nullable `active_context_key` the model sets to `context_key` on create and `NULL` on every transition into `expired`/`cancelled`) and document the downgrade from "schema-enforced" to "application-enforced + unique-index-backed" in the evidence. **Prefer the generated column** — the AC asks for a *schema-derived* key. Decide and record which path you took.

- [x] **Task 2 — Add the schema migration: `context_key`, the status-derived `active_context_key`, and the company-scoped unique key (AC1, AC3)**
  - [x] 2.1 Bump the plugin version in `plugins/kuickpay_reconcile/config.json` from `1.8.0` → `1.9.0`. (This is the FIRST upgrade branch in Epic 5 that runs real SQL; the 1.5.0–1.8.0 branches were intentionally empty permission/nav re-syncs — `kuickpay_reconcile_plugin.php:125–157`.)
  - [x] 2.2 Write ONE shared, idempotent private method (e.g. `addActiveContextGuard()`) in `kuickpay_reconcile_plugin.php`, modelled on the existing `addVoucherEvidenceColumns()` / `addBulkReconciliationColumns()` raw-`Record->query()` migration pattern. It must, each step guarded so a re-run is a no-op:
    - **2.2a** `ALTER TABLE kuickpay_vouchers ADD context_key VARCHAR(64) NULL DEFAULT NULL AFTER consumer_number` — **nullable** so the `ADD` succeeds on the already-populated table (avoid a NOT-NULL-without-default failure on existing rows). Guard with `columnExists('kuickpay_vouchers','context_key')`.
    - **2.2b** **Backfill** existing rows with the algorithm-matching value (see Task 4 for the exact algorithm): `UPDATE kuickpay_vouchers v JOIN (SELECT voucher_id, SHA1(GROUP_CONCAT(DISTINCT invoice_id ORDER BY invoice_id SEPARATOR ',')) AS ck FROM kuickpay_voucher_invoices GROUP BY voucher_id) m ON m.voucher_id = v.id SET v.context_key = m.ck WHERE v.context_key IS NULL`. (Vouchers with zero links — which should not exist, since `repository->create()` rolls back if any link insert fails — keep `context_key = NULL`, which is intentionally **not** an active claim.)
    - **2.2c** **Pre-flight duplicate resolution (before the unique key):** detect rows that would collide once the key is live — `SELECT company_id, context_key, COUNT(*) c FROM kuickpay_vouchers WHERE status NOT IN ('expired','cancelled') AND context_key IS NOT NULL GROUP BY company_id, context_key HAVING c > 1`. If any group is returned, resolve it deterministically **before** 2.2e (never silently drop the unique key): keep the most-recent (highest `id`) active row, and for the older colliding active rows — **never touch `posted`** — route them to `cancelled` so their generated `active_context_key` releases to `NULL` and an operator can investigate from the preserved row history (mirrors the fail-closed NFR9 posture). Record what was resolved in the evidence. On the current live data the expected count is **zero** (1 `pending` + 1 `manual_review` row at 5-1 time; confirm they do not share an invoice set), so this branch should not fire — but the migration must be correct if it ever does.
    - **2.2d** `ALTER TABLE kuickpay_vouchers ADD active_context_key VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN status IN ('expired','cancelled') THEN NULL ELSE context_key END) STORED` — guard with `columnExists(...,'active_context_key')`. **Active set rationale (the one money-safety design decision — see Dev Notes):** every status holds the context slot EXCEPT the two customer-re-payable terminal states `expired` and `cancelled` (per the UI Display-State Matrix, those are the only two that say "generate/pay again"). `posted` keeps the slot forever (the invoice set is paid); `failed`/`manual_review`/`confirmed_unposted`/`retry`/`pending` keep it while the claim is live or under admin resolution.
    - **2.2e** `ALTER TABLE kuickpay_vouchers ADD UNIQUE KEY uniq_kuickpay_vouchers_active_context (company_id, active_context_key)` — guard with a new `indexExists('kuickpay_vouchers','uniq_kuickpay_vouchers_active_context')` helper (`SHOW INDEX FROM ... WHERE Key_name = ?`). Multiple `NULL` `active_context_key` values are permitted by the unique index, so terminal/released rows never collide.
  - [x] 2.3 Wire `addActiveContextGuard()` into BOTH paths so fresh-install and upgrade converge on the identical schema (architecture requires both fresh-install AND upgrade verification, lines 478–483):
    - In `upgrade()`, add `if (version_compare($current_version, '1.9.0', '<')) { $this->addActiveContextGuard(); }`.
    - In `install()`, call `$this->addActiveContextGuard()` after the `kuickpay_vouchers` / `kuickpay_voucher_invoices` `create(...)` calls (the backfill `UPDATE` is then a harmless no-op on an empty table). **Do not** try to express the generated column or its unique key through the Blesta `Record->setField()`/`setKey()` builder — it does not support `GENERATED ALWAYS AS`; the raw-`query()` ALTER path is the only correct mechanism, and sharing the one method keeps the two install routes from drifting.
  - [x] 2.4 Add the `indexExists()` helper next to `columnExists()`/`enumContains()` (same `SHOW …` + `fetch()` shape).

- [x] **Task 3 — Teach the model about `context_key` (AC1)**
  - [x] 3.1 In `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`, add `'context_key'` to the `FIELDS` allowlist (`:24–48`). **Do NOT add `active_context_key`** — it is a STORED generated column that MySQL computes; writing it would error, and the existing `FIELDS`-intersection in `add()`/`edit()`/`transition()` already excludes anything not listed, so transitions automatically let MySQL recompute `active_context_key` from the new `status`.
  - [x] 3.2 Add a `context_key` validation rule in `getRules()` (`:717–784`): required / non-empty (`isEmpty`+`negate`), with a new `KuickpayVouchers.!error.context_key.empty` language key in `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php`. Every newly created voucher MUST carry a `context_key`; the DB column stays nullable only to keep the migration/backfill and the (non-existent) link-less edge safe.
  - [x] 3.3 Confirm no model read/transition method needs to change: `edit()`/`transition()`/`expire()` never pass `context_key` (so it is preserved), and `active_context_key` is generated — verify by inspection and note it.

- [x] **Task 4 — Compute and pass `context_key` from the issuance path, matching the backfill exactly (AC1, AC3)**
  - [x] 4.1 In `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php::getOrCreateForInvoiceContext()`, compute `context_key` from the **canonical, de-duplicated, ascending-sorted integer invoice-id set** already derived there (`$invoiceIds`, built from `normalizeContextInvoiceAmounts()` output). Use the **exact** algorithm the SQL backfill uses: `sha1(implode(',', $sortedDistinctIntInvoiceIds))`. Put it in a small private helper (e.g. `contextKey(array $invoiceIds): string`) so the algorithm has one source of truth, and add it to `$voucherData` (`:149–160`) alongside the other create fields.
  - [x] 4.2 **Algorithm-parity is load-bearing** (AC3): PHP `implode(',', [1,2,3])` → `"1,2,3"`; SQL `GROUP_CONCAT(DISTINCT invoice_id ORDER BY invoice_id SEPARATOR ',')` → `"1,2,3"`; `sha1()` ↔ `SHA1()` agree on the same bytes. Integer ids have no leading zeros on either side. Note the `group_concat_max_len` caveat (default 1024 chars ≈ 140+ invoices) — far beyond any real voucher's invoice set, but the migration should not silently truncate; if a deployment ever has enormous sets, raise it for the session or document the bound.
  - [x] 4.3 Confirm the existing **create-null fall-through is the recovery path** and needs no change: when the loser's `repository->create()` returns `null` (its `INSERT` hit the unique key → `Record->insert()` raises a `PDOException` → caught by `KuickPayVoucherRepository::create()`'s `catch (Throwable) { rollBack(); return null; }`), `getOrCreateForInvoiceContext()` already re-looks-up `getPendingByInvoiceSet()/getPendingByInvoiceId()` (`:175–180`) and returns the winner. **Verify this exception→null→re-lookup chain on the real DB** (Task 6) — Blesta `Record` runs PDO in exception mode, so a duplicate-key surfaces as a throwable, not a silent false. Note the race-detail: the winner's row is committed and visible to the loser's post-rollback re-lookup.

- [x] **Task 5 — Unit coverage (fakes), holding new doubles to NOT-NULL/UNIQUE fidelity (AC1; Epic 3 retro AI-2 spirit)**
  - [x] 5.1 Extend `KuickPayVoucherReferenceServiceTest.php`: assert `getOrCreateForInvoiceContext()` passes a non-empty, deterministic `context_key` into `create()` for a known invoice set, and that the same invoice set (any order, with duplicates removed) yields the **same** `context_key` while a different set yields a different one. The current `KuickPayVoucherReferenceFakeRepository::create()` just returns `null` (`:96–99`) — capture `$voucherData` to assert on it.
  - [x] 5.2 Add a **stateful** fake-repository test that simulates the unique key: a second active row with the same `(company_id, active_context_key)` must make `create()` return `null`, and the fake's `getPendingByInvoiceSet()/getPendingByInvoiceId()` must then return the first row — proving the fall-through returns exactly one pending voucher. Hold the fake to real fidelity (enforce the NOT-NULL `context_key` and the UNIQUE active-context constraint) so it cannot mask the bug, per the fake-fidelity lesson `[[kuickpay-blesta-decimal4-amount-trap]]` / Epic 3 retro AI-2. (Note: the *authoritative* proof is the real-DB harness in Task 6 — fakes can only approximate a unique constraint, exactly as they could only approximate the `FOR UPDATE` lock in 5-1.)
  - [x] 5.3 Run both component suites under PHP 8.3 (production) and the PHP 8.2 source-floor, with the documented runner, and disclose the one pre-existing gateway baseline red as baseline (see Dev Notes / `[[kuickpay-failclosed-empty-currency-red]]`).

- [x] **Task 6 — DB-backed proof against the real stack + sanitized evidence (AC1, AC3; NFR9)**
  - [x] 6.1 Add a CLI, opt-in, clearly-guarded integration harness under `plugins/kuickpay_reconcile/tests/integration/` (sibling to `live_fixture_round_trip.php` — reuse its `$container = include $root.'/lib/init.php';` bootstrap, CLI-only guard, and `--i-understand…` confirmation-flag style). It must seed disposable rows only and tear them down (or run inside a rolled-back DB transaction). **Never** run against real customer invoices.
  - [x] 6.2 Prove **AC1 single-active-context** on the real DB: seed one disposable `pending` voucher for an invoice set via the real `KuickPayVoucherReferenceService`/`KuickPayVoucherRepository`, then attempt a second create for the **same** invoice set and assert: (a) the second `INSERT` fails on `uniq_kuickpay_vouchers_active_context`, (b) `getOrCreateForInvoiceContext()`'s fall-through returns the **first** voucher, (c) exactly one `pending` row exists for that set. (Single-process true concurrency is not required — the unique-key collision is the deterministic, real-DB proof; note this honestly.)
  - [x] 6.3 Prove the **release semantics**: transition the surviving voucher to `cancelled` (slot released → `active_context_key` becomes `NULL`), then assert a fresh `pending` voucher CAN be created for the same invoice set; and transition a voucher to `posted` and assert a new same-set active voucher is still **blocked**. This proves the status-derived generated column recomputes on UPDATE.
  - [x] 6.4 Prove **AC3 migration end-to-end**: capture `SHOW CREATE TABLE kuickpay_vouchers` **before** and **after** running the real `1.8.0 → 1.9.0` `PluginManager::upgrade()` against the live DB (it actually runs SQL now, unlike 5-1's no-op bumps); show the new `context_key` column, the generated `active_context_key`, and `uniq_kuickpay_vouchers_active_context`; show existing rows backfilled with non-NULL `context_key`; and prove **idempotency** by re-running the guard method and asserting no error / no duplicate index. Cross-check that a `php` bootstrap `install()` on a scratch table produces the byte-identical generated-column + index definition (fresh-install ≡ upgrade).
  - [x] 6.5 Author/extend the sanitized evidence record (a new `docs/kuickpay/` note, or a section appended to `docs/kuickpay/live-verification-evidence.md` following its precedent) stating exactly what ran against the real stack vs fakes, the PHP version (8.3 production / 8.2 floor), exact commands, before/after schema, and the AC1/AC3 proofs. **Redaction gate (NFR8):** no `config/blesta.php` values, DB creds, host/user, KuickPay credentials, raw SOAP, or customer PII — placeholders and structural/state evidence only.

- [x] **Task 7 — Verification-honesty close-out + scope-boundary note (NFR12)**
  - [x] 7.1 Run `php -l` (under PHP 8.3, and 8.2 floor) on every changed PHP file: `kuickpay_reconcile_plugin.php`, `kuickpay_vouchers.php`, `KuickPayVoucherReferenceService.php`, the new harness, the touched language file. Confirm both component suites are green-modulo-the-disclosed-baseline-red.
  - [x] 7.2 In the Dev Agent Record and the evidence doc, explicitly state: this story **builds and proves the unique key but does NOT un-gate `replace`/`allow`** in `components/gateways/nonmerchant/kuickpay/kuickpay.php` (those remain `in_array(['block'])` at `:298–311` with the dropdown options commented at `:121–129`). Record that production un-gating is now *unblocked at the schema layer* but still owes the Story 5.5 `retireVoucher()` affected-row hardening (`deferred-work.md` 2-4) before it is safe to flip. Mark `deferred-work.md` 2-4 schema residual, 2-3 concurrent double-submit, and 3-4 double-pending as **closed at the schema layer** by this story.

### Review Findings

- [x] [Review][Decision] Duplicate-resolution branch leaves duplicates active — resolved by choosing the `cancelled` duplicate-loser strategy: older non-posted colliding rows are moved to `cancelled`, so their generated `active_context_key` releases to `NULL` while runtime `manual_review` rows continue to hold active slots.
- [x] [Review][Patch] Backfill can silently diverge for large invoice sets [plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php:421]
- [x] [Review][Patch] Fresh-install parity harness does not run the actual install path [plugins/kuickpay_reconcile/tests/integration/active_context_guard_check.php:93]
- [x] [Review][Patch] Expected duplicate-key catch in the harness does not reset the shared Record builder [plugins/kuickpay_reconcile/tests/integration/active_context_guard_check.php:206]
- [x] [Review][Patch] Evidence overstates fake coverage for posted-slot release semantics [docs/kuickpay/active-context-concurrency-verification.md:34]

## Dev Notes

### What this story is

This is a **schema + thin-application** story. Its deliverables: (1) a real DB migration that adds `context_key` + a status-derived, STORED generated `active_context_key` + a company-scoped unique key; (2) the minimal application wiring so every new voucher carries a `context_key` computed identically to the backfill; (3) unit + **real-DB** proof that two concurrent same-invoice-set submissions resolve to exactly one active pending voucher; (4) sanitized end-to-end upgrade evidence. It is the AI-5 item the Epic 1→4 retros named and deferred four times (`epic-4-retro-2026-06-13.md:64,85,122`; `epic-3-retro-2026-06-11.md:69,90,130`); Epic 5 is terminal and the last home for it (`sprint-status.yaml:64–66`).

### The mechanism — how the guard works (read this before writing the migration)

The bug (`deferred-work.md:20,89,95`): `getOrCreateForInvoiceContext()` does a `getPendingByInvoice…` lookup, finds nothing, then `create()`s — two concurrent requests both miss the lookup and both insert a `pending` voucher for the same invoice set. The 3-5 posting `FOR UPDATE` lock only blocks double-allocation *at posting time*, not two pending rows at *issuance* time.

The fix is the canonical MySQL "partial unique index" idiom:
- `context_key` — a deterministic fingerprint of the company-scoped, de-duplicated, ascending-sorted integer invoice-id set, written on every voucher. (`sha1` of `"1,2,3"`.)
- `active_context_key` — a **STORED generated column** = `CASE WHEN status IN ('expired','cancelled') THEN NULL ELSE context_key END`. MySQL recomputes it automatically on every INSERT/UPDATE of `status` or `context_key`, so the application never writes it and transition code needs no changes.
- `UNIQUE (company_id, active_context_key)` — because a unique index permits **multiple NULLs**, released/terminal vouchers (NULL) never collide, but at most one *active* voucher per `(company, invoice-set)` can exist. The loser's INSERT fails; the existing create-null fall-through (`KuickPayVoucherReferenceService.php:175–180`) re-reads and returns the winner. Net effect: exactly one active pending voucher, enforced by the database, not by a lookup-then-insert race.

**Why this is NOT the "nullable-unique trap" the architecture forbids** (architecture.md:351,538): that warning is about *optional KuickPay references* (e.g. `kuickpay_reference`, `blesta_transaction_id`) where making the column nullable-unique gives false guarantees. Here, NULL-means-"not claiming-the-slot" is the *intended, correct* use of multiple-NULLs — the architecture explicitly lists "active payment context" as a thing to make schema-idempotent (architecture.md:351).

### The one real design decision — the active-status set (decided; flagged for sign-off)

`active_context_key` is `NULL` (slot released) for exactly `expired` and `cancelled`; it holds the slot (`= context_key`) for `pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `manual_review`. Rationale, derived from the **UI Display-State Matrix** (architecture.md:595–608): `expired` and `cancelled` are the *only two* states whose customer action is "generate/pay again," so they must free the invoice set for a new voucher. `posted` is paid → the slot stays claimed forever (never mint another for a paid set). `failed` and `manual_review` are admin-resolution states (no customer re-pay path) → hold the slot until an admin resolves them (to `cancelled`, which releases). `pending`/`retry`/`confirmed_unposted` are live claims. This is AI-5's named owner Winston's sign-off item (`epic-4-retro-2026-06-13.md:122`) — implement as specified; if Israr/Winston wants a narrower active set (e.g. only `pending`), it is a one-line change to the `CASE … IN (…)` list, but the broader set is what fully closes the 3-4 double-pending and prevents a second voucher while one is `confirmed_unposted`/`posted`.

### Scope boundary: un-gating is enabled, not performed

AC2's "un-gated only behind this unique key" is a **constraint on future work, not a task here.** The `replace`/`allow` policies are hard-gated to `block` in `components/gateways/nonmerchant/kuickpay/kuickpay.php` (`getSettings` dropdown options commented at `:121–129`; validation `in_array(['block'])` at `:298–311`). **Do not flip them in this story.** Both Epic retros are explicit: land the schema story "**before any** production un-gating of `replace`/concurrent issuance" (`epic-4-retro-2026-06-13.md:122,181`; `epic-3-retro-2026-06-11.md:130`). Un-gating additionally depends on Story 5.5's `retireVoucher()` affected-row hardening (`deferred-work.md:101`). This story makes un-gating *safe at the schema layer*; the actual flip is a later, deliberate change.

### Runtime, toolchain, and how to drive the real DB (inherited from 5-1)

- **Production runtime is PHP 8.3 (ea-php83, ionCube 15)** — the framework only boots on 8.3 (the ionCube-15 core cannot load on ea-php82). Verify the live migration/upgrade legs on `/usr/local/bin/php` (8.3). The "PHP 8.2" in older ACs is a Composer source-compatibility floor only; keep the code 8.2-syntax-clean (no 8.3-only syntax). See `[[kuickpay-php82-toolchain-now-available]]` and the 5-1 RUNTIME TARGET CORRECTION.
- A real Blesta+MySQL stack runs locally; `config/blesta.php` holds DB creds; `mysql` client is `/usr/bin/mysql`; `beta.hosterpk.com` is pre-dev (data-rich, **not** live) → DB-backed tests are safe (`sprint-status.yaml:68–75`).
- Drive the upgrade through `PluginManager::upgrade()` (not a direct `upgrade()` call) so it runs the real migration path; bootstrap a framework script under 8.3 with `$c = include '<root>/lib/init.php';` then `Loader::loadModels($h, ['PluginManager']);` (5-1 evidence shows the installed version was advanced to `1.8.0`; this story's real upgrade is therefore `1.8.0 → 1.9.0`).
- Component test runner (project-context.md:74): `cd plugins/kuickpay_reconcile && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. **Do NOT** use `-c build/phpunit.xml`. Pre-existing baseline red is the gateway suite's `ambiguous/bill-payment-inquiry-empty-currency.xml` fail-closed contract test — disclose as baseline, not a regression (`[[kuickpay-failclosed-empty-currency-red]]`).

### Files to touch (UPDATE) — current state and what changes

- `plugins/kuickpay_reconcile/config.json` — version `1.8.0` → `1.9.0`.
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` — **today:** `install()` builds `kuickpay_vouchers` via `setField`/`setKey` (`:38–73`, existing unique keys `uniq_kuickpay_vouchers_consumer`/`_reg`); `upgrade()` has empty 1.5.0–1.8.0 branches (`:125–157`); migration helpers `addVoucherEvidenceColumns()`/`addBulkReconciliationColumns()` use raw `Record->query()` ALTERs with `columnExists()`/`enumContains()` guards (`:328–471`). **Change:** add `addActiveContextGuard()` + `indexExists()`; call the guard from `install()` (after the two `create(...)`s) and from a new `version_compare(<'1.9.0')` upgrade branch. **Preserve:** the existing keys, the empty-branch comments, cron registration.
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` — **today:** `FIELDS` allowlist (`:24–48`) governs `add()`/`edit()`/`transition()` inserts/updates; `getRules()` (`:717–784`) validates create. **Change:** add `'context_key'` to `FIELDS` and a required rule in `getRules()`. **Preserve:** `active_context_key` is *generated* — must NOT be in `FIELDS`; all transition methods (`edit`/`transition`/`expire`/`getReconcilable`/`getPostable` selectors) are unchanged.
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` — **today:** `getOrCreateForInvoiceContext()` already canonicalizes the invoice set (`normalizeContextInvoiceAmounts()` → sorted distinct `$invoiceIds`, `:65–88`), builds `$voucherData` (`:149–160`), calls `repository->create()`, and on null re-looks-up (`:170–180`). **Change:** compute `context_key` from `$invoiceIds` via a single-source `contextKey()` helper and add it to `$voucherData`. **Preserve:** the create-null fall-through (it IS the loser-recovery path) and `requestMatchesVoucher()`.
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php` — add `KuickpayVouchers.!error.context_key.empty` (keep existing key style; `en_us` only is fine — this is an internal validation error, not customer-facing copy).
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherReferenceServiceTest.php` — extend the fake repo (`:74–100`) to capture `$voucherData` and to enforce the unique active-context constraint; add Task 5 assertions.

### New files

- `plugins/kuickpay_reconcile/tests/integration/<name>.php` — opt-in CLI DB harness (sibling to `live_fixture_round_trip.php`); CLI-only guard + `--i-understand…` flag + disposable-rows-only + teardown/rollback.
- Sanitized evidence: a new `docs/kuickpay/` note **or** a clearly-headed section appended to `docs/kuickpay/live-verification-evidence.md`.
- `_bmad-output/` and `docs/kuickpay/` are planning/doc artifacts — keep them in commits separate from the runtime/migration code (project-context.md:104). Commit style `<type>(<scope>): <summary>`, e.g. `feat(kuickpay): add active-context schema concurrency guard`, `test(kuickpay): prove single-active-context on real DB`, `docs(kuickpay): record schema-concurrency verification`.

### Cross-cutting guardrails (project-context.md + memories)

- **Schema-affecting work needs BOTH fresh-install and upgrade artifacts** (project-context.md:63,110; architecture.md:449,478–483). The shared `addActiveContextGuard()` called from both paths is exactly this — verify both produce the identical final schema.
- **Idempotent migrations** (architecture.md:449): every ALTER guarded by `columnExists`/`indexExists`; re-running the guard must be a no-op (Task 6.4).
- **No new ORM / no raw SQL beyond the established pattern** (project-context.md:26,47): the raw `Record->query('ALTER …')` is the *existing* migration idiom in this very file — match it; do not introduce a migration framework.
- **Company scoping is structural** (project-context.md; Story 5.5 makes it a base-helper convention): the unique key is `(company_id, …)` and `context_key` lookups stay company-scoped via the composite key. Do not key uniqueness on `active_context_key` alone.
- **Never expose `config/blesta.php` / DB creds / host / PII** in the harness, evidence, or commits (project-context.md:33,112,125; NFR8). State the engine + version structurally; do not paste creds or query output containing them.
- **Disposable billing data only** in the harness; tear down or roll back. Lower-risk than 5-1 (no real `transactions` are created here — this story does not post), but still mutates real `kuickpay_vouchers` rows: isolate on a clearly-marked disposable client/invoice set and do not collide with the existing live `pending`/`manual_review` rows.
- **Fail-closed (NFR9):** the loser's failed INSERT must resolve to "return the existing pending voucher," never to a duplicate or a paid state. The pre-existing-duplicate migration branch preserves one active survivor and routes older non-posted colliding rows to `cancelled` so the unique key can be added without touching `posted`.
- **Honest reporting (NFR12):** name precisely what ran on the real DB vs fakes; a single-process unique-key collision is a legitimate, deterministic proof of AC1 — say so plainly; do not claim true multi-process concurrency you did not run.
- **Generated column / unique index = DB-only behavior fakes cannot exercise** — exactly like the `FOR UPDATE` lock in 5-1 (`[[kuickpay-bulk-idempotency-unique-item-key]]`). The real-DB harness is the authoritative proof; the fakes are a regression net only, held to NOT-NULL/UNIQUE fidelity so they do not mask the bug (Epic 3 retro AI-2).

### Prior-story intelligence

- **5-1** proved the live stack works on production 8.3, advanced the installed plugin to `1.8.0`, and established the `tests/integration/` harness + sanitized-evidence-doc pattern this story reuses. It deliberately left `context_key`/`active_context_key` to **this** story (5-1 Dev Notes, "companion structural debts … other stories").
- **Epic 3 retro Pattern #1 / #3** and **Epic 4 retro Pattern #1**: a spec can be followed exactly and still ship a defect when the *spec itself* punted a structural guard (clock alignment; schema concurrency). This story exists to convert the four-epic-old deferral into a real schema guarantee — do not re-defer any part of the active-set or backfill-parity design.
- **5.3** (next) closes the `persistEvidence():435` manual-vs-cron race and the remaining posting residuals; **5.5** closes `retireVoucher()` affected-row (the remaining dep before `replace` can be un-gated). Keep those OUT of 5.2.

## Project Structure Notes

- Migration lives in `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` (the plugin owns schema lifecycle — architecture.md:449,788; project-context.md:63). No core-app or gateway schema changes.
- Generated column + unique index are added via raw `Record->query()` ALTERs (the Blesta `setField`/`setKey` builder cannot express `GENERATED ALWAYS AS`), matching the existing `addVoucherEvidenceColumns()`/`addBulkReconciliationColumns()` migration idiom in the same file.
- Tests stay inside the extension test areas (`plugins/kuickpay_reconcile/tests/…`); **no** new root `tests/` (project-context.md:70; architecture.md:908–913).
- **Detected variance / risk to flag:** the installed version after 5-1 is `1.8.0`, so the real upgrade under test is `1.8.0 → 1.9.0` — and unlike the 1.5.0–1.8.0 no-op bumps, this branch runs real SQL. Treat that as the first true schema migration of Epic 5 and verify the ALTERs run cleanly on the live, already-populated table (the `ADD context_key` must be nullable to avoid a NOT-NULL-without-default failure on existing rows; the generated column + unique index must come *after* the backfill).
- **Engine-compatibility risk:** STORED generated column + unique index needs MySQL ≥ 5.7.8 / MariaDB ≥ 10.2 (Task 1). The application-maintained fallback (Task 1.2) preserves correctness if the engine surprises us, at the cost of "schema-enforced" → "application-enforced + unique-index-backed."

## References

- [Source: epics.md#Story 5.2 (lines 876–897); Epic 5 intro (843–846)]
- [Source: architecture.md#Data Architecture / "active payment context" idempotency (351–353); Naming Patterns / DB conventions (528–538); Infrastructure & Deployment install/upgrade/rollback/verification (441–483); UI Display-State Matrix (595–608)]
- [Source: epic-4-retro-2026-06-13.md#Pattern #6 (line 64); AI-5 (85, 122, 181); critical path (103, 109, 150–156)]
- [Source: epic-3-retro-2026-06-11.md#Pattern #5 (line 69); AI-5 (90, 130, 187)]
- [Source: deferred-work.md#2-4 schema residual (line 20); 2-3 concurrent double-submit (89); 3-4 double-pending (95); 2-4 retireVoucher (101)]
- [Source: kuickpay_reconcile_plugin.php install/upgrade/migration helpers (`:31–92`, `:100–162`, `:328–471`)]
- [Source: models/kuickpay_vouchers.php FIELDS/add/edit/transition/getRules (`:24–115`, `:638–692`, `:717–784`); active/pending lookups (`:192–318`)]
- [Source: lib/KuickPayVoucherReferenceService.php getOrCreateForInvoiceContext + create-null fall-through (`:60–186`)]
- [Source: lib/KuickPayVoucherRepository.php create()/getPendingByInvoiceSet (`:31–92`)]
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php replace/allow gate (`:121–129`, `:298–311`)]
- [Source: 5-1 story (runtime correction, integration-harness + evidence-doc precedent); tests/integration/live_fixture_round_trip.php]
- [Source: sprint-status.yaml#Epic 5 note + build order (lines 37–76)]
- Memories: `[[kuickpay-php82-toolchain-now-available]]`, `[[kuickpay-bulk-idempotency-unique-item-key]]`, `[[kuickpay-blesta-decimal4-amount-trap]]`, `[[kuickpay-reconcile-state-set]]`, `[[kuickpay-failclosed-empty-currency-red]]`, `[[kuickpay-expiry-reconcile-clock-skew]]`

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

- **Task 1 (engine):** `SELECT VERSION()` via the framework `Record` → `10.6.27-MariaDB` (≥ 10.2). Generated-column path taken; application-maintained fallback (1.2) not needed.
- **Task 6 real-DB finding:** the DB harness surfaced a latent `Record` builder state-leak. When the loser's `INSERT` throws the duplicate-key `PDOException`, `KuickPayVoucherRepository::create()` rolled back but left stale bound values on the shared Blesta `Record`; the fall-through `getPendingByInvoiceId` then failed with `SQLSTATE[HY093] number of bound variables does not match number of tokens`, swallowed by the service's outer `catch`, so the loser received `null` instead of the winner. **Fix:** `create()` now calls `Record->reset()` after `rollBack()` on every failure path. This is exactly the "verify the exception→null→re-lookup chain on the real DB" check Task 4.3 mandated; fakes could not catch it (they never touch the real `Record`).
- **Harness reporting bugs fixed mid-run:** (a) Blesta lowercases column keys, so `SHOW CREATE TABLE` must read `create table` case-insensitively (initial run showed empty DDL fragments → a vacuous `''===''` fresh-install match); (b) `flatten()` does not expose `context_key`, so the winner's key is read from the DB. After both fixes the harness is a complete deterministic real-DB proof.
- **Harness final run (PHP 8.3, MariaDB 10.6.27):** `result: PASS`, exit 0. Current invocation started from already-upgraded `1.9.0` (schema present), backfill 0 NULL, idempotent re-run clean, fresh-install ≡ upgrade (byte-identical DDL fragment from the shared `activeContextGuardSql()` provider), AC1 raw-dup rejected + race fall-through returns winner + exactly one pending, release semantics (cancel frees / posted blocks). Post-run live state clean: disposable rows deleted and scratch dropped.

### Completion Notes List

- **AC1** — `context_key` (deterministic `sha1` of the company-scoped, ascending, de-duplicated integer invoice-id set) + STORED generated `active_context_key` (`NULL` for `expired`/`cancelled`, else `context_key`) + company-scoped unique key `uniq_kuickpay_vouchers_active_context (company_id, active_context_key)`. Proven against the real DB: two same-set attempts resolve to exactly one active pending voucher (loser's INSERT fails on the unique key; create-null fall-through returns the winner). Single-process — the unique-key collision is the deterministic real-DB proof; no multi-process concurrency claimed.
- **AC2** — the application-layer double-allocation residual (3.4/3.5) is closed at the schema layer for the exact-invoice-set case. Un-gating of `replace`/`allow` is **enabled, not performed** (see scope boundary).
- **AC3** — fresh `install()` and versioned `upgrade()` both funnel through one shared idempotent `addActiveContextGuard()` and converge on the byte-identical schema; existing rows backfill with the same `sha1` the application uses (PHP `implode(',', …)` ↔ SQL `GROUP_CONCAT(DISTINCT … ORDER BY invoice_id SEPARATOR ',')`); pre-flight duplicate resolution runs before the unique key (0 collisions on live data); verified end-to-end with before/after `SHOW CREATE TABLE` and an idempotent re-run.
- **Scope boundary (NFR12):** this story builds and proves the unique key but does **NOT** un-gate `replace`/`allow` in `components/gateways/nonmerchant/kuickpay/kuickpay.php` (untouched: dropdown options commented at `:121–129`, validation `in_array(['block'])` at `:298–311`). Production un-gating is now unblocked at the schema layer but still owes the Story 5.5 `retireVoucher()` affected-row hardening before it is safe to flip.
- **Deferred-work closed at the schema layer:** `deferred-work.md` 2-4 schema residual, 2-3 concurrent double-submit, 3-4 double-pending (exact-invoice-set case). The 2-4 `retireVoucher()` affected-row item stays open (5.5).
- **Necessary caller fix:** the required `context_key` model rule would have broken the 5-1 `live_fixture_round_trip.php` harness (it called `create()` with no `context_key`); added `'context_key' => sha1((string) $invoiceId)` there.
- **Verification:** plugin suite 166 tests / 0 failures (PHP 8.3 and 8.2); gateway suite 233 / 1 **pre-existing baseline** failure (`ambiguous/bill-payment-inquiry-empty-currency.xml`, `[[kuickpay-failclosed-empty-currency-red]]`) — disclosed as baseline, not a regression. `php -l` clean on changed PHP files touched by the review patches on PHP 8.3 and 8.2; real DB harness PASS on PHP 8.3. Evidence: `docs/kuickpay/active-context-concurrency-verification.md`.

### File List

- `plugins/kuickpay_reconcile/config.json` — version `1.8.0` → `1.9.0` (UPDATE).
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` — `addActiveContextGuard()` + `resolveActiveContextDuplicates()` + `indexExists()`; wired into `install()` and a new `1.9.0` `upgrade()` branch (UPDATE).
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` — `context_key` added to `FIELDS` allowlist + required rule in `getRules()` (UPDATE).
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php` — `KuickpayVouchers.!error.context_key.empty` key (UPDATE).
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` — `contextKey()` helper + `context_key` added to `$voucherData` (UPDATE).
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` — `Record->reset()` on every `create()` failure path so the create-null fall-through re-lookup runs on a clean builder (UPDATE).
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherReferenceServiceTest.php` — stateful NOT-NULL/UNIQUE-fidelity fake + determinism, single-active-context fall-through, release, and empty-key tests (UPDATE).
- `plugins/kuickpay_reconcile/tests/integration/active_context_guard_check.php` — opt-in CLI DB-backed proof harness for AC1/AC3/release (NEW).
- `plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php` — pass the now-required `context_key` in its direct `create()` (UPDATE).
- `docs/kuickpay/active-context-concurrency-verification.md` — sanitized real-DB verification evidence (NEW).
- `_bmad-output/kuickpay/implementation-artifacts/deferred-work.md` — marked 2-4 schema residual / 2-3 double-submit / 3-4 double-pending closed at the schema layer (UPDATE).

## Change Log

| Date | Version | Description | Author |
|---|---|---|---|
| 2026-06-13 | 1.9.0 | Schema-level active-context concurrency guard: `context_key` + STORED generated `active_context_key` + company-scoped unique key, with issuance wiring, backfill/upgrade parity, real-DB proof, and a `Record`-reset fix to the create-null fall-through. | Israr (dev agent) |
