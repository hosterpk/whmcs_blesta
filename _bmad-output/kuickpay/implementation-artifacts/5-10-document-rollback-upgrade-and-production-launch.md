---
baseline_commit: b6d4ef4d
---
<!-- Powered by BMAD-CORE™ -->

# Story 5.10: Document Rollback, Upgrade, and Production Launch

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an operator,
I want rollback, upgrade, and launch guidance,
so that the KuickPay integration can be introduced or disabled safely.

This is the **final story of Epic 5 (the terminal epic)** and the **last of the three documentation stories** (5.8 deployment/config, 5.9 reconciliation/support, 5.10 rollback/upgrade/launch). It closes the rollback/upgrade/launch slice of **FR30** ("Delivery includes install, configure, reconcile, troubleshoot, rollback, upgrade, and support documentation"). It is a **documentation-only** story: it describes behavior that already shipped and was code-reviewed across Epics 1–5. **No runtime code, schema, tests, or version numbers change in this story.**

## Acceptance Criteria

_Source: [_bmad-output/kuickpay/planning-artifacts/epics.md#L1068-L1086] (Story 5.10) and FR30 [epics.md#L83]._

1. **(AC1 — Rollback)**
   **Given** rollback documentation is followed,
   **When** KuickPay must be disabled,
   **Then** it explains disabling the gateway, disabling the plugin cron, preserving Voucher/audit/payment evidence, and keeping admin evidence readable.

2. **(AC2 — Upgrade)**
   **Given** upgrade documentation is followed,
   **When** a future KuickPay extension version is deployed,
   **Then** it explains plugin/gateway upgrade order, schema migration expectations, and verification checks.

3. **(AC3 — Production launch)**
   **Given** production launch is prepared,
   **When** operators use the launch checklist,
   **Then** it includes production Blesta version confirmation, Phase 0 fixture approval, controlled payment test, credential rotation, reconciliation monitoring, first-week Manual Review checks, and rollback readiness.

4. **(AC4 — placeholders only; zero secret/environment leakage — NFR8/NFR10)** _(cross-cutting, inherited from the 5.8/5.9 docs contract)_
   **Given** any value, credential, or environment-specific detail is discussed,
   **When** the docs provide examples,
   **Then** they use **placeholders only** (e.g. `<institution-id>`, `<operator-provided-wsdl-url>`, `<consumer-number>`, `<run-id>`)
   **And** they copy **no** value from `config/blesta.php`, logs, cache, `.env`, or production settings — no real WSDL host, Institution ID, username/password, DB credential, or customer PII anywhere.

5. **(AC5 — code is truth; honest reporting — NFR12)** _(cross-cutting, inherited)_
   **Given** the docs assert how the system behaves,
   **When** a fact is stated,
   **Then** it is verified against the shipped code at the baseline commit, and any place where an operator must confirm a value in their own environment (e.g. production Blesta version, credentials, WSDL host) is called out explicitly as operator-confirmed, not assumed.

## Tasks / Subtasks

- [x] **Task 1 — AC1/AC4/AC5: write the rollback runbook** (`docs/kuickpay/rollback-runbook.md`)
  - [x] 1.1 **Sanitized header + audience.** Mirror the 5.8/5.9 sanitized header block (Date, Audience: **operator**, Scope, the "This document is sanitized … placeholders only (NFR8/NFR10)" paragraph, and "All facts below were verified against source at baseline commit `b6d4ef4d`. The code is truth…").
  - [x] 1.2 **What rollback means here.** State plainly: rollback = stop the integration *without destroying reconciliation history*. The gateway and the plugin cron are **independently disableable**, and the plugin's evidence tables are **preserved on uninstall by design** ([Source: `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` `uninstall()` docblock + body, lines 193-210 — "Voucher evidence tables are preserved on uninstall per the architecture rollback policy, even when this is the last plugin instance"]).
  - [x] 1.3 **Disable the gateway (procedure).** Document the verified admin path to remove KuickPay from checkout (Settings → Payment Gateways → Installed → KuickPay → Uninstall). **CRITICAL warning to include:** uninstalling the gateway deletes its `gateway_meta` rows — i.e. the **encrypted inquiry/voucher credentials** the reconcile cron reads. So decide first whether to **drain in-flight `pending`/`confirmed_unposted` vouchers** (let scheduled reconcile/post finish, or run Check Now / Bulk) **before** removing the gateway; once the gateway is gone the cron can no longer reconcile remaining vouchers (see Dev Notes "Rollback credential-coupling trap"). Verify the exact admin label/path against the running Blesta and record what you actually saw (NFR12).
  - [x] 1.4 **Disable the plugin cron (procedure).** Document the two distinct levers and when to use each: (a) **disable the cron tasks** (Settings → Automation/Cron Tasks → set the three KuickPay tasks `enabled=0`) which **stops execution but preserves all data and the plugin install**; vs (b) **uninstall the plugin** (Settings → Plugins → KuickPay Reconcile → Uninstall) which removes the plugin's `cron_task_runs` (and, if last instance, the shared `cron_tasks` definitions) but **still preserves all six evidence tables**. The three task keys are `reconcile_pending`, `post_confirmed`, `expire_vouchers` ([Source: plugin `getCronTasks()` lines 786-820; `cron()` key-guard line 219; `uninstall()` → `addCronTasks(true, $last_instance)` line 209]).
  - [x] 1.5 **Preserve Voucher/audit/payment evidence (what NOT to delete).** List the six durable tables and that uninstall does **not** drop any of them: `kuickpay_vouchers`, `kuickpay_voucher_invoices`, `kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_audit_events`, `kuickpay_reconcile_locks` (locks are operational and may be cleared post-rollback). State that **no Blesta transactions created by posting are reversed** by rollback — financial ledger truth is immutable; rollback stops *new* activity, it does not unwind posted payments. (See Dev Notes "Rollback mechanics reference".)
  - [x] 1.6 **Keep admin evidence readable.** Document that the four admin screens (Vouchers, Reconciliation Runs, Manual Review, Bulk) **remain fully readable after the gateway is disabled** — the controllers do not depend on the gateway being installed ([Source: admin controllers under `plugins/kuickpay_reconcile/controllers/admin_*.php` — no `GatewayManager`/gateway-table dependency]). Note the caveat: if the **plugin is uninstalled**, its admin nav/controllers go away too, so to keep evidence browsable, prefer "disable cron, keep plugin installed" over "uninstall plugin" when history must stay viewable in the UI. (Cross-link `reconciliation-runbook.md` and `support-troubleshooting.md` for what those screens show.)
  - [x] 1.7 **See also** section cross-linking `deployment-guide.md`, `upgrade-runbook.md`, `production-launch-checklist.md`, `reconciliation-runbook.md`, and `blesta-footguns.md`.

- [x] **Task 2 — AC2/AC4/AC5: write the upgrade runbook** (`docs/kuickpay/upgrade-runbook.md`)
  - [x] 2.1 **Sanitized header + audience** (operator/deployer), same block as Task 1.1.
  - [x] 2.2 **Plugin/gateway upgrade order.** State the order consistent with the documented install order in `deployment-guide.md §2` (read it first; do not invent a different order). Record current shipped versions as the baseline: **gateway `1.0.0`** (`components/gateways/nonmerchant/kuickpay/config.json`), **plugin `1.10.0`** (`plugins/kuickpay_reconcile/config.json`). Explain the coupling: the gateway provides the SOAP client + credentials the plugin cron consumes, so an upgrade that changes the client contract may require both to move together.
  - [x] 2.3 **Schema migration expectations.** Explain, in operator terms, how the plugin's `upgrade($current_version, $plugin_id)` works ([Source: plugin lines 112-191]): each version step is an **independent `version_compare($current_version, 'x.y.0', '<')` block** that runs every applicable migration in order, with **no early-return** — so upgrading across several versions runs all intermediate steps. Note which bumps ran real SQL (1.1.0 evidence columns + tables; 1.4.0 bulk columns; **1.9.0** active-context concurrency guard; **1.10.0** posting-attempts counter) vs which are **intentionally empty SQL blocks** (1.5.0–1.8.0) that exist only so Blesta's `PluginManager::upgrade()` **re-syncs the nav + permission set from `getActions()`/`getPermissions()`**. State that migrations are **idempotent** and **fresh-install ≡ upgrade** has been verified ([Source: `docs/kuickpay/active-context-concurrency-verification.md` (5.2), `docs/kuickpay/posting-safety-hardening-verification.md` (5.3)]).
  - [x] 2.4 **The upgrade footgun, in operator/maintainer terms.** Translate `blesta-footguns.md` footgun #4 for whoever cuts the next version: on every `PluginManager::upgrade()` Blesta **wipes and re-adds the entire permission/action set** from what `getPermissions()`/`getActions()` currently return — so the **full ACL must be re-declared each version**; and upgrade guards must use `<` (never `>=`) and **never early-return** before later version blocks run. The shipped `upgrade()` already follows this (all `< x.y` independent blocks). This subsection is a **pointer to `blesta-footguns.md #4`**, not a re-derivation — link, don't duplicate.
  - [x] 2.5 **Verification checks (post-upgrade).** Give an operator-runnable checklist: (a) plugin version now reads the new version; (b) the three cron tasks (`reconcile_pending`, `post_confirmed`, `expire_vouchers`) are still registered and enabled; (c) the KuickPay admin nav items + permissions are present (ACL re-sync succeeded); (d) the concurrency-guard generated columns (`context_key`, `active_context_key`, v1.9.0) and `posting_attempts` (v1.10.0) exist on `kuickpay_vouchers`; (e) a reconcile run completes without double-posting (idempotency holds). Frame these as **what to confirm**, citing the admin screens from `reconciliation-runbook.md`; do not embed SQL secrets or schema dumps.
  - [x] 2.6 **See also** cross-linking `rollback-runbook.md`, `production-launch-checklist.md`, `deployment-guide.md`, `blesta-footguns.md`.

- [x] **Task 3 — AC3/AC4/AC5: write the production launch checklist** (`docs/kuickpay/production-launch-checklist.md`)
  - [x] 3.1 **Sanitized header + audience** (operations), same block as Task 1.1. Frame as a **pre-go-live gate checklist** — each item is a checkbox with an owner and a "how to confirm".
  - [x] 3.2 **Production Blesta version confirmation.** Record that this checkout is **Blesta `6.0.0-b1` (beta — not production-supported)**, that the Phase 0 candidate production target is **Blesta 5.13 stable "unless operations explicitly approves a different supported target"**, and that the launch gate requires **operations to explicitly confirm and record the approved production Blesta version** ([Source: `docs/kuickpay/phase-0-contract.md` `production_blesta_version` = `UNCONFIRMED`]). Also confirm the **runtime is PHP 8.3 (`ea-php83`, ionCube 15)** — the cPanel `.htaccess` handler proves it and the ionCube-encoded core will not load on 8.2; PHP 8.2 is only the source-compat floor ([Source: `deployment-guide.md §8`; project-context.md Tech Stack]).
  - [x] 3.3 **Phase 0 fixture approval.** Document that all shipped SOAP fixtures are currently **synthetic/provisional, `PENDING_HUMAN_APPROVAL`** ([Source: `docs/kuickpay/testing-fixtures.md` provenance table; `docs/kuickpay/phase-0-contract.md` gate status]), and that **before production payment posting** the gate requires either (a) replacing them with **sanitized live captures** (via the 5.7 live smoke + `KuickPayRedactor::redactEnvelope()`, all sensitive values masked to `xxxx`), or (b) an **explicit human gate-owner approval** of the provisional shapes with the shape-mismatch risk acknowledged in writing.
  - [x] 3.4 **Controlled payment test.** Point to the **opt-in, default-skipped, read-only, DB-free** live smoke as the controlled pre-launch check; do **not** restate the full procedure — link `live-smoke-runbook.md` (5.7) and summarize only that it validates credentials + transport + parse + redaction and **never posts / never marks an invoice paid**. Use placeholders for all env values; reference the env opt-in pattern but copy no real value.
  - [x] 3.5 **Credential rotation.** State the **re-entry-required** rule (keep-if-blank is structurally impossible for this Blesta nonmerchant gateway): operators must **re-enter BOTH the voucher password and the inquiry password every time settings are saved**; a blank required password **fails validation before `setMeta()` runs**, so the old meta survives untouched ([Source: `deployment-guide.md §4`; `blesta-footguns.md` traps A & B; gateway `encryptableFields()` = `voucher_password`, `inquiry_password`]). This is also a pointer to `deployment-guide.md §4`, not a re-derivation.
  - [x] 3.6 **Reconciliation monitoring (first-week).** Describe what to watch: the three cron tasks (`reconcile_pending` ~5m, `post_confirmed` ~5m, `expire_vouchers` ~60m) firing without transport/credential errors; Reconciliation Runs showing `status=completed` (benign "lock held" skips are fine); yesterday's `confirmed_unposted` rows becoming `posted` (confirms `post_confirmed` is working). Link `reconciliation-runbook.md` for the run-summary counters and screens.
  - [x] 3.7 **First-week Manual Review checks.** Describe monitoring the Manual Review queue. **CRITICAL accuracy guardrail (see Dev Notes):** `manual_review` is a **safe, recoverable, dead-end state**, NOT a failure; the **only** action offered on a `manual_review` voucher is **Cancel** (terminal, requires a note). **There is NO "force paid"/"force-post" action anywhere in the product.** Do **not** write that operators can "force-post" under/over/late payments — they cannot. Describe under/over/late/duplicate/unmatched as cases the operator *investigates*, then either lets the normal flow re-resolve or Cancels. ([Source: `reconciliation-runbook.md §0` + §6.1; manual_review action matrix.])
  - [x] 3.8 **Rollback readiness.** Final gate item: confirm the team has read and can execute `rollback-runbook.md` (disable gateway + disable cron + preserve evidence + keep admin readable), and understands that posted Blesta transactions are not unwound by rollback. Link `rollback-runbook.md`.
  - [x] 3.9 **See also** cross-linking the other two new docs + `deployment-guide.md`, `reconciliation-runbook.md`, `support-troubleshooting.md`, `phase-0-contract.md`, `live-smoke-runbook.md`.

- [x] **Task 4 — AC1/AC2/AC3: wire the new docs into the existing corpus (cross-link, no duplication)**
  - [x] 4.1 **Update `deployment-guide.md §9`** ("Rollback / ownership separation (pointer only)") and the header line that says "rollback/upgrade/launch-checklist is Story 5.10 — out of scope here": now that the three docs exist, change those pointers to **link to** `rollback-runbook.md`, `upgrade-runbook.md`, and `production-launch-checklist.md`. Keep §9 a *pointer* — do **not** copy the procedures into the deployment guide.
  - [x] 4.2 Add the three new docs to the "See also" sections of `reconciliation-runbook.md` and `support-troubleshooting.md` where relevant (launch checklist + rollback). If a `docs/kuickpay/index.md` or README exists, add the three new entries; if none exists, do not create one.
  - [x] 4.3 Confirm consistent naming throughout: the gateway is **KuickPay** (product) / `Kuickpay` (class) / `kuickpay` (dir); the plugin is **KuickPay Reconcile** / `KuickpayReconcilePlugin` / `kuickpay_reconcile`. Do not slip "Kuickpay" vs "KuickPay" in operator prose.

- [x] **Task 5 — AC4/AC5: verification (secret-leak scan + code cross-check + honest reporting)**
  - [x] 5.1 **Cross-check every operator-facing claim against source** at baseline commit `b6d4ef4d` (admin paths, table names, cron keys, version strings, upgrade-block behavior, credential rule). Where a fact must be confirmed by the operator in their own environment (production Blesta version, exact admin labels, credentials, WSDL host), label it explicitly as operator-confirmed (NFR12).
  - [x] 5.2 **Secret-leak self-scan** of all changed docs: assert no `config/blesta.php` values, DB credentials, KuickPay credentials, Institution ID, real WSDL host, raw SOAP payloads, or customer PII appear — every concrete value is a placeholder (NFR8/NFR10). Run a grep for likely leak markers (real host patterns, `password`, IDs) over the new/changed files and record the result.
  - [x] 5.3 **Honest-reporting note** at the foot of each new doc (or a shared note): what was verified against code vs what the operator must confirm; the baseline commit; the "code is truth — report the doc bug if they disagree" line.

- [x] **Task 6 — docs-only hygiene & commit**
  - [x] 6.1 Keep this a **docs-only** change: touch only files under `docs/kuickpay/` (the three new docs + the cross-link edits to `deployment-guide.md`, `reconciliation-runbook.md`, `support-troubleshooting.md`). Touch **nothing** under `components/`, `plugins/`, `config/`, or any schema/test/version file.
  - [x] 6.2 Use the established commit style: `docs(kuickpay): <imperative summary>` (lowercase, < 72 chars). Do not mix runtime/code changes into this commit.

## Dev Notes

### ⚠️ Anti-disaster guardrails (read first)

- **Docs only.** Describe behavior that already shipped and was code-reviewed across Epics 1–5. If a doc and the code disagree, the **code is truth** — fix the doc and flag the discrepancy in the Dev Agent Record; **do not** change code, schema, tests, or version numbers in this story. Stay inside `docs/kuickpay/`.
- **Placeholders only — zero leakage (NFR8/NFR10).** No `config/blesta.php` value, DB credential, KuickPay credential, Institution ID, real WSDL host, raw SOAP payload, or customer PII in any doc. Every concrete value is a `<placeholder>`. This integration spent five stories hardening redaction — do not undo it in the docs.
- **"Only `posted` means paid."** Every status/monitoring/launch statement must respect this. Never imply `pending`, `retry`, `confirmed_unposted`, `manual_review`, `failed`, `expired`, or `cancelled` is "paid".
- **NO "force paid" anywhere — do not invent one in prose.** The product has **no** force-paid/force-post action. On a `manual_review` voucher the **only** offered action is **Cancel** (terminal, note required). Under/over/late/duplicate/unmatched payments are **investigated**, not "force-posted". Writing otherwise would document a feature that does not exist and invite an operator to attempt an unsafe action. [[kuickpay-manual-review-action-matrix]] [Source: `reconciliation-runbook.md §0`.]
- **Rollback credential-coupling trap (the non-obvious correctness point).** The reconcile **cron reads the inquiry credentials from the gateway's encrypted `gateway_meta`**. **Uninstalling the gateway deletes `gateway_meta`**, so any remaining `pending`/`confirmed_unposted` vouchers can no longer be reconciled or posted. The rollback runbook MUST tell the operator to decide up front: **drain in-flight vouchers first** (let scheduled reconcile/post finish, or use Check Now / Bulk) **then** remove the gateway — or knowingly accept that in-flight vouchers will be stranded. This is required for the system to behave correctly during rollback even though the AC only says "disable the gateway".
- **KuickPay vs Kuickpay.** Product/UI = **KuickPay**; PHP class = `Kuickpay`/`KuickpayReconcilePlugin`; dirs = `kuickpay` / `kuickpay_reconcile`. Keep operator prose on "KuickPay".

### The three docs to create (file plan — don't overreach)

| AC | New doc | Purpose | Hard boundary |
|----|---------|---------|---------------|
| AC1 | `docs/kuickpay/rollback-runbook.md` | Disable gateway, disable cron, preserve evidence, keep admin readable | Don't restate reconciliation ops; link `reconciliation-runbook.md` |
| AC2 | `docs/kuickpay/upgrade-runbook.md` | Upgrade order, schema-migration expectations, post-upgrade verification | Don't re-derive footgun #4; link `blesta-footguns.md` |
| AC3 | `docs/kuickpay/production-launch-checklist.md` | Pre-go-live gate: version, fixtures, controlled test, creds, monitoring, manual-review, rollback readiness | Don't restate the smoke procedure or credential mechanics; link `live-smoke-runbook.md` / `deployment-guide.md §4` |

Each doc is a **pointer-and-procedure** doc: it owns its procedure but links (does not duplicate) the deployment, reconciliation, footgun, and smoke docs that already exist.

### Rollback mechanics reference (verified at `b6d4ef4d`)

| Concern | Fact | Source |
|---------|------|--------|
| Gateway disable | Settings → Payment Gateways → Installed → KuickPay → Uninstall; `GatewayManager::delete()` removes rows from `gateways`, `gateway_currencies`, **`gateway_meta`** (deletes encrypted credentials) | `app/models/gateway_manager.php`; `app/controllers/admin_company_gateways.php::uninstall()` |
| Cron disable (keep plugin) | Settings → Automation/Cron Tasks → set the 3 tasks `enabled=0` (`cron_task_runs.enabled=0`); preserves all data, plugin stays installed | `app/models/cron_tasks.php::editTaskRun()` |
| Plugin uninstall | `uninstall()` calls `addCronTasks(true, $last_instance)` — removes only `cron_task_runs` (and shared `cron_tasks` defs if last instance); **tables preserved** | plugin lines 203-210 |
| Cron task keys | `reconcile_pending` (~5m), `post_confirmed` (~5m), `expire_vouchers` (~60m) | plugin `getCronTasks()` 786-820 |
| Tables preserved (6) | `kuickpay_vouchers`, `kuickpay_voucher_invoices`, `kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_audit_events`, `kuickpay_reconcile_locks` | plugin schema; uninstall docblock |
| Posted transactions | **Not** reversed by rollback — Blesta ledger is immutable; rollback stops new activity only | architecture rollback policy |
| Admin evidence views | 4 screens render from voucher/run/audit tables, **no gateway dependency** — readable after gateway disable (but gone if plugin uninstalled) | `plugins/kuickpay_reconcile/controllers/admin_*.php` |

### Upgrade mechanics reference (verified at `b6d4ef4d`)

- Shipped versions: **gateway `1.0.0`**, **plugin `1.10.0`** (`config.json` of each).
- `upgrade($current_version, $plugin_id)` (plugin lines 112-191): a series of **independent `version_compare($current_version, 'x.y.0', '<')` blocks**, run in order, **no early-return** — multi-version jumps run all intermediate steps. Real-SQL bumps: **1.1.0** (evidence columns + tables + cron), **1.4.0** (bulk columns), **1.9.0** (active-context concurrency guard: `context_key` + STORED generated `active_context_key`), **1.10.0** (`posting_attempts` counter). Empty-SQL bumps **1.5.0–1.8.0** exist only to trigger Blesta's nav/ACL re-sync.
- **Footgun #4 (link, don't re-derive):** `PluginManager::upgrade()` wipes and re-adds the whole permission/action set from `getPermissions()`/`getActions()` — **full ACL re-declared every version**; guards must be `<` (never `>=`) and never early-return. Shipped code already complies. [Source: `docs/kuickpay/blesta-footguns.md` #4.]
- Idempotent & **fresh-install ≡ upgrade** is proven in `active-context-concurrency-verification.md` (5.2) and `posting-safety-hardening-verification.md` (5.3) — cite, don't re-run.
- Schema/upgrade work lives in the plugin lifecycle hooks (not `components/upgrades/tasks/*` — that path is for Blesta core product upgrades). The upgrade doc describes the **plugin's** hook behavior.

### Production launch checklist reference (verified at `b6d4ef4d`)

| Gate item | Concrete fact / what to confirm | Owner | Source |
|-----------|----------------------------------|-------|--------|
| Blesta version | Checkout = `6.0.0-b1` (beta, non-prod). Candidate prod = **5.13 stable** unless ops approves another supported target. **Confirm & record.** | Operations | `phase-0-contract.md` (`production_blesta_version`=UNCONFIRMED) |
| PHP runtime | **PHP 8.3 (`ea-php83`, ionCube 15)**; won't load on 8.2; 8.2 is source-floor only | Operations | `deployment-guide.md §8`; project-context |
| Phase 0 fixtures | All fixtures **synthetic/provisional, `PENDING_HUMAN_APPROVAL`**; before posting, replace with sanitized live captures OR explicit human approval w/ risk note | Human gate owner (Israr/designate) | `testing-fixtures.md`; `phase-0-contract.md` |
| Controlled payment test | Opt-in, default-skipped, **read-only, DB-free** live smoke; validates creds+transport+parse+redact; **never posts** | Operations | `live-smoke-runbook.md` (5.7) |
| Credential rotation | **Re-enter BOTH passwords on every save**; blank required password fails validation before `setMeta()` (keep-if-blank impossible) | Operations | `deployment-guide.md §4`; footguns A & B |
| Reconciliation monitoring | 3 cron tasks firing; runs `completed`; `confirmed_unposted`→`posted` next cycle | Support ops | `reconciliation-runbook.md` |
| Manual Review (week 1) | Safe dead-end queue; **only Cancel** offered; **no force-paid**; investigate under/over/late/dup/unmatched | Support ops | `reconciliation-runbook.md §0/§6.1`; [[kuickpay-manual-review-action-matrix]] |
| Rollback readiness | Team can execute `rollback-runbook.md`; posted txns not unwound | Operations | this story's `rollback-runbook.md` |

### Doc conventions to mirror (from 5.8/5.9)

- **Sanitized header block** at the top of each new doc: `Date:`, `Audience: **<role>**`, `Scope: Story 5.10 …`, the "This document is sanitized … placeholders only (NFR8/NFR10)" paragraph, and "All facts below were verified against source at baseline commit `b6d4ef4d`. The code is truth: if this doc and the running system disagree, trust the system and report the doc bug."
- **Placeholder style:** `<operator-provided-wsdl-url>`, `<institution-id>`, `<consumer-number>`, `<invoice-id>`, `<run-id>`, `<redacted-trace-id>`, `<evidence-hash>`.
- **Markdown only**, numbered H2 sections for procedures, tables for reference data, a `## See also` cross-link block at the foot.
- **Audience split:** these are **operator/operations** docs — keep developer-internals (the footgun mechanics) as a *pointer* to `blesta-footguns.md`, not inline jargon.

### Previous Story Intelligence

- **5.8 (deployment/config, done):** created `deployment-guide.md` (install order, PKR, credentials, endpoint, timeouts, **§4 credential re-entry rule**, **§8 PHP 8.3 runtime note**, **§9 rollback pointer that defers to THIS story**) + `blesta-footguns.md` (15 footguns + traps A–L, incl. **#4 upgrade permission-wipe/`>=`/early-return** and **A/B credential keep-if-blank impossibility**). 5.10 must update §9 + the header pointer to link the new docs (Task 4.1).
- **5.9 (reconciliation/support, done):** created `reconciliation-runbook.md` (3 cron tasks, Check Now, Bulk, run summaries, **Manual Review §6**, under/over/late/dup/unmatched, **§0 "no force paid anywhere"**) + `support-troubleshooting.md` (search, Voucher Detail, **status vocabulary — only `posted`=paid**, sanitized escalation). 5.10's launch monitoring + manual-review sections must be consistent with these and link them.
- **5.7 (live smoke, done):** `live-smoke-runbook.md` — the controlled payment test the launch checklist points to.
- **5.1 (live verification, done):** confirmed runtime is PHP 8.3; `risk-acceptance-5-1-live-verification.md` is the model for "verified vs residual" honesty.
- **Recall:** [[kuickpay-manual-review-action-matrix]] (only Cancel from manual_review), [[kuickpay-recheck-outcome-token-set]], [[kuickpay-run-detail-audit-allowlist]], [[kuickpay-php82-toolchain-now-available]] (runtime is 8.3).

### Git Intelligence

- Baseline commit: `b6d4ef4d` (HEAD). Recent docs commits set the pattern: `docs(kuickpay): add reconciliation support runbooks`, `docs(kuickpay): document deployment and blesta footguns`. New docs land under `docs/kuickpay/`; sprint-status flips this story to `done` in the same docs scope.
- Commit style: `docs(kuickpay): <imperative, lowercase, <72 chars>`. Do not bundle code/runtime changes.

### Project Structure Notes

- **New files:** `docs/kuickpay/rollback-runbook.md`, `docs/kuickpay/upgrade-runbook.md`, `docs/kuickpay/production-launch-checklist.md`.
- **Edited files (cross-links only):** `docs/kuickpay/deployment-guide.md` (§9 + header pointer), `docs/kuickpay/reconciliation-runbook.md` (See also), `docs/kuickpay/support-troubleshooting.md` (See also).
- **No** changes to `components/`, `plugins/`, `config/`, schema, tests, or any `config.json`/version. This is the terminal story of the terminal epic — after it, Epic 5 can be marked done and `epic-5-retrospective` is available (optional).
- Naming: docs live under `docs/kuickpay/` (this is a generated/`docs` artifact per project-context workflow rules; do not mix with runtime changes).

### References

- [Source: _bmad-output/kuickpay/planning-artifacts/epics.md#L1068-L1086] — Story 5.10 ACs (rollback/upgrade/launch).
- [Source: _bmad-output/kuickpay/planning-artifacts/epics.md#L83] — FR30 (rollback/upgrade docs in scope).
- [Source: _bmad-output/kuickpay/planning-artifacts/epics.md#L843-L846] — Epic 5 terminal-epic framing + build order (5.8–5.10 docs).
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php#L112-L210] — `upgrade()` (independent `<` blocks) + `uninstall()` (tables preserved).
- [Source: plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php#L786-L820] — `getCronTasks()`; #L219 `cron()` key-guard.
- [Source: plugins/kuickpay_reconcile/config.json] — plugin version `1.10.0`. [Source: components/gateways/nonmerchant/kuickpay/config.json] — gateway version `1.0.0`.
- [Source: app/models/gateway_manager.php; app/controllers/admin_company_gateways.php] — gateway uninstall (`gateways`/`gateway_currencies`/`gateway_meta`).
- [Source: app/models/cron_tasks.php::editTaskRun] — disable cron task run (`enabled=0`) without data loss.
- [Source: plugins/kuickpay_reconcile/controllers/admin_*.php] — admin evidence views, no gateway dependency.
- [Source: docs/kuickpay/deployment-guide.md] — §4 credential re-entry, §8 PHP 8.3, §9 rollback pointer (to update).
- [Source: docs/kuickpay/blesta-footguns.md] — #4 upgrade permission-wipe/`>=`/early-return; traps A & B keep-if-blank.
- [Source: docs/kuickpay/reconciliation-runbook.md] — §0 no-force-paid; cron tasks; Manual Review; run counters.
- [Source: docs/kuickpay/support-troubleshooting.md] — status vocabulary (only `posted`=paid).
- [Source: docs/kuickpay/live-smoke-runbook.md] — opt-in controlled payment test (5.7).
- [Source: docs/kuickpay/testing-fixtures.md; docs/kuickpay/phase-0-contract.md] — fixtures `PENDING_HUMAN_APPROVAL`; `production_blesta_version` UNCONFIRMED (candidate 5.13).
- [Source: _bmad-output/project-context.md] — PHP 8.3 runtime; plugin lifecycle hooks; placeholders/no-secrets; `docs/` is generated artifact; docs-only commit hygiene.

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- Ran BMad code review first against baseline diff `b6d4ef4d`; all three review layers found no
  findings for the status-only `ready-for-dev` tracking change.
- Cross-checked plugin lifecycle hooks, cron task keys, and shipped versions against source before
  writing the 5.10 operator docs.
- Ran Markdown/link-oriented text scans and a placeholder/secret self-scan over changed docs.

### Completion Notes List

- Added rollback, upgrade, and production launch operator docs under `docs/kuickpay/`.
- Updated deployment, reconciliation, and support docs to link the new 5.10 docs without copying
  their procedures.
- Kept the change documentation-only: no runtime code, schema, tests, gateway/plugin config, or
  version files were modified.
- NFR12 caveat: exact production admin labels, production Blesta version, endpoint, credentials,
  staff owners, and fixture gate decision remain operator-confirmed launch values.

### File List

- `docs/kuickpay/rollback-runbook.md`
- `docs/kuickpay/upgrade-runbook.md`
- `docs/kuickpay/production-launch-checklist.md`
- `docs/kuickpay/deployment-guide.md`
- `docs/kuickpay/reconciliation-runbook.md`
- `docs/kuickpay/support-troubleshooting.md`
- `_bmad-output/kuickpay/implementation-artifacts/5-10-document-rollback-upgrade-and-production-launch.md`
- `_bmad-output/kuickpay/implementation-artifacts/sprint-status.yaml`
