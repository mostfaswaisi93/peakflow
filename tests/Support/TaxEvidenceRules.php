<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\EvidenceMissing;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tax\Services\EvidenceGate;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\SupplyContext;
use App\Tenant\Tax\TaxEvidence;
use Throwable;

/**
 * Zero-rated, exempt, out of scope - and the evidence each needs - PF-080.
 *
 * ## What is actually being claimed
 *
 * Not that the three categories exist. That they behave differently, and that
 * a treatment which needs paperwork cannot be claimed without it.
 *
 * All three show nothing in the tax column, which is why they get conflated,
 * and the conflation is invisible on the invoice and wrong in every return: it
 * moves figures between boxes, changes a registration-threshold calculation,
 * and claims input tax that is not recoverable.
 *
 * ## The export case is the one with money on it
 *
 * An export rate claimed on the strength of a foreign billing address, with
 * nothing showing the goods moved, is the most commonly assessed VAT error
 * there is. The gate refuses it at issue - while the document is still in the
 * business's hands and somebody can go and find the customs declaration.
 */
final class TaxEvidenceRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'zero-rated, exempt and out of scope are three return treatments' => $this->theThreeDiffer(),
            'an export cannot be claimed without proof the goods left' => $this->anExportNeedsProof(),
            'an intra-Community supply needs dispatch proof and a registration' => $this->intraCommunityNeedsBoth(),
            'the two export regimes code it differently and both demand proof' => $this->bothExportRegimesDemandProof(),
            'a treatment that needs nothing is not blocked' => $this->aDomesticSupplyIsNotBlocked(),
            'evidence with a blank reference is refused, and told apart' => $this->aBlankReferenceIsRefused(),
            'every reason code names a category, both languages and a source' => $this->theReasonCodesAreUsable(),
            'a reason code from another category is refused' => $this->theWrongCategoryCodeIsRefused(),
            'a category with no shipped code refuses rather than inventing one' => $this->theGapIsNamed(),
            'every category that needs a reason has codes or a declared gap' => $this->everyCategoryIsCovered(),
        ];
    }

    /**
     * The return treatment, written out from the regimes rather than the enum.
     *
     * @return list<string>
     */
    public function theThreeDiffer(): array
    {
        $violations = [];

        $expected = [
            // category => [taxable supply, restricts input recovery]
            'Z' => [true, false],
            'E' => [false, true],
            'O' => [false, true],
        ];

        foreach ($expected as $code => [$taxable, $restricts]) {
            $category = TaxCategory::from($code);

            if ($category->isTaxableSupply() !== $taxable) {
                $violations[] = sprintf(
                    '%s reports itself as %s a taxable supply. Taxable turnover is what a registration '.
                    'threshold is computed from, so getting this wrong can make a business appear not to '.
                    'need a registration it does need',
                    $code, $taxable ? 'not' : '',
                );
            }

            if ($category->restrictsInputRecovery() !== $restricts) {
                $violations[] = sprintf(
                    '%s reports input recovery as %s restricted. Exempt supplies restrict it and that is '.
                    'a cost the business bears; zero-rated supplies do not',
                    $code, $restricts ? 'not' : '',
                );
            }
        }

        if (TaxCategory::ZeroRated->isTaxableSupply() === TaxCategory::Exempt->isTaxableSupply()) {
            $violations[] = 'zero-rated and exempt agree on whether they are taxable supplies. They do '.
                'not: the first is a taxable supply at 0% with input recovery intact, the second is not '.
                'a taxable supply and restricts it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function anExportNeedsProof(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);
        $export = $this->decide(SupplyType::Goods, 'SA', 'US', CustomerStatus::Business, 'US');

        if (! \in_array(EvidenceKind::ExportProof, $gate->requirementsFor($export), true)) {
            $violations[] = sprintf(
                'rule "%s" places an export in %s and demands no proof of export. The rate is claimed '.
                'on the strength of a foreign billing address, which is not evidence that anything moved',
                $export->rule, $export->country,
            );
        }

        $refusal = $this->refusal($gate, $export, TaxEvidence::none());

        if ($refusal === null) {
            $violations[] = 'an export was issued with no evidence at all. It is assessed with the tax, '.
                'interest and a penalty, years later, when the goods are gone';
        } elseif (! str_contains($refusal, 'cannot be claimed without')) {
            /*
             | Refused, but by the backstop rather than by the requirement.
             |
             | A kind that is entirely absent also has no reference, so the
             | gate's blank-reference loop would catch it second. That refusal
             | tells somebody to "record the document number" for a document
             | nobody has claimed to hold, which sends them to the wrong place.
             */
            $violations[] = 'an export with no evidence was refused by the wrong rule, so the message '.
                'sends somebody to the wrong place: '.$refusal;
        } elseif (! str_contains($refusal, 'customs declaration')) {
            $violations[] = 'the refusal does not say what would satisfy it, so whoever reads it cannot '.
                'act on it: '.$refusal;
        }

        $recorded = TaxEvidence::none()->with(EvidenceKind::ExportProof, 'SA-EXP-2026-0041');

        if (($second = $this->refusal($gate, $export, $recorded)) !== null) {
            $violations[] = 'an export with its customs declaration on record was still refused: '.$second;
        }

        return $violations;
    }

    /** @return list<string> */
    public function intraCommunityNeedsBoth(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);
        $supply = $this->decide(SupplyType::Goods, 'FR', 'DE', CustomerStatus::Business, 'DE');

        if ($supply->category !== TaxCategory::IntraCommunity) {
            return [sprintf('the FR-to-DE B2B case is category %s, not K', $supply->category->value)];
        }

        foreach ([EvidenceKind::DispatchProof, EvidenceKind::CustomerRegistration] as $kind) {
            if (! \in_array($kind, $gate->requirementsFor($supply), true)) {
                $violations[] = sprintf(
                    'an intra-Community supply does not require %s. The exemption in the state of '.
                    'dispatch holds only if the goods arrived somewhere else in the Community, and there '.
                    'is no customs declaration to prove it because there is no customs frontier',
                    $kind->value,
                );
            }
        }

        // One of the two is not enough.
        $half = TaxEvidence::none()->with(EvidenceKind::DispatchProof, 'CMR-99812');

        if ($this->refusal($gate, $supply, $half) === null) {
            $violations[] = 'an intra-Community supply was issued with dispatch proof and no customer '.
                'registration. Both are required, and the missing one is the half a receiving state '.
                'checks first';
        }

        $both = $half->with(EvidenceKind::CustomerRegistration, 'DE811234567');

        if (($refusal = $this->refusal($gate, $supply, $both)) !== null) {
            $violations[] = 'an intra-Community supply with both on record was refused: '.$refusal;
        }

        return $violations;
    }

    /**
     * The same economic supply, coded two ways, both needing proof.
     *
     * EN 16931 codes an export `G`; the Saudi regime zero-rates it as `Z`.
     * One row carrying `Z` for everybody was wrong for an EU supplier, and
     * one carrying `G` would be wrong for a Saudi one - so it is two rows,
     * which is what the rule book is for.
     *
     * @return list<string>
     */
    public function bothExportRegimesDemandProof(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);

        $cases = [
            ['FR', 'G', 'goods-exported-from-the-eu'],
            ['SA', 'Z', 'goods-exported'],
        ];

        foreach ($cases as [$supplier, $category, $rule]) {
            $supply = $this->decide(SupplyType::Goods, $supplier, 'CH', CustomerStatus::Business, 'CH');

            if ($supply->category->value !== $category || $supply->rule !== $rule) {
                $violations[] = sprintf(
                    'an export from %s resolved to %s by "%s" and should be %s by "%s". Which code an '.
                    'export takes is a fact about a regime, and deriving it from "is it cross-border" '.
                    'gets one of the two wrong',
                    $supplier, $supply->category->value, $supply->rule, $category, $rule,
                );
            }

            if (! \in_array(EvidenceKind::ExportProof, $gate->requirementsFor($supply), true)) {
                $violations[] = sprintf('the %s export rule demands no proof of export', $supplier);
            }
        }

        if (TaxCategory::Export->requiresEvidence() === false) {
            $violations[] = 'the Export category reports that it needs no evidence, so a rule that '.
                'forgot its own list would claim an export with nothing behind it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aDomesticSupplyIsNotBlocked(): array
    {
        $gate = app(EvidenceGate::class);
        $domestic = $this->decide(SupplyType::Services, 'SA', 'SA', CustomerStatus::Business, null);

        if ($gate->requirementsFor($domestic) !== []) {
            return ['a domestic supply requires evidence. A gate that demands paperwork for an ordinary '.
                'sale is a gate somebody turns off'];
        }

        if (($refusal = $this->refusal($gate, $domestic, TaxEvidence::none())) !== null) {
            return ['a domestic supply with no evidence was refused: '.$refusal];
        }

        return [];
    }

    /** @return list<string> */
    public function aBlankReferenceIsRefused(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);
        $export = $this->decide(SupplyType::Goods, 'SA', 'US', CustomerStatus::Business, 'US');

        $blank = TaxEvidence::none()->with(EvidenceKind::ExportProof, '   ');

        if ($blank->has(EvidenceKind::ExportProof)) {
            $violations[] = 'evidence recorded as whitespace reports itself as present. A ticked box '.
                'with nothing behind it is worse than nothing: the system says the evidence exists, so '.
                'nobody looks for it, and the assessment is unanswerable';
        }

        if ($this->refusal($gate, $export, $blank) === null) {
            $violations[] = 'an export was issued with a blank export-proof reference';
        }

        if ($blank->referenceFor(EvidenceKind::ExportProof) !== null) {
            $violations[] = 'a whitespace reference is returned as a reference';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theReasonCodesAreUsable(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);
        $codes = config('tax_evidence.reason_codes');

        if (! \is_array($codes) || \count($codes) < 10) {
            return ['the reason code list holds fewer than ten codes, which cannot cover the categories '.
                'that require one'];
        }

        foreach ($codes as $code => $entry) {
            $code = (string) $code;

            if (TaxCategory::tryFrom((string) ($entry['category'] ?? '')) === null) {
                $violations[] = sprintf('the code "%s" names no known category', $code);
            }

            foreach (['ar', 'en'] as $locale) {
                if ($gate->reasonTextFor($code, $locale) === null) {
                    $violations[] = sprintf(
                        'the code "%s" has no %s text. BT-120 is read by a person, and an invoice whose '.
                        'reason is blank in the recipient\'s language is an invoice they will query',
                        $code, $locale,
                    );
                }
            }

            $source = $gate->sourceFor($code);

            if ($source === null) {
                $violations[] = sprintf('the code "%s" cites no source', $code);

                continue;
            }

            /*
             | An article, or an explicit statement that the spelling was not
             | verified. PF-079 set this convention: a guessed code reads as
             | checked and is the first thing a receiving system validates.
             */
            if (! str_contains($source, 'Art.') && ! str_contains($source, 'unverified')) {
                $violations[] = sprintf(
                    'the code "%s" cites "%s", which names neither an article nor the fact that it is '.
                    'unverified', $code, $source,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theWrongCategoryCodeIsRefused(): array
    {
        $gate = app(EvidenceGate::class);

        try {
            $gate->assertReasonCode(TaxCategory::ZeroRated, 'vatex-eu-ic');
        } catch (EvidenceMissing $e) {
            return str_contains($e->getMessage(), 'belongs to category K')
                ? []
                : ['a mismatched reason code was refused for the wrong reason: '.$e->getMessage()];
        } catch (Throwable $e) {
            return ['a mismatched reason code was refused by the wrong thing: '.$e->getMessage()];
        }

        return ['the intra-Community reason code was accepted on a zero-rated supply. It reports '.
            'something that did not happen: the other member state will look for a figure in a box and '.
            'not find it'];
    }

    /** @return list<string> */
    public function theGapIsNamed(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);

        try {
            $gate->assertReasonCode(TaxCategory::CanaryIslands, 'vatex-eu-g');
            $violations[] = 'a category with no shipped code accepted another category\'s code';
        } catch (EvidenceMissing $e) {
            if (! str_contains($e->getMessage(), 'no code for it is shipped')) {
                $violations[] = 'the gap was reported as something else: '.$e->getMessage();
            }
        }

        /*
         | An unknown code must still be refused as unknown, not reported as a
         | documented gap. The two are different: one is a row somebody has to
         | write, the other is a typo.
         */
        try {
            $gate->assertReasonCode(TaxCategory::ZeroRated, 'vatex-not-a-code');
            $violations[] = 'an invented reason code was accepted';
        } catch (EvidenceMissing $e) {
            if (! str_contains($e->getMessage(), 'not in the list')) {
                $violations[] = 'an invented code was reported as something else: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function everyCategoryIsCovered(): array
    {
        $violations = [];
        $gate = app(EvidenceGate::class);
        $declared = config('tax_evidence.categories_without_codes');
        $declared = \is_array($declared) ? $declared : [];

        foreach (TaxCategory::cases() as $category) {
            if (! $category->requiresExemptionReason()) {
                continue;
            }

            $codes = $gate->reasonCodesFor($category);

            if ($codes === [] && ! \in_array($category->value, $declared, true)) {
                $violations[] = sprintf(
                    'category %s requires a reason, has no code, and is not in the declared gap list. A '.
                    'category in neither place refuses every document silently - it looks like a bug in '.
                    'the gate rather than a row somebody has to add',
                    $category->value,
                );
            }
        }

        if (! \in_array('S', $declared, true) && $gate->reasonCodesFor(TaxCategory::Standard) !== []) {
            $violations[] = 'the standard category has reason codes, which means a standard-rated line '.
                'can carry an exemption reason';
        }

        return $violations;
    }

    private function decide(
        SupplyType $type,
        string $supplier,
        string $customer,
        CustomerStatus $status,
        ?string $delivery,
    ): PlaceOfSupplyDecision {
        $context = $delivery === null
            ? SupplyContext::withNoDeliveryLocation($type, $supplier, $customer, $status)
            : SupplyContext::deliveredTo($type, $supplier, $customer, $status, $delivery);

        return app(PlaceOfSupply::class)->decide($context);
    }

    private function refusal(EvidenceGate $gate, PlaceOfSupplyDecision $decision, TaxEvidence $evidence): ?string
    {
        try {
            $gate->assertSatisfied($decision, $evidence);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
