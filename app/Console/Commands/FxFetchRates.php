<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Services\RateFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `fx:fetch-rates` - PF-069.
 *
 * ## It exits non-zero on a degraded run, and that is not a block
 *
 * The exit code is an alert on the JOB. Nothing it does prevents an invoice
 * being issued: a tenant whose feeds are all down keeps trading on the last
 * known rate, and every document that uses one is flagged.
 *
 * The distinction matters because the obvious reading of "a provider failure
 * raises an alert" is to make the failure fatal, and a fatal rate fetch means
 * a business cannot bill its customers because a third party's API is down.
 * That trade is never worth making - an invoice at yesterday's rate is
 * correctable, and a customer not billed is not.
 *
 * So: loud, scheduled, non-zero, and with no authority over trading.
 *
 * ## Why it is per tenant rather than global
 *
 * Which pairs a tenant needs comes from its own entities' enabled
 * currencies, and the rates land in its own database. A global run would
 * have to resolve a connection per tenant anyway, and doing it one tenant at
 * a time means one tenant's feed outage does not abandon the rest.
 */
final class FxFetchRates extends Command
{
    protected $signature = 'fx:fetch-rates
        {tenant? : A tenant slug or id}
        {--all : Every servable tenant}
        {--date= : The date the rates APPLY to (default today), not the date they are fetched}
        {--set=market : The rate set to fetch}';

    protected $description = 'Fetch exchange rates, falling back to the last known rate and raising an alert';

    public function handle(TenantConnectionResolver $resolver, RateFetcher $fetcher): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        /*
         | The date rates APPLY to, which is not the date they are fetched.
         |
         | Those differ whenever a job is late or re-run for a missed day,
         | and booking Monday's invoice at Tuesday's rate because Tuesday is
         | when the job ran is a restatement nobody asked for.
         */
        $asOf = $this->option('date') === null
            ? Carbon::today()
            : Carbon::parse((string) $this->option('date'))->startOfDay();

        $set = (string) ($this->option('set') ?: Rate::SET_MARKET);
        $degraded = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            $providers = $fetcher->providers($set);

            if ($providers === []) {
                /*
                 | Said plainly rather than reported as success.
                 |
                 | A run that fetched nothing because nothing is configured
                 | looks identical in a log to a run that fetched nothing
                 | because it had nothing to fetch, and the first one needs
                 | somebody to act.
                 */
                $this->components->warn(sprintf(
                    '%s: no rate provider is both active in fx_providers and configured in config/fx.php, '.
                    'so no rate can be fetched for the %s set. Rates can still be entered by hand.',
                    $tenant->slug,
                    $set,
                ));

                $skipped++;

                continue;
            }

            $result = $fetcher->fetch($asOf, $set);

            $this->report($tenant, $result);

            if ($result['outcome'] !== 'ok') {
                $degraded++;
            }
        }

        if ($degraded > 0) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d tenant(s) could not fetch every rate. Trading continues on the last known rates, which is '.
                'deliberate - this exit code is an alert on the job, not a block on issuing documents.',
                $degraded,
            ));

            return self::FAILURE;
        }

        $this->newLine();

        /*
         | "Every rate fetched" after a screen of warnings is how a reader
         | learns to skip the output. If nothing could be fetched because
         | nothing is configured, say that instead.
         */
        $skipped === count($tenants)
            ? $this->components->warn(sprintf(
                'Nothing was fetched: no tenant has a provider that is both active and configured.',
            ))
            : $this->components->info(sprintf(
                'Every rate fetched%s.',
                $skipped > 0 ? sprintf(' (%d tenant(s) had no configured provider)', $skipped) : '',
            ));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function report(Tenant $tenant, array $result): void
    {
        /** @var list<string> $fallback */
        $fallback = $result['from_fallback'];
        /** @var list<string> $unresolved */
        $unresolved = $result['unresolved'];
        /** @var list<array{provider: string, fetched: int, failure: string|null}> $attempts */
        $attempts = $result['attempts'];

        $label = sprintf('%s · %d of %d', $tenant->slug, $result['inserted'], $result['requested']);

        if ($result['outcome'] === 'ok') {
            $this->components->twoColumnDetail($label, '<fg=green>fetched</>');

            return;
        }

        $this->newLine();
        $this->components->warn(sprintf('%s: %s', $tenant->slug, $result['outcome']));

        foreach ($attempts as $attempt) {
            if ($attempt['failure'] === null) {
                $this->line(sprintf('  <fg=green>%-12s</> %d rate(s)', $attempt['provider'], $attempt['fetched']));

                continue;
            }

            $this->line(sprintf('  <fg=red>%-12s</> %s', $attempt['provider'], $attempt['failure']));
        }

        foreach ($fallback as $pair) {
            $this->line(sprintf('  <fg=yellow>fallback</>     %s', $pair));
        }

        foreach ($unresolved as $pair) {
            $this->line(sprintf('  <fg=red>no rate</>      %s', $pair));
        }
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
