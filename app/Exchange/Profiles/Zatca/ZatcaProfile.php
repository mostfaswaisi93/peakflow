<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Zatca;

use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\DocumentSerializer;

/**
 * The Saudi regime - PF-123.
 *
 * ## Two flows, and conflating them is the common failure
 *
 * A **standard** invoice (B2B) is *cleared*: submitted to the authority
 * before it is shared with the buyer, and the authority's stamped copy -
 * not the one that was submitted - is the legal document and the archive
 * copy. A **simplified** invoice (B2C) is *reported*: given to the customer
 * at the till and reported within twenty-four hours.
 *
 * A system that treats them the same either delays every receipt at a
 * checkout waiting for a network call, or delivers a B2B invoice the
 * authority has never seen. {@see submission()} therefore answers per
 * document, which is why it takes one.
 *
 * ## What is built and what is not
 *
 * Built: the UBL subset, the PIH chain over PF-096's hashes, the TLV QR
 * payload, the Arabic requirement, the document types, and the two flows'
 * declarations. Not built: the cryptographic stamp and the clearance API,
 * which are PF-124 and need onboarding, a CSID and a key in a KMS. The
 * profile stays unselectable until they exist, because a document that
 * validates and cannot be cleared is worse than one not yet issued.
 */
final class ZatcaProfile implements JurisdictionProfile
{
    /** Twenty-four hours, for a simplified invoice. */
    public const REPORTING_WINDOW_HOURS = 24;

    public function key(): string
    {
        return 'zatca';
    }

    public function label(string $locale = 'en'): string
    {
        return $locale === 'ar' ? 'الفاتورة الإلكترونية - هيئة الزكاة والضريبة والجمارك' : 'ZATCA e-invoicing';
    }

    /**
     * 388, not 380.
     *
     * The regime's tax invoice is UNCL 1001 **388**, and 380 - the code
     * every other profile uses - is a commercial invoice. A document issued
     * as a 380 is rejected, and the rejection names a code list rather than
     * the problem.
     *
     * @return list<string>
     */
    public function permittedDocumentTypes(): array
    {
        return ['388', '381', '383'];
    }

    /**
     * 380 becomes 388.
     *
     * The internal document type is one fact and the regime's name for it
     * is another. A tenant operating under two profiles issues one invoice
     * and it is a 380 to a European recipient and a 388 to the authority,
     * and neither reading is the document "really" being something else.
     */
    public function documentTypeCode(string $code): string
    {
        return $code === '380' ? '388' : $code;
    }

    public function specificationIdentifier(): string
    {
        return (string) config(
            'jurisdictions.customization.zatca',
            'urn:cen.eu:en16931:2017#compliant#urn:fdc:zatca.gov.sa:2023:1.0',
        );
    }

    public function businessProcess(): ?string
    {
        return null;
    }

    public function serializer(): DocumentSerializer
    {
        return app(ZatcaSerializer::class);
    }

    /** @return list<string> */
    public function validatorChain(): array
    {
        return ['model', 'schema', 'en16931', 'cius'];
    }

    /** @return list<\App\Exchange\Validation\SemanticViolation> */
    public function check(SemanticInvoice $invoice): array
    {
        return app(ZatcaRules::class)->check($invoice, $this);
    }

    public function transport(): string
    {
        return 'api';
    }

    public function sealing(): string
    {
        /*
         | A cryptographic stamp, in the Exchange plane, with the key in a
         | KMS or HSM and never in the database - PF-124.
         |
         | Declared here because the profile is where the answer lives. The
         | stamp itself is not built, and `submissionDriver()` is what makes
         | the state machine runnable without it.
         */
        return 'cryptographic_stamp';
    }

    /**
     * Clearance or reporting, per document.
     *
     * The interface's no-argument `submission()` answers for the regime as a
     * whole - it has both - and {@see submissionFor()} answers for a
     * document, which is the question that matters at the moment of issue.
     */
    public function submission(): string
    {
        return 'clearance_and_reporting';
    }

    /**
     * What this document needs: clearance before delivery, or reporting after.
     *
     * A simplified invoice is handed over at the till. Waiting for a
     * clearance response would mean a queue at a checkout depends on a
     * government API being up, which is why the regime does not ask for it -
     * and why a system that cleared everything would be unusable in the one
     * place most documents are issued.
     */
    public function submissionFor(SemanticInvoice $invoice): string
    {
        return $invoice->isSimplified() ? 'reporting' : 'clearance';
    }

    /**
     * Whether the document may be delivered before the authority has it.
     *
     * The whole distinction, in one answer. A cleared document may not: the
     * copy the buyer receives has to be the authority's stamped one.
     */
    public function mayDeliverBeforeSubmission(SemanticInvoice $invoice): bool
    {
        return $this->submissionFor($invoice) === 'reporting';
    }

    /** Six years, per the Saudi VAT Implementing Regulations. */
    public function retentionYears(): int
    {
        return 6;
    }

    public function qrPolicy(): ?string
    {
        return 'tlv_base64';
    }
}
