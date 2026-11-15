<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\Operator;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Services\ChartTemplate;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * A clone that can be signed into, in one command - PF-011.
 *
 * ## Why this exists
 *
 * `composer setup` migrated an empty platform database and stopped. What a
 * developer had after it was a login form, no workspace to log into, and no
 * way to find out what the credentials would have been. The first hour of
 * every clone was spent reconstructing seed data from the migrations, and
 * everybody reconstructed it slightly differently - which is how two people
 * end up disagreeing about whether something is a bug.
 *
 * So this seeds the shortest path to a screen with numbers on it: an operator
 * for the console, a tenant with its own database, an entity with a chart of
 * accounts, and a user who owns it.
 *
 * ## It refuses to run outside local and testing
 *
 * The password is a known constant. That is the point of a development seeder
 * and it is a catastrophe in production, so the environment is checked here as
 * well as being implied by the command - `db:seed --class=DevelopmentSeeder`
 * is one typo away from a production shell, and `--force` exists precisely to
 * get past the confirmation that would otherwise have stopped it.
 *
 * ## Idempotent
 *
 * Run it twice and nothing is duplicated. A seeder that only works on an empty
 * database is a seeder people stop running, and then the data it creates stops
 * matching what the code expects.
 */
final class DevelopmentSeeder extends Seeder
{
    /**
     * The one password in this repository, and it is local-only.
     *
     * Written down rather than generated, because a generated one has to be
     * printed, and a printed one is scrolled past. Everybody on a local clone
     * uses the same one and nobody has to ask.
     */
    public const PASSWORD = 'peakflow';

    public const OPERATOR_EMAIL = 'ops@peakflow.test';

    public const USER_EMAIL = 'owner@demo.test';

    public const TENANT_SLUG = 'demo';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DevelopmentSeeder sets a known password and will not run in the '.app()->environment().
                ' environment. If you reached this from a deployment script, that script is pointed at '.
                'the wrong database.',
            );
        }

        $this->operator();
        $tenant = $this->tenant();
        $this->workspace($tenant);
        $this->report();
    }

    private function operator(): void
    {
        $operator = Operator::query()->where('email', self::OPERATOR_EMAIL)->first();

        if ($operator !== null) {
            return;
        }

        Operator::query()->create([
            'name' => 'Platform Operator',
            'email' => self::OPERATOR_EMAIL,
            'password' => self::PASSWORD,
            'locale' => 'ar',
        ]);
    }

    private function tenant(): Tenant
    {
        $existing = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         | Provisioned through the real provisioner, not inserted.
         |
         | A seeder that writes the `tenants` row directly produces a tenant
         | with no database, which fails at the first query with a message
         | about a missing password - and the developer's first experience of
         | the product is a bug that only exists in their seed data.
         */
        return app(TenantProvisioner::class)->create('Demo Trading Co', 'business', self::TENANT_SLUG);
    }

    private function workspace(Tenant $tenant): void
    {
        app(TenantConnectionResolver::class)->point($tenant);

        $entity = Entity::query()->where('code', 'DEMO')->first();

        if ($entity === null) {
            $entity = Entity::query()->create([
                'code' => 'DEMO',
                'legal_name' => 'Demo Trading Co',
                'trading_name' => 'Demo',
                'country' => 'SA',
                'functional_currency' => 'SAR',
                'presentation_currency' => 'SAR',
                'jurisdiction_profiles' => ['generic'],
                'active' => true,
            ]);

            /*
             | A chart, because an entity without one cannot post.
             |
             | The failure otherwise is a first posting that fails naming one
             | unassigned control role at a time, weeks after provisioning,
             | with nothing pointing at the seeder.
             |
             | Inside `during()`, because every ledger model refuses to be
             | queried with no entity in scope - which is the control working:
             | returning every entity's accounts here would look like data
             | rather than an error.
             */
            app(EntityContext::class)->during(
                $entity,
                static fn (): array => app(ChartTemplate::class)->apply((string) $entity->id, 'ifrs-generic'),
            );
        }

        $user = User::query()->where('email', self::USER_EMAIL)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => 'Demo Owner',
                'email' => self::USER_EMAIL,
                'password' => self::PASSWORD,
                'locale' => 'ar',
                'timezone' => 'Asia/Riyadh',
                'active' => true,
            ]);
        }

        $owner = Role::query()->where('key', 'owner')->first();

        if ($owner === null) {
            return;
        }

        $assigned = UserEntityRole::query()
            ->where('user_id', $user->id)
            ->where('entity_id', $entity->id)
            ->where('role_id', $owner->id)
            ->exists();

        if (! $assigned) {
            UserEntityRole::query()->create([
                'user_id' => $user->id,
                'entity_id' => $entity->id,
                'role_id' => $owner->id,
            ]);
        }

        if ($user->last_entity_id === null) {
            $user->forceFill(['last_entity_id' => $entity->id])->save();
        }
    }

    /**
     * Print the credentials, because a seeder nobody can use is a slow seeder.
     *
     * The alternative is a line in a README that goes stale, and a developer
     * who reads the seeder source to find out what the password is - which
     * works, and takes five minutes the first forty times.
     */
    private function report(): void
    {
        try {
            $this->command?->newLine();
            $this->command?->line('  <fg=green>Ready.</> Sign in at <options=bold>/login</>:');
            $this->command?->line('    workspace  <options=bold>'.self::TENANT_SLUG.'</>');
            $this->command?->line('    email      <options=bold>'.self::USER_EMAIL.'</>');
            $this->command?->line('    password   <options=bold>'.self::PASSWORD.'</>');
            $this->command?->newLine();
            $this->command?->line('  The operator console is at <options=bold>/ops/login</>:');
            $this->command?->line('    email      <options=bold>'.self::OPERATOR_EMAIL.'</>');
            $this->command?->line('    password   <options=bold>'.self::PASSWORD.'</>');
            $this->command?->newLine();
        } catch (Throwable) {
            // Seeded from a test rather than a command. Nothing to print to.
        }
    }
}
