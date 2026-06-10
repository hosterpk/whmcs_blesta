# KuickPay Payment Safety Verification

Date: 2026-06-11

This matrix maps FR28 payment-safety contract areas and Story 3.8 AC1 outcomes to concrete automated coverage. It is the durable audit artifact for the verification story; gap-closing tests live in the gateway and plugin suites.

## Live Baseline

Commands run on the beta host:

- `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` -> `OK (215 tests, 1120 assertions)`
- `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` -> `OK (81 tests, 315 assertions)`

Environment notes:

- PHP CLI used by the runner: `8.3.31`
- PHPUnit runner: `8.5.52`
- Root sibling `../tests`: unavailable in this checkout
- Live database and live SOAP smoke: not run for this verification pass

## FR28 Contract Matrix

| Contract area | Coverage | Status |
|---|---|---|
| Parser behavior | `components/gateways/nonmerchant/kuickpay/tests/KuickPayResponseParserTest.php`: `testTransportFailureMapsToRetry`, `testInsertVoucherFixtureMappings`, `testInquiryFixtureMappings`, `testBulkFixtureMappings`, `testBulkHardeningFixtures`, plus all 25 KuickPay fixtures under `plugins/kuickpay_reconcile/tests/fixtures/kuickpay/` | COVERED |
| Client mapping: SOAP wrapper | `KuickPaySoapClientTest.php`: transport failure, credential selection, endpoint handling, XML hardening, redacted diagnostics | COVERED |
| Client mapping: invoice/contact to voucher request | `KuickPayVoucherGatewayHelpersTest.php`: `testBuildVoucherRequestMapsInvoiceContactAndConfiguredPolicies`, `testBuildVoucherRequestAppliesMobileEmailAndBranchFallbacks`, `testBuildVoucherContactDataLoadsEmailMobileAndBranch` | COVERED |
| Idempotency | `plugins/kuickpay_reconcile/tests/KuickPayPostingServiceTest.php`: `testAlreadyPostedVoucherIsNoOpAfterLock`; `KuickPayReconcileServiceTest.php`: `testRunBulkAlreadyConfirmedVoucherIsNotDemotedOnRerun`, `testRunBulkDuplicateConsumerRowsDoNotDoubleConfirm`; `KuickPayVoucherReferenceServiceTest.php`: collision and uniqueness cases | COVERED |
| Duplicate prevention | `KuickPayResponseParserTest.php`: `insert-voucher-duplicate.xml`; `KuickPayEvidenceValidatorTest.php`: duplicate reference cases in `failureProvider`; `KuickPayReconcileServiceTest.php`: duplicate consumer rows; `KuickPayVoucherReferenceServiceTest.php`: reference collision/retry | COVERED |
| Status transitions | `KuickPayReconcileServiceTest.php`: pending/retry to `confirmed_unposted`, `manual_review`, `retry`, `expired`; `KuickPayPostingServiceTest.php`: confirmed to `posted`, rollback/failure to non-posted states; `KuickPayIssuanceServiceTest.php`: issue outcome statuses | COVERED, consolidated by Story 3.8 fail-closed wall |
| Amount handling | `KuickPayResponseParserTest.php`: amount mismatch, overpayment, late partial, trailing-zero; `KuickPayEvidenceValidatorTest.php`: exact amount, multi-link sum, trailing-zero minor-unit comparison; `KuickPayVoucherGatewayHelpersTest.php`: string amount normalization | COVERED |
| Secret masking | `KuickPayRedactorTest.php`: array/XML credential redaction and trace IDs; `KuickPayVoucherGatewayHelpersTest.php`: language-string forbidden-term scan; `redaction/credentials.xml` fixture | GAP CLOSED BY STORY 3.8 leak scan |
| Reference pattern generation | `KuickPayVoucherReferenceServiceTest.php`: registration/consumer number generation, collision retry, uniqueness exhausted; `KuickPayVoucherGatewayHelpersTest.php`: configured patterns in request context | COVERED |

## AC1 Outcome Matrix

| AC1 outcome | Coverage | Status |
|---|---|---|
| Unknown/unmapped responses never produce paid/confirmed | `KuickPayResponseParserTest.php`: `testInquiryFixtureMappings` (`bill-payment-inquiry-unknown.xml`, `non-pkr.xml`, `empty-currency.xml`), `testInsertVoucherFixtureMappings` (`insert-voucher-non-2-char-status.xml`, malformed/credential fixtures), `testBulkHardeningFixtures` (`bill-payment-bulk-malformed-xml.xml`) | COVERED, consolidated by Story 3.8 fail-closed wall |
| Duplicate posting is prevented | `KuickPayPostingServiceTest.php`: `testAlreadyPostedVoucherIsNoOpAfterLock`; `KuickPayReconcileServiceTest.php`: `testRunBulkAlreadyConfirmedVoucherIsNotDemotedOnRerun`, `testRunBulkDuplicateConsumerRowsDoNotDoubleConfirm` | COVERED, strengthened by Story 3.8 two-call transaction-writer assertion |
| Mismatched amounts never post | `KuickPayResponseParserTest.php`: `testInquiryFixtureMappings` (`amount-mismatch`, `overpayment`), `testBulkHardeningFixtures` (`bulk-overpayment`, `bulk-late-partial`); `KuickPayEvidenceValidatorTest.php`: `testValidationFailuresReturnMachineReasonCodes`, `testMultipleInvoiceLinkAllocationsNotSummingToVoucherAmountFailAmount` | COVERED, consolidated by Story 3.8 fail-closed wall |
| No secret leakage in fixtures or persisted evidence | Pre-existing redactor and language-string tests did not scan all fixtures, persisted evidence, audit payloads, run summaries, or item rows | GAP CLOSED BY STORY 3.8 leak scan |

## Minimum Fixture Gate

| Required case | Coverage | Status |
|---|---|---|
| Success | `valid/insert-voucher-success.xml`, `valid/bill-payment-inquiry-paid-exact.xml`, `valid/bill-payment-bulk-matched-paid.xml`; parser and reconcile service happy-path tests | COVERED |
| Failure | `malformed/insert-voucher-invalid-credentials.xml`; `KuickPayResponseParserTest::testInsertVoucherFixtureMappings`; issuance failure tests | COVERED |
| Malformed response | `malformed/insert-voucher-malformed.xml`, `malformed/insert-voucher-non-2-char-status.xml`, `malformed/bill-payment-inquiry-short.xml`, `malformed/bill-payment-bulk-malformed-xml.xml` | COVERED |
| Replay/duplicate | `ambiguous/insert-voucher-duplicate.xml`; bulk duplicate consumer rows; duplicate reference validator cases | COVERED |
| Amount mismatch | `ambiguous/bill-payment-inquiry-amount-mismatch.xml`, `ambiguous/bill-payment-inquiry-overpayment.xml`, `ambiguous/bill-payment-bulk-overpayment.xml`, `ambiguous/bill-payment-bulk-late-partial.xml` | COVERED |
| Invoice mismatch | `KuickPayEvidenceValidatorTest::testValidationFailuresReturnMachineReasonCodes` via `failureProvider`: empty links, missing invoice, void invoice, paid invoice, wrong client | COVERED |
| Unknown transaction | `ambiguous/bill-payment-inquiry-unknown.xml`; unmatched bulk row fixture and service test | COVERED |
| Pending/unpaid | `valid/bill-payment-inquiry-pending.xml`; pending mapping in parser and reconcile service tests | COVERED |
| Late payment | `ambiguous/bill-payment-inquiry-late-after-expiry.xml`; `KuickPayEvidenceValidatorTest::testPaidAfterVoucherExpiryFailsWithLatePaymentReason`; `KuickPayReconcileServiceTest::testLatePaymentEvidenceAppliesPolicyAndStaysManualReviewWithoutPaymentFields` | COVERED |
| Bulk reconciliation matched/unmatched rows | `valid/bill-payment-bulk-mixed-multi-row.xml`, `valid/bill-payment-bulk-suffix-pair.xml`, `ambiguous/bill-payment-bulk-unmatched.xml`; `KuickPayReconcileServiceTest` bulk tests | COVERED |

## Residual Verification Notes

- Root Blesta PHPUnit coverage is not claimed because `../tests` is unavailable.
- DB-backed install/upgrade smoke and live SOAP checks were not run; Story 3.8 relies on fixture-backed component suites and syntax checks.
- The single-inquiry null paid-date asymmetry is a LOW deferred stuck-state item: posting fails closed and does not create a Blesta transaction, but parser parity with bulk should be fixed outside this verification story.
