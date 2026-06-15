# Blesta Framework Footguns — KuickPay Cumulative Developer Note

Date: 2026-06-16
Audience: **developers / future authors** working on the KuickPay gateway and
`kuickpay_reconcile` plugin. (Operators want `docs/kuickpay/deployment-guide.md` instead.)

This note is the long-overdue **consolidation** of every Blesta framework footgun surfaced
while building KuickPay across Epics 1–4. The action item to write it has been open since
**Epic 1 retro finding #7** and was re-raised every epic since. **This file is now the
canonical place to record any future Blesta footgun** — add to it rather than rediscovering
the same trap in the next story.

Primary cumulative citation: **`epic-4-retro-2026-06-13.md`, Action-Item-10** (the most
complete inventory). Per-item retro citations follow each entry.

This note contains framework/schema facts only — **no credentials, no secrets, no real
values**. Footguns are not sensitive data.

> Verification note (NFR12): the framework-behavior entries are transcribed from the epic
> retros that discovered them. The schema-shape entries (#9 VARCHAR amount, #13 items table has
> no `company_id`, #14 audit table not indexed on `run_id`) were **re-verified directly**
> against the plugin schema in `kuickpay_reconcile_plugin.php` at baseline `299e7638`.

---

## The 15 required footguns (gotcha → workaround → symbol → source)

1. **No nested transactions / no `forUpdate()` builder.**
   - *Gotcha:* Blesta `Record` has neither nested transactions nor a `forUpdate()` builder; a
     self-transacting `create()` inside an outer `begin()` commits early and drops the row
     lock you thought you were holding.
   - *Workaround:* hand-write a raw, bound `SELECT … FOR UPDATE`.
   - *Symbol:* `KuickPayPostingService` row lock on
     `status='confirmed_unposted' AND blesta_transaction_id IS NULL`.
   - *Source:* epic-3-retro, epic-4-retro.

2. **`Transactions->add()` returns `int|void`, and `addBefore` can veto.**
   - *Gotcha:* you get no guaranteed transaction id back, and a listener can veto the insert.
   - *Workaround:* verify the transaction independently before adopting it —
     `getByTransactionId()` must show it approved *and* already applied.
   - *Symbol:* `KuickPayPostingService` posting path.
   - *Source:* epic-3-retro, epic-4-retro.

3. **`outcomeStatus()` returns the CURRENT state on success**, not the post-transition state.
   - *Gotcha:* reading it as "the new status after a transition" gives you the old value.
   - *Workaround:* don't infer the new status from `outcomeStatus()`; read the row's state
     after the transition you actually performed.
   - *Source:* epic-3-retro.

4. **`PluginManager::upgrade()` permission-wipe + `>=` early-return.**
   - *Gotcha:* upgrade deletes the whole permission/action set and re-adds only what
     `getPermissions()`/`getActions()` currently return; and a `>= x.y` early-return in the
     upgrade method skips later migration steps on the live-upgrade path.
   - *Workaround:* **re-declare the full ACL set every upgrade**, and never early-return before
     later version steps run.
   - *Symbol:* `KuickpayReconcilePlugin::upgrade()` / `getActions()`. (Directly relevant to
     the Story 5.10 upgrade docs.)
   - *Source:* epic-3-retro, epic-4-retro.

5. **`FIELDS` allowlist silently drops un-listed columns.**
   - *Gotcha:* a newly added DB column won't surface through the model until it's added to the
     model's `FIELDS` allowlist — no error, just missing data.
   - *Workaround:* add the column to the model's `FIELDS`.
   - *Source:* epic-3-retro, epic-4-retro.

6. **`Record->fetch()` returns `stdClass`, not an array** (and `insert()` does not return the
   new id).
   - *Gotcha:* row access is object-style; code that array-indexes a fetched row breaks, and
     reading "the inserted id" off the result is empty.
   - *Workaround:* treat rows as objects; cast `(array)` only where a comparator needs both
     shapes; get the new id from `lastInsertId()` (see trap G).
   - *Source:* epic-2-retro, epic-3-retro, epic-4-retro.

7. **Models do not auto-load for plugin controllers.**
   - *Gotcha:* a plugin controller does not get its models loaded for free.
   - *Workaround:* explicitly load each model (`Loader::loadModels(...)`).
   - *Source:* epic-4-retro.

8. **Computed `clients.id_code` is a `REPLACE(...)` expression, not a stored column.**
   - *Gotcha:* you cannot filter or join on `id_code` as if it were a physical column.
   - *Workaround:* reproduce the `REPLACE(...)` expression in your query; don't treat it as a
     column.
   - *Source:* epic-4-retro.

9. **VARCHAR `amount` → lexicographic comparison** (`"9" > "100"`).
   - *Gotcha:* `kuickpay_vouchers.amount` / `kuickpay_voucher_invoices.amount` are
     **`VARCHAR(20)`** (verified in schema), so range/`>`/`<` comparisons compare *strings*,
     not numbers.
   - *Workaround:* cast/normalize to a numeric form before any range compare.
   - *Symbol:* `kuickpay_vouchers.amount`, `kuickpay_voucher_invoices.amount`.
   - *Related (distinct trap):* the Blesta money-column `decimal(12,4)` 4-dp-string trap —
     see memory `[[kuickpay-blesta-decimal4-amount-trap]]`.
   - *Source:* epic-4-retro.

10. **`Widget::setFilters()` is type-hinted `InputFields`, not array.**
    - *Gotcha:* passing a plain array raises a fatal `TypeError`.
    - *Workaround:* construct and pass an `InputFields` object.
    - *Source:* epic-4-retro.

11. **`Permissions::authorized()` short-circuits (grants) only when NO permission row exists.**
    - *Gotcha:* the implicit grant disappears as soon as any row exists; once a `'*'` wildcard
      row is present, specific actions fall through to **default-deny**.
    - *Workaround:* check the exact action; do not rely on a `'*'` wildcard to authorize
      specific actions (this was the 4-2 diagnostics ACL fix).
    - *Source:* epic-4-retro.

12. **Per-controller (and per-model) language-file auto-load scope.**
    - *Gotcha:* only the controller's own language file auto-loads; keys defined elsewhere are
      not visible. The same applies per-model for model-owned language files.
    - *Workaround:* put each key in the language file that auto-loads for that
      controller/model, or load the lang explicitly.
    - *Source:* epic-4-retro; epic-2-retro.

13. **`kuickpay_reconciliation_items` has no `company_id` column.**
    - *Gotcha:* the items table cannot be company-scoped directly — there is no `company_id`
      to filter on (verified in schema).
    - *Workaround:* two-layer guard — fetch the run via `getForCompany()` first, then have the
      item model re-JOIN to the run and filter server-side.
    - *Symbol:* `kuickpay_reconciliation_items`; see memory
      `[[kuickpay-admin-list-blesta-footguns]]`.
    - *Source:* epic-4-retro.

14. **`kuickpay_audit_events` is not indexed on `run_id`.**
    - *Gotcha:* run-scoped audit reads scan the table — the table indexes `company_id`,
      `voucher_id`, and `event_name`, but **not** `run_id` (verified in schema).
    - *Workaround:* add a `run_id` index (or knowingly accept the scan) for run-detail audit
      views.
    - *Symbol:* `kuickpay_audit_events`.
    - *Source:* epic-4-retro.

15. **DB-clock vs PHP-clock divergence.**
    - *Gotcha:* `getExpirable()` selects on the DB clock (`CURDATE()`) while
      `getReconcilable()` selects on the PHP clock (`date()`); with separate locks, a
      confirmed/paid row can be overwritten to `expired`.
    - *Workaround:* interim — status-guarded `expire()` (gate the UPDATE on the expected
      status and check affected rows). Durable — put both selectors on the same clock.
    - *Symbol:* `getExpirable()` / `getReconcilable()`; see memory
      `[[kuickpay-expiry-reconcile-clock-skew]]`.
    - *Source:* epic-3-retro, epic-4-retro.

---

## Additional recurring framework traps (A–L)

These recur across the retros. Traps **A** and **B** are the structural reason the gateway
cannot do "keep-if-blank" passwords (the password re-entry decision in
`deployment-guide.md`).

A. **Nonmerchant settings save runs on an id-less instance (null `gateway_id`).**
   - *Gotcha:* during `editSettings()` the gateway has no id yet — it cannot `log()` and
     cannot read its own previously-stored encrypted password to re-supply a blank one.
   - *Workaround:* require re-entry of secrets every save; blank required password fields fail
     validation before gateway meta is rewritten.
   - *Source:* epic-1-retro. (Underpins AC4.)

B. **`GatewayManager::setMeta` is delete-then-insert.**
   - *Gotcha:* all gateway meta is wiped and re-inserted, so a blank field overwrites the
     stored value if validation permits the save — there is no per-field "skip if blank"
     merge. In the shipped KuickPay gateway, password empty-rules reject blank required
     password fields before `setMeta()` runs.
   - *Workaround:* same as A — re-enter stored passwords on every save; do not promise
     keep-if-blank or blank-to-clear behavior.
   - *Source:* epic-1-retro, epic-2-retro. (Underpins AC4.)

C. **Checkbox render-default trap.**
   - *Gotcha:* the generic `fieldCheckbox` renders every box unchecked on first load, breaking
     toggles that are supposed to default `true`.
   - *Workaround:* render with an explicit `($meta[$field] ?? $default) === 'true'` default
     (as the KuickPay settings view does).
   - *Source:* epic-1-retro, epic-2-retro.

D. **Button-value sentinel brittleness.**
   - *Gotcha:* relying on a button's display label as its submitted value breaks when the
     label changes/localizes.
   - *Workaround:* use a stable sentinel value (e.g. `run_connection_test=true`).
   - *Source:* epic-1-retro.

E. **Class-name camelCase round-trip.**
   - *Gotcha:* Blesta asset/view/language resolution expects `Kuickpay`, **not** `KuickPay`;
     the wrong casing silently fails to resolve.
   - *Workaround:* keep the class `Kuickpay` and folder `kuickpay/`.
   - *Source:* epic-1-retro, epic-2-retro.

F. **`private $gateway_id` has a setter but no getter (reflection forbidden).**
   - *Gotcha:* you cannot read the base class's private gateway id, and reflecting into it is
     not allowed.
   - *Workaround:* shadow the id into your own `protected` member via the setter override
     (`setGatewayId()`).
   - *Source:* epic-2-retro, epic-3-retro.

G. **`Record->insert()` does not return the new id.**
   - *Gotcha:* the insert call gives you no id back.
   - *Workaround:* call `lastInsertId()` immediately after.
   - *Source:* epic-2-retro, epic-3-retro.

H. **`buildProcess()` sets errors but does not early-return.**
   - *Gotcha:* setting input errors does not stop execution; the method keeps running.
   - *Workaround:* gate the rest on `if (!$this->Input->errors())` before mutating anything.
   - *Source:* epic-2-retro.

I. **Views auto-resolve as `<controller_snake>_<action>.pdt`.**
   - *Gotcha:* a mismatched view filename silently fails to render.
   - *Workaround:* name the view to match the controller-snake + action convention.
   - *Source:* epic-4-retro.

J. **Gateway config null in `persistEvidence()` in production.**
   - *Gotcha:* the gateway config is null unless it was set on the run path first — works in
     tests, null in production.
   - *Workaround:* resolve and thread the gateway config through the run path before
     `persistEvidence()` needs it.
   - *Source:* epic-3-retro, epic-4-retro.

K. **`redactedDiagnosticText()` / `redactEnvelope()` blank values but keep structural tags.**
   - *Gotcha:* the redacted envelope still contains structural SOAP tags that the leak scan
     forbids — building a log/audit value from a redacted envelope string still trips the
     scan.
   - *Workaround:* build log/audit values from safe tokens (status, error class, hashes),
     never from a redacted envelope string.
   - *Symbol:* see memory `[[kuickpay-soapclient-rawresult-unredacted]]`.
   - *Source:* epic-4-retro.

L. **`PluginManager::isInstalled(..., null company_id)` matches under ANY company.**
   - *Gotcha:* with a null company id (CLI/cron/early bootstrap) the check matches an install
     under any company, not the current one (`app/models/plugin_manager.php:214`).
   - *Workaround:* in such paths, enforce company-scoped + enabled explicitly. (The gateway's
     `companionInstalled()` passes `Blesta.company_id`, which is correct on the web path.)
   - *Source:* deferred-work.md, epic-1-retro.

---

## How to extend this note

When a new Blesta framework trap is discovered, **add it here** with the same shape — gotcha →
workaround → named symbol/file → source retro — and reference it from the retro that found it.
This consolidation exists so the next author does not pay the same tax a sixth time.
