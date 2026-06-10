# Story 2.5: Display Customer Payment Reference Panel

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a customer,
I want the Consumer Number, amount, due date, expiry date, and KuickPay identity shown clearly,
So that I can complete payment through a supported external channel.

## Acceptance Criteria

**AC1 — Reference panel content + responsive layout (FR12, UX-DR3, UX-DR22, UX-DR25)**
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
**Given** the Voucher is in any tracked state (pending, retrying, awaiting confirmation, posted, failed, expired, under manual review, or cancelled)
**When** the customer views the page
**Then** customer status copy is conservative and localized for that state (expired/cancelled/posted labels are defensive — see Dev Notes → Status reachability)
**And** success styling appears only when the Voucher is posted.

## Tasks / Subtasks

- [ ] **Task 1 — Rebuild `process.pdt` into the Customer Reference Panel (AC1)**
  - [ ] Replace the current minimal panel (`process.pdt:1-23`) with a single-column-first panel that renders, in this reading order: KuickPay identity → Consumer Number (with Copy action) → payable amount → due date → expiry date → status line → status-expectation line. All of this renders **before** any instruction content (instruction groups themselves are Story 2.6).
  - [ ] Use only Bootstrap 4.6.2 / Blesta-inherited classes (`row`, `col-12`, `col-md-*`, `text-monospace`, `badge badge-*`, `font-weight-bold`, spacing utilities). **No new CSS file, no marketing shadows/gradients/floating panels** (UX-DR25, DESIGN.md "Elevation & Depth").
  - [ ] Render the Consumer Number in `class="text-monospace"`; keep labels and amount in normal inherited typography (UX-DR26, DESIGN.md:139-143). Do **not** render the whole panel in monospace.
  - [ ] Do **not** use a `<table>` on this customer surface (EXPERIENCE.md:126). Use stacked `div`/`dl` rows so phone width is single-column with no horizontal scroll.
  - [ ] Keep the payable-reference branch gated exactly as today on `status === 'pending'` **and** non-empty `kuickpay_reference` for the *payable* treatment; status-only display for other states is Task 4 / Decision A (resolved).
  - [ ] Build the panel inside the **Unified view-flag tree** (Dev Notes → Unified view-flag contract): Story 2.4's `process_notice` safe-copy arm and the `display_mode` (`payable`/`status_only`) arms must coexist in one condition tree. Whichever of 2.4/2.5 lands first creates **all** arms (the not-yet-needed ones inert) so the other lands without restructuring `process.pdt`.
- [ ] **Task 2 — Copy Consumer Number control + temporary feedback (AC2)**
  - [ ] Add a real `<button type="button">` adjacent to the Consumer Number, keyboard-reachable, with a visible text label or `aria-label` ("Copy Consumer Number") (UX-DR4, UX-DR24, EXPERIENCE.md:100,113).
  - [ ] On click, copy **only** the Consumer Number string via an inline `<script>` (`navigator.clipboard.writeText(...)` with a `document.execCommand('copy')` + hidden-`<textarea>` fallback for insecure-origin/older browsers). Inline-body `<script>` in a gateway `process.pdt` is an established pattern — `paypal_checkout` (inline block) and `kassacompleetideal` (inline block, plain JS) both ship one; **not** `blockonomics` (it only loads an external `src` script — don't model on it). None of these do clipboard copy, so write a small **vanilla-JS** snippet (no jQuery); read the value from the Consumer Number node's `data-value`/`textContent`, never a server-rendered JS string literal. Minimal sketch in Dev Notes → Copy action design.
  - [ ] Show temporary feedback (e.g. swap the button label to "Copied" / reveal an adjacent `aria-live="polite"` message) that reverts after a short delay (EXPERIENCE.md:119).
  - [ ] `type="button"` + `preventDefault` so the action **never** submits a form or mutates payment state (UX-DR4, EXPERIENCE.md:105). The panel already renders **outside** the Blesta form (`client_pay_confirm.pdt:96` `Form->end()` precedes the `gateway_buttons` loop at `:98-112`), so this is defensive but required.
  - [ ] All copy/feedback strings come from the gateway language file (UX-DR28). No hard-coded UI text.
- [ ] **Task 3 — Conservative status→label + posted-only styling in a testable helper (AC3)**
  - [ ] Implement the status→display map as a **mandatory `protected` helper** in `kuickpay.php` — `customerVoucherStatusDisplay(string $status): array` returning `['label_key' => 'Kuickpay.process.status.<state>', 'badge' => 'badge-<variant>']` — with an `exposeCustomerVoucherStatusDisplay()` accessor (mirror `exposeReloadVoucherDecision()`). The `.pdt` renders that contract; it does **not** own the map. One conservative localized label per state (full UI Display-State Matrix customer column).
  - [ ] **Safe default — never echo the raw status.** For any unmapped/empty status the helper returns a neutral non-success entry (`Kuickpay.process.status.unknown` + `badge-secondary`). Do **not** carry forward the current `process.pdt:5-7` fallback that prints the raw status key (`$voucher['status']`) when a label is missing — that leaks `manual_review`/`confirmed_unposted` to the customer (UX-DR28).
  - [ ] Apply success styling (`badge-success` / "Payment received" wording) **only** when `status === 'posted'` — this is architecture-mandated (UX-DR20, DESIGN.md:137; Anti-Patterns architecture.md:655). Every other state uses a non-success badge per the Dev Notes matrix; those specific colors are **story-authored design guidance**, not architecture-mandated (see Dev Notes note).
  - [ ] Every badge must be paired with adjacent readable status text — color is never the only signal (UX-DR19, DESIGN.md:166,181).
  - [ ] Include a conservative status-expectation line via the **named key `Kuickpay.process.status_expectation`** ("Blesta marks this invoice paid only after KuickPay confirms your payment") (UX-DR3, UX-DR6) — never a hard-coded literal. Show it in `payable` and non-terminal `status_only` modes; **omit it on `posted`** (already received — it would contradict the success copy). The **configurable** instruction groups + any "Check Payment Status" action are Story 2.6 — do not build them in 2.5.
- [ ] **Task 4 — Surface KuickPay identity + route non-pending statuses to the view (AC1, AC3)**
  - [ ] In `buildProcess()` pass the KuickPay identity to the view via `$this->view->set(...)`: `kuickpay_name` (`Language::_('Kuickpay.name', true)`) and optionally `institution_id` (`$meta['institution_id']`, already read at `kuickpay.php:502,681`). **Do not modify `voucherRowToView()`'s return shape** to carry identity (or anything else) — Story 2.4's comparator depends on it keeping `invoices => []` (2.4 Task 3/4); identity is a sibling view var, not a voucher-row field.
  - [ ] Implement **Decision A (resolved — Dev Notes)**: route existing non-pending **displayable** vouchers to the view in `display_mode = 'status_only'` so AC3's `retry/confirmed_unposted/failed/manual_review/posted` branches are reachable in the live flow, not only via a view render test. Exact insertion point: the `block` branch at `kuickpay.php:603-604` (and the `issueVoucherIfNeeded()` null paths) — see Dev Notes → Decision A for the precise guard and the `recordReferenceGenerationFailure()` relationship. Keep the **payable** treatment (Consumer Number value + Copy + expectation) for `pending`(+reference) only; set `display_mode = 'payable'` there.
- [ ] **Task 5 — Language keys (AC1/AC2/AC3)**
  - [ ] Add these new customer keys to `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` with **these exact names** (so 2.4/2.5 neither collide nor fork naming):
    - `Kuickpay.process.identity_label` — KuickPay identity heading.
    - `Kuickpay.process.copy_button` — Copy-action label / `aria-label`.
    - `Kuickpay.process.copy_feedback` — "Copied" temporary feedback.
    - `Kuickpay.process.status_expectation` — the conservative expectation line.
    - `Kuickpay.process.status.<state>` — one per canonical state (`pending` exists at `:15`; add `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`) **plus** a neutral `Kuickpay.process.status.unknown` for the safe default.
  - [ ] **Preserve Story 2.4's keys if 2.4 landed first** — do not remove or rename `Kuickpay.process.amount_changed` / `Kuickpay.process.multi_invoice_unsupported` (2.4 Task 8) when editing this file.
  - [ ] Conservative wording only — no "paid"/"received"/success language for any state except `posted`; no raw provider status, SOAP names, parser fields, credentials, or exception classes (UX-DR28, architecture.md:608).
- [ ] **Task 6 — Verification**
  - [ ] `php -l` on every changed PHP file (`kuickpay.php`, language file) and the `.pdt`.
  - [ ] **Required** unit tests in `tests/KuickPayVoucherGatewayHelpersTest.php` (subclass-`expose*` pattern, already used for `exposeReloadVoucherDecision`/`exposeIssueVoucherIfNeeded`): cover `customerVoucherStatusDisplay()` — `posted → badge-success`; and assert non-success for **each** of the seven non-posted states explicitly (`pending`, `retry`, `confirmed_unposted`, `failed`, `expired`, `manual_review`, `cancelled`), plus unmapped/empty → neutral non-success default (do **not** rely on a single representative state) — and the `display_mode` decision helper (Decision A) if extracted. These guard AC3's posted-only-success invariant, which the `.pdt` cannot assert in the harness.
  - [ ] The `.pdt` itself is **not** drivable in the component harness — verify panel/copy/responsive/styling by inspection + manual render, and say so explicitly (see Testing standards).

## Dev Notes

### Critical gates & invariants (read first)
- **`buildProcess()` cannot mark paid — and neither can the customer.** This story is display-only. No path here may create/apply a Blesta transaction, call `markPaid`/`recordPayment`, update invoice status, or set `posted`/`confirmed_unposted`. Only `KuickPayPostingService` (Story 3.5, `backlog`) pays an invoice. [architecture.md:650-661; FR17/NFR9]
- **Success styling is posted-only.** "Payment received", green checks, paid-receipt language, or `badge-success` must appear **only** at `status === 'posted'`. "Voucher generated" / a visible Consumer Number is **not** paid. [UX-DR20; DESIGN.md:122,137; architecture.md:655; EXPERIENCE.md:132-142]
- **Copy / refresh / return never change payment state.** The Copy action and any page reload are inert with respect to paid state (`type="button"`, no form submit, no write). [UX-DR4; EXPERIENCE.md:105-106; FR14]
- **No raw diagnostics in customer view.** Never render raw SOAP/XML, provider status strings, parser field names, credentials, stack traces, exception classes, Registration Number, or admin-review internals on the customer panel. The customer reference is the **Consumer Number** only. [UX-DR28; architecture.md:608; NFR8]
- **Every customer string is language-file driven.** No hard-coded labels, status copy, or feedback text in the `.pdt`. Gateway customer copy lives in `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`. [NFR6; UX-DR28; 2.4 carry-forward]
- **Loading states must not blank a known value.** If you add any "checking" affordance, preserve the existing Consumer Number / last-known status rather than clearing it. [UX-DR21; EXPERIENCE.md:106]

### The view-model contract this story consumes (verified)
`buildProcess()` (`kuickpay.php:567`) hands the view a flat `$voucher` array via `voucherRowToView()` (`:907-924`). Fields available **today**:
`id, company_id, client_id, gateway_id, currency, amount, status, registration_number, consumer_number, kuickpay_reference, raw_status, date_due, date_expires, invoices`.
- `amount` is a normalized **decimal string** (e.g. `"1234.00"`), not a float and not currency-formatted. Display it with the `currency` code (PKR) — e.g. `PKR 1,234.00` — but never run float math or mutate the stored value (NFR13 governs comparison; display formatting is fine). Minimal-safe display: `currency` + the stored decimal string. Optional: load `CurrencyFormat` helper for grouping; it is **not** loaded today (`buildProcess` loads only `Html` at `:572`).
- `date_due` / `date_expires` may be empty — keep them conditional (current view already guards them at `process.pdt:13-18`).
- **Missing for AC1:** KuickPay identity. Add `kuickpay_name` (+ optional `institution_id`) to the view via `$this->view->set(...)` in `buildProcess()`. `Kuickpay.name` = "KuickPay" (`language:2`); `institution_id` is a merchant identifier (already embedded in the Consumer Number) — not a secret, safe to show, but optional/secondary.
- **Show Consumer Number, not Registration Number, to the customer.** AC1 and UX-DR3 specify the Consumer Number (the payable reference). Registration Number stays admin-side.
- **Do not change `voucherRowToView()`'s return shape.** Add identity (`kuickpay_name`/`institution_id`) as **separate view vars** via `$this->view->set(...)`, not as voucher-row fields. Story 2.4's `requestMatchesVoucher()` comparator depends on the row keeping `invoices => []` (2.4 Task 3 conditional-(b) rule); widening the row would silently break 2.4's single-invoice reload guard. The `status_only` panel shows identity + status + expectation only — **no per-invoice breakdown** (out of scope; it would require loading links the comparator must not see).
- **Empty `amount` guard.** `amount` can be `''`. If empty, omit the amount line — never render a bare "PKR " with no number.
- **Format the dates for display.** `date_due`/`date_expires` arrive as raw DB datetime strings (the current panel echoes them verbatim). Render them human-readably — the gateway already has a `formatVoucherDate()` helper (`exposeFormatVoucherDate` in the harness), or use a `Date`/`strtotime` format. Never echo a raw `YYYY-MM-DD HH:MM:SS` to the customer; keep both conditional (may be empty) and never mutate the stored value.

### Current state of `process.pdt` (what Story 2.3 built — you are replacing this)
```
process.pdt:1-23  →  renders the panel ONLY when status==='pending' AND kuickpay_reference!=''
                     (Consumer Number, Amount, Status, due/expiry); else echoes Kuickpay.process.retry_safe.
```
It has no Copy action, no KuickPay identity, no responsive structure, no multi-state status map, and no posted-only styling guard. Story 2.5 rebuilds it to satisfy AC1–AC3 while preserving the payable gate (`pending` + reference) for the *payable* treatment.

### UI Display-State Matrix — the customer column you must implement (AC3)
[Source: architecture.md:595-608 for the **Customer label** column. The **Styling** (badge) and **CTA/reachability** columns are **story-authored design guidance** — the architecture matrix has no badge column. Only two rules are architecture-mandated: `posted` is the sole success state, and no success styling may appear before `posted`. The other badge classes are defaults an architect may override.]

| Voucher State | Customer label (conservative) | Styling | Payable CTA (Copy/instructions)? |
|---|---|---|---|
| `pending` | Payment reference created / awaiting payment | info | **yes** (only state with payable CTA) |
| `retry` | Confirmation delayed | info | no |
| `confirmed_unposted` | Waiting for payment confirmation | info | no |
| `posted` | Payment received | **success (only here)** | no (receipt/status) |
| `failed` | Confirmation delayed | info (CONFIRMED — customer-surface override of DESIGN.md; see below) | no |
| `expired` | Payment reference expired | secondary | no (flow may allow regenerate) |
| `manual_review` | Payment under review | warning | no |
| `cancelled` | Payment reference cancelled | secondary | no (flow may allow regenerate) |

Map these to `Kuickpay.process.status.<state>` keys consumed through the `customerVoucherStatusDisplay()` helper (Task 3). Never expose the raw status string to the customer (`raw_status` is admin-only); unmapped/empty → neutral non-success default.

**`failed` badge = `info` (CONFIRMED — customer-surface override of `DESIGN.md:84-87`).** `DESIGN.md:84-87` defines `status-badge-failed` as `{colors.danger}` and `DESIGN.md:135` maps danger to "failed action." This story **deliberately departs** from that *on the customer reference panel only*, for two reasons: (1) the architecture matrix gives `failed` and `retry` the **same** conservative customer label ("Confirmation delayed") — with identical text, a different color makes color the *only* signal, violating UX-DR19; (2) red/`danger` fights the conservative, no-alarm tone this surface enforces (the customer cannot act on `failed` here; recovery is staff/reconciliation-side). **The override is scoped strictly to the customer panel — admin surfaces keep `DESIGN.md`'s danger styling and the internal `failed` vs `retry` distinction.** Decision confirmed; implement `failed → badge-info`.

**Status reachability (sets tester/reviewer expectations).** With Decision A's `status_only` routing the live-reachable states are `pending` (payable) and the non-terminal `status_only` states (`retry`/`confirmed_unposted`/`failed`/`manual_review`). `expired`/`cancelled` resolve to `allow` → a **fresh pending** voucher (the customer gets a new payable panel, not the expired/cancelled label), and `posted` means Blesta renders its own paid-invoice UI — so those three labels are **defensive** (correct to include for a robust safe default), not states the customer normally reaches through this panel. Note: the `status_only` copy for `retry`/`failed` ("Confirmation delayed" + expectation) is intentionally **action-light** — next-step guidance (wait / retry / support path) is Story 2.6; until then these panels inform without instructing, an accepted scope boundary, not a defect.

### Copy action design (AC2)
- Plain inline `<script>` (no jQuery dependency needed; jQuery + Bootstrap 4 are present in the client area, but vanilla `navigator.clipboard.writeText` is cleaner). Verified inline-body precedent: `paypal_checkout/views/default/process.pdt` (inline block, lines 4-17) and `kassacompleetideal/views/default/process.pdt` (inline block, plain JS, lines 12-24). **Not** `blockonomics` — it only loads an external `src` script. None of them do clipboard copy, so the snippet is new; minimal sketch:
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
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, done); }
      else { var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (err) {} document.body.removeChild(t); done(); }
    });
  })();
  ```
  (Illustrative — feedback text comes from the language key via `data-copied`, not a JS literal; the dev refines.)
- Read the Consumer Number from a stable hook (the `text-monospace` element's `data-value`/`textContent`) rather than re-deriving it — copy **only** that value (UX-DR4). Do **not** inject the Consumer Number or the feedback text into a server-rendered JS **string literal** (`'<?php echo … ?>'`) — that path needs JS-escaping and risks breakage/injection; read both from the DOM (`textContent`/`dataset`).
- Keyboard reachable (`<button>`, focusable) and labeled for screen readers; copy-success announced via an adjacent `aria-live="polite"` region or a focusable status text (EXPERIENCE.md:113,119; UX-DR24).
- Feedback is temporary and visual-only; it must not toggle any paid/processing state.
- Guard the clipboard call (older browsers / insecure origin) — on failure, do nothing destructive (optionally select the text). Never throw a JS dialog (no `alert`/`confirm`). **Honesty:** show the "Copied" feedback only on an **actual** successful copy — the sketch's `.then(done, done)` fires feedback on rejection too, which falsely claims a copy; a real impl must not announce "Copied" when the write failed. (Also: clearing the `aria-live` text on a timer can make some screen readers announce "blank" — prefer leaving it until the next copy, or use `role="status"`.)

### Responsive & accessibility floor
- Single-column on phone; Consumer Number + amount stay above instructions; no horizontal scroll (UX-DR22, EXPERIENCE.md:126). Desktop may widen but Consumer Number stays first (EXPERIENCE.md:127).
- Bootstrap 4.6.2 utilities confirmed present in `app/views/client/bootstrap/css/application.css`: `.text-monospace`, `.badge-*`, `.sr-only`. (Note: this is BS4 — `.font-monospace` is BS5 and does **not** exist here; use `.text-monospace`.)
- Keyboard focus order = reference → copy → expectation → (instructions, Story 2.6) (EXPERIENCE.md:117). WCAG 2.2 AA target (UX-DR24).

### KuickPay identity (AC1)
"KuickPay identity" = the localized gateway name (`Kuickpay.name` → "KuickPay"), optionally plus the configured `institution_id`. Render it as the panel heading/identity above the Consumer Number. It is brand/identifier text, not a secret.

### DECISION A (RESOLVED) — `buildProcess()` routes non-pending statuses to the view as `status_only`
AC3 enumerates `pending/retry/confirmed_unposted/failed/expired/manual_review/posted` (+`cancelled` defensively). Today the gateway routes **only** `pending`(+reference) and freshly-issued pending vouchers to the view; everything else collapses to generic copy:
- `reloadVoucherDecision()` (`:776-798`) returns `block` for `retry`/`confirmed_unposted`/`posted`/`failed`(non-credential)/`manual_review` → `buildProcess` sets `$voucher = null` (`:603-604`) → `recordReferenceGenerationFailure()` → generic `retry_safe`.
- `issueVoucherIfNeeded()` returns `null` after recording `failed`/`manual_review` evidence (`:843-844, :858, :897`) → same generic copy.
- `expired`/`cancelled` return `allow` (`:793-795`) → a **new** pending voucher is created, so the customer correctly gets a fresh payable panel (do **not** change this).

**Resolution (binding for this story; architect may override at sign-off — see consequence at the end):**
1. **Always** implement the full status map via `customerVoucherStatusDisplay()` (Task 3) so the panel renders conservative copy + posted-only styling for *any* status it receives.
2. **Route existing non-pending displayable vouchers to the view as `display_mode = 'status_only'`.** Precise insertion, verified against `kuickpay.php:601-617`:
   - In the **`block` branch** (`:603-604`), `$latest` is **always non-null** — `reloadVoucherDecision(null)` returns `allow`, not `block` (`:778-779`), so the block branch is only ever reached for a real existing non-pending voucher. Set `$voucher = $this->voucherRowToView($latest)` **and** `$display_mode = 'status_only'` instead of `$voucher = null`. The existing `if ($voucher !== null)` gate (`:613`) then runs `view->set('voucher', …)`, so the row is shown and **does not** reach `recordReferenceGenerationFailure()` — correct, because displaying real existing state is not a generation failure. (The `recordReferenceGenerationFailure()` null+log path now fires only via the create/reuse/issue **`else`** branch returning `null` — a genuine generation failure — never from the block branch.)
   - Where `issueVoucherIfNeeded()` returns `null` after recording real `failed`/`manual_review`/`retry` evidence, re-read the latest row and display it as `status_only`. For the parsed-evidence (`:843-844`) and post-persist (`:858`) returns the row **is** recorded before the null, so the re-read finds it. The exception path (`:897`) only **attempts** to persist synthetic ambiguous evidence inside an inner `try`; if that persistence itself throws, no fresh evidence row is guaranteed — the re-read then falls back to the last persisted state, which the status map / safe default still renders conservatively. Do not assume a fresh row on every null.
   - Keep the **payable** treatment (Consumer Number value + Copy + status-expectation) for `pending`(+reference) only; set `display_mode = 'payable'` there (when 2.4 has landed, the amount-change comparator **gates** this assignment — see Unified view-flag contract → intra-branch write order).
   - **Do not** change `expired`/`cancelled` → `allow` (they regenerate), and **do not** weaken any safety write or the payable gate.
3. Extract the `display_mode` decision into a `protected` helper with an `expose*` accessor so it is unit-testable (full `buildProcess()` is not harness-drivable — see Testing).

**If the architect overrides** to the conservative view-only alternative (leave `buildProcess` routing as-is; non-pending states keep generic `retry_safe` copy in the live flow): then AC3's `retry/confirmed_unposted/failed/manual_review/posted` branches are **verified at the view layer only and are not reachable in the live flow** — AC3 is satisfied as a view-contract, not end-to-end. Record that explicitly in the Dev Agent Record and treat AC3 accordingly. The binding default above is preferred precisely because it makes AC3 end-to-end-true.

This decision broadens what `buildProcess()` shows and edits the **same `buildProcess()` + `process.pdt`** that Story 2.4 also edits — see Unified view-flag contract + Coordination below.

### Unified view-flag contract (converge 2.4 `process_notice` + 2.5 `display_mode`)
2.4 and 2.5 both restructure `process.pdt`. To prevent a divergent template, both stories target **one** seam with **two orthogonal** flags:
- `process_notice` (Story 2.4) ∈ `{ 'amount_changed', 'multi_invoice_unsupported', null }` — a non-payable safe-copy notice (2.4 Task 8).
- `display_mode` (Story 2.5) ∈ `{ 'payable', 'status_only' }` — how the reference panel renders.

**Precedence in `process.pdt` (single condition tree):**
```
if (!empty($process_notice))             → print Kuickpay.process.{$process_notice}   (2.4 notice wins)
elseif ($display_mode === 'payable')     → payable panel (identity + Consumer Number + Copy + amount + dates + status + expectation)
elseif ($display_mode === 'status_only') → status-only panel (identity + status badge + expectation)
else                                     → Kuickpay.process.retry_safe                (generic fallback)
```
`process_notice` takes precedence because a notice and the payable/status render are **mutually exclusive** at the source: 2.4's multi-invoice gate sets `process_notice = 'multi_invoice_unsupported'` and **returns before** the latest-voucher lookup, while its `amount_changed` gate fires **inside** the display branch but **nulls the voucher** whenever it sets the notice (2.4:74,108) — so no render path ever carries both a notice and a payable voucher. Notice-first ordering is therefore safe (and more robust than 2.4's payable-first order, which is safe only because it nulls). **Intra-branch write order (load-bearing at the merge point):** the comparator result must **gate** the `display_mode = 'payable'` assignment — run 2.4's `requestMatchesVoucher()` first; assign `display_mode = 'payable'` **only on a match**; on a block-mismatch set `process_notice = 'amount_changed'` and leave `display_mode` unset. So `buildProcess()` sets at most one of `process_notice` / a non-default `display_mode` per render; unset `display_mode` and `null` `process_notice` fall through to `retry_safe`.

### ⚠️ Coordination with Story 2.4 (shared files — highest-risk item)
Story **2-4** (`ready-for-dev`, **not yet implemented** per `sprint-status.yaml`) edits the **same three files** this story edits:
- `kuickpay.php::buildProcess()` (2.4 adds the multi-invoice block + amount-change display gate + a `protected` display-branch decision helper),
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` (2.4 adds `amount_changed` / `multi_invoice_unsupported` safe-copy branches via its **`process_notice`** view flag — 2.4 Task 8),
- the gateway language file (2.4 adds policy + two customer-copy keys).

Build order is 2.4 → 2.5. **Template ownership (load-bearing — this guarantee must hold whichever dev reads whichever story, so it is mirrored into 2.4 Task 8 / Files-to-touch):** **2.5 owns the final 4-arm `process.pdt` template** (`process_notice → payable → status_only → retry_safe`); **2.4 contributes only the `process_notice` value-set + its two language keys and must NOT re-flatten the template or re-key the payable branch.**
- **2.4 → 2.5 (preferred):** 2.4 builds its notice arm; 2.5 then **rebuilds the panel** (its Task 1 replaces `process.pdt:1-23` wholesale) into the 4-arm tree, absorbing 2.4's `process_notice` arm. (2.5 *does* restructure here — that is its core task; "no restructuring" applies only to the order below.)
- **2.5 → 2.4 (the fragile order):** 2.5 builds the full 4-arm tree including the inert `process_notice` arm; 2.4 then only fills its notice copy + keys and must **not** collapse back to a 3-arm shape — doing so drops 2.5's `status_only` arm and silently breaks AC3's live `retry/confirmed_unposted/failed/manual_review` rendering.

Either way: converge on the single condition tree in the Unified view-flag contract; do not duplicate or clobber the other story's arms. **Decision-helper reconciliation:** both stories extract a `protected` display-branch decision helper from the **same** region (2.4: match/block/replace → `$voucher`+`process_notice` on the *display* sub-branch; 2.5: `display_mode` payable/status_only on the *block* sub-branch). Different sub-branches lower clobber risk, but a dev landing both must reconcile them into **one** coherent decision surface yielding `(voucher, display_mode, process_notice)` together — not two overlapping protected methods. Confirm sequencing + ownership with the architect before starting Task 1/4.
[Source: 2-4-gate-changed-amounts-and-multi-invoice-attempts.md:198-201, 175, 183]

### Previous-story intelligence & gotchas (carry forward)
- **Class casing is load-bearing.** Framework-instantiated gateway class is `Kuickpay` (lowercase p); lib services use capital P (`KuickPayVoucherReferenceService`, etc.). Match exactly. [2.4 notes]
- **Gateway customer/settings strings** live in the gateway language file; **model** validation strings live in the owning per-model language file. Don't cross them. [2.4 notes]
- **The `.pdt` view is not unit-testable in the component harness.** Tests subclass `Kuickpay` and call `expose*` helpers with fake seams (`tests/KuickPayVoucherGatewayHelpersTest.php`); `companionInstalled()` short-circuits full `buildProcess()` in a bare env, so no test drives the whole method. Put any testable logic (status→class map, identity assembly, `display_mode` decision) in a `protected` gateway helper with an `expose*` accessor; verify the `.pdt` markup/JS by inspection + manual render. [2.4 notes:193]
- **No live Blesta/MySQL render verification has run** in any prior KuickPay story. State the manual-render gap explicitly; do not claim browser-verified responsive/clipboard behavior you didn't run. [2.4 notes:192]
- **Keep diffs small and local to the gateway view layer.** No new top-level dirs, no new CSS/JS asset files, no Blesta-core edits, no ionCube/minified-asset edits. [project-context.md]
- **Commit convention:** `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars; keep BMad/docs artifacts out of the implementation commit. Allowed types: `feat fix docs test refactor chore`.

### Files to touch
**UPDATE (gateway):**
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` — rebuild into the Customer Reference Panel inside the **Unified view-flag tree** (`process_notice` → `payable` → `status_only` → `retry_safe`): identity, Consumer Number (`text-monospace`, `data-value`) + Copy button + inline copy `<script>`, amount (currency-prefixed, empty-guarded), formatted due/expiry, status badge via `customerVoucherStatusDisplay()`, status-expectation line, responsive single-column markup. **Drop** the current `$voucher['status']` fallback (`:5-7`, which echoes the canonical status key when a label is missing — it is *not* the `raw_status` field, which is admin-only).
- `components/gateways/nonmerchant/kuickpay/kuickpay.php` — `buildProcess()`: `view->set()` `kuickpay_name`/`institution_id` + `display_mode`/`process_notice`; add the `status_only` routing (Decision A, resolved) and `protected` `customerVoucherStatusDisplay()` + `display_mode`-decision helpers with `expose*` accessors. **Do not** modify `voucherRowToView()`'s shape, the payable gate, the create/reuse/issue safety logic, or `expired`/`cancelled` → `allow`.
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — identity label, copy button + "Copied" feedback, status-expectation line, and `Kuickpay.process.status.<state>` keys for `retry`/`confirmed_unposted`/`posted`/`failed`/`expired`/`manual_review`/`cancelled` (`pending` exists at `:15`).

**Tests:** `components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php` — required cases for `customerVoucherStatusDisplay()` (posted→success; every other state non-success; unmapped→neutral default), identity assembly, and the `display_mode` decision (Decision A, resolved).

**Do NOT touch:** any `KuickPayPostingService`/transaction path; reconciliation/posting services; the parser/redactor/SOAP client; plugin schema/models; Blesta core; ionCube-protected files; minified assets; `config/blesta.php`; `deferred-work.md`/`docs` (unless re-deferring). Instruction **groups** + "Check Payment Status" action are Story 2.6 — not here.

### Project Structure Notes
- Layout matches architecture.md#Frontend-Architecture (416-439) and #Ownership-Rule (664-673): the **gateway** owns customer-facing KuickPay reference display only; durable state/posting/reconciliation stay in `plugins/kuickpay_reconcile/`. This story is entirely within the gateway view layer + its view-model wiring — no plugin changes, no new directories.
- The panel inherits Blesta client theme (Bootstrap 4.6.2) — no standalone KuickPay visual system (UX-DR25, DESIGN.md "Brand & Style").

### Testing standards
- `php -l` on every changed PHP file; component-local PHPUnit 8.5 via the external runner (`cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`) for any extracted `protected` helper. **No root PHPUnit claim** (no sibling `../tests`).
- The `.pdt` (markup, responsive behavior, clipboard JS, badge styling) is **not** covered by the component harness — verify by code inspection and manual render in a Blesta client pay flow if a runtime is available; otherwise state the gap. Do not present `php -l` + helper unit tests as full UI verification.
- No live KuickPay calls. Redact any PII in fixtures/logs. DB-backed behavior is not runtime-verifiable in this checkout — state it.
[project-context.md Testing Rules; epics.md FR28/NFR11/NFR12]

### References
- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.5 (530-551)] — story, ACs (FR12; supports FR14 expectation).
- [Source: _bmad-output/planning-artifacts/epics.md — FR12:47; UX-DR3:156, UX-DR4:158, UX-DR19-22, UX-DR24-26, UX-DR28 (188-206)].
- [Source: _bmad-output/planning-artifacts/architecture.md:416-439 (Frontend Architecture), :595-608 (UI Display-State Matrix — customer column), :648-661 (Anti-Patterns), :518-526/:664-673 (Ownership boundaries)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/EXPERIENCE.md:55-70 (component patterns), :97-106 (interaction primitives), :108-120 (accessibility floor), :122-130 (responsive), :132-142 (payment-safety UX)].
- [Source: _bmad-output/planning-artifacts/ux-designs/ux-whmcs_blesta-2026-06-09/DESIGN.md:139-143 (typography/mono), :145-151 (layout), :161-171 (components), :172-183 (do's/don'ts)].
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php — buildProcess():567, currency/companion gates:577/581, payable+display branch:599-617, reloadVoucherDecision():776, issueVoucherIfNeeded() null paths:843/858/897, voucherRowToView():907-924, institution_id in meta:502/681, Html-only helper load:572].
- [Source: components/gateways/nonmerchant/kuickpay/views/default/process.pdt:1-23 (current minimal panel)].
- [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php — process.* labels:7-15, name:2, institution_id:35].
- [Source: app/views/client/bootstrap/client_pay_confirm.pdt:96-112 (gateway_buttons render outside the form); app/views/client/bootstrap/css/application.css:11 (Bootstrap v4.6.2); .text-monospace/.badge-*/.sr-only present].
- [Source: components/gateways/nonmerchant/{blockonomics,paypal_checkout,kassacompleetideal}/views/default/process.pdt — inline `<script>` pattern in a gateway process view].
- [Source: components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherGatewayHelpersTest.php — subclass-expose + fake-seam harness; `.pdt` not drivable].
- [Source: _bmad-output/implementation-artifacts/2-4-gate-changed-amounts-and-multi-invoice-attempts.md:175,183,193,198-201 — shared-file coordination; gateway test-harness limits; class-casing/language-ownership carry-forward].
- [Source: _bmad-output/implementation-artifacts/deferred-work.md:51 (zero-amount payable — 2.4 territory), :73 (reload shows stored amount without re-checking balance — 2.4 territory)].
- [Source: _bmad-output/project-context.md] — Blesta/PHP 8.2 conventions, loader/Input/Record/language rules, `.pdt` view rules, testing/tooling.

## Dev Agent Record

### Agent Model Used

_TBD by dev agent_

### Debug Log References

### Completion Notes List

### File List
