---
baseline_commit: a065c59f
---

# Story 2.2: Generate Registration Number and Consumer Number

Status: done

## Story

As a customer paying through KuickPay,
I want one stable Consumer Number for my invoice,
so that I can pay through my bank, wallet, branch, ATM, agent, or payment app without guessing.

> **What this story IS:** the real reference-generation engine for KuickPay Vouchers. It replaces Story 2.1's fixed `'0000'` placeholder prefix with (a) the confirmed live formula — a **random** 4-digit prefix plus invoice id for the Registration Number, Institution ID plus Registration Number for the Consumer Number — driven by (b) the **configurable** `registration_number_pattern` / `consumer_number_pattern` settings that Story 1.2 already stores and shape-validates but never consumes, with (c) generation-time validation that refuses to issue a Voucher on an empty/unknown-token/malformed/too-long reference and records an admin-safe diagnostic, and (d) company-scoped reference-value uniqueness enforced fail-closed via the schema unique keys plus bounded regenerate-on-collision.
>
> **What this story is NOT:** no SOAP call to KuickPay (Story 2.3 / 3.1), no parser consumption (Story 3.2), no amount-change/active-payment-context gating or multi-invoice handling (Story 2.4), no styled customer reference panel or copy action (Story 2.5), no instruction groups or status expectations (Story 2.6), no reconciliation/posting (Epic 3), no durable `kuickpay_audit_events` audit table or admin workbench (Epic 4). **Zero live payment mutation. No Blesta transaction creation. Vouchers still only ever reach `pending`.**

## Acceptance Criteria

_Reproduced verbatim from [Source: epics.md#Story 2.2, lines 439–461]._

**AC1 — Default reference shape.**
**Given** an eligible Voucher is created
**When** reference generation runs
**Then** the default Registration Number uses random prefix plus invoice ID
**And** the default Consumer Number uses Institution ID plus Registration Number.

**AC2 — Invalid patterns fail closed with a recorded diagnostic.**
**Given** reference patterns are configured
**When** invalid pattern values would produce empty, duplicate, or malformed references
**Then** the Voucher is not issued
**And** an admin-safe validation or diagnostic error is recorded.

**AC3 — Company-scoped uniqueness enforced, duplicates fail closed.**
**Given** company-scoped references exist
**When** a new Voucher is created
**Then** Registration Number and Consumer Number uniqueness are enforced
**And** duplicates fail closed rather than producing a second active reference.

## Non-Negotiables (read before any task)

1. **No live payment mutation, no Blesta transaction creation, no SOAP.** [Source: architecture.md Anti-Patterns lines 648–662; 2.1 NN#1] This story only changes how the local `pending` Voucher's two reference strings are generated. No `Transactions->add`, `recordPayment`, `markPaid`, invoice status change, `getSoapClient()`, `insertVoucher()`, or parser instantiation. The Voucher status stays `pending`.

2. **Gateway calls the plugin reference service; the plugin owns generation and persistence.** [Source: architecture.md lines 518–526, 781–789; 2.1 NN#2] Reference generation lives in `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` (the `KuickPayVoucherReferenceService` per architecture line 782 owns "create/reuse/idempotency decisions for Voucher references"). The gateway passes scalar context (now including the two pattern strings) and renders the result. The gateway must NOT generate references itself, build SQL, or instantiate plugin models.

3. **Use a cryptographically-secure random source for the random prefix — never `rand()`/`mt_rand()`/`uniqid()`/time.** [Source: project-context.md security rules; phase-0-contract.md line 33] Use `random_int(0, 9999)` zero-padded to 4 digits. `random_int` is PHP 7.0+ and PHP 8.2-safe. Make the random source an overridable seam (a `protected` method) so tests are deterministic (mirror the 1.4 `executeConnectionProbe()`/`resolveProbeAddresses()` seam pattern).

4. **Generation-time validation must fail closed.** [Source: epics.md AC2; architecture.md Decided Invariants (fail-closed); NFR9] An empty result, an unresolved/unknown `{token}`, a result longer than the `varchar(64)` column, or a recognized token that resolves to an empty value → **do not issue the Voucher**. Return `null` and surface a sanitized failure reason. Never persist a malformed reference, never truncate-to-fit silently, never fabricate a fallback reference value.

5. **The recorded diagnostic must be admin-safe — no secrets, no customer leak.** [Source: architecture.md lines 437, 608; UX-DR28; NFR8] AC2's "admin-safe validation or diagnostic error is recorded" is recorded through the gateway's own `log()` facility (writes to `log_gateways`, admin-only), gated by the `logging_enabled` setting, with structured non-secret data only (event, reason code, invoice id). The **customer** continues to see only the existing neutral `Kuickpay.process.not_ready` copy — never the pattern, token, reason, or any raw value.

6. **Company-scoped reference-value uniqueness stays schema-enforced and fail-closed.** [Source: architecture.md lines 351, 537–538; 2.1 schema] The 2.1 `(company_id, registration_number)` and `(company_id, consumer_number)` unique keys remain the atomic backstop. With a **random** prefix the deterministic collision that 2.1 relied on disappears, so 2.2 adds a bounded regenerate-on-collision loop (pre-check via repository + the unique keys as the atomic guard); on exhaustion it fails closed (returns `null`), never a duplicate or a second active reference.

7. **Reuse-before-create stays the primary duplicate-active-Voucher guard — do not regress AC2 of Story 2.1.** [Source: 2.1 Dev Notes "AC2 idempotency strategy"] `getOrCreateForInvoiceContext()` must still check `getPendingByInvoiceId()` first and reuse an existing `pending` Voucher **without** regenerating. Regeneration applies only on the create path, only to a brand-new Voucher, and only when a reference *value* collides.

8. **Preserve Blesta extension boundaries: no core edits, PHP 8.2 only, language-file strings.** [Source: project-context.md] Touch only `components/gateways/nonmerchant/kuickpay/*` and `plugins/kuickpay_reconcile/*`. No PHP 8.3+ syntax/APIs. All admin/customer strings stay in language files. Match each target file's existing style (legacy global classes, no `declare(strict_types=1)`, existing docblock style).

9. **Institution ID and random prefix are STRINGS — preserve leading zeros.** [Source: phase-0-contract.md line 32 (5-digit Institution ID)] The Institution ID is a zero-padded 5-digit text identifier with a leading zero (illustrative: `01999`). It must be substituted verbatim as a string — **never cast to `int`** (which would turn `01999` into `1999` and corrupt every reference). The 4-digit random prefix must likewise be **left-zero-padded to exactly 4 chars** (e.g. `0042`, not `42`) so widths stay stable. Treat `{invoice_id}` as a string too. No `(int)` cast on any reference component during substitution. The **real** production Institution ID is admin-configured in gateway settings and must not be written into this story, tests, fixtures, logs, or commits [Source: phase-0-contract.md lines 47–50; NFR10].

## Tasks / Subtasks

- [x] **Task 1 — Define the canonical token grammar and pattern expander** (AC: #1, #2)
  - [x] 1.1 In `KuickPayVoucherReferenceService`, add a pure **`protected`** method `expandPattern(string $pattern, array $values): ?string`. It substitutes `{token}` placeholders from `$values` and returns the expanded string, or `null` when the result is malformed (see 1.4). It must NOT touch the database, randomness, or `$this->repository`. Keep it `protected` (not `private`) so a test subclass can invoke it directly for the pure-helper unit tests in Task 6 — this is the same testability seam as `generateRandomPrefix()` (Task 2.1); a `private` method would force reflection or indirect-only testing and contradicts the "directly unit-testable" claim in Dev Notes → "Testability".
  - [x] 1.2 **Recognized tokens** (the only valid `{...}` placeholders):
    - `{random_prefix}` → the value of `$values['random_prefix']` (a 4-digit numeric string produced in Task 2).
    - `{invoice_id}` → `$values['invoice_id']` (the **Blesta internal invoice id** — the `id` of the first entry in `$invoice_amounts`, already supplied by `buildProcess()`; per PO decision this is NOT the customer-facing invoice number or any legacy WHMCS number, so no extra lookup is needed). Treat as a string.
    - `{institution_id}` → `$values['institution_id']` (the configured Institution ID).
    - `{registration_number}` → `$values['registration_number']` (the already-expanded Registration Number; only present when expanding the consumer pattern — see 1.5).
    Any other `{...}` sequence is an **unknown token** → malformed → return `null`. Every character outside `{...}` is a literal copied verbatim (the settings charset `/^[A-Za-z0-9_{}+\-]+$/` permits literal letters, digits, `_`, `+`, `-`).
  - [x] 1.3 **Default (canonical) patterns** used when a pattern is missing/empty in the context (defensive — settings require non-empty, but the service must be robust). Define them as class constants:
    - `DEFAULT_REGISTRATION_PATTERN = '{random_prefix}{invoice_id}'`
    - `DEFAULT_CONSUMER_PATTERN = '{institution_id}{registration_number}'`
    These produce AC1's shapes — Registration = `<4-digit random><invoice_id>`, Consumer = `<institution_id><registration_number>` (i.e. `institution_id + random_prefix + invoice_id`), matching the confirmed live formula. [Source: phase-0-contract.md lines 32–33, 123–124]
    **Worked example (illustrative — the real Institution ID is entered in gateway settings, never committed to this doc/tests/fixtures per phase-0-contract.md lines 47–50 and NFR10):** a 5-digit leading-zero Institution ID such as `01999` + 4-digit random `1111` + invoice id `666666` → Registration Number `1111666666`, Consumer Number `019991111666666` (= `institution_id` then `registration_number`, plain concatenation, no separator; 5+4+6 = 15 chars). Keeping this `institution_id + random_4 + invoice_id` order as the Blesta default preserves operational, KuickPay-side, and customer-facing continuity through the WHMCS→Blesta migration. The migration-continuity test (Task 6.2.1) must assert the value **built from these parts**, not a hand-typed literal (a literal can silently bake in a digit-count error).
  - [x] 1.4 **Malformed = return `null`** when, after substitution, the result is: empty string; OR still contains a `{` or `}` (unresolved/unknown token); OR longer than 64 characters; OR a *recognized* token resolved to an empty string (e.g. `{institution_id}` requested while `institution_id` is `''`, or `{invoice_id}` empty). Do not throw.
  - [x] 1.5 Note the **evaluation order**: the consumer pattern may reference `{registration_number}`, so Task 2 must expand the Registration Number first and pass it into the values for the consumer expansion. `{registration_number}` appearing in the *registration* pattern is a self-reference → not in that call's `$values` → unknown token → `null` (fail closed, correct).

- [x] **Task 2 — Replace `generateReferences()` with configurable, random, collision-safe generation** (AC: #1, #2, #3)
  - [x] 2.1 Add **two** `protected` methods so the random *source* is the seam (NN#3) while the padding stays in real, test-exercised code:
    ```php
    protected function randomInt(): int
    {
        return random_int(0, 9999);
    }

    protected function generateRandomPrefix(): string
    {
        return str_pad((string) $this->randomInt(), 4, '0', STR_PAD_LEFT);
    }
    ```
    **Tests override `randomInt()` (the secure-random source), NOT `generateRandomPrefix()`** — this is the key change from the round-1 draft. Overriding the formatted-string method would *bypass* `str_pad()` and make the leading-zero/zero-pad assertion (Task 6.2.1) untestable; injecting the raw integer keeps the production padding path live so a stubbed `42` flows through the real `str_pad` and must come out `'0042'`. Both stay `protected` (mirrors the 1.4 `executeConnectionProbe()`/`resolveProbeAddresses()` two-seam pattern); the test subclass exposes a public proxy to invoke `generateRandomPrefix()` directly (Task 6.1).
  - [x] 2.2 Rewrite `private function generateReferences(array $context): array`. **The return shape is always `['registration_number' => string, 'consumer_number' => string]`** — on any failure return `['registration_number' => '', 'consumer_number' => '']` (empty strings trip the existing caller-side `empty(...)` guard). The method is `array`-typed, so it never returns `null`; the failure *reason* travels via `$this->lastError`, and **`generateReferences()` itself owns setting `$this->lastError` at each failure branch.** This is the single source of the AC2 reason — the retry loop in Task 2.3 must NOT try to re-derive which pattern failed, because the flat empty-string return cannot encode reg-vs-consumer. Inputs from `$context`: `registration_number_pattern`, `consumer_number_pattern` (fall back to the Task 1.3 defaults when empty), `invoice_id` (first of `invoice_amounts`), `institution_id`. Steps:
    1. Compute `$invoice_id` (string). If empty → return empties and **leave `$this->lastError` null**. (A missing invoice context is a benign/transient `not_ready`, not an AC2 pattern-misconfiguration — this mirrors the `getOrCreateForInvoiceContext()` early-return guard, which already rejects a context with no invoice id before generation even runs.)
    2. Generate `$random = $this->generateRandomPrefix();`.
    3. `$registration = $this->expandPattern($regPattern, ['random_prefix'=>$random, 'invoice_id'=>$invoice_id, 'institution_id'=>$institution_id]);` → if `null` → set `$this->lastError = 'invalid_registration_pattern'` and return empties.
    4. `$consumer = $this->expandPattern($consPattern, ['random_prefix'=>$random, 'invoice_id'=>$invoice_id, 'institution_id'=>$institution_id, 'registration_number'=>$registration]);` → if `null` → set `$this->lastError = 'invalid_consumer_pattern'` and return empties.
    5. Return both reference strings.
  - [x] 2.3 **Collision handling (AC3) lives on the create path in `getOrCreateForInvoiceContext()`**, wrapping generation in a bounded retry. Declare `private const MAX_REFERENCE_ATTEMPTS = 5;` on the service. For up to `MAX_REFERENCE_ATTEMPTS` attempts: generate references (new `{random_prefix}` each attempt). **Distinguish two failure kinds:**
    - **Malformed pattern** (generation returns empty / `expandPattern()` → `null`): this is deterministic — retrying with a new random cannot fix it. **Break immediately and return `null`.** `$this->lastError` was already set by `generateReferences()` (Task 2.2) to `invalid_registration_pattern` / `invalid_consumer_pattern` — the loop must NOT overwrite or re-derive it (it cannot tell reg from consumer from the empty return). Do NOT consume the remaining attempts and do NOT write.
    - **Value collision**: references are well-formed, but a **pre-check** of company-scoped uniqueness finds an existing row for this `company_id`. The pre-check must call **both** `getByRegistrationNumber($registration, $company_id)` **and** `getByConsumerNumber($consumer, $company_id)` (Task 3); a hit on **either** counts as a collision → regenerate (next attempt). If well-formed-but-colliding on every attempt → set `$this->lastError = 'uniqueness_exhausted'`, return `null`.
    On the first attempt whose references are well-formed AND pass the pre-check, break the loop and proceed to `repository->create()`. **Reference control-flow (do not improvise):**
    ```php
    for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
        $refs = $this->generateReferences($context);
        if ($refs['registration_number'] === '' || $refs['consumer_number'] === '') {
            return null; // malformed: lastError already set by generateReferences(); break immediately, no write
        }
        $collision = $this->repository->getByRegistrationNumber($refs['registration_number'], $company_id)
                  || $this->repository->getByConsumerNumber($refs['consumer_number'], $company_id);
        if (!$collision) {
            break; // well-formed AND unique → proceed to create()
        }
        if ($attempt === self::MAX_REFERENCE_ATTEMPTS) {
            $this->lastError = 'uniqueness_exhausted';
            return null;
        }
    }
    // ... proceed to repository->create() with $refs ...
    ```
    **Wiring note:** the as-built create block reads `$references['registration_number']`/`['consumer_number']` into `$voucherData`. This loop names the winning values `$refs`, so feed `$refs` into `$voucherData` (or name the loop variable `$references` to match) — leaving the downstream `$voucherData` reading an undefined `$references` would warn-to-`null`, the model's non-empty rule would reject it, and `create()` would return `null` → silent `not_ready`. Mechanical, but make it explicit.
    The schema unique keys remain the atomic guard if a concurrent insert wins between the pre-check and the write — `create()` returns `null`, and the existing race-recovery re-lookup (reuse) handles the **same-invoice** concurrent case (NN#7), leaving `$lastError` null → benign `not_ready`. That benign path is correct, **not** an AC2 miss. A concurrent **distinct-reference** insert for the same invoice (true double-submit) is the accepted Story 2.4 residual — see "Decisions & deferrals" in Dev Notes.
  - [x] 2.4 Add a `private $lastError = null;` and `public function getLastError(): ?string` to the service. Set `$lastError` to a stable sanitized code (`invalid_registration_pattern`, `invalid_consumer_pattern`, `uniqueness_exhausted`, or `null` on success/benign-unavailable) so the gateway can record the AC2 diagnostic without leaking values. **Reset `$this->lastError = null` as the very first statement of `getOrCreateForInvoiceContext()` — before the `try` block AND before the `$firstInvoice` early-return guard (as-built service line ~42).** Otherwise a stale code from a prior call on the same service instance leaks and the gateway logs a false diagnostic for a benign no-invoice / reuse path. Every benign early-return path (invalid invoice, reuse hit, create-failure race-recovery) must leave `$this->lastError === null`. **Do NOT set `$lastError` in the `Throwable` catch** — an unexpected exception is a benign `not_ready`, not a categorized AC2 generation failure (a code there would leak nothing useful and could misdirect admin attention).
  - [x] 2.5 Keep the existing reuse-first path, `flatten()`, `offsetDate()`, the `create()`→`null` race-recovery re-lookup, and the `Throwable` catch unchanged except for setting `$lastError` where a generation/validation failure occurs. A `null` return with `$lastError === null` means "transient/unavailable" (benign `not_ready`); a `null` return with a non-null `$lastError` means "generation failed" (AC2 diagnostic).
  - [x] 2.6 Remove the obsolete `'0000'`-prefix comment block and the "Story 2.2 replaces this fixed `0000` prefix" note now that 2.2 implements it.

- [x] **Task 3 — Repository uniqueness pass-throughs** (AC: #3)
  - [x] 3.1 In `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`, add `getByRegistrationNumber(string $registration_number, int $company_id): ?stdClass` delegating to `$this->KuickpayVouchers->getByRegistrationNumber(...)` (the model method already exists — Task 2.x of Story 2.1), returning `$row ?: null`.
  - [x] 3.2 Add `getByConsumerNumber(string $consumer_number, int $company_id): ?stdClass` delegating to `$this->KuickpayVouchers->getByConsumerNumber(...)`, same `?: null` normalization. No model changes are needed — `KuickpayVouchers::getByConsumerNumber()` (`models/kuickpay_vouchers.php:113`) and `getByRegistrationNumber()` (`:129`) already exist.

- [x] **Task 4 — Gateway wiring: pass patterns + record the AC2 diagnostic** (AC: #1, #2)
  - [x] 4.1 In `components/gateways/nonmerchant/kuickpay/kuickpay.php` `buildProcess()`, add the two pattern strings to the context array passed to `getOrCreateForInvoiceContext()`:
    ```php
    'registration_number_pattern' => $meta['registration_number_pattern'] ?? '',
    'consumer_number_pattern' => $meta['consumer_number_pattern'] ?? '',
    ```
    (`$meta` is already resolved a few lines above as `is_array($this->meta) ? $this->meta : []`.) Do not change the existing currency/companion guard ordering or the `if (!$this->Input->errors())` gate (NN: voucher creation stays behind both guards).
  - [x] 4.2 After `$voucher = $service->getOrCreateForInvoiceContext(...)`, when `$voucher === null` AND `$service->getLastError() !== null` AND logging is enabled, record one admin-safe diagnostic via the inherited gateway logger:
    ```php
    if ($voucher === null
        && ($reason = $service->getLastError()) !== null
        && (($meta['logging_enabled'] ?? 'true') === 'true')
    ) {
        $this->log(
            'kuickpay:reference_generation',
            json_encode([
                'event' => 'reference_generation_failed',
                'reason' => $reason,
                'invoice' => (int) ($invoice_amounts[0]['id'] ?? 0),
            ]),
            'output',
            false
        );
    }
    ```
    The logged `data` carries only a sanitized event + reason code + the Blesta internal invoice id (per NN#5 — a non-secret id that aids admin triage; no pattern text, no token, no credentials, no customer data). **Default `logging_enabled` to `'true'` in this gate to match the settings UI default** (`settings.pdt:217` renders the checkbox checked-by-default via `(($meta['logging_enabled'] ?? 'true') === 'true')`). Note the precise reachability: `editSettings()` (`kuickpay.php:113–127`) normalizes the checkbox on every save — absent (unchecked) is stored as `'false'`, checked is stored as `'true'` — so after any save the meta key is **always present** with a concrete value and this `??` fallback does **not** fire. The fallback fires only for a legacy gateway configured **before** `logging_enabled` existed in the form (pre-1.2) and never re-saved; for that case `'true'` honors the UI's "on by default" intent and avoids silently dropping the AC2 diagnostic, whereas `?? 'false'` would suppress it. (The unrelated SOAP-client config builder at `kuickpay.php:508` uses `?? 'false'`; separate Epic-3 concern, intentionally unchanged.) `$this->log()` is the inherited `Gateway::log()` (`components/gateways/lib/gateway.php:254`); in the client pay flow `gateway_id` is set (via `setGatewayId()`) so the entry is keyed correctly. (Client-initiated flow leaves `staff_id` null — `Logs::addGateway` accepts that; this is expected, not a defect.) When `$voucher !== null` set the view var exactly as today.
  - [x] 4.3 Do NOT surface generation failures to the customer beyond the existing `not_ready` fallback (the view already renders `Kuickpay.process.not_ready` when `$voucher` is unset). Do not call `$this->Input->setErrors(...)` for a generation failure (that error renders to the customer).

- [x] **Task 5 — Document the token vocabulary in admin settings language** (AC: #1, #2)
  - [x] 5.1 In `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`, update the two existing pattern notes (currently "Template stored … in a later workflow.") to document the live token vocabulary and recommended defaults, e.g.:
    ```php
    $lang['Kuickpay.registration_number_pattern_note'] = 'Template for the KuickPay Registration Number. Tokens: {random_prefix} (4-digit random), {invoice_id}. Recommended: {random_prefix}{invoice_id}.';
    $lang['Kuickpay.consumer_number_pattern_note'] = 'Template for the KuickPay Consumer Number. Tokens: {institution_id}, {registration_number}, {random_prefix}, {invoice_id}. Recommended: {institution_id}{registration_number}.';
    ```
    Preserve all other keys, ordering, and single-quote style. Keep them space-free in any example (the settings charset excludes spaces). Note for the dev: the Story 1.2 illustrative `{random_prefix}+{invoice_id}` used `+` as informal concatenation notation; the canonical grammar treats `+` as a **literal**, so the recommended defaults are `+`-free. [Source: 1-2 Dev Notes lines 134–135, 157] Also mention in the admin note that braces are valid **only** as recognized `{token}` delimiters — any other or unbalanced brace (e.g. `a{b`) makes the pattern unusable and no Voucher will ever issue, even though the settings charset technically accepts the character (the generator fail-closes on any residual `{`/`}`). This prevents a "field saved but no voucher" support ticket.
  - [x] 5.2 No new customer-facing language key is required (the `Kuickpay.process.not_ready` fallback already exists). Do not add customer copy that names tokens, patterns, or reasons.

- [x] **Task 6 — Tests (pure generation + collision + wiring)** (AC: #1, #2, #3)
  - [x] 6.1 Update `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php`. **The existing `testCreateUsesDeterministicReferencesForInvoiceContext` now asserts the old `'000055'`/`'KP000055'` deterministic values and MUST be rewritten** — generation is random. Replace it with a seeded test using the `TestableKuickPayVoucherReferenceService` below (`randomQueue = [1234]`, so the real `str_pad` path yields prefix `'1234'`) that asserts the default pattern produces `registration_number === '123455'` and `consumer_number === 'KP123455'` for `invoice_id=55`, `institution_id='KP'`. Keep `testReuseReturnsExistingPendingVoucherWithoutCreating` (reuse reads a stored row — unaffected) and `testCreateFailureRerunsReuseLookupOnceForRaceRecovery`. **Add `getByRegistrationNumber`/`getByConsumerNumber` to the *shared* `KuickPayVoucherReferenceServiceFakeRepository` (default-return `null`)** — not just for the race-recovery test. The whole service body is wrapped in a `Throwable`-swallowing `try/catch`, so a missing pre-check method would surface as a `Call to undefined method` that is caught and returned as a confusing `null` voucher (every create-path test would fail opaquely), not a clear error. Make these two fakes parametrizable (a queued return per call, or a closure) so the collision tests in 6.3 can return a row on selected attempts and `null` afterwards. For deterministic randomness, **stub the integer source `randomInt()` (a queue), not `generateRandomPrefix()`**, and expose public proxies so the `protected` pure helpers are reachable from the `…Test extends TestCase` class (a `protected` method is **not** callable as `$service->expandPattern(...)` from a sibling TestCase — it throws `Error: Call to protected method`):
    ```php
    class TestableKuickPayVoucherReferenceService extends KuickPayVoucherReferenceService
    {
        /** @var int[] queued raw random values; falls back to 9999 when drained */
        public $randomQueue = [1234];

        protected function randomInt(): int
        {
            return (int) (array_shift($this->randomQueue) ?? 9999);
        }

        // Public proxies — protected helpers can't be called on $obj from a sibling TestCase.
        public function callGenerateRandomPrefix(): string { return $this->generateRandomPrefix(); }
        public function callExpandPattern(string $pattern, array $values): ?string { return $this->expandPattern($pattern, $values); }
    }
    ```
    Three reasons this shape matters (all three were broken in the round-1 draft): (a) overriding `randomInt()` lets the real `generateRandomPrefix()`/`str_pad()` run, so the zero-pad assertion in 6.2.1 is genuine; (b) the queue yields a *sequence* (`[1111, 2222]`) for the regenerate-on-collision test in 6.3; (c) the `call*` proxies are the only way the TestCase can invoke the `protected` `expandPattern()` (6.2) and `generateRandomPrefix()` (6.2.1) directly.
  - [x] 6.2 Add tests for AC1/AC2 generation (use `callExpandPattern()` for the pure-helper cases and the full `getOrCreateForInvoiceContext()` for the reason-code cases): default-pattern shape (random+invoice; institution+registration); a custom pattern with literals; a pattern that expands `> 64` chars → `null`; `{registration_number}` used in the registration pattern → `null`. **Reason-code branches must each assert the *exact* `getLastError()` code — do not collapse them, since this is the only thing that proves the reg-vs-consumer branching:**
    - unknown token (`{client_id}`) in the **registration** pattern → `null` + `getLastError() === 'invalid_registration_pattern'`;
    - unknown token in the **consumer** pattern (with a valid registration pattern) → `null` + `getLastError() === 'invalid_consumer_pattern'`;
    - empty `institution_id` with `{institution_id}` in the **consumer** pattern → `null` + `getLastError() === 'invalid_consumer_pattern'`.
    Assert no `create()` call happens on any malformed pattern.
    - [x] 6.2.1 **Migration-continuity / leading-zero test:** with an illustrative `institution_id = '01999'` (leading zero; **NOT** the real merchant ID — NN#9), `invoice_id = 666666`, the default patterns, and `randomQueue = [1111]`, assert Registration Number `=== '1111666666'` and Consumer Number `=== '019991111666666'` — and build the expected Consumer Number in the test as `$instId . $reg` (concatenation of parts), not a hand-typed literal, so a digit-count slip can't pass. This proves the leading zero in `01999` survives and the order is institution+random+invoice (NN#9). **Second assertion — the zero-pad must be proven through the real `str_pad`, not a pre-padded stub:** set `randomQueue = [42]` and assert `callGenerateRandomPrefix() === '0042'` (4-char zero-pad, not `'42'`). Because the seam injects the raw integer `42` and the override does **not** touch `generateRandomPrefix()`, the production `str_pad((string) 42, 4, '0', STR_PAD_LEFT)` actually runs — a stub that returned a pre-formatted string would prove nothing.
  - [x] 6.3 Add an AC3 collision test: extend the fake repository so `getByRegistrationNumber`/`getByConsumerNumber` return an existing row on the first generated prefix and `null` afterwards; set `randomQueue = [1111, 2222]` so the source yields the sequence; assert the service regenerates and creates with the second prefix. **Add two single-axis collision tests (this is what proves the pre-check consults BOTH methods — a both-collide test passes even if the impl checks only one, because the `||` short-circuits):** (a) `getByRegistrationNumber` returns a row on attempt 1 while `getByConsumerNumber` returns `null` → must still regenerate; (b) `getByConsumerNumber` returns a row on attempt 1 while `getByRegistrationNumber` returns `null` → must still regenerate. An implementation that checks only one method fails one of these. Add an exhaustion test: pre-check always returns a collision → after `MAX_REFERENCE_ATTEMPTS` the service returns `null` with `getLastError() === 'uniqueness_exhausted'` and never calls `create()`. Also add a **constant / duplicate-forcing pattern** test — a well-formed pattern with no `{random_prefix}` and no `{invoice_id}` (e.g. a bare `{institution_id}`): every attempt produces the *same* value, the pre-check collides on each, and the service returns `null` with `getLastError() === 'uniqueness_exhausted'` and no `create()`. This is the path that maps AC2's "**duplicate**" adjective to a recorded diagnostic (the empty/malformed adjectives map to `invalid_*_pattern`).
  - [x] 6.4 (If feasible without a live framework) a gateway-helper test asserting the context passed to the service includes `registration_number_pattern`/`consumer_number_pattern`, and that a `null` voucher + non-null `getLastError()` + `logging_enabled='true'` triggers exactly one `log()` call (stub the inherited `log()`); a `null` voucher with `getLastError()===null` triggers none. **If `log()` is stubbable, also assert the encoded payload** — `json_decode` the `data` arg and check it contains `event === 'reference_generation_failed'`, the expected `reason` code, and an `invoice` key equal to the first invoice id (and that it contains **no** pattern/token/raw-value key) — so a dev can't silently drop or mistype the payload. If the existing harness can't stub `Gateway::log()` cleanly, document it as a runtime-only check and cover the service-side `getLastError()` contract in 6.2/6.3 instead. **Customer-leak guardrail (AC2 / Task 4.3):** asserting the customer sees only `not_ready` (and that `$this->Input->setErrors(...)` is NOT called on a generation failure) is a view/gateway-layer check the service harness cannot make in isolation — cover it as the runtime check in Task 7.6 rather than a unit test; do not silently treat it as covered.

- [x] **Task 7 — Verification** (AC: #1, #2, #3)
  - [x] 7.1 `php -l` every changed PHP file. **State the exact PHP version used** (Epic 1 retro Action Item 3 — production targets 8.2; if only 8.3 is available, say so and confirm no `>8.2` syntax was introduced — `random_int`, `str_pad`, `json_encode` are all 8.2-safe).
    ```sh
    php -l plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
    php -l plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
    php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
    php -l components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
    ```
  - [x] 7.2 Run the KuickPay component suite with the **working** runner (the `-c build/phpunit.xml` form is broken — Epic 1 retro AI#2, project-context.md:74):
    ```sh
    cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests
    ```
    Report the test/assertion counts (2.1 left it at 102 tests / 566 assertions; 2.2 adds generation/collision tests).
  - [x] 7.3 Prove **2.2's own changes** introduced no mutation/SOAP surface. A directory-wide grep no longer works as a gate: `getSoapClient()` (`kuickpay.php:492`) and `InsertVoucher` (the Story 3.1 SOAP client + 3.2 parser, both `done` at this baseline) legitimately exist in-tree, so a dir grep returns ~60+ pre-existing matches and can **never** echo "clean". Scope the check to the lines 2.2 actually adds (`->add(` excluded per 2.1 Task 8.4):
    ```sh
    git diff --unified=0 a065c59f -- \
      plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php \
      plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php \
      components/gateways/nonmerchant/kuickpay/kuickpay.php \
      | grep -E '^\+' \
      | grep -inE 'Transactions(->|::)|recordPayment|markPaid|markPaidInvoice|setStatus|getSoapClient|insertVoucher' \
      || echo "clean: 2.2 added no mutation/SOAP surface"
    ```
  - [x] 7.4 Confirm randomness source: `grep -n 'random_int\|mt_rand\|\brand(\|uniqid' plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` shows `random_int` only (no `mt_rand`/`rand`/`uniqid` in the generator).
  - [x] 7.5 `git status --porcelain` shows only the expected changed files under the two extension dirs plus this story file, `sprint-status.yaml`, and `_bmad-output/implementation-artifacts/deferred-work.md` (the resolved ledger entry — see "The load-bearing change" Dev Note). No core edits.
  - [x] 7.6 If a live Blesta + MySQL stack is available: enable the plugin, open a PKR invoice pay page, confirm a Voucher row is created with a **random** 4-digit-prefixed reference; reload reuses the same row (still no duplicate); save a deliberately broken pattern (e.g. `{nope}`) and confirm no Voucher is issued, the customer sees `not_ready`, and a sanitized `reference_generation_failed` entry appears in the gateway log. If no runtime/DB, state that explicitly and rely on lint + component tests.

## Dev Notes

### Critical context — read before starting

This story is the **direct successor to Story 2.1** and changes one thing: how `KuickPayVoucherReferenceService` produces the two reference strings. Story 2.1 deliberately shipped a **deterministic `'0000'` placeholder** prefix and noted in three places that "Story 2.2 replaces this algorithm with configurable patterns." 2.2 is that replacement. Read the as-built 2.1 service in full before editing — the reuse-first gate, the atomic `repository->create()`, the race-recovery re-lookup, `flatten()`, and `offsetDate()` are all correct and stay; only generation and the surrounding collision/diagnostic logic change.

**The pattern settings already exist and are validated — they are simply not consumed yet.** Story 1.2 added `registration_number_pattern` and `consumer_number_pattern` to the gateway settings form, validated them with `'empty'` (required) + a charset rule `['matches', '/^[A-Za-z0-9_{}+\-]+$/D']`, and stored them in `$this->meta`. The language notes say "Template stored … in a later workflow." 2.2 wires them through `buildProcess()` into the service and implements the token engine. **Do not re-add settings fields or validation — they exist** (`kuickpay.php:191–212`, `settings.pdt:94–113`).

### The load-bearing change: random prefix breaks 2.1's deterministic race guard

This is the single most important design point in the story. **Read it before writing the collision logic.**

Story 2.1's AC2 idempotency strategy had three layers: (1) **deterministic** references (same invoice → same reference), (2) the company-scoped unique keys, (3) transactional create + race-recovery. Layer 1 made layer 2 a real race guard: two concurrent inserts for the same invoice computed the *same* `'0000'+invoice_id`, so the second tripped the unique key and rolled back, and race-recovery returned the winner.

The confirmed live formula uses a **random** 4-digit prefix [Source: phase-0-contract.md lines 32–33, 123–124]. AC1 mandates it. Randomness **removes layer 1**: two concurrent inserts now compute *different* references, so both can succeed → two `pending` Vouchers for the same invoice. So 2.2 must be explicit about which guarantees it still provides:

- **Reference-VALUE uniqueness (AC3, this story):** fully preserved. The unique keys still reject a duplicate *value*; 2.2 adds a pre-check + bounded regenerate-on-collision so a (rare) random collision resolves to a fresh value instead of a hard failure, and exhaustion fails closed. **This is what AC3 requires** — "Registration Number and Consumer Number uniqueness are enforced AND duplicates fail closed."
- **One-active-Voucher-per-invoice under normal flow (AC2 of Story 2.1):** preserved by the unchanged reuse-first `getPendingByInvoiceId()` check. Page reload / return reuses the existing `pending` Voucher.
- **One-active-Voucher-per-invoice under a true concurrent double-submit (two simultaneous requests, neither sees a pending row):** **NOT closed by 2.2.** With random prefixes both inserts succeed. This residual "active payment context" duplicate is owned by **Story 2.4** (the architecture's company-scoped *active payment context* schema idempotency — architecture.md line 351 — implemented alongside 2.4's amount-change/active-context gating). 2.2 intentionally does not add that schema column/upgrade task; it is explicitly Story 2.4's scope per the epic and 2.1's "AC2 idempotency strategy" notes. **This deferral was reviewed and accepted — see "Decisions & deferrals (resolved decision)" below.**

**Silver lining — random prefix resolves a 2.1 deferred deadlock.** The 2.1 review deferred: "Deterministic reference collides with a non-pending voucher → permanent `not_ready` deadlock" (a regenerated `'0000'+invoice_id` after a voucher left `pending` would trip the unique key forever) [Source: deferred-work.md lines 48]. With a random prefix, regeneration yields a *different* value, so that deadlock cannot occur. When implementing, update that ledger entry to "resolved by Story 2.2 (random prefix)."

### Confirmed reference formula (the contract you are implementing)

[Source: phase-0-contract.md lines 32–33, 119–124 — `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION`, gate `APPROVED` 2026-06-09]

- `registration_number = random_prefix + invoice_id` — a 4-digit random prefix followed by the invoice id. (The live code also has a deterministic amount-based prefix for credit-adjusted invoices; that variant is **out of scope** for 2.2 — owned by Story 2.4's amount handling. Do not implement it now.)
- `consumer_number = institution_id + random_prefix + invoice_id` — a 5-digit Institution ID, then the 4-digit random prefix, then the invoice id. With the canonical patterns this is exactly `{institution_id}` + (`{random_prefix}{invoice_id}` = registration_number) = `{institution_id}{registration_number}`.
- **Worked example (illustrative values — the real Institution ID stays in gateway settings, not committed):** a leading-zero Institution ID like `01999` + random `1111` + invoice `666666` → Consumer Number `019991111666666`, Registration Number `1111666666`. Keeping the `institution_id + random_4 + invoice_id` order as the Blesta default preserves continuity with the existing HosterPK/WHMCS references through migration. The leading zero is load-bearing — see NN#9 (string handling, no `int` cast). The contract confirms this formula **generically** (5-digit Institution ID); the literal merchant Institution ID is deliberately kept out of the contract and out of this story [Source: phase-0-contract.md lines 32–33, 47–50, 119–124].
- The reference is a single concatenated string the customer pays — **no separators** in the confirmed contract. Hence the canonical default patterns are `+`-free.
- **No production Institution ID, prefix, or any merchant value is hard-coded** — `institution_id` comes from `$this->meta`, the prefix is generated, the patterns come from settings. [Source: phase-0-contract.md "No Hard-Coding Assertion" lines 41–50; NFR10]

### Token grammar (define it precisely; the dev must not improvise)

- Delimiters `{` `}`. Recognized tokens: `{random_prefix}`, `{invoice_id}`, `{institution_id}`, `{registration_number}` (consumer pattern only). Substitution is literal string replacement.
- Everything outside `{...}` is a literal. The settings charset `/^[A-Za-z0-9_{}+\-]+$/` already guarantees only `A–Z a–z 0–9 _ { } + -` reach generation (no spaces, no `:`), so there is **no parameterized token** like `{random_prefix:6}` — the random width is a fixed 4 digits.
- `+` is a **literal**, not an operator. `{random_prefix}+{invoice_id}` would produce `1234+55`. The recommended/default patterns omit `+`.
- After substitution, any residual `{` or `}` means an unknown/malformed token → `null` (fail closed). This is the simplest, safest unknown-token detector and needs no token allow-list scan beyond the recognized set.

### Where the AC2 diagnostic goes (and why)

There is **no durable audit table yet** — `kuickpay_audit_events` and the admin workbench are Epic 4 (architecture.md lines 707–725, 842–849). For 2.2, "an admin-safe validation or diagnostic error is recorded" is satisfied with the lightest in-scope, admin-visible, sanitized sink: the inherited **`Gateway::log()`** (`components/gateways/lib/gateway.php:254`), which writes to `log_gateways` and is visible under the gateway's logs in admin. Gate it on the existing `logging_enabled` setting. Record structured non-secret data only (`{"event":"reference_generation_failed","reason":"<code>"}`). The reason codes (`invalid_registration_pattern`, `invalid_consumer_pattern`, `uniqueness_exhausted`) are sanitized and value-free.

- The **service** (plugin) cannot call the gateway's `protected log()`, so the service exposes `getLastError()` and the **gateway** records the entry. This keeps the ownership boundary (plugin decides, gateway renders/logs) intact.
- A durable `voucher.generation_failed` audit event in `kuickpay_audit_events` is **Epic 4 / Story 4.5** — note it; do not build the audit table here.
- **Caller-context caveat:** `Gateway::log()` resolves `staff_id` from the requestor when null. In the client pay flow there is no staff member, so `staff_id` is null — `Logs::addGateway` accepts a null `staff_id`, so this is fine. Do not invent a staff id.

### As-built files to read in full before editing (UPDATE targets)

- `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` (175 lines) — `getOrCreateForInvoiceContext()` (reuse → generate → create → race-recovery), `generateReferences()` (the `'0000'` placeholder to replace), `flatten()`, `offsetDate()`. **Primary change site.**
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` (92 lines) — `create()`, `getPendingByInvoiceId()`, `getWithInvoices()`. **Add two pass-throughs (Task 3); do not change `create()`.**
- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` (258 lines) — **read-only for this story**; `getByConsumerNumber()` (line 113) and `getByRegistrationNumber()` (line 129) already exist and are what Task 3 delegates to. No model change.
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (712 lines) — `buildProcess()` (567–608) builds the context and calls the service; `$meta` already resolved at line 589. **Add two context keys (4.1) + the diagnostic log (4.2).** The currency guard (577) → companion guard (581) → `if (!$this->Input->errors())` gate (585) ordering is load-bearing — preserve it.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` (99 lines) — pattern notes at lines 37, 39. **Update those two notes (Task 5).**
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php` (140 lines) — fake repository + three tests. **Rewrite the deterministic create test; extend the fake repo with uniqueness pass-throughs (Task 6).**

### Reference-service data contract (unchanged from 2.1 — do not break it)

The service still returns the **flat associative array** (`['id','company_id','client_id','gateway_id','currency','amount','status','registration_number','consumer_number','date_due','date_expires','invoices'=>[…]]`) on success or `null` on failure. The gateway passes it to the view unchanged; `process.pdt` reads `$voucher['consumer_number']` etc. Generation changes the *values* of `registration_number`/`consumer_number`, never the shape. [Source: 2.1 Dev Notes "Voucher data contract"]

### Testability — follow the Epic 1 "pure-helper extraction" win

The Epic 1 retro identified pure-helper extraction as "the single move that made every story verifiable" with no live runtime. Apply it here: `expandPattern()` is pure (no DB/randomness) → directly unit-testable; `generateRandomPrefix()` is the injectable randomness seam → tests stub it for determinism; the uniqueness pre-check goes through the repository → the existing fake-repository pattern covers it. This keeps the whole generation/collision path testable without a database. [Source: epic-1-retro-2026-06-10.md §2; 1.4 `executeConnectionProbe()` seam]

### What must NOT happen (regression / scope guardrails)

- **No SOAP, no parser, no posting, no transaction, no invoice paid-state change, no cron, no events/actions/permissions, no admin controllers/views.** Same prohibitions as Story 2.1 — 2.2 is narrower still (generation only).
- **No schema change.** Do not add columns, unique keys, or an upgrade task. (The active-context unique key is deferred to Story 2.4 — see "Decisions & deferrals".) The 2.1 `kuickpay_vouchers`/`kuickpay_voucher_invoices` schema is untouched.
- **No float math on amounts.** Not touched here, but do not introduce any.
- **No hard-coded merchant values.** Institution ID, prefix width source, and patterns all come from settings/generation. The only literals 2.2 introduces are the canonical default *pattern templates* (token strings, not merchant data) and the `4`/`9999` random bounds.
- **No customer-facing leak.** Generation failures never reach the customer beyond `not_ready`; no pattern/token/reason/value in any customer view.
- **No reuse-path regression.** Reuse must not regenerate or mutate an existing `pending` Voucher.

### Scope: what 2.2 owns vs later stories

| Surface | Owned by 2.2? | Where |
|---|---|---|
| Configurable pattern consumption (`{token}` engine) | ✅ Yes | `KuickPayVoucherReferenceService::expandPattern()` |
| Random 4-digit prefix generation | ✅ Yes | `generateRandomPrefix()` |
| Default pattern shapes (AC1) | ✅ Yes | `DEFAULT_*_PATTERN` constants |
| Generation-time validation + fail-closed (AC2) | ✅ Yes | `expandPattern()` returns `null`; `getLastError()` |
| Admin-safe diagnostic record (AC2) | ✅ Yes (gateway `log()`) | `buildProcess()` |
| Reference-VALUE uniqueness + regenerate-on-collision (AC3) | ✅ Yes | retry loop + repo pass-throughs + schema keys |
| Settings fields/validation for the patterns | ❌ No (exists) | **Story 1.2** (done) |
| Active-payment-context uniqueness (concurrent distinct-reference duplicate) | ❌ No | **Story 2.4** (+ architecture line 351) — *deferred; accepted (see Decisions & deferrals)* |
| Amount-based deterministic prefix for credit-adjusted invoices | ❌ No | **Story 2.4** |
| Multi-invoice reference/allocation | ❌ No | **Story 2.4** |
| InsertVoucher SOAP call / invoice-data mapping | ❌ No | **Story 2.3** / **3.1** |
| Durable `kuickpay_audit_events` record of generation failure | ❌ No | **Epic 4 / Story 4.5** |
| Styled reference panel + copy action | ❌ No | **Story 2.5** |

### Decisions & deferrals (resolved decision)

These were carried as an open question during drafting and are now **resolved** — they are *decisions*, not work items for the dev. Treat them as fixed scope.

- **Active-payment-context uniqueness (concurrent distinct-reference duplicate) → DEFERRED to Story 2.4 — accepted.** With a random prefix, a *true* concurrent double-submit (two simultaneous requests, neither sees a `pending` row) computes two different references, so both inserts succeed → two `pending` Vouchers for one invoice. 2.2 does **not** close this. The fix is the architecture's company-scoped *active payment context* schema idempotency (architecture.md line 351), owned by Story 2.4 alongside its amount-change/active-context gating. **Decision: do not pull the active-context unique key forward into 2.2** — no schema column, no upgrade task here. The residual is bounded (it requires genuinely simultaneous submits) and is accepted for 2.2.
- **What 2.2 *does* still guarantee:** reference-VALUE uniqueness (AC3) via pre-check + bounded regenerate-on-collision + the schema unique keys; and one-active-Voucher-per-invoice under the *normal* flow (page reload / return) via the unchanged reuse-first `getPendingByInvoiceId()` gate (does not regress 2.1 AC2). The verification (Task 7.6) should state plainly that 2.2's tests cover reference-VALUE collisions only and do **not** assert closure of the concurrent distinct-reference duplicate.
- **AC2 adjective → reason-code mapping** (so the AC-coverage is auditable): *empty* / *malformed* references → `expandPattern()` → `null` → `invalid_registration_pattern` / `invalid_consumer_pattern`; *duplicate* references → the bounded-retry exhaustion path → `uniqueness_exhausted` (reachable in practice by a well-formed but duplicate-forcing pattern — one with no `{random_prefix}` and no `{invoice_id}`; tested in Task 6.3).
- **Post-pre-check `create() === null` for the *same* invoice → benign, not an AC2 miss.** If a concurrent insert wins between the pre-check and the write, `create()` returns `null`, the existing race-recovery re-lookup reuses the winner, and `$lastError` stays `null` → benign `not_ready`. This is intended fail-closed behavior, not a dropped diagnostic.

### Previous story intelligence (2.1 + Epic 1 retro)

- **2.1 (done):** Established the service/repository/model split, the flat data contract, the reuse-first gate, the atomic create + race-recovery, and the schema unique keys. Generation was a deliberate `'0000'` placeholder. Its review **deferred to 2.2**: reference regeneration after a failed/expired voucher and the deterministic-collision deadlock (both resolved by 2.2's random prefix). [Source: 2.1 Dev Notes; deferred-work.md lines 48–52]
- **1.2 (done):** Stored + shape-validated the two pattern strings; documented illustrative defaults `{random_prefix}+{invoice_id}` / `{institution_id}+{registration_number}` and explicitly **deferred generation + the canonical token set + the determinism fix to 2.2**. [Source: 1-2 Dev Notes lines 134–135, 157, 284]
- **Epic 1 retro:** (a) state the exact PHP version in the verification note (lint ran on 8.3, target 8.2); (b) use `--bootstrap tests/bootstrap.php tests`, never `-c build/phpunit.xml`; (c) pure-helper extraction is the testability win; (d) Epic 2 is the first real consumer of the masking/redactor boundary — but 2.2 logs only sanitized structured data, so it does not exercise SOAP redaction (that is 2.3/3.2). [Source: epic-1-retro-2026-06-10.md §3, §7, §11]

### Git intelligence

HEAD `a065c59f` marks 2.1 done after a 3-layer adversarial review (102 tests / 566 assertions, all ACs/NNs PASS, 1 patch applied, 5 deferred). The service/repository/model/test files 2.2 edits are all stable and freshly reviewed. Commit convention (AGENTS.md): `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars. Suggested commits:
- `feat(kuickpay_reconcile): generate configurable reference patterns`
- `feat(kuickpay_reconcile): enforce reference uniqueness with retry`
- `feat(kuickpay): wire reference patterns and record generation diagnostics`
- `docs(kuickpay): document reference pattern token vocabulary`
- `test(kuickpay_reconcile): cover pattern generation and collision paths`

### Verification commands

```sh
# 1. Syntax (state the PHP version actually used; target is 8.2)
php -l plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
php -l plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
php -l components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php

# 2. Component suite (working runner)
cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests

# 3. No mutation/SOAP surface ADDED BY 2.2 — diff-scoped.
#    (A dir grep can't gate this: getSoapClient/InsertVoucher already exist from done Stories 3.1/3.2.)
git diff --unified=0 a065c59f -- \
  plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php \
  plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php \
  components/gateways/nonmerchant/kuickpay/kuickpay.php \
  | grep -E '^\+' | grep -inE 'Transactions(->|::)|recordPayment|markPaid|setStatus|getSoapClient|insertVoucher' \
  || echo "clean"

# 4. Secure randomness only
grep -n 'random_int\|mt_rand\|\brand(\|uniqid' plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php

# 5. No core edits
git status --porcelain
```

## Project Structure Notes

- **Alignment with architecture:** generation lives in `KuickPayVoucherReferenceService` per architecture.md line 782 ("owns create/reuse/idempotency decisions for Voucher references"). No new files are strictly required; a small pure helper class is optional but the recommended approach keeps `expandPattern()`/`generateRandomPrefix()` inside the existing service to minimize surface and reuse the existing fake-repository test harness.
- **Files modified (UPDATE):**
  - `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` — token engine, random seam, collision retry, `getLastError()`
  - `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` — `getByRegistrationNumber()` / `getByConsumerNumber()` pass-throughs
  - `components/gateways/nonmerchant/kuickpay/kuickpay.php` — pass patterns into context, record AC2 diagnostic
  - `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — pattern note docs
  - `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php` — rewrite deterministic test, add generation/collision tests
  - `_bmad-output/implementation-artifacts/deferred-work.md` — mark the 2.1 "deterministic reference collides with a non-pending voucher → permanent `not_ready` deadlock" entry resolved by 2.2's random prefix (ledger housekeeping; see "The load-bearing change")
- **Files NOT changed:** `models/kuickpay_vouchers.php` (uniqueness methods already exist), `kuickpay_reconcile_plugin.php` (no schema change), `views/default/process.pdt` (data contract unchanged), `settings.pdt` (pattern fields already present).

## References

- [Source: epics.md#Story 2.2, lines 439–461] — user story + AC1/AC2/AC3 (reproduced verbatim above); FR7.
- [Source: epics.md#Story 2.1, lines 421–437] and [Source: epics.md#Story 2.4, lines 498–528] — scope boundaries (2.1 placeholder; 2.4 owns amount-change/active-context/multi-invoice).
- [Source: docs/kuickpay/phase-0-contract.md lines 32–33, 41–50, 119–124] — confirmed Consumer/Registration Number formula (random prefix); no-hard-coding assertion; gate APPROVED.
- [Source: architecture.md lines 351, 537–538] — schema-level company-scoped idempotency for Registration/Consumer Number and active payment context; avoid nullable-unique traps.
- [Source: architecture.md lines 518–526, 781–789] — gateway/plugin ownership; `KuickPayVoucherReferenceService` owns reference create/reuse/idempotency.
- [Source: architecture.md lines 437, 608, 648–662] — admin-only sanitized diagnostics; anti-patterns (no transaction/markPaid/SOAP in buildProcess).
- [Source: components/gateways/lib/gateway.php:254] — `Gateway::log()` writes to `log_gateways` (the AC2 diagnostic sink).
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php:191–212, 567–608] — existing pattern settings rules; `buildProcess()` context build and guard ordering.
- [Source: plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php] and [Source: .../KuickPayVoucherRepository.php] and [Source: .../models/kuickpay_vouchers.php:113,129] — as-built service/repository/model.
- [Source: 1-2-configure-kuickpay-gateway-settings.md lines 134–135, 157, 284] — pattern fields stored, generation deferred to 2.2, illustrative defaults, `+`/space charset note.
- [Source: deferred-work.md lines 18, 48–52] — random-prefix idempotency note; 2.1 deferrals resolved/owned by 2.2/2.4.
- [Source: epic-1-retro-2026-06-10.md §2, §3, §7, §11] — pure-helper testability, PHP-version verification note, working PHPUnit runner, Epic 2 first-consumer risk.
- [Source: project-context.md] — PHP 8.2, legacy global classes, Loader/Input/Record, language-file rule, secure-randomness/no-secret-leak, no core edits.

## Dev Agent Record

### Agent Model Used

### Debug Log References

- 2026-06-10: Added failing expander tests, then implemented protected `expandPattern()` and default pattern constants. Targeted service suite passed: 6 tests, 20 assertions.
- 2026-06-10: Replaced placeholder reference generation with configured patterns, secure random prefix, last-error reason codes, bounded collision retry, and repository uniqueness pass-throughs. Focused suite passed: 16 tests, 51 assertions; plugin service/repository PHP lint passed.
- 2026-06-10: Wired gateway context to include configured reference patterns and added sanitized generation failure logging. Gateway helper tests passed: 13 tests, 26 assertions; gateway PHP lint passed.
- 2026-06-10: Updated admin pattern notes to document the live token vocabulary and recommended defaults. Gateway helper tests passed: 14 tests, 32 assertions; language PHP lint passed.
- 2026-06-10: Completed Task 6 test coverage for pure expansion, default/custom generation, reason codes, leading-zero preservation, random zero-padding, registration/consumer collision retry, exhaustion, duplicate-forcing patterns, gateway context, and sanitized log payloads. Focused service suite passed: 17 tests, 56 assertions.
- 2026-06-10: Verification passed. PHP lint ran with PHP 8.3.31 (target is PHP 8.2; no PHP >8.2 syntax/APIs introduced). KuickPay component suite passed: 120 tests, 631 assertions. Diff-scoped mutation/SOAP check returned clean. Randomness grep showed `random_int` only. No live Blesta/MySQL runtime verification was available in this run.

### Completion Notes List

- Implemented the canonical token expander with fail-closed handling for unknown tokens, unresolved braces, empty resolved token values, empty results, and >64-character outputs.
- Implemented random-prefix configurable reference generation, reuse-preserving collision retries, sanitized generation reason codes, and repository uniqueness pass-through methods.
- Wired `buildProcess()` through testable gateway helpers that pass reference patterns to the service and record admin-safe `reference_generation_failed` diagnostics without setting customer-facing errors.
- Updated admin-only pattern notes for the live token vocabulary without adding customer-facing generation failure copy.
- Completed focused service and gateway helper coverage for all AC1/AC2/AC3 generation, collision, and diagnostic paths available without a live Blesta runtime.
- Verified AC1/AC2/AC3 via component tests and static checks; runtime customer `not_ready` behavior was not manually exercised because no live Blesta/MySQL stack was used.

### File List

- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php
- components/gateways/nonmerchant/kuickpay/kuickpay.php
- components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php

## Change Log

- 2026-06-10: Story created (ready-for-dev) via bmad-create-story. Exhaustive context-engine analysis completed — comprehensive developer guide created. Key design call recorded: the confirmed live formula's **random** prefix removes Story 2.1's deterministic unique-key race guard, so 2.2 preserves reference-VALUE uniqueness (AC3) via pre-check + bounded regenerate-on-collision + schema keys, keeps reuse-first as the primary duplicate-active-Voucher guard, and explicitly defers the concurrent-distinct-reference active-payment-context guard to Story 2.4 (recorded as an accepted deferral — see "Decisions & deferrals").
- 2026-06-10: Product Owner confirmed the production default format `institution_id + random_4 + invoice_id` to preserve WHMCS→Blesta migration continuity. Locked this as the canonical default, added NN#9 (Institution ID / random prefix are leading-zero-safe strings — no `int` cast) and a migration-continuity unit test (Task 6.2.1). **PO decision: `{invoice_id}` resolves to the Blesta internal invoice id** (the `id` already passed in `$invoice_amounts` by `buildProcess()`) — no invoice-number lookup or legacy-number mapping required. Cross-checked the format against docs/kuickpay/phase-0-contract.md: confirmed consistent (lines 32–33, 119–124). Per the contract's own security note (lines 47–50) and NFR10, the **real** Institution ID is kept out of this story/tests — illustrative placeholders (`01999`) are used and the test builds the expected value from parts. (Note: the PO's typed example had one extra digit vs its own breakdown of 5-digit institution + 4-digit random + 6-digit invoice = 15 digits; the contract confirms a 4-digit random, so 15 digits is correct.)
- 2026-06-10: Multi-validator triage (4 independent reviews) synthesized and verified against source; story revised pre-dev. Applied: (1) **diff-scoped** the "no mutation/SOAP" verification grep in Task 7.3 and Dev-Notes — the dir-wide form can never echo "clean" because `getSoapClient()`/`InsertVoucher` already exist from done Stories 3.1/3.2; (2) pinned the `$lastError` contract — `generateReferences()` owns setting the precise reason code and returns an empty-string array, the retry loop must not re-derive it (prevents a silently-dropped AC2 diagnostic); (3) `expandPattern()` `private` → `protected` to match the "directly unit-testable" claim; (4) `$lastError` reset must be the first statement before the early-return-inside-try, and the `Throwable` catch must leave it null; (5) aligned the diagnostic `logging_enabled` gate to default `'true'` (matches `settings.pdt:217`; the checkbox has no hidden field, so `?? 'false'` would suppress AC2 diagnostics on a default-configured gateway); (6) resolved the dangling "Open Question" references into an accepted "Decisions & deferrals" section (active-payment-context uniqueness deferred to 2.4); (7) instructed adding the uniqueness pre-check methods to the *shared* test fake repo (the method-wide `try/catch` would otherwise mask a missing method as a confusing null) + a determinism subclass; (8) added a constant/duplicate-forcing exhaustion test mapping AC2's "duplicate" adjective to `uniqueness_exhausted`; (9) added the retry-loop control-flow reference, the `MAX_REFERENCE_ATTEMPTS` const declaration, and the explicit "pre-check both reg AND consumer" rule; (10) accuracy fixes — corrected the swapped `models/kuickpay_vouchers.php:113/129` order, repointed NN#4's `architecture.md:84` citation (line 84 is a posting invariant, not generation validation), added `deferred-work.md` to the expected changed-files set, added the invoice id to the diagnostic payload (NN#5 consistency), and a brace-usage admin note. Status unchanged: ready-for-dev.
- 2026-06-10: Round-2 validation (4 narrow-lane reviews: regression, dev-executability, AC2-path, AC↔test traceability) synthesized and verified against source. Production design confirmed sound (retry loop, `$lastError` contract, logging chain, PHP 8.2 compat, full AC2 `log()` chain all PASS); every real finding was in the round-1 *test scaffolding* I added. Applied: (1) **redesigned the determinism test seam** — the round-1 subclass stubbed `generateRandomPrefix()` directly, which (a) bypassed the production `str_pad` so the `42→'0042'` zero-pad assertion proved nothing, (b) couldn't yield a prefix *sequence* for the collision test, and (c) couldn't reach the now-`protected` `expandPattern()` from a sibling `TestCase`. Fixed by making the secure-random **integer** the seam (`protected randomInt()`), keeping padding in the real `generateRandomPrefix()`, using an int **queue** (`randomQueue`), and adding public `call*` proxies (Tasks 2.1, 6.1, 6.2.1, 6.3); (2) corrected the `logging_enabled` *rationale* — verified `editSettings()` (`kuickpay.php:113–127`) normalizes the checkbox to a concrete value on every save, so the `?? 'true'` fallback fires only for legacy pre-1.2 gateways (the `'true'` default itself stays correct); (3) added a dedicated **consumer-side** reason-code assertion (`invalid_consumer_pattern`) so the reg-vs-consumer branching is actually tested (Task 6.2); (4) added **single-axis collision** tests (registration-only / consumer-only) so a one-method pre-check can't pass via `||` short-circuit (Task 6.3); (5) added the logged-**payload** assertion (event/reason/invoice, no leak) and an explicit note that the customer-`not_ready`/no-`setErrors` guardrail is a runtime-only check covered in Task 7.6, not silently assumed (Task 6.4); (6) added the `$refs`→`$voucherData` wiring note to the retry-loop block. Dismissed as non-findings: one lane reviewed a pre-round-1 copy and re-flagged the already-applied `logging_enabled='true'` + invoice-payload changes. Status unchanged: ready-for-dev.
- 2026-06-10: Implemented Task 1 token expander and default pattern constants with targeted service tests passing.
- 2026-06-10: Implemented Tasks 2 and 3 configurable reference generation, collision retry, last-error contract, and repository uniqueness pass-throughs.
- 2026-06-10: Implemented Task 4 gateway pattern wiring and sanitized generation diagnostic logging.
- 2026-06-10: Implemented Task 5 admin reference pattern token documentation.
- 2026-06-10: Completed Task 6 generation, collision, and gateway wiring tests.
- 2026-06-10: Completed Task 7 verification and moved story status to review.

## Review Findings

_Adversarial code review (bmad-code-review) — 2026-06-10, baseline `a065c59f`. Three parallel layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor). **All 3 ACs and all 9 Non-Negotiables PASS** against the actual code + tests; results independently verified. 1 patch, 4 deferred, 11 dismissed as noise (false positives / by-design / unreachable inputs)._

- [x] [Review][Patch] Log `invoice` diagnostic key with an `(int)` cast to match the pinned contract [components/gateways/nonmerchant/kuickpay/kuickpay.php:671] — spec Task 4.2 prescribes `'invoice' => (int) ($invoice_amounts[0]['id'] ?? 0)`; as-built emitted `?? null` with no cast. Functionally equivalent in the live flow (`normalizeInvoiceAmounts()` preserves the integer `id`), but the cast makes the logged value type-stable regardless of upstream id type and restores the contract round-1 triage pinned. (blind + auditor) **APPLIED 2026-06-10 (commit `9859b17a`); suite green at 120 tests / 631 assertions.**
- [x] [Review][Defer] `expandPattern()` length check counts bytes, not characters, vs `varchar(64)` [plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:233] — deferred, pre-existing (carried from 2.1's `strlen` guard; unreachable via the ASCII-enforced pattern charset; if ever hit it fails closed, never corrupts). (edge)
- [x] [Review][Defer] `company_id` mismatch: collision lookup uses `(int) … ?? 0` (L59/72) while the insert uses `… ?? null` (L90) [plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:59] — deferred, low-risk (only triggers on an abnormal null-company context that cannot occur in an authenticated HTTP pay flow; fails closed without a bad write). (edge)
- [x] [Review][Defer] Throwable-catch and benign null-return paths record no admin diagnostic [plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:119] — deferred, by design (spec Task 2.4/2.5 forbids setting `lastError` in the catch → benign `not_ready`); durable audit of swallowed DB failures is owned by Epic 4 / Story 4.5. (edge)
- [x] [Review][Defer] 4-digit random prefix → 10k keyspace; high invoice volume could raise regenerate-retry frequency [plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php:141] — deferred, informational (reference uniqueness derives from `invoice_id`; bounded retry fails closed at `uniqueness_exhausted`; no corruption). (blind)
