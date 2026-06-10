<?php
/**
 * Immutable KuickPay validation result.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayValidationResult
{
    private bool $valid;
    private array $reasons;

    public function __construct(bool $valid, array $reasons = [])
    {
        $this->reasons = array_values(array_unique(array_map('strval', $reasons)));
        $this->valid = $valid && empty($this->reasons);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function reasons(): array
    {
        return $this->reasons;
    }

    public function outcomeStatus(): string
    {
        return $this->valid ? 'confirmed_unposted' : 'manual_review';
    }
}
