<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Services\MigrationSafetyInspector;
use App\Platform\ValueObjects\MigrationHazard;

/**
 * The rules that encode the non-negotiables (PF-005).
 *
 * Each one is a rule a code review will eventually miss. They are expressed
 * ONCE, here, and run by two callers:
 *
 *   - tests/Architecture/*Test.php, under PHPUnit or Pest
 *   - bin/verify, which needs no test runner at all
 *
 * Two runners over one implementation, so the checks still run in an
 * environment where the dev dependencies cannot be installed - which is
 * exactly the environment this was first written in.
 *
 * Every method returns a list of human-readable violations. Empty means pass.
 */
final class ArchitectureRules
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /** @return array<string, list<string>> rule name => violations */
    public function all(): array
    {
        return [
            'no float in the money path' => $this->noFloatInMoneyPath(),
            'no bcmath call, because the extension is not required' => $this->noBcMathCalls(),
            'only three decimal scales exist' => $this->onlyThreeScales(),
            'tenant models declare their connection' => $this->tenantModelsDeclareConnection(),
            'Reporting never writes' => $this->reportingNeverWrites(),
            'no module reaches into another module\'s models' => $this->noCrossModuleModelAccess(),
            'the tenant plane does not depend on the platform plane' => $this->tenantDoesNotDependOnPlatform(),
            'the layer rules bite, proved on every run' => $this->theLayerRulesBite(),
            'every ceiling is enforced somewhere' => $this->ceilingsAreEnforced(),
            'no physical CSS property' => $this->noPhysicalCssProperties(),
            'no untranslated text in a template' => $this->templatesHaveNoUntranslatedText(),
            'financial tables carry entity_id' => $this->financialTablesCarryEntityId(),
            'no migration is unsafe to run against live traffic' => $this->migrationsAreSafeToRunLive(),
            'and every migration says whether it is' => $this->everyMigrationDeclaresItsSafety(),
            'the built assets are not stale' => $this->builtAssetsAreNotStale(),
            'translation catalogs agree' => $this->translationCatalogsAgree(),
            'strict types everywhere' => $this->strictTypesDeclared(),
            'no exchange rate is ever inverted' => $this->ratesAreNeverInverted(),
            'a posting is never queued, and a side effect always is' => $this->postingIsNeverDeferred(),
            'an event carries no other module\'s models' => $this->eventsCarryNoModels(),
            'a subtree is read as a range, never as a LIKE' => $this->subtreesAreReadAsRanges(),
            'a suite that posts ends with the global proof' => $this->postingSuitesProveTheLedger(),
            'a bank account is read only through the disclosure service' => $this->bankReadsAreAudited(),
            'an ordered relation is reordered, never re-ordered' => $this->orderedRelationsAreReordered(),
        ];
    }

    /**
     * Postings are synchronous; side effects are not - PF-048.
     *
     * ## The rule
     *
     * **A posting that is eventually consistent with the document that
     * caused it is not a ledger.** An invoice that exists for four seconds
     * with no journal entry is an invoice whose VAT is not in the return,
     * whose receivable is not in the aging, and whose customer is not over
     * their credit limit. Four seconds is enough for a report to be run,
     * and a queue that is down is enough for a day.
     *
     * The other half matters as much in the other direction: a mail
     * listener run synchronously inside the posting transaction turns a mail
     * server timeout into a rolled-back invoice. Nobody intends that; it
     * happens because the queue is an inconvenience during development and
     * the change gets committed.
     *
     * ## Why a rule rather than a convention
     *
     * Because the mistake is invisible in review. `implements ShouldQueue`
     * on a listener is one line that reads like good practice, and it is -
     * except on the one class where it silently makes the ledger eventually
     * consistent.
     *
     * @return list<string>
     */
    public function postingIsNeverDeferred(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            $original = (string) file_get_contents($file);

            if (! str_contains($original, 'Listener') && ! str_contains($original, '\\Listeners\\')) {
                continue;
            }

            $source = $this->withoutComments($original);
            $relative = $this->relative($file);

            $isSideEffect = (bool) preg_match('/implements[^{]*\bSideEffect\b/', $source);
            $isQueued = (bool) preg_match('/implements[^{]*\bShouldQueue\b/', $source);

            /*
             | A listener that handles a FinancialEvent and is queued.
             |
             | Detected from the handle() signature rather than from a
             | marker on the listener, because the listener does not get to
             | decide: the event says the ledger depends on it.
             */
            $handlesFinancial = (bool) preg_match(
                /*
                 | `[^)]*` rather than a namespace pattern.
                 |
                 | Matching an optionally-qualified class name means
                 | matching backslashes, and a backslash inside a
                 | single-quoted PHP string inside a regex is three levels
                 | of escaping that took two attempts to get wrong in both
                 | directions. The parameter list cannot contain a `)`
                 | before the type, so this is both simpler and exact.
                 */
                '/function\s+handle\s*\([^)]*\b'.
                '(PeriodClosed|PeriodReopened|YearClosed|RevaluationRun|InvoiceIssued|CreditNoteIssued|'.
                'BillApproved|PaymentReceived|PaymentReleased|StatementLineMatched)\b/',
                $source,
            );

            if ($handlesFinancial && $isQueued && ! $isSideEffect) {
                $violations[] = sprintf(
                    '%s handles a financial event and implements ShouldQueue. A posting that is eventually '.
                    'consistent with the document that caused it is not a ledger - and the window is long '.
                    'enough for a report to be run in. If this listener is a side effect, say so by '.
                    'implementing SideEffect as well',
                    $relative,
                );
            }

            if ($isSideEffect && ! $isQueued) {
                $violations[] = sprintf(
                    '%s is marked SideEffect and is not queued, so a failure in it can fail or roll back '.
                    'the posting. A mail server being down has no business reversing a journal entry',
                    $relative,
                );
            }
        }

        return $violations;
    }

    /**
     * A cross-module event carries primitives, not models - PF-048.
     *
     * ## Why
     *
     * A `Period` on an event makes every listener depend on `Ledger`'s
     * models - including the ones the module graph forbids from doing that,
     * and including a tenant's webhook payload, which would then be a
     * serialised Eloquent model with whatever relations happened to be
     * loaded.
     *
     * It also breaks on a queue: a model serialises as a class and an id,
     * and deserialises in a worker that may be running the next release
     * against a row that has since changed - so the listener sees a
     * different state from the one that caused the event.
     *
     * Support value objects are fine. `Money` and `Rate` are values with no
     * database behind them, which is the whole reason they live in Support.
     *
     * @return list<string>
     */
    public function eventsCarryNoModels(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            if (! str_contains($file, '/Events/')) {
                continue;
            }

            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);
            $relative = $this->relative($file);

            /*
             | A constructor-promoted property typed as a module model.
             |
             | Matched on the namespace rather than on a list of class
             | names, so a model added next month is covered without this
             | rule being edited - which is the difference between a rule
             | and a snapshot.
             */
            /*
             | The type is captured and then tested with str_contains.
             |
             | Same reason as above: a namespace pattern needs backslashes,
             | and `'Models\\'` in a plain string is unambiguous where the
             | same thing inside a regex is not.
             */
            if (preg_match_all(
                '/(?:public|private|protected)\s+(?:readonly\s+)?([^\s$()]+)\s+\$\w+/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            )) {
                foreach ($matches[1] as [$class, $offset]) {
                    if (! str_contains($class, 'Models\\')) {
                        continue;
                    }

                    if ($this->lineIsAnnotated($original, $offset)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d carries %s. An event with a model on it makes every listener depend on that '.
                        "module's models, and serialises onto a queue as a class and an id that deserialises ".
                        'against a row which has since changed - so the listener sees a different state from '.
                        'the one that caused the event. Carry ids and Support value objects',
                        $relative,
                        $this->lineAt($source, $offset),
                        $class,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Nothing anywhere computes the other direction of a rate - PF-068.
     *
     * ## Why this is worth a rule of its own
     *
     * `1 / 0.92` is not the EUR to USD rate. It is a number four
     * ten-thousandths of a cent away from it, in the same direction, every
     * time it is used. A thousand units converted out and back comes home as
     * 1,000.0000000004.
     *
     * That lands as a consolidation difference small enough to dismiss and
     * large enough to notice - the worst size a difference can be, because
     * nobody can justify a week finding it and nobody can sign the accounts
     * while it is there.
     *
     * The design answer is that both directions are separate rows and
     * {@see \App\Support\Money\Rate} has no `inverse()`. This rule is the
     * tripwire for the day somebody reaches for one anyway, under deadline,
     * because a rate was missing and the inverse was right there.
     *
     * It is a text search and it knows it: it catches the obvious forms, not
     * every possible one. The real enforcement is that `convert()` refuses
     * the wrong direction and the lookup refuses a missing one. This is what
     * fails the build while the intent is still visible in the diff.
     *
     * @return list<string>
     */
    public function ratesAreNeverInverted(): array
    {
        $violations = [];

        $patterns = [
            // 1 / something whose name mentions a rate, in either order of
            // writing: `1 / $rate`, `1.0 / $fxRate`, `1 / ($rate->value)`.
            '/\b1(?:\.0+)?\s*\/\s*[^;\n]{0,40}(?:rate|fx)/i' => 'divides 1 by something rate-shaped',
            // An inverse, by any of its usual names.
            '/(?:->|::|function\s+)(?:inverse|inverted|invert|reciprocal)\s*\(/i' => 'computes an inverse rate',
            // The SQL form, which a migration or a raw query could carry.
            '/\b1\s*\/\s*(?:fx_)?rate\b/i' => 'inverts a rate in SQL',
            /*
             | The decimal-safe form, which is the one a careful developer
             | would reach for. It avoids the float and does the same wrong
             | arithmetic: BigDecimal::one()->dividedBy($rate, 12) is still
             | the reciprocal of a rounded number.
             */
            '/(?:one\(\)|of\(\s*[\'"]?1(?:\.0+)?[\'"]?\s*\))\s*->\s*dividedBy/i'
                => 'divides one by something, which is an inverse rate however it is spelled',
            '/\bbcdiv\s*\(\s*[\'"]1/i' => 'divides 1 by something with bcdiv',
            /*
             | And the form with no 1 in it at all.
             |
             | Dividing an amount BY a rate is the inverse by another name:
             | `$sar->dividedBy($eurToSarRate)` is how you get euro out of a
             | EUR-to-SAR rate without ever writing a reciprocal, and it
             | carries exactly the same drift.
             */
            '/->\s*dividedBy\s*\(\s*\$\w*(?:rate|Rate|fx|Fx)/' => 'divides an amount by a rate',
        ];

        $roots = ['app', 'database/migrations', 'resources/views'];

        foreach ($roots as $root) {
            if (! is_dir($this->basePath.'/'.$root)) {
                continue;
            }

            foreach ($this->phpFiles($root) as $file) {
                $original = (string) file_get_contents($file);

                /*
                 | Comments blanked, for the reason the float rule records:
                 | every sentence explaining why a rate must not be inverted
                 | contains the thing being searched for, so the prose would
                 | flag itself and the only ways out would be to delete the
                 | explanation or annotate it.
                 */
                $source = $this->withoutComments($original);
                $relative = $this->relative($file);

                foreach ($patterns as $pattern => $what) {
                    if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                        continue;
                    }

                    foreach ($matches[0] as [$match, $offset]) {
                        if ($this->lineIsAnnotated($original, $offset)) {
                            continue;
                        }

                        $violations[] = sprintf(
                            '%s:%d %s (%s). Fetch the rate for the direction you need; the reciprocal of a '.
                            'rounded rate does not round-trip, and the drift is one-directional and permanent.',
                            $relative,
                            $this->lineAt($source, $offset),
                            $what,
                            trim($match),
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * IEEE 754 cannot represent 0.1. The drift over a large ledger is small,
     * non-deterministic across platforms, and unexplainable to an auditor.
     *
     * @return list<string>
     */
    public function noFloatInMoneyPath(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            /*
             | Comments are blanked before scanning, not stripped.
             |
             | A comment explaining WHY a float must not be used contains the
             | word, so the rule flagged the explanation of itself - and the
             | only ways out were to delete the explanation or to annotate it
             | with the escape hatch meant for genuine exceptions. Both make
             | the codebase worse to read in order to satisfy a check.
             |
             | Blanking rather than removing keeps every byte offset, so the
             | line numbers in a violation still point at the right line.
             */
            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);
            $relative = $this->relative($file);

            // A float type declaration on a parameter, property or return.
            if (preg_match_all('/(?:^|[\s(,])(?:\?)?(?:float|double)(?:\s+\$|\s*\))/i', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    /*
                     | Matched in the blanked source, annotated against the
                     | ORIGINAL - `@arch-allow` lives in a comment, and
                     | blanking comments blanked the exemptions along with
                     | the prose they were exempting. Offsets are identical
                     | between the two because blanking preserves length.
                     */
                    if ($this->lineIsAnnotated($original, $offset)) {
                        continue;
                    }

                    $violations[] = sprintf('%s:%d declares %s', $relative, $this->lineAt($source, $offset), trim($match));
                }
            }

            // An explicit cast or coercion.
            foreach (['(float)', '(double)', 'floatval(', 'doubleval('] as $needle) {
                $pos = 0;
                while (($pos = strpos($source, $needle, $pos)) !== false) {
                    // `(float) (string) $money` inside a formatter is the one
                    // legitimate case: ICU's NumberFormatter takes a float, and
                    // the value has already been rounded to the currency's
                    // exponent. It is annotated at the call site.
                    // Against the ORIGINAL: the annotation is a comment.
                    if (! $this->lineIsAnnotated($original, $pos)) {
                        $violations[] = sprintf('%s:%d uses %s', $relative, $this->lineAt($source, $pos), $needle);
                    }
                    $pos += strlen($needle);
                }
            }

            /*
             | An Eloquent cast to float.
             |
             | Annotatable like the other two branches. It was not, and the
             | inconsistency only shows up to somebody holding a legitimate
             | case: a confidence score, a percentage, a ratio - none of
             | them money, all of them caught here with no way out but
             | deleting the cast. Two branches of one rule behaving
             | differently is how a guardrail comes to be worked around
             | rather than used.
             */
            if (preg_match_all('/=>\s*[\'"](?:float|real|double)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    if ($this->lineIsAnnotated($original, $offset)) {
                        continue;
                    }

                    $violations[] = sprintf('%s:%d casts an attribute to float', $relative, $this->lineAt($source, $offset));
                }
            }
        }

        return $violations;
    }

    /**
     * No `bc*` call anywhere - PF-001.
     *
     * ## Why this is a rule and not a preference
     *
     * `composer.json` lists `bcmath` under `suggest`, not `require`, and the
     * reasoning is recorded there: `brick/math` falls back to a pure-PHP
     * calculator, exactly as correct and slower. So the extension is ABSENT on
     * this deployment - measured, `extension_loaded('bcmath')` is false - and
     * a `bcdiv()` is a fatal error at the moment somebody computes tax.
     *
     * It is the worst shape of missing dependency: the code looks right, it
     * passes review, and it fails only on the machines without the extension -
     * which are the ones nobody develops on.
     *
     * The first version of `TaxCode::fraction()` used `bcdiv`, which is what
     * prompted this.
     *
     * @return list<string>
     */
    public function noBcMathCalls(): array
    {
        $violations = [];

        /*
         | The whole family, by name.
         |
         | A pattern like `/\bbc\w+\(/` would also match a method called
         | `bcSomething()` on an object, and more usefully it would match
         | nothing the day somebody writes `\bcdiv(...)` with a leading
         | namespace separator - which PHP resolves to the global function.
         | So the list is explicit and the separator is optional.
         */
        $functions = [
            'bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bcmod', 'bcpow', 'bcpowmod',
            'bcsqrt', 'bccomp', 'bcscale', 'bcdivmod',
        ];

        foreach ($this->phpFiles('app') as $file) {
            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);

            foreach ($functions as $function) {
                /*
                 | The lookbehind excludes word characters, `$` and `>` and
                 | NOT the backslash - so `\bcdiv(` matches too, which is how
                 | PHP spells a call to the global function from inside a
                 | namespace. The first version wrote the backslash as an
                 | optional escape and ended up requiring a literal `?`, so
                 | the rule matched nothing and passed.
                 */
                if (preg_match_all('/(?<![\w$>])'.$function.'\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($m[0] as [$match, $offset]) {
                    if ($this->lineIsAnnotated($original, $offset)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d calls %s. bcmath is under `suggest` and is ABSENT on this deployment, so '.
                        'this is a fatal error the first time it runs - use Brick\Math\BigDecimal, '.
                        'which is what the money layer uses.',
                        $this->relative($file),
                        $this->lineAt($source, $offset),
                        trim($match),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Exactly three scales. A fourth means a migration over every money column
     * in every tenant database.
     *
     * @return list<string>
     */
    public function onlyThreeScales(): array
    {
        $path = $this->basePath.'/app/Support/Money/Scale.php';

        if (! is_file($path)) {
            return ['app/Support/Money/Scale.php is missing'];
        }

        $source = (string) file_get_contents($path);
        preg_match_all('/^\s{4}case\s+(\w+)\s*=\s*(\d+);/m', $source, $m);

        $found = array_combine($m[1], $m[2]);
        $expected = ['Amount' => '4', 'UnitPrice' => '8', 'Rate' => '12'];

        if ($found !== $expected) {
            return [sprintf(
                'Scale must be exactly %s; found %s',
                json_encode($expected),
                json_encode($found),
            )];
        }

        return [];
    }

    /**
     * A tenant query must follow whichever database the resolver most recently
     * selected, so it can never read platform data even if a developer forgets
     * a scope.
     *
     * @return list<string>
     */
    public function tenantModelsDeclareConnection(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app/Tenant') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/\bextends\s+(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?Model\b/', $source)) {
                continue;
            }

            if (! str_contains($source, 'function getConnectionName')) {
                $violations[] = $this->relative($file).' extends Model without declaring getConnectionName()';
            }
        }

        return $violations;
    }

    /**
     * A report that can write has become a second source of truth, and the
     * promise that every balance is explainable from the journal depends on
     * there being exactly one.
     *
     * @return list<string>
     */
    public function reportingNeverWrites(): array
    {
        $violations = [];
        $forbidden = ['->save(', '->insert(', '->update(', '->delete(', '->forceDelete(', '->truncate(', 'DB::statement(', '::create(', '::insert('];

        foreach ($this->phpFiles('app/Tenant/Reporting') as $file) {
            $source = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                $pos = 0;
                while (($pos = strpos($source, $needle, $pos)) !== false) {
                    if (! $this->lineIsAnnotated($source, $pos)) {
                        $violations[] = sprintf('%s:%d writes via %s', $this->relative($file), $this->lineAt($source, $pos), $needle);
                    }
                    $pos += strlen($needle);
                }
            }
        }

        return $violations;
    }

    /**
     * A module may reach into another's models, traits, scopes or enums only
     * where the Deptrac ruleset permits it.
     *
     * The first version forbade EVERY cross-module reference, which is not
     * the rule: `Tax` is allowed to depend on `Ledger`, `Ledger` on
     * `Tenancy`, and so on. It passed for a year only because no module had
     * yet used a legitimate dependency, and the moment the ledger used
     * Tenancy's entity-scope trait - exactly as the ruleset intends - it
     * reported four violations that were not violations.
     *
     * So the allowed directions are read from `deptrac.yaml`, which is the
     * same file Deptrac compiles. Deptrac is not installed here, and a
     * boundary that exists only in a config nobody can run is not a boundary.
     *
     * @return list<string>
     */
    public function noCrossModuleModelAccess(): array
    {
        $violations = [];
        $modules = $this->moduleNames();
        $allowed = $this->deptracRuleset();

        foreach ($modules as $module) {
            foreach ($this->phpFiles('app/Tenant/'.$module) as $file) {
                $source = (string) file_get_contents($file);

                foreach ($modules as $other) {
                    if ($other === $module) {
                        continue;
                    }

                    // Permitted by the ruleset: Ledger may use Tenancy, Tax
                    // may use Ledger. Only the directions the ruleset omits
                    // are violations.
                    if (in_array($other, $allowed[$module] ?? [], true)) {
                        continue;
                    }

                    /*
                     | Models are the obvious boundary breach and were the only
                     | one this rule checked. They are not the only one: a
                     | trait, a scope or an enum reached across a module
                     | dissolves the boundary just as completely, and more
                     | quietly, because nothing in the code looks like a query.
                     |
                     | That gap let an Identity model use a Tenancy trait while
                     | this rule reported nothing. Services are excluded
                     | deliberately - calling another module's application
                     | service IS the sanctioned route, and Deptrac enforces
                     | the direction of those calls.
                     */
                    foreach (['Models', 'Concerns', 'Scopes', 'Enums'] as $kind) {
                        $needle = 'App\\Tenant\\'.$other.'\\'.$kind.'\\';

                        if (str_contains($source, $needle)) {
                            $violations[] = sprintf(
                                '%s references %s, which the Deptrac ruleset does not permit %s to depend on; '.
                                'go through a domain event or an application service',
                                $this->relative($file),
                                $needle,
                                $module,
                            );
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The layer rules are proved on every run, by violating them - PF-003.
     *
     * ## Why this is a rule rather than a procedure
     *
     * PF-003 asks that "a deliberate violation added in a scratch branch
     * fails the check". That is a procedure somebody performs once, writes
     * down, and never repeats - and a layer rule that silently stops matching
     * is indistinguishable from a codebase with no violations. Both report
     * nothing, which is the state a clean codebase is supposed to be in.
     *
     * So the violation is added here, on every run, in a file that is deleted
     * again: a scratch file in `app/Tenant/Ledger` importing a model from
     * `Platform` and one from `Parties`, which the ruleset permits neither of.
     * If the rules do not object to it, they are not working, and the suite
     * says so instead of passing quietly.
     *
     * ## The file is removed in a `finally`, and a stale one is removed first
     *
     * A scratch file left behind would fail every subsequent run with a real
     * violation in a file nobody wrote, which is a confusing way to discover
     * that a check crashed. So the name is distinctive, anything matching it
     * is deleted before the probe is written, and the write is undone whatever
     * happens.
     *
     * @return list<string>
     */
    public function theLayerRulesBite(): array
    {
        $problems = [];
        $directory = $this->basePath.'/app/Tenant/Ledger';

        if (! is_dir($directory)) {
            return ['app/Tenant/Ledger does not exist, so the layer rules have nothing to be proved against.'];
        }

        // Anything left by a crashed run.
        foreach (glob($directory.'/PeakflowLayerProbe*.php') ?: [] as $stale) {
            @unlink($stale);
        }

        $path = $directory.'/PeakflowLayerProbe.php';

        /*
         | Two violations in one file, because the two rules catch different
         | shapes: one reads the Deptrac ruleset for cross-module references,
         | the other forbids the tenant plane from naming the platform plane
         | at all. A probe that only tripped one would leave the other
         | unproved.
         */
        $probe = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tenant\Ledger;

            use App\Platform\Models\Tenant;
            use App\Tenant\Parties\Models\Party;

            /** A deliberate layer violation, written and deleted by ArchitectureRules. */
            final class PeakflowLayerProbe
            {
                public function __construct(public Tenant $tenant, public Party $party) {}
            }
            PHP;

        file_put_contents($path, $probe."
");

        try {
            $crossModule = $this->noCrossModuleModelAccess();
            $planes = $this->tenantDoesNotDependOnPlatform();

            $caughtCrossModule = false;

            foreach ($crossModule as $violation) {
                if (str_contains($violation, 'PeakflowLayerProbe')) {
                    $caughtCrossModule = true;

                    break;
                }
            }

            if (! $caughtCrossModule) {
                $problems[] = 'A file in app/Tenant/Ledger importing App\Tenant\Parties\Models\Party '.
                    'was not reported by the cross-module rule. The Deptrac ruleset does not permit '.
                    'Ledger to depend on Parties, so either the rule stopped matching or the ruleset '.
                    'changed - and a layer rule that reports nothing looks exactly like a clean codebase.';
            }

            $caughtPlanes = false;

            foreach ($planes as $violation) {
                if (str_contains($violation, 'PeakflowLayerProbe')) {
                    $caughtPlanes = true;

                    break;
                }
            }

            if (! $caughtPlanes) {
                $problems[] = 'A file in app/Tenant importing App\Platform\Models\Tenant was not '.
                    'reported by the plane rule. The tenant plane must not name the platform plane: a '.
                    'tenant query that reaches the platform database reads another customer\'s row and '.
                    'returns it as data.';
            }
        } finally {
            @unlink($path);
        }

        /*
         | And the rules must be quiet again once the probe is gone.
         |
         | Without this, a rule that reported every file would pass the two
         | checks above and be useless - "it objects to the probe" is only
         | half the claim.
         */
        $after = array_merge($this->noCrossModuleModelAccess(), $this->tenantDoesNotDependOnPlatform());

        foreach ($after as $violation) {
            if (str_contains($violation, 'PeakflowLayerProbe')) {
                $problems[] = 'The layer rules still report the probe after it was deleted, so they are '.
                    'reading something other than the files on disk.';
            }
        }

        return $problems;
    }

    /**
     * A single `left:` in a component is an RTL bug waiting for a customer to
     * find it.
     *
     * @return list<string>
     */
    public function noPhysicalCssProperties(): array
    {
        $violations = [];

        $patterns = [
            '/(?<![\w-])(?:margin|padding|border)-(?:left|right)\s*:/i',
            '/(?<![\w-])(?:^|\s)(?:left|right)\s*:/im',
            '/text-align\s*:\s*(?:left|right)/i',
            '/float\s*:\s*(?:left|right)/i',
        ];

        $files = array_merge(
            $this->filesMatching('resources/css', '/\.css$/'),
            $this->filesMatching('resources/views', '/\.blade\.php$/'),
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [$match, $offset]) {
                        if ($this->lineIsAnnotated($source, $offset)) {
                            continue;
                        }

                        $violations[] = sprintf(
                            '%s:%d uses the physical property "%s"; use the logical equivalent',
                            $this->relative($file),
                            $this->lineAt($source, $offset),
                            trim($match),
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Cross-company data leakage inside one tenant is harder to detect than
     * cross-tenant leakage, because the data looks plausible. "We'll scope it
     * later" is how it happens, and later has no deadline.
     *
     * @return list<string>
     */
    public function financialTablesCarryEntityId(): array
    {
        $violations = [];
        $dir = $this->basePath.'/database/migrations/tenant';

        if (! is_dir($dir)) {
            return [];
        }

        foreach ($this->filesMatching('database/migrations/tenant', '/\.php$/') as $file) {
            $source = (string) file_get_contents($file);

            /*
             | Only migrations that DECLARE themselves financial are held to
             | this. A lookup table does not need an entity.
             |
             | Matched as a docblock tag on its own line, not as a substring.
             | A plain `str_contains` also matched the prose explaining why a
             | migration is deliberately NOT marked, so writing down the
             | reason made the file fail the rule it was explaining.
             */
            if (preg_match('/^\s*\*\s*@financial\s*$/m', $source) !== 1) {
                continue;
            }

            if (! preg_match('/entity_id/', $source)) {
                $violations[] = $this->relative($file).' is marked @financial but declares no entity_id';
            }

            /*
             | And the column must be NOT NULL - PF-005.
             |
             | A nullable `entity_id` is a row scoped to no entity, and the
             | row-level security policy compares it to `current_entity()`.
             | NULL is not equal to anything in SQL, so the row becomes
             | invisible to every entity INCLUDING the one that wrote it -
             | and the write succeeds. A posting that disappears is worse
             | than one that is refused.
             |
             | Two spellings, because both are used: a Blueprint chain and
             | raw DDL for the tables that need partitioning or triggers the
             | builder cannot express.
             */
            if (preg_match_all('/->\s*(?:ulid|char|uuid|string|foreignUlid)\s*\(\s*[\'"]entity_id[\'"][^;]*?->\s*nullable\s*\(/s', $source, $nullable, PREG_OFFSET_CAPTURE)) {
                foreach ($nullable[0] as [, $offset]) {
                    $violations[] = sprintf(
                        '%s:%d declares a NULLABLE entity_id in an @financial migration. NULL equals '.
                        'nothing in SQL, so the RLS policy hides the row from every entity including the '.
                        'one that wrote it - and the write succeeds.',
                        $this->relative($file),
                        $this->lineAt($source, $offset),
                    );
                }
            }

            if (preg_match_all('/^\s*entity_id\s+(?:char|uuid|varchar|text)\s*(?:\([0-9]+\))?\s*(?!.*not\s+null)[,\n]/im', $source, $rawNullable, PREG_OFFSET_CAPTURE)) {
                foreach ($rawNullable[0] as [$match, $offset]) {
                    $violations[] = sprintf(
                        '%s:%d declares entity_id in raw DDL without NOT NULL (%s) in an @financial '.
                        'migration. The Blueprint defaults to NOT NULL and raw SQL defaults to nullable, '.
                        'which is the trap: the two spellings of the same column disagree.',
                        $this->relative($file),
                        $this->lineAt($source, $offset),
                        trim($match, " \n,"),
                    );
                }
            }

            // Either spelled out, or via the helper that migration 000500
            // installs - which is the form a new financial migration should
            // use, since it also FORCES RLS and adds the migrator bypass.
            $enablesRls = str_contains($source, 'ENABLE ROW LEVEL SECURITY')
                || str_contains($source, 'peakflow_apply_entity_rls');

            if (! $enablesRls) {
                $violations[] = $this->relative($file).
                    ' is marked @financial but enables no row-level security; call peakflow_apply_entity_rls()';
            }
        }

        return $violations;
    }

    /**
     * No user-visible text in a template may be a literal - PF-005.
     *
     * ## Why this is not the same rule as "the catalogs agree"
     *
     * That rule checks that every `__()` key exists in both files. It cannot
     * see a string that was never passed to `__()` at all, and the two
     * failures look nothing alike: a missing key renders the key, which
     * somebody notices, while a literal renders perfectly in the author's
     * language and is simply absent from the other one.
     *
     * In an Arabic-first product the literal is usually Arabic, so the English
     * page is the one that silently shows Arabic - and the people who would
     * report it are the ones not reading that page.
     *
     * ## Attributes are checked, not skipped
     *
     * Stripping tags wholesale is the easy implementation and it misses the
     * strings most likely to be left behind: `placeholder`, `title`, `alt`
     * and `aria-label` are user-visible, are not in the flow of the page, and
     * are exactly what an author forgets to wrap. So tag contents are scanned
     * for those attributes specifically, and everything else inside a tag -
     * class names, `dir`, `role`, `type` - is ignored.
     *
     * @return list<string>
     */
    public function templatesHaveNoUntranslatedText(): array
    {
        $violations = [];

        /*
         | The attributes a person actually reads.
         |
         | `value` is deliberately absent: it is `{{ old(...) }}` in every
         | form in this codebase, and a literal `value="1"` has no letters, so
         | including it would only add a way to be wrong about a submit
         | button's label - which should be the button's text anyway.
         */
        $visible = ['placeholder', 'title', 'alt', 'aria-label', 'aria-description', 'aria-placeholder', 'aria-roledescription'];

        foreach ($this->filesMatching('resources/views', '/\.blade\.php$/') as $file) {
            $original = (string) file_get_contents($file);
            $relative = $this->relative($file);
            $source = $this->withoutBladeOutput($original);

            foreach ($this->textAndAttributes($source, $visible) as [$text, $offset, $what]) {
                if (preg_match('/\p{L}{2,}/u', $text) !== 1) {
                    continue;
                }

                if ($this->lineIsAnnotated($original, $offset)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d has the untranslated %s "%s". In an Arabic-first product a literal renders '.
                    'perfectly for its author and is simply absent from the other language - and the '.
                    'people who would report it are the ones not reading that page.',
                    $relative,
                    $this->lineAt($source, $offset),
                    $what,
                    trim(preg_replace('/\s+/u', ' ', $text) ?? ''),
                );
            }
        }

        return $violations;
    }

    /**
     * Blank every Blade construct, keeping byte offsets.
     *
     * Blanked rather than removed for the same reason comments are: the line
     * numbers in a violation still point at the right line, and the
     * `@arch-allow` annotation is looked up in the original at the same
     * offset.
     */
    private function withoutBladeOutput(string $source): string
    {
        /*
         | Byte-wise, deliberately: no `/u` flag.
         |
         | With `/u` the pattern matches a CHARACTER, so each two-byte Arabic
         | letter was replaced by one space and the string got shorter.
         | Measured: `@extends('layouts.plain', ['title' => __('تسجيل
         | الدخول')])` shrank by nine bytes, every offset after it shifted,
         | and the next directive was blanked at the wrong place - so
         | `@section` survived and was reported as untranslated text on line 1.
         |
         | Blanking exists to preserve offsets. A blanker that does not is
         | worse than none, because the violations it reports point somewhere
         | else.
         */
        $blank = static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

        // Comments first: they can contain anything, including Blade echoes.
        $source = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $source = (string) preg_replace_callback('/<!--.*?-->/s', $blank, $source);
        $source = (string) preg_replace_callback('/@php\b.*?@endphp/s', $blank, $source);
        $source = (string) preg_replace_callback('/\{!!.*?!!\}/s', $blank, $source);
        $source = (string) preg_replace_callback('/\{\{.*?\}\}/s', $blank, $source);

        /*
         | Directives, with a balanced argument list.
         |
         | `@error('email')` and `@if ($a && str_contains($b, ")"))` both have
         | to go, and a non-greedy `\(.*?\)` stops at the first `)` inside a
         | string. So the parentheses are matched by depth.
         */
        $out = $source;
        $offset = 0;

        while (preg_match('/@[A-Za-z_]\w*/', $out, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$directive, $at] = $m[0];
            $end = $at + \strlen($directive);

            // An optional argument list, which may itself contain parentheses.
            $probe = $end;

            while ($probe < \strlen($out) && $out[$probe] === ' ') {
                $probe++;
            }

            if ($probe < \strlen($out) && $out[$probe] === '(') {
                $depth = 0;

                for ($i = $probe; $i < \strlen($out); $i++) {
                    if ($out[$i] === '(') {
                        $depth++;
                    } elseif ($out[$i] === ')') {
                        $depth--;

                        if ($depth === 0) {
                            $end = $i + 1;

                            break;
                        }
                    }
                }
            }

            $out = substr($out, 0, $at)
                .(string) preg_replace('/[^\n]/', ' ', substr($out, $at, $end - $at))
                .substr($out, $end);

            $offset = $end;
        }

        /*
         | Style and script bodies, which are text nodes and not prose.
         |
         | The error layout's inline CSS was reported as untranslated text -
         | several hundred characters of it - which is correct about where the
         | letters are and wrong about what they mean. A `<style>` body is not
         | something a reader reads.
         */
        $out = (string) preg_replace_callback('#<style\b[^>]*>.*?</style>#is', $blank, $out);
        $out = (string) preg_replace_callback('#<script\b[^>]*>.*?</script>#is', $blank, $out);

        // Entities, so `&nbsp;` is not a word.
        return (string) preg_replace_callback('/&(?:[A-Za-z]+|#\d+);/', $blank, $out);
    }

    /**
     * Every text node, and every user-visible attribute value, with offsets.
     *
     * @param  list<string>  $visible
     * @return list<array{string, int, string}>
     */
    private function textAndAttributes(string $source, array $visible): array
    {
        $found = [];
        $length = \strlen($source);
        $textStart = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] !== '<') {
                continue;
            }

            $close = self::tagEnd($source, $i);

            if ($close === null) {
                break;
            }

            if ($i > $textStart) {
                $found[] = [substr($source, $textStart, $i - $textStart), $textStart, 'text'];
            }

            $tag = substr($source, $i, $close - $i + 1);

            foreach ($visible as $attribute) {
                /*
                 | A PLAIN attribute: `title="..."`, where the value is text.
                 |
                 | `(?<![:\w.-])` is what keeps this off Blade's bound form.
                 | `:title="$invoice->number"` is a component prop carrying a
                 | PHP expression, not a tooltip carrying words, and reading
                 | its source as text reported ten correct pages as broken.
                 */
                if (preg_match(
                    '/(?<![:\w.\-])'.preg_quote($attribute, '/').'\s*=\s*"([^"]*)"/',
                    $tag,
                    $m,
                    PREG_OFFSET_CAPTURE,
                ) === 1) {
                    $found[] = [$m[1][0], $i + $m[1][1], $attribute.' attribute'];
                }

                /*
                 | The BOUND form: `:title="EXPR"`.
                 |
                 | Skipping it would be the easy fix and would open the hole
                 | this rule exists to close, because `:title="'نص'"` is a
                 | literal reaching the user by another route. So the
                 | expression is judged the way a Blade echo is: a quoted
                 | string with letters in it, and no call to the translator
                 | anywhere in the expression, is the violation.
                 */
                if (preg_match(
                    '/(?<![\w.\-]):'.preg_quote($attribute, '/').'\s*=\s*"([^"]*)"/',
                    $tag,
                    $m,
                    PREG_OFFSET_CAPTURE,
                ) !== 1) {
                    continue;
                }

                $expression = $m[1][0];

                if (str_contains($expression, '__(') || str_contains($expression, 'trans(')) {
                    continue;
                }

                if (preg_match('/([\'"])((?:(?!\1).)*\p{L}{2,}(?:(?!\1).)*)\1/u', $expression, $literal) === 1) {
                    $found[] = [$literal[2], $i + $m[1][1], ':'.$attribute.' expression'];
                }
            }

            $i = $close;
            $textStart = $close + 1;
        }

        if ($textStart < $length) {
            $found[] = [substr($source, $textStart), $textStart, 'text'];
        }

        return $found;
    }

    /**
     * Where a tag ends, skipping over quoted attribute values.
     *
     * `strpos($source, '>')` was wrong the moment a tag could contain a PHP
     * expression: `:title="$party->legal_name"` has a `>` inside the quotes,
     * so the tag was cut in half and the remainder - `legal_name">` - was
     * reported as untranslated text. The line number was right and the
     * finding was nonsense, which is the worst kind of false positive,
     * because the next person deletes the rule rather than the bug.
     */
    private static function tagEnd(string $source, int $from): ?int
    {
        $length = \strlen($source);
        $quote = null;

        for ($i = $from; $i < $length; $i++) {
            $character = $source[$i];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '>') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The compiled CSS must be newer than every template it was compiled
     * from.
     *
     * Tailwind emits only the utilities it finds in source, so a build that
     * ran before a template was written produces a page that renders and is
     * missing exactly the classes that template introduced. The failure is
     * nasty because it is partial: the text is all there, the layout roughly
     * holds, and the card has no background. It looks like a styling opinion
     * rather than a broken deploy.
     *
     * This happened while building the first page in this repository, which
     * is why the check exists. It compares modification times rather than
     * parsing class names - cheap, and it cannot be fooled by a utility that
     * happens to appear in two templates.
     *
     * @return list<string>
     */
    public function builtAssetsAreNotStale(): array
    {
        $manifest = $this->basePath.'/public/build/manifest.json';

        if (! is_file($manifest)) {
            // Not built at all is a deployment concern, not an architecture
            // violation - a fresh clone has no build and that is correct.
            return [];
        }

        $builtAt = (int) filemtime($manifest);
        $newest = null;
        $newestAt = 0;

        foreach (['resources/views', 'resources/css', 'resources/js'] as $directory) {
            foreach ($this->filesMatching($directory, '/\.(blade\.php|css|js)$/') as $file) {
                $at = (int) filemtime($file);

                if ($at > $newestAt) {
                    $newestAt = $at;
                    $newest = $file;
                }
            }
        }

        if ($newest === null || $newestAt <= $builtAt) {
            return [];
        }

        return [sprintf(
            '%s changed %d second(s) after the last asset build, so the compiled CSS is missing any utility '.
            'it introduced. Run `npm run build`. The symptom is a page that renders with some styles silently '.
            'absent, which reads as a design choice rather than a broken deploy.',
            $this->relative($newest),
            $newestAt - $builtAt,
        )];
    }

    /**
     * Every migration says whether it may run while serving traffic.
     *
     * ## Why a declaration and not only an inspector
     *
     * `MigrationSafetyInspector` flags the hazards it knows: a dropped
     * column, a non-concurrent index, a rewrite. A migration it does not
     * flag is not thereby safe - it is unexamined, and the two look
     * identical from the outside. Measured when this was written: 64 of 137
     * migrations carried no declaration at all, including nine that add a
     * CHECK constraint to a table that already exists, which scans every row
     * under ACCESS EXCLUSIVE.
     *
     * The declaration is what makes the author decide. Writing
     * `@online-safe` next to an `ALTER TABLE ... ADD CONSTRAINT` is a claim
     * somebody can be shown to be wrong about; writing nothing is not.
     *
     * ## The three markers
     *
     * `@online-safe` with a reason - it may run against live traffic.
     * `@online-unsafe` with a reason - it may not, and the reason names what
     * it locks, so a deployment can schedule it.
     * `@unsafe-migration-reviewed` - a hazard the inspector flagged that is
     * genuinely fine here, with why.
     *
     * Each must carry text after it. A bare marker is the same as none,
     * except that it silences this.
     *
     * @return list<string>
     */
    public function everyMigrationDeclaresItsSafety(): array
    {
        $files = array_merge(
            glob($this->basePath.'/database/migrations/*.php') ?: [],
            glob($this->basePath.'/database/migrations/tenant/*.php') ?: [],
        );

        if ($files === []) {
            return ['no migration files were found at all, so this check just asserted something '.
                'about an empty list'];
        }

        $problems = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            $name = basename((string) $file);

            if (preg_match('/@(online-safe|online-unsafe|unsafe-migration-reviewed)\s*:?\s*(\S.*)?$/m', $source, $m) !== 1) {
                $problems[] = sprintf(
                    '%s declares nothing about running while traffic is served. The inspector only '.
                    'flags the hazards it knows, so a migration it does not flag is unexamined '.
                    'rather than safe - and the two are indistinguishable from outside. Add '.
                    '@online-safe, @online-unsafe or @unsafe-migration-reviewed with a reason',
                    $name,
                );

                continue;
            }

            if (trim((string) ($m[2] ?? '')) === '') {
                $problems[] = sprintf(
                    '%s carries a bare @%s with no reason after it, which says the same as nothing '.
                    'except that it silences this check',
                    $name,
                    $m[1],
                );
            }
        }

        return $problems;
    }

    /**
     * No migration may carry an unreviewed hazard - PF-023.
     *
     * The command refuses at run time, which protects production. This
     * protects the pull request, which is where it is cheap: a reviewer
     * reading `->change()` has to know that it rewrites the table and holds
     * ACCESS EXCLUSIVE, and on a fifty-line migration they will not notice.
     *
     * A hazard that is genuinely fine here is annotated with
     * `@unsafe-migration-reviewed: why`, which puts the reason next to the
     * statement instead of in a shell history nobody can read.
     *
     * @return list<string>
     */
    public function migrationsAreSafeToRunLive(): array
    {
        $inspector = new MigrationSafetyInspector();

        $files = array_merge(
            glob($this->basePath.'/database/migrations/*.php') ?: [],
            glob($this->basePath.'/database/migrations/tenant/*.php') ?: [],
        );

        return array_map(
            static fn (MigrationHazard $hazard): string => sprintf(
                '%s %s: %s - it %s. Instead: %s',
                $hazard->location(),
                $hazard->name,
                $hazard->statement,
                $hazard->does,
                $hazard->instead,
            ),
            $inspector->blocking($inspector->inspect($files)),
        );
    }

    /**
     * The allowed dependencies, read from `deptrac.yaml`.
     *
     * Parsed with a small reader rather than a YAML library, because none is
     * installed and the shape here is fixed: a `ruleset:` block of
     * `Module:` keys each followed by a list of `- Dependency` lines. A
     * module with `~` depends on nothing.
     *
     * If the file cannot be read the rule returns an empty map, which makes
     * every cross-module reference a violation - the safe direction to fail,
     * since a silently permissive architecture check is worse than a noisy
     * one.
     *
     * @return array<string, list<string>>
     */
    private function deptracRuleset(): array
    {
        $file = $this->basePath.'/deptrac.yaml';

        if (! is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $ruleset = [];
        $current = null;
        $inRuleset = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*ruleset:\s*$/', $line) === 1) {
                $inRuleset = true;

                continue;
            }

            if (! $inRuleset) {
                continue;
            }

            // A comment or a blank line never ends the block, and never
            // starts a module either.
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (preg_match('/^\s{4}([A-Z][A-Za-z]*):\s*(~)?\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $ruleset[$current] = [];

                continue;
            }

            if ($current !== null && preg_match('/^\s{6}-\s*([A-Z][A-Za-z]*)\s*$/', $line, $m) === 1) {
                $ruleset[$current][] = $m[1];
            }
        }

        return $ruleset;
    }

    /**
     * Nothing under `app/Tenant` may reference `App\Platform`.
     *
     * The dependency runs one way: Platform knows about Identity because it
     * provisions and administers tenants; Identity must not know a platform
     * database exists. Reverse it and a tenant-plane service reads platform
     * state directly, which makes the tenant plane unrunnable without it -
     * and the Exchange plane, which runs on queues with no platform
     * connection pointed, is where that surfaces.
     *
     * Deptrac compiles this rule too, and is not installed here. A plane
     * boundary that only exists in a config file nobody can run is not a
     * boundary.
     *
     * Where tenant-plane code genuinely needs a platform-held value - the
     * risk ceilings - it depends on an interface in `App\Support` that the
     * container binds to the platform implementation.
     *
     * @return list<string>
     */
    public function tenantDoesNotDependOnPlatform(): array
    {
        $violations = [];

        foreach ($this->filesMatching('app/Tenant', '/\.php$/') as $file) {
            $source = (string) file_get_contents($file);
            $offset = 0;

            while (($position = strpos($source, 'App\\Platform\\', $offset)) !== false) {
                if (! $this->lineIsAnnotated($source, $position)) {
                    $violations[] = sprintf(
                        '%s:%d references App\\Platform directly; depend on an interface in App\\Support and '.
                        'bind the platform implementation in the container',
                        $this->relative($file),
                        $this->lineAt($source, $position),
                    );
                }

                $offset = $position + 1;
            }
        }

        return $violations;
    }

    /**
     * Every ceiling must be read by something that refuses.
     *
     * A ceiling nobody enforces is a comment with a number in it, and the
     * console displays it as though it bounds something - which tells an
     * operator the system is limited when it is not, and they will repeat
     * that to a customer.
     *
     * The seeder is the register; this checks each key appears somewhere in
     * `app/` other than the seeder and the defaults table.
     *
     * @return list<string>
     */
    public function ceilingsAreEnforced(): array
    {
        $seeder = $this->basePath.'/database/seeders/PlatformSettingSeeder.php';

        if (! is_file($seeder)) {
            return [];
        }

        if (preg_match_all("/'(ceiling\.[a-z0-9_.]+)'/", (string) file_get_contents($seeder), $matches) === 0) {
            return [];
        }

        $sources = [];

        foreach ($this->filesMatching('app', '/\.php$/') as $file) {
            // The defaults table in the ceilings service names every key by
            // definition, so it cannot count as an enforcer.
            if (str_contains($this->relative($file), 'PlatformCeilings.php')) {
                continue;
            }

            $sources[] = (string) file_get_contents($file);
        }

        $haystack = implode("\n", $sources);
        $violations = [];

        foreach (array_unique($matches[1]) as $key) {
            if (! str_contains($haystack, $key)) {
                $violations[] = sprintf(
                    '"%s" is seeded as a ceiling but nothing reads it, so the console would display a limit '.
                    'that limits nothing',
                    $key,
                );
            }
        }

        return $violations;
    }

    /**
     * The same source with every comment blanked out.
     *
     * Tokenised rather than regexed: `//` inside a string literal is not a
     * comment, and `$url = 'https://example.test'` would otherwise lose the
     * rest of its line and take any real violation after it with it.
     *
     * Each comment is replaced by spaces of the same length, so byte offsets
     * - and therefore reported line numbers - are unchanged.
     */
    private function withoutComments(string $source): string
    {
        $tokens = @token_get_all($source);

        if ($tokens === false) {
            // Unparseable: scan it as-is rather than silently skipping a file
            // that might be where the violation is.
            return $source;
        }

        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the newlines so line numbers survive.
                    $result .= preg_replace('/[^\n]/', ' ', $token[1]);

                    continue;
                }

                $result .= $token[1];

                continue;
            }

            $result .= $token;
        }

        return $result;
    }

    /**
     * A missing translation key does NOT throw - it silently prints the source
     * string. That is why this needs a test rather than a code review.
     *
     * @return list<string>
     */
    public function translationCatalogsAgree(): array
    {
        $violations = [];
        $ar = $this->json('lang/ar.json');
        $en = $this->json('lang/en.json');

        if ($ar === null || $en === null) {
            return ['lang/ar.json or lang/en.json is missing or unreadable'];
        }

        foreach (array_diff(array_keys($ar), array_keys($en)) as $key) {
            $violations[] = sprintf('"%s" is in ar.json but missing from en.json', $key);
        }

        foreach (array_diff(array_keys($en), array_keys($ar)) as $key) {
            $violations[] = sprintf('"%s" is in en.json but missing from ar.json', $key);
        }

        foreach (['lang/ar.json' => $ar, 'lang/en.json' => $en] as $name => $catalog) {
            $keys = array_keys($catalog);
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            if ($keys !== $sorted) {
                $violations[] = $name.' is not sorted';
            }
        }

        // An English value left as Arabic is the commonest symptom of a
        // hurried copy, and it renders perfectly.
        foreach ($en as $key => $value) {
            if (preg_match('/\p{Arabic}/u', (string) $value)) {
                $violations[] = sprintf('en.json still has Arabic text for "%s"', $key);
            }
        }

        /*
         | Every `__()` key in the source must exist in both catalogs - and
         | the SOURCE includes the templates.
         |
         | This rule scanned `app/` only, which is where the fewest
         | user-facing strings are. A view key missing from a catalog is
         | exactly the failure the rule exists to prevent: `__()` does not
         | throw, it prints the key, so the English page silently shows
         | Arabic - and the people who would report it are the ones not
         | reading that page.
         */
        $sources = array_merge(
            $this->phpFiles('app'),
            $this->filesMatching('resources/views', '/\.blade\.php$/'),
        );

        foreach ($sources as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $m)) {
                foreach ($m[1] as $key) {
                    $key = str_replace("\\'", "'", $key);

                    if (! array_key_exists($key, $ar)) {
                        $violations[] = sprintf('%s uses __("%s") which is missing from ar.json', $this->relative($file), $key);
                    }

                    if (! array_key_exists($key, $en)) {
                        $violations[] = sprintf('%s uses __("%s") which is missing from en.json', $this->relative($file), $key);
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function strictTypesDeclared(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'declare(strict_types=1)')) {
                $violations[] = $this->relative($file).' is missing declare(strict_types=1)';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function moduleNames(): array
    {
        $dir = $this->basePath.'/app/Tenant';

        if (! is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) && $entry[0] !== '.' && is_dir($dir.'/'.$entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDir): array
    {
        return $this->filesMatching($relativeDir, '/\.php$/');
    }

    /** @return list<string> */
    private function filesMatching(string $relativeDir, string $pattern): array
    {
        $dir = $this->basePath.'/'.$relativeDir;

        if (! is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function json(string $relative): ?array
    {
        $path = $this->basePath.'/'.$relative;

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * A deliberate, annotated exception.
     *
     * The annotation has to be on the same line, so an exception is visible at
     * the point of use rather than granted wholesale at the top of a file.
     */
    /**
     * A tree is read as a half-open range, not with a pattern match - PF-037.
     *
     * ## Why this is a rule and not a preference
     *
     * The two forms return the same rows and are not the same query.
     * Measured on a chart of 5,055 accounts, asking for the 100 under `1.1`:
     *
     *   path LIKE '1.1.%'                  no index, 4,955 rows discarded
     *   path >= '1.1.' AND path < '1.1/'   index scan, 0 discarded
     *
     * The LIKE reads every account in the entity and throws away 98% of
     * them, so its cost grows with the size of the CHART rather than the
     * size of the subtree. On this data it takes 1.75 ms and looks fine; on
     * a chart of 50,000 it reads 50,000 rows to return ten, on every
     * roll-up, in every report.
     *
     * The prefix optimization does not rescue it: `path` is `varchar` and the
     * pattern operator takes `text`, so the comparison is against a cast and
     * the planner derives no bounds - true even with a literal pattern.
     *
     * The benchmark catches this too, by measuring the service's own SQL.
     * This rule catches it without a database, which is the difference
     * between finding out on the next commit and finding out on the next
     * benchmark run.
     *
     * @return list<string>
     */
    public function subtreesAreReadAsRanges(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);

            if (! str_contains($source, 'path')) {
                continue;
            }

            /*
             | Three spellings, because there are three ways to write it: the
             | query builder's operator, a raw SQL LIKE, and the `ilike`
             | nobody should be using on an identifier at all.
             */
            $patterns = [
                '/[\'"]path[\'"]\s*,\s*[\'"](?:like|ilike|not like)[\'"]/i',
                '/\bpath\s+(?:I?LIKE|SIMILAR TO)\b/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$match, $offset]) {
                    if ($this->lineIsAnnotated($original, $offset)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d matches a path with "%s". A subtree is the half-open range from '.
                        '`path || \'.\'` to `path || \'/\'`, which the index serves; the LIKE reads '.
                        'every account in the entity and discards the ones outside the subtree - 4,955 '.
                        'of 5,055 when measured. Use AccountTree, or annotate the line if this is not a '.
                        'subtree read',
                        $this->relative($file),
                        $this->lineOf($original, $offset),
                        trim($match),
                    );
                }
            }
        }

        return $violations;
    }


    /**
     * An ordered relation is `reorder()`ed, never given a second order.
     *
     * ## The rule
     *
     * **A relation method that imposes its own order must be `reorder()`ed
     * by any caller that wants a different one.** Appending `orderBy()` to
     * it adds a second clause rather than replacing the first.
     *
     * ## Why this one is worth a rule
     *
     * Because it fails silently and plausibly. Seventeen relations here
     * impose an order on purpose - a document's lines come back in line
     * order, a fiscal year's periods in ordinal order - and that is good:
     * the caller should not have to remember. The trap is the caller who
     * wants the other end:
     *
     *     $account->statements()->orderByDesc('sequence')->first()
     *
     * compiles to `ORDER BY sequence ASC, sequence DESC`. The first clause
     * wins, and `first()` returns the OLDEST row while reading as though it
     * asked for the newest. Nothing errors, nothing logs, and the value is
     * of the right type and shape.
     *
     * Found in PF-146's own reconciliation: `BankBalances` asked for the
     * latest statement, got the first one, and every reconciliation after
     * the second import measured against the account's opening statement
     * for ever. The figures were plausible, internally consistent, and
     * wrong.
     *
     * `reorder('sequence', 'desc')` is the correct spelling. Not going
     * through the relation at all is also fine, and is what the fix did -
     * a `reorder()` is one word away from being forgotten by the next
     * caller.
     *
     * @return list<string>
     */
    public function orderedRelationsAreReordered(): array
    {
        $ordered = $this->relationsThatImposeAnOrder();

        if ($ordered === []) {
            return ['no ordered relation was found at all, so this rule is checking nothing'];
        }

        $violations = [];
        $orderings = ['->orderBy(', '->orderByDesc(', '->latest(', '->oldest('];

        foreach ([...$this->phpFiles('app'), ...$this->phpFiles('tests')] as $file) {
            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);

            foreach ($ordered as $relation => $declaringModels) {
                $needle = '->'.$relation.'()';
                $offset = 0;

                while (($at = strpos($source, $needle, $offset)) !== false) {
                    $offset = $at + \strlen($needle);

                    /*
                     | The chain, as far as the statement's end.
                     |
                     | The ordering call is rarely adjacent: the real case
                     | had two `where()`s and a line break between the
                     | relation and the `orderByDesc`.
                     */
                    $terminator = strpos($source, ';', $at);
                    $chain = substr($source, $at, ($terminator === false ? \strlen($source) : $terminator) - $at);

                    // The declaration itself, not a call site.
                    if (str_contains($chain, 'hasMany(') || str_contains($chain, 'hasOne(')) {
                        continue;
                    }

                    if (str_contains($chain, '->reorder(')) {
                        continue;
                    }

                    $found = null;

                    foreach ($orderings as $ordering) {
                        if (str_contains($chain, $ordering)) {
                            $found = $ordering;

                            break;
                        }
                    }

                    if ($found === null || $this->lineIsAnnotated($original, $at)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d orders the result of %s(), which %s already orders. The clauses are '.
                        'appended, not replaced, so the first one wins and the query quietly returns '.
                        'the opposite end of the set - use reorder() or query the model directly, or '.
                        'annotate the line',
                        $this->relative($file),
                        $this->lineOf($original, $at),
                        $relation,
                        implode(' and ', $declaringModels),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Relation methods that impose their own order.
     *
     * @return array<string, list<string>>  relation name => the models declaring it
     */
    private function relationsThatImposeAnOrder(): array
    {
        $ordered = [];

        foreach ($this->phpFiles('app') as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));

            if (! str_contains($source, 'orderBy')) {
                continue;
            }

            $pattern = '/function\s+(\w+)\s*\([^)]*\)[^{;]*\{\s*'.
                       'return\s+\$this->(?:hasMany|hasOne)\([^;]*?->orderBy/s';

            if (preg_match_all($pattern, $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $relation) {
                /*
                 | Several models declare `lines()`, and each orders it.
                 |
                 | Collected rather than overwritten so the message names
                 | every model it could be - the call site reads
                 | `$invoice->lines()`, and being told that SalesOrder
                 | orders `lines()` sends somebody to the wrong file.
                 */
                $ordered[$relation] = array_values(array_unique([
                    ...($ordered[$relation] ?? []),
                    $this->relative($file),
                ]));
            }
        }

        return $ordered;
    }

    private function lineIsAnnotated(string $source, int $offset): bool
    {
        $lineStart = (int) strrpos(substr($source, 0, $offset), "\n");
        $lineEnd = strpos($source, "\n", $offset);
        $line = substr($source, $lineStart, ($lineEnd === false ? strlen($source) : $lineEnd) - $lineStart);

        return str_contains($line, '@arch-allow');
    }

    /**
     * A check suite that writes to the ledger ends with the global proof - PF-054.
     *
     * ## This rule IS the wiring
     *
     * PF-054 asks that `ledger:verify` run as the final assertion of every
     * suite that posts, "wired into the test case rather than remembered per
     * test". A trait makes it one line; it does not make it wired - the next
     * suite somebody writes can simply not call it, and nothing notices until
     * an invariant breaks somewhere else entirely.
     *
     * So forgetting it is a build failure. That is the only form of "wired in"
     * that survives the person who wrote the convention leaving.
     *
     * ## One exemption, and it is conditional
     *
     * A benchmark that disables the write-side triggers to seed millions of
     * rows is building a ledger that deliberately lacks fingerprints and
     * recorded period states - running the proof over it would report all of
     * that, correctly, as noise. It is exempt only while it does that;
     * a benchmark posting through the real path has the same obligation as a
     * check, because it is writing a ledger and claiming something about it.
     *
     * ## Why the proof cannot live in the runner instead
     *
     * Every suite rolls back its transaction in a `finally`, so by the time
     * `bin/verify` saw the results there would be nothing left to verify. The
     * proof has to be the last thing the suite does while its data still
     * exists, which means it has to be inside the suite.
     *
     * @return list<string>
     */
    public function postingSuitesProveTheLedger(): array
    {
        $violations = [];

        foreach ($this->phpFiles('tests/Support') as $file) {
            $original = (string) file_get_contents($file);
            $source = $this->withoutComments($original);

            /*
             | "Writes to journal_lines" is the test, and it is deliberately
             | textual: a suite that names the table is a suite that puts
             | figures in the ledger, whichever helper it goes through.
             */
            if (! str_contains($source, "'journal_lines'") && ! str_contains($source, 'journal_lines')) {
                continue;
            }

            // The trait itself, and the rules file, name the table in prose.
            if (str_contains($source, 'trait ProvesTheLedger')
                || str_contains($source, 'postingSuitesProveTheLedger')) {
                continue;
            }

            /*
             | A benchmark that disables the write-side triggers is exempt,
             | and only then.
             |
             | It seeds millions of rows with the triggers off because at that
             | scale a per-line increment measures the trigger rather than the
             | read path - so the ledger it builds deliberately lacks
             | fingerprints and recorded period states. Running the global
             | proof over it would report all of that, correctly, and the
             | report would be noise.
             |
             | The exemption is conditional rather than "benchmarks are
             | exempt", because otherwise extending Benchmark is a way to dodge
             | the rule. A benchmark that posts through the real path has the
             | same obligation as a check: it is writing a ledger and claiming
             | something about it.
             */
            if (str_contains($source, 'extends Benchmark') && str_contains($source, 'DISABLE TRIGGER')) {
                continue;
            }

            if (str_contains($source, 'ProvesTheLedger')) {
                if (! str_contains($source, 'withTheLedgerProof') && ! str_contains($source, 'theLedgerProof')) {
                    $violations[] = sprintf(
                        '%s imports the ProvesTheLedger trait and never calls it, so the proof does not '.
                        'run',
                        $this->relative($file),
                    );
                }

                continue;
            }

            $violations[] = sprintf(
                '%s writes to journal_lines and does not end with the global proof. An individual check '.
                'passes even when the posting went to the wrong account, went in twice, or left an entry '.
                'whose fingerprint no longer matches - because it only looks at what its author thought '.
                'to look at. Use the ProvesTheLedger trait and wrap the returned checks in '.
                'withTheLedgerProof()',
                $this->relative($file),
            );
        }

        return $violations;
    }

    /**
     * A bank account's full number is read in ONE place - PF-035.
     *
     * ## Why the rule rather than trusting the call sites
     *
     * `PartyBankAccount::revealIban()` decrypts and returns a supplier's
     * account number. Who LOOKED at it is an auditable event, not only who
     * changed it: the fraud this guards against needs no write at all, and
     * the change log would show nothing because nothing changed.
     *
     * So the audit entry lives in `BankDetailDisclosure`, and this rule
     * confines the raw accessor's callers to it. Without the rule, the future
     * payment-file builder reaches for the model - which is the natural thing
     * to do, is one line, and reads a supplier's account with no trace.
     *
     * ## The model keeps the raw method
     *
     * Rather than the decryption moving into the service. The model is where
     * the encryption lives and splitting the pair across two classes would
     * put the key handling further from the field it protects. The accessor
     * being public and unaudited is the trade, and this rule is the price
     * paid for it.
     *
     * @return list<string>
     */
    public function bankReadsAreAudited(): array
    {
        $violations = [];

        foreach ($this->phpFiles('app') as $file) {
            $original = (string) file_get_contents($file);
            $relative = $this->relative($file);

            // The model declares them; the disclosure service is the one
            // caller the rule exists to permit.
            if (str_ends_with($relative, 'Models/PartyBankAccount.php')
                || str_ends_with($relative, 'Services/BankDetailDisclosure.php')) {
                continue;
            }

            $source = $this->withoutComments($original);

            if (preg_match_all(
                '/->(revealIban|revealAccountNumber)\s*\(/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) === 0) {
                continue;
            }

            foreach ($matches[1] as [$method, $offset]) {
                if ($this->lineIsAnnotated($original, $offset)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d calls %s() directly. Who LOOKED at a bank account is an auditable event - the '.
                    'fraud this guards against needs no write, and the change log would show nothing '.
                    'because nothing changed. Read it through BankDetailDisclosure, which records the '.
                    'read, the actor and the reason without copying the number into the log',
                    $relative,
                    $this->lineOf($original, $offset),
                    $method,
                );
            }
        }

        return $violations;
    }

    /** The 1-based line a byte offset falls on. */
    private function lineOf(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/');
    }
}
