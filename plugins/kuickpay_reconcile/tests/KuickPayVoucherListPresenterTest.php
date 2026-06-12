<?php

use PHPUnit\Framework\TestCase;

class KuickPayVoucherListPresenterTest extends TestCase
{
    /**
     * The canonical 8 voucher states, hard-coded here on purpose: this test is
     * the drift detector if KuickpayVouchers::STATUSES ever changes, since the
     * pure-seam presenter cannot load the DB-backed model.
     */
    private const CANONICAL_STATUSES = [
        'pending',
        'retry',
        'confirmed_unposted',
        'posted',
        'failed',
        'expired',
        'manual_review',
        'cancelled',
    ];

    private function presenter(): KuickPayVoucherListPresenter
    {
        return new KuickPayVoucherListPresenter();
    }

    public function testStatusMapKeysEqualTheCanonicalEightStates()
    {
        $label_keys = array_keys(KuickPayVoucherListPresenter::STATUS_LABEL_KEYS);
        $badge_keys = array_keys(KuickPayVoucherListPresenter::STATUS_BADGE_CLASSES);

        sort($label_keys);
        $expected = self::CANONICAL_STATUSES;
        sort($expected);

        $this->assertSame($expected, $label_keys, 'Status label map drifted from the canonical 8 states');
        $this->assertSame(
            array_keys(KuickPayVoucherListPresenter::STATUS_LABEL_KEYS),
            $badge_keys,
            'Label and badge maps must cover the same states in the same order'
        );
    }

    public function testLabelKeyForEveryCanonicalStatus()
    {
        $presenter = $this->presenter();

        foreach (self::CANONICAL_STATUSES as $status) {
            $this->assertSame(
                'AdminVouchers.status.' . $status,
                $presenter->labelKeyFor($status)
            );
        }
    }

    public function testBadgeClassForEveryCanonicalStatus()
    {
        $presenter = $this->presenter();

        $expected = [
            'pending' => 'bg-info',
            'retry' => 'bg-info',
            'confirmed_unposted' => 'bg-info',
            'posted' => 'bg-success',
            'failed' => 'bg-danger',
            'expired' => 'bg-secondary',
            'manual_review' => 'bg-warning text-dark',
            'cancelled' => 'bg-secondary',
        ];

        foreach ($expected as $status => $class) {
            $this->assertSame($class, $presenter->badgeClassFor($status));
        }
    }

    public function testPostedIsTheOnlySuccessBadge()
    {
        $presenter = $this->presenter();

        foreach (self::CANONICAL_STATUSES as $status) {
            $is_success = strpos($presenter->badgeClassFor($status), 'bg-success') !== false;
            if ($status === 'posted') {
                $this->assertTrue($is_success, 'posted must use the success badge');
            } else {
                $this->assertFalse($is_success, $status . ' must not use the success badge');
            }
        }
    }

    public function testConfirmedUnpostedIsNotSuccess()
    {
        $presenter = $this->presenter();

        $this->assertSame('bg-info', $presenter->badgeClassFor('confirmed_unposted'));
    }

    public function testUnknownAndEmptyStatusFallBackToSafeDefaults()
    {
        $presenter = $this->presenter();

        foreach (['', 'bogus', 'DELETE', 'posted ', 'Posted'] as $status) {
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_STATUS_LABEL_KEY,
                $presenter->labelKeyFor($status)
            );
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_BADGE_CLASS,
                $presenter->badgeClassFor($status)
            );
            $this->assertStringNotContainsString('bg-success', $presenter->badgeClassFor($status));
        }
    }

    public function testAllowedSortReturnsRequestedFieldWhenAllowlisted()
    {
        $presenter = $this->presenter();

        foreach (KuickPayVoucherListPresenter::SORTABLE_FIELDS as $field) {
            $this->assertSame($field, $presenter->allowedSort($field, 'date_created'));
        }
    }

    public function testAllowedSortRejectsArbitraryInputAndFallsBackToDefault()
    {
        $presenter = $this->presenter();

        $this->assertSame('status', $presenter->allowedSort('id; DROP TABLE', 'status'));
        $this->assertSame('client_id', $presenter->allowedSort(null, 'client_id'));
        $this->assertSame('date_created', $presenter->allowedSort('amount', 'date_created'));
        $this->assertSame('date_created', $presenter->allowedSort('blesta_transaction_id', 'date_created'));
    }

    public function testAllowedSortFallsBackToFirstFieldWhenDefaultAlsoInvalid()
    {
        $presenter = $this->presenter();

        $result = $presenter->allowedSort('nonsense', 'also_nonsense');

        $this->assertSame(KuickPayVoucherListPresenter::SORTABLE_FIELDS[0], $result);
    }

    public function testAllowedSortAlwaysReturnsAMemberOfSortableFields()
    {
        $presenter = $this->presenter();

        foreach (['amount', 'blesta_transaction_id', 'invoice_id', '', 'random', null] as $field) {
            $this->assertContains(
                $presenter->allowedSort($field, 'bad_default'),
                KuickPayVoucherListPresenter::SORTABLE_FIELDS
            );
        }
    }

    public function testSortableFieldsExcludeNonColumnAndLexicographicFields()
    {
        $this->assertNotContains('amount', KuickPayVoucherListPresenter::SORTABLE_FIELDS);
        $this->assertNotContains('blesta_transaction_id', KuickPayVoucherListPresenter::SORTABLE_FIELDS);
        $this->assertNotContains('invoice_id', KuickPayVoucherListPresenter::SORTABLE_FIELDS);
    }

    public function testAllowedOrderAcceptsAscDescCaseInsensitive()
    {
        $presenter = $this->presenter();

        $this->assertSame('asc', $presenter->allowedOrder('asc', 'desc'));
        $this->assertSame('desc', $presenter->allowedOrder('desc', 'asc'));
        $this->assertSame('asc', $presenter->allowedOrder('ASC', 'desc'));
        $this->assertSame('desc', $presenter->allowedOrder('Desc', 'asc'));
    }

    public function testAllowedOrderFallsBackToDefaultOnInvalidInput()
    {
        $presenter = $this->presenter();

        $this->assertSame('desc', $presenter->allowedOrder('sideways', 'desc'));
        $this->assertSame('asc', $presenter->allowedOrder(null, 'asc'));
        $this->assertSame('desc', $presenter->allowedOrder('', 'desc'));
        // An invalid default itself falls back to desc.
        $this->assertSame('desc', $presenter->allowedOrder('nope', 'nope'));
    }

    public function testSanitizeFiltersKeepsAllowlistedNonEmptyValues()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters([
            'status' => 'pending',
            'client_id' => '42',
            'consumer_number' => '  12345  ',
            'registration_number' => 'REG-9',
            'kuickpay_reference' => 'KP-1',
            'amount' => '100.00',
            'invoice_id' => '7',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $this->assertSame('pending', $clean['status']);
        $this->assertSame('42', $clean['client_id']);
        $this->assertSame('12345', $clean['consumer_number'], 'values must be trimmed');
        $this->assertSame('REG-9', $clean['registration_number']);
        $this->assertSame('KP-1', $clean['kuickpay_reference']);
        $this->assertSame('100.00', $clean['amount']);
        $this->assertSame('7', $clean['invoice_id']);
        $this->assertSame('2026-01-01', $clean['date_from']);
        $this->assertSame('2026-12-31', $clean['date_to']);
    }

    public function testSanitizeFiltersDropsUnknownKeysAndEmptyValues()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters([
            'company_id' => '99',       // unknown — never accept a tenant from request
            'sort' => 'status',          // unknown here
            'status' => '',              // empty
            'consumer_number' => '   ',  // whitespace only
            'client_id' => '0',          // kept as a string; model guards numeric range
            'nonsense' => 'x',
        ]);

        $this->assertArrayNotHasKey('company_id', $clean);
        $this->assertArrayNotHasKey('sort', $clean);
        $this->assertArrayNotHasKey('status', $clean);
        $this->assertArrayNotHasKey('consumer_number', $clean);
        $this->assertArrayNotHasKey('nonsense', $clean);
        $this->assertSame('0', $clean['client_id']);
    }

    public function testSanitizeFiltersRejectsOutOfRangeStatus()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters(['status' => 'super_paid']);

        $this->assertArrayNotHasKey('status', $clean);
    }

    public function testSanitizeFiltersDropsArrayValues()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters(['consumer_number' => ['a', 'b']]);

        $this->assertArrayNotHasKey('consumer_number', $clean);
    }

    public function testSanitizeFiltersNormalizesHasBlestaTransactionToggle()
    {
        $presenter = $this->presenter();

        $this->assertSame('1', $presenter->sanitizeFilters(['has_blesta_transaction' => '1'])['has_blesta_transaction']);
        $this->assertSame('1', $presenter->sanitizeFilters(['has_blesta_transaction' => 'on'])['has_blesta_transaction']);
        $this->assertArrayNotHasKey(
            'has_blesta_transaction',
            $presenter->sanitizeFilters(['has_blesta_transaction' => ''])
        );
    }

    public function testSanitizeFiltersRejectsNonNumericClientIdAndInvoiceId()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters([
            'client_id' => '42abc',
            'invoice_id' => '7xyz',
        ]);

        $this->assertArrayNotHasKey('client_id', $clean, 'non-numeric client_id must be rejected');
        $this->assertArrayNotHasKey('invoice_id', $clean, 'non-numeric invoice_id must be rejected');
    }

    public function testSanitizeFiltersKeepsZeroClientId()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters(['client_id' => '0']);

        $this->assertSame('0', $clean['client_id'], 'zero client_id is kept for the model to range-guard');
    }

    public function testSanitizeFiltersRejectsInvalidDates()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters([
            'date_from' => '2026-1-1',
            'date_to' => '2026-13-01',
        ]);

        $this->assertArrayNotHasKey('date_from', $clean);
        $this->assertArrayNotHasKey('date_to', $clean);
    }

    public function testSanitizeFiltersKeepsValidDates()
    {
        $presenter = $this->presenter();

        $clean = $presenter->sanitizeFilters([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        $this->assertSame('2026-01-01', $clean['date_from']);
        $this->assertSame('2026-12-31', $clean['date_to']);
    }

    // ---------------------------------------------------------------------
    // Story 4.2 — detail-page label allowlists (error class, event,
    // validation reason) and the diagnostic-summary field allowlist.
    // ---------------------------------------------------------------------

    /**
     * The full stored error_class domain: the 8 canonical parser classes
     * (KuickPayResponseParser::ALLOWED_ERRORS) plus the 2 operational tokens
     * written outside the parser (posting_failed, reconcile_exception). Hard
     * coded so this test is the drift detector if a new writer adds a token.
     */
    private const STORED_ERROR_CLASSES = [
        'timeout',
        'transport_error',
        'credential_error',
        'malformed_response',
        'unknown_status',
        'amount_mismatch',
        'duplicate_reference',
        'unmatched_reference',
        'posting_failed',
        'reconcile_exception',
    ];

    /** The 14 emitted audit event names (grep-verified across the plugin lib). */
    private const KNOWN_EVENTS = [
        'voucher.issued',
        'voucher.replaced',
        'voucher.expired',
        'evidence.received',
        'evidence.matched',
        'evidence.retry_decision',
        'evidence.rejected',
        'evidence.duplicate',
        'evidence.unmatched',
        'reconciliation.run.started',
        'reconciliation.run.completed',
        'posting.started',
        'posting.succeeded',
        'posting.failed',
    ];

    /** Plugin evidence-validator reasons merged into validation_errors. */
    private const VALIDATOR_REASONS = [
        'currency_mismatch',
        'amount_mismatch',
        'unmatched_reference',
        'invoice_mismatch',
        'stale_voucher',
        'duplicate_reference',
        'late_payment',
    ];

    /** Posting-service reasons merged into validation_errors on manual review. */
    private const POSTING_REASONS = [
        'missing_paid_date',
        'existing_transaction_mismatch',
        'existing_transaction_partial_application',
        'existing_transaction_apply_failed',
        'existing_transaction_unverified',
    ];

    /** Gateway-parser reasons (open-ended tail; generic fallback covers more). */
    private const PARSER_REASONS = [
        'missing_expected_context',
        'underpayment',
        'overpayment',
        'unknown_status',
    ];

    public function testPostingStateLabelKeyForEveryCanonicalStatus()
    {
        $presenter = $this->presenter();

        foreach (self::CANONICAL_STATUSES as $status) {
            $this->assertSame(
                'AdminVouchers.posting_state.' . $status,
                $presenter->postingStateLabelKey($status)
            );
        }
    }

    public function testPostingStateLabelKeyUnknownFallsBack()
    {
        $presenter = $this->presenter();

        foreach (['', 'bogus', 'Posted', 'paid'] as $status) {
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_POSTING_STATE_LABEL_KEY,
                $presenter->postingStateLabelKey($status)
            );
        }
    }

    public function testPostingStateMapKeysEqualCanonicalStatuses()
    {
        $keys = array_keys(KuickPayVoucherListPresenter::POSTING_STATE_LABEL_KEYS);
        sort($keys);
        $expected = self::CANONICAL_STATUSES;
        sort($expected);

        $this->assertSame($expected, $keys, 'Posting-state map drifted from the canonical 8 states');
    }

    public function testPostingStateMapValuesAreCanonicalKeys()
    {
        foreach (KuickPayVoucherListPresenter::POSTING_STATE_LABEL_KEYS as $status => $key) {
            $this->assertSame('AdminVouchers.posting_state.' . $status, $key);
        }
    }

    public function testErrorClassLabelKeyForEveryStoredToken()
    {
        $presenter = $this->presenter();

        foreach (self::STORED_ERROR_CLASSES as $class) {
            $this->assertSame(
                'AdminVouchers.error_class.' . $class,
                $presenter->errorClassLabelKey($class)
            );
        }
    }

    public function testErrorClassLabelKeyNullAndUnknownFallBackToUnknown()
    {
        $presenter = $this->presenter();

        $this->assertSame(
            KuickPayVoucherListPresenter::DEFAULT_ERROR_CLASS_LABEL_KEY,
            $presenter->errorClassLabelKey(null)
        );

        // Unknown tokens and the audit-only posting reasons fall back safely.
        foreach (['', 'bogus', 'TIMEOUT', 'transaction_add_failed', 'posting_failed '] as $class) {
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_ERROR_CLASS_LABEL_KEY,
                $presenter->errorClassLabelKey($class)
            );
        }
    }

    public function testErrorClassMapKeysEqualTheStoredDomain()
    {
        $keys = array_keys(KuickPayVoucherListPresenter::ERROR_CLASS_LABEL_KEYS);
        sort($keys);
        $expected = self::STORED_ERROR_CLASSES;
        sort($expected);

        $this->assertSame(
            $expected,
            $keys,
            'Error-class map drifted from the stored domain (8 parser + 2 operational tokens)'
        );
    }

    public function testErrorClassMapValuesAreCanonicalKeys()
    {
        foreach (KuickPayVoucherListPresenter::ERROR_CLASS_LABEL_KEYS as $token => $key) {
            $this->assertSame('AdminVouchers.error_class.' . $token, $key);
        }
    }

    public function testEventLabelKeyForEveryKnownEvent()
    {
        $presenter = $this->presenter();

        foreach (self::KNOWN_EVENTS as $event) {
            $this->assertSame(
                'AdminVouchers.event.' . $event,
                $presenter->eventLabelKey($event)
            );
        }
    }

    public function testEventLabelKeyUnknownFallsBackToGeneric()
    {
        $presenter = $this->presenter();

        foreach (['', 'bogus.event', 'voucher.deleted', 'VOUCHER.ISSUED'] as $event) {
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_EVENT_LABEL_KEY,
                $presenter->eventLabelKey($event)
            );
        }
    }

    public function testEventMapKeysEqualTheKnownEvents()
    {
        $keys = array_keys(KuickPayVoucherListPresenter::EVENT_LABEL_KEYS);
        sort($keys);
        $expected = self::KNOWN_EVENTS;
        sort($expected);

        $this->assertSame($expected, $keys, 'Event map drifted from the 14 emitted event names');
    }

    public function testEventMapValuesAreCanonicalKeys()
    {
        foreach (KuickPayVoucherListPresenter::EVENT_LABEL_KEYS as $token => $key) {
            $this->assertSame('AdminVouchers.event.' . $token, $key);
        }
    }

    public function testValidationReasonLabelKeyForKnownTokensFromEachSource()
    {
        $presenter = $this->presenter();

        $all = array_merge(self::VALIDATOR_REASONS, self::POSTING_REASONS, self::PARSER_REASONS);
        foreach ($all as $reason) {
            $this->assertSame(
                'AdminVouchers.validation_reason.' . $reason,
                $presenter->validationReasonLabelKey($reason)
            );
        }
    }

    public function testValidationReasonLabelKeyUnknownAndAuditOnlyFallBack()
    {
        $presenter = $this->presenter();

        // transaction_add_failed / transaction_apply_failed are audit-payload
        // reasons only — they are NEVER merged into validation_errors.
        foreach (['transaction_add_failed', 'transaction_apply_failed', '', 'bogus', 'Amount_Mismatch'] as $reason) {
            $this->assertSame(
                KuickPayVoucherListPresenter::DEFAULT_VALIDATION_REASON_LABEL_KEY,
                $presenter->validationReasonLabelKey($reason)
            );
        }
    }

    public function testValidationReasonMapCoversPluginLocalReasons()
    {
        $keys = KuickPayVoucherListPresenter::VALIDATION_REASON_LABEL_KEYS;

        foreach (array_merge(self::VALIDATOR_REASONS, self::POSTING_REASONS) as $reason) {
            $this->assertArrayHasKey($reason, $keys, $reason . ' (plugin-local) must be mapped');
        }
    }

    public function testValidationReasonMapValuesAreCanonicalKeysNoDeadEntries()
    {
        foreach (KuickPayVoucherListPresenter::VALIDATION_REASON_LABEL_KEYS as $token => $key) {
            $this->assertSame('AdminVouchers.validation_reason.' . $token, $key);
        }
    }

    public function testValidationReasonMapExcludesAuditOnlyTokens()
    {
        $keys = KuickPayVoucherListPresenter::VALIDATION_REASON_LABEL_KEYS;

        $this->assertArrayNotHasKey('transaction_add_failed', $keys);
        $this->assertArrayNotHasKey('transaction_apply_failed', $keys);
    }

    public function testAllowedDiagnosticFieldsKeepsAllowlistedKeysInFixedOrder()
    {
        $presenter = $this->presenter();

        // Keys supplied out of order + an unknown key that must be dropped.
        $decoded = [
            'currency' => 'PKR',
            'unknown_key' => 'leak',
            'status' => 'confirmed_unposted',
            'error_class' => 'amount_mismatch',
            'raw_status' => '1',
            'evidence_hash' => 'abc123',
            'redacted_trace_id' => 'trace-xyz',
            'validation_errors' => ['amount_mismatch'],
            'reference' => 'KP-1',
            'consumer_number' => '0001',
            'registration_number' => 'REG-1',
            'amount' => '100.00',
            'paid_at' => '2026-06-01 10:00:00',
        ];

        $result = $presenter->allowedDiagnosticFields($decoded);

        $this->assertArrayNotHasKey('unknown_key', $result, 'unknown keys must be dropped');
        $this->assertSame(
            [
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
            ],
            array_keys($result),
            'fields must be returned in the fixed display order'
        );
    }

    public function testAllowedDiagnosticFieldsKeepsZeroRawStatusButDropsEmpty()
    {
        $presenter = $this->presenter();

        $result = $presenter->allowedDiagnosticFields([
            'raw_status' => '0',        // legitimate provider value — kept
            'evidence_hash' => '0',     // also a valid '0' — kept
            'status' => '',             // empty string — dropped
            'error_class' => null,      // null — dropped
            'validation_errors' => [],  // empty array — dropped
        ]);

        $this->assertArrayHasKey('raw_status', $result);
        $this->assertSame('0', $result['raw_status']);
        $this->assertArrayHasKey('evidence_hash', $result);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('error_class', $result);
        $this->assertArrayNotHasKey('validation_errors', $result);
    }

    public function testAllowedDiagnosticFieldsPreservesValidationErrorsAsArray()
    {
        $presenter = $this->presenter();

        $result = $presenter->allowedDiagnosticFields([
            'validation_errors' => ['amount_mismatch', 'late_payment'],
        ]);

        $this->assertArrayHasKey('validation_errors', $result);
        $this->assertIsArray($result['validation_errors']);
        $this->assertSame(['amount_mismatch', 'late_payment'], $result['validation_errors']);
    }

    public function testAllowedDiagnosticFieldsDropsScalarValidationErrors()
    {
        $presenter = $this->presenter();

        $result = $presenter->allowedDiagnosticFields([
            'validation_errors' => 'amount_mismatch',
        ]);

        $this->assertArrayNotHasKey('validation_errors', $result);
    }

    public function testAllowedDiagnosticFieldsDropsNonScalarDiagnosticValues()
    {
        $presenter = $this->presenter();

        $result = $presenter->allowedDiagnosticFields([
            'raw_status' => ['nested' => 'unexpected'],
            'amount' => (object) ['value' => '100.00'],
            'currency' => 'PKR',
        ]);

        $this->assertArrayNotHasKey('raw_status', $result);
        $this->assertArrayNotHasKey('amount', $result);
        $this->assertSame('PKR', $result['currency']);
    }

    public function testAllowedDiagnosticFieldsEmptyInputYieldsEmpty()
    {
        $presenter = $this->presenter();

        $this->assertSame([], $presenter->allowedDiagnosticFields([]));
    }

    /**
     * Cross-file drift guard: every label key the presenter can emit (and every
     * fallback) must be defined in the AdminVouchers language file, and every
     * allowlisted diagnostic field must have a detail label. Catches the
     * "mapped a token but forgot the translation" class of bug (e.g. adding the
     * 9th/10th error class to the map but stopping the language file at 8).
     */
    public function testLanguageFileDefinesEveryAllowlistedLabelKey()
    {
        $lang = [];
        include __DIR__ . '/../language/en_us/admin_vouchers.php';

        $maps = [
            KuickPayVoucherListPresenter::STATUS_LABEL_KEYS,
            KuickPayVoucherListPresenter::POSTING_STATE_LABEL_KEYS,
            KuickPayVoucherListPresenter::ERROR_CLASS_LABEL_KEYS,
            KuickPayVoucherListPresenter::EVENT_LABEL_KEYS,
            KuickPayVoucherListPresenter::VALIDATION_REASON_LABEL_KEYS,
        ];
        foreach ($maps as $map) {
            foreach ($map as $token => $key) {
                $this->assertArrayHasKey($key, $lang, 'Missing language key ' . $key . ' for token ' . $token);
            }
        }

        $fallbacks = [
            KuickPayVoucherListPresenter::DEFAULT_STATUS_LABEL_KEY,
            KuickPayVoucherListPresenter::DEFAULT_POSTING_STATE_LABEL_KEY,
            KuickPayVoucherListPresenter::DEFAULT_ERROR_CLASS_LABEL_KEY,
            KuickPayVoucherListPresenter::DEFAULT_EVENT_LABEL_KEY,
            KuickPayVoucherListPresenter::DEFAULT_VALIDATION_REASON_LABEL_KEY,
        ];
        foreach ($fallbacks as $key) {
            $this->assertArrayHasKey($key, $lang, 'Missing fallback language key ' . $key);
        }

        foreach (KuickPayVoucherListPresenter::DIAGNOSTIC_FIELD_KEYS as $field) {
            $this->assertArrayHasKey(
                'AdminVouchers.detail.field.' . $field,
                $lang,
                'Missing diagnostic field label AdminVouchers.detail.field.' . $field
            );
        }
    }
}
