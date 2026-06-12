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
}
