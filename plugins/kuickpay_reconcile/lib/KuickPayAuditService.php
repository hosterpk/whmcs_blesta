<?php
/**
 * KuickPay audit write service
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayAuditService
{
    private $repository;

    public function __construct($repository = null)
    {
        $this->repository = $repository ?: new KuickPayAuditRepository();
    }

    public function record(string $eventName, array $context): void
    {
        $payload = $context['payload'] ?? [];

        $this->repository->add([
            'company_id' => (int) $context['company_id'],
            'voucher_id' => $context['voucher_id'] ?? null,
            'run_id' => $context['run_id'] ?? null,
            'event_name' => $eventName,
            'redacted_trace_id' => $context['redacted_trace_id'] ?? null,
            'evidence_hash' => $context['evidence_hash'] ?? null,
            'payload' => empty($payload) ? null : json_encode($payload),
            'date_created' => date('Y-m-d H:i:s'),
        ]);
    }
}
