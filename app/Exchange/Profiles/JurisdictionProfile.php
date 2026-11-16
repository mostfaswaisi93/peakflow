<?php

declare(strict_types=1);

namespace App\Exchange\Profiles;

use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\DocumentSerializer;
use App\Exchange\Validation\SemanticViolation;

/**
 * Everything a country's e-invoicing regime decides - PF-118.
 *
 * ## The test of this interface
 *
 * Adding a country is a new class implementing this, plus fixtures, and
 * **never a change to `Sales`**. Deptrac proves `Sales` cannot see
 * `Exchange` at all, and a check counts what adding a profile touches.
 *
 * If a profile ever needs something `Sales` does not already freeze on the
 * document, that is a gap in the document and not a reason for the profile
 * to reach into the sales module: the thing is added to the invoice, frozen
 * at issue, and mapped to a business term. Otherwise the second country's
 * requirements start appearing as conditionals in the issuer, and by the
 * fourth there is no issuer any more.
 *
 * ## What a profile does NOT do
 *
 * It does not load documents, post journal entries, decide tax treatment or
 * number anything. Those are decided by the planes that own them, before a
 * profile is consulted - and a profile that re-decided any of them would be
 * a second answer to a question that already has one.
 */
interface JurisdictionProfile
{
    /** The key in `config/jurisdictions.php`: `generic`, `peppol`, `zatca`. */
    public function key(): string;

    /** What a person sees, in both languages. */
    public function label(string $locale = 'en'): string;

    /**
     * The UNCL 1001 document types this regime permits.
     *
     * A regime that has no concept of a debit note is one where issuing a
     * 383 produces a document the authority rejects, and the refusal belongs
     * here - before the number is allocated - rather than in the response
     * from a clearance API.
     *
     * @return list<string>
     */
    public function permittedDocumentTypes(): array;

    /**
     * The code this regime uses for a document EN 16931 calls `$code`.
     *
     * Almost always the same code. ZATCA is the exception that makes this a
     * method: its tax invoice is UNCL 1001 **388** where every other regime
     * uses 380, which is a commercial invoice.
     *
     * The mapping is here rather than in `Sales` because the internal
     * document type is one fact and a regime's name for it is another: a
     * tenant operating under two profiles issues one document and it is a
     * 380 to one recipient and a 388 to the other. It takes a string rather
     * than the sales enum because a profile may not see `Sales` - PF-118.
     */
    public function documentTypeCode(string $code): string;

    /** `BT-24`, the customization identifier a recipient reads to pick a rule set. */
    public function specificationIdentifier(): string;

    /** `BT-23`, where the regime defines one. */
    public function businessProcess(): ?string;

    /** The syntax this regime is transmitted in. */
    public function serializer(): DocumentSerializer;

    /**
     * The validation stages, in order, by their own names.
     *
     * Ordered because the stages are not independent: a document that fails
     * the model's own rules must not be serialized, and a document that
     * fails the schema must not be run through a rule set that assumes it
     * parses. The pipeline stops at the first stage that fails for that
     * reason.
     *
     * @return list<string>
     */
    public function validatorChain(): array;

    /**
     * The regime's own rules, beyond EN 16931 - the CIUS.
     *
     * Peppol restricts code lists and makes several EN 16931 options
     * mandatory; ZATCA requires Arabic on specified fields. This is where
     * that lives, and it is the only place a country's rules may be
     * expressed.
     *
     * @return list<SemanticViolation>
     */
    public function check(SemanticInvoice $invoice): array;

    /**
     * How a document reaches the recipient: `file`, `email`, `as4`, `api`.
     *
     * `file` is not a placeholder. A regime with no mandated transport is
     * one where the supplier sends the XML themselves, and that has to work
     * end to end or nothing else can be tested.
     */
    public function transport(): string;

    /** Whether a document is cryptographically sealed before it is sent. */
    public function sealing(): string;

    /**
     * Whether and how the document is submitted to an authority before or
     * after delivery: `none`, `clearance`, `reporting`.
     *
     * The distinction is not cosmetic. A cleared document is **the
     * authority's copy** - the one with legal effect - and must be delivered
     * instead of the one that was submitted. A reported document is
     * delivered first and reported within a window. Conflating them is the
     * common failure, and it produces a system that delivers a document the
     * authority has not seen.
     */
    public function submission(): string;

    /** How many years the archive must keep the exact bytes. */
    public function retentionYears(): int;

    /** Whether a QR code is required on the printed document, and in what encoding. */
    public function qrPolicy(): ?string;
}
