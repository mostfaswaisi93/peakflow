<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config\Exceptions\ConfigurationIsUnsafe;
use App\Support\Config\ProductionConfiguration;

/**
 * The configuration skeleton, and the values production is not allowed to get
 * wrong - PF-012.
 *
 * ## Why `.env.example` needs a rule at all
 *
 * It is the file a fresh clone copies, and it is the only documentation of
 * what a deployment can set that anybody reads. A key read by a config file
 * and absent from it is invisible: the default applies, the application works,
 * and nobody knows the knob exists until an incident makes them go looking.
 *
 * 153 keys are read by `config/*.php` and 76 are set. The other 77 are mostly
 * drivers PeakFlow does not use, and setting them all to their own defaults
 * would add ninety lines that say nothing. So exclusion is DECLARED rather
 * than implied: a `# UNUSED:` line names the key, which makes leaving it out a
 * visible decision in a diff rather than an omission nobody can distinguish
 * from an oversight.
 *
 * The mirror rule matters as much. A key set in `.env.example` that no config
 * file reads sits there for years, and the next person sets it and wonders why
 * nothing happens - so those are declared too, with what reads them or the
 * task that will.
 *
 * ## And why the safe ranges are code
 *
 * PF-012 asks for a documented safe range and a platform-enforced ceiling for
 * risk-bearing values. A documented range that nothing enforces is a sentence
 * somebody read once during onboarding, so the ranges are
 * {@see ProductionConfiguration} and the enforcement is a boot that refuses.
 *
 * Every rule there is a value whose wrong setting is SILENT. That is the
 * selection criterion: a wrong database host fails loudly, while
 * `sslmode=prefer` works perfectly and sends every row in plaintext the one
 * time somebody can strip the TLS.
 */
final class ConfigurationRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every env key a config file reads is set or declared unused' => $this->everyReadKeyIsAccountedFor(),
            'every key set in .env.example is read by something' => $this->noDeadKeys(),
            'a key is not both set and declared unused' => $this->noKeyIsBothWays(),
            'the production guard catches each unsafe value' => $this->theGuardCatchesEachValue(),
            'a correct production configuration passes' => $this->aCorrectConfigurationPasses(),
            'the local configuration is deliberately one the guard rejects' => $this->theLocalConfigurationIsADevelopmentOne(),
            'the guard reports every problem at once' => $this->theGuardReportsAllAtOnce(),
            'the guard runs in production and nowhere else' => $this->theGuardIsWiredForProductionOnly(),
            'the documented safe ranges match the code' => $this->theDocumentedRangesMatchTheCode(),
        ];
    }

    /**
     * Every documented default must be the real one.
     *
     * The ranges in `docs/operations/configuration.md` are prose - a human
     * decides that 24 subset-sum candidates is the edge of reasonable. The
     * DEFAULT beside each range is not prose, and a table whose defaults have
     * drifted from the code is worse than no table: somebody reads it, plans
     * around 500ms, and the code says 5000.
     *
     * Only rows whose first cell is a dotted config path are read. The guard's
     * table above uses environment variable names, which are a different
     * thing and are asserted by the guard itself.
     *
     * @return list<string>
     */
    private function theDocumentedRangesMatchTheCode(): array
    {
        $problems = [];
        $path = \dirname(__DIR__, 2).'/docs/operations/configuration.md';

        if (! is_file($path)) {
            return ['docs/operations/configuration.md is missing, so PF-012\'s documented ranges are '.
                'nowhere.'];
        }

        $rows = 0;

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^\|\s*`([a-z_]+(?:\.[a-z_0-9]+)+)`\s*\|\s*`?([^`|]*)`?\s*\|/', trim($line), $matches) !== 1) {
                continue;
            }

            $rows++;
            $key = $matches[1];
            $documented = trim($matches[2]);

            $actual = config($key, '__missing__');

            if ($actual === '__missing__') {
                $problems[] = sprintf(
                    'The table documents %s and no config file defines it. A range for a setting that '.
                    'does not exist is advice nobody can follow.',
                    $key,
                );

                continue;
            }

            if (! $this->same($documented, $actual)) {
                $problems[] = sprintf(
                    'The table says %s defaults to `%s` and it is `%s`. Somebody reads the table, plans '.
                    'around the documented value, and the code does something else.',
                    $key,
                    $documented,
                    $this->render($actual),
                );
            }
        }

        /*
         | And the table has to have rows.
         |
         | A regular expression that stopped matching - because somebody
         | reformatted the table, or wrapped the keys differently - would make
         | this rule pass while checking nothing at all.
         */
        if ($rows < 30) {
            $problems[] = sprintf(
                'Only %d documented default(s) were read out of the table, and there should be about '.
                'forty. Either rows were deleted or the row pattern stopped matching - and a pattern '.
                'that matches nothing passes.',
                $rows,
            );
        }

        return $problems;
    }

    private function same(string $documented, mixed $actual): bool
    {
        if (is_bool($actual)) {
            return $documented === ($actual ? 'true' : 'false');
        }

        if ($actual === null) {
            return $documented === 'null';
        }

        if (is_numeric($documented) && (is_int($actual) || is_float($actual) || is_numeric((string) $actual))) {
            return abs((float) $documented - (float) (string) $actual) < 0.0000001;
        }

        return $documented === (string) $actual;
    }

    private function render(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => '['.implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : get_debug_type($v), $value)).']',
            default => (string) $value,
        };
    }

    /**
     * The source with every comment replaced by whitespace of the same length.
     *
     * Replaced rather than removed so that character offsets still line up -
     * the rule below reads a window of the file by position.
     */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $token[1]);

                continue;
            }

            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function keysReadByConfigFiles(): array
    {
        $read = [];

        foreach (glob(\dirname(__DIR__, 2).'/config/*.php') ?: [] as $file) {
            preg_match_all(
                '/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/',
                (string) file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $key) {
                $read[$key] = basename($file);
            }
        }

        return $read;
    }

    /** @return array{set: array<string, true>, unused: array<string, true>, setOnly: array<string, true>} */
    private function example(): array
    {
        $set = [];
        $unused = [];
        $setOnly = [];

        foreach (explode("\n", (string) file_get_contents(\dirname(__DIR__, 2).'/.env.example')) as $line) {
            $line = trim($line);

            if (preg_match('/^([A-Z0-9_]+)=/', $line, $matches) === 1) {
                $set[$matches[1]] = true;

                continue;
            }

            foreach (['# UNUSED:' => &$unused, '# SET-ONLY:' => &$setOnly] as $marker => &$bucket) {
                if (! str_starts_with($line, $marker)) {
                    continue;
                }

                /*
                 | Only the leading run of KEY-shaped words counts.
                 |
                 | A `# SET-ONLY:` line carries a note after the key, and the
                 | note contains words like PF-072 and `null`. Stopping at the
                 | first token that is not a key shape means the note cannot
                 | accidentally declare something.
                 */
                foreach (preg_split('/\s+/', trim(substr($line, \strlen($marker)))) ?: [] as $token) {
                    if (preg_match('/^[A-Z0-9_]+$/', $token) !== 1) {
                        break;
                    }

                    $bucket[$token] = true;
                }
            }

            unset($bucket);
        }

        return ['set' => $set, 'unused' => $unused, 'setOnly' => $setOnly];
    }

    /** @return list<string> */
    private function everyReadKeyIsAccountedFor(): array
    {
        $problems = [];
        $example = $this->example();

        foreach ($this->keysReadByConfigFiles() as $key => $file) {
            if (isset($example['set'][$key]) || isset($example['unused'][$key])) {
                continue;
            }

            $problems[] = sprintf(
                '%s is read by config/%s and is neither set in .env.example nor on a `# UNUSED:` line. '.
                'An unset key is invisible: the default applies, the application works, and nobody knows '.
                'the knob exists until an incident makes them go looking.',
                $key,
                $file,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function noDeadKeys(): array
    {
        $problems = [];
        $example = $this->example();
        $read = $this->keysReadByConfigFiles();

        foreach (array_keys($example['set']) as $key) {
            if (isset($read[$key]) || isset($example['setOnly'][$key])) {
                continue;
            }

            $problems[] = sprintf(
                '%s is set in .env.example and no file in config/ reads it. Either its config entry was '.
                'deleted - in which case the line sits here for years and the next person sets it and '.
                'wonders why nothing happens - or it is read by something else and belongs on a '.
                '`# SET-ONLY:` line with what reads it.',
                $key,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function noKeyIsBothWays(): array
    {
        $problems = [];
        $example = $this->example();

        foreach (array_keys(array_intersect_key($example['set'], $example['unused'])) as $key) {
            $problems[] = sprintf(
                '%s is both set and declared unused. One of the two statements is false, and a reader has '.
                'no way to know which.',
                $key,
            );
        }

        foreach (array_keys(array_intersect_key($example['setOnly'], $this->keysReadByConfigFiles())) as $key) {
            $problems[] = sprintf(
                '%s is on a `# SET-ONLY:` line and a config file now reads it. The declaration has '.
                'stopped being true, which is the moment to delete it rather than the moment it becomes '.
                'misleading.',
                $key,
            );
        }

        return $problems;
    }

    /**
     * Each unsafe value, on its own, must be caught.
     *
     * A guard tested only against a wholly wrong configuration passes while
     * eleven of its twelve rules do nothing. So each rule is exercised from a
     * configuration that is otherwise correct.
     *
     * @return list<string>
     */
    private function theGuardCatchesEachValue(): array
    {
        $problems = [];

        $cases = [
            'APP_DEBUG=true' => ['app.debug' => true],
            'an unset secure cookie' => ['session.secure' => null],
            'a non-secure cookie' => ['session.secure' => false],
            'a readable session cookie' => ['session.http_only' => false],
            'SameSite=None' => ['session.same_site' => 'none'],
            'an unencrypted session payload' => ['session.encrypt' => false],
            'a secure cookie over http' => ['session.secure' => true, 'app.url' => 'http://peakflow.test'],
            'sslmode=prefer on the platform connection' => ['database.connections.platform.sslmode' => 'prefer'],
            'sslmode=disable on the tenant connection' => ['database.connections.tenant.sslmode' => 'disable'],
            'sslmode=allow on the migrator connection' => ['database.connections.migrator.sslmode' => 'allow'],
            'a DOCTYPE allowed in XML' => ['exchange.xml.allow_dtd' => true],
            'external entities allowed' => ['exchange.xml.allow_external_entities' => true],
            'network access allowed from the parser' => ['exchange.xml.allow_network' => true],
            'query bindings in traces' => ['telemetry.database.record_bindings' => true],
            'the log exchange driver' => ['exchange.driver' => 'log'],
            'the stub PDF driver' => ['pdf.driver' => 'stub'],
            'PDF/A checking off with a real driver' => ['pdf.driver' => 'gotenberg', 'pdf.verapdf.enforce' => false],
            'a ZATCA sandbox with the profile on' => ['jurisdictions.enabled.zatca' => true, 'exchange.zatca.environment' => 'sandbox'],
            'migrations without a safety declaration' => ['tenancy.migrate.require_online_safety_declaration' => false],
            'webhooks allowed into private ranges' => ['exchange.webhooks.refuse_private_ranges' => false],
            'webhooks following redirects' => ['exchange.webhooks.follow_redirects' => true],
        ];

        foreach ($cases as $description => $overrides) {
            $found = ProductionConfiguration::problems(array_merge($this->safeProduction(), $overrides));

            if ($found === []) {
                $problems[] = sprintf(
                    '%s was not flagged. Every value in this guard is one whose wrong setting WORKS, so '.
                    'a rule that does not fire is a rule that is not there.',
                    $description,
                );
            }
        }

        return $problems;
    }

    /**
     * A configuration a real production deployment would have.
     *
     * @return array<string, mixed>
     */
    private function safeProduction(): array
    {
        return [
            'app.debug' => false,
            'app.url' => 'https://peakflow.example',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.encrypt' => true,
            'database.connections.platform.sslmode' => 'verify-full',
            'database.connections.tenant.sslmode' => 'verify-full',
            'database.connections.migrator.sslmode' => 'verify-full',
            'exchange.xml.allow_dtd' => false,
            'exchange.xml.allow_external_entities' => false,
            'exchange.xml.allow_network' => false,
            'telemetry.database.record_bindings' => false,
            'exchange.driver' => 'peppol',
            'pdf.driver' => 'gotenberg',
            'pdf.verapdf.enforce' => true,
            'jurisdictions.enabled.zatca' => false,
            'exchange.zatca.environment' => 'production',
            'tenancy.migrate.require_online_safety_declaration' => true,
            'exchange.webhooks.refuse_private_ranges' => true,
            'exchange.webhooks.follow_redirects' => false,
        ];
    }

    /** @return list<string> */
    private function aCorrectConfigurationPasses(): array
    {
        $found = ProductionConfiguration::problems($this->safeProduction());

        if ($found === []) {
            return [];
        }

        return [sprintf(
            "A correct production configuration was refused, which would be an outage rather than a ".
            "tightening:\n       %s",
            implode("\n       ", $found),
        )];
    }

    /**
     * The local configuration is one the guard rejects, and that is deliberate.
     *
     * A secure cookie cannot be sent over `http://localhost`, so a fresh clone
     * configured for production would not let anybody sign in. So this asserts
     * the opposite of what it looks like it should: the guard MUST object to
     * the configuration a developer is running.
     *
     * ## A subset, not an exact list
     *
     * The first version required exactly eight problems, named. That is a
     * stronger claim and a wrong one: it reads the LIVE configuration, which
     * comes from `.env`, which is not committed - so a developer who sets
     * `SESSION_ENCRYPT=true` locally would see this rule fail for being more
     * secure than expected. Noise, not signal.
     *
     * So each problem must be one of a known set, and the set must not be
     * empty. A new unsafe local value is named; a developer tightening theirs
     * is not punished for it.
     *
     * @return list<string>
     */
    private function theLocalConfigurationIsADevelopmentOne(): array
    {
        $problems = [];
        $found = ProductionConfiguration::problems();

        if ($found === []) {
            return ['The local configuration passes the production guard. Either every rule has stopped '.
                'firing, or this clone is configured for production - in which case the secure session '.
                'cookie cannot be sent over http://localhost and nobody can sign in.'];
        }

        $expected = [
            'APP_DEBUG',
            'SESSION_SECURE_COOKIE',
            'SESSION_HTTP_ONLY',
            'SESSION_SAME_SITE',
            'SESSION_ENCRYPT',
            'connection uses sslmode',
            'EXCHANGE_DRIVER',
            'PDF_DRIVER',
            'APP_URL',
        ];

        foreach ($found as $problem) {
            $recognised = false;

            foreach ($expected as $fragment) {
                if (str_contains($problem, $fragment)) {
                    $recognised = true;

                    break;
                }
            }

            if (! $recognised) {
                $problems[] = sprintf(
                    'The local configuration trips a rule that a development configuration should not: '.
                    '%s',
                    $problem,
                );
            }
        }

        /*
         | And the three that cannot be set away locally must still fire.
         |
         | DB_SSLMODE is absent from `.env.example` on purpose - a local
         | cluster has no certificate - so these three are the part of this
         | rule that does not depend on an uncommitted file.
         */
        foreach (['platform connection uses sslmode', 'tenant connection uses sslmode', 'migrator connection uses sslmode'] as $fragment) {
            $matched = false;

            foreach ($found as $problem) {
                if (str_contains($problem, $fragment)) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $problems[] = sprintf(
                    'The guard no longer objects to "%s". DB_SSLMODE is deliberately unset locally, so '.
                    'this rule firing does not depend on anybody\'s .env - which makes it the part of '.
                    'this check that cannot drift.',
                    $fragment,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theGuardReportsAllAtOnce(): array
    {
        $problems = [];

        $broken = array_merge($this->safeProduction(), [
            'app.debug' => true,
            'session.secure' => false,
            'session.encrypt' => false,
            'exchange.driver' => 'log',
        ]);

        $found = ProductionConfiguration::problems($broken);

        if (\count($found) < 4) {
            $problems[] = sprintf(
                'Four wrong values produced %d problem(s). A deployment with four should learn about '.
                'four, not about one per restart - the second is four deploys, and somewhere in the '.
                'middle of them somebody decides the guard is the problem.',
                \count($found),
            );
        }

        $message = ConfigurationIsUnsafe::because($found)->getMessage();

        foreach (['APP_DEBUG', 'SESSION_SECURE_COOKIE', 'SESSION_ENCRYPT', 'EXCHANGE_DRIVER'] as $fragment) {
            if (! str_contains($message, $fragment)) {
                $problems[] = sprintf(
                    'The refusal message does not mention %s, so somebody fixing it would have to deploy '.
                    'again to find out what else is wrong.',
                    $fragment,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theGuardIsWiredForProductionOnly(): array
    {
        $problems = [];

        /*
         | Comments blanked before anything is looked for.
         |
         | The first version used the raw source, and commenting the call out
         | left it passing - `// ProductionConfiguration::assert();` still
         | contains the string. A textual rule fooled by a comment is the
         | shape of bug it exists to catch.
         */
        $provider = $this->withoutComments(
            (string) file_get_contents(\dirname(__DIR__, 2).'/app/Providers/AppServiceProvider.php'),
        );

        if (! str_contains($provider, 'ProductionConfiguration::assert()')) {
            $problems[] = 'AppServiceProvider does not call ProductionConfiguration::assert(), so the '.
                'ranges are documented and enforced by nothing - which is the state PF-012 exists to '.
                'leave behind.';
        }

        if (! str_contains($provider, 'isProduction()')) {
            $problems[] = 'The guard is not gated on isProduction(). Every rule in it would make local '.
                'development impossible: a browser will not send a secure cookie over http://localhost, '.
                'so nobody could sign in.';
        }

        /*
         | And it must not be gated the other way.
         |
         | `if ($this->app->isProduction()) { return; }` is one character of
         | editing away and would disable the whole guard exactly where it is
         | needed. The file contains that shape legitimately, for the float
         | binding listener, so the check looks for the guard's own method.
         */
        $position = strpos($provider, 'refuseToStartOnUnsafeProductionSettings(): void');

        if ($position === false) {
            $problems[] = 'The guard method has been renamed or removed.';

            return $problems;
        }

        $body = substr($provider, $position, 400);

        if (! str_contains($body, 'if (! $this->app->isProduction()) {')) {
            $problems[] = 'The guard no longer returns early when NOT in production. Inverted, it runs '.
                'everywhere except where it matters.';
        }

        return $problems;
    }
}
