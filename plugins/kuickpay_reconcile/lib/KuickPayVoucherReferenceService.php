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
     * @var string|null Last sanitized generation error code
     */
    private $lastError = null;

    /**
     * Constructs the reference service.
     *
     * @param mixed $repository Optional repository, primarily for tests
     */
    public function __construct($repository = null)
    {
        if ($repository === null) {
            Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherRepository.php');
            $repository = new KuickPayVoucherRepository();
        }

        $this->repository = $repository;
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

        try {
            $firstInvoice = $context['invoice_amounts'][0] ?? null;
            if (!is_array($firstInvoice) || !isset($firstInvoice['id'])) {
                return null;
            }

            $invoice_id = (int) $firstInvoice['id'];
            $company_id = (int) ($context['company_id'] ?? 0);
            $pending = $this->repository->getPendingByInvoiceId($invoice_id, $company_id);
            if ($pending) {
                return $this->flatten($this->repository->getWithInvoices((int) $pending->id));
            }

            $references = null;
            for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
                $refs = $this->generateReferences($context);
                if ($refs['registration_number'] === '' || $refs['consumer_number'] === '') {
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
                'date_due' => $this->offsetDate((int) ($context['due_date_offset_days'] ?? 0)),
                'date_expires' => $this->offsetDate((int) ($context['expiry_date_offset_days'] ?? 0)),
            ];

            $invoiceLinks = [];
            foreach ((array) ($context['invoice_amounts'] ?? []) as $invoiceAmount) {
                $invoiceLinks[] = [
                    'invoice_id' => $invoiceAmount['id'] ?? null,
                    'amount' => $invoiceAmount['amount'] ?? null,
                ];
            }

            $voucher_id = $this->repository->create($voucherData, $invoiceLinks);
            if ($voucher_id) {
                return $this->flatten($this->repository->getWithInvoices($voucher_id));
            }

            $pending = $this->repository->getPendingByInvoiceId($invoice_id, $company_id);
            if ($pending) {
                return $this->flatten($this->repository->getWithInvoices((int) $pending->id));
            }
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
