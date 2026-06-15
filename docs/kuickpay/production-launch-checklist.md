# KuickPay Production Launch Checklist

Date: 2026-06-16
Audience: **operations** preparing KuickPay for production launch.
Scope: Story 5.10 production launch gate checklist. Each item has an owner and a way to confirm.

This document is sanitized. It contains **NO** `config/blesta.php` values, database
credentials, KuickPay credentials, Institution ID values, real WSDL host names, raw SOAP
payloads, or customer PII (NFR8/NFR10). Every credential or environment-specific value below is
a **placeholder** (e.g. `<institution-id>`, `<operator-provided-wsdl-url>`,
`<consumer-number>`, `<run-id>`).

All facts below were verified against source at baseline commit `b6d4ef4d`. The code is truth:
if this doc and the running system disagree, trust the system and report the doc bug.

---

## 1. Pre-go-live gates

| Gate | Owner | How to confirm |
|---|---|---|
| [ ] Production Blesta version approved | Operations | Record the approved production Blesta version in the launch record. This checkout is Blesta `6.0.0-b1` (beta, not production-supported). The Phase 0 candidate production target is Blesta 5.13 stable unless operations explicitly approves another supported target. |
| [ ] PHP runtime confirmed | Operations | Confirm production runs on PHP 8.3 (`ea-php83`) with ionCube 15. PHP 8.2 is only the source-compatibility floor; the ionCube-encoded core will not load on 8.2 here. |
| [ ] Phase 0 fixture gate closed | Human gate owner | Either replace provisional synthetic fixtures with sanitized live captures, or approve the provisional shapes in writing with shape-mismatch risk acknowledged. |
| [ ] Controlled payment test completed | Operations | Run the opt-in, default-skipped, read-only, DB-free live smoke from `live-smoke-runbook.md`. It validates credentials, transport, parse, and redaction; it never posts and never marks an invoice paid. |
| [ ] Credential rotation plan recorded | Operations | Confirm operators know the re-entry-required rule: both voucher and inquiry passwords must be re-entered every time gateway settings are saved. |
| [ ] Reconciliation monitoring staffed | Support operations | Assign first-week monitoring for cron health, run status, and `confirmed_unposted` to `posted` progression. |
| [ ] Manual Review monitoring staffed | Support operations | Assign first-week monitoring of the Manual Review queue; confirm staff know there is no force-paid/force-post action. |
| [ ] Rollback readiness confirmed | Operations | Confirm the team can execute `rollback-runbook.md` and understands posted Blesta transactions are not unwound by rollback. |

## 2. Production Blesta version and runtime

`phase-0-contract.md` records `production_blesta_version` as `UNCONFIRMED`. Do not launch until
operations explicitly confirms and records the approved production Blesta version.

Current facts to record accurately:

| Fact | Status |
|---|---|
| This checkout | Blesta `6.0.0-b1`, beta, not production-supported. |
| Candidate production target | Blesta 5.13 stable unless operations approves another supported target. |
| Runtime | PHP 8.3 (`ea-php83`) with ionCube 15. |
| Source compatibility floor | PHP 8.2 only; not the runtime for this ionCube core. |

## 3. Phase 0 fixture approval

All shipped SOAP fixtures are currently synthetic/provisional and marked
`PENDING_HUMAN_APPROVAL` in `testing-fixtures.md`.

Before production payment posting, the gate owner must choose one:

| Option | Confirmation required |
|---|---|
| Replace fixtures | Capture sanitized live responses through the 5.7 live smoke flow and `KuickPayRedactor::redactEnvelope()`, with sensitive values masked to `xxxx`. |
| Approve provisional fixtures | Record explicit human approval that provisional shapes are accepted for launch and the shape-mismatch risk is understood. |

Do not paste raw SOAP, endpoints, credentials, Institution IDs, or PII into the launch record.

## 4. Controlled payment test

Use `live-smoke-runbook.md` for the controlled pre-launch check. Do not restate the full
procedure here.

Summary only: the live smoke is opt-in, default-skipped, read-only, and DB-free. It can validate
credentials, transport, parse behavior, and redaction against operator-supplied placeholder values
such as `<operator-provided-wsdl-url>`, `<institution-id>`, and `<consumer-number>`. It never posts
a transaction and never marks a Blesta invoice paid.

## 5. Credential rotation

Gateway password fields are write-only. Operators must re-enter **both** the voucher password and
the inquiry password every time settings are saved.

Do not document or expect "leave blank to keep existing password." A blank required password fails
validation before `setMeta()` runs, so old meta survives untouched and the settings change is
rejected. The detailed explanation lives in `deployment-guide.md` §4 and `blesta-footguns.md` traps
A and B.

## 6. First-week reconciliation monitoring

Watch these signals during the first production week:

| Signal | Expected |
|---|---|
| `reconcile_pending` | Runs about every 5 minutes without transport/credential errors. Benign lock-held skips are acceptable. |
| `post_confirmed` | Runs about every 5 minutes and moves eligible `confirmed_unposted` vouchers to `posted`. |
| `expire_vouchers` | Runs about every 60 minutes and expires only eligible pending/retry vouchers. |
| Reconciliation Runs | New runs show `status=completed` unless skipped for a benign reason such as an existing lock. |
| Voucher status progression | Yesterday's `confirmed_unposted` rows should not sit indefinitely; they should become `posted` or surface a clear review/failure reason. |

Use `reconciliation-runbook.md` for the run-summary counters, screens, and the exact meaning of
each status.

## 7. First-week Manual Review checks

Manual Review is a safe, recoverable, dead-end state. It is not itself a failure.

Critical guardrails:

| Guardrail | Operator meaning |
|---|---|
| Only `posted` means paid | Never tell a customer a `manual_review` voucher is paid. |
| No force-paid action exists | Operators cannot force-post underpayment, overpayment, late payment, duplicate, or unmatched evidence inside KuickPay. |
| Manual Review action is Cancel only | Once a voucher is already in `manual_review`, the only in-plugin action is **Cancel**, and it requires a note. |
| Investigate, then resolve safely | Read diagnostics and validation reasons, let normal flow re-resolve where applicable, or Cancel and have the customer reissue. |

Use `reconciliation-runbook.md` §6 and `support-troubleshooting.md` for the action matrix, safe
status table, and sanitized escalation evidence.

## 8. Rollback readiness

Before launch, operations must confirm:

| Item | Confirmation |
|---|---|
| Gateway rollback understood | Team knows disabling/uninstalling the gateway removes stored gateway meta credentials. |
| In-flight voucher decision understood | Team knows to drain `pending` / `confirmed_unposted` vouchers first or knowingly accept they may be stranded. |
| Cron disable understood | Team knows disabling cron task runs preserves data and keeps the plugin UI available. |
| Evidence preservation understood | Team knows the six evidence tables are preserved on plugin uninstall. |
| Ledger boundary understood | Team knows rollback does not reverse posted Blesta transactions. |

The executable rollback procedure is `rollback-runbook.md`.

## 9. Honest-reporting notes (NFR12)

- Verified against source at baseline `b6d4ef4d`: shipped cron keys/intervals, Manual Review
  action constraints, gateway password re-entry rule, fixture approval status, and runtime notes
  from the existing KuickPay docs.
- Operator must confirm in their own environment: approved production Blesta version, runtime,
  credentials, endpoint, staff owners, launch date, and fixture gate decision.
- This checklist uses placeholders only. It does not include real endpoint hosts, credentials,
  Institution IDs, raw SOAP payloads, customer PII, or database/config values.

## See also

- `docs/kuickpay/rollback-runbook.md` — rollback procedure and evidence-preservation rules.
- `docs/kuickpay/upgrade-runbook.md` — upgrade order and post-upgrade verification.
- `docs/kuickpay/deployment-guide.md` — install/configuration, credentials, endpoint, runtime.
- `docs/kuickpay/reconciliation-runbook.md` — cron, Check Now, Bulk, runs, Manual Review.
- `docs/kuickpay/support-troubleshooting.md` — customer-claim handling and safe status labels.
- `docs/kuickpay/phase-0-contract.md` — Phase 0 gates and production-version confirmation.
- `docs/kuickpay/live-smoke-runbook.md` — opt-in controlled real-provider smoke.
- `docs/kuickpay/testing-fixtures.md` — fixture provenance and approval status.
