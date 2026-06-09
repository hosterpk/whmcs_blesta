<?php
/**
 * KuickPay pending-voucher reconciliation service.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayReconcileService
{
    public const CRON_INTERVAL_MINUTES = 5;
    public const BATCH_SIZE = 100;
    public const MAX_RUNTIME_SECONDS = 240;
    public const LOCK_TTL_SECONDS = 600;
    public const RETRY_LIMIT = 5;
    public const PENDING_RECHECK_MINUTES = 30;
    private const LOCK_NAME = 'reconcile_pending';

    private $voucherRepository;
    private $runRepository;
    private $itemRepository;
    private $lockRepository;
    private $auditService;
    private $parser;
    private $clientFactory;
    private $gatewayConfig;

    public function __construct(array $dependencies = [])
    {
        if (empty($dependencies)) {
            $this->loadRuntimeDependencies();
        }

        $this->voucherRepository = $dependencies['voucher_repository'] ?? new KuickPayVoucherRepository();
        $this->runRepository = $dependencies['run_repository'] ?? new KuickPayReconciliationRunRepository();
        $this->itemRepository = $dependencies['item_repository'] ?? new KuickPayReconciliationItemRepository();
        $this->lockRepository = $dependencies['lock_repository'] ?? new KuickPayReconcileLockRepository();
        $this->auditService = $dependencies['audit_service'] ?? new KuickPayAuditService();
        $this->parser = $dependencies['parser'] ?? new KuickPayResponseParser();
        $this->clientFactory = $dependencies['client_factory'] ?? null;
        $this->gatewayConfig = $dependencies['gateway_config'] ?? null;
    }

    public function runCron(int $company_id): array
    {
        return $this->run($company_id, 'cron');
    }

    public function run(int $company_id, string $trigger_type = 'cron'): array
    {
        $gateway_config = $this->gatewayConfig ?? $this->gatewayConfigForCompany($company_id);
        if ($gateway_config === null) {
            return ['status' => 'skipped', 'reason' => 'kuickpay_unavailable'];
        }

        $owner_token = $this->lockRepository->acquire($company_id, self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if ($owner_token === null) {
            return ['status' => 'skipped', 'reason' => 'lock_held'];
        }

        $cursor = $this->runRepository->getResumeCursor($company_id);
        $run_id = 0;
        $counts = $this->initialCounts();
        $status = 'completed';
        $start = time();

        try {
            $run_id = $this->runRepository->open($company_id, $trigger_type, $cursor);
            $this->auditService->record('reconciliation.run.started', [
                'company_id' => $company_id,
                'run_id' => $run_id,
                'payload' => ['trigger_type' => $trigger_type, 'cursor' => $cursor],
            ]);

            $vouchers = $this->voucherRepository->getReconcilable(
                $company_id,
                self::BATCH_SIZE,
                $cursor,
                date('Y-m-d H:i:s', time() - (self::PENDING_RECHECK_MINUTES * 60))
            );
            $counts['total_eligible'] = count($vouchers);
            $client = $this->client($gateway_config);

            foreach ($vouchers as $voucher) {
                if (time() - $start >= self::MAX_RUNTIME_SECONDS) {
                    $status = 'aborted';
                    break;
                }

                $cursor = (int) $voucher->id;
                $this->runRepository->updateCursor($run_id, $cursor);
                $outcome = $this->processVoucher($company_id, $run_id, $voucher, $client);
                $counts = $this->countOutcome($counts, $outcome['new_status'], $outcome['error']);
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $counts['total_errors']++;
        } finally {
            if ($run_id > 0) {
                $summary = json_encode(['status' => $status, 'counts' => $counts]);
                $this->runRepository->close($run_id, $status, $counts, $cursor, $summary);
                $this->auditService->record('reconciliation.run.completed', [
                    'company_id' => $company_id,
                    'run_id' => $run_id,
                    'payload' => ['status' => $status, 'counts' => $counts],
                ]);
            }

            $this->lockRepository->release($company_id, self::LOCK_NAME, $owner_token);
        }

        return ['status' => $status, 'run_id' => $run_id, 'counts' => $counts, 'cursor' => $cursor];
    }

    public function processVoucher(int $company_id, int $run_id, $voucher, $client): array
    {
        $prior_status = (string) $voucher->status;
        $error = false;

        try {
            $transport = $client->billPaymentInquiry($this->buildInquiryRequest($voucher));
            $evidence = $this->parser->parse($transport, $this->buildParserContext($voucher));
            $new_status = $this->persistEvidence($company_id, $voucher, $evidence);

            $this->itemRepository->record([
                'run_id' => $run_id,
                'voucher_id' => (int) $voucher->id,
                'prior_status' => $prior_status,
                'new_status' => $new_status,
                'error_class' => $evidence->errorClass(),
                'evidence_hash' => $evidence->evidenceHash(),
                'redacted_trace_id' => $evidence->redactedTraceId(),
                'date_created' => date('Y-m-d H:i:s'),
            ]);

            $this->recordEvidenceAudit($company_id, $run_id, $voucher, $evidence, $new_status);

            return ['new_status' => $new_status, 'error' => false];
        } catch (Throwable $e) {
            $error = true;
            $new_status = $prior_status;
            $this->itemRepository->record([
                'run_id' => $run_id,
                'voucher_id' => (int) $voucher->id,
                'prior_status' => $prior_status,
                'new_status' => $new_status,
                'error_class' => 'reconcile_exception',
                'date_created' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['new_status' => $new_status, 'error' => $error];
    }

    public function buildInquiryRequest($voucher): array
    {
        return ['RegistrationNumber' => (string) $voucher->registration_number];
    }

    public function buildParserContext($voucher): array
    {
        return [
            'expected_amount' => (string) $voucher->amount,
            'expected_currency' => (string) $voucher->currency,
            'expected_registration_number' => (string) $voucher->registration_number,
        ];
    }

    private function persistEvidence(int $company_id, $voucher, KuickPayEvidence $evidence): string
    {
        $retry_count = (int) ($voucher->retry_count ?? 0);
        $new_status = $this->mappedStatus((string) $voucher->status, $evidence, $retry_count);

        $vars = [
            'status' => $new_status,
            'date_last_checked' => date('Y-m-d H:i:s'),
            'raw_status' => $evidence->rawStatus(),
            'error_class' => $evidence->errorClass(),
            'evidence_hash' => $evidence->evidenceHash(),
            'diagnostic_summary' => $this->diagnosticSummary($evidence),
        ];

        if ($evidence->status() === 'retry') {
            $vars['retry_count'] = $retry_count + 1;
        }

        if ($evidence->isConfirmedUnposted()) {
            $vars['amount'] = $evidence->amount();
            $vars['date_paid'] = $this->paidDate($evidence);
            $vars['kuickpay_reference'] = $evidence->reference();
        }

        // Story 3.3 stops at confirmed_unposted evidence. Posting and invoice mutation belong to Stories 3.4/3.5.
        $this->voucherRepository->edit((int) $voucher->id, $company_id, $vars);

        return $new_status;
    }

    private function mappedStatus(string $current_status, KuickPayEvidence $evidence, int $retry_count): string
    {
        if (!in_array($current_status, ['pending', 'retry'], true)) {
            return $current_status;
        }

        if ($evidence->status() === 'retry') {
            return $retry_count + 1 >= self::RETRY_LIMIT ? 'manual_review' : 'retry';
        }

        if (in_array($evidence->status(), ['pending', 'confirmed_unposted', 'expired', 'manual_review'], true)) {
            return $evidence->status();
        }

        return $evidence->status() === 'failed' ? 'failed' : 'manual_review';
    }

    private function diagnosticSummary(KuickPayEvidence $evidence): string
    {
        return json_encode([
            'status' => $evidence->status(),
            'raw_status' => $evidence->rawStatus(),
            'error_class' => $evidence->errorClass(),
            'evidence_hash' => $evidence->evidenceHash(),
            'redacted_trace_id' => $evidence->redactedTraceId(),
            'validation_errors' => $evidence->validationErrors(),
        ]);
    }

    private function paidDate(KuickPayEvidence $evidence): ?string
    {
        if (!$evidence->paidAt()) {
            return null;
        }

        return substr($evidence->paidAt(), 0, 10) . ' 00:00:00';
    }

    private function recordEvidenceAudit(
        int $company_id,
        int $run_id,
        $voucher,
        KuickPayEvidence $evidence,
        string $new_status
    ): void {
        $context = [
            'company_id' => $company_id,
            'run_id' => $run_id,
            'voucher_id' => (int) $voucher->id,
            'redacted_trace_id' => $evidence->redactedTraceId(),
            'evidence_hash' => $evidence->evidenceHash(),
            'payload' => [
                'prior_status' => (string) $voucher->status,
                'new_status' => $new_status,
                'error_class' => $evidence->errorClass(),
            ],
        ];

        $this->auditService->record('evidence.received', $context);

        if ($new_status === 'confirmed_unposted') {
            $this->auditService->record('evidence.matched', $context);
        } elseif ($new_status === 'retry') {
            $this->auditService->record('evidence.retry_decision', $context);
        } elseif (in_array($new_status, ['manual_review', 'failed'], true)) {
            $this->auditService->record('evidence.rejected', $context);
        }
    }

    private function gatewayConfigForCompany(int $company_id): ?array
    {
        Loader::loadModels($this, ['GatewayManager']);
        $gateway = $this->GatewayManager->getInstalledNonmerchant($company_id, 'kuickpay', null, 'PKR');
        if (!$gateway) {
            return null;
        }

        $meta = [];
        foreach ((array) ($gateway->meta ?? []) as $field) {
            $meta[$field->key] = $field->value;
        }

        if (!$this->truthy($meta['reconciliation_enabled'] ?? null)) {
            return null;
        }

        return [
            'wsdl_url' => $meta['wsdl_url'] ?? '',
            'soap_timeout' => $meta['soap_timeout'] ?? '',
            'institution_id' => $meta['institution_id'] ?? '',
            'voucher_username' => $meta['voucher_username'] ?? '',
            'voucher_password' => $meta['voucher_password'] ?? '',
            'inquiry_username' => $meta['inquiry_username'] ?? '',
            'inquiry_password' => $meta['inquiry_password'] ?? '',
            'inquiry_same_as_voucher' => $meta['inquiry_same_as_voucher'] ?? 'false',
            'logging_enabled' => $meta['logging_enabled'] ?? 'false',
        ];
    }

    private function client(array $gateway_config)
    {
        if ($this->clientFactory) {
            return call_user_func($this->clientFactory, $gateway_config);
        }

        return new KuickPaySoapClient($gateway_config);
    }

    private function truthy($value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function initialCounts(): array
    {
        return [
            'total_eligible' => 0,
            'total_checked' => 0,
            'total_confirmed' => 0,
            'total_retry' => 0,
            'total_manual_review' => 0,
            'total_expired' => 0,
            'total_failed' => 0,
            'total_errors' => 0,
        ];
    }

    private function countOutcome(array $counts, string $new_status, bool $error): array
    {
        $counts['total_checked']++;
        if ($error) {
            $counts['total_errors']++;
        }

        if ($new_status === 'confirmed_unposted') {
            $counts['total_confirmed']++;
        } elseif ($new_status === 'retry') {
            $counts['total_retry']++;
        } elseif ($new_status === 'manual_review') {
            $counts['total_manual_review']++;
        } elseif ($new_status === 'expired') {
            $counts['total_expired']++;
        } elseif ($new_status === 'failed') {
            $counts['total_failed']++;
        }

        return $counts;
    }

    private function loadRuntimeDependencies(): void
    {
        $plugin_dir = dirname(__FILE__);
        $gateway_dir = dirname(dirname(dirname($plugin_dir))) . DS . 'components' . DS . 'gateways'
            . DS . 'nonmerchant' . DS . 'kuickpay' . DS . 'lib';

        Loader::load($plugin_dir . DS . 'KuickPayVoucherRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayReconciliationRunRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayReconciliationItemRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayReconcileLockRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayAuditRepository.php');
        Loader::load($plugin_dir . DS . 'KuickPayAuditService.php');
        Loader::load($gateway_dir . DS . 'KuickPayRedactor.php');
        Loader::load($gateway_dir . DS . 'KuickPayEvidence.php');
        Loader::load($gateway_dir . DS . 'KuickPayResponseParser.php');
        Loader::load($gateway_dir . DS . 'KuickPaySoapClient.php');
    }
}
