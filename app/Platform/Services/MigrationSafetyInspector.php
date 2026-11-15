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
            $created = $this->createBlocks($source);

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
