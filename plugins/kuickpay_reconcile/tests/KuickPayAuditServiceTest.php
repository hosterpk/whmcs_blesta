<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Loader', false)) {
    class Loader
    {
        public static function load($file): void
        {
            require_once $file;
        }

        public static function loadModels($instance, array $models): void
        {
        }
    }
}

require_once __DIR__ . '/../lib/KuickPayAuditService.php';

class KuickPayAuditServiceTest extends TestCase
{
    public function testDefaultConstructorLoadsAuditRepositoryDependency()
    {
        $service = new KuickPayAuditService();

        $this->assertInstanceOf(KuickPayAuditService::class, $service);
        $this->assertTrue(class_exists('KuickPayAuditRepository', false));
    }

    public function testConstructorUsesInjectedRepository()
    {
        $repository = new KuickPayAuditServiceFakeRepository();
        $service = new KuickPayAuditService($repository);

        $service->record('voucher.test', [
            'company_id' => 1,
            'payload' => ['ok' => true],
        ]);

        $this->assertSame('voucher.test', $repository->events[0]['event_name']);
        $this->assertSame(1, $repository->events[0]['company_id']);
    }
}

class KuickPayAuditServiceFakeRepository
{
    public array $events = [];

    public function add(array $vars): void
    {
        $this->events[] = $vars;
    }
}
