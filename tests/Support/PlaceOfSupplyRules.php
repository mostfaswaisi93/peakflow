<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Exceptions\PlaceOfSupplyUndeterminable;
use App\Tenant\Tax\Exceptions\RuleBookUnusable;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\Services\PlaceOfSupplyRuleBook;
use App\Tenant\Tax\SupplyContext;
use Throwable;

/**
 * Which jurisdiction's tax applies, and which rule said so - PF-078.
 *
 * ## What is actually being claimed
 *
 * Not "the engine returns a country". Every supply has a supplier country,
 * it is always available and always plausible, and an engine that quietly
 * returned it would pass any check that only asks for two letters back - while
 * charging Saudi VAT on a download sold to a consumer in France.
 *
 * So every case here asserts the WINNING RULE and the TAX CATEGORY, not just
 * the country. The category is what reaches the invoice line: `AE` moves the
 * liability to the customer, `K` reports an intra-Community supply, `Z`
 * demands export evidence. A case that asserted only the country would accept
 * a reverse charge as an ordinary domestic sale whenever the two happen to
 * agree.
 *
 * ## The expected answers are written out, not derived
 *
 * From the articles, in the table below. A check that read the rule book to
 * work out what the rule book should say would assert the file equals itself.
 */
final class PlaceOfSupplyRules
{
    /**
     * The shipped cases, from PF-078's criteria.
     *
     * [supply type, supplier, customer, status, delivery] => [country, category, rule]
     *
     * @var list<array{array{string, string, string, string, ?string}, array{string, string, string}}>
     */
    private const CASES = [
        // Domestic.
        [['goods', 'SA', 'SA', 'business', 'SA'], ['SA', 'S', 'goods-delivered-in-the-supplier-state']],
        [['services', 'SA', 'SA', 'business', null], ['SA', 'S', 'domestic']],
        [['digital_services', 'FR', 'FR', 'consumer', null], ['FR', 'S', 'domestic']],

        // Export of goods: outside every bloc the supplier is in.
        [['goods', 'SA', 'US', 'business', 'US'], ['SA', 'Z', 'goods-exported']],
        [['goods', 'FR', 'CH', 'consumer', 'CH'], ['FR', 'G', 'goods-exported-from-the-eu']],

        /*
         | The United Kingdom left the EU, so a French supplier delivering
         | there is exporting. This is the case that a membership list nobody
         | dated would get wrong, and it would get it wrong in the expensive
         | direction: an intra-Community supply reported for a country that is
         | not in the Community.
         */
        [['goods', 'FR', 'GB', 'business', 'GB'], ['FR', 'G', 'goods-exported-from-the-eu']],

        // Intra-GCC.
        [['goods', 'SA', 'AE', 'business', 'AE'], ['AE', 'AE', 'intra-gcc-goods-to-a-business']],
        [['goods', 'SA', 'AE', 'consumer', 'AE'], ['SA', 'S', 'intra-gcc-goods-to-a-consumer']],

        // Intra-EU goods, both directions of the B2B/B2C split.
        [['goods', 'FR', 'DE', 'business', 'DE'], ['DE', 'K', 'intra-eu-goods-to-a-business']],
        [['goods', 'FR', 'DE', 'consumer', 'DE'], ['DE', 'S', 'intra-eu-goods-to-a-consumer']],

        // Services across a border: the general rules, which read backwards.
        [['services', 'SA', 'FR', 'business', null], ['FR', 'AE', 'services-to-a-business-abroad']],
        [['services', 'SA', 'FR', 'consumer', null], ['SA', 'S', 'services-to-a-consumer-abroad']],

        // Digital services to a consumer: destination, and the reason OSS exists.
        [['digital_services', 'SA', 'FR', 'consumer', null], ['FR', 'S', 'digital-services-to-a-consumer-abroad']],
        [['digital_services', 'FR', 'DE', 'consumer', null], ['DE', 'S', 'digital-services-to-a-consumer-abroad']],

        // A business customer reverse-charges a download exactly as it does consulting.
        [['digital_services', 'SA', 'FR', 'business', null], ['FR', 'AE', 'services-to-a-business-abroad']],

        /*
         | Goods collected in the supplier's country by a foreign customer.
         |
         | Domestic, not an export: the goods did not move, and the customer's
         | address is not evidence that they did. The rule that gets this
         | wrong zero-rates a supply on the strength of a billing address,
         | which is the most common export-evidence assessment there is.
         */
        [['goods', 'SA', 'FR', 'business', 'SA'], ['SA', 'S', 'goods-delivered-in-the-supplier-state']],
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every shipped case resolves to the rule and category it should' => $this->theShippedCases(),
            'a supply nothing covers refuses rather than guessing' => $this->anUncoveredSupplyRefuses(),
            'goods with no delivery location refuse rather than falling through' => $this->aMissingDeliveryRefuses(),
            'precedence decides, and it is explicit rather than array order' => $this->precedenceDecides(),
            'the decision carries the winning rule and its source' => $this->theDecisionExplainsItself(),
            'the rule book refuses every mistake a configuration file allows' => $this->theRuleBookIsValidated(),
            'bloc membership is ISO 3166-1 and departures are dated' => $this->blocMembershipIsReal(),
            'a country no register knows is refused at the party, not the rule book' => $this->anUnknownCountryRefuses(),
        ];
    }

    /** @return list<string> */
    public function theShippedCases(): array
    {
        $violations = [];
        $engine = app(PlaceOfSupply::class);

        foreach (self::CASES as [[$type, $supplier, $customer, $status, $delivery], $expected]) {
            [$country, $category, $rule] = $expected;

            try {
                $decision = $engine->decide($this->context($type, $supplier, $customer, $status, $delivery));
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    '%s was refused and should have resolved to %s/%s by "%s": %s',
                    $this->describe($type, $supplier, $customer, $status, $delivery),
                    $country, $category, $rule, $e->getMessage(),
                );

                continue;
            }

            if ($decision->country !== $country || $decision->category->value !== $category
                || $decision->rule !== $rule) {
                $violations[] = sprintf(
                    '%s resolved to %s/%s by "%s" and should be %s/%s by "%s"',
                    $this->describe($type, $supplier, $customer, $status, $delivery),
                    $decision->country, $decision->category->value, $decision->rule,
                    $country, $category, $rule,
                );
            }
        }

        return $violations;
    }

    /**
     * A combination with no rule.
     *
     * Built by emptying the rule book down to one rule that cannot match,
     * because every combination the shipped book covers is, by design,
     * covered. The claim is about the fallback, not about a gap.
     *
     * @return list<string>
     */
    public function anUncoveredSupplyRefuses(): array
    {
        $engine = new PlaceOfSupply(new PlaceOfSupplyRuleBook([
            'blocs' => ['gcc' => ['source' => 'test', 'members' => ['SA', 'AE']]],
            'rules' => [[
                'precedence' => 10, 'id' => 'only-bahrain', 'supply_type' => 'goods',
                'supplier' => 'BH', 'customer' => '*', 'status' => '*', 'delivery' => '*',
                'place' => 'supplier', 'category' => 'S', 'evidence' => [], 'source' => 'test',
            ]],
        ]));

        $supply = SupplyContext::deliveredTo(
            SupplyType::Goods, 'SA', 'SA', CustomerStatus::Business, 'SA',
        );

        try {
            $decision = $engine->decide($supply);
        } catch (PlaceOfSupplyUndeterminable $e) {
            return str_contains($e->getMessage(), 'No place-of-supply rule matches')
                ? []
                : ['an uncovered supply was refused with the wrong reason: '.$e->getMessage()];
        }

        return [sprintf(
            'an uncovered supply resolved to %s by "%s". A fallback that answers is a document issued '.
            'in a jurisdiction nobody chose',
            $decision->country, $decision->rule,
        )];
    }

    /**
     * The refusal that protects precedence.
     *
     * A goods supply with no delivery location matches the highest-precedence
     * rule in every respect except the one it cannot evaluate. Falling
     * through would let `domestic` answer - plausibly, with a source - while
     * the rule that outranks it was never evaluated.
     *
     * @return list<string>
     */
    public function aMissingDeliveryRefuses(): array
    {
        $engine = app(PlaceOfSupply::class);

        $supply = SupplyContext::withNoDeliveryLocation(
            SupplyType::Goods, 'SA', 'SA', CustomerStatus::Business,
        );

        try {
            $decision = $engine->decide($supply);
        } catch (PlaceOfSupplyUndeterminable $e) {
            return str_contains($e->getMessage(), 'without a delivery location')
                ? []
                : ['goods with no delivery location were refused with the wrong reason: '.$e->getMessage()];
        }

        return [sprintf(
            'a supply of goods with no delivery location resolved to %s by "%s". The rule that turns on '.
            'where the goods went was skipped, and nothing in the answer says so',
            $decision->country, $decision->rule,
        )];
    }

    /**
     * Precedence, demonstrated on two rules that both match.
     *
     * @return list<string>
     */
    public function precedenceDecides(): array
    {
        $violations = [];

        $book = static fn (int $first, int $second): array => [
            'blocs' => [],
            'rules' => [
                [
                    'precedence' => $first, 'id' => 'the-specific-one', 'supply_type' => 'goods',
                    'supplier' => '*', 'customer' => 'same', 'status' => 'business', 'delivery' => '*',
                    'place' => 'customer', 'category' => 'AE', 'evidence' => [], 'source' => 'test',
                ],
                [
                    'precedence' => $second, 'id' => 'the-general-one', 'supply_type' => '*',
                    'supplier' => '*', 'customer' => '*', 'status' => '*', 'delivery' => '*',
                    'place' => 'supplier', 'category' => 'S', 'evidence' => [], 'source' => 'test',
                ],
            ],
        ];

        $supply = SupplyContext::deliveredTo(
            SupplyType::Goods, 'SA', 'SA', CustomerStatus::Business, 'SA',
        );

        foreach ([[10, 20, 'the-specific-one'], [20, 10, 'the-general-one']] as [$a, $b, $expected]) {
            $decision = (new PlaceOfSupply(new PlaceOfSupplyRuleBook($book($a, $b))))->decide($supply);

            if ($decision->rule !== $expected) {
                $violations[] = sprintf(
                    'with precedences %d and %d the winner was "%s" and should have been "%s" - the '.
                    'engine is not reading precedence',
                    $a, $b, $decision->rule, $expected,
                );
            }
        }

        /*
         | And the file's own order must not matter.
         |
         | Same two rules, same precedences, written the other way round. If
         | the answer changes, the engine is honouring array order - which is
         | invisible in a diff and would make a reformatting change what
         | documents charge.
         */
        $reversed = $book(10, 20);
        $reversed['rules'] = array_reverse($reversed['rules']);
        $decision = (new PlaceOfSupply(new PlaceOfSupplyRuleBook($reversed)))->decide($supply);

        if ($decision->rule !== 'the-specific-one') {
            $violations[] = sprintf(
                'reversing the order of two rules in the file changed the winner to "%s". Array order '.
                'decides, which means a reformatting changes what a document charges',
                $decision->rule,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDecisionExplainsItself(): array
    {
        $violations = [];
        $engine = app(PlaceOfSupply::class);

        $decision = $engine->decide(SupplyContext::withNoDeliveryLocation(
            SupplyType::DigitalServices, 'SA', 'FR', CustomerStatus::Business,
        ));

        foreach ([
            'the winning rule' => $decision->rule === 'services-to-a-business-abroad',
            'its precedence' => $decision->precedence === 80,
            'a legal source' => str_contains($decision->source, 'Art. 44'),
            'the reverse-charge verdict' => $decision->isReverseCharge(),
            'that the tax is charged abroad' => $decision->isForeign(),
            'an English explanation naming the rule' => str_contains($decision->explain('en'), 'services-to-a-business-abroad'),
            'an Arabic explanation' => str_contains($decision->explain('ar'), 'مكان التوريد'),
            'the supply it was decided from' => $decision->supply->customerCountry === 'FR',
        ] as $what => $held) {
            if (! $held) {
                $violations[] = sprintf(
                    'the decision does not carry %s. An accountant checking an invoice has to be able to '.
                    'disagree with it, and that needs the reasoning, not the answer',
                    $what,
                );
            }
        }

        $frozen = $decision->jsonSerialize();

        foreach (['country', 'category', 'rule', 'precedence', 'source', 'reverse_charge', 'supply'] as $key) {
            if (! array_key_exists($key, $frozen)) {
                $violations[] = sprintf(
                    'the serialised decision has no "%s", so freezing it onto a document would lose it',
                    $key,
                );
            }
        }

        return $violations;
    }

    /**
     * Every mistake a configuration file allows, refused.
     *
     * Each case is a single-field mutation of a rule book that loads, so a
     * case that stops failing means that specific validation was lost rather
     * than that the fixture drifted.
     *
     * @return list<string>
     */
    public function theRuleBookIsValidated(): array
    {
        $violations = [];

        $valid = [
            'precedence' => 10, 'id' => 'a-rule', 'supply_type' => 'goods', 'supplier' => '*',
            'customer' => 'same', 'status' => '*', 'delivery' => '*', 'place' => 'supplier',
            'category' => 'S', 'evidence' => [], 'source' => 'Art. 31',
        ];

        $cases = [
            'a missing condition' => ['rules' => [array_diff_key($valid, ['delivery' => null])]],
            'an unknown key' => ['rules' => [[...$valid, 'catagory' => 'S']]],
            'a word outside the vocabulary' => ['rules' => [[...$valid, 'customer' => 'nearby']]],
            'a country that is not ISO 3166-1' => ['rules' => [[...$valid, 'customer' => 'EL']]],
            'an undeclared bloc' => ['rules' => [[...$valid, 'customer' => 'bloc:nafta']]],
            'a place that is not a party to the supply' => ['rules' => [[...$valid, 'place' => 'warehouse']]],
            'a tax category that is not UNCL 5305' => ['rules' => [[...$valid, 'category' => 'QQ']]],
            'a rule with no source' => ['rules' => [[...$valid, 'source' => '  ']]],
            'two rules at the same precedence' => ['rules' => [$valid, [...$valid, 'id' => 'another']]],
            'two rules with the same id' => ['rules' => [$valid, [...$valid, 'precedence' => 20]]],
            'a rule answering with an unconstrained delivery location' => [
                'rules' => [[...$valid, 'place' => 'delivery']],
            ],
            'an evidence kind nothing implements' => ['rules' => [[...$valid, 'evidence' => ['a_hunch']]]],
            'an evidence list that is not a list' => ['rules' => [[...$valid, 'evidence' => 'export_proof']]],
            'an empty rule book' => ['rules' => []],
            'a bloc member that is not ISO 3166-1' => [
                'rules' => [$valid],
                'blocs' => ['eu' => ['source' => 'test', 'members' => ['FR', 'XI']]],
            ],
            'a bloc with no source' => [
                'rules' => [$valid],
                'blocs' => ['eu' => ['members' => ['FR']]],
            ],
            'a country listed as both a member and a departure' => [
                'rules' => [$valid],
                'blocs' => ['eu' => ['source' => 'test', 'members' => ['FR', 'GB'], 'left' => ['GB' => '2020-12-31']]],
            ],
        ];

        foreach ($cases as $what => $book) {
            $book['blocs'] ??= [];

            try {
                (new PlaceOfSupplyRuleBook($book))->rules();
            } catch (RuleBookUnusable) {
                continue;
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    '%s was refused by %s rather than by the rule book, so the message does not say '.
                    'which row is wrong: %s',
                    $what, $e::class, $e->getMessage(),
                );

                continue;
            }

            $violations[] = sprintf(
                '%s loaded without complaint. Every mistake available in a configuration file is '.
                'silent, and this one changes what a document charges',
                $what,
            );
        }

        /*
         | And the real book must load, or the cases above prove only that
         | everything is refused.
         */
        try {
            $count = \count((new PlaceOfSupplyRuleBook())->rules());

            if ($count < \count(self::CASES) - 6) {
                $violations[] = sprintf('the shipped rule book holds only %d rules', $count);
            }
        } catch (Throwable $e) {
            $violations[] = 'the shipped rule book does not load: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function blocMembershipIsReal(): array
    {
        $violations = [];
        $blocs = (new PlaceOfSupplyRuleBook())->blocs();

        /*
         | Written out, because the point of the check is to disagree with the
         | file. 27 EU members and 6 GCC members, and a count that drifts is a
         | country that was added or lost without anybody saying so.
         */
        foreach (['eu' => 27, 'gcc' => 6] as $bloc => $expected) {
            $actual = \count($blocs->membersOf($bloc));

            if ($actual !== $expected) {
                $violations[] = sprintf(
                    'the %s bloc has %d members and should have %d. A membership change is a change to '.
                    'what every cross-border supply to that country is charged',
                    $bloc, $actual, $expected,
                );
            }

            if ($blocs->source($bloc) === null) {
                $violations[] = sprintf('the %s bloc cites no instrument', $bloc);
            }
        }

        foreach (['AE', 'BH', 'KW', 'OM', 'QA', 'SA'] as $member) {
            if (! $blocs->isMember('gcc', $member)) {
                $violations[] = sprintf('%s is not in the GCC bloc', $member);
            }
        }

        if ($blocs->isMember('eu', 'GB')) {
            $violations[] = 'GB is still listed as an EU member, which would report an intra-Community '.
                'supply for a country that left the Community';
        }

        if ($blocs->leftOn('eu', 'GB') === null) {
            $violations[] = 'GB\'s departure from the EU carries no date. A credit note against a 2019 '.
                'invoice has to be treated the way the original was, and without the date there is '.
                'nothing to treat it by';
        }

        if ($blocs->share('SA', 'AE') === false || $blocs->share('SA', 'FR') === true) {
            $violations[] = 'bloc sharing is wrong: SA/AE share the GCC and SA/FR share nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    public function anUnknownCountryRefuses(): array
    {
        try {
            SupplyContext::withNoDeliveryLocation(
                SupplyType::Services, 'SA', 'ZZ', CustomerStatus::Business,
            );
        } catch (PlaceOfSupplyUndeterminable $e) {
            return str_contains($e->getMessage(), 'ISO 3166-1')
                ? []
                : ['an unknown country was refused with the wrong reason: '.$e->getMessage()];
        }

        return ['the customer country "ZZ" was accepted. No rule can match it, so the engine would '.
            'answer "no rule matched" and send somebody looking for a missing rule'];
    }

    private function context(
        string $type,
        string $supplier,
        string $customer,
        string $status,
        ?string $delivery,
    ): SupplyContext {
        $supplyType = SupplyType::from($type);
        $customerStatus = CustomerStatus::from($status);

        return $delivery === null
            ? SupplyContext::withNoDeliveryLocation($supplyType, $supplier, $customer, $customerStatus)
            : SupplyContext::deliveredTo($supplyType, $supplier, $customer, $customerStatus, $delivery);
    }

    private function describe(
        string $type,
        string $supplier,
        string $customer,
        string $status,
        ?string $delivery,
    ): string {
        return sprintf(
            '%s from %s to a %s in %s, delivered %s',
            str_replace('_', ' ', $type), $supplier, $status, $customer, $delivery ?? 'nowhere stated',
        );
    }
}
