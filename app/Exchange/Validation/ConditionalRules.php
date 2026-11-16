<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticNode;
use App\Support\Iso\CountryRegistry;
use Brick\Math\BigDecimal;

/**
 * The EN 16931 rules that depend on what else is present - PF-110, PF-116.
 *
 * ## Why these are in the model rather than in a Schematron
 *
 * Both, eventually: the vendored Schematron is what a recipient runs, and
 * stage 3 runs it too. But a document that fails `BR-CO-25` at stage 3 has
 * already been built, serialized and canonicalized, and the message comes
 * back as XPath. Catching it here means the supplier is told "an invoice
 * with an amount due needs a due date or payment terms" while they are
 * looking at the invoice.
 *
 * The two are not redundant in the way that matters: this one catches it
 * earlier and says it better, and the Schematron is the authority. When they
 * disagree, the Schematron is right and this file has a bug - which is why
 * the rule identifiers are the standard's own and not ours.
 *
 * ## Only rules whose text is certain
 *
 * Every rule here is one whose EN 16931 wording is known exactly. A rule
 * implemented from a guess at its number is worse than an unimplemented one:
 * it fires with an identifier the recipient's validator does not use, and
 * whoever looks it up finds a different rule.
 *
 * The per-category calculation rules (`BR-S-08`, `BR-Z-08`, …) and the rate
 * rules are therefore not here yet; they arrive with the vendored rule set
 * in PF-115, which is blocked on fetching the official artefacts.
 */
final class ConditionalRules
{
    /** The categories whose exemption needs a stated reason, with their rule. */
    private const EXEMPTION_REASON_RULES = [
        'E' => 'BR-E-10',
        'AE' => 'BR-AE-10',
        'K' => 'BR-IC-10',
        'G' => 'BR-G-10',
        'O' => 'BR-O-10',
    ];

    /** @return list<SemanticViolation> */
    public function check(SemanticNode $root): array
    {
        return [
            ...$this->taxPointExclusivity($root),
            ...$this->vatIdentifierPrefixes($root),
            ...$this->sellerIsIdentifiable($root),
            ...$this->accountingCurrency($root),
            ...$this->periodsHaveABoundary($root),
            ...$this->allowancesAndChargesHaveAReason($root),
            ...$this->paymentIsPossible($root),
            ...$this->exemptionsAreExplained($root),
            ...$this->identifiersCarryTheirScheme($root),
        ];
    }

    /**
     * BR-CO-3: the tax point date and its code are mutually exclusive.
     *
     * Both present is not a harmless duplication: one says the 18th and the
     * other says "the date of the supply", and the recipient's engine picks
     * whichever its implementer preferred. The tax period the transaction
     * lands in then depends on the reader.
     *
     * @return list<SemanticViolation>
     */
    private function taxPointExclusivity(SemanticNode $root): array
    {
        if ($root->has(BT::TaxPointDate) && $root->has(BT::TaxPointDateCode)) {
            return [SemanticViolation::fatal(
                'BR-CO-3',
                term: BT::TaxPointDate,
                value: $root->get(BT::TaxPointDate),
                detail: sprintf(
                    'BT-7 is %s and BT-8 is %s. One document cannot state the tax point twice.',
                    (string) $root->get(BT::TaxPointDate),
                    (string) $root->get(BT::TaxPointDateCode),
                ),
            )];
        }

        return [];
    }

    /**
     * BR-CO-9: a VAT identifier carries its country prefix.
     *
     * `123456789` is not a VAT identifier: the recipient cannot tell a
     * German number from a Dutch one, and the validation of the number's
     * own structure depends on knowing which country issued it.
     *
     * @return list<SemanticViolation>
     */
    private function vatIdentifierPrefixes(SemanticNode $root): array
    {
        $violations = [];

        $candidates = [
            [BT::SellerVatIdentifier, $root->first(BG::Seller)],
            [BT::BuyerVatIdentifier, $root->first(BG::Buyer)],
            [BT::TaxRepresentativeVatIdentifier, $root->first(BG::SellerTaxRepresentativeParty)],
        ];

        foreach ($candidates as [$term, $node]) {
            $value = $node?->get($term);

            if ($value === null) {
                continue;
            }

            $prefix = strtoupper(substr($value, 0, 2));

            if (! CountryRegistry::exists($prefix)) {
                $violations[] = SemanticViolation::fatal(
                    'BR-CO-9',
                    term: $term,
                    value: $value,
                    detail: sprintf(
                        '"%s" does not begin with an ISO 3166-1 alpha-2 country code, so the country '.
                        'of issue cannot be identified.',
                        $value,
                    ),
                );
            }
        }

        return $violations;
    }

    /**
     * BR-CO-26: the buyer can identify the supplier automatically.
     *
     * A name and an address are not an identifier. Without one of these the
     * buyer's system cannot match the invoice to a supplier record, and the
     * document goes to somebody to key in by hand - which is the cost
     * e-invoicing exists to remove.
     *
     * @return list<SemanticViolation>
     */
    private function sellerIsIdentifiable(SemanticNode $root): array
    {
        $seller = $root->first(BG::Seller);

        if ($seller === null) {
            return [];
        }

        $identified = $seller->all(BT::SellerIdentifier) !== []
            || $seller->has(BT::SellerLegalRegistrationIdentifier)
            || $seller->has(BT::SellerVatIdentifier);

        return $identified ? [] : [SemanticViolation::fatal(
            'BR-CO-26',
            term: BT::SellerIdentifier,
            group: BG::Seller,
            detail: 'None of BT-29, BT-30 or BT-31 is present, so the buyer cannot identify the seller '.
                'without reading the document.',
        )];
    }

    /**
     * BR-53: a VAT accounting currency brings the VAT amount in it.
     *
     * Declaring that VAT is accounted for in another currency and not
     * stating the amount leaves the recipient to convert it themselves, at
     * a rate they choose, for a figure that goes on a tax return.
     *
     * @return list<SemanticViolation>
     */
    private function accountingCurrency(SemanticNode $root): array
    {
        if (! $root->has(BT::VatAccountingCurrencyCode)) {
            return [];
        }

        $totals = $root->first(BG::DocumentTotals);

        if ($totals !== null && $totals->has(BT::TotalVatAmountInAccountingCurrency)) {
            return [];
        }

        return [SemanticViolation::fatal(
            'BR-53',
            term: BT::TotalVatAmountInAccountingCurrency,
            group: BG::DocumentTotals,
            value: $root->get(BT::VatAccountingCurrencyCode),
            detail: sprintf(
                'BT-6 is %s and BT-111 is absent, so the VAT amount in the accounting currency is left '.
                'to the reader to compute.',
                (string) $root->get(BT::VatAccountingCurrencyCode),
            ),
        )];
    }

    /**
     * BR-CO-19 and BR-CO-20: a period has at least one boundary.
     *
     * A period group with neither date is an assertion that the supply
     * happened over an interval nobody has stated, which is worse than
     * omitting the group: it looks like information.
     *
     * @return list<SemanticViolation>
     */
    private function periodsHaveABoundary(SemanticNode $root): array
    {
        $violations = [];

        foreach ($root->children(BG::InvoicingPeriod) as $period) {
            if (! $period->has(BT::InvoicingPeriodStartDate) && ! $period->has(BT::InvoicingPeriodEndDate)) {
                $violations[] = SemanticViolation::fatal(
                    'BR-CO-19',
                    group: BG::InvoicingPeriod,
                    detail: 'BG-14 is present with neither BT-73 nor BT-74.',
                );
            }
        }

        foreach ($root->children(BG::InvoiceLine) as $index => $line) {
            foreach ($line->children(BG::InvoiceLinePeriod) as $period) {
                if (! $period->has(BT::LinePeriodStartDate) && ! $period->has(BT::LinePeriodEndDate)) {
                    $violations[] = SemanticViolation::fatal(
                        'BR-CO-20',
                        group: BG::InvoiceLinePeriod,
                        value: $line->get(BT::LineIdentifier),
                        detail: sprintf('Line %s has a BG-26 with neither BT-134 nor BT-135.', (string) $line->get(BT::LineIdentifier)),
                    )->withPath(sprintf('document / BG-25[%d]', $index + 1));
                }
            }
        }

        return $violations;
    }

    /**
     * BR-CO-21 to BR-CO-24: every adjustment says why.
     *
     * A line that reduces the invoice by 40.00 with no reason is the line
     * the customer telephones about, and the person who answers has to
     * reconstruct it from a conversation they were not part of.
     *
     * @return list<SemanticViolation>
     */
    private function allowancesAndChargesHaveAReason(SemanticNode $root): array
    {
        $violations = [];

        $documentLevel = [
            ['BR-CO-21', BG::DocumentLevelAllowances, BT::DocumentAllowanceReason, BT::DocumentAllowanceReasonCode],
            ['BR-CO-22', BG::DocumentLevelCharges, BT::DocumentChargeReason, BT::DocumentChargeReasonCode],
        ];

        foreach ($documentLevel as [$rule, $group, $reason, $code]) {
            foreach ($root->children($group) as $node) {
                if (! $node->has($reason) && ! $node->has($code)) {
                    $violations[] = SemanticViolation::fatal(
                        $rule,
                        term: $reason,
                        group: $group,
                        value: $node->get($group === BG::DocumentLevelAllowances
                            ? BT::DocumentAllowanceAmount
                            : BT::DocumentChargeAmount),
                        detail: sprintf('A %s carries neither %s nor %s.', $group->label(), $reason->value, $code->value),
                    );
                }
            }
        }

        $lineLevel = [
            ['BR-CO-23', BG::InvoiceLineAllowances, BT::LineAllowanceReason, BT::LineAllowanceReasonCode],
            ['BR-CO-24', BG::InvoiceLineCharges, BT::LineChargeReason, BT::LineChargeReasonCode],
        ];

        foreach ($root->children(BG::InvoiceLine) as $index => $line) {
            foreach ($lineLevel as [$rule, $group, $reason, $code]) {
                foreach ($line->children($group) as $node) {
                    if (! $node->has($reason) && ! $node->has($code)) {
                        $violations[] = SemanticViolation::fatal(
                            $rule,
                            term: $reason,
                            group: $group,
                            value: $line->get(BT::LineIdentifier),
                            detail: sprintf(
                                'Line %s carries a %s with neither %s nor %s.',
                                (string) $line->get(BT::LineIdentifier),
                                $group->label(),
                                $reason->value,
                                $code->value,
                            ),
                        )->withPath(sprintf('document / BG-25[%d]', $index + 1));
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * BR-CO-25: money is owed, so the document says when and how.
     *
     * An invoice with an amount due, no due date and no payment terms is
     * one the customer cannot schedule. It is also the invoice that is paid
     * late and chased, and the dunning policy's offset is measured from a
     * date the document never stated.
     *
     * @return list<SemanticViolation>
     */
    private function paymentIsPossible(SemanticNode $root): array
    {
        $totals = $root->first(BG::DocumentTotals);
        $due = $totals?->get(BT::AmountDueForPayment);

        if ($due === null) {
            return [];
        }

        try {
            $positive = BigDecimal::of($due)->isGreaterThan(0);
        } catch (\Throwable) {
            return [];
        }

        if (! $positive) {
            return [];
        }

        if ($root->has(BT::PaymentDueDate) || $root->has(BT::PaymentTerms)) {
            return [];
        }

        return [SemanticViolation::fatal(
            'BR-CO-25',
            term: BT::PaymentDueDate,
            value: $due,
            detail: sprintf(
                '%s is due and the document states neither a due date (BT-9) nor payment terms (BT-20).',
                $due,
            ),
        )];
    }

    /**
     * BR-E-10, BR-AE-10, BR-IC-10, BR-G-10, BR-O-10.
     *
     * A breakdown that charges no VAT must say under what provision. The
     * reason is the document's legal justification, and without it the
     * recipient's tax authority sees an invoice with no VAT and no
     * explanation - which is the same thing as VAT that was not charged.
     *
     * @return list<SemanticViolation>
     */
    private function exemptionsAreExplained(SemanticNode $root): array
    {
        $violations = [];

        foreach ($root->children(BG::VatBreakdown) as $index => $group) {
            $category = strtoupper((string) $group->get(BT::VatCategoryCode));
            $rule = self::EXEMPTION_REASON_RULES[$category] ?? null;

            if ($rule === null) {
                continue;
            }

            if ($group->has(BT::VatExemptionReasonText) || $group->has(BT::VatExemptionReasonCode)) {
                continue;
            }

            $violations[] = SemanticViolation::fatal(
                $rule,
                term: BT::VatExemptionReasonText,
                group: BG::VatBreakdown,
                value: $category,
                detail: sprintf(
                    'The %s breakdown states no exemption reason, so the document does not say under '.
                    'what provision no VAT is charged.',
                    $category,
                ),
            )->withPath(sprintf('document / BG-23[%d]', $index + 1));
        }

        return $violations;
    }

    /**
     * BR-62, BR-63, BR-64, BR-65: an identifier carries its scheme.
     *
     * `0088` and `9930` are both electronic addresses and mean entirely
     * different things; `04012345678901` is a GTIN or a part number
     * depending on a scheme nobody stated. A bare code is not conformant,
     * and the failure surfaces at the recipient as an unroutable document.
     *
     * @return list<SemanticViolation>
     */
    private function identifiersCarryTheirScheme(SemanticNode $root): array
    {
        $violations = [];

        $scoped = [
            ['BR-62', BG::Seller, BT::SellerElectronicAddress],
            ['BR-63', BG::Buyer, BT::BuyerElectronicAddress],
        ];

        foreach ($scoped as [$rule, $group, $term]) {
            $node = $root->first($group);

            if ($node === null || ! $node->has($term)) {
                continue;
            }

            if ($node->qualifier($term) === null) {
                $violations[] = SemanticViolation::fatal(
                    $rule,
                    term: $term,
                    group: $group,
                    value: $node->get($term),
                    detail: sprintf(
                        '%s is "%s" with no EAS scheme, so a recipient cannot tell which address '
                        .'registry it belongs to.',
                        $term->value,
                        (string) $node->get($term),
                    ),
                );
            }
        }

        foreach ($root->children(BG::InvoiceLine) as $index => $line) {
            $item = $line->first(BG::ItemInformation);

            if ($item === null) {
                continue;
            }

            if ($item->has(BT::ItemStandardIdentifier) && $item->qualifier(BT::ItemStandardIdentifier) === null) {
                $violations[] = SemanticViolation::fatal(
                    'BR-64',
                    term: BT::ItemStandardIdentifier,
                    group: BG::ItemInformation,
                    value: $item->get(BT::ItemStandardIdentifier),
                    detail: 'An item standard identifier with no scheme is a number nobody can resolve.',
                )->withPath(sprintf('document / BG-25[%d]', $index + 1));
            }

            foreach ($item->all(BT::ItemClassificationIdentifier) as $ordinal => $value) {
                if ($item->qualifier(BT::ItemClassificationIdentifier, $ordinal) === null) {
                    $violations[] = SemanticViolation::fatal(
                        'BR-65',
                        term: BT::ItemClassificationIdentifier,
                        group: BG::ItemInformation,
                        value: $value,
                        detail: sprintf(
                            'The classification "%s" has no scheme, so UNSPSC cannot be told from GPC.',
                            $value,
                        ),
                    )->withPath(sprintf('document / BG-25[%d]', $index + 1));
                }
            }
        }

        return $violations;
    }
}
