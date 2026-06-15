<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/KuickPayReconcileServiceTest.php';

/**
 * Cross-company isolation regression (Story 5.5 AC1.5 / Epic 4 retro AI-8).
 *
 * Two halves, both required for the guarantee to be real:
 *  1. Behavioural: with company-aware repository fakes (AC2 fidelity), a query
 *     or edit issued for company A never reads or mutates company B's rows.
 *     This is only meaningful BECAUSE the fakes honour company_id — a fake that
 *     ignored it would pass vacuously, exactly the Epic-3 failure mode.
 *  2. Structural: the base-model scoped-query primitives make omitting the
 *     tenant a TYPE error, not a silent leak — each declares a required
 *     int $companyId, so a query that forgets the scope cannot be expressed.
 */
class KuickPayCompanyScopeIsolationTest extends TestCase
{
    public function testCompanyScopedReadsAndEditsNeverCrossTenants()
    {
        $companyA = 7;
        $companyB = 9;
        $voucherA = (object) [
            'id' => 1,
            'company_id' => $companyA,
            'status' => 'confirmed_unposted',
            'consumer_number' => 'CON-A',
            'currency' => 'PKR',
            'amount' => '1000.00',
            'date_expires' => null,
        ];
        $voucherB = (object) [
            'id' => 2,
            'company_id' => $companyB,
            'status' => 'confirmed_unposted',
            'consumer_number' => 'CON-B',
            'currency' => 'PKR',
            'amount' => '2000.00',
            'date_expires' => null,
        ];
        $repo = new KuickPayReconcileFakeVoucherRepository([$voucherA, $voucherB]);

        // A's reads never surface B's row...
        $this->assertFalse($repo->getForCompany(2, $companyA));
        $this->assertNull($repo->getWithInvoices(2, $companyA));
        $this->assertNull($repo->getByConsumerNumber('CON-B', $companyA));
        // ...but A still sees its own.
        $this->assertSame($voucherA, $repo->getForCompany(1, $companyA));

        // An edit issued for A against B's id affects zero rows and mutates nothing.
        $this->assertSame(0, $repo->edit(2, $companyA, ['status' => 'cancelled']));
        $this->assertSame('confirmed_unposted', $voucherB->status);

        // The in-scope edit does mutate, and reports one affected row.
        $this->assertSame(1, $repo->edit(1, $companyA, ['status' => 'posted']));
        $this->assertSame('posted', $voucherA->status);
    }

    /**
     * @dataProvider scopedHelperProvider
     */
    public function testScopedHelpersRequireATypedCompanyId(string $method, int $companyParamIndex)
    {
        $reflection = new ReflectionMethod(KuickpayReconcileModel::class, $method);
        $params = $reflection->getParameters();

        $this->assertArrayHasKey($companyParamIndex, $params, "$method should declare a company parameter");
        $param = $params[$companyParamIndex];

        $this->assertSame('companyId', $param->getName());
        $this->assertFalse($param->isOptional(), "$method companyId must be required (no default)");
        $this->assertTrue($param->hasType(), "$method companyId must be typed");
        $this->assertSame('int', (string) $param->getType());
    }

    public function testScopedInsertRejectsNonPositiveCompanyId()
    {
        $probe = new KuickPayCompanyScopeProbeModel();

        $this->expectException(InvalidArgumentException::class);
        $probe->probeInsert('kuickpay_vouchers', 0, [], []);
    }

    public function scopedHelperProvider()
    {
        return [
            'scopedSelect' => ['scopedSelect', 1],
            'scopedChildSelect' => ['scopedChildSelect', 3],
            'scopedUpdate' => ['scopedUpdate', 1],
            'scopedDelete' => ['scopedDelete', 1],
            'scopedInsert' => ['scopedInsert', 1],
        ];
    }
}

class KuickPayCompanyScopeProbeModel extends KuickpayReconcileModel
{
    public function __construct()
    {
    }

    public function probeInsert(string $table, int $companyId, array $vars, array $fields)
    {
        return $this->scopedInsert($table, $companyId, $vars, $fields);
    }
}
