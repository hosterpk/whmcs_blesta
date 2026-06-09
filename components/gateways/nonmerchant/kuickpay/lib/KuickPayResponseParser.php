<?php
/**
 * KuickPay response normalization boundary.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayResponseParser
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RETRY = 'retry';
    public const STATUS_CONFIRMED_UNPOSTED = 'confirmed_unposted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_MANUAL_REVIEW = 'manual_review';

    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_TRANSPORT = 'transport_error';
    public const ERROR_CREDENTIAL = 'credential_error';
    public const ERROR_MALFORMED = 'malformed_response';
    public const ERROR_UNKNOWN = 'unknown_status';
    public const ERROR_AMOUNT = 'amount_mismatch';
    public const ERROR_DUPLICATE = 'duplicate_reference';
    public const ERROR_UNMATCHED = 'unmatched_reference';

    private const OP_INSERT_VOUCHER = 'InsertVoucher';
    private const OP_INQUIRY = 'BillPaymentInquiry';
    private const OP_BULK = 'BillPaymentBulkInquiry';

    private const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RETRY,
        self::STATUS_CONFIRMED_UNPOSTED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_MANUAL_REVIEW,
    ];

    private const ALLOWED_ERRORS = [
        self::ERROR_TIMEOUT,
        self::ERROR_TRANSPORT,
        self::ERROR_CREDENTIAL,
        self::ERROR_MALFORMED,
        self::ERROR_UNKNOWN,
        self::ERROR_AMOUNT,
        self::ERROR_DUPLICATE,
        self::ERROR_UNMATCHED,
    ];

    private KuickPayRedactor $redactor;

    public function __construct(KuickPayRedactor $redactor = null)
    {
        $this->redactor = $redactor ?? new KuickPayRedactor();
    }

    public function parse(array $transportOutcome, array $context = []): KuickPayEvidence
    {
        $operation = isset($transportOutcome['operation']) ? (string) $transportOutcome['operation'] : '';

        if ($operation === self::OP_BULK) {
            throw new InvalidArgumentException('BillPaymentBulkInquiry evidence requires use parseBulk()');
        }

        if ($operation !== self::OP_INSERT_VOUCHER && $operation !== self::OP_INQUIRY) {
            return $this->evidence(
                $operation,
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_UNKNOWN,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $this->traceId($transportOutcome),
                ['unknown_operation']
            );
        }

        if (($transportOutcome['ok'] ?? false) === false) {
            return $this->transportFailure($transportOutcome, $operation);
        }

        $rawResult = $transportOutcome['raw_result'] ?? null;
        if (!is_string($rawResult) || trim($rawResult) === '') {
            return $this->evidence(
                $operation,
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_MALFORMED,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $this->traceId($transportOutcome),
                ['empty_result']
            );
        }

        if ($operation === self::OP_INSERT_VOUCHER) {
            return $this->parseInsertVoucher($rawResult, $transportOutcome, $context);
        }

        return $this->evidence(
            $operation,
            self::STATUS_MANUAL_REVIEW,
            self::ERROR_UNKNOWN,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->traceId($transportOutcome),
            ['unsupported_operation']
        );
    }

    public function parseBulk(array $transportOutcome, array $context = []): array
    {
        $operation = isset($transportOutcome['operation']) ? (string) $transportOutcome['operation'] : self::OP_BULK;

        if (($transportOutcome['ok'] ?? false) === false) {
            return [$this->transportFailure($transportOutcome, self::OP_BULK)];
        }

        return [$this->evidence(
            $operation,
            self::STATUS_MANUAL_REVIEW,
            self::ERROR_MALFORMED,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->traceId($transportOutcome),
            ['bulk_parser_not_implemented']
        )];
    }

    private function transportFailure(array $transportOutcome, string $operation): KuickPayEvidence
    {
        $errorClass = isset($transportOutcome['error_class']) ? (string) $transportOutcome['error_class'] : self::ERROR_TRANSPORT;
        if (!in_array($errorClass, [self::ERROR_TIMEOUT, self::ERROR_TRANSPORT], true)) {
            $errorClass = self::ERROR_TRANSPORT;
        }

        return $this->evidence(
            $operation,
            $operation === self::OP_INSERT_VOUCHER ? self::STATUS_MANUAL_REVIEW : self::STATUS_RETRY,
            $errorClass,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->traceId($transportOutcome),
            [$errorClass]
        );
    }

    private function parseInsertVoucher(string $rawResult, array $transportOutcome, array $context): KuickPayEvidence
    {
        $result = trim($rawResult);
        $rawStatus = strlen($result) >= 2 ? substr($result, 0, 2) : $result;
        $registrationNumber = isset($context['expected_registration_number'])
            ? (string) $context['expected_registration_number']
            : null;

        if (strlen($rawStatus) !== 2 || !ctype_digit($rawStatus)) {
            return $this->evidence(
                self::OP_INSERT_VOUCHER,
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_MALFORMED,
                null,
                null,
                $registrationNumber,
                null,
                null,
                null,
                $rawStatus === '' ? null : $rawStatus,
                $this->traceId($transportOutcome),
                ['malformed_status']
            );
        }

        // InsertVoucher success only means the voucher was created; paid truth comes from inquiry/bulk evidence.
        if ($rawStatus === '00') {
            $reference = trim(substr($result, 2));
            if ($reference === '') {
                return $this->evidence(
                    self::OP_INSERT_VOUCHER,
                    self::STATUS_MANUAL_REVIEW,
                    self::ERROR_MALFORMED,
                    null,
                    null,
                    $registrationNumber,
                    null,
                    null,
                    null,
                    $rawStatus,
                    $this->traceId($transportOutcome),
                    ['missing_voucher_id']
                );
            }

            return $this->evidence(
                self::OP_INSERT_VOUCHER,
                self::STATUS_PENDING,
                null,
                $reference,
                null,
                $registrationNumber,
                null,
                null,
                null,
                $rawStatus,
                $this->traceId($transportOutcome),
                []
            );
        }

        if ($rawStatus === '94') {
            return $this->evidence(
                self::OP_INSERT_VOUCHER,
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_DUPLICATE,
                null,
                null,
                $registrationNumber,
                null,
                null,
                null,
                $rawStatus,
                $this->traceId($transportOutcome),
                [self::ERROR_DUPLICATE]
            );
        }

        if ($rawStatus === '05') {
            return $this->evidence(
                self::OP_INSERT_VOUCHER,
                self::STATUS_FAILED,
                self::ERROR_CREDENTIAL,
                null,
                null,
                $registrationNumber,
                null,
                null,
                null,
                $rawStatus,
                $this->traceId($transportOutcome),
                [self::ERROR_CREDENTIAL]
            );
        }

        return $this->evidence(
            self::OP_INSERT_VOUCHER,
            self::STATUS_MANUAL_REVIEW,
            self::ERROR_UNKNOWN,
            null,
            null,
            $registrationNumber,
            null,
            null,
            null,
            $rawStatus,
            $this->traceId($transportOutcome),
            [self::ERROR_UNKNOWN]
        );
    }

    private function evidence(
        string $operation,
        string $status,
        ?string $errorClass,
        ?string $reference,
        ?string $consumerNumber,
        ?string $registrationNumber,
        ?string $amount,
        ?string $currency,
        ?string $paidAt,
        ?string $rawStatus,
        string $traceId,
        array $validationErrors
    ): KuickPayEvidence {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = self::STATUS_MANUAL_REVIEW;
            $errorClass = self::ERROR_UNKNOWN;
            $validationErrors[] = self::ERROR_UNKNOWN;
        }

        if ($errorClass !== null && !in_array($errorClass, self::ALLOWED_ERRORS, true)) {
            $status = self::STATUS_MANUAL_REVIEW;
            $errorClass = self::ERROR_UNKNOWN;
            $validationErrors[] = self::ERROR_UNKNOWN;
        }

        $currency = $currency === null ? null : strtoupper($currency);
        $hash = $this->evidenceHash(
            $operation,
            $rawStatus,
            $reference,
            $consumerNumber,
            $registrationNumber,
            $amount,
            $currency,
            $paidAt
        );

        return new KuickPayEvidence(
            $status,
            $errorClass,
            $reference,
            $consumerNumber,
            $registrationNumber,
            $amount,
            $currency,
            $paidAt,
            $rawStatus,
            $traceId,
            $hash,
            array_values(array_unique($validationErrors)),
            $operation
        );
    }

    private function evidenceHash(
        string $operation,
        ?string $rawStatus,
        ?string $reference,
        ?string $consumerNumber,
        ?string $registrationNumber,
        ?string $amount,
        ?string $currency,
        ?string $paidAt
    ): string {
        $canonical = implode('|', [
            $operation,
            $rawStatus ?? '',
            $reference ?? '',
            $consumerNumber ?? '',
            $registrationNumber ?? '',
            $amount ?? '',
            $currency === null ? '' : strtoupper($currency),
            $paidAt ?? '',
        ]);

        return substr(hash('sha256', $canonical), 0, 24);
    }

    private function traceId(array $transportOutcome): string
    {
        if (isset($transportOutcome['redacted_trace_id']) && (string) $transportOutcome['redacted_trace_id'] !== '') {
            return (string) $transportOutcome['redacted_trace_id'];
        }

        return $this->redactor->traceId();
    }
}
