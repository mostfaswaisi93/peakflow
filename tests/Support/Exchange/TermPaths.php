<?php

declare(strict_types=1);

namespace Tests\Support\Exchange;

/**
 * Where each business term sits in each syntax - the round trip of PF-113.
 *
 * ## Why this lives in the tests and not in the application
 *
 * It is an **independent restatement**. If the serializers were generated
 * from this table, a document serialized to both syntaxes and read back
 * through it would agree by construction and prove nothing: the test would
 * be asserting that one table equals itself.
 *
 * Written here by hand, from the two bindings, it is a second opinion. When
 * it disagrees with a serializer, one of them is wrong and a person has to
 * decide which - which is exactly the work a round-trip check is for.
 *
 * ## Why paths and not a parser
 *
 * A full inbound parser arrives with PF-122, which needs one to turn a
 * received document into a draft bill. For the claim this phase rests on -
 * that the model is genuinely syntax-independent - a path per term is
 * better evidence and far less code: it reads each term out of each syntax
 * through an expression written against the specification rather than
 * against our own serializer.
 *
 * A term the model populates and this table has no path for is reported as
 * a gap, so the round trip cannot quietly shrink as terms are added.
 */
final class TermPaths
{
    /**
     * UBL 2.1 - `local-name()` throughout, so no prefix has to be registered
     * and a document that renamed its prefixes still reads.
     *
     * @return array<string, string>
     */
    public static function ubl(): array
    {
        $supplier = '/*/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]';
        $customer = '/*/*[local-name()="AccountingCustomerParty"]/*[local-name()="Party"]';
        $totals = '/*/*[local-name()="LegalMonetaryTotal"]';
        $line = '/*/*[local-name()="InvoiceLine" or local-name()="CreditNoteLine"]';
        $allowance = '/*/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]';
        $charge = '/*/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]';
        $subtotal = '/*/*[local-name()="TaxTotal"]/*[local-name()="TaxSubtotal"]';

        return [
            'BT-1' => '/*/*[local-name()="ID"]',
            'BT-2' => '/*/*[local-name()="IssueDate"]',
            'BT-3' => '/*/*[local-name()="InvoiceTypeCode" or local-name()="CreditNoteTypeCode"]',
            'BT-5' => '/*/*[local-name()="DocumentCurrencyCode"]',
            'BT-6' => '/*/*[local-name()="TaxCurrencyCode"]',
            'BT-7' => '/*/*[local-name()="TaxPointDate"]',
            'BT-9' => '/*/*[local-name()="DueDate"]',
            'BT-10' => '/*/*[local-name()="BuyerReference"]',
            'BT-11' => '/*/*[local-name()="ProjectReference"]/*[local-name()="ID"]',
            'BT-12' => '/*/*[local-name()="ContractDocumentReference"]/*[local-name()="ID"]',
            'BT-13' => '/*/*[local-name()="OrderReference"]/*[local-name()="ID"]',
            'BT-14' => '/*/*[local-name()="OrderReference"]/*[local-name()="SalesOrderID"]',
            'BT-19' => '/*/*[local-name()="AccountingCost"]',
            'BT-20' => '/*/*[local-name()="PaymentTerms"]/*[local-name()="Note"]',
            'BT-22' => '/*/*[local-name()="Note"]',
            'BT-23' => '/*/*[local-name()="ProfileID"]',
            'BT-24' => '/*/*[local-name()="CustomizationID"]',
            'BT-25' => '/*/*[local-name()="BillingReference"]/*[local-name()="InvoiceDocumentReference"]/*[local-name()="ID"]',
            'BT-26' => '/*/*[local-name()="BillingReference"]/*[local-name()="InvoiceDocumentReference"]/*[local-name()="IssueDate"]',

            'BT-27' => $supplier.'/*[local-name()="PartyLegalEntity"]/*[local-name()="RegistrationName"]',
            'BT-28' => $supplier.'/*[local-name()="PartyName"]/*[local-name()="Name"]',
            'BT-29' => $supplier.'/*[local-name()="PartyIdentification"]/*[local-name()="ID"]',
            'BT-30' => $supplier.'/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"]',
            'BT-31' => $supplier.'/*[local-name()="PartyTaxScheme"][*[local-name()="TaxScheme"]/*[local-name()="ID"]="VAT"]/*[local-name()="CompanyID"]',
            'BT-32' => $supplier.'/*[local-name()="PartyTaxScheme"][*[local-name()="TaxScheme"]/*[local-name()="ID"]="!VAT"]/*[local-name()="CompanyID"]',
            'BT-33' => $supplier.'/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyLegalForm"]',
            'BT-34' => $supplier.'/*[local-name()="EndpointID"]',
            'BT-35' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="StreetName"]',
            'BT-36' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="AdditionalStreetName"]',
            'BT-37' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="CityName"]',
            'BT-38' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="PostalZone"]',
            'BT-39' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="CountrySubentity"]',
            'BT-40' => $supplier.'/*[local-name()="PostalAddress"]/*[local-name()="Country"]/*[local-name()="IdentificationCode"]',
            'BT-41' => $supplier.'/*[local-name()="Contact"]/*[local-name()="Name"]',
            'BT-42' => $supplier.'/*[local-name()="Contact"]/*[local-name()="Telephone"]',
            'BT-43' => $supplier.'/*[local-name()="Contact"]/*[local-name()="ElectronicMail"]',

            'BT-44' => $customer.'/*[local-name()="PartyLegalEntity"]/*[local-name()="RegistrationName"]',
            'BT-45' => $customer.'/*[local-name()="PartyName"]/*[local-name()="Name"]',
            'BT-46' => $customer.'/*[local-name()="PartyIdentification"]/*[local-name()="ID"]',
            'BT-47' => $customer.'/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"]',
            'BT-48' => $customer.'/*[local-name()="PartyTaxScheme"][*[local-name()="TaxScheme"]/*[local-name()="ID"]="VAT"]/*[local-name()="CompanyID"]',
            'BT-49' => $customer.'/*[local-name()="EndpointID"]',
            'BT-50' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="StreetName"]',
            'BT-51' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="AdditionalStreetName"]',
            'BT-52' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="CityName"]',
            'BT-53' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="PostalZone"]',
            'BT-54' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="CountrySubentity"]',
            'BT-55' => $customer.'/*[local-name()="PostalAddress"]/*[local-name()="Country"]/*[local-name()="IdentificationCode"]',
            'BT-56' => $customer.'/*[local-name()="Contact"]/*[local-name()="Name"]',
            'BT-57' => $customer.'/*[local-name()="Contact"]/*[local-name()="Telephone"]',
            'BT-58' => $customer.'/*[local-name()="Contact"]/*[local-name()="ElectronicMail"]',

            'BT-59' => '/*/*[local-name()="PayeeParty"]/*[local-name()="PartyName"]/*[local-name()="Name"]',
            'BT-60' => '/*/*[local-name()="PayeeParty"]/*[local-name()="PartyIdentification"]/*[local-name()="ID"]',
            'BT-61' => '/*/*[local-name()="PayeeParty"]/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"]',
            'BT-62' => '/*/*[local-name()="TaxRepresentativeParty"]//*[local-name()="PartyName"]/*[local-name()="Name"]',
            'BT-63' => '/*/*[local-name()="TaxRepresentativeParty"]//*[local-name()="PartyTaxScheme"]/*[local-name()="CompanyID"]',
            'BT-69' => '/*/*[local-name()="TaxRepresentativeParty"]//*[local-name()="Country"]/*[local-name()="IdentificationCode"]',

            'BT-70' => '/*/*[local-name()="Delivery"]/*[local-name()="DeliveryParty"]/*[local-name()="PartyName"]/*[local-name()="Name"]',
            'BT-71' => '/*/*[local-name()="Delivery"]/*[local-name()="DeliveryLocation"]/*[local-name()="ID"]',
            'BT-72' => '/*/*[local-name()="Delivery"]/*[local-name()="ActualDeliveryDate"]',
            'BT-73' => '/*/*[local-name()="InvoicePeriod"]/*[local-name()="StartDate"]',
            'BT-74' => '/*/*[local-name()="InvoicePeriod"]/*[local-name()="EndDate"]',
            'BT-75' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="StreetName"]',
            'BT-76' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="AdditionalStreetName"]',
            'BT-77' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="CityName"]',
            'BT-78' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="PostalZone"]',
            'BT-79' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="CountrySubentity"]',
            'BT-80' => '/*/*[local-name()="Delivery"]//*[local-name()="Address"]/*[local-name()="Country"]/*[local-name()="IdentificationCode"]',

            'BT-81' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="PaymentMeansCode"]',
            'BT-82' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="InstructionNote"]',
            'BT-83' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="PaymentID"]',
            'BT-84' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="PayeeFinancialAccount"]/*[local-name()="ID"]',
            'BT-85' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="PayeeFinancialAccount"]/*[local-name()="Name"]',
            'BT-86' => '/*/*[local-name()="PaymentMeans"]//*[local-name()="FinancialInstitutionBranch"]/*[local-name()="ID"]',
            'BT-89' => '/*/*[local-name()="PaymentMeans"]/*[local-name()="PaymentMandate"]/*[local-name()="ID"]',
            'BT-91' => '/*/*[local-name()="PaymentMeans"]//*[local-name()="PayerFinancialAccount"]/*[local-name()="ID"]',

            'BT-92' => $allowance.'/*[local-name()="Amount"]',
            'BT-93' => $allowance.'/*[local-name()="BaseAmount"]',
            'BT-94' => $allowance.'/*[local-name()="MultiplierFactorNumeric"]',
            'BT-95' => $allowance.'/*[local-name()="TaxCategory"]/*[local-name()="ID"]',
            'BT-96' => $allowance.'/*[local-name()="TaxCategory"]/*[local-name()="Percent"]',
            'BT-97' => $allowance.'/*[local-name()="AllowanceChargeReason"]',
            'BT-98' => $allowance.'/*[local-name()="AllowanceChargeReasonCode"]',
            'BT-99' => $charge.'/*[local-name()="Amount"]',
            'BT-100' => $charge.'/*[local-name()="BaseAmount"]',
            'BT-101' => $charge.'/*[local-name()="MultiplierFactorNumeric"]',
            'BT-102' => $charge.'/*[local-name()="TaxCategory"]/*[local-name()="ID"]',
            'BT-103' => $charge.'/*[local-name()="TaxCategory"]/*[local-name()="Percent"]',
            'BT-104' => $charge.'/*[local-name()="AllowanceChargeReason"]',
            'BT-105' => $charge.'/*[local-name()="AllowanceChargeReasonCode"]',

            'BT-106' => $totals.'/*[local-name()="LineExtensionAmount"]',
            'BT-107' => $totals.'/*[local-name()="AllowanceTotalAmount"]',
            'BT-108' => $totals.'/*[local-name()="ChargeTotalAmount"]',
            'BT-109' => $totals.'/*[local-name()="TaxExclusiveAmount"]',
            'BT-110' => '/*/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]',
            'BT-112' => $totals.'/*[local-name()="TaxInclusiveAmount"]',
            'BT-113' => $totals.'/*[local-name()="PrepaidAmount"]',
            'BT-114' => $totals.'/*[local-name()="PayableRoundingAmount"]',
            'BT-115' => $totals.'/*[local-name()="PayableAmount"]',

            'BT-116' => $subtotal.'/*[local-name()="TaxableAmount"]',
            'BT-117' => $subtotal.'/*[local-name()="TaxAmount"]',
            'BT-118' => $subtotal.'/*[local-name()="TaxCategory"]/*[local-name()="ID"]',
            'BT-119' => $subtotal.'/*[local-name()="TaxCategory"]/*[local-name()="Percent"]',
            'BT-120' => $subtotal.'/*[local-name()="TaxCategory"]/*[local-name()="TaxExemptionReason"]',
            'BT-121' => $subtotal.'/*[local-name()="TaxCategory"]/*[local-name()="TaxExemptionReasonCode"]',

            'BT-122' => '/*/*[local-name()="AdditionalDocumentReference"]/*[local-name()="ID"]',
            'BT-123' => '/*/*[local-name()="AdditionalDocumentReference"]/*[local-name()="DocumentDescription"]',
            'BT-124' => '/*/*[local-name()="AdditionalDocumentReference"]//*[local-name()="URI"]',

            'BT-126' => $line.'/*[local-name()="ID"]',
            'BT-127' => $line.'/*[local-name()="Note"]',
            'BT-129' => $line.'/*[local-name()="InvoicedQuantity" or local-name()="CreditedQuantity"]',
            'BT-130' => $line.'/*[local-name()="InvoicedQuantity" or local-name()="CreditedQuantity"]/@unitCode',
            'BT-131' => $line.'/*[local-name()="LineExtensionAmount"]',
            'BT-132' => $line.'/*[local-name()="OrderLineReference"]/*[local-name()="LineID"]',
            'BT-133' => $line.'/*[local-name()="AccountingCost"]',
            'BT-134' => $line.'/*[local-name()="InvoicePeriod"]/*[local-name()="StartDate"]',
            'BT-135' => $line.'/*[local-name()="InvoicePeriod"]/*[local-name()="EndDate"]',
            'BT-136' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]/*[local-name()="Amount"]',
            'BT-137' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]/*[local-name()="BaseAmount"]',
            'BT-138' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]/*[local-name()="MultiplierFactorNumeric"]',
            'BT-139' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]/*[local-name()="AllowanceChargeReason"]',
            'BT-140' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="false"]/*[local-name()="AllowanceChargeReasonCode"]',
            'BT-141' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]/*[local-name()="Amount"]',
            'BT-142' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]/*[local-name()="BaseAmount"]',
            'BT-143' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]/*[local-name()="MultiplierFactorNumeric"]',
            'BT-144' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]/*[local-name()="AllowanceChargeReason"]',
            'BT-145' => $line.'/*[local-name()="AllowanceCharge"][*[local-name()="ChargeIndicator"]="true"]/*[local-name()="AllowanceChargeReasonCode"]',
            'BT-146' => $line.'/*[local-name()="Price"]/*[local-name()="PriceAmount"]',
            'BT-147' => $line.'/*[local-name()="Price"]/*[local-name()="AllowanceCharge"]/*[local-name()="Amount"]',
            'BT-148' => $line.'/*[local-name()="Price"]/*[local-name()="AllowanceCharge"]/*[local-name()="BaseAmount"]',
            'BT-149' => $line.'/*[local-name()="Price"]/*[local-name()="BaseQuantity"]',
            'BT-150' => $line.'/*[local-name()="Price"]/*[local-name()="BaseQuantity"]/@unitCode',
            'BT-151' => $line.'/*[local-name()="Item"]/*[local-name()="ClassifiedTaxCategory"]/*[local-name()="ID"]',
            'BT-152' => $line.'/*[local-name()="Item"]/*[local-name()="ClassifiedTaxCategory"]/*[local-name()="Percent"]',
            'BT-153' => $line.'/*[local-name()="Item"]/*[local-name()="Name"]',
            'BT-154' => $line.'/*[local-name()="Item"]/*[local-name()="Description"]',
            'BT-155' => $line.'/*[local-name()="Item"]/*[local-name()="SellersItemIdentification"]/*[local-name()="ID"]',
            'BT-156' => $line.'/*[local-name()="Item"]/*[local-name()="BuyersItemIdentification"]/*[local-name()="ID"]',
            'BT-157' => $line.'/*[local-name()="Item"]/*[local-name()="StandardItemIdentification"]/*[local-name()="ID"]',
            'BT-158' => $line.'/*[local-name()="Item"]/*[local-name()="CommodityClassification"]/*[local-name()="ItemClassificationCode"]',
            'BT-159' => $line.'/*[local-name()="Item"]/*[local-name()="OriginCountry"]/*[local-name()="IdentificationCode"]',
            'BT-160' => $line.'/*[local-name()="Item"]/*[local-name()="AdditionalItemProperty"]/*[local-name()="Name"]',
            'BT-161' => $line.'/*[local-name()="Item"]/*[local-name()="AdditionalItemProperty"]/*[local-name()="Value"]',
        ];
    }

    /**
     * CII D16B.
     *
     * Note how little of this resembles the UBL table: the tax point is per
     * trade tax, dates are inside a `DateTimeString`, the note's subject
     * code is its own element, a line is three sections, and the tax
     * registration's scheme is `VA` rather than a nested `TaxScheme`.
     *
     * @return array<string, string>
     */
    public static function cii(): array
    {
        $document = '/*/*[local-name()="ExchangedDocument"]';
        $context = '/*/*[local-name()="ExchangedDocumentContext"]';
        $transaction = '/*/*[local-name()="SupplyChainTradeTransaction"]';
        $agreement = $transaction.'/*[local-name()="ApplicableHeaderTradeAgreement"]';
        $delivery = $transaction.'/*[local-name()="ApplicableHeaderTradeDelivery"]';
        $settlement = $transaction.'/*[local-name()="ApplicableHeaderTradeSettlement"]';
        $seller = $agreement.'/*[local-name()="SellerTradeParty"]';
        $buyer = $agreement.'/*[local-name()="BuyerTradeParty"]';
        $summation = $settlement.'/*[local-name()="SpecifiedTradeSettlementHeaderMonetarySummation"]';
        $tax = $settlement.'/*[local-name()="ApplicableTradeTax"]';
        $means = $settlement.'/*[local-name()="SpecifiedTradeSettlementPaymentMeans"]';
        $line = $transaction.'/*[local-name()="IncludedSupplyChainTradeLineItem"]';
        $lineSettlement = $line.'/*[local-name()="SpecifiedLineTradeSettlement"]';
        $allowance = $settlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]';
        $charge = $settlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]';

        return [
            'BT-1' => $document.'/*[local-name()="ID"]',
            'BT-2' => $document.'/*[local-name()="IssueDateTime"]/*[local-name()="DateTimeString"]',
            'BT-3' => $document.'/*[local-name()="TypeCode"]',
            'BT-5' => $settlement.'/*[local-name()="InvoiceCurrencyCode"]',
            'BT-6' => $settlement.'/*[local-name()="TaxCurrencyCode"]',
            'BT-7' => $tax.'/*[local-name()="TaxPointDate"]/*[local-name()="DateString"]',
            'BT-9' => $settlement.'/*[local-name()="SpecifiedTradePaymentTerms"]/*[local-name()="DueDateDateTime"]/*[local-name()="DateTimeString"]',
            'BT-10' => $agreement.'/*[local-name()="BuyerReference"]',
            'BT-11' => $agreement.'/*[local-name()="SpecifiedProcuringProject"]/*[local-name()="ID"]',
            'BT-12' => $agreement.'/*[local-name()="ContractReferencedDocument"]/*[local-name()="IssuerAssignedID"]',
            'BT-13' => $agreement.'/*[local-name()="BuyerOrderReferencedDocument"]/*[local-name()="IssuerAssignedID"]',
            'BT-19' => $settlement.'/*[local-name()="ReceivableSpecifiedTradeAccountingAccount"]/*[local-name()="ID"]',
            'BT-20' => $settlement.'/*[local-name()="SpecifiedTradePaymentTerms"]/*[local-name()="Description"]',
            'BT-22' => $document.'/*[local-name()="IncludedNote"]/*[local-name()="Content"]',
            'BT-23' => $context.'/*[local-name()="BusinessProcessSpecifiedDocumentContextParameter"]/*[local-name()="ID"]',
            'BT-24' => $context.'/*[local-name()="GuidelineSpecifiedDocumentContextParameter"]/*[local-name()="ID"]',
            'BT-25' => $settlement.'/*[local-name()="InvoiceReferencedDocument"]/*[local-name()="IssuerAssignedID"]',
            'BT-26' => $settlement.'/*[local-name()="InvoiceReferencedDocument"]/*[local-name()="FormattedIssueDateTime"]/*[local-name()="DateTimeString"]',

            'BT-27' => $seller.'/*[local-name()="Name"]',
            'BT-28' => $seller.'/*[local-name()="SpecifiedLegalOrganization"]/*[local-name()="TradingBusinessName"]',
            'BT-29' => $seller.'/*[local-name()="ID"]',
            'BT-30' => $seller.'/*[local-name()="SpecifiedLegalOrganization"]/*[local-name()="ID"]',
            'BT-31' => $seller.'/*[local-name()="SpecifiedTaxRegistration"]/*[local-name()="ID"][@schemeID="VA"]',
            'BT-32' => $seller.'/*[local-name()="SpecifiedTaxRegistration"]/*[local-name()="ID"][@schemeID="FC"]',
            'BT-33' => $seller.'/*[local-name()="Description"]',
            'BT-34' => $seller.'/*[local-name()="URIUniversalCommunication"]/*[local-name()="URIID"]',
            'BT-35' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="LineOne"]',
            'BT-36' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="LineTwo"]',
            'BT-37' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CityName"]',
            'BT-38' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="PostcodeCode"]',
            'BT-39' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CountrySubDivisionName"]',
            'BT-40' => $seller.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CountryID"]',
            'BT-41' => $seller.'/*[local-name()="DefinedTradeContact"]/*[local-name()="PersonName"]',
            'BT-42' => $seller.'/*[local-name()="DefinedTradeContact"]/*[local-name()="TelephoneUniversalCommunication"]/*[local-name()="CompleteNumber"]',
            'BT-43' => $seller.'/*[local-name()="DefinedTradeContact"]/*[local-name()="EmailURIUniversalCommunication"]/*[local-name()="URIID"]',

            'BT-44' => $buyer.'/*[local-name()="Name"]',
            'BT-45' => $buyer.'/*[local-name()="SpecifiedLegalOrganization"]/*[local-name()="TradingBusinessName"]',
            'BT-46' => $buyer.'/*[local-name()="ID"]',
            'BT-47' => $buyer.'/*[local-name()="SpecifiedLegalOrganization"]/*[local-name()="ID"]',
            'BT-48' => $buyer.'/*[local-name()="SpecifiedTaxRegistration"]/*[local-name()="ID"][@schemeID="VA"]',
            'BT-49' => $buyer.'/*[local-name()="URIUniversalCommunication"]/*[local-name()="URIID"]',
            'BT-50' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="LineOne"]',
            'BT-51' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="LineTwo"]',
            'BT-52' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CityName"]',
            'BT-53' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="PostcodeCode"]',
            'BT-54' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CountrySubDivisionName"]',
            'BT-55' => $buyer.'/*[local-name()="PostalTradeAddress"]/*[local-name()="CountryID"]',
            'BT-56' => $buyer.'/*[local-name()="DefinedTradeContact"]/*[local-name()="PersonName"]',
            'BT-57' => $buyer.'/*[local-name()="DefinedTradeContact"]/*[local-name()="TelephoneUniversalCommunication"]/*[local-name()="CompleteNumber"]',
            'BT-58' => $buyer.'/*[local-name()="DefinedTradeContact"]/*[local-name()="EmailURIUniversalCommunication"]/*[local-name()="URIID"]',

            'BT-59' => $settlement.'/*[local-name()="PayeeTradeParty"]/*[local-name()="Name"]',
            'BT-60' => $settlement.'/*[local-name()="PayeeTradeParty"]/*[local-name()="ID"]',
            'BT-61' => $settlement.'/*[local-name()="PayeeTradeParty"]/*[local-name()="SpecifiedLegalOrganization"]/*[local-name()="ID"]',
            'BT-62' => $agreement.'/*[local-name()="SellerTaxRepresentativeTradeParty"]/*[local-name()="Name"]',
            'BT-63' => $agreement.'/*[local-name()="SellerTaxRepresentativeTradeParty"]/*[local-name()="SpecifiedTaxRegistration"]/*[local-name()="ID"]',
            'BT-69' => $agreement.'/*[local-name()="SellerTaxRepresentativeTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="CountryID"]',

            'BT-70' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="Name"]',
            'BT-71' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="ID"]',
            'BT-72' => $delivery.'/*[local-name()="ActualDeliverySupplyChainEvent"]/*[local-name()="OccurrenceDateTime"]/*[local-name()="DateTimeString"]',
            'BT-73' => $settlement.'/*[local-name()="BillingSpecifiedPeriod"]/*[local-name()="StartDateTime"]/*[local-name()="DateTimeString"]',
            'BT-74' => $settlement.'/*[local-name()="BillingSpecifiedPeriod"]/*[local-name()="EndDateTime"]/*[local-name()="DateTimeString"]',
            'BT-75' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="LineOne"]',
            'BT-76' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="LineTwo"]',
            'BT-77' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="CityName"]',
            'BT-78' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="PostcodeCode"]',
            'BT-79' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="CountrySubDivisionName"]',
            'BT-80' => $delivery.'/*[local-name()="ShipToTradeParty"]/*[local-name()="PostalTradeAddress"]/*[local-name()="CountryID"]',

            'BT-81' => $means.'/*[local-name()="TypeCode"]',
            'BT-82' => $means.'/*[local-name()="Information"]',
            'BT-83' => $settlement.'/*[local-name()="PaymentReference"]',
            'BT-84' => $means.'/*[local-name()="PayeePartyCreditorFinancialAccount"]/*[local-name()="IBANID"]',
            'BT-85' => $means.'/*[local-name()="PayeePartyCreditorFinancialAccount"]/*[local-name()="AccountName"]',
            'BT-86' => $means.'/*[local-name()="PayeeSpecifiedCreditorFinancialInstitution"]/*[local-name()="BICID"]',
            'BT-89' => $settlement.'/*[local-name()="SpecifiedTradePaymentTerms"]/*[local-name()="DirectDebitMandateID"]',
            'BT-91' => $means.'/*[local-name()="PayerPartyDebtorFinancialAccount"]/*[local-name()="IBANID"]',

            'BT-92' => $allowance.'/*[local-name()="ActualAmount"]',
            'BT-93' => $allowance.'/*[local-name()="BasisAmount"]',
            'BT-94' => $allowance.'/*[local-name()="CalculationPercent"]',
            'BT-95' => $allowance.'/*[local-name()="CategoryTradeTax"]/*[local-name()="CategoryCode"]',
            'BT-96' => $allowance.'/*[local-name()="CategoryTradeTax"]/*[local-name()="RateApplicablePercent"]',
            'BT-97' => $allowance.'/*[local-name()="Reason"]',
            'BT-98' => $allowance.'/*[local-name()="ReasonCode"]',
            'BT-99' => $charge.'/*[local-name()="ActualAmount"]',
            'BT-100' => $charge.'/*[local-name()="BasisAmount"]',
            'BT-101' => $charge.'/*[local-name()="CalculationPercent"]',
            'BT-102' => $charge.'/*[local-name()="CategoryTradeTax"]/*[local-name()="CategoryCode"]',
            'BT-103' => $charge.'/*[local-name()="CategoryTradeTax"]/*[local-name()="RateApplicablePercent"]',
            'BT-104' => $charge.'/*[local-name()="Reason"]',
            'BT-105' => $charge.'/*[local-name()="ReasonCode"]',

            'BT-106' => $summation.'/*[local-name()="LineTotalAmount"]',
            'BT-107' => $summation.'/*[local-name()="AllowanceTotalAmount"]',
            'BT-108' => $summation.'/*[local-name()="ChargeTotalAmount"]',
            'BT-109' => $summation.'/*[local-name()="TaxBasisTotalAmount"]',
            'BT-110' => $summation.'/*[local-name()="TaxTotalAmount"]',
            'BT-112' => $summation.'/*[local-name()="GrandTotalAmount"]',
            'BT-113' => $summation.'/*[local-name()="TotalPrepaidAmount"]',
            'BT-114' => $summation.'/*[local-name()="RoundingAmount"]',
            'BT-115' => $summation.'/*[local-name()="DuePayableAmount"]',

            'BT-116' => $tax.'/*[local-name()="BasisAmount"]',
            'BT-117' => $tax.'/*[local-name()="CalculatedAmount"]',
            'BT-118' => $tax.'/*[local-name()="CategoryCode"]',
            'BT-119' => $tax.'/*[local-name()="RateApplicablePercent"]',
            'BT-120' => $tax.'/*[local-name()="ExemptionReason"]',
            'BT-121' => $tax.'/*[local-name()="ExemptionReasonCode"]',

            'BT-122' => $agreement.'/*[local-name()="AdditionalReferencedDocument"]/*[local-name()="IssuerAssignedID"]',
            'BT-123' => $agreement.'/*[local-name()="AdditionalReferencedDocument"]/*[local-name()="Name"]',
            'BT-124' => $agreement.'/*[local-name()="AdditionalReferencedDocument"]/*[local-name()="URIID"]',

            'BT-126' => $line.'/*[local-name()="AssociatedDocumentLineDocument"]/*[local-name()="LineID"]',
            'BT-127' => $line.'/*[local-name()="AssociatedDocumentLineDocument"]/*[local-name()="IncludedNote"]/*[local-name()="Content"]',
            'BT-129' => $line.'/*[local-name()="SpecifiedLineTradeDelivery"]/*[local-name()="BilledQuantity"]',
            'BT-130' => $line.'/*[local-name()="SpecifiedLineTradeDelivery"]/*[local-name()="BilledQuantity"]/@unitCode',
            'BT-131' => $lineSettlement.'/*[local-name()="SpecifiedTradeSettlementLineMonetarySummation"]/*[local-name()="LineTotalAmount"]',
            'BT-132' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="BuyerOrderReferencedDocument"]/*[local-name()="LineID"]',
            'BT-133' => $lineSettlement.'/*[local-name()="ReceivableSpecifiedTradeAccountingAccount"]/*[local-name()="ID"]',
            'BT-134' => $lineSettlement.'/*[local-name()="BillingSpecifiedPeriod"]/*[local-name()="StartDateTime"]/*[local-name()="DateTimeString"]',
            'BT-135' => $lineSettlement.'/*[local-name()="BillingSpecifiedPeriod"]/*[local-name()="EndDateTime"]/*[local-name()="DateTimeString"]',
            'BT-136' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]/*[local-name()="ActualAmount"]',
            'BT-137' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]/*[local-name()="BasisAmount"]',
            'BT-138' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]/*[local-name()="CalculationPercent"]',
            'BT-139' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]/*[local-name()="Reason"]',
            'BT-140' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="false"]/*[local-name()="ReasonCode"]',
            'BT-141' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]/*[local-name()="ActualAmount"]',
            'BT-142' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]/*[local-name()="BasisAmount"]',
            'BT-143' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]/*[local-name()="CalculationPercent"]',
            'BT-144' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]/*[local-name()="Reason"]',
            'BT-145' => $lineSettlement.'/*[local-name()="SpecifiedTradeAllowanceCharge"][*[local-name()="ChargeIndicator"]/*[local-name()="Indicator"]="true"]/*[local-name()="ReasonCode"]',
            'BT-146' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="NetPriceProductTradePrice"]/*[local-name()="ChargeAmount"]',
            'BT-147' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="GrossPriceProductTradePrice"]/*[local-name()="AppliedTradeAllowanceCharge"]/*[local-name()="ActualAmount"]',
            'BT-148' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="GrossPriceProductTradePrice"]/*[local-name()="ChargeAmount"]',
            'BT-149' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="NetPriceProductTradePrice"]/*[local-name()="BasisQuantity"]',
            'BT-150' => $line.'/*[local-name()="SpecifiedLineTradeAgreement"]/*[local-name()="NetPriceProductTradePrice"]/*[local-name()="BasisQuantity"]/@unitCode',
            'BT-151' => $lineSettlement.'/*[local-name()="ApplicableTradeTax"]/*[local-name()="CategoryCode"]',
            'BT-152' => $lineSettlement.'/*[local-name()="ApplicableTradeTax"]/*[local-name()="RateApplicablePercent"]',
            'BT-153' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="Name"]',
            'BT-154' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="Description"]',
            'BT-155' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="SellerAssignedID"]',
            'BT-156' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="BuyerAssignedID"]',
            'BT-157' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="GlobalID"]',
            'BT-158' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="DesignatedProductClassification"]/*[local-name()="ClassCode"]',
            'BT-159' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="OriginTradeCountry"]/*[local-name()="ID"]',
            'BT-160' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="ApplicableProductCharacteristic"]/*[local-name()="Description"]',
            'BT-161' => $line.'/*[local-name()="SpecifiedTradeProduct"]/*[local-name()="ApplicableProductCharacteristic"]/*[local-name()="Value"]',
        ];
    }

    /**
     * Terms whose written form differs between the two syntaxes.
     *
     * A date is `2026-05-18` in UBL and `20260518` in CII. The business term
     * is the same and the comparison has to say so, rather than reporting a
     * difference that is the whole reason two serializers exist.
     *
     * @return list<string>
     */
    public static function datesInCompactForm(): array
    {
        return ['BT-2', 'BT-7', 'BT-9', 'BT-26', 'BT-72', 'BT-73', 'BT-74', 'BT-134', 'BT-135'];
    }
}
