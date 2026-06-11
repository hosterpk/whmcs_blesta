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
        if (!$repository) {
            if (!class_exists('KuickPayAuditRepository', false)) {
                $repositoryFile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'KuickPayAuditRepository.php';
                if (class_exists('Loader', false)) {
                    Loader::load($repositoryFile);
                } else {
                    require_once $repositoryFile;
                }
            }

            $repository = new KuickPayAuditRepository();
        }

        $this->repository = $repository;
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
