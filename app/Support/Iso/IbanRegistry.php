<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;

/**
 * ISO 13616: which countries have an IBAN, how long it is, and an example - PF-008.
 *
 * ## The example is the length column's check
 *
 * The lengths were a `const LENGTHS` array inside {@see Iban} with the comment
 * "Provenance: IBAN Registry, SWIFT" above it and nothing confirming any of the
 * 87 numbers. A wrong length there does not fail safe: it refuses every IBAN
 * for that country with a message about the LENGTH BEING WRONG, which reads as
 * the customer's account being wrong rather than as our table being wrong - and
 * the customer is the one who gets told.
 *
 * So every row carries a published registry example, and the example checks the
 * length arithmetically: if the length were wrong, the country's own published
 * IBAN would be refused by the constructor. 80 of the 87 countries have one, and
 * `bin/verify iban` puts all 80 through the constructor on every run.
 *
 * ## How the examples were themselves checked
 *
 * Each candidate was put through MOD 97-10 before it was committed. That is a
 * real check and not a formality: the two check digits are a checksum over the
 * whole rest of the string, so a remembered IBAN whose check digits validate is
 * a string that is right to roughly 1 part in 97. One candidate failed and was
 * DROPPED rather than corrected - a corrected example is no longer a registry
 * vector, it is a computed one, and a computed one cannot confirm a length.
 *
 * Seven countries have no sourced example (`-` in the file). Their lengths are
 * therefore unconfirmed, the check counts them, and the count may shrink but
 * never grow.
 *
 * ## What is deliberately not validated: the BBAN structure
 *
 * ISO 13616 also defines each country's internal structure - `SA` is two digits
 * then eighteen alphanumerics - and PeakFlow does not check it.
 *
 * That is a decision about which failure is worse. A structure table taken from
 * memory would refuse real bank accounts, and refusing a customer's genuine
 * IBAN stops them being paid; accepting a structurally odd one that passes
 * MOD 97-10 costs a rejected payment message, later, with a reason. The second
 * is recoverable and the first is not, so the structure check waits for Phase 06,
 * where the e-invoicing validators are built against the registry file itself
 * rather than against a recollection of it.
 */
final class IbanRegistry
{
    /** The vendored file this registry reads. */
    public const SOURCE = 'iban';

    /** @var array<string, array{length: int, example: string}>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{length: int, example: string}>
     *
     * @throws RegistryDataUnusable
     */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];

        foreach (VendoredRegistry::rows(self::SOURCE) as $row) {
            foreach (['country', 'length', 'example'] as $column) {
                if (! isset($row[$column])) {
                    throw RegistryDataUnusable::column(self::SOURCE, $column);
                }
            }

            $country = $row['country'];

            if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $country, 'country code', $country);
            }

            /*
             | The length is bounded, not merely numeric.
             |
             | ISO 13616 caps an IBAN at 34 characters, and the shortest in the
             | registry is Norway's 15. A row that said `2` would make every
             | IBAN for that country refused as "wrong length", which reads as
             | the customer's account being wrong rather than as a bad row.
             */
            if (preg_match('/\A[0-9]{2}\z/', $row['length']) !== 1
                || (int) $row['length'] < 15
                || (int) $row['length'] > 34) {
                throw RegistryDataUnusable::value(self::SOURCE, $country, 'length', $row['length']);
            }

            $table[$country] = [
                'length' => (int) $row['length'],
                'example' => $row['example'] === '-' ? '' : $row['example'],
            ];
        }

        return self::$table = $table;
    }

    /** @return array<string, int> */
    public static function lengths(): array
    {
        return array_map(static fn (array $entry): int => $entry['length'], self::all());
    }

    /**
     * The published registry example for a country, where one could be sourced.
     *
     * Exposed rather than kept for the tests, because a form that cannot show
     * a user what their country's IBAN looks like is a form that gets a sort
     * code typed into it.
     */
    public static function example(string $countryCode): ?string
    {
        $entry = self::all()[strtoupper($countryCode)] ?? null;

        return $entry === null || $entry['example'] === '' ? null : $entry['example'];
    }

    /** The countries whose length no published example confirms. */
    /** @return list<string> */
    public static function unconfirmedLengths(): array
    {
        $unconfirmed = [];

        foreach (self::all() as $country => $entry) {
            if ($entry['example'] === '') {
                $unconfirmed[] = $country;
            }
        }

        return $unconfirmed;
    }

    public static function has(string $countryCode): bool
    {
        return isset(self::all()[strtoupper($countryCode)]);
    }

    /** The expected length, or null where the country has no IBAN at all. */
    public static function lengthFor(string $countryCode): ?int
    {
        return self::all()[strtoupper($countryCode)]['length'] ?? null;
    }

    /** @return list<string> */
    public static function countries(): array
    {
        return array_keys(self::all());
    }

    /** Every sourced example, keyed by country. */
    /** @return array<string, string> */
    public static function examples(): array
    {
        $examples = [];

        foreach (self::all() as $country => $entry) {
            if ($entry['example'] !== '') {
                $examples[$country] = $entry['example'];
            }
        }

        return $examples;
    }
}
