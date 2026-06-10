---
baseline_commit: 5d048aaab309cce3ed777375485c4360edbc5f28
---

# Story 2.3: Map Invoice Data and Issue KuickPay Voucher

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a customer paying an eligible invoice,
I want Blesta to send the correct invoice and contact details to KuickPay,
so that the Consumer Number is payable for the exact amount I owe.

## Acceptance Criteria

1. **AC1 — Request mapping.** Given an eligible PKR invoice and configured KuickPay settings, when the Voucher request is built, then the request maps amount, payment head, due date, expiry date, issue date, client name, mobile, email, branch, Institution ID, and Registration Number according to configured policies.
2. **AC2 — Mobile fallback.** Given a client mobile number is invalid or non-Pakistani, when the request is built, then the configured fallback mobile policy is applied and no hard-coded fallback phone is used.
3. **AC3 — Provider rejects contact data.** Given KuickPay rejects customer contact data such as email during `InsertVoucher`, when the voucher creation response is processed, then no payable Voucher is shown unless KuickPay successfully issued it, the customer sees localized safe copy, admins can inspect a sanitized provider validation reason, and any fallback email policy is configurable, not hard-coded.
4. **AC4 — Success path.** Given KuickPay returns a voucher creation success response covered by accepted fixture behavior, when the response is processed, then the Voucher remains unpaid and Pending and stored creation evidence is sanitized.
5. **AC5 — Timeout / failure / unknown.** Given KuickPay times out, fails, or returns an unknown voucher creation response, when the response is processed, then the customer sees retry-safe copy and the Voucher is marked failed, retry, or Manual Review according to safe parser rules.

> **All five ACs must hold without breaking the existing Story 2.1/2.2 create-or-reuse behavior or the Story 1.5 PKR eligibility and Story 1.1 companion guards.** A reload of an already-issued voucher must display it, not re-issue it (see Idempotency below).

---

## Dependencies (sequencing — verify before starting)

This story sits in Epic 2 by user-value grouping, but its build-order prerequisites live in Epic 0 and Epic 3 and are **all satisfied** as of this writing (`sprint-status.yaml`):

- ✅ **Story 0.1** Phase 0 contract + sanitized fixtures — `done` (human-accepted WHMCS-derived shapes on 2026-06-09; treat as provisional, fail-closed).
- ✅ **Story 3.1** `InsertVoucher` path of the SOAP client wrapper — `done` (`KuickPaySoapClient::insertVoucher()`).
- ✅ **Story 3.2** creation-response parser cases + fixtures — `done` (`KuickPayResponseParser::parse()` + `insert-voucher-*` fixtures).

[Source: epics.md#Story-2.3 Dependencies; sprint-status.yaml BUILD ORDER §3]

---

## Tasks / Subtasks

- [x] **Task 1 — Expose issuance state on the voucher contract so issuing is idempotent (AC4, AC5)**
  - [x] In `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php::flatten()` add `kuickpay_reference` and `raw_status` to the returned flat array (currently absent — lines 261-274). These are the only fields the gateway needs to decide "already issued vs. needs issuing."
  - [x] Confirm `KuickPayVoucherRepository::getWithInvoices()` already returns these columns on the voucher row (it returns the full `kuickpay_vouchers` row via the model — no model change expected).
  - [x] Do NOT change the create/reuse logic, the reference generation, or the existing flat keys. Append only.

- [x] **Task 2 — Add the `fallback_email` (and optional `default_branch`) gateway settings (AC1, AC3)**
  - [x] `payment_head_label` and `fallback_mobile` settings already exist (`views/default/settings.pdt`, `language/en_us/kuickpay.php`) — reuse them, do not re-add.
  - [x] Add `fallback_email` text field to `settings.pdt` + `Kuickpay.fallback_email` / `Kuickpay.fallback_email_note` language keys (mirror the existing `fallback_mobile` block exactly), e.g. `$lang['Kuickpay.fallback_email'] = 'Fallback email';` / `$lang['Kuickpay.fallback_email_note'] = 'Optional fallback email for voucher creation when a client email cannot be used.';`
  - [x] Optionally add `default_branch` (used when the client has no state/branch). Add field + language the same way. Keep optional.
  - [x] No new `editSettings` validation rule is required (these are optional pass-through meta, like `payment_head_label`/`fallback_mobile` today). If you add format validation, follow the existing `if_set` rule pattern and add `Kuickpay.!error.*` keys.

- [x] **Task 3 — Build the InsertVoucher field map as a pure, testable helper (AC1, AC2)**
  - [x] Add a protected helper on the gateway (e.g. `buildVoucherRequest(array $voucher, array $contactData, array $meta): array`) that returns the KuickPay field map. Keep it pure (no I/O) so it is unit-testable with a fake — this is the established testability pattern.
  - [x] Map every field per the **Field Mapping** table below. Amounts stay decimal strings via the existing `normalizeAmount()` (kuickpay.php:684); never use floats.
  - [x] **Do NOT add `userName`, `password`, or `InstitutionID`** — `KuickPaySoapClient::insertVoucher()` injects those itself via `withCredentials()` (KuickPaySoapClient.php:233-243). Adding them is a redundancy/leak risk.
  - [x] Apply the mobile fallback policy (AC2). Define the normalization contract explicitly in a pure helper, e.g. `normalizePkMobile(string $raw): ?string`: accept `03XXXXXXXXX` (11 digits), `+923XXXXXXXXX`, `00923XXXXXXXXX`, or `923XXXXXXXXX`; strip separators; canonicalize to **one** KuickPay-bound output format (default `03XXXXXXXXX`); return `null` for anything that is not a valid PK mobile. If the client mobile normalizes to `null`/empty, use the configured `fallback_mobile` — run it through the **same** normalizer (a misconfigured fallback must not pass through unchecked); never hard-code a number. Keep the exact accepted shapes/output documented so the dev does not guess.
  - [x] Apply the email fallback: use client email; if empty, use configured `fallback_email`; if that is empty too, send empty (do not hard-code) (AC3).
  - [x] Add a pure date helper, e.g. `protected function formatVoucherDate(string $ymdDate): string` returning `date('d-M-y', strtotime($ymdDate))`, and use it for `DueDate`, `ExpiryDate`, and `IssueDate` (today). Derive `VoucherMonth`/`VoucherYear` from the due date. **Do not scatter `date()` format strings** — the `d-M-y` format is provisional/unconfirmed, so a confirmed format must be a one-line change (see Date Handling).
  - [x] `$this->meta` already carries `payment_head_label`, `fallback_mobile`, and (after Task 2) `fallback_email`/`default_branch`; pass it straight into `buildVoucherRequest()` as `$meta`. The reference-service context (`buildVoucherReferenceContext()`) is separate and is **not** the source for these mapping settings.

- [x] **Task 4 — Gather client name / mobile / email / branch (AC1, AC2, AC3)**
  - [x] `$contact_info` passed to `buildProcess()` has `id`, `client_id`, `first_name`, `last_name`, `company`, `state` — but **no email and no phone**. Load them via Blesta models: `Loader::loadModels($this, ['Contacts'])`, then `$this->Contacts->get((int) $contact_info['id'])` for email, and `$this->Contacts->getNumbers((int) $contact_info['id'], 'phone', 'mobile')` for the mobile number (app/models/contacts.php:924; returns an array of number objects, each `->number`).
  - [x] `Name` = company if present else `first_name last_name`. `Branch` = `$contact_info['state']['code'] ?? ''` — **`state` is an array `{code, name}`, not a scalar** (per the `buildProcess()` docblock and the Blesta nonmerchant contract; reading `$contact_info['state']` directly array-to-strings). Fall back to configured `default_branch`, then empty. (`country` is also an array if ever used.)

- [x] **Task 5 — Issue the voucher through the SOAP client and normalize the response (AC4, AC5)**
  - [x] **Reload-safety pre-check (Guard B).** In `buildProcess()`, before create/issue, look up the most recent voucher for the invoice (any status) and apply the **reload decision matrix** (see Idempotency & reload safety). On a BLOCK decision, do NOT create or issue — set the safe view copy (Task 7) and return. Add the additive read as a model method joining the link table (there is **no** `kuickpay_vouchers.invoice_id` column — invoice linkage lives in `kuickpay_voucher_invoices`), with a thin repository pass-through:
    ```php
    // models/kuickpay_vouchers.php::getLatestByInvoiceId(int $invoice_id, int $company_id)
    return $this->Record->select(['kuickpay_vouchers.*'])
        ->from('kuickpay_vouchers')
        ->innerJoin('kuickpay_voucher_invoices', 'kuickpay_voucher_invoices.voucher_id', '=', 'kuickpay_vouchers.id', false)
        ->where('kuickpay_voucher_invoices.invoice_id', '=', $invoice_id)
        ->where('kuickpay_vouchers.company_id', '=', $company_id)
        ->order(['kuickpay_vouchers.id' => 'DESC'])
        ->limit(1)
        ->fetch();
    // KuickPayVoucherRepository::getLatestByInvoiceId(int,int): ?stdClass  — pass-through, normalize false → null
    ```
  - [x] Otherwise, after `getOrCreateForInvoiceContext()` succeeds, issue **only when the voucher needs issuing** (Guard A: `$voucher['status'] === 'pending' && empty($voucher['kuickpay_reference'])`). Immediately **before** the wire call, stamp a sentinel (set `date_last_checked` via `edit()`) so a crash after the call isn't mistaken for "never issued" (see Crash-window note). Obtain the client via the existing `getSoapClient()` (kuickpay.php:492) and call `$outcome = $client->insertVoucher($this->buildVoucherRequest(...))`. A reused, already-issued `pending` voucher (reference set) is displayed, never re-issued.
  - [x] Parse with the gateway parser: load `lib/KuickPayResponseParser.php`, then `$evidence = (new KuickPayResponseParser())->parse($outcome, ['expected_registration_number' => $voucher['registration_number']]);` — pass **only** `expected_registration_number` (single-identity; see Dev Notes). The outcome already carries `operation = 'InsertVoucher'`, so `parse()` dispatches correctly.
  - [x] Never auto-retry `insertVoucher()` (the client is single-attempt by contract; a retry can double-issue a payable voucher).

- [x] **Task 6 — Persist the normalized evidence + write the audit event in the PLUGIN (AC3, AC4, AC5)**
  - [x] Add a plugin orchestration method that takes the normalized `KuickPayEvidence` and persists it — recommended: a new **`public`** method `recordIssueOutcome(int $voucherId, int $companyId, KuickPayEvidence $evidence): void` on a plugin service (extend `KuickPayVoucherReferenceService` or add a small `KuickPayIssuanceService` in `plugins/kuickpay_reconcile/lib/`). Make it `public` (unlike the `private` `persistEvidence()` it mirrors) so the gateway factory seam can invoke it. Keeping the durable voucher-state write + audit inside the plugin respects the ownership boundary; the normalized evidence object is the sanctioned gateway↔plugin contract.
  - [x] Obtain that service from the gateway through a `protected function getIssuanceService()` factory seam (`Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . '...php'); return new ...;`), mirroring the existing `getVoucherReferenceService()` (`kuickpay.php:611`). This keeps the gateway from reaching into plugin internals and lets tests inject a fake issuance service.
  - [x] That method calls the repository with the **3-arg, company-scoped** signature — `KuickPayVoucherRepository::edit(int $voucherId, int $companyId, array $vars): void` — passing `company_id` as the **second positional argument, NOT inside `$vars`** (the model force-`unset($vars['company_id'])`). `$vars` = `status` (= `$evidence->status()` — **except** the transport-ambiguous remap below, if the OPEN DECISION is approved), `kuickpay_reference` (= `$evidence->reference()`), `raw_status`, `error_class`, `evidence_hash`, `diagnostic_summary` = `json_encode($evidence->toArray())` (sanitized — no raw SOAP), `date_last_checked` = now. Mirror the in-tree caller `KuickPayReconcileService::persistEvidence()` (`KuickPayReconcileService.php:204`): `$this->voucherRepository->edit((int) $voucher->id, $company_id, $vars);`. **Status remap (per the OPEN DECISION in Idempotency & reload safety):** if approved, when `$evidence->errorClass()` is `timeout`/`transport_error`, persist `status = 'retry'` instead of the parser's `manual_review` so reconciliation can self-resolve; keep the parser's status verbatim for every response-body case (`00`/`94`/`05`/malformed/unknown), and persist `raw_status`/`error_class`/`validation_errors` unchanged either way so the audit trail stays accurate.
  - [x] It records an audit event via `KuickPayAuditService::record(string $eventName, array $context)`: on success status `pending` → `voucher.issued`; on failure/manual_review/retry → `evidence.rejected` (canonical names per architecture). Pass `payload` as a **plain array** (the service JSON-encodes it internally — do NOT pre-encode). Context: `company_id` (required — `record()` does `(int) $context['company_id']`, which in PHP 8.2 **casts a missing key to `0`** with a warning, not a throw; never omit it), `voucher_id`, `redacted_trace_id` (= `$evidence->redactedTraceId()`), `evidence_hash`, `payload` = redacted (status, error_class, raw_status, validation_errors — never raw). `run_id` is read with `?? null`, so omit it (correct for issuance — there is no reconciliation run). This is a brand-new event literal: `voucher.issued` is architecturally canonical but has **no existing constant or caller** in the tree (event names are inline string literals; 3.3 emits only `evidence.*`), so write the literal — do not hunt for a constant to reuse.
  - [x] **No cross-model DB transaction.** Persist the voucher evidence via `edit()` **first** (the load-bearing state that prevents re-issue and drives the display), **then** `record()` the audit event sequentially — this is exactly the 3.3 pattern (`persistEvidence()` → `recordEvidenceAudit()` use **no** `begin()/commit()/rollBack()`; the only plugin transaction is `KuickPayVoucherRepository::create()`). The edit and audit writes go through two different repositories/models, so a single-model `begin/commit` would be a no-op for the other and is NOT atomic — do not add one. If the audit write fails after a successful `edit()`, leave the voucher row as written (it is the source of truth); do not roll back voucher state for an audit failure.

- [x] **Task 7 — Customer-facing display contract (AC3, AC4, AC5)**
  - [x] Re-fetch the voucher (or use the evidence-updated row) and only set a **payable** voucher into the `process` view when `status === 'pending'` and it was successfully issued (`kuickpay_reference` present). [Full reference panel + copy + instructions are Stories 2.5/2.6 — keep 2.3's view minimal.]
  - [x] When issuance failed/retry/manual_review, OR no voucher could be created, OR Guard B short-circuited, set safe localized retry copy (new `Kuickpay.process.*` language key) — do not render a payable Consumer Number, raw SOAP, parser fields, or error classes (AC3, AC5, UX-DR7/UX-DR28). `process.pdt` currently renders the consumer number for **any** non-empty voucher (`if (!empty($voucher))`); tighten the payable branch to the issued+pending condition, e.g.:
    ```php
    if (!empty($voucher) && $voucher['status'] === 'pending' && !empty($voucher['kuickpay_reference'])) {
        // render Consumer Number / amount / dates (existing block)
    } else {
        echo $this->_('Kuickpay.process.retry_safe'); // safe, non-payable copy
    }
    ```
  - [x] **Admin-inspectable rejection reason (AC3).** KuickPay's InsertVoucher response is a fixed-position status string, not a rich message — there is no provider "contact validation message" to surface. The sanitized admin-inspectable reason is the **stored normalized evidence**: `raw_status`, `error_class`, `validation_errors`, `redacted_trace_id` (persisted in `diagnostic_summary` by Task 6). State this so the dev does not invent a provider-message field that the contract does not return.
  - [x] All customer/admin strings via `language/en_us/kuickpay.php` (`Language::_`).

- [x] **Task 8 — Diagnostics/logging (AC3)**
  - [x] Record a sanitized issuance diagnostic through the existing gateway logging seam (`$this->log('kuickpay:...', json_encode([...]), 'output', false)`, gated on `logging_enabled`) — event + redacted trace id + sanitized reason/`error_class` + `(int)` invoice id only. Never log `raw_result`, credentials, mobile, email, or name. Pass any credential-bearing array through `maskCredentials()` (kuickpay.php:316) first. For the gate default, match the existing **log-path** convention `($meta['logging_enabled'] ?? 'true') !== 'true'` (default-on, as in `recordReferenceGenerationFailure()` kuickpay.php:657) — note this differs from `getSoapClient()`/`editSettings()` which default it to `'false'`; a saved gateway always carries a concrete value, so the `??` branch is effectively unreachable, but keep the log-path default consistent.

- [x] **Task 9 — Tests (AC1-AC5)**
  - [x] Unit-test `buildVoucherRequest()` mapping (amount/head/dates/name/branch via `state['code']`, mobile normalization + email fallback, no `userName`/`password`/`InstitutionID` present). Assert `fallback_email`/`default_branch` flow from `$meta` into the request when configured (regression guard against the new settings being dropped).
  - [x] Test the **response-body** branches by feeding the on-disk XML fixtures through `parse()` and asserting persisted `status`/`error_class`/`kuickpay_reference`: `valid/insert-voucher-success.xml` (`00`+id) → `pending` + reference set; `ambiguous/insert-voucher-duplicate.xml` (`94`) → `manual_review`/`duplicate_reference`; `malformed/insert-voucher-invalid-credentials.xml` (`05`) → `failed`/`credential_error` (note: this `05`→`failed` fixture lives under `malformed/` — directory name ≠ outcome); `malformed/insert-voucher-malformed.xml` (`00` no id) → `manual_review`/`malformed_response`; `malformed/insert-voucher-non-2-char-status.xml` (status `X`) → `manual_review`/`malformed_response`.
  - [x] The **timeout/transport** branch is NOT a parseable body — `ambiguous/insert-voucher-timeout.md` is a human-readable descriptor, and the parser reaches this branch via `transportFailure()` on an `ok=false` outcome. Exercise it by **synthesizing the transport outcome in code**: `parse(['ok' => false, 'operation' => 'InsertVoucher', 'error_class' => 'timeout', 'redacted_trace_id' => '...'])` → assert `manual_review`/`timeout`.
  - [x] Test idempotency (Guard A): a reused already-issued pending voucher does NOT call `insertVoucher()` again (inject a fake SOAP client and assert zero calls).
  - [x] Test reload safety (Guard B) both directions per the decision matrix: **BLOCK** cases (latest attempt = `retry`, `manual_review`, `confirmed_unposted`, `posted`) → reload makes **zero** `insertVoucher()` calls and shows safe copy, no new payable voucher; **ALLOW** cases (latest = `failed`/`credential_error`, `expired`, `cancelled`, or none) → reload proceeds to create + issue. Inject a fake SOAP client and assert the call count for each. (Concurrent double-submit is explicitly out of scope — 2.4 active-context idempotency — so do not assert on it.)
  - [x] Test customer copy: failed/timeout/Guard-B shows safe retry copy, not a payable Consumer Number.
  - [x] Run: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (do NOT use `-c build/phpunit.xml` — it is broken for this component). Add plugin-side tests under the plugin's existing test layout if you put the persist/audit method there.
  - [x] `php -l` every changed PHP file. State the exact PHP version used (lint host is 8.3.x; target is **8.2** — do not introduce >8.2 syntax). DB-backed behavior cannot be runtime-verified here; state that explicitly.

---

## Dev Notes

### Critical gates & invariants (read first)
- **Posting boundary (hard invariant).** 2.3 issues the voucher and persists the creation outcome **only**. It MUST NOT create/apply a Blesta transaction, call `markPaid`/`recordPayment`, update invoice status, or set voucher state to `posted`/`confirmed_unposted`. Only `KuickPayPostingService` (Epic 3 / Story 3.5) may pay an invoice. InsertVoucher success maps to **`pending` (created, UNPAID)** — never paid. [Source: architecture.md#Anti-Patterns lines 650-660; #Posting-Contract lines 581-593; epics.md FR10/FR17]
- **`buildProcess()` cannot mark paid** under any path. [architecture.md:410, 650]
- **Unknown/timeout ≠ payable.** Unknown, malformed, duplicate, timeout, transport responses fail closed to `failed`/`manual_review`/`retry` per the parser — never paid. [epics.md FR10; NFR9]
- **No hard-coded production values** — endpoint/WSDL, Institution ID, credentials, fallback phone, fallback email, fees, date formats all come from gateway settings. [epics.md FR2/FR10/NFR10; architecture.md:83]

### Where issuance lives (ownership boundary)
The gateway owns checkout + SOAP protocol + customer display; the plugin owns durable voucher state + audit. The **normalized parser result (`KuickPayEvidence`) is the explicit gateway↔plugin contract** [architecture.md:408, 549-551]. Recommended split:
- **Gateway (`buildProcess`)**: create/reuse voucher (existing) → build field map → `getSoapClient()->insertVoucher()` → `parser->parse()` → `KuickPayEvidence`.
- **Plugin (new `recordIssueOutcome()`)**: receives the `KuickPayEvidence`, writes the voucher row (`repository->edit`) **then** the audit event, sequentially with no DB transaction — the same persist→audit shape `KuickPayReconcileService` uses in Story 3.3 (`persistEvidence()` `:204` → `recordEvidenceAudit()` `:267`). Keeps durable-state + audit writes in the plugin, where they belong. The gateway obtains this service through a small factory seam on the gateway (see Task 6), mirroring the existing `getVoucherReferenceService()` (`kuickpay.php:611`) so tests can inject a fake.

Do not put voucher persistence, paid-state decisions, retry logic, or audit logging in the gateway. [architecture.md#Ownership-Rule lines 664-673, 765]

### Exact API surface 2.3 consumes (verified signatures + locations)

**SOAP client** — `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`
- `public function insertVoucher(array $voucherParams): array` (line 61). Single attempt (`attempts = 1`), **never auto-retried**. Injects `userName`/`password`/`InstitutionID` via `withCredentials($params, false)` (line 233-243) — caller must NOT add them.
- Obtain it through the gateway factory `protected function getSoapClient()` (kuickpay.php:492) — never `new SoapClient(...)` directly.
- Transport-outcome shape: `['ok'=>bool, 'operation'=>'InsertVoucher', 'raw_result'=>?string, 'raw_envelope'=>?string(redacted), 'error_class'=>?string(null|'timeout'|'transport_error'), 'fault'=>?string(redacted), 'redacted_request'=>array, 'redacted_trace_id'=>string, 'attempts'=>int]`. `ok` = transport reachability only (a SOAP `<Fault>` body is `ok=true` and handed to the parser). `raw_result` is **parser-only — never log/store/display it**.

**Parser** — `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`
- `public function parse(array $transportOutcome, array $context = []): KuickPayEvidence` (line 61). Dispatches on `$transportOutcome['operation']`. For issuance pass `['expected_registration_number' => $voucher['registration_number']]` only.
- **InsertVoucher mapping (private `parseInsertVoucher`, lines 282-391):**

  | Raw `InsertVoucherResult` | `status()` | `errorClass()` | notes |
  |---|---|---|---|
  | `00` + non-empty voucher id | `pending` | `null` | `reference()` = parsed voucher id; this is the success path |
  | `00` with no id | `manual_review` | `malformed_response` | `validation_errors=['missing_voucher_id']` |
  | `94` | `manual_review` | `duplicate_reference` | |
  | `05` | `failed` | `credential_error` | |
  | other 2-digit / non-numeric / non-2-char | `manual_review` | `unknown_status` / `malformed_response` | |
  | transport `ok=false` (timeout/transport) | `manual_review` | `timeout` / `transport_error` | InsertVoucher failure → `manual_review` (note: inquiry failure → `retry`) |
  | `ok=true` but empty `raw_result` | `manual_review` | `malformed_response` | |

  Creation **never** yields `confirmed_unposted` (that is paid-evidence only, from inquiry/bulk). The response does not echo the registration number; the parser carries back whatever `expected_registration_number` you pass. Consumer number is null on creation evidence.

**Evidence** — `components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php` (immutable getters): `status()`, `errorClass()`, `reference()`, `registrationNumber()`, `rawStatus()`, `evidenceHash()`, `redactedTraceId()`, `validationErrors()`, `toArray()` (12 sanitized keys — use for `diagnostic_summary`; excludes raw payload and `operation`).

**Voucher reference service** — `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php`
- `getOrCreateForInvoiceContext(array $context): ?array` (line 48) — reuse-or-create; returns the flat voucher or `null`. Reuses an existing **pending** voucher for the invoice on reload (line 60-63). Flat keys today (line 261-274): `id, company_id, client_id, gateway_id, currency, amount, status, registration_number, consumer_number, date_due, date_expires, invoices`. **Task 1 adds `kuickpay_reference` + `raw_status`.**
- `getLastError(): ?string`.

**Repository** — `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`
- `edit(int $voucher_id, int $company_id, array $vars): void` — **3-arg, company-scoped** (`:151`). `company_id` is the second positional parameter; the model (`models/kuickpay_vouchers.php::edit()` `:83`) force-`unset($vars['company_id'])`, so putting it in `$vars` silently drops scope. Canonical caller to mirror: `KuickPayReconcileService.php:204`.
- `getWithInvoices(int): ?array` → `['voucher'=>stdClass,'invoices'=>array]`. `getPendingByInvoiceId(int,int): ?stdClass` — **pending-only** (model `:166` `where status = 'pending'`). No invoice-level "any status" lookup exists yet — Task 5 adds one for the reload-safety guard.

**Audit** — `plugins/kuickpay_reconcile/lib/KuickPayAuditService.php`
- `record(string $eventName, array $context): void`. Context keys: `company_id`, `voucher_id`, `redacted_trace_id`, `evidence_hash`, `payload` (JSON-encoded, redacted). Canonical event names: `voucher.issued`, `evidence.rejected`. [architecture.md#Audit-and-Logging lines 610-634]

### Idempotency & reload safety — the load-bearing guard (prevents a second payable voucher per invoice on sequential reload)
`getOrCreateForInvoiceContext()` returns the **same pending voucher** on reload only while it is still `pending`; once issuance moves it out of `pending` (any non-success outcome), `getPendingByInvoiceId()` (pending-only) stops returning it and a **fresh** `pending` voucher is created. So a status-only guard is **not enough**: after a timeout the parser maps InsertVoucher → `manual_review`, the voucher leaves `pending`, and on the next reload a new voucher would be created and — under a naive `pending && no reference` guard — **issued again**. If the original timeout actually landed at KuickPay, you now have **two distinct payable references for one invoice**. That is the "blindly retry InsertVoucher" failure the architecture forbids. Two guards are therefore required:

**Guard A — never re-issue the same voucher row.** After Task 1 exposes `kuickpay_reference`, issue a given voucher only when `$voucher['status'] === 'pending' && empty($voucher['kuickpay_reference'])`. A successfully issued voucher on reload is `pending` + reference set → **display only**.

**Guard B — never auto-issue a NEW voucher for an invoice that may already have an outstanding or ambiguous reference.** **Before** calling `getOrCreateForInvoiceContext()`, look up the most recent voucher for the invoice (any status) via a new additive read — `KuickPayVoucherRepository::getLatestByInvoiceId(int $invoice_id, int $company_id): ?stdClass` → model method (see exact query in Task 5). Branch on the **decision matrix** below. The organizing axis is **"could the prior attempt have left a payable or in-flight reference at KuickPay?"** — if yes, BLOCK; if the create provably left nothing (or the prior voucher is terminal-released), ALLOW. **Default for any status not listed → BLOCK (fail closed).**

**Reload decision matrix (by latest prior attempt for the invoice):**

| Latest attempt | error_class | Decision |
|---|---|---|
| none (first attempt) | — | **ALLOW** → create + issue once |
| `pending` + no reference (created, not yet issued) | — | **ISSUE ONCE** (Guard A) |
| `pending` + `kuickpay_reference` set | — | **DISPLAY** the payable voucher — never re-issue |
| `retry` | `timeout`/`transport_error` (transport-ambiguous) | **BLOCK** → safe "confirmation delayed, we're confirming your payment" copy; reconciliation (3.3, reconciles `pending`+`retry`) auto-confirms via inquiry, or it expires |
| `manual_review` | `duplicate_reference`(`94`) / `unknown_status` / `malformed_response` | **BLOCK** → safe copy; needs **manual review (Epic 4)** — a `94` implies a prior create already exists at KuickPay |
| `failed` | `credential_error` (`05`) | **ALLOW** → auth was rejected so **nothing was created**; a reload must be able to succeed once an admin fixes credentials (customer sees safe copy until then) |
| `expired` / `cancelled` | — | **ALLOW** → terminal/released; generate a new voucher while the invoice is unpaid (FR23) |
| `confirmed_unposted` / `posted` | — | **BLOCK** → paid/validated; display status, never issue a new reference |

- Always persist the evidence (Task 6) even on failure, **before** returning, so the prior-attempt lookup sees it on the next load.
- **Do NOT auto-retry `insertVoucher()`** within a request, and do not let reload act as a blind retry for any BLOCK case. The behaviour in this matrix (not a specific status mapping) is the contract for AC5. [architecture.md:414 "do not blindly retry InsertVoucher"; epics.md FR-6/NFR-3/FR23]

> **OPEN DECISION (needs architect sign-off before dev) — timeout/transport resolution path.** The 3.2 parser maps an InsertVoucher transport failure to `manual_review` (`KuickPayResponseParser.php:268`), but `getReconcilable` only picks up `pending`+`retry` (`kuickpay_vouchers.php:214`) — so a `manual_review` row is **never auto-reconciled** and a timed-out-but-landed voucher would sit until Epic 4 manual-review tooling exists (still `backlog`) → a customer dead-end. **Recommended:** in `recordIssueOutcome()`, persist **`retry`** (not the parser's `manual_review`) **for the transport-ambiguous cases only** (`timeout`/`transport_error`, i.e. `ok=false` with no response body). `retry` is reconcilable, so Story 3.3's single `BillPaymentInquiry` (keyed on the stored `registration_number`) self-resolves the ambiguity: landed → confirmed; not landed → retry/backoff → `expired`. This is AC5-compliant (`retry` is an enumerated AC5 outcome) and does **not** violate architecture.md:414 (which forbids retrying *InsertVoucher* but explicitly permits *inquiry* retries). It **is** a deliberate persist-time deviation from the parser's status for the no-response transport case only — keep the parser authoritative for every response-body case (`00`/`94`/`05`/malformed/unknown). If the architect declines the deviation, keep `manual_review` and accept that timeout-landed vouchers are a **known MVP limitation** requiring manual admin action (Epic 4) — do not describe reconciliation as resolving them.

> **CONCURRENCY RESIDUAL (out of 2.3 scope — owned by Story 2.4).** Guard B is an application-level read-then-act; a *concurrent* double-submit (two tabs / double-click) can pass both reads before either writes and mint two distinct references (each carries a random prefix, so the `(company_id, registration_number)`/`(company_id, consumer_number)` unique keys don't collide). The schema-level "active payment context" idempotency that actually closes this (architecture.md:351) is the **2.4-owned** active-context uniqueness item (deferred-work.md:48, residual accepted at 2.2). So this guard's guarantee is **sequential-reload only**; the concurrent vector is a known, accepted deferral, not something 2.3 fully closes. Do not over-claim "prevents a second payable reference" without the "on sequential reload" qualifier.

> **CRASH-WINDOW (exception safety).** `buildProcess()` has no try/catch around the issue seam (`kuickpay.php:585-601`). If `buildVoucherRequest()`/`parse()`/persist throws **after** `insertVoucher()` has already hit the wire, the row stays `pending`/no-reference with no evidence, and the next reload (Guard A) re-issues → double-issue if the first call landed. Mitigate: stamp a sentinel (e.g. set `date_last_checked`) **immediately before** `insertVoucher()`, and/or wrap issue→persist so any post-wire failure persists an ambiguous (`retry`/`manual_review`) row before returning. The common timeout path is already safe (the SOAP client returns an outcome rather than throwing), so this guards only the rarer mid-issue exception.

### Field Mapping (Blesta → InsertVoucher request) — AC1
Field names are exact (from the Phase 0 addendum / WHMCS evidence; treat date format + result offsets as provisional/fail-closed).

| KuickPay field | Source | Notes |
|---|---|---|
| `RegistrationNumber` | `$voucher['registration_number']` | from Story 2.2 |
| `Head1` | `meta['payment_head_label']` (fallback to a sane default label if empty) | setting already exists |
| `Amount1` | `$voucher['amount']` (decimal string) | PKR payable amount |
| `TotalAmount` | = `Amount1` for MVP | |
| `Head2..Head10`, `Amount2..Amount10` | empty / `0` | MVP single head |
| `DueDate` | `date('d-M-y', strtotime($voucher['date_due']))` | from Y-m-d store |
| `AmountAfterDueDate` | = payable amount (no late policy in MVP) | |
| `ExpiryDate` | `date('d-M-y', strtotime($voucher['date_expires']))` | |
| `IssueDate` | today in `d-M-y` | |
| `VoucherMonth` / `VoucherYear` | derived from `date_due` | provisional format |
| `Name` | `company` else `first_name last_name` | from `$contact_info` |
| `Mobile` | sanitized client mobile, else `meta['fallback_mobile']` | AC2; never hard-coded |
| `Email` | client email, else `meta['fallback_email']`, else empty | AC3; configurable only |
| `Branch` | `$contact_info['state']['code'] ?? ''`, else `meta['default_branch']`, else empty | `state` is an array `{code,name}` — never index it as a string |

[Source: PRD addendum §A.2 field map; epics.md FR8; research "Amount Handling"]

### Date handling
Stored `date_due`/`date_expires` are `Y-m-d` (set by `KuickPayVoucherReferenceService::offsetDate()`). KuickPay voucher dates are sent `d-M-y` (e.g. `09-Jun-26`) per live WHMCS evidence — **provisional, unconfirmed by KuickPay**. Centralize the format (one helper, default `d-M-y`) so a confirmed format is a one-line change; do not scatter `date()` format strings. Amounts are decimal strings end-to-end (`normalizeAmount()`); never floats. [NFR13; architecture.md:593]

### Single-identity contract — applies to INQUIRY, not InsertVoucher
The "pass exactly ONE expected identity (registration vs consumer number), never both" rule governs the **single `BillPaymentInquiry`** parse path (`parseBillPaymentInquiry`, lines 486-491 compare both `expected_registration_number` and `expected_consumer_number` against `field[1]`; supplying both forces `unmatched_reference` → a paid row wrongly downgraded to `manual_review`). **InsertVoucher is a creation call and is unaffected** — it legitimately sends/stores both the registration number and the derived consumer number. For 2.3 pass only `expected_registration_number` into the parser context (creation never compares it for paid-truth; it is echoed onto evidence). Do not seed any future inquiry context with both identities. [[kuickpay-parser-single-identity-contract]] [Source: KuickPayResponseParser.php:486-491; PRD addendum §A.3]

### Customer display states (UX) — AC3/AC4/AC5
Only `pending` (successfully issued) may show the payable reference. Map states to conservative copy; success styling/"paid" wording appear only at `posted` (Epic 3). Never expose raw provider status, SOAP names, credentials, stack traces, parser internals, or error classes to the customer. [architecture.md#UI-Display-State-Matrix lines 595-608; UX-DR7/UX-DR19/UX-DR20/UX-DR28]

### Previous-story intelligence & gotchas
- **Class casing is load-bearing.** Framework-instantiated: `Kuickpay`, `KuickpayVouchers`, `KuickpayReconcileModel`. Lib services (not framework-instantiated): `KuickPay*` with capital P (`KuickPaySoapClient`, `KuickPayResponseParser`, `KuickPayEvidence`, `KuickPayVoucherRepository`, `KuickPayVoucherReferenceService`, `KuickPayAuditService`). Match exactly.
- **Gate ordering in `buildProcess()` is load-bearing**: PKR `currencyEligible()` → companion `companionInstalled()` → `if (!$this->Input->errors())` → create/reuse → **(new) issue**. Issuance must stay inside the `!errors()` block so ineligible currency / missing companion can never issue a voucher. [kuickpay.php:577-601]
- **Model error language keys** for any new validation must live in the owning per-model language file, not the plugin file (the plugin class is not constructed during `buildProcess`). Gateway strings live in `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`.
- **`KuickpayVouchers::edit()` does not value-validate.** It only intersects field *names* against `self::FIELDS` and force-unsets `company_id`; `getRules()` runs in `add()` only. The persist step is safe because `$evidence->status()` is always a valid enum (the parser emits only `pending`/`failed`/`manual_review`/`retry`/…), but do not rely on `edit()` to reject an out-of-enum value — write only known-good values.
- **2.3 is the first live mutation surface and first real caller of the redactor/masking boundary.** Several Epic-1 boundary items were deferred "to the first consumer" (mask non-array handling, mask-allowlist completeness, redactor value-based redaction). Re-read `_bmad-output/implementation-artifacts/deferred-work.md` before wiring logging.
- **No live Blesta/MySQL verification has run** in any prior KuickPay story. DB-path behavior (edit/audit writes) cannot be runtime-verified here — keep logic in pure/injectable helpers and state the verification gap explicitly. `ext-soap` is present in this checkout but flag it as a deploy dependency.
- **Testability pattern that works:** pure mapping helper + injectable SOAP-client factory / fake repository + fixtures fed through the parser. The `KuickPaySoapClient` constructor accepts a `$soapClientFactory` fake; `KuickPayVoucherReferenceService`/`KuickPayResponseParser` accept injected repo/redactor.
- Commit convention: `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars; keep BMad/docs artifacts out of the implementation commit. Allowed types: `feat fix docs test refactor chore`.

### Files to touch
**UPDATE (gateway):**
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` — extend `buildProcess()` (Guard-B reload pre-check + issue seam after create/reuse); add `buildVoucherRequest()`, `formatVoucherDate()`, `normalizePkMobile()`, contact-data gathering, and a `getIssuanceService()` factory seam; add `fallback_email`/`default_branch` to pass-through.
- `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt` — add `fallback_email` (+ optional `default_branch`) field.
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` — gate payable display; add safe retry copy.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — new setting + customer copy keys.

**UPDATE (plugin):**
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` — `flatten()` expose `kuickpay_reference` + `raw_status`; add issuance-persist orchestration (or new `KuickPayIssuanceService.php`).
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` + `models/kuickpay_vouchers.php` — add the additive `getLatestByInvoiceId(int $invoice_id, int $company_id): ?stdClass` read (Guard B). Reuse `edit()` (3-arg) for persist; do not change its signature.
- (read-only reuse) `KuickPayAuditService.php`.

**NEW (optional, plugin):** `plugins/kuickpay_reconcile/lib/KuickPayIssuanceService.php` if you prefer a dedicated service over extending the reference service.

**Tests:** `components/gateways/nonmerchant/kuickpay/tests/...` (mapping + issuance branches + idempotency), reusing `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,ambiguous,malformed}/insert-voucher-*`.

**Do NOT touch:** any `KuickPayPostingService`/transaction path; reconciliation services; Blesta core; ionCube-protected files; `config/blesta.php`.

### Testing standards
Parser/mapping fixture tests first; component-local PHPUnit 8.5 via the external runner; `php -l` on every changed file; no root PHPUnit claim (no sibling `../tests`). Do not call live KuickPay. Redact credentials/PII in any fixture or log. [project-context.md Testing Rules; epics.md FR28/NFR11/NFR12]

### Project Structure Notes
- Layout matches architecture.md#Complete-Project-Directory-Structure: SOAP client/parser/evidence/redactor under the gateway `lib/`; voucher persistence/audit/services under `plugins/kuickpay_reconcile/`. No new top-level dirs.
- Variance: architecture lists a `KuickPayVoucherNormalizer` for "validated SOAP-derived evidence → candidate voucher updates." For 2.3 the InsertVoucher persist is a thin status/reference write, so a full normalizer is not required; a small `recordIssueOutcome()` is sufficient. If you introduce `KuickPayIssuanceService`, note it as an additive lib consistent with the plugin `lib/` service pattern.
- `fallback_email`/`default_branch` are additive gateway settings consistent with the existing `fallback_mobile`/`payment_head_label` pattern (FR2 — all voucher-creation config is gateway-owned).

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.3] — story, ACs, sequencing, FR8/FR10.
- [Source: _bmad-output/planning-artifacts/epics.md — FR6-FR11, FR15-FR17; NFR2/NFR3/NFR9/NFR13] — voucher generation + safety requirements.
- [Source: _bmad-output/planning-artifacts/architecture.md#Posting-Contract (581-593), #Anti-Patterns (650-660), #Ownership-Rule (664-673), #Parser-&-Evidence-Contract (549-579), #UI-Display-State-Matrix (595-608), #Audit-and-Logging (610-634)].
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php — buildProcess():567, getSoapClient():492, buildVoucherReferenceContext():627, normalizeAmount():684, maskCredentials():316].
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php — insertVoucher():61, withCredentials():233].
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php — parse():61, parseInsertVoucher():282, inquiry single-identity:486-491].
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php — toArray():142].
- [Source: plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php — getOrCreateForInvoiceContext():48, flatten():246].
- [Source: plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php — edit(), getWithInvoices(), getPendingByInvoiceId()].
- [Source: plugins/kuickpay_reconcile/lib/KuickPayAuditService.php — record()].
- [Source: app/models/contacts.php — getNumbers():924 ('phone','fax' types; 'home','work','mobile' locations); get() email].
- [Source: _bmad-output/implementation-artifacts/0-1-confirm-kuickpay-contract-and-capture-sanitized-fixtures.md] — InsertVoucher contract, fixtures, provisional caveats.
- [Source: _bmad-output/implementation-artifacts/{2-1,2-2,3-1,3-2}-*.md; deferred-work.md; epic-1-retro-2026-06-10.md] — established patterns, deferred boundaries, test runner.
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, loader/Input/Record/language rules, testing/tooling.
- [Memory: kuickpay-parser-single-identity-contract] — single-inquiry one-identity rule (inquiry only, not InsertVoucher).

## Dev Agent Record

### Agent Model Used

_TBD by dev agent_

### Debug Log References

- 2026-06-10: Started implementation from baseline commit `5d048aaab309cce3ed777375485c4360edbc5f28`; loaded story, sprint status, and project context.
- 2026-06-10: Red/green for Task 1 with `KuickPayVoucherReferenceServiceTest::testFlatVoucherExposesIssuanceStateForIdempotency`; targeted service test passes.
- 2026-06-10: Red/green for Task 2 with `KuickPayVoucherGatewayHelpersTest::testVoucherCreationFallbackSettingsHaveLanguageKeys`; targeted helper test passes.
- 2026-06-10: Red/green for Tasks 3-4 with voucher request mapping, PK mobile normalization, date formatting, and contact-data helper tests; targeted helper tests pass.
- 2026-06-10: Implemented Guard B latest-voucher lookup, Guard A issuance, SOAP/parser orchestration, plugin issuance persistence, audit writes, safe display copy, crash-window ambiguous evidence handling, and sanitized issuance diagnostics.
- 2026-06-10: Full validation passed: KuickPay gateway tests `148 tests, 711 assertions`; plugin tests `12 tests, 43 assertions`; `php -l` passed for changed PHP/PDT files.

### Completion Notes List

- Task 1 complete: appended `kuickpay_reference` and `raw_status` to the flat voucher contract with null fallback, preserving existing create/reuse/reference generation behavior.
- Task 2 complete: added optional `fallback_email` and `default_branch` gateway settings and language keys without adding validation rules.
- Tasks 3-4 complete: added pure InsertVoucher mapping, centralized voucher date formatting, PK mobile normalization, email/branch fallback handling, and Blesta Contacts-backed email/mobile gathering.
- Tasks 5-8 complete: added sequential reload safety, single-attempt InsertVoucher issuance, normalized evidence persistence, audit events, crash-window ambiguous evidence handling, sanitized diagnostics, and customer-safe non-payable copy.
- Task 9 complete: added/updated gateway and plugin tests for mapping, fixture-backed parser branches, transport timeout, Guard A/B idempotency, customer safe copy, and issuance persistence.

### File List

- components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
- components/gateways/nonmerchant/kuickpay/kuickpay.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php
- components/gateways/nonmerchant/kuickpay/views/default/settings.pdt
- components/gateways/nonmerchant/kuickpay/views/default/process.pdt
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php
- plugins/kuickpay_reconcile/lib/KuickPayIssuanceService.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
- plugins/kuickpay_reconcile/models/kuickpay_vouchers.php
- plugins/kuickpay_reconcile/tests/KuickPayIssuanceServiceTest.php
- _bmad-output/implementation-artifacts/2-3-map-invoice-data-and-issue-kuickpay-voucher.md
- _bmad-output/implementation-artifacts/sprint-status.yaml

### Change Log

- 2026-06-10: Implemented Story 2.3 invoice-to-InsertVoucher mapping, issuance orchestration, evidence persistence, safe display, diagnostics, and regression tests.

### Review Findings

Adversarial code review (Blind Hunter + Edge Case Hunter + Acceptance Auditor) on baseline `5d048aaab3`..HEAD. **2 patch findings (both fixed), 2 deferred, 9 dismissed as noise/false-positive/per-spec.** Acceptance Auditor confirmed all five ACs and every load-bearing constraint satisfied. Gateway suite green at `152 tests, 721 assertions` after fixes; plugin suite `12 tests, 43 assertions`.

**Patches (fixed):**

- [x] [Review][Patch] Voucher date helpers failed open to the Unix epoch (`01-Jan-70` / month `01` / year `1970`) on empty/unparseable `date_due`/`date_expires` [components/gateways/nonmerchant/kuickpay/kuickpay.php — `buildVoucherRequest()` VoucherMonth/Year + `formatVoucherDate()`] — fixed to fail closed (empty string) so a missing date can never produce a plausible-but-wrong already-expired voucher. Confirmed High by Blind + Edge. (commit `9723d008`)
- [x] [Review][Patch] Broad `catch (Throwable)` in `issueVoucherIfNeeded()` re-persisted fabricated `transport_error`→`retry` evidence over an already-recorded successful issuance when a post-persist step (diagnostic log / latest-voucher re-read) threw, erasing the issued reference [components/gateways/nonmerchant/kuickpay/kuickpay.php — `issueVoucherIfNeeded()`] — fixed with a `$persisted` guard so the authoritative row is never overwritten after a real outcome is recorded (Task 6). (commit `8df90313`)

**Deferred:**

- [x] [Review][Defer] Concurrent double-submit can pass both reload reads before either writes and clobber an issued reference (slower `94`/duplicate response forces the row to `manual_review`, null reference) [plugins/kuickpay_reconcile/lib/KuickPayIssuanceService.php] — deferred, **explicitly out of 2.3 scope** per Dev Notes CONCURRENCY RESIDUAL; closed by the Story 2.4 active-context uniqueness item. Sequential-reload safety (this story's contract) is intact.
- [x] [Review][Defer] Reload `display` branch shows the stored voucher amount without comparing the current invoice balance, so an amount change after issuance is shown stale [components/gateways/nonmerchant/kuickpay/kuickpay.php — `buildProcess()` display branch] — deferred; amount-change handling is reconciliation/Story 2.4 territory and a mismatch fails closed at reconcile time.

**Dismissed (noise / false positive / per-spec):** Blind Hunter's `retry`-remap "double-issue" (false positive — `retry` is resolved by read-only inquiry and the reload matrix BLOCKs it), `invoice_id=0` (false positive — `flatten()` populates `invoices[].invoice_id`; the `invoices=[]` view shape never re-enters issuance), the `'issue'` decision "dead branch" (no functional impact — get-or-create reuses the pending voucher); mobile→empty when no valid fallback, 10-digit local mobile rejection, empty `Name` when all name parts blank, empty-amount pass-through, and `getLatestByInvoiceId` "latest by id" (all match the spec); and the Auditor's two informational notes (no defect).
