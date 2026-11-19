<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\Operator;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use App\Tenant\Catalog\Models\FxRate;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\JournalEntry;
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

    /**
     * Where the demo entity is, and what it keeps its books in.
     *
     * Named constants because `repoint()` compares against them: a literal
     * in two places is a seeder that moves the entity on every run, or on
     * none.
     */
    public const COUNTRY = 'PS';

    public const CURRENCY = 'ILS';

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
                'country' => self::COUNTRY,
                'functional_currency' => self::CURRENCY,
                'presentation_currency' => self::CURRENCY,
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
        }

        /*
         | Keyed on the chart being absent, not on the entity being new.
         |
         | This used to live inside the branch above, so an entity that
         | existed WITHOUT a chart was never repaired - and that is a state
         | the seeder can leave behind, because creating the entity and
         | applying the chart are two statements and the second one can be
         | interrupted. The symptom is not a seeder error: it is the demo
         | seeder later refusing a posting because the entity has no
         | `vat_output` account, several screens away from the cause.
         |
         | Inside `during()`, because every ledger model refuses to be
         | queried with no entity in scope - which is the control working:
         | returning every entity's accounts here would look like data
         | rather than an error.
         */
        $hasChart = app(EntityContext::class)->during(
            $entity,
            static fn (): bool => Account::query()->where('entity_id', $entity->id)->exists(),
        );

        if ($hasChart !== true) {
            app(EntityContext::class)->during(
                $entity,
                static fn (): array => app(ChartTemplate::class)->apply((string) $entity->id, 'ifrs-generic'),
            );
        }

        app(EntityContext::class)->during($entity, function () use ($entity): void {
            $this->repoint($entity);
            $this->restampTaxRoles($entity->refresh());
            $this->currencies($entity->refresh());
        });

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
     * Re-stamp the per-jurisdiction control roles onto the entity's country.
     *
     * ## The drift this repairs
     *
     * `ChartTemplate` stamps `vat_output` and `vat_input` with the entity's
     * country **at the moment the chart is applied**, which is right: a
     * template cannot know where its user is registered. But the demo
     * entity is created once and re-seeded for months, so an entity that
     * was created in one country and later moved to another keeps control
     * roles pointing at the country it left.
     *
     * The symptom is not a seeder error. It is the demo books refusing a
     * posting with "this entity has no vat_output account for PS" - which
     * is `ControlAccountNotConfigured` doing exactly its job, several
     * screens away from the seeder that caused it.
     *
     * ## Only while the books are empty
     *
     * A posted entry was measured against a control account in a
     * jurisdiction, and PF-084's whole point is that one authority's VAT
     * must not land in another's return. So once anything is posted this
     * leaves the roles alone and says so, the same way `repoint()` does
     * about the currency: there is no smaller honest edit.
     */
    private function restampTaxRoles(Entity $entity): void
    {
        $country = (string) $entity->country;

        $stale = AccountRole::query()
            ->where('entity_id', $entity->id)
            ->whereNotNull('jurisdiction')
            ->where('jurisdiction', '!=', $country)
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $posted = JournalEntry::query()->where('entity_id', $entity->id)->exists();

        if ($posted) {
            $this->command?->warn(sprintf(
                '  %d control role(s) still name %s while the entity is in %s, and the books are not empty.',
                $stale->count(),
                (string) $stale->first()?->jurisdiction,
                $country,
            ));
            $this->command?->line(
                '  Those roles are left alone: a posted entry was measured against a control account in '.
                'a jurisdiction, and moving it would put one authority\'s VAT in another\'s return. A '.
                'fresh workspace is the way out.',
            );

            return;
        }

        foreach ($stale as $role) {
            $role->forceFill(['jurisdiction' => $country])->save();
        }

        $this->command?->line(sprintf(
            '  <fg=green>Re-stamped</> %d control role(s) onto %s.',
            $stale->count(),
            $country,
        ));
    }

    /**
     * Move an existing demo entity onto the seeder's current currency.
     *
     * ## Why this is attempted rather than assumed
     *
     * The demo entity is created once and then re-seeded for months. A
     * developer who pulls a change to the seeded currency and sees the old
     * one has no way to tell whether the change arrived - so the seeder
     * tries, and says what happened.
     *
     * ## And why it is allowed to fail
     *
     * `entities_functional_currency_immutable` refuses the change once the
     * entity has posted entries, and that refusal is correct: under IAS 21
     * changing an entity's functional currency is a re-presentation of its
     * whole history, not an edit to a settings row. Every posted amount was
     * measured in the old one, and switching the label would silently
     * reinterpret all of them.
     *
     * So a demo with data on it keeps its currency, and the message says the
     * way out is a fresh workspace rather than a smaller edit - because
     * there is no smaller edit that is honest.
     */
    private function repoint(Entity $entity): void
    {
        $wanted = self::CURRENCY;

        if ((string) $entity->functional_currency === $wanted) {
            return;
        }

        $was = (string) $entity->functional_currency;

        try {
            $entity->forceFill([
                'country' => self::COUNTRY,
                'functional_currency' => $wanted,
                'presentation_currency' => $wanted,
            ])->save();

            $this->command?->line(sprintf(
                '  <fg=green>Moved</> the demo entity from %s to %s.',
                $was,
                $wanted,
            ));
        } catch (Throwable) {
            $this->command?->newLine();
            $this->command?->warn(sprintf(
                '  The demo entity still keeps its books in %s, not %s.',
                $was,
                $wanted,
            ));
            $this->command?->line(
                '  It has posted entries, and every one of them was measured in '.$was.'. Under IAS 21 '.
                'changing that is a re-presentation of the entity\'s history, not an edit, so the '.
                'database refuses it.',
            );
            $this->command?->line(
                '  For a demo in '.$wanted.', drop the workspace and seed again: '.
                '<options=bold>php artisan tenants:discard-abandoned</> will not do it, because the '.
                'workspace is not abandoned - remove it from the operator console first.',
            );
            $this->command?->newLine();
        }
    }

    /**
     * The currencies the demo actually trades in, and a rate for each.
     *
     * ## Why three and not one
     *
     * An entity with only its functional currency enabled exercises none of
     * the FX layer: no rate is ever looked up, no translation difference is
     * ever posted, and the first time anybody sees that code run is on a
     * customer's books. The demo trades in the three currencies that are
     * actually used where it is: the shekel it keeps its books in, and the
     * dinar and the dollar that invoices arrive in.
     *
     * ## The rates are indicative and say so
     *
     * A seeded rate is not a market rate and must never be mistaken for one,
     * so the provider is named `seed` rather than left to look like a feed.
     * `RateFetcher` overwrites them from a real provider on its first run,
     * and a report that translated a figure at one of these carries the
     * provider on its face - which is how the reader can tell.
     */
    private function currencies(Entity $entity): void
    {
        $policy = app(CurrencyPolicy::class);
        $functional = (string) $entity->functional_currency;

        foreach ([$functional, 'JOD', 'USD'] as $code) {
            $policy->enable($entity, $code);
        }

        /*
         | Written in the direction the ledger asks for them.
         |
         | A rate is FROM the document's currency TO the functional one,
         | because that is the question a posting asks: what is this invoice
         | worth in the books. Storing the other direction and inverting on
         | read is how a consolidation acquires a persistent one-cent
         | difference that grows with every account.
         */
        $rates = [
            'JOD' => '5.1500',
            'USD' => '3.6500',
        ];

        foreach ($rates as $from => $rate) {
            if ($from === $functional) {
                continue;
            }

            FxRate::query()->updateOrCreate(
                [
                    'rate_set' => 'market',
                    'from_currency' => $from,
                    'to_currency' => $functional,
                    'as_of_date' => today()->toDateString(),
                ],
                ['rate' => $rate, 'provider' => 'seed'],
            );
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
