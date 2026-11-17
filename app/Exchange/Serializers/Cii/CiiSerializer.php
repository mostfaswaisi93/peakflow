<?php

declare(strict_types=1);

namespace App\Exchange\Serializers\Cii;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Serializers\DocumentSerializer;
use App\Support\Xml\Element as E;
use App\Support\Xml\XmlWriter;

/**
 * UN/CEFACT CII D16B, from the same model - PF-113.
 *
 * ## It shares nothing with the UBL serializer but the model
 *
 * No helper, no base class, no shared "emit a party" method. That is the
 * point: if the two could share their structure, the model would not be
 * syntax-independent and the claim this phase rests on would be untested.
 *
 * The differences are real and not cosmetic:
 *
 * - A **credit note is not a different document.** CII has one root,
 *   `rsm:CrossIndustryInvoice`, and the type code says 381. UBL has a
 *   separate root, a separate line element, a separate quantity element and
 *   a different element order.
 * - The **tax point date** is document-level in UBL and sits inside each
 *   `ram:ApplicableTradeTax` here.
 * - **Dates** are `20260518` inside `udt:DateTimeString format="102"`, not
 *   `2026-05-18`.
 * - A **boolean** is a `udt:Indicator` element, not the text `true`.
 * - A **line** is split into agreement, delivery and settlement, where UBL
 *   keeps the price, the quantity and the tax beside each other.
 * - **Totals** are one `MonetarySummation` group in a fixed order where
 *   UBL's `LegalMonetaryTotal` has its own.
 *
 * ## The one thing both must agree on
 *
 * The semantic content. A document serialized to both syntaxes and read back
 * yields the same business terms with the same values, which is what the
 * `Exchange` checks assert through independently written path expressions
 * for each syntax.
 */
final class CiiSerializer implements DocumentSerializer
{
    private const NAMESPACES = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
    ];

    /** UN/CEFACT date format 102: `CCYYMMDD`. */
    private const DATE_FORMAT = '102';

    public function syntax(): string
    {
        return 'cii';
    }

    public function mediaType(): string
    {
        return 'application/xml';
    }

    public function serialize(SemanticInvoice $invoice): string
    {
        $root = E::of('rsm:CrossIndustryInvoice')->with(
            $this->context($invoice),
            $this->document($invoice),
            $this->transaction($invoice),
        );

        return (new XmlWriter(CiiSequences::all()))->render($root, self::NAMESPACES);
    }

    private function context(SemanticInvoice $invoice): E
    {
        $control = $invoice->group(BG::ProcessControl);

        return E::of('rsm:ExchangedDocumentContext')->with(
            $control?->has(BT::BusinessProcessType) === true
                ? E::of('ram:BusinessProcessSpecifiedDocumentContextParameter')->with(
                    E::text('ram:ID', (string) $control->get(BT::BusinessProcessType)),
                )
                : null,
            E::of('ram:GuidelineSpecifiedDocumentContextParameter')->with(
                E::text('ram:ID', (string) $control?->get(BT::SpecificationIdentifier)),
            ),
        );
    }

    private function document(SemanticInvoice $invoice): E
    {
        $node = E::of('rsm:ExchangedDocument')->with(
            E::text('ram:ID', (string) $invoice->get(BT::InvoiceNumber)),
            E::text('ram:TypeCode', (string) $invoice->get(BT::InvoiceTypeCode)),
            $this->dateTime('ram:IssueDateTime', $invoice->get(BT::IssueDate)),
        );

        foreach ($invoice->groups(BG::InvoiceNote) as $note) {
            if (! $note->has(BT::InvoiceNote)) {
                continue;
            }

            /*
             | The subject code is its own element here.
             |
             | UBL folds it into the note text as `#code#text`; CII has
             | `ram:SubjectCode` beside `ram:Content`. The same two business
             | terms, carried two incompatible ways - which is why they are
             | two terms in the model and not one preformatted string.
             */
            $node->with(E::of('ram:IncludedNote')->with(
                E::text('ram:Content', (string) $note->get(BT::InvoiceNote)),
                $note->has(BT::InvoiceNoteSubjectCode)
                    ? E::text('ram:SubjectCode', (string) $note->get(BT::InvoiceNoteSubjectCode))
                    : null,
            ));
        }

        return $node;
    }

    private function transaction(SemanticInvoice $invoice): E
    {
        $node = E::of('rsm:SupplyChainTradeTransaction');

        foreach ($invoice->lines() as $line) {
            $node->with($this->line($line, $invoice->currency()));
        }

        return $node->with(
            $this->agreement($invoice),
            $this->delivery($invoice),
            $this->settlement($invoice),
        );
    }

    private function line(SemanticNode $line, string $currency): E
    {
        $item = $line->first(BG::ItemInformation);
        $price = $line->first(BG::PriceDetails);
        $vat = $line->first(BG::LineVatInformation);

        $product = E::of('ram:SpecifiedTradeProduct')->with(
            $item?->has(BT::ItemStandardIdentifier) === true
                ? E::text(
                    'ram:GlobalID',
                    (string) $item->get(BT::ItemStandardIdentifier),
                    ['schemeID' => $item->qualifier(BT::ItemStandardIdentifier)],
                )
                : null,
            $this->maybe('ram:SellerAssignedID', $item?->get(BT::ItemSellerIdentifier)),
            $this->maybe('ram:BuyerAssignedID', $item?->get(BT::ItemBuyerIdentifier)),
            E::text('ram:Name', (string) $item?->get(BT::ItemName)),
            $this->maybe('ram:Description', $item?->get(BT::ItemDescription)),
        );

        foreach ($item?->children(BG::ItemAttributes) ?? [] as $attribute) {
            $product->with(E::of('ram:ApplicableProductCharacteristic')->with(
                E::text('ram:Description', (string) $attribute->get(BT::ItemAttributeName)),
                E::text('ram:Value', (string) $attribute->get(BT::ItemAttributeValue)),
            ));
        }

        foreach ($item?->all(BT::ItemClassificationIdentifier) ?? [] as $ordinal => $classification) {
            $product->with(E::of('ram:DesignatedProductClassification')->with(
                E::text(
                    'ram:ClassCode',
                    $classification,
                    ['listID' => $item?->qualifier(BT::ItemClassificationIdentifier, $ordinal)],
                ),
            ));
        }

        if ($item?->has(BT::ItemCountryOfOrigin) === true) {
            $product->with(E::of('ram:OriginTradeCountry')->with(
                E::text('ram:ID', (string) $item->get(BT::ItemCountryOfOrigin)),
            ));
        }

        $agreement = E::of('ram:SpecifiedLineTradeAgreement')->with(
            $line->has(BT::ReferencedPurchaseOrderLineReference)
                ? E::of('ram:BuyerOrderReferencedDocument')->with(
                    E::text('ram:LineID', (string) $line->get(BT::ReferencedPurchaseOrderLineReference)),
                )
                : null,
            $price?->has(BT::ItemGrossPrice) === true
                ? E::of('ram:GrossPriceProductTradePrice')->with(
                    E::text('ram:ChargeAmount', (string) $price->get(BT::ItemGrossPrice)),
                    $price->has(BT::ItemPriceDiscount)
                        ? E::of('ram:AppliedTradeAllowanceCharge')->with(
                            E::of('ram:ChargeIndicator')->with(E::text('udt:Indicator', 'false')),
                            E::text('ram:ActualAmount', (string) $price->get(BT::ItemPriceDiscount)),
                        )
                        : null,
                )
                : null,
            E::of('ram:NetPriceProductTradePrice')->with(
                E::text('ram:ChargeAmount', (string) $price?->get(BT::ItemNetPrice)),
                $price?->has(BT::ItemPriceBaseQuantity) === true
                    ? E::text(
                        'ram:BasisQuantity',
                        (string) $price->get(BT::ItemPriceBaseQuantity),
                        ['unitCode' => $price->get(BT::ItemPriceBaseQuantityUnitCode)],
                    )
                    : null,
            ),
        );

        $settlement = E::of('ram:SpecifiedLineTradeSettlement')->with(
            $vat === null ? null : E::of('ram:ApplicableTradeTax')->with(
                E::text('ram:TypeCode', 'VAT'),
                E::text('ram:CategoryCode', (string) $vat->get(BT::LineVatCategoryCode)),
                $this->maybe('ram:RateApplicablePercent', $vat->get(BT::LineVatRate)),
            ),
        );

        foreach ($line->children(BG::InvoiceLinePeriod) as $period) {
            $settlement->with(E::of('ram:BillingSpecifiedPeriod')->with(
                $this->dateTime('ram:StartDateTime', $period->get(BT::LinePeriodStartDate)),
                $this->dateTime('ram:EndDateTime', $period->get(BT::LinePeriodEndDate)),
            ));
        }

        foreach ($line->children(BG::InvoiceLineAllowances) as $allowance) {
            $settlement->with($this->tradeAllowanceCharge(
                false,
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
            $settlement->with($this->tradeAllowanceCharge(
                true,
                $charge->get(BT::LineChargeAmount),
                $charge->get(BT::LineChargeBaseAmount),
                $charge->get(BT::LineChargePercentage),
                $charge->get(BT::LineChargeReason),
                $charge->get(BT::LineChargeReasonCode),
                null,
                null,
            ));
        }

        $settlement->with(
            E::of('ram:SpecifiedTradeSettlementLineMonetarySummation')->with(
                E::text('ram:LineTotalAmount', (string) $line->get(BT::LineNetAmount)),
            ),
            $line->has(BT::LineBuyerAccountingReference)
                ? E::of('ram:ReceivableSpecifiedTradeAccountingAccount')->with(
                    E::text('ram:ID', (string) $line->get(BT::LineBuyerAccountingReference)),
                )
                : null,
        );

        return E::of('ram:IncludedSupplyChainTradeLineItem')->with(
            E::of('ram:AssociatedDocumentLineDocument')->with(
                E::text('ram:LineID', (string) $line->get(BT::LineIdentifier)),
                $line->has(BT::LineNote)
                    ? E::of('ram:IncludedNote')->with(E::text('ram:Content', (string) $line->get(BT::LineNote)))
                    : null,
            ),
            $product,
            $agreement,
            E::of('ram:SpecifiedLineTradeDelivery')->with(
                E::text(
                    'ram:BilledQuantity',
                    (string) $line->get(BT::InvoicedQuantity),
                    ['unitCode' => $line->get(BT::InvoicedQuantityUnitCode)],
                ),
            ),
            $settlement,
        );
    }

    private function agreement(SemanticInvoice $invoice): E
    {
        $node = E::of('ram:ApplicableHeaderTradeAgreement')->with(
            $this->maybe('ram:BuyerReference', $invoice->get(BT::BuyerReference)),
            $this->party('ram:SellerTradeParty', $invoice->group(BG::Seller), [
                'name' => BT::SellerName,
                'trading_name' => BT::SellerTradingName,
                'legal_id' => BT::SellerLegalRegistrationIdentifier,
                'ids' => BT::SellerIdentifier,
                'vat' => BT::SellerVatIdentifier,
                'other_tax' => BT::SellerTaxRegistrationIdentifier,
                'endpoint' => BT::SellerElectronicAddress,
                'description' => BT::SellerAdditionalLegalInformation,
                'address' => BG::SellerPostalAddress,
                'address_terms' => [
                    BT::SellerAddressLine1, BT::SellerAddressLine2, BT::SellerCity,
                    BT::SellerPostCode, BT::SellerCountrySubdivision, BT::SellerCountryCode,
                ],
                'contact' => BG::SellerContact,
                'contact_terms' => [BT::SellerContactPoint, BT::SellerContactTelephone, BT::SellerContactEmail],
            ]),
            $this->party('ram:BuyerTradeParty', $invoice->group(BG::Buyer), [
                'name' => BT::BuyerName,
                'trading_name' => BT::BuyerTradingName,
                'legal_id' => BT::BuyerLegalRegistrationIdentifier,
                'id' => BT::BuyerIdentifier,
                'vat' => BT::BuyerVatIdentifier,
                'endpoint' => BT::BuyerElectronicAddress,
                'address' => BG::BuyerPostalAddress,
                'address_terms' => [
                    BT::BuyerAddressLine1, BT::BuyerAddressLine2, BT::BuyerCity,
                    BT::BuyerPostCode, BT::BuyerCountrySubdivision, BT::BuyerCountryCode,
                ],
                'contact' => BG::BuyerContact,
                'contact_terms' => [BT::BuyerContactPoint, BT::BuyerContactTelephone, BT::BuyerContactEmail],
            ]),
        );

        if ($invoice->get(BT::PurchaseOrderReference) !== null) {
            $node->with(E::of('ram:BuyerOrderReferencedDocument')->with(
                E::text('ram:IssuerAssignedID', (string) $invoice->get(BT::PurchaseOrderReference)),
            ));
        }

        if ($invoice->get(BT::ContractReference) !== null) {
            $node->with(E::of('ram:ContractReferencedDocument')->with(
                E::text('ram:IssuerAssignedID', (string) $invoice->get(BT::ContractReference)),
            ));
        }

        foreach ($invoice->groups(BG::AdditionalSupportingDocuments) as $document) {
            $node->with(E::of('ram:AdditionalReferencedDocument')->with(
                E::text('ram:IssuerAssignedID', (string) $document->get(BT::SupportingDocumentReference)),
                $this->maybe('ram:URIID', $document->get(BT::ExternalDocumentLocation)),
                E::text('ram:TypeCode', '916'),
                $this->maybe('ram:Name', $document->get(BT::SupportingDocumentDescription)),
            ));
        }

        if ($invoice->get(BT::ProjectReference) !== null) {
            $node->with(E::of('ram:SpecifiedProcuringProject')->with(
                E::text('ram:ID', (string) $invoice->get(BT::ProjectReference)),
                E::text('ram:Name', (string) $invoice->get(BT::ProjectReference)),
            ));
        }

        $representative = $invoice->group(BG::SellerTaxRepresentativeParty);

        if ($representative !== null) {
            $node->with($this->party('ram:SellerTaxRepresentativeTradeParty', $representative, [
                'name' => BT::TaxRepresentativeName,
                'vat' => BT::TaxRepresentativeVatIdentifier,
                'address' => BG::TaxRepresentativePostalAddress,
                'address_terms' => [
                    BT::TaxRepresentativeAddressLine1, BT::TaxRepresentativeAddressLine2,
                    BT::TaxRepresentativeCity, BT::TaxRepresentativePostCode,
                    BT::TaxRepresentativeCountrySubdivision, BT::TaxRepresentativeCountryCode,
                ],
            ]));
        }

        return $node;
    }

    private function delivery(SemanticInvoice $invoice): E
    {
        $node = E::of('ram:ApplicableHeaderTradeDelivery');
        $delivery = $invoice->group(BG::DeliveryInformation);

        if ($delivery === null) {
            return $node;
        }

        $address = $delivery->first(BG::DeliverToAddress);

        if ($address !== null || $delivery->has(BT::DeliverToPartyName) || $delivery->has(BT::DeliverToLocationIdentifier)) {
            $node->with(E::of('ram:ShipToTradeParty')->with(
                $this->maybe('ram:ID', $delivery->get(BT::DeliverToLocationIdentifier)),
                $this->maybe('ram:Name', $delivery->get(BT::DeliverToPartyName)),
                $address === null ? null : $this->address($address, [
                    BT::DeliverToAddressLine1, BT::DeliverToAddressLine2, BT::DeliverToCity,
                    BT::DeliverToPostCode, BT::DeliverToCountrySubdivision, BT::DeliverToCountryCode,
                ]),
            ));
        }

        if ($delivery->has(BT::ActualDeliveryDate)) {
            $node->with(E::of('ram:ActualDeliverySupplyChainEvent')->with(
                $this->dateTime('ram:OccurrenceDateTime', $delivery->get(BT::ActualDeliveryDate)),
            ));
        }

        return $node;
    }

    private function settlement(SemanticInvoice $invoice): E
    {
        $totals = $invoice->totals();
        $currency = $invoice->currency();

        $node = E::of('ram:ApplicableHeaderTradeSettlement')->with(
            $this->maybe('ram:PaymentReference', $invoice->group(BG::PaymentInstructions)?->get(BT::RemittanceInformation)),
            $this->maybe('ram:TaxCurrencyCode', $invoice->get(BT::VatAccountingCurrencyCode)),
            E::text('ram:InvoiceCurrencyCode', $currency),
        );

        $payee = $invoice->group(BG::Payee);

        if ($payee !== null) {
            $node->with(E::of('ram:PayeeTradeParty')->with(
                $this->maybe('ram:ID', $payee->get(BT::PayeeIdentifier)),
                $payee->has(BT::PayeeLegalRegistrationIdentifier)
                    ? E::of('ram:SpecifiedLegalOrganization')->with(
                        E::text('ram:ID', (string) $payee->get(BT::PayeeLegalRegistrationIdentifier)),
                    )
                    : null,
                E::text('ram:Name', (string) $payee->get(BT::PayeeName)),
            ));
        }

        $instructions = $invoice->group(BG::PaymentInstructions);

        if ($instructions !== null) {
            $means = E::of('ram:SpecifiedTradeSettlementPaymentMeans')->with(
                E::text('ram:TypeCode', (string) $instructions->get(BT::PaymentMeansTypeCode)),
                $this->maybe('ram:Information', $instructions->get(BT::PaymentMeansText)),
            );

            foreach ($instructions->children(BG::DirectDebit) as $debit) {
                if ($debit->has(BT::DebitedAccountIdentifier)) {
                    $means->with(E::of('ram:PayerPartyDebtorFinancialAccount')->with(
                        E::text('ram:IBANID', (string) $debit->get(BT::DebitedAccountIdentifier)),
                    ));
                }
            }

            foreach ($instructions->children(BG::CreditTransfer) as $transfer) {
                $means->with(E::of('ram:PayeePartyCreditorFinancialAccount')->with(
                    E::text('ram:IBANID', (string) $transfer->get(BT::PaymentAccountIdentifier)),
                    $this->maybe('ram:AccountName', $transfer->get(BT::PaymentAccountName)),
                ));

                if ($transfer->has(BT::PaymentServiceProviderIdentifier)) {
                    $means->with(E::of('ram:PayeeSpecifiedCreditorFinancialInstitution')->with(
                        E::text('ram:BICID', (string) $transfer->get(BT::PaymentServiceProviderIdentifier)),
                    ));
                }
            }

            $node->with($means);
        }

        foreach ($invoice->vatBreakdown() as $group) {
            $node->with(E::of('ram:ApplicableTradeTax')->with(
                E::text('ram:CalculatedAmount', (string) $group->get(BT::VatCategoryTaxAmount)),
                E::text('ram:TypeCode', 'VAT'),
                $this->maybe('ram:ExemptionReason', $group->get(BT::VatExemptionReasonText)),
                E::text('ram:BasisAmount', (string) $group->get(BT::VatCategoryTaxableAmount)),
                E::text('ram:CategoryCode', (string) $group->get(BT::VatCategoryCode)),
                $this->maybe('ram:ExemptionReasonCode', $group->get(BT::VatExemptionReasonCode)),

                /*
                 | The tax point, repeated on every group.
                 |
                 | CII has no document-level element for it. It is per trade
                 | tax here, which also means a document whose groups have
                 | different tax points is expressible in CII and not in UBL -
                 | the model holds one, so every group carries the same value.
                 */
                $this->date('ram:TaxPointDate', $invoice->get(BT::TaxPointDate)),
                $this->maybe('ram:DueDateTypeCode', $invoice->get(BT::TaxPointDateCode)),
                $this->maybe('ram:RateApplicablePercent', $group->get(BT::VatCategoryRate)),
            ));
        }

        foreach ($invoice->groups(BG::InvoicingPeriod) as $period) {
            $node->with(E::of('ram:BillingSpecifiedPeriod')->with(
                $this->dateTime('ram:StartDateTime', $period->get(BT::InvoicingPeriodStartDate)),
                $this->dateTime('ram:EndDateTime', $period->get(BT::InvoicingPeriodEndDate)),
            ));
        }

        foreach ($invoice->groups(BG::DocumentLevelAllowances) as $allowance) {
            $node->with($this->tradeAllowanceCharge(
                false,
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
            $node->with($this->tradeAllowanceCharge(
                true,
                $charge->get(BT::DocumentChargeAmount),
                $charge->get(BT::DocumentChargeBaseAmount),
                $charge->get(BT::DocumentChargePercentage),
                $charge->get(BT::DocumentChargeReason),
                $charge->get(BT::DocumentChargeReasonCode),
                $charge->get(BT::DocumentChargeVatCategoryCode),
                $charge->get(BT::DocumentChargeVatRate),
            ));
        }

        if ($invoice->get(BT::PaymentTerms) !== null || $invoice->get(BT::PaymentDueDate) !== null) {
            $node->with(E::of('ram:SpecifiedTradePaymentTerms')->with(
                $this->maybe('ram:Description', $invoice->get(BT::PaymentTerms)),
                $this->dateTime('ram:DueDateDateTime', $invoice->get(BT::PaymentDueDate)),
            ));
        }

        if ($totals !== null) {
            $node->with(E::of('ram:SpecifiedTradeSettlementHeaderMonetarySummation')->with(
                E::text('ram:LineTotalAmount', (string) $totals->get(BT::SumOfLineNetAmounts)),
                $this->maybe('ram:ChargeTotalAmount', $totals->get(BT::SumOfCharges)),
                $this->maybe('ram:AllowanceTotalAmount', $totals->get(BT::SumOfAllowances)),
                E::text('ram:TaxBasisTotalAmount', (string) $totals->get(BT::TotalWithoutVat)),
                $totals->has(BT::TotalVatAmount)
                    ? E::text(
                        'ram:TaxTotalAmount',
                        (string) $totals->get(BT::TotalVatAmount),
                        ['currencyID' => $currency],
                    )
                    : null,
                $this->maybe('ram:RoundingAmount', $totals->get(BT::RoundingAmount)),
                E::text('ram:GrandTotalAmount', (string) $totals->get(BT::TotalWithVat)),
                $this->maybe('ram:TotalPrepaidAmount', $totals->get(BT::PaidAmount)),
                E::text('ram:DuePayableAmount', (string) $totals->get(BT::AmountDueForPayment)),
            ));
        }

        foreach ($invoice->groups(BG::PrecedingInvoiceReference) as $preceding) {
            $node->with(E::of('ram:InvoiceReferencedDocument')->with(
                E::text('ram:IssuerAssignedID', (string) $preceding->get(BT::PrecedingInvoiceReference)),
                $preceding->has(BT::PrecedingInvoiceIssueDate)
                    ? E::of('ram:FormattedIssueDateTime')->with(
                        E::text(
                            'qdt:DateTimeString',
                            $this->compact((string) $preceding->get(BT::PrecedingInvoiceIssueDate)),
                            ['format' => self::DATE_FORMAT],
                        ),
                    )
                    : null,
            ));
        }

        if ($invoice->get(BT::BuyerAccountingReference) !== null) {
            $node->with(E::of('ram:ReceivableSpecifiedTradeAccountingAccount')->with(
                E::text('ram:ID', (string) $invoice->get(BT::BuyerAccountingReference)),
            ));
        }

        return $node;
    }

    /**
     * A trade party, in whichever role.
     *
     * @param  array<string, mixed>  $terms
     */
    private function party(string $name, ?SemanticNode $node, array $terms): ?E
    {
        if ($node === null) {
            return null;
        }

        $party = E::of($name);

        if (isset($terms['id']) && $node->has($terms['id'])) {
            $party->with(E::text(
                'ram:ID',
                (string) $node->get($terms['id']),
                ['schemeID' => $node->qualifier($terms['id'])],
            ));
        }

        if (isset($terms['ids'])) {
            foreach ($node->all($terms['ids']) as $ordinal => $identifier) {
                $party->with(E::text(
                    'ram:ID',
                    $identifier,
                    ['schemeID' => $node->qualifier($terms['ids'], $ordinal)],
                ));
            }
        }

        if ((isset($terms['legal_id']) && $node->has($terms['legal_id']))
            || (isset($terms['trading_name']) && $node->has($terms['trading_name']))) {
            $party->with(E::of('ram:SpecifiedLegalOrganization')->with(
                isset($terms['legal_id']) && $node->has($terms['legal_id'])
                    ? E::text('ram:ID', (string) $node->get($terms['legal_id']))
                    : null,
                isset($terms['trading_name']) && $node->has($terms['trading_name'])
                    ? E::text('ram:TradingBusinessName', (string) $node->get($terms['trading_name']))
                    : null,
            ));
        }

        $party->with(
            E::text('ram:Name', (string) $node->get($terms['name'])),
            isset($terms['description']) && $node->has($terms['description'])
                ? E::text('ram:Description', (string) $node->get($terms['description']))
                : null,
        );

        if (isset($terms['contact'])) {
            $contact = $node->first($terms['contact']);

            if ($contact !== null) {
                [$person, $telephone, $email] = $terms['contact_terms'];

                $party->with(E::of('ram:DefinedTradeContact')->with(
                    $this->maybe('ram:PersonName', $contact->get($person)),
                    $contact->has($telephone)
                        ? E::of('ram:TelephoneUniversalCommunication')->with(
                            E::text('ram:CompleteNumber', (string) $contact->get($telephone)),
                        )
                        : null,
                    $contact->has($email)
                        ? E::of('ram:EmailURIUniversalCommunication')->with(
                            E::text('ram:URIID', (string) $contact->get($email)),
                        )
                        : null,
                ));
            }
        }

        $address = $node->first($terms['address']);

        if ($address !== null) {
            $party->with($this->address($address, $terms['address_terms']));
        }

        if (isset($terms['endpoint']) && $node->has($terms['endpoint'])) {
            $party->with(E::of('ram:URIUniversalCommunication')->with(
                E::text(
                    'ram:URIID',
                    (string) $node->get($terms['endpoint']),
                    ['schemeID' => $node->qualifier($terms['endpoint'])],
                ),
            ));
        }

        /*
         | The scheme on the tax registration is `VA`, not `VAT`.
         |
         | CII qualifies the registration with UNCL 5153: `VA` for value
         | added tax, `FC` for a fiscal code. UBL says `VAT` in a
         | `cac:TaxScheme`. Same business term, two code lists.
         */
        if (isset($terms['vat']) && $node->has($terms['vat'])) {
            $party->with(E::of('ram:SpecifiedTaxRegistration')->with(
                E::text('ram:ID', (string) $node->get($terms['vat']), ['schemeID' => 'VA']),
            ));
        }

        if (isset($terms['other_tax']) && $node->has($terms['other_tax'])) {
            $party->with(E::of('ram:SpecifiedTaxRegistration')->with(
                E::text('ram:ID', (string) $node->get($terms['other_tax']), ['schemeID' => 'FC']),
            ));
        }

        return $party;
    }

    /** @param array{0: BT, 1: BT, 2: BT, 3: BT, 4: BT, 5: BT} $terms */
    private function address(SemanticNode $node, array $terms): E
    {
        [$line1, $line2, $city, $postCode, $subdivision, $country] = $terms;

        return E::of('ram:PostalTradeAddress')->with(
            $this->maybe('ram:PostcodeCode', $node->get($postCode)),
            $this->maybe('ram:LineOne', $node->get($line1)),
            $this->maybe('ram:LineTwo', $node->get($line2)),
            $this->maybe('ram:CityName', $node->get($city)),
            $this->maybe('ram:CountryID', $node->get($country)),
            $this->maybe('ram:CountrySubDivisionName', $node->get($subdivision)),
        );
    }

    private function tradeAllowanceCharge(
        bool $isCharge,
        ?string $amount,
        ?string $base,
        ?string $percentage,
        ?string $reason,
        ?string $reasonCode,
        ?string $category,
        ?string $rate,
    ): E {
        return E::of('ram:SpecifiedTradeAllowanceCharge')->with(
            E::of('ram:ChargeIndicator')->with(E::text('udt:Indicator', $isCharge ? 'true' : 'false')),
            $this->maybe('ram:CalculationPercent', $percentage),
            $this->maybe('ram:BasisAmount', $base),
            $amount === null ? null : E::text('ram:ActualAmount', $amount),
            $this->maybe('ram:ReasonCode', $reasonCode),
            $this->maybe('ram:Reason', $reason),
            $category === null ? null : E::of('ram:CategoryTradeTax')->with(
                E::text('ram:TypeCode', 'VAT'),
                E::text('ram:CategoryCode', $category),
                $this->maybe('ram:RateApplicablePercent', $rate),
            ),
        );
    }

    /**
     * A plain date - `ram:TaxPointDate` and the other `udt:DateType` fields.
     *
     * `udt:DateString`, not `udt:DateTimeString`. CII has both, and they are
     * not interchangeable: a `DateType` element whose child is a
     * `DateTimeString` fails schema validation. Measured - the first version
     * of this serializer emitted the ISO date as the element's own text,
     * which is a third wrong answer.
     */
    private function date(string $name, ?string $date): ?E
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return E::of($name)->with(E::text(
            'udt:DateString',
            $this->compact($date),
            ['format' => self::DATE_FORMAT],
        ));
    }

    /** A date-time, in the only form CII accepts: `20260518` with its format code. */
    private function dateTime(string $name, ?string $date): ?E
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return E::of($name)->with(E::text(
            'udt:DateTimeString',
            $this->compact($date),
            ['format' => self::DATE_FORMAT],
        ));
    }

    private function compact(string $date): string
    {
        return str_replace('-', '', substr(trim($date), 0, 10));
    }

    private function maybe(string $name, ?string $value): ?E
    {
        return $value === null || trim($value) === '' ? null : E::text($name, $value);
    }
}
