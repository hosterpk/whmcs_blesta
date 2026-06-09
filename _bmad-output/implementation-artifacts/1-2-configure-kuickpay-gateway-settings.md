---
baseline_commit: dbee701de838a7dc6c52cf1a506ff16e98cc9de7
---

# Story 1.2: Configure KuickPay Gateway Settings

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin operator,
I want to configure KuickPay endpoints, credentials, Institution ID, reference patterns, date policies, instruction groups, logging, reconciliation, currency, fee, and timeout behavior through Blesta gateway settings,
so that production-specific values are controlled without code changes.

## Acceptance Criteria

(Reproduced verbatim from [Source: epics.md#Story 1.2, lines 334-349].)

**AC1 — Grouped, language-file-driven settings form**
**Given** the admin opens KuickPay settings
**When** the settings form renders
**Then** settings are grouped using Blesta-native form patterns
**And** all customer/admin labels come from language files.

**AC2 — Validation on save**
**Given** required settings are missing or invalid
**When** the admin saves
**Then** validation errors appear through Blesta `Input`/message patterns
**And** empty required values, invalid HTTPS URLs, invalid numeric fields, and invalid reference patterns are rejected.

**AC3 — Configured values are authoritative; nothing hard-coded**
**Given** settings are saved successfully
**When** the gateway later builds voucher or inquiry requests
**Then** it uses configured values
**And** no production URL, Institution ID, fallback phone, fee, conversion rate, or credential is hard-coded in business logic.

> AC3 note: This story **persists and validates** the settings. The downstream consumers that "build voucher or inquiry requests" (SOAP client, voucher service, reconciliation) are **Epics 2–3** and do not exist yet. The testable obligation 1.2 owns for AC3 is: every production-specific value is a stored setting (not a literal in PHP), and the form/validation/storage plumbing reads from `$meta`, not constants. See Dev Notes "AC3 scope — what 'uses configured values' means in this story."

## Non-Negotiables (read before any task)

1. **Never echo a stored credential into the page.** Render `voucher_password` / `inquiry_password` by **omitting any `value` key** from the `fieldPassword(...)` attributes array. Blesta's signature is `fieldPassword($name, $attributes = [], $label = null)` (verified: `core/Util/Input/Fields/InputFields.php`) — the **second argument is the attributes array, not a value**; a value would have to be smuggled in via `['value' => ...]`, which we must never do. Do **not** pass the stored secret back into `fieldPassword(...)` in any position. Echoing the decrypted secret into HTML is a credential leak. [Source: project-context.md#Critical Don't-Miss Rules "Do not expose secrets"; prd.md FR-3 line 114]
2. **All admin-facing strings come from language files.** Every label, tooltip/help note, select-option label, and error message is a `$lang['Kuickpay.*']` key retrieved with `$this->_(...)` / `Language::_(...)`. No hard-coded English in the `.pdt` or in `editSettings()`. [Source: epics.md AC1 line 339, NFR6 line 97, UX-DR28 line 206; project-context.md#Language-Specific Rules]
3. **No hard-coded production values anywhere in PHP logic.** WSDL URL, Institution ID, fallback mobile, fee, conversion rate, and credentials are stored settings only — never literals in `kuickpay.php` or any class. [Source: epics.md AC3 line 349, NFR10 line 105; prd.md FR-2 line 104, line 403; addendum line 243]
4. **Preserve the Story 1.1 companion-plugin guard (regression).** `settings.pdt` must still render the companion-missing setup error (`Kuickpay.!error.companion_missing`) when the companion plugin is absent, and render the configuration fields **only** when `$companion_installed` is true. `getSettings()` must keep passing `companion_installed` to the view. Do not remove or weaken this branch. [Source: 1-1 story Dev Notes "AC3 guard"; components/gateways/nonmerchant/kuickpay/kuickpay.php:48-60; views/default/settings.pdt]
5. **Encrypt credentials at rest (fail-safe).** Add `voucher_password` and `inquiry_password` to `encryptableFields()` in this story so the form never writes plaintext passwords to gateway meta. (Display masking across logs/diagnostics, same-as-voucher dedupe, and rotation-on-blank UX remain Story 1.3 — see "Scope boundary vs Story 1.3" and Open Question #1.) [Source: prd.md FR-3 line 113; architecture.md "Gateway credentials use encryptableFields()"; project-context.md secret-safety]
6. **Stay in scope.** This story **only** builds the settings form, validation, language strings, and encrypted storage. **Do NOT** add: SOAP client / `lib/` classes (Epic 3), the connection-test button or logic (Story 1.4), dynamic customer-facing PKR eligibility/blocking (Story 1.5), voucher creation, posting, schema, cron, or any plugin code. No file under `plugins/kuickpay_reconcile/` changes in this story. [Source: epics.md Stories 1.3-1.5; sprint-status.yaml#BUILD ORDER]
7. **Match the parent contract.** `editSettings`, `getSettings`, `encryptableFields`, `setMeta` keep the exact signatures `NonmerchantGateway` declares. Do not add return/param types the parent doesn't declare. Target **PHP 8.2** (no 8.3+ syntax). Treat any fee/amount value as a **decimal string**, never a PHP float. [Source: project-context.md "Preserve inherited Blesta method signatures", PHP 8.2, NFR13 line 111]

## Tasks / Subtasks

- [x] **Task 1 — Extend `getSettings()` to supply form data (AC1)** [Source: components/gateways/nonmerchant/coingate/coingate.php getSettings]
  - [x] 1.1 Keep the existing `getSettings()` body (companion check + `makeView('settings',...)` + `loadHelpers(['Form','Html'])` + `set('meta',$meta)` + `set('companion_installed',...)`). Only **add** the select-option arrays below; do not change the view-resolution idiom.
  - [x] 1.2 Build localized select-option arrays in `getSettings()` and pass them to the view (mirror coingate's `$receiveCurrency`/`$coingateEnvironment` pattern — option **values** are stable keys, option **labels** come from language files):
    - `currency_policy` options: `['pkr_only' => Language::_('Kuickpay.currency_policy.pkr_only', true)]` (single option for MVP; PKR-first is visible but not selectable to anything else).
    - `fee_policy` options: `['none' => Language::_('Kuickpay.fee_policy.none', true)]` (minimal; detailed fee mechanics are deferred — see Dev Notes "Currency & fee policy").
  - [x] 1.3 Pass the option arrays via `$this->view->set('currency_policy', $currencyPolicyOptions)` and `$this->view->set('fee_policy', $feePolicyOptions)`.

- [x] **Task 2 — Build the grouped settings template `views/default/settings.pdt` (AC1)** [Source: components/gateways/nonmerchant/coingate/views/default/settings.pdt; paypal_payments_standard/views/default/settings.pdt]
  - [x] 2.1 **Preserve the companion-missing branch** exactly as in 1.1: when `!$companion_installed`, render the `alert alert-danger` with `$this->_('Kuickpay.!error.companion_missing')` and render **no fields** (Non-Negotiable #4).
  - [x] 2.2 In the `else` (companion installed) branch, **replace** the `Kuickpay.settings.scaffold_note` info alert with the real grouped form fields. Remove the now-obsolete `scaffold_note` usage from the template (the language key may be deleted in Task 4.4).
  - [x] 2.3 Render the fields in five visual groups, each introduced by a section heading (use the repo idiom `<div class="title_row"><h3><?php $this->_('Kuickpay.group.<x>'); ?></h3></div>` followed by a `<div class="pad">` wrapping that group's body — the paypal grouped-layout idiom — or a Blesta `<fieldset>`/heading consistent with neighboring gateways), each field wrapped in `<div class="mb-3">`, using `$this->Form->label(...)`, `$this->Form->fieldText/fieldPassword/fieldSelect/fieldCheckbox(...)`, and the `bi-info-circle` tooltip idiom with `$this->Html->safe($this->_('Kuickpay.<field>_note', true))`. Field/group spec is in **Dev Notes → "Settings field specification"**.
  - [x] 2.4 **Credential fields render empty** (Non-Negotiable #1): the helper signature is `fieldPassword($name, $attributes = [], $label = null)`, so render by **omitting any `value` key** — `$this->Form->fieldPassword('voucher_password', ['id' => 'voucher_password', 'class' => 'form-control'])` (and likewise `inquiry_password`). The field is empty by construction; never pass `$meta['voucher_password']` in any argument position (and never `null` as the 2nd arg — that would discard your `id`/`class`). Text (non-secret) fields echo the value as the **second** arg per the coingate idiom: `$this->Form->fieldText('x', (isset($meta['x']) ? $meta['x'] : null), ['id' => 'x', 'class' => 'form-control'])`.
  - [x] 2.5 Checkboxes use the `fieldCheckbox('name','true', (($meta['name'] ?? '<default>') === 'true'), ['id'=>'name','class'=>'form-check-input'])` idiom, where `<default>` is the field's value from **Dev Notes → "Default meta values"** — **not** a blanket `'false'`. This is load-bearing: `inquiry_same_as_voucher`, `instruction_online_banking`, `instruction_bank_deposit`, `logging_enabled`, and `reconciliation_enabled` default to `'true'`, so a blanket `?? 'false'` renders them **unchecked on first load** and — for `inquiry_same_as_voucher` — makes `editSettings()` require separate inquiry credentials on the very first save. Selects use `fieldSelect('currency_policy', (isset($currency_policy) ? $currency_policy : []), ($meta['currency_policy'] ?? 'pkr_only'), ['id'=>'currency_policy','class'=>'form-select'])`.
  - [x] 2.6 Accessibility (UX-DR9/UX-DR24): every field has an associated `<label for>`; tab order follows the visual group order; use Blesta `form-label`/`form-control`/`form-select`/`form-check` classes only — no custom CSS, shadows, or marketing styling (UX-DR25). [Source: ux EXPERIENCE.md accessibility floor; DESIGN.md "inherit Blesta theme"]

- [ ] **Task 3 — Implement `editSettings()` validation (AC2)** [Source: components/gateways/nonmerchant/coingate/coingate.php editSettings; paypal_payments_standard.php editSettings]
  - [ ] 3.1 Default unset checkboxes to `'false'` before building rules (mirror PayPal's `if (!isset($meta['dev_mode'])) { $meta['dev_mode']='false'; }`): do this for `inquiry_same_as_voucher`, `instruction_*`, `logging_enabled`, `reconciliation_enabled`.
  - [ ] 3.2 Build the `$rules` array (full spec in Dev Notes → "Validation rules"). Required-non-empty fields use the coingate idiom `['empty' => ['rule'=>'isEmpty','negate'=>true,'message'=>Language::_('Kuickpay.!error.<f>.empty', true)]]`.
  - [ ] 3.3 Add the **HTTPS URL** rule on `wsdl_url` (callback: valid URL **and** scheme `https`) — exact callback in Dev Notes.
  - [ ] 3.4 Add **numeric** rules on `soap_timeout`, `due_date_offset_days`, `expiry_date_offset_days` (non-negative integer, **empty allowed** — all three are optional). Use the empty-tolerant repo idiom `/^([0-9]+)?$/`, **not** `/^\d+$/`: a blank text input submits `''` (the key is present), and `if_set` skips only *absent* keys, not empty strings — so `/^\d+$/` would reject a legitimate blank save. Also add a rule on `institution_id` per the decision in Open Question #2.
  - [ ] 3.5 Add **reference-pattern shape** rules (`if_set`/required) on `registration_number_pattern` and `consumer_number_pattern` (non-empty + allowed charset/token allowlist — conservative; see Dev Notes "Reference patterns: validate shape only, do not generate").
  - [ ] 3.6 Add the **conditional inquiry-credential** rules: only require `inquiry_username` / `inquiry_password` when `($meta['inquiry_same_as_voucher'] ?? 'false') !== 'true'`. Build those rule entries inside an `if (!$same)` block before `setRules`.
  - [ ] 3.7 Add `in_array` (`if_set`) rules on `currency_policy`, `fee_policy`, and every checkbox (`['true','false']`).
  - [ ] 3.8 Call `$this->Input->setRules($rules); $this->Input->validates($meta); return $meta;` (return `$meta` unchanged on both success and failure, per the non-merchant convention — Blesta re-displays the form with errors when validation fails).

- [x] **Task 4 — Language strings (AC1, AC2, NFR6)** [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php; project-context.md language rules]
  - [x] 4.1 Add a `$lang['Kuickpay.group.*']` key per group heading (endpoints_credentials, institution_reference, payment_behavior, instruction_groups, logging_reconciliation).
  - [x] 4.2 Add `$lang['Kuickpay.<field>']` (label) and `$lang['Kuickpay.<field>_note']` (tooltip/help) for every field in the spec. Help copy must be plain and payment-safe (UX voice/tone) and expose no diagnostics/credentials.
  - [x] 4.3 Add `$lang['Kuickpay.!error.<field>.<rule>']` for every rule (`.empty`, `wsdl_url.format`, `*.numeric`, `*_pattern.format`, inquiry conditional, etc.).
  - [x] 4.4 Add the select-option label keys (`Kuickpay.currency_policy.pkr_only`, `Kuickpay.fee_policy.none`). Remove the obsolete `Kuickpay.settings.scaffold_note` key — but **only after/with** Task 2.2 removes its template usage, never before, or the template would reference a missing key. Keep `Kuickpay.name`, `Kuickpay.description`, `Kuickpay.!error.companion_missing`, `Kuickpay.process.not_ready` untouched.
  - [x] 4.5 Preserve the existing file's single-quote / one-key-per-line style; do not rewrap or reorder existing keys (project-context language-file rule).

- [ ] **Task 5 — Encrypt credentials at rest (AC3 secret-safety; fail-safe for Story 1.3)** [Source: prd.md FR-3 line 113; coingate encryptableFields]
  - [ ] 5.1 Update `encryptableFields()` to `return ['voucher_password', 'inquiry_password'];` (replacing the scaffold `return [];`). This guarantees no plaintext password is written to gateway meta even before Story 1.3 lands. Blesta auto-encrypts these listed fields on save and auto-decrypts them into `$meta` on load — do **not** hand-encrypt in `editSettings()` or hand-decrypt in `getSettings()` (that would double-encrypt). Do **not** add masking/redaction logic here either — that is Story 1.3.

- [ ] **Task 6 — Verification (no overstating; AC1–AC3)** [Source: project-context.md#Testing Rules, #Development Workflow Rules; NFR12]
  - [ ] 6.1 `php -l components/gateways/nonmerchant/kuickpay/kuickpay.php` and `php -l` the changed `.pdt`/language files (templates: `php -l` is fine for syntax).
  - [ ] 6.2 Prove no hard-coded production values / no secret echo: `grep -rinE "https?://|institution|password|fee|conversion" components/gateways/nonmerchant/kuickpay/kuickpay.php` — confirm matches are only language keys/field names, never literal URLs/IDs/secrets. Confirm the password fields render empty: `grep -nE "fieldPassword\('(voucher|inquiry)_password'" components/gateways/nonmerchant/kuickpay/views/default/settings.pdt` and verify **no `value` key and no `$meta[` appears on those lines** (empty by omission — do not assert a literal `null` argument, which would be the broken 3-arg shape).
  - [ ] 6.3 Prove scope containment: `git status --porcelain` shows only the gateway tree + this story file + sprint-status.yaml; **no** `plugins/kuickpay_reconcile/` changes, **no** new `lib/` files.
  - [ ] 6.4 If a running Blesta + MySQL dev instance is available: in admin, open Settings → Payment Gateways → KuickPay, confirm the grouped form renders, save with a blank required field and a non-HTTPS URL → both rejected with Blesta error messages (AC2); save valid values → persists; reopen → password fields are blank (not echoed); confirm `gateway_meta` stores the password encrypted (not plaintext). If no runtime/DB is available, **state that explicitly** and rely on lint + grep + structural checks; do not claim install/runtime coverage. [Source: NFR12 line 109]
  - [ ] 6.5 Accessibility (UX-DR9/UX-DR24): confirm every `fieldText`/`fieldPassword`/`fieldSelect`/`fieldCheckbox` has a matching `<label>` whose `for` equals the field's `id` (each field must pass an explicit `'id'` in its attributes). Grep the `.pdt` for `for=` / `id=` pairings or inspect the rendered HTML.

## Dev Notes

### Critical context — read before starting

Story 1.1 shipped the gateway scaffold with a **deliberately empty** settings surface: `editSettings()` returns `$meta` unchanged, `encryptableFields()` returns `[]`, and `settings.pdt` shows only a companion-status alert (danger if the plugin is missing, info "scaffold_note" if present). This story turns that placeholder into the real FR-2 configuration surface. [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php:48-91; views/default/settings.pdt; 1-1 story Dev Notes "Required non-merchant gateway contract" which explicitly defers settings to "Story 1.2"]

- **Settings live in Blesta gateway meta, not a table.** The gateway owns "gateway config and encrypted meta"; the companion plugin owns the `kuickpay_*` tables and must not duplicate gateway passwords. Do not create a settings table or store settings in plugin code. [Source: architecture.md gateway/plugin ownership; "Gateway credentials use encryptableFields(). The plugin must not duplicate gateway passwords."]
- **`config.json` does not change.** `currencies: ["PKR"]` is already set (Story 1.1). Settings are gateway meta written through `editSettings()`, not declared in `config.json`. [Source: components/gateways/nonmerchant/kuickpay/config.json]
- **This is the canonical brownfield idiom in this repo.** Mirror `coingate` (closest structural match: `getSettings` builds option arrays + renders `settings.pdt`; `editSettings` uses `Input->setRules`/`validates`; `encryptableFields` lists secrets) and `paypal_payments_standard` (richer field types + checkbox defaulting + callback rules). Do **not** use the `ModuleFields` API — non-merchant gateways in this codebase render settings via `.pdt` view templates. [Source: components/gateways/nonmerchant/coingate/*; .../paypal_payments_standard/*]

### File being modified — current state and what to preserve

`components/gateways/nonmerchant/kuickpay/kuickpay.php` (READ IN FULL before editing):
- `__construct()` (21-30): loads config, `Input` component, and `kuickpay` language. **Keep.**
- `getSettings()` (48-60): companion check + view render. **Extend** (Task 1) — add option arrays only.
- `editSettings()` (68-71): `return $meta;`. **Replace body** with validation (Task 3).
- `encryptableFields()` (78-81): `return [];`. **Replace** with the two password fields (Task 5).
- `setMeta()` (88-91), `buildProcess()` (134-146), `validate()` (167-171), `success()` (190-194), `companionInstalled()` (201-206): **DO NOT TOUCH.** `buildProcess/validate/success` remain fail-closed (Epic 2/3 own the live path); `companionInstalled()` is the reused guard. The customer-facing `views/default/process.pdt` is also **not** touched in this story.

`views/default/settings.pdt` (current = companion-status alert only): keep the `!$companion_installed` danger branch; replace the info-alert `else` branch with the grouped fields. Do **not** add a `<form>` tag — Blesta wraps gateway settings output in its own form (the coingate/paypal templates have none); a nested form breaks submission.

`language/en_us/kuickpay.php` (current 5 keys): add field/label/note/error/group/option keys; remove `Kuickpay.settings.scaffold_note`; keep the other four.

### Settings field specification (FR-2)

The exact FR-2 enumerated set [Source: prd.md line 104; epics.md FR2 line 27; addendum A.2 lines 22-44], grouped per UX-DR8 [Source: epics.md line 166]. Meta keys are prescriptive; types/defaults guide the dev.

**Group 1 — Endpoints & Credentials**
| Meta key | Field type | Required | Default | KuickPay/SOAP mapping |
|---|---|---|---|---|
| `wsdl_url` | text | yes | — | SOAP WSDL/endpoint (HTTPS) |
| `voucher_username` | text | yes | — | `InsertVoucher.userName` |
| `voucher_password` | password (encrypted) | yes | — | `InsertVoucher.password` |
| `inquiry_same_as_voucher` | checkbox | — | `true` | reuse voucher creds for inquiry |
| `inquiry_username` | text | conditional¹ | — | inquiry op username |
| `inquiry_password` | password (encrypted) | conditional¹ | — | inquiry op password |

**Group 2 — Institution & Reference**
| `institution_id` | text | yes | — | `InsertVoucher.InstitutionID`; also Consumer# prefix |
| `registration_number_pattern` | text | yes | `{random_prefix}+{invoice_id}`² | Reg# generation template (consumed by Story 2.2) |
| `consumer_number_pattern` | text | yes | `{institution_id}+{registration_number}`² | Consumer# template (Story 2.2) |
| `payment_head_label` | text | no | e.g. `Invoice Payment` | `InsertVoucher.Head1` |

**Group 3 — Payment Behavior Policies**
| `due_date_offset_days` | text(numeric) | no | e.g. `7` | drives `DueDate` (Story 2.x) |
| `expiry_date_offset_days` | text(numeric) | no | e.g. `30` | drives `ExpiryDate` |
| `fallback_mobile` | text | no | — | `InsertVoucher.Mobile` fallback when client mobile invalid/non-PK |
| `currency_policy` | select | yes | `pkr_only` | PKR-first visibility (FR-5); dynamic blocking is Story 1.5 |
| `fee_policy` | select | no | `none` | configurable, not hard-coded; mechanics deferred |

**Group 4 — Instruction Groups** (per-channel enable toggles; localized text rendered to customers in Story 2.6)
| `instruction_online_banking` | checkbox | — | `true`³ | |
| `instruction_bank_deposit` | checkbox | — | `true`³ | |
| `instruction_agent_franchise` | checkbox | — | `false`³ | |
| `instruction_mobile_app` | checkbox | — | `false`³ | |

**Group 5 — Logging & Reconciliation**
| `logging_enabled` | checkbox | — | `true` | structured logging toggle (Story 4.5 consumes) |
| `reconciliation_enabled` | checkbox | — | `true` | reconciliation toggle (Epic 3 consumes) |
| `soap_timeout` | text(numeric) | no | e.g. `30` | SOAP client timeout seconds (Epic 3 consumes) |

¹ Conditional: required only when `inquiry_same_as_voucher !== 'true'` (Task 3.6).
² **Provisional, illustrative defaults — do NOT implement generation here, and do NOT auto-prefill them into the (empty-on-first-load) field.** The shown values are tokenized placeholders chosen to satisfy the shape regex `/^[A-Za-z0-9_{}+\-]+$/` (note: **spaces are NOT in the allowed charset**, so any default must be space-free — e.g. `{random_prefix}+{invoice_id}`, never `random_prefix + invoice_id`). The `random_prefix`-based Reg# formula is flagged `UNCONFIRMED` and an idempotency risk (a random prefix defeats retry de-dup); reference-generation determinism is an Epic 2/3 design decision. Story 1.2 **stores and shape-validates the pattern string only**; Story 2.2 owns generation. [Source: deferred-work.md "registration_number = random_prefix + invoice_id idempotency risk"; addendum A.3 lines 46-55 "Keep both formats configurable"; prd.md FR-7 lines 155-156]
³ Default instruction-group enablement for the merchant (HosterPK) is an **open UX question** — see Open Question #3. Pick the defaults above provisionally.

### Default meta values (authoritative — use the SAME default in `settings.pdt` and `editSettings()`)

Single source of truth for defaults, to prevent first-render/first-save contradictions. The `.pdt` MUST use each field's default in its `??` fallback (checkbox checked-state, select value). The `editSettings()` checkbox loop that defaults an **absent** key to `'false'` is the correct "admin actively unchecked it" interpretation and is consistent with these render defaults.

| Meta key | Default | First render |
|---|---|---|
| `inquiry_same_as_voucher` | `true` | checkbox **checked** |
| `instruction_online_banking` | `true`³ | checkbox **checked** |
| `instruction_bank_deposit` | `true`³ | checkbox **checked** |
| `instruction_agent_franchise` | `false`³ | checkbox unchecked |
| `instruction_mobile_app` | `false`³ | checkbox unchecked |
| `logging_enabled` | `true` | checkbox **checked** |
| `reconciliation_enabled` | `true` | checkbox **checked** |
| `currency_policy` | `pkr_only` | select (single option) |
| `fee_policy` | `none` | select (single option) |

**Why this is load-bearing:** the generic `fieldCheckbox('name','true', (($meta['name'] ?? 'false') === 'true'), …)` idiom renders *every* box **unchecked** on first load. For the `true`-default boxes that is simply wrong; for `inquiry_same_as_voucher` it is a functional bug — an unchecked-by-default box means `editSettings()` computes `$same = false` and **requires separate inquiry credentials on the very first save**, contradicting the "reuse voucher creds" default. Use `?? 'true'` (per this table) for the `true`-default boxes. Instruction-group defaults remain provisional (Open Question #3); the values above are the provisional pick.

### Validation rules (AC2) — concrete spec

Use the in-repo idioms. Required-empty rule (coingate), checkbox defaulting + callback rule (paypal). Skeleton:

```php
public function editSettings(array $meta)
{
    // Default an ABSENT checkbox to 'false' (= the admin actively unchecked it). The form renders each
    // box's intended default-checked state from Dev Notes "Default meta values"; an absent key = unchecked.
    foreach (['inquiry_same_as_voucher', 'instruction_online_banking', 'instruction_bank_deposit',
              'instruction_agent_franchise', 'instruction_mobile_app', 'logging_enabled',
              'reconciliation_enabled'] as $checkbox) {
        if (!isset($meta[$checkbox])) {
            $meta[$checkbox] = 'false';
        }
    }

    // Normalize the single-option selects to their defaults. They are "required" per the field spec,
    // but a single-option <select> always submits; normalizing also hardens against a malformed POST.
    if (!isset($meta['currency_policy']) || $meta['currency_policy'] === '') {
        $meta['currency_policy'] = 'pkr_only';
    }
    if (!isset($meta['fee_policy']) || $meta['fee_policy'] === '') {
        $meta['fee_policy'] = 'none';
    }

    $same = (($meta['inquiry_same_as_voucher'] ?? 'false') === 'true');

    $rules = [
        'wsdl_url' => [
            'empty' => [
                'rule' => 'isEmpty', 'negate' => true,
                'message' => Language::_('Kuickpay.!error.wsdl_url.empty', true),
            ],
            'format' => [
                'rule' => function ($url) {
                    return is_string($url)
                        && filter_var($url, FILTER_VALIDATE_URL) !== false
                        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
                },
                'message' => Language::_('Kuickpay.!error.wsdl_url.format', true),
            ],
        ],
        'voucher_username' => ['empty' => ['rule' => 'isEmpty', 'negate' => true,
            'message' => Language::_('Kuickpay.!error.voucher_username.empty', true)]],
        'voucher_password' => ['empty' => ['rule' => 'isEmpty', 'negate' => true,
            'message' => Language::_('Kuickpay.!error.voucher_password.empty', true)]],
        'institution_id' => ['empty' => ['rule' => 'isEmpty', 'negate' => true,
            'message' => Language::_('Kuickpay.!error.institution_id.empty', true)]],
        'registration_number_pattern' => [
            'empty' => ['rule' => 'isEmpty', 'negate' => true,
                'message' => Language::_('Kuickpay.!error.registration_number_pattern.empty', true)],
            'format' => ['rule' => ['matches', '/^[A-Za-z0-9_{}+\-]+$/'],
                'message' => Language::_('Kuickpay.!error.registration_number_pattern.format', true)],
        ],
        'consumer_number_pattern' => [
            'empty' => ['rule' => 'isEmpty', 'negate' => true,
                'message' => Language::_('Kuickpay.!error.consumer_number_pattern.empty', true)],
            'format' => ['rule' => ['matches', '/^[A-Za-z0-9_{}+\-]+$/'],
                'message' => Language::_('Kuickpay.!error.consumer_number_pattern.format', true)],
        ],
        // Optional numeric fields use the empty-tolerant repo idiom /^([0-9]+)?$/ (not /^\d+$/):
        // a blank text input submits '', and `if_set` skips only ABSENT keys, not empty strings
        // (cf. paypal payment_mapping, which guards `if (empty($map)) return true;` for this reason).
        'soap_timeout' => ['numeric' => ['if_set' => true, 'rule' => ['matches', '/^([0-9]+)?$/'],
            'message' => Language::_('Kuickpay.!error.soap_timeout.numeric', true)]],
        'due_date_offset_days' => ['numeric' => ['if_set' => true, 'rule' => ['matches', '/^([0-9]+)?$/'],
            'message' => Language::_('Kuickpay.!error.due_date_offset_days.numeric', true)]],
        'expiry_date_offset_days' => ['numeric' => ['if_set' => true, 'rule' => ['matches', '/^([0-9]+)?$/'],
            'message' => Language::_('Kuickpay.!error.expiry_date_offset_days.numeric', true)]],
        'currency_policy' => ['valid' => ['if_set' => true, 'rule' => ['in_array', ['pkr_only']],
            'message' => Language::_('Kuickpay.!error.currency_policy.valid', true)]],
        'fee_policy' => ['valid' => ['if_set' => true, 'rule' => ['in_array', ['none']],
            'message' => Language::_('Kuickpay.!error.fee_policy.valid', true)]],
    ];

    // Inquiry credentials required only when not reusing voucher credentials
    if (!$same) {
        $rules['inquiry_username'] = ['empty' => ['rule' => 'isEmpty', 'negate' => true,
            'message' => Language::_('Kuickpay.!error.inquiry_username.empty', true)]];
        $rules['inquiry_password'] = ['empty' => ['rule' => 'isEmpty', 'negate' => true,
            'message' => Language::_('Kuickpay.!error.inquiry_password.empty', true)]];
    }

    // Checkbox sanity (each must be 'true'/'false')
    foreach (['inquiry_same_as_voucher', 'instruction_online_banking', 'instruction_bank_deposit',
              'instruction_agent_franchise', 'instruction_mobile_app', 'logging_enabled',
              'reconciliation_enabled'] as $checkbox) {
        $rules[$checkbox] = ['valid' => ['if_set' => true, 'rule' => ['in_array', ['true', 'false']],
            'message' => Language::_('Kuickpay.!error.' . $checkbox . '.valid', true)]];
    }

    $this->Input->setRules($rules);
    $this->Input->validates($meta);

    return $meta;
}
```

- **`matches` is a confirmed, widely-used Blesta `Input` rule** — `paypal_payments_standard`/`gateway_payments` and many modules use `['matches', '/regex/']`, and `/^([0-9]+)?$/` is the repo's standard optional-numeric idiom. No verification detour is needed; the `ctype_digit()`/`preg_match()` anonymous-function form is a contingency only. Closures as rule callbacks are also confirmed supported (the `wsdl_url` callback below mirrors paypal's `payment_mapping` closure). [Source: project-context.md "Use Blesta Input flows"]
- **`institution_id` format** (numeric vs alphanumeric) is **Open Question #2** — default to required-non-empty only; add a numeric rule only if the team confirms numeric-only.
- **Optional free-text fields carry no shape rule by design.** `fallback_mobile` and `payment_head_label` are stored as-is with **no** `if_set` format/length validation in 1.2. This is intentional, not an omission: mobile-number sanitization (and the `InsertVoucher.Mobile` fallback contract) is an **Epic 2** voucher-mapping concern, not a settings-form concern. Do not add a phone/length rule here.
- Returning `$meta` unchanged on failure is correct: Blesta re-renders `getSettings($meta)` with the submitted values and `Input->errors()` surfaced via the standard message summary (AC2's "Blesta Input/message patterns"). [Source: coingate/paypal editSettings return convention]

### Reference patterns: validate shape only, do not generate

`registration_number_pattern` / `consumer_number_pattern` are **template strings stored for Story 2.2 to consume**. In 1.2, validate only that they are non-empty and consist of an allowed charset (letters, digits, `_ { } + -` to permit token placeholders like `{invoice_id}`) — note this charset **excludes spaces**, so any illustrative default must be written space-free. Do **not** parse tokens, generate numbers, or enforce a specific grammar — the canonical token set and the determinism fix (random prefix is an idempotency hazard) are owned by Epic 2/3. Keep both patterns configurable per the addendum. [Source: deferred-work.md item; addendum A.3; prd.md FR-7]

### AC3 scope — what "uses configured values" means in this story

No live request builder exists yet (SOAP client = Story 3.1; voucher mapping = Story 2.3). So AC3's "uses configured values… nothing hard-coded" is satisfied in 1.2 by: (a) every production value is a stored setting, not a PHP literal; (b) `getSettings`/`editSettings` read/write `$meta` exclusively. The grep in Task 6.2 is the testable proof. When Epic 2/3 build the consumers, they will read these meta keys — which is why the **meta key names in the spec are prescriptive** (downstream stories depend on them). [Source: epics.md AC3 line 349; architecture.md data-flow "kuickpay gateway [reads gateway settings] → plugin Voucher service"]

### Scope boundary vs Story 1.3 (credentials) — and why 1.2 still encrypts

Story 1.3 ("Encrypt and Mask KuickPay Credentials") owns: masking credentials across **all** surfaces (logs, diagnostics, fixtures, docs, exception paths), the same-as-voucher **duplicate-storage avoidance**, and rotation/masked-display UX. [Source: epics.md Story 1.3 lines 351-371; prd.md FR-3]
This story takes only the **fail-safe storage** slice — adding the two password fields to `encryptableFields()` (Task 5) and not echoing secrets into the form (Non-Negotiable #1) — because shipping a settings form that writes **plaintext** passwords to `gateway_meta` is a security defect even for one story. This delivers 1.3's AC1 early; 1.3 retains the substantive masking/redaction/dedupe work. See **Open Question #1** if the team prefers to strictly defer `encryptableFields()` to 1.3.

### Interim credential re-save behavior (known 1.2 limitation, closed by 1.3)

Because credential fields render empty (Non-Negotiable #1) and `voucher_password` (plus conditionally `inquiry_password`) are **required-non-empty** (AC2), an admin who later edits **any** setting must **re-enter the password(s)** to pass validation. `editSettings(array $meta)` receives **only the submitted values, not the gateway's currently-stored meta** (verified against the scaffold and the in-repo coingate/paypal flow), so 1.2 has no clean way to "keep the existing encrypted value when the field is left blank" — the gateway can't tell a blank-on-edit from a blank-on-create. That **keep-if-blank / rotation-on-blank** mechanic is exactly Story 1.3's job and needs the same current-meta plumbing.

**Decision for 1.2 (chosen — overridable by the team):** keep passwords required, and accept re-entry-on-every-save as an explicit, time-boxed limitation. Do **not** add current-meta loading and do **not** make passwords optional here — either would weaken AC2's "reject empty required values" and pre-empt 1.3. Exposure is bounded: there is no live payment path in 1.2 and 1.3 is the next story. In the Task 6.4 manual check, treat "re-saving after editing another field requires re-typing the password" as **expected behavior**, not a bug. (The coingate/paypal gateways avoid this only by echoing the decrypted secret back into a `fieldText`, which Non-Negotiable #1 forbids here.) [Source: epics.md Story 1.3 lines 351-371; prd.md FR-3]

### Currency & fee policy

- **Currency:** MVP is PKR-only. Render `currency_policy` as a select whose only option is `pkr_only`, plus a help note stating non-PKR invoices cannot use KuickPay and no conversion rate is hard-coded (FR-5 "currency behavior visible in Admin Settings"). The **dynamic** customer-facing hide/block of non-PKR invoices is **Story 1.5** — do not build eligibility logic here. [Source: prd.md FR-5 lines 126-133; epics.md Story 1.5; 1-1 Dev Notes "PKR-only at scaffold stage"]
- **Fee:** detailed fee mechanics (heads, percentage/fixed, late surcharge) are **deferred production-gate decisions** [Source: architecture.md "Deferred Decisions: Fee, partial payment…"]. Provide a minimal `fee_policy` select (`none` default) so the value is configurable and not hard-coded, and note that mechanics arrive with the fee-policy story. Treat any future fee amount as a decimal string (NFR13).

### Scope guardrails — what must NOT happen in this story

- No SOAP client, parser, redactor, or any `lib/` class; no `plugins/kuickpay_reconcile/` changes. (Epic 3 / later.)
- No connection-test button or `Echo`/`GetInstitutionsList` call (Story 1.4).
- No dynamic PKR eligibility / customer-facing blocking (Story 1.5).
- No voucher creation, posting, transactions, schema, cron, or admin controllers/models.
- No masking/redaction logic beyond not-echoing the password value, and no same-as-voucher dedupe behavior (Story 1.3).
- No changes to `buildProcess/validate/success` (stay fail-closed), `process.pdt`, `config.json`, core files, `.htaccess`, or root `composer.json`.
- No PHP 8.3+ syntax; no floats for any amount/fee.

### Verification

```sh
# 1. Syntax
php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
php -l components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
php -l components/gateways/nonmerchant/kuickpay/views/default/settings.pdt

# 2. No hard-coded production values; password rendered empty
grep -rinE 'https?://[a-z]|institutionid\s*=\s*["'\''0-9]|password.*=>.*\$meta' \
  components/gateways/nonmerchant/kuickpay/kuickpay.php \
  components/gateways/nonmerchant/kuickpay/views/default/settings.pdt \
  || echo "clean: no hard-coded prod value or secret echo"

# 3. Scope containment (no plugin/lib changes)
git status --porcelain
find components/gateways/nonmerchant/kuickpay -type d -name lib  # expect: no output

# 4. encryptableFields lists both passwords
grep -n "encryptableFields" -A2 components/gateways/nonmerchant/kuickpay/kuickpay.php
```

If no running Blesta + MySQL stack is available, root PHPUnit / install-time runtime checks are **N/A** — state this explicitly and rely on lint + grep + structural checks. Do not present lint-only coverage as full runtime verification. [Source: project-context.md#Testing Rules; NFR12 line 109]

### Project Structure Notes

- All edits stay inside `components/gateways/nonmerchant/kuickpay/` — exactly the gateway tree from Story 1.1. No new directories (no `lib/`, no `models/`). [Source: architecture.md gateway layout; epics.md line 118]
- Files touched (all **UPDATE**, none new):
  - `components/gateways/nonmerchant/kuickpay/kuickpay.php` (getSettings, editSettings, encryptableFields)
  - `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt`
  - `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`
- No file is created or deleted; `config.json`, `composer.json`, `process.pdt`, and all plugin files are unchanged.

### References

- [Source: epics.md#Story 1.2, lines 328-349] — user story + the three ACs (verbatim above).
- [Source: epics.md FR2 line 27; NFR5 line 95; NFR6 line 97; NFR10 line 105] — configurable behavior; Blesta conventions; language files; no hard-coded production values.
- [Source: epics.md#Additional Requirements lines 118, 121] — encrypted gateway settings live under the gateway; gateway must not own plugin-owned concerns.
- [Source: epics.md UX-DR8 line 166; UX-DR9 line 168; UX-DR25 line 200; UX-DR28 line 206] — settings grouping; inline validation + mask + rotation; inherit Blesta theme; language-file strings, no diagnostics exposed.
- [Source: prd.md#FR-2 lines 99-106] — exact settings field list; required-non-empty; HTTPS/numeric validation.
- [Source: prd.md#FR-3 lines 108-115] — encrypted credentials via `encryptableFields()`; masking; rotation (Story 1.3 owns masking/rotation).
- [Source: prd.md#FR-5 lines 126-133] — PKR-first; currency behavior visible in settings; no hard-coded conversion.
- [Source: prd.md#FR-7 lines 150-158] — Registration/Consumer Number default patterns; patterns configurable and validated (generation = Story 2.2).
- [Source: addendum.md A.2 lines 22-44] — `InsertVoucher` field → Admin Setting mapping (userName/password/InstitutionID/Head1/etc.).
- [Source: addendum.md A.3 lines 46-55; line 243] — Consumer Number rule; keep formats configurable; never hard-code production values.
- [Source: architecture.md] — settings stored as encrypted gateway meta via `encryptableFields()`; "config lives in Blesta gateway/plugin settings"; gateway-vs-plugin ownership; data-flow "gateway reads gateway settings".
- [Source: components/gateways/nonmerchant/coingate/coingate.php; .../coingate/views/default/settings.pdt] — canonical in-repo `getSettings`/`editSettings`/`encryptableFields` + `.pdt` field idioms (closest structural match).
- [Source: components/gateways/nonmerchant/paypal_payments_standard/paypal_payments_standard.php; .../views/default/settings.pdt] — checkbox defaulting, callback validation rules, `fieldCheckbox`/tooltip patterns.
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php:48-91; views/default/settings.pdt; language/en_us/kuickpay.php] — the exact files this story modifies (current state).
- [Source: deferred-work.md "registration_number = random_prefix + invoice_id idempotency risk"] — why 1.2 stores patterns but does not generate.
- [Source: 1-1-install-kuickpay-gateway-and-companion-plugin-scaffold.md Dev Notes] — companion guard, PKR-only-at-scaffold, settings deferred to 1.2, class-naming, secret-safety.
- [Source: project-context.md] — Blesta `Input`/`Loader`/language-file conventions; PHP 8.2; secret-safety; no-core-edit; decimal-not-float (NFR13).
- [Source: sprint-status.yaml#BUILD ORDER] — Epic 1 unblocked, parallel with Phase 0; payment posting disabled until 0-1 approved (this story wires none).

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

- 2026-06-09: Task 1 red check `grep -nE "currency_policy|fee_policy|currencyPolicyOptions|feePolicyOptions" components/gateways/nonmerchant/kuickpay/kuickpay.php` returned no matches before implementation.
- 2026-06-09: Task 1 green check `php -l components/gateways/nonmerchant/kuickpay/kuickpay.php` passed; grep confirmed localized `currency_policy` and `fee_policy` option arrays are passed to the view.
- 2026-06-09: Task 2/4 red check found only `Kuickpay.settings.scaffold_note` in the settings template/language file and no real fields.
- 2026-06-09: Task 2/4 green checks `php -l` passed for `settings.pdt` and `language/en_us/kuickpay.php`; `scaffold_note` grep returned no matches; password-field grep confirmed both `fieldPassword()` calls omit stored meta/value attributes; label/id inspection covered literal fields and the fixed instruction checkbox loop.

### Completion Notes List

- Task 1: Extended `getSettings()` with localized `currency_policy` and `fee_policy` option arrays while preserving the existing companion check, view-resolution idiom, helper loading, and existing view variables.
- Task 2/4: Replaced the scaffold info alert with five grouped settings sections, preserved the companion-missing branch, rendered password fields empty by omission, applied field-specific checkbox defaults, and added all language-driven labels, help notes, option labels, and validation messages.

### File List

- components/gateways/nonmerchant/kuickpay/kuickpay.php
- components/gateways/nonmerchant/kuickpay/views/default/settings.pdt
- components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
- _bmad-output/implementation-artifacts/1-2-configure-kuickpay-gateway-settings.md

## Change Log

- 2026-06-09: Added localized KuickPay settings option arrays to `getSettings()` for currency and fee policy.
- 2026-06-09: Built the grouped KuickPay gateway settings form and added the language keys it renders.
- 2026-06-09: Story drafted (ready-for-dev) via bmad-create-story. Exhaustive context-engine analysis across epics, PRD (FR-2/3/5/7), addendum SOAP mapping, architecture, UX (UX-DR8/9/25/28), the Story 1.1 scaffold + learnings, deferred-work log, and the canonical in-repo coingate/paypal gateway settings idioms.
- 2026-06-09: Validation triage (multi-agent) applied, all findings verified against the codebase. Corrected the `fieldPassword` call to the 2-arg attributes signature (`$attributes` is the 2nd param, not a value) and fixed the Task 6.2 grep proof accordingly; switched optional-numeric rules to the empty-tolerant `/^([0-9]+)?$/` idiom (a blank input submits `''`; `if_set` skips only absent keys); added an authoritative **Default meta values** table and made the checkbox render use each field's real default (fixes `inquiry_same_as_voucher` first-save forcing inquiry credentials); made the reference-pattern illustrative defaults space-free/regex-consistent and explicitly non-prefilled; added single-option select normalization; documented the interim credential re-save limitation (owned by 1.3); and added smaller guards (no `<form>` tag, `encryptableFields()` auto-encrypt/decrypt, `process.pdt` do-not-touch, `scaffold_note` removal ordering, group `pad` wrapper, accessibility check 6.5, downgraded the `matches` caution to confirmed).

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **`encryptableFields()` timing vs Story 1.3.** This story adds `voucher_password`/`inquiry_password` to `encryptableFields()` as a fail-safe so the settings form never persists plaintext credentials, leaving display-masking, cross-surface redaction, same-as-voucher dedupe, and rotation UX to Story 1.3. Confirm this re-slice (recommended — it closes a real plaintext-at-rest window and 1.3 keeps the substantive masking work), or instruct 1.2 to leave `encryptableFields()` empty and accept a brief plaintext window during sequential dev (mitigated by no live path).
2. **`institution_id` format.** Is Institution ID strictly numeric for this merchant (KuickPay assigns it; it prefixes the Consumer Number)? If yes, 1.2 can add a numeric rule; if it may be alphanumeric, keep required-non-empty only. Default chosen: required-non-empty, no numeric rule, pending confirmation.
3. **Default instruction-group enablement (HosterPK).** Which Instruction Groups ship enabled by default — online banking, bank deposit, agent/franchise, mobile app, or a different set? (Open in the UX decision log.) Provisional defaults: online banking + bank deposit ON; agent/franchise + mobile app OFF.
4. **Fee policy shape.** MVP uses a placeholder `fee_policy` select (`none`) since fee mechanics are a deferred production-gate decision. Confirm no fee fields are required at launch, or specify the minimal fee inputs (percentage/fixed/late surcharge) so 1.2 can add validated decimal-string fields.
5. **Date-policy representation.** This story models due/expiry as integer **offset-days** settings (`due_date_offset_days`, `expiry_date_offset_days`). Confirm offset-days is the intended policy shape, or whether KuickPay date formats/fixed dates from Phase 0 require a different representation (Phase 0 confirms date formats).
