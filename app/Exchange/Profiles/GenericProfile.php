<?php

declare(strict_types=1);

namespace App\Exchange\Profiles;

use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\DocumentSerializer;
use App\Exchange\Serializers\Ubl\UblSerializer;

/**
 * EN 16931 and nothing more - PF-119.
 *
 * ## Why the baseline is a profile and not a default
 *
 * Every other profile specializes this one, so it has to exist as a real
 * implementation rather than as the absence of one. A system whose "no
 * profile" path is a different code path from its profile path has two
 * behaviours to maintain, and the one nobody uses in production is the one
 * every test runs against.
 *
 * ## It works end to end with no credentials
 *
 * No access point, no participant identifier, no onboarding, no signing key.
 * That is what makes the rest testable: a fresh clone can issue a document,
 * validate it through every stage that does not need a vendored artefact,
 * serialize it and write it to a file. A profile that needed a government
 * sandbox to do anything would mean the first time anybody saw the pipeline
 * run was after the credentials arrived.
 *
 * ## It adds no rules of its own
 *
 * `check()` returns nothing, deliberately. EN 16931 is the rule set; a CIUS
 * is what a *jurisdiction* adds, and inventing a restriction here would mean
 * the baseline rejects documents the standard permits - which is the
 * opposite of a baseline.
 *
 * ## Not final, and only for that reason
 *
 * Every other class in `Exchange` is `final`. This one is not, because the
 * checks need a profile that differs in exactly one respect - a chain naming
 * a stage nothing implements, a serializer that throws if it is called - and
 * the alternative is a second near-copy of this class per case, which drifts
 * from the real baseline and then tests something else.
 */
class GenericProfile implements JurisdictionProfile
{
    public function key(): string
    {
        return 'generic';
    }

    public function label(string $locale = 'en'): string
    {
        return $locale === 'ar' ? 'المعيار الأوروبي EN 16931' : 'EN 16931 (generic)';
    }

    /**
     * Invoice, credit note, debit note and corrected invoice.
     *
     * UNCL 1001: 380, 381, 383, 384. Not 386 (prepayment) or 326 (partial)
     * at the baseline - they are permitted by EN 16931 and their treatment
     * differs by jurisdiction, so a profile that needs them declares them.
     *
     * @return list<string>
     */
    public function permittedDocumentTypes(): array
    {
        return ['380', '381', '383', '384'];
    }

    public function specificationIdentifier(): string
    {
        return (string) config('jurisdictions.customization.generic', 'urn:cen.eu:en16931:2017');
    }

    public function businessProcess(): ?string
    {
        /*
         | None.
         |
         | EN 16931 does not define one, and a value invented for the
         | baseline would be a term populated for no reason - which the
         | mapping document forbids and the agreement check would catch.
         */
        return null;
    }

    public function serializer(): DocumentSerializer
    {
        return app(UblSerializer::class);
    }

    /** @return list<string> */
    public function validatorChain(): array
    {
        return ['model', 'schema', 'en16931'];
    }

    /** @return list<\App\Exchange\Validation\SemanticViolation> */
    public function check(SemanticInvoice $invoice): array
    {
        return [];
    }

    public function transport(): string
    {
        return 'file';
    }

    public function sealing(): string
    {
        return 'none';
    }

    public function submission(): string
    {
        return 'none';
    }

    /**
     * Ten years.
     *
     * The longest period any jurisdiction PeakFlow targets requires, used as
     * the baseline because a document kept too long is a storage cost and a
     * document deleted too early is a compliance failure. A profile that
     * knows its own period declares it.
     */
    public function retentionYears(): int
    {
        return 10;
    }

    public function qrPolicy(): ?string
    {
        return null;
    }
}
