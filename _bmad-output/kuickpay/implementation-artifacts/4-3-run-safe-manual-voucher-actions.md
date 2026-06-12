---
baseline_commit: 9a68e138db16275c33c01166e54fb05599244058
---

<!-- Powered by BMAD-CORE™ -->

# Story 4.3: Run Safe Manual Voucher Actions

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a support or finance admin,
I want approved manual actions on a Voucher (Check Now, Mark for Manual Review, Cancel/Close),
so that delayed or ambiguous payments can be checked or routed without unsafe paid-state shortcuts.

## Acceptance Criteria

> Sourced from `epics.md` Story 4.3 (lines 772–797); FR26 (line 75); NFR3/NFR4/NFR9/NFR13/NFR14 (lines 91–113);
> UX-DR15/16/19/20/23/24/28 (lines 180–206); architecture Authentication & Security (lines 357–377), UI
> Display-State Matrix (lines 595–606), Audit patterns (lines 610–634), Anti-Patterns (lines 648–661).

1. **(AC1 — Check Now reuses the scheduled path)** Given an authorized admin clicks **Check Now** on a voucher in a
   supported state, when the action is submitted by **POST** with Blesta staff auth + plugin **ACL** + **CSRF**
   protection, then the inquiry/posting runs through the **same parser, validation, and posting path as scheduled
   reconciliation** (`KuickPayReconcileService` for the inquiry/reconcile step and `KuickPayPostingService` for the
   posting step — the exact services the `reconcile_pending` and `post_confirmed` crons use), **and** a localized
   result message reflecting the real outcome is shown on return to the detail page.

2. **(AC2 — no duplicated payment logic; single posting authority)** Check Now MUST NOT re-implement SOAP parsing,
   evidence validation, status mapping, amount comparison, or transaction creation. It calls the existing shared
   services only. **Only `KuickPayPostingService` may create or apply a Blesta transaction** (architecture lines
   129, 583, 652). Controllers/views never parse raw SOAP/XML and never branch on raw KuickPay status codes.

3. **(AC3 — Mark for Manual Review requires a note; attribution preserved)** Given an admin marks a Voucher for
   **Manual Review**, when the action is submitted, then an **admin note is required** (empty note → validation
   error, no state change), and the resulting Voucher **state (`manual_review`), the note, a timestamp, and staff
   attribution** are preserved where Blesta patterns support it: the note text is stored on `kuickpay_vouchers.admin_notes`
   and a durable **`admin.reviewed`** audit event (payload carries `staff_id` + `prior_status`; row carries `date_created`)
   is recorded.

4. **(AC4 — Cancel/Close preserves evidence and audit; never deletes paid evidence)** Given an admin cancels/closes a
   Voucher, when the action is submitted, then audit history and confirmed payment evidence are **preserved** — the
   action performs **no row DELETE**; it is a status transition to `cancelled` that leaves `kuickpay_reference`,
   `evidence_hash`, `date_paid`, `blesta_transaction_id`, the invoice-link rows, and all audit events intact — **and
   confirmed paid evidence cannot be discarded through cancel**: Cancel is **forbidden from `confirmed_unposted` and
   `posted`** (both carry validated/real payment) and from `cancelled`. Like Manual Review, **Cancel requires a
   non-empty admin note** (empty note → validation error, no state change) — the UX source lists the admin note as
   *required when manually marking Manual Review or canceling* — and the note is appended to `admin_notes` in the same
   guarded transition, with `staff_id` + `prior_status` in the payload. A durable **`admin.cancelled`** audit event is
   recorded.

5. **(AC5 — no Force Paid in MVP)** Given an admin views available actions, when the Voucher is in **any** state, then
   **no Force Paid / Mark Paid action is present** anywhere in the UI or controller. The only route to `posted` is
   `KuickPayPostingService`. No admin action sets `status='posted'`, writes `blesta_transaction_id`, or creates a
   transaction directly (architecture line 375, 660).

6. **(AC6 — mutations are POST + auth + per-action ACL + CSRF; GET stays read-only)** Every manual action is a
   **POST** route that (a) rejects non-POST (redirect to detail, no mutation), (b) requires Blesta staff
   authentication (`requireLogin()`), (c) enforces a **separate plugin ACL action per capability** — `recheck`
   (Check Now), `review` (Mark Manual Review), `cancel` (Cancel/Close) — so a staff group with only the view (`*`)
   grant cannot run them, and (d) is CSRF-protected via Blesta's `Form` token. No GET route mutates voucher state
   (NFR14, architecture line 377, 659).

7. **(AC7 — company-scoped, race-safe transitions; audit on every decision)** Every action resolves `company_id`
   server-side (never from the request) and fetches/updates the voucher **company-scoped**. State transitions are
   **status-guarded** (UPDATE … WHERE status IN (allowed-from)), so a row that concurrently left the allowed set
   (e.g. the posting cron just moved it to `posted`) is **never clobbered**; a no-op transition returns a safe
   "voucher state changed" message, not a false success. A durable audit event is written for each admin decision
   (NFR4, NFR9, architecture lines 614–632).

8. **(AC8 — action availability follows the display-state matrix; localized messaging)** The detail page renders only
   the actions allowed for the voucher's current state per the **UI Display-State Matrix** (architecture 595–606,
   reproduced in Dev Notes). Each action shows a localized success/error result message and redirects back to the
   same voucher detail page; cancel uses an intent confirmation. No success/"paid" styling or wording is introduced
   by any action (only `posted` shows success — UX-DR20).

9. **(AC9 — language-file-driven; audit events registered; no leakage)** All new button labels, confirmation prompts,
   validation errors, and result messages live in language files. The **three new audit event names**
   (`admin.rechecked`, `admin.reviewed`, `admin.cancelled`) are registered in **all three** drift-guarded places so
   the existing detail audit-timeline (Story 4.2) renders them and the presenter sync test stays green: the
   presenter `EVENT_LABEL_KEYS` map, the `admin_vouchers` language file, and the test's `KNOWN_EVENTS` list. No raw
   SOAP, credentials, stack traces, SOAP op names, or parser internals appear in any message or audit payload
   (UX-DR28, NFR8). Specifically, `admin.rechecked`'s `payload.outcome` is the **terminal status/outcome token only**
   — the full reachable set is `posted`/`already_posted`/`confirmed_unposted`/`retry`/`manual_review`/`failed`/
   `unavailable`/`skipped` — never the raw service-result array, which can carry internal `reason` tokens
   (`stale_voucher`, `missing_paid_date`, `kuickpay_unavailable`, `provider_unreachable`). The outcome→message and
   outcome→payload maps **must include a safe default** that emits a generic string and stores a generic token for any
   unanticipated value, so an unmapped status can never echo a raw status/reason to the flash message or audit.

10. **(AC10 — verification honesty)** Changed PHP passes `php -l`; new pure/unit-testable logic is covered by the
    component PHPUnit 8.5 suite. To make the safety logic genuinely testable (not buried in a `Record`-backed model
    method or inline controller code the harness can't reach), the **per-state allowed-action map and the `allowed_from`
    sets are extracted into a pure structure** (a `const`/static on the model — see Task 2) and unit-tested directly;
    `reconcileVoucher()` is covered via the existing fake-client/fake-repo pattern. The report states exactly what ran
    and what could not — the live two-group ACL separation, framework-level CSRF enforcement, the `.pdt` render, and
    the `Record`-backed `transition()` UPDATE are **`php -l` + review only** here (no DB/live admin stack), per NFR12.
    PHP 8.2 syntax only.

## Tasks / Subtasks

- [ ] **Task 1 — Single-voucher reconcile entry on the shared service (AC1, AC2)**
  - [ ] Add a public method `reconcileVoucher(int $company_id, int $voucher_id): array` to
        `plugins/kuickpay_reconcile/lib/KuickPayReconcileService.php` that drives **one** voucher through the **same**
        inquiry → parse → validate → `persistEvidence` path the cron uses, by reusing the existing `processVoucher()`
        internals. Do **not** route through `run()` / `getResumeCursor()` (that is the cron batch-cursor path and would
        inherit a foreign cursor — see deferred-work line 80; processing one known voucher sidesteps it entirely).
  - [ ] Resolve gateway config (`gatewayConfigForCompany`); if unavailable/reconciliation disabled → return
        `['status'=>'skipped','reason'=>'kuickpay_unavailable']` (controller surfaces a safe message).
  - [ ] **Fetch + fresh-status short-circuit (race guard — do this BEFORE the inquiry).** Fetch the voucher
        company-scoped; if not found / wrong company → return `['status'=>'failed','reason'=>'voucher_not_found',
        'voucher_id'=>$voucher_id]` (open no run). Then, **immediately before calling the provider, the status is the
        live row's status** — if it is **no longer `pending`/`retry`** (e.g. the 5-minute cron just confirmed it while
        the admin sat on the page), **short-circuit without an inquiry**: return `['status'=>$currentStatus,
        'voucher_id'=>$voucher_id]` (no run opened, no provider call). This closes the common window in which a manual
        Check Now would otherwise re-inquire a row the cron already moved and **demote a just-confirmed voucher to
        `manual_review`** (see Dev Notes "Manual reconcile vs cron race"). For `confirmed_unposted` the controller then
        proceeds straight to `postVoucher()`; for any other non-`pending`/`retry` status it surfaces `invalid_state`.
  - [ ] Open a lightweight **`manual`**-trigger run record and close it with **the same ceremony the cron `run()`
        uses** (`KuickPayReconcileService.php:111-166`) so the manual run row is consistent with cron rows (4.4 will
        surface manual runs). Concretely: `$counts = $this->initialCounts()` (`:666`); `$run_id =
        $this->runRepository->open($company_id, 'manual', 0)` (verified signature `open(int $company_id, string
        $trigger_type, int $cursor): int`); record `reconciliation.run.started` (payload exactly
        `['trigger_type'=>'manual','cursor'=>0]`, mirroring the cron); build the client, set
        `$counts['total_eligible'] = 1`, call `$outcome = $this->processVoucher($company_id, $run_id, $voucher,
        $client)` (which already writes the `kuickpay_reconciliation_items` row + evidence audit), then
        `$counts = $this->countOutcome($counts, $outcome['new_status'], $outcome['error'])` (`:680`). In a
        **`finally`**, **guard exactly as `run()` does — only when `$run_id > 0`** call
        `close($run_id, $status, $counts, 0, json_encode(['status'=>$status,'counts'=>$counts]))` (verified signature
        requires `status, counts, cursor, summary`) and record `reconciliation.run.completed`; wrap close/audit in
        best-effort `try/catch (Throwable)`. If `open()` / client-build throws **before** `$run_id` exists →
        `status='failed'`, skip close, return `['status'=>'failed','reason'=>'run_open_failed','voucher_id'=>$voucher_id]`.
  - [ ] **Caught-inquiry-exception path (provider timeout — the most common real failure).** `processVoucher`
        **catches** `Throwable` from `billPaymentInquiry`/`parse` and returns `['new_status'=>$prior_status,
        'error'=>true]` — it does **not** rethrow (`:325-343`), so the row stays `pending`/`retry` with no evidence
        write. When `$outcome['error'] === true`, return `['status'=>'unavailable','reason'=>'provider_unreachable',
        'run_id'=>$run_id,'voucher_id'=>$voucher_id]` (a safe token — **never** the raw exception) so `recheck()` can
        say "couldn't reach KuickPay, try again" rather than a misleading "still pending" (AC1 = the *real* outcome).
  - [ ] **Return shape** (controller `recheck()` branches on `status`): success →
        `['status' => <new voucher status: 'confirmed_unposted'|'retry'|'manual_review'|'failed'>, 'run_id' => int,
        'voucher_id' => int]`; provider unreachable → `['status'=>'unavailable','reason'=>'provider_unreachable', …]`;
        config disabled → `['status'=>'skipped','reason'=>'kuickpay_unavailable']`; setup/not-found → `failed` with the
        reason tokens above. `reconcileVoucher()` **never returns `posted`** — posting is the controller's separate
        `postVoucher()` step (this method runs only the inquiry→parse→validate→persist reconcile leg, mirroring the
        `reconcile_pending` cron, not `post_confirmed`).
  - [ ] **No batch lock — with the residual named.** Do **not** acquire the company-wide `reconcile_pending` batch lock
        for a single manual reconcile (it would make Check Now fail with `lock_held` whenever the 5-minute cron is
        mid-run). The fresh-status short-circuit above closes the *pre-inquiry* race; the **residual** is the
        *during-inquiry* interleave (cron confirms while the manual SOAP call is in flight), where
        `persistEvidence`'s stale-guard + its **un-status-guarded terminal `edit()` (`:435`)** will demote the
        cron's `confirmed_unposted` to `manual_review`. That is **safe** (fails closed, evidence preserved, NFR9 held)
        but is an operational regression this story introduces; it is documented in Dev Notes "Manual reconcile vs
        cron race" and the root cause (status-guarding `:435`) is logged in `deferred-work.md`. Record this choice +
        residual in Dev Agent Record.
- [ ] **Task 2 — Status-guarded transition methods on the model (AC3, AC4, AC7)**
  - [ ] Add a generic guarded transition to `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`, e.g.
        `transition(int $voucher_id, int $company_id, string $new_status, array $allowed_from, array $extra = []): bool`
        mirroring `expire()`’s pattern: `UPDATE … SET status=?, date_updated=now, <extra> WHERE id=? AND company_id=?
        AND status IN (allowed_from)`; return `rowCount() === 1`. `$new_status` must be in `self::STATUSES`; **allowlist
        `$extra` keys with `array_intersect_key($extra, array_flip(self::FIELDS))`** (the same defensive pattern
        `edit()` uses at `:83-98`) so an unexpected key can never write a non-`FIELDS` column.
  - [ ] **Extract the safety map as a pure structure — pin the concrete shape** (no "e.g."): two `public const` maps on
        the model, `ALLOWED_ACTIONS_BY_STATE` (state → ordered list of `recheck|review|cancel` that render/are legal)
        and `ALLOWED_FROM_BY_ACTION` (action → its `allowed_from` set). These are the **single source of truth**: the
        view button-gating (Task 5), the controller state-checks (Task 3), and the `$allowed_from` argument passed to
        `transition()` **all consume these constants** — they are **not** to be re-typed as inline literals at the call
        sites (the inline sets shown in Task 3 / the matrix are documentation *of* these constants, not a second
        authority). Pure (no `Record`), so AC10 unit-tests them directly (Task 8). This is the one place the state
        machine is defined.
  - [ ] For the note write, do the read-modify-write **in `review()`/`cancel()`** (not in `transition()`, which takes
        no `$note`/`$staff_id`): read the current `admin_notes` via `getForCompany()`, compute the appended value in
        PHP, and pass it as `$extra['admin_notes']` to `transition()` so it lands in the **same** guarded UPDATE as the
        status change. **Append format** (newest first, prior notes preserved — never overwrite history):
        `"[" . date('c') . "] (staff #" . $staff_id . ") " . $note` prepended to the existing column value with a
        `"\n"` separator. The 4.2 detail view already runs `Html->safe()` on `admin_notes`, so storage stays raw text.
  - [ ] Cancel uses the same `transition()` with `new_status='cancelled'`, the appended **required** note, and **no**
        payment-field writes.
- [ ] **Task 3 — Manual action controller methods (AC1, AC3, AC4, AC6, AC7, AC8)**
  - [ ] Add **public** `recheck()`, `review()`, and `cancel()` action methods to the **existing**
        `plugins/kuickpay_reconcile/controllers/admin_vouchers.php` (see Dev Notes "Controller location decision").
        They **must be public** — Blesta/minPHP only routes POSTs to public controller methods; a private method 404s.
        Each: rejects non-POST (`if (empty($this->post)) { redirect(detail); return; }`); resolves `company_id`
        server-side; reads the `{id}` from `$this->get[0]` with the `ctype_digit` guard (as `detail()` does); fetches
        company-scoped via `getForCompany`; enforces the per-action ACL guard (Task 4); validates state per the matrix;
        performs the action; records the audit event; sets a localized `flashMessage`; redirects back to
        `…/admin_vouchers/detail/{id}/`.
  - [ ] **Service loading:** the reconcile/posting services are **lib classes, not models** — load via `Loader::load`
        + `new`, **not** `Loader::loadModels`. (Both precedents use `Loader::load(...) + new`; the path expr differs —
        `admin_main::run():54` uses `PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . '…'`, the `cron()` hook uses
        `dirname(__FILE__) . DS . 'lib' . DS . '…'`. From a controller, use the `PLUGINDIR` form:)
        `Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayReconcileService.php'); $svc = new
        KuickPayReconcileService();` (and likewise `KuickPayPostingService`). Do this lazily inside `recheck()`, not in
        `preAction()`.
  - [ ] **Pass per-action permission booleans to the view (Tasks 3 ↔ 5).** `staffGroupAllows()` is a **private**
        controller method — a `.pdt` cannot call it. In `detail()` (the GET that renders the page), after loading,
        `$this->set('can_recheck', $this->staffGroupAllows('recheck'))` and likewise `can_review`/`can_cancel`, so
        Task 5's view gates buttons off these booleans (the controller action guards remain the authority).
  - [ ] `recheck()`: for `pending`/`retry` → call `reconcileVoucher()`, re-fetch company-scoped, and if now
        `confirmed_unposted` call `(new KuickPayPostingService())->postVoucher($company_id, $freshVoucher)`; for
        `confirmed_unposted` → call `postVoucher()` only (skip inquiry). Record `admin.rechecked` with **audit context**
        `['company_id'=>…, 'voucher_id'=>…, 'run_id'=>…]` (the manual `run_id` from `reconcileVoucher()`, so 4.4's
        run-detail view can group it) and **payload** `['staff_id'=>…, 'prior_status'=>…, 'outcome'=> <terminal token
        only>]` — never the raw result array (AC9). **Map every composite outcome to a safe message via the table in
        Task 7** — covering: reconcile→`confirmed_unposted`→post→`posted`; reconcile→`confirmed_unposted`→post→
        `manual_review` (incl. the **pre-existing null-`date_paid` guard** — `postVoucher` fails closed to
        `manual_review`/`missing_paid_date`; surface a generic "routed to manual review", never the internal reason);
        reconcile→`retry`; reconcile→`manual_review`; reconcile→`failed`; reconcile→`unavailable`/`provider_unreachable`
        (caught inquiry exception → "couldn't reach KuickPay, try again"); reconcile→`skipped` (`kuickpay_unavailable`);
        post→`already_posted`; post→`skipped`; post→`failed`; **plus a safe default** for any unanticipated token.
  - [ ] `review()`: require a non-empty note (`$this->post['admin_note']`); on empty → error message, no change.
        Guarded transition to `manual_review` from `{pending,retry,failed,expired,confirmed_unposted}`; append the
        note; record `admin.reviewed` (payload `staff_id`, `prior_status`).
  - [ ] `cancel()`: **require a non-empty note** (same contract as `review()`; empty → `!error.note_required`, no
        change — the UX source requires a note on cancel); guarded transition to `cancelled` from
        `{pending,retry,failed,expired,manual_review}`, appending the note; record `admin.cancelled` (payload
        `staff_id`, `prior_status`). Confirm the forbidden set `{confirmed_unposted,posted,cancelled}` can never
        transition (status guard + hidden button). See Dev Notes "Cancel of confirmed-origin evidence via
        `manual_review`" for the two-route path decision.
- [ ] **Task 4 — Separate per-action ACL permissions + exact-action gate (AC6)**
  - [ ] Add three `getPermissions()` rows to `kuickpay_reconcile_plugin.php`, all `alias =>
        'kuickpay_reconcile.admin_vouchers'`, `group_alias => 'admin_billing'`, with distinct actions: `recheck`,
        `review`, `cancel` (keep the existing `*` and `diagnostics` rows — re-sync deletes+re-adds the whole set).
  - [ ] Generalize Story 4.2's `canViewDiagnostics()` (`:214-240`) into a private exact-action helper
        `private function staffGroupAllows(string $action): bool` — keep its **exact** verified body (StaffGroups +
        `Acl->getAccessList('staff_group_'.$id, 'kuickpay_reconcile.admin_vouchers')`, scanning entries for the exact
        `$access->action === $action` with `$access->permission === 'allow'`, ignoring the `*` wildcard), just
        parameterizing the action token. Then `canViewDiagnostics()` becomes a one-line delegate:
        `return $this->staffGroupAllows('diagnostics');` (preserves the 4.2 detail-page gate unchanged — `php -l` +
        re-read after the refactor so AC8 of 4.2 does not regress). **Do not** swap this for `$this->authorized(...)`:
        the proven 4.2 gate is `Acl->getAccessList`, and that is what `recheck()/review()/cancel()` must call and
        **fail closed** (error + redirect) when denied. **Name each method to equal its ACL action token**
        (`recheck`↔`recheck`, `review`↔`review`, `cancel`↔`cancel`) so any framework-level route gate stays consistent.
  - [ ] Bump `config.json` `1.6.0 → 1.7.0` and add an **empty** `version_compare(... '1.7.0', '<')` branch in
        `upgrade()` (no schema change; the bump only re-syncs the permission set — same mechanism as 4.2's 1.6.0).
  - [ ] Add the three permission-name language keys to `language/en_us/kuickpay_reconcile_plugin.php`
        (`permission.vouchers_recheck`, `permission.vouchers_review`, `permission.vouchers_cancel`).
- [ ] **Task 5 — Detail-view action controls (AC5, AC8, AC9)**
  - [ ] In `views/default/admin_vouchers_detail.pdt`, add an **Actions** region (near the Admin Notes box) that
        renders only the matrix-allowed actions for `$voucher->status` (drive this off the pure map from Task 2; the
        `$mono`/`$dateOr` helpers are inline closures already defined at the top of this `.pdt`). Each action is a
        Blesta `Form->create()` POST form to the matching route with the voucher id in the URL (so the CSRF token is
        emitted and the action reads `$this->get[0]`), e.g.
        `$this->Form->create($this->base_uri . 'plugin/kuickpay_reconcile/admin_vouchers/recheck/' . (int) $voucher->id . '/');`
        (likewise `…/review/…`, `…/cancel/…`). Mark Manual Review and Cancel both include a **required** note textarea.
        **Cancel confirmation uses the verified shipped pattern** (not a bare `data-confirm`): the submit button carries
        `class="… modal-confirm-warning"` with
        `data-confirm-message="<?php echo $this->Html->safe($this->_('AdminVouchers.confirm.cancel', true));?>"`, which
        the admin theme's modal helper intercepts and submits the nearest form. (`modal-confirm-warning` is **verified
        present** in the admin theme modal JS alongside `-delete`/`-success`; shipped precedent for the class+attribute
        is `plugins/support_manager/views/default/admin_departments.pdt:63`, which uses `modal-confirm-delete`.) **No
        Force Paid / Mark Paid control in any branch.** Keep buttons keyboard-reachable; introduce no success styling.
  - [ ] Gate each button on the controller-set booleans from Task 3 (`$can_recheck`/`$can_review`/`$can_cancel`) to
        hide a control the admin cannot use — but the controller action guard (Task 4) remains the authority.
- [ ] **Task 6 — Audit-event registration across the three drift-guarded sites (AC9)**
  - [ ] Add `admin.rechecked`, `admin.reviewed`, `admin.cancelled` to `KuickPayVoucherListPresenter::EVENT_LABEL_KEYS`.
  - [ ] Add the matching `AdminVouchers.event.admin.rechecked|reviewed|cancelled` keys to
        `language/en_us/admin_vouchers.php`.
  - [ ] Update the presenter test's `KNOWN_EVENTS` const (and the `'… 14 emitted event names'` assertion message →
        17) in `tests/KuickPayVoucherListPresenterTest.php`, or `testEventMapKeysEqualTheKnownEvents`,
        `testEventLabelKeyForEveryKnownEvent`, and the language ↔ presenter sync guard (line ~683) will fail.
- [ ] **Task 7 — Language: action labels, prompts, validation + result messages (AC8, AC9)**
  - [ ] Add to `language/en_us/admin_vouchers.php` (enumerate the keys — do not leave them implied):
        button labels `AdminVouchers.action.recheck|review|cancel`; the note-textarea label `AdminVouchers.label.admin_note`;
        the cancel prompt `AdminVouchers.confirm.cancel`; validation errors `AdminVouchers.!error.note_required`
        (**shared by `review()` and `cancel()`**), `AdminVouchers.!error.invalid_state`, and
        `AdminVouchers.!error.acl_denied` (the fail-closed ACL message from Task 4); the review/cancel result strings
        `AdminVouchers.!success.review|cancel`; and the Check Now outcome strings below. Keep wording safe (no raw
        codes/op names). **Check Now outcome → key (curated; the safe default catches anything unlisted):**

        | Outcome | Language key | Suggested copy |
        |---|---|---|
        | reconcile→confirmed→post→`posted` | `AdminVouchers.!success.recheck_posted` | "Checked — payment posted." |
        | →post→`manual_review` / reconcile→`manual_review` (incl. null-`date_paid`) | `AdminVouchers.!success.recheck_manual_review` | "Checked — routed to manual review." |
        | reconcile→`retry` | `AdminVouchers.!success.recheck_retry` | "Checked — provider still unavailable, will retry." |
        | post→`already_posted` | `AdminVouchers.!success.recheck_already_posted` | "Already posted." |
        | reconcile→`unavailable` (provider unreachable) | `AdminVouchers.!error.recheck_unreachable` | "Couldn't reach KuickPay — please try again." |
        | reconcile→`skipped` (`kuickpay_unavailable`) | `AdminVouchers.!error.recheck_unavailable` | "KuickPay reconciliation is unavailable." |
        | reconcile→`failed` / post→`failed` / post→`skipped` | `AdminVouchers.!error.recheck_failed` | "Check failed — please try again later." |
        | any unanticipated token (**safe default**) | `AdminVouchers.!error.recheck_failed` | (generic — never interpolate the raw token) |
- [ ] **Task 8 — Tests + verification (AC10)**
  - [ ] Add `KuickPayReconcileService::reconcileVoucher()` unit coverage using the existing fake client / fake repos
        pattern in `tests/KuickPayReconcileServiceTest.php` (single-voucher confirm path; skipped-when-unavailable;
        does **not** post in the reconcile step; a manual run row is opened with `trigger_type='manual'`, `cursor=0`
        and closed with counts/summary; and the **null-`date_paid` confirm** case so the reconcile leg returns
        `confirmed_unposted` and the documented `postVoucher`→`manual_review` guard is exercised separately).
  - [ ] **Unit-test the pure safety map** (Task 2's `ACTIONS_BY_STATE` / `allowed_from` structure) directly — per the
        matrix, each state maps to exactly the allowed actions, and the `allowed_from` sets forbid cancel from
        `confirmed_unposted`/`posted`/`cancelled` and forbid review from `posted`/`cancelled`. This is the AC10-covered
        home for the state-machine safety logic (the `Record`-backed `transition()` itself stays `php -l` + review only
        — the harness has fake repos, not a fake `Record`; disclose this honestly).
  - [ ] **`transition()` itself is `php -l` + code-review only** (the harness has fake repos, not a fake `Record`, so a
        guarded-UPDATE test cannot run here — `expire()` has none either; disclose honestly in Dev Agent Record). Its
        *safety inputs* — the `allowed_from` sets — are the part that carries transcription risk, and those are covered
        by the pure-map test above. Do **not** add a `transition()` DB test "where supported" (it is supported nowhere
        here) — that instruction is removed to avoid the round-1 contradiction.
  - [ ] **CSRF verification (AC6, honesty):** if a live admin/DB stack is unavailable here, disclose in Dev Agent
        Record that framework-level CSRF enforcement could not be exercised (a tokenless/invalid-token POST to
        `recheck/review/cancel` being rejected) — it rests on the `admin_main::run()` precedent + the ionCube/unreadable
        `app_controller`, the same disclosure 4.2 made for the live ACL gate. Keep every action form on `Form->create()`.
  - [ ] Run `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php
        tests`; confirm the new event keys keep the presenter sync guard green and that the only pre-existing failure
        is the documented `KuickPaySecretLeakageTest` baseline. `php -l` every changed PHP file. State the PHP version
        used.

## Dev Notes

### Controller location decision (READ FIRST — deliberate, documented variance)

The three manual actions are added as POST methods (`recheck`, `review`, `cancel`) on the **existing
`admin_vouchers` controller** — the same controller that owns the list (4.1) and detail (4.2) — **not** a new
`admin_manual_review.php`. Rationale:

- The action forms live **on the voucher detail page** (`admin_vouchers_detail.pdt`) and redirect back to it; Blesta's
  idiom keeps a detail view's POST actions on the controller that renders the detail (mirrors `support_manager`'s
  ticket view + reply/close on one controller). Splitting per-voucher mutations onto a second controller would
  detach them from the page that launches them.
- Story **4.4** ("Manage Manual Review **Queue** and Run Results") is the natural owner of the architecture's
  `admin_manual_review.php` (queue workflow) and `admin_reconciliation.php` (run summaries). 4.3 is per-voucher
  actions, not a queue.
- 4.2 already extended `admin_vouchers` beyond "search/list" by adding `detail()` + the gated diagnostics section;
  this is the consistent continuation.

**Documented variance:** architecture line 792 scopes `admin_vouchers.php` to "search, list, and detail only" and
lines 795–797 reserve `admin_manual_review.php` for "human review workflow only." We intentionally keep the 4.3
actions on `admin_vouchers` (variance recorded here and in Project Structure Notes), exactly as 4.2 documented its
`admin_vouchers_detail.pdt` view-name variance. *If the reviewer prefers a dedicated controller, the action methods
move wholesale to `admin_manual_review.php` with the same ACL/guard contract — see **Open Questions for Reviewer** at
the end of this story.*

### What already exists (read before writing — exact anchors)

- **Controller wiring** `controllers/admin_vouchers.php` — `preAction()` (`:24-41`) calls `requireLogin()`, loads
  `KuickpayVouchers` + `KuickpayVoucherInvoices` + the presenter, and sets the view; `detail()` (`:143-201`) is your
  template for: `ctype_digit` id guard (`:151`), company-scoped `getForCompany` fetch + safe not-found redirect
  (`:157-164`), and `flashMessage('error', …, null, false)` + `redirect(...)` (`:161-162`). The exact-action ACL
  helper `canViewDiagnostics()` (`:214-240`) is the mechanism to **generalize** for the three new actions.
- **Mutating-POST precedent** `controllers/admin_main.php::run()` (`:34-91`): the only existing write action in this
  plugin. Copy its shape — `if (empty($this->post)) redirect(...)` (`:36-38`), validate input, call a service,
  branch on `$result['status']`, `flashMessage(...)` + `redirect(...)`. **CSRF:** `run()` does **not** call
  `validateToken()` explicitly; it relies on Blesta's framework-level CSRF check (token emitted by `Form->create()`
  in `admin_main.pdt:9`). `app/app_controller.php` is ionCube/unreadable, so this precedent is the evidence that no
  explicit token call is required — keep every action form on `Form->create()` so the token is present.
- **Shared reconcile service** `lib/KuickPayReconcileService.php` — `processVoucher(int $company_id, int $run_id,
  $voucher, $client): array` (`:301-344`) is the **per-voucher** inquiry→parse→`persistEvidence`→item→audit unit the
  cron uses; reuse it for Check Now's reconcile step. `run()` (`:98-169`) is the **batch** path (do not reuse — it
  fetches `getReconcilable()` and calls `getResumeCursor()`). `persistEvidence()` (`:375-438`) already has the
  fresh-reload **stale-guard** (`:393-409`: confirms only when the row is still `pending`/`retry`, else
  `manual_review`+`stale_voucher`) — this is why a single manual reconcile need **not** take the batch lock.
  `gatewayConfigForCompany()` (`:619-650`) resolves config + `reconciliation_enabled`.
- **Posting service** `lib/KuickPayPostingService.php` — `postVoucher(int $company_id, $voucher): array` (`:76-198`)
  is the **single-voucher** posting unit: it re-reads under `FOR UPDATE` row locks (`:94-95`), re-validates evidence
  (`:121-126`), adopts-or-creates the Blesta transaction, marks `posted`, and audits. **This is the only code allowed
  to create/apply a Blesta transaction** (`:161,169`). Check Now's posting step calls it directly for the one voucher;
  do not duplicate any of it. Outcomes: `posted | already_posted | skipped | manual_review | failed`.
- **Voucher model** `models/kuickpay_vouchers.php` — `getForCompany($id,$company)` (`:127-134`, scoped fetch — use
  this, never the unscoped `get()`); `edit($id,$company,$vars)` (`:83-98`, generic field-allowlisted update — **not**
  status-guarded, so add `transition()` for the guarded path); `expire()` (`:621-633`) is the exact status-guarded
  `rowCount()===1` pattern to mirror; `getForUpdate()` (`:642-651`) for locked reads; `getStatuses()` (`:312`,
  static) for the canonical 8 states; validation rules (`:658-725`) gate `status` to `self::STATUSES`. `FIELDS`
  (`:24-48`) already includes `admin_notes`, `status`, `date_updated`.
- **Audit service** `lib/KuickPayAuditService.php::record(string $eventName, array $context): void` (`:30-44`) —
  context keys: `company_id` (required, int), `voucher_id`, `run_id`, `redacted_trace_id`, `evidence_hash`,
  `payload` (array → `json_encode`d). Wrap admin-action audit writes so a failed audit never aborts the user action
  (catch `Throwable`), as the services already do.
- **Presenter** `lib/KuickPayVoucherListPresenter.php` — `EVENT_LABEL_KEYS` (`:145-160`, the **closed** event
  allowlist the 4.2 audit timeline renders through) + `DEFAULT_EVENT_LABEL_KEY` (`:165`). Adding events here without
  the language keys **and** the test list breaks the drift guard (below).
- **Audit-event drift guard (WILL FAIL if you skip a site)** `tests/KuickPayVoucherListPresenterTest.php`:
  `testEventMapKeysEqualTheKnownEvents` (`:495-503`) asserts `array_keys(EVENT_LABEL_KEYS) === self::KNOWN_EVENTS`
  (message hard-codes "14 emitted event names"); `testEventLabelKeyForEveryKnownEvent` (`:471-481`) and the
  language↔presenter sync guard (`:674-707`, `include`s the `admin_vouchers` language file) both require every event
  to have a matching `AdminVouchers.event.<token>` lang key. **Three new events → update all four spots: the
  presenter map, the language file, `KNOWN_EVENTS`, and the "14"→"17" message.**
- **Detail view** `views/default/admin_vouchers_detail.pdt` — Box 3 "Admin Notes" (`:166-181`) currently renders
  `admin_notes` read-only (4.2 reserved writing for **this** story). Add the Actions region beside it. Box 5
  diagnostics is permission-gated (`:220+`). Use the existing `$mono`/`$dateOr` helpers and `$this->Html->safe()` on
  every dynamic value (4.2 review findings hardened this — do not regress).
- **Plugin lifecycle** `kuickpay_reconcile_plugin.php` — `getPermissions()` (`:227-253`, three rows today: vouchers
  `*`, admin_main `*`, vouchers `diagnostics`); `getActions()` (`:204-220`, nav only); `cron()` (`:171-197`, the
  reconcile/post/expire tasks Check Now mirrors in-request); `upgrade()` (`:100-145`, empty version-bump branches for
  permission re-sync). `config.json` is `1.6.0`.

### Action-by-action contract (the safety core — match exactly)

**UI Display-State Matrix — allowed admin actions per state** (architecture 595–606; this is the authority for which
buttons render and which guarded transitions are legal):

| State | Admin label | Check Now (`recheck`) | Mark Review (`review`) | Cancel (`cancel`) |
|---|---|---|---|---|
| `pending` | Voucher active, not posted | ✅ reconcile → post if confirmed | ✅ | ✅ |
| `retry` | Provider unavailable | ✅ reconcile → post if confirmed | ✅ | ✅ |
| `confirmed_unposted` | Validated evidence, ready to post | ✅ **post only** (skip inquiry) | ✅ (holds posting) | ❌ forbidden (real paid evidence) |
| `posted` | Posted to Blesta | ❌ (done) | ❌ forbidden (cannot un-post) | ❌ forbidden (real transaction) |
| `failed` | Evidence mismatch, review required | ❌ (MVP — see note) | ✅ | ✅ |
| `expired` | Expired, not posted | ❌ | ✅ | ✅ |
| `manual_review` | Duplicate/ambiguous evidence | ❌ (MVP — see note) | — (already there) | ✅ |
| `cancelled` | Cancelled, not posted | ❌ | ❌ | ❌ (already cancelled) |

- **Check Now scope (MVP):** offer on `pending`, `retry`, `confirmed_unposted` only. Excluded on `manual_review`
  because `persistEvidence`'s stale-guard only re-confirms a `pending`/`retry` row, so re-inquiry of a
  `manual_review` voucher is a guaranteed no-op today; "retry-reconcile from manual_review" is a Story 4.4 / forward
  concern (also true for `failed`). Document this; do not render a button that does nothing.
- **Mark Manual Review forbidden from `posted`/`cancelled`:** moving a posted voucher to review would imply
  un-posting a real payment. Guard `allowed_from = {pending,retry,failed,expired,confirmed_unposted}`.
- **Cancel forbidden from `confirmed_unposted`/`posted`/`cancelled`:** these carry validated/real paid evidence; AC4
  ("confirmed paid evidence cannot be deleted through the cancel action"). Guard `allowed_from =
  {pending,retry,failed,expired,manual_review}`. Cancel is a **status change only — never a DELETE**; the voucher
  row, its `kuickpay_reference`/`evidence_hash`/`date_paid`, invoice links, and audit events all persist. **Cancel also
  requires a non-empty admin note** (UX source: note required when canceling).
- **Cancel of confirmed-origin evidence via `manual_review` — DECISION (documented, intentional):** a voucher that
  carried provider-confirmed evidence can reach `cancelled` through `manual_review`, by **two routes**: (a)
  `confirmed_unposted →[review]→ manual_review →[cancel]→ cancelled` (Review holds posting), and (b)
  `confirmed_unposted →[recheck/post]→ manual_review` when `postVoucher`'s null-`date_paid` guard fails closed
  `→[cancel]→ cancelled`. So the accepted invariant is *"any confirmed-origin voucher that lands in `manual_review` is
  cancellable,"* not merely the `review` route. **The real risk is NOT row deletion** — AC4's *no-DELETE* guarantee
  holds (both transitions write only `status`+`admin_notes`; `kuickpay_reference`/`evidence_hash`/`date_paid`/invoice
  links/audit all persist) — **the real exposure is that a provider-confirmed payment can be abandoned**: the customer
  paid, KuickPay confirmed it, and the invoice is never marked paid in Blesta. We **accept this** because it is
  *required* for the legitimate case (a confirmed **duplicate** must be cancellable), and the compensating controls are
  adequate for a human-judgment action: two deliberate clicks, a **required note on each** (AC3 + the AC4 cancel-note
  rule), and a full audit trail carrying `prior_status` (showing the evidence was confirmed). The rejected alternative —
  hard-coding "was-this-ever-confirmed" introspection into `cancel()` — would trap correctly-reviewed duplicates. The
  **direct** `confirmed_unposted → cancel` remains forbidden. (Surfaced as an open question for the reviewer at the end
  of this story.)
- **Race safety (AC7):** the `WHERE status IN (allowed_from)` clause is the concurrency guard. If the posting cron
  flips a voucher to `posted` between page render and the admin's click, the guarded UPDATE matches 0 rows →
  `rowCount()===0` → show `AdminVouchers.!error.invalid_state` ("voucher state changed; refresh"), never a false
  success. Mirror `expire()`'s `rowCount()===1` contract. **This guard protects the three `transition()` writes only.**
- **Manual reconcile vs cron race (the one un-`transition()`-guarded write — read before implementing Task 1):** the
  `recheck()`/`reconcileVoucher()` reconcile leg does **not** go through `transition()`; its terminal write is
  `persistEvidence`'s **un-status-guarded `edit()` at `KuickPayReconcileService.php:435`**. Because the manual path
  deliberately skips the batch lock, it can run concurrently with the cron on the same voucher. Sequence: admin clicks
  Check Now on a `pending` voucher → manual SOAP inquiry starts (slow) → **during that window** the `reconcile_pending`
  cron confirms the same voucher (`status=confirmed_unposted`, writes `date_paid`/`kuickpay_reference`) → the manual
  call's `persistEvidence` fresh-reload (`:394`) now sees `confirmed_unposted` ∉ `{pending,retry}` → sets
  `status='manual_review'`+`stale_voucher` (`:403-404`) → the **ungated `edit()` (`:435`) overwrites** the cron's
  `confirmed_unposted` with `manual_review`, leaving `date_paid` dangling. **The customer paid, the provider confirmed,
  and the voucher is parked in `manual_review` until a human acts.** Classification: **safe** (fails closed; no wrong
  amount, no bad/duplicate transaction; evidence preserved → NFR9 honored) but a genuine **operational** regression.
  The round-1 framing that called the stale-guard "protective" was incomplete — *in this race the stale-guard is the
  cause of the demotion.* Mitigation in this story: the **pre-inquiry fresh-status short-circuit** (Task 1) closes the
  common case (cron confirmed *before* the manual inquiry began); the during-inquiry interleave remains and is accepted
  as a safe, audited residual (the row's `stale_voucher` summary + the `kuickpay_reconciliation_items` row make it
  diagnosable; 4.4 owns manual_review recovery). The **root cause** — `:435` should be a status-guarded UPDATE
  (`WHERE status IN ('pending','retry')`) so a racing manual reconcile matches 0 rows instead of clobbering — is shared
  Epic-3 code and is **logged in `deferred-work.md`**, not changed here.
- **Attribution & note (AC3, AC4):** staff id from `$this->Session->read('blesta_staff_id')` (same source as
  `canViewDiagnostics`). Put `staff_id` + `prior_status` in the audit payload (durable, redacted-safe — no names/PII).
  Append the note to `admin_notes` with an ISO timestamp + staff prefix (`"[" . date('c') . "] (staff #" . $staff_id .
  ") " . $note`), newest first, preserving prior notes (the audit log is the durable history; the column is the
  at-a-glance view 4.2 already renders). **Empty note on BOTH `review()` and `cancel()` → reject before any write**
  (the UX source requires a note for Manual Review *and* cancel). The read-modify-write note append is guarded on
  `status` (not on the note text), so two concurrent appends could in theory clobber — acceptable for MVP since the
  audit log is authoritative; the column is convenience.

### ACL separation — the #1 risk (4.2 had a code-review finding here)

The plugin already carries a wildcard `kuickpay_reconcile.admin_vouchers` `action => '*'` row (view list/detail). In
Blesta, `Permissions::authorized()` short-circuits `return true` **only** when *neither* a group *nor* a permission
row exists; because the `*` row keeps `$permission` truthy for this alias, authorization always falls through to
`Acl->check($aro, $aco, $action)`, which is **default-deny** for any specific action a group was not explicitly
granted — `*` and a named action are **distinct ACL axes** (corroborated by `staff_groups::setPermissions()` writing
explicit per-action allow/deny, and by `support_manager`'s `*`+`delete` precedent; `app/components/acl/acl.php` is
ionCube/unreadable, so this rests on the readable `permissions.php` flow + that shipped precedent — 4.2 Dev Notes).

**Consequence for 4.3:** declaring `recheck`/`review`/`cancel` permission rows + an **explicit exact-action ACL
check** inside each action method makes the three capabilities separately grantable and **fail closed** — a group
with only `*` (view) is denied all three. **Critically, the permission action token MUST equal the controller method
name** (`recheck`↔`recheck`, etc.): if a method name has no matching specific permission row and the framework
route-gate runs `authorized(..., '<method>')`, `Acl->check` would default-deny it for *everyone* (nobody is granted
that token), breaking the feature. Align them, and rely on the in-method `staffGroupAllows('<action>')` guard
(generalized from `canViewDiagnostics`) as the readable, self-verifying authority. **Honest verification (no
DB/admin stack here):** assert in Dev Agent Record that you could not live-test the two-group separation
(group with `recheck` granted can recheck; group with only `*` cannot) and that it rests on the `permissions.php`
flow + precedent — same disclosure 4.2 made.

### Check Now — does it post? (yes — and only via the posting service)

AC1 says "the same parser, validation, **and posting path** as scheduled reconciliation." Scheduled reconciliation is
two crons: `reconcile_pending` (pending→confirmed_unposted) then `post_confirmed` (confirmed_unposted→posted). Check
Now reproduces **both** for the one voucher, in-request, using the **same services** (`reconcileVoucher()` then
`postVoucher()`), so a single click drives a `pending` voucher all the way to `posted` exactly as the two crons
would — with zero new payment logic and `KuickPayPostingService` as the sole transaction author (AC2/AC5). The
result message reports the terminal outcome (e.g. "Checked — payment posted", "Checked — still pending", "Checked —
routed to manual review", "Skipped — KuickPay unavailable").

### Deferred-work items this story touches (read `implementation-artifacts/deferred-work.md`)

- **Line 80 — `getResumeCursor` only resumes `trigger_type='cron'`:** named as a future risk for "Epic 4 Check Now."
  This story **avoids** it by not routing the single-voucher reconcile through `run()`/`getResumeCursor` at all.
  Leave the deferred item open (bulk/4.4 may still need the cursor scoping); just don't reintroduce it.
- **Line 109 — posting batch head-of-line blocking / retry cap → escalate to `manual_review` after N attempts:**
  noted as "Epic-4 scope." A posting **retry cap** is **not** in this story's ACs; do not add it here. If you observe
  it while wiring Check Now's post step, log it as still-deferred — out of 4.3 scope.
- **Line 101 — `retireVoucher()` no-op cancel reports success / non-atomic audit:** that is the *gateway-side
  reference* retire path (`KuickPayVoucherReferenceService`), distinct from this admin Cancel. Your admin Cancel must
  use the **`rowCount()===1` guarded** transition so a no-op returns `false` (and you skip the success message + the
  `admin.cancelled` audit) — i.e. implement the hardening that item asks for, on the admin path.

### Technical requirements / guardrails

- **Company scoping (AC7):** every read/write takes `company_id` as a server-resolved argument; `getForCompany` /
  `transition(... $company_id ...)`; never read `company_id` from the request; a cross-company/missing id →
  safe not-found redirect (reuse `detail()`'s pattern).
- **POST-only + CSRF (AC6):** `if (empty($this->post)) { redirect(detail); return; }` at the top of each action; every
  action form built with `Form->create()` so Blesta emits/validates the token (admin_main precedent). GET never
  mutates.
- **No float math / no schema change:** amount/currency comparisons stay inside the reused services (which already use
  integer minor units). No new columns — `admin_notes`, `status`, the `manual` run trigger_type, and
  `kuickpay_audit_events` all already exist; the `1.7.0` bump is permission-re-sync only.
- **No leakage (AC9):** result/error strings and audit payloads carry only safe tokens (status names, staff_id,
  outcome) — never raw SOAP, op names, credentials, parser internals, or stack traces. `Html->safe()` on every
  dynamic value rendered in the view.
- **PHP 8.2 target**; plugin files are legacy global classes (no `declare(strict_types=1)`); use Blesta `Loader`,
  `Record`, `Language`, `Form`/`Widget`/`Html`/`Date`/`Acl` helpers and existing services only. Match each file's
  local style; keep diffs small (project-context anti-churn rules).

### Library / framework requirements

- **Bootstrap 5.3.8** admin theme — Blesta `Form`/`Widget`/`Html` helpers, `btn` classes, `bi bi-*` icons,
  `font-monospace` (not `text-monospace`). No new CSS/JS assets, no new JS framework. Cancel confirmation via the
  admin theme's existing modal convention — a submit button with `class="… modal-confirm-warning"` +
  `data-confirm-message` (verified precedent: `support_manager`'s `modal-confirm-delete`); avoid bespoke modals.
- `.pdt` views only (no Twig/Blade); thin views — render assigned variables and post to routes; no SQL/business logic
  in the view.

### Testing requirements

- No root `../tests`, no DB, no live admin web stack in this checkout. Put unit-testable logic where it can run:
  `KuickPayReconcileService::reconcileVoucher()` (fake client + fake repos, already established in
  `KuickPayReconcileServiceTest`), and the model `transition()` rules where the Record fake supports it. The
  controller methods, the live ACL gate, CSRF, and the `.pdt` render hit `Record`/the view stack/ionCube
  `app_controller` and **cannot** be unit-tested here — verify by `php -l` + review against the confirmed Blesta APIs
  (the `flashMessage(type,msg,null,false)`+redirect contract and non-index action template auto-resolution are both
  confirmed against real core/plugin call sites).
- Runner (project-context): `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap
  tests/bootstrap.php tests` (never `-c build/phpunit.xml`). If you add a new test class file, add its `require_once`
  to `tests/bootstrap.php`. Confirm the presenter sync guard passes with the three new events and that the only
  failure remains the pre-existing `KuickPaySecretLeakageTest` baseline (4.1 Debug Log) — no **new** failures.
- Per NFR12: report the exact commands run and the PHP version used (this host historically exposes 8.3.x / 7.4.x,
  not the 8.2 target — write 8.2-compatible syntax and disclose the runtime actually exercised).

### Project Structure Notes

- **Aligned** — all changes land under the plugin per the additional-requirements boundary (`epics.md:118-124`):
  `lib/KuickPayReconcileService.php` (+`reconcileVoucher`), `models/kuickpay_vouchers.php` (+`transition`),
  `controllers/admin_vouchers.php` (+`recheck`/`review`/`cancel` + generalized ACL helper),
  `views/default/admin_vouchers_detail.pdt` (+Actions region), `lib/KuickPayVoucherListPresenter.php` (+3 events),
  `kuickpay_reconcile_plugin.php` (+3 permissions, 1.7.0 branch), `config.json` (version),
  `language/en_us/admin_vouchers.php` (+action/result/event keys),
  `language/en_us/kuickpay_reconcile_plugin.php` (+3 permission names),
  `tests/KuickPayReconcileServiceTest.php` + `tests/KuickPayVoucherListPresenterTest.php` (+cases / KNOWN_EVENTS).
- **Variance (documented above)** — the manual action methods live on `admin_vouchers` rather than a dedicated
  `admin_manual_review.php` (architecture 792, 795–797). 4.4 owns the Manual Review **queue** + run summaries.
- **No schema change** — `admin_notes`/`status`/`kuickpay_audit_events`/`manual` trigger_type all pre-exist; the
  `1.7.0` bump re-syncs permissions only.

### References

- `epics.md` Story 4.3 ACs (lines 772–797); FR26 (line 75); NFR3 (line 91), NFR4 (line 93), NFR9 (line 103),
  NFR13 (line 111), NFR14 (line 113); UX-DR15/16/19/20/23/24/28 (lines 180–206); additional requirements / ACL +
  file-location + canonical states + "no force paid" (lines 117–148).
- `architecture.md` separate ACL permissions incl. recheck/note/cancel/diagnostics (lines 357–367); admin mutations
  require POST+auth+ACL+CSRF, GET read-only (line 377); no force-paid (line 375, 660); admin workbench Check
  Now/notes/cancel/close (lines 426–435); UI display-state matrix (lines 595–606); audit patterns + `admin.reviewed`
  (lines 610–634); anti-patterns incl. no-GET-mutation, no transaction outside posting service (lines 648–661);
  ownership/posting authority (lines 129, 518–527, 581–593); controller boundaries (lines 789–797); FR24–27 → structure
  (lines 833–841).
- Code: `controllers/admin_vouchers.php:24-41,143-201,214-240`; `controllers/admin_main.php:34-91` (+`admin_main.pdt:9`
  CSRF precedent); `lib/KuickPayReconcileService.php:98-169,301-344,375-438,619-650`;
  `lib/KuickPayPostingService.php:76-198`; `models/kuickpay_vouchers.php:83-98,127-134,312,621-651,658-725`;
  `lib/KuickPayAuditService.php:30-44`; `lib/KuickPayVoucherListPresenter.php:145-165`;
  `views/default/admin_vouchers_detail.pdt:166-181`; `kuickpay_reconcile_plugin.php:171-197,227-253,100-145`;
  `tests/KuickPayVoucherListPresenterTest.php:471-509,674-707`; `tests/KuickPayReconcileServiceTest.php:1-80`.
- Blesta ACL: `app/models/permissions.php:240-264` (`authorized` logic), `:278-294` (ACO registration);
  `app/components/acl/acl.php` (ionCube — gate behavior inferred); `plugins/support_manager/support_manager_plugin.php`
  (multi-permission `*`+specific-action precedent).
- `implementation-artifacts/deferred-work.md` lines 80, 101, 109 (Check Now / cancel-atomicity / posting-retry items).
- `_bmad-output/project-context.md` (PHP 8.2; Blesta loader/Record/Language/Form/Acl rules; no root `../tests`;
  external PHPUnit 8.5 runner; commit style).

### Previous Story Intelligence (Epics 1–4.2 — apply these or repeat past review cycles)

- **Company-scope every query** (3-3, 4-1 AC4, 4-2): the single most repeated finding. Every action fetch/update is
  company-scoped; the unscoped `get()` is reserved for already-scoped service callers — never the controller.
- **Exact-action ACL, not wildcard** (4-2 code-review finding "diagnostics gate bypassed by existing wildcard"):
  reuse that exact mechanism for the three new capabilities. This is the highest-risk regression area.
- **Status/event/label as closed allowlists** (2-5, 4-1, 4-2): new audit events go through the presenter map + the
  three drift-guard sites; never concatenate a DB value into a language key.
- **Status-guarded transitions + `rowCount()===1`** (3-3/3-5 posting locks, `expire()`): the race-safe contract; the
  generic `edit()` is **not** guarded — add `transition()`.
- **`Record->fetch()`/`fetchAll()` return `stdClass`** — use `->field`, not `['field']` (cumulative footgun).
- **`Html->safe()` on every dynamic value; `_()` needs the return flag** (4-1/4-2 view hardening) — applies to the new
  Actions region and any result message variables.
- **Plugin upgrade re-sync** (4-1/4-2): keep ALL nav + permission entries; bump the version to trigger the
  delete+re-add; staff-group grants for new permissions may need re-granting after upgrade (standard Blesta).
- **Success/"paid" styling only for `posted`** (4-1 AC6, UX-DR20): no action may introduce success styling/wording.
- **Verification honesty** (all retros, NFR12): disclose the PHP version run and exactly which suites ran; the
  `KuickPaySecretLeakageTest` baseline failure is pre-existing — confirm no NEW failures.
- **No over-scoping:** the Manual Review **queue**, reconciliation **run summaries**, and standalone note-only editing
  are **Story 4.4**; structured logs/audit hardening is **Story 4.5**. 4.3 is exactly Check Now + Mark Manual Review
  (note required) + Cancel/Close.

### Git Intelligence Summary

Epic 3 (reconcile/posting/safety contracts) and Stories 4.1 (list/search/filter) + 4.2 (detail + gated diagnostics)
are `done`. The most recent commits (`9a68e138`, `d2a967a7`, `fefa1db9`, `f80b1f30`, `eada8601`) are 4.2 review
follow-ups hardening the voucher detail view + presenter language-key coverage — i.e. the very surfaces this story
extends. The `admin_vouchers` controller/detail view/presenter and the durable voucher/audit schema + the shared
reconcile/posting services are stable and were built to be reused here: 4.3 adds the **write** path (Check Now,
Mark Manual Review, Cancel) on top, reusing the cron services verbatim rather than duplicating any payment logic.

### Project Context Reference

Follow `_bmad-output/project-context.md` verbatim — especially: PHP 8.2 only; Blesta loader/Record/Language/Form/Acl
APIs (no ad-hoc SQL beyond allowlisted `Record`); keep all code inside `plugins/kuickpay_reconcile/`;
language-file-driven strings; do not edit ionCube/minified/vendored files; admin mutations require staff
auth + ACL + POST/CSRF + audit and never bypass parent controller setup; commit style `<type>(<scope>): <summary>`;
verify with `php -l` + component PHPUnit 8.5, never claim root `../tests`.

## Open Questions for Reviewer

These are deliberate decisions recorded for sign-off. None block dev-start; each has a chosen default the dev should
implement unless the reviewer overrides it here.

1. **Controller location (architecture variance).** The three manual actions live on the existing `admin_vouchers`
   controller, not a new `admin_manual_review.php` (architecture 792, 795–797 reserve the latter for the 4.4 review
   *queue*). Rationale in "Controller location decision" above. **Default: keep on `admin_vouchers`.** If the reviewer
   prefers a dedicated controller, the methods move wholesale with the same ACL/guard contract.
2. **Cancel of confirmed-origin evidence via `manual_review`.** A provider-confirmed voucher can reach `cancelled`
   through `manual_review` by two routes (Review, or recheck/post hitting the null-`date_paid` guard). The real
   exposure is *abandoning a confirmed payment* (invoice never marked paid), not row deletion — AC4's no-DELETE
   guarantee holds, and each step is human-gated, note-required, and audited. **Default: accept as the legitimate
   correction route for confirmed duplicates** (see "Cancel of confirmed-origin evidence via `manual_review`" in Dev
   Notes). Override only if AC4's intent is meant to be strict, in which case drop `confirmed_unposted` from
   `review()`'s `allowed_from` (note this does not close route (b)).
3. **Cancel note now required.** The UX source lists the admin note as required for *canceling* (not just Manual
   Review), so this story makes `cancel()` reject an empty note — stricter than the original "note optional" draft.
   **Default: required.** Flag here if Product wants cancel-note optional (would be an explicit variance from the UX).

## Dev Agent Record

### Agent Model Used

{{agent_model_name_version}}

### Debug Log References

### Completion Notes List

### File List
