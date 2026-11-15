<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Iso\CountryRegistry;
use App\Tenant\Tax\Blocs;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\RuleBookUnusable;
use App\Tenant\Tax\PlaceOfSupplyRule;

/**
 * Loads and validates the place-of-supply rules - PF-078.
 *
 * ## Everything is checked before anything is used
 *
 * The rule book is configuration, which means it is edited by people under
 * time pressure in a file with no compiler. Every mistake available in it is
 * silent: a typo'd key defaults, a typo'd country never matches, a duplicated
 * precedence makes the answer depend on array order, a missing source leaves
 * a rule nobody can defend.
 *
 * So the book is validated whole, at load, and a single bad row refuses the
 * lot. Refusing one row and continuing would be worse than useless: the bad
 * row might be the one that should have won, so the remaining rules would
 * answer a question that was not theirs.
 *
 * ## Countries are checked against the vendored register
 *
 * Not against a regex. `ZZ` matches `/^[A-Z]{2}$/` and is not a country, and
 * the EU's own VAT identifiers include `EL` and `XI`, which are not ISO
 * 3166-1 codes and are exactly what somebody copying from a VAT manual would
 * write.
 */
final class PlaceOfSupplyRuleBook
{
    private const CONDITIONS = ['supply_type', 'supplier', 'customer', 'status', 'delivery'];

    private const KEYS = ['precedence', 'id', 'place', 'category', 'source', ...self::CONDITIONS];

    private const SUPPLY_TYPES = ['goods', 'services', 'digital_services', '*'];

    private const STATUSES = ['business', 'consumer', '*'];

    /** The supplier's own country has nothing to be `same` as or `abroad` from. */
    private const SUPPLIER_WORDS = ['*'];

    private const COUNTRY_WORDS = ['*', 'same', 'abroad', 'outside'];

    private const PLACES = ['supplier', 'customer', 'delivery'];

    /** @var list<PlaceOfSupplyRule>|null */
    private ?array $rules = null;

    private ?Blocs $blocs = null;

    /**
     * @param  array<string, mixed>|null  $overrides  the rule book to load instead of the configured one
     */
    public function __construct(private readonly ?array $overrides = null) {}

    /**
     * Every rule, in the order they are evaluated.
     *
     * @return list<PlaceOfSupplyRule>
     *
     * @throws RuleBookUnusable
     */
    public function rules(): array
    {
        $this->load();

        \assert($this->rules !== null);

        return $this->rules;
    }

    /** @throws RuleBookUnusable */
    public function blocs(): Blocs
    {
        $this->load();

        \assert($this->blocs !== null);

        return $this->blocs;
    }

    /** @throws RuleBookUnusable */
    private function load(): void
    {
        if ($this->rules !== null) {
            return;
        }

        $book = $this->overrides ?? config('place_of_supply');

        if (! \is_array($book) || ! \is_array($book['rules'] ?? null) || ! \is_array($book['blocs'] ?? null)) {
            throw RuleBookUnusable::notAList();
        }

        $this->blocs = $this->readBlocs($book['blocs']);
        $this->rules = $this->readRules($book['rules']);
    }

    /**
     * @param  array<mixed>  $declared
     *
     * @throws RuleBookUnusable
     */
    private function readBlocs(array $declared): Blocs
    {
        $members = [];
        $departures = [];
        $sources = [];

        foreach ($declared as $name => $bloc) {
            $name = (string) $name;

            if (! \is_array($bloc) || ! \is_string($bloc['source'] ?? null) || $bloc['source'] === '') {
                throw RuleBookUnusable::blocWithoutASource($name);
            }

            $list = $bloc['members'] ?? [];

            foreach (\is_array($list) ? $list : [] as $country) {
                if (! \is_string($country) || ! CountryRegistry::exists($country)) {
                    throw RuleBookUnusable::unknownCountryInABloc($name, (string) $country);
                }

                $members[$name][] = $country;
            }

            foreach (\is_array($bloc['left'] ?? null) ? $bloc['left'] : [] as $country => $date) {
                $country = (string) $country;

                if (! CountryRegistry::exists($country)) {
                    throw RuleBookUnusable::unknownCountryInABloc($name, $country);
                }

                if (\in_array($country, $members[$name] ?? [], true)) {
                    throw RuleBookUnusable::departureThatIsAlsoAMember($name, $country);
                }

                $departures[$name][$country] = (string) $date;
            }

            $sources[$name] = $bloc['source'];
        }

        return new Blocs($members, $departures, $sources);
    }

    /**
     * @param  array<mixed>  $declared
     * @return list<PlaceOfSupplyRule>
     *
     * @throws RuleBookUnusable
     */
    private function readRules(array $declared): array
    {
        $rules = [];
        $precedences = [];
        $ids = [];

        foreach ($declared as $row) {
            if (! \is_array($row)) {
                throw RuleBookUnusable::notAList();
            }

            $id = \is_string($row['id'] ?? null) && $row['id'] !== '' ? $row['id'] : '(unnamed)';

            foreach (array_keys($row) as $key) {
                if (! \in_array((string) $key, self::KEYS, true)) {
                    throw RuleBookUnusable::unknownKey($id, (string) $key);
                }
            }

            foreach (['precedence', 'id', 'place', 'category', ...self::CONDITIONS] as $key) {
                if (! isset($row[$key])) {
                    throw RuleBookUnusable::missingCondition($id, $key);
                }
            }

            if (! \is_string($row['source'] ?? null) || trim($row['source']) === '') {
                throw RuleBookUnusable::ruleWithoutASource($id);
            }

            $precedence = (int) $row['precedence'];

            if (isset($precedences[$precedence])) {
                throw RuleBookUnusable::duplicatePrecedence($precedence, $precedences[$precedence], $id);
            }

            if (isset($ids[$id])) {
                throw RuleBookUnusable::duplicateId($id);
            }

            if (! \in_array($row['place'], self::PLACES, true)) {
                throw RuleBookUnusable::unknownPlace($id, (string) $row['place']);
            }

            $category = TaxCategory::tryFrom((string) $row['category']);

            if ($category === null) {
                throw RuleBookUnusable::unknownCategory($id, (string) $row['category']);
            }

            $this->checkWords($id, 'supply_type', $row['supply_type'], self::SUPPLY_TYPES, false);
            $this->checkWords($id, 'status', $row['status'], self::STATUSES, false);
            $this->checkWords($id, 'supplier', $row['supplier'], self::SUPPLIER_WORDS, true);
            $this->checkWords($id, 'customer', $row['customer'], self::COUNTRY_WORDS, true);
            $this->checkWords($id, 'delivery', $row['delivery'], self::COUNTRY_WORDS, true);

            /*
             | A rule that answers with the delivery location must constrain
             | it. Otherwise a supply with no delivery location matches the
             | rule and the rule has no country to give - and `place()` would
             | return null, which the engine would have to interpret.
             */
            if ($row['place'] === 'delivery' && $row['delivery'] === '*') {
                throw RuleBookUnusable::placeCannotBeReached($id);
            }

            $precedences[$precedence] = $id;
            $ids[$id] = true;

            $rules[] = new PlaceOfSupplyRule(
                $precedence,
                $id,
                $row['supply_type'],
                $row['supplier'],
                $row['customer'],
                $row['status'],
                $row['delivery'],
                $row['place'],
                $category,
                $row['source'],
            );
        }

        if ($rules === []) {
            throw RuleBookUnusable::emptyBook();
        }

        usort($rules, static fn (PlaceOfSupplyRule $a, PlaceOfSupplyRule $b): int
            => $a->precedence <=> $b->precedence);

        return $rules;
    }

    /**
     * @param  list<string>  $vocabulary
     *
     * @throws RuleBookUnusable
     */
    private function checkWords(
        string $rule,
        string $key,
        mixed $condition,
        array $vocabulary,
        bool $countries,
    ): void {
        $words = \is_array($condition) ? $condition : [$condition];

        foreach ($words as $word) {
            if (! \is_string($word)) {
                throw RuleBookUnusable::unknownWord($rule, $key, get_debug_type($word), $vocabulary);
            }

            if (\in_array($word, $vocabulary, true)) {
                continue;
            }

            if ($countries && str_starts_with($word, 'bloc:')) {
                if (! $this->blocs?->exists(substr($word, 5))) {
                    throw RuleBookUnusable::unknownBloc($rule, substr($word, 5));
                }

                continue;
            }

            if ($countries && CountryRegistry::exists($word)) {
                continue;
            }

            throw RuleBookUnusable::unknownWord(
                $rule,
                $key,
                $word,
                $countries ? [...$vocabulary, 'bloc:<name>', '<alpha-2>'] : $vocabulary,
            );
        }
    }
}
