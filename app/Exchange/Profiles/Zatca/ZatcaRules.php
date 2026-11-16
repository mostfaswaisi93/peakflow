<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Zatca;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Validation\SemanticViolation;

/**
 * The Saudi requirements, at stage 4 - PF-123.
 *
 * ## Arabic is not a translation
 *
 * The regime requires the invoice **in** Arabic. A document whose seller
 * name and item descriptions are English with an Arabic version somewhere
 * else is not a compliant invoice - it is an English invoice with a
 * translation, and the authority reads the field, not the appendix.
 *
 * Two fields are checked, because their requirement is not in doubt: the
 * seller's registered name and the description of what was supplied. The
 * authoritative list is in the regime's data dictionary, which is a vendored
 * artefact PeakFlow does not hold - so the two that are certain are
 * enforced, and the rest is recorded as open rather than guessed at. A rule
 * that rejects a conformant document is worse than one that does not run.
 *
 * ## The chain is not optional
 *
 * Every document carries the previous one's hash and its own position in the
 * sequence. A counter that jumps from 41 to 43 is a document that was issued
 * and not reported, which is precisely what the chain exists to make
 * visible - so a document with no counter is refused here rather than
 * discovered in a reconciliation months later.
 */
final class ZatcaRules
{
    /**
     * @return list<SemanticViolation>
     */
    public function check(SemanticInvoice $invoice, ZatcaProfile $profile): array
    {
        return [
            ...$this->documentType($invoice, $profile),
            ...$this->theChainIsPresent($invoice),
            ...$this->arabicWhereItIsRequired($invoice),
            ...$this->theBuyerIsIdentifiedOnAStandardInvoice($invoice),
            ...$this->theQrPayloadFits($invoice),
            ...$this->currency($invoice),
        ];
    }

    /**
     * 388 for a tax invoice.
     *
     * @return list<SemanticViolation>
     */
    private function documentType(SemanticInvoice $invoice, ZatcaProfile $profile): array
    {
        $code = (string) $invoice->get(BT::InvoiceTypeCode);

        if (\in_array($code, $profile->permittedDocumentTypes(), true)) {
            return [];
        }

        return [SemanticViolation::fatal(
            'ZATCA-DOCUMENT-TYPE',
            term: BT::InvoiceTypeCode,
            value: $code,
            detail: sprintf(
                'The regime\'s tax invoice is UNCL 1001 388 and this document is a %s. 380 is a '.
                'commercial invoice: the rejection names a code list rather than the problem.',
                $code,
            ),
        )];
    }

    /**
     * The invoice counter and the previous hash.
     *
     * @return list<SemanticViolation>
     */
    private function theChainIsPresent(SemanticInvoice $invoice): array
    {
        $violations = [];

        if ($invoice->jurisdictionData('chain.index') === null) {
            $violations[] = SemanticViolation::internal(
                'ZATCA-ICV-MISSING',
                detail: 'The document carries no invoice counter value. A gap in the counter is how the '.
                    'authority detects a document that was issued and not reported, and a document '.
                    'with no counter cannot take part in that.',
            );
        }

        if ($invoice->jurisdictionData('chain.previous_hash') === null) {
            $violations[] = SemanticViolation::internal(
                'ZATCA-PIH-MISSING',
                detail: 'The document carries no previous invoice hash, so it is not part of a chain '.
                    'and nothing can be proven about the documents before it.',
            );
        }

        return $violations;
    }

    /**
     * Arabic where the regime requires it.
     *
     * @return list<SemanticViolation>
     */
    private function arabicWhereItIsRequired(SemanticInvoice $invoice): array
    {
        $violations = [];
        $seller = $invoice->group(BG::Seller);
        $name = $seller?->get(BT::SellerName);

        if ($name !== null && ! $this->hasArabic($name)) {
            $violations[] = SemanticViolation::fatal(
                'ZATCA-ARABIC-SELLER-NAME',
                term: BT::SellerName,
                group: BG::Seller,
                value: $name,
                detail: sprintf(
                    'The seller name is "%s", which carries no Arabic. The regime requires the invoice '.
                    'in Arabic, and the authority reads the field rather than a translation elsewhere.',
                    $name,
                ),
            );
        }

        foreach ($invoice->lines() as $index => $line) {
            $item = $line->first(BG::ItemInformation);
            $description = $item?->get(BT::ItemName);

            if ($description === null || $this->hasArabic($description)) {
                continue;
            }

            $violations[] = SemanticViolation::fatal(
                'ZATCA-ARABIC-ITEM-NAME',
                term: BT::ItemName,
                group: BG::ItemInformation,
                value: $description,
                detail: sprintf(
                    'Line %s describes what was supplied as "%s", with no Arabic. What was sold is the '.
                    'part of the document a reader has to understand.',
                    (string) $line->get(BT::LineIdentifier),
                    $description,
                ),
            )->withPath(sprintf('document / BG-25[%d]', $index + 1));
        }

        return $violations;
    }

    /**
     * A standard invoice names its buyer's registration.
     *
     * A simplified one does not: it is handed to a consumer at a till, and
     * requiring a registration there would mean no receipt could be issued
     * to a member of the public. Requiring it on a standard invoice is what
     * makes the buyer able to deduct the input tax - without it the document
     * is one the customer cannot use.
     *
     * @return list<SemanticViolation>
     */
    private function theBuyerIsIdentifiedOnAStandardInvoice(SemanticInvoice $invoice): array
    {
        if ($invoice->isSimplified()) {
            return [];
        }

        $buyer = $invoice->group(BG::Buyer);

        if ($buyer?->has(BT::BuyerVatIdentifier) === true) {
            return [];
        }

        return [SemanticViolation::fatal(
            'ZATCA-STANDARD-BUYER-VAT',
            term: BT::BuyerVatIdentifier,
            group: BG::Buyer,
            detail: 'A standard invoice states the buyer\'s VAT registration. Without it the customer '.
                'cannot deduct the input tax, so the document is one they cannot use - and a document '.
                'to an unregistered customer is a simplified one.',
        )];
    }

    /**
     * The QR payload is buildable.
     *
     * TLV holds each value's length in one byte, so a value over 255 bytes
     * cannot be encoded - and an Arabic trading name is two or three bytes
     * per character. Finding out at the printer is finding out too late:
     * the document has been issued, numbered and chained.
     *
     * @return list<SemanticViolation>
     */
    private function theQrPayloadFits(SemanticInvoice $invoice): array
    {
        try {
            $payload = app(ZatcaSerializer::class)->qrValues($invoice);
            TlvQrCode::encode($payload);
        } catch (\LengthException $e) {
            return [SemanticViolation::internal(
                'ZATCA-QR-TOO-LONG',
                detail: $e->getMessage(),
            )];
        }

        $required = [
            TlvQrCode::TAG_SELLER_NAME,
            TlvQrCode::TAG_VAT_NUMBER,
            TlvQrCode::TAG_TIMESTAMP,
            TlvQrCode::TAG_TOTAL_WITH_VAT,
            TlvQrCode::TAG_VAT_TOTAL,
        ];

        $violations = [];

        foreach ($required as $tag) {
            if (($payload[$tag] ?? null) === null || trim((string) $payload[$tag]) === '') {
                $violations[] = SemanticViolation::internal(
                    'ZATCA-QR-INCOMPLETE',
                    detail: sprintf(
                        'QR tag %d has no value. An inspector with no network reads the code on the '.
                        'printed document and sees whatever is in it.',
                        $tag,
                    ),
                );
            }
        }

        return $violations;
    }

    /**
     * The document is in riyals.
     *
     * A Saudi tax invoice states the tax in SAR. A document in another
     * currency has to carry the tax amount in riyals as well - `BT-111`
     * with `BT-6` - because the return is filed in riyals and the authority
     * will not convert it.
     *
     * @return list<SemanticViolation>
     */
    private function currency(SemanticInvoice $invoice): array
    {
        if ($invoice->currency() === 'SAR') {
            return [];
        }

        $totals = $invoice->totals();

        if ($invoice->get(BT::VatAccountingCurrencyCode) === 'SAR'
            && $totals?->has(BT::TotalVatAmountInAccountingCurrency) === true) {
            return [];
        }

        return [SemanticViolation::fatal(
            'ZATCA-SAR-TAX-AMOUNT',
            term: BT::TotalVatAmountInAccountingCurrency,
            group: BG::DocumentTotals,
            value: $invoice->currency(),
            detail: sprintf(
                'The document is in %s and states no tax amount in riyals. The return is filed in SAR '.
                'and the authority does not convert it.',
                $invoice->currency(),
            ),
        )];
    }

    /**
     * Whether a string carries Arabic script.
     *
     * The Arabic block and its supplements, so a name in Arabic-Indic
     * digits or with Persian letters counts - the question is whether an
     * Arabic reader can read it, not which exact code points were used.
     */
    private function hasArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }
}
