<?php

use PHPUnit\Framework\TestCase;

class KuickPayVoucherRepositoryTest extends TestCase
{
    public function testFindActiveByKuickpayReferenceDelegatesToModel()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->findActiveByKuickpayReference('KP-REF', 7, 9);

        $this->assertSame($model->activeVoucher, $result);
        $this->assertSame(['KP-REF', 7, 9], $model->referenceCall);
    }

    public function testFindActiveByInvoiceIdDelegatesToModel()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->findActiveByInvoiceId(55, 7, 9);

        $this->assertSame($model->activeVoucher, $result);
        $this->assertSame([55, 7, 9], $model->invoiceCall);
    }

    private function repository($model): KuickPayVoucherRepository
    {
        $reflection = new ReflectionClass(KuickPayVoucherRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $repository->KuickpayVouchers = $model;

        return $repository;
    }
}

class KuickPayVoucherRepositoryFakeVoucherModel
{
    public ?array $referenceCall = null;
    public ?array $invoiceCall = null;
    public stdClass $activeVoucher;

    public function __construct()
    {
        $this->activeVoucher = (object) ['id' => 123];
    }

    public function findActiveByKuickpayReference(string $reference, int $company_id, int $excludeVoucherId = 0)
    {
        $this->referenceCall = [$reference, $company_id, $excludeVoucherId];

        return $this->activeVoucher;
    }

    public function findActiveByInvoiceId(int $invoice_id, int $company_id, int $excludeVoucherId = 0)
    {
        $this->invoiceCall = [$invoice_id, $company_id, $excludeVoucherId];

        return $this->activeVoucher;
    }
}
