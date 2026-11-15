<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Catalog\Exceptions\CurrencyNotEnabled;
use App\Tenant\Catalog\Exceptions\RateNotAvailable;
use App\Tenant\Catalog\Models\FxRate;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the currency and FX layer refuses - PF-067, PF-068.
 *
 * ## The one failure this file exists for
 *
 * A foreign-currency line reaching the journal with no rate and a functional
 * amount equal to its original amount. It balances. Every total agrees. And
 * it asserts that 4,000 EUR is 4,000 SAR.
 *
 * That was measured, not imagined: with the guard dropped, a balanced pair of
 * untranslated EUR lines was accepted and the trial balance then reported the
 * account holding **SAR 4,000** where SAR 16,050 belonged. SAR 12,050 gone,
 * and nothing in the system disagreeing with anything else.
 *
 * The mistake is never single-sided, which is why the check below writes a
 * BALANCED pair. A rule that forgets to translate forgets on every line, so
 * the entry is perfectly balanced and invisible to the balance constraint.
 *
 * ## Why an inverse is checked for as hard as a missing rate
 *
 * `1 / 0.92` is not the EUR -> USD rate; it is a number four ten-thousandths
 * of a cent away from it, in the same direction, every single time. It
 * accumulates into a consolidation difference small enough to dismiss and
 * large enough to notice, which is the worst size a difference can be.
 *
 * ## Why the checks run inside a rolled-back transaction
 *
 * The same reason as everywhere else here - except for one case that cannot:
 * an `fx_rates` row cannot be deleted by any role, so the probe for that has
 * to leave no row behind rather than clean one up. It uses an isolated rate
 * set per run instead.
 */
final class CurrencyEnforcementRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private string $rateSet = '';

    private string $periodId = '';

    private string $postingDate = '';

    private string $journalId = '';

    /** @var array<string, string> account code => id */
    private array $accounts = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['currency controls (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return [
                // FIRST. Every check below would pass with the guard off.
                'the translation guard is enabled' => $this->guardIsEnabled(),
                'a foreign line without a rate is refused, not read as 1' => $this->aMissingRateIsNeverOne(),
                'a functional amount must follow from its own rate' => $this->theArithmeticMustAgree(),
                'direction is stored, never inferred' => $this->directionIsNeverInferred(),
                'a currency the entity has not enabled is refused' => $this->unenabledCurrenciesAreRefused(),
                'the functional currency is always present and enabled' => $this->functionalCurrencyIsMirrored(),
                'a stored rate is superseded, never edited' => $this->ratesAreImmutable(),
                'the lookup is effective-dated and never reaches forward' => $this->lookupIsEffectiveDated(),
            ];
        } catch (Throwable $e) {
            return ['currency controls could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function guardIsEnabled(): array
    {
        $rows = $this->connection->select(<<<'SQL'
            SELECT c.relname AS table_name, t.tgname AS trigger_name, t.tgenabled::text AS enabled
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND NOT t.tgisinternal
              AND t.tgname IN ('journal_lines_translation', 'fx_rates_immutable',
                               'entities_mirror_functional_currency')
        SQL);

        $state = [];

        foreach ($rows as $row) {
            $state[$row->trigger_name] = (string) $row->enabled;
        }

        $violations = [];

        foreach (['journal_lines_translation', 'fx_rates_immutable', 'entities_mirror_functional_currency'] as $trigger) {
            $enabled = $state[$trigger] ?? null;

            if ($enabled === null) {
                $violations[] = sprintf(
                    '%s does not exist, so nothing enforces what it guarded',
                    $trigger,
                );

                continue;
            }

            if ($enabled !== 'O') {
                $violations[] = sprintf(
                    '%s is in state "%s" rather than enabled - and every other check in this file would pass '.
                    'while it is',
                    $trigger,
                    $enabled,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function aMissingRateIsNeverOne(): array
    {
        $violations = [];

        /*
         | A BALANCED pair, both sides in EUR, neither carrying a rate.
         |
         | Balanced deliberately. A single-sided line is caught by the
         | balance constraint, which says nothing about this guard - and the
         | real mistake is never single-sided anyway.
         */
        $refusal = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'EUR', null, '4000.0000'],
            ['4.1', '-4000.0000', 'EUR', null, '-4000.0000'],
        ]);

        if ($refusal === null) {
            $violations[] = 'a balanced pair of EUR lines with no rate was ACCEPTED, so the books now claim '.
                'EUR 4,000 is SAR 4,000 - at 4.0125 that is SAR 12,050 missing, and every total in the '.
                'system agrees with every other one';
        } elseif (! str_contains($refusal, 'A missing rate is not a rate of 1')) {
            $violations[] = 'an untranslated foreign line was refused for the wrong reason: '.$refusal;
        }

        // The mirror image: a rate on a line that needed no translation.
        $withRate = $this->writeLines([
            ['1.1.1.1', '1000.0000', 'SAR', '1.000000000000', '1000.0000'],
            ['4.1', '-1000.0000', 'SAR', '1.000000000000', '-1000.0000'],
        ]);

        if ($withRate === null) {
            $violations[] = 'a functional-currency line carrying a rate of 1 was accepted, so a translated '.
                'line and an untranslated one are now indistinguishable - and "was this converted" is asked '.
                'every time a balance is read in two currencies';
        }

        // And a translated pair must still go through.
        $good = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'EUR', '4.012500000000', '16050.0000'],
            ['4.1', '-4000.0000', 'EUR', '4.012500000000', '-16050.0000'],
        ]);

        if ($good !== null) {
            $violations[] = 'a correctly translated EUR entry was REFUSED, so the guard refuses real '.
                'multi-currency postings too: '.$good;
        }

        return $violations;
    }

    /** @return list<string> */
    public function theArithmeticMustAgree(): array
    {
        $violations = [];

        // 4,000 at 4.0125 is 16,050. Claiming 4,000 is the untranslated
        // figure wearing a rate, which is the harder version of the same bug.
        $mismatch = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'EUR', '4.012500000000', '4000.0000'],
            ['4.1', '-4000.0000', 'EUR', '4.012500000000', '-4000.0000'],
        ]);

        if ($mismatch === null) {
            $violations[] = 'a line whose functional amount does not follow from its own rate was accepted; '.
                'the rate and the figure beside it can now tell different stories, which is exactly what a '.
                'wrong rate looks like afterwards';
        }

        /*
         | One unit at the amount scale must still pass. The tolerance exists
         | to absorb a half-up / half-even disagreement and nothing else, and
         | a guard that refuses legitimate rounding would be turned off.
         */
        $rounding = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'EUR', '4.012500000000', '16050.0001'],
            ['4.1', '-4000.0000', 'EUR', '4.012500000000', '-16050.0001'],
        ]);

        if ($rounding !== null) {
            $violations[] = 'a line one unit out at the amount scale was refused, which is within what '.
                'rounding can produce - a guard this tight gets switched off: '.$rounding;
        }

        $tooFar = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'EUR', '4.012500000000', '16051.0000'],
            ['4.1', '-4000.0000', 'EUR', '4.012500000000', '-16051.0000'],
        ]);

        if ($tooFar === null) {
            $violations[] = 'a line a whole unit out was accepted, so the tolerance is not a rounding '.
                'allowance any more';
        }

        return $violations;
    }

    /** @return list<string> */
    public function directionIsNeverInferred(): array
    {
        $rates = app(ExchangeRates::class);
        $date = Carbon::parse($this->postingDate);
        $violations = [];

        $rates->record('EUR', 'SAR', $date, '4.012500000000', rateSet: $this->rateSet);

        try {
            $rate = $rates->rateFor('SAR', 'EUR', $date, $this->rateSet);

            $violations[] = sprintf(
                'asking for SAR to EUR returned %s when only EUR to SAR is on file, so the lookup is '.
                'inverting a stored rate - 1 divided by a rounded rate drifts one way, permanently, and '.
                'surfaces as a consolidation difference nobody can place',
                $rate->value,
            );
        } catch (RateNotAvailable $e) {
            if (! str_contains($e->getMessage(), 'does not round-trip')) {
                $violations[] = 'the refusal for a missing direction does not explain why the inverse was '.
                    'not used, which is the moment somebody writes 1 / $rate by hand';
            }
        }

        // The value object must refuse it too, not only the lookup.
        try {
            $rates->rateFor('EUR', 'SAR', $date, $this->rateSet)
                ->convert(Money::of('100', 'SAR'), RoundingPolicy::halfUp(Scale::Amount));

            $violations[] = 'a Rate converted an amount in its TO currency, so the direction is advisory';
        } catch (Throwable) {
            // Correct - and it throws before touching the database, so no
            // savepoint is needed here.
        }

        if (method_exists(Rate::class, 'inverse') || method_exists(FxRate::class, 'inverse')) {
            $violations[] = 'something now offers an inverse() method; the absence of one is the design, '.
                'because the only correct way to get the other direction is to fetch it';
        }

        // The database must not hold a from = to row at all: that is either
        // an inverse someone was about to compute or a 1 someone was about
        // to fall back to.
        $sameCurrency = $this->refusal(fn () => $this->connection->table('fx_rates')->insert([
            'id' => (string) Str::ulid(),
            'rate_set' => $this->rateSet,
            'from_currency' => 'EUR',
            'to_currency' => 'EUR',
            'as_of_date' => $this->postingDate,
            'rate' => '1',
            'provider' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($sameCurrency === null) {
            $violations[] = 'a rate from EUR to EUR was stored, which is not a rate';
        }

        return $violations;
    }

    /** @return list<string> */
    public function unenabledCurrenciesAreRefused(): array
    {
        $policy = app(CurrencyPolicy::class);
        $violations = [];

        if ($this->entity === null) {
            return ['the check could not resolve its own entity'];
        }

        try {
            $policy->assertEnabled($this->entity, 'JPY');

            $violations[] = 'a document in JPY was allowed for an entity that does not deal in it';
        } catch (CurrencyNotEnabled) {
            // Correct.
        }

        /*
         | And at the line, which is the layer that matters.
         |
         | An import, a correction script and a console session all reach
         | `journal_lines` directly, and none of them passes through the
         | service above.
         */
        $refusal = $this->writeLines([
            ['1.1.1.1', '4000.0000', 'JPY', '0.040000000000', '160.0000'],
            ['4.1', '-4000.0000', 'JPY', '0.040000000000', '-160.0000'],
        ]);

        if ($refusal === null) {
            $violations[] = 'a journal line in a currency the entity has not enabled was accepted, so the '.
                'enabled list is a dropdown filter rather than a rule';
        } elseif (! str_contains($refusal, 'does not transact in JPY')) {
            $violations[] = 'a line in an unenabled currency was refused for the wrong reason: '.$refusal;
        }

        return $violations;
    }

    /** @return list<string> */
    public function functionalCurrencyIsMirrored(): array
    {
        $violations = [];

        $row = $this->connection->table('entity_currencies')
            ->where('entity_id', $this->entityId)
            ->where('currency', 'SAR')
            ->first();

        if ($row === null) {
            $violations[] = 'a newly created entity has no row for its own functional currency, so it '.
                'cannot post at all - the mirror trigger did not fire';

            return $violations;
        }

        if ($row->is_functional !== true) {
            $violations[] = "the entity's functional currency is not marked functional";
        }

        $functionalCount = $this->connection->table('entity_currencies')
            ->where('entity_id', $this->entityId)
            ->where('is_functional', true)
            ->count();

        if ($functionalCount !== 1) {
            $violations[] = sprintf(
                'the entity has %d functional currencies; IAS 21 gives it exactly one and every translation '.
                'in the system is relative to it',
                $functionalCount,
            );
        }

        $disabled = $this->refusal(fn () => $this->connection->table('entity_currencies')
            ->where('entity_id', $this->entityId)
            ->where('currency', 'SAR')
            ->update(['enabled' => false]));

        if ($disabled === null) {
            $violations[] = "the entity's own functional currency was switched off, which leaves it unable "
                .'to post anything at all';
        }

        return $violations;
    }

    /** @return list<string> */
    public function ratesAreImmutable(): array
    {
        $rates = app(ExchangeRates::class);
        $date = Carbon::parse($this->postingDate);
        $violations = [];

        $stored = $rates->record('USD', 'SAR', $date, '3.750000000000', rateSet: $this->rateSet);

        $edited = $this->refusal(fn () => $this->connection->table('fx_rates')
            ->where('id', $stored->getKey())
            ->update(['rate' => '4.000000000000']));

        if ($edited === null) {
            $violations[] = 'a stored rate was edited in place. Every amount already translated at it keeps '.
                'the old figure and now carries a justification that says something else, which is worse '.
                'than either the old or the new number on its own';
        }

        $replacement = $rates->correct($stored, '3.752000000000', 'the feed sent the prior day');

        if ((string) $replacement->getKey() === (string) $stored->getKey()) {
            $violations[] = 'a correction returned the row it was meant to replace, so nothing was '.
                'corrected - and the original is already withdrawn, which leaves the pair with no rate in '.
                'force and every later conversion refused for a reason a long way from the cause';
        }

        $inForce = $rates->latestOnOrBefore('USD', 'SAR', $date, $this->rateSet);

        if ($inForce === null || (string) $inForce->rate !== '3.752000000000') {
            $violations[] = 'after a correction the rate in force is not the corrected one';
        }

        $old = $this->connection->table('fx_rates')->where('id', $stored->getKey())->first();

        if ($old === null) {
            $violations[] = 'the superseded rate was removed; a document booked at it can no longer be '.
                'explained';
        } elseif ($old->superseded_by_id === null || $old->withdrawn_reason === null) {
            $violations[] = 'a superseded rate does not say what replaced it or why';
        }

        return $violations;
    }

    /** @return list<string> */
    public function lookupIsEffectiveDated(): array
    {
        $rates = app(ExchangeRates::class);
        $friday = Carbon::parse($this->postingDate);
        $violations = [];

        $rates->record('GBP', 'SAR', $friday, '4.800000000000', rateSet: $this->rateSet);

        // Saturday has no rate of its own and a Saturday invoice still has
        // to be translated, so Friday's is used - and carries Friday's date,
        // so the document does not appear to have a rate that never existed.
        $saturday = $friday->copy()->addDay();
        $resolved = $rates->rateFor('GBP', 'SAR', $saturday, $this->rateSet);

        if ($resolved->date->format('Y-m-d') !== $friday->toDateString()) {
            $violations[] = 'the lookup did not fall back to the most recent earlier rate, so a weekend '.
                'document cannot be translated at all';
        }

        // And it must never reach FORWARD. A rate dated after the
        // transaction did not exist when it happened, and booking at it
        // restates the period.
        try {
            $earlier = $friday->copy()->subDays(5);
            $future = $rates->rateFor('GBP', 'SAR', $earlier, $this->rateSet);

            $violations[] = sprintf(
                'a document dated %s was translated at a rate dated %s - a rate that did not exist when the '.
                'transaction happened, which silently restates the period',
                $earlier->toDateString(),
                $future->date->format('Y-m-d'),
            );
        } catch (RateNotAvailable) {
            // Correct.
        }

        $stale = $rates->stalenessInDays($resolved, $friday->copy()->addDays(10));

        if ($stale === null) {
            $violations[] = 'a rate ten days old was not flagged as stale against a three-day threshold, so '.
                'nothing on the document tells a reader the rate was old';
        }

        return $violations;
    }

    /**
     * Write an entry with the given lines, returning the refusal or null.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null, 4: string}>  $lines
     *     [account code, amount, currency, fx_rate, functional_amount]
     */
    private function writeLines(array $lines): ?string
    {
        $entryId = (string) Str::ulid();

        return $this->refusal(function () use ($entryId, $lines): void {
            $this->connection->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $this->journalId,
                'period_id' => $this->periodId,
                'number' => 'FXCHK-'.Str::random(8),
                'accounting_date' => $this->postingDate,
                'entered_at' => now(),
                'status' => 'posted',
                'posted_at' => now(),
                'posting_rule' => 'check.currency',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = [];

            foreach ($lines as [$code, $amount, $currency, $rate, $functional]) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $this->entityId,
                    'journal_entry_id' => $entryId,
                    'accounting_date' => $this->postingDate,
                    'period_id' => $this->periodId,
                    'account_id' => $this->accounts[$code],
                    'amount' => $amount,
                    'currency' => $currency,
                    'fx_rate' => $rate,
                    'functional_amount' => $functional,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $this->connection->table('journal_lines')->insert($rows);

            /*
             | The balance trigger is deferred to COMMIT, and this write sits
             | inside a savepoint that is about to be rolled back - so it
             | would never fire, and an unbalanced entry would look accepted.
             | Forcing the check here exercises the same trigger the same way
             | a commit would.
             */
            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    /**
     * Run a write that is expected to be refused, and return the refusal.
     *
     * ## Every attempt needs its own savepoint
     *
     * A statement PostgreSQL refuses aborts the whole transaction, not just
     * the statement. Without a savepoint the first successful refusal here
     * made every later check report "current transaction is aborted" - eight
     * checks all failing for a reason that had nothing to do with what any
     * of them was looking at.
     *
     * Returns null when the write was ACCEPTED, which is the violation in
     * every caller. The write is rolled back either way, so nothing a check
     * does survives it.
     */
    private function refusal(callable $write): ?string
    {
        try {
            $this->connection->transaction(function () use ($write): void {
                $write();

                throw new AcceptedByTheLedger();
            });
        } catch (AcceptedByTheLedger) {
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
            'code' => 'FXC'.random_int(100, 999),
            'legal_name' => 'Currency check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entity = $entity;
        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        app(CurrencyPolicy::class)->enable($entity, 'EUR');

        /*
         | A rate set of its own.
         |
         | Not for tidiness: `fx_rates` rows cannot be deleted by any role,
         | and this check runs inside a transaction that is rolled back - so
         | the rows do go away. The isolated set is so that a run is not
         | affected by rates a DIFFERENT run or a real user left in `market`,
         | which is what made the first version of this check pass once and
         | then disagree with itself.
         */
        $this->rateSet = 'chk_'.Str::random(8);

        $this->connection->table('fx_rate_sets')->insert([
            'code' => $this->rateSet,
            'name' => 'Enforcement check set',
            'purpose' => 'Isolation for the currency checks.',
            'staleness_threshold_days' => 3,
            'is_default' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $postingDate = Carbon::now()->startOfYear()->addMonths(2)->addDays(9);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $postingDate);

        if ($period === null) {
            throw new \RuntimeException(
                'A newly created entity has no period covering '.$postingDate->toDateString().'.',
            );
        }

        $period->update(['state' => 'open']);

        $this->periodId = (string) $period->getKey();
        $this->postingDate = $postingDate->toDateString();

        $this->journalId = (string) $this->connection->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        foreach (['1.1.1.1', '4.1'] as $code) {
            $this->accounts[$code] = (string) $this->connection->table('accounts')
                ->where('entity_id', $this->entityId)
                ->where('code', $code)
                ->value('id');
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

/**
 * Thrown to roll back an entry the ledger ACCEPTED.
 *
 * A distinct class, not a generic exception: the only way to learn that the
 * ledger accepted something is to reach the end of the write, and the only
 * way to then leave nothing behind is to throw. Catching a RuntimeException
 * there would also catch a real failure and report it as acceptance - which
 * is the direction of error that makes a check pass while the product is
 * broken.
 */
final class AcceptedByTheLedger extends \RuntimeException {}
