---
baseline_commit: c18e27af5ffcb6481b5bf832d40f8073fe022ba9
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.5: Structural Company-Scoping and Test-Fidelity Guarantees

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a developer,
I want the recurring company-scope and fake-fidelity classes converted to structural guarantees,
so that the most-repeated review finding cannot recur by omission.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.5 (lines 957–977). Closes: **Epic 4 retro AI-8** (structural
> company-scoping — "the single most-repeated finding across four epics") + **Epic 3 retro AI-2**
> (fake-fidelity — "make the test fakes model production constraints"); `deferred-work.md` items —
> admin-permission (line 124), post-rerun call-count assertion (line 8), `getCurrencies()`→`config.json`
> wiring (line 24), `normalizeAmount()` truncate/raw-passthrough (line 104), `retireVoucher()`
> affected-row count (line 101, a precondition for un-gating `replace` per line 20);
> architecture **Data Architecture** company-scoped uniqueness (351), **Auth & Security** plugin ACL /
> POST+CSRF / GET-read-only (357–377), **Posting Contract** decimal-strings-never-floats (593),
> **Anti-Patterns** float amount matching (658) + GET admin route that mutates (659);
> NFR13 (decimal/minor-unit amounts, never floats, epics.md:111); NFR14 (admin mutations require staff
> auth + plugin ACL + POST/CSRF + audit; GET read-only, epics.md:113); epics Additional Requirement
> "company-scoped Registration Number, Consumer Number, active payment context… uniqueness" (epics.md:127).

**⚠️ SCOPE REALITY CHECK — read before coding.** This is a **structural-hardening + test-fidelity**
story, not a feature story. Three traps:

1. **Some items already partially exist.** A `testPostThenRerunCreatesExactlyOneTransaction` test
   already exists (`KuickPayPostingServiceTest.php:241-261`) and two best-in-class **constraint-enforcing
   fakes** already exist (`KuickPayVoucherReferenceFakeRepository`, `KuickPayReconcileFakeUniqueItemRepository`).
   Verify HEAD per the AC notes; **strengthen / propagate**, do not duplicate or rewrite working code.
2. **AC1 is a convention rollout, NOT a reformat.** The goal is to make omitting `company_id`
   *structurally impossible* — it is NOT to churn every model. Preserve the query *results* of methods
   that are already correctly scoped; only change *how* the scope is applied (route it through the
   un-omittable helper) and close the **8 identified gaps**.
3. **Two tables have NO `company_id` column** (`kuickpay_voucher_invoices`, `kuickpay_reconciliation_items`)
   — they are scoped via the parent join. A helper that blindly appends `where('company_id', …)` to
   their queries is a **fatal SQL error**. The convention MUST distinguish *directly-scoped* from
   *parent-scoped* tables. This is the #1 footgun of this story.

**No schema change and no plugin version bump are expected** (no new columns; the base-model convention,
`edit()` row-count surfacing, and `normalizeAmount()` rounding are all code-only). Leave the plugin at
`1.10.0` unless a concrete schema need is discovered (none identified). No new audit event name is added.

---

1. **(AC1 — `company_id` scoping is a structural guarantee, not per-method vigilance)**
   **Given** every `kuickpay_reconcile` plugin model query (and any gateway-side Record query),
   **When** a model method issues a `SELECT` / `UPDATE` / `DELETE` / `INSERT`,
   **Then** `company_id` scoping is applied through a **base helper/convention on `KuickpayReconcileModel`**
   (`plugins/kuickpay_reconcile/kuickpay_reconcile_model.php`) **unconditionally**, so a query that omits
   the tenant scope is impossible to express — caught by a required typed parameter at the helper boundary,
   not by reviewer attention.
   **And** the convention correctly handles **both** table classes:
   - *directly-scoped* tables (`kuickpay_vouchers`, `kuickpay_reconciliation_runs`, `kuickpay_audit_events`,
     `kuickpay_reconcile_locks`) → always inject `where('company_id', '=', $companyId)` (SELECT/UPDATE/DELETE)
     and require/inject `company_id` on INSERT;
   - *parent-scoped* child tables (`kuickpay_voucher_invoices`, `kuickpay_reconciliation_items` — **no
     `company_id` column**) → scope via a join to the owning voucher/run + that parent's `company_id`,
     **never** by a direct `company_id` filter on the child table.
   **And** the **8 known gaps close**: `KuickpayVouchers::get()` (`:125`, unscoped SELECT),
   `KuickpayReconciliationRuns::edit()` (`:66`, **unscoped UPDATE — the most dangerous gap**),
   `KuickpayVouchers::add()` (`:75`) / `KuickpayReconciliationRuns::add()` (`:57`) /
   `KuickpayAuditEvents::add()` (`:21`) (INSERTs that trust the caller to include `company_id`),
   `KuickpayVoucherInvoices::getByVoucherId()` (`:44`) / `getByVoucherIdForUpdate()` (`:58`) /
   `getByInvoiceId()` (`:119`) (child reads with no parent scope).
   **And** a **cross-company isolation regression test** proves a query/edit issued for company A never
   returns or mutates company B's rows, and that an INSERT/edit without a `company_id` cannot be issued.
   *(This test is only meaningful if the fakes enforce company scoping — see AC2; if a fake ignores
   `company_id`, this test passes vacuously, exactly the Epic-3 failure mode.)*
   **HEAD STATE:** No base scoped-query helper exists — `KuickpayReconcileModel extends AppModel` has only
   a constructor. Most read methods are already manually scoped; the 8 gaps above are not. This AC promotes
   the property from per-method vigilance to a structural convention (Epic 4 retro AI-8).

2. **(AC2 — test doubles model production constraints; a green suite stops manufacturing false confidence)**
   **Given** the plugin and gateway test doubles,
   **When** the **fake-fidelity checklist** is applied,
   **Then** fake repositories enforce the **real schema constraints** — the company-scoped UNIQUE keys
   `uniq_kuickpay_vouchers_consumer (company_id, consumer_number)`,
   `uniq_kuickpay_vouchers_reg (company_id, registration_number)`,
   `uniq_kuickpay_vouchers_active_context (company_id, active_context_key)` (NULL-insensitive),
   `uniq_kuickpay_items_run_voucher (run_id, voucher_id)`,
   `uniq_kuickpay_voucher_invoices_link (voucher_id, invoice_id)` — and the NOT-NULL columns the schema
   requires (e.g. `company_id`, `context_key`, `status`), **throwing on violation** exactly as MySQL would.
   **And** fake **Blesta amount sources** (the `Transactions` model fake `getApplied()`/`listResults()`,
   invoice-total readers, applied-amount providers) return **`decimal(12,4)` 4-decimal strings**
   (`'1000.0000'`, `'999.9999'`, `'100.0001'`) **systematically**, not the 2-decimal placeholders most
   fakes use today — because Blesta money columns are `decimal(12,4)` and a parser/normalizer that rejects
   the 4th decimal would route every real transaction to `manual_review` while the 2-dp fakes stay green
   (the Story 3-5 production bug).
   **And** fixtures include **both numeric and non-numeric transaction references** (the Story 3-2 bug: a
   purely numeric ref mistaken for a split thousands-separated amount) **and both blank/empty-string AND
   populated identities** (`consumer_number`/`registration_number` = `''` *and* a real value; today NULL is
   covered but empty-string is not).
   **And** a short, written **"fake-fidelity checklist"** is landed (the AI-2 deliverable) so the
   constraint set is documented once and future fakes/stories stay honest; the two existing
   constraint-enforcing fakes (`KuickPayVoucherReferenceFakeRepository`,
   `KuickPayReconcileFakeUniqueItemRepository`) are the reference templates.
   **And** every newly-strict fake keeps the relevant suite **GREEN** — strictness must reveal real bugs,
   not break clean tests; where a previously-passing test only passed because a fake was looser than the
   DB, that is a real finding to fix, not a test to weaken.

3. **(AC3 — remaining coverage gaps and correctness residuals close)**
   **Given** the admin controllers (`admin_vouchers.php`, `admin_main.php`, `admin_reconciliation.php`,
   `admin_manual_review.php`),
   **When** an action is dispatched,
   **Then** the controller's registered plugin permission is **explicitly enforced in the controller**
   (not merely assumed at the framework route) — every **mutating** action runs behind staff auth +
   plugin ACL + POST/CSRF and writes audit, and every **GET** route stays read-only (NFR14;
   architecture 357–377). In particular `admin_main`'s bulk-reconciliation `run()` (a POST mutation that
   today has **no explicit ACL check**) gates on its registered `bulk_reconcile`/`reconciliation`
   permission via the existing `staffGroupAllows()` pattern.
   **And** the posting **confirmed→post→rerun call-count is directly asserted** — a `confirmed_unposted`
   voucher posted once produces **exactly one** Blesta transaction `add()` **and one** `apply()`, and a
   rerun over the now-`posted` row produces **zero** further calls — driven through a **faithful** fake
   that actually persists `markPosted` (not a hand-mutated `status`), and exercised through
   `postConfirmed()`'s batch path, not only a direct `postVoucher()` call.
   **And** the `getCurrencies()`→`config.json` wiring is **covered against the real framework** — a test
   exercises the inherited `Gateway::loadConfig()` → `$this->config->currencies` → `getCurrencies()` path
   reading the real `config.json` (`["PKR"]`), rather than the empty-`NonmerchantGateway` stub that
   overrides `getCurrencies()` (the current harness cannot reach the production wiring). Now that Story
   5.1 stood up the live stack, attempt the real-framework path; if the component-local bootstrap
   genuinely cannot load the real base gateway, document the exact prerequisite honestly (NFR12) rather
   than claiming coverage.
   **And** `normalizeAmount()` **rounds rather than truncates and rejects invalid input** — in **both**
   copies (`components/gateways/nonmerchant/kuickpay/kuickpay.php:1487-1504` **and**
   `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:511-528`, which must stay
   byte-for-byte equivalent or the cross-side amount compare is no longer self-consistent) — using
   **decimal-string / bcmath math, never PHP floats** (NFR13; architecture 593, 658). A 4-decimal input
   rounds half-up to 2dp (`'100.0050'`→`'100.01'`, `'999.9999'`→`'1000.00'`); a non-numeric/negative input
   fails closed (returns a sentinel that can never compare equal) instead of being passed through raw.
   **And** `retireVoucher()` **surfaces the affected-row count** — `KuickpayVouchers::edit()`
   (`:102-117`, today returns `void`) returns the rows-affected, and `retireVoucher()`
   (`KuickPayVoucherReferenceService.php:256-279`, today returns `true` unconditionally) treats a 0-row
   no-op (wrong/zero `company_id`, already-`cancelled` row) as failure: **skip the audit and return
   `false`**, while still distinguishing an audit-write failure from a retire failure. *(Do NOT un-gate
   `replace`/`allow` in this story — this hardening is the documented precondition that makes un-gating
   safe later; deferred-work line 20.)*
   **HEAD STATE:** the rerun test exists but hand-mutates `status` and skips `postConfirmed()`; the
   currency test stubs the base gateway away; both `normalizeAmount()` copies truncate via
   `substr(str_pad(...,2,'0'),0,2)` and return raw on invalid; `retireVoucher()`/`edit()` ignore the
   affected-row count; `admin_main` has no explicit ACL check.

## Tasks / Subtasks

- [x] **Task 1 — AC1: introduce the structural company-scoping convention on the base model**
  - [x] 1.1 In `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php` (`KuickpayReconcileModel`), add a
        small set of scoped-query primitives that make tenant scope un-omittable. Suggested shape (match
        the file's legacy no-namespace style): `scopedSelect(string $table, int $companyId): Record` that
        returns a `Record` already carrying `->where("$table.company_id", '=', $companyId)`;
        `scopedUpdate(string $table, int $companyId, array $vars, array $fields, array $where = [])` and
        `scopedDelete(...)` that always append the `company_id` predicate; and
        `scopedInsert(string $table, int $companyId, array $vars, array $fields)` that **injects**
        `company_id` (and rejects a caller that smuggles a conflicting one). Each takes `int $companyId`
        as a **required** parameter — omission is a type error, not a silent leak.
  - [x] 1.2 Handle the **parent-scoped child tables** explicitly. `kuickpay_voucher_invoices` and
        `kuickpay_reconciliation_items` have **no `company_id` column** — add a documented convention
        (e.g. `scopedChildSelect($childTable, $parentTable, $fkColumn, int $companyId)`) that joins the
        child to its owning voucher/run and applies the **parent's** `company_id`. Do **not** route these
        through `scopedSelect` (it would emit `child.company_id` → fatal SQL). Document the two table
        classes at the top of the helper so the next author cannot mis-apply it.
  - [x] 1.3 Migrate the already-correct scoped read methods to the convention **without changing their
        results** (this is the "promote to structure" step, not a behavior change). Verify the suite stays
        green after each model file. Do not reformat unrelated code.
  - [x] 1.4 Close the 8 gaps:
        - `KuickpayVouchers::get()` (`:125`) → scope by `company_id` (or deprecate in favour of
          `getForCompany()`; pick one and update callers — `get()` currently has callers, so prefer
          adding the scope to avoid signature churn unless a caller cannot supply `company_id`).
        - `KuickpayReconciliationRuns::edit()` (`:66`) → add `->where('company_id', '=', $company_id)`
          (route through `scopedUpdate`). **Critical**: this is an unscoped cross-tenant UPDATE today.
        - `KuickpayVouchers::add()` (`:75`), `KuickpayReconciliationRuns::add()` (`:57`),
          `KuickpayAuditEvents::add()` (`:21`) → route through `scopedInsert` so `company_id` presence is
          enforced, not trusted.
        - `KuickpayVoucherInvoices::getByVoucherId()` (`:44`), `getByVoucherIdForUpdate()` (`:58`),
          `getByInvoiceId()` (`:119`) → parent-scope via join to `kuickpay_vouchers.company_id`. These
          callers must now pass `company_id`; thread it from the call sites
          (`KuickPayVoucherRepository::getInvoiceLinksForUpdate()` etc.).
  - [x] 1.5 Add a **cross-company isolation regression test** (per affected repository): seed rows for
        company A and company B; assert a company-A query/edit/insert never reads or mutates company-B
        rows, and that the helper cannot be called without a `company_id`. This test depends on AC2's
        company-aware fakes — sequence Task 2's fake upgrades first or in tandem so the test is not vacuous.
  - [x] 1.6 Audit the gateway side (`components/gateways/nonmerchant/kuickpay/`) for any direct Record
        query; the gateway should reach durable state only through plugin services, but confirm no
        gateway-local query leaks tenant scope. Report findings even if nil.

- [x] **Task 2 — AC2: make the fakes at least as strict as the database; land the checklist**
  - [x] 2.1 Extract a shared **fake-fidelity base/trait** (e.g. `tests/fakes/KuickPaySchemaFake*.php` or a
        trait reused by the repository fakes) modelling the real constraints, using the two existing
        constraint-enforcing fakes as templates: `KuickPayVoucherReferenceFakeRepository`
        (`KuickPayVoucherReferenceServiceTest.php:292-471`, enforces NOT-NULL `context_key` + UNIQUE
        `(company_id, active_context_key)` NULL-insensitive) and `KuickPayReconcileFakeUniqueItemRepository`
        (`KuickPayReconcileServiceTest.php:1560-1577`, enforces UNIQUE `(run_id, voucher_id)`).
  - [x] 2.2 Upgrade the **looser fakes** to enforce the schema UNIQUE/NOT-NULL keys (or swap them for the
        strict variants): `KuickPayReconcileFakeVoucherRepository` (`:1235`, add the consumer/registration/
        active-context UNIQUE keys), `KuickPayReconcileFakeItemRepository` (`:1544`, the non-unique twin —
        prefer the unique variant), `KuickPayIssuanceFakeVoucherRepository`
        (`KuickPayIssuanceServiceTest.php:71`), `KuickPayEvidenceValidatorFakeVoucherRepository`
        (`KuickPayEvidenceValidatorTest.php:454`), `KuickPayPostingFakeVoucherRepository`
        (`KuickPayPostingServiceTest.php:616`), and the gateway-side
        `KuickPayVoucherReferenceServiceFakeRepository`
        (`components/gateways/.../tests/KuickPayVoucherReferenceServiceTest.php:7`). Each upgrade must keep
        its suite green; a test that only passed because the fake was loose is a real finding.
  - [x] 2.3 Make fake **Blesta amount sources** return `decimal(12,4)` 4-dp strings systematically:
        `KuickPayPostingFakeTransactions` (`KuickPayPostingServiceTest.php:750-817`) `getApplied()`/
        `listResults()`/`applied_amount`, plus invoice-total/applied-amount providers
        (`KuickPayVoucherRepositoryFakeVoucherInvoiceModel:165`, `KuickPayEvidenceValidatorFakeInvoiceReader:439`).
        Add systematic 4-dp coverage (`'1000.0000'`, `'999.9999'`, `'100.0001'`) and a regression test that
        a 4-dp Blesta amount posts/normalizes correctly (the 3-5 trap; memory
        `[[kuickpay-blesta-decimal4-amount-trap]]`).
  - [x] 2.4 Diversify fixtures/identities: add a **non-numeric** and a **purely numeric** transaction-ref
        case exercised against the parser (the 3-2 trap), and add **empty-string** (`''`) identity variants
        alongside the existing NULL/populated ones for `consumer_number`/`registration_number`. Reuse the
        canonical fixture tree under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/`.
  - [x] 2.5 Land the written **fake-fidelity checklist** — a short `plugins/kuickpay_reconcile/tests/README.md`
        section (or `docs/kuickpay/`) enumerating: every schema UNIQUE/NOT-NULL key a repository fake must
        model; "Blesta money columns are `decimal(12,4)` — fakes return 4-dp strings"; "fixtures cover
        numeric + non-numeric refs and blank + populated identities"; and "a fake looser than the DB
        manufactures false green." This is the AI-2 deliverable.

- [x] **Task 3 — AC3a: explicit admin-permission enforcement (NFR14)**
  - [x] 3.1 Audit all four controllers' `preAction()`/actions. Today `admin_vouchers` gates recheck/review/
        cancel/diagnostics per-action via `staffGroupAllows()` (`kuickpay_reconcile_controller.php:36-79`)
        but the base **view** permission is route-assumed; `admin_main` (bulk reconcile) has **no explicit
        check at all**. Add explicit enforcement so each controller asserts its registered permission
        (`getPermissions()` in `kuickpay_reconcile_plugin.php:292-350`: `vouchers`/`bulk_reconcile`/
        `reconciliation`/`manual_review` + the action-scoped `recheck`/`review`/`cancel`/`diagnostics`).
  - [x] 3.2 Ensure every **mutating** action is POST + CSRF + staff-auth + audited, and every **GET** route
        is read-only (architecture 377, 659; NFR14). `admin_main::run()` (bulk reconcile) must gate on its
        permission and remain POST-driven. Do not add any GET route that mutates voucher state.
  - [x] 3.3 Tests: a `*`-only staff group is denied the gated actions (extends the Story-5.1 two-group ACL
        separation), and the bulk-reconcile action is denied without its permission. Reuse the existing ACL
        test seams; do not call live framework ACL if the harness stubs it — model it faithfully.

- [x] **Task 4 — AC3b: directly assert posting confirmed→post→rerun call-count**
  - [x] 4.1 Re-read `testPostThenRerunCreatesExactlyOneTransaction` (`KuickPayPostingServiceTest.php:241-261`).
        It asserts `assertCount(1, $transactions->adds)` but **hand-mutates** `$voucher->status='posted'`
        and exercises `postVoucher()` directly. Strengthen it to drive the **faithful** AC2 fake repository
        (which actually persists `markPosted`/`transitionFromConfirmedUnposted` so the rerun observes the
        real posted state) and assert exactly **one** `add()` **and one** `apply()` across both runs, then
        **zero** further calls on a third run.
  - [x] 4.2 Add a `postConfirmed()` **batch** idempotency test: a batch containing a `confirmed_unposted`
        voucher posts it once; a second `postConfirmed()` over the now-`posted` batch creates no second
        transaction (`already_posted` outcome). Targets `KuickPayPostingService::postConfirmed()`
        (`:42-75`) + `postVoucher()` (`:77-204`), not just the direct call.

- [x] **Task 5 — AC3c: cover `getCurrencies()`→`config.json` wiring against the real framework**
  - [x] 5.1 Add a test that exercises the inherited `Gateway::loadConfig()` →
        `$this->config->currencies` → `getCurrencies()` path reading the real
        `components/gateways/nonmerchant/kuickpay/config.json` (`"currencies": ["PKR"]`), instead of the
        empty-`NonmerchantGateway` stub + `getCurrencies()` override
        (`KuickPayCurrencyEligibilityTest.php:23-36`). Prefer loading the real base gateway now that the
        Story 5.1 live stack exists.
  - [x] 5.2 If the component-local bootstrap genuinely cannot load the real `NonmerchantGateway`/`loadConfig`
        wiring, keep the load-bearing override + `testConfigDeclaresOnlyPkr()` and **document the exact
        missing prerequisite** in the Dev Agent Record (NFR12 — disclose what ran vs. what was stubbed; do
        not claim real-framework coverage that did not run).

- [x] **Task 6 — AC3d: `normalizeAmount()` rounds + rejects invalid (both copies, no floats)**
  - [x] 6.1 Rewrite **both** copies identically — `components/gateways/nonmerchant/kuickpay/kuickpay.php:1487-1504`
        and `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:511-528`. Replace the
        `substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2)` truncation with **half-up rounding to 2dp using
        decimal-string / bcmath math, never PHP floats** (NFR13; architecture 658; Story 5.4 Dev Notes
        explicitly warned "do not introduce float math"). Reject non-numeric/negative input with a
        fail-closed sentinel (e.g. return `null`/`''` that can never compare equal) rather than returning
        the raw string.
  - [x] 6.2 **Keep the two copies byte-for-byte equivalent** — the cross-side amount comparison is only
        self-consistent if both normalize identically. `KuickPayVoucherReferenceService` is exercised by
        **both** the plugin and gateway suites (memory `[[kuickpay-voucher-reference-dual-test-suites]]`);
        run both after editing it.
  - [x] 6.3 Tests in both suites: `'100.0050'`→`'100.01'`, `'999.9999'`→`'1000.00'`, `'1000.0000'`→`'1000.00'`,
        `'1,000.50'`→`'1000.50'`, and invalid (`'abc'`, `'-5'`, `''`) → fail-closed sentinel (never equal to
        a valid amount). Drive these with the AC2 4-dp fixtures so the rounding path is real.

- [x] **Task 7 — AC3e: `retireVoucher()` surfaces affected-row count**
  - [x] 7.1 `KuickpayVouchers::edit()` (`models/kuickpay_vouchers.php:102-117`) → return the affected-row
        count from `Record->update()` (use the Blesta `Record->affectedRows()` / returned count) instead of
        `void`. Preserve the existing `company_id`/`id` scope and the `unset($vars['company_id'])` +
        `date_updated` behavior. Check every caller of `edit()` tolerates a non-void return.
  - [x] 7.2 `retireVoucher()` (`KuickPayVoucherReferenceService.php:256-279`) → branch on the row count:
        on **0 rows** (no-op: wrong/zero `company_id`, already-`cancelled`) **skip the
        `voucher.replaced` audit and return `false`**; on success, record audit + return `true`; keep a
        thrown audit-write distinguishable from a retire failure (don't let an audit `Throwable` masquerade
        as "retire failed" — log/swallow per the best-effort audit discipline, but the retire result
        reflects the edit's row count). Do **not** un-gate `replace`/`allow`.
  - [x] 7.3 Tests (both suites that load the service): retire a matching row → `true` + audit;
        retire a non-matching/zero-`company_id`/already-`cancelled` row → `false` + **no** audit.

- [x] **Task 8 — Verification & evidence**
  - [x] 8.1 `php -l` on every changed PHP file under **both** PHP 8.3 (production runtime, ea-php83) and the
        8.2 source-floor — no 8.3-only syntax/APIs (project-context.md:39; memory
        `[[kuickpay-php82-toolchain-now-available]]`).
  - [x] 8.2 Plugin suite green: `cd plugins/kuickpay_reconcile && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`.
        Capture the **actual baseline first** (expected ≈ plugin 189/189 from Story 5.4; confirm before
        claiming a delta).
  - [x] 8.3 Gateway suite green **modulo the disclosed pre-existing `empty-currency` baseline red**
        (memory `[[kuickpay-failclosed-empty-currency-red]]`):
        `cd components/gateways/nonmerchant/kuickpay && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`.
        Do **not** use `-c build/phpunit.xml` (project-context.md:74). Expected baseline ≈ gateway 239 + 1
        disclosed red. Disclose the baseline red as pre-existing; do not attribute it to this story.
  - [x] 8.4 Update `deferred-work.md`: mark the closed items as CLOSED-by-5.5 with one-line notes —
        admin-permission (line 124), post-rerun call-count (line 8), `getCurrencies` wiring (line 24),
        `normalizeAmount` truncate/raw (line 104), `retireVoucher` affected-row (line 101, and note the
        line-20 un-gating precondition is now satisfied). Keep the `docs(kuickpay)`/`_bmad-output` doc
        commit **separate** from runtime commits (project-context.md:104).
  - [x] 8.5 Optional sanitized verification record under `docs/kuickpay/` per the 5.3/5.4 cadence —
        placeholders only, **NO** `config/blesta.php`/DB creds/host/KuickPay creds/raw SOAP/customer PII
        (NFR8). If the real-framework currency test (Task 5) ran against the live stack, record exactly what
        ran vs. what was stubbed (NFR12).

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **AC1 is "promote to structure," not "rewrite every model."** Preserve the *results* of already-scoped
  methods; change only *how* the scope is applied (route through the un-omittable helper) and close the 8
  gaps. Broad reformat/churn of legacy model files violates project-context (do not mass-edit legacy files).
- **NEVER apply `company_id` directly to `kuickpay_voucher_invoices` or `kuickpay_reconciliation_items`** —
  they have **no `company_id` column** (confirmed: install schema `kuickpay_reconcile_plugin.php`; these
  tables only carry `voucher_id`/`run_id`). Scope them via the parent join. A direct `where('company_id',…)`
  on these is a fatal SQL error, not a leak fix.
- **Two `normalizeAmount()` copies must stay byte-for-byte equal.** The amount comparison is self-consistent
  only if the gateway copy (`kuickpay.php:1487`) and the plugin copy (`KuickPayVoucherReferenceService.php:511`)
  normalize identically. Edit both; run both suites.
- **No PHP floats in amount math (NFR13).** `round()`/`number_format()` operate on floats — architecture 658
  forbids float amount matching and Story 5.4 explicitly warned against introducing float math here. Use
  bcmath / decimal-string half-up rounding.
- **Do NOT un-gate `replace`/`allow`.** AC3's `retireVoucher()` hardening is the *precondition* that makes
  un-gating safe (deferred-work line 20); the actual un-gating is a separate, deliberate decision — out of
  scope here.
- **Strict fakes must reveal bugs, not break clean tests.** When a previously-green test fails after a fake
  is tightened, that is usually a **real bug** the loose fake was hiding (the Epic-3 signature failure mode,
  4-of-8 stories). Fix the production code, not the fake — unless the fake is wrong about the schema.
- **No new audit event name.** `voucher.replaced` already exists/registered; `retireVoucher()` keeps using
  it. Adding a genuinely new event name would require the 4-site event registry (presenter
  `EVENT_LABEL_KEYS`, `language/en_us/admin_vouchers.php`, `KuickPayVoucherListPresenterTest::KNOWN_EVENTS`
  + count) or `KuickPayVoucherListPresenterTest` fails (memory `[[kuickpay-run-detail-audit-allowlist]]`).
- **No schema / version bump expected.** No new columns; helper, row-count surfacing, and rounding are
  code-only. Leave the plugin at `1.10.0` unless a concrete schema need arises.
- **Fail-closed / honest reporting (NFR9, NFR12).** Audit writes stay best-effort (never abort a batch).
  Report exactly what ran on which PHP version and what was stubbed vs. run against the real framework;
  disclose the `empty-currency` baseline red, don't attribute it to this change.

### Architecture compliance (must follow)

- **Ownership boundary (architecture 518–526, 663–673, 781–802):** the **plugin** owns Voucher
  persistence, reconciliation, posting, audit, schema lifecycle; the **gateway** owns checkout/reference
  display + credential storage/masking. The base scoped-query convention belongs in the **plugin** base
  model (`KuickpayReconcileModel`). `KuickPayVoucherRepository` "owns Voucher persistence reads/writes"
  (architecture 783) — its model-layer queries are the primary migration target. Do not move plugin
  persistence into the gateway.
- **Company-scoped uniqueness is an architectural invariant** (architecture 351; epics Additional
  Requirement 127): Registration Number, Consumer Number, active payment context, and KuickPay references
  are company-scoped unique. AC1's structural scoping and AC2's fake UNIQUE keys both enforce this.
- **Auth & Security (architecture 357–377; NFR14, epics 113):** admin actions use plugin ACL with separate
  permissions (view / recheck / review / cancel / diagnostics); **admin mutations require POST + staff auth
  + ACL + CSRF**; **GET is read-only**. The plugin already registers these permissions
  (`getPermissions()` `kuickpay_reconcile_plugin.php:292-350`); AC3a makes the controllers *enforce* them
  explicitly.
- **Posting Contract / amounts (architecture 581–593, 658):** amounts compared via normalized decimal
  strings or integer minor units, **never PHP floats**; currency in every check. `normalizeAmount()` is on
  this path — AC3d keeps it float-free.
- **Audit payloads use redacted fields only** (architecture 634); `voucher.replaced` is the existing
  retire event. Audit must never abort the operation (best-effort wrap).

### Files to modify (UPDATE) — and their current state

| File | AC | Current state → change |
|---|---|---|
| `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php` | AC1 | `extends AppModel`, constructor only — **no scoped helper**. Add the scoped-query convention (direct + parent-scoped variants). |
| `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` | AC1, AC3e | `get()` `:125` unscoped SELECT; `add()` `:75` caller-trusted INSERT; `edit()` `:102-117` returns `void`. Route through helper; **`edit()` returns affected-row count**. |
| `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php` | AC1 | `edit()` `:66` **unscoped UPDATE (critical)**; `add()` `:57` caller-trusted INSERT. Scope both. |
| `plugins/kuickpay_reconcile/models/kuickpay_audit_events.php` | AC1 | `add()` `:21` caller-trusted INSERT. Route through `scopedInsert`. |
| `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php` | AC1 | `getByVoucherId()` `:44`, `getByVoucherIdForUpdate()` `:58`, `getByInvoiceId()` `:119` — child reads, **no company_id col** → parent-scope via voucher join. |
| `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` | AC3d, AC3e | `normalizeAmount()` `:511-528` truncates+raw; `retireVoucher()` `:256-279` returns `true` unconditionally. Round+reject; branch on row count. |
| `components/gateways/nonmerchant/kuickpay/kuickpay.php` | AC3d | `normalizeAmount()` `:1487-1504` (mirror copy) — same fix, kept identical. |
| `plugins/kuickpay_reconcile/controllers/admin_main.php` | AC3a | `preAction()` `:8-26` only `requireLogin()` — **no ACL**; `run()` is a POST mutation. Add explicit `bulk_reconcile`/`reconciliation` enforcement. |
| `plugins/kuickpay_reconcile/controllers/admin_vouchers.php` | AC3a | `preAction()` `:14-41` route-assumes the base `vouchers` view permission; per-action gates exist. Make the view permission explicit. |
| `plugins/kuickpay_reconcile/controllers/admin_reconciliation.php`, `admin_manual_review.php` | AC3a | Audit + enforce their registered (`reconciliation`/`manual_review`) permissions; GET stays read-only. |

**Test files (UPDATE/ADD):** plugin — `KuickPayPostingServiceTest.php` (AC3b), `KuickPayReconcileServiceTest.php`
+ `KuickPayIssuanceServiceTest.php` + `KuickPayEvidenceValidatorTest.php` + `KuickPayVoucherRepositoryTest.php`
+ `KuickPayVoucherReferenceServiceTest.php` (AC1/AC2/AC3d/AC3e), a controller-ACL test (AC3a), the new
fake-fidelity base/trait + checklist `README` (AC2), fixtures under `tests/fixtures/kuickpay/` (AC2);
gateway — `KuickPayCurrencyEligibilityTest.php` (AC3c), `KuickPayVoucherReferenceServiceTest.php` (AC3d/AC3e
mirror + fake upgrade), `KuickPayVoucherGatewayHelpersTest.php` (AC2 fake fidelity).
**Docs:** `deferred-work.md` (closures); optional `docs/kuickpay/` verification record.

**DO NOT edit:** `components/gateways/lib/gateway.php` (shared base for every Blesta gateway), core
`app/models/*` (e.g. `transactions.php`), any ionCube-protected file, `config/blesta.php`. Do not
mechanically modernize/reformat legacy model files (project-context.md:88, 91, 109, 126).

### Previous Story Intelligence (Story 5.4 — `done`, baseline `f44bd841`)

5.4 closed the audit/redaction completeness items; the patterns it established constrain 5.5:

- **Test-fake fidelity is the recurring Epic-3 lesson (5.4 Dev Notes:155 cites AI-2 directly):** "fakes must
  model NOT-NULL/UNIQUE and status-guarded writes faithfully (and Blesta `decimal(12,4)` 4-dp amount
  strings) or they mask the bug." 5.5 AC2 makes this structural — exactly what 5.4 leaned on for its
  idempotency test (the `(run_id, voucher_id)` unique fake).
- **No nested transactions in Blesta; audit writes are best-effort, post-rollback** — preserve when touching
  `retireVoucher()`'s audit. Never call a self-transacting `create()` inside an outer `begin()`.
- **`(run_id, voucher_id)` UNIQUE on `kuickpay_reconciliation_items`** and the company-scoped voucher unique
  keys are the real constraints AC2's fakes must model (memory `[[kuickpay-bulk-idempotency-unique-item-key]]`).
- **Reason-token vs. new-event-name:** `voucher.replaced` already registered; no registry change for
  `retireVoucher()`.
- **PHP 8.3 is the runtime** (ea-php83, ionCube 15); "8.2" is a **source-floor** — `php -l` under both.
- **Test invocation:** `--bootstrap tests/bootstrap.php tests`, never `-c build/phpunit.xml`. External
  runner `/root/tools/phpunit-8.5/vendor/bin/phpunit`. 5.4 final baseline: **plugin 189/189**, **gateway 239**
  modulo the disclosed `empty-currency` red.
- **Scope-reality-check discipline (5.4 reused here):** verify HEAD before implementing; some items
  (the rerun test, two strict fakes) already exist — strengthen/propagate, don't re-author.

### Git Intelligence (recent, relevant)

- `c18e27af` (HEAD) `docs(kuickpay): mark 5.4 review complete` — 5.5 baseline.
- `9f3a860a` / `15f980cb` / `d5e3a5fb` — 5.4 review fixes (leak patterns, alias redaction, credential
  object-graph masking): the **fake/fixture diversification** muscle AC2 extends.
- `01682753` (via 5.4 notes) `fix(kuickpay): record bulk reconcile rollback failures` — the best-effort
  audit-after-rollback discipline `retireVoucher()` must respect.
- **Commit style:** `<type>(kuickpay): <summary>` (`fix`/`feat`/`refactor`/`test`/`docs`), imperative,
  ≤72 chars; per-logical-unit commits; keep `_bmad-output/` + `docs/kuickpay/` doc commits **separate** from
  runtime commits (project-context.md:101-104). Reasonable commit slicing for 5.5: (1) base scoped helper +
  the 8 gap closures, (2) fake-fidelity base/trait + fake upgrades + checklist, (3) `normalizeAmount`
  rounding (both copies), (4) `retireVoucher`/`edit` row-count, (5) admin-permission enforcement, (6)
  currency-wiring + posting-rerun tests, (7) `docs` closures.

### Project Structure Notes

- Plugin tree: `plugins/kuickpay_reconcile/{kuickpay_reconcile_model.php (base model — AC1 helper home),
  models/*, lib/*, controllers/*, tests/*, kuickpay_reconcile_plugin.php (schema + getPermissions),
  config.json}`. Gateway tree: `components/gateways/nonmerchant/kuickpay/{kuickpay.php, lib/*, tests/*,
  config.json}`. No new files outside these trees except test fakes/fixtures under
  `plugins/kuickpay_reconcile/tests/` and the fake-fidelity checklist doc.
- Style: match each file's local conventions — legacy global classes here (no namespaces, no
  `declare(strict_types=1)`); short array syntax, single quotes, LF, one space around operators
  (component-local `PSR2 Transitional` PHPCS). No broad reformat of legacy model or language files.
- `KuickPayVoucherReferenceService` is loaded/tested by **both** the plugin and gateway suites
  (the gateway suite `require_once`s the plugin lib) — memory
  `[[kuickpay-voucher-reference-dual-test-suites]]`; run **both** after any edit to it (AC3d/AC3e).

### References

- [Source: epics.md#Story-5.5 (957–977)] — ACs + closure list (Epic 4 AI-8, Epic 3 AI-2; NFR14).
- [Source: epics.md] — NFR13 decimal/minor-unit, never floats (111); NFR14 admin mutations POST/ACL/CSRF/audit,
  GET read-only (113); Additional Requirement company-scoped uniqueness (127); NFR12 honest verification (109).
- [Source: epic-4-retro-2026-06-13.md#AI-8] — "Structurally enforce company-scoping… a base scoped-query
  helper (or model convention) applies `company_id` unconditionally, so the leak class can't recur by
  omission." (Pattern #4: caught-and-fixed 4 epics, recurs for lack of structure.)
- [Source: epic-3-retro-2026-06-11.md#AI-2] — "Make the test fakes model production constraints… fake
  repositories enforce NOT NULL / UNIQUE; fake Blesta amount sources return `decimal(12,4)` 4-decimal
  strings; fixtures include both numeric and non-numeric transaction refs and blank-vs-populated identities.
  Land a short 'fake-fidelity' checklist." (Pattern #1: fakes hid production bugs in 4 of 8 Epic-3 stories.)
- [Source: deferred-work.md] — admin-permission (124), post-rerun call-count (8), `getCurrencies` wiring
  (24), `normalizeAmount` truncate/raw (104), `retireVoucher` affected-row + un-gating precondition (101, 20).
- [Source: architecture.md] — Data Architecture / company-scoped uniqueness (327–355); Auth & Security /
  plugin ACL + POST+CSRF + GET-read-only (357–377); Posting Contract / decimal-strings-never-floats
  (581–593); Anti-Patterns float matching (658) + GET admin mutate (659); Ownership/Service Boundaries
  (518–526, 781–802).
- [Source: kuickpay_reconcile_plugin.php] — install schema UNIQUE/NOT-NULL keys (`uniq_kuickpay_vouchers_consumer`
  / `_reg` / `_active_context`, `uniq_kuickpay_items_run_voucher`, `uniq_kuickpay_voucher_invoices_link`);
  `amount` = `varchar(20)` (plugin) vs. Blesta source `decimal(12,4)`; `getPermissions()` (292–350).
- [Source: models/kuickpay_vouchers.php:75,102-117,125] / `kuickpay_reconciliation_runs.php:57,66` /
  `kuickpay_audit_events.php:21` / `kuickpay_voucher_invoices.php:44,58,119] — AC1 gap sites.
- [Source: KuickPayVoucherReferenceService.php:256-279,511-528] — `retireVoucher()` + `normalizeAmount()`.
- [Source: kuickpay.php:700-703,1487-1504] + config.json:11-13 + KuickPayCurrencyEligibilityTest.php:23-36 —
  AC3c currency wiring; AC3d gateway `normalizeAmount` copy.
- [Source: KuickPayPostingServiceTest.php:241-261] + KuickPayPostingService.php:42-75,77-204 — AC3b rerun.
- [Source: KuickPayVoucherReferenceServiceTest.php:292-471] + KuickPayReconcileServiceTest.php:1235,1544,1560-1577] —
  AC2 fake templates (strict) vs. loose fakes to upgrade.
- [Source: kuickpay_reconcile_controller.php:36-79] + controllers/{admin_vouchers.php:14-41, admin_main.php:8-26} —
  AC3a ACL pattern + gaps.
- [Source: project-context.md] — PHP 8.3 runtime / 8.2 source-floor (22, 39); test runner (72–74); no
  broad legacy reformat (88, 109); ionCube/base-file no-edit (91, 126); commit/doc-separation (101–104).
- Memory: `[[kuickpay-blesta-decimal4-amount-trap]]`, `[[kuickpay-voucher-reference-dual-test-suites]]`,
  `[[kuickpay-bulk-idempotency-unique-item-key]]`, `[[kuickpay-failclosed-empty-currency-red]]`,
  `[[kuickpay-php82-toolchain-now-available]]`, `[[kuickpay-admin-list-blesta-footguns]]`,
  `[[kuickpay-parser-single-identity-contract]]`, `[[kuickpay-run-detail-audit-allowlist]]`.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `claude-opus-4-8[1m]`.

### Debug Log References

- Plugin suite: `cd plugins/kuickpay_reconcile && <ea-php83> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` → **214/214** (baseline 189/189).
- Gateway suite: `cd components/gateways/nonmerchant/kuickpay && <ea-php83> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` → **250 tests, 1 failure** = the pre-existing disclosed `empty-currency` baseline red (NOT this story).
- `php -l` clean on all 37 changed PHP files under **both** ea-php83 (8.3 runtime) and ea-php82 (8.2 source-floor).
- Sanitized verification record: `docs/kuickpay/structural-company-scoping-verification.md`.

### Completion Notes List

- **AC1 — structural company-scoping.** Added the un-omittable scoped-query convention to `KuickpayReconcileModel` (`scopedSelect`/`scopedUpdate`/`scopedDelete`/`scopedInsert` for directly-scoped tables; `scopedChildSelect` for the parent-scoped child tables `kuickpay_voucher_invoices`/`kuickpay_reconciliation_items` which have NO `company_id` column). Each takes a required `int $companyId`, so omitting the tenant is a type error. Closed the 8 gaps: removed the unscoped `get()` (sole caller now uses `getForCompany()`); routed the unscoped cross-tenant `KuickpayReconciliationRuns::edit()` UPDATE through `scopedUpdate`; routed the three `add()` INSERTs through `scopedInsert`; parent-scoped the three child reads via a join to `kuickpay_vouchers.company_id`. Threaded `company_id` through `getWithInvoices()`, `getInvoiceLinksForUpdate()`, and the run repo's `close()`/`updateCursor()`. Gateway side audited: no direct Record query leaks tenant scope. ✅ Resolved deferred-work admin/scoping classes (Epic 4 retro AI-8).
- **AC2 — fake fidelity.** Extracted `tests/fakes/KuickPayFakeVoucherConstraints.php` modelling NOT-NULL + the company-scoped UNIQUE keys (consumer/registration/active-context, NULL-insensitive), reused by the voucher-reference repository fakes. Made the Blesta amount-source fakes return `decimal(12,4)` 4-dp strings systematically and proved the minor-unit parser tolerates them + fails closed on sub-paisa. Tightened the looser fakes (company-scoped reads, parent-scoped locked reads, count-returning `edit()`, the `(run_id, voucher_id)` unique on the item fake). Added a non-numeric transaction-ref case and empty-string-vs-NULL identity coverage, the cross-company isolation regression test, and the written fake-fidelity checklist (`tests/README.md`, the AI-2 deliverable).
- **AC3a — admin permissions.** Each controller now asserts its registered permission explicitly (`requirePagePermission`); `admin_main::run()` gates on `bulk_reconcile` (`*`). The raw-access-list decision is extracted to the unit-tested pure `KuickPayAclDecision`. ✅ Resolved deferred-work line 124.
- **AC3b — posting call-count.** The posting fake now faithfully persists `markPosted`; the rerun test asserts exactly one `add()`+one `apply()` then zero on a third run, plus a `postConfirmed()` batch idempotency test. ✅ Resolved deferred-work line 8.
- **AC3c — currency wiring.** `KuickPayCurrencyWiringTest` exercises the REAL `Gateway::loadConfig()`→`getCurrencies()` path reading the real `config.json` (`['PKR']`) via a minimal concrete subclass of the real base; Kuickpay inherits `getCurrencies()` with no override. NFR12: the rest of the gateway suite still stubs `NonmerchantGateway`; disclosed exactly what ran vs. stubbed in the verification record. ✅ Resolved deferred-work line 24.
- **AC3d — normalizeAmount.** Both copies (gateway + plugin service) half-up round to 2 dp with bcmath decimal-string math (no PHP floats) and fail closed to `''` on invalid/negative input; kept byte-for-byte identical. ✅ Resolved deferred-work line 104.
- **AC3e — retireVoucher.** `KuickpayVouchers::edit()` returns the affected-row count; `retireVoucher()` treats a 0-row no-op as failure (skips audit, returns `false`), keeps the audit best-effort, and does NOT un-gate `replace`/`allow`. ✅ Resolved deferred-work line 101; satisfies the line-20 un-gating precondition.
- **No schema change, no version bump** (plugin stays `1.10.0`); no new audit event name.

### File List

Runtime — gateway:
- `components/gateways/nonmerchant/kuickpay/kuickpay.php`

Runtime — plugin:
- `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php`
- `plugins/kuickpay_reconcile/kuickpay_reconcile_controller.php`
- `plugins/kuickpay_reconcile/lib/KuickPayAclDecision.php` (new)
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconciliationRunRepository.php`
- `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php`
- `plugins/kuickpay_reconcile/lib/KuickPayPostingService.php`
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconciliation_runs.php`
- `plugins/kuickpay_reconcile/models/kuickpay_audit_events.php`
- `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php`
- `plugins/kuickpay_reconcile/models/kuickpay_reconcile_locks.php`
- `plugins/kuickpay_reconcile/controllers/admin_main.php`
- `plugins/kuickpay_reconcile/controllers/admin_vouchers.php`
- `plugins/kuickpay_reconcile/controllers/admin_reconciliation.php`
- `plugins/kuickpay_reconcile/controllers/admin_manual_review.php`
- `plugins/kuickpay_reconcile/language/en_us/admin_main.php`
- `plugins/kuickpay_reconcile/language/en_us/admin_reconciliation.php`
- `plugins/kuickpay_reconcile/language/en_us/admin_manual_review.php`

Tests / fixtures — plugin:
- `plugins/kuickpay_reconcile/tests/bootstrap.php`
- `plugins/kuickpay_reconcile/tests/fakes/KuickPayFakeVoucherConstraints.php` (new)
- `plugins/kuickpay_reconcile/tests/README.md` (new — fake-fidelity checklist)
- `plugins/kuickpay_reconcile/tests/KuickPayCompanyScopeIsolationTest.php` (new)
- `plugins/kuickpay_reconcile/tests/KuickPayAclDecisionTest.php` (new)
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherReferenceServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayIssuanceServiceTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayEvidenceValidatorTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherRepositoryTest.php`
- `plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php`
- `plugins/kuickpay_reconcile/tests/integration/posting_safety_hardening_check.php`

Tests — gateway:
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayCurrencyWiringTest.php` (new)
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php`
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php`
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php`

Docs:
- `docs/kuickpay/structural-company-scoping-verification.md` (new)
- `_bmad-output/kuickpay/implementation-artifacts/deferred-work.md` (closures)

### Change Log

| Date | Change |
|---|---|
| 2026-06-15 | AC1: base-model company-scoping convention + 8 gap closures + caller threading (`e61638e0`). |
| 2026-06-15 | AC3d: `normalizeAmount()` half-up rounding + fail-closed sentinel, both copies (`aeabbfa3`). |
| 2026-06-15 | AC3e: `retireVoucher()` fails on a 0-row scoped edit; `edit()` surfaces affected-row count (`91e241be`). |
| 2026-06-15 | AC2: fake-fidelity trait + 4dp amounts + diversified fixtures + isolation test + checklist (`0af90536`). |
| 2026-06-15 | AC3a: explicit per-controller permission enforcement + pure `KuickPayAclDecision` (`baf398c4`). |
| 2026-06-15 | AC3b: posting confirmed→post→rerun call-count + batch idempotency tests (`99fd71a3`). |
| 2026-06-15 | AC3c: real-framework `getCurrencies()`→`config.json` wiring test (`547031cf`). |
| 2026-06-15 | Docs: deferred-work closures + sanitized verification record; story → review. |
