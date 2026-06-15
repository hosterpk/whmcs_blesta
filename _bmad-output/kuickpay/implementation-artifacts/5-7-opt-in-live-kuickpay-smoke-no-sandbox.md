---
baseline_commit: 7c3835d88aea366b4297d66b8d549c7d1ed83bdb
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.7: Opt-In Live KuickPay Smoke (No Sandbox)

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator and developer,
I want an explicit, redacted, default-skipped live check against the real KuickPay endpoint,
so that production credentials and data are never exercised accidentally and there is one sanctioned real-provider smoke.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.7 (lines 1001–1023). **Closes:** FR28 (`epics.md:79`),
> FR29 (`epics.md:81`); **honors** NFR8 (`epics.md:101` — admin-only / no secret exposure),
> NFR11 (`epics.md:107` — automated tests must not call live endpoints by default; live checks need
> explicit protected configuration), NFR12 (`epics.md:109` — honest verification reporting). Architecture:
> SOAP-client-only call site + gateway ownership (`architecture.md:331,520-522,770,776`); no hard-coded
> endpoints/credentials/Institution IDs (`architecture.md:83,657`; NFR10 `epics.md:105`;
> `phase-0-contract.md:41-50`). Continues the Story 5.1 live-verification charter: 5.1 explicitly routed
> "the live KuickPay SOAP leg (no sandbox)" to **this** story as opt-in only
> (`5-1-stand-up-the-live-verification-stack…:91,121,144,258`; risk-acceptance
> `docs/kuickpay/risk-acceptance-5-1-live-verification.md`).

**⚠️ SCOPE REALITY CHECK — read before coding.** This is a **verification-scaffolding + docs** story (like
Story 5.1), **not** a feature story. Six traps:

1. **KuickPay has NO sandbox.** Confirmed: `kuickpay_soap_endpoint.sandbox = NOT_AVAILABLE`,
   `kuickpay_wsdl_url.sandbox = NOT_AVAILABLE` (`phase-0-contract.md:26-27`). The original "live OR sandbox"
   story's sandbox arm is **removed** (`epics.md:1023`). There is exactly one real-provider path and it is the
   manual, opt-in, default-skipped smoke you build here.
2. **The default automated test suite must NEVER touch the live endpoint** (AC1; NFR11 `epics.md:107`). The
   live-calling code must therefore be a CLI-only script that PHPUnit's name-convention discovery **does not pick
   up** — i.e. **not** a `*Test.php` file — exactly mirroring Story 5.1's `tests/integration/*.php` harness
   scripts (`live_fixture_round_trip.php`, `posting_safety_hardening_check.php`), which ride in the tree but the
   `--bootstrap tests/bootstrap.php tests` runner never executes because they are not `TestCase` subclasses named
   `*Test`. **DECIDED: a non-discovered CLI script is the live caller; a separate green `*Test.php` guard proves
   the gate stays closed by default** (see Dev Notes "Deliverable shape"). Putting the live call inside a
   `markTestSkipped()` PHPUnit test was **considered and declined** — a live-capable test physically sitting in
   the auto-discovered suite is strictly riskier than one the runner never loads.
3. **DO NOT INVENT A KUICKPAY HOST/CREDENTIAL/INSTITUTION-ID LITERAL.** The real WSDL host, credentials, and
   Institution ID are operator-configured and **never committed** (`phase-0-contract.md:41-50`;
   `architecture.md:83,657`; NFR10). The smoke reads them at runtime from **environment variables / protected
   uncommitted configuration only**. The in-repo `app.kuickpay.com` is "example only, not a production default"
   (`0-1…:60,146`; `3-1…:131`). Hard-coding any of these is a spec + architecture-review violation.
4. **The smoke is READ-ONLY and DB-FREE — it can never mark an invoice paid** (AC2). Use a **read-only** SOAP
   operation (`BillPaymentInquiry`, or the safe-setup `Echo`/`GetInstitutionsList`). **NEVER** `InsertVoucher`
   (it mints a payable voucher) and **NEVER** any posting. Only `KuickPayPostingService` (plugin, Epic 3) can
   create/apply a Blesta transaction (`architecture.md:581-593`, anti-pattern list `:648-662`); the smoke never
   constructs or calls it, never opens the DB, never touches `kuickpay_vouchers`/`transactions`. That DB-free
   design is the cleanest possible "any failure leaves no invoice paid" guarantee and also sidesteps the
   disposable-billing-data risk that dominated Story 5.1.
5. **`KuickPaySoapClient::call()` returns `raw_result` UNREDACTED** (its own docblock, `KuickPaySoapClient.php:130`
   — "raw_result: ?string **unredacted** parser-only operation result payload"), and the parser's normalized
   `KuickPayEvidence` can carry the echoed Consumer/Registration Number, amount, and paid date. The smoke's
   printed/recorded output must emit **only** the redacted/safe field allowlist (see Dev Notes "Redaction
   allowlist") — never `raw_result`, never an unredacted envelope, never credentials / Institution ID / customer
   PII. Run anything else through `KuickPayRedactor` first (AC2; NFR8).
6. **No production money/transport logic changes; no version bump.** The seams already exist — the real
   `KuickPaySoapClient` (constructed with **no** `$soapClientFactory` → real `SoapClient`), `KuickPayRedactor`,
   `KuickPayResponseParser`. Leave the gateway `config.json` at `1.0.0`. If a genuine testability seam is
   missing (it should not be), raise it before adding; do not modify the SOAP client, parser, or redactor to make
   the smoke run.

**The smoke lives under the web root** (`components/gateways/nonmerchant/kuickpay/…`). A `.php` there that can
call the live provider with credentials is an HTTP-exposure risk, so it must be **CLI-only** (`PHP_SAPI !== 'cli'`
→ refuse, the 5.1 precedent) **and** web-blocked by a `.htaccess` (the gateway `tests/` dir currently has **none**;
the plugin `tests/.htaccess` `Require all denied` is the pattern to copy).

---

1. **(AC1 — default-skipped; opt-in only via explicit protected config/env)**
   **Given** KuickPay provides no sandbox,
   **When** the default automated test suite runs (`cd components/gateways/nonmerchant/kuickpay &&
   <php> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`),
   **Then** the live endpoint is **never** called — no live SOAP request is issued and no real `SoapClient` is
   constructed against a provider host during the suite,
   **And** the smoke runs **only** when explicitly opted in through protected configuration / environment
   variables (master switch `KUICKPAY_LIVE_SMOKE=1` **plus** the operator-supplied connection inputs), proven by a
   green default-suite guard test that asserts the gate decision returns "skip / no-op" when the opt-in is absent.
   **HEAD STATE:** no smoke exists; there is **no** `getenv`/`markTestSkipped` precedent anywhere in the gateway
   or plugin tree, so this story establishes the gating idiom. The default gateway suite today is ≈313 tests with
   the single disclosed `empty-currency` baseline red (memory `[[kuickpay-failclosed-empty-currency-red]]`).

2. **(AC2 — real endpoint with real credentials; fully redacted output; never marks an invoice paid)**
   **Given** the live smoke is enabled intentionally (AC1 opt-in satisfied) with a real WSDL URL + real
   inquiry credentials + Institution ID + a test reference, all supplied at runtime from protected config,
   **When** it runs a **read-only** operation (default `BillPaymentInquiry`; `Echo`/`GetInstitutionsList`
   selectable) against the real endpoint via the real `KuickPaySoapClient`,
   **Then** the recorded/printed output **redacts** credentials, customer contact details, raw sensitive
   response values, and production data — emitting only the safe field allowlist (transport `ok`, operation,
   `error_class`, redacted fault token, `redacted_trace_id`, `duration_ms`, attempts; and the parser's safe
   normalized fields: `status`, `error_class`, `evidence_hash`, `validation_errors`) — and contains no
   credential / Institution ID / WSDL host / unredacted envelope / `raw_result` / customer PII (verifiable by the
   `KuickPaySecretLeakageTest` scan discipline),
   **And** any failure (auth error, transport error, timeout, unknown/unmatched bill) **leaves no invoice marked
   paid** — structurally guaranteed because the smoke is DB-free, runs the gateway read-only SOAP path only, and
   never invokes posting / never writes voucher or transaction state.
   **HEAD STATE:** `KuickPaySoapClient` already returns redacted `raw_envelope`/`fault`/`redacted_request`/
   `redacted_trace_id` **but** also the **unredacted** `raw_result` (`:130`); `KuickPayResponseParser::parse()`
   returns a `KuickPayEvidence` whose `consumerNumber()`/`registrationNumber()`/`amount()`/`paidAt()` may echo the
   reference — so a naive "print the outcome" would leak. Redaction-on-output is the dev's job.

3. **(AC3 — any captured live fixtures are sanitized before commit/documentation)**
   **Given** sanitized live fixtures are captured from a smoke run,
   **When** they are committed or documented,
   **Then** they exclude passwords, unredacted SOAP envelopes, customer secrets, and environment-specific values
   — the capture path routes the envelope through `KuickPayRedactor::redactEnvelope()` and the result passes the
   `KuickPaySecretLeakageTest` forbidden-pattern scan before any commit,
   **And** because **no sanitized live KuickPay response body exists at story-creation time**
   (`phase-0-contract.md:78,100` — "no sanitized live KuickPay response bodies are committed"), this story ships
   the **sanitized-capture mechanism + the operator runbook rules + a scan guard**, and does **not** commit a real
   captured live response. Any fixture an operator later captures must clear the same gate.
   **HEAD STATE:** committed fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` and
   `docs/kuickpay/fixtures/` are all WHMCS-derived/provisional placeholders (`testing-fixtures.md`); none is a
   live capture. The leak-scan patterns live in `plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php`.

## Tasks / Subtasks

- [x] **Task 1 — AC1: build the pure, default-suite-testable opt-in gate**
  - [x] 1.1 Add a small **pure** decision class in the test area (NOT in `lib/` — this is verification
    scaffolding, not production code; project-context.md:70,211 of 5.1): e.g.
    `components/gateways/nonmerchant/kuickpay/tests/live/KuickPayLiveSmokePlan.php`. Give it a pure static/instance
    method like `plan(array $env): array` returning a structured `{ run: bool, reason: string, operation: string,
    config: array, missing: array }` decision computed **only** from an injected env map (do not read `getenv()`
    inside the pure method — pass the map in, à la Story 5.5's `KuickPayAclDecision` and 5.6's
    `Kuickpay::wsdlUrlSafety()`). Rule: `run=false` unless `env['KUICKPAY_LIVE_SMOKE'] === '1'` **and** every
    required connection input is present; otherwise `run=false` with a `reason`/`missing` breadcrumb. Never throw.
  - [x] 1.2 Define the protected-config contract as **environment variables only** (no committed values, no host
    literals): master switch `KUICKPAY_LIVE_SMOKE=1`; `KUICKPAY_SMOKE_WSDL_URL`,
    `KUICKPAY_SMOKE_INQUIRY_USERNAME`, `KUICKPAY_SMOKE_INQUIRY_PASSWORD`, `KUICKPAY_SMOKE_INSTITUTION_ID`,
    `KUICKPAY_SMOKE_CONSUMER_NUMBER` (the read-only test reference); optional `KUICKPAY_SMOKE_OPERATION`
    (default `BillPaymentInquiry`; allow `Echo`/`GetInstitutionsList`). Map these into the `KuickPaySoapClient`
    config shape (`wsdl_url`, `inquiry_username`, `inquiry_password`, `institution_id`, `soap_timeout`,
    `inquiry_same_as_voucher`) — note `withCredentials()` reads `inquiry_*` for inquiries
    (`KuickPaySoapClient.php:256-266`).
  - [x] 1.3 Add the default-suite guard test `tests/KuickPayLiveSmokeGuardTest.php` (a real `*Test.php`, so it IS
    discovered and runs green) that unit-tests the pure plan: with an **empty** env → `run=false`; with
    `KUICKPAY_LIVE_SMOKE=1` but missing inputs → `run=false` + names the `missing` keys; with the full opt-in env
    map → `run=true` and the correct operation. **This test must itself make NO live call and construct NO
    `SoapClient`** — it only exercises the pure decision. It is the green, auditable proof of AC1 ("default suite
    never calls live"). `require_once` the plan class from `tests/live/` (the gateway `tests/bootstrap.php` loads
    only the `lib/*` classes, not the test-area plan — add the `require_once` in the test).

- [x] **Task 2 — AC1/AC2: build the CLI-only, web-blocked live smoke runner**
  - [x] 2.1 Add `components/gateways/nonmerchant/kuickpay/tests/live/kuickpay_live_smoke.php` — a standalone CLI
    script (**not** `*Test.php`, so the PHPUnit runner never discovers it; mirrors 5.1's
    `tests/integration/*.php`). First lines: `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }`
    (the 5.1 `live_fixture_round_trip.php:9` guard). Then `require_once` the gateway `lib/*` (reuse
    `tests/bootstrap.php` or require the four libs directly) and the `KuickPayLiveSmokePlan`.
  - [x] 2.2 Read the real environment, run the plan (Task 1.1). If `run=false`: print a clear, **secret-free**
    "SKIPPED — opt-in not set / missing: …" message and `exit(0)` **without** constructing any SOAP client. This
    is the same gate the guard test asserts.
  - [x] 2.3 If `run=true`: construct the **real** `new KuickPaySoapClient($config)` with **no** `$soapClientFactory`
    (so it builds a real `SoapClient` against the operator WSDL) and **no** logger (or a redacting logger). The
    client's own `hasUsableWsdlUrl()` (`:367-382`) already fails closed on userinfo/non-https before any network
    call — defense-in-depth you get for free; you may also pre-validate but do not duplicate the save-time
    private-range guard (that chokepoint is the gateway settings form, Story 5.6).
  - [x] 2.4 Invoke the selected **read-only** op: `billPaymentInquiry(['Consumer_Number' => <test ref>])` (default),
    or `echoTest()` / `getInstitutionsList()`. **NEVER** `insertVoucher()`; **NEVER** any posting; **NEVER** open
    the DB. Confirm credential validation is exercised: even an unknown/unmatched Consumer Number still proves the
    real credentials authenticated and a real response transported+parsed+redacted.
  - [x] 2.5 Feed the transport outcome through `KuickPayResponseParser::parse($outcome)` to get a normalized
    `KuickPayEvidence`, and emit **only** the redaction allowlist (Dev Notes "Redaction allowlist"): transport
    `ok`/`operation`/`error_class`/redacted `fault`/`redacted_trace_id`/`duration_ms`/`attempts`, and evidence
    `status()`/`errorClass()`/`evidenceHash()`/`redactedTraceId()`/`validationErrors()`. **Do NOT** print
    `raw_result`, `raw_envelope` unredacted, `redacted_request` raw values, or evidence
    `consumerNumber()`/`registrationNumber()`/`reference()`/`amount()`/`currency()`/`paidAt()`/`rawStatus()`.
    Print a single explicit line that no invoice was created or marked paid (the DB-free guarantee).
  - [x] 2.6 Exit non-zero on a transport/credential failure (so an operator/CI wrapper sees the failure) but keep
    the printed diagnostic redacted; exit zero on a clean reachable response. A failure must still leave no
    invoice paid (trivially true — DB-free).

- [ ] **Task 3 — AC3: sanitized live-fixture capture mechanism + scan guard**
  - [x] 3.1 Add an optional, opt-in capture path to the smoke (e.g. `KUICKPAY_SMOKE_CAPTURE=<path>`): when set,
    write the **redacted** envelope (`KuickPaySoapClient` already exposes `raw_envelope` = redacted via
    `KuickPayRedactor::redactEnvelope()`) — never the raw envelope, never `raw_result` — to the given path. The
    capture must exclude passwords, unredacted SOAP envelopes, customer secrets, and environment-specific values
    (WSDL host, Institution ID).
  - [x] 3.2 Add a guard test (extend `tests/KuickPayLiveSmokeGuardTest.php` or a sibling) that, given a sample
    captured-fixture string, asserts it passes a forbidden-pattern scan reusing the
    `KuickPaySecretLeakageTest`-style patterns (credentials, Institution ID placeholder, mobile/CNIC/email PII,
    raw `<userName>`/`<password>` elements). Prove the redactor output is leak-clean **without** committing a real
    live response.
  - [ ] 3.3 **Do NOT commit a real captured live fixture** (none exists; `phase-0-contract.md:78,100`). Document in
    the runbook that any operator-captured fixture must clear the 3.2 scan before commit, and where it would live
    (`docs/kuickpay/fixtures/bill-payment-inquiry/` alongside the existing provisional set).

- [ ] **Task 4 — Web-exposure + CLI hardening (AC1 safety)**
  - [x] 4.1 Add `components/gateways/nonmerchant/kuickpay/tests/.htaccess` (or a `tests/live/.htaccess`) copying the
    plugin precedent (`plugins/kuickpay_reconcile/tests/.htaccess` — `Require all denied` with the legacy
    `Deny from all` fallback) so the smoke script (and all gateway test files) are not web-reachable. Confirm the
    gateway `tests/` dir has no `.htaccess` today (it does not).
  - [x] 4.2 Confirm the `PHP_SAPI !== 'cli'` guard (Task 2.1) is the first executable statement, before any
    `require`/env read, so an HTTP hit can never trigger a live call or load credentials.

- [ ] **Task 5 — Operator runbook + sanitized verification record (AC1/AC2/AC3; NFR8/NFR12)**
  - [ ] 5.1 Write `docs/kuickpay/live-smoke-runbook.md` (sits beside `live-verification-evidence.md`,
    `gateway-settings-and-endpoint-hardening-verification.md`): the no-sandbox caveat; the exact env vars and how
    to set them safely (never in shell history that persists, never committed); the exact run command
    (`<php> components/gateways/nonmerchant/kuickpay/tests/live/kuickpay_live_smoke.php`); how to read the redacted
    output; the read-only / DB-free / no-paid guarantee; the optional sanitized-capture flow + commit gate; and a
    pointer that this is the **one** sanctioned real-provider check (the automated full-stack verification is
    Story 5.1). This runbook is a hook for the Story 5.8 deployment docs.
  - [ ] 5.2 Optionally add a sanitized `docs/kuickpay/live-smoke-verification.md` **template/placeholder** (the
    actual run is operator-driven post-deploy) recording exactly what the smoke exercises vs. what it cannot
    (NFR12 honesty): credentials + transport + parse + redact = exercised; posting/DB = intentionally untouched.
    Placeholders only — NO `config/blesta.php`/DB creds/WSDL host/KuickPay creds/Institution ID/raw SOAP/PII.
  - [ ] 5.3 Update `deferred-work.md` if appropriate (no open item is owned by 5.7; the live SOAP leg was carried
    here by 5.1's risk-acceptance — note that this story discharges the **mechanism** for that leg, with the
    actual run remaining operator-driven). Keep the `docs(kuickpay)`/`_bmad-output` doc commit **separate** from
    the runtime/test-scaffolding commit (project-context.md:104).

- [ ] **Task 6 — Verification & evidence (NFR12)**
  - [ ] 6.1 `php -l` on every new PHP file under **both** ea-php83 (production, `/usr/local/bin/php` or
    `/opt/cpanel/ea-php83/root/usr/bin/php`) and the ea-php82 source-floor
    (`/opt/cpanel/ea-php82/root/usr/bin/php`) — no 8.3-only syntax/APIs (project-context.md:39; memory
    `[[kuickpay-php82-toolchain-now-available]]`). `getenv`/`PHP_SAPI`/`SoapClient` are all ≤8.2-safe.
  - [ ] 6.2 Run the **gateway** suite and confirm green-modulo-the-disclosed-`empty-currency`-baseline-red, and
    that the new guard test runs **and that the live smoke script was NOT discovered/executed** (it is not a
    `*Test.php`): `cd components/gateways/nonmerchant/kuickpay && <php> /root/tools/phpunit-8.5/vendor/bin/phpunit
    --bootstrap tests/bootstrap.php tests`. **Do NOT** use `-c build/phpunit.xml` (project-context.md:74).
    Capture the actual baseline first; disclose the `empty-currency` red as pre-existing
    (`[[kuickpay-failclosed-empty-currency-red]]`), do not attribute it to this story.
  - [ ] 6.3 Prove AC1 operationally without the provider: run the smoke script with **no** env opt-in and confirm
    it prints "SKIPPED" and constructs no SOAP client (exit 0); confirm the same with `KUICKPAY_LIVE_SMOKE=1` but
    missing inputs → "SKIPPED / missing …". Record these (secret-free) in the Dev Agent Record. The actual live
    run against the real endpoint is operator-driven (no credentials in this environment) — state that honestly
    (NFR12); do not fabricate a live-run transcript.

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **No sandbox, ever.** `phase-0-contract.md:26-27` — KuickPay provides none for this merchant. The smoke is the
  only real-provider path and it is opt-in + read-only. Do not invent a sandbox.
- **Default suite must never go live.** The live caller is a CLI script the PHPUnit runner does not discover
  (not `*Test.php`); the `*Test.php` guard only tests the **pure gate** and constructs no `SoapClient`. If you are
  tempted to put the live call in a `markTestSkipped()` test, re-read Trap #2 — that was declined.
- **No host/credential/Institution-ID literals.** All from env at runtime (`phase-0-contract.md:41-50`;
  `architecture.md:83,657`; NFR10). The repo's `app.kuickpay.com` is example-only.
- **Read-only + DB-free.** `BillPaymentInquiry`/`Echo`/`GetInstitutionsList` only; never `InsertVoucher`, never
  posting, never open the DB. Only `KuickPayPostingService` may move money (`architecture.md:581-593`) and the
  smoke never touches it → "no invoice paid" is structural, not a runtime check.
- **`raw_result` is UNREDACTED.** `KuickPaySoapClient.php:130`. Print only the redaction allowlist; route anything
  else through `KuickPayRedactor`. Treat the echoed Consumer/Registration Number as sensitive even though it is
  operator-supplied (production-data hygiene, NFR8).
- **Web-exposure.** The script sits under the web root; CLI-only guard first, plus a `.htaccess` deny. A
  credentialed live-calling endpoint reachable over HTTP would be a critical hole.
- **No production code changes / no version bump.** Verification scaffolding + docs only (the Story 5.1 rule). The
  SOAP/redactor/parser seams already exist; do not edit them to make the smoke run. Gateway `config.json` stays
  `1.0.0`.
- **Honest reporting (NFR12).** The real live run is operator-driven (no provider creds here). Report the gate +
  redaction proofs you actually ran; do not fabricate a live transcript. An honestly-reported un-run live leg is
  acceptable; a faked one is not.

### Deliverable shape (the AC1 design call)

The faithful reading of AC1 ("when the default automated test suite runs, the live endpoint is never called") +
NFR11 ("automated tests must not call live endpoints by default; live checks require explicit protected
configuration") + the Story 5.1 precedent yields a **two-part** deliverable:

1. **CLI live caller** — `tests/live/kuickpay_live_smoke.php`, a standalone script **not** named `*Test.php`, so
   `--bootstrap tests/bootstrap.php tests` never loads or runs it (PHPUnit only auto-discovers `*Test` `TestCase`
   subclasses). This is exactly how 5.1's `tests/integration/live_fixture_round_trip.php`,
   `active_context_guard_check.php`, and `posting_safety_hardening_check.php` ride in the tree without ever being
   executed by the suite. It hard-gates on `PHP_SAPI==='cli'` + the env opt-in.
2. **Default-suite guard test** — `tests/KuickPayLiveSmokeGuardTest.php`, a real discovered `*Test.php` that
   unit-tests the **pure** `KuickPayLiveSmokePlan` decision (no env opt-in → `run=false`; full opt-in → `run=true`)
   and the AC3 scan, **constructing no `SoapClient` and making no network call**. This converts AC1 from a
   convention into a green, auditable assertion in the default run.

**Declined alternative:** a single env-gated `markTestSkipped()` PHPUnit test as the live caller. Rejected because
a live-capable test then physically sits inside the auto-discovered suite, relying on the skip-guard firing
correctly on every runner/CI; the non-discovered CLI script is strictly safer and matches the 5.1 precedent. This
mirrors the "extract a pure, unit-testable decision" discipline from Story 5.5 (`KuickPayAclDecision`) and Story
5.6 (`Kuickpay::wsdlUrlSafety()` / `soapTimeoutInRange()` pure predicates).

### The safe live operation (the AC2 design call)

`BillPaymentInquiry` is the default smoke op: it is **read-only** (single-reference reconciliation inquiry,
`phase-0-contract.md:121`), it validates **credentials** (auth happens regardless of whether the bill exists), and
it exercises the full **transport → parse → redact** path — the highest-value "credential and response validation"
(FR29). An unknown/unmatched/expired/unpaid result is a perfectly good smoke outcome: it still proves auth +
transport + parse + redact, and it never marks anything paid (the gateway has no posting path). `Echo` and
`GetInstitutionsList` (the SOAP client's existing safe-setup ops, `KuickPaySoapClient.php:107-121`) are selectable
as even-lighter checks when the operator has no test reference. **`InsertVoucher` is forbidden** — it mints a
payable voucher and is never auto-retried for exactly this reason (`KuickPaySoapClient.php:72`).

**DECIDED: default op = `BillPaymentInquiry` with an operator-supplied test Consumer Number; `Echo`/
`GetInstitutionsList` selectable via `KUICKPAY_SMOKE_OPERATION`.**

### Redaction allowlist (the AC2 output contract)

Print/record **only** these (all safe / already-redacted):

- From the `KuickPaySoapClient` transport outcome (`KuickPaySoapClient.php:582-639`): `ok`, `operation`,
  `error_class`, `fault` (already redacted by `redactedDiagnosticText()`), `redacted_trace_id`, `duration_ms`,
  `attempt`, `attempts`. (`raw_envelope` is already redacted but prefer not to dump it; if you must, it is the
  redactor output, never the raw envelope.)
- From the normalized `KuickPayEvidence` (`KuickPayEvidence.php`): `status()`, `errorClass()`, `evidenceHash()`,
  `redactedTraceId()`, `validationErrors()`, `operation()`, `isConfirmedUnposted()`.

**Never print:** the client `raw_result` (`:130`, unredacted); the raw response envelope; `redacted_request`
values verbatim (keys only if needed); and evidence `consumerNumber()`/`registrationNumber()`/`reference()`/
`amount()`/`currency()`/`paidAt()`/`rawStatus()` (response payload that can echo the reference / carry money/PII).
Anything outside the allowlist must pass through `KuickPayRedactor` first. Self-verify the printed blob against the
`KuickPaySecretLeakageTest` forbidden patterns (Task 3.2).

### Protected configuration contract (the AC1 inputs)

Environment variables only — **nothing committed, no host literal**:

| Env var | Purpose | Maps to `KuickPaySoapClient` config |
|---|---|---|
| `KUICKPAY_LIVE_SMOKE` | master opt-in; must equal `1` | (gate only) |
| `KUICKPAY_SMOKE_WSDL_URL` | operator's confirmed production WSDL | `wsdl_url` |
| `KUICKPAY_SMOKE_INQUIRY_USERNAME` | inquiry credential | `inquiry_username` |
| `KUICKPAY_SMOKE_INQUIRY_PASSWORD` | inquiry credential | `inquiry_password` |
| `KUICKPAY_SMOKE_INSTITUTION_ID` | Institution ID | `institution_id` |
| `KUICKPAY_SMOKE_CONSUMER_NUMBER` | read-only test reference | inquiry param `Consumer_Number` |
| `KUICKPAY_SMOKE_OPERATION` (opt) | `BillPaymentInquiry` (default) / `Echo` / `GetInstitutionsList` | op select |
| `KUICKPAY_SMOKE_TIMEOUT` (opt) | bounded `[1,300]` | `soap_timeout` |
| `KUICKPAY_SMOKE_CAPTURE` (opt) | path to write the **redacted** envelope | (capture) |

`withCredentials()` reads `inquiry_*` for inquiries unless `inquiry_same_as_voucher==='true'`
(`KuickPaySoapClient.php:256-266`); set the inquiry keys. The client's `hasUsableWsdlUrl()` enforces https +
no-userinfo at call time (`:367-382`) — a bad WSDL fails closed before the network.

### Files to ADD — and the current state

| File | AC | Add |
|---|---|---|
| `components/gateways/nonmerchant/kuickpay/tests/live/KuickPayLiveSmokePlan.php` | AC1 | **new** — pure env→`{run,reason,operation,config,missing}` decision (no `getenv` inside; inject the map). |
| `components/gateways/nonmerchant/kuickpay/tests/live/kuickpay_live_smoke.php` | AC1/AC2/AC3 | **new** — CLI-only (`PHP_SAPI` guard first), not `*Test.php`; runs the real `KuickPaySoapClient` read-only op; redacted output; optional redacted capture. |
| `components/gateways/nonmerchant/kuickpay/tests/KuickPayLiveSmokeGuardTest.php` | AC1/AC3 | **new** — discovered `*Test.php`; unit-tests the pure plan + the capture scan; **no** `SoapClient`, **no** network. |
| `components/gateways/nonmerchant/kuickpay/tests/.htaccess` | AC1 | **new** — `Require all denied` (+ legacy fallback), copying `plugins/kuickpay_reconcile/tests/.htaccess`. The gateway `tests/` dir has none today. |
| `docs/kuickpay/live-smoke-runbook.md` | AC1/AC2/AC3 | **new** — operator runbook (env, run command, redacted-output reading, capture+commit gate, no-sandbox caveat). |
| `docs/kuickpay/live-smoke-verification.md` (optional) | NFR12 | **new** — sanitized template; what the smoke exercises vs. leaves untouched; placeholders only. |

**Reuse, do not modify:** `lib/KuickPaySoapClient.php` (construct with no factory → real `SoapClient`;
`billPaymentInquiry()`/`echoTest()`/`getInstitutionsList()`), `lib/KuickPayRedactor.php`
(`redactArray`/`redactEnvelope`/`sensitiveValues`/`traceId`), `lib/KuickPayResponseParser.php` (`parse()` →
`KuickPayEvidence`), `lib/KuickPayEvidence.php` (safe getters). Reuse `tests/bootstrap.php` (it `require_once`s the
four libs the smoke needs).

**DO NOT edit:** any `lib/*` production class, `kuickpay.php`, the plugin tree, any ionCube-protected file,
`config/blesta.php`. Do not bump `config.json`. Do not add a new root `tests/` (project-context.md:70).

### Testing (the harness reality)

- **`tests/bootstrap.php` loads only `lib/*`** (KuickPayRedactor, KuickPaySoapClient, KuickPayEvidence,
  KuickPayResponseParser) — no framework stubs. The guard test must `require_once` the test-area
  `tests/live/KuickPayLiveSmokePlan.php` itself.
- **PHPUnit discovery:** with `tests` as the path, PHPUnit recurses but only runs `TestCase` subclasses named
  `*Test`. `tests/live/kuickpay_live_smoke.php` and `KuickPayLiveSmokePlan.php` are therefore **never executed by
  the suite** — verify this in Task 6.2 (the test count/list does not include the smoke). This is the same reason
  5.1's `tests/integration/*.php` ride along untouched.
- **Pure-decision unit test pattern:** inject the env map into `KuickPayLiveSmokePlan::plan($env)` and assert
  `run`/`reason`/`missing` — fast, no framework, no network (the 5.5/5.6 pattern).
- **Secret-leak scan reuse:** the `KuickPaySecretLeakageTest` (plugin tree) defines forbidden credential/PII
  patterns; reuse the same shapes for the AC3 capture-scan guard (you may inline the patterns in the gateway guard
  test — the gateway suite must stay self-contained per its inline-stub convention; do not cross-require the
  plugin test).

### Previous Story Intelligence (Story 5.1 — `done`, and 5.6 — `done`)

5.1 (`5-1-stand-up-the-live-verification-stack…`) directly sets up 5.7:

- 5.1 **explicitly deferred the live KuickPay SOAP leg to this story** ("the live KuickPay SOAP leg — no sandbox —
  that is Story 5.7, opt-in only", `:91,121,144`) and risk-accepted it
  (`docs/kuickpay/risk-acceptance-5-1-live-verification.md`). 5.7 ships the **mechanism** for that leg; the actual
  run stays operator-driven.
- 5.1's **harness lives in `tests/integration/*.php` standalone scripts**, CLI-guarded (`PHP_SAPI !== 'cli'`), not
  PHPUnit tests — the exact shape to mirror for the smoke (in the **gateway** tree here, since the SOAP
  client/redactor/parser are gateway-owned).
- 5.1 proved the **SOAP factory seam** works (`KuickPaySoapClient($config, $soapClientFactory)`); the smoke uses
  the **opposite** — **no** factory → the real `SoapClient` — but the same constructor.
- 5.1's runtime correction: production is **PHP 8.3 (ea-php83, ionCube 15)**, "8.2" is a source-floor. The smoke is
  a pure CLI script using only the gateway `lib/*` (no ionCube core), so it runs on 8.2 **and** 8.3; verify on 8.3
  (production), lint on both. ext-soap/curl/openssl confirmed present on all three runtimes.

5.6 (`5-6-gateway-settings-and-endpoint-hardening`) patterns that constrain 5.7:

- **Extract a pure, unit-testable decision** (`Kuickpay::wsdlUrlSafety()`, `soapTimeoutInRange()`) — the smoke's
  opt-in gate is the analog (`KuickPayLiveSmokePlan::plan()`).
- The save-time WSDL chokepoint already exists; the SOAP client's `hasUsableWsdlUrl()` keeps userinfo/https in
  step (memory `[[kuickpay-wsdl-ssrf-save-chokepoint]]`). The smoke relies on `hasUsableWsdlUrl()` at call time; do not
  re-add the private-range guard (that's the save chokepoint, not the smoke's job).
- 5.6 final gateway baseline: ≈313 tests, the single disclosed `empty-currency` red. Capture the live baseline
  first and disclose that red as pre-existing.

### Git Intelligence (recent, relevant)

- `7c3835d8` (HEAD) `docs(kuickpay): record 5.6 review completion` — **5.7 baseline**.
- `842034fa`/`d45753e9` — 5.6 gateway settings/endpoint hardening (the `wsdlUrlSafety`/`hasUsableWsdlUrl`
  chokepoint the smoke leans on at call time).
- `49ad7759`/`2997f481` (Story 5.1) — live-verification evidence + risk-acceptance + the `tests/integration/*.php`
  harness pattern this story mirrors in the gateway tree.
- **Reasonable commit slicing for 5.7:** (1) pure plan + guard test + `.htaccess` (test scaffolding), (2) the CLI
  smoke runner + capture, (3) `docs`/runbook + verification template (separate doc commit, project-context.md:104).
  Commit style `<type>(kuickpay): <summary>`, imperative, ≤72 chars — e.g.
  `test(kuickpay): add opt-in live smoke gate and guard`, `docs(kuickpay): add live smoke runbook`.

### Project Structure Notes

- Gateway tree only: `components/gateways/nonmerchant/kuickpay/tests/{live/KuickPayLiveSmokePlan.php,
  live/kuickpay_live_smoke.php, KuickPayLiveSmokeGuardTest.php, .htaccess}` + `docs/kuickpay/live-smoke-*.md`. No
  plugin, no core, no `lib/` production additions, no new root `tests/`.
- Style: legacy global class / plain script (no namespace, no `declare(strict_types=1)`); short array syntax,
  single quotes, LF, one space around operators (component-local `PSR2 Transitional`). No broad reformat.
- The smoke is **verification scaffolding** — keep the pure decision in the test area, not in `lib/`
  (project-context.md; 5.1 Dev Notes "New files (verification scaffolding + docs only)").
- Generated-artifact hygiene: keep `docs/kuickpay/` + `_bmad-output/` doc commits separate from the
  test-scaffolding commit (project-context.md:104).

### References

- [Source: epics.md#Story-5.7 (1001–1023)] — the three ACs + "supersedes the original live/sandbox story";
  FR28 (79), FR29 (81); NFR8 (101), NFR10 (105), NFR11 (107), NFR12 (109).
- [Source: epics.md#Epic-5 intro (843–846)] — "KuickPay provides no sandbox … the only real-provider path is the
  manual, opt-in, default-skipped smoke (Story 5.7)."
- [Source: 5-1-stand-up-the-live-verification-stack-and-prove-a-real-reconcile-post-round-trip.md:91,121,144,258]
  — live SOAP leg explicitly routed to 5.7 (opt-in only); `tests/integration/*.php` CLI-script + `PHP_SAPI`-guard
  precedent; the SOAP factory seam.
- [Source: docs/kuickpay/risk-acceptance-5-1-live-verification.md] — the residual this story's mechanism addresses.
- [Source: docs/kuickpay/phase-0-contract.md:26-27,41-50,78,100,121,130] — no sandbox; no hard-coded
  endpoints/credentials/Institution ID; no committed live response bodies; `BillPaymentInquiry` is the read-only
  reconciliation op; WHMCS posts on `00` (Blesta must not — the smoke never posts).
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php:52,68-121,130,150-163,256-266,
  297-305,367-382] — constructor + factory seam; `insertVoucher` (forbidden) / `billPaymentInquiry` / `echoTest` /
  `getInstitutionsList`; the **unredacted** `raw_result`; `hasUsableWsdlUrl()` call-time guard; `withCredentials()`.
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php:71,86,100,164] —
  `redactArray`/`sensitiveValues`/`redactEnvelope`/`traceId`.
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayResponseParser.php:61] — `parse()` → `KuickPayEvidence`.
- [Source: components/gateways/nonmerchant/kuickpay/lib/KuickPayEvidence.php:72-142] — safe getters
  (`status`/`errorClass`/`evidenceHash`/`redactedTraceId`/`validationErrors`) vs. payload getters to suppress.
- [Source: components/gateways/nonmerchant/kuickpay/tests/bootstrap.php] — loads the four `lib/*` classes the smoke
  reuses; no framework stubs.
- [Source: plugins/kuickpay_reconcile/tests/.htaccess] — `Require all denied` web-block precedent to copy.
- [Source: plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php:9] — `PHP_SAPI !== 'cli'` guard.
- [Source: plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php] — forbidden credential/PII scan patterns
  to reuse for the AC3 capture guard.
- [Source: architecture.md] — SOAP-client-only call site + gateway ownership (331, 520–522, 770, 776); no
  hard-coded endpoints/credentials (83, 657); posting contract — only `KuickPayPostingService` moves money
  (581–593); Anti-Patterns (648–662).
- [Source: project-context.md:33,39,70,74,104,107,112,125] — secrets handling; 8.3 runtime / 8.2 floor; no new root
  tests; `--bootstrap` runner (not `-c build/phpunit.xml`); doc-commit separation; no live endpoints in default
  tests; no secrets in docs/commits.
- Memory: `[[kuickpay-failclosed-empty-currency-red]]` (disclose baseline red), `[[kuickpay-php82-toolchain-now-available]]`
  (8.3 runtime / 8.2 floor), `[[kuickpay-real-inquiry-response-shape-validator-fix]]` (real paid inquiry has no
  currency + echoes consumer# in field[1] — the smoke must redact the echo), `[[kuickpay-parser-single-identity-contract]]`
  (a single inquiry validates ONE identity field), `[[kuickpay-wsdl-ssrf-save-chokepoint]]` (the SSRF guard is at save;
  the smoke relies on the client's call-time `hasUsableWsdlUrl()`, don't re-add the private-range guard).

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- 2026-06-16: Loaded workflow customization with `python3.12`; no activation prepend/append steps, project context loaded.
- 2026-06-16: Story started from baseline commit `7c3835d88aea366b4297d66b8d549c7d1ed83bdb`; sprint status moved to in-progress.
- 2026-06-16: RED: guard test failed before `tests/live/KuickPayLiveSmokePlan.php` existed.
- 2026-06-16: GREEN: `/usr/local/bin/php -d display_errors=1 /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayLiveSmokeGuardTest.php` passed (6 tests, 38 assertions).
- 2026-06-16: Lint: `/usr/local/bin/php -l` and `/opt/cpanel/ea-php82/root/usr/bin/php -l` passed for `KuickPayLiveSmokeGuardTest.php` and `KuickPayLiveSmokePlan.php`.
- 2026-06-16: Runner lint passed with `/usr/local/bin/php -l` and `/opt/cpanel/ea-php82/root/usr/bin/php -l`.
- 2026-06-16: No-opt-in smoke run printed `SKIPPED` / `opt-in-not-set` and exited 0 without constructing the SOAP client.
- 2026-06-16: Incomplete opt-in smoke run (`KUICKPAY_LIVE_SMOKE=1` only) printed `SKIPPED` / missing env names and exited 0 without constructing the SOAP client.
- 2026-06-16: Confirmed `components/gateways/nonmerchant/kuickpay/tests/live` contains no `*Test.php` files.

### Completion Notes List

- Task 1 complete: added a pure, injected-env live smoke plan under gateway tests; default is skip/no-op unless `KUICKPAY_LIVE_SMOKE=1` and all required protected env inputs are present.
- Task 1 complete: added default-suite guard coverage for absent opt-in, incomplete opt-in, full opt-in config mapping, safe operation fallback, timeout fallback, and sanitized capture scan controls.
- Task 4.1 partial complete with Task 1 unit: added gateway `tests/.htaccess` matching the plugin deny precedent so the future live script is not web-reachable under the test tree.
- Task 2 complete: added the CLI-only, non-discovered live smoke script that gates on the pure plan, constructs the real `KuickPaySoapClient` only after full opt-in, invokes only read-only operations, parses evidence, and emits only allowlisted redacted diagnostics.
- Task 3.1 complete: added optional `KUICKPAY_SMOKE_CAPTURE` handling that writes only the redacted response envelope or the redactor placeholder, never `raw_result`.
- Task 4 complete: the live smoke script's first executable statement is the `PHP_SAPI !== 'cli'` refusal before requires or env reads, and the gateway tests directory is web-denied.

### File List

- components/gateways/nonmerchant/kuickpay/tests/.htaccess
- components/gateways/nonmerchant/kuickpay/tests/KuickPayLiveSmokeGuardTest.php
- components/gateways/nonmerchant/kuickpay/tests/live/KuickPayLiveSmokePlan.php
- components/gateways/nonmerchant/kuickpay/tests/live/kuickpay_live_smoke.php
- _bmad-output/kuickpay/implementation-artifacts/5-7-opt-in-live-kuickpay-smoke-no-sandbox.md
- _bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml
