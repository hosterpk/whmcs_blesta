# KuickPay Reconcile & Posting Safety Hardening — Verification Evidence (Story 5.3)

Date: 2026-06-14
Environment: local Blesta + MariaDB stack on `beta.hosterpk.com` (pre-dev, data-rich but NOT live).
Scope: records exactly what was executed against the real stack vs replayed/simulated with fakes.
Sanitization (NFR8): no DB credentials, KuickPay credentials, Institution ID, host/user, raw SOAP, or
customer PII are reproduced here. The live database name is not shown. Seeded rows are synthetic
(`KP53CON…`/`KP53REG…`, random suffixes, no real invoices/transactions); only structural/state evidence
appears below.

---

## Runtime and engine

- **Production runtime is PHP 8.3 (ea-php83, ionCube 15)** — the ionCube-15-encoded Blesta core only boots
  on 8.3. The framework-backed legs (the real `PluginManager::upgrade()` and the DB harness) ran on
  `/usr/local/bin/php` = **PHP 8.3.31**. The "PHP 8.2" floor is a Composer source-compatibility pin only;
  the unit suites additionally ran on `/opt/cpanel/ea-php82/root/usr/bin/php` = **PHP 8.2.31** as a
  portability check, and `php -l` ran on both for every changed file.
- **Database engine: MariaDB 10.6.27** (confirmed via `SELECT VERSION()` through the framework
  connection).

---

## What ran against the real DB vs fakes

| Proof | Real DB | Fakes |
|---|---|---|
| AC5.4 migration `1.9.0 → 1.10.0` (before/after column, default-0 backfill, idempotency, fresh-install ≡ upgrade) | ✅ harness | — |
| AC1 status-guarded terminal write is a no-op on a confirmed_unposted row (no demotion, date_paid intact, re-read returns actual status) | ✅ harness (real `transition()`/`editIfActive` + DB row) | ✅ unit (status-faithful fake) |
| AC2 single-inquiry `missing_paid_date` guard | — (parser is evidence-internal, no DB) | ✅ unit |
| AC3 `getReconcilable`/`getExpirable` are exact complements on one clock | ✅ harness (CURDATE() vs CURDATE()-1d real rows) | — (DB-behavior; fakes cannot prove it) |
| AC4 structural `gatewayConfig` threading + empty-keys fail-closed + trigger-scoped cursor | — | ✅ unit |
| AC5a per-Voucher transaction rolls the Voucher edit back on a mid-block failure | ✅ harness (real `Record` begin/rollBack + DB row) | ✅ unit (begin/commit/rollBack fake) |
| AC5b duplicate `(company_id, lock_name)` insert returns false (lock held) | ✅ harness (real unique key) | ✅ unit (synthetic PDOException) |
| AC5b a non-duplicate (infra) exception from `insertLock()` is surfaced, not swallowed | — (a real infra failure cannot be safely induced) | ✅ unit (synthetic `HY000`/2006 PDOException) |
| AC5c posting retry cap increments `posting_attempts` and escalates to manual_review at the cap, clearing the head | ✅ harness (real `postVoucher()` ×5 + DB row) | ✅ unit |
| AC5.5 adoption picks the most-recent approved + already-applied candidate | — | ✅ unit (multi-candidate fake) |

Honest reporting (NFR12): **single-process**. Every real-DB proof is deterministic — the AC1/AC5a guards are
exercised by setting the real row to its post-race state and then running the exact write a racing caller
would issue; no true multi-process concurrency is claimed. A single-process status-guarded UPDATE matching
zero rows (AC1), a real transaction rollback leaving the row unchanged (AC5a), and a real unique-key
collision (AC5b) are legitimate deterministic proofs that the database (not a lookup-then-act race)
enforces the invariant.

---

## Harness

`plugins/kuickpay_reconcile/tests/integration/posting_safety_hardening_check.php` — opt-in, CLI-only,
guarded by `--i-understand-this-mutates-kuickpay-vouchers` (sibling to the Story 5.2
`active_context_guard_check.php`). It seeds disposable synthetic voucher + lock rows, runs the proofs, and
deletes every row it creates (plus its scratch table) in a `finally` block. The two pre-existing live rows
are never touched.

Command (exact):

```
php plugins/kuickpay_reconcile/tests/integration/posting_safety_hardening_check.php \
  --i-understand-this-mutates-kuickpay-vouchers --company-id=1
```

### Result (sanitized — synthetic ids only)

```json
{
  "migration_before":   { "plugin_version": "1.9.0",  "posting_attempts": false },
  "migration_after":    { "plugin_version": "1.10.0", "posting_attempts": true  },
  "migration_upgrade_ran_this_invocation": true,
  "migration_fresh_install_fragment": "`posting_attempts` int(10) unsigned NOT NULL DEFAULT 0",
  "migration_upgrade_fragment":       "`posting_attempts` int(10) unsigned NOT NULL DEFAULT 0",
  "migration_fresh_matches_upgrade": true,
  "migration_existing_rows_nonzero": 0,
  "migration_idempotent_rerun_clean": true,

  "ac1_guarded_write_transitioned": false,
  "ac1_status_after": "confirmed_unposted",
  "ac1_date_paid_intact": true,
  "ac1_readback_status": "confirmed_unposted",

  "ac3_today_reconcilable_not_expirable": true,
  "ac3_yesterday_expirable_not_reconcilable": true,

  "ac5a_outcome_error": true,
  "ac5a_status_after_rollback": "pending",

  "ac5b_first_insert_true": true,
  "ac5b_duplicate_returns_false": true,

  "ac5c_outcomes": ["failed", "failed", "failed", "failed", "manual_review"],
  "ac5c_status_after": "manual_review",
  "ac5c_posting_attempts": 5,
  "ac5c_postable_advanced_past_it": true,

  "result": "PASS",
  "failures": []
}
```

After the run: plugin `version = 1.10.0`, the two live rows untouched, scratch table dropped, and a
second invocation reports `migration_upgrade_ran_this_invocation: false` with `result: PASS` (the migration
is idempotent and the proofs are repeatable).

### Notes

- The fresh-install column DDL is produced by the SAME static SQL provider the production migration runs
  (`KuickpayReconcilePlugin::postingAttemptsColumnSql()`), so `migration_fresh_matches_upgrade` catches any
  drift between the documented fresh-install column and the real upgrade by construction (mirrors the
  Story 5.2 `activeContextGuardSql()` pattern).
- AC3 removes the limbo window that the AC1 status guard previously only *guarded*: `date_expires = CURDATE()`
  is reconcilable (`>=`) and not expirable (`<`); `date_expires = CURDATE() - 1 day` is the exact reverse —
  no overlap, no gap, on one clock.

---

## Unit suites

- Plugin suite: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap
  tests/bootstrap.php tests` — green on PHP 8.3 and the 8.2 floor.
- Gateway suite: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit
  --bootstrap tests/bootstrap.php tests` — green **modulo the one disclosed pre-existing baseline red**,
  `ambiguous/bill-payment-inquiry-empty-currency.xml` (a Story-pre-existing fail-closed contract fixture,
  unrelated to this story; the AC2 guard does not change it because that fixture carries a valid date).
