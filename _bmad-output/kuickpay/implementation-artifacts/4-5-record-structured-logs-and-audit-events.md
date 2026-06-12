---
baseline_commit: bc986e92
prior_baseline_commit: bfc9f0ff0ad8e0cbfc7790de19cb9f131ee9277d
resync_note: "Re-synced to HEAD bc986e92 after Story 4.4 landed; anchors that 4.4 shifted (audit-table schema, item/audit trace columns, cron instantiations, presenter sync-guard test) re-derived, and the AC7 baseline-failure root cause corrected (see round-1 validation reports for 4.5)."
---

<!-- Powered by BMAD-CORE™ -->

# Story 4.5: Record Structured Logs and Audit Events

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator,
I want structured logs and durable audit events for KuickPay operations,
so that support and finance can investigate safely without leaking secrets.

## ⚠️ READ FIRST — this is a consolidation/hardening story, NOT a greenfield build

The audit + redaction + correlation-id infrastructure **already exists** and was built across Epics 1–3 and
Stories 4.1–4.3. **Do not reinvent any of it.** Before writing a line, internalize what is already shipped (exact
anchors in Dev Notes → "What already exists"):

- **Audit write path:** `KuickPayAuditService::record(string $eventName, array $context): void`
  (`plugins/kuickpay_reconcile/lib/KuickPayAuditService.php:30-44`) → `KuickPayAuditRepository::add()` →
  `KuickpayAuditEvents::add()` (`models/kuickpay_audit_events.php:21-28`) → table `kuickpay_audit_events`
  (schema at `kuickpay_reconcile_plugin.php:419-433` — shifted +37 by Story 4.4; was `:382-396` at the prior baseline).
- **17 audit events already emitted and registered** through a **4-site drift guard** (presenter map + language
  file + test `KNOWN_EVENTS` + a hard-coded "17" count message). Adding/renaming an event without updating all
  four **fails the build** (`KuickPayVoucherListPresenterTest`).
- **Redaction boundary:** `KuickPayRedactor` (`components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php`)
  — `redactArray()`, `redactEnvelope()`, `sensitiveValues()`, `traceId()` (the `kp_` + 16-hex correlation id).
- **Operational log seam (today):** the **gateway** writes Blesta logs from **two** sites today — the issuance path
  (`kuickpay.php:1234, 1290`, group `kuickpay:voucher_issue`) **and** a reference-generation-failure diagnostic
  (`kuickpay.php:1360-1361`, group `kuickpay:reference_generation`), both gated by the `logging_enabled` gateway
  setting. The **SOAP client writes no operational log at all**, and the cron reconcile/posting paths rely solely on
  audit events. (AC1's "one canonical shape" targets the **SOAP-operation** logs — see Task 3 for how the
  pre-existing `kuickpay:reference_generation` diagnostic is scoped.)

This story's real work is four narrow things: (1) make operational **logs** carry the AC1 field set and always
mask passwords (AC1/AC2-log); (2) close the **two audit-coverage gaps already assigned to 4.5** in `deferred-work.md`
and fix the posting-event correlation-id gap (AC3-audit/AC4); (3) prove every audit payload is **redacted-only**
and every customer surface stays safe (AC5/AC6); (4) make the secret-leakage suite **honestly green** so AC's
"no leakage" claim is verifiable (AC7). Net new audit events: **exactly two** (`voucher.generation_failed`,
`evidence.error`). Net new tables/services: **zero**.

## Acceptance Criteria

> Sourced from `epics.md` Story 4.5 (lines 820–841); FR3 (line 29), FR9 (line 41), FR15 (line 53), FR27 (line 77);
> NFR1 (line 87), NFR4 (line 93), NFR8 (line 101), NFR14 (line 113); additional requirements / audit patterns
> (lines 124, 132–133, 141–143); UX-DR17 (line 184), UX-DR28 (line ~206); architecture **Audit and Logging
> Patterns** (lines 610–634), Authentication & Security / single redaction boundary (lines 371–377),
> Enforcement Guidelines (lines 636–646), Anti-Patterns "Storing raw SOAP or credentials in logs" (lines 656–657).
> Two coverage items are explicitly pre-assigned to this story in `deferred-work.md` (lines 75, 83).

1. **(AC1 — operational log field completeness)** Given a KuickPay SOAP operation runs (`InsertVoucher`,
   `BillPaymentInquiry`, `BillPaymentBulkInquiry`) and operational logging is enabled, when an operational log line
   is written, then it carries **operation name**, a **correlation id (`redacted_trace_id`) and/or Voucher id**, a
   **sanitized request summary**, a **sanitized response summary**, the **`error_class`** (null on success), and
   **`duration_ms` or a timestamp where available** — the full FR27 field set, all in **one canonical shape** (no two
   ad-hoc log schemas). A log line is written for **each** SOAP operation outcome (success and failure) on the path
   that runs it. **There are FOUR SOAP-log wiring sites (verified at HEAD), not two — and `KuickPayReconcileService`
   alone is constructed from THREE of them, not just cron.** Every one must wire the logger or AC1 is only half-met:
   - the **gateway** issuance path logs every `InsertVoucher` outcome (issuance seam, `kuickpay.php:1181`);
   - the **cron** reconcile path (`kuickpay_reconcile_plugin.php::cron()` → `runCron`) logs every `BillPaymentInquiry`
     (`KuickPayReconcileService.php:390`);
   - the **admin "Check Now"** path (`controllers/admin_vouchers.php:241`, `AdminVouchers::recheck()` →
     `reconcileVoucher()` → `processVoucher()`) also runs `BillPaymentInquiry` (`KuickPayReconcileService.php:390`);
   - the **admin bulk run** path (`controllers/admin_main.php:62`, `AdminMain::run()` → `runBulk()`) runs
     `BillPaymentBulkInquiry` (`KuickPayReconcileService.php:283`).

   The inquiry/bulk-inquiry logs are written through the **verified Blesta `Logger`** sink (Open Question #2 — now
   resolved; see Task 2); both admin controllers extend `KuickpayReconcileController → AppController`, so
   `getFromContainer('logger')` is available at each site exactly as in cron. The reconcile path may fall back to a
   **no-op** logger only if the container yields no logger, disclosed in the Dev Agent Record. The pre-existing
   non-SOAP `kuickpay:reference_generation` diagnostic is scoped out of the canonical shape per Task 3.

2. **(AC2 — logs are sanitized; passwords always masked; gated)** Every operational log value passes through the
   **single redaction boundary** (`KuickPayRedactor`) before it is written. **No** password, username,
   `InstitutionID`, raw SOAP envelope (`<…Envelope|Header|Body…>`), raw `*Result` element, customer PII (mobile,
   CNIC, email), or unredacted fault/stack text appears in any log line — the SOAP client's **`raw_result` /
   unredacted envelope are never logged** (they are parser-only input). Logging is **gated by the `logging_enabled`
   gateway setting** on every write path; with logging disabled, no operational log line is produced. (FR3, NFR1,
   NFR8, architecture lines 371–377, 656–657.)

3. **(AC3 — audit event names are lower dot notation)** Given any KuickPay lifecycle, inquiry, posting, admin
   decision, retry, or reconciliation event occurs, when its audit record is created, then the event name uses
   **lower dot notation** matching `^[a-z][a-z_]*(\.[a-z][a-z_]*)+$` (e.g. `voucher.issued`, `evidence.received`,
   `posting.succeeded`, `admin.reviewed`). This holds for **all** currently-emitted events **and** the two new
   events this story adds. The **4-site drift guard** (presenter `EVENT_LABEL_KEYS`, the `admin_vouchers` language
   file, the test `KNOWN_EVENTS`, and the count-assertion message) is updated to **19** and stays internally
   consistent. A test asserts the lower-dot-notation invariant over `KNOWN_EVENTS`.

4. **(AC4 — audit coverage across the operation lifecycle; correlation id on posting)** Given the operations
   enumerated by NFR4 (Voucher lifecycle, inquiry attempts, posting decisions, admin actions, reconciliation runs)
   occur, when they complete — **including the two paths currently un-audited** — then a durable audit event is
   recorded: (a) a **reference-generation failure** in `KuickPayVoucherReferenceService` records
   **`voucher.generation_failed`** (closes `deferred-work.md:75`); (b) a **per-voucher processing exception** in
   `KuickPayReconcileService` (the `Throwable` catch that today writes only a `kuickpay_reconciliation_items` row)
   records **`evidence.error`** (closes `deferred-work.md:83`); and (c) **posting audit events carry a non-empty
   `redacted_trace_id`** propagated from the voucher's stored evidence, fixing the hard-coded empty string at
   `KuickPayPostingService.php:388`.

5. **(AC5 — audit payloads contain redacted fields only)** Given any audit record is written, then its `payload`
   (and the `redacted_trace_id` / `evidence_hash` columns) contain **safe tokens only** — status names, error
   classes, ids, `staff_id`, counts, outcome tokens — and **never** a password, raw SOAP/XML, a `*Result` element,
   customer PII, a credential key, a stack trace, or a SOAP operation's raw fault text. This holds for the two new
   events and is proven by the secret-leakage scan over persisted audit events (AC7). (Architecture line 634;
   epics line 836 "payloads contain redacted fields only".)

6. **(AC6 — customer surface stays generic and safe)** Given any customer-facing page renders (gateway
   `process.pdt`, client invoice/payment views), when logs or audit records exist for that voucher, then **no
   audit record, log line, or raw diagnostic is rendered to the customer** and customer messages remain
   **generic** (the existing `Kuickpay.process.*` copy). Raw diagnostics remain **admin-only** and permission-gated
   (the Story 4.2 detail timeline + diagnostics box, which already gate on the `diagnostics` ACL action). No new
   customer-visible field, status string, or provider detail is introduced by this story. (NFR8, UX-DR28,
   architecture lines 437, 608.)

7. **(AC7 — leak scan covers the new surfaces and runs honestly green)** The `KuickPaySecretLeakageTest`
   forbidden-pattern scan is **extended** to capture the two new audit-event emission paths and the operational-log
   payload shape, and asserts they contain no forbidden secret/PII/raw-envelope values. The **documented
   pre-existing baseline failure** in this suite (`testPersistedEvidenceAndAuditPayloadsContainNoSecretsOrRawEnvelopes`
   — the `confirmed_unposted` vacuous-guard assertion at `KuickPaySecretLeakageTest.php:87`, **not** a leak) is
   **resolved** so this story can claim a genuinely green leakage suite for AC's "without leaking secrets". The true
   root cause is the **single-inquiry single-identity contract** (the confirmed-capture voucher carries a
   `consumer_number` that mismatches the single echoed registration field → `unmatched_reference` → `manual_review`),
   **not** a null/missing paid date — so the fix is a **one-line test-fixture identity alignment with no production or
   paid-date change** (see Dev Notes → "Resolving the secret-leakage baseline" for the verified root cause and the fix).
   The resolution **must not weaken any `fixtureForbiddenPatterns()` / `persistedForbiddenPatterns()` regex**.

8. **(AC8 — verification honesty)** Every changed PHP file passes `php -l`. The component PHPUnit 8.5 suite runs via
   the project-context command; new pure/unit-testable logic (the log-shape builder, the event-name invariant, the
   new audit emissions through fakes) is covered. The report states the exact commands and the **PHP runtime
   actually used** (PHP availability varies by checkout — prior Linux hosts exposed 8.3.x / 7.4.x; this macOS
   checkout has **no native PHP** at all, so the suite runs only via a container such as `php:8.2-cli` — write
   **PHP 8.2-compatible syntax** and disclose whatever runtime you actually used), and names anything that could not
   run (no live Blesta DB/admin/gateway-log
   stack here — the live `$this->log()` write, the Blesta `Logger` sink, and the `.pdt` render are `php -l` +
   review only). PHP 8.2 syntax only. (NFR12.)

## Tasks / Subtasks

- [x] **Task 1 — Canonical sanitized operational-log shape + SOAP-client operation logging (AC1, AC2)**
  - [x] Define **one** structured log payload shape, reused by every KuickPay operational log write:
        `['operation' => <op>, 'redacted_trace_id' => <kp_…>, 'voucher_id' => <int|null>, 'request_summary' =>
        <redacted array>, 'response_summary' => <non-XML safe-token array>, 'error_class' => <?string>,
        'duration_ms' => <int|null>]`. **`response_summary` is a token set, NOT any envelope string.** Use the
        FR27-complete shape `['response_present' => <bool>, 'result_present' => <bool>, 'result_code' => <?string>,
        'fault' => <?string>]`, where:
        - `response_present` / `result_present` are presence booleans (`result_present = raw_result !== null && !== ''`);
          a bare presence bit alone is **not** an FR27 "response summary", hence the `result_code` below.
        - `result_code` is the **first two characters of `raw_result` ONLY when they match `/^[A-Za-z0-9]{2}$/`**, else
          `null` — so the non-secret leading status code (`00`/`05`) is summarized, but bulk-XML/CDATA datasets and any
          tag-bearing value collapse to `null`. It must be **extracted upstream and passed in as a 2-char token**; the
          builder still **never receives `raw_result`**.
        - **`fault` must be a log-safe ENUM token, NOT raw `redactedDiagnosticText()` output.** *(Critical — verified:*
          `KuickPaySoapClient::redactedDiagnosticText()` (`:456-493`) only attempts XML redaction when the text holds
          both `<` and `>` **and** `redactEnvelope()` parses; otherwise it leaves the original text, and even on success
          `redactEnvelope()` returns `saveXML()` output that **keeps** structural `<…Envelope|Header|Body…>` and `*Result`
          tags. Its key-value regex (`:487-491`) also **omits CNIC**, and strips credential *values* but not the bare
          key **names** the scan forbids — so routing `fault` straight into a log can trip the unweakened
          `persistedForbiddenPatterns()`.) Collapse `fault` to a bounded token —
          `timeout | transport_error | provider_fault | provider_fault_with_response | invalid_wsdl | xml_fault_redacted`
          (`null` when there is no fault): if the candidate contains `<`/`>` or any
          `Envelope|Header|Body|*Result|userName|password|InstitutionID` substring, collapse to `xml_fault_redacted` /
          `provider_fault`; as a **final guard**, run the `persistedForbiddenPatterns()` intent over the token and
          replace any match with `provider_fault`. **Never** log raw fault text, stack traces, transaction refs,
          consumer numbers, or customer fields.
        Put a small pure builder where it is unit-testable — recommended: a `static` helper on `KuickPayRedactor`
        (e.g. `operationLogFields(...)`) **plus** the fault collapser (e.g. `logSafeFaultToken($fault, $error_class)`),
        or a tiny new `lib/KuickPayOperationLog.php` builder in the **gateway** lib dir (keep the boundary — log shaping
        is gateway/protocol-layer, not plugin business logic). The builder takes **already-redacted, already-tokenized**
        inputs and returns the array; it **must not** accept or pass through `raw_result` or an envelope string
        (redacted or not). **If you create `lib/KuickPayOperationLog.php`, add a `require_once` for it to
        `components/gateways/nonmerchant/kuickpay/tests/bootstrap.php`** (which today requires only `KuickPayRedactor`,
        `KuickPaySoapClient`, `KuickPayEvidence`, `KuickPayResponseParser`) **and** add a gateway-side unit test
        asserting the builder carries the field set, drops `raw_result`, collapses a tag-bearing/PII-bearing fault to a
        safe token, and never emits an envelope string.
  - [x] In `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php::call()` (`:134-224`), **measure
        duration** — `duration_ms` is **net-new** (it does not exist anywhere in the file today). Put
        `$start = microtime(true)` at the **very top** of `call()` (covering that attempt's WSDL preflight + SOAP time)
        and compute `duration_ms = (int) round((microtime(true)-$start)*1000)` at each return. **`duration_ms` is
        per-transport-attempt, NOT full-operation-across-retries** — the retry loop lives in `callWithRetry()`
        (`:252-267`), **not** in `call()`, so each `call()` measures exactly one attempt. With per-attempt logging
        (OQ#5) that is the correct granularity: each log line carries its own attempt's duration. Add `duration_ms` as a new field on the **`outcome()` builder method — its body is at
        `:521-542`** (the block at `:116-128` is only the `call()` outcome-shape **docblock**; update both). Preserve
        the `finally` block (`:219-223`) that restores `default_socket_timeout`. The outcome **already** carries
        `operation`, `redacted_request`, the redacted `raw_envelope`, `error_class`, raw `fault`, and
        `redacted_trace_id` — build `response_summary` as the four-token set above (presence bits + `result_code` + the
        **log-safe `fault` token**), **never** from `raw_result` **or** the (still-tag-bearing) redacted envelope. The
        invalid-WSDL early return becomes `['response_present'=>false, 'result_present'=>false, 'result_code'=>null,
        'fault'=>'invalid_wsdl']` with `error_class='transport_error'`.
  - [x] **Route the log seam through the single `outcome()` choke point, not the call sites.** `call()` has **six**
        `return $this->outcome(...)` exit paths (grep-verified at HEAD: lines `:140, :159, :172, :184, :197, :209`) —
        the invalid/unsafe-WSDL **early return** (`:140`), the success path (`:159`), and a fault-with-response /
        fault-without-response branch in **each** of the `SoapFault` (`:172` / `:184`) and `Throwable` (`:197` /
        `:209`) catches. Logging only "after success and both catch branches" would **miss the WSDL early return**.
        Invoke the logger from `outcome()` (or a single private helper every return funnels through) so **every**
        outcome — success and failure — produces a log line, satisfying AC1's "each SOAP operation outcome". (The
        choke-point design is correct regardless of the exact count; the scalar is fixed only so an "assert N log
        lines" test enumerates the real six.)
  - [x] **Retry semantics + the `attempt` seam (decision — Open Question #5):** because the logger fires inside
        `outcome()` (inside `call()`), and `callWithRetry()` (`:252-267`) invokes `call()` up to **three** times for
        inquiry/bulk-inquiry operations, a retried operation emits **one log line per transport attempt**. **Default:
        keep per-attempt logging** (it is the diagnostic value of an operational log — you see each retry); do **not**
        move logging out of the choke point into `callWithRetry()`.
        - **The attempt index is NOT available at the choke point as the code stands** — `callWithRetry()` stamps
          `$outcome['attempts'] = $attempt` only **after** `call()` returns (`:259`), and `outcome()` defaults
          `attempts` to `1` (`:540`); an `outcome()`-time logger would therefore log `attempt=1` for every retry. To
          make the per-attempt `attempt` field real, **thread the index into the choke point** (a small, required seam
          change): give `call()` a new last param `int $attempt = 1`, have `callWithRetry()` call
          `$this->call($operation, $params, $attempt)` inside its loop, and pass `$attempt` into `outcome(...)` so the
          log fields carry it. `insertVoucher()` calls `call()` directly (no retry) and stays `attempt = 1`.
        - **Name it `attempt` (singular, this line's 1-based index), distinct from the outcome's existing `attempts`
          (plural, the total retry count set at `:259`)** — do not reuse `attempts` for the log field or C1 reappears.
        - The new gateway-side test should assert the expected number of lines (and ascending `attempt` values) for a
          retried timeout. Flag OQ#5 if the team instead wants exactly one line per public operation after retry
          resolution (that would require logging after the loop in `callWithRetry()`, abandoning the single choke point
          and re-opening the WSDL-early-return coverage gap for `insertVoucher()`).
  - [x] Add an **injected, optional log seam** to the SOAP client. The current constructor is
        `__construct(array $config, callable $soapClientFactory = null)` (`:46`); add `?callable $logger = null` as the
        **last** parameter (existing callers use positional args and are unaffected; the `KuickPaySecretLeakageClient`
        fake has its own constructor and needs no change — verify it still constructs). When a logger is set, invoke it
        from the `outcome()` choke point (above) with the canonical shape built from the **redacted** outcome fields.
        This keeps the SOAP client free of any framework `$this->log()` dependency (it is a pure lib used by both
        gateway and cron) and makes the log content unit-testable by injecting a capturing closure. **Do not** log
        inside `call()` via `error_log`/`echo`/`print` — only through the injected seam.
        - **Boundary note:** `echoTest()` and `getInstitutionsList()` (`:94-114`) also route through `call()`, but they
          are **setup-test helpers with no production caller** (only `KuickPaySoapClientTest` uses them) and are **not**
          in AC1's operation set (`InsertVoucher`/`BillPaymentInquiry`/`BillPaymentBulkInquiry`). Placing the logger in
          `outcome()` means a future caller that injects a logger would log these too — acceptable, but out of scope
          for AC1 today; no wiring for them is required.
  - [x] Honor enablement at the **caller**, not inside the SOAP client: the caller passes a logger closure only when
        `logging_enabled === 'true'` (so a disabled gateway produces a true no-op and AC2's "gated" holds without
        the lib knowing about gateway meta).

- [x] **Task 2 — Wire the log seam from EVERY SOAP-running caller (AC1, AC2)**
  - [x] **Wiring checklist — all four sites must be wired (a dev who wires only the first two leaves manual Check Now
        and admin bulk runs silent even with `logging_enabled === 'true'`):**
        1. Gateway `getSoapClient()` / `InsertVoucher` **issuance** path — `kuickpay.php`.
        2. Plugin **cron** `reconcile_pending` — `kuickpay_reconcile_plugin.php::cron()` instantiation at **`:197`**
           (the `:205` instantiation runs `expirePending()` → **no SOAP**, so it needs no logger).
        3. Admin **Check Now** single inquiry — `AdminVouchers::recheck()`, `controllers/admin_vouchers.php:241`.
        4. Admin **bulk run** — `AdminMain::run()`, `controllers/admin_main.php:62`.
  - [x] **Gateway/checkout path (site 1)** — where the gateway constructs/uses `KuickPaySoapClient` for `InsertVoucher`
        (issuance), pass a logger closure that calls the **verified** gateway log API
        `$this->log('kuickpay:' . $operation, json_encode($fields), 'output', $ok)` (same API already used at
        `kuickpay.php:1234, 1290`), **only when** `($meta['logging_enabled'] ?? 'true') === 'true'`. `$fields` is
        the canonical redacted shape from Task 1; **`$ok` is the transport-success boolean** sourced from the outcome's
        `ok` field (the logger seam passes it to the closure) — it is the **4th `$this->log()` arg, not** a member of
        the json-encoded `$fields` set. Define the seam signature precisely, e.g. `callable(array $fields, bool $ok): void`.
  - [x] **Plugin reconcile-service paths (sites 2–4) — one shared logger-dependency pattern for EVERY
        `new KuickPayReconcileService(...)` that can run SOAP.** `KuickPayReconcileService` builds the client via its
        `client_factory` (`KuickPaySecretLeakageTest.php:205-207` shows the injection seam) and resolves
        `logging_enabled` from the already-loaded gateway config: `gatewayConfigForCompany()` (`:702-728`) returns
        `'logging_enabled' => $meta['logging_enabled'] ?? 'false'` (`:728`), so the reconcile paths are
        **off-by-default** (the gate checks `=== 'true'`), **unlike** the gateway runtime default of `'true'` — an
        intentional asymmetry (cron/admin may run before any gateway settings are saved). When it resolves to `'true'`,
        the service builds a logger closure that calls `$logger->info()` (success) / `$logger->error()` (failure) with
        the canonical redacted shape. **The service owns the enablement decision; each construction site owns supplying
        the `logger` dependency.** Pass the `Logger` into the service via its `dependencies` array (constructor
        `:30-46`, e.g. a new `logger` key); the service still decides whether to hand a SOAP-client logger closure to
        the client.
        - **Sink decision (Open Question #2 — RESOLVED):** a verified Blesta `Logger` sink **exists in this checkout**
          (Monolog-backed container service registered at `config/services.php` / `core/ServiceProviders/Logger.php`),
          fetched via `$this->getFromContainer('logger')` at real in-repo call sites (`components/email/email.php:90`,
          `plugins/support_manager/support_manager_plugin.php:33`).
        - **Site 2 (cron):** `KuickpayReconcilePlugin extends Plugin`, so in `kuickpay_reconcile_plugin.php::cron()`
          (method `:188`) fetch `$logger = $this->getFromContainer('logger')` and pass it into the **`:197`**
          `KuickPayReconcileService` instantiation (`runCron`). Skip the `:205` instantiation (`expirePending`, no SOAP).
        - **Sites 3–4 (admin):** both `AdminVouchers` and `AdminMain` extend `KuickpayReconcileController → AppController`,
          so `$this->getFromContainer('logger')` is available in the controller exactly as in cron (verified). Fetch the
          logger in `AdminVouchers::recheck()` (`:206`, instantiates at `:241`) and `AdminMain::run()` (`:39`,
          instantiates at `:62`) and pass it into the service the same way. Do **not** leave these two paths unwired.
        - The **no-op fallback** is only a defensive last resort (container yields no logger), disclosed in the Dev
          Agent Record if used. **Never** invent an unverified logging API — the `Logger` container key above **is**
          verified, so use it.
  - [x] Confirm all wirings pass **only redacted** fields (Task 1 builder enforces this) and that `raw_result` is
        never handed to the logger.

- [x] **Task 3 — Normalize the gateway issuance-outcome log to the canonical shape (AC1, AC2)**
  - [x] Bring `recordIssuanceDiagnostic()` (`kuickpay.php:1284-1302`) and the issuance-exception log
        (`:1233-1244`) into the **same** canonical shape (Task 1): add `operation => 'InsertVoucher'`, keep
        `redacted_trace_id`, add `voucher_id` where known, and a redacted `request_summary`/`response_summary`
        (use the evidence's redacted fields — **not** raw envelope). Preserve the existing `logging_enabled` gate
        and the success flag (`$evidence->status() === 'pending'`). Do not change the log group string
        `kuickpay:voucher_issue` (existing tests reference the gateway log behavior).
  - [x] **Pre-existing second gateway log schema — disclose and scope (do not silently leave AC1 half-met).** A third
        `$this->log(...)` already exists at `kuickpay.php:1360-1361` (group `kuickpay:reference_generation`, payload
        `{event:'reference_generation_failed', reason, invoice}`) inside `recordReferenceGenerationFailure()`
        (`:1349-1370`), gated by `logging_enabled`. It is **already redacted-safe** (event token + reason code + integer
        invoice — no PII/SOAP). AC1's "one canonical shape (no two ad-hoc schemas)" is about the **SOAP-operation**
        logs; this is a **pre-SOAP** reference-generation diagnostic that does not map to a SOAP operation, so it is
        **explicitly scoped out** of the canonical-shape normalization. Leave it as-is — it is the gateway operational
        counterpart to the new plugin-owned `voucher.generation_failed` **audit** event (Task 5). Record this decision
        in the Dev Agent Record so AC1's "no two ad-hoc schemas" claim is honestly bounded to the SOAP-operation logs.
  - [x] Note (not a required change): the `logging_enabled` **SOAP-config default** is `'false'` (read in
        `getSoapClient()` `:598`) while the runtime gate default is `'true'` (`:1233, 1286`). Leave behavior as-is
        unless trivially harmonizable without risk; if you touch it, record the decision. It is **not** an AC.

- [x] **Task 4 — Fix posting-event correlation id propagation (AC1, AC4)**
  - [x] In `KuickPayPostingService::recordAudit()` (`lib/KuickPayPostingService.php:382-395`), replace
        `'redacted_trace_id' => ''` with the voucher's stored trace id. Source it by decoding the voucher's
        `diagnostic_summary` JSON — **this is the only source; there is NO `redacted_trace_id` column on
        `kuickpay_vouchers`** (the `redacted_trace_id` columns at `kuickpay_reconcile_plugin.php:400/:425` — shifted
        +37 by Story 4.4, were `:363/:388` at the prior baseline — belong to the items / audit tables, and the vouchers
        model allowlist carries `diagnostic_summary`, not a trace id). The
        production write reliably embeds it: `KuickPayReconcileService::diagnosticSummary()` emits
        `'redacted_trace_id' => $evidence->redactedTraceId()` at `:547`, and issuance writes it via
        `KuickPayEvidence::toArray()` (`KuickPayEvidence.php:154`) — so any confirmed voucher carries a non-empty `kp_…`
        (the fixture at `KuickPaySecretLeakageTest.php:132-137` mirrors this; the `:323-327` default block does **not**
        carry it — don't copy that shape). Access pattern: `$voucher` is a `Record->fetch()` **stdClass**, so read
        `$voucher->diagnostic_summary` then `json_decode((string) …, true)['redacted_trace_id'] ?? ''` — the identical
        decode already ships at `controllers/admin_vouchers.php:176`. Guard: default to `''` if absent/malformed, never
        throw. Keep the surrounding `try/catch (Throwable)` so an audit write still never aborts posting. (AC4(c)'s
        non-empty mandate is realistically met here — the confirming inquiry always wrote a real trace id.)
  - [x] Verify the three posting events (`posting.started/succeeded/failed`) now carry the propagated id in a unit
        test (`KuickPayPostingServiceTest`) using the existing fake audit service.

- [x] **Task 5 — New audit event `voucher.generation_failed` (AC3, AC4, AC5)**
  - [x] Emit `voucher.generation_failed` from the plugin service
        `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php`. **Anchor correction:** there is **no**
        `recordReferenceGenerationFailure()` method in this file — that name is a **gateway** helper
        (`kuickpay.php:1349-1370`, the operational-log counterpart; do **not** touch it for this audit event, and do
        **not** move audit into the gateway). The real generation-failure sinks here are the **inline terminal
        `$this->lastError = '…'` assignments**: `uniqueness_exhausted` (`:129`), `invalid_registration_pattern`
        (`:301`), `invalid_consumer_pattern` (`:312`), and `duplicate_invoice_id` (`:397`). Emit via the
        **already-injected** `$auditService` (the same seam that emits `voucher.replaced`). Payload carries **safe
        tokens only**: the `lastError` **reason code** and `invoice_id` — **never** the raw exception, SOAP, or PII.
        Pass `company_id` (required int) and `voucher_id` where one exists (null for a pre-insert failure). Wrap in
        best-effort `try/catch (Throwable)` exactly as the other services do.
  - [x] **`company_id` is NOT uniformly in scope — emit at the right place per site (verified):**
        - `uniqueness_exhausted` (`:129`) lives in `getOrCreateForInvoiceContext()`, where `$company_id` is a local
          extracted at `:77` — emit inline with `$company_id`.
        - `invalid_registration_pattern` (`:301`) / `invalid_consumer_pattern` (`:312`) live in
          `generateReferences(array $context)` — there is **no** local `$company_id`, but `$context['company_id'] ?? 0`
          is available; use that.
        - `duplicate_invoice_id` (`:397`) lives in `normalizeContextInvoiceAmounts(array $invoiceAmounts)`, which
          receives **only** the invoice subset — **no `company_id` in scope, so it is unimplementable there.** Do **not**
          emit at `:397`. Instead emit at the **call site** in `getOrCreateForInvoiceContext()` (the
          `KuickPayVoucherReferenceService.php:66-68` null return): after `normalizeContextInvoiceAmounts()` returns
          null, check `$this->lastError === 'duplicate_invoice_id'` and emit using `$context['company_id'] ?? 0`. (Do
          not pollute the pure validation method's signature, and do not push the emit into the gateway — keep it
          plugin-owned.) The `?? 0` is **defensive only** — every real gateway path into `getOrCreateForInvoiceContext()`
          supplies `company_id` in `$context`, so a `company_id = 0` audit row is not expected in production.
        - `invalid_registration_pattern` / `invalid_consumer_pattern` are **deterministic config errors** that recur on
          every payment attempt until the admin fixes the pattern/setting — keep `invoice_id` in the payload so the 4.2
          audit timeline can group/deduplicate consecutive identical failures.
  - [x] **Respect the boundary precisely (per `deferred-work.md:75`):** emit **only** on those four terminal
        `lastError` paths. Do **NOT** emit on: the `Throwable` catch at `:170` (returns null, sets no `lastError`), the
        `amount_changed` gate at `:100/:109` (a Story-2.4 amount-mismatch, not a generation failure), or any transient
        `not_ready` early `return null` (empty invoice id, race-recovery null). `create_failed` is **not implemented**
        (only an unimplemented suggestion in `deferred-work.md:103`) — do **not** invent it here; this story audits the
        existing reason codes only. The **`create()` fall-through** at `:159-174` (insert fails, race re-lookup misses,
        falls to `return null`) sets **no `lastError`** and so is correctly out of scope — note in the Dev Agent Record
        that this gap (`deferred-work.md:103`) is intentionally not closed by 4.5, so a reviewer isn't surprised a
        create-failure produces no audit row. **No double-emit risk:** `getOrCreateForInvoiceContext()` is called only
        from the gateway (`kuickpay.php:1069/:1099`, mutually exclusive branches) — no cron path invokes the reference
        service — and the four `lastError` sites are mutually exclusive within one call.
  - [x] Register the event through **all four** drift-guard sites (Task 7).

- [x] **Task 6 — New audit event `evidence.error` (AC3, AC4, AC5)**
  - [x] Emit `evidence.error` in `KuickPayReconcileService::processVoucher()` at the **per-voucher `Throwable` catch**
        (**`:408-424`** — today it records a `kuickpay_reconciliation_items` row with `error_class='reconcile_exception'`
        but **no** audit event; the `deferred-work.md:83` citation's old `:138-149` line range is **stale** — `:138-149`
        is the `run()` loop body, not the handler). Add the audit write **beside** the existing item-row write, with
        audit context `['company_id'=>…, 'voucher_id'=>…, 'run_id'=>…]` and payload
        `['error_class' => 'reconcile_exception']`. **Trace-id sourcing (verified trap — do NOT copy Task 4's
        approach here):** `$evidence` is assigned only at `:391`, so it is **not guaranteed to be assigned** in the
        `:408` catch — the transport call at `:390` can throw **before** `:391`, so the catch must not unconditionally
        read `$evidence`; and the voucher's **stored `diagnostic_summary` is STALE here** — it holds the
        *prior* issuance/inquiry trace id, not the failed operation's, so decoding it would log a wrong correlation.
        Instead **hoist** a `$redactedTraceId = ''` before the `try` (`:389`), set
        `$redactedTraceId = $evidence->redactedTraceId();` immediately after parse (`:391`), and read **that variable**
        in the catch. This yields the correct id when the failure is post-parse (the common genuine-`Throwable` case,
        since transport timeouts are *returned* as evidence, not thrown) and a clean `''` only when the inquiry/parse
        itself failed. **`redacted_trace_id => ''` is AC-compliant for `evidence.error`** — AC4(c)'s non-empty mandate
        is **posting-only**; do not over-engineer a non-empty guarantee here. **Never** source from the unredacted SOAP
        outcome or the exception message/trace. Keep it inside the existing catch (which already wraps the item-row
        write in an inner `try/catch (Throwable)` at `:421`) so a failed audit cannot escalate the error.
  - [x] Do **not** change the existing transport-timeout behavior, which already audits via `evidence.retry_decision`
        — `evidence.error` is **only** for the genuine-exception path (the residual gap named in the deferred item).
  - [x] Register the event through **all four** drift-guard sites (Task 7).

- [x] **Task 7 — Update the 4-site audit-event drift guard to 19 + lower-dot-notation invariant (AC3)**
  - [x] Add `voucher.generation_failed` and `evidence.error` to
        `lib/KuickPayVoucherListPresenter.php::EVENT_LABEL_KEYS` (`:145-163`) →
        `'AdminVouchers.event.voucher.generation_failed'` and `'AdminVouchers.event.evidence.error'`.
        **Edit ONLY the audit `EVENT_LABEL_KEYS` map (17→19).** Story 4.4 added two **sibling** label maps to this same
        presenter — `RUN_TRIGGER_LABEL_KEYS` (`:214-218`) and `RUN_STATUS_LABEL_KEYS` (`:230-235`), each with its own
        drift tests — that have **nothing** to do with audit events; do **not** touch them or their counts.
  - [x] Add the matching keys to `language/en_us/admin_vouchers.php` (the `AdminVouchers.event.*` block the
        4.2/4.3 events live in) — safe, generic human labels (e.g. "Reference generation failed", "Processing
        error"); no raw tokens. Also fix the **stale section comment at `:143`** (it still reads "14 events +
        generic"; with both new keys it should read "19 events + generic").
  - [x] Add both to `tests/KuickPayVoucherListPresenterTest.php::KNOWN_EVENTS` (`:334`) **and** bump the count
        comment/`assertSame` message from **"17 emitted event names"** to **"19"** (`:333, :505`). Confirm
        `testEventMapKeysEqualTheKnownEvents`, `testEventLabelKeyForEveryKnownEvent`, and the language↔presenter
        sync guard (now `testLanguageFileDefinesEveryAllowlistedLabelKey()`, **`:801-841`** — shifted +127 by 4.4's
        run-trigger/run-status drift tests inserted above it; was `:674-707` at the prior baseline) stay green.
  - [x] Add a new test asserting the **lower-dot-notation invariant** over `KNOWN_EVENTS`: every name matches
        `/^[a-z][a-z_]*(\.[a-z][a-z_]*)+$/`. This guards AC3 for all current and future events.

- [x] **Task 8 — Customer-surface safety verification (AC6)**
  - [x] Confirm (read + `php -l`/review — no live render here) that **no** customer-facing template renders any
        audit record, log line, or raw diagnostic: gateway `views/default/process.pdt` and any client invoice/
        payment view. This story introduces **no** customer-visible field.
  - [x] **Admin audit-render surfaces (now TWO — Story 4.4 added a second; verify both stay admin-only/ACL-gated):**
        - **Story 4.2 voucher-detail timeline** — `admin_vouchers_detail.pdt` via `getByVoucher()`; ACL-gated on the
          `diagnostics` action.
        - **Story 4.4 run-detail drill-down** — `views/default/admin_reconciliation_detail.pdt` via
          `controllers/admin_reconciliation.php` and `models/kuickpay_audit_events.php::getByRun()` (`:74-85`).
          **Verified fact that resolves "where do the two new events surface":** `getByRun()` (and `getCountByRun()`,
          `:94-103`) filter `event_name IN ('evidence.unmatched', 'evidence.duplicate')` — an explicit **allowlist**.
          Neither new event (`evidence.error`, `voucher.generation_failed`) is in it, so **neither renders on the 4.4
          run-detail view** (intentionally not shown there; `evidence.error` is still reachable via the voucher-detail
          timeline through its `voucher_id`). No change to `getByRun()` is in scope for 4.5.
  - [x] Both admin surfaces are permission-gated; AC6 is about the **customer** surface and is unaffected. Record the
        verification in the Dev Agent Record (audit display surfaces are admin-only and already shipped; 4.5 adds none).

- [x] **Task 9 — Extend the secret-leakage suite + resolve the documented baseline failure (AC5, AC7)**
  - [x] Extend `tests/KuickPaySecretLeakageTest.php` to **capture the two new audit-event payloads** (drive the
        `voucher.generation_failed` and `evidence.error` emission paths through the existing fake-repo/fake-audit
        pattern and add their captured events to the scanned set) and the **operational-log payload** (inject a
        capturing logger into the SOAP client and assert the captured fields contain no forbidden value and that
        `raw_result`/raw envelope/credential keys never appear). **Drive the `fault` collapser adversarially** — inject
        SOAP faults whose message carries (a) valid XML with `Envelope`/`Body`/`InsertVoucherResult` tags, (b) mixed
        prose + XML that fails `redactEnvelope()` parsing, (c) a bare CNIC/mobile/email **not** present in the request
        params, and (d) credential **key names** without values — and assert each captured `fault` collapses to a
        bounded token that passes the scan. Reuse `persistedForbiddenPatterns()` — **do not weaken** any pattern. (This
        scan passes **only because** Task 1 makes `response_summary` a token set with a log-safe `fault` enum, not the
        redacted envelope or raw `redactedDiagnosticText()` output — both retain `<…Envelope|Header|Body…>`/`*Result`
        tags that would otherwise trip the raw-envelope regex at `:240`.)
  - [x] **Resolve the documented baseline failure** (`testPersistedEvidenceAndAuditPayloadsContainNoSecretsOrRawEnvelopes`,
        the `assertSame('confirmed_unposted', $repo->edits[0]['status'])` at `:87`). See Dev Notes → "Resolving the
        secret-leakage baseline" for the **verified root cause**: this is the **status guard, not a forbidden-pattern
        check** — in the red state the test aborts at `:87` before the persisted scan loop even runs (so it is not a
        leak). The assertion is red because the confirmed-capture voucher **violates the single-inquiry single-identity
        contract** — its
        `consumer_number` (`'INSTITUTION_IDREG-0000001'`, test `:304-305`) mismatches the single registration field the
        inquiry echoes (`'REG-0000001'`), so `KuickPayResponseParser` (`:534-539`) records `unmatched_reference` →
        `manual_review`. The paid date is **present and valid** (`20260609`), and there is **no** single-inquiry
        null-paid-date guard, so the paid-date framing does not apply.
  - [x] **Fix at the test-fixture layer only — no production/parser/paid-date change.** Make the confirmed-capture
        voucher honor the single-identity contract so the single paid row matches. **Override `consumer_number` at the
        confirmed-capture CALL SITE (`captureConfirmedReconcilePersistence()`, `:70`), NOT by editing the shared
        `voucher()` default at `:304-305`** — that default is also consumed by `captureSingleReconcilePersistence()`
        (`:44`, bare `$this->voucher()`), so mutating it would perturb the single/pending capture too. The proven
        one-line change (empirically validated in round-2 — flips the suite green and reverts clean):
        ```php
        // in captureConfirmedReconcilePersistence(), :70
        $voucher = $this->voucher(['consumer_number' => 'REG-0000001']);
        ```
        Then the confirmed branch reaches `confirmed_unposted`, the `:87` guard is non-vacuous again, and **every
        `persistedForbiddenPatterns()`/`fixtureForbiddenPatterns()` regex stays unweakened.** Do **not** give the
        fixture "a valid paid date" (it already has one) and do **not** edit the production parser/validator — neither
        is the cause. (Open Question #3 is now resolved — see below: no payment-truth code is touched, so its
        escalation hedge no longer applies.) **Landing the fix is positive evidence the guard is non-vacuous:** the leak
        file's assertion count jumps ~**154 → 304** (full plugin suite ~**890 → 1040**) because the confirmed branch now
        persists `KP-REF-PAID`/`raw_status` and those sinks get scanned — note that in the Dev Agent Record.
  - [x] If you add a new test class file, add its `require_once` to `tests/bootstrap.php`.

- [x] **Task 10 — Verification + honesty (AC8)**
  - [x] `php -l` every changed PHP file (gateway lib + gateway `kuickpay.php` + plugin services/presenter/tests +
        the two admin controllers `admin_vouchers.php` / `admin_main.php` if touched for logger wiring).
  - [x] **Per-site wiring audit (AC1) — confirm in the report that ALL four SOAP-running sites supply the logger:**
        gateway `InsertVoucher` issuance, cron `reconcile_pending` (`kuickpay_reconcile_plugin.php:197`),
        `AdminVouchers::recheck()` (`:241`), `AdminMain::run()` (`:62`). Where the harness supports it, add a narrow
        unit/reflective or controller-seam test asserting the service receives a `logger` dependency at each site; at
        minimum, code-review-verify each construction site and state which were test-covered vs review-only.
  - [x] Run the component suite per project-context:
        `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
        and, for the gateway-side SOAP-client/redactor changes,
        `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
        (never `-c build/phpunit.xml` — project-context:74). Confirm: presenter sync guard green at **19**; the
        **plugin** secret-leakage suite **fully green** (the previously-documented `KuickPaySecretLeakageTest:87`
        baseline now resolved by the Task 9 identity fix — no remaining "documented baseline failure" disclosure for
        **that** suite); and **no NEW failures beyond the disclosed pre-existing gateway baseline below**.
  - [x] **⚠️ Pre-existing GATEWAY-suite baseline — disclose, do NOT attribute to 4.5, do NOT fix here.** At HEAD
        `bc986e92` (with no 4.5 code changes) the **gateway** suite already has **one unrelated red**:
        `KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence` on the
        `ambiguous/bill-payment-inquiry-empty-currency.xml` data set (the empty-currency inquiry returns
        `confirmed_unposted` instead of a fail-closed status — a parser/contract disagreement, **not** a logging or
        leakage issue). It is **deterministic** and reproduces in isolation (15 tests, 1 failure). Because 4.5 edits
        gateway code (`KuickPaySoapClient.php`, `KuickPayRedactor.php`, `kuickpay.php`), the dev **will** see this when
        running the gateway tree — **do not blame the logging changes and do not fix it under this story.** Verify only
        that 4.5 introduces **no new** gateway failures on top of this one; record the baseline (test name + data set +
        starting `tests/assertions/failures` counts) in the Dev Agent Record exactly as prior stories disclosed the
        leak-suite baseline. (Triage of the empty-currency fail-closed gap is a separate parser/contract story.)
  - [x] State the exact commands, the PHP runtime actually used, and what could not be exercised (live
        `$this->log()` write, the verified Blesta `Logger` sink writes on the cron **and** admin Check Now / bulk paths
        — wiring is `php -l` + review only since no live Monolog/DB sink runs here — the `.pdt` render, and any live
        admin/DB path). PHP 8.2-compatible syntax only.

## Dev Notes

### What already exists (read before writing — exact anchors)

- **Audit write service** `lib/KuickPayAuditService.php::record(string $eventName, array $context): void`
  (`:30-44`). Context keys: `company_id` (required int), `voucher_id`, `run_id`, `redacted_trace_id`,
  `evidence_hash`, `payload` (array → `json_encode`d; empty → NULL). Idempotent insert; no return. Wrap every
  call in best-effort `try/catch (Throwable)` — the existing services all do (e.g. posting `:382-394`).
- **Audit repository / model** `lib/KuickPayAuditRepository.php` → `models/kuickpay_audit_events.php`
  (`add()` `:21-28`, allowlisted `FIELDS` `:10-19`; `getByVoucher()` `:44-54` is the **admin-only**, company-scoped,
  display-column-only read the 4.2 detail timeline renders). The model already drops `company_id/run_id/id` from
  the view read and renders `payload` as one escaped blob — **do not** change this read contract.
- **Audit table** `kuickpay_audit_events` (`kuickpay_reconcile_plugin.php:419-433` — shifted +37 by Story 4.4): `id`, `company_id`,
  `voucher_id?`, `run_id?`, `event_name` varchar(64), `redacted_trace_id` varchar(32)?, `evidence_hash`
  varchar(24)?, `payload` text?, `date_created` datetime. Indexed on company / voucher / event_name.
  **No schema change is needed for this story** — both new events fit the existing columns. (If you somehow think
  you need a column, stop — you do not.)
- **The 17 currently-emitted, registered events** (the closed allowlist the 4.2 timeline renders through):
  `voucher.issued`, `voucher.replaced`, `voucher.expired`, `evidence.received`, `evidence.matched`,
  `evidence.retry_decision`, `evidence.rejected`, `evidence.duplicate`, `evidence.unmatched`,
  `reconciliation.run.started`, `reconciliation.run.completed`, `posting.started`, `posting.succeeded`,
  `posting.failed`, `admin.rechecked`, `admin.reviewed`, `admin.cancelled`. Emission sites (for reference):
  issuance `lib/KuickPayIssuanceService.php`; reconcile/evidence/run/expiry `lib/KuickPayReconcileService.php`;
  replace `lib/KuickPayVoucherReferenceService.php`; posting `lib/KuickPayPostingService.php`; admin actions
  `controllers/admin_vouchers.php`. **This story adds exactly two more → 19.**
- **The 4-site drift guard (WILL FAIL the build if you miss a site):** ① presenter
  `lib/KuickPayVoucherListPresenter.php::EVENT_LABEL_KEYS` (`:145-163`) + `DEFAULT_EVENT_LABEL_KEY` (`:168`); ②
  `language/en_us/admin_vouchers.php` `AdminVouchers.event.*` keys; ③
  `tests/KuickPayVoucherListPresenterTest.php::KNOWN_EVENTS` (`:334`); ④ the hard-coded **count** in the comment
  (`:333`) and the `assertSame(..., 'Event map drifted from the 17 emitted event names')` message (`:505`). The
  guard asserts that `array_keys(EVENT_LABEL_KEYS)` and `KNOWN_EVENTS` hold the **same set** (both `sort()`ed before
  the `assertSame`, so order is free) and that every event has a matching language key (sync guard
  `testLanguageFileDefinesEveryAllowlistedLabelKey()`, now `:801-841` — was `:674-707` before 4.4 — which `include`s
  the language file). Update **all four** sites to 19 atomically. (4.4 also added `RUN_TRIGGER_LABEL_KEYS` /
  `RUN_STATUS_LABEL_KEYS` sibling maps with their own drift tests to this presenter — leave them untouched.)
- **Redaction boundary** `components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php`: `redactArray()`
  (`:66`), `redactEnvelope()` (`:95-145`, blanks every `*Result`, rejects DOCTYPE, bounds 1 MiB, returns
  `[UNPARSEABLE_ENVELOPE]` on unsafe XML), `sensitiveValues()` (`:81`), `traceId()` (`:152-158`, `kp_`+16 hex).
  **All log content must originate from already-redacted fields produced here** — this is "the single redaction
  boundary" of architecture line 373.
- **SOAP transport outcome** `lib/KuickPaySoapClient.php::call()` (`:134-224`) + the `outcome()` **builder method**
  (`:521-542`; the `:116-128` block is only the docblock). Constructor is `__construct(array $config,
  callable $soapClientFactory = null)` (`:46`) — **no `$logger`, and no `duration_ms`/`microtime` today**. Outcome
  fields: `ok`, `operation`, `raw_result` (**unredacted, parser-only — NEVER log**), `raw_envelope` (redacted, but
  **still carries structural `<…Envelope|Header|Body…>` tags** — also NEVER log), `error_class`, `fault` (redacted),
  `redacted_request` (redacted), `redacted_trace_id`, `attempts`. The redacted request + operation + trace id +
  error_class + `fault` token are **already here**; you are adding `duration_ms` and an injected log seam, not new
  redaction. Build `response_summary` from `fault`/`response_present` — **not** the envelope.
- **Gateway operational logs today (TWO groups, not one)** `kuickpay.php`: ① issuance-exception `:1233-1244` and
  issuance-outcome `recordIssuanceDiagnostic()` `:1284-1302` (group `kuickpay:voucher_issue`); ② a
  reference-generation failure diagnostic at `:1360-1361` (group `kuickpay:reference_generation`) inside
  `recordReferenceGenerationFailure()` (`:1349-1370`). **All three `$this->log()` calls** are gated by
  `logging_enabled` and use the verified `$this->log($group, json_encode($payload), 'output', $success)` API.
  `logging_enabled` field defined in `editSettings()` `:160`, with a SOAP-config default `'false'` read in
  `getSoapClient()` `:598`, and a runtime gate default `'true'`. See Task 3 for how `kuickpay:reference_generation`
  is scoped relative to AC1.
- **Posting correlation-id gap** `lib/KuickPayPostingService.php::recordAudit()` `:382-395` — hard-codes
  `'redacted_trace_id' => ''`. The voucher's `diagnostic_summary` JSON carries the real `redacted_trace_id`
  (fixture proof: `KuickPaySecretLeakageTest.php:132-137`). Decode-and-read it (guarded).
- **Secret-leakage suite** `tests/KuickPaySecretLeakageTest.php` — scans **persisted** sinks (voucher edits, item
  rows, run summaries, audit events) from single/confirmed/bulk reconcile, posting, and issuance against
  `persistedForbiddenPatterns()` (`:237-244`: real username/password/InstitutionID element values, CNIC, mobile,
  email, raw `Envelope|Header|Body`, raw `*Result`, bare `userName|password|InstitutionID` keys) and fixtures
  against `fixtureForbiddenPatterns()`. The fakes (`:341-611`) are the established pattern for driving the new
  emission paths.

### The audit-vs-log distinction (architecture lines 610–634 — internalize this)

> "Logs are operational diagnostics. Audit records are durable business history."

- **Audit records** (this story's AC3/AC4/AC5) are **durable rows** in `kuickpay_audit_events`, redacted-only,
  dot-notation, drift-guarded, and rendered admin-only in the 4.2 timeline. They are largely **done**; you are
  closing two named gaps + the posting correlation id + proving redaction.
- **Logs** (this story's AC1/AC2) are **operational diagnostics** written through Blesta's log seam (gateway
  `$this->log()`), sanitized, gated by `logging_enabled`. They are **incomplete**: the SOAP operations themselves
  are not logged with the FR27 field set. That is the main net-new build.
- Do **not** conflate them: do not write SOAP operations into `kuickpay_audit_events` (audit is business history,
  not transport diagnostics), and do not put durable business decisions only into logs. Keep each in its lane.

### Canonical operational-log shape (AC1/AC2 — the contract)

One shape, redacted inputs only:

```
operation        // 'InsertVoucher' | 'BillPaymentInquiry' | 'BillPaymentBulkInquiry'
redacted_trace_id// 'kp_…' correlation id (always present from the SOAP client)
voucher_id       // int|null where known by the caller
request_summary  // KuickPayRedactor::redactArray($params)  — NEVER raw params
response_summary // safe-token array {response_present: bool, result_present: bool, result_code: <?2-alnum>, fault: <?enum token>}
                 //   result_code = first 2 chars of raw_result ONLY if /^[A-Za-z0-9]{2}$/, else null (extracted UPSTREAM; builder never sees raw_result)
                 //   fault = log-safe ENUM (timeout|transport_error|provider_fault|provider_fault_with_response|invalid_wsdl|xml_fault_redacted|null), NOT redactedDiagnosticText() output
                 //   — NEVER raw_result / envelope (redacted or not: it keeps Envelope|Header|Body + *Result tags)
error_class      // null on success | timeout | transport_error | …
duration_ms      // int (added in Task 1) | null where unavailable
attempt          // int 1-based index of THIS transport attempt — threaded into call()/outcome() (Task 1); per-attempt
                 //   logging (OQ#5). Distinct from the outcome's existing `attempts` (plural, total retry count set in callWithRetry)
```

- The SOAP client `call()` already holds every value except `duration_ms` (add it) and `voucher_id` (the caller
  supplies it — the client is voucher-agnostic, so pass it via the logger closure or leave null and rely on the
  trace id as the correlation key; both satisfy AC1's "correlation id **and/or** Voucher id").
- **Forbidden in any log:** `raw_result`, the `raw_envelope`/response string **(redacted or not — `redactEnvelope()`
  blanks values but keeps the structural `Envelope|Header|Body` tags the leak scan forbids)**, exception
  `getMessage()`/trace, credential values, `InstitutionID`, customer PII. The Task 1 builder is the choke point that
  enforces this; route every log write through it.
- **Gating lives at the caller** (pass a logger only when `logging_enabled==='true'`) so the lib stays
  framework/meta-agnostic and a disabled gateway is a true no-op.

### Resolving the secret-leakage baseline (AC7 — VERIFIED root cause + the fix)

The suite's `testPersistedEvidenceAndAuditPayloadsContainNoSecretsOrRawEnvelopes` has been carried as a
"documented pre-existing baseline failure" since Story 4.1. **It is not a leak.** The failing line is the
**vacuous-guard** assertion at `:87`: `assertSame('confirmed_unposted', $repo->edits[0]['status'])`, which goes
red because the confirmed capture (`:68-97`) now persists `manual_review`.

**Root cause (verified empirically by running the suite + statically against live code — NOT the paid-date theory the
prior draft of this note carried):** the symptom is a **single-inquiry single-identity-contract violation**, not a
null paid date.

- The fixture `valid/bill-payment-inquiry-paid-exact.xml` carries the row
  `00,REG-0000001,20260609,1000.00,KP-TXN-0001,KP-REF-PAID,PKR,INSTITUTION_ID`. Field[2] = `20260609` is a **valid,
  present** `Transaction_Date` that `normalizeDate()` parses to `2026-06-09` — **there is no missing/null paid date
  here**, and the single-inquiry path has **no** `missing_paid_date` guard at all (that guard lives only in the bulk
  path). The `deferred-work.md:12` null-paid-date item is real but **unrelated** — its symptom never fires for this
  fixture.
- The confirmed-capture voucher (`voucher()` helper, test `:304-305`) carries **both** identities:
  `registration_number = 'REG-0000001'` **and** `consumer_number = 'INSTITUTION_IDREG-0000001'`.
- A single inquiry echoes **one** identity — field[1] = `REG-0000001`. `KuickPayResponseParser` single-inquiry
  validation (`KuickPayResponseParser.php:534-539`) loops over **both** `expected_registration_number` and
  `expected_consumer_number`, comparing each to that single echoed `$registrationNumber`. The consumer check
  `'INSTITUTION_IDREG-0000001' !== 'REG-0000001'` fails → `errors[] = unmatched_reference` → `STATUS_MANUAL_REVIEW`.

This is the documented **single-inquiry single-identity contract** (a single inquiry validates ONE field; passing
both expected identities forces a paid row to `manual_review`). It is a **test-fixture identity artifact**, not a
payment-truth defect.

**The fix (test layer only; no production / parser / paid-date change) — empirically proven in round-2:** align the
confirmed-capture voucher to the single matching identity the inquiry echoes by **overriding `consumer_number` at the
confirmed-capture CALL SITE** (`captureConfirmedReconcilePersistence()`, `:70`):
```php
$voucher = $this->voucher(['consumer_number' => 'REG-0000001']);   // was: $this->voucher();
```
**Do NOT edit the shared `voucher()` default at `:304-305`** — it is also used by `captureSingleReconcilePersistence()`
(`:44`), so mutating the default would perturb the single/pending capture. The confirmed branch then reaches
`confirmed_unposted`, and the `:87` guard becomes non-vacuous again. **Precise effect on the scan:** in the red state
the test aborts at the `:87` `assertSame` (inside `captureConfirmedReconcilePersistence()`), so the persisted
forbidden-pattern loop over the later captures **never runs** — it isn't that "the scan passes," it's that the failure
is the status guard, not a leak. Once fixed, the full persisted scan executes and passes (leak-file assertions jump
~**154 → 304**), proving the confirmed sink ran and carries no forbidden value — the exact sinks a smuggled secret
would hit.

**Constraints:** **Do not** weaken `fixtureForbiddenPatterns()`/`persistedForbiddenPatterns()`. **Do not** "give the
fixture a valid paid date" (it already has one — that change is a no-op and leaves the test red). **Do not** edit the
production parser/validator — it is behaving correctly; the test fixture was wrong. Because the fix touches no
payment-truth code, **Open Question #3's escalation hedge no longer applies**. The point of AC7 stands: a story
literally about "investigate safely without leaking secrets" must not ship with a perpetually-red leakage suite.

### Deferred-work items this story OWNS (close them; cite them in Dev Agent Record)

- **`deferred-work.md:75`** (from 2-2 review) — "Throwable-catch and benign null paths record no admin diagnostic
  … a durable **`voucher.generation_failed`** audit event in `kuickpay_audit_events` is owned by Epic 4 / Story
  4.5." → **Task 5.** Respect the stated boundary: only genuine generation failures (with a `lastError` reason),
  **not** the transient `not_ready` early-returns.
- **`deferred-work.md:83`** (from 3-3 review) — "Per-voucher processing exception records an item row but emits no
  audit event … A redacted **`evidence.error`** event is a small add best batched with the Story 4.5 audit-viewing
  surface." → **Task 6.**

Both are **explicitly pre-assigned to 4.5**. Closing them is in-scope and expected; mark them resolved in
`deferred-work.md` is **not** required by this story (leave the log intact unless the reviewer asks), but reference
them in the Dev Agent Record.

### Event-naming note: `voucher.created` vs `voucher.issued` (decision)

The epic AC and architecture list `voucher.created` as an **example** ("such as"). The shipped code emits
**`voucher.issued`** for successful issuance — semantically the same lifecycle event. **Default: keep
`voucher.issued`**; do **not** rename (a rename churns the drift guard, the language file, and historical rows for
zero behavior gain, and the AC says "such as", not a literal mandate). Whether Product wants a `voucher.created`
alias is **Open Question #4**.

### Technical requirements / guardrails

- **Ownership boundary (epics 118–124; architecture 663–802):** log **shaping/redaction** is gateway/protocol-layer
  (it lives with the SOAP client + redactor in the gateway `lib/`); **durable audit** is plugin-owned
  (`kuickpay_audit_events`, `KuickPayAuditService`). Do not move audit into the gateway, and do not put SOAP/redaction
  logic into the plugin. The injected-logger seam is exactly what keeps the SOAP client reusable across both.
- **Single redaction boundary (architecture 371–377):** every log/audit value originates from `KuickPayRedactor`
  output or a known-safe token (status, error_class, id, staff_id, count, reason code). Never hand-build a log/audit
  string from raw SOAP, request params, exception messages, or `config/blesta.php`.
- **Anti-pattern (architecture 656–657):** "Storing raw SOAP or credentials in logs." The `raw_result` and
  unredacted envelope in the SOAP outcome exist for the **parser only** — they must never reach a log or audit row.
- **No schema change / no float math / no new external dependency.** Both new events fit existing columns;
  amount/currency are untouched here. No new Composer package, no new view engine, no new framework (project-context).
- **PHP 8.2 target; legacy global classes** (no `declare(strict_types=1)`); match each file's local style; keep
  diffs small (project-context anti-churn). Use Blesta `Loader`/`Record`/`Language`/`Form`/`Logger` APIs and the
  existing services/redactor only.
- **Best-effort audit/log writes:** a failed audit or log write must **never** abort issuance, reconciliation, or
  posting — wrap in `try/catch (Throwable)` like the existing services.

### Library / framework requirements

- **No new libraries.** This story is internal logging/audit hardening over Blesta 6.0.0-b1 / PHP 8.2. The framework
  seams are both **verified in-repo**: the gateway `$this->log(...)` (in use at `kuickpay.php:1234,1290`) for the
  issuance path, and Blesta's `Logger` container service for the cron path — fetched via
  `$this->getFromContainer('logger')` (real call sites: `components/email/email.php:90`,
  `plugins/support_manager/support_manager_plugin.php:33`), registered at `core/ServiceProviders/Logger.php`
  (Open Question #2, resolved). The no-op fallback is only a defensive last resort. PHP
  `microtime(true)` for duration is fine in implementation code (the `Date.now`/`microtime` restriction applies only
  to BMAD workflow scripts, not to the PHP app).
- `.pdt` views are untouched on the customer side; the only admin display surface (4.2 timeline + diagnostics box)
  already exists and is ACL-gated — this story renders nothing new.

### Testing requirements

- No root `../tests`, no DB, no live admin/gateway-log stack in this checkout. Put unit-testable logic where it
  runs: the **log-shape builder** (pure → assert it drops `raw_result` and carries the field set), the
  **event-name invariant** over `KNOWN_EVENTS`, the **two new audit emissions** (via the established fake
  repo/audit pattern in `KuickPayReconcileServiceTest` / a `KuickPayVoucherReferenceService` test if one exists,
  else add narrow coverage), the **posting trace-id propagation** (fake audit service), and the **extended
  secret-leakage captures**. The live `$this->log()` write, the Blesta `Logger` sink, and the `.pdt` render are
  `php -l` + review only — disclose honestly (NFR12).
- Runner (project-context:73-74): `--bootstrap tests/bootstrap.php tests`; **never** `-c build/phpunit.xml`. Add any
  new test class to the relevant `tests/bootstrap.php`. The component suite has **two** trees — the plugin
  (`plugins/kuickpay_reconcile`) and the gateway (`components/gateways/nonmerchant/kuickpay`); run **both** since
  this story changes the SOAP client + redactor (gateway) and the services/presenter (plugin).
- **AC7 acceptance bar:** the secret-leakage suite must end **green** (the previously-documented baseline failure
  resolved). Do not carry forward a "documented baseline failure" disclosure for this suite — resolving it is an AC.

### Project Structure Notes

- **Aligned** — all changes land in existing files within the two owning trees:
  - Gateway: `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` (+`duration_ms`, +log seam),
    `lib/KuickPayRedactor.php` **or** a new `lib/KuickPayOperationLog.php` (log-shape builder),
    `kuickpay.php` (wire issuance + InsertVoucher log to the canonical shape), `tests/*` (builder + log-shape tests).
  - Plugin: `lib/KuickPayPostingService.php` (trace-id propagation), `lib/KuickPayReconcileService.php`
    (+`evidence.error`, +log seam wiring + `logger` dependency), `lib/KuickPayVoucherReferenceService.php`
    (+`voucher.generation_failed`), `lib/KuickPayVoucherListPresenter.php` (+2 events),
    `kuickpay_reconcile_plugin.php` (cron logger fetch+inject), `controllers/admin_vouchers.php` /
    `controllers/admin_main.php` (Check Now / bulk logger fetch+inject — the two non-cron SOAP entry points),
    `language/en_us/admin_vouchers.php` (+2 event keys), `tests/KuickPayVoucherListPresenterTest.php`
    (KNOWN_EVENTS→19 + invariant test), `tests/KuickPaySecretLeakageTest.php` (+captures, baseline identity fix),
    `tests/KuickPayPostingServiceTest.php` / `tests/KuickPayReconcileServiceTest.php` (+coverage),
    possibly `tests/bootstrap.php` (new test class require).
- **Possible variance to record:** if you add `lib/KuickPayOperationLog.php` (new file, not in the architecture's
  directory listing at `architecture.md:683-694`), note it as a documented, in-boundary addition (gateway protocol
  lib) — same kind of variance Stories 4.2/4.3 recorded for view/controller placement.
- **No schema change, no new table, no new controller/view, no customer-facing change.**

### References

> **Anchors re-verified against baseline `bfc9f0ff` (round-1 validation).** Corrected this pass: the `outcome()`
> builder is the method at `:521-542` (not the `:118-128` docblock); `call()` is `:134-224`; the per-voucher catch is
> `KuickPayReconcileService::processVoucher()` `:408-424`; `recordReferenceGenerationFailure()` is a **gateway** method
> (`kuickpay.php:1349`), **not** a plugin method — the plugin emits from inline `lastError` sinks; a second gateway log
> group (`kuickpay:reference_generation`, `:1360-1361`) exists; `response_summary` must be a token set, not the
> redacted envelope (which keeps `Envelope|Header|Body` tags); `duration_ms`/`$logger` are net-new.
>
> **Round-2 verification (baseline `bfc9f0ff`):** the logged `fault` needs a log-safe enum collapse —
> `redactedDiagnosticText()` (`:456-493`) leaves raw tags on unparseable/mixed XML, keeps `*Result` tags on success,
> and omits CNIC; `evidence.error` must hoist the inquiry trace id (`$evidence` is out of scope in the `processVoucher`
> `:408` catch; the stored `diagnostic_summary` is stale there); posting trace id decodes `diagnostic_summary` (written
> at `KuickPayReconcileService.php:547`) — there is **no** `redacted_trace_id` column on `kuickpay_vouchers`;
> `duplicate_invoice_id` (`:397`) has **no `company_id`** in `normalizeContextInvoiceAmounts()` — emit at the
> `getOrCreateForInvoiceContext()` call site (`:66-68`) instead; and a verified Blesta `Logger` container sink exists
> (`getFromContainer('logger')`) — the cron path uses it (OQ2 resolved). Operation→path: `InsertVoucher` gateway
> (`kuickpay.php:1181`), `BillPaymentInquiry`/`BillPaymentBulkInquiry` cron (`KuickPayReconcileService.php:390/:283`).

- `epics.md` Story 4.5 ACs (lines 820–841); FR3 (29), FR9 (41), FR15 (53), FR27 (77); NFR1 (87), NFR4 (93),
  NFR8 (101), NFR14 (113); additional requirements / audit names + redaction (124, 132–133, 141–143);
  UX-DR17 (184), UX-DR28 (~206).
- `architecture.md` **Audit and Logging Patterns** (610–634), Authentication & Security / single redaction boundary
  (371–377), Parser & Evidence Contract (549–567), Posting Contract incl. "write an audit event" (581–593),
  Enforcement Guidelines (636–646), Anti-Patterns "Storing raw SOAP or credentials in logs" (648–661),
  ownership/structure (663–802), FR24–27 → structure incl. audit service/repo/model (842–849).
- Code (gateway): `lib/KuickPaySoapClient.php:134-224` (`call()`) + `:521-542` (`outcome()` builder; `:116-128` is the
  docblock) + `:46` (constructor), `lib/KuickPayRedactor.php:66-158` (note `redactEnvelope` keeps structural envelope
  tags), `kuickpay.php:160,598,1234,1290,1349-1370` (logging_enabled + the **two** operational-log groups today:
  `kuickpay:voucher_issue` and `kuickpay:reference_generation`).
- Code (plugin): `lib/KuickPayAuditService.php:30-44`, `lib/KuickPayAuditRepository.php`,
  `models/kuickpay_audit_events.php:21-54` (add/getByVoucher) + `:74-85`/`:94-103` (4.4 `getByRun()`/`getCountByRun()`,
  `event_name IN ('evidence.unmatched','evidence.duplicate')`), `kuickpay_reconcile_plugin.php:419-433` (audit table; was `:382-396` pre-4.4),
  `lib/KuickPayPostingService.php:382-395` (trace-id gap; `''` at `:388`),
  `lib/KuickPayReconcileService.php::processVoucher()` `:408-424` (per-voucher catch / `evidence.error` site) +
  `gatewayConfigForCompany()` `:702-728` (cron `logging_enabled` resolution, default `'false'`),
  `lib/KuickPayVoucherReferenceService.php` (`voucher.generation_failed` site — the inline terminal `lastError`
  assignments at `:129/:301/:312/:397`, **not** the gateway's `recordReferenceGenerationFailure()`),
  `lib/KuickPayVoucherListPresenter.php:145-168` (EVENT_LABEL_KEYS + default).
- Tests: `tests/KuickPayVoucherListPresenterTest.php:333-334,478-505` (drift guard) + `:801-841` (language↔presenter
  sync guard `testLanguageFileDefinesEveryAllowlistedLabelKey()`; was `:674-707` pre-4.4),
  `tests/KuickPaySecretLeakageTest.php:25-40,68-97,128-157,237-244` (scan + baseline assertion; voucher identity
  `:304-305`), `KuickPayResponseParser.php:534-539` (single-identity validation → `unmatched_reference`).
- `deferred-work.md:12,75,83,94` (null-paid-date guard; the two 4.5-assigned audit gaps; the 3.5 paid-date item).
- `_bmad-output/project-context.md` (PHP 8.2; Blesta Loader/Record/Language/Logger; no root `../tests`; external
  PHPUnit 8.5 runner; no raw secrets in logs/docs; commit style).
- Previous story `4-3-run-safe-manual-voucher-actions.md` (the 4-site drift-guard mechanism; the leakage-suite
  baseline disclosure; verification-honesty pattern).

### Previous Story Intelligence (Epics 1–4.3 — apply these or repeat past review cycles)

- **Status/event/label as closed allowlists** (2-5, 4-1, 4-2, 4-3): a new audit event goes through **all four**
  drift-guard sites in one change; never concatenate a DB value into a language key; unknown tokens fall to the
  safe default. This is the single most repeated audit-surface rule.
- **Redaction is a single boundary** (1-3, 3-1, 3-2): the gateway credential mask and the SOAP `redactor` are the
  two layers; route everything through them. The 3-1 deferred item (`redactEnvelope` masks element **text** by
  local-name only, not attributes/aliases) is a **known, accepted** redaction-completeness residual under the
  confirmed element-based KuickPay contract — do not expand it here, but do not log anything that bypasses the
  redactor either.
- **Best-effort side writes never abort the primary action** (3-3, 3-5, 4-3): audit/log writes are wrapped in
  `try/catch (Throwable)`; a failed audit must not break reconciliation or posting.
- **`Record->fetch()/fetchAll()` return `stdClass`** — use `->field`, not `['field']` (cumulative footgun).
- **Verification honesty** (every retro, NFR12): disclose the PHP runtime actually used (host has 8.3.x/7.4.x, not
  8.2) and exactly which suites ran. **This story's twist:** the `KuickPaySecretLeakageTest` baseline failure that
  every prior story disclosed is **this story's to resolve** (AC7) — do not just re-disclose it.
- **No over-scoping** (4-3 Previous Story Intelligence): the Manual Review **queue** and reconciliation **run-summary
  view** are **Story 4.4 — now `done` & committed** (`AdminManualReview`, `AdminReconciliation`, run list/detail views,
  read-only ACL/nav, run-trigger/run-status presenter maps, and `KuickpayAuditEvents->getByRun()/getCountByRun()`).
  4.5 is logs + audit-event coverage + leakage verification only. Do **not** build or modify queue/run-summary UI here
  — it already exists; 4.5 only adds two audit events and operational logs.

### Git Intelligence Summary

Epics 0–3 and Stories 4.1–4.4 are `done`. Story 4.3 (manual voucher actions) **added** the
`admin.rechecked/reviewed/cancelled` events through the exact 4-site drift guard this story extends again, and left
the `KuickPaySecretLeakageTest` baseline failure documented (4.3 Completion Notes / Review Findings). **Story 4.4 has
since landed (HEAD `bc986e92`, e.g. `bc986e92 docs(kuickpay): record 4.4 review fixes`)** and shipped
`controllers/admin_reconciliation.php`, `controllers/admin_manual_review.php`, run list/detail views including
`views/default/admin_reconciliation_detail.pdt`, read-only ACL/nav entries, the `RUN_TRIGGER_LABEL_KEYS` /
`RUN_STATUS_LABEL_KEYS` presenter maps, and `KuickpayAuditEvents->getByRun()/getCountByRun()` (audit-only,
run-scoped, `event_name IN ('evidence.unmatched','evidence.duplicate')`). **4.4 did NOT add any audit events or
change the 17-event drift guard** — 4.5 still owns adding `voucher.generation_failed` + `evidence.error` (→ 19).
**Consequence for the dev:** 4.4's edits shifted several line anchors 4.5 navigates by (audit-table schema
`:382-396`→`:419-433`, item/audit trace cols `:363/:388`→`:400/:425`, cron instantiations `:188/:196`→`:197/:205`,
presenter sync-guard test `:674-707`→`:801-841`); these have been re-derived throughout this story (all other ~60
anchors verified still exact at HEAD). The audit service/repo/model/table, the redactor, the SOAP outcome contract,
and the presenter drift guard are stable and were built to be extended exactly the way this story does. 4.5 relies on
the already-shipped **4.2 detail timeline** (`admin_vouchers_detail.pdt`) **and** is aware of the **4.4 run-detail
drill-down** as a second admin-only surface (Task 8) — but renders nothing new on either.

### Project Context Reference

Follow `_bmad-output/project-context.md` verbatim — especially: PHP 8.2 only; load Blesta deps via `Loader`
(`loadModels`/`loadComponents`/`load`); use `Record`/`Language`/`Form`/`Logger` APIs (no ad-hoc SQL beyond
allowlisted `Record`); keep gateway code in the gateway tree and plugin code in the plugin tree; language-file-driven
strings; **do not expose secrets** — keep `config/blesta.php`, credentials, and payment metadata out of logs,
audit payloads, docs, and fixtures; do not edit ionCube/minified/vendored files; verify with `php -l` + the external
PHPUnit 8.5 runner (never claim root `../tests`); commit style `<type>(<scope>): <summary>` (`feat`/`fix`/`test`/
`docs`/`refactor`/`chore`).

## Open Questions for Reviewer

These are deliberate decisions recorded for sign-off. Each has a chosen default the dev should implement unless the
reviewer overrides it here. None block dev-start.

1. **Build order — RESOLVED.** This question previously flagged that 4.5 might run before 4.4 was built. **Story 4.4
   is now `done` & committed** (`sprint-status.yaml:118`), built in sprint order. 4.5 has **no hard dependency** on
   4.4 and that still holds — audit events are emitted by services regardless of UI, and 4.5 reads no 4.4 data. **No
   action; proceed with 4.5.** 4.4's surfaces (manual-review queue, run list/detail) already render the existing event
   set; the two events 4.5 adds are intentionally outside 4.4's `getByRun()` allowlist (Task 8), so 4.5 changes
   nothing on 4.4's screens.
2. **Plugin reconcile-path operational-log sink — RESOLVED.** AC1 wants SOAP operations logged with the full field
   set. The gateway checkout path has a **verified** sink (`$this->log(...)`); the reconcile-service paths now also
   have one. A verified Blesta `Logger` container service (`config/services.php` / `core/ServiceProviders/Logger.php`,
   Monolog-backed) is fetched via `$this->getFromContainer('logger')` at real in-repo call sites
   (`components/email/email.php:90`, `plugins/support_manager/support_manager_plugin.php:33`). **Decision:** every
   `KuickPayReconcileService` SOAP-running construction site **uses this `Logger`** — fetch it via
   `$this->getFromContainer('logger')` and inject it through the service's `dependencies` array (Task 2): in
   `kuickpay_reconcile_plugin.php::cron()` (method `:188`, instantiation **`:197`** for `runCron`), in
   `AdminVouchers::recheck()` (`:241`), and in `AdminMain::run()` (`:62`). The no-op fallback remains only as a
   defensive last resort (container yields no logger), disclosed in the Dev Agent Record. Override if the team has a
   preferred logging sink.
3. **Secret-leakage baseline resolution — RESOLVED; no payment-truth code touched.** The earlier draft of this
   question worried the AC7 baseline fix might require a parser/validator change. **It does not.** The verified root
   cause of the `confirmed_unposted` assertion failure (`KuickPaySecretLeakageTest.php:87`) is a **test-fixture
   single-identity-contract violation** — the confirmed-capture voucher carries a `consumer_number` that mismatches
   the single registration field the inquiry echoes, so `KuickPayResponseParser:534-539` records `unmatched_reference`
   → `manual_review` (the paid date is present and valid; there is no single-inquiry null-paid-date guard). **Fix is
   test-layer only:** align the capture voucher's `consumer_number` to the echoed `'REG-0000001'` (Task 9 / Dev Notes →
   "Resolving the secret-leakage baseline"). The parser is behaving correctly and **must not** be edited. The
   escalation hedge no longer applies.
4. **`voucher.created` alias.** The epic/architecture name the example `voucher.created`; the code emits
   `voucher.issued`. **Default: keep `voucher.issued`, add no alias** (the AC says "such as"; a rename/alias churns
   the drift guard + historical rows for no behavior gain). Flag if Product wants the literal `voucher.created` name.
5. **Retry logging granularity (AC1).** Logging fires at the `outcome()` choke point inside `call()`, and
   `callWithRetry()` (`KuickPaySoapClient.php:252-267`) invokes `call()` up to **three** times for inquiry/bulk-inquiry
   operations. **Default: one operational log line per transport attempt** (carry an `attempt` index in the canonical
   shape) — this preserves the choke-point design and gives operators visibility into each retry. **Implementation
   note:** the attempt index is not visible at the choke point as the code stands (`callWithRetry()` stamps `attempts`
   only after `call()` returns, `:259`), so Task 1 spells out the required small seam change — thread `int $attempt = 1`
   through `call()`→`outcome()`. The alternative (one line per public operation after retry resolution) would require
   logging outside the choke point, in `callWithRetry()`, which the design deliberately avoids. Flag if the team wants
   single-line-per-operation instead.

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- 2026-06-12 22:07:27 PKT — PHP runtime used for syntax/tests: `PHP 8.3.31 (cli)`.
- `php -l` passed for every changed PHP file in the File List.
- `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` — passed: 158 tests, 1127 assertions.
- `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` — ran 233 tests, 1256 assertions, with the documented pre-existing gateway baseline failure only: `KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence` for data set `ambiguous/bill-payment-inquiry-empty-currency.xml`.
- Focused red/green checks run during implementation: gateway redactor/SOAP tests, gateway issuance helper test, reconcile logger test, posting trace test, reference-generation audit test, reconcile evidence-error test, presenter drift guard, and `KuickPaySecretLeakageTest` (green: 2 tests, 345 assertions).

### Completion Notes List

- Implemented canonical SOAP operational log fields via `KuickPayRedactor::operationLogFields()` and `KuickPaySoapClient` logger injection, with per-attempt `duration_ms` and `attempt`.
- Wired operational logging for all four SOAP-running paths: gateway issuance, cron reconcile, admin Check Now, and admin bulk run. Missing container logger falls back to no SOAP operational log.
- Normalized gateway voucher-issue diagnostics to the canonical shape and intentionally left pre-SOAP `kuickpay:reference_generation` scoped out.
- Propagated posting audit `redacted_trace_id` from voucher `diagnostic_summary`.
- Added durable `voucher.generation_failed` and `evidence.error` audit events with safe payloads only.
- Updated the four-site audit drift guard to 19 events and added the lower-dot-notation invariant.
- Verified customer surface safety: `process.pdt` renders no log/audit diagnostics; admin voucher diagnostics remain permission-gated; run-detail audit exceptions still allowlist only `evidence.unmatched` and `evidence.duplicate`.
- Extended the secret-leakage suite to cover the new audit paths and operational-log payloads; resolved the prior confirmed-capture baseline by aligning the test voucher `consumer_number` to the single-inquiry identity contract.
- Live Blesta `$this->log()` writes, Monolog/container sink writes, live admin/DB paths, and `.pdt` rendering were not exercised in a live app; they were covered by unit seams, syntax checks, and code review.

### File List

- components/gateways/nonmerchant/kuickpay/kuickpay.php
- components/gateways/nonmerchant/kuickpay/lib/KuickPayRedactor.php
- components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayRedactorTest.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPaySoapClientTest.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php
- plugins/kuickpay_reconcile/controllers/admin_main.php
- plugins/kuickpay_reconcile/controllers/admin_vouchers.php
- plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
- plugins/kuickpay_reconcile/language/en_us/admin_vouchers.php
- plugins/kuickpay_reconcile/lib/KuickPayPostingService.php
- plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherListPresenter.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
- plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php
- plugins/kuickpay_reconcile/tests/KuickPayReconcileServiceTest.php
- plugins/kuickpay_reconcile/tests/KuickPaySecretLeakageTest.php
- plugins/kuickpay_reconcile/tests/KuickPayVoucherListPresenterTest.php
- plugins/kuickpay_reconcile/tests/KuickPayVoucherReferenceServiceTest.php
- plugins/kuickpay_reconcile/tests/bootstrap.php

### Change Log

- 2026-06-12 — Implemented Story 4.5 structured logs, audit-event coverage, leakage hardening, and verification updates.
