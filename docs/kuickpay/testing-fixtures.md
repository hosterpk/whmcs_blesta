# KuickPay Testing Fixtures

Date: 2026-06-09

## Sanitization Rules

- Replace `userName`, `password`, `Mobile`, `Email`, `Name`, and `InstitutionID` values with obvious placeholders.
- Do not include production credentials, real Institution IDs, real customer data, unredacted SOAP requests, logs, `.env` data, or `config/blesta.php` values.
- Preserve full SOAP response envelopes for `.xml` files so parser tests can consume realistic response shape.
- Keep malformed cases as well-formed XML fixture files; malformed behavior is represented inside the `*Result` payload.
- Mark all non-live and non-sandbox captures as `synthetic_from_observed_format`, `provisional`, and `PENDING_HUMAN_APPROVAL`.

## Expected Normalized Evidence Mapping

| Operation | Fixture | `expected_status` | `error_class` | `decision_rule` |
|---|---|---|---|---|
| InsertVoucher | `insert-voucher/success.xml` | `pending` |  | Voucher created, unpaid. `raw_status` `00` observed as creation success. |
| InsertVoucher | `insert-voucher/duplicate.xml` | `manual_review` | `duplicate_reference` | Fail closed; confirm real merchant duplicate semantics before any other mapping. |
| InsertVoucher | `insert-voucher/invalid-credentials.xml` | `failed` | `credential_error` | Credential failure blocks voucher creation. |
| InsertVoucher | `insert-voucher/malformed.xml` | `manual_review` | `malformed_response` | Missing required fields is malformed, not partial success. |
| InsertVoucher | `insert-voucher/timeout.md` | `manual_review` | `timeout` | Never auto-retry `InsertVoucher`; re-evaluate only after inquiry can confirm whether creation landed. |
| BillPaymentInquiry | `bill-payment-inquiry/pending.xml` | `pending` |  | Pending is unpaid. |
| BillPaymentInquiry | `bill-payment-inquiry/paid-exact.xml` | `confirmed_unposted` |  | Validated evidence only, not posted. Only posting service may move to `posted`. |
| BillPaymentInquiry | `bill-payment-inquiry/amount-mismatch.xml` | `manual_review` | `amount_mismatch` | Compare as decimal strings or integer minor units, never floats. |
| BillPaymentInquiry | `bill-payment-inquiry/expired.xml` | `expired` |  | If real expired semantics are ambiguous, map to `manual_review` and record the reason. |
| BillPaymentInquiry | `bill-payment-inquiry/unknown.xml` | `manual_review` | `unknown_status` | Unknown status fails closed. |
| BillPaymentBulkInquiry | `bill-payment-bulk-inquiry/matched-paid.xml` | `confirmed_unposted` |  | Match by stored Consumer Number only; never infer from suffix. |
| BillPaymentBulkInquiry | `bill-payment-bulk-inquiry/unmatched.xml` | `manual_review` | `unmatched_reference` | Record as a run item for manual review. |
| BillPaymentBulkInquiry | `bill-payment-bulk-inquiry/malformed-xml.xml` | `manual_review` | `malformed_response` | Bounded retry only for transient transport truncation; malformed dataset maps to manual review. |

## Paid-Classification Preconditions (fail-closed) — added by code review 2026-06-09

A `00` inquiry status, or the mere presence of a row in a bulk `NewDataSet`, is **necessary but NOT sufficient** to reach `confirmed_unposted`. Before any paid classification, the Story 3.2 parser MUST additionally require ALL of:

- **Amount equality** — the reported paid amount equals the expected invoice amount, compared as integer minor units (or canonical decimal strings), never floats. A `00` row whose amount differs (see `amount-mismatch.xml`, status `00` / amount `900.00`) maps to `manual_review` / `amount_mismatch`.
- **Currency match** — `currency == PKR`. A non-PKR or empty currency on an otherwise-paid row maps to `manual_review`.
- **Exact Consumer Number equality** — match on the full stored Consumer Number by exact string equality only. Never suffix / substring / `contains` matching (fixtures concatenate `institution_id + registration_number` with no delimiter, e.g. `INSTITUTION_IDREG-0000001`, which would defeat a loose matcher).
- **Structural validation first (bulk)** — validate the inner dataset is well-formed and complete BEFORE extracting any row. `malformed-xml.xml` deliberately embeds a known-good Consumer Number ahead of a truncation; a parser that scans for consumer numbers before validating structure fails open. Malformed/incomplete dataset maps to `manual_review` / `malformed_response`, zero row matches.

Any precondition failure maps to `retry` or `manual_review`. Never `posted`.

## Status-Code Default (fail-closed) — added by code review 2026-06-09

The status codes exemplified by the provisional fixtures (`00`, `01`, `02`, `99`) are unverified examples, not a closed enumeration. Any inquiry status code outside the confirmed allow-list maps to `manual_review` / `unknown_status` by default. Confirm the authoritative code list with KuickPay before narrowing this default.

## Open Contract Contradiction: `InsertVoucherResult` format (fail-closed) — flagged by code review 2026-06-09

`insert-voucher/success.xml` encodes `InsertVoucherResult` as a **comma-delimited** string (`00,KP-VOUCHER-0001,INSTITUTION_ID,REG-0000001`). The story's observed-format note instead reads the voucher id at a **fixed offset** `substr(result, 3, 14)`, which on this fixture yields `KP-VOUCHER-000` — it truncates the 15-character id `KP-VOUCHER-0001`. These two representations are mutually inconsistent and BOTH are unverified (`synthetic_from_observed_format`). Story 3.2 MUST NOT hard-code either delimiter or offset until KuickPay confirms the real `InsertVoucherResult` shape; parse defensively and fail closed on any length / field-count ambiguity.

## Story 3.2 Category Mapping

| Phase 0 fixture | Story 3.2 canonical category |
|---|---|
| `insert-voucher/success.xml` | `valid/insert-voucher-success.xml` |
| `insert-voucher/duplicate.xml` | `ambiguous/insert-voucher-duplicate.xml` |
| `insert-voucher/invalid-credentials.xml` | `malformed/insert-voucher-invalid-credentials.xml` |
| `insert-voucher/malformed.xml` | `malformed/insert-voucher-malformed.xml` |
| `insert-voucher/timeout.md` | `ambiguous/insert-voucher-timeout.md` |
| `bill-payment-inquiry/pending.xml` | `valid/bill-payment-inquiry-pending.xml` |
| `bill-payment-inquiry/paid-exact.xml` | `valid/bill-payment-inquiry-paid-exact.xml` |
| `bill-payment-inquiry/amount-mismatch.xml` | `ambiguous/bill-payment-inquiry-amount-mismatch.xml` |
| `bill-payment-inquiry/expired.xml` | `valid/bill-payment-inquiry-expired.xml` |
| `bill-payment-inquiry/unknown.xml` | `ambiguous/bill-payment-inquiry-unknown.xml` |
| `bill-payment-bulk-inquiry/matched-paid.xml` | `valid/bill-payment-bulk-matched-paid.xml` |
| `bill-payment-bulk-inquiry/unmatched.xml` | `ambiguous/bill-payment-bulk-unmatched.xml` |
| `bill-payment-bulk-inquiry/malformed-xml.xml` | `malformed/bill-payment-bulk-malformed-xml.xml` |
| `redaction/credentials.xml` | `redaction/credentials.xml` |

## Fixture Provenance

All fixture rows below are provisional because no live or sandbox KuickPay captures were available to this agent. They are suitable for parser development but cannot satisfy the approval gate until replaced or confirmed by live/sandbox evidence.

| Fixture | source_type | captured_at | captured_by | redacted_by | verification_status | provisional | provisional_reason | approval_status | evidence_hash / redacted_trace_id |
|---|---|---|---|---|---|---|---|---|---|
| `insert-voucher/success.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Built from observed raw status format; no live/sandbox capture available. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-insert-success` |
| `insert-voucher/duplicate.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Duplicate semantics require merchant confirmation. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-insert-duplicate` |
| `insert-voucher/invalid-credentials.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Credential error shape is synthetic. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-insert-invalid-credentials` |
| `insert-voucher/malformed.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Malformed result semantics are synthetic. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-insert-malformed` |
| `insert-voucher/timeout.md` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Transport timeout descriptor, no response body. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-insert-timeout` |
| `bill-payment-inquiry/pending.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Built from observed comma-separated result format. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-inquiry-pending` |
| `bill-payment-inquiry/paid-exact.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Paid result shape is synthetic from observed format. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-inquiry-paid-exact` |
| `bill-payment-inquiry/amount-mismatch.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Amount mismatch semantics require real capture confirmation. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-inquiry-amount-mismatch` |
| `bill-payment-inquiry/expired.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Expired status semantics require real capture confirmation. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-inquiry-expired` |
| `bill-payment-inquiry/unknown.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Unknown status deliberately synthetic. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-inquiry-unknown` |
| `bill-payment-bulk-inquiry/matched-paid.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | XML dataset shape is synthetic from observed row naming. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-bulk-matched-paid` |
| `bill-payment-bulk-inquiry/unmatched.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Unmatched row semantics require real capture confirmation. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-bulk-unmatched` |
| `bill-payment-bulk-inquiry/malformed-xml.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Inner dataset is intentionally malformed while SOAP envelope remains well-formed. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-bulk-malformed` |
| `redaction/credentials.xml` | `synthetic_from_observed_format` | `2026-06-09T00:00:00+05:00` | `Dev Agent` | `Dev Agent` | `provisional` | `true` | Redaction method sample, not operational evidence. | `PENDING_HUMAN_APPROVAL` | `phase0-synthetic-redaction-credentials` |

## Redaction Confirmation

Expected placeholder values:

- `userName`: `REDACTED_USERNAME`
- `password`: `REDACTED_PASSWORD`
- `Mobile`: `0300XXXXXXX`
- `Email`: `customer@example.invalid`
- `Name`: `REDACTED_CUSTOMER_NAME`
- `InstitutionID`: `INSTITUTION_ID`
