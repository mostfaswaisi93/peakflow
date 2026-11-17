<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Zatca;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\DocumentSerializer;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Support\Xml\Element as E;

/**
 * The ZATCA UBL subset - PF-123.
 *
 * ## Why ZATCA needs a serializer and Peppol does not
 *
 * Peppol is a CIUS: it narrows EN 16931 and adds no elements, so the same
 * UBL serializer produces a Peppol document. ZATCA is a national
 * **extension**: it adds three `cac:AdditionalDocumentReference` entries
 * (the invoice counter, the previous invoice hash and the QR payload), a
 * `cbc:UUID`, and a `@name` attribute on the type code - none of which EN
 * 16931 has a business term for, because the standard models no hash chain
 * and no QR code.
 *
 * PF-118's interface supports both, which is the point of it. A narrowing is
 * `check()`; an extension is a serializer that composes the UBL tree and
 * adds to it.
 *
 * ## Composition, not inheritance
 *
 * This class asks {@see UblSerializer::tree()} for the element tree and adds
 * to it. `extends UblSerializer` would let a national variant change
 * anything, and the first country to override how parties are emitted would
 * quietly have its own serializer under a shared name - which is the thing
 * this phase is arranged to avoid. Adding to a tree can only add.
 *
 * ## The hash chain, and whose genesis
 *
 * PeakFlow's chain (PF-096) is hex and starts at sixty-four zeros. ZATCA's
 * PIH is the **base64 of the hex digest**, and the specification names a
 * specific first value: the base64 of `sha256("0")` in hex. So the profile
 * translates - the chain is PeakFlow's and the PIH is a representation of it
 * in the form the authority asks for. Emitting our genesis instead would
 * produce a first document the authority rejects for a hash that is
 * correctly computed over the wrong convention.
 */
final class ZatcaSerializer implements DocumentSerializer
{
    /**
     * The first PIH the specification names: base64 of `sha256("0")` in hex.
     *
     * Computed rather than pasted, so the derivation is visible and a
     * reviewer can check it without decoding a string.
     */
    public static function genesisHash(): string
    {
        return base64_encode(hash('sha256', '0'));
    }

    public function __construct(private readonly UblSerializer $ubl) {}

    public function syntax(): string
    {
        return 'ubl';
    }

    public function mediaType(): string
    {
        return 'application/xml';
    }

    public function serialize(SemanticInvoice $invoice): string
    {
        $root = $this->ubl->tree($invoice);

        $this->uuid($root, $invoice);
        $this->transactionType($root, $invoice);
        $this->counterValue($root, $invoice);
        $this->previousInvoiceHash($root, $invoice);
        $this->qrCode($root, $invoice);

        return $this->ubl->render($root, $invoice);
    }

    /**
     * `cbc:UUID` - the identifier the authority's API keys its responses on.
     *
     * Distinct from `BT-1`: the invoice number is the human, gapless,
     * per-series one, and the UUID is the one a clearance response comes
     * back against. A regime that used the number for both would make a
     * reissued document indistinguishable from the original in the
     * authority's records.
     */
    private function uuid(E $root, SemanticInvoice $invoice): void
    {
        $uuid = $invoice->jurisdictionData('document.uuid');

        if ($uuid !== null) {
            $root->with(E::text('cbc:UUID', $uuid));
        }
    }

    /**
     * The seven-digit transaction type on the type code's `@name`.
     *
     * The first two digits are the subtype - `01` standard, `02` simplified -
     * and the remaining five are flags the regime reads. Only the subtype is
     * set here, because the other five describe arrangements PeakFlow does
     * not yet model, and a flag set at a guess is a document the authority
     * reads as something it is not.
     */
    private function transactionType(E $root, SemanticInvoice $invoice): void
    {
        $subtype = $invoice->isSimplified() ? '02' : '01';

        foreach ($root->children() as $child) {
            if ($child->name === 'cbc:InvoiceTypeCode' || $child->name === 'cbc:CreditNoteTypeCode') {
                /*
                 | The attribute belongs on the element the UBL serializer
                 | already emitted, and `Element` is immutable - so the
                 | element is replaced rather than mutated, which is also
                 | what keeps the tree printable twice.
                 */
                $root->replace($child, E::text(
                    $child->name,
                    (string) $child->text,
                    ['name' => $subtype.'00000'],
                ));

                return;
            }
        }
    }

    /**
     * ICV, the invoice counter value.
     *
     * The chain's position, carried as the `cbc:UUID` of an
     * `AdditionalDocumentReference` whose id is the literal `ICV`. It is how
     * the authority detects a gap: a counter that jumps from 41 to 43 is a
     * document that was issued and not reported.
     */
    private function counterValue(E $root, SemanticInvoice $invoice): void
    {
        $index = $invoice->jurisdictionData('chain.index');

        if ($index === null) {
            return;
        }

        $root->with(E::of('cac:AdditionalDocumentReference')->with(
            E::text('cbc:ID', 'ICV'),
            E::text('cbc:UUID', $index),
        ));
    }

    /** PIH, the previous invoice hash, base64 of the hex digest. */
    private function previousInvoiceHash(E $root, SemanticInvoice $invoice): void
    {
        $previous = $invoice->jurisdictionData('chain.previous_hash');

        $pih = $previous === null || $previous === str_repeat('0', 64)
            ? self::genesisHash()
            : base64_encode($previous);

        $root->with(E::of('cac:AdditionalDocumentReference')->with(
            E::text('cbc:ID', 'PIH'),
            E::of('cac:Attachment')->with(
                E::text(
                    'cbc:EmbeddedDocumentBinaryObject',
                    $pih,
                    ['mimeCode' => 'text/plain'],
                ),
            ),
        ));
    }

    /**
     * The QR payload, base64 of the TLV buffer.
     *
     * Tags 1 to 5. Tags 6 to 9 - the XML hash, the signature and the public
     * keys - arrive with PF-124, which needs a key in a KMS, and are omitted
     * rather than emitted empty: a tag with no bytes tells a reader the
     * signature is there and is nothing.
     */
    private function qrCode(E $root, SemanticInvoice $invoice): void
    {
        $payload = TlvQrCode::base64($this->qrValues($invoice));

        $root->with(E::of('cac:AdditionalDocumentReference')->with(
            E::text('cbc:ID', 'QR'),
            E::of('cac:Attachment')->with(
                E::text(
                    'cbc:EmbeddedDocumentBinaryObject',
                    $payload,
                    ['mimeCode' => 'text/plain'],
                ),
            ),
        ));
    }

    /**
     * The values the QR carries, by tag.
     *
     * Public because the printed document carries the same payload, and the
     * PDF and the XML disagreeing about the total is the specific failure a
     * document with two halves invites.
     *
     * @return array<int, string|null>
     */
    public function qrValues(SemanticInvoice $invoice): array
    {
        $seller = $invoice->group(BG::Seller);
        $totals = $invoice->totals();

        return [
            TlvQrCode::TAG_SELLER_NAME => $seller?->get(BT::SellerName),
            TlvQrCode::TAG_VAT_NUMBER => $this->bareVatNumber($seller?->get(BT::SellerVatIdentifier)),
            TlvQrCode::TAG_TIMESTAMP => $invoice->jurisdictionData('document.issued_at'),
            TlvQrCode::TAG_TOTAL_WITH_VAT => $totals?->get(BT::TotalWithVat),
            TlvQrCode::TAG_VAT_TOTAL => $totals?->get(BT::TotalVatAmount) ?? '0.00',
        ];
    }

    /**
     * The registration number without its country prefix.
     *
     * `BT-31` carries `SA310000000000003` because `BR-CO-9` requires the ISO
     * prefix. The authority's own 15-digit number is what the QR states, and
     * an inspector's reader checks the digits against a register that does
     * not have the prefix in it.
     */
    private function bareVatNumber(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        return preg_replace('/^SA/i', '', trim($identifier));
    }
}
