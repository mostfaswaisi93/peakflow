<?php

declare(strict_types=1);

namespace App\Exchange\Readers;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Support\Xml\SafeXmlReader;

/**
 * UBL 2.1, into the semantic model - PF-141.
 *
 * ## It reads into the MODEL, not into a bill
 *
 * Which is the point of having a semantic model in the middle. An inbound
 * document is a file a stranger wrote: it is read into
 * {@see SemanticInvoice}, validated there against the EN 16931 rules and the
 * receiving profile's, and only then mapped to anything of ours. A reader
 * that wrote straight into `bills` would have to validate afterwards, and
 * "afterwards" is a half-imported document.
 *
 * ## Prefixes are not namespaces
 *
 * Every expression here matches on `local-name()`. A sender may use any
 * prefix they like - `ns2:Invoice` is as valid as `Invoice`, and several
 * large finance systems emit `ns0:` throughout - so a selector bound to the
 * prefixes we happen to emit reads our own documents and nobody else's.
 *
 * ## What it deliberately does not do
 *
 * Guess. A missing element is a missing term, not a default: if the document
 * carries no `cbc:DocumentCurrencyCode` the model has no BT-5, the
 * cardinality rules say so, and the document is rejected with the rule
 * number. A reader that filled in "probably SAR" would turn a supplier's
 * mistake into our own, and the first sign of it would be a payment in the
 * wrong currency.
 */
final class UblReader
{
    /**
     * Read a UBL invoice or credit note.
     *
     * @throws \App\Support\Xml\MalformedXml when the bytes are not XML or
     *                                       carry a DOCTYPE
     * @throws NotABillingDocument when the document is valid UBL of another
     *                             kind
     */
    public function read(string $xml): SemanticInvoice
    {
        $document = SafeXmlReader::parse($xml);
        $selector = SafeXmlReader::selector($document);

        $root = $document->documentElement;
        $rootName = $root?->localName ?? '';

        if (! \in_array($rootName, ['Invoice', 'CreditNote'], true)) {
            throw NotABillingDocument::root($rootName);
        }

        $node = SemanticNode::root();

        $this->header($node, $selector, $root);
        $this->parties($node, $selector, $root);
        $this->references($node, $selector, $root);
        $this->paymentInstructions($node, $selector, $root);
        $this->breakdown($node, $selector, $root);
        $this->totals($node, $selector, $root);
        $this->lines($node, $selector, $root, $rootName === 'CreditNote');

        $declared = (string) ($node->first(BG::ProcessControl)?->get(BT::SpecificationIdentifier) ?? '');

        return SemanticInvoice::build(
            $node,
            /*
             | The profile the SENDER declared, not one we chose.
             |
             | BT-24 is how a document says which rule set it was built
             | against, and validating a Peppol document against our own
             | national profile would report rules its sender never agreed
             | to meet. Where it is absent the generic profile applies, and
             | the cardinality rules say BT-24 is missing.
             */
            $this->profileFrom($declared),
            (string) ($node->get(BT::InvoiceTypeCode) ?? ($rootName === 'CreditNote' ? '381' : '380')),
        );
    }

    private function header(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $node->set(BT::InvoiceNumber, $this->text($selector, $root, 'cbc:ID'));
        $node->set(BT::IssueDate, $this->text($selector, $root, 'cbc:IssueDate'));
        $node->set(BT::PaymentDueDate, $this->text($selector, $root, 'cbc:DueDate'));
        $node->set(BT::TaxPointDate, $this->text($selector, $root, 'cbc:TaxPointDate'));
        $node->set(BT::InvoiceCurrencyCode, $this->text($selector, $root, 'cbc:DocumentCurrencyCode'));
        $node->set(BT::VatAccountingCurrencyCode, $this->text($selector, $root, 'cbc:TaxCurrencyCode'));
        $node->set(BT::BuyerReference, $this->text($selector, $root, 'cbc:BuyerReference'));
        $node->set(BT::BuyerAccountingReference, $this->text($selector, $root, 'cbc:AccountingCost'));

        /*
         | An Invoice carries `cbc:InvoiceTypeCode` and a CreditNote carries
         | `cbc:CreditNoteTypeCode`.
         |
         | Both are BT-3. A reader that looked only for the first reads a
         | credit note as having no type at all, and the cardinality rules
         | then report a missing BT-3 against a document that was correct.
         */
        $node->set(
            BT::InvoiceTypeCode,
            $this->text($selector, $root, 'cbc:InvoiceTypeCode')
                ?? $this->text($selector, $root, 'cbc:CreditNoteTypeCode'),
        );

        $customization = $this->text($selector, $root, 'cbc:CustomizationID');
        $process = $this->text($selector, $root, 'cbc:ProfileID');

        if ($customization !== null || $process !== null) {
            $control = SemanticNode::of(BG::ProcessControl);
            $control->set(BT::SpecificationIdentifier, $customization);
            $control->set(BT::BusinessProcessType, $process);
            $node->nest($control);
        }

        foreach ($this->all($selector, $root, 'cbc:Note') as $element) {
            $text = trim($element->textContent);

            if ($text === '') {
                continue;
            }

            $note = SemanticNode::of(BG::InvoiceNote);

            /*
             | A subject code is a prefix, by convention rather than by
             | element.
             |
             | EN 16931 puts BT-21 and BT-22 in one `cbc:Note` separated by
             | `#`, which is the one place the standard asks a reader to
             | split a string. Only a leading `#XXX#` counts: a note that
             | merely contains a hash is ordinary prose.
             */
            if (preg_match('/^#([A-Z]{3})#(.*)$/s', $text, $matches) === 1) {
                $note->set(BT::InvoiceNoteSubjectCode, $matches[1]);
                $note->set(BT::InvoiceNote, trim($matches[2]));
            } else {
                $note->set(BT::InvoiceNote, $text);
            }

            $node->nest($note);
        }

        $period = $this->first($selector, $root, 'cac:InvoicePeriod');

        if ($period !== null) {
            $group = SemanticNode::of(BG::InvoicingPeriod);
            $group->set(BT::InvoicingPeriodStartDate, $this->text($selector, $period, 'cbc:StartDate'));
            $group->set(BT::InvoicingPeriodEndDate, $this->text($selector, $period, 'cbc:EndDate'));
            $node->nest($group);
        }
    }

    private function parties(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $this->seller($node, $selector, $root);
        $this->buyer($node, $selector, $root);
    }

    private function seller(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $party = $this->first($selector, $root, 'cac:AccountingSupplierParty/cac:Party');

        if ($party === null) {
            return;
        }

        $seller = SemanticNode::of(BG::Seller);

        $seller->set(BT::SellerName, $this->text($selector, $party, 'cac:PartyLegalEntity/cbc:RegistrationName'));
        $seller->set(BT::SellerTradingName, $this->text($selector, $party, 'cac:PartyName/cbc:Name'));
        $seller->set(BT::SellerLegalRegistrationIdentifier, $this->text($selector, $party, 'cac:PartyLegalEntity/cbc:CompanyID'));
        $seller->set(BT::SellerAdditionalLegalInformation, $this->text($selector, $party, 'cac:PartyLegalEntity/cbc:CompanyLegalForm'));

        /*
         | The electronic address, with its scheme.
         |
         | This is what the supplier is matched on - PF-141's fourth
         | criterion - and the scheme is half of the identifier: the same
         | digits are a company number in one registry and a VAT number in
         | another, so an address without its scheme matches by luck.
         */
        $endpoint = $this->first($selector, $party, 'cbc:EndpointID');

        if ($endpoint !== null && trim($endpoint->textContent) !== '') {
            $seller->set(BT::SellerElectronicAddress, trim($endpoint->textContent));
            $seller->qualify(BT::SellerElectronicAddress, $endpoint->getAttribute('schemeID') ?: null);
        }

        foreach ($this->all($selector, $party, 'cac:PartyIdentification/cbc:ID') as $identifier) {
            $seller->add(BT::SellerIdentifier, trim($identifier->textContent));
        }

        foreach ($this->all($selector, $party, 'cac:PartyTaxScheme') as $scheme) {
            $identifier = $this->text($selector, $scheme, 'cbc:CompanyID');

            if ($identifier === null) {
                continue;
            }

            /*
             | VAT in one term, everything else in another.
             |
             | BT-31 is the VAT identifier and BT-32 is any other tax
             | registration, and the rules treat them differently: BR-CO-9
             | is about BT-31's country prefix and says nothing about BT-32.
             | Reading both into one term makes a document with a local tax
             | number fail a rule about VAT.
             */
            $schemeId = strtoupper((string) $this->text($selector, $scheme, 'cac:TaxScheme/cbc:ID'));

            $seller->set(
                $schemeId === 'VAT' ? BT::SellerVatIdentifier : BT::SellerTaxRegistrationIdentifier,
                $identifier,
            );
        }

        $this->postalAddress(
            $seller,
            $selector,
            $this->first($selector, $party, 'cac:PostalAddress'),
            BG::SellerPostalAddress,
            [BT::SellerAddressLine1, BT::SellerAddressLine2, BT::SellerCity,
                BT::SellerPostCode, BT::SellerCountrySubdivision, BT::SellerCountryCode],
        );

        $contact = $this->first($selector, $party, 'cac:Contact');

        if ($contact !== null) {
            $node_ = SemanticNode::of(BG::SellerContact);
            $node_->set(BT::SellerContactPoint, $this->text($selector, $contact, 'cbc:Name'));
            $node_->set(BT::SellerContactTelephone, $this->text($selector, $contact, 'cbc:Telephone'));
            $node_->set(BT::SellerContactEmail, $this->text($selector, $contact, 'cbc:ElectronicMail'));
            $seller->nest($node_);
        }

        $node->nest($seller);
    }

    private function buyer(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $party = $this->first($selector, $root, 'cac:AccountingCustomerParty/cac:Party');

        if ($party === null) {
            return;
        }

        $buyer = SemanticNode::of(BG::Buyer);

        $buyer->set(BT::BuyerName, $this->text($selector, $party, 'cac:PartyLegalEntity/cbc:RegistrationName'));
        $buyer->set(BT::BuyerTradingName, $this->text($selector, $party, 'cac:PartyName/cbc:Name'));
        $buyer->set(BT::BuyerIdentifier, $this->text($selector, $party, 'cac:PartyIdentification/cbc:ID'));
        $buyer->set(BT::BuyerLegalRegistrationIdentifier, $this->text($selector, $party, 'cac:PartyLegalEntity/cbc:CompanyID'));
        $buyer->set(BT::BuyerVatIdentifier, $this->text($selector, $party, 'cac:PartyTaxScheme/cbc:CompanyID'));

        $endpoint = $this->first($selector, $party, 'cbc:EndpointID');

        if ($endpoint !== null && trim($endpoint->textContent) !== '') {
            $buyer->set(BT::BuyerElectronicAddress, trim($endpoint->textContent));
            $buyer->qualify(BT::BuyerElectronicAddress, $endpoint->getAttribute('schemeID') ?: null);
        }

        $this->postalAddress(
            $buyer,
            $selector,
            $this->first($selector, $party, 'cac:PostalAddress'),
            BG::BuyerPostalAddress,
            [BT::BuyerAddressLine1, BT::BuyerAddressLine2, BT::BuyerCity,
                BT::BuyerPostCode, BT::BuyerCountrySubdivision, BT::BuyerCountryCode],
        );

        $node->nest($buyer);
    }

    private function references(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $node->set(BT::PurchaseOrderReference, $this->text($selector, $root, 'cac:OrderReference/cbc:ID'));
        $node->set(BT::SalesOrderReference, $this->text($selector, $root, 'cac:OrderReference/cbc:SalesOrderID'));
        $node->set(BT::ContractReference, $this->text($selector, $root, 'cac:ContractDocumentReference/cbc:ID'));
        $node->set(BT::ProjectReference, $this->text($selector, $root, 'cac:ProjectReference/cbc:ID'));
        $node->set(BT::DespatchAdviceReference, $this->text($selector, $root, 'cac:DespatchDocumentReference/cbc:ID'));
        $node->set(BT::ReceivingAdviceReference, $this->text($selector, $root, 'cac:ReceiptDocumentReference/cbc:ID'));
        $node->set(BT::PaymentTerms, $this->text($selector, $root, 'cac:PaymentTerms/cbc:Note'));

        foreach ($this->all($selector, $root, 'cac:BillingReference/cac:InvoiceDocumentReference') as $reference) {
            $preceding = SemanticNode::of(BG::PrecedingInvoiceReference);
            $preceding->set(BT::PrecedingInvoiceReference, $this->text($selector, $reference, 'cbc:ID'));
            $preceding->set(BT::PrecedingInvoiceIssueDate, $this->text($selector, $reference, 'cbc:IssueDate'));
            $node->nest($preceding);
        }
    }

    private function paymentInstructions(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $means = $this->first($selector, $root, 'cac:PaymentMeans');

        if ($means === null) {
            return;
        }

        $instructions = SemanticNode::of(BG::PaymentInstructions);
        $code = $this->first($selector, $means, 'cbc:PaymentMeansCode');

        if ($code !== null) {
            $instructions->set(BT::PaymentMeansTypeCode, trim($code->textContent));
            $instructions->set(BT::PaymentMeansText, $code->getAttribute('name') ?: null);
        }

        $instructions->set(BT::RemittanceInformation, $this->text($selector, $means, 'cbc:PaymentID'));

        $account = $this->first($selector, $means, 'cac:PayeeFinancialAccount');

        if ($account !== null) {
            $transfer = SemanticNode::of(BG::CreditTransfer);
            $transfer->set(BT::PaymentAccountIdentifier, $this->text($selector, $account, 'cbc:ID'));
            $transfer->set(BT::PaymentAccountName, $this->text($selector, $account, 'cbc:Name'));
            $transfer->set(
                BT::PaymentServiceProviderIdentifier,
                $this->text($selector, $account, 'cac:FinancialInstitutionBranch/cbc:ID'),
            );
            $instructions->nest($transfer);
        }

        $node->nest($instructions);
    }

    private function breakdown(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        foreach ($this->all($selector, $root, 'cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
            $group = SemanticNode::of(BG::VatBreakdown);

            $group->set(BT::VatCategoryTaxableAmount, $this->text($selector, $subtotal, 'cbc:TaxableAmount'));
            $group->set(BT::VatCategoryTaxAmount, $this->text($selector, $subtotal, 'cbc:TaxAmount'));
            $group->set(BT::VatCategoryCode, $this->text($selector, $subtotal, 'cac:TaxCategory/cbc:ID'));
            $group->set(BT::VatCategoryRate, $this->text($selector, $subtotal, 'cac:TaxCategory/cbc:Percent'));
            $group->set(BT::VatExemptionReasonCode, $this->text($selector, $subtotal, 'cac:TaxCategory/cbc:TaxExemptionReasonCode'));
            $group->set(BT::VatExemptionReasonText, $this->text($selector, $subtotal, 'cac:TaxCategory/cbc:TaxExemptionReason'));

            $node->nest($group);
        }
    }

    private function totals(SemanticNode $node, \DOMXPath $selector, \DOMElement $root): void
    {
        $monetary = $this->first($selector, $root, 'cac:LegalMonetaryTotal');

        if ($monetary === null) {
            return;
        }

        $totals = SemanticNode::of(BG::DocumentTotals);

        $totals->set(BT::SumOfLineNetAmounts, $this->text($selector, $monetary, 'cbc:LineExtensionAmount'));
        $totals->set(BT::TotalWithoutVat, $this->text($selector, $monetary, 'cbc:TaxExclusiveAmount'));
        $totals->set(BT::TotalWithVat, $this->text($selector, $monetary, 'cbc:TaxInclusiveAmount'));
        $totals->set(BT::SumOfAllowances, $this->text($selector, $monetary, 'cbc:AllowanceTotalAmount'));
        $totals->set(BT::SumOfCharges, $this->text($selector, $monetary, 'cbc:ChargeTotalAmount'));
        $totals->set(BT::PaidAmount, $this->text($selector, $monetary, 'cbc:PrepaidAmount'));
        $totals->set(BT::RoundingAmount, $this->text($selector, $monetary, 'cbc:PayableRoundingAmount'));
        $totals->set(BT::AmountDueForPayment, $this->text($selector, $monetary, 'cbc:PayableAmount'));

        /*
         | BT-110 is on the document, not inside the monetary total.
         |
         | `cac:TaxTotal/cbc:TaxAmount` is the document's total VAT. A reader
         | that looked for it inside `cac:LegalMonetaryTotal` finds nothing
         | and reports BR-CO-14 against a document that was right.
         */
        $totals->set(BT::TotalVatAmount, $this->text($selector, $root, 'cac:TaxTotal/cbc:TaxAmount'));

        $node->nest($totals);
    }

    private function lines(SemanticNode $node, \DOMXPath $selector, \DOMElement $root, bool $isCreditNote): void
    {
        /*
         | An Invoice and a CreditNote have different line elements, and a
         | different quantity element inside them.
         |
         | `cac:InvoiceLine/cbc:InvoicedQuantity` against
         | `cac:CreditNoteLine/cbc:CreditedQuantity`. Both are BT-129, and a
         | reader that knew only the first reads every credit note as having
         | no lines at all.
         */
        $lineElement = $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $quantityElement = $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';

        foreach ($this->all($selector, $root, $lineElement) as $element) {
            $line = SemanticNode::of(BG::InvoiceLine);

            $line->set(BT::LineIdentifier, $this->text($selector, $element, 'cbc:ID'));
            $line->set(BT::LineNote, $this->text($selector, $element, 'cbc:Note'));
            $line->set(BT::LineObjectIdentifier, $this->text($selector, $element, 'cac:DocumentReference/cbc:ID'));
            $line->set(BT::LineNetAmount, $this->text($selector, $element, 'cbc:LineExtensionAmount'));
            $line->set(BT::LineBuyerAccountingReference, $this->text($selector, $element, 'cbc:AccountingCost'));
            $line->set(
                BT::ReferencedPurchaseOrderLineReference,
                $this->text($selector, $element, 'cac:OrderLineReference/cbc:LineID'),
            );

            $quantity = $this->first($selector, $element, $quantityElement);

            if ($quantity !== null) {
                $line->set(BT::InvoicedQuantity, trim($quantity->textContent));

                /*
                 | The unit is BT-130, a term of its own, as well as the
                 | qualifier on BT-129.
                 |
                 | EN 16931 gives the unit code its own business term and
                 | UBL carries it as an attribute, so a reader that stored
                 | only the qualifier loses a term the model declares - and
                 | a round trip through the serializer then comes back one
                 | term short. Measured by exactly that round trip.
                 */
                $unit = $quantity->getAttribute('unitCode') ?: null;
                $line->set(BT::InvoicedQuantityUnitCode, $unit);
                $line->qualify(BT::InvoicedQuantity, $unit);
            }

            $this->item($line, $selector, $this->first($selector, $element, 'cac:Item'));
            $this->price($line, $selector, $this->first($selector, $element, 'cac:Price'));

            $period = $this->first($selector, $element, 'cac:InvoicePeriod');

            if ($period !== null) {
                $linePeriod = SemanticNode::of(BG::InvoiceLinePeriod);
                $linePeriod->set(BT::LinePeriodStartDate, $this->text($selector, $period, 'cbc:StartDate'));
                $linePeriod->set(BT::LinePeriodEndDate, $this->text($selector, $period, 'cbc:EndDate'));
                $line->nest($linePeriod);
            }

            $node->nest($line);
        }
    }

    private function item(SemanticNode $line, \DOMXPath $selector, ?\DOMElement $item): void
    {
        if ($item === null) {
            return;
        }

        $information = SemanticNode::of(BG::ItemInformation);
        $information->set(BT::ItemName, $this->text($selector, $item, 'cbc:Name'));
        $information->set(BT::ItemDescription, $this->text($selector, $item, 'cbc:Description'));
        $information->set(BT::ItemSellerIdentifier, $this->text($selector, $item, 'cac:SellersItemIdentification/cbc:ID'));
        $information->set(BT::ItemBuyerIdentifier, $this->text($selector, $item, 'cac:BuyersItemIdentification/cbc:ID'));
        $information->set(BT::ItemStandardIdentifier, $this->text($selector, $item, 'cac:StandardItemIdentification/cbc:ID'));
        $information->set(BT::ItemCountryOfOrigin, $this->text($selector, $item, 'cac:OriginCountry/cbc:IdentificationCode'));
        $line->nest($information);

        $category = $this->first($selector, $item, 'cac:ClassifiedTaxCategory');

        if ($category === null) {
            return;
        }

        $vat = SemanticNode::of(BG::LineVatInformation);
        $vat->set(BT::LineVatCategoryCode, $this->text($selector, $category, 'cbc:ID'));
        $vat->set(BT::LineVatRate, $this->text($selector, $category, 'cbc:Percent'));
        $line->nest($vat);
    }

    private function price(SemanticNode $line, \DOMXPath $selector, ?\DOMElement $price): void
    {
        if ($price === null) {
            return;
        }

        $details = SemanticNode::of(BG::PriceDetails);
        $details->set(BT::ItemNetPrice, $this->text($selector, $price, 'cbc:PriceAmount'));
        $details->set(BT::ItemPriceDiscount, $this->text($selector, $price, 'cac:AllowanceCharge/cbc:Amount'));
        $details->set(BT::ItemGrossPrice, $this->text($selector, $price, 'cac:AllowanceCharge/cbc:BaseAmount'));

        $base = $this->first($selector, $price, 'cbc:BaseQuantity');

        if ($base !== null) {
            $details->set(BT::ItemPriceBaseQuantity, trim($base->textContent));

            // BT-150, for the same reason as BT-130 above.
            $unit = $base->getAttribute('unitCode') ?: null;
            $details->set(BT::ItemPriceBaseQuantityUnitCode, $unit);
            $details->qualify(BT::ItemPriceBaseQuantity, $unit);
        }

        $line->nest($details);
    }

    /** @param list<BT> $terms */
    private function postalAddress(
        SemanticNode $party,
        \DOMXPath $selector,
        ?\DOMElement $address,
        BG $group,
        array $terms,
    ): void {
        if ($address === null) {
            return;
        }

        $node = SemanticNode::of($group);

        $node->set($terms[0], $this->text($selector, $address, 'cbc:StreetName'));
        $node->set($terms[1], $this->text($selector, $address, 'cbc:AdditionalStreetName'));
        $node->set($terms[2], $this->text($selector, $address, 'cbc:CityName'));
        $node->set($terms[3], $this->text($selector, $address, 'cbc:PostalZone'));
        $node->set($terms[4], $this->text($selector, $address, 'cbc:CountrySubentity'));
        $node->set($terms[5], $this->text($selector, $address, 'cac:Country/cbc:IdentificationCode'));

        $party->nest($node);
    }

    /**
     * Which profile's rules a declared BT-24 asks for.
     *
     * The sender's declaration decides, because BT-24 is how a document says
     * which rule set it was built against. A document declaring the Peppol
     * CIUS is validated against Peppol's rules whoever receives it; one
     * declaring nothing gets the generic EN 16931 set, which is the floor
     * every CIUS builds on.
     */
    private function profileFrom(string $customization): string
    {
        if ($customization === '') {
            return 'generic';
        }

        /** @var array<string, string> $map */
        $map = config('jurisdictions.customization', []);

        foreach ($map as $profile => $identifier) {
            if ($identifier !== '' && $customization === $identifier) {
                return (string) $profile;
            }
        }

        return 'generic';
    }

    /** @return list<\DOMElement> */
    private function all(\DOMXPath $selector, \DOMNode $context, string $path): array
    {
        $nodes = $selector->query($this->expression($path), $context);

        if ($nodes === false) {
            return [];
        }

        $found = [];

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function first(\DOMXPath $selector, \DOMNode $context, string $path): ?\DOMElement
    {
        return $this->all($selector, $context, $path)[0] ?? null;
    }

    private function text(\DOMXPath $selector, \DOMNode $context, string $path): ?string
    {
        $element = $this->first($selector, $context, $path);

        if ($element === null) {
            return null;
        }

        $text = trim($element->textContent);

        return $text === '' ? null : $text;
    }

    /**
     * A prefixed path, rewritten to match on local names.
     *
     * `cac:Party/cbc:Name` becomes
     * `./*[local-name()="Party"]/*[local-name()="Name"]`, so a sender's
     * choice of prefix - or no prefix at all - reads the same.
     */
    private function expression(string $path): string
    {
        $steps = [];

        foreach (explode('/', $path) as $step) {
            $local = str_contains($step, ':') ? explode(':', $step, 2)[1] : $step;
            $steps[] = sprintf('*[local-name()="%s"]', $local);
        }

        return './'.implode('/', $steps);
    }
}
