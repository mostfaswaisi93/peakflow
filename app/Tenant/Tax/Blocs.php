<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

/**
 * Which countries belong to which trade bloc, for tax purposes - PF-078.
 *
 * Built from `config/place_of_supply.php` by the rule book, which validates
 * the membership lists against the vendored ISO 3166-1 register before this
 * is constructed. Nothing here knows about any specific bloc.
 */
final readonly class Blocs
{
    /**
     * @param  array<string, list<string>>  $members
     * @param  array<string, array<string, string>>  $departures  bloc => country => the date it left
     * @param  array<string, string>  $sources  bloc => the instrument its membership comes from
     */
    public function __construct(
        private array $members = [],
        private array $departures = [],
        private array $sources = [],
    ) {}

    public function exists(string $bloc): bool
    {
        return isset($this->members[$bloc]);
    }

    public function isMember(string $bloc, string $country): bool
    {
        return \in_array($country, $this->members[$bloc] ?? [], true);
    }

    /**
     * Do these two countries share any bloc?
     *
     * This is what separates an export from an intra-bloc supply, and it is
     * asked of a PAIR rather than of one country, because a country can be in
     * a bloc the other is not in.
     */
    public function share(string $one, string $other): bool
    {
        foreach ($this->members as $bloc => $_) {
            if ($this->isMember($bloc, $one) && $this->isMember($bloc, $other)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function blocsOf(string $country): array
    {
        $blocs = [];

        foreach (array_keys($this->members) as $bloc) {
            if ($this->isMember($bloc, $country)) {
                $blocs[] = $bloc;
            }
        }

        return $blocs;
    }

    /** The date a country left a bloc, if it did. */
    public function leftOn(string $bloc, string $country): ?string
    {
        return $this->departures[$bloc][$country] ?? null;
    }

    public function source(string $bloc): ?string
    {
        return $this->sources[$bloc] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->members);
    }

    /** @return list<string> */
    public function membersOf(string $bloc): array
    {
        return $this->members[$bloc] ?? [];
    }
}
