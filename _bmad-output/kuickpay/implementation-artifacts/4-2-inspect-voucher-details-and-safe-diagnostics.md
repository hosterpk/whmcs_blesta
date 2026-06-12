---
baseline_commit: 7578534e7ab58621f0a28f4c43c7c561dd1b9ef6
---

# Story 4.2: Inspect Voucher Details and Safe Diagnostics

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a support agent,
I want a complete Voucher detail page with safe evidence,
so that I can explain pending, paid, failed, expired, or Manual Review states.

This is the **second story of Epic 4 (Admin Support and Manual Review Operations)**. It adds a **read-only
Voucher Detail page** to the SAME `admin_vouchers` controller that Story 4.1 created for the list. The detail
page surfaces everything support/finance need to explain a Voucher's state, plus a **permission-gated
"diagnostics" section** that shows the already-sanitized normalized evidence and redacted audit history. It
introduces **no schema change** and **no payment-state mutation**. Writing admin notes, "Check Now", cancel,
and Manual Review actions are **Story 4.3** — this story only **displays** what exists.

## Acceptance Criteria

Canonical ACs from `epics.md` Story 4.2 (lines 750–770):

1. **AC1 — Detail renders the full safe record.** Given an admin opens Voucher Detail, when the page renders,
   then it shows **client, invoice mapping, Registration Number, Consumer Number, amount, dates, current status,
   parsed response summary, posting state, admin notes, and related Blesta invoice/transaction links**.
   *(FR25, UX-DR13)*

2. **AC2 — Diagnostics are sanitized and permissioned.** Given diagnostics are available, when the admin has
   permission to view diagnostics, then **sanitized request/response summaries are visible** AND **raw passwords,
   unredacted SOAP, customer-facing secrets, and internal stack traces are NOT shown**. When the admin lacks the
   diagnostics permission, the diagnostics section is **hidden** (the rest of the page still renders).
   *(FR25, FR27, NFR8, UX-DR14, architecture.md:361-367 "view diagnostics" separate permission)*

3. **AC3 — Long diagnostics stay contained and keyboard-readable.** Given diagnostic content is long, when the
   detail page renders, then it **remains keyboard-readable in a contained block** AND **does not break the admin
   layout** (the block scrolls within itself; it is focusable/scrollable by keyboard). *(UX-DR14, UX-DR24)*

Derived must-hold invariants (implicit requirements — the feature is not correct in the existing system unless
ALL of these hold; treat as ACs):

4. **AC4 — Company scoping (multi-tenant safety).** The detail fetch MUST be scoped to the authenticated staff
   company. A voucher ID that does not belong to `$this->company_id` (or does not exist) MUST resolve to a safe
   "not found" outcome (flash error + redirect to the list) and MUST NEVER render another company's voucher.
   **The existing `KuickpayVouchers::get(int $voucher_id)` is NOT company-scoped (`models/kuickpay_vouchers.php:106-112`)
   — do NOT use it for this page.** *(NFR; 3-3/4-1 company-scope hardening — the single most repeated past finding)*

5. **AC5 — Read-only / idempotent, no mutation.** The detail action performs **no writes, no SOAP, no posting,
   and no state transition**. It is a GET, read-only page. There is **no admin-notes edit form, no Check Now, no
   cancel, and no Force Paid** on this page (those are 4.3/never). *(NFR14; architecture.md:377 "GET is read-only";
   :659-660 "no GET admin route that mutates state", "no force paid")*

6. **AC6 — Safe label rendering via closed allowlists.** Status, error class, audit event names, and validation
   reasons are rendered through **closed allowlists** that map each known token to a language-keyed label, with a
   **safe generic fallback** for unknown/empty values. A DB value must NEVER be concatenated into a language key.
   **Success / "paid" / green badge treatment and the Blesta transaction link appear ONLY for the `posted` state.**
   *(UX-DR19, UX-DR20; 2-5 + 4-1 review gotcha; architecture.md:424, 595-606)*

7. **AC7 — No leakage anywhere on the page.** The page renders only already-redacted / allowlisted fields. It MUST
   NOT render raw SOAP/XML, raw credentials/passwords, SOAP operation names, internal stack traces, or exception
   classes. The `diagnostic_summary` JSON is rendered by **pulling only known keys** through the presenter allowlist
   (never a blind `foreach` echo of decoded JSON). The audit `payload` JSON varies per event and contains **nested**
   structures (`counts`, `cursor`, `validation_errors`), so it is rendered as a **single escaped, pretty-printed JSON
   string** inside the gated diagnostics block — it is already past the single redaction boundary and is guarded by
   `KuickPaySecretLeakageTest`, so it is shown verbatim-but-escaped, **never decoded into a per-key echo loop and never
   mapped to per-event UI labels**. **Every** dynamic value is escaped with `$this->Html->safe(...)`. Amounts render as
   the stored decimal string (never PHP float math). *(NFR8, NFR13, UX-DR28, FR3; architecture.md:373 single redaction
   boundary, :608, :655-656)*

8. **AC8 — Diagnostics permission correctly declared and checked.** A **separate** plugin permission gates the
   diagnostics section: a new `getPermissions()` row with alias `kuickpay_reconcile.admin_vouchers` and
   `action => 'diagnostics'` (declared **alongside**, not replacing, the existing `action => '*'` view-records row
   and the `kuickpay_reconcile.admin_main` row). The controller checks it with
   `$this->authorized('kuickpay_reconcile.admin_vouchers', 'diagnostics')` and passes a boolean to the view. The
   token `diagnostics` is **NOT** a real public controller method (so it only gates the section, not a route).
   *(architecture.md:361-367; 4-1 forward constraint "diagnostics (4.2) gets its own separate permission")*

## Tasks / Subtasks

- [ ] **Task 1 — Add the company-scoped single-voucher read method** (AC: 1,4)
  - [ ] In `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` add
    `public function getForCompany(int $voucher_id, int $company_id)` returning the row or `false`, scoped by
    **both** `id` and `company_id`:
    `$this->Record->select()->from('kuickpay_vouchers')->where('id','=',$voucher_id)->where('company_id','=',$company_id)->fetch();`
    Mirror the existing `getByConsumerNumber()` shape (`:121-128`).
  - [ ] **Do NOT change `get(int $voucher_id)` (`:106-112`)** — it is called by
    `KuickPayVoucherRepository::getWithInvoices()` (`lib/KuickPayVoucherRepository.php:144`); leave that caller
    untouched. Add the new scoped method rather than re-signing `get()`. (Every other read method in this model —
    `getByConsumerNumber`, `getPostable`, `getForUpdate`, … — already takes `int $company_id`; this closes the one
    gap.)

- [ ] **Task 2 — Add the company-scoped audit-history read method** (AC: 2)
  - [ ] The audit model `plugins/kuickpay_reconcile/models/kuickpay_audit_events.php` is currently **write-only**
    (`add()` only) and so is `lib/KuickPayAuditRepository.php`. Add a read method to the **model**:
    `public function getByVoucher(int $voucher_id, int $company_id, int $limit = 100): array` →
    `select(['event_name','redacted_trace_id','evidence_hash','payload','date_created'])->from('kuickpay_audit_events')
    ->where('voucher_id','=',$voucher_id)->where('company_id','=',$company_id)->order(['date_created'=>'DESC','id'=>'DESC'])
    ->limit(max(1,$limit))->fetchAll();`
    Select an explicit column list (NOT `*`) — never select `company_id`/`run_id`/`id` into the view. The table has
    indexes on `voucher_id` and `company_id` (`kuickpay_reconcile_plugin.php:348-350`).
  - [ ] Optionally add a thin pass-through on `KuickPayAuditRepository` (`getByVoucher(...)`) to match the
    write-side `add()` pattern, but the controller may load the model directly — keep it minimal.

- [ ] **Task 3 — Extend the presenter with closed label allowlists (pure, testable seam)** (AC: 6,7)
  - [ ] In `plugins/kuickpay_reconcile/lib/KuickPayVoucherListPresenter.php` (pure PHP, no DB) add:
    - `public const ERROR_CLASS_LABEL_KEYS` mapping the error-class tokens that can actually be stored on the voucher
      row to `AdminVouchers.error_class.<class>` keys, and `errorClassLabelKey(?string $class): string` returning the
      mapped key or `AdminVouchers.error_class.unknown` for null/unknown. **The stored domain is the parser's 8 canonical
      classes** (`timeout`, `transport_error`, `credential_error`, `malformed_response`, `unknown_status`, `amount_mismatch`,
      `duplicate_reference`, `unmatched_reference` — architecture.md:569-577; `KuickPayResponseParser::ALLOWED_ERRORS`)
      **PLUS two operational tokens written outside the parser**: `posting_failed` (`lib/KuickPayPostingService.php:345`)
      and `reconcile_exception` (`lib/KuickPayReconcileService.php:335`). Map **all 10** (verified by grepping every
      `'error_class' => ...` write); a token outside this set falls back to the safe `unknown` label.
    - `public const EVENT_LABEL_KEYS` mapping the emitted audit event names to `AdminVouchers.event.<name>` keys, with
      `eventLabelKey(string $event): string` falling back to a generic key for unknown events. Known emitted names
      (grep-verified): `voucher.issued`, `voucher.replaced`, `voucher.expired`, `evidence.received`, `evidence.matched`,
      `evidence.retry_decision`, `evidence.rejected`, `evidence.duplicate`, `evidence.unmatched`,
      `reconciliation.run.started`, `reconciliation.run.completed`, `posting.started`, `posting.succeeded`,
      `posting.failed`. **The label is for display only; the raw event token is itself non-secret, so an unknown token
      may be shown escaped — but always go through the map first.**
    - `public const VALIDATION_REASON_LABEL_KEYS` mapping the **validation-reason tokens stored inside
      `diagnostic_summary.validation_errors`** to `AdminVouchers.validation_reason.<reason>` keys, and
      `validationReasonLabelKey(string $reason): string` returning the mapped key or a safe generic
      `AdminVouchers.validation_reason.unknown` for any unknown/empty token. **AC6 promises these reasons go through a
      closed allowlist with a safe generic fallback — this is that allowlist.** `validation_errors` is populated from
      **three** sources; map the known tokens from all three (grep-verified):
        - **plugin validator** (`lib/KuickPayEvidenceValidator.php:58-80,186-192`): `currency_mismatch`, `amount_mismatch`,
          `unmatched_reference`, `invoice_mismatch`, `stale_voucher`, `duplicate_reference`, `late_payment`;
        - **posting** (merged into `validation_errors` via `moveToManualReview()` → `mergeValidationErrors()`,
          `lib/KuickPayPostingService.php:341-359`): `missing_paid_date` (`:81`), `existing_transaction_mismatch` (`:213`),
          `existing_transaction_partial_application` (`:222`), `existing_transaction_apply_failed` (`:228`),
          `existing_transaction_unverified` (`:234`);
        - **gateway parser** (stored via `$evidence->validationErrors()` at `lib/KuickPayReconcileService.php:457-466`
          and `lib/KuickPayIssuanceService.php:31-40`; tokens in `components/gateways/.../KuickPayResponseParser.php`):
          `missing_expected_context`, `underpayment`, `overpayment`, `unknown_status` (its `amount_mismatch` /
          `currency_mismatch` / `unmatched_reference` are already covered above).
      Map **all of the above + the generic `unknown` fallback.** **Do NOT add `transaction_add_failed` /
      `transaction_apply_failed`** — those are `posting.failed` **audit-payload** reasons only (`:160-177`), never merged
      into `validation_errors`; they surface in the escaped payload blob, not through this label map. The parser-side tail
      lives across the gateway boundary and is open-ended, so the generic fallback — not an exhaustive cross-boundary
      enumeration — is what guarantees AC6 for any future/unseen token; never concatenate the raw token into a language key.
    - `public const DIAGNOSTIC_FIELD_KEYS` — the **closed allowlist of `diagnostic_summary` keys** the view is allowed
      to render: `status`, `raw_status`, `error_class`, `evidence_hash`, `redacted_trace_id`, `validation_errors`,
      and (issuance shape) `reference`, `consumer_number`, `registration_number`, `amount`, `currency`, `paid_at`.
      Add `allowedDiagnosticFields(array $decoded): array` that returns only those keys, in a fixed display order,
      that are present and non-empty. This guarantees AC7 even if a future writer adds a new key to the JSON.
      **Define "non-empty" as `$v !== null && $v !== '' && $v !== []` — NOT PHP `empty()`** — so a legitimate provider
      value of `'0'` (e.g. a `raw_status` of `'0'`, the exact diagnostic evidence support is reading) is **kept**, while
      `null` / `''` / `[]` are dropped. Note `validation_errors` is an **array** value: preserve it as an array (do not
      stringify it here) so the view can iterate it through `validationReasonLabelKey()`.
  - [ ] Keep these as pure constants/methods (no `Language::_`, no DB) so they stay unit-testable without the framework,
    exactly like the existing `STATUS_LABEL_KEYS` seam.

- [ ] **Task 4 — Add the `detail()` action to the `admin_vouchers` controller** (AC: 1,2,4,5,6,8)
  - [ ] In `plugins/kuickpay_reconcile/controllers/admin_vouchers.php` add `public function detail()`. **First line:**
    resolve the company explicitly — `$company_id = (int) $this->company_id;` (the snippets below all use `$company_id`;
    `index()` does the same at `:51`, so make it explicit and never read `company_id` from the request). Then resolve the
    voucher id from the route: `$voucher_id = (isset($this->get[0]) && ctype_digit((string) $this->get[0])) ? (int) $this->get[0] : 0;`
    The URL is `plugin/kuickpay_reconcile/admin_vouchers/detail/{id}/` so the id is `$this->get[0]`. (`ctype_digit` is
    **deliberately stricter** than the list `index()`'s `is_numeric` page-param check — it rejects `"1e3"`, floats, and
    signs; do not "align" it to the list's looser check.)
  - [ ] Fetch **company-scoped**: `$voucher = $this->KuickpayVouchers->getForCompany($voucher_id, $company_id);`
    If `$voucher_id <= 0` or `!$voucher` → `$this->flashMessage('error', Language::_('AdminVouchers.!error.not_found', true), null, false);`
    then `$this->redirect($this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/index/');` and `return;`.
    This single guard covers both "missing id" and "another company's voucher" (cross-company fetch returns `false`).
  - [ ] Resolve display data reusing the **already company-scoped** batched methods from 4.1 (no new unscoped queries):
    - `$invoices = $this->KuickpayVoucherInvoices->getByVoucherIds([$voucher_id], $company_id)[$voucher_id] ?? [];`
    - `$client_codes = $this->KuickpayVouchers->getClientCodes([(int) $voucher->client_id], $company_id);`
      → `$client_code = $client_codes[(int) $voucher->client_id] ?? $voucher->client_id;`
  - [ ] Decode the sanitized normalized evidence for the "parsed response summary" / diagnostics:
    `$diagnostic = json_decode((string) $voucher->diagnostic_summary, true); if (!is_array($diagnostic)) { $diagnostic = []; }`
    then `$diagnostic = $this->presenter->allowedDiagnosticFields($diagnostic);` (allowlist filter — Task 3).
  - [ ] **Permission gate (AC8):** `$can_view_diagnostics = $this->authorized('kuickpay_reconcile.admin_vouchers', 'diagnostics');`
    Only when `$can_view_diagnostics` is true, load the audit history:
    `Loader::loadModels($this, ['KuickpayReconcile.KuickpayAuditEvents']);` then
    `$events = $this->KuickpayAuditEvents->getByVoucher($voucher_id, $company_id);` (load lazily inside the gate so an
    unauthorized admin never even runs the audit query). Otherwise `$events = []`.
  - [ ] `set()` to the view: `voucher`, `invoices`, `client_code`, `diagnostic` (allowlisted decoded array),
    `events`, `can_view_diagnostics`, and `presenter`. No business logic in the view.
  - [ ] **Read-only:** the action has no `$this->post` branch, no model `edit()`/`add()`, no service call that mutates
    state, and no SOAP. (`AdminMain::run()` at `controllers/admin_main.php:34` is the mutating pattern — `detail()` must
    NOT resemble it.) The view auto-resolves to `admin_vouchers_detail.pdt` (see Dev Notes "View file resolution");
    no `setView()` call needed.

- [ ] **Task 5 — Register the diagnostics permission and bump the version** (AC: 8)
  - [ ] In `kuickpay_reconcile_plugin.php::getPermissions()` (`:218-234`) **ADD** a third entry, keeping the existing two:
    ```php
    [
        'group_alias' => 'admin_billing',
        'name' => Language::_('KuickpayReconcilePlugin.permission.vouchers_diagnostics', true),
        'alias' => 'kuickpay_reconcile.admin_vouchers',
        'action' => 'diagnostics'
    ]
    ```
    Same `alias` as the view-records row, distinct `action`. **All three entries must be returned** — upgrade
    deletes + re-adds the whole permission set (see Dev Notes "Plugin upgrade re-sync"; 4-1).
  - [ ] Add the language key `KuickpayReconcilePlugin.permission.vouchers_diagnostics` to
    `language/en_us/kuickpay_reconcile_plugin.php` (next to the existing `permission.vouchers` / `permission.bulk_reconcile` keys).
  - [ ] Bump `config.json` `version` `1.5.0` → `1.6.0`, and add a `version_compare($current_version, '1.6.0', '<')`
    branch to `upgrade()` (intentionally empty — **no schema change**; the bump exists only so
    `PluginManager::upgrade()` re-syncs the permission set from `getPermissions()` and registers the new
    `diagnostics` ACO).

- [ ] **Task 6 — Build the detail view** (AC: 1,2,3,6,7)
  - [ ] Create `plugins/kuickpay_reconcile/views/default/admin_vouchers_detail.pdt`. Use `$this->Widget` boxes (mirror
    `views/default/admin_vouchers.pdt:10-14` and `admin_main.pdt`), NOT the filter list widget. Include a **"Back to
    Voucher List"** link at the top → `plugin/kuickpay_reconcile/admin_vouchers/index/` (plain GET; the list restores its
    own filter state per 4.1; the back-link text comes from `AdminVouchers.link.back_to_list`, not a literal). Suggested boxes:
    1. **Voucher summary** (always): status badge (`$presenter->badgeClassFor`/`labelKeyFor`; success/green ONLY for
       `posted`), Registration Number, Consumer Number, **`kuickpay_reference`** (the KuickPay transaction/auth
       reference — `font-monospace`; `AdminVouchers.text.empty` when null), amount + currency, dates (created, updated,
       due, expires, last inquiry, paid, posted — use `$this->Date->cast(...)`, the **`AdminVouchers.text.empty`** key
       when null: `$voucher->date_x ? $this->Date->cast($voucher->date_x) : $this->_('AdminVouchers.text.empty', true)`),
       client (code linked to `clients/view/{client_id}/`).
    2. **Invoice mapping & related Blesta records** (always): each linked invoice → `clients/editinvoice/{client_id}/{invoice_id}/`;
       **posting state** — when `status === 'posted'` and `blesta_transaction_id` set, link the transaction via
       `clients/edittransaction/{client_id}/{blesta_transaction_id}/`; otherwise render the admin-label posting state from
       the display-state matrix (e.g. confirmed_unposted → "Validated evidence, ready to post" — **never** "paid").
    3. **Admin notes** (always): render `voucher->admin_notes` as escaped text (`nl2br($this->Html->safe(...))`), or the
       empty placeholder. **Read-only — no edit form** (that is 4.3).
    4. **Parsed response summary** (always): a short, safe summary using **mapped labels only** — status label,
       error-class label (`$voucher->error_class ? errorClassLabelKey($voucher->error_class) : 'AdminVouchers.text.none'`
       — the `text.none` key for *no error class*, never a literal "None"), `retry_count`, last-inquiry time. Do NOT put
       raw provider codes here.
    5. **Diagnostics** (gated — render the whole box only `if ($can_view_diagnostics)`): the allowlisted `diagnostic`
       fields rendered as labelled rows — `raw_status`, `error_class` mapped through `errorClassLabelKey()`,
       `evidence_hash`, `redacted_trace_id`, plus issuance fields when present. Render **`validation_errors` as a list**,
       mapping **each** token through `validationReasonLabelKey()` (Task 3) and escaping per item — never echo the raw
       array. Then the redacted **audit timeline** (`events`): per row `eventLabelKey(event_name)` label,
       `Date->cast(date_created)`, `redacted_trace_id`, `evidence_hash`, and the `payload`. **Render `payload` as a single
       escaped, pretty-printed JSON string** — `json_decode` it, and if it decodes to an array emit
       `$this->Html->safe(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))`
       inside a `<pre>` within the contained block (fallback: escape the raw stored string). **Do NOT decode the payload
       into a per-key echo loop and do NOT map payload keys to UI labels** — its shape varies per event and includes
       nested arrays (`counts`, `cursor`); it is already past the redaction boundary (AC7). Render the `diagnostic`
       allowlisted fields by pulling known keys only — never a blind `foreach` echo.
  - [ ] **Contained, keyboard-readable diagnostics block (AC3):** wrap long diagnostic/audit content in a scrollable
    region — e.g. `<div class="border rounded p-2" style="max-height:24rem;overflow:auto" tabindex="0" role="region"
    aria-label="...">` so it scrolls within itself, is keyboard-focusable/scrollable, and cannot break the admin
    layout. Use `font-monospace` for the evidence/diagnostic values (UX-DR26).
  - [ ] **Monospace (UX-DR26):** `consumer_number`, `registration_number`, `kuickpay_reference`, `blesta_transaction_id`,
    `evidence_hash`, `redacted_trace_id`, `raw_status`, and the sanitized diagnostic/audit values → `font-monospace`
    (Bootstrap **5.3.8** admin paradigm theme; `text-monospace` is a dead no-op here — 4-1 finding). Everything else
    inherits normal typography.
  - [ ] Escape **every** dynamic value with `$this->Html->safe(...)`. No SQL/business logic in the view.

- [ ] **Task 7 — Link the list rows to the detail page** (AC: 1)
  - [ ] In `plugins/kuickpay_reconcile/views/default/admin_vouchers.pdt`, make the **Consumer Number** cell (`:91`)
    a link to `plugin/kuickpay_reconcile/admin_vouchers/detail/{voucher->id}/` (keep the `font-monospace`, keep
    `$this->Html->safe(...)`). This is the row's entry point to the detail page. (No new column needed; the list
    already links client/invoice/transaction to Blesta core pages.)

- [ ] **Task 8 — Language keys for the detail page** (AC: 1,2,3,6)
  - [ ] Add keys to `plugins/kuickpay_reconcile/language/en_us/admin_vouchers.php` (auto-loaded for the `AdminVouchers`
    controller): detail box titles, every field label (registration number, `kuickpay_reference`, dates, posting state,
    admin notes, parsed-summary fields), `AdminVouchers.error_class.*` for **all 10 stored tokens** (the 8 parser classes
    + `posting_failed` + `reconcile_exception`) **plus `unknown`** — match `ERROR_CLASS_LABEL_KEYS` exactly (Task 3), do
    NOT stop at 8 or the two operational tokens render untranslated, `AdminVouchers.event.*` (the ~14 event names + a
    generic fallback), `AdminVouchers.validation_reason.*` for **every reason in `VALIDATION_REASON_LABEL_KEYS`** (the
    validator + posting + parser sets + `unknown` fallback — Task 3), the diagnostics box title + aria-label, and
    `AdminVouchers.!error.not_found`. **Placeholders/links — use language keys, never literals** (Task 6 must not hard-code
    `—` / `None` / link text): `AdminVouchers.text.empty` (`—`, for null dates/`kuickpay_reference` — **already defined by
    4.1** at `language/en_us/admin_vouchers.php:42`, reuse it), a **new** `AdminVouchers.text.none` (`None`, for the
    *no error class* case in the parsed summary — semantically distinct from `—`), and a **new**
    `AdminVouchers.link.back_to_list` (the back-link text). NO hard-coded UI strings in controller/view.

- [ ] **Task 9 — Tests + verification** (AC: 6,7,8)
  - [ ] Extend `plugins/kuickpay_reconcile/tests/KuickPayVoucherListPresenterTest.php` (no bootstrap change needed —
    the presenter is already required at `tests/bootstrap.php:6`): cover `errorClassLabelKey()` (each of the 10 stored
    tokens — 8 parser classes + `posting_failed` + `reconcile_exception` → its key; null/unknown →
    `AdminVouchers.error_class.unknown`), `eventLabelKey()` (each known event → its key; unknown → generic fallback),
    `validationReasonLabelKey()` (a reason from each of the three sources maps to its key — validator e.g.
    `currency_mismatch`, posting e.g. `existing_transaction_mismatch` / `missing_paid_date`, parser e.g. `underpayment` /
    `unknown_status`; the audit-only `transaction_add_failed` / `transaction_apply_failed` and any unknown/empty token →
    `AdminVouchers.validation_reason.unknown`), and `allowedDiagnosticFields()` (drops unknown keys, keeps the
    allowlisted set, preserves the fixed display order; **keeps a `raw_status` of `'0'`** but drops `null` / `''` / `[]`;
    a `validation_errors` array value survives as an array). Drift checks: assert `ERROR_CLASS_LABEL_KEYS` keys equal the
    full stored domain (the 8 parser classes + the 2 operational tokens), mirroring the existing `CANONICAL_STATUSES`
    drift test. For `VALIDATION_REASON_LABEL_KEYS`, assert **exactness** (every key resolves to a real
    `AdminVouchers.validation_reason.*` language key — no dead entries) and that it **covers the plugin-local reasons**
    (the validator + posting sets); do **not** assert strict equality with an exhaustive domain — the gateway-parser tail
    is open-ended and the generic `unknown` fallback covers it (AC6).
  - [ ] Run the plugin suite with the external runner: `cd plugins/kuickpay_reconcile &&
    /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (NEVER `-c build/phpunit.xml` —
    broken bootstrap path). Note the **pre-existing** `KuickPaySecretLeakageTest` 1 failure baseline (see 4-1 Debug Log)
    — do not attribute it to this story; confirm no NEW failures.
  - [ ] `php -l` on every changed/added PHP file (controller, both models, presenter, plugin handler, both language
    files, the new `.pdt`, the test). Validate `config.json` as JSON; version `1.6.0`.
  - [ ] State exactly what ran and what could not (DB-backed install/upgrade smoke, live admin render of the detail
    page + the `authorized()` gate) per NFR12 — do not claim root/`../tests` coverage.

## Dev Notes

### CRITICAL — This page adds to the SAME controller 4.1 created (no new controller)

Story 4.1 built `admin_vouchers` (controller + `admin_vouchers.pdt` + `admin_vouchers.php` language + presenter +
model query layer + nav/ACL) and explicitly set up 4.2 to add a `detail()` action here (architecture.md:792
"`admin_vouchers.php`: search, list, and detail only"; 4-1 "4.2 adds a `detail()` action to the SAME
`admin_vouchers` controller"). **Do NOT create a new controller.** The base wiring already exists:
`controllers/admin_vouchers.php:24-41` (`preAction` loads `KuickpayVouchers`, `KuickpayVoucherInvoices`, and the
presenter; calls `$this->requireLogin()`). Your `detail()` action reuses that wiring; only the audit-events model
is loaded lazily inside the diagnostics gate (Task 4).

### What already exists (read before writing — exact anchors)

- **Voucher table** `kuickpay_vouchers` (`kuickpay_reconcile_plugin.php:38-73`) — all detail fields are columns on this
  one row: `client_id, currency, amount(varchar 20), status(enum 8), registration_number, consumer_number, date_due,
  date_expires, date_created, date_updated, date_posted, date_paid, date_last_checked, retry_count, error_class(varchar 32),
  raw_status(varchar 8), evidence_hash(varchar 24), kuickpay_reference(varchar 128), blesta_transaction_id(nullable int,
  indexed), diagnostic_summary(text, JSON), admin_notes(text)`. No schema change for this story.
- **`diagnostic_summary` is ALREADY SANITIZED at write time** — it is JSON derived **only** from the normalized
  `KuickPayEvidence` object, which never contains raw SOAP/credentials/PII. Two shapes occur:
  - reconcile writer (`lib/KuickPayReconcileService.php:457-467` `diagnosticSummary()`):
    `{status, raw_status, error_class, evidence_hash, redacted_trace_id, validation_errors}`.
  - issuance writer (`lib/KuickPayIssuanceService.php:31-39`, `$evidence->toArray()`):
    `{status, error_class, reference, consumer_number, registration_number, amount, currency, paid_at, raw_status,
    redacted_trace_id, evidence_hash, validation_errors}`.
  - posting `moveToManualReview` (`lib/KuickPayPostingService.php:346-349`) merges extra `validation_errors` into the
    string. **Your job is to DECODE and render allowlisted known keys — not to re-redact, and never to fetch or render
    raw SOAP.** Raw SOAP/XML is never stored on the voucher; do not try to display it.
- **The redaction utility** `components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php` (`redactArray`,
  `redactEnvelope`, `sensitiveValues`, `traceId`) lives in the **gateway**, upstream of persistence. The detail page
  does **not** call it — the stored evidence is already past the redaction boundary (architecture.md:373, 397). Do not
  add a gateway dependency to the plugin detail page.
- **`KuickPaySecretLeakageTest`** (`plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php`) asserts persisted
  evidence/audit payloads contain no raw `Envelope|Header|Body`, no `*Result` elements, no `userName|password|InstitutionID`
  keys, and no PII. Because the detail page renders only those already-clean persisted fields (and only allowlisted keys),
  it cannot regress that contract — but keep the allowlist render (AC7) as the structural guarantee.
- **`error_class` value domain:** the **8 parser classes** (architecture.md:569-577; `KuickPayResponseParser::ALLOWED_ERRORS`)
  `timeout, transport_error, credential_error, malformed_response, unknown_status, amount_mismatch, duplicate_reference,
  unmatched_reference` — **plus 2 operational tokens written outside the parser**: `posting_failed`
  (`lib/KuickPayPostingService.php:345`) and `reconcile_exception` (`lib/KuickPayReconcileService.php:335`) — or `null`.
  Map **all 10 + an `unknown` fallback** in the presenter (Task 3). Do NOT treat the domain as "8 + null" — the two
  operational tokens are really stored and must have labels.
- **Audit table** `kuickpay_audit_events` (`kuickpay_reconcile_plugin.php:337-351`): `id, company_id, voucher_id, run_id,
  event_name(varchar 64), redacted_trace_id(varchar 32), evidence_hash(varchar 24), payload(text, redacted JSON),
  date_created`. Indexed on `voucher_id`, `company_id`, `event_name`. Written via `lib/KuickPayAuditService::record()`
  (`payload` is `json_encode`d redacted context). **The model `models/kuickpay_audit_events.php` and
  `lib/KuickPayAuditRepository.php` are WRITE-ONLY today — you add the read method (Task 2).**
- **admin_notes has NO writer today** — the column exists but nothing populates it yet (writing notes is Story 4.3).
  This story **displays** it (empty for most rows).
- **Blesta deep-link routes (verified canonical in this build, used by the 4.1 list):**
  `clients/view/{client_id}/`, `clients/editinvoice/{client_id}/{invoice_id}/`,
  `clients/edittransaction/{client_id}/{transaction_id}/`. **Do NOT use `billing/invoices/edit/...`** — it does not
  exist in this build (4-1 Change Log). `blesta_transaction_id` is a numeric `transactions.id`, suitable for the
  transaction link.
- **Presenter seam** `lib/KuickPayVoucherListPresenter.php` — already has `labelKeyFor()`, `badgeClassFor()`
  (success only for `posted`), and the closed status allowlist. Reuse for the status badge; extend with the new
  error-class / event / diagnostic-field allowlists (Task 3).

### View file resolution (why it is `admin_vouchers_detail.pdt`, NOT `admin_voucher_detail.pdt`)

Blesta/minPHP auto-resolves a controller action's template as `views/default/<controller_snake>.pdt` for `index`,
and `views/default/<controller_snake>_<action>.pdt` for other actions — **no `setView()` call needed**. Confirmed
by `plugins/client_documents/controllers/admin_main.php`: `index()` renders `admin_main.pdt`, `add()` renders
`admin_main_add.pdt` (neither calls `setView`). Therefore `AdminVouchers::detail()` auto-renders
`views/default/admin_vouchers_detail.pdt`.

**Documented variance:** `architecture.md:735` names the detail view `admin_voucher_detail.pdt` (singular). We use
`admin_vouchers_detail.pdt` (plural) to match the **implemented** `admin_vouchers` controller + `detail` action
auto-derivation — avoiding a brittle explicit `$this->view->setView(...)` and matching the existing
`admin_main_add.pdt` precedent. (4.1 already follows the architecture's `admin_vouchers` naming over older sketches;
this is the consistent continuation.)

### Diagnostics permission — how the gate actually works (Blesta plugin ACL)

The gate is a **separate plugin permission with the same alias but a distinct action**, exactly the
`support_manager.admin_tickets` + `action => 'delete'` multi-permission pattern (`plugins/support_manager/support_manager_plugin.php:1901-1947`).

- **Declare** (Task 5): a third `getPermissions()` row, `alias => 'kuickpay_reconcile.admin_vouchers'`,
  `action => 'diagnostics'`, `group_alias => 'admin_billing'`. On the version bump, `PluginManager` re-adds the whole
  set and registers `diagnostics` as an ACL ACO (`app/models/permissions.php:278-294` `add()` calls `Acl->addAco`).
- **Check** (Task 4): `$this->authorized('kuickpay_reconcile.admin_vouchers', 'diagnostics')`. **Arg 1 is the FULL
  dotted alias** (`plugin.controller`), proven by `app/controllers/admin_myinfo.php:494-497` which builds
  `plugin.controller` before calling `authorized()`, and by `Permissions::authorized()` matching
  `permissions.alias = $aco` literally (`app/models/permissions.php:240-264`). Returns `bool`; use it as a boolean
  guard (like `admin_search.php:158/216/385`) — **do NOT redirect**; just hide the section.
- **Why it must be declared (corrected mechanism — the fail-mode is CLOSED, not open):** `Permissions::authorized()`
  looks up `$permission` with `where alias = $aco AND (action = $action OR action = '*')`, then short-circuits
  `return true` **only** `if (!$group && !$permission)` (`permissions.php:248-260`). Because the existing view-records
  `action => '*'` row already lives on `kuickpay_reconcile.admin_vouchers`, the `orWhere action = '*'` makes
  `$permission` **always truthy** for this alias — so the `return true` short-circuit **cannot fire here even if you
  forget the `diagnostics` row.** Authorization therefore always falls through to `Acl->check($aro, $aco, 'diagnostics')`,
  which is **default-deny** for any group that has not been explicitly granted the `diagnostics` action. Net: an
  undeclared/ungranted `diagnostics` fails **closed** (nobody sees it), NOT open. The declaration + version bump are
  required so the `diagnostics` action is registered as a grantable ACL ACO at all (not to avoid a fail-open), and the
  `'*'` view-records grant does **not** subsume `diagnostics` — `Acl->check` treats `'*'` and `'diagnostics'` as distinct
  action axes (the `support_manager.admin_tickets` `'*'` + `'delete'` precedent depends on exactly this). **Verify the
  separation honestly:** confirming only a fully-granted admin sees the box proves nothing — you must test (a) a staff
  group **with** `diagnostics` granted → box visible, and (b) a group **with** view-records `'*'` but **without**
  `diagnostics` → box hidden while the rest of the page still renders. (`app/components/acl/acl.php` is
  ionCube-protected/unreadable in this checkout, so the `'*'`-vs-specific-action runtime behavior cannot be source-read
  here; it rests on the readable `permissions.php` flow + the shipped `support_manager` precedent.)
- **Non-subsumption is corroborated by two readable mechanisms** (so the claim does not hinge on the unreadable
  `Acl->check` alone): (1) `app/models/staff_groups.php::setPermissions()` writes an **explicit per-action `Acl->allow`/
  `Acl->deny`** — a group granted `'*'` but not `diagnostics` gets an explicit **`deny(..., 'diagnostics')`**, which would
  be unreachable dead code if `'*'` subsumed specifics; (2) `support_manager`'s v2.14.1 upgrade **explicitly iterates
  staff groups and `Acl->allow(..., 'delete')`** for a new same-alias action — also dead code under subsumption. Both
  confirm `'*'` and a specific action are distinct axes.
- **R2-1 — the fail-closed property depends on the `'*'` view-records row staying on this alias.** That row keeps
  `$permission` truthy and prevents the `!$group && !$permission` default-allow short-circuit. Task 5 already mandates
  keeping all existing rows, so the invariant holds — but a future refactor that removes/renames the `admin_vouchers
  action '*'` row could flip the gate (and the whole controller) to fail-**open**. Keep that coupling in mind.
- **Live-verify the one unreadable residual** (the `Acl->check` no-entry default, only reachable in a transient
  post-upgrade window before any staff-group re-save): create a group with view-records `'*'` only, saved **before** the
  `diagnostics` ACO exists; run the `1.6.0` upgrade; then **without re-saving** the group, open a voucher detail — the
  box must be **hidden** (default-deny). If it is ever visible, optionally harden the `1.6.0` `upgrade()` branch with
  `Acl->deny(..., 'diagnostics')` for existing groups. Per AC7 the section shows only redacted/allowlisted evidence, so a
  worst-case window leak is redacted diagnostics, not secrets.
- **`diagnostics` must NOT be a real public method.** If a `public function diagnostics()` ever exists on this
  controller, the framework's preAction ACL layer would auto-gate that whole route with this same permission. Since
  "view diagnostics" is only a SECTION of `detail()`, keep `diagnostics` as a section token only (the section gate is
  decoupled from routing — the idiomatic Blesta way).
- `app/app_controller.php` (where `authorized()` is defined) is **ionCube-protected/unreadable**; rely on the call
  contract above, which is fully evidenced from core usages and the readable `Permissions` model.

### Plugin upgrade re-sync (non-obvious — prevents wasted manual SQL) — carry-forward from 4.1

`PluginManager::upgrade()` runs only when `config.json` version ≠ installed version (`app/models/plugin_manager.php:354`),
and on a version change it **deletes + re-adds the plugin's entire permission set and action set** from the live
`getPermissions()`/`getActions()` return values. Therefore: bump the version (Task 5), and **keep all existing
entries** — the two current permissions (`kuickpay_reconcile.admin_vouchers action '*'`,
`kuickpay_reconcile.admin_main action '*'`) and both nav entries — alongside the new diagnostics permission, or they
get dropped. No manual INSERT SQL. (Staff-group grants for the plugin may need re-granting after upgrade — standard
Blesta behavior, including granting the new `diagnostics` permission to the groups that should see it.)

### UI Display-State Matrix (admin labels + forbidden treatments) — architecture.md:595-606

| State | Admin label (posting state) | Forbidden on this page |
|---|---|---|
| `pending` | Voucher active, not posted | success styling |
| `retry` | Provider unavailable | mark paid |
| `confirmed_unposted` | Validated evidence, ready to post | direct transaction / "paid" |
| `posted` | Posted to Blesta (transaction link) | duplicate posting |
| `failed` | Evidence mismatch, review required | **raw evidence display** |
| `expired` | Expired, not posted | treating as failed |
| `manual_review` | Duplicate or ambiguous evidence | force paid |
| `cancelled` | Cancelled, not posted | reuse as active |

**Only `posted` may show success/green styling and the transaction link** (UX-DR20, architecture.md:424). For `failed`,
the matrix forbids "raw evidence display" to non-diagnostic viewers — which is exactly why the evidence detail lives in
the permission-gated diagnostics box, not the always-visible summary.

### Field → source map (AC1)

| Detail field | Source | Render |
|---|---|---|
| Client | `voucher->client_id` → `client_code` (via `getClientCodes`) | link → `clients/view/{client_id}/` |
| Invoice mapping | `getByVoucherIds([id],company)[id]` = `[{invoice_id, amount}, …]` | link(s) → `clients/editinvoice/{client_id}/{invoice_id}/` |
| Registration Number | `voucher->registration_number` | `font-monospace` |
| Consumer Number | `voucher->consumer_number` | `font-monospace` |
| KuickPay Reference | `voucher->kuickpay_reference` | `font-monospace`; "—" when null |
| Amount | `voucher->amount` + `voucher->currency` | decimal string (no float) |
| Dates | `date_created, date_due, date_expires, date_last_checked, date_paid, date_posted` | `Date->cast`; "—" when null |
| Current status | `voucher->status` | presenter badge (success only for `posted`) |
| Parsed response summary | status label + `errorClassLabelKey(error_class)` + last inquiry | mapped labels only (no raw codes) |
| Posting state | `status` + `blesta_transaction_id` | matrix admin label; transaction link only when `posted` |
| Admin notes | `voucher->admin_notes` | `nl2br($this->Html->safe(...))`, read-only |
| Diagnostics (gated) | `allowedDiagnosticFields(json_decode(diagnostic_summary))` + `events` | labelled rows (`error_class`→`errorClassLabelKey`, each `validation_errors` token→`validationReasonLabelKey`) + audit timeline; each `payload` as one escaped pretty-JSON blob in the contained block |

### Technical requirements / guardrails

- **Company scoping (AC4):** use `getForCompany($id, $company_id)` and `getByVoucher($id, $company_id)`; never the
  unscoped `get($id)`; never read `company_id` from request. A non-matching/missing id → safe not-found redirect, never a
  rendered foreign voucher.
- **Read-only / idempotent (AC5):** GET only; no writes/SOAP/posting/state change; no notes form / Check Now / cancel /
  Force Paid on this page (architecture.md:377, 659-660).
- **Closed allowlists (AC6):** status, error_class, event_name, **validation_reason**, diagnostic keys all map through
  the presenter with safe fallbacks; never concatenate a DB value into a language key.
- **No leakage (AC7/NFR8/UX-DR28):** for `diagnostic_summary`, render only allowlisted decoded keys (never blind-`foreach`
  the JSON); for the audit `payload`, render one escaped pretty-JSON blob (no per-key echo, no per-event label mapping);
  `$this->Html->safe()` on every dynamic value; no raw SOAP, op names, credentials, stack traces; amounts as strings.
- **Base controller permission is route-enforced (carry-forward from 4.1):** `admin_vouchers` access (`index`/`detail`)
  is gated at the framework/route level via the existing `admin_vouchers '*'` permission — consistent with 4.1's
  deferred finding (no explicit `authorized()` call in the controller for the page itself). Only the new `diagnostics`
  **section** adds an in-action `authorized()` check. Do not add a controller-level gate for `detail()` itself.
- **Audit timeline is capped at `$limit = 100` (Task 2):** vouchers with >100 audit events silently show only the 100
  most recent. Acceptable for MVP; disclose this truncation boundary in the completion notes (no pagination this story).
- **Contained diagnostics (AC3/UX-DR14/24):** scrollable, focusable region with an accessible label; long content never
  breaks the admin layout.
- **PHP 8.2 target**; plugin files are legacy global classes (no `declare(strict_types=1)`); use Blesta `Loader`, `Record`,
  `Language`, `Widget`/`Form`/`Html`/`Date` helpers only. Match each file's existing style.

### Library / framework requirements

- **Bootstrap 5.3.8** admin paradigm theme — `font-monospace` (NOT `text-monospace`, a dead no-op here), `bi bi-*`
  icons, Blesta `Widget`/`Html`/`Date` helpers, Blesta badge classes. No new CSS/JS assets, no new JS framework.
- `.pdt` views only (no Twig/Blade); thin views (render assigned variables; no SQL/business logic).

### Testing requirements

- No root `../tests` and no live DB/web stack in this checkout. Put all testable logic (error-class/event/diagnostic
  allowlists) in the **pure presenter** and unit-test it — controllers/models that hit `Record`, and the `authorized()`
  gate + live view render, cannot be unit-tested here (verify by `php -l` + review against the confirmed APIs).
- Runner: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
  (never `-c build/phpunit.xml`). The presenter is already wired in `tests/bootstrap.php:6` — no bootstrap change needed
  unless you add a new test class file (then add its `require_once`).
- Report exactly what ran and what was unavailable per NFR12; the existing `KuickPaySecretLeakageTest` baseline failure
  is pre-existing (4-1 Debug Log) — confirm no NEW failures.

### Project Structure Notes

- **Aligned**: all changes land under the plugin per the additional-requirements boundary (`epics.md:118-124`):
  `controllers/admin_vouchers.php` (+`detail()`), `views/default/admin_vouchers_detail.pdt` (new),
  `language/en_us/admin_vouchers.php` (+detail keys), `lib/KuickPayVoucherListPresenter.php` (+allowlists),
  `models/kuickpay_vouchers.php` (+`getForCompany`), `models/kuickpay_audit_events.php` (+`getByVoucher`),
  `kuickpay_reconcile_plugin.php` (+permission), `language/en_us/kuickpay_reconcile_plugin.php` (+permission key),
  `config.json` (version), `tests/KuickPayVoucherListPresenterTest.php` (+cases), `views/default/admin_vouchers.pdt`
  (consumer-number → detail link).
- **Variance (resolved, documented above)**: view file is `admin_vouchers_detail.pdt` (auto-derived) vs the
  architecture's `admin_voucher_detail.pdt`.
- **No schema change**: read-only over existing tables + a write-only-table read method; the version bump exists solely
  to re-sync nav/permissions (add the diagnostics permission).

### References

- `epics.md` Story 4.2 ACs (lines 750-770); FR25 (line 73), FR27 (line 77); NFR8 (line 101), NFR14 (line 113);
  UX-DR13/14/19/20/21/23/24/25/26/28 (lines 176-206); additional requirements / ACL & file-location (lines 117-148).
- `architecture.md` separate ACL permissions incl. "view diagnostics" (lines 361-367); "GET is read-only" (line 377);
  admin workbench detail + redacted diagnostics (lines 426-437); normalized parser fields (lines 555-565); allowed error
  classes (lines 569-577); UI display-state matrix (lines 595-606); audit patterns + event names (lines 610-634);
  anti-patterns (lines 648-661); ownership boundary (lines 663-673); file tree incl. `admin_voucher_detail.pdt`
  (line 735) and `admin_vouchers.php` "search, list, and detail" (line 792).
- Code: `plugins/kuickpay_reconcile/controllers/admin_vouchers.php:14-41` (preAction/wiring), `:49-131` (index pattern);
  `models/kuickpay_vouchers.php:106-112` (unscoped `get` — do not use), `:121-128` (scoped getter shape),
  `:290-293` (`getStatuses`), `:461-483` (`getClientCodes`); `models/kuickpay_voucher_invoices.php:76-111`
  (`getByVoucherIds`); `models/kuickpay_audit_events.php` (write-only — add read); `lib/KuickPayVoucherListPresenter.php`
  (seam to extend); `lib/KuickPayAuditService.php:30-46` (payload shape); `kuickpay_reconcile_plugin.php:218-234`
  (permissions), `:337-351` (audit table), `:100-136` (upgrade); `views/default/admin_vouchers.pdt:91` (consumer cell);
  `controllers/admin_main.php:34` (mutating pattern to AVOID).
- Blesta ACL: `app/models/permissions.php:240-264` (`authorized` logic), `:278-294` (ACO registration);
  `app/controllers/admin_myinfo.php:494-497` (plugin ACO = `plugin.controller`); `plugins/support_manager/support_manager_plugin.php:1901-1947`
  (multi-permission pattern). View resolution: `plugins/client_documents/controllers/admin_main.php` + `admin_main_add.pdt`.
- `project-context.md` (PHP 8.2, Blesta loader/Record/Language rules, external PHPUnit 8.5 runner, no root `../tests`).

### Previous Story Intelligence (Epics 1–4.1 — apply these or repeat past review cycles)

- **Company-scope every query** (3-3 hardening, 4-1 AC4, deferred-work:52): the single most repeated finding. The
  detail fetch + audit fetch MUST be company-scoped; the unscoped `get()` is a trap.
- **Status/label as closed allowlist** (2-5, 4-1 AC6): never key a language lookup on a DB value; map through the
  presenter with a safe default — now extended to error_class, event names, and diagnostic keys.
- **Success/transaction-link only for `posted`** (4-1 AC6, UX-DR20): same rule on the detail page.
- **`Record->fetch()` returns `stdClass`** — use `->field`, not `['field']` (cumulative Blesta footgun). `fetchAll()`
  rows are stdClass too.
- **`Html->safe()` on every dynamic value; `_()` calls need the return flag** (4-1 review finding at
  `admin_vouchers.pdt:106,125`) — applies to the new view.
- **Plugin upgrade re-sync** (4-1): keep ALL nav + permission entries; bump version to trigger the sync.
- **ACL forward constraint set by 4.1**: "diagnostics (4.2) MUST live behind its own separate permission and MUST NOT be
  added under the blanket `admin_vouchers.*` grant" — this story implements exactly that.
- **Verification honesty** (all retros, NFR12): disclose PHP version used and exactly which suites ran; the
  `KuickPaySecretLeakageTest` baseline failure is pre-existing.
- **No over-scoping**: admin-notes editing, Check Now, cancel, Manual Review marking are **Story 4.3** — this page is
  read-only display only. Run summaries / Manual Review queue are 4.4.

### Git Intelligence Summary

Epic 3 (reconcile + posting + safety contracts) and Story 4.1 (voucher list/search/filter) are `done`; recent commits
are 4.1 review-fix follow-ups (voucher filter navigation, sub-paisa filter rejection). The `admin_vouchers`
controller/view/presenter/model-query layer and the durable voucher/audit schema are stable and were built to be
extended here. This story is the natural continuation: add `detail()` + `admin_vouchers_detail.pdt` + the gated
diagnostics permission, reusing 4.1's company-scoped batched lookups and the pure-seam presenter.

### Project Context Reference

Follow `_bmad-output/project-context.md` verbatim — especially: PHP 8.2 only; Blesta loader/Record/Language/Widget APIs
(no ad-hoc SQL beyond allowlisted `Record` queries); keep extension code inside `plugins/kuickpay_reconcile/`;
language-file-driven strings; do not edit ionCube/minified/vendored files; commit style `<type>(<scope>): <summary>`;
verify with `php -l` + component PHPUnit 8.5, never claim root `../tests`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
