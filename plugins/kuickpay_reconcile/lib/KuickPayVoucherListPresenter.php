<?php
/**
 * KuickPay voucher list presenter
 *
 * Pure PHP (no DB, no framework) presentation seam for the admin voucher
 * list. Holds the closed allowlists that keep request-controlled sort/filter
 * inputs and DB status values off the query builder and out of language keys,
 * so the testable rules can be unit-tested without the framework.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayVoucherListPresenter
{
    /**
     * @var array Closed allowlist mapping each canonical status to a language key.
     *  Kept in lock-step with KuickpayVouchers::STATUSES (verified by test); the
     *  pure seam cannot load the DB-backed model to read it directly.
     */
    public const STATUS_LABEL_KEYS = [
        'pending' => 'AdminVouchers.status.pending',
        'retry' => 'AdminVouchers.status.retry',
        'confirmed_unposted' => 'AdminVouchers.status.confirmed_unposted',
        'posted' => 'AdminVouchers.status.posted',
        'failed' => 'AdminVouchers.status.failed',
        'expired' => 'AdminVouchers.status.expired',
        'manual_review' => 'AdminVouchers.status.manual_review',
        'cancelled' => 'AdminVouchers.status.cancelled',
    ];

    /**
     * @var array Closed allowlist mapping each canonical status to a badge class.
     *  Only `posted` (a truly paid voucher) ever resolves to a success/green
     *  badge; every other state is info/secondary/warning/danger.
     */
    public const STATUS_BADGE_CLASSES = [
        'pending' => 'bg-info',
        'retry' => 'bg-info',
        'confirmed_unposted' => 'bg-info',
        'posted' => 'bg-success',
        'failed' => 'bg-danger',
        'expired' => 'bg-secondary',
        'manual_review' => 'bg-warning text-dark',
        'cancelled' => 'bg-secondary',
    ];

    /**
     * @var array Fields the list may be sorted by. DB columns only; `amount`
     *  (varchar → lexicographic), `blesta_transaction_id` (filter-only), and
     *  invoice mapping (a batched subquery, not a column) are deliberately absent.
     */
    public const SORTABLE_FIELDS = [
        'date_created',
        'client_id',
        'consumer_number',
        'status',
        'date_last_checked',
    ];

    /**
     * @var array Request filter keys this screen accepts. Anything else is dropped
     *  before the value can reach the query builder.
     */
    public const FILTER_KEYS = [
        'status',
        'client_id',
        'consumer_number',
        'registration_number',
        'kuickpay_reference',
        'amount',
        'invoice_id',
        'date_from',
        'date_to',
        'has_blesta_transaction',
    ];

    /**
     * @var string Language key for an unknown/empty status.
     */
    public const DEFAULT_STATUS_LABEL_KEY = 'AdminVouchers.status.unknown';

    /**
     * @var string Neutral badge class for an unknown/empty status.
     */
    public const DEFAULT_BADGE_CLASS = 'bg-secondary';

    /**
     * @var array Closed allowlist mapping each canonical status to the admin
     *  posting-state label key from the UI display-state matrix
     *  (architecture.md:595-606). This is the operational "what does this state
     *  mean for posting" label — distinct from the short status badge label.
     *  Only `posted` describes a posted-to-Blesta state; no other state is ever
     *  labelled "paid".
     */
    public const POSTING_STATE_LABEL_KEYS = [
        'pending' => 'AdminVouchers.posting_state.pending',
        'retry' => 'AdminVouchers.posting_state.retry',
        'confirmed_unposted' => 'AdminVouchers.posting_state.confirmed_unposted',
        'posted' => 'AdminVouchers.posting_state.posted',
        'failed' => 'AdminVouchers.posting_state.failed',
        'expired' => 'AdminVouchers.posting_state.expired',
        'manual_review' => 'AdminVouchers.posting_state.manual_review',
        'cancelled' => 'AdminVouchers.posting_state.cancelled',
    ];

    /**
     * @var string Language key for an unknown/empty posting state.
     */
    public const DEFAULT_POSTING_STATE_LABEL_KEY = 'AdminVouchers.posting_state.unknown';

    /**
     * @var array Closed allowlist mapping every error-class token that can be
     *  stored on a voucher row to a language key. The stored domain is the
     *  parser's 8 canonical classes (KuickPayResponseParser::ALLOWED_ERRORS)
     *  PLUS two operational tokens written outside the parser: posting_failed
     *  (KuickPayPostingService) and reconcile_exception (KuickPayReconcileService).
     *  Any token outside this set falls back to the safe unknown label.
     */
    public const ERROR_CLASS_LABEL_KEYS = [
        'timeout' => 'AdminVouchers.error_class.timeout',
        'transport_error' => 'AdminVouchers.error_class.transport_error',
        'credential_error' => 'AdminVouchers.error_class.credential_error',
        'malformed_response' => 'AdminVouchers.error_class.malformed_response',
        'unknown_status' => 'AdminVouchers.error_class.unknown_status',
        'amount_mismatch' => 'AdminVouchers.error_class.amount_mismatch',
        'duplicate_reference' => 'AdminVouchers.error_class.duplicate_reference',
        'unmatched_reference' => 'AdminVouchers.error_class.unmatched_reference',
        'posting_failed' => 'AdminVouchers.error_class.posting_failed',
        'reconcile_exception' => 'AdminVouchers.error_class.reconcile_exception',
    ];

    /**
     * @var string Language key for an unknown/absent error class.
     */
    public const DEFAULT_ERROR_CLASS_LABEL_KEY = 'AdminVouchers.error_class.unknown';

    /**
     * @var array Closed allowlist mapping every emitted audit event name to a
     *  language key. The raw event token is itself non-secret, but display
     *  always routes through this map; an unknown token gets the generic key.
     */
    public const EVENT_LABEL_KEYS = [
        'voucher.issued' => 'AdminVouchers.event.voucher.issued',
        'voucher.replaced' => 'AdminVouchers.event.voucher.replaced',
        'voucher.expired' => 'AdminVouchers.event.voucher.expired',
        'evidence.received' => 'AdminVouchers.event.evidence.received',
        'evidence.matched' => 'AdminVouchers.event.evidence.matched',
        'evidence.retry_decision' => 'AdminVouchers.event.evidence.retry_decision',
        'evidence.rejected' => 'AdminVouchers.event.evidence.rejected',
        'evidence.duplicate' => 'AdminVouchers.event.evidence.duplicate',
        'evidence.unmatched' => 'AdminVouchers.event.evidence.unmatched',
        'reconciliation.run.started' => 'AdminVouchers.event.reconciliation.run.started',
        'reconciliation.run.completed' => 'AdminVouchers.event.reconciliation.run.completed',
        'posting.started' => 'AdminVouchers.event.posting.started',
        'posting.succeeded' => 'AdminVouchers.event.posting.succeeded',
        'posting.failed' => 'AdminVouchers.event.posting.failed',
    ];

    /**
     * @var string Language key for an unknown audit event name.
     */
    public const DEFAULT_EVENT_LABEL_KEY = 'AdminVouchers.event.unknown';

    /**
     * @var array Closed allowlist mapping every validation-reason token stored
     *  inside diagnostic_summary.validation_errors to a language key. Populated
     *  from three sources: the plugin evidence validator, the posting service
     *  (merged via moveToManualReview), and the gateway response parser. The
     *  parser tail is open-ended, so any unmapped token falls back to the safe
     *  generic unknown label (AC6). The audit-only transaction_add_failed /
     *  transaction_apply_failed reasons are deliberately absent: they live in
     *  the escaped audit payload, never in validation_errors.
     */
    public const VALIDATION_REASON_LABEL_KEYS = [
        // Plugin evidence validator (KuickPayEvidenceValidator).
        'currency_mismatch' => 'AdminVouchers.validation_reason.currency_mismatch',
        'amount_mismatch' => 'AdminVouchers.validation_reason.amount_mismatch',
        'unmatched_reference' => 'AdminVouchers.validation_reason.unmatched_reference',
        'invoice_mismatch' => 'AdminVouchers.validation_reason.invoice_mismatch',
        'stale_voucher' => 'AdminVouchers.validation_reason.stale_voucher',
        'duplicate_reference' => 'AdminVouchers.validation_reason.duplicate_reference',
        'late_payment' => 'AdminVouchers.validation_reason.late_payment',
        // Posting service (merged into validation_errors on manual review).
        'missing_paid_date' => 'AdminVouchers.validation_reason.missing_paid_date',
        'existing_transaction_mismatch' => 'AdminVouchers.validation_reason.existing_transaction_mismatch',
        'existing_transaction_partial_application' => 'AdminVouchers.validation_reason.existing_transaction_partial_application',
        'existing_transaction_apply_failed' => 'AdminVouchers.validation_reason.existing_transaction_apply_failed',
        'existing_transaction_unverified' => 'AdminVouchers.validation_reason.existing_transaction_unverified',
        // Gateway response parser (open-ended tail; generic fallback covers the rest).
        'missing_expected_context' => 'AdminVouchers.validation_reason.missing_expected_context',
        'underpayment' => 'AdminVouchers.validation_reason.underpayment',
        'overpayment' => 'AdminVouchers.validation_reason.overpayment',
        'unknown_status' => 'AdminVouchers.validation_reason.unknown_status',
    ];

    /**
     * @var string Language key for an unknown/empty validation reason.
     */
    public const DEFAULT_VALIDATION_REASON_LABEL_KEY = 'AdminVouchers.validation_reason.unknown';

    /**
     * @var array Closed allowlist of diagnostic_summary keys the detail view may
     *  render, in fixed display order. Covers both the reconcile writer shape
     *  and the issuance writer shape. Guarantees AC7 even if a future writer
     *  adds a new key to the JSON: only these keys are ever surfaced.
     */
    public const DIAGNOSTIC_FIELD_KEYS = [
        'status',
        'raw_status',
        'error_class',
        'evidence_hash',
        'redacted_trace_id',
        'validation_errors',
        'reference',
        'consumer_number',
        'registration_number',
        'amount',
        'currency',
        'paid_at',
    ];

    /**
     * Returns the language key for a status via the closed allowlist.
     *
     * @param string $status The raw status value
     * @return string The language key (safe generic key for unknown/empty input)
     */
    public function labelKeyFor(string $status): string
    {
        return self::STATUS_LABEL_KEYS[$status] ?? self::DEFAULT_STATUS_LABEL_KEY;
    }

    /**
     * Returns the badge class for a status via the closed allowlist.
     *
     * @param string $status The raw status value
     * @return string The badge class (success only for `posted`; neutral default
     *  for unknown/empty input)
     */
    public function badgeClassFor(string $status): string
    {
        return self::STATUS_BADGE_CLASSES[$status] ?? self::DEFAULT_BADGE_CLASS;
    }

    /**
     * Returns the admin posting-state label key for a status via the closed
     * allowlist (the UI display-state matrix). Unknown/empty input resolves to
     * the safe generic key — the DB status is never concatenated into a key.
     *
     * @param string $status The raw status value
     * @return string The posting-state language key
     */
    public function postingStateLabelKey(string $status): string
    {
        return self::POSTING_STATE_LABEL_KEYS[$status] ?? self::DEFAULT_POSTING_STATE_LABEL_KEY;
    }

    /**
     * Validates a request sort field against the allowlist.
     *
     * Always returns a member of SORTABLE_FIELDS: the requested field when
     * allowed, otherwise the default when allowed, otherwise the first sortable
     * field. No request value ever reaches the query builder's order() unchecked.
     *
     * @param string|null $field The requested sort field
     * @param string $default The fallback sort field
     * @return string An allowlisted sort field
     */
    public function allowedSort(?string $field, string $default): string
    {
        if ($field !== null && in_array($field, self::SORTABLE_FIELDS, true)) {
            return $field;
        }

        if (in_array($default, self::SORTABLE_FIELDS, true)) {
            return $default;
        }

        return self::SORTABLE_FIELDS[0];
    }

    /**
     * Validates a request sort direction.
     *
     * Accepts only `asc`/`desc` (case-insensitive) and falls back to the default
     * (itself constrained to asc/desc, otherwise `desc`).
     *
     * @param string|null $order The requested order
     * @param string $default The fallback order
     * @return string Either `asc` or `desc`
     */
    public function allowedOrder(?string $order, string $default = 'desc'): string
    {
        $normalized = strtolower((string) $order);
        if ($normalized === 'asc' || $normalized === 'desc') {
            return $normalized;
        }

        $default = strtolower($default);

        return ($default === 'asc' || $default === 'desc') ? $default : 'desc';
    }

    /**
     * Reduces raw request filters to the allowlisted, non-empty set.
     *
     * Drops unknown keys, array values, and empty values; rejects an
     * out-of-range status; and normalizes the has_blesta_transaction toggle to
     * '1'. Match semantics (LIKE, amount normalization, etc.) are applied later
     * in the model — this only gatekeeps which keys/values survive.
     *
     * @param array $raw The raw filter input
     * @return array The sanitized filter set
     */
    public function sanitizeFilters(array $raw): array
    {
        $clean = [];

        foreach (self::FILTER_KEYS as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];
            if (is_array($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            // Integer id filters must be purely numeric.
            if (in_array($key, ['client_id', 'invoice_id'], true) && !ctype_digit((string) $value)) {
                continue;
            }

            // Date range filters must be valid YYYY-MM-DD dates.
            if (in_array($key, ['date_from', 'date_to'], true)) {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    continue;
                }
            }

            if ($key === 'status' && !array_key_exists($value, self::STATUS_LABEL_KEYS)) {
                continue;
            }

            if ($key === 'has_blesta_transaction') {
                $clean[$key] = '1';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Returns the language key for a stored error-class token via the closed
     * allowlist. Null (no error class) and any unknown token resolve to the
     * safe generic key — a DB value is never concatenated into a language key.
     *
     * @param string|null $class The stored error_class token
     * @return string The language key
     */
    public function errorClassLabelKey(?string $class): string
    {
        if ($class === null) {
            return self::DEFAULT_ERROR_CLASS_LABEL_KEY;
        }

        return self::ERROR_CLASS_LABEL_KEYS[$class] ?? self::DEFAULT_ERROR_CLASS_LABEL_KEY;
    }

    /**
     * Returns the language key for an audit event name via the closed allowlist.
     * Unknown/empty tokens resolve to the generic event key.
     *
     * @param string|null $event The audit event_name token
     * @return string The language key
     */
    public function eventLabelKey(?string $event): string
    {
        if ($event === null || $event === '') {
            return self::DEFAULT_EVENT_LABEL_KEY;
        }

        return self::EVENT_LABEL_KEYS[$event] ?? self::DEFAULT_EVENT_LABEL_KEY;
    }

    /**
     * Returns the language key for a validation-reason token via the closed
     * allowlist. Unknown/empty tokens resolve to the safe generic key.
     *
     * @param string|null $reason The validation_errors token
     * @return string The language key
     */
    public function validationReasonLabelKey(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return self::DEFAULT_VALIDATION_REASON_LABEL_KEY;
        }

        return self::VALIDATION_REASON_LABEL_KEYS[$reason] ?? self::DEFAULT_VALIDATION_REASON_LABEL_KEY;
    }

    /**
     * Reduces a decoded diagnostic_summary to the allowlisted, non-empty keys,
     * in fixed display order.
     *
     * "Non-empty" is `$v !== null && $v !== '' && $v !== []` — NOT empty() — so
     * a legitimate provider value of '0' (e.g. a raw_status of '0') is kept,
     * while null/''/[] are dropped. The validation_errors array value is
     * preserved as an array (not stringified) so the view can iterate it
     * through validationReasonLabelKey().
     *
     * @param array $decoded The json_decode(diagnostic_summary, true) array
     * @return array The allowlisted fields, key => value, in display order
     */
    public function allowedDiagnosticFields(array $decoded): array
    {
        $fields = [];

        foreach (self::DIAGNOSTIC_FIELD_KEYS as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }

            $value = $decoded[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
