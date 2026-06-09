# Story 0.1: Confirm KuickPay Contract and Capture Sanitized Fixtures

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator and developer,
I want the KuickPay integration contract confirmed and sanitized fixtures captured before payment-truth logic is built,
so that voucher issuance and payment posting rely on verified evidence rather than assumed response formats.

> **What this story IS:** a documentation + fixture-capture **release gate**. It produces (a) a contract-confirmation document, (b) a sanitized fixture set for every required KuickPay response case, (c) an expected normalized-status mapping that Story 3.2's parser will implement against, and (d) recorded rate-limit/polling guidance.
>
> **What this story is NOT:** it writes **zero runtime PHP business logic**. No gateway, no plugin, no parser, no SOAP client, no posting code. Those are Epics 1–3. Payment posting stays **disabled** until this gate is approved.

## Acceptance Criteria

**AC1 — Contract confirmed, nothing hard-coded.**
**Given** the production target and integration contract are being confirmed
**When** Phase 0 validation runs
**Then** the production Blesta version (5.13 stable versus 6.0 beta compatibility), KuickPay endpoint/WSDL, accepted date formats (due, expiry, issue, transaction), the Consumer Number formula for this merchant, and credential separation (voucher versus inquiry) are documented and approved
**And** no production credential, Institution ID, endpoint, or fallback value is hard-coded into business logic as a result.

**AC2 — Sanitized fixtures captured for every required case.**
**Given** KuickPay responses are obtained from a live or sandbox source
**When** fixtures are captured
**Then** sanitized fixtures exist for `InsertVoucher` (success, duplicate, invalid credentials, malformed result, timeout), `BillPaymentInquiry` (pending, paid exact amount, amount mismatch, expired, unknown), and Bulk Reconciliation (matched paid, unmatched, malformed XML)
**And** passwords, unredacted SOAP envelopes, customer secrets, and environment-specific values are excluded.

**AC3 — Rate limit / polling guidance recorded (or conservative default).**
**Given** rate limits and polling guidance are initially unknown
**When** Phase 0 completes
**Then** documented KuickPay rate limits and recommended polling/backoff guidance are recorded
**Or** they are explicitly flagged as unavailable with a conservative default to use until confirmed.

**AC4 — Posting stays disabled; unknown ≠ paid.**
**Given** Phase 0 has not been approved
**When** implementation reaches voucher success-path handling (Story 2.3) or parser/posting work (Epic 3)
**Then** payment posting remains disabled
**And** those slices must not consume unverified KuickPay status codes; unknown responses continue to map to retry or Manual Review, never paid.

## Non-Negotiables (read before any task)

1. **No runtime PHP / no business logic.** No gateway, plugin, parser, SOAP client, schema, or posting code is written in this story.
2. **No writes outside `docs/kuickpay/`.** Do not create `plugins/kuickpay_reconcile/` or `components/gateways/nonmerchant/kuickpay/`, and do not edit `.htaccess`, Composer files, or any app code.
3. **No "verified" claim without live/sandbox evidence.** Fixtures built only from observed formats are `synthetic_from_observed_format` and cannot satisfy the gate.
4. **Posting stays disabled and the dev never self-approves the gate.** Gate approval is a human sign-off; leave it `PENDING_HUMAN_APPROVAL`.
5. **Fail closed.** Unknown / malformed / ambiguous evidence maps to `retry` or `manual_review`, never `posted`.
6. **No secrets anywhere.** No real passwords, Institution ID, customer data, unredacted SOAP, or `config/blesta.php` values in any doc or fixture.

## Tasks / Subtasks

- [ ] **Task 1 — Create the contract-confirmation document** `docs/kuickpay/phase-0-contract.md` (AC: #1, #3, #4)
  - [ ] 1.1 Document the **production Blesta version** decision: record repo evidence (this checkout is `6.0.0-b1`) and the research finding that 6.0.0 Beta 1 is non-production/unsupported while 5.13 is current stable. Add a field `production_blesta_version` with status `APPROVED` or `UNCONFIRMED — requires operations confirmation`. Do NOT silently assume; this is an operator decision.
  - [ ] 1.2 Document the **KuickPay endpoint and WSDL** as **two separate configurable fields** for production AND sandbox: `kuickpay_soap_endpoint` (the SOAP service URL) and `kuickpay_wsdl_url` (the WSDL document a `SoapClient` consumes — typically the ASMX URL with `?WSDL`). The public ASMX base `https://app.kuickpay.com/kuickpaycoreapi/api.asmx` is an **example only**, not a production default. State that the real production endpoint/WSDL is an Admin Setting, never hard-coded.
  - [ ] 1.3 Document **accepted date formats** for `DueDate`, `ExpiryDate`, `IssueDate`, and `TransactionDate`. Mark each `confirmed` (with the format string) or `UNCONFIRMED` with a conservative default and a note that the format must be centrally normalized until KuickPay confirms.
  - [ ] 1.4 Document the **Consumer Number formula** for this merchant: confirm whether `consumer_number = institution_id + registration_number` and `registration_number = random_prefix + invoice_id` hold for HosterPK's KuickPay account. Mark `confirmed`/`UNCONFIRMED`. Keep both formats described as configurable.
  - [ ] 1.5 Document **credential separation**: whether voucher and inquiry credentials are separate pairs or one pair serves both, plus the same-as-voucher policy. Mark `confirmed`/`UNCONFIRMED`.
  - [ ] 1.6 Document **rate limits and polling/backoff guidance** (AC3): record any KuickPay-stated limits, OR explicitly flag `rate_limits: UNAVAILABLE` and record a **conservative default** to use until confirmed (e.g., single-reference inquiry on a bounded schedule with jitter/backoff, bounded bulk batch by date, no unbounded polling loops — consistent with NFR7).
  - [ ] 1.7 Add a **"No hard-coding" assertion**: confirm this story introduces no PHP business logic, therefore no production credential / Institution ID / endpoint / fallback phone / fee / conversion rate is hard-coded. All such values are future Admin Settings.

- [ ] **Task 2 — Capture sanitized fixtures** under `docs/kuickpay/fixtures/` (AC: #2)
  - [ ] 2.1 `InsertVoucher` cases → `docs/kuickpay/fixtures/insert-voucher/`: `success.xml`, `duplicate.xml`, `invalid-credentials.xml`, `malformed.xml`, and `timeout.md` (transport-outcome descriptor — timeout has no response body; capture the `SoapFault`/connection-timeout shape and expected handling instead of an envelope).
  - [ ] 2.2 `BillPaymentInquiry` cases → `docs/kuickpay/fixtures/bill-payment-inquiry/`: `pending.xml`, `paid-exact.xml`, `amount-mismatch.xml`, `expired.xml`, `unknown.xml`.
  - [ ] 2.3 `BillPaymentBulkInquiry` cases → `docs/kuickpay/fixtures/bill-payment-bulk-inquiry/`: `matched-paid.xml`, `unmatched.xml`, `malformed-xml.xml`.
  - [ ] 2.4 Each `.xml` fixture must be the **full sanitized SOAP response envelope** as the SOAP client would receive it, preserving the exact `*Result` payload (the comma-separated string for inquiry, the raw status string for InsertVoucher, the XML dataset for bulk) so Story 3.2's parser can be developed and tested against faithful inputs.
  - [ ] 2.4a **Every `.xml` fixture file MUST itself be well-formed XML** (it passes Task 4.1). The "malformed" cases (`malformed.xml`, `malformed-xml.xml`) represent **malformed KuickPay payload semantics _inside_ a well-formed SOAP envelope** — e.g., a truncated/empty/garbage `*Result` value, missing required result fields, or an inner bulk dataset string that the parser cannot parse. They are NOT broken/unparseable fixture files. If you genuinely need a transport-level invalid-XML response sample (no valid envelope at all), capture it as a `.md` transport descriptor instead, so it stays outside the XML well-formedness loop.
  - [ ] 2.5 **Sanitize every fixture**: redact/remove `userName`, `password`, real customer mobile/email/name, real Institution ID, and any environment-specific value. Replace with obvious placeholders (e.g., `REDACTED`, `0300XXXXXXX`, `INSTITUTION_ID`). If a fixture was NOT obtained from a live/sandbox source, mark it `provisional: true` (unverified) in the index so it is usable for parser development but does not satisfy the approval gate.
  - [ ] 2.6 Create the **fixture index** `docs/kuickpay/testing-fixtures.md` containing: (a) the expected normalized-status / `error_class` / `decision_rule` mapping table (see Dev Notes "Fixture → Expected Evidence Mapping"), (b) the sanitization rules, (c) the **category mapping** that Story 3.2 will use to relocate fixtures into the architecture's canonical plugin test tree, and (d) **per-fixture provenance metadata** (see below).
  - [ ] 2.6a Each fixture row in `testing-fixtures.md` must carry provenance fields so verification cannot be overclaimed: `source_type` (`live` | `sandbox` | `synthetic_from_observed_format`), `captured_at`, `captured_by`, `redacted_by`, `verification_status` (`verified` | `provisional`), `provisional_reason` (blank if verified), `approval_status` (`PENDING_HUMAN_APPROVAL` until human sign-off), and a sanitized `evidence_hash`/`redacted_trace_id`. **Only `live` or `sandbox` fixtures with complete provenance can satisfy the gate**; `synthetic_from_observed_format` rows are always `provisional`.
  - [ ] 2.7 Create one **redaction sample** `docs/kuickpay/fixtures/redaction/credentials.xml` — a sanitized SOAP envelope demonstrating the redaction approach for credential/PII fields (`userName`, `password`, `Mobile`, `Email`, `Name`, `InstitutionID` → placeholders). This is a Phase 0 deliverable (evidence of the sanitization method) and the relocation source for the architecture's `redaction/credentials.xml` slot in Story 3.2.

- [ ] **Task 3 — Record the gate posture and approval checklist** (AC: #4)
  - [ ] 3.1 Add a **Gate Status** section to `phase-0-contract.md` with **two distinct fields** so "files present" is never mistaken for "release approved":
    - `artifact_status: COMPLETE | INCOMPLETE` — the dev MAY set this to `COMPLETE` once all deliverables exist and pass verification.
    - `gate_approval_status: PENDING_HUMAN_APPROVAL | APPROVED` — the dev MUST leave this `PENDING_HUMAN_APPROVAL`. Also state that **payment posting remains DISABLED** until `gate_approval_status` is `APPROVED`, and that unknown/unverified KuickPay status codes map to `retry` or `manual_review`, never `posted`.
  - [ ] 3.2 Add an **operator approval checklist** (Blesta version confirmed, SOAP endpoint + WSDL confirmed, date formats confirmed, Consumer Number formula confirmed, credential separation confirmed, all required fixtures present and `verification_status: verified` from live/sandbox, rate-limit guidance recorded). The dev agent must NOT self-mark the gate approved — leave the approval field and date blank for human sign-off.

- [ ] **Task 4 — Verify and self-audit** (AC: #1, #2)
  - [ ] 4.1 Validate every fixture `.xml` is **well-formed XML** using a recursive enumerator that does NOT rely on bash `globstar` (which is off by default and would silently skip nested files). Use `find` (see Verification section), not `fixtures/**/*.xml`.
  - [ ] 4.2 **Secret check — two explicit steps** (a single grep is insufficient because sanitized envelopes legitimately contain placeholder `userName`/`password` fields):
    - **(a) Forbidden-real-value scan** — flag values that look real and must NOT appear: a numeric `<InstitutionID>`, a real-looking email, a real-looking Pakistani mobile, or a `password`/`userName` element whose value is anything other than an obvious placeholder (`REDACTED`, `XXXX`, etc.). Any hit = fail. Document the command and that it returned nothing real.
    - **(b) Redaction-confirmation review** — list every occurrence of sensitive field names (`userName`, `password`, `Mobile`, `Email`, `Name`, `InstitutionID`) and confirm each holds a placeholder. Record the expected placeholder matches so a reviewer can see they were intentional, not overlooked.
  - [ ] 4.3 Confirm all Phase 0 artifacts live under **`docs/kuickpay/`** (web-blocked) and that **nothing** was written under web-served `plugins/` or `components/`.
  - [ ] 4.4 No runtime PHP changed → `php -l` is N/A. State this explicitly in the completion notes and list the fallback checks actually run (XML well-formedness + secret scan).

## Dev Notes

### Critical context — read before starting

This is the **Phase 0 release gate** ([Source: epics.md#Epic 0], [Source: architecture.md#Technical Constraints & Dependencies]). The architecture and PRD both make it a hard prerequisite:

- **It gates Story 2.3 (success-path voucher issuance) and all of Epic 3 (parser, reconciliation, posting).** [Source: _bmad-output/implementation-artifacts/sprint-status.yaml#BUILD ORDER], [Source: sprint-change-proposal-2026-06-09.md#Section 4]
- **It can and should run in parallel with Epic 1** (scaffold/settings/credentials/connection-test/PKR). Epic 1 is fully unblocked today and does NOT wait on this story. [Source: sprint-status.yaml#PARALLELISM]
- It exists because PRD **Open Question #2** (exact `InsertVoucherResult` / `BillPaymentInquiryResult` / `BillPaymentBulkInquiryResult` formats) is the crux external dependency, and the readiness review found Phase 0 had no owning story. [Source: prd.md#12 Open Questions], [Source: implementation-readiness-report-2026-06-09.md#C. Phase 0 Gate Has No Owning Story]

**External-dependency honesty (do not fake completion):** Confirming KuickPay's real response formats and the production Blesta version requires inputs from an external party (KuickPay merchant onboarding) and from operations. An automated agent cannot invent these as if real. Your job is to **produce the contract-confirmation structure and the fixture set from the best available evidence**, fill confirmed fields, and clearly mark anything not yet confirmed as `UNCONFIRMED` / `provisional`. **Never** mark the gate "approved" yourself, and **never** present provisional fixtures as verified. The checklist explicitly forbids "lying about completion." [Source: .claude/skills/bmad-create-story/checklist.md]

### Deliverables and EXACT locations

All Phase 0 output lives under **`docs/kuickpay/`** (create the directory):

```text
docs/kuickpay/
├── phase-0-contract.md            # contract confirmation + gate status + approval checklist (Tasks 1, 3)
├── testing-fixtures.md            # fixture index, expected-status mapping, sanitization rules, Story 3.2 handoff (Task 2.6)
└── fixtures/
    ├── insert-voucher/
    │   ├── success.xml
    │   ├── duplicate.xml
    │   ├── invalid-credentials.xml
    │   ├── malformed.xml
    │   └── timeout.md             # transport-outcome descriptor (no envelope)
    ├── bill-payment-inquiry/
    │   ├── pending.xml
    │   ├── paid-exact.xml
    │   ├── amount-mismatch.xml
    │   ├── expired.xml
    │   └── unknown.xml
    ├── bill-payment-bulk-inquiry/
    │   ├── matched-paid.xml
    │   ├── unmatched.xml
    │   └── malformed-xml.xml       # well-formed envelope, malformed inner dataset (see Task 2.4a)
    └── redaction/
        └── credentials.xml         # sanitized sample demonstrating credential/PII redaction (Task 2.7)
```

**Why `docs/kuickpay/` and NOT the architecture's canonical fixture path now:** The architecture's canonical home for *wired* parser-test fixtures is `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` [Source: architecture.md#Complete Project Directory Structure]. That path is **not usable in Phase 0** because:
1. The `kuickpay_reconcile` plugin does not exist yet — it is created by Epic 1 Story 1.1, which runs in *parallel* with this story. Epic 0 must not depend on Epic 1. (Verified: `plugins/kuickpay_reconcile/` is absent in the current checkout.)
2. **Security:** the repo root `.htaccess` blocks public access to `docs/` (and `_bmad-output/`), but `plugins/` is web-served and `.xml` is **not** in the extension deny-list. Writing fixtures under `plugins/` now would expose them publicly before the scaffold adds protection. This directly matches the recent `fix(security): block public access to private artifacts` commit (0b4b18bf). [Source: ./.htaccess line 34 `RewriteRule ^(...|docs|...)(/|$) - [F,L]`]

**Handoff to Story 3.2:** `testing-fixtures.md` must record the deterministic mapping so Story 3.2 can copy the *verified* fixtures into `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` (and must protect that tree from web access). The architecture's category folders are `valid/`, `malformed/`, `ambiguous/`, `redaction/` [Source: architecture.md#Complete Project Directory Structure]; map Phase 0 fixtures onto them, e.g. `insert-voucher/success.xml` + `bill-payment-inquiry/pending.xml` → `valid/`; `*/malformed*.xml` → `malformed/`; `duplicate.xml` + `unmatched.xml` + `amount-mismatch.xml` → `ambiguous/`; and the Phase 0 `redaction/credentials.xml` sample → `redaction/`.

### Contract confirmation document — required fields

`phase-0-contract.md` must capture each of these with a status (`CONFIRMED` value, or `UNCONFIRMED` + conservative default):

| Field | Source / current evidence | Notes |
|---|---|---|
| `production_blesta_version` | Repo is `6.0.0-b1`; research says 6.0 beta is non-production, 5.13 is stable | Operator decision; do not assume. [Source: architecture.md#Deferred Decisions], [Source: research...md#3 Technology Stack] |
| `kuickpay_soap_endpoint` (prod + sandbox) | Public ASMX base `https://app.kuickpay.com/kuickpaycoreapi/api.asmx` (example only) | Admin Setting, never hard-coded. [Source: research...md#4 KuickPay API Surface] |
| `kuickpay_wsdl_url` (prod + sandbox) | Typically the ASMX URL + `?WSDL` (example only) | Separate field a `SoapClient` consumes; Admin Setting, never hard-coded. |
| `date_format_due` / `_expiry` / `_issue` / `_transaction` | Unknown until KuickPay confirms | Conservative default + central normalization. [Source: addendum.md#A.2], [Source: prd.md#12 Q4] |
| `consumer_number_formula` | Default `institution_id + registration_number` | Confirm for this merchant; keep configurable. [Source: addendum.md#A.3], [Source: prd.md#12 Q3] |
| `registration_number_formula` | Default `random_prefix + invoice_id` | [Source: addendum.md#A.3] |
| `credential_separation` | Voucher vs inquiry credentials, + same-as toggle | [Source: prd.md#12 Q5], [Source: addendum.md#A.2] |
| `rate_limits` / `polling_backoff` | Unknown | Record or flag UNAVAILABLE + conservative default. [Source: prd.md#12 Q8], [Source: architecture.md NFR7] |

### Fixture → Expected Evidence Mapping (the parser contract this gate locks down)

This table is the deliverable that Story 3.2's normalized parser will implement against. Put it in `testing-fixtures.md`. Statuses use the **architecture's canonical Voucher states** and `error_class` values — do not invent new ones. [Source: architecture.md#Naming Patterns], [Source: architecture.md#Parser & Evidence Contract]

Each fixture has **exactly one** canonical `expected_status`. Where a case is intentionally policy-dependent, the deterministic fallback lives in `decision_rule` — do NOT encode slash-separated statuses (e.g. `retry / manual_review`) as the expected value.

| Operation | Fixture | `expected_status` (one canonical) | `error_class` | `decision_rule` (policy-dependent fallback) |
|---|---|---|---|---|
| InsertVoucher | `success.xml` | `pending` | (none) | Voucher created, **unpaid**. `raw_status` `00` observed as creation success. |
| InsertVoucher | `duplicate.xml` | `manual_review` | `duplicate_reference` | Fail closed; confirm real merchant duplicate semantics before any other mapping. |
| InsertVoucher | `invalid-credentials.xml` | `failed` | `credential_error` | — |
| InsertVoucher | `malformed.xml` | `manual_review` | `malformed_response` | Missing required fields ⇒ malformed, not partial success. |
| InsertVoucher | `timeout.md` | `manual_review` | `timeout` | **Never auto-retry `InsertVoucher`** (idempotent-lookup rule unproven). Re-evaluate only once reconciliation inquiry can confirm whether the create landed. [Source: research...md#Resilience Pattern] |
| BillPaymentInquiry | `pending.xml` | `pending` | (none) | — |
| BillPaymentInquiry | `paid-exact.xml` | `confirmed_unposted` | (none) | **Validated evidence only — NOT paid.** Only Story 3.5 posting → `posted`. `raw_status` field `0` == `00` observed as paid. |
| BillPaymentInquiry | `amount-mismatch.xml` | `manual_review` | `amount_mismatch` | Compare as decimal strings / integer minor units, never floats. |
| BillPaymentInquiry | `expired.xml` | `expired` | (none) | If the real capture's expired semantics are ambiguous → `manual_review`; record which applies. |
| BillPaymentInquiry | `unknown.xml` | `manual_review` | `unknown_status` | — |
| BillPaymentBulkInquiry | `matched-paid.xml` | `confirmed_unposted` | (none) | Matched by stored Consumer Number only — never infer from suffix. |
| BillPaymentBulkInquiry | `unmatched.xml` | `manual_review` | `unmatched_reference` | Recorded as a run item for review. |
| BillPaymentBulkInquiry | `malformed-xml.xml` | `manual_review` | `malformed_response` | Bounded retry of the bulk call is allowed **only** if the failure classifies as transient transport truncation (`error_class=transport_error`); a genuinely malformed dataset → `manual_review`. Parse defensively: no entity expansion, no external entities, bounded length. |

**Observed (unverified) raw formats to encode in fixtures** — validate against real captures before approval [Source: addendum.md#B Parser Contract Notes]:
- `InsertVoucherResult` is a raw string; `00` observed as success; voucher id observed at `substr(result, 3, 14)`.
- `BillPaymentInquiryResult` is a comma-separated string; field `0` == `00` observed as paid; payment date at field `2`, paid amount at field `3`, transaction-reference components at fields `1`, `4`, `5`.
- `BillPaymentBulkInquiryResult` returns an XML dataset containing `Consumer_Number` rows.

**Normalized parser fields** the evidence must support (for fixture realism) [Source: architecture.md#Parser & Evidence Contract]: `status`, `error_class`, `reference`, `consumer_number`, `registration_number`, `amount`, `currency`, `paid_at`, `raw_status`, `redacted_trace_id`, `evidence_hash`, `validation_errors`.

### InsertVoucher request field shape (for realistic fixtures)

KuickPay `InsertVoucher` request fields and their Blesta mapping [Source: addendum.md#A.2]: `userName`, `password`, `InstitutionID`, `RegistrationNumber`, `Head1`/`Amount1` (payment head + PKR payable), `Head2..Head10`/`Amount2..Amount10` (empty for MVP), `TotalAmount` (= `Amount1`), `DueDate`, `AmountAfterDueDate`, `ExpiryDate`, `IssueDate`, `VoucherMonth`, `VoucherYear`, `Name`, `Mobile`, `Email`, `Branch`. When sanitizing a request echoed inside a fixture, `userName`/`password`/`Mobile`/`Email`/`Name`/`InstitutionID` must be redacted.

### Secret-safety guardrails (non-negotiable)

[Source: project-context.md#Critical Don't-Miss Rules], [Source: prd.md#5 Non-Goals], [Source: architecture.md#Authentication & Security]

- Never include real passwords, real Institution ID, unredacted SOAP envelopes, real customer contact data, or any value copied from `config/blesta.php`, logs, cache, or `.env*` in any doc or fixture.
- All Phase 0 artifacts stay under `docs/kuickpay/` which is web-blocked by root `.htaccess`. Do not write them anywhere web-served.
- This story does NOT add hard-coded production values because it adds no business logic; the contract doc records values as configurable placeholders only (AC1's second clause).

### What must NOT happen in this story (regression / scope guardrails)

- **No runtime PHP / no business logic.** No gateway class, no plugin, no parser, no SOAP client, no posting code. (Those are Stories 1.1, 3.1, 3.2, 3.5.)
- **No new tables, no schema, no `.htaccess` edits, no Composer changes.**
- **Do not create `plugins/kuickpay_reconcile/` or `components/gateways/nonmerchant/kuickpay/`** — leave those to Epic 1.
- **Do not flip any "posting enabled" flag** (none exists yet; keep it that way). Posting stays disabled until the gate is human-approved.
- Treat `docs/` and `_bmad-output/` as project artifacts; do not reorganize unrelated files. [Source: project-context.md#Development Workflow Rules]

### Verification (this story)

There is no runtime PHP to lint, so root PHPUnit / `php -l` over app code is **N/A** — state this honestly and list what actually ran [Source: project-context.md#Testing Rules, NFR12]:

```sh
# Fixture well-formedness — use find (bash globstar is OFF by default; ** would skip nested files)
find docs/kuickpay/fixtures -name '*.xml' -print -exec xmllint --noout {} \;
# (fallback if xmllint absent)
# find docs/kuickpay/fixtures -name '*.xml' | while read -r f; do php -r 'libxml_use_internal_errors(true); echo (simplexml_load_file($argv[1])?"OK ":"BAD ").$argv[1]."\n";' "$f"; done

# Secret check (a) — forbidden REAL values; any hit = FAIL:
#   numeric Institution ID, real-looking email/mobile, or non-placeholder credential values
grep -rnE '<InstitutionID>[0-9]+</InstitutionID>|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|03[0-9]{2}[0-9]{7}' docs/kuickpay/ \
  | grep -viE 'REDACTED|XXXX|INSTITUTION_ID|placeholder|example' || echo "no forbidden real values"

# Secret check (b) — redaction confirmation; review that each match is a placeholder, record expected hits
grep -rniE '<(userName|password|Mobile|Email|Name|InstitutionID)>' docs/kuickpay/
```

### Project Structure Notes

- **Alignment:** `docs/kuickpay/` is the architecture-designated documentation root [Source: architecture.md#Complete Project Directory Structure, `docs/kuickpay/testing-fixtures.md`]. Using it for Phase 0 fixtures is a deliberate, security-driven choice (see "Why `docs/kuickpay/`…" above).
- **Variance from architecture (intentional, documented):** the architecture lists fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` with `valid/ malformed/ ambiguous/ redaction/` subfolders. Phase 0 deliberately stages them under `docs/kuickpay/fixtures/` (operation-keyed) because the plugin does not exist yet and `plugins/` is web-served. `testing-fixtures.md` records the category mapping so Story 3.2 relocates the *verified* fixtures into the canonical tree and protects it. This variance is a sequencing artifact, not a design change.

### References

- [Source: epics.md#Epic 0: Phase 0 - KuickPay Contract Validation and Fixture Gate] — story, ACs, sequencing.
- [Source: epics.md#Story 0.1] — the four Given/When/Then AC blocks (reproduced above verbatim in intent).
- [Source: prd.md#12 Open Questions] — Q1 (Blesta version), Q2 (response formats), Q3 (Consumer Number), Q4 (date formats), Q5 (credential separation), Q8 (rate limits), Q11 (committable fixtures).
- [Source: addendum.md#A. External Interface Notes] — SOAP operations, InsertVoucher field map, Consumer Number rule.
- [Source: addendum.md#B. Parser Contract Notes] — observed status codes and field offsets to validate.
- [Source: architecture.md#Parser & Evidence Contract] — normalized fields and allowed `error_class` values.
- [Source: architecture.md#Naming Patterns] — canonical 8 Voucher states.
- [Source: architecture.md#Complete Project Directory Structure] — canonical fixture + docs locations.
- [Source: research/technical-kuickpay-blesta-payment-gateway-research-2026-06-09.md#12 Appendix: Phase 0 Fixture Matrix] — operation/case → expected internal status matrix.
- [Source: research...md#Resilience Pattern] — do-not-blindly-retry-InsertVoucher rationale.
- [Source: implementation-readiness-report-2026-06-09.md#C. Phase 0 Gate Has No Owning Story] — why this story exists.
- [Source: sprint-change-proposal-2026-06-09.md#Section 4] — exact backlog change that created Story 0.1.
- [Source: sprint-status.yaml#BUILD ORDER / SEQUENCING] — 0.1 gates 2.3 + Epic 3; runs parallel with Epic 1.
- [Source: project-context.md#Critical Don't-Miss Rules] — secret-safety, no-core-edit, fail-closed.
- [Source: ./.htaccess] — `docs/` and `_bmad-output/` are web-blocked; `plugins/` is served and `.xml` is not extension-denied.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **Live/sandbox access:** Are real KuickPay sandbox or production credentials available to capture *verified* fixtures during this story? If not, fixtures will be `provisional` (built from the addendum's observed formats) and the gate stays UNAPPROVED until real captures replace them — which is the correct fail-closed posture, but the team should know the gate cannot be closed without live evidence.
2. **Fixture home confirmation:** This story stages fixtures under `docs/kuickpay/fixtures/` (web-blocked, scaffold-independent) and defers relocation to `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` to Story 3.2. Confirm this is acceptable vs. waiting for the Story 1.1 scaffold and writing fixtures directly into the plugin tree (with added `.htaccess` protection).
3. **Production Blesta version:** Operations must decide 5.13 stable vs 6.0 beta. The repo is `6.0.0-b1`; research flags 6.0 beta as non-production. Whoever owns the production target should fill this before the gate is approved.
