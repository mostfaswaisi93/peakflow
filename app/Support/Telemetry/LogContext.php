<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Log context built from an ALLOWLIST - PF-014.
 *
 * ## Why not a redaction blocklist
 *
 * A blocklist fails open the first time somebody adds a field, and the field
 * that gets added is an IBAN. It fails open silently, in production, on a
 * code path that looked like a debugging convenience - and the log is the one
 * place nobody can delete from.
 *
 * An allowlist fails the other way: a new field is absent from the log until
 * somebody adds it deliberately, which is a missing diagnostic rather than a
 * leaked account number. The cost is real and is the right cost.
 *
 * ## Recursion keeps the guarantee
 *
 * An allowlisted key holding a nested array would be a hole: `['findings' =>
 * [['iban' => '...']]]` passes a top-level check and leaks. So nested arrays
 * are filtered by the same list, and a value that is neither a scalar nor an
 * array is dropped rather than stringified - `(string) $someModel` is how an
 * object's whole state reaches a log line.
 *
 * ## Tenant and entity on every line, personal data on none
 *
 * The ids are what make a line attributable to a customer without naming
 * anybody. "Tenant 01M7… entity 01M7… posted an unbalanced entry" is enough
 * to act on and contains nothing about a person.
 */
final class LogContext
{
    /**
     * Keys that are always added, whatever the caller passed.
     *
     * Not a default that a caller can override: a line without a tenant is a
     * line nobody can route to a customer, and letting a caller pass its own
     * `tenant_id` would let it pass the wrong one.
     */
    private const ALWAYS = ['correlation_id', 'tenant_id', 'entity_id'];

    /**
     * Filter a caller's context and add the ambient ids.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    public static function build(array $context): array
    {
        $allowed = self::filter($context);

        /*
         | The caller's own copies are REMOVED before the ambient ones go on.
         |
         | The first version overlaid the ambient values only when they were
         | non-null, so a caller passing `tenant_id` on a platform request -
         | where there is no tenant - had its forged value survive. A line
         | attributed to the wrong customer is worse than one attributed to
         | none.
         */
        foreach (self::ALWAYS as $key) {
            unset($allowed[$key]);
        }

        foreach (self::ambient() as $key => $value) {
            if ($value !== null) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }

    /**
     * The allowlist itself, as configured.
     *
     * @return list<string>
     */
    public static function allowlist(): array
    {
        $configured = config('telemetry.context_allowlist');

        return is_array($configured)
            ? array_values(array_map(static fn (mixed $k): string => (string) $k, $configured))
            : self::ALWAYS;
    }

    /**
     * Keep only allowlisted keys, recursively.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    public static function filter(array $context, int $depth = 0): array
    {
        $allowlist = self::allowlist();
        $kept = [];

        foreach ($context as $key => $value) {
            /*
             | A LIST is filtered element-wise and keeps its shape.
             |
             | `['findings' => [[...], [...]]]` is the normal shape of a
             | diagnostic, and dropping it because its keys are integers
             | would make the allowlist unusable - so the elements are
             | filtered and the list survives.
             */
            if (is_int($key)) {
                if (is_array($value)) {
                    $filtered = self::filter($value, $depth + 1);

                    if ($filtered !== []) {
                        $kept[$key] = $filtered;
                    }
                }

                continue;
            }

            $name = (string) $key;

            /*
             | Below the top level, a key is DATA as often as it is a field
             | name - and the rule reflects that.
             |
             | `['kinds' => ['missing' => 2, 'wrong' => 1]]` is a map of
             | classification to count, and filtering its keys against a
             | field allowlist is a category error: the first version
             | dropped the whole map and the drift alert said "3 findings"
             | with no breakdown.
             |
             | So at depth, a key survives when it is allowlisted OR its
             | value is not a string. A count survives as data; free text
             | survives only under a name somebody allowlisted. Which keeps
             | the hole closed: `findings[0]['iban' => 'SA03…']` is a string
             | under a name nobody allowlisted, and goes.
             */
            $allowedHere = in_array($name, $allowlist, true)
                || ($depth > 0 && ! is_string($value));

            if (! $allowedHere) {
                continue;
            }

            if (is_array($value)) {
                $kept[$name] = self::filter($value, $depth + 1);

                continue;
            }

            /*
             | Scalars and null only.
             |
             | An object reaching here would be stringified by the formatter,
             | and `(string) $model` is how a model's whole state - including
             | the columns it was told to hide - arrives in a log line.
             */
            if ($value === null || is_scalar($value)) {
                /*
                 | The second layer - PF-014.
                 |
                 | The allowlist controls which FIELDS reach the log and
                 | cannot control what a caller put in one. Measured: an
                 | IBAN passed under the allowlisted name `reference`
                 | survived every field check and reached the line.
                 |
                 | This is not the blocklist that was rejected: it inspects
                 | VALUES, so a new field cannot bypass it.
                 */
                $kept[$name] = SecretShapes::scrub($value);

                continue;
            }

            $kept[$name] = '('.get_debug_type($value).' dropped: not a scalar)';
        }

        return $kept;
    }

    /**
     * The ids that belong on every line.
     *
     * @return array<string, string|null>
     */
    public static function ambient(): array
    {
        return [
            'correlation_id' => CorrelationId::current(),
            'tenant_id' => self::tenantId(),
            'entity_id' => self::entityId(),
        ];
    }

    private static function tenantId(): ?string
    {
        try {
            if (! app()->bound('currentTenant')) {
                return null;
            }

            $tenant = app('currentTenant');

            return is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The entity, read from the DATABASE SESSION rather than the container.
     *
     * Because that is the value row-level security is actually using, and a
     * log line saying which entity a query was scoped to has to agree with
     * the scope the query ran under. The container's copy can be stale - a
     * `during()` block restores it on the way out, and anything logged from
     * inside that block belongs to the entity the database was using.
     */
    private static function entityId(): ?string
    {
        try {
            $variable = (string) config('tenancy.entity_scope.session_variable', 'peakflow.entity_id');

            $row = DB::connection('tenant')->selectOne(sprintf(
                "SELECT nullif(current_setting('%s', true), '') AS id",
                str_replace("'", '', $variable),
            ));

            return $row === null || $row->id === null ? null : (string) $row->id;
        } catch (Throwable) {
            /*
             | No tenant connection, or none configured. Ordinary: a platform
             | request has no entity, and a log line is not worth a query
             | that fails.
             */
            return null;
        }
    }
}
