---
baseline_commit: 6780d77fa797441ebf9d4bc0134c21fff7b2c175
---

# Story 1.4: Run Safe KuickPay Connection Tests

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin operator,
I want to test KuickPay connectivity without accidentally creating a payable Voucher,
so that setup can be verified safely before customer use.

## Acceptance Criteria

(Reproduced verbatim from [Source: epics.md#Story 1.4, lines 373-393].)

**AC1 — Normal connection test reports outcome and creates no payment**
**Given** an admin runs the normal connection test
**When** the gateway contacts KuickPay
**Then** the test reports success, credential failure, endpoint unavailable, or timeout
**And** it does not create a payable Voucher or mark any invoice paid.

**AC2 — Prefer a safe metadata operation over InsertVoucher**
**Given** KuickPay supports a safe metadata operation such as `Echo` or `GetInstitutionsList`
**When** connection testing is configured
**Then** the gateway prefers that operation over `InsertVoucher`.

**AC3 — Live voucher test requires explicit confirmation and clear labeling**
**Given** an admin requests a live voucher test
**When** the action is submitted
**Then** explicit admin confirmation is required
**And** any resulting test record is clearly labeled as a test.

> **What this story actually changes** (read before scoping): Stories 1.1–1.3 delivered the gateway scaffold, the grouped settings form (incl. `wsdl_url` and `soap_timeout`), credential encryption/masking, and the gateway-owned `maskCredentials()` redaction boundary. **1.4's net new work is a safe, in-scope connection test:** (1) add a sentinel-gated **"Test connection"** action to the settings form (Task 1); (2) implement a **transport-level HTTPS reachability probe** to the configured `wsdl_url` that reports **reachable / endpoint-unavailable / timeout**, creates no Voucher, makes **no SOAP operation call**, and adds **no `lib/`** (Task 2 — AC1 safe-path, AC2 "no InsertVoucher"); (3) add a **userinfo/SSRF guard** on the URL it fetches (Task 3); (4) add the language strings for the action and result states (Task 4); (5) **document the deferred contracts** — server-side *credential-failure* validation, the real `Echo`/`GetInstitutionsList` preference, and the AC3 live-voucher test — all of which require the Epic-3 SOAP client and Epic-2 Voucher persistence (Task 5). **Scope was confirmed with the product owner (2026-06-09): ship the safe transport test now; defer AC3 to Epic 3.** See **Dev Notes → "Scope: what 1.4 owns vs what later stories own"** and **"Why a transport probe, not a SOAP call."**

## Dependency & starting-state (read FIRST)

**Stories 1.1, 1.2, 1.3 are `done`/merged — the gateway is in the post-1.3 state.** [Source: sprint-status.yaml `1-1…: done`, `1-2…: done`, `1-3…: done`; git log `feat(kuickpay): add credential redaction boundary` and predecessors]

1.4 edits the **same three gateway files** 1.1–1.3 built. As-built state you are starting from (read each in full before editing):

- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (421 lines) — `class Kuickpay extends NonmerchantGateway` (legacy global class, **no namespace, no `declare(strict_types)`**). Key members:
  - `$credential_mask_fields` (lines **21-30**) and `maskCredentials(array $data)` (lines **291-294**, `protected`, wraps `maskDataRecursive`) — the **gateway-owned credential redaction boundary** from 1.3. **Preserve.**
  - `__construct()` (35-44): `loadConfig`, `Loader::loadComponents($this, ['Input'])`, `Language::loadLang('kuickpay', …)`. **No HTTP/SOAP component is loaded today** — 1.4 must load what it needs.
  - `getSettings(array $meta = null)` (62-86): renders `views/default/settings.pdt`; loads `Form`/`Html`; sets `companion_installed`, `currency_policy`/`fee_policy` option arrays, and the 1.3 `voucher_password_stored`/`inquiry_password_stored` booleans.
  - `editSettings(array $meta)` (94-270): checkbox-default normalization → option defaulting → whitespace-trim → `$same` dedupe (1.3) → builds `$rules` (incl. `wsdl_url` rule **136-149**, `soap_timeout` numeric rule **194-200**) → `setRules` (266) → `validates` (267) → `return $meta` (269). **This is the only POST/save hook and the only viable insertion point for the test** (see Dev Notes "Connection-test mechanism").
  - `encryptableFields()` (277-280) → `['voucher_password','inquiry_password']`. **Do not change.**
  - `buildProcess`/`validate`/`success` (347/380/403) — **fail-closed** via `getCommonError('unsupported')`. **Do not touch.**
  - `companionInstalled()` (414-419, `private`).
- `views/default/settings.pdt` (249 lines) — entire form gated on `$companion_installed`; otherwise a danger alert. Five `title_row` groups (Endpoints & Credentials @ 6, …, Logging & Reconciliation @ 210). **There is NO submit button in this fragment** — Blesta's admin layout wraps the gateway output in its own `<form>` and Save button. The `soap_timeout` text field closes the form at lines **244-248**; the gated block closes with `<?php } ?>` at line **249**.
- `language/en_us/kuickpay.php` (83 lines) — all field/note/group/error keys present, incl. `Kuickpay.!error.wsdl_url.*` (61-62), `Kuickpay.!error.soap_timeout.numeric` (72), `Kuickpay.!error.companion_missing` (5), `Kuickpay.process.not_ready` (7). **No connection-test keys exist** — 1.4 adds them.

**1.1–1.3 invariants that must be preserved** (do not revert): companion-missing guard; fail-closed payment path; 1.2 validation, whitespace-trim, and `/D`-anchored regexes; 1.3 same-as-voucher dedupe (`unset` at 125-130), masked password indicators, `maskCredentials()` boundary, and the exact `encryptableFields()` set.

## Non-Negotiables (read before any task)

1. **The connection test must never create a Voucher or mark any invoice paid.** [Source: epics.md AC1 line 384; prd.md FR-4 line 123; UX-DR10 line 170] The 1.4 probe makes **no SOAP operation call at all** — it performs a transport-level HTTPS reachability request to `wsdl_url` only. It must **not** call `InsertVoucher`, `BillPaymentInquiry`, `BillPaymentBulkInquiry`, or any business operation; it must not write to any voucher/invoice/transaction table; it must not touch `buildProcess`/`validate`/`success`. AC2's "prefer a safe metadata operation over `InsertVoucher`" is satisfied trivially here because the probe creates nothing and calls no business op.

2. **Do NOT call `$this->log()` from inside `editSettings()`.** [Source: components/gateways/lib/gateway.php:254-288; app/models/logs.php:142-146; app/models/gateway_manager.php:599-650] During `editSettings()` the gateway instance has **no `gateway_id`** (`GatewayManager::edit()` loads a fresh `loadGateway()` instance and never calls `setGatewayId()`), and `Logs::addGateway` **requires** a valid `gateway_id` (`validateExists` against `gateways`, **no `if_set`**). `log()` writes `gateway_id => $this->gateway_id` (null) and **throws** when `Logs->errors()` is non-empty (gateway.php:283-285) — so a `log()` call in the test path **would break the settings save**. The test reports **only** through `Input->setErrors()` (admin UI). This supersedes the 1.3 note that anticipated "1.4 logs through `maskCredentials()`" — see Dev Notes "Why `maskCredentials()` is not consumed here."

3. **No raw transport/SOAP/exception detail in any admin-facing message.** [Source: epics.md UX-DR28 line 206; UX-DR7 line 164; ux EXPERIENCE.md:48,53] Every result message is a `$lang['Kuickpay.*']` key surfaced via `Input->setErrors()`. Never concatenate a cURL/`SoapFault`/exception string, a URL, a host, an operation name, a stack trace, or any credential into the message. Classify the failure **internally** to a state, then show the matching language-keyed string.

4. **Add NO `lib/` files and NO new SOAP client.** [Source: architecture.md:770, 776-778; project-context.md "Preserve extension folder contracts"] `architecture.md:770` — "External KuickPay SOAP calls live **only** in `KuickPaySoapClient.php`" — and that protocol-library class is **Epic 3 / Story 3.1**. 1.4 must **not** create `components/gateways/nonmerchant/kuickpay/lib/`, must **not** instantiate `SoapClient`, and must **not** call any SOAP operation. A transport reachability request (HTTPS GET for the WSDL document) is **not** a SOAP call and is in-scope; an `Echo`/`GetInstitutionsList` SOAP invocation is **not** (Epic 3). [Source: architecture.md:390-393 optional safe ops are Epic-3-owned]

5. **The test action must not be triggered on a normal Save, and its sentinel must never persist.** The probe runs **only** when an explicit "Test connection" control posts `run_connection_test === 'true'`. A normal Save (sentinel absent) must behave exactly as today — no network call. `run_connection_test` is a transient action flag: it must **not** be added to `$rules`, must **not** be returned in `$meta`, and must be `unset()` before `return $meta` so it never reaches `gateway_meta`.

6. **1.4 is the first server-side consumer of `wsdl_url` — guard the fetch.** [Source: deferred-work.md "wsdl_url HTTPS validation is lenient (SSRF / userinfo)"; architecture.md:83,460] Before fetching, **reject a `wsdl_url` that contains userinfo** (`https://user:pass@host/…`) so credentials embedded in the URL are never transmitted/logged. HTTPS is already enforced by the 1.2 rule (kuickpay.php:136-149). Enforce TLS peer + host verification on the request. A full host-allowlist is **deferred** (real KuickPay hosts are unconfirmed until Phase 0 / Epic 5) — document it, do not invent host literals.

7. **All admin-facing strings come from language files.** [Source: project-context.md#Language-Specific Rules; epics.md UX-DR28] Every label, note, and result message is a `$lang['Kuickpay.*']` key via `$this->_(...)` / `Language::_(...)`. Preserve the file's single-quote / one-key-per-line style; do not reorder or rewrap existing keys.

8. **Stay in scope; no regressions.** Touch **only** the three gateway files. Preserve all 1.1–1.3 behavior. Do **not** add: a SOAP client / `lib/` classes, the protocol-library redactor, an AJAX/controller route, customer-facing changes, plugin code, schema, cron, or core edits. No file under `plugins/kuickpay_reconcile/` changes. Match parent signatures; target **PHP 8.2** (no 8.3+ syntax). [Source: architecture.md:765-778; project-context.md]

## Tasks / Subtasks

- [ ] **Task 1 — Add the sentinel-gated "Test connection" action to the settings form (AC1 trigger)** [Source: views/default/settings.pdt; admin_company_gateways.php:162-293]
  - [ ] 1.1 In `settings.pdt`, **inside** the `$companion_installed` block (before the closing `<?php } ?>` at line 249, after the final group's closing `</div>` at line 248), add a "Test connection" submit control that posts the sentinel `run_connection_test=true`. Use a Blesta form helper consistent with the file's idiom, e.g. `$this->Form->fieldButton('run_connection_test', $this->_('Kuickpay.test_connection', true), ['type' => 'submit', 'value' => 'true', 'class' => 'btn btn-outline-secondary'])` (verify the exact `Form` button helper available in this Blesta build before finalizing; fall back to a plain `<button type="submit" name="run_connection_test" value="true" class="btn btn-outline-secondary">` with the label pulled from the language key). Do **not** add a `<form>` tag — Blesta's layout owns the form and the primary Save button.
  - [ ] 1.2 Place the control so it submits the **same** settings form (so the just-entered `wsdl_url`/`soap_timeout` are included in the POST). Add a short `form-text` note (language key) clarifying that the test checks endpoint reachability and that a normal Save does not run the test.
  - [ ] 1.3 Do **not** render any result region that echoes a value — failures surface through Blesta's standard error region (the controller re-renders `getSettings($this->post)` with the gateway errors). Keep the entered values populated on re-render exactly as the existing fields already do.

- [ ] **Task 2 — Implement the transport reachability probe and wire it into `editSettings()` (AC1, AC2)** [Source: kuickpay.php:94-270; composer.json `ext-curl`; architecture.md:770]
  - [ ] 2.1 Add a `private` helper, e.g. `private function runConnectionTest(array $meta)`, that performs a **transport-level HTTPS request** to `$meta['wsdl_url']` using **cURL** (`ext-curl` is a required dependency — see Dev Notes "Latest tech information"). Do **not** use `SoapClient` and do **not** call any SOAP operation (Non-Negotiable #4). Defensively guard with `function_exists('curl_init')` and, if absent, set a language-keyed "test unavailable" error rather than fataling.
  - [ ] 2.2 Configure the request for a bounded, safe probe: resolve the timeout from `soap_timeout` with a sane floor/default — `$timeout = max(1, (int)($meta['soap_timeout'] ?? 0)); if ($timeout < 1) { $timeout = 30; }` — and set `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT` to it; `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_RETURNTRANSFER => true`, `CURLOPT_NOBODY => false` (a GET — some WSDL endpoints reject HEAD). Capture `curl_errno()` and `CURLINFO_RESPONSE_CODE`, then `curl_close()`. (See the **state-mapping table** in Dev Notes.)
  - [ ] 2.3 Map the outcome to exactly one result state and call `$this->Input->setErrors([...])` with the matching language key **only on failure**. **Success** (endpoint answered) sets **no** error, so the save proceeds. **Never** include the raw cURL error / URL / host in the message (Non-Negotiable #3). Do **not** call `$this->log()` (Non-Negotiable #2).
  - [ ] 2.4 In `editSettings()`, **after** `$this->Input->validates($meta);` (line 267) and **before** `return $meta;` (line 269): run the probe only when the sentinel is present and validation passed, then strip the sentinel. Exact diff in Dev Notes "editSettings change (AC1) — exact diff". The sentinel key must be **excluded from `$rules`** (do not add a rule for it).
  - [ ] 2.5 Confirm the probe issues **no** authenticated/credentialed request — it fetches the WSDL document only and sends **no** `voucher_*`/`inquiry_*` credentials. Therefore the transport probe cannot report a server-side **credential-failure** state; that state is **deferred** (Task 5 / Dev Notes). AC2's `Echo`/`GetInstitutionsList` preference is satisfied by making no business call at all here.

- [ ] **Task 3 — Guard the server-side `wsdl_url` fetch (SSRF / userinfo) (Non-Negotiable #6)** [Source: deferred-work.md "wsdl_url HTTPS validation is lenient"; architecture.md:83,460]
  - [ ] 3.1 Before fetching, parse `$meta['wsdl_url']` and **reject** it if it contains userinfo (`parse_url(..., PHP_URL_USER)` / `PHP_URL_PASS` non-empty) — set a language-keyed error and do not perform the request. This closes the credentials-in-URL slice flagged in `deferred-work.md`.
  - [ ] 3.2 Rely on the existing 1.2 rule that `wsdl_url` must be a valid **HTTPS** URL (kuickpay.php:136-149) — do not weaken it. Keep TLS peer + host verification on (Task 2.2).
  - [ ] 3.3 Do **not** implement a host allowlist here (real KuickPay hosts are unconfirmed until Phase 0 / Epic 5). Add a code comment + Dev Note recording that broader SSRF host-restriction is deferred to the story that confirms production endpoints. Do not add host literals.

- [ ] **Task 4 — Language strings (AC1, AC2)** [Source: language/en_us/kuickpay.php; project-context.md language rules; UX-DR28]
  - [ ] 4.1 Add the action + note keys: `Kuickpay.test_connection` (button label, e.g. `'Test connection'`) and `Kuickpay.test_connection_note` (e.g. `'Checks that the configured WSDL endpoint is reachable over HTTPS. This does not create a voucher or validate credentials, and a normal Save does not run the test.'`).
  - [ ] 4.2 Add the result-state error keys under the `!error.` namespace, worded per UX-DR28 (no SOAP/transport detail, no operation names, no stack traces), and honest that the invoice is unaffected. Suggested set:
    - `Kuickpay.!error.connection.unreachable` — e.g. `'Could not reach KuickPay at the configured endpoint. Check the WSDL URL and try again. No voucher was created and no invoice was changed.'`
    - `Kuickpay.!error.connection.timeout` — e.g. `'The connection to KuickPay timed out. Check the endpoint and the SOAP timeout setting, then try again. No voucher was created and no invoice was changed.'`
    - `Kuickpay.!error.connection.url_userinfo` — e.g. `'Remove the username and password embedded in the WSDL URL before testing the connection.'`
    - `Kuickpay.!error.connection.unavailable` — e.g. `'The connection test could not run in this environment.'` (used when cURL is unavailable).
  - [ ] 4.3 Preserve every existing key and the file's single-quote / one-key-per-line style; place new keys near the other `!error.*` keys (after line 72, by the `soap_timeout` error) or in a clearly commented "Connection test (Story 1.4)" block. Do not reorder/rewrap existing keys.

- [ ] **Task 5 — Document the deferred contracts (AC1 credential-failure, AC2 real Echo, AC3 live-voucher test)** [Source: epics.md AC1-AC3; architecture.md:770,390-393,524,583; product-owner decision 2026-06-09]
  - [ ] 5.1 Add a focused code comment on `runConnectionTest()` and a Dev Notes entry stating precisely what is deferred and why: (a) **server-side credential validation** (the AC1 "credential failure" state) requires an **authenticated safe SOAP op** (`Echo`/`GetInstitutionsList`) through the Epic-3 `KuickPaySoapClient.php`; (b) **AC2's** real preference of a safe metadata op is realized when that client exists; (c) **AC3's** live-voucher test (explicit confirmation + clearly-labeled test record) requires `InsertVoucher` (Epic 3) **and** durable Voucher persistence (Epic 2 plugin), so it is **not** built here — and the transport probe's guarantee of creating **no** voucher already enforces the "no accidental payable voucher" safety that AC3 protects.
  - [ ] 5.2 Do **not** stub a confirmation dialog or a test-label field in 1.4 (product-owner decision: defer, don't scaffold). State the forward contract so the Epic-3 story plugging in `Echo`/`InsertVoucher` knows to: prefer the safe op (AC2), surface the credential-failure state (AC1), and add the confirmed + labeled live-voucher path (AC3).
  - [ ] 5.3 Record in Dev Notes that `maskCredentials()` (1.3) is **not** consumed by 1.4 (the transport probe sends/logs no credentials and `editSettings()` cannot `log()`), so the boundary remains a **contract for Epic 3's authenticated SOAP path** — this is a correction to the 1.3 note that expected 1.4 to be its first caller. Do not remove or alter `maskCredentials()`.

- [ ] **Task 6 — Tests (AC1) — targeted, no live external calls** [Source: project-context.md#Testing Rules; NFR11 line 107 "tests must not call live KuickPay endpoints by default"]
  - [ ] 6.1 **Sentinel not persisted (AC1/Non-Negotiable #5):** call `editSettings()` with a full valid meta set plus `run_connection_test='true'` and a `wsdl_url` pointing at an unreachable/loopback host; assert the returned `$meta` has **no** `run_connection_test` key, and that the connection error is set (the probe ran). Call again **without** the sentinel and assert no error from the probe path and no network attempt.
  - [ ] 6.2 **Userinfo rejected (Non-Negotiable #6):** call the test path with `wsdl_url='https://user:pass@example.test/wsdl'` and assert the `Kuickpay.!error.connection.url_userinfo` error is set and **no** outbound request is attempted.
  - [ ] 6.3 **State mapping is reachability-only / no credentials transmitted (AC1, AC2):** assert (by code inspection or a seam) that the probe issues a GET for the WSDL with TLS verification on and sends no `voucher_*`/`inquiry_*` values, and that it never references `InsertVoucher`/`SoapClient`. A timeout against a deliberately black-holed address (e.g. `https://10.255.255.1/`) with a 1s `soap_timeout` should map to the **timeout** key, not **unreachable** — keep this assertion environment-tolerant (skip with an explicit note if the sandbox forbids any egress).
  - [ ] 6.4 **Where these tests live (no committed test files in 1.4's scope):** this checkout has **no** sibling `../tests` suite and **no** gateway-local `tests/` layout. Do **not** create a root `tests/` directory and do **not** commit a new test file (Task 7's scope gate allows only the three gateway files + this story file + `sprint-status.yaml`). If a PHP runtime is available, run the 6.1–6.3 checks from a **disposable** script under `temp/` and **delete it** before finishing. If the runtime/egress is unavailable, run the narrowest safe fallback (`php -l` + the grep proofs in Task 7) and **state explicitly** that runtime/network coverage did not run. Do not overstate; do not present lint/grep as full coverage. [Source: project-context.md#Testing Rules lines 68, 76, 123]

- [ ] **Task 7 — Verification (no overstating)** [Source: project-context.md#Development Workflow Rules]
  - [ ] 7.1 `php -l` the three touched files (`.php`, `.pdt`, language `.php`).
  - [ ] 7.2 **No logging in the edit/test path (Non-Negotiable #2):** `grep -nE "->log\(" components/gateways/nonmerchant/kuickpay/kuickpay.php` → expect **no** output (the gateway adds no `$this->log()` call in this story).
  - [ ] 7.3 **No SOAP / no lib (Non-Negotiable #4):** `grep -nE "SoapClient|new Soap|->__soapCall|InsertVoucher|Echo|GetInstitutionsList" components/gateways/nonmerchant/kuickpay/kuickpay.php` → expect **no** business-SOAP usage; `find components/gateways/nonmerchant/kuickpay -type d -name lib` → expect **no** output.
  - [ ] 7.4 **Sentinel handled (Non-Negotiable #5):** `grep -nE "run_connection_test" components/gateways/nonmerchant/kuickpay/kuickpay.php components/gateways/nonmerchant/kuickpay/views/default/settings.pdt` → confirm it is read in `editSettings`, `unset` before `return $meta`, and posted by the button; confirm it is **not** added to `$rules`.
  - [ ] 7.5 **Probe present + guarded:** `grep -nE "runConnectionTest|curl_init|CURLOPT_SSL_VERIFYPEER|PHP_URL_USER|soap_timeout" components/gateways/nonmerchant/kuickpay/kuickpay.php` → confirm the helper exists, TLS verification is on, userinfo is checked, and the timeout derives from `soap_timeout`.
  - [ ] 7.6 **Scope containment:** `git status --porcelain` shows only the three gateway files + this story file + `sprint-status.yaml`; **no** `plugins/kuickpay_reconcile/` changes, **no** new `lib/` files, **no** core edits.
  - [ ] 7.7 If a running Blesta + MySQL stack is available: open Settings → Payment Gateways → KuickPay; with a reachable HTTPS endpoint, click **Test connection** → settings save and no connectivity error shows; set `wsdl_url` to an unreachable host → **Test connection** re-renders with the language-keyed "could not reach" error and **no** `gateway_meta` change beyond the normal save; confirm **no** voucher/transaction row is created. If no runtime/DB/egress, **state that explicitly** and rely on lint + grep + the disposable-script unit checks. [Source: NFR12 line 109]

## Dev Notes

### Scope: what 1.4 owns vs what later stories own

| Surface in the ACs | Owned by 1.4? | Where it actually lands |
|---|---|---|
| **Transport reachability test** (reachable / endpoint-unavailable / timeout) | ✅ Yes | cURL HTTPS probe of `wsdl_url` in `runConnectionTest()` (Task 2) |
| **"No payable Voucher / no invoice marked paid" during test** | ✅ Yes (guaranteed) | Probe makes no SOAP/business call and writes nothing (Non-Negotiable #1) |
| **"Prefer safe op over `InsertVoucher`" (AC2)** | ✅ Satisfied trivially | Probe calls no business op at all; real `Echo`/`GetInstitutionsList` preference lands with the Epic-3 client |
| **Server-side credential-failure state (AC1)** | ❌ No | **Epic 3 / Story 3.1** — needs an authenticated safe SOAP op via `KuickPaySoapClient.php` [architecture.md:770,390-393] |
| **Real `Echo`/`GetInstitutionsList` SOAP call (AC2)** | ❌ No | **Epic 3 / Story 3.1** (SOAP client wrapper) |
| **Live-voucher test: explicit confirmation + labeled test record (AC3)** | ❌ No (deferred per product owner) | **Epic 3** (`InsertVoucher`) + **Epic 2** (durable Voucher persistence + test label) |
| **Gateway-log persistence of the test result** | ❌ No | Infeasible in `editSettings()` (null `gateway_id` → `log()` throws); a logged, on-demand test belongs to a story that runs with a real `gateway_id` (Epic 3/4) |
| **SOAP/XML diagnostics redactor** | ❌ No | **Epic 3 / Story 3.2** (`redactor` protocol class) [architecture.md:778] |

**Product-owner decision (2026-06-09):** ship the safe transport test now (keep Track-A parallelism; partially satisfy AC1, fully satisfy the safety ACs); defer AC3 to Epic 3 and document the contract rather than scaffolding inert UI.

### Why a transport probe, not a SOAP call

AC1 says "the gateway contacts KuickPay" and AC2 names `Echo`/`GetInstitutionsList`. But `architecture.md:770` mandates that **external KuickPay SOAP calls live only in `KuickPaySoapClient.php`**, a protocol-library class owned by **Epic 3 / Story 3.1**, and 1.4 (Track A, before Epic 3) may **not** add `lib/`. So a live SOAP `Echo` in 1.4 would either inline a raw `SoapClient` (violating `:770`) or pull the Epic-3 client forward (scope creep). The in-scope resolution: a **transport-level HTTPS reachability request** for the WSDL document is **not a SOAP operation call** — it honors `:770`, needs no `lib/`, needs no `ext-soap`, and still "contacts KuickPay" enough to report **reachable / endpoint-unavailable / timeout**. The credentialed states (credential-failure) and the real safe-op preference upgrade cleanly when the Epic-3 client exists. [Source: architecture.md:770, 390-393, 765-778; epics.md AC1-AC2; deferred-work.md WSDL-fetch ownership]

### Connection-test mechanism — the only viable Blesta hook

A Blesta **nonmerchant gateway** exposes exactly two admin interaction points: `getSettings()` (GET render) and `editSettings()` (POST save). There is **no** built-in `testConnection`/`validateConnection`/tab/action hook (confirmed across `components/gateways/lib/gateway.php`, `nonmerchant_gateway.php`, and `app/controllers/admin_company_gateways.php` — `manage()` is the only settings handler, and on POST it calls `GatewayManager::edit()` → `editSettings()`; there is no route that dispatches to an arbitrary gateway method). Therefore the test must run **inside `editSettings()`**, triggered by a **sentinel** posted from a dedicated "Test connection" submit button so that **normal saves never incur a network call**. The only feedback channel from `editSettings()` is `Input->setErrors()` (which blocks that submission). [Source: components/gateways/lib/nonmerchant_gateway.php; app/controllers/admin_company_gateways.php:162-293; app/models/gateway_manager.php:599-650]

**Honest UX consequence (document, don't fight):** because the only channel is `setErrors()`, the test cleanly reports **failures** (form re-renders with the language-keyed reason; save blocked so the admin fixes config) and reports **success implicitly** (no error → settings save → standard "Gateway updated"). There is **no** clean "Connection OK ✓" banner from within `editSettings()`. A richer, on-demand test with an explicit success affordance and a logged result requires a dedicated admin route (a real `gateway_id` context) and is **out of scope** for this gateway-only Track-A story — see Open Question #1.

### editSettings change (AC1) — exact diff

Insert between the existing `validates()` (line 267) and `return $meta;` (line 269). Do **not** add `run_connection_test` to `$rules`.

```php
        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        // AC1: run the safe transport connection test only when the admin explicitly
        // requests it and base validation passed. Never on a normal Save. The probe
        // reports via Input->setErrors() only; it must NOT call $this->log() here
        // (gateway_id is null during editSettings(), so log() would throw), and it
        // creates no Voucher and calls no SOAP operation.
        if (!$this->Input->errors() && (($meta['run_connection_test'] ?? 'false') === 'true')) {
            $this->runConnectionTest($meta);
        }

        // The action flag is transient — never persist it to gateway_meta.
        unset($meta['run_connection_test']);

        return $meta;
```

`runConnectionTest(array $meta)` is a new `private` method (place it near `maskCredentials()`): validate-no-userinfo → cURL GET of `wsdl_url` with `soap_timeout`-derived timeouts and TLS verification on → map `curl_errno`/response code to a state → `setErrors` the matching language key on failure (nothing on success). It returns `void`. (`unset()` on an absent `run_connection_test` is a safe no-op; no `isset()` guard needed.)

### State-mapping table (cURL → AC1 state → language key)

| cURL signal | AC1 state | Behavior |
|---|---|---|
| `curl_errno() === 0` and `CURLINFO_RESPONSE_CODE > 0` (endpoint answered, any HTTP status) | **success** | No error set; settings save proceeds |
| `curl_errno() === CURLE_OPERATION_TIMEDOUT` (28) | **timeout** | `setErrors(Kuickpay.!error.connection.timeout)` |
| `curl_errno()` ∈ { `CURLE_COULDNT_CONNECT` (7), `CURLE_COULDNT_RESOLVE_HOST` (6), `CURLE_SSL_*` (35/51/58/60…), other transport errnos } | **endpoint unavailable** | `setErrors(Kuickpay.!error.connection.unreachable)` |
| `wsdl_url` contains userinfo | **blocked (pre-request)** | `setErrors(Kuickpay.!error.connection.url_userinfo)`; no request issued |
| `curl_init`/`ext-curl` missing | **unavailable** | `setErrors(Kuickpay.!error.connection.unavailable)` |
| **credential failure** (server rejects creds) | **N/A in 1.4** | Requires an authenticated safe SOAP op — **deferred to Epic 3** (Task 5) |

Notes: classify timeout **before** generic transport errors. "Reachable" deliberately means *the endpoint answered* — a 401/404/500 still proves connectivity; deeper validation (valid WSDL, valid credentials) needs the Epic-3 SOAP client. Never surface the HTTP code or cURL message to the admin (Non-Negotiable #3).

### Why `maskCredentials()` is not consumed here

Story 1.3 added `maskCredentials()` anticipating that "1.4's connection-test logging" would be its first caller. After tracing the edit path, that does **not** hold: (1) `editSettings()` has a **null `gateway_id`**, so `$this->log()` would throw (Non-Negotiable #2) — 1.4 logs nothing; and (2) the transport probe sends **no** credentials (it fetches the WSDL document only), so there is nothing credential-bearing to mask. `maskCredentials()` therefore remains a **correct, ready contract for Epic 3's authenticated SOAP request/response path** (where a real `gateway_id` exists and credentials are transmitted). 1.4 must **not** remove, weaken, or call it. The `maskCredentials()` input-fragility noted in `deferred-work.md` (objects/null/bool) is likewise **not** triggered by 1.4 and stays an Epic-3 precondition. [Source: 1-3 story Task 4.3 / Dev Notes "Why establish maskCredentials() now"; deferred-work.md]

### Files being modified — current state and what to preserve

All three files are **UPDATE** (none new). Read each in full before editing.

`components/gateways/nonmerchant/kuickpay/kuickpay.php`:
- `__construct`, `setCurrency`, `getSettings`, `encryptableFields`, `maskCredentials`, `$credential_mask_fields`, `setMeta`, `buildProcess`, `validate`, `success`, `companionInstalled` — **DO NOT change behavior** (load any extra component you need in `__construct`/the helper without disturbing the existing `Input` load).
- `editSettings()` (94-270) — **extend** only at the tail (Task 2.4): add the sentinel-gated probe call + `unset` after `validates()`. Keep all 1.2/1.3 defaulting, normalization, trim, `$same` dedupe, `$rules`, and `setRules/validates` intact.
- **Add** `runConnectionTest()` (`private`) (Task 2-3).

`views/default/settings.pdt`: keep the `!$companion_installed` danger branch and the 1.1–1.3 grouped fields untouched. **Add only** the "Test connection" submit control + note inside the gated block (before line 249). Do **not** add a `<form>` tag. Do not touch `process.pdt`.

`language/en_us/kuickpay.php`: **add** the `test_connection`, `test_connection_note`, and `!error.connection.*` keys. Preserve all other keys, ordering, and quoting.

### Previous story intelligence (1.1 + 1.2 + 1.3)

- **1.1 (done):** gateway+companion scaffold, companion-missing guard (`companionInstalled()` + `Kuickpay.!error.companion_missing`), PKR-only `config.json`, fail-closed `buildProcess/validate/success`, secret-safety posture. Legacy global `Kuickpay extends NonmerchantGateway` (no namespace, no strict_types) — match it.
- **1.2 (done):** grouped settings form, `editSettings()` validation incl. the `wsdl_url` **HTTPS** rule (136-149) and `soap_timeout` numeric rule (194-200), whitespace-trim, `/D`-anchored regexes, and the language keys. **Two 1.2 deferrals 1.4 inherits:** (a) `wsdl_url` SSRF/userinfo leniency — 1.4 closes the **userinfo** slice as the first fetch consumer (Task 3); host-allowlist stays deferred; (b) `soap_timeout=0` footgun — 1.4's probe applies a floor/default (Task 2.2). [Source: deferred-work.md "Deferred from: code review of 1-2…"]
- **1.3 (done — merged):** same-as-voucher dedupe (`unset` 125-130), masked password indicators, `encryptableFields()` lock, and the **`maskCredentials()`** redaction boundary (291-294). 1.4 preserves all of it and corrects the expectation that 1.4 would be `maskCredentials()`'s first caller (see "Why `maskCredentials()` is not consumed here"). 1.3's code review deferred items (mask case-sensitivity, object/scalar handling) remain Epic-3 concerns and are untouched here. [Source: 1-3 story Review Findings; deferred-work.md "Deferred from: code review of 1-3…"]

### Git intelligence

Recent merged work: `feat(kuickpay): add credential redaction boundary`, `feat(kuickpay): show masked credential indicators`, `feat(kuickpay): avoid duplicate inquiry credentials` (1.3), preceded by the 1.2 form/validation commits. 1.4 builds directly on this. Follow the repo convention `feat(kuickpay): …` / `fix(kuickpay): …`, imperative, ≤72 chars. Suggested commits: `feat(kuickpay): add safe connection test action`, `feat(kuickpay): probe wsdl endpoint reachability`, `fix(kuickpay): reject userinfo in tested wsdl url`. [Source: project-context.md#Development Workflow Rules]

### Latest tech information

No new libraries. This story uses only: PHP 8.2; Blesta's `NonmerchantGateway`/`Gateway`/`Input`/`Form`/`Html`/`Language` APIs; and **`ext-curl`** for the transport probe. **`ext-curl` is a required dependency** (`composer.json` `require` lists `ext-curl`), so cURL is available without a new package — still guard with `function_exists('curl_init')` for defensive failure. Deliberately **do not** use `ext-soap`/`SoapClient`: it is **not** declared in `composer.json` (so it may be absent in production), and a SOAP call would violate `architecture.md:770` and the Track-A `lib/` boundary. No web research required — all contracts are in-repo and verified. Do not add packages. [Source: project-context.md#Technology Stack; composer.json `ext-curl`, `ext-openssl`; architecture.md:770]

### Project Structure Notes

- All edits stay inside `components/gateways/nonmerchant/kuickpay/`. **No new directories** — in particular **no `lib/`** (Epic 3 will legitimately add `kuickpay/lib/` for `KuickPaySoapClient.php` + the `redactor`; it is forbidden *here*). [Source: architecture.md:765-778; epics.md SOAP-op rule line 130-131]
- Files touched (all UPDATE): `kuickpay.php`, `views/default/settings.pdt`, `language/en_us/kuickpay.php`. `config.json` (no meta schema lives there), `composer.json`, `process.pdt`, and all `plugins/kuickpay_reconcile/` files unchanged.
- Architecture ownership boundary respected: the gateway owns "settings UI + encrypted gateway meta + connection check"; the **SOAP client, parser, evidence, and redactor** are the protocol library (Epic 3) and must not be created here. [Source: architecture.md:776-778, 770]

### References

- [Source: epics.md#Story 1.4, lines 373-393] — user story + AC1/AC2/AC3 (verbatim above).
- [Source: epics.md FR4 line 31; UX-DR10 line 170; UX-DR7 line 164; UX-DR28 line 206; NFR11 line 107; NFR14 line 113; sequencing line 271] — connectivity/credential-shape test; safe-test + live-test labeling; no raw diagnostics; tests not live by default; admin mutations POST/CSRF/ACL; Epic 1 parallel with Phase 0.
- [Source: prd.md FR-4 lines 117-124; FR-15 lines 236-243] — test reports success/credential-failure/timeout/unavailable, marks no invoice paid, live test = explicit intent + identifiable test record; SOAP client provides ops incl. optional setup/connectivity.
- [Source: addendum.md A.1 lines 7-20; A.2 lines 22-44] — `Echo`/`GetInstitutionsList` are "Optional or future… if credentials permit"; `InsertVoucher` request/credential field names (`userName`/`password`/`InstitutionID`/…); no `Echo`/`GetInstitutionsList` signatures specified.
- [Source: architecture.md:770, 390-393, 397, 460, 569-578, 765-778, 83] — SOAP calls live only in `KuickPaySoapClient.php` (Epic 3); optional safe setup ops; SOAP→redactor→parser; no hard-coded URLs/credentials; error-class taxonomy (timeout/transport_error/credential_error/…); gateway-vs-protocol ownership.
- [Source: components/gateways/lib/gateway.php:254-288 (log), :307-363 (maskData/maskDataRecursive)] — `log()` requires `gateway_id` and throws on log errors; base masking primitives.
- [Source: app/models/logs.php:132-176] — `addGateway` requires a valid `gateway_id` (`validateExists`, no `if_set`).
- [Source: app/models/gateway_manager.php:599-650 (edit), :883-892 (loadGateway)] — `edit()` runs `editSettings()` on a fresh `loadGateway()` instance with **no** `setGatewayId()`, so `gateway_id` is null during edit.
- [Source: app/controllers/admin_company_gateways.php:162-293] — `manage()` is the only settings handler; GET → `getSettings($vars)`, POST → `GatewayManager::edit(['meta' => $this->post])`; no gateway-action route exists.
- [Source: components/gateways/nonmerchant/kuickpay/{kuickpay.php, views/default/settings.pdt, language/en_us/kuickpay.php, config.json}] — the files this story modifies and their current as-built state.
- [Source: deferred-work.md] — 1.2 `wsdl_url` SSRF/userinfo + `soap_timeout=0` deferrals (1.4 closes the userinfo slice and floors the timeout); 1.3 mask-input fragility (Epic-3 precondition, untriggered here).
- [Source: ux EXPERIENCE.md lines 30,48,53,68,95; DESIGN.md lines 122,133-137,169] — Safe Connection Test reached from Settings; tone "could not reach KuickPay… invoice has not been marked paid"; no SOAP detail in copy; live-voucher test needs explicit confirmation + labeling; no success styling for unconfirmed states.
- [Source: project-context.md] — PHP 8.2; legacy global class style; Loader/Input/Language conventions; secret-safety; no core edits; commit convention; testing-honesty rule; `ext-curl` available, no new packages.
- [Source: sprint-status.yaml#BUILD ORDER] — Track A sequencing (1-1→1-2→1-3→1-4→1-5); Epic 1 parallel with Phase 0; SOAP client is Epic 3.

## Dev Agent Record

### Agent Model Used

_(to be filled by the dev agent)_

### Debug Log References

### Completion Notes List

### File List

## Change Log

- 2026-06-09: Story drafted (ready-for-dev) via bmad-create-story. Exhaustive context-engine analysis across epics (Story 1.4 ACs, FR4/FR-4/FR-15, UX-DR10/UX-DR28/NFR11/NFR14), PRD + addendum (safe SOAP ops, InsertVoucher field map), architecture (single redaction boundary, gateway-vs-protocol ownership, `architecture.md:770` SOAP-only-in-wrapper, optional safe ops), UX DESIGN/EXPERIENCE (safe-test surface + tone), the predecessor 1.1–1.3 stories, and verified Blesta internals (`gateway_manager::edit/loadGateway`, `admin_company_gateways::manage`, base `Gateway::log/maskData`, `Logs::addGateway` gateway_id requirement). Resolved load-bearing design points against the codebase: (1) nonmerchant gateways have **no test hook** — the only viable trigger is a **sentinel-gated probe inside `editSettings()`**; (2) `$this->log()` **cannot** run in `editSettings()` (null `gateway_id` → throws), so the test reports via `Input->setErrors()` only and `maskCredentials()` is **not** consumed here (correcting the 1.3 expectation); (3) a live SOAP `Echo` would violate `architecture.md:770`/Track-A `lib/` boundary, so 1.4 ships a **transport-level HTTPS reachability probe** (cURL, `ext-curl`) that reports reachable/unavailable/timeout, creates no voucher, and calls no SOAP op. **Product-owner decisions (2026-06-09):** ship the safe transport test now (keep Track-A parallelism); defer AC3's live-voucher test to Epic 3 with the contract documented (no inert scaffold). Server-side credential-failure (AC1) and the real `Echo`/`GetInstitutionsList` preference (AC2) are deferred to the Epic-3 SOAP client. 1.4 also closes the 1.2 `wsdl_url` **userinfo** SSRF slice and floors `soap_timeout`.

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **On-demand test with an explicit success affordance + logged result.** Within the gateway-only, no-core-change constraint, the test runs inside `editSettings()` and can only report **failures** (via `Input->setErrors()`); a successful test is implicit (settings save), and it **cannot** write a gateway log entry (null `gateway_id` during edit). A richer affordance (explicit "Connection OK ✓", a logged result, re-test without re-saving) needs a dedicated admin route where a real `gateway_id` exists. **Recommended:** accept the save-time failure-reporting MVP now; build the on-demand logged test when admin tooling lands (Epic 4) or alongside the Epic-3 SOAP client. Confirm, or request the richer route now (a small controller addition — out of this gateway-only scope).
2. **Server-side credential validation (AC1 "credential failure").** A transport probe cannot authenticate, so it cannot report credential rejection. **Recommended:** deliver this when the Epic-3 `KuickPaySoapClient.php` adds an authenticated safe op (`Echo`/`GetInstitutionsList`); that story prefers the safe op (AC2) and surfaces the credential-failure state. Confirm the deferral, and confirm with KuickPay (Phase 0) the exact safe-op name + signature so Epic 3 can wire it.
3. **AC3 live-voucher test.** Deferred to Epic 3 per product-owner decision (needs `InsertVoucher` + durable Voucher persistence + a test-label field). 1.4 guarantees no voucher can be created. Confirm the test-record label mechanism (an `is_test` flag on `kuickpay_vouchers`?) when Epic 2/3 define the schema, so the labeled-test path satisfies AC3 cleanly.
4. **Which credential pair a future authenticated test exercises.** Settings carry separate voucher vs. inquiry credentials (with the same-as-voucher toggle). When Epic 3 adds the authenticated safe-op test, confirm whether it validates the voucher pair, the inquiry pair, or both. Out of scope for 1.4's transport probe (which sends no credentials).
