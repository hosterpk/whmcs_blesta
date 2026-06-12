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
    private const LOCK_NAME_EXPIRE = 'expire_vouchers';

    private $voucherRepository;
    private $runRepository;
    private $itemRepository;
    private $lockRepository;
    private $auditService;
    private $parser;
    private $evidenceValidator;
    private $clientFactory;
    private $logger;
    private $gatewayConfig;
    private bool $hasGatewayConfigOverride = false;

    public function __construct(array $dependencies = [])
    {
        if ($this->needsRuntimeDependencies($dependencies)) {
            $this->loadRuntimeDependencies();
        }

        $this->voucherRepository = $dependencies['voucher_repository'] ?? new KuickPayVoucherRepository();
        $this->runRepository = $dependencies['run_repository'] ?? new KuickPayReconciliationRunRepository();
        $this->itemRepository = $dependencies['item_repository'] ?? new KuickPayReconciliationItemRepository();
        $this->lockRepository = $dependencies['lock_repository'] ?? new KuickPayReconcileLockRepository();
        $this->auditService = $dependencies['audit_service'] ?? new KuickPayAuditService();
        $this->parser = $dependencies['parser'] ?? new KuickPayResponseParser();
        $this->evidenceValidator = $dependencies['evidence_validator'] ?? new KuickPayEvidenceValidator();
        $this->clientFactory = $dependencies['client_factory'] ?? null;
        $this->logger = $dependencies['logger'] ?? null;
        $this->hasGatewayConfigOverride = array_key_exists('gateway_config', $dependencies);
        $this->gatewayConfig = $dependencies['gateway_config'] ?? null;
    }

    public function runCron(int $company_id): array
    {
        return $this->run($company_id, 'cron');
    }

    public function expirePending(int $company_id): array
    {
        $counts = ['processed' => 0, 'expired' => 0, 'errors' => 0];
        $owner_token = $this->lockRepository->acquire($company_id, self::LOCK_NAME_EXPIRE, self::LOCK_TTL_SECONDS);
        if ($owner_token === null) {
            return ['status' => 'skipped', 'reason' => 'lock_held', 'counts' => $counts];
        }

        $status = 'completed';
        $start = time();

        try {
            $vouchers = $this->voucherRepository->getExpirable($company_id, self::BATCH_SIZE);

            foreach ($vouchers as $voucher) {
                if (time() - $start >= self::MAX_RUNTIME_SECONDS) {
                    $status = 'aborted';
                    break;
                }

                $counts['processed']++;

                try {
                    $prior_status = (string) $voucher->status;
                    // Status-guarded UPDATE: only audit + count a row this call
                    // actually transitioned, so a voucher concurrently moved out
                    // of pending/retry (e.g. confirmed_unposted by reconcile under
                    // a PHP/DB clock skew) is never clobbered back to 'expired'.
                    if ($this->voucherRepository->expire((int) $voucher->id, $company_id)) {
                        $this->auditService->record('voucher.expired', [
                            'company_id' => $company_id,
                            'voucher_id' => (int) $voucher->id,
                            'payload' => ['prior_status' => $prior_status],
                        ]);
                        $counts['expired']++;
                    }
                } catch (Throwable $e) {
                    $counts['errors']++;
                }
            }
        } finally {
            $this->lockRepository->release($company_id, self::LOCK_NAME_EXPIRE, $owner_token);
        }

        return ['status' => $status, 'counts' => $counts];
    }

    public function run(int $company_id, string $trigger_type = 'cron'): array
    {
        $gateway_config = $this->hasGatewayConfigOverride
            ? $this->gatewayConfig
            : ($this->gatewayConfig ?? $this->gatewayConfigForCompany($company_id));
        if ($gateway_config === null) {
            return ['status' => 'skipped', 'reason' => 'kuickpay_unavailable'];
        }
        $this->gatewayConfig = $gateway_config;

        $owner_token = $this->lockRepository->acquire($company_id, self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if ($owner_token === null) {
            return ['status' => 'skipped', 'reason' => 'lock_held'];
        }

        $cursor = 0;
        $run_id = 0;
        $counts = $this->initialCounts();
        $status = 'completed';
        $start = time();

        try {
            // Resolve the resume cursor inside the try so any failure still releases the lock below.
            $cursor = $this->runRepository->getResumeCursor($company_id);
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
            try {
                if ($run_id > 0) {
                    $summary = json_encode(['status' => $status, 'counts' => $counts]);
                    $this->runRepository->close($run_id, $status, $counts, $cursor, $summary);
                    $this->auditService->record('reconciliation.run.completed', [
                        'company_id' => $company_id,
                        'run_id' => $run_id,
                        'payload' => ['status' => $status, 'counts' => $counts],
                    ]);
                }
            } catch (Throwable $closeError) {
                // Closing the run / writing audit is best-effort; the lock MUST still be released below.
            }

            $this->lockRepository->release($company_id, self::LOCK_NAME, $owner_token);
        }

        return ['status' => $status, 'run_id' => $run_id, 'counts' => $counts, 'cursor' => $cursor];
    }

    public function reconcileVoucher(int $company_id, int $voucher_id): array
    {
        $gateway_config = $this->hasGatewayConfigOverride
            ? $this->gatewayConfig
            : ($this->gatewayConfig ?? $this->gatewayConfigForCompany($company_id));
        if ($gateway_config === null) {
            return ['status' => 'skipped', 'reason' => 'kuickpay_unavailable', 'voucher_id' => $voucher_id];
        }
        $this->gatewayConfig = $gateway_config;

        $voucher = $this->voucherRepository->getForCompany($voucher_id, $company_id);
        if (!$voucher) {
            return ['status' => 'failed', 'reason' => 'voucher_not_found', 'voucher_id' => $voucher_id];
        }

        $currentStatus = (string) ($voucher->status ?? '');
        if (!in_array($currentStatus, ['pending', 'retry'], true)) {
            return ['status' => $currentStatus, 'voucher_id' => $voucher_id];
        }

        $run_id = 0;
        $counts = $this->initialCounts();
        $status = 'completed';

        try {
            $run_id = $this->runRepository->open($company_id, 'manual', 0);
            $this->auditService->record('reconciliation.run.started', [
                'company_id' => $company_id,
                'run_id' => $run_id,
                'payload' => ['trigger_type' => 'manual', 'cursor' => 0],
            ]);

            $client = $this->client($gateway_config);
            $counts['total_eligible'] = 1;
            $outcome = $this->processVoucher($company_id, $run_id, $voucher, $client);
            $counts = $this->countOutcome($counts, $outcome['new_status'], $outcome['error']);

            if ($outcome['error']) {
                return [
                    'status' => 'unavailable',
                    'reason' => 'provider_unreachable',
                    'run_id' => $run_id,
                    'voucher_id' => $voucher_id,
                ];
            }

            return [
                'status' => $outcome['new_status'],
                'run_id' => $run_id,
                'voucher_id' => $voucher_id,
            ];
        } catch (Throwable $e) {
            $status = 'failed';
            $counts['total_errors']++;

            return [
                'status' => 'failed',
                'reason' => 'run_open_failed',
                'voucher_id' => $voucher_id,
            ];
        } finally {
            try {
                if ($run_id > 0) {
                    $summary = json_encode(['status' => $status, 'counts' => $counts]);
                    $this->runRepository->close($run_id, $status, $counts, 0, $summary);
                    $this->auditService->record('reconciliation.run.completed', [
                        'company_id' => $company_id,
                        'run_id' => $run_id,
                        'payload' => ['status' => $status, 'counts' => $counts],
                    ]);
                }
            } catch (Throwable $closeError) {
                // Manual run close/audit is best-effort, matching the cron path.
            }
        }
    }

    public function runBulk(int $company_id, string $run_date, string $trigger_type = 'bulk'): array
    {
        $request = $this->buildBulkRequest($run_date);
        $gateway_config = $this->hasGatewayConfigOverride
            ? $this->gatewayConfig
            : ($this->gatewayConfig ?? $this->gatewayConfigForCompany($company_id));
        if ($gateway_config === null) {
            return ['status' => 'skipped', 'reason' => 'kuickpay_unavailable'];
        }
        $this->gatewayConfig = $gateway_config;

        $owner_token = $this->lockRepository->acquire($company_id, self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if ($owner_token === null) {
            return ['status' => 'skipped', 'reason' => 'lock_held'];
        }

        $run_id = 0;
        $counts = $this->initialCounts();
        $counts['total_unmatched'] = 0;
        $status = 'completed';
        $start = time();

        try {
            $run_id = $this->runRepository->openBulk($company_id, $run_date);
            $this->auditService->record('reconciliation.run.started', [
                'company_id' => $company_id,
                'run_id' => $run_id,
                'payload' => ['trigger_type' => $trigger_type, 'run_date' => $run_date],
            ]);

            $client = $this->client($gateway_config);
            $transport = $client->billPaymentBulkInquiry($request);
            $initialEvidence = $this->parser->parseBulk($transport, []);

            if ($this->isBulkRunFailure($initialEvidence)) {
                $status = 'failed';
                $counts['total_failed']++;
                $counts['total_errors']++;
                return ['status' => $status, 'run_id' => $run_id, 'counts' => $counts, 'run_date' => $run_date];
            }

            $expectations = $this->bulkExpectations($company_id, $initialEvidence);
            $evidenceRows = $this->parser->parseBulk($transport, ['expected' => $expectations['expected']]);
            $counts['total_eligible'] = count($evidenceRows);
            $seenConsumers = [];

            foreach ($evidenceRows as $evidence) {
                if (time() - $start >= self::MAX_RUNTIME_SECONDS) {
                    $status = 'aborted';
                    break;
                }

                $consumerNumber = (string) ($evidence->consumerNumber() ?? '');
                $voucher = $expectations['vouchers'][$consumerNumber] ?? null;

                if (!$voucher) {
                    $counts = $this->countOutcome($counts, 'manual_review', false);
                    $counts['total_unmatched']++;
                    $this->recordUnmatchedBulkAudit($company_id, $run_id, $evidence);
                    continue;
                }

                if (isset($seenConsumers[$consumerNumber])
                    || !in_array((string) $voucher->status, ['pending', 'retry'], true)
                ) {
                    // Already handled: the provider echoed this Consumer Number twice in
                    // one run, or the voucher already left pending/retry from a prior bulk
                    // run / the scheduled single path. Reprocessing it would demote a valid
                    // confirm (persistEvidence's stale guard rewrites it to manual_review),
                    // and a second (run_id, voucher_id) item row would violate the unique
                    // key and abort the whole run. Audit the duplicate only; leave the
                    // voucher and its first item untouched (idempotent rerun).
                    $counts['total_checked']++;
                    $this->recordDuplicateBulkAudit($company_id, $run_id, $voucher, $evidence);
                    continue;
                }
                $seenConsumers[$consumerNumber] = true;

                $prior_status = (string) $voucher->status;
                $error = false;

                try {
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
                } catch (Throwable $e) {
                    $error = true;
                    $new_status = $prior_status;
                }

                $counts = $this->countOutcome($counts, $new_status, $error);
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $counts['total_errors']++;
        } finally {
            try {
                if ($run_id > 0) {
                    $summary = json_encode([
                        'run_kind' => 'bulk',
                        'run_date' => $run_date,
                        'status' => $status,
                        'counts' => $counts,
                    ]);
                    $this->runRepository->close($run_id, $status, $counts, 0, $summary);
                    $this->auditService->record('reconciliation.run.completed', [
                        'company_id' => $company_id,
                        'run_id' => $run_id,
                        'payload' => ['trigger_type' => 'bulk', 'run_date' => $run_date, 'status' => $status, 'counts' => $counts],
                    ]);
                }
            } catch (Throwable $closeError) {
                // Closing the run / writing audit is best-effort; the lock MUST still be released below.
            }

            $this->lockRepository->release($company_id, self::LOCK_NAME, $owner_token);
        }

        return ['status' => $status, 'run_id' => $run_id, 'counts' => $counts, 'run_date' => $run_date];
    }

    public function processVoucher(int $company_id, int $run_id, $voucher, $client): array
    {
        $prior_status = (string) $voucher->status;
        $error = false;
        $redactedTraceId = '';

        try {
            $transport = $client->billPaymentInquiry($this->buildInquiryRequest($voucher));
            $evidence = $this->parser->parse($transport, $this->buildParserContext($voucher));
            $redactedTraceId = $evidence->redactedTraceId();
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

            try {
                $this->itemRepository->record([
                    'run_id' => $run_id,
                    'voucher_id' => (int) $voucher->id,
                    'prior_status' => $prior_status,
                    'new_status' => $new_status,
                    'error_class' => 'reconcile_exception',
                    'redacted_trace_id' => $redactedTraceId,
                    'date_created' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $recordError) {
                // One voucher's failed item write must not abort the rest of the batch.
            }

            try {
                $this->auditService->record('evidence.error', [
                    'company_id' => $company_id,
                    'voucher_id' => (int) $voucher->id,
                    'run_id' => $run_id,
                    'redacted_trace_id' => $redactedTraceId,
                    'payload' => ['error_class' => 'reconcile_exception'],
                ]);
            } catch (Throwable $auditError) {
                // Audit writes must not abort the rest of the batch.
            }
        }

        return ['new_status' => $new_status, 'error' => $error];
    }

    public function buildInquiryRequest($voucher): array
    {
        // KuickPay's BillPaymentInquiry keys on the Consumer Number (WSDL field
        // `consumerNumber`); it echoes that same number back in result field [1].
        return ['consumerNumber' => (string) $voucher->consumer_number];
    }

    public function buildBulkRequest(string $run_date): array
    {
        $date = DateTime::createFromFormat('!Y-m-d', $run_date);
        if (!$date || $date->format('Y-m-d') !== $run_date) {
            throw new InvalidArgumentException('Bulk reconciliation run_date must be YYYY-MM-DD');
        }

        return ['TransactionDate' => $date->format('Ymd')];
    }

    public function buildParserContext($voucher): array
    {
        // Match the identity KuickPay echoes back in inquiry result field [1] --
        // the Consumer Number -- not the Registration Number. Pass exactly ONE
        // expected identity (see single-identity contract).
        return [
            'expected_amount' => (string) $voucher->amount,
            'expected_currency' => (string) $voucher->currency,
            'expected_consumer_number' => (string) $voucher->consumer_number,
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
            $freshData = $this->voucherRepository->getWithInvoices((int) $voucher->id);
            $freshVoucher = $freshData['voucher'] ?? null;
            $invoiceLinks = $freshData['invoices'] ?? [];

            if (!$freshData
                || !$freshVoucher
                || (int) ($freshVoucher->company_id ?? 0) !== $company_id
                || !in_array((string) ($freshVoucher->status ?? ''), ['pending', 'retry'], true)
            ) {
                $new_status = 'manual_review';
                $vars['status'] = 'manual_review';
                $vars['diagnostic_summary'] = $this->mergeValidationErrors(
                    $vars['diagnostic_summary'],
                    ['stale_voucher']
                );
            } else {
                $result = $this->evidenceValidator->validate($freshVoucher, $invoiceLinks, $evidence);

                if ($result->isValid()) {
                    $vars['amount'] = $evidence->amount();
                    $vars['date_paid'] = $this->paidDate($evidence);
                    $vars['kuickpay_reference'] = $evidence->reference();
                } else {
                    $new_status = 'manual_review';
                    $vars['status'] = 'manual_review';
                    $vars['diagnostic_summary'] = $this->mergeValidationErrors(
                        $vars['diagnostic_summary'],
                        $result->reasons()
                    );
                }
            }
        }

        $reasons = $this->validationErrorsFromSummary($vars['diagnostic_summary']);
        $resolved_status = $this->resolveExceptionStatus($new_status, $reasons, $this->gatewayConfig);
        if ($resolved_status !== $new_status) {
            $new_status = $resolved_status;
            $vars['status'] = $resolved_status;
        }

        // Story 3.4 validates confirmed evidence only. Posting and invoice mutation belong to Story 3.5.
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

    private function mergeValidationErrors(string $diagnosticSummary, array $reasons): string
    {
        $diag = json_decode($diagnosticSummary, true) ?: [];
        $diag['validation_errors'] = array_values(array_unique(array_merge(
            $diag['validation_errors'] ?? [],
            $reasons
        )));

        return json_encode($diag);
    }

    private function validationErrorsFromSummary(string $diagnosticSummary): array
    {
        $diag = json_decode($diagnosticSummary, true) ?: [];

        return is_array($diag['validation_errors'] ?? null) ? $diag['validation_errors'] : [];
    }

    private function resolveExceptionStatus(string $currentStatus, array $reasons, ?array $gatewayConfig): string
    {
        foreach (['underpayment', 'overpayment', 'late_payment'] as $reason) {
            if (!in_array($reason, $reasons, true)) {
                continue;
            }

            $policy = $gatewayConfig[$reason . '_policy'] ?? 'manual_review';

            // TODO(production-gate): widen supported policies only after production approval.
            if ($policy === 'manual_review') {
                return 'manual_review';
            }

            return 'manual_review';
        }

        return $currentStatus;
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

    private function isBulkRunFailure(array $evidenceRows): bool
    {
        if (count($evidenceRows) !== 1) {
            return false;
        }

        $evidence = $evidenceRows[0];

        return $evidence instanceof KuickPayEvidence
            && $evidence->consumerNumber() === null
            && in_array($evidence->errorClass(), ['timeout', 'transport_error', 'malformed_response'], true);
    }

    private function bulkExpectations(int $company_id, array $evidenceRows): array
    {
        $expected = [];
        $vouchers = [];

        foreach ($evidenceRows as $evidence) {
            if (!$evidence instanceof KuickPayEvidence || $evidence->consumerNumber() === null) {
                continue;
            }

            $consumerNumber = $evidence->consumerNumber();
            if ($consumerNumber === '' || array_key_exists($consumerNumber, $vouchers)) {
                continue;
            }

            $voucher = $this->voucherRepository->getByConsumerNumber($consumerNumber, $company_id);
            if (!$voucher) {
                continue;
            }

            $vouchers[$consumerNumber] = $voucher;
            $expected[$consumerNumber] = [
                'amount' => (string) $voucher->amount,
                'currency' => (string) $voucher->currency,
            ];
        }

        return ['expected' => $expected, 'vouchers' => $vouchers];
    }

    private function recordDuplicateBulkAudit(int $company_id, int $run_id, $voucher, KuickPayEvidence $evidence): void
    {
        $this->auditService->record('evidence.duplicate', [
            'company_id' => $company_id,
            'run_id' => $run_id,
            'voucher_id' => (int) $voucher->id,
            'redacted_trace_id' => $evidence->redactedTraceId(),
            'evidence_hash' => $evidence->evidenceHash(),
            'payload' => [
                'consumer_number' => $evidence->consumerNumber(),
                'error_class' => 'duplicate_reference',
            ],
        ]);
    }

    private function recordUnmatchedBulkAudit(int $company_id, int $run_id, KuickPayEvidence $evidence): void
    {
        $this->auditService->record('evidence.unmatched', [
            'company_id' => $company_id,
            'run_id' => $run_id,
            'redacted_trace_id' => $evidence->redactedTraceId(),
            'evidence_hash' => $evidence->evidenceHash(),
            'payload' => [
                'consumer_number' => $evidence->consumerNumber(),
                'error_class' => $evidence->errorClass(),
            ],
        ]);
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
            'underpayment_policy' => $meta['underpayment_policy'] ?? 'manual_review',
            'overpayment_policy' => $meta['overpayment_policy'] ?? 'manual_review',
            'late_payment_policy' => $meta['late_payment_policy'] ?? 'manual_review',
        ];
    }

    private function client(array $gateway_config)
    {
        if ($this->clientFactory) {
            return call_user_func($this->clientFactory, $gateway_config);
        }

        return new KuickPaySoapClient($gateway_config, null, $this->operationLogger($gateway_config));
    }

    private function operationLogger(array $gateway_config): ?callable
    {
        if (!$this->truthy($gateway_config['logging_enabled'] ?? 'false') || $this->logger === null) {
            return null;
        }

        $logger = $this->logger;

        return function (array $fields, bool $ok) use ($logger): void {
            $operation = (string) ($fields['operation'] ?? 'soap');
            $message = 'kuickpay:' . $operation;

            try {
                if ($ok && is_callable([$logger, 'info'])) {
                    $logger->info($message, $fields);
                } elseif (!$ok && is_callable([$logger, 'error'])) {
                    $logger->error($message, $fields);
                }
            } catch (Throwable $e) {
                // Operational logging must never affect reconciliation.
            }
        };
    }

    private function needsRuntimeDependencies(array $dependencies): bool
    {
        foreach ([
            'voucher_repository',
            'run_repository',
            'item_repository',
            'lock_repository',
            'audit_service',
            'parser',
            'evidence_validator',
        ] as $key) {
            if (!array_key_exists($key, $dependencies)) {
                return true;
            }
        }

        return false;
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
        Loader::load($plugin_dir . DS . 'KuickPayValidationResult.php');
        Loader::load($plugin_dir . DS . 'KuickPayInvoiceReader.php');
        Loader::load($plugin_dir . DS . 'KuickPayEvidenceValidator.php');
        Loader::load($gateway_dir . DS . 'KuickPayRedactor.php');
        Loader::load($gateway_dir . DS . 'KuickPayEvidence.php');
        Loader::load($gateway_dir . DS . 'KuickPayResponseParser.php');
        Loader::load($gateway_dir . DS . 'KuickPaySoapClient.php');
    }
}
