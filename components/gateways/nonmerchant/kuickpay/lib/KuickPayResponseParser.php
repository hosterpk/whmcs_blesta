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
    private const MAX_BULK_ROWS = 10000;

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

        return $this->parseBillPaymentInquiry($rawResult, $transportOutcome, $context);
    }

    public function parseBulk(array $transportOutcome, array $context = []): array
    {
        if (($transportOutcome['ok'] ?? false) === false) {
            return [$this->transportFailure($transportOutcome, self::OP_BULK)];
        }

        $rawResult = $transportOutcome['raw_result'] ?? null;
        if (!is_string($rawResult) || trim($rawResult) === '') {
            return [$this->malformedBulkEvidence($transportOutcome, ['empty_result'])];
        }

        if (strlen($rawResult) > KuickPayRedactor::MAX_ENVELOPE_BYTES || stripos($rawResult, '<!DOCTYPE') !== false) {
            return [$this->malformedBulkEvidence($transportOutcome, ['malformed_dataset'])];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML($rawResult, LIBXML_NONET);

        if (!$loaded || !$document->documentElement || $document->documentElement->localName !== 'NewDataSet') {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [$this->malformedBulkEvidence($transportOutcome, ['malformed_dataset'])];
        }

        $tables = [];
        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Table') {
                $tables[] = $child;
            }
        }

        if (count($tables) > self::MAX_BULK_ROWS) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [$this->malformedBulkEvidence($transportOutcome, ['malformed_dataset', 'row_limit_exceeded'])];
        }

        if (count($tables) === 0) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [];
        }

        $rows = [];
        foreach ($tables as $table) {
            $row = [
                'consumer_number' => $this->bulkText($table, 'Consumer_Number'),
                'registration_number' => $this->bulkText($table, 'Registration_Number'),
                'paid_at' => $this->bulkText($table, 'Transaction_Date'),
                'amount' => $this->bulkText($table, 'Paid_Amount'),
                'reference' => $this->bulkText($table, 'Transaction_Reference'),
                'currency' => $this->bulkText($table, 'Currency'),
            ];

            foreach ($row as $value) {
                if ($value === null) {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                    return [$this->malformedBulkEvidence($transportOutcome, ['malformed_dataset'])];
                }
            }

            $rows[] = $row;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $evidence = [];
        // Drop empty expected consumer numbers: a blank value must never match a blank
        // Consumer_Number row (in_array('', [''], true) === true) and confirm a payment. An
        // absent/empty expected set leaves every row unmatched, per the fail-closed contract.
        $expectedConsumers = isset($context['expected_consumer_numbers']) && is_array($context['expected_consumer_numbers'])
            ? array_values(array_filter(
                array_map('strval', $context['expected_consumer_numbers']),
                function ($value) {
                    return $value !== '';
                }
            ))
            : [];

        foreach ($rows as $row) {
            $matched = in_array($row['consumer_number'], $expectedConsumers, true);
            if (!$matched) {
                $evidence[] = $this->bulkEvidence(
                    self::STATUS_MANUAL_REVIEW,
                    self::ERROR_UNMATCHED,
                    $row,
                    $transportOutcome,
                    [self::ERROR_UNMATCHED]
                );
                continue;
            }

            $errors = [];
            $errorClass = null;
            $expectedAmount = isset($context['expected_amount'])
                ? $this->normalizeAmount((string) $context['expected_amount'])
                : null;
            $expectedCurrency = isset($context['expected_currency'])
                ? strtoupper(trim((string) $context['expected_currency']))
                : 'PKR';
            $amount = $this->normalizeAmount($row['amount']);
            $currency = $row['currency'] === '' ? null : strtoupper($row['currency']);

            if ($expectedAmount === null || $expectedCurrency === '') {
                $errors[] = 'missing_expected_context';
            }

            if ($expectedAmount !== null && $amount !== $expectedAmount) {
                $errorClass = self::ERROR_AMOUNT;
                $errors[] = self::ERROR_AMOUNT;
            }

            if ($expectedCurrency !== '' && $currency !== $expectedCurrency) {
                $errors[] = 'currency_mismatch';
            }

            if (!empty($errors)) {
                $evidence[] = $this->bulkEvidence(
                    self::STATUS_MANUAL_REVIEW,
                    $errorClass,
                    $row,
                    $transportOutcome,
                    $errors
                );
                continue;
            }

            $evidence[] = $this->bulkEvidence(
                self::STATUS_CONFIRMED_UNPOSTED,
                null,
                $row,
                $transportOutcome,
                []
            );
        }

        return $evidence;
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

    private function parseBillPaymentInquiry(string $rawResult, array $transportOutcome, array $context): KuickPayEvidence
    {
        $fields = $this->parseInquiryFields($rawResult);
        if (count($fields) < 6) {
            return $this->evidence(
                self::OP_INQUIRY,
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
                ['malformed_result']
            );
        }

        $rawStatus = trim($fields[0]);
        if (strlen($rawStatus) !== 2 || !ctype_digit($rawStatus)) {
            return $this->inquiryEvidence(
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_MALFORMED,
                $fields,
                $rawStatus === '' ? null : $rawStatus,
                $transportOutcome,
                ['malformed_status']
            );
        }

        if ($rawStatus === '01') {
            return $this->inquiryEvidence(
                self::STATUS_PENDING,
                null,
                $fields,
                $rawStatus,
                $transportOutcome,
                []
            );
        }

        if ($rawStatus === '02') {
            return $this->inquiryEvidence(
                self::STATUS_EXPIRED,
                null,
                $fields,
                $rawStatus,
                $transportOutcome,
                []
            );
        }

        if ($rawStatus !== '00') {
            return $this->inquiryEvidence(
                self::STATUS_MANUAL_REVIEW,
                self::ERROR_UNKNOWN,
                $fields,
                $rawStatus,
                $transportOutcome,
                [self::ERROR_UNKNOWN]
            );
        }

        $errors = [];
        $errorClass = null;
        $expectedAmount = isset($context['expected_amount'])
            ? $this->normalizeAmount((string) $context['expected_amount'])
            : null;
        $expectedCurrency = isset($context['expected_currency'])
            ? strtoupper(trim((string) $context['expected_currency']))
            : 'PKR';
        $amount = $this->normalizeAmount($fields[3] ?? '');
        $currency = isset($fields[6]) && trim($fields[6]) !== '' ? strtoupper(trim($fields[6])) : null;
        $registrationNumber = trim($fields[1] ?? '');

        if ($expectedAmount === null || $expectedCurrency === '' || (
            !isset($context['expected_registration_number'])
            && !isset($context['expected_consumer_number'])
        )) {
            $errors[] = 'missing_expected_context';
        }

        if ($expectedAmount !== null && $amount !== $expectedAmount) {
            $errorClass = self::ERROR_AMOUNT;
            $errors[] = self::ERROR_AMOUNT;
        }

        if ($expectedCurrency !== '' && $currency !== $expectedCurrency) {
            $errors[] = 'currency_mismatch';
        }

        foreach (['expected_registration_number', 'expected_consumer_number'] as $key) {
            if (isset($context[$key]) && (string) $context[$key] !== $registrationNumber) {
                $errorClass = $errorClass ?? self::ERROR_UNMATCHED;
                $errors[] = self::ERROR_UNMATCHED;
            }
        }

        if (!empty($errors)) {
            return $this->inquiryEvidence(
                self::STATUS_MANUAL_REVIEW,
                $errorClass,
                $fields,
                $rawStatus,
                $transportOutcome,
                $errors
            );
        }

        return $this->inquiryEvidence(
            self::STATUS_CONFIRMED_UNPOSTED,
            null,
            $fields,
            $rawStatus,
            $transportOutcome,
            []
        );
    }

    private function inquiryEvidence(
        string $status,
        ?string $errorClass,
        array $fields,
        ?string $rawStatus,
        array $transportOutcome,
        array $validationErrors
    ): KuickPayEvidence {
        return $this->evidence(
            self::OP_INQUIRY,
            $status,
            $errorClass,
            isset($fields[5]) && trim($fields[5]) !== '' ? trim($fields[5]) : null,
            null,
            isset($fields[1]) && trim($fields[1]) !== '' ? trim($fields[1]) : null,
            isset($fields[3]) ? $this->normalizeAmount($fields[3]) : null,
            isset($fields[6]) && trim($fields[6]) !== '' ? strtoupper(trim($fields[6])) : null,
            isset($fields[2]) ? $this->normalizeDate($fields[2]) : null,
            $rawStatus,
            $this->traceId($transportOutcome),
            $validationErrors
        );
    }

    private function malformedBulkEvidence(array $transportOutcome, array $validationErrors): KuickPayEvidence
    {
        return $this->evidence(
            self::OP_BULK,
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
            $validationErrors
        );
    }

    private function bulkEvidence(
        string $status,
        ?string $errorClass,
        array $row,
        array $transportOutcome,
        array $validationErrors
    ): KuickPayEvidence {
        return $this->evidence(
            self::OP_BULK,
            $status,
            $errorClass,
            $row['reference'] === '' ? null : $row['reference'],
            $row['consumer_number'] === '' ? null : $row['consumer_number'],
            $row['registration_number'] === '' ? null : $row['registration_number'],
            $this->normalizeAmount($row['amount']),
            $row['currency'] === '' ? null : strtoupper($row['currency']),
            $this->normalizeDate($row['paid_at']),
            null,
            $this->traceId($transportOutcome),
            $validationErrors
        );
    }

    private function bulkText(DOMElement $table, string $localName): ?string
    {
        foreach ($table->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return null;
    }

    private function parseInquiryFields(string $rawResult): array
    {
        // The inquiry result is comma-delimited with fixed field positions (status,
        // registration, date, amount, txn ref, reference, currency, institution). A comma is
        // always a field boundary -- the amount never embeds a thousands separator -- so split
        // directly. normalizeAmount() strips separators from the individual amount value if any
        // are ever present. Reconstructing fields from comma counts is unsound: a purely numeric
        // txn-ref field (field 4) is indistinguishable from a split amount and would corrupt the
        // reference/currency mapping for an otherwise-valid paid row.
        return array_map('trim', explode(',', $rawResult));
    }

    private function normalizeAmount(string $amount): ?string
    {
        $amount = str_replace(',', '', trim($amount));
        if ($amount === '' || preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        $parts = explode('.', $amount, 2);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') {
            $integer = '0';
        }

        $fraction = $parts[1] ?? '';
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return $integer . '.' . $fraction;
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '' || preg_match('/^\d{8}$/', $date) !== 1) {
            return null;
        }

        $parsed = DateTime::createFromFormat('!Ymd', $date);
        if (!$parsed || $parsed->format('Ymd') !== $date) {
            return null;
        }

        return $parsed->format('Y-m-d');
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
