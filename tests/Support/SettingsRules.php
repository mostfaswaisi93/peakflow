<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Limits\Ceilings;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use App\Tenant\Tenancy\Services\Settings;
use App\Tenant\Tenancy\Settings\Exceptions\SettingRejected;
use App\Tenant\Tenancy\Settings\SettingDefinition;
use App\Tenant\Tenancy\Settings\SettingsRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tenant settings and the platform's ceilings - PF-074.
 *
 * ## The claim that carries the risk
 *
 * A tenant may move a risk-bearing setting towards safety as far as they
 * like, and may not move it away from safety past the operator's limit. A
 * write-off tolerance can go to zero and cannot go past the ceiling, because
 * a generous tolerance quietly absorbs real losses - and the person asking
 * for it is sometimes the person creating them.
 *
 * ## Refused, never clamped
 *
 * Silently reducing a request to the ceiling is worse than refusing it: the
 * tenant believes they set 500, the system uses 50, and the difference is
 * found when a write-off is rejected for a reason the screen does not
 * explain. So one check asserts the refusal AND that the stored value did
 * not move.
 *
 * ## The registry is checked for its own coherence
 *
 * Every ceiling a definition names has to resolve, or the setting is
 * unbounded in practice while appearing bounded in the code - the worst of
 * both, because a reviewer reading the definition would conclude it is safe.
 */
final class SettingsRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        // The registry needs no database, so it is checked anywhere.
        $results = ['the settings registry is coherent' => $this->registryIsCoherent()];

        $tenant = $this->tenant();

        if ($tenant === null) {
            return $results + ['settings (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $results + [
                'a setting resolves entity, then tenant, then default' => $this->resolutionOrderHolds(),
                'a tenant may lower a ceiling and never raise past it' => $this->ceilingsAreRefusedNotClamped(),
                'a value of the wrong type or shape is refused' => $this->typesAreEnforced(),
                'scope decides where an override may be written' => $this->scopeIsEnforced(),
                'every change is audited, and a refused one is not' => $this->changesAreAudited(),
                'the settings diff lists only the overrides' => $this->diffIsUsable(),
            ];
        } catch (Throwable $e) {
            return $results + ['settings could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function registryIsCoherent(): array
    {
        $violations = [];
        $ceilings = app(Ceilings::class);

        foreach (SettingsRegistry::all() as $key => $definition) {
            if ($definition->descriptionAr === '' || $definition->descriptionEn === '') {
                $violations[] = sprintf(
                    '"%s" has no description in one of the two languages. Whoever is about to change a '.
                    'setting needs to know what it does, in their own language',
                    $key,
                );
            }

            if (! in_array($definition->scope, ['tenant', 'entity'], true)) {
                $violations[] = sprintf('"%s" has scope "%s", which is neither', $key, $definition->scope);
            }

            if (! $definition->bearsRisk()) {
                continue;
            }

            if (! in_array($definition->ceilingDirection, ['at_most', 'at_least'], true)) {
                $violations[] = sprintf(
                    '"%s" names a ceiling without saying which direction it bounds, so nothing can tell '.
                    'whether a larger value is safer or more dangerous',
                    $key,
                );
            }

            /*
             | The ceiling has to RESOLVE.
             |
             | A definition naming a ceiling that does not exist is
             | unbounded in practice and appears bounded in the code, which
             | is the worst of both: a reviewer reading the definition would
             | conclude the setting is safe.
             */
            try {
                $value = $definition->type === 'int'
                    ? (string) $ceilings->int((string) $definition->ceilingKey)
                    : $ceilings->decimal((string) $definition->ceilingKey);

                if ($value === '') {
                    $violations[] = sprintf('"%s" names a ceiling that resolves to nothing', $key);
                }
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    '"%s" names the ceiling "%s", which does not resolve: %s',
                    $key,
                    (string) $definition->ceilingKey,
                    Str::limit($e->getMessage(), 120),
                );
            }
        }

        /*
         | An unknown key is refused, not created.
         |
         | A created key looks saved, is read by nothing, and the behaviour
         | somebody wanted never changes - a failure with no symptom at all
         | until months later.
         */
        try {
            SettingsRegistry::get('money.writeof_tolerance');

            $violations[] = 'a misspelled setting key was accepted, so a typo creates a setting that is '.
                'saved, read by nothing, and changes nothing';
        } catch (SettingRejected $e) {
            if (! str_contains($e->getMessage(), 'money.writeoff_tolerance')) {
                $violations[] = 'a misspelled key was refused without suggesting the real one, which is the '.
                    'whole value of refusing it';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function resolutionOrderHolds(): array
    {
        $settings = app(Settings::class);
        $violations = [];

        $this->clearOverrides();
        $settings->flush();

        if ($settings->get('money.writeoff_tolerance') !== '1.00') {
            $violations[] = sprintf(
                'with nothing set, the tolerance resolved to %s rather than the declared default',
                var_export($settings->get('money.writeoff_tolerance'), true),
            );
        }

        if ($settings->explain('money.writeoff_tolerance')['source'] !== 'the system default') {
            $violations[] = 'an unset setting does not report that it came from the default, so "why is this '.
                '1.00" has no answer on the screen';
        }

        // The tenant level sits between the entity and the default.
        $this->connection->table('tenant_settings')->insert([
            'id' => (string) Str::ulid(),
            'key' => 'money.writeoff_tolerance',
            'type' => 'decimal',
            'value' => '3.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settings->flush();

        if ($settings->get('money.writeoff_tolerance') !== '3.00') {
            $violations[] = 'a tenant-wide value did not apply to an entity with no override of its own. '.
                'Without that level, "every company does this except one" has to be configured once per '.
                'company and kept in step by hand';
        }

        $settings->set('money.writeoff_tolerance', '0.25');

        if ($settings->get('money.writeoff_tolerance') !== '0.25') {
            $violations[] = 'an entity override did not beat the tenant-wide value';
        }

        if ($settings->explain('money.writeoff_tolerance')['source'] !== 'this company') {
            $violations[] = 'an overridden setting does not say which level it came from';
        }

        $settings->set('money.writeoff_tolerance', null);

        if ($settings->get('money.writeoff_tolerance') !== '3.00') {
            $violations[] = 'clearing an entity override did not fall back to the tenant-wide value';
        }

        return $violations;
    }

    /** @return list<string> */
    public function ceilingsAreRefusedNotClamped(): array
    {
        $settings = app(Settings::class);
        $ceilings = app(Ceilings::class);
        $violations = [];

        $ceiling = $ceilings->decimal('ceiling.writeoff_tolerance');

        /*
         | The ceiling is a decimal STRING.
         |
         | A ceiling of 0.50 read through a float and compared against 0.50
         | is a comparison that can go either way depending on how each side
         | got there, and the side that matters is the one that admits a
         | value over the limit.
         */
        if (! is_string($ceiling)) {
            $violations[] = 'a money ceiling comes back as '.get_debug_type($ceiling).' rather than a '.
                'decimal string, so it has been through a float';
        }

        // Towards safety: always allowed.
        $settings->set('money.writeoff_tolerance', '0');

        if ($settings->get('money.writeoff_tolerance') !== '0') {
            $violations[] = 'a tenant could not lower a risk-bearing setting to zero, which is the direction '.
                'they are always allowed to move';
        }

        // Exactly at the ceiling: allowed.
        $settings->set('money.writeoff_tolerance', $ceiling);

        // One hundredth over: refused, and the stored value must not move.
        $over = (string) \Brick\Math\BigDecimal::of($ceiling)->plus('0.01');

        try {
            $settings->set('money.writeoff_tolerance', $over);

            $violations[] = sprintf(
                'a tenant raised the write-off tolerance to %s, past the platform ceiling of %s. A generous '.
                'tolerance absorbs theft one invoice at a time, and every individual write-off looks like '.
                'housekeeping',
                $over,
                $ceiling,
            );
        } catch (SettingRejected $e) {
            if (! str_contains($e->getMessage(), 'refused rather than quietly reduced')) {
                $violations[] = 'the ceiling refusal does not say that the value was not clamped, which is '.
                    'the thing the reader needs to know';
            }
        }

        if ($settings->get('money.writeoff_tolerance') !== $ceiling) {
            $violations[] = sprintf(
                'after a refused change the value is %s rather than the %s it was. A request silently '.
                'clamped leaves the tenant believing they set the number they typed',
                var_export($settings->get('money.writeoff_tolerance'), true),
                $ceiling,
            );
        }

        // And an integer ceiling behaves the same way.
        $escalations = $ceilings->int('ceiling.dunning_escalations');

        try {
            $settings->set('dunning.max_escalations', $escalations + 1);

            $violations[] = 'an integer ceiling was exceeded, so an unbounded escalation can send a customer '.
                'fourteen letters about an invoice they have disputed';
        } catch (SettingRejected) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function typesAreEnforced(): array
    {
        $settings = app(Settings::class);
        $violations = [];

        $refusals = [
            ['dunning.max_escalations', 'four', 'a word where a whole number belongs'],
            ['dunning.max_escalations', 2.7, 'a fraction where a whole number belongs, which would truncate'],
            ['money.rounding_mode', 'bankers', 'a value outside the allowed list'],
            ['dunning.enabled', 'perhaps', 'a value that is neither yes nor no'],
            /*
             | 5 where 0.05 was meant.
             |
             | A percentage typed as a fraction and a fraction typed as a
             | percentage are the two mistakes that actually happen. They
             | differ by a factor of a hundred and both look plausible.
             */
            ['banking.auto_match_ratio', '5', 'a percentage where a fraction belongs'],
        ];

        foreach ($refusals as [$key, $value, $what]) {
            try {
                $settings->set($key, $value);

                $violations[] = sprintf('%s was accepted for "%s"', $what, $key);
            } catch (SettingRejected) {
                // Correct.
            }
        }

        /*
         | And the one that must NOT be refused.
         |
         | The string "false" is TRUE to PHP, which is exactly why the type
         | is recorded and the cast happens in one place rather than at each
         | call site.
         */
        $settings->set('dunning.enabled', 'false');

        if ($settings->bool('dunning.enabled') !== false) {
            $violations[] = 'the string "false" was stored as true. PHP agrees with that and an accountant '.
                'does not, which is why the cast lives in one place';
        }

        return $violations;
    }

    /** @return list<string> */
    public function scopeIsEnforced(): array
    {
        $settings = app(Settings::class);
        $context = app(EntityContext::class);
        $violations = [];

        try {
            $settings->set('fx.fallback_window_days', 10, entityId: $this->entityId);

            $violations[] = 'a tenant-wide setting was written against one entity. One company deciding it '.
                'and the others not is the state the scope exists to prevent';
        } catch (SettingRejected) {
            // Correct.
        }

        /*
         | Reading a per-entity setting with NO entity is allowed; writing
         | one is not.
         |
         | The asymmetry is deliberate. A queued job with no entity selected
         | should get the organisation's answer rather than fail; a write
         | with no entity has no row to live in, and defaulting it to "all
         | of them" is how one company's policy becomes everybody's.
         */
        $context->forget();
        $settings->flush();

        try {
            $read = $settings->get('money.rounding_mode');

            if ($read === null) {
                $violations[] = 'reading a per-entity setting with no entity selected returned null rather '.
                    'than the organisation value or the default, so a queued job gets nothing';
            }
        } catch (Throwable $e) {
            $violations[] = 'reading a per-entity setting with no entity selected threw: '.
                Str::limit($e->getMessage(), 120);
        }

        try {
            $settings->set('money.rounding_mode', 'down');

            $violations[] = 'a per-entity setting was written with no entity selected';
        } catch (SettingRejected) {
            // Correct.
        } finally {
            $context->use($this->entityId);
            $settings->flush();
        }

        return $violations;
    }

    /** @return list<string> */
    public function changesAreAudited(): array
    {
        $settings = app(Settings::class);
        $violations = [];

        $settings->set('money.rounding_mode', 'half_even');

        $entry = $this->connection->table('audit_log')
            ->where('action', 'setting.changed')
            ->where('subject_label', 'money.rounding_mode')
            ->orderByDesc('created_at')
            ->first();

        if ($entry === null) {
            return ['a setting change left no audit entry, so a settings diff cannot be evidence of anything'];
        }

        /*
         | Before AND after.
         |
         | "The matching tolerance was 0.50 all year" is a claim somebody has
         | to be able to support, and an entry recording only the new value
         | cannot support it.
         */
        if (! str_contains((string) $entry->after_state, 'half_even')
            || ! str_contains((string) $entry->before_state, 'half_up')) {
            $violations[] = 'the audit entry does not record both the old and the new value, so a settings '.
                'diff shows what a setting IS and not what it was';
        }

        if ($entry->actor_user_id === null) {
            $violations[] = 'the audit entry does not say who changed the setting';
        }

        /*
         | A REFUSED change must leave nothing behind.
         |
         | An audit log claiming a change that did not happen is worse than
         | no log: it is evidence of the wrong thing, and it is the entry
         | somebody would rely on.
         */
        $before = $this->connection->table('audit_log')->where('action', 'setting.changed')->count();

        try {
            $settings->set('money.writeoff_tolerance', '99999');
        } catch (SettingRejected) {
            // Expected.
        }

        $after = $this->connection->table('audit_log')->where('action', 'setting.changed')->count();

        if ($after !== $before) {
            $violations[] = 'a refused change still wrote an audit entry, so the log records a change that '.
                'did not happen';
        }

        return $violations;
    }

    /** @return list<string> */
    public function diffIsUsable(): array
    {
        $settings = app(Settings::class);
        $violations = [];

        $settings->set('money.writeoff_tolerance', '0.10');
        $settings->set('fx.fallback_window_days', 7);

        $diff = collect($settings->diff());

        /*
         | Both scopes in one diff.
         |
         | The first version of `diff()` handed the entity id to every key
         | including the tenant-scoped ones, which the scope check refuses -
         | so the whole diff threw as soon as a tenant-wide override existed.
         */
        if (! $diff->contains(static fn (array $row): bool => $row['key'] === 'fx.fallback_window_days')) {
            $violations[] = 'a tenant-wide override is missing from the diff, so half the evidence pack is '.
                'absent';
        }

        if (! $diff->contains(static fn (array $row): bool => $row['key'] === 'money.writeoff_tolerance')) {
            $violations[] = 'an entity override is missing from the diff';
        }

        // Only the overrides. A diff listing all forty buries the three.
        if ($diff->contains(static fn (array $row): bool => $row['key'] === 'credit.block_on_limit_breach')) {
            $violations[] = 'the diff lists settings nobody changed, which buries the ones somebody did - '.
                'and those are the evidence';
        }

        $tolerance = $diff->firstWhere('key', 'money.writeoff_tolerance');

        if (($tolerance['default'] ?? null) !== '1.00') {
            $violations[] = 'a diff row does not carry the default it departs from, so the reader cannot see '.
                'what changed without looking the setting up';
        }

        if (($tolerance['risk_bearing'] ?? null) !== true || ($tolerance['ceiling'] ?? null) === null) {
            $violations[] = 'a risk-bearing override is not marked as such, or does not say which ceiling it '.
                'was measured against - which is the first thing an auditor asks about a tolerance';
        }

        return $violations;
    }

    private function clearOverrides(): void
    {
        $keys = array_keys(SettingsRegistry::all());

        $this->connection->table('tenant_settings')->whereIn('key', $keys)->delete();

        $this->connection->table('entity_settings')
            ->where('entity_id', $this->entityId)
            ->whereIn('key', $keys)
            ->delete();
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'STG'.random_int(100, 999),
            'legal_name' => 'Settings check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        /*
         | An authenticated user, because the audit entry's actor comes from
         | the guard.
         |
         | Without one the actor is null - which is CORRECT for a change made
         | by a console command or a seeder, and not what the screen path
         | does. The check seeds a user so it is asserting the mechanism
         | rather than the absence of one.
         */
        $role = Role::query()->where('key', 'controller')->firstOrFail();

        $user = User::create([
            'name' => 'Settings check',
            'email' => 'settings-'.Str::random(8).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        auth()->guard('web')->setUser($user);

        /*
         | Tenant-wide rows outlive the per-run entity, so a fresh entity is
         | not isolation on its own. Clearing them is what makes "with
         | nothing set, the default applies" true on the second run as well
         | as the first.
         */
        $this->clearOverrides();
        app(Settings::class)->flush();
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
