<?php

declare(strict_types=1);

namespace Tests\Support;

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
            'only three decimal scales exist' => $this->onlyThreeScales(),
            'tenant models declare their connection' => $this->tenantModelsDeclareConnection(),
            'Reporting never writes' => $this->reportingNeverWrites(),
            'no module reaches into another module\'s models' => $this->noCrossModuleModelAccess(),
            'no physical CSS property' => $this->noPhysicalCssProperties(),
            'financial tables carry entity_id' => $this->financialTablesCarryEntityId(),
            'translation catalogs agree' => $this->translationCatalogsAgree(),
            'strict types everywhere' => $this->strictTypesDeclared(),
        ];
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
            $source = (string) file_get_contents($file);
            $relative = $this->relative($file);

            // A float type declaration on a parameter, property or return.
            if (preg_match_all('/(?:^|[\s(,])(?:\?)?(?:float|double)(?:\s+\$|\s*\))/i', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    if ($this->lineIsAnnotated($source, $offset)) {
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
                    if (! $this->lineIsAnnotated($source, $pos)) {
                        $violations[] = sprintf('%s:%d uses %s', $relative, $this->lineAt($source, $pos), $needle);
                    }
                    $pos += strlen($needle);
                }
            }

            // An Eloquent cast to float.
            if (preg_match_all('/=>\s*[\'"](?:float|real|double)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    $violations[] = sprintf('%s:%d casts an attribute to float', $relative, $this->lineAt($source, $offset));
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
     * Modules communicate through domain events and application services. A
     * module that reaches into another's models, traits, scopes or enums has
     * dissolved the boundary that Deptrac compiles.
     *
     * @return list<string>
     */
    public function noCrossModuleModelAccess(): array
    {
        $violations = [];
        $modules = $this->moduleNames();

        foreach ($modules as $module) {
            foreach ($this->phpFiles('app/Tenant/'.$module) as $file) {
                $source = (string) file_get_contents($file);

                foreach ($modules as $other) {
                    if ($other === $module) {
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
                                '%s references %s directly; go through a domain event or an application service',
                                $this->relative($file),
                                $needle,
                            );
                        }
                    }
                }
            }
        }

        return $violations;
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

            // Only migrations that declare themselves financial are held to
            // this. A lookup table does not need an entity.
            if (! str_contains($source, '@financial')) {
                continue;
            }

            if (! preg_match('/entity_id/', $source)) {
                $violations[] = $this->relative($file).' is marked @financial but declares no entity_id';
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

        // Every __() key in the source must exist in both catalogs.
        foreach ($this->phpFiles('app') as $file) {
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
    private function lineIsAnnotated(string $source, int $offset): bool
    {
        $lineStart = (int) strrpos(substr($source, 0, $offset), "\n");
        $lineEnd = strpos($source, "\n", $offset);
        $line = substr($source, $lineStart, ($lineEnd === false ? strlen($source) : $lineEnd) - $lineStart);

        return str_contains($line, '@arch-allow');
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/');
    }
}
