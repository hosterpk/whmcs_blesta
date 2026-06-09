<?php
/**
 * KuickPay reconcile locks model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.models
 */
class KuickpayReconcileLocks extends KuickpayReconcileModel
{
    public function insertLock(int $company_id, string $lock_name, string $owner_token, string $expires): bool
    {
        try {
            $this->Record->insert('kuickpay_reconcile_locks', [
                'company_id' => $company_id,
                'lock_name' => $lock_name,
                'owner_token' => $owner_token,
                'date_acquired' => date('Y-m-d H:i:s'),
                'date_expires' => $expires,
                'date_heartbeat' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function reclaimStale(int $company_id, string $lock_name, string $owner_token, string $expires): bool
    {
        $statement = $this->Record->query(
            "UPDATE `kuickpay_reconcile_locks`
                SET `owner_token` = ?, `date_acquired` = ?, `date_expires` = ?, `date_heartbeat` = ?
              WHERE `company_id` = ? AND `lock_name` = ? AND `date_expires` < NOW()",
            $owner_token,
            date('Y-m-d H:i:s'),
            $expires,
            date('Y-m-d H:i:s'),
            $company_id,
            $lock_name
        );

        return $statement->rowCount() === 1;
    }

    public function release(int $company_id, string $lock_name, string $owner_token): void
    {
        $this->Record
            ->where('company_id', '=', $company_id)
            ->where('lock_name', '=', $lock_name)
            ->where('owner_token', '=', $owner_token)
            ->delete('kuickpay_reconcile_locks');
    }
}
