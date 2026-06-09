# KuickPay Phase 0 Contract Confirmation

Date: 2026-06-09
Artifact owner: Dev Agent
Gate owner: Operations / human approver

Evidence update: 2026-06-09 — existing live WHMCS implementation reviewed under
`/home/hosterpk/public_html/clientarea/`. The WHMCS code is live-production
implementation evidence, but not a sanitized live KuickPay response capture.
It confirms current HosterPK behavior and removes several design unknowns, while
still requiring sanitized response fixtures before gate approval.

## Scope

This Phase 0 artifact confirms the KuickPay integration contract structure and records fixture evidence before voucher success handling, parser work, reconciliation, or payment posting is built.

This story introduces no runtime PHP business logic, gateway, plugin, parser, SOAP client, schema, posting code, Composer change, or application configuration change.

## Contract Fields

| Field | Status | Value / Decision | Notes |
|---|---|---|---|
| `production_blesta_version` | `UNCONFIRMED - requires operations confirmation` | Candidate production target should be Blesta 5.13 stable unless operations explicitly approves a different supported target. This checkout is `6.0.0-b1`. | Repo evidence shows this checkout is Blesta `6.0.0-b1`; research flags 6.0 Beta 1 as non-production/unsupported. Do not assume the beta checkout is production. |
| `kuickpay_soap_endpoint.production` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Current WHMCS code uses KuickPay production ASMX over HTTPS. Store as Admin Setting in Blesta; do not hard-code. | Evidence: existing WHMCS `SoapClient` calls in `includes/hooks/kuickpayhelper.php`, `includes/hooks/z-kuickpaycheck.php`, and `includes/hooks/z-kuickpaycheckbulk.php`. |
| `kuickpay_wsdl_url.production` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Current WHMCS code uses the production ASMX WSDL URL. Store separately from the SOAP endpoint as an Admin Setting. | Evidence: existing WHMCS `SoapClient` calls consume the WSDL URL directly. |
| `kuickpay_soap_endpoint.sandbox` | `NOT_AVAILABLE` | KuickPay does not provide sandbox for this merchant per operator input. | Use live-only guarded verification and never run unsafe live calls from automated tests. |
| `kuickpay_wsdl_url.sandbox` | `NOT_AVAILABLE` | KuickPay does not provide sandbox for this merchant per operator input. | Use live-only guarded verification and sanitized captured responses. |
| `date_format_due` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Existing WHMCS voucher creation sends `d-M-y` formatted dates, e.g. PHP `date("d-M-y", ...)`. | Applies to `DueDate`; keep configurable in Blesta. |
| `date_format_expiry` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Existing WHMCS voucher creation sends `d-M-y` formatted dates, e.g. PHP `date("d-M-y", ...)`. | Applies to `ExpiryDate`; keep configurable in Blesta. |
| `date_format_issue` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Existing WHMCS voucher creation sends `d-M-y` formatted dates from invoice date. | Applies to `IssueDate`; keep configurable in Blesta. |
| `date_format_transaction` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION_FOR_BULK_REQUEST` | Existing WHMCS bulk inquiry sends `TransactionDate` as `Ymd`. Single-inquiry paid date response is parsed with `strtotime`, so exact returned format remains response-fixture dependent. | Applies to `BillPaymentBulkInquiry` request. |
| `consumer_number_formula` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Current HosterPK formula: `consumer_number = institution_id + random_prefix + invoice_id`. | The operator-facing format is a 5-digit Institution ID plus 4-digit random prefix plus invoice id. Keep components configurable. |
| `registration_number_formula` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Current HosterPK formula: `registration_number = random_prefix + invoice_id`. | Existing code uses either a random 4-digit prefix or a deterministic amount-based prefix for credit-adjusted invoices. |
| `insert_voucher_result_format` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION_NEEDS_RESPONSE_FIXTURE` | Existing WHMCS code treats `InsertVoucherResult` as a raw string where first 2 chars are status and voucher id is read using `substr(result, 3, 14)`. | The previous comma-delimited provisional fixture conflicts with the live implementation and must be replaced with a sanitized response-derived fixture before Story 3.2 parser approval. |
| `bill_payment_inquiry_result_format` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION_NEEDS_RESPONSE_FIXTURE` | Existing WHMCS code treats `BillPaymentInquiryResult` as comma-separated fields: status at field 0, transaction id component at field 1, paid date at field 2, amount at field 3, additional transaction-reference components at fields 4 and 5. | `00` is treated as paid in WHMCS, but Blesta must still require exact amount/currency/reference validation before `confirmed_unposted`. |
| `bill_payment_bulk_result_format` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION_NEEDS_RESPONSE_FIXTURE` | Existing WHMCS code reads `BillPaymentBulkInquiryResult['any']` as an XML dataset and iterates rows containing `Consumer_Number`. | Blesta parser must validate XML structure before matching rows. |
| `credential_separation` | `CONFIRMED_FROM_EXISTING_WHMCS_IMPLEMENTATION` | Existing WHMCS code uses separate credential pairs for voucher creation versus inquiry/bulk operations. | Credentials are hard-coded in WHMCS; Blesta must store them as encrypted Admin Settings and mask them in UI/logs. |
| `rate_limits` | `UNAVAILABLE` | No KuickPay-stated limit is confirmed. | Use conservative default until confirmed. |
| `polling_backoff` | `UNCONFIRMED - conservative default recorded` | Single-reference inquiry only on a bounded schedule with jitter/backoff; bounded bulk reconciliation by date range; no unbounded polling loops; never blindly retry `InsertVoucher`. | Aligns with fail-closed payment safety and NFR7-style bounded processing. |

## No Hard-Coding Assertion

This Phase 0 story adds documentation and sanitized fixture artifacts only. It introduces no PHP runtime code and therefore does not hard-code any production credential, Institution ID, endpoint, fallback phone number, fee, conversion rate, or KuickPay response fallback into Blesta business logic.

All production/sandbox endpoints, WSDL URLs, credentials, Institution IDs, date formats, numbering formulas, and polling controls must be future Admin Settings or centrally configured values.

The existing WHMCS implementation does contain hard-coded KuickPay credentials,
Institution ID, endpoint/WSDL, fallback phone handling, and operational fees.
Those values are live evidence only and must not be copied into Blesta source,
fixtures, logs, docs, or commits.

## Gate Status

| Field | Value | Notes |
|---|---|---|
| `artifact_status` | `COMPLETE_WITH_IMPLEMENTATION_EVIDENCE` | Required Phase 0 documents and provisional sanitized fixtures are present; existing live WHMCS implementation has been reviewed and summarized. |
| `gate_approval_status` | `APPROVED` | Human approver accepted WHMCS-derived KuickPay contract shapes as sufficient Phase 0 evidence for Blesta parser development. |
| `gate_approval_date` | `2026-06-09` | Human sign-off date. |
| `gate_approved_by` | `Israr` | Human approver. |

Payment posting remains `DISABLED` until the separate posting controls and Epic 3 posting service are implemented and approved. Unknown, malformed, ambiguous, or unverified KuickPay status codes map to `retry` or `manual_review`, never `posted`.

## Operator Approval Checklist

- [ ] Blesta production version confirmed and support posture accepted.
- [x] Production SOAP endpoint confirmed from existing WHMCS implementation.
- [x] Production WSDL URL confirmed from existing WHMCS implementation.
- [x] Sandbox unavailable per operator input.
- [x] Due date format confirmed from existing WHMCS implementation.
- [x] Expiry date format confirmed from existing WHMCS implementation.
- [x] Issue date format confirmed from existing WHMCS implementation.
- [x] Bulk TransactionDate request format confirmed from existing WHMCS implementation.
- [x] HosterPK Consumer Number formula confirmed from existing WHMCS implementation.
- [x] HosterPK Registration Number formula confirmed from existing WHMCS implementation.
- [x] Voucher credential policy confirmed from existing WHMCS implementation.
- [x] Inquiry/bulk credential policy confirmed from existing WHMCS implementation.
- [x] Rate limits unavailable; conservative operating limits accepted.
- [x] KuickPay sandbox unavailable; human approver accepted WHMCS-derived fixture shapes instead of committed live response bodies.
- [x] Every fixture row in `docs/kuickpay/testing-fixtures.md` has provenance and remains clearly marked as provisional where not response-captured.
- [x] Approval-gate evidence accepted by human approver from existing live WHMCS implementation, with known limitation that sanitized live response bodies are not committed.
- [x] Payment posting enablement remains blocked until the separate Epic 3 posting service and controls are implemented and approved.

## Approval Notes

The fixture set currently uses provisional evidence. Existing live WHMCS code now
confirms the implementation contract shape, but the checked-in fixtures still
need to be replaced with sanitized response captures or explicitly approved
against sanitized live responses. These fixtures can support parser development,
but they do not close the release gate.

Human approval recorded on 2026-06-09:

Human approver reviewed the existing live WHMCS implementation and accepts the
WHMCS-derived KuickPay contract and fixture shapes as sufficient Phase 0
evidence for Blesta parser development.

- Approved by: Israr
- Approved at: 2026-06-09
- Approval scope: WHMCS-derived KuickPay contract shapes only.
- Known limitation: no sanitized live KuickPay response bodies are committed.
- Safety boundary: payment posting remains disabled until separate Epic 3
  posting controls are implemented and approved.

## Existing WHMCS Implementation Evidence

Reviewed files:

- `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php`
- `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php`
- `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php`
- `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php`
- `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_ViewInvoiceDetailsPage.php`
- `/home/hosterpk/public_html/clientarea/modules/gateways/kuickpaymobileapps.php`
- `/home/hosterpk/public_html/clientarea/modules/gateways/kuickpaybanksonline.php`
- `/home/hosterpk/public_html/clientarea/modules/gateways/kuickpaybankdeposits.php`
- `/home/hosterpk/public_html/clientarea/modules/gateways/kuickpayfranchiseshops.php`

Confirmed behaviors from existing WHMCS code:

- Voucher creation uses `InsertVoucher`.
- Single-reference reconciliation uses `BillPaymentInquiry`.
- Bulk reconciliation uses `BillPaymentBulkInquiry`.
- Consumer Number is built as Institution ID + 4-digit random prefix + invoice id.
- Registration Number is built as 4-digit random prefix + invoice id.
- `InsertVoucherResult` is parsed as a raw fixed-position string.
- `BillPaymentInquiryResult` is parsed as comma-separated fields.
- `BillPaymentBulkInquiryResult['any']` is parsed as an XML dataset with `Consumer_Number` rows.
- Voucher dates are sent as `d-M-y`; bulk transaction date is sent as `Ymd`.
- Voucher credentials and inquiry/bulk credentials are separate in the current implementation.
- WHMCS posts payment immediately after inquiry status `00`; Blesta must not copy that shortcut and must use the safer `confirmed_unposted` boundary before posting.

Security note: the WHMCS implementation contains live secrets and hard-coded
merchant values. They are intentionally excluded from this document.
