# KuickPay Live Verification Evidence (Story 5.1)

Date: 2026-06-13
Environment: local Blesta + MySQL stack on `beta.hosterpk.com` (pre-dev, data-rich but NOT live).
Scope: records exactly what was executed against the real stack vs replayed from fixtures.
Sanitization: no DB credentials, KuickPay credentials, Institution ID, raw SOAP envelopes, or
customer data are reproduced here. The live database name is shown as `<blesta_db>`.

---

## Runtime target clarification — production is PHP 8.3, not 8.2

The "PHP 8.2" inherited by the epics/retros is a **Composer source-compatibility floor**
(`composer.json` `require.php ">=8.2.0"` + `config.platform.php "8.2"`), not the production runtime.
**Production is PHP 8.3 (ea-php83)** — confirmed by the cPanel `.htaccess` handler
`AddHandler application/x-httpd-ea-php83 .php` and by the fact that the ionCube-15-encoded Blesta core
cannot load on 8.2 at all (so a working site is necessarily 8.3+). Live framework legs are therefore
verified on **PHP 8.3 = production**; the 8.2 unit-suite run is a bonus portability check against the
source floor. No runtime risk-acceptance is required.

Two PHP builds are present:

| PHP build | Binary | ionCube Loader | Runs unit suites | Boots full Blesta framework |
|---|---|---|---|---|
| 8.2.31 | `/opt/cpanel/ea-php82/root/usr/bin/php` | 13.3.1 | ✅ yes | ❌ **no** |
| 8.3.31 | `/usr/local/bin/php` (= ea-php83) | 15.0.0 | ✅ yes | ✅ yes |

The Blesta core files (`app/app_model.php`, `app/app_controller.php`, …) are **ionCube-encoded for
ionCube 15**. Only the PHP 8.3 builds carry the ionCube 15 loader; the ea-php82 build ships ionCube
13.3.1 and fails with *"cannot be decoded by this version of the ionCube Loader"* when the framework
boots. Consequence:

- **Component/unit suites run on the 8.2 floor** (they never load the encoded core) — bonus portability check.
- **Framework-backed legs** (real `PluginManager` install/upgrade, admin controllers, anything
  extending the encoded `AppModel`/`AppController`) run on **PHP 8.3 = production**. ea-php82 cannot
  boot the framework (ionCube 13.3.1 < 15), and that is fine: 8.2 was never the runtime. No loader
  needs to be installed on ea-php82.

---

## AC4 / NFR12 — PHP 8.2 unit-suite baseline (executed)

Runner: `/opt/cpanel/ea-php82/root/usr/bin/php /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
(PHPUnit 8.5.52, PHP 8.2.31). Do NOT use `-c build/phpunit.xml` (resolves a missing bootstrap).

| Suite | Result |
|---|---|
| Gateway (`components/gateways/nonmerchant/kuickpay`) | **233 tests / 1256 assertions, 1 failure** |
| Plugin (`plugins/kuickpay_reconcile`) | **158 tests / 1127 assertions, 0 failures** |

The single gateway failure is the **pre-existing baseline red**
`KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence` with data set
`ambiguous/bill-payment-inquiry-empty-currency.xml` — carried at Epic 4 exit, unrelated to this story.
Disclosed as baseline, not a regression.

This discharges the deferred per-story PHP-8.2 verification notes: `deferred-work.md` 3-1 (line 30),
1-4 (line 61), 3-4 (line 97) — the suites have now run under the real PHP 8.2 target.

---

## AC1 — Real schema install/upgrade + PluginManager permission/action re-sync (executed)

Method: script-only upgrade through Blesta's real `PluginManager::upgrade($plugin_id)` (the same code
path the admin Plugins page invokes), bootstrapped via `lib/init.php` under PHP 8.3.31 (ionCube 15).
The plugin was found already installed and enabled at an older version, giving a genuine, naturally
-behind upgrade scenario (not a contrived one).

Commands (sanitized):
- BEFORE/AFTER state captured read-only via PDO under PHP 8.2 → `5-1-evidence/ac1-before.txt`, `ac1-after.txt`.
- Upgrade: `PluginManager->upgrade(11)` under `/usr/local/bin/php` (8.3.31). Reported `UPGRADE ERRORS: NONE`.

| Evidence | BEFORE | AFTER |
|---|---|---|
| `plugins.version` (id 11, `kuickpay_reconcile`, company 1) | `1.4.0` | **`1.8.0`** |
| Plugin permissions (`permissions` where alias `kuickpay_reconcile.%`) | **1** row: `admin_main *` | **8** rows: `admin_main *`, `admin_vouchers *`, `admin_vouchers recheck`, `admin_vouchers review`, `admin_vouchers cancel`, `admin_vouchers diagnostics`, `admin_manual_review *`, `admin_reconciliation *` |
| Permission row IDs | 233 | 234–241 (**old row deleted, re-added** — confirms PluginManager's wipe-and-re-sync) |
| Nav actions (`actions` where plugin_id 11) | **1** (`admin_main`) | **4** (`admin_vouchers`, `admin_main`, `admin_manual_review`, `admin_reconciliation`) |
| `kuickpay_*` tables | 6 present | 6 present (unchanged — 1.5.0→1.8.0 are permission/nav bumps, no schema SQL) |
| Voucher data (`kuickpay_vouchers` status counts) | 1 `pending`, 1 `manual_review` | 1 `pending`, 1 `manual_review` (**no data loss**) |

**Proven:** the schema is intact and the `PluginManager` permission/action re-sync completes correctly
against the real DB — including re-creating the `recheck`/`review`/`cancel`/`diagnostics` action
permissions that gate the admin mutation surfaces (prerequisite for AC3). The before/after permission
diff (1 → 8) is direct evidence of the re-sync that prior epics flagged as the
`PluginManager::upgrade()` "wipe-and-re-add" footgun behaving as intended.

**Runtime note:** the upgrade was executed under PHP **8.3.31 (ea-php83) = production** — the correct
runtime, and the only one that can boot the ionCube-15-encoded core. 8.2 source-floor compatibility is
separately evidenced by the green unit suites above.

**Residual:** the captured install/upgrade evidence proves the naturally-behind plugin upgrade and
permission/action re-sync. It does not contain a fresh gateway install run or full `SHOW CREATE TABLE`
index captures for all six `kuickpay_*` tables; those are listed in the risk acceptance as residual
evidence gaps for Story 5.1 review.

---

## AC2 — Fixture-backed DB harness (committed for repeatable execution)

A guarded fixture-backed harness is committed at
`plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php`. It boots Blesta through
`lib/init.php`, seeds a KuickPay voucher for an operator-provided disposable unpaid invoice, replays a
sanitized `BillPaymentInquiry` fixture through the real parser/validator/reconcile service, posts through
`KuickPayPostingService`, and asserts idempotency by checking that the second post returns
`already_posted`, the KuickPay transaction count increases by exactly one, and one allocation row exists.

Example command (requires a disposable invoice created only for this verification):

```sh
/usr/local/bin/php plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php \
  --company-id=1 \
  --invoice-id=<disposable_invoice_id> \
  --gateway-id=<kuickpay_gateway_id> \
  --i-understand-this-posts-real-transaction
```

The harness intentionally scopes the cron-style selector to the seeded voucher so a data-rich pre-dev DB
does not reconcile unrelated pending vouchers. Downstream writes still use the real DB-backed repositories,
locks, audit service, validator, posting service, Blesta `transactions`, and `transaction_applied` tables.

## AC2 supplemental — Live reconcile against the REAL KuickPay provider (executed 2026-06-13)

This was run against a **real, already-paid voucher** for the project owner on a disposable/pre-dev billing
record (redacted client, invoice, and transaction identifiers; Rs 500 PKR). The owner had paid it at the
bank but it still showed `pending` because the confirming inquiry had never run. Driven via the
single-voucher "Check Now" path (`reconcileVoucher`) from a bootstrap script under PHP 8.3 — **no Blesta
cron**, so no invoice delivery/emails.

**Transport result: SUCCESS.** The real `BillPaymentInquiry` reached the production KuickPay endpoint
(~6.7s round-trip), credentials valid, and KuickPay returned **`raw_status="00"` (paid)**. The live
provider integration works end-to-end at the transport/parse level.

**Initial live round-trip result: did NOT post — fail-closed to `manual_review`.** The parser produced
`status=confirmed_unposted` but the evidence validator rejected it with
`validation_errors: ["currency_mismatch","unmatched_reference"]`, so the voucher routed to `manual_review`
(no Blesta transaction created). **Payment-safety invariant HELD — NFR9: a paid-but-unmatched response did
NOT produce a false "paid".** The voucher changed `pending → manual_review` as a result.

### Two real bugs surfaced (both masked by fakes — the core value of live verification)

1. **BLOCKER — admin "Check Now" is broken in production.** `KuickPayReconcileService::reconcileVoucher()`
   (line 187) calls `$this->voucherRepository->getForCompany()`, but the production-wired
   `KuickPayVoucherRepository` (lib) has **no such method** (it lives on the `KuickpayVouchers` *model*).
   The admin controller constructs the service with the default repository (`admin_vouchers.php:248`,
   only `logger` injected), so a real admin clicking **Check Now** on any `pending`/`retry` voucher gets a
   fatal `Call to undefined method KuickPayVoucherRepository::getForCompany()`. Unit tests passed because
   they inject a *fake* repo that implements `getForCompany` (`KuickPayReconcileServiceTest.php:983`).
   *Fix:* add a `getForCompany()` wrapper to `KuickPayVoucherRepository` delegating to
   `$this->KuickpayVouchers->getForCompany()`, mirroring the existing `getForUpdate()` wrapper (repo:262).

2. **MONEY-PATH GAP — the parser/validator does not match the real single-inquiry response.** On a
   genuinely-paid voucher the validator raised `currency_mismatch` (the real `BillPaymentInquiry` response
   does not yield `currency === 'PKR'` — `KuickPayEvidenceValidator::currencyMatches()` line 88; same gap as
   the known baseline-red `bill-payment-inquiry-empty-currency.xml`) and `unmatched_reference`
   (`referenceMatches()` line 117 — the real response reference does not match the stored
   consumer/registration/`kuickpay_reference` format). **Consequence: the reconcile→post round-trip does NOT
   complete against the real provider as currently coded** — the fixtures were green but the real response
   format differs (exactly the fixture-vs-reality risk the retros named). These deliberate money-path
   validator changes are retained in Story 5.1 by code-review decision: live verification found real
   defects, but the fixture-backed harness remains required.

### Resolution — fixes applied, round-trip then completed (2026-06-13)

Three fixes were applied and the unit suites re-run green under PHP 8.2 (plugin 158/1127, no regression):

1. **`KuickPayVoucherRepository::getForCompany()` added** (delegates to the model, mirrors `getForUpdate()`)
   — unblocks the admin "Check Now" path.
2. **`KuickPayEvidenceValidator::currencyMatches()`** — accept a `null` evidence currency (KuickPay is
   PKR-only and sends no currency column); only flag a *present, non-PKR* currency. PKR safety still enforced
   on the voucher + invoices. Aligns the validator with the parser's already-correct behaviour.
3. **`KuickPayEvidenceValidator::referenceMatches()`** — the single inquiry echoes the Consumer Number in
   result field [1] (stored by the parser as the evidence registration number); match the echoed identity
   against **either** per-voucher-unique identity (registration OR consumer), blank never matches.

The real round-trip was then re-run through the **production code path** (no harness patch) and **completed**:
- reconcile (real `BillPaymentInquiry`) → `confirmed_unposted`, `date_paid` 2026-06-12;
- post → `posted`, **one real Blesta transaction** (PKR 500.00, approved, redacted reference) applied to
  the disposable invoice → `paid = 500.0000`;
- **idempotency proven**: re-running reconcile short-circuits (`posted`), re-post returns `already_posted`,
  batch posts 0, invoice stays `paid=500.0000` with exactly one `transaction_applied` row.

### Status
- Real provider reachability + credentials + paid-status detection: **PROVEN.**
- `confirmed_unposted → posted` round-trip creates/applies a real Blesta transaction: **ACHIEVED**.
- Idempotency (no duplicate transaction / no double-allocation): **PROVEN.**
- Fail-closed safety (no false paid before the fixes): **HELD** throughout.
- **Note:** findings #2/#3 are money-path validation changes made live to unblock verification; they are
  now covered by targeted validator tests and should still receive careful review because they sit on the
  payment-validation path.

## AC3 — Admin surfaces on the real stack (in progress, 2026-06-13)

**Blank admin pages diagnosed and fixed.** All three Epic-4 admin pages
(`admin_vouchers`/`admin_manual_review`/`admin_reconciliation` index) rendered **blank** in the browser.
Root cause, confirmed against the live ACL tables:
- The v1.4.0→1.8.0 upgrade **added** the `admin_vouchers`/`admin_manual_review`/`admin_reconciliation`
  permission definitions, but **adding a permission does not grant it to any staff group**. The
  Administrators group (`staff_group_1`) had an ACL grant only on the pre-existing
  `kuickpay_reconcile.admin_main`. So the three pages returned **not authorized**.
- **Secondary bug:** `requireLogin()` renders `unauthorized` from `app/views/admin/paradigm/`, but
  **`unauthorized.pdt` is missing for the admin theme** (only `app/views/client/bootstrap/unauthorized.pdt`
  exists) → the unauthorized response throws → **blank page** instead of a clean "no permission" message.

**Fix applied:** granted `staff_group_1` (Administrators) the KuickPay permissions via Blesta's `Acl` API
(`admin_vouchers` `*`/`recheck`/`review`/`cancel`/`diagnostics`, `admin_manual_review` `*`,
`admin_reconciliation` `*`). Verified: `Permissions::authorized()` now returns true and the
`admin_reconciliation` page renders a full **41,289-byte** HTML response (no error, real content). Other
staff groups (Billing/Support) can be granted via Settings → Staff → Manage Groups as desired.

### AC3 mutations exercised through the real controller (2026-06-13) — COMPLETE

Driven via `Dispatcher::dispatch` (real routing → `AdminVouchers` → POST actions) against the live DB on a
disposable seeded voucher; all test artifacts cleaned up afterward.

| Check | Result |
|---|---|
| Two-group ACL separation (the headline assertion) | **PASS** — via the real `Acl`: Administrators allowed `recheck`/`review`/`cancel`/`diagnostics`; a `*`-only group denied all four (exact-action match, wildcard ignored — matches `staffGroupAllows()`). |
| Denied action through the controller | **PASS** — review as the `*`-only group → flash error, no state change, **no audit event**. |
| Mark Manual Review (review) | **PASS** — `pending → manual_review` + `admin.reviewed` audit (`{staff_id, prior_status:pending}`). |
| Cancel | **PASS** — `manual_review → cancelled` + `admin.cancelled` audit. |
| Check Now (recheck) | **PASS** — ran the real reconcile (SOAP inquiry) + `admin.rechecked` audit; the money round-trip itself is proven in AC2. |
| CSRF enforced (NFR14) | **PASS** — a review POST with `verify_csrf_token` on and no token wrote no audit and did not mutate (framework-level CSRF, the same all Blesta admin POSTs get). |
| GET mutation routes | **NOT CAPTURED** — no durable evidence was recorded for the required GET no-mutation check; treat as an open evidence gap until rerun. |
| State guards (`ALLOWED_ACTIONS_BY_STATE`) | **PASS** — transitions honored the allowed-from sets. |
| Audit payloads | redacted (staff_id + prior_status only), lower-dot event names. |

**Prerequisite fix (from the blank-page diagnosis):** the new permissions had to be granted to a staff
group. Granted Administrators; verified pages render. The missing admin `unauthorized.pdt` view was restored
so denied staff get a clean unauthorized message instead of a blank page.

### AC3 status: **PARTIAL until rerun captures GET no-mutation evidence**.

## AC4 — Risk acceptance

Residual risks are recorded and signed in `docs/kuickpay/risk-acceptance-5-1-live-verification.md`. The
live single-inquiry SOAP leg is no longer a residual because it was exercised as supplemental proof; bulk
provider inquiry, lifecycle edge cases against the real provider, and repeatable `InsertVoucher` automation
remain accepted residuals.
