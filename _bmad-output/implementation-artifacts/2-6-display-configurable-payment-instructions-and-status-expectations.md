# Story 2.6: Display Configurable Payment Instructions and Status Expectations

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a customer,
I want clear channel-specific KuickPay instructions and payment-status expectations,
So that I know how to pay and when Blesta will update the invoice.

## Acceptance Criteria

**AC1 — Only enabled instruction groups render (FR2, FR13, UX-DR5)**
**Given** instruction groups are configured (the four `instruction_*` gateway toggles already exist)
**When** the customer views the KuickPay payment page for a payable voucher
**Then** only enabled instruction groups are displayed, each independently scannable by channel
**And** disabled groups are not rendered at all (no empty heading, no placeholder).

**AC2 — All copy is language/config-driven; no internals leak (FR13, UX-DR5, UX-DR28)**
**Given** customer-facing copy is displayed
**When** the page renders
**Then** instruction text, channel labels, support-path copy, validation messages, and status expectations come from language/config patterns
**And** no raw SOAP, parser fields, credentials, internal exception classes, provider status strings, or Registration Number are shown.

**AC3 — Customer-side behavior never marks paid; page explains the confirmation model (FR14, UX-DR6, UX-DR20, UX-DR21)**
**Given** customer-side refresh, return, the Copy action, or a Check Payment Status action is used
**When** the page updates
**Then** the invoice is not marked paid from customer-side behavior
**And** the page explains that Blesta updates the invoice only after KuickPay confirmation
**And** success styling appears only when the voucher is `posted`.

## Tasks / Subtasks

- [ ] **Task 1 — Add the testable "which instruction groups are enabled" seam in `kuickpay.php` (AC1)**
  - [ ] Add a `protected` helper `enabledInstructionGroups(array $meta): array` to `kuickpay.php` (place it next to the existing customer-display helpers, near `customerVoucherStatusDisplay()` `:898` / `resolveDisplayMode()` `:883`). It returns an **ordered** list of enabled channel descriptors, e.g. `[['channel' => 'online_banking', 'title_key' => 'Kuickpay.process.instruction.online_banking.title', 'body_key' => 'Kuickpay.process.instruction.online_banking.body'], …]`.
  - [ ] **Use the same per-channel defaults as `settings.pdt`** (the single source of truth for out-of-box state): `online_banking => 'true'`, `bank_deposit => 'true'`, `agent_franchise => 'false'`, `mobile_app => 'false'` (mirror `settings.pdt:227-232`). A channel is enabled iff `(($meta[$field] ?? $default) === 'true')` — exactly the predicate `settings.pdt:238` uses for the checkbox checked-state. This keeps a never-saved (fresh-install) gateway consistent with what the admin sees pre-save. **Do not** treat unset as `'false'` globally — `editSettings()`'s missing-checkbox default (`:139-151`, sets to `'false'`) only applies **after a save**; before any save the keys are simply absent, and the customer view must match the settings UI defaults, not the post-save normalization.
  - [ ] Preserve a **fixed channel order**: online banking → bank deposit → agent/franchise → mobile app (matches the settings-screen order at `settings.pdt:227-232` and FR13's listing order). Order is story-authored, not provider-driven.
  - [ ] Add `exposeEnabledInstructionGroups(array $meta): array` to the test subclass in `tests/KuickPayVoucherGatewayHelpersTest.php` (mirror the existing `expose*` accessors, e.g. `exposeCustomerVoucherStatusDisplay` `:107`, `exposeResolveDisplayMode` `:102`).
- [ ] **Task 2 — Render enabled instruction groups in the payable arm of `process.pdt` (AC1, AC2)**
  - [ ] In `process.pdt`, render instruction groups **only in the `payable` arm** (`:24-141`). **Pinned insertion point (structural — do not rely on a bare line number):** open a **new sibling `<div>` immediately after the `.kuickpay-voucher-info` closing `</div>` (`:90`) and BEFORE the `<?php if ($has_consumer_number) { ?>` copy-script guard (`:91`)**. Do **not** place the instructions inside the `<dl class="row">` (it closes at `:89` — adding block content there breaks the definition-list grid), and do **not** place them inside the `$has_consumer_number` guard (`:91-141`) — that would make instructions vanish whenever the Consumer Number is empty, even though they only require `$has_payable_reference`. Do **not** render instructions in the `status_only` arm (`:142-169`) — that arm has no payable Consumer Number, so "how to pay" copy would be misleading there — nor in the `process_notice`/`retry_safe` arms.
  - [ ] **Guard the whole section on a non-empty list (AC1).** Wrap **both** the section heading and the loop in `if (!empty($instruction_groups)) { … }` so an all-disabled config (helper returns `[]`) renders **nothing** — no heading, no container, no placeholder. AC1 (`:19`) forbids an orphaned heading, and the helper-level "all-disabled → `[]`" test does **not** catch this (the `.pdt` is not harness-drivable), so it must be enforced in the markup.
  - [ ] Inside that guard, render one section heading from `Kuickpay.process.instructions_heading`, then loop over `instruction_groups` (set in Task 4) rendering each group's `$group['title_key']` and `$group['body_key']`. Use the **escaped variable-key** form `echo $this->Html->safe($this->_($group['title_key']))` / `…($group['body_key'])` — mirroring the closest in-file precedent for a variable-key `$this->_($var)` lookup, the status-badge label at `:80` (the plain `echo $this->_('literal')` form at `:23,:27` is for hard-coded keys). The keys are a closed allowlist (Task 1) so the *key* is already safe; `Html->safe()` on the resolved value is harmless defense-in-depth that keeps the new markup consistent with `:80`. Minimal skeleton (dev may refine markup, but must keep the empty-guard, the single heading, and normal typography):
    ```php
    <?php if (!empty($instruction_groups)) { ?>
        <div class="kuickpay-instructions mt-3">
            <p class="font-weight-bold mb-2"><?php echo $this->_('Kuickpay.process.instructions_heading'); ?></p>
            <?php foreach ($instruction_groups as $group) { ?>
                <div class="mb-2">
                    <div class="font-weight-bold"><?php echo $this->Html->safe($this->_($group['title_key'])); ?></div>
                    <p class="mb-0"><?php echo $this->Html->safe($this->_($group['body_key'])); ?></p>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
    ```
    (Heading uses `<p class="font-weight-bold">`, not an `<h*>` tag — the panel uses `<dt>`/`<dd>`, not headings, so this avoids disturbing heading hierarchy inside the Blesta pay flow.)
  - [ ] **Typography (UX-DR26; DESIGN.md:139-148, :179):** instruction headings/body use **normal inherited Blesta typography** — NOT `text-monospace`. Monospace is reserved for the Consumer Number only (already rendered above in the panel). Do not render instructions as code-like text.
  - [ ] **Layout (UX-DR22, UX-DR25; EXPERIENCE.md:120,126; DESIGN.md:146-150):** single-column, stacked groups, no horizontal scroll on phone. Use only Bootstrap 4.6.2 / Blesta-inherited utilities already in `process.pdt` (`row`, `col-12`, `col-md-*`, spacing utilities, `text-muted`, `font-weight-bold`). **No new CSS/JS file, no marketing shadows/gradients/floating panels.** Keep the new markup in the **pinned sibling `<div>`** from the first subtask (after `.kuickpay-voucher-info` `:90`, before the `:91` guard) — reference/amount/expectation stay above instructions.
  - [ ] The instruction title/body keys come from the **closed allowlist** the helper returns (Task 1), not from any DB/user-input value — so the variable-key `$this->_($group['title_key'])` lookup is safe under the same rule 2.4's review used to clear the `process_notice` concatenation ("only hard-coded literals flow in, no input source"; `2-4-…md:312`). Never interpolate `$voucher`/contact/amount data into instruction copy.
  - [ ] **Do not** flatten, re-key, or reorder 2.5's existing arms (`process_notice → payable → status_only → retry_safe`). You are inserting content **inside** the payable arm only.
- [ ] **Task 3 — Gate the customer "Check Payment Status" action OFF for MVP via a documented capability seam (AC3)**
  - [ ] Add a `protected` helper `customerStatusCheckSupported(): bool` to `kuickpay.php` that returns `false` for MVP, with a docblock stating it is the **single flip-point** to enable the customer status-check action once a safe customer-side inquiry capability exists (Epic 3 — single-inquiry is `done` at the plugin/service layer but is **not** wired to any customer-callable, payment-safe route today; posting is Story 3.5). [FR14: "shows a Check Payment Status action only when supported"; EXPERIENCE.md:66: "Customer action appears only if current reconciliation capability supports it."]
  - [ ] Add `exposeCustomerStatusCheckSupported(): bool` to the test subclass and assert it returns `false` (the MVP gate).
  - [ ] In `process.pdt`, gate a Check Payment Status affordance behind `if (!empty($status_check_supported)) { … }`, placed in the **same new sibling block** (after the instruction groups, still before the `:91` copy-script guard). Since the seam returns `false`, **nothing renders today** — but the conditional must exist so the wiring is in place and the absence is deliberate (not an omission). To make intent unambiguous for whoever later flips the gate, the conditional body is a **documented HTML-comment placeholder only** — not an active control, not a `<button>`/`<form>`, and not a bare empty `{ }`:
    ```php
    <?php if (!empty($status_check_supported)) { ?>
        <?php // Check Payment Status affordance — wired but dark for MVP (Decision D2). ?>
        <?php // When enabled: add a language-keyed label (Kuickpay.process.check_payment_status), ?>
        <?php // use a Blesta POST/form flow with disable-on-submit, must NOT blank the Consumer ?>
        <?php // Number / last-known status, and must never mark paid except via KuickPayPostingService (Story 3.5). ?>
    <?php } ?>
    ```
    **Do not** add a customer route/controller, AJAX endpoint, live SOAP call, or any inquiry trigger in this story — that is out of scope and must remain behind the gate. **Do not** add the `Kuickpay.process.check_payment_status` language key now (it would be a dead key); add it in the story that flips the gate.
  - [ ] Document the **contract for when the action is later enabled**: it must run through the **same parser/validation/posting rules** as scheduled reconciliation (EXPERIENCE.md:103), must use a normal Blesta POST/form flow with disable-on-submit (EXPERIENCE.md:99), must **never** mark an invoice paid except through `KuickPayPostingService` (Story 3.5), and on click must **not blank** the existing Consumer Number / last-known status (UX-DR21; EXPERIENCE.md:106).
- [ ] **Task 4 — Wire the new view vars into `buildProcess()` (AC1, AC3)**
  - [ ] In `buildProcess()` inside the existing `if ($voucher !== null)` block (`:683-688`, where `voucher`/`status_display`/`display_mode`/`kuickpay_name`/`institution_id` are already set), add `$this->view->set('instruction_groups', $this->enabledInstructionGroups($meta))` and `$this->view->set('status_check_supported', $this->customerStatusCheckSupported())`. `$meta` is already in scope at `:632`.
  - [ ] **Do not** modify `voucherRowToView()` (`:1153`), `reloadVoucherDecision()` (`:852`), `resolveDisplayMode()` (`:883`), `customerVoucherStatusDisplay()` (`:898`), the 2.4 amount-change / multi-invoice gates, the payable gate, or the `expired`/`cancelled`/`failed`-credential → `allow` paths. This story adds two read-only view vars and a render block; it changes **no** routing or safety logic.
  - [ ] The `.pdt` reads `($instruction_groups ?? [])` and `(!empty($status_check_supported))` defensively so the arms still render if a var is unset.
- [ ] **Task 5 — Add customer-facing language keys (AC1, AC2)**
  - [ ] Add to `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` (in the `Kuickpay.process.*` customer block, after the status keys at `:21-29`):
    - `Kuickpay.process.instructions_heading` — section heading above the groups (e.g. "How to pay").
    - For each channel, a `*.title` and `*.body` key:
      - `Kuickpay.process.instruction.online_banking.title` / `.body`
      - `Kuickpay.process.instruction.bank_deposit.title` / `.body`
      - `Kuickpay.process.instruction.agent_franchise.title` / `.body`
      - `Kuickpay.process.instruction.mobile_app.title` / `.body`
  - [ ] Body copy must be **conservative, channel-appropriate, plain language** that tells the customer to pay using "the Consumer Number shown above" through that channel. **No** paid/received/success/"almost paid" language (EXPERIENCE.md:44-52), no provider status, SOAP names, parser fields, credentials, or Registration Number (UX-DR28). Suggested copy (dev may refine):
    - online_banking.body: "Log in to your bank's online or mobile banking, choose Bill Payment or KuickPay, enter the Consumer Number shown above, confirm the amount, and submit the payment."
    - bank_deposit.body: "Visit any participating bank branch, ask to pay a KuickPay bill, provide the Consumer Number shown above, and pay the amount due."
    - agent_franchise.body: "Visit a participating KuickPay agent or franchise, ask to pay a KuickPay bill, give the Consumer Number shown above, and pay the amount due."
    - mobile_app.body: "Open your mobile wallet or payment app, select KuickPay or Bill Payment, enter the Consumer Number shown above, and confirm the payment."
  - [ ] **Use a distinct key namespace** (`Kuickpay.process.instruction.<channel>.*`) for this customer copy — do **not** reuse the admin **setting** label keys `Kuickpay.instruction_<channel>` / `_note` (`:81-88`), which are settings-screen labels, not customer copy.
  - [ ] **Preserve all existing keys** — do not remove/rename 2.5's `Kuickpay.process.status_expectation` (`:20`), `identity_label`/`copy_button`/`copy_feedback` (`:17-19`), the `status.*` keys (`:21-29`), 2.4's `amount_changed`/`multi_invoice_unsupported` (`:9-10`), or the labels at `:12-16`. Add only the new keys.
  - [ ] If the gateway maintains parallel locales, add the new keys there too; if only `en_us` exists for this gateway, `en_us` is sufficient (NFR6; project-context language rule).
- [ ] **Task 6 — Verification**
  - [ ] `php -l` on every changed PHP file (`kuickpay.php`, language file) and the `.pdt`.
  - [ ] **Required** unit tests in `tests/KuickPayVoucherGatewayHelpersTest.php` (subclass-`expose*` + fake-seam pattern):
    - `enabledInstructionGroups()`: all-enabled meta → 4 groups in the fixed order; all-disabled (all four explicitly `'false'`) → `[]`; **unset/empty meta (fresh install)** → exactly `online_banking` + `bank_deposit` (the settings.pdt defaults); a mixed case specified as a **full explicit meta with all four keys present** — e.g. `{online_banking:'false', bank_deposit:'true', agent_franchise:'true', mobile_app:'false'}` → `[bank_deposit, agent_franchise]` (exercises an override in both directions). Assert each descriptor carries the correct `channel`, `title_key`, and `body_key`. **Do not** write the mixed case as a *sparse* meta like `{agent_franchise:'true'}` and expect `[agent_franchise]`: under the default-`true` `online_banking`/`bank_deposit`, a sparse meta correctly resolves to `[online_banking, bank_deposit, agent_franchise]` (the unset≠false rule), so a one-element expectation would make the test fail and tempt a dev to weaken the assertion or break the helper.
    - `customerStatusCheckSupported()` → `false` (MVP gate). This guards against the action being accidentally enabled before a safe customer inquiry capability exists.
    - **New language keys (AC2 enforcement).** Extend the existing `testCustomerReferencePanelLanguageKeysExist()` (`:1087-1110`, which `require`s the language file and asserts each key exists + is non-empty), or add a sibling test, to cover the nine new keys: `Kuickpay.process.instructions_heading` and `Kuickpay.process.instruction.<channel>.title`/`.body` for the four channels. Assert each exists and is non-empty, **and** assert each is free of forbidden internals. Mirror the existing forbidden-term precedent in `testProcessRetrySafeCopyHasLanguageKey()` (`:1079-1084`), but use the **case-insensitive** assertion `assertStringNotContainsStringIgnoringCase(...)` (available in PHPUnit 8.5) — the in-file precedent is case-sensitive, so a literal copy would not actually satisfy "case-insensitive". Run the reject assertions on the **`.title` keys too**, not only the bodies.
      - **Concrete reject-token set** (grounded in this gateway's real internals — `error_class` `kuickpay.php:865`, `RegistrationNumber`/`registration_number` `:794`, `raw_status` `:1097`): `SOAP`, `WSDL`, `xmlns`, `Envelope`; `error_class`, `Exception`; the snake_case raw keys `raw_status`, `registration_number`, `consumer_number`; credential terms `password`, `username`, `secret`, `credential`; the internal status enums `confirmed_unposted`, `manual_review`; and the spaced literal `Registration Number`.
      - **Allow-list carve-out (must NOT be rejected — they occur in legitimate copy):** `KuickPay`, `Bill Payment`, the **spaced** label `Consumer Number`, and the plain words `pay`/`payment`/`paid`/`amount`/`due`/`bank`/`branch`/`received`/`reference`. The trap the user flagged: "provider status strings" means the **raw upstream `raw_status` codes**, not the localized status *labels* — rejecting `paid`/`payment`/`received` would false-positive on "submit the payment" / "pay the amount due", and rejecting the spaced "Consumer Number" would break every body string (reject only the snake_case `consumer_number`). This catches missing keys, raw-key leakage, and unsafe copy that `php -l` + helper tests would not.
  - [ ] Run the **full gateway suite** with the external PHPUnit 8.5 runner and confirm no regression: `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. Do **not** use `-c build/phpunit.xml` (bootstrap-path bug — project-context). Compare the green count against the actual pre-change baseline (re-run before changing to capture it; 2.5's notes referenced ~180+ tests).
  - [ ] The `.pdt` (markup, instruction loop, responsive single-column, status-check conditional) is **not** drivable in the component harness — verify by code inspection + manual render in a Blesta client pay flow if a runtime is available; otherwise state the gap explicitly. Do **not** present `php -l` + helper unit tests as full UI verification. **Inspection checklist (must confirm by reading the rendered/static markup):** (a) all-disabled config → **no** instructions heading and **no** container render (the `if (!empty($instruction_groups))` guard holds — AC1); (b) the new block is a **sibling** between the `.kuickpay-voucher-info` `</div>` (`:90`) and the `$has_consumer_number` guard (`:91`) — **not** inside the `<dl>` and **not** inside the `$has_consumer_number` guard; (c) the `status_check_supported` conditional renders nothing in MVP.
  - [ ] No live KuickPay calls. No DB-backed render verification is possible in this checkout — state it.

## Dev Notes

### Critical gates & invariants (read first)
- **This story is display-only — `buildProcess()` still cannot mark paid, and neither can the customer.** No path here may create/apply a Blesta transaction, call `markPaid`/`recordPayment`, update invoice status, set `posted`/`confirmed_unposted`, trigger reconciliation/posting, or add a customer route that mutates state. Only `KuickPayPostingService` (Story 3.5, `backlog`) pays an invoice. [architecture.md:410,520-527,648-662; FR17; FR14]
- **No customer-side action implies paid.** Refresh, return, the Copy action, or any future Check Payment Status affordance must leave paid state unchanged — a page reload just re-runs `buildProcess()` and re-renders current **stored** state. [architecture.md:410 ("…callback/IPN paths cannot mark paid from browser/customer-side data"); EXPERIENCE.md:105; UX-DR4]
- **Success styling stays posted-only.** Instruction copy and status copy must never read as "paid/received/almost paid." Green/`badge-success` only at `status === 'posted'` (already enforced by 2.5's `customerVoucherStatusDisplay`). [UX-DR20; architecture.md:653,655; DESIGN.md:122,132,137; EXPERIENCE.md:44-52,132-142]
- **No raw diagnostics in the customer view.** Never render raw SOAP/XML, provider status strings, parser field names, credentials, stack traces, exception classes, or the **Registration Number** on the customer panel. The customer reference is the **Consumer Number** only. [UX-DR28; architecture.md:602; DESIGN.md:180; EXPERIENCE.md:53,133-142]
- **Every customer string is language-file driven.** No hard-coded labels, instruction text, or feedback in the `.pdt`. Gateway customer copy lives in `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`. [UX-DR28; DESIGN.md:182; NFR6]
- **Loading states must not blank a known value.** If/when a Check Payment Status affordance is enabled, it must preserve the existing Consumer Number / last-known status while checking. [UX-DR21; EXPERIENCE.md:106]

### What 2.5 already shipped (the seam you extend — verified against current code at HEAD)
Story 2.5 is **`done`** and merged (sprint-status `:96`; commits `9caf0fef`/`e43e36c0`/`31d7e27e`/`3d4aef25`). It rebuilt `process.pdt` into the **4-arm tree** and added the testable view-model helpers. The current shape you build on:

1. **`process.pdt` (172 lines) is a 4-arm tree:**
   ```
   if (!empty($process_notice))                         → echo Kuickpay.process.{$process_notice}     (:22-23)
   elseif ($has_payable_reference)                      → PAYABLE PANEL                                 (:24-141)
         identity + Consumer Number(+Copy+inline <script>) + amount + dates + status badge + expectation
   elseif (!empty($voucher) && display_mode==='status_only') → STATUS-ONLY PANEL                       (:142-169)
         identity + status badge + expectation (NO payable Consumer-Number treatment, NO Copy)
   else                                                 → echo Kuickpay.process.retry_safe             (:170-171)
   ```
   `$has_payable_reference` = `!empty($voucher) && $display_mode==='payable' && $status==='pending' && !empty($voucher['kuickpay_reference'])` (`:7-10`). `$show_expectation` is already computed (`:12-16`) and renders 2.5's `Kuickpay.process.status_expectation` line (`:84-88` payable, `:163-167` status_only). **Instruction groups go in a new sibling block in the payable arm, after `.kuickpay-voucher-info` closes (`:90`) and before the `$has_consumer_number` copy-script guard (`:91`); the `<script>` tag itself is at `:92`.**
2. **`buildProcess()` (`:613-694`)** routes via `reloadVoucherDecision()` (`:852`) → `displayVoucherForContext()`/`createVoucherForContext()`/block, computes `display_mode` via `resolveDisplayMode()` (`:883`, returns `null`/`'status_only'`/`'payable'`), and in the `if ($voucher !== null)` block (`:683-688`) sets `voucher`, `status_display` (from `customerVoucherStatusDisplay()` `:898`), `display_mode`, `kuickpay_name`, `institution_id`. **You add `instruction_groups` + `status_check_supported` to that same block.** It returns `$this->view->fetch()` at `:694`.
3. **Testable-seam pattern is established.** `buildProcess()` is **not** harness-drivable (`companionInstalled()` short-circuits it in a bare env), so all testable logic lives in `protected` helpers with `expose*` accessors in `tests/KuickPayVoucherGatewayHelpersTest.php` (e.g. `exposeResolveDisplayMode` `:102`, `exposeCustomerVoucherStatusDisplay` `:107`). **Follow this exact pattern** for `enabledInstructionGroups()` and `customerStatusCheckSupported()`.
[Source: kuickpay.php:613-694,852,883-922,1153; process.pdt:1-172; sprint-status.yaml:96]

### What already exists for instruction groups (do NOT rebuild)
The **four enable toggles already ship** (scaffolded in Epic 1 settings work) — this story does **not** add them:
- `editSettings()` defaults missing checkboxes to `'false'` (`:139-151`) and validates each `instruction_*` against `['in_array', ['true','false']]` (`:313-329`).
- `settings.pdt:222-248` renders the "Instruction Groups" section: a checkbox per channel with display defaults `online_banking=true, bank_deposit=true, agent_franchise=false, mobile_app=false` (`:227-232`), enabled-predicate `(($meta[$field] ?? $default) === 'true')` (`:238`).
- Language has the group header `Kuickpay.group.instruction_groups` (`:33`), the four setting labels + notes `Kuickpay.instruction_<channel>` / `_note` (`:81-88`), and the `Kuickpay.!error.instruction_<channel>.valid` keys.
**Gap this story fills:** the enabled groups are **never rendered on the customer page**, and there is **no customer instruction *content*** (only admin setting labels) and **no status-check affordance**. So 2.6 = (1) a helper that turns the toggles into an ordered enabled-group list, (2) render those groups' localized content in the payable arm, (3) the status-check capability gate, (4) the customer content keys.
[Source: kuickpay.php:139-151,313-329; settings.pdt:222-248; language/en_us/kuickpay.php:33,81-88]

### DECISION D1 (RESOLVED) — instruction content is fixed localized copy, gated by the existing toggles
The story title says "configurable," and the toggles ARE the configuration (enable/disable per channel). **Binding default:** instruction *content* is **fixed, localized copy** in the gateway language file (`Kuickpay.process.instruction.<channel>.*`), shown only for enabled channels. Rationale: (a) the scaffold deliberately chose boolean **checkboxes**, not textareas — if admin-authored free text were intended, settings.pdt would have `fieldTextarea`s; (b) FR13 emphasizes "**localized** Instruction Groups"; (c) UX-DR28 / architecture require all customer copy to be language-file driven and localizable; (d) KuickPay channel steps (pay-by-Consumer-Number via bank/branch/agent/app) are standardized, so fixed copy is correct and maintainable.
**Alternative (NOT default):** admin-editable per-channel textareas rendered via `TextParser` markdown (the `offline` gateway pattern: `offline/views/default/settings.pdt:2-7` + `offline/views/default/process.pdt:2`). This adds settings fields + markdown rendering and makes content non-localizable. See Questions — flip only if Israr wants admin-authored instruction text.

### DECISION D2 (RESOLVED) — customer "Check Payment Status" action is gated OFF in MVP
FR14 shows the action "**only when supported**"; EXPERIENCE.md:66 says the customer action "appears only if current reconciliation capability supports it." Today there is **no customer-callable, payment-safe inquiry capability**: single-inquiry exists at the plugin/service layer (Story 3.3 `done`) but is wired only into reconciliation, not a customer route; posting is Story 3.5 (`backlog`). **Binding default:** `customerStatusCheckSupported()` returns `false`, the affordance is **not rendered**, and the page's status-expectation line tells the customer Blesta updates **automatically** after KuickPay confirms (no manual action needed). The seam is the documented single flip-point + testable gate, so AC3's "Check Payment Status" branch is addressed (the action is wired-but-dark and, when later enabled, must obey the same parser/validation/posting rules and never mark paid outside `KuickPayPostingService`). **Alternative (NOT default):** render a plain page-reload "Check Payment Status" button now — rejected because a reload cannot fetch new evidence (stored status only changes via the plugin's cron reconciliation), so the button would mislead. See Questions.

### DECISION D3 (RESOLVED) — placement & focus order
Instructions render **only in the payable arm**, **after** the status-expectation line and **before** the copy `<script>`. Not in `status_only` (no actionable payable reference there) or the notice/retry arms. This matches the architecture customer-surface order ("Consumer Number, amount, due date, expiry date, copy action, and instruction groups **before secondary content**", architecture.md:420-423) and the accessibility focus order **reference → copy → expectation → instruction groups → support path** (EXPERIENCE.md:117). Consumer Number and amount stay above instructions on phone (EXPERIENCE.md:126).

### DECISION D5 (RESOLVED) — support path is reserved, not authored
UX-DR6 / EXPERIENCE.md:65 say the status-expectation block "includes a support path **when supplied**," and EXPERIENCE.md:148 says the support channel is an **open decision** — "reserve a place for it without naming an unconfirmed channel." **Binding default:** do **not** add a support-path setting or name a channel in this story. The conservative existing copy that references contacting support generically (e.g. `retry_safe`/`not_ready`) is the reserved slot. Support-path wiring is deferred until the channel is decided. Do not invent an email/phone/URL.
**AC2 note (pre-empt a false "missing support path" flag):** AC2 lists "support-path copy" only as a *source/forbidden-content* constraint — any copy that **is** shown must come from language/config and leak no internals; it does **not** require a support-path line to appear. So 2.6 satisfies it vacuously (no support-path line is rendered), and the existing generic "contact support" strings are already language-driven. **Future extension point:** when a support channel is decided, its line renders in the payable arm **after** the instruction groups — preserving the focus order reference → copy → expectation → instruction groups → support path (EXPERIENCE.md:117) — as a new language-keyed view var, with no layout reopening required.

### View tree after this story (payable arm only changes)
```
PAYABLE ARM (process.pdt :24-141):
  .kuickpay-voucher-info  (<dl class="row">, closes </dl> :89, </div> :90):
    identity (kuickpay_name [+ institution_id])
    Consumer Number (text-monospace, data-value) [+ Copy button]
    amount (currency-prefixed, empty-guarded)
    due date / expiry date (formatted, conditional)
    status badge (status_display: posted→success, else non-success)
    status-expectation line  (:84-88, when $show_expectation)
  ── NEW sibling <div> AFTER .kuickpay-voucher-info (:90) and BEFORE the $has_consumer_number guard (:91):   ← THIS STORY
       if (!empty($instruction_groups)) { instructions_heading + foreach groups { title + body } }   (renders NOTHING when all disabled — no orphan heading)
       if (!empty($status_check_supported)) { comment-only placeholder }   (false in MVP → nothing)
  inline copy <script>, guarded by if ($has_consumer_number)  (:91-141)
STATUS-ONLY / NOTICE / RETRY arms: unchanged.
```

### Previous-story intelligence & gotchas (carry forward)
- **Class casing is load-bearing.** Framework-instantiated gateway class is `Kuickpay` (lowercase p); lib services use capital P (`KuickPayVoucherReferenceService`, etc.). Match exactly. [2.4/2.5 notes]
- **Gateway customer/settings strings** live in the gateway language file; **model** validation strings live in the owning per-model language file. Don't cross them. [2.4/2.5 notes]
- **Variable-key `$this->_($var)` lookups are safe only when the key comes from a closed allowlist** (no DB/user input). 2.4's review cleared `Kuickpay.process.{$process_notice}` on exactly that basis (`2-4-…md:312`); 2.5 routed DB `status` through the `customerVoucherStatusDisplay()` allowlist for the same reason. Your `title_key`/`body_key` come from the helper's fixed list — same safety rationale. Never build an instruction key from `$voucher`/contact data.
- **The `.pdt` is not unit-testable** in the component harness — put testable logic in `protected` helpers with `expose*` accessors; verify markup by inspection + manual render. [2.4/2.5 notes]
- **No live Blesta/MySQL render verification has run** in any prior KuickPay story. State the manual-render gap explicitly; do not claim browser-verified responsive/instruction behavior you didn't run. [2.5 notes]
- **Keep diffs small and local to the gateway view layer.** No new top-level dirs, no new CSS/JS asset files, no Blesta-core edits, no ionCube/minified-asset edits, no plugin changes. [project-context.md]
- **Commit convention:** `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars; keep BMad/docs artifacts out of the implementation commit. Allowed types: `feat fix docs test refactor chore`. (e.g. `feat(kuickpay): render configurable payment instructions`.)

### Files to touch
**UPDATE (gateway only):**
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` — add `protected enabledInstructionGroups(array $meta): array` and `protected customerStatusCheckSupported(): bool` (near `:883-915`); in `buildProcess()` `:683-688` add `view->set('instruction_groups', …)` + `view->set('status_check_supported', …)`. **Do not** change routing, gates, `voucherRowToView()`, `reloadVoucherDecision()`, `resolveDisplayMode()`, or `customerVoucherStatusDisplay()`.
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` — in the **payable arm only** (`:24-141`), add a **new sibling `<div>` after the `.kuickpay-voucher-info` `</div>` (`:90`) and before the `<?php if ($has_consumer_number) { ?>` copy-script guard (`:91`)**: an `if (!empty($instruction_groups))`-guarded block (section heading + a loop over `($instruction_groups ?? [])` rendering each channel's localized title + body in normal typography — so an all-disabled config renders **no heading/container**), plus a `(!empty($status_check_supported))`-gated Check Payment Status placeholder (comment-only, renders nothing in MVP). Do **not** put the block inside the `<dl>` or inside the `$has_consumer_number` guard. Bootstrap-only utilities; single-column; wrap each variable-key lookup in `$this->Html->safe($this->_(...))` (mirrors `:80`). Do **not** alter the other three arms.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — add `Kuickpay.process.instructions_heading` and `Kuickpay.process.instruction.<channel>.title`/`.body` for the four channels (after `:29`). Preserve all existing keys.

**Tests:** `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php` — add `exposeEnabledInstructionGroups`/`exposeCustomerStatusCheckSupported` accessors + cases (all-enabled/all-disabled/fresh-install-defaults/mixed; status-check gate false).

**Do NOT touch:** the instruction enable toggles or their settings/validation (`editSettings` `:139-151,313-329`, `settings.pdt:222-248` — already shipped); any `KuickPayPostingService`/transaction/reconciliation/posting path; parser/redactor/SOAP/evidence libs; `plugins/kuickpay_reconcile/`; Blesta core; ionCube/minified assets; `config/blesta.php`; `config.json` (declarative only — no settings live there). No new customer route/controller/AJAX endpoint.

### Project Structure Notes
- Entirely within the gateway view layer + its view-model wiring — no plugin changes, no new directories, no new asset files. Matches architecture Ownership (the gateway owns "customer-facing KuickPay reference display only", architecture.md:520-527, :775-780) and Frontend Architecture (architecture.md:416-439). The panel inherits Blesta client theme (Bootstrap 4.6.2) — no standalone KuickPay visual system (UX-DR25; DESIGN.md:153-155).

### Testing standards
- `php -l` on every changed PHP file + the `.pdt`; component-local PHPUnit 8.5 via the external runner (`cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`) for the two new `protected` helpers. Do **not** use `-c build/phpunit.xml` (bootstrap-path bug). **No root PHPUnit claim** (no sibling `../tests`). Run the full gateway suite; confirm no regression vs the actual pre-change green count (re-run before editing to capture the baseline).
- The `.pdt` (instruction loop, responsive single-column, status-check conditional) is **not** covered by the harness — verify by inspection + manual render in a Blesta client pay flow if a runtime exists; otherwise state the gap. Do not present `php -l` + helper unit tests as full UI verification.
- No live KuickPay calls; no DB-backed render verifiable in this checkout — state it.
[project-context.md Testing Rules]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.6 (553-574)] — story, ACs.
- [Source: _bmad-output/planning-artifacts/epics.md — FR2:27, FR13:49, FR14:51; UX-DR5:160, UX-DR6:162, UX-DR19:188, UX-DR20:190, UX-DR21:192, UX-DR22:194, UX-DR24:198, UX-DR25:200, UX-DR26:202, UX-DR28:206; NFR6:97].
- [Source: _bmad-output/planning-artifacts/architecture.md:410 (buildProcess/customer cannot mark paid), :416-439 (Frontend Architecture; :420-423 instruction groups before secondary content), :520-527 + :775-780 (Ownership / component boundaries), :595-607 (UI Display-State Matrix — customer + Forbidden columns; :602 no raw status), :648-662 (Anti-Patterns; :653,:655 posted-only success)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/DESIGN.md:71-75 (instruction-group component), :122,:132-137 (semantic color; success-only-when-posted), :139-150 (typography/monospace; single-column), :153-159 (no custom elevation; inherit Blesta), :163-166 (customer reference panel + status expectation; status badges), :174-183 (Do/Don't)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/EXPERIENCE.md:26 (reference before instructions), :44-53 (voice/tone Do/Don't; language-file rule), :59-66 (component patterns: instruction group, status expectation, check-status), :99-106 (interaction primitives; loading must not blank), :112-120 (accessibility floor), :126-130 (responsive), :132-148 (payment-safety UX; support path open decision), :148 (reserve support-path slot)].
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php — buildProcess():613-694; meta read:632; voucher!=null view->set block:683-688; reloadVoucherDecision():852; resolveDisplayMode():883-890; customerVoucherStatusDisplay():898-915; voucherRowToView():1153; editSettings instruction defaults:139-151 + validation:313-329].
- [Source: components/gateways/nonmerchant/kuickpay/views/default/process.pdt:1-172 — 4-arm tree; payable arm:24-141; expectation:84-88; copy script:91-141; status_only:142-169; retry_safe:170-171].
- [Source: components/gateways/nonmerchant/kuickpay/views/default/settings.pdt:222-248 — Instruction Groups section; defaults & enabled-predicate:227-238].
- [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php — name:2; process keys:7-29 (status_expectation:20, identity/copy:17-19, status.*:21-29); group.instruction_groups:33; institution_id:49; instruction_<channel> labels/notes:81-88].
- [Source: components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php — subclass-`expose*` + fake-seam harness (exposeResolveDisplayMode:102, exposeCustomerVoucherStatusDisplay:107); `.pdt` not drivable].
- [Source: components/gateways/nonmerchant/offline/views/default/{settings.pdt:2-7, process.pdt:2} + language/en_us/offline.php — admin-textarea + TextParser markdown pattern (the D1 alternative, NOT the default)].
- [Source: _bmad-output/implementation-artifacts/2-5-display-customer-payment-reference-panel.md — panel/4-arm tree, status map, status_expectation, testable-seam pattern (now shipped/done at HEAD)].
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, loader/Input/Record/language rules, `.pdt` view rules, testing/tooling.

### Open questions for Israr (do not block implementation — binding defaults above apply unless you say otherwise)
1. **Instruction content source (D1):** Default is **fixed localized copy** per channel, gated by the existing toggles. Switch to **admin-editable per-channel textareas** (offline-gateway `TextParser` markdown pattern)? That adds settings fields and makes content non-localizable.
2. **Check Payment Status (D2):** Default is **OFF / not rendered** in MVP (no safe customer inquiry capability yet). Want a visible **page-reload** "Check Payment Status" button now (re-shows current stored status, no live fetch), or keep it dark until Epic 3 wires a payment-safe customer inquiry?
3. **Support path (D5):** Default is **no support-path line / no named channel** (open decision per UX). Is there a confirmed support contact (email/phone/URL or a help-page route) to surface now?

## Dev Agent Record

### Agent Model Used

_TBD by dev agent_

### Debug Log References

### Completion Notes List

### File List
