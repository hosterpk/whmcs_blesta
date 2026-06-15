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
            $this->scopedInsert(
                'kuickpay_reconcile_locks',
                $company_id,
                [
                    'lock_name' => $lock_name,
                    'owner_token' => $owner_token,
                    'date_acquired' => date('Y-m-d H:i:s'),
                    'date_expires' => $expires,
                    'date_heartbeat' => date('Y-m-d H:i:s'),
                ],
                ['lock_name', 'owner_token', 'date_acquired', 'date_expires', 'date_heartbeat']
            );

            return true;
        } catch (Exception $e) {
            // Only a duplicate-key collision on the (company_id, lock_name) unique
            // key means the lock is genuinely held -> report it as false. Any other
            // exception (connection loss, etc.) is an infrastructure failure that
            // must SURFACE, not masquerade as 'lock_held' and silently skip the run.
            if ($this->isDuplicateKeyViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Detects a unique/duplicate-key constraint violation.
     *
     * Recognises MySQL driver code 1062 so a genuine "lock already held" insert is
     * distinguished from other SQLSTATE 23000 integrity errors.
     *
     * @param Exception $e The caught exception
     * @return bool True only for a duplicate-key collision
     */
    private function isDuplicateKeyViolation(Exception $e): bool
    {
        return $e instanceof PDOException
            && isset($e->errorInfo[1])
            && (int) $e->errorInfo[1] === 1062;
    }

    public function reclaimStale(int $company_id, string $lock_name, string $owner_token, string $expires): bool
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->scopedUpdate(
            'kuickpay_reconcile_locks',
            $company_id,
            [
                'owner_token' => $owner_token,
                'date_acquired' => $now,
                'date_expires' => $expires,
                'date_heartbeat' => $now,
            ],
            ['owner_token', 'date_acquired', 'date_expires', 'date_heartbeat'],
            [
                ['lock_name', '=', $lock_name],
                ['date_expires', '<', 'NOW()', false, false],
            ]
        );

        return $statement->rowCount() === 1;
    }

    public function release(int $company_id, string $lock_name, string $owner_token): void
    {
        $this->scopedDelete('kuickpay_reconcile_locks', $company_id, [
            ['lock_name', '=', $lock_name],
            ['owner_token', '=', $owner_token],
        ]);
    }
}
