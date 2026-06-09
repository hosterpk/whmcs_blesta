# KuickPay Phase 0 Contract Confirmation

Date: 2026-06-09
Artifact owner: Dev Agent
Gate owner: Operations / human approver

## Scope

This Phase 0 artifact confirms the KuickPay integration contract structure and records fixture evidence before voucher success handling, parser work, reconciliation, or payment posting is built.

This story introduces no runtime PHP business logic, gateway, plugin, parser, SOAP client, schema, posting code, Composer change, or application configuration change.

## Contract Fields

| Field | Status | Value / Decision | Notes |
|---|---|---|---|
| `production_blesta_version` | `UNCONFIRMED - requires operations confirmation` | Candidate production target should be Blesta 5.13 stable unless operations explicitly approves a different supported target. This checkout is `6.0.0-b1`. | Repo evidence shows this checkout is Blesta `6.0.0-b1`; research flags 6.0 Beta 1 as non-production/unsupported. Do not assume the beta checkout is production. |
| `kuickpay_soap_endpoint.production` | `UNCONFIRMED` | Admin Setting required. Example only: `https://app.kuickpay.com/kuickpaycoreapi/api.asmx`. | The public ASMX base is documentation evidence only, not a production default. |
| `kuickpay_wsdl_url.production` | `UNCONFIRMED` | Admin Setting required. Example only: `https://app.kuickpay.com/kuickpaycoreapi/api.asmx?WSDL`. | Stored separately from the SOAP service URL because `SoapClient` consumes the WSDL document. |
| `kuickpay_soap_endpoint.sandbox` | `UNCONFIRMED` | Admin Setting required. | Must come from KuickPay sandbox onboarding or merchant support. |
| `kuickpay_wsdl_url.sandbox` | `UNCONFIRMED` | Admin Setting required. | Must come from KuickPay sandbox onboarding or merchant support. |
| `date_format_due` | `UNCONFIRMED` | Conservative default: centrally normalize to `yyyyMMdd` until KuickPay confirms. | Applies to `DueDate`. Parser/client work must keep the format configurable. |
| `date_format_expiry` | `UNCONFIRMED` | Conservative default: centrally normalize to `yyyyMMdd` until KuickPay confirms. | Applies to `ExpiryDate`. |
| `date_format_issue` | `UNCONFIRMED` | Conservative default: centrally normalize to `yyyyMMdd` until KuickPay confirms. | Applies to `IssueDate`. |
| `date_format_transaction` | `UNCONFIRMED` | Conservative default: accept only a centrally normalized configured format until confirmed. | Applies to `TransactionDate` in inquiry/reconciliation evidence. |
| `consumer_number_formula` | `UNCONFIRMED` | Candidate configurable formula: `consumer_number = institution_id + registration_number`. | Must be confirmed for HosterPK's KuickPay merchant account. |
| `registration_number_formula` | `UNCONFIRMED` | Candidate configurable formula: `registration_number = random_prefix + invoice_id`. | Keep random prefix and invoice mapping configurable. |
| `insert_voucher_result_format` | `UNCONFIRMED - internal contradiction flagged in review` | Committed fixtures encode `InsertVoucherResult` as comma-delimited (`00,<voucher_id>,<institution_id>,<registration_number>`). The observed-format note reads the voucher id at fixed offset `substr(result, 3, 14)`, which truncates a 15-char id (`KP-VOUCHER-0001` to `KP-VOUCHER-000`). | Reconcile delimiter vs fixed-offset and confirm with KuickPay before the Story 3.2 parser. Parse defensively; fail closed on ambiguity. |
| `credential_separation` | `UNCONFIRMED` | Future Admin Settings must support separate voucher and inquiry credentials plus a same-as-voucher policy. | Do not assume one credential pair serves all operations. |
| `rate_limits` | `UNAVAILABLE` | No KuickPay-stated limit is confirmed. | Use conservative default until confirmed. |
| `polling_backoff` | `UNCONFIRMED - conservative default recorded` | Single-reference inquiry only on a bounded schedule with jitter/backoff; bounded bulk reconciliation by date range; no unbounded polling loops; never blindly retry `InsertVoucher`. | Aligns with fail-closed payment safety and NFR7-style bounded processing. |

## No Hard-Coding Assertion

This Phase 0 story adds documentation and sanitized fixture artifacts only. It introduces no PHP runtime code and therefore does not hard-code any production credential, Institution ID, endpoint, fallback phone number, fee, conversion rate, or KuickPay response fallback into business logic.

All production/sandbox endpoints, WSDL URLs, credentials, Institution IDs, date formats, numbering formulas, and polling controls must be future Admin Settings or centrally configured values.

## Gate Status

| Field | Value | Notes |
|---|---|---|
| `artifact_status` | `COMPLETE` | Required Phase 0 documents and provisional sanitized fixtures are present and passed local verification. |
| `gate_approval_status` | `PENDING_HUMAN_APPROVAL` | Dev agent must not self-approve this gate. |
| `gate_approval_date` |  | Reserved for human sign-off. |
| `gate_approved_by` |  | Reserved for human sign-off. |

Payment posting remains `DISABLED` until `gate_approval_status` is `APPROVED` by a human approver. Unknown, malformed, ambiguous, or unverified KuickPay status codes map to `retry` or `manual_review`, never `posted`.

## Operator Approval Checklist

- [ ] Blesta production version confirmed and support posture accepted.
- [ ] Production SOAP endpoint confirmed.
- [ ] Production WSDL URL confirmed.
- [ ] Sandbox SOAP endpoint confirmed, if sandbox is available.
- [ ] Sandbox WSDL URL confirmed, if sandbox is available.
- [ ] Due date format confirmed.
- [ ] Expiry date format confirmed.
- [ ] Issue date format confirmed.
- [ ] Transaction date format confirmed.
- [ ] HosterPK Consumer Number formula confirmed.
- [ ] HosterPK Registration Number formula confirmed.
- [ ] Voucher credential policy confirmed.
- [ ] Inquiry credential policy confirmed.
- [ ] Rate limits or conservative operating limits accepted.
- [ ] All required fixtures replaced with live or sandbox captures where available.
- [ ] Every fixture row in `docs/kuickpay/testing-fixtures.md` has complete provenance.
- [ ] Every approval-gate fixture has `verification_status: verified` from `live` or `sandbox` evidence.
- [ ] Payment posting enablement remains blocked until this checklist is approved.

## Approval Notes

The fixture set currently uses `synthetic_from_observed_format` evidence because no live or sandbox credentials/captures were available to this agent. These fixtures can support parser development, but they do not close the release gate.
