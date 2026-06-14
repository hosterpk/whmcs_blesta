# KuickPay Audit, Logging & Redaction Completeness — Verification Evidence (Story 5.4)

Date: 2026-06-14
Environment: local dev on `beta.hosterpk.com` (pre-dev, NOT live).
Scope: records exactly what was executed. Story 5.4 is **test + small-fix** work with **no schema
change, no migration, and no live-stack/DB legs** — verification is unit-suite + lint based.
Sanitization (NFR8): no DB/KuickPay credentials, Institution ID, host/user, raw SOAP, or customer PII
appear here. All values below are synthetic test placeholders.

---

## Runtime and engine

- **Production runtime is PHP 8.3 (ea-php83, ionCube 15).** The isolated PHPUnit 8.5 suites ran on
  `/usr/local/bin/php` = **PHP 8.3.31** (primary) and `/opt/cpanel/ea-php82/root/usr/bin/php` =
  **PHP 8.2.31** (source-floor portability check). `php -l` ran on **both** for every changed file.
- No database, framework boot, or external SOAP call is involved in this story's verification — every
  leg is a unit suite with fakes/fixtures.

---

## What ran (all unit / lint; no real DB or provider)

| AC | Proof | Evidence |
|---|---|---|
| AC1 | Bulk per-Voucher exception is audited symmetrically with the single path (already at HEAD via 5.3 `01682753`) — **no production change** | unit: bulk `evidence.error` (voucher_id/run_id) + `reconcile_exception` item row; idempotent collision on `(run_id, voucher_id)` is swallowed, run completes |
| AC2 | `voucher.generation_failed` names the actual conflicting invoice; benign `create()` fall-through sets `create_failed` + durable audit | unit: conflicting-id-not-first → audit `invoice_id` = conflicting id; forced fall-through → `getLastError()==='create_failed'` + audit |
| AC3 | Redactor masks XML **attributes** + confirmed **aliases**; `maskCredentials()` is input-robust + case-insensitive (base class untouched) | unit: attribute/alias envelopes masked, benign untouched; masker handles null/object/bool/mixed-case with no `TypeError`/deprecation |
| AC4 | Leak-scan covers diverse PII/placeholder formats; `isTimeout()` is locale-independent | unit: positive/negative pattern controls + diversified clean fixture; `isTimeout()` duration-primary classification under a localized message |

Honest reporting (NFR12): **no live legs.** Nothing in this story touches the database, the framework, or
the real KuickPay endpoint, so there is no real-DB harness (unlike 5.1/5.2/5.3). AC1 is verified to be
**already satisfied at HEAD** (landed in 5.3 commit `01682753`); the 5.4 work is a regression test that
locks the bulk/single symmetry and the collision-swallow, not new production code.

---

## Commands (exact)

```
# lint — every changed PHP file, both engines
ea-php83 -l <file> && ea-php82 -l <file>   # all 10 changed files: clean on 8.3 AND 8.2

# plugin suite (8.3 and 8.2)
cd plugins/kuickpay_reconcile && \
  /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests

# gateway suite (8.3 and 8.2)
cd components/gateways/nonmerchant/kuickpay && \
  /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
```

## Results

- **Plugin suite: 188 tests, OK** on PHP 8.3 and on the 8.2 floor (baseline was 182; +6 new tests:
  2 AC1, 2 AC2, 2 AC4 leak controls).
- **Gateway suite: 238 tests** on PHP 8.3 and on the 8.2 floor (baseline was 234; +4: 2 redactor,
  1 masker, 1 isTimeout) — green **modulo the one disclosed pre-existing baseline red**,
  `KuickPayFailClosedContractTest` with `ambiguous/bill-payment-inquiry-empty-currency.xml`. That fixture
  is a Story-pre-existing fail-closed contract red, unrelated to this story; none of the 5.4 changes touch
  the empty-currency path.

---

## Notes

- **No new audit event name and no schema/version bump.** `create_failed` is a reason token inside the
  existing `voucher.generation_failed` event, so the 4-site event registry and plugin version (1.10.0) are
  unchanged.
- **Base class untouched.** The `maskCredentials()` hardening lives entirely in the gateway-owned
  `kuickpay.php`; `components/gateways/lib/gateway.php` was not edited.
- **Run-detail symmetry preserved (AC1.4).** `evidence.error` was intentionally **not** added to the 4.4
  run-detail allowlist (`getByRun`/`getCountByRun`), keeping single/bulk visibility symmetric.
