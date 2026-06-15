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

    public function testGetPendingByInvoiceSetDelegatesToModelWithSortedUniqueIds()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->getPendingByInvoiceSet([56, 55, 55], 7);

        $this->assertSame($model->activeVoucher, $result);
        $this->assertSame([[55, 56], 7], $model->invoiceSetCall);
    }

    public function testGetPostableDelegatesToModel()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->getPostable(7, 100, 12);

        $this->assertSame([$model->activeVoucher], $result);
        $this->assertSame([7, 100, 12], $model->postableCall);
    }

    public function testGetExpirableDelegatesToModel()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->getExpirable(7, 100, 12);

        $this->assertSame([$model->activeVoucher], $result);
        $this->assertSame([7, 100, 12], $model->expirableCall);
    }

    public function testExpireDelegatesToModel()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $repository = $this->repository($model);

        $result = $repository->expire(123, 7);

        $this->assertTrue($result);
        $this->assertSame([123, 7], $model->expireCall);
    }

    public function testLockedReadsDelegateToModels()
    {
        $model = new KuickPayVoucherRepositoryFakeVoucherModel();
        $invoiceModel = new KuickPayVoucherRepositoryFakeVoucherInvoiceModel();
        $repository = $this->repository($model, $invoiceModel);

        $voucher = $repository->getForUpdate(44, 7);
        $links = $repository->getInvoiceLinksForUpdate(44, 7);

        $this->assertSame($model->activeVoucher, $voucher);
        $this->assertSame([$invoiceModel->invoiceLink], $links);
        $this->assertSame([44, 7], $model->forUpdateCall);
        $this->assertSame([44, 7], $invoiceModel->forUpdateCall);
    }

    private function repository($model, $invoiceModel = null): KuickPayVoucherRepository
    {
        $reflection = new ReflectionClass(KuickPayVoucherRepository::class);
        $repository = $reflection->newInstanceWithoutConstructor();
        $repository->KuickpayVouchers = $model;
        $repository->KuickpayVoucherInvoices = $invoiceModel ?? new KuickPayVoucherRepositoryFakeVoucherInvoiceModel();

        return $repository;
    }
}

class KuickPayVoucherRepositoryFakeVoucherModel
{
    public ?array $referenceCall = null;
    public ?array $invoiceCall = null;
    public ?array $invoiceSetCall = null;
    public ?array $postableCall = null;
    public ?array $expirableCall = null;
    public ?array $expireCall = null;
    public ?array $forUpdateCall = null;
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

    public function getPendingByInvoiceSet(array $invoiceIds, int $company_id)
    {
        $this->invoiceSetCall = [$invoiceIds, $company_id];

        return $this->activeVoucher;
    }

    public function getPostable(int $company_id, int $limit, int $afterId = 0): array
    {
        $this->postableCall = [$company_id, $limit, $afterId];

        return [$this->activeVoucher];
    }

    public function getExpirable(int $company_id, int $limit, int $afterId = 0): array
    {
        $this->expirableCall = [$company_id, $limit, $afterId];

        return [$this->activeVoucher];
    }

    public function expire(int $voucher_id, int $company_id): bool
    {
        $this->expireCall = [$voucher_id, $company_id];

        return true;
    }

    public function getForUpdate(int $voucher_id, int $company_id)
    {
        $this->forUpdateCall = [$voucher_id, $company_id];

        return $this->activeVoucher;
    }
}

class KuickPayVoucherRepositoryFakeVoucherInvoiceModel
{
    public ?array $forUpdateCall = null;
    public stdClass $invoiceLink;

    public function __construct()
    {
        $this->invoiceLink = (object) ['voucher_id' => 44, 'invoice_id' => 55, 'amount' => '1000.00'];
    }

    public function getByVoucherIdForUpdate(int $voucher_id, int $company_id): array
    {
        $this->forUpdateCall = [$voucher_id, $company_id];

        return [$this->invoiceLink];
    }
}
