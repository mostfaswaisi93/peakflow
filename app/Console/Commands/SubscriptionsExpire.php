<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Enums\SubscriptionStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves lapsed subscriptions to their next state - PF-022.
 *
 * ## Idempotent, and that is a requirement rather than a nicety
 *
 * This runs on a schedule. A scheduler that fires twice, a retry after a
 * timeout, or an operator running it by hand during an incident must all
 * produce the same result as running it once. So every transition is
 * expressed as "rows currently in state X whose date has passed", and a row
 * already moved matches nothing on the second pass.
 *
 * The activity log is written per TRANSITION, not per row examined. A log that
 * records "considered 4,000 subscriptions, changed none" four times a day
 * buries the one line that matters, and the first thing anyone does with a log
 * like that is stop reading it.
 *
 * ## Valid THROUGH the end date
 *
 * A subscription ending on the 31st is usable all day on the 31st. The
 * comparison is therefore strictly less-than against today, and the boundary
 * has its own check, because off-by-one here means either cutting a paying
 * customer off a day early or giving a lapsed one a free day - and the first
 * generates a support ticket that is entirely deserved.
 */
final class SubscriptionsExpire extends Command
{
    protected $signature = 'subscriptions:expire
        {--pretend : Report what would change, change nothing}';

    protected $description = 'Expire lapsed subscriptions and end finished trials';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $today = now()->startOfDay();
        $transitions = 0;

        /*
         | Two transitions, deliberately separate.
         |
         | A finished trial becomes PAST DUE rather than expired: the customer
         | has not paid yet, which is not the same as having stopped paying,
         | and the two want different emails and different grace handling.
         */
        $transitions += $this->move(
            'a trial that has finished',
            Subscription::query()
                ->where('status', SubscriptionStatus::Trial->value)
                ->whereNotNull('trial_ends_on')
                ->whereDate('trial_ends_on', '<', $today),
            SubscriptionStatus::PastDue,
            $pretend,
        );

        $transitions += $this->move(
            'a subscription past its end date',
            Subscription::query()
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PastDue->value,
                    SubscriptionStatus::Trial->value,
                ])
                ->whereNotNull('ends_on')
                // Valid THROUGH ends_on, so the row only lapses once ends_on
                // is strictly in the past.
                ->whereDate('ends_on', '<', $today),
            SubscriptionStatus::Expired,
            $pretend,
        );

        if ($transitions === 0) {
            $this->components->info('Nothing to change.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%s %d subscription(s).',
            $pretend ? 'Would change' : 'Changed',
            $transitions,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Subscription>  $query
     */
    private function move(string $because, $query, SubscriptionStatus $to, bool $pretend): int
    {
        $moved = 0;

        /*
         | `lazy()` and not `cursor()`.
         |
         | `cursor()` silently ignores `with()` - it streams a single
         | unbuffered result set and never gets the chance to run the second
         | query an eager load needs. The relation then loads per row, which
         | across a few thousand subscriptions is a few thousand queries
         | inside a scheduled job, and nothing says so.
         |
         | `lazy()` chunks, so the eager load actually happens. Strict mode
         | caught this in development; in production it would have been a slow
         | job nobody could explain.
         */
        foreach ($query->with('tenant')->lazy() as $subscription) {
            $from = $subscription->status;

            if ($from === $to) {
                continue;
            }

            $this->line(sprintf(
                '  %-28s %s -> %s  (%s)',
                (string) $subscription->tenant?->slug,
                $from->value,
                $to->value,
                $because,
            ));

            $moved++;

            if ($pretend) {
                continue;
            }

            DB::connection('platform')->transaction(function () use ($subscription, $from, $to, $because): void {
                $subscription->forceFill([
                    'status' => $to,
                    'suspended_at' => $to === SubscriptionStatus::Suspended ? now() : $subscription->suspended_at,
                ])->save();

                // One row per transition. The reason travels with it, because
                // "why did this customer get cut off" is asked months later by
                // someone who was not here.
                ActivityLog::create([
                    'tenant_id' => $subscription->tenant_id,
                    'category' => 'subscription',
                    'action' => 'subscription.'.$to->value,
                    'context' => [
                        'subscription_id' => (string) $subscription->getKey(),
                        'from' => $from->value,
                        'to' => $to->value,
                        'because' => $because,
                        'ends_on' => $subscription->ends_on?->toDateString(),
                        'trial_ends_on' => $subscription->trial_ends_on?->toDateString(),
                    ],
                ]);
            });
        }

        return $moved;
    }
}
