<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Peppol;

use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Validation\SemanticViolation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The Peppol BIS Billing 3.0 CIUS - PF-120.
 *
 * ## What a CIUS actually is
 *
 * A narrowing. Peppol takes EN 16931 and removes choices: `BT-23` becomes
 * mandatory, a document may carry at most one note, the buyer and seller
 * must both have an electronic address, and the line net amount has to
 * satisfy an identity the standard states only loosely.
 *
 * Every one of those is a document that EN 16931 accepts and the Peppol
 * network rejects, which is why the stage exists: without it a supplier
 * issues a conformant invoice, the access point refuses it, and nobody can
 * explain why from our side.
 *
 * ## Only rules whose identifier and wording are certain
 *
 * The official rule set is a vendored Schematron, and PF-115 is where it
 * arrives. Until then, a rule is implemented only if its `PEPPOL-*`
 * identifier is known exactly - because the supplier reads our message and
 * then the access point's rejection, and if the two quote different
 * identifiers for the same defect they will spend the afternoon deciding
 * which of us is wrong.
 */
final class PeppolRules
{
    /**
     * The payment means Peppol permits, from its UNCL 4461 subset.
     *
     * The full subset is larger; these are the codes PeakFlow can actually
     * produce, and a code outside this list is refused with its own message
     * rather than silently sent.
     */
    private const PAYMENT_MEANS = ['1', '10', '30', '42', '48', '49', '58', '59', '68', '97'];

    /** @return list<SemanticViolation> */
    public function check(SemanticInvoice $invoice, JurisdictionProfile $profile): array
    {
        return [
            ...$this->documentType($invoice, $profile),
            ...$this->businessProcess($invoice),
            ...$this->specificationIdentifier($invoice, $profile),
            ...$this->atMostOneNote($invoice),
            ...$this->aReferenceTheBuyerCanMatch($invoice),
            ...$this->electronicAddresses($invoice),
            ...$this->accountingCurrencyDiffers($invoice),
            ...$this->paymentMeans($invoice),
            ...$this->allowanceBaseAndPercentage($invoice),
            ...$this->priceAndItsDiscount($invoice),
            ...$this->linePeriodsWithinTheInvoicePeriod($invoice),
            ...$this->lineNetAmount($invoice),
        ];
    }

    /**
     * The document type is one Peppol carries.
     *
     * A debit note (383) is conformant EN 16931 and has no Peppol flow. The
     * refusal belongs here, before a number is allocated from a gapless
     * series: a draft can be changed, and a gap cannot be closed.
     *
     * @return list<SemanticViolation>
     */
    private function documentType(SemanticInvoice $invoice, JurisdictionProfile $profile): array
    {
        $code = (string) $invoice->get(BT::InvoiceTypeCode);

        if (\in_array($code, $profile->permittedDocumentTypes(), true)) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-DOCUMENT-TYPE',
            term: BT::InvoiceTypeCode,
            value: $code,
            detail: sprintf(
                'Peppol BIS Billing carries %s and this document is a %s. The receiving access point '.
                'would reject it.',
                implode(', ', $profile->permittedDocumentTypes()),
                $code,
            ),
        )];
    }

    /**
     * PEPPOL-EN16931-R001: a business process must be provided.
     *
     * @return list<SemanticViolation>
     */
    private function businessProcess(SemanticInvoice $invoice): array
    {
        $control = $invoice->group(BG::ProcessControl);

        if ($control?->has(BT::BusinessProcessType) === true) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-EN16931-R001',
            term: BT::BusinessProcessType,
            group: BG::ProcessControl,
            detail: 'BT-23 is absent. Peppol routes on the business process, and a document that does '.
                'not state one cannot be routed.',
        )];
    }

    /**
     * PEPPOL-EN16931-R004: the specification identifier is the EN 16931 one,
     * narrowed.
     *
     * @return list<SemanticViolation>
     */
    private function specificationIdentifier(SemanticInvoice $invoice, JurisdictionProfile $profile): array
    {
        $stated = (string) $invoice->group(BG::ProcessControl)?->get(BT::SpecificationIdentifier);

        if (! str_starts_with($stated, 'urn:cen.eu:en16931:2017')) {
            return [SemanticViolation::fatal(
                'PEPPOL-EN16931-R004',
                term: BT::SpecificationIdentifier,
                group: BG::ProcessControl,
                value: $stated,
                detail: 'A Peppol document declares the EN 16931 specification identifier, narrowed by '.
                    'the Peppol customization - not a Peppol-only string.',
            )];
        }

        if ($stated !== $profile->specificationIdentifier()) {
            return [SemanticViolation::internal(
                'PEPPOL-EN16931-R004',
                term: BT::SpecificationIdentifier,
                value: $stated,
                detail: sprintf(
                    'The document declares "%s" and this profile is "%s". A document validated against '.
                    'the wrong rule set is rejected for rules it was never built to satisfy.',
                    $stated,
                    $profile->specificationIdentifier(),
                ),
            )];
        }

        return [];
    }

    /**
     * PEPPOL-EN16931-R002: no more than one note on document level.
     *
     * @return list<SemanticViolation>
     */
    private function atMostOneNote(SemanticInvoice $invoice): array
    {
        $notes = $invoice->groups(BG::InvoiceNote);

        if (\count($notes) <= 1) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-EN16931-R002',
            term: BT::InvoiceNote,
            group: BG::InvoiceNote,
            value: (string) \count($notes),
            detail: sprintf(
                'The document carries %d notes and Peppol permits one. A jurisdiction that requires a '.
                'legal note and a user who wrote their own have to be combined into it.',
                \count($notes),
            ),
        )];
    }

    /**
     * PEPPOL-EN16931-R003: a buyer reference or a purchase order reference.
     *
     * One of the two, because the buyer's system matches the invoice on it.
     * Without either, a document arrives at a large organisation with
     * nothing to attach it to and somebody keys it in by hand - which is
     * the cost the network exists to remove.
     *
     * @return list<SemanticViolation>
     */
    private function aReferenceTheBuyerCanMatch(SemanticInvoice $invoice): array
    {
        if ($invoice->get(BT::BuyerReference) !== null || $invoice->get(BT::PurchaseOrderReference) !== null) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-EN16931-R003',
            term: BT::BuyerReference,
            detail: 'Neither BT-10 nor BT-13 is present. The buyer\'s system has nothing to match the '.
                'invoice against, so it goes to somebody to key in by hand.',
        )];
    }

    /**
     * PEPPOL-EN16931-R010 and R020: both parties have an electronic address.
     *
     * It is what routing needs: the address is the participant identifier
     * the network resolves to an access point. A document without one cannot
     * be delivered, and "issued but undeliverable" is a worse state than
     * "not yet issued".
     *
     * ## The scheme is checked for shape, not membership
     *
     * `PEPPOL-COMMON-R050` requires the scheme to be from the EAS code list,
     * which is a vendored artefact. Checking membership against a partial
     * copy would reject valid addresses, so the shape - four digits - is
     * what is asserted, and the list arrives with the corpus.
     *
     * @return list<SemanticViolation>
     */
    private function electronicAddresses(SemanticInvoice $invoice): array
    {
        $violations = [];

        $parties = [
            ['PEPPOL-EN16931-R020', BG::Seller, BT::SellerElectronicAddress],
            ['PEPPOL-EN16931-R010', BG::Buyer, BT::BuyerElectronicAddress],
        ];

        foreach ($parties as [$rule, $group, $term]) {
            $node = $invoice->group($group);

            if ($node === null || ! $node->has($term)) {
                $violations[] = SemanticViolation::fatal(
                    $rule,
                    term: $term,
                    group: $group,
                    detail: sprintf(
                        '%s is absent. The electronic address is the participant identifier the network '.
                        'resolves to an access point, so the document cannot be delivered.',
                        $term->value,
                    ),
                );

                continue;
            }

            $scheme = $node->qualifier($term);

            if ($scheme === null || preg_match('/^\d{4}$/', $scheme) !== 1) {
                $violations[] = SemanticViolation::fatal(
                    'PEPPOL-COMMON-R050',
                    term: $term,
                    group: $group,
                    value: $scheme,
                    detail: sprintf(
                        'The scheme on %s is %s. A Peppol electronic address carries a four-digit EAS '.
                        'code: 0088 and 9930 are both addresses and mean entirely different registries.',
                        $term->value,
                        $scheme === null ? 'absent' : '"'.$scheme.'"',
                    ),
                );
            }
        }

        return $violations;
    }

    /**
     * PEPPOL-EN16931-R005: the accounting currency differs from the invoice's.
     *
     * Declaring that VAT is accounted for in the currency the invoice is
     * already in is a statement with no content, and a recipient that acts
     * on it converts an amount by one.
     *
     * @return list<SemanticViolation>
     */
    private function accountingCurrencyDiffers(SemanticInvoice $invoice): array
    {
        $accounting = $invoice->get(BT::VatAccountingCurrencyCode);

        if ($accounting === null || $accounting !== $invoice->currency()) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-EN16931-R005',
            term: BT::VatAccountingCurrencyCode,
            value: $accounting,
            detail: sprintf(
                'BT-6 and BT-5 are both %s. A VAT accounting currency is only meaningful when it '.
                'differs from the document\'s own.',
                $accounting,
            ),
        )];
    }

    /**
     * The payment means is in Peppol's UNCL 4461 subset.
     *
     * @return list<SemanticViolation>
     */
    private function paymentMeans(SemanticInvoice $invoice): array
    {
        $instructions = $invoice->group(BG::PaymentInstructions);
        $code = $instructions?->get(BT::PaymentMeansTypeCode);

        if ($code === null || \in_array($code, self::PAYMENT_MEANS, true)) {
            return [];
        }

        return [SemanticViolation::fatal(
            'PEPPOL-PAYMENT-MEANS',
            term: BT::PaymentMeansTypeCode,
            group: BG::PaymentInstructions,
            value: $code,
            detail: sprintf(
                '"%s" is not in the UNCL 4461 subset Peppol permits (%s).',
                $code,
                implode(', ', self::PAYMENT_MEANS),
            ),
        )];
    }

    /**
     * PEPPOL-EN16931-R041 and R042: a base amount and a percentage travel
     * together.
     *
     * A percentage with no base is a discount nobody can recompute; a base
     * with no percentage is a number with no stated relationship to the
     * amount. Either way the recipient cannot check the arithmetic, which is
     * the only reason to state either.
     *
     * @return list<SemanticViolation>
     */
    private function allowanceBaseAndPercentage(SemanticInvoice $invoice): array
    {
        $violations = [];

        $groups = [
            [BG::DocumentLevelAllowances, BT::DocumentAllowanceBaseAmount, BT::DocumentAllowancePercentage],
            [BG::DocumentLevelCharges, BT::DocumentChargeBaseAmount, BT::DocumentChargePercentage],
        ];

        foreach ($groups as [$group, $base, $percentage]) {
            foreach ($invoice->groups($group) as $node) {
                if ($node->has($base) && ! $node->has($percentage)) {
                    $violations[] = SemanticViolation::fatal(
                        'PEPPOL-EN16931-R042',
                        term: $percentage,
                        group: $group,
                        value: $node->get($base),
                        detail: 'A base amount is stated with no percentage, so the amount cannot be '.
                            'recomputed from it.',
                    );
                }

                if ($node->has($percentage) && ! $node->has($base)) {
                    $violations[] = SemanticViolation::fatal(
                        'PEPPOL-EN16931-R041',
                        term: $base,
                        group: $group,
                        value: $node->get($percentage),
                        detail: 'A percentage is stated with no base amount, so what it is a percentage '.
                            'of is unstated.',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * PEPPOL-EN16931-R044 and R046: the price, and the one allowance on it.
     *
     * A *charge* on a price is refused outright: a price that goes up by an
     * amount nobody can attribute is a price the buyer cannot check against
     * their own records. And where a gross price is stated, the net price
     * must equal it less the discount - because otherwise the document
     * states three numbers that do not relate to each other.
     *
     * @return list<SemanticViolation>
     */
    private function priceAndItsDiscount(SemanticInvoice $invoice): array
    {
        $violations = [];

        foreach ($invoice->lines() as $index => $line) {
            $price = $line->first(BG::PriceDetails);

            if ($price === null) {
                continue;
            }

            if ($price->has(BT::ItemPriceBaseQuantity)) {
                $quantity = $this->decimal($price->get(BT::ItemPriceBaseQuantity));

                if (! $quantity->isGreaterThan(0)) {
                    $violations[] = SemanticViolation::fatal(
                        'PEPPOL-EN16931-R121',
                        term: BT::ItemPriceBaseQuantity,
                        group: BG::PriceDetails,
                        value: $price->get(BT::ItemPriceBaseQuantity),
                        detail: 'A price base quantity of zero or less makes the unit price undefined.',
                    )->withPath($this->linePath($index));
                }

                /*
                 | PEPPOL-EN16931-R130: the base quantity's unit is the
                 | invoiced quantity's.
                 |
                 | "12.00 per 1000" where the line is invoiced in litres and
                 | the price is per kilogram is a document whose total cannot
                 | be derived from its parts.
                 */
                $priceUnit = $price->get(BT::ItemPriceBaseQuantityUnitCode);
                $lineUnit = $line->get(BT::InvoicedQuantityUnitCode);

                if ($priceUnit !== null && $priceUnit !== $lineUnit) {
                    $violations[] = SemanticViolation::fatal(
                        'PEPPOL-EN16931-R130',
                        term: BT::ItemPriceBaseQuantityUnitCode,
                        group: BG::PriceDetails,
                        value: $priceUnit,
                        detail: sprintf(
                            'The price is per %s and the line is invoiced in %s, so the line total '.
                            'cannot be derived from the price.',
                            $priceUnit,
                            (string) $lineUnit,
                        ),
                    )->withPath($this->linePath($index));
                }
            }

            if (! $price->has(BT::ItemGrossPrice)) {
                continue;
            }

            $expected = $this->decimal($price->get(BT::ItemGrossPrice))
                ->minus($this->decimal($price->get(BT::ItemPriceDiscount)));

            $stated = $this->decimal($price->get(BT::ItemNetPrice));

            if (! $stated->isEqualTo($expected)) {
                $violations[] = SemanticViolation::internal(
                    'PEPPOL-EN16931-R046',
                    term: BT::ItemNetPrice,
                    value: $price->get(BT::ItemNetPrice),
                    detail: sprintf(
                        'The gross price less the discount is %s and the net price states %s.',
                        (string) $expected,
                        (string) $stated,
                    ),
                )->withPath($this->linePath($index));
            }
        }

        return $violations;
    }

    /**
     * PEPPOL-EN16931-R110 and R111: a line period sits inside the invoice's.
     *
     * A line billing January inside an invoice whose period is February is
     * a document whose own two statements disagree, and the recipient's
     * accrual lands in whichever period their implementer trusted.
     *
     * @return list<SemanticViolation>
     */
    private function linePeriodsWithinTheInvoicePeriod(SemanticInvoice $invoice): array
    {
        $period = $invoice->group(BG::InvoicingPeriod);

        if ($period === null) {
            return [];
        }

        $start = $period->get(BT::InvoicingPeriodStartDate);
        $end = $period->get(BT::InvoicingPeriodEndDate);
        $violations = [];

        foreach ($invoice->lines() as $index => $line) {
            $linePeriod = $line->first(BG::InvoiceLinePeriod);

            if ($linePeriod === null) {
                continue;
            }

            $lineStart = $linePeriod->get(BT::LinePeriodStartDate);
            $lineEnd = $linePeriod->get(BT::LinePeriodEndDate);

            if ($start !== null && $lineStart !== null && $lineStart < $start) {
                $violations[] = SemanticViolation::fatal(
                    'PEPPOL-EN16931-R110',
                    term: BT::LinePeriodStartDate,
                    group: BG::InvoiceLinePeriod,
                    value: $lineStart,
                    detail: sprintf('The line period starts %s and the invoice period starts %s.', $lineStart, $start),
                )->withPath($this->linePath($index));
            }

            if ($end !== null && $lineEnd !== null && $lineEnd > $end) {
                $violations[] = SemanticViolation::fatal(
                    'PEPPOL-EN16931-R111',
                    term: BT::LinePeriodEndDate,
                    group: BG::InvoiceLinePeriod,
                    value: $lineEnd,
                    detail: sprintf('The line period ends %s and the invoice period ends %s.', $lineEnd, $end),
                )->withPath($this->linePath($index));
            }
        }

        return $violations;
    }

    /**
     * PEPPOL-EN16931-R120: the line net amount is derivable from its parts.
     *
     * `quantity x (net price / base quantity) + charges - allowances`.
     * EN 16931 states the relationship and does not require it; Peppol
     * requires it, because a recipient that cannot recompute a line has to
     * take the supplier's word for the total.
     *
     * Rounded to two decimals on both sides: a per-litre price with eight
     * decimals multiplied by a fractional quantity does not land on a cent,
     * and the document states the rounded figure.
     *
     * @return list<SemanticViolation>
     */
    private function lineNetAmount(SemanticInvoice $invoice): array
    {
        $violations = [];

        foreach ($invoice->lines() as $index => $line) {
            $price = $line->first(BG::PriceDetails);

            if ($price === null || ! $price->has(BT::ItemNetPrice)) {
                continue;
            }

            $base = $price->has(BT::ItemPriceBaseQuantity)
                ? $this->decimal($price->get(BT::ItemPriceBaseQuantity))
                : BigDecimal::one();

            if (! $base->isGreaterThan(0)) {
                // R121 has already reported it; dividing by it here would
                // throw and hide that message behind a stack trace.
                continue;
            }

            $expected = $this->decimal($line->get(BT::InvoicedQuantity))
                ->multipliedBy($this->decimal($price->get(BT::ItemNetPrice)))
                ->dividedBy($base, 8, RoundingMode::HalfUp);

            foreach ($line->children(BG::InvoiceLineCharges) as $charge) {
                $expected = $expected->plus($this->decimal($charge->get(BT::LineChargeAmount)));
            }

            foreach ($line->children(BG::InvoiceLineAllowances) as $allowance) {
                $expected = $expected->minus($this->decimal($allowance->get(BT::LineAllowanceAmount)));
            }

            $stated = $this->decimal($line->get(BT::LineNetAmount))->toScale(2, RoundingMode::HalfUp);

            if (! $stated->isEqualTo($expected->toScale(2, RoundingMode::HalfUp))) {
                $violations[] = SemanticViolation::internal(
                    'PEPPOL-EN16931-R120',
                    term: BT::LineNetAmount,
                    value: $line->get(BT::LineNetAmount),
                    detail: sprintf(
                        'The line states %s and its quantity, price, charges and allowances give %s.',
                        (string) $stated,
                        (string) $expected->toScale(2, RoundingMode::HalfUp),
                    ),
                )->withPath($this->linePath($index));
            }
        }

        return $violations;
    }

    private function linePath(int $index): string
    {
        return sprintf('document / BG-25[%d]', $index + 1);
    }

    private function decimal(?string $value): BigDecimal
    {
        if ($value === null || trim($value) === '') {
            return BigDecimal::zero();
        }

        try {
            return BigDecimal::of(trim($value));
        } catch (\Throwable) {
            return BigDecimal::zero();
        }
    }
}
