<?php
/**
 * Language definitions for the Client Invoices controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Index
$lang['ClientInvoices.index.page_title'] = 'Client #%1$s Invoices'; // %1$s is the client ID number

$lang['ClientInvoices.index.category_open'] = 'Open';
$lang['ClientInvoices.index.category_closed'] = 'Closed';

$lang['ClientInvoices.index.categorylink_make_payment'] = 'Make Payment';

$lang['ClientInvoices.index.boxtitle_invoices'] = 'Invoices';
$lang['ClientInvoices.index.heading_invoice'] = 'Invoice #';
$lang['ClientInvoices.index.heading_amount'] = 'Amount';
$lang['ClientInvoices.index.heading_paid'] = 'Paid';
$lang['ClientInvoices.index.heading_due'] = 'Due';
$lang['ClientInvoices.index.heading_dateclosed'] = 'Date Closed';
$lang['ClientInvoices.index.heading_datebilled'] = 'Date Billed';
$lang['ClientInvoices.index.heading_datedue'] = 'Date Due';
$lang['ClientInvoices.index.heading_options'] = 'Actions';
$lang['ClientInvoices.index.option_view'] = 'View';
$lang['ClientInvoices.index.option_download'] = 'Download';
$lang['ClientInvoices.index.option_pay'] = 'Pay';

$lang['ClientInvoices.index.no_results'] = 'You have no %1$s Invoices.'; // %1$s is the language for the invoice category type (e.g. Open, Closed)


// Applied
$lang['ClientInvoices.applied.heading_paymenttype'] = 'Payment Type';
$lang['ClientInvoices.applied.heading_amount'] = 'Amount';
$lang['ClientInvoices.applied.heading_applied'] = 'Applied';
$lang['ClientInvoices.applied.heading_appliedon'] = 'Applied On';

$lang['ClientInvoices.applied.no_results'] = 'This invoice has no transactions applied to it.';


// View
$lang['ClientInvoices.view.page_title'] = 'Invoice #%1$s'; // %1$s is the invoice number

$lang['ClientInvoices.view.boxtitle_invoice'] = 'Invoice';
$lang['ClientInvoices.view.boxtitle_proforma'] = 'Proforma';

$lang['ClientInvoices.view.heading_invoice'] = 'Invoice #%1$s'; // %1$s is the invoice number
$lang['ClientInvoices.view.heading_proforma'] = 'Proforma #%1$s'; // %1$s is the proforma number

$lang['ClientInvoices.view.invoice_paid'] = 'PAID';
$lang['ClientInvoices.view.invoice_from'] = 'From:';
$lang['ClientInvoices.view.invoice_bill_to'] = 'Bill to:';
$lang['ClientInvoices.view.invoice_client_id'] = 'Client ID:';
$lang['ClientInvoices.view.invoice_date_billed'] = 'Date Billed:';
$lang['ClientInvoices.view.invoice_date_due'] = 'Date Due:';
$lang['ClientInvoices.view.invoice_description'] = 'Description';
$lang['ClientInvoices.view.invoice_quantity'] = 'Quantity';
$lang['ClientInvoices.view.invoice_unit_price'] = 'Unit Price';
$lang['ClientInvoices.view.invoice_cost'] = 'Cost';
$lang['ClientInvoices.view.invoice_subtotal'] = 'Subtotal';
$lang['ClientInvoices.view.invoice_tax'] = '%1$s (%2$s%%)'; // %1$s is the tax name, %2$s is the tax amount
$lang['ClientInvoices.view.invoice_total'] = 'Total';
$lang['ClientInvoices.view.invoice_notes'] = 'Notes';
$lang['ClientInvoices.view.invoice_terms'] = 'Terms';

$lang['ClientInvoices.view.field_download'] = 'Download Invoice';
$lang['ClientInvoices.view.field_pay_invoice'] = 'Pay Invoice';

$lang['ClientInvoices.view.payments_heading'] = 'Payments';
$lang['ClientInvoices.view.payments_applied_date'] = 'Date Applied';
$lang['ClientInvoices.view.payments_type'] = 'Type';
$lang['ClientInvoices.view.payments_transaction_id'] = 'Transaction #';
$lang['ClientInvoices.view.payments_amount'] = 'Amount Applied';
$lang['ClientInvoices.view.balance_due'] = 'Balance Due';
// Errors
$lang['ClientInvoices.!error.format.invalid'] = 'The selected invoice format is not available.';
