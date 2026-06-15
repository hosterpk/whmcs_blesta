# KuickPay Rollback Runbook

Date: 2026-06-16
Audience: **operators** disabling KuickPay safely.
Scope: Story 5.10 rollback guidance. This covers disabling the gateway, disabling the plugin
cron, preserving voucher/audit/payment evidence, and keeping admin evidence readable.

This document is sanitized. It contains **NO** `config/blesta.php` values, database
credentials, KuickPay credentials, Institution ID values, real WSDL host names, raw SOAP
payloads, or customer PII (NFR8/NFR10). Every credential or environment-specific value below is
a **placeholder** (e.g. `<institution-id>`, `<operator-provided-wsdl-url>`,
`<consumer-number>`, `<run-id>`).

All facts below were verified against source at baseline commit `b6d4ef4d`. The code is truth:
if this doc and the running system disagree, trust the system and report the doc bug.

---

## 1. What rollback means here

Rollback means **stop the KuickPay integration without destroying reconciliation history**.

The checkout gateway and the companion plugin cron are independently disableable:

| Lever | Stops | Preserves |
|---|---|---|
| Disable/uninstall the **KuickPay gateway** | New KuickPay checkout and the gateway settings/meta rows. | Plugin tables and previously posted Blesta transactions. |
| Disable the **KuickPay Reconcile cron tasks** | Scheduled inquiry/posting/expiry. | Gateway settings, plugin install, plugin tables, admin screens. |
| Uninstall the **KuickPay Reconcile plugin** | Plugin cron registrations and plugin admin UI. | The six durable evidence tables. |

The plugin intentionally preserves voucher evidence tables on uninstall. Rollback is therefore a
stop-new-activity procedure; it is not a data-destruction or ledger-unwind procedure.

## 2. Decide whether to drain in-flight vouchers first

Important coupling: the reconcile cron reads inquiry credentials from the gateway's encrypted
`gateway_meta` rows. Uninstalling the gateway deletes those rows, including encrypted voucher and
inquiry credentials.

Before removing the gateway, choose one path:

| Choice | Use when | Result |
|---|---|---|
| Drain first | Operators want remaining `pending` / `confirmed_unposted` vouchers to finish normally. | Let scheduled `reconcile_pending` / `post_confirmed` finish, or use Check Now / Bulk per `reconciliation-runbook.md`, then remove the gateway. |
| Stop immediately | Operators accept that remaining in-flight vouchers may be stranded. | Disable checkout and/or remove the gateway now; the cron cannot reconcile remaining vouchers once gateway credentials are gone. |

Do not skip this decision. It is the difference between a controlled rollback and a rollback that
leaves unresolved vouchers requiring manual investigation.

## 3. Disable the gateway

Operator path to remove KuickPay from checkout:

1. Open Blesta admin.
2. Go to **Settings -> Payment Gateways -> Installed -> KuickPay -> Uninstall**.
3. Confirm the action only after the in-flight voucher decision in §2 is recorded.

This removes KuickPay from checkout. It also deletes the gateway's stored meta rows, including the
encrypted inquiry/voucher credentials that the reconcile cron reads.

Operator-confirmed value: verify the exact menu labels in the production Blesta UI before launch,
because admin wording can vary by Blesta version/theme. The source path and delete behavior were
verified; the live UI labels are an operations confirmation item.

## 4. Disable the plugin cron

There are two separate levers. Prefer the first one when evidence must remain browsable in the UI.

| Lever | Admin path | When to use | Effect |
|---|---|---|---|
| Disable cron task runs | **Settings -> Automation/Cron Tasks**; set the three KuickPay tasks disabled (`enabled=0`). | Normal rollback when operators want to stop scheduled activity but keep the plugin installed. | Stops execution, preserves all plugin data, keeps admin screens available. |
| Uninstall plugin | **Settings -> Plugins -> KuickPay Reconcile -> Uninstall**. | Only when the plugin UI and cron registrations should be removed. | Removes plugin `cron_task_runs` and, if this is the last instance, the shared `cron_tasks` definitions. Evidence tables are still preserved. |

The three plugin cron task keys are:

| Key | Interval | Purpose |
|---|---:|---|
| `reconcile_pending` | 5 min | Inquire on pending/retry vouchers. |
| `post_confirmed` | 5 min | Post confirmed-unposted vouchers to Blesta. |
| `expire_vouchers` | 60 min | Expire past-window pending/retry vouchers. |

## 5. Preserve voucher, audit, and payment evidence

Do **not** delete these durable tables during rollback:

| Table | Evidence retained |
|---|---|
| `kuickpay_vouchers` | Voucher state, amounts, references, posted transaction id. |
| `kuickpay_voucher_invoices` | Voucher-to-invoice mapping. |
| `kuickpay_reconciliation_runs` | Reconciliation run headers and counters. |
| `kuickpay_reconciliation_items` | Per-voucher run item transitions. |
| `kuickpay_audit_events` | Redacted audit timeline and evidence hashes. |
| `kuickpay_reconcile_locks` | Operational locks; these may be cleared post-rollback if stale. |

Uninstalling the plugin does not drop these tables. Rollback also does **not** reverse Blesta
transactions created by posting. The Blesta ledger remains the financial source of truth:
rollback stops new KuickPay activity, but it does not unwind already posted payments.

## 6. Keep admin evidence readable

The four plugin admin evidence screens remain readable after the gateway is disabled:

| Screen | Use |
|---|---|
| KuickPay Vouchers | Search and inspect vouchers and voucher detail. |
| KuickPay Reconciliation Runs | Read run summaries and run detail. |
| KuickPay Manual Review | Inspect the manual-review queue. |
| KuickPay Bulk Reconciliation | Trigger bulk checks only while credentials/gateway remain available. |

The plugin controllers render from voucher/run/audit tables and do not depend on the gateway being
installed. Caveat: if the **plugin is uninstalled**, its admin nav/controllers are removed too. To
keep history browsable in the UI, disable the cron tasks and keep the plugin installed.

Use `reconciliation-runbook.md` for the run screens/action matrix and
`support-troubleshooting.md` for the safe status table and sanitized escalation evidence.

## 7. Honest-reporting notes (NFR12)

- Verified against source at baseline `b6d4ef4d`: plugin uninstall preserves evidence tables;
  cron task keys and intervals; gateway uninstall removes gateway meta; plugin admin controllers
  have no gateway-table dependency for read views.
- Operator must confirm in their own environment: exact production Blesta UI labels, approved
  production Blesta version, and the current rollback decision for in-flight vouchers.
- No live endpoint, credential, Institution ID, raw SOAP payload, or customer PII is required or
  recorded by this runbook.

## See also

- `docs/kuickpay/deployment-guide.md` — install/configuration and the gateway/plugin ownership split.
- `docs/kuickpay/upgrade-runbook.md` — upgrade order, migrations, and post-upgrade verification.
- `docs/kuickpay/production-launch-checklist.md` — pre-go-live rollback-readiness gate.
- `docs/kuickpay/reconciliation-runbook.md` — Check Now, Bulk, runs, and Manual Review.
- `docs/kuickpay/support-troubleshooting.md` — safe status interpretation and escalation evidence.
- `docs/kuickpay/blesta-footguns.md` — developer-facing framework traps behind these behaviors.
