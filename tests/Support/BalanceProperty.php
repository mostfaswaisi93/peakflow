<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\Journal;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The balanced-entry invariant, over generated entries - PF-043.
 *
 * ## Why generate instead of writing cases
 *
 * Because the cases somebody writes are the cases somebody thought of, and
 * the double-entry invariant is the one rule in the system where a single
 * escape is unrecoverable: an unbalanced entry that committed is a trial
 * balance that will never tie again, and nothing downstream can tell which
 * entry did it.
 *
 * So the shape is generated: how many lines, which signs, what magnitudes,
 * and - for the unbalanced half - by how much it is off, including by the
 * smallest amount the scale can represent.
 *
 * ## Written by RAW SQL, deliberately
 *
 * Not through the Poster. The Poster refuses an unbalanced set before it
 * writes anything, which is correct and is not what is being tested here.
 * What is being tested is the database: a backfill script, a migration, a
 * psql session and a future bug all write rows the same way, and the trigger
 * is the only thing standing between them and a broken ledger.
 *
 * ## The seed is printed on failure
 *
 * A generated check that cannot be re-run is a bug report nobody can act on.
 * The seed is derived once per run and included in every message, so the
 * exact set of entries can be reproduced.
 *
 * ## `SET CONSTRAINTS ALL IMMEDIATE` is the mechanism
 *
 * The trigger is `DEFERRABLE INITIALLY DEFERRED` and fires at COMMIT, and
 * this whole suite runs inside one transaction that is rolled back. Releasing
 * a savepoint does not fire a deferred constraint - so each entry forces its
 * own check and then goes back to deferred for the next one.
 */
final class BalanceProperty
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** How many entries of each kind. */
    private const CASES = 120;

    private ?Connection $connection = null;

    private string $entityId = '';

    private string $journalId = '';

    private string $periodId = '';

    private string $postingDate = '';

    /** @var list<string> */
    private array $accountIds = [];

    private int $seed = 0;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['the balanced-entry property (no tenant provisioned - skipped)' => []];
        }

        /*
         | One seed per run, printed in every message.
         |
         | `random_int` rather than a fixed constant: a fixed seed checks the
         | same 240 entries forever, which is a regression test wearing a
         | property test's clothes. The printed seed is what makes a failure
         | reproducible.
         */
        $this->seed = random_int(1, PHP_INT_MAX);
        mt_srand($this->seed);

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedLedger();

            return $this->withTheLedgerProof([
                'any set of lines summing to zero commits' => $this->balancedEntriesCommit(),
                'any set not summing to zero is refused' => $this->unbalancedEntriesAreRefused(),
                'the smallest representable imbalance is refused' => $this->theSmallestImbalanceIsRefused(),
                'an entry with one line is refused unless it is zero' => $this->singleLineEntries(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['the balanced-entry property could not be checked' => [
                sprintf('seed %d: %s', $this->seed,
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200)),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function balancedEntriesCommit(): array
    {
        $violations = [];

        for ($case = 0; $case < self::CASES; $case++) {
            $lines = $this->balancedLines();
            $refusal = $this->write($lines);

            if ($refusal !== null) {
                $violations[] = sprintf(
                    'seed %d case %d: a balanced entry of %d lines summing to %s was refused: %s',
                    $this->seed,
                    $case,
                    count($lines),
                    $this->sumOf($lines),
                    $refusal,
                );

                if (count($violations) >= 5) {
                    break;
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function unbalancedEntriesAreRefused(): array
    {
        $violations = [];

        for ($case = 0; $case < self::CASES; $case++) {
            $lines = $this->balancedLines();

            /*
             | The imbalance spans eight orders of magnitude.
             |
             | From one ten-thousandth - the smallest the Amount scale can
             | hold - to millions. A trigger that compared rounded values, or
             | compared through a float, would pass the large cases and fail
             | only on the small ones, which is the failure that reaches
             | production.
             */
            $magnitude = 10 ** mt_rand(0, 8);
            $delta = (string) mt_rand(1, 9999);
            $offBy = BigDecimal::of($delta)->dividedBy(10_000, 4)->multipliedBy($magnitude);

            if (mt_rand(0, 1) === 1) {
                $offBy = $offBy->negated();
            }

            $index = mt_rand(0, count($lines) - 1);
            $lines[$index] = (string) BigDecimal::of($lines[$index])->plus($offBy)->toScale(4);

            if (BigDecimal::of($this->sumOf($lines))->isZero()) {
                continue;
            }

            $refusal = $this->write($lines);

            if ($refusal === null) {
                $violations[] = sprintf(
                    'seed %d case %d: an entry of %d lines summing to %s COMMITTED. A trial balance that '.
                    'has absorbed one of these never ties again, and nothing downstream can say which '.
                    'entry did it',
                    $this->seed,
                    $case,
                    count($lines),
                    $this->sumOf($lines),
                );

                if (count($violations) >= 5) {
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * The one case worth naming: off by 0.0001.
     *
     * Generated above too, but asserted on its own because it is the case a
     * comparison at two decimal places, or through a float, would let
     * through - and because a hundredth of a halala is exactly the kind of
     * difference somebody would argue is not worth refusing.
     *
     * It is. An entry that is out by 0.0001 is out, and the ledger has no way
     * to represent "nearly balanced".
     *
     * @return list<string>
     */
    public function theSmallestImbalanceIsRefused(): array
    {
        $violations = [];

        foreach (['0.0001', '-0.0001'] as $delta) {
            foreach (['1000.0000', '0.0000', '999999999.9999'] as $base) {
                $lines = [$base, (string) BigDecimal::of($base)->negated()->plus($delta)->toScale(4)];

                if ($this->write($lines) === null) {
                    $violations[] = sprintf(
                        'seed %d: lines of %s and %s committed, a sum of %s. There is no such thing as '.
                        'nearly balanced',
                        $this->seed,
                        $lines[0],
                        $lines[1],
                        $this->sumOf($lines),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * A one-line entry.
     *
     * Summing to zero means the line is zero, and a zero line is a row that
     * asserts nothing. Whether it is refused by the balance trigger or by a
     * separate rule does not matter here - what matters is that a single
     * non-zero line never commits, because that is the shape a half-written
     * import produces.
     *
     * @return list<string>
     */
    public function singleLineEntries(): array
    {
        $violations = [];

        if ($this->write(['100.0000']) === null) {
            $violations[] = sprintf(
                'seed %d: a single line of 100.0000 committed as a whole entry. That is the shape an '.
                'import leaves behind when it fails between two lines',
                $this->seed,
            );
        }

        return $violations;
    }

    /**
     * Lines that sum to exactly zero, by construction.
     *
     * The last line is the negation of the others' sum, computed in decimal.
     * Built from integers scaled by ten thousand rather than from floats,
     * because a generator that produced a sum of 0.00000000001 would be
     * testing the trigger against its own rounding error.
     *
     * @return list<string>
     */
    private function balancedLines(): array
    {
        $count = mt_rand(2, 8);
        $units = [];

        for ($line = 0; $line < $count - 1; $line++) {
            // Up to a billion in minor units of a ten-thousandth, which is
            // 100,000 currency units - and occasionally a zero line, because
            // a zero line among non-zero ones is legal and is a case nobody
            // writes by hand.
            $magnitude = 10 ** mt_rand(0, 9);
            $value = mt_rand(0, 9999) * $magnitude;

            $units[] = mt_rand(0, 1) === 1 ? $value : -$value;
        }

        $units[] = -array_sum($units);

        return array_map(
            static fn (int $u): string => (string) BigDecimal::ofUnscaledValue($u, 4)->toScale(4),
            $units,
        );
    }

    /** @param list<string> $amounts */
    private function sumOf(array $amounts): string
    {
        $sum = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $sum = $sum->plus($amount);
        }

        return (string) $sum->toScale(4);
    }

    /**
     * Write an entry with raw SQL and force the deferred check.
     *
     * @param  list<string>  $amounts
     * @return string|null the refusal, or null if it committed
     */
    private function write(array $amounts): ?string
    {
        $savepoint = 'prop_'.bin2hex(random_bytes(4));
        $entryId = (string) Str::ulid();

        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $this->connection?->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $this->journalId,
                'period_id' => $this->periodId,
                'number' => 'PROP-'.Str::ulid(),
                'accounting_date' => $this->postingDate,
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($amounts as $index => $amount) {
                $this->connection?->table('journal_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $this->entityId,
                    'journal_entry_id' => $entryId,
                    'accounting_date' => $this->postingDate,
                    'period_id' => $this->periodId,
                    'account_id' => $this->accountIds[$index % count($this->accountIds)],
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'functional_amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->connection?->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->connection?->statement('SET CONSTRAINTS ALL DEFERRED');
            $this->connection?->statement('RELEASE SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 160);
        }
    }

    private function seedLedger(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'PRP'.random_int(100, 999),
            'legal_name' => 'Balance property',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        $date = Carbon::now()->startOfYear()->addMonths(4)->addDays(6);
        $this->postingDate = $date->toDateString();

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $date);
        app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the balance property');
        $this->periodId = (string) $period->getKey();

        $this->journalId = (string) Journal::query()
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        /*
         | Several accounts, and none of them a control account.
         |
         | A control account refuses a line with no posting rule, which is a
         | different invariant and would make every case here fail for the
         | wrong reason.
         */
        $this->accountIds = Account::query()
            ->where('entity_id', $this->entityId)
            ->where('postable', true)
            ->whereNotIn('code', ['1.1.1.2', '1.1.1.3', '1.1.2.1', '1.1.2.2', '1.1.3', '1.1.4', '1.1.5',
                '2.1.1', '2.1.2', '2.1.3', '3.2', '5.3', '5.4', '5.5', '5.6'])
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if (count($this->accountIds) < 2) {
            throw new \RuntimeException('the chart offers fewer than two plain postable accounts');
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
