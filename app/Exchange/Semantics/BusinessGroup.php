<?php

declare(strict_types=1);

namespace App\Exchange\Semantics;

/**
 * The EN 16931 business GROUPS - BG-1 to BG-32.
 *
 * ## Why the groups are modelled and not just the terms
 *
 * EN 16931's cardinality is mostly a property of a group, not of a term.
 * `BT-84` (the payment account identifier) is mandatory - inside `BG-17`,
 * a credit transfer, which is itself optional. Flattening that to "the IBAN
 * is optional" loses the rule that a credit transfer without an account
 * identifier is not a credit transfer, and that rule is the one a recipient's
 * validator enforces.
 *
 * So the model is a tree of group instances, each carrying its own terms,
 * and the cardinality check walks it. The parent is declared here, which is
 * what makes the walk possible and what stops a serializer putting a
 * delivery address inside the payment instructions.
 */
enum BusinessGroup: string
{
    case InvoiceNote = 'BG-1';
    case ProcessControl = 'BG-2';
    case PrecedingInvoiceReference = 'BG-3';
    case Seller = 'BG-4';
    case SellerPostalAddress = 'BG-5';
    case SellerContact = 'BG-6';
    case Buyer = 'BG-7';
    case BuyerPostalAddress = 'BG-8';
    case BuyerContact = 'BG-9';
    case Payee = 'BG-10';
    case SellerTaxRepresentativeParty = 'BG-11';
    case TaxRepresentativePostalAddress = 'BG-12';
    case DeliveryInformation = 'BG-13';
    case InvoicingPeriod = 'BG-14';
    case DeliverToAddress = 'BG-15';
    case PaymentInstructions = 'BG-16';
    case CreditTransfer = 'BG-17';
    case PaymentCardInformation = 'BG-18';
    case DirectDebit = 'BG-19';
    case DocumentLevelAllowances = 'BG-20';
    case DocumentLevelCharges = 'BG-21';
    case DocumentTotals = 'BG-22';
    case VatBreakdown = 'BG-23';
    case AdditionalSupportingDocuments = 'BG-24';
    case InvoiceLine = 'BG-25';
    case InvoiceLinePeriod = 'BG-26';
    case InvoiceLineAllowances = 'BG-27';
    case InvoiceLineCharges = 'BG-28';
    case PriceDetails = 'BG-29';
    case LineVatInformation = 'BG-30';
    case ItemInformation = 'BG-31';
    case ItemAttributes = 'BG-32';

    /** The human name, this group's own cardinality, and the group it sits in. */
    public function definition(): array
    {
        return match ($this) {
            self::InvoiceNote => ['Invoice note', Cardinality::ZeroOrMany, null],
            self::ProcessControl => ['Process control', Cardinality::One, null],
            self::PrecedingInvoiceReference => ['Preceding invoice reference', Cardinality::ZeroOrMany, null],
            self::Seller => ['Seller', Cardinality::One, null],
            self::SellerPostalAddress => ['Seller postal address', Cardinality::One, self::Seller],
            self::SellerContact => ['Seller contact', Cardinality::ZeroOrOne, self::Seller],
            self::Buyer => ['Buyer', Cardinality::One, null],
            self::BuyerPostalAddress => ['Buyer postal address', Cardinality::One, self::Buyer],
            self::BuyerContact => ['Buyer contact', Cardinality::ZeroOrOne, self::Buyer],
            self::Payee => ['Payee', Cardinality::ZeroOrOne, null],
            self::SellerTaxRepresentativeParty => ['Seller tax representative party', Cardinality::ZeroOrOne, null],
            self::TaxRepresentativePostalAddress => ['Seller tax representative postal address', Cardinality::One, self::SellerTaxRepresentativeParty],
            self::DeliveryInformation => ['Delivery information', Cardinality::ZeroOrOne, null],
            self::InvoicingPeriod => ['Invoicing period', Cardinality::ZeroOrOne, null],
            self::DeliverToAddress => ['Deliver to address', Cardinality::ZeroOrOne, self::DeliveryInformation],
            self::PaymentInstructions => ['Payment instructions', Cardinality::ZeroOrOne, null],
            self::CreditTransfer => ['Credit transfer', Cardinality::ZeroOrMany, self::PaymentInstructions],
            self::PaymentCardInformation => ['Payment card information', Cardinality::ZeroOrOne, self::PaymentInstructions],
            self::DirectDebit => ['Direct debit', Cardinality::ZeroOrOne, self::PaymentInstructions],
            self::DocumentLevelAllowances => ['Document level allowances', Cardinality::ZeroOrMany, null],
            self::DocumentLevelCharges => ['Document level charges', Cardinality::ZeroOrMany, null],
            self::DocumentTotals => ['Document totals', Cardinality::One, null],
            self::VatBreakdown => ['VAT breakdown', Cardinality::OneOrMany, null],
            self::AdditionalSupportingDocuments => ['Additional supporting documents', Cardinality::ZeroOrMany, null],
            self::InvoiceLine => ['Invoice line', Cardinality::OneOrMany, null],
            self::InvoiceLinePeriod => ['Invoice line period', Cardinality::ZeroOrOne, self::InvoiceLine],
            self::InvoiceLineAllowances => ['Invoice line allowances', Cardinality::ZeroOrMany, self::InvoiceLine],
            self::InvoiceLineCharges => ['Invoice line charges', Cardinality::ZeroOrMany, self::InvoiceLine],
            self::PriceDetails => ['Price details', Cardinality::One, self::InvoiceLine],
            self::LineVatInformation => ['Line VAT information', Cardinality::One, self::InvoiceLine],
            self::ItemInformation => ['Item information', Cardinality::One, self::InvoiceLine],
            self::ItemAttributes => ['Item attributes', Cardinality::ZeroOrMany, self::ItemInformation],
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

    /** The group this one sits inside, or null at the document root. */
    public function parent(): ?self
    {
        return $this->definition()[2];
    }

    /** @return list<self> */
    public static function childrenOf(?self $parent): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $group): bool => $group->parent() === $parent,
        ));
    }

    /** @return list<BusinessTerm> */
    public function terms(): array
    {
        return array_values(array_filter(
            BusinessTerm::cases(),
            fn (BusinessTerm $term): bool => $term->group() === $this,
        ));
    }
}
