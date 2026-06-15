# KuickPay Live Smoke Runbook

Date: 2026-06-16
Scope: Story 5.7 opt-in real-provider smoke.

This is the one sanctioned real KuickPay provider check. KuickPay provides no
sandbox for this merchant, so the smoke is manual, read-only, CLI-only, and
default-skipped. The automated PHPUnit suite must never call the live endpoint.

## Safety Contract

- The smoke runs only when `KUICKPAY_LIVE_SMOKE=1` and all required connection
  environment variables are present.
- The smoke is a standalone CLI script, not a PHPUnit `*Test.php` file.
- The script refuses non-CLI execution before loading code or reading env.
- The gateway `tests/` directory is web-denied by `.htaccess`.
- The default operation is `BillPaymentInquiry`, which is read-only.
- `Echo` and `GetInstitutionsList` are selectable lighter read-only operations.
- `InsertVoucher` is never used because it creates a payable voucher.
- The script never opens the database and never calls posting code. No voucher,
  invoice, transaction, or allocation row can be created or changed by this
  smoke.

## Required Environment

Set these values in protected operator-controlled shell state only. Do not put
them in committed files, ticket text, persistent shell history, screenshots, or
logs.

| Variable | Required | Purpose |
|---|---:|---|
| `KUICKPAY_LIVE_SMOKE=1` | yes | Master opt-in switch. |
| `KUICKPAY_SMOKE_WSDL_URL` | yes | Operator-confirmed production WSDL URL. |
| `KUICKPAY_SMOKE_INQUIRY_USERNAME` | yes | Inquiry username. |
| `KUICKPAY_SMOKE_INQUIRY_PASSWORD` | yes | Inquiry password. |
| `KUICKPAY_SMOKE_INSTITUTION_ID` | yes | Institution ID. |
| `KUICKPAY_SMOKE_CONSUMER_NUMBER` | yes | Read-only test reference for inquiry. |
| `KUICKPAY_SMOKE_OPERATION` | no | `BillPaymentInquiry`, `Echo`, or `GetInstitutionsList`; default is `BillPaymentInquiry`. |
| `KUICKPAY_SMOKE_TIMEOUT` | no | SOAP timeout in seconds, 1-300; default is 30. |
| `KUICKPAY_SMOKE_CAPTURE` | no | Existing/local path for a sanitized redacted envelope capture. |

Recommended shell pattern:

```sh
set +o history
export KUICKPAY_LIVE_SMOKE=1
export KUICKPAY_SMOKE_WSDL_URL='<operator-provided-wsdl-url>'
export KUICKPAY_SMOKE_INQUIRY_USERNAME='<operator-provided-username>'
export KUICKPAY_SMOKE_INQUIRY_PASSWORD='<operator-provided-password>'
export KUICKPAY_SMOKE_INSTITUTION_ID='<operator-provided-institution-id>'
export KUICKPAY_SMOKE_CONSUMER_NUMBER='<operator-provided-test-reference>'
/usr/local/bin/php components/gateways/nonmerchant/kuickpay/tests/live/kuickpay_live_smoke.php
unset KUICKPAY_LIVE_SMOKE KUICKPAY_SMOKE_WSDL_URL KUICKPAY_SMOKE_INQUIRY_USERNAME
unset KUICKPAY_SMOKE_INQUIRY_PASSWORD KUICKPAY_SMOKE_INSTITUTION_ID KUICKPAY_SMOKE_CONSUMER_NUMBER
set -o history
```

## Reading Output

The script prints JSON containing only safe fields:

- transport: `ok`, `operation`, `error_class`, redacted `fault`,
  `redacted_trace_id`, `duration_ms`, `attempt`, `attempts`
- evidence: `status`, `error_class`, `evidence_hash`, `redacted_trace_id`,
  `validation_errors`, `operation`, `is_confirmed_unposted`
- `no_invoice_paid: true`

It does not print the WSDL URL, credentials, Institution ID, consumer number,
raw SOAP result, unredacted envelope, amount, paid date, customer contact data,
or Blesta database values.

Exit codes:

- `0`: skipped safely, or a reachable non-credential provider response was
  transported, parsed, and redacted.
- `1`: transport failure or credential failure.

An unmatched or unknown test reference can still be a valid smoke result if the
transport is reachable and credentials are accepted. This smoke validates
credentials, transport, parsing, and redaction. It intentionally does not verify
posting or database effects.

## Sanitized Capture

If `KUICKPAY_SMOKE_CAPTURE` is set, the script writes only the redacted response
envelope exposed by `KuickPaySoapClient`. It never writes `raw_result`.

Before committing or documenting any operator-captured fixture:

1. Store it under `docs/kuickpay/fixtures/bill-payment-inquiry/`.
2. Confirm it contains no password, username, Institution ID, WSDL host,
   customer PII, raw result payload, or unredacted SOAP value.
3. Run the gateway guard test:

```sh
cd components/gateways/nonmerchant/kuickpay
/usr/local/bin/php /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/KuickPayLiveSmokeGuardTest.php
```

Do not commit a real captured live response unless the forbidden-pattern scan is
clean and the fixture has been manually reviewed.
