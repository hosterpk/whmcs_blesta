---
baseline_commit: 45926c5e41114ad147968f6ed3ffe43226be40bb
---

# Story 1.5: Enforce PKR-Only Gateway Eligibility

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin and customer,
I want KuickPay available only for eligible PKR invoices,
so that unsupported currencies cannot create unsafe payment references.

## Acceptance Criteria

(Reproduced verbatim from [Source: epics.md#Story 1.5, lines 395-415].)

**AC1 — KuickPay can appear for a PKR invoice**
**Given** KuickPay is enabled for the company
**When** a customer views payment options for a PKR invoice
**Then** KuickPay can appear as a non-merchant payment option.

**AC2 — Non-PKR invoice: hidden or blocked, no voucher**
**Given** a customer views payment options for a non-PKR invoice
**When** KuickPay eligibility is evaluated
**Then** KuickPay is hidden or blocked with clear localized copy
**And** no Voucher is created.

**AC3 — PKR-first policy visible, no hard-coded conversion**
**Given** currency behavior is configured
**When** an admin reviews KuickPay settings
**Then** the PKR-first policy is visible
**And** no USD-to-PKR or other conversion value is hard-coded.

> **What this story actually changes** (read before scoping): Stories 1.1–1.4 (and 3.1) delivered the gateway scaffold, the PKR-only `config.json`, the grouped settings form (including the `currency_policy = pkr_only` select + note), credential encryption/masking, the SOAP client, and the safe connection test. **Blesta already hides KuickPay from non-PKR invoices natively** — `config.json` declares `"currencies": ["PKR"]`, and both gateway-listing paths inner-join `gateway_currencies` on the invoice currency (see Dev Notes → "How Blesta already enforces currency eligibility"). **1.5's net-new work is to make that guarantee fail-closed and explicit, not to re-invent it:** (1) add a **defense-in-depth currency-eligibility guard inside `buildProcess()`** — via a small pure `currencyEligible()` helper (the unit-test target) — so that a non-PKR currency reaching the gateway (today gated only by the companion check) is **blocked with clear localized copy and creates no Voucher** (Task 1 — AC2); (2) **lock the native config-level hiding** against regression and prove a PKR invoice still lists KuickPay (Task 2 — AC1); (3) **surface the PKR-first policy** in settings and **prove no conversion value is hard-coded** in business logic (Task 3 — AC3); (4) add the customer-facing language string for the blocked state (Task 4); (5) targeted tests + honest verification (Tasks 5–6). **Scope guardrail:** voucher creation itself is **Epic 2 (Story 2.3)** and posting is **Epic 3** — 1.5 only *gates* the entry point so that when Epic 2 wires voucher creation into `buildProcess()`, the eligibility check is already in front of it. Do **not** build voucher persistence, the customer reference panel, or instruction groups here. See **Dev Notes → "Scope: what 1.5 owns vs what later stories own."**

### Acceptance closure for 1.5 — how "done" is measured (read together with the verbatim ACs above)

These ACs are fully closable by 1.5 (unlike 1.4's partial-fulfillment case). Measure "done" as:

- **AC1 DONE when:** `config.json` still declares `currencies` containing `PKR` (PKR is assignable by the admin and a PKR invoice lists KuickPay through the native `gateway_currencies` join), and `buildProcess()` renders the normal (companion-gated placeholder) path for a `PKR` active currency without setting a currency-ineligibility error.
- **AC2 DONE when:** a non-PKR (or unknown/empty) active currency reaching `buildProcess()` produces a **clear, language-keyed** ineligibility message via `Input->setErrors()` and **no** payable form / **no** Voucher path runs (fail-closed). The native "hidden" arm (KuickPay absent from a non-PKR invoice's method list) is preserved and asserted.
- **AC3 DONE when:** the settings screen visibly states the PKR-only/PKR-first policy (the `currency_policy` field + note already render it; confirm/strengthen visibility), the `currency_policy` save rule still restricts the value to `pkr_only` (no conversion policy is selectable in MVP), and a grep proves **no** USD-to-PKR / exchange-rate / conversion constant exists in gateway or `lib/` business logic.

A reviewer should treat AC2's "hidden **or** blocked" as satisfied by **both** layers together: the native join hides KuickPay for non-PKR invoices, and the `buildProcess()` guard blocks-with-copy on any path that still reaches the gateway. 1.5 is the story that makes the "no Voucher created for non-PKR" guarantee structural rather than incidental.

## Dependency & starting-state (read FIRST)

**Stories 1.1, 1.2, 1.3, 1.4 are `done`/merged, and Story 3.1 is `done` too — the gateway is in the post-1.4 + post-3.1 state.** [Source: sprint-status.yaml `1-1…`–`1-4…: done`, `3-1…: done`] 1.5 is the **last story in Epic 1** (Track A: 1-1 → 1-2 → 1-3 → 1-4 → **1-5**), runs in parallel with Phase 0, and is **fully unblocked today**. [Source: sprint-status.yaml BUILD ORDER lines 38-65]

1.5 edits the **same gateway files** prior Epic 1 stories built. As-built state you are starting from (read each in full before editing):

- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (607 lines) — `class Kuickpay extends NonmerchantGateway` (legacy global class, **no namespace, no `declare(strict_types)`**; base `Gateway` carries `#[\AllowDynamicProperties]`, so the dynamic `$this->currency` written by `setCurrency()` is allowed — see Dev Notes). Key members relevant to 1.5:
  - `setCurrency($currency)` (**51-54**): `{$this->currency = $currency;}` — sets the active currency the framework hands the gateway **before** `buildProcess()`. This is the value 1.5's guard reads. **Preserve as-is.**
  - `getSettings(array $meta = null)` (**62-86**): renders `settings.pdt`; sets `companion_installed`, the `currency_policy`/`fee_policy` **option arrays** (`currency_policy` offers only `pkr_only`), and the password-stored booleans. AC3 visibility lives in the view this renders.
  - `editSettings(array $meta)` (**94-276**): defaulting → trim → `$same` dedupe → `$rules` (incl. the **`currency_policy` rule at 215-221**: `['in_array', ['pkr_only']]`) → `setRules`/`validates` → sentinel-gated connection test (1.4) → `unset(run_connection_test)` → `return $meta`. **AC3's "no conversion policy selectable" is enforced by the existing `currency_policy` rule — do not weaken it.**
  - `buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)` (**534-546**): makes the `process` view, loads `Html`, and **today only checks `companionInstalled()`** — on failure sets `getCommonError('unsupported')`; always returns `$this->view->fetch()`. **This is where 1.5 adds the currency-eligibility guard** (Task 1). It is the customer-facing payment-form entry point and the future home of Epic 2 voucher creation.
  - `validate()` (**567-571**) and `success()` (**590-594**): both **fail-closed** via `getCommonError('unsupported')`. **Do not touch** — no payment can be created through them regardless of currency.
  - `getCurrencies()` is **inherited** from `components/gateways/lib/gateway.php:99-105`: returns `$this->config->currencies` (i.e. `["PKR"]` from `config.json`, set by `loadConfig()` in the constructor). 1.5's guard uses this so **"PKR" lives only in `config.json` data, never hard-coded in business logic**.
  - `companionInstalled()` (**601-606**, `private`), `getSoapClient()`/`setMeta()` (3.1), `maskCredentials()`/`$credential_mask_fields` (1.3), `runConnectionTest()`/`executeConnectionProbe()`/`resolveProbeAddresses()` (1.4) — **all out of scope; do not change.**
- `components/gateways/nonmerchant/kuickpay/config.json` (14 lines) — declares `"currencies": ["PKR"]` (lines 11-13). **This is the native eligibility contract. Do NOT remove, broaden, or rename it.** Verifying it stays exactly `["PKR"]` is part of AC1/AC2.
- `views/default/settings.pdt` (255 lines) — entire form gated on `$companion_installed`. The **Payment Behavior Policies** group (`title_row` @ 126) renders the `currency_policy` select (**160-169**) with the info-tooltip note `Kuickpay.currency_policy_note`. This is the AC3 "PKR-first policy visible" surface.
- `views/default/process.pdt` (2 lines) — echoes `Kuickpay.process.not_ready` only. Customer-facing placeholder; **no voucher/amount/paid language**. 1.5 does **not** need to change this (the blocked-currency message surfaces through the gateway error region, mirroring the companion-missing pattern) — see Dev Notes.
- `language/en_us/kuickpay.php` (90 lines) — has `Kuickpay.currency_policy` (41), `Kuickpay.currency_policy_note` (42, "KuickPay is configured for PKR invoices only. No conversion rate is stored or applied here."), `Kuickpay.currency_policy.pkr_only` (43), and `Kuickpay.process.not_ready` (7). **No customer-facing "currency ineligible / non-PKR blocked" key exists** — 1.5 adds it. Last key is `Kuickpay.!error.connection.unavailable` (90).

**Invariants that must be preserved** (do not revert): the `config.json` `currencies: ["PKR"]` declaration; the companion-missing guard and fail-closed `validate`/`success`; 1.2 validation incl. the `currency_policy` `in_array(['pkr_only'])` rule and `/D`-anchored regexes; 1.3 credential masking/encryption; 1.4's connection-test path; 3.1's SOAP client/`getSoapClient()`/`lib/` classes (untouched).

## Non-Negotiables (read before any task)

1. **A non-PKR (or unknown/empty) currency must never produce a payable form or a Voucher — fail closed.** [Source: epics.md AC2 lines 407-410; prd.md FR-5 lines 126-133; NFR9 line 103 "fail closed to retry or Manual Review, not paid"; UX-DR2 line 154] The `buildProcess()` guard treats **only** a currency present in `$this->getCurrencies()` (config-declared, i.e. `PKR`) as eligible. Anything else — a non-PKR code, `null`, or empty (e.g. `buildProcess()` somehow reached without `setCurrency()`) — is **ineligible**: set the language-keyed error and do **not** run any voucher-creation path. There is no voucher path in 1.5 yet; the guard is the gate Epic 2's `InsertVoucher` flow will sit behind, so place it as an **early** check before any future create logic.

2. **Do NOT hard-code "PKR" (or any currency / conversion rate) in business logic.** [Source: epics.md AC3 line 415; prd.md FR-5 line 132; architecture.md:83 "No hard-coded … conversion rates"; NFR10 line 105; project-context.md anti-patterns] Read eligibility from `$this->getCurrencies()` (which returns `config.json`'s `currencies`), mirroring the core `GatewayManager::currencyExists()` contract (`in_array($currency, $gateway->getCurrencies())`, gateway_manager.php:778-781). The literal `"PKR"` belongs in `config.json` (data) only. **No** USD-to-PKR multiplier, exchange rate, or conversion table may appear anywhere in `kuickpay.php` or `lib/`.

3. **All customer/admin-facing strings come from language files; no raw/internal detail.** [Source: project-context.md#Language-Specific Rules; epics.md UX-DR28 line 206] The blocked-currency message is a `$lang['Kuickpay.*']` key surfaced via `Input->setErrors()`. Never concatenate the currency code, an internal class/operation name, or any diagnostic into customer copy. Preserve the language file's single-quote / one-key-per-line style; do not reorder or rewrap existing keys.

4. **Preserve Blesta's native currency hiding; do not weaken or bypass it.** [Source: app/models/gateway_manager.php:310-360 (`getAllInstalledNonmerchant`), :234-298 (`getInstalledNonmerchant`); architecture.md:776 "Gateway: … PKR eligibility"] The `config.json` `currencies: ["PKR"]` + the `gateway_currencies` inner-join are what make KuickPay invisible to non-PKR invoices (the AC2 "hidden" arm and the AC1 "appears for PKR" arm). 1.5 must **not** edit core, must **not** alter the gateway's currency declaration to be broader, and must **not** add a parallel listing path. The `buildProcess()` guard is **defense in depth layered on top of** this, not a replacement for it.

5. **Stay in scope; no regressions; no Epic 2/3 work.** Touch **only** `components/gateways/nonmerchant/kuickpay/kuickpay.php`, `language/en_us/kuickpay.php`, and (if needed for AC3 visibility) `views/default/settings.pdt` — plus, optionally, **one** new committed unit test under the existing `tests/` dir (Task 5). Do **not**: create Voucher persistence/models, the customer reference panel, instruction groups, schema, cron, plugin code, an AJAX/controller route, or any core edit. Do **not** change `config.json` other than to *confirm* it (no diff expected). Match parent signatures; target **PHP 8.2** (no 8.3+ syntax). [Source: architecture.md:518-526, 765-778; project-context.md]

6. **Read `$this->currency` defensively; do not redeclare or change `setCurrency()`.** [Source: kuickpay.php:51-54; components/gateways/lib/gateway.php:16 `#[\AllowDynamicProperties]`] `setCurrency()` already stores the active currency as the dynamic `$this->currency` (allowed by the base attribute — **not** a deprecation, so do **not** "fix" it by adding a typed property unless you keep it null-safe and behavior-identical; the minimal-diff choice is to read `$this->currency ?? null` as-is). The guard must tolerate `$this->currency` being unset/`null` and treat it as ineligible (NN#1). Do **not** modify `setCurrency()`'s signature or behavior.

## Tasks / Subtasks

- [ ] **Task 1 — Add the PKR-eligibility guard to `buildProcess()` (AC2, defense-in-depth)** [Source: kuickpay.php:534-546; gateway_manager.php:778-781; architecture.md:776]
  - [ ] 1.1 Add a small **`protected` eligibility helper** (e.g. `currencyEligible()`) that returns `in_array((string) ($this->currency ?? ''), (array) $this->getCurrencies(), true)`. Source PKR from `getCurrencies()` only (NN#2) — mirrors core `GatewayManager::currencyExists()`. Treat unset/empty/non-PKR (and an empty `getCurrencies()`) as **ineligible** (NN#1, NN#6). Strict in-array; Blesta currency codes are upper-case ISO 4217, matching `config.json`'s `PKR`. Keeping the decision in a pure helper (no view/Loader/PluginManager) is what makes Task 5 cleanly unit-testable — see Dev Notes "buildProcess() change — exact shape".
  - [ ] 1.2 In `buildProcess()`, **before** the existing `companionInstalled()` check (and before any future voucher logic), call the helper; when **ineligible**, set a language-keyed gateway error — `$this->Input->setErrors(['currency' => ['ineligible' => Language::_('Kuickpay.!error.currency_ineligible', true)]]);` — and ensure **no payable form / no voucher path** runs. Mirror the existing companion-missing pattern (which sets `getCommonError('unsupported')` and still returns the neutral `process` view): returning the rendered `process` view while an error is set is the established gateway idiom — Blesta surfaces the error on the pay page and does not process a payment. Do **not** invent a new return shape. Do **not** echo the currency code or any internal detail (NN#3).
  - [ ] 1.3 Order the checks **currency-first** (customer-facing AC2 path), then companion (admin-config problem), using `if (!$this->currencyEligible()) { … } elseif (!$this->companionInstalled()) { … }`. The `elseif` is canonical: on a **double failure** (non-PKR currency **and** companion missing) only the currency-ineligibility error shows — intentional (the customer gets the most relevant message). Do **not** stack/merge both errors. Keep the single `return $this->view->fetch();` at the end; do not add divergent returns.
  - [ ] 1.4 Add a code comment marking this as the **eligibility gate for Epic 2 voucher creation**: "Story 2.3 wires `InsertVoucher`/voucher persistence into `buildProcess()` — it MUST sit behind this guard so a non-PKR currency can never create a Voucher (epics.md AC2; NFR9 fail-closed)." Do **not** stub any voucher logic here.

- [ ] **Task 2 — Lock the native config-level eligibility (AC1, AC2 "hidden" arm)** [Source: config.json:11-13; gateway_manager.php:310-360, 234-298]
  - [ ] 2.1 **Confirm `config.json` is unchanged** and still declares `"currencies": ["PKR"]` (exactly — no extra codes, no removal). Expect **no diff** to `config.json`. If it differs from `["PKR"]`, stop and flag (it would silently change customer-visible eligibility).
  - [ ] 2.2 Record in Dev Notes (and a brief code comment near the guard) that the **primary** "hidden for non-PKR / shown for PKR" behavior is Blesta-native: `config.json currencies` gates which currencies the admin can assign in `gateway_currencies`, and `getAllInstalledNonmerchant()`/`getInstalledNonmerchant()` inner-join that table on the invoice currency, so KuickPay never lists for a non-PKR invoice. The `buildProcess()` guard is the fail-closed backstop, not the primary mechanism. Do **not** duplicate or replace the core join.
  - [ ] 2.3 Note the **admin dependency** (do not implement): for AC1 to hold in a live system, the admin must (a) have PKR configured as a company currency and (b) assign PKR to KuickPay on the gateway manage screen — the standard Blesta gateway-currency assignment. 1.5 changes nothing here; it only relies on the existing `config.json` declaration making PKR the sole assignable option.

- [ ] **Task 3 — PKR-first policy visibility + no hard-coded conversion (AC3)** [Source: settings.pdt:160-169; language/en_us/kuickpay.php:41-43; kuickpay.php:215-221; prd.md FR-5 line 133]
  - [ ] 3.1 Confirm the settings screen **visibly states** the PKR-first/PKR-only policy. Today the `currency_policy` select (value `pkr_only`) plus the `Kuickpay.currency_policy_note` tooltip ("KuickPay is configured for PKR invoices only. No conversion rate is stored or applied here.") render this. **This already satisfies "the PKR-first policy is visible."** If you judge the tooltip-only placement too easy to miss, you MAY add a single always-visible `form-text`/info line under the currency field restating the policy (language-keyed, e.g. reuse/extend the note) — keep it minimal, Blesta-native, and do not restructure the group. Do not convert the select into free text.
  - [ ] 3.2 Confirm the `editSettings()` `currency_policy` rule still restricts the saved value to `['in_array', ['pkr_only']]` (kuickpay.php:215-221) so **no conversion policy is selectable** in MVP. Do **not** weaken it. (FR-5: PKR-only "unless an approved currency conversion policy is configured" — none is configurable in MVP, which is correct.)
  - [ ] 3.3 **Prove no hard-coded conversion** (NN#2): the guard reads `getCurrencies()`, and no exchange-rate/USD-to-PKR/multiplier constant exists in `kuickpay.php` or `lib/`. Add a Dev Note recording the grep proof (Task 6.3). No code change is expected for this sub-AC beyond the guard sourcing PKR from config.

- [ ] **Task 4 — Language string for the blocked state (AC2, AC3)** [Source: language/en_us/kuickpay.php; UX-DR28; ux EXPERIENCE.md "Non-PKR Blocked" surface line 28]
  - [ ] 4.1 Add the customer-facing key `Kuickpay.!error.currency_ineligible` — clean, no raw/internal detail, honest that the invoice is unaffected. Exact line to append (single-quote, one-key-per-line style):
    ```php
    $lang['Kuickpay.!error.currency_ineligible'] = 'KuickPay can only be used to pay invoices in Pakistani Rupees (PKR). Please choose another payment method for this invoice.';
    ```
    Keep it currency-policy-honest but customer-simple (do not surface the "unless a conversion policy is approved" admin nuance to customers). Append it after the current last key (`Kuickpay.!error.connection.unavailable`, line 90) or in a clearly commented "PKR eligibility (Story 1.5)" block; preserve all existing keys/order/quoting.
  - [ ] 4.2 If Task 3.1's optional always-visible policy line is added, add its language key too (or reuse `Kuickpay.currency_policy_note`). Do not hard-code any label in the view.

- [ ] **Task 5 — Tests (AC1, AC2) — targeted, no live external calls** [Source: project-context.md#Testing Rules; 3-1/1-4 gateway-local `tests/` + `build/phpunit.xml` pattern; NFR11]
  - [ ] 5.1 **Target the pure helper, not `buildProcess()` directly.** Testing `buildProcess()` in isolation would require stubbing the view layer (`makeView`/`fetch`), `Loader::loadHelpers`, **and** the `private` `companionInstalled()` → `PluginManager`/`Configure` chain — far heavier than any existing gateway test and with no repo precedent (the 1.4/3.1 tests exercise **view-free** `protected` seams only). The `currencyEligible()` helper (Task 1.1) sidesteps all of it. **Note `companionInstalled()` is `private`** — a test subclass cannot override it for a `buildProcess()` test (PHP private methods are not polymorphic); this is the second reason to test the helper.
  - [ ] 5.2 **Eligible PKR passes (AC1):** in a tiny test subclass of `Kuickpay`, override `getCurrencies()` to return `['PKR']` (the in-test `NonmerchantGateway` stub does not provide the base `Gateway::getCurrencies()`, so the test must supply it), `setCurrency('PKR')`, and assert `currencyEligible()` is `true`. **Ineligible blocked (AC2):** `setCurrency('USD')`, unset/`null` currency, and `getCurrencies() === []` cases → assert `currencyEligible()` is `false`. Optionally smoke-test `buildProcess()` only where the harness supports it, asserting `$gateway->Input->errors()` carries the `currency`/`ineligible` key. **On "no Voucher created":** there is **no** voucher path in 1.5 (deferred to Epic 2/2.3), so the test can only assert the ineligibility error is set; the structural "no Voucher" guarantee becomes behaviorally testable when Epic 2 wires `InsertVoucher` behind the guard — say so, so a reviewer does not reject the test for "not proving no voucher was created".
  - [ ] 5.3 **Config eligibility regression (AC1/AC2 hidden arm):** assert (via the gateway with its real `config.json` loaded, or by reading the file) that the declared currencies are exactly `['PKR']` — so the native `gateway_currencies` join keeps hiding KuickPay for non-PKR invoices.
  - [ ] 5.4 **Where these tests live:** add `components/gateways/nonmerchant/kuickpay/tests/KuickPayCurrencyEligibilityTest.php` (class `KuickPayCurrencyEligibilityTest`, matching the `KuickPay<Feature>Test` convention). **Do not** create a root `tests/` dir. Follow the **self-contained** pattern the 1.4 test uses — define the minimal `NonmerchantGateway`/`Language` stubs and `require_once __DIR__ . '/../kuickpay.php';` directly; the eligibility test does **not** need `tests/bootstrap.php` (it loads the SOAP/redactor `lib/` classes, irrelevant here). Run via the component-local runner; if you do route through `build/phpunit.xml`, note the documented caveat (project-context.md:70) that it resolves `tests/bootstrap.php` relative to `build/` (use `--bootstrap …/tests/bootstrap.php …/tests`). **If no PHP runtime / `php` is on PATH** (the validation shells had none), run `php -l` where a PHP 8.2-compatible binary exists + the Task 6 greps, and **state explicitly** that runtime coverage did not run — do not overstate.

- [ ] **Task 6 — Verification (no overstating)** [Source: project-context.md#Development Workflow Rules]
  - [ ] 6.1 `php -l` the touched files (`kuickpay.php`, `language/en_us/kuickpay.php`, and `settings.pdt` if changed) using a PHP 8.2-compatible binary if one is available; the patch must use 8.2-safe syntax only. **If no `php` is on PATH**, state that `php -l` could not run and rely on the static greps (6.2–6.5) + source review — do not overstate runtime coverage.
  - [ ] 6.2 **Guard present + config-sourced (NN#1, NN#2):** `grep -nE "currencyEligible|getCurrencies|\\$this->currency|currency_ineligible|in_array" components/gateways/nonmerchant/kuickpay/kuickpay.php` → confirm the helper reads `$this->currency`, checks it against `getCurrencies()`, and `buildProcess()` sets the language-keyed error on the ineligible path.
  - [ ] 6.3 **No hard-coded currency/conversion in business logic (NN#2, AC3):** `grep -rniE "usd|exchange|conversion|convert|->\\s*0\\.[0-9]|rate" components/gateways/nonmerchant/kuickpay/kuickpay.php components/gateways/nonmerchant/kuickpay/lib/*.php` → expect **no** conversion math/constants; and `grep -rn "PKR" components/gateways/nonmerchant/kuickpay/kuickpay.php components/gateways/nonmerchant/kuickpay/lib/` → expect **no** `PKR` literal in business logic. (Do **not** grep the whole gateway dir for `PKR`: it legitimately appears in `config.json` (data), `language/en_us/kuickpay.php` (copy), and existing `tests/` fixtures — e.g. `KuickPaySoapClientTest.php` — so a repo-wide hit is expected and is **not** a failure.)
  - [ ] 6.4 **Config unchanged (Task 2.1):** `git diff -- components/gateways/nonmerchant/kuickpay/config.json` → expect **empty**; `grep -n "currencies" components/gateways/nonmerchant/kuickpay/config.json` → `["PKR"]`.
  - [ ] 6.5 **Scope containment (NN#5):** `git status --porcelain` shows only `kuickpay.php`, `language/en_us/kuickpay.php`, optionally `settings.pdt`, optionally one new `tests/KuickPayCurrencyEligibilityTest.php`, this story file, and `sprint-status.yaml`; **no** `plugins/kuickpay_reconcile/` changes, **no** core edits, **no** `lib/*`/`config.json`/`process.pdt` changes, **no** change to 3.1/1.4 methods or tests.
  - [ ] 6.6 If a running Blesta + MySQL stack is available: enable KuickPay, assign PKR; open the client pay page for a **PKR** invoice → KuickPay appears as a method (AC1); for a **non-PKR** invoice → KuickPay is **absent** from the method list (native hidden arm). If you can force `buildProcess()` with a non-PKR currency (e.g. explicit gateway selection on a multi-currency edge), confirm the language-keyed block shows and **no** voucher/transaction row is created (AC2). If no runtime/DB, **state that explicitly** and rely on `php -l` + greps + the unit checks. [Source: NFR12]

## Dev Notes

### Scope: what 1.5 owns vs what later stories own

| Surface in the ACs | Owned by 1.5? | Where it lives |
|---|---|---|
| **PKR invoice can list KuickPay (AC1)** | ✅ Confirmed (native) | `config.json currencies: ["PKR"]` + `gateway_currencies` join (`getAllInstalledNonmerchant`/`getInstalledNonmerchant`) — 1.5 verifies & does not regress it |
| **Non-PKR hidden from method list (AC2 arm 1)** | ✅ Confirmed (native) | same native join — KuickPay never lists for a non-PKR invoice |
| **Non-PKR blocked-with-copy + no Voucher (AC2 arm 2)** | ✅ Yes (net-new) | the `buildProcess()` eligibility guard reading `getCurrencies()` (Task 1) — fail-closed backstop |
| **PKR-first policy visible in settings (AC3)** | ✅ Confirmed/strengthen | `currency_policy` select + `currency_policy_note` in `settings.pdt` (Task 3.1) |
| **No conversion value hard-coded (AC3)** | ✅ Yes | guard sources PKR from `getCurrencies()`; grep proof (Tasks 3.3/6.3) |
| **Actual Voucher creation / reuse / idempotency** | ❌ No | **Epic 2, Story 2.3** (`map-invoice-data-and-issue-kuickpay-voucher`) — wired into `buildProcess()` **behind** 1.5's guard |
| **Customer reference panel / Consumer Number / instructions** | ❌ No | **Epic 2** (Stories 2.5/2.6) |
| **Payment posting / paid-state** | ❌ No | **Epic 3** (`KuickPayPostingService` only) |
| **Approved currency-conversion policy (FR-5 escape hatch)** | ❌ No (not in MVP) | no conversion policy is configurable; `currency_policy` is locked to `pkr_only` |

### How Blesta already enforces currency eligibility (the native mechanism — read before coding)

Blesta gateways declare supported currencies in `config.json`; the base `Gateway::getCurrencies()` returns `$this->config->currencies` (gateway.php:99-105). KuickPay's `config.json` declares `["PKR"]`. Two consequences make most of AC1/AC2 **already true** before 1.5:

1. **Admin assignment is constrained to PKR.** On the gateway manage screen, the assignable currencies are the intersection of `$gateway->getCurrencies()` (= `["PKR"]`) and the company's configured currencies (`admin_company_gateways.php:239-289`). So an admin can only ever map PKR into the `gateway_currencies` table for KuickPay.
2. **The pay-page listing inner-joins on the invoice currency.** Both `GatewayManager::getAllInstalledNonmerchant($company_id, $currency, …)` (gateway_manager.php:310-360, the "list all methods" path) and `getInstalledNonmerchant($company_id, …, $currency, …)` (234-298, the explicit-gateway path used when a client picks a specific method) do `->on('gateway_currencies.currency','=',$currency)->innerJoin('gateway_currencies', …)`. A non-PKR invoice therefore returns **no** KuickPay row, and `GatewayPayments::getBuildProcess()` (gateway_payments.php:133-170) never even constructs the gateway for it. This is the AC2 **"hidden"** arm and the AC1 **"appears for PKR"** arm — delivered natively.

`GatewayManager::currencyExists($currency, $gateway)` (gateway_manager.php:778-781) is the canonical "is this currency supported by this gateway" predicate and is literally `in_array($currency, $gateway->getCurrencies())` — 1.5's guard mirrors that exact contract so the gateway's self-check agrees with the core.

**So why add a `buildProcess()` guard at all?** Because AC2 requires "hidden **or** blocked with clear copy **and** no Voucher created" as a structural guarantee, and NFR9 demands fail-closed behavior. The native join *hides* but does not, by itself, guarantee that the gateway's voucher-creation entry point refuses a non-PKR currency if one ever reaches it. (Note: **both** listing paths are currency-filtered — `getBuildProcess()` passes `$currency` to `getInstalledNonmerchant(…)` on the explicit-gateway-id path *and* to `getAllInstalledNonmerchant(…)` on the list-all path, so an ordinary explicit selection is filtered too. The residual ways a non-PKR currency could still reach `buildProcess()` are: a DB-level misconfiguration of `gateway_currencies`, a future multi-currency Payment Attempt edge, a direct unit-test seam, or simply Epic 2 wiring `InsertVoucher` into `buildProcess()` later.) The guard makes "no Voucher for non-PKR" true **at the gateway**, not just incidentally true because of the listing query. It is defense-in-depth layered on top of the native mechanism — **not** a replacement, and it must not edit core or broaden the declaration (NN#4).

### `buildProcess()` change — exact shape

`buildProcess()` today (kuickpay.php:534-546):

```php
public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
{
    $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

    // Load the helpers required for this view
    Loader::loadHelpers($this, ['Html']);

    if (!$this->companionInstalled()) {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    return $this->view->fetch();
}
```

Extract the decision into a small **pure eligibility helper**, then call it from `buildProcess()` **before** the companion check (currency-first per Task 1.3). The helper — not `buildProcess()` — is the unit-test target (Task 5): it needs only `$this->currency` + `getCurrencies()`, so AC1/AC2 are verifiable without standing up the view / `Loader::loadHelpers` / `PluginManager` that `buildProcess()` pulls in. Source PKR from `getCurrencies()` (never a literal), null-safe on `$this->currency`:

```php
    /**
     * Whether the active payment currency is eligible for KuickPay.
     *
     * Eligibility is read from getCurrencies() (config.json -> ["PKR"]) so no
     * currency/conversion value is hard-coded (epics.md AC3; architecture.md:84).
     * Mirrors GatewayManager::currencyExists(): a non-PKR code, null, empty, or an
     * empty getCurrencies() (e.g. config failed to load) is ineligible — fail closed
     * (NFR9). Do NOT add a hard-coded "PKR" fallback (NN#2).
     */
    protected function currencyEligible()
    {
        return in_array((string) ($this->currency ?? ''), (array) $this->getCurrencies(), true);
    }
```

and in `buildProcess()`, before the existing companion check:

```php
    // AC2 (Story 1.5): PKR-only eligibility gate, before any (future) voucher logic.
    // The active currency is set by setCurrency() before buildProcess(). Anything
    // not config-eligible is blocked with localized copy and creates no Voucher.
    // Story 2.3 must wire InsertVoucher BEHIND this gate.
    if (!$this->currencyEligible()) {
        $this->Input->setErrors(
            ['currency' => ['ineligible' => Language::_('Kuickpay.!error.currency_ineligible', true)]]
        );
    } elseif (!$this->companionInstalled()) {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    return $this->view->fetch();
```

Notes: keep the single trailing `return $this->view->fetch();` (the neutral `process.not_ready` view is fine to render alongside a set error — that is exactly how the companion-missing case already behaves, and Blesta will not process a payment while the gateway has errors). The `elseif` is **canonical**: on a double failure (non-PKR currency **and** companion missing) only the currency-ineligibility error shows — intentional, since the customer gets the most relevant, actionable message; do **not** stack or merge the two errors. Note the differing field keys are both valid Blesta `setErrors()` shapes: `getCommonError('unsupported')` returns `['' => ['unsupported' => …]]` (empty-string whole-form key) while the currency guard uses `['currency' => ['ineligible' => …]]` (a named field) — this is deliberate, not an inconsistency. Do **not** change `process.pdt` (the error region carries the blocked message; `GatewayPayments::getBuildProcess()` surfaces `$gateway_obj->errors()` to the controller after `buildProcess()` returns). Do **not** add a voucher stub.

### Why read `$this->currency` and not re-derive currency from the invoice

`GatewayPayments::initGateway()` calls `$gateway_obj->setCurrency($currency)` with the Payment Attempt's currency **before** `buildProcess()` (gateway_payments.php:69-85, 156-162), so `$this->currency` is the authoritative active currency the framework intends to charge. Re-deriving it from `$invoice_amounts`/contact data would be redundant and could diverge from what the framework filtered on. Read `$this->currency` (null-safe). The base `Gateway` class is annotated `#[\AllowDynamicProperties]` (gateway.php:16), so the dynamic property `setCurrency()` writes is **not** a PHP 8.2 deprecation — you do **not** need to declare a typed `$currency` property, and you must **not** change `setCurrency()` (NN#6). (If you prefer an explicit `private $currency = null;` declaration for readability, it is allowed only if it stays null-by-default and changes no behavior — but the minimal-diff choice is to leave it dynamic and just read `$this->currency ?? ''`.)

### AC3 — PKR-first policy visibility and the conversion-policy escape hatch

FR-5 reads: "The MVP supports PKR payments only **unless an approved currency conversion policy is configured**." In MVP **no conversion policy is configurable** — `getSettings()` offers only `currency_policy => pkr_only` (kuickpay.php:70-72) and `editSettings()` rejects anything else (`['in_array', ['pkr_only']]`, kuickpay.php:215-221). That is the correct interpretation: the escape hatch is a *future* config, not a hidden MVP path, so there is nothing to conversion-convert and "no USD-to-PKR value is hard-coded" is satisfied by simply never having one. The settings screen already *shows* the policy via the `currency_policy` field + `currency_policy_note`. 1.5 confirms this is visible (optionally adding one always-visible restatement line) and proves the absence of conversion constants — it does **not** build a conversion feature.

### Files being modified — current state and what to preserve

All edits are **UPDATE** (no new production files; one optional new test). Read each in full before editing.

`components/gateways/nonmerchant/kuickpay/kuickpay.php`:
- **Add** one `protected currencyEligible()` helper (Task 1.1) and **extend `buildProcess()` only** — call the helper before the companion check (above). Keep the view construction, `Html` load, and the single `return $this->view->fetch();`.
- `setCurrency`, `getSettings`, `editSettings` (incl. the `currency_policy` rule), `encryptableFields`, `maskCredentials`, `setMeta`, `getSoapClient`, `validate`, `success`, `companionInstalled`, and all 1.4 connection-test methods — **DO NOT change behavior.**

`language/en_us/kuickpay.php`: **add** `Kuickpay.!error.currency_ineligible` (+ any optional AC3 line key). Preserve all other keys, ordering, and quoting.

`views/default/settings.pdt`: change **only** if you add the optional always-visible PKR-policy line (Task 3.1); otherwise leave untouched (the tooltip already satisfies AC3). Do not restructure the Payment Behavior group.

`config.json`: **confirm only — expect no diff.** `process.pdt`, `lib/*`, all `plugins/kuickpay_reconcile/` files, and 3.1/1.4 methods/tests: **unchanged.**

### Previous story intelligence (1.1 + 1.2 + 1.3 + 1.4 + 3.1)

- **1.1 (done):** scaffold, companion-missing guard (`companionInstalled()` + `Kuickpay.!error.companion_missing`), **PKR-only `config.json`** (the native eligibility contract 1.5 relies on), fail-closed `buildProcess/validate/success`. Legacy global `Kuickpay extends NonmerchantGateway` — match it. The 1.1 deferral about `companionInstalled()` on a null `company_id` (deferred-work.md) is unrelated to currency and stays deferred.
- **1.2 (done):** grouped settings form incl. the `currency_policy` select + `Kuickpay.currency_policy_note` (the AC3 visibility surface) and the `currency_policy` `in_array(['pkr_only'])` save rule (the AC3 "no conversion policy selectable" lock). 1.5 confirms both and must not weaken them. [Source: kuickpay.php:70-72, 110-115, 215-221; settings.pdt:160-169]
- **1.3 (done):** credential encryption/masking — untouched here; the guard sends/logs nothing credential-bearing.
- **1.4 (done):** sentinel-gated cURL connection probe + SSRF guard inside `editSettings()` — a different method; 1.5 must not disturb it. Confirms the gateway has no extra admin action hook beyond `getSettings`/`editSettings` and that `editSettings()` cannot `log()` (null `gateway_id`) — irrelevant to `buildProcess()`, which is the customer path, but reinforces "no `log()` here either" (the guard reports via `Input->setErrors()` only). [Source: 1-4 story; deferred-work.md 1-4 entries]
- **3.1 (done):** `lib/KuickPaySoapClient.php`, `getSoapClient()`/`setMeta()` — **not used by 1.5** (no SOAP call in an eligibility check). Untouched.

### Git intelligence

Recent merged work (HEAD `45926c5e`) closes Story 1.4: `chore(kuickpay): record 1.4 review findings and mark done`, `fix(kuickpay): block private-range hosts in connection probe`, `feat(kuickpay): probe wsdl endpoint reachability`, `feat(kuickpay): add safe connection test action`. The Epic 1 gateway surface is otherwise stable; 1.5 layers a small, isolated guard onto `buildProcess()` without disturbing the connection-test or SOAP layers. Follow the repo convention `feat(kuickpay): …` / `fix(kuickpay): …`, imperative, ≤72 chars. Suggested commits: `feat(kuickpay): block non-pkr currencies at checkout`, `feat(kuickpay): add currency-ineligible message`, `test(kuickpay): cover pkr-only eligibility guard`. [Source: git log; project-context.md#Development Workflow Rules]

### Latest tech information

No new libraries, no web research required — all contracts are in-repo and verified. This story uses only PHP 8.2 and Blesta's `Gateway::getCurrencies()`/`NonmerchantGateway`/`Input`/`Language` APIs. No `ext-soap`/`ext-curl` involvement (an eligibility check makes no network/SOAP call). Do not add packages. [Source: project-context.md#Technology Stack; components/gateways/lib/gateway.php:99-105]

### Project Structure Notes

- All edits stay inside `components/gateways/nonmerchant/kuickpay/`. **No new directories.** The only permitted addition is one optional unit test under the existing `tests/` dir (Task 5).
- Architecture ownership boundary respected: the gateway owns "settings UI + encrypted gateway meta + **PKR eligibility** + checkout reference display" (architecture.md:776); durable Voucher/posting state are the plugin (Epic 2/3). 1.5 adds only the PKR-eligibility slice and explicitly defers voucher creation to Epic 2 (behind this guard). [Source: architecture.md:518-526, 765-778]

### References

- [Source: epics.md#Story 1.5, lines 395-415] — user story + AC1/AC2/AC3 (verbatim above).
- [Source: epics.md FR5 (line 33), UX-DR2 (line 154), NFR9 (line 103), NFR10 (line 105); FR Coverage Map line 214 (FR5 → Epic 1)] — PKR-only MVP; method-selection eligibility & no voucher for non-PKR; fail-closed; nothing hard-coded.
- [Source: prd.md (prd-whmcs_blesta-2026-06-09/prd.md) FR-5 lines 126-133] — "PKR-First Currency Policy": non-PKR blocked/routed-away with a clear message; no USD-to-PKR value hard-coded in business logic; currency behavior visible in Admin Settings.
- [Source: architecture.md:81 (PKR-first MVP), :83 (no hard-coded conversion rates), :518-526 (ownership boundaries), :776 ("Gateway: … PKR eligibility …"), :765-778] — gateway owns PKR eligibility; no hard-coded conversion.
- [Source: components/gateways/lib/gateway.php:99-105 (`getCurrencies()`), :16 (`#[\AllowDynamicProperties]`), :449-454 (`loadConfig`)] — config-sourced currencies; dynamic `$currency` allowed.
- [Source: app/models/gateway_manager.php:778-781 (`currencyExists`), :310-360 (`getAllInstalledNonmerchant`), :234-298 (`getInstalledNonmerchant`)] — the native `in_array(currency, getCurrencies())` predicate and the `gateway_currencies` inner-join that hides non-PKR.
- [Source: components/gateway_payments/gateway_payments.php:69-85 (`initGateway` → `setCurrency`), :133-170 (`getBuildProcess`)] — currency is set on the gateway before `buildProcess()`; the listing is currency-filtered.
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php:51-54 (`setCurrency`), :62-86 (`getSettings`), :110-115/215-221 (`currency_policy` default + rule), :534-546 (`buildProcess`), :601-606 (`companionInstalled`)] — the as-built gateway surface 1.5 touches/preserves.
- [Source: components/gateways/nonmerchant/kuickpay/config.json:11-13] — `"currencies": ["PKR"]` (the native eligibility declaration; expect no diff).
- [Source: components/gateways/nonmerchant/kuickpay/views/default/settings.pdt:160-169; language/en_us/kuickpay.php:7,41-43,90] — AC3 visibility (`currency_policy` field + note); neutral `process.not_ready`; last existing key.
- [Source: components/gateways/lib/nonmerchant_gateway.php:221-251 (`getCommonError`)] — the error-shape pattern the companion-missing guard uses (1.5 uses a gateway-specific localized key instead, per AC2 "clear localized copy").
- [Source: ux EXPERIENCE.md lines 25,28,61,80-81] — "Payment Method Selection" shows KuickPay only for an eligible PKR invoice; "Non-PKR Blocked" surface explains unavailability; non-PKR → hidden or blocked, no Voucher.
- [Source: project-context.md] — PHP 8.2; legacy global class style; Loader/Input/Language conventions; language-file rule; no core edits; commit convention; testing-honesty rule.
- [Source: sprint-status.yaml#BUILD ORDER + development_status] — Track A 1-1→…→**1-5**; Epic 1 parallel with Phase 0; 1-1..1-4 done; 1-5 is the final Epic 1 story and fully unblocked.
- [Source: deferred-work.md] — 1-4 PHPUnit runner caveat (`--bootstrap tests/bootstrap.php tests`); prior deferrals are unrelated to currency eligibility.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

- 2026-06-09: Story **created** (ready-for-dev) via bmad-create-story against baseline `45926c5e` (post-1.4). Exhaustive context-engine analysis across epics (Story 1.5 ACs, FR5/UX-DR2/NFR9/NFR10), PRD (FR-5 PKR-First Currency Policy), architecture (gateway owns "PKR eligibility", no hard-coded conversion, ownership boundaries), the predecessor 1.1–1.4 stories + 3.1, and **verified Blesta internals** — `Gateway::getCurrencies()`/`loadConfig`/`#[\AllowDynamicProperties]`, `GatewayManager::currencyExists`/`getAllInstalledNonmerchant`/`getInstalledNonmerchant` (the `gateway_currencies` inner-join), and `GatewayPayments::initGateway`/`getBuildProcess` (currency set via `setCurrency()` before `buildProcess()`). **Load-bearing design decision:** Blesta already *hides* KuickPay from non-PKR invoices natively (config `currencies: ["PKR"]` + the currency-filtered listing), so 1.5's net-new work is a **fail-closed PKR-eligibility guard inside `buildProcess()`** (defense-in-depth, sourcing PKR from `getCurrencies()` so nothing is hard-coded) plus confirming AC3 settings visibility and the absence of conversion constants. Voucher creation is explicitly deferred to **Epic 2 (Story 2.3)**, which must wire `InsertVoucher` **behind** this guard. Confirmed: the `currency_policy` save rule already locks the value to `pkr_only` (no conversion policy is configurable in MVP), and no USD-to-PKR/exchange-rate constant exists in gateway/`lib/` business logic.

- 2026-06-09: **Multi-agent validation triage applied** (round 1 synthesis; every finding re-verified against the live code before editing). All reviews returned ready-for-dev with **0 critical code blockers**. Net changes: (1) **extracted a pure `protected currencyEligible()` helper** as the eligibility decision and the Task 5 unit-test target — `buildProcess()` builds a view and calls the `private` `companionInstalled()`, so testing it directly would need view/`Loader`/`PluginManager`/`Configure` stubs with no repo precedent; the helper needs only `$this->currency` + `getCurrencies()`, making AC1/AC2 cleanly testable (the existing 1.4/3.1 tests exercise view-free `protected` seams only). (2) **Reconciled the error-ordering wording** — the `elseif` is canonical (on non-PKR **and** companion-missing, only the currency error shows; do not stack), and documented that the two valid `setErrors()` field-key shapes (`''` vs `'currency'`) differ deliberately. (3) **Fixed Task 6.3's `grep "PKR"` expectation** — `PKR` legitimately appears in `tests/` fixtures (verified `KuickPaySoapClientTest.php`), so the hard-coded-currency check is scoped to `kuickpay.php` + `lib/` only. (4) **Hedged the PHP-runtime assumptions** (no host-version claim; explicit "if no `php` on PATH, state it"). (5) **Test bootstrap guidance** — the eligibility test follows the 1.4 **self-contained** pattern (`require_once ../kuickpay.php`; no `tests/bootstrap.php`) and must override `getCurrencies()` since the in-test `NonmerchantGateway` stub is empty. (6) **Added the empty-`getCurrencies()` fail-closed note** (config-load failure blocks all incl. PKR — correct; do **not** add a PKR fallback). (7) **Corrected** the claim that an "explicit gateway-id call" bypasses native filtering — `getBuildProcess()` passes `$currency` to both listing paths, so it is filtered too; residual reach-paths narrowed to DB misconfig / multi-currency edge / unit seam / Epic 2 wiring. (8) Added the exact `$lang[…]` line for Task 4.1 and fixed the language-file line count (90, not 91). Pure token-trimming suggestions (collapse References, "quick-ref summary") were **declined** — the exhaustive context-engineering style is intentional and matches the Epic 1 precedent. No design change; the guard logic was confirmed correct and fail-closed by all reviewers.

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **"Hidden" vs "blocked-with-copy" as the customer experience.** Natively, a non-PKR invoice simply won't list KuickPay (hidden) — the cleanest UX, and what most customers will see. The `buildProcess()` guard's blocked-with-copy message only surfaces if the gateway is reached with a non-PKR currency anyway (explicit gateway selection, a `gateway_currencies` misconfiguration, or a future multi-currency edge). **Recommended:** ship both layers as designed (hidden by default, blocked-with-copy as the fail-closed backstop). If the team wants a *visible* "Non-PKR Blocked" explainer on the method-selection screen even when KuickPay is hidden (per the UX "Non-PKR Blocked" surface, EXPERIENCE.md:28), that requires touching the Blesta client pay view (core/order plugin) and is out of this gateway-only story's scope — raise it as an Epic 2 customer-UX item.
2. **Optional always-visible AC3 policy line.** The PKR-first policy is currently shown via the `currency_policy` field + tooltip. Task 3.1 leaves it to dev judgment whether to add one always-visible `form-text` restatement. **Recommended:** the existing field + note is sufficient for "the PKR-first policy is visible"; add the extra line only if review wants it more prominent — keep it language-keyed and minimal.
3. **`gateway_currencies` assignment in a fresh install.** AC1 ("KuickPay can appear for a PKR invoice") depends on the admin assigning PKR on the manage screen and PKR being a company currency. 1.5 does not auto-assign currencies (that's standard Blesta admin setup, not gateway code). **Recommended:** document the "enable KuickPay → assign PKR" step in the Epic 5 deployment runbook (Story 5.2 already lists "PKR enablement"); no code action in 1.5.
4. **Currency-code casing / normalization.** The guard compares `$this->currency` strictly against `getCurrencies()` (`["PKR"]`). Blesta stores/passes ISO 4217 codes upper-cased, so a strict match is correct and mirrors core `currencyExists()`. **Recommended:** keep the strict compare; if any environment is found passing lower-case codes (none observed), normalize with `strtoupper()` at the compare site only — do not mutate `$this->currency`.
