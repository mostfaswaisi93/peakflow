<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;

/**
 * ISO 3166-1 countries.
 *
 * Provenance: `database/iso/countries.tsv`, recorded in `database/iso/provenance.json`
 * with how it was obtained and what it was cross-checked against - PF-007. A registry
 * update arrives as a reviewed change to that file, and the digest in the manifest is
 * what says the file has not drifted since somebody read it.
 */
final class CountryRegistry
{
    /** The vendored file this registry reads. */
    public const SOURCE = 'countries';

    /** @var array<string, array{alpha3: string, numeric: string}>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{alpha3: string, numeric: string}>
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
            foreach (['alpha2', 'alpha3', 'numeric'] as $column) {
                if (! isset($row[$column])) {
                    throw RegistryDataUnusable::column(self::SOURCE, $column);
                }
            }

            /*
             | Shapes checked at load.
             |
             | The numeric code is stored as a STRING and must keep its leading
             | zeros: `008` is Albania and `8` is nothing. A row that lost them
             | would pass every alpha-2 lookup and fail only where the numeric
             | form is what the standard asks for - which is the e-invoicing
             | and ISO 20022 payloads, in production, at a receiving system.
             */
            if (preg_match('/\A[A-Z]{2}\z/', $row['alpha2']) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $row['alpha2'], 'alpha-2 code', $row['alpha2']);
            }

            if (preg_match('/\A[A-Z]{3}\z/', $row['alpha3']) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $row['alpha2'], 'alpha-3 code', $row['alpha3']);
            }

            if (preg_match('/\A[0-9]{3}\z/', $row['numeric']) !== 1) {
                throw RegistryDataUnusable::value(self::SOURCE, $row['alpha2'], 'numeric code', $row['numeric']);
            }

            $table[$row['alpha2']] = ['alpha3' => $row['alpha3'], 'numeric' => $row['numeric']];
        }

        return self::$table = $table;
    }

    public static function exists(string $alpha2): bool
    {
        return isset(self::all()[strtoupper($alpha2)]);
    }

    public static function alpha3(string $alpha2): ?string
    {
        return self::all()[strtoupper($alpha2)]['alpha3'] ?? null;
    }

    public static function numeric(string $alpha2): ?string
    {
        return self::all()[strtoupper($alpha2)]['numeric'] ?? null;
    }

    /**
     * Localized country name, through ICU rather than a translation table.
     *
     * ICU already knows every country in every locale we serve, and keeping a
     * hand-maintained Arabic list beside it would mean maintaining a worse copy.
     */
    public static function name(string $alpha2, ?string $locale = null): ?string
    {
        if (! self::exists($alpha2)) {
            return null;
        }

        return \Locale::getDisplayRegion('-'.strtoupper($alpha2), $locale ?? app()->getLocale())
            ?: strtoupper($alpha2);
    }

    /** Does ISO 13616 define an IBAN for this country? Delegated, so there is one answer. */
    public static function usesIban(string $alpha2): bool
    {
        return Iban::appliesTo($alpha2);
    }

    public static function count(): int
    {
        return count(self::all());
    }
}
