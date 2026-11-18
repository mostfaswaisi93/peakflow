<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Iso\Exceptions\UnknownUnit;
use App\Support\Iso\UnitRegistry;
use App\Tenant\Catalog\Exceptions\NoPriceFound;
use App\Tenant\Catalog\Models\Item;
use App\Tenant\Catalog\Models\PriceList;
use App\Tenant\Catalog\Models\PriceListLine;
use App\Tenant\Catalog\Models\PricePoint;
use App\Tenant\Catalog\Services\PriceResolver;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Items and the prices they resolve to - PF-064, PF-065.
 *
 * ## The two things worth guarding
 *
 * **A price that cannot be explained.** "Why is this price 94.50" is asked
 * every week, and a resolver that returns only a number cannot be made to
 * answer it afterwards - by then the candidates are gone. So every check here
 * reads the derivation as well as the figure, and one of them asserts that
 * two lists cannot make a price ambiguous in the first place.
 *
 * **A price resolved against today instead of the document's date.** A credit
 * note issued in April against a March invoice must use March's price.
 * Reading "the current list" reprices history whenever somebody edits a list,
 * and the first sign of it is a credit note that does not match the invoice
 * it credits.
 */
final class CatalogRules
{
    private ?Connection $connection = null;

    private string $suffix = '';

    private ?Item $item = null;

    /** @var array<string, PriceList> */
    private array $lists = [];

    private Carbon $march;

    private ?PriceList $threeRulesBase = null;

    public function __construct()
    {
        $this->march = Carbon::parse('2026-03-15');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        // No database needed for the unit registry, so it runs anywhere.
        $results = ['a unit of measure is a Rec 20 code, not a word' => $this->unitsAreCoded()];

        $tenant = $this->tenant();

        if ($tenant === null) {
            return $results + ['catalog (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $results + [
                'an item type is checked, because it drives place of supply' => $this->itemTypesAreChecked(),
                'a price beats the less specific lists, and says which' => $this->resolutionOrderHolds(),
                'a quantity tier takes the highest floor at or below it' => $this->tiersTakeTheRightFloor(),
                'two lists cannot make one price ambiguous' => $this->pricesAreUnambiguous(),
                "history resolves by the document's date" => $this->historyIsStable(),
                'a missing price is refused, never zero' => $this->missingPricesAreRefused(),
                'a unit price keeps eight decimals and rounds once' => $this->precisionSurvives(),

                // ---- Price history and derivation - PF-254 -------------
                'every price change keeps its date, person and reason' => $this->priceChangesAreKept(),
                'a price point cannot be edited or removed' => $this->pricePointsAreFacts(),
                'a change scheduled for a future date needs nothing to run' => $this->aScheduledChangeArrives(),
                'and it is visible before it arrives' => $this->aScheduledChangeIsVisible(),
                'a backdated change asks for a name and a sentence' => $this->backdatingIsAskedFor(),
                'a derived list re-runs its rule rather than copying' => $this->aDerivedListIsReRun(),
                'a tier whose first price starts later is not today\'s' => $this->aFuturePriceIsNotTodays(),
                'a margin, a markup and a discount are different arithmetic' => $this->theThreeRulesDiffer(),
                "a derived list's own line beats its rule" => $this->anExceptionBeatsTheRule(),
                'a derivation that loops is refused by name' => $this->aLoopingDerivationIsRefused(),
            ];
        } catch (Throwable $e) {
            return $results + ['catalog could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function unitsAreCoded(): array
    {
        $violations = [];

        if (UnitRegistry::countable() !== 'C62') {
            $violations[] = 'the default countable unit is not C62. EA is the obvious guess and several '.
                'national e-invoicing profiles reject it where C62 is expected';
        }

        foreach (['HUR', 'KGM', 'C62', 'KWH', 'ANN'] as $code) {
            if (! UnitRegistry::exists($code)) {
                $violations[] = sprintf('%s is missing from the unit list', $code);
            }
        }

        /*
         | The guesses. An unknown code is REJECTED by the receiving system
         | rather than queried, so the refusal has to name candidates - a
         | message saying only "unknown unit" sends somebody to the standard
         | rather than to the answer.
         */
        foreach (['HR' => 'HUR', 'KG' => 'KGM', 'EACH' => 'EA', 'HOURS' => 'HUR'] as $guess => $meant) {
            try {
                UnitRegistry::assertExists($guess);

                $violations[] = sprintf('"%s" was accepted as a unit code', $guess);
            } catch (UnknownUnit $e) {
                if (! str_contains($e->getMessage(), $meant)) {
                    $violations[] = sprintf(
                        '"%s" was refused without suggesting %s, which is what it means',
                        $guess,
                        $meant,
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function itemTypesAreChecked(): array
    {
        $violations = [];

        if ($this->item?->placeOfSupplyFollowsCustomer() !== true) {
            $violations[] = 'a digital service does not report that place of supply follows the customer. '.
                'The type is not a category: it decides which country\'s VAT applies, and getting it wrong '.
                'files a return in the wrong country';
        }

        $unknownType = $this->refusal(fn () => $this->connection->table('items')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'CHK-TYPE-'.Str::random(6),
            'name_ar' => 'نوع',
            'name_en' => 'Unknown type',
            'type' => 'subscription',
            'unit_code' => 'C62',
            'recognition' => 'immediate',
            'sellable' => true,
            'purchasable' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($unknownType === null) {
            $violations[] = 'an item type outside good/service/digital_service was accepted, so place of '.
                'supply has a fourth case nothing handles';
        }

        /*
         | `over_period` with no period.
         |
         | A recognition schedule nobody can compute, and it fails at the
         | moment of invoicing rather than at the moment of configuration.
         */
        $noPeriod = $this->refusal(fn () => $this->connection->table('items')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'CHK-PER-'.Str::random(6),
            'name_ar' => 'فترة',
            'name_en' => 'No period',
            'type' => 'service',
            'unit_code' => 'C62',
            'recognition' => 'over_period',
            'recognition_months' => null,
            'sellable' => true,
            'purchasable' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($noPeriod === null) {
            $violations[] = 'an item recognised over a period with no period was accepted';
        }

        return $violations;
    }

    /** @return list<string> */
    public function resolutionOrderHolds(): array
    {
        $resolver = app(PriceResolver::class);
        $violations = [];
        $itemId = (string) $this->item?->getKey();

        $general = $resolver->resolve($itemId, 'SAR', $this->march);

        if ($general['unit_price'] !== '100.00000000') {
            $violations[] = sprintf('the general list resolved to %s rather than 100', $general['unit_price']);
        }

        $group = $resolver->resolve($itemId, 'SAR', $this->march, partyGroup: 'retail');

        if ($group['unit_price'] !== '95.00000000') {
            $violations[] = sprintf(
                'a group list did not beat the general one: got %s. Specificity comes before priority, and '.
                'that order is not configurable - an order a tenant can reorder is one nobody can explain',
                $group['unit_price'],
            );
        }

        $party = $resolver->resolve($itemId, 'SAR', $this->march, partyId: 'PARTYCHK', partyGroup: 'retail');

        if ($party['unit_price'] !== '88.00000000') {
            $violations[] = sprintf('a party list did not beat the group one: got %s', $party['unit_price']);
        }

        /*
         | The derivation, which is the requirement.
         |
         | A screen has to be able to show which list and which rule produced
         | a given price. A resolver returning only a number cannot be made
         | to do that afterwards.
         */
        if (! str_contains($party['derivation'], 'for this party')
            || ! str_contains($party['derivation'], 'in force from')) {
            $violations[] = 'a resolved price does not say which list it came from, for whom, or over what '.
                'dates - so "why is this price 88.00" has no answer but a guess';
        }

        if (! $this->mentions($party['beaten'], (string) $this->lists['group']->code)) {
            $violations[] = 'a resolved price does not report the lists it beat, so a screen can show the '.
                'answer and not the alternatives it was chosen over';
        }

        return $violations;
    }

    /** @return list<string> */
    public function tiersTakeTheRightFloor(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        $this->tier($this->lists['general']->getKey(), $itemId, '10', unitPrice: '85.00000000');

        $this->tier($this->lists['general']->getKey(), $itemId, '100', unitPrice: '78.00000000');

        /*
         | Nine, ten and five hundred.
         |
         | Nine is the one that matters: a resolver ordering tiers ascending
         | picks the base price for every order, which makes every quantity
         | break decorative. The symptom is a customer invoiced at list
         | price for a container load - which they notice and we do not.
         */
        $expected = ['9' => '100.00000000', '10' => '85.00000000', '500' => '78.00000000'];

        foreach ($expected as $quantity => $price) {
            $resolved = $resolver->resolve($itemId, 'SAR', $this->march, quantity: (string) $quantity);

            if ($resolved['unit_price'] !== $price) {
                $violations[] = sprintf(
                    '%s units resolved to %s rather than %s',
                    $quantity,
                    $resolved['unit_price'],
                    $price,
                );
            }
        }

        $five = $resolver->resolve($itemId, 'SAR', $this->march, quantity: '500');

        if (! str_contains($five['derivation'], 'tier from 100 units')) {
            $violations[] = 'a tiered price does not say which tier it used';
        }

        return $violations;
    }

    /** @return list<string> */
    public function pricesAreUnambiguous(): array
    {
        $violations = [];

        /*
         | A second general list is FINE on its own.
         |
         | The first version of this guard was an EXCLUDE constraint on the
         | lists, which forbade two general lists in one currency at one
         | priority regardless of what was on them - so a utility tariff
         | list could not coexist with a general goods list, which is an
         | ordinary setup. It failed on the first probe that tried it.
         */
        $second = PriceList::create([
            'code' => 'CHK-SEC-'.$this->suffix,
            'name_ar' => 'ثانية',
            'name_en' => 'Second general',
            'currency' => 'SAR',
            'valid_from' => '2026-01-01',
            'priority' => 0,
        ]);

        if (! $second->exists) {
            $violations[] = 'a second general list could not be created at all, which forbids an ordinary '.
                'setup: a utility tariff list beside a general goods list';
        }

        // What must be refused is the same ITEM on both.
        $clash = $this->refusal(fn () => $this->tier($second->getKey(), (string) $this->item?->getKey(), '0', unitPrice: '1.00000000'));

        if ($clash === null) {
            $violations[] = 'one item is now priced by two lists identical in specificity, priority and '.
                'dates. Which wins depends on row order, so the same invoice priced twice can come out '.
                'differently and no screen can explain either answer';
        } elseif (! str_contains($clash, 'row order')) {
            $violations[] = 'the clash was refused without explaining why it cannot be allowed: '.$clash;
        }

        return $violations;
    }

    /** @return list<string> */
    public function historyIsStable(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        $rise = PriceList::create([
            'code' => 'CHK-RISE-'.$this->suffix,
            'name_ar' => 'زيادة',
            'name_en' => 'April rise',
            'currency' => 'SAR',
            'valid_from' => '2026-04-01',
            'priority' => 20,
        ]);

        $this->tier($rise->getKey(), $itemId, '0', unitPrice: '110.00000000');

        if ($resolver->resolve($itemId, 'SAR', $this->march)['unit_price'] !== '100.00000000') {
            $violations[] = 'a March invoice was repriced by an April list. A resolver reading "the current '.
                'price list" reprices history whenever somebody edits one, and the first sign of it is a '.
                'credit note that does not match the invoice it credits';
        }

        $april = $resolver->resolve($itemId, 'SAR', Carbon::parse('2026-04-15'));

        if ($april['unit_price'] !== '110.00000000') {
            $violations[] = sprintf('an April invoice priced at %s rather than the April list', $april['unit_price']);
        }

        return $violations;
    }

    /** @return list<string> */
    public function missingPricesAreRefused(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        /*
         | Refused, not zero.
         |
         | A zero price invoices nothing and still balances: the line
         | appears, the total is short, the VAT is short, and the customer
         | pays what they were asked for. It is found when somebody reads the
         | margin report, by which point the return has been filed.
         */
        try {
            $price = $resolver->resolve($itemId, 'EUR', $this->march);

            $violations[] = sprintf(
                'an item with no EUR price list resolved to %s rather than being refused',
                $price['unit_price'],
            );
        } catch (NoPriceFound $e) {
            if (! str_contains($e->getMessage(), 'refused rather than priced at zero')) {
                $violations[] = 'a missing price was refused without saying why it is not zero';
            }
        }

        try {
            $resolver->resolve($itemId, 'SAR', Carbon::parse('2025-06-01'));

            $violations[] = 'a date before every list starts still resolved to a price';
        } catch (NoPriceFound) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function precisionSurvives(): array
    {
        $resolver = app(PriceResolver::class);
        $violations = [];

        /*
         | 0.0725 per unit, ten thousand units.
         |
         | Rounding the price to the currency's two decimals before
         | multiplying gives 700 or 730 instead of 725 - a 3.5% error on a
         | utility invoice, which is the whole reason the UnitPrice scale
         | exists.
         */
        $line = $resolver->extended('0.07250000', '10000', 'SAR');

        if ((string) $line->amount !== '725.0000') {
            $violations[] = sprintf(
                '0.0725 times 10,000 came out as %s rather than 725.0000. Rounding a unit price to the '.
                "currency's own exponent before multiplying is a 3.5%% error on a utility invoice",
                (string) $line->amount,
            );
        }

        /*
         | And the stored amount is at the amount scale whatever the
         | currency. The currency's exponent applies at PRESENTATION: storing
         | the display value is how a three-decimal currency rounded to two
         | on a document, and then summed from the document, drifts by a few
         | fils a month.
         */
        $jod = $resolver->extended('0.07254000', '10000', 'JOD');

        if ((string) $jod->amount !== '725.4000') {
            $violations[] = sprintf('a JOD line stored %s rather than at the amount scale', (string) $jod->amount);
        }

        if ((string) $jod->toPresentation() !== '725.400') {
            $violations[] = sprintf(
                'a JOD amount presents as %s rather than at three decimals - JOD, BHD, KWD, OMR and TND are '.
                'three-decimal currencies and four of them are target markets',
                (string) $jod->toPresentation(),
            );
        }

        return $violations;
    }

    /** @param list<string> $haystack */
    private function mentions(array $haystack, string $needle): bool
    {
        foreach ($haystack as $entry) {
            if (str_contains($entry, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function refusal(callable $write): ?string
    {
        try {
            $this->connection->transaction(function () use ($write): void {
                $write();

                throw new CatalogWriteAccepted();
            });
        } catch (CatalogWriteAccepted) {
            return null;
        } catch (Throwable $e) {
            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }

        return null;
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'CAT'.random_int(100, 999),
            'legal_name' => 'Catalog check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context->use($entity);
        $this->suffix = Str::random(6);

        $this->item = Item::create([
            'code' => 'CHK-ITM-'.$this->suffix,
            'name_ar' => 'ترخيص سنوي',
            'name_en' => 'Annual licence',
            'type' => 'digital_service',
            'unit_code' => 'ANN',
            'recognition' => 'over_period',
            'recognition_months' => 12,
            'sellable' => true,
        ]);

        foreach ([
            ['general', null, null, '100.00000000'],
            ['group', null, 'retail', '95.00000000'],
            ['party', 'PARTYCHK', null, '88.00000000'],
        ] as [$key, $partyId, $group, $price]) {
            $list = PriceList::create([
                'code' => 'CHK-'.strtoupper($key).'-'.$this->suffix,
                'name_ar' => $key,
                'name_en' => ucfirst($key).' list',
                'currency' => 'SAR',
                'party_id' => $partyId,
                'party_group' => $group,
                'valid_from' => '2026-01-01',
                'priority' => 0,
            ]);

            $this->tier($list->getKey(), (string) $this->item->getKey(), '0', unitPrice: $price);

            $this->lists[$key] = $list;
        }
    }


    // ---- Price history and derivation - PF-254 ----------------------------

    /** @return list<string> */
    private function priceChangesAreKept(): array
    {
        $resolver = app(PriceResolver::class);
        $prices = app(\App\Tenant\Catalog\Services\Prices::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        [$list, $tier] = $this->ownList('kept', 101);

        /*
         | 100 in January, 120 from May.
         |
         | "What did this cost in March" is asked by a customer holding
         | an invoice, and the only way to answer it is to have kept
         | March.
         */
        $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2026-05-01'),
            reason: 'the supplier raised their price from the start of May',
            userId: str_repeat('2', 26),
            unitPrice: '120.00000000',
        );

        foreach ([
            ['2026-03-15', '100.00000000', 'March'],
            ['2026-05-18', '120.00000000', 'May'],
        ] as [$on, $expected, $label]) {
            $resolved = $resolver->resolve($itemId, 'SAR', Carbon::parse($on), 'PARTYKEPT')['unit_price'];

            if ($resolved !== $expected) {
                $violations[] = sprintf(
                    'a %s document priced at %s rather than %s - a price kept in a column that is '.
                    'overwritten cannot answer "what did this cost in March"',
                    $label,
                    $resolved,
                    $expected,
                );
            }
        }

        $history = $prices->historyOf((string) $tier?->getKey());

        if (\count($history) !== 2) {
            $violations[] = sprintf('the tier has %d point(s) of history rather than 2', \count($history));
        }

        /*
         | And each one names its date, its person and its reason.
         |
         | A history that recorded only the numbers answers "what was
         | it" and not "who changed it and why", which is the question
         | asked when a customer disputes the invoice.
         */
        foreach ($history as $point) {
            foreach (['effective_from', 'changed_by', 'reason'] as $field) {
                if (trim((string) ($point[$field] ?? '')) === '') {
                    $violations[] = sprintf('a price point carries no %s', $field);
                }
            }
        }

        if (($history[0]['changed_by'] ?? '') !== str_repeat('2', 26)) {
            $violations[] = 'the May change is not attributed to the person who made it';
        }

        return $violations;
    }

    /** @return list<string> */
    private function pricePointsAreFacts(): array
    {
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        $point = PricePoint::query()
            ->whereIn('price_list_line_id', PriceListLine::query()
                ->where('item_id', $itemId)
                ->pluck('id'))
            ->first();

        foreach ([
            'changed' => fn () => DB::connection('tenant')->table('price_points')
                ->where('id', (string) $point?->getKey())
                ->update(['unit_price' => '1.00000000']),
            'removed' => fn () => DB::connection('tenant')->table('price_points')
                ->where('id', (string) $point?->getKey())
                ->delete(),
        ] as $label => $attempt) {
            $refusal = $this->refusal($attempt);

            if ($refusal === null) {
                $violations[] = sprintf(
                    'a price point can be %s - which rewrites what a customer was charged, while the '.
                    'invoice that quoted it does not change with it',
                    $label,
                );
            } elseif (! str_contains($refusal, 'record a new point')) {
                $violations[] = 'the refusal does not say what to do instead: '.$refusal;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function aScheduledChangeArrives(): array
    {
        $resolver = app(PriceResolver::class);
        $prices = app(\App\Tenant\Catalog\Services\Prices::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        [$list, $tier] = $this->ownList('arrives', 102);

        $scheduled = $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::now()->addMonth()->startOfMonth(),
            reason: 'the annual increase agreed with the supplier in October',
            userId: str_repeat('2', 26),
            unitPrice: '150.00000000',
        );

        if (($scheduled['scheduled'] ?? false) !== true) {
            $violations[] = 'a change dated next month does not report itself as scheduled';
        }

        /*
         | Today's price is unchanged, and next month's is the new one.
         |
         | Nothing runs in between. The row simply becomes the latest
         | point at or before the document date, which is the whole
         | mechanism - no job to queue and nothing to forget on a public
         | holiday.
         */
        $today = $resolver->resolve($itemId, 'SAR', Carbon::now(), 'PARTYARRIVES')['unit_price'];

        if ($today === '150.00000000') {
            $violations[] = 'a change dated next month is already in force today, so a rise takes '.
                'effect the moment somebody types it';
        }

        $later = $resolver->resolve(
            $itemId,
            'SAR',
            Carbon::now()->addMonth()->startOfMonth()->addDay(),
            'PARTYARRIVES',
        )['unit_price'];

        if ($later !== '150.00000000') {
            $violations[] = sprintf(
                'next month resolves to %s rather than the scheduled 150 - something has to be run for '.
                'the change to arrive, and that is the thing nobody runs',
                $later,
            );
        }

        /*
         | And two changes cannot share a day.
         |
         | Which row wins would depend on how the planner read them, and
         | the symptom is an invoice whose price nobody can reproduce.
         */
        $clash = $this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::now()->addMonth()->startOfMonth(),
            reason: 'a second attempt at the same increase, typed by somebody else',
            userId: str_repeat('3', 26),
            unitPrice: '155.00000000',
        ));

        if ($clash === null) {
            $violations[] = 'two price changes were recorded for one tier on one date';
        } elseif (! str_contains($clash, 'which row the planner happened to read')) {
            /*
             | And the sentence is the service's, not the index's.
             |
             | The unique index refuses it too, which is the control -
             | but "duplicate key value violates unique constraint" is
             | read by somebody scheduling a price rise, and it tells
             | them nothing to do.
             */
            $violations[] = 'a clashing date is refused by the index rather than explained by the '.
                'service: '.$clash;
        }

        /*
         | And a change with no reason is refused with a sentence.
         |
         | The column refuses a short reason too. The difference is that
         | "violates check constraint price_points_say_why" sends
         | somebody to the schema, and this sends them to the sentence
         | they have to type.
         */
        $silent = $this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2027-01-01'),
            reason: 'up',
            userId: str_repeat('2', 26),
            unitPrice: '160.00000000',
        ));

        if ($silent === null) {
            $violations[] = 'a price change was recorded with "up" as its reason - a supplier increase, '.
                'a promotion and a corrected typo are read differently when a customer disputes an '.
                'invoice';
        } elseif (! str_contains($silent, 'tells nobody which it was')) {
            $violations[] = 'a reasonless change is refused by the column rather than explained by the '.
                'service: '.$silent;
        }

        /*
         | And a point that is neither a price nor a discount is refused.
         */
        $neither = $this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2027-02-01'),
            reason: 'a point with no figure on it at all, typed by mistake',
            userId: str_repeat('2', 26),
        ));

        if ($neither === null) {
            $violations[] = 'a price point was recorded with neither an amount nor a percentage';
        } elseif (! str_contains($neither, 'never both and never neither')) {
            $violations[] = 'a figureless point is refused by the column rather than explained by the '.
                'service: '.$neither;
        }

        /*
         | And so is a point that is both.
         |
         | Both is two answers to one question, and whichever the
         | resolver reads first becomes a rule nobody wrote down.
         */
        $both = $this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2027-03-01'),
            reason: 'a point carrying an amount and a percentage at once',
            userId: str_repeat('2', 26),
            unitPrice: '150.00000000',
            discountPercent: '10.000000',
        ));

        if ($both === null) {
            $violations[] = 'a price point was recorded as both an amount and a percentage off the '.
                'next list down';
        }

        return $violations;
    }

    /** @return list<string> */
    private function aScheduledChangeIsVisible(): array
    {
        $prices = app(\App\Tenant\Catalog\Services\Prices::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        [$list, $tier] = $this->ownList('visible', 103);

        $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::now()->addMonth()->startOfMonth(),
            reason: 'the annual increase agreed with the supplier in October',
            userId: str_repeat('2', 26),
            unitPrice: '150.00000000',
        );

        /*
         | Scoped to this check's own list, because the shared
         | transaction carries the other checks' pending changes too.
         */
        $pending = $prices->pending((string) $list->getKey());

        if ($pending === []) {
            return ['a change dated next month is invisible until it happens, so a rise is discovered '.
                'by a customer'];
        }

        $row = $pending[0];

        /*
         | And it says what it replaces.
         |
         | "94.50 becomes 150.00 on the first" is a sentence somebody
         | acts on. "150.00 from the first" is one they have to go and
         | compare, and mostly will not.
         */
        if (($row['current_price'] ?? '') !== '100.00000000') {
            /*
             | By value, not by "is it null".
             |
             | A bite that replaced the sub-select with `IS NULL` turned
             | the figure into the string "false" and the first draft of
             | this check passed on it, because it only asked whether
             | something was there.
             */
            $violations[] = sprintf(
                'the pending change says it replaces %s rather than the 100 in force, so the size of '.
                'the rise is not readable from the list',
                (string) ($row['current_price'] ?? '-'),
            );
        }

        if (($row['unit_price'] ?? '') !== '150.00000000') {
            $violations[] = sprintf('the pending change reads %s', (string) ($row['unit_price'] ?? '-'));
        }

        if (! str_contains((string) ($row['reason'] ?? ''), 'annual increase')) {
            $violations[] = 'the pending change does not carry its reason';
        }

        /*
         | And a change already in force is not pending.
         |
         | A list that showed every price as "upcoming" would be read by
         | nobody, which is the same as not having it.
         */
        foreach ($pending as $candidate) {
            if (Carbon::parse((string) $candidate['effective_from'])->lte(Carbon::now())) {
                $violations[] = sprintf(
                    'a change already in force from %s is listed as pending',
                    (string) $candidate['effective_from'],
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function backdatingIsAskedFor(): array
    {
        $prices = app(\App\Tenant\Catalog\Services\Prices::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        [$list, $tier] = $this->ownList('backdate', 104);

        $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2026-05-01'),
            reason: 'the supplier raised their price from the start of May',
            userId: str_repeat('2', 26),
            unitPrice: '120.00000000',
        );

        /*
         | A price dated BENEATH the one already in force.
         |
         | Which changes an answer the resolver has already given, while
         | the invoices that quoted the old price do not change with it.
         */
        $silent = $this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2026-04-01'),
            reason: 'correcting a figure that was entered a decimal place out',
            userId: str_repeat('2', 26),
            unitPrice: '115.00000000',
        ));

        if ($silent === null) {
            $violations[] = 'a price was backdated under one already in force with nobody accepting it, '.
                'so the price list and the invoices that quoted it now disagree silently';
        } elseif (! str_contains($silent, 'comparing two of them')) {
            $violations[] = 'the refusal does not say who finds out: '.$silent;
        }

        /*
         | And with a name and a sentence it goes through.
         |
         | Sometimes it is right - a price entered as 1500 that should
         | have been 15.00, caught before anything was invoiced - and a
         | product that said no outright would have the correction made
         | by an UPDATE somebody runs against the database, which loses
         | the reason and the name with it.
         */
        $accepted = $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2026-04-01'),
            reason: 'correcting a figure that was entered a decimal place out',
            userId: str_repeat('2', 26),
            unitPrice: '115.00000000',
            backdatedBy: str_repeat('3', 26),
            backdatedReason: 'nothing was invoiced from this list in April, checked against the ledger',
        );

        if (($accepted['backdated'] ?? false) !== true) {
            $violations[] = 'an accepted backdated change does not report itself as one, so it reads '.
                'like an ordinary price change in the audit trail';
        }

        /*
         | A forward change needs no such acceptance.
         |
         | Asking for a second name on every price change is how the
         | second name stops meaning anything.
         */
        if ($this->refusal(fn () => $prices->schedule(
            priceListLineId: (string) $tier?->getKey(),
            effectiveFrom: Carbon::parse('2026-06-01'),
            reason: 'the second stage of the increase, as agreed in the contract',
            userId: str_repeat('2', 26),
            unitPrice: '125.00000000',
        )) !== null) {
            $violations[] = 'an ordinary forward-dated change was refused for having no acceptance, '.
                'which is how the acceptance becomes a rubber stamp';
        }

        return $violations;
    }

    /** @return list<string> */
    private function aDerivedListIsReRun(): array
    {
        $resolver = app(PriceResolver::class);
        $prices = app(\App\Tenant\Catalog\Services\Prices::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        [$base, $baseTier] = $this->ownList('rerunbase', 105);

        $wholesale = PriceList::create([
            'code' => 'CHK-WHOLESALE-'.$this->suffix,
            'name_ar' => 'الجملة',
            'name_en' => 'Wholesale',
            'currency' => 'SAR',
            'party_id' => 'PARTYCHK',
            'valid_from' => '2026-01-01',
            'priority' => 50,
            'derived_from_id' => (string) $base->getKey(),
            'derivation_kind' => 'discount',
            'derivation_percent' => '15.000000',
        ]);

        /*
         | The derived list has NO line for this item.
         |
         | Which is the point: driving the candidate query from
         | `price_list_lines` made such a list no candidate at all, so
         | the derivation could never be reached.
         */
        $resolved = $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYCHK');

        if ($resolved['unit_price'] !== '85.00000000') {
            $violations[] = sprintf(
                'a list that is the general list less 15%% resolved to %s rather than 85',
                $resolved['unit_price'],
            );
        }

        if (! str_contains($resolved['derivation'], 'derived as discount')) {
            $violations[] = 'the answer does not say it was derived, so "why is this price 85" is '.
                'unanswerable from the screen: '.$resolved['derivation'];
        }

        /*
         | And it does not read as a discount LINE.
         |
         | The two have different shapes and the first draft ran both
         | through one branch, producing "; % off CHK-RERUNBASE derived
         | as discount 15% of ..." - an empty percentage and the word
         | "off" in front of a sentence that was not a discount. The
         | check above passed on exactly that, because the note it
         | looked for was in there somewhere.
         */
        if (str_contains($resolved['derivation'], '% off') || str_contains($resolved['derivation'], ';  ')) {
            $violations[] = 'a derived list is explained as though it were a discount line, with an '.
                'empty percentage in the sentence: '.$resolved['derivation'];
        }

        if (($resolved['list_id'] ?? '') === '') {
            $violations[] = 'the answer does not name the list by id, so a line that snapshots it '.
                'cannot be traced back once somebody tidies the codes up';
        }

        /*
         | The base moves, and the derived price moves with it.
         |
         | A copy with the arithmetic already done stops agreeing with
         | its base the first time the base moves, and nobody notices
         | because both numbers look deliberate.
         */
        $prices->schedule(
            priceListLineId: (string) $baseTier->getKey(),
            effectiveFrom: Carbon::parse('2026-05-01'),
            reason: 'the supplier raised their price from the start of May',
            userId: str_repeat('2', 26),
            unitPrice: '200.00000000',
        );

        $after = $resolver->resolve($itemId, 'SAR', Carbon::parse('2026-05-18'), 'PARTYCHK');

        if ($after['unit_price'] !== '170.00000000') {
            $violations[] = sprintf(
                'the base rose to 200 and the derived list resolved to %s rather than 170 - the rule '.
                'was copied rather than re-run, so the two have stopped agreeing',
                $after['unit_price'],
            );
        }

        /*
         | And a past date derives the PAST base.
         |
         | A credit note in May against a March invoice has to derive
         | March's figure, or the credit does not match the invoice it
         | credits.
         */
        $backInMarch = $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYCHK');

        if ($backInMarch['unit_price'] !== '85.00000000') {
            $violations[] = sprintf(
                'a March document derived %s from a base that only rose in May',
                $backInMarch['unit_price'],
            );
        }

        /*
         | A rule with nothing to apply to is refused, not fallen
         | through.
         |
         | Quietly using the next list down prices the order from a list
         | the agreement does not mention.
         */
        $orphan = PriceList::create([
            'code' => 'CHK-ORPHAN-'.$this->suffix,
            'name_ar' => 'مشتقة بلا أساس',
            'name_en' => 'Derived with no base',
            'currency' => 'SAR',
            'party_id' => 'PARTYNONE',
            'valid_from' => '2026-01-01',
            'priority' => 90,
            'derived_from_id' => (string) PriceList::create([
                'code' => 'CHK-EMPTY-'.$this->suffix,
                'name_ar' => 'فارغة',
                'name_en' => 'Empty',
                'currency' => 'SAR',
                'valid_from' => '2026-01-01',
                'priority' => 0,
                'active' => false,
            ])->getKey(),
            'derivation_kind' => 'discount',
            'derivation_percent' => '10.000000',
        ]);

        $noBase = $this->refusal(fn () => $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYNONE'));

        if ($noBase === null) {
            $violations[] = 'a list derived from one with no price for this item resolved anyway, so '.
                'the order was priced from a list the agreement does not mention';
        }

        /*
         | And a base list that is not there at all.
         |
         | A different branch from the one above: there the base exists
         | and has no price, here the id points at nothing - which is
         | what a deleted list or a wrong currency looks like. The bite
         | sweep found the second branch unexercised, because the first
         | one caught every case the check built.
         */
        PriceList::create([
            'code' => 'CHK-GHOSTBASE-'.$this->suffix,
            'name_ar' => 'مشتقة من قائمة غير موجودة',
            'name_en' => 'Derived from nothing',
            'currency' => 'SAR',
            'party_id' => 'PARTYGHOST',
            'valid_from' => '2026-01-01',
            'priority' => 95,
            'derived_from_id' => str_repeat('9', 26),
            'derivation_kind' => 'discount',
            'derivation_percent' => '10.000000',
        ]);

        $ghost = $this->refusal(fn () => $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYGHOST'));

        if ($ghost === null) {
            $violations[] = 'a list derived from a base that does not exist resolved to a price anyway';
        } elseif (! str_contains($ghost, 'CHK-GHOSTBASE')) {
            /*
             | And it is refused BY NAME, not by crashing.
             |
             | `refusal()` returns something for any throwable, a PHP
             | type error included - so a check that only asked whether
             | something was thrown passed while the guard was removed
             | and the code dereferenced null. The bite sweep showed
             | exactly that. What the guard is for is a sentence naming
             | the list, so asserting the name is asserting the guard.
             */
            $violations[] = 'a missing base list is not refused by name, so the error names a null '.
                'rather than the list an operator has to fix: '.$ghost;
        }

        unset($orphan, $wholesale);

        return $violations;
    }

    /**
     * A tier whose first price starts next month is not today's - PF-254.
     *
     * @return list<string>
     */
    private function aFuturePriceIsNotTodays(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        /*
         | A list with a tier whose ONLY point is next month.
         |
         | It must not be a candidate today: there is no price in it
         | yet. A resolver that took the row anyway would read a null
         | price and either invoice zero or fall into the derivation
         | path of a list that is not derived - which is what the bite
         | sweep showed when the filter was removed.
         */
        $list = PriceList::create([
            'code' => 'CHK-FUTURE-'.$this->suffix,
            'name_ar' => 'قائمة تبدأ لاحقاً',
            'name_en' => 'Starts later',
            'currency' => 'SAR',
            'party_id' => 'PARTYFUTURE',
            'valid_from' => '2026-01-01',
            'priority' => 96,
        ]);

        $line = PriceListLine::create([
            'price_list_id' => (string) $list->getKey(),
            'item_id' => $itemId,
            'min_quantity' => '0',
        ]);

        PricePoint::create([
            'price_list_line_id' => (string) $line->getKey(),
            'unit_price' => '55.00000000',
            'effective_from' => '2026-09-01',
            'reason' => 'the price this tier will open at, from September',
            'changed_by' => str_repeat('0', 26),
        ]);

        /*
         | In March it is not there, so the general list answers.
         */
        $march = $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYFUTURE');

        if ($march['list_code'] === (string) $list->code) {
            $violations[] = sprintf(
                'a tier whose first price starts in September priced a March document at %s - there is '.
                'no price in that list yet',
                $march['unit_price'],
            );
        }

        /*
         | And in September it is.
         */
        $later = $resolver->resolve($itemId, 'SAR', Carbon::parse('2026-09-15'), 'PARTYFUTURE');

        if ($later['unit_price'] !== '55.00000000') {
            $violations[] = sprintf(
                'the September price resolved to %s rather than 55, so a tier that starts later never '.
                'starts at all',
                $later['unit_price'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function theThreeRulesDiffer(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        /*
         | 100 under three rules at 20 per cent.
         |
         | A MARGIN is on the selling price - 100 / 0.8 = 125. A MARKUP
         | is on the cost - 100 x 1.2 = 120. A DISCOUNT is off the base
         | - 100 x 0.8 = 80. Operators use the three words
         | interchangeably, which is exactly why the kind is stored
         | rather than inferred from the number: treating a 20% margin
         | as a 20% markup underprices every line by 5, and a year of
         | that is profit nobody can find.
         */
        foreach ([
            ['margin', '125.00000000'],
            ['markup', '120.00000000'],
            ['discount', '80.00000000'],
        ] as $index => [$kind, $expected]) {
            $party = 'PARTY'.strtoupper($kind);

            PriceList::create([
                'code' => 'CHK-'.strtoupper($kind).'-'.$this->suffix,
                'name_ar' => $kind,
                'name_en' => ucfirst($kind),
                'currency' => 'SAR',
                'party_id' => $party,
                'valid_from' => '2026-01-01',
                'priority' => 60 + $index,
                'derived_from_id' => (string) $this->theThreeRulesBase()->getKey(),
                'derivation_kind' => $kind,
                'derivation_percent' => '20.000000',
            ]);

            $resolved = $resolver->resolve($itemId, 'SAR', $this->march, $party);

            if ($resolved['unit_price'] !== $expected) {
                $violations[] = sprintf(
                    'a %s of 20%% on 100 came out at %s rather than %s - the three are different '.
                    'arithmetic and operators use the words interchangeably',
                    $kind,
                    $resolved['unit_price'],
                    $expected,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function anExceptionBeatsTheRule(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        $list = PriceList::create([
            'code' => 'CHK-EXCEPT-'.$this->suffix,
            'name_ar' => 'مشتقة باستثناء',
            'name_en' => 'Derived with an exception',
            'currency' => 'SAR',
            'party_id' => 'PARTYEXC',
            'valid_from' => '2026-01-01',
            'priority' => 70,
            'derived_from_id' => (string) $this->lists['general']->getKey(),
            'derivation_kind' => 'discount',
            'derivation_percent' => '15.000000',
        ]);

        /*
         | "Retail less fifteen per cent, except this one at 70."
         |
         | A real arrangement, and the exception has to beat the rule or
         | it is not an exception. Forbidding it would send operators to
         | build the exception as a second list with a higher priority -
         | the same thing, spelled so that nobody can see it is one.
         */
        $this->tier((string) $list->getKey(), $itemId, '0', unitPrice: '70.00000000');

        $resolved = $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYEXC');

        if ($resolved['unit_price'] !== '70.00000000') {
            $violations[] = sprintf(
                'a derived list with its own price for this item resolved to %s rather than the 70 on '.
                'its line, so an exception cannot be expressed',
                $resolved['unit_price'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function aLoopingDerivationIsRefused(): array
    {
        $resolver = app(PriceResolver::class);
        $itemId = (string) $this->item?->getKey();
        $violations = [];

        $a = PriceList::create([
            'code' => 'CHK-LOOPA-'.$this->suffix,
            'name_ar' => 'أ',
            'name_en' => 'Loop A',
            'currency' => 'SAR',
            'party_id' => 'PARTYLOOP',
            'valid_from' => '2026-01-01',
            'priority' => 80,
        ]);

        $b = PriceList::create([
            'code' => 'CHK-LOOPB-'.$this->suffix,
            'name_ar' => 'ب',
            'name_en' => 'Loop B',
            'currency' => 'SAR',
            'valid_from' => '2026-01-01',
            'priority' => 0,
            'derived_from_id' => (string) $a->getKey(),
            'derivation_kind' => 'discount',
            'derivation_percent' => '10.000000',
        ]);

        DB::connection('tenant')->table('price_lists')->where('id', (string) $a->getKey())->update([
            'derived_from_id' => (string) $b->getKey(),
            'derivation_kind' => 'discount',
            'derivation_percent' => '10.000000',
        ]);

        $refusal = $this->refusal(fn () => $resolver->resolve($itemId, 'SAR', $this->march, 'PARTYLOOP'));

        if ($refusal === null) {
            $violations[] = 'two lists deriving from each other resolved to a price, which means the '.
                'chain was not followed at all';
        } elseif (! str_contains($refusal, 'has no bottom')) {
            /*
             | And it is refused BY NAME.
             |
             | The column constraint stops a list deriving from itself; a
             | cycle of two is still expressible, and without the guard
             | the symptom is a stack overflow whose message names a
             | stack rather than a price list.
             */
            $violations[] = 'a derivation cycle is not refused by name, so the error names a stack '.
                'rather than a price list: '.$refusal;
        }

        /*
         | And a list cannot derive from itself at all.
         */
        $itself = $this->refusal(fn () => DB::connection('tenant')->table('price_lists')
            ->where('id', (string) $b->getKey())
            ->update(['derived_from_id' => (string) $b->getKey()]));

        if ($itself === null) {
            $violations[] = 'a price list was set to derive from itself';
        }

        return $violations;
    }

    /**
     * A general list and one tier, for a check that needs its own.
     *
     * The checks in this suite share one transaction rather than a
     * savepoint each, so a check that schedules a price change on the
     * shared fixture collides with the next one that does - which it
     * did, on the first run, with two changes on one date. A dedicated
     * list also keeps a new check from changing the answer an earlier
     * one asserts.
     *
     * The priority is given by the caller and has to be distinct, or
     * two lists become identical in specificity, priority and dates -
     * which is the ambiguity `pricesAreUnambiguous` exists to refuse.
     *
     * @return array{0: PriceList, 1: PriceListLine}
     */
    private function ownList(string $key, int $priority, string $price = '100.00000000'): array
    {
        $list = PriceList::create([
            'code' => 'CHK-'.strtoupper($key).'-'.$this->suffix,
            'name_ar' => $key,
            'name_en' => ucfirst($key),
            'currency' => 'SAR',
            'party_id' => 'PARTY'.strtoupper($key),
            'valid_from' => '2026-01-01',
            'priority' => $priority,
        ]);

        $line = $this->tier(
            (string) $list->getKey(),
            (string) $this->item?->getKey(),
            '0',
            unitPrice: $price,
        );

        return [$list, $line];
    }

    /** The base the three rules are measured against, created once. */
    private function theThreeRulesBase(): PriceList
    {
        return $this->threeRulesBase ??= $this->ownList('rulesbase', 107)[0];
    }

    /**
     * A tier and the price it starts at - PF-254.
     *
     * The price is no longer a column on the tier: it is the first point
     * in the tier's history, dated from the list's own `valid_from` so
     * that it is in force for every document the list covers.
     */
    private function tier(
        string $listId,
        string $itemId,
        string $minQuantity,
        ?string $unitPrice = null,
        ?string $discountPercent = null,
        ?string $from = null,
    ): PriceListLine {
        $line = PriceListLine::create([
            'price_list_id' => $listId,
            'item_id' => $itemId,
            'min_quantity' => $minQuantity,
        ]);

        PricePoint::create([
            'price_list_line_id' => (string) $line->getKey(),
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'effective_from' => $from
                ?? (string) PriceList::query()->whereKey($listId)->value('valid_from'),
            'reason' => 'the price this tier opened at, set up by the check fixture',
            'changed_by' => str_repeat('0', 26),
        ]);

        return $line;
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}

/** Thrown to roll back a write the database ACCEPTED. */
final class CatalogWriteAccepted extends \RuntimeException {}
