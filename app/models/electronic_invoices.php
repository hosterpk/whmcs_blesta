<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;
use Loader;

/**
 * Electronic Invoice management
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ElectronicInvoices extends AppModel
{
    /**
     * Initialize ElectronicInvoices
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['electronic_invoices']);
    }

    /**
     * Generates an electronic invoice in the specified format
     *
     * @param int $invoice_id The ID of the invoice to generate
     * @param string $format The format to generate (e.g., 'ubl')
     * @return string|false The generated content on success, false on error
     */
    public function generate($invoice_id, $format)
    {
        if (!isset($this->Invoices)) {
            Loader::loadModels($this, ['Invoices']);
        }

        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->InvoiceFormats)) {
            Loader::loadComponents($this, ['InvoiceFormats']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        $company_settings = $this->SettingsCollection->fetchSettings(
            $this->Companies,
            Configure::get('Blesta.company_id')
        );

        // Verify the format exists and is enabled
        if (!$this->isFormatEnabled($format, $company_settings)) {
            $this->Input->setErrors([
                'format' => [
                    'invalid' => Language::_('ElectronicInvoices.!error.format.invalid', true)
                ]
            ]);
            return false;
        }

        // Fetch the invoice data
        $invoice = $this->Invoices->get($invoice_id);
        if (!$invoice) {
            $this->Input->setErrors([
                'invoice_id' => [
                    'exists' => Language::_('ElectronicInvoices.!error.invoice_id.exists', true)
                ]
            ]);
            return false;
        }

        // Generate the electronic invoice format
        try {
            $format_instance = $this->InvoiceFormats->create($format);
            $format_instance->setInvoiceId($invoice_id);

            // Set currency format helper
            if (!isset($this->CurrencyFormat)) {
                Loader::loadHelpers($this, ['CurrencyFormat' => [Configure::get('Blesta.company_id')]]);
            }
            $format_instance->setCurrency($this->CurrencyFormat);

            // Generate the format
            $format_instance->generateFormat();
            $content = $format_instance->fetch();

            // Check if cache is enabled and save to cache
            if ($this->isCacheEnabled($company_settings)) {
                $this->writeCache($invoice_id, $format, $content);
            }

            return $content;
        } catch (Exception $e) {
            $this->Input->setErrors([
                'generate' => [
                    'failed' => $e->getMessage()
                ]
            ]);
            return false;
        }
    }

    /**
     * Generates all enabled electronic invoice formats for a given invoice
     *
     * @param int $invoice_id The ID of the invoice to generate
     * @return array An array of format => content pairs, empty array on error
     */
    public function generateAll($invoice_id)
    {
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        $company_settings = $this->SettingsCollection->fetchSettings(
            $this->Companies,
            Configure::get('Blesta.company_id')
        );

        // Get enabled formats
        $enabled_formats = $this->getEnabledFormats($company_settings);

        if (empty($enabled_formats)) {
            $this->Input->setErrors([
                'formats' => [
                    'none_enabled' => Language::_('ElectronicInvoices.!error.formats.none_enabled', true)
                ]
            ]);
            return [];
        }

        $results = [];
        foreach ($enabled_formats as $format) {
            $content = $this->generate($invoice_id, $format);
            if ($content !== false) {
                $results[$format] = $content;
            }
        }

        return $results;
    }

    /**
     * Downloads an electronic invoice in the specified format
     * Checks cache first, then generates if not cached
     *
     * @param int $invoice_id The ID of the invoice to download
     * @param string $format The format to download (e.g., 'ubl')
     * @return bool True on success, false on error
     */
    public function download($invoice_id, $format)
    {
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->InvoiceFormats)) {
            Loader::loadComponents($this, ['InvoiceFormats']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        $company_settings = $this->SettingsCollection->fetchSettings(
            $this->Companies,
            Configure::get('Blesta.company_id')
        );

        // Verify the format exists and is enabled
        if (!$this->isFormatEnabled($format, $company_settings)) {
            $this->Input->setErrors([
                'format' => [
                    'invalid' => Language::_('ElectronicInvoices.!error.format.invalid', true)
                ]
            ]);
            return false;
        }

        try {
            // Create format instance
            $format_instance = $this->InvoiceFormats->create($format);
            $format_instance->setInvoiceId($invoice_id);

            // Set currency format helper
            if (!isset($this->CurrencyFormat)) {
                Loader::loadHelpers($this, ['CurrencyFormat' => [Configure::get('Blesta.company_id')]]);
            }
            $format_instance->setCurrency($this->CurrencyFormat);

            // Check cache if enabled
            $cached_content = false;
            if ($this->isCacheEnabled($company_settings)) {
                $cached_content = $this->fetchCache($invoice_id, $format);
            }

            // Set security headers
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none';");
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');

            // If cached, download cached content using Download component
            if ($cached_content !== false) {
                if (!isset($this->Download)) {
                    Loader::loadComponents($this, ['Download']);
                }

                $filename = 'invoice_' . $invoice_id . '.' . $format_instance->getFileExtension();
                $this->Download->setContentType($format_instance->getMimeType());
                $this->Download->downloadData($filename, $cached_content);
            } else {
                // Generate and cache if enabled
                if ($this->isCacheEnabled($company_settings)) {
                    $format_instance->generateFormat();
                    $content = $format_instance->fetch();
                    $this->writeCache($invoice_id, $format, $content);
                }

                // Use format instance's download method
                $format_instance->download();
            }

            return true;
        } catch (Exception $e) {
            $this->Input->setErrors([
                'download' => [
                    'failed' => $e->getMessage()
                ]
            ]);
            return false;
        }
    }

    /**
     * Writes an electronic invoice to cache
     *
     * @param int $invoice_id The ID of the invoice to cache
     * @param string $format The format of the invoice to cache
     * @param string $data The data to cache
     * @return bool True if the invoice has been saved to cache successfully, false on error
     */
    public function writeCache($invoice_id, $format, $data)
    {
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        // Check if the invoice has been cached previously
        $cached_invoice = $this->fetchCache($invoice_id, $format);
        if (!empty($cached_invoice)) {
            return true;
        }

        // Fetch company settings
        $company_settings = $this->SettingsCollection->fetchSettings(
            $this->Companies,
            Configure::get('Blesta.company_id')
        );

        // Get file extension for this format
        try {
            if (!isset($this->InvoiceFormats)) {
                Loader::loadComponents($this, ['InvoiceFormats']);
            }
            $format_instance = $this->InvoiceFormats->create($format);
            $extension = $format_instance->getFileExtension();
        } catch (Exception $e) {
            return false;
        }

        // Create the cache folder if it does not exist
        $cache = rtrim($company_settings['uploads_dir'], DS) . DS . Configure::get('Blesta.company_id')
            . DS . 'invoices' . DS . md5('invoice_' . $invoice_id . $format) . '.' . $extension;
        $cache_dir = dirname($cache);

        if (!file_exists($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        // Save output to cache file
        file_put_contents($cache, $data);

        return true;
    }

    /**
     * Fetches a cached electronic invoice
     *
     * @param int $invoice_id The ID of the invoice to fetch
     * @param string $format The format of the invoice to fetch
     * @return string|false The cached content on success, false if not found
     */
    public function fetchCache($invoice_id, $format)
    {
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        // Fetch the data
        $uploads_dir = $this->SettingsCollection->fetchSetting(
            $this->Companies,
            Configure::get('Blesta.company_id'),
            'uploads_dir'
        );

        // Get file extension for this format
        try {
            if (!isset($this->InvoiceFormats)) {
                Loader::loadComponents($this, ['InvoiceFormats']);
            }
            $format_instance = $this->InvoiceFormats->create($format);
            $extension = $format_instance->getFileExtension();
        } catch (Exception $e) {
            return false;
        }

        $cache = rtrim($uploads_dir['value'], DS) . DS . Configure::get('Blesta.company_id')
            . DS . 'invoices' . DS . md5('invoice_' . $invoice_id . $format) . '.' . $extension;

        if (file_exists($cache)) {
            return file_get_contents($cache);
        }

        return false;
    }

    /**
     * Clears the electronic invoice cache
     *
     * @param int $invoice_id The ID of the invoice to clear
     * @param string $format The format to clear, or null to clear all formats for this invoice
     * @return bool True if the cache has been deleted successfully, false otherwise
     */
    public function clearCache($invoice_id, $format = null)
    {
        if (!isset($this->Companies)) {
            Loader::loadModels($this, ['Companies']);
        }

        if (!isset($this->SettingsCollection)) {
            Loader::loadComponents($this, ['SettingsCollection']);
        }

        $company_settings = $this->SettingsCollection->fetchSettings(
            $this->Companies,
            Configure::get('Blesta.company_id')
        );
        $uploads_dir = $this->SettingsCollection->fetchSetting(
            $this->Companies,
            Configure::get('Blesta.company_id'),
            'uploads_dir'
        );

        $cache_cleared = false;

        // If format is specified, clear only that format
        if ($format !== null) {
            try {
                if (!isset($this->InvoiceFormats)) {
                    Loader::loadComponents($this, ['InvoiceFormats']);
                }
                $format_instance = $this->InvoiceFormats->create($format);
                $extension = $format_instance->getFileExtension();

                $cache = rtrim($uploads_dir['value'], DS) . DS . Configure::get('Blesta.company_id')
                    . DS . 'invoices' . DS . md5('invoice_' . $invoice_id . $format) . '.' . $extension;

                if (is_file($cache)) {
                    @unlink($cache);
                    $cache_cleared = true;
                }
            } catch (Exception $e) {
                // Format doesn't exist, nothing to clear
            }
        } else {
            // Clear all formats for this invoice
            $enabled_formats = $this->getEnabledFormats($company_settings);
            foreach ($enabled_formats as $enabled_format) {
                if ($this->clearCache($invoice_id, $enabled_format)) {
                    $cache_cleared = true;
                }
            }
        }

        return $cache_cleared;
    }

    /**
     * Updates the cache for an invoice by clearing old cache and regenerating all formats
     *
     * @param int $invoice_id The ID of the invoice to update cache for
     * @return bool True if cache was updated successfully, false otherwise
     */
    public function updateCache($invoice_id)
    {
        // Clear all existing cache for this invoice
        $this->clearCache($invoice_id);

        // Regenerate all enabled formats (will automatically cache if enabled)
        $results = $this->generateAll($invoice_id);

        // Return true if at least one format was generated successfully
        return !empty($results);
    }

    /**
     * Checks if a format is enabled for the current company
     *
     * @param string $format The format to check
     * @param array $company_settings The company settings array
     * @return bool True if the format is enabled, false otherwise
     */
    private function isFormatEnabled($format, $company_settings)
    {
        $enabled_formats = $this->getEnabledFormats($company_settings);
        return in_array($format, $enabled_formats);
    }

    /**
     * Gets the list of enabled electronic invoice formats for the current company
     *
     * @param array $company_settings The company settings array
     * @return array List of enabled format keys
     */
    private function getEnabledFormats($company_settings)
    {
        if (
            !isset($company_settings['electronic_invoice_formats'])
            || empty($company_settings['electronic_invoice_formats'])
        ) {
            return [];
        }

        $formats = safe_unserialize($company_settings['electronic_invoice_formats']);

        return is_array($formats) ? $formats : [];
    }

    /**
     * Checks if caching is enabled for electronic invoices
     *
     * @param array $company_settings The company settings array
     * @return bool True if caching is enabled, false otherwise
     */
    private function isCacheEnabled($company_settings)
    {
        return isset($company_settings['inv_cache'])
            && $company_settings['inv_cache'] !== 'none';
    }
}
