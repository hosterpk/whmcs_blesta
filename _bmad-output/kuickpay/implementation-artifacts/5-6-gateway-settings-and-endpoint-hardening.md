---
baseline_commit: f8a6be40affda5e0a5c8390532e0a524991f069e
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.6: Gateway Settings and Endpoint Hardening

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator,
I want the gateway settings and outbound endpoint surface hardened,
so that misconfiguration and SSRF cannot reach an unsafe request.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.6 (lines 979–999). **Closes** (from `deferred-work.md`): `wsdl_url`
> SSRF/userinfo (`:46`), host-allowlist + IPv6-only-DNS gap + 4xx/5xx/redirect-as-reachable (`:57`–`:58`),
> numeric bounds + `expiry >= due` relation (`:47`), the missing-logo / broken admin extension-card image
> (`:41`, Epic 1→4 retro logo item). NFR8 (`epics.md:101` — "Raw Diagnostic Summary must be admin-only and
> must not expose secrets"; here: no credentials-in-URL, no SSRF egress). Architecture: ownership boundary
> (gateway owns settings UI + encrypted meta + the only SOAP-call site, `architecture.md:331,520-522,770,776`);
> Anti-Patterns "no hard-coded production credentials / institution IDs / URLs" (`architecture.md:83,657`;
> NFR10 `epics.md:105`).

**⚠️ SCOPE REALITY CHECK — read before coding.** This is a **gateway-only hardening** story. Five traps:

1. **The private-range/DNS-rebinding SSRF guard ALREADY EXISTS.** It was implemented 2026-06-09
   (`deferred-work.md:57`) in `runConnectionTest()`: host resolution via `resolveProbeAddresses()`,
   `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` rejection, unresolvable-host block,
   and `CURLOPT_RESOLVE` pinning. **Do NOT re-implement it.** This story adds only (a) the confirmed-host
   **allowlist**, (b) the **IPv6-only-DNS** close, and promotes the userinfo+host checks to **save time**.
2. **`deferred-work.md` line anchors are PRE-5.5 and have drifted.** It cites `kuickpay.php:106-119`
   (wsdl_url), `:102,167-181` (numeric), `:353` (reachability). The **current** anchors (HEAD `f8a6be40`)
   are: `wsdl_url` rule **`:210-224`**, numeric rules **`:268-288`** (shared rule defined `:206`), connection
   test **`:502-602`**, reachability check **`:584`**, `resolveProbeAddresses()` **`:642-647`**. Use the
   current anchors; do not chase the stale ones.
3. **DO NOT INVENT A KUICKPAY HOST LITERAL.** The only in-repo host, `app.kuickpay.com`, is explicitly marked
   **"example only, not a production default"** (`0-1-confirm-kuickpay-contract…:60,146`; `3-1-wrap-kuickpay-soap-operations:131`).
   The real production WSDL host is **operator-configured, never committed** (`phase-0-contract.md:43-46`;
   NFR10). The allowlist is therefore **operator-supplied** (a new optional gateway setting; **DECIDED:
   permissive when the allowlist is empty** — see Dev Notes "Allowlist mechanism"). Hard-coding
   `app.kuickpay.com` is a spec violation (`architecture.md:83,657`).
4. **The save rule is the real SSRF chokepoint, not the connection test.** The stored `wsdl_url` is later
   fetched **server-side, unguarded**, by `getSoapClient()` (`kuickpay.php:678-679`) and by the plugin cron
   (`KuickPayReconcileService::gatewayConfigForCompany()`, `deferred-work.md:85`). The cron-side validator
   `KuickPaySoapClient::hasUsableWsdlUrl()` (`lib/KuickPaySoapClient.php:359-374`) rejects userinfo + non-https
   but has **no** private-range/allowlist guard. So a private/non-allowlisted host that passes the lenient
   save rule today is fetched by cron with no SSRF protection. **AC1's value comes from gating at save**, so a
   bad value can never be persisted.
5. **`reachability honestly per the documented contract` is mostly DOCUMENT + TEST, not behavior change.**
   The current "errno==0 && response_code>0 ⇒ reachable" (incl. 4xx/5xx/redirect) is a **deliberate
   pure-reachability design** (Open Question #5, `deferred-work.md:58`) — an auth-protected WSDL legitimately
   returns 401/403, and deeper endpoint validity is the credentialed safe-op path (Story 5.1, now **done**).
   AC3 wants that contract **written down** and the previously-untested branches (redirect/4xx/5xx-as-reachable;
   `connection.unavailable` curl-missing) **covered honestly** — **DECIDED: keep pure-reachability** (not a
   flip to "4xx = failure"; document + test only).

**No schema change and no version bump are expected** (gateway meta is schema-less key/value; the new
optional allowlist setting defaults safe; the logo is a static asset). Leave the gateway `config.json` at
`1.0.0`. No plugin changes. **Single runtime file** plus a logo asset, language keys, the settings view, and
tests.

---

1. **(AC1 — `wsdl_url` save-time SSRF/userinfo/allowlist hardening + IPv6-only-DNS close)**
   **Given** the `wsdl_url` gateway setting,
   **When** it is saved (`editSettings()`),
   **Then** the save-time validation **rejects embedded userinfo** (`https://user:pass@host/wsdl` →
   `parse_url(PHP_URL_USER)`/`PHP_URL_PASS` non-null is rejected) **before the value is persisted**, closing
   the credentials-in-URL surface at the chokepoint (today the `wsdl_url.format` rule, `:217-221`, only checks
   `FILTER_VALIDATE_URL` + `https` and **accepts userinfo**).
   **And** the saved host is **validated against the operator-configurable confirmed-endpoint allowlist** (a
   new optional `wsdl_allowed_hosts` gateway setting — **no invented host literals**); when the allowlist is
   populated the host must match (case-insensitive, exact host, userinfo stripped); when it is **empty** the
   value still must pass the public-host SSRF checks below (**DECIDED: default-permissive** — any public HTTPS
   host that passes the SSRF checks may be saved; the operator pins exact hosts later).
   **And** the save rule also applies the **public-host SSRF checks** already in `runConnectionTest()`
   (`:536-556`) — private/loopback/link-local/reserved addresses are rejected at **save time** — so the stored
   value can never steer the unguarded `getSoapClient()` / cron `gatewayConfigForCompany()` SOAP fetch at an
   internal service or `169.254.169.254`.
   **And** the connection-test SSRF guard is **extended to the IPv6-only-DNS gap** (`deferred-work.md:57`):
   `resolveProbeAddresses()` (`:642-647`, today `gethostbynamel()` = **IPv4-only**) also resolves **AAAA**
   records, every resolved IPv4 **and** IPv6 address is validated with
   `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (works for both families), and all validated
   addresses are pinned via `CURLOPT_RESOLVE` (`:558-564`). A legit IPv6-only KuickPay host becomes testable;
   a host whose AAAA points at `::1`/`fc00::/7`/`fe80::/10` is explicitly **blocked** (today it silently
   resolves to `[]` → blocked-by-accident, which also breaks legit IPv6 endpoints).
   **And** the userinfo + https + host checks are factored into a **single shared validator** reused by the
   save rule, the probe (`:514-525`), and (at minimum cross-referenced with) the cron-side
   `hasUsableWsdlUrl()` so the three copies cannot drift (the 5.5 "keep both copies byte-for-byte" discipline,
   memory `[[kuickpay-voucher-reference-dual-test-suites]]`).
   **HEAD STATE:** `wsdl_url.format` save rule (`:210-224`) is lenient (accepts userinfo + any public/private
   host); `runConnectionTest()` (`:502-602`) is strict but **only runs on the Test-connection button**;
   `resolveProbeAddresses()` is IPv4-only; no allowlist exists anywhere.

2. **(AC2 — numeric settings bounds + cross-field relation)**
   **Given** the numeric settings `soap_timeout`, `due_date_offset_days`, `expiry_date_offset_days`,
   **When** they are saved,
   **Then** `soap_timeout` (when set) must be an **integer > 0** — `'0'` is rejected (today it passes: the
   shared `$optionalNumericRule = ['matches', '/^([0-9]+)?$/D']` at `:206` matches `0`), the
   instant/no-timeout footgun is closed.
   **And** **leading-zero** values (`'007'`, `'00'`) are rejected and **large values are bounded** for all
   three numeric fields (today `/^([0-9]+)?$/D` accepts leading zeros and arbitrarily large values) — apply a
   documented maximum (**adopted defaults**: `soap_timeout ∈ [1, 300]` seconds; offsets `∈ [0, 365]` days).
   **And** the cross-field relation **`expiry_date_offset_days >= due_date_offset_days`** is enforced at save
   time, so the KuickPay `ExpiryDate` (`kuickpay.php:896` → voucher payload) can never fall before its
   `DueDate` (`:895`). This is a **new cross-field rule** — no relation infrastructure exists in `editSettings`
   today; the shared `$optionalNumericRule` must be **split** since the three fields now diverge
   (`soap_timeout`: positive; offsets: allow-zero + relation).
   **And** the optional-field semantics are preserved: an **unset/empty** numeric field still passes (`if_set`)
   and falls through to its existing runtime default — only an explicit out-of-range value is rejected.
   **HEAD STATE:** one shared `$optionalNumericRule` (`:206`) applied to all three fields (`:268-288`), each
   emitting only a single `.numeric` message; `'0'`, `'007'`, huge values, and an `expiry < due` pairing all
   pass.

3. **(AC3 — honest reachability contract + real gateway logo)**
   **Given** the connection test and the admin extension card,
   **When** the endpoint returns 4xx/5xx/redirect, or the admin extension card renders,
   **Then** the **reachability contract is documented** (code docblock on `runConnectionTest()` /
   `executeConnectionProbe()` + a sanitized handoff note): *reachable* ≔ `errno == 0 && response_code > 0`
   (the host completed TLS and returned **any** HTTP status — including 401/403 on an auth-gated WSDL and
   3xx with `FOLLOWLOCATION=false`); *unreachable* ≔ `errno != 0` **or** `response_code == 0`;
   *timeout* ≔ `errno == CURLE_OPERATION_TIMEDOUT`; *unavailable* ≔ cURL absent. Deeper endpoint validity is
   intentionally the credentialed safe-op test (Story 5.1, done), **not** this probe.
   **And** the contract is **reported honestly** — the previously-untested branches are now covered so the
   documented behavior is verified, not silently assumed: a **redirect/4xx/5xx response is asserted as
   "reachable"** (no error set) and the **`connection.unavailable`** (curl-missing, `:504-511`) branch is
   asserted (both untested today, `deferred-work.md:58,60`). **DECIDED: keep pure-reachability** — do **not**
   change it to "4xx = failure" (document + test only).
   **And** a real **`logo.png`** is shown rather than a broken image: create
   `components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` (the default path returned by the
   shared base `Gateway::getLogo()`, `components/gateways/lib/gateway.php:178-184`; kuickpay's `config.json`
   has no `logo` key so the default applies). The image must be a **real raster PNG** (transparent 8-bit RGBA,
   ~150px wide; `150×69` is the most common existing-gateway shape), generated with `convert`
   (`/usr/bin/convert`) or PHP GD (`imagecreatetruecolor` + `imagepng`, both present on the 8.3 runtime) — an
   LLM cannot hand-type binary. Blesta core `GatewayManager::getGatewayInfo()`
   (`app/models/gateway_manager.php:917-919`) emits the `<img>` URL unconditionally, so the absent file is what
   makes the admin extension card show a broken image today.
   **HEAD STATE:** `runConnectionTest()` returns success for any `errno==0 && response_code>0` (`:584`) with
   no written contract and no redirect/4xx/5xx/unavailable test; `views/default/images/` does **not exist** and
   `logo.png` is absent.

## Tasks / Subtasks

- [x] **Task 1 — AC1: extract a shared `wsdl_url` safety validator; harden the save rule**
  - [x] 1.1 Add a small **pure** helper (e.g. `protected function wsdlUrlSafety(string $url, array $allowedHosts): array` returning a structured pass/fail + reason token, or a set of focused predicates `rejectsUserinfo()`, `schemeIsHttps()`, `hostAllowed()`) on the gateway. Model the **pure, unit-testable** seam on Story 5.5's `KuickPayAclDecision` (extracted decision class, `plugins/kuickpay_reconcile/lib/KuickPayAclDecision.php`) so the rule logic is testable **without** the real Blesta `Input` (the connection-probe `Input` fake's `validates()` is a no-op and cannot exercise field rules — see Dev Notes "Testing").
  - [x] 1.2 Replace the lenient `wsdl_url.format` closure (`:217-221`) so it ALSO **rejects userinfo** (`parse_url PHP_URL_USER`/`PHP_URL_PASS` non-null) and **rejects private/loopback/link-local/reserved hosts** at save (reuse the probe's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` logic, factored out so save + probe share it). Keep the existing `https`-only + `FILTER_VALIDATE_URL` + non-empty checks.
  - [x] 1.3 Add the **operator-configurable allowlist**: a new optional `wsdl_allowed_hosts` gateway setting (comma/newline-separated host list; **defaults empty — invent NO host literal**). Add its `editSettings` default + validation, a `settings.pdt` field under the endpoint group, and a language label/note. When populated, the `wsdl_url` host must match an allowlist entry (case-insensitive exact host); when empty, fall back to the AC1 public-host SSRF checks (**DECIDED: default-permissive**).
  - [x] 1.4 Close the **IPv6-only-DNS gap** in `resolveProbeAddresses()` (`:642-647`): in addition to `gethostbynamel()` (IPv4/A), resolve **AAAA** records (e.g. `dns_get_record($host, DNS_AAAA)`), returning the combined IPv4 + IPv6 address set. The existing `runConnectionTest()` validation loop (`:543-548`) already uses `FILTER_VALIDATE_IP` with the private/reserved flags, which validate IPv6 too — confirm it rejects `::1`, `fc00::/7`, `fe80::/10`, and `::ffff:10.0.0.0/104` (IPv4-mapped). Bracket IPv6 hosts correctly for `CURLOPT_RESOLVE` (`host:port:ipv6` — the host is already `[]`-trimmed at `:537`).
  - [x] 1.5 Cross-reference / align the cron-side `KuickPaySoapClient::hasUsableWsdlUrl()` (`lib/KuickPaySoapClient.php:359-374`) with the new shared userinfo/https logic so the three `wsdl_url` validators (save rule, probe, SOAP client) cannot drift. (The SOAP client need not gain the allowlist/private-range guard — the save chokepoint prevents a bad value from ever reaching it — but note this explicitly in Dev Agent Record so the boundary is intentional, not an omission.)

- [x] **Task 2 — AC2: split numeric rules, add bounds + leading-zero rejection + the `expiry >= due` relation**
  - [x] 2.1 Split the shared `$optionalNumericRule` (`:206`). `soap_timeout`: **positive, no leading zeros** (e.g. `['matches', '/^[1-9][0-9]*$/D']`, `if_set`) + an explicit upper bound (recommend `<= 300`). Offsets: **allow `0`, no leading zeros** (e.g. `/^(0|[1-9][0-9]*)$/D`) + an upper bound (recommend `<= 365`). Add the bound either via a custom closure or a paired numeric `<=` rule. Keep all three `if_set` so unset/empty still passes.
  - [x] 2.2 Add the cross-field relation **`expiry_date_offset_days >= due_date_offset_days`**. Blesta field rules are per-field; implement the relation as a closure rule on `expiry_date_offset_days` that reads the sibling value from the `$meta` under validation (the validated array is in scope in `editSettings`), or as a post-`validates()` explicit `Input->setErrors()` check mirroring how `runConnectionTest` sets `connection.*` errors. Only compare when **both** are set/non-empty.
  - [x] 2.3 Add language keys for the new messages (see Dev Notes "Language keys"): e.g. `Kuickpay.!error.soap_timeout.positive`, `.range`; `Kuickpay.!error.due_date_offset_days.range`; `Kuickpay.!error.expiry_date_offset_days.range`; `Kuickpay.!error.expiry_date_offset_days.before_due`. Match the existing block style at `language/en_us/kuickpay.php:115-148` (single `en_us` locale — no parallel dir).

- [x] **Task 3 — AC3a: document + honestly cover the reachability contract**
  - [x] 3.1 Write the reachability contract into the `runConnectionTest()` / `executeConnectionProbe()` docblocks (`:493-501`, `:604-614`): the four outcomes (reachable / unreachable / timeout / unavailable) and the explicit statement that 4xx/5xx/3xx count as **reachable** by design (auth-gated WSDL → 401/403; `FOLLOWLOCATION=false` → 3xx code returned). Echo it in the sanitized handoff/verification doc (Task 6).
  - [x] 3.2 Add the missing honest tests (no production-behavior change — pure-reachability retained): a **redirect (301/302) and a 4xx and a 5xx response asserted as reachable** (no error set), and the **`connection.unavailable`** branch (stub `curl_init` absence is hard — instead test the early-return guard via the existing seam; if the curl-missing branch genuinely cannot be reached under the harness, document that honestly per NFR12 rather than faking coverage).

- [x] **Task 4 — AC3b: ship a real gateway logo**
  - [x] 4.1 Create `components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` — a real transparent 8-bit RGBA PNG, ~150px wide (target `150×69`), a few KB, generated with `/usr/bin/convert` or a PHP GD script (do **not** commit an SVG renamed `.png`; verify with `file logo.png` / `identify logo.png` that it is a valid PNG of the right dimensions). Keep branding simple (a plain "KuickPay" wordmark/placeholder is acceptable per `deferred-work.md:41`). Do not add a `logo` key to `config.json` — the default `getLogo()` path resolves it.

- [x] **Task 5 — Tests (AC1/AC2/AC3)**
  - [x] 5.1 New `tests/KuickPaySettingsValidationTest.php` (there is **no** settings-validation coverage today). Follow `KuickPayConnectionProbeTest`'s **inline-stub** pattern (stub `NonmerchantGateway` + identity `Language::_`; `tests/bootstrap.php` provides none of these). Drive the **pure validator(s)** from Task 1.1 / the numeric+relation predicates directly — OR, if you load the real Blesta `Input` (as `KuickPayCurrencyWiringTest` loads the real `Gateway` base), exercise `editSettings()` end-to-end. Assert: userinfo rejected at save; private/loopback/metadata host rejected at save; allowlist match/non-match (populated) and empty-allowlist fallback; `soap_timeout` `'0'`/`'007'`/over-max rejected, valid passes; offsets leading-zero/over-max rejected, `0` allowed; `expiry < due` rejected, `expiry == due` and `expiry > due` allowed; both-unset passes.
  - [x] 5.2 Extend `tests/KuickPayConnectionProbeTest.php`: IPv6-only host resolves+validates+pins (new `resolvedAddresses` covering an IPv6 string; assert `CURLOPT_RESOLVE` bracketing); private IPv6 (`::1`, `fc00::1`) blocked → `url_blocked`; redirect/4xx/5xx asserted reachable; (best-effort) `connection.unavailable`.
  - [x] 5.3 Keep both KuickPay gateway behaviors green. Run the **gateway** suite (the dual-suite caveat for `KuickPayVoucherReferenceService` does not apply here since this story doesn't touch that lib, but run the full gateway suite). Capture the baseline first.

- [x] **Task 6 — Verification & evidence**
  - [x] 6.1 `php -l` on every changed PHP file under **both** ea-php83 (production runtime) and the ea-php82 source-floor — no 8.3-only syntax/APIs (project-context.md:39; memory `[[kuickpay-php82-toolchain-now-available]]`). `dns_get_record`/`gethostbynamel`/`filter_var` are all ≤8.2-safe.
  - [x] 6.2 Gateway suite **modulo the disclosed pre-existing `empty-currency` baseline red** (memory `[[kuickpay-failclosed-empty-currency-red]]`): `cd components/gateways/nonmerchant/kuickpay && <ea-php83> /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. **Do NOT** use `-c build/phpunit.xml` (project-context.md:74). Capture the actual baseline first (≈ gateway 250 tests, 1 disclosed failure per Story 5.5's record); disclose the baseline red as pre-existing, do not attribute it to this story.
  - [x] 6.3 Verify the logo renders: confirm `getimagesize()` reports a valid PNG of the intended dimensions; optionally note the admin extension-card path (`app/views/admin/paradigm/admin_company_gateways_installed.pdt:118-119`, ~200px container).
  - [x] 6.4 Update `deferred-work.md`: mark CLOSED-by-5.6 with one-line notes — `wsdl_url` SSRF/userinfo (`:46`), SSRF residual host-allowlist + IPv6 gap (`:57`), 4xx/5xx/redirect-as-reachable + probe coverage gaps (`:58,:60`), numeric bounds + relation (`:47`), missing logo (`:41`). Keep the `docs(kuickpay)`/`_bmad-output` doc commit **separate** from runtime commits (project-context.md:104).
  - [x] 6.5 Optional sanitized verification record under `docs/kuickpay/` per the 5.3/5.4/5.5 cadence — placeholders only, **NO** `config/blesta.php`/DB creds/host/KuickPay creds/raw SOAP/customer PII, and **no committed production WSDL host** (NFR8; `phase-0-contract.md:43-46`). Record exactly what ran vs. what was stubbed (NFR12).

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **Do NOT invent a KuickPay host literal.** `app.kuickpay.com` is "example only, not a production default"
  (`0-1…:60,146`; `3-1…:131`); the real host is operator-configured and uncommitted (`phase-0-contract.md:43-46`;
  NFR10 `epics.md:105`; architecture Anti-Pattern `:83,657`). The allowlist is an **operator-supplied setting**,
  default empty. Hard-coding any host fails the spec and the architecture review.
- **The SSRF private-range/rebinding guard already exists** (`deferred-work.md:57`) — extend it (allowlist +
  IPv6), don't re-author it. Re-reading `runConnectionTest()` (`:527-564`) before editing is mandatory.
- **Gate at SAVE.** The stored `wsdl_url` is fetched server-side unguarded by `getSoapClient()` (`:678-679`)
  and the plugin cron (`deferred-work.md:85`); `hasUsableWsdlUrl()` (`KuickPaySoapClient.php:359-374`) has no
  private-range/allowlist guard. The save rule is the only chokepoint that protects the **cron** path, not just
  the admin Test button.
- **Pure-reachability is intentional.** AC3 is document + honest test coverage, **not** "4xx ⇒ failure". An
  auth-protected WSDL returns 401/403; deeper validity is Story 5.1's credentialed path (done). Do not silently
  start rejecting 4xx/5xx (it would break a legitimate auth-gated endpoint). Stricter "4xx ⇒ failure" was
  **considered and declined** (Israr sign-off, story creation) — keep pure-reachability.
- **Don't edit the shared base.** `components/gateways/lib/gateway.php` (`getLogo()`, the base for every Blesta
  gateway) and any ionCube-protected file / `config/blesta.php` are off-limits (5.5 Dev Notes; project-context.md:91,126).
  The logo is fixed by **adding the missing asset at the default path**, not by overriding `getLogo()`.
- **No floats / no scope creep.** No amount math here (5.5 closed `normalizeAmount`). Don't touch the SOAP
  client transport, the parser, or plugin code. This story is `kuickpay.php` + a logo + language + view + tests.
- **Preserve `if_set` optional semantics.** Tightening numeric rules must not make an **unset** `soap_timeout`/
  offset suddenly required — only an explicit out-of-range value is rejected. Several settings legitimately
  default empty and fall through to runtime defaults (`getSoapClient` `?? ''`; payload `?? 0`).
- **Honest reporting (NFR8, NFR12).** Disclose the `empty-currency` baseline red as pre-existing; report
  exactly which PHP version ran and what was stubbed vs. exercised against the real framework; never commit the
  production WSDL host or any secret.

### Architecture compliance (must follow)

- **Ownership boundary (`architecture.md:331,520-522,667,770,776`):** the **gateway** owns the settings UI,
  encrypted gateway meta, the SOAP endpoint config, and the only SOAP-call site (`KuickPaySoapClient.php`). All
  5.6 work lands in the gateway tree. Do **not** push settings/endpoint logic into the plugin.
- **No hard-coded endpoints/credentials (`architecture.md:83,657`; NFR10 `epics.md:105`; `phase-0-contract.md:43-46`):**
  endpoints are operator-configured. The allowlist is a setting, not a literal.
- **NFR8 (`epics.md:101`):** no secrets exposed via the settings/diagnostic surface — rejecting `user:pass@host`
  removes credentials-in-URL; the verification record must carry no host/secret.
- **Connection-test contract (`epics.md:383` Story 1.4; UX-DR10 `epics.md:170`):** the probe reports success /
  credential failure / unavailable / timeout and **creates no payable voucher / marks no invoice paid** — AC3's
  documentation must stay consistent with this four-outcome contract.
- **Anti-Patterns (`architecture.md:648-662`):** no GET admin route that mutates; no hard-coded credentials.
  (The settings form is an existing POST flow — preserve it.)

### Files to modify (UPDATE/ADD) — and their current state

| File | AC | Current state → change |
|---|---|---|
| `components/gateways/nonmerchant/kuickpay/kuickpay.php` | AC1, AC2, AC3a | `wsdl_url.format` rule `:210-224` lenient (accepts userinfo + any host); shared `$optionalNumericRule` `:206` applied at `:268-288` (accepts `0`/leading-zeros/huge, no relation); `runConnectionTest()` `:502-602` strict but probe-only; `resolveProbeAddresses()` `:642-647` IPv4-only; reachability `:584` undocumented. Add shared wsdl validator + allowlist + save-time SSRF; split numeric rules + bounds + `expiry>=due`; resolve AAAA; document the reachability contract. |
| `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt` | AC1 | Renders `wsdl_url` (`:10-19`), `soap_timeout` (`:308-317`), offsets (`:130-149`), Test-connection **submit button** (`:319-324`). Add the `wsdl_allowed_hosts` field under the endpoint group; no inline error panel needed (Blesta surfaces `Input->errors()` generically). |
| `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` | AC1, AC2 | Existing `wsdl_url.empty/format` (`:115-116`), `soap_timeout.numeric` (`:126`), offset `.numeric` (`:127-128`), `connection.*` (`:143-147`). Add the new error keys + the `wsdl_allowed_hosts` label/note, matching the block style. **Single `en_us` locale — no parallel dir.** |
| `components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` | AC3b | **Absent** (the `images/` dir does not exist). Create a real transparent 8-bit RGBA PNG ~150px wide. |
| `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` | AC1 | `hasUsableWsdlUrl()` `:359-374` rejects userinfo + non-https, **no** allowlist/private-range. Align its userinfo/https logic with the new shared validator (no behavior regression); the allowlist/private-range guard stays at the save chokepoint by design — note this intentional boundary. |

**Test files (ADD/UPDATE):** `tests/KuickPaySettingsValidationTest.php` (**new** — AC1/AC2; no settings-rule
coverage exists today), `tests/KuickPayConnectionProbeTest.php` (AC1 IPv6 + AC3 redirect/4xx/5xx/unavailable).
**Docs:** `deferred-work.md` (closures); optional `docs/kuickpay/` verification record.

**DO NOT edit:** `components/gateways/lib/gateway.php` (shared base `getLogo()`), the plugin tree, any
ionCube-protected file, `config/blesta.php`. Do not bump `config.json` version. Do not mechanically reformat
legacy code (project-context.md:88,91,109,126).

### Allowlist mechanism (the AC1 design call)

The spec disallows host literals (trap #3). The reconciliation that satisfies *"hosts are validated against the
confirmed KuickPay endpoint allowlist"* **without inventing literals** is an **operator-configurable allowlist**:
a new optional `wsdl_allowed_hosts` gateway setting (the operator pastes their Phase-0-confirmed host(s) at
deployment — a hook for the 5.8 deployment docs). Recommended default: **empty ⇒ permissive** (the value must
still pass the AC1 public-host SSRF checks, preserving today's ability to save before the operator pins the
host); **populated ⇒ host must match**. The alternative (empty ⇒ fail-closed, save blocked until the allowlist
is configured) is stricter but breaks first-run setup and was **declined** (Israr sign-off, story creation).
**DECIDED: empty ⇒ permissive.** Either way: no literal in source.

### Testing (the harness reality)

- **`tests/bootstrap.php` provides NO framework stubs** — it only loads the `lib/*` classes. Each suite stubs
  `NonmerchantGateway` + `Language` **inline** (see `KuickPayConnectionProbeTest.php:5-19`) and `require_once`s
  `kuickpay.php`.
- **The connection-probe `Input` fake's `validates()` is a no-op that always passes** (`:31-35`) — it **cannot**
  test the new `setRules`/`validates` field rules. Two faithful options: **(a)** extract the rule logic into a
  **pure validator** (à la 5.5's `KuickPayAclDecision`) and unit-test it directly (recommended — fast, no
  framework); **(b)** load the **real** Blesta `Input` standalone (as `KuickPayCurrencyWiringTest` loads the real
  `Gateway` base now that the 5.1 live stack exists) and drive `editSettings()` end-to-end. If (b) genuinely
  cannot load `Input` in the component harness, document the exact prerequisite (NFR12) and rely on (a).
- **Network seam:** subclass `Kuickpay` and override the `protected` `executeConnectionProbe()` /
  `resolveProbeAddresses()` (settable `probeResult` / `resolvedAddresses`, captured `probeCalls`) — exactly as
  `KuickPayConnectionProbeGateway` (`:48-64`) does. Instantiate via
  `ReflectionClass::newInstanceWithoutConstructor()` + inject the `Input` fake; reach the probe through the
  public `editSettings($meta)` with `run_connection_test => 'true'`.
- **`Language::_` is an identity stub**, so assert against the literal key string
  (`'Kuickpay.!error.connection.url_blocked'` etc.). Read errors back via the nested shape
  `$input->errors['connection']['<token>']` (or `['wsdl_allowed_hosts']['<token>']` for save-rule errors).

### Language keys (add, matching `language/en_us/kuickpay.php:115-148`)

Convention `$lang['Kuickpay.!error.<field>.<rule>'] = '...';`. Likely additions: `soap_timeout.positive`,
`soap_timeout.range`; `due_date_offset_days.range`; `expiry_date_offset_days.range`,
`expiry_date_offset_days.before_due`; a `wsdl_url` host/userinfo save token (reuse the existing
`connection.url_userinfo`/`url_blocked` messages' tone, or add `wsdl_url.userinfo`/`wsdl_url.host`); plus the
`wsdl_allowed_hosts` label + note (`Kuickpay.wsdl_allowed_hosts`, `Kuickpay.wsdl_allowed_hosts_note`). Mirror the
existing tone ("must be a non-negative integer"; the `connection.*` "No voucher was created and no invoice was
changed." reassurance where appropriate).

### Previous Story Intelligence (Story 5.5 — `done`, baseline `c18e27af`)

5.5 patterns that directly constrain 5.6:

- **Extract pure, unit-testable decisions** — 5.5 pulled the admin-ACL decision into `KuickPayAclDecision`
  (`plugins/kuickpay_reconcile/lib/KuickPayAclDecision.php`) + `KuickPayAclDecisionTest`. Do the same for the
  `wsdl_url` safety / numeric-bounds / relation logic so it is testable without the real `Input`.
- **Keep duplicated validators byte-for-byte / non-drifting** — 5.5's `normalizeAmount()` had to stay identical
  across gateway + plugin copies. Here the three `wsdl_url` validators (save rule, probe, `hasUsableWsdlUrl()`)
  must share one userinfo/https source of truth (memory `[[kuickpay-voucher-reference-dual-test-suites]]`).
- **Fail closed; strictness reveals real bugs** — a save rule that now rejects userinfo/private hosts is the
  intended tightening; if an existing test breaks, the loose rule was hiding the gap.
- **PHP 8.3 runtime / 8.2 source-floor** — `php -l` under both; no 8.3-only APIs (memory
  `[[kuickpay-php82-toolchain-now-available]]`). 5.5 final gateway baseline: **250 tests, 1 disclosed
  `empty-currency` red**.
- **Test invocation:** `--bootstrap tests/bootstrap.php tests`, never `-c build/phpunit.xml`
  (project-context.md:74). External runner `/root/tools/phpunit-8.5/vendor/bin/phpunit`.
- **Commit slicing + doc separation** — `<type>(kuickpay): <summary>`, imperative, ≤72 chars; keep
  `_bmad-output/`/`docs/kuickpay/` doc commits **separate** from runtime (project-context.md:101-104).

### Git Intelligence (recent, relevant)

- `f8a6be40` (HEAD) `docs(kuickpay): record 5.5 review fixes` — **5.6 baseline**.
- `0acddd44`/`aeabbfa3` — 5.5 `normalizeAmount` rounding (the "keep both copies identical, no floats" discipline
  that 5.6 mirrors for the shared `wsdl_url` validator).
- `baf398c4` `fix(kuickpay): enforce registered admin permissions in each controller` + `KuickPayAclDecision`
  — the **pure-decision-class** pattern 5.6 reuses for testable settings validation.
- **Reasonable commit slicing for 5.6:** (1) shared `wsdl_url` validator + save-rule userinfo/host/SSRF +
  allowlist setting, (2) IPv6 AAAA resolution in the probe, (3) numeric bounds + leading-zero + `expiry>=due`,
  (4) reachability-contract docblock + honest tests, (5) logo asset, (6) settings-validation test suite, (7)
  `docs`/`deferred-work` closures (separate commit).

### Project Structure Notes

- Gateway tree only: `components/gateways/nonmerchant/kuickpay/{kuickpay.php, lib/*, views/default/{settings.pdt,
  images/logo.png}, language/en_us/kuickpay.php, tests/*, config.json}`. No files outside this tree (no plugin,
  no core).
- Style: legacy global class (no namespace, no `declare(strict_types=1)`); short array syntax, single quotes,
  LF, one space around operators (component-local `PSR2 Transitional` PHPCS). No broad reformat. `.pdt` exempt
  from end-newline/line-length (project-context.md:89).
- PNG is a binary asset — generate it; do not hand-author. Verify with `file`/`identify`/`getimagesize`.

### References

- [Source: epics.md#Story-5.6 (979–999)] — the three ACs + the `_Closes:_` list.
- [Source: epics.md] — NFR8 admin-only/no-secret (101); NFR10 no-hard-coded endpoints (105); NFR11 no live
  endpoints in default tests (107); Story 1.4 connection-test four-outcome contract (383); UX-DR10 safe test
  (170); FR2 configurable endpoints (27); FR15 SOAP client timeouts/TLS (53).
- [Source: deferred-work.md] — `wsdl_url` SSRF/userinfo (46); numeric bounds + `expiry>=due` (47); SSRF guard
  RESOLVED + residual host-allowlist + IPv6 gap (57); 4xx/5xx/redirect-as-reachable (58); probe coverage gaps
  incl. `connection.unavailable` (60); missing logo + `GatewayManager::getGatewayInfo` `gateway_manager.php:917-919`
  (41); cron builds SOAP client from stored `wsdl_url` (85).
- [Source: architecture.md] — gateway ownership boundary (331, 520–522, 667, 770, 776); no hard-coded
  endpoints/credentials (83, 657); Auth & Security (357, 373); Anti-Patterns (648–662).
- [Source: phase-0-contract.md:24-25,43-50,132-133] — endpoint CONFIRMED-from-WHMCS but value uncommitted /
  operator-configured; do not copy into source/fixtures/docs.
- [Source: kuickpay.php:206,210-224,268-288,502-602,584,642-647,678-679,895-896] — current (HEAD `f8a6be40`)
  anchors for the wsdl rule, numeric rules, connection test, reachability check, `resolveProbeAddresses`,
  `getSoapClient`, and the offset → voucher-payload mapping.
- [Source: lib/KuickPaySoapClient.php:359-374] — cron-side `hasUsableWsdlUrl()` (userinfo/https, no allowlist).
- [Source: components/gateways/lib/gateway.php:178-184] — default `getLogo()` → `views/default/images/logo.png`
  (shared base — **do not edit**).
- [Source: app/models/gateway_manager.php:917-919] — unconditional logo `<img>` URL (the broken-image origin);
  card render `app/views/admin/paradigm/admin_company_gateways_installed.pdt:118-119` (~200px container).
- [Source: tests/KuickPayConnectionProbeTest.php:5-19,23-64,233-240] — inline-stub harness, no-op `validates()`
  `Input` fake, the `executeConnectionProbe`/`resolveProbeAddresses` override seam.
- [Source: language/en_us/kuickpay.php:115-148] — existing error-key block to match (single `en_us` locale).
- Memory: `[[kuickpay-failclosed-empty-currency-red]]` (disclose the baseline red), `[[kuickpay-php82-toolchain-now-available]]`
  (8.3 runtime / 8.2 floor), `[[kuickpay-voucher-reference-dual-test-suites]]` (keep duplicated validators
  aligned), `[[kuickpay-real-gateway-base-loads-standalone]]` (the real `Gateway` base loads in the harness —
  relevant if testing `editSettings` against real `Input`).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context) — BMAD dev-story workflow.

### Debug Log References

- Baseline gateway suite (before changes): **250 tests, 1 failure** — the disclosed
  `KuickPayFailClosedContractTest` `empty-currency` red (memory
  `[[kuickpay-failclosed-empty-currency-red]]`).
- Final gateway suite: **312 tests, 1413 assertions, 1 failure** — same disclosed
  baseline red only (+62 new tests: 51 settings-validation, 11 probe).
- `php -l` clean under **both** ea-php83 (8.3.31) and ea-php82 (8.2.31) for every
  changed PHP file.
- Runtime facts verified before coding: `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`
  rejects `::1`/`fc00::/7`/`fe80::/10`/IPv4-mapped IPv6 and passes public IPv6;
  `dns_get_record(..., DNS_AAAA)` available; `Minphp\Input\Input` loads standalone
  (no DB/Loader); `if_set` skips only null/absent values (present `''` still runs
  the rule — handled by an explicit empty-string guard in the numeric closures).

### Completion Notes List

- **AC1 — `wsdl_url` save hardening + allowlist + IPv6.** Added a pure, shared
  validator `Kuickpay::wsdlUrlSafety($url, $allowedHosts)` (format/userinfo/
  literal-IP-range/allowlist; NO DNS) plus `validatedProbeAddresses()` (DNS-resolve
  + range-validate, shared by the save rule and the probe). The lenient
  `wsdl_url.format` rule is now `format` + `userinfo` + `host`, so a userinfo URL,
  a private/loopback/link-local/reserved literal IP, a named host resolving to a
  private address, or a non-allowlisted host can never be **persisted** — closing
  the unguarded `getSoapClient()`/cron SOAP-fetch surface at the save chokepoint.
  Operator allowlist `wsdl_allowed_hosts` is a new optional setting (NO host literal
  in source; empty ⇒ permissive, populated ⇒ exact case-insensitive host match).
  `resolveProbeAddresses()` now resolves AAAA in addition to A; `CURLOPT_RESOLVE`
  brackets IPv6 hosts correctly.
- **AC2 — numeric bounds + relation.** Split the shared `$optionalNumericRule`:
  `soap_timeout` ∈ [1, 300] (rejects `'0'` + leading zeros), offsets ∈ [0, 365]
  (allow `0`, reject leading zeros), and the cross-field rule
  `expiry_date_offset_days >= due_date_offset_days`. Pure predicates
  (`soapTimeoutInRange`/`offsetDaysInRange`/`expiryNotBeforeDue`) back both the
  Blesta rules and the unit tests. Unset/empty fields still pass (optional
  semantics preserved via an explicit `'' ||` guard, since `if_set` does not skip
  a present empty string).
- **AC3 — reachability contract + logo.** Documented the four-outcome pure-
  reachability contract in `runConnectionTest()`/`executeConnectionProbe()`
  docblocks (4xx/5xx/3xx = reachable by design; NOT flipped to "4xx ⇒ failure").
  Added a `connectionTransportAvailable()` seam so the `connection.unavailable`
  branch is covered honestly (without removing `curl_init`). Shipped a real
  150×69 transparent 8-bit RGBA `logo.png` at the default `getLogo()` path
  (GD + DejaVuSans-Bold; verified by `file`/`identify`/`getimagesize`).
- **Task 1.5 — intentional boundary.** `KuickPaySoapClient::hasUsableWsdlUrl()`
  keeps its userinfo/https checks in step with the shared validator
  (cross-referenced in both docblocks) but deliberately does NOT gain the
  private-range/allowlist guard: the save chokepoint already prevents a bad value
  from reaching it, so a call-time guard would duplicate the chokepoint, not add
  coverage. Documented here so the boundary is intentional, not an omission.
- **Testing fidelity.** New `KuickPaySettingsValidationTest` drives `editSettings()`
  end-to-end against the REAL `Minphp\Input\Input` (the connection-probe suite's
  no-op `Input` fake cannot evaluate field rules) AND unit-tests the pure
  validators directly. `KuickPayConnectionProbeTest` extended for IPv6
  resolve/validate/pin, private-IPv6 blocking, redirect/4xx/5xx-as-reachable, and
  the transport-unavailable branch. Review added a real-Input regression that
  invalid settings do not run the probe. No live DB or KuickPay SOAP call
  (offline; NFR11) — DNS stubbed via the `resolveProbeAddresses()` seam.
- **No schema change, no version bump.** Gateway `config.json` stays `1.0.0`; no
  plugin or core edits; shared base `getLogo()` untouched.

### File List

- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (modified) — shared
  `wsdl_url` validator + allowlist + save-time SSRF/userinfo + IPv6 AAAA + numeric
  bounds + `expiry>=due` relation + reachability-contract docblocks + transport seam.
- `components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php` (modified) —
  `hasUsableWsdlUrl()` docblock cross-reference + intentional-boundary note.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` (modified) —
  `wsdl_url.userinfo`/`.host`, `wsdl_allowed_hosts` label/note/`.valid`, numeric
  `.range` (replacing `.numeric`), `expiry_date_offset_days.before_due`.
- `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt` (modified) —
  added the `wsdl_allowed_hosts` field under the endpoints group.
- `components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` (added) —
  real 150×69 transparent 8-bit RGBA PNG.
- `components/gateways/nonmerchant/kuickpay/tests/KuickPaySettingsValidationTest.php`
  (added) — 52 tests (pure validators + real-`Input` editSettings end-to-end).
- `components/gateways/nonmerchant/kuickpay/tests/KuickPayConnectionProbeTest.php`
  (modified) — +11 tests (IPv6, redirect/4xx/5xx reachable, transport unavailable).
- `_bmad-output/kuickpay/implementation-artifacts/deferred-work.md` (modified) —
  marked CLOSED-by-5.6 for the logo, `wsdl_url` SSRF/userinfo, numeric bounds +
  relation, SSRF residual (allowlist + IPv6), 4xx/5xx-reachable, and probe coverage
  gaps. _(doc commit kept separate from runtime per project-context.md:104)_
- `docs/kuickpay/gateway-settings-and-endpoint-hardening-verification.md` (added) —
  sanitized verification record (NFR8/NFR12).

### Review Findings

- [x] [Review][Patch] Probe must reuse the shared WSDL safety validator [components/gateways/nonmerchant/kuickpay/kuickpay.php:741] — Fixed by routing `runConnectionTest()` through `Kuickpay::wsdlUrlSafety()` before address validation, so the save rule and probe share the same format/userinfo/allowlist decision path.
- [x] [Review][Patch] IPv6 `CURLOPT_RESOLVE` entries need bracketed address literals [components/gateways/nonmerchant/kuickpay/kuickpay.php:780] — Fixed by bracketing IPv6 resolved addresses in the address slot and updating the probe test expectation to match libcurl syntax.
- [x] [Review][Patch] SOAP timeout validation and runtime bounds must agree [components/gateways/nonmerchant/kuickpay/lib/KuickPaySoapClient.php:13] — Fixed by aligning the SOAP client runtime bounds and probe cap to the Story 5.6 accepted range of `1..300`, plus adding a real-Input regression that invalid settings do not run the probe.

### Change Log

| Date | Change |
|---|---|
| 2026-06-15 | AC1: shared `wsdl_url` safety validator + save-time userinfo/SSRF/allowlist hardening; IPv6 AAAA resolution + `CURLOPT_RESOLVE` bracketing in the probe. |
| 2026-06-15 | AC2: split numeric rules — `soap_timeout` ∈ [1,300], offsets ∈ [0,365], leading-zero rejection, and the `expiry_date_offset_days >= due_date_offset_days` relation. |
| 2026-06-15 | AC3: documented the pure-reachability contract + honest probe coverage (redirect/4xx/5xx + `connection.unavailable`); shipped a real 150×69 RGBA `logo.png`. |
| 2026-06-15 | Tests: new `KuickPaySettingsValidationTest` (real `Input` + pure validators); extended `KuickPayConnectionProbeTest`. Gateway suite 313 tests / 1 disclosed baseline red after review fixes. |
| 2026-06-15 | Docs: `deferred-work.md` closures + sanitized verification record. |
| 2026-06-16 | Review fixes: shared validator reuse in the probe, bracketed IPv6 `CURLOPT_RESOLVE` addresses, and runtime timeout alignment with the accepted `1..300` range. |
