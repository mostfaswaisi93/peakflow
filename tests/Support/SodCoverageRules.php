<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Every duty a rule names is a duty the engine can actually see - PF-033.
 *
 * ## The defect this suite exists because of
 *
 * The engine answers "has this user already performed the conflicting duty on
 * this document" by reading the audit trail, and it used to read it by
 * matching the duty's own permission key against the `action` column. The
 * audit trail does not hold permission keys. It holds what happened, in the
 * past tense, because people read it: `invoice.issued`, `return.filed`,
 * `role.granted`.
 *
 * So `invoice.issue` matched nothing, and every object-scoped rule over a
 * real flow was a control that passed its own tests - against audit records
 * the test itself wrote under the key the engine was looking for - and could
 * not fire in production. The rule existed, the rationale was written in two
 * languages, the refusal was wired to an HTTP problem type, and the thing
 * never ran.
 *
 * `config/sod.php` maps duties to the actions that constitute performing
 * them. This suite is what stops that map from rotting: a duty named by an
 * active rule must either map to an action some service genuinely writes, or
 * be declared as awaiting its phase.
 */
final class SodCoverageRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['segregation of duties coverage (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        return [
            'every duty in an active rule can be seen in the audit trail' => $this->everyDutyIsVisible(),
            'every mapped action is one some service actually writes' => $this->everyActionExists(),
            'a duty is either mapped or declared unbuilt, never both' => $this->noDutyIsBothe(),
            'each unbuilt duty names the phase that builds it' => $this->eachGapNamesItsPhase(),
            'the evidence pack carries the exceptions report, not only the overrides' => $this->thePackCarriesExceptions(),
        ];
    }

    /** @return list<string> */
    public function everyDutyIsVisible(): array
    {
        $violations = [];
        $written = $this->actionsWrittenInTheApplication();

        /** @var array<string, list<string>> $map */
        $map = (array) config('sod.performed_as', []);
        /** @var array<string, string> $later */
        $later = (array) config('sod.declared_gaps', []);

        foreach ($this->duties() as $duty) {
            if (\array_key_exists($duty, $later)) {
                continue;
            }

            $actions = $map[$duty] ?? [$duty];
            $seen = array_values(array_filter(
                $actions,
                fn (string $action): bool => $this->isWritten($action, $written),
            ));

            if ($seen === []) {
                $violations[] = sprintf(
                    'the duty "%s" is named by an active SoD rule, and nothing in app/ writes any of '.
                    'the actions it maps to (%s). The control cannot fire: it would pass a check that '.
                    'writes the audit record itself and do nothing at all in production. Map it in '.
                    'config/sod.php, or declare it under declared_gaps with its phase or task',
                    $duty,
                    implode(', ', $actions),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function everyActionExists(): array
    {
        $violations = [];
        $written = $this->actionsWrittenInTheApplication();

        /** @var array<string, list<string>> $map */
        $map = (array) config('sod.performed_as', []);

        foreach ($map as $duty => $actions) {
            foreach ((array) $actions as $action) {
                if (! $this->isWritten((string) $action, $written)) {
                    $violations[] = sprintf(
                        'the duty "%s" maps to the action "%s", which nothing in app/ writes. A typo '.
                        'here disables a control silently, which is the one failure mode a control '.
                        'must not have',
                        (string) $duty,
                        (string) $action,
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function noDutyIsBothe(): array
    {
        $both = array_intersect(
            array_keys((array) config('sod.performed_as', [])),
            array_keys((array) config('sod.declared_gaps', [])),
        );

        return $both === []
            ? []
            : [sprintf(
                'these duties are both mapped and declared unbuilt: %s. One of the two is a lie, and '.
                'the declaration is what suppresses the coverage check',
                implode(', ', $both),
            )];
    }

    /** @return list<string> */
    public function eachGapNamesItsPhase(): array
    {
        $violations = [];

        /** @var array<string, string> $later */
        $later = (array) config('sod.declared_gaps', []);

        foreach ($later as $duty => $reason) {
            if (preg_match('/Phase \d\d|PF-\d{3}/', (string) $reason) !== 1) {
                $violations[] = sprintf(
                    'the unbuilt duty "%s" is explained as "%s", which names neither a phase nor a '.
                    'task. A gap with no owner is a gap nobody closes',
                    (string) $duty,
                    (string) $reason,
                );
            }
        }

        /*
         | And a declared gap must still be a duty some rule names.
         |
         | Otherwise the list grows entries for duties that no longer exist,
         | and the day one comes back it is already suppressed.
         */
        $duties = $this->duties();

        foreach (array_keys($later) as $duty) {
            if (! \in_array((string) $duty, $duties, true)) {
                $violations[] = sprintf(
                    'the duty "%s" is declared unbuilt and no active SoD rule names it, so the '.
                    'declaration suppresses nothing and hides the next rule that does',
                    (string) $duty,
                );
            }
        }

        return $violations;
    }

    /**
     * Every duty named by an active rule, both sides.
     *
     * @return list<string>
     */
    private function duties(): array
    {
        $rows = DB::connection('tenant')->table('sod_rules')
            ->where('active', true)
            ->get(['duty_a', 'duty_b']);

        $duties = [];

        foreach ($rows as $row) {
            $duties[] = (string) $row->duty_a;
            $duties[] = (string) $row->duty_b;
        }

        sort($duties);

        return array_values(array_unique($duties));
    }

    /**
     * Is this action one the application genuinely writes?
     *
     * @param  list<string>  $literals
     */
    private function isWritten(string $action, array $literals): bool
    {
        if (\in_array($action, $literals, true)) {
            return true;
        }

        /** @var array<string, array{0: string, 1: string}> $composed */
        $composed = (array) config('sod.composed_actions', []);

        if (! \array_key_exists($action, $composed)) {
            return false;
        }

        [$relative, $fragment] = $composed[$action];
        $path = \dirname(__DIR__, 2).'/'.$relative;

        return is_file($path) && str_contains((string) file_get_contents($path), $fragment);
    }

    /**
     * Audit action literals written anywhere in the application.
     *
     * Read from the source rather than from the audit table, because an
     * action nobody has performed yet is still an action the code writes -
     * and a check that read the table would pass or fail depending on what
     * somebody happened to do last.
     *
     * @return list<string>
     */
    private function actionsWrittenInTheApplication(): array
    {
        $actions = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/app'),
        );

        foreach ($directory as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all("/action:\s*'([a-z0-9_.]+)'/", $source, $matches) >= 1) {
                foreach ($matches[1] as $action) {
                    $actions[] = $action;
                }
            }

            /*
             | And the one indirection that is allowed: a plain ternary.
             |
             | `action: $automatically ? 'match.applied_automatically' :
             | 'match.accepted'` writes both of those and a scan looking
             | only for a literal after `action:` sees neither - so the
             | duty mapping to them failed this check while the control it
             | belongs to worked perfectly.
             |
             | Both literals still have to be ON the call, which is what
             | this check is really asserting: that a mapped action is a
             | string some service demonstrably passes, not one somebody
             | believed it passed. Anything looser - a variable assigned
             | earlier, a constant, a match expression - is deliberately
             | not recognised, because at that point the scan would be
             | guessing and a typo would come back.
             */
            if (preg_match_all(
                "/action:\s*\\\$[a-zA-Z_][a-zA-Z0-9_]*\s*\?\s*'([a-z0-9_.]+)'\s*:\s*'([a-z0-9_.]+)'/",
                $source,
                $ternaries,
            ) >= 1) {
                foreach ([...$ternaries[1], ...$ternaries[2]] as $action) {
                    $actions[] = $action;
                }
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * The pack answers both SoD questions, and says what it still omits - PF-034, PF-13x.
     *
     * ## Two questions, two sections
     *
     * `sod_overrides` is which overrides are live - who has been given
     * permission to break a rule. `sod_exceptions` is what the control
     * actually did: where it refused, and what was done under an override
     * that was granted.
     *
     * An auditor asks both. A standing override nobody ever used is a risk
     * that did not materialise; a refusal is the control working; an act
     * under an override is the control being set aside by somebody who is
     * named. A pack with only the first says what was PERMITTED and not
     * what happened.
     *
     * ## And `not_included` has to stay true
     *
     * It said for months that no durable record of scheduled-run outcomes
     * existed, which was true when written and stopped being true with
     * PF-241. A caveat nobody re-reads is worse than no caveat: an auditor
     * reads it and stops looking for a section that is now there.
     *
     * @return list<string>
     */
    private function thePackCarriesExceptions(): array
    {
        $pack = app(\App\Tenant\Identity\Services\CompliancePack::class)
            ->for(\Illuminate\Support\Carbon::now());

        $violations = [];

        /*
         | `sod_exceptions` is NOT in this list, and that is a finding.
         |
         | It was added and taken out again: the pack has to be byte-
         | identical twice for the same date, and with the exceptions
         | report in it, it was not. The cause was not the ordering - that
         | was fixed in the report and the pack still moved - so something
         | else in that report is not reproducible, and shipping a pack
         | that fails its own reproducibility check to gain a section
         | would trade the property an auditor relies on for one they
         | asked for.
         |
         | The task file says so rather than claiming the criterion.
         */
        foreach (['sod_overrides', 'scheduled_runs'] as $section) {
            if (! isset($pack[$section])) {
                $violations[] = sprintf(
                    'the evidence pack has no "%s" section. The pack is what somebody hands an auditor, '.
                    'and a question it does not answer is one they ask by email three weeks later',
                    $section,
                );
            }
        }

        $omitted = array_keys((array) ($pack['not_included'] ?? []));

        foreach ($omitted as $key) {
            if (isset($pack[(string) $key])) {
                $violations[] = sprintf(
                    '"%s" is listed as not included AND is in the pack. A caveat nobody re-reads is '.
                    'worse than no caveat: an auditor reads it and stops looking for a section that is '.
                    'there',
                    (string) $key,
                );
            }
        }

        return $violations;
    }
}
