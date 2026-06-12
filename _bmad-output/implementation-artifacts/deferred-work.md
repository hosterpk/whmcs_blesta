# Deferred Work

## Deferred from: code review of 4-2-inspect-voucher-details-and-safe-diagnostics.md (2026-06-12)

- No controller/model/integration tests for detail page, permission gate, or company-scoped reads (`plugins/kuickpay_reconcile/controllers/admin_vouchers.php:143`, `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`, `plugins/kuickpay_reconcile/models/kuickpay_audit_events.php`) — pre-existing limitation: no DB/live admin stack in this checkout; spec already documents `php -l` + review fallback.
