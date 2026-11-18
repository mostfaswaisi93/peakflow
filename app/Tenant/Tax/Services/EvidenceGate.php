<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\EvidenceMissing;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tax\TaxEvidence;

/**
 * What must be on record before a document may be issued - PF-080.
 *
 * ## The three are three different things
 *
 * Zero-rated, exempt and out of scope all show nothing in the tax column and
 * have nothing else in common:
 *
 * - **zero-rated** is a taxable supply at 0%. It counts toward taxable
 *   turnover, toward the registration threshold, and input recovery is intact.
 * - **exempt** is not a taxable supply. Input recovery is restricted, which is
 *   a cost the business bears.
 * - **out of scope** is outside the regime. It is not on the return at all.
 *
 * Collapsing them does not look wrong on an invoice and is wrong in every
 * return: it moves figures between boxes, changes a registration threshold
 * calculation, and claims input tax that is not recoverable.
 *
 * ## The gate is the half that can be enforced before documents exist
 *
 * PF-080's last criterion is that a document cannot be ISSUED without its
 * evidence, and documents arrive in Phase 05. This is the rule it will call,
 * built and checked now so the issue path has something to call rather than a
 * comment saying it should.
 */
final class EvidenceGate
{
    /**
     * What this decision needs on record.
     *
     * @return list<EvidenceKind>
     */
    public function requirementsFor(PlaceOfSupplyDecision $decision): array
    {
        return $decision->evidenceRequired();
    }

    /**
     * Refuse unless everything the treatment needs is recorded.
     *
     * @throws EvidenceMissing
     */
    public function assertSatisfied(PlaceOfSupplyDecision $decision, TaxEvidence $evidence): void
    {
        $this->assertHolds(
            $this->requirementsFor($decision),
            $evidence,
            $decision->rule,
            $decision->category,
        );
    }

    /**
     * The same gate, for a decision as a document carries it - PF-080.
     *
     * The issue path holds the determination as the array it will freeze onto
     * the row, not as a `PlaceOfSupplyDecision`: it is handed one by whoever
     * resolved the supply, it freezes exactly that, and a document reprinted
     * in six years is read back from the same array. Rebuilding the object to
     * ask this question would mean the gate ran against something other than
     * what the document says - which is the one thing a frozen determination
     * exists to prevent.
     *
     * `evidence_required` is what the winning rule demands and `evidence` is
     * what is held, both in the shape `PlaceOfSupplyDecision` and
     * `TaxEvidence` serialize to.
     *
     * A required kind this engine does not know REFUSES rather than being
     * skipped. The alternative is the worst failure available here: a typo in
     * a rule's evidence list silently becomes a rule with no evidence list,
     * and every document under it is issued with nothing behind its rate.
     *
     * @param  array<string, mixed>  $decision  the determination as it will be frozen
     *
     * @throws EvidenceMissing
     */
    public function assertRecordedOn(array $decision): void
    {
        $declared = $decision['evidence_required'] ?? [];
        $required = [];

        foreach (\is_array($declared) ? $declared : [] as $value) {
            $kind = \is_string($value) ? EvidenceKind::tryFrom($value) : null;

            if ($kind === null) {
                throw EvidenceMissing::anEvidenceKindThisEngineDoesNotKnow(
                    \is_string($value) ? $value : get_debug_type($value),
                );
            }

            $required[] = $kind;
        }

        if ($required === []) {
            return;
        }

        $recorded = $decision['evidence'] ?? [];

        $this->assertHolds(
            $required,
            TaxEvidence::fromStored(\is_array($recorded) ? $recorded : []),
            (string) ($decision['rule'] ?? 'unnamed'),
            TaxCategory::tryFrom((string) ($decision['category'] ?? '')) ?? TaxCategory::Standard,
        );
    }

    /**
     * One gate, whichever shape the decision arrived in.
     *
     * Shared rather than written twice, because two gates are two chances to
     * disagree - and the one that disagrees in the lenient direction is the
     * one that issues the document.
     *
     * @param  list<EvidenceKind>  $required
     *
     * @throws EvidenceMissing
     */
    private function assertHolds(
        array $required,
        TaxEvidence $evidence,
        string $rule,
        TaxCategory $category,
    ): void {
        /*
         | Blank BEFORE missing, because `has()` cannot tell them apart.
         |
         | A reference of whitespace is absent as far as `has()` is
         | concerned, so asking "what is missing" first reports a ticked box
         | with nothing behind it as nothing at all. The two send somebody to
         | different places - the forwarder for the document, or whoever
         | ticked the box - and a refusal that cannot distinguish them gets
         | resolved by typing a number in.
         */
        foreach ($evidence->recordedWithoutAReference() as $blank) {
            if (\in_array($blank, $required, true)) {
                throw EvidenceMissing::withABlankReference($blank);
            }
        }

        $missing = $evidence->missingFrom($required);

        if ($missing !== []) {
            throw EvidenceMissing::beforeIssuing($rule, $category, $missing);
        }
    }

    /**
     * Refuse unless the exemption reason is a code this engine knows, for the
     * right category.
     *
     * @throws EvidenceMissing
     */
    public function assertReasonCode(TaxCategory $category, ?string $code): void
    {
        if (! $category->requiresExemptionReason()) {
            return;
        }

        $available = $this->reasonCodesFor($category);

        if ($available === [] && $this->isKnownToHaveNoCodes($category)) {
            throw EvidenceMissing::noCodeShippedFor($category);
        }

        if ($code === null || trim($code) === '') {
            throw EvidenceMissing::noReasonCode($category);
        }

        $codes = $this->codes();
        $entry = $codes[$code] ?? null;

        if (! \is_array($entry)) {
            throw EvidenceMissing::unknownReasonCode($code, $category);
        }

        $belongsTo = TaxCategory::tryFrom((string) ($entry['category'] ?? ''));

        if ($belongsTo === null) {
            throw EvidenceMissing::unknownReasonCode($code, $category);
        }

        if ($belongsTo !== $category) {
            throw EvidenceMissing::reasonCodeForTheWrongCategory($code, $belongsTo, $category, $available);
        }
    }

    /**
     * The codes available for a category.
     *
     * @return list<string>
     */
    public function reasonCodesFor(TaxCategory $category): array
    {
        $codes = [];

        foreach ($this->codes() as $code => $entry) {
            if (\is_array($entry) && ($entry['category'] ?? null) === $category->value) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /** The reason text for a code, in the document's language. */
    public function reasonTextFor(string $code, ?string $locale = null): ?string
    {
        $entry = $this->codes()[$code] ?? null;

        if (! \is_array($entry)) {
            return null;
        }

        $text = $entry[($locale ?? app()->getLocale()) === 'ar' ? 'ar' : 'en'] ?? null;

        return \is_string($text) && $text !== '' ? $text : null;
    }

    /** The article a code comes from, for the tax panel and the audit. */
    public function sourceFor(string $code): ?string
    {
        $entry = $this->codes()[$code] ?? null;
        $source = \is_array($entry) ? ($entry['source'] ?? null) : null;

        return \is_string($source) && $source !== '' ? $source : null;
    }

    /** @return array<string, mixed> */
    private function codes(): array
    {
        $codes = config('tax_evidence.reason_codes');

        return \is_array($codes) ? $codes : [];
    }

    /**
     * Is this a category the configuration admits it has no codes for?
     *
     * Named in the configuration rather than inferred from an empty result,
     * so that a category whose codes were deleted by accident is reported as
     * an unknown code rather than as a documented gap.
     */
    private function isKnownToHaveNoCodes(TaxCategory $category): bool
    {
        $named = config('tax_evidence.categories_without_codes');

        return \is_array($named) && \in_array($category->value, $named, true);
    }
}
