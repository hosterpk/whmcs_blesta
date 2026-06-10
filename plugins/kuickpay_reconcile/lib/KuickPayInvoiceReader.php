<?php
/**
 * Thin read-only wrapper around Blesta invoice reads.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayInvoiceReader
{
    public function __construct()
    {
        Loader::loadModels($this, ['Invoices']);
    }

    public function get(int $invoice_id): ?stdClass
    {
        $invoice = $this->Invoices->get($invoice_id);

        return $invoice ?: null;
    }
}
