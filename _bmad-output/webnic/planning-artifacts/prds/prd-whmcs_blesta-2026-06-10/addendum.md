# Addendum — WebNIC Registrar Module

Technical depth and mechanism-level ("how") detail that belongs to the architecture/solution-design phase, not the PRD body. The PRD fixes the WHAT; this captures the HOW-leaning notes surfaced during formalization so they are not lost.

## Async provisioning — mechanism notes (for architecture)

The PRD (FR17, FR39a–FR39d) fixes the state machine's **states, invariants, and give-up behaviour**. Architecture must decide the mechanism:

- **State-machine table schema.** Columns implied by FR39a: `service_id`, `domain`, `kind` (register|transfer), `state` (submitted|pending|active|failed|cancelled), `webnic_order_id`/`transfer_id`, `attempts`, `created`, `last_polled`, `next_poll_at`. Plus a uniqueness constraint supporting FR18a's per-service+domain intent.
- **Registration-intent vs state-machine row.** FR18a's intent record may be the same row as FR39a (intent = `submitted` state created pre-call) or a separate table; decide in architecture. The invariant the PRD fixes: the intent is **committed before** the `Register Domain` call so a crash mid-call leaves a recoverable marker.
- **Poll cadence / backoff numbers.** FR39b assumes (pending OTE/B5): poll once per cron cycle, capped retry count, backoff on transient errors, hard give-up ~30 days. These are illustrative; set real values once OTE behaviour is known.
- **Cron ownership.** Module `cron()` hook vs coordination with Domain-Manager domain sync — affects whether the module owns its scheduler. Deferred (D4 / L1).

## Idempotency — recovery flow (for architecture)

The dangerous window (no webhooks, async register): submit → lost response before persisting `pendingOrderId` → Blesta retries `addService`. FR18a's recovery flow:
1. Write intent row (own transaction) keyed by service+domain **before** the API call.
2. On retry with an existing intent, **query WebNIC by domain** for a pending/active order; reconcile from it.
3. On unknown/timeout, resolve by query — **never blind-resubmit**.
Hard dependency: **by-domain pending-order lookup** must exist (Blocker B2). If WebNIC only returns an order handle in the (lost) response and offers no by-domain search, this flow is impossible and the idempotency strategy must be reworked.

## Contact-handle orchestration — saga & cleanup (for architecture)

Registration is a multi-call saga: create/lookup contacts → create/lookup registrant account → ensure ≥2 hosts → `Register Domain`. Risks and the stance the PRD implies (detail deferred, D4(e)):
- **Reuse-on-retry over delete-on-failure:** deterministic lookup-before-create so a retry reuses rather than re-creates, avoiding orphan accumulation.
- Reuse scope (shared reseller / per-client / per-domain) is **Blocker B3** — it drives table design, cleanup, and privacy exposure.
- The whole §4.3 prerequisite model is `[Unverified]` against a quotable WebNIC contract — **Blocker B7**.

## Pricing & currency (for architecture)

LogicBoxes `getFilteredTldPricing` converts registrar cost into the operator's configured currencies via `$this->Currencies->convert(...)`. The WebNIC module must do the same (FR7) rather than passing through only WebNIC-returned currencies (USD + `localPrice.*`). Currency strategy is deferred item **D1**.

## Token cache & single-flight (for architecture)

FR5 names the refresh-stampede hazard; NFR1 says tokens live in memory/cache only. In PHP-FPM/CLI-cron there is no shared in-process memory, so the cache backend (APCu / file / DB / Blesta cache) and a single-flight/locking strategy must be chosen and reconciled with NFR1. Deferred item **D4(d)**.

## Rejected / deferred alternative

- **Forwarding + DNSSEC "own the regression" path (rejected at formalization).** The alternative to pulling forwarding/DNSSEC into the MVP (C-002) was to keep them in Section 2 and explicitly list them as accepted MVP regressions in §2.3/§12 with operator sign-off. Rejected because it contradicts the PRD's zero-regression parity thesis (G1). Recorded here in case the user reverses the scope call.
