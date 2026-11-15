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

        PriceListLine::create([
            'price_list_id' => $this->lists['general']->getKey(),
            'item_id' => $itemId,
            'min_quantity' => '10',
            'unit_price' => '85.00000000',
        ]);

        PriceListLine::create([
            'price_list_id' => $this->lists['general']->getKey(),
            'item_id' => $itemId,
            'min_quantity' => '100',
            'unit_price' => '78.00000000',
        ]);

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
        $clash = $this->refusal(fn () => PriceListLine::create([
            'price_list_id' => $second->getKey(),
            'item_id' => (string) $this->item?->getKey(),
            'min_quantity' => '0',
            'unit_price' => '1.00000000',
        ]));

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

        PriceListLine::create([
            'price_list_id' => $rise->getKey(),
            'item_id' => $itemId,
            'min_quantity' => '0',
            'unit_price' => '110.00000000',
        ]);

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

            PriceListLine::create([
                'price_list_id' => $list->getKey(),
                'item_id' => $this->item->getKey(),
                'min_quantity' => '0',
                'unit_price' => $price,
            ]);

            $this->lists[$key] = $list;
        }
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
