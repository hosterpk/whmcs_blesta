<?php
use Blesta\Core\Util\Helpers\Helper;

/**
 * Settings Processor Helper
 *
 * Provides processing and validation for complex settings, particularly those
 * stored as JSON with multi-currency or range-based constraints.
 *
 * @package blesta
 * @subpackage helpers.settingsprocessor
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SettingsProcessor extends Helper
{
    /**
     * Validate currency-based settings with min/max constraints
     *
     * @param array $input_data Raw input data keyed by currency code,
     *   with each value containing 'min' and/or 'max' keys
     * @param int $company_id The company ID to fetch currency settings from
     * @return array Empty array if valid, or errors keyed by currency containing error keys
     */
    public function validateCurrencyBasedSettings($input_data, $company_id = null)
    {
        $errors = [];

        if (empty($input_data) || !is_array($input_data)) {
            return $errors;
        }

        Loader::loadModels($this, ['Currencies']);

        foreach ($input_data as $currency => $limits) {
            if (!is_array($limits)) {
                continue;
            }

            $min = isset($limits['min']) && $limits['min'] !== '' ? $limits['min'] : null;
            $max = isset($limits['max']) && $limits['max'] !== '' ? $limits['max'] : null;

            // Normalize values for validation (convert comma decimal to period)
            $min_normalized = $min !== null ? $this->normalizeCurrencyInput($min, $currency, $company_id) : null;
            $max_normalized = $max !== null ? $this->normalizeCurrencyInput($max, $currency, $company_id) : null;

            // Validate minimum
            if ($min !== null && ($min_normalized === null || !is_numeric($min_normalized) || (float)$min_normalized <= 0)) {
                $errors[$currency][] = 'min_amount';
            }

            // Validate maximum
            if ($max !== null && ($max_normalized === null || !is_numeric($max_normalized) || (float)$max_normalized <= 0)) {
                $errors[$currency][] = 'max_amount';
            }

            // Validate that max > min
            if ($min !== null && $max !== null && $min_normalized !== null && $max_normalized !== null
                && (float)$max_normalized <= (float)$min_normalized) {
                $errors[$currency][] = 'max_less_than_min';
            }
        }

        return $errors;
    }

    /**
     * Normalize currency input value by converting locale-specific decimal separators to period
     *
     * @param string $value The value to normalize
     * @param string $currency The ISO 4217 currency code
     * @param int $company_id The company ID to fetch currency settings from
     * @return string|null Normalized value with period as decimal separator, or null if invalid
     */
    public function normalizeCurrencyInput($value, $currency, $company_id = null)
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Load Currencies model if not already loaded
        if (!isset($this->Currencies)) {
            Loader::loadModels($this, ['Currencies']);
        }

        // Get currency info to detect decimal separator
        $currency_info = $this->Currencies->get($currency, $company_id);

        // Determine the decimal separator from the input itself
        // If input has both comma and period, the last one is the decimal separator
        // Examples: "1,234.56" (period is decimal), "1.234,56" (comma is decimal)
        $has_comma = strpos($value, ',') !== false;
        $has_period = strpos($value, '.') !== false;

        if ($has_comma && $has_period) {
            // Both separators present - determine which is decimal by position
            $last_comma = strrpos($value, ',');
            $last_period = strrpos($value, '.');
            $input_uses_comma_decimal = $last_comma > $last_period;
        } elseif ($has_comma) {
            // Only comma - could be decimal or thousand separator
            // Check currency format to determine
            if ($currency_info && isset($currency_info->format)) {
                $decimal = substr($currency_info->format, -3, 1);
                $input_uses_comma_decimal = ($decimal == ',');
            } else {
                // Assume comma is decimal if it's the last separator and there are exactly 2 digits after
                $parts = explode(',', $value);
                $input_uses_comma_decimal = (count($parts) == 2 && strlen(end($parts)) <= 2);
            }
        } else {
            // Only period or no separator - period is decimal
            $input_uses_comma_decimal = false;
        }

        // Normalize based on detected input format
        if ($input_uses_comma_decimal) {
            // Comma is decimal - remove periods (thousand separators) and convert comma to period
            $value = str_replace(['.', ' '], '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            // Period is decimal - remove commas and spaces (thousand separators)
            $value = str_replace([',', ' '], '', $value);
        }

        return $value;
    }

    /**
     * Process currency-based settings (format and structure)
     * No validation - use validateCurrencyBasedSettings() first if needed
     *
     * @param array $input_data Raw input data keyed by currency code,
     *   with each value containing 'min' and/or 'max' keys
     * @param int $company_id The company ID to fetch currency settings from
     * @return array Processed settings array ready for JSON encoding
     */
    public function processCurrencyBasedSettings($input_data, $company_id = null)
    {
        $processed_settings = [];

        if (empty($input_data) || !is_array($input_data)) {
            return $processed_settings;
        }

        foreach ($input_data as $currency => $limits) {
            if (!is_array($limits)) {
                continue;
            }

            $min = isset($limits['min']) && $limits['min'] !== '' ? $limits['min'] : null;
            $max = isset($limits['max']) && $limits['max'] !== '' ? $limits['max'] : null;

            // Skip if both are null
            if ($min === null && $max === null) {
                continue;
            }

            // Normalize values (convert comma decimal to period)
            $min_normalized = $min !== null ? $this->normalizeCurrencyInput($min, $currency, $company_id) : null;
            $max_normalized = $max !== null ? $this->normalizeCurrencyInput($max, $currency, $company_id) : null;

            // Build the limit data structure
            $limit_data = [];
            $limit_data['min'] = $min_normalized !== null ? $this->formatDecimalValue($min_normalized) : null;
            $limit_data['max'] = $max_normalized !== null ? $this->formatDecimalValue($max_normalized) : null;

            $processed_settings[$currency] = $limit_data;
        }

        return $this->encodeJsonSetting($processed_settings);
    }

    /**
     * Format a decimal value with consistent precision
     *
     * @param mixed $value The value to format
     * @param int $precision Number of decimal places (default: 4)
     * @return string Formatted decimal string
     */
    public function formatDecimalValue($value, $precision = 4)
    {
        // Convert to float first to handle string values with periods correctly
        $float_value = (float) $value;
        return number_format($float_value, $precision, '.', '');
    }

    /**
     * Encode data to JSON format with error handling
     *
     * @param mixed $data Data to encode
     * @return string JSON encoded string, or '{}' on error
     */
    public function encodeJsonSetting($data)
    {
        if (empty($data)) {
            return json_encode([]);
        }

        $json = json_encode($data);

        // Check for JSON encoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode([]);
        }

        return $json;
    }

    /**
     * Decode JSON setting with error handling
     *
     * @param string $json_string JSON string to decode
     * @return array Decoded array, or empty array on error
     */
    public function decodeJsonSetting($json_string)
    {
        if (empty($json_string)) {
            return [];
        }

        $decoded = json_decode($json_string, true);

        // Check for JSON decoding errors or non-array result
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
