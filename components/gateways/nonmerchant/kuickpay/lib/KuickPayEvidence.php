<?php
/**
 * Immutable normalized KuickPay evidence contract.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickPayEvidence
{
    private string $status;
    private ?string $error_class;
    private ?string $reference;
    private ?string $consumer_number;
    private ?string $registration_number;
    private ?string $amount;
    private ?string $currency;
    private ?string $paid_at;
    private ?string $raw_status;
    private string $redacted_trace_id;
    private string $evidence_hash;
    private array $validation_errors;
    private ?string $operation;

    /**
     * @param string $status Canonical parser status
     * @param string|null $error_class Canonical parser error class
     * @param string|null $reference KuickPay payment reference or voucher id
     * @param string|null $consumer_number Consumer Number for bulk rows
     * @param string|null $registration_number Registration Number
     * @param string|null $amount Canonical decimal string
     * @param string|null $currency Uppercase currency code
     * @param string|null $paid_at Canonical paid date
     * @param string|null $raw_status Provider status code
     * @param string $redacted_trace_id Redacted diagnostic trace id
     * @param string $evidence_hash Deterministic non-PII evidence hash
     * @param array $validation_errors Machine-readable validation reasons
     * @param string|null $operation Internal operation name, not part of the public contract
     */
    public function __construct(
        string $status,
        ?string $error_class,
        ?string $reference,
        ?string $consumer_number,
        ?string $registration_number,
        ?string $amount,
        ?string $currency,
        ?string $paid_at,
        ?string $raw_status,
        string $redacted_trace_id,
        string $evidence_hash,
        array $validation_errors = [],
        ?string $operation = null
    ) {
        $this->status = $status;
        $this->error_class = $error_class;
        $this->reference = $reference;
        $this->consumer_number = $consumer_number;
        $this->registration_number = $registration_number;
        $this->amount = $amount;
        $this->currency = $currency === null ? null : strtoupper($currency);
        $this->paid_at = $paid_at;
        $this->raw_status = $raw_status;
        $this->redacted_trace_id = $redacted_trace_id;
        $this->evidence_hash = $evidence_hash;
        $this->validation_errors = array_values(array_map('strval', $validation_errors));
        $this->operation = $operation;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function errorClass(): ?string
    {
        return $this->error_class;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function consumerNumber(): ?string
    {
        return $this->consumer_number;
    }

    public function registrationNumber(): ?string
    {
        return $this->registration_number;
    }

    public function amount(): ?string
    {
        return $this->amount;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function paidAt(): ?string
    {
        return $this->paid_at;
    }

    public function rawStatus(): ?string
    {
        return $this->raw_status;
    }

    public function redactedTraceId(): string
    {
        return $this->redacted_trace_id;
    }

    public function evidenceHash(): string
    {
        return $this->evidence_hash;
    }

    public function validationErrors(): array
    {
        return $this->validation_errors;
    }

    public function operation(): ?string
    {
        return $this->operation;
    }

    public function isConfirmedUnposted(): bool
    {
        return $this->status === 'confirmed_unposted';
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'error_class' => $this->error_class,
            'reference' => $this->reference,
            'consumer_number' => $this->consumer_number,
            'registration_number' => $this->registration_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at,
            'raw_status' => $this->raw_status,
            'redacted_trace_id' => $this->redacted_trace_id,
            'evidence_hash' => $this->evidence_hash,
            'validation_errors' => $this->validation_errors,
        ];
    }
}
