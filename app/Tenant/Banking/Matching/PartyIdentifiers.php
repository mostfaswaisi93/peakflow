<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Tenant\Banking\Models\PartyBankIdentifier;
use Illuminate\Support\Str;

/**
 * Who a counterparty is, when we can say rather than guess - PF-160.
 *
 * ## The engine learns by accumulating identifiers, not by lowering a bar
 *
 * A fuzzy name match that somebody confirms stores the counterparty's
 * account number against the party. The next transfer from that payer
 * matches **exactly**, on an identifier, with no name comparison at all -
 * and the one after that, and every one after that.
 *
 * The alternative shape is an engine that notices it was right and becomes
 * more willing to guess. That engine cannot be audited: nobody can say why
 * last month's matches were made at a bar this month's would not clear, and
 * the only way to investigate a bad match is to reconstruct a threshold
 * that has since moved again.
 *
 * ## A name is never an identifier
 *
 * `normalised_name` is a stored type here, and it is deliberately **not**
 * used by {@see identify()}. It exists so the fuzzy pass can rank, and
 * because knowing which names have been seen is useful. Identity comes from
 * an account number or a BIC - things a payer cannot have by coincidence.
 *
 * Two companies called "Gulf Trading" is not a hypothetical.
 */
final class PartyIdentifiers
{
    /**
     * The party an account or BIC belongs to, where one is on file.
     *
     * The account first: a BIC identifies a bank, and a bank has many
     * customers. Matching on a BIC alone would make every payer at Al Rajhi
     * the same party, which is the kind of wrong that looks like a working
     * feature until the first two customers share a bank.
     */
    public function identify(string $entityId, ?string $account, ?string $bic = null): ?string
    {
        $byAccount = $this->lookup($entityId, 'counterparty_account', $account);

        if ($byAccount !== null) {
            return $byAccount;
        }

        /*
         | A BIC is only an identifier when exactly one party has it.
         |
         | For a small supplier at a small bank that is informative. For
         | anybody at a large one it is not, and the query says which case
         | this is rather than assuming.
         */
        if ($bic === null || trim($bic) === '') {
            return null;
        }

        $parties = PartyBankIdentifier::query()
            ->where('entity_id', $entityId)
            ->where('identifier_type', 'counterparty_bic')
            ->where('identifier', $this->normalise($bic))
            ->pluck('party_id')
            ->unique();

        return $parties->count() === 1 ? (string) $parties->first() : null;
    }

    /**
     * Record what a confirmed match taught us.
     *
     * Called when a person accepts or corrects a proposal, never when the
     * engine applies one by itself: an identifier learned from the engine's
     * own guess is the engine teaching itself, and two confident mistakes
     * later nobody can tell where the belief came from.
     */
    public function learn(
        string $entityId,
        string $partyId,
        ?string $account,
        ?string $bic,
        ?string $counterpartyName,
        string $userId,
        ?string $transactionId = null,
    ): int {
        $learned = 0;

        foreach ([
            'counterparty_account' => $account,
            'counterparty_bic' => $bic,
            'normalised_name' => $counterpartyName === null ? null : $this->normaliseName($counterpartyName),
        ] as $type => $value) {
            if ($value === null || mb_strlen(trim($value)) < 4) {
                continue;
            }

            $identifier = $type === 'normalised_name' ? $value : $this->normalise($value);

            $existing = PartyBankIdentifier::query()
                ->where('entity_id', $entityId)
                ->where('identifier_type', $type)
                ->where('identifier', $identifier)
                ->first();

            if ($existing !== null) {
                $existing->increment('times_seen');
                $existing->update(['last_seen_at' => now()]);

                continue;
            }

            PartyBankIdentifier::create([
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'party_id' => $partyId,
                'identifier_type' => $type,
                'identifier' => $identifier,
                'confirmed_by' => $userId,
                'learned_from_transaction_id' => $transactionId,
                'times_seen' => 1,
                'last_seen_at' => now(),
            ]);

            $learned++;
        }

        return $learned;
    }

    /**
     * A name reduced to what two spellings of it have in common.
     *
     * Case, punctuation, legal-form suffixes, Arabic diacritics and the
     * alef variants, and the spacing a bank's 35-character field forces.
     * `شركة الخليج للتجارة ذ.م.م` and `Al Khaleej Trading LLC` do not reduce
     * to the same thing and should not - that is a transliteration
     * question, and the fuzzy pass scores it rather than pretending it is
     * an equality.
     */
    public function normaliseName(string $name): string
    {
        $value = trim($name);

        /*
         | Arabic diacritics carry no information about identity.
         |
         | They are optional in writing, so the same company name appears
         | with and without them in the same statement - and a comparison
         | that kept them would call those two different payers.
         */
        $value = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value);

        /*
         | Alef, ya and ta marbuta variants.
         |
         | أ إ آ ا are one letter typed four ways; ى and ي likewise, and
         | ة against ه. Normalising them is the single biggest improvement
         | to Arabic name comparison and the one most implementations skip.
         */
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
            'ة' => 'ه',
        ]);

        $value = mb_strtolower($value);

        // Legal forms, which differ between the register and the bank.
        $suffixes = [
            'ذ.م.م', 'ذمم', 'ش.م.م', 'شمم', 'ش.م.ب', 'ش م م',
            'llc', 'l.l.c', 'ltd', 'limited', 'plc', 'inc', 'incorporated',
            'gmbh', 'ag', 'sa', 's.a', 'bv', 'b.v', 'co', 'company', 'corp',
            'est', 'establishment', 'trading', 'group', 'holding',
        ];

        /*
         | Dots inside an acronym are deleted, not turned into spaces.
         |
         | `L.L.C.` becomes `llc`, which is in the list below. Replacing
         | the dots with spaces first gives `l l c` - three one-letter
         | words that match no suffix, so the legal form survives and
         | "Gulf Retail L.L.C." stops matching "Gulf Retail LLC". The same
         | for `ذ.م.م` and `ش.م.م`, which is how every Gulf register writes
         | them.
         |
         | Found by the first fuzzy check run against a dotted legal form.
         */
        $value = str_replace(['.', '\u{066B}', '\u{06D4}'], '', $value);

        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $words = preg_split('/\s+/u', trim($value)) ?: [];

        $words = array_values(array_filter(
            $words,
            static fn (string $word): bool => $word !== '' && ! \in_array($word, $suffixes, true),
        ));

        return implode(' ', $words);
    }

    private function lookup(string $entityId, string $type, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $party = PartyBankIdentifier::query()
            ->where('entity_id', $entityId)
            ->where('identifier_type', $type)
            ->where('identifier', $this->normalise($value))
            ->value('party_id');

        return $party === null ? null : (string) $party;
    }

    private function normalise(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }
}
