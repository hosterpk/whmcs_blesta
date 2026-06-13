# KuickPay Story 5.1 — Live Verification Risk Acceptance

- **Date:** 2026-06-13
- **Scope:** Story 5.1 (the live-verification gate, deferred across Epics 1–4).
- **Environment:** local Blesta + MySQL on `beta.hosterpk.com` (pre-dev, not live), production runtime
  **PHP 8.3 (ea-php83, ionCube 15)**; `8.2` is the Composer source-compatibility floor only.
- **Evidence:** `docs/kuickpay/live-verification-evidence.md` (+ `5-1-evidence/` raw captures).

This document records, for sign-off, exactly what Story 5.1 **verified by execution** and what **ships to
production unverified** (accepted risk). It supersedes the originally-anticipated "ship the test harness but
never run it" outcome — the gate was actually run.

## Verified by live execution (no longer a residual)

- **AC1** — Real schema install + a genuine **1.4.0 → 1.8.0** upgrade through `PluginManager` against the live
  DB; permission/action re-sync proven (permissions 1 → 8, nav 1 → 4); no schema/data loss.
- **AC2** — A real **`confirmed_unposted → posted`** round-trip on a genuinely bank-paid voucher: real
  `BillPaymentInquiry` to the production KuickPay endpoint (valid credentials, `raw_status 00`), real Blesta
  transaction created and applied to the invoice, and **idempotency** proven (no duplicate, no
  double-allocation). This also exercised the real single-inquiry leg of the opt-in live smoke (Story 5.7).
- **AC2 fixture-backed harness** — a guarded repeatable harness is committed at
  `plugins/kuickpay_reconcile/tests/integration/live_fixture_round_trip.php`; it requires an operator-selected
  disposable invoice and explicit confirmation before creating a real transaction.
- **AC3** — **Check Now / Cancel / Mark Manual Review** driven through the real admin controller (live
  routing → ACL → CSRF), the **two-group ACL separation** proven (a `*`-only group is denied
  recheck/review/cancel/diagnostics), and durable, redacted **audit events** written.
- **PHP 8.2 source-floor**: both component suites run green under ea-php82 (gateway 233/1256 with the one
  known pre-existing `empty-currency` baseline red; plugin 158/1127).
- **Bugs found and fixed live**: missing
  `KuickPayVoucherRepository::getForCompany()` (Check Now fatal); over-strict `currencyMatches()` (PKR-only
  provider sends no currency); wrong-identity `referenceMatches()` (consumer echo vs stored registration).
  Plus the restored admin `unauthorized.pdt` (blank → clean message on denial), targeted validator tests,
  and the staff-group
  permission-grant step.

## Residuals shipping unverified — ACCEPTED

1. **Bulk reconciliation (`BillPaymentBulkInquiry`) against the real provider** — only the single-inquiry
   path was exercised live; the bulk path is covered by fixtures/unit tests only.
2. **Lifecycle edge cases against the real provider** — late / partial / overpayment / expiry /
   duplicate-reference are covered by fixtures and unit tests, not by a live provider run.
3. **Real `InsertVoucher` (voucher creation)** — proven in practice by the account owner's real checkout
   flow on a redacted pre-dev voucher, but not by a repeatable automated test in this effort.
4. **AC1 fresh install / full schema-index capture evidence gap** — the naturally-behind plugin upgrade and
   permission/action re-sync were proven, but a fresh gateway install path and full `SHOW CREATE TABLE` index
   captures for all six `kuickpay_*` tables were not captured in this artifact.
5. **AC3 GET no-mutation evidence gap** — POST-without-CSRF rejection was captured, but a durable record of
   GET requests to mutation routes not mutating state still needs a rerun.
6. **Pre-existing gateway baseline red** — the `bill-payment-inquiry-empty-currency` fail-closed-contract
   test remains failing (1 test); unrelated to the behavior verified here.
7. **Restored core view** `app/views/admin/paradigm/unauthorized.pdt` is a local patch to the Blesta core
   view tree (a missing-file restoration); a future Blesta upgrade may supersede it.
8. **Operational step**: after install/upgrade, the plugin's permissions must be granted to each staff group
   that needs access (not automatic). Belongs in the 5.8 deploy docs.

## Out of Story 5.1 scope — still gate production (separate Epic 5 stories)

5.2 schema-level active-context concurrency guard · 5.3 reconcile/posting safety hardening (incl. the
`persistEvidence():435` status-guard) · 5.4 audit/redaction completeness · 5.5 structural company-scoping +
test fidelity · 5.6 gateway/endpoint hardening · 5.7 full opt-in live smoke (InsertVoucher + bulk) ·
5.8–5.10 documentation. These remain open and are **not** covered by this acceptance.

## Sign-off

By signing, the project lead accepts the residuals in the sections above as shipping to production
unverified for the purposes of Story 5.1.

- **Project Lead (Israr):** ISRAR UL HAQ  **Date:** 13TH JUN 2026
