<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/kuickpay_reconcile_locks.php';

class KuickPayReconcileLocksTest extends TestCase
{
    public function testInsertLockReturnsFalseOnDuplicateKeyViolation()
    {
        // SQLSTATE 23000 / MySQL 1062: the (company_id, lock_name) unique key
        // throws this when the lock is genuinely held. That is the ONE case that
        // means "lock_held"; insertLock must report it as false.
        $duplicate = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $duplicate->errorInfo = ['23000', 1062, "Duplicate entry '1-reconcile_pending' for key 'uniq'"];

        $model = $this->lockModel($duplicate);

        $this->assertFalse($model->insertLock(1, 'reconcile_pending', 'owner-token', '2026-06-14 00:00:00'));
    }

    public function testInsertLockSurfacesInfrastructureFailure()
    {
        // A connection-level failure (server gone away) is NOT a held lock. It
        // must surface, not masquerade as 'lock_held' by silently returning false.
        $infra = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $infra->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        $model = $this->lockModel($infra);

        $this->expectException(PDOException::class);
        $model->insertLock(1, 'reconcile_pending', 'owner-token', '2026-06-14 00:00:00');
    }

    public function testInsertLockSurfacesNonDuplicateIntegrityFailure()
    {
        // SQLSTATE 23000 is a broad integrity class; only MySQL 1062 duplicate-key
        // means "lock held". Other integrity failures must surface.
        $integrity = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1048 Column cannot be null');
        $integrity->errorInfo = ['23000', 1048, "Column 'owner_token' cannot be null"];

        $model = $this->lockModel($integrity);

        $this->expectException(PDOException::class);
        $model->insertLock(1, 'reconcile_pending', 'owner-token', '2026-06-14 00:00:00');
    }

    public function testInsertLockReturnsTrueOnSuccessfulInsert()
    {
        $model = $this->lockModel(null);

        $this->assertTrue($model->insertLock(1, 'reconcile_pending', 'owner-token', '2026-06-14 00:00:00'));
    }

    private function lockModel(?Throwable $throwOnInsert): KuickpayReconcileLocks
    {
        $model = (new ReflectionClass(KuickpayReconcileLocks::class))->newInstanceWithoutConstructor();
        $model->Record = new Kp53FakeLockRecord($throwOnInsert);

        return $model;
    }
}

class Kp53FakeLockRecord
{
    public array $inserts = [];
    private ?Throwable $throwOnInsert;

    public function __construct(?Throwable $throwOnInsert)
    {
        $this->throwOnInsert = $throwOnInsert;
    }

    public function insert($table, array $vars, $fields = null)
    {
        $this->inserts[] = [$table, $vars];

        if ($this->throwOnInsert !== null) {
            throw $this->throwOnInsert;
        }

        return $this;
    }
}
