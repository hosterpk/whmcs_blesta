---
baseline_commit: 299e7638aaeb0474751156632aa3b24459017e2e
---

<!-- Powered by BMAD-CORE™ -->

# Story 5.8: Document Deployment and Configuration

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator,
I want install and configuration documentation,
so that KuickPay can be deployed without guessing extension paths or settings.

## Acceptance Criteria

> Sourced from `epics.md` Story 5.8 (lines 1025–1050) and the doc-location rule
> `epics.md:148`. **Adds (Epic 1→4 retro AI):** the cumulative Blesta-footgun dev note
> (4 epics overdue — Epic 1 #7 → Epic 4 AI-8/AI-10, never written) + the credential
> keep-if-blank decision (Epic 1 #5, carried). **Honors:** NFR8 (`epics.md:101` — no secret
> exposure), NFR10 (`epics.md:105` — no hard-coded endpoints/credentials/Institution IDs),
> NFR12 (`epics.md:109` — honest reporting). Architecture: `docs/kuickpay/` doc set
> (`architecture.md:754-763`), deploy-as-extension-files + rollback model (`architecture.md:441-475`),
> ownership boundaries (`architecture.md:518-526,663-782`).

**This is a DOCUMENTATION-ONLY story.** No production code, no test code, no schema, no
version bump, no settings behavior change. The deliverables are Markdown files under
`docs/kuickpay/`. The "implementation" is writing accurate operator + developer docs that
match the **already-shipped** gateway/plugin behavior. Do not "fix" any code you document; if
you find a real defect, note it as a finding — do not change it in this story.

1. **(AC1 — the deployment / configuration guide)**
   **Given** the deployment guide is opened,
   **When** an operator follows it,
   **Then** it explains: gateway and plugin **file locations**, **install order**, **dependency
   checks** (companion-plugin requirement), **PKR enablement**, **credential entry**,
   **Institution ID**, **endpoint configuration** (`wsdl_url` + `wsdl_allowed_hosts`),
   **timeouts** (`soap_timeout`), **instruction groups**, and **safe connection testing** — all
   matching the real field keys/labels and behavior in the shipped gateway (see Dev Notes
   "Configuration field reference" and "Deployment facts").

2. **(AC2 — placeholders only; zero secret/environment leakage — NFR8/NFR10)**
   **Given** credentials or environment-specific values are discussed,
   **When** documentation provides examples,
   **Then** it uses **placeholders only** (e.g. `<institution-id>`, `<operator-provided-wsdl-url>`)
   **And** it does **not** copy any value from `config/blesta.php`, logs, cache, `.env`, or
   production settings — no real WSDL host, no Institution ID, no username/password, no DB
   credential, no customer PII anywhere in any doc.

3. **(AC3 — cumulative Blesta-framework footgun developer note)**
   **Given** the developer footgun note under `docs/kuickpay/`,
   **When** a future author reads it,
   **Then** it captures **every** Blesta framework footgun surfaced across Epics 1–4 (the full
   enumerated list in Dev Notes "Footgun source-map") so later authors stop rediscovering them:
   no nested transactions / no `forUpdate()` builder; `Transactions->add()` `int|void` +
   `addBefore` veto; `outcomeStatus()` returns current state; `upgrade()` permission-wipe and
   `>=` early-return; `FIELDS` allowlist drops columns; `Record->fetch()`→`stdClass`; models
   don't auto-load for plugin controllers; computed `clients.id_code`; VARCHAR-amount
   lexicographic compare; `Widget::setFilters(InputFields)` type contract; `Permissions::authorized()`
   short-circuit semantics; per-controller language-file auto-load scope; `items` table without
   `company_id`; audit table not indexed on `run_id`; DB-clock vs PHP-clock divergence.
   Each entry must state the **gotcha + the correct workaround** and cite its source retro.

4. **(AC4 — credential keep-if-blank vs password re-entry decision, recorded explicitly)**
   **Given** the credential keep-if-blank behavior,
   **When** the deploy/config docs are written,
   **Then** the **keep-if-blank vs password re-entry** decision (Epic 1 #5, carried) is recorded
   **explicitly** so operators understand re-save behavior: the shipped behavior is
   **re-entry required** — password fields are write-only, blank required password fields fail
   validation before gateway meta is rewritten, and operators **must re-enter both passwords
   every time they save settings**, exactly as the gateway's own field notes state
   (`Kuickpay.voucher_password_stored`, `Kuickpay.inquiry_password_stored`). Blesta nonmerchant
   `setMeta` is delete+insert and `editSettings()` runs on an id-less instance, so true
   keep-if-blank remains structurally impossible.

## Tasks / Subtasks

- [x] **Task 1 — AC1/AC2/AC4: write the deployment + configuration guide** (`docs/kuickpay/deployment-guide.md`)
  - [x] 1.1 **File locations & ownership.** Document the two installable trees and their roles
    (gateway = checkout/customer-reference display + settings UI + PKR eligibility; plugin =
    durable voucher state, reconciliation, posting, cron, schema, admin workbench). Source the
    tree from `architecture.md:675-763`; the gateway is
    `components/gateways/nonmerchant/kuickpay/` (class `Kuickpay`, note the **camelCase
    round-trip** — `Kuickpay`, not `KuickPay`) and the plugin is `plugins/kuickpay_reconcile/`
    (class `KuickpayReconcilePlugin`).
  - [x] 1.2 **Install order + dependency check.** State the order explicitly: **install &
    enable the `kuickpay_reconcile` plugin FIRST, then install/enable the gateway.** The gateway
    hard-checks the companion via `companionInstalled()` →
    `PluginManager->isInstalled('kuickpay_reconcile', company_id)` and refuses to operate
    (settings error `Kuickpay.!error.companion_missing`; payment-processing guard) when the
    plugin is missing. Note the plugin `install()` creates the schema (voucher / voucher-invoice
    / reconciliation-run / reconciliation-item / audit / lock tables) and registers the three
    cron tasks. Note the gateway's `lib/*` SOAP/parser/redactor/evidence classes are **shared**:
    the plugin's reconciliation/posting cron loads them, so the gateway tree must be present for
    the plugin cron to run.
  - [x] 1.3 **PKR enablement.** Document that the gateway is **PKR-only** by `config.json`
    `"currencies": ["PKR"]` (and the fixed `currency_policy = pkr_only` setting); a company must
    have PKR configured/active for the gateway to be selectable, and non-PKR invoices are refused
    at runtime (`currencyEligible()`). Cross-reference Story 1.5.
  - [x] 1.4 **Credential entry + AC4 decision.** Document the four credential fields
    (`voucher_username`, `voucher_password`, `inquiry_username`, `inquiry_password`) and the
    `inquiry_same_as_voucher` toggle (when on, inquiry creds reuse voucher creds). State that
    only the two **password** fields are encrypted (`encryptableFields()` =
    `['voucher_password','inquiry_password']`; usernames stored plaintext). **Record the password
    re-entry decision (AC4) prominently:** passwords are write-only in the UI (shown as a masked
    "stored" marker, never echoed), blank required passwords fail validation before meta rewrite,
    and **operators must re-enter BOTH passwords on every settings save.** Use placeholders only.
  - [x] 1.5 **Institution ID + endpoint configuration.** Document `institution_id` (merchant
    identifier assigned by KuickPay — placeholder only, never a real value), `wsdl_url`
    (HTTPS-only, no embedded userinfo, SSRF-guarded at save: private/loopback/reserved hosts
    rejected), and `wsdl_allowed_hosts` (optional operator allowlist; when set the WSDL host must
    match exactly; empty = any public HTTPS host that passes the safety checks). Explain that no
    endpoint/host literal is committed anywhere — the operator supplies the confirmed production
    WSDL at deploy time (`phase-0-contract.md`, NFR10).
  - [x] 1.6 **Timeouts + instruction groups + other settings.** Document `soap_timeout`
    (1–300s, default 30), the four instruction-group checkboxes
    (`instruction_online_banking`/`instruction_bank_deposit` default ON,
    `instruction_agent_franchise`/`instruction_mobile_app` default OFF), `due_date_offset_days` /
    `expiry_date_offset_days` (0–365, expiry **≥** due), the reference-pattern templates, the
    fallback/branch fields, and the fixed policy fields (fee `none`; amount-change/multi-invoice
    `block`; under/over/late `manual_review`), `logging_enabled`, `reconciliation_enabled`. Use
    the "Configuration field reference" table in Dev Notes as the source of truth.
  - [x] 1.7 **Safe connection testing.** Document the **Test Connection** action
    (`run_connection_test` button in settings): it fetches **only** the configured WSDL over HTTPS
    to prove reachability — it sends **no credentials**, creates **no voucher**, posts **no
    payment**. Explain the reachable / unreachable / timeout / unavailable (curl-missing) outcomes
    and that it does NOT validate credentials (the credentialed real-provider check is the opt-in
    live smoke — link `docs/kuickpay/live-smoke-runbook.md`, Story 5.7).
  - [x] 1.8 **Apply the placeholders-only safety rule (AC2) throughout** and add the standard
    sanitized-doc header (mirror the existing verification docs) stating the file contains no
    `config/blesta.php`/DB/KuickPay credentials, no Institution ID, no WSDL host, no PII.

- [x] **Task 2 — AC3: write the cumulative Blesta-framework footgun developer note** (`docs/kuickpay/blesta-footguns.md`)
  - [x] 2.1 Transcribe **all 15 required footguns** (AC3 list) plus the additional recurring ones
    in Dev Notes "Footgun source-map (additional)". For each: a one-line **gotcha**, the
    **correct workaround**, the **named symbol/file**, and the **source retro** citation.
  - [x] 2.2 Lead with the single most complete cumulative inventory citation:
    `epic-4-retro-2026-06-13.md` Action-Item-10 (the consolidated list), then per-item retro
    citations. State at the top that this note is the long-overdue consolidation (open since Epic
    1 #7) and is the canonical place to add any future Blesta footgun.
  - [x] 2.3 Keep it a **developer** reference (distinct audience from the operator deployment
    guide). No secrets, no real values — footguns are framework/schema facts, not credentials.

- [x] **Task 3 — Verification (NFR8/NFR12)**
  - [x] 3.1 Cross-check **every** setting key, default, range, and label paraphrase in the docs
    against the real source: gateway `language/en_us/kuickpay.php` (labels + notes),
    `kuickpay.php` (`editSettings()`/`getSettings()`/`encryptableFields()`/`runConnectionTest()`),
    `config.json` (version + currencies). Do not invent a field that isn't in the code; do not
    omit one the AC names.
  - [x] 3.2 **Secret-leak self-scan** of every new/changed doc (mirror Story 5.7 discipline):
    grep the docs for credential/host/Institution-ID/PII shapes and confirm only placeholders
    appear — no `config/blesta.php` value, no real WSDL host, no username/password/CNIC/mobile/email.
  - [x] 3.3 Confirm footgun coverage: tick off all 15 AC-named footguns are present in
    `blesta-footguns.md` with workaround + source.
  - [x] 3.4 Honest reporting (NFR12): if any documented behavior could not be confirmed against
    the code, say so explicitly rather than asserting it.

- [x] **Task 4 — Doc hygiene & commit**
  - [x] 4.1 Keep this a **docs-only** change set under `docs/kuickpay/` + the `_bmad-output/`
    story file. No runtime/test files touched (project-context.md:104 — don't mix generated docs
    with runtime changes). Commit style `docs(kuickpay): <summary>`, imperative, ≤72 chars (e.g.
    `docs(kuickpay): add deployment guide and configuration reference`,
    `docs(kuickpay): add cumulative blesta footgun note`).
  - [x] 4.2 Cross-link the new docs into the existing `docs/kuickpay/` set (and, if present, an
    index) so they're discoverable beside the verification records and the live-smoke runbook.

### Review Findings

- [x] [Review][Patch] Remove concrete endpoint host literal from docs and story artifact
  [`docs/kuickpay/deployment-guide.md`] — AC2/NFR10 placeholders-only violation; fixed by
  replacing the literal with placeholder-only wording and updating the story notes/self-scan.
- [x] [Review][Patch] Sync sprint-status generated header with YAML metadata
  [`_bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml`] — fixed header
  `last_updated` so comments and YAML agree.
- [x] [Review][Patch] Correct `reconciliation_enabled` scope
  [`docs/kuickpay/deployment-guide.md`] — fixed wording to say the setting gates
  inquiry/reconciliation runs, not `post_confirmed` or `expire_vouchers`.
- [x] [Review][Patch] Correct blank-password save behavior
  [`docs/kuickpay/deployment-guide.md`] — fixed guide, footgun note, and story record to reflect
  source truth: blank required passwords fail validation before `GatewayManager::setMeta()` can
  rewrite gateway meta.

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **Docs only.** Touch nothing under `components/` or `plugins/`. You are describing behavior
  that already shipped and was code-reviewed across Epics 1–5. If a doc and the code disagree,
  the **code is truth** — fix the doc, and flag the discrepancy in the Dev Agent Record; do not
  change code in this story.
- **Placeholders only (AC2/NFR8/NFR10).** Never copy a real value from `config/blesta.php`,
  logs, cache, `.env`, or production settings. No real WSDL host, Institution ID, username,
  password, DB credential, customer PII, or concrete endpoint host literal in any doc. Prefer
  `<operator-provided-wsdl-url>` for every endpoint example.
- **No `KuickPay` vs `Kuickpay` slip.** The gateway class is `Kuickpay` (camelCase round-trip);
  Blesta asset/view resolution depends on this exact casing (footgun E). Get it right in the docs.
- **The credential re-save rule is re-entry required, not keep-if-blank** (AC4). Do not describe
  a "leave blank to keep existing password" behavior — that is exactly what Blesta nonmerchant
  gateways CANNOT do here. The gateway's own field notes already say "Re-enter it to save any
  settings change"; the docs must match that. Also do not claim blank fields wipe credentials:
  the shipped validation rejects blank required passwords before `GatewayManager::setMeta()` runs.

### Deployment facts (verified against source at baseline `299e7638`)

| Fact | Value | Source |
|---|---|---|
| Gateway tree | `components/gateways/nonmerchant/kuickpay/` | `architecture.md:678-694`; dir confirmed |
| Gateway class | `Kuickpay` (camelCase round-trip) | `kuickpay.php:11` |
| Gateway version / currency | `config.json` `version 1.0.0`, `currencies: ["PKR"]` | `config.json:2,11-12` |
| Plugin tree | `plugins/kuickpay_reconcile/` | `architecture.md:696-752` |
| Plugin class / version | `KuickpayReconcilePlugin`, `config.json` `1.10.0` | `kuickpay_reconcile_plugin.php:11`; plugin `config.json:2` |
| **Install order** | **plugin FIRST, then gateway** (gateway refuses without companion) | `companionInstalled()` `kuickpay.php:101,1045,1912-1917` |
| Companion check | `PluginManager->isInstalled('kuickpay_reconcile', Blesta.company_id)` | `kuickpay.php:1916` |
| Shared libs (gateway→plugin) | `KuickPaySoapClient`/`KuickPayResponseParser`/`KuickPayEvidence`/`KuickPayRedactor` loaded by plugin cron | `architecture.md:770-778`; plugin `tests/bootstrap.php` |
| Plugin schema owner | `install()` creates voucher/voucher-invoice/reconciliation/audit/lock tables + cron | `architecture.md:449,669`; `kuickpay_reconcile_plugin.php install()` |
| Cron tasks | `reconcile_pending` (5m), `post_confirmed` (5m), `expire_vouchers` (60m) | `kuickpay_reconcile_plugin.php getCronTasks()/cron()` |
| Encrypted fields | `encryptableFields()` = `['voucher_password','inquiry_password']` (usernames plaintext) | `kuickpay.php:615-618` |
| Connection test | `runConnectionTest()` — HTTPS reachability only, no creds, no voucher, no post | `kuickpay.php runConnectionTest()`; button `settings.pdt run_connection_test` |
| Endpoint hardening | save-time SSRF/userinfo/HTTPS guard + optional `wsdl_allowed_hosts`; cron-side `hasUsableWsdlUrl()` keeps userinfo/https only | Story 5.6 verification doc; `KuickPaySoapClient::hasUsableWsdlUrl()` |

**Rollback/ownership context for the guide (do not deep-doc rollback — that's Story 5.10):** the
gateway can be disabled independently of the plugin cron; voucher/audit/payment-evidence tables
are preserved on uninstall (`architecture.md:470-475,199-209`). Mention the separation; leave the
full rollback runbook to 5.10.

### Configuration field reference (source of truth for the config doc — verified labels)

All keys live in gateway `editSettings()`/`getSettings()`; labels/notes in
`language/en_us/kuickpay.php`. **Document with placeholders only.**

| Setting key | Label (lang) | Notes for the doc |
|---|---|---|
| `wsdl_url` | WSDL URL | HTTPS only, no embedded userinfo, SSRF-guarded (private/loopback/reserved rejected). Operator-supplied; **no host literal in repo**. |
| `wsdl_allowed_hosts` | Allowed WSDL hosts | Optional comma/newline list; when set, WSDL host must match exactly; empty = any safe public HTTPS host. |
| `voucher_username` | Voucher username | Plaintext. Used for `InsertVoucher`. |
| `voucher_password` | Voucher password | **Encrypted; write-only; re-entry required** — blank values fail validation; re-enter on every save (AC4). |
| `inquiry_same_as_voucher` | Use voucher credentials for inquiries | When ON, inquiry reuses voucher creds (inquiry_* unset). |
| `inquiry_username` | Inquiry username | Plaintext; required when separate inquiry creds used. |
| `inquiry_password` | Inquiry password | **Encrypted; write-only; re-entry required** when separate inquiry credentials are used (AC4). |
| `institution_id` | Institution ID | Merchant identifier from KuickPay. **Placeholder only.** |
| `registration_number_pattern` | (Registration Number template) | Tokens: `{random_prefix}`,`{invoice_id}`. |
| `consumer_number_pattern` | (Consumer Number template) | Tokens: `{institution_id}`,`{registration_number}`,`{random_prefix}`,`{invoice_id}`. |
| `payment_head_label` | (Payment head label) | Optional. |
| `due_date_offset_days` | Due date offset days | 0–365, optional. |
| `expiry_date_offset_days` | Expiry date offset days | 0–365; must be **≥** `due_date_offset_days`. |
| `fallback_mobile` / `fallback_email` / `default_branch` | (fallbacks/branch) | Optional. |
| `currency_policy` | (currency policy) | Fixed `pkr_only`. |
| `fee_policy` | (fee policy) | Fixed `none`. |
| `amount_change_policy` / `multi_invoice_policy` | (policies) | Fixed `block` (MVP). |
| `underpayment_policy` / `overpayment_policy` / `late_payment_policy` | (policies) | Fixed `manual_review`. |
| `instruction_online_banking` | Online banking instructions | Default **ON**. |
| `instruction_bank_deposit` | Bank deposit instructions | Default **ON**. |
| `instruction_agent_franchise` | Agent or franchise instructions | Default **OFF**. |
| `instruction_mobile_app` | Mobile app instructions | Default **OFF**. |
| `logging_enabled` | (logging) | Default ON. |
| `reconciliation_enabled` | (reconciliation) | Default ON; gates inquiry/reconciliation runs (`reconcile_pending`), not `post_confirmed` or `expire_vouchers`. |
| `soap_timeout` | SOAP timeout seconds | 1–300, default **30**. |
| `run_connection_test` | (Test Connection button) | Transient; triggers `runConnectionTest()`; unset before save. |

The gateway's own field notes already document the password re-entry behavior — quote/paraphrase
them rather than inventing wording:
- `voucher_password_note`: "Password used when creating KuickPay vouchers. **Re-enter it when saving settings.**"
- `voucher_password_stored`: "The voucher password is hidden for security. **Re-enter it to save any settings change.**"
- (`inquiry_password_*` mirror these.)

### AC4 — the credential keep-if-blank decision, explained (record this verbatim-ish in the guide)

**Decision: password re-entry required (keep-if-blank is NOT implemented and cannot be).** Why, so
the doc states it authoritatively:
- Blesta `GatewayManager::setMeta()` is **delete-then-insert** of all gateway meta, so a blank
  field would overwrite the stored value if validation permitted the save — there is no per-field
  "skip if blank" merge (footgun B).
- Nonmerchant `editSettings()` runs on an **id-less instance** (`gateway_id` is null at save), so
  the gateway cannot read its own previously-stored encrypted password to re-supply it when blank
  (footgun A). Reflection on the private `$gateway_id` is forbidden (footgun F).
- Net effect in the shipped gateway: password fields are write-only and **blank required
  passwords fail validation** (`voucher_password` always; `inquiry_password` when separate inquiry
  credentials are used), so `GatewayManager::setMeta()` is not called and existing meta is left
  untouched.
- **Operator rule to document:** treat the password fields as write-only; **always re-enter both
  passwords when changing any setting**, or the save will be rejected. This was Epic 1 finding #5,
  carried through to be recorded here, with the final shipped validation behavior verified during
  review.

### Footgun source-map (AC3) — the 15 required, with workaround + citation

Primary cumulative citation: `epic-4-retro-2026-06-13.md` Action-Item-10 (most complete inventory).
Render each as: **gotcha → workaround → symbol → source**.

1. **No nested txns / no `forUpdate()` builder.** Blesta `Record` has neither; a self-transacting
   `create()` inside an outer `begin()` commits early and drops the row lock. → Hand-write a raw
   bound `SELECT … FOR UPDATE`. Symbol: `KuickPayPostingService` lock on
   `status='confirmed_unposted' AND blesta_transaction_id IS NULL`. *(epic-3-retro, epic-4-retro)*
2. **`Transactions->add()` returns `int|void` + `addBefore` veto.** No guaranteed id; a listener
   can veto the insert. → Verify the transaction independently before adopting
   (`getByTransactionId()` demands approved + already-applied). *(epic-3-retro, epic-4-retro)*
3. **`outcomeStatus()` returns CURRENT state on success**, not the post-transition state. → Don't
   read it as the new status after a transition. *(epic-3-retro)*
4. **`upgrade()` permission-wipe + `>=` early-return.** `PluginManager::upgrade()` deletes the
   whole permission/action set and re-adds only what `getPermissions()`/`getActions()` return; a
   `>= x.y` early-return skips later migrations on the live-upgrade path. → Re-declare the full
   ACL set every upgrade; never early-return before later version steps. *(epic-3-retro,
   epic-4-retro; relevant to Story 5.10 upgrade docs)*
5. **`FIELDS` allowlist silently drops un-listed columns.** A new column won't surface until added
   to the model's `FIELDS`. → Add the column to `FIELDS`. *(epic-3-retro, epic-4-retro)*
6. **`Record->fetch()` returns `stdClass`, not array** (and `insert()` doesn't return the new id —
   use `lastInsertId()`). → Treat rows as objects; cast `(array)` where a comparator needs both.
   *(epic-2-retro, epic-3-retro, epic-4-retro)*
7. **Models don't auto-load for plugin controllers.** → Explicitly load each model. *(epic-4-retro)*
8. **Computed `clients.id_code`** is a `REPLACE(...)` expression, not a stored column. → Reproduce
   the expression; don't filter/join on it as a column. *(epic-4-retro)*
9. **VARCHAR `amount` → lexicographic compare** (`"9" > "100"`). → Cast/normalize before range
   compare. (Distinct from the decimal(12,4) 4dp-string trap, `[[kuickpay-blesta-decimal4-amount-trap]]`.)
   *(epic-4-retro)*
10. **`Widget::setFilters()` is type-hinted `InputFields`, not array** — array → fatal `TypeError`.
    → Pass an `InputFields` object. *(epic-4-retro)*
11. **`Permissions::authorized()` short-circuits (grants) only when NO permission row exists**;
    once a `'*'` wildcard row exists, specific actions fall through to default-deny. → Check the
    exact action; don't rely on `'*'` to authorize specifics (the 4-2 diagnostics ACL fix).
    *(epic-4-retro)*
12. **Per-controller language-file auto-load scope** — only the controller's own lang file loads;
    keys elsewhere aren't visible (model variant: per-model lang files). → Put keys in the
    auto-loaded file for that controller/model. *(epic-4-retro; epic-2-retro)*
13. **`kuickpay_reconciliation_items` has no `company_id`** — can't be company-scoped directly. →
    Two-layer guard: fetch the run via `getForCompany()` first, then the model re-JOINs + filters
    server-side. *(epic-4-retro)*
14. **`kuickpay_audit_events` not indexed on `run_id`** — run-scoped reads scan. → Add an index (or
    accept the scan) for run-detail audit views. *(epic-4-retro)*
15. **DB-clock vs PHP-clock divergence.** `getExpirable()` uses DB `CURDATE()`, `getReconcilable()`
    uses PHP `date()`; with separate locks a confirmed/paid row can be overwritten to `expired`. →
    Interim: status-guarded `expire()`. Durable: both selectors on the same clock.
    (`[[kuickpay-expiry-reconcile-clock-skew]]`) *(epic-3-retro, epic-4-retro)*

### Footgun source-map (additional — include these too; they recur across the retros)

A. **Nonmerchant settings save runs on an id-less instance (null `gateway_id`)** — can't `log()`
   during save, can't keep-if-blank. *(epic-1-retro)* — underpins AC4.
B. **`GatewayManager::setMeta` is delete+insert** — no keep-if-blank for stored passwords; blank
   would overwrite if validation permitted it, but shipped KuickPay password rules reject blank
   required passwords before meta rewrite. *(epic-1-retro, epic-2-retro)* — underpins AC4.
C. **Checkbox render-default trap** — generic `fieldCheckbox` renders every box unchecked on first
   load, breaking `true`-default toggles. *(epic-1-retro, epic-2-retro)*
D. **Button-value sentinel brittleness** — don't rely on a button's display label as its value;
   use a stable sentinel. *(epic-1-retro)*
E. **Class-name camelCase round-trip** — asset/view resolution expects `Kuickpay`, not `KuickPay`.
   *(epic-1-retro, epic-2-retro)*
F. **`private $gateway_id` with setter but no getter** (reflection forbidden) — shadow into a
   `protected` member. *(epic-2-retro, epic-3-retro)*
G. **`Record->insert()` does not return the new id** — call `lastInsertId()`. *(epic-2-retro,
   epic-3-retro)*
H. **`buildProcess()` sets errors but does not early-return** — gate on `if (!$this->Input->errors())`.
   *(epic-2-retro)*
I. **Views auto-resolve as `<controller_snake>_<action>.pdt`** — a mismatched filename silently
   fails to render. *(epic-4-retro)*
J. **Gateway config null in `persistEvidence()` in production** unless set on the run path first.
   *(epic-3-retro, epic-4-retro)*
K. **`redactedDiagnosticText()`/`redactEnvelope()` blank values but keep structural tags** the leak
   scan forbids — build log/audit values from safe tokens, never from a redacted envelope string.
   (`[[kuickpay-soapclient-rawresult-unredacted]]`) *(epic-4-retro)*
L. **`PluginManager::isInstalled(..., null company_id)` matches under ANY company** (CLI/cron/early
   bootstrap, `app/models/plugin_manager.php:214`) — enforce company-scoped + enabled in such
   paths. *(deferred-work.md, epic-1-retro)*

> The note may keep the 15 AC-required items as the headline list and fold A–L in as "additional
> framework traps"; both sets cite their retro. The dev judges whether to merge or section them —
> completeness over format.

### Doc conventions to mirror (existing `docs/kuickpay/` set)

- **Sanitized header** at the top of each doc, mirroring
  `gateway-settings-and-endpoint-hardening-verification.md:1-8` and `live-smoke-runbook.md` —
  state the file contains no `config/blesta.php`/DB/KuickPay credentials, no Institution ID, no
  WSDL host, no PII; and (for verification-style docs) what was confirmed vs assumed (NFR12).
- **Placeholder style:** `<operator-provided-wsdl-url>`, `<institution-id>`,
  `<operator-provided-username>` — exactly the style already used in `live-smoke-runbook.md:47-51`.
- **Audience split:** `deployment-guide.md` is for **operators** (install/configure/test);
  `blesta-footguns.md` is for **developers/future authors**. Keep them separate files.
- **Markdown** only, in `docs/kuickpay/`. Tables for the field reference and the footgun list are
  the most scannable form.

### Where this story sits in the doc plan (don't overreach)

`epics.md:148` + `architecture.md:754-763` plan a `docs/kuickpay/` set across Stories 5.8–5.10:
- **5.8 (this story):** deployment + configuration (+ footgun note + credential decision). →
  `deployment-guide.md` (install/config) and `blesta-footguns.md`.
- **5.9:** reconciliation runbook + admin-review runbook + support-troubleshooting. **Out of scope here.**
- **5.10:** rollback + upgrade + production-launch checklist. **Out of scope here.**

If you find a clean home in the architecture's planned filenames
(`implementation-boundaries.md`/`deployment-checklist.md`/`operator-runbook.md`), you may instead
split the AC1 content across those — but a single `deployment-guide.md` satisfies "the deployment
guide" AC literally and is less ambiguous. Either way: **do not** start the 5.9/5.10 runbooks here,
and **don't** deep-document rollback/upgrade (just reference the separation of gateway-disable vs
plugin-cron-disable). Footgun #4 (upgrade permission-wipe) is named here for the note but the
upgrade *runbook* is 5.10.

### Previous Story Intelligence (5.7 — done; 5.6, 5.1)

- **5.7** shipped `docs/kuickpay/live-smoke-runbook.md` + `live-smoke-verification.md` with the
  exact sanitized-doc + placeholder discipline this story reuses, and is the **credentialed**
  connection check (the gateway Test Connection here is reachability-only). Link to it from the
  "safe connection testing" section. 5.7's review hammered AC2-style leakage (a fault leaked the
  WSDL host) — apply the same paranoia to these docs.
- **5.6** is the authoritative source for the endpoint-hardening prose: save-time SSRF/userinfo
  chokepoint + `wsdl_allowed_hosts` allowlist; cron-side `hasUsableWsdlUrl()` keeps only
  userinfo/https by design (`[[kuickpay-wsdl-ssrf-save-chokepoint]]`) — describe it that way; don't
  imply the cron re-runs the private-range guard.
- **5.1** established the "ship the mechanism/doc; operator runs it live" honesty pattern; the
  deployment guide should likewise be operator-actionable without asserting a run that didn't happen.
- **Runtime reality** (`[[kuickpay-php82-toolchain-now-available]]`): production is **PHP 8.3
  (ea-php83)**; "8.2" is a source-floor. If the guide mentions runtime, say 8.3 production / 8.2
  source-floor — don't claim an 8.2 runtime.

### Git Intelligence

- Baseline HEAD: `299e7638` `docs(kuickpay): close 5.7 as done…`. Epic 5 docs land via
  `docs(kuickpay): …` commits (e.g. `75978c6a` live-smoke runbook) kept separate from
  runtime/test commits — follow that for this docs-only story.

### Project Structure Notes

- New files (docs only): `docs/kuickpay/deployment-guide.md`, `docs/kuickpay/blesta-footguns.md`
  (+ the `_bmad-output/` story file). No `components/`, `plugins/`, `tests/`, schema, or
  `config.json` changes. No version bump.
- Aligns with `epics.md:148` ("documentation should live under `docs/kuickpay/`") and the
  architecture's planned doc set (`architecture.md:754-763`).
- No conflict with the existing `docs/kuickpay/` verification docs — these are additive operator +
  developer references. Optionally update an index if one exists.

### References

- [Source: epics.md#Story-5.8 (1025–1050)] — the four ACs (deployment guide content; placeholders
  only; cumulative footgun note; credential keep-if-blank decision).
- [Source: epics.md:148] — deployment/support docs live under `docs/kuickpay/`.
- [Source: epics.md:843-846,1052-1086] — Epic 5 terminal-epic framing; 5.9/5.10 scope (what NOT to
  write here).
- [Source: architecture.md:441-475] — deploy-as-extension-files, plugin owns schema/cron, rollback
  model (gateway-disable vs plugin-cron-disable; preserve evidence).
- [Source: architecture.md:518-526,663-782] — ownership boundaries + complete directory tree +
  the planned `docs/kuickpay/` doc set.
- [Source: components/gateways/nonmerchant/kuickpay/kuickpay.php:11,101,615-618,1045,1912-1917] —
  class `Kuickpay`; `companionInstalled()`; `encryptableFields()`; install-order guard.
- [Source: components/gateways/nonmerchant/kuickpay/config.json:2,11-12] — version `1.0.0`,
  `currencies: ["PKR"]`.
- [Source: components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php:44-134] — all
  settings labels/notes + the `*_password_stored` password re-entry wording.
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php:11] +
  [Source: plugins/kuickpay_reconcile/config.json:2] — `KuickpayReconcilePlugin`, version `1.10.0`;
  `install()`/`upgrade()`/`uninstall()`/`cron()`/`getCronTasks()`.
- [Source: docs/kuickpay/gateway-settings-and-endpoint-hardening-verification.md] — Story 5.6
  endpoint-hardening prose to reuse; sanitized-header pattern.
- [Source: docs/kuickpay/live-smoke-runbook.md] — sanitized + placeholder doc discipline; the
  credentialed connection check to link from "safe connection testing".
- [Source: docs/kuickpay/phase-0-contract.md] — no committed endpoints/credentials/Institution IDs;
  example host is example-only.
- [Source: _bmad-output/kuickpay/implementation-artifacts/epic-4-retro-2026-06-13.md (AI-10)] —
  most complete cumulative footgun inventory (primary AC3 citation).
- [Source: epic-1-retro-2026-06-10.md (#5,#7), epic-2-retro-2026-06-10.md, epic-3-retro-2026-06-11.md] —
  per-footgun sources; credential keep-if-blank decision (Epic 1 #5).
- [Source: project-context.md:33,104,109,112,125] — secrets handling; doc-commit separation; no
  secrets in docs.
- Memory: `[[kuickpay-wsdl-ssrf-save-chokepoint]]`, `[[kuickpay-soapclient-rawresult-unredacted]]`,
  `[[kuickpay-expiry-reconcile-clock-skew]]`, `[[kuickpay-blesta-decimal4-amount-trap]]`,
  `[[kuickpay-php82-toolchain-now-available]]`, `[[kuickpay-admin-list-blesta-footguns]]`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context) — BMAD Dev Story workflow.

### Debug Log References

- Workflow block resolved via `_bmad/scripts/resolve_customization.py` (Python 3.11 from
  `/root/anaconda3/bin/python3`, per `tomllib` requirement). `activation_steps_*` empty;
  persistent fact `project-context.md` loaded.
- Field-key cross-check: all 31 documented gateway setting keys exist in
  `language/en_us/kuickpay.php` / `kuickpay.php` (automated grep, 0 missing).
- Constants verified in source: `MAX_SOAP_TIMEOUT = 300`, `MAX_OFFSET_DAYS = 365`, runtime
  default `soap_timeout = 30`.
- Schema-shape footguns re-verified directly against `kuickpay_reconcile_plugin.php`:
  `amount` is `VARCHAR(20)` (#9); `kuickpay_reconciliation_items` has no `company_id` (#13);
  `kuickpay_audit_events` indexes `company_id`/`voucher_id`/`event_name` but not `run_id` (#14).
- Secret-leak self-scan of both new docs: no literal URLs, no email/CNIC/5+digit shapes, no
  credential, DB-credential, or endpoint-host literals; endpoint examples use placeholders.
- Footgun coverage scan: all 15 AC-named footguns (1–15) present, plus additional traps A–L;
  27 `Source:` retro citations (15 + 12).
- Change set is docs-only: `git status` shows no `components/`, `plugins/`, or `tests/`
  changes (pre-existing `.htaccess`/`dashboard`/etc. changes are outside this task's scope and
  were left untouched).

### Completion Notes List

- **AC1 (deployment/config guide):** `docs/kuickpay/deployment-guide.md` covers file
  locations & ownership (gateway `Kuickpay` vs plugin `KuickpayReconcilePlugin`, camelCase
  round-trip), install order (plugin first; `companionInstalled()` hard-check) + dependency
  check + shared-lib coupling (plugin cron `Loader::load`s the gateway `lib/`), PKR
  enablement, credential entry, Institution ID + endpoint config (`wsdl_url` save-time
  SSRF/userinfo/HTTPS guard + `wsdl_allowed_hosts`), timeouts/instruction-groups/all settings
  (field-reference tables), and the reachability-only Test Connection action.
- **AC2 (placeholders only / no leakage):** both docs are sanitized with the standard header
  and use placeholders only; secret-leak self-scan is clean (see Debug Log).
- **AC3 (cumulative footgun note):** `docs/kuickpay/blesta-footguns.md` transcribes all 15
  AC-named footguns (gotcha → workaround → symbol → source) plus additional traps A–L, led by
  the `epic-4-retro-2026-06-13.md` Action-Item-10 cumulative citation, and is declared the
  canonical home for future footguns (open since Epic 1 #7).
- **AC4 (credential keep-if-blank decision):** the **password re-entry required** decision is
  recorded explicitly and prominently in the guide (write-only password fields; blank required
  passwords fail validation before meta rewrite; operators must re-enter BOTH passwords every
  save), with the structural why (footguns A + B) cross-referenced to the footgun note.
- **Review correction (NFR12):** the story's initial Dev Notes expected "blank password clears
  stored credential"; review verified the shipped code instead rejects blank required passwords
  in `editSettings()` and `GatewayManager::edit()` does not call `setMeta()` when gateway
  validation reports errors. The docs were corrected to code truth.
- **Discrepancy/finding (NFR12):** the lock table is actually named `kuickpay_reconcile_locks`
  (the story Dev Notes referred to "lock tables" generically); the guide uses the verified
  real name. No code/doc disagreements requiring a code change were found — nothing in code was
  modified (docs-only story honored).
- **Discoverability:** new docs cross-link to each other and into the existing `docs/kuickpay/`
  set (live-smoke runbook, 5.6 hardening verification, phase-0 contract) via "See also"
  sections. No `index.md` exists in `docs/kuickpay/`, so none was updated (story made that
  conditional on presence); existing closed verification records were left unchurned.
- **No** production code, test code, schema, version bump, or settings behavior change.

### File List

- `docs/kuickpay/deployment-guide.md` (new) — operator deployment + configuration guide
  (AC1/AC2/AC4).
- `docs/kuickpay/blesta-footguns.md` (new) — cumulative developer footgun note (AC3).
- `_bmad-output/kuickpay/implementation-artifacts/5-8-document-deployment-and-configuration.md`
  (modified) — task checkboxes, Dev Agent Record, File List, Change Log, Status.
- `_bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml` (modified) — story status
  `ready-for-dev` → `in-progress` → `review`.

## Change Log

| Date | Change |
|---|---|
| 2026-06-16 | Story 5.8 implemented (docs-only): added `deployment-guide.md` (AC1/AC2/AC4) and `blesta-footguns.md` (AC3); verified all field keys/defaults/ranges and the 15+A–L footguns against source at baseline `299e7638`; secret-leak self-scan clean. Status → review. |
