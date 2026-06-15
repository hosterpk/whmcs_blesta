<?php
/**
 * KuickPay voucher reference service
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayVoucherReferenceService
{
    private const DEFAULT_REGISTRATION_PATTERN = '{random_prefix}{invoice_id}';
    private const DEFAULT_CONSUMER_PATTERN = '{institution_id}{registration_number}';
    private const MAX_REFERENCE_ATTEMPTS = 5;

    /**
     * @var KuickPayVoucherRepository Voucher repository
     */
    private $repository;

    /**
     * @var KuickPayAuditService Audit service
     */
    private $auditService;

    /**
     * @var string|null Last sanitized generation error code
     */
    private $lastError = null;

    /**
     * @var int|null Invoice id that actually triggered a duplicate-with-differing-amount
     *  conflict, surfaced from the pure validator without changing its signature
     */
    private $conflictInvoiceId = null;

    /**
     * Constructs the reference service.
     *
     * @param mixed $repository Optional repository, primarily for tests
     * @param mixed $auditService Optional audit service, primarily for tests
     */
    public function __construct($repository = null, $auditService = null)
    {
        $useDefaultServices = $repository === null;
        if ($repository === null) {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherRepository.php');
            $repository = new KuickPayVoucherRepository();
        }
        if ($auditService === null && $useDefaultServices) {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayAuditService.php');
            $auditService = new KuickPayAuditService();
        }

        $this->repository = $repository;
        $this->auditService = $auditService;
    }

    /**
     * Gets or creates a pending voucher for an invoice payment context.
     *
     * @param array $context Voucher creation context
     * @return array|null Flat voucher data, or null on failure
     */
    public function getOrCreateForInvoiceContext(array $context): ?array
    {
        $this->lastError = null;
        $this->conflictInvoiceId = null;

        try {
            $invoiceAmounts = $this->normalizeContextInvoiceAmounts((array) ($context['invoice_amounts'] ?? []));
            if ($invoiceAmounts === null) {
                if ($this->lastError === 'duplicate_invoice_id') {
                    // Name the invoice whose duplicate-with-differing-amount actually
                    // triggered the conflict, not merely the first context row.
                    $this->recordGenerationFailed(
                        (int) ($context['company_id'] ?? 0),
                        $this->conflictInvoiceId
                            ?? $this->firstContextInvoiceId((array) ($context['invoice_amounts'] ?? [])),
                        $this->lastError
                    );
                }
                return null;
            }
            $context['invoice_amounts'] = $invoiceAmounts;

            $firstInvoice = $invoiceAmounts[0] ?? null;
            if (!is_array($firstInvoice) || !isset($firstInvoice['id'])) {
                return null;
            }

            $invoice_id = (int) $firstInvoice['id'];
            $company_id = (int) ($context['company_id'] ?? 0);
            $invoiceIds = array_map(function (array $invoiceAmount): int {
                return (int) $invoiceAmount['id'];
            }, $invoiceAmounts);
            $useInvoiceSet = ($context['multi_invoice_policy'] ?? 'block') === 'allow' && count($invoiceIds) > 1;
            $pending = $useInvoiceSet
                ? $this->repository->getPendingByInvoiceSet($invoiceIds, $company_id)
                : $this->repository->getPendingByInvoiceId($invoice_id, $company_id);
            if ($pending) {
                $pendingFlat = $this->flatten($this->repository->getWithInvoices((int) $pending->id, $company_id));
                if ($pendingFlat === null) {
                    return null;
                }

                if ($this->requestMatchesVoucher(
                    $pendingFlat,
                    (string) ($context['amount'] ?? ''),
                    (array) ($context['invoice_amounts'] ?? [])
                )) {
                    return $pendingFlat;
                }

                if (($context['amount_change_policy'] ?? 'block') !== 'replace') {
                    $this->lastError = 'amount_changed';
                    return null;
                }

                $retired = $this->retireVoucher((int) $pendingFlat['id'], $company_id, 'amount_changed', [
                    'old_amount' => (string) ($pendingFlat['amount'] ?? ''),
                    'new_amount' => (string) ($context['amount'] ?? ''),
                ]);
                if (!$retired) {
                    $this->lastError = 'amount_changed';
                    return null;
                }
            }

            $references = null;
            for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
                $refs = $this->generateReferences($context);
                if ($refs['registration_number'] === '' || $refs['consumer_number'] === '') {
                    if (in_array($this->lastError, ['invalid_registration_pattern', 'invalid_consumer_pattern'], true)) {
                        $this->recordGenerationFailed($company_id, $invoice_id, (string) $this->lastError);
                    }
                    return null;
                }

                $collision = $this->repository->getByRegistrationNumber($refs['registration_number'], $company_id)
                    || $this->repository->getByConsumerNumber($refs['consumer_number'], $company_id);
                if (!$collision) {
                    $references = $refs;
                    break;
                }

                if ($attempt === self::MAX_REFERENCE_ATTEMPTS) {
                    $this->lastError = 'uniqueness_exhausted';
                    $this->recordGenerationFailed($company_id, $invoice_id, $this->lastError);
                    return null;
                }
            }

            if ($references === null) {
                return null;
            }

            $voucherData = [
                'company_id' => $context['company_id'] ?? null,
                'gateway_id' => $context['gateway_id'] ?? null,
                'client_id' => $context['client_id'] ?? null,
                'currency' => $context['currency'] ?? null,
                'amount' => $context['amount'] ?? null,
                'status' => 'pending',
                'registration_number' => $references['registration_number'],
                'consumer_number' => $references['consumer_number'],
                'context_key' => $this->contextKey($invoiceIds),
                'date_due' => $this->offsetDate((int) ($context['due_date_offset_days'] ?? 0)),
                'date_expires' => $this->offsetDate((int) ($context['expiry_date_offset_days'] ?? 0)),
            ];

            $invoiceLinks = [];
            foreach ($invoiceAmounts as $invoiceAmount) {
                $invoiceLinks[] = [
                    'invoice_id' => $invoiceAmount['id'] ?? null,
                    'amount' => $invoiceAmount['amount'] ?? null,
                ];
            }

            $voucher_id = $this->repository->create($voucherData, $invoiceLinks);
            if ($voucher_id) {
                return $this->flatten($this->repository->getWithInvoices($voucher_id, $company_id));
            }

            $pending = $useInvoiceSet
                ? $this->repository->getPendingByInvoiceSet($invoiceIds, $company_id)
                : $this->repository->getPendingByInvoiceId($invoice_id, $company_id);
            if ($pending) {
                return $this->flatten($this->repository->getWithInvoices((int) $pending->id, $company_id));
            }

            // Genuine create fall-through: create() returned falsy AND the
            // race-recovery re-lookup found no concurrent winner. Leave a sanitized
            // diagnostic so the gateway's recordReferenceGenerationFailure() emits a
            // non-null reason, plus a durable audit breadcrumb (symmetric with the
            // uniqueness_exhausted emit above) — no failure is traceless. This is NOT
            // reached on the race-recovery return above or the outer catch below.
            $this->lastError = 'create_failed';
            $this->recordGenerationFailed($company_id, $invoice_id, $this->lastError);
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Gets the last sanitized generation error.
     *
     * @return string|null Last generation error code, or null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Determines whether a stored voucher still matches the requested payment context.
     *
     * @param array $voucherFlat Flat voucher data with optional invoice links
     * @param string $contextAmount Requested total amount
     * @param array $contextInvoiceAmounts Requested invoice allocations
     * @return bool True when the stored voucher matches the request
     */
    public function requestMatchesVoucher(
        array $voucherFlat,
        string $contextAmount,
        array $contextInvoiceAmounts
    ): bool {
        if (
            $this->normalizeAmount((string) ($voucherFlat['amount'] ?? ''))
            !== $this->normalizeAmount($contextAmount)
        ) {
            return false;
        }

        $voucherInvoices = (array) ($voucherFlat['invoices'] ?? []);
        if (empty($voucherInvoices)) {
            return true;
        }

        return $this->canonicalInvoiceAmounts($voucherInvoices, 'invoice_id')
            === $this->canonicalInvoiceAmounts($contextInvoiceAmounts, 'id');
    }

    /**
     * Retires a stale voucher and records replacement audit history.
     *
     * @param int $voucherId Voucher ID
     * @param int $companyId Company ID scope
     * @param string $reason Replacement reason
     * @param array $auditPayload Additional audit payload fields
     * @return bool True when the retire write was attempted successfully
     */
    public function retireVoucher(int $voucherId, int $companyId, string $reason, array $auditPayload = []): bool
    {
        try {
            $this->repository->edit($voucherId, $companyId, ['status' => 'cancelled']);

            if ($this->auditService === null) {
                Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayAuditService.php');
                $this->auditService = new KuickPayAuditService();
            }

            $redactedTraceId = $auditPayload['redacted_trace_id'] ?? null;
            unset($auditPayload['redacted_trace_id']);
            $this->auditService->record('voucher.replaced', [
                'company_id' => $companyId,
                'voucher_id' => $voucherId,
                'redacted_trace_id' => $redactedTraceId,
                'payload' => array_merge(['reason' => $reason], $auditPayload),
            ]);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Records a durable, redacted reference-generation failure audit event.
     *
     * @param int $companyId Company scope
     * @param int $invoiceId Invoice id related to the generation attempt
     * @param string $reason Safe reason code
     * @param int|null $voucherId Voucher id when one exists
     */
    private function recordGenerationFailed(int $companyId, int $invoiceId, string $reason, ?int $voucherId = null): void
    {
        try {
            if ($this->auditService === null) {
                Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayAuditService.php');
                $this->auditService = new KuickPayAuditService();
            }

            $this->auditService->record('voucher.generation_failed', [
                'company_id' => $companyId,
                'voucher_id' => $voucherId,
                'payload' => [
                    'reason' => $reason,
                    'invoice_id' => $invoiceId,
                ],
            ]);
        } catch (Throwable $e) {
            // Audit writes must not abort reference generation.
        }
    }

    private function firstContextInvoiceId(array $invoiceAmounts): int
    {
        $first = reset($invoiceAmounts);

        return is_array($first) ? (int) ($first['id'] ?? 0) : 0;
    }

    /**
     * Generates a secure random integer for the reference prefix.
     *
     * @return int Random integer in the 4-digit prefix range
     */
    protected function randomInt(): int
    {
        return random_int(0, 9999);
    }

    /**
     * Generates a zero-padded 4-digit random prefix.
     *
     * @return string The generated random prefix
     */
    protected function generateRandomPrefix(): string
    {
        return str_pad((string) $this->randomInt(), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generates configured voucher references.
     *
     * @param array $context Voucher context
     * @return array Registration and consumer numbers, or empty values on failure
     */
    private function generateReferences(array $context): array
    {
        $invoice_id = (string) ($context['invoice_amounts'][0]['id'] ?? '');
        if ($invoice_id === '') {
            return ['registration_number' => '', 'consumer_number' => ''];
        }

        $registrationPattern = (string) ($context['registration_number_pattern'] ?? self::DEFAULT_REGISTRATION_PATTERN);
        if ($registrationPattern === '') {
            $registrationPattern = self::DEFAULT_REGISTRATION_PATTERN;
        }

        $consumerPattern = (string) ($context['consumer_number_pattern'] ?? self::DEFAULT_CONSUMER_PATTERN);
        if ($consumerPattern === '') {
            $consumerPattern = self::DEFAULT_CONSUMER_PATTERN;
        }

        $random = $this->generateRandomPrefix();
        $institution_id = (string) ($context['institution_id'] ?? '');
        $registration_number = $this->expandPattern($registrationPattern, [
            'random_prefix' => $random,
            'invoice_id' => $invoice_id,
            'institution_id' => $institution_id,
        ]);
        if ($registration_number === null) {
            $this->lastError = 'invalid_registration_pattern';
            return ['registration_number' => '', 'consumer_number' => ''];
        }

        $consumer_number = $this->expandPattern($consumerPattern, [
            'random_prefix' => $random,
            'invoice_id' => $invoice_id,
            'institution_id' => $institution_id,
            'registration_number' => $registration_number,
        ]);
        if ($consumer_number === null) {
            $this->lastError = 'invalid_consumer_pattern';
            return ['registration_number' => '', 'consumer_number' => ''];
        }

        return [
            'registration_number' => $registration_number,
            'consumer_number' => $consumer_number,
        ];
    }

    /**
     * Expands a configured reference pattern.
     *
     * @param string $pattern The reference pattern
     * @param array $values Token values keyed by token name
     * @return string|null Expanded pattern, or null when malformed
     */
    protected function expandPattern(string $pattern, array $values): ?string
    {
        $tokens = ['random_prefix', 'invoice_id', 'institution_id', 'registration_number'];
        $valid = true;
        $expanded = preg_replace_callback('/\{([^{}]+)\}/', function (array $matches) use ($values, $tokens, &$valid) {
            $token = $matches[1];
            if (!in_array($token, $tokens, true) || !isset($values[$token]) || (string) $values[$token] === '') {
                $valid = false;
                return '';
            }

            return (string) $values[$token];
        }, $pattern);

        if (!$valid || $expanded === null || $expanded === '' || strpos($expanded, '{') !== false || strpos($expanded, '}') !== false) {
            return null;
        }

        if (strlen($expanded) > 64) {
            return null;
        }

        return $expanded;
    }

    /**
     * Builds an order-independent invoice allocation map.
     *
     * @param array $invoiceAmounts Invoice amount rows
     * @param string $idKey Invoice ID key name
     * @return array Canonical invoice allocations keyed by invoice ID
     */
    private function canonicalInvoiceAmounts(array $invoiceAmounts, string $idKey): array
    {
        $canonical = [];
        foreach ($invoiceAmounts as $invoiceAmount) {
            $row = (array) $invoiceAmount;
            $invoice_id = (string) ($row[$idKey] ?? '');
            if ($invoice_id === '') {
                continue;
            }

            $canonical[$invoice_id] = $this->normalizeAmount((string) ($row['amount'] ?? ''));
        }

        ksort($canonical, SORT_NATURAL);

        return $canonical;
    }

    /**
     * Canonicalizes context invoice allocations and fails closed on conflicting duplicates.
     *
     * @param array $invoiceAmounts Incoming invoice amount rows
     * @return array|null Canonical rows sorted by invoice ID, or null on conflict
     */
    private function normalizeContextInvoiceAmounts(array $invoiceAmounts): ?array
    {
        $canonical = [];
        foreach ($invoiceAmounts as $invoiceAmount) {
            $row = (array) $invoiceAmount;
            $invoice_id = (string) ($row['id'] ?? '');
            if ($invoice_id === '') {
                continue;
            }

            $amount = $this->normalizeAmount((string) ($row['amount'] ?? ''));
            if (isset($canonical[$invoice_id]) && $canonical[$invoice_id]['amount'] !== $amount) {
                $this->lastError = 'duplicate_invoice_id';
                // Surface the conflicting id to the caller via a private member so the
                // audit can name it; the pure validator's signature/return stays intact.
                $this->conflictInvoiceId = (int) $invoice_id;
                return null;
            }

            $canonical[$invoice_id] = [
                'id' => (int) $invoice_id,
                'amount' => $amount,
            ];
        }

        ksort($canonical, SORT_NATURAL);

        return array_values($canonical);
    }

    /**
     * Computes the deterministic active-context fingerprint for an invoice set.
     *
     * Single source of truth for the context_key written on every voucher
     * (Story 5.2). MUST stay byte-identical to the SQL migration backfill:
     * PHP `sha1(implode(',', $sortedDistinctIntIds))` over the ascending,
     * de-duplicated integer invoice-id set mirrors
     * `SHA1(GROUP_CONCAT(DISTINCT invoice_id ORDER BY invoice_id SEPARATOR ','))`.
     * Normalizes here (intval + unique + numeric sort) so the algorithm is
     * self-contained regardless of caller ordering, matching the same
     * normalization in KuickpayVouchers::getPendingByInvoiceSet().
     *
     * @param array $invoiceIds Invoice IDs (any order, possibly duplicated)
     * @return string The 40-char sha1 context key
     */
    private function contextKey(array $invoiceIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
        sort($ids, SORT_NUMERIC);

        return sha1(implode(',', $ids));
    }

    /**
     * Normalizes an amount to a canonical two-decimal string, or fails closed.
     *
     * Half-up rounds to 2 dp using decimal-string / bcmath math (never PHP
     * floats); non-numeric or negative input returns an empty sentinel that can
     * never compare equal to a valid amount, instead of passing the raw string
     * through. MUST stay byte-for-byte identical to the mirror copy in
     * Kuickpay::normalizeAmount() so the cross-side amount compare is
     * self-consistent (Story 5.5 AC3d; NFR13; architecture 658).
     *
     * @param string $amount The amount to normalize
     * @return string The normalized 2-dp amount, or '' when invalid
     */
    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);
        $normalized = str_replace(',', '', $amount);

        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return '';
        }

        // Adding 0.005 then truncating to 2 dp rounds a .xx5 tie up for the
        // non-negative values that reach here, with no float intermediates.
        return bcadd($normalized, '0.005', 2);
    }

    /**
     * Converts repository data into the gateway/view contract.
     *
     * @param array|null $data Nested repository data
     * @return array|null Flat voucher data, or null when incomplete
     */
    private function flatten(?array $data): ?array
    {
        if (empty($data['voucher'])) {
            return null;
        }

        $voucher = $data['voucher'];
        $invoices = [];
        foreach ((array) ($data['invoices'] ?? []) as $invoice) {
            $invoices[] = [
                'invoice_id' => $invoice->invoice_id,
                'amount' => $invoice->amount,
            ];
        }

        return [
            'id' => (int) $voucher->id,
            'company_id' => (int) $voucher->company_id,
            'client_id' => (int) $voucher->client_id,
            'gateway_id' => (int) $voucher->gateway_id,
            'currency' => $voucher->currency,
            'amount' => $voucher->amount,
            'status' => $voucher->status,
            'registration_number' => $voucher->registration_number,
            'consumer_number' => $voucher->consumer_number,
            'kuickpay_reference' => $voucher->kuickpay_reference ?? null,
            'raw_status' => $voucher->raw_status ?? null,
            'date_due' => $voucher->date_due,
            'date_expires' => $voucher->date_expires,
            'invoices' => $invoices,
        ];
    }

    /**
     * Returns a Y-m-d date offset from today.
     *
     * @param int $days Number of days to offset
     * @return string Date in Y-m-d format
     */
    private function offsetDate(int $days): string
    {
        return date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));
    }
}
