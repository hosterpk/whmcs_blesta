# KuickPay Live Smoke Verification Template

Date: 2026-06-16
Story: 5.7 Opt-In Live KuickPay Smoke (No Sandbox)

No live provider run is recorded in this commit. The smoke requires
operator-held production credentials and is intentionally post-deploy/manual.

## What This Smoke Exercises

- Real `SoapClient` construction through `KuickPaySoapClient` after explicit
  opt-in.
- Real KuickPay WSDL reachability.
- Inquiry credentials and Institution ID accepted or rejected by the provider.
- One read-only SOAP operation:
  - `BillPaymentInquiry` by default, or
  - `Echo`, or
  - `GetInstitutionsList`.
- `KuickPayResponseParser::parse()` normalization.
- Redacted, allowlisted diagnostic output.
- Optional sanitized-envelope capture.

## What This Smoke Does Not Exercise

- Voucher creation.
- Blesta database writes.
- Reconciliation cron selection.
- `KuickPayPostingService`.
- Blesta `transactions` or `transaction_applied`.
- Customer invoice status changes.

## Operator Run Record

Use this section after an authorized live run. Keep it sanitized.

| Field | Value |
|---|---|
| Date/time | `<timestamp>` |
| Environment | `<environment name, no host secrets>` |
| PHP binary | `<php binary>` |
| Operation | `<BillPaymentInquiry/Echo/GetInstitutionsList>` |
| Result | `<COMPLETED/FAILED/SKIPPED>` |
| Transport ok | `<true/false>` |
| Transport error class | `<safe token or null>` |
| Evidence status | `<safe status>` |
| Evidence error class | `<safe token or null>` |
| Evidence hash | `<hash only>` |
| Redacted trace id | `<kp_...>` |
| Validation errors | `<safe tokens>` |
| Capture committed | `<no/yes, fixture path if reviewed>` |

Do not record WSDL hosts, credentials, Institution ID, consumer numbers, raw SOAP
payloads, raw results, customer PII, amounts, paid dates, or Blesta DB values.

## Current Verification State

- Default no-opt-in path: verified by the committed guard test and direct CLI
  skip run.
- Incomplete opt-in path: verified by direct CLI skip run with missing env names
  only.
- Live provider path: not run in this environment because production credentials
  are not present here.
- Capture redaction: verified by the guard test running the real
  `KuickPayRedactor::redactEnvelope()` over an envelope of real credentials/PII
  and asserting no real value survives and the `xxxx` output passes the value
  scan. The captured artifact is a full redacted envelope, not a plugin persisted
  fixture; committing one to `docs/kuickpay/fixtures/` is gated by manual review
  (no automated scan covers that directory). See the runbook "Sanitized Capture".
- Payment safety: structurally guaranteed by the smoke being DB-free and
  read-only.
