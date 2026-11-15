<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\SodRule;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Identity\Services\AuditChainVerifier;
use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\EntitySetting;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves the tenant database ENFORCES what it claims, behaviourally.
 *
 * {@see ArchitectureRules} reads source files and {@see WiringRules} inspects
 * the assembled application. This one runs statements against a real,
 * provisioned tenant database and checks what PostgreSQL actually does -
 * because "we wrote a trigger" and "the trigger fires" are different claims,
 * and only the second one matters.
 *
 * ## Everything runs inside a transaction that is rolled back
 *
 * Each probe is wrapped in its own SAVEPOINT, because a failed statement
 * poisons a PostgreSQL transaction and the refusals here are deliberate
 * failures. The outer transaction is rolled back regardless, so these checks
 * can run against a working tenant without leaving anything behind.
 *
 * Skips cleanly when no tenant is provisioned - a fresh clone has none, and
 * reporting a false failure would train people to ignore the output.
 */
final class TenantEnforcementRules
{
    private ?Connection $connection = null;

    private ?string $entityA = null;

    private ?string $entityB = null;

    /**
     * Checked against EVERY provisioned tenant, not the first one.
     *
     * A tenant created with `CREATE DATABASE` and a tenant linked to a
     * pre-created managed database arrive at the same guarantees by different
     * routes, and a tenant that drifted - a grant made during an incident and
     * never reverted - is invisible if only one is sampled. Violations are
     * reported with the slug that produced them.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            return ['tenant enforcement (no tenant provisioned - skipped)' => []];
        }

        $results = [
            'the application role has no DDL' => [],
            'the application role can read and write rows' => [],
            'audit_log refuses UPDATE and DELETE' => [],
            'row-level security scopes reads to the current entity' => [],
            'row-level security refuses writes into another entity' => [],
            'the seeded baseline is present' => [],
            'the Eloquent scope refuses an unscoped query' => [],
            'the Eloquent scope fills and freezes entity_id' => [],
            'permissions resolve per entity' => [],
            'every entity-scoped table has a forced RLS policy' => [],
            'the audit chain verifies, and a written record reads back identically' => [],
            'segregation of duties reads the audit trail' => [],
            'the unoverridable rules are the right two' => [],
            'every duty pair is refused in both directions' => [],
        ];

        foreach ($tenants as $tenant) {
            foreach ($this->check($tenant) as $rule => $violations) {
                foreach ($violations as $violation) {
                    $results[$rule][] = sprintf('[%s] %s', $tenant->slug, $violation);
                }
            }
        }

        return $results;
    }

    /** @return array<string, list<string>> */
    private function check(Tenant $tenant): array
    {
        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');

        $this->connection->beginTransaction();

        try {
            $this->seedProbeEntities();

            return [
                'the application role has no DDL' => $this->applicationRoleHasNoDdl(),
                'the application role can read and write rows' => $this->applicationRoleCanUseData(),
                'audit_log refuses UPDATE and DELETE' => $this->auditLogIsAppendOnly(),
                'row-level security scopes reads to the current entity' => $this->rlsScopesReads(),
                'row-level security refuses writes into another entity' => $this->rlsRefusesForeignWrites(),
                'the seeded baseline is present' => $this->baselineIsSeeded(),
                'the Eloquent scope refuses an unscoped query' => $this->eloquentScopeRefusesUnscoped(),
                'the Eloquent scope fills and freezes entity_id' => $this->eloquentScopeFillsAndFreezes(),
                'permissions resolve per entity' => $this->permissionsResolvePerEntity(),
                'every entity-scoped table has a forced RLS policy' => $this->everyScopedTableHasAPolicy(),
                'the audit chain verifies, and a written record reads back identically' => $this->auditChainVerifies(),
                'segregation of duties reads the audit trail' => $this->sodReadsTheAuditTrail(),
                'the unoverridable rules are the right two' => $this->unoverridableRulesAreCorrect(),
                'every duty pair is refused in both directions' => $this->everyPairBothDirections(),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function applicationRoleHasNoDdl(): array
    {
        return array_values(array_filter([
            $this->refuses('CREATE TABLE', 'CREATE TABLE peakflow_ddl_probe (id int)'),
            $this->refuses('ALTER TABLE', 'ALTER TABLE users ADD COLUMN peakflow_probe int'),
            $this->refuses('DROP TABLE', 'DROP TABLE users'),
        ]));
    }

    /** @return list<string> */
    public function applicationRoleCanUseData(): array
    {
        return array_values(array_filter([
            $this->allows('SELECT', 'SELECT count(*) FROM permissions'),
            $this->allows('INSERT', sprintf(
                "INSERT INTO entity_settings (id, entity_id, key, type, value, created_at, updated_at) VALUES ('%s', '%s', 'write-probe', 'string', 'x', now(), now())",
                Str::ulid(),
                $this->entityA,
            ), $this->entityA),
        ]));
    }

    /**
     * Append-only is claimed on two independent layers. Both are checked
     * SEPARATELY, because a check that merely sees a refusal cannot tell which
     * layer produced it - and an earlier version of this file was satisfied by
     * the trigger while the privilege revoke silently did nothing at all.
     *
     * @return list<string>
     */
    public function auditLogIsAppendOnly(): array
    {
        $violations = [];

        // Layer one: the application role must not hold the privilege. Asked
        // of the catalogue directly, so it is true whether or not a statement
        // happens to match a row.
        foreach (['UPDATE', 'DELETE'] as $privilege) {
            $granted = (bool) $this->connection->selectOne(
                'SELECT has_table_privilege(current_user, ?, ?) AS granted',
                ['audit_log', $privilege],
            )->granted;

            if ($granted) {
                $violations[] = sprintf(
                    'the application role still holds %s on audit_log; TenantProvisioner::harden() did not revoke it',
                    $privilege,
                );
            }
        }

        // And on every partition, because a privilege on a partitioned table
        // does not describe the partitions underneath it.
        $writable = $this->connection->select(<<<'SQL'
            SELECT child.relname
            FROM pg_inherits i
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_class child ON child.oid = i.inhrelid
            WHERE parent.relname = 'audit_log'
              AND (has_table_privilege(current_user, child.oid, 'UPDATE')
                OR has_table_privilege(current_user, child.oid, 'DELETE'))
        SQL);

        if ($writable !== []) {
            $violations[] = sprintf(
                '%d audit_log partition(s) are still UPDATE/DELETE-able, starting with %s',
                count($writable),
                $writable[0]->relname,
            );
        }

        // Layer two: the trigger refuses even a role that DOES hold the
        // privilege. Checked as the migrator, which owns the table - otherwise
        // the revoke above would mask it and the trigger would be untested.
        $violations = array_merge($violations, $this->triggerRefusesPrivilegedMutation());

        // Appending must still work, or the log is not a log.
        $append = $this->allows('INSERT into audit_log', sprintf(
            "INSERT INTO audit_log (id, category, action, canonical_hash, previous_hash, chain_index, created_at) VALUES ('%s', 'probe', 'probe', '%s', '%s', 1, now())",
            Str::ulid(),
            str_repeat('a', 64),
            str_repeat('0', 64),
        ));

        if ($append !== null) {
            $violations[] = $append;
        }

        return $violations;
    }

    /**
     * Prove the trigger, not the privilege, by using a role that has the
     * privilege: the migrator owns audit_log and can UPDATE it as far as
     * PostgreSQL's privilege system is concerned. If the statement still
     * fails, the trigger is what failed it.
     *
     * @return list<string>
     */
    private function triggerRefusesPrivilegedMutation(): array
    {
        $tenant = (array) config('database.connections.tenant');
        $migrator = (array) config('database.connections.migrator');

        config(['database.connections.tenant_enforcement_probe' => array_merge($tenant, [
            'username' => $migrator['username'],
            'password' => $migrator['password'],
        ])]);

        DB::purge('tenant_enforcement_probe');

        $probe = DB::connection('tenant_enforcement_probe');
        $id = (string) Str::ulid();

        // Its own transaction, rolled back: the row must not survive, and a
        // trigger that refuses DELETE means it cannot be cleaned up any other
        // way short of truncating the audit log.
        $probe->beginTransaction();

        try {
            $probe->insert(sprintf(
                "INSERT INTO audit_log (id, category, action, canonical_hash, previous_hash, chain_index, created_at) VALUES ('%s', 'probe', 'probe', '%s', '%s', 1, now())",
                $id,
                str_repeat('b', 64),
                str_repeat('0', 64),
            ));

            $violations = [];

            foreach ([
                'UPDATE' => sprintf("UPDATE audit_log SET action = 'tampered' WHERE id = '%s'", $id),
                'DELETE' => sprintf("DELETE FROM audit_log WHERE id = '%s'", $id),
            ] as $label => $sql) {
                $savepoint = 'trigger_probe_'.bin2hex(random_bytes(4));
                $probe->statement('SAVEPOINT '.$savepoint);

                try {
                    $probe->statement($sql);
                    $violations[] = sprintf(
                        'the audit_log trigger did NOT refuse %s from a role holding the privilege; append-only rests on the revoke alone',
                        $label,
                    );
                } catch (Throwable) {
                    // Refused, which is the point.
                }

                $probe->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }

            return $violations;
        } catch (Throwable $e) {
            return [sprintf('could not probe the audit_log trigger as the migrator: %s', $e->getMessage())];
        } finally {
            $probe->rollBack();
            DB::purge('tenant_enforcement_probe');
        }
    }

    /** @return list<string> */
    public function rlsScopesReads(): array
    {
        $violations = [];

        foreach ([['A', $this->entityA, 'from-A'], ['B', $this->entityB, 'from-B']] as [$label, $entity, $expected]) {
            $this->setEntity($entity);

            $count = (int) $this->connection->selectOne('SELECT count(*) AS n FROM entity_settings')->n;

            if ($count !== 1) {
                $violations[] = sprintf(
                    'entity %s sees %d entity_settings rows, expected exactly its own 1',
                    $label,
                    $count,
                );
            }

            $value = $this->connection->selectOne('SELECT value FROM entity_settings LIMIT 1')->value ?? null;

            if ($value !== $expected) {
                $violations[] = sprintf('entity %s read "%s", expected "%s"', $label, (string) $value, $expected);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function rlsRefusesForeignWrites(): array
    {
        $this->setEntity($this->entityA);

        $violation = $this->refuses('INSERT into another entity', sprintf(
            "INSERT INTO entity_settings (id, entity_id, key, type, created_at, updated_at) VALUES ('%s', '%s', 'smuggled', 'string', now(), now())",
            Str::ulid(),
            $this->entityB,
        ));

        return $violation === null ? [] : [$violation];
    }

    /** @return list<string> */
    public function baselineIsSeeded(): array
    {
        $violations = [];

        $expected = [
            'permissions' => 40,
            'roles' => 6,
            'sod_rules' => 9,
        ];

        foreach ($expected as $table => $atLeast) {
            $count = (int) $this->connection->selectOne("SELECT count(*) AS n FROM {$table}")->n;

            if ($count < $atLeast) {
                $violations[] = sprintf('%s has %d rows, expected at least %d', $table, $count, $atLeast);
            }
        }

        // The one pair that may never be overridden, whatever the size of the
        // business: whoever changes a beneficiary account must not release a
        // payment to it.
        $overridable = $this->connection->selectOne(
            "SELECT overridable FROM sod_rules WHERE key = 'beneficiary_then_release'",
        );

        if ($overridable === null) {
            $violations[] = 'the beneficiary-change / payment-release SoD rule is not seeded';
        } elseif ($overridable->overridable) {
            $violations[] = 'the beneficiary-change / payment-release SoD rule is marked overridable; it must never be';
        }

        return $violations;
    }

    /** @return list<Tenant> */
    /**
     * A query with no entity established must be REFUSED, not unfiltered.
     *
     * This is the single most consequential line in the scoping design. The
     * obvious implementation skips the constraint when nothing is set, and
     * that quietly returns every company's rows - a trial balance combining
     * four legal entities, which looks like data rather than an error.
     *
     * @return list<string>
     */
    public function eloquentScopeRefusesUnscoped(): array
    {
        $context = app(EntityContext::class);
        $context->forget();

        try {
            EntitySetting::query()->count();

            return ['an unscoped query on EntitySetting returned instead of refusing; it would have crossed entities'];
        } catch (EntityNotInScope) {
            return [];
        } finally {
            $context->use($this->entityA);
        }
    }

    /** @return list<string> */
    public function eloquentScopeFillsAndFreezes(): array
    {
        $context = app(EntityContext::class);
        $violations = [];

        $context->use($this->entityA);

        $setting = EntitySetting::create([
            'key' => 'enforcement.probe.'.bin2hex(random_bytes(4)),
            'type' => 'string',
            'value' => 'filled-from-context',
        ]);

        if ($setting->entity_id !== $this->entityA) {
            $violations[] = 'entity_id was not filled from the entity context on create';
        }

        // Scoped reads see exactly one entity's rows, and dropping the
        // Eloquent scope does NOT reach the other entity's, because the
        // database is still enforcing it.
        foreach ([$this->entityA, $this->entityB] as $entity) {
            $context->use($entity);

            $foreign = EntitySetting::query()->where('entity_id', '!=', $entity)->count();

            if ($foreign !== 0) {
                $violations[] = sprintf('a scoped read returned %d row(s) from another entity', $foreign);
            }

            $unscoped = EntitySetting::withoutEntityScope()->where('entity_id', '!=', $entity)->count();

            if ($unscoped !== 0) {
                $violations[] = sprintf(
                    'withoutEntityScope() reached %d row(s) in another entity; row-level security is not holding',
                    $unscoped,
                );
            }
        }

        // Moving a posted row between legal entities is a reversal and a new
        // document, never an UPDATE.
        $context->use($this->entityA);

        try {
            $setting->entity_id = $this->entityB;
            $setting->save();

            $violations[] = 'entity_id was changed on an existing row; that is a reversal plus a new document, not an edit';
        } catch (\DomainException) {
            // Refused, which is the point.
        }

        return $violations;
    }

    /**
     * A role grants in ONE entity. Holding one in a parent company grants
     * nothing in its subsidiary.
     *
     * @return list<string>
     */
    public function permissionsResolvePerEntity(): array
    {
        $role = Role::query()->where('key', 'accountant')->first();

        if ($role === null) {
            return ['the seeded accountant role is missing, so permission resolution cannot be checked'];
        }

        $permission = (string) $role->permissions()->orderBy('key')->value('key');

        if ($permission === '') {
            return ['the accountant role holds no permissions'];
        }

        $user = User::create([
            'name' => 'Enforcement Probe',
            'email' => 'enforcement-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityA,
            'role_id' => $role->getKey(),
        ]);

        $violations = [];

        if (! $user->holds($permission, $this->entityA)) {
            $violations[] = sprintf('a user with the accountant role does not hold %s in that entity', $permission);
        }

        if ($user->permissionsIn($this->entityB)->isNotEmpty()) {
            $violations[] = 'a user holds permissions in an entity they have no role in';
        }

        // The gate must answer the same way, scoped to the acting entity.
        app(EntityContext::class)->use($this->entityA);

        if (! Gate::forUser($user)->allows($permission)) {
            $violations[] = sprintf('Gate denied %s while acting in the entity that grants it', $permission);
        }

        app(EntityContext::class)->use($this->entityB);
        $user->forgetResolvedPermissions();

        if (Gate::forUser($user)->allows($permission)) {
            $violations[] = sprintf('Gate allowed %s while acting in an entity that grants nothing', $permission);
        }

        // A deactivated user holds nothing, whatever their roles say - checked
        // here rather than at login, because a user deactivated mid-session
        // must stop being able to act immediately.
        app(EntityContext::class)->use($this->entityA);
        $user->forceFill(['active' => false])->saveQuietly();
        $user->forgetResolvedPermissions();

        if ($user->holds($permission, $this->entityA)) {
            $violations[] = 'a deactivated user still holds permissions';
        }

        return $violations;
    }

    /**
     * The live-database half of PF-005's requirement.
     *
     * The architecture rule reads migration source and can only check that the
     * words are present. This asks the catalogue: every table that has an
     * `entity_id` column must have RLS enabled, FORCED, and a policy on it.
     *
     * `FORCE` is the part worth asking about separately. Plain `ENABLE` exempts
     * the table's OWNER from its own policies, and in a tenant database the
     * owner is the migrator - so a table that is merely enabled is scoped for
     * the application and wide open to anything running as the migrator,
     * including a console command someone writes next year.
     *
     * @return list<string>
     */
    public function everyScopedTableHasAPolicy(): array
    {
        /*
         | A table opts out by declaring `peakflow:tenant-scoped`, and a
         | partition inherits its parent's declaration - both because access
         | through the parent applies the parent's policy, and because opting
         | a table out 25 times would be 25 chances to miss one.
         |
         | The opt-out is a comment in the database rather than a list here, so
         | the reason travels with the table and shows up in \dt+ for whoever
         | is holding the incident.
         */
        $rows = $this->connection->select(<<<'SQL'
            SELECT c.relname,
                   c.relrowsecurity AS enabled,
                   c.relforcerowsecurity AS forced,
                   (SELECT count(*) FROM pg_policies p
                      WHERE p.schemaname = 'public' AND p.tablename = c.relname) AS policies
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN information_schema.columns col
              ON col.table_schema = 'public'
             AND col.table_name = c.relname
             AND col.column_name = 'entity_id'
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND coalesce(obj_description(c.oid, 'pg_class'), '') NOT LIKE '%peakflow:tenant-scoped%'
              AND coalesce((
                    SELECT obj_description(parent.oid, 'pg_class')
                    FROM pg_inherits i
                    JOIN pg_class parent ON parent.oid = i.inhparent
                    WHERE i.inhrelid = c.oid
                  ), '') NOT LIKE '%peakflow:tenant-scoped%'
            ORDER BY c.relname
        SQL);

        $violations = [];

        foreach ($rows as $row) {
            if (! $row->enabled) {
                $violations[] = sprintf('%s has an entity_id column but row-level security is not enabled', $row->relname);

                continue;
            }

            if (! $row->forced) {
                $violations[] = sprintf(
                    '%s has row-level security enabled but not FORCED, so the table owner bypasses its own policy',
                    $row->relname,
                );
            }

            if ((int) $row->policies === 0) {
                $violations[] = sprintf('%s has row-level security enabled but no policy, so it returns nothing', $row->relname);
            }
        }

        if ($rows === []) {
            $violations[] = 'no entity-scoped tables were found at all, which means this check is not checking anything';
        }

        return $violations;
    }

    /**
     * The chain must verify, AND a record written now must still hash the same
     * once PostgreSQL has had it.
     *
     * The second half is the one that found a real bug. The writer hashed a
     * PHP-formatted timestamp and a `json_encode` string; `timestamptz` renders
     * in the server's own format and `jsonb` reorders keys by design, so
     * nothing the writer wrote could ever be verified. The writer and verifier
     * also held separate copies of the field list, which drifted immediately.
     *
     * Both now go through AuditRecordProjection, and this check fails if they
     * ever stop agreeing - it writes a record, reads it back through the same
     * connection and recomputes the hash from what the database returned.
     *
     * @return list<string>
     */
    public function auditChainVerifies(): array
    {
        $violations = [];

        $before = app(AuditChainVerifier::class)->verify();

        if (! $before->isIntact) {
            $violations[] = 'the existing audit chain does not verify: '.$before->summary();
        }

        // Written inside the enclosing transaction, so it is rolled back with
        // everything else. Verified on the same connection, because another
        // one cannot see an uncommitted row.
        app(AuditLog::class)->record(
            category: 'enforcement',
            action: 'enforcement.projection.round_trip',
            subjectType: 'EntityProbe',
            subjectId: $this->entityA,
            subjectLabel: 'round-trip probe',
            before: ['b' => 'before', 'nested' => ['z' => 1, 'a' => 2]],
            after: ['a' => 'after', 'nested' => ['z' => 3, 'a' => 4]],
            reason: 'proves the written hash survives a database round-trip',
            entityId: $this->entityA,
        );

        $after = app(AuditChainVerifier::class)->verify();

        if (! $after->isIntact) {
            $violations[] = 'a record written in this transaction does not verify when read back: '.
                $after->summary().
                ' - the writer and the verifier have stopped agreeing about what is hashed';
        }

        if ($after->recordsChecked !== $before->recordsChecked + 1) {
            $violations[] = sprintf(
                'the chain grew by %d records when exactly 1 was written',
                $after->recordsChecked - $before->recordsChecked,
            );
        }

        return $violations;
    }

    /**
     * The engine must find a conflict that exists only in the audit trail,
     * and must find it across DIFFERENT documents when the rule is
     * party-scoped.
     *
     * `wouldRefuse()` is used rather than `assert()` on purpose: a real
     * refusal is written out-of-band so it survives the rollback it causes,
     * which is correct behaviour and would mean this check appended rows to
     * the audit log every time it ran. The prior duty IS written through
     * `record()`, which joins this transaction and is rolled back with it.
     *
     * @return list<string>
     */
    public function sodReadsTheAuditTrail(): array
    {
        $engine = app(SodEngine::class);
        $audit = app(AuditLog::class);

        $actor = $this->probeUser('sod-probe');

        if ($actor === null) {
            return ['could not create a probe user, so SoD could not be checked'];
        }

        $violations = [];
        $payment = (string) Str::ulid();
        $supplier = (string) Str::ulid();

        app(EntityContext::class)->use($this->entityA);

        // Nothing has happened yet, so nothing conflicts.
        if ($engine->wouldRefuse('payment.release', 'Payment', $payment, $supplier, null, $actor) !== null) {
            $violations[] = 'a duty was refused before any conflicting duty had been performed';
        }

        // The same person prepares it. Object scope, and not overridable.
        $audit->record(
            category: 'payment',
            action: 'payment.prepare',
            subjectType: 'Payment',
            subjectId: $payment,
            subjectLabel: 'enforcement probe payment',
            partyId: $supplier,
            entityId: $this->entityA,
            actor: $actor,
        );

        $refusedBy = $engine->wouldRefuse('payment.release', 'Payment', $payment, $supplier, null, $actor);

        if ($refusedBy?->key !== 'prepare_then_release') {
            $violations[] = sprintf(
                'the preparer of a payment was not refused its release; got %s',
                $refusedBy?->key ?? 'no refusal at all',
            );
        }

        // A different person is not blocked by what the first one did.
        $other = $this->probeUser('sod-probe-other');

        if ($other !== null && $engine->wouldRefuse('payment.release', 'Payment', $payment, $supplier, null, $other) !== null) {
            $violations[] = 'a second person was refused a release they had no part in preparing';
        }

        /*
         | The party scope, which is the one that catches what object scope
         | cannot: changing a supplier's bank details and releasing a payment
         | are two duties on two DIFFERENT documents, and the conflict exists
         | only in the party they share.
         */
        $otherPayment = (string) Str::ulid();

        $audit->record(
            category: 'party',
            action: 'supplier.bank_details.update',
            subjectType: 'Party',
            subjectId: $supplier,
            subjectLabel: 'enforcement probe supplier',
            partyId: $supplier,
            entityId: $this->entityA,
            actor: $actor,
        );

        $crossDocument = $engine->wouldRefuse('payment.release', 'Payment', $otherPayment, $supplier, null, $actor);

        if ($crossDocument === null) {
            $violations[] = 'whoever changed a supplier\'s bank details was allowed to release a payment to that '.
                'supplier on a different document; the party-scoped rule is not matching';
        }

        // A party-scoped rule cannot be evaluated without a party, and
        // skipping it silently would disable the control.
        try {
            $engine->wouldRefuse('payment.release', 'Payment', $payment, null, null, $actor);

            $violations[] = 'a party-scoped rule was evaluated with no party id instead of refusing to guess';
        } catch (\LogicException) {
            // Correct.
        }

        return $violations;
    }

    /**
     * The two rules that may never be overridden, checked by name.
     *
     * Both describe the same fraud from different angles: arranging where
     * money goes and then sending it. An override mechanism for either would
     * be the first thing an insider reached for, so the engine refuses to
     * create one - and this check exists because marking them overridable
     * would be a one-word change in a seeder with no other symptom.
     *
     * @return list<string>
     */
    public function unoverridableRulesAreCorrect(): array
    {
        $expected = ['beneficiary_then_release', 'prepare_then_release'];

        $actual = SodRule::query()
            ->where('overridable', false)
            ->orderBy('key')
            ->pluck('key')
            ->all();

        if ($actual === $expected) {
            return [];
        }

        $violations = [];

        foreach (array_diff($expected, $actual) as $missing) {
            $violations[] = sprintf('"%s" must never be overridable, but it is', $missing);
        }

        foreach (array_diff($actual, $expected) as $unexpected) {
            $violations[] = sprintf(
                '"%s" is marked non-overridable; only %s should be, so either the seeder or this check is wrong',
                $unexpected,
                implode(' and ', $expected),
            );
        }

        return $violations;
    }

    /**
     * Every seeded pair, in BOTH directions, plus the case where the two acts
     * are performed by different people.
     *
     * Both directions matter and are easy to get wrong. A rule is stored as
     * duty_a then duty_b, and an implementation that only ever compares the
     * attempted duty against duty_a would enforce half of every rule while
     * appearing to work: "created then approved" would be caught and
     * "approved then created" would sail through. The engine resolves the
     * pair with `conflictingDuty()`, which is direction-agnostic, and this is
     * what holds it to that.
     *
     * The different-users case is the control's whole purpose: if it refuses
     * two people as readily as one, it is not segregating duties, it is just
     * blocking work.
     *
     * @return list<string>
     */
    public function everyPairBothDirections(): array
    {
        $engine = app(SodEngine::class);
        $audit = app(AuditLog::class);

        $first = $this->probeUser('pairs-first');
        $second = $this->probeUser('pairs-second');

        if ($first === null || $second === null) {
            return ['could not create probe users, so the duty pairs could not be checked'];
        }

        app(EntityContext::class)->use($this->entityA);

        $rules = SodRule::query()->where('active', true)->orderBy('key')->get();

        if ($rules->isEmpty()) {
            return ['no active SoD rules are seeded, so this check is not checking anything'];
        }

        $violations = [];

        foreach ($rules as $rule) {
            foreach ([[$rule->duty_a, $rule->duty_b], [$rule->duty_b, $rule->duty_a]] as [$performed, $attempted]) {
                // A fresh subject per direction, so one direction's history
                // cannot satisfy the other's check.
                $subjectId = (string) Str::ulid();
                $keys = [
                    'subjectType' => 'PairProbe',
                    'subjectId' => $subjectId,
                    'partyId' => (string) Str::ulid(),
                    'periodId' => (string) Str::ulid(),
                ];

                $audit->record(
                    category: 'pair-probe',
                    action: $performed,
                    subjectType: $keys['subjectType'],
                    subjectId: $keys['subjectId'],
                    subjectLabel: 'duty pair probe',
                    entityId: $this->entityA,
                    partyId: $keys['partyId'],
                    periodId: $keys['periodId'],
                    actor: $first,
                );

                $refusedSame = $engine->wouldRefuse(
                    $attempted,
                    $keys['subjectType'],
                    $keys['subjectId'],
                    $keys['partyId'],
                    $keys['periodId'],
                    $first,
                );

                if ($refusedSame === null) {
                    $violations[] = sprintf(
                        '%s: performing "%s" then attempting "%s" was NOT refused for the same user',
                        $rule->key,
                        $performed,
                        $attempted,
                    );
                }

                $refusedOther = $engine->wouldRefuse(
                    $attempted,
                    $keys['subjectType'],
                    $keys['subjectId'],
                    $keys['partyId'],
                    $keys['periodId'],
                    $second,
                );

                if ($refusedOther !== null) {
                    $violations[] = sprintf(
                        '%s: a DIFFERENT user was refused "%s" after %s performed "%s"; the rule is blocking work '.
                        'rather than separating duties',
                        $rule->key,
                        $attempted,
                        $first->name,
                        $performed,
                    );
                }
            }
        }

        return $violations;
    }

    private function probeUser(string $handle): ?User
    {
        $role = Role::query()->where('key', 'controller')->first();

        if ($role === null) {
            return null;
        }

        $user = User::create([
            'name' => 'Enforcement '.$handle,
            'email' => $handle.'-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityA,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }

    private function tenants(): array
    {
        try {
            return Tenant::query()
                ->with('connection')
                ->whereHas('connection')
                ->orderBy('slug')
                ->get()
                ->all();
        } catch (Throwable) {
            // No platform database yet. A fresh clone is not a failure.
            return [];
        }
    }

    private function seedProbeEntities(): void
    {
        $this->entityA = (string) Str::ulid();
        $this->entityB = (string) Str::ulid();

        foreach ([[$this->entityA, 'PRBA', 'from-A'], [$this->entityB, 'PRBB', 'from-B']] as [$id, $code, $value]) {
            $this->connection->insert(sprintf(
                "INSERT INTO entities (id, code, legal_name, country, functional_currency, created_at, updated_at) VALUES ('%s', '%s', '%s Probe', 'SA', 'SAR', now(), now())",
                $id,
                $code.random_int(100, 999),
                $code,
            ));

            $this->setEntity($id);

            $this->connection->insert(sprintf(
                "INSERT INTO entity_settings (id, entity_id, key, type, value, created_at, updated_at) VALUES ('%s', '%s', 'probe', 'string', '%s', now(), now())",
                Str::ulid(),
                $id,
                $value,
            ));
        }
    }

    private function setEntity(?string $entityId): void
    {
        $variable = (string) config('tenancy.entity_scope.session_variable', 'peakflow.entity_id');

        $this->connection->statement(sprintf(
            'SET LOCAL %s = %s',
            $variable,
            $this->connection->getPdo()->quote((string) $entityId),
        ));
    }

    /**
     * Run a statement that MUST fail, inside a savepoint.
     *
     * The savepoint is what makes a deliberate failure survivable: in
     * PostgreSQL a failed statement aborts the whole transaction, so without
     * one the first refusal would poison every later check.
     */
    private function refuses(string $label, string $sql): ?string
    {
        $savepoint = 'probe_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $this->connection->statement($sql);
        } catch (Throwable) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        }

        $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

        return sprintf('%s was NOT refused for the application role', $label);
    }

    private function allows(string $label, string $sql, ?string $entity = null): ?string
    {
        $savepoint = 'probe_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            if ($entity !== null) {
                $this->setEntity($entity);
            }

            $this->connection->statement($sql);
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return sprintf('%s was refused but should be allowed: %s', $label, $e->getMessage());
        }
    }
}
