# WHMCS Live KuickPay Implementation Evidence

Date: 2026-06-09

## Scope

Existing live WHMCS implementation was reviewed under:

`/home/hosterpk/public_html/clientarea/`

This document records sanitized product/contract evidence only. It intentionally
does not copy credentials, Institution ID values, customer data, notification
keys, raw payloads, or live response bodies.

## Evidence Summary

| Area | Finding | Evidence |
|---|---|---|
| Voucher creation operation | Existing WHMCS implementation calls `InsertVoucher`. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:108`, `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:203` |
| Production WSDL use | Existing WHMCS implementation constructs `SoapClient` with the KuickPay production WSDL URL. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:69`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:234`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:41` |
| InsertVoucher fields | Existing request includes credential fields, Institution ID, Registration Number, heads/amounts, due/expiry/issue dates, voucher month/year, customer name/mobile/email, and branch. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:165` |
| Registration Number formula | Existing WHMCS implementation builds Registration Number as four-digit prefix + invoice id. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:169`, `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php:76`, `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php:113` |
| Consumer Number formula | Existing WHMCS implementation builds Consumer Number as Institution ID + four-digit prefix + invoice id. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:104`, `/home/hosterpk/public_html/clientarea/modules/gateways/kuickpaymobileapps.php:109` |
| Voucher date formats | Existing WHMCS implementation sends voucher dates from PHP `date("d-M-y", ...)`. | `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php:44`, `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php:46`, `/home/hosterpk/public_html/clientarea/includes/hooks/ecommerce_UpdateInvoiceTotal.php:47` |
| Bulk inquiry date format | Existing WHMCS implementation sends bulk `TransactionDate` as PHP `date('Ymd')`. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:42`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:49` |
| InsertVoucher response parsing | Existing WHMCS implementation treats `InsertVoucherResult` as raw fixed-position text: status from first 2 chars, voucher id from offset 3 length 14. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:216`, `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:217` |
| Single inquiry operation | Existing WHMCS implementation calls `BillPaymentInquiry`. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:241` |
| Single inquiry response parsing | Existing WHMCS implementation splits `BillPaymentInquiryResult` by comma and treats field 0 as status. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:107`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:108`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:243` |
| Paid status behavior in WHMCS | Existing WHMCS implementation treats inquiry status `00` as paid and posts WHMCS payment. Blesta must preserve the safer `confirmed_unposted` boundary before posting. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:116`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:166` |
| Bulk inquiry operation | Existing WHMCS implementation calls `BillPaymentBulkInquiry`. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:51` |
| Bulk response parsing | Existing WHMCS implementation reads `BillPaymentBulkInquiryResult['any']` as XML and iterates dataset rows. | `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:54`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:58`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:89` |
| Credential separation | Existing WHMCS implementation uses one credential pair for voucher creation and another for inquiry/bulk. | `/home/hosterpk/public_html/clientarea/includes/hooks/kuickpayhelper.php:165`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheck.php:236`, `/home/hosterpk/public_html/clientarea/includes/hooks/z-kuickpaycheckbulk.php:45` |

## Product Implications

- KuickPay has no sandbox for this merchant, so verification must be live-only,
  guarded, and opt-in.
- The current WHMCS implementation is strong evidence for HosterPK's existing
  working contract, but it is not a substitute for sanitized response fixtures.
- Blesta must not copy WHMCS hard-coding. Endpoint, WSDL, Institution ID,
  credentials, date formats, fees, fallback contact values, and polling controls
  belong in encrypted or protected Admin Settings/configuration.
- Blesta must not copy WHMCS' immediate posting shortcut. A `00` inquiry status
  should become `confirmed_unposted` only after amount, currency, and exact
  Consumer Number validation; posting remains isolated to the posting service.

