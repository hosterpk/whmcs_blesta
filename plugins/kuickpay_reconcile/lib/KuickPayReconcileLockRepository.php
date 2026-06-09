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
