# Addendum: KuickPay Blesta Payment Gateway

This addendum preserves implementation-facing detail that is useful for architecture, epics, stories, and engineering handoff but too specific for the PRD body.

## A. External Interface Notes

### A.1 KuickPay SOAP Operations

Required for MVP:

- `InsertVoucher` - create/register a payable Voucher for a Blesta invoice Payment Attempt.
- `BillPaymentInquiry` - query one Consumer Number and confirm payment status.
- `BillPaymentBulkInquiry` - reconcile payments by Institution ID and transaction date as a daily/audit safety net.

Optional or future:

- `Echo` - setup/connectivity check if credentials permit.
- `GetInstitutionsList` - institution discovery or validation if credentials permit.
- `PaymentInquiry` - clarify with KuickPay before relying on it.
- `sendEmail` and `sendSMS` - out of MVP; Blesta should own customer communication unless later approved.

### A.2 InsertVoucher Field Mapping

| KuickPay field | Blesta mapping |
| --- | --- |
| `userName` | Voucher API username Admin Setting |
| `password` | Voucher API password Admin Setting, encrypted |
| `InstitutionID` | Institution ID Admin Setting |
| `RegistrationNumber` | Generated Registration Number |
| `Head1` | Payment head label Admin Setting |
| `Amount1` | PKR payable amount |
| `Head2` to `Head10` | Empty for MVP unless a future fee policy requires separate heads |
| `Amount2` to `Amount10` | Empty or zero for MVP unless a future fee policy requires separate heads |
| `TotalAmount` | Same as `Amount1` for MVP |
| `DueDate` | Configured due date policy |
| `AmountAfterDueDate` | Same as payable amount unless late policy is configured |
| `ExpiryDate` | Configured expiry date policy |
| `IssueDate` | Invoice date or generated date policy |
| `VoucherMonth` | Month derived from due date |
| `VoucherYear` | Year derived from due date |
| `Name` | Client name or company |
| `Mobile` | Sanitized Pakistani mobile number or configured fallback |
| `Email` | Client email, with configured fallback/override if needed |
| `Branch` | Client state or configured default branch |

### A.3 Consumer Number Rule

The MVP default should store both:

```text
registration_number = random_prefix + invoice_id
consumer_number = institution_id + registration_number
```

Do not assume this rule is universal until KuickPay confirms it for the merchant. Keep both formats configurable.

## B. Parser Contract Notes

Parser output should normalize raw KuickPay responses into a structure equivalent to:

```php
[
    'success' => true,
    'status' => 'pending|paid|failed|expired|manual_review',
    'consumer_number' => '...',
    'registration_number' => '...',
    'voucher_id' => '...',
    'auth_id' => '...',
    'transaction_id' => '...',
    'transaction_ref' => '...',
    'amount' => '...',
    'payment_date' => '...',
    'message' => '...',
    'raw' => '...'
]
```

Known behavior to validate with fixtures before production Payment Posting:

- `InsertVoucherResult` is a raw string where status code `00` has been observed as voucher creation success.
- Voucher ID has been observed at `substr(result, 3, 14)`.
- `BillPaymentInquiryResult` is a comma-separated string where field `0` equal to `00` has been observed as paid.
- Payment date has been observed at field `2`, paid amount at field `3`, and transaction reference components at fields `1`, `4`, and `5`.
- `BillPaymentBulkInquiryResult` returns an XML dataset containing `Consumer_Number` rows.

Any response not covered by sanitized fixture evidence must map to retry or Manual Review, not paid.

## C. Suggested Data Model

### C.1 `kuickpay_vouchers`

Recommended fields:

- `id`
- `company_id`
- `gateway_id`
- `client_id`
- `invoice_ids`
- `invoice_amounts_json`
- `currency`
- `amount`
- `amount_after_due_date`
- `voucher_fee`
- `posted_gateway_fee`
- `registration_number`
- `registration_random`
- `consumer_number`
- `institution_id`
- `voucher_id`
- `auth_id`
- `payment_channel`
- `status`
- `issue_date`
- `due_date`
- `expiry_date`
- `payment_date`
- `kuickpay_transaction_id`
- `kuickpay_transaction_ref`
- `blesta_transaction_id`
- `insert_request_hash`
- `insert_response_raw`
- `insert_status_code`
- `last_inquiry_at`
- `last_inquiry_response_raw`
- `last_inquiry_status_code`
- `last_bulk_seen_at`
- `last_error`
- `admin_note`
- `created_at`
- `updated_at`

Recommended uniqueness/index behavior:

- Unique Registration Number.
- Unique Consumer Number.
- Unique KuickPay transaction reference where present.
- Index status, company/status, client, Consumer Number, payment date, and Blesta transaction ID.

### C.2 `kuickpay_reconciliation_runs`

Recommended fields:

- `id`
- `company_id`
- `run_type`
- `status`
- `started_at`
- `completed_at`
- `vouchers_checked`
- `payments_posted`
- `raw_summary`
- `error_message`

Recommended run types:

- `single`
- `cron`
- `bulk`
- `manual`

## D. Suggested Extension Shape

Gateway:

```text
components/gateways/nonmerchant/kuickpay/
  config.json
  kuickpay.php
  lib/
    kuickpay_client.php
    kuickpay_parser.php
    kuickpay_exceptions.php
  language/
    en_us/
      kuickpay.php
  views/
    default/
      process.pdt
      settings.pdt
      images/
        logo.png
```

Companion plugin, if needed for cron/admin screens:

```text
plugins/kuickpay_reconcile/
  config.json
  kuickpay_reconcile_plugin.php
  controllers/
    admin_manage_plugin.php
  models/
    kuickpay_vouchers.php
    kuickpay_reconciliation_runs.php
  language/
    en_us/
      kuickpay_reconcile_plugin.php
  views/
    default/
      admin_vouchers.pdt
      admin_voucher_detail.pdt
```

The architecture workflow should decide whether reconciliation belongs entirely inside gateway install/upgrade hooks or needs the companion plugin for scheduled tasks and admin screens.

## E. Suggested Implementation Sequence

1. Validate KuickPay credentials, operations, response formats, date formats, status codes, and sanitized fixtures.
2. Define the parser contract and fixture-backed tests.
3. Create the non-merchant gateway skeleton and metadata.
4. Implement required Blesta gateway methods and secure Admin Settings.
5. Implement SOAP client wrapper with timeout, TLS, credential selection, and sanitized logging.
6. Implement Voucher generation, idempotency, persistence, and customer payment page.
7. Implement Pending Voucher Reconciliation and Payment Posting.
8. Implement Bulk Reconciliation and expiry handling.
9. Implement admin Voucher list, detail, Check Now, cancellation, Manual Review, and run summaries.
10. Add unit tests, optional live tests, deployment guide, rollback notes, and support documentation.

## F. Project Context Guardrails

- Target PHP 8.2. Do not introduce PHP 8.3+ syntax or assumptions.
- Preserve Blesta extension boundaries and loader patterns.
- Keep user-facing text in language files.
- Use Blesta validation and error patterns.
- Do not edit Blesta core for this gateway.
- Do not copy credentials or environment-specific values into docs, tests, logs, examples, or fixtures.
- Root Composer tests expect a sibling `../tests` suite that may not exist; use targeted fallback verification when unavailable.

## G. Handoff Prompt for Implementation

```text
You are implementing a Blesta custom payment integration for KuickPay.

Read the PRD and addendum fully before coding.

Primary objective:
Create an installable Blesta non-merchant gateway named KuickPay that can generate KuickPay Vouchers and Consumer Numbers for Blesta invoices, show payment instructions, reconcile confirmed payments, and post safe Blesta transactions.

Hard constraints:
- Do not modify Blesta core files.
- Use Blesta extension conventions and PHP 8.2-compatible code.
- Store KuickPay voucher and inquiry passwords encrypted.
- Never hard-code production credentials, institution ID, fallback phone, URLs, fees, or notification recipients in PHP business logic.
- Never log API passwords.
- Fail closed: unknown KuickPay response must become Manual Review or retry, never paid.
- Prevent duplicate Vouchers and duplicate Blesta transactions.
- Support PKR only for MVP unless product approves conversion.
- Default Consumer Number format is Institution ID plus Registration Number.
- Default Registration Number format is random prefix plus invoice ID.
- Implement parser from sanitized live or sandbox fixtures before final Payment Posting logic.
- Preserve known fixture-backed behavior only after tests prove it.

Start with API contract validation and parser fixtures, then implement the gateway skeleton, Admin Settings, SOAP client, Voucher generation, Reconciliation, admin operations, tests, and deployment docs.
```
