# KuickPay Active-Context Concurrency Guard — Verification Evidence (Story 5.2)

Date: 2026-06-13
Environment: local Blesta + MariaDB stack on `beta.hosterpk.com` (pre-dev, data-rich but NOT live).
Scope: records exactly what was executed against the real stack vs replayed/simulated with fakes.
Sanitization (NFR8): no DB credentials, KuickPay credentials, Institution ID, host/user, raw SOAP, or
customer PII are reproduced here. The live database name is not shown. `context_key` values are SHA-1
fingerprints of invoice-id sets (not PII); only truncated/placeholder values appear below.

---

## Runtime and engine

- **Production runtime is PHP 8.3 (ea-php83, ionCube 15)** — the ionCube-15-encoded Blesta core only boots
  on 8.3. Framework-backed legs (the real `PluginManager::upgrade()` and the DB harness) ran on
  `/usr/local/bin/php` = **PHP 8.3.31**. The "PHP 8.2" floor is a Composer source-compatibility pin only;
  the unit suites additionally ran on `/opt/cpanel/ea-php82/root/usr/bin/php` = **PHP 8.2.31** as a
  portability check. See `live-verification-evidence.md` (Story 5.1) for the full runtime rationale.
- **Database engine: MariaDB 10.6.27** (confirmed via `SELECT VERSION()` through the framework
  connection). This is ≥ MariaDB 10.2, so it supports `GENERATED ALWAYS AS (...) STORED` and a `UNIQUE`
  index over the (nullable) generated column with the multiple-NULLs-allowed semantics this design relies
  on. **The schema-derived generated-column path was taken** (Task 1) — the application-maintained
  fallback (Task 1.2) was not needed; the guard remains schema-enforced, not application-enforced.

---

## What ran against the real DB vs fakes

| Proof | Real DB | Fakes |
|---|---|---|
| AC3 migration `1.8.0 → 1.9.0` (before/after schema, backfill, idempotency, fresh-install ≡ upgrade) | ✅ harness | — |
| AC1 unique-key rejects a duplicate active context | ✅ harness (raw INSERT + real service race) | ✅ unit (stateful fake) |
| AC1 create-null fall-through returns the winner | ✅ harness (real `Record` exception → reset → re-lookup) | ✅ unit (stateful fake) |
| Release semantics (cancel frees slot; posted holds it) | ✅ harness | ✅ unit |
| `context_key` determinism / order-independence | ✅ harness (DB value) | ✅ unit |

Honest reporting (NFR12): single-process. The deterministic real-DB proof of single-active-context is the
**unique-key collision**, not a true multi-process run — no multi-process concurrency is claimed. A
single-process unique-key collision is a legitimate, deterministic proof that the database (not a
lookup-then-insert race) enforces the invariant.

---

## Exact commands

```
# Engine/version (structural disclosure only)
/usr/local/bin/php -r '... SELECT VERSION() via framework Record ...'   # → 10.6.27-MariaDB

# DB-backed proof harness (opt-in, CLI-only, disposable synthetic invoice ids, self-cleaning)
/usr/local/bin/php plugins/kuickpay_reconcile/tests/integration/active_context_guard_check.php \
  --i-understand-this-mutates-kuickpay-vouchers --company-id=1 --gateway-id=1 --client-id=1

# Component unit suites (8.3 production + 8.2 floor)
cd plugins/kuickpay_reconcile && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
cd components/gateways/nonmerchant/kuickpay && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

The harness drives the real `PluginManager::upgrade($plugin_id)` (plugin id 11, company 1) once, advancing
`plugins.version` `1.8.0 → 1.9.0`. It seeds only disposable rows under clearly-marked synthetic invoice ids
(~1.9 billion), deletes every row it creates, and drops its scratch table. No real customer invoice is
touched. Post-run live state confirmed clean: 2 production vouchers intact and backfilled, 0 leftover
disposable rows, scratch dropped, 0 orphan links.

---

## AC3 — migration before/after, backfill, idempotency, fresh-install ≡ upgrade

Before (installed `1.8.0`): `context_key`, `active_context_key`, `uniq_kuickpay_vouchers_active_context`
all absent.

After (installed `1.9.0`) `SHOW CREATE TABLE kuickpay_vouchers` fragment:

```
`context_key` varchar(64) DEFAULT NULL,
`active_context_key` varchar(64) GENERATED ALWAYS AS (case when `status` in ('expired','cancelled') then NULL else `context_key` end) STORED,
UNIQUE KEY `uniq_kuickpay_vouchers_active_context` (`company_id`,`active_context_key`)
```

- **Backfill:** 0 linked voucher rows left with a NULL `context_key` (the 2 pre-existing production rows —
  one `posted`, one `manual_review` — backfilled to distinct keys; they do not share an invoice set, so the
  unique key added without a pre-flight collision, exactly as predicted).
- **Large invoice-set parity:** the guard raises `group_concat_max_len` to `@@max_allowed_packet` before
  backfill so SQL `GROUP_CONCAT(DISTINCT ... ORDER BY ... SEPARATOR ',')` does not silently truncate under
  the default 1024-byte session limit.
- **Idempotency:** re-running the guard branch (`upgrade('1.8.0', 11)`) reported no errors and the unique
  index still spans exactly its 2 key columns — every ALTER is `columnExists`/`indexExists`-guarded.
- **Fresh-install ≡ upgrade:** a scratch table taken to the 1.8.0 base shape and run through the same guard
  ALTERs from `KuickpayReconcilePlugin::activeContextGuardSql()` produced a **byte-identical** `context_key`
  / generated `active_context_key` / unique-key
  definition to the upgraded production table. Both `install()` and `upgrade()` funnel through the one
  shared `addActiveContextGuard()`.

---

## AC1 — single active context, against the real DB

- A raw second `INSERT` for the same `(company_id, context_key)` with a different identity (so the
  consumer/registration unique keys do not fire first) is **rejected** by
  `uniq_kuickpay_vouchers_active_context` (SQLSTATE 23000).
- Driving the **real** `KuickPayVoucherReferenceService` through a race in which its pending pre-lookup
  misses: the service attempts a create, the create's `INSERT` fails on the unique key, and the
  **create-null fall-through re-looks-up and returns the committed winner**.
- After both, **exactly one** `pending` row exists for the invoice set.
- The winner's stored `context_key` equals the canonical `sha1(implode(',', sortedDistinctIntIds))`,
  matching the SQL backfill algorithm byte-for-byte.

### Real-DB finding fixed by this story — `Record` builder state leak on a failed create

The DB harness surfaced a latent defect the unique key now makes reachable: when the loser's `INSERT`
throws the duplicate-key `PDOException`, `KuickPayVoucherRepository::create()` rolled back but left **stale
bound values on the shared Blesta `Record` builder**. The very next query (the fall-through
`getPendingByInvoiceId`) then failed with *"number of bound variables does not match number of tokens"*
(SQLSTATE HY093), which the service's outer `catch` swallowed — so the loser would have received `null`
instead of the winner's pending voucher, breaking the AC1 fall-through.

Fix: `KuickPayVoucherRepository::create()` now calls `Record->reset()` after rolling back on every failure
path, leaving the builder clean for the caller's re-lookup. This is the exact "verify the exception → null
→ re-lookup chain on the real DB" check Task 4.3 mandated; fakes could not have caught it (they never
exercise the real `Record`), mirroring the `FOR UPDATE` lesson from Story 5.1.

---

## Release semantics — the generated column recomputes on UPDATE

- Transitioning the surviving voucher to `cancelled` recomputes `active_context_key` to `NULL` (slot
  released); a fresh `pending` voucher for the **same** invoice set can then be created.
- Transitioning that fresh voucher to `posted` keeps `active_context_key = context_key` (paid set keeps the
  slot forever); a new same-set active `INSERT` is then **blocked** by the unique key.

This confirms the status-derived STORED generated column is recomputed by the engine on every UPDATE of
`status`, with no application writes to `active_context_key`.

---

## Active-status set (the one money-safety design decision)

`active_context_key` is `NULL` (slot released) for exactly `expired` and `cancelled`; it holds the slot
(`= context_key`) for `pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `manual_review`.
Rationale: per the architecture UI Display-State Matrix, `expired`/`cancelled` are the only two states whose
customer action is "generate/pay again", so they must free the invoice set; `posted` is paid (hold
forever); `failed`/`manual_review` are admin-resolution states with no customer re-pay path (hold until an
admin resolves to `cancelled`). If the pre-flight duplicate-resolution branch ever finds duplicate active
non-posted rows, it cancels the older colliding rows before the unique key is added, which releases those
slots without weakening the runtime `manual_review` active-slot semantics. Implemented as specified for
Winston's AI-5 sign-off; narrowing the set is a one-line change to the `CASE … IN (…)` list.

---

## Pre-existing baseline (disclosed, not a regression)

Gateway suite: **233 tests, 1 failure** — `KuickPayFailClosedContractTest::…` with
`ambiguous/bill-payment-inquiry-empty-currency.xml` (`confirmed_unposted`). This is the carried Epic-4-exit
baseline red, unrelated to this story. Plugin suite: **166 tests, 0 failures** (8.3 and 8.2).

---

## Unit-suite results

| Suite | PHP 8.3 | PHP 8.2 |
|---|---|---|
| Plugin (`plugins/kuickpay_reconcile`) | 166 tests, 0 failures | 166 tests, 0 failures |
| Gateway (`components/gateways/nonmerchant/kuickpay`) | 233 tests, 1 baseline failure | 233 tests, 1 baseline failure |

---

## Scope boundary — un-gating is enabled, not performed

This story **builds and proves** `uniq_kuickpay_vouchers_active_context` but does **not** un-gate
`replace`/`allow` concurrent issuance. Those policies remain hard-gated to `block` in
`components/gateways/nonmerchant/kuickpay/kuickpay.php` (dropdown options commented at `:121–129`,
validation `in_array(['block'])` at `:298–311`) and are untouched here. Production un-gating is now
*unblocked at the schema layer* but still owes the Story 5.5 `retireVoucher()` affected-row hardening
(`deferred-work.md` 2-4) before it is safe to flip.

Closed at the schema layer by this story: `deferred-work.md` 2-4 schema residual (active-context
uniqueness), 2-3 concurrent double-submit, 3-4 double-pending — for the exact-invoice-set case.
