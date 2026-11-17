<?php

declare(strict_types=1);

namespace App\Exchange\Serializers\Ubl;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Serializers\DocumentSerializer;
use App\Support\Xml\Element as E;
use App\Support\Xml\XmlWriter;

/**
 * UBL 2.1, from the semantic model - PF-112.
 *
 * ## What this class is allowed to know
 *
 * The model, and the UBL schema. Not the database, not the invoice table,
 * not a profile's business rules. Everything it emits comes from a `BT`
 * lookup, which is what makes the mapping document checkable: a term that
 * appears here and not in the mapping fails the build.
 *
 * ## An Invoice and a CreditNote are different documents
 *
 * Not one document with a different type code. They are separate UBL
 * document types with separate root elements, separate line elements
 * (`cac:InvoiceLine` against `cac:CreditNoteLine`), separate quantity
 * elements (`cbc:InvoicedQuantity` against `cbc:CreditedQuantity`) and, the
 * part that catches everyone, a different element order: the credit note
 * puts `cbc:TaxPointDate` ahead of its type code and its note.
 *
 * A debit note (UNCL 1001 383) and a corrected invoice (384) are **Invoice**
 * documents in UBL - the correction is in the type code and the preceding
 * reference, not in the root element.
 *
 * ## Code lists carry their list identifier
 *
 * `S` is a tax category in UNCL 5305 and a unit in other lists;
 * `380` is an invoice type in UNCL 1001 and a currency elsewhere. A code
 * without its list is a string a recipient has to guess the meaning of, and
 * the EN 16931 rule set rejects several of them outright.
 */
final class UblSerializer implements DocumentSerializer
{
    private const NAMESPACES_INVOICE = [
        '' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    private const NAMESPACES_CREDIT_NOTE = [
        '' => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    /** UNCL 1001 codes that are credit notes in UBL rather than invoices. */
    private const CREDIT_NOTE_TYPES = ['381', '261'];

    public function syntax(): string
    {
        return 'ubl';
    }

    public function mediaType(): string
    {
        return 'application/xml';
    }

    public function serialize(SemanticInvoice $invoice): string
    {
        return $this->render($this->tree($invoice), $invoice);
    }

    /**
     * The element tree, before it is rendered - PF-123.
     *
     * Exposed because a national regime adds elements EN 16931 has no term
     * for: ZATCA's previous-invoice hash, its invoice counter and its QR
     * payload are `cac:AdditionalDocumentReference` entries that no business
     * term describes. A profile that needs them composes this tree and adds
     * to it.
     *
     * Composition, not inheritance. A `ZatcaSerializer extends UblSerializer`
     * would make the national variant able to change anything, and the first
     * country to override `parties()` would quietly have its own serializer
     * with a shared name - which is the thing the whole phase is arranged to
     * avoid. Adding to a tree can only add.
     */
    public function tree(SemanticInvoice $invoice): E
    {
        $isCreditNote = $this->isCreditNote($invoice);
        $root = E::of($isCreditNote ? 'CreditNote' : 'Invoice');

        $this->header($root, $invoice, $isCreditNote);
        $this->parties($root, $invoice);
        $this->delivery($root, $invoice);
        $this->payment($root, $invoice);
        $this->allowancesAndCharges($root, $invoice);
        $this->taxTotal($root, $invoice);
        $this->monetaryTotals($root, $invoice);
        $this->lines($root, $invoice, $isCreditNote);

        return $root;
    }

    /** Render a tree this serializer built, with the right namespaces. */
    public function render(E $root, SemanticInvoice $invoice): string
    {
        return (new XmlWriter(UblSequences::all()))->render(
            $root,
            $this->isCreditNote($invoice) ? self::NAMESPACES_CREDIT_NOTE : self::NAMESPACES_INVOICE,
        );
    }

    public function isCreditNote(SemanticInvoice $invoice): bool
    {
        return \in_array($invoice->documentTypeCode, self::CREDIT_NOTE_TYPES, true);
    }

    private function header(E $root, SemanticInvoice $invoice, bool $isCreditNote): void
    {
        $control = $invoice->group(BG::ProcessControl);

        $root->with(
            $this->maybe('cbc:CustomizationID', $control?->get(BT::SpecificationIdentifier)),
            $this->maybe('cbc:ProfileID', $control?->get(BT::BusinessProcessType)),
            $this->maybe('cbc:ID', $invoice->get(BT::InvoiceNumber)),
            $this->maybe('cbc:IssueDate', $invoice->get(BT::IssueDate)),
            $this->maybe('cbc:DueDate', $invoice->get(BT::PaymentDueDate)),
            $this->maybe('cbc:TaxPointDate', $invoice->get(BT::TaxPointDate)),
        );

        /*
         | The type code carries its list - UNCL 1001.
         |
         | `listID` is not emitted on the type code in the EN 16931 UBL
         | binding, which states the list in the specification identifier
         | instead: adding one here is a difference from every other
         | implementation and a Schematron warning.
         */
        $root->with(E::text(
            $isCreditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode',
            (string) $invoice->get(BT::InvoiceTypeCode),
        ));

        foreach ($invoice->groups(BG::InvoiceNote) as $note) {
            $text = $note->get(BT::InvoiceNote);

            if ($text === null) {
                continue;
            }

            /*
             | BT-21 is prefixed into the note, which is the UBL binding.
             |
             | EN 16931 has a subject code and a text; UBL has one element.
             | The binding is `#<code>#<text>`, and inventing a second
             | element for the code would be a document no recipient parses.
             */
            $subject = $note->get(BT::InvoiceNoteSubjectCode);

            $root->with(E::text(
                'cbc:Note',
                $subject === null ? $text : sprintf('#%s#%s', $subject, $text),
            ));
        }

        $root->with(
            $this->maybe('cbc:DocumentCurrencyCode', $invoice->get(BT::InvoiceCurrencyCode)),
            $this->maybe('cbc:TaxCurrencyCode', $invoice->get(BT::VatAccountingCurrencyCode)),
            $this->maybe('cbc:AccountingCost', $invoice->get(BT::BuyerAccountingReference)),
            $this->maybe('cbc:BuyerReference', $invoice->get(BT::BuyerReference)),
        );

        foreach ($invoice->groups(BG::InvoicingPeriod) as $period) {
            $root->with(E::of('cac:InvoicePeriod')->with(
                $this->maybe('cbc:StartDate', $period->get(BT::InvoicingPeriodStartDate)),
                $this->maybe('cbc:EndDate', $period->get(BT::InvoicingPeriodEndDate)),
            ));
        }

        $order = $invoice->get(BT::PurchaseOrderReference);
        $salesOrder = $invoice->get(BT::SalesOrderReference);

        if ($order !== null || $salesOrder !== null) {
            /*
             | `cbc:ID` is mandatory inside cac:OrderReference.
             |
             | A document with only a sales order reference still needs one,
             | and UBL's own convention for "no buyer order number" is the
             | string `NA` rather than an empty element.
             */
            $root->with(E::of('cac:OrderReference')->with(
                E::text('cbc:ID', $order ?? 'NA'),
                $this->maybe('cbc:SalesOrderID', $salesOrder),
            ));
        }

        foreach ($invoice->groups(BG::PrecedingInvoiceReference) as $preceding) {
            $root->with(E::of('cac:BillingReference')->with(
                E::of('cac:InvoiceDocumentReference')->with(
                    E::text('cbc:ID', (string) $preceding->get(BT::PrecedingInvoiceReference)),
                    $this->maybe('cbc:IssueDate', $preceding->get(BT::PrecedingInvoiceIssueDate)),
                ),
            ));
        }

        if ($invoice->get(BT::ContractReference) !== null) {
            $root->with(E::of('cac:ContractDocumentReference')->with(
                E::text('cbc:ID', (string) $invoice->get(BT::ContractReference)),
            ));
        }

        foreach ($invoice->groups(BG::AdditionalSupportingDocuments) as $document) {
            $root->with(E::of('cac:AdditionalDocumentReference')->with(
                E::text('cbc:ID', (string) $document->get(BT::SupportingDocumentReference)),
                $this->maybe('cbc:DocumentDescription', $document->get(BT::SupportingDocumentDescription)),
                $document->get(BT::ExternalDocumentLocation) === null
                    ? null
                    : E::of('cac:Attachment')->with(
                        E::of('cac:ExternalReference')->with(
                            E::text('cbc:URI', (string) $document->get(BT::ExternalDocumentLocation)),
                        ),
                    ),
            ));
        }

        if ($invoice->get(BT::ProjectReference) !== null) {
            $root->with(E::of('cac:ProjectReference')->with(
                E::text('cbc:ID', (string) $invoice->get(BT::ProjectReference)),
            ));
        }
    }

    private function parties(E $root, SemanticInvoice $invoice): void
    {
        $seller = $invoice->group(BG::Seller);

        if ($seller !== null) {
            $party = E::of('cac:Party')->with(
                $seller->has(BT::SellerElectronicAddress)
                    ? E::text(
                        'cbc:EndpointID',
                        (string) $seller->get(BT::SellerElectronicAddress),
                        ['schemeID' => $seller->qualifier(BT::SellerElectronicAddress)],
                    )
                    : null,
            );

            foreach ($seller->all(BT::SellerIdentifier) as $ordinal => $identifier) {
                $party->with(E::of('cac:PartyIdentification')->with(
                    E::text('cbc:ID', $identifier, ['schemeID' => $seller->qualifier(BT::SellerIdentifier, $ordinal)]),
                ));
            }

            if ($seller->has(BT::SellerTradingName)) {
                $party->with(E::of('cac:PartyName')->with(
                    E::text('cbc:Name', (string) $seller->get(BT::SellerTradingName)),
                ));
            }

            $party->with($this->address(
                'cac:PostalAddress',
                $seller->first(BG::SellerPostalAddress),
                [
                    BT::SellerAddressLine1, BT::SellerAddressLine2, BT::SellerCity,
                    BT::SellerPostCode, BT::SellerCountrySubdivision, BT::SellerCountryCode,
                ],
            ));

            if ($seller->has(BT::SellerVatIdentifier)) {
                $party->with(E::of('cac:PartyTaxScheme')->with(
                    E::text('cbc:CompanyID', (string) $seller->get(BT::SellerVatIdentifier)),
                    E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
                ));
            }

            if ($seller->has(BT::SellerTaxRegistrationIdentifier)) {
                /*
                 | A second scheme, named `!VAT` - the EN 16931 binding.
                 |
                 | It is how a jurisdiction's non-VAT registration is carried
                 | without colliding with BT-31, and it looks like a typo
                 | until you read the specification.
                 */
                $party->with(E::of('cac:PartyTaxScheme')->with(
                    E::text('cbc:CompanyID', (string) $seller->get(BT::SellerTaxRegistrationIdentifier)),
                    E::of('cac:TaxScheme')->with(E::text('cbc:ID', '!VAT')),
                ));
            }

            $party->with(E::of('cac:PartyLegalEntity')->with(
                E::text('cbc:RegistrationName', (string) $seller->get(BT::SellerName)),
                $this->maybe('cbc:CompanyID', $seller->get(BT::SellerLegalRegistrationIdentifier)),
                $this->maybe('cbc:CompanyLegalForm', $seller->get(BT::SellerAdditionalLegalInformation)),
            ));

            $contact = $seller->first(BG::SellerContact);

            if ($contact !== null) {
                $party->with(E::of('cac:Contact')->with(
                    $this->maybe('cbc:Name', $contact->get(BT::SellerContactPoint)),
                    $this->maybe('cbc:Telephone', $contact->get(BT::SellerContactTelephone)),
                    $this->maybe('cbc:ElectronicMail', $contact->get(BT::SellerContactEmail)),
                ));
            }

            $root->with(E::of('cac:AccountingSupplierParty')->with($party));
        }

        $buyer = $invoice->group(BG::Buyer);

        if ($buyer !== null) {
            $party = E::of('cac:Party')->with(
                $buyer->has(BT::BuyerElectronicAddress)
                    ? E::text(
                        'cbc:EndpointID',
                        (string) $buyer->get(BT::BuyerElectronicAddress),
                        ['schemeID' => $buyer->qualifier(BT::BuyerElectronicAddress)],
                    )
                    : null,
                $buyer->has(BT::BuyerIdentifier)
                    ? E::of('cac:PartyIdentification')->with(
                        E::text(
                            'cbc:ID',
                            (string) $buyer->get(BT::BuyerIdentifier),
                            ['schemeID' => $buyer->qualifier(BT::BuyerIdentifier)],
                        ),
                    )
                    : null,
                $buyer->has(BT::BuyerTradingName)
                    ? E::of('cac:PartyName')->with(E::text('cbc:Name', (string) $buyer->get(BT::BuyerTradingName)))
                    : null,
            );

            $party->with($this->address(
                'cac:PostalAddress',
                $buyer->first(BG::BuyerPostalAddress),
                [
                    BT::BuyerAddressLine1, BT::BuyerAddressLine2, BT::BuyerCity,
                    BT::BuyerPostCode, BT::BuyerCountrySubdivision, BT::BuyerCountryCode,
                ],
            ));

            if ($buyer->has(BT::BuyerVatIdentifier)) {
                $party->with(E::of('cac:PartyTaxScheme')->with(
                    E::text('cbc:CompanyID', (string) $buyer->get(BT::BuyerVatIdentifier)),
                    E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
                ));
            }

            $party->with(E::of('cac:PartyLegalEntity')->with(
                E::text('cbc:RegistrationName', (string) $buyer->get(BT::BuyerName)),
                $this->maybe('cbc:CompanyID', $buyer->get(BT::BuyerLegalRegistrationIdentifier)),
            ));

            $contact = $buyer->first(BG::BuyerContact);

            if ($contact !== null) {
                $party->with(E::of('cac:Contact')->with(
                    $this->maybe('cbc:Name', $contact->get(BT::BuyerContactPoint)),
                    $this->maybe('cbc:Telephone', $contact->get(BT::BuyerContactTelephone)),
                    $this->maybe('cbc:ElectronicMail', $contact->get(BT::BuyerContactEmail)),
                ));
            }

            $root->with(E::of('cac:AccountingCustomerParty')->with($party));
        }

        $payee = $invoice->group(BG::Payee);

        if ($payee !== null) {
            $root->with(E::of('cac:PayeeParty')->with(
                $payee->has(BT::PayeeIdentifier)
                    ? E::of('cac:PartyIdentification')->with(E::text('cbc:ID', (string) $payee->get(BT::PayeeIdentifier)))
                    : null,
                E::of('cac:PartyName')->with(E::text('cbc:Name', (string) $payee->get(BT::PayeeName))),
                $payee->has(BT::PayeeLegalRegistrationIdentifier)
                    ? E::of('cac:PartyLegalEntity')->with(
                        E::text('cbc:CompanyID', (string) $payee->get(BT::PayeeLegalRegistrationIdentifier)),
                    )
                    : null,
            ));
        }

        $representative = $invoice->group(BG::SellerTaxRepresentativeParty);

        if ($representative !== null) {
            $root->with(E::of('cac:TaxRepresentativeParty')->with(
                E::of('cac:Party')->with(
                    E::of('cac:PartyName')->with(
                        E::text('cbc:Name', (string) $representative->get(BT::TaxRepresentativeName)),
                    ),
                    $this->address(
                        'cac:PostalAddress',
                        $representative->first(BG::TaxRepresentativePostalAddress),
                        [
                            BT::TaxRepresentativeAddressLine1, BT::TaxRepresentativeAddressLine2,
                            BT::TaxRepresentativeCity, BT::TaxRepresentativePostCode,
                            BT::TaxRepresentativeCountrySubdivision, BT::TaxRepresentativeCountryCode,
                        ],
                    ),
                    E::of('cac:PartyTaxScheme')->with(
                        E::text('cbc:CompanyID', (string) $representative->get(BT::TaxRepresentativeVatIdentifier)),
                        E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
                    ),
                ),
            ));
        }
    }

    private function delivery(E $root, SemanticInvoice $invoice): void
    {
        $delivery = $invoice->group(BG::DeliveryInformation);

        if ($delivery === null) {
            return;
        }

        $node = E::of('cac:Delivery')->with(
            $this->maybe('cbc:ActualDeliveryDate', $delivery->get(BT::ActualDeliveryDate)),
        );

        $address = $delivery->first(BG::DeliverToAddress);

        if ($address !== null || $delivery->has(BT::DeliverToLocationIdentifier)) {
            $node->with(E::of('cac:DeliveryLocation')->with(
                $this->maybe('cbc:ID', $delivery->get(BT::DeliverToLocationIdentifier)),
                $this->address('cac:Address', $address, [
                    BT::DeliverToAddressLine1, BT::DeliverToAddressLine2, BT::DeliverToCity,
                    BT::DeliverToPostCode, BT::DeliverToCountrySubdivision, BT::DeliverToCountryCode,
                ]),
            ));
        }

        if ($delivery->has(BT::DeliverToPartyName)) {
            $node->with(E::of('cac:DeliveryParty')->with(
                E::of('cac:PartyName')->with(E::text('cbc:Name', (string) $delivery->get(BT::DeliverToPartyName))),
            ));
        }

        $root->with($node);
    }

    private function payment(E $root, SemanticInvoice $invoice): void
    {
        $instructions = $invoice->group(BG::PaymentInstructions);

        if ($instructions !== null) {
            $means = E::of('cac:PaymentMeans')->with(
                E::text(
                    'cbc:PaymentMeansCode',
                    (string) $instructions->get(BT::PaymentMeansTypeCode),
                    ['listID' => 'UNCL4461'],
                ),
                $this->maybe('cbc:InstructionNote', $instructions->get(BT::PaymentMeansText)),
                $this->maybe('cbc:PaymentID', $instructions->get(BT::RemittanceInformation)),
            );

            foreach ($instructions->children(BG::CreditTransfer) as $transfer) {
                $means->with(E::of('cac:PayeeFinancialAccount')->with(
                    E::text('cbc:ID', (string) $transfer->get(BT::PaymentAccountIdentifier)),
                    $this->maybe('cbc:Name', $transfer->get(BT::PaymentAccountName)),
                    $transfer->has(BT::PaymentServiceProviderIdentifier)
                        ? E::of('cac:FinancialInstitutionBranch')->with(
                            E::text('cbc:ID', (string) $transfer->get(BT::PaymentServiceProviderIdentifier)),
                        )
                        : null,
                ));
            }

            foreach ($instructions->children(BG::DirectDebit) as $debit) {
                $means->with(E::of('cac:PaymentMandate')->with(
                    $this->maybe('cbc:ID', $debit->get(BT::MandateReferenceIdentifier)),
                    $debit->has(BT::DebitedAccountIdentifier)
                        ? E::of('cac:PayerFinancialAccount')->with(
                            E::text('cbc:ID', (string) $debit->get(BT::DebitedAccountIdentifier)),
                        )
                        : null,
                ));
            }

            $root->with($means);
        }

        if ($invoice->get(BT::PaymentTerms) !== null) {
            $root->with(E::of('cac:PaymentTerms')->with(
                E::text('cbc:Note', (string) $invoice->get(BT::PaymentTerms)),
            ));
        }
    }

    private function allowancesAndCharges(E $root, SemanticInvoice $invoice): void
    {
        $currency = $invoice->currency();

        foreach ($invoice->groups(BG::DocumentLevelAllowances) as $allowance) {
            $root->with($this->allowanceCharge(
                false,
                $currency,
                $allowance->get(BT::DocumentAllowanceAmount),
                $allowance->get(BT::DocumentAllowanceBaseAmount),
                $allowance->get(BT::DocumentAllowancePercentage),
                $allowance->get(BT::DocumentAllowanceReason),
                $allowance->get(BT::DocumentAllowanceReasonCode),
                $allowance->get(BT::DocumentAllowanceVatCategoryCode),
                $allowance->get(BT::DocumentAllowanceVatRate),
            ));
        }

        foreach ($invoice->groups(BG::DocumentLevelCharges) as $charge) {
            $root->with($this->allowanceCharge(
                true,
                $currency,
                $charge->get(BT::DocumentChargeAmount),
                $charge->get(BT::DocumentChargeBaseAmount),
                $charge->get(BT::DocumentChargePercentage),
                $charge->get(BT::DocumentChargeReason),
                $charge->get(BT::DocumentChargeReasonCode),
                $charge->get(BT::DocumentChargeVatCategoryCode),
                $charge->get(BT::DocumentChargeVatRate),
            ));
        }
    }

    private function allowanceCharge(
        bool $isCharge,
        string $currency,
        ?string $amount,
        ?string $base,
        ?string $percentage,
        ?string $reason,
        ?string $reasonCode,
        ?string $category,
        ?string $rate,
    ): E {
        return E::of('cac:AllowanceCharge')->with(
            E::text('cbc:ChargeIndicator', $isCharge ? 'true' : 'false'),
            $this->maybe('cbc:AllowanceChargeReasonCode', $reasonCode),
            $this->maybe('cbc:AllowanceChargeReason', $reason),
            $this->maybe('cbc:MultiplierFactorNumeric', $percentage),
            $amount === null ? null : E::text('cbc:Amount', $amount, ['currencyID' => $currency]),
            $base === null ? null : E::text('cbc:BaseAmount', $base, ['currencyID' => $currency]),
            $category === null ? null : E::of('cac:TaxCategory')->with(
                E::text('cbc:ID', $category, ['schemeID' => 'UNCL5305']),
                $this->maybe('cbc:Percent', $rate),
                E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
            ),
        );
    }

    /**
     * One `cac:TaxTotal` with one `cac:TaxSubtotal` per rate group.
     *
     * Per group, not per line. Tax summed from per-line amounts and tax
     * summed from per-group amounts differ by cents on any document with
     * more than a few lines, and `BR-CO-14` rejects the first shape.
     */
    private function taxTotal(E $root, SemanticInvoice $invoice): void
    {
        $currency = $invoice->currency();
        $totals = $invoice->totals();
        $groups = $invoice->vatBreakdown();

        if ($groups === [] && $totals?->get(BT::TotalVatAmount) === null) {
            return;
        }

        $total = E::of('cac:TaxTotal')->with(
            E::text('cbc:TaxAmount', (string) ($totals?->get(BT::TotalVatAmount) ?? '0'), ['currencyID' => $currency]),
        );

        foreach ($groups as $group) {
            $total->with(E::of('cac:TaxSubtotal')->with(
                E::text('cbc:TaxableAmount', (string) $group->get(BT::VatCategoryTaxableAmount), ['currencyID' => $currency]),
                E::text('cbc:TaxAmount', (string) $group->get(BT::VatCategoryTaxAmount), ['currencyID' => $currency]),
                E::of('cac:TaxCategory')->with(
                    E::text('cbc:ID', (string) $group->get(BT::VatCategoryCode), ['schemeID' => 'UNCL5305']),
                    $this->maybe('cbc:Percent', $group->get(BT::VatCategoryRate)),
                    $this->maybe('cbc:TaxExemptionReasonCode', $group->get(BT::VatExemptionReasonCode)),
                    $this->maybe('cbc:TaxExemptionReason', $group->get(BT::VatExemptionReasonText)),
                    E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
                ),
            ));
        }

        $root->with($total);
    }

    private function monetaryTotals(E $root, SemanticInvoice $invoice): void
    {
        $totals = $invoice->totals();

        if ($totals === null) {
            return;
        }

        $currency = $invoice->currency();
        $amount = fn (string $name, ?string $value): ?E => $value === null
            ? null
            : E::text($name, $value, ['currencyID' => $currency]);

        $root->with(E::of('cac:LegalMonetaryTotal')->with(
            $amount('cbc:LineExtensionAmount', $totals->get(BT::SumOfLineNetAmounts)),
            $amount('cbc:TaxExclusiveAmount', $totals->get(BT::TotalWithoutVat)),
            $amount('cbc:TaxInclusiveAmount', $totals->get(BT::TotalWithVat)),
            $amount('cbc:AllowanceTotalAmount', $totals->get(BT::SumOfAllowances)),
            $amount('cbc:ChargeTotalAmount', $totals->get(BT::SumOfCharges)),
            $amount('cbc:PrepaidAmount', $totals->get(BT::PaidAmount)),
            $amount('cbc:PayableRoundingAmount', $totals->get(BT::RoundingAmount)),
            $amount('cbc:PayableAmount', $totals->get(BT::AmountDueForPayment)),
        ));
    }

    private function lines(E $root, SemanticInvoice $invoice, bool $isCreditNote): void
    {
        $currency = $invoice->currency();

        foreach ($invoice->lines() as $line) {
            $node = E::of($isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine')->with(
                E::text('cbc:ID', (string) $line->get(BT::LineIdentifier)),
                $this->maybe('cbc:Note', $line->get(BT::LineNote)),
                E::text(
                    $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity',
                    (string) $line->get(BT::InvoicedQuantity),
                    ['unitCode' => $line->get(BT::InvoicedQuantityUnitCode)],
                ),
                E::text('cbc:LineExtensionAmount', (string) $line->get(BT::LineNetAmount), ['currencyID' => $currency]),
                $this->maybe('cbc:AccountingCost', $line->get(BT::LineBuyerAccountingReference)),
            );

            foreach ($line->children(BG::InvoiceLinePeriod) as $period) {
                $node->with(E::of('cac:InvoicePeriod')->with(
                    $this->maybe('cbc:StartDate', $period->get(BT::LinePeriodStartDate)),
                    $this->maybe('cbc:EndDate', $period->get(BT::LinePeriodEndDate)),
                ));
            }

            if ($line->has(BT::ReferencedPurchaseOrderLineReference)) {
                $node->with(E::of('cac:OrderLineReference')->with(
                    E::text('cbc:LineID', (string) $line->get(BT::ReferencedPurchaseOrderLineReference)),
                ));
            }

            foreach ($line->children(BG::InvoiceLineAllowances) as $allowance) {
                $node->with($this->allowanceCharge(
                    false,
                    $currency,
                    $allowance->get(BT::LineAllowanceAmount),
                    $allowance->get(BT::LineAllowanceBaseAmount),
                    $allowance->get(BT::LineAllowancePercentage),
                    $allowance->get(BT::LineAllowanceReason),
                    $allowance->get(BT::LineAllowanceReasonCode),
                    null,
                    null,
                ));
            }

            foreach ($line->children(BG::InvoiceLineCharges) as $charge) {
                $node->with($this->allowanceCharge(
                    true,
                    $currency,
                    $charge->get(BT::LineChargeAmount),
                    $charge->get(BT::LineChargeBaseAmount),
                    $charge->get(BT::LineChargePercentage),
                    $charge->get(BT::LineChargeReason),
                    $charge->get(BT::LineChargeReasonCode),
                    null,
                    null,
                ));
            }

            $node->with($this->item($line));
            $node->with($this->price($line, $currency));

            $root->with($node);
        }
    }

    private function item(SemanticNode $line): E
    {
        $item = $line->first(BG::ItemInformation);
        $vat = $line->first(BG::LineVatInformation);

        $node = E::of('cac:Item')->with(
            $this->maybe('cbc:Description', $item?->get(BT::ItemDescription)),
            E::text('cbc:Name', (string) $item?->get(BT::ItemName)),
            $item?->has(BT::ItemBuyerIdentifier) === true
                ? E::of('cac:BuyersItemIdentification')->with(
                    E::text('cbc:ID', (string) $item->get(BT::ItemBuyerIdentifier)),
                )
                : null,
            $item?->has(BT::ItemSellerIdentifier) === true
                ? E::of('cac:SellersItemIdentification')->with(
                    E::text('cbc:ID', (string) $item->get(BT::ItemSellerIdentifier)),
                )
                : null,
            $item?->has(BT::ItemStandardIdentifier) === true
                ? E::of('cac:StandardItemIdentification')->with(
                    E::text(
                        'cbc:ID',
                        (string) $item->get(BT::ItemStandardIdentifier),
                        ['schemeID' => $item->qualifier(BT::ItemStandardIdentifier)],
                    ),
                )
                : null,
            $item?->has(BT::ItemCountryOfOrigin) === true
                ? E::of('cac:OriginCountry')->with(
                    E::text('cbc:IdentificationCode', (string) $item->get(BT::ItemCountryOfOrigin)),
                )
                : null,
        );

        foreach ($item?->all(BT::ItemClassificationIdentifier) ?? [] as $ordinal => $classification) {
            $node->with(E::of('cac:CommodityClassification')->with(
                E::text(
                    'cbc:ItemClassificationCode',
                    $classification,
                    ['listID' => $item?->qualifier(BT::ItemClassificationIdentifier, $ordinal)],
                ),
            ));
        }

        if ($vat !== null) {
            $node->with(E::of('cac:ClassifiedTaxCategory')->with(
                E::text('cbc:ID', (string) $vat->get(BT::LineVatCategoryCode), ['schemeID' => 'UNCL5305']),
                $this->maybe('cbc:Percent', $vat->get(BT::LineVatRate)),
                E::of('cac:TaxScheme')->with(E::text('cbc:ID', 'VAT')),
            ));
        }

        foreach ($item?->children(BG::ItemAttributes) ?? [] as $attribute) {
            $node->with(E::of('cac:AdditionalItemProperty')->with(
                E::text('cbc:Name', (string) $attribute->get(BT::ItemAttributeName)),
                E::text('cbc:Value', (string) $attribute->get(BT::ItemAttributeValue)),
            ));
        }

        return $node;
    }

    private function price(SemanticNode $line, string $currency): E
    {
        $price = $line->first(BG::PriceDetails);

        $node = E::of('cac:Price')->with(
            E::text('cbc:PriceAmount', (string) $price?->get(BT::ItemNetPrice), ['currencyID' => $currency]),
            $price?->has(BT::ItemPriceBaseQuantity) === true
                ? E::text(
                    'cbc:BaseQuantity',
                    (string) $price->get(BT::ItemPriceBaseQuantity),
                    ['unitCode' => $price->get(BT::ItemPriceBaseQuantityUnitCode)],
                )
                : null,
        );

        /*
         | The price discount is an allowance on the price - BT-147/BT-148.
         |
         | UBL has no "discount" element on a price: the gross price is the
         | base amount and the discount is an allowance against it, which is
         | how a recipient recomputes BT-146 and checks it.
         */
        if ($price?->has(BT::ItemPriceDiscount) === true) {
            $node->with(E::of('cac:AllowanceCharge')->with(
                E::text('cbc:ChargeIndicator', 'false'),
                E::text('cbc:Amount', (string) $price->get(BT::ItemPriceDiscount), ['currencyID' => $currency]),
                $price->has(BT::ItemGrossPrice)
                    ? E::text('cbc:BaseAmount', (string) $price->get(BT::ItemGrossPrice), ['currencyID' => $currency])
                    : null,
            ));
        }

        return $node;
    }

    /**
     * An address group, in whichever UBL element holds it.
     *
     * @param  array{0: BT, 1: BT, 2: BT, 3: BT, 4: BT, 5: BT}  $terms
     */
    private function address(string $name, ?SemanticNode $node, array $terms): ?E
    {
        if ($node === null) {
            return null;
        }

        [$line1, $line2, $city, $postCode, $subdivision, $country] = $terms;

        return E::of($name)->with(
            $this->maybe('cbc:StreetName', $node->get($line1)),
            $this->maybe('cbc:AdditionalStreetName', $node->get($line2)),
            $this->maybe('cbc:CityName', $node->get($city)),
            $this->maybe('cbc:PostalZone', $node->get($postCode)),
            $this->maybe('cbc:CountrySubentity', $node->get($subdivision)),
            $node->get($country) === null
                ? null
                : E::of('cac:Country')->with(
                    E::text('cbc:IdentificationCode', (string) $node->get($country)),
                ),
        );
    }

    private function maybe(string $name, ?string $value): ?E
    {
        return $value === null || trim($value) === '' ? null : E::text($name, $value);
    }
}
