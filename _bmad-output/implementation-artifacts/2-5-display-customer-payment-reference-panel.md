---
baseline_commit: a7c238539805a58abe98d8e045285aaeca86bec1
---

# Story 2.5: Display Customer Payment Reference Panel

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a customer,
I want the Consumer Number, amount, due date, expiry date, and KuickPay identity shown clearly,
So that I can complete payment through a supported external channel.

## Acceptance Criteria

**AC1 — Reference panel content + responsive layout (FR12, UX-DR3, UX-DR22, UX-DR25, UX-DR26)**
**Given** a Pending Voucher exists
**When** the customer views the KuickPay payment page
**Then** Consumer Number, payable amount, due date, expiry date, and KuickPay identity appear before instructions
**And** the layout is usable on phone and desktop widths without horizontal scrolling.

**AC2 — Copy Consumer Number action (FR12, UX-DR4, UX-DR24)**
**Given** the customer uses the Copy Consumer Number action
**When** the action completes
**Then** only the Consumer Number is copied
**And** temporary feedback is shown without changing payment state.

**AC3 — Conservative, localized status copy + posted-only success styling (UX-DR19, UX-DR20, UX-DR28)**
**Given** the Voucher is Pending, retrying, awaiting confirmation, posted, failed, expired, under manual review, or cancelled
**When** the customer views the page
**Then** customer status copy is conservative and localized for that state
**And** success styling appears only when the Voucher is posted.

## Tasks / Subtasks

- [x] **Task 1 — Rebuild `process.pdt` into the Customer Reference Panel (AC1)**
  - [x] Replace the current minimal payable panel (`process.pdt:1-19`) with a single-column-first panel that renders, in this reading order: **KuickPay identity → Consumer Number (with Copy action) → payable amount → due date → expiry date → status line → status-expectation line.** All of this renders **before** any instruction content (configurable instruction groups themselves are Story 2.6).
  - [x] Use only Bootstrap 4.6.2 / Blesta-inherited classes (`row`, `col-12`, `col-md-*`, `text-monospace`, `badge badge-*`, `font-weight-bold`, spacing utilities). **No new CSS file, no marketing shadows/gradients/floating panels** (UX-DR25; DESIGN.md:153-155).
  - [x] Render the Consumer Number in `class="text-monospace"`; keep labels and amount in normal inherited typography (UX-DR26; DESIGN.md:43-48). Do **not** render the whole panel in monospace.
  - [x] Do **not** use a `<table>` on this customer surface. Use stacked `div`/`dl` rows so phone width is single-column with no horizontal scroll (UX-DR22; EXPERIENCE.md:126).
  - [x] Fit the panel into the **4-arm view tree** that extends what Story 2.4 shipped (see Dev Notes → "What Story 2.4 actually shipped" and "The 4-arm view tree 2.5 builds"). 2.4 left a 3-arm tree (`payable → process_notice → retry_safe`); 2.5 rebuilds it into the 4-arm order **`process_notice → payable → status_only → retry_safe`** (`process_notice` keeps top precedence — see Dev Notes → "The 4-arm view tree 2.5 builds"; do **not** keep the old payable-first order), inserting the `status_only` arm and switching the panel selector from the inline `status==='pending'` literal to the `display_mode` view flag set in `buildProcess()`.
- [x] **Task 2 — Copy Consumer Number control + temporary feedback (AC2)**
  - [x] Add a real `<button type="button">` adjacent to the Consumer Number, keyboard-reachable, with a visible text label or `aria-label` ("Copy Consumer Number") (UX-DR4, UX-DR24; EXPERIENCE.md:97-99,108-121). **Render the Copy button (and the `text-monospace`/`data-value` hook) only when `consumer_number` is non-empty** — `voucherRowToView()` casts a null DB field to `''`, so guard against a partial-issuance edge where `kuickpay_reference` is set but `consumer_number` is blank; copying an empty string with a "Copied" message is a confusing failure. If blank, show the label line without the button.
  - [x] On click, copy **only** the Consumer Number string via an inline `<script>` (`navigator.clipboard.writeText(...)` with a `document.execCommand('copy')` + hidden-`<textarea>` fallback for insecure-origin/older browsers). Inline-body `<script>` in a gateway `process.pdt` is an established pattern — `paypal_checkout/views/default/process.pdt` (inline block, lines 4-17) and `kassacompleetideal/views/default/process.pdt` (inline block, plain JS, lines 12-24) both ship one; **not** `blockonomics` (it only loads an external `src` script — don't model on it). None do clipboard copy, so write a small **vanilla-JS** snippet (no jQuery); read the value from the Consumer Number node's `data-value`/`textContent`, never a server-rendered JS string literal. Minimal sketch in Dev Notes → Copy action design.
  - [x] Show temporary feedback (swap the button label to "Copied" / reveal an adjacent `role="status"` / `aria-live="polite"` message) **only on an actual successful copy**, reverting after a short delay (EXPERIENCE.md:60-63).
  - [x] `type="button"` + `preventDefault` so the action **never** submits a form or mutates payment state (UX-DR4; EXPERIENCE.md:97-99). The panel already renders **outside** the Blesta form (`client_pay_confirm.pdt:96` `Form->end()` precedes the `gateway_buttons` loop at `:98-112`), so this is defensive but required.
  - [x] All copy/feedback strings come from the gateway language file (UX-DR28). No hard-coded UI text.
- [x] **Task 3 — Conservative status→label/badge map in a testable helper (AC3)**
  - [x] Implement the status→display map as a **mandatory `protected` helper** in `kuickpay.php` — `customerVoucherStatusDisplay(string $status): array` returning `['label_key' => 'Kuickpay.process.status.<state>', 'badge' => 'badge-<variant>']` — with an `exposeCustomerVoucherStatusDisplay()` accessor (mirror the existing `expose*` test seams). The `.pdt` renders that contract; it does **not** own the map (see Dev Notes → "Why the view must not concatenate the status into a key").
  - [x] **Wire the helper result into the view model (required — the `.pdt` cannot call a `protected` gateway method).** In `buildProcess()`, wherever a non-null `$voucher` is set (the `payable` and `status_only` paths), compute `$status_display = $this->customerVoucherStatusDisplay((string) ($voucher['status'] ?? ''))` and `$this->view->set('status_display', $status_display)` alongside the existing `view->set('voucher', …)` at the `:679` gate. The `.pdt` then renders **only** `$status_display['label_key']` (via `$this->_(...)`) and `$status_display['badge']` — never a status-key it builds itself, and never the dropped raw-status fallback. Without this handoff the map is unreachable from the template and the dev would be forced to re-introduce the forbidden in-view key lookup.
  - [x] **Safe default — never echo the raw status.** For any unmapped/empty status the helper returns a neutral non-success entry (`Kuickpay.process.status.unknown` + `badge-secondary`). **Drop** the current `process.pdt:5-7` fallback that prints the raw status key (`$voucher['status']`) when a label is missing — that would leak `manual_review`/`confirmed_unposted` to the customer (UX-DR28).
  - [x] Apply success styling (`badge-success` / "Payment received" wording) **only** when `status === 'posted'` — architecture-mandated (UX-DR20; architecture.md:655; DESIGN.md:80-83). Every other state uses a non-success badge per the Dev Notes matrix; those specific colors are **story-authored design guidance**, not architecture-mandated.
  - [x] Every badge must be paired with adjacent readable status text — color is never the only signal (UX-DR19; EXPERIENCE.md:108-121).
  - [x] Include a conservative status-expectation line via the **named key `Kuickpay.process.status_expectation`** ("Blesta marks this invoice paid only after KuickPay confirms your payment") (UX-DR6; FR14) — never a hard-coded literal. Show it in `payable` and the **non-terminal** `status_only` states (`retry`, `confirmed_unposted`, `failed`, `manual_review`); **omit it on `posted`** (already received — it would contradict the success copy) **and on the terminal `expired`/`cancelled` states** ("Blesta marks this paid only after KuickPay confirms" contradicts a reference that is no longer payable). A simple rule for the dev: show the expectation line only when `display_mode === 'payable'`, or when `display_mode === 'status_only'` and the status is **not** in `{posted, expired, cancelled}`. The **configurable** instruction groups and any "Check Payment Status" action are Story 2.6 — do not build them here.
- [x] **Task 4 — Surface KuickPay identity + route non-pending statuses to the view (AC1, AC3)**
  - [x] In `buildProcess()` pass the KuickPay identity to the view via `$this->view->set(...)`: `kuickpay_name` (`Language::_('Kuickpay.name', true)`) and optionally `institution_id` (`$meta['institution_id']`, already read at `kuickpay.php:548,747`). **Do not modify `voucherRowToView()`'s return shape** to carry identity — identity is a sibling view var, not a voucher-row field.
  - [x] Set the **`display_mode`** view flag in `buildProcess()`: `'payable'` whenever a non-null payable voucher (pending + reference) is set (the display/create branches, `:679-680`); `'status_only'` for existing non-pending **block-state** vouchers. **Lifecycle — follow the `$voucher` pattern, not the `process_notice` pattern:** set a local `$display_mode` in each branch, then `$this->view->set('display_mode', $display_mode)` inside the `if ($voucher !== null)` gate at `:679` (the `.pdt` reads `($display_mode ?? '')`, so it must reach the view as a var or the `status_only` arm is dead code). Leave it unset on the `process_notice`/`retry_safe` paths — the `?? ''` default handles those. **Decision A (resolved — Dev Notes):** in the `block` branch (`kuickpay.php:668-669`) set `$voucher = $this->voucherRowToView($latest)` **and** `display_mode = 'status_only'` instead of `$voucher = null`, so AC3's `retry/confirmed_unposted/failed/manual_review/posted` branches are reachable in the live flow, not only via a view render test. `$latest` is guaranteed non-null in the block branch (`reloadVoucherDecision(null)` returns `'allow'`, not `'block'` — `kuickpay.php:846-847`). Showing real existing state must **not** route to `recordReferenceGenerationFailure()` (`:681-683`); the existing `if ($voucher !== null)` gate at `:679` already prevents that once `$voucher` is non-null.
  - [x] Keep the **payable** treatment (Consumer Number value + Copy + expectation) for `pending`(+reference) only; do **not** change `expired`/`cancelled`/`failed`-credential → `allow` (`:861-863, :857-859`) — they regenerate a fresh payable voucher — and do **not** weaken any safety write, the payable gate, or the multi-invoice/amount-change gates 2.4 added.
- [x] **Task 5 — Language keys (AC1/AC2/AC3)**
  - [x] Add these new customer keys to `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` with **these exact names**:
    - `Kuickpay.process.identity_label` — KuickPay identity heading.
    - `Kuickpay.process.copy_button` — Copy-action label / `aria-label`.
    - `Kuickpay.process.copy_feedback` — "Copied" temporary feedback.
    - `Kuickpay.process.status_expectation` — the conservative expectation line.
    - `Kuickpay.process.status.<state>` — one per canonical state (`pending` already exists at `:17`; add `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`) **plus** a neutral `Kuickpay.process.status.unknown` for the safe default. For `unknown`, use conservative, non-alarming, non-technical wording (e.g. "Reference status unavailable") — never "error", "contact support", or any raw/internal state name.
  - [x] **Preserve Story 2.4's keys** — do not remove or rename `Kuickpay.process.amount_changed` (`:9`) / `Kuickpay.process.multi_invoice_unsupported` (`:10`), nor the Story 2.1/2.3 labels `consumer_number_label`/`amount_label`/`status_label`/`due_date_label`/`expiry_date_label` (`:12-16`). Reuse the existing labels; add only the new keys above.
  - [x] Conservative wording only — no "paid"/"received"/success language for any state except `posted`; no raw provider status, SOAP names, parser fields, credentials, or exception classes (UX-DR28; architecture.md:655,669-670).
- [x] **Task 6 — Verification**
  - [x] `php -l` on every changed PHP file (`kuickpay.php`, language file) and the `.pdt`.
  - [x] **Required** unit tests in `tests/KuickPayVoucherGatewayHelpersTest.php` (subclass-`expose*` pattern, already used for `displayVoucherForContext`/`createVoucherForContext` and others): cover `customerVoucherStatusDisplay()` — `posted → badge-success`; and assert **non-success for each** of the seven non-posted states explicitly (`pending`, `retry`, `confirmed_unposted`, `failed`, `expired`, `manual_review`, `cancelled`), plus unmapped/empty → neutral non-success default. Do **not** rely on a single representative state. These guard AC3's posted-only-success invariant, which the `.pdt` cannot assert in the harness. **Recommended (cheap, catches matrix drift):** beyond the non-success floor, assert the **exact** badge variant for the states whose color is story-authored design guidance — especially the deliberate `failed → badge-info` override (a non-success-only floor would let a regression to `badge-danger` pass), `manual_review → badge-warning`, and `expired`/`cancelled → badge-secondary`.
  - [x] **Required — extract and test the `display_mode` decision.** Decision A (routing `block`-state vouchers to `status_only` instead of `retry_safe`) is the headline behavioral change of this story and the thing that makes AC3 reachable in the live flow, yet `buildProcess()` is not harness-drivable (`companionInstalled()` short-circuits it in a bare env). So extract the routing into a `protected` helper **keyed off the resolved voucher** — `resolveDisplayMode(?array $voucher, string $decision): ?string` — with an `expose*` accessor. Logic: `$voucher === null → null` (the `process_notice`/`retry_safe` path); `$decision === 'block' → 'status_only'` (Decision A); otherwise `→ 'payable'`. **Do not key it off the pre-decision `$latest`+`$decision`** — for `decision='display'` a pending+ref `$latest` can still resolve to `voucher=null` on amount-mismatch/replace-fail (`kuickpay.php:900,913,923`), and `allow`/`issue` can resolve to `null` too (`:958,:961`), so `($latest,$decision)` cannot tell `payable` from no-voucher and cannot express the `null` case. Keying off the resolved `$voucher` makes the helper the genuine authority — it is the exact value the `:679` gate already holds (Task 4 lifecycle note). Call it per branch on the resolved voucher (the `block` branch passes `voucherRowToView($latest)`; the display/create branches pass their returned voucher), then `view->set('display_mode', …)` at `:679`. **Required tests:** `resolveDisplayMode(null, 'display') → null`; `resolveDisplayMode(<row>, 'block') → 'status_only'`; `resolveDisplayMode(<row>, 'allow'|'issue'|'display') → 'payable'`. Without this, the story's central behavior could ship with **zero** automated coverage.
  - [x] **"One decision surface" means one owner per output, not one fat method.** 2.4's coordination note asks 2.5 to reconcile its routing into one decision surface — read that as: `voucher`/`process_notice` stay owned by the existing `displayVoucherForContext()`/`createVoucherForContext()` seams (**do not fold them into `resolveDisplayMode()`** — wrapping two thin seams in a third recreates the overlap the note warns against); `display_mode` is owned solely by `resolveDisplayMode()` (consuming their result); `status_display` is **presentation**, owned solely by `customerVoucherStatusDisplay()` — it is **not** part of the routing decision. All four outputs are consumed together at the single `:679` gate; that gate is the reconciliation surface.
  - [x] **Identity assembly:** the `kuickpay_name`/`institution_id` view vars are plain `view->set()` calls inside the non-drivable `buildProcess()`, so they are **not** unit-testable directly. Either fold identity into the same testable view-model helper above (return `kuickpay_name`/`institution_id` alongside `display_mode`/`status_display`) and assert it there, **or** verify identity by inspection/manual render and say so — do **not** leave "identity assembly" as a required unit test with no seam behind it.
  - [x] The `.pdt` itself is **not** drivable in the component harness — verify panel/copy/responsive/styling by inspection + manual render, and say so explicitly (see Testing standards). Run the full gateway suite to confirm no regression of 2.4's 180 tests.

## Dev Notes

### Critical gates & invariants (read first)
- **`buildProcess()` cannot mark paid — and neither can the customer.** This story is display-only. No path here may create/apply a Blesta transaction, call `markPaid`/`recordPayment`, update invoice status, or set `posted`/`confirmed_unposted`. Only `KuickPayPostingService` (Story 3.5, `backlog`) pays an invoice. [architecture.md:651-652,669-670; FR17]
- **Success styling is posted-only.** "Payment received", green checks, paid-receipt language, or `badge-success` must appear **only** at `status === 'posted'`. "Voucher generated" / a visible Consumer Number is **not** paid. [UX-DR20; architecture.md:655; DESIGN.md:80-83; EXPERIENCE.md:133-142]
- **Copy / refresh / return never change payment state.** The Copy action and any page reload are inert with respect to paid state (`type="button"`, no form submit, no write). [UX-DR4; EXPERIENCE.md:60-63; FR14]
- **No raw diagnostics in customer view.** Never render raw SOAP/XML, provider status strings, parser field names, credentials, stack traces, exception classes, the **Registration Number** (`raw_status`/`error_class` are admin-only), or admin-review internals on the customer panel. The customer reference is the **Consumer Number** only. [UX-DR28; architecture.md UI Display-State Matrix "Forbidden" column (`:599-606`); EXPERIENCE.md:133-142]
- **Every customer string is language-file driven.** No hard-coded labels, status copy, or feedback text in the `.pdt`. Gateway customer copy lives in `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`. [UX-DR28; 2.4 carry-forward]
- **Loading states must not blank a known value.** If you add any "checking" affordance, preserve the existing Consumer Number / last-known status rather than clearing it. [UX-DR21; EXPERIENCE.md:108-121]

### The view-model contract this story consumes (verified against current code)
`buildProcess()` (`kuickpay.php:613`) hands the view a flat `$voucher` array via `voucherRowToView()` (`:1104-1122`). Fields available **today**:
`id, company_id, client_id, gateway_id, currency, amount, status, registration_number, consumer_number, kuickpay_reference, raw_status, date_due, date_expires, invoices`.
- `voucherRowToView()` **hard-codes `'invoices' => []`** (`:1120`). The per-invoice breakdown that 2.4's comparator loads (`displayVoucherForContext` `:889-892`) is a **local** widening for the comparator only — it is never returned in the row handed to the view. So the customer panel never sees an invoice breakdown; do not try to render one (out of scope; it would require loads the view must not perform).
- `amount` is a normalized **decimal string** (e.g. `"1234.00"`), not a float and not currency-formatted. Display it with the `currency` code (PKR) — e.g. `PKR 1,234.00` — but never run float math or mutate the stored value (display formatting only). Minimal-safe display: `currency` + the stored decimal string. Optional: load `CurrencyFormat` for grouping; it is **not** loaded today (`buildProcess` loads only `Html` at `:618`).
- **Empty `amount`/`currency` guards.** `amount` can be `''`; if empty, omit the amount line — never render a bare "PKR " with no number. `currency` can also be `''` (`voucherRowToView()` casts a null to `''`, `:1111`); prepend it only when non-empty, so an empty currency renders the amount with no prefix rather than a leading bare space.
- `date_due` / `date_expires` may be empty and arrive as raw DB datetime strings — the current panel echoes them verbatim (`process.pdt:14,17`). Render them human-readably and keep both conditional. The gateway already has `formatVoucherDate(string $ymdDate): string` (`:1187-1192`, `exposeFormatVoucherDate` in the harness) which returns `date('d-M-y', strtotime($ymdDate))` (e.g. `09-Jun-26`) or `''`; reuse it for display (read-only) or use a `Date`/`strtotime` format. Never echo a raw `YYYY-MM-DD HH:MM:SS`, and never mutate the stored value.
- **Show Consumer Number, not Registration Number, to the customer.** AC1 and UX-DR3 specify the Consumer Number (the payable reference). Registration Number stays admin-side.
- **Missing for AC1:** KuickPay identity. Add `kuickpay_name` (+ optional `institution_id`) to the view via `$this->view->set(...)` in `buildProcess()` — **separate view vars**, not voucher-row fields. `Kuickpay.name` = "KuickPay" (`language:2`); `institution_id` is a merchant identifier (already embedded in the Consumer Number) — not a secret, safe to show, but optional/secondary. In the `.pdt`, render it only when `!empty($institution_id)`; if empty, show `kuickpay_name` alone as the identity heading (no empty/bare label line).

### What Story 2.4 actually shipped (the seam you extend — read before Task 1/4)
Story 2.4 is **`done`** (sprint-status.yaml; code-reviewed, all 5 ACs verified — see `2-4-...md:296-312`). It already restructured the **same three files** this story touches, so 2.5 **extends** a real, working seam — there is no "coordinate with an unbuilt story" anymore. Verified current shape:

1. **`process.pdt` is a 3-arm tree, payable-first** (lines 1-25):
   ```
   if (!empty($voucher) && $voucher['status']==='pending' && !empty($voucher['kuickpay_reference']))  → payable panel (Story 2.1/2.3 minimal)
   elseif (!empty($process_notice))   → echo Kuickpay.process.{$process_notice}   (2.4: amount_changed / multi_invoice_unsupported)
   else                               → echo Kuickpay.process.retry_safe
   ```
   2.4 used a **single `process_notice` view flag** (string literal, set in PHP). It did **not** add a `display_mode` flag and did **not** adopt a "process_notice-first" order. 2.5 introduces `display_mode` and the `status_only` arm.
2. **`buildProcess()` (`:613-687`)** routes via `reloadVoucherDecision()` (`:844-866`), which returns one of `allow | issue | display | block`:
   - `null` latest → `allow`; `pending`+no-ref → `issue`; `pending`+ref → `display`; `failed`+`credential_error` → `allow`; `expired`/`cancelled` → `allow`; **everything else** (`retry`, `confirmed_unposted`, `posted`, `failed`-non-credential, `manual_review`) → `block`.
   - `display` → `displayVoucherForContext()` (`:879-925`) → returns `['voucher' => <pending+ref row>, 'process_notice' => null]` on match, or `['voucher' => null, 'process_notice' => 'amount_changed']` on mismatch/replace-fail.
   - `block` → `$voucher = null` (`:668-669`) → no `process_notice` → falls to `recordReferenceGenerationFailure()` (`:682`) → generic `retry_safe`. **← this is the branch Decision A changes.**
   - `allow`/`issue` → `createVoucherForContext()` (`:940-962`) → returns a freshly created/issued **pending+ref** voucher, or null (+optional `amount_changed`).
   - After the branch: `if ($voucher !== null) view->set('voucher', $voucher)` (`:679-680`) `elseif ($service !== null) recordReferenceGenerationFailure(...)` (`:681-683`).
3. **The testable-seam pattern is established.** Because `buildProcess()` is **not** harness-drivable (`companionInstalled()` short-circuits it in a bare env), 2.4 extracted `displayVoucherForContext()`/`createVoucherForContext()` as `protected` seams with fake-injection tests in `tests/KuickPayVoucherGatewayHelpersTest.php`. **Follow this exact pattern**: put 2.5's testable logic (`customerVoucherStatusDisplay()`, and any `display_mode` decision) in `protected` helpers with `expose*` accessors; verify the `.pdt` by inspection + manual render.
[Source: kuickpay.php:613-687,844-866,879-962,1104-1122; process.pdt:1-25; 2-4-...md:263-274,296-312]

### Why the view must not concatenate the status into a key (load-bearing carry-forward)
2.4's code review explicitly cleared `process.pdt`'s `Kuickpay.process.{$process_notice}` concatenation as safe **only because "only hard-coded literals flow in, no input source"** (`2-4-...md:312`). For 2.5, the voucher **`status` is a DB-sourced value**, so building `Kuickpay.process.status.{$status}` in the view would reintroduce an input-driven key lookup and could surface an unmapped raw status to the customer. **Map through the `customerVoucherStatusDisplay()` allowlist helper instead** — it returns a fixed `label_key` from a closed set and a neutral default for anything unmapped. This is the same reason Task 3 forbids the `process.pdt:5-7` raw-status fallback.

### The 4-arm view tree 2.5 builds (extends 2.4's tree)
**`process_notice` keeps top precedence** — this matches the contract 2.4 handed forward (the 2.4 view-gating task explicitly specifies the 2.5 tree as `process_notice → payable → status_only → retry_safe` and states "process_notice keeps top precedence"). A `voucher` and a `process_notice` are mutually exclusive at the source today (every branch nulls `$voucher` whenever it sets a notice — verified across the multi-invoice, `display`, and `create` branches), so ordering is **behavior-neutral now**; notice-first is chosen anyway for (a) cross-story consistency with 2.4's documented contract and (b) defense-in-depth — if a future change ever set both, a warning notice (e.g. `amount_changed`) should win over rendering a possibly-stale payable panel. Insert `status_only` directly after `payable`:
```
if (!empty($process_notice))                                        → echo Kuickpay.process.{$process_notice}  (2.4, unchanged)
elseif (!empty($voucher) && ($display_mode ?? '') === 'payable')    → payable panel
      (identity + Consumer Number[+Copy] + amount + dates + status badge + expectation)
elseif (!empty($voucher) && ($display_mode ?? '') === 'status_only') → status-only panel
      (identity + status badge + expectation; NO payable Consumer-Number treatment, NO Copy, NO amount-as-payable)
else                                                                → echo Kuickpay.process.retry_safe          (2.4, unchanged)
```
Belt-and-suspenders: keep the explicit `status==='pending' && kuickpay_reference` assertion in the payable arm (or guarantee it in `buildProcess` before setting `display_mode='payable'`) so the payable treatment can never render for a non-payable row. Do **not** flatten or re-key 2.4's `process_notice`/`retry_safe` arms.

### DECISION A (RESOLVED) — `buildProcess()` routes block-state vouchers as `status_only`
AC3 enumerates `pending/retry/confirmed_unposted/failed/expired/manual_review/posted` (+`cancelled` defensively). Today only `pending`(+ref) and freshly-issued/replaced pending vouchers reach the view; all `block` states collapse to `retry_safe`.

**Resolution (binding default; architect may override at sign-off):**
1. **Always** implement the full status map via `customerVoucherStatusDisplay()` (Task 3) so the panel renders conservative copy + posted-only styling for *any* status it receives.
2. **In the `block` branch (`:668-669`)** set `$voucher = $this->voucherRowToView($latest)` and `display_mode = 'status_only'` instead of `$voucher = null`. `$latest` is non-null there (proved at `:846-847`). The `if ($voucher !== null)` gate (`:679`) then runs `view->set('voucher', …)` and **skips** `recordReferenceGenerationFailure()` — correct, because displaying real existing state is not a generation failure.
3. Keep the **payable** treatment for `pending`(+ref) only (`display_mode='payable'` on the display/create non-null returns). Do **not** change `expired`/`cancelled`/`failed`-credential → `allow` (they regenerate a fresh payable voucher), and do **not** weaken any safety write or the 2.4 gates.
4. **Required (see Task 6):** extract the `display_mode` decision into a `protected` helper with an `expose*` accessor for unit coverage (full `buildProcess()` is not harness-drivable). This is the only automated guard that Decision A's routing — the change that makes AC3 live-reachable — actually behaves.

**If the architect overrides** to the view-only alternative (leave `buildProcess` routing as-is; non-pending states keep generic `retry_safe` in the live flow): then AC3's `retry/confirmed_unposted/failed/manual_review/posted` branches are verified at the view layer only and are **not reachable end-to-end**. Record that explicitly in the Dev Agent Record. The binding default above is preferred precisely because it makes AC3 end-to-end-true.

### UI Display-State Matrix — the customer column you must implement (AC3)
[Source: architecture.md:595-607 for the **Customer label** column. The **Styling** (badge) column is **story-authored design guidance** — the architecture matrix has no badge column. Only two rules are architecture-mandated: `posted` is the sole success state, and no success styling may appear before `posted` (architecture.md:655). DESIGN.md:76-95 supplies badge color intent; the customer-panel `failed` override below is deliberate.]

| Voucher State | Customer label (conservative, architecture.md:599-606) | Badge | Payable CTA (Copy)? |
|---|---|---|---|
| `pending` | Payment reference created — awaiting payment | `badge-info` | **yes** (only state with payable CTA) |
| `retry` | Confirmation delayed | `badge-info` | no |
| `confirmed_unposted` | Waiting for payment confirmation | `badge-info` | no |
| `posted` | Payment received | **`badge-success` (only here)** | no (receipt/status) |
| `failed` | Confirmation delayed | `badge-info` (customer-surface override — see below) | no |
| `expired` | Payment reference expired | `badge-secondary` | no (flow regenerates) |
| `manual_review` | Payment under review | `badge-warning` | no |
| `cancelled` | Payment reference cancelled | `badge-secondary` | no (flow regenerates) |
| _unmapped/empty_ | (neutral, `…status.unknown`) | `badge-secondary` | no |

Map these through `customerVoucherStatusDisplay()` (Task 3). All four badge variants exist in Blesta's Bootstrap 4.6.2 (`application.css`: `.badge-success:5719`, `.badge-info`/`.badge-warning`/`.badge-secondary` present). `pending` label already exists at `language:17`; reuse it.

**`failed` badge = `info` (customer-surface override of DESIGN.md:84-87).** DESIGN.md maps `failed → danger` (red). This story **deliberately departs** *on the customer reference panel only*: (1) the architecture matrix gives `failed` and `retry` the **same** conservative customer label ("Confirmation delayed") — identical text with a different color makes color the *only* signal (violates UX-DR19); (2) red/`danger` fights the conservative, no-alarm tone this surface enforces (the customer cannot act on `failed` here; recovery is staff/reconciliation-side). **Scoped strictly to the customer panel — admin surfaces keep DESIGN.md's danger styling and the internal `failed` vs `retry` distinction.** Implement `failed → badge-info`. (Architect may override at sign-off — see Questions.)

**Status reachability (sets tester/reviewer expectations).** With Decision A, the live-reachable states are `pending` (payable) and the non-terminal `status_only` states (`retry`/`confirmed_unposted`/`failed`/`manual_review`). `expired`/`cancelled` → `allow` → a **fresh pending** voucher (customer gets a new payable panel, not the expired/cancelled label). `posted` is **not yet reachable live** — the posting service is Story 3.5 (`backlog`), so no voucher reaches `posted` until that ships; Blesta then renders its own paid-invoice UI. So `posted`/`expired`/`cancelled` labels are **defensive** (correct to include for a robust safe default), not states the customer normally reaches through this panel today. The `status_only` copy for `retry`/`failed` ("Confirmation delayed" + expectation) is intentionally **action-light** — next-step guidance is Story 2.6; until then these panels inform without instructing.

### Copy action design (AC2)
- Plain inline `<script>` (no jQuery dependency needed; jQuery + Bootstrap 4 are present in the client area, but vanilla `navigator.clipboard.writeText` is cleaner). Verified inline-body precedent: `paypal_checkout/views/default/process.pdt` (inline block, lines 4-17) and `kassacompleetideal/views/default/process.pdt` (inline block, plain JS, lines 12-24). **Not** `blockonomics` — it only loads an external `src` script. None do clipboard copy, so the snippet is new; minimal sketch:
  ```
  (function () {
    var btn = document.getElementById('kp-copy-btn'),
        src = document.getElementById('kp-consumer-number'),   // carries data-value="<consumer number>"
        fb  = document.getElementById('kp-copy-feedback');      // carries data-copied="<?php echo $this->_('Kuickpay.process.copy_feedback'); ?>"
    if (!btn || !src) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var v = (src.dataset.value || src.textContent).trim();
      function done() { fb.textContent = fb.dataset.copied; setTimeout(function () { fb.textContent = ''; }, 2000); }
      function fail() { /* do nothing destructive; optionally select the text */ }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, fail); }
      else { var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); var ok = false; try { ok = document.execCommand('copy'); } catch (err) {} document.body.removeChild(t); ok ? done() : fail(); }
    });
  })();
  ```
  (Illustrative — feedback text comes from the language key via `data-copied`, not a JS literal; the dev refines.)
- **Honesty:** show "Copied" only on an **actual** successful copy — the rejection handler must **not** announce success (the sketch above routes rejection/`execCommand` failure to `fail()`, not `done()`).
- Read the Consumer Number from a stable hook (the `text-monospace` element's `data-value`/`textContent`) and copy **only** that value (UX-DR4). Do **not** inject the Consumer Number or feedback text into a server-rendered JS **string literal** (`'<?php echo … ?>'`) — that needs JS-escaping and risks breakage/injection; read both from the DOM.
- Keyboard reachable (`<button>`, focusable) and labeled for screen readers; copy-success announced via an adjacent `role="status"`/`aria-live="polite"` region (EXPERIENCE.md:108-121; UX-DR24). Prefer leaving the announced text until the next copy (or use `role="status"`) so screen readers don't announce "blank" when a timer clears it.
- Never throw a JS dialog (no `alert`/`confirm`) — see harness rule on modal dialogs. Feedback is temporary and visual-only; it must not toggle any paid/processing state.

### Responsive & accessibility floor
- Single-column on phone; Consumer Number + amount stay above instructions; no horizontal scroll (UX-DR22; EXPERIENCE.md:126). Desktop may widen but Consumer Number stays first (DESIGN.md:147).
- Bootstrap 4.6.2 utilities confirmed present in `app/views/client/bootstrap/css/application.css`: `.text-monospace` (`:11022`), `.badge-success` (`:5719`) + other `.badge-*`, `.sr-only` (`:8716`). (This is BS4 — `.font-monospace` is BS5 and does **not** exist here; use `.text-monospace`.)
- Keyboard focus order = reference → copy → expectation → (instructions, Story 2.6) (EXPERIENCE.md:108-121). WCAG 2.2 AA target (UX-DR24).

### Previous-story intelligence & gotchas (carry forward)
- **Story 2.4 was implemented by GPT-5 Codex and code-reviewed (bmad-code-review, YOLO).** All 5 ACs verified; 1 patch applied (added `createVoucherForContext` seam + its tests), 5 deferred, 7 dismissed. None of 2.4's deferrals block 2.5 (the `replace`/`allow` policies are code-gated out of production until Epic 3 posting; the customer panel sees mostly `block`-policy states). [2-4-...md:294-312]
- **Class casing is load-bearing.** Framework-instantiated gateway class is `Kuickpay` (lowercase p); lib services use capital P (`KuickPayVoucherReferenceService`, `KuickPayEvidence`, etc.). Match exactly. [2.4 notes]
- **Gateway customer/settings strings** live in the gateway language file; **model** validation strings live in the owning per-model language file. Don't cross them. [2.4 notes]
- **The `.pdt` view is not unit-testable in the component harness.** Tests subclass `Kuickpay` and call `expose*` helpers with fake seams; `companionInstalled()` short-circuits full `buildProcess()` in a bare env. Put any testable logic in a `protected` gateway helper with an `expose*` accessor; verify the `.pdt` markup/JS by inspection + manual render. [2.4 notes; 2-4-...md:298]
- **No live Blesta/MySQL render verification has run** in any prior KuickPay story. State the manual-render gap explicitly; do not claim browser-verified responsive/clipboard behavior you didn't run. [2.4 notes:261]
- **Keep diffs small and local to the gateway view layer.** No new top-level dirs, no new CSS/JS asset files, no Blesta-core edits, no ionCube/minified-asset edits. [project-context.md]
- **Commit convention:** `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars; keep BMad/docs artifacts out of the implementation commit. Allowed types: `feat fix docs test refactor chore`.

### Files to touch
**UPDATE (gateway only):**
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` — rebuild into the Customer Reference Panel inside the **4-arm tree** (`process_notice → payable → status_only → retry_safe`): identity, Consumer Number (`text-monospace`, `data-value`) + Copy button + inline copy `<script>`, amount (currency-prefixed, empty-guarded), formatted due/expiry, status badge via the `status_display` view var (set from `customerVoucherStatusDisplay()` in `buildProcess()`), status-expectation line, responsive single-column markup. **Escape every dynamic value with `$this->Html->safe(...)`** — including `consumer_number`, `amount`, `currency`, formatted dates, `institution_id`, and `kuickpay_name`; this also applies in **attribute** context (`data-value="…"` on the Consumer Number node — Blesta's `Html->safe()` uses `htmlspecialchars(ENT_QUOTES)`, which is attribute-safe). **Drop** the `process.pdt:5-7` raw-status fallback. Do **not** flatten/re-key 2.4's `process_notice`/`retry_safe` arms.
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` — `buildProcess()` (`:613-687`): `view->set()` `kuickpay_name`/`institution_id` + `display_mode`; add the `status_only` routing in the `block` branch (`:668-669`, Decision A); add `protected` `customerVoucherStatusDisplay()` **and the required** `resolveDisplayMode(?array $voucher, string $decision)` helper, both with `expose*` accessors. **Do not** modify `voucherRowToView()` (`:1104-1122`), the payable gate, `reloadVoucherDecision()` (`:844-866`), the 2.4 amount-change/multi-invoice gates, or `expired`/`cancelled`/`failed`-credential → `allow`.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — add `identity_label`, `copy_button`, `copy_feedback`, `status_expectation`, and `status.<state>` keys for `retry`/`confirmed_unposted`/`posted`/`failed`/`expired`/`manual_review`/`cancelled`/`unknown` (`status.pending` exists at `:17`). Preserve all 2.4/2.1/2.3 keys.

**Tests:** `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php` — required cases for `customerVoucherStatusDisplay()` (posted→success; every other state non-success; unmapped→neutral default) **and** the required `resolveDisplayMode(?array $voucher, string $decision)` helper (`null` voucher → `null`; resolved row + `block` → `status_only`; resolved row + `display`/`allow`/`issue` → `payable`). Identity (`kuickpay_name`/`institution_id`) is tested only if folded into the same view-model helper; otherwise it is an inspection/manual-render check (no standalone seam).

**Do NOT touch:** any `KuickPayPostingService`/transaction path; reconciliation/posting services; the parser/redactor/SOAP client/evidence libs; `plugins/kuickpay_reconcile/` schema/models/services; Blesta core; ionCube-protected files; minified assets; `config/blesta.php`; `deferred-work.md`/`docs` (unless re-deferring). Configurable instruction **groups** + "Check Payment Status" action are Story 2.6 — not here.

### Project Structure Notes
- Layout matches architecture.md Frontend-Architecture (416-439) and the Ownership rule (520-524, 669-670): the **gateway** owns customer-facing KuickPay reference display only; durable state/posting/reconciliation stay in `plugins/kuickpay_reconcile/`. This story is entirely within the gateway view layer + its view-model wiring — no plugin changes, no new directories.
- The panel inherits Blesta client theme (Bootstrap 4.6.2) — no standalone KuickPay visual system (UX-DR25; DESIGN.md:153-155).

### Testing standards
- `php -l` on every changed PHP file; component-local PHPUnit 8.5 via the external runner (`cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`) for the extracted `protected` helpers. Do **not** use `-c build/phpunit.xml` for this component (bootstrap path bug — see project-context.md). **No root PHPUnit claim** (no sibling `../tests`). Run the full gateway suite and confirm no regression against the current baseline of **180 tests** green (2.4 reported 178 at dev-final; its code-review patch added 2 → 180 at HEAD). If a fresh run differs, compare against the actual pre-change count rather than the literal 180.
- The `.pdt` (markup, responsive behavior, clipboard JS, badge styling) is **not** covered by the component harness — verify by code inspection and manual render in a Blesta client pay flow if a runtime is available; otherwise state the gap. Do not present `php -l` + helper unit tests as full UI verification.
- No live KuickPay calls. Redact any PII in fixtures/logs. DB-backed behavior is not runtime-verifiable in this checkout — state it.
[project-context.md Testing Rules]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.5 (530-551)] — story, ACs.
- [Source: _bmad-output/planning-artifacts/epics.md — FR12:47, FR14:51; UX-DR3:156, UX-DR4:158, UX-DR6:162, UX-DR19:188, UX-DR20:190, UX-DR21:192, UX-DR22:194, UX-DR24:198, UX-DR25:200, UX-DR26:202, UX-DR28:206].
- [Source: _bmad-output/planning-artifacts/architecture.md:416-439 (Frontend Architecture), :520-524 + :669-670 (Ownership / posting boundary), :595-607 (UI Display-State Matrix — customer column), :648-662 (Anti-Patterns; :655 posted-only success)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/DESIGN.md:43-48 (monospace typography), :76-95 (status-badge color intent), :147 (single-column layout/reference-first), :153-155 + :172-183 (no custom elevation / do's & don'ts)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/EXPERIENCE.md:55-63 (component patterns), :97-99 (copy-action primitive), :108-121 (accessibility floor), :124-131 (responsive), :132-142 (payment-safety UX)].
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php — buildProcess():613-687; currency/companion gates:623-629; multi-invoice block→notice:640-645; reloadVoucherDecision():844-866; displayVoucherForContext():879-925; createVoucherForContext():940-962; block branch ($voucher=null):668-669; voucher!=null→view->set / else recordReferenceGenerationFailure:679-683; issueVoucherIfNeeded():1005-1096; voucherRowToView() (invoices=>[]):1104-1122; formatVoucherDate():1187-1192; recordReferenceGenerationFailure():1201-1222; institution_id in meta:548,747; Html-only helper load:618].
- [Source: components/gateways/nonmerchant/kuickpay/views/default/process.pdt:1-25 (current 3-arm tree; raw-status fallback:5-7; payable markup:9-19; process_notice arm:21-22; retry_safe:23-24)].
- [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php — name:2, retry_safe:8, amount_changed:9, multi_invoice_unsupported:10, consumer_number_label/amount_label/status_label/due_date_label/expiry_date_label:12-16, status.pending:17, institution_id setting:37].
- [Source: app/views/client/bootstrap/client_pay_confirm.pdt:96-112 (gateway_buttons render outside the form); app/views/client/bootstrap/css/application.css:11 (Bootstrap v4.6.2), :5719 (.badge-success), :8716 (.sr-only), :11022 (.text-monospace)].
- [Source: components/gateways/nonmerchant/{paypal_checkout,kassacompleetideal,blockonomics}/views/default/process.pdt — inline `<script>` precedent (paypal_checkout:4-17, kassacompleetideal:12-24; blockonomics is external-src only, do not model on it)].
- [Source: components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php — subclass-`expose*` + fake-seam harness; `.pdt` not drivable].
- [Source: _bmad-output/implementation-artifacts/2-4-gate-changed-amounts-and-multi-invoice-attempts.md:263-312 — completion notes, File List, review findings; key-concatenation safety lesson:312; testable-seam pattern:298,302; class-casing/language-ownership carry-forward].
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, loader/Input/Record/language rules, `.pdt` view rules, testing/tooling.

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- 2026-06-10: Added failing component tests for `resolveDisplayMode()`, `customerVoucherStatusDisplay()`, and required customer panel language keys; confirmed red with 24 missing-helper errors and 1 missing-language-key failure.
- 2026-06-10: Implemented status display helper, display-mode helper, `buildProcess()` view-model wiring, block-state `status_only` routing, and customer language keys; component suite passed.
- 2026-06-10: Rebuilt `process.pdt` into the 4-arm customer panel with guarded Copy Consumer Number behavior, status-only rendering, localized copy, and Bootstrap-only layout; component suite and syntax checks passed.
- 2026-06-10: Final validation passed: `php -l` on `kuickpay.php`, `language/en_us/kuickpay.php`, and `views/default/process.pdt`; full KuickPay gateway suite passed with 205 tests and 877 assertions.

### Completion Notes List

- Implemented the customer reference panel in the required `process_notice -> payable -> status_only -> retry_safe` order, with identity, Consumer Number, amount, due/expiry dates, status badge text, and expectation copy rendered before future instruction content.
- Added guarded Copy Consumer Number behavior using a real `type="button"`, DOM-sourced Consumer Number value, Clipboard API plus `execCommand('copy')` fallback, and success feedback only after a successful copy.
- Added `customerVoucherStatusDisplay()` and `resolveDisplayMode()` protected seams with test exposure, including posted-only success styling and explicit non-success coverage for every non-posted state.
- Routed existing block-state vouchers to `status_only` without weakening expired/cancelled/credential-failure regeneration behavior, multi-invoice gates, amount-change gates, or any posting boundary.
- Added required localized customer keys while preserving existing Story 2.4 and earlier gateway process keys.
- Verified `.pdt` markup, responsive structure, copy behavior, identity assembly, and status-only panel by code inspection because the component harness does not drive Blesta template rendering or browser clipboard behavior. No live Blesta/MySQL/manual browser render was available in this checkout.

### File List

- components/gateways/nonmerchant/kuickpay/kuickpay.php
- components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
- components/gateways/nonmerchant/kuickpay/views/default/process.pdt
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php
- _bmad-output/implementation-artifacts/2-5-display-customer-payment-reference-panel.md
- _bmad-output/implementation-artifacts/sprint-status.yaml

### Change Log

- 2026-06-10: Marked story in progress with baseline commit.
- 2026-06-10: Added customer status/display-mode view-model helpers, tests, and language keys.
- 2026-06-10: Rebuilt the customer payment reference panel and copy interaction.
- 2026-06-10: Completed validation and moved story to review.
- 2026-06-10: Code review (bmad-code-review, YOLO) — 1 patch applied, 1 deferred, 22 dismissed; status → done.

## Review Findings

_Adversarial code review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), 2026-06-10. Diff baseline `a7c23853..HEAD`. Full gateway suite green (205 tests, 877 assertions); `php -l` clean. Outcome: 0 decision-needed, 1 patch (fixed), 1 deferred, 22 dismissed as noise/by-design._

- [x] [Review][Patch] Customer voucher dates echoed as raw `YYYY-MM-DD HH:MM:SS` instead of human-readable [components/gateways/nonmerchant/kuickpay/views/default/process.pdt:63-71] — FIXED in `31d7e27e`. Payable panel now formats `date_due`/`date_expires` to `d-M-y` (mirrors `formatVoucherDate()`) via guarded inline `strtotime`/`date` in the `.pdt` header, per Dev Notes:95 ("never echo a raw `YYYY-MM-DD HH:MM:SS`"). Unparseable/empty values omit the row. AC1.

- [x] [Review][Defer] Concurrent reconcile can render a just-issued non-pending voucher as `display_mode='payable'`, which then falls through all panel arms to the generic `retry_safe` copy [components/gateways/nonmerchant/kuickpay/kuickpay.php:1093] — deferred, pre-existing. `issueVoucherIfNeeded()` re-reads `getLatestByInvoiceId()` without re-asserting `status==='pending'`; if a reconcile flips the row between the issuance write and this re-read, `resolveDisplayMode(..., 'issue')` returns `payable` but the `.pdt` `payable` arm requires `status==='pending'` and `status_only` requires `display_mode==='status_only'`, so the panel shows `retry_safe`. The re-read predates this story (issuance path), the `issue→payable` mapping is the intended Decision-A contract (asserted by tests), and the consequence is benign (generic safe copy; self-corrects on next render once the row routes to `block`→`status_only`). No payment-state or success-styling leak. Revisit if observed in practice or alongside Story 3.5 posting.
