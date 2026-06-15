<?php
/**
 * Shared schema-fidelity for KuickPay voucher-repository test doubles.
 *
 * Epic 3 retro AI-2: a fake repository that is looser than the database
 * manufactures a false-green suite. This trait encodes the real kuickpay_vouchers
 * guarantees ONCE so every repository fake can model them identically; see
 * tests/README.md for the written fake-fidelity checklist.
 *
 * Constraints modeled (a violation throws, exactly as MySQL would on write):
 *  - NOT NULL: company_id, context_key, status.
 *  - UNIQUE (company_id, consumer_number)      -> uniq_kuickpay_vouchers_consumer
 *  - UNIQUE (company_id, registration_number)  -> uniq_kuickpay_vouchers_reg
 *  - UNIQUE (company_id, active_context_key)   -> uniq_kuickpay_vouchers_active_context
 *
 * active_context_key is the STORED generated column equal to context_key EXCEPT
 * for the terminal expired/cancelled states, where it is NULL — so a released
 * slot no longer collides (NULL-insensitive uniqueness). MySQL also treats a NULL
 * consumer_number/registration_number as distinct (many NULLs allowed) but an
 * empty string '' as a value (two '' in one company collide).
 *
 * The authoritative proof of these keys remains the real-DB harness; this trait
 * is a regression net that keeps unit fakes honest.
 */
trait KuickPayFakeVoucherConstraints
{
    /** @var array<string, int> "company|consumer_number" => voucher id */
    private array $consumerIndex = [];

    /** @var array<string, int> "company|registration_number" => voucher id */
    private array $registrationIndex = [];

    /** @var array<string, int> "company|active_context_key" => voucher id */
    private array $activeContextIndex = [];

    /**
     * Asserts the NOT-NULL + company-scoped UNIQUE keys for a voucher INSERT and
     * records its index entries. Throws on any violation.
     *
     * @param int $id The voucher id being inserted
     * @param array $vars The voucher fields
     */
    protected function enforceVoucherInsert(int $id, array $vars): void
    {
        foreach (['company_id', 'context_key', 'status'] as $required) {
            if (!isset($vars[$required]) || (string) $vars[$required] === '') {
                throw new RuntimeException("kuickpay_vouchers.$required cannot be null");
            }
        }

        $companyId = (int) $vars['company_id'];
        $consumer = $vars['consumer_number'] ?? null;
        $registration = $vars['registration_number'] ?? null;
        $activeContextKey = $this->fakeActiveContextKey((string) $vars['status'], (string) $vars['context_key']);

        // NULL identities are distinct; empty string and real values are not.
        if ($consumer !== null) {
            $this->assertUnique($this->consumerIndex, $companyId . '|' . $consumer, 'uniq_kuickpay_vouchers_consumer');
        }
        if ($registration !== null) {
            $this->assertUnique($this->registrationIndex, $companyId . '|' . $registration, 'uniq_kuickpay_vouchers_reg');
        }
        if ($activeContextKey !== null) {
            $this->assertUnique(
                $this->activeContextIndex,
                $companyId . '|' . $activeContextKey,
                'uniq_kuickpay_vouchers_active_context'
            );
        }

        if ($consumer !== null) {
            $this->consumerIndex[$companyId . '|' . $consumer] = $id;
        }
        if ($registration !== null) {
            $this->registrationIndex[$companyId . '|' . $registration] = $id;
        }
        if ($activeContextKey !== null) {
            $this->activeContextIndex[$companyId . '|' . $activeContextKey] = $id;
        }
    }

    /**
     * Asserts the voucher constraints for an UPDATE and reindexes atomically.
     *
     * MySQL enforces these UNIQUE/NOT-NULL keys on UPDATE as well as INSERT.
     *
     * @param int $id The voucher id being updated
     * @param array $oldVars The pre-update voucher fields
     * @param array $newVars The post-update voucher fields
     */
    protected function enforceVoucherUpdate(int $id, array $oldVars, array $newVars): void
    {
        $this->removeVoucherIndexes($id, $oldVars);

        try {
            $this->enforceVoucherInsert($id, $newVars);
        } catch (RuntimeException $e) {
            $this->enforceVoucherInsert($id, $oldVars);
            throw $e;
        }
    }

    /**
     * Releases a voucher's active-context slot when it transitions to a terminal
     * state, mirroring the STORED active_context_key going NULL so a fresh active
     * voucher with the same context can be created.
     *
     * @param int $companyId The company scope
     * @param string $contextKey The voucher context key
     * @param string $oldStatus The status before the update
     * @param string $newStatus The status after the update
     */
    protected function releaseActiveContextOnTerminal(
        int $companyId,
        string $contextKey,
        string $oldStatus,
        string $newStatus
    ): void {
        $wasActive = $this->fakeActiveContextKey($oldStatus, $contextKey);
        $nowActive = $this->fakeActiveContextKey($newStatus, $contextKey);
        if ($wasActive !== null && $nowActive === null) {
            unset($this->activeContextIndex[$companyId . '|' . $wasActive]);
        }
    }

    /**
     * Derives active_context_key from status exactly as the STORED generated
     * column does: NULL for the terminal expired/cancelled states, else the
     * context key.
     *
     * @param string $status The voucher status
     * @param string $contextKey The voucher context key
     * @return string|null The active context key, or null when released
     */
    protected function fakeActiveContextKey(string $status, string $contextKey): ?string
    {
        return in_array($status, ['expired', 'cancelled'], true) ? null : $contextKey;
    }

    /**
     * Removes a voucher's current unique-index entries.
     *
     * @param int $id The voucher id
     * @param array $vars The voucher fields currently indexed
     */
    private function removeVoucherIndexes(int $id, array $vars): void
    {
        $companyId = (int) ($vars['company_id'] ?? 0);
        $consumer = $vars['consumer_number'] ?? null;
        $registration = $vars['registration_number'] ?? null;
        $activeContextKey = isset($vars['status'], $vars['context_key'])
            ? $this->fakeActiveContextKey((string) $vars['status'], (string) $vars['context_key'])
            : null;

        if ($consumer !== null && ($this->consumerIndex[$companyId . '|' . $consumer] ?? null) === $id) {
            unset($this->consumerIndex[$companyId . '|' . $consumer]);
        }
        if ($registration !== null && ($this->registrationIndex[$companyId . '|' . $registration] ?? null) === $id) {
            unset($this->registrationIndex[$companyId . '|' . $registration]);
        }
        if (
            $activeContextKey !== null
            && ($this->activeContextIndex[$companyId . '|' . $activeContextKey] ?? null) === $id
        ) {
            unset($this->activeContextIndex[$companyId . '|' . $activeContextKey]);
        }
    }

    /**
     * @param array<string, int> $index The unique index to check
     * @param string $key The composite index key
     * @param string $constraint The constraint name (for the error message)
     */
    private function assertUnique(array $index, string $key, string $constraint): void
    {
        if (isset($index[$key])) {
            throw new RuntimeException("Duplicate entry for key $constraint");
        }
    }
}
