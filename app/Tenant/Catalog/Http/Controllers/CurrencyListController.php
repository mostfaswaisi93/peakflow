<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Http\Controllers;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Models\EntityCurrency;
use App\Tenant\Catalog\Models\FxProvider;
use App\Tenant\Catalog\Models\FxRateSet;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The entity's currencies, and the rate on file for each - PF-067, PF-068, PF-069.
 *
 * ## The functional currency is the page's first statement, not a column
 *
 * Every figure the product shows is measured in it, so a reader who takes it
 * for something else reads every report wrong and nothing on the screen
 * contradicts them. A tick in a column is read as a property of one row among
 * several; the sentence a reader needs is that the company keeps its books in
 * this currency, has exactly one, and cannot change it once anything is
 * posted - which under IAS 21 is a re-presentation of the entity's whole
 * history rather than an edit of a field.
 *
 * ## A rate without its date, its provider and its set reconciles to nothing
 *
 * Those three are what make a figure attributable. Asked in March why
 * February's invoices carry a January rate, a page showing only the number
 * cannot answer, and the person asking has no way to tell that it cannot.
 *
 * So every rate row carries all three, plus its age, and a rate older than
 * its set's staleness threshold is marked: a translation done at a month-old
 * rate is wrong in a way no total reveals, because it balances.
 *
 * ## A pair with no rate keeps its row
 *
 * The first instinct is to list the rates that exist, which turns the most
 * important case into an absence. A pair with nothing on file is exactly why
 * a posting in that currency will be refused, and the reader needs to see it
 * before they try - not as a refusal on a document they have already written.
 *
 * ## The rates shown are the posting path's own lookup
 *
 * {@see ExchangeRates::latestOnOrBefore()} is called per pair rather than the
 * table being grouped here, because that method encodes which row wins: the
 * newest `as_of_date` first, provider precedence only as a tie-break, and
 * withdrawn or superseded rows excluded. A page that re-derived "the latest
 * rate" with its own SQL would be a second answer to the question, and the
 * first time the two disagreed the screen would be the convincing one.
 *
 * The cost is one query per enabled foreign currency, and an entity has a
 * handful. The alternative is a screen that is cheap and does not say what a
 * posting would do.
 */
final class CurrencyListController
{
    /**
     * The staleness limit for a rate whose set has no row left on file.
     *
     * A week, chosen so that something is marked rather than nothing: the
     * threshold belongs to the rate set, and a set that has been deleted
     * leaves rates behind it. Treating "no threshold" as "never stale" would
     * make a month-old rate render exactly like this morning's.
     */
    private const STALE_AFTER_DAYS_WITHOUT_A_SET = 7;

    public function __construct(private readonly ExchangeRates $rates) {}

    /**
     * @permission report.view - a rate, its age and its provider are the
     *     audit trail behind every translated figure in the books, which is
     *     reporting information rather than something the navigation offers
     *     everybody. It is the permission `Navigation` hides this item
     *     behind, and a page readable where its own menu entry is hidden is
     *     the arrangement where the menu looks like the control. The route
     *     carries it too; this is the half that still holds when somebody
     *     adds a second route to the group and forgets the middleware.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        Gate::authorize('report.view');

        /*
         | The sets are read before the entity is resolved, so the filter can
         | still be rendered on a request that has no entity.
         |
         | `fx_rate_sets` is tenant-wide on purpose - a monthly average for
         | October is a fact about October, and two entities in the group
         | using different numbers for it is a consolidation nobody can
         | explain.
         */
        $sets = FxRateSet::query()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        // `?set[]=x` arrives as an array, and casting one to a string is a
        // 500 on a URL anybody can type.
        $parameter = $request->query('set');
        $requested = \is_string($parameter) ? trim($parameter) : '';

        $selected = $requested === '' ? null : $sets->firstWhere('code', $requested);

        $entity = $context->entity();

        if ($entity === null) {
            /*
             | No entity means no currencies, and nothing is queried.
             |
             | `EntityCurrency` carries the entity scope, which THROWS rather
             | than returning every company's rows, so a page that read it
             | here would be a 500 on the one request that legitimately has
             | no entity yet. The middleware establishes one; this is the
             | half that holds if it is ever reordered.
             */
            return view('app.masters.currencies', [
                'entity' => null,
                'functional' => null,
                'currencies' => [],
                'sets' => $sets,
                'set' => null,
                'setCode' => '',
                'staleAfterDays' => self::STALE_AFTER_DAYS_WITHOUT_A_SET,
                'unknownSet' => false,
                'rates' => [],
            ]);
        }

        $functional = (string) $entity->functional_currency;

        $currencies = EntityCurrency::query()
            /*
             | The functional currency first, then alphabetically.
             |
             | It is the row every other row is measured against, and sorting
             | it into the middle of the alphabet is how it stops being the
             | first thing read.
             */
            ->orderByDesc('is_functional')
            ->orderBy('currency')
            ->get();

        /*
         | The entity's own default set, taken from its functional currency's
         | row.
         |
         | `default_rate_set` is per currency, and the functional row is the
         | one row every entity has - so it is the entity's answer to "which
         | set do we translate at" and the right thing for the filter to open
         | on. A page that opened on `market` regardless would quietly show a
         | different set from the one the books are translated at.
         */
        $own = $currencies->firstWhere('is_functional', true);
        $ownDefault = $own === null ? '' : (string) $own->default_rate_set;

        $set = $selected
            ?? $sets->firstWhere('code', $ownDefault)
            ?? $sets->firstWhere('is_default', true)
            ?? $sets->first();

        /*
         | The code that is actually looked up, which survives a set whose
         | definition has been deleted.
         |
         | Rates outlive their set's row - they are immutable and the set is
         | an ordinary table - so falling back to the entity's own code, and
         | then to `market`, shows the rates that exist rather than an empty
         | table under a filter with nothing in it.
         */
        $setCode = $set !== null
            ? (string) $set->code
            : ($ownDefault !== '' ? $ownDefault : Rate::SET_MARKET);

        $staleAfterDays = $set !== null
            ? $set->staleness_threshold_days
            : self::STALE_AFTER_DAYS_WITHOUT_A_SET;

        /*
         | The provider names, as one map.
         |
         | A lookup per rate row would be an N+1 that no figure on the page
         | would be wrong about, which is why nobody would ever find it.
         */
        $providerNames = FxProvider::query()->pluck('name', 'code')->all();

        /*
         | One pair per enabled currency that is not the functional one, in
         | the direction a posting asks for.
         |
         | This is the same set of directions the rate fetcher asks the feeds
         | for, and for the same reason: every enabled foreign currency needs
         | a rate TO the functional currency, and nothing else does.
         |
         | A disabled currency is deliberately absent - a document in it is
         | refused before a rate is ever wanted, so a missing-rate warning
         | for it would be a warning about something that cannot happen.
         */
        $rates = $currencies
            ->filter(static fn (EntityCurrency $row): bool => $row->enabled
                && ! $row->is_functional
                /*
                 | And not the functional code itself, whatever the flag
                 | says.
                 |
                 | The flag is mirrored from the entity by a trigger, and a
                 | row written before the mirror existed can carry the
                 | functional code with the flag false - there is a repair
                 | migration for exactly that. Taking the flag's word for it
                 | would put a "no rate on file" warning on the one pair
                 | that must never have a rate, and a false alarm on this
                 | page costs the real ones their meaning.
                 */
                && (string) $row->currency !== $functional)
            ->map(fn (EntityCurrency $row): array => $this->describeRate(
                (string) $row->currency,
                $functional,
                $setCode,
                $staleAfterDays,
                $providerNames,
            ))
            ->values()
            ->all();

        return view('app.masters.currencies', [
            'entity' => $entity,
            'functional' => $functional,
            'currencies' => $currencies
                ->map(fn (EntityCurrency $row): array => $this->describeCurrency($row))
                ->all(),
            'sets' => $sets,
            'set' => $set,
            'setCode' => $setCode,
            'staleAfterDays' => $staleAfterDays,
            'unknownSet' => $requested !== '' && $selected === null,
            'rates' => $rates,
        ]);
    }

    /**
     * One row of `entity_currencies`, as the page needs it.
     *
     * @return array<string, mixed>
     */
    private function describeCurrency(EntityCurrency $row): array
    {
        $code = (string) $row->currency;

        /*
         | A code the registry does not carry is shown as stored, with no
         | exponent assumed for it.
         |
         | Defaulting to two is the failure this whole registry exists
         | against: `JOD 1.234` presented as `1.23` is a thousandfold error
         | that an eye slides straight over. Saying "unknown" is a question
         | somebody answers; a plausible wrong number is not.
         */
        $known = CurrencyRegistry::exists($code);

        return [
            'code' => $code,
            'known' => $known,
            /*
             | Both names on every row, whichever language is being read.
             |
             | The page's job is to let somebody confirm that a code means
             | what they think it means, and the reader who gets that wrong is
             | the one reading the name in the other language. Resolved
             | through ICU rather than stored, which is the registry's own
             | decision - a hand-maintained list beside ICU's would be a
             | worse copy of the same data.
             */
            'name_ar' => $known ? CurrencyRegistry::name($code, 'ar') : null,
            'name_en' => $known ? CurrencyRegistry::name($code, 'en') : null,
            'numeric' => $known ? CurrencyRegistry::numeric($code) : null,
            'exponent' => $known ? CurrencyRegistry::exponent($code) : null,
            /*
             | What this entity SHOWS, which is not always the ISO exponent.
             |
             | A few jurisdictions present a three-decimal currency at two on
             | a customer-facing document while still settling at three, so
             | the override exists and the page states it where it differs.
             | Neither figure is the stored scale: amounts are stored at the
             | amount scale regardless, because storing the display value is
             | how a ledger stops balancing.
             */
            'presentation_decimals' => $known ? $row->presentationDecimals() : null,
            'enabled' => (bool) $row->enabled,
            'is_functional' => (bool) $row->is_functional,
        ];
    }

    /**
     * The rate a posting made today would pick for one pair, or its absence.
     *
     * @param  array<string, mixed>  $providerNames
     * @return array<string, mixed>
     */
    private function describeRate(
        string $from,
        string $to,
        string $setCode,
        int $staleAfterDays,
        array $providerNames,
    ): array {
        $row = $this->rates->latestOnOrBefore($from, $to, now(), $setCode);

        if ($row === null) {
            return [
                'from' => $from,
                'to' => $to,
                'rate' => null,
                'as_of' => null,
                'age_in_days' => null,
                'provider' => null,
                'provider_name' => null,
                'rate_set' => $setCode,
                'is_stale' => false,
            ];
        }

        $asOf = $row->as_of_date;
        $provider = (string) $row->provider;
        $age = $asOf === null ? null : (int) $asOf->diffInDays(now(), false);
        $name = $providerNames[$provider] ?? null;

        return [
            'from' => $from,
            'to' => $to,
            /*
             | The rate as stored, as a string, to all twelve of its decimals.
             |
             | Nothing here trims it or rounds it. A rate read back with fewer
             | decimals than it was stored with is a different rate, and the
             | first thing anybody does with a trimmed one is multiply by it.
             */
            'rate' => (string) $row->rate,
            'as_of' => $asOf?->toDateString(),
            'age_in_days' => $age,
            'provider' => $provider,
            /*
             | The stored code stands on its own when the feed's row is gone.
             |
             | A rate whose provider was later deleted is still the rate that
             | was used, so the code travels with the rate and the name is
             | decoration. Dropping the row instead would make a historical
             | document unexplainable.
             */
            'provider_name' => \is_string($name) && $name !== '' ? $name : null,
            'rate_set' => (string) $row->rate_set,
            'is_stale' => $age !== null && $age > $staleAfterDays,
        ];
    }
}
