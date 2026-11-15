<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Exceptions\RateNotAvailable;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\MonetaryRevaluation;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `fx:revalue` - restating monetary items at the closing rate - PF-070.
 *
 * ## Why the orchestration is here and not in a service
 *
 * This reads rates from `Catalog` and posts through `Ledger`, and no module
 * may depend on both: `Ledger` depends on nothing but `Tenancy`, which is
 * what keeps posting rules changeable without touching anything else, and
 * `Catalog` must not post.
 *
 * A command is outside the module graph - it is the composition root - so
 * joining the two here is the one place it is allowed. The arithmetic lives
 * in `Ledger\Services\MonetaryRevaluation` and the rate choice in
 * `Catalog\Services\ExchangeRates`; this picks the rate set and the date and
 * hands one to the other.
 *
 * ## `--dry-run` is the normal way to use it
 *
 * A revaluation is a posting into a period somebody is about to close, and
 * the figure it produces is one of the things that gets questioned. Showing
 * the workings - exposure, rate, booked value, restated value, difference -
 * before posting anything is what makes it reviewable rather than something
 * that appeared in the general ledger overnight.
 */
final class FxRevalue extends Command
{
    protected $signature = 'fx:revalue
        {tenant? : A tenant slug or id}
        {--all : Every servable tenant}
        {--entity= : One entity code, rather than all of them}
        {--date= : The closing date (default today)}
        {--set=market : The rate set to close at}
        {--dry-run : Show the workings and post nothing}';

    protected $description = 'Restate monetary items at the closing rate, reversing the previous run (IAS 21)';

    public function handle(
        TenantConnectionResolver $resolver,
        MonetaryRevaluation $revaluation,
        ExchangeRates $rates,
        FiscalCalendarGenerator $calendar,
    ): int {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $asAt = $this->option('date') === null
            ? Carbon::today()
            : Carbon::parse((string) $this->option('date'))->startOfDay();

        $set = (string) ($this->option('set') ?: Rate::SET_MARKET);
        $dry = (bool) $this->option('dry-run');
        $problems = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            $entities = Entity::query()
                ->when(
                    $this->option('entity') !== null,
                    fn ($query) => $query->where('code', $this->option('entity')),
                )
                ->where('active', true)
                ->orderBy('code')
                ->get();

            foreach ($entities as $entity) {
                app(EntityContext::class)->use($entity);

                $problems += $this->revalueEntity(
                    $tenant,
                    $entity,
                    $asAt,
                    $set,
                    $dry,
                    $revaluation,
                    $rates,
                    $calendar,
                );
            }

            app(EntityContext::class)->forget();
        }

        $this->newLine();

        if ($problems > 0) {
            $this->components->error(sprintf(
                '%d entity/entities could not be revalued completely. Nothing was guessed: an account with no '.
                'closing rate, or no classification, was left alone and named above.',
                $problems,
            ));

            return self::FAILURE;
        }

        $this->components->info($dry ? 'Dry run complete; nothing was posted.' : 'Revaluation posted.');

        return self::SUCCESS;
    }

    private function revalueEntity(
        Tenant $tenant,
        Entity $entity,
        Carbon $asAt,
        string $set,
        bool $dry,
        MonetaryRevaluation $revaluation,
        ExchangeRates $rates,
        FiscalCalendarGenerator $calendar,
    ): int {
        $label = sprintf('%s · %s', $tenant->slug, $entity->code);
        $entityId = (string) $entity->getKey();
        $functional = (string) $entity->functional_currency;

        $pairs = $revaluation->pairsNeeded($entityId, $functional, $asAt);

        if ($pairs === []) {
            $this->components->twoColumnDetail($label, '<fg=gray>no foreign monetary balances</>');

            return 0;
        }

        /*
         | The rates are fetched BEFORE anything is posted, and a missing one
         | is carried through rather than thrown.
         |
         | Throwing here would abandon the whole entity over one currency. A
         | tenant holding euro and yen, with a yen rate missing, should still
         | have its euro balances restated - and be told, by name, which one
         | was left alone.
         */
        $closing = [];
        $missing = [];

        foreach ($pairs as $pair) {
            try {
                $rate = $rates->rateFor($pair['from'], $pair['to'], $asAt, $set);
                $closing[$pair['from'].'/'.$pair['to']] = $rate;

                $stale = $rates->stalenessInDays($rate, $asAt);

                if ($stale !== null) {
                    $this->line(sprintf(
                        '  <fg=yellow>stale rate</>   %s/%s is %d days old (%s)',
                        $pair['from'],
                        $pair['to'],
                        $stale,
                        $rate->date->format('Y-m-d'),
                    ));
                }
            } catch (RateNotAvailable $e) {
                $missing[] = $pair['from'].'/'.$pair['to'];
                $this->line(sprintf('  <fg=red>no rate</>      %s', $e->getMessage()));
            }
        }

        $unclassified = $revaluation->unclassified($entityId, $functional, $asAt);

        foreach ($unclassified as $account) {
            $this->line(sprintf(
                '  <fg=red>unclassified</> %s holds a foreign balance and is neither monetary nor '.
                'non-monetary. It was left alone: a default would be wrong in whichever direction it pointed.',
                $account,
            ));
        }

        if ($dry) {
            $this->showWorkings($label, $revaluation, $entityId, $functional, $asAt, $closing);

            return $missing === [] && $unclassified === [] ? 0 : 1;
        }

        $period = $calendar->periodFor($entityId, $asAt);

        if ($period === null) {
            $this->components->twoColumnDetail($label, '<fg=red>no period covers '.$asAt->toDateString().'</>');

            return 1;
        }

        if (! $period->acceptsPostings()) {
            $this->components->twoColumnDetail(
                $label,
                sprintf('<fg=red>%s is %s</>', $period->label, $period->state->value),
            );

            return 1;
        }

        $result = $revaluation->revalue($entity, $period, $asAt, $closing, $set);

        $this->components->twoColumnDetail($label, sprintf(
            '<fg=green>%d restated</>%s',
            count($result['workings']),
            $result['reversal'] === null ? '' : ' (previous run reversed)',
        ));

        foreach ($result['workings'] as $working) {
            if ($working['difference'] === '0.0000') {
                continue;
            }

            $this->line(sprintf(
                '    %-10s %s %18s at %s = %18s, booked %18s, difference %s',
                $working['account'],
                $working['currency'],
                $working['exposure'],
                $working['rate'],
                $working['restated'],
                $working['booked'],
                $working['difference'],
            ));
        }

        return $missing === [] && $unclassified === [] ? 0 : 1;
    }

    /** @param array<string, Rate> $closing */
    private function showWorkings(
        string $label,
        MonetaryRevaluation $revaluation,
        string $entityId,
        string $functional,
        Carbon $asAt,
        array $closing,
    ): void {
        $rows = [];

        foreach ($revaluation->exposures($entityId, $functional, $asAt) as $exposure) {
            $rate = $closing[$exposure['currency'].'/'.$functional] ?? null;

            $rows[] = [
                $exposure['code'],
                $exposure['currency'],
                $exposure['exposure'],
                $rate === null ? '-' : (string) $rate->value,
                $exposure['booked'],
                $rate === null ? '-' : (string) $rate->convert(
                    \App\Support\Money\Money::of($exposure['exposure'], $exposure['currency']),
                    \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount),
                )->amount,
            ];
        }

        $this->newLine();
        $this->line(sprintf('  <options=bold>%s</> — dry run', $label));
        $this->table(['Account', 'Ccy', 'Exposure', 'Closing rate', 'Booked', 'Restated'], $rows);
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $given = $this->argument('tenant');

        if ($given === null) {
            $this->components->error('Name a tenant, or pass --all.');

            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->where('slug', $given)
            ->orWhere('id', $given)
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
