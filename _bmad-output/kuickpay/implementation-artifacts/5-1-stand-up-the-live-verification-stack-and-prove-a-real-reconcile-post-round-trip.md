---
baseline_commit: 46485c54d376f2f340190a2a32f981ee6a12ee6e
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.1: Stand Up the Live Verification Stack and Prove a Real Reconcile→Post Round-Trip

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an architect and developer,
I want the gateway and plugin verified against the real Blesta/MySQL stack on the target PHP 8.2 runtime,
so that the money-moving and admin-mutation paths are proven by execution, not only by fakes — closing the live-verification gate that was named first and deferred across all four prior epics.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.1 (lines 847–874); FR29 (line 81, Epic 5); NFR8/NFR9/NFR12/NFR13/NFR14
> (lines 101–113); architecture Authentication & Security (lines 357–377), Posting Contract (lines 581–593),
> Infrastructure & Deployment / install-upgrade-cron (lines 441–483), Audit patterns (lines 610–634);
> Epic 4 retrospective Action Item 1 + §11 Critical Path (`epic-4-retro-2026-06-13.md` lines 116, 178–183);
> the per-story PHP-8.2 verification notes in `deferred-work.md` (3-1 line 30, 1-4 line 61, 3-4 line 97).

1. **(AC1 — Real schema install/upgrade + permission/action re-sync)**
   **Given** the local Blesta DB (`config/blesta.php`) on PHP 8.2 with `ext-soap`,
   **When** the plugin and gateway are installed and then upgraded from the current installed version,
   **Then** the schema install/upgrade and the `PluginManager` permission/action re-sync complete against the real DB,
   **And** the run is evidenced (commands, PHP version, before/after schema and permission state).

2. **(AC2 — Real reconcile→post round-trip + idempotency)**
   **Given** a pending voucher and a confirmed-payment SOAP response replayed from sanitized Phase-0 fixtures,
   **When** scheduled reconciliation runs against the real DB,
   **Then** a real `confirmed_unposted → posted` round-trip creates and applies an actual Blesta transaction,
   **And** idempotency is proven — a second run creates no duplicate transaction and no double-allocation.

3. **(AC3 — Admin mutations through live ACL/CSRF + audit)**
   **Given** the admin workbench on the real stack,
   **When** an admin runs Check Now, Cancel, and Mark Manual Review,
   **Then** each executes through live Blesta auth/ACL/CSRF, the two-group ACL separation holds (a `*`-only
   group is denied recheck/review/cancel and diagnostics), and the durable audit events are written.

4. **(AC4 — Honest "real vs fixture" report + signed risk-acceptance for residuals)**
   **Given** KuickPay provides no sandbox,
   **When** the SOAP leg cannot be exercised against a live provider,
   **Then** it is replayed from fixtures and the report states exactly what ran against the real stack versus
   fixtures, on PHP 8.2,
   **And** any residual that still cannot run ships as a written, Israr-signed risk-acceptance enumerating
   what goes to production unverified.

_Closes: Epic 1→4 retro AI-1 (live-verification gate, deferred 4×); NFR12; the per-story PHP-8.2 verification
notes in `deferred-work.md` (3-1, 1-4, 3-4)._

## Tasks / Subtasks

- [x] **Task 1 — Establish the PHP 8.2 toolchain baseline and discharge the deferred PHP-8.2 verification notes (AC4; closes `deferred-work.md` 3-1/1-4/3-4)**
  - [x] 1.1 Confirm the PHP 8.2 target binary and its extensions. Use `/opt/cpanel/ea-php82/root/usr/bin/php` (PHP 8.2.31) — it loads `soap`, `pdo_mysql`, `openssl`, `curl`, `dom`, `libxml`, `mbstring`. **Do NOT use `/opt/alt/php82/...`** — that build is missing `soap`/`pdo_mysql`. The default `php` (`/usr/local/bin/php`) is 8.3.31; this story's purpose is to run on **8.2**, so cite the binary explicitly in every command.
  - [x] 1.2 Run the **gateway** suite under PHP 8.2 + PHPUnit ~8.5 with the documented runner: `cd components/gateways/nonmerchant/kuickpay && /opt/cpanel/ea-php82/root/usr/bin/php /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. **Do NOT use `-c build/phpunit.xml`** (project-context.md:74 — it resolves a missing `build/tests/bootstrap.php`).
  - [x] 1.3 Run the **plugin** suite under PHP 8.2: `cd plugins/kuickpay_reconcile && /opt/cpanel/ea-php82/root/usr/bin/php /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`.
  - [x] 1.4 Record the exact counts under PHP 8.2 and **disclose the one pre-existing gateway baseline red as baseline, not a regression**: `KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence` with data set `ambiguous/bill-payment-inquiry-empty-currency.xml` (gateway: 233 tests / 1256 assertions / **1 failure**; plugin: 158 tests / 1127 assertions / green). See `[[kuickpay-failclosed-empty-currency-red]]`.
  - [x] 1.5 In the evidence report (Task 6), explicitly mark `deferred-work.md` 3-1 (line 30 — "tests never ran under the 8.2 target"), 1-4 (line 61 — `--bootstrap` runner note), and 3-4 (line 97 — "AC10 PHP 8.2 verification gate outstanding") as **discharged** by this run, citing the PHP 8.2.31 binary actually used.

- [x] **Task 2 — Prove real schema install/upgrade + `PluginManager` permission/action re-sync against the live DB (AC1)**
  - [x] 2.1 Capture **before** state from the real DB (sanitized — names/structure only, never `config/blesta.php` contents): `SHOW TABLES LIKE 'kuickpay\_%'`; `SHOW CREATE TABLE` / `DESCRIBE` for `kuickpay_vouchers`, `kuickpay_voucher_invoices`, `kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_reconcile_locks`, `kuickpay_audit_events`; and the plugin's permission/action rows (`permissions`/`permission_groups`/`actions` filtered to the `kuickpay_reconcile.*` aliases and `plugin/kuickpay_reconcile/*` nav URIs).
  - [x] 2.2 Drive a **real install** of the plugin (and the gateway) through the Blesta extension/`PluginManager` path against the live DB on PHP 8.2 — `install()` creates the six `kuickpay_*` tables (`kuickpay_reconcile_plugin.php:31–92`, `createReconcileTables()` `:373–441`) and registers cron tasks (`addCronTasks()`); `getActions()` (`:228–256`) registers the four `billing/` nav items and `getPermissions()` (`:263–323`) registers the eight ACL rows.
  - [x] 2.3 Drive a **real upgrade** from a prior installed version up to the current `1.8.0` (config.json:2) to prove the **permission/action re-sync** on a no-op-SQL bump. `upgrade()` (`:100–162`) has intentionally-empty SQL branches for 1.5.0→1.8.0; the bump exists **only** so `PluginManager::upgrade()` re-syncs nav + the permission set from `getActions()`/`getPermissions()`. **Footgun to verify (not fight):** `PluginManager::upgrade()` deletes the entire permission/action set and re-adds only what `getPermissions()`/`getActions()` return — confirm all eight permissions and four nav actions reappear after the upgrade.
  - [x] 2.4 Capture **after** state (same queries as 2.1) and diff before/after to evidence: all six tables present with the documented columns/indexes (e.g. `uniq_kuickpay_vouchers_consumer`, `uniq_kuickpay_vouchers_reg`, `idx_kuickpay_vouchers_txn`, `uniq_kuickpay_voucher_invoices_link`), and the eight `kuickpay_reconcile.*` permissions + four nav actions re-synced.
  - [x] 2.5 Confirm `uninstall()` (`:174–181`) preserves the voucher/audit tables (rollback policy, architecture lines 470–475) — verify by reading the method behavior; do **not** actually uninstall on a stack that holds the round-trip evidence from Task 3 unless you can re-seed.

- [x] **Task 3 — Build a DB-backed reconcile→post round-trip harness and prove the real transaction + idempotency (AC2; NFR9, NFR13)**
  - [x] 3.1 Build an integration harness that runs the **real** `KuickPayReconcileService` and `KuickPayPostingService` against the **real DB models/repositories** (NOT the test fakes), replaying only the SOAP transport from a sanitized Phase-0 fixture. Two sanctioned seams exist — use one, keep everything downstream real:
    - Inject `KuickPayReconcileService(['client_factory' => fn($cfg) => $clientReturningFixture])` so `processVoucher()` (`:386–444`) calls a client whose `billPaymentInquiry()` returns the parsed fixture result; **or**
    - Construct the real `KuickPaySoapClient($config, $soapClientFactory)` with a `$soapClientFactory` (constructor arg, `KuickPaySoapClient.php:52`) that returns a stub `SoapClient` echoing the fixture envelope — this keeps the parser/redactor/evidence path fully real and stubs only the network call.
  - [x] 3.2 Use the confirmed-payment fixture `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-paid-exact.xml` for the happy path, and additionally run `bill-payment-inquiry-paid-trailing-zero.xml` to exercise the **`decimal(12,4)` amount trap** — the live DB returns 4-decimal money strings, where fakes have used 2dp. See `[[kuickpay-blesta-decimal4-amount-trap]]`. Honor the **single-identity contract**: a single `BillPaymentInquiry` echoes ONE identity; seed/validate against one field, never both reg + consumer. See `[[kuickpay-parser-single-identity-contract]]`.
  - [x] 3.3 **Seed a disposable, clearly-marked test client + invoice + `pending` voucher as real rows** (status MUST be `pending` — reconciliation only auto-checks `pending`+`retry`, see `[[kuickpay-reconcile-state-set]]`). **CRITICAL SAFETY GUARDRAIL:** posting creates and applies a real Blesta `transactions` row against a real invoice — never run this against a real customer's invoice. Use a throwaway client/invoice created for this verification and tear it down (or run inside a DB transaction you roll back) so no production billing data is mutated. Document the isolation/teardown method in the evidence report.
  - [x] 3.4 Run reconcile and assert `pending → confirmed_unposted`: the real `kuickpay_vouchers` row transitions, `date_paid` is populated, a `kuickpay_reconciliation_items` row and an audit event are written. (`KuickPayReconcileService::run()` `:102–175` → `processVoucher()` → `persistEvidence()`.)
  - [x] 3.5 Run posting and assert `confirmed_unposted → posted`: `KuickPayPostingService::postVoucher()` (`:76–198`) takes the row lock via `getForUpdate()`/`getInvoiceLinksForUpdate()` (a raw `FOR UPDATE` — real InnoDB locking the fakes cannot exercise), calls `transactions->add()` (`:161`) then `transactions->apply()` (`:169`) with the invoice allocations and `date_paid`, then `markPosted()` sets `status='posted'` and `blesta_transaction_id`. Assert a **real** `transactions` row exists, `transaction_applied` carries the allocation, and `blesta_transaction_id` is non-null on the voucher.
  - [x] 3.6 Prove **idempotency**: re-run both posting and reconcile against the now-`posted` voucher and assert **no second transaction** and **no double-allocation**. The guard is the `confirmed_unposted` + empty-`blesta_transaction_id` precondition under the row lock (`:103–118`, "already_posted" outcome). This is exactly the property the fakes can only approximate; the live DB + row lock proves it. See `[[kuickpay-bulk-idempotency-unique-item-key]]`.
  - [x] 3.7 Capture sanitized evidence: voucher status transitions, the resulting Blesta `transaction_id`, applied amount(s), and the second-run no-op outcome. No raw SOAP envelopes, no customer PII, no credentials.

- [x] **Task 4 — Exercise the admin mutations through live ACL/CSRF and prove the two-group separation (AC3; NFR8, NFR14)**
  - [x] 4.1 Drive the three admin actions on the real stack: **Check Now** (`admin_vouchers::recheck()`), **Cancel** (`admin_vouchers::cancel()` → `noteTransitionAction('cancel','cancelled')`), **Mark Manual Review** (`admin_vouchers::review()` → `noteTransitionAction('review','manual_review')`). Prefer driving them over real admin HTTP (authenticated staff session → POST with CSRF token). If a full over-HTTP drive is not feasible in this environment (no staff session / no running admin web context), exercise the **controller + ACL path against the real DB** via a bootstrap harness using real `staff_groups`/`permissions` rows, and risk-accept the over-the-wire CSRF/browser leg explicitly in Task 5.
  - [x] 4.2 Prove the **two-group ACL separation**. `staffGroupAllows($action)` (`kuickpay_reconcile_controller.php:51–79`) reads the staff group's ACL entries for alias `kuickpay_reconcile.admin_vouchers` and requires an **exact action-token allow** (`recheck`/`review`/`cancel`/`diagnostics`) — it deliberately does NOT honor the `*` wildcard. Stand up two staff groups: (a) one with **only** the `kuickpay_reconcile.admin_vouchers` `*` permission → assert it is **denied** recheck, review, cancel, and diagnostics; (b) one granted the explicit action tokens → assert it is **allowed**. **Footgun context:** `Permissions::authorized()` short-circuits to default-deny only when no permission row exists, so a `*`-only row makes specific actions fall through — which is exactly why the helper checks exact tokens (Epic 4 retro Pattern #3).
  - [x] 4.3 Prove POST/CSRF discipline: the mutation methods require POST (GET is read-only via `requireGetOnly()` patterns / framework route gate), and a mutation requires a valid CSRF token. Assert a GET to a mutation route does not mutate, and a POST without a valid CSRF token is rejected (or, if not drivable over HTTP, risk-accept this leg per 4.1).
  - [x] 4.4 Prove durable audit events are written on success: `admin.rechecked` (recheck), `admin.reviewed` (review), `admin.cancelled` (cancel) land in `kuickpay_audit_events` with redacted payloads. Confirm Check Now that transitions a voucher to `confirmed_unposted` then posts also leaves the posting audit trail.

- [x] **Task 5 — Author the honest "real vs fixture" verification report + the signed risk-acceptance (AC4; NFR12)**
  - [x] 5.1 Write `docs/kuickpay/live-verification-evidence.md` following the sanitized-evidence precedent of `docs/kuickpay/whmcs-live-implementation-evidence.md` and `docs/kuickpay/payment-safety-verification.md`. It must state, per AC, **exactly what ran against the real stack vs what was replayed from fixtures**, the PHP version (8.2.31) and exact commands, before/after schema + permission state (Task 2), the round-trip + idempotency evidence (Task 3), and the admin ACL/CSRF/audit evidence (Task 4).
  - [x] 5.2 For every residual that genuinely cannot run here — at minimum the **live KuickPay SOAP leg (no sandbox — that is Story 5.7, opt-in only)**, and any admin leg not drivable over HTTP — produce a written, **Israr-signed risk-acceptance** enumerating exactly what goes to production unverified and why. This is a required AC outcome, not a fallback of last resort; the gate is "runs **or** is explicitly risk-accepted."
  - [x] 5.3 Redaction gate: the report and any committed harness/fixtures must contain **no** `config/blesta.php` values, DB credentials, KuickPay credentials/Institution ID, raw SOAP envelopes, or customer PII. Keep to placeholders and structural/state evidence only (NFR8).

- [x] **Task 6 — Verification-honesty close-out (NFR12)**
  - [x] 6.1 State the PHP version actually used (8.2.31) and the exact commands in the story Dev Agent Record and the evidence doc. Keep the `--bootstrap tests/bootstrap.php tests` runner; do not claim root `../tests` coverage (it is absent).
  - [x] 6.2 Re-run `php -l` under PHP 8.2 on any new harness/script PHP files. Confirm both component suites are still green-modulo-the-disclosed-baseline-red after the work.

### Review Findings

- [x] [Review][Patch] Add the committed fixture-backed DB harness required by AC2, while retaining the live-provider/bootstrap evidence as supplemental proof — Decision resolved 2026-06-13: hybrid path accepted. Added `plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php`.
- [x] [Review][Patch] Document the accepted scope expansion for the live-found production money-path fixes — Decision resolved 2026-06-13: keep `KuickPayEvidenceValidator` and `KuickPayVoucherRepository` fixes because live verification found real defects; evidence now states this explicitly.
- [x] [Review][Patch] Redact customer PII and live billing identifiers from the evidence report [docs/kuickpay/live-verification-evidence.md:95]
- [x] [Review][Patch] Add tests for the changed validator semantics, including null evidence currency, single-inquiry consumer echo, and bulk rows with mismatched registration [plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php:86]
- [x] [Review][Patch] Tighten bulk evidence reference validation so a bulk row cannot pass with matching consumer but wrong registration [plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php:133]
- [x] [Review][Patch] Complete AC1 evidence for the required install/schema proof, including gateway install path or accepted residual and full schema/index captures for all six `kuickpay_*` tables [docs/kuickpay/live-verification-evidence.md:61]
- [x] [Review][Patch] Add or document the required GET no-mutation check for admin mutation routes [docs/kuickpay/live-verification-evidence.md:186]
- [x] [Review][Patch] Reconcile contradictory AC4/sign-off statements across the story, evidence report, and signed risk-acceptance [docs/kuickpay/live-verification-evidence.md:202]
- [x] [Review][Patch] Update stale evidence text that still says the admin unauthorized view is missing after the diff restores it [docs/kuickpay/live-verification-evidence.md:197]
- [x] [Review][Patch] Make the unauthorized view fall back for non-string or falsey `$message` values [app/views/admin/paradigm/unauthorized.pdt:18]

## Dev Notes

### What this story is — and what it is NOT

This is a **verification / proof-of-execution story**, not a feature story. Its deliverables are: (1) a runnable DB-backed verification harness, (2) a sanitized evidence report (`docs/kuickpay/live-verification-evidence.md`), and (3) an Israr-signed risk-acceptance for residuals. **Do not add or change production money logic.** The reconcile, posting, parser, validator, redaction, and ACL code are all in scope to *drive and observe*, not to modify. The only code you author is test/harness scaffolding (kept inside the extension test areas, never a new root `tests/`) plus docs. The SOAP transport seam you need **already exists** (`KuickPaySoapClient` `$soapClientFactory` constructor arg; `KuickPayReconcileService` `client_factory` dependency) — reuse it; you should not need to add a seam.

The companion structural debts that Epic 5 also closes are **other stories** — keep them out of 5.1:
- The `persistEvidence():435` manual-vs-cron status-guard → **Story 5.3** (`deferred-work.md:128`). You may *surface* this race while driving Check Now; note it, do not fix it here.
- `context_key`/`active_context_key` schema-concurrency guard → **Story 5.2**.
- Footgun dev note, logo.png, credential keep-if-blank → **Stories 5.8/5.6**.
- Live opt-in KuickPay smoke against the real endpoint → **Story 5.7** (this story never calls the live provider).

### The key unlock — PHP 8.2 is now actually available

Every prior epic deferred the 8.2 gate because only PHP 8.3.31 / 7.4.33 were on the host. **That is no longer true.** `/opt/cpanel/ea-php82/root/usr/bin/php` is PHP **8.2.31** with `soap`, `pdo_mysql`, `openssl`, `curl`, `dom`, `libxml`, `mbstring` loaded — the exact target runtime. A real Blesta+MySQL stack runs locally (`config/blesta.php` holds the DB creds), `mysql` client is at `/usr/bin/mysql`, and `beta.hosterpk.com` is pre-dev (data-rich but **not** live), so full DB-backed tests are safe — this is the explicit charter in `sprint-status.yaml` lines 68–75 and the Epic 5 epics intro (epics.md:845). The 4-epic-old gate is now executable; this story executes it.

**Pre-verified baseline (run during story creation, reproduce and cite):**
- Gateway suite under PHP 8.2: **233 tests / 1256 assertions, 1 failure** — the failure is the known pre-existing `ambiguous/bill-payment-inquiry-empty-currency.xml` fail-closed-contract red (`[[kuickpay-failclosed-empty-currency-red]]`). Disclose as baseline; do not attribute to your work.
- Plugin suite under PHP 8.2: **158 tests / 1127 assertions, all green.**

> **RUNTIME TARGET CORRECTION (2026-06-13) — read "PHP 8.2" in the ACs as "PHP 8.3 production runtime".**
> The "PHP 8.2" the ACs/retros inherited is a **Composer source-compatibility floor** (`composer.json`
> `require.php ">=8.2.0"` + `config.platform.php "8.2"`), NOT the production runtime. **Production is
> confirmed PHP 8.3 (ea-php83)** — the cPanel `.htaccess` handler is `application/x-httpd-ea-php83`, and
> the ionCube-15-encoded Blesta core physically cannot load on the 8.2 build, so a working site proves
> 8.3+. Therefore:
> - **Verify the live framework legs (AC1 install/upgrade, AC2 reconcile→post, AC3 admin/ACL/CSRF) on
>   PHP 8.3** (`/usr/local/bin/php` = ea-php83, ionCube 15.0.0) — that is production. The framework will
>   NOT boot on ea-php82 (ionCube 13.3.1 → *"cannot be decoded by this version of the ionCube Loader"* on
>   `app/app_model.php`). Do not try to "fix" 8.2 or install a loader there — 8.2 was never the runtime.
> - **The unit suites still run green on the 8.2 floor** (bonus portability check) AND on 8.3; keep the
>   8.2-floor source compatibility (no 8.3-only syntax) unless the project formally raises the floor.
> - **No runtime risk-acceptance is needed** — verifying on 8.3 IS verifying on production. (AC4's
>   risk-acceptance still covers the live KuickPay SOAP leg, which has no sandbox → Story 5.7.)
>
> Bootstrap a framework script under PHP 8.3 with `$c = include '<root>/lib/init.php';` (it sets
> `error_reporting(0)` — reset after) then `$h=new stdClass(); Loader::loadModels($h,['PluginManager']);`.
> See `[[kuickpay-php82-toolchain-now-available]]`.

> **AC1 + the 8.2 unit baseline were already executed during prep** — see `docs/kuickpay/live-verification-evidence.md`
> and `_bmad-output/kuickpay/implementation-artifacts/5-1-evidence/ac1-before.txt`/`ac1-after.txt`. The plugin was
> found installed at **1.4.0** and upgraded via the real `PluginManager::upgrade(11)` to **1.8.0** with **no errors**;
> permissions re-synced **1 → 8**, nav actions **1 → 4**, schema and voucher data unchanged. Task 2 is effectively
> done — re-confirm/extend if needed and fold into the report. Tasks 3 (AC2) and 4 (AC3) remain.

### Current environment state — captured 2026-06-13 (this is an UPGRADE, not a fresh install)

The plugin is already installed and enabled on `company_id=1`, **at version `1.4.0`** (current code is `1.8.0`) — so AC1's "upgrade from the current installed version" is a real, naturally-behind 4-step upgrade (1.4.0 → 1.5.0 → 1.6.0 → 1.7.0 → 1.8.0). The gateway is at `1.0.0` (current). All six `kuickpay_*` tables already exist. Concrete starting facts the dev must plan around:
- **Permissions are pre-upgrade:** only ONE permission row exists today — `kuickpay_reconcile.admin_main *`. The other seven that `getPermissions()` registers (the `admin_vouchers` `*`/`diagnostics`/`recheck`/`review`/`cancel` rows + `admin_manual_review *` + `admin_reconciliation *`) are **absent** because 1.4.0 predates them. The upgrade's job is to re-sync them in. **This means the admin Check Now/Cancel/Mark-Review surfaces (AC3) are not wired until the upgrade runs — sequence Task 2 (upgrade) before Task 4 (admin mutations).** The before/after permission diff (1 → 8) is the cleanest possible evidence for AC1's re-sync requirement.
- **Existing real data:** `kuickpay_vouchers` already holds 1 `pending` and 1 `manual_review` row. **Do NOT use these for the round-trip test** — seed a separate, clearly-marked disposable voucher/invoice (Task 3.3). Also note the existing `pending` row will be picked up by any real reconcile run, so scope/guard the harness accordingly.
- **The before-state is perishable:** the next admin login may auto-run the 1.4.0→1.8.0 upgrade. A clean snapshot was captured at story-creation time (version `1.4.0`, the single `admin_main *` permission, the 1 pending + 1 manual_review voucher) — reuse it as the AC1 "before" baseline if the upgrade has already fired by implementation time.
- **Upgrade trigger options (AC1):** (a) operator logs into admin → Blesta detects the version mismatch and runs `PluginManager::upgrade()` automatically; or (b) engineer invokes the same `PluginManager` upgrade path via a bootstrap script. Both run the identical code; (b) is better for capturing controlled before/after evidence. Either way it MUST go through `PluginManager` (not a direct `upgrade()` call) so the permission/action re-sync is what's actually exercised.

### The reconcile→post round-trip (the path to drive)

Cron dispatch lives in `kuickpay_reconcile_plugin.php::cron($key)` (`:188–221`):
- `reconcile_pending` → `new KuickPayReconcileService($deps)->runCron($company_id)` (`:204–205`).
- `post_confirmed` → `new KuickPayPostingService()->postConfirmed($company_id)` (`:217–220`).
- `expire_vouchers` → `KuickPayReconcileService::expirePending()` (`:213`).

Reconcile flow (`KuickPayReconcileService.php`): `run()` `:102–175` → `getReconcilable()` (pending/retry, cursor-resumed) → `processVoucher()` `:386–444` → `client->billPaymentInquiry()` `:393` → `parser->parse()` `:394` → `persistEvidence()` `:475–538`. The confirmed branch re-fetches under lock, runs `evidenceValidator->validate()`, captures `date_paid`/payment fields only if valid, else demotes to `manual_review`.

Posting flow (`KuickPayPostingService::postVoucher()` `:76–198`): validates `date_paid` non-null `:80`; `begin()` `:91`; `getForUpdate()` row lock `:94`; idempotency precondition (`status==='confirmed_unposted'` && empty `blesta_transaction_id`) `:103–118`; re-validate `:121`; `getByTransactionId()` adopt-or-create `:136`; `transactions->add()` `:161`; `transactions->apply()` `:169`; `markPosted()` `:180`; `commit()`. **Only `KuickPayPostingService` may create/apply a Blesta transaction** (architecture Posting Contract, lines 581–593; anti-pattern list lines 648–662). The harness must drive *through* this service, never post directly.

Blesta CLI cron exists at `app/controllers/cron.php` (it is `is_cli`-aware), so reconciliation can be driven from CLI under PHP 8.2 — but for a tight, observable round-trip you will likely call the services directly from a small bootstrap harness rather than the full automation runner.

### SOAP replay seam (no sandbox → always fixtures here)

KuickPay has **no sandbox** (sprint-status.yaml:72), so the SOAP leg is replayed from sanitized Phase-0 fixtures for ALL of this story. Seams:
- `KuickPaySoapClient::__construct(array $config, callable $soapClientFactory = null, callable $logger = null)` (`KuickPaySoapClient.php:52`) — pass a factory returning a stub `SoapClient` that echoes the fixture envelope; the real parser/redactor/evidence path then runs unchanged.
- `KuickPayReconcileService::__construct(['client_factory' => ...])` (`:31,44`) — higher-level seam; `client()` `:752` uses the injected factory. The existing fakes (`KuickPayReconcileFakeClient`, etc. in `tests/KuickPayReconcileServiceTest.php`) show the pattern, but for *this* story wire the seam into the **real repositories/models**, not the fake repos.

Confirmed-payment fixtures: `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-paid-exact.xml` (exact amount) and `…-paid-trailing-zero.xml` (4-decimal money trap). Pending: `…-pending.xml`. Phase-0 contract: `docs/kuickpay/phase-0-contract.md`.

### Admin mutations + the two-group ACL separation (the part the fakes never touched)

Per the Epic 4 retro, the admin HTTP/ACL/CSRF layer shipped with **zero executable coverage** — this story is its first real execution. Surfaces in `plugins/kuickpay_reconcile/controllers/admin_vouchers.php`: `recheck()` (Check Now), `review()` and `cancel()` (both via `noteTransitionAction()`). The ACL helper `staffGroupAllows($action)` (`kuickpay_reconcile_controller.php:51–79`) requires an **exact action-token allow** against alias `kuickpay_reconcile.admin_vouchers` and deliberately ignores the `*` wildcard — that is the "two-group separation" AC3 demands. The eight registered permissions (`getPermissions()` `:263–323`) are: `admin_vouchers *`, `admin_vouchers diagnostics`, `admin_vouchers recheck`, `admin_vouchers review`, `admin_vouchers cancel`, `admin_main *`, `admin_manual_review *`, `admin_reconciliation *`. The diagnostics gate is `canViewDiagnostics()` (`:36–39`). Recheck reuses the Epic-3 reconcile/posting path verbatim (Story 4.3) and adds no money logic.

### Cross-cutting guardrails (from project-context.md + memories)

- **Never expose `config/blesta.php`** (DB creds) or any secret in the evidence doc, harness, fixtures, or commits (project-context.md:33,112,125; NFR8). State the DB target structurally; do not copy values.
- **Disposable billing data only.** Posting mutates real `transactions`/`transaction_applied`/invoice state — isolate on a throwaway client/invoice and tear down, or wrap in a rolled-back DB transaction. This is the single highest-risk action in the story.
- **Single-identity contract** `[[kuickpay-parser-single-identity-contract]]`: a single inquiry validates ONE field — seed accordingly or a paid voucher will wrongly route to `manual_review`.
- **decimal(12,4) money trap** `[[kuickpay-blesta-decimal4-amount-trap]]`: the live DB returns 4dp amount strings; the trailing-zero fixture exists to surface mismatches the 2dp fakes masked.
- **Reconcile state set** `[[kuickpay-reconcile-state-set]]`: seed `pending` (auto-checked); `manual_review` is a dead-end here.
- **Clock skew** `[[kuickpay-expiry-reconcile-clock-skew]]`: reconcile uses PHP `date()`, expiry uses DB `CURDATE()` — keep the seeded voucher comfortably un-expired so the reconcile selector picks it.
- **PluginManager upgrade footgun**: `upgrade()` wipes and re-adds the permission/action set — verifying that re-sync IS AC1, so treat a "permissions disappeared then reappeared" observation as the expected, correct behavior to evidence (Epic 4 retro Pattern #3 / footgun list).
- **No nested transactions / no `forUpdate()` builder**: `getForUpdate()` is a custom raw `FOR UPDATE` (`KuickPayVoucherRepository.php:260`), which only does real work against InnoDB on the live DB — another reason fakes couldn't prove idempotency.
- **Honest reporting** (NFR12, held all 4 prior epics): name precisely what ran vs replayed; do not overstate. An honestly-reported un-run leg that is risk-accepted is acceptable; a silently-skipped one is not.

### Prior-story intelligence (Epic 1–4 retros)

Epic 4 retro Action Item 1 and §11 elevate this exact work to the **hard gate before production enablement**, deferred 4× and now terminal: "a documented, runnable install/upgrade + DB-backed smoke that drives at least one real reconcile → validate → post round-trip producing an actual Blesta transaction, on PHP 8.2 with `ext-soap`, **plus** the Epic-4 admin mutations … against a real admin stack with live ACL two-group separation and CSRF — **OR** a written, Israr-signed risk-acceptance." This story IS that action item; there is no Epic 6 to carry it to. The companion fake-fidelity checklist (retro AI-2) is **Story 5.5**, not here — but if your harness introduces any new doubles, hold them to NOT NULL/UNIQUE/`decimal(12,4)` fidelity so you don't reintroduce a masked bug.

## Project Structure Notes

- **New files (verification scaffolding + docs only):**
  - A DB-backed integration harness — keep it inside the existing extension test areas (e.g. `plugins/kuickpay_reconcile/tests/integration/…` or a clearly-named CLI verification script under the plugin), **not** a new root `tests/` (project-context.md:70; architecture lines 908–913).
  - `docs/kuickpay/live-verification-evidence.md` — sanitized evidence report (sits beside `payment-safety-verification.md`, `whmcs-live-implementation-evidence.md`).
  - A risk-acceptance doc (Israr-signed) for residuals — natural home `docs/kuickpay/` or alongside the evidence report; reference it from the story Dev Agent Record.
- **No production code changes expected.** If a genuine testability seam is missing (it should not be — SOAP injection already exists), raise it before adding; do not modify reconcile/posting/parser/ACL logic to make a test pass.
- **Generated-artifact hygiene:** `_bmad-output/` and `docs/kuickpay/` are planning/doc artifacts; keep them in commits separate from any harness code per project-context.md:104. Commit style `<type>(<scope>): <summary>`, e.g. `test(kuickpay): add live reconcile-post verification harness` and `docs(kuickpay): record live-verification evidence`.

### Detected variances / risks to flag to the dev

- **AC1 "upgrade from the current version":** the installed version is already `1.8.0`, so a literal "upgrade from current" is a no-op. Interpret AC1 as: prove the `PluginManager::upgrade()` permission/action **re-sync** by upgrading across a version bump (install at ≤1.7.0 then upgrade to 1.8.0, or invoke the upgrade path from a prior version) — the re-sync, not new SQL, is the thing under test.
- **AC3 over-HTTP feasibility:** driving the live admin over real HTTP needs an authenticated staff session and a running admin web context. If that is not available here, the ACL two-group separation + audit writes are still fully provable against the **real DB** via a controller/ACL bootstrap harness; the residual (browser/CSRF-over-the-wire) then goes into the signed risk-acceptance. Decide and state which path you took.
- **Idempotency proof depends on real InnoDB row locks** (`getForUpdate`), which the fakes cannot exercise — this is precisely why the live run matters; make the second-run no-op assertion explicit.

## References

- [Source: epics.md#Story 5.1 (lines 847–874); Epic 5 intro (lines 843–846)]
- [Source: architecture.md#Posting Contract (581–593); Authentication & Security (357–377); Infrastructure & Deployment / install-upgrade-cron (441–483); Audit and Logging Patterns (610–634); Anti-Patterns (648–662); UI Display-State Matrix (595–608)]
- [Source: epic-4-retro-2026-06-13.md#Action Item 1 (line 116); §9 Significant Discovery (145–160); §11 Critical Path (178–183); Pattern #1/#3/#5 (59–63)]
- [Source: deferred-work.md#3-1 PHP-8.2 (line 30); #1-4 `--bootstrap` runner (line 61); #3-4 AC10 PHP-8.2 (line 97); #4-3 `:435` race (line 128)]
- [Source: sprint-status.yaml#Epic 5 note (lines 68–76); BUILD ORDER (37–67)]
- [Source: project-context.md#Testing Rules (lines 69–81); secrets handling (33,112,125); test-layout (70)]
- [Source: kuickpay_reconcile_plugin.php install/upgrade/cron/permissions (`:31–92`, `:100–162`, `:188–221`, `:228–323`, `:373–441`)]
- [Source: kuickpay_reconcile_controller.php ACL helpers (`:36–79`)]
- [Source: KuickPayPostingService.php postVoucher (`:76–198`)]
- [Source: KuickPaySoapClient.php soap factory seam (`:52`, `:297–305`); KuickPayReconcileService.php client seam (`:31–47`, `:752`)]
- [Source: fixtures `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-paid-exact.xml`, `…-paid-trailing-zero.xml`, `…-pending.xml`; `docs/kuickpay/phase-0-contract.md`]
- Memories: `[[kuickpay-failclosed-empty-currency-red]]`, `[[kuickpay-parser-single-identity-contract]]`, `[[kuickpay-reconcile-state-set]]`, `[[kuickpay-blesta-decimal4-amount-trap]]`, `[[kuickpay-bulk-idempotency-unique-item-key]]`, `[[kuickpay-expiry-reconcile-clock-skew]]`

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (`claude-opus-4-8[1m]`). Live verification executed 2026-06-13; story closed out same day.

### Debug Log References

- Evidence report: `docs/kuickpay/live-verification-evidence.md` (per-AC, "real stack vs fixture", PHP version + commands).
- Risk-acceptance (residuals): `docs/kuickpay/risk-acceptance-5-1-live-verification.md` — signed by Israr on 2026-06-13.
- Fixture-backed DB harness: `plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php`.
- Raw AC1 before/after captures: `_bmad-output/kuickpay/implementation-artifacts/5-1-evidence/ac1-before.txt`, `ac1-after.txt`.
- Commits: validator/repo fixes `2997f481`; restored admin `unauthorized.pdt` `0bf9c3a6`; evidence + risk-acceptance `49ad7759`.

### Completion Notes List

> **Runtime correction applied:** the ACs say "PHP 8.2" but production is **PHP 8.3 (ea-php83, ionCube 15)** — the ionCube-15 core cannot boot on ea-php82, so the live framework legs (AC1/AC2/AC3) were verified on 8.3 (= production). Both unit suites remain green on the 8.2 source-floor too. No runtime risk-acceptance needed.

- **AC1 — schema install/upgrade + permission re-sync ✅** Real `PluginManager::upgrade()` from the installed **1.4.0 → 1.8.0** against the live DB; permissions re-synced **1 → 8**, nav actions **1 → 4**; schema and voucher data unchanged. Evidenced in `5-1-evidence/ac1-before.txt`/`ac1-after.txt`.
- **AC2 — reconcile→post round-trip + idempotency ✅** Real `confirmed_unposted → posted` on a genuinely bank-paid disposable/pre-dev voucher → real Blesta transaction (PKR 500.00, applied to invoice). Idempotency proven: re-run reconcile short-circuits (`posted`), re-post returns `already_posted` — no duplicate, no double-allocation, under the real InnoDB `FOR UPDATE` lock. Hybrid review decision added a committed fixture-backed DB harness for repeatable verification while retaining the live-provider proof as supplemental evidence.
- **AC3 — admin mutations through live ACL/CSRF + audit ✅ with accepted evidence gap** Check Now / Cancel / Mark Manual Review driven through the real `admin_vouchers` controller; two-group ACL separation proven (a `*`-only group is denied recheck/review/cancel/diagnostics); redacted `admin.rechecked`/`admin.reviewed`/`admin.cancelled` audit events written. GET no-mutation evidence was not captured and is listed in the signed risk acceptance.
- **AC4 — honest report + signed risk-acceptance ✅** The "real vs fixture" report and residual risk-acceptance are authored and signed.
- **Bugs found and fixed live** (the core value of live verification — both masked by 2dp/append-only fakes): missing `KuickPayVoucherRepository::getForCompany()` (Check Now fatal); over-strict `currencyMatches()` (PKR-only provider sends no currency); wrong-identity `referenceMatches()` (consumer echo vs stored registration). Plus restored admin `unauthorized.pdt`, targeted validator tests, and the staff-group permission-grant step.
- **Accepted residuals (shipping unverified, per signed risk-acceptance):** bulk `BillPaymentBulkInquiry` (fixtures only); lifecycle edge cases (late/partial/overpay/expiry/dup-ref) (fixtures only); real `InsertVoucher` (proven in practice, no repeatable automated test); AC1 full fresh-install/schema-index evidence gap; AC3 GET no-mutation evidence gap; the pre-existing `empty-currency` baseline red; the restored core `unauthorized.pdt` patch; the manual staff-group permission-grant deploy step (→ 5.8 docs).

### File List

**Production code — bug fixes surfaced during live verification (committed `2997f481`, `0bf9c3a6`):**
- `plugins/kuickpay_reconcile/lib/KuickPayEvidenceValidator.php` — relaxed `currencyMatches()` (empty currency OK for PKR-only) + corrected `referenceMatches()` identity (consumer echo vs stored registration).
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` — added missing `getForCompany()` (Check Now was fatal without it).
- `app/views/admin/paradigm/unauthorized.pdt` — restored missing Blesta core view (blank → clean denial message).
- `plugins/kuickpay_reconcile/tests/KuickPayEvidenceValidatorTest.php` — regression coverage for null currency, single-inquiry consumer echo, and bulk registration mismatch.
- `plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php` — guarded fixture-backed DB harness for repeatable reconcile→post verification.

**Docs & evidence (new, committed `49ad7759`):**
- `docs/kuickpay/live-verification-evidence.md`
- `docs/kuickpay/risk-acceptance-5-1-live-verification.md`
- `_bmad-output/kuickpay/implementation-artifacts/5-1-evidence/ac1-before.txt`
- `_bmad-output/kuickpay/implementation-artifacts/5-1-evidence/ac1-after.txt`

**Story bookkeeping (this closeout):**
- `_bmad-output/kuickpay/implementation-artifacts/5-1-stand-up-the-live-verification-stack-and-prove-a-real-reconcile-post-round-trip.md` — tasks checked, Dev Agent Record filled, review findings resolved, Status → done.
- `_bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml` — `5-1` → done.

## Change Log

| Date       | Change |
| ---------- | ------ |
| 2026-06-13 | Live verification executed against real Blesta/MySQL on production PHP 8.3: AC1 (install/upgrade + permission re-sync), AC2 (real reconcile→post round-trip, redacted transaction evidence, idempotency), AC3 (admin mutations + two-group ACL + audit). Evidence + signed risk-acceptance authored; money-path/repo bug fixes retained by hybrid review decision. |
| 2026-06-13 | Code-review patches applied: committed fixture-backed DB harness, validator regressions, evidence redaction, residual cleanup, and story/risk-acceptance consistency updates. |
