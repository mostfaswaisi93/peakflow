<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Exceptions\EntryDoesNotBalance;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Services\ChartTemplate;
use App\Tenant\Ledger\Services\LedgerVerifier;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the ledger actually refuses - PF-043, PF-044, PF-045.
 *
 * ## Testing a DEFERRED constraint without committing
 *
 * The balanced-entry trigger fires at COMMIT, and these checks run inside a
 * transaction that is rolled back - so the trigger would never fire at all
 * and an unbalanced entry would appear to be accepted.
 *
 * `SET CONSTRAINTS ALL IMMEDIATE` is what resolves that: it forces every
 * deferred constraint to check at that moment. The check therefore exercises
 * the same trigger the same way a commit would, while still leaving nothing
 * behind.
 *
 * Without it this file would be a set of green checks proving nothing, which
 * is the worst possible outcome for the invariant the whole phase exists to
 * guarantee.
 */
final class LedgerEnforcementRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    /** @var array<string, string> account code => id */
    private array $accounts = [];

    private string $journalId = '';

    private string $periodId = '';

    private string $postingDate = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['ledger invariants (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');

        $this->connection->beginTransaction();

        try {
            $this->seed();

            return [
                // FIRST, because every check below is meaningless if a guard
                // is switched off - and they would all pass.
                'every ledger guard is enabled' => $this->guardsAreEnabled(),
                'an entry must balance in the functional currency' => $this->entriesMustBalance(),
                'a header or control account refuses a manual posting' => $this->accountsRefuseWhatTheyShould(),
                'a closed period refuses a posting' => $this->closedPeriodsRefuse(),
                'a posted entry cannot be altered' => $this->postedEntriesAreImmutable(),
                'the chart opens with every required control role' => $this->chartIsUsable(),
                'a posting rule that does not balance is refused by name' => $this->badRulesFailByName(),
                'ledger:verify finds an entry someone unbalanced' => $this->verifyCatchesDamage(),
            ];
        } catch (Throwable $e) {
            return ['ledger invariants could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function entriesMustBalance(): array
    {
        $violations = [];

        // Balanced: must survive the immediate check.
        $balanced = $this->writeEntry([['1.1.1.1', '1000.0000'], ['4.1', '-1000.0000']]);

        if ($balanced !== null) {
            $violations[] = 'a balanced entry was refused: '.$balanced;
        }

        // Unbalanced: must not.
        $unbalanced = $this->writeEntry([['1.1.1.1', '1000.0000'], ['4.1', '-999.0000']]);

        if ($unbalanced === null) {
            $violations[] = 'an entry whose lines sum to 1.0000 was ACCEPTED; the ledger does not balance';
        } elseif (! str_contains($unbalanced, 'does not balance')) {
            $violations[] = 'an unbalanced entry was refused for the wrong reason: '.$unbalanced;
        }

        /*
         | A multi-line entry is transiently unbalanced after every line but
         | the last. If this fails, the trigger has been changed to fire per
         | statement - which rejects every real posting while still passing a
         | test that writes a balanced entry in one go.
         */
        $multi = $this->writeEntry([
            ['1.1.1.1', '250.0000'], ['1.1.1.1', '250.0000'],
            ['1.1.1.1', '250.0000'], ['1.1.1.1', '250.0000'],
            ['4.1', '-1000.0000'],
        ]);

        if ($multi !== null) {
            $violations[] = 'a five-line balanced entry was refused, so the balance check is no longer '.
                'deferred to commit: '.$multi;
        }

        return $violations;
    }

    /** @return list<string> */
    public function accountsRefuseWhatTheyShould(): array
    {
        $violations = [];

        // '1' is the Assets header - a total, not a place to post.
        $header = $this->writeEntry([['1', '100.0000'], ['4.1', '-100.0000']]);

        if ($header === null || ! str_contains($header, 'is a header')) {
            $violations[] = 'a posting to a header account was accepted, so it is invisible in every '.
                'breakdown of the total it belongs to';
        }

        // '1.1.1.2' is the bank control account.
        $control = $this->writeEntry([['1.1.1.2', '100.0000'], ['4.1', '-100.0000']]);

        if ($control === null || ! str_contains($control, 'control account')) {
            $violations[] = 'a manual posting to a control account was accepted; once a control account and '.
                'its subledger disagree, neither can be shown to be the correct one';
        }

        // A registered posting rule may.
        $byRule = $this->writeEntry([['1.1.1.2', '100.0000'], ['4.1', '-100.0000']], rule: 'probe.rule');

        if ($byRule !== null) {
            $violations[] = 'a posting rule was refused a control account, which leaves nothing able to '.
                'post a receivable: '.$byRule;
        }

        return $violations;
    }

    /** @return list<string> */
    public function closedPeriodsRefuse(): array
    {
        $violations = [];

        foreach (['closed', 'soft_closed'] as $state) {
            $this->connection->table('periods')->where('id', $this->periodId)->update(['state' => $state]);

            $refusal = $this->writeEntry([['1.1.1.1', '10.0000'], ['4.1', '-10.0000']]);

            if ($refusal === null || ! str_contains($refusal, 'does not accept this posting')) {
                $violations[] = sprintf('a %s period accepted an ordinary posting', $state);
            }
        }

        // Soft-closed still takes adjustments - that is what the state is for.
        $this->connection->table('periods')->where('id', $this->periodId)->update(['state' => 'soft_closed']);

        $adjusting = $this->writeEntry([['1.1.1.1', '10.0000'], ['4.1', '-10.0000']], rule: 'adjusting');

        if ($adjusting !== null) {
            $violations[] = 'a soft-closed period refused an adjusting posting, so the auditor\'s '.
                'adjustments have nowhere to land: '.$adjusting;
        }

        $this->connection->table('periods')->where('id', $this->periodId)->update(['state' => 'open']);

        return $violations;
    }

    /** @return list<string> */
    public function postedEntriesAreImmutable(): array
    {
        $violations = [];
        $entryId = (string) Str::ulid();

        $written = $this->writeEntry([['1.1.1.1', '50.0000'], ['4.1', '-50.0000']], entryId: $entryId);

        if ($written !== null) {
            return ['could not write a posted entry to test immutability: '.$written];
        }

        foreach ([
            'UPDATE' => fn () => $this->connection->statement(
                "UPDATE journal_entries SET narration = 'tampered' WHERE id = ?",
                [$entryId],
            ),
            'DELETE' => fn () => $this->connection->statement(
                'DELETE FROM journal_entries WHERE id = ?',
                [$entryId],
            ),
        ] as $operation => $attempt) {
            $savepoint = 'immutable_'.bin2hex(random_bytes(4));
            $this->connection->statement('SAVEPOINT '.$savepoint);

            try {
                $attempt();

                $violations[] = sprintf(
                    'a posted entry was %sd by raw SQL; a correction must be a reversal, or the trial balance '.
                    'stops reconciling to the statements printed from it',
                    strtolower($operation),
                );
            } catch (Throwable) {
                // Refused, by the trigger or by the privilege. Both are right.
            }

            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function chartIsUsable(): array
    {
        $missing = app(ChartTemplate::class)->missingRequiredRoles($this->entityId);

        if ($missing === []) {
            return [];
        }

        return [sprintf(
            'a freshly seeded entity is missing %d required control role(s): %s. It cannot post at all, and '.
            'the first attempt fails with a message about a role rather than about the chart',
            count($missing),
            implode(', ', array_map(static fn ($role): string => $role->value, $missing)),
        )];
    }

    /**
     * Every trigger guarding the ledger is actually ENABLED.
     *
     * `ALTER TABLE ... DISABLE TRIGGER` is persistent DDL. A migration, a
     * data fix, or a test that disables a trigger and is then interrupted
     * leaves the table unguarded - and every check below passes, because
     * nothing refuses anything any more.
     *
     * That is not hypothetical. An earlier version of the damage-injection
     * check disabled these triggers to produce an unbalanced entry; the run
     * was killed between disabling and re-enabling, and the next run reported
     * that unbalanced entries, header-account postings and manual control
     * -account postings were all ACCEPTED. The ledger had no guards at all
     * and the suite would have gone on saying so in whichever direction was
     * asked.
     *
     * So this runs first, and the current damage check never touches a
     * trigger.
     *
     * @return list<string>
     */
    public function guardsAreEnabled(): array
    {
        $expected = [
            'journal_lines' => [
                'journal_lines_entry_balances',
                'journal_lines_follow_entry',
                'journal_lines_account_is_postable',
                'journal_lines_posted_immutable',
            ],
            'journal_entries' => [
                'journal_entries_period_accepts',
                'journal_entries_posted_immutable',
            ],
            'periods' => ['periods_locked_is_terminal'],
        ];

        $rows = $this->connection->select(<<<'SQL'
            SELECT c.relname AS table_name, t.tgname AS trigger_name, t.tgenabled::text AS enabled
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND NOT t.tgisinternal
              AND c.relname IN ('journal_lines', 'journal_entries', 'periods')
        SQL);

        $state = [];

        foreach ($rows as $row) {
            $state[$row->table_name][$row->trigger_name] = (string) $row->enabled;
        }

        $violations = [];

        foreach ($expected as $table => $triggers) {
            foreach ($triggers as $trigger) {
                $enabled = $state[$table][$trigger] ?? null;

                if ($enabled === null) {
                    $violations[] = sprintf('%s.%s does not exist, so nothing enforces what it guarded', $table, $trigger);

                    continue;
                }

                // 'O' is origin-enabled, which is what a working trigger is.
                // 'D' is disabled; 'R' and 'A' are replica-only states that
                // also mean it does not fire for ordinary writes.
                if ($enabled !== 'O') {
                    $violations[] = sprintf(
                        '%s.%s is in state "%s" rather than enabled - the ledger is accepting writes it should '.
                        'refuse, and every other check here would pass while it does',
                        $table,
                        $trigger,
                        $enabled,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * A rule whose lines do not sum to zero fails with ITS OWN name - PF-047.
     *
     * The database catches it at commit, truly and uselessly: the message
     * says the entry does not balance, not which of forty rules produced it.
     * The poster checks first so a developer is told where to look.
     *
     * @return list<string>
     */
    public function badRulesFailByName(): array
    {
        $registry = app(PostingRuleRegistry::class);

        $broken = new class implements PostingRule
        {
            public function key(): string
            {
                return 'enforcement.probe.unbalanced';
            }

            public function describe(): RuleDescription
            {
                return RuleDescription::of($this->key(), 'فحص', 'Probe', []);
            }

            public function lines(array $event, PostingContext $context): array
            {
                // One side only. Balances to 100, which is the bug.
                return [PostingLine::toAccount((string) $event['account'], $context->money('100.0000'))];
            }
        };

        $registry->register($broken);

        $entity = Entity::query()->whereKey($this->entityId)->firstOrFail();
        $violations = [];

        try {
            app(Poster::class)->post(
                $entity,
                $broken->key(),
                ['account' => $this->accounts['1.1.1.1']],
                Carbon::parse($this->postingDate),
            );

            $violations[] = 'a rule returning one unbalanced line was accepted';
        } catch (EntryDoesNotBalance $e) {
            if (! str_contains($e->getMessage(), $broken->key())) {
                $violations[] = 'an unbalanced rule was refused without naming the rule, so the failure says '.
                    'which constraint fired rather than which rule is wrong';
            }
        } catch (Throwable $e) {
            $violations[] = 'an unbalanced rule failed with '.class_basename($e).
                ' rather than a refusal naming it: '.Str::limit($e->getMessage(), 120);
        }

        // And it wrote nothing.
        $written = $this->connection->table('journal_entries')
            ->where('posting_rule', $broken->key())
            ->count();

        if ($written > 0) {
            $violations[] = 'a refused rule still wrote an entry, so the check happens after the write';
        }

        return $violations;
    }

    /**
     * `ledger:verify` must find an entry that someone unbalanced - PF-054.
     *
     * ## The damage is made through the ordinary connection, on purpose
     *
     * The first version injected it as the MIGRATOR with the triggers
     * disabled, which is the shape of the real cause - a restore or a
     * backfill. It also hung: disabling a trigger needs ACCESS EXCLUSIVE on
     * the table, and the enclosing transaction already holds locks on it, so
     * the two waited on each other until the run was killed.
     *
     * The deferred balance trigger gives a simpler route. It fires at COMMIT,
     * and this transaction never commits - so an extra line inserted here
     * leaves the entry genuinely unbalanced, in a transaction that will be
     * rolled back, with no privileged connection and no lock to contend for.
     *
     * A verifier that has never found anything is a verifier nobody has
     * tested, and the first time that matters is during an audit.
     *
     * @return list<string>
     */
    public function verifyCatchesDamage(): array
    {
        $verifier = app(LedgerVerifier::class);

        if ($verifier->verify($this->entityId)->isNotEmpty()) {
            return ['the probe ledger is already damaged before anything was injected'];
        }

        $entryId = (string) Str::ulid();

        if ($this->writeEntry([['1.1.1.1', '20.0000'], ['4.1', '-20.0000']], entryId: $entryId) !== null) {
            return ['could not write a healthy entry to damage'];
        }

        $violations = [];
        $savepoint = 'damage_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            // One extra line. The entry now sums to 3.0000, and the deferred
            // trigger will not notice until a commit that never comes.
            $this->connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => $this->postingDate,
                'period_id' => $this->periodId,
                'account_id' => $this->accounts['4.1'],
                'amount' => '3.0000',
                'currency' => 'SAR',
                'functional_amount' => '3.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $found = $verifier->verify($this->entityId)
                ->contains(static fn ($finding): bool => $finding->kind === 'unbalanced entry');

            if (! $found) {
                $violations[] = 'an entry left out by 3.0000 was not reported, so the global proof proves '.
                    'nothing about the invariant the whole phase exists to guarantee';
            }
        } catch (Throwable $e) {
            $violations[] = 'could not inject damage to test the verifier: '.Str::limit($e->getMessage(), 140);
        } finally {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        // And the finding clears once the damage is gone.
        if ($violations === [] && $verifier->verify($this->entityId)->isNotEmpty()) {
            $violations[] = 'the verifier still reports damage after it was rolled back, so it is reading '.
                'something other than the current state';
        }

        return $violations;
    }

    /**
     * Write an entry and return the refusal, or null if it was accepted.
     *
     * `SET CONSTRAINTS ALL IMMEDIATE` forces the deferred balance trigger to
     * check here rather than at a commit that will never happen. Each attempt
     * runs in its own savepoint, because a refusal aborts the transaction.
     *
     * @param  list<array{0: string, 1: string}>  $lines  [account code, signed amount]
     */
    private function writeEntry(array $lines, ?string $rule = null, ?string $entryId = null): ?string
    {
        $entryId ??= (string) Str::ulid();
        $savepoint = 'entry_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $this->connection->statement(
                "SELECT set_config('peakflow.posting_rule', ?, true)",
                [$rule ?? ''],
            );

            $this->connection->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $this->journalId,
                'period_id' => $this->periodId,
                'number' => 'CHK-'.Str::ulid(),
                'accounting_date' => $this->postingDate,
                'status' => 'posted',
                'posting_rule' => $rule,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as [$code, $amount]) {
                $this->connection->table('journal_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $this->entityId,
                    'journal_entry_id' => $entryId,
                    'accounting_date' => $this->postingDate,
                    'period_id' => $this->periodId,
                    'account_id' => $this->accounts[$code],
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'functional_amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // The whole point: make the deferred trigger fire now.
            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->connection->statement('SET CONSTRAINTS ALL DEFERRED');

            $this->connection->statement('RELEASE SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 240);
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'CHK'.random_int(100, 999),
            'legal_name' => 'Ledger check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        /*
         | Nothing is seeded here on purpose.
         |
         | Creating an entity now opens its ledger: the EntityCreated listener
         | applies the chart template and generates this year and the next.
         | Seeding again would be idempotent for the chart and would collide
         | with the exclusion constraint for the calendar - which is what this
         | check did until the listener landed, and the collision is the
         | constraint working.
         |
         | So the check uses what a real entity actually has, which is also
         | what it should be testing.
         */
        $postingDate = Carbon::now()->startOfYear()->addMonths(2)->addDays(9);

        $period = app(FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, $postingDate);

        if ($period === null) {
            throw new \RuntimeException(
                'A newly created entity has no period covering '.$postingDate->toDateString().
                ', so the EntityCreated listener did not open its calendar.'
            );
        }

        $period->update(['state' => 'open']);

        $this->periodId = (string) $period->getKey();
        $this->postingDate = $postingDate->toDateString();

        $this->journalId = (string) $this->connection->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        foreach (['1', '1.1.1.1', '1.1.1.2', '4.1'] as $code) {
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
