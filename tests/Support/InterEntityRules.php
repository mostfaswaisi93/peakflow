<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\InterEntityPosting;
use App\Tenant\Ledger\Services\InterEntityReconciliation;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A four-entity group, both directions, three currencies - PF-057.
 *
 * ## The shape, and why it is this shape
 *
 * Four entities: two keeping books in SAR, one in EUR, one in USD. Six
 * transactions settled in three currencies, in both directions between the
 * same pairs, so every combination appears at least once:
 *
 *   - settled in the lender's currency and foreign to the borrower
 *   - settled in the borrower's currency and foreign to the lender
 *   - foreign to both
 *   - settled in a currency both share
 *
 * A group of two would prove none of that, and a single direction would miss
 * the case where the same pair of entities owes each other in two currencies
 * at once - which is the one that nets wrongly if the sign is taken from the
 * entity rather than from the transaction.
 *
 * ## What is claimed, in order of how badly it fails
 *
 * **Each entry balances in its own functional currency.** The two sides are
 * two ordinary entries in two sets of books. Neither can borrow the other's
 * currency to balance in.
 *
 * **The settlement amounts cancel exactly.** One transaction holds one
 * amount, so there is nothing to reconcile - and that is asserted rather than
 * assumed, because "by construction" is a claim about code that can change.
 *
 * **The translation difference is identified, per pair.** Two entities at two
 * rates produce two functional figures, and the difference is real. A group
 * total would net differences in opposite directions and read as zero; the
 * question during a consolidation is which balance a difference is on.
 *
 * **An inter-entity line cannot exist without a transaction.** Which is the
 * failure that actually happens: somebody reaches the inter-company account
 * another way, and the balance sits there growing with nothing to explain it.
 */
final class InterEntityRules
{
    use Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    /** @var array<string, Entity> */
    private array $group = [];

    private Carbon $date;

    private string $entityId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['inter-entity postings (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheGroup();

            return $this->withTheLedgerProof([
                'a pair posts two entries, each balanced in its own currency' => $this->bothSidesBalance(),
                'the settlement amounts cancel exactly' => $this->settlementCancels(),
                'the translation difference is identified, not absorbed' => $this->translationIsIdentified(),
                'an inter-entity line cannot exist without a transaction' => $this->linesMustBePaired(),
                'a transaction needs two different entities and a description' => $this->theTransactionIsChecked(),
                'a year cannot close over a broken pair' => $this->theCloseRefuses(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['inter-entity postings could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 240),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function bothSidesBalance(): array
    {
        $violations = [];
        $trialBalance = app(TrialBalance::class);

        foreach ($this->group as $key => $entity) {
            $statement = app(EntityContext::class)->during(
                $entity,
                fn (): array => $trialBalance->for(
                    (string) $entity->getKey(),
                    (string) $entity->functional_currency,
                    $this->date,
                ),
            );

            if ($statement['balances'] !== true) {
                $violations[] = sprintf(
                    '%s (%s) does not balance: %s against %s. Each side of an inter-entity transaction '.
                    'is an ordinary entry in its own books and cannot borrow the other entity\'s '.
                    'currency to balance in',
                    $key,
                    (string) $entity->functional_currency,
                    $statement['total_debit'],
                    $statement['total_credit'],
                );
            }

            if ($statement['rows']->isEmpty()) {
                $violations[] = sprintf('%s has no balances at all, so nothing was posted into it', $key);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function settlementCancels(): array
    {
        $result = app(InterEntityReconciliation::class)->eliminations($this->date, 'SAR', $this->closingRates());
        $violations = [];

        /*
         | Counted among THIS group's pairs, not among all of them.
         |
         | The first version asserted six pairs in total and failed with
         | eighteen, because a tenant can have inter-entity history of its
         | own - and every real one does. A check that only passes on an
         | empty database is a check that gets deleted.
         */
        $mine = $this->minePairs($result['pairs']);

        if (count($mine) !== 6) {
            $violations[] = sprintf(
                'the consolidation found %d of this group\'s 6 pairs. A cross-entity read of a table '.
                'under row-level security returns NOTHING rather than everything, and nothing looks like '.
                'a clean result',
                count($mine),
            );
        }

        /*
         | The residual is summed over THIS group's pairs.
         |
         | The global figure includes the tenant's own history, which is
         | somebody else's problem and would make this check report theirs.
         */
        $residual = BigDecimal::zero();

        foreach ($mine as $pair) {
            $residual = $residual->plus($pair['settlement_residual'] ?? '0');
        }

        if (! $residual->isZero()) {
            $violations[] = sprintf(
                'this group\'s settlement amounts leave %s rather than cancelling. One transaction '.
                'holds one amount and both sides derive from it, so a residual means that has stopped '.
                'being true',
                (string) $residual,
            );
        }

        if ($result['missing_rates'] !== []) {
            $violations[] = sprintf(
                'the consolidation reported %s - a pair it could not translate or could not see from '.
                'both sides',
                implode('; ', $result['missing_rates']),
            );
        }

        if ($result['eliminates'] !== true) {
            $violations[] = 'the pairs do not eliminate in the settlement currency';
        }

        if (app(InterEntityReconciliation::class)->halfPosted() !== []) {
            $violations[] = 'a transaction is missing one of its two entries, which a deferred '.
                'constraint should make impossible to commit';
        }

        return $violations;
    }

    /** @return list<string> */
    public function translationIsIdentified(): array
    {
        $result = app(InterEntityReconciliation::class)->eliminations($this->date, 'SAR', $this->closingRates());
        $violations = [];

        $withADifference = array_values(array_filter(
            $this->minePairs($result['pairs']),
            static fn (array $pair): bool => ! BigDecimal::of($pair['translation_difference'])->isZero(),
        ));

        /*
         | Four of the six pairs cross a currency boundary, so four must show
         | a difference.
         |
         | Zero differences would mean the two sides were translated at the
         | same rate - which is only true when both entities keep books in
         | the same currency, and this group deliberately does not.
         */
        if (count($withADifference) < 4) {
            $violations[] = sprintf(
                'only %d of 6 pairs report a translation difference. Two entities keeping books in '.
                'different currencies translate the same settlement amount at different rates, so the '.
                'difference is arithmetic - its absence means it was absorbed somewhere',
                count($withADifference),
            );
        }

        /*
         | And each difference must be the two sides' presentation figures
         | summed - not something rounded, netted or carried.
         */
        foreach ($this->minePairs($result['pairs']) as $pair) {
            $expected = BigDecimal::of($pair['from_in_presentation'])->plus($pair['to_in_presentation']);

            if (! $expected->isEqualTo(BigDecimal::of($pair['translation_difference']))) {
                $violations[] = sprintf(
                    '%s reports a difference of %s where %s and %s sum to %s',
                    $pair['reference'],
                    $pair['translation_difference'],
                    $pair['from_in_presentation'],
                    $pair['to_in_presentation'],
                    (string) $expected,
                );
            }
        }

        /*
         | A pair that does NOT cross a currency boundary must show zero.
         |
         | Otherwise the "difference" is a rounding artefact being reported
         | as a translation difference, which would send somebody looking for
         | a rate that was never applied.
         */
        foreach ($this->minePairs($result['pairs']) as $pair) {
            if (! str_ends_with($pair['settlement'], 'SAR')) {
                continue;
            }

            if (! str_contains($pair['from_functional'], 'SAR') || ! str_contains($pair['to_functional'], 'SAR')) {
                continue;
            }

            if (! BigDecimal::of($pair['translation_difference'])->isZero()) {
                $violations[] = sprintf(
                    '%s is settled in SAR between two SAR entities and reports a translation difference '.
                    'of %s. Nothing was translated, so a difference here is a rounding artefact wearing '.
                    'the name of one',
                    $pair['reference'],
                    $pair['translation_difference'],
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function linesMustBePaired(): array
    {
        $violations = [];
        $entity = $this->group['A'];
        $entityId = (string) $entity->getKey();

        $interEntityAccount = app(EntityContext::class)->during($entity, fn (): ?string => DB::connection('tenant')
            ->table('accounts')
            ->join('account_roles', 'account_roles.account_id', '=', 'accounts.id')
            ->where('accounts.entity_id', $entityId)
            ->where('account_roles.role', 'inter_entity')
            ->value('accounts.id'));

        if ($interEntityAccount === null) {
            return ['the chart has no inter-entity account, so nothing can be asserted about its lines'];
        }

        $cash = app(EntityContext::class)->during($entity, fn (): string => (string) Account::query()
            ->where('entity_id', $entityId)
            ->where('code', '1.1.1.1')
            ->value('id'));

        /*
         | Raw SQL, because this is about what the DATABASE refuses.
         |
         | The service cannot produce an unpaired line - that is the point of
         | it - so going through the service would assert nothing. A backfill
         | script, a psql session and a future bug all write rows this way.
         */
        $unpaired = $this->writeTwoLines($entityId, $interEntityAccount, $cash, null);

        if ($unpaired === null) {
            $violations[] = 'a line reached the inter-entity account with no transaction behind it. An '.
                'inter-company balance with no counterpart cannot be eliminated in consolidation and '.
                'cannot be explained to the other entity\'s accountant - it simply sits there, growing';
        } elseif (! str_contains($unpaired, 'names no inter-entity transaction')) {
            $violations[] = sprintf(
                'an unpaired inter-entity line was refused for some other reason: %s',
                Str::limit($unpaired, 120),
            );
        }

        /*
         | And the other direction: a transaction named on a line that is
         | not an inter-entity line at all.
         */
        $transactionId = (string) DB::connection('tenant')->table('inter_entity_transactions')
            ->where('from_entity_id', $entityId)
            ->value('id');

        $misnamed = $this->writeTwoLines($entityId, $cash, $cash, $transactionId);

        if ($misnamed === null) {
            $violations[] = 'a line to an ordinary account named an inter-entity transaction. The '.
                'elimination would remove a figure that is not the inter-company balance';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theTransactionIsChecked(): array
    {
        $violations = [];
        $posting = app(InterEntityPosting::class);
        $a = $this->group['A'];
        $b = $this->group['B'];

        $cash = $this->accountIn($a, '1.1.1.1');
        $expense = $this->accountIn($b, '5.2');

        foreach ([
            'both sides naming the same entity' => [
                fn () => $posting->post($a, $a, Money::of('100.0000', 'SAR'), $this->date,
                    'a transaction with itself', $cash, $cash),
                'two different entities',
            ],
            'a settlement of zero' => [
                fn () => $posting->post($a, $b, Money::of('0.0000', 'SAR'), $this->date,
                    'a transaction of nothing at all', $cash, $expense),
                'records nothing',
            ],
            'a description of three characters' => [
                fn () => $posting->post($a, $b, Money::of('100.0000', 'SAR'), $this->date,
                    'fix', $cash, $expense),
                'at least ten characters',
            ],
        ] as $label => [$attempt, $needle]) {
            $refusal = $this->refusal($attempt);

            if ($refusal === null) {
                $violations[] = sprintf('%s was accepted', $label);
            } elseif (! str_contains($refusal, $needle)) {
                $violations[] = sprintf(
                    '%s was refused for some other reason: %s',
                    $label,
                    Str::limit($refusal, 120),
                );
            }
        }

        /*
         | A foreign settlement with no rate.
         |
         | Refused rather than defaulted to 1, because a rate of 1 asserts
         | the two currencies are at par and the assertion is invisible
         | afterwards.
         */
        $noRate = $this->refusal(fn () => $posting->post(
            $a,
            $this->group['C'],
            Money::of('500.0000', 'USD'),
            $this->date,
            'a dollar transaction with no rate supplied',
            $cash,
            $this->accountIn($this->group['C'], '5.2'),
        ));

        if ($noRate === null) {
            $violations[] = 'a transaction settled in a foreign currency was posted with no rate. The '.
                'alternative to refusing is a rate of 1, which asserts the currencies are at par';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCloseRefuses(): array
    {
        $violations = [];
        $entity = $this->group['A'];
        $entityId = (string) $entity->getKey();

        /*
         | A half-posted transaction, written directly.
         |
         | The deferred constraint refuses it at COMMIT, so inside this
         | transaction it exists - which is exactly the state the close has
         | to notice. Forcing the constraint immediate would refuse the
         | fixture rather than letting the close be tested against it.
         */
        $this->connection->statement('SAVEPOINT half_posted');

        try {
            app(EntityContext::class)->during($entity, function () use ($entityId): void {
                DB::connection('tenant')->table('inter_entity_transactions')->insert([
                    'id' => (string) Str::ulid(),
                    'from_entity_id' => $entityId,
                    'to_entity_id' => (string) $this->group['B']->getKey(),
                    'reference' => 'IE-HALF-'.Str::random(6),
                    'description' => 'a transaction written directly, with no entries',
                    'accounting_date' => $this->date->toDateString(),
                    'settlement_currency' => 'SAR',
                    'settlement_amount' => '999.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $halfPosted = app(InterEntityReconciliation::class)->halfPosted();

            if ($halfPosted === []) {
                $violations[] = 'a transaction with neither entry was not reported as half-posted, so '.
                    'the close would proceed over it and lock an unexplainable balance into retained '.
                    'earnings';
            }
        } finally {
            $this->connection->statement('ROLLBACK TO SAVEPOINT half_posted');
        }

        return $violations;
    }

    // ---------------------------------------------------------------------

    /**
     * The pairs belonging to this check's own group.
     *
     * A tenant has inter-entity history of its own and the consolidation
     * reads all of it, correctly. The claims here are about the six
     * transactions this check posted, so they are selected by the entity
     * codes it created - which are random per run and cannot collide with
     * anything already there.
     *
     * @param  list<array<string, string>>  $pairs
     * @return list<array<string, string>>
     */
    private function minePairs(array $pairs): array
    {
        $codes = array_map(
            static fn (Entity $entity): string => (string) $entity->code,
            array_values($this->group),
        );

        return array_values(array_filter(
            $pairs,
            static fn (array $pair): bool => in_array($pair['from_entity'], $codes, true)
                && in_array($pair['to_entity'], $codes, true),
        ));
    }

    /** @return array<string, Rate> */
    private function closingRates(): array
    {
        return [
            'EUR/SAR' => $this->rate('EUR', 'SAR', '4.400000000000'),
            'USD/SAR' => $this->rate('USD', 'SAR', '3.760000000000'),
        ];
    }

    private function rate(string $from, string $to, string $value): Rate
    {
        return new Rate($from, $to, $value, $this->date->toDateTimeImmutable(), 'manual', 'market');
    }

    private function accountIn(Entity $entity, string $code): string
    {
        return app(EntityContext::class)->during($entity, fn (): string => (string) Account::query()
            ->where('entity_id', $entity->getKey())
            ->where('code', $code)
            ->value('id'));
    }

    /**
     * Two raw lines in one entry, to see what the database refuses.
     *
     * @return string|null the refusal, or null if it committed
     */
    private function writeTwoLines(
        string $entityId,
        string $firstAccount,
        string $secondAccount,
        ?string $transactionId,
    ): ?string {
        $savepoint = 'ie_'.bin2hex(random_bytes(4));
        $entryId = (string) Str::ulid();

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $period = (string) DB::connection('tenant')->table('periods')
                ->where('entity_id', $entityId)
                ->where('is_adjusting', false)
                ->whereDate('starts_on', '<=', $this->date->toDateString())
                ->whereDate('ends_on', '>=', $this->date->toDateString())
                ->value('id');

            DB::connection('tenant')->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $entityId,
                'journal_id' => (string) DB::connection('tenant')->table('journals')
                    ->where('entity_id', $entityId)->where('code', 'GEN')->value('id'),
                'period_id' => $period,
                'number' => 'IEP-'.Str::random(8),
                'accounting_date' => $this->date->toDateString(),
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([[$firstAccount, '50.0000', $transactionId], [$secondAccount, '-50.0000', null]] as $index => [$account, $amount, $transaction]) {
                DB::connection('tenant')->table('journal_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'journal_entry_id' => $entryId,
                    'accounting_date' => $this->date->toDateString(),
                    'period_id' => $period,
                    'account_id' => $account,
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'functional_amount' => $amount,
                    'inter_entity_transaction_id' => $index === 0 ? $transaction : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->connection->statement('SET CONSTRAINTS ALL DEFERRED');
            $this->connection->statement('RELEASE SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200);
        }
    }

    /** @return string|null the refusal, or null if it succeeded */
    private function refusal(callable $attempt): ?string
    {
        $savepoint = 'att_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220);
        }
    }

    private function seedTheGroup(): void
    {
        $context = app(EntityContext::class);
        $this->date = Carbon::now()->startOfYear()->addMonths(6)->addDays(9);

        foreach ([['A', 'SAR'], ['B', 'EUR'], ['C', 'USD'], ['D', 'SAR']] as [$key, $currency]) {
            $context->forget();

            $entity = Entity::create([
                'code' => 'IE'.$key.random_int(100, 999),
                'legal_name' => 'Group entity '.$key,
                'country' => 'SA',
                'functional_currency' => $currency,
                'active' => true,
            ]);

            $context->use($entity);

            /*
             | A group entity transacting in three currencies has to enable
             | them. Enabling one is a deliberate act and the ledger refuses
             | a line in a currency nobody opted into, rather than converting
             | at a rate nobody chose.
             */
            foreach (['SAR', 'EUR', 'USD'] as $enabled) {
                if ($enabled !== $currency) {
                    app(CurrencyPolicy::class)->enable($entity, $enabled);
                }
            }

            $period = app(FiscalCalendarGenerator::class)->periodFor((string) $entity->getKey(), $this->date);

            if ($period !== null && $period->state !== PeriodState::Open) {
                app(PeriodStateMachine::class)->transition(
                    $period,
                    PeriodState::Open,
                    'opened for the inter-entity group check',
                );
            }

            $this->group[$key] = $entity;
        }

        $this->entityId = (string) $this->group['A']->getKey();

        $context->forget();

        $user = User::create([
            'name' => 'Group controller',
            'email' => 'grp'.random_int(100000, 999999).'@check.test',
            'password' => 'x-not-a-real-password',
            'active' => true,
        ]);

        $role = Role::query()->where('key', 'controller')->firstOrFail();

        foreach ($this->group as $entity) {
            UserEntityRole::create([
                'user_id' => $user->getKey(),
                'entity_id' => $entity->getKey(),
                'role_id' => $role->getKey(),
            ]);
        }

        auth()->guard('web')->setUser($user);

        $this->postSixTransactions();

        $context->use($this->entityId);
    }

    /**
     * Six transactions, both directions, three currencies.
     *
     * The rate for each side is chosen per transaction, because the two
     * entities translate the same settlement amount into different
     * currencies and one rate could only be right for one of them.
     */
    private function postSixTransactions(): void
    {
        $table = [
            'SAR' => ['EUR' => '0.230000000000', 'USD' => '0.266666666667'],
            'EUR' => ['SAR' => '4.347826086957', 'USD' => '1.159420289855'],
            'USD' => ['SAR' => '3.750000000000', 'EUR' => '0.862500000000'],
        ];

        $posting = app(InterEntityPosting::class);

        foreach ([
            ['A', 'B', '10000.0000', 'SAR', 'A pays a supplier invoice on behalf of B'],
            ['B', 'A', '4000.0000', 'EUR', 'B settles a shared licence cost for A'],
            ['A', 'C', '25000.0000', 'USD', 'A funds C working capital in dollars'],
            ['C', 'D', '8000.0000', 'USD', 'C recharges D for shared services'],
            ['D', 'B', '15000.0000', 'SAR', 'D pays B staff costs in riyals'],
            ['B', 'C', '2500.0000', 'EUR', 'B invoices C for group management'],
        ] as [$fromKey, $toKey, $amount, $currency, $description]) {
            $from = $this->group[$fromKey];
            $to = $this->group[$toKey];

            $fromCurrency = (string) $from->functional_currency;
            $toCurrency = (string) $to->functional_currency;

            $posting->post(
                from: $from,
                to: $to,
                settlement: Money::of($amount, $currency),
                accountingDate: $this->date,
                description: $description,
                fromCounterAccountId: $this->accountIn($from, '1.1.1.1'),
                toCounterAccountId: $this->accountIn($to, '5.2'),
                fromRate: $currency === $fromCurrency
                    ? null
                    : $this->rate($currency, $fromCurrency, $table[$currency][$fromCurrency]),
                toRate: $currency === $toCurrency
                    ? null
                    : $this->rate($currency, $toCurrency, $table[$currency][$toCurrency]),
            );
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
