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
}
