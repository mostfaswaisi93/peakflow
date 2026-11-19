<?php

declare(strict_types=1);

namespace App\Platform\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Breaking each invariant on purpose, so the alert can be seen to fire.
 *
 * PF-242's last criterion: "a game-day exercise injects each condition in
 * staging and verifies the alert fires and the runbook works".
 *
 * ## Why this is production code
 *
 * Because two things need it and they must agree. `invariants:game-day` runs
 * it against a staging tenant once per release; `bin/verify observability`
 * runs the same injectors against a fixture on every run. One implementation,
 * two runners - the arrangement the whole of `bin/verify` is built on - and
 * the alternative is a game day whose injections differ from the ones the
 * checks prove, which means the release exercise and the build disagree about
 * what was tested.
 *
 * ## It damages the DATA, not the alerting
 *
 * That distinction is the point. Asserting that a watcher raises an alert when
 * handed a finding proves the plumbing; it proves nothing about whether the
 * watcher can SEE a real breach. So every injector here produces the breach
 * the way production produces it: a stored balance moved by a unit, an extra
 * line on a posted entry, a fingerprint that no longer matches its content, a
 * grant the application role should not hold.
 *
 * ## The caller wraps it in a savepoint, and nothing here commits
 *
 * Deliberately: an injector that managed its own transaction could not be
 * composed, and the one that needs a second connection - the privilege
 * grant - has to hand that connection back so the caller can roll it back on
 * the same session that made the grant.
 *
 * ## Refused outside a development or staging environment
 *
 * It writes damage. Even rolled back it takes locks on `journal_lines`, and a
 * crash between the injection and the rollback would leave a production
 * ledger holding an unbalanced entry. There is no `--force`.
 */
final class InvariantInjection
{
    /** Environments this may run in at all. */
    private const ALLOWED = ['local', 'development', 'testing', 'staging'];

    /**
     * The conditions this can produce, and what each one damages.
     *
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        return [
            'read_model_drift' => 'moves one stored balance by a unit, the way a partial rebuild or a '.
                'restored backup leaves it',
            'unbalanced_entry_in_database' => 'adds one line to a posted entry, which the deferred '.
                'balance trigger will not notice until a commit that never comes',
            'hash_chain_divergence' => 'changes a posted entry\'s stored fingerprint so it no longer '.
                'matches what the database recomputes from its lines',
            'subledger_mismatch' => 'reaches a control account with no posting rule recorded, so the '.
                'subledger and the control account disagree and neither can be shown to be right',
            'archive_checksum_mismatch' => 'changes a sealed artefact\'s recorded hash, the way storage '.
                'corruption or an edit to history presents',
            'privilege_drift' => 'grants the application role TRUNCATE on journal_entries, which is the '.
                'shape of a manual grant made during an incident',
            'suspense_above_threshold' => 'parks an unmatched bank line in suspense against a threshold '.
                'of zero, so one unexplained receipt breaches it',
            'in_transit_aged' => 'moves the clock sixty days past a released run\'s pay date, because a '.
                'released run cannot be edited and the condition is produced by time rather than by a '.
                'write',
        ];
    }

    public function __construct(private readonly PrivilegeMatrix $matrix) {}

    /**
     * Break one invariant.
     *
     * @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object}
     *   `injected` false with a stated reason when this tenant has nothing to
     *   damage - which is a fact about the tenant, not a pass. `rollback` is
     *   non-null only for the injector that needs a second connection.
     */
    public function inject(string $condition, string $entityId): array
    {
        $this->assertSafe();

        return match ($condition) {
            'read_model_drift' => $this->moveAStoredBalance($entityId),
            'unbalanced_entry_in_database' => $this->unbalanceAnEntry($entityId),
            'hash_chain_divergence' => $this->breakAFingerprint($entityId),
            'subledger_mismatch' => $this->postToAControlAccountWithNoRule($entityId),
            'archive_checksum_mismatch' => $this->corruptAnArchivedHash($entityId),
            'privilege_drift' => $this->grantAPrivilegeNobodyShouldHold(),
            'suspense_above_threshold' => $this->parkSomethingUnexplained($entityId),
            'in_transit_aged' => $this->ageAReleasedInstruction($entityId),
            default => throw new RuntimeException(sprintf(
                'No injector for "%s". A game day that skipped a condition silently would be a game '.
                'day that proved the alert fires for the conditions somebody remembered.',
                $condition,
            )),
        };
    }

    private function assertSafe(): void
    {
        $environment = (string) app()->environment();

        if (! in_array($environment, self::ALLOWED, true)) {
            throw new RuntimeException(sprintf(
                'The invariant game day injects real damage and will not run in "%s". Even rolled back '.
                'it holds locks on journal_lines, and a crash between the injection and the rollback '.
                'would leave the ledger carrying an unbalanced entry. There is deliberately no way to '.
                'force it.',
                $environment,
            ));
        }
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function moveAStoredBalance(string $entityId): array
    {
        $moved = $this->tenant()->update(
            'UPDATE account_balances SET net = net + 1 WHERE id = ('.
            'SELECT id FROM account_balances WHERE entity_id = ? ORDER BY id LIMIT 1)',
            [$entityId],
        );

        return $moved === 0
            ? $this->notHere('this entity has no stored balance row, so it has never posted anything')
            : $this->done('one stored balance moved by a unit');
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function unbalanceAnEntry(string $entityId): array
    {
        $entry = $this->tenant()->selectOne(
            "SELECT l.journal_entry_id, l.entity_id, l.accounting_date, l.period_id, l.account_id, l.currency ".
            "FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id ".
            'WHERE l.entity_id = ? AND e.status = \'posted\' ORDER BY e.accounting_date, e.id LIMIT 1',
            [$entityId],
        );

        if ($entry === null) {
            return $this->notHere('this entity has no posted journal line to unbalance');
        }

        /*
         | One extra line, through the ORDINARY connection.
         |
         | The balanced-entry rule is a DEFERRED constraint trigger: it fires
         | at COMMIT, and the caller's savepoint is rolled back - so the entry
         | is genuinely unbalanced for the duration of the injection with no
         | privileged connection and no ALTER TABLE to contend for a lock
         | with. Disabling the trigger instead needs ACCESS EXCLUSIVE on a
         | table the enclosing transaction already holds locks on, which
         | deadlocks rather than failing.
         */
        $this->tenant()->table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => (string) $entry->entity_id,
            'journal_entry_id' => (string) $entry->journal_entry_id,
            'accounting_date' => (string) $entry->accounting_date,
            'period_id' => (string) $entry->period_id,
            'account_id' => (string) $entry->account_id,
            'amount' => '3.0000',
            'currency' => (string) $entry->currency,
            'functional_amount' => '3.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->done('one posted entry is out by 3.0000');
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function breakAFingerprint(string $entityId): array
    {
        $fingerprint = $this->tenant()->selectOne(
            'SELECT f.journal_entry_id FROM journal_entry_fingerprints f '.
            'JOIN journal_entries e ON e.id = f.journal_entry_id '.
            'WHERE e.entity_id = ? ORDER BY e.accounting_date, e.id LIMIT 1',
            [$entityId],
        );

        if ($fingerprint === null) {
            return $this->notHere('this entity has no fingerprinted entry, so nothing is inside the proof');
        }

        /*
         | The stored hash is changed, not the entry.
         |
         | A posted entry cannot be updated - the immutability trigger
         | refuses it, correctly - so the realistic shape of this breach is
         | the one the verifier describes: the content and the recorded hash
         | disagree. Which half moved is exactly what an investigation has to
         | establish, and either way the claim "this entry is what it was
         | when it was posted" is no longer true.
         */
        $this->tenant()->update(
            'UPDATE journal_entry_fingerprints SET content_hash = ? WHERE journal_entry_id = ?',
            [str_repeat('0', 64), (string) $fingerprint->journal_entry_id],
        );

        return $this->done('one posted entry no longer matches its fingerprint');
    }

    /**
     * A control account reached with no posting rule recorded.
     *
     * ## Two earlier versions of this were impossible, and that is good news
     *
     * The first inserted a line belonging to no entry - the `orphaned line`
     * finding the verifier looks for. `sync_line_to_entry()` refuses it:
     * "Journal entry … does not exist". The second tried to re-date a line so
     * it fell in a different period from its entry; the same trigger copies
     * the entry's date onto the line, so there is nothing to re-date.
     *
     * Both refusals are the database doing its job, and finding them this way
     * is the point of a game day: two of the conditions `ledger:verify` looks
     * for cannot be produced through the application's own connection at all.
     *
     * What CAN be produced is the one the verifier describes as its own
     * residual risk: an entry that "arrived through a path that set the
     * posting-rule variable without being a rule". The session variable is
     * what lets a control account accept a line, and it is set with
     * `set_config(..., true)` - transaction-local - so anything that sets it
     * and then writes an entry with no `posting_rule` column value produces a
     * subledger that no longer ties to its control account, with nothing in
     * the entry to say which rule put it there.
     *
     * @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object}
     */
    private function postToAControlAccountWithNoRule(string $entityId): array
    {
        $target = $this->tenant()->selectOne(
            'SELECT r.account_id, r.role, l.period_id, l.accounting_date, l.currency, e.journal_id '.
            'FROM account_roles r '.
            'JOIN journal_lines l ON l.entity_id = r.entity_id '.
            'JOIN journal_entries e ON e.id = l.journal_entry_id '.
            'WHERE r.entity_id = ? ORDER BY r.role LIMIT 1',
            [$entityId],
        );

        if ($target === null) {
            return $this->notHere('this entity has no control account and no posted line to date against');
        }

        $entryId = (string) Str::ulid();

        $this->tenant()->table('journal_entries')->insert([
            'id' => $entryId,
            'entity_id' => $entityId,
            'journal_id' => (string) $target->journal_id,
            'period_id' => (string) $target->period_id,
            'number' => 'GAMEDAY-'.Str::upper(Str::random(8)),
            'accounting_date' => (string) $target->accounting_date,
            'entered_at' => now(),
            'narration' => 'injected by the invariant game day',
            /*
             | No posting rule, which is the whole point.
             |
             | `posting_rule IS NULL` on a non-draft entry touching a control
             | account is what `ledger:verify` reports as a manual posting:
             | the subledger and the control account now disagree and neither
             | can be shown to be the correct one without re-deriving the
             | whole history.
             */
            'posting_rule' => null,
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         | Transaction-local, and it is what the control-account trigger
         | consults.
         |
         | Set here deliberately: the residual risk the verifier names is a
         | path that sets this variable without being a rule, and reproducing
         | that is the only way to see whether the alert notices.
         */
        $this->tenant()->statement(
            "SELECT set_config('peakflow.posting_rule', 'the invariant game day', true)",
        );

        /*
         | A BALANCED pair, and neither line is zero.
         |
         | Two refusals shaped this. `journal_lines_amount_not_zero` rejects a
         | nil line - correctly, since a zero line tells nobody anything - and
         | the deferred balance rule rejects a one-sided entry at commit, so
         | an injection that left it unbalanced would be injecting the WRONG
         | condition and would mask this one behind the other alert.
         |
         | So the entry balances perfectly and is still wrong: it reaches a
         | control account with no rule recorded, which is the whole point.
         | A tamper that balances is the only kind worth doing.
         */
        $counterpart = $this->tenant()->selectOne(
            'SELECT a.id FROM accounts a LEFT JOIN account_roles r ON r.account_id = a.id '.
            'WHERE a.entity_id = ? AND a.postable = true AND r.role IS NULL ORDER BY a.code LIMIT 1',
            [$entityId],
        );

        if ($counterpart === null) {
            return $this->notHere('this entity has no postable account outside the control roles');
        }

        foreach ([
            [(string) $target->account_id, '5.0000'],
            [(string) $counterpart->id, '-5.0000'],
        ] as [$accountId, $amount]) {
            $this->tenant()->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => (string) $target->accounting_date,
                'period_id' => (string) $target->period_id,
                'account_id' => $accountId,
                'amount' => $amount,
                'currency' => (string) $target->currency,
                'functional_amount' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->done(sprintf(
            'the %s control account was reached with no posting rule recorded',
            (string) $target->role,
        ));
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function corruptAnArchivedHash(string $entityId): array
    {
        $artefact = $this->tenant()->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->whereIn('period_label', fn ($query) => $query
                ->select('period_label')
                ->from('archive_manifests')
                ->where('entity_id', $entityId))
            ->orderBy('content_hash')
            ->first();

        if ($artefact === null) {
            return $this->notHere('this entity has no artefact inside a sealed period');
        }

        /*
         | The BYTES are corrupted, not the row.
         |
         | `archived_artefacts` is WORM and refuses an update by name: "an
         | archive copy that can be corrected is not an archive copy, and the
         | recipient holds a hash of the original". Which is correct, and it
         | means the only way this condition can arise is the way it arises in
         | production: the storage changed under the record. Bit rot, a bucket
         | migration, or somebody editing history.
         |
         | So the file is overwritten and restored afterwards. The disk is not
         | transactional, which is exactly why the rollback is handed back
         | rather than left to the caller's savepoint.
         */
        $disk = \Illuminate\Support\Facades\Storage::disk((string) $artefact->disk);
        $path = (string) $artefact->path;

        if (! $disk->exists($path)) {
            return $this->notHere('the sealed artefact is already missing from storage');
        }

        $original = (string) $disk->get($path);
        $disk->put($path, $original.' corrupted by the invariant game day');

        return [
            'injected' => true,
            'why' => sprintf(
                'the bytes of one sealed artefact in %s no longer hash to its record',
                (string) $artefact->period_label,
            ),
            'rollback' => static function () use ($disk, $path, $original): void {
                $disk->put($path, $original);
            },
            'watcher' => null,
        ];
    }

    /**
     * A grant the application role must never hold.
     *
     * ## Why this one needs a second connection and hands back a rollback
     *
     * The application role cannot grant itself anything - that is the point
     * of the arrangement - so the grant has to be made by the owner. `GRANT`
     * is transactional in PostgreSQL, so it is made inside a transaction on
     * the migrator's own session and rolled back there; and the question has
     * to be asked FROM THAT SESSION, because an uncommitted grant is
     * invisible to every other connection.
     *
     * Which is why a watcher comes back with it: one bound to the migrator's
     * session, asking about the application's role. Asking about
     * `current_user` on that session would report that it holds everything,
     * because it owns the tables - and the first version of the equivalent
     * check in `HardeningRules` passed for exactly that reason.
     *
     * @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object}
     */
    private function grantAPrivilegeNobodyShouldHold(): array
    {
        $role = config('database.connections.tenant.username');

        if (! is_string($role) || $role === '') {
            return $this->notHere('no tenant connection is established, so there is no role to grant to');
        }

        $migrator = $this->migrator();

        if ($migrator === null) {
            return $this->notHere('the migrator could not reach this tenant database');
        }

        $wrapped = $migrator->getQueryGrammar()->wrapTable($role);

        try {
            $migrator->beginTransaction();
            $migrator->statement('GRANT TRUNCATE ON journal_entries TO '.$wrapped);
        } catch (Throwable $e) {
            try {
                $migrator->rollBack();
            } catch (Throwable) {
                // Nothing to roll back if the transaction never opened.
            }

            return $this->notHere('the grant was refused: '.$e->getMessage());
        }

        return [
            'injected' => true,
            'why' => sprintf('%s now holds TRUNCATE on journal_entries', $role),
            'rollback' => static function () use ($migrator): void {
                $migrator->rollBack();
            },
            'watcher' => new \App\Platform\Alerts\PrivilegeDriftWatcher(
                $this->matrix,
                connection: $migrator->getName(),
                role: $role,
            ),
        ];
    }

    /**
     * An unexplained receipt in suspense, against a threshold of zero.
     *
     * ## Why the threshold moves and the balance is real
     *
     * The breach is "suspense holds money nobody can explain", and that is
     * what is injected: a real bank line is parked through the real service,
     * which posts to the suspense control account through the real posting
     * rule. What the threshold is for is deciding when that is worth waking
     * somebody for, and a staging tenant's threshold is whatever somebody set
     * it to - so the exercise sets it to zero and restores it, because the
     * alert's job is to notice the balance rather than to agree with a
     * particular figure.
     *
     * Moving the threshold ALONE would be the hollow version of this: an
     * alert fired by a configuration change proves nothing about whether the
     * watcher can read a control account.
     *
     * @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object}
     */
    private function parkSomethingUnexplained(string $entityId): array
    {
        $transaction = \App\Tenant\Banking\Models\BankTransaction::query()
            ->where('entity_id', $entityId)
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('matches')
                ->whereColumn('matches.bank_transaction_id', 'bank_transactions.id')
                ->whereNull('matches.reversed_at'))
            ->orderBy('booking_date')
            ->first();

        if ($transaction === null) {
            return $this->notHere('this entity has no unmatched bank line to park');
        }

        $was = config('telemetry.thresholds.suspense');
        config(['telemetry.thresholds.suspense' => '0.0000']);

        try {
            app(\App\Tenant\Banking\Services\Suspense::class)->park(
                $transaction,
                str_repeat('0', 26),
                'parked by the invariant game day, to prove the suspense alert can fire',
            );
        } catch (Throwable $e) {
            config(['telemetry.thresholds.suspense' => $was]);

            return $this->notHere('the line could not be parked: '.$e->getMessage());
        }

        return [
            'injected' => true,
            'why' => 'one unmatched bank line parked in suspense, against a threshold of zero',
            'rollback' => static function () use ($was): void {
                config(['telemetry.thresholds.suspense' => $was]);
            },
            'watcher' => null,
        ];
    }

    /**
     * A released instruction, aged past the floor.
     *
     * The run's pay date is moved back rather than an instruction invented,
     * because the three causes of an aged in-transit item - a file never
     * handed over, a rejection nobody reported, a statement line nobody
     * matched - all present as an instruction that is simply old. Backdating
     * the run reproduces that without inventing a payment nobody made.
     *
     * @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object}
     */
    private function ageAReleasedInstruction(string $entityId): array
    {
        $run = $this->tenant()->table('payment_runs as r')
            ->join('payment_run_instructions as i', 'i.run_id', '=', 'r.id')
            ->where('r.entity_id', $entityId)
            ->where('r.state', 'released')
            ->whereNull('i.cleared_on')
            ->orderByDesc('r.pay_on')
            ->select('r.id', 'r.pay_on')
            ->first();

        if ($run === null) {
            return $this->notHere('this entity has no released payment instruction that has not cleared');
        }

        /*
         | The CLOCK moves, not the run.
         |
         | Backdating the run was the obvious injection and the database
         | refuses it by name: "payment run … has been released and cannot be
         | changed. The money has left; editing the instruction afterwards
         | would describe a payment the bank never made." Which is right, and
         | it means an aged in-transit item is produced by time passing rather
         | than by any write at all - so time is what the game day moves.
         |
         | That also makes this the most faithful injection of the eight: the
         | condition in production is exactly "nobody looked for sixty days".
         */
        $was = \Illuminate\Support\Carbon::getTestNow();

        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse((string) $run->pay_on)->addDays(60),
        );

        return [
            'injected' => true,
            'why' => sprintf(
                'sixty days have passed since run %s was payable, and nothing has cleared',
                (string) $run->id,
            ),
            'rollback' => static function () use ($was): void {
                \Illuminate\Support\Carbon::setTestNow($was);
            },
            'watcher' => null,
        ];
    }

    /**
     * A connection as the migrator, against the tenant currently pointed at.
     *
     * The same technique the hardening suite uses: the tenant connection's
     * own host and database with the migrator's credentials, under a name of
     * its own so purging it cannot take the application's connection with it.
     */
    private function migrator(): ?Connection
    {
        try {
            $tenant = (array) config('database.connections.tenant');
            $migrator = (array) config('database.connections.migrator');

            config(['database.connections.pf242_migrator' => array_merge($tenant, [
                'username' => $migrator['username'] ?? null,
                'password' => $migrator['password'] ?? null,
            ])]);

            DB::purge('pf242_migrator');

            $connection = DB::connection('pf242_migrator');
            $connection->getPdo();

            return $connection;
        } catch (Throwable) {
            return null;
        }
    }

    private function tenant(): Connection
    {
        return DB::connection('tenant');
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function done(string $why): array
    {
        return ['injected' => true, 'why' => $why, 'rollback' => null, 'watcher' => null];
    }

    /** @return array{injected: bool, why: string, rollback: ?callable(): void, watcher: ?object} */
    private function notHere(string $why): array
    {
        return ['injected' => false, 'why' => $why, 'rollback' => null, 'watcher' => null];
    }
}
