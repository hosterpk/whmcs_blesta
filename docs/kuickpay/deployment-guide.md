# KuickPay Deployment & Configuration Guide

Date: 2026-06-16
Audience: **operators** deploying and configuring the KuickPay gateway + companion plugin.
Scope: Story 5.8 (deployment + configuration). Reconciliation/support runbooks are Story 5.9;
rollback/upgrade/launch-checklist is Story 5.10 — out of scope here.

This document is sanitized. It contains **NO** `config/blesta.php` values, database
credentials, KuickPay credentials, Institution ID values, real WSDL host names, raw SOAP
payloads, or customer PII (NFR8; `phase-0-contract.md`). Every credential or
environment-specific value below is a **placeholder** (e.g. `<operator-provided-wsdl-url>`,
`<institution-id>`). No production endpoint or secret is committed anywhere in this repo —
the operator supplies the confirmed values at deploy time (NFR10). It states plainly what is
verified against the shipped code versus what an operator must confirm in their own
environment (NFR12).

All facts below were verified against source at baseline commit `299e7638`.

---

## 1. What you are deploying (two trees, two roles)

KuickPay ships as **two cooperating Blesta extensions**. Both must be present; they own
different halves of the workflow.

| Tree | Path | Class | Role |
|---|---|---|---|
| **Gateway** | `components/gateways/nonmerchant/kuickpay/` | `Kuickpay` | Checkout / customer-reference display, the settings UI, PKR eligibility gating, the SOAP/parser/redactor/evidence libraries, and the safe connection test. |
| **Plugin** | `plugins/kuickpay_reconcile/` | `KuickpayReconcilePlugin` | Durable voucher state, reconciliation, posting to invoices, the cron tasks, the database schema, and the admin reconciliation workbench. |

> **Casing matters — `Kuickpay`, not `KuickPay`.** The gateway class is `Kuickpay`
> (camelCase round-trip). Blesta resolves the gateway's views, assets, and language files
> from this exact class casing, so the folder is `kuickpay/` and the class is `Kuickpay`. The
> human-facing brand name rendered to customers is "KuickPay" (from the language file), but
> the code identifier is `Kuickpay`. Do not rename either.

**Shared libraries (gateway → plugin).** The gateway's `lib/` classes —
`KuickPaySoapClient`, `KuickPayResponseParser`, `KuickPayEvidence`, `KuickPayRedactor` — are
**shared**. The plugin's reconciliation cron loads them at runtime
(`KuickPayReconcileService::loadRuntimeDependencies()` calls `Loader::load()` against
`components/gateways/nonmerchant/kuickpay/lib/`). **Consequence:** the gateway tree must
remain installed for the plugin cron to run. Removing the gateway directory will break
reconciliation/posting even though the plugin schema and rows survive.

---

## 2. Install order + dependency check

**Install and enable the `kuickpay_reconcile` plugin FIRST, then install/enable the
gateway.**

The gateway hard-depends on the companion plugin and refuses to operate without it:

- On the **settings screen**, when the plugin is missing the gateway shows
  `Kuickpay.!error.companion_missing` ("KuickPay requires its companion plugin. Install and
  activate the KuickPay Reconcile plugin, then reload this page to configure the gateway.")
  instead of the configuration form. (`getSettings()` → `companionInstalled()`.)
- On the **payment path**, `buildProcess()` sets an `unsupported` common error when the
  companion is absent, so no voucher can be created. (`kuickpay.php:1045`.)
- The check is `PluginManager->isInstalled('kuickpay_reconcile', Blesta.company_id)`, scoped
  to the **current company**. (`companionInstalled()`, `kuickpay.php:1912-1917`.)

The plugin's `install()` is the **schema owner**. It creates the tables and registers the
cron tasks:

| Table | Purpose |
|---|---|
| `kuickpay_vouchers` | Durable per-voucher state (status, amounts, dates, evidence hash, posted transaction id). |
| `kuickpay_voucher_invoices` | Voucher ↔ invoice link rows. |
| `kuickpay_reconciliation_runs` | One row per reconciliation run, with the per-run counters. |
| `kuickpay_reconciliation_items` | Per-voucher outcome rows for a run. |
| `kuickpay_reconcile_locks` | Company-scoped cron locks (prevents overlapping runs). |
| `kuickpay_audit_events` | Append-only audit trail for reconciliation/posting events. |

Cron tasks registered at install (`getCronTasks()`):

| Task key | Interval | Purpose |
|---|---|---|
| `reconcile_pending` | every **5 min** | Inquire on pending/retry vouchers and record evidence. |
| `post_confirmed` | every **5 min** | Post confirmed-unposted vouchers to their invoices. |
| `expire_vouchers` | every **60 min** | Mark past-expiry vouchers expired. |

> Blesta dispatches these through its normal cron surface. Confirm the Blesta system cron is
> actually running on the host — the reconciliation/posting/expiry workflow only advances when
> cron fires. The inquiry/reconciliation run is additionally gated by the
> `reconciliation_enabled` setting (see §6): when it is off, `resolveGatewayConfig()` returns
> null and `reconcile_pending` skips without contacting KuickPay. The `post_confirmed` and
> `expire_vouchers` tasks are separate cleanup/posting paths and are not paused by that
> checkbox.

---

## 3. PKR enablement

KuickPay is **PKR-only**:

- The gateway's `config.json` declares `"currencies": ["PKR"]`, so Blesta's gateway-currency
  join only offers KuickPay for PKR.
- The `currency_policy` setting is fixed to `pkr_only` (the only selectable option), and the
  field note states no conversion rate is stored or applied.
- At runtime, `currencyEligible()` re-checks the invoice currency against the gateway's
  currency list; a non-PKR invoice is refused with `Kuickpay.!error.currency_ineligible`
  before any voucher is created.

**Operator action:** the company must have **PKR** configured and active, and the invoice
being paid must be in PKR, for KuickPay to be selectable and usable. (Cross-reference Story
1.5.)

---

## 4. Credential entry (and the password re-entry rule — AC4)

KuickPay uses up to two credential pairs:

| Field key | Label | Storage |
|---|---|---|
| `voucher_username` | Voucher username | Plaintext (used for `InsertVoucher`). |
| `voucher_password` | Voucher password | **Encrypted, write-only.** |
| `inquiry_username` | Inquiry username | Plaintext (used for inquiry operations). |
| `inquiry_password` | Inquiry password | **Encrypted, write-only.** |
| `inquiry_same_as_voucher` | Use voucher credentials for inquiries | Toggle. |

Only the two **password** fields are encrypted at rest — `encryptableFields()` returns
exactly `['voucher_password', 'inquiry_password']`. Usernames are stored as plaintext gateway
meta.

When **`inquiry_same_as_voucher` is ON**, inquiry requests reuse the voucher credentials and
the separate `inquiry_username` / `inquiry_password` are not stored (`editSettings()` unsets
them, and the inquiry-credential validation rules are skipped). When it is **OFF**, both
inquiry username and inquiry password become required.

### ⚠️ Password fields are write-only: you must re-enter BOTH passwords every save

The shipped behavior is **re-entry required**, not keep-if-blank.

- The password fields are **write-only** in the UI: a stored password is shown only as a
  masked marker (`•••••••• ` plus "The voucher/inquiry password is hidden for security.
  Re-enter it to save any settings change."). The actual value is never echoed back into the
  form.
- **A blank required password field fails validation.** There is no "leave blank to keep the
  existing password" behavior — it is structurally impossible for a Blesta nonmerchant gateway
  here (see the developer note `blesta-footguns.md`, traps A and B): `setMeta()` is
  delete-then-insert of all gateway meta, and `editSettings()` runs on an **id-less instance**,
  so the gateway cannot read its own previously-stored encrypted password to re-supply a blank
  one. Because `voucher_password` is required, and `inquiry_password` is required when separate
  inquiry credentials are used, Blesta rejects a blank-password save before it can rewrite
  gateway meta.

> **Operator rule:** treat the password fields as write-only. **Re-enter BOTH the voucher
> password and the inquiry password every time you save settings** — even when you are only
> changing an unrelated field. If you submit a blank required password, the settings save is
> rejected and the old gateway meta is left untouched. This matches the gateway's own field
> notes (`voucher_password_stored`, `inquiry_password_stored`: "Re-enter it to save any
> settings change.").

Use placeholders when recording examples for your team — never paste a real username or
password into a ticket, doc, or shell history.

---

## 5. Institution ID + endpoint configuration

| Field key | Label | What to enter |
|---|---|---|
| `institution_id` | Institution ID | The merchant institution identifier **assigned by KuickPay**. Placeholder: `<institution-id>`. Required. Never commit the real value. |
| `wsdl_url` | WSDL URL | The HTTPS KuickPay SOAP WSDL/endpoint URL for this merchant. Placeholder: `<operator-provided-wsdl-url>`. Required. |
| `wsdl_allowed_hosts` | Allowed WSDL hosts | Optional confirmed-endpoint allowlist. |

**`wsdl_url` is hardened at save time** (Story 5.6 chokepoint, `wsdlUrlSafety()`):

- **HTTPS only.** A non-HTTPS or malformed URL is rejected (`wsdl_url.format`).
- **No embedded userinfo.** A `user:pass@host` URL is rejected (`wsdl_url.userinfo`) — the
  stored value is later fetched server-side unguarded by the cron, so credentials must never
  be persisted in it.
- **SSRF guard.** Private, loopback, link-local, and otherwise reserved addresses are
  rejected (IPv4 and IPv6, including IPv4-mapped IPv6). A named host must resolve only to
  public addresses or it is blocked (`wsdl_url.host`).

**`wsdl_allowed_hosts`** is an optional operator allowlist of confirmed KuickPay endpoint
hostnames (comma-, newline-, or space-separated):

- When **set**, the `wsdl_url` host must match one of the listed hosts **exactly** or the save
  is rejected.
- When **empty**, any public HTTPS host that passes the safety checks above is allowed.

> **No endpoint host literal belongs in deployment documentation.** Any host-like value in
> older contract notes is example-only, not a production default (`phase-0-contract.md`,
> NFR10). The operator pastes their Phase-0 confirmed production WSDL (and, optionally, the
> allowlist host) at deploy time.

> **Boundary note (do not over-promise):** the save-time chokepoint is where the
> SSRF/userinfo/allowlist enforcement lives. The cron-side
> `KuickPaySoapClient::hasUsableWsdlUrl()` re-checks only userinfo/HTTPS by design — it
> deliberately does **not** re-run the private-range/allowlist guard, because the save
> chokepoint already prevents a bad value from ever being stored
> (`gateway-settings-and-endpoint-hardening-verification.md`). Do not describe the cron as
> re-validating private ranges.

---

## 6. Timeouts, instruction groups, and the remaining settings

### Timeout

| Field key | Label | Range / default |
|---|---|---|
| `soap_timeout` | SOAP timeout seconds | Whole seconds **1–300**. Optional; the runtime default when unset/empty is **30**. Leading zeros are rejected. |

### Reference patterns and voucher fields

| Field key | Label | Notes |
|---|---|---|
| `registration_number_pattern` | Registration number pattern | Required. Tokens: `{random_prefix}` (4-digit random), `{invoice_id}`. Recommended: `{random_prefix}{invoice_id}`. Allowed characters: letters, numbers, underscores, braces, `+`, `-`. |
| `consumer_number_pattern` | Consumer number pattern | Required. Tokens: `{institution_id}`, `{registration_number}`, `{random_prefix}`, `{invoice_id}`. Recommended: `{institution_id}{registration_number}`. Same character allowlist. |
| `payment_head_label` | Payment head label | Optional. |
| `due_date_offset_days` | Due date offset days | Optional, **0–365** whole days from voucher creation. |
| `expiry_date_offset_days` | Expiry date offset days | Optional, **0–365**; must be **≥** `due_date_offset_days`. |
| `fallback_mobile` / `fallback_email` / `default_branch` | Fallback mobile / email / branch | Optional fallbacks used when a client value cannot be used for voucher creation. |

### Instruction groups (customer "How to pay" guidance)

Four checkboxes select which payment-channel instructions the customer sees. The settings
form pre-checks them per the defaults below:

| Field key | Label | Default |
|---|---|---|
| `instruction_online_banking` | Online banking instructions | **ON** |
| `instruction_bank_deposit` | Bank deposit instructions | **ON** |
| `instruction_agent_franchise` | Agent or franchise instructions | **OFF** |
| `instruction_mobile_app` | Mobile app instructions | **OFF** |

### Fixed-policy fields (this version)

These render as the single shipped option and cannot currently be changed:

| Field key | Fixed value |
|---|---|
| `currency_policy` | `pkr_only` |
| `fee_policy` | `none` (no additional fee) |
| `amount_change_policy` | `block` (block stale references) |
| `multi_invoice_policy` | `block` (one invoice per reference) |
| `underpayment_policy` | `manual_review` |
| `overpayment_policy` | `manual_review` |
| `late_payment_policy` | `manual_review` |

### Logging & reconciliation toggles

| Field key | Label | Default | Effect |
|---|---|---|---|
| `logging_enabled` | Enable structured logging | **ON** (form pre-checks it) | Gates the gateway's structured operational logging. |
| `reconciliation_enabled` | Enable reconciliation | **ON** (form pre-checks it) | Gates inquiry/reconciliation runs — when off, `resolveGatewayConfig()` returns null and `reconcile_pending` skips without contacting KuickPay. It does not pause `post_confirmed` or `expire_vouchers`. |

> Note on checkbox defaults: the settings form pre-checks `logging_enabled`,
> `reconciliation_enabled`, `inquiry_same_as_voucher`, and the two ON instruction groups. On
> save, any checkbox the browser does **not** submit is stored as `false` — so an operator who
> unchecks reconciliation and saves will stop the inquiry/reconciliation leg. Keep
> `reconciliation_enabled` checked in normal operation.

---

## 7. Safe connection testing

The settings screen has a **Test connection** action (the `run_connection_test` submit
button, value `true`). It is a **reachability-only** probe:

- It fetches **only** the configured `wsdl_url` over HTTPS to prove the endpoint is
  reachable.
- It sends **no credentials**, creates **no voucher**, and posts **no payment**.
- Normal **Save** does *not* run it; it runs only when you click **Test connection** and the
  settings otherwise validate. The button value is stripped before the settings are stored
  (`unset($meta['run_connection_test'])`).
- The button note says that **if the endpoint is reachable, clicking Test connection also
  saves these settings** — so a successful test persists the form.

Outcomes you may see:

| Outcome | Meaning |
|---|---|
| (reachable — no error) | TLS completed and the host returned any HTTP status (including a 3xx/401/403 on an auth-gated WSDL). Treated as success. |
| `connection.unreachable` | The host could not be reached (transport error or no HTTP response). |
| `connection.timeout` | The connection exceeded `soap_timeout` (default 30s). Check the endpoint and timeout. |
| `connection.url_userinfo` | The URL has embedded `user:pass` — remove it. |
| `connection.url_blocked` | The host is private/local/reserved or not on the allowlist. |
| `connection.unavailable` | The cURL transport is missing in this environment — the test could not run. |

> **Test connection does NOT validate credentials.** It only proves the endpoint is
> reachable. The credentialed, real-provider check is the **opt-in live smoke** — see
> `docs/kuickpay/live-smoke-runbook.md` (Story 5.7), which is the one sanctioned real-provider
> check (manual, read-only, default-skipped; KuickPay provides no sandbox for this merchant).

---

## 8. Runtime note

Production runs on **PHP 8.3 (`ea-php83`)** — confirmed by the cPanel `.htaccess` handler and
required by the ionCube-encoded Blesta core. PHP 8.2 is only the *source-compatibility floor*,
not the runtime. If you mirror this deployment elsewhere, run it on the PHP 8.3 build.

---

## 9. Rollback / ownership separation (pointer only)

The gateway can be **disabled independently** of the plugin cron, and the voucher / audit /
payment-evidence tables are **preserved on uninstall** — so disabling the gateway does not
destroy reconciliation history. This guide only notes the separation; the full
rollback/upgrade/launch runbook is **Story 5.10** (do not treat this section as the rollback
procedure).

---

## 10. Honest-reporting notes (NFR12)

- Every setting key, label, default, and range above was cross-checked against the gateway
  `language/en_us/kuickpay.php`, `kuickpay.php`
  (`editSettings()`/`getSettings()`/`encryptableFields()`/`runConnectionTest()`), the settings
  view `views/default/settings.pdt`, both `config.json` files, and the plugin
  `kuickpay_reconcile_plugin.php` (install/schema/cron) at baseline `299e7638`.
- This guide describes shipped behavior only. It does not assert that any live connection,
  voucher creation, or payment was performed from this document — those are operator actions
  (the live smoke is the sanctioned real-provider check, Story 5.7).

## See also

- `docs/kuickpay/blesta-footguns.md` — developer reference for the Blesta framework footguns
  behind these behaviors (e.g. the password re-entry traps).
- `docs/kuickpay/live-smoke-runbook.md` — the opt-in credentialed real-provider smoke.
- `docs/kuickpay/gateway-settings-and-endpoint-hardening-verification.md` — Story 5.6 endpoint
  hardening verification.
- `docs/kuickpay/phase-0-contract.md` — no committed endpoints/credentials/Institution IDs.
