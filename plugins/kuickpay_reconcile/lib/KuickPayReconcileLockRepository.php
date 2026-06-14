<?php
/**
 * KuickPay reconciliation lock repository
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayReconcileLockRepository
{
    public function __construct()
    {
        Loader::loadModels($this, ['KuickpayReconcile.KuickpayReconcileLocks']);
    }

    /**
     * Acquires a named lock, returning its owner token or null when held.
     *
     * insertLock() now returns false ONLY for a duplicate-key collision (the lock
     * is genuinely held) and throws on any infrastructure failure. That throw is
     * deliberately NOT caught here so a real infra failure surfaces to the caller
     * instead of silently falling through to reclaimStale()/null and masquerading
     * as 'lock_held'. The duplicate-key -> reclaimStale() stale-reclaim path is
     * preserved exactly.
     *
     * @param int $company_id The company ID
     * @param string $lockName The lock name
     * @param int $ttlSeconds The lock TTL in seconds
     * @return string|null The owner token, or null when the lock is held
     */
    public function acquire(int $company_id, string $lockName, int $ttlSeconds): ?string
    {
        $owner_token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);

        if ($this->KuickpayReconcileLocks->insertLock($company_id, $lockName, $owner_token, $expires)) {
            return $owner_token;
        }

        if ($this->KuickpayReconcileLocks->reclaimStale($company_id, $lockName, $owner_token, $expires)) {
            return $owner_token;
        }

        return null;
    }

    public function release(int $company_id, string $lockName, string $ownerToken): void
    {
        $this->KuickpayReconcileLocks->release($company_id, $lockName, $ownerToken);
    }
}
