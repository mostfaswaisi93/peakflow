<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Events\RevaluationRun;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Posting\Rules\ForeignCurrencyRevaluationRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restating monetary items at the closing rate - PF-070, IAS 21.
 *
 * ## Monetary items only, and that is the whole of IAS 21 here
 *
 * Cash, receivables, payables and the like are restated at the closing rate.
 * Property, inventory, prepayments, equity and - the one that catches people
 * - deferred revenue are not. Getting it backwards produces no error, only a
 * balance sheet that is wrong in a direction nobody notices.
 *
 * The classification is a column on the account, because it cannot be
 * derived: property and receivables are both assets.
 *
 * An account holding a foreign balance with NO classification is reported,
 * never assumed. A default would be wrong in whichever direction it pointed,
 * and silently wrong is the failure this whole module is shaped against.
 *
 * ## Reversed and recomputed, never accumulated
 *
 * An unrealized difference is an opinion about today's rate. Next period the
 * rate is different and so is the opinion; adding this period's to last
 * period's gives a figure that is neither.
 *
 * So every run reverses the previous one in full before computing. That is
 * also what makes it re-runnable inside an open period: run it on three
 * successive days and exactly one adjustment stands, the last.
 *
 * ## Why the rates are passed in
 *
 * `Ledger` must not know what the catalog is. Whoever runs the revaluation
 * chooses the rate set and the closing date; this service posts what it is
 * handed and refuses what does not balance. Which is also what lets it be
 * exercised without a rate feed.
 */
final class MonetaryRevaluation
{
    public function __construct(
        private readonly Poster $poster,
        private readonly Reverser $reverser,
    ) {}

    /**
     * What each monetary account holds in each foreign currency.
     *
     * Two figures per row and they answer different questions: `exposure` is
     * how much foreign currency the account holds, and `booked` is what the
     * books currently say that is worth. The difference between `booked` and
     * `exposure` restated at the closing rate is the revaluation.
     *
     * @return list<array{
     *     account_id: string, code: string, name: string,
     *     currency: string, exposure: string, booked: string,
     * }>
     */
    public function exposures(string $entityId, string $functionalCurrency, Carbon $asAt): array
    {
        /*
         | Foreign-currency lines only, and that exclusion matters.
         |
         | A previous revaluation posted a FUNCTIONAL-currency adjustment to
         | this same account. Including it would mix the adjustment into the
         | "what the books say this foreign balance is worth" figure, and the
         | next run would then revalue its own previous output.
         |
         | The reversal at the start of each run cancels those adjustments
         | anyway, so this is the second of two independent reasons the
         | arithmetic cannot accumulate.
         */
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                l.account_id,
                a.code,
                a.name_en AS name,
                l.currency,
                sum(l.amount)            AS exposure,
                sum(l.functional_amount) AS booked
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = cast(? AS char(26))
              AND l.accounting_date <= ?
              AND e.status IN ('posted', 'reversed')
              AND a.monetary IS TRUE
              AND l.currency <> cast(? AS char(3))
            GROUP BY l.account_id, a.code, a.name_en, l.currency
            HAVING sum(l.amount) <> 0
            ORDER BY a.code, l.currency
        SQL, [$entityId, $asAt->toDateString(), $functionalCurrency]);

        return array_map(static fn (object $row): array => [
            'account_id' => (string) $row->account_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'currency' => (string) $row->currency,
            'exposure' => (string) $row->exposure,
            'booked' => (string) $row->booked,
        ], $rows);
    }

    /**
     * Accounts holding a foreign balance that nobody has classified.
     *
     * Reported rather than guessed. An unclassified account with a foreign
     * balance is precisely the case where a default is wrong in whichever
     * direction it points, and where a human knows the answer in seconds.
     *
     * @return list<string>
     */
    public function unclassified(string $entityId, string $functionalCurrency, Carbon $asAt): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT DISTINCT a.code, a.name_en AS name
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = cast(? AS char(26))
              AND l.accounting_date <= ?
              AND e.status IN ('posted', 'reversed')
              AND a.monetary IS NULL
              AND l.currency <> cast(? AS char(3))
            ORDER BY a.code
        SQL, [$entityId, $asAt->toDateString(), $functionalCurrency]);

        return array_map(
            static fn (object $row): string => sprintf('%s %s', $row->code, $row->name),
            $rows,
        );
    }

    /**
     * Revalue, reversing whatever the last run posted.
     *
     * @param  array<string, Rate>  $rates  "FROM/TO" => the closing rate
     * @return array{
     *     run_id: string,
     *     entry: JournalEntry|null,
     *     reversal: JournalEntry|null,
     *     workings: list<array<string, string>>,
     *     missing_rates: list<string>,
     *     unclassified: list<string>,
     * }
     */
    public function revalue(
        Entity $entity,
        Period $period,
        Carbon $asAt,
        array $rates,
        string $rateSet,
    ): array {
        $entityId = (string) $entity->getKey();
        $functional = (string) $entity->functional_currency;
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $entityId,
            $functional,
            $period,
            $asAt,
            $rates,
            $rateSet,
            $policy,
        ): array {
            /*
             | The reversal comes FIRST, inside the same transaction.
             |
             | Computing before reversing would measure the account including
             | the previous adjustment, and posting the result would then
             | stack one opinion on another. In one transaction so that a
             | failure halfway cannot leave a period with its old adjustment
             | reversed and no new one posted - which reads as a sudden
             | exchange gain nobody made.
             */
            $previous = $this->currentRun($entityId, (string) $period->getKey());
            $reversal = null;

            if ($previous !== null && $previous->journal_entry_id !== null) {
                $entry = JournalEntry::query()->findOrFail($previous->journal_entry_id);

                $reversal = $this->reverser->reverse(
                    $entry,
                    $asAt,
                    'superseded by a later revaluation of the same period',
                );
            }

            if ($previous !== null) {
                DB::connection('tenant')->table('fx_revaluation_runs')
                    ->where('id', $previous->id)
                    ->update(['is_current' => false, 'updated_at' => now()]);
            }

            $adjustments = [];
            $workings = [];
            $missing = [];

            foreach ($this->exposures($entityId, $functional, $asAt) as $row) {
                $pair = $row['currency'].'/'.$functional;
                $rate = $rates[$pair] ?? null;

                if ($rate === null) {
                    /*
                     | No closing rate: reported, and the account is left
                     | alone.
                     |
                     | Not skipped quietly and not revalued at the rate it
                     | was booked at - the first hides an unrevalued balance
                     | in a run that reported success, the second asserts
                     | the rate did not move.
                     */
                    $missing[] = sprintf('%s for %s %s', $pair, $row['code'], $row['name']);

                    continue;
                }

                $exposure = Money::of($row['exposure'], $row['currency']);
                $restated = $rate->convert($exposure, $policy);
                $booked = Money::of($row['booked'], $functional);
                $difference = $restated->minus($booked);

                $workings[] = [
                    'account' => $row['code'],
                    'name' => $row['name'],
                    'currency' => $row['currency'],
                    'exposure' => (string) $exposure->amount,
                    'rate' => (string) $rate->value,
                    'rate_date' => $rate->date->format('Y-m-d'),
                    'booked' => (string) $booked->amount,
                    'restated' => (string) $restated->amount,
                    'difference' => (string) $difference->amount,
                ];

                if ($difference->isZero()) {
                    continue;
                }

                /*
                 | Accumulated per ACCOUNT, not per account and currency.
                 |
                 | An account holding both euro and dollars produces two rows
                 | above and must produce one line below: two lines to the
                 | same account in the same entry are legal and are a
                 | reconciliation nobody can follow.
                 */
                $key = $row['account_id'];

                $adjustments[$key] = isset($adjustments[$key])
                    ? Money::of($adjustments[$key], $functional)->plus($difference)->amount
                    : $difference->amount;

                $adjustments[$key] = (string) $adjustments[$key];
            }

            $entry = null;

            $payload = array_map(
                static fn (string $accountId, string $difference): array => [
                    'account_id' => $accountId,
                    'difference' => $difference,
                ],
                array_keys($adjustments),
                array_values($adjustments),
            );

            if ($payload !== []) {
                $entry = $this->poster->post(
                    entity: $entity,
                    ruleKey: ForeignCurrencyRevaluationRule::KEY,
                    event: ['adjustments' => $payload],
                    accountingDate: $asAt,
                    journalCode: 'GEN',
                    sourceType: 'fx_revaluation',
                    narration: sprintf(
                        'Unrealized exchange differences at %s, %s rates (IAS 21)',
                        $asAt->toDateString(),
                        $rateSet,
                    ),
                );
            }

            $runId = (string) Str::ulid();

            DB::connection('tenant')->table('fx_revaluation_runs')->insert([
                'id' => $runId,
                'entity_id' => $entityId,
                'period_id' => (string) $period->getKey(),
                'rate_set' => $rateSet,
                'revalued_at' => $asAt->toDateString(),
                'journal_entry_id' => $entry?->getKey(),
                'reversal_entry_id' => $reversal?->getKey(),
                'supersedes_run_id' => $previous?->id,
                'is_current' => true,
                'accounts_examined' => count($workings),
                'accounts_restated' => count($payload),
                'workings' => json_encode($workings, JSON_THROW_ON_ERROR),
                'unclassified_accounts' => $this->unclassified($entityId, $functional, $asAt) === []
                    ? null
                    : implode('; ', $this->unclassified($entityId, $functional, $asAt)),
                'run_by_user_id' => auth()->guard('web')->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            event(new RevaluationRun(
                entityId: $entityId,
                periodId: (string) $period->getKey(),
                runId: $runId,
                rateSet: $rateSet,
                revaluedAt: $asAt->toDateString(),
                entryId: $entry === null ? null : (string) $entry->getKey(),
                accountsRestated: count($payload),
                missingRates: $missing,
                unclassified: $this->unclassified($entityId, $functional, $asAt),
            ));

            return [
                'run_id' => $runId,
                'entry' => $entry,
                'reversal' => $reversal,
                'workings' => $workings,
                'missing_rates' => $missing,
                'unclassified' => $this->unclassified($entityId, $functional, $asAt),
            ];
        });
    }

    /** The run currently standing for a period, if any. */
    private function currentRun(string $entityId, string $periodId): ?object
    {
        return DB::connection('tenant')->table('fx_revaluation_runs')
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->where('is_current', true)
            ->first();
    }

    /** Which currency pairs a revaluation would need. */
    public function pairsNeeded(string $entityId, string $functionalCurrency, Carbon $asAt): array
    {
        $pairs = [];

        foreach ($this->exposures($entityId, $functionalCurrency, $asAt) as $row) {
            $pairs[$row['currency'].'/'.$functionalCurrency] = [
                'from' => $row['currency'],
                'to' => $functionalCurrency,
            ];
        }

        return array_values($pairs);
    }
}
