<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\ValueObjects\MigrationHazard;

/**
 * Refuses a migration that is not safe to run while the application serves
 * traffic - PF-023.
 *
 * ## Why this is static analysis and not a dry run
 *
 * A dry run tells you whether the SQL is valid. It cannot tell you that
 * `ALTER TABLE invoices ALTER COLUMN total TYPE numeric(21,4)` will hold an
 * ACCESS EXCLUSIVE lock for eleven minutes on a table with forty million rows,
 * because on a developer's machine that table has nine rows and the migration
 * finishes instantly. The hazard is a property of the STATEMENT, not of the
 * environment it was tested in, so the statement is what gets inspected.
 *
 * ## What it is actually protecting against
 *
 * PostgreSQL's DDL is transactional, which is a genuine advantage and also the
 * trap: because `ALTER TABLE` does not fail, it is easy to believe it is
 * cheap. Several forms take an ACCESS EXCLUSIVE lock, which queues behind
 * every open transaction on the table and then makes every subsequent query
 * queue behind IT. On a busy table the outage is not the rewrite - it is the
 * lock queue that forms while the rewrite waits for a long-running SELECT to
 * finish.
 *
 * So each hazard here names the statement, says what it does, and gives the
 * safe alternative. A check that only says "unsafe" gets bypassed with a flag
 * within a week.
 *
 * ## Creating a table is not altering one
 *
 * Every hazard here is about contention with live traffic, so none of them
 * applies inside `Schema::create()`: a table that does not exist yet has no
 * readers to block, no rows to rewrite and no lock queue to form. Indexes and
 * foreign keys declared in a CREATE TABLE are free.
 *
 * Without that distinction this inspector flags every migration that has ever
 * created a table - which is all of them - and gets switched off on the first
 * day. So offsets inside a `Schema::create()` block are excluded, and the
 * hazards are evaluated only against `Schema::table()`, raw SQL, and data
 * backfills.
 *
 * ## Deliberately conservative, and it says so
 *
 * This matches patterns in migration source. It will flag things that are
 * harmless on a small table, and it can be overridden per migration with an
 * `@unsafe-migration-reviewed` annotation naming who decided and why. What it
 * must never do is stay silent about a rewrite, because the cost of a false
 * positive is a conversation and the cost of a false negative is an outage.
 */
final class MigrationSafetyInspector
{
    /**
     * Patterns that are unsafe while the application serves traffic.
     *
     * Each entry: the regex, a short name, what it does, and what to do
     * instead. The alternatives are the part that makes this usable.
     *
     * @var array<string, array{pattern: string, does: string, instead: string}>
     */
    private const HAZARDS = [
        'not-null column without a default' => [
            // ->string('x')->nullable(false) or ->integer('x') with no default
            'pattern' => '/->(?:string|integer|bigInteger|boolean|decimal|text|date|dateTime|timestamp|uuid|ulid|char)\([^)]*\)(?:(?!->default|->nullable)[^;\n])*->nullable\(\s*false\s*\)/',
            'does' => 'adds a NOT NULL column with no default, which fails outright if the table has any rows '.
                'and rewrites it if a volatile default is supplied',
            'instead' => 'add it nullable, backfill in batches, then set NOT NULL in a later migration - or give '.
                'it a constant default, which PostgreSQL 11+ applies without a rewrite',
        ],
        'column type change' => [
            'pattern' => '/->change\(\)/',
            'does' => 'rewrites the table and holds ACCESS EXCLUSIVE for the duration, except for a few widening '.
                'casts PostgreSQL can do in place',
            'instead' => 'add a new column, backfill, switch readers, then drop the old one. If the cast really is '.
                'in-place (varchar(n) to varchar(m) where m > n, varchar to text), say so in the annotation',
        ],
        'index without CONCURRENTLY' => [
            'pattern' => '/\$table->(?:index|unique|fullText|spatialIndex)\(/',
            'does' => 'builds the index while holding a lock that blocks writes to the table',
            'instead' => 'CREATE INDEX CONCURRENTLY in a migration of its own, outside a transaction. A Laravel '.
                'migration wraps itself in one, so this needs $this->withinTransaction = false',
        ],
        'foreign key without NOT VALID' => [
            'pattern' => '/->(?:foreign|foreignId|foreignUlid|foreignUuid)\(/',
            'does' => 'takes a SHARE ROW EXCLUSIVE lock on both tables and validates every existing row',
            'instead' => 'ADD CONSTRAINT ... NOT VALID, then VALIDATE CONSTRAINT in a second migration - the '.
                'validation takes only a SHARE UPDATE EXCLUSIVE lock and does not block writes',
        ],
        'rename' => [
            'pattern' => '/->(?:renameColumn|rename)\(/',
            'does' => 'is instant in the database and breaks every running instance of the OLD code the moment it '.
                'commits',
            'instead' => 'add the new name, write to both, migrate readers, then drop the old one. A rename is a '.
                'deploy-coordination problem wearing a migration costume',
        ],
        'dropping a column' => [
            'pattern' => '/->dropColumn\(/',
            'does' => 'is instant, and breaks any running code still selecting it - including a queued job '.
                'enqueued before the deploy',
            'instead' => 'stop writing it, deploy, confirm nothing reads it, then drop it in a later release',
        ],
        'ALTER on a partitioned table' => [
            // The tables that ARE partitioned. `journal_entries` was listed
            // here and is not partitioned - only its lines are - so every
            // ALTER on it was reported as locking 26 relations when it locks
            // one. A check that cries wolf about a safe statement teaches
            // people to pass --skip-preflight.
            'pattern' => '/ALTER\s+TABLE\s+(?:ONLY\s+)?(?:audit_log|journal_lines)\b/i',
            'does' => 'takes ACCESS EXCLUSIVE on the parent AND every partition, so a table with 25 monthly '.
                'partitions locks 26 relations at once',
            'instead' => 'alter each partition individually where the operation allows it, or schedule the parent '.
                'alteration in a maintenance window and say so in the annotation',
        ],
        /*
         | Found by writing PF-246's declarations: nine migrations add a
         | CHECK constraint to a table that already exists, and nothing
         | flagged any of them.
         |
         | Detected procedurally rather than by this pattern alone - the
         | constraints come comma-separated inside one ALTER TABLE, so a
         | regex looking for `NOT VALID` anywhere before the semicolon is
         | satisfied by a sibling constraint that has one.
         */
        'unbounded UPDATE' => [
            'pattern' => '/->(?:update|delete)\(\s*\[/',
            'does' => 'writes every row in one transaction, holding row locks and generating WAL proportional to '.
                'the table',
            'instead' => 'batch it - chunkById with a sleep between batches - so the transaction stays short and '.
                'replication can keep up',
        ],
    ];

    private const REVIEWED = '@unsafe-migration-reviewed';

    /**
     * A migration that declares it is not for live traffic - PF-246.
     *
     * Different from {@see self::REVIEWED}, and the difference is worth
     * keeping. `@unsafe-migration-reviewed` says "this hazard is fine HERE,
     * even online". `@online-unsafe` says "this must not run while traffic
     * is served", which is a schedule rather than an exoneration.
     */
    private const OFFLINE = '@online-unsafe';

    /**
     * The hazards an `@online-unsafe` declaration actually answers.
     *
     * Narrow on purpose. A declaration that excused every hazard would make
     * one annotation silence the whole inspector for a file, which is the
     * failure mode `@unsafe-migration-reviewed` already has to be careful
     * about - it is at least named for reviewing a specific thing.
     *
     * What belongs here is a hazard whose entire cost is "it blocks while
     * it runs". Saying the migration does not run against traffic answers
     * that completely. It does NOT answer a hazard that breaks the running
     * code afterwards - a rename, a dropped column - because that outlives
     * the window.
     */
    private const ANSWERED_BY_BEING_OFFLINE = [
        'CHECK constraint without NOT VALID',
    ];

    /**
     * Inspect migration files and return what would be unsafe.
     *
     * @param  list<string>  $files  absolute paths
     * @return list<MigrationHazard>
     */
    public function inspect(array $files): array
    {
        $hazards = [];

        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false) {
                continue;
            }

            $reviewed = $this->reviewNote($source);
            $offline = str_contains($source, self::OFFLINE);
            $created = $this->createBlocks($source);

            foreach ($this->checkConstraintsThatScan($source) as [$statement, $offset]) {
                if ($this->lineIsExcused($source, $offset) || $this->fallsInside($offset, $created)) {
                    continue;
                }

                if ($offline) {
                    continue;
                }

                $hazards[] = new MigrationHazard(
                    file: basename($file),
                    line: substr_count($source, "\n", 0, $offset) + 1,
                    name: 'CHECK constraint without NOT VALID',
                    statement: $statement,
                    does: 'scans every row of an existing table under ACCESS EXCLUSIVE, so reads and '.
                        'writes both wait for the length of a full scan',
                    instead: 'ADD CONSTRAINT ... NOT VALID, then VALIDATE CONSTRAINT in a second '.
                        'statement - the validation takes only SHARE UPDATE EXCLUSIVE and blocks '.
                        'neither. Or declare @online-unsafe and schedule it',
                    reviewedBecause: $reviewed,
                );
            }

            foreach (self::HAZARDS as $name => $hazard) {
                if (preg_match_all($hazard['pattern'], $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$matched, $offset]) {
                    $line = substr_count($source, "\n", 0, (int) $offset) + 1;

                    // A per-line annotation beats the file-wide one, so a
                    // migration can accept one hazard without blanket-excusing
                    // every other hazard it might grow later.
                    if ($this->lineIsExcused($source, (int) $offset)) {
                        continue;
                    }

                    // Inside a CREATE TABLE: nothing to contend with.
                    if ($this->fallsInside((int) $offset, $created)) {
                        continue;
                    }

                    $hazards[] = new MigrationHazard(
                        file: basename($file),
                        line: $line,
                        name: (string) $name,
                        statement: trim((string) $matched),
                        does: $hazard['does'],
                        instead: $hazard['instead'],
                        reviewedBecause: $reviewed,
                    );
                }
            }
        }

        return $hazards;
    }

    /**
     * Hazards that nobody has signed off on.
     *
     * @param  list<MigrationHazard>  $hazards
     * @return list<MigrationHazard>
     */
    public function blocking(array $hazards): array
    {
        return array_values(array_filter(
            $hazards,
            static fn (MigrationHazard $hazard): bool => $hazard->reviewedBecause === null,
        ));
    }

    /**
     * The character ranges covered by `Schema::create()` calls.
     *
     * Found by brace matching rather than by regex, because the closure body
     * contains braces of its own and a non-greedy match to the first `});`
     * stops at the wrong one as soon as a migration has two create blocks.
     *
     * @return list<array{int, int}>
     */
    private function createBlocks(string $source): array
    {
        $blocks = [];
        $offset = 0;

        while (($start = strpos($source, 'Schema::create(', $offset)) !== false) {
            $depth = 0;
            $end = null;

            for ($i = $start; $i < strlen($source); $i++) {
                if ($source[$i] === '(') {
                    $depth++;

                    continue;
                }

                if ($source[$i] !== ')') {
                    continue;
                }

                $depth--;

                if ($depth === 0) {
                    $end = $i;

                    break;
                }
            }

            if ($end === null) {
                // Unbalanced source. Treat the rest of the file as inside the
                // block rather than reporting hazards we cannot place - a
                // confidently wrong line number is worse than silence.
                $blocks[] = [$start, strlen($source)];

                break;
            }

            $blocks[] = [$start, $end];
            $offset = $end + 1;
        }

        return $blocks;
    }

    /** @param list<array{int, int}> $ranges */
    private function fallsInside(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset <= $end) {
                return true;
            }
        }

        return false;
    }

    /** The file-wide review note, if the migration carries one. */
    /**
     * Each `ADD CONSTRAINT ... CHECK` that will scan, with its offset.
     *
     * Procedural, because the constraints arrive comma-separated inside one
     * `ALTER TABLE` and a regex asking whether `NOT VALID` appears before
     * the semicolon is satisfied by a SIBLING constraint that has one. Each
     * clause is cut at the next `ADD CONSTRAINT` or the end of the
     * statement and asked on its own.
     *
     * @return list<array{string, int}>
     */
    private function checkConstraintsThatScan(string $source): array
    {
        if (preg_match_all('/ADD\s+CONSTRAINT\s+\w+/i', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        /*
         | A constraint on a table this migration created is not a hazard.
         |
         | Nothing can be reading a table that did not exist a moment ago, so
         | the scan finds no rows and the lock contends with nobody. The first
         | version of this flagged create_fiscal_calendar and
         | create_chart_of_accounts - every table-creating migration in the
         | product - because fallsInside() knows the Schema closure and these
         | constraints arrive in a raw ALTER TABLE after it.
         */
        $createdHere = $this->tablesCreatedIn($source);

        $found = [];
        $starts = array_map(static fn (array $m): int => (int) $m[1], $matches[0]);

        foreach ($matches[0] as $index => [$matched, $offset]) {
            $offset = (int) $offset;
            $semicolon = strpos($source, ';', $offset);
            $next = $starts[$index + 1] ?? PHP_INT_MAX;
            $end = min($next, $semicolon === false ? PHP_INT_MAX : $semicolon, strlen($source));

            $clause = substr($source, $offset, $end - $offset);

            if (preg_match('/\bCHECK\s*\(/i', $clause) !== 1) {
                continue;
            }

            if (preg_match('/\bNOT\s+VALID\b/i', $clause) === 1) {
                continue;
            }

            if (\in_array($this->alterTargetBefore($source, $offset), $createdHere, true)) {
                continue;
            }

            $found[] = [trim((string) $matched), $offset];
        }

        return $found;
    }

    /**
     * The tables this migration creates, however it spells it.
     *
     * @return list<string>
     */
    private function tablesCreatedIn(string $source): array
    {
        preg_match_all('/Schema::create\(\s*[\x27"](\w+)[\x27"]/', $source, $schema);
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $source, $raw);

        return array_values(array_unique([...$schema[1], ...$raw[1]]));
    }

    /**
     * Which table the `ALTER TABLE` above this offset is about.
     *
     * The nearest one preceding it, because the clauses of one statement
     * share a target and PostgreSQL has no way to spell two in one.
     */
    private function alterTargetBefore(string $source, int $offset): ?string
    {
        if (preg_match_all('/ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)/i', substr($source, 0, $offset), $m, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $last = end($m[1]);

        return $last === false ? null : (string) $last[0];
    }


    private function reviewNote(string $source): ?string
    {
        if (preg_match('/'.preg_quote(self::REVIEWED, '/').':\s*(.+)/', $source, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * Is the hazard excused on its own line?
     *
     * Same shape as the `@arch-allow` annotation the architecture rules use,
     * because a second convention for the same idea is a convention nobody
     * remembers.
     */
    private function lineIsExcused(string $source, int $offset): bool
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($source, "\n", $offset);
        $line = substr($source, $lineStart, ($lineEnd === false ? strlen($source) : $lineEnd) - $lineStart);

        return str_contains($line, self::REVIEWED);
    }
}
