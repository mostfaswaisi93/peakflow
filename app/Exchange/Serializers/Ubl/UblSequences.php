<?php

declare(strict_types=1);

namespace App\Exchange\Serializers\Ubl;

/**
 * The UBL 2.1 element sequences, as data - PF-112.
 *
 * ## Why this is a table and not comments in a serializer
 *
 * Every UBL complex type is an `xsd:sequence`, so the order is part of the
 * contract and not a formatting choice. Holding it as data means it can be
 * read against the schema by a person, diffed when a profile adds a term,
 * and used by the writer to make an out-of-order document impossible to
 * build rather than merely likely to be caught.
 *
 * ## The Invoice and the CreditNote differ, and that is the trap
 *
 * In `Invoice` the order is `IssueDate`, `DueDate`, `InvoiceTypeCode`,
 * `Note`, `TaxPointDate`. In `CreditNote` it is `IssueDate`, `DueDate`,
 * `TaxPointDate`, `CreditNoteTypeCode`, `Note` - the tax point moves ahead
 * of the type code and the note. A serializer that treats a credit note as
 * an invoice with a different type code emits valid-looking XML that fails
 * schema validation on exactly the documents that carry a tax point.
 *
 * ## Written from the schema, and not yet verified against it
 *
 * These sequences are written from UBL 2.1 and the EN 16931 subset. PF-114
 * is what proves them - XSD validation against the vendored schemas - and it
 * is blocked on fetching those schemas, which this environment cannot do. So
 * the position of an element here is reviewed and reasoned, not machine
 * checked, and the task file says so rather than claiming otherwise.
 *
 * Elements outside the EN 16931 subset are included where they sit between
 * two that are used, because the sequence has to be complete to be correct.
 */
final class UblSequences
{
    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'Invoice' => [
                'ext:UBLExtensions',
                'cbc:UBLVersionID',
                'cbc:CustomizationID',
                'cbc:ProfileID',
                'cbc:ProfileExecutionID',
                'cbc:ID',
                'cbc:CopyIndicator',
                'cbc:UUID',
                'cbc:IssueDate',
                'cbc:IssueTime',
                'cbc:DueDate',
                'cbc:InvoiceTypeCode',
                'cbc:Note',
                'cbc:TaxPointDate',
                'cbc:DocumentCurrencyCode',
                'cbc:TaxCurrencyCode',
                'cbc:PricingCurrencyCode',
                'cbc:PaymentCurrencyCode',
                'cbc:PaymentAlternativeCurrencyCode',
                'cbc:AccountingCostCode',
                'cbc:AccountingCost',
                'cbc:LineCountNumeric',
                'cbc:BuyerReference',
                'cac:InvoicePeriod',
                'cac:OrderReference',
                'cac:BillingReference',
                'cac:DespatchDocumentReference',
                'cac:ReceiptDocumentReference',
                'cac:StatementDocumentReference',
                'cac:OriginatorDocumentReference',
                'cac:ContractDocumentReference',
                'cac:AdditionalDocumentReference',
                'cac:ProjectReference',
                'cac:Signature',
                'cac:AccountingSupplierParty',
                'cac:AccountingCustomerParty',
                'cac:PayeeParty',
                'cac:BuyerCustomerParty',
                'cac:SellerSupplierParty',
                'cac:TaxRepresentativeParty',
                'cac:Delivery',
                'cac:DeliveryTerms',
                'cac:PaymentMeans',
                'cac:PaymentTerms',
                'cac:PrepaidPayment',
                'cac:AllowanceCharge',
                'cac:TaxExchangeRate',
                'cac:PricingExchangeRate',
                'cac:PaymentExchangeRate',
                'cac:PaymentAlternativeExchangeRate',
                'cac:TaxTotal',
                'cac:WithholdingTaxTotal',
                'cac:LegalMonetaryTotal',
                'cac:InvoiceLine',
            ],

            /*
             | The credit note's own order.
             |
             | `cbc:TaxPointDate` is BEFORE `cbc:CreditNoteTypeCode` and
             | `cbc:Note`, where the invoice has it after both. This is the
             | difference that makes a shared "emit a document" method wrong.
             */
            'CreditNote' => [
                'ext:UBLExtensions',
                'cbc:UBLVersionID',
                'cbc:CustomizationID',
                'cbc:ProfileID',
                'cbc:ProfileExecutionID',
                'cbc:ID',
                'cbc:CopyIndicator',
                'cbc:UUID',
                'cbc:IssueDate',
                'cbc:IssueTime',
                'cbc:DueDate',
                'cbc:TaxPointDate',
                'cbc:CreditNoteTypeCode',
                'cbc:Note',
                'cbc:DocumentCurrencyCode',
                'cbc:TaxCurrencyCode',
                'cbc:PricingCurrencyCode',
                'cbc:PaymentCurrencyCode',
                'cbc:PaymentAlternativeCurrencyCode',
                'cbc:AccountingCostCode',
                'cbc:AccountingCost',
                'cbc:LineCountNumeric',
                'cbc:BuyerReference',
                'cac:InvoicePeriod',
                'cac:DiscrepancyResponse',
                'cac:OrderReference',
                'cac:BillingReference',
                'cac:DespatchDocumentReference',
                'cac:ReceiptDocumentReference',
                'cac:ContractDocumentReference',
                'cac:AdditionalDocumentReference',
                'cac:Signature',
                'cac:AccountingSupplierParty',
                'cac:AccountingCustomerParty',
                'cac:PayeeParty',
                'cac:BuyerCustomerParty',
                'cac:SellerSupplierParty',
                'cac:TaxRepresentativeParty',
                'cac:Delivery',
                'cac:DeliveryTerms',
                'cac:PaymentMeans',
                'cac:PaymentTerms',
                'cac:AllowanceCharge',
                'cac:TaxExchangeRate',
                'cac:PricingExchangeRate',
                'cac:PaymentExchangeRate',
                'cac:PaymentAlternativeExchangeRate',
                'cac:TaxTotal',
                'cac:WithholdingTaxTotal',
                'cac:LegalMonetaryTotal',
                'cac:CreditNoteLine',
            ],

            'cac:InvoicePeriod' => [
                'cbc:StartDate', 'cbc:StartTime', 'cbc:EndDate', 'cbc:EndTime',
                'cbc:DurationMeasure', 'cbc:DescriptionCode', 'cbc:Description',
            ],

            'cac:OrderReference' => ['cbc:ID', 'cbc:SalesOrderID', 'cbc:CopyIndicator', 'cbc:UUID', 'cbc:IssueDate'],

            'cac:BillingReference' => [
                'cac:InvoiceDocumentReference',
                'cac:SelfBilledInvoiceDocumentReference',
                'cac:CreditNoteDocumentReference',
                'cac:SelfBilledCreditNoteDocumentReference',
                'cac:DebitNoteDocumentReference',
                'cac:BillingReferenceLine',
            ],

            'cac:InvoiceDocumentReference' => ['cbc:ID', 'cbc:CopyIndicator', 'cbc:UUID', 'cbc:IssueDate'],
            'cac:ContractDocumentReference' => ['cbc:ID', 'cbc:CopyIndicator', 'cbc:UUID', 'cbc:IssueDate', 'cbc:DocumentTypeCode'],
            'cac:ProjectReference' => ['cbc:ID', 'cbc:UUID', 'cbc:IssueDate'],

            'cac:AdditionalDocumentReference' => [
                'cbc:ID', 'cbc:CopyIndicator', 'cbc:UUID', 'cbc:IssueDate',
                'cbc:DocumentTypeCode', 'cbc:DocumentType', 'cbc:DocumentDescription',
                'cac:Attachment',
            ],
            'cac:Attachment' => ['cbc:EmbeddedDocumentBinaryObject', 'cac:ExternalReference'],
            'cac:ExternalReference' => ['cbc:URI', 'cbc:DocumentHash', 'cbc:MimeCode', 'cbc:FileName', 'cbc:Description'],

            'cac:AccountingSupplierParty' => ['cbc:CustomerAssignedAccountID', 'cac:Party'],
            'cac:AccountingCustomerParty' => ['cbc:CustomerAssignedAccountID', 'cbc:SupplierAssignedAccountID', 'cac:Party'],
            'cac:TaxRepresentativeParty' => ['cac:Party'],
            'cac:PayeeParty' => [
                'cbc:MarkCareIndicator', 'cac:PartyIdentification', 'cac:PartyName',
                'cac:PostalAddress', 'cac:PartyTaxScheme', 'cac:PartyLegalEntity', 'cac:Contact',
            ],

            'cac:Party' => [
                'cbc:MarkCareIndicator',
                'cbc:MarkAttentionIndicator',
                'cbc:WebsiteURI',
                'cbc:LogoReferenceID',
                'cbc:EndpointID',
                'cbc:IndustryClassificationCode',
                'cac:PartyIdentification',
                'cac:PartyName',
                'cac:Language',
                'cac:PostalAddress',
                'cac:PhysicalLocation',
                'cac:PartyTaxScheme',
                'cac:PartyLegalEntity',
                'cac:Contact',
                'cac:Person',
            ],

            'cac:PartyIdentification' => ['cbc:ID'],
            'cac:PartyName' => ['cbc:Name'],

            'cac:PostalAddress' => [
                'cbc:ID', 'cbc:AddressTypeCode', 'cbc:AddressFormatCode', 'cbc:Postbox',
                'cbc:Floor', 'cbc:Room', 'cbc:StreetName', 'cbc:AdditionalStreetName',
                'cbc:BlockName', 'cbc:BuildingName', 'cbc:BuildingNumber', 'cbc:InhouseMail',
                'cbc:Department', 'cbc:MarkAttention', 'cbc:MarkCare', 'cbc:PlotIdentification',
                'cbc:CitySubdivisionName', 'cbc:CityName', 'cbc:PostalZone',
                'cbc:CountrySubentity', 'cbc:CountrySubentityCode', 'cbc:Region', 'cbc:District',
                'cbc:TimezoneOffset', 'cac:AddressLine', 'cac:Country',
            ],
            'cac:Address' => [
                'cbc:ID', 'cbc:AddressTypeCode', 'cbc:AddressFormatCode', 'cbc:Postbox',
                'cbc:Floor', 'cbc:Room', 'cbc:StreetName', 'cbc:AdditionalStreetName',
                'cbc:BlockName', 'cbc:BuildingName', 'cbc:BuildingNumber', 'cbc:InhouseMail',
                'cbc:Department', 'cbc:MarkAttention', 'cbc:MarkCare', 'cbc:PlotIdentification',
                'cbc:CitySubdivisionName', 'cbc:CityName', 'cbc:PostalZone',
                'cbc:CountrySubentity', 'cbc:CountrySubentityCode', 'cbc:Region', 'cbc:District',
                'cbc:TimezoneOffset', 'cac:AddressLine', 'cac:Country',
            ],
            'cac:AddressLine' => ['cbc:Line'],
            'cac:Country' => ['cbc:IdentificationCode', 'cbc:Name'],

            'cac:PartyTaxScheme' => [
                'cbc:RegistrationName', 'cbc:CompanyID', 'cbc:TaxLevelCode',
                'cbc:ExemptionReasonCode', 'cbc:ExemptionReason',
                'cac:RegistrationAddress', 'cac:TaxScheme',
            ],
            'cac:TaxScheme' => ['cbc:ID', 'cbc:Name', 'cbc:TaxTypeCode', 'cbc:CurrencyCode', 'cac:JurisdictionRegionAddress'],

            'cac:PartyLegalEntity' => [
                'cbc:RegistrationName', 'cbc:CompanyID', 'cbc:CompanyLegalFormCode',
                'cbc:CompanyLegalForm', 'cbc:SoleProprietorshipIndicator',
                'cbc:CompanyLiquidationStatusCode', 'cbc:CorporationStockAmount',
                'cbc:FullyPaidSharesIndicator', 'cac:RegistrationAddress', 'cac:CorporateRegistrationScheme',
            ],

            'cac:Contact' => ['cbc:ID', 'cbc:Name', 'cbc:Telephone', 'cbc:Telefax', 'cbc:ElectronicMail', 'cbc:Note'],

            'cac:Delivery' => [
                'cbc:ID', 'cbc:Quantity', 'cbc:MinimumQuantity', 'cbc:MaximumQuantity',
                'cbc:ActualDeliveryDate', 'cbc:ActualDeliveryTime', 'cbc:LatestDeliveryDate',
                'cbc:LatestDeliveryTime', 'cbc:TrackingID', 'cac:DeliveryLocation',
                'cac:AlternativeDeliveryLocation', 'cac:RequestedDeliveryPeriod',
                'cac:PromisedDeliveryPeriod', 'cac:EstimatedDeliveryPeriod', 'cac:DeliveryParty',
            ],
            'cac:DeliveryLocation' => [
                'cbc:ID', 'cbc:Description', 'cbc:Conditions', 'cbc:CountrySubentity',
                'cbc:CountrySubentityCode', 'cac:ValidityPeriod', 'cac:Address',
            ],
            'cac:DeliveryParty' => [
                'cbc:MarkCareIndicator', 'cac:PartyIdentification', 'cac:PartyName',
                'cac:PostalAddress', 'cac:PartyTaxScheme', 'cac:PartyLegalEntity', 'cac:Contact',
            ],

            'cac:PaymentMeans' => [
                'cbc:ID', 'cbc:PaymentMeansCode', 'cbc:PaymentDueDate', 'cbc:PaymentChannelCode',
                'cbc:InstructionID', 'cbc:InstructionNote', 'cbc:PaymentID',
                'cac:CardAccount', 'cac:PayerFinancialAccount', 'cac:PayeeFinancialAccount',
                'cac:CreditAccount', 'cac:PaymentMandate', 'cac:TradeFinancing',
            ],
            'cac:PayeeFinancialAccount' => [
                'cbc:ID', 'cbc:Name', 'cbc:AliasName', 'cbc:AccountTypeCode',
                'cbc:AccountFormatCode', 'cbc:CurrencyCode', 'cbc:PaymentNote',
                'cac:FinancialInstitutionBranch', 'cac:Country',
            ],
            'cac:FinancialInstitutionBranch' => ['cbc:ID', 'cbc:Name', 'cac:FinancialInstitution', 'cac:Address'],
            'cac:CardAccount' => [
                'cbc:PrimaryAccountNumberID', 'cbc:NetworkID', 'cbc:CardTypeCode',
                'cbc:ValidityStartDate', 'cbc:ExpiryDate', 'cbc:IssuerID',
                'cbc:IssueNumberID', 'cbc:CV2ID', 'cbc:CardChipCode', 'cbc:ChipApplicationID',
                'cbc:HolderName',
            ],
            'cac:PaymentMandate' => [
                'cbc:ID', 'cbc:MandateTypeCode', 'cbc:MaximumPaymentInstructionsNumeric',
                'cbc:MaximumPaidAmount', 'cbc:SignatureID', 'cac:PayerParty',
                'cac:PayerFinancialAccount', 'cac:ValidityPeriod', 'cac:PaymentReversalPeriod', 'cac:Clause',
            ],

            'cac:PaymentTerms' => [
                'cbc:ID', 'cbc:PaymentMeansID', 'cbc:PrepaidPaymentReferenceID', 'cbc:Note',
                'cbc:ReferenceEventCode', 'cbc:SettlementDiscountPercent', 'cbc:PenaltySurchargePercent',
                'cbc:PaymentPercent', 'cbc:Amount', 'cbc:SettlementDiscountAmount',
                'cbc:PenaltyAmount', 'cbc:PaymentTermsDetailsURI', 'cbc:PaymentDueDate',
                'cbc:InstallmentDueDate', 'cbc:InvoicingPartyReference', 'cac:SettlementPeriod',
                'cac:PenaltyPeriod', 'cac:ExchangeRate', 'cac:ValidityPeriod',
            ],

            'cac:AllowanceCharge' => [
                'cbc:ID', 'cbc:ChargeIndicator', 'cbc:AllowanceChargeReasonCode',
                'cbc:AllowanceChargeReason', 'cbc:MultiplierFactorNumeric',
                'cbc:PrepaidIndicator', 'cbc:SequenceNumeric', 'cbc:Amount',
                'cbc:BaseAmount', 'cbc:AccountingCostCode', 'cbc:AccountingCost',
                'cbc:PerUnitAmount', 'cac:TaxCategory', 'cac:TaxTotal', 'cac:PaymentMeans',
            ],

            'cac:TaxTotal' => ['cbc:TaxAmount', 'cbc:RoundingAmount', 'cbc:TaxEvidenceIndicator', 'cbc:TaxIncludedIndicator', 'cac:TaxSubtotal'],
            'cac:TaxSubtotal' => [
                'cbc:TaxableAmount', 'cbc:TaxAmount', 'cbc:CalculationSequenceNumeric',
                'cbc:TransactionCurrencyTaxAmount', 'cbc:Percent', 'cbc:BaseUnitMeasure',
                'cbc:PerUnitAmount', 'cbc:TierRange', 'cbc:TierRatePercent', 'cac:TaxCategory',
            ],
            'cac:TaxCategory' => [
                'cbc:ID', 'cbc:Name', 'cbc:Percent', 'cbc:BaseUnitMeasure', 'cbc:PerUnitAmount',
                'cbc:TaxExemptionReasonCode', 'cbc:TaxExemptionReason', 'cbc:TierRange',
                'cbc:TierRatePercent', 'cac:TaxScheme',
            ],
            'cac:ClassifiedTaxCategory' => [
                'cbc:ID', 'cbc:Name', 'cbc:Percent', 'cbc:BaseUnitMeasure', 'cbc:PerUnitAmount',
                'cbc:TaxExemptionReasonCode', 'cbc:TaxExemptionReason', 'cbc:TierRange',
                'cbc:TierRatePercent', 'cac:TaxScheme',
            ],

            'cac:LegalMonetaryTotal' => [
                'cbc:LineExtensionAmount', 'cbc:TaxExclusiveAmount', 'cbc:TaxInclusiveAmount',
                'cbc:AllowanceTotalAmount', 'cbc:ChargeTotalAmount', 'cbc:PrepaidAmount',
                'cbc:PayableRoundingAmount', 'cbc:PayableAmount', 'cbc:PayableAlternativeAmount',
            ],

            'cac:InvoiceLine' => [
                'cbc:ID', 'cbc:UUID', 'cbc:Note', 'cbc:InvoicedQuantity', 'cbc:LineExtensionAmount',
                'cbc:TaxPointDate', 'cbc:AccountingCostCode', 'cbc:AccountingCost',
                'cbc:PaymentPurposeCode', 'cbc:FreeOfChargeIndicator', 'cac:InvoicePeriod',
                'cac:OrderLineReference', 'cac:DespatchLineReference', 'cac:ReceiptLineReference',
                'cac:BillingReference', 'cac:DocumentReference', 'cac:PricingReference',
                'cac:OriginatorParty', 'cac:Delivery', 'cac:PaymentTerms', 'cac:AllowanceCharge',
                'cac:TaxTotal', 'cac:WithholdingTaxTotal', 'cac:Item', 'cac:Price',
                'cac:DeliveryTerms', 'cac:SubInvoiceLine', 'cac:ItemPriceExtension',
            ],
            'cac:CreditNoteLine' => [
                'cbc:ID', 'cbc:UUID', 'cbc:Note', 'cbc:CreditedQuantity', 'cbc:LineExtensionAmount',
                'cbc:TaxPointDate', 'cbc:AccountingCostCode', 'cbc:AccountingCost',
                'cbc:PaymentPurposeCode', 'cbc:FreeOfChargeIndicator', 'cac:DiscrepancyResponse',
                'cac:DespatchLineReference', 'cac:ReceiptLineReference', 'cac:BillingReference',
                'cac:OrderLineReference', 'cac:DocumentReference', 'cac:PricingReference',
                'cac:Delivery', 'cac:PaymentTerms', 'cac:TaxTotal', 'cac:Item', 'cac:Price',
                'cac:DeliveryTerms', 'cac:SubCreditNoteLine', 'cac:ItemPriceExtension',
                'cac:InvoicePeriod', 'cac:AllowanceCharge',
            ],
            'cac:OrderLineReference' => [
                'cbc:LineID', 'cbc:SalesOrderLineID', 'cbc:UUID', 'cbc:LineStatusCode',
                'cac:OrderReference',
            ],

            'cac:Item' => [
                'cbc:Description', 'cbc:PackQuantity', 'cbc:PackSizeNumeric',
                'cbc:CatalogueIndicator', 'cbc:Name', 'cbc:HazardousRiskIndicator',
                'cbc:AdditionalInformation', 'cbc:Keyword', 'cbc:BrandName', 'cbc:ModelName',
                'cac:BuyersItemIdentification', 'cac:SellersItemIdentification',
                'cac:ManufacturersItemIdentification', 'cac:StandardItemIdentification',
                'cac:CatalogueItemIdentification', 'cac:AdditionalItemIdentification',
                'cac:CatalogueDocumentReference', 'cac:ItemSpecificationDocumentReference',
                'cac:OriginCountry', 'cac:CommodityClassification', 'cac:TransactionConditions',
                'cac:HazardousItem', 'cac:ClassifiedTaxCategory', 'cac:AdditionalItemProperty',
                'cac:ManufacturerParty', 'cac:InformationContentProviderParty',
            ],
            'cac:BuyersItemIdentification' => ['cbc:ID', 'cbc:ExtendedID', 'cbc:BarcodeSymbologyID', 'cac:PhysicalAttribute', 'cac:MeasurementDimension', 'cac:IssuerParty'],
            'cac:SellersItemIdentification' => ['cbc:ID', 'cbc:ExtendedID', 'cbc:BarcodeSymbologyID', 'cac:PhysicalAttribute', 'cac:MeasurementDimension', 'cac:IssuerParty'],
            'cac:StandardItemIdentification' => ['cbc:ID', 'cbc:ExtendedID', 'cbc:BarcodeSymbologyID', 'cac:PhysicalAttribute', 'cac:MeasurementDimension', 'cac:IssuerParty'],
            'cac:OriginCountry' => ['cbc:IdentificationCode', 'cbc:Name'],
            'cac:CommodityClassification' => ['cbc:NatureCode', 'cbc:CargoTypeCode', 'cbc:CommodityCode', 'cbc:ItemClassificationCode'],
            'cac:AdditionalItemProperty' => [
                'cbc:ID', 'cbc:Name', 'cbc:NameCode', 'cbc:TestMethod', 'cbc:Value',
                'cbc:ValueQuantity', 'cbc:ValueQualifier', 'cbc:ImportanceCode', 'cbc:ListValue',
                'cac:UsabilityPeriod', 'cac:ItemPropertyGroup', 'cac:RangeDimension', 'cac:ItemPropertyRange',
            ],

            'cac:Price' => [
                'cbc:PriceAmount', 'cbc:BaseQuantity', 'cbc:PriceChangeReason',
                'cbc:PriceTypeCode', 'cbc:PriceType', 'cbc:OrderableUnitFactorRate',
                'cac:PriceList', 'cac:AllowanceCharge', 'cac:PricingExchangeRate', 'cac:ValidityPeriod',
            ],
        ];
    }
}
