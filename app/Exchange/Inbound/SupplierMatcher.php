<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Who sent this document - PF-141.
 *
 * ## Three resorts, in order, and a name is not one of them
 *
 * 1. **The electronic address** (BT-34 with its scheme). This is what the
 *    network routes on, it was registered by the supplier, and the scheme is
 *    half of it: the same digits are a company number in one registry and a
 *    VAT number in another.
 * 2. **The tax registration** (BT-31 or BT-32). Issued by an authority and
 *    unique within it, which is why it is a resort at all.
 * 3. **The sender allowlist**, where somebody deliberately associated an
 *    address with a supplier and is answerable for it.
 *
 * Then nobody, and a person decides.
 *
 * **There is deliberately no name match.** Two suppliers called "Gulf
 * Trading" are two suppliers; a Latin transliteration of an Arabic name has
 * at least four plausible spellings; and a bill paid to the wrong supplier is
 * money gone somewhere it was never owed, which is the hardest error in
 * accounts payable to get back. A fuzzy match would be right most of the
 * time, and "most of the time" on a payment instruction is not a standard
 * anybody would defend out loud.
 *
 * The `match_method` column enumerates what is allowed, so the absence stays
 * absent even if somebody later adds a helpful-looking function here.
 */
final class SupplierMatcher
{
    /**
     * Match a document's seller against the parties we know.
     *
     * Returns null where nothing matched, which is not a failure: it is the
     * state that sends the document to a person, and the honest answer when
     * the only remaining evidence is a name.
     */
    public function match(InboundDocument $document, SemanticInvoice $invoice): ?SupplierMatch
    {
        $entityId = (string) $document->entity_id;
        $seller = $invoice->group(BG::Seller);

        if ($seller === null) {
            return null;
        }

        $address = $seller->get(BT::SellerElectronicAddress);
        $scheme = $seller->qualifier(BT::SellerElectronicAddress);

        if ($address !== null && $scheme !== null) {
            /*
             | The scheme AND the identifier.
             |
             | Matching on the identifier alone is how a company number
             | matches a VAT number that happens to share its digits - which
             | is not rare, because several registries derive one from the
             | other.
             */
            $partyId = $this->partyByRegistration($entityId, $scheme, $address, 'eas_code');

            if ($partyId !== null) {
                return SupplierMatch::byElectronicAddress($partyId, $scheme, $address);
            }
        }

        /*
         | A list of pairs, not an array keyed by the term.
         |
         | PHP array keys are strings and integers, so an enum used as one
         | is cast - which is a TypeError at best and a silent "0" at worst.
         | Measured: the first version of this loop did exactly that.
         */
        foreach ([[BT::SellerVatIdentifier, 'VAT'], [BT::SellerTaxRegistrationIdentifier, 'OTHER']] as [$term, $kind]) {
            $identifier = $seller->get($term);

            if ($identifier === null) {
                continue;
            }

            $partyId = $this->partyByTaxIdentifier($entityId, $identifier, $kind);

            if ($partyId !== null) {
                return SupplierMatch::byTaxRegistration($partyId, $kind, $identifier);
            }
        }

        $sender = $document->sender_address;

        if ($sender !== null) {
            $partyId = DB::connection('tenant')->table('inbound_senders')
                ->where('entity_id', $entityId)
                ->where('channel', (string) $document->channel)
                ->where('address', $sender)
                ->where('active', true)
                ->whereNotNull('party_id')
                ->value('party_id');

            if ($partyId !== null) {
                return SupplierMatch::bySenderAllowlist((string) $partyId, $sender);
            }
        }

        return null;
    }

    /**
     * An identifier with its ISO 3166 country prefix removed, if it has one.
     *
     * Only where what follows is plausible: `SA310000000000003` loses its
     * prefix and `SA` alone does not, because an identifier that is nothing
     * but a country code is not an identifier. Two letters followed by at
     * least six characters is the shape every scheme that prefixes uses.
     */
    private function withoutCountryPrefix(string $identifier): ?string
    {
        if (preg_match('/^([A-Z]{2})([A-Za-z0-9]{6,})$/', strtoupper($identifier), $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * Whether a sender may send to this entity at all - PF-140.
     *
     * An address rather than a domain, because a shared mailbox at a large
     * supplier sends from one address and their marketing sends from another
     * at the same domain. A domain allowlist is the broader grant and nobody
     * revisits it.
     */
    public function isAllowlisted(string $entityId, string $channel, ?string $address): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }

        return DB::connection('tenant')->table('inbound_senders')
            ->where('entity_id', $entityId)
            ->where('channel', $channel)
            ->where('address', strtolower(trim($address)))
            ->where('active', true)
            ->exists();
    }

    /**
     * A party by one of its registrations, on a given column.
     *
     * `party_tax_registrations` holds both the tax identifier and the
     * electronic address scheme, because they are two facts about one
     * registration: a supplier is routable *as* the entity that holds that
     * registration, and splitting them into two tables would let the two
     * disagree about who the supplier is.
     */
    private function partyByRegistration(
        string $entityId,
        string $scheme,
        string $identifier,
        string $schemeColumn,
    ): ?string {
        $partyId = DB::connection('tenant')->table('party_tax_registrations as r')
            ->join('parties as p', 'p.id', '=', 'r.party_id')
            ->where('p.is_supplier', true)
            ->where('p.active', true)
            ->where('r.'.$schemeColumn, $scheme)
            ->where('r.identifier', $identifier)
            ->value('r.party_id');

        return $partyId === null ? null : (string) $partyId;
    }

    private function partyByTaxIdentifier(string $entityId, string $identifier, string $kind): ?string
    {
        /*
         | Both the prefixed and the bare form.
         |
         | BR-CO-9 requires BT-31 to begin with its country code, and
         | `SemanticInvoiceBuilder` adds one - so a document carries
         | `SA310000000000003` while `party_tax_registrations.identifier`
         | holds `310000000000003`, because the authority issued it without
         | the prefix and that is what somebody reads off a certificate.
         |
         | Measured: comparing only the document's form matched nothing at
         | all, so every inbound document went to a person. Storing the
         | prefixed form instead would be worse - it would change what the
         | tax engine, the returns and the printed invoice all read.
         */
        $candidates = array_values(array_unique(array_filter([
            $identifier,
            $this->withoutCountryPrefix($identifier),
        ])));

        $query = DB::connection('tenant')->table('party_tax_registrations as r')
            ->join('parties as p', 'p.id', '=', 'r.party_id')
            ->where('p.is_supplier', true)
            ->where('p.active', true)
            ->whereIn('r.identifier', $candidates);

        if ($kind === 'VAT') {
            /*
             | A VAT identifier matches a VAT registration, not any
             | registration.
             |
             | BT-31 is the supplier's VAT number. Matching it against a
             | commercial registration that shares its digits identifies the
             | right company by luck and the wrong one when the luck runs
             | out.
             */
            $query->where('r.scheme', 'VAT');
        }

        $partyId = $query->value('r.party_id');

        return $partyId === null ? null : (string) $partyId;
    }
}
