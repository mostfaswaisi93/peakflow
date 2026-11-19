<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Services\AccessReview;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The periodic access review - PF-238.
 *
 * ## Why it exits zero even when it finds things
 *
 * Unlike every verify command in this product. Those assert an invariant: a
 * broken hash chain is wrong and the exit code is how a scheduler learns it.
 * This asserts nothing. Somebody holding a role they no longer need is not a
 * fault in the system, it is a fact about an organisation, and the answer is
 * a person deciding - not a page at 03:00.
 *
 * The one exception is the row that cannot be right: a money-moving
 * permission held by somebody with no second factor. `RoleGrantService`
 * refuses to create that, so finding one means it arrived another way - an
 * import, a direct write, or a grant made before the rule existed. That is a
 * control that is not holding, and it exits non-zero.
 *
 * ## Printed per row, not summarised
 *
 * A review that says "41 users, 7 dormant" is a review nobody can act on.
 * Every line names somebody, because the output IS the worksheet.
 */
final class AccessReviewCommand extends Command
{
    protected $signature = 'access:review
        {tenant? : A tenant slug or id}
        {--all : Every provisioned tenant}
        {--dormant-days=90 : Treat an account or token unused for this long as dormant}
        {--grants : Print the full grant list as well as what needs attention}';

    protected $description = 'Who holds what, who has a second factor, and what has gone unused';

    public function handle(TenantConnectionResolver $resolver): int
    {
        /*
         | `--all`, so the review can be PERIODIC - PF-238.
         |
         | The criterion asks for a periodic access review, and a command
         | that takes a required tenant cannot be scheduled fleet-wide: it
         | would need one schedule entry per tenant, written by hand, and a
         | tenant provisioned afterwards would be reviewed by nothing. The
         | cadence check in `ObservabilityRules` is what said so - this
         | command and `compliance:report` were both in no schedule and
         | giving no reason the moment they were written.
         */
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        $worst = self::SUCCESS;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            if ($this->reviewOne($tenant) !== self::SUCCESS) {
                $worst = self::FAILURE;
            }
        }

        return $worst;
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ((bool) $this->option('all')) {
            return Tenant::query()
                ->with('connection')
                ->whereHas('connection')
                ->whereNotNull('provisioned_at')
                ->orderBy('slug')
                ->get()
                ->all();
        }

        $named = (string) ($this->argument('tenant') ?? '');

        if ($named === '') {
            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(fn ($q) => $q->where('slug', $named)->orWhere('id', $named))
            ->first();

        return $tenant === null ? [] : [$tenant];
    }

    private function reviewOne(Tenant $tenant): int
    {

        $review = app(AccessReview::class);
        $grants = $review->grants();

        $attention = array_values(array_filter($grants, static fn (array $r): bool => $r['needs_attention'] === true));

        $this->components->info(sprintf('Access review for %s', (string) $tenant->slug));

        if ($attention !== []) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d grant(s) carry a money-moving permission with no confirmed second factor.',
                \count($attention),
            ));

            foreach ($attention as $row) {
                $this->line(sprintf(
                    '  <fg=red>!</> %s (%s) in %s — %s — holds %s',
                    (string) $row['user'],
                    (string) $row['email'],
                    (string) $row['entity'],
                    implode(', ', $row['roles']),
                    implode(', ', \array_slice($row['money_moving'], 0, 3)),
                ));
            }
        }

        if ((bool) $this->option('grants')) {
            $this->newLine();
            $this->components->info(sprintf('%d grant(s)', \count($grants)));

            foreach ($grants as $row) {
                $this->line(sprintf(
                    '  %s %s in %s — %s%s',
                    $row['active'] ? ' ' : '<fg=yellow>inactive</>',
                    (string) $row['user'],
                    (string) $row['entity'],
                    implode(', ', $row['roles']),
                    $row['money_moving'] === []
                        ? ''
                        : sprintf(' — money-moving: %d, factor: %s', \count($row['money_moving']), $row['has_factor'] ? 'yes' : 'NO'),
                ));
            }
        }

        $days = max(1, (int) $this->option('dormant-days'));
        $dormant = $review->dormant(Carbon::now()->subDays($days));

        $this->newLine();
        $this->components->info(sprintf('Unused for %d day(s) or more', $days));

        foreach ($dormant['users'] as $row) {
            $this->line(sprintf(
                '  <fg=yellow>account</> %s (%s) — last seen %s%s',
                (string) $row['user'],
                (string) $row['email'],
                $row['last_seen'] ?? 'never',
                $row['money_moving'] > 0 ? sprintf(' — still holds %d money-moving permission(s)', $row['money_moving']) : '',
            ));
        }

        foreach ($dormant['tokens'] as $row) {
            $this->line(sprintf(
                '  <fg=yellow>token</>   %s — last used %s, created %s',
                (string) $row['name'],
                $row['last_used'] ?? 'never',
                $row['created'] ?? 'unknown',
            ));
        }

        if ($dormant['users'] === [] && $dormant['tokens'] === []) {
            $this->line('  Nothing. Every active account and live token has been used inside the window.');
        }

        return $attention === [] ? self::SUCCESS : self::FAILURE;
    }
}
