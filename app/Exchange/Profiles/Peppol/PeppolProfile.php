<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Peppol;

use App\Exchange\Profiles\JurisdictionProfile;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\DocumentSerializer;
use App\Exchange\Serializers\Ubl\UblSerializer;

/**
 * Peppol BIS Billing 3.0 - PF-120.
 *
 * ## A CIUS is a narrowing, not a dialect
 *
 * Peppol does not change EN 16931's meaning. It removes choices: terms the
 * standard makes optional become mandatory, code lists are restricted to
 * subsets, and a few arithmetic identities the standard states loosely are
 * tightened. So this profile is the generic one plus `check()`, plus a
 * different specification identifier and a business process - and nothing
 * in the serializer changes at all.
 *
 * That is the test of PF-118's interface, and it is the reason the
 * architecture was built this way: a second country that needed a second
 * serializer, or a conditional in the issuer, would mean the interface was
 * the wrong shape.
 *
 * ## Which rules are implemented, and why not all of them
 *
 * Every rule here is one whose Peppol identifier and wording are known
 * exactly. The official rule set is a vendored Schematron (PF-115), and
 * until it is here the rest are **not** implemented - because a rule that
 * fires with an identifier the recipient's access point does not use sends
 * whoever looks it up to a different rule, and that is worse than a rule
 * that does not fire.
 *
 * The same reasoning applies to the EAS code list: the complete list is a
 * vendored artefact, and rejecting an address because our partial copy does
 * not contain its scheme would reject valid documents. The shape is checked;
 * membership waits for the list.
 */
final class PeppolProfile implements JurisdictionProfile
{
    public function key(): string
    {
        return 'peppol';
    }

    public function label(string $locale = 'en'): string
    {
        return $locale === 'ar' ? 'بيبول للفوترة 3.0' : 'Peppol BIS Billing 3.0';
    }

    /**
     * Invoice, credit note and corrected invoice.
     *
     * Not 383, a debit note: Peppol BIS Billing has no debit note flow, and
     * a supplier who issues one gets a document the receiving access point
     * rejects. Refusing it here - before the number is allocated - is the
     * difference between a draft the user can change and a gap in a gapless
     * series.
     *
     * @return list<string>
     */
    public function permittedDocumentTypes(): array
    {
        return ['380', '381', '384', '389'];
    }

    public function specificationIdentifier(): string
    {
        return (string) config(
            'jurisdictions.customization.peppol',
            'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        );
    }

    public function businessProcess(): ?string
    {
        return (string) config(
            'jurisdictions.business_process.peppol',
            'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
        );
    }

    public function serializer(): DocumentSerializer
    {
        /*
         | The same serializer, unchanged.
         |
         | Peppol is UBL 2.1 with a narrower profile, and if adding it had
         | needed a serializer of its own then the semantic model would not
         | be doing its job.
         */
        return app(UblSerializer::class);
    }

    /** @return list<string> */
    public function validatorChain(): array
    {
        return ['model', 'schema', 'en16931', 'cius'];
    }

    /** @return list<\App\Exchange\Validation\SemanticViolation> */
    public function check(SemanticInvoice $invoice): array
    {
        return app(PeppolRules::class)->check($invoice, $this);
    }

    /**
     * AS4 through an access point - PF-122.
     *
     * Declared here even though the transport is not built, because the
     * profile is where the answer lives and `EXCHANGE_DRIVER=log` is what
     * makes the state machine runnable without a partner.
     */
    public function transport(): string
    {
        return 'as4';
    }

    public function sealing(): string
    {
        /*
         | None at the document level.
         |
         | AS4 signs the *message*, and the receipt is the evidence of
         | delivery. Peppol does not require a signature inside the invoice,
         | and adding one would produce a document other access points do
         | not expect.
         */
        return 'none';
    }

    public function submission(): string
    {
        return 'none';
    }

    public function retentionYears(): int
    {
        /*
         | Ten.
         |
         | Peppol itself sets no retention period - it is a transport
         | network, not a tax authority - so this is the longest period any
         | member state requires, which is the only safe reading of "the
         | network does not say".
         */
        return 10;
    }

    public function qrPolicy(): ?string
    {
        return null;
    }
}
