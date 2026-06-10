---
baseline_commit: 45926c5e41114ad147968f6ed3ffe43226be40bb
---

# Story 3.2: Normalize KuickPay Evidence with Fixtures

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer and finance operator,
I want raw KuickPay responses normalized into a stable, fixture-backed evidence contract,
so that every payment decision reads typed, validated fields — never opaque SOAP strings — and unknown or unsafe evidence fails closed to retry or Manual Review, never paid.

> **Scope note (delivery order matters).** The `InsertVoucher` creation-response cases of this parser are a hard prerequisite of **Story 2.3** (voucher issuance) and depend on **Story 0.1** fixtures (gate APPROVED 2026-06-09). Build the **evidence object + parser core + InsertVoucher (creation) cases first** (Tasks 1–3) to unblock 2.3; the `BillPaymentInquiry` (Task 4) and `BillPaymentBulkInquiry` (Task 5) cases may follow with the rest of Epic 3. The story is `done` only when all three operations are covered. [Source: epics.md:600-617; sprint-status.yaml:48-62]

## Acceptance Criteria

Derived from Epic 3 Story 3.2 (epics.md:600-617), FR16, FR17, FR28, the architecture Parser & Evidence Contract (architecture.md:549-579), and the Phase 0 fixture mapping + fail-closed preconditions (docs/kuickpay/testing-fixtures.md). Each is independently testable.

1. **Normalized evidence contract — every required field present.** A parser converts every raw KuickPay response (voucher creation, single inquiry, bulk inquiry) into a `KuickPayEvidence` value object exposing exactly these normalized fields: `status`, `error_class`, `reference`, `consumer_number`, `registration_number`, `amount`, `currency`, `paid_at`, `raw_status`, `redacted_trace_id`, `evidence_hash`, `validation_errors`. Missing required result fields yield `error_class = malformed_response` (status `manual_review`), **not** partial success. [Source: epics.md:610-612; architecture.md:553-567]

2. **Single SOAP-string boundary.** The parser is the only place a raw KuickPay `*Result` string / bulk XML dataset is interpreted for business meaning. It consumes the Story 3.1 transport outcome (`raw_result`) and emits typed evidence; no controller, view, cron, reconciliation, or posting code branches on raw SOAP/XML. Product code reads `KuickPayEvidence` only. [Source: architecture.md:397,400,408,551,653-654; epics.md:132]

3. **`status` uses canonical Voucher states; the parser never emits `posted`/`cancelled`.** Parser `status` is one of `pending`, `retry`, `confirmed_unposted`, `failed`, `expired`, `manual_review` only. `posted` is reachable solely through `KuickPayPostingService` (Story 3.5); `cancelled` is an admin action. `confirmed_unposted` means **validated evidence only — NOT paid**. [Source: architecture.md:535-536,581-583,601; testing-fixtures.md:24]

4. **`error_class` is exactly one of the allowed parser classes (or null).** Allowed: `timeout`, `transport_error`, `credential_error`, `malformed_response`, `unknown_status`, `amount_mismatch`, `duplicate_reference`, `unmatched_reference`. `timeout`/`transport_error` are passed through from the 3.1 transport outcome; the other six are the parser's to assign. `duplicate_reference` and `unmatched_reference` are review exceptions that must never fall through to posting. [Source: architecture.md:569-579]

5. **InsertVoucher creation mapping (fixture-backed).** Given a transport-successful `InsertVoucher` outcome, the parser maps the raw `InsertVoucherResult` fixed-position string per the contract table in Dev Notes: `00`+voucher id → `pending` (created, unpaid); `00` with missing voucher id → `malformed_response`/`manual_review`; `94` → `duplicate_reference`/`manual_review`; `05` → `credential_error`/`failed`; any other or non-2-char status → `unknown_status` or `malformed_response`/`manual_review`. A transport `timeout`/`transport_error` on InsertVoucher → `manual_review` (creation outcome unknown; **never auto-retry InsertVoucher**). Creation never yields `confirmed_unposted`. [Source: testing-fixtures.md:18-22,51; docs/kuickpay/fixtures/insert-voucher/*; epics.md:136]

6. **BillPaymentInquiry mapping (fixture-backed, fail-closed paid classification).** Given a transport-successful single inquiry, the parser maps the comma-separated `BillPaymentInquiryResult` per the Dev Notes table: `01` → `pending`; `02` → `expired`; `00` → `confirmed_unposted` **only if all paid-classification preconditions pass** (amount equality vs expected in minor units/decimal strings — never floats; `currency == PKR`; exact identity equality vs expected — single inquiry has **no** Consumer Number field, so identity is matched on the Registration Number (field 1), and `consumer_number` is null), else `amount_mismatch`/`unmatched_reference`/`manual_review`; `99` or any other code, or a result with too few fields → `unknown_status`/`malformed_response`/`manual_review`. A transport `timeout`/`transport_error` on inquiry → `retry`. Without the expected-amount/currency/reference context, a `00` row **cannot** reach `confirmed_unposted` and fails closed to `manual_review`. [Source: testing-fixtures.md:23-27,32-45; docs/kuickpay/fixtures/bill-payment-inquiry/*; architecture.md:593]

7. **BillPaymentBulkInquiry mapping (structure-first, exact-match, bounded XML).** Given a transport-successful bulk outcome, the parser safely parses the inner dataset with bounded length, DOCTYPE rejection, and no external-entity/entity-expansion, **validates structure before extracting any row**, matches each row to the caller-supplied expected Consumer Numbers by **exact string equality only** (never suffix/substring), and applies the same paid preconditions as single inquiry. Matched+valid → `confirmed_unposted`; unmatched → `unmatched_reference`/`manual_review`; malformed/incomplete inner dataset → `malformed_response`/`manual_review` with **zero** matched rows. [Source: testing-fixtures.md:28-30,38-39; epics.md:135; architecture.md:412; docs/kuickpay/fixtures/bill-payment-bulk-inquiry/*]

8. **Only fixture-backed confirmed-payment behavior can confirm; unknown ≠ paid.** Parser unit tests run against the Phase 0 fixtures (relocated to the canonical test tree) plus the Story-3.2 hardening fixtures. Every enumerated case asserts its expected `status`/`error_class` from the mapping table. No raw status code outside the confirmed allow-list can produce `confirmed_unposted`; every unknown/malformed/duplicate/unmatched/mismatched/timeout case maps to `retry` or `manual_review`. [Source: epics.md:614-617; FR17; testing-fixtures.md:43-45]

9. **No secret/PII leakage through evidence or diagnostics.** `KuickPayEvidence` carries only normalized business fields + a redacted trace id + a non-PII evidence hash; it never carries credentials, customer PII (`Name`/`Mobile`/`Email`/`Branch`), a raw SOAP envelope, a raw fault string, or a stack trace. Any diagnostic passes through `KuickPayRedactor`. `evidence_hash` is derived from non-PII canonical evidence and is deterministic for identical evidence (so audit/dedup correlate). [Source: architecture.md:563-565,634,656; NFR8; FR3]

10. **Fixtures relocated to the canonical test tree and web-protected.** The Phase 0 fixtures move into `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,malformed,ambiguous,redaction}/` per the testing-fixtures.md Category Mapping, and that tree is blocked from public web access (root `.htaccess` does **not** cover `plugins/`, and `.xml` is not in the extension deny-list). Provisional/approval provenance is preserved. [Source: testing-fixtures.md:59-76; 0-1 Dev Notes "Handoff to Story 3.2"; architecture.md:739-752; ./.htaccess:34]

11. **No regressions; verification stated honestly.** No change to gateway payment placeholders (`buildProcess`/`validate`/`success`), `encryptableFields()`, `editSettings()`, `KuickPaySoapClient`, or `KuickPayRedactor` public behavior. `php -l` passes on every touched PHP file. Do not claim root PHPUnit (`../tests`) coverage unless that sibling suite is present; state exactly what ran (and that `php`/`ext-soap` may be absent in this checkout — see Risks). [Source: kuickpay.php:94 (editSettings), 283 (encryptableFields), 473 (getSoapClient), 534/567/590 (buildProcess/validate/success); 3-1 regression guards; NFR12]

## Tasks / Subtasks

> **Delivery order:** Tasks 1–3 (evidence object + parser core + InsertVoucher creation cases) are the Story-2.3 unblock slice — build and verify them first. Tasks 4–5 (inquiry, bulk) follow. Task 6 (relocation + web-block), Task 7 (tests), Task 8 (verification) finalize. [Source: epics.md:606; sprint-status.yaml:51,61-62]

- [x] **Task 1 — Create the evidence value object `lib/KuickPayEvidence.php` (AC: 1, 3, 9)**
  - [x] Create `components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php`. Global namespace (no `namespace`), plain class `KuickPayEvidence`, matching `KuickPaySoapClient`/`KuickPayRedactor` style. **Typing:** PHP 8.2 parameter/return type hints (the lib-file convention 3.1 established — `?string`, `string`, `array`, `bool`); do **not** add `declare(strict_types=1)`; do **not** retrofit types onto legacy `kuickpay.php`.
  - [x] Hold exactly the 12 normalized fields (AC1): `status`, `error_class`, `reference`, `consumer_number`, `registration_number`, `amount`, `currency`, `paid_at`, `raw_status`, `redacted_trace_id`, `evidence_hash`, `validation_errors`. Make it immutable (constructor-assigned, read-only getters or public readonly-style accessors). Provide `toArray(): array` and a small helper such as `isConfirmedUnposted(): bool` (`status === 'confirmed_unposted'`). Add `operation` as an internal field if useful, but it is not one of the 12 contract fields. **`toArray()` returns exactly the 12 contract keys in contract-table order — `status`, `error_class`, `reference`, `consumer_number`, `registration_number`, `amount`, `currency`, `paid_at`, `raw_status`, `redacted_trace_id`, `evidence_hash`, `validation_errors` — using these exact snake_case names; the internal `operation` field must NOT appear in `toArray()`** (downstream 2.3/3.3/3.5 rely on this shape).
  - [x] **`amount` is always a canonical decimal string or null — never a PHP float/int** (NFR13). `currency` is an uppercased code (e.g. `PKR`) or null. `validation_errors` is a `string[]` of machine-readable reasons (e.g. `amount_mismatch`, `missing_voucher_id`, `malformed_dataset`). `raw_status` is the provider code string (`00`,`94`,…) — a status code, not a secret.
  - [x] **No secret/PII members** (AC9): no raw envelope, no raw fault, no `Name`/`Mobile`/`Email`/`Branch`, no credentials. The object IS the redacted, normalized contract product code consumes.

- [x] **Task 2 — Create the parser core `lib/KuickPayResponseParser.php` (AC: 1, 2, 4, 9)**
  - [x] Create `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`. Global namespace, plain class `KuickPayResponseParser`, CamelCase filename per architecture (architecture.md:545,685) — matches the 3.1 lib-file naming variance; do not rename to snake_case.
  - [x] Constructor `__construct(KuickPayRedactor $redactor = null)`; default `new KuickPayRedactor()`. Reuse the redactor for `traceId()` and any diagnostic redaction. Both libs are `Loader::load`ed together (see Task 3 of 3.1's `getSoapClient()` precedent and Task 6 below).
  - [x] Public entry `parse(array $transportOutcome, array $context = []): KuickPayEvidence` — handles the single-row operations only (`InsertVoucher`, `BillPaymentInquiry`), dispatching on `$transportOutcome['operation']`. **`parse()` ALWAYS returns exactly one `KuickPayEvidence`; never widen its declared return type to `array`/`mixed`.** Bulk is handled exclusively by `parseBulk(array $transportOutcome, array $context = []): array` (one `KuickPayEvidence` per dataset row — see Task 5); do **not** route bulk through `parse()`. **Dispatch rules:** `operation === 'BillPaymentBulkInquiry'` passed to `parse()` → throw `InvalidArgumentException` ("use parseBulk()"); `operation` missing or none of the three known operations → return a single `manual_review`/`unknown_status` evidence (do **not** throw, do **not** return null). [return contract locked — see Open Question #2]
  - [x] **Transport short-circuit (do this before any body parsing).** If `$transportOutcome['ok'] === false`: build evidence carrying `error_class` from `$transportOutcome['error_class']` (`timeout`/`transport_error`) and `redacted_trace_id` from the outcome, with status decided by operation — **InsertVoucher → `manual_review`** (creation outcome unknown, never auto-retry), **inquiry/bulk → `retry`** (safe to re-inquire; 3.1 already bounded-retried transport). `raw_status` = null. [Source: testing-fixtures.md:22; epics.md:136; architecture.md:414]
  - [x] **Empty/absent body on a transport-success.** If `$transportOutcome['ok'] === true` but `raw_result` is null or empty (e.g. a SOAP `<Fault>` body carries no `*Result` element, so 3.1's `extractRawResult()` returned null), classify as `malformed_response`/`manual_review` for **every** operation — **never `retry`** (`retry` is reserved for `ok === false` inquiry/bulk). Branch on the null/empty body explicitly; do not let it fall into `substr()`/`explode()`/XML parse by accident. [Source: 3-1 fault-with-body handoff; KuickPaySoapClient.php:361-401]
  - [x] `redacted_trace_id`: carry through `$transportOutcome['redacted_trace_id']` when present; else mint via `$redactor->traceId()`.
  - [x] `evidence_hash`: deterministic and **identical for identical evidence** so audit/dedup correlate (AC9). Serialize a canonical string with **`implode('|', [...])` in this exact field order** — `operation`, `raw_status`, `reference`, `consumer_number`, `registration_number`, `amount`, `currency` (uppercased), `paid_at` — coercing each null to the empty string `''` (not the literal `'null'`); then `substr(hash('sha256', $canonical), 0, 24)` (24 hex / 96 bits). Use the **already-normalized** `amount`/`currency`/`paid_at` (post-normalization, so `1000.0` and `1000.00` hash identically). Do **not** include the trace id, wall-clock time, or any credential/PII. [Source: architecture.md:564]
  - [x] Centralize the allowed-`error_class` set and the canonical-`status` set as class constants; assert outputs are members (fail closed to `manual_review`/`unknown_status` on any unexpected branch). Never emit `posted` or `cancelled` (AC3).

- [x] **Task 3 — InsertVoucher creation parsing (AC: 5, 8) — Story-2.3 unblock slice**
  - [x] Parse the fixed-position `InsertVoucherResult` per the contract: `raw_status = substr(trim, 0, 2)`; voucher id = the remainder after the status (the WHMCS shape is status(2) + space + id; the documented offset is `substr(result, 3, 14)`). Read **defensively** — trim, tolerate the space delimiter, and treat a missing/empty id as malformed. Do not hard-fail on length; classify. [Source: whmcs-live-implementation-evidence.md:26; testing-fixtures.md:51; fixtures insert-voucher/success.xml=`00 VOUCHERID00001`, malformed.xml=`00`]
  - [x] Mapping: `00`+non-empty id → `pending`, error_class none, `reference` = parsed voucher id, `registration_number` = `context['expected_registration_number']` or null (do **not** set `registration_number` to the voucher id — the Registration Number is the request-side identifier and is not echoed in the creation response), `consumer_number` = null; `00`+empty id → `manual_review`/`malformed_response` (`validation_errors=['missing_voucher_id']`); `94` → `manual_review`/`duplicate_reference`; `05` → `failed`/`credential_error`; non-2-char or unrecognized status → `manual_review` with `malformed_response` (non-numeric/short) or `unknown_status` (recognized-shape unknown code). Creation **never** sets `amount`/`paid_at` as confirmation and never returns `confirmed_unposted`. [Source: testing-fixtures.md:18-22]
  - [x] Code comment: InsertVoucher confirmation is creation-only (voucher exists, unpaid); paid truth comes only from inquiry/bulk (3.3/3.7) then posting (3.5).

- [x] **Task 4 — BillPaymentInquiry parsing with fail-closed paid classification (AC: 6, 8)** *(may follow the creation slice)*
  - [x] Split `BillPaymentInquiryResult` on `,`. Field map (indices 0–5 WHMCS-confirmed; 6–7 fixture-derived/provisional — read defensively): `[0]` status, `[1]` registration/reference, `[2]` paid date (`Ymd`), `[3]` paid amount, `[4]` txn reference, `[5]` reference, `[6]` currency, `[7]` institution id. **Evidence-field mapping (single inquiry):** `registration_number` = `[1]`, `reference` = `[5]` (KuickPay payment reference), `amount` = normalized `[3]`, `currency` = uppercased `[6]`, `paid_at` = normalized `[2]`; `consumer_number` = **null** — the single-inquiry result has **no Consumer Number field** (verified against `paid-exact.xml`). Fields `[4]` (txn reference) and `[7]` (institution id) are **not** mapped to the 12-field contract — discard them; do not invent a field. Too few fields (e.g. `< 6`) → `manual_review`/`malformed_response`. [Source: whmcs-live-implementation-evidence.md:28; testing-fixtures.md:52; deferred-work.md:13 (empty/short result)]
  - [x] Mapping: `01` → `pending`; `02` → `expired` (record reason; if a real capture shows ambiguous expired semantics, prefer `manual_review`); `00` → run **PAID-CLASSIFICATION PRECONDITIONS** (next subtask); `99` / any unenumerated code → `manual_review`/`unknown_status`. [Source: testing-fixtures.md:23-27,43-45]
  - [x] **Paid-classification preconditions** (status `00` candidate). ALL must hold or fail closed to `manual_review`: (a) **amount equality** — normalized paid amount (field 3) equals normalized `context['expected_amount']` per the amount-normalization rule below, **never floats** (mismatch → `amount_mismatch`); (b) **currency** — uppercased field 6 == `PKR` / `context['expected_currency']` (empty/non-PKR → `manual_review` with `error_class = null` and `validation_errors[] = 'currency_mismatch'` — there is **no** currency error class in the allowed set and the status is *known*, so do **not** use `unknown_status` here); (c) **exact identity equality** — for single inquiry the comparable identity field is `registration_number` (field 1); when `context` supplies `expected_registration_number` (and/or `expected_consumer_number`, which for single inquiry is also matched against field 1 since the result carries no Consumer Number), require **exact string equality** (mismatch → `unmatched_reference`); (d) **no expected context provided** → cannot confirm → `manual_review` (the parser must not invent confirmation). All pass → `confirmed_unposted`, `paid_at` = normalized field 2, `amount`/`currency`/`reference`/`registration_number` populated. [Source: testing-fixtures.md:32-45; architecture.md:593]
  - [x] **Amount-normalization rule (float-free; reused by bulk Task 5).** To compare/store amounts: (1) cast nothing to float; (2) strip thousands separators (`,`) and surrounding whitespace; (3) split on `.` into integer and fractional parts; (4) right-pad or truncate the fractional part to exactly 2 digits; (5) the canonical form is `"<int>.<2-digit frac>"` — so `1000`, `1000.0`, and `1,000.00` all normalize to `1000.00`; (6) compare the canonical strings for equality (or the equivalent integer minor units derived by string concatenation). Store the canonical decimal string on `amount`. **Never** use `floatval`/`(float)`/`sprintf('%.2f', $float)`/`round()`/`==` on floats — they reintroduce the binary-float error this rule exists to avoid. [Source: architecture.md:593; NFR13]
  - [x] Normalize `paid_at` best-effort from `Ymd` to a canonical date string (e.g. `Y-m-d`); on unparseable date keep null + add a `validation_errors` note, but do not solely reject otherwise-valid paid evidence on date format.

- [x] **Task 5 — BillPaymentBulkInquiry parsing (structure-first, exact-match, bounded XML) (AC: 7, 8)** *(may follow the creation slice)*
  - [x] The bulk `*Result` text content is an XML dataset string (`<NewDataSet><Table>…`); the 3.1 client already extracted it into `raw_result` (the fixture wraps it in CDATA — the extracted text is the inner XML). Safe-parse with the **same fail-closed guards** used in `KuickPayRedactor::redactEnvelope()` / `KuickPaySoapClient::extractRawResult()`: reject `<!DOCTYPE` (case-insensitive) before parsing, bound input length (reuse `KuickPayRedactor::MAX_ENVELOPE_BYTES`), parse under `libxml_use_internal_errors(true)` with `LIBXML_NONET`, and **never** `LIBXML_NOENT`/`LIBXML_DTDLOAD`. Do **not** call deprecated `libxml_disable_entity_loader()` (no-op on PHP 8.2). [Source: KuickPayRedactor.php:99-107; KuickPaySoapClient.php:376-391; architecture.md:412; epics.md:135]
  - [x] **Validate structure BEFORE extracting any row.** If the inner dataset fails to parse or is incomplete (see `malformed-xml.xml`: a known-good Consumer Number precedes a truncation) → return a single `manual_review`/`malformed_response` evidence with **zero** matched rows. A parser that scans for Consumer Numbers before validating structure fails open — do not do that. [Source: testing-fixtures.md:39]
  - [x] For each `<Table>` row read `Consumer_Number`, `Registration_Number`, `Transaction_Date`, `Paid_Amount`, `Transaction_Reference`, `Currency`. **Evidence-field mapping (bulk row):** `consumer_number` = `Consumer_Number`, `registration_number` = `Registration_Number`, `reference` = `Transaction_Reference` (the only reference-like field in a bulk row), `amount` = normalized `Paid_Amount`, `currency` = uppercased `Currency`, `paid_at` = normalized `Transaction_Date`. Match `Consumer_Number` against `context['expected_consumer_numbers']` (array) by **exact string equality only — never suffix/substring/`contains`** (current shape `institution_id + 4-digit prefix + invoice_id` has no delimiter; loose matching misclassifies). Apply the **same amount-normalization rule as Task 4**. Matched → apply paid preconditions → `confirmed_unposted` or `manual_review`/`amount_mismatch`; unmatched — **including when `expected_consumer_numbers` is empty/absent, in which case every row is unmatched** → `manual_review`/`unmatched_reference`. [Source: testing-fixtures.md:28-30,38; epics.md:700]
  - [x] **Bound the payload**: the byte bound is `KuickPayRedactor::MAX_ENVELOPE_BYTES` (reject oversize before parsing); additionally cap rows at a concrete `MAX_BULK_ROWS` (e.g. 10000) — exceeding it → a single `malformed_response`/`manual_review` evidence (NFR7). Do not build unbounded structures. Return one `KuickPayEvidence` per row so `KuickPayReconcileService` (3.7) can record matched/unmatched run items.
  - [x] **`parseBulk()` return shape (do not collide with the empty-dataset rule below).** On transport `ok === false`, return a **single-element list** `[evidence]` with `status='retry'` and the passed-through transport `error_class` — never an empty list. On structural/parse failure (or the row cap), return a **single-element** `manual_review`/`malformed_response` list with **zero matched rows**. A well-formed empty dataset returns an **empty list** (see below).
  - [x] **libxml hygiene:** capture the prior `libxml_use_internal_errors()` value, set it `true` for the parse, then `libxml_clear_errors()` and restore the prior value — do not leave error suppression enabled for downstream code.
  - [x] **Empty-but-well-formed dataset is NOT malformed.** A valid `<NewDataSet/>` (or zero `<Table>` rows) means "no payments for that date" → return an empty list, not a `malformed_response`. Reserve `malformed_response` for parse failure / structural truncation (the `malformed-xml.xml` case). Keep this distinction explicit so a quiet day does not register as a provider error.

- [x] **Task 6 — Relocate Phase 0 fixtures to the canonical test tree and web-protect it (AC: 10)**
  - [x] Copy the Phase 0 fixtures from `docs/kuickpay/fixtures/` into `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,malformed,ambiguous,redaction}/` using the exact target names in the testing-fixtures.md **Story 3.2 Category Mapping** (testing-fixtures.md:59-76), e.g. `insert-voucher/success.xml`→`valid/insert-voucher-success.xml`, `insert-voucher/malformed.xml`→`malformed/insert-voucher-malformed.xml`, `insert-voucher/duplicate.xml`→`ambiguous/insert-voucher-duplicate.xml`, `bill-payment-bulk-inquiry/malformed-xml.xml`→`malformed/bill-payment-bulk-malformed-xml.xml`, `redaction/credentials.xml`→`redaction/credentials.xml`, etc. **Copy, do not move** — the `docs/kuickpay/fixtures/` originals remain the Phase 0 provenance record (testing-fixtures.md indexes them) and 3.1's tests reference them. `timeout.md` stays a `.md` descriptor (map to `ambiguous/insert-voucher-timeout.md`). [Source: testing-fixtures.md:59-76; 0-1 Dev Notes "Handoff to Story 3.2"]
  - [x] **Add web protection** to the new plugin test tree: the repo root `.htaccess` blocks `docs`/`_bmad-output` but **not** `plugins/`, and `.xml` is not in the extension deny-list, so these fixtures would be publicly fetchable. First ensure the parent path exists (create `plugins/kuickpay_reconcile/tests/` and the four category subdirs `fixtures/kuickpay/{valid,malformed,ambiguous,redaction}/`), then add `plugins/kuickpay_reconcile/tests/.htaccess` with the exact dual-directive content below. The **root `.htaccess:24-30` already uses this Apache 2.2/2.4-portable pattern** — cite that as the precedent; note `plugins/phpids/lib/IDS/.htaccess` is weaker (bare `deny from all`, Apache-2.2 only) so do not copy it verbatim. Verify no fixture path is web-reachable. [Source: ./.htaccess:24-30,34; 0-1 Dev Notes security rationale; PRD secret-safety]

    ```apache
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
    ```
  - [x] These fixtures are still `provisional`/`PENDING_HUMAN_APPROVAL` (gate approved on WHMCS-derived evidence, fixtures are `synthetic_from_observed_format`). Preserve that status; do not relabel them `verified`. The relocated copies are for parser development/testing, consistent with 3.1. [Source: testing-fixtures.md:78-97]

- [x] **Task 7 — Add Story-3.2 hardening fixtures and parser tests (AC: 8) and wire the suite**
  - [x] Add the **deferred Story-3.2 hardening fixtures** (deferred-work.md:13) under the canonical tree, each as a well-formed SOAP envelope (malformed semantics live inside the `*Result`, per the Phase 0 convention): a **non-2-char InsertVoucher status**; an **inquiry amount with differing precision/trailing zeros** (e.g. `1000.0` vs expected `1000.00` — proves minor-unit/decimal comparison, must classify as paid-equal, not mismatch); an **empty / short (`<6` field) inquiry result**; a **non-PKR / empty currency** on an otherwise-paid row (→ `manual_review`); a **multi-row bulk dataset** mixing matched + unmatched + a duplicate Consumer Number; an **overpayment** and a **late/partial** row; and a **Consumer-Number suffix/substring pair** (one value is a suffix of another) to prove exact-match discrimination. Add provenance rows for them in `docs/kuickpay/testing-fixtures.md`. [Source: deferred-work.md:13]
  - [x] Add component-local parser tests in the existing gateway test tree `components/gateways/nonmerchant/kuickpay/tests/`: `KuickPayResponseParserTest.php` and `KuickPayEvidenceTest.php` (CamelCase, matching lib files and the 3.1 test naming). Extend `tests/bootstrap.php` to `require_once` the two new lib files (`KuickPayEvidence.php`, `KuickPayResponseParser.php`). Resolve the fixture directory via a path constant pointing at the canonical plugin tree (`__DIR__ . '/../../../../../plugins/kuickpay_reconcile/tests/fixtures/kuickpay/'`) — see Project Structure Notes for why fixtures live plugin-side while gateway-lib tests live gateway-side. [Source: tests/bootstrap.php; build/phpunit.xml; architecture.md:826-831]
  - [x] Required cases — assert `status` + `error_class` for **every** mapping-table row across all three operations, plus: transport `ok=false` timeout/transport_error → `manual_review` (insert) / `retry` (inquiry/bulk); `00`-without-expected-context → `manual_review` (fail closed, no confirmation); amount mismatch / trailing-zero equality; non-PKR currency → manual_review; exact vs suffix Consumer-Number match; bulk structure-first malformed → zero matched rows; DOCTYPE/oversize bulk dataset rejected; `evidence_hash` deterministic for identical evidence and differs on differing evidence; no PII/credential/raw-envelope field on any evidence object. No live KuickPay call (NFR11) — fixtures only. [Source: epics.md:614-617; FR28; testing-fixtures.md:32-45]

- [x] **Task 8 — Verification and regression guard (AC: 11)**
  - [x] Confirm **no change** to `kuickpay.php` payment/settings methods, `KuickPaySoapClient.php`, or `KuickPayRedactor.php` public behavior. The parser is a new consumer of the 3.1 transport outcome; it does not modify the client. (You MAY add the two new `Loader::load`s where a future caller wires the parser, but no caller is required by this story — 2.3/3.3 wire it.)
  - [x] Run `php -l` on `KuickPayEvidence.php`, `KuickPayResponseParser.php`, and the edited `tests/bootstrap.php`. Run the component-local suite (`--bootstrap tests/bootstrap.php tests`, per the 1-4 deferred note about the broken `-c build/phpunit.xml` runner). Validate the new fixture XML well-formedness with `xmllint --noout` (available here) or the `simplexml` fallback. State exactly what ran. **Environment reality:** `php`/`ext-soap` may be absent in this checkout (the 3.1 record is contradictory — Risks say absent, completion says PHP 8.3.31 present). If `php` is unavailable, say so and run `php -l` + PHPUnit ~8.5 under **PHP 8.2** before merge; do not claim a lint/suite that never ran. [Source: deferred-work.md:9,40; project-context testing rules; NFR12]
  - [x] Secret-safety re-scan on the relocated + new fixtures (the two-step Phase 0 scan: forbidden-real-value scan + redaction-confirmation), since they now sit under web-served `plugins/`. Confirm the `.htaccess` denies access. [Source: 0-1 Task 4.2]

## Dev Notes

### Scope boundary (read first)

This story delivers the **evidence-normalization layer only**: a `KuickPayEvidence` value object + a `KuickPayResponseParser` that turns the Story 3.1 transport outcome into typed, validated, fail-closed evidence, backed by relocated + expanded fixtures and parser tests. It explicitly does **not**:
- call SOAP or do transport (that is 3.1, `KuickPaySoapClient`, already done — the parser **consumes its `raw_result`**);
- persist Vouchers, run reconciliation, or post transactions (3.3/3.5/3.7, plugin services);
- compute reference/Consumer numbers or map invoices (Epic 2 / 2.2);
- decide an invoice is paid — `confirmed_unposted` is **validated evidence only**; only `KuickPayPostingService` → `posted` (3.5).

The single most important invariant: **the parser turns provider strings into typed evidence and fails closed; it never marks anything paid, and unknown/ambiguous evidence becomes `retry` or `manual_review`, never `confirmed_unposted` or `posted`.** [Source: architecture.md:551,581-583,653-661; NFR9]

### Architecture compliance (guardrails)

- **One parse home.** Raw SOAP `*Result` strings / bulk XML are interpreted only in `KuickPayResponseParser`. No controller/view/cron/posting/reconcile service branches on raw SOAP/XML — they read `KuickPayEvidence`. The flow is `SOAP client → redactor → parser → normalized result → state machine`; 3.1 owns the first two arrows, this story owns the third. [Source: architecture.md:397,400,408,551,770-772; anti-patterns 653-654]
- **Fail closed everywhere.** Missing required field → `malformed_response`. Unenumerated status → `unknown_status`. Any uncertainty → `manual_review`/`retry`, never paid. `00`/row-presence is necessary-but-not-sufficient for paid. [Source: architecture.md:567,579; testing-fixtures.md:32-45; NFR9]
- **Amounts never float** (NFR13). Normalize and compare as integer minor units or canonical decimal strings. `1000.0`, `1000.00`, `1,000.00` must compare equal to expected `1000.00`; strip thousands separators / normalize precision deliberately. Currency is part of every paid check. [Source: architecture.md:593; deferred-work.md:13 trailing-zero trap]
- **Redaction boundary.** Reuse `KuickPayRedactor` for `traceId()` and any diagnostic text; never place a raw envelope/fault/PII on the evidence object. [Source: architecture.md:656; AC9]
- **Anti-patterns to avoid:** emitting `posted` from the parser; returning `confirmed_unposted` without amount/currency/reference validation; parsing raw XML in a controller/view; float amount comparison; suffix/substring Consumer-Number matching; unbounded/entity-expanding XML parsing; leaking secrets in evidence/diagnostics. [Source: architecture.md:653-661; testing-fixtures.md:38]

### The parser & evidence contract (the API this story defines)

**`KuickPayEvidence`** — immutable value object, the 12 normalized fields (AC1):

| Field | Type | Notes |
|---|---|---|
| `status` | string | one of `pending`,`retry`,`confirmed_unposted`,`failed`,`expired`,`manual_review` (never `posted`/`cancelled`) |
| `error_class` | ?string | null or one of the 8 allowed classes |
| `reference` | ?string | KuickPay reference / parsed voucher id |
| `consumer_number` | ?string | full Consumer Number (bulk rows; never suffix-matched) |
| `registration_number` | ?string | Registration Number / inquiry field 1 |
| `amount` | ?string | **canonical decimal string, never float** |
| `currency` | ?string | uppercased (e.g. `PKR`) |
| `paid_at` | ?string | normalized date (best-effort `Y-m-d`), null if unparseable |
| `raw_status` | ?string | provider code (`00`,`94`,…) — a code, not a secret |
| `redacted_trace_id` | string | from transport outcome, else `redactor->traceId()` |
| `evidence_hash` | string | deterministic non-PII hash for audit/dedup |
| `validation_errors` | string[] | machine-readable downgrade reasons |

**`KuickPayResponseParser`**:
- `__construct(KuickPayRedactor $redactor = null)`
- `parse(array $transportOutcome, array $context = []): KuickPayEvidence` — InsertVoucher + BillPaymentInquiry (single-row), dispatch on `$transportOutcome['operation']`.
- `parseBulk(array $transportOutcome, array $context = []): KuickPayEvidence[]` — one evidence per dataset row; malformed/incomplete → single `manual_review` evidence, zero matched rows.
- `$context` keys (all optional; absence forces fail-closed for paid classification): `expected_amount` (decimal string), `expected_currency` (default `PKR`), `expected_registration_number`, `expected_consumer_number`, and for bulk `expected_consumer_numbers` (string[]). The plugin reconcile/voucher services (3.3/3.4/3.7) supply these from durable Voucher records.

**Transport-outcome shape consumed** (produced by `KuickPaySoapClient`, do not re-derive — see KuickPaySoapClient.php:521-542):
`['ok'=>bool, 'operation'=>string, 'raw_result'=>?string, 'raw_envelope'=>?string(redacted), 'error_class'=>?string(null|timeout|transport_error), 'fault'=>?string(redacted), 'redacted_request'=>array, 'redacted_trace_id'=>string, 'attempts'=>int]`. **`raw_result` is the parser's single functional input** — the unredacted `*Result` payload. `raw_envelope`/`fault` are redacted diagnostics; do not parse them for business meaning.

### Raw response shapes (confirmed from live WHMCS; fixtures provisional)

[Source: whmcs-live-implementation-evidence.md; testing-fixtures.md:47-57; docs/kuickpay/fixtures/*]

- **`InsertVoucherResult`** — fixed-position string. `00 VOUCHERID00001`: status = first 2 chars; voucher id after the status (documented `substr(result,3,14)`). `00` alone = malformed (missing id). `94 …` duplicate, `05 …` invalid credentials. Parse defensively; classify, don't crash.
- **`BillPaymentInquiryResult`** — comma-separated. Indices: `0`=status, `1`=registration/reference, `2`=paid date (`Ymd`), `3`=paid amount, `4`=txn ref, `5`=reference, `6`=currency (`PKR`), `7`=institution id. Indices 0–5 are WHMCS-confirmed; **6–7 are fixture-derived/provisional** — read defensively (currency may be absent → fail closed on paid classification).
- **`BillPaymentBulkInquiryResult`** — XML dataset (`<NewDataSet><Table>…`), often CDATA-wrapped; the 3.1 client returns the inner XML string in `raw_result`. Rows carry `Consumer_Number`, `Registration_Number`, `Transaction_Date` (`Ymd`), `Paid_Amount`, `Transaction_Reference`, `Currency`. Match by full `Consumer_Number` exact equality only.

### Status & error_class mapping table (the parser contract to implement)

[Source: testing-fixtures.md:14-45; fixtures verified on disk]

| Operation | Fixture | raw_status | `status` | `error_class` |
|---|---|---|---|---|
| InsertVoucher | success.xml `00 VOUCHERID00001` | `00`+id | `pending` | — |
| InsertVoucher | malformed.xml `00` | `00` no id | `manual_review` | `malformed_response` |
| InsertVoucher | duplicate.xml `94 …` | `94` | `manual_review` | `duplicate_reference` |
| InsertVoucher | invalid-credentials.xml `05 …` | `05` | `failed` | `credential_error` |
| InsertVoucher | timeout.md (no body) | — | `manual_review` | `timeout` |
| InsertVoucher | (hardening) non-2-char status | malformed | `manual_review` | `malformed_response` |
| BillPaymentInquiry | pending.xml `01,…` | `01` | `pending` | — |
| BillPaymentInquiry | paid-exact.xml `00,…,1000.00,…,PKR,…` | `00`+preconditions pass | `confirmed_unposted` | — |
| BillPaymentInquiry | amount-mismatch.xml `00,…,900.00,…` | `00`+amount≠expected | `manual_review` | `amount_mismatch` |
| BillPaymentInquiry | expired.xml `02,…` | `02` | `expired` | — |
| BillPaymentInquiry | unknown.xml `99,…` | `99`/other | `manual_review` | `unknown_status` |
| BillPaymentInquiry | (hardening) non-PKR/empty currency, `00` | `00`+currency fail | `manual_review` | null (`validation_errors=['currency_mismatch']`) |
| BillPaymentInquiry | (hardening) `<6` fields/empty | malformed | `manual_review` | `malformed_response` |
| BillPaymentBulkInquiry | matched-paid.xml (row matches expected) | row+preconditions pass | `confirmed_unposted` | — |
| BillPaymentBulkInquiry | unmatched.xml (no expected match) | unmatched row | `manual_review` | `unmatched_reference` |
| BillPaymentBulkInquiry | malformed-xml.xml (truncated dataset) | structure invalid | `manual_review` | `malformed_response` |
| any | transport ok=false, insert | — | `manual_review` | `timeout`/`transport_error` |
| any | transport ok=false, inquiry/bulk | — | `retry` | `timeout`/`transport_error` |

Note: where currency/amount/reference preconditions fail on a `00` candidate, prefer the **most specific** error class (`amount_mismatch` for amount, `unmatched_reference` for reference); a currency failure (no currency class exists, and the status is *known*) or any other precondition failure with no more-specific class → `manual_review` with `error_class = null` (record the reason in `validation_errors`, e.g. `currency_mismatch`). Never `confirmed_unposted` without all preconditions + expected context.

### Existing code you must integrate with (read; do not break)

- **`components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php`** (DONE, 3.1) — produces the transport outcome the parser consumes; `extractRawResult()` (lines 361-401) already hands the `*Result` payload through with the DOCTYPE/size guard. Do not modify it.
- **`components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php`** (DONE, 3.1) — reuse `traceId()` (152-158), `MAX_ENVELOPE_BYTES`/`ENVELOPE_UNPARSEABLE` constants (17-18), and the safe-XML pattern (95-145) for the bulk inner-dataset parse. Do not modify its public behavior.
- **`components/gateways/nonmerchant/kuickpay/tests/bootstrap.php`** (UPDATE) — currently `require_once`s the two existing libs; add the two new lib files.
- **`components/gateways/nonmerchant/kuickpay/build/phpunit.xml`** — suite globs `tests/*Test.php` and whitelists `lib/`; new tests are auto-discovered. Runner note: use `--bootstrap tests/bootstrap.php tests` (the `-c build/phpunit.xml` path is broken — see deferred-work.md:40).
- **`components/gateways/nonmerchant/kuickpay/kuickpay.php`** — leave payment placeholders, `editSettings`, `encryptableFields`, `getSoapClient` factory unchanged (AC11). The parser does not need a gateway change; 2.3/3.3 will wire it.

### Project Structure Notes (the key structural decision — read carefully)

- **Parser + Evidence classes are GATEWAY-lib files**, completing the 4-file lib set the architecture mandates: `components/gateways/nonmerchant/kuickpay/lib/{KuickPaySoapClient,KuickPayResponseParser,KuickPayEvidence,KuickPayRedactor}.php` (architecture.md:683-687; FR-15-17 mapping 826-831). 3.1 explicitly deferred `KuickPayResponseParser`/`KuickPayEvidence` to this story (3-1 Dev Notes "Project Structure Notes"). CamelCase filenames are intentional (architecture.md:545); global namespace; PHP 8.2 type hints; no `declare(strict_types=1)`.
- **Fixtures' canonical home is the PLUGIN test tree**: `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,malformed,ambiguous,redaction}/` (architecture.md:739-752; FR-15-17 mapping line 831; 0-1 handoff). This is a deliberate variance from "tests next to the class": the architecture designates the fixtures as the **shared gateway↔plugin contract artifact**, and the bulk of downstream consumers (reconcile/posting tests 3.3-3.8) live plugin-side. The relocation source + category mapping is testing-fixtures.md:59-76.
- **Parser unit-test PHP lives in the GATEWAY test tree** (`components/gateways/nonmerchant/kuickpay/tests/`), consistent with 3.1's component-local gateway-lib tests and with where the classes live. The gateway tests reference the canonical plugin fixtures via a relative path constant. This gateway-test→plugin-fixture reference is the architecture's intended shape (it placed the parser in the gateway lib and the fixtures in the plugin tree under one FR mapping). **If a reviewer flags the cross-tree reference, this is the documented rationale — not an oversight.** (See Open Questions #1 for the alternative.)
- **Web protection is mandatory** for the relocated fixtures (AC10): `plugins/` is web-served, root `.htaccess` does not cover it, and `.xml` is not extension-denied. Add `plugins/kuickpay_reconcile/tests/.htaccess` (deny all) using the dual-directive content in Task 6. Precedent for the portable Apache 2.2/2.4 form: root `.htaccess:24-30` (not the weaker bare `deny from all` in `plugins/phpids/lib/IDS/.htaccess`).
- **No new language keys** required (the parser surfaces no UI strings; evidence is internal/redacted). If any operator-facing string becomes necessary later, it belongs in plugin/gateway language files (`Kuickpay.*` / plugin convention), never hard-coded. [Source: NFR6]

### Previous Story Intelligence

- **3.1 (SOAP wrapper, done — commits eaf1833a..0f70ff5a):** the transport outcome is the parser's input. **`ok` is transport reachability, NOT a payment signal**; a SOAP `<Fault>` body is `ok=true` and handed to the parser — so an in-body `05 INVALID_CREDENTIALS` arrives as `ok=true` with `raw_result`, and classifying it `credential_error` is **this story's job** (the client deliberately does not). `raw_result` is the only unredacted field. The client never emits parser-owned error classes. `KuickPayRedactor` already does case-insensitive keyed redaction, default-namespace envelope redaction, and blanks `*Result` in `raw_envelope` (so the bulk Consumer_Number/InstitutionID don't leak in diagnostics — the functional copy is in `raw_result`). [Source: 3-1 AC6/AC7; KuickPaySoapClient.php:169-218; KuickPayRedactor.php:129-138]
- **0-1 (Phase 0, done — gate APPROVED by Israr 2026-06-09):** produced the fixtures + the expected-evidence mapping this parser implements. Gate approved on **WHMCS-derived evidence**; fixtures remain `synthetic_from_observed_format`/`provisional` (no sandbox, no sanitized live capture). Post-review hardening added the **Paid-Classification Preconditions** (amount/currency/exact-Consumer-Number) and the **fail-closed status-code default** (codes outside `{00,01,02,94,99,05}` → `unknown_status`) — both are this parser's contract. [Source: testing-fixtures.md:32-45; 0-1 Review Findings]
- **Deferred items that land here:**
  - 0-1 review: the **Story-3.2 hardening fixture set** (Task 7) — non-2-char status, trailing-zero amount, short/empty inquiry, non-PKR currency, mixed multi-row bulk, overpayment, late/partial, suffix-discriminating Consumer pair. [deferred-work.md:13]
  - 1-3 review: "value-based redaction of serialized credential strings" → the protocol redactor layer (satisfied by 3.1's `KuickPayRedactor`); and "credential mask allowlist completion" — the **redactor** is already case-insensitive, so the parser/diagnostic path is covered; the gateway's `maskCredentials()` exact-match allowlist is a separate gateway-owned concern, not required by this story. [deferred-work.md:31-32]
- **Naming is load-bearing:** gateway class `Kuickpay`, plugin class `KuickpayReconcilePlugin`; lib classes load via `Loader::load(dirname(__FILE__).DS.'lib'.DS.'<File>.php')`. [Source: 1-1; alipay.php:176, paystack.php:343]

### Git Intelligence

Recent substantive 3.1 commits established the lib pattern this story extends: `feat(kuickpay): wrap soap operations behind safe client` plus review fixes (`fix(kuickpay): harden redactor for object and array values` 1dd909b9, `fix(kuickpay): cap soap timeout` a5c69e58, `fix(kuickpay): guard empty envelope redaction on php 8` eaf1833a, `test(kuickpay): cover throwable-with-body transport branch` e7837701). Baseline for this story is current HEAD `45926c5e`. Suggested commit: `feat(kuickpay): normalize soap responses into evidence` (type `feat`, scope `kuickpay`, imperative, ≤72 chars). Keep BMad/docs artifact changes out of the implementation commit unless explicitly bundling; the fixture relocation + `.htaccess` are implementation (under `plugins/`), the testing-fixtures.md provenance update is a docs touch. [Source: project-context workflow rules; git log]

### Testing Standards Summary

- **No live KuickPay calls** (NFR11) — fixtures only; the parser takes a transport-outcome array, so tests construct outcomes from fixture `*Result` payloads (no `SoapClient` needed; the bulk path needs only `dom`/`libxml`, not `ext-soap`).
- Component-local tests under `components/gateways/nonmerchant/kuickpay/tests/` (do **not** create root `tests/`; do not claim `../tests` PHPUnit unless that sibling suite is present — NFR12). PHPUnit `~8.5`; use `assertRegExp`-era APIs the project targets (3.1 chose `~8.5` deliberately).
- **FR28 coverage owned here:** parser behavior, status transitions, amount handling, masking (no secret in evidence). Idempotency/duplicate-prevention/posting tests remain 3.4/3.5/3.8.
- Verify: `php -l` touched PHP; `xmllint --noout` new fixtures; component suite via `--bootstrap tests/bootstrap.php tests`. State exactly what ran; if `php`/`ext-soap` absent here, run under PHP 8.2 + PHPUnit ~8.5 before merge (reconcile the 3.1 contradictory toolchain record). [Source: deferred-work.md:9,40; architecture.md:477-483]

### Risks / Open Items (surface in Dev Agent Record)

- **Fixtures provisional, no sandbox.** The mapping is locked to WHMCS-derived shapes, human-accepted for parser development. Keep the parser **defensive and fail-closed** so a fixture/response-capture refinement (e.g. the still-open authoritative `InsertVoucherResult` delimiter/offset, 0-1 decision row) cannot turn a wrong guess into a paid invoice. Do not narrow the status allow-list beyond the confirmed set. [Source: testing-fixtures.md:43-45; 0-1 Review decision-needed]
- **Indices 6–7 of the inquiry result (currency, institution) are fixture-derived, not WHMCS-confirmed.** Read defensively; absent/empty currency must fail closed on paid classification (currency is required for every paid check). [Source: whmcs-live-implementation-evidence.md:28 documents only indices 0–5]
- **`php`/`ext-soap` may be absent in this checkout** (3.1 record is self-contradictory). `php -l` and the PHPUnit suite must run under PHP 8.2 + PHPUnit ~8.5 before merge; `xmllint` IS available here for fixture well-formedness. Do not overstate verification. [Source: deferred-work.md:9]
- **Cross-tree test→fixture reference** (gateway tests reading plugin fixtures) is a documented architectural choice, not an accident (see Project Structure Notes + Open Questions #1).

### References

- [Source: _bmad-output/kuickpay/planning-artifacts/epics.md#Story-3.2 (lines 600-617)] — story, ACs, sequencing note
- [Source: _bmad-output/kuickpay/planning-artifacts/epics.md (lines 132-135, FR16/FR17 lines 55-57, FR28 line 79)] — parser/normalization additional requirements
- [Source: _bmad-output/kuickpay/planning-artifacts/architecture.md#Parser-and-Evidence-Contract (lines 549-579)] — 12 normalized fields + 8 allowed error classes
- [Source: _bmad-output/kuickpay/planning-artifacts/architecture.md#API-and-Communication-Patterns (lines 381-414)] — SOAP→redactor→parser flow, bounded XML, retry policy
- [Source: _bmad-output/kuickpay/planning-artifacts/architecture.md#Posting-Contract / #UI-Display-State-Matrix (lines 581-608)] — confirmed_unposted ≠ paid; state semantics
- [Source: _bmad-output/kuickpay/planning-artifacts/architecture.md#Complete-Project-Directory-Structure (lines 683-687, 739-752, 826-831)] — gateway lib parser/evidence + plugin fixture tree
- [Source: docs/kuickpay/testing-fixtures.md] — expected-evidence mapping, paid preconditions, status-code default, category mapping, provenance
- [Source: docs/kuickpay/whmcs-live-implementation-evidence.md] — confirmed result shapes/offsets/date formats
- [Source: docs/kuickpay/fixtures/*] — the actual sanitized fixtures the parser must satisfy
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php (lines 116-224, 361-401, 521-542)] — transport outcome shape + raw_result handoff
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php (lines 17-18, 95-158)] — reusable constants, safe-XML, traceId
- [Source: _bmad-output/kuickpay/implementation-artifacts/{3-1,0-1}-*.md, deferred-work.md] — predecessor patterns, hardening backlog, toolchain caveats
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, testing/workflow rules, secret-safety

## Dev Agent Record

### Agent Model Used

### Debug Log References

- 2026-06-10: Added `KuickPayEvidenceTest` first; bootstrap failed on missing `KuickPayEvidence.php` as the expected RED phase.
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayEvidenceTest.php` passed after implementation (2 tests, 19 assertions).
- 2026-06-10: `php -l` passed for `KuickPayEvidence.php`, `tests/bootstrap.php`, and `KuickPayEvidenceTest.php`.
- 2026-06-10: Added `KuickPayResponseParserTest` first; bootstrap failed on missing `KuickPayResponseParser.php` as the expected RED phase.
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayResponseParserTest.php` passed after implementation (13 tests, 92 assertions).
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` passed (52 tests, 262 assertions).
- 2026-06-10: `php -l` passed for `KuickPayResponseParser.php` and `KuickPayResponseParserTest.php`.
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayResponseParserTest.php --filter Inquiry` passed (13 tests, 93 assertions).
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` passed after inquiry implementation (64 tests, 352 assertions).
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayResponseParserTest.php --filter Bulk` passed (9 tests, 50 assertions).
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` passed after bulk implementation (72 tests, 400 assertions).
- 2026-06-10: `php -l` passed for `KuickPayResponseParser.php` and `KuickPayResponseParserTest.php` after bulk implementation.
- 2026-06-10: Copied all Phase 0 fixtures to `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` using the Story 3.2 category mapping; `cmp` verified relocated copies match source fixtures.
- 2026-06-10: Added `plugins/kuickpay_reconcile/tests/.htaccess` with Apache 2.2/2.4 deny directives to block web access to plugin-side fixtures.
- 2026-06-10: Added Story 3.2 hardening fixtures and provenance rows in `docs/kuickpay/testing-fixtures.md`.
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayResponseParserTest.php` passed with fixture-backed cases (49 tests, 367 assertions).
- 2026-06-10: `find plugins/kuickpay_reconcile/tests/fixtures/kuickpay -name '*.xml' -print0 | xargs -0 xmllint --noout` passed.
- 2026-06-10: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` passed after fixture-backed tests (88 tests, 537 assertions).
- 2026-06-10: Story-specific diff (`git diff --name-only 8114d3478c1406d8787fda69f3685bcc9ba433e5..HEAD`) confirms no changes to `kuickpay.php`, `KuickPaySoapClient.php`, or `KuickPayRedactor.php`.
- 2026-06-10: Final syntax check passed for `KuickPayEvidence.php`, `KuickPayResponseParser.php`, `tests/bootstrap.php`, `KuickPayEvidenceTest.php`, and `KuickPayResponseParserTest.php`.
- 2026-06-10: Final component suite passed: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (88 tests, 537 assertions).
- 2026-06-10: Secret-safety scan found no known forbidden real values (`voucher-user`, `voucher-secret`, `03001234567`, `john@example.com`, `Customer Name`, `config/blesta.php`, `public_html/clientarea`) in plugin fixtures.
- 2026-06-10: Redaction-confirmation scan found only expected placeholders (`REDACTED_USERNAME`, `REDACTED_PASSWORD`, `0300XXXXXXX`, `customer@example.invalid`, `REDACTED_CUSTOMER_NAME`, `INSTITUTION_ID`); `plugins/kuickpay_reconcile/tests/.htaccess` contains `Require all denied` plus Apache 2.2 fallback.
- 2026-06-10: Final completion gate passed: no unchecked task boxes; component PHPUnit passed (88 tests, 537 assertions); touched PHP syntax checks passed; plugin fixture XML validation passed.

### Completion Notes List

- Implemented immutable `KuickPayEvidence` value object with typed constructor/getters, exact 12-key `toArray()` contract, internal-only operation field, uppercase currency normalization, string validation errors, and `isConfirmedUnposted()`.
- Implemented parser core dispatch, transport short-circuit, empty-body malformed handling, allowed status/error fail-closed guardrails, trace-id carry-through, deterministic evidence hashes, and InsertVoucher creation parsing.
- Implemented BillPaymentInquiry parsing, including field normalization, pending/expired/unknown status mapping, fail-closed `00` paid preconditions, exact identity matching, currency mismatch handling, and float-free amount normalization.
- Implemented BillPaymentBulkInquiry parsing with bounded/DOCTYPE-safe XML parsing, structure-first validation, row cap, exact Consumer Number matching, empty-dataset handling, and matched/unmatched row evidence.
- Relocated Phase 0 KuickPay fixtures into the canonical plugin test fixture tree and added web protection without changing the original docs fixture provenance.
- Added Story 3.2 hardening fixtures, documented provisional provenance, and expanded parser tests to read canonical plugin fixtures for all mapping-table operations and fail-closed hardening cases.
- Verified no gateway placeholder/settings/client/redactor regression surface was changed; final lint, PHPUnit, XML validation, fixture secret scan, and web-protection checks passed.
- Story is complete and ready for review; all acceptance criteria are covered by parser implementation, relocated fixtures, fixture-backed tests, and verification notes.

### File List

- components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayEvidenceTest.php
- components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php
- components/gateways/nonmerchant/kuickpay/tests/bootstrap.php
- plugins/kuickpay_reconcile/tests/.htaccess
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-bulk-unmatched.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-bulk-late-partial.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-bulk-overpayment.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-inquiry-empty-currency.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-inquiry-amount-mismatch.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-inquiry-non-pkr.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/bill-payment-inquiry-unknown.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/insert-voucher-duplicate.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/ambiguous/insert-voucher-timeout.md
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/malformed/bill-payment-bulk-malformed-xml.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/malformed/bill-payment-inquiry-short.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/malformed/insert-voucher-invalid-credentials.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/malformed/insert-voucher-malformed.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/malformed/insert-voucher-non-2-char-status.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/redaction/credentials.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-bulk-matched-paid.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-bulk-mixed-multi-row.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-bulk-suffix-pair.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-expired.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-paid-exact.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-paid-trailing-zero.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/bill-payment-inquiry-pending.xml
- plugins/kuickpay_reconcile/tests/fixtures/kuickpay/valid/insert-voucher-success.xml
- docs/kuickpay/testing-fixtures.md
- _bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml
- _bmad-output/kuickpay/implementation-artifacts/3-2-normalize-kuickpay-evidence-with-fixtures.md

### Change Log

- 2026-06-10: Added normalized evidence value object and contract tests.
- 2026-06-10: Added parser core and InsertVoucher creation-response mappings.
- 2026-06-10: Added BillPaymentInquiry parsing and fail-closed paid classification.
- 2026-06-10: Added BillPaymentBulkInquiry parsing and structure-first safety checks.
- 2026-06-10: Relocated Phase 0 fixtures to the plugin test tree and blocked web access.
- 2026-06-10: Added hardening fixtures, provenance, and fixture-backed parser tests.
- 2026-06-10: Completed verification and regression guard for parser evidence story.
- 2026-06-10: Marked story ready for review after final completion gates.
- 2026-06-10: Code review applied two fail-closed parser fixes (commits 9350b310, a5ec6b84); suite 89 tests / 543 assertions.

### Review Findings

Adversarial code review (`bmad-code-review`, 2026-06-10, scope `8114d347..HEAD`) ran three parallel Opus-class layers — Blind Hunter (diff-only), Edge Case Hunter (diff + project), and Acceptance Auditor (diff + spec). Outcome: **2 patch, 0 decision-needed, 0 defer, 9 dismissed**. Both patches were applied and verified (`php -l` clean; component suite 89 tests, 543 assertions).

**Patches (applied):**

- [x] [Review][Patch] Unsound inquiry field reconstruction corrupted valid paid rows — `parseInquiryFields()` rebuilt fields from comma counts and mistook a purely numeric transaction-reference (field 4) for a split thousands-separated amount, mismapping `reference`/`currency`; it could confirm a paid row with the wrong reference or downgrade a genuine paid row to manual_review. All three layers converged on it; untested because every fixture used a non-numeric `KP-TXN-*` ref. Removed the reconstruction (result is fixed-position comma-delimited) and added a numeric-txn-ref regression guard. Fixed in `9350b310`. [components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php:582]
- [x] [Review][Patch] Blank consumer number could match a blank expected entry in bulk — `parseBulk()` used `in_array('', [''], true)`, letting a blank `Consumer_Number` row match a blank expected value and confirm payment (fail-open). Now filters empty expected consumer numbers so an absent/blank identity leaves every row unmatched. Fixed in `a5ec6b84`. [components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php:187]

**Dismissed (spec-compliant or no defect) — recorded for audit:**

- Single-inquiry `expected_consumer_number` matched against the registration field (field 1) — flagged HIGH by the diff-only Blind Hunter, but **spec-mandated**: single inquiry carries no Consumer Number; AC6 / Task 4 require identity matched on field 1. The spec-aware Acceptance Auditor confirmed it is a non-issue.
- Amount fractional truncation to 2 digits — spec amount-normalization rule step 4 explicitly mandates truncate-to-2; currency is PKR-only (Story 1.5), no sub-paisa.
- `evidence_hash` excludes `status`/`error_class` — AC2 fixes the hash input to exactly `[operation, raw_status, reference, consumer_number, registration_number, amount, currency, paid_at]`; correlating identical business evidence is the intent.
- `currency_mismatch` yields `error_class = null` plus a validation error — AC6 mandates exactly this (no currency error class exists; status is known).
- DOCTYPE substring rejection — AC7 mandates rejecting `<!DOCTYPE` before parsing.
- InsertVoucher voucher-id via `trim(substr($result, 2))` — spec authorizes defensive, delimiter-tolerant parsing.
- libxml internal-error state save/restore without try/finally — every current return path restores it; `loadXML()` does not throw.
- Bulk DOCTYPE literal inside business data, and incidental fail-closed ordering — speculative, no current defect.

**Out-of-scope note:** `kuickpay.php` carries a `buildProcess()` currency backstop at HEAD from Story 1.5 (commits `9380a6a7`/`0534daf8`), already reviewed and `done`. AC11 holds for the 3.2 diff, which touches none of the protected gateway/client/redactor surfaces.

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **Fixture home & test location (confirm the structural decision).** This story follows the architecture literally: parser/evidence classes → gateway `lib/`; fixtures → canonical `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` (web-protected); parser tests → gateway `tests/` referencing the plugin fixtures by relative path. The alternative is keeping fixtures + parser tests together under the gateway `tests/fixtures/` and treating the plugin fixture tree as the home for plugin-side reconcile/posting fixtures only (3.3-3.8). The chosen option honors architecture.md:826-831 and the 0-1 handoff; confirm before dev if you prefer the co-located alternative.
2. **`KuickPayEvidence` ingestion shape (return contract now LOCKED).** The return contract is no longer ambiguous: `parse()` always returns one `KuickPayEvidence` (single-row ops only); `parseBulk()` always returns `KuickPayEvidence[]`; there is no polymorphic `parse()`. Still open and non-blocking: if a downstream service (2.3/3.3) would rather ingest the normalized array form, `toArray()` is provided — confirm the preferred ingestion shape so 2.3/3.3 wiring is frictionless.
3. **Authoritative `InsertVoucherResult` format** remains the gate's tracked external dependency (delimiter/offset, 0-1 decision-needed). The parser is built defensively against the WHMCS fixed-position shape; if KuickPay later confirms a different layout, only the InsertVoucher branch + its fixtures change. Non-blocking.
