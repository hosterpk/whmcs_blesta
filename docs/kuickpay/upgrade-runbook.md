# KuickPay Upgrade Runbook

Date: 2026-06-16
Audience: **operators and deployers** upgrading the KuickPay gateway + companion plugin.
Scope: Story 5.10 upgrade guidance. This covers plugin/gateway upgrade order, plugin schema
migration expectations, the Blesta permission/action upgrade footgun, and post-upgrade checks.

This document is sanitized. It contains **NO** `config/blesta.php` values, database
credentials, KuickPay credentials, Institution ID values, real WSDL host names, raw SOAP
payloads, or customer PII (NFR8/NFR10). Every credential or environment-specific value below is
a **placeholder** (e.g. `<institution-id>`, `<operator-provided-wsdl-url>`, `<run-id>`).

All facts below were verified against source at baseline commit `b6d4ef4d`. The code is truth:
if this doc and the running system disagree, trust the system and report the doc bug.

---

## 1. Current shipped versions

| Extension | Path | Current shipped version |
|---|---|---:|
| KuickPay gateway | `components/gateways/nonmerchant/kuickpay/config.json` | `1.0.0` |
| KuickPay Reconcile plugin | `plugins/kuickpay_reconcile/config.json` | `1.10.0` |

The gateway provides checkout, settings, SOAP/parser/redactor libraries, and encrypted gateway
credentials. The plugin owns durable voucher state, reconciliation, posting, cron tasks, admin
screens, and schema.

## 2. Upgrade order

Follow the same ownership order as deployment:

1. Upgrade/install the **KuickPay Reconcile plugin** package first.
2. Run the plugin upgrade through Blesta's plugin manager.
3. Confirm plugin schema/admin/cron checks in §5.
4. Upgrade/install the **KuickPay gateway** package.
5. Open and save gateway settings only when operators are ready to re-enter the required password
   fields (see `deployment-guide.md` §4).
6. Run the post-upgrade reconciliation checks in §5.

The coupling to remember: the plugin cron consumes the gateway's SOAP client and gateway-stored
credentials. A future gateway change that alters the SOAP client contract, settings shape, or
credential fields may require a matching plugin release. Do not upgrade one side across an
incompatible contract boundary.

## 3. Schema migration expectations

The plugin owns its schema changes in `KuickpayReconcilePlugin::upgrade($current_version,
$plugin_id)`.

The shipped upgrade method is a sequence of independent:

```php
if (version_compare($current_version, '<version>', '<')) {
    // migration for that version
}
```

blocks. There is no early return between version blocks, so a multi-version jump runs every
applicable intermediate migration in order.

| Version guard | Migration behavior |
|---|---|
| `< 1.1.0` | Adds voucher evidence columns, creates reconciliation/evidence tables, registers cron tasks. |
| `< 1.2.0` | Re-adds cron task definitions. |
| `< 1.3.0` | Re-adds cron task definitions. |
| `< 1.4.0` | Adds bulk reconciliation columns. |
| `< 1.5.0` | Intentionally empty SQL block; version bump re-syncs nav/permissions. |
| `< 1.6.0` | Intentionally empty SQL block; version bump re-syncs diagnostics permission. |
| `< 1.7.0` | Intentionally empty SQL block; version bump re-syncs manual action permissions. |
| `< 1.8.0` | Intentionally empty SQL block; version bump re-syncs manual-review/run-view nav and permissions. |
| `< 1.9.0` | Adds active-context concurrency guard (`context_key`, generated `active_context_key`, unique key). |
| `< 1.10.0` | Adds `posting_attempts` counter. |

Fresh-install equivalence and idempotent upgrade behavior are documented in
`active-context-concurrency-verification.md` and
`posting-safety-hardening-verification.md`.

## 4. The upgrade footgun

For whoever cuts the next version: `PluginManager::upgrade()` wipes and re-adds the plugin's
permission/action set from the current `getPermissions()` and `getActions()` definitions.

Do not re-derive the framework mechanics here. The maintainer rule is in `blesta-footguns.md` #4:
the full ACL/nav set must be re-declared every version, version guards must use `<`, and upgrade
must not early-return before later blocks can run. The shipped `upgrade()` follows that pattern.

## 5. Post-upgrade verification checks

Run these checks after the package upgrade and Blesta plugin/gateway upgrade flow.

| Check | How to confirm |
|---|---|
| Plugin version | Blesta shows the KuickPay Reconcile plugin at the expected new version. |
| Cron registrations | The three tasks `reconcile_pending`, `post_confirmed`, and `expire_vouchers` are registered and enabled unless rollback/maintenance intentionally disabled them. |
| Admin nav + permissions | Billing nav shows KuickPay Vouchers, Bulk Reconciliation, Manual Review, and Reconciliation Runs; expected staff groups can access the screens. |
| Schema columns | `kuickpay_vouchers` has the v1.9.0 concurrency guard columns (`context_key`, `active_context_key`) and the v1.10.0 `posting_attempts` column. Confirm through the operator's normal DB-inspection process; do not paste schema dumps or credentials into tickets/docs. |
| Reconciliation idempotency | A reconciliation run completes without double-posting; `confirmed_unposted` rows become `posted` only through the posting path. Use `reconciliation-runbook.md` for run summaries and status interpretation. |
| Gateway settings | If settings are saved, both password fields are re-entered as required by `deployment-guide.md` §4. |

Only `posted` means paid. Post-upgrade monitoring must continue to treat `pending`, `retry`,
`confirmed_unposted`, `manual_review`, `failed`, `expired`, and `cancelled` as not-paid states.

## 6. Honest-reporting notes (NFR12)

- Verified against source at baseline `b6d4ef4d`: shipped versions, upgrade block order, real-SQL
  versus intentionally empty upgrade blocks, cron task keys, and the permission/action re-sync
  footgun pointer.
- Operator must confirm in their own environment: target version being deployed, production
  Blesta version, staff ACL assignments, live cron enabled state, gateway credentials, and endpoint.
- This runbook does not include SQL dumps, endpoint hosts, credentials, Institution IDs, raw SOAP,
  or customer PII.

## See also

- `docs/kuickpay/rollback-runbook.md` — rollback procedure and in-flight voucher warning.
- `docs/kuickpay/production-launch-checklist.md` — launch gates and first-week monitoring.
- `docs/kuickpay/deployment-guide.md` — install order, credentials, endpoint, and runtime notes.
- `docs/kuickpay/reconciliation-runbook.md` — run summaries, status interpretation, and Manual Review.
- `docs/kuickpay/blesta-footguns.md` — developer-facing Blesta upgrade/ACL footgun #4.
- `docs/kuickpay/active-context-concurrency-verification.md` — v1.9.0 verification.
- `docs/kuickpay/posting-safety-hardening-verification.md` — v1.10.0 verification.
