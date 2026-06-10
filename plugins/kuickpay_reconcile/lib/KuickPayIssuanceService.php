<?php
/**
 * KuickPay voucher issuance persistence service.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayIssuanceService
{
    private $voucherRepository;
    private $auditService;

    public function __construct($voucherRepository = null, $auditService = null)
    {
        if ($voucherRepository === null) {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherRepository.php');
            $voucherRepository = new KuickPayVoucherRepository();
        }
        if ($auditService === null) {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayAuditService.php');
            $auditService = new KuickPayAuditService();
        }

        $this->voucherRepository = $voucherRepository;
        $this->auditService = $auditService;
    }

    public function recordIssueOutcome(int $voucherId, int $companyId, KuickPayEvidence $evidence): void
    {
        $status = $this->issuanceStatus($evidence);
        $diagnostic = $evidence->toArray();

        $this->voucherRepository->edit($voucherId, $companyId, [
            'status' => $status,
            'kuickpay_reference' => $evidence->reference(),
            'raw_status' => $evidence->rawStatus(),
            'error_class' => $evidence->errorClass(),
            'evidence_hash' => $evidence->evidenceHash(),
            'diagnostic_summary' => json_encode($diagnostic),
            'date_last_checked' => date('Y-m-d H:i:s'),
        ]);

        $this->auditService->record($status === 'pending' ? 'voucher.issued' : 'evidence.rejected', [
            'company_id' => $companyId,
            'voucher_id' => $voucherId,
            'redacted_trace_id' => $evidence->redactedTraceId(),
            'evidence_hash' => $evidence->evidenceHash(),
            'payload' => [
                'status' => $status,
                'error_class' => $evidence->errorClass(),
                'raw_status' => $evidence->rawStatus(),
                'validation_errors' => $evidence->validationErrors(),
            ],
        ]);
    }

    private function issuanceStatus(KuickPayEvidence $evidence): string
    {
        if (in_array($evidence->errorClass(), ['timeout', 'transport_error'], true)) {
            return 'retry';
        }

        return $evidence->status();
    }
}
