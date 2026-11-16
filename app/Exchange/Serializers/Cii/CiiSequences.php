<?php

declare(strict_types=1);

namespace App\Exchange\Serializers\Cii;

/**
 * The CII D16B element sequences, as data - PF-113.
 *
 * Same reasoning as the UBL table: every CII complex type is a sequence, so
 * the order is part of the contract. Written from UN/CEFACT D16B and the
 * EN 16931 CII binding.
 *
 * ## How different CII really is
 *
 * The two syntaxes are not renamings of each other, which is the point of
 * holding one semantic model:
 *
 * - The **tax point date** is a document-level element in UBL and sits
 *   inside each `ram:ApplicableTradeTax` in CII.
 * - **Dates** are `2026-05-18` in UBL and `20260518` inside a
 *   `udt:DateTimeString format="102"` in CII.
 * - A **boolean** is the text `true` in UBL and a `udt:Indicator` element in
 *   CII.
 * - There is **no credit note document** in CII: a credit note is a
 *   `CrossIndustryInvoice` whose type code is 381, where UBL has a separate
 *   root element, separate line element and a different element order.
 * - The line is split into **agreement, delivery and settlement** sections
 *   where UBL keeps price, quantity and tax beside each other.
 *
 * A serializer that could be written by renaming the other one would not be
 * evidence of anything.
 */
final class CiiSequences
{
    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'rsm:CrossIndustryInvoice' => [
                'rsm:ExchangedDocumentContext',
                'rsm:ExchangedDocument',
                'rsm:SupplyChainTradeTransaction',
            ],

            'rsm:ExchangedDocumentContext' => [
                'ram:TestIndicator',
                'ram:BusinessProcessSpecifiedDocumentContextParameter',
                'ram:BIMSpecifiedDocumentContextParameter',
                'ram:ScenarioSpecifiedDocumentContextParameter',
                'ram:ApplicationSpecifiedDocumentContextParameter',
                'ram:GuidelineSpecifiedDocumentContextParameter',
                'ram:SubsetSpecifiedDocumentContextParameter',
                'ram:MessageStandardSpecifiedDocumentContextParameter',
            ],
            'ram:BusinessProcessSpecifiedDocumentContextParameter' => ['ram:ID', 'ram:Value'],
            'ram:GuidelineSpecifiedDocumentContextParameter' => ['ram:ID', 'ram:Value'],

            'rsm:ExchangedDocument' => [
                'ram:ID',
                'ram:Name',
                'ram:TypeCode',
                'ram:IssueDateTime',
                'ram:CopyIndicator',
                'ram:LanguageID',
                'ram:Purpose',
                'ram:ControlRequirementIndicator',
                'ram:IncludedNote',
                'ram:EffectiveSpecifiedPeriod',
                'ram:IssuerTradeParty',
            ],
            'ram:IncludedNote' => ['ram:ContentCode', 'ram:Content', 'ram:SubjectCode', 'ram:ID'],
            'ram:IssueDateTime' => ['udt:DateTimeString'],
            'ram:OccurrenceDateTime' => ['udt:DateTimeString'],
            'ram:DueDateDateTime' => ['udt:DateTimeString'],
            'ram:StartDateTime' => ['udt:DateTimeString'],
            'ram:EndDateTime' => ['udt:DateTimeString'],
            'ram:FormattedIssueDateTime' => ['qdt:DateTimeString'],
            'ram:ChargeIndicator' => ['udt:Indicator'],
            'ram:TaxPointDate' => ['udt:DateString'],

            'rsm:SupplyChainTradeTransaction' => [
                'ram:IncludedSupplyChainTradeLineItem',
                'ram:ApplicableHeaderTradeAgreement',
                'ram:ApplicableHeaderTradeDelivery',
                'ram:ApplicableHeaderTradeSettlement',
            ],

            'ram:IncludedSupplyChainTradeLineItem' => [
                'ram:AssociatedDocumentLineDocument',
                'ram:SpecifiedTradeProduct',
                'ram:SpecifiedLineTradeAgreement',
                'ram:SpecifiedLineTradeDelivery',
                'ram:SpecifiedLineTradeSettlement',
            ],
            'ram:AssociatedDocumentLineDocument' => [
                'ram:LineID', 'ram:LineStatusCode', 'ram:LineStatusReasonCode', 'ram:IncludedNote',
            ],
            'ram:SpecifiedTradeProduct' => [
                'ram:ID', 'ram:GlobalID', 'ram:SellerAssignedID', 'ram:BuyerAssignedID',
                'ram:IndustryAssignedID', 'ram:ModelID', 'ram:Name', 'ram:Description',
                'ram:ApplicableProductCharacteristic', 'ram:DesignatedProductClassification',
                'ram:IndividualTradeProductInstance', 'ram:OriginTradeCountry',
                'ram:IncludedReferencedProduct',
            ],
            'ram:ApplicableProductCharacteristic' => [
                'ram:TypeCode', 'ram:Description', 'ram:ValueMeasure', 'ram:Value',
            ],
            'ram:DesignatedProductClassification' => ['ram:ClassCode', 'ram:ClassName'],
            'ram:OriginTradeCountry' => ['ram:ID', 'ram:Name'],

            'ram:SpecifiedLineTradeAgreement' => [
                'ram:BuyerOrderReferencedDocument',
                'ram:QuotationReferencedDocument',
                'ram:ContractReferencedDocument',
                'ram:AdditionalReferencedDocument',
                'ram:GrossPriceProductTradePrice',
                'ram:NetPriceProductTradePrice',
            ],
            'ram:GrossPriceProductTradePrice' => [
                'ram:ChargeAmount', 'ram:BasisQuantity', 'ram:AppliedTradeAllowanceCharge',
            ],
            'ram:NetPriceProductTradePrice' => ['ram:ChargeAmount', 'ram:BasisQuantity'],
            'ram:AppliedTradeAllowanceCharge' => [
                'ram:ChargeIndicator', 'ram:SequenceNumeric', 'ram:CalculationPercent',
                'ram:BasisAmount', 'ram:BasisQuantity', 'ram:ActualAmount', 'ram:ReasonCode', 'ram:Reason',
            ],
            'ram:SpecifiedLineTradeDelivery' => [
                'ram:BilledQuantity', 'ram:ChargeFreeQuantity', 'ram:PackageQuantity',
                'ram:ShipToTradeParty', 'ram:UltimateShipToTradeParty',
                'ram:ActualDeliverySupplyChainEvent', 'ram:DespatchAdviceReferencedDocument',
                'ram:ReceivingAdviceReferencedDocument', 'ram:DeliveryNoteReferencedDocument',
            ],
            'ram:SpecifiedLineTradeSettlement' => [
                'ram:ApplicableTradeTax',
                'ram:BillingSpecifiedPeriod',
                'ram:SpecifiedTradeAllowanceCharge',
                'ram:SpecifiedTradePaymentTerms',
                'ram:SpecifiedTradeSettlementLineMonetarySummation',
                'ram:InvoiceReferencedDocument',
                'ram:AdditionalReferencedDocument',
                'ram:ReceivableSpecifiedTradeAccountingAccount',
            ],
            'ram:SpecifiedTradeSettlementLineMonetarySummation' => [
                'ram:LineTotalAmount', 'ram:ChargeTotalAmount', 'ram:AllowanceTotalAmount',
                'ram:TaxBasisTotalAmount', 'ram:TaxTotalAmount', 'ram:GrandTotalAmount',
                'ram:InformationAmount', 'ram:TotalAllowanceChargeAmount',
            ],

            'ram:ApplicableHeaderTradeAgreement' => [
                'ram:BuyerReference',
                'ram:SellerTradeParty',
                'ram:BuyerTradeParty',
                'ram:SalesAgentTradeParty',
                'ram:BuyerTaxRepresentativeTradeParty',
                'ram:ProductEndUserTradeParty',
                'ram:ApplicableTradeDeliveryTerms',
                'ram:SellerOrderReferencedDocument',
                'ram:BuyerOrderReferencedDocument',
                'ram:QuotationReferencedDocument',
                'ram:ContractReferencedDocument',
                'ram:DemandForecastReferencedDocument',
                'ram:PromotionalDealReferencedDocument',
                'ram:PriceListReferencedDocument',
                'ram:AdditionalReferencedDocument',
                'ram:BuyerAgentTradeParty',
                'ram:SpecifiedProcuringProject',
                'ram:UltimateCustomerOrderReferencedDocument',
                'ram:SellerTaxRepresentativeTradeParty',
            ],

            'ram:SellerTradeParty' => [
                'ram:ID', 'ram:GlobalID', 'ram:SpecifiedLegalOrganization', 'ram:Name',
                'ram:RoleCode', 'ram:Description', 'ram:DefinedTradeContact',
                'ram:PostalTradeAddress', 'ram:URIUniversalCommunication',
                'ram:SpecifiedTaxRegistration',
            ],
            'ram:BuyerTradeParty' => [
                'ram:ID', 'ram:GlobalID', 'ram:SpecifiedLegalOrganization', 'ram:Name',
                'ram:RoleCode', 'ram:Description', 'ram:DefinedTradeContact',
                'ram:PostalTradeAddress', 'ram:URIUniversalCommunication',
                'ram:SpecifiedTaxRegistration',
            ],
            'ram:PayeeTradeParty' => [
                'ram:ID', 'ram:GlobalID', 'ram:SpecifiedLegalOrganization', 'ram:Name',
                'ram:RoleCode', 'ram:Description', 'ram:DefinedTradeContact',
                'ram:PostalTradeAddress', 'ram:URIUniversalCommunication',
                'ram:SpecifiedTaxRegistration',
            ],
            'ram:SellerTaxRepresentativeTradeParty' => [
                'ram:ID', 'ram:GlobalID', 'ram:SpecifiedLegalOrganization', 'ram:Name',
                'ram:RoleCode', 'ram:Description', 'ram:DefinedTradeContact',
                'ram:PostalTradeAddress', 'ram:URIUniversalCommunication',
                'ram:SpecifiedTaxRegistration',
            ],
            'ram:ShipToTradeParty' => [
                'ram:ID', 'ram:GlobalID', 'ram:SpecifiedLegalOrganization', 'ram:Name',
                'ram:RoleCode', 'ram:Description', 'ram:DefinedTradeContact',
                'ram:PostalTradeAddress', 'ram:URIUniversalCommunication',
                'ram:SpecifiedTaxRegistration',
            ],
            'ram:SpecifiedLegalOrganization' => ['ram:ID', 'ram:TradingBusinessName', 'ram:PostalTradeAddress'],
            'ram:DefinedTradeContact' => [
                'ram:ID', 'ram:PersonName', 'ram:DepartmentName', 'ram:TypeCode',
                'ram:JobTitle', 'ram:Responsibility', 'ram:PersonID',
                'ram:TelephoneUniversalCommunication', 'ram:FaxUniversalCommunication',
                'ram:EmailURIUniversalCommunication',
            ],
            'ram:TelephoneUniversalCommunication' => ['ram:URIID', 'ram:ChannelCode', 'ram:CompleteNumber'],
            'ram:EmailURIUniversalCommunication' => ['ram:URIID', 'ram:ChannelCode', 'ram:CompleteNumber'],
            'ram:URIUniversalCommunication' => ['ram:URIID', 'ram:ChannelCode', 'ram:CompleteNumber'],
            'ram:PostalTradeAddress' => [
                'ram:ID', 'ram:PostcodeCode', 'ram:BuildingNumber', 'ram:BuildingName',
                'ram:LineOne', 'ram:LineTwo', 'ram:LineThree', 'ram:StreetName',
                'ram:CityName', 'ram:CitySubDivisionName', 'ram:CountryID', 'ram:CountryName',
                'ram:CountrySubDivisionID', 'ram:CountrySubDivisionName',
            ],
            'ram:SpecifiedTaxRegistration' => ['ram:ID'],

            'ram:BuyerOrderReferencedDocument' => [
                'ram:IssuerAssignedID', 'ram:URIID', 'ram:LineID', 'ram:TypeCode',
                'ram:ReferenceTypeCode', 'ram:FormattedIssueDateTime',
            ],
            'ram:ContractReferencedDocument' => [
                'ram:IssuerAssignedID', 'ram:URIID', 'ram:LineID', 'ram:TypeCode',
                'ram:ReferenceTypeCode', 'ram:FormattedIssueDateTime',
            ],
            'ram:InvoiceReferencedDocument' => [
                'ram:IssuerAssignedID', 'ram:URIID', 'ram:LineID', 'ram:TypeCode',
                'ram:ReferenceTypeCode', 'ram:FormattedIssueDateTime',
            ],
            'ram:AdditionalReferencedDocument' => [
                'ram:IssuerAssignedID', 'ram:URIID', 'ram:LineID', 'ram:TypeCode',
                'ram:Name', 'ram:AttachmentBinaryObject', 'ram:ReferenceTypeCode',
                'ram:FormattedIssueDateTime',
            ],
            'ram:SpecifiedProcuringProject' => ['ram:ID', 'ram:Name'],

            'ram:ApplicableHeaderTradeDelivery' => [
                'ram:RelatedSupplyChainConsignment',
                'ram:ShipToTradeParty',
                'ram:UltimateShipToTradeParty',
                'ram:ShipFromTradeParty',
                'ram:ActualDeliverySupplyChainEvent',
                'ram:DespatchAdviceReferencedDocument',
                'ram:ReceivingAdviceReferencedDocument',
                'ram:DeliveryNoteReferencedDocument',
            ],
            'ram:ActualDeliverySupplyChainEvent' => [
                'ram:ID', 'ram:TypeCode', 'ram:OccurrenceDateTime', 'ram:OccurrenceSpecifiedPeriod',
                'ram:UnloadingBaseportLocation', 'ram:OccurrenceLogisticsLocation',
            ],

            'ram:ApplicableHeaderTradeSettlement' => [
                'ram:CreditorReferenceTypeCode',
                'ram:CreditorReferenceIssuerID',
                'ram:CreditorReferenceID',
                'ram:PaymentReference',
                'ram:TaxCurrencyCode',
                'ram:InvoiceCurrencyCode',
                'ram:PaymentCurrencyCode',
                'ram:InvoiceIssuerReference',
                'ram:TotalAdjustmentAmount',
                'ram:InvoicerTradeParty',
                'ram:InvoiceeTradeParty',
                'ram:PayeeTradeParty',
                'ram:PayerTradeParty',
                'ram:TaxApplicableTradeCurrencyExchange',
                'ram:SpecifiedTradeSettlementPaymentMeans',
                'ram:ApplicableTradeTax',
                'ram:BillingSpecifiedPeriod',
                'ram:SpecifiedTradeAllowanceCharge',
                'ram:SpecifiedLogisticsServiceCharge',
                'ram:SpecifiedTradePaymentTerms',
                'ram:SpecifiedTradeSettlementHeaderMonetarySummation',
                'ram:InvoiceReferencedDocument',
                'ram:ReceivableSpecifiedTradeAccountingAccount',
                'ram:SpecifiedAdvancePayment',
            ],
            'ram:SpecifiedTradeSettlementPaymentMeans' => [
                'ram:TypeCode', 'ram:Information', 'ram:ID',
                'ram:ApplicableTradeSettlementFinancialCard',
                'ram:PayerPartyDebtorFinancialAccount',
                'ram:PayeePartyCreditorFinancialAccount',
                'ram:PayerSpecifiedDebtorFinancialInstitution',
                'ram:PayeeSpecifiedCreditorFinancialInstitution',
            ],
            'ram:PayeePartyCreditorFinancialAccount' => [
                'ram:IBANID', 'ram:AccountName', 'ram:AccountID', 'ram:ProprietaryID',
            ],
            'ram:PayerPartyDebtorFinancialAccount' => [
                'ram:IBANID', 'ram:AccountName', 'ram:AccountID', 'ram:ProprietaryID',
            ],
            'ram:PayeeSpecifiedCreditorFinancialInstitution' => [
                'ram:BICID', 'ram:GermanBankleitzahlID', 'ram:ClearingSystemName', 'ram:Name',
                'ram:LocationFinancialInstitutionAddress',
            ],
            'ram:ApplicableTradeSettlementFinancialCard' => ['ram:ID', 'ram:CardholderName'],

            /*
             | The tax point lives HERE in CII, not at document level.
             |
             | `ram:TaxPointDate` and `ram:DueDateTypeCode` are inside each
             | trade tax group - one of the structural differences that makes
             | this a different syntax rather than a renaming.
             */
            'ram:ApplicableTradeTax' => [
                'ram:CalculatedAmount',
                'ram:TypeCode',
                'ram:ExemptionReason',
                'ram:BasisAmount',
                'ram:LineTotalBasisAmount',
                'ram:AllowanceChargeBasisAmount',
                'ram:CategoryCode',
                'ram:ExemptionReasonCode',
                'ram:TaxPointDate',
                'ram:DueDateTypeCode',
                'ram:RateApplicablePercent',
            ],
            'ram:BillingSpecifiedPeriod' => [
                'ram:ID', 'ram:Description', 'ram:StartDateTime', 'ram:EndDateTime',
                'ram:CompleteDateTime', 'ram:OpenIndicator', 'ram:DurationMeasure',
                'ram:InclusiveIndicator', 'ram:SeasonCode',
            ],
            'ram:SpecifiedTradeAllowanceCharge' => [
                'ram:ChargeIndicator', 'ram:SequenceNumeric', 'ram:CalculationPercent',
                'ram:BasisAmount', 'ram:BasisQuantity', 'ram:ActualAmount',
                'ram:ReasonCode', 'ram:Reason', 'ram:TypeCode', 'ram:CategoryTradeTax',
            ],
            'ram:CategoryTradeTax' => [
                'ram:CalculatedAmount', 'ram:TypeCode', 'ram:ExemptionReason',
                'ram:BasisAmount', 'ram:CategoryCode', 'ram:ExemptionReasonCode',
                'ram:TaxPointDate', 'ram:DueDateTypeCode', 'ram:RateApplicablePercent',
            ],
            'ram:SpecifiedTradePaymentTerms' => [
                'ram:ID', 'ram:Description', 'ram:DueDateDateTime',
                'ram:DirectDebitMandateID', 'ram:PartialPaymentPercent',
                'ram:PartialPaymentAmount', 'ram:ApplicableTradePaymentPenaltyTerms',
                'ram:ApplicableTradePaymentDiscountTerms', 'ram:PayeeTradeParty',
            ],
            'ram:SpecifiedTradeSettlementHeaderMonetarySummation' => [
                'ram:LineTotalAmount',
                'ram:ChargeTotalAmount',
                'ram:AllowanceTotalAmount',
                'ram:TaxBasisTotalAmount',
                'ram:TaxTotalAmount',
                'ram:RoundingAmount',
                'ram:GrandTotalAmount',
                'ram:InformationAmount',
                'ram:TotalPrepaidAmount',
                'ram:TotalDiscountAmount',
                'ram:DuePayableAmount',
            ],
            'ram:ReceivableSpecifiedTradeAccountingAccount' => ['ram:ID', 'ram:TypeCode'],
        ];
    }
}
