<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Limits\Ceilings;
use App\Tenant\Tenancy\Settings\Exceptions\SettingRejected;
use App\Tenant\Tenancy\Settings\SettingDefinition;
use App\Tenant\Tenancy\Settings\SettingsRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading and writing settings - PF-074.
 *
 * ## Resolution: entity, then tenant, then the declared default
 *
 * Three levels and no more. A fourth - a plan-level default, say - sounds
 * harmless and turns "why is this setting 30" into a four-way search, which
 * is the question a settings screen exists to answer in one glance.
 *
 * `explain()` returns which level supplied the value, because that is the
 * question and a bare value cannot answer it.
 *
 * ## Resolved once per request
 *
 * The service is request-scoped and caches what it reads. A setting read in
 * a loop over five hundred invoice lines is one query, not five hundred - and
 * more importantly it is the SAME value for every line, which a per-read
 * lookup cannot promise once somebody can change a setting mid-request.
 *
 * ## A ceiling is refused, never clamped
 *
 * Clamping leaves the tenant believing they set 500 while the system uses 50,
 * and the difference surfaces when a write-off is rejected for a reason the
 * screen does not explain. The refusal names the limit and says which
 * direction is still open to them.
 *
 * ## Every change is audited
 *
 * With the before and after value, because a settings diff is part of the
 * compliance evidence pack: "the matching tolerance was 0.50 all year" is a
 * claim somebody has to be able to support, and an audit entry per change is
 * what supports it.
 */
final class Settings
{
    /** @var array<string, string|int|bool|array<mixed>|null> resolved values, per request */
    private array $resolved = [];

    /** @var array<string, string>|null tenant-level raw values, read once */
    private ?array $tenantValues = null;

    /** @var array<string, array<string, string>> entity id => key => raw value */
    private array $entityValues = [];

    public function __construct(
        private readonly EntityContext $entities,
        private readonly Ceilings $ceilings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The effective value.
     *
     * @throws SettingRejected if the key is not declared
     */
    public function get(string $key, ?string $entityId = null): string|int|bool|array|null
    {
        $definition = SettingsRegistry::get($key);
        $entityId = $this->entityFor($definition, $entityId);
        $cacheKey = $key.'|'.($entityId ?? '-');

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        return $this->resolved[$cacheKey] = $definition->cast($this->raw($definition, $entityId));
    }

    /** The value as a decimal string, for a money or ratio setting. */
    public function decimal(string $key, ?string $entityId = null): string
    {
        $value = $this->get($key, $entityId);

        return is_scalar($value) ? (string) $value : '0';
    }

    public function int(string $key, ?string $entityId = null): int
    {
        $value = $this->get($key, $entityId);

        return is_scalar($value) ? (int) $value : 0;
    }

    public function bool(string $key, ?string $entityId = null): bool
    {
        return (bool) $this->get($key, $entityId);
    }

    /**
     * Where the effective value came from.
     *
     * @return array{
     *     key: string, value: string|int|bool|array<mixed>|null, source: string,
     *     default: string|int|bool|null, scope: string, description: string,
     *     ceiling: ?string, overridden: bool,
     * }
     */
    public function explain(string $key, ?string $entityId = null, string $locale = 'en'): array
    {
        $definition = SettingsRegistry::get($key);
        $entityId = $this->entityFor($definition, $entityId);

        $source = match (true) {
            $entityId !== null && isset($this->entityRows($entityId)[$key]) => 'this company',
            isset($this->tenantRows()[$key]) => 'the organisation',
            default => 'the system default',
        };

        return [
            'key' => $key,
            'value' => $this->get($key, $entityId),
            'source' => $source,
            'default' => $definition->default,
            'scope' => $definition->scope,
            'description' => $definition->description($locale),
            'ceiling' => $definition->ceilingKey === null
                ? null
                : $this->ceilingValue($definition),
            'overridden' => $source !== 'the system default',
        ];
    }

    /**
     * Set a value, or clear it with null.
     *
     * @throws SettingRejected
     */
    public function set(string $key, mixed $value, ?string $entityId = null): void
    {
        $definition = SettingsRegistry::get($key);
        $entityId = $this->entityFor($definition, $entityId, forWriting: true);

        $normalised = $definition->normalise($value);

        if ($normalised !== null) {
            $this->assertWithinCeiling($definition, $normalised);
        }

        $before = $this->raw($definition, $entityId);

        DB::connection('tenant')->transaction(function () use ($definition, $normalised, $entityId, $before): void {
            $definition->scope === 'entity'
                ? $this->writeEntityRow($definition, $normalised, (string) $entityId)
                : $this->writeTenantRow($definition, $normalised);

            /*
             | Inside the transaction, so a refused write leaves no audit
             | entry claiming it happened - and a successful one cannot be
             | committed without its record.
             |
             | The before AND after value, because a settings diff is part of
             | the compliance evidence pack: "the matching tolerance was 0.50
             | all year" is a claim somebody has to support.
             */
            $this->audit->record(
                category: 'settings',
                action: 'setting.changed',
                subjectType: 'setting',
                /*
                 | The key goes in the LABEL, not in `subject_id`.
                 |
                 | `audit_log.subject_id` is char(26) - it holds a ULID. A
                 | setting key is not one, and `banking.auto_match_day_window`
                 | is 29 characters, so writing it there fails with "value
                 | too long for type character(26)" at the moment somebody
                 | changes that particular setting. Shorter keys worked,
                 | which is how it would have reached production.
                 |
                 | A setting has no row id to use either: clearing an
                 | override deletes the row. So the key is the label, and
                 | the history of one setting is
                 | `subject_type = 'setting' AND subject_label = <key>`.
                 */
                subjectLabel: $definition->key,
                before: ['value' => $before],
                after: ['value' => $normalised],
                reason: $normalised === null
                    ? 'the override was removed, so the setting falls back to '.
                      ($definition->scope === 'entity' ? 'the organisation or the default' : 'the default')
                    : sprintf('set to %s', $normalised),
                entityId: $definition->scope === 'entity' ? $entityId : null,
            );
        });

        $this->flush();
    }

    /**
     * Everything this tenant has overridden - the settings diff.
     *
     * Only the overrides, not every setting. A diff that lists all of them
     * buries the three somebody changed among the forty they did not, and
     * the three are the evidence.
     *
     * @return list<array{
     *     key: string, scope: string, value: string|int|bool|array<mixed>|null,
     *     default: string|int|bool|null, source: string, description: string,
     *     ceiling: ?string, risk_bearing: bool,
     * }>
     */
    public function diff(?string $entityId = null, string $locale = 'en'): array
    {
        $entityId ??= $this->currentEntityId();
        $diff = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            if ($definition->scope === 'entity' && $entityId === null) {
                continue;
            }

            /*
             | A tenant-scoped setting is explained with NO entity.
             |
             | Passing one is refused by `entityFor`, correctly - and the
             | first version of this loop passed the entity to every key, so
             | `diff()` threw as soon as a tenant-scoped setting existed.
             | The skip above only covered the opposite case.
             */
            $explained = $this->explain(
                $key,
                $definition->scope === 'tenant' ? null : $entityId,
                $locale,
            );

            if (! $explained['overridden']) {
                continue;
            }

            $diff[] = [
                'key' => $key,
                'scope' => $definition->scope,
                'value' => $explained['value'],
                'default' => $definition->default,
                'source' => $explained['source'],
                'description' => $definition->description($locale),
                'ceiling' => $explained['ceiling'],
                'risk_bearing' => $definition->bearsRisk(),
            ];
        }

        return $diff;
    }

    /** Forget what was read, after a write or an entity switch. */
    public function flush(): void
    {
        $this->resolved = [];
        $this->tenantValues = null;
        $this->entityValues = [];
    }

    /**
     * The raw stored string, walking entity then tenant then default.
     */
    private function raw(SettingDefinition $definition, ?string $entityId): ?string
    {
        if ($definition->scope === 'entity' && $entityId !== null) {
            $own = $this->entityRows($entityId)[$definition->key] ?? null;

            if ($own !== null) {
                return $own;
            }
        }

        /*
         | A per-entity setting falls back to the TENANT row before the
         | default, even though the registry calls it entity-scoped.
         |
         | That is the point of the scope: it says where an override may be
         | written, not where a value may come from. "Every company uses
         | half-up rounding, except this one" is the normal arrangement, and
         | without the tenant level it would have to be configured once per
         | company and kept in step by hand.
         */
        return $this->tenantRows()[$definition->key]
            ?? ($definition->default === null ? null : $this->defaultAsString($definition));
    }

    private function defaultAsString(SettingDefinition $definition): string
    {
        $default = $definition->default;

        return match (true) {
            is_bool($default) => $default ? '1' : '0',
            default => (string) $default,
        };
    }

    /**
     * @throws SettingRejected
     */
    private function assertWithinCeiling(SettingDefinition $definition, string $value): void
    {
        if ($definition->ceilingKey === null) {
            return;
        }

        $ceiling = BigDecimal::of($this->ceilingValue($definition));
        $requested = BigDecimal::of($value);

        $breached = $definition->ceilingDirection === 'at_least'
            ? $requested->isLessThan($ceiling)
            : $requested->isGreaterThan($ceiling);

        if ($breached) {
            throw SettingRejected::overCeiling(
                $definition->key,
                $value,
                (string) $ceiling,
                $definition->ceilingDirection ?? 'at_most',
            );
        }
    }

    private function ceilingValue(SettingDefinition $definition): string
    {
        $key = (string) $definition->ceilingKey;

        return $definition->type === 'int'
            ? (string) $this->ceilings->int($key)
            : $this->ceilings->decimal($key);
    }

    /**
     * Which entity a read or write applies to, checking the scope.
     *
     * @throws SettingRejected
     */
    private function entityFor(
        SettingDefinition $definition,
        ?string $entityId,
        bool $forWriting = false,
    ): ?string {
        if ($definition->scope === 'tenant') {
            if ($entityId !== null) {
                throw SettingRejected::wrongScope($definition->key, 'tenant', true);
            }

            return null;
        }

        $resolved = $entityId ?? $this->currentEntityId();

        /*
         | Reading without an entity is allowed and falls back to the tenant
         | row; WRITING without one is not.
         |
         | The asymmetry is deliberate. A queued job that reads
         | `documents.default_locale` with no entity selected should get the
         | organisation's answer rather than fail. A write with no entity has
         | no row to live in, and defaulting it to "all of them" is how one
         | company's policy becomes everybody's.
         */
        if ($forWriting && $resolved === null) {
            throw SettingRejected::wrongScope($definition->key, 'entity', false);
        }

        return $resolved;
    }

    private function currentEntityId(): ?string
    {
        // `current()` returns the id, not the model - reading the model here
        // would load an entity nobody asked for on every settings lookup.
        return $this->entities->current();
    }

    /** @return array<string, string> */
    private function tenantRows(): array
    {
        return $this->tenantValues ??= DB::connection('tenant')
            ->table('tenant_settings')
            ->pluck('value', 'key')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    /** @return array<string, string> */
    private function entityRows(string $entityId): array
    {
        return $this->entityValues[$entityId] ??= DB::connection('tenant')
            ->table('entity_settings')
            ->where('entity_id', $entityId)
            ->pluck('value', 'key')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }

    private function writeTenantRow(SettingDefinition $definition, ?string $value): void
    {
        if ($value === null) {
            DB::connection('tenant')->table('tenant_settings')->where('key', $definition->key)->delete();

            return;
        }

        DB::connection('tenant')->table('tenant_settings')->updateOrInsert(
            ['key' => $definition->key],
            [
                'id' => (string) Str::ulid(),
                'type' => $definition->type,
                'value' => $value,
                'set_by_user_id' => auth()->guard('web')->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function writeEntityRow(SettingDefinition $definition, ?string $value, string $entityId): void
    {
        if ($value === null) {
            DB::connection('tenant')->table('entity_settings')
                ->where('entity_id', $entityId)
                ->where('key', $definition->key)
                ->delete();

            return;
        }

        DB::connection('tenant')->table('entity_settings')->updateOrInsert(
            ['entity_id' => $entityId, 'key' => $definition->key],
            [
                'id' => (string) Str::ulid(),
                'type' => $definition->type,
                'value' => $value,
                'set_by_user_id' => auth()->guard('web')->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
