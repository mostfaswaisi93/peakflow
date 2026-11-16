<?php

declare(strict_types=1);

namespace App\Exchange\Semantics;

/**
 * The EN 16931 business TERMS - BT-1 to BT-161.
 *
 * ## One registry, and the mapping document agrees with it
 *
 * Every term the application populates is addressed by its `BT` number here
 * and nowhere else, so "where does BT-112 come from" has one answer. The
 * serializers reference these cases rather than element paths, which is what
 * makes a second syntax a second serializer rather than a second invoice
 * system.
 *
 * `docs/standards/en16931-mapping.md` carries what this file cannot: the
 * internal source of each term and the reason for each deliberate omission.
 * `Exchange` checks assert the two agree in both directions - every term
 * here appears there with the same cardinality, and every term there exists
 * here - because a mapping document that has drifted from the code is worse
 * than none: it is consulted, and it is wrong.
 *
 * ## The gaps are real
 *
 * There is no BT-4. EN 16931 does not define one, and a registry that
 * invented it to close the sequence would be a registry nobody could check
 * against the standard.
 */
enum BusinessTerm: string
{
    case InvoiceNumber = 'BT-1';
    case IssueDate = 'BT-2';
    case InvoiceTypeCode = 'BT-3';
    case InvoiceCurrencyCode = 'BT-5';
    case VatAccountingCurrencyCode = 'BT-6';
    case TaxPointDate = 'BT-7';
    case TaxPointDateCode = 'BT-8';
    case PaymentDueDate = 'BT-9';
    case BuyerReference = 'BT-10';
    case ProjectReference = 'BT-11';
    case ContractReference = 'BT-12';
    case PurchaseOrderReference = 'BT-13';
    case SalesOrderReference = 'BT-14';
    case ReceivingAdviceReference = 'BT-15';
    case DespatchAdviceReference = 'BT-16';
    case TenderOrLotReference = 'BT-17';
    case InvoicedObjectIdentifier = 'BT-18';
    case BuyerAccountingReference = 'BT-19';
    case PaymentTerms = 'BT-20';
    case InvoiceNoteSubjectCode = 'BT-21';
    case InvoiceNote = 'BT-22';
    case BusinessProcessType = 'BT-23';
    case SpecificationIdentifier = 'BT-24';
    case PrecedingInvoiceReference = 'BT-25';
    case PrecedingInvoiceIssueDate = 'BT-26';
    case SellerName = 'BT-27';
    case SellerTradingName = 'BT-28';
    case SellerIdentifier = 'BT-29';
    case SellerLegalRegistrationIdentifier = 'BT-30';
    case SellerVatIdentifier = 'BT-31';
    case SellerTaxRegistrationIdentifier = 'BT-32';
    case SellerAdditionalLegalInformation = 'BT-33';
    case SellerElectronicAddress = 'BT-34';
    case SellerAddressLine1 = 'BT-35';
    case SellerAddressLine2 = 'BT-36';
    case SellerCity = 'BT-37';
    case SellerPostCode = 'BT-38';
    case SellerCountrySubdivision = 'BT-39';
    case SellerCountryCode = 'BT-40';
    case SellerContactPoint = 'BT-41';
    case SellerContactTelephone = 'BT-42';
    case SellerContactEmail = 'BT-43';
    case BuyerName = 'BT-44';
    case BuyerTradingName = 'BT-45';
    case BuyerIdentifier = 'BT-46';
    case BuyerLegalRegistrationIdentifier = 'BT-47';
    case BuyerVatIdentifier = 'BT-48';
    case BuyerElectronicAddress = 'BT-49';
    case BuyerAddressLine1 = 'BT-50';
    case BuyerAddressLine2 = 'BT-51';
    case BuyerCity = 'BT-52';
    case BuyerPostCode = 'BT-53';
    case BuyerCountrySubdivision = 'BT-54';
    case BuyerCountryCode = 'BT-55';
    case BuyerContactPoint = 'BT-56';
    case BuyerContactTelephone = 'BT-57';
    case BuyerContactEmail = 'BT-58';
    case PayeeName = 'BT-59';
    case PayeeIdentifier = 'BT-60';
    case PayeeLegalRegistrationIdentifier = 'BT-61';
    case TaxRepresentativeName = 'BT-62';
    case TaxRepresentativeVatIdentifier = 'BT-63';
    case TaxRepresentativeAddressLine1 = 'BT-64';
    case TaxRepresentativeAddressLine2 = 'BT-65';
    case TaxRepresentativeCity = 'BT-66';
    case TaxRepresentativePostCode = 'BT-67';
    case TaxRepresentativeCountrySubdivision = 'BT-68';
    case TaxRepresentativeCountryCode = 'BT-69';
    case DeliverToPartyName = 'BT-70';
    case DeliverToLocationIdentifier = 'BT-71';
    case ActualDeliveryDate = 'BT-72';
    case InvoicingPeriodStartDate = 'BT-73';
    case InvoicingPeriodEndDate = 'BT-74';
    case DeliverToAddressLine1 = 'BT-75';
    case DeliverToAddressLine2 = 'BT-76';
    case DeliverToCity = 'BT-77';
    case DeliverToPostCode = 'BT-78';
    case DeliverToCountrySubdivision = 'BT-79';
    case DeliverToCountryCode = 'BT-80';
    case PaymentMeansTypeCode = 'BT-81';
    case PaymentMeansText = 'BT-82';
    case RemittanceInformation = 'BT-83';
    case PaymentAccountIdentifier = 'BT-84';
    case PaymentAccountName = 'BT-85';
    case PaymentServiceProviderIdentifier = 'BT-86';
    case PaymentCardPrimaryAccountNumber = 'BT-87';
    case PaymentCardHolderName = 'BT-88';
    case MandateReferenceIdentifier = 'BT-89';
    case BankAssignedCreditorIdentifier = 'BT-90';
    case DebitedAccountIdentifier = 'BT-91';
    case DocumentAllowanceAmount = 'BT-92';
    case DocumentAllowanceBaseAmount = 'BT-93';
    case DocumentAllowancePercentage = 'BT-94';
    case DocumentAllowanceVatCategoryCode = 'BT-95';
    case DocumentAllowanceVatRate = 'BT-96';
    case DocumentAllowanceReason = 'BT-97';
    case DocumentAllowanceReasonCode = 'BT-98';
    case DocumentChargeAmount = 'BT-99';
    case DocumentChargeBaseAmount = 'BT-100';
    case DocumentChargePercentage = 'BT-101';
    case DocumentChargeVatCategoryCode = 'BT-102';
    case DocumentChargeVatRate = 'BT-103';
    case DocumentChargeReason = 'BT-104';
    case DocumentChargeReasonCode = 'BT-105';
    case SumOfLineNetAmounts = 'BT-106';
    case SumOfAllowances = 'BT-107';
    case SumOfCharges = 'BT-108';
    case TotalWithoutVat = 'BT-109';
    case TotalVatAmount = 'BT-110';
    case TotalVatAmountInAccountingCurrency = 'BT-111';
    case TotalWithVat = 'BT-112';
    case PaidAmount = 'BT-113';
    case RoundingAmount = 'BT-114';
    case AmountDueForPayment = 'BT-115';
    case VatCategoryTaxableAmount = 'BT-116';
    case VatCategoryTaxAmount = 'BT-117';
    case VatCategoryCode = 'BT-118';
    case VatCategoryRate = 'BT-119';
    case VatExemptionReasonText = 'BT-120';
    case VatExemptionReasonCode = 'BT-121';
    case SupportingDocumentReference = 'BT-122';
    case SupportingDocumentDescription = 'BT-123';
    case ExternalDocumentLocation = 'BT-124';
    case AttachedDocument = 'BT-125';
    case LineIdentifier = 'BT-126';
    case LineNote = 'BT-127';
    case LineObjectIdentifier = 'BT-128';
    case InvoicedQuantity = 'BT-129';
    case InvoicedQuantityUnitCode = 'BT-130';
    case LineNetAmount = 'BT-131';
    case ReferencedPurchaseOrderLineReference = 'BT-132';
    case LineBuyerAccountingReference = 'BT-133';
    case LinePeriodStartDate = 'BT-134';
    case LinePeriodEndDate = 'BT-135';
    case LineAllowanceAmount = 'BT-136';
    case LineAllowanceBaseAmount = 'BT-137';
    case LineAllowancePercentage = 'BT-138';
    case LineAllowanceReason = 'BT-139';
    case LineAllowanceReasonCode = 'BT-140';
    case LineChargeAmount = 'BT-141';
    case LineChargeBaseAmount = 'BT-142';
    case LineChargePercentage = 'BT-143';
    case LineChargeReason = 'BT-144';
    case LineChargeReasonCode = 'BT-145';
    case ItemNetPrice = 'BT-146';
    case ItemPriceDiscount = 'BT-147';
    case ItemGrossPrice = 'BT-148';
    case ItemPriceBaseQuantity = 'BT-149';
    case ItemPriceBaseQuantityUnitCode = 'BT-150';
    case LineVatCategoryCode = 'BT-151';
    case LineVatRate = 'BT-152';
    case ItemName = 'BT-153';
    case ItemDescription = 'BT-154';
    case ItemSellerIdentifier = 'BT-155';
    case ItemBuyerIdentifier = 'BT-156';
    case ItemStandardIdentifier = 'BT-157';
    case ItemClassificationIdentifier = 'BT-158';
    case ItemCountryOfOrigin = 'BT-159';
    case ItemAttributeName = 'BT-160';
    case ItemAttributeValue = 'BT-161';

    /** The human name, this term's cardinality within its group, and that group. */
    public function definition(): array
    {
        return match ($this) {
            self::InvoiceNumber => ['Invoice number', Cardinality::One, null],
            self::IssueDate => ['Invoice issue date', Cardinality::One, null],
            self::InvoiceTypeCode => ['Invoice type code', Cardinality::One, null],
            self::InvoiceCurrencyCode => ['Invoice currency code', Cardinality::One, null],
            self::VatAccountingCurrencyCode => ['VAT accounting currency code', Cardinality::ZeroOrOne, null],
            self::TaxPointDate => ['Value added tax point date', Cardinality::ZeroOrOne, null],
            self::TaxPointDateCode => ['Value added tax point date code', Cardinality::ZeroOrOne, null],
            self::PaymentDueDate => ['Payment due date', Cardinality::ZeroOrOne, null],
            self::BuyerReference => ['Buyer reference', Cardinality::ZeroOrOne, null],
            self::ProjectReference => ['Project reference', Cardinality::ZeroOrOne, null],
            self::ContractReference => ['Contract reference', Cardinality::ZeroOrOne, null],
            self::PurchaseOrderReference => ['Purchase order reference', Cardinality::ZeroOrOne, null],
            self::SalesOrderReference => ['Sales order reference', Cardinality::ZeroOrOne, null],
            self::ReceivingAdviceReference => ['Receiving advice reference', Cardinality::ZeroOrOne, null],
            self::DespatchAdviceReference => ['Despatch advice reference', Cardinality::ZeroOrOne, null],
            self::TenderOrLotReference => ['Tender or lot reference', Cardinality::ZeroOrOne, null],
            self::InvoicedObjectIdentifier => ['Invoiced object identifier', Cardinality::ZeroOrOne, null],
            self::BuyerAccountingReference => ['Buyer accounting reference', Cardinality::ZeroOrOne, null],
            self::PaymentTerms => ['Payment terms', Cardinality::ZeroOrOne, null],
            self::InvoiceNoteSubjectCode => ['Invoice note subject code', Cardinality::ZeroOrOne, BusinessGroup::InvoiceNote],
            self::InvoiceNote => ['Invoice note', Cardinality::One, BusinessGroup::InvoiceNote],
            self::BusinessProcessType => ['Business process type', Cardinality::ZeroOrOne, BusinessGroup::ProcessControl],
            self::SpecificationIdentifier => ['Specification identifier', Cardinality::One, BusinessGroup::ProcessControl],
            self::PrecedingInvoiceReference => ['Preceding Invoice reference', Cardinality::One, BusinessGroup::PrecedingInvoiceReference],
            self::PrecedingInvoiceIssueDate => ['Preceding Invoice issue date', Cardinality::ZeroOrOne, BusinessGroup::PrecedingInvoiceReference],
            self::SellerName => ['Seller name', Cardinality::One, BusinessGroup::Seller],
            self::SellerTradingName => ['Seller trading name', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerIdentifier => ['Seller identifier', Cardinality::ZeroOrMany, BusinessGroup::Seller],
            self::SellerLegalRegistrationIdentifier => ['Seller legal registration identifier', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerVatIdentifier => ['Seller VAT identifier', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerTaxRegistrationIdentifier => ['Seller tax registration identifier', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerAdditionalLegalInformation => ['Seller additional legal information', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerElectronicAddress => ['Seller electronic address', Cardinality::ZeroOrOne, BusinessGroup::Seller],
            self::SellerAddressLine1 => ['Seller address line 1', Cardinality::ZeroOrOne, BusinessGroup::SellerPostalAddress],
            self::SellerAddressLine2 => ['Seller address line 2', Cardinality::ZeroOrOne, BusinessGroup::SellerPostalAddress],
            self::SellerCity => ['Seller city', Cardinality::ZeroOrOne, BusinessGroup::SellerPostalAddress],
            self::SellerPostCode => ['Seller post code', Cardinality::ZeroOrOne, BusinessGroup::SellerPostalAddress],
            self::SellerCountrySubdivision => ['Seller country subdivision', Cardinality::ZeroOrOne, BusinessGroup::SellerPostalAddress],
            self::SellerCountryCode => ['Seller country code', Cardinality::One, BusinessGroup::SellerPostalAddress],
            self::SellerContactPoint => ['Seller contact point', Cardinality::ZeroOrOne, BusinessGroup::SellerContact],
            self::SellerContactTelephone => ['Seller contact telephone number', Cardinality::ZeroOrOne, BusinessGroup::SellerContact],
            self::SellerContactEmail => ['Seller contact email address', Cardinality::ZeroOrOne, BusinessGroup::SellerContact],
            self::BuyerName => ['Buyer name', Cardinality::One, BusinessGroup::Buyer],
            self::BuyerTradingName => ['Buyer trading name', Cardinality::ZeroOrOne, BusinessGroup::Buyer],
            self::BuyerIdentifier => ['Buyer identifier', Cardinality::ZeroOrOne, BusinessGroup::Buyer],
            self::BuyerLegalRegistrationIdentifier => ['Buyer legal registration identifier', Cardinality::ZeroOrOne, BusinessGroup::Buyer],
            self::BuyerVatIdentifier => ['Buyer VAT identifier', Cardinality::ZeroOrOne, BusinessGroup::Buyer],
            self::BuyerElectronicAddress => ['Buyer electronic address', Cardinality::ZeroOrOne, BusinessGroup::Buyer],
            self::BuyerAddressLine1 => ['Buyer address line 1', Cardinality::ZeroOrOne, BusinessGroup::BuyerPostalAddress],
            self::BuyerAddressLine2 => ['Buyer address line 2', Cardinality::ZeroOrOne, BusinessGroup::BuyerPostalAddress],
            self::BuyerCity => ['Buyer city', Cardinality::ZeroOrOne, BusinessGroup::BuyerPostalAddress],
            self::BuyerPostCode => ['Buyer post code', Cardinality::ZeroOrOne, BusinessGroup::BuyerPostalAddress],
            self::BuyerCountrySubdivision => ['Buyer country subdivision', Cardinality::ZeroOrOne, BusinessGroup::BuyerPostalAddress],
            self::BuyerCountryCode => ['Buyer country code', Cardinality::One, BusinessGroup::BuyerPostalAddress],
            self::BuyerContactPoint => ['Buyer contact point', Cardinality::ZeroOrOne, BusinessGroup::BuyerContact],
            self::BuyerContactTelephone => ['Buyer contact telephone number', Cardinality::ZeroOrOne, BusinessGroup::BuyerContact],
            self::BuyerContactEmail => ['Buyer contact email address', Cardinality::ZeroOrOne, BusinessGroup::BuyerContact],
            self::PayeeName => ['Payee name', Cardinality::One, BusinessGroup::Payee],
            self::PayeeIdentifier => ['Payee identifier', Cardinality::ZeroOrOne, BusinessGroup::Payee],
            self::PayeeLegalRegistrationIdentifier => ['Payee legal registration identifier', Cardinality::ZeroOrOne, BusinessGroup::Payee],
            self::TaxRepresentativeName => ['Seller tax representative name', Cardinality::One, BusinessGroup::SellerTaxRepresentativeParty],
            self::TaxRepresentativeVatIdentifier => ['Seller tax representative VAT identifier', Cardinality::One, BusinessGroup::SellerTaxRepresentativeParty],
            self::TaxRepresentativeAddressLine1 => ['Tax representative address line 1', Cardinality::ZeroOrOne, BusinessGroup::TaxRepresentativePostalAddress],
            self::TaxRepresentativeAddressLine2 => ['Tax representative address line 2', Cardinality::ZeroOrOne, BusinessGroup::TaxRepresentativePostalAddress],
            self::TaxRepresentativeCity => ['Tax representative city', Cardinality::ZeroOrOne, BusinessGroup::TaxRepresentativePostalAddress],
            self::TaxRepresentativePostCode => ['Tax representative post code', Cardinality::ZeroOrOne, BusinessGroup::TaxRepresentativePostalAddress],
            self::TaxRepresentativeCountrySubdivision => ['Tax representative country subdivision', Cardinality::ZeroOrOne, BusinessGroup::TaxRepresentativePostalAddress],
            self::TaxRepresentativeCountryCode => ['Tax representative country code', Cardinality::One, BusinessGroup::TaxRepresentativePostalAddress],
            self::DeliverToPartyName => ['Deliver to party name', Cardinality::ZeroOrOne, BusinessGroup::DeliveryInformation],
            self::DeliverToLocationIdentifier => ['Deliver to location identifier', Cardinality::ZeroOrOne, BusinessGroup::DeliveryInformation],
            self::ActualDeliveryDate => ['Actual delivery date', Cardinality::ZeroOrOne, BusinessGroup::DeliveryInformation],
            self::InvoicingPeriodStartDate => ['Invoicing period start date', Cardinality::ZeroOrOne, BusinessGroup::InvoicingPeriod],
            self::InvoicingPeriodEndDate => ['Invoicing period end date', Cardinality::ZeroOrOne, BusinessGroup::InvoicingPeriod],
            self::DeliverToAddressLine1 => ['Deliver to address line 1', Cardinality::ZeroOrOne, BusinessGroup::DeliverToAddress],
            self::DeliverToAddressLine2 => ['Deliver to address line 2', Cardinality::ZeroOrOne, BusinessGroup::DeliverToAddress],
            self::DeliverToCity => ['Deliver to city', Cardinality::ZeroOrOne, BusinessGroup::DeliverToAddress],
            self::DeliverToPostCode => ['Deliver to post code', Cardinality::ZeroOrOne, BusinessGroup::DeliverToAddress],
            self::DeliverToCountrySubdivision => ['Deliver to country subdivision', Cardinality::ZeroOrOne, BusinessGroup::DeliverToAddress],
            self::DeliverToCountryCode => ['Deliver to country code', Cardinality::One, BusinessGroup::DeliverToAddress],
            self::PaymentMeansTypeCode => ['Payment means type code', Cardinality::One, BusinessGroup::PaymentInstructions],
            self::PaymentMeansText => ['Payment means text', Cardinality::ZeroOrOne, BusinessGroup::PaymentInstructions],
            self::RemittanceInformation => ['Remittance information', Cardinality::ZeroOrOne, BusinessGroup::PaymentInstructions],
            self::PaymentAccountIdentifier => ['Payment account identifier', Cardinality::One, BusinessGroup::CreditTransfer],
            self::PaymentAccountName => ['Payment account name', Cardinality::ZeroOrOne, BusinessGroup::CreditTransfer],
            self::PaymentServiceProviderIdentifier => ['Payment service provider identifier', Cardinality::ZeroOrOne, BusinessGroup::CreditTransfer],
            self::PaymentCardPrimaryAccountNumber => ['Payment card primary account number', Cardinality::One, BusinessGroup::PaymentCardInformation],
            self::PaymentCardHolderName => ['Payment card holder name', Cardinality::ZeroOrOne, BusinessGroup::PaymentCardInformation],
            self::MandateReferenceIdentifier => ['Mandate reference identifier', Cardinality::ZeroOrOne, BusinessGroup::DirectDebit],
            self::BankAssignedCreditorIdentifier => ['Bank assigned creditor identifier', Cardinality::ZeroOrOne, BusinessGroup::DirectDebit],
            self::DebitedAccountIdentifier => ['Debited account identifier', Cardinality::ZeroOrOne, BusinessGroup::DirectDebit],
            self::DocumentAllowanceAmount => ['Document level allowance amount', Cardinality::One, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowanceBaseAmount => ['Document level allowance base amount', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowancePercentage => ['Document level allowance percentage', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowanceVatCategoryCode => ['Document level allowance VAT category code', Cardinality::One, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowanceVatRate => ['Document level allowance VAT rate', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowanceReason => ['Document level allowance reason', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelAllowances],
            self::DocumentAllowanceReasonCode => ['Document level allowance reason code', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelAllowances],
            self::DocumentChargeAmount => ['Document level charge amount', Cardinality::One, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargeBaseAmount => ['Document level charge base amount', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargePercentage => ['Document level charge percentage', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargeVatCategoryCode => ['Document level charge VAT category code', Cardinality::One, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargeVatRate => ['Document level charge VAT rate', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargeReason => ['Document level charge reason', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelCharges],
            self::DocumentChargeReasonCode => ['Document level charge reason code', Cardinality::ZeroOrOne, BusinessGroup::DocumentLevelCharges],
            self::SumOfLineNetAmounts => ['Sum of Invoice line net amount', Cardinality::One, BusinessGroup::DocumentTotals],
            self::SumOfAllowances => ['Sum of allowances on document level', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::SumOfCharges => ['Sum of charges on document level', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::TotalWithoutVat => ['Invoice total amount without VAT', Cardinality::One, BusinessGroup::DocumentTotals],
            self::TotalVatAmount => ['Invoice total VAT amount', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::TotalVatAmountInAccountingCurrency => ['Invoice total VAT amount in accounting currency', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::TotalWithVat => ['Invoice total amount with VAT', Cardinality::One, BusinessGroup::DocumentTotals],
            self::PaidAmount => ['Paid amount', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::RoundingAmount => ['Rounding amount', Cardinality::ZeroOrOne, BusinessGroup::DocumentTotals],
            self::AmountDueForPayment => ['Amount due for payment', Cardinality::One, BusinessGroup::DocumentTotals],
            self::VatCategoryTaxableAmount => ['VAT category taxable amount', Cardinality::One, BusinessGroup::VatBreakdown],
            self::VatCategoryTaxAmount => ['VAT category tax amount', Cardinality::One, BusinessGroup::VatBreakdown],
            self::VatCategoryCode => ['VAT category code', Cardinality::One, BusinessGroup::VatBreakdown],
            self::VatCategoryRate => ['VAT category rate', Cardinality::ZeroOrOne, BusinessGroup::VatBreakdown],
            self::VatExemptionReasonText => ['VAT exemption reason text', Cardinality::ZeroOrOne, BusinessGroup::VatBreakdown],
            self::VatExemptionReasonCode => ['VAT exemption reason code', Cardinality::ZeroOrOne, BusinessGroup::VatBreakdown],
            self::SupportingDocumentReference => ['Supporting document reference', Cardinality::One, BusinessGroup::AdditionalSupportingDocuments],
            self::SupportingDocumentDescription => ['Supporting document description', Cardinality::ZeroOrOne, BusinessGroup::AdditionalSupportingDocuments],
            self::ExternalDocumentLocation => ['External document location', Cardinality::ZeroOrOne, BusinessGroup::AdditionalSupportingDocuments],
            self::AttachedDocument => ['Attached document', Cardinality::ZeroOrOne, BusinessGroup::AdditionalSupportingDocuments],
            self::LineIdentifier => ['Invoice line identifier', Cardinality::One, BusinessGroup::InvoiceLine],
            self::LineNote => ['Invoice line note', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLine],
            self::LineObjectIdentifier => ['Invoice line object identifier', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLine],
            self::InvoicedQuantity => ['Invoiced quantity', Cardinality::One, BusinessGroup::InvoiceLine],
            self::InvoicedQuantityUnitCode => ['Invoiced quantity unit of measure code', Cardinality::One, BusinessGroup::InvoiceLine],
            self::LineNetAmount => ['Invoice line net amount', Cardinality::One, BusinessGroup::InvoiceLine],
            self::ReferencedPurchaseOrderLineReference => ['Referenced purchase order line reference', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLine],
            self::LineBuyerAccountingReference => ['Invoice line Buyer accounting reference', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLine],
            self::LinePeriodStartDate => ['Invoice line period start date', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLinePeriod],
            self::LinePeriodEndDate => ['Invoice line period end date', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLinePeriod],
            self::LineAllowanceAmount => ['Invoice line allowance amount', Cardinality::One, BusinessGroup::InvoiceLineAllowances],
            self::LineAllowanceBaseAmount => ['Invoice line allowance base amount', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineAllowances],
            self::LineAllowancePercentage => ['Invoice line allowance percentage', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineAllowances],
            self::LineAllowanceReason => ['Invoice line allowance reason', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineAllowances],
            self::LineAllowanceReasonCode => ['Invoice line allowance reason code', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineAllowances],
            self::LineChargeAmount => ['Invoice line charge amount', Cardinality::One, BusinessGroup::InvoiceLineCharges],
            self::LineChargeBaseAmount => ['Invoice line charge base amount', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineCharges],
            self::LineChargePercentage => ['Invoice line charge percentage', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineCharges],
            self::LineChargeReason => ['Invoice line charge reason', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineCharges],
            self::LineChargeReasonCode => ['Invoice line charge reason code', Cardinality::ZeroOrOne, BusinessGroup::InvoiceLineCharges],
            self::ItemNetPrice => ['Item net price', Cardinality::One, BusinessGroup::PriceDetails],
            self::ItemPriceDiscount => ['Item price discount', Cardinality::ZeroOrOne, BusinessGroup::PriceDetails],
            self::ItemGrossPrice => ['Item gross price', Cardinality::ZeroOrOne, BusinessGroup::PriceDetails],
            self::ItemPriceBaseQuantity => ['Item price base quantity', Cardinality::ZeroOrOne, BusinessGroup::PriceDetails],
            self::ItemPriceBaseQuantityUnitCode => ['Item price base quantity unit of measure code', Cardinality::ZeroOrOne, BusinessGroup::PriceDetails],
            self::LineVatCategoryCode => ['Invoiced item VAT category code', Cardinality::One, BusinessGroup::LineVatInformation],
            self::LineVatRate => ['Invoiced item VAT rate', Cardinality::ZeroOrOne, BusinessGroup::LineVatInformation],
            self::ItemName => ['Item name', Cardinality::One, BusinessGroup::ItemInformation],
            self::ItemDescription => ['Item description', Cardinality::ZeroOrOne, BusinessGroup::ItemInformation],
            self::ItemSellerIdentifier => ['Item Seller\'s identifier', Cardinality::ZeroOrOne, BusinessGroup::ItemInformation],
            self::ItemBuyerIdentifier => ['Item Buyer\'s identifier', Cardinality::ZeroOrOne, BusinessGroup::ItemInformation],
            self::ItemStandardIdentifier => ['Item standard identifier', Cardinality::ZeroOrOne, BusinessGroup::ItemInformation],
            self::ItemClassificationIdentifier => ['Item classification identifier', Cardinality::ZeroOrMany, BusinessGroup::ItemInformation],
            self::ItemCountryOfOrigin => ['Item country of origin', Cardinality::ZeroOrOne, BusinessGroup::ItemInformation],
            self::ItemAttributeName => ['Item attribute name', Cardinality::One, BusinessGroup::ItemAttributes],
            self::ItemAttributeValue => ['Item attribute value', Cardinality::One, BusinessGroup::ItemAttributes],
        };
    }

    public function label(): string
    {
        return (string) $this->definition()[0];
    }

    public function cardinality(): Cardinality
    {
        return $this->definition()[1];
    }

    /**
     * The group this term belongs to, or null when it sits at the document
     * root (BT-1 to BT-20 and BT-23, which EN 16931 lists outside any BG).
     */
    public function group(): ?BusinessGroup
    {
        return $this->definition()[2];
    }

    public static function fromId(string $id): self
    {
        return self::from(strtoupper(trim($id)));
    }
}
