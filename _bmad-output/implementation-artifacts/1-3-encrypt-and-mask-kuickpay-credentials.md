# Story 1.3: Encrypt and Mask KuickPay Credentials

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin operator,
I want KuickPay credential passwords encrypted and masked,
so that sensitive gateway access is protected during setup, diagnostics, and support.

## Acceptance Criteria

(Reproduced verbatim from [Source: epics.md#Story 1.3, lines 351-371].)

**AC1 — Credentials encrypted at rest**
**Given** voucher and inquiry passwords are configured
**When** Blesta stores gateway meta
**Then** password fields are included in `encryptableFields()` or equivalent Blesta-supported encryption.

**AC2 — Credentials masked/redacted on every surface**
**Given** settings, logs, diagnostics, fixtures, docs, or exception messages are displayed
**When** credential fields are present
**Then** password values are masked or redacted
**And** raw credential values never appear.

**AC3 — Same-as-voucher avoids duplicate storage; display stays masked**
**Given** the same-as-voucher credential toggle is enabled
**When** inquiry settings are saved
**Then** duplicate password storage is avoided where the Blesta setting pattern allows
**And** display remains masked.

> **What this story actually changes** (read before scoping): Story 1.2 already (a) added `voucher_password` / `inquiry_password` to `encryptableFields()` and (b) renders the password fields **empty** (never echoes the secret). So AC1 lands with 1.2; **1.3's net new work is**: (1) **lock/verify** the encryption contract with a regression test (AC1); (2) **remove duplicate inquiry-credential storage** when the same-as-voucher toggle is on (AC3); (3) **add a masked "a credential is stored" indicator** to the settings form without exposing the value (AC2/AC3 "display remains masked"); (4) **establish the gateway's credential-redaction boundary** — a reusable mask applied to anything the gateway logs or surfaces in diagnostics/exceptions (AC2 "logs, diagnostics, exception messages"). See **Dev Notes → "Scope: what 1.3 owns vs what later stories own."**

## Dependency & starting-state (read FIRST)

**Story 1.2 is `done` and merged — the gateway is in the post-1.2 state** (confirmed at story-authoring time). [Source: sprint-status.yaml `1-2-configure-kuickpay-gateway-settings: done`; git log `feat(kuickpay): render grouped settings form` / `validate gateway settings` / `encrypt credential settings`]

1.3 edits the **same three files** 1.2 built out. As-built state you are starting from (read each in full before editing):

- `kuickpay.php` — `getSettings()` at **lines 48-70** (builds `currency_policy`/`fee_policy` option arrays; passes `meta` + `companion_installed`). `editSettings()` at **lines 78-248**: checkbox defaulting → single-option normalization → **whitespace-trim of `voucher_username`/`inquiry_username`/`institution_id`** → `$same` computed at **line 109** → `$rules` (line 113) → conditional inquiry rules when `!$same` (lines 209-224) → checkbox `in_array` rules → `setRules`/`validates` → `return $meta`. `encryptableFields()` at **lines 255-258** returns `['voucher_password', 'inquiry_password']`.
- `settings.pdt` — companion-missing danger branch (lines 1-4) + grouped fields in the `else` branch. `voucher_password` rendered empty at **line 37**, `inquiry_password` at **line 69** (both `fieldPassword($name, [...attrs])`, no `value`). The inquiry username+password block (lines 52-71) is **always rendered** — 1.2 added **no** JS show/hide tied to `inquiry_same_as_voucher`.
- `language/en_us/kuickpay.php` (82 lines) — all field/label/note/error/group/option keys present. `Kuickpay.voucher_password_note` (line 18) **already says** "Re-enter it when saving settings."; `inquiry_password_note` (line 24) does not.

Two 1.2 follow-up fixes are already in place and **must be preserved**: whitespace-only required-identifier rejection (`trim(...)`, lines 101-107) and `/D`-anchored regexes (`/^([0-9]+)?$/D`, `/^[A-Za-z0-9_{}+\-]+$/D`, lines 110-111). Do not revert them.

## Non-Negotiables (read before any task)

1. **Never echo, hint, or transmit a stored credential into any page.** Preserve 1.2 Non-Negotiable #1: render `voucher_password` / `inquiry_password` by **omitting any `value` key** from `fieldPassword($name, $attributes = [], $label = null)`. Do **not** put the stored secret (plaintext **or** ciphertext) into a hidden field, data attribute, JS variable, tooltip, or placeholder. The masked "is-stored" indicator (Task 3) is a **boolean-derived, fixed-length** mask — it must not reveal the value **or its length**. [Source: project-context.md "Do not expose secrets"; architecture.md:51, 437, 608; prd.md FR-3]

2. **Mask before you log — every time, no exceptions.** Any data the gateway passes to `$this->log(...)`, embeds in an exception/error message, or writes to a diagnostic must first pass through the credential mask (Task 4). The base `Gateway` class already provides `maskData()`/`maskDataRecursive()` — use them; do not invent a parallel masker. Architecture mandates a **single redaction boundary**: "All SOAP diagnostics, exceptions, retry records, logs, and audit fields pass through a single redaction boundary." 1.3 establishes the **gateway-owned credential** slice of that boundary. [Source: architecture.md:373, 315, 656; components/gateways/lib/gateway.php:307-322; eway.php:576-593]

3. **Do not store the same secret twice.** When `inquiry_same_as_voucher === 'true'`, `editSettings()` must **not persist** `inquiry_username` / `inquiry_password` (AC3 "duplicate password storage is avoided"). Remove those keys from the returned `$meta` so `gateway_meta` holds exactly one credential pair. Downstream consumers (Epic 3 inquiry path) read the voucher credentials when the toggle is on — document this contract; do not build the consumer here. [Source: epics.md Story 1.3 lines 368-371; architecture.md:371 "plugin must not duplicate gateway passwords"]

4. **Keep credentials encrypted at rest.** `encryptableFields()` must return `['voucher_password', 'inquiry_password']`. Blesta auto-encrypts these on save and auto-decrypts into `$meta` on load (`gateway_manager.php:626-633` write path, `:794-799` read path). Do **not** hand-encrypt in `editSettings()` or hand-decrypt in `getSettings()` — that double-encrypts. [Source: app/models/gateway_manager.php:626-633, 789-832]

5. **Passwords stay required; do NOT attempt "leave blank to keep".** `editSettings(array $meta)` receives **only the submitted POST**, never the gateway's currently-stored meta, and the gateway instance has **no `gateway_id`** during edit (`gateway_manager::edit()` calls `editSettings()` on a fresh `loadGateway()` instance with no `setGatewayId()`/`setMeta()`), and `setMeta()` **deletes-all-then-reinserts** — so a blank password cannot "keep" the old value; it would erase it. Keep-if-blank is **architecturally infeasible here without a core change** — do not hack it (no gateway-loads-its-own-meta, no hidden ciphertext field). Accept re-entry-on-save as the MVP behavior (see Open Question #1). [Source: app/models/gateway_manager.php:599-650, 814-832; admin_company_gateways.php:185-220, 287]

6. **All admin-facing strings come from language files.** Every label, note, indicator, and error is a `$lang['Kuickpay.*']` key via `$this->_(...)` / `Language::_(...)`. No hard-coded English in the `.pdt` or PHP. Preserve the existing single-quote / one-key-per-line style; do not rewrap or reorder 1.2's keys. [Source: project-context.md#Language-Specific Rules; epics.md UX-DR28 line 206]

7. **Stay in scope; no regressions.** Touch **only** the three gateway files. Preserve the Story 1.1 companion-missing guard and the Story 1.2 form/validation. Do **not** add: SOAP client / `lib/` classes, the protocol-library redactor, the connection-test button, customer-facing changes, plugin code, schema, cron, or core edits. No file under `plugins/kuickpay_reconcile/` changes. Keep `buildProcess/validate/success` fail-closed. Match parent signatures; target **PHP 8.2** (no 8.3+ syntax). [Source: architecture.md:765-778; sprint-status.yaml#BUILD ORDER; project-context.md "Preserve inherited Blesta method signatures"]

## Tasks / Subtasks

- [ ] **Task 1 — Lock credential encryption at rest + regression guard (AC1)** [Source: app/models/gateway_manager.php:626-633; coingate encryptableFields]
  - [ ] 1.1 Confirm `encryptableFields()` (lines 255-258) still returns exactly `['voucher_password', 'inquiry_password']` and leave it intact. (1.2 delivered this; 1.3 locks it with the Task 6.1 regression test.)
  - [ ] 1.2 Do **not** add `voucher_username`/`inquiry_username` to `encryptableFields()` — usernames are not the AC1-encrypted set, and adding them changes 1.2's stored shape. (Username log-masking is handled separately in Task 4; see Dev Notes "Which fields are 'credentials'.")
  - [ ] 1.3 Add the regression assertion in Task 6 that both password keys are present and no plaintext password is written to `gateway_meta`.

- [ ] **Task 2 — Avoid duplicate credential storage for same-as-voucher (AC3)** [Source: epics.md Story 1.3 lines 368-371; 1-2 editSettings]
  - [ ] 2.1 In `editSettings()`, `$same` is **already computed at line 109** — reuse it; do not recompute.
  - [ ] 2.2 Immediately after line 109 (`$same = ...`) and **before** `$rules` is built (line 113), when `$same` is true **unset** `inquiry_username` and `inquiry_password` from `$meta` so they are never persisted as a duplicate of the voucher pair. Placing it before `$rules`/`validates` means the now-absent keys are not validated. (1.2 already skips the conditional inquiry-credential **required** rules when `$same` at lines 209-224; 1.3 goes further and physically drops the keys so `gateway_meta` stores one pair.) `unset()` on an already-absent key is a **safe no-op** in PHP (e.g. first save where the inquiry keys were never submitted) — do **not** add an `isset()`/`array_key_exists()` guard. See Dev Notes "editSettings change (AC3) — exact diff."
  - [ ] 2.3 When `$same` is false, keep 1.2's behavior: `inquiry_username`/`inquiry_password` are required-non-empty and stored.
  - [ ] 2.4 Document (code comment + Dev Notes) the downstream contract: "When `inquiry_same_as_voucher === 'true'`, the inquiry operation uses `voucher_username`/`voucher_password`. Epic 3 inquiry code must honor this; the inquiry meta keys are intentionally absent." Do **not** build the consumer.

- [ ] **Task 3 — Masked credential display in the settings form (AC2/AC3 "display remains masked")** [Source: admin_company_gateways.php:180, 287 (getSettings receives current decrypted meta on GET); 1-2 settings.pdt]
  - [ ] 3.1 In `getSettings(array $meta = null)`, compute two **booleans** (not values) and pass them to the view: `$voucher_password_stored = !empty($meta['voucher_password']);` and `$inquiry_password_stored = !empty($meta['inquiry_password']);`. Pass via `$this->view->set('voucher_password_stored', $voucher_password_stored);` (and the inquiry one). These read the current decrypted meta Blesta supplies on GET, but only as a presence test. **Never** pass `$meta['voucher_password']` itself to the view.
  - [ ] 3.2 In `settings.pdt`, render a masked status note **inside** each password field's existing `<div class="mb-3">`, immediately **after** the `fieldPassword(...)` `?>` and **before that block's closing `</div>`** — i.e. between line 38 (`?>`) and line 39 (`</div>`) for `voucher_password`, and between line 70 (`?>`) and line 71 (`</div>`) for `inquiry_password`. Do **not** place it after the `</div>` (it would fall outside the field group and break layout). Render it **only when the corresponding `*_stored` boolean is true**, e.g. `<?php if (!empty($voucher_password_stored)) { ?><div class="form-text">••••••••&nbsp;<?php $this->_('Kuickpay.voucher_password_stored'); ?></div><?php } ?>`. The mask glyphs are **literal, fixed-length** (8 chars) — never derived from the stored value or its length (Non-Negotiable #1).
  - [ ] 3.3 Keep both password fields **empty and required** exactly as 1.2 renders them (`fieldPassword('voucher_password', ['id'=>'voucher_password','class'=>'form-control'])`, no `value` key). Do not change the field rendering; only **add** the adjacent status note.
  - [ ] 3.4 Gate the inquiry status note on `$inquiry_password_stored` only. (1.2 renders the inquiry block **unconditionally** — there is no JS show/hide. When same-as-voucher is on, Task 2 unsets `inquiry_password`, so `$inquiry_password_stored` is false and no misleading "stored" note shows. Self-consistent — do not add new show/hide behavior.)
  - [ ] 3.5 **Honesty on POST-validation failure (important — the booleans are NOT always false here).** On a failed save the controller re-renders `getSettings($vars)` with `$vars = $this->post` (admin_company_gateways.php:215-216, 287), so `$meta` is the **submitted** array. The gateway's `getSettings()` receives only that array — it has **no request-method context** and cannot distinguish a GET (decrypted stored meta) from a failed-POST re-render (submitted values). Because passwords are **required on every save**, the common case "admin typed a password but a *different* field failed validation" leaves `!empty($meta['voucher_password'])` **true** → the note renders even though nothing was persisted (e.g. on first configuration). This leaks no value or length (fixed 8-glyph mask), but the language copy must therefore **not** assert "currently stored." Word the Task 5.1 key so it is truthful whether the value is already stored (GET) or freshly typed but not yet saved (failed POST): "the password is hidden for security; (re-)enter it to save any settings change," not "a credential is currently stored." Gating the note to GET-only is **infeasible here** for the same reason keep-if-blank is (the gateway sees only `$meta`, no request object) — do not attempt a core change for it. Use `!empty(...)` throughout (null-safe; `getSettings(null)` on first install yields false → no note).

- [ ] **Task 4 — Establish the gateway credential-redaction boundary (AC2 "logs, diagnostics, exception messages")** [Source: components/gateways/lib/gateway.php:307-322; eway.php:576-593; paypal_payments_standard.php:652; architecture.md:373]
  - [ ] 4.1 Add a `private` constant or property listing the credential field names to mask: `private $credential_mask_fields = ['voucher_password', 'inquiry_password', 'voucher_username', 'inquiry_username', 'password', 'userName', 'Password', 'UserName'];` — include both the **stored meta keys** and the likely **SOAP request keys** (`userName`/`password` per addendum A.2) so the same boundary covers Epic 3's request payloads. (Usernames ARE masked **in logs/diagnostics** even though they are not encrypted at rest — defense in depth; see Dev Notes "Which fields are 'credentials'.")
  - [ ] 4.2 Add `protected function maskCredentials(array $data) { return $this->maskDataRecursive($data, $this->credential_mask_fields); }` — a thin wrapper over the base-class primitive (default mask = full value masked, `mask_char='x'`, `unmask_length=0`). **Declare it `protected`, not `private`** — matching the base-class `maskData`/`maskDataRecursive` visibility (both `protected`, verified `gateway.php:307,342`) — so it is reachable from the Task 6.3 test subclass and from any in-class logging the gateway adds later. **Wrap `maskDataRecursive` (not flat `maskData`)** so the same boundary safely covers **nested** SOAP request/response payloads (Epic 3's eventual consumers, the reason `userName`/`password` are pre-listed) as well as the flat `gateway_meta` array — flat `maskData` would silently leave deep credential keys unmasked. PHPDoc the return as `@return array`. This is the **gateway-owned credential boundary**; all gateway logging/diagnostics MUST route through it. Mirror the in-repo idiom `serialize($this->maskData($params, $mask_fields))` used by eway/payflow (substituting the recursive variant here). Note: `unmask_length=0` masks to `str_repeat('x', strlen($value))`, so redacted **log** output preserves the secret's *length* — acceptable for diagnostics (defense-in-depth, matches the in-repo idiom) and deliberately distinct from the Task 3 form indicator, which is fixed-length and reveals neither value nor length.
  - [ ] 4.3 Do **not** add any `$this->log(...)` call in this story (no live request path exists until 1.4/Epic 3). The method is the **contract** for the gateway's own logging/diagnostics/exceptions. Add a clear PHPDoc on `maskCredentials()` stating: "All KuickPay credential-bearing data the **gateway itself** logs, embeds in an exception/error message, or writes to a diagnostic must pass through this first." State its consumer precisely: the gateway's own logging, **including Story 1.4's connection-test logging if that test is added in-class on this gateway**. Do **not** claim Epic 3's SOAP client consumes this method: the SOAP client and its redactor are a **separate** protocol-library class (`lib/KuickPaySoapClient.php`, the `redactor` per architecture.md:405,778 — Epic 3), a consistent second layer that redacts SOAP XML on its own. A `protected` gateway method is not (and should not be) called cross-class by that library; the two layers are intentionally separate (see Dev Notes scope table). [Source: architecture.md:373, 778; :405]
  - [ ] 4.4 Audit existing gateway error paths for credential leakage: `buildProcess`/`validate`/`success` use `getCommonError(...)` (safe, language-keyed) and must stay that way. Confirm no method concatenates `$meta['*_password']` (or any credential) into an error/exception/log string. If you add any new error copy, it must be a language key and contain no credential.

- [ ] **Task 5 — Language strings (AC2)** [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php; project-context.md language rules]
  - [ ] 5.1 Add `$lang['Kuickpay.voucher_password_stored']` and `$lang['Kuickpay.inquiry_password_stored']` for the masked status notes (Task 3.2), placed near the credential keys (after line 24). Copy must be honest about re-entry, reveal nothing, **and stay true whether the value is already stored (GET) or freshly typed but not yet saved (failed-POST re-render — see Task 3.5)** — so do **not** assert "currently stored." E.g. `'The voucher password is hidden for security. Re-enter it to save any settings change.'`
  - [ ] 5.2 `Kuickpay.voucher_password_note` (line 18) already says "Re-enter it when saving settings." — leave it. Optionally align `Kuickpay.inquiry_password_note` (line 24) to note the value is encrypted and re-entered; only if it reads cleanly — no churn.
  - [ ] 5.3 Preserve every existing key and the file's single-quote / one-key-per-line style; do not reorder or rewrap 1.2's block.

- [ ] **Task 6 — Tests (AC1, AC2, AC3) — targeted, no live external calls** [Source: project-context.md#Testing Rules; architecture.md:751-752 redaction test fixture]
  - [ ] 6.1 **encryptableFields regression (AC1):** assert `(new Kuickpay())->encryptableFields()` returns **exactly** `['voucher_password', 'inquiry_password']` — both password keys present, **no** username keys, and no extra keys (assert `count() === 2`). (Pure, no DB.)
  - [ ] 6.2 **same-as-voucher dedupe (AC3):** call `editSettings()` with `inquiry_same_as_voucher='true'` + a full valid meta set, assert the returned `$meta` has **no** `inquiry_username`/`inquiry_password` keys and **no** validation errors; call again with the toggle `'false'` and blank inquiry creds, assert validation errors are set (required path preserved).
  - [ ] 6.3 **credential redaction (AC2):** call `maskCredentials(['voucher_password'=>'s3cr3t','userName'=>'kp_user','InstitutionID'=>'123'])` and assert the password/username values are fully masked while non-credential keys (`InstitutionID`) pass through unchanged. Because `maskCredentials()` is **`protected`** (Task 4.2), a tiny test subclass of `Kuickpay` can expose it via a public pass-through (`public function exposeMask($d) { return $this->maskCredentials($d); }`); reflection is an equally valid alternative. (A `private` method would be invisible to the subclass and force reflection — another reason 4.2 declares it `protected`.) Add one **nested**-input assertion (e.g. `['Envelope'=>['Body'=>['password'=>'s3cr3t']]]`) proving the recursive variant masks deep credential keys. Confirms the boundary works before any consumer exists.
  - [ ] 6.4 **Where these tests live (no committed test files in 1.3's scope):** this checkout has **no** sibling `../tests` suite and **no** `components/gateways/nonmerchant/kuickpay/tests/` layout. Do **not** create a root `tests/` directory (project-context.md#Testing Rules forbids it) and do **not** commit a new test file — Task 7.5's scope gate allows only the three gateway files + the story file + `sprint-status.yaml`. If a PHP runtime is available, run the 6.1–6.3 checks from a **disposable** script (e.g. `temp/kuickpay-1.3-tests.php`) via `php` from the project root, then **delete it** before finishing. If the runtime is unavailable, run the narrowest safe fallback (`php -l` + the grep proofs in Task 7) and **state explicitly** that DB/runtime coverage did not run. Do not overstate, and do not present lint/grep as full coverage. [Source: project-context.md#Testing Rules lines 68, 76]

- [ ] **Task 7 — Verification (no overstating)** [Source: project-context.md#Development Workflow Rules]
  - [ ] 7.1 `php -l` the three touched files (`.php`, `.pdt`, language `.php`).
  - [ ] 7.2 **No secret echo / no value leak.** A blanket grep for `$meta['*_password']` would also match the **intended** `!empty(...)` presence tests Task 3.1 adds — so split the proof so it cannot flag its own safe reads:
    ```sh
    # (a) Leak check — any password-value read NOT guarded by empty(): expect NO output
    grep -nE "\$meta\['(voucher|inquiry)_password'\]" \
      components/gateways/nonmerchant/kuickpay/kuickpay.php \
      components/gateways/nonmerchant/kuickpay/views/default/settings.pdt \
      | grep -vE "empty\(\s*\$meta\['(voucher|inquiry)_password'\]"
    # (b) Presence check — the ONLY reads are the two !empty() tests in getSettings(): expect exactly 2
    grep -nE "!empty\(\s*\$meta\['(voucher|inquiry)_password'\]\)" \
      components/gateways/nonmerchant/kuickpay/kuickpay.php
    # (c) The .pdt renders the masked note from the booleans, never the value: expect the *_stored vars only
    grep -nE "voucher_password_stored|inquiry_password_stored" \
      components/gateways/nonmerchant/kuickpay/views/default/settings.pdt
    ```
    Confirm (a) is empty, (b) returns exactly two lines, and the `.pdt` never references a password value — only the `*_stored` booleans.
  - [ ] 7.3 **Mask boundary present:** `grep -nE "maskCredentials|maskData|credential_mask_fields" components/gateways/nonmerchant/kuickpay/kuickpay.php` — confirm the wrapper exists and lists the password keys.
  - [ ] 7.4 **Dedupe present:** `grep -nE "unset\(\\\$meta\['inquiry_(username|password)'\]\)" components/gateways/nonmerchant/kuickpay/kuickpay.php` — confirm the same-as-voucher unset.
  - [ ] 7.5 **Scope containment:** `git status --porcelain` shows only the three gateway files + this story file + `sprint-status.yaml`; **no** `plugins/kuickpay_reconcile/` changes, **no** new `lib/` files. `find components/gateways/nonmerchant/kuickpay -type d -name lib` → expect no output.
  - [ ] 7.6 If a running Blesta + MySQL stack is available: open Settings → Payment Gateways → KuickPay; save with same-as-voucher ON → confirm `gateway_meta` has **no** `inquiry_password` row and the voucher password row is **encrypted** (ciphertext, not plaintext); reopen → password fields blank, masked "stored" note shown; toggle same-as-voucher OFF, save blank inquiry creds → rejected. If no runtime/DB, **state that explicitly** and rely on lint + grep + unit tests. [Source: NFR12 line 109]

## Dev Notes

### Scope: what 1.3 owns vs what later stories own

| Surface in AC2 | Owned by 1.3? | Where it actually lands |
|---|---|---|
| **Settings display** masked | ✅ Yes | Empty fields (1.2) + masked "stored" note (Task 3) |
| **Encryption at rest** | ✅ Verify/lock | `encryptableFields()` (delivered 1.2, locked here Task 1) |
| **Same-as-voucher dedupe** | ✅ Yes | `editSettings()` unset (Task 2) |
| **Gateway credential mask for logs/diagnostics/exceptions** | ✅ Establish boundary | `maskCredentials()` wrapper (Task 4) — *contract only; no live log call yet* |
| **SOAP/XML diagnostics redactor** (the protocol-library "single redaction boundary") | ❌ No | **Epic 3 / Story 3.2** (`redactor` protocol class) [Epic-3 ownership per epics.md/sprint-status.yaml; architecture.md:778 "Protocol library: SOAP client, parser, evidence object, redactor", :397 SOAP→redactor→parser] |
| **Connection-test logging** | ❌ No | **Story 1.4** (routes its logging through `maskCredentials()` *if added in-class on this gateway*; not a cross-class caller) |
| **Fixtures sanitized of credentials** | ❌ No | **Story 0.1** (owns sanitized fixtures — `in-progress` at 1.3 authoring time, not yet done). 1.3 creates **no** fixture/doc surfaces; if implementation incidentally touches any, scan them for credential leakage. |
| **Deployment/ops docs credential guidance** | ❌ No | **Epic 5** (5.2/5.4) |

**Why establish `maskCredentials()` now even though nothing logs yet:** the story is literally "encrypt **and mask**," AC2 names logs/diagnostics/exceptions, and 1.4 (the very next story) adds the connection test that logs to KuickPay. Shipping the boundary + test now means 1.4 and Epic 3 plug into a ready, tested contract rather than re-deriving masking. It is a **thin wrapper over the base-class `maskData()`** (already present), not a new redactor — so it does not pre-empt or duplicate Epic 3's protocol-library redactor; the two are consistent layers (gateway credentials vs SOAP evidence). [Source: architecture.md:373, 490 "Implement settings, credential encryption, and redaction"]

### Files being modified — current state and what to preserve

All three files are **UPDATE** (none new). Read each in full before editing.

`components/gateways/nonmerchant/kuickpay/kuickpay.php`:
- `__construct()`, `setMeta()`, `setCurrency()`, `buildProcess()`, `validate()`, `success()`, `companionInstalled()` — **DO NOT TOUCH** (fail-closed payment path + reused companion guard).
- `getSettings()` (lines 48-70) — **extend** (Task 3.1): add the two `*_stored` booleans to the view; keep 1.2's option-array passing and companion check intact.
- `editSettings()` (lines 78-248) — **extend** (Task 2): add the same-as-voucher unset after line 109; keep 1.2's defaulting, normalization, whitespace-trim, `$rules`, and `setRules/validates` intact.
- `encryptableFields()` (lines 255-258) — **verify** returns both password keys (Task 1).
- **Add** `maskCredentials()` + `$credential_mask_fields` (Task 4).

`views/default/settings.pdt`: keep the `!$companion_installed` danger branch and 1.2's grouped fields. **Add only** the masked status notes next to the two password fields. Do **not** add a `<form>` tag (Blesta wraps gateway output in its own form). Do not touch `process.pdt`.

`language/en_us/kuickpay.php`: **add** the two `*_stored` keys; optionally refine the two password `_note` keys. Preserve all other keys, ordering, and quoting.

### editSettings change (AC3) — exact diff

`$same` already exists at **line 109**. Insert the dedupe on the next line, **before** `$rules = [` (line 113) — do not recompute `$same`:

```php
$same = (($meta['inquiry_same_as_voucher'] ?? 'false') === 'true'); // existing line 109
// AC3: when reusing voucher credentials for inquiry, do NOT persist a duplicate
// credential pair. Drop the keys so gateway_meta stores exactly one pair.
// Downstream (Epic 3 inquiry) reads voucher_* when inquiry_same_as_voucher === 'true'.
if ($same) {
    unset($meta['inquiry_username'], $meta['inquiry_password']);
}
```

1.2 already guards the inquiry-credential **required** rules with `if (!$same)` (lines 209-224), so once the keys are unset they are neither validated nor stored. Returning `$meta` (now without the inquiry keys) is what `gateway_manager::edit()` converts to `gateway_meta` rows (`:627-635`) — absent key ⇒ no row ⇒ no duplicate. `setMeta()` does a full delete+reinsert (`:814-832`), so simply not returning the keys is sufficient and clean.

### Masked display (AC2/AC3) — how, and why this is honest

`getSettings($meta)` is the only credential-adjacent method that sees **current decrypted meta** (the controller passes `numericToKey($gateway_info->meta)` on GET — `admin_company_gateways.php:180,287`). We exploit that for a **presence test only**: `!empty($meta['voucher_password'])` → render a fixed 8-glyph mask + a note. We never pass the value or its length to the view, so there is no length oracle and no value exposure (Non-Negotiable #1).

**One caveat the note copy must respect (Task 3.5):** on a failed save the controller re-renders `getSettings($this->post)` (`admin_company_gateways.php:215-216,287`), and the gateway **cannot distinguish that submitted array from GET-time stored meta** — it has no request context. If the admin typed a password but another field failed validation, the presence test is **true on a value that was never persisted**. No value/length leaks (fixed mask), but the copy must therefore avoid claiming the credential is "currently stored." The honest framing — true in both GET and failed-POST — is "the password is hidden for security; re-enter it to save any settings change." Because keep-if-blank is infeasible (Non-Negotiable #5), the password is **required on every save** anyway, so "re-enter to save" is always accurate. This satisfies AC3's "display remains masked" (we display a mask + status, never the secret) and AC2's "raw credential values never appear." GET-only gating would remove the imprecision but is infeasible without a core change (the gateway sees only `$meta`) — out of scope, same root cause as keep-if-blank.

### Which fields are "credentials" — encryption set vs masking set

- **Encrypted at rest (AC1):** `voucher_password`, `inquiry_password` **only**. Matches 1.2's stored shape; usernames are not in `encryptableFields()`. Adding usernames would silently change which rows are encrypted and is out of AC1's "password fields" scope.
- **Masked in logs/diagnostics (AC2):** passwords **and** usernames (and the SOAP-side `userName`/`password` keys per addendum A.2). Usernames are not secret-at-rest but should not appear in shared diagnostics/audit — defense in depth, and the base `maskData()` cost is trivial. This split is deliberate: encrypt the secrets, redact the broader credential surface from logs.

### Keep-if-blank rotation — why it's out, with proof

The 1.2 story speculated 1.3 would add "keep-if-blank / rotation-on-blank." After tracing the gateway-edit path, that is **not implementable without a Blesta core change**:

- `admin_company_gateways::manage()` posts raw `$this->post` as `meta` (`:209`); on save it calls `GatewayManager->edit($id, ['meta' => $this->post])`.
- `GatewayManager::edit()` (`:612-621`) does `$gateway = $this->get($id)` (has decrypted meta) but then loads a **separate fresh** gateway via `loadGateway()` and calls `editSettings($vars['meta'])` on it — passing **only the POST**, never `$gateway->meta`, and never calling `setGatewayId()`/`setMeta()` on the instance. So inside `editSettings()` there is no current value and no `gateway_id` to fetch one.
- `GatewayManager::setMeta()` (`:814-832`) **deletes all rows then reinserts** only what `editSettings()` returned — so a blank/absent password is **erased**, not preserved.

Therefore the only ways to "keep" a blank password would be (a) the gateway loads its own meta by id — impossible, no id; or (b) echo the stored secret/ciphertext back to the form — forbidden (Non-Negotiable #1). Both rejected. **MVP behavior: passwords required, re-entered on each settings save.** "Rotatable" (NFR1) is still satisfied — admins rotate by entering new values. See Open Question #1 for the post-MVP path (a small `GatewayManager`/core enhancement to hand current meta to `editSettings`, explicitly out of this story).

### Base-class masking primitives (already available — do not reimplement)

[Source: components/gateways/lib/gateway.php:307-401]
- Both `maskData(...)` and `maskDataRecursive(...)` are **`protected`** on the base `Gateway` (verified `gateway.php:307,342`), so a `protected` `maskCredentials()` wrapper and a `Kuickpay` test subclass can both reach them.
- `maskData(array $data, array $mask_fields, $mask_char = 'x', $unmask_length = 0)` — masks listed keys in a **flat** array only. Shorthand: a numeric-indexed list of field names (`['USER','PWD']`) masks the whole value (paypal idiom, `:652`).
- `maskDataRecursive(...)` — same signature, recurses into nested arrays. **Task 4.2 wraps this one** (not flat `maskData`) so the gateway boundary covers nested SOAP payloads as well as the flat `gateway_meta`; flat `maskData` on a nested payload would leave deep credential keys unmasked.
- Default (`unmask_length = 0`) masks the entire value with `str_repeat('x', strlen($value))` — fully redacted, but **length-preserving** in log output (fine for diagnostics; the Task 3 form indicator is separately fixed-length so it leaks neither value nor length). The in-repo logging idiom is `$this->log($url, serialize($this->maskData($params, $mask_fields)), 'input', true);` (eway `:590-593`, payflow, quantum, stripe) — 1.3 uses the recursive variant in the wrapper.

### Previous story intelligence (1.1 + 1.2)

- **1.1 (done):** established the gateway+companion scaffold, the companion-missing guard (`companionInstalled()` + `Kuickpay.!error.companion_missing`), PKR-only `config.json`, fail-closed `buildProcess/validate/success`, and the secret-safety posture. Class is legacy global `Kuickpay extends NonmerchantGateway` (no namespace, no strict_types) — match it.
- **1.2 (done — merged):** built the grouped settings form, `editSettings()` validation, the language keys, and — importantly for 1.3 — already added both passwords to `encryptableFields()` and renders password fields empty. It explicitly **defers to 1.3**: cross-surface masking, same-as-voucher dedupe, and rotation/masked display. It also documented the "re-enter password on every save" limitation and (incorrectly) expected 1.3 to fix it via keep-if-blank — 1.3 instead documents why that's infeasible (above) and delivers the dedupe + masked indicator + redaction boundary. [Source: 1-2 story "Scope boundary vs Story 1.3", "Interim credential re-save behavior"]
- **Idempotency footgun unrelated to 1.3 but in the credential file:** the `registration_number = random_prefix + invoice_id` pattern is an Epic 2/3 concern — do not touch reference-pattern handling here. [Source: deferred-work.md]

### Git intelligence

The 1.2 implementation is merged: `feat(kuickpay): render grouped settings form`, `feat(kuickpay): validate gateway settings`, `feat(kuickpay): encrypt credential settings`, plus two fixes (`fix(kuickpay): anchor settings regexes against trailing newline`, `fix(kuickpay): reject whitespace-only required identifier fields`). 1.3 builds directly on this. Follow the repo commit convention `feat(kuickpay): …` / `fix(kuickpay): …`, imperative, ≤72 chars. [Source: project-context.md#Development Workflow Rules]

### Latest tech information

No new libraries. This story uses only: PHP 8.2; Blesta's `NonmerchantGateway`/`Gateway` base classes (`maskData`, `log`, `encryptableFields`); `ext-openssl` (already required — backs Blesta's `systemEncrypt`/`systemDecrypt` for `encryptableFields`); and Blesta `Input`/`Form`/`Html` helpers. No web research required — all contracts are in-repo and verified above. Do not add packages. [Source: project-context.md#Technology Stack; composer.json ext-openssl]

### Project Structure Notes

- All edits stay inside `components/gateways/nonmerchant/kuickpay/`. No new directories (no `lib/`, no `models/`), no new files. **This `lib/` prohibition is scoped to Story 1.3 only** — Epic 3 will legitimately add `kuickpay/lib/` (the SOAP client `lib/KuickPaySoapClient.php` and the `redactor` protocol class, per architecture.md:405,778); it is forbidden *here*, not forever. [Source: architecture.md:765-778 gateway-vs-protocol ownership; :405; epics.md line 118]
- Files touched (all UPDATE): `kuickpay.php`, `views/default/settings.pdt`, `language/en_us/kuickpay.php`. `config.json`, `composer.json`, `process.pdt`, and all `plugins/kuickpay_reconcile/` files unchanged.
- Architecture ownership boundary respected: the gateway owns "encrypted gateway meta" and credential handling; the **redactor protocol class** and SOAP diagnostics are Epic 3 and must not be created here. [Source: architecture.md:776-778, 371]

### References

- [Source: epics.md#Story 1.3, lines 351-371] — user story + AC1/AC2/AC3 (verbatim above).
- [Source: epics.md FR3 line 29; NFR1 line 87; NFR10 line 105] — encrypt/mask credentials everywhere; encrypted, redacted, rotatable; no hard-coded production secrets.
- [Source: epics.md UX-DR9 line 168 (mask credentials on display, support rotation); UX-DR28 line 206 (no raw diagnostics/credentials/SOAP names/stack traces)].
- [Source: prd.md FR-3] — stored encrypted, masked across settings/logs/diagnostics/customer views/fixtures/docs/exceptions.
- [Source: architecture.md:51, 371, 373, 397, 437, 490, 608, 656, 751-752, 776-778] — encrypted/redacted, never customer-visible; `encryptableFields()`; single redaction boundary (`:315`/`:373`); SOAP→redactor→parser flow (`:397`); redactor is a protocol-library class (`:778`, Epic 3); redaction test fixture (`:751-752`); gateway-vs-protocol ownership (`:776-778`). (Epic/Story numbers come from epics.md/sprint-status.yaml, not architecture.md.)
- [Source: app/models/gateway_manager.php:599-650 (edit), :789-832 (getMeta/setMeta encrypt-decrypt)] — why editSettings can't see current meta; how encryptableFields drives encryption.
- [Source: app/controllers/admin_company_gateways.php:180, 209, 214, 287] — getSettings receives current decrypted meta on GET; POST re-renders submitted values.
- [Source: components/gateways/lib/gateway.php:254-288 (log), :307-401 (maskData/maskValue)] — base masking + logging primitives.
- [Source: components/gateways/merchant/eway/eway.php:576-593; …/payflow/payflow.php:529-532; …/paypal_payments_standard/paypal_payments_standard.php:652] — in-repo `maskData` + `log` idiom and allowlist shorthand.
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php (current 1.1 scaffold); …/views/default/settings.pdt; …/language/en_us/kuickpay.php] — files this story modifies.
- [Source: 1-2-configure-kuickpay-gateway-settings.md "Scope boundary vs Story 1.3", "Interim credential re-save behavior", Non-Negotiable #1, Task 5] — what 1.2 delivered and deferred.
- [Source: 1-1-install-kuickpay-gateway-and-companion-plugin-scaffold.md] — companion guard, class naming, secret-safety, fail-closed payment path.
- [Source: project-context.md] — PHP 8.2; legacy global class style; Loader/Input/Language conventions; secret-safety; no core edits; commit convention; testing-honesty rule.
- [Source: sprint-status.yaml#BUILD ORDER] — Track A sequencing (1-1→1-2→1-3); Epic 1 parallel with Phase 0; posting disabled until 0-1.

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List

## Change Log

- 2026-06-09: Story drafted (ready-for-dev) via bmad-create-story. Exhaustive context-engine analysis across epics (Story 1.3 ACs, FR3/NFR1/UX-DR9/UX-DR28), PRD FR-3, architecture (single redaction boundary, gateway-vs-protocol ownership, encryptableFields), the predecessor 1.2 story (scope boundary + interim limitation), the 1.1 scaffold, and the verified Blesta gateway-edit internals (`gateway_manager::edit/getMeta/setMeta`, `admin_company_gateways::manage`, base `Gateway::maskData/log`, and the eway/payflow/paypal masking idiom). Resolved three load-bearing design points against the codebase: (1) AC1 is delivered by 1.2 — 1.3 locks it + regression-tests it; (2) **keep-if-blank credential rotation is architecturally infeasible** (editSettings gets POST-only on an id-less instance; setMeta wipes+reinserts) — documented with proof, passwords stay required; (3) the gateway credential-redaction boundary is a thin wrapper over base `maskData()` (not a new redactor — Epic 3 owns the SOAP redactor), established now for 1.4/Epic 3 to consume. Masked "is-stored" indicator uses a presence-only boolean from the GET-time decrypted meta, never the value or its length.
- 2026-06-09: Re-aligned to as-built code after Story 1.2 was merged mid-authoring (sprint-status flipped 1-2 → done). Corrected the starting-state section (post-1.2, not scaffold), the AC3 diff (reuse existing `$same` at line 109 rather than recompute), the masked-note insertion points (after `fieldPassword` at lines 38/70), and the inquiry-note gating (1.2 renders the inquiry block unconditionally — no JS show/hide; gate on `$inquiry_password_stored`). Noted that `voucher_password_note` already states the re-entry behavior, and that the two 1.2 follow-up fixes (whitespace-trim, `/D` anchors) must be preserved.
- 2026-06-09: Applied multi-agent validation triage (round 1), each finding re-verified against the live code before editing. Substantive corrections: (1) **`maskCredentials()` declared `protected`** (not `private`) and wrapped over **`maskDataRecursive`** (not flat `maskData`) — base `maskData`/`maskDataRecursive` are both `protected` (`gateway.php:307,342`), so the wrapper is reachable from the Task 6.3 test subclass and the recursive variant safely covers nested SOAP payloads; reframed the contract so it is the *gateway-owned* boundary and dropped the inaccurate claim that Epic 3's separate SOAP-client/redactor class consumes this `protected` method (it is a separate, consistent layer — architecture.md:405,778). (2) **Fixed the masked "stored" indicator's honesty on a failed-POST re-render** — verified the controller re-feeds `$this->post` into `getSettings()` (`admin_company_gateways.php:215-216,287`), which (passwords being required) makes `!empty($meta['password'])` true on values that were never persisted; the gateway has no request context to gate GET-only, so the language copy must not assert "currently stored." Corrected Task 3.5, Task 5.1 copy, and the Dev Notes masked-display rationale. (3) **Reconciled Task 6 tests with the 3-file scope gate** (disposable `temp/` script, no committed test files; honest fallback reporting) and **split the Task 7.2 leak grep** so it no longer flags its own safe `!empty()` presence reads (added a negative-leak check, an exactly-two presence check, and a `*_stored` render check). (4) Doc-accuracy/clarity: repointed `architecture.md` citations (`:715`→`:751-752`; redactor→`:778`), noted the `lib/` ban is **1.3-scoped** (Epic 3 adds `kuickpay/lib/`), flagged Story 0.1 fixtures as `in-progress` (not done), clarified the masked-note insertion point (before the field's closing `</div>`), the `unset()` no-op safety, the exactly-two-keys `encryptableFields` assertion, and a nested-input redaction test. Story remains `ready-for-dev`.

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **Keep-if-blank rotation (the 1.2-flagged limitation).** Confirmed **infeasible** in the current gateway-edit architecture (editSettings receives POST only, no current meta, no gateway_id; setMeta deletes+reinserts). 1.3 keeps passwords **required on every settings save** and adds a masked "stored" indicator. **Recommended:** accept re-entry-on-save for MVP (rotation still works; no live payment path; bounded UX cost). If the team wants true keep-if-blank later, the clean path is a small `GatewayManager`/core enhancement to pass current decrypted meta into `editSettings()` — a separate, core-touching story, explicitly out of 1.3's no-core-edit scope.
2. **Username masking in logs — settled (non-blocking).** 1.3 masks `voucher_username`/`inquiry_username` in logs/diagnostics (defense in depth) but does **not** encrypt them at rest (AC1 = "password fields" only). This is resolved by Dev Notes "Which fields are 'credentials'": encrypt the two passwords, redact the broader credential surface (incl. usernames) from diagnostics. Left here only so the team can veto if they disagree; default stands: redact-in-logs, not encrypt-at-rest.
3. **Masked-indicator UX.** 1.3 shows a fixed 8-glyph mask + "a credential is stored, re-enter to save" note when a password exists. Confirm this is the desired affordance, or whether the team prefers no indicator at all (plain empty required field, as 1.2 ships) to avoid implying keep-if-blank. (Default chosen: show the honest masked indicator.)
4. **SOAP credential key names for the mask allowlist.** `maskCredentials()` pre-lists `userName`/`password` (per addendum A.2 InsertVoucher mapping) so Epic 3 request payloads are covered by the same boundary. Confirm the exact KuickPay SOAP field names from Phase 0 (0.1) so the allowlist is complete before Epic 3 logs live requests; extend the list in 3.2 if Phase 0 reveals more credential-bearing fields.
