---
baseline_commit: a633a49a9d90d1a233e3bc82ddb14c07019d0132
---

# Story 3.8: Verify Payment-Safety Contracts

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer and finance operator,
I want automated and fallback verification for reconciliation and posting behavior,
so that unsafe payment mutations are caught before release.

## Context & Why This Story Exists

This is the **capstone of Epic 3** — the gate that proves the payment-truth machinery built across Stories 0.1–3.7 is actually covered by tests, and that its safety contracts cannot be silently broken in a future change. FR28 enumerates the eight contract areas delivery must test; this story exists to make that list **verifiably true**, not aspirational.

**Read this first — it changes how you scope the work.** The test suite already exists and is green. As of the 3.7 review (commit `a633a49a`):
- **Gateway suite** `components/gateways/nonmerchant/kuickpay/tests/` — **215 PHPUnit-executed tests / 1120 assertions** (≈133 `test*` **methods** expanded by 13 `@dataProvider`s), 8 test files (~3.7k lines).
- **Plugin suite** `plugins/kuickpay_reconcile/tests/` — **81 PHPUnit-executed tests / 315 assertions** (≈57 methods × 5 data providers), 5 test files (~2.3k lines).
- The `215`/`81` figures are **per the 3.7 review and are PHPUnit run-counts, not method counts** — Task 5 records the **live** counts from an actual suite run, not these. Don't grep for "215 test methods" (there are ≈133/57).
- **25 sanitized SOAP/XML fixtures** under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,ambiguous,malformed,redaction}/`.

So this story is **overwhelmingly an audit + gap-close + honest-reporting story**, not a build-from-scratch story. The single biggest failure mode here is a dev agent that **reinvents tests that already exist** or, worse, **edits production payment logic** under cover of a "verification" story. Your job is:

1. **Prove coverage** — map every FR28 contract area and every AC1-named outcome to the concrete existing test(s), in a **coverage matrix** committed as the story's primary artifact.
2. **Close the real gaps** — the few areas that are genuinely under-tested (chiefly a cross-cutting **secret-leakage scan** over persisted evidence + all fixtures, and a **consolidated fail-closed guarantee** that no unknown/malformed/ambiguous response ever reaches `confirmed_unposted`/`posted`). Add only the tests that don't already exist.
3. **Make verification repeatable and honest** — a documented procedure (both component suites + `php -l` + the leak scan) and a verification report that states **exactly what ran** and what was unavailable (sibling `../tests`, live DB), with **no root-PHPUnit overstatement** (NFR12, AC2).

**If a new contract test reveals a genuine payment-safety defect** (something that can mark an invoice paid unsafely), that is the story working as intended — but you **STOP and surface it** as a finding, you do **not** bury a production-logic change inside this test story. Stories 3.1–3.7 each passed adversarial review, so expect coverage gaps, not live bugs; a real bug is an escalation, not a quiet fix.

## Acceptance Criteria

From epics.md Story 3.8 (lines 707–722), restated as testable criteria. **ACs are the floor, not the ceiling** — the deliverable must honor every Must-Not-Break Invariant in §"Must-Not-Break Invariants" even where not spelled out in an AC.

1. **AC1 — The eight FR28 contract areas have explicit, passing coverage, and the four named unsafe outcomes are provably covered.** Given parser-behavior, client-mapping, idempotency, duplicate-prevention, status-transition, amount-handling, secret-masking, and reference-pattern-generation checks exist, when the relevant component test suite runs, then it explicitly covers: (a) **unknown/unmapped responses never produce a paid/confirmed result** (fail closed to `retry`/`manual_review`), (b) **duplicate posting is prevented** (a re-run never creates a second Blesta transaction or a second confirm), (c) **mismatched amounts never post** (amount mismatch → `manual_review`, never `confirmed_unposted`/`posted`), and (d) **no secret leakage** — no production credential, password, real Institution ID, or customer PII (CNIC, real mobile/email) appears in **any fixture**, and **no raw/unredacted SOAP/XML envelope or credential** appears in any persisted `diagnostic_summary`, audit payload, run summary, items row, or test-emitted log. (Sanitized SOAP response envelopes with placeholder values **are expected** in `.xml` parser fixtures per `docs/kuickpay/testing-fixtures.md` §"Sanitization Rules" — the scan forbids *real secrets* and *raw persisted envelopes*, not the realistic fixture shape parser tests require.) Each area maps to a concrete test in the coverage matrix; any gap found is closed with a new test (not by relaxing an assertion).

2. **AC2 — Verification is honest and the fallback is explicit.** Given the sibling Blesta `../tests` suite and a live database runtime are unavailable in this environment, when verification is reported, then the report lists the **exact fallback commands actually run** (the two component PHPUnit invocations with their pass counts, `php -l` over changed files, and the leak scan) and **does not claim root PHPUnit coverage** or present lint-only/fixture-only checks as full coverage. The report names what could **not** be exercised (root `../tests`, DB-backed install/upgrade smoke, live SOAP) rather than implying it passed.

## Scope Decision (read before estimating)

**In scope (3.8):**
- A **FR28 coverage matrix** (the eight areas + the four AC1 outcomes → existing test file/method), committed as a doc artifact under `docs/kuickpay/`.
- **Gap-closing tests only** — new test methods/fixtures for the genuinely under-covered guarantees (see §"Coverage Gaps to Close"). Reuse existing fixtures; add a fixture only when no existing one exercises the case.
- A **consolidated fail-closed test** asserting that the full set of unknown/malformed/ambiguous fixtures, run through the real parser (+ validator where applicable), never yields `confirmed_unposted`/`posted`.
- An **automated secret-leakage scan** test that walks every fixture file and every persisted-evidence string the services produce, asserting no forbidden value/credential/raw-envelope leaks.
- A **repeatable verification procedure + honest verification report** (the Dev Agent Record's Completion Notes is the report; optionally mirror the procedure into `docs/kuickpay/`).

**Out of scope (do NOT do):**
- **Any change to production payment logic** — parser rules, validator, posting service, reconcile service, schema, gateway. This is a test/doc story. The only non-test edits permitted are (a) the coverage-matrix doc and (b) a **test-only seam** if and only if a contract genuinely cannot be asserted without one (and even then, prefer the existing DI constructors — see §Testing).
- New features, admin UI, run-summary views, audit-viewing surfaces (Epic 4).
- Live/sandbox KuickPay tests (Story 5.1) — those are opt-in and disabled by default; do not enable network calls.
- Root `tests/` directory creation; modernizing the legacy PHPUnit setup; any PHPCS reformat sweep.

## Tasks / Subtasks

- [x] **Task 1 — Build the FR28 coverage matrix (AC1).** Produce a matrix mapping each of the 8 FR28 areas + the 4 AC1 outcomes to the concrete existing test(s). Use §"FR28 Coverage Matrix (start here)" below as the verified starting point; confirm each cited test still exists and passes, and mark each cell **COVERED** / **GAP**. Commit the matrix to `docs/kuickpay/payment-safety-verification.md` (new file). Do not duplicate it into runtime code.
  - [x] Run both suites first to get the live baseline counts; record them.
  - [x] For every cell marked GAP, decide whether an existing test partially covers it (strengthen) or nothing does (add).
  - [x] Cross-check the matrix against architecture.md's **minimum fixture gate** (`architecture.md:228-239`): ensure each case is mapped — including **single-inquiry late-after-expiry** (`ambiguous/bill-payment-inquiry-late-after-expiry.xml`), **single-inquiry overpayment** (`ambiguous/bill-payment-inquiry-overpayment.xml`, parser test:502), and **invoice mismatch** — the last is **COVERED at the validator layer** (`KuickPayEvidenceValidatorTest::testValidationFailuresReturnMachineReasonCodes` via `failureProvider`, 5 `invoice_mismatch` cases: empty links, missing/void/paid invoice, wrong-client), **not** a parser fixture (invoice validation lives in the validator, not the parser) — map it there; do **not** hunt for a parser fixture. Add a row or mark COVERED/GAP explicitly for any case the current matrix only covers in the bulk context.

- [x] **Task 2 — Close the secret-leakage gap (AC1.d, NFR8).** The current leak coverage is `KuickPayRedactorTest` (envelope/credential unit masking) + a forbidden-term scan over **language strings** in `KuickPayVoucherGatewayHelpersTest.php:1241`. Neither scans **fixtures** or **persisted evidence**. Add a dedicated leak-scan test (plugin suite, e.g. `tests/KuickPaySecretLeakageTest.php`):
  - [x] **Fixture scan:** glob every file under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/**`, assert none contains a forbidden *value* — real-looking credentials, `password`/`userName` values, an unredacted SOAP `Envelope`/`Header` credential, a real Institution ID, or PII (CNIC, real mobile, real email). Key the scan on the sanitization rules in `docs/kuickpay/testing-fixtures.md` §"Sanitization Rules" (placeholders only; `synthetic_from_observed_format`). Use a forbidden-substring list, not just a `REDACTED_` allow-check (deferred-work 0.1 flagged that mixed placeholder styles defeat a `REDACTED_`-keyed scan). **Do not flag the presence of a sanitized `<Envelope>` itself** — every `.xml` fixture legitimately carries one (testing-fixtures.md:9); flag only forbidden *values* inside it, and only `.md` transport descriptors and `.xml` results, not a stray real secret string.
  - [x] **Persisted-evidence scan:** drive the reconcile + posting services over the malformed/credential/redaction fixtures (reuse the existing DI fakes from `KuickPayReconcileServiceTest`/`KuickPayPostingServiceTest`), capture every value they persist or audit — `diagnostic_summary`, run `summary`, `kuickpay_reconciliation_items` rows, and audit payloads (the fake audit service already records `[eventName, context]`) — and assert each is free of forbidden values and carries only redacted trace IDs / evidence hashes. This is the cross-cutting NFR8 guarantee no single existing test makes. Reuse the **inline per-test-file fake audit class** these suites already define — you do **not** need to `require_once` the real `KuickPayAuditService` (it is not loaded by `tests/bootstrap.php`).
  - [x] **Don't miss these sinks** (an independent enumeration flagged them): also exercise the **issuance path** — `KuickPayIssuanceService::recordIssueOutcome` persists `diagnostic_summary = json_encode($evidence->toArray())` (IssuanceService:39), which carries `raw_status`/`reference`/`consumer_number`/`registration_number`. And scan the two **provider-echoed voucher columns** where a smuggled secret would land but a `diagnostic_summary`-only scan would miss it: **`raw_status`** (ReconcileService:378, IssuanceService:36) and **`kuickpay_reference`** (IssuanceService:35). Confirmed-clean fields you do **not** need to chase: exception/`Throwable` messages are swallowed (never persisted), and the plugin/gateway have **no** log sinks (`error_log`/`Log::`/`logger`); the run `summary` carries only `status` + integer counts.

- [x] **Task 3 — Consolidated fail-closed guarantee (AC1.a/c).** Add one test (gateway suite, alongside `KuickPayResponseParserTest`, or a new `KuickPayFailClosedContractTest.php`) that enumerates the unsafe cases and asserts **none** yields `confirmed_unposted`/`posted` and each fails closed. Split the enumeration by input type — do **not** glob `.md` into the XML parser:
  - [x] **XML/SOAP result fixtures** — every `.xml` under `malformed/` and `ambiguous/` plus the `unknown`/`empty-currency`/`non-pkr` cases: feed each through the real parser (and, for matched-but-unsafe rows, the validator via the existing fake-backed service path). Assert the result status is fail-closed (`pending`/`expired`/`manual_review`/`failed`/`retry`) and **never** `confirmed_unposted`/`posted`. This turns the per-fixture assertions into a single regression wall: a future parser change that lets any unsafe case through breaks this test.
  - [x] **Transport descriptor (not XML)** — `ambiguous/insert-voucher-timeout.md` is a **markdown transport-outcome descriptor with no response body** (testing-fixtures.md:22), not a SOAP/XML result. Assert it as a *structured transport outcome* (`ok => false`, `operation => InsertVoucher`, `error_class => timeout` → `manual_review`) the way `KuickPayResponseParserTest.php:64-75` already constructs it — **never** read it through the XML parser. Include it in the matrix as a transport case, separately from the `.xml` results.
  - [x] **`error_class` assertion — contract-correct (read before asserting).** The parser's `error_class` is **one of the 8 `ERROR_*` constants OR `null`** — *not* "always one of 8." `currency_mismatch` is **not** an `error_class`: for non-PKR / empty-currency paid candidates the parser sets `errorClass() === null` and pushes `currency_mismatch` into `validationErrors()` (verified parser:526-527, test:504-505; the parser's `ALLOWED_ERRORS` guard at :723-725 enforces 8-or-null). So assert: (a) where parser `errorClass()` is non-null it is one of the 8; (b) currency-mismatch cases have `errorClass() === null` **and** `validationErrors()` contains `currency_mismatch` **and** status `manual_review`. Do **not** add a 9th class or relax parser semantics (Invariant #1/#4).
  - [x] **Service/persisted layer is a superset of the 8.** If the wall captures persisted/service output (not just the parser evidence object), the services also emit `reconcile_exception` (`KuickPayReconcileService.php:335` — written **only** to `kuickpay_reconciliation_items.error_class`) and `posting_failed` (`KuickPayPostingService.php:345` — written to `kuickpay_vouchers.error_class` + `diagnostic_summary`) for their own exception/failure rows. These are **legitimate fail-closed codes, not leaks or defects.** An independent enumeration confirmed the complete persisted set is exactly `{the 8} ∪ {reconcile_exception, posting_failed}` (plus `null` for success/confirmed/posted) — 10 non-null values, no others. Assert voucher `status` is never `confirmed_unposted`/`posted`, and treat that set as the allowed persisted `error_class`. Do not "collapse" these into the 8 by editing production.
  - [x] Assert the **paid-classification preconditions** from `docs/kuickpay/testing-fixtures.md` §"Paid-Classification Preconditions": a `00` status / present bulk row is necessary-but-not-sufficient — amount equality, currency match, exact Consumer-Number equality, and structural validation must all hold to reach `confirmed_unposted`. (A usable paid date is also required **on the bulk path**; the single-inquiry path does not enforce it — see the paid-date bullet below for that documented asymmetry.)
  - [x] **Paid-date — assert the guarantee that HOLDS, not the one that fails.** `parseBulk` fails closed on a missing/unparseable paid date (parser:261-270, added by `e04ac212`); the **single-inquiry** path does **not** — `parseInquiry` reaches `confirmed_unposted` (parser:548-555) with no paid-date check, so a matched, amount/currency/reference-correct row with an empty/unparseable `Transaction_Date` yields `confirmed_unposted` + `date_paid = null`, and **no downstream layer (validator/reconcile) catches it** before persistence (confirmed by a full single-inquiry trace). **Do NOT assert "single-inquiry confirmed ⟹ paid date present"** — that assertion *fails* against current code and would stall this story or tempt the forbidden parser edit. Instead assert the guarantee that actually holds and is currently **uncovered**: such a row **never reaches `posted`**. Drive `KuickPayPostingService::postVoucher` with a `confirmed_unposted` voucher whose `date_paid` is null/empty/`0000-00-00` and assert `validPaidDate` (`KuickPayPostingService.php:80-82,307-316`) moves it to `manual_review` with `missing_paid_date` and creates **no** transaction (fake-driven). Note in the report that the second layer — `getPostable`'s `date_paid IS NOT NULL` filter — is DB-side and not exercisable without a live DB.
  - [x] **Record the parser asymmetry as a LOW deferred item — it is NOT a payment-safety escalation.** The single-inquiry `confirmed_unposted`-with-null-date is a latent *stuck-state* gap (the voucher never posts **and** is never surfaced as `manual_review`), not an unsafe posting — the posting layer fails closed, so no invoice is ever marked paid, so it does **not** trip §Context's "can mark an invoice paid unsafely" escalation trigger. Add it to `deferred-work.md` as LOW/latent, with the fix being to mirror the `parseBulk` `missing_paid_date` guard (parser:261-270) into `parseInquiry`'s confirmed branch — a one-block production change to be done **outside** Story 3.8. Do not make that production edit here.

- [ ] **Task 4 — Confirm duplicate-posting & amount-mismatch idempotency (AC1.b/c).** Verify (don't reinvent) that these named outcomes are asserted; strengthen only if the assertion is weak:
  - [ ] Duplicate posting: `KuickPayPostingServiceTest` already has `testAlreadyPostedVoucherIsNoOpAfterLock` (proves the no-op on a **single** call over an already-`posted` voucher). The bulk rerun-idempotency test `testRunBulkAlreadyConfirmedVoucherIsNotDemotedOnRerun` lives in **`KuickPayReconcileServiceTest.php:530`** (added with commit `b0b1d91c`) — **not** in the posting test. Confirm a re-run creates **no** second `Transactions->add()/apply()` and no second item row (`uniq_kuickpay_items_run_voucher`). The existing posting test asserts the no-op on first encounter only — if no test drives **two successive `postVoucher()` calls** and asserts the transaction-writer fake's call count stays zero across both, add that explicit re-run assertion.
  - [ ] Mismatched amount: confirm `amount-mismatch` (single) and bulk `overpayment`/`late-partial` fixtures assert `manual_review` + `amount_mismatch` and **never** `confirmed_unposted`. The parser owns this (Invariant #6); do not move the check.
  - [ ] Amount comparison uses normalized decimal strings / minor units, never floats (NFR13) — confirm `bill-payment-inquiry-paid-trailing-zero.xml` proves `1000.0 == 1000.00`.

- [ ] **Task 5 — Run the full verification and write the honest report (AC2).** Execute the verification procedure (§Testing) and record results in the Dev Agent Record as the verification report:
  - [ ] Run both component suites with the external PHPUnit 8.5 runner; record exact `tests/assertions` and pass/fail.
  - [ ] `php -l` on every changed PHP file (test files + any test-only seam); record results.
  - [ ] State explicitly that root `../tests` and live DB-backed install/upgrade/SOAP smoke were **not** available/run; do not imply they passed (NFR12). If run on the web host where the runner exists, say so; if run elsewhere, say what was used.
  - [ ] Confirm the matrix shows every FR28 area COVERED after gap-close; list any residual gap explicitly (and route to `deferred-work.md` if accepted).

- [ ] **Task 6 — (Optional / SHOULD) audit-trail completeness spot-check.** `KuickPayAuditService` has **no dedicated test**; audit emission is asserted only indirectly. If time allows, add a focused test that the posting path emits `posting.started` → `posting.succeeded`/`posting.failed` and the reconcile path emits the evidence/run events, with redacted payloads only. Also cover the **3.3 deferred gap** — a per-voucher `catch (Throwable)` reconcile path writes a `reconcile_exception` item row; confirm it emits a redacted error/evidence audit event too. A real `KuickPayAuditService` test needs `require_once __DIR__ . '/../lib/KuickPayAuditService.php'` added to `tests/bootstrap.php` (it is not currently loaded). **Not AC-required** (FR28's list does not include audit completeness) — record as deferred if not done.

## FR28 Coverage Matrix (start here — verified against the tree at `a633a49a`)

Use this as the audited starting point for Task 1. "Status" is this story's assessment; confirm by running the suites.

| FR28 area | Primary existing coverage | Status |
|---|---|---|
| **Parser behavior** | `gateway/tests/KuickPayResponseParserTest.php` (25 methods) + 25 fixtures (`insert-voucher-*`, `bill-payment-inquiry-*`, `bill-payment-bulk-*`) | COVERED |
| **Client mapping** (SOAP wrapper: timeouts, TLS, credential selection, sanitized logging) | `gateway/tests/KuickPaySoapClientTest.php` (17) | COVERED |
| **Client mapping** (invoice/contact → voucher request, FR8) | `gateway/tests/KuickPayVoucherGatewayHelpersTest.php` (request construction, amount normalization, allocation) | COVERED |
| **Idempotency** | `plugin/tests/KuickPayPostingServiceTest.php` (already-posted no-op, lock); `KuickPayReconcileServiceTest` (bulk rerun idempotency, dup consumer); reference uniqueness in `KuickPayVoucherReferenceServiceTest` | COVERED |
| **Duplicate prevention** | reference collision/retry in `KuickPayVoucherReferenceServiceTest` (30); `insert-voucher-duplicate.xml`; bulk duplicate-consumer rows; `duplicate_reference` validator path | COVERED |
| **Status transitions** | `KuickPayReconcileServiceTest` (pending→confirmed_unposted/manual_review/retry); `KuickPayPostingServiceTest` (→posted, rollback keeps non-posted); `KuickPayIssuanceServiceTest` (pending/retry) | COVERED (no single consolidated forbidden-jump test — see Task 3) |
| **Amount handling** | `plugin/tests/KuickPayEvidenceValidatorTest.php` (exact, trailing-zero, multi-link, currency); parser amount fail-closed; `paid-trailing-zero`/`amount-mismatch` fixtures | COVERED |
| **Secret masking** | `gateway/tests/KuickPayRedactorTest.php` (6); forbidden-term scan over **language strings** `KuickPayVoucherGatewayHelpersTest.php:1241`; `redaction/credentials.xml` | **GAP** — no scan over fixtures or persisted evidence/audit (Task 2) |
| **Reference pattern generation** | `KuickPayVoucherReferenceServiceTest.php` (30) — registration/consumer patterns, collision, uniqueness-exhausted | COVERED |
| **AC1.a — unknown never paid** | per-fixture parser tests (`unknown`, `non-2-char-status`, `malformed`, `empty-currency`, `non-pkr`) | COVERED, **needs consolidation** (Task 3) |
| **AC1.b — duplicate posting prevented** | `testAlreadyPostedVoucherIsNoOpAfterLock`; bulk rerun idempotency (`b0b1d91c`) | COVERED, **confirm txn-writer call-count** (Task 4) |
| **AC1.c — mismatched amount never posts** | `amount-mismatch`/`overpayment`/`late-partial` fixtures → `manual_review` | COVERED, **consolidate in Task 3** |
| **AC1.d — no secret leakage** | redactor unit + language-string scan only | **GAP** — Task 2 closes the fixture + persisted-evidence scan |

**Net: 2 real gaps (both secret-leakage breadth) + 1 consolidation (fail-closed wall) + 1 confirmation (duplicate-posting call-count).** Everything else is COVERED and must not be reinvented.

## Coverage Gaps to Close (the actual new test work)

1. **Secret-leakage breadth (AC1.d / NFR8)** — fixture scan + persisted-evidence/audit scan. *The* headline gap. (Task 2)
2. **Consolidated fail-closed wall (AC1.a/c)** — one enumerated test over all unsafe fixtures asserting no `confirmed_unposted`/`posted`. (Task 3)
3. **Duplicate-posting call-count assertion (AC1.b)** — make the no-op explicit against the transaction-writer fake. (Task 4)
4. **(Optional) audit-completeness** — `KuickPayAuditService` has no dedicated test. (Task 6, SHOULD)

## Must-Not-Break Invariants (verify each; this story must not weaken any)

These are the contracts the new tests *assert*, and the lines this story must not cross:

1. **No production-logic edits.** Parser, validator, posting/reconcile services, schema, and gateway stay byte-for-byte unchanged except via an explicitly-flagged escalation. New code is test code + the matrix doc. [§Scope Decision]
2. **Posting boundary.** Only `KuickPayPostingService` may create/apply a Blesta transaction. Tests assert this; they must not call posting from the bulk/single path. [architecture.md §Posting Contract :583; §Anti-Patterns :650–661]
3. **Fail closed on unknown.** Unknown/malformed/duplicate/mismatched/unmatched/late/partial/over → `retry`/`manual_review`/`failed`, never paid. Task 3 is the regression wall for this. [NFR9; architecture.md :49, :567, :579]
4. **Amount fail-closed lives in the parser.** Do not move/relax the amount check to make a test pass. The parser's `error_class` is a fixed enum of **8 values or `null`** — `currency_mismatch` is a `validationErrors()` entry, not a 9th class (parser:526-527/548-555, guard :723-725); the service layer additionally emits `reconcile_exception`/`posting_failed` for its own rows. Assert against that contract — do not add a class or change parser semantics. [memory: parser front-runs validator; architecture.md :569–577]
5. **No secret/PII leakage** anywhere — fixtures, `diagnostic_summary`, `summary`, items, audit payloads, logs. Redacted trace IDs / evidence hashes only. Task 2 is the enforcement; it must not itself print a leaked value on failure (assert on a boolean/redacted message, not by echoing the secret). [NFR8; project-context; architecture.md :608, :634]
6. **Honest verification.** No root-PHPUnit claim unless sibling `../tests` exists (it does not here). Lint/fixture/component checks are reported as exactly that. [NFR12; AC2; architecture.md :274, :645, :911]
7. **Both suites stay green and grow.** Baseline ≥ 215 gateway / 81 plugin **PHPUnit-executed tests** (≈133 / 57 `test*` methods before data-provider expansion); new tests add to these counts and all existing tests keep passing (backward-compatible). The ≥215/≥81 gate is on **executed tests** from a live run. [3.7 review baseline]
8. **No network in the default suite.** Tests use fixtures + DI fakes only; do not introduce a live SOAP/WSDL/HTTP call (that is Story 5.1, opt-in, disabled by default). [NFR11]
9. **Company scoping & redaction in any new fake-driven assertion** — mirror the existing tests' company-scoped, redacted shapes; do not weaken them.

## Dev Notes

### Files that already exist — reuse, do not reinvent

| Component | Path | Use in 3.8 |
|---|---|---|
| Parser + 25 fixtures | `components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php`; `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/**` | Drive the fail-closed wall (Task 3) and leak scan (Task 2) off these. |
| Redactor + test | `…/lib/KuickPayRedactor.php`; `…/tests/KuickPayRedactorTest.php` | Existing unit masking — the leak scan extends, not replaces, this. |
| Existing language leak scan | `…/tests/KuickPayVoucherGatewayHelpersTest.php:1241` (`$forbiddenTerms`) | Reuse the forbidden-term pattern; extend its target set to fixtures + persisted evidence. |
| Reconcile service + DI fakes | `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php`; `…/tests/KuickPayReconcileServiceTest.php` | Reuse the DI constructor (`client_factory`, `voucher_repository`, `gateway_config`, fake audit/item repos) to capture persisted evidence for Task 2. |
| Posting service + test | `…/lib/KuickPayPostingService.php`; `…/tests/KuickPayPostingServiceTest.php` | Confirm duplicate-posting no-op against the transaction-writer fake (Task 4). |
| Validator + test | `…/lib/KuickPayEvidenceValidator.php`; `…/tests/KuickPayEvidenceValidatorTest.php` | Amount/currency/reference assertions already here — cite in matrix. |
| Fixture conventions doc | `docs/kuickpay/testing-fixtures.md` | Source of the sanitization rules + paid-classification preconditions the scans/wall assert. |

### Test runner reality (critical — read before running anything)

- The **external PHPUnit 8.5 runner lives on the web-facing host** at `/root/tools/phpunit-8.5/vendor/bin/phpunit`. This planning checkout is macOS (`darwin`) and **does not have it** — `php -l` may be all that runs locally. Run the suites where the runner exists (the beta host), or via the dev's configured PHP 8.2 + PHPUnit ~8.5 toolchain. Report which environment was used.
- **Use `--bootstrap tests/bootstrap.php tests`, NOT `-c build/phpunit.xml`** (project-context: PHPUnit resolves `tests/bootstrap.php` relative to `build/` and fails on the missing `build/tests/bootstrap.php`). The gateway `build/phpunit.xml` exists but is the broken path; the plugin has no `build/` dir at all.
- Gateway suite: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
- Plugin suite: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
- Bootstraps **manually `require_once` the classes** (no Composer autoload in these suites): gateway `tests/bootstrap.php` loads Redactor/SoapClient/Evidence/Parser; plugin `tests/bootstrap.php` loads the gateway parser/evidence/redactor **plus** the plugin lib classes via `../../../components/...` relative paths. **A new plugin test that needs another lib class must add its `require_once` to `plugins/kuickpay_reconcile/tests/bootstrap.php`** — there is no autoloader to fall back on.
- PHP 8.2 target only; no `declare(strict_types=1)`; match each test file's existing style. Several prior reviews ran under PHP 8.3/7.4 because no 8.2 binary was present (deferred-work 3.4, 3.1) — if 8.2 is available, prefer it and say so; if not, disclose the version used.

### Why the secret-leak scan must read persisted output, not just the redactor

The redactor is unit-tested in isolation, but NFR8's guarantee is end-to-end: *nothing the services persist or audit* may carry a secret. The chain parser → `persistEvidence` → `diagnostic_summary`/audit/items/run-`summary` has never been scanned as a whole. The bug class to catch: a value that bypasses the redactor (a credential concatenated into a string before it became an array — deferred-work 1.3 flagged exactly this) or a fixture that smuggles a real-looking secret. Capture what the **fake** repos/audit service receive (they already record call args) and assert over those captured strings.

### Anti-reinvention guardrails (the dominant failure mode for this story)

- Before writing any test, search the matrix and the existing files for the assertion. ~190 methods already exist; most "obvious" tests are already written.
- Do **not** add a second copy of an existing assertion in a new file "for completeness." The matrix is the completeness artifact; tests are not duplicated to fill it.
- Do **not** create a root `tests/` tree, a `phpunit.xml.dist`, or a Composer test script — the suites run via `--bootstrap` by design.
- Do **not** "fix" a failing contract test by relaxing the production rule. A genuine fail-closed/posting/leak failure is an escalation (surface it), not a quiet edit. (See §Context, last paragraph.)

### Project Structure Notes

- Gateway-owned tests live under `components/gateways/nonmerchant/kuickpay/tests/`; plugin-owned tests under `plugins/kuickpay_reconcile/tests/`. Put new tests in the suite that owns the class under test (parser/redactor/fail-closed-wall → gateway; reconcile/posting/leak-of-persisted-evidence → plugin).
- The coverage-matrix doc belongs under `docs/kuickpay/` (project doc area; architecture.md references `docs/kuickpay/testing-fixtures.md` :762 as the sibling). Keep BMAD/_bmad-output and runtime/test commits separate per project-context.
- Naming follows the existing `*Test.php` suffix; fixtures keep the `kuickpay/{valid,ambiguous,malformed,redaction}/` layout and the `synthetic_from_observed_format`/`PENDING_HUMAN_APPROVAL` markers.

### References

- [Source: epics.md#Story-3.8] lines 707–722 (ACs); FR28 line 79; FR15–FR23 lines 53–69; NFR9 line 103; NFR11 line 107; NFR12 line 109; NFR13 line 111; NFR8 line 101.
- [Source: architecture.md] Testing Framework + minimum fixture gate :225–239; Verification Baseline :264–274; parser evidence contract + 8 error classes :555–579; Posting Contract :581–593; UI Display-State Matrix :595–608; Audit patterns :610–634; Enforcement + Anti-Patterns :636–661; root-PHPUnit honesty :911, :645, :274.
- [Source: docs/kuickpay/testing-fixtures.md] Sanitization Rules; Expected Normalized Evidence Mapping; Paid-Classification Preconditions (fail-closed); Status-Code Default (fail-closed).
- [Source: project-context.md] Testing Rules (external PHPUnit 8.5 runner + `--bootstrap` quirk :73–74; no root `tests/` :70; honesty :81); secret/PII non-exposure :125.
- Live test/source/fixture anchors verified by direct read at `a633a49a` (counts: gateway 215/1120, plugin 81/315 per 3.7 review).

## Previous Story Intelligence (3.1–3.7)

- **3.1 (SOAP client):** structured transport outcomes; never decides paid; retries timeout/transport ×3. Tested in `KuickPaySoapClientTest`. Deferred: redaction completeness vs attribute/aliased fields — **the leak scan (Task 2) is the right place to assert current redaction holds**.
- **3.2 (parser/evidence):** normalized contract + the 25 fixtures + the paid-classification preconditions. This is the bedrock the fail-closed wall (Task 3) stands on. Backward compatibility was a hard rule in 3.7 (Approach A expectation-map) — all existing parser tests must keep passing.
- **3.3 (single inquiry):** lock → run → batch → persist → close pattern; several LOW deferrals (no txn around per-voucher writes; `getResumeCursor` cron-only) recorded in deferred-work — **not** in 3.8 scope to fix, but the leak/idempotency scans pass over this code.
- **3.4 (validator):** side-effect-free; bare reason codes; the null/malformed paid-date HIGH item was resolved **for the bulk path only** in 3.7 (`e04ac212` routes a missing bulk paid date → `manual_review`; guard at parser:261-270). The **single-inquiry** confirmed path (`parseInquiry`, parser:548-555) still permits a null paid date to reach `confirmed_unposted`. This is a **LOW/latent stuck-state gap, not a payment-safety escalation** — the posting layer fails closed (`validPaidDate`), so no invoice is ever marked paid. Task 3 asserts the *never-posted* guarantee (which passes), and the parser asymmetry is recorded to `deferred-work.md` for an out-of-scope one-block fix (mirror the bulk guard into `parseInquiry`). Do **not** assert "confirmed ⟹ date present" (it fails against current code) and do **not** edit the parser here.
- **3.5 (posting):** the only transaction writer; re-read/row-lock/revalidate/idempotent on `blesta_transaction_id`. `testAlreadyPostedVoucherIsNoOpAfterLock` is the duplicate-posting anchor (Task 4).
- **3.6 (exceptions):** under/over in the parser, late in the validator, both fail closed; `*_policy` settings + `resolveExceptionStatus`; fixed the config-threading bug. Amount/late fixtures back Task 3/Task 4.
- **3.7 (bulk):** added bulk engine + per-consumer expectation map (backward compatible), `mixed-multi-row`/`suffix-pair`/late/over bulk fixtures, and a **rerun-idempotency** test + the **missing-paid-date fail-closed fix**. Two LOW reporting deferrals (count overlap, unbounded `run_date`) are Epic 4 — **not** 3.8 scope. The bulk fixtures are prime inputs for Task 3.

## Git Intelligence Summary

Epic 3 cadence (`feat|fix|test|docs(kuickpay): …`, ≤72-char lowercase imperative; branch from `main`): small fixture-backed test additions, conventional commits, BMAD docs committed separately from runtime/test code (project-context). Recent HEAD: `a633a49a docs(kuickpay): record story 3.7 code review outcome`. Expect 3.8 commits like `test(kuickpay): scan fixtures and persisted evidence for secrets`, `test(kuickpay): add fail-closed contract wall`, `docs(kuickpay): add payment-safety verification matrix`. Keep the matrix doc commit (`docs`) separate from the test commits (`test`).

## Latest Tech Information

No external/web research required — internal verification story. Toolchain is pinned by project-context: PHP 8.2, PHPUnit ~8.5 (legacy, no autoload — manual `require_once` bootstraps), Blesta 6.0.0-b1. Do not assume PHPUnit 9/10 APIs (e.g. prefer `assertStringNotContainsStringIgnoringCase` as already used; avoid attributes-based test config). No new test library, mocking framework, or XML tooling — the suites use hand-rolled fakes and `libxml` hardening already in place; do not relax `LIBXML_NONET`/DOCTYPE/byte/row caps in any test helper.

## Testing

- **Procedure (the AC2 deliverable):**
  1. `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` → record `tests/assertions`, expect ≥215 and all green.
  2. `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` → record, expect ≥81 and all green.
  3. `php -l` on every changed PHP file (new tests + any test-only seam).
  4. The new leak-scan test (Task 2) is itself part of the suite — it both runs under PHPUnit and serves as the repeatable secret check.
- **Fixtures:** reuse the 25 existing under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/{valid,ambiguous,malformed,redaction}/`. Add a fixture only if a contract case has none; mark new fixtures `synthetic_from_observed_format` / `PENDING_HUMAN_APPROVAL` per `testing-fixtures.md`.
- **DI fakes:** reuse the constructors that `KuickPayReconcileServiceTest`/`KuickPayPostingServiceTest` already use (fake client factory returning fixture transport outcomes; fake voucher/run/item repositories; fake audit service) — do not invent a new harness.
- **Honesty rule (NFR12, AC2):** root `../tests` and a live DB are **unavailable** here. Report exactly the component-suite + `php -l` + leak-scan results. Never present them as root PHPUnit coverage. Disclose the PHP version actually used and any smoke (DB install/upgrade, live SOAP) that could not run.

## Open Questions / Clarifications (resolve with Israr before or during dev)

- **OQ-1 (matrix doc home) — RESOLVED.** The coverage matrix goes at `docs/kuickpay/payment-safety-verification.md` (beside `testing-fixtures.md`), as Scope Decision and Task 1 already mandate. Do **not** pause for this — it is decided. (FR30 full docs remain Epic 5; this small, durable verification matrix is the natural FR28 artifact.)
- **OQ-2 (audit-completeness scope).** Is the optional Task 6 (dedicated `KuickPayAuditService` test) in scope now, or deferred to Epic 4 / Story 4.5 (audit-viewing surface)? It is **not** in FR28's enumerated list, so the story treats it as SHOULD/defer. Default: defer unless Israr wants it now.
- **OQ-3 (test-only seam tolerance).** If a contract (e.g. the AC13 enablement-gate skip path flagged in deferred-work 3.3) genuinely can't be asserted without a small testability seam, is adding a **test-only** seam acceptable, or should it stay a documented gap? Story default: prefer existing DI; if a seam is unavoidable, it must be test-only, non-behavioral, and flagged in the report — otherwise record as deferred.

## Pre-Dev Validation Revisions (round 1)

Four independent validation passes were triaged; every applied change below was re-verified by direct read of the tree at `a633a49a` before editing. So the dev knows what moved and why:

**Applied (verified):**
- **AC1.d / Task 2** — distinguished *sanitized SOAP envelopes in fixtures (expected)* from *raw envelopes/credentials in persisted output (forbidden)*. `testing-fixtures.md:9` mandates real envelope shape in `.xml` fixtures, so an unscoped "no SOAP envelope in any fixture" scan would false-positive all 24 XML fixtures and break the green-suite invariant.
- **Task 3 (error_class)** — corrected to **8 values OR `null`**. `currency_mismatch` is a `validationErrors()` entry with `errorClass()===null` (parser:526-527; test:504-505); the parser's `ALLOWED_ERRORS` guard (:723-725) enforces 8-or-null. Also flagged the service-layer superset (`reconcile_exception` :335, `posting_failed` :345) so the dev does not treat a legitimate persisted code as a leak/defect.
- **Task 3 (timeout)** — split the enumeration: `insert-voucher-timeout.md` is a markdown transport descriptor (no response body) and must be asserted as a structured transport outcome, not parsed as XML.
- **Task 3/Task 4 + 3.4 note (paid date)** — the 3.7 fix (`e04ac212`) was **bulk-only**; the single-inquiry confirmed path (`parseInquiry`, parser:548-555) still permits a null paid date → `confirmed_unposted`. (Round-2 deep-trace **corrected the disposition** — see Round 2 below.)
- **Task 4 (attribution)** — the bulk rerun-idempotency test is `testRunBulkAlreadyConfirmedVoucherIsNotDemotedOnRerun` in `KuickPayReconcileServiceTest.php:530`, not the posting test; clarified the two-call re-run call-count gap.
- **Counts** — clarified `215/81` are PHPUnit-executed runs (≈133/57 methods); Task 5 records live counts. **OQ-1 resolved.** Added the architecture minimum-fixture-gate cross-check (single-inquiry late/overpayment, invoice mismatch) to Task 1.

**Rejected after verification (do not act on these):**
- "*The counts are fabricated*" and "*commit `b0b1d91c` / the bulk rerun test don't exist*" — **false**: `b0b1d91c` is in `git log` (`fix(kuickpay): make bulk reconcile idempotent on duplicate rows`), the test is at `KuickPayReconcileServiceTest:530`, and `215/81` reconcile as data-provider-expanded PHPUnit runs (three of four passes concur).
- "*Add a 9th `currency_mismatch` error_class*" — **wrong fix**; the contract is "8 or null," and adding a class would be a forbidden production-logic edit.

## Pre-Dev Validation Revisions (round 2)

A second pass ran four **narrow, non-overlapping** lanes against the round-1-revised story. Outcome: the round-1 edits held up (independent diff-audit PASS), two areas were confirmed complete, and one disposition was corrected.

**Confirmed — no change needed:**
- **Diff/consistency audit: PASS (5/5).** Every round-1 correction is factually correct and internally consistent; no stale "always one of 8" phrasing remains.
- **`error_class` exhaustiveness: EXHAUSTIVE.** Independent enumeration across all services/repositories confirms the persisted set is exactly `{the 8} ∪ {reconcile_exception, posting_failed}` + `null` — 10 non-null values, no others. (Added the where-written precision to Task 3.)
- **Pre-existing fixture leak: none.** All 25 fixtures are clean placeholders — the scan is a regression guard, not a cleanup.
- **Invoice-mismatch: COVERED** at the validator layer (`KuickPayEvidenceValidatorTest::failureProvider`, 5 cases) — not a parser-fixture gap. (Folded into Task 1 so the dev maps it to the right layer.)

**Applied this round:**
- **Paid-date disposition corrected (the substantive one).** The single-inquiry null-date asymmetry is real, but because the posting layer fails closed (`getPostable` filters `date_paid IS NOT NULL`; `validPaidDate` guards `postVoucher`), **no invoice is ever marked paid** — so it does **not** trip the "mark-invoice-paid-unsafely" escalation trigger. It is a **LOW/latent stuck-state gap.** Task 3 now asserts the *never-posted* guarantee (which **passes**) instead of a *confirmed-requires-date* precondition (which would **fail** against current code and tempt the forbidden parser edit); the parser asymmetry is routed to `deferred-work.md` for an out-of-scope one-block fix.
- **Task 2 sinks broadened** — added the issuance-path `diagnostic_summary`, and the provider-echoed `raw_status` / `kuickpay_reference` voucher columns (a `diagnostic_summary`-only scan would miss a standalone value). Confirmed exception messages and logs are non-sinks.

**Net for the dev:** no open escalation flag. The one item that *looked* like an escalation in round 1 is a documented LOW deferred-work gap; everything Task 3 asserts is a guarantee that holds against current code.

## Dev Agent Record

### Agent Model Used

### Debug Log References

- 2026-06-11: Baseline gateway suite passed: `OK (215 tests, 1120 assertions)`.
- 2026-06-11: Baseline plugin suite passed: `OK (81 tests, 315 assertions)`.
- 2026-06-11: Secret leakage test passed standalone: `OK (2 tests, 260 assertions)`.
- 2026-06-11: Plugin suite with leak scan passed: `OK (83 tests, 575 assertions)`.
- 2026-06-11: Fail-closed contract test passed: `OK (15 tests, 107 assertions)`.
- 2026-06-11: Posting service test passed after empty-date guard: `OK (21 tests, 98 assertions)`.
- 2026-06-11: Full gateway suite passed after Task 3: `OK (230 tests, 1227 assertions)`.
- 2026-06-11: Full plugin suite passed after Task 3: `OK (84 tests, 579 assertions)`.

### Completion Notes List

- Task 1: Added `docs/kuickpay/payment-safety-verification.md` with FR28 contract coverage, AC1 outcome coverage, minimum fixture gate mapping, live baseline counts, and honest unavailable-runtime notes.
- Task 2: Added `KuickPaySecretLeakageTest` to scan every KuickPay fixture and captured reconcile/posting/issuance persisted evidence, item rows, run summaries, and audit payloads for forbidden credentials, PII, raw SOAP envelopes, and credential keys.
- Task 3: Added a gateway fail-closed fixture wall, strengthened posting paid-date coverage for the empty-string case, and recorded the single-inquiry null-date parser asymmetry as LOW deferred work without changing production parser logic.

### File List

- docs/kuickpay/payment-safety-verification.md
- components/gateways/nonmerchant/kuickpay/tests/KuickPayFailClosedContractTest.php
- plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php
- plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php
- _bmad-output/kuickpay/implementation-artifacts/deferred-work.md
- _bmad-output/kuickpay/implementation-artifacts/3-8-verify-payment-safety-contracts.md
- _bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml

### Change Log

- 2026-06-11: Added payment-safety coverage matrix and recorded baseline component-suite counts.
- 2026-06-11: Added automated fixture and persisted-evidence secret leakage scan.
- 2026-06-11: Added consolidated fail-closed contract wall and paid-date posting guard coverage.

### Review Findings
