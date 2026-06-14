---
baseline_commit: f44bd841166ea7f0fd01211796f5fd28c2d610a9
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.4: Audit, Logging, and Redaction Completeness

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator,
I want every operation path to leave a durable, fully-redacted trace,
so that investigations are complete and no sink can leak secrets.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.4 (lines 929–955); `deferred-work.md` items —
> 4-5 review bulk `evidence.error` (`:350`, line 132) and `voucher.generation_failed` `invoice_id` mislabel (`:290`, line 133);
> 2-4 review `create_failed` fall-through (`:174`, line 103);
> 3-1 review `redactEnvelope` attributes/aliases (`KuickPayRedactor.php:77`, line 29) and `isTimeout` locale (line 28);
> 1-3 review `maskCredentials` non-array/object/non-string + case-sensitive allowlist (`kuickpay.php:291-294` + base `gateway.php`, lines 51, 53);
> 3-8 review leak-scan PII/credential pattern breadth (`KuickPaySecretLeakageTest.php:183-202`, line 7) and 0-1 mixed-placeholder note (line 36);
> architecture **Audit** (610–634), **Redaction boundary** (371–373, 397, 771), **ownership** (522, 656, 669, 765);
> NFR8 admin-only/no-secret diagnostics (epics.md:101); NFR13 decimal/minor-unit amounts, never floats (epics.md:111).

**⚠️ SCOPE REALITY CHECK — read before coding.** Several items this story "closes" were **already implemented** by Story 5.3's review fixes after the deferred-work notes were written. The dev agent MUST verify current HEAD state per the AC notes below and **not re-implement working code**. Where an AC is already satisfied at runtime, the work is a **dedicated regression test + honest verification record**, not new production code. Do not claim new implementation for code that already exists; do not delete/rewrite working code to "match" a stale deferred-work line.

1. **(AC1 — Bulk per-Voucher exception is audited symmetrically with the single path)**
   **Given** a per-Voucher exception thrown by `persistVoucherOutcome()` inside the bulk reconcile loop,
   **When** the bulk loop's inner `catch (Throwable)` handles it,
   **Then** it emits a best-effort `evidence.error` audit event **and** writes a `kuickpay_reconciliation_items` row (`error_class='reconcile_exception'`), symmetric with the single-Voucher `processVoucher()` failure path.
   **HEAD STATE:** ✅ **already satisfied** — the bulk catch at `KuickPayReconcileService.php:332-346` calls the shared `recordProcessVoucherFailure()` (`:453-485`), which writes the item row **and** the `evidence.error` audit (landed in Story 5.3 commit `01682753`). The single path calls the same helper at `:401`.
   **5.4 WORK:** add a **dedicated regression test** in `KuickPayReconcileServiceTest.php` that drives a bulk run where one voucher's `persistVoucherOutcome()` throws and asserts **both** (a) an `evidence.error` audit event for that `voucher_id`, and (b) a `kuickpay_reconciliation_items` row with `error_class='reconcile_exception'` — locking the symmetry so a future refactor cannot silently regress it. Also assert idempotency: a bulk failure row colliding with a prior success row for the same `(run_id, voucher_id)` is swallowed (the helper's inner try/catch) and does **not** abort the run.
   **And** the run-detail visibility of `evidence.error` stays **symmetric with the single path** — i.e. left OFF the 4.4 run-detail allowlist (see AC1 note in Dev Notes); do **not** add it to `getByRun`/`getCountByRun` unless explicitly directed (out of this AC's literal scope).

2. **(AC2 — `voucher.generation_failed` names the actual conflicting invoice, and create() fall-through is never traceless)**
   **Given** a `duplicate_invoice_id` conflict on the multi-invoice (`multi_invoice_policy='allow'`) path,
   **When** `recordGenerationFailed(...)` emits the `voucher.generation_failed` audit event,
   **Then** the payload's `invoice_id` names the **actually-conflicting** invoice (the one whose duplicate-with-differing-amount triggered the failure in `normalizeContextInvoiceAmounts()` at `KuickPayVoucherReferenceService.php:444`), **not** `firstContextInvoiceId()` (`:291-296`, the first context row).
   **And** the conflicting id is surfaced via a **private member** (e.g. `$this->conflictInvoiceId`, reset at method entry) — the pure validator's signature/return MUST NOT change (4.5 Task-5 constraint; deferred-work line 133).
   **Given** the benign `create()` fall-through (`:186 return null`, reached after `repository->create()` returns falsy at `:171` **and** the race-recovery re-lookup at `:176-181` finds no winner),
   **When** that path returns null,
   **Then** it sets a `create_failed` diagnostic (`$this->lastError = 'create_failed'`) so the gateway's `recordReferenceGenerationFailure()` (`kuickpay.php:1376-1397`) emits a non-null `reason` instead of returning early on null (`:1383`) — no failure is traceless.
   **Scope guard:** the `create_failed` code is set **only** on the genuine fall-through — NOT on the race-recovery return (`:179-181`, a concurrent winner exists → benign success) and NOT on the outer `catch (Throwable)` (`:182-184`). Recommended (completeness-maximizing, symmetric with `uniqueness_exhausted` at `:140`): also emit the durable `recordGenerationFailed($company_id, $invoice_id, 'create_failed')` on the fall-through so the trace is durable audit, not only an operational gateway log.

3. **(AC3 — Redactor and credential masker mask attributes, aliases, and non-string inputs under a case-insensitive allowlist)**
   **Given** the SOAP envelope redactor `KuickPayRedactor::redactEnvelope()` (`:95-145`),
   **When** an envelope carries sensitive values in **XML attributes** (e.g. `<Customer name="…"/>`) or under **aliased element names** the exact-local-name XPath (`:116-127`) does not match,
   **Then** those are masked too — extend masking to **attribute values** whose attribute local-name is in the sensitive lookup, and cover the confirmed aliases. The existing element-text masking (`:117-127`, case-insensitive via `translate(...)`) and the `*Result` blanking (`:133-138`, covers the bulk CDATA payload) MUST be preserved.
   **Given** the gateway credential masker `maskCredentials()` (`kuickpay.php:406-408`),
   **When** **non-array / object / non-string** values reach it (object graphs, `null`, bools, nested objects),
   **Then** they are masked safely — `null`→`null`, array/non-stringable object→a fixed token (`'xxxx'`), scalar→string-cast then masked — with **no `TypeError`/deprecation** (the current path delegates to base `Gateway::maskDataRecursive` → `maskValue` which `str_repeat(…, strlen($value))` and throws on non-strings).
   **And** the credential allowlist match is **case-insensitive** (base `array_search` at `gateway.php:350` is case-sensitive; `$credential_mask_fields` at `kuickpay.php:26-35` misses lowercase `username`/`USERNAME` and other casings).
   **HARD CONSTRAINT:** **do NOT edit `components/gateways/lib/gateway.php`** — it is the shared base class for every Blesta gateway. The fix lives entirely in the gateway-owned `maskCredentials()` + `$credential_mask_fields` (architecture's "two intentional layers"; keep the gateway and redactor credential keys in sync per `KuickPayRedactor.php:5-7`).
   **And** the redactor's **array path** (`maskDataRecursive` `:301-325` + `maskValue` `:355-391`) is already hardened (case-insensitive, null/array/object-safe) — reuse its approach; do **not** regress it.

4. **(AC4 — Leak-scan covers diverse PII/placeholder formats and `isTimeout()` is locale-independent)**
   **Given** the secret-leakage suite `KuickPaySecretLeakageTest.php` forbidden-pattern sets (`fixtureForbiddenPatterns()` `:307-317`, `persistedForbiddenPatterns()` `:319-326`),
   **When** fixtures diversify,
   **Then** the PII/credential patterns cover **alternate formats** (international/dashed/spaced mobile beyond bare `/\b03\d{9}\b/`; undashed 13-digit CNIC beyond `/\b\d{5}-\d{7}-\d\b/`) and **mixed placeholder styles** (e.g. `0300XXXXXXX`, masked `xxxx`, alternate `REDACTED_*` casings) — **while the suite stays GREEN**: every broadened forbidden pattern MUST be paired with (a) a diversified fixture that exercises it and (b) an allow-list of the mixed placeholder styles actually used, so clean placeholders never false-positive (deferred-work line 7 warns broadening risks false positives).
   **Given** `KuickPaySoapClient::isTimeout()` (`:462-464`),
   **When** a transport timeout is classified,
   **Then** classification is **locale-independent** — it MUST NOT depend solely on substring-matching localized exception text (`/timeout|timed out|temporarily unavailable/i`), which OS-localized socket `strerror`/`LC_MESSAGES` output can defeat. Classify on a stable signal (recommended: attempt duration ≈ configured `timeout()` ceiling, threaded into the classifier; secondary: PHP-internal English markers, which are not OS-localized). Label-only impact (both `timeout` and `transport_error` retry identically per AC6) — keep the change minimal and deterministic under any locale; add a test with a localized timeout message asserting correct classification.

## Tasks / Subtasks

- [x] **Task 1 — AC1: lock bulk `evidence.error`/item-row symmetry with a regression test (NO production change expected)**
  - [x] 1.1 Re-read `KuickPayReconcileService.php:328-348` (bulk inner catch) and `:453-485` (`recordProcessVoucherFailure`). Confirm the bulk catch already emits `evidence.error` + item row via the shared helper (landed in `01682753`). If — and only if — HEAD differs from this, wire the bulk catch to `recordProcessVoucherFailure()` mirroring the single path (`:401`).
  - [x] 1.2 In `KuickPayReconcileServiceTest.php`, add a test: bulk run where one voucher's `persistVoucherOutcome()` throws → assert an `evidence.error` audit event for that `voucher_id` AND a `kuickpay_reconciliation_items` row with `error_class='reconcile_exception'`. (The leak suite's `captureEvidenceErrorAudit()` covers the single path; this is the missing **bulk** assertion.)
  - [x] 1.3 Add an idempotency assertion: a bulk failure item write colliding with a prior `(run_id, voucher_id)` row is swallowed by the helper's inner try/catch and the run completes (no abort). Use a fake item repository that throws a unique-key error on the second `(run_id, voucher_id)` insert.
  - [x] 1.4 Do NOT add `evidence.error` to `getByRun`/`getCountByRun` (keeps single/bulk run-detail visibility symmetric). Note the decision in the Dev Agent Record.
- [x] **Task 2 — AC2: precise `invoice_id` + `create_failed` traceability**
  - [x] 2.1 Add `private $conflictInvoiceId = null;` to `KuickPayVoucherReferenceService`; reset it at `getOrCreateForInvoiceContext()` entry alongside `$this->lastError = null` (`:62`).
  - [x] 2.2 In `normalizeContextInvoiceAmounts()` (`:433-458`), when the duplicate-with-differing-amount is detected (`:444`), capture the conflicting id: `$this->conflictInvoiceId = (int) $invoice_id;` before returning null. Do NOT change the method signature/return type.
  - [x] 2.3 At the `duplicate_invoice_id` emit (`:67-72`), use `$this->conflictInvoiceId` (fallback to `firstContextInvoiceId(...)` if null) instead of `firstContextInvoiceId(...)` directly.
  - [x] 2.4 At the genuine create fall-through (`:186`, after the `:176-181` re-lookup finds no winner), set `$this->lastError = 'create_failed';`. Do NOT set it on the race-recovery return (`:179-181`) or the outer `catch` (`:182-184`).
  - [x] 2.5 (Recommended) Also emit `recordGenerationFailed($company_id, $invoice_id, 'create_failed')` on that fall-through for a durable audit trace, symmetric with `uniqueness_exhausted` (`:140`). `create_failed` is a **reason token inside `voucher.generation_failed`** — it is NOT a new audit event name, so the 4-site event-registry drift guard does NOT apply.
  - [x] 2.6 Tests in `KuickPayVoucherReferenceServiceTest.php`: (a) multi-invoice context where the conflicting pair is NOT first → assert the `voucher.generation_failed` payload `invoice_id` equals the conflicting id; (b) forced create-fall-through (create returns falsy, no pending winner) → assert `getLastError() === 'create_failed'` (and the durable audit if 2.5 done).
- [x] **Task 3 — AC3: redactor attributes/aliases**
  - [x] 3.1 In `KuickPayRedactor::redactEnvelope()` (`:115-138`), after masking element text, also iterate each matched/relevant node's **attributes** and set the value to `xxxx` when the attribute local-name (lowercased) is in `sensitiveFields()`. Preserve the element-text loop and the `*Result` blanking exactly.
  - [x] 3.2 Cover confirmed **aliased element names** (e.g. `CustomerName`, `MobileNo`, `CNIC`) — prefer extending `$sensitive_fields` (`:23-53`) with the confirmed aliases over broad substring XPath (which risks corrupting structural elements). Keep redaction bounded to the confirmed KuickPay contract; do not invent field names.
  - [x] 3.3 Tests in `KuickPayRedactorTest.php`: an envelope with a sensitive **attribute** and an envelope with an **aliased element** → assert both are masked; assert a benign attribute/element is untouched and `*Result`/element-text behavior is unchanged.
- [x] **Task 4 — AC3: harden `maskCredentials()` (gateway-owned; base class untouched)**
  - [x] 4.1 Reimplement `maskCredentials()` (`kuickpay.php:406-408`) so it no longer relies on base `Gateway::maskDataRecursive`. Mirror the redactor's hardened recursion: case-insensitive key match against the credential allowlist; `null`→`null`; array/non-stringable object→`'xxxx'`; scalar→`(string)` then mask. NEVER call `strlen()` on a non-string.
  - [x] 4.2 Make `$credential_mask_fields` (`:26-35`) case-insensitive in effect (lowercase-compare) and add missing common casings/spellings that the confirmed contract uses (e.g. lowercase `username`). Keep these **in sync** with `KuickPayRedactor::$sensitive_fields` credential subset (per the `KuickPayRedactor.php:5-7` contract). Do not add speculative field names beyond the confirmed Phase-0 contract.
  - [x] 4.3 **Do NOT touch `components/gateways/lib/gateway.php`.** Verify no other gateway depends on KuickPay-specific masking (it doesn't — `maskCredentials` is gateway-local).
  - [x] 4.4 Tests (extend `KuickPayVoucherGatewayHelpersTest.php` or add a focused masking test): pass `maskCredentials()` an array containing a credential key holding (a) `null`, (b) a nested object, (c) a bool, (d) a mixed-case credential key → assert masked safely, no error/deprecation, non-credential keys preserved.
- [x] **Task 5 — AC4: diversify leak-scan + locale-independent `isTimeout()`**
  - [x] 5.1 Broaden `fixtureForbiddenPatterns()`/`persistedForbiddenPatterns()` (`:307-326`) for international/dashed/spaced mobile and undashed 13-digit CNIC, and accept the mixed placeholder styles in the allow-lookaheads. Run the full leak suite after EACH pattern change — keep it GREEN (no false positives on existing clean fixtures).
  - [x] 5.2 Add at least one diversified fixture under `tests/fixtures/kuickpay/` (mixed placeholder styles) that (a) exercises a new forbidden pattern when a real secret is present and (b) passes clean when only mixed-style placeholders are present. Confirm `testFixtureFilesContainNoForbiddenSecretOrPiiValues` still passes.
  - [x] 5.3 Rewrite `KuickPaySoapClient::isTimeout()` (`:462-464`) to classify locale-independently. Recommended: thread the attempt elapsed ms + configured `timeout()` into the decision (timeout fires at ≈ ceiling) so classification holds under any `LC_MESSAGES`; keep PHP-internal English markers as a secondary signal. Update both call sites (`:206`, `:235`) if the signature changes.
  - [x] 5.4 Test in `KuickPaySoapClientTest.php`: a transport exception whose message is a **localized** timeout string still classifies as `timeout` (or, with the duration approach, an exception at ≈ the timeout ceiling classifies as `timeout`); a genuine non-timeout transport error classifies as `transport_error`.
- [x] **Task 6 — Verification & evidence**
  - [x] 6.1 `php -l` on every changed PHP file under **both** PHP 8.3 (production runtime) and the 8.2 source-floor — no 8.3-only syntax/APIs (project-context.md:39).
  - [x] 6.2 Plugin suite green: `cd plugins/kuickpay_reconcile && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`.
  - [x] 6.3 Gateway suite green (modulo the disclosed pre-existing `empty-currency` baseline red — `[[kuickpay-failclosed-empty-currency-red]]`): `cd components/gateways/nonmerchant/kuickpay && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. Do NOT use `-c build/phpunit.xml` (project-context.md:74).
  - [x] 6.4 Update `deferred-work.md`: mark the closed items (bulk `evidence.error` line 132, `invoice_id` mislabel line 133, `create_failed` line 103, `redactEnvelope` attributes/aliases line 29, `maskCredentials` hardening line 51, leak-scan breadth line 7, `isTimeout` locale line 28) as CLOSED-by-5.4 with one-line notes. (Separate `docs(kuickpay)` commit from runtime commits — project-context.md:104.)
  - [x] 6.5 Optional sanitized verification record under `docs/kuickpay/` per the 5.3 cadence — placeholders only, NO `config/blesta.php`/DB creds/host/KuickPay creds/raw SOAP/customer PII (NFR8).

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **Do NOT re-implement AC1.** The bulk `evidence.error`/item-row symmetry already exists at HEAD (`KuickPayReconcileService.php:332-346` → `recordProcessVoucherFailure` `:453-485`, from commit `01682753`). AC1 is a **test + verification** AC. Re-writing this is wheel-reinvention and risks regressing the rollback-then-record discipline 5.3 established.
- **Do NOT edit the shared base `components/gateways/lib/gateway.php`.** Every Blesta gateway extends it. AC3's `maskCredentials` hardening lives entirely in `components/gateways/nonmerchant/kuickpay/kuickpay.php`. (The deferred-work note cites `gateway.php maskDataRecursive` as the *root cause*, not the *fix site*.)
- **Do NOT change the pure validator's signature** in `normalizeContextInvoiceAmounts()`. Surface the conflicting id via a private member (4.5 Task-5 constraint).
- **Do NOT add a new audit event name.** `evidence.error`, `voucher.generation_failed`, `voucher.replaced` already exist and are registered in the 4-site event registry (presenter `EVENT_LABEL_KEYS`, `language/en_us/admin_vouchers.php`, `KuickPayVoucherListPresenterTest::KNOWN_EVENTS` + count=19). `create_failed` is a **reason token** inside `voucher.generation_failed`'s payload — adding it does NOT require touching that registry. Adding a genuinely new event name WOULD require all 4 sites or `KuickPayVoucherListPresenterTest` fails.
- **No schema / version bump expected.** No new columns; `kuickpay_reconciliation_items` already has the `uniq_kuickpay_items_run_voucher (run_id, voucher_id)` unique key; `evidence.error` already registered. Leave the plugin version as 5.3 left it (1.10.0) unless a concrete schema need arises (none identified).
- **Broadening leak-scan patterns can break green.** Every new forbidden pattern needs a paired diversified fixture + an allow-lookahead for the mixed placeholder styles actually present. Run the leak suite after each change.
- **Fail-closed / honest reporting (NFR9, NFR12).** Audit/log writes are best-effort and must never abort the batch (existing try/catch pattern). Report exactly what ran on which PHP version; disclose the `empty-currency` baseline red, don't attribute it to this change.

### Architecture compliance (must follow)

- **Ownership boundary:** the **plugin** (`kuickpay_reconcile`) owns audit records, reconciliation, posting; the **gateway** owns credential storage/masking and the SOAP redactor. Audit logging must NOT move into the gateway (architecture.md:522, 669, 765). The two redaction layers are intentional and separate: gateway `maskCredentials()` (credential keys) + `KuickPayRedactor` (SOAP/XML envelope + PII) — "keep credential keys in sync" (`KuickPayRedactor.php:5-7`; architecture.md:371-373, 397).
- **Audit payloads use redacted fields only** (architecture.md:634); event names use lower-dot notation (architecture.md:623-632). **Never** store raw SOAP or credentials in logs (architecture.md:656); no controller/view/cron branches on raw SOAP/XML (architecture.md:771).
- **NFR8:** raw Diagnostic Summary is admin-only and must not expose secrets/unnecessary customer data. **NFR13:** amount comparisons use normalized decimal strings or integer minor units, never PHP floats — `normalizeAmount()` (`KuickPayVoucherReferenceService.php:489-506`) already does this; do not introduce float math when touching AC2.
- **Audit service contract:** `KuickPayAuditService::record(string $eventName, array $context): void` — context keys: `company_id` (int, required), `voucher_id`, `run_id`, `redacted_trace_id`, `evidence_hash`, `payload` (array→json, empty→NULL). Always wrap caller-side in try/catch (audit must not abort the batch).

### Files to modify (UPDATE) — and their current state

| File | AC | Current state → change |
|---|---|---|
| `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php` | AC1 | Bulk catch `:332-346` already calls `recordProcessVoucherFailure` `:453-485`. **Likely no prod change** — verify only. |
| `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` | AC2 | `:67-72` emits via `firstContextInvoiceId`; `:444` detects conflict; `:186` fall-through leaves `lastError` null. Add `$conflictInvoiceId` member + `create_failed`. |
| `components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php` | AC3 | `redactEnvelope` `:95-145` masks element text (CI) + blanks `*Result`; **attributes untouched**. Add attribute masking + confirmed aliases. Array path `:301-391` already hardened — leave it. |
| `components/gateways/nonmerchant/kuickpay/kuickpay.php` | AC3 | `maskCredentials` `:406-408` delegates to base `maskDataRecursive` (throws on non-strings, case-sensitive). Reimplement gateway-local, input-robust, CI allowlist `:26-35`. |
| `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` | AC4 | `isTimeout` `:462-464` substring-matches localized text; call sites `:206`,`:235`. Make locale-independent. |

**Test files (UPDATE/ADD):** `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php` (AC1), `…/KuickPayVoucherReferenceServiceTest.php` (AC2), `components/gateways/nonmerchant/kuickpay/tests/KuickPayRedactorTest.php` (AC3), `…/tests/KuickPayVoucherGatewayHelpersTest.php` or new masking test (AC3), `…/tests/KuickPaySoapClientTest.php` (AC4), `plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php` + new fixtures under `…/tests/fixtures/kuickpay/` (AC4). **Docs:** `_bmad-output/kuickpay/implementation-artifacts/deferred-work.md` (mark closures), optional `docs/kuickpay/`.

**DO NOT edit:** `components/gateways/lib/gateway.php` (shared base), core `app/models/transactions.php`, any ionCube-protected file, `config/blesta.php`.

### Previous Story Intelligence (Story 5.3 — `done`, baseline `b20b2a9f`)

Patterns to be consistent with (5.3 hardened the exact reconcile/audit paths 5.4 touches):

- **Audit/item writes are best-effort and post-rollback.** `recordProcessVoucherFailure()` writes the item row then the `evidence.error` audit on a **fresh statement after `rollBack()`**, each in its own try/catch — a failed write never aborts the batch. AC1's test must respect this (collisions are swallowed, not fatal). 5.3's `01682753` specifically added the bulk rollback-path item+audit that AC1 now ratifies.
- **No nested transactions in Blesta** — a self-transacting call inside an outer `begin()` commits early and drops the lock. Audit/item writes are non-transacting and safe; never call `voucherRepository->create()` (self-transacts) inside a wrapped block.
- **`(run_id, voucher_id)` is a UNIQUE key on `kuickpay_reconciliation_items`** — a second row for the same pair throws and would abort a bulk run. The bulk loop already routes duplicates/echoes to an audit-only branch (`:309-322`) before any second item write; the failure helper swallows a collision. Don't introduce a code path that writes a 2nd item row for the same pair.
- **Status-guarded writes & honest no-ops** (5.3 AC1, fixes `47e2ddce`): the no-op path records the **true current** status, not a stale prior status. Keep audit/item payloads accurate when touching these paths.
- **Test-fake fidelity (Epic-3 retro AI-2):** fakes must model NOT-NULL/UNIQUE and status-guarded writes faithfully (and Blesta `decimal(12,4)` 4-dp amount strings) or they mask the bug. For AC1's idempotency test, the fake item repo must actually throw on a duplicate `(run_id, voucher_id)`.
- **PHP 8.3 is the runtime** (ea-php83, ionCube 15; framework boots only on 8.3); "8.2" is a **source-floor** — no 8.3-only syntax/APIs. `php -l` under both. Verify live legs on 8.3 (`[[kuickpay-php82-toolchain-now-available]]`).
- **Test invocation** (project-context.md:74): `--bootstrap tests/bootstrap.php tests`, never `-c build/phpunit.xml`. External runner: `/root/tools/phpunit-8.5/vendor/bin/phpunit`. Baselines: plugin **180/180** green; gateway **234** green modulo the disclosed `empty-currency` red.

### Git Intelligence (recent, audit/redaction-relevant)

- `01682753 fix(kuickpay): record bulk reconcile rollback failures` — **seeds AC1**: added the bulk catch's item+audit-after-rollback. AC1 = lock it with a test.
- `47e2ddce fix(kuickpay): record reconcile no-op status accurately` — audit-accuracy precedent.
- `94197e23 fix(kuickpay): surface non-duplicate lock insert errors` — distinguish infra errors from dup-key; observability precedent.
- `e84bbcde` / `f44bd841` — adoption hardening + 5.3 review-finding closures; `KuickPaySecretLeakageTest.php` is the live redaction gate to extend in AC4.
- **Commit style:** `<type>(kuickpay): <summary>` (`fix`/`feat`/`refactor`/`test`/`docs`), imperative, ≤72 chars; per-logical-unit commits; keep `_bmad-output/` + `docs/kuickpay/` doc commits **separate** from runtime commits (project-context.md:101-104).

### Project Structure Notes

- Gateway extension tree: `components/gateways/nonmerchant/kuickpay/{kuickpay.php, lib/*, tests/*, views/default/*.pdt, language/en_us/*, config.json}`. Plugin tree: `plugins/kuickpay_reconcile/{lib/*, models/*, controllers/*, tests/*, kuickpay_reconcile_plugin.php, config.json, language/en_us/*}`. Keep gateway-owned masking in the gateway and plugin-owned audit in the plugin (architecture ownership boundary). No new files outside these trees except test fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`.
- Style: match each file's local conventions (legacy global classes here — no namespaces, no `declare(strict_types=1)`); short array syntax, single quotes, LF, one space around operators (component-local `PSR2 Transitional` PHPCS). No broad reformat of legacy files or language files.

### References

- [Source: epics.md#Story-5.4 (929–955)] — ACs + closure list (NFR8 + NFR13).
- [Source: deferred-work.md] — bulk `evidence.error` (line 132), `invoice_id` mislabel (line 133), `create_failed` (line 103), `redactEnvelope` attributes/aliases (line 29), `isTimeout` locale (line 28), `maskCredentials` non-array/object/non-string + case-sensitive allowlist (lines 51, 53), leak-scan breadth (line 7), mixed placeholders (line 36).
- [Source: architecture.md] — Audit model/naming/redacted payloads (610–634); redaction boundary (371–373, 397, 771); ownership (522, 656, 669, 765); never-log raw SOAP/creds (656).
- [Source: project-context.md] — PHP 8.3 runtime / 8.2 source-floor (22, 39); test runner (72–74); commit/doc-separation rules (101–104); ionCube/base-file no-edit (91, 126).
- [Source: KuickPayReconcileService.php:332-346,401,453-485] — bulk/single failure symmetry (AC1).
- [Source: KuickPayVoucherReferenceService.php:62,67-72,140,171-186,291-296,433-458] — AC2 sites.
- [Source: KuickPayRedactor.php:23-53,95-145,301-391] — AC3 redactor.
- [Source: kuickpay.php:26-35,406-408,1376-1397] + [gateway.php:342-404 — DO NOT EDIT] — AC3 masker (root cause vs fix site).
- [Source: KuickPaySoapClient.php:206,235,462-464] — AC4 `isTimeout`.
- [Source: KuickPaySecretLeakageTest.php:216-234,307-326] — AC1 single-path coverage + AC4 patterns.
- Memory: [[kuickpay-run-detail-audit-allowlist]], [[kuickpay-recheck-outcome-token-set]], [[kuickpay-failclosed-empty-currency-red]], [[kuickpay-php82-toolchain-now-available]], [[kuickpay-blesta-decimal4-amount-trap]].

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context)

### Debug Log References

- Baseline (before any change): plugin **182/182** green; gateway **234** with **1** pre-existing red
  (`KuickPayFailClosedContractTest` / `ambiguous/bill-payment-inquiry-empty-currency.xml` —
  `[[kuickpay-failclosed-empty-currency-red]]`, disclosed, not introduced by this story).
- Final after review fixes: plugin **189/189** (+7 tests); gateway **239** (+5 tests) with the **same** single baseline red.
  Both suites verified on PHP **8.3** (production runtime) and the **8.2** source-floor; `php -l` clean on
  both engines for all 10 changed files.

### Completion Notes List

- **AC1 (test-only, no production change):** verified at HEAD that the bulk reconcile catch already mirrors
  the single `processVoucher()` failure path via `recordProcessVoucherFailure()` (item row +
  best-effort `evidence.error`, landed in Story 5.3 `01682753`). Added two regression tests locking
  (a) bulk/single `evidence.error`+item-row symmetry keyed to `voucher_id`/`run_id`, and (b) the
  idempotent swallow of a `(run_id, voucher_id)` unique-key collision on the failure-path item write.
  Per 1.4, `evidence.error` was deliberately **not** added to the 4.4 run-detail allowlist
  (`getByRun`/`getCountByRun`), keeping single/bulk visibility symmetric.
- **AC2:** `voucher.generation_failed` now names the actually-conflicting invoice via a private
  `$conflictInvoiceId` member (validator signature unchanged); the benign `create()` fall-through sets
  `lastError='create_failed'` and emits a durable `voucher.generation_failed` audit — not on the
  race-recovery return or the outer catch. `create_failed` is a reason token, so the 4-site event
  registry is untouched.
- **AC3:** `KuickPayRedactor::redactEnvelope()` now masks sensitive XML **attribute** values and the
  confirmed aliases (`CustomerName`, `MobileNo`, `CNIC`); element-text + `*Result` blanking preserved.
  Free-text SOAP diagnostics also mask those alias key/value pairs. `maskCredentials()` reimplemented
  gateway-local (base `gateway.php` untouched), input-robust (top-level non-array, null/array/object/bool,
  nested object graphs) and case-insensitive, mirroring the redactor's hardened array path.
- **AC4:** leak-scan forbidden patterns broadened (international/dashed/spaced/split mobile, undashed
  13-digit CNIC) with paired positive/negative control tests + diversified clean and quarantined
  positive-control fixtures, suite stays green. `KuickPaySoapClient::isTimeout()` reclassified to be locale-independent —
  attempt duration (≈ `timeout()` ceiling) is the primary signal, English markers the fallback; both call
  sites thread the elapsed duration. Label-only impact (both classes retry identically per AC6).
- **No schema/version bump** (no new columns; plugin stays 1.10.0). **No new audit event name.**
- Verification record: `docs/kuickpay/audit-redaction-completeness-verification.md` (sanitized, NFR8).

### File List

Production:
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` (AC2)
- `components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php` (AC3)
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (AC3)
- `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` (AC4)

Tests:
- `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php` (AC1)
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherReferenceServiceTest.php` (AC2)
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayRedactorTest.php` (AC3)
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php` (AC3)
- `components/gateways/nonmerchant/kuickpay/tests/KuickPaySoapClientTest.php` (AC4)
- `plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php` (AC4)
- `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/redaction/diversified-placeholders.xml` (AC4, new)
- `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/redaction/diversified-real-secrets.leaky-control.xml` (AC4, new positive control)

Docs:
- `_bmad-output/kuickpay/implementation-artifacts/deferred-work.md` (closures)
- `docs/kuickpay/audit-redaction-completeness-verification.md` (new, sanitized verification record)

### Change Log

- 2026-06-14: Implemented Story 5.4 (Audit, Logging & Redaction Completeness). AC1 regression-only
  (bulk/single `evidence.error` symmetry + idempotency); AC2 precise conflicting `invoice_id` +
  `create_failed` traceability; AC3 redactor attribute/alias masking + hardened gateway credential
  masker; AC4 broadened leak-scan + locale-independent `isTimeout()`. Review fixes added alias
  free-text diagnostic redaction, nested object-graph credential masking, split mobile leak-scan coverage,
  and a quarantined positive-control fixture. Plugin 189/189, gateway 239
  (modulo the disclosed pre-existing `empty-currency` baseline red), on PHP 8.3 and 8.2. Status → review.
