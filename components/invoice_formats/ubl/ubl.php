<?php

/**
 * UBL XML Invoice Format
 *
 * @package blesta
 * @subpackage components.invoice_formats.ubl
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Ubl extends InvoiceFormat
{
    /**
     * Constructor - loads language for this format
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Generates the UBL XML format
     */
    public function generateFormat()
    {
        if (!$this->invoice) {
            throw new Exception("Invoice data must be set before generating format.");
        }

        // Load company and client data
        Loader::loadModels($this, ['Companies', 'Clients', 'Contacts']);
        $company = $this->Companies->get($this->invoice->company_id);
        $client = $this->Clients->get($this->invoice->client_id);

        // Get company contact info
        $company_contact = null;
        if (!empty($company->contact_id)) {
            $company_contact = $this->Contacts->get($company->contact_id);
        }

        // Get client contact info
        $client_contact = null;
        if (!empty($client->contact_id)) {
            $client_contact = $this->Contacts->get($client->contact_id);
        }

        // Create XML document
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Create root Invoice element with namespaces
        $invoice = $xml->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $invoice->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cac',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'
        );
        $invoice->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:cbc',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'
        );
        $xml->appendChild($invoice);

        // Add UBL version ID (OASIS UBL 2.1)
        $ubl_version = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:UBLVersionID',
            '2.1'
        );
        $invoice->appendChild($ubl_version);

        // Add customization ID (OASIS UBL 2.1 Standard)
        $customization = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:CustomizationID',
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2'
        );
        $invoice->appendChild($customization);

        // Add invoice ID
        $id = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:ID',
            htmlspecialchars($this->invoice->id_code)
        );
        $invoice->appendChild($id);

        // Add issue date
        $issue_date = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:IssueDate',
            date('Y-m-d', strtotime($this->invoice->date_billed))
        );
        $invoice->appendChild($issue_date);

        // Add due date if available
        if (!empty($this->invoice->date_due)) {
            $due_date = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                'cbc:DueDate',
                date('Y-m-d', strtotime($this->invoice->date_due))
            );
            $invoice->appendChild($due_date);
        }

        // Add invoice type code (380 = Commercial Invoice as per UN/CEFACT code list)
        $type_code = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:InvoiceTypeCode',
            '380'
        );
        $invoice->appendChild($type_code);

        // Add document currency code
        $currency_code = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:DocumentCurrencyCode',
            htmlspecialchars($this->invoice->currency)
        );
        $invoice->appendChild($currency_code);

        // Add accounting supplier party (company)
        $supplier_party = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:AccountingSupplierParty'
        );
        $invoice->appendChild($supplier_party);

        $supplier = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:Party'
        );
        $supplier_party->appendChild($supplier);

        // Add supplier name
        $supplier_name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:PartyName'
        );
        $supplier->appendChild($supplier_name);

        $name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:Name',
            htmlspecialchars($company->name)
        );
        $supplier_name->appendChild($name);

        // Add supplier postal address if available
        if ($company_contact) {
            $postal_address = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                'cac:PostalAddress'
            );
            $supplier->appendChild($postal_address);

            if (!empty($company_contact->address1)) {
                $street_name = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:StreetName',
                    htmlspecialchars($company_contact->address1)
                );
                $postal_address->appendChild($street_name);
            }

            if (!empty($company_contact->address2)) {
                $additional_street = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:AdditionalStreetName',
                    htmlspecialchars($company_contact->address2)
                );
                $postal_address->appendChild($additional_street);
            }

            if (!empty($company_contact->city)) {
                $city_name = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:CityName',
                    htmlspecialchars($company_contact->city)
                );
                $postal_address->appendChild($city_name);
            }

            if (!empty($company_contact->zip)) {
                $postal_zone = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:PostalZone',
                    htmlspecialchars($company_contact->zip)
                );
                $postal_address->appendChild($postal_zone);
            }

            if (!empty($company_contact->state)) {
                $country_subentity = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:CountrySubentity',
                    htmlspecialchars($company_contact->state)
                );
                $postal_address->appendChild($country_subentity);
            }

            if (!empty($company_contact->country)) {
                $country = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:Country'
                );
                $postal_address->appendChild($country);

                $country_code = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:IdentificationCode',
                    htmlspecialchars(strtoupper($company_contact->country))
                );
                $country->appendChild($country_code);
            }
        }

        // Add supplier party legal entity
        $party_legal_entity = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:PartyLegalEntity'
        );
        $supplier->appendChild($party_legal_entity);

        $registration_name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:RegistrationName',
            htmlspecialchars($company->name)
        );
        $party_legal_entity->appendChild($registration_name);

        // Add contact information if available
        if ($company_contact) {
            $contact = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                'cac:Contact'
            );
            $supplier->appendChild($contact);

            if (!empty($company_contact->first_name) || !empty($company_contact->last_name)) {
                $contact_name = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:Name',
                    htmlspecialchars(trim($company_contact->first_name . ' ' . $company_contact->last_name))
                );
                $contact->appendChild($contact_name);
            }

            if (!empty($company->phone)) {
                $telephone = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:Telephone',
                    htmlspecialchars($company->phone)
                );
                $contact->appendChild($telephone);
            }

            if (!empty($company_contact->email)) {
                $electronic_mail = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:ElectronicMail',
                    htmlspecialchars($company_contact->email)
                );
                $contact->appendChild($electronic_mail);
            }
        }

        // Add accounting customer party (client)
        $customer_party = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:AccountingCustomerParty'
        );
        $invoice->appendChild($customer_party);

        $customer = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:Party'
        );
        $customer_party->appendChild($customer);

        // Add customer name
        $customer_name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:PartyName'
        );
        $customer->appendChild($customer_name);

        $client_display_name =
            !empty($client->company) ? $client->company : $client->first_name . ' ' . $client->last_name;
        $name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:Name',
            htmlspecialchars($client_display_name)
        );
        $customer_name->appendChild($name);

        // Add customer postal address if available
        if ($client_contact) {
            $postal_address = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                'cac:PostalAddress'
            );
            $customer->appendChild($postal_address);

            if (!empty($client_contact->address1)) {
                $street_name = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:StreetName',
                    htmlspecialchars($client_contact->address1)
                );
                $postal_address->appendChild($street_name);
            }

            if (!empty($client_contact->address2)) {
                $additional_street = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:AdditionalStreetName',
                    htmlspecialchars($client_contact->address2)
                );
                $postal_address->appendChild($additional_street);
            }

            if (!empty($client_contact->city)) {
                $city_name = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:CityName',
                    htmlspecialchars($client_contact->city)
                );
                $postal_address->appendChild($city_name);
            }

            if (!empty($client_contact->zip)) {
                $postal_zone = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:PostalZone',
                    htmlspecialchars($client_contact->zip)
                );
                $postal_address->appendChild($postal_zone);
            }

            if (!empty($client_contact->state)) {
                $country_subentity = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:CountrySubentity',
                    htmlspecialchars($client_contact->state)
                );
                $postal_address->appendChild($country_subentity);
            }

            if (!empty($client_contact->country)) {
                $country = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:Country'
                );
                $postal_address->appendChild($country);

                $country_code = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:IdentificationCode',
                    htmlspecialchars(strtoupper($client_contact->country))
                );
                $country->appendChild($country_code);
            }
        }

        // Add customer party legal entity
        $customer_legal_entity = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:PartyLegalEntity'
        );
        $customer->appendChild($customer_legal_entity);

        $customer_reg_name = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:RegistrationName',
            htmlspecialchars($client_display_name)
        );
        $customer_legal_entity->appendChild($customer_reg_name);

        // Add invoice lines
        if (isset($this->invoice->line_items) && is_array($this->invoice->line_items)) {
            foreach ($this->invoice->line_items as $index => $line_item) {
                $invoice_line = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:InvoiceLine'
                );
                $invoice->appendChild($invoice_line);

                // Line ID
                $line_id = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:ID',
                    $index + 1
                );
                $invoice_line->appendChild($line_id);

                // Invoiced quantity
                $quantity = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:InvoicedQuantity',
                    $line_item->qty
                );
                $quantity->setAttribute('unitCode', 'C62'); // Unit = "one"
                $invoice_line->appendChild($quantity);

                // Line extension amount
                $line_total = $line_item->qty * $line_item->amount;
                $extension_amount = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:LineExtensionAmount',
                    $this->formatCurrency($line_total)
                );
                $extension_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
                $invoice_line->appendChild($extension_amount);

                // Item
                $item = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:Item'
                );
                $invoice_line->appendChild($item);

                $description = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:Description',
                    htmlspecialchars($line_item->description)
                );
                $item->appendChild($description);

                // Add tax classification for item
                $classified_tax_category = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:ClassifiedTaxCategory'
                );
                $item->appendChild($classified_tax_category);

                $item_tax_id = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:ID',
                    'S' // Standard rate
                );
                $classified_tax_category->appendChild($item_tax_id);

                $item_tax_percent = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:Percent',
                    '0.00'
                );
                $classified_tax_category->appendChild($item_tax_percent);

                $item_tax_scheme = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:TaxScheme'
                );
                $classified_tax_category->appendChild($item_tax_scheme);

                $item_tax_scheme_id = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:ID',
                    'VAT'
                );
                $item_tax_scheme->appendChild($item_tax_scheme_id);

                // Price
                $price = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                    'cac:Price'
                );
                $invoice_line->appendChild($price);

                $price_amount = $xml->createElementNS(
                    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'cbc:PriceAmount',
                    $this->formatCurrency($line_item->amount)
                );
                $price_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
                $price->appendChild($price_amount);
            }
        }

        // Get totals for tax and monetary calculations
        $presenter = $this->Invoices->getPresenter($this->invoice->id);
        $totals = $presenter->totals();

        // Add tax total if taxes are present
        if (isset($totals->tax_amount) && $totals->tax_amount > 0) {
            $tax_total = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                'cac:TaxTotal'
            );
            $invoice->appendChild($tax_total);

            $tax_amount_element = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                'cbc:TaxAmount',
                $this->formatCurrency($totals->tax_amount)
            );
            $tax_amount_element->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
            $tax_total->appendChild($tax_amount_element);

            // Add tax subtotal for each tax
            if (isset($totals->taxes) && is_array($totals->taxes)) {
                foreach ($totals->taxes as $tax) {
                    $tax_subtotal = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                        'cac:TaxSubtotal'
                    );
                    $tax_total->appendChild($tax_subtotal);

                    $taxable_amount = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                        'cbc:TaxableAmount',
                        $this->formatCurrency($totals->subtotal)
                    );
                    $taxable_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
                    $tax_subtotal->appendChild($taxable_amount);

                    $subtotal_tax_amount = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                        'cbc:TaxAmount',
                        $this->formatCurrency($tax->tax_amount)
                    );
                    $subtotal_tax_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
                    $tax_subtotal->appendChild($subtotal_tax_amount);

                    // Add tax category
                    $tax_category = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                        'cac:TaxCategory'
                    );
                    $tax_subtotal->appendChild($tax_category);

                    $tax_category_id = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                        'cbc:ID',
                        'S' // Standard rate
                    );
                    $tax_category->appendChild($tax_category_id);

                    if (isset($tax->percentage)) {
                        $tax_percent = $xml->createElementNS(
                            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                            'cbc:Percent',
                            $this->formatCurrency($tax->percentage)
                        );
                        $tax_category->appendChild($tax_percent);
                    }

                    // Add tax scheme
                    $tax_scheme = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                        'cac:TaxScheme'
                    );
                    $tax_category->appendChild($tax_scheme);

                    $tax_scheme_id = $xml->createElementNS(
                        'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                        'cbc:ID',
                        'VAT' // Value Added Tax
                    );
                    $tax_scheme->appendChild($tax_scheme_id);
                }
            }
        }

        // Add legal monetary total
        $monetary_total = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cac:LegalMonetaryTotal'
        );
        $invoice->appendChild($monetary_total);

        $line_extension_amount = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:LineExtensionAmount',
            $this->formatCurrency($totals->subtotal)
        );
        $line_extension_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
        $monetary_total->appendChild($line_extension_amount);

        if (isset($totals->tax_amount) && $totals->tax_amount > 0) {
            $tax_exclusive_amount = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                'cbc:TaxExclusiveAmount',
                $this->formatCurrency($totals->subtotal)
            );
            $tax_exclusive_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
            $monetary_total->appendChild($tax_exclusive_amount);

            $tax_inclusive_amount = $xml->createElementNS(
                'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                'cbc:TaxInclusiveAmount',
                $this->formatCurrency($totals->total)
            );
            $tax_inclusive_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
            $monetary_total->appendChild($tax_inclusive_amount);
        }

        $payable_amount = $xml->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'cbc:PayableAmount',
            $this->formatCurrency($totals->total)
        );
        $payable_amount->setAttribute('currencyID', htmlspecialchars($this->invoice->currency));
        $monetary_total->appendChild($payable_amount);

        // Generate XML string
        $this->content = $xml->saveXML();
    }

    /**
     * Formats a currency value for UBL XML (plain number without currency symbol)
     *
     * @param float $amount The amount to format
     * @return string The formatted amount
     */
    private function formatCurrency($amount)
    {
        if (!$this->currency_format) {
            // Fallback to number_format if CurrencyFormat is not set
            return number_format($amount, 2, '.', '');
        }

        // Use CurrencyFormat with options to get plain number format
        $currency_options = [
            'prefix' => false,
            'suffix' => false,
            'code' => false,
            'with_separator' => false
        ];

        $formatted = $this->currency_format->format($amount, $this->invoice->currency, $currency_options);

        // Remove any remaining currency symbols or spaces and ensure decimal formatting
        $formatted = preg_replace('/[^\d.-]/', '', $formatted);

        // Ensure 2 decimal places
        return number_format((float) $formatted, 2, '.', '');
    }
}
