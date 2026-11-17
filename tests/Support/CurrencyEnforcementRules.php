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
use App\Tenant\Catalog\Services\RateFetcher;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Services\MonetaryRevaluation;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * ## Every check owns its accounts
 *
 * They all share one transaction, so they share the database. The
 * classification check leaves an account unclassified on purpose; the
 * accumulation check posts and reposts. Pointing both at the receivable made
 * the second report "a rate moving from 4.00 to 4.10 produced no entry at
 * all" - true, and caused entirely by the check before it.
 *
 * The same applies to dates. Rates are idempotent per pair, date, set and
 * provider, so a check that records a rate for a day another check already
 * used records nothing and then measures the other check's number.
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
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private string $rateSet = '';

    private string $periodId = '';

    private string $postingDate = '';

    private string $journalId = '';

    /**
     * The response the probe feed currently gives.
     *
     * Held here and served through ONE registered fake, because
     * `Http::fake()` appends stubs rather than replacing them and the FIRST
     * match wins - so a later "the feed returns 503" stub never fires and
     * the check silently asserts against the earlier response. It cost two
     * checks before it was spotted.
     *
     * @var (callable(): mixed)|null
     */
    private $feedResponse = null;

    private bool $feedRegistered = false;

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

            /*
             | Each check is run in its own try, not the whole set in one.
             |
             | A single try around everything turns one unexpected throw into
             | one opaque failure and reports nothing about the other
             | fourteen checks - which happened while proving these bite: a
             | deliberately broken revaluation raised a constraint violation
             | and the whole suite came back as "currency controls could not
             | be checked", hiding the four checks that had already found the
             | real problem.
             */
            return $this->withTheLedgerProof($this->runEach([
                // FIRST. Every check below would pass with the guard off.
                'the translation guard is enabled' => fn (): array => $this->guardIsEnabled(),
                'a foreign line without a rate is refused, not read as 1' => fn (): array => $this->aMissingRateIsNeverOne(),
                'a functional amount must follow from its own rate' => fn (): array => $this->theArithmeticMustAgree(),
                'direction is stored, never inferred' => fn (): array => $this->directionIsNeverInferred(),
                'a currency the entity has not enabled is refused' => fn (): array => $this->unenabledCurrenciesAreRefused(),
                'the functional currency is always present and enabled' => fn (): array => $this->functionalCurrencyIsMirrored(),
                'a stored rate is superseded, never edited' => fn (): array => $this->ratesAreImmutable(),
                'the lookup is effective-dated and never reaches forward' => fn (): array => $this->lookupIsEffectiveDated(),
                'a rate on a line says WHICH rate, not just what number' => fn (): array => $this->rateProvenanceIsRequired(),
                'a feed outage does not stop the business trading' => fn (): array => $this->anOutageDoesNotStopTrading(),
                'a degraded fetch raises an alert nobody can miss' => fn (): array => $this->degradationIsAlerted(),
                'a feed sending an impossible rate is not partly believed' => fn (): array => $this->impossibleRatesAreRejected(),
                'a finished fetch run cannot be rewritten' => fn (): array => $this->fetchRunsAreFinal(),
                'only monetary items are revalued' => fn (): array => $this->onlyMonetaryItemsAreRevalued(),
                'a revaluation is reversed and recomputed, never accumulated' => fn (): array => $this->revaluationDoesNotAccumulate(),
            ]), $this->entityId);
        } catch (Throwable $e) {
            return ['currency controls could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * Run each check, keeping an unexpected throw to that check.
     *
     * A failure inside one check is reported as that check's violation with
     * the message attached, and the rest still run. The savepoint matters as
     * much as the try: a PostgreSQL error aborts the transaction, so without
     * one every later check would report "current transaction is aborted".
     *
     * @param  array<string, callable(): list<string>>  $checks
     * @return array<string, list<string>>
     */
    private function runEach(array $checks): array
    {
        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $this->connection->statement('SAVEPOINT check_boundary');
                $results[$name] = $check();
                $this->connection->statement('RELEASE SAVEPOINT check_boundary');
            } catch (Throwable $e) {
                $this->connection->statement('ROLLBACK TO SAVEPOINT check_boundary');

                $results[$name] = [sprintf(
                    'the check could not run: %s',
                    Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 200),
                )];
            }
        }

        return $results;
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
     * A line records which rate it used, not merely the number - PF-069.
     *
     * The value alone answers none of the questions actually asked about a
     * posting: was the rate current, was it the market or the statutory one,
     * which feed said so. Nor can it be looked up afterwards - the row may
     * have been superseded, and two feeds can have sent the same figure for
     * different days.
     *
     * The staleness requirement depends on this entirely. "A rate older than
     * the threshold is flagged on every document that uses it" is not
     * answerable from a number.
     *
     * @return list<string>
     */
    public function rateProvenanceIsRequired(): array
    {
        $violations = [];

        $bare = $this->refusal(function (): void {
            $this->connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => (string) Str::ulid(),
                'accounting_date' => $this->postingDate,
                'period_id' => $this->periodId,
                'account_id' => $this->accounts['1.1.1.1'],
                'amount' => '4000.0000',
                'currency' => 'EUR',
                'fx_rate' => '4.012500000000',
                // Deliberately no set, date or provider.
                'functional_amount' => '16050.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if ($bare === null) {
            $violations[] = 'a line was accepted carrying a rate with no date, set or provider. The figure can '.
                'be checked and not placed, and whether it was stale - which every document using it is '.
                'required to show - cannot be worked out at all';
        }

        // A rate dated AFTER the transaction did not exist when it happened.
        $fromTheFuture = $this->writeLinesWith(
            rateAsOf: Carbon::parse($this->postingDate)->addDays(5)->toDateString(),
        );

        if ($fromTheFuture === null) {
            $violations[] = 'a posting was translated at a rate dated after the transaction, which is a rate '.
                'that did not exist when it happened - booking at it silently restates the period';
        }

        // A set nobody can resolve answers none of the questions either.
        $unknownSet = $this->writeLinesWith(rateSet: 'not_a_real_set');

        if ($unknownSet === null) {
            $violations[] = 'a line named a rate set that does not exist, so "was this the market rate or the '.
                'statutory one" has an answer nobody can look up';
        }

        return $violations;
    }

    /**
     * A correctly translated pair, with one piece of provenance altered.
     *
     * Returns the refusal, or null if it was accepted.
     */
    private function writeLinesWith(?string $rateSet = null, ?string $rateAsOf = null): ?string
    {
        $entryId = (string) Str::ulid();

        return $this->refusal(function () use ($entryId, $rateSet, $rateAsOf): void {
            $this->connection->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $this->journalId,
                'period_id' => $this->periodId,
                'number' => 'FXPRV-'.Str::random(8),
                'accounting_date' => $this->postingDate,
                'entered_at' => now(),
                'status' => 'posted',
                'posted_at' => now(),
                'posting_rule' => 'check.currency',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $line = fn (string $code, string $amount, string $functional): array => [
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => $this->postingDate,
                'period_id' => $this->periodId,
                'account_id' => $this->accounts[$code],
                'amount' => $amount,
                'currency' => 'EUR',
                'fx_rate' => '4.012500000000',
                'fx_rate_set' => $rateSet ?? $this->rateSet,
                'fx_rate_as_of' => $rateAsOf ?? $this->postingDate,
                'fx_rate_provider' => 'manual',
                'functional_amount' => $functional,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->connection->table('journal_lines')->insert([
                $line('1.1.1.1', '4000.0000', '16050.0000'),
                $line('4.1', '-4000.0000', '-16050.0000'),
            ]);

            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    /**
     * PF-069: the feeds are down and the business keeps trading.
     *
     * The requirement is unusual and worth restating: an invoice that cannot
     * be issued because a third party's API is down is a WORSE outcome than
     * an invoice issued at yesterday's rate. The second is correctable; the
     * first is a customer not being billed.
     *
     * So the checks here are mostly about what does NOT happen.
     *
     * @return list<string>
     */
    public function anOutageDoesNotStopTrading(): array
    {
        $rates = app(ExchangeRates::class);
        $fetcher = app(RateFetcher::class);
        $violations = [];

        $friday = Carbon::parse($this->postingDate);
        $monday = $friday->copy()->addDays(3);

        // Friday's rate is on file. Monday every feed is down.
        $rates->record('EUR', 'SAR', $friday, '4.012500000000', rateSet: $this->rateSet);
        $this->configureFeed(fn () => Http::response('gateway timeout', 504));

        $result = $fetcher->fetch($monday, $this->rateSet);

        if (! $this->mentions($result['from_fallback'], 'EUR/SAR')) {
            $violations[] = 'with every feed down, EUR/SAR was not reported as falling back to the last known '.
                'rate - so either it was silently refused, which stops the business billing, or it was '.
                'silently invented';
        }

        /*
         | Nothing may be written forward.
         |
         | Copying Friday's rate to Monday would assert that a feed published
         | it on Monday. It did not - and the rate would then look current on
         | every document that used it, which defeats the staleness flag the
         | same requirement asks for.
         */
        $copied = $this->connection->table('fx_rates')
            ->where('rate_set', $this->rateSet)
            ->where('from_currency', 'EUR')
            ->where('to_currency', 'SAR')
            ->where('as_of_date', $monday->toDateString())
            ->exists();

        if ($copied) {
            $violations[] = "Friday's rate was copied forward under Monday's date, which claims a feed "
                .'published it on Monday and makes it look current on every document that uses it';
        }

        // And the conversion must still work, at the real date.
        try {
            $rate = $rates->rateFor('EUR', 'SAR', $monday, $this->rateSet);

            if ($rate->date->format('Y-m-d') !== $friday->toDateString()) {
                $violations[] = 'the rate used on Monday does not carry its real date, so nothing downstream '.
                    'can tell how old it was';
            }
        } catch (Throwable $e) {
            $violations[] = 'with the feeds down, converting EUR to SAR was refused outright - a feed outage '.
                'must not stop an invoice being issued: '.Str::limit($e->getMessage(), 120);
        }

        // A second run must add nothing.
        $before = $this->connection->table('fx_rates')->where('rate_set', $this->rateSet)->count();
        $fetcher->fetch($monday, $this->rateSet);
        $after = $this->connection->table('fx_rates')->where('rate_set', $this->rateSet)->count();

        if ($after !== $before) {
            $violations[] = sprintf(
                're-running the fetch inserted %d more row(s); it has to be idempotent per pair, date, set and '.
                'provider, because a partial failure is the normal way a rate job ends and retrying is the '.
                'normal response',
                $after - $before,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function degradationIsAlerted(): array
    {
        $fetcher = app(RateFetcher::class);
        $violations = [];

        $this->configureFeed(fn () => Http::response('down', 503));

        $result = $fetcher->fetch(Carbon::parse($this->postingDate), $this->rateSet);

        if ($result['outcome'] === 'ok') {
            return ['a fetch in which every feed returned 503 reported itself as ok'];
        }

        $run = $this->connection->table('fx_rate_fetch_runs')->where('id', $result['run_id'])->first();

        if ($run === null) {
            return ['the fetch left no run record, so there is nothing to alert on and nothing to read later'];
        }

        if ($run->alert_raised !== true) {
            $violations[] = 'a degraded run did not raise an alert. Falling back silently is the failure mode: '.
                'a month of invoices at a three-week-old rate produces a difference that is found at the '.
                'close, is material, and every individual invoice looked fine at the time';
        }

        if ($run->provider_attempts === null || ! str_contains((string) $run->provider_attempts, '503')) {
            $violations[] = 'the run does not record what each provider said, so "why is this rate from the '.
                'fallback" cannot be answered without reading application logs';
        }

        /*
         | The audit log as well as the run row.
         |
         | A log line is rotated and is not chained. The audit entry is
         | append-only and sits beside the documents it explains, which is
         | what somebody needs in March when asked why February's invoices
         | carry a January rate.
         */
        $audited = $this->connection->table('audit_log')
            ->where('subject_id', $result['run_id'])
            ->where('action', 'fx.rates.'.$result['outcome'])
            ->exists();

        if (! $audited) {
            $violations[] = 'the degradation reached no audit entry, so the only record of it is a log line '.
                'that will be rotated away';
        }

        return $violations;
    }

    /** @return list<string> */
    public function impossibleRatesAreRejected(): array
    {
        $fetcher = app(RateFetcher::class);
        $date = Carbon::parse($this->postingDate)->addDays(2);

        /*
         | A feed sending a zero.
         |
         | The tempting handling is to skip that pair and keep the rest. It
         | is wrong: a feed that sends a zero has broken in a way that gives
         | no reason to trust the values beside it, and a rate of zero
         | converts every amount to nothing while still balancing.
         */
        $this->configureFeed(fn () => Http::response(['rates' => ['SAR' => '0']], 200));

        $result = $fetcher->fetch($date, $this->rateSet);

        $stored = $this->connection->table('fx_rates')
            ->where('rate_set', $this->rateSet)
            ->where('as_of_date', $date->toDateString())
            ->where('provider', 'probe_feed')
            ->exists();

        $violations = [];

        if ($stored) {
            $violations[] = 'a rate from a response that also contained a zero was stored; the whole response '.
                'has to be treated as unusable, because nothing in it is now trustworthy';
        }

        $reported = collect($result['attempts'])->contains(
            static fn (array $attempt): bool => $attempt['failure'] !== null,
        );

        if (! $reported) {
            $violations[] = 'a feed that sent a rate of zero was not recorded as having failed, so it keeps '.
                'its place in the precedence order and its failure counter stays at nought';
        }

        return $violations;
    }

    /** @return list<string> */
    public function fetchRunsAreFinal(): array
    {
        $fetcher = app(RateFetcher::class);

        $this->configureFeed(fn () => Http::response(['rates' => ['SAR' => '4.0125']], 200));
        $result = $fetcher->fetch(Carbon::parse($this->postingDate), $this->rateSet);

        $rewritten = $this->refusal(fn () => $this->connection->table('fx_rate_fetch_runs')
            ->where('id', $result['run_id'])
            ->update(['outcome' => 'ok', 'notes' => null, 'alert_raised' => false]));

        if ($rewritten === null) {
            return ['a finished fetch run was rewritten. It is the record of what the feeds said at that '.
                'moment, which is the answer to why a document carries the rate it carries - and an answer '.
                'that can be edited afterwards is not one'];
        }

        return [];
    }

    /**
     * Point a single probe feed at a fake HTTP response.
     *
     * The provider row and the configuration are both written here, because
     * the two halves live in different places on purpose - the precedence is
     * an operator decision in the tenant's database, and the URL is a
     * deployment concern in config - and a check that sets only one of them
     * silently exercises nothing.
     */
    private function configureFeed(callable $response): void
    {
        $this->feedResponse = $response;

        if (! $this->feedRegistered) {
            // Registered once. See the note on $feedResponse.
            Http::fake(fn () => ($this->feedResponse)());
            $this->feedRegistered = true;
        }

        config(['fx.providers.probe_feed' => [
            'driver' => 'http',
            'url' => 'https://rates.invalid/{date}?base={base}&symbols={symbols}',
            'rates_path' => 'rates',
            'timeout' => 2,
        ]]);

        /*
         | A precedence far from any real one. `fx_providers.precedence` is
         | unique - two providers at the same rank means an arbitrary winner -
         | so a check cannot reuse a number a seeded provider holds.
         */
        $this->connection->table('fx_providers')->updateOrInsert(
            ['code' => 'probe_feed'],
            [
                'name' => 'Enforcement check feed',
                'precedence' => 9001,
                'active' => true,
                'consecutive_failures' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
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

    /**
     * PF-070: monetary items are restated, non-monetary ones are not.
     *
     * ## This is the whole of IAS 21 in one check
     *
     * Getting the distinction backwards produces no error. It produces a
     * balance sheet that is wrong in a direction nobody notices, because
     * both answers look like numbers.
     *
     * The account the check turns on is deferred revenue. It holds a foreign
     * balance, it is a liability, and it is NOT monetary - it is settled by
     * delivering goods and services rather than by paying money. It is the
     * account most often wrongly revalued, and the resulting entry puts an
     * exchange difference through profit or loss for an obligation that is
     * not denominated in currency at all.
     *
     * @return list<string>
     */
    public function onlyMonetaryItemsAreRevalued(): array
    {
        $revaluation = app(MonetaryRevaluation::class);
        $violations = [];

        // EUR 10,000 receivable and EUR 5,000 deferred revenue, both at 4.00.
        $this->postForeignBalance('1.1.2.1', '10000.0000');
        $this->postForeignBalance('2.1.3', '-5000.0000');

        $exposures = collect($revaluation->exposures($this->entityId, 'SAR', Carbon::parse($this->postingDate)));

        if (! $exposures->contains(static fn (array $row): bool => $row['code'] === '1.1.2.1')) {
            $violations[] = 'a foreign trade receivable was not picked up for revaluation, so a monetary '.
                'balance stays at the rate it was booked at and the balance sheet reports last month\'s value';
        }

        if ($exposures->contains(static fn (array $row): bool => $row['code'] === '2.1.3')) {
            $violations[] = 'deferred revenue was included for revaluation. It is settled by delivering goods '.
                'and services, not by paying money, so IAS 21 does not restate it - revaluing it puts an '.
                'exchange difference through profit or loss for an obligation that is not denominated in '.
                'currency';
        }

        foreach (['1.2.1' => 'property, plant and equipment', '1.1.4' => 'suspense'] as $code => $what) {
            if ($exposures->contains(static fn (array $row): bool => $row['code'] === $code)) {
                $violations[] = sprintf('%s was included for revaluation, and it is not a monetary item', $what);
            }
        }

        /*
         | An unclassified account is REPORTED, not guessed at.
         |
         | A default would be wrong in whichever direction it pointed, and
         | this is the one case where a human knows the answer in seconds.
         */
        /*
         | Accrued payroll, not payments in transit.
         |
         | `1.1.1.3` carries the `payments_in_transit` control role, and
         | PF-179 asserts that account against the released instructions
         | that have not cleared. A fixture posting a balance there is
         | fabricating a control-account position no instruction accounts
         | for - which is precisely the fault that check exists to find,
         | and it duly found this.
         |
         | Petty cash was the first substitute and was wrong too: the
         | revaluation check above uses it as "the cash account" and an
         | extra 2,000 broke its arithmetic. `2.1.9` is monetary,
         | foreign-capable, used by nothing else here, and carries no role
         | for anything to assert against.
         */
        $this->postForeignBalance('2.1.9', '2000.0000');

        $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', '2.1.9')
            ->update(['monetary' => null]);

        $unclassified = $revaluation->unclassified($this->entityId, 'SAR', Carbon::parse($this->postingDate));

        if (! $this->mentions($unclassified, '2.1.9')) {
            $violations[] = 'an account holding a foreign balance with no monetary classification was not '.
                'reported, so it is being silently skipped or silently revalued and nobody is told which';
        }

        $stillIncluded = collect($revaluation->exposures($this->entityId, 'SAR', Carbon::parse($this->postingDate)))
            ->contains(static fn (array $row): bool => $row['code'] === '2.1.9');

        if ($stillIncluded) {
            $violations[] = 'an unclassified account was revalued anyway, which asserts a classification '.
                'nobody made';
        }

        return $violations;
    }

    /**
     * PF-070: run it three times, one adjustment stands.
     *
     * An unrealized difference is an opinion about today's rate. Next period
     * the rate is different and so is the opinion, and adding this period's
     * to last period's gives a figure that is neither.
     *
     * @return list<string>
     */
    public function revaluationDoesNotAccumulate(): array
    {
        $revaluation = app(MonetaryRevaluation::class);
        $rates = app(ExchangeRates::class);
        $violations = [];

        /*
         | A closing date of its own, one day on.
         |
         | An earlier check already recorded a 4.0125 rate for EUR/SAR on
         | the posting date, and the fetch is idempotent per pair, date, set
         | and provider - so recording 4.10 for the same day inserted
         | nothing and this check silently measured somebody else's rate. It
         | reported the adjustments as accumulating when they were not.
         */
        $asAt = Carbon::parse($this->postingDate)->addDay();

        /*
         | Petty cash, not the receivable.
         |
         | Every check in this file shares one transaction, so they share the
         | database too - and the check above posts to the receivable and
         | leaves an account unclassified. The first version of this one
         | reported "a rate moving from 4.00 to 4.10 produced no entry at
         | all", which was true and was caused entirely by the check before
         | it. Each check owns its accounts.
         */
        $this->postForeignBalance('1.1.1.1', '10000.0000');

        $rates->record('EUR', 'SAR', $asAt, '4.100000000000', rateSet: $this->rateSet);
        $closing = $rates->rateFor('EUR', 'SAR', $asAt, $this->rateSet);

        $period = $this->period();

        $first = $revaluation->revalue($this->entity, $period, $asAt, ['EUR/SAR' => $closing], $this->rateSet);

        if ($first['entry'] === null) {
            return ['a rate moving from 4.00 to 4.10 on a EUR 10,000 receivable produced no entry at all'];
        }

        $afterOne = $this->netBalance('1.1.1.1', $asAt);

        if ($afterOne !== '41000.0000') {
            $violations[] = sprintf(
                'after one revaluation the cash account is %s; EUR 10,000 at 4.10 is SAR 41,000',
                $afterOne,
            );
        }

        // Twice more, same rate, same period.
        $second = $revaluation->revalue($this->entity, $period, $asAt, ['EUR/SAR' => $closing], $this->rateSet);
        $revaluation->revalue($this->entity, $period, $asAt, ['EUR/SAR' => $closing], $this->rateSet);

        if ($second['reversal'] === null) {
            $violations[] = 'the second run did not reverse the first, so two adjustments now stand for one '.
                'period and the figure is neither run\'s answer';
        }

        $afterThree = $this->netBalance('1.1.1.1', $asAt);

        if ($afterThree !== '41000.0000') {
            $violations[] = sprintf(
                'after three runs in the same period the balance is %s rather than SAR 41,000 - the '.
                'adjustments are accumulating, which makes the balance a function of how many times somebody '.
                'ran the close',
                $afterThree,
            );
        }

        $current = $this->connection->table('fx_revaluation_runs')
            ->where('entity_id', $this->entityId)
            ->where('is_current', true)
            ->count();

        if ($current !== 1) {
            $violations[] = sprintf('%d revaluation runs are marked current for one period', $current);
        }

        /*
         | The database refuses a supersession with no reversal.
         |
         | Belt and braces: the service does it in the right order, and this
         | is what holds if a later change to the service does not.
         */
        $unreversed = $this->refusal(fn () => $this->connection->table('fx_revaluation_runs')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'period_id' => (string) $period->getKey(),
            'rate_set' => $this->rateSet,
            'revalued_at' => $this->postingDate,
            'journal_entry_id' => null,
            'reversal_entry_id' => null,
            'supersedes_run_id' => $first['run_id'],
            'is_current' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($unreversed === null) {
            $violations[] = 'a run superseded another without reversing its entry, so both adjustments stand';
        }

        return $violations;
    }

    /**
     * Post a foreign-currency balance onto an account, at 4.00.
     *
     * Through the POSTER and a registered rule, not by inserting lines.
     *
     * The first version inserted directly and was refused: trade receivables
     * and deferred revenue are both control accounts, and a control account
     * may only be reached by a posting rule. That guard is right, and getting
     * round it by setting the session variable the poster sets would have
     * made this check pass while proving the ledger accepts something it
     * must not.
     *
     * Posting properly also means the translation invariant runs on these
     * lines, which is what the rest of this file is about.
     */
    private function postForeignBalance(string $code, string $amount): void
    {
        app(Poster::class)->post(
            entity: $this->entity,
            ruleKey: CurrencyCheckBalanceRule::KEY,
            event: [
                'account_id' => $this->accountId($code),
                'contra_id' => $this->accountId('4.1'),
                'amount' => $amount,
                'rate' => $this->bookingRate(),
            ],
            accountingDate: Carbon::parse($this->postingDate),
        );
    }

    /**
     * The rate the probe balances were booked at: 4.00 flat.
     *
     * Constructed rather than fetched, because the rate a posting used is a
     * property of that posting. Looking it up here would make the check
     * depend on the lookup it is not testing.
     */
    private function bookingRate(): Rate
    {
        return new Rate(
            from: 'EUR',
            to: 'SAR',
            value: '4.000000000000',
            date: new \DateTimeImmutable($this->postingDate),
            provider: 'manual',
            rateSet: $this->rateSet,
        );
    }

    private function netBalance(string $code, Carbon $asAt): string
    {
        $row = collect(app(TrialBalance::class)->for($this->entityId, 'SAR', $asAt)['rows'])
            ->firstWhere('code', $code);

        // net_debit, not debit: the gross columns are movements, and after a
        // reversal plus a fresh adjustment the account appears in both.
        return (string) ($row['net_debit'] ?? '0.0000');
    }

    private function accountId(string $code): string
    {
        return $this->accounts[$code] ??= (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', $code)
            ->value('id');
    }

    private function period(): Period
    {
        /** @var Period $period */
        $period = Period::query()->findOrFail($this->periodId);

        return $period;
    }

    /**
     * Write an entry with the given lines, returning the refusal or null.
     *
     * The provenance columns are derived from the rate rather than passed,
     * because `journal_lines_rate_is_complete` demands all four together and
     * a check that had to spell them out every time would get them wrong -
     * which it did: the first version of this file supplied the rate and not
     * its date, and the constraint refused the entries that were supposed to
     * be ACCEPTED. The guard was right and the check was wrong.
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
                    'fx_rate_set' => $rate === null ? null : $this->rateSet,
                    'fx_rate_as_of' => $rate === null ? null : $this->postingDate,
                    'fx_rate_provider' => $rate === null ? null : 'manual',
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

        app(PostingRuleRegistry::class)->register(new CurrencyCheckBalanceRule());

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

        app(PeriodStateMachine::class)->transition(
            $period,
            PeriodState::Open,
            'opened for the enforcement checks',
        );

        $this->periodId = (string) $period->getKey();
        $this->postingDate = $postingDate->toDateString();

        $this->journalId = (string) $this->connection->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        foreach (['1.1.1.1', '1.1.1.3', '4.1', '1.1.2.1', '2.1.3', '1.2.1', '1.1.4', '2.1.9'] as $code) {
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
 * A posting rule for the checks, so control accounts can be reached properly.
 *
 * Trade receivables and deferred revenue are both control accounts, and the
 * ledger refuses a manual posting to either - the invariant that keeps a
 * subledger equal to its control account. A check needs a balance on them, so
 * it posts the way the product does, through a rule.
 *
 * The rate arrives in the event rather than being looked up, because `Ledger`
 * must not know what the catalog is.
 */
final class CurrencyCheckBalanceRule implements PostingRule
{
    public const KEY = 'check.currency.foreign_balance';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(self::KEY, 'رصيد أجنبي للفحص', 'A foreign balance, for the checks', [
            ['Dr/Cr', 'the account under test', 'the foreign amount, translated'],
            ['Dr/Cr', 'revenue', 'the other side, at the same rate'],
        ]);
    }

    /**
     * @param  array{account_id: string, contra_id: string, amount: string, rate: Rate}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $amount = Money::of($event['amount'], 'EUR');

        return [
            PostingLine::translated($event['account_id'], $amount, $event['rate'], $policy),
            PostingLine::translated($event['contra_id'], $amount->negated(), $event['rate'], $policy),
        ];
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
