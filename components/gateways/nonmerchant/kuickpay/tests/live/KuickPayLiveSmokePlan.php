<?php
/**
 * Pure opt-in plan for the manual KuickPay live smoke.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.kuickpay
 */
class KuickPayLiveSmokePlan
{
    private const DEFAULT_OPERATION = 'BillPaymentInquiry';
    private const DEFAULT_TIMEOUT = '30';

    private static $required_env = [
        'KUICKPAY_SMOKE_WSDL_URL',
        'KUICKPAY_SMOKE_INQUIRY_USERNAME',
        'KUICKPAY_SMOKE_INQUIRY_PASSWORD',
        'KUICKPAY_SMOKE_INSTITUTION_ID',
        'KUICKPAY_SMOKE_CONSUMER_NUMBER',
    ];

    private static $allowed_operations = [
        'BillPaymentInquiry',
        'Echo',
        'GetInstitutionsList',
    ];

    /**
     * Decide whether the live smoke may run from an injected environment map.
     *
     * @param array $env Environment values keyed by variable name
     * @return array Structured decision; never throws and never reads process env
     */
    public static function plan(array $env): array
    {
        $operation = self::operation($env['KUICKPAY_SMOKE_OPERATION'] ?? null);
        $decision = [
            'run' => false,
            'reason' => 'opt-in-not-set',
            'operation' => $operation,
            'config' => [],
            'missing' => [],
            'consumer_number' => self::stringValue($env['KUICKPAY_SMOKE_CONSUMER_NUMBER'] ?? null),
            'capture_path' => self::stringValue($env['KUICKPAY_SMOKE_CAPTURE'] ?? null),
        ];

        if (self::stringValue($env['KUICKPAY_LIVE_SMOKE'] ?? null) !== '1') {
            return $decision;
        }

        $missing = self::missingRequiredInputs($env);
        if (!empty($missing)) {
            $decision['reason'] = 'missing-required-inputs';
            $decision['missing'] = $missing;

            return $decision;
        }

        $decision['run'] = true;
        $decision['reason'] = 'ready';
        $decision['config'] = [
            'wsdl_url' => self::stringValue($env['KUICKPAY_SMOKE_WSDL_URL']),
            'inquiry_username' => self::stringValue($env['KUICKPAY_SMOKE_INQUIRY_USERNAME']),
            'inquiry_password' => self::stringValue($env['KUICKPAY_SMOKE_INQUIRY_PASSWORD']),
            'institution_id' => self::stringValue($env['KUICKPAY_SMOKE_INSTITUTION_ID']),
            'soap_timeout' => self::timeout($env['KUICKPAY_SMOKE_TIMEOUT'] ?? null),
            'inquiry_same_as_voucher' => 'false',
        ];

        return $decision;
    }

    private static function missingRequiredInputs(array $env): array
    {
        $missing = [];

        foreach (self::$required_env as $key) {
            if (self::stringValue($env[$key] ?? null) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private static function operation($operation): string
    {
        $operation = self::stringValue($operation);

        return in_array($operation, self::$allowed_operations, true) ? $operation : self::DEFAULT_OPERATION;
    }

    private static function timeout($timeout): string
    {
        $timeout = self::stringValue($timeout);

        if ($timeout === '' || preg_match('/^\d+$/', $timeout) !== 1) {
            return self::DEFAULT_TIMEOUT;
        }

        $timeout = (int) $timeout;
        if ($timeout < 1 || $timeout > 300) {
            return self::DEFAULT_TIMEOUT;
        }

        return (string) $timeout;
    }

    private static function stringValue($value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }
}
