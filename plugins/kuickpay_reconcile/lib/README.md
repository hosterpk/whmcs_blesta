# KuickPay Reconcile Library Boundary

`KuickPayPostingService` is the only KuickPay-owned class permitted to create or apply Blesta transactions for confirmed KuickPay payments.

Gateway code, controllers, views, reconciliation services, validators, and repositories must not call `Transactions->add()`, `Transactions->apply()`, invoice paid-state helpers, or any equivalent payment-posting path directly. They may prepare evidence, validate state, persist diagnostics, or expose read-only status. The posting service owns the final locked re-read, idempotency checks, transaction create/apply, voucher `posted` transition, rollback handling, and posting audit events.
