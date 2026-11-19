<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\DevelopmentSeeder;
use Illuminate\Support\Str;

/**
 * The repository conventions, and the one command that makes a clone work - PF-001, PF-011.
 *
 * ## Why the hook is tested by running it
 *
 * A commit-message hook is a shell script, and the only honest test of a shell
 * script is to run it. So each case below writes a message to a file and
 * invokes `.githooks/commit-msg` as git would - which also means the test
 * fails if the file loses its execute bit, which is the way a hook most often
 * stops working.
 */
final class RepositoryRules
{
    /**
     * Messages that must be accepted, and messages that must not.
     *
     * The first rejection is the shape this repository's own history took for
     * nine commits - `PF-006: ...` instead of `feat(iso): ...`. The hook did
     * not exist yet; it does now, and those nine are history that
     * CONTRIBUTING.md forbids rewriting.
     *
     * @var array<string, array{bool, string}>
     */
    private const MESSAGES = [
        'a conventional subject with a scope and a task' => [true, "feat(ledger): enforce the balanced-entry invariant\n\nRefs PF-043"],
        'a fix with a scope' => [true, "fix(banking): reject a statement with a continuity gap\n\nRefs PF-081"],
        'docs with no scope and no task' => [true, 'docs: explain why the registries are files'],
        'a chore' => [true, 'chore: bump the lockfile'],
        'a breaking change marker' => [true, "refactor(money)!: the rounding policy is required\n\nRefs PF-006"],
        'a summary starting with an acronym' => [true, "feat(iso): RFC 9457 problem types\n\nRefs PF-015"],
        'a merge commit' => [true, 'Merge branch main into pf-001'],
        'a fixup a rebase will fold away' => [true, 'fixup! feat(ledger): something'],

        'a bare task prefix, which is what this history did' => [false, 'PF-006: read a number somebody typed, or refuse it'],
        'a capitalised summary' => [false, "feat: Added the thing\n\nRefs PF-001"],
        'a full stop at the end' => [false, "feat(ledger): enforce the invariant.\n\nRefs PF-043"],
        'a non-blank second line' => [false, "feat(ledger): enforce the invariant\nRefs PF-043"],
        'a subject over 72 characters' => [false, "feat(ledger): this subject is deliberately far longer than seventy-two characters so it is refused\n\nRefs PF-043"],
        'a behaviour change with no task' => [false, 'feat(ledger): no task reference at all'],
        'a type that is not in the list' => [false, 'wip: something'],
        'a capitalised scope' => [false, "feat(Ledger): capitalised scope\n\nRefs PF-001"],
        'no type at all' => [false, 'just a sentence with no type'],
    ];

    private string $temporary = '';

    /**
     * The progress table in docs/tasks/README.md, against the phase files.
     *
     * ## Why this is a check and not a convention
     *
     * A progress table is read INSTEAD of the files it summarises - that is
     * what it is for - so one that has drifted is worse than none at all. And
     * it drifts silently: ticking a criterion is the last step of a task, the
     * table is somewhere else, and nothing about the tick suggests a second
     * edit.
     *
     * What is checked is the arithmetic, not the prose: the per-phase counts
     * of met and open criteria, and how many tasks have no open criterion
     * left. The written claims around the table - which phases have been
     * worked, why an open criterion in a worked phase is acceptable - are
     * judgement and belong to whoever wrote them.
     *
     * @return list<string>
     */
    public function theProgressTableIsHonest(): array
    {
        $violations = [];
        $readme = $this->base().'/docs/tasks/README.md';
        $contents = is_file($readme) ? (string) file_get_contents($readme) : '';

        if (! str_contains($contents, '## Progress')) {
            return ['docs/tasks/README.md has no progress table, and the phase files are the only '.
                'record of what is done'];
        }

        /*
         | Only the Progress section counts - PF-021.
         |
         | This searched the whole file, and the file opens with a Contents
         | table of the same shape: phase link, then numbers. So a Progress
         | row accidentally written into Contents satisfied the check while
         | the Progress table stayed stale AND the Contents row was
         | destroyed - which is exactly what happened, and the check said
         | nothing for two commits.
         |
         | Measured: with the row in the wrong table the suite was green,
         | and narrowing the search to the section turned it red on the
         | same file.
         */
        $progress = mb_substr($contents, (int) mb_strpos($contents, '## Progress'));

        /*
         | Both halves of every phase - PF-021.
         |
         | A finished task lives in `docs/tasks/done/`, so that a phase file
         | shows what is LEFT. The totals must not move when a task crosses
         | that line: the work was done either way, and a table that quietly
         | dropped 151 finished tasks the day they were filed would be the
         | most misleading thing in the repository.
         |
         | So the counts are summed over the pair, and the pairing is
         | asserted below - a phase with a `done` file and no phase file, or
         | the same task in both, is a split that went wrong.
         */
        $files = glob($this->base().'/docs/tasks/phase-*.md') ?: [];
        sort($files);

        $totals = ['complete' => 0, 'tasks' => 0, 'met' => 0, 'open' => 0];

        foreach ($files as $file) {
            $name = basename($file);
            $finished = $this->base().'/docs/tasks/done/'.$name;

            $source = (string) file_get_contents($file);

            if (is_file($finished)) {
                $source .= "\n".(string) file_get_contents($finished);
            }

            $phase = $this->phaseFileCounts($source);
            $number = substr($name, 6, 2);

            $totals['complete'] += $phase['complete'];
            $totals['tasks'] += $phase['tasks'];
            $totals['met'] += $phase['met'];
            $totals['open'] += $phase['open'];

            $expected = sprintf(
                '| [%s](%s) | %d / %d | %d | %d |',
                $number, $name, $phase['complete'], $phase['tasks'], $phase['met'], $phase['open'],
            );

            if (! str_contains($progress, $expected)) {
                $violations[] = sprintf(
                    'the progress table does not hold "%s". Phase %s has %d of %d tasks complete, %d '.
                    'criteria met and %d open, and the table is what people read instead of the file',
                    $expected, $number, $phase['complete'], $phase['tasks'], $phase['met'], $phase['open'],
                );
            }
        }

        $expected = sprintf(
            '| **all** | **%d / %d** | **%d** | **%d** |',
            $totals['complete'], $totals['tasks'], $totals['met'], $totals['open'],
        );

        if (! str_contains($progress, $expected)) {
            $violations[] = sprintf('the progress table\'s total row is not "%s"', $expected);
        }

        return $violations;
    }

    /**
     * A seeder invents its people; it never copies one - PF-239.
     *
     * ## Pseudonymised by the process that creates it
     *
     * Not by a cleanup afterwards, and the difference is the whole
     * criterion. A copy-then-anonymise pipeline has a window - between the
     * restore and the scrub - in which a developer's laptop holds a customer
     * list, and the window is exactly as long as whatever goes wrong that
     * morning. A seeder that invents its data has no window, because the
     * data was never real.
     *
     * ## Asserted on the addresses, because they are the identifying half
     *
     * A made-up name is a made-up name; an email address is routable. RFC
     * 2606 reserves `.test`, `.example` and `.invalid` so that they can
     * never be delegated, which makes an address under one provably nobody's
     * - and a seeder writing `@gmail.com` is writing an address that could
     * belong to a person, whether or not it does today.
     *
     * That is also why this is a source scan rather than a database one. By
     * the time a real address is in a table, the thing to assert about it is
     * no longer where it came from.
     *
     * ## And there is no scrub to depend on
     *
     * A script named for anonymising a restored database would mean the
     * product had chosen copy-then-clean after all, and this check would be
     * asserting the second half of a design the first half contradicts.
     *
     * @return list<string>
     */
    public function seedersInventTheirPeople(): array
    {
        $files = array_merge(
            glob($this->base().'/database/seeders/*.php') ?: [],
            glob($this->base().'/database/seeders/*/*.php') ?: [],
        );

        if ($files === []) {
            return ['no seeder was found, so this asserted nothing about an empty set'];
        }

        $reserved = ['.test', '.example', '.invalid', '.localhost'];
        $problems = [];
        $seen = 0;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = 'database/seeders/'.ltrim(str_replace($this->base().'/database/seeders', '', $file), '/');

            if (preg_match_all('/[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}/', $source, $matches) === 0) {
                continue;
            }

            foreach (array_unique($matches[0]) as $address) {
                $seen++;

                $domain = substr((string) $address, (int) strrpos((string) $address, '@'));

                foreach ($reserved as $suffix) {
                    if (str_ends_with($domain, $suffix)) {
                        continue 2;
                    }
                }

                $problems[] = sprintf(
                    '%s writes "%s", whose domain is not one RFC 2606 reserves. A reserved domain can '.
                    'never be delegated, so an address under one is provably nobody\'s - and this is '.
                    'an address that could belong to a person, whether or not it does today',
                    $name,
                    (string) $address,
                );
            }
        }

        if ($seen === 0) {
            $problems[] = 'no seeder writes an email address at all, so this check asserted nothing - '.
                'which is how a rule about seeded data comes to pass against a seeder that stopped '.
                'seeding people';
        }

        /*
         | And nothing anonymises a restored database.
         |
         | Such a script would mean the product had chosen copy-then-clean
         | after all, and this check would be asserting the second half of a
         | design its first half contradicts.
         */
        foreach (array_merge(glob($this->base().'/bin/*') ?: [], glob($this->base().'/database/*.php') ?: []) as $script) {
            if (! is_file($script)) {
                continue;
            }

            $contents = (string) file_get_contents($script);

            if (preg_match('/\b(anonymi[sz]e|scrub|obfuscate)[-_ ]?(production|prod|restore|dump|customers?)\b/i', $contents) === 1) {
                $problems[] = sprintf(
                    '%s looks like a scrub of restored production data. Copy-then-anonymise has a '.
                    'window between the restore and the scrub in which a laptop holds a customer '.
                    'list, and the window is as long as whatever goes wrong that morning',
                    basename((string) $script),
                );
            }
        }

        return $problems;
    }


    /**
     * Nothing in the repository looks like a secret - PF-234.
     *
     * ## Why the repository and not only the logs
     *
     * `LoggingRules` proves a secret does not survive into a log line, and
     * `ObservabilityRules` proves it does not reach a span attribute. Both
     * are about a value passing THROUGH the application. This is about one
     * that was committed - which is a different failure with a worse
     * property: a log rotates, and a git history does not. A key pushed once
     * is a key in every clone, every fork and every CI cache, and rotating
     * it is the only remedy because deleting the commit is not one.
     *
     * ## Shapes, not entropy
     *
     * An entropy threshold over every string in a codebase produces a list
     * of hashes, ULIDs, base64 fixtures and minified assets, and a check
     * whose output is mostly noise is a check somebody switches off. What is
     * here instead is the handful of shapes that are unambiguous: a PEM
     * private key header, the provider prefixes that are registered formats
     * (`AKIA`, `ghp_`, `xox...`), and an assignment of a long literal to a
     * name that says it is a credential.
     *
     * The cost of that choice is honest: a secret with no recognisable shape
     * - a bare password, a hex string - passes. This catches the ones that
     * announce themselves, which is most of them, and it never cries wolf.
     *
     * ## `.env.example` is scanned too
     *
     * It is the most likely file to acquire a real value, because it is the
     * one somebody edits while making their own `.env` work. Its placeholder
     * values are short and obviously placeholders, so they do not trip the
     * length rule.
     *
     * @return list<string>
     */
    private function nothingLooksLikeACommittedSecret(): array
    {
        $shapes = [
            'a PEM private key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
            'an AWS access key id' => '/\bAKIA[0-9A-Z]{16}\b/',
            'a GitHub token' => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36,}\b/',
            'a GitHub fine-grained token' => '/\bgithub_pat_[A-Za-z0-9_]{50,}\b/',
            'a Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
            'a Stripe secret key' => '/\bsk_(?:live|test)_[A-Za-z0-9]{20,}\b/',
            'a Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',

            /*
             | A credential-shaped NAME with a long literal after it.
             |
             | Twenty characters, because every placeholder in this
             | repository is shorter and every real key is longer. The name
             | list is what keeps it from matching an ordinary long string:
             | a path, a class name or a hash assigned to `$expected` is not
             | a credential however long it is.
             */
            'a long literal assigned to a credential-shaped name' =>
                '/\b(?:secret|password|passwd|api_?key|apikey|private_?key|access_?token|auth_?token|client_?secret)\b'.
                '\s*(?:=>|=|:)\s*[\'"][A-Za-z0-9+\/=_-]{20,}[\'"]/i',
        ];

        $files = $this->trackedFiles();

        if ($files === []) {
            return ['no tracked files could be listed, so this scan asserted nothing about an empty '.
                'set - which is exactly how a scan comes to pass for ever'];
        }

        $problems = [];

        foreach ($files as $file) {
            $path = $this->base().'/'.$file;

            if (! is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if ($source === '' || ! mb_check_encoding($source, 'UTF-8')) {
                continue;
            }

            foreach ($shapes as $name => $pattern) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$matched, $offset]) {
                    $line = substr_count($source, "\n", 0, (int) $offset) + 1;

                    if ($this->allowedOnItsLine($source, (int) $offset)) {
                        continue;
                    }

                    /*
                     | The finding names the FILE and the LINE and not the
                     | value. A check that printed the secret would put it in
                     | CI output, a terminal scrollback and whatever ships
                     | the run's log - which is three more places to rotate
                     | it out of.
                     */
                    $problems[] = sprintf(
                        '%s:%d looks like %s. A log rotates and a git history does not: a key pushed '.
                        'once is in every clone, every fork and every CI cache, and rotating it is '.
                        'the only remedy because deleting the commit is not one. '.
                        'docs/operations/runbooks.md has the procedure. If it is genuinely not a '.
                        'secret, annotate the line with @secret-scan-allow: why',
                        $file,
                        $line,
                        $name,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * The files git is tracking, which is the set that can be pushed.
     *
     * Asked of git rather than walked from disk: what matters is what is in
     * the repository, and a scan over the working directory reports on
     * `vendor/`, `node_modules/` and whatever somebody left in `storage/` -
     * none of which is committed and all of which is noise.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $command = sprintf('git -C %s ls-files 2>/dev/null', escapeshellarg($this->base()));
        $output = @shell_exec($command);

        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $file): bool => $file !== ''
                && ! str_starts_with($file, 'public/build/')
                && ! str_ends_with($file, '.lock'),
        ));
    }

    /** Is the match excused on its own line? */
    private function allowedOnItsLine(string $source, int $offset): bool
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $end = strpos($source, "\n", $offset);

        $line = substr(
            $source,
            $start === false ? 0 : $start + 1,
            ($end === false ? strlen($source) : $end) - ($start === false ? 0 : $start + 1),
        );

        return str_contains($line, '@secret-scan-allow');
    }


    /**
     * @return array{tasks: int, complete: int, met: int, open: int}
     */
    /**
     * A task is open or it is finished, and it is written down once.
     *
     * ## Why this exists
     *
     * The phase files hold what is LEFT and `docs/tasks/done/` holds what is
     * not, which is only useful while the two agree. Two failures are
     * possible and both are quiet: a task copied rather than moved is
     * counted twice by the progress table, and a task that got lost between
     * them disappears from the backlog with nothing to say it was dropped.
     *
     * The second is the one worth the check. A task nobody can find is a
     * task nobody will do, and "it was in the file last month" is not
     * something anybody notices.
     *
     * ## Finished means finished
     *
     * A block filed under `done/` with an open criterion in it is a task
     * somebody called finished and did not finish - so that is a failure
     * too, named for what it is rather than silently re-counted.
     *
     * @return list<string>
     */
    public function noTaskIsFiledTwice(): array
    {
        $violations = [];
        $seen = [];

        foreach (glob($this->base().'/docs/tasks/phase-*.md') ?: [] as $file) {
            $name = basename($file);

            foreach ([$file => 'open', $this->base().'/docs/tasks/done/'.$name => 'finished'] as $path => $where) {
                if (! is_file($path)) {
                    continue;
                }

                $source = (string) file_get_contents($path);

                if (preg_match_all('/^### (PF-\d+)/m', $source, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $id) {
                    if (isset($seen[$id])) {
                        $violations[] = sprintf(
                            '%s is filed as %s and also as %s. The progress table counts it twice, and '.
                            'one of the two copies is the one nobody will update.',
                            $id,
                            $seen[$id],
                            $where,
                        );

                        continue;
                    }

                    $seen[$id] = $where.' in '.$name;
                }
            }
        }

        /*
         | And the backlog is the list of what exists.
         |
         | A task in the flat view and in neither phase file has been lost
         | between the two, which is the failure nobody notices: it is not
         | in the open file, so nobody is going to do it, and it is not in
         | the finished one, so nobody can see it was dropped.
         */
        $backlog = $this->base().'/docs/tasks/backlog.md';

        if (is_file($backlog)) {
            preg_match_all('/`(PF-\d+)`/', (string) file_get_contents($backlog), $listed);

            foreach (array_unique($listed[1]) as $id) {
                if (! isset($seen[$id])) {
                    $violations[] = sprintf(
                        '%s is in the backlog and in no phase file, open or finished. It is not on '.
                        'anybody\'s list and nothing says it was dropped.',
                        $id,
                    );
                }
            }
        }

        foreach (glob($this->base().'/docs/tasks/done/phase-*.md') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, '- [ ]')) {
                $violations[] = sprintf(
                    'docs/tasks/done/%s holds an open criterion, so something filed as finished is not.',
                    basename($file),
                );
            }
        }

        return $violations;
    }

    private function phaseFileCounts(string $source): array
    {
        $met = preg_match_all('/^- \[x\]/m', $source);
        $open = preg_match_all('/^- \[ \]/m', $source);

        $blocks = preg_split('/^### PF-\d+/m', $source);
        $blocks = \is_array($blocks) ? array_slice($blocks, 1) : [];

        $complete = 0;

        foreach ($blocks as $block) {
            if (! str_contains($block, '- [ ]')) {
                $complete++;
            }
        }

        return [
            'tasks' => \count($blocks),
            'complete' => $complete,
            'met' => \is_int($met) ? $met : 0,
            'open' => \is_int($open) ? $open : 0,
        ];
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->temporary = sys_get_temp_dir().'/peakflow-hook-'.Str::random(8);
        mkdir($this->temporary, 0o700, true);

        try {
            return [
                'the commit-message hook accepts and refuses the right messages' => $this->theHookWorks(),
                'the hook is installed by composer setup' => $this->theHookIsInstalled(),
                'the hook and CONTRIBUTING.md agree on the types' => $this->theTypesAgree(),
                'composer setup takes a clone to a running application' => $this->setupIsComplete(),
                'the development seeder refuses to run outside local' => $this->theSeederRefusesProduction(),
                'the laptop setup is documented with measured numbers' => $this->theLaptopSetupIsDocumented(),
                'nothing is fetched from a third party at runtime' => $this->nothingIsFetchedAtRuntime(),
                'static analysis has no baseline, and the rule is written down' => $this->staticAnalysisHasNoBaseline(),
                'the backlog progress table agrees with the phase files' => $this->theProgressTableIsHonest(),
                'every task is in exactly one of the two files' => $this->noTaskIsFiledTwice(),
                'nothing in the repository looks like a committed secret' => $this->nothingLooksLikeACommittedSecret(),
                'a seeder invents its people rather than copying them' => $this->seedersInventTheirPeople(),
            ];
        } finally {
            foreach (glob($this->temporary.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->temporary);
        }
    }

    private function base(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function theHookWorks(): array
    {
        $problems = [];
        $hook = $this->base().'/.githooks/commit-msg';

        if (! is_file($hook)) {
            return ['.githooks/commit-msg is missing, so nothing checks a commit message before it is '.
                'written - and after it is written the only fix is rewriting history, which '.
                'CONTRIBUTING.md forbids on a shared branch.'];
        }

        if (! is_executable($hook)) {
            $problems[] = '.githooks/commit-msg is not executable. Git runs it as a program, so it would '.
                'be skipped silently - which is the way a hook most often stops working.';
        }

        foreach (self::MESSAGES as $description => [$shouldPass, $message]) {
            $file = $this->temporary.'/msg-'.substr(md5($description), 0, 8);
            file_put_contents($file, $message);

            exec(escapeshellarg($hook).' '.escapeshellarg($file).' 2>&1', $output, $status);

            $passed = $status === 0;

            if ($passed === $shouldPass) {
                continue;
            }

            $problems[] = $shouldPass
                ? sprintf(
                    'The hook refused %s, which is valid: %s',
                    $description,
                    implode(' ', array_slice($output, 0, 3)),
                )
                : sprintf(
                    'The hook accepted %s. The subject was "%s".',
                    $description,
                    strtok($message, "\n"),
                );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theHookIsInstalled(): array
    {
        $problems = [];

        $composer = (string) file_get_contents($this->base().'/composer.json');

        if (! str_contains($composer, 'bin/install-hooks')) {
            $problems[] = 'composer.json does not run bin/install-hooks, so the hook is a file in the '.
                'repository that nothing points git at. PF-001 asks for it to be installed BY `composer '.
                'setup`, because a hook somebody has to remember to install is a hook that works on one '.
                'machine.';
        }

        $installer = $this->base().'/bin/install-hooks';

        if (! is_file($installer) || ! is_executable($installer)) {
            $problems[] = 'bin/install-hooks is missing or not executable.';

            return $problems;
        }

        $source = (string) file_get_contents($installer);

        if (! str_contains($source, 'core.hooksPath')) {
            $problems[] = 'bin/install-hooks does not set core.hooksPath. Copying into .git/hooks goes '.
                'stale the moment the hook changes and nobody re-runs the installer.';
        }

        /*
         | And it must survive having no repository.
         |
         | `composer setup` runs in a deployment built from a tarball, where
         | there is no `.git` at all. An installer that failed there would
         | break setup for the case that needs it least and complains loudest.
         */
        if (! str_contains($source, '-d .git')) {
            $problems[] = 'bin/install-hooks does not check for a .git directory. `composer setup` also '.
                'runs in a deployment built from a tarball, where failing here would break setup for a '.
                'hook nobody can use anyway.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theTypesAgree(): array
    {
        $problems = [];

        $hook = (string) file_get_contents($this->base().'/.githooks/commit-msg');
        $contributing = (string) file_get_contents($this->base().'/CONTRIBUTING.md');

        if (preg_match("/^TYPES='([^']+)'/m", $hook, $matches) !== 1) {
            return ['The hook does not declare a TYPES list this check can read.'];
        }

        $types = explode('|', $matches[1]);

        foreach ($types as $type) {
            if (! str_contains($contributing, '`'.$type.'`')) {
                $problems[] = sprintf(
                    'The hook accepts the type `%s` and CONTRIBUTING.md does not list it. A contributor '.
                    'reads the document and the hook decides, so the two disagreeing means somebody is '.
                    'refused for following the instructions.',
                    $type,
                );
            }
        }

        /*
         | And the other direction, which is the one that bites.
         |
         | A type documented and not accepted is a contributor doing what the
         | guide says and being refused by a script - and the refusal message
         | quotes the guide back at them.
         */
        if (preg_match('/Types in use: (.+?)\. The scope/', $contributing, $documented) === 1) {
            preg_match_all('/`([a-z]+)`/', $documented[1], $listed);

            foreach ($listed[1] as $type) {
                if (! \in_array($type, $types, true)) {
                    $problems[] = sprintf(
                        'CONTRIBUTING.md lists the type `%s` and the hook refuses it.',
                        $type,
                    );
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function setupIsComplete(): array
    {
        $problems = [];
        $setup = $this->base().'/bin/setup';

        if (! is_file($setup) || ! is_executable($setup)) {
            return ['bin/setup is missing or not executable.'];
        }

        /*
         | Comments blanked first.
         |
         | The script's own header explains the bug it fixes - that
         | `php artisan migrate` used to run as the application role - and the
         | regression check below flagged that explanation. The fourth time in
         | this codebase a textual rule has reported the prose describing it,
         | and the fix is the same each time.
         */
        $source = $this->withoutShellComments((string) file_get_contents($setup));

        $required = [
            '.env.example' => 'copy .env from the example, or a fresh clone has no configuration at all',
            'key:generate' => 'generate an APP_KEY, without which every encrypted value and signed URL fails',
            'install-hooks' => 'install the commit-message hook, which PF-001 asks `composer setup` to do',
            '--database=migrator' => 'migrate as the MIGRATOR. The application role has no DDL (PF-010), '.
                'so `php artisan migrate` on its own fails with a permission error that reads as a '.
                'broken database - and that is exactly what a fresh clone used to do',
            'DevelopmentSeeder' => 'seed something to sign into. Without it a clone ends at a login form '.
                'with no workspace and no discoverable credentials',
            'npm run build' => 'build the assets, or every page renders with no styles',
            '--wait' => 'wait on the compose healthchecks rather than racing them, which is the '.
                'difference between a setup that works and one that works on a fast machine',
        ];

        foreach ($required as $needle => $why) {
            if (! str_contains($source, $needle)) {
                $problems[] = sprintf('bin/setup does not %s (no `%s`).', $why, $needle);
            }
        }

        /*
         | The bare `artisan migrate` must NOT be there.
         |
         | This is the regression that matters: adding `migrate` back without
         | the flag would make a fresh clone fail again, and the error names
         | a password rather than a privilege.
         */
        if (preg_match('/artisan migrate(?![^\n]*--database)/', $source) === 1) {
            $problems[] = 'bin/setup runs `artisan migrate` without `--database=migrator`. The '.
                'application role has no DDL, so this fails on a fresh clone with a permission error.';
        }

        $composer = (string) file_get_contents($this->base().'/composer.json');

        if (! str_contains($composer, 'bin/setup')) {
            $problems[] = 'composer.json does not call bin/setup, so `composer setup` does something '.
                'other than what is tested here.';
        }

        return $problems;
    }

    /**
     * No stylesheet, template or built asset may reach a third party - PF-017.
     *
     * ## What this is really protecting
     *
     * Two things, and the privacy one is the one people forget. A webfont
     * fetched from a CDN tells that CDN which pages a customer's finance team
     * opens, request by request, with their IP address attached - and an
     * accounting system's URLs say what the company is doing. The other is
     * availability: a page whose typography depends on a third party loses it
     * the moment that third party is unreachable, which is also the moment
     * nobody can be asked about it.
     *
     * ## The vendoring itself is one flag and one allowed host away
     *
     * `vite.config.js` downloads IBM Plex at build time when
     * `PEAKFLOW_FETCH_FONTS=1`, and the default is off because the download
     * needs `fonts.bunny.net` - a build environment that denies that host
     * would fail the WHOLE build rather than the font step, which is a worse
     * failure than falling back to a system face.
     *
     * So what is asserted here is the part that holds either way: the built
     * output contains no remote URL at all, every `@font-face` resolves to a
     * local file, and the fallback stack names a real Arabic face rather than
     * the generic `sans-serif` - because on Linux the generic resolves to
     * whatever fontconfig substitutes, which is how an Arabic interface ends
     * up rendered in a face nobody chose.
     *
     * @return list<string>
     */
    private function nothingIsFetchedAtRuntime(): array
    {
        $problems = [];
        $base = $this->base();

        $hosts = ['fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'use.typekit.net', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com'];

        $files = array_merge(
            glob($base.'/resources/css/*.css') ?: [],
            glob($base.'/resources/views/**/*.blade.php') ?: [],
            glob($base.'/resources/views/*.blade.php') ?: [],
            glob($base.'/public/build/assets/*.css') ?: [],
            glob($base.'/public/build/assets/*.js') ?: [],
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $relative = str_replace($base.'/', '', $file);

            foreach ($hosts as $host) {
                if (str_contains($source, $host)) {
                    $problems[] = sprintf(
                        '%s references %s. A webfont or script fetched from a CDN tells that CDN which '.
                        'pages a customer\'s finance team opens, request by request, with their IP '.
                        'attached - and an accounting system\'s URLs say what the company is doing.',
                        $relative,
                        $host,
                    );
                }
            }

            /*
             | And any remote URL in a BUILT asset, not only a known host.
             |
             | The built CSS is what a browser actually loads. A `url(https:...)`
             | in it is a request to somewhere, whoever it belongs to.
             */
            if (str_contains($relative, 'public/build/')
                && preg_match('#url\(\s*[\'"]?https?://#i', $source, $m) === 1) {
                $problems[] = sprintf(
                    '%s contains a remote url() (%s). The built CSS is what a browser loads, so a remote '.
                    'URL in it is a request to a third party on every page view.',
                    $relative,
                    $m[0],
                );
            }
        }

        /*
         | Every `@font-face` src must resolve to a file that exists.
         |
         | A vendored font that was deleted leaves a stylesheet asking for a
         | 404, and the symptom is the fallback face - which looks like a
         | design decision.
         */
        foreach (glob($base.'/resources/css/*.css') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('#src:\s*url\(\s*[\'"]?([^\'")]+)#i', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $url) {
                if (str_starts_with($url, 'http') || str_starts_with($url, 'data:')) {
                    continue;
                }

                $resolved = realpath(\dirname($file).'/'.ltrim($url, '/'));

                if ($resolved === false) {
                    $problems[] = sprintf(
                        '%s declares a @font-face src of "%s", which does not resolve to a file. The '.
                        'symptom is the fallback face, which looks like a design decision.',
                        str_replace($base.'/', '', $file),
                        $url,
                    );
                }
            }
        }

        /*
         | The Arabic fallback, which is the part that is wrong by default.
         |
         | `sans-serif` alone is not a fallback for an Arabic interface: the
         | generic resolves to a Latin-first face on Linux and the Arabic
         | coverage is whatever fontconfig substitutes.
         */
        $css = (string) file_get_contents($base.'/resources/css/app.css');

        if (preg_match('/--font-sans:\s*([^;]+);/s', $css, $stack) !== 1) {
            $problems[] = 'resources/css/app.css declares no --font-sans token.';

            return $problems;
        }

        $arabicFallbacks = ['Noto Sans Arabic', 'Geeza Pro', 'Tahoma', 'Segoe UI'];
        $named = 0;

        foreach ($arabicFallbacks as $face) {
            if (str_contains($stack[1], $face)) {
                $named++;
            }
        }

        if ($named < 2) {
            $problems[] = sprintf(
                'The --font-sans stack names %d Arabic-capable fallback face(s) and should name several: '.
                'Noto Sans Arabic on Linux and Android, Geeza Pro on macOS, Segoe UI or Tahoma on '.
                'Windows. Falling through to the generic `sans-serif` leaves an Arabic interface in '.
                'whatever fontconfig substitutes, which is a face nobody chose.',
                $named,
            );
        }

        // And the build flag has to be documented, or nobody knows it exists.
        $example = (string) file_get_contents($base.'/.env.example');

        if (! str_contains($example, 'PEAKFLOW_FETCH_FONTS')) {
            $problems[] = '.env.example does not mention PEAKFLOW_FETCH_FONTS, so the switch that '.
                'self-hosts the fonts is a thing only vite.config.js knows about.';
        }

        return $problems;
    }

    /**
     * There is no baseline, and the rule for adding one is documented - PF-002.
     *
     * ## Why the absence is checked rather than the contents
     *
     * A baseline file is generated by a command. `phpstan analyse
     * --generate-baseline` is one line, it makes a red run green, and the
     * person who runs it at 6pm on a Friday is not making a decision about
     * technical debt - they are making the pipeline stop complaining.
     *
     * So the file not existing is the control. Generating one becomes a
     * visible change to a tracked file, which is a thing a reviewer sees.
     *
     * The failure mode a baseline produces is not that it hides one problem.
     * It is that the file grows, nobody reads it, and the analyser's output
     * stops meaning anything - at which point the level the project claims to
     * run at is a number in a config file.
     *
     * ## PHPStan and Pint cannot be installed in this environment
     *
     * Their Composer sources are unreachable through this environment's proxy,
     * so `vendor/bin/phpstan` does not exist and "the analyser is clean" is
     * not assertable here. What IS assertable is everything around it: the
     * config at the level it claims, no baseline, and the rule written where a
     * reviewer reads it. Those are the parts that rot, and they rot silently.
     *
     * @return list<string>
     */
    private function staticAnalysisHasNoBaseline(): array
    {
        $problems = [];
        $base = $this->base();

        $candidates = array_merge(
            glob($base.'/*baseline*') ?: [],
            glob($base.'/build/*baseline*') ?: [],
            glob($base.'/tests/*baseline*') ?: [],
        );

        foreach ($candidates as $file) {
            $problems[] = sprintf(
                '%s exists. A baseline is a record of debt, not a way to pass - and it is generated by a '.
                'one-line command that makes a red run green, which is not a decision about technical '.
                'debt. See CONTRIBUTING.md: a new entry needs a reviewer by name and a PF-### that '.
                'removes it.',
                str_replace($base.'/', '', $file),
            );
        }

        $config = $base.'/phpstan.neon';

        if (! is_file($config)) {
            $problems[] = 'phpstan.neon is missing.';

            return $problems;
        }

        $source = (string) file_get_contents($config);

        if (preg_match('/^\s*level:\s*max\s*$/m', $source) !== 1) {
            $problems[] = 'phpstan.neon is not at `level: max`. Lowering the level to pass is the same '.
                'decision as a baseline entry, taken silently and applied to the whole codebase.';
        }

        /*
         | An include named baseline, which is the other way to get one.
         |
         | `includes: [baseline.neon]` with the file gitignored passes the
         | existence check above and has exactly the effect it was meant to
         | prevent.
         */
        if (preg_match('/^\s*-\s*.*baseline.*$/mi', $source) === 1) {
            $problems[] = 'phpstan.neon includes a baseline file. With the file gitignored this passes '.
                'every other check here and has exactly the effect they exist to prevent.';
        }

        $contributing = (string) file_get_contents($base.'/CONTRIBUTING.md');

        /*
         | Phrases from the requirements, not words from the heading.
         |
         | The first version looked for "reviewer" inside the section, and the
         | section's own HEADING contains it - so deleting the requirement
         | left the check passing. A needle that the thing it is looking for
         | is not the only source of is not a needle.
         */
        foreach ([
            'approves the entry' => 'that a reviewer has to approve the entry - and not the author, '.
                'because the point is that somebody else agreed the debt is worth taking',
            'PF-###' => 'that the entry carries the task that removes it. A baseline line with no task '.
                'is a line nobody will ever delete, because nobody can tell whether it is still needed',
            'stays at `max`' => 'that the level stays at max, because lowering it to pass is the same '.
                'decision as a baseline entry taken silently and applied to everything',
        ] as $needle => $why) {
            if (! str_contains($contributing, $needle)) {
                $problems[] = sprintf('CONTRIBUTING.md does not state %s.', $why);
            }
        }

        if (! is_file($base.'/pint.json')) {
            $problems[] = 'pint.json is missing, so formatting is whatever each editor does.';
        }

        return $problems;
    }

    /**
     * Blank every `#` comment, keeping the line count.
     *
     * The shell's own rule: a `#` starts a comment when it begins a word -
     * at the start of a line or after whitespace. That is wrong inside a
     * quoted string containing ` #`, which this script does not have and a
     * future one might; the cost of being wrong is a positive check failing
     * on a command it cannot see, which is a visible failure rather than a
     * silent pass.
     */
    private function withoutShellComments(string $source): string
    {
        return (string) preg_replace_callback(
            '/(^|\s)#[^\n]*/m',
            static fn (array $m): string => $m[1].(string) preg_replace('/./', ' ', substr($m[0], \strlen($m[1]))),
            $source,
        );
    }

    /** @return list<string> */
    private function theSeederRefusesProduction(): array
    {
        $problems = [];
        $source = (string) file_get_contents($this->base().'/database/seeders/DevelopmentSeeder.php');

        if (! str_contains($source, "environment(['local', 'testing'])")) {
            $problems[] = 'DevelopmentSeeder does not check the environment. It sets a known password, '.
                'and `db:seed --class=DevelopmentSeeder --force` is one typo away from a production '.
                'shell - with `--force` existing precisely to get past the confirmation that would '.
                'otherwise have stopped it.';
        }

        if (DevelopmentSeeder::PASSWORD === '') {
            $problems[] = 'The development password is empty, so nobody can sign in with what the setup '.
                'script prints.';
        }

        /*
         | And the documented credentials must be the real ones.
         |
         | A document that prints a password the seeder does not set is a
         | document that costs five minutes every time somebody trusts it.
         */
        $doc = (string) file_get_contents($this->base().'/docs/operations/local-development.md');

        foreach ([DevelopmentSeeder::USER_EMAIL, DevelopmentSeeder::OPERATOR_EMAIL, DevelopmentSeeder::PASSWORD, DevelopmentSeeder::TENANT_SLUG] as $value) {
            if (! str_contains($doc, $value)) {
                $problems[] = sprintf(
                    'docs/operations/local-development.md does not mention "%s", which the seeder '.
                    'actually creates. A document that prints the wrong credentials costs five minutes '.
                    'every time somebody trusts it.',
                    $value,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theLaptopSetupIsDocumented(): array
    {
        $problems = [];
        $override = $this->base().'/compose.laptop.yaml';

        if (! is_file($override)) {
            return ['compose.laptop.yaml is missing. A compose PROFILE can only include or exclude a '.
                'service, so running a smaller PostgreSQL needs an override file - which is why "a '.
                'cut-down profile for a laptop" is two mechanisms rather than one.'];
        }

        $source = (string) file_get_contents($override);

        foreach (['shared_buffers', 'max_connections', 'maxmemory'] as $setting) {
            if (! str_contains($source, $setting)) {
                $problems[] = sprintf('compose.laptop.yaml does not reduce %s.', $setting);
            }
        }

        /*
         | Two things must NOT be reduced, and both for the same kind of
         | reason: they are not resource trades, they are features.
         */
        if (! str_contains($source, 'pg_stat_statements')) {
            $problems[] = 'compose.laptop.yaml drops pg_stat_statements. The query-budget checks assert '.
                'index and partition use through EXPLAIN, so dropping it makes those checks pass on a '.
                'laptop for the wrong reason.';
        }

        if (! str_contains($source, 'max_locks_per_transaction=256')) {
            $problems[] = 'compose.laptop.yaml does not keep max_locks_per_transaction at 256. A journal '.
                'with monthly partitions takes many locks in one transaction, and lowering it turns a '.
                'correct migration into "out of shared memory" - not a resource trade, a feature that '.
                'stops working.';
        }

        $doc = (string) file_get_contents($this->base().'/docs/operations/local-development.md');

        foreach (['max_connections', 'compose.laptop.yaml', '--profile laptop'] as $topic) {
            if (! str_contains($doc, $topic)) {
                $problems[] = sprintf('The local development document does not explain %s.', $topic);
            }
        }

        /*
         | The resource numbers have to be measured, not guessed, so the
         | document is required to carry the units that make a number
         | checkable by somebody repeating the measurement.
         */
        if (preg_match('/\b19 MB\b/', $doc) !== 1) {
            $problems[] = 'The local development document no longer states the measured size of a '.
                'migrated tenant database. "Resource expectations" without a number is a sentence '.
                'nobody can plan against.';
        }

        return $problems;
    }
}
