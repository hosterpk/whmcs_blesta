---
title: 'Fix KuickPay Audit Repository Loading'
type: 'bugfix'
created: '2026-06-11'
status: 'done'
route: 'one-shot'
---

# Fix KuickPay Audit Repository Loading

## Intent

**Problem:** Selecting KuickPay on the client payment confirmation flow could fatal before the customer reference panel rendered because `KuickPayAuditService` instantiated `KuickPayAuditRepository` without ensuring the class was loaded.

**Approach:** Make `KuickPayAuditService` own loading of its direct repository dependency on the default constructor path, while preserving injected repositories and adding constructor regression coverage.

## Suggested Review Order

- Start at the dependency boundary that caused the payment-confirm blank page.
  [`KuickPayAuditService.php:14`](../../plugins/kuickpay_reconcile/lib/KuickPayAuditService.php#L14)

- Confirm the default construction path now loads the repository before instantiation.
  [`KuickPayAuditService.php:15`](../../plugins/kuickpay_reconcile/lib/KuickPayAuditService.php#L15)

- Check the CLI fallback does not require Blesta loader bootstrap.
  [`KuickPayAuditService.php:17`](../../plugins/kuickpay_reconcile/lib/KuickPayAuditService.php#L17)

- Verify regression coverage for the live default constructor path.
  [`KuickPayAuditServiceTest.php:23`](../../plugins/kuickpay_reconcile/tests/KuickPayAuditServiceTest.php#L23)

- Verify injected repositories still work for unit seams.
  [`KuickPayAuditServiceTest.php:31`](../../plugins/kuickpay_reconcile/tests/KuickPayAuditServiceTest.php#L31)
