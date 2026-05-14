<?php
/**
 * Abstract class that all Invoice Formats must extend
 *
 * @package blesta
 * @subpackage blesta.components.invoice_formats
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
#[\AllowDynamicProperties]
abstract class InvoiceFormat
{
    /**
     * @var array The config data for this format
     */
    protected $config = [];

    /**
     * @var stdClass The invoice data
     */
    protected $invoice;

    /**
     * @var string The generated format content
     */
    protected $content;

    /**
     * @var CurrencyFormat The CurrencyFormat object for formatting currency values
     */
    protected $currency_format;

    /**
     * Constructor - loads the config.json for this format
     */
    public function __construct()
    {
        // Load the Invoices model
        Loader::loadModels($this, ['Invoices']);

        // Load config.json for this format
        $this->loadConfig();
    }

    /**
     * Loads the config.json file for this format
     */
    private function loadConfig()
    {
        $class_name = get_class($this);
        $format_name = Loader::fromCamelCase($class_name);
        $config_file = COMPONENTDIR . 'invoice_formats' . DS . $format_name . DS . 'config.json';

        if (file_exists($config_file)) {
            $config_content = file_get_contents($config_file);
            $this->config = json_decode($config_content, true);
        }
    }

    /**
     * Sets the invoice ID and loads the invoice data
     *
     * @param int $invoice_id The invoice ID
     */
    public function setInvoiceId($invoice_id)
    {
        // Get invoice with full data including client info
        $this->invoice = $this->Invoices->get($invoice_id, true);

        if (!$this->invoice) {
            throw new Exception("Invoice with ID {$invoice_id} not found.");
        }

        // Set company_id from client if not already set
        if (!isset($this->invoice->company_id) && isset($this->invoice->client->company_id)) {
            $this->invoice->company_id = $this->invoice->client->company_id;
        } else {
            $this->invoice->company_id = Configure::get('Blesta.company_id');
        }
    }

    /**
     * Sets the CurrencyFormat object for formatting currency values
     *
     * @param CurrencyFormat $currency_format The CurrencyFormat object
     */
    public function setCurrency(CurrencyFormat $currency_format)
    {
        $this->currency_format = $currency_format;
    }

    /**
     * Generates the electronic invoice format
     * This method must be implemented by each format
     */
    abstract public function generateFormat();

    /**
     * Returns the name of this invoice format
     *
     * @return string The name of this format
     */
    public function getName()
    {
        return $this->config['name'] ?? get_class($this);
    }

    /**
     * Returns the description of this invoice format
     *
     * @return string The name of this format
     */
    public function getDescription()
    {
        return $this->config['description'] ?? get_class($this);
    }

    /**
     * Returns the version of this invoice format
     *
     * @return string The current version of this format
     */
    public function getVersion()
    {
        return $this->config['version'] ?? '1.0.0';
    }

    /**
     * Returns the name and URL for the authors of this invoice format
     *
     * @return array The name and URL of the authors of this format
     */
    public function getAuthors()
    {
        return $this->config['authors'] ?? [];
    }

    /**
     * Returns the MIME type for this format
     *
     * @return string The MIME type
     */
    public function getMimeType()
    {
        return $this->config['mime_type'] ?? 'text/plain';
    }

    /**
     * Returns the file extension for this format
     *
     * @return string The file extension
     */
    public function getFileExtension()
    {
        return $this->config['file_extension'] ?? 'txt';
    }

    /**
     * Returns the invoice format content in the desired format
     *
     * @return string The document content
     */
    public function fetch()
    {
        if (!$this->content) {
            $this->generateFormat();
        }

        return $this->content;
    }

    /**
     * Outputs the Invoice format to stdout, sending the appropriate headers to render the document inline
     *
     * @param string $name The name of the document minus the extension (optional)
     */
    public function stream($name = null)
    {
        if (!$name) {
            $name = 'invoice_' . Loader::fromCamelCase(get_class($this)) . '_' . $this->invoice->id_code;
        }

        $filename = $name . '.' . $this->getFileExtension();
        $content = $this->fetch();

        header('Content-Type: ' . $this->getMimeType());
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Pragma: public');

        echo $content;
    }

    /**
     * Outputs the Invoice format to stdout, sending the appropriate headers to force a download
     *
     * @param string $name The name of the document minus the extension (optional)
     */
    public function download($name = null)
    {
        if (!$name) {
            $name = 'invoice_' . Loader::fromCamelCase(get_class($this)) . '_' . $this->invoice->id_code;
        }

        $filename = $name . '.' . $this->getFileExtension();
        $content = $this->fetch();

        header('Content-Type: ' . $this->getMimeType());
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Pragma: public');

        echo $content;
    }
}